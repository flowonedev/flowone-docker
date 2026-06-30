<script>
// Module-scoped caches dedupe network calls across all instances of the
// component (e.g. multiple reminder cards for the same meeting).
const linkCache = new Map()
const participantCache = new Map()
</script>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'

const props = defineProps({
  eventId: { type: [Number, String], required: true },
  startTime: { type: String, default: '' },
  endTime: { type: String, default: '' },
  // Whether the current viewer is the host/organizer of this meeting.
  // Host sees both the admin link and the participant link; invitees only
  // see the participant link.
  isHost: { type: Boolean, default: false },
  // Optional preloaded links (avoids a network call when already known).
  meetingLink: { type: String, default: '' },
  adminLink: { type: String, default: '' },
  // 'stack' shows buttons full-width stacked (reminder popup);
  // 'row' shows them inline (chat header).
  layout: { type: String, default: 'stack' },
  showWhen: { type: Boolean, default: true },
})

const { t } = useI18n()
const calendar = useCalendarStore()

const guestLink = ref(props.meetingLink || '')
const hostLink = ref(props.adminLink || '')
const linksLoading = ref(false)
const linksLoaded = ref(!!props.meetingLink)

const participants = ref([])
const participantsLoading = ref(false)
const participantsLoaded = ref(false)
const showParticipants = ref(false)

const whenLabel = computed(() => {
  if (!props.startTime) return ''
  const start = new Date(props.startTime)
  if (Number.isNaN(start.getTime())) return ''
  const dateStr = start.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
  const startStr = start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  if (!props.endTime) return `${dateStr} · ${startStr}`
  const end = new Date(props.endTime)
  if (Number.isNaN(end.getTime())) return `${dateStr} · ${startStr}`
  const endStr = end.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  return `${dateStr} · ${startStr} – ${endStr}`
})

async function ensureLinks() {
  if (linksLoaded.value || linksLoading.value) return
  const cacheKey = String(props.eventId)
  if (linkCache.has(cacheKey)) {
    const cached = linkCache.get(cacheKey)
    guestLink.value = cached.guest || guestLink.value
    hostLink.value = cached.admin || hostLink.value
    linksLoaded.value = true
    return
  }
  linksLoading.value = true
  try {
    const res = await calendar.getEventMeeting(props.eventId)
    if (res.success && res.data) {
      guestLink.value = res.data.meeting_link || guestLink.value
      hostLink.value = res.data.admin_meeting_link || hostLink.value
      linkCache.set(cacheKey, { guest: guestLink.value, admin: hostLink.value })
      linksLoaded.value = true
    }
  } catch (e) {
    // Non-fatal: the participant link from the list payload may still work.
  } finally {
    linksLoading.value = false
  }
}

function navigateToLink(link) {
  if (!link) return
  // ALWAYS open meetings in a new window/tab — never replace the app.
  try {
    const u = new URL(link, window.location.origin)
    window.open(u.href, '_blank', 'noopener')
  } catch (_) {
    window.open(link, '_blank', 'noopener')
  }
}

async function openParticipantLink() {
  await ensureLinks()
  navigateToLink(guestLink.value)
}

async function openAdminLink() {
  await ensureLinks()
  navigateToLink(hostLink.value || guestLink.value)
}

async function loadParticipants() {
  showParticipants.value = true
  if (participantsLoaded.value || participantsLoading.value) return
  const cacheKey = String(props.eventId)
  if (participantCache.has(cacheKey)) {
    participants.value = participantCache.get(cacheKey)
    participantsLoaded.value = true
    return
  }
  participantsLoading.value = true
  try {
    const list = await calendar.getParticipants(props.eventId)
    participants.value = Array.isArray(list) ? list : []
    participantCache.set(cacheKey, participants.value)
    participantsLoaded.value = true
  } catch (e) {
    participants.value = []
  } finally {
    participantsLoading.value = false
  }
}

function statusLabel(status) {
  switch (status) {
    case 'accepted': return t('calendarView.accepted')
    case 'declined': return t('calendarView.declined')
    case 'tentative': return t('meetingActions.tentative')
    case 'pending':
    default: return t('calendarView.pending')
  }
}

function statusClass(status) {
  switch (status) {
    case 'accepted': return 'bg-green-500'
    case 'declined': return 'bg-red-500'
    case 'tentative': return 'bg-amber-500'
    default: return 'bg-surface-300 dark:bg-surface-600'
  }
}

