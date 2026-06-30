<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useOAuthCallback } from '@/composables/useOAuthCallback'
import { useAddons } from '@/composables/useAddons'
import api from '@/services/api'
import { isIOSNativePlatform } from '@/utils/platform'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'

// App Store Guideline 4/4.8: Google/Microsoft OAuth opens the system browser
// on native iOS, so these connect buttons are hidden there (the whole Linked
// Accounts tab is also gated out of Settings on iOS).
const iosNative = isIOSNativePlatform()

const accountsStore = useAccountsStore()
const auth = useAuthStore()
const toast = useToastStore()
const calendarStore = useCalendarStore()
const { calendarEnabled } = useAddons()

// OAuth callback handler for sessionStorage fallback
const { checkOAuthFallback } = useOAuthCallback()

// Loading states
const loading = ref(true)
const connectingGoogleCalendar = ref(false)
const removingAccount = ref(null)
const syncingAccountId = ref(null)

// Add Account Modal
const showAddAccountModal = ref(false)
const testingConnection = ref(false)
const addingAccount = ref(false)
const connectingGoogle = ref(false)
const connectingMicrosoft = ref(false)
const selectedPreset = ref('custom')

const addAccountForm = ref({
  account_email: '',
  password: '',
  display_name: '',
  account_type: 'separate',
  imap_host: '',
  imap_port: 993,
  imap_encryption: 'ssl',
  smtp_host: '',
  smtp_port: 465,
  smtp_encryption: 'ssl',
  sync_frequency: 15,
  leave_on_server: true,
  auto_label: '',
})

// Account history
const accountHistory = ref([])
const reconnectingHistoryId = ref(null) // Track history entry being reconnected

// Modals
const showRemoveAccountModal = ref(false)
const showDesyncModal = ref(false)
const accountToRemove = ref(null)
const calendarToDesync = ref(null)
const desyncDeleteEvents = ref(false)
const syncedEventsCount = ref(0)

// Calendar connections
const calendarConnections = ref([])

// Check if there are any synced calendars
const hasSyncedCalendars = computed(() => {
  // Check calendar-only connections
  const hasCalendarConnSync = calendarConnections.value.some(conn => conn.synced_calendars?.length > 0)
  // Check Google OAuth calendar syncs
  const hasGoogleSync = calendarStore.syncConfigs?.length > 0
  // Check Microsoft OAuth calendar syncs
  const hasMicrosoftSync = calendarStore.microsoftSyncConfigs?.length > 0
  
  return hasCalendarConnSync || hasGoogleSync || hasMicrosoftSync
})

// Calendar sync per account
const expandedAccounts = ref({}) // Track which accounts have calendar panel open
const expandedCalendarConns = ref({}) // Track which calendar-only connections have panel open
const loadingCalendars = ref({}) // Track loading state per account
const googleCalendars = ref({}) // Store calendars per account ID
const microsoftCalendars = ref({}) // Store Microsoft calendars per account ID
const calendarConnCalendars = ref({}) // Store calendars for calendar-only connections
const selectedCalendars = ref({}) // Selected calendars per account
const selectedCalendarConnCals = ref({}) // Selected calendars for calendar-only connections
const selectedLocalCalendar = ref(null)

// Get account calendars based on provider
function getAccountCalendars(accountId, provider) {
  if (provider === 'microsoft') {
    return microsoftCalendars.value[accountId]
  }
  return googleCalendars.value[accountId]
}

// Toggle account calendar panel
function toggleAccountCalendars(accountId) {
  expandedAccounts.value[accountId] = !expandedAccounts.value[accountId]
  if (expandedAccounts.value[accountId]) {
    // Find the account to get provider
    const account = oauthAccounts.value.find(a => a.id === accountId)
    if (account && !getAccountCalendars(accountId, account.provider)?.length) {
      loadAccountCalendars(accountId, account.provider)
    }
  }
}

// Load calendars for a specific account
async function loadAccountCalendars(accountId, provider = 'google') {
  loadingCalendars.value[accountId] = true
  try {
    if (provider === 'microsoft') {
      const calendars = await calendarStore.fetchMicrosoftCalendars(accountId)
      microsoftCalendars.value[accountId] = calendars
      await calendarStore.fetchMicrosoftSyncConfigs(accountId)
    } else {
      const calendars = await calendarStore.fetchGoogleCalendars(accountId)
      googleCalendars.value[accountId] = calendars
      await calendarStore.fetchSyncConfigs(accountId)
    }
  } catch (e) {
    console.error(`Failed to load ${provider} calendars:`, e)
    toast.error(`Failed to load ${provider} calendars`)
  } finally {
    loadingCalendars.value[accountId] = false
  }
}

// Check if OAuth calendar is synced
function isOAuthCalendarSynced(account, calendarId) {
  if (account.provider === 'microsoft') {
    return calendarStore.isMicrosoftCalendarSynced(calendarId)
  }
  return calendarStore.isGoogleCalendarSynced(calendarId)
}

// Sync OAuth calendar
async function syncOAuthCalendar(account, calendarId) {
  loadingCalendars.value[account.id] = true
  try {
    let result
    if (account.provider === 'microsoft') {
      result = await calendarStore.syncFromMicrosoft(account.id, calendarId)
    } else {
      result = await calendarStore.syncFromGoogle(account.id, calendarId)
    }
    if (result?.success) {
      const { imported, updated } = result
      if (imported > 0 || updated > 0) {
        toast.success(`Synced: ${imported || 0} imported, ${updated || 0} updated`)
      } else {
        toast.info('Calendar is up to date')
      }
    } else {
      toast.error(result?.error || 'Sync failed')
    }
  } catch (e) {
    toast.error('Sync failed')
  } finally {
    loadingCalendars.value[account.id] = false
  }
}

// Disable OAuth calendar sync
function disableOAuthCalendarSync(account, calendarId) {
  showDesyncOptions(account.id, calendarId, account.provider === 'microsoft' ? 'microsoft_oauth' : 'oauth')
}

// Setup sync for selected OAuth calendars
async function setupOAuthCalendarSync(account) {
  const selected = selectedCalendars.value[account.id] || []
  if (selected.length === 0 || !selectedLocalCalendar.value) {
    toast.error('Please select calendars and a local calendar')
    return
  }
  
  loadingCalendars.value[account.id] = true
  try {
    // ONE request for all selected calendars regardless of provider.
    // Server skips already-synced entries (already_synced counter).
    const result = account.provider === 'microsoft'
      ? await calendarStore.bulkSetupMicrosoftSync(account.id, [...selected], selectedLocalCalendar.value)
      : await calendarStore.bulkSetupGoogleSync(account.id, [...selected], selectedLocalCalendar.value)

    const configured = result.configured || 0
    if (result.success && configured > 0) {
      toast.success(`Enabled sync for ${configured} calendar(s)`)
      selectedCalendars.value[account.id] = []
      await loadAccountCalendars(account.id, account.provider)
    } else if (result.success && configured === 0 && (result.alreadySynced || 0) > 0) {
      toast.info('All selected calendars were already synced')
      selectedCalendars.value[account.id] = []
    } else if (!result.success) {
      toast.error(result.error || 'Failed to enable calendar sync')
    }
  } catch (e) {
    console.error('Failed to setup sync:', e)
    toast.error('Failed to enable calendar sync')
  } finally {
    loadingCalendars.value[account.id] = false
  }
}

// Toggle calendar selection
function toggleCalendarSelection(accountId, calendarId) {
  if (!selectedCalendars.value[accountId]) {
    selectedCalendars.value[accountId] = []
  }
  const index = selectedCalendars.value[accountId].indexOf(calendarId)
  if (index > -1) {
    selectedCalendars.value[accountId].splice(index, 1)
  } else {
    selectedCalendars.value[accountId].push(calendarId)
  }
}

// Check if calendar is selected
function isCalendarSelected(accountId, calendarId) {
  return selectedCalendars.value[accountId]?.includes(calendarId)
}

// Setup sync for selected calendars
async function setupCalendarSync(accountId) {
  const selected = selectedCalendars.value[accountId] || []
  if (selected.length === 0 || !selectedLocalCalendar.value) {
    toast.error('Please select calendars and a local calendar')
    return
  }
  
  loadingCalendars.value[accountId] = true
  try {
    const result = await calendarStore.bulkSetupGoogleSync(
      accountId,
      [...selected],
      selectedLocalCalendar.value
    )
    const configured = result.configured || 0
    if (result.success && configured > 0) {
      toast.success(`Enabled sync for ${configured} calendar(s)`)
      selectedCalendars.value[accountId] = []
      await loadAccountCalendars(accountId)
    } else if (result.success && configured === 0 && (result.alreadySynced || 0) > 0) {
      toast.info('All selected calendars were already synced')
      selectedCalendars.value[accountId] = []
    } else if (!result.success) {
      toast.error(result.error || 'Failed to enable calendar sync')
    }
  } catch (e) {
    console.error('Failed to setup sync:', e)
    toast.error('Failed to enable calendar sync')
  } finally {
    loadingCalendars.value[accountId] = false
  }
}

