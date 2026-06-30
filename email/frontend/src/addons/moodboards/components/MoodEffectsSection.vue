<template>
  <div class="space-y-3">
    <!-- Text Shadow (text items only) -->
    <template v-if="item.type === 'text'">
      <div>
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Text Shadow</span>
          <span
            @click="toggleTextShadow"
            :class="[
              'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
              textShadowEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                textShadowEnabled ? 'translate-x-[18px]' : 'translate-x-[2px]'
              ]"
            />
          </span>
        </div>
        <template v-if="textShadowEnabled">
          <div class="space-y-2">
            <!-- Shadow color -->
            <div class="flex items-center gap-2">
              <MoodColorPicker
                :model-value="textShadowColor"
                @update:model-value="val => onUpdate({ text_shadow_color: val })"
                :palette="store.getColorPalette()"
                label="Text shadow color"
                :show-caret="false"
                dropdown-position="top-full left-0"
              />
              <div class="flex items-center gap-1 flex-1">
                <label class="text-[9px] text-surface-400 w-8">Alpha</label>
                <input
                  type="range"
                  :value="textShadowOpacity"
                  min="0" max="100" step="5"
                  @input="onUpdate({ text_shadow_opacity: parseInt($event.target.value) })"
                  class="flex-1 h-1 accent-primary-500 cursor-pointer"
                />
                <span class="text-[10px] text-surface-400 min-w-[22px] text-right">{{ textShadowOpacity }}%</span>
              </div>
            </div>
            <!-- Offset X / Y -->
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Offset X</label>
                <MoodScrubInput
                  class="mt-0.5"
                  label="X"
                  suffix="px"
                  :model-value="textShadowX"
                  @update:model-value="onUpdate({ text_shadow_x: $event })"
                />
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Offset Y</label>
                <MoodScrubInput
                  class="mt-0.5"
                  label="Y"
                  suffix="px"
                  :model-value="textShadowY"
                  @update:model-value="onUpdate({ text_shadow_y: $event })"
                />
              </div>
            </div>
            <!-- Blur -->
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Blur</label>
              <div class="flex items-center gap-1 mt-0.5">
                <input
                  type="range"
                  :value="textShadowBlur"
                  min="0" max="200" step="1"
                  @input="onUpdate({ text_shadow_blur: parseInt($event.target.value) })"
                  class="flex-1 h-1 accent-primary-500 cursor-pointer"
                />
                <span class="text-[10px] text-surface-400 min-w-[14px] text-right">{{ textShadowBlur }}</span>
              </div>
            </div>
          </div>
        </template>
      </div>
      <div class="border-t border-surface-100 dark:border-surface-700" />
    </template>

    <!-- Drop Shadow -->
    <div>
      <div class="flex items-center justify-between mb-1.5">
        <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Drop Shadow</span>
        <span
          @click="toggleShadow"
          :class="[
            'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
            shadowEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
          ]"
        >
          <span
            :class="[
              'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
              shadowEnabled ? 'translate-x-[18px]' : 'translate-x-[2px]'
            ]"
          />
        </span>
      </div>
      <template v-if="shadowEnabled">
        <div class="space-y-2">
          <!-- Shadow color -->
          <div class="flex items-center gap-2">
            <MoodColorPicker
              :model-value="shadowColor"
              @update:model-value="val => onUpdate({ shadow_color: val })"
              :palette="store.getColorPalette()"
              label="Shadow color"
              :show-caret="false"
              dropdown-position="top-full left-0"
            />
            <div class="flex items-center gap-1 flex-1">
              <label class="text-[9px] text-surface-400 w-8">Alpha</label>
              <input
                type="range"
                :value="shadowOpacity"
                min="0" max="100" step="5"
                @input="onUpdate({ shadow_opacity: parseInt($event.target.value) })"
                class="flex-1 h-1 accent-primary-500 cursor-pointer"
              />
              <span class="text-[10px] text-surface-400 min-w-[22px] text-right">{{ shadowOpacity }}%</span>
            </div>
          </div>
          <!-- Offset X / Y -->
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Offset X</label>
              <MoodScrubInput
                class="mt-0.5"
                label="X"
                suffix="px"
                :model-value="shadowX"
                @update:model-value="onUpdate({ shadow_x: $event })"
              />
            </div>
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Offset Y</label>
              <MoodScrubInput
                class="mt-0.5"
                label="Y"
                suffix="px"
                :model-value="shadowY"
                @update:model-value="onUpdate({ shadow_y: $event })"
              />
            </div>
          </div>
          <!-- Blur / Spread -->
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Blur</label>
              <div class="flex items-center gap-1 mt-0.5">
                <input
                  type="range"
                  :value="shadowBlur"
                  min="0" max="200" step="1"
                  @input="onUpdate({ shadow_blur: parseInt($event.target.value) })"
                  class="flex-1 h-1 accent-primary-500 cursor-pointer"
                />
                <span class="text-[10px] text-surface-400 min-w-[14px] text-right">{{ shadowBlur }}</span>
              </div>
            </div>
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Spread</label>
              <div class="flex items-center gap-1 mt-0.5">
                <input
                  type="range"
                  :value="shadowSpread"
                  min="-20" max="200" step="1"
                  @input="onUpdate({ shadow_spread: parseInt($event.target.value) })"
                  class="flex-1 h-1 accent-primary-500 cursor-pointer"
                />
                <span class="text-[10px] text-surface-400 min-w-[14px] text-right">{{ shadowSpread }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Divider -->
    <div class="border-t border-surface-100 dark:border-surface-700" />

    <!-- Blur (filter: blur — blurs the object itself) -->
    <div>
      <div class="flex items-center justify-between mb-1.5">
        <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Blur</span>
        <span
          @click="toggleBlur"
          :class="[
            'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
            blurEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
          ]"
        >
          <span
            :class="[
              'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
              blurEnabled ? 'translate-x-[18px]' : 'translate-x-[2px]'
            ]"
          />
        </span>
      </div>
      <template v-if="blurEnabled">
        <div class="flex items-center gap-2">
          <input
            type="range"
            :value="blurAmount"
            min="0" max="500" step="1"
            @input="onUpdate({ blur_amount: parseInt($event.target.value) })"
            class="flex-1 h-1 accent-primary-500 cursor-pointer"
          />
          <span class="text-[10px] text-surface-400 min-w-[26px] text-right">{{ blurAmount }}px</span>
        </div>
      </template>
    </div>

    <!-- Divider -->
    <div class="border-t border-surface-100 dark:border-surface-700" />

    <!-- Backdrop Blur (backdrop-filter: blur — blurs objects BEHIND this element, frosted glass effect) -->
    <div>
      <div class="flex items-center justify-between mb-1.5">
        <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Backdrop Blur</span>
        <span
          @click="toggleBackdropBlur"
          :class="[
            'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
            backdropBlurEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
          ]"
        >
          <span
            :class="[
              'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
              backdropBlurEnabled ? 'translate-x-[18px]' : 'translate-x-[2px]'
            ]"
          />
        </span>
      </div>
      <template v-if="backdropBlurEnabled">
        <div class="flex items-center gap-2">
          <input
            type="range"
            :value="backdropBlurAmount"
            min="0" max="200" step="1"
            @input="onUpdate({ backdrop_blur_amount: parseInt($event.target.value) })"
            class="flex-1 h-1 accent-primary-500 cursor-pointer"
          />
          <span class="text-[10px] text-surface-400 min-w-[14px] text-right">{{ backdropBlurAmount }}px</span>
        </div>
      </template>
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
import { EffectType } from '../utils/figmaStyleSchema'

