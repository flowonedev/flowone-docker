<template>
  <div class="collab-document-editor flex h-full" style="background: #eaeaea;">
    <!-- Main editor area -->
    <div class="flex flex-col flex-1 min-w-0">
      <!-- Header (hidden in print) -->
      <header class="print-hide document-header" style="background: #ffffff; border-bottom: 1px solid #e2e8f0;">
        <!-- Top row: Logo, Title, Actions -->
        <div class="flex items-center px-2 md:px-4 py-2">
        <!-- Back button -->
        <button 
          @click="$emit('close')"
            class="p-2 hover:bg-surface-100 rounded-xl transition-colors mr-2"
            title="Back to documents"
        >
            <span class="material-symbols-rounded text-primary-500" style="font-size: 24px;">arrow_back</span>
        </button>
        
          <div class="flex flex-col min-w-0 flex-1">
        <!-- Editable title -->
        <input
          v-model="localTitle"
          @blur="saveTitle"
          @keydown.enter="$event.target.blur()"
          :disabled="!canEdit"
              class="text-lg font-medium text-surface-900 bg-transparent border-none focus:outline-none hover:bg-surface-50 focus:bg-surface-50 rounded-lg px-2 py-1 -ml-2 transition-colors"
          :class="{ 'cursor-not-allowed opacity-60': !canEdit }"
              placeholder="Untitled document"
            />
            
            <!-- Status indicator -->
            <div class="flex items-center gap-2 ml-2 text-sm" :class="statusClass">
              <span v-if="status === 'connected'" class="text-surface-500">{{ statusText }}</span>
              <span v-else class="flex items-center gap-1">
          <span class="w-2 h-2 rounded-full" :class="statusDotClass"></span>
                {{ statusText }}
              </span>
            </div>
        </div>
        
          <!-- Document stats indicator (moved to header) -->
          <div class="document-stats-indicator-header">
            <span>{{ totalPages }} {{ totalPages === 1 ? 'page' : 'pages' }}</span>
            <span class="stats-divider"></span>
            <span>{{ wordCount.toLocaleString() }} {{ wordCount === 1 ? 'word' : 'words' }}</span>
            <span class="stats-divider"></span>
            <span>{{ charCount.toLocaleString() }} characters</span>
          </div>
        
          <!-- Right side actions -->
          <div class="flex items-center gap-1 md:gap-1.5">
            <!-- Presence avatars (all active users including yourself) - hidden on mobile -->
            <CollabPresenceAvatars 
              v-if="allActiveUsers.length > 0 && !isMobile"
              :users="allActiveUsers"
          :maxVisible="5"
              :currentUserEmail="props.user.email"
            />
            
            <div v-if="!isMobile" class="w-px h-6 bg-surface-200 mx-1"></div>
            
            <!-- Comments toggle button -->
            <button 
              @click="showComments = !showComments"
              class="header-icon-btn relative"
              :class="{ 'active': showComments }"
              title="Comments"
            >
              <span class="material-symbols-rounded">chat_bubble</span>
              <span 
                v-if="openCommentCount > 0"
                class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-primary-500 text-white text-[10px] font-semibold rounded-full flex items-center justify-center"
              >
                {{ openCommentCount > 9 ? '9+' : openCommentCount }}
              </span>
            </button>
            
            <!-- Share button -->
        <button
          v-if="canShare"
          @click="$emit('share')"
              class="share-btn"
        >
              <span class="material-symbols-rounded">share</span>
              <span class="hidden md:inline">Share</span>
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
                class="absolute right-0 top-full mt-1 w-52 bg-white rounded-xl shadow-lg border border-surface-200 py-1 z-50"
            @click.stop
          >
            <button 
                  @click="saveVersion"
                  class="w-full px-4 py-2.5 text-left text-sm text-surface-700 hover:bg-surface-100 flex items-center gap-3 transition-colors"
            >
                  <span class="material-symbols-rounded text-surface-500" style="font-size: 20px;">history</span>
              Save version
            </button>
            <button 
                  @click="showMenu = false; showVersionHistory = true"
                  class="w-full px-4 py-2.5 text-left text-sm text-surface-700 hover:bg-surface-100 flex items-center gap-3 transition-colors"
            >
                  <span class="material-symbols-rounded text-surface-500" style="font-size: 20px;">restore</span>
              Version history
            </button>
                <hr class="my-1 border-surface-200" />
            <button 
              @click="exportDocx"
                  class="w-full px-4 py-2.5 text-left text-sm text-surface-700 hover:bg-surface-100 flex items-center gap-3 transition-colors"
            >
                  <span class="material-symbols-rounded text-surface-500" style="font-size: 20px;">download</span>
                  Download
            </button>
            <button 
              v-if="driveFileId"
              @click="saveToDrive"
              :disabled="isSavingToDrive"
              class="w-full px-4 py-2.5 text-left text-sm text-surface-700 hover:bg-surface-100 flex items-center gap-3 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span class="material-symbols-rounded text-surface-500" style="font-size: 20px;">
                {{ isSavingToDrive ? 'sync' : 'cloud_upload' }}
              </span>
              {{ isSavingToDrive ? 'Saving...' : 'Save to Drive' }}
            </button>
            <button 
              @click="$emit('duplicate')"
                  class="w-full px-4 py-2.5 text-left text-sm text-surface-700 hover:bg-surface-100 flex items-center gap-3 transition-colors"
            >
                  <span class="material-symbols-rounded text-surface-500" style="font-size: 20px;">content_copy</span>
              Make a copy
            </button>
                <hr class="my-1 border-surface-200" />
                <button 
                  @click="showMenu = false; showHeaderFooterSettings = true"
                  class="w-full px-4 py-2.5 text-left text-sm text-surface-700 hover:bg-surface-100 flex items-center gap-3 transition-colors"
                >
                  <span class="material-symbols-rounded text-surface-500" style="font-size: 20px;">article</span>
                  Header & Footer
                </button>
                <button 
                  @click="showMenu = false; printDocument()"
                  class="w-full px-4 py-2.5 text-left text-sm text-surface-700 hover:bg-surface-100 flex items-center gap-3 transition-colors"
                >
                  <span class="material-symbols-rounded text-surface-500" style="font-size: 20px;">print</span>
                  Print
        </button>
      </div>
            </div>
          </div>
        </div>
    
    <!-- Toolbar (hidden in print) -->
    <div class="print-hide">
      <CollabDocumentToolbar
        v-if="editor && canEdit"
        :editor="editor"
        :canUndo="canUndo"
        :canRedo="canRedo"
            :documentTitle="localTitle"
        @undo="undo"
        @redo="redo"
            @add-comment="openCommentForSelection"
            @save-docx="handleSaveDocx"
      />
    </div>
      </header>
      
      <!-- Document Ruler - FIXED to header bottom, not scrolling -->
      <div v-if="editor && !isMobile" class="editor-ruler-container print-hide">
        <DocumentRuler
          v-model:left-margin="pageMargins.left"
          v-model:right-margin="pageMargins.right"
          :page-width="pageWidth"
          :visible="true"
          @margins-changed="handleMarginsChanged"
        />
      </div>
      
      <!-- Mobile stats indicator (shown only on mobile) -->
      <div class="mobile-stats-indicator print-hide" v-if="isMobile">
        <span>{{ totalPages }} {{ totalPages === 1 ? 'page' : 'pages' }}</span>
        <span class="stats-divider"></span>
        <span>{{ wordCount.toLocaleString() }} {{ wordCount === 1 ? 'word' : 'words' }}</span>
      </div>
    
    <!-- Editor area with page-like styling -->
      <div 
        ref="editorAreaRef"
        class="collab-editor-area flex-1 overflow-auto py-4 md:py-8 px-2 md:px-4"
        @mousemove="handleMouseMove"
        @mouseleave="handleMouseLeave"
        @contextmenu="handleContextMenu"
        @touchstart="handleTouchStart"
        @touchmove="handleTouchMove"
        @touchend="handleTouchEnd"
      >
        <!-- Remote mouse cursors overlay -->
        <div class="remote-cursors-container">
          <div
            v-for="cursor in remoteCursors"
            :key="cursor.clientId"
            class="remote-cursor"
            :style="{ left: cursor.x + 'px', top: cursor.y + 'px' }"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" class="cursor-pointer-icon">
              <path d="M5.5 3.21V20.8c0 .45.54.67.85.35l4.86-4.86a.5.5 0 0 1 .35-.15h6.87a.5.5 0 0 0 .35-.85L6.35 2.86a.5.5 0 0 0-.85.35Z" :fill="cursor.color" stroke="#fff" stroke-width="1.5"/>
            </svg>
            <span
              class="remote-cursor-label"
              :style="{ backgroundColor: cursor.color }"
            >{{ cursor.name }}</span>
          </div>
        </div>

        <!-- Page container - centers content -->
        <div class="collab-page-wrapper mx-auto">
          <!-- Loading state -->
          <div v-if="isLoading" class="collab-page collab-page-loading">
            <div class="flex flex-col items-center justify-center h-full gap-3">
              <div class="spinner text-primary-500"></div>
              <span class="text-surface-500">Loading document...</span>
            </div>
          </div>
          
          <!-- Error state -->
          <div v-else-if="error" class="collab-page collab-page-loading">
            <div class="flex flex-col items-center justify-center h-full gap-3 text-center">
              <span class="material-symbols-rounded text-red-500" style="font-size: 48px;">error</span>
              <p class="text-surface-700">{{ error }}</p>
              <button @click="reconnect" class="btn btn-primary">Try again</button>
            </div>
          </div>
          
          <!-- Syncing state -->
          <div v-else-if="!editor" class="collab-page collab-page-loading">
            <div class="flex flex-col items-center justify-center h-full gap-3">
              <div class="spinner text-primary-500"></div>
              <span class="text-surface-500">{{ !isSynced ? 'Syncing document...' : 'Initializing editor...' }}</span>
            </div>
          </div>
          
          <!-- Single Paginated Editor -->
          <div 
            v-else
            ref="pagesContainer"
            class="tiptap-pages-wrapper"
            :class="{ 'cursor-not-allowed': !canEdit }"
          >
            <div class="tiptap-pages">
              <editor-content 
                :editor="editor" 
                class="collab-editor-content"
                @mouseup="checkSelection"
              />
            </div>
            
            <!-- Table controller -->
            <TableController
              v-for="table in activeTables"
              :key="table.id"
              :editor="editor"
              :table-element="table.element"
              :visible="selectedTable === table.id"
              :can-edit="canEdit"
            />
          </div>
        </div>
      </div>
    </div>
    
    <!-- Comments panel (slide from right) -->
    <transition name="slide-right">
      <CollabCommentsPanel
        v-if="showComments"
        :threads="commentThreads"
        :user="props.user"
        :selectedText="selectedText"
        :hasSelection="hasSelection"
        @close="showComments = false"
        @add-comment="handleAddComment"
        @add-reply="handleAddReply"
        @resolve="handleResolveThread"
        @unresolve="handleUnresolveThread"
        @select-thread="handleSelectThread"
      />
    </transition>
    
    <!-- Version History Panel -->
    <CollabVersionHistoryPanel
      :show="showVersionHistory"
      :document-uuid="props.documentUuid"
      :document-title="localTitle"
      document-type="document"
      @close="showVersionHistory = false"
      @restored="handleVersionRestored"
    />
    
    <!-- Print Preview Modal -->
    <PrintPreviewModal
      :show="showPrintPreview"
      :document-title="localTitle"
      :html-content="editor?.getHTML() || ''"
      :header-text="headerText"
      :footer-text="footerText"
      :initial-show-page-numbers="showPageNumbers"
      @close="showPrintPreview = false"
      @print="handlePrintFromPreview"
    />
    
    <!-- Header/Footer Settings Modal -->
    <Teleport to="body">
      <div v-if="showHeaderFooterSettings" class="hf-modal-overlay" @click.self="showHeaderFooterSettings = false">
        <div class="hf-modal">
          <div class="hf-modal-header">
            <h3>Header & Footer</h3>
            <button @click="showHeaderFooterSettings = false" class="hf-close-btn">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          <div class="hf-modal-body">
            <div class="hf-field">
              <label>Header Text</label>
              <input 
                v-model="headerText" 
                type="text" 
                placeholder="Enter header text (appears on all pages)"
              />
              <span class="hf-hint">Appears at the top of each printed page</span>
            </div>
            <div class="hf-field">
              <label>Footer Text</label>
              <input 
                v-model="footerText" 
                type="text" 
                placeholder="Enter footer text (appears on all pages)"
              />
              <span class="hf-hint">Appears at the bottom of each printed page</span>
            </div>
            <div class="hf-checkbox">
              <label class="flex items-center gap-3 cursor-pointer">
                <div 
                  class="toggle-switch"
                  :class="{ 'active': showPageNumbers }"
                  @click="showPageNumbers = !showPageNumbers"
                >
                  <div class="toggle-knob"></div>
                </div>
                <span>Show page numbers in footer</span>
              </label>
            </div>
          </div>
          <div class="hf-modal-footer">
            <button @click="showHeaderFooterSettings = false" class="hf-btn secondary">Cancel</button>
            <button @click="showHeaderFooterSettings = false" class="hf-btn primary">Apply</button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Print-only header/footer (hidden normally, shown when printing) -->
    <div class="print-header" v-if="headerText">{{ headerText }}</div>
    <div class="print-footer">
      <span v-if="footerText">{{ footerText }}</span>
      <span v-if="footerText && showPageNumbers"> | </span>
      <span v-if="showPageNumbers" class="page-number"></span>
    </div>
    
    <!-- Floating add comment button (appears on text selection) -->
    <div 
      v-if="hasSelection && !showComments && canEdit"
      class="floating-comment-btn"
      :style="{ top: selectionPosition.y + 'px' }"
    >
      <button 
        @click="openCommentForSelection"
        class="comment-btn-inner"
        title="Add comment"
      >
        <span class="material-symbols-rounded">add_comment</span>
      </button>
    </div>

    <!-- Context Menu (right-click) -->
    <Teleport to="body">
      <div 
        v-if="showContextMenu"
        class="context-menu"
        :style="{ top: contextMenuPosition.y + 'px', left: contextMenuPosition.x + 'px' }"
        @click.stop
      >
        <!-- Edit actions -->
        <button @click="contextAction('cut')" class="context-menu-item" :disabled="!hasSelection || !canEdit">
          <span class="material-symbols-rounded">content_cut</span>
          <span class="item-label">Cut</span>
          <span class="item-shortcut">Ctrl+X</span>
        </button>
        <button @click="contextAction('copy')" class="context-menu-item" :disabled="!hasSelection">
          <span class="material-symbols-rounded">content_copy</span>
          <span class="item-label">Copy</span>
          <span class="item-shortcut">Ctrl+C</span>
        </button>
        <button @click="contextAction('paste')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">content_paste</span>
          <span class="item-label">Paste</span>
          <span class="item-shortcut">Ctrl+V</span>
        </button>
        <button @click="contextAction('delete')" class="context-menu-item" :disabled="!hasSelection || !canEdit">
          <span class="material-symbols-rounded">delete</span>
          <span class="item-label">Delete</span>
          <span class="item-shortcut"></span>
        </button>
        
        <div class="context-menu-divider"></div>
        
        <!-- Comment -->
        <button @click="contextAction('comment')" class="context-menu-item" :disabled="!hasSelection || !canEdit">
          <span class="material-symbols-rounded">add_comment</span>
          <span class="item-label">Comment</span>
          <span class="item-shortcut">Ctrl+Alt+M</span>
        </button>
        
        <div class="context-menu-divider"></div>
        
        <!-- Insert actions -->
        <button @click="contextAction('link')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">link</span>
          <span class="item-label">Insert link</span>
          <span class="item-shortcut">Ctrl+K</span>
        </button>
        <button @click="contextAction('image')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">image</span>
          <span class="item-label">Insert image</span>
          <span class="item-shortcut"></span>
        </button>
        <button @click="contextAction('table')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">table_chart</span>
          <span class="item-label">Insert table</span>
          <span class="item-shortcut"></span>
        </button>
        <button @click="contextAction('divider')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">horizontal_rule</span>
          <span class="item-label">Insert divider</span>
          <span class="item-shortcut"></span>
        </button>
        <button @click="contextAction('codeBlock')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">code_blocks</span>
          <span class="item-label">Insert code block</span>
          <span class="item-shortcut"></span>
        </button>
        
        <div class="context-menu-divider"></div>
        
        <!-- Formatting -->
        <button @click="contextAction('bold')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">format_bold</span>
          <span class="item-label">Bold</span>
          <span class="item-shortcut">Ctrl+B</span>
        </button>
        <button @click="contextAction('italic')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">format_italic</span>
          <span class="item-label">Italic</span>
          <span class="item-shortcut">Ctrl+I</span>
        </button>
        <button @click="contextAction('underline')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">format_underlined</span>
          <span class="item-label">Underline</span>
          <span class="item-shortcut">Ctrl+U</span>
        </button>
        
        <div class="context-menu-divider"></div>
        
        <button @click="contextAction('clearFormat')" class="context-menu-item" :disabled="!canEdit">
          <span class="material-symbols-rounded">format_clear</span>
          <span class="item-label">Clear formatting</span>
          <span class="item-shortcut">Ctrl+\\</span>
        </button>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import Link from '@tiptap/extension-link'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import TextAlign from '@tiptap/extension-text-align'
