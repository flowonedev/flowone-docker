/**
 * useCallLauncher composable
 *
 * Thin orchestration layer between call triggers and the call store.
 *
 * When a user starts/accepts/joins a call, this composable shows the
 * PreCallDeviceModal so they can confirm or change their mic/camera/speaker
 * before the connection is established. The actual call work is still
 * delegated to the call store; the launcher only handles the modal handshake.
 *
 * Modal-visibility state is module-level so a single instance of
 * PreCallDeviceModal in App.vue serves every trigger in the app.
 */

import { computed, ref } from 'vue'
import { useCallStore } from '@/stores/call'
import { useDevicePreferences } from '@/composables/useDevicePreferences'

// ── Module-level singleton modal state ──────────────────────────────────
const isModalOpen = ref(false)
const modalMode = ref('outgoing') // 'outgoing' | 'incoming-accept' | 'join-existing' | 'guest-prejoin'
const modalCallType = ref('video')
const modalCallerName = ref('')

// Pending promise resolver while the modal is open
let pendingResolve = null

function openModal({ mode, callType, callerName }) {
  modalMode.value = mode || 'outgoing'
  modalCallType.value = callType || 'video'
  modalCallerName.value = callerName || ''
  isModalOpen.value = true

  return new Promise((resolve) => {
    // If something already had a pending resolver, cancel it first so
    // back-to-back triggers don't leave a dangling promise.
    if (pendingResolve) {
      try { pendingResolve({ confirmed: false, payload: null }) } catch (_) { /* ignore */ }
    }
    pendingResolve = resolve
  })
}

function confirmModal(payload) {
  isModalOpen.value = false
  const resolve = pendingResolve
  pendingResolve = null
  if (resolve) resolve({ confirmed: true, payload })
}

function cancelModal() {
  isModalOpen.value = false
  const resolve = pendingResolve
  pendingResolve = null
  if (resolve) resolve({ confirmed: false, payload: null })
}

/**
 * Build a payload from the saved preferences without showing the modal —
 * used when the user opted into "skip pre-call screen next time".
 */
function buildPayloadFromPrefs(callType) {
  const prefs = useDevicePreferences()
  return {
    audioInputId: prefs.resolveAudioInputId(),
    videoInputId: callType === 'video' ? prefs.resolveVideoInputId() : null,
    audioOutputId: prefs.resolveAudioOutputId(),
    joinMuted: false,
    joinCamOff: callType !== 'video'
  }
}

export function useCallLauncher() {
  const callStore = useCallStore()
  const prefs = useDevicePreferences()

  /**
   * Show the modal (or skip it) and start an outgoing call.
   */
  async function startCall(convId, callType, participants) {
    if (callStore.isInCall) {
      console.warn('[CallLauncher] Already in a call — ignoring startCall')
      return
    }

    let payload
    if (prefs.skipPreCallModal.value) {
      payload = buildPayloadFromPrefs(callType)
    } else {
      const result = await openModal({ mode: 'outgoing', callType })
      if (!result.confirmed) return
      payload = result.payload
    }

    await callStore.initiateCall(convId, callType, participants, {
      audioInputId: payload.audioInputId,
      videoInputId: payload.videoInputId,
      audioOutputId: payload.audioOutputId,
      joinMuted: payload.joinMuted,
      joinCamOff: payload.joinCamOff
    })
  }

  /**
   * Show the modal then answer an incoming ringing call.
   */
  async function acceptIncomingCall() {
    if (callStore.callStatus !== 'ringing') {
      console.warn('[CallLauncher] No ringing call to accept')
      return
    }
    const callType = callStore.callType || 'video'
    const callerName = callStore.callerInfo?.name
      || callStore.callerInfo?.email?.split('@')[0]
      || ''

    let payload
    if (prefs.skipPreCallModal.value) {
      payload = buildPayloadFromPrefs(callType)
    } else {
      const result = await openModal({
        mode: 'incoming-accept',
        callType,
        callerName
      })
      if (!result.confirmed) return
      payload = result.payload
    }

    await callStore.answerCall({
      audioInputId: payload.audioInputId,
      videoInputId: payload.videoInputId,
      audioOutputId: payload.audioOutputId,
      joinMuted: payload.joinMuted,
      joinCamOff: payload.joinCamOff
    })
  }

  /**
   * Show the modal then join an already-active call in a conversation.
   */
  async function joinExistingCall(convId, activeCall) {
    if (callStore.isInCall) {
      console.warn('[CallLauncher] Already in a call — ignoring joinExistingCall')
      return
    }
    const callType = activeCall?.callType || 'video'

    let payload
    if (prefs.skipPreCallModal.value) {
      payload = buildPayloadFromPrefs(callType)
    } else {
      const result = await openModal({ mode: 'join-existing', callType })
      if (!result.confirmed) return
      payload = result.payload
    }

    await callStore.joinCall(convId, activeCall, {
      audioInputId: payload.audioInputId,
      videoInputId: payload.videoInputId,
      audioOutputId: payload.audioOutputId,
      joinMuted: payload.joinMuted,
      joinCamOff: payload.joinCamOff
    })
  }

  return {
    // Modal state (read by App.vue's <PreCallDeviceModal>)
    isModalOpen: computed(() => isModalOpen.value),
    modalMode: computed(() => modalMode.value),
    modalCallType: computed(() => modalCallType.value),
    modalCallerName: computed(() => modalCallerName.value),

    // Modal control (called by the App.vue modal listeners)
    confirmModal,
    cancelModal,

    // Public actions (called by triggers)
    startCall,
    acceptIncomingCall,
    joinExistingCall
  }
}
