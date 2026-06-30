<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useMailboxStore } from '@/stores/mailbox'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useComposeStore } from '@/stores/compose'
import { useLabelsStore } from '@/stores/labels'
import { useLayoutStore } from '@/stores/layout'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useNotificationsStore } from '@/stores/notifications'
import { useAccountsStore } from '@/stores/accounts'
import { useToastStore } from '@/stores/toast'
import { useSettingsStore } from '@/stores/settings'
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'
import { useOAuthCallback } from '@/composables/useOAuthCallback'
import { useFolderRevalidationInterval } from '@/composables/useFolderRevalidationInterval'
import { useAddons } from '@/composables/useAddons'
import AppHeader from '@/components/shared/AppHeader.vue'
import FolderTree from '@/components/FolderTree.vue'
import FolderRail from '@/components/FolderRail.vue'
import EmailList from '@/components/EmailList.vue'
import EmailSearchBar from '@/components/EmailSearchBar.vue'
import EmailView from '@/components/EmailView.vue'
// ComposeModal moved to App.vue as ComposeWindow for cross-view persistence
import BulkActions from '@/components/BulkActions.vue'
import AISummaryPanel from '@/addons/ai-assistant/components/AISummaryPanel.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import { useEmailSearchStore } from '@/stores/emailSearch'
import { useHoverIntent } from '@/composables/useHoverIntent'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'

import { isDebugEnabled } from '@/utils/debug'
import { isIOSNativePlatform } from '@/utils/platform'
import { folderToUrlPath, urlPathToFolder } from '@/services/mailRouteService'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const mailbox = useMailboxStore()
const auth = useAuthStore()
const theme = useThemeStore()
const compose = useComposeStore()
const labelsStore = useLabelsStore()
const layout = useLayoutStore()
const emailSearch = useEmailSearchStore()
const todosStore = useTodosStore()
const notificationsStore = useNotificationsStore()
const accountsStore = useAccountsStore()
const toast = useToastStore()
const settingsStore = useSettingsStore()

// Import calendar for event reminders
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useConversationsStore } from '@/stores/conversations'
const calendarStore = useCalendarStore()
const aiStore = useAIStore()
const conversationsStore = useConversationsStore()

// OAuth callback handler for sessionStorage fallback
const { checkOAuthFallback } = useOAuthCallback()

useFolderRevalidationInterval()

// Addon flags
const { aiAssistantEnabled } = useAddons()

// App Store Guideline 4/4.8: Google/Microsoft OAuth opens the system browser on
// native iOS, so the add-account OAuth buttons are hidden there.
const iosNative = isIOSNativePlatform()

const showAddAccountModal = ref(false)
const testingConnection = ref(false)
const addingAccount = ref(false)
const connectingGoogle = ref(false)
const connectingMicrosoft = ref(false)
const selectedPreset = ref('custom')

// Mobile state
const isMobile = ref(false)
const sidebarOpen = ref(false)

// Live viewport width (used to clamp the email list column so the email
// view panel is never squeezed out of view when the window narrows).
const windowWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1920)

