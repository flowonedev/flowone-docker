/**
 * Huddle Store (Pinia)
 * 
 * Manages persistent audio rooms (huddles) with real WebRTC audio.
 * Unlike regular calls (ring-and-answer), huddles are drop-in/drop-out.
 * 
 * Coordinates:
 * - HTTP API for huddle state (start, join, leave)
 * - WebSocket signaling for WebRTC (SDP, ICE)
 * - WebRTC peer connections for audio streaming
 * - Media devices for microphone capture
 */

import { defineStore } from 'pinia'
import { isDebugEnabled } from '@/utils/debug'
import { ref, computed } from 'vue'
import { useMailSyncSocket, EventTypes } from '@/services/mailSyncSocket'
import { useWebRTC } from '@/composables/useWebRTC'
import { useMediaDevices } from '@/composables/useMediaDevices'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

export const useHuddleStore = defineStore('huddle', () => {
  // ============================================
  // STATE
  // ============================================
  
  const huddleId = ref(null)
  const conversationId = ref(null)
  const huddle = ref(null)              // Full huddle object from backend
  const isInHuddle = ref(false)
  const isMuted = ref(false)
  const isDeafened = ref(false)
  const loading = ref(false)
  const elapsedTime = ref('')
  
  // Map of conversationId -> huddle info for sidebar indicators
  const conversationActiveHuddles = ref({})
  
  // WebRTC and media composables (scoped per huddle session)
  let webrtc = null
  let media = null
  let timerInterval = null
  let pollInterval = null
  
  // Buffer ICE candidates that arrive before the peer connection is ready
  let pendingIceCandidates = [] // { fromEmail, candidate }

  // Local mic audio level detection
  let _localAudioCtx = null
  let _localAnalyser = null
  let _localSourceNode = null
  let _localLevelTimer = null
  let _localFramesAbove = 0
  let _lastSpeakingBroadcast = false
  
  // AudioContext for reliable mobile audio playback.
  // Created during user gesture (join/start) so it's pre-authorized
  // by browser autoplay policy.
  let _huddleAudioCtx = null
  // Track MediaStreamSource nodes for cleanup
  let _audioSourceNodes = new Map() // email -> MediaStreamAudioSourceNode
  
  // ============================================
  // COMPUTED
  // ============================================
  
  const participants = computed(() => {
    if (!huddle.value?.participants) return []
    return huddle.value.participants
  })
  
  const participantCount = computed(() => participants.value.length)
  
  const localStream = computed(() => {
    return media ? media.localStream.value : null
  })
  
  const remoteStreams = computed(() => {
    return webrtc ? webrtc.remoteStreams.value : new Map()
  })
  
  // ============================================
  // WEBSOCKET EVENT HANDLERS
  // ============================================
  
  // Speaking state for Part 4 speaking indicators
  const speakingParticipants = ref(new Set())

  function setupSocketListeners() {
    const socket = useMailSyncSocket()
    
    socket.on(EventTypes.HUDDLE_PARTICIPANT_JOINED, (event) => {
      handleParticipantJoined(event)
      // Refresh sidebar indicators for all huddles
      fetchAllActiveHuddles()
    })
    socket.on(EventTypes.HUDDLE_PARTICIPANT_LEFT, (event) => {
      handleParticipantLeft(event)
      fetchAllActiveHuddles()
    })
    socket.on(EventTypes.HUDDLE_ENDED, () => {
      fetchAllActiveHuddles()
    })
    socket.on(EventTypes.HUDDLE_SDP_OFFER, handleSdpOffer)
    socket.on(EventTypes.HUDDLE_SDP_ANSWER, handleSdpAnswer)
    socket.on(EventTypes.HUDDLE_ICE_CANDIDATE, handleIceCandidate)
    socket.on(EventTypes.HUDDLE_MEDIA_STATE, handleMediaState)
    
    // Speaking indicator events
    if (EventTypes.HUDDLE_SPEAKING) {
      socket.on(EventTypes.HUDDLE_SPEAKING, handleSpeakingState)
    }
  }

  function handleSpeakingState(event) {
    const payload = event.payload || event
    const { email, isSpeaking: speaking } = payload
    if (!email) return
    const newSet = new Set(speakingParticipants.value)
    if (speaking) {
      newSet.add(email.toLowerCase())
    } else {
      newSet.delete(email.toLowerCase())
    }
    speakingParticipants.value = newSet
  }
  
  /**
   * Handle HUDDLE_PARTICIPANT_JOINED event
   * Two cases:
   * 1. participantEmail is set: another participant joined -> create peer connection + send offer
   * 2. participantEmail is null: join acknowledgment with existing participants list
   */
  async function handleParticipantJoined(event) {
    const payload = event.payload || event
    if (payload.huddleId !== huddleId.value) return
    if (!webrtc || !media) return
    
    const auth = useAuthStore()
    const myEmail = auth.userEmail?.toLowerCase()
    
    if (payload.participantEmail && payload.participantEmail.toLowerCase() !== myEmail) {
      // Case 1: Another participant joined - create peer connection and send offer
      const newEmail = payload.participantEmail
      isDebugEnabled() && console.log(`[HuddleStore] Participant joined: ${newEmail}`)
      
      if (webrtc.peerConnections.value.has(newEmail)) {
        isDebugEnabled() && console.log(`[HuddleStore] Already have peer connection to ${newEmail}, skipping`)
        return
      }
      
      try {
        webrtc.createPeerConnection(newEmail)
        
        if (media.localStream.value) {
          webrtc.addLocalTracks(newEmail, media.localStream.value)
        }
        
        const offer = await webrtc.createOffer(newEmail)
        sendSignal('HUDDLE_SDP_OFFER', {
          huddleId: huddleId.value,
          sdp: offer,
          targetEmail: newEmail
        })
        
        isDebugEnabled() && console.log(`[HuddleStore] Sent SDP offer to new participant: ${newEmail}`)
      } catch (e) {
        console.error(`[HuddleStore] Failed to connect to ${newEmail}:`, e)
      }
    } else if (!payload.participantEmail && payload.existingParticipants?.length > 0) {
      // Case 2: Join acknowledgment - we'll receive SDP offers from existing participants
      // They will send offers to us; we just need to wait
      isDebugEnabled() && console.log(`[HuddleStore] Join acknowledged, existing participants:`, payload.existingParticipants)
    }
    
    // Refresh huddle state from backend
    await fetchHuddleState()
  }
  
  async function handleParticipantLeft(event) {
    const payload = event.payload || event
    if (payload.huddleId !== huddleId.value) return
    
    const leaverEmail = payload.participantEmail
    isDebugEnabled() && console.log(`[HuddleStore] Participant left: ${leaverEmail}`)
    
    // Close peer connection for the leaver
    if (webrtc) {
      webrtc.closePeerConnection(leaverEmail)
    }
    
    // Refresh huddle state
    await fetchHuddleState()
  }
  
  async function handleSdpOffer(event) {
    const payload = event.payload || event
    if (payload.huddleId !== huddleId.value) return
    if (!webrtc || !media) return
    
    const fromEmail = payload.fromEmail
    isDebugEnabled() && console.log(`[HuddleStore] Received SDP offer from ${fromEmail}`)
    
    try {
      // Create peer connection if not exists
      if (!webrtc.peerConnections.value.has(fromEmail)) {
        webrtc.createPeerConnection(fromEmail)
      }
      
      // Set remote description FIRST (correct WebRTC order for answerer).
      // This puts the PC in 'have-remote-offer' state before adding local
      // tracks, which prevents spurious negotiationneeded events.
      await webrtc.setRemoteDescription(fromEmail, payload.sdp)
      
      // THEN add local tracks (in 'have-remote-offer' state)
      if (media.localStream.value) {
        const pc = webrtc.peerConnections.value.get(fromEmail)
        const hasSenders = pc?.getSenders().some(s => s.track)
        if (!hasSenders) {
          webrtc.addLocalTracks(fromEmail, media.localStream.value)
        }
      }
      
      // Create and send answer
      const answer = await webrtc.createAnswer(fromEmail)
      sendSignal('HUDDLE_SDP_ANSWER', {
        huddleId: huddleId.value,
        sdp: answer,
        targetEmail: fromEmail
      })
      
      isDebugEnabled() && console.log(`[HuddleStore] Sent SDP answer to ${fromEmail}`)
      
      // Flush buffered ICE candidates for this peer
      const buffered = pendingIceCandidates.filter(c => c.fromEmail === fromEmail)
      for (const { candidate } of buffered) {
        try {
          await webrtc.addIceCandidate(fromEmail, candidate)
        } catch (e) {
          console.warn('[HuddleStore] Failed to add buffered ICE candidate:', e)
        }
      }
      pendingIceCandidates = pendingIceCandidates.filter(c => c.fromEmail !== fromEmail)
      
    } catch (e) {
      console.error(`[HuddleStore] Failed to handle SDP offer from ${fromEmail}:`, e)
    }
  }
  
  async function handleSdpAnswer(event) {
    const payload = event.payload || event
    if (payload.huddleId !== huddleId.value) return
    if (!webrtc) return
    
    const fromEmail = payload.fromEmail
    isDebugEnabled() && console.log(`[HuddleStore] Received SDP answer from ${fromEmail}`)
    
    try {
      await webrtc.setRemoteDescription(fromEmail, payload.sdp)
      
      // Flush buffered ICE candidates
      const buffered = pendingIceCandidates.filter(c => c.fromEmail === fromEmail)
      for (const { candidate } of buffered) {
        try {
          await webrtc.addIceCandidate(fromEmail, candidate)
        } catch (e) {
          console.warn('[HuddleStore] Failed to add buffered ICE candidate:', e)
        }
      }
      pendingIceCandidates = pendingIceCandidates.filter(c => c.fromEmail !== fromEmail)
    } catch (e) {
      console.error(`[HuddleStore] Failed to handle SDP answer from ${fromEmail}:`, e)
    }
  }
  
  async function handleIceCandidate(event) {
    const payload = event.payload || event
    if (payload.huddleId !== huddleId.value) return
    if (!payload.candidate) return
    
    if (webrtc) {
      // Explicitly check peer connection and remote description before adding.
      // Previously this relied on useWebRTC.addIceCandidate throwing on failure,
      // but it silently returned/caught errors, causing ICE candidates to be lost.
      const pc = webrtc.peerConnections.value.get(payload.fromEmail)
      if (!pc) {
        isDebugEnabled() && console.log(`[HuddleStore] Buffering ICE candidate for ${payload.fromEmail} (peer connection not created yet)`)
        pendingIceCandidates.push({ fromEmail: payload.fromEmail, candidate: payload.candidate })
        return
      }
      
      if (!pc.remoteDescription) {
        isDebugEnabled() && console.log(`[HuddleStore] Buffering ICE candidate for ${payload.fromEmail} (no remote description yet)`)
        pendingIceCandidates.push({ fromEmail: payload.fromEmail, candidate: payload.candidate })
        return
      }
      
      try {
        await webrtc.addIceCandidate(payload.fromEmail, payload.candidate)
      } catch (e) {
        isDebugEnabled() && console.log(`[HuddleStore] Buffering ICE candidate for ${payload.fromEmail} (add failed):`, e.message)
        pendingIceCandidates.push({ fromEmail: payload.fromEmail, candidate: payload.candidate })
      }
    } else {
      pendingIceCandidates.push({ fromEmail: payload.fromEmail, candidate: payload.candidate })
    }
  }
  
  function handleMediaState(event) {
    const payload = event.payload || event
    if (payload.huddleId !== huddleId.value) return
    
    // Update participant's mute/deafen state in the huddle data
    if (huddle.value?.participants) {
      const participant = huddle.value.participants.find(
        p => p.email === payload.participantEmail
      )
      if (participant) {
        participant.is_muted = payload.isMuted ? 1 : 0
        participant.is_deafened = payload.isDeafened ? 1 : 0
      }
    }
  }
  
  // ============================================
  // HUDDLE ACTIONS
  // ============================================
  
  /**
   * Start a new huddle or join existing one in a conversation
   */
  async function startHuddle(convId) {
    if (isInHuddle.value) {
      const toast = useToastStore()
      toast.warning('You are already in a huddle')
      return
    }
    
    loading.value = true
    try {
      // 1. Register with backend (creates DB record)
      const response = await api.post('/chat/huddles/start', {
        conversation_id: convId
      })
      
      if (!response.data.success) {
        throw new Error(response.data.error || 'Failed to start huddle')
      }
      
      const huddleData = response.data.data.huddle
      huddleId.value = huddleData.id
      conversationId.value = convId
      huddle.value = huddleData
      isInHuddle.value = true
      
      // 2. Initialize WebRTC audio
      await setupWebRTCAudio()
      
      // 3. Signal join via WebSocket (triggers peer connections)
      sendSignal('HUDDLE_JOIN', {
        huddleId: huddleData.id,
        conversationId: convId
      })
      
      // 4. Start timer
      startTimer()
      startPolling()
      
      isDebugEnabled() && console.log(`[HuddleStore] Joined huddle ${huddleData.id} in conversation ${convId}`)
      
    } catch (e) {
      console.error('[HuddleStore] Failed to start huddle:', e)
      const toast = useToastStore()
      toast.error(`Failed to start huddle: ${e.message}`)
      cleanupHuddle()
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Join an existing active huddle
   */
  async function joinHuddle(existingHuddleId, convId) {
    if (isInHuddle.value) {
      const toast = useToastStore()
      toast.warning('You are already in a huddle')
      return
    }
    
    loading.value = true
    try {
      // 1. Register with backend
      const response = await api.post(`/chat/huddles/${existingHuddleId}/join`)
      
      if (!response.data.success) {
        throw new Error(response.data.error || 'Failed to join huddle')
      }
      
      const huddleData = response.data.data.huddle
      huddleId.value = huddleData.id
      conversationId.value = convId || huddleData.conversation_id
      huddle.value = huddleData
      isInHuddle.value = true
      
      // 2. Initialize WebRTC audio
      await setupWebRTCAudio()
      
      // 3. Signal join via WebSocket
      sendSignal('HUDDLE_JOIN', {
        huddleId: huddleData.id,
        conversationId: conversationId.value
      })
      
      // 4. Start timer
      startTimer()
      startPolling()
      
      isDebugEnabled() && console.log(`[HuddleStore] Joined existing huddle ${huddleData.id}`)
      
    } catch (e) {
      console.error('[HuddleStore] Failed to join huddle:', e)
      const toast = useToastStore()
      toast.error(`Failed to join huddle: ${e.message}`)
      cleanupHuddle()
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Leave the current huddle
   */
  async function leaveHuddle() {
    if (!huddleId.value) return
    
    const hId = huddleId.value
    
    try {
      // Signal leave via WebSocket first (so peers disconnect quickly)
      sendSignal('HUDDLE_LEAVE', { huddleId: hId })
      
      // Then update backend
      await api.post(`/chat/huddles/${hId}/leave`)
      
    } catch (e) {
      console.error('[HuddleStore] Failed to leave huddle:', e)
    }
    
    cleanupHuddle(false)
  }
  
  /**
   * Toggle microphone mute
   */
  function toggleMute() {
    if (!media) return
    
    const audioOn = media.toggleAudio()
    isMuted.value = !audioOn
    
    // Broadcast state to other participants
    sendSignal('HUDDLE_MEDIA_STATE', {
      huddleId: huddleId.value,
      isMuted: isMuted.value,
      isDeafened: isDeafened.value
    })
  }
  
  /**
   * Toggle deafen (mutes incoming audio + own mic)
   */
  function toggleDeafen() {
    isDeafened.value = !isDeafened.value
    
    if (isDeafened.value) {
      // Mute self when deafening
      if (media && !isMuted.value) {
        media.toggleAudio()
      }
      isMuted.value = true
      
      // Mute all remote audio
      if (webrtc) {
        for (const [, stream] of webrtc.remoteStreams.value) {
          stream.getAudioTracks().forEach(track => { track.enabled = false })
        }
      }
    } else {
      // Undeafen - restore remote audio
      if (webrtc) {
        for (const [, stream] of webrtc.remoteStreams.value) {
          stream.getAudioTracks().forEach(track => { track.enabled = true })
        }
      }
    }
    
    sendSignal('HUDDLE_MEDIA_STATE', {
      huddleId: huddleId.value,
      isMuted: isMuted.value,
      isDeafened: isDeafened.value
    })
  }
  
  // ============================================
  // WEBRTC SETUP
  // ============================================
  
  async function setupWebRTCAudio() {
    webrtc = useWebRTC()
    media = useMediaDevices()
    
    // IMPORTANT: Create and resume AudioContext NOW during the user gesture
    // (startHuddle/joinHuddle tap). Mobile browsers require audio playback to
    // originate from a user interaction. If we wait until remote tracks arrive,
    // the gesture window has expired and audio will be silently blocked.
    try {
      if (!_huddleAudioCtx || _huddleAudioCtx.state === 'closed') {
        _huddleAudioCtx = new (window.AudioContext || window.webkitAudioContext)()
      }
      if (_huddleAudioCtx.state === 'suspended') {
        await _huddleAudioCtx.resume()
      }
      isDebugEnabled() && console.log('[HuddleStore] AudioContext warmed up:', _huddleAudioCtx.state)
    } catch (e) {
      console.warn('[HuddleStore] Failed to warm AudioContext:', e)
    }
    
    // Fetch TURN server credentials
    await webrtc.fetchIceServers()
    
    // Get local audio stream
    try {
      await media.getUserMedia({ audio: true, video: false })
      isDebugEnabled() && console.log('[HuddleStore] Microphone captured successfully')
    } catch (mediaErr) {
      if (mediaErr.name === 'NotFoundError' || mediaErr.name === 'NotReadableError' || mediaErr.name === 'NotAllowedError') {
        isMuted.value = true
        const toast = useToastStore()
        toast.warning('No microphone found. You can listen but others won\'t hear you.')
      } else {
        throw mediaErr
      }
    }
    
    // Start local speaking detection after mic capture
    startLocalSpeakingDetection()

    // Set up WebRTC callbacks
    webrtc.setCallbacks({
      onIceCandidateCb: (targetEmail, candidate) => {
        sendSignal('HUDDLE_ICE_CANDIDATE', {
          huddleId: huddleId.value,
          candidate,
          targetEmail
        })
      },
      onRemoteTrackCb: (email, track, stream) => {
        isDebugEnabled() && console.log(`[HuddleStore] Remote audio track from ${email}: kind=${track.kind}, enabled=${track.enabled}`)
        // Auto-play remote audio
        if (track.kind === 'audio') {
          playRemoteAudio(email, stream)
        }
      },
      onConnectionStateChangeCb: (email, state) => {
        isDebugEnabled() && console.log(`[HuddleStore] Connection state for ${email}: ${state}`)
      }
    })
  }
  
  /**
   * Start monitoring local mic level for speaking detection.
   * Broadcasts HUDDLE_SPEAKING events when state changes.
   */
  function startLocalSpeakingDetection() {
    stopLocalSpeakingDetection()

    const stream = media?.localStream?.value
    if (!stream || !stream.getAudioTracks().length) return

    try {
      _localAudioCtx = new (window.AudioContext || window.webkitAudioContext)()
      _localAnalyser = _localAudioCtx.createAnalyser()
      _localAnalyser.fftSize = 256
      _localAnalyser.smoothingTimeConstant = 0.3

      _localSourceNode = _localAudioCtx.createMediaStreamSource(stream)
      _localSourceNode.connect(_localAnalyser)

      const bufferLength = _localAnalyser.frequencyBinCount
      const dataArray = new Uint8Array(bufferLength)
      const THRESHOLD = 15
      const CONSECUTIVE = 3

      _localLevelTimer = setInterval(() => {
        if (isMuted.value) {
          if (_lastSpeakingBroadcast) {
            _lastSpeakingBroadcast = false
            broadcastSpeakingState(false)
          }
          return
        }

        _localAnalyser.getByteFrequencyData(dataArray)
        let sum = 0
        for (let i = 0; i < bufferLength; i++) sum += dataArray[i]
        const avg = sum / bufferLength

        if (avg > THRESHOLD) {
          _localFramesAbove++
          if (_localFramesAbove >= CONSECUTIVE && !_lastSpeakingBroadcast) {
            _lastSpeakingBroadcast = true
            broadcastSpeakingState(true)
          }
        } else {
          _localFramesAbove = 0
          if (_lastSpeakingBroadcast) {
            _lastSpeakingBroadcast = false
            broadcastSpeakingState(false)
          }
        }
      }, 60)

      isDebugEnabled() && console.log('[HuddleStore] Local speaking detection started')
    } catch (e) {
      console.warn('[HuddleStore] Local speaking detection failed:', e)
    }
  }

  function stopLocalSpeakingDetection() {
    if (_localLevelTimer) { clearInterval(_localLevelTimer); _localLevelTimer = null }
    if (_localSourceNode) { try { _localSourceNode.disconnect() } catch (_) {} _localSourceNode = null }
    if (_localAnalyser) { try { _localAnalyser.disconnect() } catch (_) {} _localAnalyser = null }
    if (_localAudioCtx && _localAudioCtx.state !== 'closed') { try { _localAudioCtx.close() } catch (_) {} _localAudioCtx = null }
    _localFramesAbove = 0
    _lastSpeakingBroadcast = false
  }

  function broadcastSpeakingState(speaking) {
    if (!huddleId.value) return
    const auth = useAuthStore()
    sendSignal('HUDDLE_SPEAKING', {
      huddleId: huddleId.value,
      email: auth.userEmail,
      isSpeaking: speaking,
    })

    // Update local speakingParticipants immediately
    const email = auth.userEmail?.toLowerCase()
    if (email) {
      const newSet = new Set(speakingParticipants.value)
      if (speaking) {
        newSet.add(email)
      } else {
        newSet.delete(email)
      }
      speakingParticipants.value = newSet
    }
  }

  /**
   * Play remote audio stream using TWO methods for maximum reliability:
   * 1. Web Audio API (AudioContext) – most reliable on mobile because the
   *    AudioContext was pre-authorized during the user's join gesture.
   * 2. Hidden <audio> element – fallback / belt-and-suspenders.
   */
  function playRemoteAudio(email, stream) {
    isDebugEnabled() && console.log(`[HuddleStore] playRemoteAudio for ${email}: tracks=${stream.getAudioTracks().length}, audioCtx=${_huddleAudioCtx?.state}`)
    
    // === Method 1: AudioContext (primary – pre-authorized during join gesture) ===
    // Disconnect previous source for this participant
    if (_audioSourceNodes.has(email)) {
      try { _audioSourceNodes.get(email).disconnect() } catch (e) {}
      _audioSourceNodes.delete(email)
    }
    
    if (_huddleAudioCtx && _huddleAudioCtx.state !== 'closed') {
      try {
        // Resume if suspended (might happen after backgrounding on mobile)
        if (_huddleAudioCtx.state === 'suspended') {
          _huddleAudioCtx.resume().catch(() => {})
        }
        const source = _huddleAudioCtx.createMediaStreamSource(stream)
        source.connect(_huddleAudioCtx.destination)
        _audioSourceNodes.set(email, source)
        isDebugEnabled() && console.log(`[HuddleStore] AudioContext playing audio from ${email}`)
      } catch (e) {
        console.warn(`[HuddleStore] AudioContext play failed for ${email}:`, e.message)
      }
    }
    
    // === Method 2: <audio> element fallback ===
    const existingEl = document.querySelector(`audio[data-huddle-audio="${email}"]`)
    if (existingEl) existingEl.remove()
    
    const audioEl = document.createElement('audio')
    audioEl.setAttribute('data-huddle-audio', email)
    audioEl.autoplay = true
    audioEl.playsInline = true
    audioEl.srcObject = stream
    // Use off-screen positioning instead of display:none
    // (some mobile browsers restrict audio from display:none elements)
    audioEl.style.cssText = 'position:absolute;width:1px;height:1px;left:-9999px;'
    document.body.appendChild(audioEl)
    
    const attemptPlay = (retries = 0) => {
      audioEl.play().catch(e => {
        console.warn(`[HuddleStore] Audio element play failed for ${email} (attempt ${retries + 1}):`, e.message)
        if (retries < 3) {
          setTimeout(() => attemptPlay(retries + 1), 1000 * (retries + 1))
        }
      })
    }
    attemptPlay()
    
    // Resume audio on next user interaction if autoplay was blocked
    const resumeOnInteraction = () => {
      if (audioEl.paused && audioEl.srcObject) {
        audioEl.play().catch(() => {})
      }
      if (_huddleAudioCtx?.state === 'suspended') {
        _huddleAudioCtx.resume().catch(() => {})
      }
    }
    document.addEventListener('click', resumeOnInteraction, { once: true })
    document.addEventListener('touchstart', resumeOnInteraction, { once: true })
  }
  
  // ============================================
  // POLLING & TIMER
  // ============================================
  
  async function fetchHuddleState() {
    if (!conversationId.value) return
    try {
      const response = await api.get(`/chat/huddles/active/${conversationId.value}`)
      if (response.data.success) {
        const h = response.data.data?.huddle
        if (h && h.is_active) {
          huddle.value = h
        } else if (isInHuddle.value) {
          // Huddle ended while we're in it
          cleanupHuddle()
        }
      }
    } catch (e) {
      // Silent fail
    }
  }
  
  /**
   * Fetch all active huddles across conversations for sidebar indicators
   */
  let _huddlesHydratedAt = 0
  const HUDDLE_HYDRATE_COOLDOWN = 15000

  function markInitPending() {
    _huddlesHydratedAt = Date.now()
  }

  async function fetchAllActiveHuddles() {
    if (_huddlesHydratedAt && (Date.now() - _huddlesHydratedAt < HUDDLE_HYDRATE_COOLDOWN)) return
    try {
      const response = await api.get('/chat/huddles/active-all')
      if (response.data.success) {
        hydrateActiveHuddles(response.data.data?.huddles || [])
      }
    } catch (e) {
      // Silent - table may not exist yet
    }
  }

  function hydrateActiveHuddles(huddles) {
    const map = {}
    for (const h of (huddles || [])) {
      if (h.conversation_id) {
        map[h.conversation_id] = {
          huddleId: h.id,
          participantCount: h.participants?.length || 0,
          participants: h.participants || [],
          startedAt: h.started_at
        }
      }
    }
    conversationActiveHuddles.value = map
    _huddlesHydratedAt = Date.now()
  }
  
  function startTimer() {
    if (timerInterval) clearInterval(timerInterval)
    timerInterval = setInterval(() => {
      if (!huddle.value?.started_at) return
      const diff = Math.floor((Date.now() - new Date(huddle.value.started_at).getTime()) / 1000)
      const mins = Math.floor(diff / 60)
      const secs = diff % 60
      elapsedTime.value = `${mins}:${secs.toString().padStart(2, '0')}`
    }, 1000)
  }
  
  function stopTimer() {
    if (timerInterval) {
      clearInterval(timerInterval)
      timerInterval = null
    }
    elapsedTime.value = ''
  }
  
  function startPolling() {
    if (pollInterval) clearInterval(pollInterval)
    pollInterval = setInterval(() => {
      fetchHuddleState()
      fetchAllActiveHuddles()
    }, 5000)
  }
  
  function stopPolling() {
    if (pollInterval) {
      clearInterval(pollInterval)
      pollInterval = null
    }
  }
  
  // ============================================
  // HELPERS
  // ============================================
  
  function sendSignal(type, data) {
    const socket = useMailSyncSocket()
    const sent = socket.send({ type, ...data })
    if (!sent) {
      console.error(`[HuddleStore] Failed to send ${type} - WebSocket not connected`)
    }
    return sent
  }
  
  function cleanupHuddle(shouldSignalLeave = true) {
    // Signal leave if we haven't already
    if (shouldSignalLeave && huddleId.value) {
      sendSignal('HUDDLE_LEAVE', { huddleId: huddleId.value })
    }
    
    // Clean up WebRTC
    if (webrtc) {
      webrtc.closeAll()
      webrtc = null
    }
    
    // Clean up media
    if (media) {
      media.cleanup()
      media = null
    }
    
    // Remove all hidden audio elements
    document.querySelectorAll('audio[data-huddle-audio]').forEach(el => el.remove())
    
    // Clean up AudioContext and source nodes
    for (const [, source] of _audioSourceNodes) {
      try { source.disconnect() } catch (e) {}
    }
    _audioSourceNodes.clear()
    if (_huddleAudioCtx) {
      _huddleAudioCtx.close().catch(() => {})
      _huddleAudioCtx = null
    }

    // Clean up speaking detection
    stopLocalSpeakingDetection()
    speakingParticipants.value = new Set()
    
    // Clear buffers
    pendingIceCandidates = []
    
    // Stop timers
    stopTimer()
    stopPolling()
    
    // Remove this conversation's huddle indicator from the sidebar immediately
    // Then do a final refresh to get accurate state (huddle may still be active with others)
    const convId = conversationId.value
    if (convId && conversationActiveHuddles.value[convId]) {
      const updated = { ...conversationActiveHuddles.value }
      delete updated[convId]
      conversationActiveHuddles.value = updated
    }
    
    // Reset state
    huddleId.value = null
    conversationId.value = null
    huddle.value = null
    isInHuddle.value = false
    isMuted.value = false
    isDeafened.value = false
    loading.value = false
    
    // Final refresh of all active huddles so sidebar shows correct state
    // (the huddle might still be active with other participants)
    setTimeout(() => fetchAllActiveHuddles(), 1000)
  }
  
  return {
    // State
    huddleId,
    conversationId,
    huddle,
    isInHuddle,
    isMuted,
    isDeafened,
    loading,
    elapsedTime,
    conversationActiveHuddles,
    speakingParticipants,

    // Computed
    participants,
    participantCount,
    localStream,
    remoteStreams,
    
    // Actions
    setupSocketListeners,
    startHuddle,
    joinHuddle,
    leaveHuddle,
    toggleMute,
    toggleDeafen,
    fetchHuddleState,
    fetchAllActiveHuddles,
    hydrateActiveHuddles,
    markInitPending,
    cleanupHuddle
  }
})

