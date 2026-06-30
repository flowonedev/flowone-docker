<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useCallStore } from '@/stores/call'
import { useDevicePreferences } from '@/composables/useDevicePreferences'
import CallEffectsPanel from './CallEffectsPanel.vue'
import DeviceSelectorMenu from './DeviceSelectorMenu.vue'
import CallBarButton from './CallBarButton.vue'

const callStore = useCallStore()
const prefs = useDevicePreferences()

// ─── Device / capability detection ────────────────────────────────────────────

// Touch-first detection: reliable on iPadOS, Android tablets, and avoids
// DevTools UA spoofing. maxTouchPoints > 1 works across all modern browsers.
// UA regex kept only as last-resort fallback for legacy/non-touch mobile.
const isMobileDevice = computed(() => {
  if (typeof navigator === 'undefined') return false
  if (navigator.maxTouchPoints > 1) return true
  if (typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches) return true
  const ua = navigator.userAgent || ''
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua)
})

// Screen sharing requires getDisplayMedia API + a secure context (HTTPS).
// We intentionally trust capability detection here instead of UA/touch heuristics,
// because touch-enabled desktop devices (e.g. many Windows laptops) still support it.
const canScreenShare = computed(() => {
  if (typeof navigator === 'undefined') return false
  if (!navigator.mediaDevices?.getDisplayMedia) return false
  if (typeof window !== 'undefined' && !window.isSecureContext) return false
  return true
})

// Speaker switching via setSinkId is NOT supported on iOS Safari/Chrome.
// Only show the button when the browser actually supports audio output selection.
const canSwitchAudioOutput = computed(() => {
  if (typeof window === 'undefined') return false
  return 'setSinkId' in HTMLMediaElement.prototype
})

// ─── Effects panel state ────────────────────────────────────────────────────────

const showEffectsPanel = ref(false)

const hasActiveEffect = computed(() => callStore.videoEffectMode !== 'none')

const effectIcon = computed(() => {
  switch (callStore.videoEffectMode) {
    case 'blur': return 'blur_on'
    case 'virtual-bg': return 'wallpaper'
    default: return 'auto_awesome'
  }
})

// ─── "More" overflow menu (secondary actions) ──────────────────────────────────
// Secondary controls collapse here to keep the top-bar tidy on narrow widths.

const showMore = ref(false)
const moreRoot = ref(null)

const canFlip = computed(() => isMobileDevice.value && callStore.isVideoOn)
const hasMoreItems = computed(() => callStore.isVideoOn || canFlip.value)

function closeMore() {
  showMore.value = false
}

function openEffectsFromMore() {
  closeMore()
  showEffectsPanel.value = true
}

async function flipFromMore() {
  closeMore()
  await handleFlipCamera()
}

function handleMoreOutsideClick(e) {
  if (!showMore.value) return
  if (moreRoot.value && !moreRoot.value.contains(e.target)) showMore.value = false
}

function handleMoreKey(e) {
  if (e.key === 'Escape' && showMore.value) showMore.value = false
}

onMounted(() => {
  document.addEventListener('mousedown', handleMoreOutsideClick, true)
  document.addEventListener('keydown', handleMoreKey)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', handleMoreOutsideClick, true)
  document.removeEventListener('keydown', handleMoreKey)
})

// ─── Per-button loading guards ─────────────────────────────────────────────────
// Prevents a second click from queueing a duplicate operation while the first
// is still in flight (double-click, tap-and-tap on mobile, etc.).

const muteChanging        = ref(false)
const videoChanging       = ref(false)
const screenShareChanging = ref(false)
const flipChanging        = ref(false)
const hangingUp           = ref(false)

async function handleToggleMute() {
  if (muteChanging.value) return
  muteChanging.value = true
  try { await callStore.toggleMute() } finally { muteChanging.value = false }
}

async function handleToggleVideo() {
  if (videoChanging.value) return
  videoChanging.value = true
  try { await callStore.toggleVideo() } finally { videoChanging.value = false }
}

async function handleToggleScreenShare() {
  if (screenShareChanging.value) return
  screenShareChanging.value = true
  try { await callStore.toggleScreenShare() } finally { screenShareChanging.value = false }
}

async function handleFlipCamera() {
  if (flipChanging.value) return
  flipChanging.value = true
  try { await callStore.flipCamera() } finally { flipChanging.value = false }
}

async function handleHangUp() {
  if (hangingUp.value) return
  hangingUp.value = true
  try { await callStore.hangUp() } finally { hangingUp.value = false }
}

async function handleAudioInputSwitch(deviceId) {
  prefs.setAudioInput(deviceId)
  await callStore.switchAudioDevice(deviceId)
}

async function handleVideoInputSwitch(deviceId) {
  prefs.setVideoInput(deviceId)
  await callStore.switchVideoDevice(deviceId)
}

async function handleAudioOutputSwitch(deviceId) {
  prefs.setAudioOutput(deviceId)
  await callStore.switchAudioOutputDevice(deviceId)
}
</script>

