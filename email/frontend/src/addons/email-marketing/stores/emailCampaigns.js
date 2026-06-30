import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useMailSyncSocket, EventTypes } from '@/services/mailSyncSocket'
import { isDebugEnabled } from '@/utils/debug'

/**
 * Email Campaigns Store
 * 
 * Manages bulk email campaigns with rate limiting:
 * - Queue bulk emails for background sending
 * - Track campaign progress in real-time
 * - Pause, resume, cancel campaigns
 * - Retry failed emails
 */
export const useEmailCampaignsStore = defineStore('emailCampaigns', () => {
  // State
  const campaigns = ref([])
  const currentCampaign = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const rateLimits = ref(null)
  
  // Computed
  const activeCampaigns = computed(() => 
    campaigns.value.filter(c => ['pending', 'processing'].includes(c.status))
  )
  
  const completedCampaigns = computed(() => 
    campaigns.value.filter(c => c.status === 'completed')
  )
  
  const pausedCampaigns = computed(() => 
    campaigns.value.filter(c => c.status === 'paused')
  )
  
  const draftCampaigns = computed(() =>
    campaigns.value.filter(c => c.status === 'draft')
  )
  
  const hasActiveCampaigns = computed(() => activeCampaigns.value.length > 0)
  
  // Actions
  
  /**
   * Fetch all campaigns for current user
   */
  async function fetchCampaigns() {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.get('/email-queue/campaigns')
      if (response.data.success) {
        campaigns.value = response.data.data?.campaigns ?? []
        isDebugEnabled() && console.log('[EmailCampaigns] Fetched', campaigns.value.length, 'campaigns')
      } else {
        error.value = response.data.error || 'Failed to load campaigns'
        isDebugEnabled() && console.warn('[EmailCampaigns] API returned success=false:', response.data)
      }
    } catch (e) {
      error.value = e.response?.data?.error || 'Failed to load campaigns'
      console.error('[EmailCampaigns] Failed to fetch campaigns:', e)
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Get a single campaign by ID
   */
  async function fetchCampaign(campaignId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.get(`/email-queue/campaigns/${campaignId}`)
      if (response.data.success) {
        currentCampaign.value = response.data.data.campaign
        
        // Update in campaigns list
        const index = campaigns.value.findIndex(c => c.campaign_id === campaignId)
        if (index !== -1) {
          campaigns.value[index] = { ...campaigns.value[index], ...response.data.data.campaign }
        }
        
        return response.data.data.campaign
      }
    } catch (e) {
      error.value = e.response?.data?.error || 'Failed to load campaign'
      console.error('Failed to fetch campaign:', e)
    } finally {
      loading.value = false
    }
    
    return null
  }
  
  /**
   * Fetch full campaign analytics (recipients, opens, clicks, unsubscribes)
   */
  async function fetchCampaignAnalytics(campaignId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.get(`/email-queue/campaigns/${campaignId}/analytics`)
      if (response.data.success) {
        const analytics = response.data.data
        currentCampaign.value = analytics.campaign
        return { success: true, ...analytics }
      }
      return { success: false, error: response.data.error || 'Failed to load analytics' }
    } catch (e) {
      error.value = e.response?.data?.error || 'Failed to load campaign analytics'
      console.error('[EmailCampaigns] Failed to fetch analytics:', e)
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Queue a bulk email for sending
   * Returns campaign info including estimated send time
   */
  async function queueBulkEmail(emailData) {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.post('/email-queue/send', emailData)
      
      if (response.data.success) {
        // Add to campaigns list
        const newCampaign = {
          campaign_id: response.data.data.campaign_id,
          subject: emailData.subject,
          total_recipients: response.data.data.total_recipients,
          sent_count: 0,
          failed_count: 0,
          status: 'pending',
          progress_percent: 0,
          created_at: new Date().toISOString()
        }
        campaigns.value.unshift(newCampaign)
        
        return {
          success: true,
          campaignId: response.data.data.campaign_id,
          totalRecipients: response.data.data.total_recipients,
          estimatedHours: response.data.data.estimated_hours,
          message: response.data.data.message
        }
      }
      
      return { success: false, error: response.data.error || 'Failed to queue email' }
      
    } catch (e) {
      const errorMsg = e.response?.data?.error || 'Failed to queue email'
      error.value = errorMsg
      console.error('Failed to queue bulk email:', e)
      return { success: false, error: errorMsg }
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Pause a campaign
   */
  async function pauseCampaign(campaignId) {
    try {
      const response = await api.post(`/email-queue/campaigns/${campaignId}/pause`)
      
      if (response.data.success) {
        // Update local state
        const index = campaigns.value.findIndex(c => c.campaign_id === campaignId)
        if (index !== -1) {
          campaigns.value[index].status = 'paused'
        }
        if (currentCampaign.value?.campaign_id === campaignId) {
          currentCampaign.value.status = 'paused'
        }
        return { success: true }
      }
      
      return { success: false, error: response.data.error }
      
    } catch (e) {
      console.error('Failed to pause campaign:', e)
      return { success: false, error: e.response?.data?.error || 'Failed to pause campaign' }
    }
  }
  
  /**
   * Resume a paused campaign
   */
  async function resumeCampaign(campaignId) {
    try {
      const response = await api.post(`/email-queue/campaigns/${campaignId}/resume`)
      
      if (response.data.success) {
        // Update local state
        const index = campaigns.value.findIndex(c => c.campaign_id === campaignId)
        if (index !== -1) {
          campaigns.value[index].status = 'processing'
        }
        if (currentCampaign.value?.campaign_id === campaignId) {
          currentCampaign.value.status = 'processing'
        }
        return { success: true }
      }
      
      return { success: false, error: response.data.error }
      
    } catch (e) {
      console.error('Failed to resume campaign:', e)
      return { success: false, error: e.response?.data?.error || 'Failed to resume campaign' }
    }
  }
  
  /**
   * Cancel a campaign
   */
  async function cancelCampaign(campaignId) {
    try {
      const response = await api.delete(`/email-queue/campaigns/${campaignId}`)
      
      if (response.data.success) {
        // Update local state
        const index = campaigns.value.findIndex(c => c.campaign_id === campaignId)
        if (index !== -1) {
          campaigns.value[index].status = 'cancelled'
        }
        if (currentCampaign.value?.campaign_id === campaignId) {
          currentCampaign.value.status = 'cancelled'
        }
        return { success: true }
      }
      
      return { success: false, error: response.data.error }
      
    } catch (e) {
      console.error('Failed to cancel campaign:', e)
      return { success: false, error: e.response?.data?.error || 'Failed to cancel campaign' }
    }
  }
  
  /**
   * Permanently delete a campaign and all related data
   */
  async function deleteCampaign(campaignId) {
    try {
      const response = await api.post(`/email-queue/campaigns/${campaignId}/delete`)
      
      if (response.data.success) {
        campaigns.value = campaigns.value.filter(c => c.campaign_id !== campaignId)
        if (currentCampaign.value?.campaign_id === campaignId) {
          currentCampaign.value = null
        }
        return { success: true }
      }
      
      return { success: false, error: response.data.error }
      
    } catch (e) {
      console.error('Failed to delete campaign:', e)
      return { success: false, error: e.response?.data?.error || 'Failed to delete campaign' }
    }
  }
  
  /**
   * Get failed recipients for a campaign
   */
  async function getFailedRecipients(campaignId) {
    try {
      const response = await api.get(`/email-queue/campaigns/${campaignId}/failed`)
      
      if (response.data.success) {
        return { success: true, failed: response.data.data.failed }
      }
      
      return { success: false, error: response.data.error, failed: [] }
      
    } catch (e) {
      console.error('Failed to get failed recipients:', e)
      return { success: false, error: e.response?.data?.error || 'Failed to load', failed: [] }
    }
  }
  
  /**
   * Retry failed emails in a campaign
   */
  async function retryFailed(campaignId) {
    try {
      const response = await api.post(`/email-queue/campaigns/${campaignId}/retry`)
      
      if (response.data.success) {
        // Refresh campaign data
        await fetchCampaign(campaignId)
        return { success: true, retried: response.data.data.retried }
      }
      
      return { success: false, error: response.data.error }
      
    } catch (e) {
      console.error('Failed to retry:', e)
      return { success: false, error: e.response?.data?.error || 'Failed to retry' }
    }
  }
  
  /**
   * Fetch current rate limits
   */
  async function fetchRateLimits() {
    try {
      const response = await api.get('/email-queue/rate-limits')
      
      if (response.data.success) {
        rateLimits.value = response.data.data
        return rateLimits.value
      }
    } catch (e) {
      console.error('Failed to fetch rate limits:', e)
    }
    return null
  }
  
  /**
   * Update campaign progress from WebSocket event
   */
  function updateCampaignProgress(payload) {
    const { campaign_id, total_recipients, sent_count, failed_count, progress_percent, status } = payload
    
    const index = campaigns.value.findIndex(c => c.campaign_id === campaign_id)
    if (index !== -1) {
      campaigns.value[index] = {
        ...campaigns.value[index],
        total_recipients,
        sent_count,
        failed_count,
        progress_percent,
        status
      }
    }
    
    if (currentCampaign.value?.campaign_id === campaign_id) {
      currentCampaign.value = {
        ...currentCampaign.value,
        total_recipients,
        sent_count,
        failed_count,
        progress_percent,
        status
      }
    }
  }
  
  /**
   * Handle campaign update from WebSocket event
   */
  function handleCampaignUpdate(payload) {
    const { action, campaign_id, campaign } = payload
    
    if (action === 'completed') {
      // Campaign completed - update status
      const index = campaigns.value.findIndex(c => c.campaign_id === campaign_id)
      if (index !== -1) {
        campaigns.value[index].status = 'completed'
        campaigns.value[index].completed_at = new Date().toISOString()
      }
    } else if (action === 'paused' || action === 'resumed' || action === 'cancelled') {
      // Status changed
      const index = campaigns.value.findIndex(c => c.campaign_id === campaign_id)
      if (index !== -1 && campaign) {
        campaigns.value[index] = { ...campaigns.value[index], ...campaign }
      }
    }
  }
  
  // ========================================
  // DRAFT CAMPAIGN ACTIONS
  // ========================================
  
  async function createDraft(subject = '') {
    try {
      const response = await api.post('/email-queue/campaigns/draft', { subject })
      if (response.data.success) {
        const newDraft = {
          campaign_id: response.data.data.campaign_id,
          subject,
          total_recipients: 0,
          sent_count: 0,
          failed_count: 0,
          status: 'draft',
          progress_percent: 0,
          created_at: new Date().toISOString()
        }
        campaigns.value.unshift(newDraft)
        return { success: true, campaignId: response.data.data.campaign_id }
      }
      return { success: false, error: response.data.error || 'Failed to create draft' }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || 'Failed to create draft' }
    }
  }
  
  async function updateDraft(campaignId, data) {
    try {
      const payload = { ...data }
      if (payload.body_html) {
        payload.body_html_b64 = btoa(unescape(encodeURIComponent(payload.body_html)))
        delete payload.body_html
      }
      const response = await api.post(`/email-queue/campaigns/${campaignId}/draft`, payload)
      if (response.data.success) {
        const idx = campaigns.value.findIndex(c => c.campaign_id === campaignId)
        if (idx !== -1) {
          campaigns.value[idx] = { ...campaigns.value[idx], ...data, updated_at: new Date().toISOString() }
        }
        return { success: true, serverData: response.data.data }
      }
      return { success: false, error: response.data.error || 'Failed to update draft' }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || 'Failed to update draft' }
    }
  }
  
  async function finalizeDraft(campaignId) {
    try {
      const response = await api.post(`/email-queue/campaigns/${campaignId}/finalize`)
      if (response.data.success) {
        const idx = campaigns.value.findIndex(c => c.campaign_id === campaignId)
        if (idx !== -1) {
          campaigns.value[idx].status = 'pending'
          campaigns.value[idx].total_recipients = response.data.data.total_recipients
        }
        return {
          success: true,
          totalRecipients: response.data.data.total_recipients,
          estimatedHours: response.data.data.estimated_hours,
          message: response.data.data.message
        }
      }
      return { success: false, error: response.data.error || 'Failed to send campaign' }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || 'Failed to send campaign' }
    }
  }
  
  /**
   * Calculate estimated completion time for a recipient count
   */
  function calculateEstimatedTime(recipientCount) {
    const HOURLY_LIMIT = 100
    const hours = Math.ceil(recipientCount / HOURLY_LIMIT)
    
    if (hours <= 1) {
      return 'less than 1 hour'
    } else if (hours < 24) {
      return `approximately ${hours} hour${hours > 1 ? 's' : ''}`
    } else {
      const days = Math.ceil(hours / 24)
      return `approximately ${days} day${days > 1 ? 's' : ''}`
    }
  }
  
  /**
   * Clear store state
   */
  function $reset() {
    campaigns.value = []
    currentCampaign.value = null
    loading.value = false
    error.value = null
    rateLimits.value = null
  }
  
  // Get socket instance
  let socket = null
  
  /**
   * Register WebSocket event handlers
   */
  function registerWebSocketHandlers() {
    try {
      socket = useMailSyncSocket()
      
      // Progress updates (sent during queue processing)
      socket.on(EventTypes.CAMPAIGN_PROGRESS, (payload) => {
        isDebugEnabled() && console.log('[EmailCampaigns] Progress update:', payload)
        updateCampaignProgress(payload)
      })
      
      // Campaign status changes (paused, resumed, completed, cancelled)
      socket.on(EventTypes.CAMPAIGN_UPDATE, (payload) => {
        isDebugEnabled() && console.log('[EmailCampaigns] Campaign update:', payload)
        handleCampaignUpdate(payload)
      })
    } catch (e) {
      console.warn('[EmailCampaigns] Could not register WebSocket handlers:', e)
    }
  }
  
  /**
   * Unregister WebSocket event handlers
   */
  function unregisterWebSocketHandlers() {
    if (socket) {
      socket.off(EventTypes.CAMPAIGN_PROGRESS)
      socket.off(EventTypes.CAMPAIGN_UPDATE)
    }
  }
  
  return {
    // State
    campaigns,
    currentCampaign,
    loading,
    error,
    rateLimits,
    
    // Computed
    activeCampaigns,
    completedCampaigns,
    pausedCampaigns,
    draftCampaigns,
    hasActiveCampaigns,
    
    // Actions
    fetchCampaigns,
    fetchCampaign,
    fetchCampaignAnalytics,
    queueBulkEmail,
    createDraft,
    updateDraft,
    finalizeDraft,
    pauseCampaign,
    resumeCampaign,
    cancelCampaign,
    deleteCampaign,
    getFailedRecipients,
    retryFailed,
    fetchRateLimits,
    updateCampaignProgress,
    handleCampaignUpdate,
    calculateEstimatedTime,
    registerWebSocketHandlers,
    unregisterWebSocketHandlers,
    $reset
  }
})

