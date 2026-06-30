<script setup>
import { RouterView, useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { useClientsStore } from '@/stores/clients'
import { useSearchStore } from '@/addons/universal-search/stores/search'
import { useMailboxStore } from '@/stores/mailbox'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'
import { useHuddleStore } from '@/stores/huddle'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useNotificationsStore } from '@/stores/notifications'
import { useTimeTracker } from '@/addons/time-tracker/composables/useTimeTracker'
import { useMailSyncIntegration } from '@/composables/useMailSyncIntegration'
import { usePresence } from '@/composables/usePresence'
import { isElectron } from '@/services/electronApi'
import { startIdleMonitoring, stopIdleMonitoring, isWarningVisible, countdownSeconds, dismissWarning, isEnabled as idleEnabled, setRouter as setIdleRouter } from '@/services/idleLogout'
import { useAddons } from '@/composables/useAddons'
import { bootstrap as fetchBootstrap, hydrateStores, resetBootstrap } from '@/services/bootstrap'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import ToastContainer from '@/components/shared/ToastContainer.vue'
import MindMap from '@/components/mindmap/MindMap.vue'
import SuperSearch from '@/addons/universal-search/components/SuperSearch.vue'
import FloatingChatWidget from '@/addons/chat/components/FloatingChatWidget.vue'
import TodoPanel from '@/addons/tasks/components/TodoPanel.vue'
import NotificationPanel from '@/components/NotificationPanel.vue'
import CallOverlay from '@/components/call/CallOverlay.vue'
import IncomingCallModal from '@/components/call/IncomingCallModal.vue'
import CallPip from '@/components/call/CallPip.vue'
import OnboardingPopup from '@/components/onboarding/OnboardingPopup.vue'
import FeedbackButton from '@/components/feedback/FeedbackButton.vue'
import FeedbackModal from '@/components/feedback/FeedbackModal.vue'
import ComposeWindow from '@/components/ComposeWindow.vue'
import TitleBar from './components/TitleBar.vue'
import DatabaseDebugPanel from './components/DatabaseDebugPanel.vue'
import LockScreen from './components/LockScreen.vue'
import { onMounted, onUnmounted, watch, ref, computed } from 'vue'

const router = useRouter()
const route = useRoute()
setIdleRouter(router)
const showTitleBar = computed(() => isElectron())
const isMoodboardRoute = computed(() => route.path.startsWith('/mood'))
const isMacOS = computed(() => isElectron() && window.api?.platform === 'darwin')
const isAppLocked = ref(false)
const dbSyncDebugEnabled = ref(false)

function refreshDbSyncDebugSetting() {
  if (isElectron() && window.api?.config) {
    window.api.config.get('dbSyncDebugEnabled').then(v => { dbSyncDebugEnabled.value = !!v })
  }
}
refreshDbSyncDebugSetting()
router.afterEach((to, from) => {
  if (from.path === '/settings') refreshDbSyncDebugSetting()
})

const auth = useAuthStore()
const theme = useThemeStore()
const layout = useLayoutStore()
const clientsStore = useClientsStore()
const searchStore = useSearchStore()
const mailbox = useMailboxStore()
const chatStore = useChatStore()
const callStore = useCallStore()
const huddleStore = useHuddleStore()
const todosStore = useTodosStore()
const notificationsStore = useNotificationsStore()
const { chatEnabled, universalSearchEnabled, fetchAddons: fetchAddonStatus } = useAddons()

const updateAvailable = ref(false)
const isUpdating = ref(false)
const bootstrapReady = ref(false)

async function runBootstrap() {
  try {
    const data = await fetchBootstrap()
    await hydrateStores(data)
    bootstrapReady.value = true
  } catch (e) {
    console.warn('[App] Bootstrap failed, falling back to individual fetches:', e.message)
    bootstrapReady.value = true
  }
}

const maximizePadding = ref({ top: 0, right: 0, bottom: 0, left: 0 })

const totalBadgeCount = computed(() => {
  return (mailbox.unreadCount || 0) + (chatStore.totalUnread || 0) + (notificationsStore.missedCallUnreadCount || 0)
})

