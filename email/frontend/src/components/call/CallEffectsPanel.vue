<script setup>
import { ref, computed } from 'vue'
import { useCallStore } from '@/stores/call'

const emit = defineEmits(['close'])
const callStore = useCallStore()

defineProps({
  // 'above' = opens upward (legacy bottom control bar)
  // 'below' = opens downward (top control bar)
  placement: {
    type: String,
    default: 'above'
  }
})

const applying = ref(false)
const customBgInput = ref(null)

const blurPresets = [
  { label: 'Subtle', radius: 3, icon: 'blur_short' },
  { label: 'Medium', radius: 8, icon: 'blur_medium' },
  { label: 'Strong', radius: 15, icon: 'blur_on' },
]

const virtualBackgrounds = [
  { label: 'Office', url: 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=1280&q=80', thumb: 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=160&q=60' },
  { label: 'Nature', url: 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=1280&q=80', thumb: 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=160&q=60' },
  { label: 'Abstract', url: 'https://images.unsplash.com/photo-1557682250-33bd709cbe85?w=1280&q=80', thumb: 'https://images.unsplash.com/photo-1557682250-33bd709cbe85?w=160&q=60' },
  { label: 'City', url: 'https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?w=1280&q=80', thumb: 'https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?w=160&q=60' },
  { label: 'Studio', url: 'https://images.unsplash.com/photo-1598488035139-bdbb2231ce04?w=1280&q=80', thumb: 'https://images.unsplash.com/photo-1598488035139-bdbb2231ce04?w=160&q=60' },
  { label: 'Cozy', url: 'https://images.unsplash.com/photo-1585412727339-54e4bae3bbf9?w=1280&q=80', thumb: 'https://images.unsplash.com/photo-1585412727339-54e4bae3bbf9?w=160&q=60' },
]

const currentMode = computed(() => callStore.videoEffectMode)
const currentBlurRadius = computed(() => callStore.blurRadius)
const currentVirtualBgUrl = computed(() => callStore.virtualBgUrl)

async function applyEffect(mode, options = {}) {
  if (applying.value) return
  applying.value = true
  try {
    await callStore.setVideoEffect(mode, options)
  } finally {
    applying.value = false
  }
}

async function handleBlurRadiusChange(e) {
  const radius = parseInt(e.target.value) || 10
  if (applying.value) return
  applying.value = true
  try {
    await callStore.setBlurRadius(radius)
  } finally {
    applying.value = false
  }
}

function handleCustomBgUpload() {
  customBgInput.value?.click()
}

async function onCustomBgSelected(e) {
  const file = e.target.files?.[0]
  if (!file) return
  const url = URL.createObjectURL(file)
  await applyEffect('virtual-bg', { imageUrl: url })
}
</script>

