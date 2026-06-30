<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useAuthStore } from '@/stores/auth'
import { useMailboxStore } from '@/stores/mailbox'
import { useLabelsStore } from '@/stores/labels'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useToastStore } from '@/stores/toast'
import { useDriveStore } from '@/stores/drive'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useFiltersStore } from '@/stores/filters'
import { useNotificationsStore } from '@/stores/notifications'
import { useSettingsStore } from '@/stores/settings'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { useOAuthCallback } from '@/composables/useOAuthCallback'
import { isIOSNativePlatform } from '@/utils/platform'

// App Store Guideline 4/4.8: adding external accounts uses system-browser
// OAuth, so the "Add Account" entry and its Google/Microsoft buttons are
// hidden on native iOS (strict single org account).
const iosNative = isIOSNativePlatform()

const accountsStore = useAccountsStore()
const authStore = useAuthStore()
const mailboxStore = useMailboxStore()
const labelsStore = useLabelsStore()
const todosStore = useTodosStore()
const toast = useToastStore()
const driveStore = useDriveStore()
const calendarStore = useCalendarStore()
const filtersStore = useFiltersStore()
const notificationsStore = useNotificationsStore()
const settingsStore = useSettingsStore()
const themeStore = useThemeStore()
const layoutStore = useLayoutStore()

// Setup OAuth callback handler for sessionStorage fallback
const { checkOAuthFallback } = useOAuthCallback()

const showDropdown = ref(false)
const showAddModal = ref(false)
const testingConnection = ref(false)
const addingAccount = ref(false)
const connectingGoogle = ref(false)
const connectingMicrosoft = ref(false)
const detectingSettings = ref(false)
const selectedPreset = ref('custom')
const testResults = ref(null)
const detectedProvider = ref(null)

