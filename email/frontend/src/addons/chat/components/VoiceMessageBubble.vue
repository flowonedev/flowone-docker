<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { getToken } from '@/services/tokenStorage'
import { getApiOrigin } from '@/services/serverRegistry'

const props = defineProps({
  /** The audio attachment object with path, type, etc */
  attachment: {
    type: Object,
    required: true
  },
  /** Duration in seconds from the message record */
  duration: {
    type: Number,
    default: 0
  },
  /** Whether this is the current user's own message */
  isOwn: {
    type: Boolean,
    default: false
  },
  /** Waveform data (array of 0-1 values) embedded in message content */
  waveform: {
    type: Array,
    default: () => []
  },
  /** Conversation ID for building attachment URL */
  conversationId: {
    type: [Number, String],
    default: null
  }
})

// Audio playback state
const isPlaying = ref(false)
const isLoading = ref(false)
const currentTime = ref(0)
const audioDuration = ref(props.duration || 0)
const audioRef = ref(null)
const playbackRate = ref(1)
const canvasRef = ref(null)

// Lazy-load: only set src when user first clicks play to avoid 429 rate limits
const audioSrc = ref(null)

// Available playback speeds
const speeds = [1, 1.5, 2]

const progressPercent = computed(() => {
  if (!audioDuration.value) return 0
  return (currentTime.value / audioDuration.value) * 100
})

