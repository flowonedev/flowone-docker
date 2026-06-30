<template>
  <Teleport to="body">
    <div 
      class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/50"
      @click.self="$emit('close')"
    >
      <div class="bg-white rounded-xl shadow-2xl w-[400px] overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200">
          <h2 class="text-lg font-semibold text-surface-900 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500" style="font-size: 24px;">bookmark_add</span>
            Save as Template
          </h2>
          <button @click="$emit('close')" class="p-1.5 hover:bg-surface-100 rounded-full transition-colors">
            <span class="material-symbols-rounded text-surface-500">close</span>
          </button>
        </div>

        <!-- Content -->
        <div class="p-5 space-y-4">
          <!-- Preview -->
          <div 
            class="aspect-video rounded-xl overflow-hidden border border-surface-200"
            :style="previewBackgroundStyle"
          >
            <div class="relative w-full h-full">
              <div 
                v-for="(obj, i) in previewObjects" 
                :key="i"
                class="absolute"
                :style="getPreviewObjectStyle(obj)"
              ></div>
            </div>
          </div>

          <!-- Template name -->
          <div>
            <label class="block text-sm font-medium text-surface-700 mb-1.5">
              Template Name
            </label>
            <input
              v-model="templateName"
              type="text"
              class="w-full px-4 py-2.5 border border-surface-300 rounded-full bg-white text-surface-900 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="My Custom Template"
              @keydown.enter="saveTemplate"
            />
          </div>

          <!-- Icon selector -->
          <div>
            <label class="block text-sm font-medium text-surface-700 mb-1.5">
              Icon
            </label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="icon in iconOptions"
                :key="icon"
                @click="selectedIcon = icon"
                class="w-10 h-10 flex items-center justify-center rounded-full border-2 transition-all"
                :class="selectedIcon === icon 
                  ? 'border-primary-500 bg-primary-50 text-primary-600' 
                  : 'border-surface-200 text-surface-500 hover:border-surface-300'"
              >
                <span class="material-symbols-rounded" style="font-size: 22px;">{{ icon }}</span>
              </button>
            </div>
          </div>

          <!-- Info text -->
          <p class="text-xs text-surface-500">
            This template will be saved locally and available only on this device.
          </p>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-surface-200 bg-surface-50/50">
          <button
            @click="$emit('close')"
            class="px-4 py-2 text-sm text-surface-600 hover:bg-surface-100 rounded-full transition-colors"
          >
            Cancel
          </button>
          <button
            @click="saveTemplate"
            :disabled="!templateName.trim()"
            class="px-5 py-2 text-sm font-medium bg-primary-500 hover:bg-primary-600 text-white rounded-full transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Save Template
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue'
import { saveCustomTemplate } from '../data/slideTemplates.js'

const props = defineProps({
  slideObjects: {
    type: Array,
    default: () => []
  },
  slideBackground: {
    type: Object,
    default: null
  },
  slideWidth: {
    type: Number,
    default: 1920
  },
  slideHeight: {
    type: Number,
    default: 1080
  }
})

const emit = defineEmits(['close', 'saved'])

// State
const templateName = ref('')
const selectedIcon = ref('style')

// Icon options
const iconOptions = [
  'style',
  'dashboard',
  'view_agenda',
  'view_column',
  'grid_view',
  'view_module',
  'image',
  'format_quote',
  'title',
  'article',
  'analytics',
  'pie_chart',
]

// Preview objects (scaled down)
const previewObjects = computed(() => {
  return props.slideObjects.slice(0, 8) // Limit for performance
})

// Preview background style
const previewBackgroundStyle = computed(() => {
  const bg = props.slideBackground
  if (!bg) {
    return { backgroundColor: '#f3f4f6' } // gray-100
  }
  
  if (bg.type === 'gradient') {
    return { background: bg.value }
  } else if (bg.type === 'image') {
    return {
      backgroundImage: `url(${bg.value})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    }
  }
  return { backgroundColor: bg.value || '#f3f4f6' }
})

// Get preview object style (scaled)
function getPreviewObjectStyle(obj) {
  const scaleX = 100 / props.slideWidth
  const scaleY = 100 / props.slideHeight
  const scale = Math.min(scaleX, scaleY) * 3 // Scale factor for preview
  
  const style = {
    left: `${obj.x * scale}%`,
    top: `${obj.y * scale}%`,
    width: `${obj.width * scale}%`,
    height: `${obj.height * scale}%`,
  }
  
  if (obj.type === 'shape') {
    style.backgroundColor = obj.fill || '#e5e7eb'
    if (obj.shapeType === 'ellipse') {
      style.borderRadius = '50%'
    } else if (obj.borderRadius) {
      style.borderRadius = `${obj.borderRadius * scale}px`
    }
  } else if (obj.type === 'text') {
    style.backgroundColor = '#d1d5db'
  } else if (obj.type === 'image') {
    style.backgroundColor = '#9ca3af'
  }
  
  return style
}

// Save template
function saveTemplate() {
  if (!templateName.value.trim()) return
  
  try {
    const template = saveCustomTemplate(
      templateName.value.trim(),
      props.slideObjects,
      { 
        icon: selectedIcon.value,
        background: props.slideBackground
      }
    )
    
    emit('saved', template)
    emit('close')
  } catch (e) {
    console.error('Failed to save template:', e)
    // Could show a toast notification here
  }
}
</script>

