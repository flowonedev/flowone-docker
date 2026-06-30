<script setup>
/**
 * VideoCallRoom - Reusable LiveKit video call room component.
 *
 * Full-featured call room: video grid, screen sharing, chat, PiP self-view,
 * participants sidebar, admin mute controls, emoji, transcript saving.
 *
 * Used by GuestCallView (guest token auth) and PortalCallRoom (portal session auth).
 *
 * Props:
 *  - livekitToken: LiveKit JWT token (required)
 *  - wsUrl: LiveKit WebSocket URL (required)
 *  - participantName: Display name (pre-filled, editable in pre-join)
 *  - isAdmin: Enable admin controls (mute all, force-mute)
 *  - showPreJoin: Show camera preview + name input before joining
 *  - transcriptUrl: POST endpoint for saving chat transcript on call end
 *
 * Emits:
 *  - ended: Call ended or participant left
 */
import { ref, onBeforeUnmount, computed, nextTick, watch, defineProps, defineEmits } from 'vue'
import { Room, RoomEvent, Track, DisconnectReason } from 'livekit-client'
import { useDevicePreferences } from '@/composables/useDevicePreferences'
import PreJoinPanel from '@/components/call/PreJoinPanel.vue'
import CallChatPanel from '@/components/call/CallChatPanel.vue'
import VideoCallControls from '@/components/call/VideoCallControls.vue'
import CallSecurityBadge from '@/components/call/CallSecurityBadge.vue'

const props = defineProps({
  livekitToken: { type: String, required: true },
  wsUrl: { type: String, required: true },
  participantName: { type: String, default: '' },
  isAdmin: { type: Boolean, default: false },
  showPreJoin: { type: Boolean, default: true },
  transcriptUrl: { type: String, default: '' },
  workshopMode: { type: Boolean, default: false },
  roomKey: { type: String, default: '' },
  kickUrl: { type: String, default: '' },
  /** Public API base, e.g. https://flowone.pro/api -- used for admission endpoints. */
  apiBase: { type: String, default: '' },
  /** Admin token used to authorize admission/lobby actions when isAdmin=true. */
  adminToken: { type: String, default: '' },
  /**
   * Base URL for in-call chat attachments, e.g.
   * `https://flowone.pro/api/guest/call/<token>/attachments`.
   * Each participant passes their OWN token; chat messages carry only the
   * attachment id. Empty string hides the attach button (portal rooms).
   */
  attachmentsBaseUrl: { type: String, default: '' },
  /**
   * Optional async function that returns fresh LiveKit credentials.
   * Used on rejoin / auto-retry after a Disconnected(failure) event so
   * we don't reuse a potentially expired access token.
   *
   * Should resolve to `{ livekitToken: string, wsUrl: string, isAdmin?: boolean }`.
   * If it throws or returns null, the cached props.livekitToken is used.
   */
  reconnectFn: { type: Function, default: null },
})

const emit = defineEmits(['ended', 'kicked'])

// ------ Resilience state ------
const connectionState = ref('idle') // idle | connecting | connected | reconnecting | disconnected
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const kickedReason = ref('')
const dupTabConflict = ref(false)
let wakeLockSentinel = null
let dupTabChannel = null
const dupTabId = Math.random().toString(36).slice(2)
let dupTabHeartbeat = null
const showResumePrompt = ref(false)

// ------ In-call admission state (admins only) ------
const pendingAdmissions = ref([]) // [{ id, guest_name, requested_at }]
/** Toggle for the right-side waiting-room sidebar (admins only). */
const showWaitingRoom = ref(false)
let admissionLobbyTimer = null
const admissionActing = ref({}) // { [id]: 'approving' | 'denying' }

const status = ref(props.showPreJoin ? 'prejoin' : 'connecting')
const errorMessage = ref('')
const displayName = ref(props.participantName || '')

const room = ref(null)
const remoteParticipants = ref([])
/** LiveKit identities currently speaking (includes the local participant). */
const speakingIdentities = ref(new Set())
const isMicMuted = ref(false)
const isCamOff = ref(false)
const isScreenSharing = ref(false)
const callDuration = ref(0)
let durationInterval = null
let rebuildTimer = null

const activeScreenShare = ref(null)

const showSidebar = ref(false)
const pinnedIdentity = ref(null)
const usingFrontCamera = ref(true)

const forceMuted = ref(false)
const allMuted = ref(false)
const toastMessage = ref('')
let toastTimer = null

const chatMessages = ref([])
const showChat = ref(false)
const unreadCount = ref(0)

const useSpeaker = ref(true)

const selfViewEl = ref(null)
let selfViewAttachedTrack = null
const pipPosition = ref({ corner: 'bottom-right' })
const pipDragging = ref(false)
let pipDragStart = null
let pipElStart = null

const attachedTracks = new WeakMap()

// ── Device selection (pre-join + in-call) ──────────────────────────────
const devicePrefs = useDevicePreferences()
const selectedAudioInputId = ref(devicePrefs.audioInputId.value)
const selectedVideoInputId = ref(devicePrefs.videoInputId.value)
const selectedAudioOutputId = ref(devicePrefs.audioOutputId.value)

const isMobile = computed(() => /Android|iPhone|iPad|iPod/i.test(navigator.userAgent))
const isWorkshopGuest = computed(() => props.workshopMode && !props.isAdmin)

const formattedDuration = computed(() => {
  const h = Math.floor(callDuration.value / 3600)
  const m = Math.floor((callDuration.value % 3600) / 60).toString().padStart(2, '0')
  const s = (callDuration.value % 60).toString().padStart(2, '0')
  return h > 0 ? `${h}:${m}:${s}` : `${m}:${s}`
})

const canScreenShare = computed(() => {
  return typeof navigator !== 'undefined'
    && !!navigator.mediaDevices?.getDisplayMedia
    && (typeof window === 'undefined' || window.isSecureContext)
})

const allParticipants = computed(() => {
  const local = {
    identity: room.value?.localParticipant?.identity || 'you',
    name: room.value?.localParticipant?.name || displayName.value || 'You',
    isLocal: true,
    hasVideo: !isCamOff.value,
    hasAudio: !isMicMuted.value,
    isScreenSharing: isScreenSharing.value,
  }
  const remote = remoteParticipants.value.map(p => ({
    ...p,
    isLocal: false,
  }))
  return [local, ...remote]
})

const participantCount = computed(() => allParticipants.value.length)
const isPresentationMode = computed(() => !!activeScreenShare.value || !!pinnedIdentity.value || isWorkshopGuest.value)
const workshopSpeaker = computed(() => {
  if (!isWorkshopGuest.value) return null
  // Pick the first remote participant who has video, otherwise the first remote.
  return remoteParticipants.value.find(p => p.hasVideo) || remoteParticipants.value[0] || null
})

const pinnedParticipant = computed(() => {
  if (!pinnedIdentity.value) return null
  return remoteParticipants.value.find(p => p.identity === pinnedIdentity.value) || null
})

// ------ Speaking indicator (Teams-style ring around the active speaker) ------

function isSpeaking(identity) {
  return speakingIdentities.value.has(identity)
}

const isLocalSpeaking = computed(() => {
  const id = room.value?.localParticipant?.identity
  return !!id && speakingIdentities.value.has(id)
})

const unpinnedParticipants = computed(() => {
  if (!pinnedIdentity.value) return remoteParticipants.value
  return remoteParticipants.value.filter(p => p.identity !== pinnedIdentity.value)
})

// Auto-start if no pre-join (with pre-join, PreJoinPanel owns the camera preview)
if (!props.showPreJoin) {
  connectToRoom(props.livekitToken, props.wsUrl)
}

onBeforeUnmount(() => {
  if (room.value) room.value.disconnect()
  if (durationInterval) clearInterval(durationInterval)
  if (rebuildTimer) clearTimeout(rebuildTimer)
  releaseWakeLock()
  teardownLifecycleListeners()
  teardownDupTabChannel()
  stopAdmissionLobbyPolling()
})

// In-call device switchers — apply the change live and persist the choice.
async function handleInCallAudioInputChange(deviceId) {
  selectedAudioInputId.value = deviceId
  devicePrefs.setAudioInput(deviceId)
  if (room.value) {
    try { await room.value.switchActiveDevice('audioinput', deviceId) }
    catch (e) { showToast('Could not switch microphone'); console.warn('[VideoCallRoom] switchActiveDevice audioinput failed:', e) }
  }
}

