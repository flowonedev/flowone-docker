<template>
  <div class="collab-slide-toolbar flex items-center gap-1 px-4 py-2 bg-white border-b border-gray-200 flex-wrap">
    <!-- Insert tools -->
    <div class="toolbar-group">
      <button
        @click="$emit('add-text')"
        class="toolbar-btn"
        title="Add text box"
      >
        <span class="material-symbols-rounded">text_fields</span>
      </button>
      
      <button
        @click="$emit('show-shapes')"
        class="toolbar-btn"
        title="Add shape"
      >
        <span class="material-symbols-rounded">shapes</span>
      </button>
      
      <button
        @click="$emit('add-image')"
        class="toolbar-btn"
        title="Add image"
      >
        <span class="material-symbols-rounded">image</span>
      </button>
    </div>
    
    <div class="toolbar-divider"></div>
    
    <!-- Slide operations -->
    <div class="toolbar-group">
      <button
        @click="$emit('add-slide')"
        class="toolbar-btn"
        title="New slide"
      >
        <span class="material-symbols-rounded">add</span>
        <span class="btn-label">New slide</span>
      </button>
      
      <button
        @click="$emit('show-templates')"
        class="toolbar-btn"
        title="Slide templates"
      >
        <span class="material-symbols-rounded">dashboard</span>
      </button>
    </div>
    
    <div class="toolbar-divider"></div>
    
    <!-- Selection-based tools (only show when object is selected) -->
    <template v-if="hasSelection">
      <!-- Text formatting (for text objects) -->
      <div v-if="selectedObjectType === 'text'" class="toolbar-group">
        <button
          @click="toggleBold"
          class="toolbar-btn"
          :class="{ 'active': isBold }"
          title="Bold (Ctrl+B)"
        >
          <span class="material-symbols-rounded">format_bold</span>
        </button>
        
        <button
          @click="toggleItalic"
          class="toolbar-btn"
          :class="{ 'active': isItalic }"
          title="Italic (Ctrl+I)"
        >
          <span class="material-symbols-rounded">format_italic</span>
        </button>
        
        <button
          @click="toggleUnderline"
          class="toolbar-btn"
          :class="{ 'active': isUnderline }"
          title="Underline (Ctrl+U)"
        >
          <span class="material-symbols-rounded">format_underlined</span>
        </button>
        
        <div class="toolbar-divider"></div>
        
        <!-- Font size -->
        <select
          :value="fontSize"
          @change="setFontSize($event.target.value)"
          class="toolbar-select w-16"
          title="Font size"
        >
          <option v-for="size in fontSizes" :key="size" :value="size">{{ size }}</option>
        </select>
        
        <!-- Text color -->
        <div class="color-picker-wrapper">
          <button class="toolbar-btn color-btn" title="Text color">
            <span class="material-symbols-rounded">format_color_text</span>
            <span class="color-indicator" :style="{ backgroundColor: textColor }"></span>
          </button>
          <input
            type="color"
            :value="textColor"
            @input="setTextColor($event.target.value)"
            class="color-input"
          />
        </div>
        
        <div class="toolbar-divider"></div>
        
        <!-- Text alignment -->
        <button
          @click="setTextAlign('left')"
          class="toolbar-btn"
          :class="{ 'active': textAlign === 'left' }"
          title="Align left"
        >
          <span class="material-symbols-rounded">format_align_left</span>
        </button>
        
        <button
          @click="setTextAlign('center')"
          class="toolbar-btn"
          :class="{ 'active': textAlign === 'center' }"
          title="Align center"
        >
          <span class="material-symbols-rounded">format_align_center</span>
        </button>
        
        <button
          @click="setTextAlign('right')"
          class="toolbar-btn"
          :class="{ 'active': textAlign === 'right' }"
          title="Align right"
        >
          <span class="material-symbols-rounded">format_align_right</span>
        </button>
      </div>
      
      <!-- Shape formatting (for shape objects) -->
      <div v-if="selectedObjectType === 'shape'" class="toolbar-group">
        <!-- Fill color -->
        <div class="color-picker-wrapper">
          <button class="toolbar-btn color-btn" title="Fill color">
            <span class="material-symbols-rounded">format_color_fill</span>
            <span class="color-indicator" :style="{ backgroundColor: fillColor }"></span>
          </button>
          <input
            type="color"
            :value="fillColor"
            @input="setFillColor($event.target.value)"
            class="color-input"
          />
        </div>
        
        <!-- Stroke color -->
        <div class="color-picker-wrapper">
          <button class="toolbar-btn color-btn" title="Stroke color">
            <span class="material-symbols-rounded">border_color</span>
            <span class="color-indicator" :style="{ backgroundColor: strokeColor }"></span>
          </button>
          <input
            type="color"
            :value="strokeColor"
            @input="setStrokeColor($event.target.value)"
            class="color-input"
          />
        </div>
        
        <!-- Stroke width -->
        <select
          :value="strokeWidth"
          @change="setStrokeWidth($event.target.value)"
          class="toolbar-select w-16"
          title="Stroke width"
        >
          <option value="0">None</option>
          <option value="1">1px</option>
          <option value="2">2px</option>
          <option value="3">3px</option>
          <option value="4">4px</option>
          <option value="6">6px</option>
          <option value="8">8px</option>
        </select>
      </div>
      
      <div class="toolbar-divider"></div>
      
      <!-- Position & alignment -->
      <div class="toolbar-group">
        <!-- Align to slide -->
        <button
          @click="alignObject('left')"
          class="toolbar-btn"
          title="Align to slide left"
        >
          <span class="material-symbols-rounded">align_horizontal_left</span>
        </button>
        
        <button
          @click="alignObject('center')"
          class="toolbar-btn"
          title="Align to slide center"
        >
          <span class="material-symbols-rounded">align_horizontal_center</span>
        </button>
        
        <button
          @click="alignObject('right')"
          class="toolbar-btn"
          title="Align to slide right"
        >
          <span class="material-symbols-rounded">align_horizontal_right</span>
        </button>
        
        <button
          @click="alignObject('top')"
          class="toolbar-btn"
          title="Align to slide top"
        >
          <span class="material-symbols-rounded">align_vertical_top</span>
        </button>
        
        <button
          @click="alignObject('middle')"
          class="toolbar-btn"
          title="Align to slide middle"
        >
          <span class="material-symbols-rounded">align_vertical_center</span>
        </button>
        
        <button
          @click="alignObject('bottom')"
          class="toolbar-btn"
          title="Align to slide bottom"
        >
          <span class="material-symbols-rounded">align_vertical_bottom</span>
        </button>
      </div>
      
      <div class="toolbar-divider"></div>
      
      <!-- Layer controls -->
      <div class="toolbar-group">
        <button
          @click="$emit('bring-forward')"
          class="toolbar-btn"
          title="Bring forward"
        >
          <span class="material-symbols-rounded">flip_to_front</span>
        </button>
        
        <button
          @click="$emit('send-backward')"
          class="toolbar-btn"
          title="Send backward"
        >
          <span class="material-symbols-rounded">flip_to_back</span>
        </button>
      </div>
      
      <div class="toolbar-divider"></div>
      
      <!-- Delete -->
      <button
        @click="$emit('delete')"
        class="toolbar-btn text-red-500 hover:bg-red-50"
        title="Delete selected"
      >
        <span class="material-symbols-rounded">delete</span>
      </button>
    </template>
    
    <!-- Right side controls -->
    <div class="ml-auto flex items-center gap-2">
      <!-- Zoom -->
      <div class="toolbar-group">
        <button
          @click="$emit('zoom-out')"
          class="toolbar-btn"
          title="Zoom out"
        >
          <span class="material-symbols-rounded">remove</span>
        </button>
        
        <select
          :value="zoomLevel"
          @change="$emit('zoom-change', parseInt($event.target.value))"
          class="toolbar-select w-20"
          title="Zoom level"
        >
          <option value="50">50%</option>
          <option value="75">75%</option>
          <option value="100">100%</option>
          <option value="125">125%</option>
          <option value="150">150%</option>
          <option value="200">200%</option>
        </select>
        
        <button
          @click="$emit('zoom-in')"
          class="toolbar-btn"
          title="Zoom in"
        >
          <span class="material-symbols-rounded">add</span>
        </button>
        
        <button
          @click="$emit('zoom-fit')"
          class="toolbar-btn"
          title="Fit to screen"
        >
          <span class="material-symbols-rounded">fit_screen</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  selectedObject: {
    type: Object,
    default: null,
  },
  slideWidth: {
    type: Number,
    default: 1920,
  },
  slideHeight: {
    type: Number,
    default: 1080,
  },
  zoomLevel: {
    type: Number,
    default: 100,
  },
})

