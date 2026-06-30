<template>
  <div class="collab-slide-thumbnails w-64 bg-white border-r border-gray-200 flex flex-col overflow-hidden">
    <!-- Header -->
    <div class="p-3 border-b border-gray-200 flex items-center justify-between">
      <span class="text-sm font-medium text-gray-700">Slides</span>
      <button
        v-if="canEdit"
        @click="$emit('add')"
        class="p-1 hover:bg-gray-100 rounded transition-colors"
        title="Add slide"
      >
        <span class="material-symbols-rounded text-gray-500" style="font-size: 20px;">add</span>
      </button>
    </div>
    
    <!-- Slides list -->
    <div class="flex-1 overflow-y-auto p-2 space-y-2">
      <div
        v-for="(slide, index) in slides"
        :key="slide.id"
        class="collab-thumbnail group relative"
        :class="{ 
          'ring-2 ring-blue-500': index === currentIndex,
          'opacity-60': draggingIndex === index,
        }"
        :draggable="canEdit"
        @click="$emit('select', index)"
        @dragstart="handleDragStart(index, $event)"
        @dragover.prevent="handleDragOver(index, $event)"
        @dragend="handleDragEnd"
        @drop="handleDrop(index)"
      >
        <!-- Slide number -->
        <div class="absolute top-1 left-1 w-5 h-5 bg-black/50 text-white text-xs rounded flex items-center justify-center z-10">
          {{ index + 1 }}
        </div>
        
        <!-- Users viewing this slide -->
        <div 
          v-if="userSlidePositions[index] && userSlidePositions[index].length > 0"
          class="absolute bottom-1 left-1 flex -space-x-1.5 z-10"
        >
          <div
            v-for="user in userSlidePositions[index].slice(0, 3)"
            :key="user.clientId"
            class="w-5 h-5 rounded-full border-2 border-white flex items-center justify-center text-[8px] font-bold text-white"
            :style="{ backgroundColor: user.color }"
            :title="user.userName"
          >
            {{ user.userName.charAt(0).toUpperCase() }}
          </div>
          <div
            v-if="userSlidePositions[index].length > 3"
            class="w-5 h-5 rounded-full bg-gray-500 border-2 border-white flex items-center justify-center text-[8px] font-bold text-white"
          >
            +{{ userSlidePositions[index].length - 3 }}
          </div>
        </div>
        
        <!-- Thumbnail preview -->
        <div 
          class="aspect-video bg-white rounded overflow-hidden border border-gray-200 cursor-pointer hover:border-blue-300 transition-colors"
          :style="getThumbnailBackground(slide)"
        >
          <!-- Mini preview of objects -->
          <svg 
            class="w-full h-full"
            :viewBox="`0 0 1920 1080`"
            preserveAspectRatio="xMidYMid meet"
          >
            <!-- Render mini shapes -->
            <g v-for="obj in slide.objects" :key="obj.id">
              <rect
                v-if="obj.type === 'shape' && obj.shapeType === 'rectangle'"
                :x="obj.x"
                :y="obj.y"
                :width="obj.width"
                :height="obj.height"
                :fill="obj.fill"
                :rx="obj.borderRadius || 0"
              />
              <ellipse
                v-else-if="obj.type === 'shape' && obj.shapeType === 'ellipse'"
                :cx="obj.x + obj.width / 2"
                :cy="obj.y + obj.height / 2"
                :rx="obj.width / 2"
                :ry="obj.height / 2"
                :fill="obj.fill"
              />
              <!-- Text objects - render actual text -->
              <g v-else-if="obj.type === 'text'">
                <foreignObject
                  :x="obj.x"
                  :y="obj.y"
                  :width="obj.width"
                  :height="obj.height"
                >
                  <div 
                    xmlns="http://www.w3.org/1999/xhtml"
                    :style="{
                      width: '100%',
                      height: '100%',
                      fontSize: (obj.fontSize || 24) + 'px',
                      fontFamily: obj.fontFamily || 'Inter, sans-serif',
                      fontWeight: obj.fontWeight || 'normal',
                      fontStyle: obj.fontStyle || 'normal',
                      color: obj.color || '#000000',
                      textAlign: obj.textAlign || 'left',
                      lineHeight: obj.lineHeight || 1.4,
                      letterSpacing: (obj.letterSpacing || 0) + 'px',
                      textTransform: obj.textTransform || 'none',
                      overflow: 'hidden',
                      display: 'flex',
                      alignItems: 'flex-start',
                      justifyContent: obj.textAlign === 'center' ? 'center' : obj.textAlign === 'right' ? 'flex-end' : 'flex-start',
                    }"
                    v-html="formatTextContent(obj.content || obj.text || '')"
                  ></div>
                </foreignObject>
              </g>
              <!-- Image objects -->
              <image
                v-else-if="obj.type === 'image' && obj.src"
                :x="obj.x"
                :y="obj.y"
                :width="obj.width"
                :height="obj.height"
                :href="obj.src"
                preserveAspectRatio="xMidYMid slice"
              />
              <rect
                v-else-if="obj.type === 'image' && !obj.src"
                :x="obj.x"
                :y="obj.y"
                :width="obj.width"
                :height="obj.height"
                fill="#d1d5db"
              />
            </g>
          </svg>
        </div>
        
        <!-- Actions (on hover) -->
        <div 
          v-if="canEdit && slides.length > 1"
          class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity flex gap-1 z-10"
        >
          <button
            @click.stop="$emit('duplicate', index)"
            class="p-1 bg-white rounded shadow hover:bg-gray-100"
            title="Duplicate slide"
          >
            <span class="material-symbols-rounded text-gray-600" style="font-size: 14px;">content_copy</span>
          </button>
          <button
            @click.stop="$emit('delete', index)"
            class="p-1 bg-white rounded shadow hover:bg-red-100"
            title="Delete slide"
          >
            <span class="material-symbols-rounded text-red-500" style="font-size: 14px;">delete</span>
          </button>
        </div>
        
        <!-- Drop indicator -->
        <div 
          v-if="dropTargetIndex === index && dropTargetIndex !== draggingIndex"
          class="absolute -top-1 left-0 right-0 h-0.5 bg-blue-500"
        ></div>
      </div>
      
      <!-- Add slide button at bottom -->
      <button
        v-if="canEdit"
        @click="$emit('add')"
        class="w-full aspect-video border-2 border-dashed border-gray-300 rounded flex items-center justify-center text-gray-400 hover:border-blue-300 hover:text-blue-400 transition-colors"
      >
        <span class="material-symbols-rounded" style="font-size: 32px;">add</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  slides: {
    type: Array,
    default: () => [],
  },
  currentIndex: {
    type: Number,
    default: 0,
  },
  canEdit: {
    type: Boolean,
    default: true,
  },
  userSlidePositions: {
    type: Object,
    default: () => ({}),
  },
})