const form = ref({
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

// Include primary account in the list (only separate accounts for switching)
const allAccounts = computed(() => {
  const primaryAccount = {
    id: 'primary',
    account_email: authStore.userEmail,
    display_name: authStore.displayName,
    is_primary: true,
    is_default: accountsStore.separateAccounts.length === 0,
    account_type: 'primary',
  }
  return [primaryAccount, ...accountsStore.separateAccounts]
})

const currentAccount = computed(() => {
  if (!accountsStore.activeAccountId || accountsStore.activeAccountId === 'primary') {
    return allAccounts.value[0]
  }
  return allAccounts.value.find(a => a.id === parseInt(accountsStore.activeAccountId)) || allAccounts.value[0]
})

function getAccountInitials(account) {
  const name = account.display_name || account.account_email
  return name.substring(0, 2).toUpperCase()
}

// Accent color mapping (ID -> hex color)
const accentColorMap = {
  green: '#22c55e',
  red: '#ef4444',
  purple: '#a855f7',
  blue: '#3b82f6',
  gold: '#eab308',
  mono: '#404040',
  teal: '#14b8a6',
  orange: '#f97316',
  gradient: '#a855f7', // Use purple as fallback for gradient
}

// Reactive key to force re-render when account/theme changes
const avatarKey = ref(0)

// Watch for theme changes to update avatars
watch(() => themeStore.accentColor, () => {
  avatarKey.value++
})
watch(() => accountsStore.activeAccountId, () => {
  avatarKey.value++
})

// Get the stored accent color for a specific account
function getAccountAccentColor(account) {
  // Access avatarKey to create reactive dependency
  const _ = avatarKey.value
  const accountId = account.id === 'primary' ? 'primary' : account.id
  // Try per-account key first, then global fallback
  const accentId = localStorage.getItem(`webmail_accent_${accountId}`) 
    || localStorage.getItem('webmail_accent') 
    || 'green'
  return accentColorMap[accentId] || accentColorMap.green
}

// Get inline style for account avatar background
function getAccountAvatarStyle(account) {
  const color = getAccountAccentColor(account)
  return { backgroundColor: color }
}

async function switchAccount(account) {
  showDropdown.value = false
  
  const accountId = account.id === 'primary' ? 'primary' : account.id
  
  // Use the new switchAccount that refreshes all data
  await accountsStore.switchAccount(accountId, {
    mailbox: mailboxStore,
    labels: labelsStore,
    todos: todosStore,
    drive: driveStore,
    calendar: calendarStore,
    filters: filtersStore,
    notifications: notificationsStore,
    settings: settingsStore,
    theme: themeStore,
  })
  
  toast.success(`Switched to ${account.display_name || account.account_email}`)
}

function openAddModal() {
  showDropdown.value = false
  resetForm()
  showAddModal.value = true
}

function resetForm() {
  form.value = {
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
  testResults.value = null
  detectedProvider.value = null
}

// Auto-detect settings when email is entered
let detectTimeout = null
async function onEmailChange() {
  testResults.value = null
  
  // Auto-fill auto_label for linked accounts
  if (form.value.account_type === 'linked' && form.value.account_email) {
    form.value.auto_label = form.value.account_email.toLowerCase()
  }
  
  // Debounce the detection
  if (detectTimeout) clearTimeout(detectTimeout)
  
  const email = form.value.account_email
  if (!email || !email.includes('@')) {
    detectedProvider.value = null
    return
  }
  
  detectTimeout = setTimeout(async () => {
    detectingSettings.value = true
    const result = await accountsStore.detectSettings(email)
    detectingSettings.value = false
    
    if (result) {
      detectedProvider.value = result.provider
      
      // Only auto-fill if using custom preset
      if (selectedPreset.value === 'custom' && result.settings) {
        form.value.imap_host = result.settings.imap_host || ''
        form.value.imap_port = result.settings.imap_port || 993
        form.value.imap_encryption = result.settings.imap_encryption || 'ssl'
        form.value.smtp_host = result.settings.smtp_host || ''
        form.value.smtp_port = result.settings.smtp_port || 587
        form.value.smtp_encryption = result.settings.smtp_encryption || 'tls'
      }
    }
  }, 500)
}

function applyPreset(presetKey) {
  selectedPreset.value = presetKey
  const preset = accountsStore.presets[presetKey]
  if (preset) {
    form.value.imap_host = preset.imap_host
    form.value.imap_port = preset.imap_port
    form.value.imap_encryption = preset.imap_encryption
    form.value.smtp_host = preset.smtp_host
    form.value.smtp_port = preset.smtp_port
    form.value.smtp_encryption = preset.smtp_encryption
  }
}

async function testConnection() {
  if (!form.value.account_email || !form.value.password || !form.value.imap_host) {
    toast.warning('Please fill in email, password and IMAP host')
    return
  }
  
  testingConnection.value = true
  testResults.value = null
  
  const result = await accountsStore.testConnection(form.value)
  testingConnection.value = false
  testResults.value = result
  
  if (result.success) {
    toast.success('Connection successful')
  } else {
    toast.error(result.error || 'Connection failed')
  }
}

async function addAccount() {
  if (!form.value.account_email || !form.value.password || !form.value.imap_host) {
    toast.warning('Please fill in required fields')
    return
  }
  
  // Auto-fill SMTP host if not set
  if (!form.value.smtp_host) {
    form.value.smtp_host = form.value.imap_host.replace('imap.', 'smtp.')
  }
  
  addingAccount.value = true
  const result = await accountsStore.addAccount(form.value)
  addingAccount.value = false
  
  if (result.success) {
    toast.success('Account added successfully')
    showAddModal.value = false
    
    if (form.value.account_type === 'linked') {
      toast.info('Starting initial sync...')
      const syncResult = await accountsStore.triggerSync(result.account.id)
      if (syncResult.success) {
        const imported = syncResult.imported || syncResult.fetched || 0
        if (imported > 0) {
          toast.success(`Imported ${imported} emails from linked account into your inbox`)
        } else if (syncResult.fetched > 0) {
          toast.warning(`Fetched ${syncResult.fetched} emails but import pending - refreshing...`)
          await accountsStore.processQueue()
        } else {
          toast.info('No new emails to sync')
        }
        await mailboxStore.fetchFolders(true)
        await mailboxStore.fetchMessages('INBOX')
      } else {
        toast.error(syncResult.error || 'Initial sync failed')
      }
    } else {
      // For separate accounts, switch to it
      accountsStore.setActiveAccount(result.account.id)
      await mailboxStore.fetchFolders()
      await mailboxStore.fetchMessages('INBOX')
    }
  } else {
    toast.error(result.error || 'Failed to add account')
  }
}

// Google OAuth sign-in
async function signInWithGoogle() {
  connectingGoogle.value = true
  
  try {
    // Get the OAuth URL with account preferences
    const authUrl = await accountsStore.getGoogleAuthUrl({
      account_type: form.value.account_type,
      sync_frequency: form.value.sync_frequency,
      leave_on_server: form.value.leave_on_server,
      auto_label: form.value.auto_label,
    })
    
    if (!authUrl) {
      toast.error('Failed to get Google authorization URL')
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
      toast.error('Popup was blocked. Please allow popups for this site and try again.')
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
              toast.success(`Google account ${data.account_email || ''} connected successfully`)
              showAddModal.value = false
              await accountsStore.fetchAccounts()
              if (form.value.account_type === 'linked') {
                await mailboxStore.fetchMessages('INBOX')
              }
            } else if (data.error) {
              toast.error(`Google sign-in failed: ${data.error.replace(/_/g, ' ')}`)
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
      // Verify origin
      if (!event.origin.includes('flowone.pro')) return
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
        toast.success(`Google account ${account_email || ''} connected successfully`)
        showAddModal.value = false
        await accountsStore.fetchAccounts()
        
        if (form.value.account_type === 'linked') {
          await mailboxStore.fetchMessages('INBOX')
        }
      } else if (error) {
        toast.error(`Google sign-in failed: ${error.replace(/_/g, ' ')}`)
      }
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    // Check if popup was closed - also check sessionStorage for result
    const checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }
      if (!popup || popup.closed) {
        clearInterval(checkClosed)
        window.removeEventListener('message', handleOAuthMessage)
        
        // Check sessionStorage for result (popup might have stored it before closing)
        const handled = await checkSessionStorage()
        if (!handled) {
          connectingGoogle.value = false
          // Don't show error - user might have cancelled intentionally
        }
      }
    }, 500)
    
    // Timeout after 5 minutes
    setTimeout(() => {
      if (messageHandled) return
      clearInterval(checkClosed)
      window.removeEventListener('message', handleOAuthMessage)
      if (connectingGoogle.value) {
        connectingGoogle.value = false
        toast.warning('Google sign-in timed out')
      }
    }, 300000)
    
  } catch (e) {
    console.error('Google OAuth error:', e)
    toast.error('Failed to initiate Google sign-in')
    connectingGoogle.value = false
  }
}

