<script setup>
/**
 * GuestCallView - One-click video call page for external clients.
 *
 * Thin wrapper: validates the guest token, fetches LiveKit credentials,
 * then hands off to the reusable VideoCallRoom component.
 */
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute } from 'vue-router'
import VideoCallRoom from '@/components/call/VideoCallRoom.vue'
import PreJoinPanel from '@/components/call/PreJoinPanel.vue'
import { getApiOrigin } from '@/services/serverRegistry'

const route = useRoute()

const status = ref('loading') // loading | prejoin | joining | waiting_admission | ready | ended | kicked | error
const errorMessage = ref('')

const livekitToken = ref('')
const wsUrl = ref('')
const participantName = ref('')
const isAdmin = ref(false)
const transcriptUrl = ref('')

const apiBase = getApiOrigin() + '/api'

/** Last /info payload for calendar-aware prejoin */
const callInfo = ref(null)
const admissionRequestId = ref(null)
let admissionPollTimer = null

/** Admin lobby state */
const lobbyRequests = ref([])
let lobbyPollTimer = null
const lobbyError = ref('')

function clearAdmissionPoll() {
  if (admissionPollTimer) {
    clearTimeout(admissionPollTimer)
    clearInterval(admissionPollTimer)
    admissionPollTimer = null
  }
}

function clearLobbyPoll() {
  if (lobbyPollTimer) {
    clearInterval(lobbyPollTimer)
    lobbyPollTimer = null
  }
}

onBeforeUnmount(() => {
  clearAdmissionPoll()
  clearLobbyPoll()
})

onMounted(async () => {
  const token = route.params.token
  if (!token) {
    status.value = 'error'
    errorMessage.value = 'No call link token provided.'
    return
  }

  try {
    const res = await fetch(`${apiBase}/guest/call/${token}/info`)
    const data = await res.json()
    if (!res.ok || !data.success) {
      status.value = 'error'
      errorMessage.value = data.error || 'This link is no longer valid.'
      return
    }
    if (!data.data.valid) {
      status.value = 'error'
      errorMessage.value = data.data.expired
        ? 'This call link has expired.'
        : 'This call link is no longer available.'
      return
    }

    callInfo.value = data.data

    transcriptUrl.value = `${apiBase}/guest/call/${token}/transcript`
    status.value = 'prejoin'
  } catch {
    status.value = 'error'
    errorMessage.value = 'Unable to connect. Please check your internet connection.'
  }
})

async function joinCall(name) {
  const token = route.params.token
  status.value = 'joining'
  try {
    const res = await fetch(`${apiBase}/guest/call/${token}/join`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: name || 'Guest' })
    })
    if (res.status === 410) {
      status.value = 'error'
      errorMessage.value = 'This call link is no longer valid. Please contact the organizer.'
      return
    }
    if (res.status === 429) {
      const data = await res.json().catch(() => ({}))
      const retry = data.retry_after || 30
      status.value = 'error'
      errorMessage.value = `Too many attempts. Please try again in ${retry}s.`
      return
    }
    const data = await res.json()
    if (!res.ok || !data.success) {
      status.value = 'error'
      errorMessage.value = data.error || 'Failed to join call.'
      return
    }
    const payload = data.data || {}
    if (payload.status === 'pending_admission') {
      admissionRequestId.value = payload.request_id
      participantName.value = name || 'Guest'
      status.value = 'waiting_admission'
      startAdmissionPolling(token, payload.request_id)
      return
    }
    livekitToken.value = payload.livekit_token
    wsUrl.value = payload.ws_url
    isAdmin.value = !!payload.is_admin
    participantName.value = name || 'Guest'
    status.value = 'ready'
  } catch {
    status.value = 'error'
    errorMessage.value = 'Connection failed. Please try again.'
  }
}

const admissionPollStartedAt = ref(0)

function admissionPollInterval(elapsedMs) {
  if (elapsedMs < 30_000) return 3000        // first 30s: 3s
  if (elapsedMs < 120_000) return 5000       // first 2min: 5s
  if (elapsedMs < 300_000) return 8000       // first 5min: 8s
  return 15_000                              // capped at 15s
}

