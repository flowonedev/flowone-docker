<script setup>
import { computed, ref, watch, onMounted, onUnmounted, nextTick, toRaw } from 'vue'
import { useCallStore } from '@/stores/call'
import { useAuthStore } from '@/stores/auth'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { isDebugEnabled } from '@/utils/debug'
import CallControls from './CallControls.vue'
import CallSecurityBadge from './CallSecurityBadge.vue'
import ParticipantTile from './ParticipantTile.vue'

const callStore = useCallStore()
const authStore = useAuthStore()
const colleaguesStore = useColleaguesStore()
const localEmail = computed(() => authStore.userEmail || '')
const localDisplayName = computed(() => authStore.displayName || 'You')

// Teams-style speaking ring — emails currently speaking (incl. local)
function isSpeaking(email) {
  return !!email && callStore.speakingParticipants.has(String(email).toLowerCase())
}
const localAvatarUrl = computed(() => {
  const col = colleaguesStore.colleagueByEmail?.[authStore.userEmail?.toLowerCase()]
  if (col) return colleaguesStore.getAvatarUrl(col) || ''
  return ''
})

// Timer for call duration display
const displayDuration = ref(0)
let durationTimer = null

const formattedDuration = computed(() => {
  const hrs = Math.floor(displayDuration.value / 3600)
  const mins = Math.floor((displayDuration.value % 3600) / 60)
  const secs = displayDuration.value % 60
  if (hrs > 0) {
    return `${hrs}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
  }
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
})

const statusText = computed(() => {
  switch (callStore.callStatus) {
    case 'initiating': return 'Connecting...'
    case 'ringing': return callStore.callDirection === 'outgoing' ? 'Ringing...' : 'Incoming call'
    case 'active': return ''  // Timer shown separately now
    case 'ended': return callStore.callError || 'Call ended'
    default: return ''
  }
})

// iOS blocks WebRTC audio autoplay when the call is answered from the native
// CallKit screen (no web user gesture). Try once when the call goes active
// (succeeds if the CallKit audio session already permits it); otherwise the
// "Tap to enable sound" button / first tap resumes playback within a gesture.
function enableSound() {
  callStore.enableAudioPlayback()
}

function onOverlayPointerDown() {
  if (callStore.audioPlaybackBlocked) callStore.enableAudioPlayback()
}

watch(() => callStore.callStatus, (s) => {
  if (s === 'active') callStore.enableAudioPlayback()
})

const callTypeIcon = computed(() => {
  return callStore.callType === 'video' ? 'videocam' : 'call'
})

// Single source of truth for remote screen share — set directly from LiveKit track callback
const activeScreenShare = ref(null) // { email, name, track } or null

const isAnyoneScreenSharing = computed(() => {
  return callStore.isScreenSharing || !!activeScreenShare.value
})

// Active (connected) participants - filter out rejected/left
const activeParticipants = computed(() => {
  return callStore.participants.filter(p => p.status !== 'rejected' && p.status !== 'left')
})

// Pending (still ringing) participants
const pendingParticipants = computed(() => {
  return callStore.participants.filter(p => p.status === 'pending')
})

// Grid layout based on participant count (used in non-presentation mode)
const gridClass = computed(() => {
  const count = activeParticipants.value.length + 1 // +1 for self
  if (count <= 1) return 'grid-cols-1'
  if (count === 2) return 'grid-cols-1 md:grid-cols-2'
  if (count <= 4) return 'grid-cols-2'
  if (count <= 6) return 'grid-cols-2 md:grid-cols-3'
  return 'grid-cols-3'
})

// ============================================
// FOCUSED MODE (Messenger / WhatsApp style)
// Mobile 1:1 calls: double-tap to fullscreen one participant,
// the other becomes a small draggable PiP in the corner.
// ============================================

// 'self' = local fullscreen, participant email = remote fullscreen, null = normal grid
const focusedEmail = ref(null)

// Mobile detection
const isMobile = computed(() => {
  if (typeof navigator === 'undefined') return false
  const ua = navigator.userAgent || ''
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua)
})

// Only enable focused mode for mobile 1:1 calls without screen sharing
const canUseFocusedMode = computed(() => {
  return isMobile.value && activeParticipants.value.length === 1 && !isAnyoneScreenSharing.value
})

// Auto-exit focused mode when conditions change (e.g. 3rd participant joins, screen share starts)
watch(canUseFocusedMode, (can) => {
  if (!can) focusedEmail.value = null
})

// Double-tap detection
let lastTapTime = 0
let lastTapTarget = null

function handleTileTap(target) {
  if (!canUseFocusedMode.value) return
  
  const now = Date.now()
  if (now - lastTapTime < 350 && lastTapTarget === target) {
    // Double-tap detected
    if (focusedEmail.value === target) {
      // Already focused on this tile — exit focused mode
      focusedEmail.value = null
    } else {
      focusedEmail.value = target
    }
    lastTapTime = 0
    lastTapTarget = null
  } else {
    lastTapTime = now
    lastTapTarget = target
  }
}

// Swap: tap PiP to swap it to fullscreen
function handlePipTap() {
  if (!focusedEmail.value) return
  // Swap: if remote is fullscreen, switch to local fullscreen and vice versa
  if (focusedEmail.value === 'self') {
    focusedEmail.value = activeParticipants.value[0]?.email || null
  } else {
    focusedEmail.value = 'self'
  }
}

// The focused (fullscreen) participant data
const focusedParticipant = computed(() => {
  if (!focusedEmail.value) return null
  if (focusedEmail.value === 'self') return { type: 'local' }
  const p = activeParticipants.value.find(p => p.email === focusedEmail.value)
  return p ? { type: 'remote', ...p } : null
})

// PiP dragging
const pipPosition = ref({ x: 16, y: 80 }) // top-right offset (right, top)
let pipDragging = false
let pipDragOffset = { x: 0, y: 0 }

function onPipTouchStart(e) {
  if (e.touches.length !== 1) return
  pipDragging = true
  const touch = e.touches[0]
  const el = e.currentTarget
  const rect = el.getBoundingClientRect()
  pipDragOffset = {
    x: touch.clientX - rect.left,
    y: touch.clientY - rect.top
  }
}

function onPipTouchMove(e) {
  if (!pipDragging || e.touches.length !== 1) return
  e.preventDefault()
  const touch = e.touches[0]
  const vw = window.innerWidth
  const vh = window.innerHeight
  const pipW = 120
  const pipH = 170

  // Position as left/top
  let newLeft = touch.clientX - pipDragOffset.x
  let newTop = touch.clientY - pipDragOffset.y

  // Clamp to viewport
  newLeft = Math.max(8, Math.min(vw - pipW - 8, newLeft))
  newTop = Math.max(8, Math.min(vh - pipH - 8, newTop))

  pipPosition.value = { x: newLeft, y: newTop }
}

function onPipTouchEnd() {
  if (!pipDragging) return
  pipDragging = false

  // Snap to nearest horizontal edge (left or right)
  const vw = window.innerWidth
  const pipW = 120
  const centerX = pipPosition.value.x + pipW / 2

  if (centerX < vw / 2) {
    // Snap to left
    pipPosition.value = { ...pipPosition.value, x: 12 }
  } else {
    // Snap to right
    pipPosition.value = { ...pipPosition.value, x: vw - pipW - 12 }
  }
}

// Reset PiP position when entering focused mode
watch(focusedEmail, (val) => {
  if (val) {
    const vw = typeof window !== 'undefined' ? window.innerWidth : 400
    pipPosition.value = { x: vw - 132, y: 80 }
  }
})

// Participants sidebar state during screen share
const sidebarOpen = ref(true)

// Fullscreen state for the screen share area
const isScreenShareFullscreen = ref(false)
const screenShareContainerRef = ref(null)

function toggleScreenShareFullscreen() {
  if (!screenShareContainerRef.value) return
  if (document.fullscreenElement) {
    document.exitFullscreen().catch(() => {})
  } else {
    screenShareContainerRef.value.requestFullscreen().catch(() => {})
  }
}

function handleFullscreenChange() {
  isScreenShareFullscreen.value = !!document.fullscreenElement
}

// Shared surface display label for the sharer
const screenShareLabel = computed(() => {
  const surface = callStore.screenShareSurface
  if (!surface) return 'You are sharing your screen'
  switch (surface) {
    case 'monitor': return 'Sharing: Entire Screen'
    case 'window': return 'Sharing: Application Window'
    case 'browser': return 'Sharing: Browser Tab'
    default: return 'You are sharing your screen'
  }
})

// Local screen share video ref
const localScreenVideoRef = ref(null)

// Remote screen share container — track.attach() creates the <video> inside
const remoteScreenContainerRef = ref(null)
const _attachedScreenTrack = new WeakMap()

// Attach local screen share stream to video element
function setLocalScreenVideo(stream) {
  const el = localScreenVideoRef.value
  if (!el) return false
  const rawStream = stream ? toRaw(stream) : null
  if (el.srcObject !== rawStream) el.srcObject = rawStream
  if (stream && el.paused) {
    el.play().catch(e => console.warn('[CallOverlay] Local screen autoplay blocked:', e.message))
  }
  return true
}

// Attach remote screen share using LiveKit track.attach() (same pattern as CRM Pro VideoCallRoom)
function attachScreenTrack(el, track) {
  if (!el || !track) return
  if (_attachedScreenTrack.get(el) === track) return
  const existing = el.querySelector('video, canvas')
  if (existing) existing.remove()
  const mediaEl = track.attach()
  mediaEl.style.width = '100%'
  mediaEl.style.height = '100%'
  mediaEl.style.objectFit = 'contain'
  el.appendChild(mediaEl)
  _attachedScreenTrack.set(el, track)
}

function detachScreenTrack(el) {
  if (!el) return
  const existing = el.querySelector('video, canvas')
  if (existing) existing.remove()
  _attachedScreenTrack.delete(el)
}

// Watch local screen share stream
watch(
  [() => callStore.screenStream, () => callStore.isScreenSharing],
  ([stream, isSharing]) => {
    if (isSharing && stream) {
      if (!setLocalScreenVideo(stream)) {
        nextTick(() => setLocalScreenVideo(stream))
      }
    } else {
      setLocalScreenVideo(null)
    }
  },
  { immediate: true, flush: 'post' }
)

watch(localScreenVideoRef, (el) => {
  if (el && callStore.isScreenSharing && callStore.screenStream) {
    setLocalScreenVideo(callStore.screenStream)
  }
})

// When activeScreenShare changes, use track.attach() on the container div
watch(activeScreenShare, async (ss) => {
  await nextTick()
  const el = remoteScreenContainerRef.value
  if (ss?.track) {
    if (el) attachScreenTrack(el, ss.track)
  } else {
    detachScreenTrack(el)
  }
}, { flush: 'post' })

// When the container ref first appears (template switches to presentation layout), attach
watch(remoteScreenContainerRef, (el) => {
  if (el && activeScreenShare.value?.track) {
    attachScreenTrack(el, activeScreenShare.value.track)
  }
})

// Clear activeScreenShare when the remote participant stops sharing
// (LiveKit removes from remoteScreenTracks on TrackUnsubscribed)
watch(
  () => callStore.remoteScreenTracks,
  (tracks) => {
    if (activeScreenShare.value && !tracks.has(activeScreenShare.value.email)) {
      activeScreenShare.value = null
    }
  },
  { flush: 'post' }
)

onMounted(() => {
  document.addEventListener('fullscreenchange', handleFullscreenChange)

  // If we mounted already-active (e.g. answered from CallKit), try to resume
  // audio immediately — the first tap / sound button covers the iOS-blocked case.
  if (callStore.callStatus === 'active') callStore.enableAudioPlayback()

  durationTimer = setInterval(() => {
    if (callStore.callAnswerTime) {
      displayDuration.value = Math.floor((Date.now() - callStore.callAnswerTime) / 1000)
    }
  }, 1000)

  // Register for screen share tracks — LiveKit delivers the track object directly
  callStore.onScreenShareTrack((lkTrack, email) => {
    isDebugEnabled() && console.log(`[CallOverlay] onScreenShareTrack: email=${email}, kind=${lkTrack?.kind}`)
    const participant = callStore.participants.find(p => p.email === email)
    activeScreenShare.value = {
      email,
      name: participant?.name || email?.split('@')[0] || 'Unknown',
      track: lkTrack
    }
  })

  // Handle case where screen share was already active before mount
  if (!callStore.isScreenSharing && callStore.remoteScreenTracks.size > 0) {
    const [email, track] = callStore.remoteScreenTracks.entries().next().value || []
    if (email && track) {
      const participant = callStore.participants.find(p => p.email === email)
      activeScreenShare.value = {
        email,
        name: participant?.name || email?.split('@')[0] || 'Unknown',
        track
      }
    }
  }
})

onUnmounted(() => {
  document.removeEventListener('fullscreenchange', handleFullscreenChange)
  if (document.fullscreenElement) {
    document.exitFullscreen().catch(() => {})
  }
  if (durationTimer) {
    clearInterval(durationTimer)
  }
  callStore.offScreenShareTrack()
  detachScreenTrack(remoteScreenContainerRef.value)
})
</script>

<template>
  <div class="fixed inset-0 z-[9999] bg-surface-950 flex flex-col" @pointerdown="onOverlayPointerDown">
    <!-- Top bar (Teams-style: title + controls + actions).
         safe-area-top/x keep the row (and the hang-up button it holds) below the
         iOS status bar / Dynamic Island — without it the overlay is fixed inset-0
         and the controls render under the notch, unreachable on mobile. -->
    <div class="flex items-center gap-3 px-4 sm:px-6 pb-3 shrink-0 border-b border-white/5 safe-area-top safe-area-x">
      <!-- Left: call type, status, timer, encryption shield -->
      <div class="flex items-center gap-2.5 sm:gap-3 flex-1 min-w-0">
        <span class="material-symbols-rounded text-white/60 shrink-0">{{ callTypeIcon }}</span>
        <div class="hidden sm:block min-w-0">
          <h3 class="text-white font-medium text-sm truncate">
            {{ callStore.callType === 'video' ? 'Video Call' : 'Voice Call' }}
          </h3>
          <p v-if="statusText" class="text-white/50 text-xs truncate">{{ statusText }}</p>
        </div>
        <!-- Call duration timer -->
        <div
          v-if="callStore.callStatus === 'active'"
          class="hidden md:flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-full shrink-0"
        >
          <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
          <span class="text-white font-mono text-sm font-medium tracking-wider">{{ formattedDuration }}</span>
        </div>
        <!-- Encryption shield -->
        <CallSecurityBadge v-if="callStore.callStatus === 'active'" compact class="shrink-0" />
      </div>

      <!-- Center: call controls moved up into the top bar. Shown for the whole
           in-call lifecycle (connecting / ringing / active) so an outgoing call
           can always be hung up — previously the only hang-up button was hidden
           until the call went active, stranding the caller while it rang. -->
      <CallControls v-if="callStore.isInCall" />

      <!-- Right: minimize -->
      <div class="flex items-center gap-2 flex-1 justify-end">
        <button
          @click="callStore.toggleMinimize()"
          class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition-colors"
          title="Minimize"
        >
          <span class="material-symbols-rounded text-white text-lg">picture_in_picture_alt</span>
        </button>
      </div>
    </div>
    
    <!-- ========================= -->
    <!-- PRESENTATION LAYOUT: Screen share + collapsible sidebar -->
    <!-- ========================= -->
    <template v-if="isAnyoneScreenSharing">
      <div class="flex-1 flex overflow-hidden">
        <!-- Main screen share area -->
        <div class="flex-1 px-3 sm:px-4 py-2 overflow-hidden relative" ref="screenShareContainerRef">
          <!-- LOCAL screen share -->
          <div 
            v-if="callStore.isScreenSharing && callStore.screenStream"
            class="w-full h-full rounded-2xl overflow-hidden bg-black"
          >
            <video
              ref="localScreenVideoRef"
              autoplay
              playsinline
              muted
              class="w-full h-full object-contain"
            />
            <div class="absolute top-4 left-5 bg-red-500/90 px-3 py-1.5 rounded-full flex items-center gap-2 shadow-lg">
              <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
              <span class="material-symbols-rounded text-white text-sm">screen_share</span>
              <span class="text-white text-xs font-semibold">{{ screenShareLabel }}</span>
            </div>
          </div>
          
          <!-- REMOTE screen share -->
          <div 
            v-else-if="activeScreenShare"
            class="w-full h-full rounded-2xl overflow-hidden bg-black"
          >
            <div ref="remoteScreenContainerRef" class="w-full h-full"></div>
            <div class="absolute top-4 left-5 bg-blue-500/80 px-3 py-1.5 rounded-full flex items-center gap-2">
              <span class="material-symbols-rounded text-white text-sm">screen_share</span>
              <span class="text-white text-xs font-medium">
                {{ activeScreenShare.name }} is sharing their screen
              </span>
            </div>
            <!-- Fullscreen toggle for viewers -->
            <button
              @click="toggleScreenShareFullscreen"
              class="absolute top-4 right-5 w-9 h-9 rounded-full bg-black/50 hover:bg-black/70 flex items-center justify-center transition-colors z-10"
              :title="isScreenShareFullscreen ? 'Exit fullscreen' : 'Fullscreen'"
            >
              <span class="material-symbols-rounded text-white text-lg">
                {{ isScreenShareFullscreen ? 'fullscreen_exit' : 'fullscreen' }}
              </span>
            </button>
          </div>

          <!-- Sidebar toggle button (visible when sidebar is closed) -->
          <button
            v-if="!sidebarOpen"
            @click="sidebarOpen = true"
            class="absolute top-4 right-5 w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition-colors z-10"
            :class="{ 'right-16': activeScreenShare && !callStore.isScreenSharing }"
            title="Show participants"
          >
            <span class="material-symbols-rounded text-white text-lg">group</span>
          </button>
        </div>
        
        <!-- Participants sidebar (collapsible) -->
        <div 
          v-if="sidebarOpen"
          class="w-[200px] shrink-0 bg-surface-900/80 border-l border-surface-700/50 flex flex-col overflow-hidden transition-all"
        >
          <!-- Sidebar header -->
          <div class="flex items-center justify-between px-3 py-2.5 border-b border-surface-700/50">
            <span class="text-white/70 text-xs font-medium">Participants</span>
            <button
              @click="sidebarOpen = false"
              class="w-6 h-6 rounded-full hover:bg-white/10 flex items-center justify-center transition-colors"
              title="Hide participants"
            >
              <span class="material-symbols-rounded text-white/50 text-base">close</span>
            </button>
          </div>
          
          <!-- Participant tiles (vertical scrolling) -->
          <div class="flex-1 overflow-y-auto p-2 space-y-2">
            <!-- Local camera (self) -->
            <div class="w-full aspect-video">
              <ParticipantTile
                :stream="callStore.localStream"
                :name="localDisplayName"
                :email="localEmail"
                :avatar="localAvatarUrl"
                :is-local="true"
                :is-speaking="isSpeaking(localEmail)"
                :media-state="{ 
                  audio: !callStore.isAudioMuted, 
                  video: callStore.isVideoOn, 
                  screenShare: callStore.isScreenSharing 
                }"
                compact
              />
            </div>
            
            <!-- Connected remote participants (camera feeds) -->
            <div 
              v-for="participant in activeParticipants"
              :key="participant.email"
              class="w-full aspect-video"
            >
              <ParticipantTile
                :stream="callStore.remoteStreams.get(participant.email)"
                :name="participant.name"
                :email="participant.email"
                :avatar="participant.avatar"
                :is-speaking="isSpeaking(participant.email)"
                :media-state="participant.mediaState"
                :status="participant.status"
                compact
              />
            </div>
          </div>
        </div>
      </div>
    </template>
    
    <!-- ========================= -->
    <!-- FOCUSED LAYOUT (mobile 1:1): Fullscreen + draggable PiP -->
    <!-- ========================= -->
    <template v-else-if="canUseFocusedMode && focusedEmail && focusedParticipant">
      <!-- Fullscreen tile -->
      <div class="flex-1 overflow-hidden relative" @click="handleTileTap(focusedEmail)">
        <!-- Local fullscreen -->
        <ParticipantTile
          v-if="focusedParticipant.type === 'local'"
          :stream="callStore.localStream"
          :name="localDisplayName"
          :email="localEmail"
          :avatar="localAvatarUrl"
          :is-local="true"
          :is-speaking="isSpeaking(localEmail)"
          :media-state="{ 
            audio: !callStore.isAudioMuted, 
            video: callStore.isVideoOn, 
            screenShare: callStore.isScreenSharing 
          }"
        />
        <!-- Remote fullscreen -->
        <ParticipantTile
          v-else
          :stream="callStore.remoteStreams.get(focusedParticipant.email)"
          :name="focusedParticipant.name"
          :email="focusedParticipant.email"
          :avatar="focusedParticipant.avatar"
          :is-speaking="isSpeaking(focusedParticipant.email)"
          :media-state="focusedParticipant.mediaState"
          :status="focusedParticipant.status"
        />
      </div>

      <!-- PiP (the other participant) — draggable, snap to edge -->
      <div
        class="absolute z-30 w-[120px] h-[170px] rounded-2xl overflow-hidden shadow-2xl ring-2 ring-white/20"
        :style="{ left: pipPosition.x + 'px', top: pipPosition.y + 'px' }"
        @touchstart.passive="onPipTouchStart"
        @touchmove="onPipTouchMove"
        @touchend.passive="onPipTouchEnd"
        @click.stop="handlePipTap"
      >
        <!-- PiP is local when remote is fullscreen -->
        <ParticipantTile
          v-if="focusedParticipant.type === 'remote'"
          :stream="callStore.localStream"
          :name="localDisplayName"
          :email="localEmail"
          :avatar="localAvatarUrl"
          :is-local="true"
          :is-speaking="isSpeaking(localEmail)"
          :media-state="{ 
            audio: !callStore.isAudioMuted, 
            video: callStore.isVideoOn, 
            screenShare: callStore.isScreenSharing 
          }"
          compact
        />
        <!-- PiP is remote when local is fullscreen -->
        <ParticipantTile
          v-else
          :stream="callStore.remoteStreams.get(activeParticipants[0]?.email)"
          :name="activeParticipants[0]?.name"
          :email="activeParticipants[0]?.email"
          :avatar="activeParticipants[0]?.avatar"
          :is-speaking="isSpeaking(activeParticipants[0]?.email)"
          :media-state="activeParticipants[0]?.mediaState"
          :status="activeParticipants[0]?.status"
          compact
        />
      </div>
    </template>
    
    <!-- ========================= -->
    <!-- NORMAL LAYOUT: Grid of participant tiles -->
    <!-- ========================= -->
    <template v-else>
      <div class="flex-1 p-3 sm:p-4 overflow-hidden">
        <div :class="['grid gap-3 h-full auto-rows-fr', gridClass]">
          <!-- Local video (self) -->
          <div class="h-full" @click="handleTileTap('self')">
            <ParticipantTile
              :stream="callStore.localStream"
              :name="localDisplayName"
              :email="localEmail"
              :avatar="localAvatarUrl"
              :is-local="true"
              :is-speaking="isSpeaking(localEmail)"
              :media-state="{ 
                audio: !callStore.isAudioMuted, 
                video: callStore.isVideoOn, 
                screenShare: callStore.isScreenSharing 
              }"
            />
          </div>
          
          <!-- Remote participants (active only) -->
          <div
            v-for="participant in activeParticipants"
            :key="participant.email"
            class="h-full"
            @click="handleTileTap(participant.email)"
          >
            <ParticipantTile
              :stream="callStore.remoteStreams.get(participant.email)"
              :name="participant.name"
              :email="participant.email"
              :avatar="participant.avatar"
              :is-speaking="isSpeaking(participant.email)"
              :media-state="participant.mediaState"
              :status="participant.status"
            />
          </div>
        </div>
      </div>
    </template>
    
    <!-- Pending participants indicator (still ringing) -->
    <div 
      v-if="pendingParticipants.length > 0 && callStore.callStatus !== 'ended'"
      class="absolute top-16 right-4 bg-surface-800/90 backdrop-blur-sm border border-surface-700 rounded-xl px-4 py-3 z-20 max-w-[200px]"
    >
      <p class="text-white/60 text-xs font-medium mb-2">Calling...</p>
      <div class="space-y-1.5">
        <div
          v-for="p in pendingParticipants"
          :key="p.email"
          class="flex items-center gap-2"
        >
          <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
          <span class="text-white/80 text-xs truncate">{{ p.name || p.email?.split('@')[0] }}</span>
        </div>
      </div>
    </div>
    
    <!-- Call error -->
    <div 
      v-if="callStore.callError"
      class="absolute top-20 left-1/2 -translate-x-1/2 bg-red-500/20 border border-red-500/30 text-red-300 px-4 py-2 rounded-xl text-sm z-20"
    >
      {{ callStore.callError }}
    </div>

    <!-- Tap-to-enable-sound (iOS autoplay block, e.g. answered via CallKit).
         The tap provides the user gesture iOS needs to start remote audio. -->
    <button
      v-if="callStore.audioPlaybackBlocked && callStore.callStatus === 'active'"
      @click="enableSound"
      class="absolute left-1/2 -translate-x-1/2 z-30 flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white px-5 py-3 rounded-full shadow-2xl text-sm font-semibold animate-pulse"
      style="bottom: calc(1.75rem + env(safe-area-inset-bottom, 0px))"
    >
      <span class="material-symbols-rounded">volume_up</span>
      Tap to enable sound
    </button>
    
  </div>
</template>
