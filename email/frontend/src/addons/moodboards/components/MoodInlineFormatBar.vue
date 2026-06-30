<template>
  <!-- Inline rich-text format bar — appears above selected text in contenteditable -->
  <div
    class="inline-format-bar bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 flex items-center gap-0.5 px-1.5 py-1"
    style="transform: translateX(-50%)"
    @mousedown.prevent.stop
  >
    <!-- Bold -->
    <button
      @click.stop="$emit('format-bold')"
      class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300 transition-colors"
      title="Bold"
    >
      <span class="material-symbols-rounded text-base">format_bold</span>
    </button>

    <!-- Italic -->
    <button
      @click.stop="$emit('format-italic')"
      class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300 transition-colors"
      title="Italic"
    >
      <span class="material-symbols-rounded text-base">format_italic</span>
    </button>

    <!-- Underline -->
    <button
      @click.stop="$emit('format-underline')"
      class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300 transition-colors"
      title="Underline"
    >
      <span class="material-symbols-rounded text-base">format_underlined</span>
    </button>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-600 mx-0.5" />

    <!-- Color presets -->
    <div class="flex items-center gap-0.5">
      <button
        v-for="c in quickColors"
        :key="c"
        @click.stop="$emit('format-color', c)"
        class="w-5 h-5 rounded-full border border-surface-200 dark:border-surface-600 hover:ring-2 hover:ring-primary-400 transition-all flex-shrink-0"
        :style="{ backgroundColor: c }"
        :title="'Color: ' + c"
      />
      <!-- Custom color -->
      <div class="relative">
        <button
          @click.stop="showCustomColor = !showCustomColor"
          class="w-5 h-5 rounded-full border border-dashed border-surface-300 dark:border-surface-500 hover:border-primary-400 flex items-center justify-center transition-colors flex-shrink-0"
          title="Custom color"
        >
          <span class="material-symbols-rounded text-[10px] text-surface-400">palette</span>
        </button>
        <div
          v-if="showCustomColor"
          class="absolute top-full left-1/2 -translate-x-1/2 mt-2 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-2 z-50"
          @mousedown.prevent.stop
        >
          <input
            type="color"
            :value="customColorValue"
            @input="customColorValue = $event.target.value"
            @change="$emit('format-color', $event.target.value); showCustomColor = false"
            class="w-8 h-8 rounded cursor-pointer border-0 p-0"
          />
        </div>
      </div>
    </div>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-600 mx-0.5" />

    <!-- Font family quick picks -->
    <div class="relative" ref="fontDropdownRef">
      <button
        @click.stop="showFontPicker = !showFontPicker"
        class="flex items-center gap-0.5 px-1.5 py-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-[10px] font-medium text-surface-600 dark:text-surface-300 max-w-[70px]"
        title="Change font"
      >
        <span class="material-symbols-rounded text-sm">text_format</span>
        <span class="material-symbols-rounded text-[10px] text-surface-400">expand_more</span>
      </button>
      <div
        v-if="showFontPicker"
        class="absolute top-full right-0 mt-1 w-44 max-h-52 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
        @mousedown.prevent.stop
      >
        <button
          v-for="f in quickFonts"
          :key="f"
          @click.stop="onSelectFont(f)"
          class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300"
          :style="{ fontFamily: f }"
        >
          {{ f }}
        </button>
      </div>
    </div>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-600 mx-0.5" />

    <!-- Clear all formatting -->
    <button
      @click.stop="$emit('format-clear')"
      class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30 text-surface-500 hover:text-red-500 dark:text-surface-400 dark:hover:text-red-400 transition-colors"
      title="Clear formatting"
    >
      <span class="material-symbols-rounded text-base">format_clear</span>
    </button>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { QUICK_FONTS, loadGoogleFont, preloadFontList } from '../utils/fontLoader'

const emit = defineEmits(['format-bold', 'format-italic', 'format-underline', 'format-color', 'format-font', 'format-clear'])

const showCustomColor = ref(false)
const customColorValue = ref('#6366f1')
const showFontPicker = ref(false)
const fontDropdownRef = ref(null)

const quickColors = [
  '#1e293b', '#ffffff', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899'
]

const quickFonts = QUICK_FONTS

function onSelectFont(f) {
  loadGoogleFont(f)
  emit('format-font', f)
  showFontPicker.value = false
}

let inlineFontsPreloaded = false
watch(showFontPicker, (open) => {
  if (open && !inlineFontsPreloaded) {
    inlineFontsPreloaded = true
    preloadFontList(QUICK_FONTS)
  }
})

function onDocClick(e) {
  if (fontDropdownRef.value && !fontDropdownRef.value.contains(e.target)) {
    showFontPicker.value = false
  }
}

onMounted(() => document.addEventListener('mousedown', onDocClick))
onUnmounted(() => document.removeEventListener('mousedown', onDocClick))
</script>

<style scoped>
.inline-format-bar {
  animation: fadeInUp 0.15s ease;
}
@keyframes fadeInUp {
  from { opacity: 0; transform: translateX(-50%) translateY(4px); }
  to { opacity: 1; transform: translateX(-50%) translateY(0); }
}
</style>