// Sync now from Google Calendar
async function syncFromGoogle(accountId, googleCalendarId) {
  loadingCalendars.value[accountId] = true
  try {
    await calendarStore.pullFromGoogleCalendar(accountId, googleCalendarId)
    toast.success('Calendar synced')
  } catch (e) {
    toast.error('Sync failed')
  } finally {
    loadingCalendars.value[accountId] = false
  }
}

// Disable calendar sync
async function disableCalendarSync(accountId, googleCalendarId) {
  showDesyncOptions(accountId, googleCalendarId, 'oauth')
}

// ============= Calendar-Only Connection Functions =============

// Toggle calendar-only connection panel
function toggleCalendarConnPanel(connId) {
  expandedCalendarConns.value[connId] = !expandedCalendarConns.value[connId]
  if (expandedCalendarConns.value[connId] && !calendarConnCalendars.value[connId]) {
    loadCalendarConnCalendars(connId)
  }
}

// Load calendars for a calendar-only connection
async function loadCalendarConnCalendars(connId) {
  loadingCalendars.value['conn_' + connId] = true
  try {
    const response = await api.get('/calendar/connections/calendars', {
      params: { connection_id: connId }
    })
    if (response.data.success) {
      calendarConnCalendars.value[connId] = response.data.data.calendars || []
    }
  } catch (e) {
    console.error('Failed to load calendars for connection:', e)
    toast.error('Failed to load calendars')
  } finally {
    loadingCalendars.value['conn_' + connId] = false
  }
}

// Toggle calendar selection for calendar-only connection
function toggleCalendarConnSelection(connId, calendarId) {
  if (!selectedCalendarConnCals.value[connId]) {
    selectedCalendarConnCals.value[connId] = []
  }
  const index = selectedCalendarConnCals.value[connId].indexOf(calendarId)
  if (index > -1) {
    selectedCalendarConnCals.value[connId].splice(index, 1)
  } else {
    selectedCalendarConnCals.value[connId].push(calendarId)
  }
}

// Check if calendar is selected for calendar-only connection
function isCalendarConnSelected(connId, calendarId) {
  return selectedCalendarConnCals.value[connId]?.includes(calendarId)
}

// Setup sync for calendar-only connection
async function setupCalendarConnSync(connId) {
  const selected = selectedCalendarConnCals.value[connId] || []
  if (selected.length === 0 || !selectedLocalCalendar.value) {
    toast.error('Please select calendars and a local calendar')
    return
  }
  
  loadingCalendars.value['conn_' + connId] = true
  try {
    // ONE setup + ONE pull instead of 2N sequential requests.
    const setup = await calendarStore.bulkSetupConnectionSync(
      connId,
      [...selected],
      selectedLocalCalendar.value
    )
    const configured = setup.configured || 0
    if (setup.success && configured > 0) {
      toast.success(`Enabled sync for ${configured} calendar(s). Syncing events...`)
      selectedCalendarConnCals.value[connId] = []
      await loadData()

      const pull = await calendarStore.bulkSyncFromConnection(connId, [...selected])
      if (pull.success && pull.imported > 0) {
        toast.success(`Imported ${pull.imported} event(s)`)
      }
    } else if (!setup.success) {
      toast.error(setup.error || 'Failed to enable calendar sync')
    }
  } catch (e) {
    console.error('Failed to setup sync:', e)
    toast.error('Failed to enable calendar sync')
  } finally {
    loadingCalendars.value['conn_' + connId] = false
  }
}

// Sync now from calendar-only connection
async function syncCalendarConnFromGoogle(connId, googleCalendarId) {
  loadingCalendars.value['conn_' + connId] = true
  try {
    const response = await api.post('/calendar/connections/sync/pull', {
      connection_id: connId,
      google_calendar_id: googleCalendarId
    })
    console.log('[Calendar Sync] Response:', response.data)
    if (response.data.success) {
      const { imported, updated, errors } = response.data.data || {}
      if (errors?.length) {
        toast.warning(`Sync completed with errors: ${errors[0]}`)
      } else if (imported > 0 || updated > 0) {
        toast.success(`Synced: ${imported || 0} imported, ${updated || 0} updated`)
      } else {
        toast.info('Calendar is up to date (0 new events)')
      }
      await fetchCalendarConnections()
      await calendarStore.fetchEvents()
    } else {
      toast.error(response.data.message || 'Sync failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Sync failed')
  } finally {
    loadingCalendars.value['conn_' + connId] = false
  }
}

// Check if calendar is already synced for calendar-only connection
function isCalendarConnSynced(conn, calendarId) {
  return conn.synced_calendars?.some(s => s.google_calendar_id === calendarId)
}

// Get synced events count for a calendar
function getCalendarConnSyncedCount(conn, calendarId) {
  const sync = conn.synced_calendars?.find(s => s.google_calendar_id === calendarId)
  return sync?.synced_events_count || 0
}

// Check if all unsynced calendars are selected
function areAllCalendarsSelected(conn) {
  const calendars = calendarConnCalendars.value[conn.id] || []
  const unsyncedCalendars = calendars.filter(cal => !isCalendarConnSynced(conn, cal.id))
  if (unsyncedCalendars.length === 0) return false
  const selected = selectedCalendarConnCals.value[conn.id] || []
  return unsyncedCalendars.every(cal => selected.includes(cal.id))
}

// Toggle all unsynced calendars selection
function toggleAllCalendarConn(connId) {
  const conn = calendarConnections.value.find(c => c.id === connId)
  if (!conn) return
  
  const calendars = calendarConnCalendars.value[connId] || []
  const unsyncedCalendars = calendars.filter(cal => !isCalendarConnSynced(conn, cal.id))
  
  if (areAllCalendarsSelected(conn)) {
    // Deselect all
    selectedCalendarConnCals.value[connId] = []
  } else {
    // Select all unsynced
    selectedCalendarConnCals.value[connId] = unsyncedCalendars.map(cal => cal.id)
  }
}

// ============= Add Account Modal Functions =============

const accountPresets = {
  gmail: { imap_host: 'imap.gmail.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.gmail.com', smtp_port: 465, smtp_encryption: 'ssl' },
  outlook: { imap_host: 'outlook.office365.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.office365.com', smtp_port: 465, smtp_encryption: 'ssl' },
  hotmail: { imap_host: 'outlook.office365.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.office365.com', smtp_port: 465, smtp_encryption: 'ssl' },
  yahoo: { imap_host: 'imap.mail.yahoo.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.mail.yahoo.com', smtp_port: 465, smtp_encryption: 'ssl' },
  icloud: { imap_host: 'imap.mail.me.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.mail.me.com', smtp_port: 465, smtp_encryption: 'ssl' },
  devcon1: { imap_host: 'mail.devcon1.hu', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'mail.devcon1.hu', smtp_port: 465, smtp_encryption: 'ssl' },
  custom: { imap_host: '', imap_port: 993, imap_encryption: 'ssl', smtp_host: '', smtp_port: 465, smtp_encryption: 'ssl' },
}

function applyPreset(presetKey) {
  selectedPreset.value = presetKey
  const preset = accountPresets[presetKey]
  if (preset) {
    addAccountForm.value.imap_host = preset.imap_host
    addAccountForm.value.imap_port = preset.imap_port
    addAccountForm.value.imap_encryption = preset.imap_encryption
    addAccountForm.value.smtp_host = preset.smtp_host
    addAccountForm.value.smtp_port = preset.smtp_port
    addAccountForm.value.smtp_encryption = preset.smtp_encryption
  }
}

function resetAddAccountForm() {
  addAccountForm.value = {
    account_email: '',
    password: '',
    display_name: '',
    account_type: 'separate',
    imap_host: '',
    imap_port: 993,
    imap_encryption: 'ssl',
    smtp_host: '',
    smtp_port: 465,
    smtp_encryption: 'ssl',
    sync_frequency: 15,
    leave_on_server: true,
    auto_label: '',
  }
  selectedPreset.value = 'custom'
  reconnectingHistoryId.value = null // Clear reconnection tracking
}

async function testConnection() {
  if (!addAccountForm.value.account_email || !addAccountForm.value.password) {
    toast.error('Please enter email and password')
    return
  }
  
  testingConnection.value = true
  try {
    const result = await accountsStore.testConnection(addAccountForm.value)
    if (result.success) {
      toast.success('Connection successful')
    } else {
      toast.error(result.error || 'Connection failed')
    }
  } catch (e) {
    toast.error('Connection test failed')
  } finally {
    testingConnection.value = false
  }
}

async function addNewAccount() {
  if (!addAccountForm.value.account_email || !addAccountForm.value.password) {
    toast.error('Please enter email and password')
    return
  }
  
  addingAccount.value = true
  try {
    const result = await accountsStore.addAccount(addAccountForm.value)
    if (result.success) {
      toast.success('Account added successfully')
      showAddAccountModal.value = false
      
      // Remove from history if this was a reconnection
      if (reconnectingHistoryId.value) {
        await deleteHistoryEntry(reconnectingHistoryId.value)
        reconnectingHistoryId.value = null
      }
      
      resetAddAccountForm()
      await loadData()
    } else {
      toast.error(result.error || 'Failed to add account')
    }
  } catch (e) {
    toast.error('Failed to add account')
  } finally {
    addingAccount.value = false
  }
}

async function connectGoogleOAuth() {
  connectingGoogle.value = true
  
  try {
    const response = await api.get('/auth/google', {
      params: {
        account_type: addAccountForm.value.account_type,
        sync_frequency: addAccountForm.value.sync_frequency,
        leave_on_server: addAccountForm.value.leave_on_server ? 1 : 0,
        auto_label: addAccountForm.value.auto_label || '',
      }
    })
    
    if (!response.data.success || !response.data.data.auth_url) {
      toast.error('Failed to get Google authorization URL')
      connectingGoogle.value = false
      return
    }
    
    const authUrl = response.data.data.auth_url
    const width = 500, height = 600
    const left = window.screenX + (window.outerWidth - width) / 2
    const top = window.screenY + (window.outerHeight - height) / 2
    
    const popup = window.open(authUrl, 'google-oauth', `width=${width},height=${height},left=${left},top=${top},popup=1`)
    
    let messageHandled = false
    let checkClosed = null
    
    const handleOAuthMessage = async (event) => {
      // Phase 2.3: validate event.origin to defeat cross-origin spoofing.
      // The OAuth callback page is served from the same origin as the
      // frontend (flowone.pro), so any other origin is rejected.
      if (event.origin !== window.location.origin) return
      if (event.data?.type !== 'oauth_callback' || event.data?.provider !== 'google') return
      if (messageHandled) return
      
      messageHandled = true
      window.removeEventListener('message', handleOAuthMessage)
      if (checkClosed) clearInterval(checkClosed)
      connectingGoogle.value = false
      
      if (event.data.success) {
        toast.success(`Google account ${event.data.account_email || ''} connected!`)
        showAddAccountModal.value = false
        resetAddAccountForm()
        await loadData()
      } else if (event.data.error) {
        toast.error(`Google sign-in failed: ${event.data.error.replace(/_/g, ' ')}`)
      }
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }
      
      // Check sessionStorage fallback
      try {
        const stored = sessionStorage.getItem('oauth_callback_result')
        if (stored) {
          const data = JSON.parse(stored)
          if (data.type === 'oauth_callback' && data.provider === 'google') {
            sessionStorage.removeItem('oauth_callback_result')
            messageHandled = true
            clearInterval(checkClosed)
            window.removeEventListener('message', handleOAuthMessage)
            connectingGoogle.value = false
            if (data.success) {
              toast.success(`Google account ${data.account_email || ''} connected!`)
              showAddAccountModal.value = false
              resetAddAccountForm()
              await loadData()
            } else if (data.error) {
              toast.error(`Google sign-in failed: ${data.error.replace(/_/g, ' ')}`)
            }
            return
          }
        }
      } catch { /* ignore */ }
      
      try {
        if (!popup || popup.closed) {
          clearInterval(checkClosed)
          setTimeout(() => {
            if (!messageHandled) {
              window.removeEventListener('message', handleOAuthMessage)
              connectingGoogle.value = false
            }
          }, 300)
        }
      } catch {
        // COOP blocks popup.closed - keep polling sessionStorage
      }
    }, 500)
    
    setTimeout(() => {
      if (checkClosed) clearInterval(checkClosed)
      window.removeEventListener('message', handleOAuthMessage)
      connectingGoogle.value = false
    }, 300000)
    
  } catch (e) {
    console.error('Google OAuth error:', e)
    toast.error('Failed to initiate Google sign-in')
    connectingGoogle.value = false
  }
}

