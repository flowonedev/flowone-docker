<script setup>
import { ref, watch, onMounted } from 'vue'
import Modal from '@/components/shared/Modal.vue'
import { useChatStore } from '@/addons/chat/stores/chat'

const props = defineProps({
  show: Boolean,
  conversationId: [Number, String]
})

const emit = defineEmits(['close', 'update'])
const chatStore = useChatStore()

// Settings state
const backgroundImage = ref('')
const backgroundOpacity = ref(0.1)
const backgroundSize = ref('') // Empty = cover/fill, '20px 20px' = tiled pattern
const previewUrl = ref('')
const previewSize = ref('')
const saving = ref(false)

// Preset backgrounds
const presetBackgrounds = [
  { name: 'None', url: '', size: '' },
  { name: 'Gradient Blue', url: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', size: '' },
  { name: 'Gradient Green', url: 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)', size: '' },
  { name: 'Gradient Sunset', url: 'linear-gradient(135deg, #ff6b6b 0%, #feca57 100%)', size: '' },
  { name: 'Gradient Purple', url: 'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)', size: '' },
  { name: 'Gradient Dark', url: 'linear-gradient(135deg, #232526 0%, #414345 100%)', size: '' },
  { name: 'Pattern Dots', url: 'radial-gradient(circle, rgba(0,0,0,0.1) 1px, transparent 1px)', size: '20px 20px' },
  { name: 'Pattern Grid', url: 'linear-gradient(rgba(0,0,0,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(0,0,0,0.05) 1px, transparent 1px)', size: '20px 20px' },
]

// Load settings from server via store
async function loadSettings() {
  if (!props.conversationId) return
  
  const result = await chatStore.getConversationSettings(props.conversationId)
  if (result.success && result.settings) {
    backgroundImage.value = result.settings.backgroundImage || ''
    backgroundOpacity.value = result.settings.backgroundOpacity ?? 0.1
    backgroundSize.value = result.settings.backgroundSize || ''
    previewUrl.value = result.settings.backgroundImage || ''
    previewSize.value = result.settings.backgroundSize || ''
  } else {
    backgroundImage.value = ''
    backgroundOpacity.value = 0.1
    backgroundSize.value = ''
    previewUrl.value = ''
    previewSize.value = ''
  }
}

// Save settings (broadcasts to all participants)
async function saveSettings() {
  saving.value = true
  
  const settings = {
    backgroundImage: backgroundImage.value,
    backgroundOpacity: backgroundOpacity.value,
    backgroundSize: backgroundSize.value
  }
  
  emit('update', settings)
  emit('close')
  saving.value = false
}

// Handle custom image upload
const fileInput = ref(null)

function handleFileSelect(event) {
  const file = event.target.files?.[0]
  if (!file) return
  
  if (!file.type.startsWith('image/')) {
    alert('Please select an image file')
    return
  }
  
  const reader = new FileReader()
  reader.onload = (e) => {
    backgroundImage.value = e.target.result
    backgroundSize.value = '' // Custom images use cover
    previewUrl.value = e.target.result
    previewSize.value = ''
  }
  reader.readAsDataURL(file)
  event.target.value = ''
}

function selectPreset(preset) {
  backgroundImage.value = preset.url
  backgroundSize.value = preset.size || ''
  previewUrl.value = preset.url
  previewSize.value = preset.size || ''
}

function clearBackground() {
  backgroundImage.value = ''
  backgroundSize.value = ''
  previewUrl.value = ''
  previewSize.value = ''
}

watch(() => props.show, (val) => {
  if (val) loadSettings()
})

onMounted(() => {
  if (props.show) loadSettings()
})
</script>

<template>
  <Modal :show="show" title="Chat Appearance" size="md" @close="emit('close')">
    <div class="space-y-6">
      <!-- Background Preview -->
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Preview</label>
        <div 
          class="h-32 rounded-lg border border-surface-200 dark:border-surface-700 relative overflow-hidden"
          :style="{
            backgroundColor: 'rgb(var(--color-surface))'
          }"
        >
          <!-- Background layer -->
          <div 
            v-if="previewUrl"
            class="absolute inset-0"
            :style="{
              background: previewUrl.startsWith('linear') || previewUrl.startsWith('radial') ? previewUrl : `url(${previewUrl})`,
              backgroundSize: previewSize || 'cover',
              backgroundPosition: 'center',
              opacity: backgroundOpacity
            }"
          ></div>
          <!-- Sample messages -->
          <div class="absolute inset-0 p-3 flex flex-col justify-end gap-2">
            <div class="flex justify-start">
              <div class="bg-surface-200 dark:bg-surface-700/50 px-3 py-1.5 rounded-2xl rounded-bl-md text-sm max-w-[60%]">
                Hey! How are you?
              </div>
            </div>
            <div class="flex justify-end">
              <div class="bg-primary-500/15 border border-primary-500/30 px-3 py-1.5 rounded-2xl rounded-br-md text-sm max-w-[60%]">
                I'm great, thanks!
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Opacity Slider -->
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
          Background Opacity: {{ Math.round(backgroundOpacity * 100) }}%
        </label>
        <input
          type="range"
          v-model.number="backgroundOpacity"
          min="0"
          max="0.5"
          step="0.05"
          class="w-full accent-primary-500"
        />
      </div>
      
      <!-- Preset Backgrounds -->
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Preset Backgrounds</label>
        <div class="grid grid-cols-4 gap-2">
          <button
            v-for="preset in presetBackgrounds"
            :key="preset.name"
            @click="selectPreset(preset)"
            :class="[
              'h-16 rounded-lg border-2 transition-all overflow-hidden',
              backgroundImage === preset.url 
                ? 'border-primary-500 ring-2 ring-primary-500/30' 
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'
            ]"
            :title="preset.name"
          >
            <div 
              v-if="preset.url"
              class="w-full h-full"
              :style="{
                background: preset.url,
                backgroundSize: preset.size || 'cover'
              }"
            ></div>
            <div v-else class="w-full h-full flex items-center justify-center bg-surface-100 dark:bg-surface-800">
              <span class="material-symbols-rounded text-surface-400">block</span>
            </div>
          </button>
        </div>
      </div>
      
      <!-- Custom Image -->
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Custom Image</label>
        <div class="flex gap-2">
          <button
            @click="fileInput?.click()"
            class="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded">upload</span>
            <span>Upload Image</span>
          </button>
          <button
            v-if="backgroundImage"
            @click="clearBackground"
            class="px-4 py-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded">delete</span>
          </button>
        </div>
        <input
          ref="fileInput"
          type="file"
          accept="image/*"
          class="hidden"
          @change="handleFileSelect"
        />
      </div>
    </div>
    
    <template #footer>
      <button class="btn-secondary" @click="emit('close')" :disabled="saving">Cancel</button>
      <button class="btn-primary" @click="saveSettings" :disabled="saving">
        <span v-if="saving" class="material-symbols-rounded animate-spin text-sm mr-1">progress_activity</span>
        {{ saving ? 'Saving...' : 'Save Changes' }}
      </button>
    </template>
  </Modal>
</template>

