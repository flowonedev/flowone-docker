<template>
  <div class="flex flex-col h-full">
    <!-- Header -->
    <div class="flex items-center justify-between px-3 py-2 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-1.5">
        <span class="material-symbols-rounded text-sm text-primary-500">code</span>
        <h3 class="text-[11px] font-semibold text-surface-700 dark:text-surface-300 uppercase tracking-wider">
          CSS Inspect
        </h3>
      </div>
      <div class="flex items-center gap-1">
        <button
          v-if="canExportHtml"
          @click="exportHtml"
          class="p-1 rounded-lg transition-colors"
          :class="htmlFeedback
            ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
            : 'text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'"
          title="Download as HTML file"
        >
          <span class="material-symbols-rounded text-sm">{{ htmlFeedback ? 'check' : 'download' }}</span>
        </button>
        <button
          v-if="flatCss"
          @click="copyAll"
          class="p-1 rounded-lg transition-colors"
          :class="copyFeedback
            ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
            : 'text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'"
          title="Copy CSS"
        >
          <span class="material-symbols-rounded text-sm">{{ copyFeedback ? 'check' : 'content_copy' }}</span>
        </button>
      </div>
    </div>

    <!-- Tab bar: Element | Export -->
    <div v-if="hasSelection" class="flex border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <button
        @click="viewMode = 'element'"
        class="flex-1 py-1.5 text-[10px] font-medium uppercase tracking-wider transition-colors"
        :class="viewMode === 'element'
          ? 'text-primary-600 dark:text-primary-400 border-b-2 border-primary-500'
          : 'text-surface-400 hover:text-surface-600'"
      >Element</button>
      <button
        @click="viewMode = 'export'"
        class="flex-1 py-1.5 text-[10px] font-medium uppercase tracking-wider transition-colors"
        :class="viewMode === 'export'
          ? 'text-primary-600 dark:text-primary-400 border-b-2 border-primary-500'
          : 'text-surface-400 hover:text-surface-600'"
      >Export</button>
    </div>

    <!-- No selection -->
    <div v-if="!hasSelection" class="flex-1 flex items-center justify-center p-6">
      <p class="text-xs text-surface-400 text-center">
        Select an element to inspect its CSS properties
      </p>
    </div>

    <!-- CSS output -->
    <div v-else class="flex-1 overflow-y-auto custom-scrollbar min-h-0">
      <div v-if="!flatCss" class="p-4">
        <p class="text-xs text-surface-400 italic">No CSS properties to show</p>
      </div>

      <pre v-else class="px-4 py-3 text-[11px] leading-[1.8] font-mono whitespace-pre-wrap break-all select-text text-surface-600 dark:text-surface-300"><template v-for="(line, idx) in cssLines" :key="idx"><span v-html="highlightLine(line)"></span>
</template></pre>

      <!-- Linked globals summary (element mode only) -->
      <div v-if="viewMode === 'element' && linkedGlobals.length" class="border-t border-surface-200 dark:border-surface-700 px-3 py-2.5">
        <p class="text-[9px] font-semibold text-surface-500 uppercase tracking-wider mb-2">Linked Globals</p>
        <div class="space-y-1.5">
          <div
            v-for="g in linkedGlobals"
            :key="g.key"
            class="flex items-center gap-2"
          >
            <span
              v-if="g.type === 'color'"
              class="w-3.5 h-3.5 rounded border border-surface-300 dark:border-surface-600 flex-shrink-0"
              :style="{ backgroundColor: g.value }"
            />
            <span v-else-if="g.type === 'gradient'" class="material-symbols-rounded text-xs text-amber-500 flex-shrink-0">gradient</span>
            <span v-else class="material-symbols-rounded text-xs text-sky-500 flex-shrink-0">text_fields</span>
            <span class="text-[10px] text-surface-600 dark:text-surface-400">
              <span class="font-medium">{{ g.name }}</span>
              <span class="text-surface-400 ml-1">({{ g.key }})</span>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useMoodBoardGlobalStylesStore } from '@/addons/moodboards/stores/moodBoardGlobalStyles'
import { generateItemCss, generateFullCss, generateGroupCss, generateRootBlock, generateTextStyleClasses, wrapInSelector, generateCssClassBlocks, toCssName } from '@/addons/moodboards/utils/cssInspectUtils'
import { buildReadableGlobalsMap } from '@/addons/moodboards/utils/globalStyleResolver'
import { buildHtmlExport, downloadHtmlFile } from '@/addons/moodboards/utils/htmlExportBuilder'

