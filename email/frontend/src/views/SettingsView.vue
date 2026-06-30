<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useToastStore } from '@/stores/toast'
import { useAccountsStore } from '@/stores/accounts'
import { useSettingsStore } from '@/stores/settings'
import { useOnboardingStore } from '@/stores/onboarding'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useMailboxStore } from '@/stores/mailbox'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import api from '@/services/api'
import { isElectron } from '@/services/electronApi'
import { isIOSNativePlatform } from '@/utils/platform'
import { isDebugEnabled } from '@/utils/debug'
import TwoFactorSetup from '@/components/TwoFactorSetup.vue'
import SessionsManager from '@/components/SessionsManager.vue'
import DevicesManager from '@/components/DevicesManager.vue'
import FilterSettings from '@/components/FilterSettings.vue'
import SpamSettings from '@/components/SpamSettings.vue'
import StatisticsTab from '@/addons/time-tracker/components/StatisticsTab.vue'
import RichTextEditor from '@/components/RichTextEditor.vue'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import AccountsTab from '@/components/AccountsTab.vue'
import StorageAdminDashboard from '@/components/storage/StorageAdminDashboard.vue'
import browserNotifications from '@/services/browserNotifications'
import notificationSounds from '@/services/notificationSounds'
import pushNotifications, { pushStatus } from '@/services/pushNotifications'
import { idleTimeoutMinutes, isEnabled as idleLogoutEnabledRef, setIdleTimeout, setIdleLogoutEnabled } from '@/services/idleLogout'
import {
  isEnabled as appLockEnabled,
  hasPinSet as appLockHasPin,
  hasBiometric as appLockHasBiometric,
  biometricAvailable as appLockBiometricAvailable,
  lockTimeoutMinutes as appLockTimeout,
  setPin as appLockSetPin,
  removePin as appLockRemovePin,
  registerBiometric as appLockRegisterBiometric,
  removeBiometric as appLockRemoveBiometric,
  checkBiometricSupport as appLockCheckBiometricSupport,
  enable as appLockEnable,
  disable as appLockDisable,
  setLockTimeout as appLockSetTimeout,
} from '@/services/appLock'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import EmailTemplateManager from '@/components/EmailTemplateManager.vue'
import { useOAuthCallback } from '@/composables/useOAuthCallback'
import { useAddons } from '@/composables/useAddons'
import AppHeader from '@/components/shared/AppHeader.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import BillingSettings from '@/components/BillingSettings.vue'
import IntegrationsSettings from '@/components/IntegrationsSettings.vue'
import NewsReaderSettings from '@/addons/news-reader/views/NewsReaderSettings.vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const accountsStore = useAccountsStore()
const settingsStore = useSettingsStore()
const calendarStore = useCalendarStore()
const aiStore = useAIStore()
const mailboxStore = useMailboxStore()
const boardsStore = useBoardsStore()
const onboardingStore = useOnboardingStore()

// OAuth callback handler for sessionStorage fallback
const { checkOAuthFallback } = useOAuthCallback()

// Addon flags
const { emailTrackingEnabled, aiAssistantEnabled, newsReaderEnabled } = useAddons()

// Cache management state
const cacheStats = ref(null)
const loadingCacheStats = ref(false)
const clearingCache = ref(false)

// Confirmation modal state
const showClearCacheConfirm = ref(false)
const clearCacheAction = ref(null) // 'all', 'messages', 'ai', or folder name
const clearCacheTitle = ref('')
const clearCacheMessage = ref('')

// Desktop app settings state
const desktopLaunchAtStartup = ref(false)
const desktopDbSyncDebug = ref(false)

async function loadDesktopSettings() {
  if (!isElectron()) return
  try {
    const [launchVal, dbDebugVal] = await Promise.all([
      window.api.config.get('launchAtStartup'),
      window.api.config.get('dbSyncDebugEnabled'),
    ])
    desktopLaunchAtStartup.value = !!launchVal
    desktopDbSyncDebug.value = !!dbDebugVal
  } catch (e) {
    console.error('Failed to load desktop settings:', e)
  }
}

async function toggleDesktopLaunchAtStartup() {
  desktopLaunchAtStartup.value = !desktopLaunchAtStartup.value
  try {
    await window.api.config.set('launchAtStartup', desktopLaunchAtStartup.value)
  } catch (e) {
    desktopLaunchAtStartup.value = !desktopLaunchAtStartup.value
    console.error('Failed to save launch at startup:', e)
  }
}

async function toggleDesktopDbSyncDebug() {
  desktopDbSyncDebug.value = !desktopDbSyncDebug.value
  try {
    await window.api.config.set('dbSyncDebugEnabled', desktopDbSyncDebug.value)
  } catch (e) {
    desktopDbSyncDebug.value = !desktopDbSyncDebug.value
    console.error('Failed to save db sync debug:', e)
  }
}

// Idle auto-logout state
const idleLogoutEnabled = ref(idleLogoutEnabledRef.value)
const idleTimeoutValue = ref(idleTimeoutMinutes.value)

function toggleIdleLogout() {
  idleLogoutEnabled.value = !idleLogoutEnabled.value
  setIdleLogoutEnabled(idleLogoutEnabled.value)
}

function updateIdleTimeout() {
  setIdleTimeout(idleTimeoutValue.value)
}

// App Lock state
const appLockPinInput = ref('')
const appLockPinConfirm = ref('')
const appLockSettingPin = ref(false)
const appLockPinError = ref('')
const appLockBioLoading = ref(false)

async function toggleAppLock() {
  if (appLockEnabled.value) {
    appLockDisable()
    toast.success(t('settingsView.appLockDisabled'))
  } else {
    if (!appLockHasPin.value) {
      toast.error(t('settingsView.setAPinCodeFirst'))
      return
    }
    try {
      appLockEnable()
      toast.success(t('settingsView.appLockEnabled'))
    } catch (e) {
      toast.error(e.message)
    }
  }
}

async function saveAppLockPin() {
  appLockPinError.value = ''
  if (!appLockPinInput.value || appLockPinInput.value.length < 4) {
    appLockPinError.value = t('settingsView.pinMustBeAtLeast4')
    return
  }
  if (!/^\d+$/.test(appLockPinInput.value)) {
    appLockPinError.value = t('settingsView.pinMustContainOnlyDigits')
    return
  }
  if (appLockPinInput.value !== appLockPinConfirm.value) {
    appLockPinError.value = t('settingsView.pinsDoNotMatch')
    return
  }
  try {
    await appLockSetPin(appLockPinInput.value)
    // Store PIN length for the lock screen
    localStorage.setItem('app_lock_pin_length', String(appLockPinInput.value.length))
    appLockPinInput.value = ''
    appLockPinConfirm.value = ''
    appLockSettingPin.value = false
    toast.success(t('settingsView.pinSetSuccessfully'))
  } catch (e) {
    appLockPinError.value = e.message
  }
}

function handleRemovePin() {
  appLockRemovePin()
  appLockPinInput.value = ''
  appLockPinConfirm.value = ''
  toast.success(t('settingsView.pinRemoved'))
}

async function toggleBiometric() {
  if (appLockHasBiometric.value) {
    appLockRemoveBiometric()
    toast.success(t('settingsView.biometricRemoved'))
  } else {
    appLockBioLoading.value = true
    try {
      await appLockRegisterBiometric()
      toast.success(t('settingsView.biometricRegistered'))
    } catch (e) {
      toast.error(t('settingsView.biometricSetupFailed') + ' ' + (e.message || 'cancelled'))
    }
    appLockBioLoading.value = false
  }
}

function updateAppLockTimeout(event) {
  appLockSetTimeout(parseInt(event.target.value, 10))
}

// Load cache statistics (localStorage and Redis only - no IndexedDB)
async function loadCacheStats() {
  loadingCacheStats.value = true
  try {
    // Get localStorage usage (AI summaries, theme, etc.)
    let localStorageSize = 0
    let localStorageItems = 0
    let aiSummaryCount = 0
    
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i)
      const value = localStorage.getItem(key)
      localStorageSize += (key.length + value.length) * 2 // UTF-16 encoding
      localStorageItems++
      
      if (key.startsWith('ai_summary_')) {
        aiSummaryCount++
      }
    }
    
    // Get Redis cache stats from backend
    let redisStats = {
      available: false,
      server: {},
      user: { total_keys: 0, messages: 0, conversations: 0, folders: 0, thumbnails: 0 },
      ttl: {}
    }
    
    try {
      const response = await api.get('/mailbox/cache/stats')
      if (response.data.success) {
        redisStats = response.data.data
      }
    } catch (e) {
      console.warn('Failed to load Redis stats:', e)
    }
    
    cacheStats.value = {
      localStorage: {
        totalItems: localStorageItems,
        sizeBytes: localStorageSize,
        aiSummaries: aiSummaryCount,
      },
      redis: redisStats
    }
  } catch (e) {
    console.error('Failed to load cache stats:', e)
    toast.error(t('settingsView.failedToLoadCacheStatistics'))
  } finally {
    loadingCacheStats.value = false
  }
}

// Clear Redis cache
async function clearRedisCache(type = 'all') {
  clearingCache.value = true
  try {
    const response = await api.post('/mailbox/cache/clear', { type })
    if (response.data.success) {
      toast.success(t('settingsView.redisCacheCleared', { count: response.data.data.cleared }))
      await loadCacheStats()
    } else {
      toast.error(t('settingsView.failedToClearRedisCache'))
    }
  } catch (e) {
    console.error('Failed to clear Redis cache:', e)
    toast.error(t('settingsView.failedToClearRedisCache'))
  } finally {
    clearingCache.value = false
  }
}

// Show confirmation modal for cache clearing
function confirmClearCache(action, title, message) {
  clearCacheAction.value = action
  clearCacheTitle.value = title
  clearCacheMessage.value = message
  showClearCacheConfirm.value = true
}

// Handle confirmed cache clear
async function handleClearCacheConfirmed() {
  showClearCacheConfirm.value = false
  
  if (clearCacheAction.value === 'all') {
    await clearAllCaches()
  } else if (clearCacheAction.value === 'ai') {
    await clearSpecificCache('ai')
  }
}


// Clear all caches
async function clearAllCaches() {
  clearingCache.value = true
  try {
    // Clear AI summary cache from localStorage
    const keysToRemove = []
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i)
      if (key.startsWith('ai_summary_')) {
        keysToRemove.push(key)
      }
    }
    keysToRemove.forEach(key => localStorage.removeItem(key))
    
    // Clear boards cache
    boardsStore.clearEmailBoardCache()
    
    toast.success(t('settingsView.allCachesClearedSuccessfully'))
    
    // Reload stats
    await loadCacheStats()
  } catch (e) {
    console.error('Failed to clear caches:', e)
    toast.error(t('settingsView.failedToClearSomeCaches'))
  } finally {
    clearingCache.value = false
  }
}

// Clear specific cache type
async function clearSpecificCache(type) {
  clearingCache.value = true
  try {
    switch (type) {
      case 'ai':
        const keysToRemove = []
        for (let i = 0; i < localStorage.length; i++) {
          const key = localStorage.key(i)
          if (key.startsWith('ai_summary_')) {
            keysToRemove.push(key)
          }
        }
        keysToRemove.forEach(key => localStorage.removeItem(key))
        toast.success(t('settingsView.aiSummaryCacheCleared'))
        break
      case 'boards':
        boardsStore.clearEmailBoardCache()
        toast.success(t('settingsView.boardsCacheCleared'))
        break
    }
    await loadCacheStats()
  } catch (e) {
    console.error(`Failed to clear ${type} cache:`, e)
    toast.error(t('settingsView.failedToClearCache', { type }))
  } finally {
    clearingCache.value = false
  }
}