async function connectMicrosoftOAuth() {
  connectingMicrosoft.value = true
  
  try {
    const response = await api.get('/auth/microsoft', {
      params: {
        account_type: addAccountForm.value.account_type,
        sync_frequency: addAccountForm.value.sync_frequency,
        leave_on_server: addAccountForm.value.leave_on_server ? 1 : 0,
        auto_label: addAccountForm.value.auto_label || '',
      }
    })
    
    if (!response.data.success || !response.data.data.auth_url) {
      toast.error('Failed to get Microsoft authorization URL')
      connectingMicrosoft.value = false
      return
    }
    
    const authUrl = response.data.data.auth_url
    const width = 500, height = 600
    const left = window.screenX + (window.outerWidth - width) / 2
    const top = window.screenY + (window.outerHeight - height) / 2
    
    const popup = window.open(authUrl, 'microsoft-oauth', `width=${width},height=${height},left=${left},top=${top},popup=1`)
    
    let messageHandled = false
    let checkClosed = null
    
    const handleOAuthMessage = async (event) => {
      // Phase 2.3: validate event.origin (same fix as Google flow above)
      if (event.origin !== window.location.origin) return
      if (event.data?.type !== 'oauth_callback' || event.data?.provider !== 'microsoft') return
      if (messageHandled) return
      
      messageHandled = true
      window.removeEventListener('message', handleOAuthMessage)
      if (checkClosed) clearInterval(checkClosed)
      connectingMicrosoft.value = false
      
      if (event.data.success) {
        toast.success(`Microsoft account ${event.data.account_email || ''} connected!`)
        showAddAccountModal.value = false
        resetAddAccountForm()
        await loadData()
      } else if (event.data.error) {
        toast.error(`Microsoft sign-in failed: ${event.data.error.replace(/_/g, ' ')}`)
      }
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }
      
      try {
        const stored = sessionStorage.getItem('oauth_callback_result')
        if (stored) {
          const data = JSON.parse(stored)
          if (data.type === 'oauth_callback' && data.provider === 'microsoft') {
            sessionStorage.removeItem('oauth_callback_result')
            messageHandled = true
            clearInterval(checkClosed)
            window.removeEventListener('message', handleOAuthMessage)
            connectingMicrosoft.value = false
            if (data.success) {
              toast.success(`Microsoft account ${data.account_email || ''} connected!`)
              showAddAccountModal.value = false
              resetAddAccountForm()
              await loadData()
            } else if (data.error) {
              toast.error(`Microsoft sign-in failed: ${data.error.replace(/_/g, ' ')}`)
            }
            return
          }
        }
      } catch { /* ignore */ }
      
      try {
        if (!popup || popup.closed) {
          clearInterval(checkClosed)
          setTimeout(() => {
            if (!messageHandled) {
              window.removeEventListener('message', handleOAuthMessage)
              connectingMicrosoft.value = false
            }
          }, 300)
        }
      } catch {
        // COOP blocks popup.closed
      }
    }, 500)
    
    setTimeout(() => {
      if (checkClosed) clearInterval(checkClosed)
      window.removeEventListener('message', handleOAuthMessage)
      connectingMicrosoft.value = false
    }, 300000)
    
  } catch (e) {
    console.error('Microsoft OAuth error:', e)
    toast.error('Failed to initiate Microsoft sign-in')
    connectingMicrosoft.value = false
  }
}

// Get all accounts grouped by type
const primaryAccount = computed(() => ({
  id: 'primary',
  account_email: auth.userEmail,
  display_name: auth.displayName,
  type: 'primary',
}))

const imapAccounts = computed(() => 
  accountsStore.accounts.filter(a => !a.is_oauth)
)

const oauthAccounts = computed(() => 
  accountsStore.accounts.filter(a => a.is_oauth)
)

// Fetch all data
async function loadData() {
  loading.value = true
  try {
    await accountsStore.fetchAccounts()
    if (calendarEnabled.value) {
      await fetchCalendarConnections()
    }
    await fetchAccountHistory()
    // Load local calendars for sync dropdown
    if (calendarEnabled.value) {
      await calendarStore.fetchCalendars()
    }
  } catch (e) {
    console.error('Failed to load accounts data:', e)
    toast.error('Failed to load accounts')
  } finally {
    loading.value = false
  }
}

