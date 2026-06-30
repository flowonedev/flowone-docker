<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useSearchStore } from '@/addons/universal-search/stores/search'
import { useMailboxStore } from '@/stores/mailbox'
import { folderCollectionUrl } from '@/services/mailRouteService'
import { getToken } from '@/services/tokenStorage'
import { isDebugEnabled } from '@/utils/debug'
import { useAddons } from '@/composables/useAddons'

const router = useRouter()
const search = useSearchStore()
const mailbox = useMailboxStore()
const { moodboardsEnabled, kanbanBoardsEnabled, chatEnabled, calendarEnabled, tasksEnabled } = useAddons()

// Refs
const searchInput = ref(null)
const searchQuery = ref('')
const showAiToggle = ref(false)
const useAi = ref(false)
const isRebuilding = ref(false)
const showIndexStats = ref(false)
const showAiExamples = ref(false)
const showFiltersHelp = ref(false)
const expandedGroups = ref(new Set(['all'])) // Track which groups are expanded

// Filter bar state
const showFilterBar = ref(false)
const filters = ref({
  from: '',
  client: '',
  folder: '',
  extension: '',
  dateAfter: '',
  dateBefore: '',
})

// Active filters for display
const activeFilters = computed(() => {
  const active = []
  if (filters.value.from) active.push({ key: 'from', label: 'From', value: filters.value.from })
  if (filters.value.client) active.push({ key: 'client', label: 'Client', value: filters.value.client })
  if (filters.value.folder) active.push({ key: 'folder', label: 'Folder', value: filters.value.folder })
  if (filters.value.extension) active.push({ key: 'extension', label: 'Ext', value: filters.value.extension })
  if (filters.value.dateAfter) active.push({ key: 'dateAfter', label: 'After', value: filters.value.dateAfter })
  if (filters.value.dateBefore) active.push({ key: 'dateBefore', label: 'Before', value: filters.value.dateBefore })
  return active
})

const hasActiveFilters = computed(() => activeFilters.value.length > 0)

// Tooltip shown when hovering the engine badge in the footer
const searchEngineTooltip = computed(() => {
  switch (search.searchEngine) {
    case 'meilisearch':
      return 'Powered by Meilisearch (fast, typo-tolerant, multilingual)'
    case 'fulltext':
      return 'Using MySQL FULLTEXT index (Meilisearch unavailable or empty)'
    case 'like':
      return 'Using MySQL LIKE fallback (slowest — only filename/snippet matched)'
    default:
      return ''
  }
})

// Extension options
const extensionOptions = [
  { value: '', label: 'Any' },
  { value: 'pdf', label: 'PDF' },
  { value: 'docx', label: 'Word' },
  { value: 'xlsx', label: 'Excel' },
  { value: 'jpg', label: 'JPEG' },
  { value: 'png', label: 'PNG' },
  { value: 'zip', label: 'ZIP' },
]

// Apply filters to search query
function applyFilters() {
  // Build the filter syntax from current filters
  let queryParts = []
  
  // Get base search term (without existing filter operators)
  let baseQuery = searchQuery.value
    .replace(/\bfrom:[^\s]+/gi, '')
    .replace(/\bclient:[^\s]+/gi, '')
    .replace(/\b(?:in|folder):[^\s]+/gi, '')
    .replace(/\bext:[^\s]+/gi, '')
    .replace(/\bafter:[^\s]+/gi, '')
    .replace(/\bbefore:[^\s]+/gi, '')
    .trim()
  
  if (baseQuery) queryParts.push(baseQuery)
  if (filters.value.from) queryParts.push(`from:${filters.value.from.replace(/\s+/g, '_')}`)
  if (filters.value.client) queryParts.push(`client:${filters.value.client.replace(/\s+/g, '_')}`)
  if (filters.value.folder) queryParts.push(`in:${filters.value.folder.replace(/\s+/g, '_')}`)
  if (filters.value.extension) queryParts.push(`ext:${filters.value.extension}`)
  if (filters.value.dateAfter) queryParts.push(`after:${filters.value.dateAfter}`)
  if (filters.value.dateBefore) queryParts.push(`before:${filters.value.dateBefore}`)
  
  searchQuery.value = queryParts.join(' ')
  showFilterBar.value = false
  
  // Actually run the search with the new filters
  handleSearch()
}

// Clear single filter
function clearFilter(key) {
  filters.value[key] = ''
  applyFilters()
}

// Clear all filters
function clearAllFilters() {
  filters.value = {
    from: '',
    client: '',
    folder: '',
    extension: '',
    dateAfter: '',
    dateBefore: '',
  }
  applyFilters()
}

// Parse existing filters from search query to populate filter bar
function parseFiltersFromQuery() {
  const query = searchQuery.value
  
  const fromMatch = query.match(/\bfrom:([^\s]+)/i)
  if (fromMatch) filters.value.from = fromMatch[1].replace(/_/g, ' ')
  
  const clientMatch = query.match(/\bclient:([^\s]+)/i)
  if (clientMatch) filters.value.client = clientMatch[1].replace(/_/g, ' ')
  
  const folderMatch = query.match(/\b(?:in|folder):([^\s]+)/i)
  if (folderMatch) filters.value.folder = folderMatch[1].replace(/_/g, ' ')
  
  const extMatch = query.match(/\bext:([^\s]+)/i)
  if (extMatch) filters.value.extension = extMatch[1]
  
  const afterMatch = query.match(/\bafter:([^\s]+)/i)
  if (afterMatch) filters.value.dateAfter = afterMatch[1]
  
  const beforeMatch = query.match(/\bbefore:([^\s]+)/i)
  if (beforeMatch) filters.value.dateBefore = beforeMatch[1]
}

// Toggle filter bar
function toggleFilterBar() {
  showFilterBar.value = !showFilterBar.value
  if (showFilterBar.value) {
    parseFiltersFromQuery()
  }
}