import Table from '@tiptap/extension-table'
import TableRow from '@tiptap/extension-table-row'
import TableCell from '@tiptap/extension-table-cell'
import TableHeader from '@tiptap/extension-table-header'
import Collaboration from '@tiptap/extension-collaboration'
import CollaborationCursor from '@tiptap/extension-collaboration-cursor'
import TextStyle from '@tiptap/extension-text-style'
import { Color } from '@tiptap/extension-color'
import Highlight from '@tiptap/extension-highlight'
import Subscript from '@tiptap/extension-subscript'
import Superscript from '@tiptap/extension-superscript'
import FontFamily from '@tiptap/extension-font-family'
import { FontSize } from '../extensions/FontSize.js'
import { Pagination } from '../extensions/Pagination.js'
import { HardPageBreak } from '../extensions/HardPageBreak.js'
import { useCollabDocument } from '../composables/useCollabDocument.js'
import { useCollabComments } from '../composables/useCollabComments.js'
import { exportToDocx } from '../services/docxExportService.js'
import { useToastStore } from '@/stores/toast'
import { useCollabStore } from '../stores/collabStore'
import { isDebugEnabled } from '@/utils/debug'
import CollabDocumentToolbar from './CollabDocumentToolbar.vue'
import CollabPresenceAvatars from './CollabPresenceAvatars.vue'
import CollabCommentsPanel from './CollabCommentsPanel.vue'
import DocumentRuler from './DocumentRuler.vue'
import TableController from './TableController.vue'
import CollabVersionHistoryPanel from './CollabVersionHistoryPanel.vue'
import PrintPreviewModal from './PrintPreviewModal.vue'
import {
  PAGE_WIDTH,
  PAGE_HEIGHT,
  PAGE_CONTENT_HEIGHT,
  PAGE_MARGIN_TOP,
  PAGE_MARGIN_BOTTOM,
  PAGE_MARGIN_LEFT,
  PAGE_MARGIN_RIGHT
} from '../services/pagination/constants.js'
import '../styles/pagination.css'