const emit = defineEmits(['select', 'add', 'delete', 'duplicate', 'reorder'])

// Drag state
const draggingIndex = ref(null)
const dropTargetIndex = ref(null)

// Format text content for display in thumbnails
function formatTextContent(content) {
  if (!content) return ''
  // Content may already be HTML from rich text editor - strip tags for clean thumbnail display
  // First decode any HTML entities, then strip tags
  const temp = document.createElement('div')
  temp.innerHTML = content
  const plainText = temp.textContent || temp.innerText || ''
  // Truncate long text for thumbnail and convert newlines
  return plainText.substring(0, 500).replace(/\n/g, '<br>')
}

// Get thumbnail background style
function getThumbnailBackground(slide) {
  const bg = slide.background || { type: 'solid', value: '#ffffff' }
  
  if (bg.type === 'gradient') {
    return { background: bg.value }
  } else if (bg.type === 'image') {
    return { 
      backgroundImage: `url(${bg.value})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    }
  }
  return { backgroundColor: bg.value }
}

// Drag handlers
function handleDragStart(index, e) {
  draggingIndex.value = index
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', index.toString())
}

function handleDragOver(index, e) {
  if (draggingIndex.value === null) return
  if (draggingIndex.value === index) return
  
  dropTargetIndex.value = index
}

function handleDrop(index) {
  if (draggingIndex.value === null || draggingIndex.value === index) return
  
  emit('reorder', draggingIndex.value, index)
}

function handleDragEnd() {
  draggingIndex.value = null
  dropTargetIndex.value = null
}
</script>

<style>
.collab-slide-thumbnails {
  scrollbar-width: thin;
}

.collab-slide-thumbnails::-webkit-scrollbar {
  width: 6px;
}

.collab-slide-thumbnails::-webkit-scrollbar-track {
  background: transparent;
}

.collab-slide-thumbnails::-webkit-scrollbar-thumb {
  background: #d1d5db;
  border-radius: 3px;
}
</style>