const store = useMoodBoardsStore()
const gsStore = useMoodBoardGlobalStylesStore()

const viewMode = ref('element')
const copyFeedback = ref(false)

const selectedItems = computed(() => store.selectedItems || [])
const hasSelection = computed(() => selectedItems.value.length > 0)

const item = computed(() => {
  if (selectedItems.value.length === 1) return selectedItems.value[0]
  return null
})

function isGroupType(it) {
  return it?.type === 'group' || it?.type === 'repeat_grid'
}

const isGroup = computed(() => isGroupType(item.value))

function getGroupChildren(it) {
  if (!it || !isGroupType(it)) return []
  return (store.currentBoard?.items || []).filter(i => i.parent_id === it.id)
}

const groupChildren = computed(() => getGroupChildren(item.value))

const cssOptions = computed(() => ({
  globalColors: gsStore.globalColors,
  globalGradients: gsStore.globalGradients,
  globalTextStyles: gsStore.globalTextStyles,
  globalCssClasses: gsStore.globalCssClasses,
}))

function generateSingleElementCss(it) {
  if (isGroupType(it)) {
    return generateGroupCss(it, getGroupChildren(it), { ...cssOptions.value, includeGlobals: false })
  }
  const sections = generateItemCss(it, cssOptions.value)
  const rawCss = sections.map(s => s.css).join('\n')
  const wrapped = wrapInSelector(it, rawCss)
  const classBlocks = generateCssClassBlocks(it, cssOptions.value.globalCssClasses)
  return [wrapped, classBlocks].filter(Boolean).join('\n\n')
}

function generateSingleExportCss(it) {
  if (isGroupType(it)) {
    return generateGroupCss(it, getGroupChildren(it), { ...cssOptions.value, includeGlobals: true })
  }
  return generateFullCss(it, cssOptions.value)
}

const elementCss = computed(() => {
  if (!hasSelection.value) return ''
  if (selectedItems.value.length === 1) return generateSingleElementCss(selectedItems.value[0])
  return selectedItems.value
    .map(it => generateSingleElementCss(it))
    .filter(Boolean)
    .join('\n\n')
})

const exportCss = computed(() => {
  if (!hasSelection.value) return ''
  if (selectedItems.value.length === 1) return generateSingleExportCss(selectedItems.value[0])
  const parts = []
  const root = generateRootBlock(cssOptions.value.globalColors, cssOptions.value.globalGradients)
  if (root) parts.push(root)
  const textClasses = generateTextStyleClasses(cssOptions.value.globalTextStyles)
  if (textClasses) parts.push(textClasses)
  for (const it of selectedItems.value) {
    parts.push(generateSingleElementCss(it))
  }
  return parts.filter(Boolean).join('\n\n')
})

const flatCss = computed(() => viewMode.value === 'export' ? exportCss.value : elementCss.value)
const cssLines = computed(() => flatCss.value ? flatCss.value.split('\n') : [])

const linkedGlobals = computed(() => {
  if (!hasSelection.value) return []
  const allItems = []
  for (const it of selectedItems.value) {
    allItems.push(it)
    if (isGroupType(it)) allItems.push(...getGroupChildren(it))
  }
  const merged = {}
  for (const itm of allItems) {
    const map = buildReadableGlobalsMap(itm, gsStore.globalColors, gsStore.globalTextStyles, gsStore.globalGradients)
    Object.assign(merged, map)
  }
  return Object.entries(merged).map(([key, info]) => ({ key, ...info }))
})

const isDark = computed(() => document.documentElement.classList.contains('dark'))

const T = computed(() => isDark.value ? {
  selector: 'color:rgb(208,135,112);font-weight:600',
  property: 'color:rgb(143,188,187)',
  variable: 'color:rgb(180,142,173)',
  number:   'color:rgb(163,190,140)',
  unit:     'color:rgb(163,190,140);opacity:0.8',
  string:   'color:rgb(163,190,140)',
  func:     'color:rgb(136,192,208)',
  colorTok: 'color:rgb(208,135,112)',
  comment:  'color:rgb(107,114,128);font-style:italic',
  brace:    'color:rgb(107,114,128)',
  punct:    'color:rgb(107,114,128)',
} : {
  selector: 'color:rgb(208,135,112);font-weight:600',
  property: 'color:rgb(94,129,172)',
  variable: 'color:rgb(180,142,173)',
  number:   'color:rgb(163,190,140)',
  unit:     'color:rgb(163,190,140);opacity:0.8',
  string:   'color:rgb(163,190,140)',
  func:     'color:rgb(136,192,208)',
  colorTok: 'color:rgb(208,135,112)',
  comment:  'color:rgb(156,163,175);font-style:italic',
  brace:    'color:rgb(156,163,175)',
  punct:    'color:rgb(156,163,175)',
})