const props = defineProps({
  documentUuid: {
    type: String,
    required: true,
  },
  user: {
    type: Object,
    required: true, // { email, name }
  },
  driveFileId: {
    type: Number,
    default: null, // If set, document is linked to a Drive file and can be saved back
  },
})

const emit = defineEmits(['close', 'share', 'versions', 'duplicate', 'export'])

const toast = useToastStore()
const collabStore = useCollabStore()

// Save to Drive state
const isSavingToDrive = ref(false)

// Collaboration composable
const {
  ydoc,
  provider,
  isConnected,
  isSynced,
  status,
  isInitialized,
  isLoading,
  error,
  documentTitle,
  users,
  otherUsers,
  canEdit,
  canShare,
  isOwner,
  init,
  destroy,
  reconnect,
  setTitle,
  createSnapshot,
  canUndo,
  canRedo,
  undo,
  redo,
  getCollaborationConfig,
  getCollaborationCursorConfig,
  getCollabUserColor,
} = useCollabDocument({ user: props.user })

// Comments composable
const userRef = computed(() => props.user)
const {
  threads: commentThreads,
  openThreads,
  addThread,
  addReply,
  resolveThread: resolveCommentThread,
  unresolveThread: unresolveCommentThread,
  init: initComments,
} = useCollabComments({ ydoc, user: userRef })

// Computed comment count
const openCommentCount = computed(() => openThreads.value.length)

// All active users including yourself (for presence avatars)
const allActiveUsers = computed(() => {
  const currentUserEmail = props.user.email?.toLowerCase()
  const currentUser = {
    email: props.user.email,
    name: props.user.name || props.user.email?.split('@')[0],
    color: getCollabUserColor(props.user.email),
    isYou: true,
  }
  
  // Filter out our own email (stale sessions from reconnects)
  // and deduplicate by email/clientId to prevent duplicates
  const seenKeys = new Set([currentUserEmail])
  const others = (otherUsers.value || [])
    .filter(u => {
      const email = u.email?.toLowerCase()
      // Use email as primary dedup key, fall back to clientId
      const key = email || `client-${u.clientId}`
      if (email === currentUserEmail || seenKeys.has(key)) {
        return false
      }
      seenKeys.add(key)
      return true
    })
    .map(u => ({
      ...u,
      // Ensure color is always set (fallback to hash-based color)
      color: u.color || getCollabUserColor(u.email || `anon-${u.clientId}`),
      name: u.name || u.email?.split('@')[0] || 'Anonymous',
      isYou: false,
    }))
  
  // Put yourself first
  return [currentUser, ...others]
})

// Local state
const localTitle = ref('')
const showMenu = ref(false)
const editorInstance = ref(null)

// Editor computed with null check - must be defined before watches that use it
const editor = computed(() => {
  return editorInstance.value
})

const showComments = ref(false)
const showVersionHistory = ref(false)
const showPrintPreview = ref(false)
const selectedText = ref('')
const hasSelection = ref(false)
const selectionPosition = ref({ x: 0, y: 0 })
const pagesContainer = ref(null)
const editorAreaRef = ref(null)

// Mobile detection
const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

// Page margins (editable via ruler)
const pageMargins = ref({
  left: PAGE_MARGIN_LEFT,
  right: PAGE_MARGIN_RIGHT,
  top: PAGE_MARGIN_TOP,
  bottom: PAGE_MARGIN_BOTTOM
})

const pageWidth = PAGE_WIDTH
const pageHeight = PAGE_HEIGHT


// Touch handling for mobile
let touchStartY = 0
function handleTouchStart(e) {
  if (e.touches.length === 1) {
    touchStartY = e.touches[0].clientY
  }
}

function handleTouchMove(e) {
  // Allow default scroll behavior
}

function handleTouchEnd(e) {
  // Handle touch end if needed
}

// Table controller state
const activeTables = ref([])
const selectedTable = ref(null)

// Watch for table selection in editor
watch(() => editor.value, (newEditor) => {
  if (!newEditor) return
  
  // Listen for selection changes to detect table selection
  newEditor.on('selectionUpdate', () => {
    updateActiveTables()
  })
  
  // Listen for updates to detect new tables
  newEditor.on('update', () => {
    nextTick(() => {
      updateActiveTables()
    })
  })
  
  // Listen for clicks to detect table clicks
  newEditor.on('create', ({ editor: createdEditor }) => {
    const view = createdEditor.view
    const handleClick = (event) => {
      const target = event.target
      if (target.closest('table')) {
        const table = target.closest('table')
        const tableId = activeTables.value.find(t => t.element === table)?.id
        if (tableId) {
          selectedTable.value = tableId
        } else {
          updateActiveTables()
        }
      } else if (!target.closest('.table-controller')) {
        // Click outside table - hide controls after a short delay
        setTimeout(() => {
          if (!createdEditor.state.selection.$anchor.node(-1)?.type.name.includes('table') &&
              !createdEditor.state.selection.$anchor.node(-2)?.type.name.includes('table')) {
            selectedTable.value = null
          }
        }, 100)
      }
    }
    
    view.dom.addEventListener('click', handleClick)
    
    return () => {
      view.dom.removeEventListener('click', handleClick)
    }
  })
  
  updateActiveTables()
})

