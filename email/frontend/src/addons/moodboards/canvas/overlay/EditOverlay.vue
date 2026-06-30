<template>
  <div
    v-if="editingItem"
    class="absolute z-30 pointer-events-auto"
    :style="overlayStyle"
    @click.stop
    @pointerdown.stop
  >
    <!-- Text editor -->
    <div
      v-if="editType === 'text'"
      ref="editorRef"
      class="w-full h-full outline-none overflow-visible"
      contenteditable="true"
      :style="textEditorStyle"
      @blur="onTextBlur"
      @keydown.escape="cancelEdit"
      @mouseup="updateFormatBar"
      @keyup="updateFormatBar"
    />

    <!-- Note editor -->
    <div v-else-if="editType === 'note'" class="w-full h-full p-3 flex flex-col gap-2">
      <input
        ref="editorRef"
        class="font-bold text-sm bg-transparent border-none outline-none w-full"
        :value="editingItem.title || ''"
        placeholder="Title"
        @input="editData.title = $event.target.value"
        @keydown.escape="cancelEdit"
      />
      <textarea
        class="flex-1 bg-transparent border-none outline-none resize-none text-sm"
        :value="editingItem.content || ''"
        placeholder="Content"
        @input="editData.content = $event.target.value"
        @keydown.escape="cancelEdit"
      />
    </div>

    <!-- Shape text -->
    <div v-else-if="editType === 'shape'" class="w-full h-full flex items-center justify-center p-2">
      <textarea
        ref="editorRef"
        class="bg-transparent border-none outline-none resize-none text-center w-full"
        :value="editingItem.content || ''"
        @input="editData.content = $event.target.value"
        @blur="commitEdit"
        @keydown.escape="cancelEdit"
      />
    </div>

    <!-- Link editor -->
    <div v-else-if="editType === 'link'" class="w-full h-full p-3 flex flex-col gap-2">
      <input
        ref="editorRef"
        class="text-sm bg-transparent border-b border-surface-300 outline-none w-full pb-1"
        :value="editingItem.title || ''"
        placeholder="Title"
        @input="editData.title = $event.target.value"
        @keydown.escape="cancelEdit"
      />
      <input
        class="text-xs bg-transparent border-b border-surface-300 outline-none w-full pb-1 text-blue-500"
        :value="editingItem.url || ''"
        placeholder="URL"
        @input="editData.url = $event.target.value"
        @keydown.escape="cancelEdit"
      />
    </div>

    <!-- Todo list editor -->
    <div v-else-if="editType === 'todo_list'" class="w-full h-full p-3 overflow-auto">
      <input
        ref="editorRef"
        class="font-bold text-sm bg-transparent border-none outline-none w-full mb-2"
        :value="editingItem.title || ''"
        placeholder="Title"
        @input="editData.title = $event.target.value"
      />
      <div
        v-for="(todo, idx) in editTodos"
        :key="idx"
        class="flex items-center gap-2 py-1"
      >
        <button
          class="w-4 h-4 rounded border flex-shrink-0"
          :class="todo.done ? 'bg-green-500 border-green-500' : 'border-surface-300'"
          @click="todo.done = !todo.done"
        />
        <input
          class="flex-1 text-sm bg-transparent border-none outline-none"
          v-model="todo.text"
          @keydown.enter="addTodo(idx)"
          @keydown.escape="cancelEdit"
        />
      </div>
    </div>

    <!-- Column title -->
    <div v-else-if="editType === 'column'" class="p-2">
      <input
        ref="editorRef"
        class="text-sm bg-transparent border-none outline-none w-full"
        :value="editingItem.title || ''"
        @input="editData.title = $event.target.value"
        @blur="commitEdit"
        @keydown.escape="cancelEdit"
      />
    </div>

    <!-- Video/YouTube URL -->
    <div v-else-if="editType === 'media'" class="w-full h-full flex items-center justify-center p-4">
      <input
        ref="editorRef"
        class="text-sm bg-white dark:bg-surface-800 border border-surface-300 rounded-lg px-3 py-2
               outline-none w-full max-w-sm"
        :value="editingItem.url || ''"
        placeholder="Enter URL"
        @input="editData.url = $event.target.value"
        @blur="commitEdit"
        @keydown.escape="cancelEdit"
        @keydown.enter="commitEdit"
      />
    </div>

    <!-- Generic fallback -->
    <div v-else class="w-full h-full p-3">
      <input
        ref="editorRef"
        class="text-sm bg-transparent border-none outline-none w-full"
        :value="editingItem.title || editingItem.content || ''"
        @input="editData.title = $event.target.value"
        @blur="commitEdit"
        @keydown.escape="cancelEdit"
      />
    </div>

    <!-- Inline format bar (teleported to body to avoid clipping by the overlay) -->
    <Teleport to="body">
      <MoodInlineFormatBar
        v-if="editType === 'text' && showFormatBar && formatBarPos"
        :style="{ position: 'fixed', top: formatBarPos.top + 'px', left: formatBarPos.left + 'px', zIndex: 999999 }"
        @format-bold="applyCommand('bold')"
        @format-italic="applyCommand('italic')"
        @format-underline="applyCommand('underline')"
        @format-color="applyColor"
        @format-font="applyFont"
        @format-clear="clearFormatting"
      />
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, reactive, watch, nextTick } from 'vue'
import MoodInlineFormatBar from '../../components/MoodInlineFormatBar.vue'
import { loadGoogleFont } from '../../utils/fontLoader.js'

