<template>
  <div class="flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-2 border-b border-surface-100 dark:border-surface-700">
      <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Character Styles</p>
      <button
        @click="openNewEditor"
        class="p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-primary-500 transition-colors"
        title="Add text style"
      >
        <span class="material-symbols-rounded" style="font-size: 16px;">add</span>
      </button>
    </div>

    <!-- Style list (XD-style compact) -->
    <div v-if="styles.length" class="py-0.5">
      <div
        v-for="style in styles"
        :key="style.id"
        class="flex items-center gap-2.5 px-3 py-1.5 hover:bg-surface-50 dark:hover:bg-surface-800 group cursor-pointer transition-colors"
        @click="applyToSelected(style)"
      >
        <!-- Ag preview -->
        <span
          class="w-7 flex-shrink-0 text-surface-400 dark:text-surface-500 leading-none select-none"
          :style="agStyle(style)"
        >Ag</span>

        <!-- Name -->
        <span class="flex-1 min-w-0 text-[11px] text-surface-700 dark:text-surface-300 truncate">
          {{ style.name }}
        </span>

        <!-- Usage badge -->
        <span
          v-if="getUsage(style.id) > 0"
          class="flex-shrink-0 text-[7px] font-bold bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 min-w-[14px] h-[14px] flex items-center justify-center rounded-full px-0.5"
        >{{ getUsage(style.id) }}</span>

        <!-- Hover actions -->
        <div class="flex items-center gap-0.5 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
          <button
            @click.stop="openEditor(style)"
            class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-400"
            title="Edit"
          >
            <span class="material-symbols-rounded" style="font-size: 13px;">edit</span>
          </button>
          <button
            @click.stop="removeStyle(style.id)"
            class="p-0.5 rounded hover:bg-red-50 dark:hover:bg-red-900/20 text-surface-400 hover:text-red-500"
            title="Remove"
          >
            <span class="material-symbols-rounded" style="font-size: 13px;">close</span>
          </button>
        </div>
      </div>
    </div>
    <p v-else class="px-4 py-2 text-[11px] text-surface-400 italic">No character styles yet</p>

    <!-- Save from selection -->
    <div v-if="canSaveFromSelection && !editorOpen" class="px-3 py-1.5">
      <button
        @click="saveFromSelection"
        class="w-full flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600"
      >
        <span class="material-symbols-rounded" style="font-size: 13px;">add_circle</span>
        Save style from selection
      </button>
    </div>

    <!-- Inline editor (compact) -->
    <div v-if="editorOpen" class="px-3 py-2.5 border-t border-primary-200 dark:border-primary-800 bg-primary-50/30 dark:bg-primary-900/10 space-y-2">
      <div class="flex items-center justify-between">
        <p class="text-[9px] font-semibold text-primary-600 dark:text-primary-400 uppercase tracking-wider">
          {{ editorStyle.id ? 'Edit Style' : 'New Style' }}
        </p>
        <button @click="editorOpen = false" class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-700">
          <span class="material-symbols-rounded text-sm text-surface-400">close</span>
        </button>
      </div>

      <!-- Name -->
      <input
        v-model="editorStyle.name"
        class="w-full text-xs bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
        placeholder="Style name..."
      />

      <!-- Font + Weight row -->
      <div class="flex gap-1.5">
        <select
          v-model="editorStyle.font_family"
          class="flex-1 min-w-0 text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-700 dark:text-surface-300"
        >
          <option v-for="f in FONTS" :key="f" :value="f">{{ f }}</option>
        </select>
        <select
          v-model="editorStyle.font_weight"
          class="w-20 text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-700 dark:text-surface-300"
        >
          <option v-for="w in WEIGHTS" :key="w.v" :value="w.v">{{ w.l }}</option>
        </select>
      </div>

      <!-- Size + Line-height + Letter-spacing row -->
      <div class="flex gap-1.5">
        <div class="flex-1">
          <label class="text-[8px] text-surface-400 uppercase">Size</label>
          <input v-model.number="editorStyle.font_size" type="number" min="8" max="200" step="1"
            class="w-full text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-700 dark:text-surface-300" />
        </div>
        <div class="flex-1">
          <label class="text-[8px] text-surface-400 uppercase">LH</label>
          <input v-model.number="editorStyle.line_height" type="number" min="0.5" max="4" step="0.05"
            class="w-full text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-700 dark:text-surface-300" />
        </div>
        <div class="flex-1">
          <label class="text-[8px] text-surface-400 uppercase">LS</label>
          <input v-model.number="editorStyle.letter_spacing" type="number" min="-5" max="20" step="0.1"
            class="w-full text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-700 dark:text-surface-300" />
        </div>
      </div>

      <!-- Transform + Color row -->
      <div class="flex gap-1.5 items-end">
        <div class="flex gap-0.5">
          <button
            v-for="t in TRANSFORMS" :key="t.v"
            @click="editorStyle.text_transform = t.v"
            class="px-1.5 py-1 text-[9px] font-medium rounded border transition-colors"
            :class="editorStyle.text_transform === t.v
              ? 'bg-primary-500 text-white border-primary-500'
              : 'bg-surface-50 dark:bg-surface-700 text-surface-500 border-surface-200 dark:border-surface-600'"
          >{{ t.l }}</button>
        </div>
        <label class="relative flex-shrink-0 cursor-pointer ml-auto">
          <div class="w-5 h-5 rounded border border-surface-300 dark:border-surface-600"
            :style="{ backgroundColor: editorStyle.text_color || '#000000' }" />
          <input type="color" v-model="editorStyle.text_color"
            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
        </label>
      </div>

      <!-- Preview -->
      <div class="p-2 rounded-lg bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-600">
        <p :style="previewStyle(editorStyle)" class="text-surface-700 dark:text-surface-300 truncate leading-tight">
          The quick brown fox
        </p>
      </div>

      <!-- Save -->
      <button
        @click="saveEditorStyle"
        :disabled="!editorStyle.name?.trim()"
        class="w-full py-1.5 text-[10px] font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl transition-colors"
      >{{ editorStyle.id ? 'Update' : 'Create' }}</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const moodStore = useMoodBoardsStore()

