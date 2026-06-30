<script setup>
/**
 * MeetingJoinView - Join a scheduled meeting via a meeting token link.
 * 
 * Flow: User clicks meeting link -> logs in (if needed) -> lands here ->
 * fetches meeting details -> user clicks "Join Meeting" -> navigates to chat 
 * and starts the call.
 */
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCallLauncher } from '@/composables/useCallLauncher'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'

const route = useRoute()
const router = useRouter()
const callLauncher = useCallLauncher()
const chatStore = useChatStore()
const toast = useToastStore()
const auth = useAuthStore()
const { t, locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

const status = ref('loading') // 'loading' | 'ready' | 'joining' | 'error'
const errorMessage = ref('')
const meeting = ref(null)
/** Unified public join URL (/guest/call/...) when API returns it */
const guestJoinUrl = ref(null)

const meetingTime = computed(() => {
  if (!meeting.value) return ''
  const start = new Date(meeting.value.start_time)
  const end = new Date(meeting.value.end_time)
  const dateOpts = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }
  const timeOpts = { hour: '2-digit', minute: '2-digit' }
  const dateStr = start.toLocaleDateString(localeTag.value, dateOpts)
  const startStr = start.toLocaleTimeString(localeTag.value, timeOpts)
  const endStr = end.toLocaleTimeString(localeTag.value, timeOpts)
  return t('meetingJoinView.meetingTimeRange', { date: dateStr, start: startStr, end: endStr })
})

const isMeetingNow = computed(() => {
  if (!meeting.value) return false
  const now = Date.now()
  const start = new Date(meeting.value.start_time).getTime()
  const end = new Date(meeting.value.end_time).getTime()
  // Allow joining 5 minutes before start and until end
  return now >= (start - 5 * 60 * 1000) && now <= end
})

const isMeetingPast = computed(() => {
  if (!meeting.value) return false
  return Date.now() > new Date(meeting.value.end_time).getTime()
})

const isMeetingFuture = computed(() => {
  if (!meeting.value) return false
  const start = new Date(meeting.value.start_time).getTime()
  return Date.now() < (start - 5 * 60 * 1000)
})

onMounted(async () => {
  const token = route.params.token
  
  if (!token) {
    status.value = 'error'
    errorMessage.value = 'meetingJoinView.invalidMeetingLinkNoToken'
    return
  }
  
  try {
    const response = await api.get(`/meetings/${token}`)
    const payload = response.data?.data
    const joinUrl = payload?.redirect_to || payload?.meeting_link
    if (joinUrl && String(joinUrl).includes('/guest/call/')) {
      try {
        const u = new URL(joinUrl, window.location.origin)
        guestJoinUrl.value = u.pathname + u.search
        await router.replace(guestJoinUrl.value)
        return
      } catch (_) {
        guestJoinUrl.value = null
      }
    }
    if (payload?.event) {
      meeting.value = payload.event
      status.value = 'ready'
    } else {
      status.value = 'error'
      errorMessage.value = 'meetingJoinView.meetingNotFound'
    }
  } catch (err) {
    status.value = 'error'
    if (err.response?.status === 404) {
      errorMessage.value = 'meetingJoinView.meetingDoesNotExistOrExpired'
    } else if (err.response?.status === 401) {
      errorMessage.value = 'meetingJoinView.pleaseLogInToJoinMeeting'
    } else {
      errorMessage.value = err.response?.data?.error || 'meetingJoinView.failedToLoadMeetingDetails'
    }
  }
})

async function joinMeeting() {
  if (!meeting.value) return

  status.value = 'joining'

  try {
    if (guestJoinUrl.value) {
      await router.push(guestJoinUrl.value)
      return
    }

    const token = route.params.token
    const response = await api.get(`/meetings/${token}`)
    const joinUrl = response.data?.data?.redirect_to || response.data?.data?.meeting_link
    if (joinUrl && String(joinUrl).includes('/guest/call/')) {
      const u = new URL(joinUrl, window.location.origin)
      await router.push(u.pathname + u.search)
      return
    }

    await chatStore.init()

    const conversationId = meeting.value.meeting_conversation_id

    if (conversationId) {
      chatStore.setActiveConversation(conversationId)
      await router.push('/chat')
      setTimeout(() => {
        const conversation = chatStore.conversations.find(c => c.id === conversationId)
        if (conversation) {
          const participants = (conversation.participants || [])
            .map(p => p.email)
            .filter(e => e !== auth.userEmail)

          callLauncher.startCall(conversationId, 'video', participants)
        }
        toast.success(t('meetingJoinView.joiningMeeting'))
      }, 1000)
    } else {
      toast.info(t('meetingJoinView.meetingChatIsNotAvailable'))
      router.push('/chat')
    }
  } catch (err) {
    console.error('[MeetingJoinView] Failed to join meeting:', err)
    status.value = 'ready'
    toast.error(t('meetingJoinView.failedToJoinMeetingPlease'))
  }
}

