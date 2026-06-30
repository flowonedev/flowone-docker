<script setup>
/**
 * PreJoinPanel - Shared pre-join screen for call rooms.
 *
 * Teams-like layout: camera preview on the left, microphone/camera/speaker
 * device card on the right, "Ready to join?" card spanning underneath.
 * Stacks vertically on small screens.
 *
 * Owns the camera preview stream, the live mic VU meter, and the device
 * selection (persisted through useDevicePreferences). Parents only handle
 * the join event.
 *
 * Used by GuestCallView and VideoCallRoom (internal prejoin).
 */
import { computed, onBeforeUnmount, onMounted, nextTick, ref } from 'vue'
import DeviceSelectorMenu from '@/components/call/DeviceSelectorMenu.vue'
import { useDevicePreferences } from '@/composables/useDevicePreferences'

const props = defineProps({
  joinLabel: { type: String, default: 'Join Call' },
})

const emit = defineEmits(['join'])

/** Two-way bound display name (parent keeps ownership for persistence). */
const name = defineModel('name', { type: String, default: '' })

const previewStream = ref(null)
const videoPreviewEl = ref(null)
const hasCameraPermission = ref(true)

// Device preferences (singleton — shared with the in-call control bar)
const devicePrefs = useDevicePreferences()
const selectedAudioInputId = ref(devicePrefs.audioInputId.value)
const selectedVideoInputId = ref(devicePrefs.videoInputId.value)
const selectedAudioOutputId = ref(devicePrefs.audioOutputId.value)

// Live mic level meter (Web Audio AnalyserNode over the captured audio track)
const micLevel = ref(0)
let _audioCtx = null
let _analyser = null
let _analyserSrc = null
let _levelTimer = null
let _levelData = null
let _previewSeq = 0

const meterBars = computed(() => {
  const bars = 12
  const filled = Math.round(micLevel.value * bars)
  return Array.from({ length: bars }, (_, i) => i < filled)
})

onMounted(() => {
  startCameraPreview()
})

onBeforeUnmount(() => {
  stopCameraPreview()
})

async function startCameraPreview() {
  const seq = ++_previewSeq
  stopCameraPreview()

  const audioId = selectedAudioInputId.value
  const videoId = selectedVideoInputId.value

  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: {
        deviceId: videoId ? { exact: videoId } : undefined
      },
      audio: {
        deviceId: audioId ? { exact: audioId } : undefined,
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      }
    })
    if (seq !== _previewSeq) {
      stream.getTracks().forEach(t => t.stop())
      return
    }
    previewStream.value = stream
    devicePrefs.enumerate()
    await nextTick()
    if (videoPreviewEl.value) {
      videoPreviewEl.value.srcObject = stream
      videoPreviewEl.value.muted = true
    }
    setupLevelMeter(stream)
  } catch {
    if (seq !== _previewSeq) return
    try {
      const fallback = await navigator.mediaDevices.getUserMedia({
        video: { deviceId: videoId ? { exact: videoId } : undefined }
      })
      if (seq !== _previewSeq) {
        fallback.getTracks().forEach(t => t.stop())
        return
      }
      previewStream.value = fallback
      await nextTick()
      if (videoPreviewEl.value) videoPreviewEl.value.srcObject = fallback
    } catch {
      hasCameraPermission.value = false
    }
  }
}

function stopCameraPreview() {
  teardownLevelMeter()
  if (previewStream.value) {
    previewStream.value.getTracks().forEach(t => t.stop())
    previewStream.value = null
  }
}

function setupLevelMeter(stream) {
  teardownLevelMeter()
  const audioTrack = stream.getAudioTracks()[0]
  if (!audioTrack) return
  try {
    _audioCtx = new (window.AudioContext || window.webkitAudioContext)()
    _analyserSrc = _audioCtx.createMediaStreamSource(new MediaStream([audioTrack]))
    _analyser = _audioCtx.createAnalyser()
    _analyser.fftSize = 512
    _analyser.smoothingTimeConstant = 0.6
    _levelData = new Uint8Array(_analyser.frequencyBinCount)
    _analyserSrc.connect(_analyser)
    _levelTimer = setInterval(() => {
      if (!_analyser || !_levelData) return
      _analyser.getByteTimeDomainData(_levelData)
      let peak = 0
      for (let i = 0; i < _levelData.length; i++) {
        const v = Math.abs(_levelData[i] - 128)
        if (v > peak) peak = v
      }
      micLevel.value = Math.min(1, peak / 100)
    }, 80)
  } catch (e) {
    console.warn('[PreJoinPanel] Level meter init failed:', e?.message || e)
  }
}

