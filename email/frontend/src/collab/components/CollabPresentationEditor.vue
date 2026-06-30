<template>
  <div class="collab-presentation-editor flex flex-col h-full" style="background: #eaeaea;">
    <!-- Presentation Mode Overlay -->
    <CollabPresentationMode
      v-if="isPresentationMode"
      :slides="slides"
      :startIndex="currentSlideIndex"
      :slideWidth="slideWidth"
      :slideHeight="slideHeight"
      @exit="exitPresentation"
    />

    <!-- Header (hidden in print and presentation mode) -->
    <header v-if="!isPresentationMode" class="print-hide" style="background: #ffffff; border-bottom: 1px solid #e2e8f0;">
      <!-- Top row: Back, Title, Actions -->
      <div class="flex items-center px-4 py-2">
        <!-- Back button -->
        <button 
          @click="$emit('close')"
          class="p-2 hover:bg-gray-100 rounded-xl transition-colors mr-2"
          title="Back to documents"
        >
          <span class="material-symbols-rounded text-orange-500" style="font-size: 24px;">arrow_back</span>
        </button>
        
        <div class="flex flex-col min-w-0 flex-1">
          <!-- Editable title -->
          <input
            v-model="localTitle"
            @blur="saveTitle"
            @keydown.enter="$event.target.blur()"
            :disabled="!canEdit"
            class="text-lg font-medium text-gray-900 bg-transparent border-none focus:outline-none hover:bg-gray-50 focus:bg-gray-50 rounded-lg px-2 py-1 -ml-2 transition-colors"
            :class="{ 'cursor-not-allowed opacity-60': !canEdit }"
            placeholder="Untitled Presentation"
          />
          
          <!-- Status indicator -->
          <div class="flex items-center gap-2 ml-2 text-sm" :class="statusClass">
            <span v-if="status === 'connected'" class="text-gray-500">{{ statusText }}</span>
            <span v-else class="flex items-center gap-1">
              <span class="w-2 h-2 rounded-full" :class="statusDotClass"></span>
              {{ statusText }}
            </span>
          </div>
        </div>
        
        <!-- Right side actions -->
        <div class="flex items-center gap-1.5">
          <!-- Presence avatars (all active users including yourself) -->
          <CollabPresenceAvatars 
            v-if="allActiveUsers.length > 0"
            :users="allActiveUsers"
            :maxVisible="5"
            :currentUserEmail="props.user.email"
          />
          
          <div class="w-px h-6 bg-gray-200 mx-1"></div>
          
          <!-- Present button -->
          <button
            @click="startPresentation"
            class="present-btn"
          >
            <span class="material-symbols-rounded">play_arrow</span>
            <span>Present</span>
          </button>
          
          <!-- Share button -->
          <button
            v-if="canShare"
            @click="$emit('share')"
            class="share-btn"
          >
            <span class="material-symbols-rounded">share</span>
            <span>Share</span>
          </button>
          
          <!-- More menu -->
          <div class="relative">
            <button 
              @click="showMenu = !showMenu"
              class="header-icon-btn"
              :class="{ 'active': showMenu }"
            >
              <span class="material-symbols-rounded">more_vert</span>
            </button>
            
            <!-- Dropdown menu -->
            <div 
              v-if="showMenu"
              class="absolute right-0 top-full mt-1 w-52 bg-white rounded-xl shadow-lg border border-gray-200 py-1 z-50"
              @click.stop
            >
              <button 
                @click="showMenu = false; showVersionHistory = true"
                class="w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-3 transition-colors"
              >
                <span class="material-symbols-rounded text-gray-500" style="font-size: 20px;">history</span>
                Version history
              </button>
              <hr class="my-1 border-gray-200" />
              <button 
                @click="showMenu = false; $emit('duplicate')"
                class="w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-3 transition-colors"
              >
                <span class="material-symbols-rounded text-gray-500" style="font-size: 20px;">content_copy</span>
                Make a copy
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Toolbar (hidden in print) -->
      <div class="print-hide">
        <CollabPresentationToolbar
          v-if="canEdit"
          :canUndo="canUndo"
          :canRedo="canRedo"
          :isExporting="isExporting"
          :selectedObject="selectedObject"
          :hasObjects="currentSlideObjects.length > 0"
          :showComments="showCommentsPanel"
          :commentCount="commentThreads.filter(t => !t.resolved).length"
          :themes="themesList"
          :currentThemeId="selectedTheme"
          :themePreview="themePreview"
          :currentThemeName="currentThemeName"
          @export-pptx="exportToPptx"
          @undo="undo"
          @redo="redo"
          @global-undo="globalUndo"
          @add-text="addTextBox"
          @add-shape="addShape"
          @add-image="addImage"
          @open-templates="showTemplatePicker = true"
          @new-slide="addNewSlide"
          @open-background="showBackgroundSettings = true"
          @save-template="showSaveTemplateModal = true"
          @update-object="updateSelectedObject"
          @delete-selected="deleteSelectedObjects"
          @bring-forward="bringForward"
          @send-backward="sendBackward"
          @align-to-artboard="alignToArtboard"
          @toggle-comments="showCommentsPanel = !showCommentsPanel"
          @open-history="showVersionHistory = true"
          @change-theme="applyThemeById"
          @open-shape-library="showExtendedShapeMenu = true"
          @print="handlePrint"
        />
      </div>
    </header>
    
    <!-- Main content area -->
    <div v-if="!isPresentationMode" class="flex-1 flex min-h-0 overflow-hidden">
      <!-- Slide thumbnails sidebar -->
      <CollabSlideThumbnails
        :slides="slides"
        :currentIndex="currentSlideIndex"
        :canEdit="canEdit"
        :userSlidePositions="userSlidePositions"
        @select="goToSlide"
        @add="addNewSlide"
        @delete="deleteSlide"
        @duplicate="duplicateSlide"
        @reorder="moveSlide"
      />
      
      <!-- Canvas area with mouse tracking -->
      <div 
        ref="canvasContainerRef"
        class="flex-1 min-h-0 overflow-hidden p-4 flex items-center justify-center relative"
        style="background: #eaeaea;"
        @mousemove="handleCanvasMouseMove"
        @mouseleave="handleCanvasMouseLeave"
      >
        <!-- Loading state -->
        <div v-if="isLoading" class="flex flex-col items-center gap-3">
          <div class="w-8 h-8 border-2 border-orange-500 border-t-transparent rounded-full animate-spin"></div>
          <span class="text-gray-500">Loading presentation...</span>
        </div>
        
        <!-- Error state -->
        <div v-else-if="error" class="flex flex-col items-center gap-3 text-center">
          <span class="material-symbols-rounded text-red-500" style="font-size: 48px;">error</span>
          <p class="text-gray-700">{{ error }}</p>
          <button 
            @click="reconnect"
            class="px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg"
          >
            Try again
          </button>
        </div>
        
        <!-- Slide canvas -->
        <CollabSlideCanvas
          v-else-if="currentSlide"
          ref="slideCanvasRef"
          :slide="currentSlide"
          :slideIndex="currentSlideIndex"
          :slideWidth="slideWidth"
          :slideHeight="slideHeight"
          :selectedIds="selectedObjectIds"
          :canEdit="canEdit"
          :cursors="currentSlideRemoteCursors"
          @select="selectObject"
          @deselect="clearSelection"
          @update="updateObject"
          @cursor-move="onCursorMove"
          @duplicate-drag="handleDuplicateDrag"
          @context-action="handleContextAction"
        />
        
        <div v-else class="text-gray-500">
          No slides yet. Click "New slide" to add one.
        </div>
        
        <!-- Floating Add Comment Button (appears when object is selected) -->
        <div 
          v-if="hasSelection && canEdit && !showCommentsPanel"
          class="floating-comment-btn"
          :style="floatingButtonStyle"
        >
          <button 
            @click="openCommentForSelection"
            class="comment-btn-inner"
            title="Add comment"
          >
            <span class="material-symbols-rounded">add_comment</span>
          </button>
        </div>
        
      </div>
      
      <!-- Comments panel (slide from right like in docs) -->
      <transition name="slide-right">
        <CollabPresentationComments
          v-if="showCommentsPanel"
          ref="commentsRef"
          :threads="commentThreads"
          :currentSlideIndex="currentSlideIndex"
          :canEdit="canEdit"
          :user="props.user"
          :selectedObjectId="selectedObjectIds[0] || null"
          :hasSelection="hasSelection"
          class="w-80"
          @close="showCommentsPanel = false"
          @goto="handleGotoComment"
          @add="handleAddComment"
          @resolve="resolveComment"
          @unresolve="unresolveComment"
          @reply="handleReplyComment"
        />
      </transition>
    </div>
    
    <!-- Hidden file input for images -->
    <input 
      ref="imageInput"
      type="file" 
      accept="image/*" 
      class="hidden"
      @change="handleImageSelect"
    />
    
    <!-- Template Picker Modal -->
    <CollabTemplatePickerModal
      v-if="showTemplatePicker"
      @close="showTemplatePicker = false"
      @select="handleTemplateSelect"
    />
    
    <!-- Version History Panel -->
    <CollabVersionHistoryPanel
      :show="showVersionHistory"
      :documentUuid="props.documentUuid"
      :documentTitle="localTitle"
      documentType="presentation"
      @close="showVersionHistory = false"
      @restored="handleVersionRestored"
    />
    
    <!-- Background Settings Modal -->
    <CollabBackgroundSettingsModal
      v-if="showBackgroundSettings"
      ref="backgroundModalRef"
      :currentBackground="currentSlide?.background"
      @close="showBackgroundSettings = false"
      @apply="handleBackgroundApply"
      @open-drive-picker="showDrivePickerForBg = true"
    />
    
    <!-- Drive Picker for Background Image -->
    <DriveFilePicker
      :show="showDrivePickerForBg"
      title="Choose Background Image"
      :acceptTypes="['image/*']"
      @select="handleDriveImageSelect"
      @cancel="showDrivePickerForBg = false"
    />
    
    <!-- Save Template Modal -->
    <CollabSaveTemplateModal
      v-if="showSaveTemplateModal"
      :slideObjects="currentSlideObjects"
      :slideBackground="currentSlide?.background"
      :slideWidth="slideWidth"
      :slideHeight="slideHeight"
      @close="showSaveTemplateModal = false"
      @saved="handleTemplateSaved"
    />
    
    <!-- Extended Shape Picker Modal -->
    <div 
      v-if="showExtendedShapeMenu"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="showExtendedShapeMenu = false"
    >
      <div class="bg-white rounded-xl shadow-2xl w-[700px] max-h-[80vh] overflow-hidden" @click.stop>
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
          <h3 class="font-semibold text-gray-900">Insert Shape</h3>
          <button @click="showExtendedShapeMenu = false" class="p-1 hover:bg-gray-100 rounded">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        
        <div class="flex h-[400px]">
          <!-- Category sidebar -->
          <div class="w-48 border-r border-gray-200 p-2 overflow-y-auto">
            <button
              v-for="cat in shapeCategories"
              :key="cat.id"
              @click="selectedShapeCategory = cat.id"
              class="w-full flex items-center gap-2 px-3 py-2 rounded-full text-sm transition-colors"
              :class="selectedShapeCategory === cat.id ? 'bg-primary-500/15 text-primary-600' : 'hover:bg-gray-100 text-gray-700'"
            >
              <span class="material-symbols-rounded" style="font-size: 18px;">{{ cat.icon }}</span>
              {{ cat.name }}
            </button>
          </div>
          
          <!-- Shape grid -->
          <div class="flex-1 p-4 overflow-y-auto">
            <div 
              class="grid gap-3"
              :class="selectedShapeCategory === 'icons' ? 'grid-cols-6' : 'grid-cols-4'"
            >
              <button
                v-for="shape in shapeCategories.find(c => c.id === selectedShapeCategory)?.shapes || []"
                :key="shape.id"
                @click="addExtendedShape(shape.id); showExtendedShapeMenu = false"
                class="flex flex-col items-center gap-1 p-2 rounded-xl border-2 border-transparent hover:border-primary-500 hover:bg-primary-50 transition-all"
                :class="selectedShapeCategory === 'icons' ? 'p-2' : 'p-3 gap-2'"
              >
                <div 
                  class="flex items-center justify-center text-gray-600"
                  :class="selectedShapeCategory === 'icons' ? 'w-10 h-10' : 'w-12 h-12'"
                >
                  <span 
                    class="material-symbols-rounded" 
                    :style="{ fontSize: selectedShapeCategory === 'icons' ? '24px' : '28px' }"
                  >{{ shape.icon }}</span>
                </div>
                <span 
                  class="text-gray-600 text-center truncate w-full"
                  :class="selectedShapeCategory === 'icons' ? 'text-[10px]' : 'text-xs'"
                >{{ shape.name }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Header icon buttons */
.header-icon-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.15s ease;
}