const props = defineProps({
  item: { type: Object, required: true },
})

const emit = defineEmits(['update-style-data'])

const store = useMoodBoardsStore()

const ns = computed(() => normalizeSd(props.item.type, props.item.style_data))

// ── Text Shadow (text items only) ──
const textShadowEffect = computed(() => ns.value.effects?.find(e => e.type === EffectType.TEXT_SHADOW))
const textShadowEnabled = computed(() => !!textShadowEffect.value?.visible)
const textShadowColor = computed(() => textShadowEffect.value?.color ? figmaToHex(textShadowEffect.value.color) : '#000000')
const textShadowOpacity = computed(() => Math.round((textShadowEffect.value?.color?.a ?? 0.4) * 100))
const textShadowX = computed(() => textShadowEffect.value?.offset?.x ?? 1)
const textShadowY = computed(() => textShadowEffect.value?.offset?.y ?? 2)
const textShadowBlur = computed(() => textShadowEffect.value?.radius ?? 4)

function toggleTextShadow() {
  if (textShadowEnabled.value) {
    onUpdate({ text_shadow_enabled: false })
  } else {
    onUpdate({
      text_shadow_enabled: true,
      text_shadow_color: props.item.style_data?.text_shadow_color || '#000000',
      text_shadow_opacity: props.item.style_data?.text_shadow_opacity ?? 40,
      text_shadow_x: props.item.style_data?.text_shadow_x ?? 1,
      text_shadow_y: props.item.style_data?.text_shadow_y ?? 2,
      text_shadow_blur: props.item.style_data?.text_shadow_blur ?? 4,
    })
  }
}