watch(totalBadgeCount, (count) => {
  document.title = count > 0 ? `(${count}) FlowOne - Email` : 'FlowOne - Email'
  if (isElectron() && window.api?.setBadgeCount) {
    window.api.setBadgeCount(count)
  }
  if (isElectron() && window.api?.tray?.setUnread) {
    window.api.tray.setUnread(count > 0)
  }
}, { immediate: true })

const timeTracker = useTimeTracker()

const mailSync = useMailSyncIntegration()

const wsConnected = computed(() => mailSync.connectionState?.value === 'connected')
const wsState = computed(() => mailSync.connectionState?.value || 'disconnected')

const presence = usePresence()

function handleNavigateTo(event) {
  const { view, folderId, boardId, cardId, eventId, ownerEmail } = event.detail || {}
  if (!view) return
  
  switch (view) {
    case 'drive':
      if (folderId) {
        if (ownerEmail && auth.userEmail && ownerEmail.toLowerCase() !== auth.userEmail.toLowerCase()) {
          router.push({ path: '/drive', query: { view: 'shared', shared: folderId.toString() } })
        } else {
          router.push({ name: 'drive-folder', params: { folderId } })
        }
      } else {
        router.push('/drive')
      }
      break
    case 'calendar':
      if (eventId) {
        router.push({ path: '/calendar', query: { event: eventId } })
      } else {
        router.push('/calendar')
      }
      break
    case 'boards':
      if (boardId) {
        router.push(`/boards/${boardId}`)
      } else {
        router.push('/boards')
      }
      break
    case 'todos':
      todosStore.openPanel()
      break
    case 'mood':
      if (boardId) {
        router.push(`/mood/${boardId}`)
      } else {
        router.push('/mood')
      }
      break
    case 'chat':
      router.push('/chat')
      break
  }
}

onMounted(async () => {
  if (isElectron()) {
    await auth.initFromElectron()
    
    window.api.on('maximize-padding', (padding) => {
      maximizePadding.value = padding
    })
    
    window.api.on('update-available', () => {
      updateAvailable.value = true
    })
    
    window.api.on('auth-failed', () => {
      auth.clearAuth()
      router.replace('/login')
    })
    
    window.api.on('app-locked', () => {
      isAppLocked.value = true
    })
    window.api.on('app-unlocked', () => {
      isAppLocked.value = false
    })
    
    window.api.on('forced-logout', () => {
      auth.clearAuth()
      router.replace('/login')
    })

    if (window.api?.sso?.onAuthenticated) {
      window.api.sso.onAuthenticated(async () => {
        await auth.initFromElectron()
        if (router.currentRoute.value.name === 'login') {
          router.push({ name: 'mailbox' })
        }
      })
    }
    
    window.api.lock.isLocked().then(locked => {
      isAppLocked.value = locked
    })
    
    window.addEventListener('logout', () => {})
  }
  
  window.addEventListener('navigate-to', handleNavigateTo)
  
  await theme.initTheme()
  await layout.initLayout()
  
  if (auth.isAuthenticated) {
    await runBootstrap()
    clientTimeTracker.setClientsStore(clientsStore)
    
    mailSync.init()
    presence.init()
    
    if (chatEnabled.value) {
      callStore.setupSocketListeners()
      huddleStore.setupSocketListeners()
      huddleStore.fetchAllActiveHuddles()
    }
    
    notificationsStore.subscribeToEvents()
    notificationsStore.startPolling(120000)
    
    if (universalSearchEnabled.value) {
      searchStore.startAttachmentIndexing()
      searchStore.startBodyIndexing()
    }
    
    if (idleEnabled.value) {
      startIdleMonitoring()
    }
  }
})

onUnmounted(() => {
  mailSync.cleanup()
  presence.cleanup()
  searchStore.stopAttachmentIndexing()
  searchStore.stopBodyIndexing()
  notificationsStore.stopPolling()
  stopIdleMonitoring()
  window.removeEventListener('navigate-to', handleNavigateTo)
})

