import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'
import { isElectron } from '@/services/electronApi'

const VALID_TABS = ['all', 'email', 'attachments', 'calendar', 'drive', 'boards', 'todos', 'clients', 'chats', 'moodboards']

function emptyCounts() {
  return { total: 0, email: 0, email_attachment: 0, calendar_event: 0, drive_file: 0, drive_folder: 0, board: 0, card: 0, todo: 0, client: 0, collab_doc: 0, chat_message: 0, mood_board_item: 0 }
}

/**
 * Universal Search Store
 * Manages state for Super Master Search across emails, drive, boards, todos, clients
 */
export const useSearchStore = defineStore('search', () => {
  // State
  const query = ref('')
  const results = ref([])
  const groupedResults = ref({})
  const counts = ref(emptyCounts())
  const aiAnswer = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const activeTab = ref('all') // 'all', 'email', 'drive', 'boards', 'todos', 'clients'
  const isOpen = ref(false) // For global search modal/panel
  const searchEngine = ref(null) // 'meilisearch', 'fulltext', 'like', or null
  
  // Filters
  const filters = ref({
    types: null, // Array of types to search, null = all
    clientId: null,
    boardId: null,
    dateFrom: null,
    dateTo: null,
  })
  
  // Quick search results (for autocomplete)
  const quickResults = ref([])
  const quickLoading = ref(false)
  
  // Index stats
  const indexStats = ref(null)
  
  // Attachment indexing state
  const attachmentIndexing = ref({
    running: false,
    lastRun: null,
    remaining: 0,
    processed: 0,
    consecutiveErrors: 0,
    disabled: false,
  })
  let attachmentIndexInterval = null
  let attachmentInitialTimeout = null
  const ATTACHMENT_INDEX_MAX_ERRORS = 3
  
  // Email body indexing state
  const bodyIndexing = ref({
    running: false,
    lastRun: null,
    remaining: 0,
    processed: 0,
  })
  let bodyIndexInterval = null
  let bodyInitialTimeout = null
  let bodyBatchTimeout = null
  
  // Computed
  const hasResults = computed(() => results.value.length > 0)
  const hasAiAnswer = computed(() => aiAnswer.value !== null)
  
  // Get results by type
  const emailResults = computed(() => groupedResults.value.email || [])
  const attachmentResults = computed(() => groupedResults.value.email_attachment || [])
  const driveResults = computed(() => [
    ...(groupedResults.value.drive_file || []),
    ...(groupedResults.value.drive_folder || []),
  ])
  const boardResults = computed(() => [
    ...(groupedResults.value.board || []),
    ...(groupedResults.value.card || []),
  ])
  const todoResults = computed(() => groupedResults.value.todo || [])
  const clientResults = computed(() => groupedResults.value.client || [])
  const calendarResults = computed(() => groupedResults.value.calendar_event || [])
  const chatResults = computed(() => groupedResults.value.chat_message || [])
  const moodBoardResults = computed(() => groupedResults.value.mood_board_item || [])
  
  // Get results for current tab
  const filteredResults = computed(() => {
    switch (activeTab.value) {
      case 'email':
        return emailResults.value
      case 'attachments':
        return attachmentResults.value
      case 'calendar':
        return calendarResults.value
      case 'drive':
        return driveResults.value
      case 'boards':
        return boardResults.value
      case 'todos':
        return todoResults.value
      case 'clients':
        return clientResults.value
      case 'chats':
        return chatResults.value
      case 'moodboards':
        return moodBoardResults.value
      default:
        return results.value
    }
  })
  
  // Tab counts
  const tabCounts = computed(() => ({
    all: counts.value.total,
    email: counts.value.email,
    attachments: counts.value.email_attachment,
    calendar: counts.value.calendar_event,
    drive: counts.value.drive_file + counts.value.drive_folder,
    boards: counts.value.board + counts.value.card,
    todos: counts.value.todo,
    clients: counts.value.client,
    chats: counts.value.chat_message,
    moodboards: counts.value.mood_board_item,
  }))

  // Actions
  
  // Track current search to prevent race conditions
  let currentSearchId = 0
  let currentQuickSearchId = 0
  
  /**
   * Main search - full search with all options
   */
  async function search(searchQuery, options = {}) {
    if (!searchQuery || searchQuery.trim().length < 2) {
      clearResults()
      return
    }
    
    // Generate unique ID for this search to handle race conditions
    const searchId = ++currentSearchId
    
    searchQuery = searchQuery.trim()
    
    // Clear ALL previous results immediately to prevent stale data
    results.value = []
    groupedResults.value = {}
    counts.value = emptyCounts()
    quickResults.value = []
    aiAnswer.value = null
    searchEngine.value = null
    
    query.value = searchQuery
    loading.value = true
    error.value = null
    
    try {
      const params = {
        q: searchQuery,
        limit: options.limit || 50,
      }
      
      // options.types takes priority over filters.types when both are present
      if (options.types && options.types.length > 0) {
        params.types = options.types.join(',')
      } else if (filters.value.types && filters.value.types.length > 0) {
        params.types = filters.value.types.join(',')
      }
      if (filters.value.clientId) {
        params.client_id = filters.value.clientId
      }
      if (filters.value.boardId) {
        params.board_id = filters.value.boardId
      }
      if (filters.value.dateFrom) {
        params.date_from = filters.value.dateFrom
      }
      if (filters.value.dateTo) {
        params.date_to = filters.value.dateTo
      }
      
      // Enable AI answer if requested
      if (options.ai) {
        params.ai = '1'
      }
      
      isDebugEnabled() && console.log('[Search] Starting full search:', searchQuery, 'searchId:', searchId)
      
      const response = await api.get('/search/universal', { params })
      
      // Check if this search is still the current one (prevent race conditions)
      if (searchId !== currentSearchId) {
        isDebugEnabled() && console.log('[Search] Ignoring stale response for searchId:', searchId, 'current:', currentSearchId)
        return
      }
      
      isDebugEnabled() && console.log('[Search] API response:', response.data)
      
      if (response.data.success) {
        const data = response.data.data
        isDebugEnabled() && console.log('[Search] Results count:', data.results?.length, 'Counts:', data.counts, 'Engine:', data.search_engine)
        
        results.value = data.results || []
        groupedResults.value = data.grouped || {}
        counts.value = data.counts || emptyCounts()
        aiAnswer.value = data.ai_answer || null
        searchEngine.value = data.search_engine || null
        
        isDebugEnabled() && console.log('[Search] Stored results.value length:', results.value.length)
      } else {
        error.value = response.data.message || 'Search failed'
        console.error('[Search] API error:', response.data.message)
      }
    } catch (e) {
      if (searchId === currentSearchId) {
        console.error('[Search] Error:', e)
        error.value = e.response?.data?.message || 'Search failed'
        searchEngine.value = null
      }
    } finally {
      // Only update loading if this is still the current search
      if (searchId === currentSearchId) {
        loading.value = false
      }
    }
  }
  
  /**
   * Quick search for autocomplete dropdown
   * Does NOT run if a full search is in progress (loading=true)
   */
  async function quickSearch(searchQuery) {
    if (loading.value) {
      isDebugEnabled() && console.log('[QuickSearch] Skipping - full search in progress')
      return
    }
    
    if (!searchQuery || searchQuery.trim().length < 2) {
      quickResults.value = []
      return
    }
    
    const qsId = ++currentQuickSearchId
    quickLoading.value = true
    
    try {
      const response = await api.get('/search/quick', {
        params: { q: searchQuery.trim(), limit: 8 }
      })
      
      if (qsId !== currentQuickSearchId || loading.value) {
        isDebugEnabled() && console.log('[QuickSearch] Discarding stale results, qsId:', qsId, 'current:', currentQuickSearchId)
        return
      }
      
      if (response.data.success) {
        quickResults.value = response.data.data.results || []
      }
    } catch (e) {
      if (qsId === currentQuickSearchId) {
        console.error('[QuickSearch] Error:', e)
        quickResults.value = []
      }
    } finally {
      if (qsId === currentQuickSearchId) {
        quickLoading.value = false
      }
    }
  }
  
  /**
   * Search with AI answer extraction
   */
  async function searchWithAI(searchQuery) {
    return search(searchQuery, { ai: true })
  }
  
  /**
   * Clear all results
   */
  function clearResults() {
    results.value = []
    groupedResults.value = {}
    counts.value = emptyCounts()
    aiAnswer.value = null
    searchEngine.value = null
    error.value = null
    quickResults.value = []
  }
  
  /**
   * Clear query and results
   */
  function clearSearch() {
    query.value = ''
    clearResults()
  }
  
  /**
   * Open search panel/modal
   */
  function openSearch() {
    isOpen.value = true
  }
  
  /**
   * Close search panel/modal
   */
  function closeSearch() {
    isOpen.value = false
  }
  
  /**
   * Toggle search panel
   */
  function toggleSearch() {
    isOpen.value = !isOpen.value
  }
  
  /**
   * Set active tab
   */
  function setTab(tab) {
    if (VALID_TABS.includes(tab)) {
      activeTab.value = tab
    }
  }
  
  /**
   * Set filters
   */
  function setFilters(newFilters) {
    filters.value = { ...filters.value, ...newFilters }
  }
  
  /**
   * Clear filters
   */
  function clearFilters() {
    filters.value = {
      types: null,
      clientId: null,
      boardId: null,
      dateFrom: null,
      dateTo: null,
    }
  }
  
  /**
   * Rebuild search index
   */
  async function rebuildIndex() {
    try {
      const response = await api.post('/search/index/rebuild')
      if (response.data.success) {
        indexStats.value = response.data.data.indexed
        return response.data.data
      }
    } catch (e) {
      console.error('Rebuild index error:', e)
      throw e
    }
  }
  
  /**
   * Get index statistics
   */
  async function fetchIndexStats() {
    try {
      const response = await api.get('/search/index/stats')
      if (response.data.success) {
        indexStats.value = response.data.data.stats
        return response.data.data
      }
    } catch (e) {
      console.error('Fetch index stats error:', e)
    }
  }
  
  /**
   * Index a single item (for real-time updates)
   * Call this after creating/updating: cards, todos, files, clients
   */
  async function indexItem(type, id, data = null) {
    try {
      await api.post('/search/index/item', { type, id, data })
    } catch (e) {
      // Silent fail - indexing errors shouldn't break the app
      console.warn('Index item failed:', e)
    }
  }
  
  /**
   * Remove item from index (call on delete)
   */
  async function removeFromIndex(type, id) {
    try {
      await api.delete('/search/index/item', { params: { type, id } })
    } catch (e) {
      console.warn('Remove from index failed:', e)
    }
  }
  
  /**
   * Index attachment content (uses active IMAP session)
   * Called on login and periodically every 10 minutes
   */
  async function indexAttachments(limit = 30) {
    if (attachmentIndexing.value.disabled) {
      return null
    }
    if (attachmentIndexing.value.running) {
      isDebugEnabled() && console.log('[AttachmentIndex] Already running, skipping')
      return null
    }
    
    attachmentIndexing.value.running = true
    
    try {
      isDebugEnabled() && console.log('[AttachmentIndex] Starting batch indexing...')
      const response = await api.post('/search/index/attachments', { limit })
      
      if (response.data.success) {
        const data = response.data.data
        attachmentIndexing.value.lastRun = new Date()
        attachmentIndexing.value.remaining = data.remaining || 0
        attachmentIndexing.value.processed = data.processed || 0
        attachmentIndexing.value.consecutiveErrors = 0
        
        if (data.skipped) {
          isDebugEnabled() && console.log('[AttachmentIndex] Skipped:', data.reason || 'unknown')
        } else {
          isDebugEnabled() && console.log('[AttachmentIndex] Completed:', data)
        }
        return data
      }
    } catch (e) {
      attachmentIndexing.value.consecutiveErrors += 1
      console.error('[AttachmentIndex] Error:', e)
      // Circuit breaker: stop polling after N consecutive failures so a
      // persistent backend issue cannot flood the network tab and server
      // logs every 10 minutes. Reloading the app re-enables it.
      if (attachmentIndexing.value.consecutiveErrors >= ATTACHMENT_INDEX_MAX_ERRORS) {
        attachmentIndexing.value.disabled = true
        stopAttachmentIndexing()
        console.warn(
          `[AttachmentIndex] disabled after ${ATTACHMENT_INDEX_MAX_ERRORS} consecutive failures; reload the app to retry`
        )
      }
    } finally {
      attachmentIndexing.value.running = false
    }
    return null
  }
  
  /**
   * Start automatic attachment indexing
   * Runs once immediately, then every 10 minutes
   */
  function startAttachmentIndexing() {
    stopAttachmentIndexing()
    
    isDebugEnabled() && console.log('[AttachmentIndex] Starting automatic indexing...')
    
    const initialDelay = isElectron() ? 90000 : 30000
    attachmentInitialTimeout = setTimeout(() => indexAttachments(), initialDelay)
    
    attachmentIndexInterval = setInterval(() => {
      isDebugEnabled() && console.log('[AttachmentIndex] Periodic indexing triggered')
      indexAttachments()
    }, 10 * 60 * 1000)
  }
  
  /**
   * Stop automatic attachment indexing
   * Call on logout
   */
  function stopAttachmentIndexing() {
    if (attachmentInitialTimeout) {
      clearTimeout(attachmentInitialTimeout)
      attachmentInitialTimeout = null
    }
    if (attachmentIndexInterval) {
      clearInterval(attachmentIndexInterval)
      attachmentIndexInterval = null
    }
    isDebugEnabled() && console.log('[AttachmentIndex] Stopped automatic indexing')
  }
  
  /**
   * Index email body content in batches
   * Fetches full email bodies via IMAP and updates search index
   * Called on login and periodically every 5 minutes
   */
  const BODY_CHAIN_DELAY = 120000 // 2 minutes between consecutive chains
  const BODY_MAX_CHAINS = 3       // Max consecutive chain batches per cycle

  let bodyChainCount = 0

  async function indexBodies(limit = 100) {
    if (bodyIndexing.value.running) {
      isDebugEnabled() && console.log('[BodyIndex] Already running, skipping')
      return null
    }
    
    bodyIndexing.value.running = true
    
    try {
      isDebugEnabled() && console.log('[BodyIndex] Starting batch body indexing...')
      const response = await api.post('/search/index/bodies', { limit })
      
      if (response.data.success) {
        const data = response.data.data
        bodyIndexing.value.lastRun = new Date()
        bodyIndexing.value.remaining = data.remaining || 0
        bodyIndexing.value.processed = data.processed || 0
        
        isDebugEnabled() && console.log('[BodyIndex] Completed:', data)
        
        if (data.remaining > 0 && bodyChainCount < BODY_MAX_CHAINS) {
          bodyChainCount++
          isDebugEnabled() && console.log(`[BodyIndex] ${data.remaining} remaining, chain ${bodyChainCount}/${BODY_MAX_CHAINS}, next batch in ${BODY_CHAIN_DELAY / 1000}s`)
          bodyBatchTimeout = setTimeout(() => indexBodies(limit), BODY_CHAIN_DELAY)
        } else {
          bodyChainCount = 0
        }
        
        return data
      }
    } catch (e) {
      console.error('[BodyIndex] Error:', e)
    } finally {
      bodyIndexing.value.running = false
    }
    return null
  }
  
  /**
   * Start automatic email body indexing
   * Runs once immediately (with 10s delay to let the session settle), then every 5 minutes
   */
  function startBodyIndexing() {
    stopBodyIndexing()
    
    isDebugEnabled() && console.log('[BodyIndex] Starting automatic body indexing...')
    
    const initialDelay = isElectron() ? 120000 : 10000
    bodyInitialTimeout = setTimeout(() => indexBodies(), initialDelay)
    
    bodyIndexInterval = setInterval(() => {
      isDebugEnabled() && console.log('[BodyIndex] Periodic body indexing triggered')
      bodyChainCount = 0
      indexBodies()
    }, 5 * 60 * 1000)
  }
  
  /**
   * Stop automatic email body indexing
   * Call on logout
   */
  function stopBodyIndexing() {
    if (bodyInitialTimeout) {
      clearTimeout(bodyInitialTimeout)
      bodyInitialTimeout = null
    }
    if (bodyBatchTimeout) {
      clearTimeout(bodyBatchTimeout)
      bodyBatchTimeout = null
    }
    if (bodyIndexInterval) {
      clearInterval(bodyIndexInterval)
      bodyIndexInterval = null
    }
    isDebugEnabled() && console.log('[BodyIndex] Stopped automatic body indexing')
  }
  
  /**
   * Get icon for result type
   */
  function getTypeIcon(type) {
    const icons = {
      email: 'mail',
      email_attachment: 'attachment',
      calendar_event: 'event',
      drive_file: 'description',
      drive_folder: 'folder',
      board: 'dashboard',
      card: 'view_kanban',
      todo: 'task_alt',
      client: 'business',
      contact: 'person',
      collab_doc: 'edit_document',
      chat_message: 'chat',
      mood_board_item: 'dashboard_customize',
    }
    return icons[type] || 'article'
  }
  
  /**
   * Get label for result type
   */
  function getTypeLabel(type) {
    const labels = {
      email: 'Email',
      email_attachment: 'Attachment',
      calendar_event: 'Event',
      drive_file: 'File',
      drive_folder: 'Folder',
      board: 'Board',
      card: 'Card',
      todo: 'Todo',
      client: 'Client',
      contact: 'Contact',
      collab_doc: 'Document',
      chat_message: 'Chat',
      mood_board_item: 'MoodBoard',
    }
    return labels[type] || type
  }
  
  /**
   * Format context breadcrumb
   */
  function formatContext(context) {
    if (!Array.isArray(context) || context.length === 0) return ''
    return context.map(c => c?.name || '').filter(Boolean).join(' > ')
  }

  return {
    // State
    query,
    results,
    groupedResults,
    counts,
    aiAnswer,
    loading,
    error,
    activeTab,
    isOpen,
    filters,
    quickResults,
    quickLoading,
    indexStats,
    searchEngine,
    attachmentIndexing,
    bodyIndexing,
    
    // Computed
    hasResults,
    hasAiAnswer,
    emailResults,
    attachmentResults,
    calendarResults,
    driveResults,
    boardResults,
    todoResults,
    clientResults,
    chatResults,
    moodBoardResults,
    filteredResults,
    tabCounts,
    
    // Actions
    search,
    quickSearch,
    searchWithAI,
    clearResults,
    clearSearch,
    openSearch,
    closeSearch,
    toggleSearch,
    setTab,
    setFilters,
    clearFilters,
    rebuildIndex,
    fetchIndexStats,
    indexItem,
    removeFromIndex,
    indexAttachments,
    startAttachmentIndexing,
    stopAttachmentIndexing,
    indexBodies,
    startBodyIndexing,
    stopBodyIndexing,
    getTypeIcon,
    getTypeLabel,
    formatContext,
  }
})

