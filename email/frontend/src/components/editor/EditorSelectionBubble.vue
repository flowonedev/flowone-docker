<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import ColorPickerButton from './ColorPickerButton.vue'
import { TEXT_COLOR_PRESETS, HIGHLIGHT_PRESETS } from './colorPalettes'

const props = defineProps({
  editor: { type: Object, required: true },
  tasksEnabled: { type: Boolean, default: false },
  textPresets: { type: Array, default: () => TEXT_COLOR_PRESETS },
  highlightPresets: { type: Array, default: () => HIGHLIGHT_PRESETS },
})

const emit = defineEmits(['add-todo'])

const visible = ref(false)
const pos = ref({ top: '0px', left: '0px', placement: 'top' })
const bubbleRef = ref(null)
const activeTextColor = ref(null)
const activeHighlight = ref(null)

let dragging = false
let editorDom = null

function hide() {
  visible.value = false
}

function updateFromSelection() {
  const editor = props.editor
  if (!editor || editor.isDestroyed) return hide()
  const { state, view } = editor
  const sel = state.selection
  if (sel.empty) return hide()
  const text = state.doc.textBetween(sel.from, sel.to, ' ').trim()
  if (!text) return hide()

  activeTextColor.value = editor.getAttributes('textStyle')?.color || null
  activeHighlight.value = editor.getAttributes('highlight')?.color || null

  let start, end
  try {
    start = view.coordsAtPos(sel.from)
    end = view.coordsAtPos(sel.to)
  } catch (_) {
    return hide()
  }

  const centerLeft = (start.left + end.left) / 2
  const selTop = Math.min(start.top, end.top)
  const selBottom = Math.max(start.bottom, end.bottom)

  // Default above the selection; flip below if too close to the top.
  let placement = 'top'
  let top = selTop - 8
  if (selTop < 70) {
    placement = 'bottom'
    top = selBottom + 8
  }

  const half = 110
  const left = Math.max(half + 8, Math.min(centerLeft, window.innerWidth - half - 8))

  pos.value = { top: `${top}px`, left: `${left}px`, placement }
  visible.value = true
}

function onEditorMousedown() {
  dragging = true
  visible.value = false
}

function onDocMouseup() {
  setTimeout(() => {
    dragging = false
    updateFromSelection()
  }, 10)
}

function onSelectionUpdate() {
  if (!dragging) updateFromSelection()
}

function onDocMousedown(e) {
  if (!visible.value) return
  const t = e.target
  if (editorDom && editorDom.contains(t)) return
  if (t?.closest?.('[data-editor-bubble]') || t?.closest?.('[data-color-panel]')) return
  hide()
}

function onScrollResize() {
  if (visible.value) updateFromSelection()
}

function applyTextColor(color) {
  const chain = props.editor?.chain().focus()
  if (!chain) return
  if (color) chain.setColor(color).run()
  else chain.unsetColor().run()
  activeTextColor.value = color
}

function applyHighlight(color) {
  const chain = props.editor?.chain().focus()
  if (!chain) return
  if (color) chain.setHighlight({ color }).run()
  else chain.unsetHighlight().run()
  activeHighlight.value = color
}

function addTodo() {
  emit('add-todo')
  hide()
}

onMounted(() => {
  const editor = props.editor
  if (!editor) return
  editorDom = editor.view?.dom || null
  editor.on('selectionUpdate', onSelectionUpdate)
  if (editorDom) editorDom.addEventListener('mousedown', onEditorMousedown)
  document.addEventListener('mouseup', onDocMouseup)
  document.addEventListener('mousedown', onDocMousedown, true)
  window.addEventListener('scroll', onScrollResize, true)
  window.addEventListener('resize', onScrollResize)
})

onBeforeUnmount(() => {
  const editor = props.editor
  if (editor && !editor.isDestroyed) editor.off('selectionUpdate', onSelectionUpdate)
  if (editorDom) editorDom.removeEventListener('mousedown', onEditorMousedown)
  document.removeEventListener('mouseup', onDocMouseup)
  document.removeEventListener('mousedown', onDocMousedown, true)
  window.removeEventListener('scroll', onScrollResize, true)
  window.removeEventListener('resize', onScrollResize)
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      ref="bubbleRef"
      data-editor-bubble
      class="fixed z-[9999] flex items-center gap-0.5 px-1 py-1 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700"
      :style="{
        top: pos.top,
        left: pos.left,
        transform: pos.placement === 'top' ? 'translate(-50%, -100%)' : 'translate(-50%, 0)',
      }"
    >
      <ColorPickerButton
        icon="format_color_text"
        title="Text color"
        panel-title="Text color"
        remove-label="Auto"
        :presets="textPresets"
        :indicator-color="activeTextColor"
        :placement="pos.placement === 'top' ? 'top' : 'bottom'"
        compact
        @select="applyTextColor"
      />
      <ColorPickerButton
        icon="format_color_fill"
        title="Highlight color"
        panel-title="Highlight"
        remove-label="None"
        :presets="highlightPresets"
        :indicator-color="activeHighlight"
        :placement="pos.placement === 'top' ? 'top' : 'bottom'"
        compact
        @select="applyHighlight"
      />

      <template v-if="tasksEnabled">
        <div class="w-px h-5 bg-surface-200 dark:bg-surface-700 mx-0.5"></div>
        <button
          type="button"
          title="Add selection to Todo"
          @click="addTodo"
          class="flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-base">add_task</span>
          To-do
        </button>
      </template>
    </div>
  </Teleport>
</template>