async function fetchCalendarConnections() {
  try {
    const response = await api.get('/calendar/connections')
    if (response.data.success) {
      calendarConnections.value = response.data.data.connections
    }
  } catch (e) {
    console.error('Failed to fetch calendar connections:', e)
  }
}

async function fetchAccountHistory() {
  try {
    const response = await api.get('/accounts/history')
    if (response.data.success) {
      accountHistory.value = response.data.data.history
    }
  } catch (e) {
    console.error('Failed to fetch account history:', e)
  }
}

// Auto-sync helpers
function formatLastSync(dateStr) {
  if (!dateStr) return 'Never'
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  
  const minutes = Math.floor(diff / 60000)
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)
  
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`
  if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`
  return `${days} day${days > 1 ? 's' : ''} ago`
}

async function syncLinkedImapAccount(account) {
  syncingAccountId.value = account.id
  try {
    const result = await accountsStore.triggerSync(account.id)
    if (result.success) {
      const imported = result.imported || 0
      if (imported > 0) {
        toast.success(`Synced ${imported} email${imported > 1 ? 's' : ''} from ${account.account_email}`)
      } else {
        toast.info(`${account.account_email} is up to date`)
      }
    } else {
      toast.error(result.error || 'Sync failed')
    }
  } catch (e) {
    console.error('Linked account sync failed:', e)
    toast.error('Failed to sync linked account')
  } finally {
    syncingAccountId.value = null
  }
}

async function manualSyncAll() {
  const result = await calendarStore.syncAllLinkedCalendars()
  if (result.errors?.length > 0) {
    toast.warning(`Sync completed with errors: ${result.errors.join(', ')}`)
  } else if (result.imported > 0 || result.updated > 0) {
    toast.success(`Synced: ${result.imported} new, ${result.updated} updated`)
  } else {
    toast.info('Calendars are up to date')
  }
}

// Remove account handlers
function confirmRemoveAccount(account, type) {
  accountToRemove.value = { ...account, accountType: type }
  showRemoveAccountModal.value = true
}