function updateActiveTables() {
  if (!editor.value || !pagesContainer.value) {
    activeTables.value = []
    return
  }
  
  const proseMirror = pagesContainer.value.querySelector('.ProseMirror')
  if (!proseMirror) return
  
  // Find all tables in the document
  const tables = proseMirror.querySelectorAll('table')
  const newTables = Array.from(tables).map((table, index) => ({
    id: `table-${index}-${Date.now()}`,
    element: table
  }))
  
  // Preserve selection if table still exists
  const currentSelected = selectedTable.value
  const stillExists = newTables.find(t => {
    const oldTable = activeTables.value.find(at => at.id === currentSelected)
    return oldTable && t.element === oldTable.element
  })
  
  activeTables.value = newTables
  
  if (stillExists) {
    selectedTable.value = stillExists.id
  } else {
    // Check if cursor is in a table
    const { $anchor } = editor.value.state.selection
    const isInTable = $anchor.node(-1)?.type.name === 'table' || 
                      $anchor.node(-2)?.type.name === 'table' ||
                      $anchor.node(-3)?.type.name === 'table'
    
    if (isInTable) {
      // Find which table element corresponds to current position
      const pos = editor.value.state.selection.$anchor.pos
      const tableAtPos = newTables.find(t => {
        try {
          const tablePos = editor.value.view.posAtDOM(t.element, 0)
          if (tablePos === null) return false
          const tableEnd = tablePos + t.element.offsetHeight // Approximate
          return pos >= tablePos && pos <= tableEnd
        } catch {
          return false
        }
      })
      
      if (tableAtPos) {
        selectedTable.value = tableAtPos.id
      }
    } else if (!currentSelected || !stillExists) {
      selectedTable.value = null
    }
  }
}

// Pagination - now handled by the Pagination extension
// Total pages is computed from the extension storage
const totalPages = computed(() => {
  if (!editor.value) return 1
  return editor.value.storage.pagination?.totalPages || 1
})

// Context menu state
const showContextMenu = ref(false)
const contextMenuPosition = ref({ x: 0, y: 0 })

// Header/Footer settings for print
const showHeaderFooterSettings = ref(false)
const headerText = ref('')
const footerText = ref('')
const showPageNumbers = ref(true)

// Remote mouse pointer tracking (separate from text cursor)
// Uses 'mousePointer' field to avoid conflict with CollaborationCursor's 'cursor' field
const remoteMousePointers = ref(new Map())

const remoteCursors = computed(() => {
  const cursors = []
  remoteMousePointers.value.forEach((state, clientId) => {
    if (state.mousePointer && state.user && state.user.email !== props.user.email) {
      cursors.push({
        clientId,
        x: state.mousePointer.x,
        y: state.mousePointer.y,
        color: state.user.color || getCollabUserColor(state.user.email || 'anonymous'),
        name: state.user.name || state.user.email?.split('@')[0] || 'Anonymous',
      })
    }
  })
  return cursors
})

// Handle mouse movement - broadcast position using separate field
let mouseThrottle = null
function handleMouseMove(event) {
  if (!provider.value?.awareness || !editorAreaRef.value) return
  
  // Throttle to ~15fps for performance (less aggressive)
  if (mouseThrottle) return
  mouseThrottle = setTimeout(() => { mouseThrottle = null }, 66)
  
  const rect = editorAreaRef.value.getBoundingClientRect()
  const x = event.clientX - rect.left + editorAreaRef.value.scrollLeft
  const y = event.clientY - rect.top + editorAreaRef.value.scrollTop
  
  // Use 'mousePointer' field to avoid conflict with CollaborationCursor
  provider.value.awareness.setLocalStateField('mousePointer', { x, y })
}

// Clear mouse pointer when leaving editor area
function handleMouseLeave() {
  if (!provider.value?.awareness) return
  provider.value.awareness.setLocalStateField('mousePointer', null)
}

// Setup awareness listener for mouse pointers only
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
    
    remoteMousePointers.value = newStates
  }
  
  awareness.on('change', updatePointers)
  updatePointers()
  
  return () => {
    awareness.off('change', updatePointers)
  }
}

let cleanupMouseListener = null

// Word count
const wordCount = computed(() => {
  if (!editor.value) return 0
  const text = editor.value.getText()
  if (!text || !text.trim()) return 0
  return text.trim().split(/\s+/).filter(word => word.length > 0).length
})

// Character count
const charCount = computed(() => {
  if (!editor.value) return 0
  return editor.value.getText().length
})


// Handle margins changed from ruler
function handleMarginsChanged(margins) {
  pageMargins.value.left = margins.left
  pageMargins.value.right = margins.right
  
  // Update editor content padding to match margins (only on desktop)
  if (!isMobile.value) {
    const proseMirror = pagesContainer.value?.querySelector('.ProseMirror')
    if (proseMirror) {
      proseMirror.style.paddingLeft = `${margins.left}px`
      proseMirror.style.paddingRight = `${margins.right}px`
    }
  }
  
  // Update pagination extension options and recalculate
  if (editor.value) {
    editor.value.commands.setPaginationOptions({
      marginLeft: margins.left,
      marginRight: margins.right,
      marginTop: pageMargins.value.top,
      marginBottom: pageMargins.value.bottom
    })
  }
}

// Update content height - pagination is now handled by the Pagination extension automatically
function updateContentHeight() {
  // Pagination is handled by the extension via ProseMirror decorations
  // No manual recalculation needed
}

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
    case 'connected': return 'text-surface-500'
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

// Initialize
let editorRetryInterval = null

onMounted(async () => {
  const success = await init(props.documentUuid, props.user)
  
  if (success) {
    localTitle.value = documentTitle.value
    
    // Wait for Y.js to sync before creating editor
    // Use a robust approach with multiple fallbacks
    const tryCreateEditor = () => {
      if (isSynced.value && ydoc.value && provider.value && !editor.value) {
        return createEditor()
      }
      return false
    }
    
    // Try immediately
    if (!tryCreateEditor()) {
      // Watch for sync state changes
      const stopWatch = watch(isSynced, (synced) => {
        if (synced && tryCreateEditor()) {
          stopWatch()
          if (editorRetryInterval) {
            clearInterval(editorRetryInterval)
            editorRetryInterval = null
          }
      }
    }, { immediate: true })
      
      // Also add interval-based retry as ultimate fallback
      editorRetryInterval = setInterval(() => {
        if (editor.value) {
          clearInterval(editorRetryInterval)
          editorRetryInterval = null
          return
        }
        
        if (isSynced.value && ydoc.value && provider.value) {
          isDebugEnabled() && console.log('[CollabEditor] Interval retry: Creating editor')
          if (tryCreateEditor()) {
            clearInterval(editorRetryInterval)
            editorRetryInterval = null
          }
        }
      }, 500)
      
      // Clear interval after 10 seconds to prevent infinite retries
      setTimeout(() => {
        if (editorRetryInterval) {
          console.warn('[CollabEditor] Editor creation timed out after 10s', {
            isSynced: isSynced.value,
            hasYdoc: !!ydoc.value,
            hasProvider: !!provider.value,
            hasEditor: !!editor.value
          })
          clearInterval(editorRetryInterval)
          editorRetryInterval = null
        }
      }, 10000)
    }
  }
})

