<script setup>
import { ref, watch, computed } from 'vue'
import * as XLSX from 'xlsx'

const props = defineProps({
  leftContent: Object,
  rightContent: Object,
  leftVersion: Object,
  rightVersion: Object,
  mode: { type: String, default: 'side-by-side' }
})

const leftSheets = ref([])
const rightSheets = ref([])
const activeSheetName = ref(null)
const loading = ref(true)
const error = ref(null)
const syncScroll = ref(true)
const showDiffHighlight = ref(true)
const showChangedOnly = ref(false)

// Scroll refs
const leftScrollRef = ref(null)
const rightScrollRef = ref(null)
let isScrolling = false

// Stats
const stats = ref({ changed: 0, addedRows: 0, removedRows: 0 })

const EMPTY_SHEET = { headers: [], rows: [], data: [], rowCount: 0, colCount: 0 }

// Union of sheet names from both versions (sheets can be added/removed across versions)
const sheetNames = computed(() => {
  const names = leftSheets.value.map(s => s.name)
  rightSheets.value.forEach(s => {
    if (!names.includes(s.name)) names.push(s.name)
  })
  return names
})

const leftActiveSheet = computed(() =>
  leftSheets.value.find(s => s.name === activeSheetName.value) || EMPTY_SHEET
)
const rightActiveSheet = computed(() =>
  rightSheets.value.find(s => s.name === activeSheetName.value) || EMPTY_SHEET
)

function sheetOnlyOn(name) {
  const inLeft = leftSheets.value.some(s => s.name === name)
  const inRight = rightSheets.value.some(s => s.name === name)
  if (inLeft && !inRight) return 'left'
  if (!inLeft && inRight) return 'right'
  return null
}

// Convert base64 to ArrayBuffer
function base64ToArrayBuffer(base64) {
  const base64Data = base64.replace(/^data:[^;]+;base64,/, '')
  const binaryString = atob(base64Data)
  const bytes = new Uint8Array(binaryString.length)
  for (let i = 0; i < binaryString.length; i++) {
    bytes[i] = binaryString.charCodeAt(i)
  }
  return bytes.buffer
}

function parseSpreadsheet(content) {
  if (!content?.content) {
    throw new Error('No content provided')
  }

  const arrayBuffer = base64ToArrayBuffer(content.content)
  const workbook = XLSX.read(arrayBuffer, { type: 'array' })

  return workbook.SheetNames.map(name => {
    const worksheet = workbook.Sheets[name]
    const json = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' })

    const headers = json[0] || []
    const rows = json.slice(1)

    return {
      name,
      headers,
      rows,
      data: json,
      rowCount: json.length,
      colCount: json.reduce((max, row) => Math.max(max, row?.length || 0), 0)
    }
  })
}

// Compare cells and generate diff data
const diffData = computed(() => {
  if (!activeSheetName.value) return null

  const leftSheet = leftActiveSheet.value
  const rightSheet = rightActiveSheet.value

  const leftData = leftSheet.data
  const rightData = rightSheet.data

  const maxRows = Math.max(leftData.length, rightData.length)
  const maxCols = Math.max(leftSheet.colCount, rightSheet.colCount)

  const rows = []
  let changedCount = 0
  let addedRowCount = 0
  let removedRowCount = 0

  for (let i = 0; i < maxRows; i++) {
    const leftRow = leftData[i] || []
    const rightRow = rightData[i] || []

    const rowType = !leftData[i] ? 'added' : !rightData[i] ? 'removed' : 'normal'

    if (rowType === 'added') addedRowCount++
    if (rowType === 'removed') removedRowCount++

    const cells = []
    let rowHasChanges = false

    for (let j = 0; j < maxCols; j++) {
      const leftVal = String(leftRow[j] ?? '')
      const rightVal = String(rightRow[j] ?? '')

      let cellType = 'unchanged'

      if (rowType === 'added') {
        cellType = rightVal ? 'added' : 'empty'
      } else if (rowType === 'removed') {
        cellType = leftVal ? 'removed' : 'empty'
      } else if (leftVal !== rightVal) {
        cellType = 'changed'
        changedCount++
        rowHasChanges = true
      }

      cells.push({ leftVal, rightVal, type: cellType })
    }

    rows.push({
      rowIndex: i,
      type: rowType,
      hasChanges: rowHasChanges,
      cells
    })
  }

  stats.value = { changed: changedCount, addedRows: addedRowCount, removedRows: removedRowCount }

  return {
    headers: rightSheet.headers.length > leftSheet.headers.length ? rightSheet.headers : leftSheet.headers,
    rows,
    maxCols
  }
})

