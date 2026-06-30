<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useToastStore } from '@/stores/toast'
import { useChatPresence } from '@/composables/useChatPresence'
import api from '@/services/api'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import MeetingSettingsToggles from '@/components/call/MeetingSettingsToggles.vue'
import MeetingLinkDialog from '@/components/call/MeetingLinkDialog.vue'

const props = defineProps({
  show: Boolean
})

const emit = defineEmits(['close', 'open-group', 'open-channel', 'started'])

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()
const toast = useToastStore()
const { getStatusColor } = useChatPresence()

// Search
const searchQuery = ref('')

// Multi-select mode
const multiSelect = ref(false)
const selectedColleagues = ref([])

// Meeting scheduling
const showScheduleForm = ref(false)
const meetingTitle = ref('')
const meetingDate = ref('')
const meetingStartTime = ref('')
const meetingEndTime = ref('')
const meetingCreating = ref(false)
const meetingWaitingRoom = ref(false)
const meetingParticipantsHidden = ref(false)

// Meeting link dialog (shown after a successful Meet Now / Schedule / Call Link)
const showMeetingLinkDialog = ref(false)
const createdMeetingLink = ref(null)
const createdAdminMeetingLink = ref(null)
const createdMeetingTitle = ref('')

// Call Link (standalone share-with-anyone) settings.
// Defaults: waiting room ON so every visitor lands in the lobby until the host
// admits them. Workshop mode stays OFF unless the user explicitly enables it.
const showCallLinkForm = ref(false)
const callLinkWaitingRoom = ref(true)
const callLinkParticipantsHidden = ref(false)

// Reset on open
watch(() => props.show, (val) => {
  if (val) {
    searchQuery.value = ''
    multiSelect.value = false
    selectedColleagues.value = []
    showScheduleForm.value = false
    meetingTitle.value = ''
    meetingDate.value = ''
    meetingStartTime.value = ''
    meetingEndTime.value = ''
    meetingCreating.value = false
    meetingWaitingRoom.value = false
    meetingParticipantsHidden.value = false
    showMeetingLinkDialog.value = false
    createdMeetingLink.value = null
    createdAdminMeetingLink.value = null
    createdMeetingTitle.value = ''
    showCallLinkForm.value = false
    callLinkWaitingRoom.value = true
    callLinkParticipantsHidden.value = false
    colleaguesStore.refreshPresence()
    nextTick(() => {
      const input = document.querySelector('.new-conv-search-input')
      if (input) input.focus()
    })
  }
})

// Filtered colleagues
const filteredColleagues = computed(() => {
  const all = colleaguesStore.sortedColleagues
  if (!searchQuery.value) return all
  const q = searchQuery.value.toLowerCase()
  return all.filter(c =>
    c.display_name?.toLowerCase().includes(q) ||
    c.email.toLowerCase().includes(q) ||
    c.job_title?.toLowerCase().includes(q)
  )
})

// Selection helpers
function isSelected(colleague) {
  return selectedColleagues.value.some(c => c.id === colleague.id)
}

function toggleSelection(colleague) {
  if (isSelected(colleague)) {
    selectedColleagues.value = selectedColleagues.value.filter(c => c.id !== colleague.id)
  } else {
    selectedColleagues.value.push(colleague)
  }
}

function removeSelected(colleague) {
  selectedColleagues.value = selectedColleagues.value.filter(c => c.id !== colleague.id)
}

// Handle colleague click - either toggle selection or start 1:1
function handleColleagueClick(colleague) {
  if (multiSelect.value) {
    toggleSelection(colleague)
  } else {
    startDirectChat(colleague)
  }
}

// Start 1:1 DM
async function startDirectChat(colleague) {
  emit('close')
  await chatStore.openDMWith(colleague.id)
  emit('started')
}

// Start multi-person group chat
async function startGroupChat() {
  if (selectedColleagues.value.length === 0) return

  if (selectedColleagues.value.length === 1) {
    // Only 1 person selected, start a DM
    await startDirectChat(selectedColleagues.value[0])
    return
  }

  // Multiple people - open GroupChatModal with pre-selected members
  emit('close')
  emit('open-group', selectedColleagues.value.map(c => c.id))
}

