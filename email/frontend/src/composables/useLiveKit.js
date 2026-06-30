/**
 * useLiveKit composable
 * 
 * Replaces useWebRTC — manages media routing through LiveKit SFU server
 * instead of peer-to-peer mesh WebRTC connections.
 * 
 * LiveKit handles all ICE negotiation, SDP exchange, TURN relay, and track
 * routing internally. This composable simply connects to a room, publishes
 * local tracks, and exposes remote participants' streams reactively.
 */

import { ref, markRaw } from 'vue'
import {
  Room,
  RoomEvent,
  Track,
  ConnectionState,
  DisconnectReason,
  LocalParticipant,
  RemoteParticipant,
  RemoteTrackPublication,
  ScreenSharePresets,
} from 'livekit-client'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

export function useLiveKit() {
  // ── Reactive state ──────────────────────────────────────────────────
  const room = ref(null)                        // LiveKit Room instance
  const remoteStreams = ref(new Map())           // email -> MediaStream (camera/audio)
  const remoteScreenStreams = ref(new Map())     // email -> MediaStream (screen share)
  const remoteScreenTracks = ref(new Map())     // email -> LiveKit Track (screen share video)
  const connectionState = ref('new')            // new, connecting, connected, reconnecting, failed, closed
  const participantStates = ref(new Map())      // email -> 'connected' | 'disconnected'
  const screenSharingParticipants = ref(new Set())
  const speakingParticipants = ref(new Set())   // emails (lowercase) currently speaking, incl. local

  // ── Callbacks (set by call store) ───────────────────────────────────
  let onRemoteTrackCb = null
  let onConnectionStateChangeCb = null
  let onParticipantConnectedCb = null
  let onParticipantDisconnectedCb = null
  let onSpeakingChangedCb = null

  // ── Connection sequence counter ──────────────────────────────────────
  // Incremented on every connect() call. After the async connect() resolves
  // we verify the seq is still current — if not, a newer connect() raced
  // ahead and we must abandon this one.
  let connectSeq = 0

  // ── Token fetch ─────────────────────────────────────────────────────

  /**
   * Get a LiveKit access token from the backend.
   * @param {string} roomName - Room name (= callId)
   * @param {string} [displayName] - User's display name
   * @returns {{ token: string, ws_url: string }}
   */
  async function fetchToken(roomName, displayName = '') {
    const response = await api.post('/call/livekit-token', {
      room_name: roomName,
      display_name: displayName
    })
    if (!response.data.success) {
      throw new Error('Failed to get LiveKit token')
    }
    return response.data.data // { token, ws_url }
  }

  // ── Room connection ─────────────────────────────────────────────────

  /**
   * Connect to a LiveKit room.
   * @param {string} wsUrl   - LiveKit server URL (wss://...)
   * @param {string} token   - Access token from backend
   */
  async function connect(wsUrl, token) {
    const seq = ++connectSeq

    if (room.value) {
      await disconnect()
    }

    const lkRoom = new Room({
      adaptiveStream: false,
      dynacast: true,
      autoSubscribe: true,
      audioCaptureDefaults: {
        autoGainControl: true,
        echoCancellation: true,
        noiseSuppression: true,
        channelCount: 1
      },
      videoCaptureDefaults: {
        resolution: { width: 1280, height: 720, frameRate: 30 },
      },
      publishDefaults: {
        simulcast: true,
        videoSimulcastLayers: [
          { width: 640, height: 360, encoding: { maxBitrate: 500_000, maxFramerate: 25 } },
          { width: 320, height: 180, encoding: { maxBitrate: 150_000, maxFramerate: 15 } },
        ],
        videoEncoding: { maxBitrate: 1_700_000, maxFramerate: 30 },
        screenShareEncoding: ScreenSharePresets.h1080fps30.encoding,
      },
    })

    // Wire up all event handlers before connecting
    setupRoomEvents(lkRoom)
    // Set room ref before connect so early track events are not dropped.
    // markRaw prevents Vue from wrapping the Room in a reactive Proxy.
    // LiveKit's internal methods (setCameraEnabled, setScreenShareEnabled, etc.)
    // use structuredClone and Worker postMessage which fail on Proxy objects.
    room.value = markRaw(lkRoom)

    connectionState.value = 'connecting'
    isDebugEnabled() && console.log(`[LiveKit] Connecting to ${wsUrl} (seq=${seq})`)

    try {
      await lkRoom.connect(wsUrl, token)
    } catch (e) {
      console.error(`[LiveKit] Connection FAILED: ${e.name}: ${e.message}`)
      if (room.value === lkRoom) {
        room.value = null
      }
      lkRoom.removeAllListeners()
      lkRoom.disconnect()
      throw e
    }

    if (seq !== connectSeq) {
      isDebugEnabled() && console.warn(`[LiveKit] Connection abandoned (seq ${seq} superseded by ${connectSeq})`)
      if (room.value === lkRoom) {
        room.value = null
      }
      lkRoom.removeAllListeners()
      lkRoom.disconnect()
      return
    }

    connectionState.value = 'connected'
    isDebugEnabled() && console.log(`[LiveKit] Connected! Room: ${lkRoom.name}, Identity: ${lkRoom.localParticipant.identity}, RemoteParticipants: ${lkRoom.remoteParticipants.size}`)

    // Process any participants already in the room (late joiner scenario)
    for (const participant of lkRoom.remoteParticipants.values()) {
      // Explicitly subscribe to every published track for reliability.
      for (const publication of participant.trackPublications.values()) {
        try { publication.setSubscribed(true) } catch (_) { /* ignore */ }
      }
      handleParticipantConnected(participant)
    }
  }

  /**
   * Disconnect from the LiveKit room.
   */
  async function disconnect() {
    if (room.value) {
      const lkRoom = room.value
      // Null room.value FIRST so all event handler guards (lkRoom !== room.value)
      // immediately start returning, preventing any post-disconnect state mutation.
      room.value = null
      lkRoom.removeAllListeners()
      lkRoom.disconnect()
    }
    remoteStreams.value = new Map()
    remoteScreenStreams.value = new Map()
    remoteScreenTracks.value = new Map()
    screenSharingParticipants.value = new Set()
    speakingParticipants.value = new Set()
    participantStates.value = new Map()
    connectionState.value = 'closed'
  }

  // ── Room event handlers ─────────────────────────────────────────────

  function addOrReplaceTrack(stream, mediaStreamTrack) {
    if (!stream || !mediaStreamTrack) return
    const stale = stream.getTracks().filter(
      t => t.kind === mediaStreamTrack.kind && t.id !== mediaStreamTrack.id
    )
    stale.forEach(t => stream.removeTrack(t))
    if (!stream.getTrackById(mediaStreamTrack.id)) {
      stream.addTrack(mediaStreamTrack)
    }
  }

  function setupRoomEvents(lkRoom) {
    lkRoom.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
      if (lkRoom !== room.value) return
      const email = String(participant.identity || '').toLowerCase()
      const mst = track.mediaStreamTrack
      isDebugEnabled() && console.log(`[LiveKit] TrackSubscribed: kind=${track.kind} source=${track.source} from=${email} sid=${track?.sid || 'n/a'} mstId=${mst?.id?.slice(0,8) || 'null'} mstState=${mst?.readyState || 'null'} mstEnabled=${mst?.enabled ?? 'null'}`)

      // Ensure participant state exists — TrackSubscribed can arrive before ParticipantConnected.
      if (!participantStates.value.has(email)) {
        participantStates.value.set(email, 'connected')
        participantStates.value = new Map(participantStates.value)
      }

      if (track.source === Track.Source.ScreenShare || track.source === Track.Source.ScreenShareAudio) {
        const newScreenStreams = new Map(remoteScreenStreams.value)
        const existingStream = newScreenStreams.get(email)
        const screenStream = markRaw(new MediaStream())
        if (existingStream) {
          const existingTracks = existingStream.getTracks()
          console.log(`[LiveKit] Screen stream: copying ${existingTracks.length} existing tracks from previous stream`)
          existingTracks.forEach(t => screenStream.addTrack(t))
        }
        addOrReplaceTrack(screenStream, track.mediaStreamTrack)
        newScreenStreams.set(email, screenStream)
        remoteScreenStreams.value = newScreenStreams

        if (isDebugEnabled()) {
          const finalTracks = screenStream.getTracks().map(t => `${t.kind}:${t.id.slice(0,8)}:${t.readyState}:enabled=${t.enabled}`).join(', ')
          console.log(`[LiveKit] Screen stream built for ${email}: streamId=${screenStream.id.slice(0,8)}, tracks=[${finalTracks}]`)
        }

        if (track.source === Track.Source.ScreenShare) {
          const newTracks = new Map(remoteScreenTracks.value)
          newTracks.set(email, markRaw(track))
          remoteScreenTracks.value = newTracks
          isDebugEnabled() && console.log(`[LiveKit] ScreenShare VIDEO track stored for ${email}, sid=${track.sid}`)
        } else {
          isDebugEnabled() && console.log(`[LiveKit] ScreenShare AUDIO subscribed from ${email}`)
        }

        const newSharers = new Set(screenSharingParticipants.value)
        newSharers.add(email)
        screenSharingParticipants.value = newSharers

        if (onRemoteTrackCb) {
          onRemoteTrackCb(email, track, screenStream)
        }
      } else {
        // Camera / microphone track — always create a fresh MediaStream so the
        // object reference changes, triggering Vue watchers in ParticipantTile.
        const newStreams = new Map(remoteStreams.value)
        const existingStream = newStreams.get(email)
        const stream = markRaw(new MediaStream())
        if (existingStream) {
          existingStream.getTracks().forEach(t => stream.addTrack(t))
        }
        addOrReplaceTrack(stream, track.mediaStreamTrack)
        newStreams.set(email, stream)
        remoteStreams.value = newStreams

        if (onRemoteTrackCb) {
          onRemoteTrackCb(email, track, stream)
        }
      }
    })

    // Track unsubscribed — a remote track was removed
    lkRoom.on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
      if (lkRoom !== room.value) return
      const email = String(participant.identity || '').toLowerCase()
      isDebugEnabled() && console.log(`[LiveKit] TrackUnsubscribed: kind=${track.kind} source=${track.source} from=${email}`)

      if (track.source === Track.Source.ScreenShare || track.source === Track.Source.ScreenShareAudio) {
        const screenStream = remoteScreenStreams.value.get(email)
        if (screenStream) {
          screenStream.removeTrack(track.mediaStreamTrack)
          const newScreenStreams = new Map(remoteScreenStreams.value)
          const newSharers = new Set(screenSharingParticipants.value)
          if (screenStream.getTracks().length === 0) {
            newScreenStreams.delete(email)
            newSharers.delete(email)
          }
          remoteScreenStreams.value = newScreenStreams
          screenSharingParticipants.value = newSharers
        }
        if (track.source === Track.Source.ScreenShare) {
          const newTracks = new Map(remoteScreenTracks.value)
          newTracks.delete(email)
          remoteScreenTracks.value = newTracks
        }
      } else {
        const stream = remoteStreams.value.get(email)
        if (stream) {
          stream.removeTrack(track.mediaStreamTrack)
          const newStreams = new Map(remoteStreams.value)
          if (stream.getTracks().length === 0) {
            newStreams.delete(email)
          }
          remoteStreams.value = newStreams
        }
      }
    })

    // Participant connected
    lkRoom.on(RoomEvent.ParticipantConnected, (participant) => {
      if (lkRoom !== room.value) return
      const pEmail = String(participant.identity || '').toLowerCase()
      isDebugEnabled() && console.log(`[LiveKit] ParticipantConnected: ${pEmail}, publications=${participant.trackPublications.size}`)
      for (const publication of participant.trackPublications.values()) {
        isDebugEnabled() && console.log(`[LiveKit]   -> force-subscribe: source=${publication.source} kind=${publication.kind} subscribed=${publication.isSubscribed}`)
        try { publication.setSubscribed(true) } catch (_) { /* ignore */ }
      }
      handleParticipantConnected(participant)
    })

    // Remote participant published a new track. Force subscription for robustness.
    lkRoom.on(RoomEvent.TrackPublished, (publication, participant) => {
      if (lkRoom !== room.value) return
      try {
        publication.setSubscribed(true)
        isDebugEnabled() && console.log(`[LiveKit] TrackPublished: source=${publication.source} kind=${publication.kind} from=${String(participant.identity || '').toLowerCase()} (forced subscribe)`)
      } catch (e) {
        console.warn('[LiveKit] TrackPublished force-subscribe failed:', e?.message || e)
      }
    })

    // Participant disconnected
    lkRoom.on(RoomEvent.ParticipantDisconnected, (participant) => {
      if (lkRoom !== room.value) return
      const email = String(participant.identity || '').toLowerCase()
      isDebugEnabled() && console.log(`[LiveKit] ParticipantDisconnected: ${email}`)

      // Clean up their streams — build all new collections once and assign
      const newStreams = new Map(remoteStreams.value)
      const newScreenStreams = new Map(remoteScreenStreams.value)
      const newSharers = new Set(screenSharingParticipants.value)
      const newStates = new Map(participantStates.value)
      newStreams.delete(email)
      newScreenStreams.delete(email)
      newSharers.delete(email)
      newStates.delete(email)
      remoteStreams.value = newStreams
      remoteScreenStreams.value = newScreenStreams
      screenSharingParticipants.value = newSharers
      participantStates.value = newStates

      if (onParticipantDisconnectedCb) {
        onParticipantDisconnectedCb(email)
      }
    })

    // Connection state changes
    lkRoom.on(RoomEvent.ConnectionStateChanged, (state) => {
      if (lkRoom !== room.value) return
      isDebugEnabled() && console.log(`[LiveKit] ConnectionStateChanged: ${state}`)

      switch (state) {
        case ConnectionState.Connected:
          connectionState.value = 'connected'
          break
        case ConnectionState.Reconnecting:
          connectionState.value = 'reconnecting'
          break
        case ConnectionState.Disconnected:
          connectionState.value = 'closed'
          break
        default:
          connectionState.value = state
      }

      if (onConnectionStateChangeCb) {
        onConnectionStateChangeCb(null, connectionState.value)
      }
    })

    // Disconnected event (with reason)
    lkRoom.on(RoomEvent.Disconnected, (reason) => {
      if (lkRoom !== room.value) return
      isDebugEnabled() && console.log(`[LiveKit] Disconnected: reason=${reason}`)
      connectionState.value = 'closed'
      speakingParticipants.value = new Set()
    })

    // Active speakers — drives the Teams-style speaking ring on tiles.
    // Identities are emails for in-app calls; local participant is included.
    lkRoom.on(RoomEvent.ActiveSpeakersChanged, (speakers) => {
      if (lkRoom !== room.value) return
      speakingParticipants.value = new Set(
        speakers.map(p => String(p.identity || '').toLowerCase()).filter(Boolean)
      )
      if (onSpeakingChangedCb) {
        onSpeakingChangedCb(speakingParticipants.value)
      }
    })

    // Reconnected — rebuild all stream maps from LiveKit's current state.
    // After a network hiccup LiveKit re-negotiates tracks; the old MediaStreamTrack
    // references may be dead. Reset to empty first to drop any tracks from participants
    // who stopped publishing during the outage, then rebuild clean from current state.
    lkRoom.on(RoomEvent.Reconnected, () => {
      if (lkRoom !== room.value) return
      isDebugEnabled() && console.log(`[LiveKit] Reconnected — re-syncing all streams, remoteParticipants=${lkRoom.remoteParticipants.size}`)
      connectionState.value = 'connected'

      // Reset first so stale tracks from participants who dropped a source are not kept
      const newStreams = new Map()
      const newScreenStreams = new Map()
      const newScreenTracks = new Map()
      const newSharers = new Set()

      for (const participant of lkRoom.remoteParticipants.values()) {
        const email = String(participant.identity || '').toLowerCase()

        for (const publication of participant.trackPublications.values()) {
          if (!publication.isSubscribed || !publication.track) continue
          const track = publication.track

          if (track.source === Track.Source.ScreenShare || track.source === Track.Source.ScreenShareAudio) {
            let screenStream = newScreenStreams.get(email)
            if (!screenStream) {
              screenStream = markRaw(new MediaStream())
              newScreenStreams.set(email, screenStream)
            }
            if (!screenStream.getTrackById(track.mediaStreamTrack.id)) {
              screenStream.addTrack(track.mediaStreamTrack)
            }
            if (track.source === Track.Source.ScreenShare) {
              newScreenTracks.set(email, markRaw(track))
            }
            newSharers.add(email)
          } else {
            let stream = newStreams.get(email)
            if (!stream) {
              stream = markRaw(new MediaStream())
              newStreams.set(email, stream)
            }
            if (!stream.getTrackById(track.mediaStreamTrack.id)) {
              stream.addTrack(track.mediaStreamTrack)
            }
          }
        }
      }

      remoteStreams.value = newStreams
      remoteScreenStreams.value = newScreenStreams
      remoteScreenTracks.value = newScreenTracks
      screenSharingParticipants.value = newSharers

      if (onConnectionStateChangeCb) {
        onConnectionStateChangeCb(null, 'connected')
      }
    })

    // Track stream state changed — LiveKit paused/resumed a remote video track
    // due to bandwidth adaptation. When a track resumes we must trigger a reactive
    // update so the <video> element re-renders with the live frames.
    lkRoom.on(RoomEvent.TrackStreamStateChanged, (publication, streamState, participant) => {
      if (lkRoom !== room.value) return
      const email = String(participant.identity || '').toLowerCase()
      isDebugEnabled() && console.log(`[LiveKit] TrackStreamStateChanged: source=${publication.source} kind=${publication.kind} state=${streamState} from=${email}`)

      // Force reactive update so watchers in ParticipantTile re-evaluate
      remoteStreams.value = new Map(remoteStreams.value)
      remoteScreenStreams.value = new Map(remoteScreenStreams.value)
    })

    // Track muted/unmuted (remote participant)
    lkRoom.on(RoomEvent.TrackMuted, (publication, participant) => {
      if (lkRoom !== room.value) return
      isDebugEnabled() && console.log(`[LiveKit] TrackMuted: ${publication.kind} source=${publication.source} from=${String(participant.identity || '').toLowerCase()}`)
    })

    lkRoom.on(RoomEvent.TrackUnmuted, (publication, participant) => {
      if (lkRoom !== room.value) return
      isDebugEnabled() && console.log(`[LiveKit] TrackUnmuted: ${publication.kind} source=${publication.source} from=${String(participant.identity || '').toLowerCase()}`)
    })
  }

  function handleParticipantConnected(participant) {
    const email = String(participant.identity || '').toLowerCase()
    isDebugEnabled() && console.log(`[LiveKit] handleParticipantConnected: ${email}, trackPublications=${participant.trackPublications.size}`)

    participantStates.value.set(email, 'connected')
    participantStates.value = new Map(participantStates.value)

    if (onParticipantConnectedCb) {
      onParticipantConnectedCb(email)
    }

    // Subscribe to existing tracks (for late joiners).
    // Build all collections outside the loop -- assign once at the end to avoid
    // creating a new Map/Set per track when a participant has multiple tracks.
    const newStreams = new Map(remoteStreams.value)
    const newScreenStreams = new Map(remoteScreenStreams.value)
    const newScreenTracks = new Map(remoteScreenTracks.value)
    const newSharers = new Set(screenSharingParticipants.value)
    let streamsChanged = false
    let screenStreamsChanged = false

    for (const publication of participant.trackPublications.values()) {
      if (publication.isSubscribed && publication.track) {
        const track = publication.track
        if (track.source === Track.Source.ScreenShare || track.source === Track.Source.ScreenShareAudio) {
          let screenStream = newScreenStreams.get(email)
          if (!screenStream) {
            screenStream = markRaw(new MediaStream())
            newScreenStreams.set(email, screenStream)
          }
          addOrReplaceTrack(screenStream, track.mediaStreamTrack)
          if (track.source === Track.Source.ScreenShare) {
            newScreenTracks.set(email, markRaw(track))
          }
          newSharers.add(email)
          screenStreamsChanged = true
        } else {
          const existingStream = newStreams.get(email)
          const stream = markRaw(new MediaStream())
          if (existingStream) {
            existingStream.getTracks().forEach(t => stream.addTrack(t))
          }
          addOrReplaceTrack(stream, track.mediaStreamTrack)
          newStreams.set(email, stream)
          streamsChanged = true
        }
      }
    }

    if (streamsChanged) remoteStreams.value = newStreams
    if (screenStreamsChanged) {
      remoteScreenStreams.value = newScreenStreams
      remoteScreenTracks.value = newScreenTracks
      screenSharingParticipants.value = newSharers
    }
  }

  // ── Local track publishing ──────────────────────────────────────────

  /**
   * Publish local audio/video tracks to the room.
   * @param {MediaStream} stream - Local media stream from getUserMedia
   */
  const VIDEO_ENCODING = { maxBitrate: 1_700_000, maxFramerate: 30 }
  const VIDEO_SIMULCAST_LAYERS = [
    { width: 640, height: 360, encoding: { maxBitrate: 500_000, maxFramerate: 25 } },
    { width: 320, height: 180, encoding: { maxBitrate: 150_000, maxFramerate: 15 } },
  ]

  async function publishLocalTracks(stream) {
    if (!room.value) return

    const localParticipant = room.value.localParticipant

    for (const track of stream.getTracks()) {
      if (!room.value) break
      if (track.readyState !== 'live') continue

      const source = track.kind === 'audio' ? Track.Source.Microphone : Track.Source.Camera
      const opts = { source }
      if (track.kind === 'video') {
        opts.simulcast = true
        opts.videoEncoding = VIDEO_ENCODING
        opts.videoSimulcastLayers = VIDEO_SIMULCAST_LAYERS
      }
      isDebugEnabled() && console.log(`[LiveKit] Publishing local track: ${track.kind} (label: ${track.label}, source: ${source})`)
      await localParticipant.publishTrack(track, opts)
    }
  }

  /**
   * Publish a single local track.
   * @param {MediaStreamTrack} track - The track to publish
   * @param {string} [source] - Track source type ('camera', 'microphone', 'screen_share')
   */
  async function publishTrack(track, source = Track.Source.Camera) {
    if (!room.value) return

    const opts = { source }
    if (track.kind === 'video') {
      opts.simulcast = true
      opts.videoEncoding = VIDEO_ENCODING
      opts.videoSimulcastLayers = VIDEO_SIMULCAST_LAYERS
    }
    await room.value.localParticipant.publishTrack(track, opts)
  }

  /**
   * Unpublish a specific local track.
   * @param {MediaStreamTrack} track - The track to unpublish
   */
  async function unpublishTrack(track) {
    if (!room.value) return

    const localParticipant = room.value.localParticipant
    for (const publication of localParticipant.trackPublications.values()) {
      if (publication.track?.mediaStreamTrack?.id === track.id) {
        await localParticipant.unpublishTrack(track)
        break
      }
    }
  }

  // ── Mute / unmute ───────────────────────────────────────────────────

  /**
   * Mute or unmute local microphone.
   * LiveKit handles track muting/unmuting internally.
   *
   * @param {boolean} enabled
   * @param {Object}  [opts]            Optional capture options.
   * @param {string}  [opts.deviceId]   Microphone device ID to use when enabling.
   */
  async function setMicrophoneEnabled(enabled, opts = {}) {
    if (!room.value) return null
    const captureOptions = (enabled && opts.deviceId)
      ? { deviceId: opts.deviceId }
      : undefined
    await room.value.localParticipant.setMicrophoneEnabled(enabled, captureOptions)
    if (enabled) {
      for (const pub of room.value.localParticipant.trackPublications.values()) {
        if (pub.source === Track.Source.Microphone && pub.track) {
          return pub.track.mediaStreamTrack
        }
      }
    }
    return null
  }

  /**
   * Enable or disable local camera.
   * Returns the new camera MediaStreamTrack when enabling (for local preview), null otherwise.
   *
   * @param {boolean} enabled
   * @param {Object}  [opts]            Optional capture options.
   * @param {string}  [opts.deviceId]   Camera device ID to use when enabling.
   */
  async function setCameraEnabled(enabled, opts = {}) {
    if (!room.value) return null
    const captureOptions = (enabled && opts.deviceId)
      ? { deviceId: opts.deviceId }
      : undefined
    try {
      await room.value.localParticipant.setCameraEnabled(enabled, captureOptions)
    } catch (e) {
      // Some browser/livekit combinations throw DataCloneError during
      // getUserMedia constraint cloning. Fallback to muting existing camera track.
      const cameraPub = room.value.localParticipant.getTrackPublication('camera')
      if (cameraPub?.track) {
        try {
          if (enabled) {
            await cameraPub.track.unmute()
          } else {
            await cameraPub.track.mute()
          }
        } catch (_) {
          throw e
        }
      } else {
        throw e
      }
    }
    if (enabled) {
      // Return the LiveKit-managed camera track for local preview
      for (const pub of room.value.localParticipant.trackPublications.values()) {
        if (pub.source === Track.Source.Camera && pub.track && pub.track.kind === 'video') {
          return pub.track.mediaStreamTrack
        }
      }
    }
    return null
  }

  // ── Screen sharing ──────────────────────────────────────────────────

  /**
   * Start or stop screen sharing.
   * @param {boolean} enabled
   * @returns {MediaStreamTrack|null} The screen share track if started
   */
  async function setScreenShareEnabled(enabled) {
    if (!room.value) return null

    if (enabled) {
      await room.value.localParticipant.setScreenShareEnabled(true, {
        audio: true,
        resolution: { width: 1920, height: 1080, frameRate: 30 },
        contentHint: 'detail',
        surfaceSwitching: 'include',
      }, {
        videoEncoding: ScreenSharePresets.h1080fps30.encoding,
        screenShareEncoding: ScreenSharePresets.h1080fps30.encoding,
      })
      for (const pub of room.value.localParticipant.trackPublications.values()) {
        if (pub.source === Track.Source.ScreenShare && pub.track) {
          return pub.track.mediaStreamTrack
        }
      }
      return null
    } else {
      await room.value.localParticipant.setScreenShareEnabled(false)
      return null
    }
  }

  // ── Audio device switching ──────────────────────────────────────────

  /**
   * Switch the active microphone device.
   * @param {string} deviceId
   */
  async function switchMicrophone(deviceId) {
    if (!room.value) return
    await room.value.switchActiveDevice('audioinput', deviceId)
  }

  /**
   * Switch the active camera device.
   * @param {string} deviceId
   */
  async function switchCamera(deviceId) {
    if (!room.value) return
    await room.value.switchActiveDevice('videoinput', deviceId)
  }

  /**
   * Switch the active audio output device.
   * @param {string} deviceId
   */
  async function switchAudioOutput(deviceId) {
    if (!room.value) return
    await room.value.switchActiveDevice('audiooutput', deviceId)
  }

  /**
   * Resume blocked audio playback. iOS/Safari (and a CallKit-answered call,
   * where the WebView never received a web user gesture) block WebRTC audio
   * autoplay; LiveKit's startAudio() resumes the shared AudioContext. Must be
   * invoked from within a user gesture to succeed. Safe to call repeatedly.
   */
  async function startAudio() {
    if (!room.value) return
    try { await room.value.startAudio() } catch (_e) { /* not blocked / no gesture */ }
  }

  // ── Callbacks ───────────────────────────────────────────────────────

  /**
   * Set event callbacks (compatibility with call store patterns).
   */
  function setCallbacks({
    onRemoteTrackCb: remoteCb = null,
    onConnectionStateChangeCb: connCb = null,
    onParticipantConnectedCb: joinCb = null,
    onParticipantDisconnectedCb: leaveCb = null,
    onSpeakingChangedCb: speakingCb = null,
  } = {}) {
    onRemoteTrackCb = remoteCb
    onConnectionStateChangeCb = connCb
    onParticipantConnectedCb = joinCb
    onParticipantDisconnectedCb = leaveCb
    onSpeakingChangedCb = speakingCb
  }

  // ── Stats ───────────────────────────────────────────────────────────

  /**
   * Get participant count (including local).
   */
  function getParticipantCount() {
    if (!room.value) return 0
    return room.value.remoteParticipants.size + 1
  }

  /**
   * Check if we are the only participant in the room.
   */
  function isAlone() {
    return getParticipantCount() <= 1
  }

  return {
    // State
    room,
    remoteStreams,
    remoteScreenStreams,
    remoteScreenTracks,
    screenSharingParticipants,
    speakingParticipants,
    connectionState,
    participantStates,

    // Connection
    fetchToken,
    connect,
    disconnect,

    // Track management
    publishLocalTracks,
    publishTrack,
    unpublishTrack,

    // Media controls
    setMicrophoneEnabled,
    setCameraEnabled,
    setScreenShareEnabled,

    // Device switching
    switchMicrophone,
    switchCamera,
    switchAudioOutput,
    startAudio,

    // Callbacks
    setCallbacks,

    // Utilities
    getParticipantCount,
    isAlone,
  }
}