.header-icon-btn:hover {
  background: #f3f4f6;
  color: #374151;
}

.header-icon-btn.active {
  background: rgb(var(--color-primary-100));
  color: rgb(var(--color-primary-600));
}

.header-icon-btn .material-symbols-rounded {
  font-size: 22px;
}

/* Present button */
.present-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: #f97316;
  color: white;
  font-size: 14px;
  font-weight: 600;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.present-btn:hover {
  background: #ea580c;
}

.present-btn .material-symbols-rounded {
  font-size: 18px;
}

/* Share button */
.share-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: rgb(var(--color-primary-500));
  color: white;
  font-size: 14px;
  font-weight: 600;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.share-btn:hover {
  background: rgb(var(--color-primary-600));
}

.share-btn .material-symbols-rounded {
  font-size: 18px;
}

/* Slide transition for comments panel */
.slide-right-enter-active,
.slide-right-leave-active {
  transition: transform 0.2s ease-out;
}

.slide-right-enter-from,
.slide-right-leave-to {
  transform: translateX(100%);
}

/* Floating comment button - positioned to the right of the slide */
.floating-comment-btn {
  position: absolute;
  z-index: 50;
  transition: all 0.15s ease;
}

.comment-btn-inner {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  background: rgb(var(--color-primary-500));
  color: white;
  border: none;
  border-radius: 9999px;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(var(--color-primary-500), 0.3);
  transition: all 0.15s ease;
}

