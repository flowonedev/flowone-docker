<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '@/services/api'
import { useAddons } from '@/composables/useAddons'

const emit = defineEmits(['select', 'close'])
const { moodboardsEnabled, kanbanBoardsEnabled, calendarEnabled } = useAddons()

// Navigation state
const selectedCategory = ref(null) // null = show categories, 'drive' | 'calendar' | 'boards' | 'documents' | 'mood_boards'
const selectedBoard = ref(null) // When drilling into a specific board for cards
const drillStack = ref([]) // Navigation breadcrumb stack for drive folders
const search = ref('')

// Loading states
const loadingItems = ref(false)

// Data holders
const driveFolders = ref([])
const driveFiles = ref([])
const currentDriveFolderId = ref(null)
const calendarEvents = ref([])
const boards = ref([])
const boardCards = ref([])
const collabDocuments = ref([])
const moodBoards = ref([])

// Categories configuration (mood_boards gated by addon)
const categories = computed(() => {
  const items = [
    { type: 'drive', icon: 'hard_drive', label: 'Drive', color: 'text-blue-500' },
    { type: 'documents', icon: 'edit_document', label: 'Documents', color: 'text-amber-500' },
  ]
  if (calendarEnabled.value) {
    items.push({ type: 'calendar', icon: 'calendar_month', label: 'Calendar Event', color: 'text-green-500' })
  }
  if (kanbanBoardsEnabled.value) {
    items.push({ type: 'boards', icon: 'dashboard', label: 'Board / Card', color: 'text-purple-500' })
  }
  if (moodboardsEnabled.value) {
    items.push({ type: 'mood_boards', icon: 'palette', label: 'Mood Board', color: 'text-pink-500' })
  }
  return items
})

// Header title
const headerTitle = computed(() => {
  if (!selectedCategory.value) return 'Share in chat'
  if (selectedCategory.value === 'drive') {
    if (drillStack.value.length > 0) return drillStack.value[drillStack.value.length - 1].name
    return 'Drive'
  }
  if (selectedCategory.value === 'documents') return 'Documents'
  if (selectedCategory.value === 'calendar') return 'Calendar Events'
  if (selectedCategory.value === 'boards') {
    if (selectedBoard.value) return selectedBoard.value.name
    return 'Boards'
  }
  if (selectedCategory.value === 'mood_boards') return 'Mood Boards'
  return 'Share in chat'
})

// Can go back?
const canGoBack = computed(() => {
  if (!selectedCategory.value) return false
  if (selectedCategory.value === 'drive' && drillStack.value.length > 0) return true
  if (selectedCategory.value === 'boards' && selectedBoard.value) return true
  return !!selectedCategory.value
})

// ---- DRIVE ----
async function loadDriveContents(folderId = null) {
  loadingItems.value = true
  try {
    const params = {}
    if (folderId) params.folder_id = folderId
    const res = await api.get('/drive', { params })
    if (res.data.success) {
      driveFolders.value = res.data.data.folders || []
      driveFiles.value = res.data.data.files || []
      currentDriveFolderId.value = folderId
    }
  } catch (e) {
    console.error('Failed to load drive contents:', e)
  }
  loadingItems.value = false
}

function driveOpenFolder(folder) {
  drillStack.value.push({ id: folder.id, name: folder.name })
  loadDriveContents(folder.id)
}

function selectDriveFolder(folder) {
  emit('select', `[embed:drive_folder:${folder.id}]`)
}

function selectDriveFile(file) {
  emit('select', `[embed:drive_file:${file.id}]`)
}

// Filtered drive items
const filteredDriveFolders = computed(() => {
  if (!search.value) return driveFolders.value
  const q = search.value.toLowerCase()
  return driveFolders.value.filter(f => f.name.toLowerCase().includes(q))
})

const filteredDriveFiles = computed(() => {
  if (!search.value) return driveFiles.value
  const q = search.value.toLowerCase()
  return driveFiles.value.filter(f => (f.original_name || f.name || '').toLowerCase().includes(q))
})

// ---- DOCUMENTS (Collab) ----
async function loadCollabDocuments() {
  loadingItems.value = true
  try {
    const res = await api.get('/collab/documents', { params: { limit: 50 } })
    if (res.data.success) {
      collabDocuments.value = res.data.data?.documents || res.data.data || []
    }
  } catch (e) {
    console.error('Failed to load collab documents:', e)
  }
  loadingItems.value = false
}

