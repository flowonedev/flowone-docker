/**
 * Call Store (Pinia)
 * 
 * Orchestrates voice/video calls using LiveKit SFU.
 * 
 * Architecture: LiveKit replaces the previous mesh WebRTC approach.
 * Instead of managing N peer connections per participant, the client
 * connects to a single LiveKit room and the SFU handles all track routing.
 * 
 * Coordinates:
 * - WebSocket signaling (via mailSyncSocket) for call lifecycle
 * - LiveKit SFU (via useLiveKit) for media routing
 * - Media devices (via useMediaDevices) for local media capture
 * - Call history (via API)
 */

import { defineStore } from 'pinia'
import { ref, computed, watch, markRaw } from 'vue'
import { useMailSyncSocket } from '@/services/mailSyncSocket'
import { EventTypes } from '@/services/mailSyncSocket'
import { useLiveKit } from '@/composables/useLiveKit'
import { Track } from 'livekit-client'
import { useMediaDevices } from '@/composables/useMediaDevices'
import { useDevicePreferences } from '@/composables/useDevicePreferences'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { isDebugEnabled } from '@/utils/debug'
import { nativeLog } from '@/services/nativeLog'
import api from '@/services/api'

export const useCallStore = defineStore('call', () => {
  // ============================================
  // STATE
  // ============================================
  
  const callId = ref(null)
  const callType = ref(null)           // 'voice' or 'video'
  const callStatus = ref('idle')       // idle, initiating, ringing, active, ended
  const callDirection = ref(null)      // 'outgoing' or 'incoming'
  const conversationId = ref(null)
  const participants = ref([])          // Array of { email, name, avatar, mediaState }
  const callerInfo = ref(null)          // For incoming calls: { email, name }
  const callStartTime = ref(null)
  const callAnswerTime = ref(null)
  const isMinimized = ref(false)
  const callError = ref(null)

  // iOS/Safari block WebRTC audio autoplay until a user gesture. When the call
  // is answered from the native CallKit/FSI screen the WebView never gets that
  // gesture, so remote audio elements fail to .play() (NotAllowedError) and the
  // user hears nothing. ParticipantTile flips this on; CallOverlay surfaces a
  // "tap to enable sound" affordance whose handler runs enableAudioPlayback().
  const audioPlaybackBlocked = ref(false)
  
  // Native system call UI coordination (iOS CallKit / Android full-screen-intent).
  // When a VoIP/FSI push presents the ring through the OS, the native plugin sets
  // this to the ringing callId so the in-app IncomingCallModal stands down (no
  // double UI). Accept/Decline from the system UI flow back in through
  // acceptFromNative/rejectFromNative below.
  const nativeRingCallId = ref(null)
  // A decision taken in the system UI before the WS CALL_INITIATE arrives (the
  // app was killed and woken by the push). Consumed by handleIncomingCall.
  let _pendingNativeAction = null

  // Active call in current conversation (from server query - not our call)
  const activeCallInConversation = ref(null)  // { callId, callType, participants, initiatedBy, startedAt }
  
  // Map of conversationId -> { callId, callType, participants } for sidebar call indicators
  const conversationActiveCalls = ref({})
  
  // Media state (our own)
  const isAudioMuted = ref(false)
  const isVideoOn = ref(false)
  const isScreenSharing = ref(false)
  const isSpeakerOn = ref(false) // false = earpiece (default for voice), true = loudspeaker
  const screenShareSurface = ref(null) // 'monitor' | 'window' | 'browser' | null

  // Video effects state: 'none' | 'blur' | 'virtual-bg'
  const videoEffectMode = ref('none')
  const blurRadius = ref(10)
  const virtualBgUrl = ref(null)

  // Persistent background processor instance (avoids flash on switchTo)
  let _bgProcessor = null
  let _bgProcessorReady = false
  
  // LiveKit and media composables (scoped per call)
  let livekit = null
  let media = null
  
  // Guard flag: true while answerCall() is in progress, prevents CALL_DISMISSED
  // from racing with the answer flow on the same device
  let isLocallyAnswering = false
  let socketListenersInitialized = false
  let _callEpoch = 0
  let _cleanupTimer = null
  let _ringTimeout = null
  let _savedCallRecordKey = null

  // Screen share: remembers whether camera was on before screen share started
  // so it can be restored when screen share stops
  let _wasVideoOnBeforeScreenShare = false
  // Camera flip: tracks current facing mode ('user' = front, 'environment' = back)
  let _currentFacingMode = 'user'
  // Pre-call device/join options (set by initiateCall/answerCall/joinCall, consumed by connectToLiveKitRoom)
  let _pendingJoinOptions = null
  
  // ============================================
  // MEDIA OPERATION LOCK
  // Serializes all media control operations (mute, video, flip, switch)
  // so rapid tapping or interleaving cannot produce stale-state reads,
  // duplicate getUserMedia calls, or concurrent LiveKit mutations.
  // ============================================
  let _mediaOpInFlight = Promise.resolve()
  
  async function withMediaLock(fn) {
    const prev = _mediaOpInFlight
    let resolve
    _mediaOpInFlight = new Promise(r => { resolve = r })
    await prev.catch(() => {}) // don't cascade previous failures
    try {
      return await fn()
    } finally {
      resolve()
    }
  }
  
  // ============================================
  // HELPERS — Colleague lookup for participant avatars
  // ============================================
  const colleagues = useColleaguesStore()

  function normalizeEmail(email) {
    return String(email || '').trim().toLowerCase()
  }

  function refreshParticipantProfiles() {
    for (const p of participants.value) {
      const normalized = normalizeEmail(p.email)
      const colleague = colleagues.colleagueByEmail?.[normalized]
      if (!colleague) continue
      p.email = normalized
      p.avatar = colleagues.getAvatarUrl(colleague) || p.avatar || null
      if (!p.name || p.name === 'Unknown' || p.name === normalized.split('@')[0]) {
        p.name = colleague.display_name || p.name
      }
    }
  }
  
  function _resolveParticipant(email, overrides = {}) {
    const normalizedEmail = normalizeEmail(email)
    const colleague = colleagues.colleagueByEmail?.[normalizedEmail]
    return {
      email: normalizedEmail,
      name: colleague?.display_name || normalizedEmail?.split('@')[0] || 'Unknown',
      avatar: colleague ? colleagues.getAvatarUrl(colleague) : null,
      ...overrides
    }
  }

  watch(() => colleagues.colleagues.length, () => {
    refreshParticipantProfiles()
  })

  // ============================================
  // COMPUTED
  // ============================================
  
  const isInCall = computed(() => {
    return ['initiating', 'ringing', 'active'].includes(callStatus.value)
  })
  
  const isRinging = computed(() => callStatus.value === 'ringing')
  const isActive = computed(() => callStatus.value === 'active')
  // True while the OS call UI (CallKit / Android FSI) owns the current ring, so
  // the in-app IncomingCallModal can suppress itself to avoid double UI.
  const nativeRingActive = computed(() => !!nativeRingCallId.value && nativeRingCallId.value === callId.value)
  
  // Explicit reactive refs - ensures Vue tracks changes when livekit/media
  // transition from null to an object
  const _localStreamRef = ref(null)
  const _remoteStreamsRef = ref(new Map())
  const _remoteScreenStreamsRef = ref(new Map())
  const _remoteScreenTracksRef = ref(new Map())
  const _screenStreamRef = ref(null)
  
  const remoteStreams = computed(() => _remoteStreamsRef.value)
  const remoteScreenStreams = computed(() => _remoteScreenStreamsRef.value)
  const remoteScreenTracks = computed(() => _remoteScreenTracksRef.value)
  const localStream = computed(() => _localStreamRef.value)
  const screenStream = computed(() => _screenStreamRef.value)

  // Emails (lowercase) currently speaking — drives the speaking ring on tiles
  const speakingParticipants = ref(new Set())

  // Direct callback for screen share track attachment — bypasses Vue reactivity entirely.
  // CallOverlay registers a handler; when a screen share track arrives, it's called directly.
  let _screenShareHandler = null
  function onScreenShareTrack(handler) { _screenShareHandler = handler }
  function offScreenShareTrack() { _screenShareHandler = null }
  
  // ============================================
  // WEBSOCKET EVENT HANDLERS
  // ============================================
  
  function setupSocketListeners() {
    if (socketListenersInitialized) return
    socketListenersInitialized = true

    const socket = useMailSyncSocket()
    
    socket.on(EventTypes.CALL_INITIATE, handleIncomingCall)
    socket.on(EventTypes.CALL_RINGING, handleCallRinging)
    socket.on(EventTypes.CALL_ANSWER, handleCallAnswered)
    socket.on(EventTypes.CALL_REJECT, handleCallRejected)
    socket.on(EventTypes.CALL_HANGUP, handleCallHangup)
    socket.on(EventTypes.CALL_MEDIA_STATE, handleRemoteMediaState)
    socket.on(EventTypes.CALL_MISSED, handleCallMissed)
    socket.on(EventTypes.CALL_DISMISSED, handleCallDismissed)
    socket.on(EventTypes.CALL_ACTIVE_STATUS, handleActiveCallStatus)
    
    // On WebSocket reconnect, verify active call state with the server
    socket.on(EventTypes.CONNECTED, handleReconnectCallRecovery)
    
    socket.on(EventTypes.CALL_SCREEN_SHARE_START, handleRemoteScreenShareStart)
    socket.on(EventTypes.CALL_SCREEN_SHARE_STOP, handleRemoteScreenShareStop)
    socket.on(EventTypes.CALL_PARTICIPANT_JOINED, handleParticipantJoined)
  }

  function beginCallSession() {
    _callEpoch += 1
    _savedCallRecordKey = null
    clearScheduledCleanup()
    clearRingTimeout()
  }

  function clearScheduledCleanup() {
    if (_cleanupTimer) {
      clearTimeout(_cleanupTimer)
      _cleanupTimer = null
    }
  }

  function clearRingTimeout() {
    if (_ringTimeout) {
      clearTimeout(_ringTimeout)
      _ringTimeout = null
    }
  }

  function scheduleCleanup(delayMs) {
    clearScheduledCleanup()
    const epochAtSchedule = _callEpoch
    _cleanupTimer = setTimeout(() => {
      if (epochAtSchedule !== _callEpoch) return
      cleanupCall()
    }, delayMs)
  }

  function saveCallRecordOnce(data) {
    const recordCallId = data?.call_id || callId.value
    if (!recordCallId) return
    const key = `${_callEpoch}:${recordCallId}`
    if (_savedCallRecordKey === key) return
    _savedCallRecordKey = key
    saveCallRecord(data)
  }
  
  /**
   * On WebSocket reconnect, verify that any active call still exists server-side.
   */
  function handleReconnectCallRecovery() {
    if (!callId.value || callStatus.value === 'idle' || callStatus.value === 'ended') return
    
    isDebugEnabled() && console.log('[CallStore] WebSocket reconnected during active call, verifying call state...')
    const socket = useMailSyncSocket()
    
    socket.send({
      type: 'CALL_ACTIVE_QUERY',
      conversationId: conversationId.value
    })
    
    // Re-broadcast our current media state so remote peers get fresh data
    if (callStatus.value === 'active') {
      broadcastMediaState()
      isDebugEnabled() && console.log('[CallStore] Re-broadcast media state after reconnect')
    }
    
    // Safety timeout: if LiveKit is disconnected, clean up
    const recoveryEpoch = _callEpoch
    setTimeout(() => {
      if (recoveryEpoch !== _callEpoch) return
      if (callId.value && callStatus.value === 'active' && livekit && livekit.connectionState.value === 'closed') {
        console.warn('[CallStore] Call state recovery: LiveKit disconnected, cleaning up')
        callStatus.value = 'ended'
        cleanupCall()
      }
    }, 10000)
  }
  
  function handleIncomingCall(event) {
    const payload = event.payload || event
    
    isDebugEnabled() && console.log('[CallStore] Incoming call:', {
      callId: payload.callId,
      callType: payload.callType,
      callerEmail: payload.callerEmail,
      participants: payload.participants
    })
    
    if (callStatus.value !== 'idle') {
      if (callId.value && callId.value === payload.callId && callDirection.value === 'incoming') {
        isDebugEnabled() && console.log('[CallStore] Duplicate CALL_INITIATE received for same call, ignoring')
        return
      }
      isDebugEnabled() && console.log('[CallStore] Busy on another call flow, rejecting incoming call')
      sendSignal('CALL_REJECT', {
        callId: payload.callId,
        reason: 'busy'
      })
      return
    }

    beginCallSession()
    callId.value = payload.callId
    callType.value = payload.callType
    callStatus.value = 'ringing'
    callDirection.value = 'incoming'
    conversationId.value = payload.conversationId
    callerInfo.value = {
      email: payload.callerEmail,
      name: payload.callerName || payload.callerEmail?.split('@')[0]
    }
    
    // Filter out our own email from participants
    const auth = useAuthStore()
    const myEmail = auth.userEmail?.toLowerCase()
    participants.value = (payload.participants || [])
      .filter(email => email.toLowerCase() !== myEmail)
      .map(email => {
        const normalized = String(email || '').trim().toLowerCase()
        const isCaller = normalized === String(payload.callerEmail || '').trim().toLowerCase()
        return _resolveParticipant(normalized, {
          name: isCaller ? (payload.callerName || normalized.split('@')[0]) : undefined,
          mediaState: { audio: true, video: payload.callType === 'video', screenShare: false }
        })
      })
    
    // Track this call for sidebar indicators
    if (payload.conversationId) {
      conversationActiveCalls.value = {
        ...conversationActiveCalls.value,
        [payload.conversationId]: {
          callId: payload.callId,
          callType: payload.callType,
          participants: payload.participants || [],
          initiatedBy: payload.callerEmail,
          conversationId: payload.conversationId
        }
      }
    }
    
    isDebugEnabled() && console.log('[CallStore] Call state set: status=ringing, direction=incoming → IncomingCallModal should show')

    // The system call UI (CallKit / Android FSI) may have presented this ring —
    // and even captured the user's Accept/Decline — before this WS event landed
    // (app was killed and woken by the VoIP/FSI push). Apply that decision now
    // instead of ringing in-app a second time.
    if (_pendingNativeAction && (!_pendingNativeAction.callId || _pendingNativeAction.callId === payload.callId)) {
      const pending = _pendingNativeAction
      _pendingNativeAction = null
      isDebugEnabled() && console.log('[CallStore] Applying pending native decision:', pending.action)
      nativeLog('[CallStore] CALL_INITIATE landed; applying queued native ' + pending.action +
        ' callId=' + payload.callId)
      if (pending.action === 'accept') { answerCall(pending.options || {}); return }
      if (pending.action === 'reject') { rejectCall(payload.callId, pending.reason || 'declined'); return }
    }

    playRingtone()

    // Safety net: the server stops ringing after CALL_TIMEOUT (~30s) and sends
    // CALL_MISSED, but that socket event can be lost if our WS is suspended
    // (e.g. app backgrounded on iOS) — leaving this incoming-call modal ringing
    // forever even though a missed-call push already arrived. Auto-dismiss a bit
    // past the server timeout so the modal can never get stuck. The CALL_MISSED/
    // CALL_DISMISSED handlers clear this timer first when they do arrive.
    clearRingTimeout()
    const ringEpoch = _callEpoch
    const missedPayload = {
      callId: payload.callId,
      conversationId: payload.conversationId,
      callType: payload.callType,
      callerEmail: payload.callerEmail,
      callerName: payload.callerName
    }
    _ringTimeout = setTimeout(() => {
      _ringTimeout = null
      // Bail if this is a stale timer or the call already progressed/cleared.
      if (ringEpoch !== _callEpoch) return
      if (callStatus.value !== 'ringing' || callDirection.value !== 'incoming') return
      isDebugEnabled() && console.warn('[CallStore] Incoming call ring timeout — clearing stuck modal (missed)')
      stopRingtone()
      callStatus.value = 'ended'
      persistMissedCallNotification(missedPayload)
      scheduleCleanup(500)
    }, 38000)
  }
  
  async function handleCallRinging(event) {
    const payload = event.payload || event

    if (callStatus.value === 'active') {
      isDebugEnabled() && console.log('[CallStore] Ignoring CALL_RINGING - call already active')
      return
    }

    if (callStatus.value !== 'initiating' && callStatus.value !== 'ringing') {
      isDebugEnabled() && console.log('[CallStore] Ignoring CALL_RINGING - this device did not initiate the call')
      return
    }

    if (callId.value && callId.value !== payload.callId) {
      isDebugEnabled() && console.log('[CallStore] Ignoring CALL_RINGING for different callId', payload.callId)
      return
    }

    isDebugEnabled() && console.log('[CallStore] Call is ringing, callId:', payload.callId)
    callId.value = payload.callId
    callStatus.value = 'ringing'
    playRingback()
    
    // Now that we have the callId (room name), connect to LiveKit room.
    // The caller connects early so they're already in the room when the callee joins.
    if (livekit) {
      try {
        const hasLocalMedia = !!(media?.localStream?.value)
        await connectToLiveKitRoom(hasLocalMedia, _pendingJoinOptions || {})
        isDebugEnabled() && console.log('[CallStore] Caller connected to LiveKit room:', payload.callId)
      } catch (e) {
        console.error('[CallStore] Failed to connect caller to LiveKit room:', e)
        callError.value = 'Failed to connect to call server'
      }
    }
  }
  
  async function handleCallAnswered(event) {
    const payload = event.payload || event

    // Race-safe guard: caller may receive CALL_ANSWER before CALL_RINGING(callId assignment)
    if (!callId.value && callDirection.value === 'outgoing' && callStatus.value === 'initiating') {
      callId.value = payload.callId
    }

    if (!callId.value || callId.value !== payload.callId) {
      isDebugEnabled() && console.log('[CallStore] Ignoring CALL_ANSWER - this device is not in this call', payload.callId)
      return
    }

    if (!['initiating', 'ringing', 'active'].includes(callStatus.value)) {
      isDebugEnabled() && console.log('[CallStore] Ignoring CALL_ANSWER in terminal state', callStatus.value)
      return
    }

    stopRingtone()
    stopRingback()

    clearScheduledCleanup()
    callStatus.value = 'active'
    callAnswerTime.value = Date.now()
    
    // Mark participant as connected
    if (payload.answeredBy) {
      const answeredBy = normalizeEmail(payload.answeredBy)
      const p = participants.value.find(p => p.email === answeredBy)
      if (p) p.status = 'connected'
    }
    
    // With LiveKit, no SDP exchange is needed here.
    // The answerer connects to the same LiveKit room independently.
    // LiveKit's ParticipantConnected event handles the rest.
    isDebugEnabled() && console.log('[CallStore] Call answered by', payload.answeredBy, '- LiveKit will handle media connection')
  }
  
  function handleCallRejected(event) {
    const payload = event.payload || event
    
    // Multi-device guard
    if (!callId.value || callId.value !== payload.callId) return
    
    isDebugEnabled() && console.log(`[CallStore] Call rejected by ${payload.rejectedBy}: ${payload.reason}`)
    
    // Mark the participant who rejected
    const rejectorIndex = participants.value.findIndex(p => p.email === payload.rejectedBy)
    if (rejectorIndex !== -1) {
      participants.value[rejectorIndex].status = 'rejected'
    }
    
    // Check if all remote participants have rejected
    const allRejected = participants.value.every(p => p.status === 'rejected')
    
    if (allRejected) {
      stopRingtone()
      stopRingback()
      playHangupTone()
      
      const wasTimeout = payload.reason === 'no_answer'
      
      callStatus.value = 'ended'
      callError.value = wasTimeout ? 'No answer' : (payload.reason === 'busy' ? 'User is busy' : 'Call declined')
      
      if (callId.value) {
        saveCallRecordOnce({
          call_id: callId.value,
          conversation_id: conversationId.value,
          call_type: callType.value,
          status: wasTimeout ? 'missed' : 'declined',
          rejected_by: wasTimeout ? null : payload.rejectedBy,
          started_at: callStartTime.value ? new Date(callStartTime.value).toISOString() : null,
          ended_at: new Date().toISOString(),
          duration_seconds: 0,
          participants: participants.value.map(p => p.email)
        })
      }

      scheduleCleanup(2000)
    } else {
      const toast = useToastStore()
      const rejectorName = payload.rejectedBy?.split('@')[0] || 'Someone'
      const reason = payload.reason === 'busy' ? 'is busy' : payload.reason === 'no_answer' ? 'did not answer' : 'declined'
      toast.warning(`${rejectorName} ${reason}`)
    }
  }
  
  function handleCallHangup(event) {
    const payload = event.payload || event
    
    // If we're not in this call but observing it via the banner, handle banner updates
    if (!callId.value || callId.value !== payload.callId) {
      if (payload.hungUpBy === 'system' || payload.reason === 'all_others_left') {
        if (activeCallInConversation.value && activeCallInConversation.value.callId === payload.callId) {
          activeCallInConversation.value = null
        }
        if (payload.conversationId && conversationActiveCalls.value[payload.conversationId]) {
          const updated = { ...conversationActiveCalls.value }
          delete updated[payload.conversationId]
          conversationActiveCalls.value = updated
        }
      } else if (activeCallInConversation.value && activeCallInConversation.value.callId === payload.callId) {
        const callParticipants = activeCallInConversation.value.participants || []
        activeCallInConversation.value = {
          ...activeCallInConversation.value,
          participants: callParticipants.filter(e => e !== payload.hungUpBy)
        }
        if (activeCallInConversation.value.participants.length === 0) {
          activeCallInConversation.value = null
        }
        if (payload.conversationId && conversationActiveCalls.value[payload.conversationId]) {
          const sidebarInfo = conversationActiveCalls.value[payload.conversationId]
          const updatedParticipants = (sidebarInfo.participants || []).filter(e => e !== payload.hungUpBy)
          if (updatedParticipants.length === 0) {
            const updated = { ...conversationActiveCalls.value }
            delete updated[payload.conversationId]
            conversationActiveCalls.value = updated
          } else {
            conversationActiveCalls.value = {
              ...conversationActiveCalls.value,
              [payload.conversationId]: { ...sidebarInfo, participants: updatedParticipants }
            }
          }
        }
      }
      return
    }
    
    // Calculate local duration
    const localDuration = callAnswerTime.value
      ? Math.floor((Date.now() - callAnswerTime.value) / 1000)
      : (payload.duration || 0)
    
    // System hangup or all others left → end call
    if (payload.hungUpBy === 'system' || payload.reason === 'all_others_left') {
      stopRingtone()
      stopRingback()
      playHangupTone()
      
      if (callId.value) {
        saveCallRecordOnce({
          call_id: callId.value,
          conversation_id: conversationId.value,
          call_type: callType.value,
          status: localDuration > 0 ? 'completed' : 'cancelled',
          started_at: callStartTime.value ? new Date(callStartTime.value).toISOString() : null,
          answered_at: callAnswerTime.value ? new Date(callAnswerTime.value).toISOString() : null,
          ended_at: new Date().toISOString(),
          duration_seconds: localDuration,
          participants: participants.value.map(p => p.email),
          had_screen_share: isScreenSharing.value ? 1 : 0
        })
      }

      callStatus.value = 'ended'
      scheduleCleanup(1500)
      return
    }
    
    isDebugEnabled() && console.log(`[CallStore] Participant hung up: ${payload.hungUpBy}`)
    
    // Remove from participant list
    const idx = participants.value.findIndex(p => p.email === payload.hungUpBy)
    if (idx !== -1) {
      participants.value.splice(idx, 1)
    }
    
    // Check if any remote participants remain
    const remainingActive = participants.value.filter(p => p.status !== 'rejected' && p.status !== 'left')
    
    if (remainingActive.length === 0) {
      stopRingtone()
      stopRingback()
      playHangupTone()
      
      if (callId.value) {
        saveCallRecordOnce({
          call_id: callId.value,
          conversation_id: conversationId.value,
          call_type: callType.value,
          status: localDuration > 0 ? 'completed' : 'cancelled',
          started_at: callStartTime.value ? new Date(callStartTime.value).toISOString() : null,
          answered_at: callAnswerTime.value ? new Date(callAnswerTime.value).toISOString() : null,
          ended_at: new Date().toISOString(),
          duration_seconds: localDuration,
          participants: participants.value.map(p => p.email),
          had_screen_share: isScreenSharing.value ? 1 : 0
        })
      }

      callStatus.value = 'ended'
      scheduleCleanup(1500)
    }
  }
  
  function handleRemoteMediaState(event) {
    const payload = event.payload || event
    if (!callId.value || callId.value !== payload.callId) return
    
    const participant = participants.value.find(p => p.email === payload.participantEmail)
    if (participant) {
      participant.mediaState = {
        audio: payload.audio ?? participant.mediaState?.audio ?? true,
        video: payload.video ?? participant.mediaState?.video ?? false,
        screenShare: payload.screenShare ?? participant.mediaState?.screenShare ?? false
      }
    }
  }
  
  function handleRemoteScreenShareStart(event) {
    const payload = event.payload || event
    if (!callId.value || callId.value !== payload.callId) return
    
    isDebugEnabled() && console.log('[CallStore] Remote screen share START from', payload.participantEmail)
    
    const participant = participants.value.find(p => p.email === payload.participantEmail)
    if (participant) {
      participant.mediaState = { ...participant.mediaState, screenShare: true }
    }
    
    // LiveKit handles the actual track routing via TrackSubscribed events
    // Just sync our refs in case LiveKit already delivered the track
    syncStreamRefs()
    setTimeout(() => syncStreamRefs(), 500)
  }
  
  function handleRemoteScreenShareStop(event) {
    const payload = event.payload || event
    if (!callId.value || callId.value !== payload.callId) return

    const sharerEmail = payload.participantEmail

    isDebugEnabled() && console.log('[CallStore] Remote screen share STOP from', sharerEmail)

    const participant = participants.value.find(p => p.email === sharerEmail)
    if (participant) {
      participant.mediaState = { ...participant.mediaState, screenShare: false }
    }

    // Proactively clean stale screen share stream/track refs for this participant.
    // LiveKit's TrackUnsubscribed may arrive after a delay, keeping the UI stuck
    // in presentation mode if we only rely on syncStreamRefs.
    if (livekit && sharerEmail) {
      const screenStreams = livekit.remoteScreenStreams.value
      const screenTracks = livekit.remoteScreenTracks.value
      if (screenStreams.has(sharerEmail)) {
        const updated = new Map(screenStreams)
        updated.delete(sharerEmail)
        livekit.remoteScreenStreams.value = updated
      }
      if (screenTracks.has(sharerEmail)) {
        const updated = new Map(screenTracks)
        updated.delete(sharerEmail)
        livekit.remoteScreenTracks.value = updated
      }
      const sharers = livekit.screenSharingParticipants.value
      if (sharers.has(sharerEmail)) {
        const updated = new Set(sharers)
        updated.delete(sharerEmail)
        livekit.screenSharingParticipants.value = updated
      }
    }

    syncStreamRefs()
  }
  
  function handleCallMissed(event) {
    const payload = event.payload || event
    
    if (!callId.value || (payload.callId && callId.value !== payload.callId)) {
      if (payload.callerEmail) {
        persistMissedCallNotification(payload)
      }
      if (!payload.callerEmail && payload.callId && payload.conversationId) {
        saveCallRecordOnce({
          call_id: payload.callId,
          conversation_id: payload.conversationId,
          call_type: payload.callType || 'voice',
          status: 'missed',
          started_at: null,
          participants: payload.participants || []
        })
      }
      return
    }
    
    stopRingtone()
    stopRingback()
    playHangupTone()
    
    isDebugEnabled() && console.log('[CallStore] Call missed:', payload)
    
    if (!payload.callerEmail) {
      callError.value = 'No answer'
    }
    
    callStatus.value = 'ended'
    
    if (!payload.callerEmail) {
      saveCallRecordOnce({
        call_id: payload.callId || callId.value,
        conversation_id: payload.conversationId || conversationId.value,
        call_type: payload.callType || callType.value,
        status: 'missed',
        started_at: callStartTime.value ? new Date(callStartTime.value).toISOString() : null,
        participants: (payload.participants || participants.value.map(p => p.email))
      })
    }
    
    if (payload.callerEmail) {
      persistMissedCallNotification(payload)
    }
    
    scheduleCleanup(2000)
  }
  
  function handleCallDismissed(event) {
    const payload = event.payload || event
    
    if (!callId.value || callId.value !== payload.callId) return
    
    // If this device is already active in this call flow, ignore dismiss
    if (callStatus.value === 'active') {
      isDebugEnabled() && console.log('[CallStore] Ignoring CALL_DISMISSED - this device is the active call device')
      return
    }
    
    if (isLocallyAnswering) {
      isDebugEnabled() && console.log('[CallStore] Ignoring CALL_DISMISSED - currently answering on this device')
      return
    }
    
    isDebugEnabled() && console.log('[CallStore] Call dismissed on another device:', payload.reason)
    
    stopRingtone()
    stopRingback()
    
    if (payload.reason !== 'answered_elsewhere') {
      playHangupTone()
    }

    callStatus.value = 'ended'
    scheduleCleanup(500)
  }
  
  /**
   * Handle CALL_PARTICIPANT_JOINED - update participant list
   * With LiveKit, the actual media connection is handled by the SFU.
   * We only need to update the participant list for the UI.
   */
  function handleParticipantJoined(event) {
    const payload = event.payload || event
    if (!callId.value || callId.value !== payload.callId) return
    
    if (payload.participantEmail) {
      const newEmail = normalizeEmail(payload.participantEmail)
      isDebugEnabled() && console.log(`[CallStore] Participant joined: ${newEmail}`)
      
      const existingIdx = participants.value.findIndex(p => p.email === newEmail)
      if (existingIdx === -1) {
        participants.value.push(_resolveParticipant(newEmail, {
          name: payload.participantName || undefined,
          status: 'connected',
          mediaState: { audio: true, video: callType.value === 'video', screenShare: false }
        }))
      } else {
        participants.value[existingIdx].status = 'connected'
        if (payload.participantName) {
          participants.value[existingIdx].name = payload.participantName
        }
      }
      
      // LiveKit handles the actual peer connection automatically
    } else if (payload.connectedParticipants && payload.connectedParticipants.length > 0) {
      isDebugEnabled() && console.log(`[CallStore] Existing participants in call:`, payload.connectedParticipants)
      
      for (const peer of payload.connectedParticipants) {
        const peerEmail = normalizeEmail(peer)
        const existingIdx = participants.value.findIndex(p => p.email === peerEmail)
        if (existingIdx === -1) {
          participants.value.push(_resolveParticipant(peerEmail, {
            status: 'connected',
            mediaState: { audio: true, video: callType.value === 'video', screenShare: false }
          }))
        }
      }
    }
  }
  
  function handleActiveCallStatus(event) {
    const payload = event.payload || event
    const convId = payload.conversationId
    
    if (payload.active) {
      const callInfo = {
        callId: payload.callId,
        callType: payload.callType,
        participants: payload.participants || [],
        initiatedBy: payload.initiatedBy,
        startedAt: payload.startedAt,
        answeredAt: payload.answeredAt,
        conversationId: convId
      }
      activeCallInConversation.value = callInfo
      if (convId) {
        conversationActiveCalls.value = { ...conversationActiveCalls.value, [convId]: callInfo }
      }
    } else {
      activeCallInConversation.value = null
      if (convId && conversationActiveCalls.value[convId]) {
        const updated = { ...conversationActiveCalls.value }
        delete updated[convId]
        conversationActiveCalls.value = updated
      }
    }
  }
  
  function queryActiveCall(convId) {
    if (convId !== 'all') {
      activeCallInConversation.value = null
    }
    sendSignal('CALL_ACTIVE_QUERY', { conversationId: convId })
  }
  
  function queryAllActiveCalls() {
    sendSignal('CALL_ACTIVE_QUERY', { conversationId: 'all' })
  }
  
  // ============================================
  // LIVEKIT ROOM CONNECTION
  // ============================================
  
  /**
   * Connect to a LiveKit room for the current call.
   * Fetches a token from the backend and establishes the connection.
   * @param {boolean} hasLocalMedia - Whether we have local media to publish
   * @param {Object}  [options]
   * @param {boolean} [options.joinMuted]  - Start with mic muted
   * @param {boolean} [options.joinCamOff] - Start with camera off
   */
  async function connectToLiveKitRoom(hasLocalMedia, options = {}) {
    if (!livekit || !callId.value) return

    const prefs = useDevicePreferences()
    const audioDeviceId = prefs.resolveAudioInputId()
    const videoDeviceId = prefs.resolveVideoInputId()
    const audioOutputDeviceId = prefs.resolveAudioOutputId()
    const joinMuted = !!options.joinMuted
    const joinCamOff = !!options.joinCamOff
    
    const auth = useAuthStore()
    const displayName = auth.userEmail?.split('@')[0] || ''
    
    isDebugEnabled() && console.log(`[CallStore] Fetching LiveKit token for room: ${callId.value}`)
    
    const { token, ws_url } = await livekit.fetchToken(callId.value, displayName)
    
    livekit.setCallbacks({
      onRemoteTrackCb: (email, lkTrack, stream) => {
        const normalizedEmail = normalizeEmail(email)
        isDebugEnabled() && console.log(`[CallStore] LiveKit remote track from ${normalizedEmail}: kind=${lkTrack.kind}, source=${lkTrack.source}`)
        syncStreamRefs()
        // LiveKit track source is the source of truth — no dependency on
        // reactive screenSharingParticipants or WS signaling events.
        if (lkTrack.source === Track.Source.ScreenShare) {
          console.log(`[CallStore] Remote screen VIDEO track received from ${normalizedEmail}`)
          const p = participants.value.find(p => p.email === normalizedEmail)
          if (p && !p.mediaState?.screenShare) {
            p.mediaState = { ...p.mediaState, screenShare: true }
          }
          if (_screenShareHandler) {
            _screenShareHandler(lkTrack, normalizedEmail)
          }
        }
      },
      onConnectionStateChangeCb: (email, state) => {
        isDebugEnabled() && console.log(`[CallStore] LiveKit connection state: ${state}`)
        syncStreamRefs()
        if (state === 'failed' || state === 'closed') {
          if (callStatus.value === 'active') {
            callError.value = 'Connection lost'
          }
        } else if (state === 'connected') {
          // Clear transient error on successful reconnection
          if (callError.value === 'Connection lost') {
            callError.value = null
          }
          syncStreamRefs()
        }
      },
      onParticipantConnectedCb: (email) => {
        const normalizedEmail = normalizeEmail(email)
        isDebugEnabled() && console.log(`[CallStore] LiveKit participant connected: ${normalizedEmail}`)
        // A remote peer actually joined the media room — ground truth that the
        // call connected. Promote the caller out of ringing even if the WS
        // CALL_ANSWER never arrived (e.g. the callee answered from the lock
        // screen with its socket suspended). The callee is already 'active'
        // from answerCall, so this only fires for the waiting caller.
        if (callStatus.value === 'ringing' || callStatus.value === 'initiating') {
          stopRingback()
          stopRingtone()
          clearScheduledCleanup()
          callStatus.value = 'active'
          if (!callAnswerTime.value) callAnswerTime.value = Date.now()
          nativeLog('[CallStore] remote joined LiveKit -> call active callId=' + (callId.value || ''))
        }
        // Update participant status
        const p = participants.value.find(p => p.email === normalizedEmail)
        if (p) {
          p.status = 'connected'
        } else {
          // New participant we didn't know about
          participants.value.push(_resolveParticipant(normalizedEmail, {
            status: 'connected',
            mediaState: { audio: true, video: callType.value === 'video', screenShare: false }
          }))
        }
        refreshParticipantProfiles()
        syncStreamRefs()
      },
      onParticipantDisconnectedCb: (email) => {
        isDebugEnabled() && console.log(`[CallStore] LiveKit participant disconnected: ${normalizeEmail(email)}`)
        syncStreamRefs()
      },
      onSpeakingChangedCb: (speakingSet) => {
        speakingParticipants.value = new Set(speakingSet)
      }
    })
    
    await livekit.connect(ws_url, token)

    // Let LiveKit capture AND publish tracks internally (same as CRM Pro VideoCallRoom).
    // This produces higher quality than manually publishing pre-captured tracks because
    // LiveKit controls the full encoder pipeline with proper bitrate/simulcast setup.
    if (hasLocalMedia) {
      // Stop pre-captured tracks — LiveKit will do its own getUserMedia
      if (media?.localStream?.value) {
        media.localStream.value.getTracks().forEach(t => t.stop())
      }

      try {
        if (joinMuted) {
          // Honor "join muted" — leave the mic off, do not publish a track.
          isAudioMuted.value = true
        } else {
          const micTrack = await livekit.setMicrophoneEnabled(true, audioDeviceId ? { deviceId: audioDeviceId } : {})
          if (micTrack && media?.localStream?.value) {
            media.localStream.value.getAudioTracks().forEach(t => media.localStream.value.removeTrack(t))
            media.localStream.value.addTrack(micTrack)
          }
        }
      } catch (e) {
        console.warn('[CallStore] Mic enable failed:', e)
        isAudioMuted.value = true
      }

      if (callType.value === 'video' && !joinCamOff) {
        try {
          const cameraTrack = await livekit.setCameraEnabled(true, videoDeviceId ? { deviceId: videoDeviceId } : {})
          if (cameraTrack && media?.localStream?.value) {
            media.localStream.value.getVideoTracks().forEach(t => media.localStream.value.removeTrack(t))
            media.localStream.value.addTrack(cameraTrack)
          }
        } catch (e) {
          console.warn('[CallStore] Camera enable failed:', e)
          isVideoOn.value = false
        }
      } else if (joinCamOff) {
        isVideoOn.value = false
      }

      // Apply preferred audio output (speaker) device, if browser supports it
      if (audioOutputDeviceId) {
        try { await livekit.switchAudioOutput(audioOutputDeviceId) } catch (_) { /* ignore */ }
      }

      syncStreamRefs()
      isDebugEnabled() && console.log('[CallStore] Published local tracks via LiveKit setCameraEnabled/setMicrophoneEnabled')
    }
  }
  
  // ============================================
  // CALL ACTIONS
  // ============================================
  
  /**
   * Initiate an outgoing call
   * @param {string} convId
   * @param {string} type - 'voice' | 'video'
   * @param {Array}  participantInputs
   * @param {Object} [options]
   * @param {string} [options.audioInputId] - Preferred mic device (also persisted via prefs)
   * @param {string} [options.videoInputId] - Preferred camera device
   * @param {string} [options.audioOutputId] - Preferred speaker device
   * @param {boolean} [options.joinMuted]   - Start with mic muted
   * @param {boolean} [options.joinCamOff]  - Start with camera off
   */
  async function initiateCall(convId, type, participantInputs, options = {}) {
    if (isInCall.value) {
      console.warn('[CallStore] Already in a call')
      return
    }
    
    // Check WebSocket connection (isConnected works for both browser WS and Electron IPC)
    const socket = useMailSyncSocket()
    if (!socket.isConnected()) {
      const toast = useToastStore()
      toast.error('Not connected to server. Please wait for reconnection and try again.')
      console.error('[CallStore] Cannot initiate call — WebSocket not connected')
      return
    }
    
    const normalizedParticipants = (participantInputs || [])
      .map((item) => {
        if (typeof item === 'string') {
          return { email: normalizeEmail(item) }
        }
        return {
          email: normalizeEmail(item?.email),
          name: item?.display_name || item?.name || null,
          avatar: item?.avatar || item?.avatar_url || null
        }
      })
      .filter(p => !!p.email)

    const participantEmails = normalizedParticipants.map(p => p.email)

    beginCallSession()
    callError.value = null
    callType.value = type
    callDirection.value = 'outgoing'
    callStatus.value = 'initiating'
    conversationId.value = convId
    callStartTime.value = Date.now()
    
    participants.value = normalizedParticipants.map(p => _resolveParticipant(p.email, {
      name: p.name || undefined,
      avatar: p.avatar || undefined,
      status: 'pending',
      mediaState: { audio: true, video: type === 'video', screenShare: false }
    }))
    
    livekit = useLiveKit()
    media = useMediaDevices()

    // Apply explicit device selections (if any) so the pre-capture in
    // getUserMedia uses the user-chosen mic/camera. Falls back to prefs
    // automatically when these are null.
    if (options.audioInputId) media.selectedAudioDevice.value = options.audioInputId
    if (options.videoInputId) media.selectedVideoDevice.value = options.videoInputId

    // Stash join-state for handleCallRinging -> connectToLiveKitRoom
    _pendingJoinOptions = {
      joinMuted: !!options.joinMuted,
      joinCamOff: !!options.joinCamOff
    }

    try {
      let actualType = type
      let hasLocalMedia = false
      
      try {
        await media.getUserMedia({
          audio: true,
          video: type === 'video' && !options.joinCamOff
        })
        hasLocalMedia = true
      } catch (mediaErr) {
        if (type === 'video' && (mediaErr.name === 'NotFoundError' || mediaErr.name === 'NotReadableError')) {
          try {
            await media.getUserMedia({ audio: true, video: false })
            actualType = 'voice'
            hasLocalMedia = true
            const toast = useToastStore()
            toast.warning('No camera found. Starting voice call instead.')
          } catch (audioErr) {
            console.warn('[CallStore] No audio device available, joining in listen-only mode')
            actualType = 'voice'
            isAudioMuted.value = true
            const toast = useToastStore()
            toast.warning('No microphone found. You can listen but others won\'t hear you.')
          }
        } else if (mediaErr.name === 'NotFoundError' || mediaErr.name === 'NotReadableError') {
          console.warn('[CallStore] No audio device available, joining in listen-only mode')
          isAudioMuted.value = true
          const toast = useToastStore()
          toast.warning('No microphone found. You can listen but others won\'t hear you.')
        } else if (mediaErr.name === 'NotAllowedError') {
          console.warn('[CallStore] Media permission denied, joining in listen-only mode')
          isAudioMuted.value = true
          const toast = useToastStore()
          toast.warning('Microphone access denied. You can listen but others won\'t hear you.')
        } else {
          throw mediaErr
        }
      }

      callType.value = actualType
      isVideoOn.value = actualType === 'video' && hasLocalMedia && !options.joinCamOff
      if (options.joinMuted) isAudioMuted.value = true

      // Sync local stream ref for UI preview
      syncStreamRefs()
      
      // Send CALL_INITIATE signal (server broadcasts to all participants)
      // No SDP needed — LiveKit handles media routing
      sendSignal('CALL_INITIATE', {
        conversationId: convId,
        callType: actualType,
        participants: participantEmails
      })
      
      // Connect to LiveKit room (uses callId as room name)
      // The callId is set by handleCallRinging, so we wait for it
      // Actually, the server assigns callId in CALL_RINGING response.
      // We'll connect after receiving CALL_RINGING.
      // For now, connect if we already have a callId (shouldn't happen yet)
      
      // Track in sidebar
      conversationActiveCalls.value = {
        ...conversationActiveCalls.value,
        [convId]: {
          callId: callId.value,
          callType: actualType,
          participants: participantEmails,
          initiatedBy: useAuthStore().userEmail,
          conversationId: convId
        }
      }
      
    } catch (e) {
      console.error('[CallStore] Failed to initiate call:', e)
      const errorMsg = media?.permissionError?.value || e.message
      callError.value = errorMsg
      callStatus.value = 'idle'
      
      const toast = useToastStore()
      toast.error(`Call failed: ${errorMsg}`)
      
      cleanupCall()
    }
  }
  
  /**
   * Answer an incoming call
   * @param {Object} [options]
   * @param {string} [options.audioInputId]
   * @param {string} [options.videoInputId]
   * @param {string} [options.audioOutputId]
   * @param {boolean} [options.joinMuted]
   * @param {boolean} [options.joinCamOff]
   */
  async function answerCall(options = {}) {
    if (callStatus.value !== 'ringing') return

    nativeLog('[CallStore] answerCall start callId=' + (callId.value || '') + ' type=' + (callType.value || ''))

    isLocallyAnswering = true
    
    stopRingtone()
    clearRingTimeout()
    callStatus.value = 'active'
    callAnswerTime.value = Date.now()
    
    livekit = useLiveKit()
    media = useMediaDevices()

    if (options.audioInputId) media.selectedAudioDevice.value = options.audioInputId
    if (options.videoInputId) media.selectedVideoDevice.value = options.videoInputId

    try {
      let hasLocalMedia = false
      
      try {
        await media.getUserMedia({
          audio: true,
          video: callType.value === 'video' && !options.joinCamOff
        })
        isVideoOn.value = callType.value === 'video' && !options.joinCamOff
        if (options.joinMuted) isAudioMuted.value = true
        hasLocalMedia = true
        nativeLog('[CallStore] answerCall getUserMedia OK hasLocalMedia=true muted=' + isAudioMuted.value)
      } catch (mediaErr) {
        nativeLog('[CallStore] answerCall getUserMedia FAILED name=' + (mediaErr?.name || '') + ' msg=' + (mediaErr?.message || ''))
        if (callType.value === 'video' && (mediaErr.name === 'NotFoundError' || mediaErr.name === 'NotReadableError')) {
          try {
            await media.getUserMedia({ audio: true, video: false })
            isVideoOn.value = false
            hasLocalMedia = true
            const toast = useToastStore()
            toast.warning('No camera found. Joining with audio only.')
          } catch (audioErr) {
            console.warn('[CallStore] No audio device available, answering in listen-only mode')
            isVideoOn.value = false
            isAudioMuted.value = true
            const toast = useToastStore()
            toast.warning('No microphone found. You can listen but others won\'t hear you.')
          }
        } else if (mediaErr.name === 'NotFoundError' || mediaErr.name === 'NotReadableError') {
          console.warn('[CallStore] No audio device available, answering in listen-only mode')
          isAudioMuted.value = true
          const toast = useToastStore()
          toast.warning('No microphone found. You can listen but others won\'t hear you.')
        } else if (mediaErr.name === 'NotAllowedError') {
          console.warn('[CallStore] Media permission denied, answering in listen-only mode')
          isAudioMuted.value = true
          const toast = useToastStore()
          toast.warning('Microphone access denied. You can listen but others won\'t hear you.')
        } else {
          throw mediaErr
        }
      }
      
      // Sync local stream ref for UI
      syncStreamRefs()
      
      // Connect to LiveKit room (callId is already set from CALL_INITIATE)
      await connectToLiveKitRoom(hasLocalMedia, {
        joinMuted: !!options.joinMuted,
        joinCamOff: !!options.joinCamOff
      })
      nativeLog('[CallStore] answerCall LiveKit connected callId=' + (callId.value || ''))
      
      // Send CALL_ANSWER to notify the caller (no SDP needed)
      const answerSent = sendSignal('CALL_ANSWER', {
        callId: callId.value
      })
      nativeLog('[CallStore] answerCall CALL_ANSWER sendResult=' + answerSent + ' callId=' + (callId.value || ''))
      
      isLocallyAnswering = false
      
    } catch (e) {
      isLocallyAnswering = false
      nativeLog('[CallStore] answerCall ERROR: ' + (e?.message || e))
      console.error('[CallStore] Failed to answer call:', e)
      callError.value = media?.permissionError?.value || e.message
      
      const toast = useToastStore()
      toast.error(`Failed to join call: ${e.message}`)
      
      rejectCall(callId.value, 'error')
    }
  }
  
  /**
   * Join an ongoing call in a conversation
   * @param {string} convId
   * @param {Object} activeCall
   * @param {Object} [options]
   */
  async function joinCall(convId, activeCall, options = {}) {
    if (isInCall.value) {
      const toast = useToastStore()
      toast.warning('You are already in a call')
      return
    }
    
    const auth = useAuthStore()
    const myEmail = auth.userEmail?.toLowerCase()
    const participantEmails = (activeCall.participants || []).filter(
      e => e.toLowerCase() !== myEmail
    )
    
    if (!participantEmails.length) {
      const toast = useToastStore()
      toast.error('No participants to connect to')
      return
    }
    
    beginCallSession()
    callError.value = null
    callType.value = activeCall.callType
    callDirection.value = 'outgoing'
    callStatus.value = 'initiating'
    conversationId.value = convId
    callStartTime.value = Date.now()
    callId.value = activeCall.callId
    
    participants.value = participantEmails.map(email => _resolveParticipant(email, {
      mediaState: { audio: true, video: activeCall.callType === 'video', screenShare: false }
    }))
    
    livekit = useLiveKit()
    media = useMediaDevices()

    if (options.audioInputId) media.selectedAudioDevice.value = options.audioInputId
    if (options.videoInputId) media.selectedVideoDevice.value = options.videoInputId

    try {
      let hasLocalMedia = false
      try {
        await media.getUserMedia({
          audio: true,
          video: activeCall.callType === 'video' && !options.joinCamOff
        })
        isVideoOn.value = activeCall.callType === 'video' && !options.joinCamOff
        if (options.joinMuted) isAudioMuted.value = true
        hasLocalMedia = true
      } catch (mediaErr) {
        if (mediaErr.name === 'NotFoundError' || mediaErr.name === 'NotReadableError' || mediaErr.name === 'NotAllowedError') {
          isAudioMuted.value = true
          isVideoOn.value = false
          const toast = useToastStore()
          toast.warning('No microphone found. You can listen but others won\'t hear you.')
        } else {
          throw mediaErr
        }
      }
      
      syncStreamRefs()
      
      // Connect to LiveKit room
      await connectToLiveKitRoom(hasLocalMedia, {
        joinMuted: !!options.joinMuted,
        joinCamOff: !!options.joinCamOff
      })
      
      // Send CALL_ANSWER to notify others
      sendSignal('CALL_ANSWER', {
        callId: activeCall.callId
      })
      
      callStatus.value = 'active'
      callAnswerTime.value = Date.now()
      
    } catch (e) {
      console.error('[CallStore] Failed to join call:', e)
      callError.value = e.message
      const toast = useToastStore()
      toast.error(`Failed to join call: ${e.message}`)
      cleanupCall()
    }
  }
  
  /**
   * Reject an incoming call
   */
  function rejectCall(id = null, reason = 'declined') {
    stopRingtone()
    playHangupTone()
    
    sendSignal('CALL_REJECT', {
      callId: id || callId.value,
      reason
    })
    
    callStatus.value = 'ended'
    scheduleCleanup(1000)
  }

  // ============================================
  // AUDIO PLAYBACK UNBLOCK (iOS autoplay policy)
  // ============================================

  /** A remote <audio> element was blocked from autoplaying (iOS, no gesture). */
  function notifyAudioBlocked() {
    if (!audioPlaybackBlocked.value) {
      audioPlaybackBlocked.value = true
      nativeLog('[CallStore] remote audio autoplay BLOCKED — needs user gesture to start sound')
    }
  }

  /**
   * Resume remote audio playback. MUST be called from within a user gesture
   * (e.g. a tap handler) for iOS to honor it. Re-plays our managed remote
   * <audio> elements (clearing their auto-block flag) and resumes LiveKit's
   * shared AudioContext. Idempotent.
   */
  async function enableAudioPlayback() {
    try { await livekit?.startAudio?.() } catch (_e) { /* not blocked */ }
    if (typeof document !== 'undefined') {
      const els = document.querySelectorAll('audio[data-call-audio]')
      for (const el of els) {
        try { delete el.dataset.autoblocked } catch (_e) { /* readonly */ }
        try { await el.play() } catch (_e) { /* still blocked / no src */ }
      }
    }
    audioPlaybackBlocked.value = false
    nativeLog('[CallStore] enableAudioPlayback() ran — resumed ' +
      (typeof document !== 'undefined' ? document.querySelectorAll('audio[data-call-audio]').length : 0) +
      ' audio element(s)')
  }

  // ============================================
  // NATIVE SYSTEM CALL UI BRIDGE (CallKit / Android FSI)
  // Driven by services/callKit.js from the native CallNative plugin events.
  // ============================================

  /** The system UI is presenting this ring; suppress the in-app modal. */
  function markNativeRing(id) {
    if (id) nativeRingCallId.value = id
  }

  /** The system ring for this call is gone; let the in-app modal resume. */
  function clearNativeRing(id = null) {
    if (!id || nativeRingCallId.value === id) nativeRingCallId.value = null
  }

  /**
   * Build the incoming-call session straight from a native VoIP push payload,
   * mirroring the state handleIncomingCall sets from a WS CALL_INITIATE. Used
   * on a CallKit answer when CALL_INITIATE never landed (the app was
   * backgrounded by the system call UI, suspending its socket).
   */
  function bootstrapIncomingFromPush(info) {
    beginCallSession()
    callId.value = info.callId
    callType.value = info.callType || 'voice'
    callStatus.value = 'ringing'
    callDirection.value = 'incoming'
    conversationId.value = info.conversationId || null

    const auth = useAuthStore()
    const myEmail = auth.userEmail?.toLowerCase()
    const callerEmail = String(info.callerEmail || '').trim().toLowerCase()
    callerInfo.value = {
      email: callerEmail,
      name: info.callerName || (callerEmail ? callerEmail.split('@')[0] : 'Unknown')
    }
    participants.value = (callerEmail && callerEmail !== myEmail)
      ? [_resolveParticipant(callerEmail, {
          name: info.callerName || callerEmail.split('@')[0],
          mediaState: { audio: true, video: info.callType === 'video', screenShare: false }
        })]
      : []
  }

  /**
   * User accepted the call from the system UI (CallKit / FSI).
   *   1. If the WS CALL_INITIATE already set up the ringing state, answer now.
   *   2. Otherwise, if the native layer forwarded the push call info, bootstrap
   *      the session from it and answer — do NOT wait for a CALL_INITIATE
   *      replay, which never arrives once CallKit backgrounds the app.
   *   3. Only as a last resort (no info) queue for handleIncomingCall.
   */
  function acceptFromNative(id = null, callInfo = null, options = {}) {
    clearNativeRing(id)
    if (callStatus.value === 'ringing' && callDirection.value === 'incoming' && (!id || id === callId.value)) {
      nativeLog('[CallStore] acceptFromNative id=' + (id || '') + ' status=ringing -> answer now')
      return answerCall(options)
    }
    const info = callInfo && (callInfo.callId || id)
      ? { ...callInfo, callId: callInfo.callId || id }
      : null
    if (callStatus.value === 'idle' && info) {
      nativeLog('[CallStore] acceptFromNative id=' + (info.callId || '') +
        ' status=idle -> bootstrap from push + answer')
      bootstrapIncomingFromPush(info)
      return answerCall(options)
    }
    nativeLog('[CallStore] acceptFromNative id=' + (id || '') + ' status=' + callStatus.value +
      ' dir=' + (callDirection.value || '') + ' -> queued (await CALL_INITIATE replay)')
    _pendingNativeAction = { callId: id, action: 'accept', options }
  }

  /**
   * User declined the call from the system UI. Reject now if we're ringing,
   * otherwise tell the server directly and queue so a late CALL_INITIATE for
   * this call is auto-rejected rather than ringing in-app.
   */
  function rejectFromNative(id = null, reason = 'declined') {
    clearNativeRing(id)
    if (callStatus.value === 'ringing' && callDirection.value === 'incoming' && (!id || id === callId.value)) {
      return rejectCall(id, reason)
    }
    sendSignal('CALL_REJECT', { callId: id, reason })
    _pendingNativeAction = { callId: id, action: 'reject', reason }
  }
  
  /**
   * Hang up the current call
   */
  function hangUp() {
    stopRingtone()
    stopRingback()
    playHangupTone()
    
    const duration = callAnswerTime.value
      ? Math.floor((Date.now() - callAnswerTime.value) / 1000)
      : 0
    
    // Clear sidebar indicator if we're the last active participant
    const remainingActive = participants.value.filter(p => p.status === 'connected' || p.status === 'pending')
    if (remainingActive.length <= 1) {
      if (conversationId.value && conversationActiveCalls.value[conversationId.value]) {
        const updated = { ...conversationActiveCalls.value }
        delete updated[conversationId.value]
        conversationActiveCalls.value = updated
      }
    }
    
    sendSignal('CALL_HANGUP', {
      callId: callId.value
    })
    
    // Save call history
    if (callId.value) {
      const wasAnswered = callAnswerTime.value !== null
      saveCallRecordOnce({
        call_id: callId.value,
        conversation_id: conversationId.value,
        call_type: callType.value,
        status: wasAnswered ? 'completed' : 'missed',
        started_at: callStartTime.value ? new Date(callStartTime.value).toISOString() : null,
        answered_at: callAnswerTime.value ? new Date(callAnswerTime.value).toISOString() : null,
        ended_at: new Date().toISOString(),
        duration_seconds: duration,
        participants: participants.value.map(p => p.email),
        had_screen_share: isScreenSharing.value ? 1 : 0
      })
    }

    callStatus.value = 'ended'
    scheduleCleanup(1000)
  }
  
  // ============================================
  // MEDIA CONTROLS
  // ============================================
  
  /**
   * Toggle microphone
   * Uses LiveKit as the single authority for audio muting.
   * No dual management — LiveKit handles mute/unmute internally.
   */
  async function toggleMute() {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      if (!callId.value || !livekit || !livekit.room.value) return
      
      const newMuted = !isAudioMuted.value
      
      try {
        await livekit.setMicrophoneEnabled(!newMuted)
        if (opEpoch !== _callEpoch || !callId.value || !livekit || !livekit.room.value) return // bail if call ended mid-operation
        isAudioMuted.value = newMuted
        isDebugEnabled() && console.log(`[CallStore] Mic ${newMuted ? 'MUTED' : 'UNMUTED'} via LiveKit`)
      } catch (e) {
        console.error('[CallStore] toggleMute failed:', e)
      }
      
      if (opEpoch !== _callEpoch || !callId.value) return
      broadcastMediaState()
    })
  }

  /**
   * Apply a mute state requested from the native CallKit mute toggle (the user
   * tapped mute on the lock-screen call UI). Mirrors toggleMute but takes an
   * explicit target and does NOT call back into the native layer, so there's no
   * native<->store mute feedback loop. No-op unless a LiveKit call is live —
   * before the foreground hand-off the native engine owns the mic, so this only
   * keeps the in-app UI/state in sync once the WebView has taken over.
   */
  async function setMutedFromNative(muted) {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      const target = !!muted
      if (!callId.value || !livekit || !livekit.room.value) return
      if (isAudioMuted.value === target) return
      try {
        await livekit.setMicrophoneEnabled(!target)
        if (opEpoch !== _callEpoch || !callId.value || !livekit || !livekit.room.value) return
        isAudioMuted.value = target
        nativeLog('[CallStore] mic ' + (target ? 'MUTED' : 'UNMUTED') + ' from native CallKit')
      } catch (e) {
        console.error('[CallStore] setMutedFromNative failed:', e)
      }
      if (opEpoch !== _callEpoch || !callId.value) return
      broadcastMediaState()
    })
  }
  
  /**
   * Toggle camera
   * Uses ONLY LiveKit's camera management to avoid ghost track publications.
   * LiveKit internally handles getUserMedia, publishing, unpublishing, and stopping.
   * We never call media.toggleVideo() during an active call — that creates competing
   * track lifecycles that cause frozen frames after 2-3 toggles.
   */
  async function toggleVideo() {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      if (!callId.value || !livekit || !livekit.room.value) return
      
      if (isScreenSharing.value) {
        const toast = useToastStore()
        toast.info('Camera is paused during screen share')
        return
      }
      
      const newState = !isVideoOn.value
      
      try {
        // Let LiveKit handle EVERYTHING — getUserMedia, publish, unpublish, stop.
        // setCameraEnabled returns the new MediaStreamTrack when enabling.
        const cameraTrack = await livekit.setCameraEnabled(newState)
        if (opEpoch !== _callEpoch || !callId.value || !livekit || !livekit.room.value) return // bail if call ended mid-operation
        isVideoOn.value = newState
        
        // Update local preview stream so ParticipantTile can render our camera
        if (media && media.localStream.value) {
          // Remove old video tracks from preview (don't .stop() — LiveKit owns them)
          const oldVideoTracks = media.localStream.value.getVideoTracks()
          oldVideoTracks.forEach(t => media.localStream.value.removeTrack(t))
          
          // Add LiveKit-managed camera track for local preview
          if (newState && cameraTrack) {
            media.localStream.value.addTrack(cameraTrack)
          }
        }
        
        isDebugEnabled() && console.log(`[CallStore] Camera ${newState ? 'ON' : 'OFF'} via LiveKit`)
      } catch (e) {
        console.error('[CallStore] toggleVideo failed:', e)
        if (newState) {
          isVideoOn.value = false
          const toast = useToastStore()
          toast.error('Failed to enable camera')
        }
      }
      
      if (opEpoch !== _callEpoch || !callId.value) return
      syncStreamRefs()
      broadcastMediaState()
    })
  }
  
  /**
   * Get the local camera publication from LiveKit.
   */
  function _getCameraPublication() {
    if (!livekit?.room?.value) return null
    for (const pub of livekit.room.value.localParticipant.trackPublications.values()) {
      if (pub.source === Track.Source.Camera && pub.track && pub.track.kind === 'video') {
        return pub
      }
    }
    return null
  }

  /**
   * Ensure the persistent background processor is initialized on the camera track.
   * Starts in 'disabled' mode so switchTo() can toggle without visual artifacts.
   */
  async function _ensureBgProcessor() {
    if (_bgProcessor && _bgProcessorReady) return _bgProcessor

    const cameraPub = _getCameraPublication()
    if (!cameraPub?.track) return null

    const { BackgroundProcessor } = await import('@livekit/track-processors')
    _bgProcessor = BackgroundProcessor({ mode: 'disabled' })
    await cameraPub.track.setProcessor(_bgProcessor)
    _bgProcessorReady = true
    isDebugEnabled() && console.log('[CallStore] Background processor initialized (disabled mode)')
    return _bgProcessor
  }

  /**
   * Set the active video effect mode.
   * @param {'none'|'blur'|'virtual-bg'} mode
   * @param {Object} [options] - { blurRadius?: number, imageUrl?: string }
   */
  async function setVideoEffect(mode, options = {}) {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      if (!callId.value || !livekit || !livekit.room.value) return
      if (!isVideoOn.value) {
        const toast = useToastStore()
        toast.info('Turn on your camera first to use effects')
        return
      }

      try {
        const processor = await _ensureBgProcessor()
        if (!processor) {
          const toast = useToastStore()
          toast.error('Video effects are not available in this browser')
          return
        }
        if (opEpoch !== _callEpoch || !callId.value) return

        if (mode === 'blur') {
          const radius = options.blurRadius ?? blurRadius.value
          blurRadius.value = radius
          await processor.switchTo({ mode: 'background-blur', blurRadius: radius })
          videoEffectMode.value = 'blur'
          isDebugEnabled() && console.log(`[CallStore] Effect -> blur (radius: ${radius})`)
        } else if (mode === 'virtual-bg') {
          const imageUrl = options.imageUrl ?? virtualBgUrl.value
          if (!imageUrl) return
          virtualBgUrl.value = imageUrl
          await processor.switchTo({ mode: 'virtual-background', imagePath: imageUrl })
          videoEffectMode.value = 'virtual-bg'
          isDebugEnabled() && console.log(`[CallStore] Effect -> virtual-bg`)
        } else {
          await processor.switchTo({ mode: 'disabled' })
          videoEffectMode.value = 'none'
          isDebugEnabled() && console.log('[CallStore] Effect -> none')
        }
      } catch (e) {
        console.error('[CallStore] setVideoEffect failed:', e)
        const toast = useToastStore()
        toast.error('Failed to apply video effect')
      }
    })
  }

  /**
   * Update blur radius while blur is active.
   */
  async function setBlurRadius(radius) {
    blurRadius.value = radius
    if (videoEffectMode.value === 'blur') {
      await setVideoEffect('blur', { blurRadius: radius })
    }
  }

  /**
   * Toggle screen sharing
   * With LiveKit, screen sharing is handled entirely by the SFU.
   */
  async function toggleScreenShare() {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      if (!callId.value || !livekit || !livekit.room.value) return

      async function stopScreenShareInternal() {
        await livekit.setScreenShareEnabled(false)
        if (opEpoch !== _callEpoch || !callId.value) return

        _screenStreamRef.value = null
        isScreenSharing.value = false
        screenShareSurface.value = null
        if (_wasVideoOnBeforeScreenShare) {
          const cameraTrack = await livekit.setCameraEnabled(true)
          isVideoOn.value = true

          // Keep local preview in sync with LiveKit-managed camera track.
          if (media && media.localStream.value) {
            const oldVideoTracks = media.localStream.value.getVideoTracks()
            oldVideoTracks.forEach(t => media.localStream.value.removeTrack(t))
            if (cameraTrack) {
              media.localStream.value.addTrack(cameraTrack)
            }
          }
        }

        sendSignal('CALL_SCREEN_SHARE_STOP', { callId: callId.value })
        broadcastMediaState()
        syncStreamRefs()
      }

      if (isScreenSharing.value) {
        await stopScreenShareInternal()
      } else {
        try {
          _wasVideoOnBeforeScreenShare = isVideoOn.value
          const screenTrack = await livekit.setScreenShareEnabled(true)
          if (opEpoch !== _callEpoch || !callId.value || !livekit || !livekit.room.value) return

          if (screenTrack) {
            isDebugEnabled() && console.log(`[CallStore] Screen share track obtained: id=${screenTrack.id?.slice(0,8)}, readyState=${screenTrack.readyState}, enabled=${screenTrack.enabled}, label=${screenTrack.label}`)

            // Detect what kind of surface is being shared (monitor, window, browser tab)
            try {
              const settings = screenTrack.getSettings?.()
              screenShareSurface.value = settings?.displaySurface || null
              isDebugEnabled() && console.log(`[CallStore] Screen share surface: ${screenShareSurface.value}, label: ${screenTrack.label}`)
            } catch (_) {
              screenShareSurface.value = null
            }

            const screenMediaStream = markRaw(new MediaStream([screenTrack]))
            _screenStreamRef.value = screenMediaStream
            isScreenSharing.value = true
            isVideoOn.value = false

            // Remove camera track from local preview while sharing.
            if (media && media.localStream.value) {
              const oldVideoTracks = media.localStream.value.getVideoTracks()
              oldVideoTracks.forEach(t => media.localStream.value.removeTrack(t))
            }

            sendSignal('CALL_SCREEN_SHARE_START', {
              callId: callId.value
            })

            screenTrack.onended = () => {
              if (opEpoch !== _callEpoch) return
              if (!isScreenSharing.value) return

              isDebugEnabled() && console.log(`[CallStore] screenTrack.onended fired — readyState=${screenTrack.readyState}`)

              // Check if LiveKit replaced the track internally (e.g. simulcast restart).
              // If a live ScreenShare publication still exists, update our preview ref
              // instead of stopping the share.
              const rm = livekit?.room?.value
              if (rm) {
                for (const pub of rm.localParticipant.trackPublications.values()) {
                  if (pub.source === Track.Source.ScreenShare) {
                    isDebugEnabled() && console.log(`[CallStore] onended guard: found ScreenShare pub, track=${!!pub.track}, mst=${pub.track?.mediaStreamTrack?.readyState || 'none'}`)
                    if (pub.track) {
                      const freshTrack = pub.track.mediaStreamTrack
                      if (freshTrack && freshTrack.readyState === 'live') {
                        _screenStreamRef.value = markRaw(new MediaStream([freshTrack]))
                        freshTrack.onended = screenTrack.onended
                        isDebugEnabled() && console.log(`[CallStore] onended guard: replaced with fresh live track`)
                        return
                      }
                    }
                  }
                }
                isDebugEnabled() && console.log(`[CallStore] onended guard: no live ScreenShare publication found — stopping`)
              }

              toggleScreenShare()
            }

            broadcastMediaState()
            syncStreamRefs()
          }
        } catch (e) {
          console.error('[CallStore] Screen share failed:', e)
          if (e.name !== 'NotAllowedError') {
            const toast = useToastStore()
            toast.error('Screen sharing failed')
          }
        }
      }
    })
  }
  
  /**
   * Switch audio input device mid-call
   */
  async function switchAudioDevice(deviceId) {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      if (!callId.value || !livekit || !livekit.room.value) return
      
      try {
        // Use ONLY LiveKit's switchActiveDevice — do NOT also call media.switchAudioDevice().
        // Both call getUserMedia internally, creating two mic streams and a resource leak.
        await livekit.switchMicrophone(deviceId)
        if (opEpoch !== _callEpoch || !callId.value || !livekit || !livekit.room.value) return
        
        isDebugEnabled() && console.log(`[CallStore] Audio device switched to ${deviceId}`)
      } catch (e) {
        console.error('[CallStore] Failed to switch audio device:', e)
        const toast = useToastStore()
        toast.error('Failed to switch microphone')
      }
    })
  }
  
  /**
   * Switch audio output (speaker) device mid-call
   */
  async function switchAudioOutputDevice(deviceId) {
    if (!livekit || !livekit.room.value) return
    try {
      await livekit.switchAudioOutput(deviceId)
      isDebugEnabled() && console.log(`[CallStore] Audio output switched to ${deviceId}`)
    } catch (e) {
      console.error('[CallStore] Failed to switch audio output:', e)
    }
  }

  /**
   * Switch video input device mid-call
   */
  async function switchVideoDevice(deviceId) {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      if (!callId.value || !livekit || !livekit.room.value) return
      
      if (isScreenSharing.value) {
        const toast = useToastStore()
        toast.info('Cannot switch camera during screen share')
        return
      }
      
      try {
        // Use ONLY LiveKit's switchActiveDevice — do NOT also call media.switchVideoDevice().
        // Both call getUserMedia internally, creating two camera streams and a resource leak.
        await livekit.switchCamera(deviceId)
        if (opEpoch !== _callEpoch || !callId.value || !livekit || !livekit.room.value) return
        
        // Update local preview with LiveKit's new track
        if (media && media.localStream.value) {
          const newPub = livekit.room.value.localParticipant.getTrackPublication('camera')
          const newTrack = newPub?.track?.mediaStreamTrack
          
          const oldVideoTracks = media.localStream.value.getVideoTracks()
          oldVideoTracks.forEach(t => media.localStream.value.removeTrack(t))
          
          if (newTrack) {
            media.localStream.value.addTrack(newTrack)
          }
        }
        
        syncStreamRefs()
        isDebugEnabled() && console.log(`[CallStore] Video device switched to ${deviceId}`)
      } catch (e) {
        console.error('[CallStore] Failed to switch video device:', e)
        const toast = useToastStore()
        toast.error('Failed to switch camera')
      }
    })
  }
  
  /**
   * Minimize/restore call overlay
   */
  function toggleMinimize() {
    isMinimized.value = !isMinimized.value
  }
  
  // ============================================
  // HELPERS
  // ============================================
  
  /**
   * Sync the explicit reactive refs with the live livekit/media composable values.
   */
  function syncStreamRefs() {
    _localStreamRef.value = media?.localStream?.value ?? null
    _remoteStreamsRef.value = livekit ? livekit.remoteStreams.value : new Map()
    _remoteScreenStreamsRef.value = livekit ? livekit.remoteScreenStreams.value : new Map()
    _remoteScreenTracksRef.value = livekit ? livekit.remoteScreenTracks.value : new Map()
  }

  function sendSignal(type, data) {
    const socket = useMailSyncSocket()
    const sent = socket.send({ type, ...data })
    if (!sent) {
      console.error(`[CallStore] ⚠️ Failed to send ${type} — WebSocket not connected!`)
      nativeLog('[CallStore] sendSignal ' + type + ' FAILED — WS not connected')
    } else {
      isDebugEnabled() && console.log(`[CallStore] → Sent ${type}`, data?.callId || '')
    }
    return sent
  }
  
  /**
   * Toggle speaker/earpiece audio output on mobile
   */
  function toggleSpeaker() {
    isSpeakerOn.value = !isSpeakerOn.value
    
    // Use LiveKit's switchActiveDevice for audio output
    if (livekit && livekit.room.value) {
      const sinkId = isSpeakerOn.value ? 'default' : ''
      livekit.switchAudioOutput(sinkId).catch(e => {
        console.warn('[CallStore] switchAudioOutput failed:', e.message)
      })
    }
    
    // Fallback for audio elements
    const audioElements = document.querySelectorAll('audio[data-call-audio]')
    for (const audioEl of audioElements) {
      if (typeof audioEl.setSinkId === 'function') {
        const sinkId = isSpeakerOn.value ? 'default' : ''
        audioEl.setSinkId(sinkId).catch(e => {
          console.warn('[CallStore] setSinkId failed:', e.message)
        })
      }
    }
    
    // iOS Safari fallback
    if (!('setSinkId' in HTMLMediaElement.prototype)) {
      try {
        if (isSpeakerOn.value) {
          if (!window._callAudioCtx) {
            window._callAudioCtx = new (window.AudioContext || window.webkitAudioContext)()
          }
          window._callAudioCtx.resume()
        } else {
          if (window._callAudioCtx) {
            window._callAudioCtx.close()
            window._callAudioCtx = null
          }
        }
      } catch (e) {
        console.warn('[CallStore] Audio context toggle failed:', e.message)
      }
    }
    
    isDebugEnabled() && console.log(`[CallStore] Speaker toggled: ${isSpeakerOn.value ? 'ON (loudspeaker)' : 'OFF (earpiece)'}`)
  }
  
  /**
   * Flip between front and back camera on mobile.
   * Tracks the active camera index internally instead of relying on
   * Uses facingMode ('user' = front, 'environment' = back) instead of
   * deviceId + enumerateDevices(). Device enumeration on mobile browsers
   * returns devices in unstable order, making index-based cycling unreliable.
   * facingMode is the web standard for front/back camera selection and
   * works consistently across all mobile browsers.
   *
   * Calls restartTrack({ facingMode }) directly on the LiveKit camera track
   * which replaces the underlying MediaStreamTrack in-place (no off/on toggle).
   */
  async function flipCamera() {
    return withMediaLock(async () => {
      const opEpoch = _callEpoch
      if (!callId.value || !livekit || !livekit.room.value) return
      if (isScreenSharing.value) return
      if (!isVideoOn.value) return
      
      try {
        const localParticipant = livekit.room.value.localParticipant
        const cameraPub = localParticipant.getTrackPublication('camera')
        
        if (!cameraPub || !cameraPub.track) {
          isDebugEnabled() && console.warn('[CallStore] No camera track publication to flip')
          return
        }
        
        const newFacingMode = _currentFacingMode === 'user' ? 'environment' : 'user'
        
        // restartTrack replaces the underlying MediaStreamTrack in-place.
        // No need for getUserMedia, no device enumeration, no deviceId matching.
        await cameraPub.track.restartTrack({ facingMode: newFacingMode })
        if (opEpoch !== _callEpoch || !callId.value || !livekit || !livekit.room.value) return
        
        _currentFacingMode = newFacingMode
        
        // The publication's track now has a new mediaStreamTrack.
        // Swap it into the local preview stream.
        if (media && media.localStream.value) {
          const newMediaTrack = cameraPub.track.mediaStreamTrack
          
          const oldVideoTracks = media.localStream.value.getVideoTracks()
          oldVideoTracks.forEach(t => media.localStream.value.removeTrack(t))
          
          if (newMediaTrack) {
            media.localStream.value.addTrack(newMediaTrack)
          }
        }
        
        syncStreamRefs()
        isDebugEnabled() && console.log(`[CallStore] Camera flipped to facingMode: ${newFacingMode}`)
      } catch (e) {
        console.error('[CallStore] Failed to flip camera:', e)
        const toast = useToastStore()
        toast.error('Failed to flip camera')
      }
    })
  }
  
  function broadcastMediaState() {
    sendSignal('CALL_MEDIA_STATE', {
      callId: callId.value,
      audio: !isAudioMuted.value,
      video: isVideoOn.value,
      screenShare: isScreenSharing.value
    })
  }
  
  function cleanupCall() {
    clearScheduledCleanup()
    clearRingTimeout()
    _callEpoch += 1
    _savedCallRecordKey = null

    stopRingtone()
    stopRingback()
    
    // Disconnect from LiveKit room
    if (livekit) {
      livekit.disconnect()
      livekit = null
    }
    speakingParticipants.value = new Set()
    
    // Clean up media
    if (media) {
      media.cleanup()
      media = null
    }
    
    _wasVideoOnBeforeScreenShare = false
    _currentFacingMode = 'user'
    _pendingJoinOptions = null
    
    // Clear the "call in progress" indicators
    if (conversationId.value && conversationActiveCalls.value[conversationId.value]) {
      const updated = { ...conversationActiveCalls.value }
      delete updated[conversationId.value]
      conversationActiveCalls.value = updated
    }
    if (activeCallInConversation.value) {
      activeCallInConversation.value = null
    }
    
    // Reset state
    callId.value = null
    callType.value = null
    callStatus.value = 'idle'
    callDirection.value = null
    conversationId.value = null
    participants.value = []
    callerInfo.value = null
    callStartTime.value = null
    callAnswerTime.value = null
    isMinimized.value = false
    callError.value = null
    isAudioMuted.value = false
    isVideoOn.value = false
    isScreenSharing.value = false
    isSpeakerOn.value = false
    videoEffectMode.value = 'none'
    virtualBgUrl.value = null
    _bgProcessor = null
    _bgProcessorReady = false
    screenShareSurface.value = null
    _localStreamRef.value = null
    _remoteStreamsRef.value = new Map()
    _remoteScreenStreamsRef.value = new Map()
    _remoteScreenTracksRef.value = new Map()
    _screenStreamRef.value = null
    _screenShareHandler = null
    
    // Clean up speaker audio context
    if (window._callAudioCtx) {
      try { window._callAudioCtx.close() } catch(e) {}
      window._callAudioCtx = null
    }
  }
  
  async function saveCallRecord(data) {
    try {
      await api.post('/call/history', data)
    } catch (e) {
      console.error('[CallStore] Failed to save call record:', e)
    }
  }
  
  /**
   * Persist a missed call as a notification
   */
  async function persistMissedCallNotification(payload) {
    try {
      const notificationsStore = useNotificationsStore()
      const toast = useToastStore()
      
      notificationsStore.addMissedCallNotification(payload)
      
      const callerName = payload.callerName || payload.callerEmail?.split('@')[0] || 'Unknown'
      const callTypeLabel = payload.callType === 'video' ? 'video call' : 'call'
      toast.error(`Missed ${callTypeLabel} from ${callerName}`, 15000)
      
      setTimeout(() => {
        notificationsStore.fetchNotifications()
      }, 10000)
    } catch (e) {
      console.error('[CallStore] Failed to refresh notifications after missed call:', e)
    }
  }
  
  // ============================================
  // AUDIO / SOUND EFFECTS
  // ============================================
  
  let ringtoneCtx = null
  let ringtoneTimer = null
  let ringbackCtx = null
  let ringbackTimer = null
  
  function playRingtone() {
    try {
      stopRingtone()
      ringtoneCtx = new (window.AudioContext || window.webkitAudioContext)()
      
      function ringBurst() {
        if (!ringtoneCtx || ringtoneCtx.state === 'closed') return
        const t = ringtoneCtx.currentTime
        
        const g1 = ringtoneCtx.createGain()
        g1.gain.setValueAtTime(0.12, t)
        g1.gain.exponentialRampToValueAtTime(0.001, t + 0.45)
        g1.connect(ringtoneCtx.destination)
        
        const o1a = ringtoneCtx.createOscillator()
        o1a.type = 'sine'
        o1a.frequency.value = 440
        o1a.connect(g1)
        o1a.start(t)
        o1a.stop(t + 0.45)
        
        const o1b = ringtoneCtx.createOscillator()
        o1b.type = 'sine'
        o1b.frequency.value = 480
        o1b.connect(g1)
        o1b.start(t)
        o1b.stop(t + 0.45)
        
        const g2 = ringtoneCtx.createGain()
        g2.gain.setValueAtTime(0.12, t + 0.65)
        g2.gain.exponentialRampToValueAtTime(0.001, t + 1.1)
        g2.connect(ringtoneCtx.destination)
        
        const o2a = ringtoneCtx.createOscillator()
        o2a.type = 'sine'
        o2a.frequency.value = 440
        o2a.connect(g2)
        o2a.start(t + 0.65)
        o2a.stop(t + 1.1)
        
        const o2b = ringtoneCtx.createOscillator()
        o2b.type = 'sine'
        o2b.frequency.value = 480
        o2b.connect(g2)
        o2b.start(t + 0.65)
        o2b.stop(t + 1.1)
      }
      
      ringBurst()
      ringtoneTimer = setInterval(ringBurst, 3000)
    } catch (e) {
      // AudioContext not available
    }
  }
  
  function stopRingtone() {
    if (ringtoneTimer) {
      clearInterval(ringtoneTimer)
      ringtoneTimer = null
    }
    if (ringtoneCtx) {
      ringtoneCtx.close().catch(() => {})
      ringtoneCtx = null
    }
  }
  
  function playRingback() {
    try {
      stopRingback()
      ringbackCtx = new (window.AudioContext || window.webkitAudioContext)()
      
      function ringbackTone() {
        if (!ringbackCtx || ringbackCtx.state === 'closed') return
        const t = ringbackCtx.currentTime
        
        const gain = ringbackCtx.createGain()
        gain.gain.setValueAtTime(0.06, t)
        gain.gain.setValueAtTime(0.06, t + 1.8)
        gain.gain.exponentialRampToValueAtTime(0.001, t + 2.0)
        gain.connect(ringbackCtx.destination)
        
        const osc1 = ringbackCtx.createOscillator()
        osc1.type = 'sine'
        osc1.frequency.value = 440
        osc1.connect(gain)
        osc1.start(t)
        osc1.stop(t + 2.0)
        
        const osc2 = ringbackCtx.createOscillator()
        osc2.type = 'sine'
        osc2.frequency.value = 480
        osc2.connect(gain)
        osc2.start(t)
        osc2.stop(t + 2.0)
      }
      
      ringbackTone()
      ringbackTimer = setInterval(ringbackTone, 6000)
    } catch (e) {
      // AudioContext not available
    }
  }
  
  function stopRingback() {
    if (ringbackTimer) {
      clearInterval(ringbackTimer)
      ringbackTimer = null
    }
    if (ringbackCtx) {
      ringbackCtx.close().catch(() => {})
      ringbackCtx = null
    }
  }
  
  function playHangupTone() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)()
      const t = ctx.currentTime
      
      const frequencies = [620, 480, 380]
      const beepDuration = 0.15
      const gap = 0.08
      
      frequencies.forEach((freq, i) => {
        const startTime = t + i * (beepDuration + gap)
        
        const gain = ctx.createGain()
        gain.gain.setValueAtTime(0.1, startTime)
        gain.gain.exponentialRampToValueAtTime(0.001, startTime + beepDuration)
        gain.connect(ctx.destination)
        
        const osc = ctx.createOscillator()
        osc.type = 'sine'
        osc.frequency.value = freq
        osc.connect(gain)
        osc.start(startTime)
        osc.stop(startTime + beepDuration)
      })
      
      const totalDuration = frequencies.length * (beepDuration + gap) + 0.1
      setTimeout(() => ctx.close().catch(() => {}), totalDuration * 1000)
    } catch (e) {
      // AudioContext not available
    }
  }
  
  return {
    // State
    callId,
    callType,
    callStatus,
    callDirection,
    conversationId,
    participants,
    callerInfo,
    callStartTime,
    callAnswerTime,
    isMinimized,
    callError,
    isAudioMuted,
    isVideoOn,
    isScreenSharing,
    isSpeakerOn,
    videoEffectMode,
    blurRadius,
    virtualBgUrl,
    screenShareSurface,
    activeCallInConversation,
    conversationActiveCalls,
    nativeRingCallId,
    audioPlaybackBlocked,
    
    // Computed
    isInCall,
    isRinging,
    isActive,
    nativeRingActive,
    remoteStreams,
    remoteScreenStreams,
    remoteScreenTracks,
    localStream,
    screenStream,
    speakingParticipants,
    onScreenShareTrack,
    offScreenShareTrack,
    
    // Actions
    setupSocketListeners,
    initiateCall,
    answerCall,
    rejectCall,
    hangUp,
    markNativeRing,
    clearNativeRing,
    acceptFromNative,
    rejectFromNative,
    setMutedFromNative,
    notifyAudioBlocked,
    enableAudioPlayback,
    toggleMute,
    toggleVideo,
    toggleScreenShare,
    setVideoEffect,
    setBlurRadius,
    toggleMinimize,
    toggleSpeaker,
    flipCamera,
    switchAudioDevice,
    switchVideoDevice,
    switchAudioOutputDevice,
    cleanupCall,
    queryActiveCall,
    queryAllActiveCalls,
    joinCall
  }
})