.comment-btn-inner:hover {
  background: rgb(var(--color-primary-600));
  transform: scale(1.05);
  box-shadow: 0 6px 16px rgba(var(--color-primary-500), 0.4);
}

.comment-btn-inner .material-symbols-rounded {
  font-size: 24px;
}


</style>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useCollabPresentation } from '../composables/useCollabPresentation.js'
import CollabPresenceAvatars from './CollabPresenceAvatars.vue'
import CollabSlideThumbnails from './CollabSlideThumbnails.vue'
import CollabSlideCanvas from './CollabSlideCanvas.vue'
import CollabPresentationToolbar from './CollabPresentationToolbar.vue'
import CollabPresentationMode from './CollabPresentationMode.vue'
import CollabTemplatePickerModal from './CollabTemplatePickerModal.vue'
import CollabPresentationComments from './CollabPresentationComments.vue'
import CollabVersionHistoryPanel from './CollabVersionHistoryPanel.vue'
import CollabBackgroundSettingsModal from './CollabBackgroundSettingsModal.vue'
import CollabSaveTemplateModal from './CollabSaveTemplateModal.vue'
import DriveFilePicker from '@/components/DriveFilePicker.vue'
import { createObjectsFromTemplate, createObjectsFromCustomTemplate } from '../data/slideTemplates.js'
import { getShapesByCategory, getShapeDefinition } from '../data/shapeLibrary.js'
import { getThemeList, getTheme, getThemeBackground } from '../data/presentationThemes.js'
import { downloadAsPptx } from '../services/collabExportService.js'
import { getSlideDimensions } from '../services/presentation/slideDimensions.js'
import { isDebugEnabled } from '@/utils/debug'