function teardownLevelMeter() {
  if (_levelTimer) {
    clearInterval(_levelTimer)
    _levelTimer = null
  }
  try { _analyserSrc?.disconnect?.() } catch (_) { /* ignore */ }
  _analyserSrc = null
  _analyser = null
  _levelData = null
  if (_audioCtx) {
    try { _audioCtx.close() } catch (_) { /* ignore */ }
    _audioCtx = null
  }
  micLevel.value = 0
}

function onAudioInputChange(deviceId) {
  selectedAudioInputId.value = deviceId
  devicePrefs.setAudioInput(deviceId)
  startCameraPreview()
}

function onVideoInputChange(deviceId) {
  selectedVideoInputId.value = deviceId
  devicePrefs.setVideoInput(deviceId)
  startCameraPreview()
}

function onAudioOutputChange(deviceId) {
  selectedAudioOutputId.value = deviceId
  devicePrefs.setAudioOutput(deviceId)
}

function handleJoin() {
  stopCameraPreview()
  emit('join', (name.value || '').trim())
}
</script>

<template>
  <div class="w-full max-w-4xl mx-auto">
    <!-- Top row: camera preview (left) + device card (right) -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-4 lg:items-stretch">
      <!-- Camera preview -->
      <div class="lg:col-span-3">
        <div class="relative rounded-2xl overflow-hidden bg-surface-800 aspect-video shadow-2xl">
          <video
            v-if="hasCameraPermission"
            ref="videoPreviewEl"
            autoplay muted playsinline
            class="w-full h-full object-cover"
            style="transform: scaleX(-1)"
          ></video>
          <div v-else class="w-full h-full flex items-center justify-center">
            <div class="text-center">
              <span class="material-symbols-rounded text-5xl text-surface-500">videocam_off</span>
              <p class="text-surface-400 text-sm mt-2">Camera access not available</p>
            </div>
          </div>

          <!-- Live mic level meter overlay -->
          <div
            v-if="hasCameraPermission"
            class="absolute bottom-3 left-3 right-3 flex items-center gap-2 px-3 py-1.5 rounded-full
                   bg-black/45 backdrop-blur-sm pointer-events-none"
          >
            <span class="material-symbols-rounded text-white/80 text-[16px]">mic</span>
            <div class="flex items-center gap-[2px] flex-1">
              <span
                v-for="(on, i) in meterBars"
                :key="i"
                class="flex-1 h-1.5 rounded-full transition-colors duration-75"
                :class="on
                  ? (i > 8 ? 'bg-red-400' : i > 5 ? 'bg-amber-300' : 'bg-emerald-400')
                  : 'bg-white/15'"
              ></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Device selectors (mic / camera / speaker) -->
      <div class="lg:col-span-2">
        <div class="h-full bg-surface-800/60 backdrop-blur-sm rounded-2xl p-4 border border-surface-700/50
                    flex flex-col justify-center gap-3">
          <DeviceSelectorMenu
            kind="audioinput"
            variant="inline"
            label="Microphone"
            :selectedDeviceId="selectedAudioInputId"
            @update:selectedDeviceId="onAudioInputChange"
          />
          <DeviceSelectorMenu
            kind="videoinput"
            variant="inline"
            label="Camera"
            :selectedDeviceId="selectedVideoInputId"
            @update:selectedDeviceId="onVideoInputChange"
          />
          <DeviceSelectorMenu
            v-if="devicePrefs.canSwitchAudioOutput"
            kind="audiooutput"
            variant="inline"
            label="Speaker"
            :selectedDeviceId="selectedAudioOutputId"
            @update:selectedDeviceId="onAudioOutputChange"
          />
        </div>
      </div>
    </div>

    <!-- Ready to join: full width under the two columns -->
    <div class="bg-surface-800/60 backdrop-blur-sm rounded-2xl p-6 border border-surface-700/50">
      <h2 class="text-xl font-bold text-white mb-1">Ready to join?</h2>
      <p class="text-surface-400 text-sm mb-5">Enter your name so others know who you are.</p>
      <div class="flex flex-col sm:flex-row gap-3">
        <input
          v-model="name" type="text" placeholder="Your name" autofocus
          class="flex-1 px-4 py-3 rounded-xl bg-surface-700/50 border border-surface-600 text-white placeholder-surface-500
                 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none transition-all"
          @keydown.enter="handleJoin"
        />
        <button
          @click="handleJoin"
          class="sm:w-auto w-full px-8 py-3 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-semibold text-sm
                 transition-all shadow-lg shadow-primary-500/20 hover:shadow-primary-500/30 flex items-center justify-center gap-2"
        >
          <span class="material-symbols-rounded text-lg">videocam</span>
          {{ joinLabel }}
        </button>
      </div>
    </div>
  </div>
</template>
