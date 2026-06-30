<script setup>
/**
 * PreCallDeviceModal
 *
 * Shown before a call connects so the user can verify their camera & mic and
 * pick which devices to use. Owns its own short-lived getUserMedia stream
 * (separate from the call store's stream) which is torn down on confirm/cancel.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useDevicePreferences } from '@/composables/useDevicePreferences'
import DeviceSelectorMenu from './DeviceSelectorMenu.vue'

const props = defineProps({
  // 'outgoing' | 'incoming-accept' | 'join-existing' | 'guest-prejoin'
  mode: {
    type: String,
    default: 'outgoing'
  },
  // 'voice' | 'video'
  callType: {
    type: String,
    default: 'video'
  },
  callerName: {
    type: String,
    default: ''
  },
  // Optional override label for the confirm button
  confirmLabel: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['confirm', 'cancel'])

const prefs = useDevicePreferences()

// Local working selections — applied to prefs only on confirm
const audioInputId = ref(prefs.audioInputId.value)
const videoInputId = ref(prefs.videoInputId.value)
const audioOutputId = ref(prefs.audioOutputId.value)

// Join-state toggles
const joinMuted = ref(false)
const joinCamOff = ref(props.callType !== 'video')
const skipNextTime = ref(prefs.skipPreCallModal.value)

// Stream + element refs
const previewVideoEl = ref(null)
const localStream = ref(null)
const previewError = ref('')
const isStarting = ref(false)
let streamSeq = 0

// Audio level meter
const micLevel = ref(0) // 0..1
let audioCtx = null
let analyser = null
let analyserSource = null
let levelTimer = null
let levelData = null

const isVideoCall = computed(() => props.callType === 'video')

const headerTitle = computed(() => {
  switch (props.mode) {
    case 'incoming-accept':
      return props.callerName ? `Answer call from ${props.callerName}` : 'Answer call'
    case 'join-existing':
      return 'Join ongoing call'
    case 'guest-prejoin':
      return 'Ready to join?'
    default:
      return isVideoCall.value ? 'Start video call' : 'Start voice call'
  }
})

const confirmText = computed(() => {
  if (props.confirmLabel) return props.confirmLabel
  switch (props.mode) {
    case 'incoming-accept': return 'Answer'
    case 'join-existing': return 'Join call'
    default: return 'Start call'
  }
})

async function startPreviewStream() {
  const seq = ++streamSeq
  isStarting.value = true
  previewError.value = ''

  // Stop any previous stream first
  stopPreviewStream({ keepError: true })

  if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
    previewError.value = 'Media access is not available in this browser.'
    isStarting.value = false
    return
  }

  try {
    const constraints = {
      audio: {
        deviceId: audioInputId.value ? { exact: audioInputId.value } : undefined,
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      },
      video: isVideoCall.value ? {
        deviceId: videoInputId.value ? { exact: videoInputId.value } : undefined,
        width: { ideal: 1280 },
        height: { ideal: 720 }
      } : false
    }
    const stream = await navigator.mediaDevices.getUserMedia(constraints)
    if (seq !== streamSeq) {
      // Superseded by a later request — discard
      stream.getTracks().forEach(t => t.stop())
      return
    }
    localStream.value = stream

    // Re-enumerate now that permissions are granted so labels populate
    prefs.enumerate()

    await nextTick()
    if (previewVideoEl.value && isVideoCall.value) {
      previewVideoEl.value.srcObject = stream
    }

    setupLevelMeter(stream)
  } catch (e) {
    if (seq !== streamSeq) return
    if (e.name === 'NotAllowedError') {
      previewError.value = 'Microphone/camera access was blocked. Please allow access in your browser settings.'
    } else if (e.name === 'NotFoundError') {
      previewError.value = isVideoCall.value
        ? 'No microphone or camera found.'
        : 'No microphone found.'
    } else if (e.name === 'NotReadableError') {
      previewError.value = 'Your microphone or camera is being used by another app.'
    } else if (e.name === 'OverconstrainedError') {
      // Fall back to defaults if the requested device is gone
      audioInputId.value = null
      videoInputId.value = null
      isStarting.value = false
      startPreviewStream()
      return
    } else {
      previewError.value = e.message || 'Failed to access media devices.'
    }
  } finally {
    if (seq === streamSeq) {
      isStarting.value = false
    }
  }
}

function stopPreviewStream({ keepError = false } = {}) {
  teardownLevelMeter()
  if (localStream.value) {
    localStream.value.getTracks().forEach(t => t.stop())
    localStream.value = null
  }
  if (previewVideoEl.value) {
    try { previewVideoEl.value.srcObject = null } catch (_) { /* ignore */ }
  }
  if (!keepError) previewError.value = ''
}

