<template>
  <div 
    class="collab-slide-canvas relative"
    :style="canvasContainerStyle"
    @click="handleCanvasClick"
    @mousemove="handleMouseMove"
    @mouseleave="handleMouseLeave"
  >
    <!-- Slide background -->
    <div 
      class="absolute inset-0 shadow-2xl overflow-hidden"
      :style="{ ...slideBackgroundStyle, borderRadius: `${Math.round(16 * scale)}px` }"
    >
      <!-- DOM layer for text and images -->
      <div class="absolute inset-0" style="z-index: 1;" :style="{ transform: `scale(${scale})`, transformOrigin: 'top left' }">
        <div
          v-for="obj in nonShapeObjects"
          :key="obj.id"
          class="absolute group"
          :class="{ 
            'cursor-move': canEdit && !isEditingText(obj.id),
          }"
          :style="getObjectStyle(obj)"
          @mousedown.stop="handleObjectMouseDown(obj, $event)"
          @click.stop="handleObjectClick(obj, $event)"
          @dblclick.stop="handleObjectDoubleClick(obj, $event)"
          @contextmenu.stop.prevent="handleContextMenu(obj, $event)"
        >
          <!-- Selection border - always visible with dashed border, solid when selected -->
          <div 
            class="absolute -inset-2 rounded pointer-events-none transition-all"
            :class="isSelected(obj.id) 
              ? 'border-2 border-blue-500' 
              : 'border border-dashed border-gray-400/50 group-hover:border-blue-400'"
          ></div>
          
          <!-- Text object -->
          <div 
            v-if="obj.type === 'text'"
            class="w-full h-full overflow-hidden"
            :class="getListClass(obj)"
            :style="getTextStyle(obj)"
          >
            <!-- Display mode - shows Y.js content -->
            <div 
              v-if="editingTextId !== obj.id"
              class="w-full h-full"
              v-html="formatTextContent(obj)"
            ></div>
            <!-- Edit mode - isolated from Vue reactivity -->
            <div 
              v-else
              :ref="el => setTextEditorRef(el, obj.id)"
              contenteditable="true"
              class="w-full h-full outline-none cursor-text"
              :class="getListClass(obj)"
              style="user-select: text; -webkit-user-select: text; direction: ltr; unicode-bidi: normal;"
              @mousedown.stop
              @keydown.stop
              @blur="finishTextEdit"
              @input="onTextInput"
            ></div>
          </div>
          
          <!-- Image object -->
          <img 
            v-if="obj.type === 'image'"
            :src="obj.imageUrl"
            :style="getImageStyle(obj)"
            class="w-full h-full"
            draggable="false"
          />
          
          <!-- Resize handles - ALWAYS visible when selected -->
          <template v-if="isSelected(obj.id) && canEdit">
            <!-- Corner handles - larger for easier grabbing -->
            <div 
              v-for="handle in resizeHandles"
              :key="handle.position"
              class="absolute w-5 h-5 bg-white border-2 border-blue-500 rounded shadow-md hover:bg-blue-100 hover:scale-125 transition-all z-50"
              :style="getHandleStyle(handle)"
              @mousedown.stop.prevent="startResize(obj, handle.position, $event)"
            ></div>
            
            <!-- Rotation handle with line connector -->
            <div class="absolute -top-12 left-1/2 -translate-x-1/2 flex flex-col items-center z-50">
              <div 
                class="w-7 h-7 bg-white border-2 border-blue-500 rounded-full cursor-grab flex items-center justify-center shadow-md hover:bg-blue-100 hover:scale-125 transition-all"
                @mousedown.stop.prevent="startRotate(obj, $event)"
              >
                <span class="material-symbols-rounded text-blue-500" style="font-size: 18px;">rotate_right</span>
              </div>
              <div class="w-0.5 h-4 bg-blue-500"></div>
            </div>
          </template>
        </div>
      </div>
      
      <!-- SVG layer for shapes (on top of DOM layer) -->
      <svg 
        class="absolute inset-0 w-full h-full"
        style="pointer-events: none; z-index: 10;"
        :viewBox="`0 0 ${slideWidth} ${slideHeight}`"
        preserveAspectRatio="xMidYMid meet"
      >
        <!-- Render shapes - each shape has pointer-events:all to receive clicks -->
        <g 
          v-for="obj in shapeObjects" 
          :key="obj.id"
          :style="{ pointerEvents: 'all', cursor: canEdit ? 'pointer' : 'default' }"
          @mousedown.stop.prevent="handleShapeMouseDown(obj, $event)"
          @click.stop="handleShapeClick(obj, $event)"
          @contextmenu.stop.prevent="handleContextMenu(obj, $event)"
        >
          <CollabSlideShape
            :object="obj"
            :selected="isSelected(obj.id)"
            :scale="scale"
          />
          
          <!-- Resize handles for selected shapes -->
          <g v-if="isSelected(obj.id) && canEdit">
            <!-- Corner handles -->
            <rect
              v-for="handle in shapeResizeHandles"
              :key="`${obj.id}-${handle.position}`"
              :x="getShapeHandleX(obj, handle.position) - 6"
              :y="getShapeHandleY(obj, handle.position) - 6"
              width="12"
              height="12"
              fill="white"
              stroke="#2196F3"
              stroke-width="2"
              rx="2"
              :style="{ cursor: handle.cursor, pointerEvents: 'all' }"
              @mousedown.stop.prevent="startShapeResize(obj, handle.position, $event)"
            />
            
            <!-- Rotation handle -->
            <g 
              :transform="`translate(${obj.x + obj.width / 2}, ${obj.y - 35})`"
              style="pointer-events: all; cursor: grab;"
              @mousedown.stop.prevent="startShapeRotate(obj, $event)"
            >
              <line x1="0" y1="25" x2="0" y2="10" stroke="#2196F3" stroke-width="2" />
              <circle r="10" fill="white" stroke="#2196F3" stroke-width="2" />
              <path d="M -5 0 A 5 5 0 1 1 5 0" fill="none" stroke="#2196F3" stroke-width="2" />
              <path d="M 5 -3 L 5 3 L 8 0 Z" fill="#2196F3" />
            </g>
          </g>
        </g>
      </svg>
      
      <!-- Remote cursors overlay -->
      <CollabCursors
        :cursors="cursors"
        :objectPositions="objectPositions"
        :scale="scale"
      />
    </div>
    
    <!-- Context menu (outside slide container for proper positioning) -->
    <CollabContextMenu
      :show="showContextMenu"
      :x="contextMenuX"
      :y="contextMenuY"
      :objectType="contextMenuObjectType"
      @close="closeContextMenu"
      @action="handleContextAction"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import CollabSlideShape from './CollabSlideShape.vue'