function s(token) { return T.value[token] }

function esc(str) {
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function highlightLine(line) {
  const t = line.trim()
  if (t.startsWith('/*')) return `<span style="${s('comment')}">${esc(line)}</span>`
  if (!t) return ''
  if (t === '}') return `<span style="${s('brace')}">${esc(line)}</span>`

  if (t.endsWith('{')) {
    return line.replace(/^(\s*)(.*?)(\s*\{)$/, (_, ws, sel, br) =>
      `${esc(ws)}<span style="${s('selector')}">${esc(sel)}</span><span style="${s('brace')}">${esc(br)}</span>`)
  }

  if (t.startsWith('--')) {
    return line.replace(/^(\s*)(--[\w-]+)(\s*:\s*)(.+?)(;?)$/, (_, ws, prop, colon, val, semi) =>
      `${esc(ws)}<span style="${s('variable')}">${esc(prop)}</span><span style="${s('punct')}">${esc(colon)}</span>${highlightValue(val)}<span style="${s('punct')}">${esc(semi)}</span>`)
  }

  const propMatch = line.match(/^(\s*)([\w-]+)(\s*:\s*)(.+?)(;?)$/)
  if (propMatch) {
    const [, ws, prop, colon, val, semi] = propMatch
    return `${esc(ws)}<span style="${s('property')}">${esc(prop)}</span><span style="${s('punct')}">${esc(colon)}</span>${highlightValue(val)}<span style="${s('punct')}">${esc(semi)}</span>`
  }

  return esc(line)
}

function highlightValue(val) {
  const tokens = []
  const re = /(var\(--[\w-]+\))|(#[0-9a-fA-F]{3,8})\b|('[^']*')|(\b\w[\w-]*)\(|(\b\d+\.?\d*)(px|em|rem|%|deg|s|ms|vw|vh)\b|(?<![#\w])(\b\d+\.?\d*)\b(?![\w#])/g
  let last = 0
  let m
  while ((m = re.exec(val)) !== null) {
    if (m.index > last) tokens.push({ type: 'plain', text: val.slice(last, m.index) })
    if (m[1]) {
      const inner = m[1]
      const varName = inner.match(/var\((--[\w-]+)\)/)?.[1] || ''
      tokens.push({ type: 'func', text: 'var(' })
      tokens.push({ type: 'variable', text: varName })
      tokens.push({ type: 'func', text: ')' })
    } else if (m[2]) {
      tokens.push({ type: 'color', text: m[2] })
    } else if (m[3]) {
      tokens.push({ type: 'string', text: m[3] })
    } else if (m[4]) {
      tokens.push({ type: 'func', text: m[4] })
      tokens.push({ type: 'plain', text: '(' })
    } else if (m[5]) {
      tokens.push({ type: 'number', text: m[5] })
      tokens.push({ type: 'unit', text: m[6] })
    } else if (m[7]) {
      tokens.push({ type: 'number', text: m[7] })
    }
    last = m.index + m[0].length
  }
  if (last < val.length) tokens.push({ type: 'plain', text: val.slice(last) })

  return tokens.map(t => {
    const e = esc(t.text)
    if (t.type === 'plain') return e
    if (t.type === 'color') return `<span style="${s('colorTok')};border-bottom:2px solid ${t.text}">${e}</span>`
    return `<span style="${s(t.type)}">${e}</span>`
  }).join('')
}

async function copyAll() {
  await navigator.clipboard.writeText(flatCss.value)
  copyFeedback.value = true
  setTimeout(() => { copyFeedback.value = false }, 1500)
}

// ── HTML Export ──

const htmlFeedback = ref(false)

const canExportHtml = computed(() => {
  if (!hasSelection.value) return false
  const it = item.value
  if (!it) return false
  return isGroupType(it) || it.type === 'frame' || it.type === 'slide'
})

function exportHtml() {
  const it = item.value
  if (!it) return

  const allItems = store.currentBoard?.items || []
  const boardName = store.currentBoard?.name || 'export'

  const html = buildHtmlExport(it, allItems, {
    ...cssOptions.value,
    boardName,
  })

  const filename = `${toCssName(it.title || boardName)}.html`
  downloadHtmlFile(html, filename)

  htmlFeedback.value = true
  setTimeout(() => { htmlFeedback.value = false }, 1500)
}
</script>