// Create TipTap editor with collaboration
function createEditor() {
  if (!ydoc.value || !provider.value) {
    console.warn('[CollabEditor] Cannot create editor: ydoc or provider not ready')
    return false
  }
  
  // Get fragment directly from ydoc to avoid computed timing issues
  const fragment = ydoc.value.getXmlFragment('content')
  if (!fragment) {
    console.warn('[CollabEditor] Cannot create editor: fragment not ready')
    return false
  }
  
  // Destroy existing editor if any
  if (editorInstance.value) {
    editorInstance.value.destroy()
    editorInstance.value = null
  }
  
  isDebugEnabled() && console.log('[CollabEditor] Creating editor instance')
  
  editorInstance.value = new Editor({
    editable: canEdit.value,
    extensions: [
      StarterKit.configure({
        history: false, // Disable default history, Y.js handles undo/redo
        codeBlock: true, // Ensure code block is enabled
      }),
      Underline,
      Link.configure({
        openOnClick: false,
        HTMLAttributes: {
          target: '_blank',
          rel: 'noopener noreferrer nofollow',
        },
      }),
      Image.configure({
        inline: true,
        allowBase64: true,
      }),
      Placeholder.configure({
        placeholder: 'Start typing...',
      }),
      TextAlign.configure({
        types: ['heading', 'paragraph'],
      }),
      Table.configure({
        resizable: true,
        HTMLAttributes: {
          class: 'table-wrapper',
        },
      }),
      TableRow,
      TableCell,
      TableHeader,
      // Text styling extensions
      TextStyle,
      Color,
      Highlight.configure({
        multicolor: true,
      }),
      Subscript,
      Superscript,
      FontFamily,
      FontSize,
      // Hard page break - user-insertable explicit page breaks (Ctrl+Enter)
      HardPageBreak,
      // Pagination extension - handles page breaks as ProseMirror decorations
      Pagination.configure({
        pageWidth: PAGE_WIDTH,
        pageHeight: PAGE_HEIGHT,
        pageContentHeight: PAGE_CONTENT_HEIGHT,
        marginTop: pageMargins.value.top,
        marginBottom: pageMargins.value.bottom,
        marginLeft: pageMargins.value.left,
        marginRight: pageMargins.value.right,
        showPageNumbers: showPageNumbers.value,
        enabled: !isMobile.value, // Disable on mobile
      }),
      // Y.js collaboration - use fragment directly for better compatibility
      Collaboration.configure({
        fragment: fragment,
      }),
      // Remote cursors - shows other users' cursor positions in real-time
      CollaborationCursor.configure({
        provider: provider.value,
        user: {
          name: props.user.name || props.user.email.split('@')[0],
          email: props.user.email,
          color: getCollabUserColor(props.user.email),
        },
        render: (user) => {
          const cursor = document.createElement('span')
          cursor.classList.add('collaboration-cursor__caret')
          cursor.style.borderColor = user.color
          
          const label = document.createElement('span')
          label.classList.add('collaboration-cursor__label')
          label.style.backgroundColor = user.color
          label.textContent = user.name
          cursor.appendChild(label)
          
          return cursor
        },
        selectionRender: (user) => {
          return {
            style: `background-color: ${user.color}33;`, // 33 = 20% opacity in hex
            class: 'collaboration-cursor__selection',
          }
        },
      }),
    ],
    editorProps: {
      attributes: {
        class: 'prose prose-lg max-w-none focus:outline-none min-h-full',
      },
      handleDOMEvents: {
        // Improve mobile touch handling
        touchstart: (view, event) => {
          // Allow default touch behavior for better mobile experience
          return false
        },
      },
    },
    onUpdate: () => {
      // Pagination is handled automatically by the Pagination extension
    },
    onCreate: ({ editor: createdEditor }) => {
      isDebugEnabled() && console.log('[CollabEditor] Editor created successfully')
      nextTick(() => {
        // Check if document is empty and we have pending initial content
        const content = createdEditor.getText()
        if (!content || content.trim() === '') {
          const initialContent = collabStore.consumePendingInitialContent(props.documentUuid)
          if (initialContent) {
            isDebugEnabled() && console.log('[CollabEditor] Loading initial content from imported file')
            createdEditor.commands.setContent(initialContent)
          }
        }
        
        // Set initial padding from margins (only on desktop)
        const proseMirror = pagesContainer.value?.querySelector('.ProseMirror')
        if (proseMirror && !isMobile.value) {
          proseMirror.style.paddingLeft = `${pageMargins.value.left}px`
          proseMirror.style.paddingRight = `${pageMargins.value.right}px`
          proseMirror.style.paddingTop = `${pageMargins.value.top}px`
          proseMirror.style.paddingBottom = `${pageMargins.value.bottom}px`
        }

        // Re-assert awareness user AFTER all extensions have initialized.
        // TipTap's CollaborationCursor extension may overwrite the 'user' awareness
        // field with only { name, color }, dropping our 'email' field.
        // This ensures the full user object (including email) is broadcast to peers.
        if (provider.value?.awareness) {
          provider.value.awareness.setLocalStateField('user', {
            email: props.user.email,
            name: props.user.name || props.user.email.split('@')[0],
            color: getCollabUserColor(props.user.email),
          })
        }
        
        // Pagination is handled automatically by the Pagination extension
      })
    },
  })
  
  return true
}

// Save title
async function saveTitle() {
  if (localTitle.value !== documentTitle.value) {
    await setTitle(localTitle.value)
  }
}

// Save a version (auto-named with timestamp)
async function saveVersion() {
  showMenu.value = false
  try {
    // Get TipTap JSON snapshot for better comparison
    const tipTapJson = editor.value ? editor.value.getJSON() : null
    
    // Create snapshot - backend will store CRDT state, but we can also store TipTap JSON
    const result = await createSnapshot(null) // Auto-named by backend or uses timestamp
    if (result) {
      toast.success('Version saved')
      // Optionally store TipTap JSON in the version metadata for better comparison
      // This would require backend support to store additional metadata
    } else {
      toast.error('Failed to save version')
    }
  } catch (e) {
    console.error('[CollabEditor] Failed to save version:', e)
    toast.error('Failed to save version')
  }
}

// Handle version restored
function handleVersionRestored(version) {
  isDebugEnabled() && console.log('[CollabEditor] Version restored:', version.version_number)
  // Reload the document to show the restored version
  // The Y.js provider should sync the restored state automatically
  toast.success(`Restored to Version ${version.version_number}`)
  // Force editor to reload if needed
  if (editor.value) {
    // Editor will automatically sync via Y.js
    nextTick(() => {
      // Trigger a refresh if needed
      editor.value?.commands.focus()
    })
  }
}

// Print document - open print preview modal
function printDocument() {
  if (!editor.value) {
    window.print()
    return
  }
  
  // Open print preview modal
  showPrintPreview.value = true
}

// Handle print from preview modal
function handlePrintFromPreview() {
  showPrintPreview.value = false
  toast.success('Document sent to printer')
}

// Direct print (bypasses preview, used as fallback)
async function directPrint() {
  if (!editor.value || !pagesContainer.value) {
    window.print()
    return
  }

  const proseMirror = pagesContainer.value.querySelector('.ProseMirror')
  if (!proseMirror) {
    window.print()
    return
  }

  // Add print class to body for print-specific styles
  document.body.classList.add('print-doc')

  // Add A4 page style with proper margins in mm
  const pageStyle = document.createElement('style')
  pageStyle.setAttribute('data-print-style', 'doc')
  pageStyle.textContent = `
    @page { 
      size: A4 portrait; 
      margin: 25mm 19mm 25mm 19mm;
    }
    
    @media print {
      /* Hide everything except the editor content */
      body.print-doc > *:not(.collab-document-editor) {
        display: none !important;
      }
      
      /* Hide header, toolbar, ruler, panels */
      body.print-doc .document-header,
      body.print-doc .print-hide,
      body.print-doc .editor-ruler-container,
      body.print-doc .collab-comments-panel,
      body.print-doc .version-history-panel,
      body.print-doc .mobile-stats-indicator,
      body.print-doc .remote-cursors-container,
      body.print-doc .table-controller {
        display: none !important;
      }
      
      /* Style the editor area */
      body.print-doc .collab-editor-area {
        overflow: visible !important;
        padding: 0 !important;
        background: white !important;
        height: auto !important;
      }
      
      body.print-doc .tiptap-pages-wrapper {
        padding: 0 !important;
        background: white !important;
      }
      
      body.print-doc .tiptap-pages {
        box-shadow: none !important;
        border: none !important;
        width: 100% !important;
        min-height: auto !important;
      }
      
      body.print-doc .tiptap-pages .ProseMirror {
        padding: 0 !important;
        min-height: auto !important;
      }
      
      /* Orphan/widow control for better text flow */
      body.print-doc .tiptap-pages .ProseMirror p,
      body.print-doc .tiptap-pages .ProseMirror li {
        orphans: 3;
        widows: 3;
      }
      
      /* CRITICAL: Page break styling for print - soft breaks */
      body.print-doc .tiptap-page-break {
        display: block !important;
        width: 100% !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        page-break-after: always !important;
        break-after: page !important;
      }
      
      body.print-doc .tiptap-page-break .tiptap-page-footer,
      body.print-doc .tiptap-page-break .tiptap-pagination-gap,
      body.print-doc .tiptap-page-break .tiptap-page-header {
        visibility: hidden !important;
        height: 0 !important;
        overflow: hidden !important;
      }
      
      /* CRITICAL: Hard page breaks */
      body.print-doc .hard-page-break {
        display: block !important;
        width: 100% !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        page-break-after: always !important;
        break-after: page !important;
        visibility: hidden;
      }
    }
  `
  document.head.appendChild(pageStyle)

  await nextTick()
  await new Promise(resolve => setTimeout(resolve, 100))

  window.print()
  
  // Cleanup after print
  const cleanup = () => {
    document.body.classList.remove('print-doc')
    if (pageStyle.parentNode) {
      pageStyle.parentNode.removeChild(pageStyle)
    }
    window.removeEventListener('afterprint', cleanup)
  }
  window.addEventListener('afterprint', cleanup)
  setTimeout(cleanup, 2000)
}