// Check if mobile on mount and resize
function checkMobile() {
  isMobile.value = window.innerWidth < 768
  windowWidth.value = window.innerWidth
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

const addAccountForm = ref({
  account_email: '',
  password: '',
  display_name: '',
  imap_host: '',
  imap_port: 993,
  imap_encryption: 'ssl',
  smtp_host: '',
  smtp_port: 465,
  smtp_encryption: 'ssl',
  // Linked account options
  account_type: 'separate', // 'separate' or 'linked'
  sync_frequency: 15,
  leave_on_server: true,
  auto_label: '',
})

function resetAddAccountForm() {
  addAccountForm.value = {
    account_email: '',
    password: '',
    display_name: '',
    imap_host: '',
    imap_port: 993,
    imap_encryption: 'ssl',
    smtp_host: '',
    smtp_port: 587,
    smtp_encryption: 'tls',
    account_type: 'separate',
    sync_frequency: 15,
    leave_on_server: true,
    auto_label: '',
  }
  selectedPreset.value = 'custom'
}

function openAddAccountModal() {
  resetAddAccountForm()
  showAddAccountModal.value = true
}

function applyPreset(presetKey) {
  selectedPreset.value = presetKey
  const preset = accountsStore.presets[presetKey]
  if (preset) {
    addAccountForm.value.imap_host = preset.imap_host
    addAccountForm.value.imap_port = preset.imap_port
    addAccountForm.value.imap_encryption = preset.imap_encryption
    addAccountForm.value.smtp_host = preset.smtp_host
    addAccountForm.value.smtp_port = preset.smtp_port
    addAccountForm.value.smtp_encryption = preset.smtp_encryption
  }
}

async function testAccountConnection() {
  if (!addAccountForm.value.account_email || !addAccountForm.value.password || !addAccountForm.value.imap_host) {
    toast.warning(t('mailboxView.pleaseFillInEmailPassword'))
    return
  }
  
  testingConnection.value = true
  const result = await accountsStore.testConnection(addAccountForm.value)
  testingConnection.value = false
  
  if (result.success) {
    toast.success(t('mailboxView.connectionSuccessful'))
  } else {
    toast.error(result.error || t('mailboxView.connectionFailed'))
  }
}

async function addNewAccount() {
  if (!addAccountForm.value.account_email || !addAccountForm.value.password || !addAccountForm.value.imap_host) {
    toast.warning(t('mailboxView.pleaseFillInRequiredFields'))
    return
  }
  
  if (!addAccountForm.value.smtp_host) {
    addAccountForm.value.smtp_host = addAccountForm.value.imap_host.replace('imap.', 'smtp.')
  }
  
  addingAccount.value = true
  const result = await accountsStore.addAccount(addAccountForm.value)
  addingAccount.value = false
  
  if (result.success) {
    toast.success(t('mailboxView.accountAddedSuccessfully'))
    showAddAccountModal.value = false
    
    if (addAccountForm.value.account_type === 'linked') {
      // For linked accounts, trigger initial sync
      toast.info(t('mailboxView.startingInitialSync'))
      const syncResult = await accountsStore.triggerSync(result.account.id)
      if (syncResult.success) {
        toast.success(`${syncResult.fetched} ${t('mailboxView.syncedSyncresultfetchedEmailsFromLinked')}`)
        // Refresh inbox to show new emails
        await mailbox.fetchMessages('INBOX')
      }
    } else {
      // For separate accounts, switch to it
      accountsStore.setActiveAccount(result.account.id)
      await mailbox.fetchFolders()
      await mailbox.fetchMessages('INBOX')
    }
  } else {
    toast.error(result.error || t('mailboxView.failedToAddAccount'))
  }
}

// Google OAuth sign-in
async function signInWithGoogle() {
  connectingGoogle.value = true
  
  try {
    // Get the OAuth URL with account preferences
    const authUrl = await accountsStore.getGoogleAuthUrl({
      account_type: addAccountForm.value.account_type,
      sync_frequency: addAccountForm.value.sync_frequency,
      leave_on_server: addAccountForm.value.leave_on_server,
      auto_label: addAccountForm.value.auto_label,
    })
    
    if (!authUrl) {
      toast.error(t('mailboxView.failedToGetGoogleAuthorization'))
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
      toast.error(t('mailboxView.popupWasBlockedPleaseAllow'))
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
              toast.success(`Google account ${data.account_email || ''} ${t('mailboxView.connectionSuccessful')}`)
              showAddAccountModal.value = false
              await accountsStore.fetchAccounts()
              if (addAccountForm.value.account_type === 'linked') {
                await mailbox.fetchMessages('INBOX')
              }
            } else if (data.error) {
              toast.error(`${t('mailboxView.failedToInitiateGoogleSignin')}: ${data.error.replace(/_/g, ' ')}`)
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
      if (!event.origin.includes('flowone.pro')) return
      if (event.data?.type !== 'oauth_callback') return
      if (messageHandled) return
      
      messageHandled = true
      window.removeEventListener('message', handleOAuthMessage)
      window.removeEventListener('focus', handleFocus)
      clearInterval(checkClosed)
      connectingGoogle.value = false
      
      // Clear sessionStorage since we got the message directly
      sessionStorage.removeItem('oauth_callback_result')
      
      const { success, error, account_email } = event.data
      
      if (success) {
        toast.success(`Google account ${account_email || ''} ${t('mailboxView.connectionSuccessful')}`)
        showAddAccountModal.value = false
        await accountsStore.fetchAccounts()
        
        if (addAccountForm.value.account_type === 'linked') {
          await mailbox.fetchMessages('INBOX')
        }
      } else if (error) {
        toast.error(`${t('mailboxView.failedToInitiateGoogleSignin')}: ${error.replace(/_/g, ' ')}`)
      }
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    // Listen for focus back to this window (when OAuth tab/popup completes and redirects)
    const handleFocus = async () => {
      if (messageHandled) return
      // Small delay to let sessionStorage be set by redirect
      await new Promise(r => setTimeout(r, 300))
      await checkSessionStorage()
    }
    window.addEventListener('focus', handleFocus)
    
    // Check if popup was closed - also check sessionStorage for result
    const checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }
      if (!popup || popup.closed) {
        clearInterval(checkClosed)
        window.removeEventListener('message', handleOAuthMessage)
        window.removeEventListener('focus', handleFocus)
        
        // Check sessionStorage for result (popup might have stored it before closing)
        const handled = await checkSessionStorage()
        if (!handled) {
          connectingGoogle.value = false
        }
      }
    }, 500)
    
    // Timeout after 5 minutes
    setTimeout(() => {
      if (messageHandled) return
      clearInterval(checkClosed)
      window.removeEventListener('message', handleOAuthMessage)
      window.removeEventListener('focus', handleFocus)
      if (connectingGoogle.value) {
        connectingGoogle.value = false
        toast.warning(t('mailboxView.googleSigninTimedOut'))
      }
    }, 300000)
    
  } catch (e) {
    console.error('Google OAuth error:', e)
    toast.error(t('mailboxView.failedToInitiateGoogleSignin'))
    connectingGoogle.value = false
  }
}

// Microsoft OAuth sign-in
async function signInWithMicrosoft() {
  connectingMicrosoft.value = true
  
  try {
    // Get the OAuth URL with account preferences
    const authUrl = await accountsStore.getMicrosoftAuthUrl({
      account_type: addAccountForm.value.account_type,
      sync_frequency: addAccountForm.value.sync_frequency,
      leave_on_server: addAccountForm.value.leave_on_server,
      auto_label: addAccountForm.value.auto_label,
    })
    
    if (!authUrl) {
      toast.error(t('mailboxView.failedToGetMicrosoftAuthorization'))
      connectingMicrosoft.value = false
      return
    }
    
    // Open OAuth popup
    const width = 500
    const height = 600
    const left = window.screenX + (window.outerWidth - width) / 2
    const top = window.screenY + (window.outerHeight - height) / 2
    
    const popup = window.open(
      authUrl,
      'microsoft-oauth',
      `width=${width},height=${height},left=${left},top=${top},popup=1`
    )
    
    // Check if popup was blocked
    if (!popup || popup.closed) {
      toast.error(t('mailboxView.popupWasBlockedPleaseAllow'))
      connectingMicrosoft.value = false
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
            connectingMicrosoft.value = false
            
            if (data.success) {
              toast.success(`Microsoft account ${data.account_email || ''} ${t('mailboxView.connectionSuccessful')}`)
              showAddAccountModal.value = false
              await accountsStore.fetchAccounts()
              if (addAccountForm.value.account_type === 'linked') {
                await mailbox.fetchMessages('INBOX')
              }
            } else if (data.error) {
              toast.error(`${t('mailboxView.failedToInitiateMicrosoftSignin')}: ${data.error.replace(/_/g, ' ')}`)
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
      if (!event.origin.includes('flowone.pro')) return
      if (event.data?.type !== 'oauth_callback') return
      if (messageHandled) return
      
      messageHandled = true
      window.removeEventListener('message', handleOAuthMessage)
      window.removeEventListener('focus', handleFocus)
      clearInterval(checkClosed)
      connectingMicrosoft.value = false
      
      // Clear sessionStorage since we got the message directly
      sessionStorage.removeItem('oauth_callback_result')
      
      const { success, error, account_email } = event.data
      
      if (success) {
        toast.success(`Microsoft account ${account_email || ''} ${t('mailboxView.connectionSuccessful')}`)
        showAddAccountModal.value = false
        await accountsStore.fetchAccounts()
        
        if (addAccountForm.value.account_type === 'linked') {
          await mailbox.fetchMessages('INBOX')
        }
      } else if (error) {
        toast.error(`${t('mailboxView.failedToInitiateMicrosoftSignin')}: ${error.replace(/_/g, ' ')}`)
      }
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    // Listen for focus back to this window (when OAuth tab/popup completes and redirects)
    const handleFocus = async () => {
      if (messageHandled) return
      await new Promise(r => setTimeout(r, 300))
      await checkSessionStorage()
    }
    window.addEventListener('focus', handleFocus)
    
    // Check if popup was closed - also check sessionStorage for result
    const checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }
      if (!popup || popup.closed) {
        clearInterval(checkClosed)
        window.removeEventListener('message', handleOAuthMessage)
        window.removeEventListener('focus', handleFocus)
        
        // Check sessionStorage for result (popup might have stored it before closing)
        const handled = await checkSessionStorage()
        if (!handled) {
          connectingMicrosoft.value = false
        }
      }
    }, 500)
    
    // Timeout after 5 minutes
    setTimeout(() => {
      if (messageHandled) return
      clearInterval(checkClosed)
      window.removeEventListener('message', handleOAuthMessage)
      window.removeEventListener('focus', handleFocus)
      if (connectingMicrosoft.value) {
        connectingMicrosoft.value = false
        toast.warning(t('mailboxView.microsoftSigninTimedOut'))
      }
    }, 300000)
    
  } catch (e) {
    console.error('Microsoft OAuth error:', e)
    toast.error(t('mailboxView.failedToInitiateMicrosoftSignin'))
    connectingMicrosoft.value = false
  }
}