import CollabCursors from './CollabCursors.vue'
import CollabContextMenu from './CollabContextMenu.vue'

const props = defineProps({
  slide: {
    type: Object,
    required: true,
  },
  slideIndex: {
    type: Number,
    default: 0,
  },
  slideWidth: {
    type: Number,
    default: 1920,
  },
  slideHeight: {
    type: Number,
    default: 1080,
  },
  selectedIds: {
    type: Array,
    default: () => [],
  },
  canEdit: {
    type: Boolean,
    default: true,
  },
  cursors: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['select', 'deselect', 'update', 'cursor-move', 'duplicate-drag', 'context-action'])

// Local state
const containerWidth = ref(800)
const containerHeight = ref(600)
const editingTextId = ref(null)
const editingTextContent = ref('')
const textEditorRefs = ref({})

// Function ref for text editor
function setTextEditorRef(el, objId) {
  if (el) {
    textEditorRefs.value[objId] = el
  } else {
    delete textEditorRefs.value[objId]
  }
}

// Drag state
const isDragging = ref(false)
const dragObject = ref(null)
const dragStart = ref({ x: 0, y: 0 })
const dragObjectStart = ref({ x: 0, y: 0 })
const dragSelectedStarts = ref({}) // Store starting positions of ALL selected objects for multi-drag
const hasMoved = ref(false) // Track if mouse actually moved (to distinguish click from drag)
const DRAG_THRESHOLD = 3 // Minimum pixels to move before considering it a drag
const isAltDrag = ref(false) // Track if Alt was pressed for duplicate-drag
const altDragDuplicated = ref(false) // Track if we've already created the duplicate
const handledByMouseUp = ref(false) // Track if click was already handled by mouseup

// Resize state
const isResizing = ref(false)
const resizeObject = ref(null)
const resizeHandle = ref(null)
const resizeStart = ref({ x: 0, y: 0, width: 0, height: 0, objX: 0, objY: 0 })

// Rotation state
const isRotating = ref(false)
const rotateObject = ref(null)
const rotateStart = ref({ angle: 0, centerX: 0, centerY: 0 })

// Context menu state
const showContextMenu = ref(false)
const contextMenuX = ref(0)
const contextMenuY = ref(0)
const contextMenuObjectId = ref(null)
const contextMenuObjectType = ref(null)

// Computed
const scale = computed(() => {
  // Fit slide to container with padding
  const padding = 20
  const maxWidth = containerWidth.value - padding * 2
  const maxHeight = containerHeight.value - padding * 2
  
  if (maxWidth <= 0 || maxHeight <= 0) return 0.5
  
  const scaleX = maxWidth / props.slideWidth
  const scaleY = maxHeight / props.slideHeight
  
  // Use the smaller scale to ensure slide fits within container
  return Math.min(scaleX, scaleY, 1)
})

const canvasContainerStyle = computed(() => ({
  width: `${props.slideWidth * scale.value}px`,
  height: `${props.slideHeight * scale.value}px`,
}))

const slideBackgroundStyle = computed(() => {
  const bg = props.slide.background || { type: 'solid', value: '#ffffff' }
  
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
})

const shapeObjects = computed(() => {
  return (props.slide.objects || [])
    .filter(o => o.type === 'shape')
    .sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0)) // Sort by zIndex for proper layering
})

const nonShapeObjects = computed(() => {
  return (props.slide.objects || [])
    .filter(o => o.type !== 'shape')
    .sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0)) // Sort by zIndex for proper layering
})

// Get the selected object for context menu
const selectedObject = computed(() => {
  if (props.selectedIds.length !== 1) return null
  return (props.slide.objects || []).find(o => o.id === props.selectedIds[0])
})

const objectPositions = computed(() => {
  const positions = {}
  for (const obj of props.slide.objects || []) {
    positions[obj.id] = {
      x: obj.x,
      y: obj.y,
      width: obj.width,
      height: obj.height,
    }
  }
  return positions
})

const resizeHandles = [
  { position: 'nw', cursor: 'nwse-resize' },
  { position: 'n', cursor: 'ns-resize' },
  { position: 'ne', cursor: 'nesw-resize' },
  { position: 'e', cursor: 'ew-resize' },
  { position: 'se', cursor: 'nwse-resize' },
  { position: 's', cursor: 'ns-resize' },
  { position: 'sw', cursor: 'nesw-resize' },
  { position: 'w', cursor: 'ew-resize' },
]