function setupLevelMeter(stream) {
  teardownLevelMeter()
  const audioTrack = stream.getAudioTracks()[0]
  if (!audioTrack) return
  try {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)()
    analyserSource = audioCtx.createMediaStreamSource(new MediaStream([audioTrack]))
    analyser = audioCtx.createAnalyser()
    analyser.fftSize = 512
    analyser.smoothingTimeConstant = 0.6
    levelData = new Uint8Array(analyser.frequencyBinCount)
    analyserSource.connect(analyser)

    levelTimer = setInterval(() => {
      if (!analyser || !levelData) return
      analyser.getByteTimeDomainData(levelData)
      // Compute peak deviation from 128 (midpoint of byte sample)
      let peak = 0
      for (let i = 0; i < levelData.length; i++) {
        const v = Math.abs(levelData[i] - 128)
        if (v > peak) peak = v
      }
      // Map 0..128 -> 0..1, with a bit of curve for visual punch
      const normalized = Math.min(1, peak / 100)
      micLevel.value = normalized
    }, 80)
  } catch (e) {
    console.warn('[PreCallDeviceModal] Level meter init failed:', e?.message || e)
  }
}

function teardownLevelMeter() {
  if (levelTimer) {
    clearInterval(levelTimer)
    levelTimer = null
  }
  try { analyserSource?.disconnect?.() } catch (_) { /* ignore */ }
  analyserSource = null
  analyser = null
  levelData = null
  if (audioCtx) {
    try { audioCtx.close() } catch (_) { /* ignore */ }
    audioCtx = null
  }
  micLevel.value = 0
}

function handleAudioInputChange(deviceId) {
  audioInputId.value = deviceId
  startPreviewStream()
}

function handleVideoInputChange(deviceId) {
  videoInputId.value = deviceId
  startPreviewStream()
}

function handleAudioOutputChange(deviceId) {
  audioOutputId.value = deviceId
  // Try to apply to the preview video element so the user can hear themselves
  // playing back if they're testing — but we don't loop mic into output here,
  // so this is mostly to validate the device is available.
  if (previewVideoEl.value && typeof previewVideoEl.value.setSinkId === 'function') {
    previewVideoEl.value.setSinkId(deviceId).catch(() => {})
  }
}

function confirm() {
  // Persist selections + skip flag
  prefs.setAudioInput(audioInputId.value)
  prefs.setVideoInput(videoInputId.value)
  prefs.setAudioOutput(audioOutputId.value)
  prefs.setSkipPreCallModal(skipNextTime.value)

  const payload = {
    audioInputId: audioInputId.value,
    videoInputId: videoInputId.value,
    audioOutputId: audioOutputId.value,
    joinMuted: joinMuted.value,
    joinCamOff: joinCamOff.value || !isVideoCall.value
  }

  // Tear down our preview stream BEFORE handing off so the call store's
  // getUserMedia call can grab the same device without a "device in use" error.
  stopPreviewStream()

  emit('confirm', payload)
}

function cancel() {
  stopPreviewStream()
  emit('cancel')
}

// Bar count for the VU meter
const meterBars = computed(() => {
  const bars = 12
  const filled = Math.round(micLevel.value * bars)
  return Array.from({ length: bars }, (_, i) => i < filled)
})

watch(previewVideoEl, (el) => {
  if (el && localStream.value && isVideoCall.value) {
    el.srcObject = localStream.value
  }
})

// Kick off preview the moment we mount
startPreviewStream()

