<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import * as Diff from 'diff'

const props = defineProps({
  leftContent: Object,
  rightContent: Object,
  leftVersion: Object,
  rightVersion: Object,
  mode: { type: String, default: 'diff' }
})

const { t } = useI18n()

const diffResult = ref([])
const stats = ref({ added: 0, removed: 0, unchanged: 0 })
const diffGranularity = ref('line') // 'line', 'word', 'char'
const hideUnchanged = ref(false)
const currentChange = ref(0) // 1-based index into change blocks; 0 = none focused
const rootRef = ref(null)

const CONTEXT_LINES = 3
const MIN_COLLAPSE_RUN = CONTEXT_LINES * 2 + 2

const leftText = computed(() => props.leftContent?.content || '')
const rightText = computed(() => props.rightContent?.content || '')
const language = computed(() => props.leftContent?.language || 'plaintext')

const statsUnit = computed(() => {
  switch (diffGranularity.value) {
    case 'word': return t('textDiffCompare.unitWords')
    case 'char': return t('textDiffCompare.unitChars')
    default: return t('textDiffCompare.unitLines')
  }
})

watch([leftText, rightText, diffGranularity], () => {
  computeDiff()
  currentChange.value = 0
}, { immediate: true })

function computeDiff() {
  if (!leftText.value && !rightText.value) return

  let changes
  switch (diffGranularity.value) {
    case 'word':
      changes = Diff.diffWordsWithSpace(leftText.value, rightText.value)
      break
    case 'char':
      changes = Diff.diffChars(leftText.value, rightText.value)
      break
    case 'line':
    default:
      changes = Diff.diffLines(leftText.value, rightText.value)
  }

  let added = 0
  let removed = 0
  let unchanged = 0

  changes.forEach(part => {
    let count
    if (diffGranularity.value === 'line') {
      count = part.value.split('\n').filter(l => l !== '').length
    } else if (diffGranularity.value === 'word') {
      count = part.value.split(/\s+/).filter(w => w !== '').length
    } else {
      count = part.value.length
    }

    if (part.added) added += count
    else if (part.removed) removed += count
    else unchanged += count
  })

  stats.value = { added, removed, unchanged }
  diffResult.value = changes
}

// ── Line structures ──

// Paired rows: one entry drives BOTH panes (left/right cells), so the
// side-by-side view, collapsing and navigation all share row indices.
const pairedLines = computed(() => {
  if (diffGranularity.value !== 'line') return []

  const rows = []
  let leftLineNum = 1
  let rightLineNum = 1
  let pendingRemoved = []

  const flushPending = () => {
    pendingRemoved.forEach(r => {
      rows.push({ left: r, right: { num: null, content: '', type: 'empty' }, changed: true })
    })
    pendingRemoved = []
  }

  diffResult.value.forEach(part => {
    const partLines = part.value.split('\n')
    if (partLines[partLines.length - 1] === '') partLines.pop()

    partLines.forEach(line => {
      if (part.removed) {
        pendingRemoved.push({ num: leftLineNum++, content: line, type: 'removed' })
      } else if (part.added) {
        // Pair added lines against pending removed lines (modified rows)
        if (pendingRemoved.length) {
          const leftCell = pendingRemoved.shift()
          rows.push({ left: leftCell, right: { num: rightLineNum++, content: line, type: 'added' }, changed: true })
        } else {
          rows.push({ left: { num: null, content: '', type: 'empty' }, right: { num: rightLineNum++, content: line, type: 'added' }, changed: true })
        }
      } else {
        flushPending()
        const cell = { content: line, type: 'unchanged' }
        rows.push({
          left: { ...cell, num: leftLineNum++ },
          right: { ...cell, num: rightLineNum++ },
          changed: false
        })
      }
    })
    if (!part.added && !part.removed) flushPending()
  })
  flushPending()

  return rows
})

const unifiedLines = computed(() => {
  if (diffGranularity.value !== 'line') return []

  const lines = []
  let leftLineNum = 1
  let rightLineNum = 1

  diffResult.value.forEach(part => {
    const partLines = part.value.split('\n')
    if (partLines[partLines.length - 1] === '') partLines.pop()

    partLines.forEach(line => {
      if (part.added) {
        lines.push({ type: 'added', leftNum: null, rightNum: rightLineNum++, content: line, changed: true })
      } else if (part.removed) {
        lines.push({ type: 'removed', leftNum: leftLineNum++, rightNum: null, content: line, changed: true })
      } else {
        lines.push({ type: 'unchanged', leftNum: leftLineNum++, rightNum: rightLineNum++, content: line, changed: false })
      }
    })
  })

  return lines
})