const visibleRows = computed(() => {
  if (!diffData.value) return []
  if (!showChangedOnly.value) return diffData.value.rows
  return diffData.value.rows.filter(row => row.type !== 'normal' || row.hasChanges)
})

// Sync scroll handlers
function onLeftScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  const rightEl = rightScrollRef.value
  if (rightEl) {
    rightEl.scrollTop = e.target.scrollTop
    rightEl.scrollLeft = e.target.scrollLeft
  }
  requestAnimationFrame(() => { isScrolling = false })
}

function onRightScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  const leftEl = leftScrollRef.value
  if (leftEl) {
    leftEl.scrollTop = e.target.scrollTop
    leftEl.scrollLeft = e.target.scrollLeft
  }
  requestAnimationFrame(() => { isScrolling = false })
}

async function loadSpreadsheets() {
  loading.value = true
  error.value = null

  try {
    leftSheets.value = parseSpreadsheet(props.leftContent)
    rightSheets.value = parseSpreadsheet(props.rightContent)
    activeSheetName.value = sheetNames.value[0] || null
  } catch (e) {
    console.error('XLSX parsing error:', e)
    error.value = e.message
  } finally {
    loading.value = false
  }
}

watch([() => props.leftContent, () => props.rightContent], () => {
  if (props.leftContent && props.rightContent) {
    loadSpreadsheets()
  }
}, { immediate: true })

function getCellClass(cell, side) {
  if (!showDiffHighlight.value) return ''

  if (cell.type === 'added' && side === 'right') {
    return 'bg-green-100 dark:bg-green-500/20 text-green-800 dark:text-green-200'
  }
  if (cell.type === 'removed' && side === 'left') {
    return 'bg-red-100 dark:bg-red-500/20 text-red-800 dark:text-red-200'
  }
  if (cell.type === 'changed') {
    if (side === 'left') {
      return 'bg-amber-100 dark:bg-amber-500/20 text-amber-800 dark:text-amber-200'
    }
    return 'bg-blue-100 dark:bg-blue-500/20 text-blue-800 dark:text-blue-200'
  }
  return ''
}