onBeforeUnmount(() => {
  stopPreviewStream()
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[10001] flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
      <div class="bg-surface-900 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl border border-surface-700/50 max-h-[95vh] flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-surface-700/40 shrink-0">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-white/70">
              {{ isVideoCall ? 'videocam' : 'call' }}
            </span>
            <h3 class="text-white font-semibold text-base">{{ headerTitle }}</h3>
          </div>
          <button
            type="button"
            @click="cancel"
            class="w-8 h-8 rounded-full hover:bg-white/10 flex items-center justify-center transition-colors"
            aria-label="Close"
          >
            <span class="material-symbols-rounded text-white/60 text-xl">close</span>
          </button>
        </div>

        <div class="flex-1 overflow-y-auto p-5 space-y-5">
          <!-- Camera preview (video calls only) -->
          <div
            v-if="isVideoCall"
            class="relative rounded-2xl overflow-hidden bg-surface-950 aspect-video shadow-inner"
          >
            <video
              v-if="!previewError && !joinCamOff"
              ref="previewVideoEl"
              autoplay
              muted
              playsinline
              class="w-full h-full object-cover"
              style="transform: scaleX(-1);"
            ></video>
            <div
              v-else
              class="w-full h-full flex items-center justify-center"
            >
              <div class="text-center px-6">
                <span class="material-symbols-rounded text-5xl text-white/40">
                  {{ joinCamOff ? 'videocam_off' : 'error' }}
                </span>
                <p class="mt-2 text-sm text-white/60">
                  {{ joinCamOff ? 'Camera off' : (previewError || 'Camera unavailable') }}
                </p>
              </div>
            </div>

            <!-- Mic level meter overlay (bottom of preview) -->
            <div class="absolute bottom-3 left-3 right-3 flex items-center gap-2 bg-black/40 backdrop-blur-sm px-3 py-1.5 rounded-full">
              <span class="material-symbols-rounded text-white/70 text-base shrink-0">
                {{ joinMuted ? 'mic_off' : 'mic' }}
              </span>
              <div class="flex items-center gap-0.5 flex-1">
                <span
                  v-for="(filled, i) in meterBars"
                  :key="i"
                  class="h-2 flex-1 rounded-full transition-colors duration-75"
                  :class="filled
                    ? (i > 8 ? 'bg-red-400' : i > 5 ? 'bg-amber-300' : 'bg-green-400')
                    : 'bg-white/15'"
                ></span>
              </div>
            </div>
          </div>

          <!-- Voice-only mic meter (no preview) -->
          <div v-else class="rounded-2xl bg-surface-950 px-4 py-5 flex items-center gap-3">
            <span class="material-symbols-rounded text-white/70 text-2xl shrink-0">
              {{ joinMuted ? 'mic_off' : 'mic' }}
            </span>
            <div class="flex items-center gap-1 flex-1">
              <span
                v-for="(filled, i) in meterBars"
                :key="i"
                class="h-3 flex-1 rounded-full transition-colors duration-75"
                :class="filled
                  ? (i > 8 ? 'bg-red-400' : i > 5 ? 'bg-amber-300' : 'bg-green-400')
                  : 'bg-white/15'"
              ></span>
            </div>
          </div>

          <!-- Error banner (non-video calls or camera-off video calls) -->
          <div
            v-if="previewError"
            class="bg-red-500/15 border border-red-500/30 text-red-200 px-3 py-2 rounded-xl text-sm flex items-start gap-2"
          >
            <span class="material-symbols-rounded text-base mt-0.5 shrink-0">warning</span>
            <span class="flex-1">{{ previewError }}</span>
          </div>

          <!-- Device selectors -->
          <div class="space-y-3">
            <DeviceSelectorMenu
              kind="audioinput"
              variant="inline"
              label="Microphone"
              :selectedDeviceId="audioInputId"
              @update:selectedDeviceId="handleAudioInputChange"
            />
            <DeviceSelectorMenu
              v-if="isVideoCall"
              kind="videoinput"
              variant="inline"
              label="Camera"
              :selectedDeviceId="videoInputId"
              @update:selectedDeviceId="handleVideoInputChange"
            />
            <DeviceSelectorMenu
              v-if="prefs.canSwitchAudioOutput"
              kind="audiooutput"
              variant="inline"
              label="Speaker"
              :selectedDeviceId="audioOutputId"
              @update:selectedDeviceId="handleAudioOutputChange"
            />
          </div>

          <!-- Join-state toggles -->
          <div class="flex items-center gap-2 pt-1">
            <button
              type="button"
              @click="joinMuted = !joinMuted"
              :class="[
                'flex-1 px-3 py-2 rounded-xl text-sm font-medium transition-colors flex items-center justify-center gap-2 border',
                joinMuted
                  ? 'bg-red-500/15 border-red-500/30 text-red-300'
                  : 'bg-surface-800 border-surface-700 text-white/80 hover:bg-surface-700'
              ]"
            >
              <span class="material-symbols-rounded text-base">{{ joinMuted ? 'mic_off' : 'mic' }}</span>
              {{ joinMuted ? 'Join muted' : 'Mic on' }}
            </button>
            <button
              v-if="isVideoCall"
              type="button"
              @click="joinCamOff = !joinCamOff"
              :class="[
                'flex-1 px-3 py-2 rounded-xl text-sm font-medium transition-colors flex items-center justify-center gap-2 border',
                joinCamOff
                  ? 'bg-surface-800 border-surface-700 text-white/60'
                  : 'bg-blue-500/15 border-blue-500/30 text-blue-300'
              ]"
            >
              <span class="material-symbols-rounded text-base">{{ joinCamOff ? 'videocam_off' : 'videocam' }}</span>
              {{ joinCamOff ? 'Camera off' : 'Camera on' }}
            </button>
          </div>

          <!-- Skip toggle -->
          <label class="flex items-center gap-2 cursor-pointer select-none pt-1">
            <input
              type="checkbox"
              v-model="skipNextTime"
              class="w-4 h-4 rounded border-surface-600 bg-surface-800 text-primary-500 focus:ring-primary-500 focus:ring-offset-0"
            />
            <span class="text-xs text-white/60">Don't show this screen for future calls (you can still switch devices in-call).</span>
          </label>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-2 px-5 py-4 border-t border-surface-700/40 shrink-0">
          <button
            type="button"
            @click="cancel"
            class="px-4 py-2.5 rounded-xl text-sm font-medium text-white/70 hover:bg-white/5 transition-colors"
          >
            Cancel
          </button>
          <button
            type="button"
            @click="confirm"
            :disabled="isStarting"
            class="ml-auto px-5 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 disabled:opacity-50 text-white text-sm font-semibold transition-colors flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-base">{{ isVideoCall ? 'videocam' : 'call' }}</span>
            {{ confirmText }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