const emit = defineEmits([
  'add-text',
  'add-image',
  'add-slide',
  'show-shapes',
  'show-templates',
  'update-object',
  'bring-forward',
  'send-backward',
  'delete',
  'zoom-in',
  'zoom-out',
  'zoom-change',
  'zoom-fit',
])

// Font sizes
const fontSizes = [12, 14, 16, 18, 20, 24, 28, 32, 36, 40, 48, 56, 64, 72, 96]

// Computed properties
const hasSelection = computed(() => !!props.selectedObject)
const selectedObjectType = computed(() => props.selectedObject?.type)

// Text properties
const isBold = computed(() => props.selectedObject?.fontWeight === 'bold')
const isItalic = computed(() => props.selectedObject?.fontStyle === 'italic')
const isUnderline = computed(() => props.selectedObject?.textDecoration === 'underline')
const fontSize = computed(() => props.selectedObject?.fontSize || 24)
const textColor = computed(() => props.selectedObject?.color || '#000000')
const textAlign = computed(() => props.selectedObject?.textAlign || 'left')

// Shape properties
const fillColor = computed(() => props.selectedObject?.fill || '#2196F3')
const strokeColor = computed(() => props.selectedObject?.stroke || '#1976D2')
const strokeWidth = computed(() => props.selectedObject?.strokeWidth || 2)