// Watch for external title changes
watch(documentTitle, (newTitle) => {
  if (newTitle !== localTitle.value) {
    localTitle.value = newTitle
  }
})

// Export as DOCX
async function exportDocx() {
  showMenu.value = false
  await handleSaveDocx()
}

// Handle save/export to DOCX
async function handleSaveDocx() {
  if (!editor.value) return
  
  try {
    const html = editor.value.getHTML()
    const title = localTitle.value || 'Untitled Document'
    // Pass comments to be included in the DOCX
    const comments = commentThreads.value || []
    await exportToDocx(html, title, comments)
  } catch (e) {
    console.error('Failed to export DOCX:', e)
  }
}

// Save document back to the original Drive file
async function saveToDrive() {
  if (!editor.value || !props.driveFileId) return
  
  showMenu.value = false
  isSavingToDrive.value = true
  
  try {
    const html = editor.value.getHTML()
    await collabStore.saveToDrive(props.documentUuid, html, true)
    toast.success('Document saved to Drive')
  } catch (e) {
    console.error('Failed to save to Drive:', e)
    toast.error('Failed to save document to Drive')
  } finally {
    isSavingToDrive.value = false
  }
}

// Close menu on outside click
function handleClickOutside(e) {
  if (showMenu.value && !e.target.closest('.relative')) {
    showMenu.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

// Setup mouse pointer listener when provider is ready
watch(() => provider.value, (newProvider) => {
  if (newProvider?.awareness) {
    cleanupMouseListener = setupMousePointerListener()
  }
}, { immediate: true })

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  // Cleanup mouse pointer listener
  if (cleanupMouseListener) {
    cleanupMouseListener()
    cleanupMouseListener = null
  }
  // Cleanup editor retry interval
  if (editorRetryInterval) {
    clearInterval(editorRetryInterval)
    editorRetryInterval = null
  }
  if (editorInstance.value) {
    editorInstance.value.destroy()
    editorInstance.value = null
  }
  destroy()
})

// Update editor editable state when permissions change
watch(canEdit, (editable) => {
  if (editorInstance.value) {
    editorInstance.value.setEditable(editable)
  }
})

// ============================================================
// COMMENTS
// ============================================================

// Check for text selection
function checkSelection() {
  if (!editor.value) {
    hasSelection.value = false
    return
  }

  const { from, to, empty } = editor.value.state.selection
  
  if (empty || from === to) {
    hasSelection.value = false
    selectedText.value = ''
    return
  }

  // Get selected text
  const text = editor.value.state.doc.textBetween(from, to, ' ')
  if (text.trim().length === 0) {
    hasSelection.value = false
    selectedText.value = ''
    return
  }

  selectedText.value = text.slice(0, 100) + (text.length > 100 ? '...' : '')
  hasSelection.value = true

  // Get vertical position for floating button (on right side of page)
  const coords = editor.value.view.coordsAtPos(to)
  selectionPosition.value = {
    y: coords.top + (coords.bottom - coords.top) / 2 // Center vertically on selection
  }
}

// Open comment panel for current selection
function openCommentForSelection() {
  if (!hasSelection.value) return
  showComments.value = true
}

// Context menu handlers
function handleContextMenu(event) {
  event.preventDefault()
  
  // Position the context menu at mouse position, with bounds checking
  const menuWidth = 220
  const menuHeight = 500
  let x = event.clientX
  let y = event.clientY
  
  // Keep menu within viewport - check right edge
  if (x + menuWidth > window.innerWidth) {
    x = window.innerWidth - menuWidth - 10
  }
  // Keep menu within viewport - check left edge
  if (x < 10) {
    x = 10
  }
  // Keep menu within viewport - check bottom edge
  if (y + menuHeight > window.innerHeight) {
    y = window.innerHeight - menuHeight - 10
  }
  // Keep menu within viewport - check top edge
  if (y < 10) {
    y = 10
  }
  
  contextMenuPosition.value = { x, y }
  showContextMenu.value = true
}

function closeContextMenu() {
  showContextMenu.value = false
}

async function contextAction(action) {
  closeContextMenu()
  
  if (!editor.value) return
  
  switch (action) {
    case 'cut':
      document.execCommand('cut')
      break
    case 'copy':
      document.execCommand('copy')
      break
    case 'paste':
      try {
        const text = await navigator.clipboard.readText()
        editor.value.chain().focus().insertContent(text).run()
      } catch (e) {
        // Fallback if clipboard API fails
        document.execCommand('paste')
      }
      break
    case 'delete':
      editor.value.chain().focus().deleteSelection().run()
      break
    case 'comment':
      openCommentForSelection()
      break
    case 'link':
      // Toggle link - if already a link, remove it
      if (editor.value.isActive('link')) {
        editor.value.chain().focus().unsetLink().run()
      } else if (hasSelection.value) {
        // If text selected, use it as URL (basic approach)
        const selectedUrl = selectedText.value.trim()
        if (selectedUrl.includes('.') || selectedUrl.includes('://')) {
          const url = selectedUrl.startsWith('http') ? selectedUrl : `https://${selectedUrl}`
          editor.value.chain().focus().setLink({ href: url }).run()
        }
      }
      break
    case 'image':
      // Trigger image file picker
      const input = document.createElement('input')
      input.type = 'file'
      input.accept = 'image/*'
      input.onchange = (e) => {
        const file = e.target.files?.[0]
        if (file) {
          const reader = new FileReader()
          reader.onload = (event) => {
            editor.value.chain().focus().setImage({ src: event.target.result }).run()
          }
          reader.readAsDataURL(file)
        }
      }
      input.click()
      break
    case 'table':
      editor.value.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
      break
    case 'divider':
      editor.value.chain().focus().setHorizontalRule().run()
      break
    case 'codeBlock':
      if (editor.value.isActive('codeBlock')) {
        editor.value.chain().focus().toggleCodeBlock().run()
      } else {
        editor.value.chain().focus().toggleCodeBlock().run()
      }
      break
    case 'bold':
      editor.value.chain().focus().toggleBold().run()
      break
    case 'italic':
      editor.value.chain().focus().toggleItalic().run()
      break
    case 'underline':
      editor.value.chain().focus().toggleUnderline().run()
      break
    case 'clearFormat':
      editor.value.chain().focus().clearNodes().unsetAllMarks().run()
      break
  }
}

// Handle adding a new comment thread
function handleAddComment(data) {
  if (!editor.value) return
  
  const { from, to } = editor.value.state.selection
  
  addThread({
    content: data.content,
    quotedText: data.quotedText || selectedText.value,
    selectionFrom: from,
    selectionTo: to
  })
  
  // Clear selection
  hasSelection.value = false
  selectedText.value = ''
}

// Handle adding a reply to a thread
function handleAddReply(data) {
  addReply(data.threadId, data.content)
}

// Handle resolving a thread
function handleResolveThread(threadId) {
  resolveCommentThread(threadId)
}

// Handle unresolving a thread
function handleUnresolveThread(threadId) {
  unresolveCommentThread(threadId)
}

// Handle selecting a thread (scroll to position)
function handleSelectThread(thread) {
  // Could scroll to the position in the document where the comment was made
  // For now, just highlight it
  isDebugEnabled() && console.log('Selected thread:', thread.id)
}

// Initialize comments when synced
watch(isSynced, (synced) => {
  if (synced && ydoc.value) {
    initComments()
  }
}, { immediate: true })