// Filter examples for help popup
const filterExamples = {
  person: {
    label: 'By Person',
    filters: [
      { syntax: 'from:miklos', desc: 'From sender/creator named miklos' },
      { syntax: 'from:john', desc: 'From anyone named john' },
    ]
  },
  location: {
    label: 'By Location',
    filters: [
      { syntax: 'in:Projects', desc: 'In folder "Projects"' },
      { syntax: 'folder:Inbox', desc: 'In email folder "Inbox"' },
      { syntax: 'client:Acme', desc: 'Related to client "Acme"' },
    ]
  },
  type: {
    label: 'By Type',
    filters: [
      { syntax: 'type:email', desc: 'Only emails' },
      { syntax: 'type:file', desc: 'Only drive files' },
      { syntax: 'type:attachment', desc: 'Only email attachments' },
      { syntax: 'type:card', desc: 'Only board cards' },
      ...(tasksEnabled.value ? [{ syntax: 'type:todo', desc: 'Only todos' }] : []),
      { syntax: 'type:event', desc: 'Only calendar events' },
    ]
  },
  file: {
    label: 'By File Type',
    filters: [
      { syntax: 'ext:pdf', desc: 'Only PDF files' },
      { syntax: 'ext:docx', desc: 'Only Word documents' },
      { syntax: 'ext:xlsx', desc: 'Only Excel spreadsheets' },
      { syntax: 'ext:jpg', desc: 'Only JPEG images' },
    ]
  },
  date: {
    label: 'By Date',
    filters: [
      { syntax: 'after:2025-01', desc: 'After January 2025' },
      { syntax: 'before:2025-06', desc: 'Before June 2025' },
    ]
  }
}

const filterCombos = [
  { syntax: 'report from:john type:file', desc: 'Reports from John as files' },
  { syntax: 'invoice client:Acme ext:pdf', desc: 'Invoices for Acme as PDFs' },
  { syntax: 'meeting after:2025-01 type:email', desc: 'Meeting emails since Jan 2025' },
]

// AI Search Example Queries (Hungarian)
const aiExamples = [
  { icon: 'search', text: 'mikor van a megbeszélés [ügyféllel]', category: 'Keresés' },
  { icon: 'summarize', text: 'foglald össze [személy] emailjeit', category: 'Összegzés' },
  { icon: 'event', text: 'mi a határidő a [projekt] projektnél', category: 'Dátumok' },
  { icon: 'contact_page', text: 'mi [személy] telefonszáma', category: 'Kontakt' },
  { icon: 'description', text: 'mi a jelszó a [dokumentumban]', category: 'Kivonás' },
  { icon: 'question_answer', text: 'mit mondott [személy] a [témáról]', category: 'Keresés' },
  { icon: 'attach_money', text: 'mennyit ajánlottunk [ügyfélnek]', category: 'Kivonás' },
  { icon: 'mail', text: 'mi volt az utolsó email [személytől]', category: 'Keresés' },
  { icon: 'link', text: 'mi a zoom link a [meeting] meetinghez', category: 'Keresés' },
]

// Use example query
const useExampleQuery = (example) => {
  searchQuery.value = example.text
  showAiExamples.value = false
  useAi.value = true
  searchInput.value?.focus()
}

// Use filter example - append to current query or set if empty
const useFilterExample = (filter) => {
  const current = searchQuery.value.trim()
  if (current) {
    // Check if filter is already present
    const filterKey = filter.syntax.split(':')[0] + ':'
    if (!current.includes(filterKey)) {
      searchQuery.value = current + ' ' + filter.syntax
    } else {
      // Replace existing filter of same type
      const regex = new RegExp(`\\b${filterKey}[^\\s]+`, 'g')
      searchQuery.value = current.replace(regex, filter.syntax).trim()
    }
  } else {
    searchQuery.value = filter.syntax
  }
  showFiltersHelp.value = false
  searchInput.value?.focus()
}

// Debounce timer
let debounceTimer = null

// Watch for query changes and trigger quick search
watch(searchQuery, (newQuery) => {
  clearTimeout(debounceTimer)
  
  if (newQuery.length < 2) {
    search.clearResults()
    return
  }
  
  debounceTimer = setTimeout(() => {
    search.quickSearch(newQuery)
  }, 200)
})

// Watch for search modal open to focus input
watch(() => search.isOpen, (isOpen) => {
  if (isOpen) {
    nextTick(() => {
      searchInput.value?.focus()
    })
  }
})

// Handle full search (on Enter)
function handleSearch() {
  if (searchQuery.value.length < 2) return
  
  // Cancel any pending quickSearch to prevent race conditions
  clearTimeout(debounceTimer)
  debounceTimer = null
  
  isDebugEnabled() && console.log('[SuperSearch] Full search triggered for:', searchQuery.value)
  
  if (useAi.value) {
    search.searchWithAI(searchQuery.value)
  } else {
    search.search(searchQuery.value)
  }
}

// Navigate to result
function navigateToResult(result) {
  search.closeSearch()
  
  // Use the link from the result
  if (result.link) {
    router.push(result.link)
  }
}

// Quick navigation from autocomplete
function quickNavigate(result) {
  search.closeSearch()
  if (result.link) {
    router.push(result.link)
  }
}

// Close modal
function closeModal() {
  search.closeSearch()
  searchQuery.value = ''
  search.clearResults()
}

// Rebuild search index
async function handleRebuildIndex() {
  if (isRebuilding.value) return
  
  isRebuilding.value = true
  try {
    const result = await search.rebuildIndex()
    // Show a brief notification or just let the UI update
    if (result?.indexed) {
      const total = Object.values(result.indexed).reduce((a, b) => a + b, 0)
      isDebugEnabled() && console.log(`Index rebuilt: ${total} items indexed`)
    }
  } catch (e) {
    console.error('Failed to rebuild index:', e)
  } finally {
    isRebuilding.value = false
  }
}

// Load index stats on hover
async function loadIndexStats() {
  if (!search.indexStats) {
    await search.fetchIndexStats()
  }
  showIndexStats.value = true
}

// Toggle group expansion
function toggleGroup(groupKey) {
  if (expandedGroups.value.has(groupKey)) {
    expandedGroups.value.delete(groupKey)
  } else {
    expandedGroups.value.add(groupKey)
  }
}

// Check if group is expanded
function isGroupExpanded(groupKey) {
  return expandedGroups.value.has(groupKey)
}

