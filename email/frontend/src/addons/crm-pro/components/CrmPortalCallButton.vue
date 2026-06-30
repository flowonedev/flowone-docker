<script setup>
/**
 * CrmPortalCallButton - Quick action to create a portal call room
 * Used in ClientSnapshot to start an instant call or schedule one with a client.
 * 
 * "Copy Link" generates a one-click guest call link (no portal login needed).
 * "Join" opens the call page in the browser (works in Electron + web).
 */
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import MeetingSettingsToggles from '@/components/call/MeetingSettingsToggles.vue'

const props = defineProps({
  clientId: { type: Number, required: true },
  boardId: { type: Number, default: null },
  cardId: { type: Number, default: null },
})

const toast = useToastStore()
const auth = useAuthStore()

const calls = ref([])
const loading = ref(false)
const showScheduleModal = ref(false)
const scheduledAt = ref('')
const creating = ref(false)
const ending = ref(null)
const joining = ref(null)
const copyingLink = ref(null)
const sendingTranscript = ref(null)
const showLinkModal = ref(false)
const generatedLink = ref('')
const generatedAdminLink = ref('')

const waitingRoomEnabled = ref(false)
const participantsHidden = ref(false)
const copyingAdminLink = ref(null)

watch(() => props.clientId, () => fetchCalls(), { immediate: true })

async function fetchCalls() {
  loading.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/portal/calls`)
    if (res.data?.success) {
      calls.value = res.data.data?.calls || res.data.data || []
    }
  } catch (e) {
    calls.value = []
  } finally {
    loading.value = false
  }
}

async function startInstantCall() {
  creating.value = true
  try {
    const payload = { call_type: 'instant' }
    if (props.boardId) payload.board_id = props.boardId
    if (props.cardId) payload.card_id = props.cardId
    if (waitingRoomEnabled.value) payload.waiting_room = true
    if (participantsHidden.value) payload.participants_hidden = true
    const res = await api.post(`/clients/${props.clientId}/portal/calls`, payload)
    if (res.data?.success) {
      toast.success('Call room created! Share the link with your client.')
      await fetchCalls()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create call')
  } finally {
    creating.value = false
  }
}

async function scheduleCall() {
  if (!scheduledAt.value) {
    toast.error('Please select a date and time')
    return
  }
  creating.value = true
  try {
    const payload = { call_type: 'scheduled', scheduled_at: scheduledAt.value }
    if (props.boardId) payload.board_id = props.boardId
    if (props.cardId) payload.card_id = props.cardId
    if (waitingRoomEnabled.value) payload.waiting_room = true
    if (participantsHidden.value) payload.participants_hidden = true
    const res = await api.post(`/clients/${props.clientId}/portal/calls`, payload)
    if (res.data?.success) {
      toast.success('Call scheduled')
      showScheduleModal.value = false
      scheduledAt.value = ''
      await fetchCalls()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to schedule call')
  } finally {
    creating.value = false
  }
}

async function endCall(call) {
  ending.value = call.id
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/calls/${call.id}/end`)
    if (res.data?.success) {
      toast.success('Call ended')
      await fetchCalls()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to end call')
  } finally {
    ending.value = null
  }
}

const cancelling = ref(null)

async function cancelCall(call) {
  cancelling.value = call.id
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/calls/${call.id}/cancel`)
    if (res.data?.success) {
      toast.success('Call cancelled')
      await fetchCalls()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to cancel call')
  } finally {
    cancelling.value = null
  }
}

function openGeneratedLink(link) {
  if (!link) return
  showLinkModal.value = false
  window.open(link, '_blank', 'noopener')
}

async function joinCall(call) {
  joining.value = call.id
  try {
    const linkRes = await api.post(`/clients/${props.clientId}/portal/calls/${call.id}/guest-link`, {
      ttl_hours: 4,
      max_uses: 0,
      role: 'admin'
    })
    if (linkRes.data?.success) {
      const callUrl = linkRes.data.data?.link
      if (callUrl) {
        window.open(callUrl, '_blank')
        toast.success('Call opened in browser')
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to join call')
  } finally {
    joining.value = null
  }
}

async function copyGuestLink(call) {
  copyingLink.value = call.id
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/calls/${call.id}/guest-link`, {
      ttl_hours: 24,
      max_uses: 0,
      role: 'guest'
    })
    if (res.data?.success) {
      const link = res.data.data?.link
      if (link) {
        generatedLink.value = link
        generatedAdminLink.value = ''
        showLinkModal.value = true
        try {
          await navigator.clipboard.writeText(link)
          toast.success('Guest call link copied to clipboard')
        } catch {
          toast.info('Link generated - copy it from the dialog')
        }
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to generate guest link')
  } finally {
    copyingLink.value = null
  }
}

