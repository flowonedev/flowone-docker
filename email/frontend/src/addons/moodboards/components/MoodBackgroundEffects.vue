<template>
  <div :class="embedded
    ? 'flex flex-col overflow-hidden'
    : 'w-72 bg-white dark:bg-surface-800 rounded-2xl shadow-lg border border-surface-200 dark:border-surface-700 overflow-hidden'"
  >
    <!-- Header -->
    <div v-if="!embedded" class="flex items-center justify-between px-4 py-2.5 border-b border-surface-100 dark:border-surface-700">
      <h3 class="text-sm font-semibold text-surface-800 dark:text-surface-200">Background Effects</h3>
      <button
        @click="$emit('close')"
        class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400"
      >
        <span class="material-symbols-rounded text-lg">close</span>
      </button>
    </div>

    <div class="px-4 py-3 space-y-4 max-h-[420px] overflow-y-auto custom-scrollbar">

      <!-- Gradient -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Gradient Overlay</p>
          <button
            @click="toggleEffect('gradient')"
            class="relative w-8 h-4 rounded-full transition-colors"
            :class="localEffect.gradient?.enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
          >
            <span
              class="absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform"
              :class="localEffect.gradient?.enabled ? 'translate-x-4.5' : 'translate-x-0.5'"
            />
          </button>
        </div>
        <template v-if="localEffect.gradient?.enabled">
          <div class="flex gap-2 mb-2">
            <div>
              <label class="text-[9px] text-surface-400 block mb-0.5">From</label>
              <MoodColorPicker
                :model-value="localEffect.gradient.from || '#000000'"
                @update:model-value="setGradientProp('from', $event)"
                :palette="store.getColorPalette()"
                label="Gradient from"
                :show-caret="false"
                dropdown-position="top-full left-0"
              />
            </div>
            <div>
              <label class="text-[9px] text-surface-400 block mb-0.5">To</label>
              <MoodColorPicker
                :model-value="localEffect.gradient.to || '#ffffff'"
                @update:model-value="setGradientProp('to', $event)"
                :palette="store.getColorPalette()"
                label="Gradient to"
                :show-caret="false"
                dropdown-position="top-full left-0"
              />
            </div>
            <div class="flex-1">
              <label class="text-[9px] text-surface-400 block mb-0.5">Angle</label>
              <input
                type="range" min="0" max="360" step="1"
                :value="localEffect.gradient.angle ?? 135"
                @input="setGradientProp('angle', parseInt($event.target.value))"
                class="w-full accent-primary-500"
              />
              <span class="text-[9px] text-surface-400">{{ localEffect.gradient.angle ?? 135 }}deg</span>
            </div>
          </div>
          <div>
            <label class="text-[9px] text-surface-400 block mb-0.5">Opacity</label>
            <input
              type="range" min="0" max="100" step="1"
              :value="localEffect.gradient.opacity ?? 30"
              @input="setGradientProp('opacity', parseInt($event.target.value))"
              class="w-full accent-primary-500"
            />
            <span class="text-[9px] text-surface-400">{{ localEffect.gradient.opacity ?? 30 }}%</span>
          </div>
        </template>
      </div>

      <!-- Grain / Noise -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Grain / Noise</p>
          <button
            @click="toggleEffect('grain')"
            class="relative w-8 h-4 rounded-full transition-colors"
            :class="localEffect.grain?.enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
          >
            <span
              class="absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform"
              :class="localEffect.grain?.enabled ? 'translate-x-4.5' : 'translate-x-0.5'"
            />
          </button>
        </div>
        <template v-if="localEffect.grain?.enabled">
          <div class="space-y-2">
            <div>
              <label class="text-[9px] text-surface-400 block mb-0.5">Intensity</label>
              <input
                type="range" min="1" max="100" step="1"
                :value="localEffect.grain.intensity ?? 20"
                @input="setGrainProp('intensity', parseInt($event.target.value))"
                class="w-full accent-primary-500"
              />
              <span class="text-[9px] text-surface-400">{{ localEffect.grain.intensity ?? 20 }}%</span>
            </div>
            <div>
              <label class="text-[9px] text-surface-400 block mb-0.5">Size</label>
              <input
                type="range" min="1" max="10" step="0.5"
                :value="localEffect.grain.size ?? 1"
                @input="setGrainProp('size', parseFloat($event.target.value))"
                class="w-full accent-primary-500"
              />
              <span class="text-[9px] text-surface-400">{{ localEffect.grain.size ?? 1 }}x</span>
            </div>
            <div class="flex gap-2">
              <button
                v-for="mode in ['mono', 'color']"
                :key="mode"
                @click="setGrainProp('mode', mode)"
                class="flex-1 px-2 py-1 text-[10px] font-medium rounded-lg border transition-colors"
                :class="(localEffect.grain.mode || 'mono') === mode
                  ? 'bg-primary-500 text-white border-primary-500'
                  : 'bg-surface-50 dark:bg-surface-700 text-surface-600 dark:text-surface-400 border-surface-200 dark:border-surface-600'"
              >
                {{ mode === 'mono' ? 'Mono' : 'Color' }}
              </button>
            </div>
          </div>
        </template>
      </div>

      <!-- Blur -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Blur</p>
          <button
            @click="toggleEffect('blur')"
            class="relative w-8 h-4 rounded-full transition-colors"
            :class="localEffect.blur?.enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
          >
            <span
              class="absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform"
              :class="localEffect.blur?.enabled ? 'translate-x-4.5' : 'translate-x-0.5'"
            />
          </button>
        </div>
        <template v-if="localEffect.blur?.enabled">
          <div>
            <label class="text-[9px] text-surface-400 block mb-0.5">Amount</label>
            <input
              type="range" min="0" max="40" step="0.5"
              :value="localEffect.blur.amount ?? 4"
              @input="setEffectProp('blur', 'amount', parseFloat($event.target.value))"
              class="w-full accent-primary-500"
            />
            <span class="text-[9px] text-surface-400">{{ localEffect.blur.amount ?? 4 }}px</span>
          </div>
        </template>
      </div>

      <!-- Vignette -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Vignette</p>
          <button
            @click="toggleEffect('vignette')"
            class="relative w-8 h-4 rounded-full transition-colors"
            :class="localEffect.vignette?.enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
          >
            <span
              class="absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform"
              :class="localEffect.vignette?.enabled ? 'translate-x-4.5' : 'translate-x-0.5'"
            />
          </button>
        </div>
        <template v-if="localEffect.vignette?.enabled">
          <div class="space-y-2">
            <div>
              <label class="text-[9px] text-surface-400 block mb-0.5">Intensity</label>
              <input
                type="range" min="0" max="100" step="1"
                :value="localEffect.vignette.intensity ?? 40"
                @input="setEffectProp('vignette', 'intensity', parseInt($event.target.value))"
                class="w-full accent-primary-500"
              />
              <span class="text-[9px] text-surface-400">{{ localEffect.vignette.intensity ?? 40 }}%</span>
            </div>
            <div>
              <label class="text-[9px] text-surface-400 block mb-0.5">Spread</label>
              <input
                type="range" min="10" max="100" step="1"
                :value="localEffect.vignette.spread ?? 60"
                @input="setEffectProp('vignette', 'spread', parseInt($event.target.value))"
                class="w-full accent-primary-500"
              />
              <span class="text-[9px] text-surface-400">{{ localEffect.vignette.spread ?? 60 }}%</span>
            </div>
          </div>
        </template>
      </div>

      <!-- Presets -->
      <div>
        <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-2">Presets</p>
        <div class="grid grid-cols-3 gap-1.5">
          <button
            v-for="preset in presets"
            :key="preset.name"
            @click="applyPreset(preset)"
            class="px-2 py-2 text-[10px] font-medium text-surface-600 dark:text-surface-400 bg-surface-50 dark:bg-surface-700 hover:bg-surface-100 dark:hover:bg-surface-600 rounded-lg border border-surface-200 dark:border-surface-600 transition-colors text-center"
          >
            {{ preset.name }}
          </button>
        </div>
      </div>

      <!-- Clear all -->
      <button
        @click="clearAll"
        class="w-full flex items-center justify-center gap-1 px-3 py-2 text-xs font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition-colors"
      >
        <span class="material-symbols-rounded text-sm">delete</span>
        Clear All Effects
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import MoodColorPicker from './MoodColorPicker.vue'