// Collapse long unchanged runs into a single "N lines hidden" marker,
// keeping CONTEXT_LINES on each side of every change.
function collapseRows(rows) {
  if (!hideUnchanged.value) return rows.map((row, idx) => ({ row, idx, collapsed: null }))

  const out = []
  let i = 0
  while (i < rows.length) {
    if (rows[i].changed) {
      out.push({ row: rows[i], idx: i, collapsed: null })
      i++
      continue
    }
    // Measure the unchanged run
    let runEnd = i
    while (runEnd < rows.length && !rows[runEnd].changed) runEnd++
    const runLen = runEnd - i
    const keepHead = i === 0 ? 0 : CONTEXT_LINES
    const keepTail = runEnd === rows.length ? 0 : CONTEXT_LINES

    if (runLen >= MIN_COLLAPSE_RUN + (keepHead + keepTail - CONTEXT_LINES * 2)) {
      for (let k = i; k < i + keepHead; k++) out.push({ row: rows[k], idx: k, collapsed: null })
      const hiddenCount = runLen - keepHead - keepTail
      if (hiddenCount > 0) {
        out.push({ row: null, idx: -1, collapsed: hiddenCount })
      }
      for (let k = runEnd - keepTail; k < runEnd; k++) out.push({ row: rows[k], idx: k, collapsed: null })
    } else {
      for (let k = i; k < runEnd; k++) out.push({ row: rows[k], idx: k, collapsed: null })
    }
    i = runEnd
  }
  return out
}

const displayPaired = computed(() => collapseRows(pairedLines.value))
const displayUnified = computed(() => collapseRows(unifiedLines.value))

// ── Change blocks (consecutive changed rows) for navigation ──

const changeBlocks = computed(() => {
  const rows = diffGranularity.value === 'line'
    ? (props.mode === 'side-by-side' ? pairedLines.value : unifiedLines.value)
    : diffResult.value.map(p => ({ changed: !!(p.added || p.removed) }))

  const blocks = []
  rows.forEach((row, idx) => {
    if (row.changed && (idx === 0 || !rows[idx - 1].changed)) {
      blocks.push(idx)
    }
  })
  return blocks
})

watch([changeBlocks, () => props.mode], () => { currentChange.value = 0 })

function goToChange(delta) {
  const total = changeBlocks.value.length
  if (!total) return
  let next = currentChange.value + delta
  if (next < 1) next = total
  if (next > total) next = 1
  currentChange.value = next

  const targetIdx = changeBlocks.value[next - 1]
  const root = rootRef.value
  if (!root) return
  root.querySelectorAll(`[data-row="${targetIdx}"]`).forEach(el => {
    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
  })
}
</script>