// ── Drop Shadow ──
const dropShadowEffect = computed(() => ns.value.effects?.find(e => e.type === EffectType.DROP_SHADOW))
const shadowEnabled = computed(() => !!dropShadowEffect.value?.visible)
const shadowColor = computed(() => dropShadowEffect.value?.color ? figmaToHex(dropShadowEffect.value.color) : '#000000')
const shadowOpacity = computed(() => Math.round((dropShadowEffect.value?.color?.a ?? 0.25) * 100))
const shadowX = computed(() => dropShadowEffect.value?.offset?.x ?? 0)
const shadowY = computed(() => dropShadowEffect.value?.offset?.y ?? 4)
const shadowBlur = computed(() => dropShadowEffect.value?.radius ?? 8)
const shadowSpread = computed(() => dropShadowEffect.value?.spread ?? 0)

function toggleShadow() {
  if (shadowEnabled.value) {
    onUpdate({ shadow_enabled: false })
  } else {
    // Enable with defaults
    onUpdate({
      shadow_enabled: true,
      shadow_color: props.item.style_data?.shadow_color || '#000000',
      shadow_opacity: props.item.style_data?.shadow_opacity ?? 25,
      shadow_x: props.item.style_data?.shadow_x ?? 0,
      shadow_y: props.item.style_data?.shadow_y ?? 4,
      shadow_blur: props.item.style_data?.shadow_blur ?? 8,
      shadow_spread: props.item.style_data?.shadow_spread ?? 0,
    })
  }
}

// ── Blur ──
const layerBlurEffect = computed(() => ns.value.effects?.find(e => e.type === EffectType.LAYER_BLUR))
const blurEnabled = computed(() => !!layerBlurEffect.value?.visible)
const blurAmount = computed(() => layerBlurEffect.value?.radius ?? 4)

function toggleBlur() {
  if (blurEnabled.value) {
    onUpdate({ blur_enabled: false })
  } else {
    const stored = props.item.style_data?.blur_amount
    onUpdate({
      blur_enabled: true,
      blur_amount: (stored && stored > 0) ? stored : 4,
    })
  }
}

// ── Backdrop Blur (frosted glass -- blurs what's behind this element) ──
const bgBlurEffect = computed(() => ns.value.effects?.find(e => e.type === EffectType.BACKGROUND_BLUR))
const backdropBlurEnabled = computed(() => !!bgBlurEffect.value?.visible)
const backdropBlurAmount = computed(() => bgBlurEffect.value?.radius ?? 8)

function toggleBackdropBlur() {
  if (backdropBlurEnabled.value) {
    onUpdate({ backdrop_blur_enabled: false })
  } else {
    onUpdate({
      backdrop_blur_enabled: true,
      backdrop_blur_amount: props.item.style_data?.backdrop_blur_amount ?? 8,
    })
  }
}

function onUpdate(data) {
  emit('update-style-data', data)
}
</script>