// Shape resize handles (for SVG shapes)
const shapeResizeHandles = [
  { position: 'nw', cursor: 'nwse-resize' },
  { position: 'ne', cursor: 'nesw-resize' },
  { position: 'se', cursor: 'nwse-resize' },
  { position: 'sw', cursor: 'nesw-resize' },
]

// Methods
function isSelected(id) {
  return props.selectedIds.includes(id)
}

function isEditingText(id) {
  return editingTextId.value === id
}

// Handle mouse down on object (prepare for potential drag)
function handleObjectMouseDown(obj, e) {
  if (!props.canEdit) return
  
  // If we're editing this text, don't interfere
  if (editingTextId.value === obj.id) return
  
  // Prepare for potential drag - don't start dragging yet
  dragObject.value = obj
  dragStart.value = { x: e.clientX, y: e.clientY }
  dragObjectStart.value = { x: obj.x, y: obj.y }
  hasMoved.value = false
  isDragging.value = false
  isAltDrag.value = e.altKey // Track if Alt is pressed for duplicate-drag
  altDragDuplicated.value = false
  
  // Store starting positions of ALL selected objects for multi-drag
  dragSelectedStarts.value = {}
  if (isSelected(obj.id) && props.selectedIds.length > 1) {
    // Object is already selected and there are multiple selections - prepare for multi-drag
    for (const id of props.selectedIds) {
      const selectedObj = (props.slide.objects || []).find(o => o.id === id)
      if (selectedObj) {
        dragSelectedStarts.value[id] = { x: selectedObj.x, y: selectedObj.y }
      }
    }
  }
  
  // Add listeners to track mouse movement
  document.addEventListener('mouseup', handleObjectMouseUp)
  document.addEventListener('mousemove', handleObjectDrag)
}

// Handle mouse up on object (determine if it was a click or drag)
function handleObjectMouseUp(e) {
  document.removeEventListener('mouseup', handleObjectMouseUp)
  document.removeEventListener('mousemove', handleObjectDrag)
  
  const obj = dragObject.value
  if (!obj) return
  
  // Mark that we're handling this interaction (prevents click handler from re-handling)
  handledByMouseUp.value = true
  // Reset after a short delay (after click event would have fired)
  setTimeout(() => { handledByMouseUp.value = false }, 50)
  
  // If we didn't move (or moved very little), treat as click
  if (!hasMoved.value) {
    // Handle as click
    if (editingTextId.value === obj.id) {
      // Already editing, do nothing
    } else if (obj.type === 'text' && isSelected(obj.id)) {
      // Text object already selected - start editing on SECOND click
      startTextEdit(obj)
    } else {
      // First click - just select the object (don't edit yet)
      // Support both Shift+Click and Ctrl+Click for multi-selection
      const addToSelection = e.shiftKey || e.ctrlKey || e.metaKey
      selectObject(obj.id, addToSelection)
    }
  }
  
  // Reset drag state
  isDragging.value = false
  dragObject.value = null
  hasMoved.value = false
}

// Handle click on object - now mostly handled by mouseup
function handleObjectClick(obj, e) {
  // Skip if already handled by mouseup (which fires before click)
  if (handledByMouseUp.value) return
  
  // Click events are now handled in handleObjectMouseUp
  // This is kept for cases where mousedown didn't fire (e.g., programmatic clicks)
  if (!props.canEdit) return
  if (editingTextId.value === obj.id) return
  
  // Only handle if we're not in drag mode
  if (!isDragging.value && !hasMoved.value) {
    if (obj.type === 'text' && isSelected(obj.id)) {
      startTextEdit(obj)
    } else if (!isSelected(obj.id)) {
      // Support both Shift+Click and Ctrl+Click for multi-selection
      const addToSelection = e.shiftKey || e.ctrlKey || e.metaKey
      selectObject(obj.id, addToSelection)
    }
  }
}

// Handle double-click - immediately enter edit mode for text
function handleObjectDoubleClick(obj, e) {
  if (!props.canEdit) return
  
  if (obj.type === 'text') {
    // Select and immediately start editing
    if (!isSelected(obj.id)) {
      selectObject(obj.id, false)
    }
    startTextEdit(obj)
  }
}

function getObjectStyle(obj) {
  return {
    left: `${obj.x}px`,
    top: `${obj.y}px`,
    width: `${obj.width}px`,
    height: `${obj.height}px`,
    transform: obj.rotation ? `rotate(${obj.rotation}deg)` : undefined,
    zIndex: obj.zIndex || 0,
  }
}

function getTextStyle(obj) {
  const borderWidth = Number(obj.borderWidth || 0)
  const borderColor = obj.borderColor || 'transparent'

  return {
    fontSize: `${obj.fontSize || 24}px`,
    fontFamily: obj.fontFamily || 'Inter',
    fontWeight: obj.fontWeight || 'normal',
    fontStyle: obj.fontStyle || 'normal',
    textAlign: obj.textAlign || 'left',
    color: obj.color || '#000000',
    backgroundColor: obj.backgroundColor || 'transparent',
    letterSpacing: obj.letterSpacing ? `${obj.letterSpacing}px` : 'normal',
    lineHeight: obj.lineHeight || 1.4,
    textDecoration: obj.textDecoration || 'none',
    textTransform: obj.textTransform || 'none',
    padding: '8px',
    border: borderWidth > 0 && borderColor !== 'transparent' ? `${borderWidth}px solid ${borderColor}` : 'none',
    borderRadius: obj.borderRadius ? `${obj.borderRadius}px` : '0',
    boxSizing: 'border-box',
  }
}

function getListClass(obj) {
  if (obj.listType === 'bullet') return 'list-style-bullet'
  if (obj.listType === 'numbered') return 'list-style-numbered'
  return ''
}

