<script setup>
import { ref, computed, watch } from 'vue'
import * as XLSX from 'xlsx'
import mammoth from 'mammoth'
import DOMPurify from 'dompurify'

// Renders one version's preview payload ({type, content}) from the
// versions/{id}/preview endpoint: image, pdf, text, spreadsheet and docx
// inline; anything else falls through to the #unsupported slot.
const props = defineProps({
  data: { type: Object, required: true },
})

const MAX_PREVIEW_ROWS = 500

const parsing = ref(false)
const parseError = ref(null)

// Spreadsheet state
const sheets = ref([])
const activeSheet = ref(0)

// Docx state
const docxHtml = ref('')

const type = computed(() => props.data?.type || 'unsupported')

const activeRows = computed(() => {
  const sheet = sheets.value[activeSheet.value]
  if (!sheet) return []
  return sheet.rows.slice(0, MAX_PREVIEW_ROWS)
})

const truncated = computed(() => {
  const sheet = sheets.value[activeSheet.value]
  return sheet ? sheet.rows.length > MAX_PREVIEW_ROWS : false
})

function base64ToArrayBuffer(base64) {
  const base64Data = base64.replace(/^data:[^;]+;base64,/, '')
  const binaryString = atob(base64Data)
  const bytes = new Uint8Array(binaryString.length)
  for (let i = 0; i < binaryString.length; i++) {
    bytes[i] = binaryString.charCodeAt(i)
  }
  return bytes.buffer
}

async function parse() {
  parseError.value = null
  sheets.value = []
  docxHtml.value = ''

  if (type.value !== 'spreadsheet' && type.value !== 'docx') return

  parsing.value = true
  try {
    const buffer = base64ToArrayBuffer(props.data.content)

    if (type.value === 'spreadsheet') {
      const workbook = XLSX.read(buffer, { type: 'array' })
      sheets.value = workbook.SheetNames.map((name) => {
        const json = XLSX.utils.sheet_to_json(workbook.Sheets[name], { header: 1, defval: '' })
        return {
          name,
          rows: json,
          colCount: json.reduce((max, row) => Math.max(max, row?.length || 0), 0),
        }
      })
      activeSheet.value = 0
    } else {
      const result = await mammoth.convertToHtml({ arrayBuffer: buffer })
      docxHtml.value = DOMPurify.sanitize(result.value)
    }
  } catch (e) {
    console.error('Version preview parse failed:', e)
    parseError.value = e.message
  } finally {
    parsing.value = false
  }
}

watch(() => props.data, parse, { immediate: true })
</script>

<template>
  <!-- Image -->
  <img
    v-if="type === 'image'"
    :src="data.content"
    class="max-w-full max-h-[70vh] object-contain rounded-lg"
  />

  <!-- PDF -->
  <iframe
    v-else-if="type === 'pdf'"
    :src="data.content"
    class="w-full h-[70vh] rounded-lg border-0"
  ></iframe>

  <!-- Text -->
  <pre
    v-else-if="type === 'text'"
    class="w-full text-xs whitespace-pre-wrap break-words text-surface-800 dark:text-surface-200 self-start"
  >{{ data.content }}</pre>

  <!-- Office formats being parsed -->
  <div v-else-if="parsing" class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-500"></div>

  <div v-else-if="parseError" class="flex flex-col items-center text-center py-8">
    <span class="material-symbols-rounded text-4xl text-red-400 mb-2">error</span>
    <p class="text-surface-500">{{ parseError }}</p>
  </div>

  <!-- Spreadsheet -->
  <div v-else-if="type === 'spreadsheet' && sheets.length" class="w-full self-start flex flex-col gap-2 min-h-0">
    <div v-if="sheets.length > 1" class="flex items-center gap-1 p-1 bg-surface-100 dark:bg-surface-700 rounded-lg self-start overflow-x-auto max-w-full">
      <button
        v-for="(sheet, idx) in sheets"
        :key="sheet.name"
        @click="activeSheet = idx"
        :class="[
          'px-3 py-1 text-xs font-medium rounded transition-colors whitespace-nowrap',
          activeSheet === idx
            ? 'bg-white dark:bg-surface-500 text-surface-900 dark:text-white shadow-sm'
            : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white'
        ]"
      >
        {{ sheet.name }}
      </button>
    </div>
    <div class="overflow-auto border border-surface-200 dark:border-surface-700 rounded-lg max-h-[62vh]">
      <table class="preview-sheet">
        <tbody>
          <tr v-for="(row, rIdx) in activeRows" :key="rIdx">
            <td class="row-num">{{ rIdx + 1 }}</td>
            <td v-for="cIdx in (sheets[activeSheet]?.colCount || 0)" :key="cIdx">
              {{ row[cIdx - 1] ?? '' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="truncated" class="text-xs text-surface-400">
      {{ $t('versionsPanel.previewTruncated', { count: MAX_PREVIEW_ROWS }) }}
    </p>
  </div>

  <!-- Docx -->
  <div
    v-else-if="type === 'docx' && docxHtml"
    class="w-full self-start prose prose-sm dark:prose-invert max-w-none docx-preview"
    v-html="docxHtml"
  ></div>

  <!-- Everything else -->
  <slot v-else name="unsupported"></slot>
</template>

<style scoped>
.preview-sheet {
  width: max-content;
  min-width: 100%;
  border-collapse: collapse;
  font-size: 0.75rem;
}

.preview-sheet td {
  border: 1px solid #e5e7eb;
  padding: 0.3rem 0.55rem;
  white-space: nowrap;
  min-width: 70px;
  color: #374151;
}

.preview-sheet .row-num {
  background: #f3f4f6;
  font-weight: 500;
  text-align: center;
  min-width: 40px;
  width: 40px;
  color: #6b7280;
  position: sticky;
  left: 0;
}

.dark .preview-sheet td {
  border-color: #374151;
  color: #d1d5db;
}

.dark .preview-sheet .row-num {
  background: #111827;
  color: #9ca3af;
}

.docx-preview :deep(img) {
  max-width: 100%;
  height: auto;
}
</style>
