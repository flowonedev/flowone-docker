/**
 * useMediaDevices composable
 * 
 * Manages media devices (microphone, camera) for WebRTC calls.
 * Handles device enumeration, permission requests, and stream management.
 */

import { ref, markRaw } from 'vue'
import { useDevicePreferences } from './useDevicePreferences'

export function useMediaDevices() {
  const prefs = useDevicePreferences()

  const localStream = ref(null)
  const screenStream = ref(null)
  const audioDevices = ref([])
  const videoDevices = ref([])
  // selectedAudioDevice / selectedVideoDevice can be set explicitly per-call;
  // when null, getUserMedia falls back to the user's saved preference.
  const selectedAudioDevice = ref(null)
  const selectedVideoDevice = ref(null)
  const isAudioEnabled = ref(true)
  const isVideoEnabled = ref(false)
  const isScreenSharing = ref(false)
  const permissionError = ref(null)

  // Guard refs for async operations
  const videoChanging = ref(false)
  const cameraTrack = ref(null)

  // Request ID counters to detect stale async results
  let videoSwitchId = 0
  let audioSwitchId = 0

  // Hot-plug listener — re-enumerates whenever a device is added/removed
  // so callers (UI dropdowns, in-call menus) see the change immediately.
  let _deviceChangeListener = null
  if (typeof navigator !== 'undefined' && navigator.mediaDevices?.addEventListener) {
    _deviceChangeListener = () => { enumerateDevices() }
    navigator.mediaDevices.addEventListener('devicechange', _deviceChangeListener)
  }

  // Resolve the effective device ID — explicit selection wins, otherwise
  // fall through to the user's saved preference.
  function effectiveAudioDeviceId() {
    return selectedAudioDevice.value || prefs.audioInputId.value || null
  }
  function effectiveVideoDeviceId() {
    return selectedVideoDevice.value || prefs.videoInputId.value || null
  }

  /**
   * Enumerate available media devices.
   * Call only after getUserMedia() has resolved so that device labels are populated.
   */
  async function enumerateDevices() {
    try {
      if (!navigator.mediaDevices?.enumerateDevices) return
      const devices = await navigator.mediaDevices.enumerateDevices()
      audioDevices.value = devices.filter(d => d.kind === 'audioinput')
      videoDevices.value = devices.filter(d => d.kind === 'videoinput')
    } catch (e) {
      console.error('[MediaDevices] Failed to enumerate:', e)
    }
  }

  /**
   * Get user media (microphone and/or camera).
   * Stops any existing localStream tracks before replacing the reference.
   */
  async function getUserMedia({ audio = true, video = false } = {}) {
    try {
      permissionError.value = null

      if (!navigator.mediaDevices?.getUserMedia) {
        throw new DOMException(
          'Media devices are not available. Please check your browser or app permissions.',
          'NotSupportedError'
        )
      }

      const audioId = effectiveAudioDeviceId()
      const videoId = effectiveVideoDeviceId()

      const constraints = {
        audio: audio ? {
          deviceId: audioId ? { exact: audioId } : undefined,
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        } : false,
        video: video ? {
          deviceId: videoId ? { exact: videoId } : undefined,
          width: { ideal: 1280 },
          height: { ideal: 720 },
          frameRate: { ideal: 30 }
        } : false
      }

      const stream = await navigator.mediaDevices.getUserMedia(constraints)

      // Stop all existing tracks before replacing — prevents camera LED staying on
      if (localStream.value) {
        localStream.value.getTracks().forEach(t => t.stop())
      }

      localStream.value = markRaw(stream)

      // Track the camera track separately so toggleVideo/switchVideo target only it
      cameraTrack.value = video ? stream.getVideoTracks()[0] ?? null : null

      isAudioEnabled.value = audio
      isVideoEnabled.value = video

      // Enumerate after permission is granted so labels are populated
      await enumerateDevices()

      return stream
    } catch (e) {
      console.error('[MediaDevices] getUserMedia failed:', e)
      if (e.name === 'NotAllowedError') {
        permissionError.value = 'Permission denied. Please allow access to your microphone/camera.'
      } else if (e.name === 'NotFoundError') {
        permissionError.value = 'No microphone or camera found on this device.'
      } else {
        permissionError.value = `Failed to access media devices: ${e.message}`
      }
      throw e
    }
  }

  /**
   * Toggle microphone mute/unmute
   */
  function toggleAudio() {
    if (!localStream.value) return
    const audioTracks = localStream.value.getAudioTracks()
    const nextEnabled = !audioTracks.some(t => t.enabled)
    audioTracks.forEach(track => { track.enabled = nextEnabled })
    isAudioEnabled.value = nextEnabled
    return isAudioEnabled.value
  }

  /**
   * Toggle camera on/off.
   * Guards against concurrent invocations (double-click race).
   */
  async function toggleVideo() {
    if (!localStream.value) return
    if (videoChanging.value) return

    videoChanging.value = true
    try {
      // Only remove the tracked camera track — never removes screen share tracks
      if (cameraTrack.value) {
        cameraTrack.value.stop()
        localStream.value.removeTrack(cameraTrack.value)
        cameraTrack.value = null
        isVideoEnabled.value = false
      } else {
        const videoId = effectiveVideoDeviceId()
        const videoStream = await navigator.mediaDevices.getUserMedia({
          video: {
            deviceId: videoId ? { exact: videoId } : undefined,
            width: { ideal: 1280 },
            height: { ideal: 720 }
          }
        })
        const videoTrack = videoStream.getVideoTracks()[0]
        localStream.value.addTrack(videoTrack)
        cameraTrack.value = videoTrack
        isVideoEnabled.value = true
      }
    } catch (e) {
      console.error('[MediaDevices] Failed to toggle video:', e)
      isVideoEnabled.value = false
    } finally {
      videoChanging.value = false
    }

    return isVideoEnabled.value
  }

  /**
   * Start screen sharing.
   * Returns the existing screen stream immediately if already sharing.
   */
  async function startScreenShare() {
    if (isScreenSharing.value) return screenStream.value

    try {
      const stream = await navigator.mediaDevices.getDisplayMedia({
        video: {
          cursor: 'always',
          width: { ideal: 1920 },
          height: { ideal: 1080 },
          frameRate: { ideal: 30, max: 60 }
        },
        audio: false
      })

      screenStream.value = stream
      isScreenSharing.value = true

      // Capture stream reference so that a stale onended from a previous session
      // does not accidentally stop a newer screen share session
      const capturedStream = stream
      stream.getVideoTracks()[0].onended = () => {
        if (screenStream.value === capturedStream) {
          stopScreenShare()
        }
      }

      return stream
    } catch (e) {
      console.error('[MediaDevices] Screen share failed:', e)
      isScreenSharing.value = false
      throw e
    }
  }

  /**
   * Stop screen sharing
   */
  function stopScreenShare() {
    if (screenStream.value) {
      screenStream.value.getTracks().forEach(t => t.stop())
      screenStream.value = null
    }
    isScreenSharing.value = false
  }

  /**
   * Switch audio input device.
   * Uses a request-ID counter to discard results from superseded calls.
   */
  async function switchAudioDevice(deviceId) {
    selectedAudioDevice.value = deviceId
    const requestId = ++audioSwitchId

    if (!localStream.value) return

    localStream.value.getAudioTracks().forEach(t => t.stop())

    const stream = await navigator.mediaDevices.getUserMedia({
      audio: {
        deviceId: { exact: deviceId },
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      }
    })

    // Discard if a newer switchAudioDevice call has already started
    if (requestId !== audioSwitchId) {
      stream.getTracks().forEach(t => t.stop())
      return
    }

    const newTrack = stream.getAudioTracks()[0]

    // Stop and release all remaining tracks on the temporary stream
    stream.getTracks().forEach(t => { if (t !== newTrack) t.stop() })

    const oldTrack = localStream.value.getAudioTracks()[0]
    if (oldTrack) localStream.value.removeTrack(oldTrack)
    localStream.value.addTrack(newTrack)

    return newTrack
  }

  /**
   * Switch video input device.
   * Uses a request-ID counter to discard results from superseded calls.
   */
  async function switchVideoDevice(deviceId) {
    selectedVideoDevice.value = deviceId
    const requestId = ++videoSwitchId

    if (!localStream.value || !isVideoEnabled.value) return

    localStream.value.getVideoTracks().forEach(t => t.stop())

    const stream = await navigator.mediaDevices.getUserMedia({
      video: {
        deviceId: { exact: deviceId },
        width: { ideal: 1280 },
        height: { ideal: 720 }
      }
    })

    // Discard if a newer switchVideoDevice call has already started
    if (requestId !== videoSwitchId) {
      stream.getTracks().forEach(t => t.stop())
      return
    }

    const newTrack = stream.getVideoTracks()[0]

    // Stop and release all remaining tracks on the temporary stream
    stream.getTracks().forEach(t => { if (t !== newTrack) t.stop() })

    const oldTrack = localStream.value.getVideoTracks()[0]
    if (oldTrack) localStream.value.removeTrack(oldTrack)
    localStream.value.addTrack(newTrack)
    cameraTrack.value = newTrack

    return newTrack
  }

  /**
   * Stop all media tracks and clean up
   */
  function cleanup() {
    if (localStream.value) {
      localStream.value.getTracks().forEach(t => t.stop())
      localStream.value = null
    }
    cameraTrack.value = null
    stopScreenShare()
    isAudioEnabled.value = true
    isVideoEnabled.value = false
    permissionError.value = null
    // Reset device selections so stale IDs don't cause NotFoundError on next call
    selectedAudioDevice.value = null
    selectedVideoDevice.value = null

    // Detach hot-plug listener so we don't leak it across short-lived calls
    if (_deviceChangeListener && navigator?.mediaDevices?.removeEventListener) {
      try {
        navigator.mediaDevices.removeEventListener('devicechange', _deviceChangeListener)
      } catch (_) { /* ignore */ }
      _deviceChangeListener = null
    }
  }

  return {
    // State
    localStream,
    screenStream,
    audioDevices,
    videoDevices,
    selectedAudioDevice,
    selectedVideoDevice,
    isAudioEnabled,
    isVideoEnabled,
    isScreenSharing,
    permissionError,

    // Methods
    enumerateDevices,
    getUserMedia,
    toggleAudio,
    toggleVideo,
    startScreenShare,
    stopScreenShare,
    switchAudioDevice,
    switchVideoDevice,
    cleanup
  }
}
