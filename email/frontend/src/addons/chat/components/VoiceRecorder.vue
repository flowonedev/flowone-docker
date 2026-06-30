<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'

const emit = defineEmits(['send', 'cancel'])

// Recording state
const isRecording = ref(false)
const isPaused = ref(false)
const recordingDuration = ref(0)
const mediaRecorder = ref(null)
const audioChunks = ref([])
const audioStream = ref(null)
const durationTimer = ref(null)
// Guard that prevents finishRecording() running twice (timer + button click race)
const finishing = ref(false)

// Waveform visualization
const canvasRef = ref(null)
const analyser = ref(null)
const audioContext = ref(null)
const animationFrame = ref(null)
const waveformData = ref([])

// Max recording duration (5 minutes)
const MAX_DURATION = 300

// Wall-clock tracking for drift-free duration — plain vars, no reactive overhead needed
let recordingStartTime = 0
let pauseStartTime = 0
let totalPausedMs = 0

const formattedDuration = computed(() => {
  const mins = Math.floor(recordingDuration.value / 60)
  const secs = recordingDuration.value % 60
  return `${mins}:${secs.toString().padStart(2, '0')}`
})

const durationProgress = computed(() => {
  return (recordingDuration.value / MAX_DURATION) * 100
})

async function startRecording() {
  try {
    if (!navigator.mediaDevices?.getUserMedia) {
      emit('cancel', 'Microphone is not available on this device')
      return
    }

    const stream = await navigator.mediaDevices.getUserMedia({
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        sampleRate: 48000
      }
    })
    
    audioStream.value = stream
    audioChunks.value = []
    recordingDuration.value = 0
    waveformData.value = []
    finishing.value = false
    
    // Set up audio analysis for waveform
    audioContext.value = new (window.AudioContext || window.webkitAudioContext)()
    const source = audioContext.value.createMediaStreamSource(stream)
    analyser.value = audioContext.value.createAnalyser()
    analyser.value.fftSize = 256
    source.connect(analyser.value)
    
    // Prefer webm/opus; fall back to plain webm, then ogg.
    // audio/mp4 is NOT supported for recording in Chrome/Firefox — never use it as fallback.
    const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
      ? 'audio/webm;codecs=opus'
      : MediaRecorder.isTypeSupported('audio/webm')
        ? 'audio/webm'
        : MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
          ? 'audio/ogg;codecs=opus'
          : ''   // empty string = browser picks its own default
    
    mediaRecorder.value = new MediaRecorder(stream, {
      ...(mimeType ? { mimeType } : {}),
      audioBitsPerSecond: 128000
    })
    
    mediaRecorder.value.ondataavailable = (event) => {
      if (event.data.size > 0) {
        audioChunks.value.push(event.data)
      }
    }
    
    mediaRecorder.value.start(100) // Collect data every 100ms
    isRecording.value = true
    isPaused.value = false
    
    // Wall-clock timer — immune to setInterval drift over long recordings
    recordingStartTime = Date.now()
    totalPausedMs = 0
    pauseStartTime = 0
    durationTimer.value = setInterval(() => {
      if (!isPaused.value) {
        const elapsed = Date.now() - recordingStartTime - totalPausedMs
        recordingDuration.value = Math.floor(elapsed / 1000)
        if (recordingDuration.value >= MAX_DURATION) {
          finishRecording()
        }
      }
    }, 500) // Poll twice per second; only fires the emit when threshold is hit
    
    // Start waveform animation
    drawWaveform()
    
  } catch (error) {
    console.error('Failed to start recording:', error)
    if (error.name === 'NotAllowedError') {
      emit('cancel', 'Microphone permission denied')
    } else {
      emit('cancel', 'Failed to access microphone')
    }
  }
}