function formatTextContent(obj) {
  const content = obj.content || ''
  const listType = obj.listType
  
  // If no list type or content is already formatted, return as-is
  if (!listType || listType === 'none') {
    return content
  }
  
  // Check if content is already a list
  if (content.includes('<ul>') || content.includes('<ol>')) {
    return content
  }
  
  // Split content by line breaks or paragraphs and wrap in list items
  // Extract text from paragraphs
  const tempDiv = document.createElement('div')
  tempDiv.innerHTML = content
  
  // Get text nodes and paragraphs
  const lines = []
  const walker = document.createTreeWalker(tempDiv, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT)
  let node
  let currentText = ''
  
  while (node = walker.nextNode()) {
    if (node.nodeType === Node.TEXT_NODE) {
      currentText += node.textContent
    } else if (node.tagName === 'P' || node.tagName === 'BR' || node.tagName === 'DIV') {
      if (currentText.trim()) {
        lines.push(currentText.trim())
        currentText = ''
      }
    }
  }
  if (currentText.trim()) {
    lines.push(currentText.trim())
  }
  
  // If we couldn't extract lines, just return content
  if (lines.length === 0) {
    return content
  }
  
  // Wrap in appropriate list
  const tag = listType === 'bullet' ? 'ul' : 'ol'
  const listItems = lines.map(line => `<li>${line}</li>`).join('')
  return `<${tag} class="slide-list">${listItems}</${tag}>`
}

function getImageStyle(obj) {
  return {
    objectFit: obj.objectFit || 'contain',
    objectPosition: obj.objectPosition || '50% 50%',
    borderRadius: obj.borderRadius ? `${obj.borderRadius}px` : '0',
    display: 'block',
  }
}

function getHandleStyle(handle) {
  // Handles are 20px (w-5), so offset by -10px to center on corners
  const positions = {
    nw: { top: '-10px', left: '-10px', cursor: 'nwse-resize' },
    n: { top: '-10px', left: '50%', transform: 'translateX(-50%)', cursor: 'ns-resize' },
    ne: { top: '-10px', right: '-10px', cursor: 'nesw-resize' },
    e: { top: '50%', right: '-10px', transform: 'translateY(-50%)', cursor: 'ew-resize' },
    se: { bottom: '-10px', right: '-10px', cursor: 'nwse-resize' },
    s: { bottom: '-10px', left: '50%', transform: 'translateX(-50%)', cursor: 'ns-resize' },
    sw: { bottom: '-10px', left: '-10px', cursor: 'nesw-resize' },
    w: { top: '50%', left: '-10px', transform: 'translateY(-50%)', cursor: 'ew-resize' },
  }
  return positions[handle.position]
}

// Shape handle position helpers (for SVG shapes)
function getShapeHandleX(obj, position) {
  switch (position) {
    case 'nw':
    case 'sw':
      return obj.x
    case 'ne':
    case 'se':
      return obj.x + obj.width
    default:
      return obj.x + obj.width / 2
  }
}

function getShapeHandleY(obj, position) {
  switch (position) {
    case 'nw':
    case 'ne':
      return obj.y
    case 'sw':
    case 'se':
      return obj.y + obj.height
    default:
      return obj.y + obj.height / 2
  }
}

// Shape resize handler
function startShapeResize(obj, handle, e) {
  if (!props.canEdit) return
  
  // Store the SVG element for coordinate conversion
  shapeSvgElement = e.target.closest('svg')
  
  // Convert screen coords to SVG coords
  const svgPoint = screenToSVG(e.clientX, e.clientY, shapeSvgElement)
  
  isResizing.value = true
  resizeObject.value = obj
  resizeHandle.value = handle
  resizeStart.value = {
    x: svgPoint.x,
    y: svgPoint.y,
    width: obj.width,
    height: obj.height,
    objX: obj.x,
    objY: obj.y,
    fontSize: obj.fontSize || 24,
  }
  
  document.addEventListener('mouseup', stopResize)
  document.addEventListener('mousemove', handleShapeResizeMove)
}

function handleShapeResizeMove(e) {
  if (!isResizing.value || !resizeObject.value || !props.canEdit) return
  
  // Convert current mouse position to SVG coordinates
  const svgPoint = screenToSVG(e.clientX, e.clientY, shapeSvgElement)
  
  const dx = svgPoint.x - resizeStart.value.x
  const dy = svgPoint.y - resizeStart.value.y
  
  let newWidth = resizeStart.value.width
  let newHeight = resizeStart.value.height
  let newX = resizeStart.value.objX
  let newY = resizeStart.value.objY
  
  const minSize = 20
  
  switch (resizeHandle.value) {
    case 'se':
      newWidth = Math.max(minSize, resizeStart.value.width + dx)
      newHeight = Math.max(minSize, resizeStart.value.height + dy)
      break
    case 'sw':
      newWidth = Math.max(minSize, resizeStart.value.width - dx)
      newX = resizeStart.value.objX + (resizeStart.value.width - newWidth)
      newHeight = Math.max(minSize, resizeStart.value.height + dy)
      break
    case 'ne':
      newWidth = Math.max(minSize, resizeStart.value.width + dx)
      newHeight = Math.max(minSize, resizeStart.value.height - dy)
      newY = resizeStart.value.objY + (resizeStart.value.height - newHeight)
      break
    case 'nw':
      newWidth = Math.max(minSize, resizeStart.value.width - dx)
      newX = resizeStart.value.objX + (resizeStart.value.width - newWidth)
      newHeight = Math.max(minSize, resizeStart.value.height - dy)
      newY = resizeStart.value.objY + (resizeStart.value.height - newHeight)
      break
  }
  
  emit('update', props.slideIndex, resizeObject.value.id, {
    x: newX,
    y: newY,
    width: newWidth,
    height: newHeight,
  })
}