// Clear selection when clicking outside
function handleDocumentClick(e) {
  // Close context menu when clicking outside
  if (showContextMenu.value && !e.target.closest('.context-menu')) {
    closeContextMenu()
  }
  
  // Don't clear if clicking on comment-related elements
  if (e.target.closest('.collab-comments-panel') || 
      e.target.closest('[title="Add comment"]') ||
      e.target.closest('.collab-editor-content') ||
      e.target.closest('.context-menu')) {
    return
  }
  
  // Small delay to allow selection to be processed first
  setTimeout(() => {
    if (!window.getSelection()?.toString()) {
      hasSelection.value = false
    }
  }, 100)
}


let resizeObserver = null
let resizeHandler = null

onMounted(() => {
  document.addEventListener('mousedown', handleDocumentClick)
  
  // Check mobile on mount and resize
  checkMobile()
  window.addEventListener('resize', checkMobile)
  
  // Watch for window resize - pagination extension handles recalculation automatically
  resizeHandler = () => {
    checkMobile()
    // Trigger pagination recalculation via extension
    if (editor.value) {
      editor.value.commands.recalculatePagination()
    }
  }
  
  window.addEventListener('resize', resizeHandler)
  
  // Also observe editor container for size changes
  nextTick(() => {
    if (pagesContainer.value) {
      resizeObserver = new ResizeObserver(() => {
        if (resizeHandler) resizeHandler()
      })
      resizeObserver.observe(pagesContainer.value)
    }
  })
})

onUnmounted(() => {
  document.removeEventListener('mousedown', handleDocumentClick)
  window.removeEventListener('resize', checkMobile)
  
  if (resizeHandler) {
    window.removeEventListener('resize', resizeHandler)
    resizeHandler = null
  }
  
  if (resizeObserver) {
    resizeObserver.disconnect()
    resizeObserver = null
  }
  
})
</script>

<style>
/* Editor area - light gray background like Google Docs */
.collab-editor-area {
  background: #eaeaea;
}

/* These are legacy classes kept for compatibility */
.collab-page-container {
  position: relative;
}

/* Ruler container - fixed below header, not scrolling */
.editor-ruler-container {
  width: 100%;
  background: #eaeaea;
  display: flex;
  justify-content: center;
  padding: 0;
  border-bottom: 1px solid #e5e7eb;
}

.editor-ruler-container .document-ruler {
  width: 794px;
  max-width: 100%;
  background: #fff;
  border-bottom: none;
}

@media (max-width: 768px) {
  .editor-ruler-container {
    display: none !important;
  }
}

/* Page wrapper - centers the A4 page */
.collab-page-wrapper {
  max-width: 850px;
  width: 100%;
  margin: 0 auto;
}

/* Pages container - holds both editor and visual layers */
.collab-pages-container {
  position: relative;
  min-height: 100%;
}

/* ============================================
   PAGINATED EDITOR (Widget-based)
   ============================================ */

/* Pagination styles are imported from pagination.css */

/* A4 page styling - PURE WHITE background */
/* A4 page styling with header/footer padding */
.collab-page {
  position: relative;
  width: 794px;
  max-width: 100%;
  margin: 0 auto;
  padding: 96px 96px; /* 1-inch margins to match Word default */
  background: #ffffff !important;
  border-radius: 2px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.08);
  border: 1px solid #e2e8f0;
  box-sizing: border-box;
}

/* Loading state page */
.collab-page.collab-page-loading {
  min-height: 400px;
  display: flex;
  align-items: center;
  justify-content: center;
}

@media (max-width: 768px) {
  .collab-page {
    width: 100%;
    padding: 24px 16px;
    margin: 0 auto 16px auto;
    border-radius: 0;
    box-shadow: none;
    border-left: none;
    border-right: none;
  }
}

/* Page break indicator - visual line showing page boundary */
.page-break-indicator {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  height: 40px;
  margin-top: -20px;
  pointer-events: none;
  z-index: 20;
}

/* Dashed lines on either side of the page label */
.page-break-line {
  flex: 1;
  height: 0;
  border-top: 2px dashed #cbd5e1;
}

/* Page number label */
.page-break-label {
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: #f1f5f9;
  padding: 4px 16px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  white-space: nowrap;
}

/* Document stats indicator in header */
.document-stats-indicator-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 16px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 20px;
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}

@media (max-width: 768px) {
  .document-stats-indicator-header {
    display: none;
  }
}

/* Mobile stats indicator */
.mobile-stats-indicator {
  display: none;
  position: sticky;
  top: 0;
  z-index: 30;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid #e2e8f0;
  padding: 8px 16px;
  text-align: center;
  font-size: 12px;
  color: #64748b;
}

.mobile-stats-indicator .stats-divider {
  display: inline-block;
  width: 1px;
  height: 12px;
  background: #d1d5db;
  margin: 0 8px;
  vertical-align: middle;
}

@media (max-width: 768px) {
  .mobile-stats-indicator {
    display: block;
  }
  
  /* Mobile header optimizations */
  .document-header {
    position: sticky;
    top: 0;
    z-index: 40;
  }
  
  /* Smaller header buttons on mobile */
  .header-icon-btn {
    width: 32px;
    height: 32px;
  }
  
  .share-btn {
    padding: 6px 12px;
    font-size: 13px;
  }
}

.document-stats-indicator-header .stats-divider {
  width: 1px;
  height: 12px;
  background: #d1d5db;
}

/* Floating comment button - positioned to the right of the A4 page */
.floating-comment-btn {
  position: fixed;
  right: calc(50% - 397px - 60px); /* Right side of A4 page (794/2 = 397px from center) */
  z-index: 50;
  transform: translateY(-50%);
}

.comment-btn-inner {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background: rgb(var(--color-primary-500));
  color: white;
  border: none;
  border-radius: 12px;
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
  font-size: 22px;
}

/* Editor content styles - pagination-aware */
/* Padding and min-height are set by pagination.css for .tiptap-pages .ProseMirror */
.collab-editor-content .ProseMirror {
  outline: none;
  color: #1f2937;
  font-family: 'Outfit', system-ui, sans-serif;
  font-size: 18px;
  line-height: 1.5;
  background: #ffffff !important;
  /* Mobile optimizations */
  -webkit-text-size-adjust: 100%;
  -webkit-tap-highlight-color: transparent;
  touch-action: manipulation;
}

@media (max-width: 768px) {
  .collab-editor-content .ProseMirror {
    /* Mobile-specific optimizations */
    word-wrap: break-word;
    overflow-wrap: break-word;
    -webkit-overflow-scrolling: touch;
  }
  
  /* Improve touch targets on mobile */
  .collab-editor-content .ProseMirror p,
  .collab-editor-content .ProseMirror h1,
  .collab-editor-content .ProseMirror h2,
  .collab-editor-content .ProseMirror h3,
  .collab-editor-content .ProseMirror h4,
  .collab-editor-content .ProseMirror h5,
  .collab-editor-content .ProseMirror h6 {
    min-height: 44px; /* Minimum touch target size */
    padding: 4px 0;
  }
}

.collab-editor-content .ProseMirror p.is-editor-empty:first-child::before {
  color: #a1a1aa;
  content: attr(data-placeholder);
  float: left;
  height: 0;
  pointer-events: none;
}

/* Typography - scaled to match DOCX export */
.collab-editor-content .ProseMirror h1 {
  font-size: 32px;
  font-weight: 600;
  margin: 24px 0 12px;
  color: #18181b;
}

.collab-editor-content .ProseMirror h2 {
  font-size: 26px;
  font-weight: 600;
  margin: 20px 0 10px;
  color: #18181b;
}

.collab-editor-content .ProseMirror h3 {
  font-size: 21px;
  font-weight: 500;
  margin: 16px 0 8px;
  color: #27272a;
}

.collab-editor-content .ProseMirror p {
  margin: 0 0 12px;
}

/* Remote cursor styles */
.collab-editor-content .collaboration-cursor__caret {
  border-left: 2px solid;
  border-right: none;
  margin-left: -1px;
  margin-right: -1px;
  pointer-events: none;
  position: relative;
  word-break: normal;
  animation: cursor-blink 1.2s ease-in-out infinite;
}

@keyframes cursor-blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.6; }
}

.collab-editor-content .collaboration-cursor__label {
  border-radius: 6px 6px 6px 0;
  color: #fff;
  font-size: 11px;
  font-style: normal;
  font-weight: 600;
  left: -2px;
  line-height: normal;
  padding: 3px 10px;
  position: absolute;
  top: -1.8em;
  user-select: none;
  white-space: nowrap;
  font-family: 'Outfit', system-ui, sans-serif;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  z-index: 100;
  opacity: 0;
  transform: translateY(4px);
  animation: cursor-label-appear 0.2s ease-out forwards;
}

