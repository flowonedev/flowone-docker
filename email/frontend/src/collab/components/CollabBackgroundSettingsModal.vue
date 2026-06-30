<template>
  <Teleport to="body">
    <div 
      class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/50"
      @click.self="$emit('close')"
    >
      <div class="bg-white  rounded-xl shadow-2xl w-[480px] max-h-[90vh] overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200">
          <h2 class="text-lg font-semibold text-surface-900">Background</h2>
          <button @click="$emit('close')" class="p-1.5 hover:bg-surface-100 rounded-full transition-colors">
            <span class="material-symbols-rounded text-surface-500">close</span>
          </button>
        </div>

        <!-- Content -->
        <div class="p-5 space-y-5">
          <!-- Background Type Tabs -->
          <div class="flex gap-1 p-1 bg-surface-100 rounded-full">
            <button
              @click="activeTab = 'color'"
              class="flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all"
              :class="activeTab === 'color' 
                ? 'bg-white text-surface-900 shadow-sm' 
                : 'text-surface-600 hover:text-surface-900'"
            >
              <span class="material-symbols-rounded" style="font-size: 18px;">palette</span>
              Color
            </button>
            <button
              @click="activeTab = 'gradient'"
              class="flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all"
              :class="activeTab === 'gradient' 
                ? 'bg-white text-surface-900 shadow-sm' 
                : 'text-surface-600 hover:text-surface-900'"
            >
              <span class="material-symbols-rounded" style="font-size: 18px;">gradient</span>
              Gradient
            </button>
            <button
              @click="activeTab = 'image'"
              class="flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all"
              :class="activeTab === 'image' 
                ? 'bg-white text-surface-900 shadow-sm' 
                : 'text-surface-600 hover:text-surface-900'"
            >
              <span class="material-symbols-rounded" style="font-size: 18px;">image</span>
              Image
            </button>
          </div>

          <!-- Color Picker -->
          <div v-if="activeTab === 'color'" class="space-y-4">
            <!-- Preset colors -->
            <div>
              <label class="block text-sm font-medium text-surface-700 mb-2">Preset Colors</label>
              <div class="grid grid-cols-8 gap-2">
                <button
                  v-for="color in presetColors"
                  :key="color"
                  @click="selectedColor = color"
                  class="w-8 h-8 rounded-full border-2 transition-all hover:scale-110"
                  :class="selectedColor === color ? 'border-primary-500 ring-2 ring-primary-200' : 'border-surface-200'"
                  :style="{ backgroundColor: color }"
                ></button>
              </div>
            </div>

            <!-- Custom color -->
            <div>
              <label class="block text-sm font-medium text-surface-700 mb-2">Custom Color</label>
              <div class="flex items-center gap-3">
                <input
                  type="color"
                  v-model="selectedColor"
                  class="w-12 h-10 rounded-full cursor-pointer border border-surface-300"
                />
                <input
                  type="text"
                  v-model="selectedColor"
                  class="flex-1 px-3 py-2 text-sm border border-surface-300 rounded-full bg-white text-surface-900 focus:outline-none focus:ring-2 focus:ring-primary-500"
                  placeholder="#ffffff"
                />
              </div>
            </div>
          </div>

          <!-- Gradient Picker -->
          <div v-if="activeTab === 'gradient'" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 mb-2">Preset Gradients</label>
              <div class="grid grid-cols-4 gap-3">
                <button
                  v-for="gradient in presetGradients"
                  :key="gradient.name"
                  @click="selectedGradient = gradient.value"
                  class="aspect-video rounded-xl border-2 transition-all hover:scale-105"
                  :class="selectedGradient === gradient.value ? 'border-primary-500 ring-2 ring-primary-200' : 'border-surface-200'"
                  :style="{ background: gradient.value }"
                  :title="gradient.name"
                ></button>
              </div>
            </div>

            <!-- Custom gradient -->
            <div>
              <label class="block text-sm font-medium text-surface-700 mb-2">Custom Gradient</label>
              <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                  <span class="text-xs text-surface-500">From</span>
                  <input
                    type="color"
                    v-model="gradientStart"
                    class="w-10 h-8 rounded-full cursor-pointer border border-surface-300"
                  />
                </div>
                <div class="flex items-center gap-2">
                  <span class="text-xs text-surface-500">To</span>
                  <input
                    type="color"
                    v-model="gradientEnd"
                    class="w-10 h-8 rounded-full cursor-pointer border border-surface-300"
                  />
                </div>
                <select
                  v-model="gradientDirection"
                  class="flex-1 px-3 py-2 text-sm border border-surface-300 rounded-full bg-white"
                >
                  <option value="to right">Left to Right</option>
                  <option value="to left">Right to Left</option>
                  <option value="to bottom">Top to Bottom</option>
                  <option value="to top">Bottom to Top</option>
                  <option value="to bottom right">Diagonal</option>
                  <option value="135deg">Diagonal (alt)</option>
                </select>
              </div>
              <div 
                class="mt-3 h-12 rounded-xl border border-surface-300"
                :style="{ background: customGradient }"
              ></div>
            </div>
          </div>

          <!-- Image Picker -->
          <div v-if="activeTab === 'image'" class="space-y-4">
            <!-- Current image preview -->
            <div v-if="selectedImage" class="relative">
              <div 
                class="aspect-video rounded-xl border border-surface-300 bg-cover bg-center"
                :style="{ backgroundImage: `url(${selectedImage})` }"
              ></div>
              <button
                @click="selectedImage = ''"
                class="absolute top-2 right-2 p-1 bg-red-500 hover:bg-red-600 text-white rounded-full"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">close</span>
              </button>
            </div>

            <!-- Upload options -->
            <div class="flex gap-3">
              <button
                @click="triggerFileUpload"
                class="flex-1 flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-surface-300 rounded-full text-surface-600 hover:border-primary-400 hover:text-primary-500 transition-colors"
              >
                <span class="material-symbols-rounded">upload</span>
                Upload Image
              </button>
              <button
                @click="openDrivePicker"
                class="flex-1 flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-surface-300 rounded-full text-surface-600 hover:border-primary-400 hover:text-primary-500 transition-colors"
              >
                <span class="material-symbols-rounded">cloud</span>
                From Drive
              </button>
            </div>

            <!-- Hidden file input -->
            <input
              ref="fileInput"
              type="file"
              accept="image/*"
              class="hidden"
              @change="handleFileSelect"
            />
          </div>

          <!-- Preview -->
          <div>
            <label class="block text-sm font-medium text-surface-700 mb-2">Preview</label>
            <div 
              class="aspect-video rounded-xl border border-surface-300 shadow-inner"
              :style="previewStyle"
            >
              <div class="flex items-center justify-center h-full text-surface-400 text-sm">
                Slide Preview
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between px-5 py-4 border-t border-surface-200 bg-surface-50 /50">
          <button
            @click="resetToDefault"
            class="px-4 py-2 text-sm text-surface-600 hover:text-surface-900 transition-colors"
          >
            Reset to default
          </button>
          <div class="flex items-center gap-3">
            <button
              @click="$emit('close')"
              class="px-4 py-2 text-sm text-surface-600 hover:bg-surface-100 rounded-full transition-colors"
            >
              Cancel
            </button>
            <button
              @click="applyBackground"
              class="px-5 py-2 text-sm font-medium bg-primary-500 hover:bg-primary-600 text-white rounded-full transition-colors"
            >
              Apply
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  currentBackground: {
    type: Object,
    default: () => ({ type: 'solid', value: '#ffffff' })
  }
})