const _gs = ref(null)

onMounted(async () => {
  try {
    const mod = await import('@/addons/moodboards/stores/moodBoardGlobalStyles')
    _gs.value = mod.useMoodBoardGlobalStylesStore()
  } catch (e) {
    console.error('[MoodGlobalTextStyles] Store init failed:', e)
  }
})

const styles = computed(() => _gs.value?.globalTextStyles || [])

const FONTS = [
  'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins',
  'Raleway', 'Nunito', 'Playfair Display', 'Merriweather',
  'Oswald', 'Quicksand', 'DM Sans', 'Space Grotesk', 'Manrope',
  'Plus Jakarta Sans', 'Work Sans', 'Rubik', 'PT Sans', 'Source Sans Pro',
]
const WEIGHTS = [
  { v: '100', l: 'Thin' }, { v: '200', l: 'ExLight' }, { v: '300', l: 'Light' },
  { v: '400', l: 'Regular' }, { v: '500', l: 'Medium' }, { v: '600', l: 'Semi' },
  { v: '700', l: 'Bold' }, { v: '800', l: 'ExBold' }, { v: '900', l: 'Black' },
]
const TRANSFORMS = [
  { v: 'none', l: 'Aa' }, { v: 'uppercase', l: 'AA' },
  { v: 'lowercase', l: 'aa' }, { v: 'capitalize', l: 'Ab' },
]

const editorOpen = ref(false)
const editorStyle = ref(defaultStyle())

function defaultStyle() {
  return {
    id: null, name: '', font_family: 'Inter', font_weight: '400',
    font_size: 16, line_height: 1.4, letter_spacing: 0,
    text_transform: 'none', text_color: '#000000',
  }
}

function agStyle(s) {
  return {
    fontFamily: `'${s.font_family || 'Inter'}', sans-serif`,
    fontWeight: s.font_weight || '400',
    fontSize: Math.min(s.font_size || 16, 20) + 'px',
  }
}

function previewStyle(s) {
  return {
    fontFamily: `'${s.font_family || 'Inter'}', sans-serif`,
    fontWeight: s.font_weight || '400',
    fontSize: Math.min(s.font_size || 16, 28) + 'px',
    lineHeight: s.line_height || 1.4,
    letterSpacing: (s.letter_spacing || 0) + 'px',
    textTransform: s.text_transform || 'none',
    color: s.text_color || undefined,
  }
}

function getUsage(id) {
  return _gs.value?.getUsageCount(id) || 0
}

function applyToSelected(style) {
  const ids = [...moodStore.selectedItemIds]
  if (!ids.length || !_gs.value) return
  _gs.value.applyTextStyleToItems(style.id, ids)
}

function removeStyle(id) {
  _gs.value?.removeGlobalTextStyle(id)
}

function openNewEditor() {
  editorStyle.value = defaultStyle()
  editorOpen.value = true
}

function openEditor(style) {
  editorStyle.value = { ...style }
  editorOpen.value = true
}

function saveEditorStyle() {
  const s = editorStyle.value
  if (!s.name?.trim() || !_gs.value) return

  if (s.id) {
    _gs.value.updateGlobalTextStyle(s.id, {
      name: s.name, font_family: s.font_family, font_weight: s.font_weight,
      font_size: s.font_size, line_height: s.line_height,
      letter_spacing: s.letter_spacing, text_transform: s.text_transform,
      text_color: s.text_color,
    })
  } else {
    _gs.value.addGlobalTextStyle({
      ...s, id: `ts-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    })
  }
  editorOpen.value = false
}

// ── Save from selection ──

const canSaveFromSelection = computed(() => {
  const item = moodStore.selectedItems[0]
  if (!item || !_gs.value) return false
  const t = item.type
  return t === 'text' || t === 'shape' || t === 'pen_shape'
})

function saveFromSelection() {
  const item = moodStore.selectedItems[0]
  if (!item || !_gs.value) return
  const sd = item.style_data || {}
  const isShape = item.type === 'shape' || item.type === 'pen_shape'

  const style = {
    id: `ts-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: `Style from ${item.content?.substring(0, 15) || item.type}`,
    font_family: isShape ? (sd.shape_font_family ?? 'Inter') : (sd.font_family ?? 'Inter'),
    font_weight: isShape ? (sd.shape_font_weight ?? '400') : (sd.font_weight ?? '400'),
    font_size: isShape ? (sd.shape_font_size ?? 16) : (sd.font_size ?? 16),
    line_height: isShape ? (sd.shape_line_height ?? 1) : (sd.line_height ?? 1),
    letter_spacing: isShape ? (sd.shape_letter_spacing ?? 0) : (sd.letter_spacing ?? 0),
    text_transform: isShape ? (sd.shape_text_transform ?? 'none') : (sd.text_transform ?? 'none'),
    text_color: isShape ? (sd.shape_text_color ?? '#000000') : (sd.text_color ?? '#000000'),
  }
  _gs.value.addGlobalTextStyle(style)
}
</script>