@keyframes cursor-label-appear {
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Remote selection highlight - shows what other users have selected */
.collab-editor-content .collaboration-cursor__selection {
  background: inherit;
  border-radius: 2px;
}

/* Ensure cursor is visible above content */
.collab-editor-content .collaboration-cursor__caret {
  z-index: 50;
}

/* Remote mouse cursor styles */
.remote-cursors-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  pointer-events: none;
  z-index: 1000;
  overflow: hidden;
}

.remote-cursor {
  position: absolute;
  pointer-events: none;
  transform: translate(-2px, -2px);
  transition: left 0.05s linear, top 0.05s linear;
  z-index: 1000;
}

.remote-cursor .cursor-pointer-icon {
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.25));
}

.remote-cursor-label {
  position: absolute;
  left: 16px;
  top: 14px;
  padding: 2px 8px;
  border-radius: 6px 6px 6px 0;
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  line-height: normal;
  white-space: nowrap;
  font-family: 'Outfit', system-ui, sans-serif;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  user-select: none;
}

/* Make editor area relative for cursor positioning */
.collab-editor-area {
  position: relative;
}

/* Table styles */
.collab-editor-content .table-wrapper {
  margin: 16px 0;
  overflow-x: auto;
  position: relative;
}

.collab-editor-content table {
  border-collapse: collapse;
  margin: 0;
  overflow: hidden;
  table-layout: fixed;
  width: 100%;
  position: relative;
}

.collab-editor-content td,
.collab-editor-content th {
  border: 1px solid #e4e4e7;
  box-sizing: border-box;
  min-width: 1em;
  padding: 8px 12px;
  position: relative;
  vertical-align: top;
}

.collab-editor-content th {
  background-color: #f4f4f5;
  font-weight: 600;
}

/* Table cell selection */
.collab-editor-content .selectedCell {
  background-color: rgba(59, 130, 246, 0.1) !important;
}

.collab-editor-content .selectedCell::after {
  z-index: 2;
  position: absolute;
  content: "";
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  border: 2px solid #3b82f6;
  pointer-events: none;
}

/* Column resize handle */
.collab-editor-content .column-resize-handle {
  position: absolute;
  right: -2px;
  top: 0;
  bottom: -2px;
  width: 4px;
  background-color: #3b82f6;
  pointer-events: auto;
  cursor: col-resize;
  opacity: 0;
  transition: opacity 0.2s;
  z-index: 5;
}

.collab-editor-content table:hover .column-resize-handle,
.collab-editor-content .column-resize-handle:hover {
  opacity: 1;
}

/* Table hover state */
.collab-editor-content table:hover {
  box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.2);
}

/* Ensure table controller is above table */
.collab-pages-container .table-controller {
  z-index: 15;
}

/* Link styling */
.collab-editor-content a {
  color: rgb(var(--color-primary-500));
  text-decoration: underline;
}

/* Code block */
.collab-editor-content pre {
  background: #f4f4f5;
  border-radius: 8px;
  padding: 16px;
  font-family: 'JetBrains Mono', 'Courier New', Courier, monospace;
  font-size: 14px;
  margin: 16px 0;
  overflow-x: auto;
  position: relative;
  border: 1px solid #e4e4e7;
}

.collab-editor-content pre code {
  background: transparent;
  padding: 0;
  font-size: inherit;
  color: inherit;
  border-radius: 0;
  font-family: inherit;
  display: block;
  white-space: pre;
  overflow-x: auto;
}

.collab-editor-content code {
  background: #f4f4f5;
  padding: 2px 6px;
  border-radius: 4px;
  font-family: 'JetBrains Mono', 'Courier New', Courier, monospace;
  font-size: 0.9em;
  color: #e11d48;
  border: 1px solid #e4e4e7;
}

.collab-editor-content pre code {
  background: transparent;
  padding: 0;
  border: none;
  color: #18181b;
}

/* Blockquote */
.collab-editor-content blockquote {
  border-left: 4px solid #e4e4e7;
  margin: 16px 0;
  padding-left: 16px;
  color: #71717a;
  font-style: italic;
}

/* List styling */
.collab-editor-content ul,
.collab-editor-content ol {
  padding-left: 24px;
  margin: 12px 0;
}

.collab-editor-content li {
  margin-bottom: 4px;
}

/* Horizontal rule */
.collab-editor-content hr {
  border: none;
  border-top: 1px solid #e4e4e7;
  margin: 24px 0;
}

/* Image */
.collab-editor-content img {
  max-width: 100%;
  height: auto;
  border-radius: 8px;
  margin: 16px 0;
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

/* Header icon buttons - unified style */
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

/* Share button - prominent green */
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

/* Header/Footer Settings Modal */
.hf-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}

.hf-modal {
  background: white;
  border-radius: 16px;
  width: 480px;
  max-width: 90vw;
  box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
}

.hf-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid #e5e7eb;
}

.hf-modal-header h3 {
  font-size: 18px;
  font-weight: 600;
  color: #111827;
  margin: 0;
}

.hf-close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  background: transparent;
  border-radius: 8px;
  color: #6b7280;
  cursor: pointer;
}

.hf-close-btn:hover {
  background: #f3f4f6;
}

.hf-modal-body {
  padding: 24px;
}

.hf-field {
  margin-bottom: 20px;
}

.hf-field label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: #374151;
  margin-bottom: 8px;
}

.hf-field input {
  width: 100%;
  padding: 12px 14px;
  font-size: 14px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  outline: none;
  transition: all 0.15s;
}

.hf-field input:focus {
  border-color: rgb(var(--color-primary-500));
  box-shadow: 0 0 0 3px rgba(var(--color-primary-500), 0.15);
}

.hf-hint {
  display: block;
  font-size: 12px;
  color: #9ca3af;
  margin-top: 6px;
}

.hf-checkbox {
  margin-top: 16px;
}

.hf-checkbox span {
  font-size: 14px;
  color: #374151;
}

.toggle-switch {
  width: 44px;
  height: 24px;
  background: #d1d5db;
  border-radius: 12px;
  position: relative;
  cursor: pointer;
  transition: all 0.2s;
}

.toggle-switch.active {
  background: rgb(var(--color-primary-500));
}

.toggle-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  background: white;
  border-radius: 50%;
  transition: all 0.2s;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

.toggle-switch.active .toggle-knob {
  left: 22px;
}

.hf-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid #e5e7eb;
  background: #f9fafb;
  border-radius: 0 0 16px 16px;
}

.hf-btn {
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 600;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
}

.hf-btn.secondary {
  background: white;
  color: #374151;
  border: 1px solid #e5e7eb;
}

.hf-btn.secondary:hover {
  background: #f3f4f6;
}

.hf-btn.primary {
  background: rgb(var(--color-primary-500));
  color: white;
}

.hf-btn.primary:hover {
  background: rgb(var(--color-primary-600));
}

/* Print-only elements - hidden on screen */
.print-header,
.print-footer {
  display: none;
}


/* Context Menu Styles */
.context-menu {
  position: fixed;
  z-index: 99999;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 0 2px 10px rgba(0, 0, 0, 0.08);
  padding: 6px;
  min-width: 220px;
  animation: contextMenuFadeIn 0.12s ease-out;
}

@keyframes contextMenuFadeIn {
  from {
    opacity: 0;
    transform: scale(0.95) translateY(-4px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

.context-menu-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border: none;
  background: transparent;
  color: #374151;
  font-size: 13px;
  text-align: left;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.1s ease;
}

.context-menu-item:hover:not(:disabled) {
  background: #f3f4f6;
}

.context-menu-item:active:not(:disabled) {
  background: #e5e7eb;
}

.context-menu-item:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.context-menu-item .material-symbols-rounded {
  font-size: 20px;
  color: #6b7280;
  flex-shrink: 0;
}

.context-menu-item:hover:not(:disabled) .material-symbols-rounded {
  color: rgb(var(--color-primary-500));
}

.context-menu-item .item-label {
  flex: 1;
}

.context-menu-item .item-shortcut {
  font-size: 11px;
  color: #9ca3af;
  font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
}

.context-menu-divider {
  height: 1px;
  background: #e5e7eb;
  margin: 6px 8px;
}
</style>