// Microsoft OAuth sign-in
async function signInWithMicrosoft() {
  connectingMicrosoft.value = true
  
  try {
    // Get the OAuth URL with account preferences
    const authUrl = await accountsStore.getMicrosoftAuthUrl({
      account_type: form.value.account_type,
      sync_frequency: form.value.sync_frequency,
      leave_on_server: form.value.leave_on_server,
      auto_label: form.value.auto_label,
    })
    
    if (!authUrl) {
      toast.error('Failed to get Microsoft authorization URL')
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
      toast.error('Popup was blocked. Please allow popups for this site and try again.')
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
              toast.success(`Microsoft account ${data.account_email || ''} connected successfully`)
              showAddModal.value = false
              await accountsStore.fetchAccounts()
              if (form.value.account_type === 'linked') {
                await mailboxStore.fetchMessages('INBOX')
              }
            } else if (data.error) {
              toast.error(`Microsoft sign-in failed: ${data.error.replace(/_/g, ' ')}`)
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
      // Verify origin
      if (!event.origin.includes('flowone.pro')) return
      if (event.data?.type !== 'oauth_callback') return
      if (messageHandled) return
      
      messageHandled = true
      window.removeEventListener('message', handleOAuthMessage)
      clearInterval(checkClosed)
      connectingMicrosoft.value = false
      
      // Clear sessionStorage since we got the message directly
      sessionStorage.removeItem('oauth_callback_result')
      
      const { success, error, account_email } = event.data
      
      if (success) {
        toast.success(`Microsoft account ${account_email || ''} connected successfully`)
        showAddModal.value = false
        await accountsStore.fetchAccounts()
        
        if (form.value.account_type === 'linked') {
          await mailboxStore.fetchMessages('INBOX')
        }
      } else if (error) {
        toast.error(`Microsoft sign-in failed: ${error.replace(/_/g, ' ')}`)
      }
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    // Check if popup was closed - also check sessionStorage for result
    const checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }
      if (!popup || popup.closed) {
        clearInterval(checkClosed)
        window.removeEventListener('message', handleOAuthMessage)
        
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
      if (connectingMicrosoft.value) {
        connectingMicrosoft.value = false
        toast.warning('Microsoft sign-in timed out')
      }
    }, 300000)
    
  } catch (e) {
    console.error('Microsoft OAuth error:', e)
    toast.error('Failed to initiate Microsoft sign-in')
    connectingMicrosoft.value = false
  }
}