async function removeAccount() {
  if (!accountToRemove.value) return
  
  const account = accountToRemove.value
  removingAccount.value = account.id
  showRemoveAccountModal.value = false
  
  try {
    let success = false
    
    if (account.accountType === 'imap') {
      success = await accountsStore.deleteAccount(account.id)
    } else if (account.accountType === 'oauth') {
      success = await accountsStore.deleteOAuthAccount(account.id)
    } else if (account.accountType === 'calendar_only') {
      const response = await api.delete(`/calendar/connections/${account.id}`, {
        data: { delete_events: false }
      })
      success = response.data.success
    }
    
    if (success) {
      toast.success(`Removed ${account.account_email || account.google_email}`)
      await loadData()
    } else {
      toast.error('Failed to remove account')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to remove account')
  } finally {
    removingAccount.value = null
    accountToRemove.value = null
  }
}

// Desync modal handlers
async function showDesyncOptions(accountId, calendarId, connectionType) {
  calendarToDesync.value = { accountId, calendarId, connectionType }
  desyncDeleteEvents.value = false
  
  // Get synced events count
  try {
    let endpoint, params
    if (connectionType === 'calendar_only') {
      endpoint = '/calendar/connections/sync/events-count'
      params = { connection_id: accountId, google_calendar_id: calendarId }
    } else if (connectionType === 'microsoft_oauth') {
      endpoint = '/calendar/microsoft/sync/events-count'
      params = { account_id: accountId, ms_calendar_id: calendarId }
    } else {
      endpoint = '/calendar/google/sync/events-count'
      params = { account_id: accountId, google_calendar_id: calendarId }
    }
    
    const response = await api.get(endpoint, { params })
    if (response.data.success) {
      syncedEventsCount.value = response.data.data.count
    }
  } catch (e) {
    syncedEventsCount.value = 0
  }
  
  showDesyncModal.value = true
}

async function confirmDesync() {
  if (!calendarToDesync.value) return
  
  const { accountId, calendarId, connectionType } = calendarToDesync.value
  showDesyncModal.value = false
  
  try {
    let endpoint, data
    if (connectionType === 'calendar_only') {
      endpoint = '/calendar/connections/desync'
      data = { connection_id: accountId, google_calendar_id: calendarId, delete_events: desyncDeleteEvents.value }
    } else if (connectionType === 'microsoft_oauth') {
      endpoint = '/calendar/microsoft/desync'
      data = { account_id: accountId, ms_calendar_id: calendarId, delete_events: desyncDeleteEvents.value }
    } else {
      endpoint = '/calendar/google/desync'
      data = { account_id: accountId, google_calendar_id: calendarId, delete_events: desyncDeleteEvents.value }
    }
    
    const response = await api.post(endpoint, data)
    
    if (response.data.success) {
      const action = desyncDeleteEvents.value ? 'removed' : 'disconnected'
      toast.success(`Calendar sync ${action}`)
      await loadData()
      await calendarStore.fetchEvents()
    } else {
      toast.error(response.data.message || 'Failed to disable sync')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to disable sync')
  } finally {
    calendarToDesync.value = null
  }
}

// Connect Google Calendar only
async function connectGoogleCalendar() {
  connectingGoogleCalendar.value = true
  
  try {
    const response = await api.get('/calendar/connections/auth')
    if (!response.data.success || !response.data.data.auth_url) {
      toast.error('Failed to get authorization URL')
      connectingGoogleCalendar.value = false
      return
    }
    
    const authUrl = response.data.data.auth_url
    
    // Open OAuth popup
    const width = 500
    const height = 600
    const left = window.screenX + (window.outerWidth - width) / 2
    const top = window.screenY + (window.outerHeight - height) / 2
    
    const popup = window.open(
      authUrl,
      'google-calendar-oauth',
      `width=${width},height=${height},left=${left},top=${top},popup=1`
    )
    
    let messageHandled = false
    let checkClosed = null
    
    // Listen for OAuth callback via postMessage
    const handleOAuthMessage = async (event) => {
      // Accept messages from any origin (the popup sends with '*')
      if (event.data?.type !== 'oauth_callback') return
      // Only handle google_calendar provider messages
      if (event.data?.provider !== 'google_calendar') return
      if (messageHandled) return
      
      messageHandled = true
      window.removeEventListener('message', handleOAuthMessage)
      if (checkClosed) clearInterval(checkClosed)
      
      const { success, error, account_email } = event.data
      
      if (success) {
        await loadData()
        toast.success(`Google Calendar ${account_email || ''} connected!`)
      } else if (error) {
        toast.error(`Google Calendar connection failed: ${error.replace(/_/g, ' ')}`)
      }
      
      connectingGoogleCalendar.value = false
    }
    
    window.addEventListener('message', handleOAuthMessage)
    
    // Check sessionStorage for OAuth result (fallback when COOP blocks postMessage)
    const checkSessionStorage = async () => {
      try {
        const stored = sessionStorage.getItem('oauth_callback_result')
        if (stored) {
          const data = JSON.parse(stored)
          if (data.type === 'oauth_callback' && data.provider === 'google_calendar') {
            sessionStorage.removeItem('oauth_callback_result')
            messageHandled = true
            if (data.success) {
              await loadData()
              toast.success(`Google Calendar ${data.account_email || ''} connected!`)
            } else if (data.error) {
              toast.error(`Google Calendar connection failed: ${data.error.replace(/_/g, ' ')}`)
            }
            connectingGoogleCalendar.value = false
            return true
          }
        }
      } catch { /* ignore */ }
      return false
    }
    
    // Check if popup was closed - wrapped in try-catch for COOP
    checkClosed = setInterval(async () => {
      if (messageHandled) {
        clearInterval(checkClosed)
        return
      }
      
      const handled = await checkSessionStorage()
      if (handled) {
        clearInterval(checkClosed)
        window.removeEventListener('message', handleOAuthMessage)
        return
      }
      
      try {
        if (!popup || popup.closed) {
          clearInterval(checkClosed)
          setTimeout(async () => {
            if (!messageHandled) {
              window.removeEventListener('message', handleOAuthMessage)
              const handledAfterClose = await checkSessionStorage()
              if (!handledAfterClose) {
                await loadData()
                connectingGoogleCalendar.value = false
              }
            }
          }, 300)
        }
      } catch {
        // COOP blocks access to popup.closed - keep polling sessionStorage
      }
    }, 500)
    
    // Timeout after 5 minutes
    setTimeout(() => {
      if (checkClosed) clearInterval(checkClosed)
      window.removeEventListener('message', handleOAuthMessage)
      connectingGoogleCalendar.value = false
    }, 300000)
    
  } catch (e) {
    console.error('Google Calendar OAuth error:', e)
    toast.error('Failed to initiate Google Calendar connection')
    connectingGoogleCalendar.value = false
  }
}

// Reconnect from history
async function reconnectFromHistory(historyEntry) {
  if (historyEntry.account_type === 'imap') {
    // Store history ID to delete after successful reconnection
    reconnectingHistoryId.value = historyEntry.id
    // Open add account modal with pre-filled settings
    // Settings are stored in server_settings JSON object
    const settings = historyEntry.server_settings || {}
    addAccountForm.value.account_email = historyEntry.account_email || ''
    addAccountForm.value.imap_host = settings.imap_host || ''
    addAccountForm.value.imap_port = settings.imap_port || 993
    addAccountForm.value.smtp_host = settings.smtp_host || ''
    addAccountForm.value.smtp_port = settings.smtp_port || 465
    addAccountForm.value.imap_encryption = settings.imap_encryption || 'ssl'
    addAccountForm.value.smtp_encryption = settings.smtp_encryption || 'ssl'
    addAccountForm.value.display_name = historyEntry.display_name || ''
    showAddAccountModal.value = true
  } else if (historyEntry.account_type === 'google_oauth') {
    // Use popup-based OAuth
    await connectGoogleOAuth()
  } else if (historyEntry.account_type === 'microsoft_oauth') {
    // Use popup-based OAuth
    await connectMicrosoftOAuth()
  } else if (historyEntry.account_type === 'google_calendar') {
    await connectGoogleCalendar()
    // Remove from history after OAuth reconnection
    await deleteHistoryEntry(historyEntry.id)
  }
  // For IMAP: history will be removed after successful account creation in addAccount()
}

async function deleteHistoryEntry(id) {
  try {
    const response = await api.delete(`/accounts/history/${id}`)
    if (response.data.success) {
      accountHistory.value = accountHistory.value.filter(h => h.id !== id)
    }
  } catch (e) {
    console.error('Failed to delete history entry:', e)
  }
}

// Account type helpers
function getAccountTypeLabel(type) {
  switch (type) {
    case 'imap': return 'IMAP'
    case 'google_oauth': return 'Google'
    case 'microsoft_oauth': return 'Microsoft'
    case 'google_calendar': return 'Google Calendar'
    default: return type
  }
}

function getAccountTypeIcon(type) {
  switch (type) {
    case 'imap': return 'mail'
    case 'google_oauth':
    case 'google_calendar': return null // Use Google SVG
    case 'microsoft_oauth': return null // Use Microsoft SVG
    default: return 'account_circle'
  }
}

const emit = defineEmits(['reconnect-imap'])

onMounted(async () => {
  // Check for OAuth callback result from sessionStorage (fallback when popup lost opener)
  await checkOAuthFallback()
  // Load account data
  await loadData()
})
</script>

<template>
  <div class="space-y-8">
    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner text-primary-500"></span>
    </div>
    
    <template v-else>
      <!-- Primary Account -->
      <section>
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-primary-500">account_circle</span>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Primary Account</h2>
        </div>
        
        <div class="card p-4">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-primary-500 flex items-center justify-center text-white text-lg font-medium">
              {{ primaryAccount.display_name?.substring(0, 2).toUpperCase() || primaryAccount.account_email?.substring(0, 2).toUpperCase() }}
            </div>
            <div class="flex-1">
              <p class="font-medium text-surface-900 dark:text-surface-100">
                {{ primaryAccount.display_name || primaryAccount.account_email }}
              </p>
              <p class="text-sm text-surface-500">{{ primaryAccount.account_email }}</p>
            </div>
            <span class="px-3 py-1 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 text-xs font-medium">
              Primary
            </span>
          </div>
          <p class="mt-4 text-xs text-surface-400">
            This is your main account. To change it, sign out and log in with a different account.
          </p>
        </div>
      </section>
      
      <!-- Connected Email Accounts -->
      <section>
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">mail</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Connected Email Accounts</h2>
          </div>
          <button
            @click="showAddAccountModal = true"
            class="btn-secondary btn-sm"
          >
            <span class="material-symbols-rounded">add</span>
            Add Account
          </button>
        </div>
        
        <div class="space-y-3">
          <!-- OAuth Accounts (Google/Microsoft) -->
          <div 
            v-for="account in oauthAccounts" 
            :key="'oauth-' + account.id"
            class="card overflow-hidden"
          >
            <div class="p-4">
              <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full flex items-center justify-center" :class="account.provider === 'google' ? 'bg-blue-500' : 'bg-blue-600'">
                  <!-- Google Icon -->
                  <svg v-if="account.provider === 'google'" class="w-5 h-5" viewBox="0 0 24 24" fill="white">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                  </svg>
                  <!-- Microsoft Icon -->
                  <svg v-else class="w-5 h-5" viewBox="0 0 24 24" fill="white">
                    <path d="M11.4 24H0V12.6h11.4V24zM24 24H12.6V12.6H24V24zM11.4 11.4H0V0h11.4v11.4zm12.6 0H12.6V0H24v11.4z"/>
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                    {{ account.display_name || account.account_email }}
                  </p>
                  <p class="text-sm text-surface-500 truncate">{{ account.account_email }}</p>
                </div>
                <div class="flex items-center gap-2">
                  <span class="px-2 py-1 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 text-xs">
                    {{ account.provider === 'google' ? 'Google' : 'Microsoft' }}
                  </span>
                  
                  <!-- Calendar Sync Status -->
                  <span 
                    v-if="account.synced_calendars?.length" 
                    class="flex items-center gap-1 px-2 py-1 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 text-xs"
                  >
                    <span class="material-symbols-rounded text-sm">sync</span>
                    {{ account.synced_calendars.length }} calendar(s)
                  </span>
                  
                  <!-- Calendar Sync Toggle (Google and Microsoft) -->
                  <button
                    @click="toggleAccountCalendars(account.id)"
                    class="btn-ghost btn-icon btn-sm"
                    :class="expandedAccounts[account.id] ? 'text-primary-500' : 'text-surface-400'"
                    title="Manage calendar sync"
                  >
                    <span class="material-symbols-rounded">{{ expandedAccounts[account.id] ? 'expand_less' : 'calendar_month' }}</span>
                  </button>
                  
                  <button
                    @click="confirmRemoveAccount(account, 'oauth')"
                    :disabled="removingAccount === account.id"
                    class="btn-ghost btn-icon btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                    title="Remove account"
                  >
                    <span v-if="removingAccount === account.id" class="spinner w-4 h-4"></span>
                    <span v-else class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </div>
            </div>
            
            <!-- Expanded Calendar Sync Panel -->
            <div v-if="expandedAccounts[account.id]" class="border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50 p-4">
              <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-medium text-surface-700 dark:text-surface-300">
                  {{ account.provider === 'google' ? 'Google' : 'Microsoft' }} Calendar Sync
                </h4>
                <button
                  @click="loadAccountCalendars(account.id, account.provider)"
                  :disabled="loadingCalendars[account.id]"
                  class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-surface-200 dark:bg-surface-600 text-surface-700 dark:text-surface-200 hover:bg-surface-300 dark:hover:bg-surface-500 transition-colors disabled:opacity-50"
                >
                  <span v-if="loadingCalendars[account.id]" class="spinner w-4 h-4"></span>
                  <span v-else class="material-symbols-rounded text-sm">refresh</span>
                  {{ getAccountCalendars(account.id, account.provider)?.length ? 'Refresh' : 'Load Calendars' }}
                </button>
              </div>
              
              <!-- Calendars List -->
              <div v-if="getAccountCalendars(account.id, account.provider)?.length" class="space-y-2">
                <div 
                  v-for="cal in getAccountCalendars(account.id, account.provider)" 
                  :key="cal.id"
                  class="flex items-center justify-between p-3 rounded-lg bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700"
                >
                  <div class="flex items-center gap-3">
                    <div class="w-3 h-3 rounded" :style="{ backgroundColor: cal.color || cal.backgroundColor || '#3b82f6' }"></div>
                    <div>
                      <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ cal.summary || cal.name }}</p>
                      <p v-if="cal.primary || cal.isDefault" class="text-xs text-primary-500">Primary</p>
                    </div>
                  </div>
                  
                  <div class="flex items-center gap-2">
                    <template v-if="isOAuthCalendarSynced(account, cal.id)">
                      <span class="text-xs text-green-500 flex items-center gap-1">
                        <span class="material-symbols-rounded text-sm">check_circle</span>
                        Synced
                      </span>
                      <button 
                        @click="syncOAuthCalendar(account, cal.id)"
                        :disabled="loadingCalendars[account.id]"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-surface-200 dark:bg-surface-600 text-surface-700 dark:text-surface-200 hover:bg-surface-300 dark:hover:bg-surface-500 transition-colors disabled:opacity-50"
                        title="Sync now"
                      >
                        <span class="material-symbols-rounded text-sm">sync</span>
                        Sync
                      </button>
                      <button 
                        @click="disableOAuthCalendarSync(account, cal.id)"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-500/30 transition-colors"
                        title="Disable sync"
                      >
                        <span class="material-symbols-rounded text-sm">link_off</span>
                        Unlink
                      </button>
                    </template>
                    <button
                      v-else
                      @click="toggleCalendarSelection(account.id, cal.id)"
                      :class="['btn-sm', isCalendarSelected(account.id, cal.id) ? 'btn-primary' : 'btn-secondary']"
                    >
                      <span class="material-symbols-rounded text-sm">{{ isCalendarSelected(account.id, cal.id) ? 'check' : 'add' }}</span>
                      {{ isCalendarSelected(account.id, cal.id) ? 'Selected' : 'Select' }}
                    </button>
                  </div>
                </div>
                
                <!-- Setup Sync Form -->
                <div v-if="selectedCalendars[account.id]?.length" class="mt-3 p-3 bg-primary-50 dark:bg-primary-500/10 rounded-lg border border-primary-200 dark:border-primary-500/30">
                  <p class="text-sm font-medium text-primary-700 dark:text-primary-400 mb-2">
                    Sync {{ selectedCalendars[account.id].length }} calendar(s) to:
                  </p>
                  <div class="flex items-end gap-2">
                    <select v-model="selectedLocalCalendar" class="input input-sm flex-1">
                      <option :value="null">Choose local calendar...</option>
                      <option v-for="localCal in calendarStore.calendars" :key="localCal.id" :value="localCal.id">
                        {{ localCal.name }}
                      </option>
                    </select>
                    <button 
                      @click="setupOAuthCalendarSync(account)"
                      :disabled="!selectedLocalCalendar || loadingCalendars[account.id]"
                      class="btn-primary btn-sm"
                    >
                      <span class="material-symbols-rounded">link</span>
                      Enable Sync
                    </button>
                  </div>
                </div>
              </div>
              
              <!-- Loading State -->
              <div v-else-if="loadingCalendars[account.id]" class="py-6 text-center text-surface-500">
                <span class="spinner mb-2"></span>
                <p class="text-sm">Loading calendars...</p>
              </div>
              
              <!-- Empty State -->
              <div v-else class="py-6 text-center text-surface-500 text-sm">
                <span class="material-symbols-rounded text-2xl mb-2 block">calendar_month</span>
                Click "Load Calendars" to see your Google calendars
              </div>
            </div>
          </div>
          
          <!-- IMAP Accounts -->
          <div 
            v-for="account in imapAccounts" 
            :key="'imap-' + account.id"
            class="card p-4"
          >
            <div class="flex items-center gap-4">
              <div class="w-10 h-10 rounded-full bg-surface-500 flex items-center justify-center text-white">
                <span class="material-symbols-rounded">mail</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ account.display_name || account.account_email }}
                </p>
                <p class="text-sm text-surface-500 truncate">{{ account.account_email }}</p>
              </div>
              <div class="flex items-center gap-2">
                <span class="px-2 py-1 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 text-xs">
                  IMAP
                </span>
                <span 
                  v-if="account.account_type === 'linked'" 
                  class="px-2 py-1 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 text-xs"
                >
                  Linked
                </span>
                <span class="text-xs text-surface-400">
                  {{ account.imap_host }}
                </span>
                <button
                  v-if="account.account_type === 'linked'"
                  @click="syncLinkedImapAccount(account)"
                  :disabled="syncingAccountId === account.id"
                  class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 hover:bg-primary-200 dark:hover:bg-primary-500/30 transition-colors disabled:opacity-50"
                  title="Sync now"
                >
                  <span v-if="syncingAccountId === account.id" class="spinner w-3 h-3"></span>
                  <span v-else class="material-symbols-rounded text-sm">sync</span>
                  Sync
                </button>
                <button
                  @click="confirmRemoveAccount(account, 'imap')"
                  :disabled="removingAccount === account.id"
                  class="btn-ghost btn-icon btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                  title="Remove account"
                >
                  <span v-if="removingAccount === account.id" class="spinner w-4 h-4"></span>
                  <span v-else class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </div>
          </div>
          
          <!-- Empty State -->
          <div 
            v-if="oauthAccounts.length === 0 && imapAccounts.length === 0"
            class="card p-6 text-center text-surface-500"
          >
            <span class="material-symbols-rounded text-3xl mb-2 block">inbox</span>
            <p>No additional email accounts connected</p>
          </div>
        </div>
      </section>
      
      <!-- Calendar Connections & Auto-Sync -->
      <section v-if="calendarEnabled">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">calendar_month</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Calendar Connections</h2>
          </div>
          <button
            v-if="!iosNative"
            @click="connectGoogleCalendar"
            :disabled="connectingGoogleCalendar"
            class="btn-secondary btn-sm"
          >
            <span v-if="connectingGoogleCalendar" class="spinner w-4 h-4"></span>
            <span v-else class="material-symbols-rounded">add</span>
            Connect Google Calendar
          </button>
        </div>
        
        <!-- Auto-Sync Settings (only shown if there are synced calendars) -->
        <div v-if="hasSyncedCalendars" class="card p-4 mb-4">
          <div class="flex items-center gap-2 mb-3">
            <span class="material-symbols-rounded text-primary-500 text-lg">sync</span>
            <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Auto-Sync Settings</h3>
          </div>
          
          <div class="space-y-3">
            <!-- Enable/Disable Toggle -->
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100">Automatic Sync</p>
                <p class="text-xs text-surface-500">Sync linked calendars in the background</p>
              </div>
              <button
                @click="calendarStore.setAutoSyncEnabled(!calendarStore.autoSyncEnabled)"
                :class="[
                  'relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2',
                  calendarStore.autoSyncEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span 
                  :class="[
                    'absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200',
                    calendarStore.autoSyncEnabled ? 'translate-x-5' : 'translate-x-0'
                  ]"
                ></span>
              </button>
            </div>
            
            <!-- Sync Interval & Last Sync (inline when enabled) -->
            <div v-if="calendarStore.autoSyncEnabled" class="flex items-center justify-between gap-4 pt-2 border-t border-surface-200 dark:border-surface-700">
              <div class="flex items-center gap-3">
                <select 
                  :value="calendarStore.autoSyncInterval"
                  @change="calendarStore.setAutoSyncInterval(parseInt($event.target.value))"
                  class="input text-xs py-1.5 px-2 w-auto"
                >
                  <option :value="15">Every 15 min</option>
                  <option :value="30">Every 30 min</option>
                  <option :value="60">Every hour</option>
                  <option :value="120">Every 2 hours</option>
                  <option :value="360">Every 6 hours</option>
                  <option :value="720">Every 12 hours</option>
                  <option :value="1440">Once a day</option>
                </select>
                <span class="text-xs text-surface-400">
                  {{ calendarStore.lastAutoSync ? 'Last: ' + formatLastSync(calendarStore.lastAutoSync) : 'Never synced' }}
                </span>
              </div>
              <button
                @click="manualSyncAll"
                :disabled="calendarStore.syncing"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 hover:bg-primary-200 dark:hover:bg-primary-500/30 transition-colors disabled:opacity-50"
              >
                <span v-if="calendarStore.syncing" class="spinner w-3 h-3"></span>
                <span v-else class="material-symbols-rounded text-sm">sync</span>
                Sync Now
              </button>
            </div>
          </div>
        </div>
        
        <div class="space-y-3">
          <div 
            v-for="conn in calendarConnections" 
            :key="'cal-' + conn.id"
            class="card overflow-hidden"
          >
            <div class="p-4">
              <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center text-white">
                  <span class="material-symbols-rounded">calendar_month</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                    {{ conn.display_name || conn.google_email }}
                  </p>
                  <p class="text-sm text-surface-500 truncate">{{ conn.google_email }}</p>
                </div>
                <div class="flex items-center gap-2">
                  <span class="px-2 py-1 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 text-xs">
                    Calendar Only
                  </span>
                  
                  <!-- Synced calendars count -->
                  <span 
                    v-if="conn.synced_calendars?.length" 
                    class="flex items-center gap-1 px-2 py-1 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400 text-xs"
                  >
                    <span class="material-symbols-rounded text-sm">sync</span>
                    {{ conn.synced_calendars.length }} calendar(s)
                  </span>
                  
                  <!-- Expand/Collapse Calendar Panel -->
                  <button
                    @click="toggleCalendarConnPanel(conn.id)"
                    class="btn-ghost btn-icon btn-sm"
                    :class="expandedCalendarConns[conn.id] ? 'text-primary-500' : 'text-surface-400'"
                    title="Manage calendar sync"
                  >
                    <span class="material-symbols-rounded">{{ expandedCalendarConns[conn.id] ? 'expand_less' : 'calendar_month' }}</span>
                  </button>
                  
                  <button
                    @click="confirmRemoveAccount(conn, 'calendar_only')"
                    :disabled="removingAccount === conn.id"
                    class="btn-ghost btn-icon btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                    title="Remove connection"
                  >
                    <span v-if="removingAccount === conn.id" class="spinner w-4 h-4"></span>
                    <span v-else class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </div>
            </div>
            
            <!-- Calendar Sync Panel (Expandable) -->
            <div 
              v-if="expandedCalendarConns[conn.id]" 
              class="border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50 p-4"
            >
              <!-- Header with Toggle All and Refresh -->
              <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-medium text-surface-600 dark:text-surface-400">Calendars</p>
                <div class="flex items-center gap-2">
                  <button 
                    v-if="calendarConnCalendars[conn.id]?.length"
                    @click="toggleAllCalendarConn(conn.id)"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                    title="Toggle all calendars"
                  >
                    <span class="material-symbols-rounded text-sm">select_all</span>
                    {{ areAllCalendarsSelected(conn) ? 'Deselect All' : 'Select All' }}
                  </button>
                  <button 
                    @click="loadCalendarConnCalendars(conn.id)"
                    :disabled="loadingCalendars['conn_' + conn.id]"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors disabled:opacity-50"
                  >
                    <span class="material-symbols-rounded text-sm">refresh</span>
                    Refresh
                  </button>
                </div>
              </div>
              
              <!-- Loading -->
              <div v-if="loadingCalendars['conn_' + conn.id]" class="py-4 text-center text-surface-500">
                <span class="spinner mb-2"></span>
                <p class="text-sm">Loading calendars...</p>
              </div>
              
              <!-- Unified Calendar List -->
              <div v-else-if="calendarConnCalendars[conn.id]?.length" class="space-y-1">
                <div 
                  v-for="cal in calendarConnCalendars[conn.id]" 
                  :key="cal.id"
                  class="flex items-center gap-3 p-3 rounded-lg transition-colors hover:bg-surface-100 dark:hover:bg-surface-700"
                >
                  <div 
                    class="w-3 h-3 rounded-full flex-shrink-0" 
                    :style="{ backgroundColor: cal.backgroundColor || '#4285f4' }"
                  ></div>
                  <span class="flex-1 text-sm truncate">{{ cal.summary }}</span>
                  
                  <!-- Synced calendar controls -->
                  <template v-if="isCalendarConnSynced(conn, cal.id)">
                    <span class="text-xs text-surface-400">({{ getCalendarConnSyncedCount(conn, cal.id) }} events)</span>
                    <button
                      @click="syncCalendarConnFromGoogle(conn.id, cal.id)"
                      :disabled="loadingCalendars['conn_' + conn.id]"
                      class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-surface-200 dark:bg-surface-600 text-surface-700 dark:text-surface-200 hover:bg-surface-300 dark:hover:bg-surface-500 transition-colors disabled:opacity-50"
                      title="Sync now"
                    >
                      <span class="material-symbols-rounded text-sm">sync</span>
                      Sync
                    </button>
                    <button
                      @click="showDesyncOptions(conn.id, cal.id, 'calendar_only')"
                      class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-500/30 transition-colors"
                      title="Disable sync"
                    >
                      <span class="material-symbols-rounded text-sm">link_off</span>
                      Unlink
                    </button>
                  </template>
                  
                  <!-- Not synced - Toggle Switch -->
                  <button
                    v-else
                    @click="toggleCalendarConnSelection(conn.id, cal.id)"
                    class="relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                    :class="isCalendarConnSelected(conn.id, cal.id) 
                      ? 'bg-primary-500' 
                      : 'bg-surface-300 dark:bg-surface-600'"
                    role="switch"
                    :aria-checked="isCalendarConnSelected(conn.id, cal.id)"
                  >
                    <span 
                      class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"
                      :class="isCalendarConnSelected(conn.id, cal.id) ? 'translate-x-5' : 'translate-x-0'"
                    ></span>
                  </button>
                </div>
                
                <!-- Sync Selected -->
                <div v-if="selectedCalendarConnCals[conn.id]?.length" class="pt-3 mt-3 border-t border-surface-200 dark:border-surface-700">
                  <div class="flex items-center gap-2">
                    <select 
                      v-model="selectedLocalCalendar"
                      class="input text-sm flex-1"
                    >
                      <option :value="null" disabled>Select local calendar</option>
                      <option 
                        v-for="localCal in calendarStore.calendars" 
                        :key="localCal.id" 
                        :value="localCal.id"
                      >
                        {{ localCal.name }}
                      </option>
                    </select>
                    <button 
                      @click="setupCalendarConnSync(conn.id)"
                      :disabled="!selectedLocalCalendar || loadingCalendars['conn_' + conn.id]"
                      class="btn-primary btn-sm"
                    >
                      <span class="material-symbols-rounded">link</span>
                      Enable Sync
                    </button>
                  </div>
                </div>
              </div>
              
              <!-- Empty State -->
              <div v-else class="py-4 text-center text-surface-500 text-sm">
                <span class="material-symbols-rounded text-2xl mb-2 block">calendar_month</span>
                Click "Refresh" to load your Google calendars
              </div>
            </div>
          </div>
          
          <!-- Empty State -->
          <div 
            v-if="calendarConnections.length === 0"
            class="card p-6 text-center text-surface-500"
          >
            <span class="material-symbols-rounded text-3xl mb-2 block">event_busy</span>
            <p class="mb-2">No calendar-only connections</p>
            <p class="text-xs">Connect a Google Calendar without granting email access</p>
          </div>
        </div>
      </section>
      
      <!-- Account History -->
      <section v-if="accountHistory.length > 0">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-primary-500">history</span>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Account History</h2>
        </div>
        
        <div class="card p-4">
          <p class="text-xs text-surface-500 mb-4">
            Previously connected accounts. Click "Reconnect" to quickly add them again.
          </p>
          
          <div class="space-y-2">
            <div 
              v-for="entry in accountHistory" 
              :key="entry.id"
              class="flex items-center gap-3 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800"
            >
              <div class="w-8 h-8 rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center">
                <span v-if="getAccountTypeIcon(entry.account_type)" class="material-symbols-rounded text-surface-500 text-sm">
                  {{ getAccountTypeIcon(entry.account_type) }}
                </span>
                <svg v-else-if="entry.account_type.includes('google')" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" class="text-blue-500"/>
                </svg>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ entry.account_email }}
                </p>
                <p class="text-xs text-surface-400">
                  {{ getAccountTypeLabel(entry.account_type) }} - Removed {{ new Date(entry.disconnected_at).toLocaleDateString() }}
                </p>
              </div>
              <div class="flex items-center gap-2">
                <button
                  @click="reconnectFromHistory(entry)"
                  class="btn-secondary btn-sm"
                >
                  <span class="material-symbols-rounded">link</span>
                  Reconnect
                </button>
                <button
                  @click="deleteHistoryEntry(entry.id)"
                  class="btn-ghost btn-icon btn-sm text-surface-400 hover:text-red-500"
                  title="Remove from history"
                >
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>
      
      <!-- Info Box -->
      <div class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl">
        <div class="flex items-start gap-3">
          <span class="material-symbols-rounded text-blue-500 mt-0.5">info</span>
          <div class="text-sm text-blue-600 dark:text-blue-400">
            <p class="font-medium text-blue-700 dark:text-blue-300 mb-2">About Accounts & Calendar Sync</p>
            <ul class="space-y-1 text-xs">
              <li><strong>Email Accounts (Google/Microsoft)</strong> - Full email access and calendar sync. Click the calendar icon to manage sync.</li>
              <li><strong>Calendar-Only</strong> - Only syncs calendar events without email access.</li>
              <li><strong>IMAP Accounts</strong> - Traditional email accounts without calendar sync.</li>
            </ul>
            <p class="mt-2 text-xs">Calendar sync is two-way - events sync in both directions.</p>
          </div>
        </div>
      </div>
    </template>
    
    <!-- Remove Account Confirmation Modal -->
    <ConfirmModal
      :show="showRemoveAccountModal"
      title="Remove Account"
      :message="`Are you sure you want to remove ${accountToRemove?.account_email || accountToRemove?.google_email}? This will stop syncing and remove saved credentials.`"
      confirm-text="Remove"
      type="danger"
      @confirm="removeAccount"
      @cancel="showRemoveAccountModal = false; accountToRemove = null"
    />
    
    <!-- Desync Options Modal -->
    <div v-if="showDesyncModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div class="w-full max-w-md bg-white dark:bg-surface-800 rounded-2xl shadow-xl">
        <div class="p-6">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-amber-500">sync_disabled</span>
            </div>
            <div>
              <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Disable Calendar Sync</h3>
              <p class="text-sm text-surface-500">What should happen to synced events?</p>
            </div>
          </div>
          
          <div class="space-y-3 mb-6">
            <p class="text-sm text-surface-600 dark:text-surface-400">
              This calendar has <strong>{{ syncedEventsCount }}</strong> synced events.
            </p>
            
            <!-- Option: Keep Events -->
            <label 
              class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors"
              :class="!desyncDeleteEvents ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700'"
            >
              <input 
                type="radio" 
                :checked="!desyncDeleteEvents"
                @change="desyncDeleteEvents = false"
                class="mt-1"
              >
              <div>
                <p class="font-medium text-surface-900 dark:text-surface-100">Keep events</p>
                <p class="text-sm text-surface-500">Events will remain in your calendar but won't sync anymore</p>
              </div>
            </label>
            
            <!-- Option: Delete Events -->
            <label 
              class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors"
              :class="desyncDeleteEvents ? 'border-red-500 bg-red-50 dark:bg-red-500/10' : 'border-surface-200 dark:border-surface-700'"
            >
              <input 
                type="radio" 
                :checked="desyncDeleteEvents"
                @change="desyncDeleteEvents = true"
                class="mt-1"
              >
              <div>
                <p class="font-medium text-surface-900 dark:text-surface-100">Delete all synced events</p>
                <p class="text-sm text-surface-500">Remove all {{ syncedEventsCount }} events from your local calendar</p>
              </div>
            </label>
          </div>
          
          <div class="flex gap-3">
            <button
              @click="showDesyncModal = false; calendarToDesync = null"
              class="flex-1 btn-secondary"
            >
              Cancel
            </button>
            <button
              @click="confirmDesync"
              :class="desyncDeleteEvents ? 'flex-1 btn-danger' : 'flex-1 btn-primary'"
            >
              {{ desyncDeleteEvents ? 'Delete & Disable' : 'Keep & Disable' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Add Account Modal -->
  <Teleport to="body">
    <div v-if="showAddAccountModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div class="w-full max-w-lg bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Add Email Account</h3>
          <button @click="showAddAccountModal = false; resetAddAccountForm()" class="btn-ghost btn-icon">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        
        <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">
          <!-- Account Type Toggle -->
          <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">
              How should this account work?
            </label>
            <div class="grid grid-cols-2 gap-3">
              <button 
                @click="addAccountForm.account_type = 'separate'"
                class="p-3 rounded-xl border-2 text-left transition-all"
                :class="addAccountForm.account_type === 'separate' 
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' 
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'"
              >
                <span class="material-symbols-rounded text-xl mb-1 block" :class="addAccountForm.account_type === 'separate' ? 'text-primary-500' : 'text-surface-400'">swap_horiz</span>
                <span class="font-medium text-sm text-surface-900 dark:text-surface-100">Separate</span>
                <p class="text-xs text-surface-500 mt-1">Switch between accounts. Each has its own inbox.</p>
              </button>
              <button 
                @click="addAccountForm.account_type = 'linked'"
                class="p-3 rounded-xl border-2 text-left transition-all"
                :class="addAccountForm.account_type === 'linked' 
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' 
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'"
              >
                <span class="material-symbols-rounded text-xl mb-1 block" :class="addAccountForm.account_type === 'linked' ? 'text-primary-500' : 'text-surface-400'">link</span>
                <span class="font-medium text-sm text-surface-900 dark:text-surface-100">Linked</span>
                <p class="text-xs text-surface-500 mt-1">Sync emails into your main inbox. Like Gmail's POP fetch.</p>
              </button>
            </div>
          </div>
          
          <!-- Email Provider Selection -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
              Email Provider
            </label>
            <div class="flex flex-wrap gap-2">
              <button 
                v-if="!iosNative"
                @click="connectGoogleOAuth" 
                :disabled="connectingGoogle"
                class="px-3 py-2 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-500 flex items-center gap-2 transition-all"
              >
                <span v-if="connectingGoogle" class="spinner w-4 h-4"></span>
                <svg v-else class="w-4 h-4" viewBox="0 0 24 24">
                  <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                  <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                  <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                  <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Gmail
              </button>
              <button 
                v-if="!iosNative"
                @click="connectMicrosoftOAuth" 
                :disabled="connectingMicrosoft"
                class="px-3 py-2 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-500 flex items-center gap-2 transition-all"
              >
                <span v-if="connectingMicrosoft" class="spinner w-4 h-4"></span>
                <svg v-else class="w-4 h-4" viewBox="0 0 24 24">
                  <path fill="#F25022" d="M11.4 11.4H0V0h11.4z"/>
                  <path fill="#7FBA00" d="M24 11.4H12.6V0H24z"/>
                  <path fill="#00A4EF" d="M11.4 24H0V12.6h11.4z"/>
                  <path fill="#FFB900" d="M24 24H12.6V12.6H24z"/>
                </svg>
                Outlook
              </button>
              <button 
                @click="applyPreset('hotmail')"
                class="px-3 py-2 rounded-xl border transition-all"
                :class="selectedPreset === 'hotmail' ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700 hover:border-primary-500'"
              >Outlook / Hotmail</button>
              <button 
                @click="applyPreset('yahoo')"
                class="px-3 py-2 rounded-xl border transition-all"
                :class="selectedPreset === 'yahoo' ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700 hover:border-primary-500'"
              >Yahoo Mail</button>
              <button 
                @click="applyPreset('icloud')"
                class="px-3 py-2 rounded-xl border transition-all"
                :class="selectedPreset === 'icloud' ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700 hover:border-primary-500'"
              >iCloud Mail</button>
              <button 
                @click="applyPreset('devcon1')"
                class="px-3 py-2 rounded-xl border transition-all"
                :class="selectedPreset === 'devcon1' ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700 hover:border-primary-500'"
              >Devcon1</button>
              <button 
                @click="applyPreset('custom')"
                class="px-3 py-2 rounded-xl border transition-all"
                :class="selectedPreset === 'custom' ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700 hover:border-primary-500'"
              >Custom IMAP Server</button>
            </div>
          </div>
          
          <!-- Email & Password -->
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Email Address</label>
              <input 
                v-model="addAccountForm.account_email" 
                type="email" 
                class="input"
                placeholder="you@example.com"
              >
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Password / App Password</label>
              <input 
                v-model="addAccountForm.password" 
                type="password" 
                class="input"
                placeholder="Your email password"
              >
              <p class="text-xs text-surface-400 mt-1">For Gmail/Yahoo with 2FA, use an App Password</p>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Display Name (optional)</label>
              <input 
                v-model="addAccountForm.display_name" 
                type="text" 
                class="input"
                placeholder="My Work Account"
              >
            </div>
          </div>
          
          <!-- Server Settings -->
          <details class="border border-surface-200 dark:border-surface-700 rounded-xl">
            <summary class="px-4 py-3 cursor-pointer text-sm font-medium text-surface-700 dark:text-surface-300">
              Server Settings
            </summary>
            <div class="px-4 pb-4 space-y-4">
              <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                  <label class="block text-xs text-surface-500 mb-1">IMAP Host</label>
                  <input v-model="addAccountForm.imap_host" type="text" class="input text-sm">
                </div>
                <div>
                  <label class="block text-xs text-surface-500 mb-1">Port</label>
                  <input v-model="addAccountForm.imap_port" type="number" class="input text-sm">
                </div>
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                  <label class="block text-xs text-surface-500 mb-1">SMTP Host</label>
                  <input v-model="addAccountForm.smtp_host" type="text" class="input text-sm">
                </div>
                <div>
                  <label class="block text-xs text-surface-500 mb-1">Port</label>
                  <input v-model="addAccountForm.smtp_port" type="number" class="input text-sm">
                </div>
              </div>
            </div>
          </details>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between">
          <button 
            @click="testConnection" 
            :disabled="testingConnection" 
            class="btn-ghost"
          >
            <span v-if="testingConnection" class="spinner w-4 h-4"></span>
            <span class="material-symbols-rounded">wifi_tethering</span>
            Test Connection
          </button>
          <div class="flex gap-2">
            <button @click="showAddAccountModal = false; resetAddAccountForm()" class="btn-ghost">Cancel</button>
            <button @click="addNewAccount" :disabled="addingAccount" class="btn-primary">
              <span v-if="addingAccount" class="spinner w-4 h-4"></span>
              <span class="material-symbols-rounded">add</span>
              Add Account
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