const props = defineProps({
  editingItem: Object,
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
})

const emit = defineEmits(['commit', 'cancel'])

const editorRef = ref(null)
const editData = reactive({})
const editTodos = ref([])
const showFormatBar = ref(false)
const formatBarPos = ref(null)

const editType = computed(() => {
  const item = props.editingItem
  if (!item) return null
  if (item.type === 'text') return 'text'
  if (item.type === 'note') return 'note'
  if (item.type === 'shape') return 'shape'
  if (item.type === 'link') return 'link'
  if (item.type === 'todo_list') return 'todo_list'
  if (item.type === 'column') return 'column'
  if (['video', 'youtube', 'audio'].includes(item.type)) return 'media'
  return 'generic'
})

const overlayStyle = computed(() => {
  if (!props.editingItem) return { display: 'none' }
  const item = props.editingItem
  const scale = item.style_data?.item_scale ?? 1
  const style = {
    left: `${(item.pos_x || 0) * props.zoom + props.panX}px`,
    top: `${(item.pos_y || 0) * props.zoom + props.panY}px`,
    width: `${(item.width || 200) * props.zoom}px`,
    height: `${(item.height || 100) * props.zoom}px`,
  }
  if (scale !== 1) {
    style.transform = `scale(${scale})`
    style.transformOrigin = 'center'
  }
  return style
})

const textEditorStyle = computed(() => {
  const item = props.editingItem
  if (!item) return {}
  const sd = item.style_data || {}
  const textData = sd.text || {}
  const fontSize = getNumeric(sd.font_size ?? sd.fontSize ?? textData.fontSize, 14)
  const padding = getNumeric(sd.text_padding, 12)
  const letterSpacing = getNumeric(sd.letter_spacing, 0)
  const lineHeight = sd.line_height != null
    ? (getNumeric(sd.line_height, 1.4) <= 10 ? getNumeric(sd.line_height, 1.4) : `${getNumeric(sd.line_height, 1.4) * props.zoom}px`)
    : 1.4

  const baseColor = sd.text_color || '#333333'
  const fontFamily = sd.font_family || sd.fontFamily || textData.fontFamily || 'Inter, sans-serif'
  const iconFont = fontFamily === 'Material Symbols Rounded' || fontFamily === 'Material Symbols Outlined'
  const style = {
    fontFamily,
    fontSize: `${fontSize * props.zoom}px`,
    fontWeight: sd.font_weight || sd.fontWeight || textData.fontWeight || '400',
    fontStyle: sd.font_style || textData.fontStyle || 'normal',
    textAlign: String(sd.text_align || sd.textAlign || textData.textAlignHorizontal || textData.textAlign || 'left').toLowerCase(),
    textTransform: sd.text_transform || 'none',
    letterSpacing: `${letterSpacing * props.zoom}px`,
    lineHeight,
    padding: `${padding * props.zoom}px`,
    color: baseColor,
    caretColor: baseColor,
    textRendering: 'geometricPrecision',
    WebkitFontSmoothing: 'antialiased',
    MozOsxFontSmoothing: 'grayscale',
    fontFeatureSettings: "'liga'",
  }

  if (iconFont) {
    style.whiteSpace = 'nowrap'
    style.wordWrap = 'normal'
    style.direction = 'ltr'
    style.lineHeight = 1
    style.letterSpacing = 'normal'
    style.textTransform = 'none'
  }

  if (sd.text_clip_image) {
    style.backgroundImage = `url(${sd.text_clip_image})`
    style.backgroundSize = sd.text_clip_image_size || 'cover'
    style.backgroundPosition = 'center'
    style.WebkitBackgroundClip = 'text'
    style.backgroundClip = 'text'
    style.WebkitTextFillColor = 'transparent'
    style.color = 'transparent'
  } else {
    const gradientCSS = _buildGradientCSS(sd.text_fill_type, sd.text_color, sd.text_fill_gradient)
    if (gradientCSS) {
      style.background = gradientCSS
      style.WebkitBackgroundClip = 'text'
      style.backgroundClip = 'text'
      style.WebkitTextFillColor = 'transparent'
      style.color = 'transparent'
      style.caretColor = sd.text_fill_gradient?.stops?.[0]?.color || baseColor
    }
  }

  const strokeW = getNumeric(sd.text_stroke_width, 0)
  const strokeC = sd.text_stroke_color || 'transparent'
  if (strokeW > 0 && strokeC && strokeC !== 'transparent') {
    style.WebkitTextStroke = `${strokeW}px ${strokeC}`
    style.paintOrder = 'stroke fill'
  }

  const shadows = []
  if (sd.text_shadow_enabled) {
    const tsx = getNumeric(sd.text_shadow_x, 1)
    const tsy = getNumeric(sd.text_shadow_y, 2)
    const tsb = getNumeric(sd.text_shadow_blur, 4)
    const tsc = sd.text_shadow_color || '#000000'
    const tso = (sd.text_shadow_opacity ?? 40) / 100
    shadows.push(`${tsx}px ${tsy}px ${tsb}px ${_hexToRgba(tsc, tso)}`)
  }
  if (shadows.length) style.textShadow = shadows.join(', ')

  return style
})