// Watch for chatEnabled becoming true (addons loaded asynchronously after mount).
// setupSocketListeners may have been skipped in onMounted because chatEnabled was
// still false at that point. The socket.on() Set is idempotent, so duplicate calls
// are harmless.
watch(chatEnabled, (enabled) => {
  if (enabled && auth.isAuthenticated) {
    callStore.setupSocketListeners()
    huddleStore.setupSocketListeners()
    huddleStore.fetchAllActiveHuddles()
  }
})

watch(() => auth.isAuthenticated, async (isAuth) => {
  if (isAuth) {
    bootstrapReady.value = false
    resetBootstrap()
    await runBootstrap()
    await theme.initTheme()
    await layout.initLayout()
    clientTimeTracker.setClientsStore(clientsStore)
    clientsStore.fetchClients()
    
    mailSync.init()
    presence.init()
    
    if (chatEnabled.value) {
      callStore.setupSocketListeners()
      huddleStore.setupSocketListeners()
      huddleStore.fetchAllActiveHuddles()
    }
    
    notificationsStore.subscribeToEvents()
    notificationsStore.startPolling(120000)
    
    if (universalSearchEnabled.value) {
      searchStore.startAttachmentIndexing()
      searchStore.startBodyIndexing()
    }
    
    if (idleEnabled.value) {
      startIdleMonitoring()
    }
  } else {
    bootstrapReady.value = false
    resetBootstrap()
    mailSync.cleanup()
    presence.cleanup()
    callStore.cleanupCall()
    searchStore.stopAttachmentIndexing()
    searchStore.stopBodyIndexing()
    notificationsStore.stopPolling()
    stopIdleMonitoring()
    router.replace('/login')
  }
})

// Suppress ambient-bg class on moodboard routes and macOS
watch([isMoodboardRoute, isMacOS], ([isMood, isMac]) => {
  if (theme.ambientBackground && !isMood && !isMac) {
    document.documentElement.classList.add('ambient-bg')
  } else {
    document.documentElement.classList.remove('ambient-bg')
  }
})

// Handle update (for Electron auto-updater)
async function handleUpdate() {
  if (!isElectron()) return
  
  isUpdating.value = true
  try {
    // Main process handles the actual update
    window.api.send('install-update')
  } catch (e) {
    console.error('Update failed:', e)
  }
}
</script>