const emit = defineEmits(['close', 'apply', 'open-drive-picker'])

// State
const activeTab = ref('color')
const selectedColor = ref('#ffffff')
const selectedGradient = ref('')
const selectedImage = ref('')
const gradientStart = ref('#6366f1')
const gradientEnd = ref('#ec4899')
const gradientDirection = ref('to right')
const fileInput = ref(null)
const showDrivePicker = ref(false)

// Preset colors
const presetColors = [
  '#ffffff', '#f8fafc', '#f1f5f9', '#e2e8f0',
  '#1e293b', '#0f172a', '#020617', '#000000',
  '#ef4444', '#f97316', '#eab308', '#22c55e',
  '#14b8a6', '#3b82f6', '#6366f1', '#a855f7',
]

// Preset gradients
const presetGradients = [
  { name: 'Sunset', value: 'linear-gradient(to right, #f97316, #ec4899)' },
  { name: 'Ocean', value: 'linear-gradient(to right, #06b6d4, #3b82f6)' },
  { name: 'Forest', value: 'linear-gradient(to right, #22c55e, #14b8a6)' },
  { name: 'Purple', value: 'linear-gradient(to right, #8b5cf6, #ec4899)' },
  { name: 'Night', value: 'linear-gradient(to bottom, #1e293b, #0f172a)' },
  { name: 'Dawn', value: 'linear-gradient(to bottom, #fef3c7, #fecaca)' },
  { name: 'Sky', value: 'linear-gradient(to bottom, #dbeafe, #bfdbfe)' },
  { name: 'Slate', value: 'linear-gradient(135deg, #64748b, #334155)' },
]