// Handle refresh summary from AI panel
async function handleRefreshSummary() {
  const msg = mailbox.currentMessage
  if (!msg) return
  
  // Build content from conversation messages if available
  let emailContent = ''
  
  if (msg.isConversation && msg.messages) {
    emailContent = msg.messages
      .map(m => {
        const from = m.from?.[0]?.email || 'Unknown'
        const to = m.to?.map(t => t.email).join(', ') || 'Unknown'
        const cc = m.cc?.map(c => c.email).join(', ') || ''
        const date = new Date(m.timestamp * 1000).toLocaleString()
        const body = m.body_text || stripHtmlForAI(m.body_html) || ''
        let headers = `From: ${from}\nTo: ${to}`
        if (cc) headers += `\nCc: ${cc}`
        headers += `\nDate: ${date}\nSubject: ${m.subject}`
        return `${headers}\n\n${body}`
      })
      .join('\n\n---\n\n')
  } else {
    const from = msg.from?.[0]?.email || 'Unknown'
    const to = msg.to?.map(t => t.email).join(', ') || 'Unknown'
    const cc = msg.cc?.map(c => c.email).join(', ') || ''
    const date = new Date(msg.timestamp * 1000).toLocaleString()
    const body = msg.body_text || stripHtmlForAI(msg.body_html) || ''
    let headers = `From: ${from}\nTo: ${to}`
    if (cc) headers += `\nCc: ${cc}`
    headers += `\nDate: ${date}\nSubject: ${msg.subject}`
    emailContent = `${headers}\n\n${body}`
  }
  
  if (!emailContent.trim()) return
  
  const cacheInfo = {
    folder: mailbox.currentFolder,
    uid: msg.uid,
    messageId: msg.message_id,
    userEmail: auth.userEmail
  }
  
  // Force refresh (clear cache and re-summarize)
  aiStore.clearSummary()
  const result = await aiStore.summarize(emailContent, cacheInfo, true)
  
  if (!result.success && result.too_long) {
    toast.warning(result.error, { duration: 8000 })
  }
}

// Strip HTML for AI processing
function stripHtmlForAI(html) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  return doc.body.textContent || ''
}

// Enable keyboard shortcuts
useKeyboardShortcuts()

// Check if we have selection
const hasSelection = computed(() => mailbox.selectedMessages.length > 0)

// Resizable email list column
const SIDEBAR_FULL_WIDTH = 256
const SIDEBAR_RAIL_WIDTH = 64
const MIN_EMAIL_LIST_WIDTH = 280
const MIN_EMAIL_VIEW_WIDTH = 480

// Sidebar collapse / hover-intent. When collapsed, the icon rail occupies the
// reserved column space and the full FolderTree pops out as a floating overlay
// on hover-intent (Slack/Linear-style). The popout never shifts page layout.
const { open: sidebarHover, onEnter: onSidebarEnter, onLeave: onSidebarLeave, forceClose: forceCloseSidebarHover } = useHoverIntent({ openDelay: 80, closeDelay: 150 })

// Called whenever the FolderTree popout commits a folder selection — closes
// the popout immediately (no 150ms tail) so the user sees the new folder
// without a hover ghost.
function onSidebarPopoutSelect() {
  closeSidebar()
  forceCloseSidebarHover()
}

const currentSidebarWidth = computed(() => layout.sidebarCollapsed ? SIDEBAR_RAIL_WIDTH : SIDEBAR_FULL_WIDTH)

const emailListWidth = ref(parseInt(localStorage.getItem('emailListWidth')) || 384)
const isResizing = ref(false)

// The width actually applied to the email list column. Clamps the
// stored preference against the current window size so the email view
// panel always has at least MIN_EMAIL_VIEW_WIDTH px to render in.
// The user's saved preference is preserved — when the window widens
// again the column springs back to their chosen size.
const effectiveEmailListWidth = computed(() => {
  const maxAllowed = Math.max(
    MIN_EMAIL_LIST_WIDTH,
    windowWidth.value - currentSidebarWidth.value - MIN_EMAIL_VIEW_WIDTH
  )
  return Math.min(emailListWidth.value, maxAllowed)
})

function startResize(e) {
  e.preventDefault()
  isResizing.value = true
  document.addEventListener('mousemove', onResize)
  document.addEventListener('mouseup', stopResize)
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
}

function onResize(e) {
  if (!isResizing.value) return
  const sidebarPx = currentSidebarWidth.value
  const maxWidth = window.innerWidth - sidebarPx - MIN_EMAIL_VIEW_WIDTH
  const newWidth = e.clientX - sidebarPx
  emailListWidth.value = Math.min(
    Math.max(newWidth, MIN_EMAIL_LIST_WIDTH),
    Math.max(MIN_EMAIL_LIST_WIDTH, maxWidth)
  )
}