const formattedCurrentTime = computed(() => {
  const t = isPlaying.value ? currentTime.value : audioDuration.value
  const mins = Math.floor(t / 60)
  const secs = Math.floor(t % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
})

const audioUrl = computed(() => {
  const basePath = getApiOrigin()
  const path = props.attachment.path || props.attachment.url || ''
  const match = path.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
  if (match) {
    const [, convId, filename] = match
    const url = basePath + '/api/chat/attachments/' + convId + '/' + encodeURIComponent(filename)
    const token = getToken('webmail_token')
    return token ? url + '?token=' + encodeURIComponent(token) : url
  }
  return ''
})

function togglePlay() {
  if (!audioRef.value) return
  
  if (isPlaying.value) {
    audioRef.value.pause()
  } else {
    // Lazy-load: set audio src on first play attempt
    if (!audioSrc.value) {
      isLoading.value = true
      audioSrc.value = audioUrl.value
      // Wait for src to bind, then play
      nextTick(() => {
        audioRef.value.load()
        audioRef.value.play().catch(err => {
          console.error('Failed to play audio:', err)
          isLoading.value = false
        })
      })
      return
    }
    audioRef.value.play().catch(err => {
      console.error('Failed to play audio:', err)
    })
  }
}

function handlePlay() {
  isPlaying.value = true
  isLoading.value = false
}

function handlePause() {
  isPlaying.value = false
}

function handleEnded() {
  isPlaying.value = false
  currentTime.value = 0
}

function handleTimeUpdate() {
  if (audioRef.value) {
    currentTime.value = audioRef.value.currentTime
  }
}

function handleLoadedMetadata() {
  if (audioRef.value && audioRef.value.duration && isFinite(audioRef.value.duration)) {
    audioDuration.value = audioRef.value.duration
  }
  isLoading.value = false
}

function handleWaiting() {
  isLoading.value = true
}

function handleCanPlay() {
  isLoading.value = false
}

function cycleSpeed() {
  const idx = speeds.indexOf(playbackRate.value)
  playbackRate.value = speeds[(idx + 1) % speeds.length]
  if (audioRef.value) {
    audioRef.value.playbackRate = playbackRate.value
  }
}

function seekTo(event) {
  if (!audioRef.value || !audioDuration.value) return
  
  const rect = event.currentTarget.getBoundingClientRect()
  const x = event.clientX - rect.left
  const percent = x / rect.width
  const newTime = percent * audioDuration.value
  
  audioRef.value.currentTime = Math.max(0, Math.min(newTime, audioDuration.value))
  currentTime.value = audioRef.value.currentTime
}

// Draw static waveform visualization
function drawWaveform() {
  if (!canvasRef.value) return
  
  const canvas = canvasRef.value
  const ctx = canvas.getContext('2d')
  const dpr = window.devicePixelRatio || 1
  
  canvas.width = canvas.offsetWidth * dpr
  canvas.height = canvas.offsetHeight * dpr
  ctx.scale(dpr, dpr)
  
  const width = canvas.offsetWidth
  const height = canvas.offsetHeight
  ctx.clearRect(0, 0, width, height)
  
  // Generate pseudo-random waveform if no data provided
  const data = props.waveform.length > 0
    ? props.waveform
    : generatePseudoWaveform(50)
  
  const barCount = data.length
  const totalBarSpace = width - 8 // Padding
  const barWidth = 2
  const gap = Math.max(1, (totalBarSpace / barCount) - barWidth)
  
  const isDark = document.documentElement.classList.contains('dark')
  
  for (let i = 0; i < barCount; i++) {
    const val = data[i] || 0
    const barHeight = Math.max(2, val * (height * 0.75))
    const x = 4 + i * (barWidth + gap)
    const y = (height - barHeight) / 2
    
    // Color based on playback progress
    const barProgress = (i / barCount) * 100
    const isPlayed = barProgress <= progressPercent.value
    
    if (isPlayed && (isPlaying.value || currentTime.value > 0)) {
      ctx.fillStyle = props.isOwn
        ? (isDark ? 'rgba(99, 102, 241, 1)' : 'rgba(79, 70, 229, 0.9)')
        : (isDark ? 'rgba(99, 102, 241, 1)' : 'rgba(79, 70, 229, 0.9)')
    } else {
      ctx.fillStyle = props.isOwn
        ? (isDark ? 'rgba(148, 163, 184, 0.4)' : 'rgba(100, 116, 139, 0.35)')
        : (isDark ? 'rgba(148, 163, 184, 0.4)' : 'rgba(100, 116, 139, 0.35)')
    }
    
    ctx.beginPath()
    ctx.roundRect(x, y, barWidth, barHeight, 1)
    ctx.fill()
  }
}

function generatePseudoWaveform(count) {
  // Generate a natural-looking waveform from attachment ID or path as seed
  const seed = (props.attachment.id || props.attachment.filename || 'default')
    .split('')
    .reduce((acc, c) => acc + c.charCodeAt(0), 0)
  
  const data = []
  for (let i = 0; i < count; i++) {
    const x = Math.sin(seed + i * 0.5) * 0.3 + Math.sin(i * 0.2) * 0.2 + 0.3
    data.push(Math.max(0.05, Math.min(1, x + Math.sin(seed * i * 0.1) * 0.15)))
  }
  return data
}

// Redraw waveform when progress changes
watch([progressPercent, () => props.waveform], () => {
  drawWaveform()
})

let resizeObserver = null

onMounted(() => {
  // Initial draw after a tick
  requestAnimationFrame(() => {
    drawWaveform()
  })
  
  // Redraw on resize
  if (canvasRef.value) {
    resizeObserver = new ResizeObserver(() => drawWaveform())
    resizeObserver.observe(canvasRef.value)
  }
})

onUnmounted(() => {
  if (resizeObserver) {
    resizeObserver.disconnect()
  }
  if (audioRef.value) {
    audioRef.value.pause()
  }
})
</script>

<template>
  <div class="voice-bubble flex items-center gap-2.5 min-w-[220px] max-w-[320px]">
    <!-- Hidden audio element -->
    <audio
      ref="audioRef"
      :src="audioSrc"
      preload="none"
      @play="handlePlay"
      @pause="handlePause"
      @ended="handleEnded"
      @timeupdate="handleTimeUpdate"
      @loadedmetadata="handleLoadedMetadata"
      @waiting="handleWaiting"
      @canplay="handleCanPlay"
    />
    
    <!-- Play/Pause button -->
    <button
      @click="togglePlay"
      :class="[
        'w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 transition-colors',
        isOwn
          ? 'bg-primary-500/20 hover:bg-primary-500/30 text-primary-600 dark:text-primary-400'
          : 'bg-surface-300/50 dark:bg-surface-600/50 hover:bg-surface-300 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'
      ]"
    >
      <span v-if="isLoading" class="material-symbols-rounded text-xl animate-spin">progress_activity</span>
      <span v-else class="material-symbols-rounded text-xl">
        {{ isPlaying ? 'pause' : 'play_arrow' }}
      </span>
    </button>
    
    <!-- Waveform + time -->
    <div class="flex-1 min-w-0 flex flex-col gap-1">
      <!-- Waveform (clickable for seeking) -->
      <div 
        class="h-8 cursor-pointer relative"
        @click="seekTo"
      >
        <canvas 
          ref="canvasRef"
          class="w-full h-full"
        ></canvas>
      </div>
      
      <!-- Time + Speed -->
      <div class="flex items-center justify-between">
        <span class="text-[11px] font-mono tabular-nums text-surface-500 dark:text-surface-400">
          {{ formattedCurrentTime }}
        </span>
        <button
          v-if="isPlaying || currentTime > 0"
          @click="cycleSpeed"
          :class="[
            'text-[10px] font-bold px-1.5 py-0.5 rounded-full transition-colors',
            isOwn
              ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 hover:bg-primary-500/25'
              : 'bg-surface-300/50 dark:bg-surface-600/50 text-surface-600 dark:text-surface-400 hover:bg-surface-300 dark:hover:bg-surface-600'
          ]"
        >
          {{ playbackRate }}x
        </button>
        <span v-else class="text-[11px] text-surface-400 flex items-center gap-0.5">
          <span class="material-symbols-rounded text-xs">mic</span>
        </span>
      </div>
    </div>
  </div>
</template>

