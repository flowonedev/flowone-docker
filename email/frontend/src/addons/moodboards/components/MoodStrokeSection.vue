<template>
  <div class="space-y-3">
    <div class="flex items-center gap-2">
      <MoodColorPicker
        :model-value="strokeColor"
        @update:model-value="onUpdateColor"
        :palette="store.getColorPalette()"
        :allow-transparent="true"
        label="Stroke color (double-click to remove)"
        :show-caret="false"
        dropdown-position="top-full left-0"
      />
      <MoodScrubInput
        class="flex-1"
        icon="line_weight"
        suffix="px"
        :model-value="strokeWidth"
        :min="0" :max="100"
        :default-value="2"
        @update:model-value="onUpdateWidth($event)"
      />
    </div>
    <!-- Stroke style (solid/dashed/dotted) -->
    <div class="flex items-center gap-1">
      <button
        v-for="ss in ['solid', 'dashed', 'dotted']"
        :key="ss"
        @click="onUpdateStyle(ss)"
        :class="[
          'flex-1 py-1 text-[10px] rounded-md transition-all',
          strokeStyle === ss
            ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
            : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
        ]"
      >
        {{ ss }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import MoodColorPicker from './MoodColorPicker.vue'
import MoodScrubInput from './MoodScrubInput.vue'
import { normalizeSd } from '../utils/styleAdapter'
import { figmaToHex } from '../utils/colorConvert'

const props = defineProps({
  item: { type: Object, required: true },
})

const emit = defineEmits(['update-style-data'])

const store = useMoodBoardsStore()

const ns = computed(() => normalizeSd(props.item.type, props.item.style_data))

const isShape = computed(() => props.item.type === 'shape' || props.item.type === 'pen_shape')
const isFrame = computed(() => props.item.type === 'frame' || props.item.type === 'slide')

const strokeColor = computed(() => {
  const firstStroke = ns.value.strokes?.[0]
  if (firstStroke?.color) return figmaToHex(firstStroke.color)
  return 'transparent'
})

const strokeWidth = computed(() => ns.value.strokeWeight || 0)

const strokeStyle = computed(() => {
  if (isShape.value) return props.item.style_data?.shape_border_style || 'solid'
  if (isFrame.value) return props.item.style_data?.stroke_style || 'solid'
  return 'solid'
})

function onUpdateColor(val) {
  if (val === 'transparent') {
    onRemoveBorder()
    return
  }
  if (isShape.value) emit('update-style-data', { shape_border_color: val })
  else if (isFrame.value) emit('update-style-data', { stroke_color: val })
}

function onUpdateWidth(val) {
  if (isShape.value) emit('update-style-data', { shape_border_width: val })
  else if (isFrame.value) emit('update-style-data', { stroke_width: val })
}

function onUpdateStyle(val) {
  if (isShape.value) emit('update-style-data', { shape_border_style: val })
  else if (isFrame.value) emit('update-style-data', { stroke_style: val })
}

function onRemoveBorder() {
  if (isShape.value) emit('update-style-data', { shape_border_color: 'transparent', shape_border_width: 0 })
  else if (isFrame.value) emit('update-style-data', { stroke_color: 'transparent', stroke_width: 0 })
}
</script>