// Group results by folder or client
const groupedFileResults = computed(() => {
  const files = search.driveResults
  if (!files || files.length === 0) return []
  
  // Group by folder
  const groups = {}
  files.forEach(file => {
    const folderName = file.context?.find(c => c.type === 'folder')?.name || 
                       file.folder_name || 
                       'Root'
    if (!groups[folderName]) {
      groups[folderName] = []
    }
    groups[folderName].push(file)
  })
  
  // Convert to array and sort by count
  return Object.entries(groups)
    .map(([name, items]) => ({ name, items, count: items.length }))
    .sort((a, b) => b.count - a.count)
})

// Determine if we should show grouped view (more than 10 results in drive tab)
const shouldGroupDriveResults = computed(() => {
  return search.activeTab === 'drive' && search.driveResults.length > 10
})

// Handle keyboard shortcut
function handleKeydown(e) {
  // Cmd/Ctrl + K to open search
  if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
    e.preventDefault()
    search.toggleSearch()
  }
  
  // Escape to close
  if (e.key === 'Escape' && search.isOpen) {
    closeModal()
  }
}

// Register keyboard listener
onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})

// Get icon for result type
function getIcon(result) {
  if (result.icon) return result.icon
  return search.getTypeIcon(result.source_type)
}

// Thumbnail cache
const thumbnailCache = ref({})

// Check if attachment is an image
function isImageAttachment(result) {
  if (result.source_type !== 'email_attachment') return false
  const title = (result.title || '').toLowerCase()
  const mimeType = (result.mime_type || '').toLowerCase()
  // Check by mime type or extension
  return mimeType.startsWith('image/') || 
         /\.(jpg|jpeg|png|gif|webp|bmp)$/.test(title)
}

// Get thumbnail URL for attachment (from cache or fetch)
function getAttachmentThumbnailUrl(result) {
  const cacheKey = `${result.source_type}-${result.source_id}`
  
  // Return cached URL if available
  if (thumbnailCache.value[cacheKey]) {
    return thumbnailCache.value[cacheKey]
  }
  
  // Start loading in background
  if (!thumbnailCache.value[cacheKey + '_loading']) {
    thumbnailCache.value[cacheKey + '_loading'] = true
    loadAttachmentThumbnail(result, cacheKey)
  }
  
  // Return placeholder while loading
  return ''
}

// Load thumbnail via fetch with auth header
async function loadAttachmentThumbnail(result, cacheKey) {
  try {
    // Only attempt if we have valid folder, uid, AND part
    // Part must be explicitly set in extra_data (not just defaulted)
    // This prevents 404 errors for attachments indexed before part field was added
    if (!result.extra?.folder || !result.extra?.uid || !result.extra?.part) return
    
    const folder = result.extra.folder
    const uid = result.extra.uid
    const part = result.extra.part
    
    const token = getToken('webmail_token')
    const baseUrl = import.meta.env.VITE_API_URL || ''
    const url = `${baseUrl}/api${folderCollectionUrl(mailbox.folders, folder, `messages/${uid}/attachments/${part}/thumbnail`)}`
    
    const response = await fetch(url, {
      headers: { 'Authorization': `Bearer ${token}` }
    })
    
    if (response.ok) {
      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)
      thumbnailCache.value[cacheKey] = blobUrl
    }
  } catch (e) {
    // Silently fail - will show icon instead
  }
}

// Handle thumbnail load error - show fallback icon
function handleThumbnailError(event) {
  const img = event.target
  const parent = img.parentElement
  if (parent) {
    // Replace with icon
    parent.innerHTML = '<span class="material-symbols-rounded text-xl text-surface-400">image</span>'
    parent.classList.add('flex', 'items-center', 'justify-center')
  }
}

// Get color class for result type
function getTypeColor(type) {
  const colors = {
    'email': 'from-blue-500 to-blue-600',
    'email_attachment': 'from-sky-500 to-cyan-500',
    'calendar_event': 'from-red-500 to-rose-500',
    'drive_file': 'from-amber-500 to-orange-500',
    'drive_folder': 'from-amber-400 to-amber-500',
    'board': 'from-purple-500 to-purple-600',
    'card': 'from-indigo-500 to-purple-500',
    ...(tasksEnabled.value && { 'todo': 'from-emerald-500 to-teal-500' }),
    'client': 'from-rose-500 to-pink-500',
    'contact': 'from-cyan-500 to-blue-500',
    'collab_doc': 'from-violet-500 to-purple-500',
    'chat_message': 'from-teal-500 to-emerald-500',
    'mood_board_item': 'from-fuchsia-500 to-pink-500',
  }
  return colors[type] || 'from-gray-500 to-gray-600'
}

