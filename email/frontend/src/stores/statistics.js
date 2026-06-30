import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useAddons } from '@/composables/useAddons'

export const useStatisticsStore = defineStore('statistics', () => {
  // State
  const loading = ref(false)
  const error = ref(null)
  const period = ref('week') // 'day', 'week', 'month', 'year', 'all'
  const lastUpdated = ref(null)
  
  // Statistics data
  const overview = ref(null)
  const emailStats = ref(null)
  const topContacts = ref([])
  const activeConversations = ref([])
  const taskStats = ref(null)
  const calendarStats = ref(null)
  const driveStats = ref(null)
  const boardStats = ref(null)
  const clientStats = ref(null)
  const aiStats = ref(null)
  const timeStats = ref(null)
  const folderStats = ref([])
  const preferenceStats = ref(null)
  const readReceipts = ref(null)
  const recentEvents = ref([])
  
  // Computed
  const hasData = computed(() => overview.value !== null)
  
  const totalEmails = computed(() => {
    if (!emailStats.value?.totals) return 0
    return (emailStats.value.totals.emails_sent || 0) + (emailStats.value.totals.emails_received || 0)
  })
  
  const emailsSent = computed(() => emailStats.value?.totals?.emails_sent || 0)
  const emailsReceived = computed(() => emailStats.value?.totals?.emails_received || 0)
  
  const avgReplyTime = computed(() => {
    const seconds = emailStats.value?.avg_reply_time_seconds
    if (!seconds) return null
    
    if (seconds < 60) return `${Math.round(seconds)}s`
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`
    if (seconds < 86400) return `${(seconds / 3600).toFixed(1)}h`
    return `${(seconds / 86400).toFixed(1)}d`
  })
  
  const taskCompletionRate = computed(() => taskStats.value?.completion_rate || 0)
  
  const totalTimeSpent = computed(() => {
    if (!timeStats.value?.total_seconds) return '0m'
    const seconds = timeStats.value.total_seconds
    
    if (seconds < 60) return `${seconds}s`
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`
    if (seconds < 86400) return `${(seconds / 3600).toFixed(1)}h`
    return `${(seconds / 86400).toFixed(1)}d`
  })
  
  const totalAIUsage = computed(() => {
    if (!aiStats.value) return 0
    return (aiStats.value.summaries || 0) + (aiStats.value.rewrites || 0)
  })
  
  // Actions
  async function fetchOverview() {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.get('/statistics/overview', {
        params: { period: period.value }
      })
      
      if (response.data.success) {
        overview.value = response.data.data
        
        // Extract individual stats from overview
        emailStats.value = overview.value.email
        topContacts.value = overview.value.top_contacts || []
        activeConversations.value = overview.value.active_conversations || []
        taskStats.value = overview.value.tasks
        calendarStats.value = overview.value.calendar
        driveStats.value = overview.value.drive
        boardStats.value = overview.value.boards
        clientStats.value = overview.value.clients
        aiStats.value = overview.value.ai
        timeStats.value = overview.value.time
        folderStats.value = overview.value.folders || []
        preferenceStats.value = overview.value.preferences
        // Only populate read receipts if email tracking addon is enabled
        const { emailTrackingEnabled } = useAddons()
        readReceipts.value = emailTrackingEnabled.value ? (overview.value.read_receipts || null) : null
        
        lastUpdated.value = new Date()
      }
    } catch (err) {
      console.error('Failed to fetch statistics overview:', err)
      error.value = err.response?.data?.error || 'Failed to load statistics'
    } finally {
      loading.value = false
    }
  }
  
  async function fetchEmailStats() {
    try {
      const response = await api.get('/statistics/emails', {
        params: { period: period.value }
      })
      
      if (response.data.success) {
        emailStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch email stats:', err)
    }
  }
  
  async function fetchTopContacts(limit = 10) {
    try {
      const response = await api.get('/statistics/contacts', {
        params: { limit }
      })
      
      if (response.data.success) {
        topContacts.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch top contacts:', err)
    }
  }
  
  async function fetchActiveConversations(limit = 10) {
    try {
      const response = await api.get('/statistics/conversations', {
        params: { period: period.value, limit }
      })
      
      if (response.data.success) {
        activeConversations.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch active conversations:', err)
    }
  }
  
  async function fetchTaskStats() {
    const { tasksEnabled } = useAddons()
    if (!tasksEnabled.value) return
    try {
      const response = await api.get('/statistics/tasks', {
        params: { period: period.value }
      })
      
      if (response.data.success) {
        taskStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch task stats:', err)
    }
  }
  
  async function fetchCalendarStats() {
    const { calendarEnabled } = useAddons()
    if (!calendarEnabled.value) return

    try {
      const response = await api.get('/statistics/calendar', {
        params: { period: period.value }
      })
      
      if (response.data.success) {
        calendarStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch calendar stats:', err)
    }
  }
  
  async function fetchDriveStats() {
    try {
      const response = await api.get('/statistics/drive', {
        params: { period: period.value }
      })
      
      if (response.data.success) {
        driveStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch drive stats:', err)
    }
  }
  
  async function fetchAIStats() {
    const { aiAssistantEnabled } = useAddons()
    if (!aiAssistantEnabled.value) {
      aiStats.value = null
      return
    }
    try {
      const response = await api.get('/statistics/ai', {
        params: { period: period.value }
      })
      
      if (response.data.success) {
        aiStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch AI stats:', err)
    }
  }
  
  async function fetchTimeStats() {
    const { timeTrackerEnabled } = useAddons()
    if (!timeTrackerEnabled.value) { timeStats.value = null; return }
    try {
      const response = await api.get('/statistics/time', {
        params: { period: period.value }
      })
      
      if (response.data.success) {
        timeStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch time stats:', err)
    }
  }
  
  async function fetchFolderStats() {
    try {
      const response = await api.get('/statistics/folders')
      
      if (response.data.success) {
        folderStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch folder stats:', err)
    }
  }
  
  async function fetchPreferenceStats() {
    try {
      const response = await api.get('/statistics/preferences')
      
      if (response.data.success) {
        preferenceStats.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch preference stats:', err)
    }
  }
  
  async function fetchRecentEvents(limit = 50) {
    try {
      const response = await api.get('/statistics/events', {
        params: { limit }
      })
      
      if (response.data.success) {
        recentEvents.value = response.data.data
      }
    } catch (err) {
      console.error('Failed to fetch recent events:', err)
    }
  }
  
  // Track time spent
  async function trackTime(section, durationSeconds, folder = null) {
    if (durationSeconds < 1) return true
    const { timeTrackerEnabled } = useAddons()
    if (!timeTrackerEnabled.value) return false
    
    try {
      await api.post('/statistics/track-time', {
        section,
        duration_seconds: durationSeconds,
        folder
      })
      return true
    } catch (err) {
      console.error('Failed to track time:', err)
      return false
    }
  }
  
  // Log an event
  async function logEvent(eventType, eventData = {}) {
    try {
      await api.post('/statistics/log-event', {
        event_type: eventType,
        event_data: eventData
      })
    } catch (err) {
      console.error('Failed to log event:', err)
    }
  }
  
  // Set period and refresh data
  function setPeriod(newPeriod) {
    period.value = newPeriod
    fetchOverview()
  }
  
  // Refresh all statistics
  function refresh() {
    fetchOverview()
  }
  
  // Reset state
  function reset() {
    loading.value = false
    error.value = null
    overview.value = null
    emailStats.value = null
    topContacts.value = []
    activeConversations.value = []
    taskStats.value = null
    calendarStats.value = null
    driveStats.value = null
    boardStats.value = null
    clientStats.value = null
    aiStats.value = null
    timeStats.value = null
    folderStats.value = []
    preferenceStats.value = null
    readReceipts.value = null
    recentEvents.value = []
    lastUpdated.value = null
  }
  
  return {
    // State
    loading,
    error,
    period,
    lastUpdated,
    overview,
    emailStats,
    topContacts,
    activeConversations,
    taskStats,
    calendarStats,
    driveStats,
    boardStats,
    clientStats,
    aiStats,
    timeStats,
    folderStats,
    preferenceStats,
    readReceipts,
    recentEvents,
    
    // Computed
    hasData,
    totalEmails,
    emailsSent,
    emailsReceived,
    avgReplyTime,
    taskCompletionRate,
    totalTimeSpent,
    totalAIUsage,
    
    // Actions
    fetchOverview,
    fetchEmailStats,
    fetchTopContacts,
    fetchActiveConversations,
    fetchTaskStats,
    fetchCalendarStats,
    fetchDriveStats,
    fetchAIStats,
    fetchTimeStats,
    fetchFolderStats,
    fetchPreferenceStats,
    fetchRecentEvents,
    trackTime,
    logEvent,
    setPeriod,
    refresh,
    reset
  }
})

