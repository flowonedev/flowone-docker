/**
 * useCollabDocument Composable
 * 
 * High-level composable for collaborative document editing.
 * Combines provider, awareness, and TipTap integration.
 */

import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useCollabProvider } from './useCollabProvider.js'
import { useCollabAwareness } from './useCollabAwareness.js'
import { useCollabStore } from '../stores/collabStore.js'
import * as Y from 'yjs'

/**
 * Setup collaborative document editing
 * 
 * @param {Object} options
 * @param {string} options.documentUuid - Document UUID
 * @param {Object} options.user - Current user { email, name }
 */
export function useCollabDocument(options = {}) {
  const collabStore = useCollabStore()

  // ============================================================
  // PROVIDER & AWARENESS
  // ============================================================

  const {
    ydoc,
    provider,
    awareness,
    isConnected,
    isSynced,
    status,
    error: providerError,
    connect,
    disconnect,
    reconnect,
    setAwarenessUser,
    updateCursor,
    clearCursor,
    getCollabUserColor,
  } = useCollabProvider()

  const {
    users,
    otherUsers,
    currentUser,
    cursors,
    getCursorStyle,
    getUserInitials,
    getCursorLabel,
  } = useCollabAwareness(provider)

  // ============================================================
  // DOCUMENT STATE
  // ============================================================

  const isInitialized = ref(false)
  const isLoading = ref(false)
  const error = ref(null)
  const documentTitle = ref('')

  // Y.js content fragment for TipTap
  const yXmlFragment = computed(() => {
    if (!ydoc.value) return null
    return ydoc.value.getXmlFragment('content')
  })

  // ============================================================
  // INITIALIZATION
  // ============================================================

  /**
   * Initialize the collaborative document
   */
  async function init(documentUuid, user) {
    if (isInitialized.value) {
      console.warn('[CollabDocument] Already initialized')
      return true
    }

    isLoading.value = true
    error.value = null

    try {
      // 1. Fetch document metadata and token
      const docResponse = await collabStore.fetchDocument(documentUuid)
      if (!docResponse.success) {
        throw new Error(docResponse.error || 'Failed to load document')
      }

      documentTitle.value = collabStore.currentDocument?.title || 'Untitled'

      // 2. Get collaboration token
      const token = await collabStore.getCollabToken(documentUuid)
      if (!token) {
        throw new Error('Failed to get collaboration token')
      }

      // 3. Connect to collaboration server
      const connected = await connect(documentUuid, token, user)
      if (!connected) {
        throw new Error(providerError.value || 'Failed to connect')
      }

      isInitialized.value = true
      return true
    } catch (e) {
      console.error('[CollabDocument] Init error:', e)
      error.value = e.message
      return false
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Cleanup and disconnect
   */
  function destroy() {
    disconnect()
    isInitialized.value = false
    documentTitle.value = ''
    error.value = null
  }

  // ============================================================
  // DOCUMENT OPERATIONS
  // ============================================================

  /**
   * Update document title
   */
  async function setTitle(newTitle) {
    if (!collabStore.currentDocument) return false

    try {
      await collabStore.updateDocument(
        collabStore.currentDocument.uuid,
        { title: newTitle }
      )
      documentTitle.value = newTitle
      return true
    } catch (e) {
      error.value = e.message
      return false
    }
  }

  /**
   * Get current content as HTML (for snapshots, export, etc.)
   */
  function getContentAsHtml(editor) {
    if (!editor) return ''
    return editor.getHTML()
  }

  /**
   * Get current content as JSON
   */
  function getContentAsJson(editor) {
    if (!editor) return null
    return editor.getJSON()
  }

  /**
   * Create a named version/snapshot
   */
  async function createSnapshot(name) {
    if (!collabStore.currentDocument) return null

    try {
      const response = await collabStore.createVersion(
        collabStore.currentDocument.uuid,
        name
      )
      return response.success ? response.data.version : null
    } catch (e) {
      error.value = e.message
      return null
    }
  }

  // ============================================================
  // UNDO/REDO with Y.js
  // ============================================================

  // Y.js UndoManager for collaborative undo/redo
  const undoManager = ref(null)
  const canUndo = ref(false)
  const canRedo = ref(false)

  /**
   * Initialize UndoManager for the document
   */
  function initUndoManager() {
    if (!ydoc.value) return
    
    // Destroy existing undo manager
    if (undoManager.value) {
      undoManager.value.destroy()
      undoManager.value = null
    }

    const yContent = ydoc.value.getXmlFragment('content')
    
    // Create new UndoManager - track all origins for local changes
    undoManager.value = new Y.UndoManager(yContent, {
      // Track changes from this client and null origin (TipTap uses null)
      trackedOrigins: new Set([null]),
      captureTimeout: 500, // Group changes within 500ms
    })

    // Listen for stack changes
    const updateState = () => {
      canUndo.value = undoManager.value ? undoManager.value.undoStack.length > 0 : false
      canRedo.value = undoManager.value ? undoManager.value.redoStack.length > 0 : false
    }

    undoManager.value.on('stack-item-added', updateState)
    undoManager.value.on('stack-item-popped', updateState)
    undoManager.value.on('stack-cleared', updateState)
    
    // Initial state
    updateState()
  }

  function undo() {
    if (undoManager.value && canUndo.value) {
      undoManager.value.undo()
    }
  }

  function redo() {
    if (undoManager.value && canRedo.value) {
      undoManager.value.redo()
    }
  }

  // Initialize undo manager when synced
  watch(isSynced, (synced) => {
    if (synced && ydoc.value) {
      // Small delay to ensure Y.js structures are ready
      setTimeout(() => {
        initUndoManager()
      }, 100)
    }
  }, { immediate: true })

  // ============================================================
  // TIPTAP INTEGRATION HELPERS
  // ============================================================

  /**
   * Get TipTap collaboration extension config
   */
  function getCollaborationConfig() {
    return {
      document: ydoc.value,
      field: 'content',
    }
  }

  /**
   * Get TipTap collaboration cursor extension config
   */
  function getCollaborationCursorConfig() {
    const user = options.user || {}
    return {
      provider: provider.value,
      user: {
        name: user.name || user.email?.split('@')[0] || 'Anonymous',
        email: user.email || '',
        color: getCollabUserColor(user.email || 'anonymous'),
      },
    }
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  onUnmounted(() => {
    if (undoManager.value) {
      undoManager.value.destroy()
      undoManager.value = null
    }
    destroy()
  })

  // ============================================================
  // RETURN
  // ============================================================

  return {
    // Y.js
    ydoc,
    yXmlFragment,
    
    // Provider state
    provider,
    awareness,
    isConnected,
    isSynced,
    status,
    
    // Document state
    isInitialized,
    isLoading,
    error: computed(() => error.value || providerError.value),
    documentTitle,
    
    // Users/presence
    users,
    otherUsers,
    currentUser,
    cursors,
    getCursorStyle,
    getUserInitials,
    getCursorLabel,
    
    // Permissions
    canEdit: computed(() => collabStore.canEdit),
    canShare: computed(() => collabStore.canShare),
    isOwner: computed(() => collabStore.isOwner),
    
    // Actions
    init,
    destroy,
    reconnect,
    
    // Document operations
    setTitle,
    getContentAsHtml,
    getContentAsJson,
    createSnapshot,
    
    // Undo/Redo
    undoManager,
    canUndo,
    canRedo,
    undo,
    redo,
    
    // TipTap helpers
    getCollaborationConfig,
    getCollaborationCursorConfig,
    
    // Cursor
    updateCursor,
    clearCursor,

    // Helpers
    getCollabUserColor,
  }
}