// Shape rotation handler
function startShapeRotate(obj, e) {
  if (!props.canEdit) return
  
  isRotating.value = true
  rotateObject.value = obj
  
  // Store the SVG element for coordinate conversion
  const svg = e.target.closest('svg')
  shapeSvgElement = svg
  
  // Get center of shape in screen coordinates
  const rect = svg.getBoundingClientRect()
  const scaleX = rect.width / props.slideWidth
  const scaleY = rect.height / props.slideHeight
  
  // Calculate the center point in screen coordinates
  const centerX = rect.left + (obj.x + obj.width / 2) * scaleX
  const centerY = rect.top + (obj.y + obj.height / 2) * scaleY
  
  rotateStart.value = {
    angle: obj.rotation || 0,
    centerX: centerX,
    centerY: centerY,
  }
  
  document.addEventListener('mouseup', stopRotate)
  document.addEventListener('mousemove', handleShapeRotateMove)
}

function handleShapeRotateMove(e) {
  if (!isRotating.value || !rotateObject.value || !props.canEdit) return
  
  const dx = e.clientX - rotateStart.value.centerX
  const dy = e.clientY - rotateStart.value.centerY
  
  let angle = Math.atan2(dy, dx) * (180 / Math.PI) + 90
  
  // Snap to 15-degree increments if shift is held
  if (e.shiftKey) {
    angle = Math.round(angle / 15) * 15
  }
  
  emit('update', props.slideIndex, rotateObject.value.id, {
    rotation: angle,
  })
}

function selectObject(id, addToSelection = false) {
  emit('select', id, addToSelection)
}

function handleCanvasClick(e) {
  if (e.target === e.currentTarget || e.target.closest('.collab-slide-canvas > div')) {
    emit('deselect')
  }
}

function handleMouseMove(e) {
  const rect = e.currentTarget.getBoundingClientRect()
  const x = (e.clientX - rect.left) / scale.value
  const y = (e.clientY - rect.top) / scale.value
  
  emit('cursor-move', { x, y })
  
  // Handle resizing (text/image objects - uses handleResize which is called here)
  if (isResizing.value && resizeObject.value && props.canEdit) {
    handleResize(e)
  }
  
  // Handle rotation
  if (isRotating.value && rotateObject.value && props.canEdit) {
    handleRotate(e)
  }
}

function handleMouseLeave() {
  emit('cursor-move', { x: -100, y: -100 }) // Move cursor off-screen
}

// Drag handlers
function handleObjectDrag(e) {
  if (!dragObject.value || !props.canEdit) return
  
  let dx = e.clientX - dragStart.value.x
  let dy = e.clientY - dragStart.value.y
  const distance = Math.sqrt(dx * dx + dy * dy)
  
  // Check if we've moved enough to consider it a drag
  if (!hasMoved.value && distance >= DRAG_THRESHOLD) {
    hasMoved.value = true
    isDragging.value = true
    
    // Alt+Drag = Duplicate and drag the copy
    if (isAltDrag.value && !altDragDuplicated.value) {
      altDragDuplicated.value = true
      // Emit duplicate event - parent will create copy and return new ID
      emit('duplicate-drag', props.slideIndex, dragObject.value)
    }
    
    // Select object when drag starts (if not selected)
    if (!isSelected(dragObject.value.id)) {
      selectObject(dragObject.value.id, false)
    }
  }
  
  // Only move if we're actually dragging
  if (hasMoved.value && isDragging.value) {
    // Shift = constrain to X or Y axis
    if (e.shiftKey) {
      if (Math.abs(dx) > Math.abs(dy)) {
        dy = 0 // Constrain to X axis
      } else {
        dx = 0 // Constrain to Y axis
      }
    }
    
    const scaledDx = dx / scale.value
    const scaledDy = dy / scale.value
    
    // Check if we have multiple objects selected for multi-drag
    const multiDragIds = Object.keys(dragSelectedStarts.value)
    if (multiDragIds.length > 1) {
      // Multi-drag: move all selected objects
      for (const id of multiDragIds) {
        const startPos = dragSelectedStarts.value[id]
        const obj = (props.slide.objects || []).find(o => o.id === id)
        if (startPos && obj) {
          emit('update', props.slideIndex, id, {
            x: Math.max(0, Math.min(props.slideWidth - obj.width, startPos.x + scaledDx)),
            y: Math.max(0, Math.min(props.slideHeight - obj.height, startPos.y + scaledDy)),
          })
        }
      }
    } else {
      // Single drag: move only the dragged object
      emit('update', props.slideIndex, dragObject.value.id, {
        x: Math.max(0, Math.min(props.slideWidth - dragObject.value.width, dragObjectStart.value.x + scaledDx)),
        y: Math.max(0, Math.min(props.slideHeight - dragObject.value.height, dragObjectStart.value.y + scaledDy)),
      })
    }
  }
}

function stopDrag() {
  isDragging.value = false
  dragObject.value = null
  hasMoved.value = false
  dragSelectedStarts.value = {} // Clear multi-drag state
  shapeSvgElement = null
  // Clean up all possible event listeners
  document.removeEventListener('mouseup', stopDrag)
  document.removeEventListener('mouseup', handleObjectMouseUp)
  document.removeEventListener('mouseup', handleShapeMouseUp)
  document.removeEventListener('mousemove', handleShapeDrag)
  document.removeEventListener('mousemove', handleObjectDrag)
}

