<script setup>
import { ref, watch, computed, onMounted, onUnmounted, nextTick, toRaw } from 'vue'
import { isDebugEnabled } from '@/utils/debug'
import { useCallStore } from '@/stores/call'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const callStore = useCallStore()

const props = defineProps({
  stream: {
    type: MediaStream,
    default: null
  },
  name: {
    type: String,
    default: 'Unknown'
  },
  email: {
    type: String,
    default: ''
  },
  avatar: {
    type: String,
    default: null
  },
  isLocal: {
    type: Boolean,
    default: false
  },
  mediaState: {
    type: Object,
    default: () => ({ audio: true, video: false, screenShare: false })
  },
  compact: {
    type: Boolean,
    default: false
  },
  status: {
    type: String,
    default: 'connected' // 'pending' | 'connected' | 'rejected' | 'left'
  },
  /** Teams-style speaking highlight (green ring around the tile) */
  isSpeaking: {
    type: Boolean,
    default: false
  }
})

const videoRef = ref(null)
const audioRef = ref(null)
const hasVideo = ref(false)

// Unwrap Vue's reactive Proxy — browser APIs (srcObject, getVideoTracks, etc.)
// need the real MediaStream object, not a Proxy wrapper.
const rawStream = computed(() => props.stream ? toRaw(props.stream) : null)

// Track event listeners and pending timers for cleanup
let currentStream = null
let trackPollTimer = null
const _pendingTimers = []

// Wraps setTimeout so every handle is tracked and can be cancelled on unmount
function scheduleCheck(fn, delay) {
  const id = setTimeout(() => {
    const idx = _pendingTimers.indexOf(id)
    if (idx !== -1) _pendingTimers.splice(idx, 1)
    fn()
  }, delay)
  _pendingTimers.push(id)
  return id
}

/**
 * For REMOTE participants, the signaling (mediaState) is the authority
 * for whether they have video on. WebRTC tracks remain live/enabled
 * even when the sender disables their camera (sending black frames).
 * So we must trust mediaState.video for remote participants.
 */
const effectiveHasVideo = computed(() => {
  if (props.isLocal) {
    // Local: trust actual track state
    return hasVideo.value
  }
  // Remote: trust signaling for camera video only.
  // Screen share is rendered in the presentation view, not the participant tile.
  const signalSaysVideo = props.mediaState?.video || false
  if (!signalSaysVideo) return false
  // If signaling says video but no tracks yet, still show avatar
  return hasVideo.value
})

/**
 * The SOLE authority for hasVideo state.
 * Only actual live video tracks in the stream set this to true.
 */
function checkVideoTracks() {
  const stream = rawStream.value
  if (!stream) {
    if (hasVideo.value) {
      hasVideo.value = false
    }
    return
  }
  
  const tracks = stream.getVideoTracks()
  const hadVideo = hasVideo.value
  const nowHasVideo = tracks.some(t => t.readyState === 'live')
  
  if (nowHasVideo !== hadVideo) {
    isDebugEnabled() && console.log(`[ParticipantTile] hasVideo: ${hadVideo} -> ${nowHasVideo} for ${props.name} (${props.email}) | tracks: ${tracks.map(t => `${t.kind}:${t.readyState}:${t.enabled}`).join(', ')}`)
    hasVideo.value = nowHasVideo
    
    if (nowHasVideo && videoRef.value) {
      nextTick(() => {
        if (videoRef.value) {
          if (videoRef.value.srcObject !== stream) {
            videoRef.value.srcObject = stream
          }
          if (videoRef.value.paused) {
            videoRef.value.play().catch(e => {
              console.warn('[ParticipantTile] Video play failed:', e.message)
            })
          }
        }
      })
    }
  }
}

function attachStream() {
  if (currentStream) {
    currentStream.removeEventListener('addtrack', onTrackChange)
    currentStream.removeEventListener('removetrack', onTrackChange)
    currentStream = null
  }
  
  const stream = rawStream.value
  if (stream) {
    isDebugEnabled() && console.log(`[ParticipantTile] attachStream for ${props.name}: audio=${stream.getAudioTracks().length}, video=${stream.getVideoTracks().length}`)
    
    if (videoRef.value) {
      videoRef.value.srcObject = stream
    }
    
    stream.addEventListener('addtrack', onTrackChange)
    stream.addEventListener('removetrack', onTrackChange)
    currentStream = stream
    
    if (!props.isLocal && audioRef.value) {
      audioRef.value.srcObject = stream
      audioRef.value.play().catch(e => {
        if (e.name === 'NotAllowedError') callStore.notifyAudioBlocked()
        console.warn('[ParticipantTile] Audio autoplay blocked:', e.message)
      })
    }
    
    checkVideoTracks()
    
    const attachedStream = stream
    scheduleCheck(() => { if (rawStream.value === attachedStream) checkVideoTracks() }, 100)
    scheduleCheck(() => { if (rawStream.value === attachedStream) checkVideoTracks() }, 500)
    scheduleCheck(() => { if (rawStream.value === attachedStream) checkVideoTracks() }, 1500)
    
  } else {
    hasVideo.value = false
    if (audioRef.value) {
      audioRef.value.srcObject = null
    }
  }
}