// Format bytes to human readable
function formatBytes(bytes) {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

// Format TTL seconds to human readable
function formatTtl(seconds) {
  if (!seconds) return 'N/A'
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h`
  return `${Math.round(seconds / 86400)}d`
}

// Format time ago
function formatTimeAgo(timestamp) {
  if (!timestamp) return 'Never'
  const seconds = Math.floor((Date.now() - timestamp) / 1000)
  if (seconds < 60) return `${seconds}s ago`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  return `${days}d ago`
}

// Computed for account display
const allAccounts = computed(() => {
  const primaryAccount = {
    id: 'primary',
    account_email: auth.userEmail,
    display_name: auth.displayName,
    is_primary: true,
    is_default: accountsStore.accounts.length === 0,
  }
  return [primaryAccount, ...accountsStore.accounts]
})

const currentAccount = computed(() => {
  if (!accountsStore.activeAccountId || accountsStore.activeAccountId === 'primary') {
    return allAccounts.value[0]
  }
  return allAccounts.value.find(a => a.id === parseInt(accountsStore.activeAccountId)) || allAccounts.value[0]
})

// Out of Office status indicator
const oooStatus = computed(() => {
  if (!settings.value.ooo_enabled) return null
  
  const now = new Date()
  const start = settings.value.ooo_start_date ? new Date(settings.value.ooo_start_date) : null
  const end = settings.value.ooo_end_date ? new Date(settings.value.ooo_end_date) : null
  
  // No dates set - active immediately and indefinitely
  if (!start && !end) {
    return {
      text: t('settingsView.oooActiveNowIndefinitely'),
      icon: 'check_circle',
      class: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
    }
  }
  
  // Has start date in the future
  if (start && now < start) {
    const formatted = start.toLocaleString(undefined, { 
      dateStyle: 'medium', 
      timeStyle: 'short' 
    })
    return {
      text: t('settingsView.oooScheduledToStart', { date: formatted }),
      icon: 'schedule',
      class: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400'
    }
  }
  
  // Has end date in the past
  if (end && now > end) {
    return {
      text: t('settingsView.oooScheduleEnded'),
      icon: 'event_busy',
      class: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'
    }
  }
  
  // Currently active
  if (end) {
    const formatted = end.toLocaleString(undefined, { 
      dateStyle: 'medium', 
      timeStyle: 'short' 
    })
    return {
      text: t('settingsView.oooActiveNowEndsOn', { date: formatted }),
      icon: 'check_circle',
      class: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
    }
  }
  
  return {
    text: t('settingsView.oooActiveNoEndDate'),
    icon: 'check_circle',
    class: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
  }
})

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const theme = useThemeStore()
const toast = useToastStore()

const colleaguesStore = useColleaguesStore()
// Phase 8: expose is_admin reactively so the Storage Dashboard sidebar
// entry only renders for org admins.
const { isAdmin: isCurrentUserAdmin } = storeToRefs(colleaguesStore)

// App Store Guideline 4/4.8: the Linked Accounts tab (external Gmail/Outlook +
// Google Calendar via system-browser OAuth) is removed on native iOS. Guard the
// query param too, so ?tab=accounts cannot reach it from deep links.
const iosNative = isIOSNativePlatform()
const requestedTab = route.query.tab || 'general'
// 'accounts' is removed on native iOS. 'connect-desktop' was removed entirely
// (replaced by the approve-from-another-device sign-in). Guard the deep-link
// query for both so a stale ?tab= cannot land on a non-existent tab.
const removedTabs = ['connect-desktop']
const activeTab = ref(
  ((iosNative && requestedTab === 'accounts') || removedTabs.includes(requestedTab))
    ? 'general'
    : requestedTab
)
const loading = ref(true)
const saving = ref(false)
const changingPassword = ref(false)

// Avatar upload state
const avatarUploading = ref(false)
const avatarFileInput = ref(null)
const avatarPreview = ref(null)

// Computed avatar URL — prefer auth store (freshest after upload), then colleague store
const currentAvatarUrl = computed(() => {
  return avatarPreview.value || auth.user?.avatar_url || colleaguesStore.getAvatarUrl(colleaguesStore.currentColleague) || null
})

async function triggerAvatarUpload() {
  avatarFileInput.value?.click()
}

async function handleAvatarFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return
  
  // Validate on client side
  const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
  if (!validTypes.includes(file.type)) {
    toast.error(t('settingsView.invalidFileTypePleaseUse'))
    return
  }
  if (file.size > 5 * 1024 * 1024) {
    toast.error(t('settingsView.fileTooLargeMaximumSize'))
    return
  }
  
  // Show local preview immediately
  avatarPreview.value = URL.createObjectURL(file)
  
  // Upload
  avatarUploading.value = true
  try {
    const formData = new FormData()
    formData.append('avatar', file)
    
    // Let the browser set the multipart Content-Type (with boundary) automatically
    const response = await api.post('/colleagues/me/avatar', formData)
    
    if (response.data.success) {
      toast.success(t('settingsView.avatarUpdated'))
      // Update auth store user data so header updates immediately
      if (auth.user) {
        auth.user.avatar_url = response.data.data.avatar_url
      }
      // Optimistically update the colleague store so the header (resolved by
      // email) and chat (resolved by id) reflect the new avatar instantly,
      // then force-refresh from the server to stay in sync.
      colleaguesStore.setMyAvatar(response.data.data.avatar_path)
      colleaguesStore.fetchMe(true)
      // Clear local preview — the real URL is now in auth.user
      avatarPreview.value = null
    } else {
      toast.error(response.data.message || t('settingsView.failedToUploadAvatar'))
      avatarPreview.value = null
    }
  } catch (e) {
    toast.error(t('settingsView.failedToUploadAvatar') + ' ' + (e.response?.data?.message || e.message))
    avatarPreview.value = null
  } finally {
    avatarUploading.value = false
    // Reset file input so same file can be re-selected
    if (avatarFileInput.value) avatarFileInput.value.value = ''
  }
}

async function removeAvatar() {
  avatarUploading.value = true
  try {
    const response = await api.delete('/colleagues/me/avatar')
    if (response.data.success) {
      toast.success(t('settingsView.avatarRemoved'))
      avatarPreview.value = null
      if (auth.user) {
        auth.user.avatar_url = null
      }
      colleaguesStore.setMyAvatar(null)
      colleaguesStore.fetchMe(true)
    } else {
      toast.error(t('settingsView.failedToRemoveAvatar'))
    }
  } catch (e) {
    toast.error(t('settingsView.failedToRemoveAvatar'))
  } finally {
    avatarUploading.value = false
  }
}

// Mobile state
const isMobile = ref(window.innerWidth < 768)
const sidebarOpen = ref(false)

function checkMobile() {
  isMobile.value = window.innerWidth < 768
  if (!isMobile.value) {
    sidebarOpen.value = false
  }
}

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

function closeSidebar() {
  sidebarOpen.value = false
}

// Sidebar navigation groups
const sidebarGroups = computed(() => [
  {
    name: t('settingsView.accountGroup'),
    items: [
      { id: 'general', name: t('settingsView.general'), icon: 'settings' },
      // App Store Guideline 4/4.8: the Linked Accounts tab adds external
      // Gmail/Outlook accounts and Google Calendar via system-browser OAuth,
      // which Apple rejects. Hidden on native iOS (strict single org account).
      ...(iosNative ? [] : [{ id: 'accounts', name: t('settingsView.linkedAccounts'), icon: 'manage_accounts' }]),
      { id: 'signature', name: t('settingsView.signature'), icon: 'draw' },
      { id: 'security', name: t('settingsView.security'), icon: 'shield' },
    ]
  },
  {
    name: t('settingsView.mailGroup'),
    items: [
      { id: 'filters', name: t('settingsView.filtersAndRules'), icon: 'filter_list' },
      { id: 'spam', name: t('settingsView.spamProtection'), icon: 'block' },
      { id: 'notifications', name: t('settingsView.notifications'), icon: 'notifications' },
      { id: 'email-templates', name: t('settingsView.emailTemplates'), icon: 'dashboard_customize' },
    ]
  },
  {
    name: t('settingsView.featuresGroup'),
    items: [
      ...(aiAssistantEnabled.value ? [{ id: 'ai', name: t('settingsView.aiAssistant'), icon: 'auto_awesome' }] : []),
      ...(newsReaderEnabled.value ? [{ id: 'news_reader', name: t('settingsView.newsReader'), icon: 'newspaper' }] : []),
      { id: 'integrations', name: 'Integrations', icon: 'cable' },
      { id: 'billing', name: t('settingsView.billingIntegration'), icon: 'receipt_long' },
      { id: 'statistics', name: t('settingsView.statistics'), icon: 'analytics' },
    ]
  },
  {
    name: t('settingsView.advancedGroup'),
    items: [
      ...(isElectron() ? [{ id: 'desktop-app', name: 'Desktop App', icon: 'computer' }] : []),
      { id: 'drive-storage', name: t('settingsView.driveStorage'), icon: 'hard_drive' },
      { id: 'cache', name: t('settingsView.cacheAndStorage'), icon: 'storage' },
      { id: 'system', name: t('settingsView.systemLabel'), icon: 'build' },
      // Phase 8: Storage dashboard (tiered storage + reclaim + backup). Admin-only.
      // Renders inline as a Settings tab so the sidebar stays visible. The same
      // dashboard component is also reachable via the standalone /admin/storage
      // route for bookmark/direct-link use.
      ...(isCurrentUserAdmin.value ? [{ id: 'storage-admin', name: t('storage.admin.title', 'Storage Dashboard'), icon: 'monitoring' }] : []),
    ]
  },
])

// Flatten for backwards compatibility
const tabs = computed(() => sidebarGroups.value.flatMap(g => g.items))

// AI Settings state
const aiSettings = ref({
  api_key: '',
  model: 'gpt-5-nano',
  writing_style: 'professional',
  temperature: 1.0,
  prompt_summarize: '',
  prompt_rewrite: '',
  prompt_draft_reply: '',
})
const aiConfigured = ref(false)

// Storage settings state (read-only, from Panel)
const storageConfig = ref({
  driver: 'local',
  storage_name: null,
  path: '',
  source: 'fallback'
})
const storageStats = ref(null)
const loadingStorage = ref(false)
// Default fallback values for models and styles
const aiModels = ref({
  'gpt-5-nano': { name: 'GPT-5 Nano', description: 'Cheapest, good for basic tasks' },
  'gpt-5-mini': { name: 'GPT-5 Mini', description: 'Balanced price/performance' },
  'gpt-4.1-nano': { name: 'GPT-4.1 Nano', description: 'Budget option' },
  'gpt-4.1-mini': { name: 'GPT-4.1 Mini', description: 'Reliable and capable' },
})
const aiStyles = ref({
  'friendly': 'Friendly and warm',
  'professional': 'Professional and polished',
  'corporate': 'Corporate and formal',
  'casual': 'Casual and relaxed',
  'concise': 'Brief and to the point',
})
const aiDefaultPrompts = ref({
  summarize: `Analyze the following email conversation and provide a structured summary.

IMPORTANT: Your response MUST be in the SAME LANGUAGE as the email content.

Format your response as JSON with the following structure:
{
    "topic": "Brief topic description (max 10 words)",
    "main_points": ["Point 1", "Point 2", "Point 3"],
    "context": "Brief context about what this conversation is about",
    "action_items": ["Action 1", "Action 2"],
    "suggested_actions": [
        {"label": "Reply agreeing", "type": "reply", "prompt": "Draft a reply agreeing"},
        {"label": "Request more info", "type": "reply", "prompt": "Draft a reply asking for more details"}
    ]
}

Email content:
{{email_content}}`,
  rewrite: `Rewrite the following text in a {{style}} tone. Keep the same meaning but adjust the style. Only return the rewritten text.

IMPORTANT: Your response MUST be in the SAME LANGUAGE as the original text.

Original text:
{{text}}`,
  draft_reply: `Based on the following email, draft a {{style}} reply.

IMPORTANT: Your reply MUST be in the SAME LANGUAGE as the original email.

Original email:
{{email_content}}

{{additional_instructions}}

Generate only the reply body, no subject line or signatures.`,
})
const showApiKey = ref(false)
const savingAI = ref(false)
const aiLoaded = ref(false)

// Load AI settings when tab becomes active
async function loadAISettings() {
  if (aiLoaded.value) return
  
  isDebugEnabled() && console.log('Loading AI settings...')
  const data = await aiStore.fetchSettings()
  isDebugEnabled() && console.log('AI settings data:', data)
  
  if (data) {
    aiConfigured.value = data.configured
    aiSettings.value.model = data.model || 'gpt-5-nano'
    aiSettings.value.writing_style = data.writing_style || 'professional'
    aiSettings.value.temperature = data.temperature ?? 1.0
    aiSettings.value.prompt_summarize = data.prompts?.summarize || ''
    aiSettings.value.prompt_rewrite = data.prompts?.rewrite || ''
    aiSettings.value.prompt_draft_reply = data.prompts?.draft_reply || ''
    // Update with server values if available
    if (data.available_models && Object.keys(data.available_models).length > 0) {
      aiModels.value = data.available_models
    }
    if (data.available_styles && Object.keys(data.available_styles).length > 0) {
      aiStyles.value = data.available_styles
    }
    if (data.default_prompts) {
      isDebugEnabled() && console.log('Default prompts:', data.default_prompts)
      aiDefaultPrompts.value = data.default_prompts
    }
    // Show placeholder if API key is configured
    if (data.configured) {
      aiSettings.value.api_key = '********'
    }
    aiLoaded.value = true
  } else {
    console.error('Failed to load AI settings - no data returned')
  }
}

// ==================== STORAGE SETTINGS ====================
// Storage configuration is read-only (managed via Panel)

// Load storage configuration from Panel
async function loadStorageConfig() {
  loadingStorage.value = true
  try {
    const response = await api.get('/settings/storage')
    if (response.data.success) {
      const data = response.data.data
      storageConfig.value = {
        driver: data.driver,
        storage_name: data.storage_name || (data.driver === 'nfs' ? t('settingsView.nasStorage') : t('settingsView.localStorage')),
        path: data.config?.path || data.stats?.path || '',
        source: data.config?.source || 'unknown',
        is_from_panel: data.is_from_panel || false
      }
      storageStats.value = data.stats
    }
  } catch (e) {
    console.error('Failed to load storage config:', e)
    toast.error(t('settingsView.failedToLoadStorageConfiguration'))
  } finally {
    loadingStorage.value = false
  }
}

// Save AI settings
async function saveAISettings() {
  savingAI.value = true
  
  const payload = {
    ai_model: aiSettings.value.model,
    ai_writing_style: aiSettings.value.writing_style,
    ai_temperature: aiSettings.value.temperature,
    ai_prompt_summarize: aiSettings.value.prompt_summarize,
    ai_prompt_rewrite: aiSettings.value.prompt_rewrite,
    ai_prompt_draft_reply: aiSettings.value.prompt_draft_reply,
  }
  
  // Only include API key if it was changed (not empty and not placeholder)
  if (aiSettings.value.api_key && aiSettings.value.api_key !== '********') {
    payload.ai_api_key = aiSettings.value.api_key
  }
  
  isDebugEnabled() && console.log('Saving AI settings:', { ...payload, ai_api_key: payload.ai_api_key ? '[REDACTED]' : undefined })
  
  const result = await aiStore.saveSettings(payload)
  savingAI.value = false
  
  isDebugEnabled() && console.log('Save result:', result)
  
  if (result.success) {
    // Use the server's configured state
    aiConfigured.value = aiStore.configured
    toast.success(t('settingsView.aiSettingsSaved'))
    // Show placeholder for API key after save
    if (aiSettings.value.api_key && aiSettings.value.api_key !== '********') {
      aiSettings.value.api_key = '********'
    }
  } else {
    toast.error(result.error || t('settingsView.failedToSaveAiSettings'))
  }
}

// Clear AI API key
async function clearApiKey() {
  savingAI.value = true
  const result = await aiStore.saveSettings({ ai_api_key: '' })
  savingAI.value = false
  
  if (result.success) {
    aiConfigured.value = false
    aiSettings.value.api_key = ''
    toast.success(t('settingsView.apiKeyRemoved'))
  } else {
    toast.error(t('settingsView.failedToRemoveApiKey'))
  }
}

// Reset prompt to default
function resetPrompt(type) {
  if (aiDefaultPrompts.value[type]) {
    aiSettings.value[`prompt_${type}`] = ''
    toast.info(t('settingsView.promptResetToDefault'))
  }
}

// Copy prompt to clipboard
async function copyPrompt(type) {
  // Use the custom prompt if set, otherwise use the default
  const prompt = aiSettings.value[`prompt_${type}`] || aiDefaultPrompts.value[type] || ''
  if (!prompt) {
    toast.error(t('settingsView.noPromptToCopy'))
    return
  }
  
  try {
    await navigator.clipboard.writeText(prompt)
    toast.success(t('settingsView.promptCopiedToClipboard'))
  } catch (err) {
    // Fallback for older browsers
    const textArea = document.createElement('textarea')
    textArea.value = prompt
    document.body.appendChild(textArea)
    textArea.select()
    document.execCommand('copy')
    document.body.removeChild(textArea)
    toast.success(t('settingsView.promptCopiedToClipboard'))
  }
}

// Google Calendar sync state
const googleCalendarSyncLoading = ref(false)
const googleCalendarList = ref([])
const selectedGoogleCalendars = ref([]) // Changed to array for multi-select
const selectedLocalCalendar = ref(null)
const googleCalendarSyncConfigs = ref([])

// OAuth account removal state
const removingOAuthAccount = ref(null)
const showRemoveAccountConfirm = ref(false)
const accountToRemove = ref(null)

// Google OAuth state for adding accounts
const connectingGoogle = ref(false)

// Toggle Google calendar selection
function toggleGoogleCalendarSelection(calendarId) {
  const index = selectedGoogleCalendars.value.indexOf(calendarId)
  if (index > -1) {
    selectedGoogleCalendars.value.splice(index, 1)
  } else {
    selectedGoogleCalendars.value.push(calendarId)
  }
}

// Check if a Google calendar is selected
function isGoogleCalendarSelected(calendarId) {
  return selectedGoogleCalendars.value.includes(calendarId)
}

// Get all OAuth accounts (Gmail accounts) - these provide Google Calendar data
const oauthAccounts = computed(() => {
  return accountsStore.accounts.filter(a => a.is_oauth)
})

// Check if current account is OAuth
const isCurrentAccountOAuth = computed(() => {
  return currentAccount.value && currentAccount.value.is_oauth
})

// Get current account's local calendars only (for sync destination)
const currentAccountCalendars = computed(() => {
  // Calendars are already filtered by the active account on the backend
  return calendarStore.calendars
})

// Add Google Account via OAuth
async function addGoogleAccount() {
  connectingGoogle.value = true
  
  try {
    // Get the OAuth URL
    const authUrl = await accountsStore.getGoogleAuthUrl({
      account_type: 'linked',
      sync_frequency: 'realtime',
      leave_on_server: true,
      auto_label: false,
    })
    
    if (!authUrl) {
      toast.error(t('settingsView.googleSignInNotAvailable'))
      connectingGoogle.value = false
      return
    }
    
    // Open OAuth popup
    const width = 500
    const height = 600
    const left = window.screenX + (window.outerWidth - width) / 2
    const top = window.screenY + (window.outerHeight - height) / 2
    
    const popup = window.open(
      authUrl,
      'google-oauth',
      `width=${width},height=${height},left=${left},top=${top},popup=1`
    )
    
    // Check if popup was blocked
    if (!popup || popup.closed) {
      toast.error(t('settingsView.popupWasBlockedPleaseAllow'))
      connectingGoogle.value = false
      return
    }
    
    let messageHandled = false
    
    // Helper to check sessionStorage for result (fallback)
    const checkSessionStorage = async () => {
      const resultJson = sessionStorage.getItem('oauth_callback_result')
      if (resultJson) {
        try {
          const data = JSON.parse(resultJson)
          if (data.type === 'oauth_callback') {
            sessionStorage.removeItem('oauth_callback_result')
            messageHandled = true
            connectingGoogle.value = false
            
            if (data.success) {
              await accountsStore.fetchAccounts()
              toast.success(t('settingsView.googleAccountConnected', { email: data.account_email || '' }))
            } else if (data.error) {
              toast.error(t('settingsView.googleSignInFailed', { error: data.error.replace(/_/g, ' ') }))
            }
            return true
          }
        } catch (e) {
          console.error('Failed to parse OAuth result from sessionStorage:', e)
        }
      }
      return false
    }
    
    // Listen for OAuth callback via postMessage
    const handleOAuthMessage = async (event) => {
      // Phase 2.3: strict origin equality (substring match would accept
      // crafted hosts like flowone.pro.attacker.com)
      if (event.origin !== window.location.origin) return
      if (event.data?.type !== 'oauth_callback') return
      if (messageHandled) return
      
      messageHandled = true
      window.removeEventListener('message', handleOAuthMessage)
      clearInterval(checkClosed)
      connectingGoogle.value = false
      
      // Clear sessionStorage since we got the message directly
      sessionStorage.removeItem('oauth_callback_result')
      
      const { success, error, account_email } = event.data
      
      if (success) {
        await accountsStore.fetchAccounts()
        toast.success(t('settingsView.googleAccountConnected', { email: account_email || '' }))
      } else if (error) {
        toast.error(t('settingsView.googleSignInFailed', { error: error.replace(/_/g, ' ') }))
      }
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    // Check if popup was closed - also check sessionStorage for result
    // Wrapped in try-catch because Cross-Origin-Opener-Policy on Google's
    // OAuth pages severs the opener relationship, making popup.closed throw
    const checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }

      // Check sessionStorage first (works even when COOP blocks popup.closed)
      const handled = await checkSessionStorage()
      if (handled) {
        clearInterval(checkClosed)
        window.removeEventListener('message', handleOAuthMessage)
        return
      }

      try {
        if (!popup || popup.closed) {
          clearInterval(checkClosed)
          window.removeEventListener('message', handleOAuthMessage)
          
          const handledAfterClose = await checkSessionStorage()
          if (!handledAfterClose) {
            connectingGoogle.value = false
          }
        }
      } catch {
        // COOP blocks access to popup.closed - keep polling sessionStorage
      }
    }, 500)
    
    // Timeout after 5 minutes
    setTimeout(() => {
      if (messageHandled) return
      clearInterval(checkClosed)
      window.removeEventListener('message', handleOAuthMessage)
      connectingGoogle.value = false
    }, 300000)
    
  } catch (e) {
    console.error('Google OAuth error:', e)
    toast.error(t('settingsView.failedToInitiateGoogleSignin'))
    connectingGoogle.value = false
  }
}

// Load Google calendars for an OAuth account
async function loadGoogleCalendars(accountId) {
  googleCalendarSyncLoading.value = true
  try {
    const calendars = await calendarStore.fetchGoogleCalendars(accountId)
    googleCalendarList.value = calendars
    const configs = await calendarStore.fetchSyncConfigs(accountId)
    googleCalendarSyncConfigs.value = configs
  } catch (e) {
    console.error('Failed to load Google calendars:', e)
    toast.error(t('settingsView.failedToLoadGoogleCalendars'))
  } finally {
    googleCalendarSyncLoading.value = false
  }
}

// Setup sync between local and Google calendar (supports multiple Google calendars)
async function setupCalendarSync(accountId) {
  if (selectedGoogleCalendars.value.length === 0 || !selectedLocalCalendar.value) {
    toast.warning(t('settingsView.pleaseSelectAtLeastOne'))
    return
  }
  
  googleCalendarSyncLoading.value = true

  try {
    // ONE request to configure all selected calendars, ONE request to
    // pull events for them. Server skips already-synced entries.
    const setup = await calendarStore.bulkSetupGoogleSync(
      accountId,
      [...selectedGoogleCalendars.value],
      selectedLocalCalendar.value
    )

    if (setup.success && setup.configured > 0) {
      toast.success(t('settingsView.calendarsSyncConfigured', { count: setup.configured }))

      const pull = await calendarStore.bulkSyncFromGoogle(
        accountId,
        [...selectedGoogleCalendars.value]
      )
      if (pull.success && pull.imported > 0) {
        toast.success(t('settingsView.syncedEventsFromGoogle', { count: pull.imported }))
      }
    }
    if (setup.failed > 0) {
      toast.warning(t('settingsView.calendarsFailed', { count: setup.failed }))
    }

    // calendarStore.bulkSetupGoogleSync already refreshes syncConfigs.
    googleCalendarSyncConfigs.value = calendarStore.syncConfigs
    selectedGoogleCalendars.value = []
    selectedLocalCalendar.value = null
  } finally {
    googleCalendarSyncLoading.value = false
  }
}

// Trigger sync from Google
async function syncFromGoogleCalendar(accountId, googleCalendarId) {
  googleCalendarSyncLoading.value = true
  try {
    const result = await calendarStore.syncFromGoogle(accountId, googleCalendarId)
    
    if (result.success) {
      toast.success(t('settingsView.importedEvents', { imported: result.imported, updated: result.updated }))
    } else {
      toast.error(result.error || t('settingsView.failedToSyncFromGoogle'))
    }
  } finally {
    googleCalendarSyncLoading.value = false
  }
}

// Disable sync
async function disableCalendarSync(accountId, googleCalendarId) {
  googleCalendarSyncLoading.value = true
  try {
    const result = await calendarStore.disableGoogleSync(accountId, googleCalendarId)
    
    if (result.success) {
      toast.success(t('settingsView.calendarSyncDisabled'))
      // Refresh sync configs
      await calendarStore.fetchSyncConfigs(accountId)
      googleCalendarSyncConfigs.value = calendarStore.syncConfigs
    } else {
      toast.error(result.error || t('settingsView.failedToDisableSync'))
    }
  } finally {
    googleCalendarSyncLoading.value = false
  }
}

// OAuth account removal
function confirmRemoveOAuthAccount(account) {
  accountToRemove.value = account
  showRemoveAccountConfirm.value = true
}

async function removeOAuthAccount() {
  if (!accountToRemove.value) return
  
  removingOAuthAccount.value = accountToRemove.value.id
  showRemoveAccountConfirm.value = false
  
  try {
    const success = await accountsStore.deleteOAuthAccount(accountToRemove.value.id)
    
    if (success) {
      toast.success(t('settingsView.removedAccount', { email: accountToRemove.value.account_email }))
      // Clear the calendar list if it was showing this account's calendars
      googleCalendarList.value = []
      selectedGoogleCalendars.value = []
    } else {
      toast.error(t('settingsView.failedToRemoveAccount'))
    }
  } catch (e) {
    toast.error(e.message || t('settingsView.failedToRemoveAccount'))
  } finally {
    removingOAuthAccount.value = null
    accountToRemove.value = null
  }
}

// System diagnostics
const permissionsCheck = ref(null)
const checkingPermissions = ref(false)
const fixingPermissions = ref(false)

async function checkPermissions() {
  checkingPermissions.value = true
  try {
    const response = await api.get('/system/permissions')
    if (response.data.success) {
      permissionsCheck.value = response.data.data.permissions
    } else {
      toast.error(response.data.message || t('settingsView.failedToCheckPermissions'))
    }
  } catch (e) {
    console.error('Permission check error:', e)
    const msg = e.response?.data?.message || e.message || t('settingsView.failedToCheckPermissions')
    toast.error(msg)
  } finally {
    checkingPermissions.value = false
  }
}

async function fixPermissions() {
  fixingPermissions.value = true
  try {
    const response = await api.post('/system/permissions/fix')
    if (response.data.success) {
      const result = response.data.data
      
      if (result.needs_manual) {
        toast.warning(t('settingsView.someFixesRequireSudo'))
        isDebugEnabled() && console.log('Manual commands needed:', result.manual_commands)
      } else {
        toast.success(t('settingsView.permissionsFixedSuccessfully'))
      }
      
      // Refresh the check
      await checkPermissions()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('settingsView.failedToFixPermissions'))
  } finally {
    fixingPermissions.value = false
  }
}

function setTab(tabId) {
  // Generic escape hatch: a sidebar item can carry `externalRoute` to
  // jump out of Settings entirely. Currently unused (the Storage
  // Dashboard renders inline as a Settings tab) but kept for future
  // items that genuinely need a dedicated full-screen URL.
  const item = tabs.value.find(t => t.id === tabId)
  if (item?.externalRoute) {
    router.push(item.externalRoute)
    return
  }
  activeTab.value = tabId
  router.replace({ query: { tab: tabId } })
}

// Use storeToRefs for proper Pinia reactivity
const { settings: storeSettings } = storeToRefs(settingsStore)

// Local settings ref that syncs with store
const settings = ref({
  display_name: '',
  signature: '',
  messages_per_page: 50,
  theme: 'system',
  accent_color: 'green',
  auto_mark_read: true,
  confirm_delete: true,
  refresh_interval: 60,
  large_attachment_threshold: 10,
  block_remote_images: true,
  // Out of Office settings
  ooo_enabled: false,
  ooo_subject: '',
  ooo_message: '',
  ooo_start_date: '',
  ooo_end_date: '',
  undo_send_delay: 0,
  compose_style: 'modal',
  auto_add_mentions_to_recipients: true,
  notify_on_mention: true,
})

// Watch for store settings changes and sync to local
watch(storeSettings, (newSettings) => {
  if (newSettings) {
    Object.assign(settings.value, newSettings)
  }
}, { deep: true, immediate: true })

// Watch for theme store changes and sync to local settings
watch(() => theme.accentColor, (newAccent) => {
  settings.value.accent_color = newAccent
}, { immediate: true })

watch(() => theme.theme, (newTheme) => {
  settings.value.theme = newTheme
}, { immediate: true })

const password = ref({
  current: '',
  new: '',
  confirm: '',
})

// Browser notifications
const browserNotificationsEnabled = ref(browserNotifications.enabled)
const notificationPermission = ref(('Notification' in window) ? Notification.permission : 'denied')

// Notification settings (stored in localStorage)
const notificationSettings = ref({
  desktopEnabled: localStorage.getItem('notification_desktop') !== 'false',
  readReceiptsEnabled: localStorage.getItem('notification_read_receipts') !== 'false',
  newEmailEnabled: localStorage.getItem('notification_new_email') !== 'false',
  chatMessagesEnabled: localStorage.getItem('notification_chat') !== 'false',
  calendarRemindersEnabled: localStorage.getItem('notification_calendar') !== 'false',
  reminderTimes: JSON.parse(localStorage.getItem('notification_reminder_times') || '[15, 5, 0]'),
  soundEnabled: localStorage.getItem('notification_sound') !== 'false',
})

// Server-side per-type push preferences (apply to all of the user's devices).
// localStorage mirrors the last-known state for an instant first paint.
const PUSH_PREF_TYPES = ['email', 'chat', 'calls', 'calendar', 'boards']
const pushPrefs = ref(JSON.parse(localStorage.getItem('push_prefs') || '{}'))
PUSH_PREF_TYPES.forEach((type) => {
  if (pushPrefs.value[type] === undefined) pushPrefs.value[type] = true
})
const pushPrefsSaving = ref(false)

async function loadPushPrefs() {
  try {
    const res = await api.get('/push/preferences')
    const prefs = res.data?.preferences
    if (prefs && typeof prefs === 'object') {
      PUSH_PREF_TYPES.forEach((type) => {
        if (prefs[type] !== undefined) pushPrefs.value[type] = !!prefs[type]
      })
      localStorage.setItem('push_prefs', JSON.stringify(pushPrefs.value))
    }
  } catch (e) {
    // Keep cached/default values on failure.
  }
}

async function togglePushPref(type) {
  if (!PUSH_PREF_TYPES.includes(type)) return
  const next = !pushPrefs.value[type]
  pushPrefs.value[type] = next
  localStorage.setItem('push_prefs', JSON.stringify(pushPrefs.value))
  pushPrefsSaving.value = true
  try {
    await api.put('/push/preferences', { [type]: next })
  } catch (e) {
    // Revert on failure.
    pushPrefs.value[type] = !next
    localStorage.setItem('push_prefs', JSON.stringify(pushPrefs.value))
    toast.error(t('settingsView.failedToLoadSettings'))
  } finally {
    pushPrefsSaving.value = false
  }
}

// Debug logs (stored in localStorage, not backend - client-side only)
const debugLogsEnabled = ref(localStorage.getItem('webmail_debug_logs') === 'true')

function toggleDebugLogs() {
  debugLogsEnabled.value = !debugLogsEnabled.value
  localStorage.setItem('webmail_debug_logs', debugLogsEnabled.value ? 'true' : 'false')
  toast.success(debugLogsEnabled.value ? t('settingsView.debugLogsEnabled') : t('settingsView.debugLogsDisabled'))
}

// Bug report button (stored in localStorage)
const bugReportEnabled = ref(localStorage.getItem('bug_report_enabled') !== 'false')

function toggleBugReport() {
  bugReportEnabled.value = !bugReportEnabled.value
  localStorage.setItem('bug_report_enabled', bugReportEnabled.value ? 'true' : 'false')
  toast.success(bugReportEnabled.value ? 'Bug report button enabled' : 'Bug report button disabled')
}

// Platform tour header button (hidden by default, opt-in)
function togglePlatformTour() {
  onboardingStore.setTourButtonEnabled(!onboardingStore.tourButtonEnabled)
  toast.success(onboardingStore.tourButtonEnabled ? 'Platform tour button enabled' : 'Platform tour button hidden')
}

function saveNotificationSetting(key, value) {
  localStorage.setItem(key, JSON.stringify(value))
}

async function toggleBrowserNotifications() {
  if (!browserNotificationsEnabled.value) {
    // Enabling - request permission
    const granted = await browserNotifications.requestPermission()
    if (granted) {
      browserNotifications.setEnabled(true)
      browserNotificationsEnabled.value = true
      notificationPermission.value = 'granted'
      notificationSettings.value.desktopEnabled = true
      saveNotificationSetting('notification_desktop', true)
      toast.success(t('settingsView.browserNotificationsEnabled'))
    } else {
      toast.warning(t('settingsView.permissionDeniedBrowser'))
      notificationPermission.value = ('Notification' in window) ? Notification.permission : 'denied'
    }
  } else {
    // Disabling
    browserNotifications.setEnabled(false)
    browserNotificationsEnabled.value = false
    notificationSettings.value.desktopEnabled = false
    saveNotificationSetting('notification_desktop', false)
    toast.info(t('settingsView.browserNotificationsDisabledMsg'))
  }
}

function toggleNewEmailNotifications() {
  notificationSettings.value.newEmailEnabled = !notificationSettings.value.newEmailEnabled
  saveNotificationSetting('notification_new_email', notificationSettings.value.newEmailEnabled)
  toast.success(notificationSettings.value.newEmailEnabled ? t('settingsView.newEmailNotifEnabled') : t('settingsView.newEmailNotifDisabled'))
}

function toggleChatNotifications() {
  notificationSettings.value.chatMessagesEnabled = !notificationSettings.value.chatMessagesEnabled
  saveNotificationSetting('notification_chat', notificationSettings.value.chatMessagesEnabled)
  toast.success(notificationSettings.value.chatMessagesEnabled ? t('settingsView.chatNotifEnabled') : t('settingsView.chatNotifDisabled'))
}

function toggleReadReceiptNotifications() {
  notificationSettings.value.readReceiptsEnabled = !notificationSettings.value.readReceiptsEnabled
  saveNotificationSetting('notification_read_receipts', notificationSettings.value.readReceiptsEnabled)
  toast.success(notificationSettings.value.readReceiptsEnabled ? t('settingsView.readReceiptNotifEnabled') : t('settingsView.readReceiptNotifDisabled'))
}

function toggleCalendarReminders() {
  notificationSettings.value.calendarRemindersEnabled = !notificationSettings.value.calendarRemindersEnabled
  saveNotificationSetting('notification_calendar', notificationSettings.value.calendarRemindersEnabled)
  toast.success(notificationSettings.value.calendarRemindersEnabled ? t('settingsView.calendarRemindersEnabledMsg') : t('settingsView.calendarRemindersDisabledMsg'))
}

function toggleReminderTime(minutes) {
  const times = notificationSettings.value.reminderTimes
  const index = times.indexOf(minutes)
  if (index > -1) {
    times.splice(index, 1)
  } else {
    times.push(minutes)
    times.sort((a, b) => b - a) // Sort descending
  }
  saveNotificationSetting('notification_reminder_times', times)
}

function toggleSoundNotifications() {
  notificationSettings.value.soundEnabled = !notificationSettings.value.soundEnabled
  saveNotificationSetting('notification_sound', notificationSettings.value.soundEnabled)
  toast.success(notificationSettings.value.soundEnabled ? t('settingsView.notifSoundsEnabled') : t('settingsView.notifSoundsDisabled'))
}

function previewEmailSound() {
  notificationSounds.playEmailSound({ force: true })
}

function previewChatSound() {
  notificationSounds.playChatSound({ force: true })
}

async function testNotification() {
  if (!browserNotificationsEnabled.value) {
    toast.warning(t('settingsView.enableDesktopNotifFirst'))
    return
  }
  await browserNotifications.show(t('settingsView.testNotificationTitle'), {
    body: t('settingsView.testNotificationBody'),
    tag: 'test-notification'
  })
  toast.success(t('settingsView.testNotificationSent'))
}

// Push notifications (PWA - works even when app is closed)
const pushLoading = ref(false)

async function togglePushNotifications() {
  pushLoading.value = true
  try {
    if (pushStatus.value === 'subscribed') {
      await pushNotifications.unsubscribe()
      toast.success(t('settingsView.pushNotificationsDisabled'))
    } else {
      // This MUST be called from user gesture (button click) for iOS
      const subscription = await pushNotifications.subscribe()
      if (subscription) {
        toast.success(t('settingsView.pushNotifEnabled'))
      } else if (pushStatus.value === 'denied') {
        toast.warning(t('settingsView.permissionDeniedDevice'))
      } else {
        toast.warning(t('settingsView.couldNotEnablePush'))
      }
    }
  } catch (e) {
    console.error('[Settings] Push toggle failed:', e)
    toast.error(t('settingsView.failedToTogglePushNotifications'))
  }
  pushLoading.value = false
}

// Initialize push notification status
pushNotifications.init()

onMounted(async () => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  
  // Load desktop app settings if running in Electron
  loadDesktopSettings()

  // Check biometric support for app lock settings
  appLockCheckBiometricSupport()
  
  // Check for OAuth callback result from sessionStorage (fallback when popup lost opener)
  await checkOAuthFallback()

  // Load server-side push notification preferences (per-type gating).
  loadPushPrefs()
  
  try {
    // First fetch accounts to validate the active account (like MailboxView does)
    await accountsStore.fetchAccounts()
    
    // Then fetch settings for the current (validated) account
    await settingsStore.fetchSettings(true) // Force reload for current account
    
    // Load calendars if calendar tab is active (and addon enabled)
    if (activeTab.value === 'calendar') {
      const { calendarEnabled } = useAddons()
      if (calendarEnabled.value) await calendarStore.fetchCalendars()
    }
    
    // Load cache stats if cache tab is active
    if (activeTab.value === 'cache') {
      await loadCacheStats()
    }
  } catch (e) {
    toast.error(t('settingsView.failedToLoadSettings'))
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

// Watch for tab changes to load data when needed
watch(activeTab, async (newTab) => {
  if (iosNative && newTab === 'accounts') {
    activeTab.value = 'general'
    return
  }
  if (newTab === 'calendar') {
    const { calendarEnabled } = useAddons()
    if (calendarEnabled.value) await calendarStore.fetchCalendars()
  } else if (newTab === 'ai') {
    await loadAISettings()
  } else if (newTab === 'cache') {
    await loadCacheStats()
  } else if (newTab === 'drive-storage') {
    await loadStorageConfig()
  }
})

async function saveSettings() {
  saving.value = true
  try {
    // Ensure we save the current theme store values (in case user clicked accent without saving)
    const settingsToSave = {
      ...settings.value,
      theme: theme.theme,
      accent_color: theme.accentColor,
    }
    
    const result = await settingsStore.updateSettings(settingsToSave)
    if (result.success) {
      toast.success(t('settingsView.settingsSaved'))
    } else {
      toast.error(result.error || t('settingsView.failedToSaveSettings'))
    }
  } catch (e) {
    toast.error(t('settingsView.failedToSaveSettings'))
  } finally {
    saving.value = false
  }
}

// Apply display density using theme store
function applyDisplayDensity(density) {
  theme.setDisplayDensity(density, false) // Don't save to server yet - will save with saveSettings()
}

async function changePassword() {
  if (!password.value.current || !password.value.new) {
    toast.warning(t('settingsView.pleaseFillAllPasswordFields'))
    return
  }
  
  if (password.value.new !== password.value.confirm) {
    toast.warning(t('settingsView.newPasswordsDoNotMatch'))
    return
  }
  
  if (password.value.new.length < 8) {
    toast.warning(t('settingsView.passwordMustBeAtLeast'))
    return
  }
  
  changingPassword.value = true
  try {
    const response = await api.put('/settings/password', {
      current_password: password.value.current,
      new_password: password.value.new,
    })
    
    if (response.data.success) {
      toast.success(t('settingsView.passwordChanged'))
      password.value = { current: '', new: '', confirm: '' }
    } else {
      toast.error(response.data.message || t('settingsView.failedToChangePassword'))
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('settingsView.failedToChangePassword'))
  } finally {
    changingPassword.value = false
  }
}

</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="settings"
      icon="settings"
      :title="$t('settingsView.settings')"
      :show-mobile-menu="true"
      @toggle-sidebar="toggleSidebar"
    />
    
    <!-- Content with Sidebar -->
    <div :class="isMobile ? 'flex-1 flex flex-col min-h-0' : 'flex flex-1 overflow-hidden'">
      <!-- Desktop Sidebar -->
      <aside 
        v-if="!isMobile"
        class="w-64 bg-white dark:bg-[rgb(var(--color-surface))] border-r border-surface-200 dark:border-[rgb(var(--color-border))] overflow-y-auto flex-shrink-0 hidden md:block"
      >
        <nav class="p-4 space-y-6">
          <div v-for="group in sidebarGroups" :key="group.name">
            <h3 class="px-3 mb-2 text-xs font-semibold text-surface-500 uppercase tracking-wider">
              {{ group.name }}
            </h3>
            <div class="space-y-1">
              <button
                v-for="item in group.items"
                :key="item.id"
                @click="setTab(item.id)"
                :class="[
                  'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all',
                  activeTab === item.id 
                    ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                    : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
                ]"
              >
                <span class="material-symbols-rounded text-lg">{{ item.icon }}</span>
                {{ item.name }}
              </button>
            </div>
          </div>
        </nav>
      </aside>

      <!-- Mobile: current tab indicator + menu trigger -->
      <div v-if="isMobile" class="flex items-center justify-between px-4 py-2.5 bg-white dark:bg-[rgb(var(--color-surface))] border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex-shrink-0">
        <button
          @click="sidebarOpen = true"
          class="flex items-center gap-2 text-sm font-medium text-surface-900 dark:text-surface-100"
        >
          <span class="material-symbols-rounded text-lg text-primary-500">{{ tabs.find(t => t.id === activeTab)?.icon || 'settings' }}</span>
          {{ tabs.find(t => t.id === activeTab)?.name || 'Settings' }}
          <span class="material-symbols-rounded text-lg text-surface-400">expand_more</span>
        </button>
      </div>
      
      <!-- Main Content -->
      <main :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 overflow-y-auto'">
        <div class="p-4 md:p-8">
      
      <!-- Accounts Tab (hidden on native iOS - strict single org account) -->
      <div v-if="activeTab === 'accounts' && !iosNative">
        <AccountsTab />
      </div>
      
      <!-- General Settings Tab -->
      <div v-else-if="activeTab === 'general'">
        <div v-if="loading" class="flex items-center justify-center py-12">
          <span class="spinner text-primary-500"></span>
        </div>
        
        <div v-else class="space-y-8">
          <!-- PROFILE AVATAR SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">account_circle</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.profilePicture') }}</h2>
            </div>
            
            <div class="card p-4 md:p-6">
              <div class="flex flex-col sm:flex-row items-center gap-4 sm:gap-6">
                <!-- Current Avatar Preview -->
                <div class="relative group flex-shrink-0">
                  <UserAvatar
                    :email="auth.userEmail"
                    :name="auth.displayName"
                    :avatar-url="currentAvatarUrl || ''"
                    size="3xl"
                  />
                  <button
                    @click="triggerAvatarUpload"
                    class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer"
                  >
                    <span class="material-symbols-rounded text-white text-2xl">photo_camera</span>
                  </button>
                  <div
                    v-if="avatarUploading"
                    class="absolute inset-0 rounded-full bg-black/60 flex items-center justify-center"
                  >
                    <span class="spinner text-white"></span>
                  </div>
                </div>
                
                <!-- Info & Actions -->
                <div class="flex-1 min-w-0 text-center sm:text-left">
                  <h3 class="text-base font-medium text-surface-900 dark:text-surface-100 truncate">
                    {{ auth.displayName }}
                  </h3>
                  <p class="text-sm text-surface-500 mb-3 truncate">{{ auth.userEmail }}</p>
                  
                  <div class="flex items-center justify-center sm:justify-start gap-2 flex-wrap">
                    <button
                      @click="triggerAvatarUpload"
                      :disabled="avatarUploading"
                      class="px-4 py-1.5 text-sm font-medium rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50"
                    >
                      <span class="material-symbols-rounded text-sm mr-1 align-text-bottom">upload</span>
                      {{ currentAvatarUrl ? $t('settingsView.changePhoto') : $t('settingsView.uploadPhoto') }}
                    </button>
                    <button
                      v-if="currentAvatarUrl"
                      @click="removeAvatar"
                      :disabled="avatarUploading"
                      class="px-4 py-1.5 text-sm font-medium rounded-full border border-surface-300 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors disabled:opacity-50"
                    >
                      <span class="material-symbols-rounded text-sm mr-1 align-text-bottom">delete</span>
                      {{ $t('settingsView.removeLabel') }}
                    </button>
                  </div>
                  <p class="mt-2 text-xs text-surface-400">{{ $t('settingsView.jpegPngGifOrWebp') }}</p>
                </div>
              </div>
              
              <!-- Hidden file input -->
              <input
                ref="avatarFileInput"
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                class="hidden"
                @change="handleAvatarFileSelected"
              />
            </div>
          </section>
          
          <!-- APPEARANCE SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">palette</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.appearance') }}</h2>
            </div>
            
            <div class="card p-4 md:p-6">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                    {{ $t('settingsView.themeLabel') }}
                  </label>
                  <select v-model="settings.theme" class="input" @change="theme.setTheme(settings.theme)">
                    <option value="system">{{ $t('settingsView.system') }}</option>
                    <option value="light">{{ $t('settingsView.light') }}</option>
                    <option value="dark">{{ $t('settingsView.dark') }}</option>
                  </select>
                </div>
                
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                    {{ $t('settingsView.messagesPerPage') }}
                  </label>
                  <select v-model="settings.messages_per_page" class="input">
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                  </select>
                </div>
                
                <div class="col-span-1 md:col-span-2">
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                    {{ $t('settingsView.backgroundRefreshInterval') }}
                  </label>
                  <select v-model="settings.refresh_interval" class="input w-full">
                    <option :value="0">{{ $t('settingsView.disabled') }}</option>
                    <option :value="30">{{ $t('settingsView.every30Seconds') }}</option>
                    <option :value="60">{{ $t('settingsView.every1Minute') }}</option>
                    <option :value="120">{{ $t('settingsView.every2Minutes') }}</option>
                    <option :value="300">{{ $t('settingsView.every5Minutes') }}</option>
                    <option :value="600">{{ $t('settingsView.every10Minutes') }}</option>
                  </select>
                  <p class="mt-1 text-xs text-surface-500">{{ $t('settingsView.howOftenToCheckFor') }}</p>
                </div>
              </div>
              
              <!-- Large Attachment Threshold -->
              <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('settingsView.largeAttachmentBehavior') }}
                </label>
                <select v-model="settings.large_attachment_threshold" class="input w-full">
                  <option :value="0">{{ $t('settingsView.alwaysAttachDirectlyNoDrive') }}</option>
                  <option :value="5">{{ $t('settingsView.uploadToDriveIfLargerThan5') }}</option>
                  <option :value="10">{{ $t('settingsView.uploadToDriveIfLargerThan10') }}</option>
                  <option :value="15">{{ $t('settingsView.uploadToDriveIfLargerThan15') }}</option>
                  <option :value="20">{{ $t('settingsView.uploadToDriveIfLargerThan20') }}</option>
                  <option :value="25">{{ $t('settingsView.uploadToDriveIfLarger') }}</option>
                </select>
                <p class="mt-2 text-xs text-surface-500">
                  {{ $t('settingsView.filesExceedingThisSize') }}
                </p>
              </div>
              
              <!-- Undo Send Delay -->
              <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 mt-4">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('settingsView.undoSend') }}
                </label>
                <select v-model="settings.undo_send_delay" class="input w-full">
                  <option :value="0">{{ $t('settingsView.off') }}</option>
                  <option :value="10">{{ $t('settingsView.undoSend10s') }}</option>
                  <option :value="20">{{ $t('settingsView.undoSend20s') }}</option>
                  <option :value="30">{{ $t('settingsView.undoSend30s') }}</option>
                  <option :value="60">{{ $t('settingsView.undoSend60s') }}</option>
                </select>
                <p class="mt-1 text-xs text-surface-500">{{ $t('settingsView.undoSendDesc') }}</p>
              </div>

              <!-- Accent Color Picker -->
              <div class="mt-6">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">
                  {{ $t('settingsView.accentColor') }}
                </label>
                <div class="flex flex-wrap gap-3">
                  <button
                    v-for="accent in theme.availableAccents"
                    :key="accent.id"
                    @click="theme.setAccentColor(accent.id)"
                    :title="accent.name"
                    :data-accent-swatch="accent.id"
                    :class="[
                      'w-10 h-10 rounded-full transition-all duration-200 flex items-center justify-center',
                      'ring-2 ring-offset-2 dark:ring-offset-surface-800',
                      theme.accentColor === accent.id 
                        ? 'ring-surface-900 dark:ring-white scale-110 is-selected' 
                        : 'ring-transparent hover:scale-105'
                    ]"
                    :style="{ background: accent.color }"
                  >
                    <span 
                      v-if="theme.accentColor === accent.id" 
                      class="material-symbols-rounded text-lg drop-shadow-md"
                      :class="accent.id === 'mono' ? 'text-primary-500' : 'text-white'"
                    >check</span>
                  </button>
                </div>
              </div>

              <!-- Ambient Background Toggle -->
              <div class="flex items-center justify-between mt-5 pt-4 border-t border-surface-100 dark:border-surface-700/50">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.ambientBackground') }}</p>
                  <p class="text-xs text-surface-500">{{ $t('settingsView.ambientBackgroundDesc') }}</p>
                </div>
                <button
                  @click="theme.setAmbientBackground(!theme.ambientBackground)"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', theme.ambientBackground ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', theme.ambientBackground ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
            </div>
          </section>
          
          <!-- BEHAVIOR SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">tune</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.behavior') }}</h2>
            </div>
            
            <div class="card p-4 md:p-6 space-y-4">
              <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.autoMarkAsRead') }}</p>
                  <p class="text-xs text-surface-500">{{ $t('settingsView.markMessagesAsReadWhen') }}</p>
                </div>
                <button
                  @click="settings.auto_mark_read = !settings.auto_mark_read"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', settings.auto_mark_read ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', settings.auto_mark_read ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
              
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.confirmDelete') }}</p>
                  <p class="text-xs text-surface-500">{{ $t('settingsView.askBeforeDeletingMessages') }}</p>
                </div>
                <button
                  @click="settings.confirm_delete = !settings.confirm_delete"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', settings.confirm_delete ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', settings.confirm_delete ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
              
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.blockRemoteImages') }}</p>
                  <p class="text-xs text-surface-500">{{ $t('settingsView.hideImagesFromUntrustedSenders') }}</p>
                </div>
                <button
                  @click="settings.block_remote_images = !settings.block_remote_images"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', settings.block_remote_images ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', settings.block_remote_images ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
              
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.overrideEmailStyling') }}</p>
                  <p class="text-xs text-surface-500">{{ $t('settingsView.forceReadableColorsInEmail') }}</p>
                </div>
                <button
                  @click="settings.override_email_styling = !settings.override_email_styling"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', settings.override_email_styling ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', settings.override_email_styling ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
              
              <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">Gmail-style compose</p>
                  <p class="text-xs text-surface-500">Open compose as a bottom-right floating window instead of a centered modal</p>
                </div>
                <button
                  @click="settings.compose_style = settings.compose_style === 'inline' ? 'modal' : 'inline'"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', settings.compose_style === 'inline' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', settings.compose_style === 'inline' ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>

              <!-- Mentions (Phase 3) -->
              <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
                <p class="text-xs font-semibold uppercase tracking-wide text-surface-500 mb-3">{{ $t('settingsView.mentionsSection') }}</p>

                <div class="flex items-center justify-between gap-4 mb-4">
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.autoAddMentions') }}</p>
                    <p class="text-xs text-surface-500">{{ $t('settingsView.autoAddMentionsDesc') }}</p>
                  </div>
                  <button
                    @click="settings.auto_add_mentions_to_recipients = !settings.auto_add_mentions_to_recipients"
                    :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', settings.auto_add_mentions_to_recipients ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                  >
                    <span
                      :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', settings.auto_add_mentions_to_recipients ? 'translate-x-6' : 'translate-x-0']"
                    ></span>
                  </button>
                </div>

                <div class="flex items-center justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.notifyOnMention') }}</p>
                    <p class="text-xs text-surface-500">{{ $t('settingsView.notifyOnMentionDesc') }}</p>
                  </div>
                  <button
                    @click="settings.notify_on_mention = !settings.notify_on_mention"
                    :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', settings.notify_on_mention ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                  >
                    <span
                      :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', settings.notify_on_mention ? 'translate-x-6' : 'translate-x-0']"
                    ></span>
                  </button>
                </div>
              </div>

              <div class="flex items-center justify-between pt-4 border-t border-surface-200 dark:border-surface-700">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.browserNotifications') }}</p>
                  <p class="text-xs text-surface-500">{{ $t('settingsView.getDesktopAlertsForRead') }}</p>
                </div>
                <button
                  @click="toggleBrowserNotifications"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', browserNotificationsEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', browserNotificationsEnabled ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
              
              <div v-if="notificationPermission === 'denied'" class="text-xs text-amber-500 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">warning</span>
                {{ $t('settingsView.notificationsBlockedBrowser') }}
              </div>
              
              <div class="flex items-center justify-between gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.debugLogs') }}</p>
                  <p class="text-xs text-surface-500">{{ $t('settingsView.showDetailedConsoleLogsFor') }}</p>
                </div>
                <button
                  @click="toggleDebugLogs"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', debugLogsEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', debugLogsEnabled ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>

              <div class="flex items-center justify-between gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">Bug Report Button</p>
                  <p class="text-xs text-surface-500">Show the floating bug report button for submitting feedback</p>
                </div>
                <button
                  @click="toggleBugReport"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', bugReportEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', bugReportEnabled ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>

              <div class="flex items-center justify-between gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">Platform Tour Button</p>
                  <p class="text-xs text-surface-500">Show the rocket Platform Tour button in the header</p>
                </div>
                <button
                  @click="togglePlatformTour"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', onboardingStore.tourButtonEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', onboardingStore.tourButtonEnabled ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
            </div>
          </section>
          
          <!-- Save Button -->
          <div class="flex justify-center pb-4 md:sticky md:bottom-4">
            <button 
              @click="saveSettings" 
              class="btn-primary shadow-lg px-8" 
              :disabled="saving"
            >
              <span v-if="saving" class="spinner"></span>
              <span class="material-symbols-rounded">save</span>
              {{ $t('settingsView.saveSettings') }}
            </button>
          </div>
        </div>
      </div>
      
      <!-- Signature Tab -->
      <div v-else-if="activeTab === 'signature'">
        <div v-if="loading" class="flex items-center justify-center py-12">
          <span class="spinner text-primary-500"></span>
        </div>
        
        <div v-else class="space-y-8">
          <!-- PROFILE SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">person</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.profile') }}</h2>
            </div>
            
            <div class="card p-4 md:p-6">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                    {{ $t('settingsView.emailAddress') }}
                  </label>
                  <input
                    :value="currentAccount.account_email"
                    type="email"
                    class="input bg-surface-100 dark:bg-surface-800"
                    disabled
                  />
                </div>
                
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                    {{ $t('settingsView.displayName') }}
                  </label>
                  <input
                    v-model="settings.display_name"
                    type="text"
                    class="input"
                    :placeholder="$t('settingsView.yourName')"
                  />
                </div>
              </div>

              <div class="mt-4 flex items-start gap-2 rounded-lg bg-surface-100 dark:bg-surface-800 p-3">
                <span class="material-symbols-rounded text-base text-surface-500 mt-0.5">info</span>
                <p class="text-xs text-surface-500 dark:text-surface-400">
                  {{ $t('settingsView.managedAccountNote') }}
                </p>
              </div>
            </div>
          </section>
          
          <!-- EMAIL SIGNATURE SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">draw</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.emailSignature') }}</h2>
            </div>
            
            <div class="card p-4 md:p-6">
              <p class="text-sm text-surface-500 mb-4">
                {{ $t('settingsView.signatureDescription') }}
              </p>
              <RichTextEditor
                v-model="settings.signature"
                :placeholder="$t('settingsView.yourEmailSignature')"
                :compact="false"
                :showAI="false"
              />
              <p class="text-xs text-surface-400 mt-3">
                {{ $t('settingsView.signatureTip') }}
              </p>
            </div>
          </section>
          
          <!-- OUT OF OFFICE SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">schedule_send</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.outOfOfficeAutoreply') }}</h2>
            </div>
            
            <div class="card p-4 md:p-6 space-y-6">
              <!-- Enable Toggle -->
              <div class="flex items-center justify-between gap-4">
                <div>
                  <h3 class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.enableOutOfOffice') }}</h3>
                  <p class="text-sm text-surface-500">{{ $t('settingsView.automaticallyReplyToIncomingEmails') }}</p>
                </div>
                <button 
                  @click="settings.ooo_enabled = !settings.ooo_enabled"
                  :class="[
                    'relative w-11 h-6 rounded-full transition-colors duration-200',
                    settings.ooo_enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                  ]"
                >
                  <span 
                    :class="[
                      'absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200',
                      settings.ooo_enabled ? 'translate-x-5' : 'translate-x-0'
                    ]"
                  ></span>
                </button>
              </div>
              
              <!-- OOO Settings (shown when enabled) -->
              <div v-if="settings.ooo_enabled" class="space-y-5 pt-4 border-t border-surface-200 dark:border-surface-700">
                <!-- Schedule -->
                <div>
                  <h4 class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-3 flex items-center gap-2">
                    <span class="material-symbols-rounded text-base">date_range</span>
                    {{ $t('settingsView.schedule') }}
                  </h4>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm text-surface-600 dark:text-surface-400 mb-1.5">{{ $t('settingsView.startDateTime') }}</label>
                      <input
                        v-model="settings.ooo_start_date"
                        type="datetime-local"
                        class="input"
                      />
                    </div>
                    <div>
                      <label class="block text-sm text-surface-600 dark:text-surface-400 mb-1.5">{{ $t('settingsView.endDateTime') }}</label>
                      <input
                        v-model="settings.ooo_end_date"
                        type="datetime-local"
                        class="input"
                      />
                    </div>
                  </div>
                  <p class="text-xs text-surface-400 mt-2">
                    {{ $t('settingsView.leaveEmptyToStart') }}
                  </p>
                </div>
                
                <!-- Subject Line -->
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                    {{ $t('settingsView.autoReplySubject') }}
                  </label>
                  <input
                    v-model="settings.ooo_subject"
                    type="text"
                    class="input"
                    :placeholder="$t('settingsView.outOfOfficeReOriginalsubject')"
                  />
                  <p class="text-xs text-surface-400 mt-1.5">
                    {{ $t('settingsView.useOriginalSubject', { placeholder: '{original_subject}' }) }}
                  </p>
                </div>
                
                <!-- Message -->
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                    {{ $t('settingsView.autoReplyMessage') }}
                  </label>
                  <RichTextEditor
                    v-model="settings.ooo_message"
                    :placeholder="$t('settingsView.iAmCurrentlyOutOf')"
                    :compact="false"
                    :showAI="false"
                  />
                  <p class="text-xs text-surface-400 mt-2">
                    {{ $t('settingsView.autoReplyDescription') }}
                  </p>
                </div>
                
                <!-- Status Indicator -->
                <div v-if="oooStatus" :class="['p-3 rounded-lg flex items-center gap-3', oooStatus.class]">
                  <span class="material-symbols-rounded">{{ oooStatus.icon }}</span>
                  <span class="text-sm font-medium">{{ oooStatus.text }}</span>
                </div>
              </div>
            </div>
          </section>
          
          <!-- Save Button -->
          <div class="flex justify-center pb-4 md:sticky md:bottom-4">
            <button 
              @click="saveSettings" 
              class="btn-primary shadow-lg px-8" 
              :disabled="saving"
            >
              <span v-if="saving" class="spinner"></span>
              <span class="material-symbols-rounded">save</span>
              {{ $t('settingsView.saveSettings') }}
            </button>
          </div>
        </div>
      </div>
      
      <!-- Security Tab -->
      <div v-else-if="activeTab === 'security'" class="space-y-8">
        <!-- TWO-FACTOR AUTHENTICATION -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">verified_user</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.twofactorAuthentication') }}</h2>
          </div>
          <TwoFactorSetup />
        </section>
        
        <!-- CHANGE PASSWORD -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">lock</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.changePassword') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('settingsView.currentPasswordLabel') }}
                </label>
                <input
                  v-model="password.current"
                  type="password"
                  class="input w-full"
                  :placeholder="$t('settingsView.enterCurrentPassword')"
                />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('settingsView.newPasswordLabel') }}
                </label>
                <input
                  v-model="password.new"
                  type="password"
                  class="input w-full"
                  :placeholder="$t('settingsView.enterNewPassword')"
                />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('settingsView.confirmNewPasswordLabel') }}
                </label>
                <input
                  v-model="password.confirm"
                  type="password"
                  class="input w-full"
                  :placeholder="$t('settingsView.confirmNewPassword')"
                />
              </div>
            </div>
            <div class="mt-4">
              <button @click="changePassword" class="btn-primary" :disabled="changingPassword">
                <span v-if="changingPassword" class="spinner"></span>
                <span class="material-symbols-rounded">lock</span>
                {{ $t('settingsView.changePassword') }}
              </button>
            </div>
          </div>
        </section>
        
        <!-- Idle Auto-Logout Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">schedule</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.idleAutologout') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-5">
            <p class="text-sm text-surface-500 dark:text-surface-400">
              {{ $t('settingsView.autoLogoutDescription') }}
            </p>
            
            <!-- Enable toggle -->
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.enableAutologout') }}</p>
                <p class="text-sm text-surface-500 dark:text-surface-400">{{ $t('settingsView.logOutWhenInactiveFor') }}</p>
              </div>
              <button
                @click="toggleIdleLogout"
                :class="idleLogoutEnabled ? 'bg-primary-600' : 'bg-surface-300 dark:bg-surface-600'"
                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out"
              >
                <span
                  :class="idleLogoutEnabled ? 'translate-x-5' : 'translate-x-0'"
                  class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5 ml-0.5"
                />
              </button>
            </div>
            
            <!-- Timeout selector -->
            <div v-if="idleLogoutEnabled" class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.timeout') }}</p>
                <p class="text-sm text-surface-500 dark:text-surface-400">{{ $t('settingsView.minutesOfInactivityBeforeWarning') }}</p>
              </div>
              <select
                v-model.number="idleTimeoutValue"
                @change="updateIdleTimeout"
                class="rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 px-3 py-2 text-sm"
              >
                <option :value="5">{{ $t('settingsView.5Minutes') }}</option>
                <option :value="10">{{ $t('settingsView.10Minutes') }}</option>
                <option :value="15">{{ $t('settingsView.15Minutes') }}</option>
                <option :value="30">{{ $t('settingsView.30Minutes') }}</option>
                <option :value="60">{{ $t('settingsView.1Hour') }}</option>
                <option :value="120">{{ $t('settingsView.2Hours') }}</option>
                <option :value="240">{{ $t('settingsView.4Hours') }}</option>
                <option :value="480">{{ $t('settingsView.8Hours') }}</option>
              </select>
            </div>
          </div>
        </section>

        <!-- APP LOCK (PIN / Biometric) -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">phonelink_lock</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.appLock') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-5">
            <p class="text-sm text-surface-500 dark:text-surface-400">
              {{ $t('settingsView.appLockDescription') }}
            </p>
            
            <!-- PIN Setup -->
            <div class="border border-surface-200 dark:border-surface-700 rounded-xl p-4 space-y-4">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.pinCode') }}</p>
                  <p class="text-sm text-surface-500 dark:text-surface-400">
                    {{ appLockHasPin ? $t('settingsView.pinIsSet') : $t('settingsView.setPinDescription') }}
                  </p>
                </div>
                <div class="flex items-center gap-2">
                  <span v-if="appLockHasPin" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">
                    <span class="material-symbols-rounded text-sm">check_circle</span>
                    {{ $t('settingsView.activeLabel') }}
                  </span>
                  <button
                    v-if="appLockHasPin"
                    @click="handleRemovePin"
                    class="text-sm text-red-500 hover:text-red-600 transition-colors"
                  >
                    {{ $t('settingsView.removeLabel') }}
                  </button>
                  <button
                    v-else
                    @click="appLockSettingPin = true"
                    class="btn-secondary btn-sm"
                  >
                    <span class="material-symbols-rounded text-base">pin</span>
                    {{ $t('settingsView.setPin') }}
                  </button>
                </div>
              </div>
              
              <!-- PIN Input Form -->
              <div v-if="appLockSettingPin || (!appLockHasPin && appLockSettingPin)" class="space-y-3 pt-2 border-t border-surface-200 dark:border-surface-700">
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">{{ $t('settingsView.newPin48Digits') }}</label>
                  <input
                    v-model="appLockPinInput"
                    type="password"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="8"
                    class="input max-w-48"
                    :placeholder="$t('settingsView.enterPin')"
                    @keyup.enter="saveAppLockPin"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">{{ $t('settingsView.confirmPin') }}</label>
                  <input
                    v-model="appLockPinConfirm"
                    type="password"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="8"
                    class="input max-w-48"
                    :placeholder="$t('settingsView.confirmPin')"
                    @keyup.enter="saveAppLockPin"
                  />
                </div>
                <p v-if="appLockPinError" class="text-sm text-red-500">{{ appLockPinError }}</p>
                <div class="flex items-center gap-2">
                  <button @click="saveAppLockPin" class="btn-primary btn-sm">
                    <span class="material-symbols-rounded text-base">save</span>
                    {{ $t('settingsView.savePin') }}
                  </button>
                  <button @click="appLockSettingPin = false; appLockPinInput = ''; appLockPinConfirm = ''; appLockPinError = ''" class="btn-secondary btn-sm">{{ $t('settingsView.cancel') }}</button>
                </div>
              </div>
              
              <!-- Change PIN -->
              <div v-if="appLockHasPin && !appLockSettingPin">
                <button @click="appLockSettingPin = true" class="text-sm text-primary-500 hover:text-primary-600 transition-colors">
                  {{ $t('settingsView.changePin') }}
                </button>
              </div>
            </div>
            
            <!-- Biometric Setup -->
            <div v-if="appLockBiometricAvailable" class="border border-surface-200 dark:border-surface-700 rounded-xl p-4">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.faceIdFingerprint') }}</p>
                  <p class="text-sm text-surface-500 dark:text-surface-400">
                    {{ appLockHasBiometric ? $t('settingsView.biometricRegisteredAlt') : $t('settingsView.useBiometricDescription') }}
                  </p>
                </div>
                <button
                  @click="toggleBiometric"
                  :disabled="appLockBioLoading || !appLockHasPin"
                  :class="appLockHasBiometric ? 'bg-primary-600' : 'bg-surface-300 dark:bg-surface-600'"
                  class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span
                    :class="appLockHasBiometric ? 'translate-x-5' : 'translate-x-0'"
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5 ml-0.5"
                  />
                </button>
              </div>
              <p v-if="!appLockHasPin" class="text-xs text-surface-400 mt-2">{{ $t('settingsView.setAPinFirstTo') }}</p>
            </div>
            
            <!-- Enable Toggle -->
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.enableAppLock') }}</p>
                <p class="text-sm text-surface-500 dark:text-surface-400">{{ $t('settingsView.lockAfterLeavingTheApp') }}</p>
              </div>
              <button
                @click="toggleAppLock"
                :disabled="!appLockHasPin"
                :class="appLockEnabled ? 'bg-primary-600' : 'bg-surface-300 dark:bg-surface-600'"
                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span
                  :class="appLockEnabled ? 'translate-x-5' : 'translate-x-0'"
                  class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5 ml-0.5"
                />
              </button>
            </div>
            
            <!-- Lock Timeout -->
            <div v-if="appLockEnabled" class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.lockAfter') }}</p>
                <p class="text-sm text-surface-500 dark:text-surface-400">{{ $t('settingsView.howLongBeforeTheApp') }}</p>
              </div>
              <select
                :value="appLockTimeout"
                @change="updateAppLockTimeout"
                class="rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 px-3 py-2 text-sm"
              >
                <option :value="1">{{ $t('settingsView.1Minute') }}</option>
                <option :value="2">{{ $t('settingsView.2Minutes') }}</option>
                <option :value="5">{{ $t('settingsView.5Minutes') }}</option>
                <option :value="10">{{ $t('settingsView.10Minutes') }}</option>
                <option :value="15">{{ $t('settingsView.15Minutes') }}</option>
                <option :value="30">{{ $t('settingsView.30Minutes') }}</option>
                <option :value="60">{{ $t('settingsView.1Hour') }}</option>
              </select>
            </div>
          </div>
        </section>

        <!-- SESSIONS & TRUSTED DEVICES -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">devices</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.sessionsTrustedDevices') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6">
            <SessionsManager />
          </div>
        </section>
        
        <!-- DEVICE MANAGEMENT (Remote Wipe, Blocking) -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">security</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.deviceManagement') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6">
            <DevicesManager />
          </div>
        </section>
      </div>
      
      <!-- Filters Tab -->
      <div v-else-if="activeTab === 'filters'">
        <FilterSettings />
      </div>
      
      <!-- Spam Protection Tab -->
      <div v-else-if="activeTab === 'spam'">
        <SpamSettings />
      </div>
      
      <!-- Notifications Tab -->
      <div v-else-if="activeTab === 'notifications'" class="space-y-6">
        <!-- Desktop Notifications Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">computer</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.desktopNotifications') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-6">
            <!-- Master Toggle -->
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.enableDesktopNotifications') }}</p>
                <p class="text-sm text-surface-500">{{ $t('settingsView.showNotificationsInYourSystem') }}</p>
              </div>
              <button
                @click="toggleBrowserNotifications"
                :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', browserNotificationsEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span 
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', browserNotificationsEnabled ? 'translate-x-6' : 'translate-x-0']"
                ></span>
              </button>
            </div>
            
            <!-- Permission Warning -->
            <div v-if="notificationPermission === 'denied'" class="flex items-start gap-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
              <span class="material-symbols-rounded text-amber-500 mt-0.5">warning</span>
              <div>
                <p class="text-sm font-medium text-amber-700 dark:text-amber-400">{{ $t('settingsView.notificationsBlocked') }}</p>
                <p class="text-xs text-amber-600 dark:text-amber-500">{{ $t('settingsView.yourBrowserHasBlockedNotifications') }}</p>
              </div>
            </div>
            
            <!-- Test Button -->
            <div v-if="browserNotificationsEnabled" class="pt-4 border-t border-surface-200 dark:border-surface-700">
              <button @click="testNotification" class="btn-secondary btn-sm">
                <span class="material-symbols-rounded">notifications_active</span>
                {{ $t('settingsView.sendTestNotification') }}
              </button>
            </div>
          </div>
        </section>

        <!-- New email desktop alerts -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">mail</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.newEmailAlerts') }}</h2>
          </div>

          <div class="card p-4 md:p-6 space-y-4">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.newEmailAlerts') }}</p>
                <p class="text-sm text-surface-500">{{ $t('settingsView.getNotifiedWhenNewEmail') }}</p>
              </div>
              <button
                @click="toggleNewEmailNotifications"
                :disabled="!browserNotificationsEnabled"
                :class="[
                  'w-12 h-6 rounded-full transition-colors relative shrink-0',
                  !browserNotificationsEnabled ? 'opacity-50 cursor-not-allowed' : '',
                  notificationSettings.newEmailEnabled && browserNotificationsEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', notificationSettings.newEmailEnabled && browserNotificationsEnabled ? 'translate-x-6' : 'translate-x-0']"
                ></span>
              </button>
            </div>

            <p v-if="!browserNotificationsEnabled" class="text-xs text-surface-400">
              <span class="material-symbols-rounded text-xs align-middle">info</span>
              {{ $t('settingsView.enableDesktopNotifForEmail') }}
            </p>
          </div>
        </section>

        <!-- New chat message desktop alerts -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">chat</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.chatAlerts') }}</h2>
          </div>

          <div class="card p-4 md:p-6 space-y-4">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.chatAlerts') }}</p>
                <p class="text-sm text-surface-500">{{ $t('settingsView.getNotifiedWhenNewChat') }}</p>
              </div>
              <button
                @click="toggleChatNotifications"
                :disabled="!browserNotificationsEnabled"
                :class="[
                  'w-12 h-6 rounded-full transition-colors relative shrink-0',
                  !browserNotificationsEnabled ? 'opacity-50 cursor-not-allowed' : '',
                  notificationSettings.chatMessagesEnabled && browserNotificationsEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', notificationSettings.chatMessagesEnabled && browserNotificationsEnabled ? 'translate-x-6' : 'translate-x-0']"
                ></span>
              </button>
            </div>

            <p v-if="!browserNotificationsEnabled" class="text-xs text-surface-400">
              <span class="material-symbols-rounded text-xs align-middle">info</span>
              {{ $t('settingsView.enableDesktopNotifForEmail') }}
            </p>
          </div>
        </section>

        <!-- Email Notifications Section (gated by email_tracking addon) -->
        <section v-if="emailTrackingEnabled">
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">mark_email_read</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.emailNotifications') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-4">
            <!-- Read Receipts -->
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.readReceiptAlerts') }}</p>
                <p class="text-sm text-surface-500">{{ $t('settingsView.getNotifiedWhenSomeoneOpens') }}</p>
              </div>
              <button
                @click="toggleReadReceiptNotifications"
                :disabled="!browserNotificationsEnabled"
                :class="[
                  'w-12 h-6 rounded-full transition-colors relative shrink-0',
                  !browserNotificationsEnabled ? 'opacity-50 cursor-not-allowed' : '',
                  notificationSettings.readReceiptsEnabled && browserNotificationsEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span 
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', notificationSettings.readReceiptsEnabled && browserNotificationsEnabled ? 'translate-x-6' : 'translate-x-0']"
                ></span>
              </button>
            </div>
            
            <p v-if="!browserNotificationsEnabled" class="text-xs text-surface-400">
              <span class="material-symbols-rounded text-xs align-middle">info</span>
              {{ $t('settingsView.enableDesktopNotifForEmail') }}
            </p>
          </div>
        </section>
        
        <!-- Calendar Notifications Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">calendar_month</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.calendarReminders') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-6">
            <!-- Calendar Reminders Toggle -->
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.eventReminders') }}</p>
                <p class="text-sm text-surface-500">{{ $t('settingsView.getNotifiedBeforeCalendarEvents') }}</p>
              </div>
              <button
                @click="toggleCalendarReminders"
                :disabled="!browserNotificationsEnabled"
                :class="[
                  'w-12 h-6 rounded-full transition-colors relative shrink-0',
                  !browserNotificationsEnabled ? 'opacity-50 cursor-not-allowed' : '',
                  notificationSettings.calendarRemindersEnabled && browserNotificationsEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span 
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', notificationSettings.calendarRemindersEnabled && browserNotificationsEnabled ? 'translate-x-6' : 'translate-x-0']"
                ></span>
              </button>
            </div>
            
            <!-- Reminder Times -->
            <div v-if="notificationSettings.calendarRemindersEnabled && browserNotificationsEnabled" class="pt-4 border-t border-surface-200 dark:border-surface-700">
              <p class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">{{ $t('settingsView.remindMe') }}</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="minutes in [30, 15, 10, 5, 0]"
                  :key="minutes"
                  @click="toggleReminderTime(minutes)"
                  :class="[
                    'px-4 py-2 rounded-full text-sm font-medium transition-colors',
                    notificationSettings.reminderTimes.includes(minutes)
                      ? 'bg-primary-500 text-white'
                      : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
                  ]"
                >
                  {{ minutes === 0 ? $t('settingsView.atStartTime') : $t('settingsView.minBefore', { minutes }) }}
                </button>
              </div>
            </div>
            
            <p v-if="!browserNotificationsEnabled" class="text-xs text-surface-400">
              <span class="material-symbols-rounded text-xs align-middle">info</span>
              {{ $t('settingsView.enableDesktopNotifForCalendar') }}
            </p>
          </div>
        </section>
        
        <!-- Sound Settings Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">volume_up</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.sound') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.notificationSounds') }}</p>
                <p class="text-sm text-surface-500">{{ $t('settingsView.playASoundWhenNotifications') }}</p>
              </div>
              <button
                @click="toggleSoundNotifications"
                :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', notificationSettings.soundEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span 
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', notificationSettings.soundEnabled ? 'translate-x-6' : 'translate-x-0']"
                ></span>
              </button>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-4">
              <button
                type="button"
                @click="previewEmailSound"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm text-surface-700 dark:text-surface-300 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
              >
                <span class="material-symbols-rounded text-base">mail</span>
                {{ $t('settingsView.previewEmailSound') }}
              </button>
              <button
                type="button"
                @click="previewChatSound"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm text-surface-700 dark:text-surface-300 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
              >
                <span class="material-symbols-rounded text-base">chat_bubble</span>
                {{ $t('settingsView.previewChatSound') }}
              </button>
            </div>
          </div>
        </section>
        
        <!-- Push Notifications Section (PWA - offline notifications) -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">phone_android</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.pushNotifications') }}</h2>
            <span class="px-2 py-0.5 text-[10px] font-bold bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 rounded-full uppercase tracking-wide">PWA</span>
          </div>
          
          <div class="card p-4 md:p-6 space-y-4">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.enablePushNotifications') }}</p>
                <p class="text-sm text-surface-500">{{ $t('settingsView.receiveNotificationsOnYourPhonedesktop') }}</p>
              </div>
              <button
                v-if="pushStatus !== 'unsupported'"
                @click="togglePushNotifications"
                :disabled="pushLoading || pushStatus === 'denied'"
                :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', pushStatus === 'subscribed' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span 
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', pushStatus === 'subscribed' ? 'translate-x-6' : 'translate-x-0']"
                ></span>
              </button>
              <span v-else class="text-xs text-surface-400 italic">{{ $t('settingsView.notSupported') }}</span>
            </div>
            
            <!-- Status info -->
            <div v-if="pushStatus === 'subscribed'" class="flex items-center gap-2 text-sm text-green-600 dark:text-green-400">
              <span class="material-symbols-rounded text-base">check_circle</span>
              <span>{{ $t('settingsView.pushNotificationsAreActiveYoull') }}</span>
            </div>
            
            <div v-else-if="pushStatus === 'denied'" class="flex items-center gap-2 text-sm text-red-500">
              <span class="material-symbols-rounded text-base">block</span>
              <span>{{ $t('settingsView.permissionDeniedGoToYour') }}</span>
            </div>
            
            <div v-else-if="pushStatus === 'unsupported'" class="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400">
              <span class="material-symbols-rounded text-base">warning</span>
              <span v-if="pushNotifications.isIOS && !pushNotifications.isStandalone">
                {{ $t('settingsView.iosHomescreenInstruction') }}
              </span>
              <span v-else>{{ $t('settingsView.pushNotificationsAreNotSupported') }}</span>
            </div>
            
            <div v-else class="text-sm text-surface-500">
              <p>{{ $t('settingsView.clickTheToggleAboveTo') }}</p>
            </div>
            
            <!-- Per-type notification preferences (apply to all your devices) -->
            <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
              <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3">
                {{ $t('settingsView.notificationTypes') }}
              </p>
              <div class="space-y-3">
                <div
                  v-for="pref in [
                    { key: 'email', icon: 'mail', label: $t('settingsView.prefEmail') },
                    { key: 'chat', icon: 'chat', label: $t('settingsView.prefChat') },
                    { key: 'calls', icon: 'call', label: $t('settingsView.prefCalls') },
                    { key: 'calendar', icon: 'event', label: $t('settingsView.prefCalendar') },
                    { key: 'boards', icon: 'dashboard', label: $t('settingsView.prefBoards') },
                  ]"
                  :key="pref.key"
                  class="flex items-center justify-between gap-4"
                >
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-surface-400 text-lg">{{ pref.icon }}</span>
                    <span class="text-sm text-surface-700 dark:text-surface-300">{{ pref.label }}</span>
                  </div>
                  <button
                    @click="togglePushPref(pref.key)"
                    :disabled="pushPrefsSaving"
                    :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', pushPrefs[pref.key] ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                  >
                    <span
                      :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', pushPrefs[pref.key] ? 'translate-x-6' : 'translate-x-0']"
                    ></span>
                  </button>
                </div>
              </div>
            </div>

            <!-- iOS install guide -->
            <div v-if="pushNotifications.isIOS" class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700/50 rounded-lg">
              <div class="flex items-start gap-2 text-sm text-blue-700 dark:text-blue-300">
                <span class="material-symbols-rounded text-base mt-0.5 flex-shrink-0">smartphone</span>
                <div>
                  <p class="font-medium mb-1">{{ $t('settingsView.iphoneIpad') }}</p>
                  <p class="text-blue-600 dark:text-blue-400 text-xs">
                    {{ $t('settingsView.iosInstallInstructions') }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </section>
        
        <!-- Info Section -->
        <div class="p-4 bg-surface-100 dark:bg-surface-800 rounded-xl">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-surface-400 mt-0.5">info</span>
            <div class="text-sm text-surface-500">
              <p class="font-medium text-surface-600 dark:text-surface-400 mb-1">{{ $t('settingsView.aboutNotifications') }}</p>
              <p class="mb-1"><strong>{{ $t('settingsView.desktopNotifications') }}</strong> {{ $t('settingsView.appearWhenTheAppIs') }}</p>
              <p><strong>{{ $t('settingsView.pushNotifications') }}</strong> {{ $t('settingsView.pwaWorkEvenWhenThe') }}</p>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Email Templates Tab -->
      <div v-else-if="activeTab === 'email-templates'">
        <EmailTemplateManager />
      </div>

      <div v-else-if="activeTab === 'news_reader'">
        <NewsReaderSettings />
      </div>
      
      <!-- AI Assistant Tab -->
      <div v-else-if="activeTab === 'ai'" class="space-y-8">
        <!-- Privacy Warning Banner -->
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-600/50 rounded-xl p-4">
          <div class="flex gap-3">
            <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-xl flex-shrink-0 mt-0.5">privacy_tip</span>
            <div>
              <h3 class="font-semibold text-amber-800 dark:text-amber-300">{{ $t('settingsView.privacyNotice') }}</h3>
              <p class="text-sm text-amber-700 dark:text-amber-400 mt-1">
                {{ $t('settingsView.aiPrivacyWarning', { provider: 'OpenAI', link: '' }) }}
                <a href="https://openai.com/policies/privacy-policy" target="_blank" class="underline hover:text-amber-900 dark:hover:text-amber-200">{{ $t('settingsView.privacyPolicy') }}</a>.
              </p>
              <p class="text-xs text-amber-600 dark:text-amber-500 mt-2 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">info</span>
                {{ $t('settingsView.onlyContentYouChoose') }}
              </p>
            </div>
          </div>
        </div>
        
        <!-- API Configuration Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">key</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.apiConfiguration') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-6">
            <!-- Status indicator -->
            <div :class="[
              'flex items-center gap-3 p-4 rounded-xl',
              aiConfigured 
                ? 'bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30' 
                : 'bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30'
            ]">
              <span :class="[
                'material-symbols-rounded text-2xl',
                aiConfigured ? 'text-green-500' : 'text-amber-500'
              ]">
                {{ aiConfigured ? 'check_circle' : 'warning' }}
              </span>
              <div>
                <p :class="['font-medium', aiConfigured ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400']">
                  {{ aiConfigured ? $t('settingsView.aiAssistantConfigured') : $t('settingsView.apiKeyRequired') }}
                </p>
                <p class="text-sm text-surface-500">
                  {{ aiConfigured ? $t('settingsView.apiKeySecurelyStored') : $t('settingsView.addOpenaiApiKey') }}
                </p>
              </div>
            </div>
            
            <!-- API Key Input -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ $t('settingsView.openaiApiKey') }}
              </label>
              <div class="flex flex-col sm:flex-row gap-2">
                <div class="flex-1 relative">
                  <input
                    v-model="aiSettings.api_key"
                    :type="showApiKey ? 'text' : 'password'"
                    class="input pr-10 font-mono text-sm"
                    placeholder="sk-..."
                  />
                  <button
                    @click="showApiKey = !showApiKey"
                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-surface-400 hover:text-surface-600 hover:bg-surface-100 dark:hover:bg-surface-700"
                    :title="$t('settingsView.toggleVisibility')"
                  >
                    <span class="material-symbols-rounded text-lg">
                      {{ showApiKey ? 'visibility_off' : 'visibility' }}
                    </span>
                  </button>
                </div>
                <button
                  v-if="aiConfigured"
                  @click="clearApiKey"
                  class="btn-danger"
                  :title="$t('settingsView.removeApiKey')"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
              <p class="mt-2 text-xs text-surface-500">
                {{ $t('settingsView.getApiKeyFrom') }} <a href="https://platform.openai.com/api-keys" target="_blank" class="text-primary-500 hover:underline">{{ $t('settingsView.openaiPlatform') }}</a>
              </p>
            </div>
            
            <!-- Model Selection -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ $t('settingsView.aiModel') }}
              </label>
              <select v-model="aiSettings.model" class="input">
                <option v-for="(info, id) in aiModels" :key="id" :value="id">
                  {{ info.name }} - {{ info.description }}
                </option>
              </select>
              <p class="mt-1 text-xs text-surface-500">
                {{ $t('settingsView.aiModelDescription') }}
              </p>
            </div>
            
            <!-- Writing Style -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ $t('settingsView.defaultWritingStyle') }}
              </label>
              <select v-model="aiSettings.writing_style" class="input">
                <option v-for="(label, id) in aiStyles" :key="id" :value="id">
                  {{ label }}
                </option>
              </select>
              <p class="mt-1 text-xs text-surface-500">
                {{ $t('settingsView.usedForRewritingAndDraft') }}
              </p>
            </div>
            
            <!-- Temperature -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Temperature: {{ aiSettings.temperature.toFixed(1) }}
              </label>
              <div class="flex items-center gap-4">
                <input
                  type="range"
                  v-model.number="aiSettings.temperature"
                  min="0"
                  max="2"
                  step="0.1"
                  class="flex-1 h-2 bg-surface-200 dark:bg-surface-700 rounded-lg cursor-pointer accent-slider"
                />
                <span class="text-sm text-surface-500 w-8">{{ aiSettings.temperature.toFixed(1) }}</span>
              </div>
              <p class="mt-1 text-xs" :class="aiSettings.model.startsWith('gpt-5') ? 'text-amber-500' : 'text-surface-500'">
                <template v-if="aiSettings.model.startsWith('gpt-5')">
                  {{ $t('settingsView.gpt5IgnoresTemperature') }}
                </template>
                <template v-else>
                  {{ $t('settingsView.temperatureDescription') }}
                </template>
              </p>
            </div>
          </div>
        </section>
        
        <!-- Custom Prompts Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">edit_note</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.customPrompts') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-6">
            <p class="text-sm text-surface-500">
              {{ $t('settingsView.customizePromptsDescription') }}
              Available variables: <code class="px-1.5 py-0.5 rounded bg-surface-100 dark:bg-surface-700 text-xs" v-pre>{{email_content}}</code>, 
              <code class="px-1.5 py-0.5 rounded bg-surface-100 dark:bg-surface-700 text-xs" v-pre>{{text}}</code>, 
              <code class="px-1.5 py-0.5 rounded bg-surface-100 dark:bg-surface-700 text-xs" v-pre>{{style}}</code>
            </p>
            
            <!-- Summarize Prompt -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-medium text-surface-700 dark:text-surface-300">
                  {{ $t('settingsView.summarizePrompt') }}
                </label>
                <div class="flex items-center gap-2">
                  <button
                    @click="copyPrompt('summarize')"
                    class="text-xs text-surface-500 hover:text-primary-500 flex items-center gap-1"
                    :title="$t('settingsView.copyPromptToClipboard')"
                  >
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                    {{ $t('settingsView.copyLabel') }}
                  </button>
                  <button
                    v-if="aiSettings.prompt_summarize"
                    @click="resetPrompt('summarize')"
                    class="text-xs text-primary-500 hover:underline"
                  >
                    {{ $t('settingsView.resetToDefault') }}
                  </button>
                </div>
              </div>
              <textarea
                v-model="aiSettings.prompt_summarize"
                class="input min-h-[120px] font-mono text-sm"
                :placeholder="aiDefaultPrompts.summarize || 'Loading default prompt...'"
              ></textarea>
            </div>
            
            <!-- Rewrite Prompt -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-medium text-surface-700 dark:text-surface-300">
                  {{ $t('settingsView.rewritePrompt') }}
                </label>
                <div class="flex items-center gap-2">
                  <button
                    @click="copyPrompt('rewrite')"
                    class="text-xs text-surface-500 hover:text-primary-500 flex items-center gap-1"
                    :title="$t('settingsView.copyPromptToClipboard')"
                  >
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                    {{ $t('settingsView.copyLabel') }}
                  </button>
                  <button
                    v-if="aiSettings.prompt_rewrite"
                    @click="resetPrompt('rewrite')"
                    class="text-xs text-primary-500 hover:underline"
                  >
                    {{ $t('settingsView.resetToDefault') }}
                  </button>
                </div>
              </div>
              <textarea
                v-model="aiSettings.prompt_rewrite"
                class="input min-h-[100px] font-mono text-sm"
                :placeholder="aiDefaultPrompts.rewrite || 'Loading default prompt...'"
              ></textarea>
            </div>
            
            <!-- Draft Reply Prompt -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-medium text-surface-700 dark:text-surface-300">
                  {{ $t('settingsView.draftReplyPrompt') }}
                </label>
                <div class="flex items-center gap-2">
                  <button
                    @click="copyPrompt('draft_reply')"
                    class="text-xs text-surface-500 hover:text-primary-500 flex items-center gap-1"
                    :title="$t('settingsView.copyPromptToClipboard')"
                  >
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                    {{ $t('settingsView.copyLabel') }}
                  </button>
                  <button
                    v-if="aiSettings.prompt_draft_reply"
                    @click="resetPrompt('draft_reply')"
                    class="text-xs text-primary-500 hover:underline"
                  >
                    {{ $t('settingsView.resetToDefault') }}
                  </button>
                </div>
              </div>
              <textarea
                v-model="aiSettings.prompt_draft_reply"
                class="input min-h-[100px] font-mono text-sm"
                :placeholder="aiDefaultPrompts.draft_reply || 'Loading default prompt...'"
              ></textarea>
            </div>
          </div>
        </section>
        
        <!-- Info Section -->
        <div class="p-4 bg-surface-100 dark:bg-surface-800 rounded-xl">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-surface-400 mt-0.5">info</span>
            <div class="text-sm text-surface-500">
              <p class="font-medium text-surface-600 dark:text-surface-400 mb-1">{{ $t('settingsView.aboutAiFeatures') }}</p>
              <p>{{ $t('settingsView.aiFeaturesUseOpenaisGpt') }}</p>
            </div>
          </div>
        </div>
        
        <!-- Save Button -->
        <div class="flex justify-center pb-4 md:sticky md:bottom-4">
          <button 
            @click="saveAISettings" 
            class="btn-primary shadow-lg px-8" 
            :disabled="savingAI"
          >
            <span v-if="savingAI" class="spinner"></span>
            <span class="material-symbols-rounded">save</span>
            {{ $t('settingsView.saveAiSettings') }}
          </button>
        </div>
      </div>
      
      <!-- Integrations Tab -->
      <div v-else-if="activeTab === 'integrations'">
        <IntegrationsSettings :initial-sub-tab="$route.query.subtab || ''" />
      </div>

      <!-- Billing Integration Tab -->
      <div v-else-if="activeTab === 'billing'">
        <BillingSettings />
      </div>

      <!-- Statistics Tab -->
      <div v-else-if="activeTab === 'statistics'">
        <StatisticsTab />
      </div>

      
      <!-- System Tab -->
      <div v-else-if="activeTab === 'system'" class="space-y-6">
        <!-- Data Folder Permissions Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">folder</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.dataFolderPermissions') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6 space-y-4">
            <p class="text-sm text-surface-500">
              {{ $t('settingsView.dataFolderDescription') }}
            </p>
            
            <!-- Check Button -->
            <button 
              @click="checkPermissions" 
              :disabled="checkingPermissions"
              class="btn-secondary"
            >
              <span v-if="checkingPermissions" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded">policy</span>
              {{ checkingPermissions ? $t('settingsView.checking') : $t('settingsView.checkPermissions') }}
            </button>
            
            <!-- Results -->
            <div v-if="permissionsCheck" class="mt-4 space-y-4">
              <!-- Overall Status -->
              <div :class="[
                'flex items-center gap-3 p-4 rounded-xl',
                permissionsCheck.all_correct 
                  ? 'bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30' 
                  : 'bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30'
              ]">
                <span :class="[
                  'material-symbols-rounded text-2xl',
                  permissionsCheck.all_correct ? 'text-green-500' : 'text-red-500'
                ]">
                  {{ permissionsCheck.all_correct ? 'check_circle' : 'error' }}
                </span>
                <div>
                  <p :class="['font-medium', permissionsCheck.all_correct ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400']">
                    {{ permissionsCheck.all_correct ? $t('settingsView.allPermissionsCorrect') : $t('settingsView.permissionsNeedAttention') }}
                  </p>
                  <p class="text-sm text-surface-500">{{ permissionsCheck.path }}</p>
                </div>
              </div>
              
              <!-- Details Table -->
              <div class="bg-surface-50 dark:bg-surface-900 rounded-xl overflow-x-auto">
                <table class="w-full text-sm min-w-[400px]">
                  <thead>
                    <tr class="border-b border-surface-200 dark:border-surface-700">
                      <th class="text-left px-4 py-2 text-surface-500">{{ $t('settingsView.property') }}</th>
                      <th class="text-left px-4 py-2 text-surface-500">{{ $t('settingsView.current') }}</th>
                      <th class="text-left px-4 py-2 text-surface-500">{{ $t('settingsView.expected') }}</th>
                      <th class="text-center px-4 py-2 text-surface-500">{{ $t('settingsView.status') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr class="border-b border-surface-200 dark:border-surface-700">
                      <td class="px-4 py-2 text-surface-700 dark:text-surface-300">{{ $t('settingsView.owner') }}</td>
                      <td class="px-4 py-2 font-mono text-surface-600 dark:text-surface-400">{{ permissionsCheck.owner }}</td>
                      <td class="px-4 py-2 font-mono text-surface-600 dark:text-surface-400">{{ permissionsCheck.expected.owner }}</td>
                      <td class="px-4 py-2 text-center">
                        <span :class="['material-symbols-rounded', permissionsCheck.owner_correct ? 'text-green-500' : 'text-red-500']">
                          {{ permissionsCheck.owner_correct ? 'check' : 'close' }}
                        </span>
                      </td>
                    </tr>
                    <tr class="border-b border-surface-200 dark:border-surface-700">
                      <td class="px-4 py-2 text-surface-700 dark:text-surface-300">{{ $t('settingsView.group') }}</td>
                      <td class="px-4 py-2 font-mono text-surface-600 dark:text-surface-400">{{ permissionsCheck.group }}</td>
                      <td class="px-4 py-2 font-mono text-surface-600 dark:text-surface-400">{{ permissionsCheck.expected.group }}</td>
                      <td class="px-4 py-2 text-center">
                        <span :class="['material-symbols-rounded', permissionsCheck.group_correct ? 'text-green-500' : 'text-red-500']">
                          {{ permissionsCheck.group_correct ? 'check' : 'close' }}
                        </span>
                      </td>
                    </tr>
                    <tr>
                      <td class="px-4 py-2 text-surface-700 dark:text-surface-300">{{ $t('settingsView.permissions') }}</td>
                      <td class="px-4 py-2 font-mono text-surface-600 dark:text-surface-400">{{ permissionsCheck.permissions }}</td>
                      <td class="px-4 py-2 font-mono text-surface-600 dark:text-surface-400">{{ permissionsCheck.expected.permissions }}</td>
                      <td class="px-4 py-2 text-center">
                        <span :class="['material-symbols-rounded', permissionsCheck.permissions_correct ? 'text-green-500' : 'text-red-500']">
                          {{ permissionsCheck.permissions_correct ? 'check' : 'close' }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              
              <!-- Subdirectories -->
              <div v-if="permissionsCheck.subdirectories?.length" class="mt-4">
                <p class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">{{ $t('settingsView.subdirectories') }}</p>
                <div class="flex flex-wrap gap-2">
                  <span 
                    v-for="subdir in permissionsCheck.subdirectories" 
                    :key="subdir.path"
                    :class="[
                      'inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs',
                      subdir.exists && subdir.correct 
                        ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' 
                        : subdir.exists 
                          ? 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400'
                          : 'bg-surface-200 dark:bg-surface-700 text-surface-500'
                    ]"
                  >
                    <span class="material-symbols-rounded text-sm">
                      {{ subdir.exists && subdir.correct ? 'check' : subdir.exists ? 'warning' : 'folder_off' }}
                    </span>
                    {{ subdir.path }}
                  </span>
                </div>
              </div>
              
              <!-- Fix Button -->
              <div v-if="!permissionsCheck.all_correct" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                <button 
                  @click="fixPermissions" 
                  :disabled="fixingPermissions"
                  class="btn-primary"
                >
                  <span v-if="fixingPermissions" class="spinner w-4 h-4"></span>
                  <span v-else class="material-symbols-rounded">build</span>
                  {{ fixingPermissions ? $t('settingsView.fixing') : $t('settingsView.fixPermissionsBtn') }}
                </button>
                
                <div class="mt-3 p-3 bg-amber-50 dark:bg-amber-500/10 rounded-lg border border-amber-200 dark:border-amber-500/30">
                  <p class="text-sm text-amber-700 dark:text-amber-400 font-medium mb-2">
                    <span class="material-symbols-rounded text-sm align-middle mr-1">terminal</span>
                    {{ $t('settingsView.ifAutoFixDoesntWork') }}
                  </p>
                  <pre class="text-xs bg-surface-900 dark:bg-black text-green-400 p-3 rounded-lg overflow-x-auto">sudo chown -R nobody:nogroup /var/www/vps-email/data
sudo chmod -R 755 /var/www/vps-email/data</pre>
                </div>
              </div>
            </div>
          </div>
        </section>
        
        <!-- System Info Section -->
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">info</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.systemInformation') }}</h2>
          </div>
          
          <div class="card p-4 md:p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
              <div>
                <p class="text-surface-500">{{ $t('settingsView.application') }}</p>
                <p class="font-medium text-surface-700 dark:text-surface-300">{{ $t('settingsView.webmailClient') }}</p>
              </div>
              <div>
                <p class="text-surface-500">{{ $t('settingsView.dataDirectory') }}</p>
                <p class="font-mono text-surface-700 dark:text-surface-300 break-all">/var/www/vps-email/data</p>
              </div>
              <div>
                <p class="text-surface-500">{{ $t('settingsView.expectedOwner') }}</p>
                <p class="font-mono text-surface-700 dark:text-surface-300">nobody:nogroup</p>
              </div>
              <div>
                <p class="text-surface-500">{{ $t('settingsView.expectedPermissions') }}</p>
                <p class="font-mono text-surface-700 dark:text-surface-300">755</p>
              </div>
            </div>
          </div>
        </section>
      </div>
      
      <!-- Drive Storage Tab (Read-only - config from Panel) -->
      <div v-else-if="activeTab === 'desktop-app'" class="space-y-6">
        <section>
          <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-primary-500">computer</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Desktop App Settings</h2>
          </div>
          <div class="card p-4 md:p-6 space-y-5">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">Launch at startup</p>
                <p class="text-sm text-surface-500">Start automatically when you log in to Windows</p>
              </div>
              <button
                @click="toggleDesktopLaunchAtStartup"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  desktopLaunchAtStartup ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    desktopLaunchAtStartup ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>

            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-surface-700 dark:text-surface-300">Database Sync Debug</p>
                <p class="text-sm text-surface-500">Show the database debug panel for inspecting local sync data</p>
              </div>
              <button
                @click="toggleDesktopDbSyncDebug"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  desktopDbSyncDebug ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    desktopDbSyncDebug ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
          </div>
        </section>
      </div>

      <div v-else-if="activeTab === 'drive-storage'" class="space-y-6">
        <!-- Loading State -->
        <div v-if="loadingStorage" class="flex items-center justify-center py-12">
          <span class="spinner text-primary-500"></span>
        </div>
        
        <template v-else>
          <!-- Drive Storage Status -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">hard_drive</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.driveStorage') }}</h2>
            </div>
            
            <div class="card p-4 md:p-6 space-y-6">
              <!-- Current Storage Type -->
              <div class="flex items-center gap-4 p-4 rounded-xl" :class="storageConfig.driver === 'nfs' ? 'bg-cyan-500/10' : 'bg-green-500/10'">
                <div class="w-10 h-10 sm:w-14 sm:h-14 rounded-xl flex items-center justify-center flex-shrink-0" :class="storageConfig.driver === 'nfs' ? 'bg-cyan-500/20' : 'bg-green-500/20'">
                  <span class="material-symbols-rounded text-2xl sm:text-3xl" :class="storageConfig.driver === 'nfs' ? 'text-cyan-400' : 'text-green-400'">
                    {{ storageConfig.driver === 'nfs' ? 'cloud_sync' : 'dns' }}
                  </span>
                </div>
                <div class="flex-1 min-w-0">
                  <h3 class="text-base sm:text-lg font-semibold truncate" :class="storageConfig.driver === 'nfs' ? 'text-cyan-300' : 'text-green-300'">
                    {{ storageConfig.storage_name || (storageConfig.driver === 'nfs' ? $t('settingsView.nasStorage') : $t('settingsView.localStorage')) }}
                  </h3>
                  <p class="font-mono text-xs sm:text-sm truncate" :class="storageConfig.driver === 'nfs' ? 'text-cyan-400/70' : 'text-green-400/70'">
                    {{ storageConfig.path || storageStats?.path }}
                  </p>
                  <p v-if="storageConfig.is_from_panel" class="text-xs text-surface-500 mt-1 flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">verified</span>
                    {{ $t('settingsView.configuredViaPanel') }}
                  </p>
                </div>
              </div>
              
              <!-- Disk Usage -->
              <div v-if="storageStats?.available" class="space-y-3">
                <div class="flex justify-between text-sm">
                  <span class="text-surface-500">{{ $t('settingsView.diskUsage') }}</span>
                  <span class="font-medium text-surface-300">
                    {{ storageStats.used_formatted }} / {{ storageStats.total_formatted }}
                  </span>
                </div>
                <div class="h-3 bg-surface-700 rounded-full overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all"
                    :class="storageStats.percent_used > 90 ? 'bg-red-500' : storageStats.percent_used > 70 ? 'bg-amber-500' : 'bg-primary-500'"
                    :style="{ width: storageStats.percent_used + '%' }"
                  ></div>
                </div>
                <div class="flex justify-between text-xs text-surface-500">
                  <span>{{ storageStats.percent_used }}% used</span>
                  <span>{{ storageStats.free_formatted }} free</span>
                </div>
              </div>
              
              <!-- Storage not available -->
              <div v-else class="text-center py-4 text-surface-500">
                <span class="material-symbols-rounded text-3xl mb-2 block">cloud_off</span>
                <p>{{ $t('settingsView.storageStatisticsNotAvailable') }}</p>
              </div>
            </div>
          </section>
          
          <!-- Drive Statistics -->
          <section v-if="storageStats?.database">
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">analytics</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.driveStatistics') }}</h2>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
              <div class="card p-4 text-center">
                <div class="text-2xl sm:text-3xl font-bold text-surface-100">{{ storageStats.database.total_files?.toLocaleString() || 0 }}</div>
                <div class="text-sm text-surface-500 mt-1">{{ $t('settingsView.files') }}</div>
              </div>
              <div class="card p-4 text-center">
                <div class="text-2xl sm:text-3xl font-bold text-surface-100">{{ storageStats.database.total_folders?.toLocaleString() || 0 }}</div>
                <div class="text-sm text-surface-500 mt-1">{{ $t('settingsView.folders') }}</div>
              </div>
              <div class="card p-4 text-center">
                <div class="text-2xl sm:text-3xl font-bold text-surface-100">{{ storageStats.database.total_size_formatted || '0 B' }}</div>
                <div class="text-sm text-surface-500 mt-1">{{ $t('settingsView.totalSize') }}</div>
              </div>
            </div>
          </section>
          
          <!-- Info Note -->
          <section>
            <div class="flex items-start gap-3 p-4 rounded-xl bg-surface-800/50 border border-surface-700">
              <span class="material-symbols-rounded text-surface-500">info</span>
              <p class="text-sm text-surface-400">
                {{ $t('settingsView.storageConfigManagedByAdmin') }}
              </p>
            </div>
          </section>
        </template>
      </div>
      
      <!-- Cache & Storage Tab -->
      <div v-else-if="activeTab === 'cache'" class="space-y-6">
        <!-- Overview Section -->
        <section>
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">storage</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.cacheStorage') }}</h2>
            </div>
            <button 
              @click="loadCacheStats" 
              :disabled="loadingCacheStats"
              class="btn-secondary btn-sm"
            >
              <span v-if="loadingCacheStats" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded">refresh</span>
              {{ $t('settingsView.refresh') }}
            </button>
          </div>
          
          <p class="text-sm text-surface-500 mb-6">
            {{ $t('settingsView.cacheDescription') }}
          </p>
          
          <!-- Cache Enable/Disable Toggle -->
          <div class="card p-4 mb-6">
            <div class="flex items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-lg" :class="mailboxStore.listCacheEnabled ? 'text-green-500' : 'text-surface-400'">
                  {{ mailboxStore.listCacheEnabled ? 'check_circle' : 'cancel' }}
                </span>
                <div>
                  <div class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.messageCache') }}</div>
                  <div class="text-xs text-surface-500">
                    {{ mailboxStore.listCacheEnabled ? $t('settingsView.cacheEnabledDesc') : $t('settingsView.cacheDisabledDesc') }}
                  </div>
                </div>
              </div>
              <button 
                @click="toggleCacheEnabled"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                :class="mailboxStore.listCacheEnabled ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600'"
              >
                <span 
                  class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow"
                  :class="mailboxStore.listCacheEnabled ? 'translate-x-6' : 'translate-x-1'"
                ></span>
              </button>
            </div>
            <div v-if="!mailboxStore.listCacheEnabled" class="mt-3 p-3 bg-amber-50 dark:bg-amber-500/10 rounded-lg text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
              <span class="material-symbols-rounded text-lg flex-shrink-0">info</span>
              <span>{{ $t('settingsView.cacheIsDisabledForDebugging') }}</span>
            </div>
          </div>
          
          <!-- Loading State -->
          <div v-if="loadingCacheStats && !cacheStats" class="flex items-center justify-center py-12">
            <span class="spinner text-primary-500"></span>
          </div>
          
          <div v-else-if="cacheStats" class="space-y-6">
            <!-- Redis Server Cache (New) -->
            <div class="card p-5 space-y-4" :class="cacheStats.redis?.available ? 'border-[#4ade80]/30 dark:border-[#4ade80]/20' : 'border-surface-200 dark:border-surface-700'">
              <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-lg bg-[#4ade80]/10 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[#4ade80] text-xl">dns</span>
                  </div>
                  <div>
                    <div class="font-semibold text-surface-900 dark:text-surface-100">{{ $t('settingsView.redisServerCache') }}</div>
                    <div class="text-xs text-surface-500">{{ $t('settingsView.serversideHighperformanceCache') }}</div>
                  </div>
                </div>
                <span 
                  class="px-2.5 py-1 rounded-full text-xs font-medium"
                  :class="cacheStats.redis?.available ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' : 'bg-surface-100 dark:bg-surface-700 text-surface-500'"
                >
                  {{ cacheStats.redis?.available ? $t('settingsView.connected') : $t('settingsView.offlineLabel') }}
                </span>
              </div>
              
              <div v-if="cacheStats.redis?.available" class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-2">
                <div class="text-center p-3 bg-surface-50 dark:bg-surface-800 rounded-lg">
                  <div class="text-2xl font-bold text-[#4ade80]">{{ cacheStats.redis?.user?.messages || 0 }}</div>
                  <div class="text-xs text-surface-500 mt-1">{{ $t('settingsView.messages') }}</div>
                </div>
                <div class="text-center p-3 bg-surface-50 dark:bg-surface-800 rounded-lg">
                  <div class="text-2xl font-bold text-[#4ade80]">{{ cacheStats.redis?.user?.conversations || 0 }}</div>
                  <div class="text-xs text-surface-500 mt-1">{{ $t('settingsView.conversations') }}</div>
                </div>
                <div class="text-center p-3 bg-surface-50 dark:bg-surface-800 rounded-lg">
                  <div class="text-2xl font-bold text-[#4ade80]">{{ cacheStats.redis?.user?.folders || 0 }}</div>
                  <div class="text-xs text-surface-500 mt-1">{{ $t('settingsView.folderStatus') }}</div>
                </div>
                <div class="text-center p-3 bg-surface-50 dark:bg-surface-800 rounded-lg">
                  <div class="text-2xl font-bold text-[#4ade80]">{{ cacheStats.redis?.user?.thumbnails || 0 }}</div>
                  <div class="text-xs text-surface-500 mt-1">{{ $t('settingsView.thumbnails') }}</div>
                </div>
              </div>
              
              
              <div v-if="cacheStats.redis?.available" class="flex flex-wrap items-center gap-4 pt-2 text-xs text-surface-500 border-t border-surface-200 dark:border-surface-700">
                <span>Version: {{ cacheStats.redis?.server?.version || 'N/A' }}</span>
                <span>Memory: {{ cacheStats.redis?.server?.used_memory || 'N/A' }}</span>
                <span>TTL: Messages {{ formatTtl(cacheStats.redis?.ttl?.message) }}, Folders {{ formatTtl(cacheStats.redis?.ttl?.folder_status) }}</span>
              </div>
              
              <div v-if="cacheStats.redis?.available && cacheStats.redis?.user?.total_keys > 0" class="pt-2">
                <button 
                  @click="clearRedisCache('all')"
                  :disabled="clearingCache"
                  class="btn-secondary btn-sm"
                >
                  <span class="material-symbols-rounded text-sm">delete</span>
                  {{ $t('settingsView.clearRedisCacheBtn', { keys: cacheStats.redis?.user?.total_keys }) }}
                </button>
              </div>
              
              <div v-if="!cacheStats.redis?.available" class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-lg text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
                <span class="material-symbols-rounded text-lg flex-shrink-0">info</span>
                <span>{{ $t('settingsView.redisServerIsNotAvailable') }}</span>
              </div>
            </div>
            
            <!-- Cache Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <!-- AI Summary Cache Card -->
              <div class="card p-5 space-y-3">
                <div class="flex items-center justify-between gap-4">
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-purple-500">auto_awesome</span>
                    <span class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.aiSummaries') }}</span>
                  </div>
                  <span 
                    class="px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="cacheStats.localStorage.aiSummaries > 0 ? 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-400' : 'bg-surface-100 dark:bg-surface-700 text-surface-500'"
                  >
                    {{ cacheStats.localStorage.aiSummaries > 0 ? $t('settingsView.activeLabel') : $t('settingsView.emptyLabel') }}
                  </span>
                </div>
                <div class="space-y-1 text-sm">
                  <div class="flex justify-between">
                    <span class="text-surface-500">{{ $t('settingsView.cachedSummaries') }}</span>
                    <span class="font-mono text-surface-700 dark:text-surface-300">{{ cacheStats.localStorage.aiSummaries }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-surface-500">{{ $t('settingsView.expiresAfter') }}</span>
                    <span class="font-mono text-surface-700 dark:text-surface-300">24h</span>
                  </div>
                </div>
                <button 
                  @click="confirmClearCache('ai', $t('settingsView.clearAiSummariesTitle'), $t('settingsView.clearAiSummariesMessage'))"
                  :disabled="clearingCache || cacheStats.localStorage.aiSummaries === 0"
                  class="w-full btn-secondary btn-sm justify-center"
                >
                  <span class="material-symbols-rounded text-sm">delete</span>
                  {{ $t('settingsView.clearSummaries') }}
                </button>
              </div>
              
              <!-- Local Storage Card -->
              <div class="card p-5 space-y-3">
                <div class="flex items-center justify-between gap-4">
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-amber-500">database</span>
                    <span class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.localStorage') }}</span>
                  </div>
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400">
                    {{ $t('settingsView.inUse') }}
                  </span>
                </div>
                <div class="space-y-1 text-sm">
                  <div class="flex justify-between">
                    <span class="text-surface-500">{{ $t('settingsView.totalItems') }}</span>
                    <span class="font-mono text-surface-700 dark:text-surface-300">{{ cacheStats.localStorage.totalItems }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-surface-500">{{ $t('settingsView.estimatedSize') }}</span>
                    <span class="font-mono text-surface-700 dark:text-surface-300">{{ formatBytes(cacheStats.localStorage.sizeBytes) }}</span>
                  </div>
                </div>
                <div class="text-xs text-surface-400 pt-2 border-t border-surface-200 dark:border-surface-700">
                  {{ $t('settingsView.includesSettingsAndPrefs') }}
                </div>
              </div>
            </div>
            
            <!-- Clear All Button -->
            <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
              <div class="flex items-start gap-4">
                <div class="flex-1">
                  <h3 class="font-medium text-surface-900 dark:text-surface-100">{{ $t('settingsView.clearAllCaches') }}</h3>
                  <p class="text-sm text-surface-500 mt-1">
                    {{ $t('settingsView.clearAllCachesDescription') }}
                  </p>
                </div>
                <button 
                  @click="confirmClearCache('all', $t('settingsView.clearAllCaches'), $t('settingsView.clearAllCachesConfirmMessage'))"
                  :disabled="clearingCache"
                  class="btn-danger"
                >
                  <span v-if="clearingCache" class="spinner w-4 h-4"></span>
                  <span v-else class="material-symbols-rounded">delete_forever</span>
                  {{ clearingCache ? $t('settingsView.clearing') : $t('settingsView.clearAllBtn') }}
                </button>
              </div>
            </div>
          </div>
          
          <!-- Loading State (fallback) -->
          <div v-else class="flex items-center justify-center py-12">
            <span class="spinner text-primary-500"></span>
          </div>
        </section>
        
        <!-- How Caching Works Info -->
        <section>
          <div class="p-4 bg-surface-100 dark:bg-surface-800 rounded-xl">
            <div class="flex items-start gap-3">
              <span class="material-symbols-rounded text-surface-400 mt-0.5">info</span>
              <div class="text-sm text-surface-500">
                <p class="font-medium text-surface-600 dark:text-surface-400 mb-2">{{ $t('settingsView.howLayeredCachingWorks') }}</p>
                <p class="mb-3 text-xs">{{ $t('settingsView.cachingExplanationIntro') }}</p>
                <ul class="space-y-1.5">
                  <li><strong class="text-[#4ade80]">{{ $t('settingsView.redisServer') }}</strong> {{ $t('settingsView.sharedCacheAcrossAllYour') }}</li>
                  <li><strong class="text-blue-500">{{ $t('settingsView.browserCacheIndexeddb') }}</strong> {{ $t('settingsView.fastestForRepeatViewsStores') }}</li>
                  <li><strong class="text-purple-500">{{ $t('settingsView.aiSummariesLocalstorage') }}</strong> {{ $t('settingsView.cachedEmailSummariesToAvoid') }}</li>
                  <li><strong>{{ $t('settingsView.memoryCache') }}</strong> {{ $t('settingsView.instantAccessForCurrentlyOpen') }}</li>
                </ul>
                <p class="mt-3 text-xs text-surface-400">{{ $t('settingsView.lowRedisMessageCountIs') }}</p>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- Phase 8: Storage Dashboard (admin-only tab, gated in sidebarGroups) -->
      <div v-else-if="activeTab === 'storage-admin'">
        <StorageAdminDashboard />
      </div>
      
        </div>
      </main>
    </div>
    
    <!-- Remove OAuth Account Confirmation Modal -->
    <ConfirmModal
      :show="showRemoveAccountConfirm"
      :title="$t('settingsView.removeGoogleAccount')"
      :message="$t('settingsView.removeAccountConfirmMessage', { email: accountToRemove?.account_email })"
      :confirm-text="$t('settingsView.removeBtn')"
      type="danger"
      @confirm="removeOAuthAccount"
      @cancel="showRemoveAccountConfirm = false; accountToRemove = null"
    />
    
    <!-- Clear Cache Confirmation Modal -->
    <ConfirmModal
      :show="showClearCacheConfirm"
      :title="clearCacheTitle"
      :message="clearCacheMessage"
      :confirm-text="$t('settingsView.clearCacheBtn')"
      type="danger"
      @confirm="handleClearCacheConfirmed"
      @cancel="showClearCacheConfirm = false"
    />
    
    <!-- Mobile Bottom Navigation -->
    <MobileBottomNav v-if="isMobile" />

    <!-- Mobile settings menu bottom sheet -->
    <Teleport to="body">
      <Transition name="settings-sheet">
        <div
          v-if="isMobile && sidebarOpen"
          class="fixed inset-0 z-[60] bg-black/40"
          @click.self="closeSidebar"
        >
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[85vh] overflow-y-auto" style="-webkit-overflow-scrolling: touch;">
            <div class="flex justify-center pt-3 pb-1 sticky top-0 bg-white dark:bg-surface-800 z-10">
              <div class="w-10 h-1 bg-surface-300 dark:bg-surface-600 rounded-full"></div>
            </div>
            <div class="px-4 pb-6">
              <div v-for="group in sidebarGroups" :key="'m-' + group.name" class="mb-4">
                <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 px-1">{{ group.name }}</p>
                <div class="space-y-1">
                  <button
                    v-for="item in group.items"
                    :key="'m-' + item.id"
                    @click="setTab(item.id); closeSidebar()"
                    :class="[
                      'w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors',
                      activeTab === item.id
                        ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                        : 'bg-surface-50 dark:bg-surface-700/50 text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-600'
                    ]"
                  >
                    <span class="material-symbols-rounded text-lg">{{ item.icon }}</span>
                    {{ item.name }}
                    <span v-if="activeTab === item.id" class="material-symbols-rounded text-sm ml-auto">check</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.settings-sheet-enter-active { transition: opacity 0.2s ease; }
.settings-sheet-enter-active > div:last-child { transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.settings-sheet-leave-active { transition: opacity 0.15s ease; }
.settings-sheet-leave-active > div:last-child { transition: transform 0.2s ease-in; }
.settings-sheet-enter-from { opacity: 0; }
.settings-sheet-enter-from > div:last-child { transform: translateY(100%); }
.settings-sheet-leave-to { opacity: 0; }
.settings-sheet-leave-to > div:last-child { transform: translateY(100%); }
</style>
