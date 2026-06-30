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
import { isElectron } from '@/services/electronApi'
import ToastContainer from '@/components/shared/ToastContainer.vue'
import CallOverlay from '@/components/call/CallOverlay.vue'
import IncomingCallModal from '@/components/call/IncomingCallModal.vue'
import CallPip from '@/components/call/CallPip.vue'
import TitleBar from './components/TitleBar.vue'
import { onMounted, onUnmounted, watch, ref, computed } from 'vue'

const showTitleBar = computed(() => isElectron())
const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()
const chatStore = useChatStore()
const callStore = useCallStore()
const huddleStore = useHuddleStore()

const maximizePadding = ref({ top: 0, right: 0, bottom: 0, left: 0 })

const totalBadgeCount = computed(() => chatStore.totalUnread || 0)

watch(totalBadgeCount, (count) => {
  document.title = count > 0 ? `(${count}) FlowOne - Chat` : 'FlowOne - Chat'
  if (isElectron() && window.api?.setBadgeCount) {
    window.api.setBadgeCount(count)
  }
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
}

function cleanupChatFeatures() {
  mailSync.cleanup()
  presence.cleanup()
  callStore.cleanupCall()
  chatNotifications.cleanup()
}

onMounted(async () => {
  if (isElectron()) {
    await auth.initFromElectron()
    window.api.on('maximize-padding', (padding) => { maximizePadding.value = padding })
    window.api.on('auth-failed', () => { auth.clearAuth(); router.replace('/login') })
    window.api.on('forced-logout', () => { auth.clearAuth(); router.replace('/login') })

    if (window.api?.sso?.onAuthenticated) {
      window.api.sso.onAuthenticated(async () => {
        await auth.initFromElectron()
        if (router.currentRoute.value.name === 'login') {
          router.push({ name: 'chat' })
        }
      })
    }
  }

  await theme.initTheme()

  if (auth.isAuthenticated) {
    auth.checkAuth().catch(() => {})
    initChatFeatures()
  }
})

onUnmounted(() => {
  cleanupChatFeatures()
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
  <div
    class="h-screen flex flex-col overflow-hidden"
    :style="{
      paddingTop: maximizePadding.top + 'px',
      paddingRight: maximizePadding.right + 'px',
      paddingBottom: maximizePadding.bottom + 'px',
      paddingLeft: maximizePadding.left + 'px',
    }"
  >
    <TitleBar v-if="showTitleBar" />
    <div class="main-content flex-1 overflow-hidden">
      <RouterView />
    </div>
  </div>

  <ToastContainer />

  <!-- WebSocket status indicator -->
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

  <!-- Call UI (voice/video) -->
  <template v-if="auth.isAuthenticated">
    <CallOverlay v-if="callStore.isInCall && !callStore.isMinimized && !(callStore.isRinging && callStore.callDirection === 'incoming')" />
    <IncomingCallModal v-if="callStore.isRinging && callStore.callDirection === 'incoming' && !callStore.nativeRingActive" />
    <CallPip v-if="callStore.isInCall && callStore.isMinimized" />
  </template>
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
</style>