function stopResize() {
  isResizing.value = false
  document.removeEventListener('mousemove', onResize)
  document.removeEventListener('mouseup', stopResize)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
  localStorage.setItem('emailListWidth', emailListWidth.value.toString())
}

onUnmounted(() => {
  document.removeEventListener('mousemove', onResize)
  document.removeEventListener('mouseup', stopResize)
  window.removeEventListener('resize', checkMobile)
  accountsStore.stopUnreadCountsPolling()
  accountsStore.stopLinkedSyncPolling()
})

// Update URL when folder changes
function updateFolderUrl(folderName) {
  if (!folderName) return
  
  // Don't update URL for search results - keep it stable
  if (folderName === 'SEARCH_RESULTS') {
    const newUrl = '/folder/search_results'
    if (router.currentRoute.value.fullPath.toLowerCase() !== newUrl.toLowerCase()) {
      router.replace(newUrl)
    }
    return
  }
  
  // Convert folder name to clean URL:
  // - dots become slashes (for hierarchy)
  // - spaces become underscores
  // - lowercase for cleaner URLs
  const folderPath = folderName
    .replace(/\./g, '/')
    .replace(/ /g, '_')
    .toLowerCase()
  
  // Update URL to reflect current folder
  const newUrl = folderName === 'INBOX' ? '/inbox' : `/folder/${folderPath}`
  if (router.currentRoute.value.fullPath.toLowerCase() !== newUrl.toLowerCase()) {
    router.replace(newUrl)
  }
}

// folderToUrlPath / urlPathToFolder now live in mailRouteService so MailboxView
// and NotificationPanel share one source of truth (see imports above).

// Get folder name from URL (case-insensitive match)
function getFolderFromUrl() {
  const folderParam = route.params.folder
  if (!folderParam) return 'INBOX'
  
  // Handle array (from wildcard route) or string
  const folderPath = Array.isArray(folderParam) ? folderParam.join('/') : folderParam
  // Convert URL path back to folder name format:
  // - slashes become dots
  // - underscores become spaces
  const urlFolderName = decodeURIComponent(folderPath)
    .replace(/\//g, '.')
    .replace(/_/g, ' ')
  
  return urlFolderName
}

// Find actual folder name (case-insensitive, handles both . and / hierarchy separators)
function findActualFolderName(urlFolderName) {
  if (!mailbox.folders || mailbox.folders.length === 0) return urlFolderName
  
  const lowerName = urlFolderName.toLowerCase()
  const found = mailbox.folders.find(f => f.name.toLowerCase() === lowerName)
  if (found) return found.name
  
  // Gmail uses / as separator (e.g. [Gmail]/Bin) but the URL roundtrip converts / to .
  // Try matching with dots replaced by slashes to recover the original name
  const withSlashes = urlFolderName.replace(/\./g, '/').toLowerCase()
  const found2 = mailbox.folders.find(f => f.name.toLowerCase() === withSlashes)
  if (found2) return found2.name

  // Try matching each folder through the same URL transformation (covers edge cases)
  const found3 = mailbox.folders.find(f => {
    const urlVersion = f.name.replace(/\./g, '/').replace(/ /g, '_').toLowerCase()
    return urlVersion === withSlashes.replace(/ /g, '_')
  })
  return found3 ? found3.name : urlFolderName
}

onMounted(async () => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  await checkOAuthFallback()
  
  try {
    const refreshMs = (settingsStore.settings.refresh_interval || 60) * 1000
    if (refreshMs > 0) {
      accountsStore.startUnreadCountsPolling(refreshMs)
    }
    
    if (accountsStore.linkedAccounts.length > 0) {
      accountsStore.startLinkedSyncPolling()
    }
    
    conversationsStore.migrateExistingSplits().catch(e => {
      console.warn('[MailboxView] Failed to migrate splits:', e)
    })
    
    const urlFolder = getFolderFromUrl()
    const normalizedUrl = urlFolder.toLowerCase().replace(/ /g, '_')
    const isDefaultInbox = normalizedUrl === 'inbox' || normalizedUrl === ''

    // Single-request init: folders + messages + conversations + pinned + scheduled + ai_config
    const initResult = await mailbox.initMailbox()

    // Hydrate AI config from bundled response (avoids separate GET /ai/config)
    if (initResult?.ai_config && aiAssistantEnabled.value) {
      aiStore.hydrateFromInit(initResult.ai_config)
    }
    
    if (isDefaultInbox) {
      // initMailbox already loaded INBOX folders + messages
    } else if (normalizedUrl === 'search_results') {
      isDebugEnabled() && console.log('[MailboxView] On search_results URL - redirecting to INBOX')
      mailbox.currentFolder = 'INBOX'
      router.replace('/inbox')
    } else if (normalizedUrl === 'all_mail') {
      isDebugEnabled() && console.log('[MailboxView] On all_mail URL - fetching all mail')
      await mailbox.fetchAllMail()
    } else if (normalizedUrl === 'scheduled') {
      isDebugEnabled() && console.log('[MailboxView] On scheduled URL - fetching scheduled emails')
      await mailbox.fetchScheduledEmails()
    } else {
      const actualFolder = findActualFolderName(urlFolder)
      await mailbox.fetchMessages(actualFolder)
    }
    
    try {
      await calendarStore.fetchTodayEventsForReminders()
    } catch (e) {
      console.error('Failed to initialize calendar reminders:', e)
    }
  } catch (e) {
    console.error('MailboxView initialization failed:', e)
    if (e.response?.status === 401 || e.response?.status === 403) {
      auth.clearAuth()
      window.location.href = '/login'
    }
  }
})

// Page title is now managed centrally in App.vue (combines email + chat unreads)

// Update URL when folder changes
watch(() => mailbox.currentFolder, (newFolder) => {
  if (newFolder) {
    // Don't overwrite the email URL when navigating to a specific message
    // The mailbox-email route watcher handles folder switching internally,
    // and the currentMessage watcher will set the correct /email/.../message/uid URL
    if (route.name === 'mailbox-email') return
    updateFolderUrl(newFolder)
  }
})