<template>
  <div ref="rootRef" class="h-full flex flex-col font-mono text-sm">
    <!-- Stats bar with granularity toggle -->
    <div class="flex items-center justify-between px-4 py-2 bg-surface-100 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-4 text-xs">
        <span class="text-surface-500">{{ language }}</span>
        <span class="flex items-center gap-1 text-green-600 dark:text-green-400">
          <span class="w-2 h-2 rounded bg-green-500"></span>
          +{{ stats.added }} {{ statsUnit }}
        </span>
        <span class="flex items-center gap-1 text-red-600 dark:text-red-400">
          <span class="w-2 h-2 rounded bg-red-500"></span>
          -{{ stats.removed }} {{ statsUnit }}
        </span>
        <span class="text-surface-500">
          {{ stats.unchanged }} {{ $t('textDiffCompare.unchanged') }}
        </span>
      </div>

      <div class="flex items-center gap-3">
        <!-- Change navigation -->
        <div v-if="changeBlocks.length" class="flex items-center gap-1 text-xs text-surface-600 dark:text-surface-400">
          <button
            @click="goToChange(-1)"
            class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
            :title="$t('textDiffCompare.prevChange')"
          >
            <span class="material-symbols-rounded text-base">keyboard_arrow_up</span>
          </button>
          <span class="min-w-[80px] text-center select-none">
            {{ $t('textDiffCompare.changeCounter', { current: currentChange || 1, total: changeBlocks.length }) }}
          </span>
          <button
            @click="goToChange(1)"
            class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
            :title="$t('textDiffCompare.nextChange')"
          >
            <span class="material-symbols-rounded text-base">keyboard_arrow_down</span>
          </button>
        </div>

        <!-- Hide unchanged toggle (line mode) -->
        <label v-if="diffGranularity === 'line'" class="flex items-center gap-2 text-xs text-surface-600 dark:text-surface-400 cursor-pointer select-none">
          <div
            @click="hideUnchanged = !hideUnchanged"
            :class="[
              'relative w-9 h-5 rounded-full transition-colors cursor-pointer',
              hideUnchanged ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', hideUnchanged ? 'translate-x-4' : 'translate-x-0.5']"></div>
          </div>
          {{ $t('textDiffCompare.hideUnchanged') }}
        </label>

        <!-- Granularity Toggle -->
        <div class="flex items-center gap-1 bg-surface-200 dark:bg-surface-600 rounded-lg p-0.5">
          <button
            v-for="g in ['line', 'word', 'char']"
            :key="g"
            @click="diffGranularity = g"
            :class="[
              'px-2.5 py-1 text-xs rounded-md transition-colors',
              diffGranularity === g
                ? 'bg-white dark:bg-surface-700 text-primary-600 dark:text-primary-400 shadow-sm font-medium'
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
          >{{ $t('textDiffCompare.granularity_' + g) }}</button>
        </div>
      </div>
    </div>

    <!-- Inline Diff View for Word/Char granularity -->
    <template v-if="diffGranularity !== 'line'">
      <div class="flex-1 flex flex-col overflow-hidden">
        <div class="flex border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex-1 px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-r border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-amber-500">history</span>
              <span class="font-medium text-amber-700 dark:text-amber-300">
                {{ $t('versionCompare.olderVersion', { number: leftVersion?.version_number }) }}
              </span>
            </div>
          </div>
          <div class="flex-1 px-4 py-2 bg-green-50 dark:bg-green-500/10">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-green-500">update</span>
              <span class="font-medium text-green-700 dark:text-green-300">
                {{ $t('versionCompare.newerVersion', { number: rightVersion?.version_number }) }}
              </span>
            </div>
          </div>
        </div>

        <div class="flex-1 overflow-auto bg-surface-50 dark:bg-surface-900 p-4">
          <div class="whitespace-pre-wrap break-words leading-relaxed">
            <template v-for="(part, idx) in diffResult" :key="idx">
              <span
                v-if="part.added"
                :data-row="idx"
                class="bg-green-200 dark:bg-green-500/30 text-green-800 dark:text-green-200 rounded-sm px-0.5"
              >{{ part.value }}</span>
              <span
                v-else-if="part.removed"
                :data-row="idx"
                class="bg-red-200 dark:bg-red-500/30 text-red-800 dark:text-red-200 line-through rounded-sm px-0.5"
              >{{ part.value }}</span>
              <span
                v-else
                class="text-surface-700 dark:text-surface-300"
              >{{ part.value }}</span>
            </template>
          </div>
        </div>

        <div class="px-4 py-2 bg-surface-100 dark:bg-surface-700/50 border-t border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center gap-4 text-xs text-surface-500">
            <span class="flex items-center gap-1">
              <span class="inline-block px-1.5 py-0.5 bg-green-200 dark:bg-green-500/30 text-green-800 dark:text-green-200 rounded text-[10px]">{{ $t('textDiffCompare.added') }}</span>
              {{ $t('textDiffCompare.newContent') }}
            </span>
            <span class="flex items-center gap-1">
              <span class="inline-block px-1.5 py-0.5 bg-red-200 dark:bg-red-500/30 text-red-800 dark:text-red-200 rounded line-through text-[10px]">{{ $t('textDiffCompare.removed') }}</span>
              {{ $t('textDiffCompare.deletedContent') }}
            </span>
          </div>
        </div>
      </div>
    </template>

    <!-- Line-based views -->
    <template v-else>
      <!-- Side by Side View -->
      <div v-if="mode === 'side-by-side'" class="flex-1 flex overflow-hidden">
        <!-- Left (older) -->
        <div class="flex-1 flex flex-col border-r border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-amber-500">history</span>
              <span class="font-medium text-amber-700 dark:text-amber-300">
                {{ $t('versionCompare.olderVersion', { number: leftVersion?.version_number }) }}
              </span>
            </div>
          </div>
          <div class="flex-1 overflow-auto bg-surface-50 dark:bg-surface-900">
            <table class="w-full">
              <tbody>
                <tr
                  v-for="(entry, dIdx) in displayPaired"
                  :key="'l' + dIdx"
                  :data-row="entry.idx >= 0 ? entry.idx : undefined"
                  :class="entry.collapsed !== null ? '' : {
                    'bg-red-50 dark:bg-red-500/10': entry.row.left.type === 'removed',
                    'bg-surface-100 dark:bg-surface-800/50': entry.row.left.type === 'empty'
                  }"
                >
                  <template v-if="entry.collapsed !== null">
                    <td colspan="2" class="px-3 py-1 text-center text-xs text-surface-400 bg-surface-100 dark:bg-surface-800 select-none">
                      ··· {{ $t('textDiffCompare.hiddenLines', { count: entry.collapsed }) }} ···
                    </td>
                  </template>
                  <template v-else>
                    <td class="w-12 px-2 py-0.5 text-right text-xs text-surface-400 select-none border-r border-surface-200 dark:border-surface-700">
                      {{ entry.row.left.num }}
                    </td>
                    <td
                      :class="[
                        'px-3 py-0.5 whitespace-pre',
                        entry.row.left.type === 'removed' ? 'text-red-700 dark:text-red-300' : 'text-surface-700 dark:text-surface-300'
                      ]"
                    >{{ entry.row.left.content }}</td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Right (newer) -->
        <div class="flex-1 flex flex-col overflow-hidden">
          <div class="px-4 py-2 bg-green-50 dark:bg-green-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-green-500">update</span>
              <span class="font-medium text-green-700 dark:text-green-300">
                {{ $t('versionCompare.newerVersion', { number: rightVersion?.version_number }) }}
              </span>
            </div>
          </div>
          <div class="flex-1 overflow-auto bg-surface-50 dark:bg-surface-900">
            <table class="w-full">
              <tbody>
                <tr
                  v-for="(entry, dIdx) in displayPaired"
                  :key="'r' + dIdx"
                  :data-row="entry.idx >= 0 ? entry.idx : undefined"
                  :class="entry.collapsed !== null ? '' : {
                    'bg-green-50 dark:bg-green-500/10': entry.row.right.type === 'added',
                    'bg-surface-100 dark:bg-surface-800/50': entry.row.right.type === 'empty'
                  }"
                >
                  <template v-if="entry.collapsed !== null">
                    <td colspan="2" class="px-3 py-1 text-center text-xs text-surface-400 bg-surface-100 dark:bg-surface-800 select-none">
                      ··· {{ $t('textDiffCompare.hiddenLines', { count: entry.collapsed }) }} ···
                    </td>
                  </template>
                  <template v-else>
                    <td class="w-12 px-2 py-0.5 text-right text-xs text-surface-400 select-none border-r border-surface-200 dark:border-surface-700">
                      {{ entry.row.right.num }}
                    </td>
                    <td
                      :class="[
                        'px-3 py-0.5 whitespace-pre',
                        entry.row.right.type === 'added' ? 'text-green-700 dark:text-green-300' : 'text-surface-700 dark:text-surface-300'
                      ]"
                    >{{ entry.row.right.content }}</td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Unified Diff View -->
      <div v-else class="flex-1 overflow-auto bg-surface-50 dark:bg-surface-900">
        <table class="w-full">
          <tbody>
            <tr
              v-for="(entry, dIdx) in displayUnified"
              :key="dIdx"
              :data-row="entry.idx >= 0 ? entry.idx : undefined"
              :class="entry.collapsed !== null ? '' : {
                'bg-green-50 dark:bg-green-500/10': entry.row.type === 'added',
                'bg-red-50 dark:bg-red-500/10': entry.row.type === 'removed'
              }"
            >
              <template v-if="entry.collapsed !== null">
                <td colspan="4" class="px-3 py-1 text-center text-xs text-surface-400 bg-surface-100 dark:bg-surface-800 select-none">
                  ··· {{ $t('textDiffCompare.hiddenLines', { count: entry.collapsed }) }} ···
                </td>
              </template>
              <template v-else>
                <td class="w-12 px-2 py-0.5 text-right text-xs text-surface-400 select-none border-r border-surface-200 dark:border-surface-700">
                  {{ entry.row.leftNum }}
                </td>
                <td class="w-12 px-2 py-0.5 text-right text-xs text-surface-400 select-none border-r border-surface-200 dark:border-surface-700">
                  {{ entry.row.rightNum }}
                </td>
                <td class="w-6 px-1 py-0.5 text-center select-none">
                  <span v-if="entry.row.type === 'added'" class="text-green-600 dark:text-green-400 font-bold">+</span>
                  <span v-else-if="entry.row.type === 'removed'" class="text-red-600 dark:text-red-400 font-bold">-</span>
                </td>
                <td
                  :class="[
                    'px-3 py-0.5 whitespace-pre',
                    entry.row.type === 'added' ? 'text-green-700 dark:text-green-300' : '',
                    entry.row.type === 'removed' ? 'text-red-700 dark:text-red-300' : '',
                    entry.row.type === 'unchanged' ? 'text-surface-700 dark:text-surface-300' : ''
                  ]"
                >{{ entry.row.content }}</td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
