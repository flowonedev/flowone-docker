<script setup>
import { ref, computed, watch, nextTick, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import * as Diff from 'diff'
import { usePdfRenderer } from '@/composables/usePdfRenderer'

const props = defineProps({
  leftContent: Object,
  rightContent: Object,
  leftVersion: Object,
  rightVersion: Object,
  // 'side-by-side' renders pages in sync; 'text' diffs the extracted text
  mode: { type: String, default: 'side-by-side' }
})

const { t } = useI18n()

const leftPdf = usePdfRenderer()
const rightPdf = usePdfRenderer()

const loading = ref(true)
const error = ref(null)
const currentPage = ref(1)
const leftCanvasRef = ref(null)
const rightCanvasRef = ref(null)
const leftPaneRef = ref(null)
const rightPaneRef = ref(null)

// Text diff state
const textDiffLines = ref([])
const textStats = ref({ added: 0, removed: 0 })
const textExtracted = ref(false)
const extractingText = ref(false)

const totalPages = computed(() => Math.max(leftPdf.pageCount.value, rightPdf.pageCount.value))

async function dataUrlToBytes(dataUrl) {
  // fetch() handles both data: URLs and regular URLs
  const res = await fetch(dataUrl)
  return await res.arrayBuffer()
}

async function loadDocuments() {
  loading.value = true
  error.value = null
  textExtracted.value = false
  textDiffLines.value = []

  try {
    const [leftBytes, rightBytes] = await Promise.all([
      dataUrlToBytes(props.leftContent.content),
      dataUrlToBytes(props.rightContent.content),
    ])
    await Promise.all([
      leftPdf.loadPdfFromData(leftBytes),
      rightPdf.loadPdfFromData(rightBytes),
    ])
    if (leftPdf.error.value || rightPdf.error.value) {
      throw new Error(leftPdf.error.value || rightPdf.error.value)
    }
    currentPage.value = 1
    await nextTick()
    await renderCurrentPages()
    if (props.mode === 'text') {
      await buildTextDiff()
    }
  } catch (e) {
    console.error('PDF compare load failed:', e)
    error.value = e.message || t('pdfCompare.loadFailed')
  } finally {
    loading.value = false
  }
}

async function renderCurrentPages() {
  const page = currentPage.value
  const tasks = []
  if (leftCanvasRef.value && page <= leftPdf.pageCount.value) {
    const width = (leftPaneRef.value?.clientWidth || 600) - 32
    tasks.push(leftPdf.renderPage(page, leftCanvasRef.value, width))
  }
  if (rightCanvasRef.value && page <= rightPdf.pageCount.value) {
    const width = (rightPaneRef.value?.clientWidth || 600) - 32
    tasks.push(rightPdf.renderPage(page, rightCanvasRef.value, width))
  }
  await Promise.all(tasks)
}

function goToPage(delta) {
  const next = currentPage.value + delta
  if (next < 1 || next > totalPages.value) return
  currentPage.value = next
}

watch(currentPage, async () => {
  await nextTick()
  await renderCurrentPages()
})

// ── Text diff ──

async function extractText(renderer) {
  const doc = renderer.pdfDoc.value
  if (!doc) return ''
  const pages = []
  for (let i = 1; i <= doc.numPages; i++) {
    const page = await doc.getPage(i)
    const content = await page.getTextContent()
    // Group items into lines by their vertical position
    const lines = []
    let lastY = null
    let current = []
    content.items.forEach((item) => {
      const y = Math.round(item.transform[5])
      if (lastY !== null && Math.abs(y - lastY) > 2) {
        lines.push(current.join(' '))
        current = []
      }
      if (item.str.trim() !== '') current.push(item.str)
      lastY = y
    })
    if (current.length) lines.push(current.join(' '))
    pages.push(`--- ${t('pdfCompare.pageMarker', { number: i })} ---\n` + lines.join('\n'))
  }
  return pages.join('\n\n')
}

async function buildTextDiff() {
  if (textExtracted.value || extractingText.value) return
  extractingText.value = true
  try {
    const [leftText, rightText] = await Promise.all([
      extractText(leftPdf),
      extractText(rightPdf),
    ])

    const changes = Diff.diffLines(leftText, rightText)
    const lines = []
    let added = 0
    let removed = 0

    changes.forEach((part) => {
      const partLines = part.value.split('\n')
      if (partLines[partLines.length - 1] === '') partLines.pop()
      partLines.forEach((line) => {
        const type = part.added ? 'added' : part.removed ? 'removed' : 'unchanged'
        if (type === 'added') added++
        if (type === 'removed') removed++
        lines.push({ type, content: line })
      })
    })

    textDiffLines.value = lines
    textStats.value = { added, removed }
    textExtracted.value = true
  } finally {
    extractingText.value = false
  }
}

watch(() => props.mode, async (mode) => {
  if (mode === 'text' && !loading.value && !error.value) {
    await buildTextDiff()
  } else if (mode === 'side-by-side' && !loading.value && !error.value) {
    await nextTick()
    await renderCurrentPages()
  }
})

watch([() => props.leftContent, () => props.rightContent], () => {
  if (props.leftContent?.content && props.rightContent?.content) {
    loadDocuments()
  }
}, { immediate: true })

onUnmounted(() => {
  leftPdf.destroy()
  rightPdf.destroy()
})
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500 mx-auto mb-3"></div>
        <p class="text-surface-500">{{ $t('pdfCompare.loading') }}</p>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex-1 flex items-center justify-center">
      <div class="text-center max-w-md">
        <span class="material-symbols-rounded text-5xl text-red-500 mb-4">error</span>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">{{ $t('pdfCompare.loadFailed') }}</h3>
        <p class="text-surface-500">{{ error }}</p>
        <button
          @click="loadDocuments"
          class="mt-4 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
        >
          {{ $t('versionCompare.tryAgain') }}
        </button>
      </div>
    </div>

    <!-- Synced page view -->
    <template v-else-if="mode === 'side-by-side'">
      <!-- Page navigation -->
      <div class="flex items-center justify-center gap-3 px-4 py-2 bg-surface-100 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
        <button
          @click="goToPage(-1)"
          :disabled="currentPage <= 1"
          class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-40 disabled:cursor-not-allowed"
          :title="$t('pdfCompare.prevPage')"
        >
          <span class="material-symbols-rounded text-surface-600 dark:text-surface-400">chevron_left</span>
        </button>
        <span class="text-sm text-surface-600 dark:text-surface-400 select-none min-w-[140px] text-center">
          {{ $t('pdfCompare.pageOf', { current: currentPage, total: totalPages }) }}
        </span>
        <button
          @click="goToPage(1)"
          :disabled="currentPage >= totalPages"
          class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-40 disabled:cursor-not-allowed"
          :title="$t('pdfCompare.nextPage')"
        >
          <span class="material-symbols-rounded text-surface-600 dark:text-surface-400">chevron_right</span>
        </button>
      </div>

      <div class="flex-1 flex overflow-hidden">
        <!-- Left PDF (older) -->
        <div class="flex-1 flex flex-col border-r border-surface-200 dark:border-surface-700 min-w-0">
          <div class="px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2 text-sm">
                <span class="material-symbols-rounded text-amber-500">history</span>
                <span class="font-medium text-amber-700 dark:text-amber-300">
                  {{ $t('versionCompare.olderVersion', { number: leftVersion?.version_number }) }}
                </span>
              </div>
              <span class="text-xs text-surface-500">{{ $t('pdfCompare.pagesCount', { count: leftPdf.pageCount.value }) }}</span>
            </div>
          </div>
          <div ref="leftPaneRef" class="flex-1 overflow-auto bg-surface-100 dark:bg-surface-800 p-4">
            <canvas v-show="currentPage <= leftPdf.pageCount.value" ref="leftCanvasRef" class="shadow-lg mx-auto"></canvas>
            <p v-if="currentPage > leftPdf.pageCount.value" class="text-center text-sm text-surface-400 mt-8">
              {{ $t('pdfCompare.noSuchPage') }}
            </p>
          </div>
        </div>

        <!-- Right PDF (newer) -->
        <div class="flex-1 flex flex-col min-w-0">
          <div class="px-4 py-2 bg-green-50 dark:bg-green-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2 text-sm">
                <span class="material-symbols-rounded text-green-500">update</span>
                <span class="font-medium text-green-700 dark:text-green-300">
                  {{ $t('versionCompare.newerVersion', { number: rightVersion?.version_number }) }}
                </span>
              </div>
              <span class="text-xs text-surface-500">{{ $t('pdfCompare.pagesCount', { count: rightPdf.pageCount.value }) }}</span>
            </div>
          </div>
          <div ref="rightPaneRef" class="flex-1 overflow-auto bg-surface-100 dark:bg-surface-800 p-4">
            <canvas v-show="currentPage <= rightPdf.pageCount.value" ref="rightCanvasRef" class="shadow-lg mx-auto"></canvas>
            <p v-if="currentPage > rightPdf.pageCount.value" class="text-center text-sm text-surface-400 mt-8">
              {{ $t('pdfCompare.noSuchPage') }}
            </p>
          </div>
        </div>
      </div>
    </template>

    <!-- Extracted text diff -->
    <template v-else>
      <div v-if="extractingText" class="flex-1 flex items-center justify-center">
        <div class="text-center">
          <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500 mx-auto mb-3"></div>
          <p class="text-surface-500">{{ $t('pdfCompare.extractingText') }}</p>
        </div>
      </div>
      <template v-else>
        <div class="flex items-center gap-4 px-4 py-2 bg-surface-100 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700 flex-shrink-0 text-xs">
          <span class="flex items-center gap-1 text-green-600 dark:text-green-400">
            <span class="w-2 h-2 rounded bg-green-500"></span>
            +{{ textStats.added }} {{ $t('textDiffCompare.unitLines') }}
          </span>
          <span class="flex items-center gap-1 text-red-600 dark:text-red-400">
            <span class="w-2 h-2 rounded bg-red-500"></span>
            -{{ textStats.removed }} {{ $t('textDiffCompare.unitLines') }}
          </span>
          <span class="text-surface-400">{{ $t('pdfCompare.textDiffHint') }}</span>
        </div>
        <div class="flex-1 overflow-auto bg-surface-50 dark:bg-surface-900 font-mono text-sm">
          <table class="w-full">
            <tbody>
              <tr
                v-for="(line, idx) in textDiffLines"
                :key="idx"
                :class="{
                  'bg-green-50 dark:bg-green-500/10': line.type === 'added',
                  'bg-red-50 dark:bg-red-500/10': line.type === 'removed'
                }"
              >
                <td class="w-6 px-1 py-0.5 text-center select-none">
                  <span v-if="line.type === 'added'" class="text-green-600 dark:text-green-400 font-bold">+</span>
                  <span v-else-if="line.type === 'removed'" class="text-red-600 dark:text-red-400 font-bold">-</span>
                </td>
                <td
                  :class="[
                    'px-3 py-0.5 whitespace-pre-wrap break-words',
                    line.type === 'added' ? 'text-green-700 dark:text-green-300' : '',
                    line.type === 'removed' ? 'text-red-700 dark:text-red-300' : '',
                    line.type === 'unchanged' ? 'text-surface-700 dark:text-surface-300' : ''
                  ]"
                >{{ line.content }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>