function startAdmissionPolling(token, requestId) {
  clearAdmissionPoll()
  admissionPollStartedAt.value = Date.now()
  const poll = async () => {
    const elapsed = Date.now() - admissionPollStartedAt.value
    if (elapsed > 30 * 60 * 1000) {
      clearAdmissionPoll()
      status.value = 'error'
      errorMessage.value = 'Your join request timed out. Please contact the organizer.'
      return
    }
    try {
      const res = await fetch(`${apiBase}/guest/call/${token}/admission/${requestId}`)
      if (res.status === 410 || res.status === 404) {
        clearAdmissionPoll()
        status.value = 'error'
        errorMessage.value = 'This call link is no longer available.'
        return
      }
      const data = await res.json()
      if (!res.ok || !data.success) return
      const st = data.data?.status
      if (st === 'approved' && data.data?.livekit_token) {
        clearAdmissionPoll()
        livekitToken.value = data.data.livekit_token
        wsUrl.value = data.data.livekit_ws_url
        isAdmin.value = false
        status.value = 'ready'
        return
      }
      if (st === 'denied' || st === 'expired') {
        clearAdmissionPoll()
        status.value = 'error'
        errorMessage.value = st === 'denied' ? 'The host declined your join request.' : 'Your join request expired.'
        return
      }
    } catch { /* ignore single failures */ }
    // schedule next poll with backoff
    if (admissionPollTimer === null) return
    clearTimeout(admissionPollTimer)
    admissionPollTimer = setTimeout(poll, admissionPollInterval(Date.now() - admissionPollStartedAt.value))
  }
  admissionPollTimer = setTimeout(poll, 0)
}

// Pre-join: name input (camera preview + devices live in PreJoinPanel)
//
// Name autofill is keyed by token so the same person rejoining the same call
// keeps their typed name, but the recipient of a shared link never sees the
// previous user's name pre-populated.
const GUEST_NAME_STORAGE_PREFIX = 'flowone:guest:name:'
const LEGACY_GUEST_NAME_KEY = 'flowone:guest:lastName'
const guestName = ref('')

function tokenStorageKey() {
  const token = route.params.token
  if (!token) return ''
  return GUEST_NAME_STORAGE_PREFIX + String(token).slice(0, 32)
}

function deriveDefaultName() {
  // Only restore a name when rejoining the EXACT same link. Previously this
  // used a single global key which leaked the host's name to anyone who later
  // opened a different guest link from the same browser, and pre-filled the
  // host's email-derived name for any recipient of an admin link they
  // accidentally shared.
  const key = tokenStorageKey()
  if (!key) return ''
  try {
    const stored = localStorage.getItem(key)
    if (stored && stored.trim()) return stored.trim()
  } catch (_) { /* ignore */ }

  // Best-effort: migrate the legacy global key into a per-token entry the
  // first time the original creator returns to one of their links. We only
  // honor it for admin tokens (host re-joining) to avoid leaking to guests.
  if (callInfo.value?.is_admin) {
    try {
      const legacy = localStorage.getItem(LEGACY_GUEST_NAME_KEY)
      if (legacy && legacy.trim()) {
        try { localStorage.setItem(key, legacy.trim()) } catch (_) { /* ignore */ }
        return legacy.trim()
      }
    } catch (_) { /* ignore */ }
  }
  return ''
}

function handleJoin(name) {
  if (name) {
    const key = tokenStorageKey()
    if (key) {
      try { localStorage.setItem(key, name) } catch (_) { /* ignore */ }
    }
  }
  joinCall(name)
}

watch(status, (v) => {
  if (v === 'prejoin') {
    if (!guestName.value) guestName.value = deriveDefaultName()
    maybeStartLobbyPolling()
  } else {
    clearLobbyPoll()
  }
})

const isAdminToken = () => !!(callInfo.value?.is_admin)
const isWaitingRoomOn = () => !!(callInfo.value?.waiting_room_enabled)

function maybeStartLobbyPolling() {
  if (!isAdminToken() || !isWaitingRoomOn()) return
  fetchLobby()
  clearLobbyPoll()
  lobbyPollTimer = setInterval(fetchLobby, 3500)
}

async function fetchLobby() {
  const token = route.params.token
  if (!token) return
  try {
    const url = `${apiBase}/guest/call/lobby?admin_token=${encodeURIComponent(token)}`
    const res = await fetch(url)
    const data = await res.json()
    if (!res.ok || !data.success) {
      lobbyError.value = data.error || 'Unable to load lobby.'
      return
    }
    lobbyError.value = ''
    lobbyRequests.value = Array.isArray(data.data) ? data.data : []
  } catch {
    /* keep last state, surface no error to admin to avoid noise */
  }
}