<template>
  <div
    :class="[
      'absolute left-1/2 -translate-x-1/2 w-[380px] max-w-[90vw] bg-surface-900/95 backdrop-blur-xl border border-surface-700/60 rounded-2xl shadow-2xl z-50 overflow-hidden',
      placement === 'below' ? 'top-14' : 'bottom-20'
    ]"
  >
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-surface-700/40">
      <h4 class="text-white text-sm font-semibold">Video Effects</h4>
      <button
        @click="emit('close')"
        class="w-7 h-7 rounded-full hover:bg-white/10 flex items-center justify-center transition-colors"
      >
        <span class="material-symbols-rounded text-white/50 text-lg">close</span>
      </button>
    </div>

    <div class="p-4 space-y-5 max-h-[360px] overflow-y-auto">
      <!-- No effect -->
      <div>
        <button
          @click="applyEffect('none')"
          :disabled="applying"
          :class="[
            'w-full flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all',
            currentMode === 'none'
              ? 'bg-white/10 ring-1 ring-white/20'
              : 'hover:bg-white/5'
          ]"
        >
          <span class="w-10 h-10 rounded-lg bg-surface-800 flex items-center justify-center shrink-0">
            <span class="material-symbols-rounded text-white/60">block</span>
          </span>
          <div class="text-left">
            <span class="text-white text-sm font-medium">None</span>
            <p class="text-white/40 text-xs">No background effects</p>
          </div>
          <span v-if="currentMode === 'none'" class="ml-auto material-symbols-rounded text-green-400 text-lg">check_circle</span>
        </button>
      </div>

      <!-- Background blur -->
      <div>
        <p class="text-white/50 text-xs font-medium uppercase tracking-wider mb-2 px-1">Background Blur</p>
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="preset in blurPresets"
            :key="preset.radius"
            @click="applyEffect('blur', { blurRadius: preset.radius })"
            :disabled="applying"
            :class="[
              'flex flex-col items-center gap-1.5 px-2 py-3 rounded-xl transition-all',
              currentMode === 'blur' && currentBlurRadius === preset.radius
                ? 'bg-purple-500/20 ring-1 ring-purple-400/40'
                : 'bg-surface-800/60 hover:bg-surface-800'
            ]"
          >
            <span class="material-symbols-rounded text-xl" :class="currentMode === 'blur' && currentBlurRadius === preset.radius ? 'text-purple-400' : 'text-white/60'">
              {{ preset.icon }}
            </span>
            <span class="text-xs font-medium" :class="currentMode === 'blur' && currentBlurRadius === preset.radius ? 'text-purple-300' : 'text-white/70'">
              {{ preset.label }}
            </span>
          </button>
        </div>

        <!-- Custom blur slider (visible when blur is active) -->
        <div v-if="currentMode === 'blur'" class="mt-3 px-1">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-white/50 text-xs">Intensity</span>
            <span class="text-white/70 text-xs font-mono">{{ currentBlurRadius }}</span>
          </div>
          <input
            type="range"
            min="1"
            max="20"
            :value="currentBlurRadius"
            @change="handleBlurRadiusChange"
            class="w-full h-1.5 bg-surface-700 rounded-full appearance-none cursor-pointer accent-purple-500
              [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4
              [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-purple-400
              [&::-webkit-slider-thumb]:shadow-lg [&::-webkit-slider-thumb]:cursor-pointer"
          />
        </div>
      </div>

      <!-- Virtual backgrounds -->
      <div>
        <p class="text-white/50 text-xs font-medium uppercase tracking-wider mb-2 px-1">Virtual Background</p>
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="bg in virtualBackgrounds"
            :key="bg.url"
            @click="applyEffect('virtual-bg', { imageUrl: bg.url })"
            :disabled="applying"
            :class="[
              'relative aspect-video rounded-xl overflow-hidden transition-all group',
              currentMode === 'virtual-bg' && currentVirtualBgUrl === bg.url
                ? 'ring-2 ring-blue-400'
                : 'ring-1 ring-surface-700/60 hover:ring-white/30'
            ]"
          >
            <img :src="bg.thumb" :alt="bg.label" class="w-full h-full object-cover" loading="lazy" />
            <div class="absolute inset-0 bg-black/30 group-hover:bg-black/10 transition-colors"></div>
            <span class="absolute bottom-1 left-1 text-[10px] text-white/80 font-medium bg-black/40 px-1.5 py-0.5 rounded">{{ bg.label }}</span>
            <span
              v-if="currentMode === 'virtual-bg' && currentVirtualBgUrl === bg.url"
              class="absolute top-1 right-1 material-symbols-rounded text-blue-400 text-base bg-black/40 rounded-full w-5 h-5 flex items-center justify-center"
            >check</span>
          </button>

          <!-- Upload custom -->
          <button
            @click="handleCustomBgUpload"
            :disabled="applying"
            class="aspect-video rounded-xl border-2 border-dashed border-surface-600 hover:border-white/30 flex flex-col items-center justify-center gap-1 transition-colors"
          >
            <span class="material-symbols-rounded text-white/40 text-lg">add_photo_alternate</span>
            <span class="text-white/40 text-[10px] font-medium">Custom</span>
          </button>
        </div>
        <input
          ref="customBgInput"
          type="file"
          accept="image/*"
          class="hidden"
          @change="onCustomBgSelected"
        />
      </div>
    </div>

    <!-- Loading overlay -->
    <div v-if="applying" class="absolute inset-0 bg-surface-900/60 flex items-center justify-center z-10">
      <div class="w-6 h-6 border-2 border-white/20 border-t-white/80 rounded-full animate-spin"></div>
    </div>
  </div>
</template>