async function handleInCallVideoInputChange(deviceId) {
  selectedVideoInputId.value = deviceId
  devicePrefs.setVideoInput(deviceId)
  if (room.value) {
    try {
      await room.value.switchActiveDevice('videoinput', deviceId)
      isCamOff.value = false
      await nextTick()
      setTimeout(attachSelfView, 200)
    } catch (e) { showToast('Could not switch camera'); console.warn('[VideoCallRoom] switchActiveDevice videoinput failed:', e) }
  }
}

async function handleInCallAudioOutputChange(deviceId) {
  selectedAudioOutputId.value = deviceId
  devicePrefs.setAudioOutput(deviceId)
  if (room.value) {
    try { await room.value.switchActiveDevice('audiooutput', deviceId) }
    catch (e) { console.warn('[VideoCallRoom] switchActiveDevice audiooutput failed:', e) }
  }
}

// ------ Join call ------

function joinFromPreJoin() {
  // Pick up any device changes made in the PreJoinPanel (shared singleton prefs)
  selectedAudioInputId.value = devicePrefs.audioInputId.value
  selectedVideoInputId.value = devicePrefs.videoInputId.value
  selectedAudioOutputId.value = devicePrefs.audioOutputId.value
  status.value = 'connecting'
  connectToRoom(props.livekitToken, props.wsUrl)
}

// ------ LiveKit room ------

async function connectToRoom(livekitToken, wsUrl) {
  const lkRoom = new Room({ adaptiveStream: false, dynacast: true })
  room.value = lkRoom

  // Publish a handle for the Phase C2 chaos suite. No-op in production builds
  // unless the suite has already installed its hook script; assigning the value
  // triggers the test hook to wire LiveKit event listeners.
  try { if (typeof window !== 'undefined') window.flowoneCallRoom = lkRoom } catch (_) {}

  lkRoom.on(RoomEvent.TrackSubscribed, handleTrackSubscribed)
  lkRoom.on(RoomEvent.TrackUnsubscribed, handleTrackUnsubscribed)
  lkRoom.on(RoomEvent.ParticipantConnected, (participant) => {
    rebuildParticipants()
    sendChatHistoryTo(participant)
  })
  lkRoom.on(RoomEvent.ParticipantDisconnected, (participant) => {
    if (activeScreenShare.value?.identity === participant.identity) {
      activeScreenShare.value = null
    }
    if (pinnedIdentity.value === participant.identity) {
      pinnedIdentity.value = null
    }
    rebuildParticipants()
  })
  lkRoom.on(RoomEvent.Reconnecting, () => {
    connectionState.value = 'reconnecting'
    showToast('Reconnecting…')
  })
  lkRoom.on(RoomEvent.Reconnected, () => {
    connectionState.value = 'connected'
    showToast('Reconnected')
  })
  lkRoom.on(RoomEvent.SignalConnected, () => {
    connectionState.value = 'connected'
  })
  lkRoom.on(RoomEvent.Disconnected, (reason) => {
    connectionState.value = 'disconnected'
    speakingIdentities.value = new Set()
    releaseWakeLock()
    const r = reason
    const kicked = r === DisconnectReason?.PARTICIPANT_REMOVED
      || r === 'PARTICIPANT_REMOVED'
      || r === 4 /* numeric enum fallback */
    if (kicked) {
      kickedReason.value = 'You were removed from the call by the host.'
      status.value = 'kicked'
      if (durationInterval) clearInterval(durationInterval)
      // Only emit 'kicked' — emitting 'ended' here would let parent
      // components flip status back to 'ended', hiding the kicked screen.
      emit('kicked')
      return
    }
    if (props.isAdmin) sendTranscriptToBackend()
    status.value = 'ended'
    if (durationInterval) clearInterval(durationInterval)
    emit('ended')
  })
  lkRoom.on(RoomEvent.TrackMuted, () => rebuildParticipants())
  lkRoom.on(RoomEvent.TrackUnmuted, () => rebuildParticipants())
  lkRoom.on(RoomEvent.ActiveSpeakersChanged, (speakers) => {
    speakingIdentities.value = new Set(speakers.map(s => s.identity))
  })
  lkRoom.on(RoomEvent.DataReceived, handleDataReceived)

  try {
    await lkRoom.connect(wsUrl, livekitToken)
    await new Promise(r => setTimeout(r, 300))

    if (isWorkshopGuest.value) {
      isCamOff.value = true
      isMicMuted.value = true
    } else {
      const camOpts = selectedVideoInputId.value ? { deviceId: selectedVideoInputId.value } : undefined
      const micOpts = selectedAudioInputId.value ? { deviceId: selectedAudioInputId.value } : undefined
      try { await lkRoom.localParticipant.setCameraEnabled(true, camOpts) }
      catch { isCamOff.value = true }

      try { await lkRoom.localParticipant.setMicrophoneEnabled(true, micOpts) }
      catch (e) {
        console.warn('Mic enable failed, retrying...', e)
        await new Promise(r => setTimeout(r, 500))
        try { await lkRoom.localParticipant.setMicrophoneEnabled(true, micOpts) }
        catch { isMicMuted.value = true }
      }

      // Apply preferred audio output (speaker) if browser supports it
      if (selectedAudioOutputId.value) {
        try { await lkRoom.switchActiveDevice('audiooutput', selectedAudioOutputId.value) }
        catch (_) { /* ignore */ }
      }
    }

    status.value = 'active'
    callDuration.value = 0
    durationInterval = setInterval(() => callDuration.value++, 1000)
    rebuildParticipants()
    setTimeout(() => doRebuildParticipants(), 1500)
    setTimeout(() => doRebuildParticipants(), 4000)
    await nextTick()
    setTimeout(attachSelfView, 300)
    connectionState.value = 'connected'
    requestWakeLock()
    setupLifecycleListeners()
    setupDupTabChannel()
    startAdmissionLobbyPolling()
  } catch (err) {
    status.value = 'error'
    errorMessage.value = 'Failed to connect: ' + (err.message || 'Unknown error')
  }
}

// ------ Resilience helpers ------

async function requestWakeLock() {
  if (typeof navigator === 'undefined' || !('wakeLock' in navigator)) return
  try {
    wakeLockSentinel = await navigator.wakeLock.request('screen')
    wakeLockSentinel.addEventListener('release', () => { wakeLockSentinel = null })
  } catch { /* permission denied or unsupported */ }
}

function releaseWakeLock() {
  try { wakeLockSentinel?.release?.() } catch { /* ignore */ }
  wakeLockSentinel = null
}

function handleVisibility() {
  if (document.visibilityState === 'visible') {
    if (!wakeLockSentinel) requestWakeLock()
    if (isIOSSafari() && room.value?.localParticipant) {
      showResumePrompt.value = true
    }
  } else {
    if (isIOSSafari()) {
      try { room.value?.localParticipant?.setCameraEnabled(false) } catch { /* ignore */ }
    }
  }
}

function isIOSSafari() {
  if (typeof navigator === 'undefined') return false
  const ua = navigator.userAgent
  return /iP(hone|od|ad)/.test(ua) && /WebKit/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua)
}

async function resumeAfterBackground() {
  showResumePrompt.value = false
  if (!room.value?.localParticipant) return
  try { await room.value.localParticipant.setCameraEnabled(!isCamOff.value) } catch { /* ignore */ }
  try { await room.value.localParticipant.setMicrophoneEnabled(!isMicMuted.value) } catch { /* ignore */ }
}

function handleOnline() {
  isOnline.value = true
  showToast('Back online — reconnecting…')
}

function handleOffline() {
  isOnline.value = false
  connectionState.value = 'reconnecting'
  showToast('You are offline')
}

function setupLifecycleListeners() {
  if (typeof window === 'undefined') return
  document.addEventListener('visibilitychange', handleVisibility)
  window.addEventListener('online', handleOnline)
  window.addEventListener('offline', handleOffline)
}

function teardownLifecycleListeners() {
  if (typeof window === 'undefined') return
  document.removeEventListener('visibilitychange', handleVisibility)
  window.removeEventListener('online', handleOnline)
  window.removeEventListener('offline', handleOffline)
}

function setupDupTabChannel() {
  const key = props.roomKey || props.livekitToken
  if (!key || typeof BroadcastChannel === 'undefined') return
  try {
    dupTabChannel = new BroadcastChannel(`flowone-call-${key.slice(0, 24)}`)
    dupTabChannel.onmessage = (e) => {
      const msg = e?.data
      if (!msg || msg.id === dupTabId) return
      if (msg.type === 'hello') {
        dupTabChannel.postMessage({ type: 'already-here', id: dupTabId })
      } else if (msg.type === 'already-here') {
        dupTabConflict.value = true
      }
    }
    dupTabChannel.postMessage({ type: 'hello', id: dupTabId })
    dupTabHeartbeat = setInterval(() => {
      try { dupTabChannel?.postMessage({ type: 'heartbeat', id: dupTabId, ts: Date.now() }) } catch { /* ignore */ }
    }, 5000)
  } catch { /* ignore */ }
}