function selectCollabDocument(doc) {
  emit('select', `[embed:collab_doc:${doc.id}]`)
}

const filteredCollabDocuments = computed(() => {
  if (!search.value) return collabDocuments.value
  const q = search.value.toLowerCase()
  return collabDocuments.value.filter(d => (d.title || '').toLowerCase().includes(q))
})

function getDocTypeIcon(type) {
  if (type === 'presentation') return 'slideshow'
  return 'description'
}

function getDocTypeColor(type) {
  if (type === 'presentation') return 'text-orange-500'
  return 'text-amber-500'
}

function formatDocDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now - d
  const diffMins = Math.floor(diffMs / 60000)
  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  const diffHours = Math.floor(diffMins / 60)
  if (diffHours < 24) return `${diffHours}h ago`
  const diffDays = Math.floor(diffHours / 24)
  if (diffDays < 7) return `${diffDays}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

// ---- CALENDAR ----
async function loadCalendarEvents() {
  loadingItems.value = true
  try {
    // Load upcoming events (next 60 days)
    const now = new Date()
    const start = now.toISOString().split('T')[0]
    const end = new Date(now.getTime() + 60 * 86400000).toISOString().split('T')[0]
    
    const res = await api.get('/events', { params: { start, end } })
    if (res.data.success) {
      calendarEvents.value = (res.data.data.events || []).sort((a, b) => 
        new Date(a.start_time).getTime() - new Date(b.start_time).getTime()
      )
    }
  } catch (e) {
    console.error('Failed to load calendar events:', e)
  }
  loadingItems.value = false
}

function selectCalendarEvent(event) {
  emit('select', `[embed:calendar_event:${event.id}]`)
}

const filteredCalendarEvents = computed(() => {
  if (!search.value) return calendarEvents.value
  const q = search.value.toLowerCase()
  return calendarEvents.value.filter(e => e.title.toLowerCase().includes(q))
})

// ---- BOARDS ----
async function loadBoards() {
  loadingItems.value = true
  try {
    const res = await api.get('/boards')
    if (res.data.success) {
      boards.value = res.data.data.boards || []
    }
  } catch (e) {
    console.error('Failed to load boards:', e)
  }
  loadingItems.value = false
}

async function openBoard(board) {
  selectedBoard.value = board
  loadingItems.value = true
  try {
    const res = await api.get(`/boards/${board.id}`)
    if (res.data.success) {
      const fullBoard = res.data.data.board
      // Flatten cards from all lists
      boardCards.value = (fullBoard.lists || []).flatMap(list => 
        (list.cards || []).map(card => ({
          ...card,
          list_name: list.name,
          board_name: board.name,
        }))
      )
    }
  } catch (e) {
    console.error('Failed to load board:', e)
  }
  loadingItems.value = false
}

function selectBoard(board) {
  emit('select', `[embed:board:${board.id}]`)
}

function selectBoardCard(card) {
  emit('select', `[embed:board_card:${card.id}]`)
}

const filteredBoards = computed(() => {
  if (!search.value) return boards.value
  const q = search.value.toLowerCase()
  return boards.value.filter(b => b.name.toLowerCase().includes(q))
})

const filteredBoardCards = computed(() => {
  if (!search.value) return boardCards.value
  const q = search.value.toLowerCase()
  return boardCards.value.filter(c => c.title.toLowerCase().includes(q))
})

// ---- MOOD BOARDS ----
async function loadMoodBoards() {
  loadingItems.value = true
  try {
    const res = await api.get('/mood-boards')
    if (res.data.success) {
      moodBoards.value = (res.data.data.boards || []).filter(b => !b.archived)
    }
  } catch (e) {
    console.error('Failed to load mood boards:', e)
  }
  loadingItems.value = false
}

function selectMoodBoard(board) {
  emit('select', `[embed:mood_board:${board.id}]`)
}

const filteredMoodBoards = computed(() => {
  if (!search.value) return moodBoards.value
  const q = search.value.toLowerCase()
  return moodBoards.value.filter(b => (b.name || '').toLowerCase().includes(q))
})

// ---- NAVIGATION ----
function selectCategory(cat) {
  selectedCategory.value = cat.type
  search.value = ''
  
  if (cat.type === 'drive') {
    drillStack.value = []
    loadDriveContents(null)
  } else if (cat.type === 'documents') {
    loadCollabDocuments()
  } else if (cat.type === 'calendar') {
    loadCalendarEvents()
  } else if (cat.type === 'boards') {
    selectedBoard.value = null
    loadBoards()
  } else if (cat.type === 'mood_boards') {
    loadMoodBoards()
  }
}

function goBack() {
  search.value = ''
  
  if (selectedCategory.value === 'drive' && drillStack.value.length > 0) {
    drillStack.value.pop()
    const parentId = drillStack.value.length > 0 ? drillStack.value[drillStack.value.length - 1].id : null
    loadDriveContents(parentId)
    return
  }
  
  if (selectedCategory.value === 'boards' && selectedBoard.value) {
    selectedBoard.value = null
    boardCards.value = []
    return
  }
  
  // Go back to categories
  selectedCategory.value = null
  selectedBoard.value = null
  drillStack.value = []
}

// Helper functions
function getFileIcon(mimeType) {
  if (!mimeType) return 'draft'
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('video/')) return 'movie'
  if (mimeType.startsWith('audio/')) return 'audio_file'
  if (mimeType.includes('pdf')) return 'picture_as_pdf'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'description'
  if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'table_chart'
  if (mimeType.includes('zip') || mimeType.includes('rar')) return 'folder_zip'
  return 'draft'
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

function formatEventDate(dateStr) {
  const d = new Date(dateStr)
  const today = new Date()
  const tomorrow = new Date(today)
  tomorrow.setDate(tomorrow.getDate() + 1)
  
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === tomorrow.toDateString()) return 'Tomorrow'
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

function formatEventTime(dateStr) {
  return new Date(dateStr).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

// Close on escape
function handleKeydown(e) {
  if (e.key === 'Escape') {
    if (canGoBack.value) {
      goBack()
    } else {
      emit('close')
    }
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <!-- Backdrop -->
  <div class="fixed inset-0 z-40" @click="emit('close')"></div>
  
  <!-- Picker popover -->
  <div class="absolute left-0 bottom-full mb-2 w-72 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 overflow-hidden z-50">
    
    <!-- Header -->
    <div class="flex items-center gap-2 px-3 py-2.5 border-b border-surface-100 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
      <button 
        v-if="canGoBack"
        @click="goBack"
        class="w-7 h-7 flex items-center justify-center hover:bg-surface-200 dark:hover:bg-surface-700 rounded-full transition-colors"
      >
        <span class="material-symbols-rounded text-lg text-surface-500">arrow_back</span>
      </button>
      <span v-else class="material-symbols-rounded text-lg text-surface-400">add_link</span>
      <span class="text-sm font-medium text-surface-700 dark:text-surface-200 flex-1 truncate">{{ headerTitle }}</span>
      <button 
        @click="emit('close')"
        class="w-7 h-7 flex items-center justify-center hover:bg-surface-200 dark:hover:bg-surface-700 rounded-full transition-colors"
      >
        <span class="material-symbols-rounded text-lg text-surface-400">close</span>
      </button>
    </div>
    
    <!-- Search (only when in a category) -->
    <div v-if="selectedCategory" class="px-3 py-2 border-b border-surface-100 dark:border-surface-700">
      <div class="flex items-center gap-2 px-2.5 py-1.5 bg-surface-100 dark:bg-surface-900 rounded-lg">
        <span class="material-symbols-rounded text-surface-400 text-lg">search</span>
        <input 
          v-model="search"
          type="text"
          placeholder="Search..."
          class="bg-transparent text-sm outline-none flex-1 text-surface-800 dark:text-surface-200 placeholder-surface-400"
        />
      </div>
    </div>
    
    <!-- Level 1: Categories -->
    <div v-if="!selectedCategory" class="max-h-72 overflow-y-auto py-1">
      <button 
        v-for="cat in categories" 
        :key="cat.type"
        @click="selectCategory(cat)"
        class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
      >
        <span class="material-symbols-rounded text-xl" :class="cat.color">{{ cat.icon }}</span>
        <span class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ cat.label }}</span>
        <span class="material-symbols-rounded text-surface-300 ml-auto text-lg">chevron_right</span>
      </button>
    </div>
    
    <!-- Loading -->
    <div v-else-if="loadingItems" class="flex items-center justify-center py-10">
      <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
    </div>
    
    <!-- Level 2: Drive browser -->
    <div v-else-if="selectedCategory === 'drive'" class="max-h-72 overflow-y-auto">
      <!-- Folders -->
      <div v-if="filteredDriveFolders.length" class="py-1">
        <div 
          v-for="folder in filteredDriveFolders" 
          :key="'f-' + folder.id"
          class="flex items-center gap-2.5 px-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors group"
        >
          <span 
            class="material-symbols-rounded text-xl flex-shrink-0"
            :style="folder.color ? { color: folder.color } : {}"
            :class="!folder.color && 'text-blue-400'"
          >folder</span>
          <button @click="driveOpenFolder(folder)" class="flex-1 text-left min-w-0">
            <span class="text-sm text-surface-700 dark:text-surface-200 truncate block">{{ folder.name }}</span>
          </button>
          <button 
            @click.stop="selectDriveFolder(folder)"
            class="w-7 h-7 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-all"
            title="Share this folder"
          >
            <span class="material-symbols-rounded text-blue-500 text-lg">share</span>
          </button>
          <button 
            @click="driveOpenFolder(folder)"
            class="w-7 h-7 flex items-center justify-center rounded-full hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors flex-shrink-0"
          >
            <span class="material-symbols-rounded text-surface-400 text-lg">chevron_right</span>
          </button>
        </div>
      </div>
      
      <!-- Files -->
      <div v-if="filteredDriveFiles.length" class="py-1">
        <button 
          v-for="file in filteredDriveFiles" 
          :key="'fi-' + file.id"
          @click="selectDriveFile(file)"
          class="w-full flex items-center gap-2.5 px-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
        >
          <span class="material-symbols-rounded text-xl text-surface-400 flex-shrink-0">{{ getFileIcon(file.mime_type) }}</span>
          <div class="flex-1 min-w-0 text-left">
            <span class="text-sm text-surface-700 dark:text-surface-200 truncate block">{{ file.original_name || file.name }}</span>
            <span class="text-xs text-surface-400">{{ formatSize(file.size) }}</span>
          </div>
        </button>
      </div>
      
      <!-- Empty state -->
      <div v-if="!filteredDriveFolders.length && !filteredDriveFiles.length" class="py-8 text-center text-surface-400 text-sm">
        {{ search ? 'No results found' : 'Empty folder' }}
      </div>
    </div>
    
    <!-- Level 2: Collab Documents -->
    <div v-else-if="selectedCategory === 'documents'" class="max-h-72 overflow-y-auto">
      <div v-if="filteredCollabDocuments.length" class="py-1">
        <button 
          v-for="doc in filteredCollabDocuments" 
          :key="'doc-' + doc.id"
          @click="selectCollabDocument(doc)"
          class="w-full flex items-center gap-2.5 px-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
        >
          <span 
            class="material-symbols-rounded text-xl flex-shrink-0"
            :class="getDocTypeColor(doc.type)"
          >{{ getDocTypeIcon(doc.type) }}</span>
          <div class="flex-1 min-w-0 text-left">
            <span class="text-sm text-surface-700 dark:text-surface-200 truncate block">{{ doc.title || 'Untitled' }}</span>
            <div class="flex items-center gap-1.5 text-xs text-surface-400 mt-0.5">
              <span class="capitalize">{{ doc.type || 'document' }}</span>
              <span v-if="doc.updated_at" class="text-surface-300 dark:text-surface-600">|</span>
              <span v-if="doc.updated_at">{{ formatDocDate(doc.updated_at) }}</span>
            </div>
          </div>
        </button>
      </div>
      
      <div v-else class="py-8 text-center text-surface-400 text-sm">
        {{ search ? 'No documents found' : 'No documents yet' }}
      </div>
    </div>
    
    <!-- Level 2: Calendar events -->
    <div v-else-if="selectedCategory === 'calendar'" class="max-h-72 overflow-y-auto">
      <div v-if="filteredCalendarEvents.length" class="py-1">
        <button 
          v-for="event in filteredCalendarEvents" 
          :key="event.id"
          @click="selectCalendarEvent(event)"
          class="w-full flex items-center gap-2.5 px-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
        >
          <span 
            class="w-2 h-8 rounded-full flex-shrink-0"
            :style="{ backgroundColor: event.color || event.calendar_color || '#3b82f6' }"
          ></span>
          <div class="flex-1 min-w-0 text-left">
            <span class="text-sm text-surface-700 dark:text-surface-200 truncate block">{{ event.title }}</span>
            <div class="flex items-center gap-1 text-xs text-surface-400">
              <span>{{ formatEventDate(event.start_time) }}</span>
              <span v-if="!event.all_day">{{ formatEventTime(event.start_time) }}</span>
            </div>
          </div>
        </button>
      </div>
      
      <div v-else class="py-8 text-center text-surface-400 text-sm">
        {{ search ? 'No events found' : 'No upcoming events' }}
      </div>
    </div>
    
    <!-- Level 2: Boards list / Level 3: Board cards -->
    <div v-else-if="selectedCategory === 'boards'" class="max-h-72 overflow-y-auto">
      <!-- Board list (level 2) -->
      <template v-if="!selectedBoard">
        <div v-if="filteredBoards.length" class="py-1">
          <div 
            v-for="board in filteredBoards" 
            :key="board.id"
            class="flex items-center gap-2.5 px-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors group"
          >
            <div 
              class="w-6 h-6 rounded flex-shrink-0"
              :style="{ backgroundColor: board.background_color || '#7c3aed' }"
            ></div>
            <button @click="openBoard(board)" class="flex-1 text-left min-w-0">
              <span class="text-sm text-surface-700 dark:text-surface-200 truncate block">{{ board.name }}</span>
              <span class="text-xs text-surface-400">{{ board.card_count || 0 }} cards</span>
            </button>
            <button 
              @click.stop="selectBoard(board)"
              class="w-7 h-7 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 hover:bg-purple-100 dark:hover:bg-purple-900/40 transition-all"
              title="Share entire board"
            >
              <span class="material-symbols-rounded text-purple-500 text-lg">share</span>
            </button>
            <button 
              @click="openBoard(board)"
              class="w-7 h-7 flex items-center justify-center rounded-full hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors flex-shrink-0"
              title="Browse cards"
            >
              <span class="material-symbols-rounded text-surface-400 text-lg">chevron_right</span>
            </button>
          </div>
        </div>
        <div v-else class="py-8 text-center text-surface-400 text-sm">
          {{ search ? 'No boards found' : 'No boards yet' }}
        </div>
      </template>
      
      <!-- Board cards (level 3) -->
      <template v-else>
        <div v-if="filteredBoardCards.length" class="py-1">
          <button 
            v-for="card in filteredBoardCards" 
            :key="card.id"
            @click="selectBoardCard(card)"
            class="w-full flex items-center gap-2.5 px-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
          >
            <span 
              class="material-symbols-rounded text-lg flex-shrink-0"
              :class="card.completed ? 'text-green-500' : 'text-purple-400'"
            >{{ card.completed ? 'check_circle' : 'credit_card' }}</span>
            <div class="flex-1 min-w-0 text-left">
              <span 
                class="text-sm text-surface-700 dark:text-surface-200 truncate block"
                :class="card.completed && 'line-through opacity-60'"
              >{{ card.title }}</span>
              <span class="text-xs text-surface-400 truncate block">{{ card.list_name }}</span>
            </div>
            <span v-if="card.due_date" class="text-xs text-surface-400 flex-shrink-0">
              {{ new Date(card.due_date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) }}
            </span>
          </button>
        </div>
        <div v-else class="py-8 text-center text-surface-400 text-sm">
          {{ search ? 'No cards found' : 'No cards on this board' }}
        </div>
      </template>
    </div>
    
    <!-- Level 2: Mood Boards -->
    <div v-else-if="selectedCategory === 'mood_boards'" class="max-h-72 overflow-y-auto">
      <div v-if="filteredMoodBoards.length" class="py-1">
        <button 
          v-for="board in filteredMoodBoards" 
          :key="'mb-' + board.id"
          @click="selectMoodBoard(board)"
          class="w-full flex items-center gap-2.5 px-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
        >
          <div 
            class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
            :style="{ backgroundColor: board.background_color || '#f5f5f5' }"
          >
            <span class="material-symbols-rounded text-pink-500 dark:text-pink-400 text-lg">palette</span>
          </div>
          <div class="flex-1 min-w-0 text-left">
            <span class="text-sm text-surface-700 dark:text-surface-200 truncate block">{{ board.name }}</span>
            <span v-if="board.description" class="text-xs text-surface-400 truncate block">{{ board.description }}</span>
          </div>
        </button>
      </div>
      <div v-else class="py-8 text-center text-surface-400 text-sm">
        {{ search ? 'No mood boards found' : 'No mood boards yet' }}
      </div>
    </div>
    
  </div>
</template>