function getRowClass(row, side) {
  if (!showDiffHighlight.value) return ''

  if (row.type === 'added' && side === 'right') {
    return 'bg-green-50 dark:bg-green-500/10'
  }
  if (row.type === 'removed' && side === 'left') {
    return 'bg-red-50 dark:bg-red-500/10'
  }
  if (row.type === 'added' && side === 'left') {
    return 'bg-surface-100 dark:bg-surface-700/50'
  }
  if (row.type === 'removed' && side === 'right') {
    return 'bg-surface-100 dark:bg-surface-700/50'
  }
  return ''
}
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Toolbar -->
    <div class="flex items-center justify-between px-4 py-2 bg-surface-100 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-4 min-w-0">
        <!-- Sheet tabs (union of both versions) -->
        <div v-if="sheetNames.length > 1" class="flex items-center gap-1 p-1 bg-surface-200 dark:bg-surface-600 rounded-lg overflow-x-auto">
          <button
            v-for="name in sheetNames"
            :key="name"
            @click="activeSheetName = name"
            :class="[
              'px-3 py-1 text-xs font-medium rounded transition-colors whitespace-nowrap flex items-center gap-1',
              activeSheetName === name
                ? 'bg-white dark:bg-surface-500 text-surface-900 dark:text-white shadow-sm'
                : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white'
            ]"
          >
            {{ name }}
            <span
              v-if="sheetOnlyOn(name) === 'right'"
              class="material-symbols-rounded text-xs text-green-500"
              :title="$t('spreadsheetCompare.sheetAdded')"
            >add_circle</span>
            <span
              v-else-if="sheetOnlyOn(name) === 'left'"
              class="material-symbols-rounded text-xs text-red-500"
              :title="$t('spreadsheetCompare.sheetRemoved')"
            >do_not_disturb_on</span>
          </button>
        </div>

        <!-- Stats -->
        <div class="flex items-center gap-3 text-xs whitespace-nowrap">
          <span v-if="stats.changed > 0" class="flex items-center gap-1.5 text-blue-600 dark:text-blue-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-blue-500"></span>
            {{ $t('spreadsheetCompare.cellsChanged', { count: stats.changed }) }}
          </span>
          <span v-if="stats.addedRows > 0" class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-green-500"></span>
            +{{ $t('spreadsheetCompare.rowsCount', { count: stats.addedRows }) }}
          </span>
          <span v-if="stats.removedRows > 0" class="flex items-center gap-1.5 text-red-600 dark:text-red-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-red-500"></span>
            -{{ $t('spreadsheetCompare.rowsCount', { count: stats.removedRows }) }}
          </span>
        </div>
      </div>

      <div class="flex items-center gap-4 flex-shrink-0">
        <!-- Changed-rows-only filter -->
        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer select-none">
          <div
            @click="showChangedOnly = !showChangedOnly"
            :class="[
              'relative w-10 h-5 rounded-full transition-colors cursor-pointer',
              showChangedOnly ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <div
              :class="[
                'absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
                showChangedOnly ? 'translate-x-5' : 'translate-x-0.5'
              ]"
            ></div>
          </div>
          <span class="flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">filter_alt</span>
            {{ $t('spreadsheetCompare.changedRowsOnly') }}
          </span>
        </label>

        <!-- Highlight toggle -->
        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer select-none">
          <div
            @click="showDiffHighlight = !showDiffHighlight"
            :class="[
              'relative w-10 h-5 rounded-full transition-colors cursor-pointer',
              showDiffHighlight ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <div
              :class="[
                'absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
                showDiffHighlight ? 'translate-x-5' : 'translate-x-0.5'
              ]"
            ></div>
          </div>
          <span class="flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">palette</span>
            {{ $t('spreadsheetCompare.highlight') }}
          </span>
        </label>

        <!-- Sync scroll toggle -->
        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer select-none">
          <div
            @click="syncScroll = !syncScroll"
            :class="[
              'relative w-10 h-5 rounded-full transition-colors cursor-pointer',
              syncScroll ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <div
              :class="[
                'absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
                syncScroll ? 'translate-x-5' : 'translate-x-0.5'
              ]"
            ></div>
          </div>
          <span class="flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">sync</span>
            {{ $t('spreadsheetCompare.syncScroll') }}
          </span>
        </label>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500 mx-auto mb-3"></div>
        <p class="text-surface-500">{{ $t('spreadsheetCompare.parsing') }}</p>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex-1 flex items-center justify-center">
      <div class="text-center max-w-md">
        <span class="material-symbols-rounded text-5xl text-red-500 mb-4">error</span>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">{{ $t('spreadsheetCompare.parsingFailed') }}</h3>
        <p class="text-surface-500">{{ error }}</p>
        <button
          @click="loadSpreadsheets"
          class="mt-4 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
        >
          {{ $t('versionCompare.tryAgain') }}
        </button>
      </div>
    </div>

    <!-- Side by Side Comparison -->
    <div v-else-if="diffData" class="flex-1 flex overflow-hidden">
      <!-- Left Spreadsheet (older) -->
      <div class="flex-1 flex flex-col border-r border-surface-200 dark:border-surface-700 min-w-0">
        <div class="px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-amber-500">history</span>
              <span class="font-medium text-amber-700 dark:text-amber-300">
                {{ $t('versionCompare.olderVersion', { number: leftVersion?.version_number }) }}
              </span>
            </div>
            <span class="text-xs text-surface-500">
              {{ $t('spreadsheetCompare.dimensions', { rows: leftActiveSheet.rowCount, cols: leftActiveSheet.colCount }) }}
            </span>
          </div>
        </div>

        <div
          ref="leftScrollRef"
          @scroll="onLeftScroll"
          class="flex-1 overflow-auto bg-white dark:bg-surface-800"
        >
          <table class="spreadsheet-table">
            <thead>
              <tr>
                <th class="row-num">#</th>
                <th v-for="(header, hIdx) in diffData.headers" :key="hIdx">
                  {{ header || String.fromCharCode(65 + hIdx) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in visibleRows"
                :key="'left-' + row.rowIndex"
                :class="getRowClass(row, 'left')"
              >
                <td class="row-num">
                  <span v-if="row.type === 'removed'" class="text-red-500 font-medium">-</span>
                  <span v-else-if="row.type === 'added'" class="text-surface-400">+</span>
                  <span v-else>{{ row.rowIndex + 1 }}</span>
                </td>
                <td
                  v-for="(cell, cIdx) in row.cells"
                  :key="cIdx"
                  :class="getCellClass(cell, 'left')"
                >
                  <template v-if="row.type === 'added'">
                    <span class="text-surface-400 text-xs italic">-</span>
                  </template>
                  <template v-else>
                    {{ cell.leftVal }}
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Right Spreadsheet (newer) -->
      <div class="flex-1 flex flex-col min-w-0">
        <div class="px-4 py-2 bg-green-50 dark:bg-green-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-green-500">update</span>
              <span class="font-medium text-green-700 dark:text-green-300">
                {{ $t('versionCompare.newerVersion', { number: rightVersion?.version_number }) }}
              </span>
            </div>
            <span class="text-xs text-surface-500">
              {{ $t('spreadsheetCompare.dimensions', { rows: rightActiveSheet.rowCount, cols: rightActiveSheet.colCount }) }}
            </span>
          </div>
        </div>

        <div
          ref="rightScrollRef"
          @scroll="onRightScroll"
          class="flex-1 overflow-auto bg-white dark:bg-surface-800"
        >
          <table class="spreadsheet-table">
            <thead>
              <tr>
                <th class="row-num">#</th>
                <th v-for="(header, hIdx) in diffData.headers" :key="hIdx">
                  {{ header || String.fromCharCode(65 + hIdx) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in visibleRows"
                :key="'right-' + row.rowIndex"
                :class="getRowClass(row, 'right')"
              >
                <td class="row-num">
                  <span v-if="row.type === 'added'" class="text-green-500 font-medium">+</span>
                  <span v-else-if="row.type === 'removed'" class="text-surface-400">-</span>
                  <span v-else>{{ row.rowIndex + 1 }}</span>
                </td>
                <td
                  v-for="(cell, cIdx) in row.cells"
                  :key="cIdx"
                  :class="getCellClass(cell, 'right')"
                >
                  <template v-if="row.type === 'removed'">
                    <span class="text-surface-400 text-xs italic">-</span>
                  </template>
                  <template v-else>
                    {{ cell.rightVal }}
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.spreadsheet-table {
  width: max-content;
  min-width: 100%;
  border-collapse: collapse;
  font-size: 0.75rem;
}

.spreadsheet-table th,
.spreadsheet-table td {
  border: 1px solid #e5e7eb;
  padding: 0.35rem 0.6rem;
  text-align: left;
  white-space: nowrap;
  min-width: 80px;
}

.spreadsheet-table th {
  background: #f9fafb;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 10;
}

.spreadsheet-table .row-num {
  background: #f3f4f6;
  font-weight: 500;
  text-align: center;
  min-width: 40px;
  width: 40px;
  color: #6b7280;
  position: sticky;
  left: 0;
  z-index: 5;
}

.spreadsheet-table th.row-num {
  z-index: 15;
}

.dark .spreadsheet-table th,
.dark .spreadsheet-table td {
  border-color: #374151;
}

.dark .spreadsheet-table th {
  background: #1f2937;
}

.dark .spreadsheet-table .row-num {
  background: #111827;
  color: #9ca3af;
}
</style>