function nameFromEmail(email) {
  if (!email) return ''
  const local = email.split('@')[0]
  return local.charAt(0).toUpperCase() + local.slice(1)
}

// Preload links so the buttons are responsive on first click.
onMounted(() => {
  if (!linksLoaded.value) ensureLinks()
})
</script>

<template>
  <div class="flex flex-col gap-2">
    <!-- When -->
    <div v-if="showWhen && whenLabel" class="flex items-center gap-1.5 text-xs text-surface-500 dark:text-surface-400">
      <span class="material-symbols-rounded text-sm">schedule</span>
      <span>{{ whenLabel }}</span>
    </div>

    <!-- Buttons + participants -->
    <div
      :class="[
        'flex gap-2',
        layout === 'stack' ? 'flex-col' : 'flex-row flex-wrap items-center',
      ]"
    >
      <!-- Admin / host link (host only) -->
      <button
        v-if="isHost"
        type="button"
        @click="openAdminLink"
        :disabled="linksLoading"
        :class="[
          'inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition-colors',
          'bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-60',
          layout === 'stack' ? 'w-full px-3 py-1.5 text-sm' : 'px-2.5 py-1.5 text-xs sm:text-sm',
        ]"
        :title="$t('meetingActions.openAdminLinkTitle')"
      >
        <span class="material-symbols-rounded text-base leading-none">shield_person</span>
        <!-- Row layout (chat header) uses a short label on mobile so both link
             buttons fit on one line; full label everywhere else. -->
        <template v-if="layout === 'row'">
          <span class="hidden sm:inline">{{ $t('meetingActions.openAdminLink') }}</span>
          <span class="sm:hidden">{{ $t('meetingActions.adminShort') }}</span>
        </template>
        <template v-else>{{ $t('meetingActions.openAdminLink') }}</template>
      </button>

      <!-- Participant / guest link (everyone) -->
      <button
        type="button"
        @click="openParticipantLink"
        :disabled="linksLoading"
        :class="[
          'inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition-colors',
          'bg-primary-500 hover:bg-primary-600 text-white disabled:opacity-60',
          layout === 'stack' ? 'w-full px-3 py-1.5 text-sm' : 'px-2.5 py-1.5 text-xs sm:text-sm',
        ]"
        :title="$t('meetingActions.openParticipantLinkTitle')"
      >
        <span class="material-symbols-rounded text-base leading-none">group</span>
        <template v-if="layout === 'row'">
          <span class="hidden sm:inline">{{ $t('meetingActions.openParticipantLink') }}</span>
          <span class="sm:hidden">{{ $t('meetingActions.participantShort') }}</span>
        </template>
        <template v-else>{{ $t('meetingActions.openParticipantLink') }}</template>
      </button>

      <!-- Participants / invitees (hover) -->
      <div
        class="relative inline-flex"
        @mouseenter="loadParticipants"
        @mouseleave="showParticipants = false"
      >
        <button
          type="button"
          class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          :title="$t('meetingActions.participants')"
          @click="loadParticipants"
        >
          <span class="material-symbols-rounded text-lg leading-none">groups</span>
        </button>

        <!-- Popover -->
        <div
          v-if="showParticipants"
          class="absolute z-50 bottom-full right-0 mb-2 w-60 rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 shadow-lg p-2"
        >
          <p class="px-2 pb-1.5 text-xs font-semibold text-surface-500 dark:text-surface-400">
            {{ $t('meetingActions.participants') }}
          </p>
          <div v-if="participantsLoading" class="px-2 py-2 text-xs text-surface-400">
            {{ $t('meetingActions.loading') }}
          </div>
          <div v-else-if="participants.length === 0" class="px-2 py-2 text-xs text-surface-400">
            {{ $t('meetingActions.noParticipants') }}
          </div>
          <ul v-else class="max-h-56 overflow-y-auto space-y-0.5">
            <li
              v-for="p in participants"
              :key="p.email || p.user_email"
              class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-750"
            >
              <span
                class="w-2 h-2 rounded-full flex-shrink-0"
                :class="statusClass(p.status)"
                :title="statusLabel(p.status)"
              ></span>
              <div class="min-w-0 flex-1">
                <p class="text-xs font-medium text-surface-800 dark:text-surface-200 truncate">
                  {{ p.display_name || nameFromEmail(p.email || p.user_email) }}
                </p>
                <p class="text-[11px] text-surface-400 truncate">{{ p.email || p.user_email }}</p>
              </div>
              <span class="text-[10px] text-surface-400 flex-shrink-0">{{ statusLabel(p.status) }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