function onTrackChange(event) {
  isDebugEnabled() && console.log(`[ParticipantTile] Track ${event.type}: ${event.track?.kind} (${event.track?.readyState}, enabled=${event.track?.enabled}) for ${props.name}`)
  
  if (!hasVideo.value && videoRef.value && rawStream.value) {
    videoRef.value.srcObject = rawStream.value
  }
  
  checkVideoTracks()
  
  // After any track change, re-ensure audio is playing
  // SDP renegotiation (e.g. adding video to a voice call) can disrupt audio playback
  ensureAudioPlaying()
  
  // Also re-check after a short delay (track readyState can change)
  scheduleCheck(checkVideoTracks, 200)
  scheduleCheck(ensureAudioPlaying, 300)
}

/**
 * Ensure the audio element is actively playing the remote stream.
 * Called after track changes and SDP renegotiation to recover audio.
 */
function ensureAudioPlaying() {
  const stream = rawStream.value
  if (props.isLocal || !audioRef.value || !stream) return
  
  if (audioRef.value.dataset.autoblocked === '1') return
  
  const audioTracks = stream.getAudioTracks()
  if (audioTracks.length === 0) return
  
  if (audioRef.value.srcObject !== stream) {
    audioRef.value.srcObject = stream
  }
  
  // Try to play if paused
  if (audioRef.value.paused) {
    audioRef.value.play().catch(e => {
      if (e.name === 'NotAllowedError') {
        // Browser blocked autoplay (iOS: no user gesture, e.g. CallKit accept).
        // Stop retrying silently, but flag the call so the overlay can offer a
        // tap-to-enable-sound affordance whose gesture resumes playback.
        audioRef.value.dataset.autoblocked = '1'
        callStore.notifyAudioBlocked()
        console.warn('[ParticipantTile] Audio autoplay blocked by browser policy:', e.message)
      } else {
        console.warn('[ParticipantTile] Audio re-play failed:', e.message)
      }
    })
  }
}

watch(() => props.stream, attachStream, { flush: 'post' })

// Watch media state changes from signaling (CALL_MEDIA_STATE, SCREEN_SHARE events)
// Instead of directly setting hasVideo, use this as a TRIGGER to re-check actual tracks.
// This avoids the conflict between signaling state and actual track state.
// audio is intentionally excluded — mic mute/unmute changes do not affect video track state.
watch(
  () => [props.mediaState?.video, props.mediaState?.screenShare],
  ([video, screenShare]) => {
    const shouldHaveVideo = video || screenShare || false
    
    if (shouldHaveVideo && rawStream.value) {
      // Re-check actual tracks with escalating delays
      // (SDP renegotiation + ontrack may take a moment)
      checkVideoTracks()
      scheduleCheck(checkVideoTracks, 200)
      scheduleCheck(checkVideoTracks, 500)
      scheduleCheck(checkVideoTracks, 1000)
      scheduleCheck(checkVideoTracks, 2000)
    } else if (!shouldHaveVideo) {
      // Media state says no video - trust it and re-check
      checkVideoTracks()
    }
    
    // After any media state change, re-ensure audio is playing
    // This handles the case where SDP renegotiation disrupts audio
    ensureAudioPlaying()
    scheduleCheck(ensureAudioPlaying, 500)
    scheduleCheck(ensureAudioPlaying, 1500)
  }
)

onMounted(() => {
  attachStream()
  
  // Polling fallback: check for video tracks when video isn't showing yet
  // Also periodically ensure audio is playing (handles autoplay policy and renegotiation recovery)
  trackPollTimer = setInterval(() => {
    if (rawStream.value) {
      if (!hasVideo.value) {
        checkVideoTracks()
      }
      // Periodically ensure audio is playing (recovery mechanism)
      ensureAudioPlaying()
    }
  }, 3000)
})