function togglePause() {
  if (!mediaRecorder.value) return
  
  if (isPaused.value) {
    // Resuming — accumulate the paused window into totalPausedMs
    if (pauseStartTime) {
      totalPausedMs += Date.now() - pauseStartTime
      pauseStartTime = 0
    }
    mediaRecorder.value.resume()
    isPaused.value = false
    drawWaveform()
  } else {
    // Pausing — record when the pause started
    pauseStartTime = Date.now()
    mediaRecorder.value.pause()
    isPaused.value = true
    if (animationFrame.value) {
      cancelAnimationFrame(animationFrame.value)
      animationFrame.value = null
    }
  }
}

function cancelRecording() {
  cleanup()
  emit('cancel')
}

async function finishRecording() {
  // Guard: prevent concurrent calls from timer expiry + send button click
  if (finishing.value) return
  if (!mediaRecorder.value || mediaRecorder.value.state === 'inactive') return
  finishing.value = true
  
  const duration = recordingDuration.value
  
  // Stop recording and wait for the final ondataavailable + onstop flush.
  // Use addEventListener with { once: true } so we never overwrite a previously
  // registered onstop handler and the promise always resolves exactly once.
  await new Promise((resolve) => {
    mediaRecorder.value.addEventListener('stop', resolve, { once: true })
    mediaRecorder.value.stop()
  })
  
  // Snapshot mimeType and chunks before cleanup() nulls them
  const mimeType = mediaRecorder.value?.mimeType || 'audio/webm'
  const chunks = audioChunks.value.slice()
  const waveformSnapshot = waveformData.value.slice(-50)
  
  cleanup()
  
  const blob = new Blob(chunks, { type: mimeType })
  const ext = mimeType.includes('webm') ? 'webm' : mimeType.includes('ogg') ? 'ogg' : 'webm'
  const filename = `voice-${Date.now()}.${ext}`
  const file = new File([blob], filename, { type: mimeType })
  
  emit('send', {
    file,
    duration,
    mimeType,
    waveform: waveformSnapshot
  })
}

function drawWaveform() {
  if (!analyser.value || !canvasRef.value || isPaused.value) return
  
  const canvas = canvasRef.value
  const ctx = canvas.getContext('2d')
  const bufferLength = analyser.value.frequencyBinCount
  const dataArray = new Uint8Array(bufferLength)

  // Track last painted size to avoid reallocating the canvas buffer every frame
  let lastCanvasW = 0
  let lastCanvasH = 0
  
  function draw() {
    // Guard: component may have unmounted between rAF schedule and execution
    if (!canvasRef.value) return
    if (!isRecording.value || isPaused.value) return

    animationFrame.value = requestAnimationFrame(draw)
    
    analyser.value.getByteFrequencyData(dataArray)
    
    // Calculate average amplitude
    const average = dataArray.reduce((a, b) => a + b, 0) / bufferLength
    const normalized = average / 255
    waveformData.value.push(normalized)
    
    // Keep only last ~200 samples for display
    if (waveformData.value.length > 200) {
      waveformData.value = waveformData.value.slice(-200)
    }
    
    // Only reallocate the canvas backing store when the element size actually changes.
    // Doing this every frame is expensive (full GPU buffer realloc).
    const dpr = window.devicePixelRatio || 1
    const targetW = canvas.offsetWidth * dpr
    const targetH = canvas.offsetHeight * dpr
    if (targetW !== lastCanvasW || targetH !== lastCanvasH) {
      canvas.width = targetW
      canvas.height = targetH
      lastCanvasW = targetW
      lastCanvasH = targetH
    }

    // Reset the transform completely before applying DPR scale.
    // Without this, each frame multiplies the previous scale → 2x, 4x, 8x runaway.
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.scale(dpr, dpr)
    
    const width = canvas.offsetWidth
    const height = canvas.offsetHeight
    
    ctx.clearRect(0, 0, width, height)
    
    const barCount = Math.min(waveformData.value.length, Math.floor(width / 4))
    const barWidth = 2
    const gap = 2
    const startIdx = Math.max(0, waveformData.value.length - barCount)
    
    const isDark = document.documentElement.classList.contains('dark')
    ctx.fillStyle = isDark ? 'rgba(99, 102, 241, 0.8)' : 'rgba(79, 70, 229, 0.7)'

    for (let i = 0; i < barCount; i++) {
      const val = waveformData.value[startIdx + i] || 0
      const barHeight = Math.max(2, val * (height * 0.8))
      const x = i * (barWidth + gap)
      const y = (height - barHeight) / 2
      
      ctx.beginPath()
      ctx.roundRect(x, y, barWidth, barHeight, 1)
      ctx.fill()
    }
  }
  
  draw()
}