function _buildGradientCSS(fillType, fillColor, gradient) {
  if (!fillType || fillType === 'solid') return null
  const stops = gradient?.stops
  if (!stops || stops.length < 2) return null
  const stopsStr = [...stops].sort((a, b) => a.position - b.position)
    .map(s => `${s.color} ${s.position}%`).join(', ')
  if (fillType === 'radial') return `radial-gradient(circle, ${stopsStr})`
  return `linear-gradient(${gradient?.angle ?? gradient?.gradientAngle ?? 180}deg, ${stopsStr})`
}

function _hexToRgba(hex, alpha) {
  hex = (hex || '#000000').replace('#', '')
  if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]
  const r = parseInt(hex.substring(0, 2), 16) || 0
  const g = parseInt(hex.substring(2, 4), 16) || 0
  const b = parseInt(hex.substring(4, 6), 16) || 0
  return `rgba(${r},${g},${b},${alpha})`
}

function getNumeric(value, fallback) {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  const parsed = parseFloat(value)
  return Number.isFinite(parsed) ? parsed : fallback
}

watch(() => props.editingItem, async (item) => {
  if (!item) return
  Object.keys(editData).forEach(k => delete editData[k])
  if (item.type === 'todo_list') {
    const sd = item.style_data || {}
    editTodos.value = (sd.todos || []).map(t => ({ ...t }))
  }
  await nextTick()
  if (item.type === 'text' && editorRef.value) {
    const content = item.content || ''
    if (/<[a-z][\s\S]*>/i.test(content)) {
      editorRef.value.innerHTML = content
    } else {
      editorRef.value.textContent = content
    }
    editorRef.value.focus()
    const sel = window.getSelection()
    if (sel && editorRef.value.childNodes.length) {
      sel.selectAllChildren(editorRef.value)
      sel.collapseToEnd()
    }
  } else {
    editorRef.value?.focus()
  }
}, { immediate: true })

function updateFormatBar() {
  requestAnimationFrame(() => {
    const sel = window.getSelection()
    if (!sel || sel.isCollapsed || !sel.rangeCount) {
      showFormatBar.value = false
      return
    }
    const range = sel.getRangeAt(0)
    if (!editorRef.value?.contains(range.commonAncestorContainer)) {
      showFormatBar.value = false
      return
    }
    const rect = range.getBoundingClientRect()
    if (rect.width < 2) {
      showFormatBar.value = false
      return
    }
    formatBarPos.value = {
      top: rect.top - 44,
      left: rect.left + rect.width / 2,
    }
    showFormatBar.value = true
  })
}

function applyCommand(cmd) {
  document.execCommand(cmd, false, null)
  editorRef.value?.focus()
}

function applyColor(color) {
  document.execCommand('foreColor', false, color)
  editorRef.value?.focus()
}

function applyFont(fontFamily) {
  loadGoogleFont(fontFamily)
  document.execCommand('fontName', false, fontFamily)
  editorRef.value?.focus()
}

function clearFormatting() {
  const el = editorRef.value
  if (!el) return
  const sel = window.getSelection()
  if (sel && !sel.isCollapsed && sel.rangeCount) {
    document.execCommand('removeFormat', false, null)
  } else {
    const plain = el.innerText || el.textContent || ''
    el.textContent = plain
  }
  el.focus()
  showFormatBar.value = false
}

function onTextBlur(e) {
  if (e.relatedTarget?.closest?.('.inline-format-bar')) return
  const formatBar = document.querySelector('.inline-format-bar')
  if (formatBar?.contains(e.relatedTarget)) return
  showFormatBar.value = false
  commitEdit()
}

function commitEdit() {
  const data = { ...editData }
  if (props.editingItem?.type === 'todo_list') {
    data.style_data = { ...(props.editingItem.style_data || {}), todos: editTodos.value }
  }
  if (editType.value === 'text' && editorRef.value) {
    const html = editorRef.value.innerHTML || ''
    const hasHtml = /<[a-z][\s\S]*>/i.test(html) && html !== editorRef.value.textContent
    data.content = hasHtml ? html : editorRef.value.textContent
  }
  emit('commit', { id: props.editingItem?.id, ...data })
}

function cancelEdit() {
  emit('cancel')
}

function addTodo(afterIdx) {
  editTodos.value.splice(afterIdx + 1, 0, { text: '', done: false })
}
</script>