// Format a Date as MySQL DATETIME in the user's local timezone (YYYY-MM-DD HH:MM:SS).
// The backend stores DATETIME without zone info; the meeting's `timezone` field carries
// the IANA zone separately. Sending toISOString() (UTC with `T` and `Z`) is rejected by
// MariaDB STRICT_TRANS_TABLES on DATETIME columns and was the cause of the /meetings 500.
function toMysqlDateTime(date) {
  const pad = (n) => String(n).padStart(2, '0')
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
  )
}

// Create instant meeting
async function startInstantMeeting() {
  if (selectedColleagues.value.length === 0) {
    toast.warning('Select at least one participant for the meeting')
    return
  }

  meetingCreating.value = true
  try {
    const now = new Date()
    const end = new Date(now.getTime() + 60 * 60 * 1000) // 1 hour from now

    const response = await api.post('/meetings', {
      title: `Meeting with ${selectedColleagues.value.map(c => c.display_name || c.email.split('@')[0]).join(', ')}`,
      start_time: toMysqlDateTime(now),
      end_time: toMysqlDateTime(end),
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      participants: selectedColleagues.value.map(c => c.email),
      waiting_room: meetingWaitingRoom.value,
      participants_hidden: meetingParticipantsHidden.value,
    })

    if (response.data?.data?.event) {
      const event = response.data.data.event
      toast.success('Meeting created')

      if (event.meeting_conversation_id) {
        chatStore.setActiveConversation(event.meeting_conversation_id)
        await chatStore.fetchConversations()
      }

      const guestLink =
        response.data?.data?.meeting_link ||
        (response.data?.data?.guest_call_token
          ? `${window.location.origin}/guest/call/${response.data.data.guest_call_token}`
          : (event.meeting_token ? `${window.location.origin}/meet/${event.meeting_token}` : null))
      const adminLink = response.data?.data?.admin_meeting_link || null

      if (guestLink || adminLink) {
        createdMeetingLink.value = guestLink
        createdAdminMeetingLink.value = adminLink
        createdMeetingTitle.value = event.title || 'Meeting'
        showMeetingLinkDialog.value = true
      } else {
        emit('close')
      }

      emit('started')
    } else {
      toast.error('Failed to create meeting')
    }
  } catch (e) {
    console.error('Failed to create meeting:', e)
    toast.error(e.response?.data?.error || 'Failed to create meeting')
  } finally {
    meetingCreating.value = false
  }
}

// Called when the user dismisses the post-creation link dialog
function onMeetingLinkDialogClose() {
  showMeetingLinkDialog.value = false
  emit('close')
}

// Schedule a meeting for later
async function scheduleTheMeeting() {
  if (!meetingDate.value || !meetingStartTime.value || !meetingEndTime.value) {
    toast.warning('Please fill in date, start time, and end time')
    return
  }

  if (selectedColleagues.value.length === 0) {
    toast.warning('Select at least one participant')
    return
  }

  meetingCreating.value = true
  try {
    const startLocal = new Date(`${meetingDate.value}T${meetingStartTime.value}`)
    const endLocal = new Date(`${meetingDate.value}T${meetingEndTime.value}`)

    const response = await api.post('/meetings', {
      title: meetingTitle.value || `Meeting with ${selectedColleagues.value.map(c => c.display_name || c.email.split('@')[0]).join(', ')}`,
      start_time: toMysqlDateTime(startLocal),
      end_time: toMysqlDateTime(endLocal),
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      participants: selectedColleagues.value.map(c => c.email),
      waiting_room: meetingWaitingRoom.value,
      participants_hidden: meetingParticipantsHidden.value,
    })

    if (response.data?.data?.event) {
      const event = response.data.data.event
      toast.success('Meeting scheduled')

      const guestLink =
        response.data?.data?.meeting_link ||
        (response.data?.data?.guest_call_token
          ? `${window.location.origin}/guest/call/${response.data.data.guest_call_token}`
          : (event.meeting_token ? `${window.location.origin}/meet/${event.meeting_token}` : null))
      const adminLink = response.data?.data?.admin_meeting_link || null

      if (guestLink || adminLink) {
        createdMeetingLink.value = guestLink
        createdAdminMeetingLink.value = adminLink
        createdMeetingTitle.value = event.title || 'Meeting'
        showMeetingLinkDialog.value = true
      } else {
        emit('close')
      }

      emit('started')
    } else {
      toast.error('Failed to schedule meeting')
    }
  } catch (e) {
    console.error('Failed to schedule meeting:', e)
    toast.error(e.response?.data?.error || 'Failed to schedule meeting')
  } finally {
    meetingCreating.value = false
  }
}