// Shape interaction handlers
function handleShapeMouseDown(obj, e) {
  if (!props.canEdit) return
  
  // Store the SVG element for coordinate conversion
  shapeSvgElement = e.target.closest('svg')
  
  // Prepare for potential drag
  dragObject.value = obj
  const svgPoint = screenToSVG(e.clientX, e.clientY, shapeSvgElement)
  dragStart.value = { x: svgPoint.x, y: svgPoint.y }
  dragObjectStart.value = { x: obj.x, y: obj.y }
  hasMoved.value = false
  isDragging.value = false
  
  // Store starting positions of ALL selected objects for multi-drag
  dragSelectedStarts.value = {}
  if (isSelected(obj.id) && props.selectedIds.length > 1) {
    // Object is already selected and there are multiple selections - prepare for multi-drag
    for (const id of props.selectedIds) {
      const selectedObj = (props.slide.objects || []).find(o => o.id === id)
      if (selectedObj) {
        dragSelectedStarts.value[id] = { x: selectedObj.x, y: selectedObj.y }
      }
    }
  }
  
  document.addEventListener('mouseup', handleShapeMouseUp)
  document.addEventListener('mousemove', handleShapeDrag)
}

function handleShapeMouseUp(e) {
  document.removeEventListener('mouseup', handleShapeMouseUp)
  document.removeEventListener('mousemove', handleShapeDrag)
  
  const obj = dragObject.value
  if (!obj) return
  
  // If we didn't move, treat as click - select the shape
  if (!hasMoved.value) {
    if (!isSelected(obj.id)) {
      // Support both Shift+Click and Ctrl+Click for multi-selection
      const addToSelection = e.shiftKey || e.ctrlKey || e.metaKey
      selectObject(obj.id, addToSelection)
    }
  }
  
  // Reset drag state
  isDragging.value = false
  dragObject.value = null
  hasMoved.value = false
  shapeSvgElement = null
}

function handleShapeClick(obj, e) {
  // Click events are now handled in handleShapeMouseUp
  if (!props.canEdit) return
  if (!isDragging.value && !hasMoved.value && !isSelected(obj.id)) {
    // Support both Shift+Click and Ctrl+Click for multi-selection
    const addToSelection = e.shiftKey || e.ctrlKey || e.metaKey
    selectObject(obj.id, addToSelection)
  }
}

// Shape drag handlers (for SVG shapes)
// Store SVG reference for coordinate conversion
let shapeSvgElement = null

// Convert screen coordinates to SVG coordinates
function screenToSVG(screenX, screenY, svg) {
  if (!svg) return { x: screenX, y: screenY }
  
  const point = svg.createSVGPoint()
  point.x = screenX
  point.y = screenY
  
  const ctm = svg.getScreenCTM()
  if (ctm) {
    const svgPoint = point.matrixTransform(ctm.inverse())
    return { x: svgPoint.x, y: svgPoint.y }
  }
  
  // Fallback: calculate based on viewBox and bounding rect
  const rect = svg.getBoundingClientRect()
  const scaleX = props.slideWidth / rect.width
  const scaleY = props.slideHeight / rect.height
  return {
    x: (screenX - rect.left) * scaleX,
    y: (screenY - rect.top) * scaleY,
  }
}

function handleShapeDrag(e) {
  if (!dragObject.value || !props.canEdit) return
  
  // Convert current mouse position to SVG coordinates
  const svgPoint = screenToSVG(e.clientX, e.clientY, shapeSvgElement)
  
  let dx = svgPoint.x - dragStart.value.x
  let dy = svgPoint.y - dragStart.value.y
  const distance = Math.sqrt(dx * dx + dy * dy)
  
  // Check if we've moved enough to consider it a drag
  if (!hasMoved.value && distance >= DRAG_THRESHOLD) {
    hasMoved.value = true
    isDragging.value = true
    
    // Select object when drag starts (if not selected)
    if (!isSelected(dragObject.value.id)) {
      selectObject(dragObject.value.id, false)
    }
  }
  
  // Only move if we're actually dragging
  if (hasMoved.value && isDragging.value) {
    // Shift = constrain to X or Y axis
    if (e.shiftKey) {
      if (Math.abs(dx) > Math.abs(dy)) {
        dy = 0 // Constrain to X axis
      } else {
        dx = 0 // Constrain to Y axis
      }
    }
    
    // Check if we have multiple objects selected for multi-drag
    const multiDragIds = Object.keys(dragSelectedStarts.value)
    if (multiDragIds.length > 1) {
      // Multi-drag: move all selected objects
      for (const id of multiDragIds) {
        const startPos = dragSelectedStarts.value[id]
        const obj = (props.slide.objects || []).find(o => o.id === id)
        if (startPos && obj) {
          emit('update', props.slideIndex, id, {
            x: Math.max(0, Math.min(props.slideWidth - obj.width, startPos.x + dx)),
            y: Math.max(0, Math.min(props.slideHeight - obj.height, startPos.y + dy)),
          })
        }
      }
    } else {
      // Single drag: move only the dragged object
      emit('update', props.slideIndex, dragObject.value.id, {
        x: Math.max(0, Math.min(props.slideWidth - dragObject.value.width, dragObjectStart.value.x + dx)),
        y: Math.max(0, Math.min(props.slideHeight - dragObject.value.height, dragObjectStart.value.y + dy)),
      })
    }
  }
}

// Resize handlers
function startResize(obj, handle, e) {
  if (!props.canEdit) return
  
  isResizing.value = true
  resizeObject.value = obj
  resizeHandle.value = handle
  resizeStart.value = {
    x: e.clientX,
    y: e.clientY,
    width: obj.width,
    height: obj.height,
    objX: obj.x,
    objY: obj.y,
    fontSize: obj.fontSize || 24, // Store original font size for scaling
  }
  
  document.addEventListener('mouseup', stopResize)
  document.addEventListener('mousemove', handleResize)
}

