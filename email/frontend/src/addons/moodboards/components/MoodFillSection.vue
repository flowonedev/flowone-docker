<template>
  <div class="space-y-3">
    <!-- Global color quick-pick row -->
    <div v-if="gsStore.globalColors.length" class="flex items-center gap-1 flex-wrap">
      <span class="text-[8px] text-surface-400 uppercase tracking-wider font-bold mr-1">Globals</span>
      <button
        v-for="gc in gsStore.globalColors"
        :key="gc.id"
        @click="applyGlobalColor(gc)"
        class="w-5 h-5 rounded border-2 flex-shrink-0 transition-all hover:scale-125 hover:ring-2 hover:ring-primary-300"
        :class="isActiveGlobal(gc.id) ? 'border-primary-500 ring-2 ring-primary-300' : 'border-surface-200 dark:border-surface-600'"
        :style="{ backgroundColor: gc.value }"
        :title="`${gc.name} (${gc.value})`"
      />
    </div>

    <!-- Gradient-aware fill picker (solid / linear / radial) -->
    <MoodGradientPicker
      :fill-type="fillType"
      :color="fillColor"
      :gradient="fillGradient"
      :palette="store.getColorPalette()"
      :allow-transparent="true"
      @update:fill-type="onUpdateFillType"
      @update:color="onUpdateColor"
      @update:gradient="onUpdateGradient"
    />
    <!-- Opacity (hidden for text, since text has its own in Appearance) -->
    <template v-if="!hideOpacity">
      <div>
        <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Opacity</label>
        <div class="flex items-center gap-2 mt-0.5">
          <input
            type="range"
            :value="opacity"
            min="0" max="100" step="5"
            @input="onUpdateOpacity(parseInt($event.target.value))"
            class="flex-1 h-1 accent-primary-500 cursor-pointer"
          />
          <span class="text-[10px] text-surface-400 min-w-[22px] text-right">{{ opacity }}%</span>
        </div>
      </div>
      <!-- Backdrop Blur -->
      <div>
        <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Backdrop Blur</label>
        <div class="flex items-center gap-2 mt-0.5">
          <input
            type="range"
            :value="backdropBlur"
            min="0" max="50" step="1"
            @input="onUpdateBackdropBlur(parseInt($event.target.value))"
            class="flex-1 h-1 accent-primary-500 cursor-pointer"
          />
          <span class="text-[10px] text-surface-400 min-w-[14px] text-right">{{ backdropBlur }}</span>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useMoodBoardGlobalStylesStore } from '@/addons/moodboards/stores/moodBoardGlobalStyles'
import MoodGradientPicker from './MoodGradientPicker.vue'
import { normalizeSd } from '../utils/styleAdapter'
import { figmaToHex } from '../utils/colorConvert'
import { PaintType, EffectType } from '../utils/figmaStyleSchema'

const props = defineProps({
  item: { type: Object, required: true },
  hideOpacity: { type: Boolean, default: false },
})

const emit = defineEmits(['update-style-data'])

const store = useMoodBoardsStore()
const gsStore = useMoodBoardGlobalStylesStore()

const ns = computed(() => normalizeSd(props.item.type, props.item.style_data))

const isShape = computed(() => props.item.type === 'shape' || props.item.type === 'pen_shape')
const isFrame = computed(() => props.item.type === 'frame' || props.item.type === 'slide')
const isText = computed(() => props.item.type === 'text')

const fillType = computed(() => {
  const firstFill = ns.value.fills?.[0]
  if (!firstFill) return 'solid'
  if (firstFill.type === PaintType.GRADIENT_LINEAR) return 'linear'
  if (firstFill.type === PaintType.GRADIENT_RADIAL) return 'radial'
  return 'solid'
})

const fillColor = computed(() => {
  const firstFill = ns.value.fills?.[0]
  if (firstFill?.color) return figmaToHex(firstFill.color)
  if (isShape.value) return '#6366f1'
  if (isFrame.value) return '#ffffff'
  return '#ffffff'
})

const fillGradient = computed(() => {
  const firstFill = ns.value.fills?.[0]
  if (firstFill?.gradientStops?.length) {
    return {
      angle: firstFill.gradientAngle ?? 180,
      stops: firstFill.gradientStops.map(s => ({
        color: figmaToHex(s.color),
        position: Math.round((s.position ?? 0) * 100),
      })),
    }
  }
  const defaultStops = [{ color: '#6366f1', position: 0 }, { color: '#ec4899', position: 100 }]
  return { angle: 180, stops: defaultStops }
})

const opacity = computed(() => Math.round(ns.value.opacity * 100))

const backdropBlur = computed(() => {
  const bd = ns.value.effects?.find(e => e.visible && e.type === EffectType.BACKGROUND_BLUR)
  return bd?.radius || 0
})

function onUpdateFillType(val) {
  if (isShape.value) emit('update-style-data', { shape_fill_type: val })
  else if (isFrame.value) emit('update-style-data', { fill_type: val })
  else if (isText.value) emit('update-style-data', { text_fill_type: val })
}

function onUpdateColor(val) {
  if (isShape.value) emit('update-style-data', { shape_fill: val })
  else if (isFrame.value) emit('update-style-data', { fill_color: val })
  else if (isText.value) emit('update-style-data', { text_color: val })
}

function onUpdateGradient(val) {
  if (isShape.value) emit('update-style-data', { shape_fill_gradient: val })
  else if (isFrame.value) emit('update-style-data', { fill_gradient: val })
  else if (isText.value) emit('update-style-data', { text_fill_gradient: val })
}

function onUpdateOpacity(val) {
  if (isShape.value) emit('update-style-data', { shape_opacity: val })
  else if (isFrame.value) emit('update-style-data', { frame_opacity: val })
}

function onUpdateBackdropBlur(val) {
  if (isShape.value) emit('update-style-data', { shape_backdrop_blur: val })
  else if (isFrame.value) emit('update-style-data', { frame_backdrop_blur: val })
}

function applyGlobalColor(gc) {
  let styleKey = 'background_color'
  if (isShape.value) styleKey = 'shape_fill'
  else if (isFrame.value) styleKey = 'fill_color'
  else if (isText.value) styleKey = 'text_color'

  const currentGlobals = props.item.style_data?._globals || {}
  emit('update-style-data', {
    [styleKey]: gc.value,
    _globals: { ...currentGlobals, [styleKey]: { type: 'color', id: gc.id } },
  })
}

function isActiveGlobal(gcId) {
  const globals = props.item.style_data?._globals || {}
  return Object.values(globals).some(ref => ref?.id === gcId)
}
</script>