const props = defineProps({
  documentUuid: {
    type: String,
    required: true,
  },
  user: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['close', 'share', 'duplicate', 'ready'])

// Presentation composable
const {
  isInitialized,
  isLoading,
  error,
  isConnected,
  status,
  meta,
  slides,
  currentSlideIndex,
  currentSlide,
  currentSlideObjects,
  selectedObjectIds,
  users,
  otherUsers,
  cursors,
  remoteCursors,
  userSlidePositions,
  canEdit,
  canShare,
  provider,
  init,
  destroy,
  reconnect,
  undo,
  redo,
  globalUndo,
  canUndo,
  canRedo,
  addSlide,
  deleteSlide: removeSlide,
  duplicateSlide: dupSlide,
  moveSlide: reorderSlide,
  setSlideBackground,
  addObject,
  updateObject: updateObj,
  deleteObject: removeObject,
  deleteSelectedObjects: deleteSelected,
  selectObject: select,
  clearSelection: deselect,
  goToSlide: navigateToSlide,
  setTitle,
  setTheme,
  updateCursor,
  updateMousePointer,
  clearMousePointer,
  getCollabUserColor,
  commentThreads,
  addComment,
  replyToComment,
  resolveComment,
  unresolveComment,
  bringToFront,
  sendToBack,
  duplicateObject: duplicateObj,
} = useCollabPresentation({ user: props.user })

// Local state
const localTitle = ref('')
const selectedTheme = ref('modern')
const imageInput = ref(null)
const isPresentationMode = ref(false)
const canvasContainerRef = ref(null)
const slideCanvasRef = ref(null)
const showTemplatePicker = ref(false)
const showCommentsPanel = ref(false)
const commentsRef = ref(null)
const showVersionHistory = ref(false)
const showBackgroundSettings = ref(false)
const showSaveTemplateModal = ref(false)
const showDrivePickerForBg = ref(false)
const backgroundModalRef = ref(null)
const isExporting = ref(false)
const showMenu = ref(false)

// Clipboard for copy/paste
const clipboard = ref(null)


// Theme list
const themesList = getThemeList()

// Current theme info
const currentThemeName = computed(() => {
  const theme = themesList.find(t => t.id === selectedTheme.value)
  return theme?.name || 'Modern'
})

const themePreview = computed(() => {
  const theme = themesList.find(t => t.id === selectedTheme.value)
  return theme?.preview || { background: '#ffffff', accent: '#2563eb', text: '#1f2937' }
})

// All active users including yourself (for presence avatars)
const allActiveUsers = computed(() => {
  const currentUserEmail = props.user.email?.toLowerCase()
  const currentUser = {
    email: props.user.email,
    name: props.user.name || props.user.email?.split('@')[0],
    color: getCollabUserColor(props.user.email),
    isYou: true,
    slideIndex: currentSlideIndex.value,
  }
  
  // Filter out our own email (stale sessions from reconnects)
  // and deduplicate by email/clientId to prevent duplicates
  const seenKeys = new Set([currentUserEmail])
  const others = (otherUsers.value || [])
    .filter(u => {
      const email = u.email?.toLowerCase()
      const key = email || `client-${u.clientId}`
      if (email === currentUserEmail || seenKeys.has(key)) {
        return false
      }
      seenKeys.add(key)
      return true
    })
    .map(u => ({
      ...u,
      color: u.color || getCollabUserColor(u.email || `anon-${u.clientId}`),
      name: u.name || u.email?.split('@')[0] || 'Anonymous',
      isYou: false,
    }))
  
  return [currentUser, ...others]
})

// Computed remote cursors for current slide only
const currentSlideRemoteCursors = computed(() => {
  return remoteCursors.value.filter(c => c.slideIndex === currentSlideIndex.value)
})

// Slide dimensions
const slideDimensions = computed(() => getSlideDimensions(meta.value || {}))
const slideWidth = computed(() => slideDimensions.value.width)
const slideHeight = computed(() => slideDimensions.value.height)

// Status display
const statusText = computed(() => {
  switch (status.value) {
    case 'connecting': return 'Connecting...'
    case 'connected': return 'Saved'
    case 'reconnecting': return 'Reconnecting...'
    default: return 'Offline'
  }
})

const statusClass = computed(() => {
  switch (status.value) {
    case 'connected': return 'text-gray-500'
    case 'connecting':
    case 'reconnecting': return 'text-amber-600'
    default: return 'text-red-600'
  }
})

const statusDotClass = computed(() => {
  switch (status.value) {
    case 'connected': return 'bg-green-500'
    case 'connecting':
    case 'reconnecting': return 'bg-amber-500 animate-pulse'
    default: return 'bg-red-500'
  }
})

// Shape options - get from library with categories
const shapeCategories = getShapesByCategory()

// State for expanded shape picker
const showExtendedShapeMenu = ref(false)
const selectedShapeCategory = ref('icons')

// Selected object
const selectedObject = computed(() => {
  if (selectedObjectIds.value.length !== 1) return null
  return currentSlideObjects.value.find(o => o.id === selectedObjectIds.value[0])
})

// Whether an object is selected
const hasSelection = computed(() => selectedObjectIds.value.length > 0)

// Position for the floating comment button (to the right of the canvas)
const floatingButtonStyle = computed(() => {
  // Position the button to the right of the canvas area
  const canvasEl = slideCanvasRef.value?.$el
  if (!canvasEl) {
    return { right: '20px', top: '50%', transform: 'translateY(-50%)' }
  }
  
  const containerEl = canvasContainerRef.value
  if (!containerEl) {
    return { right: '20px', top: '50%', transform: 'translateY(-50%)' }
  }
  
  const canvasRect = canvasEl.getBoundingClientRect()
  const containerRect = containerEl.getBoundingClientRect()
  
  // Position to the right of the slide
  const rightOffset = containerRect.right - canvasRect.right - 8
  
  // If object is selected, position near the object
  if (selectedObject.value) {
    const obj = selectedObject.value
    const scaleX = canvasRect.width / slideWidth.value
    const scaleY = canvasRect.height / slideHeight.value
    const scale = Math.min(scaleX, scaleY)
    
    // Calculate object's position on screen
    const objTop = obj.y * scale + canvasRect.top - containerRect.top
    const objBottom = (obj.y + obj.height) * scale + canvasRect.top - containerRect.top
    const objCenter = (objTop + objBottom) / 2
    
    return {
      right: `${Math.max(rightOffset, 12)}px`,
      top: `${objCenter}px`,
      transform: 'translateY(-50%)'
    }
  }
  
  return { right: '20px', top: '50%', transform: 'translateY(-50%)' }
})

// Open comments panel and start adding a comment for the selected object
function openCommentForSelection() {
  showCommentsPanel.value = true
  
  // Wait for panel to open, then start the comment
  setTimeout(() => {
    if (commentsRef.value) {
      commentsRef.value.startComment(
        currentSlideIndex.value,
        selectedObjectIds.value[0] || null,
        selectedObject.value?.x || 0,
        selectedObject.value?.y || 0
      )
    }
  }, 100)
}

// Keyboard handler for delete and other shortcuts
function handleKeyDown(e) {
  // Ctrl+Z - Undo (works even when editing)
  if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
    e.preventDefault()
    undo()
    return
  }
  
  // Ctrl+Shift+Z - Global Undo (undo everyone's changes)
  if ((e.ctrlKey || e.metaKey) && e.key === 'z' && e.shiftKey) {
    e.preventDefault()
    globalUndo()
    return
  }
  
  // Ctrl+Y - Redo
  if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
    e.preventDefault()
    redo()
    return
  }
  
  // Don't handle other shortcuts if we're in an input or textarea
  const activeEl = document.activeElement
  const isInFormField = activeEl?.tagName === 'INPUT' || activeEl?.tagName === 'TEXTAREA'
  const isInContentEditable = activeEl?.isContentEditable || activeEl?.closest('[contenteditable="true"]')
  const selection = window.getSelection()
  const hasTextSelection = selection && selection.toString().length > 0
  const isActivelyEditingText = isInContentEditable && activeEl?.closest('.slide-object')
  
  // Ctrl+D - Duplicate selected objects (prevent browser bookmark)
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
    // Always prevent default for Ctrl+D to avoid bookmark dialog
    e.preventDefault()
    if (selectedObjectIds.value.length > 0 && canEdit.value && !isInFormField) {
      duplicateSelectedObjects()
    }
    return
  }
  
  // Ctrl+C - Copy selected objects (only if not selecting text within an editable)
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
    // If in form field or actively selecting text in contenteditable, let browser handle it
    if (isInFormField || (isInContentEditable && hasTextSelection)) {
      return // Let browser copy text
    }
    // Otherwise copy selected objects
    if (selectedObjectIds.value.length > 0) {
      e.preventDefault()
      copySelectedObjects()
      return
    }
  }
  
  // Ctrl+V - Paste copied objects or clipboard images
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v') {
    // If in form field or contenteditable, let browser handle paste
    if (isInFormField || isInContentEditable) {
      return // Let browser paste text
    }
    
    // If we have internal clipboard objects, paste them and prevent default
    // The paste event handler will handle images from system clipboard
    if (clipboard.value && clipboard.value.length > 0) {
      e.preventDefault()
      pasteObjects()
      return
    }
    
    // Otherwise, let the default paste happen - paste event handler will catch images
    // Don't prevent default so the paste event fires with clipboardData
  }
  
  // Delete/Backspace - delete selected objects
  if ((e.key === 'Delete' || e.key === 'Backspace') && selectedObjectIds.value.length > 0 && canEdit.value) {
    if (isInFormField) return
    if (isActivelyEditingText) return
    
    e.preventDefault()
    deleteSelectedObjects()
    return
  }
  
  if (isInFormField || isInContentEditable) return
  
  // Escape - clear selection
  if (e.key === 'Escape') {
    deselect()
    showMenu.value = false
  }
  
  // Arrow keys - navigate slides (when no object selected)
  if (selectedObjectIds.value.length === 0) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
      e.preventDefault()
      if (currentSlideIndex.value < slides.value.length - 1) {
        navigateToSlide(currentSlideIndex.value + 1)
      }
    }
    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (currentSlideIndex.value > 0) {
        navigateToSlide(currentSlideIndex.value - 1)
      }
    }
  }
  
}