async function copyAdminLink(call) {
  copyingAdminLink.value = call.id
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/calls/${call.id}/guest-link`, {
      ttl_hours: 24,
      max_uses: 0,
      role: 'admin'
    })
    if (res.data?.success) {
      const link = res.data.data?.link
      if (link) {
        generatedAdminLink.value = link
        generatedLink.value = ''
        showLinkModal.value = true
        try {
          await navigator.clipboard.writeText(link)
          toast.success('Admin link copied')
        } catch {
          toast.info('Admin link generated - copy it from the dialog')
        }
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to generate admin link')
  } finally {
    copyingAdminLink.value = null
  }
}

async function copyToClipboardValue(value) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    toast.success('Link copied')
  } catch {
    const ta = document.createElement('textarea')
    ta.value = value
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
    toast.success('Link copied')
  }
}

async function sendTranscript(call) {
  sendingTranscript.value = call.id
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/calls/${call.id}/transcript`)
    if (res.data?.success) {
      toast.success('Transcript sent to your email')
    } else {
      toast.error(res.data?.error || 'Failed to send transcript')
    }
  } catch (e) {
    const msg = e.response?.data?.error || 'Failed to send transcript'
    toast.error(msg)
  } finally {
    sendingTranscript.value = null
  }
}

const activeCalls = computed(() =>
  calls.value.filter(c => c.status === 'waiting' || c.status === 'active')
)

const activeCallIds = computed(() => new Set(activeCalls.value.map(c => c.id)))

const recentCalls = computed(() =>
  calls.value.filter(c => !activeCallIds.value.has(c.id)).slice(0, 5)
)

const now = ref(Date.now())
let nowTimer = null
onMounted(() => { nowTimer = setInterval(() => { now.value = Date.now() }, 30000) })
onBeforeUnmount(() => { if (nowTimer) clearInterval(nowTimer) })