const props = defineProps({
  embedded: { type: Boolean, default: false }
})

const emit = defineEmits(['close'])
const store = useMoodBoardsStore()

// Local reactive copy of the effect
const localEffect = ref({
  gradient: null,
  grain: null,
  blur: null,
  vignette: null,
})

// Load from store on mount
const stored = store.getBackgroundEffect()
if (stored) {
  localEffect.value = { ...localEffect.value, ...stored }
}

// Debounce save
let saveTimer = null
function debounceSave() {
  clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    store.saveBackgroundEffect(localEffect.value)
  }, 400)
}

function toggleEffect(key) {
  if (!localEffect.value[key]) {
    localEffect.value[key] = { enabled: true }
  } else {
    localEffect.value[key].enabled = !localEffect.value[key].enabled
  }
  debounceSave()
}

function setGradientProp(prop, val) {
  if (!localEffect.value.gradient) localEffect.value.gradient = { enabled: true }
  localEffect.value.gradient[prop] = val
  debounceSave()
}

function setGrainProp(prop, val) {
  if (!localEffect.value.grain) localEffect.value.grain = { enabled: true }
  localEffect.value.grain[prop] = val
  debounceSave()
}

function setEffectProp(effect, prop, val) {
  if (!localEffect.value[effect]) localEffect.value[effect] = { enabled: true }
  localEffect.value[effect][prop] = val
  debounceSave()
}

function clearAll() {
  localEffect.value = { gradient: null, grain: null, blur: null, vignette: null }
  store.saveBackgroundEffect(null)
}

const presets = [
  {
    name: 'Film Grain',
    data: { grain: { enabled: true, intensity: 25, size: 1.5, mode: 'mono' } }
  },
  {
    name: 'Dreamy',
    data: {
      gradient: { enabled: true, from: '#667eea', to: '#764ba2', angle: 135, opacity: 20 },
      blur: { enabled: true, amount: 2 }
    }
  },
  {
    name: 'Dark Vignette',
    data: { vignette: { enabled: true, intensity: 60, spread: 50 } }
  },
  {
    name: 'Warm Glow',
    data: {
      gradient: { enabled: true, from: '#f093fb', to: '#f5576c', angle: 45, opacity: 15 },
      grain: { enabled: true, intensity: 10, size: 1, mode: 'mono' }
    }
  },
  {
    name: 'Ocean',
    data: {
      gradient: { enabled: true, from: '#4facfe', to: '#00f2fe', angle: 180, opacity: 25 }
    }
  },
  {
    name: 'Noir',
    data: {
      grain: { enabled: true, intensity: 30, size: 2, mode: 'mono' },
      vignette: { enabled: true, intensity: 70, spread: 40 }
    }
  },
]

function applyPreset(preset) {
  localEffect.value = { gradient: null, grain: null, blur: null, vignette: null, ...preset.data }
  debounceSave()
}
</script>