// Initialize
onMounted(async () => {
  document.addEventListener('keydown', handleKeyDown)
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('paste', handlePasteEvent)
  
  const success = await init(props.documentUuid, props.user)
  emit('ready')
  
  if (success) {
    localTitle.value = meta.value.title || 'Untitled Presentation'
    selectedTheme.value = meta.value.theme || 'modern'
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeyDown)
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('paste', handlePasteEvent)
  
  if (cleanupMouseListener) {
    cleanupMouseListener()
    cleanupMouseListener = null
  }
  destroy()
})

// Click outside handler for menu
function handleClickOutside(e) {
  if (showMenu.value && !e.target.closest('.relative')) {
    showMenu.value = false
  }
}

// Watch title changes
watch(() => meta.value.title, (newTitle) => {
  if (newTitle !== localTitle.value) {
    localTitle.value = newTitle
  }
})

// Actions
function saveTitle() {
  if (localTitle.value !== meta.value.title) {
    setTitle(localTitle.value)
  }
}

function addNewSlide() {
  addSlide(currentSlideIndex.value + 1)
  navigateToSlide(currentSlideIndex.value + 1)
}

function handleTemplateSelect(templateId, isCustom = false) {
  addSlide(currentSlideIndex.value + 1)
  navigateToSlide(currentSlideIndex.value + 1)
  
  let templateObjects = []
  let templateBackground = null
  
  if (isCustom) {
    const result = createObjectsFromCustomTemplate(templateId)
    templateObjects = result.objects
    templateBackground = result.background
  } else {
    templateObjects = createObjectsFromTemplate(templateId)
  }
    
  for (const obj of templateObjects) {
    addObject(obj)
  }
  
  if (templateBackground) {
    setSlideBackground(currentSlideIndex.value, templateBackground)
  }
  
  showTemplatePicker.value = false
}

function handleTemplateSaved(template) {
  isDebugEnabled() && console.log('Template saved:', template.name)
}

async function exportToPptx() {
  if (isExporting.value) return
  
  isExporting.value = true
  try {
    const { width, height, aspectRatio } = slideDimensions.value
    const presentationMeta = {
      aspectRatio,
      slideWidth: width,
      slideHeight: height
    }
    
    const title = meta.value?.title || 'Presentation'
    await downloadAsPptx(presentationMeta, slides.value, title)
  } catch (e) {
    console.error('Export failed:', e)
    alert('Failed to export presentation. Please try again.')
  } finally {
    isExporting.value = false
  }
}