// Update URL when email is selected/deselected
watch(() => mailbox.currentMessage, (newMessage, oldMessage) => {
  isDebugEnabled() && console.log('[URL Debug] currentMessage changed:', { 
    hasNew: !!newMessage, 
    uid: newMessage?.uid, 
    folder: mailbox.currentFolder 
  })
  
  if (newMessage && newMessage.uid) {
    // Email selected - update URL to include message UID
    const isVirtualFolder = mailbox.currentFolder === 'ALL_MAIL' || mailbox.currentFolder === 'SEARCH_RESULTS'
    const folderPath = folderToUrlPath(mailbox.currentFolder)
    let newUrl = `/email/${folderPath}/message/${newMessage.uid}`
    // For virtual folders, encode the actual IMAP folder as a query param so page reload works
    if (isVirtualFolder && newMessage.folder) {
      newUrl += `?mf=${encodeURIComponent(newMessage.folder)}`
    }
    isDebugEnabled() && console.log('[URL Debug] Updating URL to:', newUrl, 'Current:', router.currentRoute.value.fullPath)
    if (router.currentRoute.value.fullPath !== newUrl) {
      router.replace(newUrl)
    }
    
    // Track email reading for client time tracking
    clientTimeTracker.trackEmailRead(newMessage, auth.userEmail)
  } else if (!newMessage && oldMessage) {
    // Email deselected (closed) - go back to folder view
    updateFolderUrl(mailbox.currentFolder)
    
    // Stop tracking when email is closed
    clientTimeTracker.stopTracking()
  }
})

// Watch for email route params (/email/:folder/message/:uid)
watch(() => [route.name, route.params], async ([routeName, params]) => {
  isDebugEnabled() && console.log('[Route Debug] Route changed:', { routeName, params })
  
  if (routeName === 'mailbox-email' && params.folder && params.uid) {
    // Convert URL path to folder name and then find the actual folder name (case-insensitive)
    const urlFolder = urlPathToFolder(params.folder)
    const messageUid = parseInt(params.uid)
    
    // Special handling for virtual folders (search results, all mail) - don't try to fetch a real folder
    // Note: urlPathToFolder converts 'search_results' to 'search results' (underscore -> space)
    const isSearchResults = urlFolder.toLowerCase().replace(/ /g, '_') === 'search_results' || 
                            mailbox.currentFolder === 'SEARCH_RESULTS'
    const isAllMail = urlFolder.toLowerCase().replace(/ /g, '_') === 'all_mail' || 
                      mailbox.currentFolder === 'ALL_MAIL'
    
    if (isSearchResults || isAllMail) {
      isDebugEnabled() && console.log('[Route Debug] In virtual folder (search/all mail), just fetching message')
      if (messageUid && messageUid > 0 && mailbox.currentMessage?.uid !== messageUid) {
        // Try to resolve the message's actual IMAP folder from multiple sources:
        // 1. URL query param ?mf= (most reliable, survives page reload)
        const queryFolder = route.query.mf ? decodeURIComponent(route.query.mf) : null
        // 2. Message list - use folder from ?mf= to disambiguate UID collisions across folders
        const searchMessage = mailbox.findMessageByUid(messageUid, queryFolder || null)
        const actualFolder = queryFolder || searchMessage?.folder

        if (actualFolder) {
          await mailbox.fetchMessageFromFolder(messageUid, actualFolder)
        } else {
          // No folder info available - load All Mail data first, then retry
          if (isAllMail && mailbox.messages.length === 0) {
            await mailbox.fetchAllMail()
            const msg = mailbox.findMessageByUid(messageUid)
            if (msg?.folder) {
              await mailbox.fetchMessageFromFolder(messageUid, msg.folder)
            } else {
              console.warn(`[Route] Could not find folder for UID ${messageUid} in All Mail`)
            }
          } else {
            await mailbox.fetchMessage(messageUid, false, queryFolder)
          }
        }
      }
      return
    }
    
    const targetFolder = findActualFolderName(urlFolder)
    
    isDebugEnabled() && console.log('[Route Debug] Email route:', { 
      urlFolder, 
      targetFolder, 
      messageUid, 
      rawFolder: params.folder, 
      rawUid: params.uid,
      knownFolders: mailbox.folders?.length || 0
    })
    
    // Don't fetch if already on this message
    if (mailbox.currentMessage?.uid === messageUid && mailbox.currentFolder === targetFolder) {
      isDebugEnabled() && console.log('[Route Debug] Already on this message, skipping fetch')
      return
    }
    
    // Switch folder if different (compare case-insensitively)
    if (targetFolder && targetFolder.toLowerCase() !== mailbox.currentFolder?.toLowerCase()) {
      isDebugEnabled() && console.log('[Route Debug] Switching folder to:', targetFolder)
      // Handle ALL_MAIL virtual folder
      if (targetFolder.toLowerCase() === 'all_mail') {
        await mailbox.fetchAllMail()
      } else {
        await mailbox.fetchMessages(targetFolder, 1)
      }
    }
    
    // Open specific message
    if (messageUid && messageUid > 0) {
      isDebugEnabled() && console.log('[Route Debug] Fetching message UID:', messageUid)
      setTimeout(async () => {
        await mailbox.fetchMessage(messageUid)
      }, 100)
    }
  }
}, { immediate: true })

// Watch for query parameters (folder, message, search) to handle deep links
watch(() => route.query, async (query) => {
  // Handle folder and message params (for opening specific emails)
  if (query.folder || query.message) {
    const urlFolder = query.folder ? decodeURIComponent(query.folder) : mailbox.currentFolder
    // Use findActualFolderName to get case-correct folder name
    const targetFolder = findActualFolderName(urlFolder)
    const messageUid = query.message ? parseInt(query.message) : null
    
    // Switch folder if different (compare case-insensitively)
    if (targetFolder && targetFolder.toLowerCase() !== mailbox.currentFolder?.toLowerCase()) {
      // Handle ALL_MAIL virtual folder
      if (targetFolder.toLowerCase() === 'all_mail') {
        await mailbox.fetchAllMail()
      } else {
        await mailbox.fetchMessages(targetFolder, 1)
      }
    }
    
    // Open specific message if provided
    if (messageUid) {
      // Wait a tick for messages to load
      setTimeout(async () => {
        await mailbox.fetchMessage(messageUid)
        // Redirect to proper email URL
        const folderPath = folderToUrlPath(targetFolder)
        router.replace(`/email/${folderPath}/message/${messageUid}`)
      }, 100)
    }
  }
  
  // Handle search params (for View Emails from Clients). EmailList no longer
  // owns the search bar — the header search lives in EmailSearchBar + the
  // emailSearch store, so we prime that store and let it kick off the search.
  if (query.search && !query.folder && !query.message) {
    const searchTerm = decodeURIComponent(query.search)
    // Tiny delay so mailbox.currentFolder is populated before we cache it as
    // preSearchFolder.
    setTimeout(() => {
      emailSearch.primeFromUrl(searchTerm)
      // Strip the param so back/forward doesn't replay the search
      const cleanQuery = { ...query }
      delete cleanQuery.search
      router.replace({ query: cleanQuery })
    }, 150)
  }
}, { immediate: true })