<template>
  <!-- Ambient background blobs - hidden on moodboard and macOS (GPU-intensive blur) -->
  <div v-if="theme.ambientBackground && !isMoodboardRoute && !isMacOS" class="ambient-blobs" aria-hidden="true">
    <div class="ambient-blob ambient-blob-1"></div>
    <div class="ambient-blob ambient-blob-2"></div>
    <div class="ambient-blob ambient-blob-3"></div>
  </div>

  <div
    class="h-screen flex flex-col overflow-hidden"
    :style="{
      paddingTop: maximizePadding.top + 'px',
      paddingRight: maximizePadding.right + 'px',
      paddingBottom: maximizePadding.bottom + 'px',
      paddingLeft: maximizePadding.left + 'px',
    }"
  >
    <!-- Window Title Bar (Electron only) -->
    <TitleBar v-if="showTitleBar" />
    
    <!-- Main App Content -->
    <div class="main-content flex-1 overflow-hidden">
      <RouterView />
    </div>
    
    <!-- Lock Screen Overlay (Electron only) -->
    <LockScreen 
      v-if="showTitleBar && isAppLocked && auth.isAuthenticated"
      :user-email="auth.user?.email || ''"
      :user-name="auth.user?.name || ''"
      @unlocked="isAppLocked = false"
    />
  </div>
  
  <ToastContainer />
  <MindMap />
  <SuperSearch v-if="universalSearchEnabled" />
  <FloatingChatWidget v-if="auth.isAuthenticated && auth.authChecked && chatEnabled" />
  <TodoPanel v-if="auth.isAuthenticated && auth.authChecked" />
  <NotificationPanel v-if="auth.isAuthenticated && auth.authChecked" />
  <OnboardingPopup v-if="auth.isAuthenticated && auth.authChecked" />
  <FeedbackButton v-if="auth.isAuthenticated && auth.authChecked" />
  <FeedbackModal v-if="auth.isAuthenticated && auth.authChecked" />
  <ComposeWindow v-if="auth.isAuthenticated && auth.authChecked && bootstrapReady" />
  <DatabaseDebugPanel v-if="showTitleBar && dbSyncDebugEnabled" />
  
  <!-- WebSocket connection status indicator (visible when disconnected) -->
  <Transition name="fade">
    <div
      v-if="auth.isAuthenticated && !wsConnected"
      class="fixed bottom-3 left-3 z-[9998] flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium shadow-lg backdrop-blur-sm"
      :class="wsState === 'reconnecting' 
        ? 'bg-amber-500/90 text-white' 
        : 'bg-red-500/90 text-white'"
      :title="wsState === 'reconnecting' ? 'Reconnecting to server...' : 'Disconnected from server'"
    >
      <span class="w-2 h-2 rounded-full animate-pulse" :class="wsState === 'reconnecting' ? 'bg-amber-200' : 'bg-red-200'"></span>
      <span class="material-symbols-rounded text-sm">{{ wsState === 'reconnecting' ? 'sync' : 'cloud_off' }}</span>
      {{ wsState === 'reconnecting' ? 'Reconnecting...' : 'Offline' }}
    </div>
  </Transition>

  <!-- Call UI (voice/video) — gated by chat addon -->
  <template v-if="auth.isAuthenticated && chatEnabled">
    <CallOverlay v-if="callStore.isInCall && !callStore.isMinimized && !(callStore.isRinging && callStore.callDirection === 'incoming')" />
    <IncomingCallModal v-if="callStore.isRinging && callStore.callDirection === 'incoming' && !callStore.nativeRingActive" />
    <CallPip v-if="callStore.isInCall && callStore.isMinimized" />
  </template>
  
  <!-- Idle Auto-Logout Warning -->
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="isWarningVisible" class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/60 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl p-8 max-w-sm w-full mx-4 text-center">
          <div class="w-16 h-16 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-rounded text-3xl text-amber-600 dark:text-amber-400">schedule</span>
          </div>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Session Timeout</h3>
          <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            You have been inactive. You will be logged out in
            <span class="font-bold text-amber-600 dark:text-amber-400">{{ countdownSeconds }}s</span>
          </p>
          <button
            @click="dismissWarning"
            class="w-full px-6 py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-full font-medium text-sm transition-colors"
          >
            Stay Logged In
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Desktop Update Banner (Electron auto-updater) -->
  <Transition name="slide-up">
    <div
      v-if="updateAvailable"
      class="fixed bottom-4 left-4 right-4 sm:left-auto sm:right-4 sm:w-96 z-[9999] bg-primary-600 text-white rounded-xl shadow-2xl p-4 flex items-center gap-3"
    >
      <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center flex-shrink-0">
        <span class="material-symbols-rounded text-xl">system_update</span>
      </div>
      <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm">Update Available</p>
        <p class="text-xs text-white/80">A new version is ready to install</p>
      </div>
      <button
        @click="handleUpdate"
        :disabled="isUpdating"
        class="px-4 py-2 bg-white text-primary-600 rounded-lg font-medium text-sm hover:bg-white/90 transition-colors disabled:opacity-50 flex items-center gap-2"
      >
        <span v-if="isUpdating" class="material-symbols-rounded animate-spin text-base">refresh</span>
        {{ isUpdating ? 'Updating...' : 'Update' }}
      </button>
    </div>
  </Transition>
</template>

<style scoped>
/* Fade transitions */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Update Banner Animation */
.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.3s ease-out;
}

.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(100%);
  opacity: 0;
}

/* Views use h-[100dvh] / h-screen which overflows the desktop container
   (titlebar sits above the main content area). Force route children to
   fill the parent instead of the viewport. */
.main-content :deep(> *) {
  height: 100% !important;
  max-height: 100% !important;
}
</style>