function teardownDupTabChannel() {
  try { dupTabChannel?.close?.() } catch { /* ignore */ }
  dupTabChannel = null
  if (dupTabHeartbeat) {
    clearInterval(dupTabHeartbeat)
    dupTabHeartbeat = null
  }
}

function dismissDupTabWarning() {
  dupTabConflict.value = false
}

// ------ In-call admission helpers (admins only) ------

function pushAdmissionRequest(req) {
  if (!req?.id) return
  const i = pendingAdmissions.value.findIndex(r => r.id === req.id)
  if (i >= 0) {
    pendingAdmissions.value.splice(i, 1, { ...pendingAdmissions.value[i], ...req })
  } else {
    pendingAdmissions.value.push(req)
  }
}

function removeAdmissionRequest(id) {
  pendingAdmissions.value = pendingAdmissions.value.filter(r => r.id !== id)
  delete admissionActing.value[id]
}

function admissionEnabled() {
  return props.isAdmin && !!props.apiBase && !!props.adminToken
}

async function refreshAdmissionLobby() {
  if (!admissionEnabled()) return
  try {
    const url = `${props.apiBase}/guest/call/lobby?admin_token=${encodeURIComponent(props.adminToken)}`
    const res = await fetch(url)
    const data = await res.json()
    if (!res.ok || !data.success) return
    const rows = Array.isArray(data.data) ? data.data : []
    pendingAdmissions.value = rows.map(r => ({
      id: r.id,
      guest_name: r.guest_name || 'Guest',
      requested_at: r.requested_at || '',
    }))
  } catch { /* ignore */ }
}

function startAdmissionLobbyPolling() {
  if (!admissionEnabled()) return
  refreshAdmissionLobby()
  if (admissionLobbyTimer) clearInterval(admissionLobbyTimer)
  admissionLobbyTimer = setInterval(refreshAdmissionLobby, 6000)
}

function stopAdmissionLobbyPolling() {
  if (admissionLobbyTimer) clearInterval(admissionLobbyTimer)
  admissionLobbyTimer = null
}