function cleanup() {
  isRecording.value = false
  isPaused.value = false
  finishing.value = false

  // Reset wall-clock tracking
  recordingStartTime = 0
  pauseStartTime = 0
  totalPausedMs = 0
  
  if (durationTimer.value) {
    clearInterval(durationTimer.value)
    durationTimer.value = null
  }
  
  if (animationFrame.value) {
    cancelAnimationFrame(animationFrame.value)
    animationFrame.value = null
  }
  
  if (mediaRecorder.value && mediaRecorder.value.state !== 'inactive') {
    try { mediaRecorder.value.stop() } catch (e) { /* ignore */ }
  }
  mediaRecorder.value = null
  
  if (audioStream.value) {
    audioStream.value.getTracks().forEach(t => t.stop())
    audioStream.value = null
  }
  
  if (audioContext.value) {
    // Null the ref immediately so any subsequent startRecording() won't encounter
    // the closing context; the actual async close() completes in the background.
    const ctx = audioContext.value
    audioContext.value = null
    ctx.close().catch(() => {})
  }
  
  analyser.value = null
}

onMounted(() => {
  startRecording()
})

onUnmounted(() => {
  cleanup()
})
</script>

<template>
  <div class="voice-recorder flex items-center gap-3 w-full">
    <!-- Cancel button -->
    <button
      @click="cancelRecording"
      class="w-9 h-9 flex items-center justify-center rounded-full bg-red-500/10 hover:bg-red-500/20 text-red-500 transition-colors flex-shrink-0"
      title="Cancel recording"
    >
      <span class="material-symbols-rounded text-xl">delete</span>
    </button>
    
    <!-- Recording indicator + waveform -->
    <div class="flex-1 flex items-center gap-3 min-w-0">
      <!-- Recording dot -->
      <div class="flex items-center gap-2 flex-shrink-0">
        <div 
          :class="[
            'w-2.5 h-2.5 rounded-full',
            isPaused ? 'bg-yellow-500' : 'bg-red-500 animate-pulse'
          ]"
        ></div>
        <span class="text-sm font-mono font-medium text-surface-700 dark:text-surface-300 tabular-nums">
          {{ formattedDuration }}
        </span>
      </div>
      
      <!-- Waveform canvas -->
      <div class="flex-1 h-10 min-w-0">
        <canvas 
          ref="canvasRef" 
          class="w-full h-full"
        ></canvas>
      </div>
    </div>
    
    <!-- Pause/Resume button -->
    <button
      @click="togglePause"
      class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors flex-shrink-0"
      :title="isPaused ? 'Resume' : 'Pause'"
    >
      <span class="material-symbols-rounded text-xl text-surface-600 dark:text-surface-400">
        {{ isPaused ? 'mic' : 'pause' }}
      </span>
    </button>
    
    <!-- Send button -->
    <button
      @click="finishRecording"
      :disabled="recordingDuration < 1"
      :class="[
        'w-9 h-9 flex items-center justify-center rounded-full transition-colors flex-shrink-0',
        recordingDuration >= 1
          ? 'bg-primary-500 text-white hover:bg-primary-600'
          : 'bg-surface-100 dark:bg-surface-800 text-surface-400 cursor-not-allowed'
      ]"
      title="Send voice message"
    >
      <span class="material-symbols-rounded text-xl">send</span>
    </button>
  </div>
</template>

<style scoped>
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}
.animate-pulse {
  animation: pulse 1.2s ease-in-out infinite;
}
</style>

