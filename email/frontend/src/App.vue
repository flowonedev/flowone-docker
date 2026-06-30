<script setup>
import { RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { usePerspectiveStore } from '@/stores/perspective'
import { useSettingsStore } from '@/stores/settings'
import { useClientsStore } from '@/stores/clients'
import { useSearchStore } from '@/addons/universal-search/stores/search'
import { useMailboxStore } from '@/stores/mailbox'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'
import { useHuddleStore } from '@/stores/huddle'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useNotificationsStore } from '@/stores/notifications'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { initFaviconBadge, updateFaviconBadge } from '@/utils/faviconBadge'
import { useTabFlash } from '@/composables/useTabFlash'
import { isDebugEnabled } from '@/utils/debug'
import { useTimeTracker } from '@/addons/time-tracker/composables/useTimeTracker'
import { useMailSyncIntegration } from '@/composables/useMailSyncIntegration'
import { usePresence } from '@/composables/usePresence'
import { useRegisterSW } from 'virtual:pwa-register/vue'
import pushNotifications from '@/services/pushNotifications'
import { initNativePush, setNativeBadge } from '@/services/nativePush'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import { startIdleMonitoring, stopIdleMonitoring, isWarningVisible, countdownSeconds, dismissWarning, isEnabled as idleEnabled } from '@/services/idleLogout'
import { init as initAppLock, destroy as destroyAppLock } from '@/services/appLock'
import { useAddons } from '@/composables/useAddons'
import { registerWorkSessionBridge } from '@/addons/project-hub/services/workSessionBridge'
import { bootstrap as fetchBootstrap, hydrateStores, resetBootstrap } from '@/services/bootstrap'
import api from '@/services/api'
import { startAuthHandoffServer } from '@/services/crossTabAuth'
import ToastContainer from '@/components/shared/ToastContainer.vue'
import AppLockScreen from '@/components/AppLockScreen.vue'
import DeviceApprovalModal from '@/components/DeviceApprovalModal.vue'
import InstallPrompt from '@/components/shared/InstallPrompt.vue'
import MindMap from '@/components/mindmap/MindMap.vue'
import SuperSearch from '@/addons/universal-search/components/SuperSearch.vue'
import FloatingChatWidget from '@/addons/chat/components/FloatingChatWidget.vue'
import TodoPanel from '@/addons/tasks/components/TodoPanel.vue'
import NotificationPanel from '@/components/NotificationPanel.vue'
import UnifiedShareModal from '@/components/drive/share/UnifiedShareModal.vue'
import CalendarReminderPopup from '@/addons/calendar/components/CalendarReminderPopup.vue'
import CallOverlay from '@/components/call/CallOverlay.vue'
import IncomingCallModal from '@/components/call/IncomingCallModal.vue'
import CallPip from '@/components/call/CallPip.vue'
import PreCallDeviceModal from '@/components/call/PreCallDeviceModal.vue'
import { useCallLauncher } from '@/composables/useCallLauncher'
import OnboardingPopup from '@/components/onboarding/OnboardingPopup.vue'
import FeedbackButton from '@/components/feedback/FeedbackButton.vue'
import FeedbackModal from '@/components/feedback/FeedbackModal.vue'
import ComposeWindow from '@/components/ComposeWindow.vue'
import SetupWizard from '@/components/setup/SetupWizard.vue'
import ForcePasswordChangeModal from '@/components/ForcePasswordChangeModal.vue'
import BottomTicker from '@/addons/news-reader/components/BottomTicker.vue'
import FlipboardReader from '@/addons/news-reader/components/FlipboardReader.vue'
import { useRouter, useRoute } from 'vue-router'
import { onMounted, onUnmounted, watch, ref, computed } from 'vue'

const router = useRouter()
const route = useRoute()
const isMoodboardRoute = computed(() => route.path.startsWith('/mood'))
const auth = useAuthStore()
const theme = useThemeStore()
const layout = useLayoutStore()
const perspectiveStore = usePerspectiveStore()
const clientsStore = useClientsStore()
const searchStore = useSearchStore()
const mailbox = useMailboxStore()
const chatStore = useChatStore()
const callStore = useCallStore()
const callLauncher = useCallLauncher()
const huddleStore = useHuddleStore()
const todosStore = useTodosStore()
const notificationsStore = useNotificationsStore()
const calendarStore = useCalendarStore()
const { chatEnabled, calendarEnabled, universalSearchEnabled, newsReaderEnabled, fetchAddons: fetchAddonStatus } = useAddons()
const settingsStore = useSettingsStore()