async function handlePrint() {
  // Add print class to body for CSS targeting
  document.body.classList.add('print-ppt')

  // Add @page style for landscape printing
  const pageStyle = document.createElement('style')
  pageStyle.setAttribute('data-print-style', 'ppt')
  pageStyle.textContent = '@page { size: A4 landscape; margin: 0.5cm; }'
  document.head.appendChild(pageStyle)

  // Create print container - visible immediately for debugging
  const printContainer = document.createElement('div')
  printContainer.id = 'print-container'
  printContainer.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 99999;
    background: white;
    overflow: auto;
    padding: 20px;
  `
  document.body.appendChild(printContainer)

  const { width: baseWidth, height: baseHeight } = slideDimensions.value
  const printPageWidth = 297 * 3.78 // A4 landscape width in pixels at 96dpi
  const printPageHeight = 210 * 3.78 // A4 landscape height in pixels at 96dpi
  const printScale = Math.min((printPageWidth - 40) / baseWidth, (printPageHeight - 40) / baseHeight)

  isDebugEnabled() && console.log('[PPT Print] Starting print...', {
    slideCount: slides.value.length,
    baseWidth,
    baseHeight,
    printScale
  })

  const originalIndex = currentSlideIndex.value
  let slidesAdded = 0
  
  // For each slide, navigate to it, clone the rendered canvas, then add to print
  for (let i = 0; i < slides.value.length; i++) {
    // Navigate to this slide to render it
    navigateToSlide(i)
    
    // Wait for Vue to update the DOM
    await nextTick()
    await new Promise(resolve => setTimeout(resolve, 100))
    
    // Find the slide canvas element using the ref if available
    let slideCanvas = slideCanvasRef.value?.$el
    if (!slideCanvas) {
      slideCanvas = document.querySelector('.collab-slide-canvas')
    }
    
    isDebugEnabled() && console.log(`[PPT Print] Slide ${i + 1}: canvas found =`, !!slideCanvas)
    
    if (!slideCanvas) {
      // Create a fallback slide with the slide's background and content info
      const slide = slides.value[i]
      const fallbackSlide = document.createElement('div')
      fallbackSlide.className = 'print-slide'
      fallbackSlide.style.cssText = `
        width: ${printPageWidth - 40}px;
        height: ${printPageHeight - 40}px;
        background: ${slide?.background?.color || '#ffffff'};
        position: relative;
        page-break-after: always;
        margin: 0 auto 20px auto;
        border: 1px solid #ccc;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: #666;
      `
      fallbackSlide.textContent = `Slide ${i + 1}`
      printContainer.appendChild(fallbackSlide)
      slidesAdded++
      continue
    }
    
    // Clone the entire slide canvas
    const clone = slideCanvas.cloneNode(true)
    clone.className = 'print-slide'
    
    // Remove interactive elements from clone
    clone.querySelectorAll('.border-blue-500, .border-dashed, [class*="cursor-"], .absolute.-inset-2').forEach(el => {
      el.style.display = 'none'
    })
    
    // Remove resize handles and rotation handles
    clone.querySelectorAll('.w-5.h-5.bg-white, .w-7.h-7.bg-white, .-top-12').forEach(el => {
      el.remove()
    })
    
    // Style the clone for print - fixed size for A4 landscape
    clone.style.cssText = `
      width: ${printPageWidth - 40}px;
      height: ${(printPageWidth - 40) * (baseHeight / baseWidth)}px;
      position: relative;
      page-break-after: always;
      overflow: visible;
      box-shadow: none;
      margin: 0 auto 20px auto;
      transform: none;
      background: white;
    `
    
    // Fix the inner slide background div
    const innerDiv = clone.querySelector(':scope > div')
    if (innerDiv) {
      innerDiv.style.cssText = `
        width: 100%;
        height: 100%;
        position: absolute;
        top: 0;
        left: 0;
        border-radius: 0;
        box-shadow: none;
        overflow: visible;
      `
    }
    
    // Reset any transform scaling - use CSS sizing instead
    clone.querySelectorAll('[style*="transform"]').forEach(el => {
      const currentTransform = el.style.transform
      if (currentTransform.includes('scale')) {
        el.style.transform = 'none'
      }
    })
    
    // Make all positioned elements visible
    clone.querySelectorAll('.absolute').forEach(el => {
      el.style.overflow = 'visible'
    })
    
    printContainer.appendChild(clone)
    slidesAdded++
  }
  
  isDebugEnabled() && console.log(`[PPT Print] Total slides added: ${slidesAdded}`)
  
  // Navigate back to original slide
  navigateToSlide(originalIndex)
  
  // If no slides were added, create a fallback
  if (slidesAdded === 0) {
    console.warn('[PPT Print] No slides added, creating fallback')
    const fallback = document.createElement('div')
    fallback.className = 'print-slide'
    fallback.style.cssText = `
      width: ${printPageWidth - 40}px;
      height: ${printPageHeight - 40}px;
      background: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      color: #333;
      border: 2px solid #ccc;
      margin: 0 auto;
    `
    fallback.innerHTML = `<div style="text-align: center;"><div style="font-size: 32px; font-weight: bold; margin-bottom: 10px;">Presentation</div><div>No slides to print</div></div>`
    printContainer.appendChild(fallback)
  }
  
  // Wait for images to load
  const images = printContainer.querySelectorAll('img')
  if (images.length > 0) {
    await Promise.all(
      Array.from(images).map(img => 
        img.complete ? Promise.resolve() : new Promise(resolve => {
          img.onload = resolve
          img.onerror = resolve
        })
      )
    )
  }
  
  // Delay to ensure rendering
  await new Promise(resolve => setTimeout(resolve, 200))
  
  isDebugEnabled() && console.log('[PPT Print] Container children:', printContainer.children.length)
  
  // Print
  window.print()
  
  // Cleanup after print dialog closes
  window.addEventListener('afterprint', function cleanup() {
    printContainer.remove()
    document.body.classList.remove('print-ppt')
    if (pageStyle.parentNode) {
      pageStyle.parentNode.removeChild(pageStyle)
    }
    window.removeEventListener('afterprint', cleanup)
  })
  
  // Fallback cleanup after timeout (allow print preview to render)
  setTimeout(() => {
    if (document.getElementById('print-container')) {
      printContainer.remove()
    }
    document.body.classList.remove('print-ppt')
    if (pageStyle.parentNode) {
      pageStyle.parentNode.removeChild(pageStyle)
    }
  }, 60000)
}

function deleteSlide(index) {
  removeSlide(index)
}

function duplicateSlide(index) {
  dupSlide(index)
}

function moveSlide(from, to) {
  reorderSlide(from, to)
}

function goToSlide(index) {
  navigateToSlide(index)
}

function addTextBox() {
  addObject({
    type: 'text',
    x: 100,
    y: 100,
    width: 400,
    height: 100,
    content: '<p>Click to edit text</p>',
    fontSize: 32,
    fontFamily: 'Inter',
    color: '#000000',
  })
}

function addShape(shapeType) {
  addObject({
    type: 'shape',
    shapeType,
    x: 100,
    y: 100,
    width: 200,
    height: 200,
    fill: '#2196F3',
    stroke: '#1976D2',
    strokeWidth: 2,
  })
}

function addExtendedShape(shapeType) {
  const shapeDef = getShapeDefinition(shapeType)
  addObject({
    type: 'shape',
    shapeType,
    x: 100,
    y: 100,
    width: shapeDef?.defaultWidth || 200,
    height: shapeDef?.defaultHeight || 150,
    fill: shapeDef?.noFill ? 'transparent' : '#2196F3',
    stroke: '#1976D2',
    strokeWidth: 2,
  })
}

function addImage() {
  imageInput.value?.click()
}

function handleImageSelect(e) {
  const file = e.target.files?.[0]
  if (!file) return
  
  const reader = new FileReader()
  reader.onload = (event) => {
    addObject({
      type: 'image',
      x: 100,
      y: 100,
      width: 400,
      height: 300,
      imageUrl: event.target.result,
      objectFit: 'contain',
    })
  }
  reader.readAsDataURL(file)
  
  e.target.value = ''
}

// Handle native paste event (screenshots, copied images)
function handlePasteEvent(e) {
  isDebugEnabled() && console.log('[Paste] Paste event fired')
  
  // Don't handle if in an input or contenteditable
  const activeEl = document.activeElement
  const isInFormField = activeEl?.tagName === 'INPUT' || activeEl?.tagName === 'TEXTAREA'
  const isInContentEditable = activeEl?.isContentEditable || activeEl?.closest('[contenteditable="true"]')
  
  if (isInFormField || isInContentEditable) {
    isDebugEnabled() && console.log('[Paste] In form field or contenteditable, skipping')
    return
  }
  if (!canEdit.value) {
    isDebugEnabled() && console.log('[Paste] Cannot edit, skipping')
    return
  }
  
  // Check for images in clipboard data
  const items = e.clipboardData?.items
  isDebugEnabled() && console.log('[Paste] Clipboard items:', items?.length || 0)
  
  if (!items) return
  
  for (const item of items) {
    isDebugEnabled() && console.log('[Paste] Item type:', item.type, 'kind:', item.kind)
    if (item.type.startsWith('image/')) {
      e.preventDefault()
      const blob = item.getAsFile()
      isDebugEnabled() && console.log('[Paste] Got image blob:', blob?.size, 'bytes')
      if (blob) {
        processImageBlob(blob)
      }
      return
    }
  }
  isDebugEnabled() && console.log('[Paste] No image found in clipboard')
}

// Process an image blob and add it to the slide
async function processImageBlob(blob) {
  isDebugEnabled() && console.log('[Paste] Processing image blob:', blob?.size, 'bytes')
  
  return new Promise((resolve) => {
    const reader = new FileReader()
    
    reader.onload = (event) => {
      isDebugEnabled() && console.log('[Paste] FileReader loaded, data length:', event.target.result?.length)
      
      // Create image to get dimensions
      const img = new Image()
      img.onload = () => {
        isDebugEnabled() && console.log('[Paste] Image loaded, dimensions:', img.width, 'x', img.height)
        
        // Calculate dimensions - fit to slide while maintaining aspect ratio
        const maxWidth = slideWidth.value * 0.6 // Max 60% of slide width
        const maxHeight = slideHeight.value * 0.6 // Max 60% of slide height
        
        let width = img.width
        let height = img.height
        
        // Scale down if too large
        if (width > maxWidth) {
          height = (height * maxWidth) / width
          width = maxWidth
        }
        if (height > maxHeight) {
          width = (width * maxHeight) / height
          height = maxHeight
        }
        
        // Center the image on the slide
        const x = (slideWidth.value - width) / 2
        const y = (slideHeight.value - height) / 2
        
        isDebugEnabled() && console.log('[Paste] Adding image object at', x, y, 'size:', width, 'x', height)
        
        addObject({
          type: 'image',
          x,
          y,
          width,
          height,
          imageUrl: event.target.result,
          objectFit: 'contain',
        })
        
        isDebugEnabled() && console.log('[Paste] Image added successfully')
        resolve(true) // Image was pasted
      }
      img.onerror = (err) => {
        console.error('[Paste] Image load error:', err)
        resolve(false)
      }
      img.src = event.target.result
    }
    reader.onerror = (err) => {
      console.error('[Paste] FileReader error:', err)
      resolve(false)
    }
    reader.readAsDataURL(blob)
  })
}

function selectObject(id, addToSelection = false) {
  select(id, addToSelection)
}

function clearSelection() {
  deselect()
}

function updateObject(slideIndex, objectId, updates) {
  updateObj(slideIndex, objectId, updates)
}

function updateSelectedObject(updates) {
  if (selectedObjectIds.value.length === 1) {
    updateObj(currentSlideIndex.value, selectedObjectIds.value[0], updates)
  }
}

function deleteSelectedObjects() {
  deleteSelected()
}

function applyTheme() {
  setTheme(selectedTheme.value)
  
  const bg = getThemeBackground(selectedTheme.value)
  slides.value.forEach((_, index) => {
    setSlideBackground(index, bg)
  })
}

function applyThemeById(themeId) {
  selectedTheme.value = themeId
  applyTheme()
}

function handleBackgroundApply(background) {
  setSlideBackground(currentSlideIndex.value, background)
}

function handleDriveImageSelect(files) {
  showDrivePickerForBg.value = false
  
  if (files && files.length > 0) {
    const file = files[0]
    const imageUrl = file.download_url || file.url || `/api/drive/download/${file.id}`
    
    if (backgroundModalRef.value) {
      backgroundModalRef.value.setImageFromDrive(imageUrl)
    }
  }
}

function onCursorMove(position) {
  updateCursor({
    slideIndex: currentSlideIndex.value,
    x: position.x,
    y: position.y,
    selectedObjectId: selectedObjectIds.value[0] || null,
  })
}

// Mouse pointer tracking
let mouseThrottle = null
function handleCanvasMouseMove(event) {
  if (!provider.value?.awareness) return
  
  if (mouseThrottle) return
  mouseThrottle = setTimeout(() => { mouseThrottle = null }, 66)
  
  const canvasEl = slideCanvasRef.value?.$el
  if (!canvasEl) return
  
  const rect = canvasEl.getBoundingClientRect()
  
  const rawX = event.clientX - rect.left
  const rawY = event.clientY - rect.top
  
  const scaleX = rect.width / slideWidth.value
  const scaleY = rect.height / slideHeight.value
  const scale = Math.min(scaleX, scaleY)
  
  const x = rawX / scale
  const y = rawY / scale
  
  updateMousePointer({
    slideIndex: currentSlideIndex.value,
    x,
    y,
  })
}

function handleCanvasMouseLeave() {
  clearMousePointer()
}

function setupMousePointerListener() {
  if (!provider.value?.awareness) return
  
  const awareness = provider.value.awareness
  
  const updatePointers = () => {
    const states = awareness.getStates()
    const newStates = new Map()
    
    states.forEach((state, clientId) => {
      if (clientId !== awareness.clientID) {
        newStates.set(clientId, state)
      }
    })
  }
  
  awareness.on('change', updatePointers)
  updatePointers()
  
  return () => {
    awareness.off('change', updatePointers)
  }
}

let cleanupMouseListener = null

watch(() => provider.value, (newProvider) => {
  if (newProvider?.awareness) {
    cleanupMouseListener = setupMousePointerListener()
  }
}, { immediate: true })

function startPresentation() {
  isPresentationMode.value = true
}

function exitPresentation() {
  isPresentationMode.value = false
}

// Comment handlers
function handleGotoComment(slideIndex, objectId) {
  navigateToSlide(slideIndex)
  if (objectId) {
    select(objectId)
  }
}

function handleAddComment(data) {
  addComment(data)
}

function handleReplyComment(data) {
  replyToComment(data.threadId, data.content)
}

function handleVersionRestored(version) {
  isDebugEnabled() && console.log('[Presentation] Version restored:', version.version_number)
}

// Layer controls
function bringForward() {
  if (selectedObjectIds.value.length === 1) {
    bringToFront(currentSlideIndex.value, selectedObjectIds.value[0])
  }
}

function sendBackward() {
  if (selectedObjectIds.value.length === 1) {
    sendToBack(currentSlideIndex.value, selectedObjectIds.value[0])
  }
}

// Align object to artboard position
function alignToArtboard(position) {
  if (selectedObjectIds.value.length === 0) return
  
  const obj = selectedObject.value
  if (!obj) return
  
  let newX = obj.x
  let newY = obj.y
  const padding = 40 // Padding from edges
  
  switch (position) {
    case 'top-left':
      newX = padding
      newY = padding
      break
    case 'top-center':
      newX = (slideWidth.value - obj.width) / 2
      newY = padding
      break
    case 'top-right':
      newX = slideWidth.value - obj.width - padding
      newY = padding
      break
    case 'middle-left':
      newX = padding
      newY = (slideHeight.value - obj.height) / 2
      break
    case 'center':
      newX = (slideWidth.value - obj.width) / 2
      newY = (slideHeight.value - obj.height) / 2
      break
    case 'middle-right':
      newX = slideWidth.value - obj.width - padding
      newY = (slideHeight.value - obj.height) / 2
      break
    case 'bottom-left':
      newX = padding
      newY = slideHeight.value - obj.height - padding
      break
    case 'bottom-center':
      newX = (slideWidth.value - obj.width) / 2
      newY = slideHeight.value - obj.height - padding
      break
    case 'bottom-right':
      newX = slideWidth.value - obj.width - padding
      newY = slideHeight.value - obj.height - padding
      break
  }
  
  updateObject(currentSlideIndex.value, obj.id, { x: newX, y: newY })
}

// Handle Alt+Drag duplicate
function handleDuplicateDrag(slideIndex, originalObj) {
  // Create a copy of the object
  const objCopy = { ...originalObj }
  delete objCopy.id // Remove ID so addObject creates a new one
  
  // Add the duplicated object (it will be at the same position initially)
  const newId = addObject(objCopy)
  
  if (newId) {
    // Select the new object (it will be the one being dragged)
    selectObject(newId, false)
  }
}

// Handle context menu actions
function handleContextAction(event) {
  const { action, objectId, objectType, slideIndex } = event
  
  switch (action) {
    case 'duplicate':
      duplicateObj(slideIndex, objectId)
      break
      
    case 'copy':
      copySelectedObjects()
      break
      
    case 'cut':
      copySelectedObjects()
      deleteSelectedObjects()
      break
      
    case 'bringToFront':
      bringToFront(slideIndex, objectId)
      break
      
    case 'sendToBack':
      sendToBack(slideIndex, objectId)
      break
      
    case 'delete':
      removeObject(slideIndex, objectId)
      break
  }
}

// Copy selected objects to clipboard
function copySelectedObjects() {
  if (selectedObjectIds.value.length === 0) return
  
  // Get the selected objects
  const objectsToCopy = currentSlideObjects.value.filter(
    obj => selectedObjectIds.value.includes(obj.id)
  )
  
  if (objectsToCopy.length === 0) return
  
  // Deep clone the objects and remove their IDs so they'll get new ones when pasted
  clipboard.value = objectsToCopy.map(obj => {
    const clone = JSON.parse(JSON.stringify(obj))
    delete clone.id // Remove ID so a new one is generated on paste
    return clone
  })
  
  isDebugEnabled() && console.log(`Copied ${clipboard.value.length} object(s) to clipboard`)
}

// Paste objects from clipboard
function pasteObjects() {
  if (!clipboard.value || clipboard.value.length === 0 || !canEdit.value) return
  
  // Offset pasted objects slightly so they don't overlap exactly
  const PASTE_OFFSET = 20
  
  const newIds = []
  
  for (const obj of clipboard.value) {
    // Create a new object with offset position
    const newObj = {
      ...obj,
      x: (obj.x || 0) + PASTE_OFFSET,
      y: (obj.y || 0) + PASTE_OFFSET,
    }
    
    // Add the object to the current slide
    const newId = addObject(newObj)
    if (newId) {
      newIds.push(newId)
    }
  }
  
  // Select the newly pasted objects
  if (newIds.length > 0) {
    // Clear current selection and select new objects
    deselect()
    for (const id of newIds) {
      selectObject(id, true) // true for multi-select
    }
  }
  
  // Update clipboard positions for next paste (so repeated paste creates a cascade)
  clipboard.value = clipboard.value.map(obj => ({
    ...obj,
    x: (obj.x || 0) + PASTE_OFFSET,
    y: (obj.y || 0) + PASTE_OFFSET,
  }))
  
  isDebugEnabled() && console.log(`Pasted ${newIds.length} object(s)`)
}

// Duplicate selected objects (Ctrl+D)
function duplicateSelectedObjects() {
  if (selectedObjectIds.value.length === 0 || !canEdit.value) return
  
  // Get selected objects and copy them
  copySelectedObjects()
  
  // Immediately paste
  pasteObjects()
}
</script>