function handleResize(e) {
  const dx = (e.clientX - resizeStart.value.x) / scale.value
  const dy = (e.clientY - resizeStart.value.y) / scale.value
  
  let newWidth = resizeStart.value.width
  let newHeight = resizeStart.value.height
  let newX = resizeStart.value.objX
  let newY = resizeStart.value.objY
  
  const minSize = 20
  
  switch (resizeHandle.value) {
    case 'e':
      newWidth = Math.max(minSize, resizeStart.value.width + dx)
      break
    case 'w':
      newWidth = Math.max(minSize, resizeStart.value.width - dx)
      newX = resizeStart.value.objX + (resizeStart.value.width - newWidth)
      break
    case 's':
      newHeight = Math.max(minSize, resizeStart.value.height + dy)
      break
    case 'n':
      newHeight = Math.max(minSize, resizeStart.value.height - dy)
      newY = resizeStart.value.objY + (resizeStart.value.height - newHeight)
      break
    case 'se':
      newWidth = Math.max(minSize, resizeStart.value.width + dx)
      newHeight = Math.max(minSize, resizeStart.value.height + dy)
      break
    case 'sw':
      newWidth = Math.max(minSize, resizeStart.value.width - dx)
      newX = resizeStart.value.objX + (resizeStart.value.width - newWidth)
      newHeight = Math.max(minSize, resizeStart.value.height + dy)
      break
    case 'ne':
      newWidth = Math.max(minSize, resizeStart.value.width + dx)
      newHeight = Math.max(minSize, resizeStart.value.height - dy)
      newY = resizeStart.value.objY + (resizeStart.value.height - newHeight)
      break
    case 'nw':
      newWidth = Math.max(minSize, resizeStart.value.width - dx)
      newX = resizeStart.value.objX + (resizeStart.value.width - newWidth)
      newHeight = Math.max(minSize, resizeStart.value.height - dy)
      newY = resizeStart.value.objY + (resizeStart.value.height - newHeight)
      break
  }
  
  const updates = {
    x: newX,
    y: newY,
    width: newWidth,
    height: newHeight,
  }
  
  // Scale font size when Shift is held for text objects
  if (e.shiftKey && resizeObject.value.type === 'text') {
    const originalFontSize = resizeStart.value.fontSize || resizeObject.value.fontSize || 24
    const scaleFactor = Math.min(newWidth / resizeStart.value.width, newHeight / resizeStart.value.height)
    const newFontSize = Math.max(8, Math.round(originalFontSize * scaleFactor))
    updates.fontSize = newFontSize
  }
  
  emit('update', props.slideIndex, resizeObject.value.id, updates)
}

function stopResize() {
  isResizing.value = false
  resizeObject.value = null
  resizeHandle.value = null
  document.removeEventListener('mouseup', stopResize)
  document.removeEventListener('mousemove', handleResize)
  document.removeEventListener('mousemove', handleShapeResizeMove)
}

// Rotation handlers
function startRotate(obj, e) {
  if (!props.canEdit) return
  
  isRotating.value = true
  rotateObject.value = obj
  
  const rect = e.currentTarget.closest('.absolute').getBoundingClientRect()
  rotateStart.value = {
    angle: obj.rotation || 0,
    centerX: rect.left + rect.width / 2,
    centerY: rect.top + rect.height / 2,
  }
  
  document.addEventListener('mouseup', stopRotate)
}

function handleRotate(e) {
  const dx = e.clientX - rotateStart.value.centerX
  const dy = e.clientY - rotateStart.value.centerY
  
  let angle = Math.atan2(dy, dx) * (180 / Math.PI) + 90
  
  // Snap to 15-degree increments if shift is held
  if (e.shiftKey) {
    angle = Math.round(angle / 15) * 15
  }
  
  emit('update', props.slideIndex, rotateObject.value.id, {
    rotation: angle,
  })
}

function stopRotate() {
  isRotating.value = false
  rotateObject.value = null
  document.removeEventListener('mouseup', stopRotate)
  document.removeEventListener('mousemove', handleShapeRotateMove)
}

// Context menu handlers
function handleContextMenu(obj, e) {
  if (!props.canEdit) return
  
  e.preventDefault()
  
  // Select the object if not already selected
  if (!isSelected(obj.id)) {
    selectObject(obj.id, false)
  }
  
  // Show context menu at mouse position
  showContextMenu.value = true
  contextMenuX.value = e.clientX
  contextMenuY.value = e.clientY
  contextMenuObjectId.value = obj.id
  contextMenuObjectType.value = obj.type
}

function closeContextMenu() {
  showContextMenu.value = false
  contextMenuObjectId.value = null
  contextMenuObjectType.value = null
}

function handleContextAction(action) {
  if (!contextMenuObjectId.value) return
  
  emit('context-action', {
    action,
    objectId: contextMenuObjectId.value,
    objectType: contextMenuObjectType.value,
    slideIndex: props.slideIndex,
  })
  
  closeContextMenu()
}

// Text editing
function startTextEdit(obj) {
  if (!props.canEdit) return
  
  editingTextId.value = obj.id
  
  nextTick(() => {
    // Small delay to ensure the edit div is rendered
    setTimeout(() => {
      const editorEl = textEditorRefs.value[obj.id]
      if (editorEl) {
        // Manually set the content (NOT via Vue binding to avoid reactivity issues)
        editorEl.innerHTML = obj.content || ''
        editorEl.focus()
        
        // Place cursor at end of text
        const range = document.createRange()
        const sel = window.getSelection()
        if (editorEl.childNodes.length > 0) {
          range.selectNodeContents(editorEl)
          range.collapse(false) // false = collapse to end
        } else {
          range.setStart(editorEl, 0)
          range.collapse(true)
        }
        sel.removeAllRanges()
        sel.addRange(range)
      }
    }, 20)
  })
}