// Text formatting methods
function toggleBold() {
  emit('update-object', { fontWeight: isBold.value ? 'normal' : 'bold' })
}

function toggleItalic() {
  emit('update-object', { fontStyle: isItalic.value ? 'normal' : 'italic' })
}

function toggleUnderline() {
  emit('update-object', { textDecoration: isUnderline.value ? 'none' : 'underline' })
}

function setFontSize(size) {
  emit('update-object', { fontSize: parseInt(size) })
}

function setTextColor(color) {
  emit('update-object', { color })
}

function setTextAlign(align) {
  emit('update-object', { textAlign: align })
}

// Shape formatting methods
function setFillColor(color) {
  emit('update-object', { fill: color })
}

function setStrokeColor(color) {
  emit('update-object', { stroke: color })
}

function setStrokeWidth(width) {
  emit('update-object', { strokeWidth: parseInt(width) })
}

// Alignment methods
function alignObject(position) {
  if (!props.selectedObject) return
  
  const obj = props.selectedObject
  let updates = {}
  
  switch (position) {
    case 'left':
      updates.x = 40 // padding
      break
    case 'center':
      updates.x = (props.slideWidth - obj.width) / 2
      break
    case 'right':
      updates.x = props.slideWidth - obj.width - 40
      break
    case 'top':
      updates.y = 40
      break
    case 'middle':
      updates.y = (props.slideHeight - obj.height) / 2
      break
    case 'bottom':
      updates.y = props.slideHeight - obj.height - 40
      break
  }
  
  emit('update-object', updates)
}
</script>

<style scoped>
.collab-slide-toolbar {
  user-select: none;
}

.toolbar-group {
  display: flex;
  align-items: center;
  gap: 2px;
}

.toolbar-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 6px;
  border: none;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
  color: #4b5563;
}

.toolbar-btn:hover {
  background: #f3f4f6;
  color: #1f2937;
}

.dark .toolbar-btn:hover {
  background: #374151;
  color: #f9fafb;
}

.toolbar-btn.active {
  background: #e0e7ff;
  color: #4f46e5;
}

.dark .toolbar-btn.active {
  background: #3730a3;
  color: #c7d2fe;
}

.toolbar-btn .material-symbols-rounded {
  font-size: 20px;
}

.btn-label {
  font-size: 13px;
  font-weight: 500;
}

.toolbar-divider {
  width: 1px;
  height: 24px;
  background: #e5e7eb;
  margin: 0 8px;
}

.dark .toolbar-divider {
  background: #4b5563;
}

.toolbar-select {
  padding: 4px 8px;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  background: white;
  font-size: 13px;
  cursor: pointer;
}

.dark .toolbar-select {
  background: #374151;
  border-color: #4b5563;
  color: #f9fafb;
}

.color-picker-wrapper {
  position: relative;
}

.color-btn {
  position: relative;
}

.color-indicator {
  position: absolute;
  bottom: 4px;
  left: 50%;
  transform: translateX(-50%);
  width: 14px;
  height: 3px;
  border-radius: 1px;
}

.color-input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
  width: 100%;
  height: 100%;
}
</style>

