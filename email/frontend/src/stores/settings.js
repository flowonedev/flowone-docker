import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/services/api'
import { debugLog, isDebugEnabled as readDebugFromLS } from '@/utils/debug'

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref({
    display_name: '',
    signature: '',
    messages_per_page: 50,
    theme: 'system',
    accent_color: 'green',
    layout_mode: 'columns',
    display_density: 'cosy', // 'cosy' (default) or 'compact'
    auto_mark_read: true,
    confirm_delete: true,
    refresh_interval: 60, // seconds (0 = disabled)
    large_attachment_threshold: 10, // MB (0 = disabled, always attach directly)
    block_remote_images: true, // Block images from untrusted senders
    override_email_styling: true, // Override inline styles in emails for readability
    locale: 'en', // 'en' or 'hu'
    perspective: 'operations',
    setup_completed: false,
    // Out of Office auto-reply
    ooo_enabled: false,
    ooo_subject: '',
    ooo_message: '',
    ooo_start_date: '',
    ooo_end_date: '',
    undo_send_delay: 0,
    compose_style: 'modal', // 'modal' (centered overlay) or 'inline' (Gmail-style bottom-right)
    // News Reader — Markets panel basket selection
    news_markets_stocks: [],
    news_markets_crypto: [],
    // Note: debug_logs is stored in localStorage only, not in backend settings
  })
  
  const loading = ref(false)
  const loaded = ref(false)
  const currentAccountEmail = ref(null) // Track which account's settings are loaded
  
  // Trusted senders (persisted to backend, cached locally)
  const trustedSenders = ref([])
  const trustedSendersLoaded = ref(false)

  async function fetchSettings(forceReload = false) {
    debugLog('[TRUSTED-SENDER] fetchSettings called, loading:', loading.value, 'loaded:', loaded.value, 'forceReload:', forceReload)
    
    // Always fetch trusted senders if not loaded yet, even if settings are cached
    if (!trustedSendersLoaded.value) {
      debugLog('[TRUSTED-SENDER] fetchSettings: trusted senders not loaded, fetching...')
      await fetchTrustedSenders()
    }
    
    if (loading.value) {
      debugLog('[TRUSTED-SENDER] fetchSettings: already loading, returning')
      return
    }
    if (loaded.value && !forceReload) {
      debugLog('[TRUSTED-SENDER] fetchSettings: already loaded, returning')
      return
    }
    
    loading.value = true
    try {
      debugLog('[TRUSTED-SENDER] fetchSettings: fetching from API...')
      const response = await api.get('/settings')
      if (response.data.success) {
        const newSettings = { ...getDefaults(), ...response.data.data.settings }
        // debug_logs is a CLIENT-ONLY setting persisted in localStorage (it is
        // toggled in SettingsView). The backend never stores or returns it, so
        // seed it FROM localStorage here. The previous code did the reverse and
        // wrote `false` back to localStorage on every load (undefined === true),
        // which is exactly why enabling Debug Logs never stuck across refreshes.
        newSettings.debug_logs = readDebugFromLS()
        settings.value = newSettings
        currentAccountEmail.value = response.data.data.account_email || null
        loaded.value = true
        // Theme application is handled by the caller (component) to avoid circular deps
        debugLog('[TRUSTED-SENDER] fetchSettings: settings loaded successfully')
        
        // Fetch trusted senders again in case they weren't loaded above
        if (!trustedSendersLoaded.value) {
          await fetchTrustedSenders()
        }
      }
    } catch (e) {
      console.error('[TRUSTED-SENDER] fetchSettings: API failed:', e)
    } finally {
      loading.value = false
    }
  }
  
  // Reset loaded flag to force reload when account changes
  function resetLoaded() {
    loaded.value = false
    currentAccountEmail.value = null
    trustedSendersLoaded.value = false
    trustedSenders.value = []
  }

  async function updateSettings(newSettings) {
    try {
      // Convert reactive object to plain object for API
      const settingsToSave = JSON.parse(JSON.stringify(newSettings))
      const response = await api.put('/settings', settingsToSave)
      if (response.data.success) {
        // Update local settings with the saved values
        Object.assign(settings.value, settingsToSave)
        // Theme application is handled by the caller (component) to avoid circular deps
        // Note: debug_logs is handled separately in SettingsView via localStorage
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to save settings' }
    }
  }

  // Get signature with proper formatting for email body
  function getSignatureHtml() {
    if (!settings.value.signature || settings.value.signature.trim() === '') {
      return ''
    }
    
    // Add a separator line before the signature
    return `<p><br></p><p>--</p>${settings.value.signature}`
  }
  
  function getDefaults() {
    return {
      display_name: '',
      signature: '',
      messages_per_page: 50,
      theme: 'system',
      accent_color: 'green',
      layout_mode: 'columns',
      display_density: 'cosy', // 'cosy' (default) or 'compact'
      auto_mark_read: true,
      confirm_delete: true,
      refresh_interval: 60, // seconds (0 = disabled)
      large_attachment_threshold: 10, // MB (0 = disabled)
      block_remote_images: true, // Block images from untrusted senders
      override_email_styling: true, // Override inline styles in emails for readability
      locale: 'en', // 'en' or 'hu'
      perspective: 'operations',
      setup_completed: false,
      // Out of Office auto-reply
      ooo_enabled: false,
      ooo_subject: '',
      ooo_message: '',
      ooo_start_date: '',
      ooo_end_date: '',
      undo_send_delay: 0,
      compose_style: 'modal',
      // News Reader — Markets panel basket selection. Empty arrays here
      // tell MarketsService to fall back to its own default basket
      // (see backend MarketsService::DEFAULT_STOCKS / DEFAULT_CRYPTO).
      news_markets_stocks: [],
      news_markets_crypto: [],
      // Note: debug_logs is stored in localStorage only
    }
  }
  
  // Trusted senders management (persisted to backend)
  // Version counter to force Vue reactivity updates when list changes
  const trustedSendersVersion = ref(0)
  
  async function fetchTrustedSenders() {
    debugLog('[TRUSTED-SENDER] fetchTrustedSenders called, alreadyLoaded:', trustedSendersLoaded.value)
    if (trustedSendersLoaded.value) {
      debugLog('[TRUSTED-SENDER] fetchTrustedSenders: already loaded, returning cached:', trustedSenders.value)
      return trustedSenders.value
    }
    
    try {
      debugLog('[TRUSTED-SENDER] fetchTrustedSenders: fetching from API...')
      const response = await api.get('/settings/trusted-senders')
      if (response.data.success) {
        const newSenders = response.data.data.trusted_senders || []
        debugLog('[TRUSTED-SENDER] fetchTrustedSenders: API returned:', newSenders)
        trustedSenders.value = newSenders
        trustedSendersLoaded.value = true
        trustedSendersVersion.value++ // Force reactivity update
        debugLog('[TRUSTED-SENDER] fetchTrustedSenders: loaded successfully, version now:', trustedSendersVersion.value)
        
        // Migrate any existing localStorage entries to backend (one-time
        // migration). ONE HTTP call regardless of how many local entries
        // exist; the legacy code fired N POSTs sequentially.
        const localSenders = getLocalStorageTrustedSenders()
        if (localSenders.length > 0) {
          debugLog('[TRUSTED-SENDER] fetchTrustedSenders: migrating localStorage senders:', localSenders)
          const known = new Set(trustedSenders.value.map(s => s.toLowerCase()))
          const toMigrate = localSenders
            .map(e => String(e).toLowerCase().trim())
            .filter(e => e && !known.has(e))
          if (toMigrate.length > 0) {
            try {
              const importResp = await api.post('/settings/trusted-senders/import', { emails: toMigrate })
              if (importResp.data?.success) {
                trustedSenders.value = importResp.data.data?.trusted_senders || trustedSenders.value
                trustedSendersVersion.value++
              }
            } catch (e) {
              debugLog('[TRUSTED-SENDER] fetchTrustedSenders: batch migration failed, falling back to per-entry:', e)
              for (const email of toMigrate) {
                await addTrustedSender(email)
              }
            }
          }
          // Clear localStorage after migration regardless of result.
          localStorage.removeItem('webmail_trusted_senders')
        }
      }
    } catch (e) {
      console.error('[TRUSTED-SENDER] fetchTrustedSenders: API failed:', e)
      // Fallback to localStorage if API fails
      trustedSenders.value = getLocalStorageTrustedSenders()
      trustedSendersLoaded.value = true // Mark as loaded even on fallback so banner can show
      trustedSendersVersion.value++ // Force reactivity update even on fallback
      debugLog('[TRUSTED-SENDER] fetchTrustedSenders: using localStorage fallback:', trustedSenders.value)
    }
    
    return trustedSenders.value
  }
  
  // Helper to read from localStorage (for migration and fallback)
  function getLocalStorageTrustedSenders() {
    try {
      return JSON.parse(localStorage.getItem('webmail_trusted_senders') || '[]')
    } catch {
      return []
    }
  }
  
  function getTrustedSenders() {
    // Return cached value (call fetchTrustedSenders first to load)
    return trustedSenders.value
  }
  
  function isTrustedSender(email) {
    if (!email) {
      debugLog('[TRUSTED-SENDER] isTrustedSender: email is null/undefined')
      return false
    }
    // Access version to create reactive dependency
    const _ = trustedSendersVersion.value
    // Check cached list
    const emailLower = email.toLowerCase()
    const result = trustedSenders.value.some(s => s.toLowerCase() === emailLower)
    debugLog('[TRUSTED-SENDER] isTrustedSender check:', {
      email,
      emailLower,
      result,
      trustedSendersCount: trustedSenders.value.length,
      trustedSendersLoaded: trustedSendersLoaded.value,
      version: trustedSendersVersion.value,
    })
    return result
  }
  
  async function addTrustedSender(email) {
    debugLog('[TRUSTED-SENDER] addTrustedSender called with:', email)
    if (!email) return false
    
    const lowerEmail = email.toLowerCase()
    
    // Optimistically update local cache
    if (!trustedSenders.value.includes(lowerEmail)) {
      trustedSenders.value.push(lowerEmail)
      trustedSendersVersion.value++ // Force reactivity update
      debugLog('[TRUSTED-SENDER] addTrustedSender: added to local cache, version now:', trustedSendersVersion.value)
    } else {
      debugLog('[TRUSTED-SENDER] addTrustedSender: already in local cache')
    }
    
    try {
      const response = await api.post(`/settings/trusted-senders?email=${encodeURIComponent(lowerEmail)}`, { email: lowerEmail })
      debugLog('[TRUSTED-SENDER] addTrustedSender: API response:', response.data)
      if (response.data.success) {
        trustedSenders.value = response.data.data.trusted_senders || trustedSenders.value
        trustedSendersVersion.value++
        debugLog('[TRUSTED-SENDER] addTrustedSender: success, list now:', trustedSenders.value)
        return true
      }
    } catch (e) {
      console.error('[TRUSTED-SENDER] addTrustedSender: API FAILED:', e?.response?.status, e?.response?.data, { email: lowerEmail })
      trustedSenders.value = trustedSenders.value.filter(s => s !== lowerEmail)
      trustedSendersVersion.value++
      return false
    }
    
    return true
  }
  
  async function removeTrustedSender(email) {
    if (!email) return false
    
    const lowerEmail = email.toLowerCase()
    
    // Optimistically update local cache
    const previousSenders = [...trustedSenders.value]
    trustedSenders.value = trustedSenders.value.filter(s => s.toLowerCase() !== lowerEmail)
    
    try {
      const response = await api.delete('/settings/trusted-senders', { data: { email: lowerEmail } })
      if (response.data.success) {
        trustedSenders.value = response.data.data.trusted_senders || trustedSenders.value
        return true
      }
    } catch (e) {
      console.error('Failed to remove trusted sender:', e)
      // Revert optimistic update on failure
      trustedSenders.value = previousSenders
      return false
    }
    
    return true
  }

  // Debug logs helper - can be called from anywhere
  function isDebugEnabled() {
    return settings.value.debug_logs === true
  }

  function hydrateFromBootstrap(settingsData, trustedSendersData) {
    if (settingsData) {
      const merged = { ...getDefaults(), ...settingsData }
      // debug_logs lives only in localStorage (see fetchSettings); reflect it
      // IN rather than overwriting localStorage from the absent bootstrap value.
      merged.debug_logs = readDebugFromLS()
      settings.value = merged
      loaded.value = true
    }
    if (Array.isArray(trustedSendersData)) {
      trustedSenders.value = trustedSendersData
      trustedSendersLoaded.value = true
      trustedSendersVersion.value++
    }
  }

  return {
    settings,
    loading,
    loaded,
    currentAccountEmail,
    trustedSenders,
    trustedSendersVersion,
    trustedSendersLoaded,
    fetchSettings,
    resetLoaded,
    updateSettings,
    getSignatureHtml,
    getDefaults,
    fetchTrustedSenders,
    getTrustedSenders,
    isTrustedSender,
    addTrustedSender,
    removeTrustedSender,
    isDebugEnabled,
    hydrateFromBootstrap,
  }
})