// Logout from an auxiliary (non-primary) account
async function logoutAuxiliaryAccount(e, account) {
  e.stopPropagation()
  
  if (account.is_primary) {
    toast.warning('Use the main Sign Out button to logout from primary account')
    return
  }
  
  const wasActive = currentAccount.value.id === account.id
  
  // Delete the account from backend and local state. Pick the endpoint from
  // the account object (OAuth vs IMAP live in separate tables, ids can collide).
  const result = await accountsStore.removeAccountByType(account)
  
  if (result) {
    toast.success(`Logged out of ${account.account_email}`)
    showDropdown.value = false
    
    // If we logged out of the active account, switch to primary
    if (wasActive) {
      await accountsStore.switchAccount('primary', {
        mailbox: mailboxStore,
        labels: labelsStore,
        todos: todosStore,
        drive: driveStore,
        calendar: calendarStore,
        filters: filtersStore,
        notifications: notificationsStore,
        settings: settingsStore,
        theme: themeStore,
      })
    }
  } else {
    toast.error('Failed to logout from account')
  }
}

// Sign out completely (all accounts, redirect to login)
function signOut() {
  showDropdown.value = false
  authStore.logout()
}

// Format last sync time
function formatLastSync(timestamp) {
  if (!timestamp) return 'Never'
  const date = new Date(timestamp)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  
  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  
  const diffHours = Math.floor(diffMins / 60)
  if (diffHours < 24) return `${diffHours}h ago`
  
  return date.toLocaleDateString()
}

// Convert a separate account to linked (sync into primary inbox)
async function convertToLinked(account) {
  const result = await accountsStore.updateAccount(account.id, {
    account_type: 'linked',
    auto_label: account.account_email.toLowerCase(),
    sync_enabled: true,
  })
  if (result.success) {
    // If we were viewing this account, switch back to primary first
    if (accountsStore.activeAccountId === account.id?.toString()) {
      await accountsStore.switchAccount('primary', {
        mailbox: mailboxStore,
        labels: labelsStore,
        todos: todosStore,
        drive: driveStore,
        calendar: calendarStore,
        filters: filtersStore,
        notifications: notificationsStore,
        settings: settingsStore,
        theme: themeStore,
      })
    }
    toast.success(`${account.account_email} converted to linked account`)
    await accountsStore.fetchAccounts()
    // Trigger initial sync
    toast.info('Starting sync...')
    const syncResult = await accountsStore.triggerSync(account.id)
    if (syncResult.success) {
      const imported = syncResult.imported || 0
      if (imported > 0) {
        toast.success(`Imported ${imported} emails into your inbox`)
      } else {
        toast.info('Sync complete, no new emails to import')
      }
      await mailboxStore.fetchFolders(true)
      await mailboxStore.fetchMessages('INBOX')
    }
  } else {
    toast.error(result.error || 'Failed to convert account')
  }
}