// Custom gradient computed
const customGradient = computed(() => {
  return `linear-gradient(${gradientDirection.value}, ${gradientStart.value}, ${gradientEnd.value})`
})

// Preview style
const previewStyle = computed(() => {
  if (activeTab.value === 'color') {
    return { backgroundColor: selectedColor.value }
  } else if (activeTab.value === 'gradient') {
    return { background: selectedGradient.value || customGradient.value }
  } else if (activeTab.value === 'image' && selectedImage.value) {
    return { 
      backgroundImage: `url(${selectedImage.value})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center'
    }
  }
  return { backgroundColor: '#ffffff' }
})

// Initialize from current background
watch(() => props.currentBackground, (bg) => {
  if (!bg) return
  
  if (bg.type === 'solid') {
    activeTab.value = 'color'
    selectedColor.value = bg.value
  } else if (bg.type === 'gradient') {
    activeTab.value = 'gradient'
    selectedGradient.value = bg.value
  } else if (bg.type === 'image') {
    activeTab.value = 'image'
    selectedImage.value = bg.value
  }
}, { immediate: true })

// Methods
function triggerFileUpload() {
  fileInput.value?.click()
}

function handleFileSelect(e) {
  const file = e.target.files?.[0]
  if (!file) return
  
  const reader = new FileReader()
  reader.onload = (event) => {
    selectedImage.value = event.target.result
  }
  reader.readAsDataURL(file)
  
  e.target.value = ''
}

function resetToDefault() {
  activeTab.value = 'color'
  selectedColor.value = '#ffffff'
  selectedGradient.value = ''
  selectedImage.value = ''
}

function openDrivePicker() {
  emit('open-drive-picker')
}

function applyBackground() {
  let background
  
  if (activeTab.value === 'color') {
    background = { type: 'solid', value: selectedColor.value }
  } else if (activeTab.value === 'gradient') {
    background = { type: 'gradient', value: selectedGradient.value || customGradient.value }
  } else if (activeTab.value === 'image' && selectedImage.value) {
    background = { type: 'image', value: selectedImage.value }
  } else {
    background = { type: 'solid', value: '#ffffff' }
  }
  
  emit('apply', background)
  emit('close')
}

// Expose for Drive picker integration
function setImageFromDrive(imageUrl) {
  selectedImage.value = imageUrl
  activeTab.value = 'image'
}

defineExpose({
  setImageFromDrive
})
</script>

