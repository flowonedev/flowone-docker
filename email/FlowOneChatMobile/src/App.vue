<script setup>
import { RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'
import { useHuddleStore } from '@/stores/huddle'
import { useMailSyncIntegration } from '@/composables/useMailSyncIntegration'
import { usePresence } from '@/composables/usePresence'
import { useChatNotifications } from './composables/useChatNotifications.js'
import ToastContainer from '@/components/shared/ToastContainer.vue'
import CallOverlay from '@/components/call/CallOverlay.vue'
import IncomingCallModal from '@/components/call/IncomingCallModal.vue'
import CallPip from '@/components/call/CallPip.vue'
import PreCallDeviceModal from '@/components/call/PreCallDeviceModal.vue'
import DeviceApprovalModal from '@/components/DeviceApprovalModal.vue'
import { useCallLauncher } from '@/composables/useCallLauncher'
import { App as CapApp } from '@capacitor/app'
import { Browser } from '@capacitor/browser'
import { onMounted, onUnmounted, watch, computed } from 'vue'
import api from '@/services/api'
import { pushNotifications } from '@/services/pushNotifications'
import { callKit } from '@/services/callKit'

const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()
const chatStore = useChatStore()
const callStore = useCallStore()
const callLauncher = useCallLauncher()
const huddleStore = useHuddleStore()

const totalBadgeCount = computed(() => chatStore.totalUnread || 0)

// Report the authoritative unread total to the backend (debounced) so native
// push (iOS aps.badge / Android notificationCount) can seed the app-icon badge.
let badgeSyncTimer = null
let lastSyncedBadge = -1
function syncBadgeToServer(count) {
  const next = Math.max(0, count | 0)
  if (!auth.isAuthenticated || next === lastSyncedBadge) return
  if (badgeSyncTimer) clearTimeout(badgeSyncTimer)
  badgeSyncTimer = setTimeout(() => {
    lastSyncedBadge = next
    api.post('/push/badge', { count: next }).catch(() => {})
  }, 1500)
}

watch(totalBadgeCount, (count) => {
  document.title = count > 0 ? `(${count}) FlowOne - Chat` : 'FlowOne - Chat'

  // Web Badging API is unavailable in the Capacitor WKWebView, so drive the
  // native app-icon badge directly — this is what clears it once read.
  pushNotifications.setBadge(count)

  syncBadgeToServer(count)
}, { immediate: true })

const mailSync = useMailSyncIntegration()
const wsConnected = computed(() => mailSync.connectionState?.value === 'connected')
const wsState = computed(() => mailSync.connectionState?.value || 'disconnected')
const presence = usePresence()
const chatNotifications = useChatNotifications()

function initChatFeatures() {
  mailSync.init()
  presence.init()
  callStore.setupSocketListeners()
  huddleStore.setupSocketListeners()
  huddleStore.fetchAllActiveHuddles()
  chatNotifications.init()
  // Native push (FCM token registration + tap routing). Best-effort: never
  // blocks chat startup if Firebase/permissions are unavailable.
  pushNotifications.init(router).catch(() => {})
  // Native full-screen call UI bridge (iOS CallKit / Android FSI). No-op when
  // the CallNative plugin isn't present.
  callKit.init().catch(() => {})
}

function cleanupChatFeatures() {
  mailSync.cleanup()
  presence.cleanup()
  callStore.cleanupCall()
  chatNotifications.cleanup()
}

async function handleOAuthDeepLink(url) {
  try {
    const parsed = new URL(url)
    const oauthSuccess = parsed.searchParams.get('oauth_success')
    const oauthError = parsed.searchParams.get('oauth_error')

    await Browser.close().catch(() => {})

    if (oauthSuccess) {
      const tokenData = JSON.parse(atob(oauthSuccess))
      await auth.setTokens(tokenData)
      router.replace('/chat')
    } else if (oauthError) {
      router.replace({ path: '/login', query: { oauth_error: oauthError } })
    }
  } catch (e) {
    console.error('OAuth deep link error:', e)
    router.replace('/login')
  }
}

// CallKit opens flowone-chat://call/answer?callId=... to foreground the app when
// the user answers on the lock screen. The richer native `callAnswered` plugin
// event (with conversationId/callType/caller) drives the actual join once the
// WebView resumes; this handler is a foreground-time fallback that answers from
// the callId alone if that event was missed. acceptFromNative is guarded/
// idempotent, so a redundant call here is safe.
function handleCallAnswerDeepLink(url) {
  try {
    const callId = new URL(url).searchParams.get('callId') || null
    if (callId && callStore.callId !== callId) {
      callStore.acceptFromNative(callId)
    }
  } catch (e) {
    console.error('Call answer deep link error:', e)
  }
}

let deepLinkListener = null

onMounted(async () => {
  await theme.initTheme()

  deepLinkListener = await CapApp.addListener('appUrlOpen', ({ url }) => {
    if (!url.startsWith('flowone-chat://')) return
    if (url.includes('oauth_success') || url.includes('oauth_error')) {
      handleOAuthDeepLink(url)
    } else if (url.includes('call/answer')) {
      handleCallAnswerDeepLink(url)
    }
  })

  if (auth.hasToken) {
    auth.checkAuth().catch(() => {})
    initChatFeatures()
  }
})

onUnmounted(() => {
  cleanupChatFeatures()
  if (deepLinkListener) {
    deepLinkListener.remove()
  }
})

watch(() => auth.isAuthenticated, async (isAuth) => {
  if (isAuth) {
    await theme.initTheme()
    auth.checkAuth().catch(() => {})
    initChatFeatures()
  } else {
    cleanupChatFeatures()
    router.replace('/login')
  }
})
</script>

<template>
  <div class="h-screen h-[100dvh] flex flex-col overflow-hidden safe-area-padding">
    <div class="main-content flex-1 overflow-hidden">
      <RouterView />
    </div>
  </div>

  <ToastContainer />

  <Transition name="fade">
    <div
      v-if="auth.isAuthenticated && !wsConnected"
      class="fixed bottom-3 left-3 z-[9998] flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium shadow-lg backdrop-blur-sm"
      :class="wsState === 'reconnecting' ? 'bg-amber-500/90 text-white' : 'bg-red-500/90 text-white'"
    >
      <span class="w-2 h-2 rounded-full animate-pulse" :class="wsState === 'reconnecting' ? 'bg-amber-200' : 'bg-red-200'"></span>
      <span class="material-symbols-rounded text-sm">{{ wsState === 'reconnecting' ? 'sync' : 'cloud_off' }}</span>
      {{ wsState === 'reconnecting' ? 'Reconnecting...' : 'Offline' }}
    </div>
  </Transition>

  <template v-if="auth.isAuthenticated">
    <CallOverlay v-if="callStore.isInCall && !callStore.isMinimized && !(callStore.isRinging && callStore.callDirection === 'incoming')" />
    <IncomingCallModal v-if="callStore.isRinging && callStore.callDirection === 'incoming' && !callStore.nativeRingActive" />
    <CallPip v-if="callStore.isInCall && callStore.isMinimized" />
    <PreCallDeviceModal
      v-if="callLauncher.isModalOpen.value"
      :mode="callLauncher.modalMode.value"
      :callType="callLauncher.modalCallType.value"
      :callerName="callLauncher.modalCallerName.value"
      @confirm="callLauncher.confirmModal"
      @cancel="callLauncher.cancelModal"
    />
  </template>

  <!-- Approve a sign-in started on another device (taps the matching number) -->
  <DeviceApprovalModal v-if="auth.isAuthenticated" />
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.3s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }

.main-content :deep(> *) {
  height: 100% !important;
  max-height: 100% !important;
}

.safe-area-padding {
  /* NOTE: do NOT pad the top here. Each view's header owns the notch inset via
     `.safe-area-top` / `.min-h-safe-top` (same as the email app). Adding it here
     too double-applies env(safe-area-inset-top) and pushes the header down. */
  padding-bottom: env(safe-area-inset-bottom, 0px);
  padding-left: env(safe-area-inset-left, 0px);
  padding-right: env(safe-area-inset-right, 0px);
}
</style>