// Convert a linked account back to separate
async function convertToSeparate(account) {
  const result = await accountsStore.updateAccount(account.id, {
    account_type: 'separate',
    sync_enabled: false,
  })
  if (result.success) {
    toast.success(`${account.account_email} converted to separate account`)
    await accountsStore.fetchAccounts()
  } else {
    toast.error(result.error || 'Failed to convert account')
  }
}

// Trigger sync for a linked account
async function syncLinkedAccount(account) {
  const result = await accountsStore.triggerSync(account.id)
  if (result.success) {
    const imported = result.imported || 0
    const fetched = result.fetched || 0
    if (imported > 0) {
      toast.success(`Imported ${imported} new emails from ${account.account_email}`)
    } else if (fetched > 0) {
      toast.info(`Fetched ${fetched} emails, processing queue...`)
      await accountsStore.processQueue()
      toast.success('Queue processed')
    } else {
      toast.info(`No new emails from ${account.account_email}`)
    }
    await mailboxStore.fetchFolders(true)
    await mailboxStore.fetchMessages()
  } else {
    toast.error(result.error || 'Sync failed')
  }
}

onMounted(async () => {
  await accountsStore.fetchAccounts()
  // Check for OAuth callback result from sessionStorage (fallback when popup lost opener)
  await checkOAuthFallback()
})
</script>