const showSetupWizard = ref(false)
const bootstrapReady = ref(false)
const isAppReady = computed(() => auth.isAuthenticated && bootstrapReady.value && route.name !== 'login')
// Blocking modal: user must set a new password before using the app
// (migrated mailbox / admin-flagged for forced change).
const showForcePasswordChange = computed(() => auth.isAuthenticated && auth.forcePasswordChange && route.name !== 'login')

function handleSetupComplete() {
  showSetupWizard.value = false
}

// Dev shortcut: Ctrl+Shift+F1 reopens the setup wizard
if (typeof window !== 'undefined') {
  window.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.shiftKey && e.key === 'F1' && auth.isAuthenticated) {
      e.preventDefault()
      showSetupWizard.value = true
    }
  })
}

// Centralized page title + favicon badge: combines email + chat + missed call unread counts
const totalBadgeCount = computed(() => {
  return (mailbox.unreadCount || 0) + (chatStore.totalUnread || 0) + (notificationsStore.missedCallUnreadCount || 0)
})

const tabFlash = useTabFlash()
let prevBadgeCount = 0

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
  // Installed PWAs compose the window title as "{manifest name} - {document.title}".
  // The manifest name is the brand ("FlowOne.PRO"), so document.title holds only
  // the tagline to avoid repeating the brand in the title bar.
  const tagline = 'Your Business. Your Data. Your Rules.'
  const normalTitle = count > 0 ? `(${count}) ${tagline}` : tagline
  document.title = normalTitle
  tabFlash.updateTitle(normalTitle)

  // Flash the tab title when count increases while tab is not focused
  if (count > prevBadgeCount && count > 0 && !tabFlash.tabFocused.value) {
    tabFlash.startFlash(normalTitle)
  } else if (count === 0) {
    tabFlash.stopFlash()
  }
  prevBadgeCount = count

  updateFaviconBadge(count)

  if ('setAppBadge' in navigator) {
    if (count > 0) {
      navigator.setAppBadge(count).catch(() => {})
    } else {
      navigator.clearAppBadge().catch(() => {})
    }
  }

  // The Web Badging API above is unavailable in the native Capacitor shell, so
  // the iOS/Android app-icon badge must be driven directly — this is what
  // actually makes it disappear once everything is read.
  setNativeBadge(count)

  syncBadgeToServer(count)
}, { immediate: true })

// PWA updates apply silently in the background - no user-facing prompt.
// Every 5 minutes we check for a new build; when one is found it is precached
// and activated under the hood. We intentionally do NOT reload the page, so the
// user is never interrupted - the new version is picked up on their next refresh.
const { needRefresh, updateServiceWorker } = useRegisterSW({
  onRegisteredSW(swUrl, registration) {
    if (registration) {
      setInterval(() => {
        registration.update()
      }, 5 * 60 * 1000) // Check every 5 minutes
    }
  },
  onRegisterError(error) {
    console.error('SW registration error:', error)
  }
})

// Activate a freshly-installed service worker immediately, but without reloading
// (pass false). Combined with clientsClaim:false in the workbox config, the open
// tab keeps running the current version until the user refreshes.
watch(needRefresh, (ready) => {
  if (ready) {
    Promise.resolve(updateServiceWorker(false)).catch((e) => console.error('SW silent update failed:', e))
  }
})

// Initialize time tracking for statistics
const timeTracker = useTimeTracker()

// Initialize real-time mail sync (WebSocket)
const mailSync = useMailSyncIntegration()

// WebSocket connection status (always visible when disconnected)
const wsConnected = computed(() => mailSync.connectionState?.value === 'connected')
const wsState = computed(() => mailSync.connectionState?.value || 'disconnected')

// Show top banner after 5+ seconds of disconnection (avoids flashing on brief reconnects)
const showConnectionBanner = ref(false)
let connectionBannerTimer = null

