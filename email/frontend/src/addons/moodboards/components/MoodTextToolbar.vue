<template>
  <!-- Text formatting toolbar — two clean rows (floating or embedded) -->
  <div
    :class="vertical
      ? 'space-y-0'
      : 'bg-white dark:bg-surface-800 rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 px-2.5 py-2 z-50 w-max'"
    @mousedown.stop
    @click.stop
  >
    <!-- Row 1: Font Family | Weight | Size | Color | Transform -->
    <div :class="vertical ? 'flex flex-wrap items-center gap-1' : 'flex items-center gap-1'">
      <!-- Font Family Picker -->
      <div class="relative" ref="fontDropdownRef">
        <button
          @click.stop="showFontDropdown = !showFontDropdown"
          class="flex items-center gap-1 px-2 py-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-[11px] font-medium text-surface-700 dark:text-surface-300 max-w-[100px] truncate"
          :style="{ fontFamily: currentFont }"
          :title="'Font: ' + currentFont"
        >
          <span class="truncate">{{ currentFontLabel }}</span>
          <span class="material-symbols-rounded text-[13px] text-surface-400 flex-shrink-0">expand_more</span>
        </button>
        <div
          v-if="showFontDropdown"
          class="absolute top-full left-0 mt-1 w-56 max-h-64 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
        >
          <div class="px-2 py-1.5 sticky top-0 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700">
            <input
              v-model="fontSearch"
              placeholder="Search fonts..."
              class="w-full bg-surface-100 dark:bg-surface-700 rounded-lg px-2 py-1 text-xs text-surface-700 dark:text-surface-300 focus:outline-none"
              ref="fontSearchInput"
            />
          </div>
          <div v-for="group in filteredFontGroups" :key="group.label" class="py-1">
            <div class="px-3 py-1 text-[10px] uppercase tracking-wider text-surface-400 font-semibold">{{ group.label }}</div>
            <button
              v-for="f in group.fonts"
              :key="f.value"
              @click.stop="selectFont(f.value)"
              class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center justify-between"
              :class="currentFont === f.value ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
              :style="{ fontFamily: f.value }"
            >
              <span>{{ f.label }}</span>
              <span v-if="currentFont === f.value" class="material-symbols-rounded text-sm text-primary-500">check</span>
            </button>
          </div>
        </div>
      </div>

      <div class="w-px h-5 bg-surface-200 dark:bg-surface-600" />

      <!-- Font Weight Picker -->
      <div class="relative" ref="weightDropdownRef">
        <button
          @click.stop="showWeightDropdown = !showWeightDropdown"
          class="flex items-center gap-0.5 px-1.5 py-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-[11px] text-surface-700 dark:text-surface-300 min-w-[52px]"
          :style="{ fontWeight: currentWeight }"
          :title="'Weight: ' + currentWeightLabel"
        >
          <span class="truncate">{{ currentWeightLabel }}</span>
          <span class="material-symbols-rounded text-[12px] text-surface-400 flex-shrink-0">expand_more</span>
        </button>
        <div
          v-if="showWeightDropdown"
          class="absolute top-full left-0 mt-1 w-40 max-h-64 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
        >
          <button
            v-for="w in fontWeights"
            :key="w.value"
            @click.stop="selectWeight(w.value)"
            class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center justify-between"
            :class="currentWeight === w.value ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
            :style="{ fontWeight: w.value }"
          >
            <span>{{ w.label }}</span>
            <span v-if="currentWeight === w.value" class="material-symbols-rounded text-sm text-primary-500">check</span>
          </button>
        </div>
      </div>

      <div class="w-px h-5 bg-surface-200 dark:bg-surface-600" />

      <!-- Font Size (slider + number) -->
      <div class="flex items-center gap-1" title="Font size">
        <input
          type="range"
          :value="fontSize"
          min="1"
          max="500"
          step="1"
          @input.stop="$emit('set-font-size', parseInt($event.target.value))"
          class="w-16 h-1 accent-primary-500 cursor-pointer"
        />
        <input
          type="number"
          :value="fontSize"
          min="1"
          max="500"
          @change.stop="$emit('set-font-size', Math.max(1, Math.min(500, parseInt($event.target.value) || 14)))"
          @click.stop
          class="w-10 text-[10px] font-medium text-surface-600 dark:text-surface-300 bg-surface-100 dark:bg-surface-700 rounded px-1 py-0.5 text-center focus:outline-none focus:ring-1 focus:ring-primary-400 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
        />
        <span class="text-[9px] text-surface-400">px</span>
      </div>

      <div class="w-px h-5 bg-surface-200 dark:bg-surface-600" />

      <!-- Letter Spacing -->
      <div class="flex items-center gap-0.5" title="Letter spacing">
        <span class="material-symbols-rounded text-[13px] text-surface-400">format_letter_spacing</span>
        <input
          type="range"
          :value="currentLetterSpacing"
          min="-10"
          max="100"
          step="0.5"
          @input.stop="$emit('update-style', { letter_spacing: parseFloat($event.target.value) })"
          class="w-14 h-1 accent-primary-500 cursor-pointer"
        />
        <span class="text-[9px] text-surface-400 min-w-[20px] text-right">{{ currentLetterSpacing }}</span>
      </div>

      <div class="w-px h-5 bg-surface-200 dark:bg-surface-600" />

      <!-- Line Height -->
      <div class="flex items-center gap-0.5" title="Line height">
        <span class="material-symbols-rounded text-[13px] text-surface-400">format_line_spacing</span>
        <input
          type="range"
          :value="currentLineHeight"
          min="0.5"
          max="5"
          step="0.1"
          @input.stop="$emit('update-style', { line_height: parseFloat($event.target.value) })"
          class="w-12 h-1 accent-primary-500 cursor-pointer"
        />
        <span class="text-[9px] text-surface-400 min-w-[16px] text-right">{{ currentLineHeight }}</span>
      </div>

      <div class="w-px h-5 bg-surface-200 dark:bg-surface-600" />

      <!-- Text Color -->
      <MoodColorPicker
        :model-value="currentTextColor"
        @update:model-value="$emit('update-style', { text_color: $event })"
        :palette="colorPalette"
        :contrast-bg="contrastBg"
        label="Text color"
        dropdown-position="top-full right-0"
      />
    </div>

    <!-- Row 2: Alignment | Transform | Opacity | Lorem -->
    <div :class="vertical
      ? 'flex flex-wrap items-center gap-1 mt-1.5 pt-1.5 border-t border-surface-100 dark:border-surface-700'
      : 'flex items-center gap-1 mt-1.5 pt-1.5 border-t border-surface-100 dark:border-surface-700'"
    >
      <!-- Text Alignment -->
      <div class="flex items-center gap-0.5">
        <button
          v-for="a in alignOptions"
          :key="a.value"
          @click.stop="$emit('update-style', { text_align: a.value })"
          class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
          :class="currentAlign === a.value ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white' : 'text-surface-500'"
          :title="a.label"
        >
          <span class="material-symbols-rounded text-sm">{{ a.icon }}</span>
        </button>
      </div>

      <div class="w-px h-4 bg-surface-200 dark:bg-surface-600" />

      <!-- Text Transform -->
      <button
        @click.stop="cycleTextTransform"
        class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 relative"
        :class="currentTransform !== 'none' ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white' : 'text-surface-500'"
        :title="'Transform: ' + currentTransform"
      >
        <span class="material-symbols-rounded text-sm">{{ transformIcon }}</span>
      </button>

      <div class="w-px h-4 bg-surface-200 dark:bg-surface-600" />

      <!-- Opacity -->
      <div class="flex items-center gap-0.5" title="Opacity">
        <span class="material-symbols-rounded text-[13px] text-surface-400">opacity</span>
        <input
          type="range"
          :value="currentOpacity"
          min="0"
          max="100"
          step="5"
          @input.stop="$emit('update-style', { text_opacity: parseInt($event.target.value) })"
          class="w-14 h-1 accent-primary-500 cursor-pointer"
        />
        <span class="text-[9px] text-surface-400 min-w-[22px] text-right">{{ currentOpacity }}%</span>
      </div>

      <div class="w-px h-4 bg-surface-200 dark:bg-surface-600" />

      <!-- Lorem Ipsum Generator -->
      <div class="relative" ref="loremBtnRef">
        <button
          @click.stop="showLoremMenu = !showLoremMenu"
          class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500"
          title="Insert Lorem Ipsum"
        >
          <span class="material-symbols-rounded text-sm">notes</span>
        </button>
        <div
          v-if="showLoremMenu"
          class="absolute top-full right-0 mt-1 w-44 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
        >
          <div class="px-3 py-1.5 text-[10px] uppercase tracking-wider text-surface-400 font-semibold">Insert Lorem Ipsum</div>
          <button
            v-for="opt in loremOptions"
            :key="opt.label"
            @click.stop="insertLorem(opt.type)"
            class="w-full text-left px-3 py-1.5 text-xs text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-sm text-surface-400">{{ opt.icon }}</span>
            {{ opt.label }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import MoodColorPicker from './MoodColorPicker.vue'
import { FONT_GROUPS as SHARED_FONT_GROUPS, getFontLabel, filterFontGroups, loadGoogleFont, preloadFontList, ALL_FONT_VALUES } from '../utils/fontLoader'

const props = defineProps({
  styleData: { type: Object, default: () => ({}) },
  fontSize: { type: Number, default: 14 },
  colorPalette: { type: Array, default: () => [] },
  vertical: { type: Boolean, default: false },
  contrastBg: { type: String, default: '' },
})

const emit = defineEmits(['update-style', 'increase-font', 'decrease-font', 'set-font-size', 'insert-lorem'])

// --- Font Family ---
const FONT_GROUPS = SHARED_FONT_GROUPS

const showFontDropdown = ref(false)
const fontSearch = ref('')
const fontDropdownRef = ref(null)
const fontSearchInput = ref(null)

const currentFont = computed(() => props.styleData?.font_family || 'Inter')
const currentFontLabel = computed(() => getFontLabel(currentFont.value))

const filteredFontGroups = computed(() => filterFontGroups(FONT_GROUPS, fontSearch.value))

function selectFont(fontValue) {
  loadGoogleFont(fontValue)
  emit('update-style', { font_family: fontValue })
  showFontDropdown.value = false
  fontSearch.value = ''
}

let fontsPreloaded = false
watch(showFontDropdown, (open) => {
  if (open) {
    setTimeout(() => fontSearchInput.value?.focus(), 50)
    if (!fontsPreloaded) {
      fontsPreloaded = true
      preloadFontList(ALL_FONT_VALUES)
    }
  }
})

// --- Text Color ---
const currentTextColor = computed(() => props.styleData?.text_color || '#1e293b')

// --- Font Weight ---
const fontWeights = [
  { value: '100', label: 'Thin' },
  { value: '200', label: 'Extra Light' },
  { value: '300', label: 'Light' },
  { value: '400', label: 'Regular' },
  { value: '500', label: 'Medium' },
  { value: '600', label: 'Semi Bold' },
  { value: '700', label: 'Bold' },
  { value: '800', label: 'Extra Bold' },
  { value: '900', label: 'Black' },
]

const showWeightDropdown = ref(false)
const weightDropdownRef = ref(null)

const currentWeight = computed(() => props.styleData?.font_weight || '400')
const currentWeightLabel = computed(() => {
  const w = fontWeights.find(fw => fw.value === currentWeight.value)
  return w ? w.label : 'Regular'
})

function selectWeight(value) {
  emit('update-style', { font_weight: value })
  showWeightDropdown.value = false
}

// --- Text Transform ---
const currentTransform = computed(() => props.styleData?.text_transform || 'none')
const transformIcon = computed(() => {
  switch (currentTransform.value) {
    case 'uppercase': return 'title'
    case 'lowercase': return 'text_fields'
    case 'capitalize': return 'match_case'
    default: return 'format_size'
  }
})

function cycleTextTransform() {
  const cycle = ['none', 'uppercase', 'lowercase', 'capitalize']
  const idx = cycle.indexOf(currentTransform.value)
  const next = cycle[(idx + 1) % cycle.length]
  emit('update-style', { text_transform: next })
}

// --- Text Alignment ---
const alignOptions = [
  { value: 'left', icon: 'format_align_left', label: 'Align Left' },
  { value: 'center', icon: 'format_align_center', label: 'Align Center' },
  { value: 'right', icon: 'format_align_right', label: 'Align Right' },
]
const currentAlign = computed(() => props.styleData?.text_align || 'left')

// --- Letter Spacing ---
const currentLetterSpacing = computed(() => props.styleData?.letter_spacing ?? 0)

// --- Line Height ---
const currentLineHeight = computed(() => props.styleData?.line_height ?? 1)

// --- Opacity ---
const currentOpacity = computed(() => props.styleData?.text_opacity ?? 100)

// --- Lorem Ipsum ---
const showLoremMenu = ref(false)
const loremBtnRef = ref(null)

const loremOptions = [
  { label: '1 Sentence', type: 'sentence', icon: 'short_text' },
  { label: '1 Paragraph', type: 'paragraph', icon: 'notes' },
  { label: '3 Paragraphs', type: 'paragraphs', icon: 'article' },
  { label: 'Heading Text', type: 'heading', icon: 'title' },
]

const LOREM_SENTENCES = [
  'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
  'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
  'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
  'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
  'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
  'Curabitur pretium tincidunt lacus, nec faucibus nisl tincidunt vel.',
  'Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae.',
  'Proin eget tortor risus, nec luctus nisl tincidunt vel.',
  'Maecenas sed diam eget risus varius blandit sit amet non magna.',
  'Integer posuere erat a ante venenatis dapibus posuere velit aliquet.',
]

const LOREM_HEADINGS = [
  'The Art of Visual Design',
  'Crafting Meaningful Experiences',
  'Beyond the Ordinary',
  'Where Ideas Take Shape',
  'Redefining Creative Excellence',
  'A New Perspective on Design',
]

function generateLorem(type) {
  switch (type) {
    case 'sentence':
      return LOREM_SENTENCES[Math.floor(Math.random() * LOREM_SENTENCES.length)]
    case 'paragraph':
      return shuffleAndPick(LOREM_SENTENCES, 4).join(' ')
    case 'paragraphs':
      return [
        shuffleAndPick(LOREM_SENTENCES, 4).join(' '),
        shuffleAndPick(LOREM_SENTENCES, 3).join(' '),
        shuffleAndPick(LOREM_SENTENCES, 4).join(' '),
      ].join('\n\n')
    case 'heading':
      return LOREM_HEADINGS[Math.floor(Math.random() * LOREM_HEADINGS.length)]
    default:
      return LOREM_SENTENCES[0]
  }
}

function shuffleAndPick(arr, count) {
  const shuffled = [...arr].sort(() => Math.random() - 0.5)
  return shuffled.slice(0, count)
}

function insertLorem(type) {
  emit('insert-lorem', generateLorem(type))
  showLoremMenu.value = false
}

// Close dropdowns on outside click
function onDocClick(e) {
  if (fontDropdownRef.value && !fontDropdownRef.value.contains(e.target)) {
    showFontDropdown.value = false
  }
  if (weightDropdownRef.value && !weightDropdownRef.value.contains(e.target)) {
    showWeightDropdown.value = false
  }
  if (loremBtnRef.value && !loremBtnRef.value.contains(e.target) && showLoremMenu.value) {
    showLoremMenu.value = false
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onDocClick)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onDocClick)
})
</script>