async function admitAdmission(id) {
  if (!admissionEnabled() || !id) return
  admissionActing.value = { ...admissionActing.value, [id]: 'approving' }
  try {
    const res = await fetch(`${props.apiBase}/guest/call/admission/${id}/approve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_token: props.adminToken }),
    })
    const data = await res.json()
    if (res.ok && data.success) {
      removeAdmissionRequest(id)
      showToast('Guest admitted')
    } else {
      showToast(data.error || 'Failed to admit')
      delete admissionActing.value[id]
    }
  } catch {
    showToast('Failed to admit')
    delete admissionActing.value[id]
  }
}

async function denyAdmission(id) {
  if (!admissionEnabled() || !id) return
  admissionActing.value = { ...admissionActing.value, [id]: 'denying' }
  try {
    const res = await fetch(`${props.apiBase}/guest/call/admission/${id}/deny`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_token: props.adminToken }),
    })
    const data = await res.json()
    if (res.ok && data.success) {
      removeAdmissionRequest(id)
      showToast('Guest declined')
    } else {
      showToast(data.error || 'Failed to deny')
      delete admissionActing.value[id]
    }
  } catch {
    showToast('Failed to deny')
    delete admissionActing.value[id]
  }
}

function handleTrackSubscribed(track, publication, participant) {
  if (track.source === Track.Source.ScreenShare && track.kind === 'video') {
    activeScreenShare.value = {
      identity: participant.identity,
      name: participant.name || participant.identity,
      track
    }
  }
  rebuildParticipants()
}

function handleTrackUnsubscribed(track, publication, participant) {
  if (track.source === Track.Source.ScreenShare) {
    if (activeScreenShare.value?.identity === participant.identity) {
      activeScreenShare.value = null
    }
  }
  rebuildParticipants()
}

function rebuildParticipants() {
  if (rebuildTimer) clearTimeout(rebuildTimer)
  rebuildTimer = setTimeout(() => {
    rebuildTimer = null
    doRebuildParticipants()
  }, 100)
}

function doRebuildParticipants() {
  if (!room.value) return
  const list = []
  room.value.remoteParticipants.forEach((p) => {
    const camPub = Array.from(p.trackPublications.values()).find(
      t => t.track && t.source === Track.Source.Camera && t.track.kind === 'video'
    )
    const audioPub = Array.from(p.trackPublications.values()).find(
      t => t.track && t.source === Track.Source.Microphone
    )
    const screenPub = Array.from(p.trackPublications.values()).find(
      t => t.track && t.source === Track.Source.ScreenShare && t.track.kind === 'video'
    )
    list.push({
      identity: p.identity,
      name: p.name || p.identity,
      videoTrack: camPub?.track || null,
      audioTrack: audioPub?.track || null,
      hasVideo: !!(camPub?.track && !camPub.isMuted),
      hasAudio: !!(audioPub?.track && !audioPub.isMuted),
      isScreenSharing: !!screenPub?.track,
    })
  })
  remoteParticipants.value = list
}

// ------ Data channel ------

function handleDataReceived(payload, participant) {
  try {
    const text = new TextDecoder().decode(payload)
    const msg = JSON.parse(text)

    if (msg.type === 'chat') {
      chatMessages.value.push({
        sender: msg.sender,
        identity: msg.identity,
        message: msg.message,
        ts: msg.ts,
        isLocal: false,
        isImage: !!msg.isImage,
        isFile: !!msg.isFile,
        attachmentId: msg.attachmentId || null,
        name: msg.name || '',
        mime: msg.mime || '',
        size: msg.size || 0,
      })
      if (!showChat.value) unreadCount.value++
    }

    if (msg.type === 'chat_sync' && Array.isArray(msg.messages)) {
      const existingKeys = new Set(
        chatMessages.value.map(m => `${m.ts}_${m.identity}`)
      )
      let added = 0
      for (const m of msg.messages) {
        const key = `${m.ts}_${m.identity}`
        if (!existingKeys.has(key)) {
          existingKeys.add(key)
          const myIdentity = room.value?.localParticipant?.identity
          chatMessages.value.push({
            sender: m.sender,
            identity: m.identity,
            message: m.message,
            ts: m.ts,
            isLocal: m.identity === myIdentity,
            isImage: !!m.isImage,
            isFile: !!m.isFile,
            attachmentId: m.attachmentId || null,
            name: m.name || '',
            mime: m.mime || '',
            size: m.size || 0,
          })
          added++
        }
      }
      if (added > 0) {
        chatMessages.value.sort((a, b) => a.ts - b.ts)
        if (!showChat.value) unreadCount.value += added
      }
    }

    if ((msg.kind === 'admission_request' || msg.type === 'admission_request') && props.isAdmin) {
      const who = msg.name || 'Someone'
      pushAdmissionRequest({ id: msg.request_id, guest_name: who, requested_at: new Date().toISOString() })
      // Auto-open the waiting-room sidebar so the host sees the late joiner
      // immediately, even if they were focused on the call.
      openWaitingRoom()
      showToast(`${who} is waiting to join`)
    }

    if ((msg.kind === 'admission_resolved' || msg.type === 'admission_resolved') && props.isAdmin) {
      if (msg.request_id) removeAdmissionRequest(msg.request_id)
    }

    if (msg.type === 'call_ended') {
      if (room.value) room.value.disconnect()
      status.value = 'ended'
      if (durationInterval) clearInterval(durationInterval)
      emit('ended')
      return
    }

    if (msg.type === 'admin_mute' && !props.isAdmin) {
      const myIdentity = room.value?.localParticipant?.identity
      if (msg.target === 'all' || msg.target === myIdentity) {
        forceMuted.value = msg.muted
        if (msg.muted && !isMicMuted.value) {
          isMicMuted.value = true
          room.value?.localParticipant?.setMicrophoneEnabled(false).catch(() => {})
          showToast('The host has muted you')
        }
        if (!msg.muted) {
          showToast('The host has allowed you to unmute')
        }
      }
    }
  } catch { /* ignore malformed data */ }
}

function sendData(obj, destinationIdentities) {
  if (!room.value?.localParticipant) return
  const data = new TextEncoder().encode(JSON.stringify(obj))
  const opts = { reliable: true }
  if (destinationIdentities) opts.destinationIdentities = destinationIdentities
  room.value.localParticipant.publishData(data, opts)
}

function sendChatHistoryTo(participant) {
  if (chatMessages.value.length === 0) return
  setTimeout(() => {
    const history = chatMessages.value.map(m => ({
      sender: m.sender,
      identity: m.identity,
      message: m.message,
      ts: m.ts,
      isImage: !!m.isImage,
      isFile: !!m.isFile,
      attachmentId: m.attachmentId || null,
      name: m.name || '',
      mime: m.mime || '',
      size: m.size || 0,
    }))
    sendData({ type: 'chat_sync', messages: history }, [participant.identity])
  }, 1500)
}

function showToast(msg) {
  toastMessage.value = msg
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toastMessage.value = '' }, 4000)
}

// ------ Chat ------

function localSenderName() {
  return room.value?.localParticipant?.name || displayName.value || 'You'
}

function sendChatMessage(text) {
  if (!text || !room.value) return
  const msg = {
    type: 'chat',
    message: text,
    sender: localSenderName(),
    identity: room.value.localParticipant.identity,
    ts: Date.now(),
  }
  sendData(msg)
  chatMessages.value.push({ ...msg, isLocal: true })
}

/** Legacy fallback: base64 image over the data channel (no upload endpoint). */
function sendChatImage(base64Url) {
  if (!room.value) return
  const msg = {
    type: 'chat',
    message: base64Url,
    sender: localSenderName(),
    identity: room.value.localParticipant.identity,
    ts: Date.now(),
    isImage: true,
  }
  sendData(msg)
  chatMessages.value.push({ ...msg, isLocal: true })
}

/**
 * Broadcast a server-stored attachment ({ id, name, mime, size, is_image })
 * after CallChatPanel uploaded it. Only the id travels over the data channel;
 * each receiver downloads via their own token.
 */
function sendChatAttachment(attachment) {
  if (!room.value || !attachment?.id) return
  const msg = {
    type: 'chat',
    message: '',
    sender: localSenderName(),
    identity: room.value.localParticipant.identity,
    ts: Date.now(),
    isFile: true,
    isImage: !!attachment.is_image,
    attachmentId: attachment.id,
    name: attachment.name || 'Attachment',
    mime: attachment.mime || '',
    size: attachment.size || 0,
  }
  sendData(msg)
  chatMessages.value.push({ ...msg, isLocal: true })
}

function toggleChat() {
  showChat.value = !showChat.value
  if (showChat.value) {
    unreadCount.value = 0
    showSidebar.value = false
    showWaitingRoom.value = false
  }
}

function toggleWaitingRoom() {
  showWaitingRoom.value = !showWaitingRoom.value
  if (showWaitingRoom.value) {
    showSidebar.value = false
    showChat.value = false
    refreshAdmissionLobby()
  }
}

function openWaitingRoom() {
  showSidebar.value = false
  showChat.value = false
  showWaitingRoom.value = true
}

// Right-side panels are mutually exclusive — opening one closes the others.
watch(showSidebar, (v) => {
  if (v) {
    showChat.value = false
    showWaitingRoom.value = false
  }
})
watch(showWaitingRoom, (v) => {
  if (v) {
    showSidebar.value = false
    showChat.value = false
  }
})

watch(workshopSpeaker, (speaker) => {
  if (!isWorkshopGuest.value) return
  if (speaker && pinnedIdentity.value !== speaker.identity) {
    pinnedIdentity.value = speaker.identity
  }
})

// ------ Admin mute controls ------

function muteAll() {
  allMuted.value = true
  sendData({ type: 'admin_mute', target: 'all', muted: true })
  showToast('All participants muted')
}

function unmuteAll() {
  allMuted.value = false
  sendData({ type: 'admin_mute', target: 'all', muted: false })
  showToast('Participants can now unmute')
}

async function requestKick(identity, displayName) {
  if (!props.isAdmin || !props.kickUrl || !identity) return
  if (typeof window !== 'undefined' && !window.confirm(`Remove ${displayName || identity} from the call?`)) return
  try {
    const res = await fetch(props.kickUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ identity }),
    })
    if (res.ok) {
      showToast(`Removed ${displayName || 'participant'}`)
    } else {
      const data = await res.json().catch(() => ({}))
      showToast(data.error || 'Failed to remove participant')
    }
  } catch {
    showToast('Failed to remove participant')
  }
}

// ------ Controls ------

async function toggleMic() {
  if (!room.value) return
  if (forceMuted.value && !props.isAdmin) {
    showToast('The host has muted you')
    return
  }
  try {
    isMicMuted.value = !isMicMuted.value
    await room.value.localParticipant.setMicrophoneEnabled(!isMicMuted.value)
  } catch { isMicMuted.value = true }
}

async function toggleCam() {
  if (!room.value) return
  try {
    isCamOff.value = !isCamOff.value
    await room.value.localParticipant.setCameraEnabled(!isCamOff.value)
  } catch { isCamOff.value = true }
}

async function toggleScreenShare() {
  if (!room.value) return
  try {
    if (isScreenSharing.value) {
      await room.value.localParticipant.setScreenShareEnabled(false)
      isScreenSharing.value = false
      if (activeScreenShare.value?.identity === room.value.localParticipant.identity) {
        activeScreenShare.value = null
      }
    } else {
      await room.value.localParticipant.setScreenShareEnabled(true)
      isScreenSharing.value = true
      const screenPub = Array.from(room.value.localParticipant.trackPublications.values()).find(
        t => t.source === Track.Source.ScreenShare && t.track?.kind === 'video'
      )
      if (screenPub?.track) {
        activeScreenShare.value = {
          identity: room.value.localParticipant.identity,
          name: 'You',
          track: screenPub.track
        }
        screenPub.track.mediaStreamTrack?.addEventListener('ended', () => {
          isScreenSharing.value = false
          if (activeScreenShare.value?.identity === room.value?.localParticipant?.identity) {
            activeScreenShare.value = null
          }
        })
      }
    }
  } catch {
    isScreenSharing.value = false
  }
}

async function flipCamera() {
  if (!room.value) return
  try {
    const devices = await navigator.mediaDevices.enumerateDevices()
    const videoDevices = devices.filter(d => d.kind === 'videoinput')
    if (videoDevices.length < 2) {
      showToast('No other camera available')
      return
    }

    const wantBack = usingFrontCamera.value
    const target = videoDevices.find(d => {
      const label = d.label.toLowerCase()
      return wantBack
        ? (label.includes('back') || label.includes('rear') || label.includes('environment'))
        : (label.includes('front') || label.includes('user') || label.includes('facetime'))
    })

    if (target) {
      await room.value.switchActiveDevice('videoinput', target.deviceId)
    } else {
      const camPub = Array.from(room.value.localParticipant.trackPublications.values())
        .find(t => t.source === Track.Source.Camera)
      const currentId = camPub?.track?.mediaStreamTrack?.getSettings()?.deviceId
      const other = videoDevices.find(d => d.deviceId !== currentId)
      if (other) {
        await room.value.switchActiveDevice('videoinput', other.deviceId)
      }
    }

    usingFrontCamera.value = !usingFrontCamera.value
    isCamOff.value = false
    await nextTick()
    setTimeout(attachSelfView, 300)
  } catch {
    showToast('Could not switch camera')
  }
}

async function toggleSpeaker() {
  useSpeaker.value = !useSpeaker.value
  if (!room.value) return

  const isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent)
  if (isIOS) {
    showToast('Use iOS Control Center to switch audio output')
    return
  }

  try {
    const devices = await navigator.mediaDevices.enumerateDevices()
    const audioOutputs = devices.filter(d => d.kind === 'audiooutput')

    if (audioOutputs.length < 2) {
      showToast(useSpeaker.value ? 'Speaker' : 'Earpiece')
      return
    }

    let speakerDevice = null
    let earpieceDevice = null
    for (const d of audioOutputs) {
      const label = (d.label || '').toLowerCase()
      if (!speakerDevice && (label.includes('speaker') || d.deviceId === 'default')) {
        speakerDevice = d
      }
      if (!earpieceDevice && (label.includes('earpiece') || label.includes('handset') || label.includes('receiver'))) {
        earpieceDevice = d
      }
    }

    if (!speakerDevice) speakerDevice = audioOutputs[0]
    if (!earpieceDevice) earpieceDevice = audioOutputs.find(d => d !== speakerDevice) || audioOutputs[1]

    const targetDev = useSpeaker.value ? speakerDevice : earpieceDevice
    if (!targetDev) {
      showToast(useSpeaker.value ? 'Speaker' : 'Earpiece')
      return
    }

    try {
      await room.value.switchActiveDevice('audiooutput', targetDev.deviceId)
    } catch { /* fallback below */ }

    const container = document.querySelector('[data-call-container]')
    const mediaEls = (container || document).querySelectorAll('audio, video')
    for (const el of mediaEls) {
      if (typeof el.setSinkId === 'function') {
        try { await el.setSinkId(targetDev.deviceId) } catch { /* not all elements support it */ }
      }
    }

    showToast(useSpeaker.value ? 'Speaker' : 'Earpiece')
  } catch {
    showToast(useSpeaker.value ? 'Speaker' : 'Earpiece')
  }
}

function pinParticipant(identity) {
  pinnedIdentity.value = pinnedIdentity.value === identity ? null : identity
}

function onPipPointerDown(e) {
  pipDragging.value = true
  const pip = e.currentTarget
  const rect = pip.getBoundingClientRect()
  pipDragStart = { x: e.clientX || e.touches?.[0]?.clientX, y: e.clientY || e.touches?.[0]?.clientY }
  pipElStart = { left: rect.left, top: rect.top }
  pip.style.transition = 'none'
  pip.setPointerCapture?.(e.pointerId)

  const onMove = (ev) => {
    const cx = ev.clientX || ev.touches?.[0]?.clientX || 0
    const cy = ev.clientY || ev.touches?.[0]?.clientY || 0
    const dx = cx - pipDragStart.x
    const dy = cy - pipDragStart.y
    pip.style.position = 'fixed'
    pip.style.left = (pipElStart.left + dx) + 'px'
    pip.style.top = (pipElStart.top + dy) + 'px'
    pip.style.right = 'auto'
    pip.style.bottom = 'auto'
  }

  const onUp = () => {
    pipDragging.value = false
    pip.style.transition = 'all 0.25s ease'
    const rect2 = pip.getBoundingClientRect()
    const cx = rect2.left + rect2.width / 2
    const cy = rect2.top + rect2.height / 2
    const ww = window.innerWidth
    const wh = window.innerHeight
    const isRight = cx > ww / 2
    const isBottom = cy > wh / 2
    pip.style.position = ''
    pip.style.left = ''
    pip.style.top = ''
    pip.style.right = ''
    pip.style.bottom = ''
    pipPosition.value.corner = (isBottom ? 'bottom' : 'top') + '-' + (isRight ? 'right' : 'left')
    document.removeEventListener('pointermove', onMove)
    document.removeEventListener('pointerup', onUp)
  }

  document.addEventListener('pointermove', onMove)
  document.addEventListener('pointerup', onUp)
}

function leaveCall() {
  if (room.value) {
    if (props.isAdmin) {
      sendData({ type: 'call_ended' })
      sendTranscriptToBackend()
      setTimeout(() => {
        room.value?.disconnect()
        status.value = 'ended'
        if (durationInterval) clearInterval(durationInterval)
        emit('ended')
      }, 300)
      return
    }
    room.value.disconnect()
  }
  status.value = 'ended'
  if (durationInterval) clearInterval(durationInterval)
  emit('ended')
}

let transcriptSent = false
function sendTranscriptToBackend() {
  if (transcriptSent || chatMessages.value.length === 0 || !props.transcriptUrl) return
  transcriptSent = true
  const messages = chatMessages.value.map(m => ({
    sender: m.sender,
    identity: m.identity,
    message: m.message,
    ts: m.ts,
    isImage: !!m.isImage,
    isFile: !!m.isFile,
    attachmentId: m.attachmentId || null,
    name: m.name || '',
    mime: m.mime || '',
    size: m.size || 0,
  }))

  let payload = JSON.stringify({ messages, duration: callDuration.value })

  // sendBeacon (and fetch keepalive) silently fail above ~64KB. Server-stored
  // attachments only travel as ids, but legacy base64 images can blow the
  // limit — replace them with a placeholder if the payload is too large.
  if (payload.length > 60000) {
    const slim = messages.map(m => (
      m.isImage && !m.isFile && String(m.message).startsWith('data:')
        ? { ...m, message: '[Image shared during the call]', isImage: false }
        : m
    ))
    payload = JSON.stringify({ messages: slim, duration: callDuration.value })
  }

  const beaconOk = !!(navigator.sendBeacon
    && navigator.sendBeacon(props.transcriptUrl, new Blob([payload], { type: 'application/json' })))
  if (!beaconOk) {
    fetch(props.transcriptUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true,
    }).catch((e) => console.warn('[VideoCallRoom] Transcript send failed:', e?.message || e))
  }
}

// ------ Track attachment ------

function attachVideoTrack(el, track) {
  if (!el || !track) return
  if (attachedTracks.get(el) === track) return
  const existing = el.querySelector('video, canvas')
  if (existing) existing.remove()
  const mediaEl = track.attach()
  mediaEl.style.width = '100%'
  mediaEl.style.height = '100%'
  mediaEl.style.objectFit = 'cover'
  el.appendChild(mediaEl)
  attachedTracks.set(el, track)
}

function attachScreenTrack(el, track) {
  if (!el || !track) return
  if (attachedTracks.get(el) === track) return
  const existing = el.querySelector('video, canvas')
  if (existing) existing.remove()
  const mediaEl = track.attach()
  mediaEl.style.width = '100%'
  mediaEl.style.height = '100%'
  mediaEl.style.objectFit = 'contain'
  el.appendChild(mediaEl)
  attachedTracks.set(el, track)
}

function attachAudio(el, track) {
  if (!el || !track) return
  if (attachedTracks.get(el) === track) return
  const existing = el.querySelector('audio')
  if (existing) existing.remove()
  const audioEl = track.attach()
  el.appendChild(audioEl)
  attachedTracks.set(el, track)
}

const gridClass = computed(() => {
  const count = remoteParticipants.value.length
  if (count <= 1) return 'grid-cols-1'
  if (count === 2) return 'grid-cols-2'
  if (count <= 4) return 'grid-cols-2 grid-rows-2'
  if (count <= 6) return 'grid-cols-3 grid-rows-2'
  return 'grid-cols-3 grid-rows-3'
})

function attachSelfView() {
  if (!room.value || !selfViewEl.value) return
  const camPub = Array.from(room.value.localParticipant.trackPublications.values()).find(
    t => t.track && t.source === Track.Source.Camera && t.track.kind === 'video'
  )
  const track = camPub?.track
  if (!track || track === selfViewAttachedTrack) return
  const existing = selfViewEl.value.querySelector('video, canvas')
  if (existing) existing.remove()
  const mediaEl = track.attach()
  mediaEl.style.width = '100%'
  mediaEl.style.height = '100%'
  mediaEl.style.objectFit = 'cover'
  mediaEl.style.transform = 'scaleX(-1)'
  selfViewEl.value.appendChild(mediaEl)
  selfViewAttachedTrack = track
}

watch(isCamOff, async (off) => {
  if (!off) {
    await nextTick()
    setTimeout(attachSelfView, 200)
  } else {
    selfViewAttachedTrack = null
  }
})

const screenShareTargetRef = ref(null)

watch(activeScreenShare, async (ss) => {
  if (!ss?.track) return
  for (let attempt = 0; attempt < 5; attempt++) {
    await nextTick()
    const el = screenShareTargetRef.value || document.getElementById('screen-share-target')
    if (el) {
      attachScreenTrack(el, ss.track)
      return
    }
    await new Promise(r => setTimeout(r, 50))
  }
})

async function rejoinCall() {
  transcriptSent = false
  chatMessages.value = []
  callDuration.value = 0
  if (props.showPreJoin) {
    // PreJoinPanel remounts and starts its own camera preview
    status.value = 'prejoin'
    return
  }
  status.value = 'connecting'
  let creds = { livekitToken: props.livekitToken, wsUrl: props.wsUrl }
  if (typeof props.reconnectFn === 'function') {
    try {
      const fresh = await props.reconnectFn()
      if (fresh?.livekitToken && fresh?.wsUrl) {
        creds = { livekitToken: fresh.livekitToken, wsUrl: fresh.wsUrl }
      }
    } catch { /* fall back to cached creds */ }
  }
  connectToRoom(creds.livekitToken, creds.wsUrl)
}
</script>

<template>
  <div class="h-full bg-gradient-to-br from-surface-900 via-surface-800 to-surface-900 flex flex-col overflow-hidden">
    <!-- Top bar (Teams-style: brand + controls + actions) -->
    <header class="px-4 sm:px-5 py-3 flex items-center justify-between gap-3 border-b border-surface-700/40 flex-shrink-0">
      <!-- Left: brand + timer + encryption shield -->
      <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
        <div class="w-7 h-7 rounded-lg bg-primary-500 flex items-center justify-center shrink-0">
          <span class="material-symbols-rounded text-white text-base">videocam</span>
        </div>
        <span class="hidden sm:inline text-white/80 font-semibold text-sm tracking-wide">FlowOne</span>
        <div v-if="status === 'active'" class="hidden md:flex items-center gap-2 text-white/60 text-sm shrink-0">
          <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
          {{ formattedDuration }}
        </div>
        <CallSecurityBadge v-if="status === 'active'" compact class="shrink-0" />
      </div>

      <!-- Right: Teams-style controls (utility + media + leave) -->
      <div class="flex items-center min-w-0">
        <VideoCallControls
          v-if="status === 'active'"
          :isMicMuted="isMicMuted"
          :isCamOff="isCamOff"
          :isScreenSharing="isScreenSharing"
          :forceMuted="forceMuted"
          :isAdmin="isAdmin"
          :isMobile="isMobile"
          :isWorkshopGuest="isWorkshopGuest"
          :showChat="showChat"
          :unreadCount="unreadCount"
          :allMuted="allMuted"
          :useSpeaker="useSpeaker"
          :canScreenShare="canScreenShare"
          :selectedAudioInputId="selectedAudioInputId"
          :selectedVideoInputId="selectedVideoInputId"
          :selectedAudioOutputId="selectedAudioOutputId"
          :admissionEnabled="admissionEnabled()"
          :showWaitingRoom="showWaitingRoom"
          :pendingCount="pendingAdmissions.length"
          :showSidebar="showSidebar"
          :participantCount="participantCount"
          @toggle-mic="toggleMic"
          @toggle-cam="toggleCam"
          @flip="flipCamera"
          @toggle-speaker="toggleSpeaker"
          @toggle-screen-share="toggleScreenShare"
          @toggle-chat="toggleChat"
          @mute-all="muteAll"
          @unmute-all="unmuteAll"
          @leave="leaveCall"
          @audio-input-change="handleInCallAudioInputChange"
          @video-input-change="handleInCallVideoInputChange"
          @audio-output-change="handleInCallAudioOutputChange"
          @toggle-waiting-room="toggleWaitingRoom"
          @toggle-sidebar="showSidebar = !showSidebar"
        />
      </div>
    </header>

    <!-- Pre-join -->
    <div v-if="status === 'prejoin'" class="flex-1 flex items-center justify-center p-4 overflow-y-auto">
      <PreJoinPanel
        v-model:name="displayName"
        join-label="Join Call"
        @join="joinFromPreJoin"
      />
    </div>

    <!-- Connecting -->
    <div v-else-if="status === 'connecting'" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl text-primary-400 animate-spin">progress_activity</span>
        <p class="mt-4 text-white font-medium">Joining call...</p>
        <p class="text-surface-400 text-sm mt-1">Setting up your camera and microphone</p>
      </div>
    </div>

    <!-- Active Call -->
    <div v-else-if="status === 'active'" class="flex-1 flex overflow-hidden" data-call-container>
      <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Presentation mode -->
        <div v-if="isPresentationMode" class="flex-1 flex flex-col overflow-hidden p-3 gap-3">
          <div
            class="flex-1 rounded-2xl bg-black overflow-hidden relative min-h-0 transition-shadow duration-150"
            :class="!activeScreenShare && pinnedParticipant && isSpeaking(pinnedParticipant.identity) ? 'ring-2 ring-green-400' : ''"
          >
            <template v-if="activeScreenShare">
              <div ref="screenShareTargetRef" class="w-full h-full"></div>
              <div class="absolute bottom-3 left-3 px-3 py-1.5 rounded-full bg-black/60 backdrop-blur-sm text-white text-xs font-medium flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-green-400">screen_share</span>
                {{ activeScreenShare?.name }} is sharing
              </div>
            </template>
            <template v-else-if="pinnedParticipant">
              <div
                v-if="pinnedParticipant.hasVideo"
                :ref="el => { if (el) attachVideoTrack(el, pinnedParticipant.videoTrack) }"
                class="w-full h-full"
              ></div>
              <div v-else class="w-full h-full flex items-center justify-center">
                <div class="w-24 h-24 rounded-full bg-primary-500/20 flex items-center justify-center">
                  <span class="text-4xl font-bold text-primary-400">{{ (pinnedParticipant.name || '?')[0].toUpperCase() }}</span>
                </div>
              </div>
              <div :ref="el => { if (el && pinnedParticipant.audioTrack) attachAudio(el, pinnedParticipant.audioTrack) }" class="hidden"></div>
              <div class="absolute bottom-3 left-3 px-3 py-1.5 rounded-full bg-black/60 backdrop-blur-sm text-white text-xs font-medium flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-blue-400">push_pin</span>
                {{ pinnedParticipant.name }}
              </div>
              <button
                @click="pinnedIdentity = null"
                class="absolute top-3 right-3 w-8 h-8 rounded-full bg-black/50 hover:bg-black/70 flex items-center justify-center text-white/70 hover:text-white transition-all"
                title="Unpin"
              >
                <span class="material-symbols-rounded text-base">close</span>
              </button>
            </template>
          </div>
          <!-- Filmstrip -->
          <div v-if="!isWorkshopGuest" class="flex gap-2 overflow-x-auto pb-1 flex-shrink-0" style="max-height: 140px;">
            <div
              v-if="activeScreenShare && pinnedParticipant"
              class="relative rounded-xl overflow-hidden bg-surface-800 flex-shrink-0 shadow-lg cursor-pointer group transition-shadow duration-150"
              :class="isSpeaking(pinnedParticipant.identity) ? 'ring-2 ring-green-400' : 'ring-2 ring-blue-500/40'"
              style="width: 160px; height: 120px;"
              @dblclick="pinnedIdentity = null"
            >
              <div
                v-if="pinnedParticipant.hasVideo"
                :ref="el => { if (el) attachVideoTrack(el, pinnedParticipant.videoTrack) }"
                class="w-full h-full"
              ></div>
              <div v-else class="w-full h-full flex items-center justify-center">
                <div class="w-10 h-10 rounded-full bg-primary-500/20 flex items-center justify-center">
                  <span class="text-lg font-bold text-primary-400">{{ (pinnedParticipant.name || '?')[0].toUpperCase() }}</span>
                </div>
              </div>
              <div class="absolute bottom-1 left-1 right-1 flex items-center">
                <span class="text-[10px] text-white bg-black/50 px-1.5 py-0.5 rounded-full truncate max-w-[120px] flex items-center gap-1">
                  <span class="material-symbols-rounded text-blue-400 text-[9px]">push_pin</span>
                  {{ pinnedParticipant.name }}
                </span>
              </div>
            </div>
            <div
              v-for="p in unpinnedParticipants" :key="p.identity"
              class="relative rounded-xl overflow-hidden bg-surface-800 flex-shrink-0 shadow-lg cursor-pointer group transition-shadow duration-150"
              :class="isSpeaking(p.identity) ? 'ring-2 ring-green-400' : ''"
              style="width: 160px; height: 120px;"
              @dblclick="pinParticipant(p.identity)"
            >
              <div
                v-if="p.hasVideo"
                :ref="el => { if (el) attachVideoTrack(el, p.videoTrack) }"
                class="w-full h-full"
              ></div>
              <div v-else class="w-full h-full flex items-center justify-center">
                <div class="w-10 h-10 rounded-full bg-primary-500/20 flex items-center justify-center">
                  <span class="text-lg font-bold text-primary-400">{{ (p.name || '?')[0].toUpperCase() }}</span>
                </div>
              </div>
              <div :ref="el => { if (el && p.audioTrack) attachAudio(el, p.audioTrack) }" class="hidden"></div>
              <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100">
                <span class="material-symbols-rounded text-white text-lg drop-shadow-lg">push_pin</span>
              </div>
              <div class="absolute bottom-1 left-1 right-1 flex items-center justify-between">
                <span class="text-[10px] text-white bg-black/50 px-1.5 py-0.5 rounded-full truncate max-w-[80px]">{{ p.name }}</span>
                <div class="flex gap-0.5">
                  <span v-if="!p.hasAudio" class="w-4 h-4 rounded-full bg-red-500/80 flex items-center justify-center">
                    <span class="material-symbols-rounded text-white text-[9px]">mic_off</span>
                  </span>
                </div>
              </div>
            </div>
            <div v-if="remoteParticipants.length === 0" class="flex items-center justify-center px-6 text-surface-500 text-xs whitespace-nowrap">
              <span class="material-symbols-rounded text-base mr-1.5">person_add</span>
              Waiting for others...
            </div>
          </div>
        </div>

        <!-- Grid mode -->
        <div v-else class="flex-1 p-3 overflow-hidden">
          <div class="w-full h-full grid gap-2" :class="gridClass">
            <div
              v-for="p in remoteParticipants" :key="p.identity"
              class="relative rounded-2xl overflow-hidden bg-surface-800 shadow-xl min-h-0 cursor-pointer group transition-shadow duration-150"
              :class="isSpeaking(p.identity) ? 'ring-2 ring-green-400' : ''"
              @dblclick="pinParticipant(p.identity)"
            >
              <div
                v-if="p.hasVideo"
                :ref="el => { if (el) attachVideoTrack(el, p.videoTrack) }"
                class="w-full h-full"
              ></div>
              <div v-else class="w-full h-full flex items-center justify-center">
                <div class="w-16 h-16 rounded-full bg-primary-500/20 flex items-center justify-center">
                  <span class="text-2xl font-bold text-primary-400">{{ (p.name || '?')[0].toUpperCase() }}</span>
                </div>
              </div>
              <div :ref="el => { if (el && p.audioTrack) attachAudio(el, p.audioTrack) }" class="hidden"></div>
              <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100">
                <span class="material-symbols-rounded text-white/80 text-2xl drop-shadow-lg">push_pin</span>
              </div>
              <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between">
                <span class="text-[11px] text-white bg-black/50 px-2 py-0.5 rounded-full truncate max-w-[60%] font-medium">{{ p.name }}</span>
                <div class="flex gap-1">
                  <span v-if="!p.hasAudio" class="w-5 h-5 rounded-full bg-red-500/80 flex items-center justify-center" title="Muted">
                    <span class="material-symbols-rounded text-white text-[11px]">mic_off</span>
                  </span>
                  <span v-if="p.isScreenSharing" class="w-5 h-5 rounded-full bg-green-500/80 flex items-center justify-center" title="Sharing screen">
                    <span class="material-symbols-rounded text-white text-[11px]">screen_share</span>
                  </span>
                </div>
              </div>
            </div>
            <div v-if="remoteParticipants.length === 0" class="rounded-2xl bg-surface-800/50 flex items-center justify-center">
              <div class="text-center">
                <span class="material-symbols-rounded text-5xl text-surface-500">person_add</span>
                <p class="text-surface-400 mt-3 text-sm">Waiting for others to join...</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Self-view PiP -->
        <div
          v-if="!isCamOff && status === 'active'"
          class="absolute z-30 w-36 h-28 sm:w-44 sm:h-32 rounded-xl overflow-hidden bg-surface-900 shadow-2xl border border-surface-600/50 cursor-grab select-none"
          :class="{
            'bottom-4 right-4': pipPosition.corner === 'bottom-right',
            'bottom-4 left-4': pipPosition.corner === 'bottom-left',
            'top-4 right-4': pipPosition.corner === 'top-right',
            'top-4 left-4': pipPosition.corner === 'top-left',
            'cursor-grabbing': pipDragging,
            'ring-2 ring-green-400': isLocalSpeaking,
            'ring-1 ring-black/20': !isLocalSpeaking,
          }"
          style="touch-action: none; transition: all 0.25s ease;"
          @pointerdown.prevent="onPipPointerDown"
        >
          <div ref="selfViewEl" class="w-full h-full pointer-events-none"></div>
          <div class="absolute bottom-1 left-1 px-1.5 py-0.5 rounded-full bg-black/50 text-[9px] text-white font-medium pointer-events-none">
            You
          </div>
        </div>

      </div>

      <!-- Waiting room sidebar (admins only) -->
      <Transition name="slide">
        <div
          v-if="showWaitingRoom && admissionEnabled()"
          class="w-80 bg-surface-800/90 backdrop-blur-sm border-l border-surface-700/50 flex flex-col flex-shrink-0"
        >
          <div class="px-4 py-3 border-b border-surface-700/40 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-white flex items-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-base text-amber-400">door_front</span>
              Waiting room
              <span class="text-amber-300 text-[11px] bg-amber-500/15 px-2 py-0.5 rounded-full">
                {{ pendingAdmissions.length }}
              </span>
            </h3>
            <div class="flex items-center gap-1">
              <button
                @click="refreshAdmissionLobby"
                class="w-7 h-7 rounded-full hover:bg-white/10 flex items-center justify-center text-surface-300"
                title="Refresh"
              >
                <span class="material-symbols-rounded text-base">refresh</span>
              </button>
              <button
                @click="showWaitingRoom = false"
                class="w-7 h-7 rounded-full hover:bg-white/10 flex items-center justify-center text-surface-300"
                title="Close"
              >
                <span class="material-symbols-rounded text-base">close</span>
              </button>
            </div>
          </div>
          <div class="flex-1 overflow-y-auto p-3 space-y-2">
            <div v-if="pendingAdmissions.length === 0" class="text-center text-surface-500 text-xs py-8">
              <span class="material-symbols-rounded text-3xl block mb-2">door_open</span>
              No one is waiting right now.
            </div>
            <div
              v-for="req in pendingAdmissions"
              :key="req.id"
              class="flex items-center justify-between gap-2 bg-surface-700/40 rounded-xl p-2.5"
            >
              <div class="flex items-center gap-2 min-w-0 flex-1">
                <div class="w-8 h-8 rounded-full bg-amber-500/15 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-amber-300 text-base">person</span>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="text-white text-sm truncate">{{ req.guest_name || 'Guest' }}</p>
                  <p class="text-surface-400 text-[11px] truncate">{{ req.requested_at }}</p>
                </div>
              </div>
              <div class="flex items-center gap-1.5 flex-shrink-0">
                <button
                  @click="admitAdmission(req.id)"
                  :disabled="!!admissionActing[req.id]"
                  class="px-2.5 py-1 rounded-full bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 text-xs font-medium flex items-center gap-1 disabled:opacity-50"
                >
                  <span class="material-symbols-rounded text-sm">{{ admissionActing[req.id] === 'approving' ? 'sync' : 'check' }}</span>
                  Admit
                </button>
                <button
                  @click="denyAdmission(req.id)"
                  :disabled="!!admissionActing[req.id]"
                  class="px-2.5 py-1 rounded-full bg-red-500/20 hover:bg-red-500/30 text-red-300 text-xs font-medium flex items-center gap-1 disabled:opacity-50"
                >
                  <span class="material-symbols-rounded text-sm">{{ admissionActing[req.id] === 'denying' ? 'sync' : 'close' }}</span>
                  Deny
                </button>
              </div>
            </div>
          </div>
          <p class="px-4 py-2 text-[11px] text-surface-500 border-t border-surface-700/40 flex-shrink-0">
            Late joiners stay here until you let them in.
          </p>
        </div>
      </Transition>

      <!-- Participants sidebar -->
      <Transition name="slide">
        <div
          v-if="showSidebar"
          class="w-72 bg-surface-800/90 backdrop-blur-sm border-l border-surface-700/50 flex flex-col flex-shrink-0"
        >
          <div class="px-4 py-3 border-b border-surface-700/40 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-white flex items-center gap-2">
              <span class="material-symbols-rounded text-base">group</span>
              Participants ({{ participantCount }})
            </h3>
            <button @click="showSidebar = false" class="text-surface-400 hover:text-white transition-colors">
              <span class="material-symbols-rounded text-lg">close</span>
            </button>
          </div>
          <div class="flex-1 overflow-y-auto py-2">
            <div
              v-for="p in allParticipants" :key="p.identity"
              class="px-4 py-2.5 flex items-center gap-3 hover:bg-surface-700/30 transition-colors cursor-pointer"
              :class="{ 'bg-blue-500/10': pinnedIdentity === p.identity }"
              @click="!p.isLocal && pinParticipant(p.identity)"
            >
              <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 transition-shadow duration-150"
                   :class="[
                     p.hasVideo ? 'bg-blue-500/20' : 'bg-surface-600',
                     isSpeaking(p.identity) ? 'ring-2 ring-green-400' : ''
                   ]">
                <span class="material-symbols-rounded text-sm" :class="p.hasVideo ? 'text-blue-400' : 'text-surface-400'">
                  {{ p.hasVideo ? 'videocam' : 'person' }}
                </span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-white truncate">
                  {{ p.name }}
                  <span v-if="p.isLocal" class="text-surface-500 text-xs">(You)</span>
                </p>
              </div>
              <div class="flex items-center gap-1.5 flex-shrink-0">
                <span
                  v-if="pinnedIdentity === p.identity"
                  class="w-5 h-5 rounded-full bg-blue-500/20 flex items-center justify-center"
                  title="Pinned"
                >
                  <span class="material-symbols-rounded text-blue-400 text-[11px]">push_pin</span>
                </span>
                <button
                  v-if="isAdmin && !p.isLocal"
                  @click.stop="sendData({ type: 'admin_mute', target: p.identity, muted: p.hasAudio })"
                  class="w-5 h-5 rounded-full flex items-center justify-center hover:scale-110 transition-transform"
                  :class="p.hasAudio ? 'bg-green-500/15' : 'bg-red-500/20'"
                  :title="p.hasAudio ? 'Mute this participant' : 'Allow to unmute'"
                >
                  <span class="material-symbols-rounded text-[11px]" :class="p.hasAudio ? 'text-green-400' : 'text-red-400'">
                    {{ p.hasAudio ? 'mic' : 'mic_off' }}
                  </span>
                </button>
                <button
                  v-if="isAdmin && !p.isLocal && kickUrl"
                  @click.stop="requestKick(p.identity, p.name)"
                  class="w-5 h-5 rounded-full bg-red-500/20 hover:bg-red-500/30 flex items-center justify-center transition-all"
                  title="Remove from call"
                >
                  <span class="material-symbols-rounded text-red-400 text-[11px]">person_remove</span>
                </button>
                <template v-else>
                  <span
                    v-if="!p.hasAudio"
                    class="w-5 h-5 rounded-full bg-red-500/20 flex items-center justify-center"
                    title="Muted"
                  >
                    <span class="material-symbols-rounded text-red-400 text-[11px]">mic_off</span>
                  </span>
                  <span
                    v-else
                    class="w-5 h-5 rounded-full bg-green-500/15 flex items-center justify-center"
                    title="Mic on"
                  >
                    <span class="material-symbols-rounded text-green-400 text-[11px]">mic</span>
                  </span>
                </template>
                <span
                  v-if="p.isScreenSharing"
                  class="w-5 h-5 rounded-full bg-green-500/20 flex items-center justify-center"
                  title="Sharing screen"
                >
                  <span class="material-symbols-rounded text-green-400 text-[11px]">screen_share</span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Chat sidebar -->
      <Transition name="slide">
        <CallChatPanel
          v-if="showChat"
          :messages="chatMessages"
          :attachments-base-url="attachmentsBaseUrl"
          :sender-name="displayName"
          @close="showChat = false"
          @send="sendChatMessage"
          @send-attachment="sendChatAttachment"
          @send-image="sendChatImage"
          @notify="showToast"
        />
      </Transition>
    </div>

    <!-- Toast notification -->
    <Transition name="toast">
      <div
        v-if="toastMessage"
        class="fixed bottom-24 left-1/2 -translate-x-1/2 z-50 px-5 py-2.5 rounded-full bg-surface-800/95 backdrop-blur-sm
               border border-surface-600/50 text-white text-sm font-medium shadow-xl"
      >
        {{ toastMessage }}
      </div>
    </Transition>

    <!-- Reconnect / offline banner -->
    <div
      v-if="status === 'active' && (connectionState === 'reconnecting' || !isOnline)"
      class="fixed top-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-full bg-yellow-500/20 border border-yellow-500/40
             text-yellow-200 text-xs font-medium flex items-center gap-2 shadow-lg"
    >
      <span class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
      <span>{{ !isOnline ? 'You are offline. Waiting for connection…' : 'Reconnecting…' }}</span>
    </div>

    <!-- Workshop mode hint (guest) -->
    <div
      v-if="status === 'active' && isWorkshopGuest"
      class="fixed top-14 left-1/2 -translate-x-1/2 z-40 px-4 py-1.5 rounded-full bg-indigo-500/20 border border-indigo-500/30
             text-indigo-200 text-xs font-medium flex items-center gap-1.5"
    >
      <span class="material-symbols-rounded text-sm">school</span>
      Workshop mode — your mic and camera are off
    </div>

    <!-- iOS Safari resume CTA -->
    <div
      v-if="showResumePrompt && status === 'active'"
      class="fixed bottom-32 left-1/2 -translate-x-1/2 z-50 px-4 py-3 rounded-2xl bg-surface-800/95 backdrop-blur-sm
             border border-surface-600/50 text-white text-sm shadow-xl flex items-center gap-3"
    >
      <span class="material-symbols-rounded text-primary-400">play_circle</span>
      <span>Tap to resume your camera and mic</span>
      <button
        @click="resumeAfterBackground"
        class="px-3 py-1.5 rounded-full bg-primary-500 hover:bg-primary-600 text-white text-xs font-semibold"
      >Resume</button>
    </div>

    <!--
      Floating "X waiting" pill: appears when the host has the sidebar
      closed AND there are pending admissions, so late joiners are never
      missed even when the host is focused on the call grid.
    -->
    <button
      v-if="isAdmin && status === 'active' && admissionEnabled() && pendingAdmissions.length > 0 && !showWaitingRoom"
      @click="openWaitingRoom"
      class="fixed bottom-24 right-4 z-50 flex items-center gap-2 px-4 py-2.5 rounded-full bg-amber-500/90 hover:bg-amber-500
             text-white text-sm font-semibold shadow-xl transition-all animate-pulse"
    >
      <span class="material-symbols-rounded text-base">door_front</span>
      {{ pendingAdmissions.length }} waiting
    </button>

    <!-- Duplicate tab warning -->
    <div
      v-if="dupTabConflict && status !== 'kicked' && status !== 'ended'"
      class="fixed top-4 right-4 z-50 max-w-xs px-4 py-3 rounded-2xl bg-red-500/15 border border-red-500/40 text-red-200
             text-xs shadow-xl"
    >
      <div class="flex items-center gap-2 mb-1">
        <span class="material-symbols-rounded text-base">tab_duplicate</span>
        <strong class="font-semibold">Another tab is open</strong>
      </div>
      <p class="opacity-80 mb-2">This call link is already open in another tab. Closing other tabs avoids LiveKit identity conflicts.</p>
      <button
        @click="dismissDupTabWarning"
        class="px-2 py-1 rounded-full bg-red-500/30 hover:bg-red-500/40 text-white text-[11px] font-medium"
      >Continue anyway</button>
    </div>

    <!-- Force-muted indicator -->
    <div
      v-if="forceMuted && !isAdmin && status === 'active'"
      class="fixed top-16 left-1/2 -translate-x-1/2 z-40 px-4 py-1.5 rounded-full bg-orange-500/20 border border-orange-500/30
             text-orange-300 text-xs font-medium flex items-center gap-1.5"
    >
      <span class="material-symbols-rounded text-sm">lock</span>
      Muted by host
    </div>

    <!-- Kicked / removed -->
    <div v-else-if="status === 'kicked'" class="flex-1 flex items-center justify-center p-4">
      <div class="text-center max-w-sm">
        <div class="w-20 h-20 rounded-full bg-red-500/15 flex items-center justify-center mx-auto mb-5">
          <span class="material-symbols-rounded text-4xl text-red-400">block</span>
        </div>
        <h2 class="text-2xl font-bold text-white mb-2">Removed from the call</h2>
        <p class="text-surface-400 mb-6">{{ kickedReason || 'The host removed you from this call.' }}</p>
        <p class="text-surface-500 text-xs">You can close this tab.</p>
      </div>
    </div>

    <!-- Ended -->
    <div v-else-if="status === 'ended'" class="flex-1 flex items-center justify-center p-4">
      <div class="text-center max-w-sm">
        <div class="w-20 h-20 rounded-full bg-surface-700 flex items-center justify-center mx-auto mb-5">
          <span class="material-symbols-rounded text-4xl text-surface-400">call_end</span>
        </div>
        <h2 class="text-2xl font-bold text-white mb-2">Call ended</h2>
        <p class="text-surface-400 mb-6">Thank you for joining. You can close this tab.</p>
        <button
          @click="rejoinCall"
          class="px-6 py-3 rounded-full bg-primary-500 hover:bg-primary-600 text-white font-medium text-sm transition-all"
        >
          Rejoin Call
        </button>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="status === 'error'" class="flex-1 flex items-center justify-center p-4">
      <div class="text-center max-w-sm">
        <div class="w-20 h-20 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-5">
          <span class="material-symbols-rounded text-4xl text-red-400">error</span>
        </div>
        <h2 class="text-2xl font-bold text-white mb-2">Unable to join</h2>
        <p class="text-surface-400 mb-6">{{ errorMessage }}</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-enter-active,
.slide-leave-active {
  transition: all 0.2s ease;
}
.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
  opacity: 0;
}
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translate(-50%, 10px);
}
.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.2s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  max-height: 0;
  opacity: 0;
  overflow: hidden;
}
.slide-up-enter-to,
.slide-up-leave-from {
  max-height: 200px;
  opacity: 1;
}
</style>