<template>
  <div class="relative flex items-center gap-0.5 sm:gap-1">

    <!-- Camera (with device caret) -->
    <CallBarButton
      :icon="callStore.isVideoOn ? 'videocam' : 'videocam_off'"
      label="Camera"
      :title="callStore.isVideoOn ? 'Turn off camera' : 'Turn on camera'"
      :disabled="videoChanging"
      :buttonClass="callStore.isVideoOn ? 'text-white/90 hover:bg-white/10' : 'text-white/50 hover:bg-white/10'"
      @click="handleToggleVideo"
    >
      <template #chevron>
        <DeviceSelectorMenu
          kind="videoinput"
          align="down"
          flat
          :selectedDeviceId="prefs.videoInputId.value"
          @update:selectedDeviceId="handleVideoInputSwitch"
        />
      </template>
    </CallBarButton>

    <!-- Microphone (with device caret) -->
    <CallBarButton
      :icon="callStore.isAudioMuted ? 'mic_off' : 'mic'"
      label="Mic"
      :title="callStore.isAudioMuted ? 'Unmute' : 'Mute'"
      :disabled="muteChanging"
      :buttonClass="callStore.isAudioMuted ? 'text-red-400 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="handleToggleMute"
    >
      <template #chevron>
        <DeviceSelectorMenu
          kind="audioinput"
          align="down"
          flat
          :selectedDeviceId="prefs.audioInputId.value"
          @update:selectedDeviceId="handleAudioInputSwitch"
        />
      </template>
    </CallBarButton>

    <!-- Speaker/Earpiece toggle — touch devices only -->
    <CallBarButton
      v-if="isMobileDevice && canSwitchAudioOutput"
      :icon="callStore.isSpeakerOn ? 'volume_up' : 'phone_in_talk'"
      label="Speaker"
      :title="callStore.isSpeakerOn ? 'Switch to earpiece' : 'Switch to speaker'"
      :buttonClass="callStore.isSpeakerOn ? 'text-white/90 hover:bg-white/10' : 'text-amber-400 hover:bg-white/10'"
      @click="callStore.toggleSpeaker()"
    >
      <template #chevron>
        <DeviceSelectorMenu
          kind="audiooutput"
          align="down"
          flat
          :selectedDeviceId="prefs.audioOutputId.value"
          @update:selectedDeviceId="handleAudioOutputSwitch"
        />
      </template>
    </CallBarButton>

    <!-- Desktop audio-output selector -->
    <CallBarButton
      v-else-if="canSwitchAudioOutput && prefs.audioOutputDevices.value.length > 1"
      icon="volume_up"
      label="Audio"
      title="Audio output"
      buttonClass="text-white/90 hover:bg-white/10"
    >
      <template #chevron>
        <DeviceSelectorMenu
          kind="audiooutput"
          align="down"
          flat
          :selectedDeviceId="prefs.audioOutputId.value"
          @update:selectedDeviceId="handleAudioOutputSwitch"
        />
      </template>
    </CallBarButton>

    <!-- Screen share (desktop only, active call only, requires secure context) -->
    <CallBarButton
      v-if="callStore.isActive && canScreenShare"
      :icon="callStore.isScreenSharing ? 'stop_screen_share' : 'screen_share'"
      label="Share"
      :title="callStore.isScreenSharing ? 'Stop sharing' : 'Share screen'"
      :disabled="screenShareChanging"
      :buttonClass="callStore.isScreenSharing ? 'text-green-400 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="handleToggleScreenShare"
    />

    <!-- More (overflow: video effects, flip camera) -->
    <div v-if="hasMoreItems" ref="moreRoot" class="relative">
      <CallBarButton
        icon="more_horiz"
        label="More"
        title="More"
        :buttonClass="showMore ? 'text-white bg-white/10' : 'text-white/90 hover:bg-white/10'"
        @click="showMore = !showMore"
      />
      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0 -translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="showMore"
          class="absolute z-50 top-full mt-2 right-0 min-w-[200px] bg-surface-900/95 backdrop-blur-xl border border-surface-700 rounded-xl shadow-2xl overflow-hidden py-1"
        >
          <button
            v-if="callStore.isVideoOn"
            @click="openEffectsFromMore"
            class="w-full flex items-center gap-3 px-3 py-2.5 text-sm text-left transition-colors hover:bg-white/5"
            :class="hasActiveEffect ? 'text-purple-300' : 'text-white/80'"
          >
            <span class="material-symbols-rounded text-lg" :class="hasActiveEffect ? 'text-purple-400' : 'text-white/60'">{{ effectIcon }}</span>
            Video effects
          </button>
          <button
            v-if="canFlip"
            @click="flipFromMore"
            :disabled="flipChanging"
            class="w-full flex items-center gap-3 px-3 py-2.5 text-sm text-left text-white/80 hover:bg-white/5 transition-colors disabled:opacity-50"
          >
            <span class="material-symbols-rounded text-lg text-white/60">cameraswitch</span>
            Flip camera
          </button>
        </div>
      </Transition>
    </div>

    <!-- Divider -->
    <div class="w-px h-7 bg-white/15 mx-1.5 self-center"></div>

    <!-- Leave (flat red) -->
    <CallBarButton
      icon="call_end"
      label="Leave"
      title="Leave call"
      :disabled="hangingUp"
      buttonClass="text-red-400 hover:bg-red-500/15"
      labelClass="text-red-400"
      @click="handleHangUp"
    />

    <!-- Effects panel popover -->
    <CallEffectsPanel
      v-if="showEffectsPanel && callStore.isVideoOn"
      placement="below"
      @close="showEffectsPanel = false"
    />
  </div>
</template>