function goHome() {
  router.push('/inbox')
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-900 p-4">
    <div class="max-w-lg w-full bg-white dark:bg-surface-800 rounded-2xl shadow-xl overflow-hidden">
      
      <!-- Loading -->
      <template v-if="status === 'loading'">
        <div class="p-8 text-center">
          <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-3xl text-primary-500 animate-spin">progress_activity</span>
          </div>
          <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">
            {{ $t('meetingJoinView.loadingMeeting') }}
          </h2>
          <p class="text-surface-500">
            {{ $t('meetingJoinView.fetchingMeetingDetails') }}
          </p>
        </div>
      </template>
      
      <!-- Meeting Ready -->
      <template v-else-if="status === 'ready' || status === 'joining'">
        <!-- Meeting Header -->
        <div class="bg-gradient-to-r from-primary-500 to-primary-600 p-6 text-white">
          <div class="flex items-center gap-3 mb-3">
            <span class="material-symbols-rounded text-3xl">videocam</span>
            <span class="text-sm font-medium opacity-80">{{ $t('meetingJoinView.onlineMeeting') }}</span>
          </div>
          <h1 class="text-2xl font-bold">{{ meeting.title }}</h1>
          <p v-if="meeting.organizer_email" class="mt-1 text-sm opacity-80">
            {{ $t('meetingJoinView.organizedBy', { email: meeting.organizer_email }) }}
          </p>
        </div>
        
        <!-- Meeting Details -->
        <div class="p-6 space-y-4">
          <!-- Time -->
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-surface-400 mt-0.5">schedule</span>
            <div>
              <p class="text-surface-900 dark:text-surface-100 font-medium">{{ meetingTime }}</p>
              <p v-if="isMeetingNow" class="text-sm text-green-600 dark:text-green-400 font-medium mt-1">
                <span class="inline-block w-2 h-2 bg-green-500 rounded-full mr-1 animate-pulse"></span>
                {{ $t('meetingJoinView.happeningNow') }}
              </p>
              <p v-else-if="isMeetingFuture" class="text-sm text-amber-600 dark:text-amber-400 mt-1">
                {{ $t('meetingJoinView.notStartedYet') }}
              </p>
              <p v-else-if="isMeetingPast" class="text-sm text-surface-500 mt-1">
                {{ $t('meetingJoinView.thisMeetingHasEnded') }}
              </p>
            </div>
          </div>
          
          <!-- Description -->
          <div v-if="meeting.description" class="flex items-start gap-3">
            <span class="material-symbols-rounded text-surface-400 mt-0.5">notes</span>
            <p class="text-surface-700 dark:text-surface-300 text-sm whitespace-pre-wrap">{{ meeting.description }}</p>
          </div>
          
          <!-- Join Button -->
          <div class="pt-4">
            <button
              @click="joinMeeting"
              :disabled="status === 'joining' || isMeetingPast"
              class="w-full py-4 rounded-xl font-semibold text-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              :class="isMeetingPast 
                ? 'bg-surface-200 dark:bg-surface-700 text-surface-500 cursor-not-allowed' 
                : 'bg-primary-500 hover:bg-primary-600 text-white shadow-lg hover:shadow-xl'"
            >
              <span v-if="status === 'joining'" class="flex items-center justify-center gap-2">
                <span class="material-symbols-rounded animate-spin">progress_activity</span>
                {{ $t('meetingJoinView.joining') }}
              </span>
              <span v-else-if="isMeetingPast" class="flex items-center justify-center gap-2">
                <span class="material-symbols-rounded">event_busy</span>
                {{ $t('meetingJoinView.meetingEnded') }}
              </span>
              <span v-else class="flex items-center justify-center gap-2">
                <span class="material-symbols-rounded">videocam</span>
                {{ $t('meetingJoinView.joinMeeting') }}
              </span>
            </button>
            
            <p v-if="isMeetingFuture" class="text-center text-sm text-surface-500 mt-2">
              {{ $t('meetingJoinView.joinUpTo5MinutesBeforeStart') }}
            </p>
          </div>
        </div>
      </template>
      
      <!-- Error -->
      <template v-else>
        <div class="p-8 text-center">
          <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-3xl text-red-500">error</span>
          </div>
          <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">
            {{ $t('meetingJoinView.meetingNotAvailable') }}
          </h2>
          <p class="text-surface-500 mb-6">
            {{ typeof errorMessage === 'string' && errorMessage.startsWith('meetingJoinView.') ? t(errorMessage) : errorMessage }}
          </p>
          <button
            @click="goHome"
            class="px-6 py-3 bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors font-medium"
          >
            {{ $t('meetingJoinView.goToHome') }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

