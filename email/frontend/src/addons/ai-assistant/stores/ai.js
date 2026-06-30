import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

// Summary cache duration: 24 hours
const SUMMARY_CACHE_DURATION = 24 * 60 * 60 * 1000

// Max content length for AI summarization (must match backend)
const MAX_SUMMARIZE_LENGTH = 40000
const AVG_EMAIL_SIZE = 1500

// Track events silently (non-blocking)
async function trackEvent(eventType, eventData = {}) {
  try {
    await api.post('/statistics/log-event', { event_type: eventType, event_data: eventData })
  } catch (e) {
    // Silent fail
  }
}

export const useAIStore = defineStore('ai', () => {
  // State
  const configured = ref(false)
  const model = ref('gpt-5-nano')
  const writingStyle = ref('professional')
  const models = ref({})
  const styles = ref({})
  const defaultPrompts = ref({})
  const customPrompts = ref({
    summarize: '',
    rewrite: '',
    draft_reply: '',
  })
  
  // Loading states
  const loading = ref(false)
  const summarizing = ref(false)
  const rewriting = ref(false)
  const draftingReply = ref(false)
  
  // Summary panel state
  const summaryPanelOpen = ref(false)
  const currentSummary = ref(null)
  const summaryError = ref(null)
  const summaryDebug = ref(null)
  const currentSummaryCacheKey = ref(null)
  
  // Computed
  const isConfigured = computed(() => configured.value)
  const currentModel = computed(() => models.value[model.value]?.name || model.value)
  const currentStyle = computed(() => styles.value[writingStyle.value] || writingStyle.value)
  
  /**
   * Generate cache key for an email/conversation
   */
  function generateCacheKey(folder, uid, messageId) {
    return `ai_summary_${folder}_${uid}_${messageId || ''}`
  }
  
  /**
   * Get cached summary from localStorage
   */
  function getCachedSummary(cacheKey) {
    try {
      const cached = localStorage.getItem(cacheKey)
      if (!cached) return null
      
      const data = JSON.parse(cached)
      const now = Date.now()
      
      // Check if expired
      if (now > data.expiresAt) {
        localStorage.removeItem(cacheKey)
        return null
      }
      
      return {
        summary: data.summary,
        cachedAt: data.cachedAt,
        expiresAt: data.expiresAt,
        hoursRemaining: Math.ceil((data.expiresAt - now) / (60 * 60 * 1000))
      }
    } catch (e) {
      console.error('Failed to get cached summary:', e)
      return null
    }
  }
  
  /**
   * Save summary to localStorage cache
   */
  function cacheSummary(cacheKey, summary) {
    try {
      const now = Date.now()
      const data = {
        summary,
        cachedAt: now,
        expiresAt: now + SUMMARY_CACHE_DURATION
      }
      localStorage.setItem(cacheKey, JSON.stringify(data))
    } catch (e) {
      console.error('Failed to cache summary:', e)
    }
  }
  
  /**
   * Check if a message has a valid cached summary
   */
  function hasCachedSummary(folder, uid, messageId) {
    const cacheKey = generateCacheKey(folder, uid, messageId)
    return getCachedSummary(cacheKey) !== null
  }
  
  /**
   * Get cache info for a message
   */
  function getSummaryCacheInfo(folder, uid, messageId) {
    const cacheKey = generateCacheKey(folder, uid, messageId)
    return getCachedSummary(cacheKey)
  }
  
  /**
   * Clear cached summary for a message
   */
  function clearCachedSummary(folder, uid, messageId) {
    const cacheKey = generateCacheKey(folder, uid, messageId)
    localStorage.removeItem(cacheKey)
  }
  
  function hydrateFromInit(data) {
    if (!data) return
    configured.value = data.configured ?? false
    model.value = data.model ?? 'gpt-5-nano'
    writingStyle.value = data.style ?? 'professional'
    models.value = data.models ?? {}
    styles.value = data.styles ?? {}
    defaultPrompts.value = data.default_prompts ?? {}
  }

  /**
   * Fetch AI configuration from server
   */
  async function fetchConfig() {
    loading.value = true
    try {
      const response = await api.get('/ai/config')
      if (response.data.success) {
        const data = response.data.data
        configured.value = data.configured
        model.value = data.model
        writingStyle.value = data.style
        models.value = data.models
        styles.value = data.styles
        defaultPrompts.value = data.default_prompts
      }
    } catch (e) {
      console.error('Failed to fetch AI config:', e)
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Fetch AI settings for settings page
   */
  async function fetchSettings() {
    loading.value = true
    try {
      const response = await api.get('/settings/ai')
      if (response.data.success) {
        const data = response.data.data
        configured.value = data.configured
        model.value = data.model
        writingStyle.value = data.writing_style
        models.value = data.available_models
        styles.value = data.available_styles
        defaultPrompts.value = data.default_prompts
        customPrompts.value = {
          summarize: data.prompts.summarize || '',
          rewrite: data.prompts.rewrite || '',
          draft_reply: data.prompts.draft_reply || '',
        }
        return data
      }
    } catch (e) {
      console.error('Failed to fetch AI settings:', e)
    } finally {
      loading.value = false
    }
    return null
  }
  
  /**
   * Save AI settings
   */
  async function saveSettings(settings) {
    loading.value = true
    isDebugEnabled() && console.log('AI Store: Saving settings...', settings)
    try {
      const response = await api.put('/settings/ai', settings)
      isDebugEnabled() && console.log('AI Store: Save response:', response.data)
      if (response.data.success) {
        const data = response.data.data
        configured.value = data.configured
        model.value = data.model
        writingStyle.value = data.writing_style
        isDebugEnabled() && console.log('AI Store: Settings saved, configured:', data.configured)
        return { success: true }
      }
      console.error('AI Store: Save failed:', response.data.message)
      return { success: false, error: response.data.message }
    } catch (e) {
      console.error('AI Store: Save exception:', e)
      return { success: false, error: e.response?.data?.message || 'Failed to save settings' }
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Summarize email content (with caching)
   * @param {string} emailContent - The email content to summarize
   * @param {object} cacheInfo - Optional { folder, uid, messageId, userEmail } for caching
   * @param {boolean} forceRefresh - Force new summary even if cached
   */
  async function summarize(emailContent, cacheInfo = null, forceRefresh = false) {
    if (!configured.value) {
      return { success: false, error: 'AI not configured. Please add your API key in Settings.' }
    }
    
    // Check cache first (unless force refresh)
    let cacheKey = null
    if (cacheInfo && !forceRefresh) {
      cacheKey = generateCacheKey(cacheInfo.folder, cacheInfo.uid, cacheInfo.messageId)
      const cached = getCachedSummary(cacheKey)
      
      if (cached) {
        isDebugEnabled() && console.log('aiStore.summarize - Using cached summary, hours remaining:', cached.hoursRemaining)
        currentSummary.value = cached.summary
        currentSummaryCacheKey.value = cacheKey
        return { 
          success: true, 
          summary: cached.summary,
          cached: true,
          hoursRemaining: cached.hoursRemaining
        }
      }
    }
    
    isDebugEnabled() && console.log('aiStore.summarize - emailContent length:', emailContent?.length)
    isDebugEnabled() && console.log('aiStore.summarize - userEmail:', cacheInfo?.userEmail)
    
    if (!emailContent || emailContent.length < 10) {
      console.error('aiStore.summarize - Email content is empty or too short!')
      return { success: false, error: 'No email content to summarize' }
    }
    
    if (emailContent.length > MAX_SUMMARIZE_LENGTH) {
      const approxEmails = Math.floor(MAX_SUMMARIZE_LENGTH / AVG_EMAIL_SIZE)
      const error = `This conversation is too lengthy for AI summarization. The maximum allowed length is ${MAX_SUMMARIZE_LENGTH.toLocaleString()} characters (roughly the last ${approxEmails} emails). Please try summarizing a shorter thread.`
      summaryError.value = error
      return { success: false, error, too_long: true, max_length: MAX_SUMMARIZE_LENGTH, current_length: emailContent.length }
    }
    
    summarizing.value = true
    summaryError.value = null
    
    try {
      const response = await api.post('/ai/summarize', {
        email_content: emailContent,
        user_email: cacheInfo?.userEmail || null
      })
      
      isDebugEnabled() && console.log('AI Summary full response:', response.data)
      
      if (response.data.success) {
        isDebugEnabled() && console.log('AI Summary response:', response.data.data.summary)
        currentSummary.value = response.data.data.summary
        
        // Cache the summary if we have cache info
        if (cacheInfo) {
          cacheKey = cacheKey || generateCacheKey(cacheInfo.folder, cacheInfo.uid, cacheInfo.messageId)
          cacheSummary(cacheKey, response.data.data.summary)
          currentSummaryCacheKey.value = cacheKey
        }
        
        // Track AI summary usage
        trackEvent('ai_summary')
        
        return { 
          success: true, 
          summary: response.data.data.summary,
          cached: false,
          hoursRemaining: 24,
          _debug: response.data.data._debug 
        }
      }
      
      const debug = response.data.data?._debug
      summaryError.value = response.data.message
      summaryDebug.value = debug
      return { 
        success: false, 
        error: response.data.message,
        _debug: debug 
      }
    } catch (e) {
      console.error('AI Summary exception:', e.response?.data)
      const error = e.response?.data?.message || 'Failed to summarize email'
      const debug = e.response?.data?.data?._debug
      summaryError.value = error
      summaryDebug.value = debug
      return { 
        success: false, 
        error,
        _debug: debug 
      }
    } finally {
      summarizing.value = false
    }
  }
  
  /**
   * Rewrite text with AI
   */
  async function rewrite(text, style = null) {
    if (!configured.value) {
      return { success: false, error: 'AI not configured. Please add your API key in Settings.' }
    }
    
    isDebugEnabled() && console.log('aiStore.rewrite - text length:', text?.length)
    isDebugEnabled() && console.log('aiStore.rewrite - text preview:', text?.substring(0, 200))
    isDebugEnabled() && console.log('aiStore.rewrite - style:', style || writingStyle.value)
    
    if (!text || text.trim().length < 3) {
      console.error('aiStore.rewrite - Text is empty or too short!')
      return { success: false, error: 'No text to rewrite' }
    }
    
    rewriting.value = true
    
    try {
      const response = await api.post('/ai/rewrite', {
        text,
        style: style || writingStyle.value
      })
      
      if (response.data.success) {
        // Track AI rewrite usage
        trackEvent('ai_rewrite')
        return { success: true, rewritten: response.data.data.rewritten }
      }
      
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to rewrite text' }
    } finally {
      rewriting.value = false
    }
  }
  
  /**
   * Generate draft reply
   */
  async function draftReply(emailContent, style = null, instructions = '') {
    if (!configured.value) {
      return { success: false, error: 'AI not configured. Please add your API key in Settings.' }
    }
    
    draftingReply.value = true
    
    try {
      const response = await api.post('/ai/draft-reply', {
        email_content: emailContent,
        style: style || writingStyle.value,
        instructions
      })
      
      if (response.data.success) {
        return { success: true, draft: response.data.data.draft }
      }
      
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to generate reply' }
    } finally {
      draftingReply.value = false
    }
  }
  
  /**
   * Open summary panel
   */
  function openSummaryPanel() {
    summaryPanelOpen.value = true
  }
  
  /**
   * Close summary panel
   */
  function closeSummaryPanel() {
    summaryPanelOpen.value = false
  }
  
  /**
   * Toggle summary panel
   */
  function toggleSummaryPanel() {
    summaryPanelOpen.value = !summaryPanelOpen.value
  }
  
  /**
   * Clear current summary
   */
  function clearSummary() {
    currentSummary.value = null
    summaryError.value = null
  }
  
  return {
    // State
    configured,
    model,
    writingStyle,
    models,
    styles,
    defaultPrompts,
    customPrompts,
    loading,
    summarizing,
    rewriting,
    draftingReply,
    summaryPanelOpen,
    currentSummary,
    summaryError,
    summaryDebug,
    currentSummaryCacheKey,
    
    // Computed
    isConfigured,
    currentModel,
    currentStyle,
    
    // Actions
    fetchConfig,
    hydrateFromInit,
    fetchSettings,
    saveSettings,
    summarize,
    hasCachedSummary,
    getSummaryCacheInfo,
    clearCachedSummary,
    rewrite,
    draftReply,
    openSummaryPanel,
    closeSummaryPanel,
    toggleSummaryPanel,
    clearSummary,
  }
})