async function admitRequest(id) {
  const token = route.params.token
  try {
    const res = await fetch(`${apiBase}/guest/call/admission/${id}/approve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_token: token }),
    })
    const data = await res.json()
    if (res.ok && data.success) {
      lobbyRequests.value = lobbyRequests.value.filter(r => r.id !== id)
    }
  } catch { /* ignore */ }
}

async function denyRequest(id) {
  const token = route.params.token
  try {
    const res = await fetch(`${apiBase}/guest/call/admission/${id}/deny`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_token: token }),
    })
    const data = await res.json()
    if (res.ok && data.success) {
      lobbyRequests.value = lobbyRequests.value.filter(r => r.id !== id)
    }
  } catch { /* ignore */ }
}

/**
 * Re-issue LiveKit credentials by calling /join again. Used by VideoCallRoom
 * when the user clicks "Rejoin" after a Disconnected(failure) event - the
 * original livekit_token may have expired by then.
 */
async function freshJoinCreds() {
  const token = route.params.token
  if (!token) return null
  try {
    const res = await fetch(`${apiBase}/guest/call/${token}/join`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: participantName.value || 'Guest' }),
    })
    if (!res.ok) return null
    const data = await res.json()
    const payload = data?.data
    if (!payload?.livekit_token || !payload?.ws_url) return null
    livekitToken.value = payload.livekit_token
    wsUrl.value = payload.ws_url
    return { livekitToken: payload.livekit_token, wsUrl: payload.ws_url, isAdmin: !!payload.is_admin }
  } catch {
    return null
  }
}
</script>

<template>
  <div class="h-screen bg-gradient-to-br from-surface-900 via-surface-800 to-surface-900 flex flex-col overflow-hidden">

    <!-- Loading -->
    <div v-if="status === 'loading'" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl text-primary-400 animate-spin">progress_activity</span>
        <p class="mt-4 text-white/60">Loading call...</p>
      </div>
    </div>

    <!-- Pre-join (guest name + camera preview) -->
    <template v-else-if="status === 'prejoin'">
      <header class="px-5 py-3 flex items-center border-b border-surface-700/40 flex-shrink-0">
        <div class="flex items-center gap-3">
          <div class="w-7 h-7 rounded-lg bg-primary-500 flex items-center justify-center">
            <span class="material-symbols-rounded text-white text-base">videocam</span>
          </div>
          <div>
            <span class="text-white/80 font-semibold text-sm tracking-wide block">FlowOne</span>
            <template v-if="callInfo?.owner_context?.title">
              <span class="text-white text-sm font-medium">{{ callInfo.owner_context.title }}</span>
              <span v-if="callInfo.owner_context.organizer_email" class="text-white/50 text-xs block">
                {{ callInfo.owner_context.organizer_email }}
              </span>
            </template>
          </div>
        </div>
      </header>
      <!--
        Teams-like prejoin: main column (camera + name) on the left,
        waiting-room as a persistent right sidebar for admins.
        Stacks on screens narrower than lg.
      -->
      <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">
        <div class="flex-1 flex items-center justify-center p-4 overflow-y-auto">
          <PreJoinPanel
            v-model:name="guestName"
            :join-label="callInfo?.is_admin ? 'Join as host' : 'Join Call'"
            @join="handleJoin"
          />
        </div>

        <!--
          Admin lobby sidebar: visible only when this link is an admin link
          and waiting room is on. Persistent right column on lg+, full width
          (below the main column) on smaller screens.
        -->
        <aside
          v-if="callInfo?.is_admin && callInfo?.waiting_room_enabled"
          class="w-full lg:w-80 lg:flex-shrink-0 border-t lg:border-t-0 lg:border-l border-surface-700/50
                 bg-surface-900/60 backdrop-blur-sm flex flex-col max-h-[40vh] lg:max-h-none"
        >
          <header class="px-4 py-3 flex items-center justify-between border-b border-surface-700/40 flex-shrink-0">
            <div class="flex items-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-amber-400 text-base">door_front</span>
              <h3 class="text-white font-semibold text-sm">Waiting room</h3>
              <span class="text-amber-300 text-[11px] bg-amber-500/10 px-2 py-0.5 rounded-full">
                {{ lobbyRequests.length }} waiting
              </span>
            </div>
            <button
              @click="fetchLobby"
              class="w-7 h-7 rounded-full hover:bg-white/10 flex items-center justify-center text-surface-300"
              title="Refresh"
            >
              <span class="material-symbols-rounded text-base">refresh</span>
            </button>
          </header>

          <p v-if="lobbyError" class="text-red-400 text-xs px-4 pt-2">{{ lobbyError }}</p>

          <div class="flex-1 overflow-y-auto p-3 space-y-2">
            <div v-if="lobbyRequests.length === 0" class="text-surface-500 text-xs italic py-6 text-center">
              No one is waiting right now.
            </div>
            <div
              v-for="req in lobbyRequests"
              :key="req.id"
              class="flex items-center justify-between gap-2 bg-surface-800/70 rounded-xl p-2.5"
            >
              <div class="flex items-center gap-2 min-w-0 flex-1">
                <div class="w-8 h-8 rounded-full bg-amber-500/15 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-amber-300 text-base">person</span>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="text-white text-sm truncate">{{ req.guest_name || 'Guest' }}</p>
                  <p class="text-surface-400 text-[11px] truncate">{{ req.requested_at || '' }}</p>
                </div>
              </div>
              <div class="flex items-center gap-1.5 flex-shrink-0">
                <button
                  @click="admitRequest(req.id)"
                  class="px-2.5 py-1 rounded-full bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 text-xs font-medium flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">check</span>
                  Admit
                </button>
                <button
                  @click="denyRequest(req.id)"
                  class="px-2.5 py-1 rounded-full bg-red-500/20 hover:bg-red-500/30 text-red-300 text-xs font-medium flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">close</span>
                  Deny
                </button>
              </div>
            </div>
          </div>

          <p class="px-4 py-2 text-[11px] text-surface-500 border-t border-surface-700/40 flex-shrink-0">
            Late joiners stay here until you let them in.
          </p>
        </aside>
      </div>
    </template>

    <!-- Waiting for host admission -->
    <div v-else-if="status === 'waiting_admission'" class="flex-1 flex items-center justify-center p-6">
      <div class="text-center max-w-md">
        <span class="material-symbols-rounded text-5xl text-amber-400">hourglass_top</span>
        <p class="mt-4 text-white font-medium text-lg">Waiting for the host to let you in…</p>
        <p class="text-surface-400 text-sm mt-2">Please keep this page open. You will join automatically when approved.</p>
      </div>
    </div>

    <!-- Joining -->
    <div v-else-if="status === 'joining'" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl text-primary-400 animate-spin">progress_activity</span>
        <p class="mt-4 text-white font-medium">Joining call...</p>
        <p class="text-surface-400 text-sm mt-1">Setting up your camera and microphone</p>
      </div>
    </div>

    <!-- VideoCallRoom (ready = have LiveKit token) -->
    <VideoCallRoom
      v-else-if="status === 'ready'"
      :livekit-token="livekitToken"
      :ws-url="wsUrl"
      :participant-name="participantName"
      :is-admin="isAdmin"
      :show-pre-join="false"
      :transcript-url="transcriptUrl"
      :workshop-mode="!!callInfo?.participants_hidden"
      :room-key="callInfo?.room_name || route.params.token"
      :kick-url="isAdmin ? `${apiBase}/guest/call/${route.params.token}/kick` : ''"
      :api-base="apiBase"
      :admin-token="isAdmin ? String(route.params.token) : ''"
      :attachments-base-url="`${apiBase}/guest/call/${route.params.token}/attachments`"
      :reconnect-fn="freshJoinCreds"
      @ended="status = 'ended'"
      @kicked="status = 'kicked'"
    />

    <!-- Kicked -->
    <template v-if="status === 'kicked'">
      <header class="px-5 py-3 flex items-center border-b border-surface-700/40 flex-shrink-0">
        <div class="flex items-center gap-3">
          <div class="w-7 h-7 rounded-lg bg-primary-500 flex items-center justify-center">
            <span class="material-symbols-rounded text-white text-base">videocam</span>
          </div>
          <span class="text-white/80 font-semibold text-sm tracking-wide">FlowOne</span>
        </div>
      </header>
      <div class="flex-1 flex items-center justify-center p-4">
        <div class="text-center max-w-sm">
          <div class="w-20 h-20 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-5">
            <span class="material-symbols-rounded text-4xl text-red-400">block</span>
          </div>
          <h2 class="text-2xl font-bold text-white mb-2">You were removed from the call</h2>
          <p class="text-surface-400 mb-6">The host removed you from this call.</p>
          <p class="text-surface-500 text-xs">You can close this tab.</p>
        </div>
      </div>
    </template>

    <!-- Ended (shown after VideoCallRoom emits 'ended') -->
    <template v-if="status === 'ended'">
      <header class="px-5 py-3 flex items-center border-b border-surface-700/40 flex-shrink-0">
        <div class="flex items-center gap-3">
          <div class="w-7 h-7 rounded-lg bg-primary-500 flex items-center justify-center">
            <span class="material-symbols-rounded text-white text-base">videocam</span>
          </div>
          <span class="text-white/80 font-semibold text-sm tracking-wide">FlowOne</span>
        </div>
      </header>
      <div class="flex-1 flex items-center justify-center p-4">
        <div class="text-center max-w-sm">
          <div class="w-20 h-20 rounded-full bg-surface-700 flex items-center justify-center mx-auto mb-5">
            <span class="material-symbols-rounded text-4xl text-surface-400">call_end</span>
          </div>
          <h2 class="text-2xl font-bold text-white mb-2">Call ended</h2>
          <p class="text-surface-400 mb-6">Thank you for joining. You can close this tab.</p>
          <button
            @click="status = 'prejoin'"
            class="px-6 py-3 rounded-full bg-primary-500 hover:bg-primary-600 text-white font-medium text-sm transition-all"
          >
            Rejoin Call
          </button>
        </div>
      </div>
    </template>

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