<template>
  <div class="relative">
    <!-- Account Switcher Button -->
    <button 
      @click="showDropdown = !showDropdown"
      class="w-full flex items-center gap-3 p-3 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
    >
      <div 
        class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-medium"
        :style="getAccountAvatarStyle(currentAccount)"
      >
        {{ getAccountInitials(currentAccount) }}
      </div>
      <div class="flex-1 min-w-0 text-left">
        <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
          {{ currentAccount.display_name || currentAccount.account_email.split('@')[0] }}
        </p>
        <p class="text-xs text-surface-500 truncate">
          {{ currentAccount.account_email }}
        </p>
      </div>
      <span class="material-symbols-rounded text-surface-400">
        {{ showDropdown ? 'expand_less' : 'expand_more' }}
      </span>
    </button>
    
    <!-- Dropdown -->
    <Teleport to="body">
      <div 
        v-if="showDropdown" 
        class="fixed inset-0 z-50"
        @click="showDropdown = false"
      >
        <div 
          class="absolute top-16 left-4 w-72 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 overflow-hidden"
          @click.stop
        >
          <!-- Accounts List (Separate accounts for switching) -->
          <div class="max-h-48 overflow-y-auto">
            <div
              v-for="account in allAccounts"
              :key="account.id"
              @click="switchAccount(account)"
              :class="[
                'group w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors cursor-pointer',
                currentAccount.id === account.id ? 'bg-primary-50 dark:bg-primary-500/10' : ''
              ]"
            >
              <div 
                class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-medium shrink-0"
                :style="getAccountAvatarStyle(account)"
              >
                {{ getAccountInitials(account) }}
              </div>
              <div class="flex-1 min-w-0 text-left">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ account.display_name || account.account_email.split('@')[0] }}
                  <span v-if="account.is_primary" class="text-xs text-surface-400 ml-1">(primary)</span>
                </p>
                <p class="text-xs text-surface-500 truncate">{{ account.account_email }}</p>
              </div>
              <!-- Active indicator -->
              <span v-if="currentAccount.id === account.id" class="material-symbols-rounded text-primary-500 shrink-0">check</span>
              <!-- Convert to linked button for non-primary accounts -->
              <button
                v-if="!account.is_primary"
                @click.stop="convertToLinked(account)"
                class="p-1.5 rounded-full hover:bg-cyan-100 dark:hover:bg-cyan-900/30 text-surface-400 hover:text-cyan-500 transition-all shrink-0"
                title="Convert to linked (sync into primary inbox)"
              >
                <span class="material-symbols-rounded text-lg">link</span>
              </button>
              <!-- Logout button for non-primary accounts -->
              <button
                v-if="!account.is_primary"
                @click="logoutAuxiliaryAccount($event, account)"
                class="p-1.5 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-400 hover:text-red-500 transition-all shrink-0"
                title="Logout from this account"
              >
                <span class="material-symbols-rounded text-lg">logout</span>
              </button>
            </div>
          </div>
          
          <!-- Linked Accounts Section -->
          <div v-if="accountsStore.linkedAccounts.length > 0" class="border-t border-surface-200 dark:border-surface-700">
            <div class="px-4 py-2 flex items-center gap-2 text-xs text-surface-500 uppercase tracking-wide">
              <span class="material-symbols-rounded text-sm">link</span>
              Linked Accounts
            </div>
            <div
              v-for="account in accountsStore.linkedAccounts"
              :key="'linked-' + account.id"
              class="group w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              <div 
                class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-medium shrink-0 opacity-75"
                :style="getAccountAvatarStyle(account)"
              >
                {{ getAccountInitials(account) }}
              </div>
              <div class="flex-1 min-w-0 text-left">
                <p class="text-sm text-surface-700 dark:text-surface-300 truncate">
                  {{ account.display_name || account.account_email.split('@')[0] }}
                </p>
                <p class="text-xs text-surface-400 truncate">
                  {{ account.sync_enabled ? 'Syncing' : 'Paused' }}
                  <span v-if="account.last_sync" class="ml-1">- Last: {{ formatLastSync(account.last_sync) }}</span>
                </p>
              </div>
              <!-- Sync button -->
              <button
                @click.stop="syncLinkedAccount(account)"
                :disabled="accountsStore.syncing"
                class="p-1.5 rounded-full hover:bg-primary-100 dark:hover:bg-primary-900/30 text-surface-400 hover:text-primary-500 transition-all shrink-0"
                title="Sync now"
              >
                <span :class="['material-symbols-rounded text-lg', accountsStore.syncing ? 'animate-spin' : '']">sync</span>
              </button>
              <!-- Convert to separate -->
              <button
                @click.stop="convertToSeparate(account)"
                class="p-1.5 rounded-full hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-all shrink-0"
                title="Convert to separate account"
              >
                <span class="material-symbols-rounded text-lg">link_off</span>
              </button>
              <!-- Remove linked account -->
              <button
                @click.stop="logoutAuxiliaryAccount($event, account)"
                class="p-1.5 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-400 hover:text-red-500 transition-all shrink-0"
                title="Remove linked account"
              >
                <span class="material-symbols-rounded text-lg">close</span>
              </button>
            </div>
          </div>
          
          <!-- Add Account -->
          <div class="border-t border-surface-200 dark:border-surface-700 mt-2 pt-2">
            <button
              v-if="!iosNative"
              @click="openAddModal"
              class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-primary-500"
            >
              <span class="material-symbols-rounded">add</span>
              <span class="text-sm font-medium">Add Account</span>
            </button>
            
            <!-- Sign Out -->
            <button
              @click="signOut"
              class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500"
            >
              <span class="material-symbols-rounded">logout</span>
              <span class="text-sm font-medium">Sign Out</span>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Add Account Modal -->
    <Teleport to="body">
      <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="w-full max-w-lg bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Add Email Account</h3>
            <button @click="showAddModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">
            <!-- Account Type Toggle (First - applies to both Google and manual) -->
            <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">
                How should this account work?
              </label>
              <div class="flex gap-3">
                <button
                  @click="form.account_type = 'separate'; form.auto_label = ''"
                  :class="[
                    'flex-1 p-3 rounded-lg border-2 transition-all text-left',
                    form.account_type === 'separate' 
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' 
                      : 'border-surface-200 dark:border-surface-600 hover:border-surface-300'
                  ]"
                >
                  <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-rounded text-lg" :class="form.account_type === 'separate' ? 'text-primary-500' : 'text-surface-400'">swap_horiz</span>
                    <span class="font-medium text-sm">Separate</span>
                  </div>
                  <p class="text-xs text-surface-500">Switch between accounts. Each has its own inbox.</p>
                </button>
                <button
                  @click="form.account_type = 'linked'; form.auto_label = form.account_email?.toLowerCase() || ''"
                  :class="[
                    'flex-1 p-3 rounded-lg border-2 transition-all text-left',
                    form.account_type === 'linked' 
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' 
                      : 'border-surface-200 dark:border-surface-600 hover:border-surface-300'
                  ]"
                >
                  <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-rounded text-lg" :class="form.account_type === 'linked' ? 'text-primary-500' : 'text-surface-400'">link</span>
                    <span class="font-medium text-sm">Linked</span>
                  </div>
                  <p class="text-xs text-surface-500">Sync emails into your main inbox. Like Gmail's POP fetch.</p>
                </button>
              </div>
            </div>
            
            <!-- Provider Presets -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Email Provider
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
                  <span v-else>Gmail</span>
                </button>
                
                <!-- Microsoft OAuth Button -->
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
                  <span v-else>Outlook</span>
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
            
            <!-- Credentials -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Email Address
                </label>
                <div class="relative">
                  <input 
                    v-model="form.account_email" 
                    @input="onEmailChange"
                    type="email" 
                    class="input pr-10" 
                    placeholder="your@email.com" 
                  />
                  <div class="absolute right-3 top-1/2 -translate-y-1/2">
                    <span v-if="detectingSettings" class="spinner w-4 h-4"></span>
                    <span v-else-if="detectedProvider" class="material-symbols-rounded text-green-500 text-lg" :title="'Detected: ' + detectedProvider">check_circle</span>
                  </div>
                </div>
                <p v-if="detectedProvider" class="text-xs text-green-600 dark:text-green-400 mt-1">
                  Detected: {{ detectedProvider }} - settings auto-filled
                </p>
              </div>
              
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Password / App Password
                </label>
                <input v-model="form.password" type="password" class="input" placeholder="Password" />
                <p class="text-xs text-surface-500 mt-1">For Gmail/Yahoo with 2FA, use an App Password</p>
              </div>
              
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Display Name (optional)
                </label>
                <input v-model="form.display_name" type="text" class="input" placeholder="My Work Account" />
              </div>
            </div>
            
            <!-- Server Settings -->
            <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
              <p class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">Server Settings</p>
              
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs text-surface-500 mb-1">IMAP Host</label>
                  <input v-model="form.imap_host" type="text" class="input text-sm" placeholder="imap.example.com" />
                </div>
                <div class="flex gap-2">
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">Port</label>
                    <input v-model="form.imap_port" type="number" class="input text-sm" />
                  </div>
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">Encryption</label>
                    <select v-model="form.imap_encryption" class="input text-sm">
                      <option value="ssl">SSL</option>
                      <option value="tls">TLS</option>
                      <option value="none">None</option>
                    </select>
                  </div>
                </div>
                
                <div>
                  <label class="block text-xs text-surface-500 mb-1">SMTP Host</label>
                  <input v-model="form.smtp_host" type="text" class="input text-sm" placeholder="smtp.example.com" />
                </div>
                <div class="flex gap-2">
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">Port</label>
                    <input v-model="form.smtp_port" type="number" class="input text-sm" />
                  </div>
                  <div class="flex-1">
                    <label class="block text-xs text-surface-500 mb-1">Encryption</label>
                    <select v-model="form.smtp_encryption" class="input text-sm">
                      <option value="ssl">SSL</option>
                      <option value="tls">TLS</option>
                      <option value="none">None</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Linked Account Options -->
            <div v-if="form.account_type === 'linked'" class="pt-4 border-t border-surface-200 dark:border-surface-700">
              <p class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">Sync Options</p>
              
              <div class="space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <label class="block text-sm text-surface-700 dark:text-surface-300">Sync Frequency</label>
                    <p class="text-xs text-surface-500">How often to check for new emails</p>
                  </div>
                  <select v-model="form.sync_frequency" class="input w-32 text-sm">
                    <option :value="5">5 minutes</option>
                    <option :value="15">15 minutes</option>
                    <option :value="30">30 minutes</option>
                    <option :value="60">1 hour</option>
                  </select>
                </div>
                
                <div class="flex items-center justify-between">
                  <div>
                    <label class="block text-sm text-surface-700 dark:text-surface-300">Leave on Server</label>
                    <p class="text-xs text-surface-500">Keep emails on the source server after sync</p>
                  </div>
                  <button
                    @click="form.leave_on_server = !form.leave_on_server"
                    :class="[
                      'relative w-11 h-6 rounded-full transition-colors',
                      form.leave_on_server ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span
                      :class="[
                        'absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform',
                        form.leave_on_server ? 'translate-x-5' : 'translate-x-0.5'
                      ]"
                    ></span>
                  </button>
                </div>
                
                <div>
                  <label class="block text-sm text-surface-700 dark:text-surface-300 mb-1">Auto Label</label>
                  <div class="flex items-center gap-2 p-2.5 rounded-xl bg-surface-100 dark:bg-surface-700 border border-surface-200 dark:border-surface-600">
                    <span class="material-symbols-rounded text-cyan-500 text-lg">label</span>
                    <span class="text-sm text-surface-700 dark:text-surface-300 font-medium">
                      {{ form.auto_label || form.account_email || 'email@domain.com' }}
                    </span>
                  </div>
                  <p class="text-xs text-surface-500 mt-1">All synced emails will be automatically labeled with the account address</p>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Test Results Panel -->
          <div v-if="testResults" class="px-6 py-3 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900">
            <div class="flex items-center gap-4">
              <!-- IMAP Result -->
              <div class="flex items-center gap-2">
                <span 
                  :class="[
                    'material-symbols-rounded text-lg',
                    testResults.imap?.success ? 'text-green-500' : 'text-red-500'
                  ]"
                >
                  {{ testResults.imap?.success ? 'check_circle' : 'cancel' }}
                </span>
                <span class="text-sm">
                  IMAP {{ testResults.imap?.success ? 'OK' : 'Failed' }}
                  <span v-if="testResults.imap?.success && testResults.imap?.folders_count" class="text-surface-500">
                    ({{ testResults.imap.folders_count }} folders)
                  </span>
                </span>
              </div>
              
              <!-- SMTP Result -->
              <div v-if="testResults.smtp?.tested" class="flex items-center gap-2">
                <span 
                  :class="[
                    'material-symbols-rounded text-lg',
                    testResults.smtp?.success ? 'text-green-500' : 'text-amber-500'
                  ]"
                >
                  {{ testResults.smtp?.success ? 'check_circle' : 'warning' }}
                </span>
                <span class="text-sm">
                  SMTP {{ testResults.smtp?.success ? 'OK' : 'Failed' }}
                </span>
              </div>
              <div v-else class="flex items-center gap-2 text-surface-400">
                <span class="material-symbols-rounded text-lg">remove_circle_outline</span>
                <span class="text-sm">SMTP not tested</span>
              </div>
            </div>
            
            <!-- Error details -->
            <p v-if="testResults.imap?.error" class="text-xs text-red-500 mt-2">
              IMAP: {{ testResults.imap.error }}
            </p>
            <p v-if="testResults.smtp?.error" class="text-xs text-amber-500 mt-1">
              SMTP: {{ testResults.smtp.error }}
            </p>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-between">
            <button @click="testConnection" class="btn-secondary" :disabled="testingConnection">
              <span v-if="testingConnection" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded">wifi_tethering</span>
              {{ testingConnection ? 'Testing...' : 'Test Connection' }}
            </button>
            <div class="flex gap-2">
              <button @click="showAddModal = false" class="btn-ghost">Cancel</button>
              <button @click="addAccount" class="btn-primary" :disabled="addingAccount || !testResults?.success">
                <span v-if="addingAccount" class="spinner w-4 h-4"></span>
                <span v-else class="material-symbols-rounded">add</span>
                Add Account
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

