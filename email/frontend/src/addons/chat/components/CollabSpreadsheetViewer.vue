<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import * as XLSX from 'xlsx'

const props = defineProps({
  url: {
    type: String,
    required: true
  },
  filename: {
    type: String,
    default: 'Spreadsheet'
  },
  contentId: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close'])

const chatStore = useChatStore()

// State
const loading = ref(true)
const error = ref(null)
const workbook = ref(null)
const sheets = ref([])
const activeSheet = ref(0)
const tableData = ref([])
const headers = ref([])
const selectedCell = ref(null) // { row: 0, col: 0 }
const scrollContainer = ref(null)

// View Together state
const isViewTogether = computed(() => chatStore.viewSession !== null)
const tableContainer = ref(null)

const otherPosition = computed(() => {
  const pos = chatStore.otherParticipantPosition
  if (!pos || pos.position?.type !== 'spreadsheet') return null
  return pos
})

// Other participant's cursor
const otherCursor = computed(() => {
  if (!isViewTogether.value) return null
  return chatStore.otherParticipantCursor
})

// Check if other user is on same sheet and cell
const otherOnSameSheet = computed(() => {
  if (otherPosition.value?.position?.sheet === activeSheet.value) return true
  if (otherCursor.value?.position?.sheet === activeSheet.value) return true
  return false
})

const otherCell = computed(() => {
  if (!otherOnSameSheet.value) return null
  return otherPosition.value?.position?.cell || otherCursor.value?.position?.cell
})

// Other user's cell range selection
const otherCellRange = computed(() => {
  if (!otherOnSameSheet.value) return null
  return otherPosition.value?.position?.cellRange || otherCursor.value?.position?.cellRange
})

// Check if a cell is in the other user's selection
function isCellInOtherSelection(row, col) {
  if (!otherCellRange.value) return false
  const { startRow, endRow, startCol, endCol } = otherCellRange.value
  return row >= startRow && row <= endRow && col >= startCol && col <= endCol
}

// Computed cursor position for display
const displayCursorPosition = computed(() => {
  if (!otherCursor.value || !tableContainer.value) return null
  if (otherCursor.value.x < 0 || otherCursor.value.y < 0) return null
  
  const rect = tableContainer.value.getBoundingClientRect()
  return {
    x: otherCursor.value.x * rect.width,
    y: otherCursor.value.y * rect.height,
    user: otherCursor.value.user
  }
})

function close() {
  emit('close')
}

function handleKeydown(e) {
  if (e.key === 'Escape') {
    close()
  }
}

async function loadSpreadsheet() {
  loading.value = true
  error.value = null
  
  try {
    // Fetch the file
    const response = await fetch(props.url)
    if (!response.ok) throw new Error('Failed to fetch file')
    
    const arrayBuffer = await response.arrayBuffer()
    
    // Parse with SheetJS
    workbook.value = XLSX.read(arrayBuffer, { type: 'array' })
    
    // Get sheet names
    sheets.value = workbook.value.SheetNames
    
    // Load first sheet
    loadSheet(0)
    
    loading.value = false
  } catch (e) {
    console.error('Failed to load spreadsheet:', e)
    error.value = 'Failed to load spreadsheet'
    loading.value = false
  }
}

function loadSheet(index) {
  if (!workbook.value || index < 0 || index >= sheets.value.length) return
  
  activeSheet.value = index
  const sheetName = sheets.value[index]
  const sheet = workbook.value.Sheets[sheetName]
  
  // Convert to JSON with headers
  const json = XLSX.utils.sheet_to_json(sheet, { header: 1 })
  
  if (json.length > 0) {
    // First row as headers
    headers.value = json[0] || []
    // Rest as data
    tableData.value = json.slice(1)
  } else {
    headers.value = []
    tableData.value = []
  }
  
  selectedCell.value = null
  syncPosition()
}

// Multi-cell selection state
const selectionStart = ref(null) // { row, col }
const selectionEnd = ref(null) // { row, col }
const isDragging = ref(false)

function selectCell(row, col) {
  selectedCell.value = { row, col }
  selectionStart.value = { row, col }
  selectionEnd.value = { row, col }
  syncCellSelection()
}

function handleCellMouseDown(row, col, e) {
  isDragging.value = true
  selectionStart.value = { row, col }
  selectionEnd.value = { row, col }
  selectedCell.value = { row, col }
  e.preventDefault() // Prevent text selection
}

function handleCellMouseEnter(row, col) {
  if (isDragging.value) {
    selectionEnd.value = { row, col }
  }
}

function handleCellMouseUp() {
  if (isDragging.value) {
    isDragging.value = false
    syncCellSelection()
  }
}

// Get selection range (normalized so start is always top-left)
const selectionRange = computed(() => {
  if (!selectionStart.value || !selectionEnd.value) return null
  
  const startRow = Math.min(selectionStart.value.row, selectionEnd.value.row)
  const endRow = Math.max(selectionStart.value.row, selectionEnd.value.row)
  const startCol = Math.min(selectionStart.value.col, selectionEnd.value.col)
  const endCol = Math.max(selectionStart.value.col, selectionEnd.value.col)
  
  return { startRow, endRow, startCol, endCol }
})

// Check if a cell is in the current selection
function isCellSelected(row, col) {
  if (!selectionRange.value) return false
  const { startRow, endRow, startCol, endCol } = selectionRange.value
  return row >= startRow && row <= endRow && col >= startCol && col <= endCol
}

// Get selection range as cell addresses (e.g., "A1:C5")
function getSelectionAddress() {
  if (!selectionRange.value) return null
  const { startRow, endRow, startCol, endCol } = selectionRange.value
  
  if (startRow === endRow && startCol === endCol) {
    return getCellAddress(startRow, startCol)
  }
  
  return `${getCellAddress(startRow, startCol)}:${getCellAddress(endRow, endCol)}`
}

function getCellAddress(row, col) {
  // Convert column number to letter (0 = A, 1 = B, etc.)
  const colLetter = String.fromCharCode(65 + col)
  return `${colLetter}${row + 2}` // +2 because row 0 in data is row 2 in Excel (after header)
}

function handleScroll() {
  if (!scrollContainer.value || !isViewTogether.value) return
  
  const { scrollTop, scrollHeight, clientHeight, scrollLeft, scrollWidth, clientWidth } = scrollContainer.value
  const scrollY = scrollHeight > clientHeight ? scrollTop / (scrollHeight - clientHeight) : 0
  const scrollX = scrollWidth > clientWidth ? scrollLeft / (scrollWidth - clientWidth) : 0
  
  chatStore.syncViewPosition({
    type: 'spreadsheet',
    contentId: props.contentId,
    sheet: activeSheet.value,
    cell: getSelectionAddress(),
    cellRange: selectionRange.value,
    scrollX: Math.round(scrollX * 100) / 100,
    scrollY: Math.round(scrollY * 100) / 100
  })
}

function syncCellSelection() {
  if (isViewTogether.value) {
    chatStore.syncViewPosition({
      type: 'spreadsheet',
      contentId: props.contentId,
      sheet: activeSheet.value,
      cell: getSelectionAddress(),
      cellRange: selectionRange.value
      // Don't include scroll position when just selecting cells
    })
  }
}

function syncPosition() {
  if (isViewTogether.value) {
    chatStore.syncViewPosition({
      type: 'spreadsheet',
      contentId: props.contentId,
      sheet: activeSheet.value,
      cell: getSelectionAddress(),
      cellRange: selectionRange.value
      // Don't include scroll position - only sync scroll when actually scrolling
    })
  }
}

function jumpToOther() {
  if (!otherPosition.value?.position) return
  
  const { sheet, cell, scrollX, scrollY } = otherPosition.value.position
  
  // Switch sheet if needed
  if (sheet !== activeSheet.value && sheet >= 0 && sheet < sheets.value.length) {
    loadSheet(sheet)
  }
  
  // Scroll to position
  if (scrollContainer.value) {
    nextTick(() => {
      const { scrollHeight, clientHeight, scrollWidth, clientWidth } = scrollContainer.value
      if (scrollY !== undefined) {
        scrollContainer.value.scrollTop = scrollY * (scrollHeight - clientHeight)
      }
      if (scrollX !== undefined) {
        scrollContainer.value.scrollLeft = scrollX * (scrollWidth - clientWidth)
      }
    })
  }
}

function downloadSpreadsheet() {
  const link = document.createElement('a')
  link.href = props.url
  link.download = props.filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function getInitials(name) {
  if (!name) return '?'
  return name.substring(0, 2).toUpperCase()
}

// Handle mouse movement for cursor sync
function handleMouseMove(e) {
  if (!isViewTogether.value || !tableContainer.value) return
  
  const rect = tableContainer.value.getBoundingClientRect()
  const x = e.clientX - rect.left
  const y = e.clientY - rect.top
  
  // Include current position so other user knows we're on same sheet
  // Don't include scroll position here - only sync scroll when actually scrolling
  const currentPosition = {
    type: 'spreadsheet',
    contentId: props.contentId,
    sheet: activeSheet.value,
    cell: getSelectionAddress(),
    cellRange: selectionRange.value
  }
  
  chatStore.syncCursorPosition(x, y, rect.width, rect.height, currentPosition)
}

function handleMouseLeave() {
  if (isViewTogether.value) {
    chatStore.syncCursorPosition(-1, -1, 1, 1, null)
  }
  // End drag if leaving the table
  if (isDragging.value) {
    isDragging.value = false
    syncCellSelection()
  }
}

// Parse cell address to get row/col indices
function parseCellAddress(addr) {
  if (!addr) return null
  const match = addr.match(/^([A-Z]+)(\d+)$/)
  if (!match) return null
  const col = match[1].charCodeAt(0) - 65
  const row = parseInt(match[2]) - 2 // -2 because row 2 in Excel is row 0 in data
  return { row, col }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
  document.body.style.overflow = 'hidden'
  
  loadSpreadsheet()
  
  // If View Together is active, sync initial position
  if (isViewTogether.value && chatStore.viewSession?.contentType === 'pending') {
    chatStore.startViewSession('spreadsheet', props.contentId)
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
  document.body.style.overflow = ''
})

// Watch for other participant's position - auto-follow if enabled or sync scroll is on
watch(() => chatStore.otherParticipantPosition, (pos) => {
  if (!pos || pos.position?.type !== 'spreadsheet') return
  
  // Auto-follow if follow mode OR sync scroll mode is enabled (and we're not the presenter)
  const shouldFollow = chatStore.followMode || (chatStore.syncScrollMode && !chatStore.isPresenter)
  
  if (shouldFollow) {
    const { sheet, cell, cellRange, scrollX, scrollY } = pos.position
    
    // Switch sheet if needed
    if (sheet !== undefined && sheet !== activeSheet.value && sheet >= 0 && sheet < sheets.value.length) {
      loadSheet(sheet)
    }
    
    // Apply cell range selection if specified
    if (cellRange) {
      selectionStart.value = { row: cellRange.startRow, col: cellRange.startCol }
      selectionEnd.value = { row: cellRange.endRow, col: cellRange.endCol }
      selectedCell.value = { row: cellRange.startRow, col: cellRange.startCol }
    } else if (cell) {
      // Single cell selection
      const parsed = parseCellAddress(cell)
      if (parsed) {
        selectedCell.value = parsed
        selectionStart.value = parsed
        selectionEnd.value = parsed
      }
    }
    
    // Only scroll if scroll values are explicitly provided (numbers between 0 and 1)
    if (scrollContainer.value) {
      nextTick(() => {
        const { scrollHeight, clientHeight, scrollWidth, clientWidth } = scrollContainer.value
        if (typeof scrollY === 'number' && scrollY >= 0) {
          scrollContainer.value.scrollTop = scrollY * (scrollHeight - clientHeight)
        }
        if (typeof scrollX === 'number' && scrollX >= 0) {
          scrollContainer.value.scrollLeft = scrollX * (scrollWidth - clientWidth)
        }
      })
    }
  }
}, { deep: true })
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[99990] flex flex-col bg-surface-900">
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 bg-surface-800 border-b border-surface-700">
        <div class="flex items-center gap-4">
          <span class="material-symbols-rounded text-2xl text-green-500">table_chart</span>
          <div>
            <p class="font-medium text-white">{{ filename }}</p>
            <p class="text-sm text-surface-400">
              {{ sheets.length }} sheet{{ sheets.length !== 1 ? 's' : '' }}
              <span v-if="selectedCell">
                - Cell {{ getCellAddress(selectedCell.row, selectedCell.col) }}
              </span>
            </p>
          </div>
        </div>
        
        <div class="flex items-center gap-2">
          <!-- View Together indicator -->
          <div 
            v-if="isViewTogether"
            class="flex items-center gap-2 px-3 py-1.5 bg-primary-500 rounded-full text-sm text-white mr-2"
          >
            <span class="material-symbols-rounded text-sm animate-pulse">screen_share</span>
            <span>View Together</span>
            <span v-if="chatStore.followMode" class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Following</span>
          </div>
          
          <button
            @click="downloadSpreadsheet"
            class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white"
            title="Download"
          >
            <span class="material-symbols-rounded">download</span>
          </button>
          
          <button
            @click="close"
            class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white"
            title="Close (Esc)"
          >
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
      </div>
      
      <!-- Sheet tabs -->
      <div v-if="sheets.length > 1" class="flex items-center gap-1 px-4 py-2 bg-surface-800 border-b border-surface-700 overflow-x-auto">
        <button
          v-for="(sheet, index) in sheets"
          :key="sheet"
          @click="loadSheet(index)"
          :class="[
            'relative px-4 py-1.5 rounded-t-lg text-sm transition-colors whitespace-nowrap',
            index === activeSheet 
              ? 'bg-surface-700 text-white' 
              : 'text-surface-400 hover:text-white hover:bg-surface-700/50'
          ]"
        >
          {{ sheet }}
          <!-- Other user on this sheet indicator -->
          <span 
            v-if="isViewTogether && otherPosition?.position?.sheet === index && index !== activeSheet"
            class="absolute -top-1 -right-1 w-4 h-4 bg-amber-500 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
          >
            {{ getInitials(otherPosition?.user?.name).charAt(0) }}
          </span>
        </button>
      </div>
      
      <!-- Other participant on different sheet indicator -->
      <div 
        v-if="isViewTogether && otherPosition && !otherOnSameSheet"
        class="px-4 py-2 bg-amber-500/20 border-b border-amber-500/30"
      >
        <button
          @click="jumpToOther"
          class="flex items-center gap-2 text-amber-400 text-sm hover:text-amber-300 transition-colors"
        >
          <span class="w-5 h-5 bg-amber-500 text-white rounded-full flex items-center justify-center text-xs font-medium">
            {{ getInitials(otherPosition.user?.name) }}
          </span>
          <span>{{ otherPosition.user?.name }} is on "{{ sheets[otherPosition.position?.sheet] }}"</span>
          <span class="material-symbols-rounded text-sm">arrow_forward</span>
        </button>
      </div>
      
      <!-- Spreadsheet content -->
      <div 
        ref="scrollContainer"
        class="flex-1 overflow-auto relative"
        @scroll="handleScroll"
      >
        <!-- Table container for cursor tracking -->
        <div
          ref="tableContainer"
          class="relative min-h-full"
          @mousemove="handleMouseMove"
          @mouseleave="handleMouseLeave"
        >
          <!-- Loading state -->
          <div v-if="loading" class="flex items-center justify-center h-full min-h-[400px]">
            <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">progress_activity</span>
          </div>
          
          <!-- Error state -->
          <div v-else-if="error" class="flex flex-col items-center justify-center h-full min-h-[400px] text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2">error</span>
            <p>{{ error }}</p>
          </div>
          
          <!-- Table -->
          <table 
            v-else 
            class="w-full border-collapse select-none"
            @mouseup="handleCellMouseUp"
          >
          <thead class="sticky top-0 z-10">
            <tr>
              <th class="w-12 px-3 py-2 bg-surface-700 text-surface-400 text-xs font-medium text-center border-r border-surface-600">
                #
              </th>
              <th 
                v-for="(header, colIndex) in headers"
                :key="colIndex"
                class="px-3 py-2 bg-surface-700 text-white text-sm font-medium text-left border-r border-surface-600 min-w-[100px]"
              >
                {{ header || String.fromCharCode(65 + colIndex) }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr 
              v-for="(row, rowIndex) in tableData"
              :key="rowIndex"
              class="hover:bg-surface-800/50"
            >
              <td class="px-3 py-1.5 bg-surface-800 text-surface-400 text-xs text-center border-r border-b border-surface-700">
                {{ rowIndex + 2 }}
              </td>
              <td 
                v-for="(cell, colIndex) in row"
                :key="colIndex"
                @mousedown="handleCellMouseDown(rowIndex, colIndex, $event)"
                @mouseenter="handleCellMouseEnter(rowIndex, colIndex)"
                :class="[
                  'px-3 py-1.5 text-sm border-r border-b border-surface-700 cursor-cell transition-colors',
                  // Our selection (single or multi)
                  isCellSelected(rowIndex, colIndex)
                    ? 'bg-primary-500/30 text-white'
                    : 'text-surface-200 hover:bg-surface-700/50',
                  // Primary selected cell gets a ring
                  selectedCell?.row === rowIndex && selectedCell?.col === colIndex
                    ? 'ring-2 ring-primary-500 ring-inset'
                    : '',
                  // Other user's selection
                  otherOnSameSheet && isCellInOtherSelection(rowIndex, colIndex)
                    ? 'bg-amber-500/20'
                    : ''
                ]"
              >
                <div class="relative">
                  {{ cell ?? '' }}
                  <!-- Other user's cell indicator (show on first cell of their selection) -->
                  <span 
                    v-if="otherOnSameSheet && otherCellRange && otherCellRange.startRow === rowIndex && otherCellRange.startCol === colIndex"
                    class="absolute -top-1 -right-1 w-4 h-4 bg-amber-500 rounded-full flex items-center justify-center text-[10px] font-bold text-white z-10"
                    :title="(otherPosition?.user?.name || otherCursor?.user?.name) + ' selected'"
                  >
                    {{ getInitials(otherPosition?.user?.name || otherCursor?.user?.name).charAt(0) }}
                  </span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        
          <!-- Empty state -->
          <div v-if="!loading && !error && tableData.length === 0" class="flex flex-col items-center justify-center h-64 text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2">table_chart</span>
            <p>This sheet is empty</p>
          </div>
          
          <!-- Other participant's cursor -->
          <div 
            v-if="isViewTogether && otherOnSameSheet && displayCursorPosition"
            class="absolute pointer-events-none transition-all duration-75 z-50"
            :style="{ 
              left: displayCursorPosition.x + 'px', 
              top: displayCursorPosition.y + 'px',
              transform: 'translate(-4px, -4px)'
            }"
          >
            <!-- Cursor pointer -->
            <svg width="24" height="24" viewBox="0 0 24 24" class="drop-shadow-lg">
              <path 
                d="M4 4 L4 20 L9 15 L14 20 L16 18 L11 13 L18 13 Z" 
                fill="#f59e0b" 
                stroke="white" 
                stroke-width="1.5"
              />
            </svg>
            <!-- Name tag -->
            <div class="absolute left-5 top-4 px-2 py-0.5 bg-amber-500 text-white text-xs rounded whitespace-nowrap">
              {{ displayCursorPosition.user?.name || 'Participant' }}
            </div>
          </div>
        </div>
      </div>
      
      <!-- Footer with cell info -->
      <div class="flex items-center justify-between px-4 py-2 bg-surface-800 border-t border-surface-700 text-sm text-surface-400">
        <div>
          <span v-if="selectedCell">
            {{ getSelectionAddress() }}: 
            {{ tableData[selectedCell.row]?.[selectedCell.col] ?? '(empty)' }}
          </span>
          <span v-else>Click a cell to select</span>
        </div>
        
        <!-- Other user's selection indicator -->
        <div 
          v-if="isViewTogether && otherOnSameSheet && (otherCell || otherCellRange)"
          class="flex items-center gap-2"
        >
          <span class="w-5 h-5 bg-amber-500 text-white rounded-full flex items-center justify-center text-xs font-medium">
            {{ getInitials(otherPosition?.user?.name || otherCursor?.user?.name) }}
          </span>
          <span>{{ otherPosition?.user?.name || otherCursor?.user?.name }} at {{ otherCell }}</span>
        </div>
      </div>
    </div>
  </Teleport>
</template>