onUnmounted(() => {
  // Cancel all pending delayed checks so they cannot fire against unmounted refs
  for (const id of _pendingTimers) clearTimeout(id)
  _pendingTimers.length = 0

  // Clean up stream listeners
  if (currentStream) {
    currentStream.removeEventListener('addtrack', onTrackChange)
    currentStream.removeEventListener('removetrack', onTrackChange)
    currentStream = null
  }
  // Clean up polling
  if (trackPollTimer) {
    clearInterval(trackPollTimer)
    trackPollTimer = null
  }
  // Clean up media elements to release stream references and prevent memory leaks
  if (videoRef.value) {
    videoRef.value.srcObject = null
  }
  if (audioRef.value) {
    audioRef.value.srcObject = null
  }
})

</script>

<template>
  <div 
    :class="[
      'participant-tile relative rounded-2xl overflow-hidden bg-surface-900 flex items-center justify-center h-full w-full transition-shadow duration-150',
      compact ? 'aspect-video' : '',
      isSpeaking ? 'ring-2 ring-green-400' : ''
    ]"
  >
    <!-- Audio element for remote participants (always plays, not affected by v-show) -->
    <!-- v-show keeps the DOM element alive through parent re-renders, preserving playback state -->
    <audio
      v-show="!isLocal"
      ref="audioRef"
      autoplay
      playsinline
      data-call-audio
      class="hidden"
    />
    
    <!-- Video feed (always muted - audio comes from the separate audio element for remote) -->
    <video
      v-show="effectiveHasVideo"
      ref="videoRef"
      autoplay
      playsinline
      muted
      class="w-full h-full object-cover"
    />
    
    <!-- Avatar placeholder (no video) — hidden when pending since the pending overlay has its own avatar -->
    <div 
      v-if="!effectiveHasVideo && (isLocal || status !== 'pending')"
      class="flex flex-col items-center justify-center gap-2"
    >
      <UserAvatar
        :email="email"
        :name="name"
        :size="compact ? 'lg' : '3xl'"
      />
      <span :class="['text-white/80 font-medium truncate max-w-full px-2', compact ? 'text-[10px]' : 'text-sm']">{{ name }}</span>
    </div>
    
    <!-- Pending/Connecting overlay — self-contained with avatar + name so nothing bleeds through -->
    <div 
      v-if="status === 'pending' && !isLocal"
      class="absolute inset-0 bg-surface-900 flex flex-col items-center justify-center z-10"
    >
      <!-- Avatar in overlay -->
      <div class="relative mb-3">
        <div class="opacity-60 ring-2 ring-white/15 rounded-full">
          <UserAvatar
            :email="email"
            :name="name"
            :size="compact ? 'lg' : '3xl'"
          />
        </div>
        <!-- Small ringing badge on avatar -->
        <span 
          :class="[
            'absolute -bottom-1 -right-1 rounded-full bg-surface-800 flex items-center justify-center animate-pulse',
            compact ? 'w-4 h-4' : 'w-6 h-6'
          ]"
        >
          <span :class="['material-symbols-rounded text-amber-400', compact ? 'text-[10px]' : 'text-sm']">ring_volume</span>
        </span>
      </div>
      <span :class="['text-white/80 font-medium truncate max-w-full px-2', compact ? 'text-[10px]' : 'text-sm']">{{ name }}</span>
      <span :class="['text-white/50 font-medium mt-0.5', compact ? 'text-[8px]' : 'text-xs']">Ringing...</span>
    </div>
    
    <!-- Status indicators (bottom overlay) -->
    <div class="absolute bottom-1.5 left-1.5 right-1.5 flex items-center justify-between">
      <span v-if="effectiveHasVideo" class="text-[11px] text-white/90 font-medium bg-black/50 px-2 py-0.5 rounded-full truncate max-w-[70%]">
        {{ name }}{{ isLocal ? ' (You)' : '' }}
      </span>
      <span v-else class="text-[0px]">&nbsp;</span>
      
      <div class="flex items-center gap-1">
        <!-- Audio muted indicator -->
        <span 
          v-if="!mediaState?.audio"
          class="w-5 h-5 rounded-full bg-red-500/80 flex items-center justify-center"
          title="Muted"
        >
          <span class="material-symbols-rounded text-white text-[11px]">mic_off</span>
        </span>
        
        <!-- Screen sharing indicator -->
        <span 
          v-if="mediaState?.screenShare"
          class="w-5 h-5 rounded-full bg-green-500/80 flex items-center justify-center"
          title="Sharing screen"
        >
          <span class="material-symbols-rounded text-white text-[11px]">screen_share</span>
        </span>
      </div>
    </div>
  </div>
</template>