// Watch for refresh interval changes and restart polling
watch(() => settingsStore.settings.refresh_interval, (newInterval) => {
  const refreshMs = (newInterval || 0) * 1000
  if (refreshMs > 0) {
    accountsStore.startUnreadCountsPolling(refreshMs)
  } else {
    accountsStore.stopUnreadCountsPolling()
  }
})

// Watch for pending add account flag from settings page
watch(() => accountsStore.pendingAddAccount, (shouldOpen) => {
  if (shouldOpen) {
    showAddAccountModal.value = true
    accountsStore.pendingAddAccount = false
  }
}, { immediate: true })
</script>

<template>
  <!--
    Outer shell is a HORIZONTAL flex so the sidebar reaches the top of the
    viewport. The AppHeader sits inside the right column and only spans the
    width of the content area (email list + email view), Slack/Linear-style.
  -->
  <div class="h-screen h-[100dvh] flex bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    
    <!-- Add Account Modal -->
    <Teleport to="body">
      <div v-if="showAddAccountModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="w-full max-w-lg bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('mailboxView.addEmailAccount') }}</h3>
            <button @click="showAddAccountModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">
            <!-- Account Type Toggle (First - applies to both Google and manual) -->
            <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">
                {{ $t('mailboxView.howShouldThisAccountWork') }}
              </label>
              <div class="flex gap-3">
                <button
                  @click="addAccountForm.account_type = 'separate'"
                  :class="[
                    'flex-1 p-3 rounded-lg border-2 transition-all text-left',
                    addAccountForm.account_type === 'separate' 
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' 
                      : 'border-surface-200 dark:border-surface-600 hover:border-surface-300'
                  ]"
                >
                  <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-rounded text-lg" :class="addAccountForm.account_type === 'separate' ? 'text-primary-500' : 'text-surface-400'">swap_horiz</span>
                    <span class="font-medium text-sm">{{ $t('mailboxView.separate') }}</span>
                  </div>
                  <p class="text-xs text-surface-500">{{ $t('mailboxView.switchBetweenAccountsEachHas') }}</p>
                </button>
                <button
                  @click="addAccountForm.account_type = 'linked'"
                  :class="[
                    'flex-1 p-3 rounded-lg border-2 transition-all text-left',
                    addAccountForm.account_type === 'linked' 
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' 
                      : 'border-surface-200 dark:border-surface-600 hover:border-surface-300'
                  ]"
                >
                  <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-rounded text-lg" :class="addAccountForm.account_type === 'linked' ? 'text-primary-500' : 'text-surface-400'">link</span>
                    <span class="font-medium text-sm">{{ $t('mailboxView.linked') }}</span>
                  </div>
                  <p class="text-xs text-surface-500">{{ $t('mailboxView.syncEmailsIntoYourMain') }}</p>
                </button>
              </div>
            </div>
            
            <!-- Provider Presets -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ $t('mailboxView.emailProvider') }}
              </label>
              <div class="flex flex-wrap gap-2">
                <!-- Google OAuth Button (small, in row) -->
                <button
                  v-if="accountsStore.googleOAuthEnabled && !iosNative"
                  @click="signInWithGoogle"
                  :disabled="connectingGoogle"
                  class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 flex items-center gap-1.5"
                >
                  <svg class="w-4 h-4" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                  </svg>
                  <span v-if="connectingGoogle" class="spinner w-3 h-3"></span>
                  <span v-else>{{ $t('mailboxView.gmail') }}</span>
                </button>
                
                <!-- Microsoft OAuth Button (small, in row) -->
                <button
                  v-if="accountsStore.microsoftOAuthEnabled && !iosNative"
                  @click="signInWithMicrosoft"
                  :disabled="connectingMicrosoft"
                  class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 flex items-center gap-1.5"
                >
                  <svg class="w-4 h-4" viewBox="0 0 23 23">
                    <path fill="#f35325" d="M1 1h10v10H1z"/>
                    <path fill="#81bc06" d="M12 1h10v10H12z"/>
                    <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                    <path fill="#ffba08" d="M12 12h10v10H12z"/>
                  </svg>
                  <span v-if="connectingMicrosoft" class="spinner w-3 h-3"></span>
                  <span v-else>{{ $t('mailboxView.outlook') }}</span>
                </button>
                
                <button
                  v-for="(preset, key) in accountsStore.presets"
                  :key="key"
                  v-show="!(key === 'gmail' && accountsStore.googleOAuthEnabled)"
                  @click="applyPreset(key)"
                  :class="[
                    'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
                    selectedPreset === key 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                  ]"
                >
                  {{ preset.name }}
                </button>
              </div>
            </div>
            
            <!-- Credentials (for manual connection) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('mailboxView.emailAddress') }}
                </label>
                <input v-model="addAccountForm.account_email" type="email" class="input" :placeholder="$t('mailboxView.youremailcom')" />
              </div>
              
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('mailboxView.passwordAppPassword') }}
                </label>
                <input v-model="addAccountForm.password" type="password" class="input" :placeholder="$t('mailboxView.password')" />
                <p class="text-xs text-surface-500 mt-1">{{ $t('mailboxView.forGmailyahooWith2faUse') }}</p>
              </div>
              
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('mailboxView.displayNameOptional') }}
                </label>
                <input v-model="addAccountForm.display_name" type="text" class="input" :placeholder="$t('mailboxView.myWorkAccount')" />
              </div>
            </div>
            
            <!-- Server Settings -->
            <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
              <p class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">{{ $t('mailboxView.serverSettings') }}</p>
              
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs text-surface-500 mb-1">{{ $t('mailboxView.imapHost') }}</label>
                  <input v-model="addAccountForm.imap_host" type="text" class="input text-sm" :placeholder="$t('mailboxView.imapexamplecom')" />
                </div>
                <div class="flex gap-2">
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">{{ $t('mailboxView.port') }}</label>
                    <input v-model="addAccountForm.imap_port" type="number" class="input text-sm" />
                  </div>
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">{{ $t('mailboxView.encryption') }}</label>
                    <select v-model="addAccountForm.imap_encryption" class="input text-sm">
                      <option value="ssl">{{ $t('mailboxView.ssl') }}</option>
                      <option value="tls">{{ $t('mailboxView.tls') }}</option>
                      <option value="none">{{ $t('mailboxView.none') }}</option>
                    </select>
                  </div>
                </div>
                
                <div>
                  <label class="block text-xs text-surface-500 mb-1">{{ $t('mailboxView.smtpHost') }}</label>
                  <input v-model="addAccountForm.smtp_host" type="text" class="input text-sm" :placeholder="$t('mailboxView.smtpexamplecom')" />
                </div>
                <div class="flex gap-2">
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">{{ $t('mailboxView.port') }}</label>
                    <input v-model="addAccountForm.smtp_port" type="number" class="input text-sm" />
                  </div>
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">{{ $t('mailboxView.encryption') }}</label>
                    <select v-model="addAccountForm.smtp_encryption" class="input text-sm">
                      <option value="ssl">{{ $t('mailboxView.ssl') }}</option>
                      <option value="tls">{{ $t('mailboxView.tls') }}</option>
                      <option value="none">{{ $t('mailboxView.none') }}</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Linked Account Options -->
            <div v-if="addAccountForm.account_type === 'linked'" class="pt-4 border-t border-surface-200 dark:border-surface-700">
              <p class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">{{ $t('mailboxView.syncOptions') }}</p>
              
              <div class="space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <label class="block text-sm text-surface-700 dark:text-surface-300">{{ $t('mailboxView.syncFrequency') }}</label>
                    <p class="text-xs text-surface-500">{{ $t('mailboxView.howOftenToCheckFor') }}</p>
                  </div>
                  <select v-model="addAccountForm.sync_frequency" class="input w-32 text-sm">
                    <option :value="5">{{ $t('mailboxView.5Minutes') }}</option>
                    <option :value="15">{{ $t('mailboxView.15Minutes') }}</option>
                    <option :value="30">{{ $t('mailboxView.30Minutes') }}</option>
                    <option :value="60">{{ $t('mailboxView.1Hour') }}</option>
                  </select>
                </div>
                
                <div class="flex items-center justify-between">
                  <div>
                    <label class="block text-sm text-surface-700 dark:text-surface-300">{{ $t('mailboxView.leaveOnServer') }}</label>
                    <p class="text-xs text-surface-500">{{ $t('mailboxView.keepEmailsOnTheSource') }}</p>
                  </div>
                  <button
                    @click="addAccountForm.leave_on_server = !addAccountForm.leave_on_server"
                    :class="[
                      'w-12 h-6 rounded-full transition-colors relative shrink-0',
                      addAccountForm.leave_on_server ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span
                      :class="[
                        'absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200',
                        addAccountForm.leave_on_server ? 'translate-x-6' : 'translate-x-0'
                      ]"
                    ></span>
                  </button>
                </div>
                
                <div>
                  <label class="block text-sm text-surface-700 dark:text-surface-300 mb-1">{{ $t('mailboxView.autoLabelOptional') }}</label>
                  <input 
                    v-model="addAccountForm.auto_label" 
                    type="text" 
                    class="input text-sm" 
                    :placeholder="$t('mailboxView.egWorkPersonal')"
                  />
                  <p class="text-xs text-surface-500 mt-1">{{ $t('mailboxView.automaticallyTagSyncedEmailsWith') }}</p>
                </div>
              </div>
            </div>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-between">
            <button @click="testAccountConnection" class="btn-secondary" :disabled="testingConnection">
              <span v-if="testingConnection" class="spinner w-4 h-4"></span>
              <span class="material-symbols-rounded">wifi_tethering</span>
              {{ $t('mailboxView.testConnection') }}
            </button>
            <div class="flex gap-2">
              <button @click="showAddAccountModal = false" class="btn-ghost">{{ $t('mailboxView.cancel') }}</button>
              <button @click="addNewAccount" class="btn-primary" :disabled="addingAccount">
                <span v-if="addingAccount" class="spinner w-4 h-4"></span>
                <span class="material-symbols-rounded">add</span>
                {{ $t('mailboxView.addAccount') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Mobile sidebar overlay (fixed inset-0; sits behind the off-canvas aside) -->
    <div
      v-if="isMobile"
      class="sidebar-overlay"
      :class="{ 'open': sidebarOpen }"
      @click="closeSidebar"
    ></div>

    <!--
      Folder sidebar — sits at the top-left of the viewport (outside the right
      column) so the FlowOne brand block reaches the very top instead of
      tucking under the AppHeader.

      Three behaviours:
        * mobile          : full panel, slides over content (off-canvas, position:fixed)
        * desktop expanded: 256px panel inline (FolderTree)
        * desktop rail    : 64px icon rail (FolderRail) + hover-intent pops out
                            the full FolderTree as a floating overlay on the
                            right edge — no layout shift.
      We mount FolderTree OR FolderRail (not both at once) so watchers, DnD
      and folder fetches only run once.
    -->
    <aside
      class="flex-shrink-0 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] overflow-visible transition-[width] duration-150 ease-out relative z-20"
      :class="[
        // `.sidebar-container` provides the off-canvas slide-in for mobile only.
        // On desktop/tablet it would force width: 220px !important (tablet rule)
        // or 14rem (compact density), nuking the collapsed-rail width — so we
        // only apply it on mobile and let Tailwind drive the desktop width.
        isMobile ? 'sidebar-container' : '',
        { 'open': sidebarOpen },
        (!isMobile && layout.sidebarCollapsed) ? 'w-16' : 'w-64'
      ]"
      style="contain: layout;"
      @mouseenter="!isMobile && layout.sidebarCollapsed && onSidebarEnter()"
      @mouseleave="!isMobile && layout.sidebarCollapsed && onSidebarLeave()"
    >
      <!-- Mobile / expanded desktop: full FolderTree inline -->
      <div v-if="isMobile || !layout.sidebarCollapsed" class="h-full overflow-hidden">
        <FolderTree @folder-selected="closeSidebar" />
      </div>

      <!-- Collapsed desktop: icon rail (always visible) -->
      <div v-else class="h-full overflow-hidden">
        <FolderRail @folder-selected="onSidebarLeave" />
      </div>

      <!-- Collapsed desktop: hover popout of full FolderTree, anchored to the
           right edge of the rail, floats over the right column. -->
      <div
        v-if="!isMobile && layout.sidebarCollapsed && sidebarHover"
        class="absolute top-0 left-full h-full w-64 z-30 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] shadow-2xl"
        @mouseenter="onSidebarEnter"
        @mouseleave="onSidebarLeave"
      >
        <FolderTree @folder-selected="onSidebarPopoutSelect" />
      </div>
    </aside>

    <!-- Right column: header + main content stacked, sized to remaining width.
         min-w-0 so flex-basis doesn't force overflow when the email list grows. -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Top bar — Email view hides AppHeader's brand block because the FlowOne
           logo lives in the sidebar (see FolderTree header / FolderRail) and the
           centre slot is taken over by the email search pill. -->
      <AppHeader
        current-view="email"
        icon="mail"
        :title="t('mailboxView.devconWebmail')"
        :hide-branding="true"
        :show-mobile-menu="isMobile"
        @toggle-sidebar="toggleSidebar"
      >
        <template #center>
          <!-- No max-width cap: the search input should fill the ENTIRE
               space between the brand block on the left and the right
               action cluster (weather/rocket/notifications/…). -->
          <div class="hidden md:flex w-full px-2">
            <EmailSearchBar placement="header" class="w-full" />
          </div>
        </template>
      </AppHeader>

    <!-- Main content -->
    <div class="flex-1 flex overflow-hidden relative">
      <!-- COLUMNS LAYOUT (3-column) -->
      <template v-if="layout.isColumnsLayout">
        <!-- Email list column -->
        <div 
          class="flex-shrink-0 flex flex-col border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] relative"
          :style="{ width: effectiveEmailListWidth + 'px' }"
        >
          <!-- Bulk actions overlay (covers search bar, no layout shift) -->
          <Transition name="toolbar-swap">
            <div
              v-if="hasSelection"
              class="absolute top-0 left-0 right-0 h-12 z-10 flex items-center border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900"
            >
              <BulkActions />
            </div>
          </Transition>
          
          <!-- Email list content -->
          <div class="flex-1 overflow-hidden">
            <EmailList />
          </div>
          
          <!-- Resize handle (wider hit area, thin visual line) -->
          <div 
            @mousedown="startResize"
            class="absolute -right-1 w-3 top-0 bottom-0 cursor-col-resize z-20 group"
          >
            <div 
              class="absolute inset-y-0 left-1/2 -translate-x-1/2 w-0.5 group-hover:w-1 group-hover:bg-primary-500/50 transition-all"
              :class="{ '!w-1 !bg-primary-500': isResizing }"
            ></div>
          </div>
        </div>
        
        <!-- Email view column -->
        <main class="flex-1 min-w-[480px] flex flex-col">
          <EmailView />
        </main>
      </template>
      
      <!-- STACKED LAYOUT (Gmail-style) -->
      <template v-else>
        <div class="flex-1 flex flex-col bg-white dark:bg-[rgb(var(--color-surface))] relative overflow-hidden">
          <!-- Email list view -->
          <Transition name="slide-left">
          <div v-show="!mailbox.currentMessage" class="flex-1 flex flex-col overflow-hidden relative">
            <!-- Bulk actions overlay (covers search bar, no layout shift) -->
            <Transition name="toolbar-swap">
              <div
                v-if="hasSelection"
                class="absolute top-0 left-0 right-0 h-12 z-10 flex items-center border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900"
              >
                <BulkActions />
              </div>
            </Transition>
            
            <!-- List content -->
            <div class="flex-1 overflow-hidden">
              <EmailList />
            </div>
          </div>
          </Transition>
          
          <!-- Email view (full screen overlay when message selected) - slides in from right -->
          <Transition name="slide-right">
            <div v-if="mailbox.currentMessage" class="absolute inset-0 bg-white dark:bg-[rgb(var(--color-surface))] overflow-hidden z-10">
              <EmailView :showPlaceholder="false" />
            </div>
          </Transition>
        </div>
      </template>
      
      <!-- Mobile floating compose button -->
      <button 
        v-if="isMobile && !mailbox.currentMessage"
        @click="compose.open()"
        class="btn-primary mobile-fab"
        :title="$t('mailboxView.compose')"
      >
        <span class="material-symbols-rounded" style="font-size: 1.5rem; line-height: 1;">edit</span>
      </button>
    </div>
    </div><!-- /right column -->

    <!-- ComposeWindow is now rendered globally in App.vue -->
    
    <!-- AI Summary panel -->
    <AISummaryPanel v-if="aiAssistantEnabled" @refresh="handleRefreshSummary" />
    
    <!-- Mobile bottom navigation - hide when viewing an email -->
    <MobileBottomNav v-if="isMobile && !mailbox.currentMessage" />
  </div>
</template>

<style scoped>
/* Slide from right animation - for email view */
/* Enter (sliding in): decelerate - starts fast, slows down at the end */
.slide-right-enter-active {
  transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
/* Leave (sliding out): accelerate - exact reverse of enter curve */
.slide-right-leave-active {
  transition: transform 0.4s cubic-bezier(0.7, 0, 0.84, 0);
}

.slide-right-enter-from {
  transform: translateX(100%);
}

.slide-right-leave-to {
  transform: translateX(100%);
}

/* Slide left animation - for email list (opposite direction) */
/* Enter (list reappearing): decelerate - settles at the end */
.slide-left-enter-active {
  transition: transform 0.32s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.32s cubic-bezier(0.16, 1, 0.3, 1);
}
/* Leave (list pushed away): accelerate - exact reverse of enter curve */
.slide-left-leave-active {
  transition: transform 0.32s cubic-bezier(0.7, 0, 0.84, 0), opacity 0.32s cubic-bezier(0.7, 0, 0.84, 0);
}

.slide-left-enter-from {
  transform: translateX(-30%);
  opacity: 0;
}

.slide-left-leave-to {
  transform: translateX(-30%);
  opacity: 0;
}

/* Toolbar swap - fade transition for bulk actions overlay */
.toolbar-swap-enter-active,
.toolbar-swap-leave-active {
  transition: opacity 0.15s ease;
}
.toolbar-swap-enter-from,
.toolbar-swap-leave-to {
  opacity: 0;
}
</style>