function isJoinable(call) {
  if (call.status === 'active') return true
  if (call.call_type !== 'scheduled' || !call.scheduled_at) return true
  const scheduledMs = new Date(call.scheduled_at).getTime()
  return now.value >= scheduledMs - 5 * 60 * 1000
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">call</span>
        Portal Calls
      </h3>
      <div class="flex gap-1">
        <button @click="startInstantCall" :disabled="creating"
                class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium 
                       bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400 
                       hover:bg-green-100 dark:hover:bg-green-500/20 transition-colors disabled:opacity-50"
                title="Start instant call">
          <span class="material-symbols-rounded text-sm">videocam</span>
          Call Now
        </button>
        <button @click="showScheduleModal = true"
                class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium 
                       bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 
                       hover:bg-blue-100 dark:hover:bg-blue-500/20 transition-colors"
                title="Schedule a call">
          <span class="material-symbols-rounded text-sm">schedule</span>
          Schedule
        </button>
      </div>
    </div>

    <!-- Meeting room settings (applied to next created call) -->
    <MeetingSettingsToggles
      v-model:waiting-room="waitingRoomEnabled"
      v-model:participants-hidden="participantsHidden"
      class="mb-3"
    />

    <!-- Active calls -->
    <div v-if="activeCalls.length > 0" class="space-y-2 mb-2">
      <div v-for="call in activeCalls" :key="call.id"
           class="p-3 rounded-lg bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30">
        <div class="flex items-center gap-3">
          <span class="w-2 h-2 rounded-full shrink-0"
                :class="call.status === 'active' ? 'bg-green-500 animate-pulse' : 'bg-green-500 animate-pulse'"></span>
          <div class="flex-1 min-w-0 text-xs">
            <span class="font-medium text-green-700 dark:text-green-300">
              {{ call.status === 'active' ? 'Active Call' : 'Waiting for client' }}
            </span>
            <p class="text-surface-400 truncate mt-0.5">{{ call.room_name }}</p>
            <p v-if="call.call_type === 'scheduled' && call.scheduled_at" class="text-blue-500 dark:text-blue-400 mt-0.5 flex items-center gap-1">
              <span class="material-symbols-rounded text-xs">schedule</span>
              Scheduled: {{ formatDate(call.scheduled_at) }}
            </p>
          </div>
        </div>
        <!-- Action buttons -->
        <div class="flex items-center gap-1.5 mt-2 pl-5">
          <button v-if="isJoinable(call)" @click="joinCall(call)" :disabled="joining === call.id"
                  class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium
                         bg-green-600 hover:bg-green-700 text-white transition-colors disabled:opacity-50"
                  title="Join this call room">
            <span class="material-symbols-rounded text-sm">videocam</span>
            {{ joining === call.id ? 'Joining...' : 'Join' }}
          </button>
          <button @click="copyGuestLink(call)" :disabled="copyingLink === call.id"
                  class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium
                         bg-surface-200 dark:bg-surface-600 hover:bg-surface-300 dark:hover:bg-surface-500
                         text-surface-700 dark:text-surface-200 transition-colors disabled:opacity-50"
                  title="Generate & copy guest call link">
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': copyingLink === call.id }">
              {{ copyingLink === call.id ? 'sync' : 'link' }}
            </span>
            {{ copyingLink === call.id ? 'Generating...' : 'Copy Link' }}
          </button>
          <button @click="copyAdminLink(call)" :disabled="copyingAdminLink === call.id"
                  class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium
                         bg-amber-100 dark:bg-amber-500/15 hover:bg-amber-200 dark:hover:bg-amber-500/25
                         text-amber-700 dark:text-amber-300 border border-amber-500/30 transition-colors disabled:opacity-50"
                  title="Generate host (admin) link — DO NOT share with clients">
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': copyingAdminLink === call.id }">
              {{ copyingAdminLink === call.id ? 'sync' : 'shield_person' }}
            </span>
            {{ copyingAdminLink === call.id ? 'Generating...' : 'Host link' }}
          </button>
          <button v-if="call.status === 'active'" @click="endCall(call)" :disabled="ending === call.id"
                  class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium
                         bg-red-50 dark:bg-red-500/10 hover:bg-red-100 dark:hover:bg-red-500/20
                         text-red-600 dark:text-red-400 transition-colors disabled:opacity-50 ml-auto"
                  title="End this call">
            <span class="material-symbols-rounded text-sm">call_end</span>
            {{ ending === call.id ? 'Ending...' : 'End' }}
          </button>
          <button v-else @click="cancelCall(call)" :disabled="cancelling === call.id"
                  class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium
                         bg-red-50 dark:bg-red-500/10 hover:bg-red-100 dark:hover:bg-red-500/20
                         text-red-600 dark:text-red-400 transition-colors disabled:opacity-50 ml-auto"
                  title="Cancel this call">
            <span class="material-symbols-rounded text-sm">close</span>
            {{ cancelling === call.id ? 'Cancelling...' : 'Cancel' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Recent calls -->
    <div v-if="recentCalls.length > 0" class="space-y-1">
      <div v-for="call in recentCalls" :key="call.id"
           class="flex items-center gap-2 p-2 rounded-lg text-xs text-surface-500">
        <span class="material-symbols-rounded text-sm"
              :class="call.status === 'cancelled' ? 'text-red-400' : call.status === 'ended' ? 'text-surface-400' : 'text-green-500'">
          {{ call.status === 'cancelled' ? 'cancel' : call.status === 'ended' ? 'call_end' : 'videocam' }}
        </span>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5 flex-wrap">
            <span :class="call.status === 'cancelled' ? 'line-through text-surface-400' : ''">
              {{ call.call_type === 'scheduled' ? 'Scheduled call' : 'Quick call' }}
            </span>
            <span v-if="call.status === 'cancelled'" class="text-red-400">(cancelled)</span>
            <span v-if="call.board_name" class="px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-[10px] font-medium truncate max-w-[120px]">
              {{ call.board_name }}
            </span>
            <span v-if="call.card_title" class="px-1.5 py-0.5 rounded bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 text-[10px] font-medium truncate max-w-[120px]">
              {{ call.card_title }}
            </span>
          </div>
          <div class="flex items-center gap-2 mt-0.5">
            <p v-if="call.call_type === 'scheduled' && call.scheduled_at" class="text-blue-400 text-[10px] flex items-center gap-0.5">
              <span class="material-symbols-rounded text-[10px]">schedule</span>
              {{ formatDate(call.scheduled_at) }}
            </p>
            <span v-if="call.participants?.length" class="text-[10px] text-surface-400 flex items-center gap-0.5">
              <span class="material-symbols-rounded text-[10px]">group</span>
              {{ call.participants.length }}
            </span>
          </div>
        </div>
        <button
          v-if="call.status === 'ended' && call.chat_transcript"
          @click="sendTranscript(call)"
          :disabled="sendingTranscript === call.id"
          class="flex items-center gap-1 px-2 py-0.5 rounded-lg text-[10px] font-medium
                 bg-indigo-50 dark:bg-indigo-500/10 hover:bg-indigo-100 dark:hover:bg-indigo-500/20
                 text-indigo-600 dark:text-indigo-400 transition-colors disabled:opacity-50"
          title="Send chat transcript to your email"
        >
          <span class="material-symbols-rounded text-xs" :class="{ 'animate-spin': sendingTranscript === call.id }">
            {{ sendingTranscript === call.id ? 'sync' : 'description' }}
          </span>
          {{ sendingTranscript === call.id ? 'Sending...' : 'Transcript' }}
        </button>
        <span class="text-surface-400 shrink-0">{{ formatDate(call.call_type === 'scheduled' && call.scheduled_at ? call.scheduled_at : call.created_at) }}</span>
      </div>
    </div>
    <p v-else-if="activeCalls.length === 0" class="text-xs text-surface-400 text-center py-2">No calls yet</p>

    <!-- Schedule Modal -->
    <Teleport to="body">
      <div v-if="showScheduleModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showScheduleModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-sm p-6">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-4">Schedule a Call</h3>
          <div class="mb-4">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Date & Time</label>
            <input v-model="scheduledAt" type="datetime-local"
                   class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 
                          bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                          focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
          <div class="flex justify-end gap-3">
            <button @click="showScheduleModal = false" class="px-4 py-2 text-sm text-surface-500">Cancel</button>
            <button @click="scheduleCall" :disabled="creating"
                    class="px-6 py-2 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50">
              {{ creating ? 'Scheduling...' : 'Schedule' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Link Modal (guest and/or admin) -->
    <Teleport to="body">
      <div v-if="showLinkModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showLinkModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg p-6">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-2 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">link</span>
            Call link{{ generatedLink && generatedAdminLink ? 's' : '' }}
          </h3>

          <!-- Guest link -->
          <template v-if="generatedLink">
            <p class="text-sm text-surface-500 mb-2">
              Share this link with your client. They can join the video call instantly — no login or account needed.
            </p>
            <div class="flex items-center gap-2 p-3 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 mb-4">
              <input
                :value="generatedLink"
                readonly
                class="flex-1 bg-transparent text-sm text-surface-700 dark:text-surface-300 outline-none font-mono truncate"
                @click="$event.target.select()"
              />
              <button
                @click="copyToClipboardValue(generatedLink)"
                class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-primary-600 hover:bg-primary-700 text-white transition-colors flex-shrink-0"
              >
                <span class="material-symbols-rounded text-sm">content_copy</span>
                Copy
              </button>
              <button
                v-if="!generatedAdminLink"
                @click="openGeneratedLink(generatedLink)"
                class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-green-600 hover:bg-green-700 text-white transition-colors flex-shrink-0"
                title="Join the call as a participant"
              >
                <span class="material-symbols-rounded text-sm">videocam</span>
                Join
              </button>
            </div>
          </template>

          <!-- Admin link -->
          <template v-if="generatedAdminLink">
            <div class="flex items-center gap-2 mb-1.5">
              <span class="material-symbols-rounded text-amber-500 text-base">shield_person</span>
              <p class="text-sm font-semibold text-amber-600 dark:text-amber-300">Host link — DO NOT share with clients</p>
            </div>
            <p class="text-xs text-amber-600/80 dark:text-amber-300/70 mb-2">
              This link grants admin rights (admit guests, kick participants, end the call). Keep it private.
            </p>
            <div class="flex items-center gap-2 p-3 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/40">
              <input
                :value="generatedAdminLink"
                readonly
                class="flex-1 bg-transparent text-sm text-amber-800 dark:text-amber-200 outline-none font-mono truncate"
                @click="$event.target.select()"
              />
              <button
                @click="copyToClipboardValue(generatedAdminLink)"
                class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-amber-600 hover:bg-amber-700 text-white transition-colors flex-shrink-0"
              >
                <span class="material-symbols-rounded text-sm">content_copy</span>
                Copy
              </button>
              <button
                @click="openGeneratedLink(generatedAdminLink)"
                class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-amber-500 hover:bg-amber-600 text-white transition-colors flex-shrink-0"
                title="Join as host (admin rights)"
              >
                <span class="material-symbols-rounded text-sm">videocam</span>
                Start Meeting
              </button>
            </div>
          </template>

          <div class="flex justify-end mt-5">
            <button
              @click="showLinkModal = false"
              class="px-5 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