// Debounce timer for text sync
let textSyncTimer = null

function onTextInput(e) {
  if (!editingTextId.value) return
  
  const content = e.target.innerHTML
  
  // Sync to Y.js in real-time for collaboration
  // Use a small debounce to batch rapid keystrokes
  if (textSyncTimer) {
    clearTimeout(textSyncTimer)
  }
  
  textSyncTimer = setTimeout(() => {
    if (editingTextId.value) {
      emit('update', props.slideIndex, editingTextId.value, {
        content: content,
      })
    }
  }, 100) // 100ms debounce for smooth collaboration
}

function finishTextEdit(e) {
  if (!editingTextId.value) return
  
  // Clear any pending sync
  if (textSyncTimer) {
    clearTimeout(textSyncTimer)
    textSyncTimer = null
  }
  
  // Final sync on blur - get content directly from the element
  const content = e?.target?.innerHTML
  if (content !== undefined) {
    emit('update', props.slideIndex, editingTextId.value, {
      content: content,
    })
  }
  
  editingTextId.value = null
}

// Resize observer for responsive scaling
let resizeObserver = null

onMounted(() => {
  const container = document.querySelector('.collab-slide-canvas')?.parentElement
  if (container) {
    resizeObserver = new ResizeObserver((entries) => {
      const rect = entries[0].contentRect
      containerWidth.value = rect.width
      containerHeight.value = rect.height
    })
    resizeObserver.observe(container)
    containerWidth.value = container.offsetWidth
    containerHeight.value = container.offsetHeight
  }
})

onUnmounted(() => {
  resizeObserver?.disconnect()
  document.removeEventListener('mouseup', stopDrag)
  document.removeEventListener('mousemove', handleShapeDrag)
  document.removeEventListener('mousemove', handleObjectDrag)
  document.removeEventListener('mouseup', stopResize)
  document.removeEventListener('mousemove', handleResize)
  document.removeEventListener('mousemove', handleShapeResizeMove)
  document.removeEventListener('mouseup', stopRotate)
  document.removeEventListener('mousemove', handleShapeRotateMove)
})
</script>

<style>
.collab-slide-canvas {
  user-select: none;
}

/* Allow text selection inside editing elements */
.collab-slide-canvas [contenteditable="true"] {
  user-select: text !important;
  -webkit-user-select: text !important;
  cursor: text !important;
}

.select-text {
  user-select: text !important;
  -webkit-user-select: text !important;
}

/* List styles for text objects */
.list-style-bullet ul,
.list-style-bullet ol,
.list-style-bullet .slide-list {
  list-style-type: disc;
  padding-left: 1.5em;
  margin: 0;
}

.list-style-bullet li {
  margin-bottom: 0.25em;
}

.list-style-numbered ul,
.list-style-numbered ol,
.list-style-numbered .slide-list {
  list-style-type: decimal;
  padding-left: 1.5em;
  margin: 0;
}

.list-style-numbered li {
  margin-bottom: 0.25em;
}

/* Slide list base styles */
.slide-list {
  margin: 0;
  padding-left: 1.5em;
}

.slide-list li {
  margin-bottom: 0.25em;
}

/* Nested list support */
.slide-list ul,
.slide-list ol {
  margin-top: 0.25em;
  margin-bottom: 0.25em;
}

/* Print styles for slide canvas */
@media print {
  .collab-slide-canvas {
    width: 100% !important;
    height: auto !important;
    max-width: 100% !important;
    min-width: 100% !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    overflow: visible !important;
    transform: none !important;
    position: relative !important;
  }
  
  /* Override inline styles */
  .collab-slide-canvas[style] {
    width: 100% !important;
    height: auto !important;
  }
  
  /* Slide background - maintain aspect ratio */
  .collab-slide-canvas > div {
    position: relative !important;
    width: 100% !important;
    height: auto !important;
    aspect-ratio: 16 / 9 !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    overflow: visible !important;
  }
  
  /* Scale layer - reset transform for print */
  .collab-slide-canvas > div > div[style*="transform"] {
    transform: none !important;
    width: 100% !important;
    height: 100% !important;
  }
  
  /* All absolute positioned objects need to scale properly */
  .collab-slide-canvas .absolute {
    position: absolute !important;
  }
  
  /* Hide selection indicators */
  .collab-slide-canvas .absolute.-inset-2,
  .collab-slide-canvas .border-2.border-blue-500,
  .collab-slide-canvas .border-dashed,
  .collab-slide-canvas .border-gray-400\/50 {
    display: none !important;
  }
  
  /* Hide resize/rotate handles */
  .collab-slide-canvas .w-5.h-5.bg-white.border-2,
  .collab-slide-canvas .w-7.h-7.bg-white.border-2,
  .collab-slide-canvas .absolute.-top-12 {
    display: none !important;
  }
  
  /* Ensure all content prints with colors */
  .collab-slide-canvas,
  .collab-slide-canvas * {
    visibility: visible !important;
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
  }
  
  /* Make sure text and images are visible */
  .collab-slide-canvas img {
    max-width: 100% !important;
  }
  
  .collab-slide-canvas div[class*="text"],
  .collab-slide-canvas div[v-html] {
    overflow: visible !important;
  }
}
</style>