// Get badge color for result type
function getBadgeColor(type) {
  const colors = {
    'email': 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
    'email_attachment': 'bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300',
    'calendar_event': 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
    'drive_file': 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
    'drive_folder': 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
    'board': 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300',
    'card': 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300',
    ...(tasksEnabled.value && { 'todo': 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' }),
    'client': 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300',
    'contact': 'bg-cyan-100 dark:bg-cyan-900/40 text-cyan-700 dark:text-cyan-300',
    'collab_doc': 'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300',
    'chat_message': 'bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300',
    'mood_board_item': 'bg-fuchsia-100 dark:bg-fuchsia-900/40 text-fuchsia-700 dark:text-fuchsia-300',
  }
  return colors[type] || 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400'
}

// Escape HTML for safe v-html fallback (when no highlighting available)
function escapeHtml(text) {
  if (!text) return ''
  const div = document.createElement('div')
  div.textContent = text
  return div.innerHTML
}

// Format date
function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  
  if (diff < 60000) return 'Just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  if (diff < 604800000) return `${Math.floor(diff / 86400000)}d ago`
  
  return date.toLocaleDateString()
}

// Tabs for filtering (moodboards gated by addon)
const tabs = computed(() => {
  const items = [
    { id: 'all', label: 'All', icon: 'search' },
    { id: 'email', label: 'Emails', icon: 'mail' },
    { id: 'attachments', label: 'Attachments', icon: 'attachment' },
  ]
  if (calendarEnabled.value) {
    items.push({ id: 'calendar', label: 'Calendar', icon: 'event' })
  }
  items.push({ id: 'drive', label: 'Drive', icon: 'folder' })
  if (kanbanBoardsEnabled.value) {
    items.push({ id: 'boards', label: 'Boards', icon: 'view_kanban' })
  }
  if (tasksEnabled.value) {
    items.push({ id: 'todos', label: 'Todos', icon: 'task_alt' })
  }
  items.push({ id: 'clients', label: 'Clients', icon: 'business' })
  if (chatEnabled.value) {
    items.push({ id: 'chats', label: 'Chats', icon: 'chat' })
  }
  if (moodboardsEnabled.value) {
    items.push({ id: 'moodboards', label: 'MoodBoards', icon: 'dashboard_customize' })
  }
  return items
})
</script>

<template>
  <!-- Search Modal Backdrop -->
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="search.isOpen"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100]"
        @click="closeModal"
      />
    </Transition>
    
    <!-- Search Modal -->
    <Transition name="slide-down">
      <div
        v-if="search.isOpen"
        class="fixed inset-x-4 top-[5vh] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-[1100px] z-[101] max-h-[90vh] flex flex-col"
      >
        <div class="bg-white dark:bg-surface-900 rounded-2xl shadow-2xl shadow-black/20 dark:shadow-black/50 border border-surface-200/50 dark:border-surface-700/50 overflow-hidden flex flex-col max-h-full ring-1 ring-black/5 dark:ring-white/5">
          
          <!-- Search Input -->
          <div class="p-5 border-b border-surface-200 dark:border-surface-700/50 bg-gradient-to-r from-surface-50 to-white dark:from-surface-900/50 dark:to-surface-900/30">
            <div class="relative flex items-center gap-4">
              <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center shadow-lg shadow-primary-500/20">
                <span class="material-symbols-rounded text-xl text-white">search</span>
              </div>
              <input
                ref="searchInput"
                v-model="searchQuery"
                type="text"
                class="flex-1 bg-transparent text-lg font-medium text-surface-800 dark:text-surface-100 placeholder-surface-400 outline-none"
                placeholder="Search emails, documents, boards, todos..."
                @keydown.enter="handleSearch"
              />
              <div class="flex items-center gap-2">
                <!-- AI Toggle with Info -->
                <div class="relative flex items-center gap-1">
                  <button
                    @click="useAi = !useAi"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition-all duration-200 border"
                    :class="useAi 
                      ? 'bg-gradient-to-r from-emerald-500 to-teal-500 text-white border-transparent shadow-lg shadow-emerald-500/25' 
                      : 'bg-surface-100 dark:bg-surface-800 text-surface-500 border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-700 hover:text-primary-600 dark:hover:text-primary-400'"
                    title="Enable AI-powered answers"
                  >
                    <span class="material-symbols-rounded text-sm">auto_awesome</span>
                    AI
                  </button>
                  
                  <!-- AI Examples Info Button -->
                  <button
                    @click="showAiExamples = !showAiExamples"
                    @mouseenter="showAiExamples = true"
                    class="w-6 h-6 flex items-center justify-center rounded-full text-surface-400 hover:text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition-colors"
                    title="AI search examples"
                  >
                    <span class="material-symbols-rounded text-base">help</span>
                  </button>
                  
                  <!-- AI Examples Popup -->
                  <Transition name="fade">
                    <div
                      v-if="showAiExamples"
                      @mouseleave="showAiExamples = false"
                      class="absolute top-full right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] p-3 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 z-[200]"
                    >
                      <div class="flex items-center gap-2 mb-3 pb-2 border-b border-surface-200 dark:border-surface-700">
                        <span class="material-symbols-rounded text-primary-500 text-lg">auto_awesome</span>
                        <span class="text-sm font-semibold text-surface-800 dark:text-surface-200">AI Keresés Példák</span>
                      </div>
                      <p class="text-xs text-surface-500 dark:text-surface-400 mb-3">
                        Tegyél fel kérdéseket és az AI megtalálja a választ az emailjeidben, fájljaidban:
                      </p>
                      <div class="space-y-1 max-h-64 overflow-y-auto pr-1">
                        <button
                          v-for="(example, idx) in aiExamples"
                          :key="idx"
                          @click="useExampleQuery(example)"
                          class="w-full flex items-center gap-2 px-2.5 py-2 rounded-lg text-left text-xs hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors group"
                        >
                          <span class="material-symbols-rounded text-surface-400 group-hover:text-primary-500 text-sm">{{ example.icon }}</span>
                          <span class="flex-1 text-surface-600 dark:text-surface-300 group-hover:text-surface-800 dark:group-hover:text-surface-100">{{ example.text }}</span>
                          <span class="text-[10px] px-1.5 py-0.5 rounded bg-surface-100 dark:bg-surface-700 text-surface-500">{{ example.category }}</span>
                        </button>
                      </div>
                      <p class="mt-3 pt-2 border-t border-surface-200 dark:border-surface-700 text-[10px] text-surface-400 dark:text-surface-500">
                        Cseréld a [zárójeleket] valódi nevekre/témákra
                      </p>
                    </div>
                  </Transition>
                </div>
                
                <!-- Filters Toggle Button -->
                <div class="relative">
                  <button
                    @click="toggleFilterBar"
                    class="w-7 h-7 flex items-center justify-center rounded-lg transition-colors border"
                    :class="showFilterBar || hasActiveFilters
                      ? 'text-amber-600 bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-700'
                      : 'text-surface-400 hover:text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/30 border-transparent hover:border-amber-200 dark:hover:border-amber-700'"
                    title="Search filters"
                  >
                    <span class="material-symbols-rounded text-lg">tune</span>
                    <span 
                      v-if="hasActiveFilters && !showFilterBar" 
                      class="absolute -top-1 -right-1 w-4 h-4 bg-amber-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"
                    >
                      {{ activeFilters.length }}
                    </span>
                  </button>
                  
                </div>
                
                <!-- Keyboard shortcut hint -->
                <kbd class="hidden sm:inline-flex items-center gap-1 px-2.5 py-1.5 bg-surface-100 dark:bg-surface-800 rounded-lg text-xs text-surface-500 font-mono border border-surface-200 dark:border-surface-700">
                  ESC
                </kbd>
              </div>
            </div>
            
            <!-- Active Filters Pills -->
            <div v-if="hasActiveFilters" class="flex items-center gap-2 mt-3 flex-wrap">
              <span class="text-xs text-surface-400">Filters:</span>
              <button
                v-for="filter in activeFilters"
                :key="filter.key"
                @click="clearFilter(filter.key)"
                class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300 hover:bg-primary-200 dark:hover:bg-primary-800/50 transition-colors group"
              >
                <span class="text-primary-500">{{ filter.label }}:</span>
                <span>{{ filter.value }}</span>
                <span class="material-symbols-rounded text-sm opacity-60 group-hover:opacity-100">close</span>
              </button>
              <button
                @click="clearAllFilters"
                class="text-xs text-surface-400 hover:text-red-500 transition-colors"
              >
                Clear all
              </button>
            </div>
          </div>
          
          <!-- Filter Bar (Expandable) -->
          <Transition name="expand">
            <form
              v-if="showFilterBar"
              @submit.prevent="applyFilters"
              class="px-5 py-4 border-b border-surface-200 dark:border-surface-700/50 bg-surface-50/50 dark:bg-surface-800/30"
            >
              <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <!-- From -->
                <div>
                  <label class="block text-[10px] font-semibold text-surface-500 uppercase tracking-wider mb-1">From</label>
                  <input
                    v-model="filters.from"
                    type="text"
                    placeholder="Sender name..."
                    class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none transition-all"
                  />
                </div>
                
                <!-- Client -->
                <div>
                  <label class="block text-[10px] font-semibold text-surface-500 uppercase tracking-wider mb-1">Client</label>
                  <input
                    v-model="filters.client"
                    type="text"
                    placeholder="Client name..."
                    class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none transition-all"
                  />
                </div>
                
                <!-- Folder -->
                <div>
                  <label class="block text-[10px] font-semibold text-surface-500 uppercase tracking-wider mb-1">Folder</label>
                  <input
                    v-model="filters.folder"
                    type="text"
                    placeholder="Folder name..."
                    class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none transition-all"
                  />
                </div>
                
                <!-- Extension -->
                <div>
                  <label class="block text-[10px] font-semibold text-surface-500 uppercase tracking-wider mb-1">File Type</label>
                  <select
                    v-model="filters.extension"
                    class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none transition-all"
                  >
                    <option v-for="opt in extensionOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                  </select>
                </div>
                
                <!-- Date After -->
                <div>
                  <label class="block text-[10px] font-semibold text-surface-500 uppercase tracking-wider mb-1">After Date</label>
                  <input
                    v-model="filters.dateAfter"
                    type="date"
                    class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none transition-all"
                  />
                </div>
                
                <!-- Date Before -->
                <div>
                  <label class="block text-[10px] font-semibold text-surface-500 uppercase tracking-wider mb-1">Before Date</label>
                  <input
                    v-model="filters.dateBefore"
                    type="date"
                    class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none transition-all"
                  />
                </div>
              </div>
              
              <!-- Apply/Clear buttons -->
              <div class="flex items-center justify-end gap-2 mt-3">
                <button
                  type="button"
                  @click="clearAllFilters"
                  class="px-3 py-1.5 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
                >
                  Clear
                </button>
                <button
                  type="submit"
                  class="px-4 py-1.5 text-sm font-medium bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors flex items-center gap-1.5"
                >
                  <span class="material-symbols-rounded text-base">search</span>
                  Apply Filters
                </button>
              </div>
            </form>
          </Transition>
          
          <!-- Results Area -->
          <div class="flex-1 overflow-y-auto">
            
            <!-- AI Answer -->
            <div
              v-if="search.aiAnswer"
              class="m-4 p-4 bg-gradient-to-r from-primary-50 via-primary-50/80 to-transparent dark:from-primary-900/30 dark:via-primary-900/20 dark:to-transparent rounded-xl border border-primary-200/50 dark:border-primary-700/30"
            >
              <div class="flex items-start gap-4">
                <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl flex items-center justify-center shadow-lg shadow-primary-500/20">
                  <span class="material-symbols-rounded text-white">auto_awesome</span>
                </div>
                <div class="flex-1">
                  <p class="text-xs font-semibold text-primary-600 dark:text-primary-400 uppercase tracking-wide mb-1.5">AI Answer</p>
                  <p class="text-sm text-primary-900 dark:text-primary-100 leading-relaxed">{{ search.aiAnswer.answer }}</p>
                  <button
                    v-if="search.aiAnswer.source"
                    @click="navigateToResult(search.aiAnswer.source)"
                    class="mt-3 text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 flex items-center gap-1.5 font-medium group"
                  >
                    <span class="material-symbols-rounded text-sm">{{ getIcon(search.aiAnswer.source) }}</span>
                    {{ search.aiAnswer.source.title }}
                    <span class="material-symbols-rounded text-sm group-hover:translate-x-0.5 transition-transform">arrow_forward</span>
                  </button>
                </div>
              </div>
            </div>
            
            <!-- Tabs -->
            <div
              v-if="search.hasResults"
              class="flex items-center gap-1 px-4 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))] overflow-x-auto"
            >
              <button
                v-for="tab in tabs"
                :key="tab.id"
                @click="search.setTab(tab.id)"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium whitespace-nowrap transition-colors"
                :class="search.activeTab === tab.id 
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' 
                  : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-800'"
              >
                <span class="material-symbols-rounded text-base">{{ tab.icon }}</span>
                {{ tab.label }}
                <span
                  v-if="search.tabCounts[tab.id] > 0"
                  class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-surface-200 dark:bg-surface-700"
                >
                  {{ search.tabCounts[tab.id] }}
                </span>
              </button>
              
              <!-- Search Engine Badge -->
              <div class="ml-auto flex items-center">
                <span
                  v-if="search.searchEngine"
                  class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wide"
                  :class="search.searchEngine === 'meilisearch' 
                    ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400' 
                    : 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400'"
                  :title="search.searchEngine === 'meilisearch' ? 'Powered by Meilisearch' : 'Using MySQL fallback'"
                >
                  <span class="material-symbols-rounded text-xs">{{ search.searchEngine === 'meilisearch' ? 'bolt' : 'database' }}</span>
                  {{ search.searchEngine === 'meilisearch' ? 'Meili' : search.searchEngine === 'fulltext' ? 'MySQL' : 'SQL' }}
                </span>
              </div>
            </div>
            
            <!-- Loading -->
            <div v-if="search.loading" class="p-10 text-center">
              <div class="inline-flex flex-col items-center gap-3">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-primary-100 to-primary-50 dark:from-primary-900/30 dark:to-primary-800/20 flex items-center justify-center">
                  <svg class="animate-spin h-6 w-6 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                  </svg>
                </div>
                <span class="text-sm font-medium text-surface-500">Searching...</span>
              </div>
            </div>
            
            <!-- Quick Results (Autocomplete) -->
            <div v-else-if="!search.hasResults && search.quickResults.length > 0" class="py-3">
              <p class="px-4 py-2 text-[11px] font-semibold text-surface-400 uppercase tracking-widest">Quick Results</p>
              <button
                v-for="result in search.quickResults"
                :key="`${result.type}-${result.id}`"
                @click="quickNavigate(result)"
                class="group w-full px-4 py-3 flex items-center gap-4 hover:bg-gradient-to-r hover:from-surface-100/80 hover:to-transparent dark:hover:from-surface-800/60 dark:hover:to-transparent transition-all duration-200 text-left border-l-2 border-transparent hover:border-primary-500"
              >
                <!-- Gradient Icon Background -->
                <div :class="['w-10 h-10 rounded-xl bg-gradient-to-br flex items-center justify-center shadow-sm group-hover:shadow-md group-hover:scale-105 transition-all duration-200', getTypeColor(result.type)]">
                  <span class="material-symbols-rounded text-lg text-white">
                    {{ search.getTypeIcon(result.type) }}
                  </span>
                </div>
                <div class="flex-1 min-w-0">
                  <p
                    class="text-sm font-semibold text-surface-800 dark:text-surface-100 truncate group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors search-highlight"
                    v-html="result.highlighted_title || escapeHtml(result.title)"
                  ></p>
                  <p v-if="result.subtitle" class="text-xs text-surface-500 dark:text-surface-400 truncate mt-0.5">
                    {{ result.subtitle }}
                  </p>
                </div>
                <span :class="['text-[11px] font-medium px-2.5 py-1 rounded-full', getBadgeColor(result.type)]">
                  {{ search.getTypeLabel(result.type) }}
                </span>
                <span class="material-symbols-rounded text-surface-300 dark:text-surface-600 group-hover:text-primary-500 transition-colors">
                  arrow_forward
                </span>
              </button>
              
              <div class="mx-4 mt-3 pt-3 border-t border-surface-200 dark:border-surface-700/50">
                <p class="text-xs text-surface-400 flex items-center gap-2">
                  Press <kbd class="px-2 py-1 bg-surface-100 dark:bg-surface-800 rounded-md font-mono text-surface-600 dark:text-surface-300 shadow-sm">Enter</kbd> for full search
                </p>
              </div>
            </div>
            
            <!-- Full Results -->
            <div v-else-if="search.hasResults" class="py-3 space-y-1">
              
              <!-- Grouped View for Drive files (when > 10 results) -->
              <template v-if="shouldGroupDriveResults">
                <div v-for="group in groupedFileResults" :key="group.name" class="mx-3 mb-2">
                  <!-- Group Header (collapsible) -->
                  <button
                    @click="toggleGroup(group.name)"
                    class="w-full flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-100 dark:bg-surface-800/50 hover:bg-surface-200 dark:hover:bg-surface-800 transition-colors"
                  >
                    <span class="material-symbols-rounded text-lg text-amber-500 transition-transform" :class="{ 'rotate-90': isGroupExpanded(group.name) }">
                      chevron_right
                    </span>
                    <span class="material-symbols-rounded text-amber-500">folder</span>
                    <span class="font-medium text-sm text-surface-700 dark:text-surface-200">{{ group.name }}</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-xs font-medium text-surface-600 dark:text-surface-400">
                      {{ group.count }}
                    </span>
                  </button>
                  
                  <!-- Group Items (collapsible) -->
                  <Transition name="expand">
                    <div v-if="isGroupExpanded(group.name)" class="mt-1 ml-4 space-y-1">
                      <div
                        v-for="result in group.items"
                        :key="`${result.source_type}-${result.source_id}`"
                        @click="navigateToResult(result)"
                        class="group p-2 flex items-center gap-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800/50 cursor-pointer transition-all duration-150"
                      >
                        <div :class="['w-8 h-8 rounded-lg bg-gradient-to-br flex items-center justify-center shrink-0', getTypeColor(result.source_type)]">
                          <span class="material-symbols-rounded text-sm text-white">
                            {{ getIcon(result) }}
                          </span>
                        </div>
                        <div class="flex-1 min-w-0">
                          <p
                            class="text-sm text-surface-700 dark:text-surface-200 truncate group-hover:text-primary-600 dark:group-hover:text-primary-400 search-highlight"
                            v-html="result.highlighted_title || escapeHtml(result.title)"
                          ></p>
                          <p v-if="result.date" class="text-[10px] text-surface-400">{{ formatDate(result.date) }}</p>
                        </div>
                        <span class="material-symbols-rounded text-surface-300 dark:text-surface-600 group-hover:text-primary-500 text-sm">
                          arrow_forward
                        </span>
                      </div>
                    </div>
                  </Transition>
                </div>
              </template>
              
              <!-- Regular flat list view -->
              <template v-else>
                <div
                  v-for="result in search.filteredResults"
                  :key="`${result.source_type}-${result.source_id}`"
                  @click="navigateToResult(result)"
                  class="group mx-3 p-3 flex items-start gap-4 rounded-xl hover:bg-gradient-to-r hover:from-surface-100 hover:to-surface-50 dark:hover:from-surface-800/70 dark:hover:to-surface-800/30 cursor-pointer transition-all duration-200 border border-transparent hover:border-surface-200 dark:hover:border-surface-700 hover:shadow-sm"
                >
                  <!-- Thumbnail for image attachments -->
                  <div 
                    v-if="result.source_type === 'email_attachment' && isImageAttachment(result)"
                    class="w-11 h-11 rounded-xl overflow-hidden shadow-sm group-hover:shadow-md group-hover:scale-105 transition-all duration-200 shrink-0 bg-surface-200 dark:bg-surface-700 flex items-center justify-center"
                  >
                    <img 
                      v-if="getAttachmentThumbnailUrl(result)"
                      :src="getAttachmentThumbnailUrl(result)"
                      :alt="result.title"
                      class="w-full h-full object-cover"
                      loading="lazy"
                      @error="handleThumbnailError($event)"
                    />
                    <span v-else class="material-symbols-rounded text-lg text-surface-400 animate-pulse">image</span>
                  </div>
                  <!-- Gradient Icon (default) -->
                  <div 
                    v-else
                    :class="['w-11 h-11 rounded-xl bg-gradient-to-br flex items-center justify-center shadow-sm group-hover:shadow-md group-hover:scale-105 transition-all duration-200 shrink-0', getTypeColor(result.source_type)]"
                  >
                    <span class="material-symbols-rounded text-xl text-white">
                      {{ getIcon(result) }}
                    </span>
                  </div>
                  
                  <!-- Content -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <p
                        class="text-sm font-semibold text-surface-800 dark:text-surface-100 truncate group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors search-highlight"
                        v-html="result.highlighted_title || escapeHtml(result.title)"
                      ></p>
                      <span :class="['text-[10px] font-semibold px-2 py-0.5 rounded-full uppercase tracking-wide shrink-0', getBadgeColor(result.source_type)]">
                        {{ search.getTypeLabel(result.source_type) }}
                      </span>
                    </div>
                    
                    <!-- Context breadcrumb -->
                    <div v-if="result.context && result.context.length > 0" class="flex items-center gap-1 text-xs text-primary-600/80 dark:text-primary-400/80 mb-1.5">
                      <span
                        v-for="(ctx, idx) in result.context"
                        :key="ctx.id"
                        class="flex items-center gap-1"
                      >
                        <span class="material-symbols-rounded text-xs">
                          {{ ctx.type === 'client' ? 'business' : ctx.type === 'board' ? 'dashboard' : 'folder' }}
                        </span>
                        <span>{{ ctx.name }}</span>
                        <span v-if="idx < result.context.length - 1" class="material-symbols-rounded text-xs text-surface-400">chevron_right</span>
                      </span>
                    </div>
                    
                    <!-- Snippet -->
                    <p
                      v-if="result.snippet"
                      class="text-xs text-surface-500 dark:text-surface-400 line-clamp-2 leading-relaxed search-highlight"
                      v-html="result.highlighted_snippet || escapeHtml(result.snippet)"
                    ></p>
                    
                    <!-- Meta -->
                    <div class="flex items-center gap-3 mt-2 text-[11px] text-surface-400">
                      <span v-if="result.date" class="flex items-center gap-1">
                        <span class="material-symbols-rounded text-xs">schedule</span>
                        {{ formatDate(result.date) }}
                      </span>
                      <span v-if="result.extra?.from" class="flex items-center gap-1">
                        <span class="material-symbols-rounded text-xs">person</span>
                        {{ result.extra.from }}
                      </span>
                    </div>
                  </div>
                  
                  <!-- Arrow indicator -->
                  <span class="material-symbols-rounded text-surface-300 dark:text-surface-600 group-hover:text-primary-500 group-hover:translate-x-1 transition-all duration-200 self-center">
                    arrow_forward
                  </span>
                </div>
              </template>
            </div>
            
            <!-- Empty State -->
            <div v-else-if="searchQuery.length >= 2 && !search.loading" class="p-10 text-center">
              <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-surface-200 to-surface-100 dark:from-surface-700 dark:to-surface-800 flex items-center justify-center">
                <span class="material-symbols-rounded text-3xl text-surface-400 dark:text-surface-500">search_off</span>
              </div>
              <p class="text-surface-600 dark:text-surface-300 font-medium">No results found for "{{ searchQuery }}"</p>
              <p class="text-sm text-surface-400 mt-1">Try different keywords or check spelling</p>
            </div>
            
            <!-- Initial State -->
            <div v-else class="p-10 text-center">
              <div class="w-20 h-20 mx-auto mb-5 rounded-2xl bg-gradient-to-br from-primary-100 to-primary-50 dark:from-primary-900/30 dark:to-primary-800/20 flex items-center justify-center shadow-inner">
                <span class="material-symbols-rounded text-4xl text-primary-500 dark:text-primary-400">manage_search</span>
              </div>
              <p class="text-surface-600 dark:text-surface-300 font-medium mb-1">Universal Search</p>
              <p class="text-sm text-surface-400">Search across your entire workspace</p>
              <div class="flex flex-wrap justify-center gap-2 mt-5">
                <span class="text-xs px-3 py-1.5 bg-gradient-to-r from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-800/20 rounded-full text-blue-600 dark:text-blue-400 font-medium flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">mail</span>
                  Emails
                </span>
                <span class="text-xs px-3 py-1.5 bg-gradient-to-r from-amber-100 to-amber-50 dark:from-amber-900/30 dark:to-amber-800/20 rounded-full text-amber-600 dark:text-amber-400 font-medium flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">folder</span>
                  Drive
                </span>
                <span class="text-xs px-3 py-1.5 bg-gradient-to-r from-purple-100 to-purple-50 dark:from-purple-900/30 dark:to-purple-800/20 rounded-full text-purple-600 dark:text-purple-400 font-medium flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">view_kanban</span>
                  Boards
                </span>
                <span v-if="tasksEnabled" class="text-xs px-3 py-1.5 bg-gradient-to-r from-emerald-100 to-emerald-50 dark:from-emerald-900/30 dark:to-emerald-800/20 rounded-full text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">task_alt</span>
                  Todos
                </span>
                <span class="text-xs px-3 py-1.5 bg-gradient-to-r from-rose-100 to-rose-50 dark:from-rose-900/30 dark:to-rose-800/20 rounded-full text-rose-600 dark:text-rose-400 font-medium flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">business</span>
                  Clients
                </span>
              </div>
            </div>
          </div>
          
          <!-- Footer -->
          <div class="px-4 py-2 border-t border-surface-200 dark:border-surface-700/50 bg-surface-50 dark:bg-surface-900/50 flex items-center justify-between text-xs text-surface-400">
            <div class="flex items-center gap-3">
              <span class="flex items-center gap-1">
                <kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded font-mono">Enter</kbd>
                to search
              </span>
              <span class="flex items-center gap-1">
                <kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded font-mono">Esc</kbd>
                to close
              </span>
            </div>
            <div class="flex items-center gap-3">
              <!-- Search Engine Indicator (always visible once a search has run) -->
              <span
                v-if="search.searchEngine"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide"
                :class="search.searchEngine === 'meilisearch'
                  ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400'
                  : 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400'"
                :title="searchEngineTooltip"
              >
                <span class="material-symbols-rounded text-[12px]">{{ search.searchEngine === 'meilisearch' ? 'bolt' : 'database' }}</span>
                {{ search.searchEngine === 'meilisearch' ? 'Meili' : search.searchEngine === 'fulltext' ? 'MySQL FT' : 'MySQL LIKE' }}
              </span>
              <span v-if="search.counts.total > 0">
                {{ search.counts.total }} results
              </span>
              <div class="relative">
                <button
                  @click="handleRebuildIndex"
                  @mouseenter="loadIndexStats"
                  @mouseleave="showIndexStats = false"
                  :disabled="isRebuilding"
                  class="flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium transition-all hover:bg-surface-200 dark:hover:bg-surface-700 disabled:opacity-50"
                  :class="isRebuilding ? 'text-primary-500' : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
                >
                  <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': isRebuilding }">
                    {{ isRebuilding ? 'progress_activity' : 'refresh' }}
                  </span>
                  {{ isRebuilding ? 'Indexing...' : 'Rebuild Index' }}
                </button>
                
                <!-- Index Stats Tooltip -->
                <Transition name="fade">
                  <div
                    v-if="showIndexStats && search.indexStats && !isRebuilding"
                    class="absolute bottom-full right-0 mb-2 p-3 bg-white dark:bg-surface-800 text-surface-700 dark:text-surface-200 rounded-xl shadow-xl text-xs min-w-[180px] z-50 border border-surface-200 dark:border-surface-700"
                  >
                    <p class="font-semibold mb-2 text-surface-500 dark:text-surface-400 uppercase tracking-wide text-[10px]">Indexed Items</p>
                    <div class="space-y-1.5">
                      <div class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-blue-500 dark:text-blue-400 text-sm">mail</span>
                          Emails
                        </span>
                        <span class="font-medium text-blue-600 dark:text-blue-400">{{ search.indexStats.emails || 0 }}</span>
                      </div>
                      <div class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-sky-500 dark:text-sky-400 text-sm">attachment</span>
                          Attachments
                        </span>
                        <span class="font-medium text-sky-600 dark:text-sky-400">{{ search.indexStats.attachments || 0 }}</span>
                      </div>
                      <div v-if="calendarEnabled" class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-red-500 dark:text-red-400 text-sm">event</span>
                          Events
                        </span>
                        <span class="font-medium text-red-600 dark:text-red-400">{{ search.indexStats.calendar_events || 0 }}</span>
                      </div>
                      <div class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-amber-500 dark:text-amber-400 text-sm">folder</span>
                          Files
                        </span>
                        <span class="font-medium text-amber-600 dark:text-amber-400">{{ search.indexStats.drive_files || 0 }}</span>
                      </div>
                      <div v-if="kanbanBoardsEnabled" class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-purple-500 dark:text-purple-400 text-sm">view_kanban</span>
                          Cards
                        </span>
                        <span class="font-medium text-purple-600 dark:text-purple-400">{{ search.indexStats.cards || 0 }}</span>
                      </div>
                      <div v-if="tasksEnabled" class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-emerald-500 dark:text-emerald-400 text-sm">task_alt</span>
                          Todos
                        </span>
                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ search.indexStats.todos || 0 }}</span>
                      </div>
                      <div class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-rose-500 dark:text-rose-400 text-sm">business</span>
                          Clients
                        </span>
                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ search.indexStats.clients || 0 }}</span>
                      </div>
                      <div v-if="chatEnabled" class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-teal-500 dark:text-teal-400 text-sm">chat</span>
                          Chats
                        </span>
                        <span class="font-medium text-teal-600 dark:text-teal-400">{{ search.indexStats.chat_messages || 0 }}</span>
                      </div>
                      <div v-if="moodboardsEnabled" class="flex justify-between items-center">
                        <span class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-fuchsia-500 dark:text-fuchsia-400 text-sm">dashboard_customize</span>
                          MoodBoards
                        </span>
                        <span class="font-medium text-fuchsia-600 dark:text-fuchsia-400">{{ search.indexStats.mood_board_items || 0 }}</span>
                      </div>
                    </div>
                    <div class="mt-2 pt-2 border-t border-surface-200 dark:border-surface-700 flex justify-between">
                      <span class="text-surface-500 dark:text-surface-400">Total</span>
                      <span class="font-bold text-surface-800 dark:text-white">{{ Object.values(search.indexStats).reduce((a, b) => a + (typeof b === 'number' ? b : 0), 0) }}</span>
                    </div>
                  </div>
                </Transition>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.2s ease;
}

.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-20px) translateX(-50%);
}

/* Filter bar expand animation */
.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  max-height: 0;
  padding-top: 0;
  padding-bottom: 0;
}

.expand-enter-to,
.expand-leave-from {
  max-height: 200px;
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Search term highlighting - uses :deep since <mark> is injected via v-html */
.search-highlight :deep(mark) {
  background-color: rgb(250 204 21 / 0.5);
  color: inherit;
  padding: 0.5px 2px;
  border-radius: 2px;
  font-weight: 600;
}

/* Expand/collapse animation for grouped results */
.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}

.expand-enter-to,
.expand-leave-from {
  opacity: 1;
  max-height: 1000px;
}
</style>

<style>
/* Dark mode search highlighting (unscoped so .dark ancestor works) */
.dark .search-highlight mark {
  background-color: rgb(234 179 8 / 0.3);
  color: rgb(253 224 71);
}
</style>