// Guest call link
const guestLinkLoading = ref(false)

function openCallLinkForm() {
  showCallLinkForm.value = true
  showScheduleForm.value = false
  multiSelect.value = false
  selectedColleagues.value = []
}

async function createGuestCallLink() {
  guestLinkLoading.value = true
  try {
    const response = await api.post('/chat/guest-call-link', {
      waiting_room: callLinkWaitingRoom.value,
      participants_hidden: callLinkParticipantsHidden.value,
    })
    if (!response.data?.success) throw new Error(response.data?.error || 'Failed to create link')

    const { guest_link, admin_link } = response.data.data

    createdMeetingLink.value = guest_link
    createdAdminMeetingLink.value = admin_link
    createdMeetingTitle.value = 'Call link'
    showCallLinkForm.value = false
    showMeetingLinkDialog.value = true

    try {
      await navigator.clipboard.writeText(guest_link)
      toast.success('Share link copied. Use the host link to join yourself.')
    } catch (_) {
      toast.success('Call link ready. Copy the share link below.')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || e.message || 'Failed to create call link')
  } finally {
    guestLinkLoading.value = false
  }
}

function close() {
  emit('close')
}

// Default date/time for schedule form
function openScheduleForm() {
  showScheduleForm.value = true
  multiSelect.value = true
  const now = new Date()
  const tomorrow = new Date(now)
  tomorrow.setDate(tomorrow.getDate() + 1)
  meetingDate.value = tomorrow.toISOString().split('T')[0]
  meetingStartTime.value = '10:00'
  meetingEndTime.value = '11:00'
  meetingTitle.value = ''
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="show"
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9995]"
        @click.self="close"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md max-h-[85vh] flex flex-col mx-4">

          <!-- Header -->
          <div class="p-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2">
                <button
                  v-if="showScheduleForm || showCallLinkForm"
                  @click="showScheduleForm = false; showCallLinkForm = false"
                  class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
                >
                  <span class="material-symbols-rounded text-surface-500">arrow_back</span>
                </button>
                <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                  {{ showScheduleForm ? 'Schedule Meeting' : (showCallLinkForm ? 'Call Link' : 'New Conversation') }}
                </h2>
              </div>
              <button
                @click="close"
                class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                <span class="material-symbols-rounded text-surface-500">close</span>
              </button>
            </div>

            <!-- Search -->
            <div class="relative">
              <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
              <input
                v-model="searchQuery"
                type="text"
                placeholder="Search colleagues..."
                class="new-conv-search-input w-full pl-10 pr-4 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition-colors"
              />
            </div>
          </div>

          <!-- Quick Actions -->
          <div v-if="!showScheduleForm && !showCallLinkForm" class="px-4 pt-3 pb-2 space-y-2">
            <div class="grid grid-cols-2 gap-2">
              <!-- Group Chat -->
              <button
                @click="$emit('close'); $emit('open-group', [])"
                class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors text-left"
              >
                <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-blue-600 dark:text-blue-400 text-lg">group</span>
                </div>
                <div class="min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 leading-tight">Group Chat</p>
                  <p class="text-[11px] text-surface-500 leading-tight">Named group</p>
                </div>
              </button>

              <!-- Channel -->
              <button
                @click="$emit('close'); $emit('open-channel')"
                class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors text-left"
              >
                <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-purple-600 dark:text-purple-400 text-lg">tag</span>
                </div>
                <div class="min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 leading-tight">Channel</p>
                  <p class="text-[11px] text-surface-500 leading-tight">Topic-based</p>
                </div>
              </button>
            </div>

            <div class="grid grid-cols-3 gap-2">
              <!-- Instant Meeting -->
              <button
                @click="multiSelect = true"
                class="flex items-center gap-2 px-2.5 py-2.5 rounded-xl border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors text-left"
                :class="{ 'ring-2 ring-green-500 border-green-500 dark:border-green-500': multiSelect && !showScheduleForm }"
              >
                <div class="w-7 h-7 rounded-lg bg-green-100 dark:bg-green-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-green-600 dark:text-green-400 text-base">videocam</span>
                </div>
                <div class="min-w-0">
                  <p class="text-xs font-medium text-surface-900 dark:text-surface-100 leading-tight">Meeting</p>
                  <p class="text-[10px] text-surface-500 leading-tight">Start now</p>
                </div>
              </button>

              <!-- Schedule Meeting -->
              <button
                @click="openScheduleForm"
                class="flex items-center gap-2 px-2.5 py-2.5 rounded-xl border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors text-left"
                :class="{ 'ring-2 ring-amber-500 border-amber-500 dark:border-amber-500': showScheduleForm }"
              >
                <div class="w-7 h-7 rounded-lg bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-base">calendar_clock</span>
                </div>
                <div class="min-w-0">
                  <p class="text-xs font-medium text-surface-900 dark:text-surface-100 leading-tight">Schedule</p>
                  <p class="text-[10px] text-surface-500 leading-tight">Plan meeting</p>
                </div>
              </button>

              <!-- Call Link (highlighted - external sharing) -->
              <button
                @click="openCallLinkForm"
                :disabled="guestLinkLoading"
                :class="[
                  'flex items-center gap-2 px-2.5 py-2.5 rounded-xl border transition-colors text-left disabled:opacity-50',
                  showCallLinkForm
                    ? 'ring-2 ring-cyan-500 border-cyan-500 dark:border-cyan-500 bg-cyan-100 dark:bg-cyan-500/20'
                    : 'border-cyan-300 dark:border-cyan-500/40 bg-cyan-50 dark:bg-cyan-500/10 hover:bg-cyan-100 dark:hover:bg-cyan-500/20',
                ]"
              >
                <div class="w-7 h-7 rounded-lg bg-cyan-500 flex items-center justify-center flex-shrink-0">
                  <span v-if="guestLinkLoading" class="material-symbols-rounded text-white text-base animate-spin">progress_activity</span>
                  <span v-else class="material-symbols-rounded text-white text-base">add_link</span>
                </div>
                <div class="min-w-0">
                  <p class="text-xs font-medium text-cyan-700 dark:text-cyan-300 leading-tight">Call Link</p>
                  <p class="text-[10px] text-cyan-600/70 dark:text-cyan-400/70 leading-tight">Share with anyone</p>
                </div>
              </button>
            </div>
          </div>

          <!-- Call Link Form (shown when creating a standalone share link) -->
          <div v-if="showCallLinkForm" class="flex-1 overflow-y-auto px-4 py-4 space-y-4">
            <div class="bg-cyan-50 dark:bg-cyan-500/10 border border-cyan-200 dark:border-cyan-500/30 rounded-xl p-3 flex items-start gap-2.5">
              <span class="material-symbols-rounded text-cyan-600 dark:text-cyan-400 text-lg flex-shrink-0 mt-0.5">add_link</span>
              <div class="text-xs text-cyan-800 dark:text-cyan-200 leading-snug">
                Create a one-click video call link to share with anyone — no login required. You will get a separate host link to join with moderation rights.
              </div>
            </div>

            <div>
              <p class="text-xs font-semibold text-surface-700 dark:text-surface-200 mb-2">Call options</p>
              <MeetingSettingsToggles
                v-model:waiting-room="callLinkWaitingRoom"
                v-model:participants-hidden="callLinkParticipantsHidden"
                size="md"
                layout="column"
              />
              <ul class="mt-3 text-[11px] text-surface-500 dark:text-surface-400 space-y-1 pl-1">
                <li class="flex items-start gap-1.5">
                  <span class="material-symbols-rounded text-[14px] leading-none mt-0.5">door_front</span>
                  <span>Waiting room: guests wait in a lobby until you let them in.</span>
                </li>
                <li class="flex items-start gap-1.5">
                  <span class="material-symbols-rounded text-[14px] leading-none mt-0.5">visibility_off</span>
                  <span>Workshop mode: guests only see the host. They cannot see or hear each other.</span>
                </li>
              </ul>
            </div>

            <button
              @click="createGuestCallLink"
              :disabled="guestLinkLoading"
              class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold bg-cyan-500 text-white hover:bg-cyan-600 transition-colors disabled:opacity-50"
            >
              <span v-if="guestLinkLoading" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
              <span v-else class="material-symbols-rounded text-base">add_link</span>
              {{ guestLinkLoading ? 'Creating…' : 'Create call link' }}
            </button>
          </div>

          <!-- Schedule Form (shown when scheduling a meeting) -->
          <div v-if="showScheduleForm" class="px-4 pt-3 pb-2 space-y-3 border-b border-surface-200 dark:border-surface-700">
            <div>
              <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-1">Title</label>
              <input
                v-model="meetingTitle"
                type="text"
                placeholder="Meeting title (optional)"
                class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none"
              />
            </div>
            <div class="grid grid-cols-3 gap-2">
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-1">Date</label>
                <input
                  v-model="meetingDate"
                  type="date"
                  class="w-full px-2 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-1">Start</label>
                <input
                  v-model="meetingStartTime"
                  type="time"
                  class="w-full px-2 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-1">End</label>
                <input
                  v-model="meetingEndTime"
                  type="time"
                  class="w-full px-2 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none"
                />
              </div>
            </div>
            <!-- Back handled by header back button -->
          </div>

          <!-- Multi-select toggle -->
          <div v-if="!showScheduleForm && !showCallLinkForm" class="px-4 py-2 flex items-center justify-between">
            <p class="text-xs text-surface-500">
              {{ multiSelect ? 'Select people, then choose action below' : 'Click a person to start 1:1 chat' }}
            </p>
            <button
              @click="multiSelect = !multiSelect; if (!multiSelect) selectedColleagues = []"
              class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium transition-colors"
              :class="multiSelect
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400'
                : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
              "
            >
              <span class="material-symbols-rounded text-sm">{{ multiSelect ? 'check_box' : 'select_all' }}</span>
              {{ multiSelect ? 'Multi-select ON' : 'Multi-select' }}
            </button>
          </div>

          <!-- Selected chips -->
          <div v-if="selectedColleagues.length > 0 && !showCallLinkForm" class="px-4 pb-2">
            <div class="flex flex-wrap gap-1.5">
              <span
                v-for="c in selectedColleagues"
                :key="c.id"
                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400"
              >
                {{ c.display_name || c.email.split('@')[0] }}
                <button @click="removeSelected(c)" class="hover:text-red-500 transition-colors ml-0.5">
                  <span class="material-symbols-rounded text-xs">close</span>
                </button>
              </span>
            </div>
          </div>

          <!-- Colleague List -->
          <div v-if="!showCallLinkForm" class="flex-1 overflow-y-auto px-2 pb-2">
            <div
              v-for="colleague in filteredColleagues"
              :key="colleague.id"
              @click="handleColleagueClick(colleague)"
              class="flex items-center gap-3 px-3 py-2.5 rounded-xl cursor-pointer transition-colors"
              :class="[
                isSelected(colleague)
                  ? 'bg-primary-50 dark:bg-primary-500/10 ring-1 ring-primary-300 dark:ring-primary-500/30'
                  : 'hover:bg-surface-100 dark:hover:bg-surface-700'
              ]"
            >
              <!-- Selection indicator -->
              <div v-if="multiSelect" class="flex-shrink-0">
                <div
                  class="w-5 h-5 rounded-md border-2 flex items-center justify-center transition-colors"
                  :class="isSelected(colleague)
                    ? 'bg-primary-500 border-primary-500'
                    : 'border-surface-300 dark:border-surface-600'
                  "
                >
                  <span v-if="isSelected(colleague)" class="material-symbols-rounded text-white text-sm">check</span>
                </div>
              </div>

              <!-- Avatar -->
              <UserAvatar
                :colleague="colleague"
                size="lg"
              />

              <!-- Info -->
              <div class="flex-1 min-w-0">
                <p class="font-medium text-surface-900 dark:text-surface-100 truncate text-sm">
                  {{ colleague.display_name || colleague.email.split('@')[0] }}
                </p>
                <p class="text-xs text-surface-500 truncate">
                  {{ colleague.job_title || colleague.email }}
                </p>
              </div>

              <!-- Status dot -->
              <div
                :class="[
                  'w-2.5 h-2.5 rounded-full flex-shrink-0',
                  getStatusColor(colleague)
                ]"
              ></div>
            </div>

            <!-- Empty state -->
            <div
              v-if="filteredColleagues.length === 0"
              class="text-center py-8 text-surface-500"
            >
              <span class="material-symbols-rounded text-4xl mb-2 block">group_off</span>
              <p class="text-sm">{{ searchQuery ? 'No matches found' : 'No colleagues found' }}</p>
            </div>
          </div>

          <!-- Bottom Action Bar (when people are selected) -->
          <div
            v-if="selectedColleagues.length > 0 && !showCallLinkForm"
            class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 rounded-b-2xl"
          >
            <!-- Meeting toggles (waiting room / workshop mode) -->
            <MeetingSettingsToggles
              v-if="multiSelect"
              v-model:waiting-room="meetingWaitingRoom"
              v-model:participants-hidden="meetingParticipantsHidden"
              class="mb-2"
            />

            <div class="flex items-center gap-2">
              <p class="text-xs text-surface-500 flex-shrink-0">
                {{ selectedColleagues.length }} selected
              </p>
              <div class="flex-1"></div>

              <!-- Instant meeting button (when multiselect on) -->
              <button
                v-if="multiSelect && !showScheduleForm"
                @click="startInstantMeeting"
                :disabled="meetingCreating"
                class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-green-500 text-white hover:bg-green-600 transition-colors disabled:opacity-50"
              >
                <span class="material-symbols-rounded text-sm">videocam</span>
                {{ meetingCreating ? 'Creating...' : 'Meet Now' }}
              </button>

              <!-- Schedule meeting button -->
              <button
                v-if="showScheduleForm"
                @click="scheduleTheMeeting"
                :disabled="meetingCreating || !meetingDate || !meetingStartTime || !meetingEndTime"
                class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-amber-500 text-white hover:bg-amber-600 transition-colors disabled:opacity-50"
              >
                <span class="material-symbols-rounded text-sm">calendar_clock</span>
                {{ meetingCreating ? 'Scheduling...' : 'Schedule' }}
              </button>

              <!-- Start chat / group chat -->
              <button
                v-if="!showScheduleForm"
                @click="startGroupChat"
                class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
              >
                <span class="material-symbols-rounded text-sm">
                  {{ selectedColleagues.length === 1 ? 'chat' : 'group' }}
                </span>
                {{ selectedColleagues.length === 1 ? 'Start Chat' : 'Group Chat' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <MeetingLinkDialog
    :show="showMeetingLinkDialog"
    :meeting-link="createdMeetingLink"
    :admin-meeting-link="createdAdminMeetingLink"
    :meeting-title="createdMeetingTitle"
    :header-label="createdMeetingTitle === 'Call link' ? 'Call link ready' : ''"
    :share-note="createdMeetingTitle === 'Call link' ? 'Share this link with anyone you want to call:' : ''"
    :show-invite-note="createdMeetingTitle !== 'Call link'"
    @close="onMeetingLinkDialogClose"
  />
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-active > div,
.modal-leave-active > div {
  transition: transform 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-from > div {
  transform: scale(0.95);
}
.modal-leave-to > div {
  transform: scale(0.95);
}
</style>

