/**
 * useWebRTC composable
 * 
 * Manages WebRTC peer connections for voice/video calls.
 * Handles SDP offer/answer exchange, ICE candidates, and track management.
 */

import { ref, onUnmounted } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

export function useWebRTC() {
  const peerConnections = ref(new Map()) // email -> RTCPeerConnection
  const remoteStreams = ref(new Map())   // email -> MediaStream (camera)
  const remoteScreenStreams = ref(new Map()) // email -> MediaStream (screen share)
  const iceServers = ref([])
  const connectionState = ref('new')     // new, connecting, connected, failed, closed
  const peerConnectionStates = ref(new Map()) // email -> connectionState (per-peer tracking for group calls)
  
  // Track which participants are screen sharing (set by call store via CALL_SCREEN_SHARE_START)
  const screenSharingParticipants = ref(new Set())
  // Track screen share stream IDs for reliable track identification
  const screenShareStreamIds = ref(new Map()) // email -> streamId

  // ICE candidates that arrived before setRemoteDescription — flushed once remote SDP is set
  const pendingIceCandidates = new Map() // email -> RTCIceCandidate[]
  // Prevents concurrent negotiation on the same peer connection
  const negotiationLocks = new Map() // email -> boolean
  
  // Callbacks
  let onRemoteTrack = null
  let onIceCandidate = null
  let onConnectionStateChange = null
  let onNegotiationNeeded = null
  
  /**
   * Fetch ICE/TURN server configuration from backend
   */
  async function fetchIceServers() {
    try {
      const response = await api.get('/call/ice-servers')
      if (response.data.success) {
        iceServers.value = response.data.data.iceServers || []
        return iceServers.value
      }
    } catch (e) {
      console.error('[WebRTC] Failed to fetch ICE servers:', e)
    }
    
    // Fallback to public STUN
    iceServers.value = [{ urls: 'stun:stun.l.google.com:19302' }]
    return iceServers.value
  }
  
  /**
   * Create a peer connection for a participant
   */
  function createPeerConnection(participantEmail) {
    if (peerConnections.value.has(participantEmail)) {
      return peerConnections.value.get(participantEmail)
    }
    
    const pc = new RTCPeerConnection({
      iceServers: iceServers.value,
      iceCandidatePoolSize: 10
    })
    
    // ICE candidate handling
    pc.onicecandidate = (event) => {
      if (peerConnections.value.get(participantEmail) !== pc) return
      if (event.candidate && onIceCandidate) {
        onIceCandidate(participantEmail, event.candidate)
      }
    }
    
    // Remote track handling
    // Distinguish between camera and screen share tracks:
    // - If participant is marked as screen sharing and we receive a video track
    //   on a DIFFERENT stream from the camera/audio stream, it goes into remoteScreenStreams
    // - Camera + audio tracks go into remoteStreams
    pc.ontrack = (event) => {
      if (peerConnections.value.get(participantEmail) !== pc) return
      isDebugEnabled() && console.log(`[WebRTC] ontrack: kind=${event.track.kind}, readyState=${event.track.readyState}, enabled=${event.track.enabled}, label="${event.track.label}", nativeStreams=${event.streams?.length || 0}, streamId=${event.streams?.[0]?.id || 'none'} from ${participantEmail}`)
      
      const isScreenSharer = screenSharingParticipants.value.has(participantEmail)
      const existingStream = remoteStreams.value.get(participantEmail)
      const existingVideoTrackCount = existingStream ? existingStream.getVideoTracks().length : 0
      
      // Determine if this is a screen share track using multiple heuristics.
      // Critically, the outer condition does NOT require isScreenSharer — the signaling flag
      // may arrive after ontrack on fast networks. Structural detection runs first; the flag
      // is kept as a final fallback only.
      // 1. Known stream ID: if CALL_SCREEN_SHARE_START included the sender's screen stream ID,
      //    we can match it directly against the event's stream ID (most reliable)
      // 2. Stream identity: screen share arrives on a DIFFERENT MediaStream than camera/audio
      //    (because addTrack(screenTrack, screenMediaStream) uses a separate stream)
      // 3. Track count: if we already have a camera video track, a second video must be screen
      // 4. Label heuristics: some browsers label screen tracks with 'screen', 'window', etc.
      // 5. isScreenSharer flag as last resort (may arrive late via signaling)
      const eventStreamId = event.streams?.[0]?.id
      const existingStreamId = existingStream?.id
      const knownScreenStreamId = screenShareStreamIds.value.get(participantEmail)
      
      const matchesKnownScreenStream = eventStreamId && knownScreenStreamId && eventStreamId === knownScreenStreamId
      const isDifferentStream = eventStreamId && existingStreamId && eventStreamId !== existingStreamId
      const trackLabel = event.track.label.toLowerCase()
      
      const isScreenTrack = event.track.kind === 'video' && (
        matchesKnownScreenStream ||
        isDifferentStream ||
        existingVideoTrackCount > 0 ||
        trackLabel.includes('screen') ||
        trackLabel.includes('web-contents') ||
        trackLabel.includes('window') ||
        trackLabel.includes('monitor') ||
        trackLabel.includes('display') ||
        isScreenSharer
      )
      
      if (isScreenTrack) {
        // This is a screen share track - put in separate screen stream
        let screenStream
        if (event.streams && event.streams.length > 0) {
          // Use the browser-provided stream for the screen track
          screenStream = event.streams[0]
        } else {
          screenStream = remoteScreenStreams.value.get(participantEmail)
          if (!screenStream) {
            screenStream = new MediaStream()
          }
          if (!screenStream.getTrackById(event.track.id)) {
            screenStream.addTrack(event.track)
          }
        }
        remoteScreenStreams.value.set(participantEmail, screenStream)
        remoteScreenStreams.value = new Map(remoteScreenStreams.value)
        
        isDebugEnabled() && console.log(`[WebRTC] Screen share stream for ${participantEmail}: video=${screenStream.getVideoTracks().length}`)
        
        // CRITICAL: Notify the store so it can sync reactive refs.
        // Without this, the store's _remoteScreenStreamsRef is never updated
        // and the UI never renders the remote screen share video.
        if (onRemoteTrack) {
          onRemoteTrack(participantEmail, event.track, screenStream)
        }
        
        // When screen track ends, remove from screen streams
        event.track.onended = () => {
          remoteScreenStreams.value.delete(participantEmail)
          remoteScreenStreams.value = new Map(remoteScreenStreams.value)
          // Also notify store to sync the removal
          if (onRemoteTrack) {
            onRemoteTrack(participantEmail, event.track, null)
          }
        }
      } else {
        // Normal camera/audio track
        let stream
        if (event.streams && event.streams.length > 0) {
          stream = event.streams[0]
          remoteStreams.value.set(participantEmail, stream)
        } else {
          stream = remoteStreams.value.get(participantEmail)
          if (!stream) {
            stream = new MediaStream()
            remoteStreams.value.set(participantEmail, stream)
          }
          if (!stream.getTrackById(event.track.id)) {
            stream.addTrack(event.track)
          }
        }
        
        isDebugEnabled() && console.log(`[WebRTC] Remote stream for ${participantEmail}: audio=${stream.getAudioTracks().length}, video=${stream.getVideoTracks().length}`)
        
        // Trigger reactive update (new Map reference so Vue picks up changes)
        remoteStreams.value = new Map(remoteStreams.value)
        
        if (onRemoteTrack) {
          onRemoteTrack(participantEmail, event.track, stream)
        }
      }
    }
    
    // Connection state monitoring
    pc.onconnectionstatechange = () => {
      if (peerConnections.value.get(participantEmail) !== pc) return
      const state = pc.connectionState
      
      // Track per-peer connection state
      peerConnectionStates.value.set(participantEmail, state)
      peerConnectionStates.value = new Map(peerConnectionStates.value)
      
      // Aggregate: overall state is 'connected' only if ALL peers are connected
      // Otherwise reflect the worst state (failed > connecting > new)
      const allStates = Array.from(peerConnectionStates.value.values())
      if (allStates.every(s => s === 'connected')) {
        connectionState.value = 'connected'
      } else if (allStates.some(s => s === 'failed')) {
        connectionState.value = 'failed'
      } else if (allStates.some(s => s === 'connecting')) {
        connectionState.value = 'connecting'
      } else {
        connectionState.value = state
      }
      
      isDebugEnabled() && console.log(`[WebRTC] Connection state (${participantEmail}): ${state} | aggregate: ${connectionState.value}`)
      
      if (onConnectionStateChange) {
        onConnectionStateChange(participantEmail, state)
      }
      
      // Clean up failed/closed connections (both camera AND screen streams)
      if (state === 'failed' || state === 'closed') {
        remoteStreams.value.delete(participantEmail)
        remoteStreams.value = new Map(remoteStreams.value)
        remoteScreenStreams.value.delete(participantEmail)
        remoteScreenStreams.value = new Map(remoteScreenStreams.value)
        screenSharingParticipants.value.delete(participantEmail)
        screenShareStreamIds.value.delete(participantEmail)
      }
    }
    
    pc.oniceconnectionstatechange = () => {
      if (peerConnections.value.get(participantEmail) !== pc) return
      isDebugEnabled() && console.log(`[WebRTC] ICE state (${participantEmail}): ${pc.iceConnectionState}`)
    }
    
    pc.onnegotiationneeded = async () => {
      if (peerConnections.value.get(participantEmail) !== pc) return
      if (negotiationLocks.get(participantEmail)) return
      if (pc.signalingState !== 'stable') return

      negotiationLocks.set(participantEmail, true)
      try {
        if (onNegotiationNeeded) {
          await onNegotiationNeeded(participantEmail, pc)
        }
      } finally {
        negotiationLocks.set(participantEmail, false)
      }
    }
    
    peerConnections.value.set(participantEmail, pc)
    return pc
  }
  
  /**
   * Add local media tracks to a peer connection
   */
  function addLocalTracks(participantEmail, stream) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc || !stream) return
    
    stream.getTracks().forEach(track => {
      // Check if track is already added
      const senders = pc.getSenders()
      const existingSender = senders.find(s => s.track === track)
      if (!existingSender) {
        pc.addTrack(track, stream)
      }
    })
  }
  
  /**
   * Replace a track (e.g., when switching camera or starting screen share)
   * Returns true if a sender was found and replaced, false otherwise.
   */
  async function replaceTrack(participantEmail, oldTrack, newTrack) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc) return false
    
    // Try exact match first, then match by kind
    let sender = pc.getSenders().find(s => s.track === oldTrack)
    if (!sender && newTrack) {
      sender = pc.getSenders().find(s => s.track?.kind === newTrack.kind)
    }
    // Also try senders with null track (previously replaced with null)
    if (!sender && newTrack) {
      sender = pc.getSenders().find(s => !s.track && s._wasCameraTrack && newTrack.kind === 'video')
    }
    
    if (sender) {
      await sender.replaceTrack(newTrack)
      return true
    }
    return false
  }
  
  /**
   * Create an SDP offer (for the caller)
   */
  async function createOffer(participantEmail) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc) return null
    
    const offer = await pc.createOffer({
      offerToReceiveAudio: true,
      offerToReceiveVideo: true
    })
    
    await pc.setLocalDescription(offer)
    return pc.localDescription
  }
  
  /**
   * Create an SDP answer (for the callee)
   */
  async function createAnswer(participantEmail) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc) return null
    
    const answer = await pc.createAnswer()
    await pc.setLocalDescription(answer)
    return pc.localDescription
  }
  
  /**
   * Set remote SDP description.
   * Includes glare protection (drops incoming offers mid-negotiation) and
   * flushes any ICE candidates that arrived before the remote SDP was ready.
   */
  async function setRemoteDescription(participantEmail, sdp) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc) return

    // Glare protection: if both sides created an offer simultaneously, ignore
    // the incoming offer while we are mid-negotiation. Answers can always be applied.
    if (sdp.type === 'offer' && pc.signalingState !== 'stable') {
      isDebugEnabled() && console.log(`[WebRTC] Glare: ignoring offer from ${participantEmail} (signalingState=${pc.signalingState})`)
      return
    }
    
    await pc.setRemoteDescription(new RTCSessionDescription(sdp))

    // Flush ICE candidates that arrived before this remote description
    const queue = pendingIceCandidates.get(participantEmail)
    if (queue?.length) {
      isDebugEnabled() && console.log(`[WebRTC] Flushing ${queue.length} queued ICE candidates for ${participantEmail}`)
      for (const c of queue) {
        await pc.addIceCandidate(new RTCIceCandidate(c))
      }
      pendingIceCandidates.delete(participantEmail)
    }
  }
  
  /**
   * Add an ICE candidate from a remote peer.
   * Queues the candidate if the remote description is not yet set, then flushes
   * the queue automatically inside setRemoteDescription.
   */
  async function addIceCandidate(participantEmail, candidate) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc) return // silently drop — connection may have been closed

    if (!pc.remoteDescription) {
      if (!pendingIceCandidates.has(participantEmail)) {
        pendingIceCandidates.set(participantEmail, [])
      }
      pendingIceCandidates.get(participantEmail).push(candidate)
      isDebugEnabled() && console.log(`[WebRTC] Queued ICE candidate for ${participantEmail} (no remote SDP yet)`)
      return
    }

    await pc.addIceCandidate(new RTCIceCandidate(candidate))
  }
  
  /**
   * Close a specific peer connection
   */
  function closePeerConnection(participantEmail) {
    const pc = peerConnections.value.get(participantEmail)
    if (pc) {
      pc.close()
      peerConnections.value.delete(participantEmail)
    }
    remoteStreams.value.delete(participantEmail)
    remoteStreams.value = new Map(remoteStreams.value)
    remoteScreenStreams.value.delete(participantEmail)
    remoteScreenStreams.value = new Map(remoteScreenStreams.value)
    screenSharingParticipants.value.delete(participantEmail)
    screenShareStreamIds.value.delete(participantEmail)
    peerConnectionStates.value.delete(participantEmail)
    peerConnectionStates.value = new Map(peerConnectionStates.value)
    pendingIceCandidates.delete(participantEmail)
    negotiationLocks.delete(participantEmail)
  }
  
  /**
   * Close all peer connections
   */
  function closeAll() {
    for (const [, pc] of peerConnections.value) {
      pc.close()
    }
    // Assign new references so Vue reactive watchers detect the change
    peerConnections.value      = new Map()
    remoteStreams.value        = new Map()
    remoteScreenStreams.value  = new Map()
    peerConnectionStates.value = new Map()
    screenSharingParticipants.value = new Set()
    screenShareStreamIds.value = new Map()
    // Internal-only Maps are not reactive, .clear() is fine
    pendingIceCandidates.clear()
    negotiationLocks.clear()
    connectionState.value = 'closed'
  }
  
  /**
   * Mark a participant as screen sharing (called from call store on CALL_SCREEN_SHARE_START)
   * @param {string} participantEmail
   * @param {boolean} isSharing
   * @param {string} [screenStreamId] - The sender's screen MediaStream ID for reliable track identification
   */
  function setScreenSharing(participantEmail, isSharing, screenStreamId) {
    if (isSharing) {
      screenSharingParticipants.value.add(participantEmail)
      if (screenStreamId) {
        screenShareStreamIds.value.set(participantEmail, screenStreamId)
      }
      
      // Since the sender uses replaceTrack (camera->screen), the screen content
      // arrives on the existing camera stream and ontrack does NOT fire.
      // Copy the camera stream reference to remoteScreenStreams so the
      // presentation layout can render it immediately. If ontrack later fires
      // (addTrack case on voice calls), it will overwrite with the real stream.
      const cameraStream = remoteStreams.value.get(participantEmail)
      if (cameraStream) {
        remoteScreenStreams.value.set(participantEmail, cameraStream)
        remoteScreenStreams.value = new Map(remoteScreenStreams.value)
      }
      
      isDebugEnabled() && console.log(`[WebRTC] Marked ${participantEmail} as screen sharing (streamId: ${screenStreamId || 'unknown'})`)
    } else {
      screenSharingParticipants.value.delete(participantEmail)
      screenShareStreamIds.value.delete(participantEmail)
      // Clean up screen stream when they stop sharing
      remoteScreenStreams.value.delete(participantEmail)
      remoteScreenStreams.value = new Map(remoteScreenStreams.value)
    }
  }
  
  /**
   * Set event callbacks
   */
  function setCallbacks({
    onRemoteTrackCb = null,
    onIceCandidateCb = null,
    onConnectionStateChangeCb = null,
    onNegotiationNeededCb = null
  } = {}) {
    onRemoteTrack = onRemoteTrackCb
    onIceCandidate = onIceCandidateCb
    onConnectionStateChange = onConnectionStateChangeCb
    onNegotiationNeeded = onNegotiationNeededCb
  }
  
  /**
   * Attempt ICE restart for a failed peer connection.
   * Returns the new SDP offer if successful, null otherwise.
   * This avoids tearing down the entire connection on transient network issues.
   */
  async function restartIce(participantEmail) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc) return null
    
    // Only restart if connection is in a failed/disconnected state
    if (pc.connectionState !== 'failed' && pc.iceConnectionState !== 'failed' &&
        pc.iceConnectionState !== 'disconnected') {
      return null
    }

    // Cannot create a new offer while mid-negotiation — restart would fail silently
    if (pc.signalingState !== 'stable') {
      isDebugEnabled() && console.log(`[WebRTC] ICE restart deferred for ${participantEmail} (signalingState=${pc.signalingState})`)
      return null
    }
    
    isDebugEnabled() && console.log(`[WebRTC] Attempting ICE restart for ${participantEmail}`)
    
    try {
      const offer = await pc.createOffer({ iceRestart: true })
      await pc.setLocalDescription(offer)
      return pc.localDescription
    } catch (e) {
      console.error(`[WebRTC] ICE restart failed for ${participantEmail}:`, e)
      return null
    }
  }
  
  /**
   * Get connection stats for a peer
   */
  async function getStats(participantEmail) {
    const pc = peerConnections.value.get(participantEmail)
    if (!pc) return null
    
    const stats = await pc.getStats()
    const result = { audio: {}, video: {} }
    
    stats.forEach(report => {
      if (report.type === 'inbound-rtp') {
        const kind = report.kind
        if (kind === 'audio' || kind === 'video') {
          result[kind] = {
            bytesReceived: report.bytesReceived,
            packetsReceived: report.packetsReceived,
            packetsLost: report.packetsLost,
            jitter: report.jitter
          }
        }
      }
    })
    
    return result
  }
  
  onUnmounted(() => {
    closeAll()
  })
  
  return {
    // State
    peerConnections,
    remoteStreams,
    remoteScreenStreams,
    screenSharingParticipants,
    iceServers,
    connectionState,
    peerConnectionStates,
    
    // Methods
    fetchIceServers,
    createPeerConnection,
    addLocalTracks,
    replaceTrack,
    createOffer,
    createAnswer,
    setRemoteDescription,
    addIceCandidate,
    closePeerConnection,
    closeAll,
    setCallbacks,
    setScreenSharing,
    restartIce,
    getStats
  }
}

