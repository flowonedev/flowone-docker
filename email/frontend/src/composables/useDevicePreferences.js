/**
 * useDevicePreferences composable
 *
 * Single source of truth for the user's preferred audio input, video input,
 * and audio output devices for calls. Persists selections to localStorage,
 * keeps the live device list in sync via `navigator.mediaDevices.devicechange`,
 * and drops stale IDs (e.g. an unplugged headset) so callers can fall back
 * to a sensible default automatically.
 *
 * Implemented as a module-level singleton so every component/composable
 * shares the same reactive state — call `useDevicePreferences()` from
 * anywhere and you get the same refs.
 */

import { ref, readonly } from 'vue'

const STORAGE_KEYS = {
  audioInputId: 'flowone:call:audioInputId',
  videoInputId: 'flowone:call:videoInputId',
  audioOutputId: 'flowone:call:audioOutputId',
  skipPreCallModal: 'flowone:call:skipPreCallModal'
}

function readString(key) {
  try {
    const v = localStorage.getItem(key)
    return v && v.length > 0 ? v : null
  } catch (_) {
    return null
  }
}

function writeString(key, value) {
  try {
    if (value) localStorage.setItem(key, value)
    else localStorage.removeItem(key)
  } catch (_) { /* ignore quota / privacy mode */ }
}

function readBool(key) {
  try {
    return localStorage.getItem(key) === '1'
  } catch (_) {
    return false
  }
}

function writeBool(key, value) {
  try {
    if (value) localStorage.setItem(key, '1')
    else localStorage.removeItem(key)
  } catch (_) { /* ignore */ }
}

// ── Module-level singleton state ────────────────────────────────────────
const audioInputId = ref(readString(STORAGE_KEYS.audioInputId))
const videoInputId = ref(readString(STORAGE_KEYS.videoInputId))
const audioOutputId = ref(readString(STORAGE_KEYS.audioOutputId))
const skipPreCallModal = ref(readBool(STORAGE_KEYS.skipPreCallModal))

const audioInputDevices = ref([])
const videoInputDevices = ref([])
const audioOutputDevices = ref([])

const hasEnumeratedWithLabels = ref(false)
let initialized = false
let _changeListener = null

const canSwitchAudioOutput = (() => {
  if (typeof window === 'undefined') return false
  return 'setSinkId' in HTMLMediaElement.prototype
})()

/**
 * Enumerate devices and update the reactive lists.
 * Drops persisted IDs that are no longer present and emits the validated value
 * back through `setAudioInput` / `setVideoInput` / `setAudioOutput` so
 * downstream consumers (which watch the refs) re-react.
 */
async function enumerate() {
  try {
    if (!navigator?.mediaDevices?.enumerateDevices) return
    const devices = await navigator.mediaDevices.enumerateDevices()

    audioInputDevices.value = devices.filter(d => d.kind === 'audioinput')
    videoInputDevices.value = devices.filter(d => d.kind === 'videoinput')
    audioOutputDevices.value = devices.filter(d => d.kind === 'audiooutput')

    // Labels are only populated after the user has granted permission to at
    // least one device of that kind. Track this so the modal can show a
    // "Allow access to see device names" hint when needed.
    hasEnumeratedWithLabels.value = devices.some(d => !!d.label)

    // Validate persisted selections — if a device was unplugged, clear it.
    if (audioInputId.value && !audioInputDevices.value.some(d => d.deviceId === audioInputId.value)) {
      setAudioInput(null)
    }
    if (videoInputId.value && !videoInputDevices.value.some(d => d.deviceId === videoInputId.value)) {
      setVideoInput(null)
    }
    if (audioOutputId.value && !audioOutputDevices.value.some(d => d.deviceId === audioOutputId.value)) {
      setAudioOutput(null)
    }
  } catch (e) {
    console.warn('[DevicePreferences] enumerate failed:', e?.message || e)
  }
}

function setAudioInput(deviceId) {
  audioInputId.value = deviceId || null
  writeString(STORAGE_KEYS.audioInputId, audioInputId.value)
}

function setVideoInput(deviceId) {
  videoInputId.value = deviceId || null
  writeString(STORAGE_KEYS.videoInputId, videoInputId.value)
}

function setAudioOutput(deviceId) {
  audioOutputId.value = deviceId || null
  writeString(STORAGE_KEYS.audioOutputId, audioOutputId.value)
}

function setSkipPreCallModal(skip) {
  skipPreCallModal.value = !!skip
  writeBool(STORAGE_KEYS.skipPreCallModal, skipPreCallModal.value)
}

/**
 * Resolve the effective device ID for a kind — either the user's stored
 * preference if it still exists, or the system default (`'default'` or the
 * first device, depending on browser).
 */
function resolveAudioInputId() {
  if (audioInputId.value && audioInputDevices.value.some(d => d.deviceId === audioInputId.value)) {
    return audioInputId.value
  }
  return audioInputDevices.value[0]?.deviceId || null
}

function resolveVideoInputId() {
  if (videoInputId.value && videoInputDevices.value.some(d => d.deviceId === videoInputId.value)) {
    return videoInputId.value
  }
  return videoInputDevices.value[0]?.deviceId || null
}

function resolveAudioOutputId() {
  if (audioOutputId.value && audioOutputDevices.value.some(d => d.deviceId === audioOutputId.value)) {
    return audioOutputId.value
  }
  return audioOutputDevices.value[0]?.deviceId || null
}

function ensureInitialized() {
  if (initialized) return
  initialized = true

  enumerate()

  if (typeof navigator !== 'undefined' && navigator.mediaDevices?.addEventListener) {
    _changeListener = () => { enumerate() }
    navigator.mediaDevices.addEventListener('devicechange', _changeListener)
  }
}

export function useDevicePreferences() {
  ensureInitialized()

  return {
    // Reactive selections (writable via setters)
    audioInputId: readonly(audioInputId),
    videoInputId: readonly(videoInputId),
    audioOutputId: readonly(audioOutputId),
    skipPreCallModal: readonly(skipPreCallModal),

    // Reactive device lists
    audioInputDevices: readonly(audioInputDevices),
    videoInputDevices: readonly(videoInputDevices),
    audioOutputDevices: readonly(audioOutputDevices),
    hasEnumeratedWithLabels: readonly(hasEnumeratedWithLabels),

    // Capabilities
    canSwitchAudioOutput,

    // Setters
    setAudioInput,
    setVideoInput,
    setAudioOutput,
    setSkipPreCallModal,

    // Resolvers — return current effective ID (preference if valid, else default)
    resolveAudioInputId,
    resolveVideoInputId,
    resolveAudioOutputId,

    // Manual re-enumerate (e.g. after gUM grants permission so labels populate)
    enumerate
  }
}