watch(wsConnected, (connected) => {
  if (!connected) {
    // Start timer to show banner after 5 seconds
    if (!connectionBannerTimer) {
      connectionBannerTimer = setTimeout(() => {
        showConnectionBanner.value = true
      }, 5000)
    }
  } else {
    // Connected - clear timer, show "restored" briefly then hide
    if (connectionBannerTimer) {
      clearTimeout(connectionBannerTimer)
      connectionBannerTimer = null
    }
    if (showConnectionBanner.value) {
      // Keep showing "Connection restored" for 2 seconds
      setTimeout(() => {
        showConnectionBanner.value = false
      }, 2000)
    }
  }
}, { immediate: true })

// Initialize presence tracking
const presence = usePresence()

async function initCalendarRemindersIfEnabled() {
  if (!calendarEnabled.value) return
  try {
    await calendarStore.fetchTodayEventsForReminders()
    calendarStore.startEventReminders()
  } catch (e) {
    isDebugEnabled() && console.warn('[App] Calendar reminders init failed:', e)
  }
}

// Handle navigate-to events from chat embed cards (shared drive files, boards, calendar events)
function handleNavigateTo(event) {
  const { view, folderId, boardId, cardId, eventId, ownerEmail } = event.detail || {}
  if (!view) return
  
  switch (view) {
    case 'drive':
      if (folderId) {
        // If the item belongs to another user, open in shared view
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

let stopAuthHandoffServer = null

onMounted(async () => {
  initFaviconBadge()
  pushNotifications.listenForNotificationClicks(router)
  window.addEventListener('navigate-to', handleNavigateTo)

  // Lend this tab's live session to freshly opened sibling tabs (device-approval
  // page) over a same-origin BroadcastChannel. Reads tokens lazily, so it only
  // answers once this tab is signed in.
  stopAuthHandoffServer = startAuthHandoffServer()

  // Apply localStorage theme instantly (no network), then override from bootstrap
  theme.initThemeLocal()
  
  if (auth.isAuthenticated && !route.meta?.public) {
    // Single bootstrap call replaces: settings, accounts, labels, filters,
    // addons, notifications, trusted-senders, colleagues, groups, todos, vapid
    const data = await fetchBootstrap()
    await hydrateStores(data)
    bootstrapReady.value = true
    registerWorkSessionBridge()
    
    if (!settingsStore.settings.setup_completed) {
      showSetupWizard.value = true
    }

    // Mark chat/huddle init pending BEFORE any await — FloatingChatWidget mounts
    // after bootstrapReady and its ensureSubscribed() would fire redundant calls
    if (chatEnabled.value) {
      chatStore.markInitPending()
      huddleStore.markInitPending()
    }

    const clientInitData = await clientsStore.initClients()
    if (clientInitData) clientTimeTracker.hydrateFromInit(clientInitData)
    clientTimeTracker.setClientsStore(clientsStore)
    mailSync.init()
    presence.init()

    if (chatEnabled.value) {
      callStore.setupSocketListeners()
      huddleStore.setupSocketListeners()
      const chatInitData = await chatStore.initChat()
      if (chatInitData) {
        chatStore.hydrateFromInit(chatInitData)
        huddleStore.hydrateActiveHuddles(chatInitData.active_huddles)
      }
    }
    
    notificationsStore.subscribeToEvents()
    notificationsStore.startPolling(30000)

    await initCalendarRemindersIfEnabled()
    
    if (universalSearchEnabled.value) {
      searchStore.startAttachmentIndexing()
      searchStore.startBodyIndexing()
    }
    
    pushNotifications.syncExisting().catch(e => {
      isDebugEnabled() && console.log('[App] Push sync skipped:', e.message || 'not available')
    })

    initNativePush(router).catch(e => {
      isDebugEnabled() && console.log('[App] Native push skipped:', e.message || 'not available')
    })
    
    if (idleEnabled.value) {
      startIdleMonitoring()
    }
    
    initAppLock()
  }
})

onUnmounted(() => {
  // Cleanup WebSocket connection
  mailSync.cleanup()
  // Cleanup presence tracking
  presence.cleanup()
  // Stop search indexing (safe to call even if addon disabled)
  searchStore.stopAttachmentIndexing()
  searchStore.stopBodyIndexing()
  // Stop notification polling
  notificationsStore.stopPolling()
  calendarStore.stopEventReminders()
  // Stop idle monitoring
  stopIdleMonitoring()
  // Stop app lock monitoring
  destroyAppLock()
  // Remove navigate-to listener
  window.removeEventListener('navigate-to', handleNavigateTo)
  // Stop answering cross-tab auth handoff requests
  if (stopAuthHandoffServer) stopAuthHandoffServer()
})

// Suppress ambient-bg class on moodboard routes (moodboard has its own canvas bg)
watch(isMoodboardRoute, (isMood) => {
  if (theme.ambientBackground) {
    if (isMood) {
      document.documentElement.classList.remove('ambient-bg')
    } else {
      document.documentElement.classList.add('ambient-bg')
    }
  }
})

// Watch for chatEnabled becoming true (addons loaded asynchronously after mount).
// setupSocketListeners may have been skipped in onMounted because chatEnabled was
// still false at that point. The socket.on() Set is idempotent, so duplicate calls
// are harmless.
watch(chatEnabled, (enabled) => {
  if (enabled && auth.isAuthenticated && !route.meta?.public) {
    callStore.setupSocketListeners()
    huddleStore.setupSocketListeners()
  }
})

watch(calendarEnabled, (enabled) => {
  if (!auth.isAuthenticated || route.meta?.public) return
  if (enabled) {
    initCalendarRemindersIfEnabled()
  } else {
    calendarStore.stopEventReminders()
  }
})

// Watch for auth changes to reinitialize settings (skip on public routes like guest call)
watch(() => auth.isAuthenticated, async (isAuth) => {
  if (isAuth && !route.meta?.public) {
    bootstrapReady.value = false
    resetBootstrap()
    const data = await fetchBootstrap()
    await hydrateStores(data)
    bootstrapReady.value = true

    if (!settingsStore.settings.setup_completed) {
      showSetupWizard.value = true
    }

    if (chatEnabled.value) {
      chatStore.markInitPending()
      huddleStore.markInitPending()
    }

    const clientInitData = await clientsStore.initClients()
    if (clientInitData) clientTimeTracker.hydrateFromInit(clientInitData)
    clientTimeTracker.setClientsStore(clientsStore)
    mailSync.init()
    presence.init()

    if (chatEnabled.value) {
      callStore.setupSocketListeners()
      huddleStore.setupSocketListeners()
      const chatInitData = await chatStore.initChat()
      if (chatInitData) {
        chatStore.hydrateFromInit(chatInitData)
        huddleStore.hydrateActiveHuddles(chatInitData.active_huddles)
      }
    }
    
    notificationsStore.subscribeToEvents()
    notificationsStore.startPolling(30000)

    await initCalendarRemindersIfEnabled()
    
    if (universalSearchEnabled.value) {
      searchStore.startAttachmentIndexing()
      searchStore.startBodyIndexing()
    }
    
    pushNotifications.syncExisting().catch(e => {
      isDebugEnabled() && console.log('[App] Push sync skipped:', e.message || 'not available')
    })

    initNativePush(router).catch(e => {
      isDebugEnabled() && console.log('[App] Native push skipped:', e.message || 'not available')
    })
    
    if (idleEnabled.value) {
      startIdleMonitoring()
    }
    
    initAppLock()
  } else if (!isAuth) {
    bootstrapReady.value = false
    resetBootstrap()
    mailSync.cleanup()
    presence.cleanup()
    callStore.cleanupCall()
    searchStore.stopAttachmentIndexing()
    searchStore.stopBodyIndexing()
    notificationsStore.stopPolling()
    calendarStore.stopEventReminders()
    stopIdleMonitoring()
    destroyAppLock()
  }
})
</script>

<template>
  <!-- Ambient background blobs (accent-colored, static) - hidden on moodboard (has its own bg) -->
  <div v-if="theme.ambientBackground && !isMoodboardRoute" class="ambient-blobs" aria-hidden="true">
    <div class="ambient-blob ambient-blob-1"></div>
    <div class="ambient-blob ambient-blob-2"></div>
    <div class="ambient-blob ambient-blob-3"></div>
  </div>

  <!-- Show a brief loading state while bootstrap hydrates stores for authenticated users -->
  <template v-if="auth.isAuthenticated && !bootstrapReady">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-white dark:bg-surface-950 transition-colors">
      <div class="flex flex-col items-center gap-3">
        <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
      </div>
    </div>
  </template>
  <template v-else>
    <RouterView />
  </template>
  <ToastContainer />
  <InstallPrompt />
  <MindMap v-if="bootstrapReady" />
  <SuperSearch v-if="universalSearchEnabled && bootstrapReady" />
  <BottomTicker v-if="isAppReady && newsReaderEnabled" />
  <FlipboardReader v-if="isAppReady && newsReaderEnabled" />
  <FloatingChatWidget v-if="isAppReady && chatEnabled" />
  <TodoPanel v-if="isAppReady" />
  <NotificationPanel v-if="isAppReady" />
  <UnifiedShareModal v-if="isAppReady" />
  <CalendarReminderPopup v-if="isAppReady && calendarEnabled" />
  <OnboardingPopup v-if="isAppReady" />
  <FeedbackButton v-if="isAppReady" />
  <FeedbackModal v-if="isAppReady" />
  <ComposeWindow v-if="isAppReady" />
  
  <!-- First-time setup wizard (suppressed while a forced password change is pending) -->
  <SetupWizard v-if="showSetupWizard && !showForcePasswordChange" @complete="handleSetupComplete" />

  <!-- Forced password change (migrated / admin-flagged accounts).
       Hard gate: rendered above everything else and takes priority over the
       welcome wizard until the new password is set. -->
  <ForcePasswordChangeModal v-if="showForcePasswordChange" />
  
  <!-- WebSocket connection status: top banner for prolonged disconnections -->
  <Transition name="slide-down">
    <div
      v-if="auth.isAuthenticated && showConnectionBanner"
      class="fixed top-0 left-0 right-0 z-[9999] flex items-center justify-center gap-2 py-2 px-4 text-sm font-medium"
      :class="wsConnected 
        ? 'bg-green-600 text-white' 
        : wsState === 'reconnecting' 
          ? 'bg-amber-500 text-white' 
          : 'bg-red-500 text-white'"
    >
      <span class="material-symbols-rounded text-base" :class="wsState === 'reconnecting' ? 'animate-spin' : ''">
        {{ wsConnected ? 'cloud_done' : wsState === 'reconnecting' ? 'sync' : 'cloud_off' }}
      </span>
      {{ wsConnected ? 'Connection restored' : wsState === 'reconnecting' ? 'Connection lost. Reconnecting...' : 'Disconnected. Messages and calls may not be delivered.' }}
    </div>
  </Transition>

  <!-- WebSocket connection status indicator pill (visible when disconnected, bottom-left) -->
  <Transition name="fade">
    <div
      v-if="auth.isAuthenticated && !wsConnected && !showConnectionBanner"
      class="fixed bottom-3 left-3 z-[9998] flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium shadow-lg backdrop-blur-sm"
      :class="wsState === 'reconnecting' 
        ? 'bg-amber-500/90 text-white' 
        : 'bg-red-500/90 text-white'"
      :title="wsState === 'reconnecting' ? 'Reconnecting to server...' : 'Disconnected from server — real-time sync is off'"
    >
      <span class="w-2 h-2 rounded-full animate-pulse" :class="wsState === 'reconnecting' ? 'bg-amber-200' : 'bg-red-200'"></span>
      <span class="material-symbols-rounded text-sm">{{ wsState === 'reconnecting' ? 'sync' : 'cloud_off' }}</span>
      {{ wsState === 'reconnecting' ? 'Reconnecting...' : 'Offline' }}
    </div>
  </Transition>

  <!-- Call UI (voice/video) — gated by chat addon -->
  <template v-if="isAppReady && chatEnabled">
    <CallOverlay 
      v-if="callStore.isInCall && !(callStore.isRinging && callStore.callDirection === 'incoming')" 
      v-show="!callStore.isMinimized" 
    />
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
  
  <!-- App Lock Screen (PIN / biometric after inactivity) -->
  <AppLockScreen v-if="isAppReady" />

  <!-- "Someone wants to sign in" approval prompt (pushed to signed-in sessions) -->
  <DeviceApprovalModal v-if="isAppReady" />

  <!-- Idle Auto-Logout Warning -->
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="isWarningVisible" class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/60 backdrop-blur-sm">
        <div class="bg-white dark:bg-surface-900 rounded-2xl shadow-2xl p-8 max-w-sm w-full mx-4 text-center">
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

</template>

<style scoped>
/* Idle Warning Fade */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Connection Banner Slide Down */
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}

/* PWA Update Banner Animation */
.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.3s ease-out;
}

.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(100%);
  opacity: 0;
}
</style>
