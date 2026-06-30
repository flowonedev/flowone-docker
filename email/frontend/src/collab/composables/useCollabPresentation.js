/**
 * useCollabPresentation Composable
 * 
 * High-level composable for collaborative presentation editing.
 * Manages slides, objects, and multi-user editing of presentations.
 */

import { ref, computed, watch, onUnmounted } from 'vue'
import { useCollabProvider } from './useCollabProvider.js'
import { useCollabAwareness } from './useCollabAwareness.js'
import { isDebugEnabled } from '@/utils/debug'
import { useCollabStore } from '../stores/collabStore.js'
import * as Y from 'yjs'
import { v4 as uuidv4 } from 'uuid'
import {
  DEFAULT_SLIDE_WIDTH,
  DEFAULT_SLIDE_HEIGHT,
  DEFAULT_ASPECT_RATIO
} from '../services/presentation/slideDimensions.js'

/**
 * Setup collaborative presentation editing
 * 
 * @param {Object} options
 * @param {string} options.documentUuid - Document UUID
 * @param {Object} options.user - Current user { email, name }
 */
export function useCollabPresentation(options = {}) {
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
  // PRESENTATION STATE
  // ============================================================

  const isInitialized = ref(false)
  const isLoading = ref(false)
  const error = ref(null)

  // ============================================================
  // REMOTE CURSOR TRACKING
  // ============================================================

  // Reactive ref to force updates when awareness changes
  const awarenessVersion = ref(0)
  let awarenessCleanup = null

  // Setup awareness change listener
  function setupAwarenessListener() {
    if (!provider.value?.awareness) return
    
    const awareness = provider.value.awareness
    
    const onAwarenessChange = () => {
      // Increment version to trigger reactivity
      awarenessVersion.value++
    }
    
    awareness.on('change', onAwarenessChange)
    
    // Store cleanup function
    awarenessCleanup = () => {
      awareness.off('change', onAwarenessChange)
    }
  }

  // Remote cursors with full user info and position
  // Format matches what CollabCursors.vue expects: { clientId, user: { name, email, color }, x, y, ... }
  const remoteCursors = computed(() => {
    // Access awarenessVersion to make this reactive to awareness changes
    const _ = awarenessVersion.value
    
    if (!provider.value?.awareness) return []
    
    const states = provider.value.awareness.getStates()
    const clientId = provider.value.awareness.clientID
    const result = []
    
    states.forEach((state, id) => {
      if (id === clientId) return // Skip self
      if (!state.user) return
      
      const mousePointer = state.mousePointer || state.cursor
      if (mousePointer && mousePointer.x !== undefined && mousePointer.y !== undefined) {
        result.push({
          clientId: id,
          user: {
            name: state.user.name || state.user.email?.split('@')[0] || 'Anonymous',
            email: state.user.email || '',
            color: state.user.color || getCollabUserColor(state.user.email || 'anon'),
          },
          slideIndex: mousePointer.slideIndex ?? 0,
          x: mousePointer.x,
          y: mousePointer.y,
          selectedObjectId: mousePointer.selectedObjectId || null,
        })
      }
    })
    
    return result
  })

  // Track which slide each user is currently viewing
  const userSlidePositions = computed(() => {
    // Access awarenessVersion to make this reactive to awareness changes
    const _ = awarenessVersion.value
    
    if (!provider.value?.awareness) return {}
    
    const states = provider.value.awareness.getStates()
    const clientId = provider.value.awareness.clientID
    const positions = {}
    
    states.forEach((state, id) => {
      if (id === clientId) return // Skip self
      if (!state.user) return
      
      const slideIndex = state.mousePointer?.slideIndex ?? state.cursor?.slideIndex ?? 0
      if (!positions[slideIndex]) {
        positions[slideIndex] = []
      }
      positions[slideIndex].push({
        clientId: id,
        userName: state.user.name || state.user.email?.split('@')[0] || 'Anonymous',
        color: state.user.color || getCollabUserColor(state.user.email || 'anon'),
      })
    })
    
    return positions
  })

  // Presentation metadata
  const meta = ref({
    title: 'Untitled Presentation',
    theme: 'light',
    aspectRatio: DEFAULT_ASPECT_RATIO,
    slideWidth: DEFAULT_SLIDE_WIDTH,
    slideHeight: DEFAULT_SLIDE_HEIGHT,
  })

  // Current slide index
  const currentSlideIndex = ref(0)

  // Selected object IDs (can be multiple for multi-select)
  const selectedObjectIds = ref([])

  // Reactive slides array (synced from Y.js)
  const slides = ref([])

  // Comments threads (synced from Y.js)
  const commentThreads = ref([])

  // Current slide
  const currentSlide = computed(() => {
    return slides.value[currentSlideIndex.value] || null
  })

  // Current slide objects
  const currentSlideObjects = computed(() => {
    return currentSlide.value?.objects || []
  })

  // ============================================================
  // Y.JS BINDINGS
  // ============================================================

  // Y.js structures
  let yMeta = null
  let ySlides = null
  let yComments = null
  
  // Undo/Redo support
  let undoManager = null
  const canUndo = ref(false)
  const canRedo = ref(false)

  /**
   * Bind to Y.js document structures
   */
  function bindYjsStructures() {
    if (!ydoc.value) return

    // Get or create meta map
    yMeta = ydoc.value.getMap('meta')
    
    // Get or create slides array
    ySlides = ydoc.value.getArray('slides')

    // Initialize meta if empty
    if (yMeta.size === 0) {
      ydoc.value.transact(() => {
        yMeta.set('title', meta.value.title)
        yMeta.set('theme', meta.value.theme)
        yMeta.set('aspectRatio', meta.value.aspectRatio)
        yMeta.set('slideWidth', meta.value.slideWidth)
        yMeta.set('slideHeight', meta.value.slideHeight)
      })
    } else {
      // Sync from Y.js
      syncMetaFromYjs()
    }

    // Initialize with one slide if empty
    if (ySlides.length === 0) {
      addSlide()
    } else {
      // Sync slides from Y.js
      syncSlidesFromYjs()
    }

    // Get or create comments array
    yComments = ydoc.value.getArray('comments')
    syncCommentsFromYjs()

    // Observe changes
    yMeta.observe(onMetaChange)
    ySlides.observeDeep(onSlidesChange)
    yComments.observeDeep(onCommentsChange)
    
    // Initialize undo manager
    initUndoManager()
  }
  
  /**
   * Initialize UndoManager for undo/redo support
   */
  let globalUndoManager = null
  const canGlobalUndo = ref(false)
  const canGlobalRedo = ref(false)
  
  function initUndoManager() {
    if (!ydoc.value || !ySlides) return
    
    // Destroy existing undo managers
    if (undoManager) {
      undoManager.destroy()
      undoManager = null
    }
    if (globalUndoManager) {
      globalUndoManager.destroy()
      globalUndoManager = null
    }
    
    // Create LOCAL UndoManager - tracks only local user's changes
    undoManager = new Y.UndoManager([ySlides, yMeta], {
      trackedOrigins: new Set([null, ydoc.value.clientID]),
      captureTimeout: 500, // Group changes within 500ms
    })
    
    // Create GLOBAL UndoManager - tracks ALL changes from all users
    // By NOT specifying trackedOrigins, it tracks all origins
    globalUndoManager = new Y.UndoManager([ySlides, yMeta], {
      captureTimeout: 500,
    })
    
    // Update state when stack changes for local undo
    const updateUndoState = () => {
      canUndo.value = undoManager ? undoManager.undoStack.length > 0 : false
      canRedo.value = undoManager ? undoManager.redoStack.length > 0 : false
    }
    
    // Update state when stack changes for global undo
    const updateGlobalUndoState = () => {
      canGlobalUndo.value = globalUndoManager ? globalUndoManager.undoStack.length > 0 : false
      canGlobalRedo.value = globalUndoManager ? globalUndoManager.redoStack.length > 0 : false
    }
    
    undoManager.on('stack-item-added', updateUndoState)
    undoManager.on('stack-item-popped', updateUndoState)
    undoManager.on('stack-cleared', updateUndoState)
    
    globalUndoManager.on('stack-item-added', updateGlobalUndoState)
    globalUndoManager.on('stack-item-popped', updateGlobalUndoState)
    globalUndoManager.on('stack-cleared', updateGlobalUndoState)
    
    updateUndoState()
    updateGlobalUndoState()
  }
  
  /**
   * Undo last LOCAL change (Ctrl+Z)
   */
  function undo() {
    if (undoManager && canUndo.value) {
      undoManager.undo()
    }
  }
  
  /**
   * Redo last undone LOCAL change (Ctrl+Y)
   */
  function redo() {
    if (undoManager && canRedo.value) {
      undoManager.redo()
    }
  }
  
  /**
   * Global Undo - undoes last change from ANY user (Ctrl+Shift+Z)
   * This allows reverting changes made by collaborators
   */
  function globalUndo() {
    if (globalUndoManager && canGlobalUndo.value) {
      globalUndoManager.undo()
    }
  }
  
  /**
   * Global Redo - redoes last globally undone change
   */
  function globalRedo() {
    if (globalUndoManager && canGlobalRedo.value) {
      globalUndoManager.redo()
    }
  }

  /**
   * Unbind from Y.js structures
   */
  function unbindYjsStructures() {
    // Destroy undo managers
    if (undoManager) {
      undoManager.destroy()
      undoManager = null
      canUndo.value = false
      canRedo.value = false
    }
    if (globalUndoManager) {
      globalUndoManager.destroy()
      globalUndoManager = null
      canGlobalUndo.value = false
      canGlobalRedo.value = false
    }
    
    if (yMeta) {
      yMeta.unobserve(onMetaChange)
      yMeta = null
    }
    if (ySlides) {
      ySlides.unobserveDeep(onSlidesChange)
      ySlides = null
    }
    if (yComments) {
      yComments.unobserveDeep(onCommentsChange)
      yComments = null
    }
  }

  /**
   * Sync meta from Y.js to local state
   */
  function syncMetaFromYjs() {
    if (!yMeta) return
    meta.value = {
      title: yMeta.get('title') || 'Untitled Presentation',
      theme: yMeta.get('theme') || 'light',
      aspectRatio: yMeta.get('aspectRatio') || DEFAULT_ASPECT_RATIO,
      slideWidth: yMeta.get('slideWidth') || DEFAULT_SLIDE_WIDTH,
      slideHeight: yMeta.get('slideHeight') || DEFAULT_SLIDE_HEIGHT,
    }
  }

  function applyImportedPresentationMeta(importMeta) {
    if (!yMeta || !importMeta) return

    ydoc.value.transact(() => {
      if (importMeta.aspectRatio) {
        yMeta.set('aspectRatio', importMeta.aspectRatio)
      }
      if (importMeta.slideWidth) {
        yMeta.set('slideWidth', importMeta.slideWidth)
      }
      if (importMeta.slideHeight) {
        yMeta.set('slideHeight', importMeta.slideHeight)
      }
    })

    syncMetaFromYjs()
  }

  /**
   * Sync slides from Y.js to local state
   */
  function syncSlidesFromYjs() {
    if (!ySlides) return
    try {
      const arr = ySlides.toArray()
      slides.value = arr.map(ySlide => ySlideToObject(ySlide)).filter(Boolean)
    } catch (e) {
      console.warn('[CollabPresentation] Error syncing slides from Y.js:', e)
    }
  }

  /**
   * Convert Y.Map slide to plain object
   */
  function ySlideToObject(ySlide) {
    if (!ySlide) return { id: uuidv4(), background: { type: 'solid', value: '#ffffff' }, objects: [] }
    
    const objects = ySlide.get('objects')
    return {
      id: ySlide.get('id') || uuidv4(),
      background: ySlide.get('background') || { type: 'solid', value: '#ffffff' },
      objects: objects ? objects.toArray().map(yObj => yObjectToPlain(yObj)).filter(Boolean) : [],
    }
  }

  /**
   * Convert Y.Map object to plain object
   */
  function yObjectToPlain(yObj) {
    if (!yObj) return null
    
    const obj = {}
    try {
      yObj.forEach((value, key) => {
        obj[key] = value
      })
    } catch (e) {
      console.warn('[CollabPresentation] Error converting Y.Map to plain object:', e)
      return null
    }
    return obj
  }

  /**
   * Handle meta changes from Y.js
   */
  function onMetaChange(event) {
    syncMetaFromYjs()
  }

  /**
   * Handle slides changes from Y.js
   */
  function onSlidesChange(events) {
    syncSlidesFromYjs()
  }

  /**
   * Sync comments from Y.js to local state
   */
  function syncCommentsFromYjs() {
    if (!yComments) return
    try {
      const arr = yComments.toArray()
      commentThreads.value = arr.map(yThread => yThreadToObject(yThread)).filter(Boolean)
    } catch (e) {
      console.warn('[CollabPresentation] Error syncing comments from Y.js:', e)
    }
  }

  /**
   * Convert Y.Map comment thread to plain object
   */
  function yThreadToObject(yThread) {
    if (!yThread) return null
    
    try {
      const replies = yThread.get('replies')
      return {
        id: yThread.get('id'),
        slideIndex: yThread.get('slideIndex'),
        objectId: yThread.get('objectId') || null,
        x: yThread.get('x') || 0,
        y: yThread.get('y') || 0,
        content: yThread.get('content'),
        author: yThread.get('author'),
        createdAt: yThread.get('createdAt'),
        resolved: yThread.get('resolved') || false,
        resolvedAt: yThread.get('resolvedAt') || null,
        resolvedBy: yThread.get('resolvedBy') || null,
        replies: replies ? replies.toArray().map(yReply => yReplyToObject(yReply)).filter(Boolean) : [],
      }
    } catch (e) {
      console.warn('[CollabPresentation] Error converting comment thread:', e)
      return null
    }
  }

  /**
   * Convert Y.Map reply to plain object
   */
  function yReplyToObject(yReply) {
    if (!yReply) return null
    
    try {
      return {
        id: yReply.get('id'),
        content: yReply.get('content'),
        author: yReply.get('author'),
        createdAt: yReply.get('createdAt'),
      }
    } catch (e) {
      console.warn('[CollabPresentation] Error converting reply:', e)
      return null
    }
  }

  /**
   * Handle comments changes from Y.js
   */
  function onCommentsChange(events) {
    syncCommentsFromYjs()
  }

  // ============================================================
  // INITIALIZATION
  // ============================================================

  /**
   * Initialize the collaborative presentation
   */
  async function init(documentUuid, user) {
    if (isInitialized.value) {
      console.warn('[CollabPresentation] Already initialized')
      return true
    }

    isLoading.value = true
    error.value = null
    currentDocumentUuid = documentUuid // Store for initial slides lookup

    try {
      // 1. Fetch document metadata and token
      const docResponse = await collabStore.fetchDocument(documentUuid)
      if (!docResponse.success) {
        throw new Error(docResponse.error || 'Failed to load document')
      }

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

      // 4. Bind to Y.js structures when synced
      isInitialized.value = true
      return true
    } catch (e) {
      console.error('[CollabPresentation] Init error:', e)
      error.value = e.message
      return false
    } finally {
      isLoading.value = false
    }
  }

  // Bind Y.js when synced
  watch(isSynced, (synced) => {
    if (synced && ydoc.value) {
      bindYjsStructures()
      
      // Setup awareness listener for cursor tracking
      setupAwarenessListener()

      const pendingMeta = collabStore.consumePendingPresentationMeta(currentDocumentUuid)
      if (pendingMeta) {
        applyImportedPresentationMeta(pendingMeta)
      }
      
      // Check for pending initial slides from PPTX import
      const pending = collabStore.consumePendingInitialSlides(currentDocumentUuid)
      if (pending && pending.slides && pending.slides.length > 0 && ySlides) {
        const shouldImport = pending.forceReimport || ySlides.length <= 1 || Array.from({ length: ySlides.length }, (_, i) => {
          const s = ySlides.get(i)
          const objs = s?.get?.('objects')
          return !objs || objs.length === 0
        }).every(Boolean)

        if (shouldImport) {
          importSlidesFromPptx(pending.slides)
        }
      }
    }
  })

  // Store current document UUID for initial slides lookup
  let currentDocumentUuid = null

  /**
   * Cleanup and disconnect
   */
  function destroy() {
    // Cleanup awareness listener
    if (awarenessCleanup) {
      awarenessCleanup()
      awarenessCleanup = null
    }
    
    unbindYjsStructures()
    disconnect()
    isInitialized.value = false
    currentSlideIndex.value = 0
    selectedObjectIds.value = []
    slides.value = []
    error.value = null
  }

  // ============================================================
  // SLIDE OPERATIONS
  // ============================================================

  /**
   * Add a new slide
   */
  function addSlide(index = null) {
    if (!ySlides || !ydoc.value) return null

    const slideId = uuidv4()
    const newSlide = new Y.Map()
    
    ydoc.value.transact(() => {
      newSlide.set('id', slideId)
      newSlide.set('background', { type: 'solid', value: '#ffffff' })
      newSlide.set('objects', new Y.Array())

      if (index === null || index >= ySlides.length) {
        ySlides.push([newSlide])
      } else {
        ySlides.insert(index, [newSlide])
      }
    })

    return slideId
  }

  /**
   * Delete a slide
   */
  function deleteSlide(index) {
    if (!ySlides || ySlides.length <= 1) return false // Keep at least one slide

    ydoc.value.transact(() => {
      ySlides.delete(index, 1)
    })

    // Adjust current index if needed
    if (currentSlideIndex.value >= slides.value.length) {
      currentSlideIndex.value = Math.max(0, slides.value.length - 1)
    }

    return true
  }

  /**
   * Duplicate a slide
   */
  function duplicateSlide(index) {
    if (!ySlides || !ydoc.value) return null

    const sourceSlide = ySlides.get(index)
    if (!sourceSlide) return null

    const slideId = uuidv4()
    const newSlide = new Y.Map()
    
    ydoc.value.transact(() => {
      newSlide.set('id', slideId)
      newSlide.set('background', sourceSlide.get('background') || { type: 'solid', value: '#ffffff' })
      
      // Copy objects
      const newObjects = new Y.Array()
      const sourceObjects = sourceSlide.get('objects')
      if (sourceObjects && sourceObjects.length > 0) {
        const len = sourceObjects.length
        for (let i = 0; i < len; i++) {
          const yObj = sourceObjects.get(i)
          if (yObj) {
            const newObj = new Y.Map()
            yObj.forEach((value, key) => {
              newObj.set(key, key === 'id' ? uuidv4() : value)
            })
            newObjects.push([newObj])
          }
        }
      }
      newSlide.set('objects', newObjects)

      ySlides.insert(index + 1, [newSlide])
    })

    return slideId
  }

  /**
   * Reorder slides
   */
  function moveSlide(fromIndex, toIndex) {
    if (!ySlides || fromIndex === toIndex) return false
    if (fromIndex < 0 || fromIndex >= ySlides.length) return false
    if (toIndex < 0 || toIndex >= ySlides.length) return false

    ydoc.value.transact(() => {
      const slide = ySlides.get(fromIndex)
      ySlides.delete(fromIndex, 1)
      ySlides.insert(toIndex, [slide])
    })

    // Update current index if the current slide moved
    if (currentSlideIndex.value === fromIndex) {
      currentSlideIndex.value = toIndex
    } else if (fromIndex < currentSlideIndex.value && toIndex >= currentSlideIndex.value) {
      currentSlideIndex.value--
    } else if (fromIndex > currentSlideIndex.value && toIndex <= currentSlideIndex.value) {
      currentSlideIndex.value++
    }

    return true
  }

  /**
   * Set slide background
   */
  function setSlideBackground(index, background) {
    if (!ySlides) return false
    
    const ySlide = ySlides.get(index)
    if (!ySlide) return false

    ydoc.value.transact(() => {
      ySlide.set('background', background)
    })

    return true
  }

  // ============================================================
  // OBJECT OPERATIONS
  // ============================================================

  /**
   * Add an object to the current slide
   */
  function addObject(objectData) {
    if (!ySlides || !ydoc.value) return null

    const ySlide = ySlides.get(currentSlideIndex.value)
    if (!ySlide) return null

    const objectId = uuidv4()
    let yObjects = ySlide.get('objects')

    ydoc.value.transact(() => {
      // Ensure objects array exists
      if (!yObjects) {
        yObjects = new Y.Array()
        ySlide.set('objects', yObjects)
      }
      
      const newObj = new Y.Map()
      newObj.set('id', objectId)
      newObj.set('type', objectData.type)
      newObj.set('x', objectData.x || 100)
      newObj.set('y', objectData.y || 100)
      newObj.set('width', objectData.width || 200)
      newObj.set('height', objectData.height || 100)
      newObj.set('rotation', objectData.rotation || 0)
      newObj.set('zIndex', yObjects.length || 0)

      // Type-specific properties
      if (objectData.type === 'text') {
        newObj.set('content', objectData.content || '<p>Text</p>')
        newObj.set('fontSize', objectData.fontSize || 24)
        newObj.set('fontFamily', objectData.fontFamily || 'Inter')
        newObj.set('fontWeight', objectData.fontWeight || 'normal')
        newObj.set('fontStyle', objectData.fontStyle || 'normal')
        newObj.set('textAlign', objectData.textAlign || 'left')
        newObj.set('color', objectData.color || '#000000')
        newObj.set('backgroundColor', objectData.backgroundColor || 'transparent')
        // Additional text properties
        if (objectData.textDecoration) newObj.set('textDecoration', objectData.textDecoration)
        if (objectData.textTransform) newObj.set('textTransform', objectData.textTransform)
        if (objectData.letterSpacing !== undefined) newObj.set('letterSpacing', objectData.letterSpacing)
        if (objectData.listType) newObj.set('listType', objectData.listType)
        if (objectData.scaleContent !== undefined) newObj.set('scaleContent', objectData.scaleContent)
      } else if (objectData.type === 'shape') {
        newObj.set('shapeType', objectData.shapeType || 'rectangle')
        newObj.set('fill', objectData.fill || '#2196F3')
        newObj.set('stroke', objectData.stroke || '#1976D2')
        newObj.set('strokeWidth', objectData.strokeWidth || 2)
      } else if (objectData.type === 'image') {
        newObj.set('imageUrl', objectData.imageUrl || objectData.src || '')
        newObj.set('objectFit', objectData.objectFit || 'contain')
        if (objectData.borderRadius !== undefined) newObj.set('borderRadius', objectData.borderRadius)
      }

      yObjects.push([newObj])
    })

    return objectId
  }

  /**
   * Update an object property
   */
  function updateObject(slideIndex, objectId, updates) {
    if (!ySlides || !ydoc.value) return false

    const ySlide = ySlides.get(slideIndex)
    if (!ySlide) return false

    const yObjects = ySlide.get('objects')
    if (!yObjects) return false
    
    let targetObj = null

    // Find the object
    const len = yObjects.length || 0
    for (let i = 0; i < len; i++) {
      const yObj = yObjects.get(i)
      if (yObj && yObj.get('id') === objectId) {
        targetObj = yObj
        break
      }
    }

    if (!targetObj) return false

    ydoc.value.transact(() => {
      for (const [key, value] of Object.entries(updates)) {
        targetObj.set(key, value)
      }
    })

    return true
  }

  /**
   * Delete an object
   */
  function deleteObject(slideIndex, objectId) {
    if (!ySlides || !ydoc.value) return false

    const ySlide = ySlides.get(slideIndex)
    if (!ySlide) return false

    const yObjects = ySlide.get('objects')
    if (!yObjects) return false
    
    let objectIndex = -1
    const len = yObjects.length || 0

    for (let i = 0; i < len; i++) {
      const yObj = yObjects.get(i)
      if (yObj && yObj.get('id') === objectId) {
        objectIndex = i
        break
      }
    }

    if (objectIndex === -1) return false

    ydoc.value.transact(() => {
      yObjects.delete(objectIndex, 1)
    })

    // Remove from selection
    selectedObjectIds.value = selectedObjectIds.value.filter(id => id !== objectId)

    return true
  }

  /**
   * Delete selected objects
   */
  function deleteSelectedObjects() {
    for (const objectId of [...selectedObjectIds.value]) {
      deleteObject(currentSlideIndex.value, objectId)
    }
    selectedObjectIds.value = []
  }

  /**
   * Bring object to front
   */
  function bringToFront(slideIndex, objectId) {
    const objects = slides.value[slideIndex]?.objects || []
    const maxZ = Math.max(...objects.map(o => o.zIndex || 0))
    updateObject(slideIndex, objectId, { zIndex: maxZ + 1 })
  }

  /**
   * Send object to back
   */
  function sendToBack(slideIndex, objectId) {
    const objects = slides.value[slideIndex]?.objects || []
    const minZ = Math.min(...objects.map(o => o.zIndex || 0))
    updateObject(slideIndex, objectId, { zIndex: minZ - 1 })
  }

  /**
   * Duplicate an object on the same slide
   */
  function duplicateObject(slideIndex, objectId) {
    if (!ySlides || !ydoc.value) return null

    const ySlide = ySlides.get(slideIndex)
    if (!ySlide) return null

    const yObjects = ySlide.get('objects')
    if (!yObjects) return null

    // Find the source object
    let sourceObj = null
    const len = yObjects.length || 0
    for (let i = 0; i < len; i++) {
      const yObj = yObjects.get(i)
      if (yObj && yObj.get('id') === objectId) {
        sourceObj = yObj
        break
      }
    }

    if (!sourceObj) return null

    const newObjectId = uuidv4()

    ydoc.value.transact(() => {
      const newObj = new Y.Map()
      
      // Copy all properties from source
      sourceObj.forEach((value, key) => {
        if (key === 'id') {
          newObj.set('id', newObjectId)
        } else if (key === 'x') {
          // Offset the duplicate
          newObj.set('x', (value || 0) + 20)
        } else if (key === 'y') {
          newObj.set('y', (value || 0) + 20)
        } else {
          newObj.set(key, value)
        }
      })

      yObjects.push([newObj])
    })

    // Select the new object
    selectedObjectIds.value = [newObjectId]

    return newObjectId
  }

  // ============================================================
  // SELECTION
  // ============================================================

  function selectObject(objectId, addToSelection = false) {
    if (addToSelection) {
      if (!selectedObjectIds.value.includes(objectId)) {
        selectedObjectIds.value.push(objectId)
      }
    } else {
      selectedObjectIds.value = [objectId]
    }

    // Update awareness with selection
    updateCursor({
      slideIndex: currentSlideIndex.value,
      selectedObjectId: objectId,
    })
  }

  function deselectObject(objectId) {
    selectedObjectIds.value = selectedObjectIds.value.filter(id => id !== objectId)
    
    updateCursor({
      slideIndex: currentSlideIndex.value,
      selectedObjectId: selectedObjectIds.value[0] || null,
    })
  }

  function clearSelection() {
    selectedObjectIds.value = []
    updateCursor({
      slideIndex: currentSlideIndex.value,
      selectedObjectId: null,
    })
  }

  // ============================================================
  // NAVIGATION
  // ============================================================

  function goToSlide(index) {
    if (index >= 0 && index < slides.value.length) {
      currentSlideIndex.value = index
      clearSelection()
      
      updateCursor({
        slideIndex: index,
        selectedObjectId: null,
      })
    }
  }

  function nextSlide() {
    goToSlide(currentSlideIndex.value + 1)
  }

  function previousSlide() {
    goToSlide(currentSlideIndex.value - 1)
  }

  // ============================================================
  // META OPERATIONS
  // ============================================================

  function setTitle(title) {
    if (!yMeta || !ydoc.value) return false
    ydoc.value.transact(() => {
      yMeta.set('title', title)
    })
    return true
  }

  function setTheme(theme) {
    if (!yMeta || !ydoc.value) return false
    ydoc.value.transact(() => {
      yMeta.set('theme', theme)
    })
    return true
  }

  // ============================================================
  // COMMENTS
  // ============================================================

  /**
   * Add a new comment thread
   */
  function addComment(commentData) {
    if (!yComments || !ydoc.value || !options.user) return null

    const threadId = uuidv4()
    const now = new Date().toISOString()
    
    ydoc.value.transact(() => {
      const newThread = new Y.Map()
      newThread.set('id', threadId)
      newThread.set('slideIndex', commentData.slideIndex)
      newThread.set('objectId', commentData.objectId || null)
      newThread.set('x', commentData.x || 0)
      newThread.set('y', commentData.y || 0)
      newThread.set('content', commentData.content)
      newThread.set('author', {
        email: options.user.email,
        name: options.user.name || options.user.email?.split('@')[0],
        color: getCollabUserColor(options.user.email),
      })
      newThread.set('createdAt', now)
      newThread.set('resolved', false)
      newThread.set('replies', new Y.Array())
      
      yComments.push([newThread])
    })

    return threadId
  }

  /**
   * Reply to a comment thread
   */
  function replyToComment(threadId, content) {
    if (!yComments || !ydoc.value || !options.user) return false

    const now = new Date().toISOString()
    
    // Find the thread
    for (let i = 0; i < yComments.length; i++) {
      const yThread = yComments.get(i)
      if (yThread.get('id') === threadId) {
        ydoc.value.transact(() => {
          const replies = yThread.get('replies')
          const newReply = new Y.Map()
          newReply.set('id', uuidv4())
          newReply.set('content', content)
          newReply.set('author', {
            email: options.user.email,
            name: options.user.name || options.user.email?.split('@')[0],
            color: getCollabUserColor(options.user.email),
          })
          newReply.set('createdAt', now)
          
          replies.push([newReply])
        })
        return true
      }
    }

    return false
  }

  /**
   * Resolve a comment thread
   */
  function resolveComment(threadId) {
    if (!yComments || !ydoc.value || !options.user) return false

    for (let i = 0; i < yComments.length; i++) {
      const yThread = yComments.get(i)
      if (yThread.get('id') === threadId) {
        ydoc.value.transact(() => {
          yThread.set('resolved', true)
          yThread.set('resolvedAt', new Date().toISOString())
          yThread.set('resolvedBy', {
            email: options.user.email,
            name: options.user.name || options.user.email?.split('@')[0],
          })
        })
        return true
      }
    }

    return false
  }

  /**
   * Unresolve (reopen) a comment thread
   */
  function unresolveComment(threadId) {
    if (!yComments || !ydoc.value) return false

    for (let i = 0; i < yComments.length; i++) {
      const yThread = yComments.get(i)
      if (yThread.get('id') === threadId) {
        ydoc.value.transact(() => {
          yThread.set('resolved', false)
          yThread.set('resolvedAt', null)
          yThread.set('resolvedBy', null)
        })
        return true
      }
    }

    return false
  }

  /**
   * Delete a comment thread
   */
  function deleteComment(threadId) {
    if (!yComments || !ydoc.value) return false

    for (let i = 0; i < yComments.length; i++) {
      const yThread = yComments.get(i)
      if (yThread.get('id') === threadId) {
        ydoc.value.transact(() => {
          yComments.delete(i, 1)
        })
        return true
      }
    }

    return false
  }

  // ============================================================
  // MOUSE POINTER TRACKING
  // ============================================================

  /**
   * Update local mouse pointer position in awareness
   */
  function updateMousePointer(pointerData) {
    if (!provider.value?.awareness) return
    
    provider.value.awareness.setLocalStateField('mousePointer', {
      slideIndex: pointerData.slideIndex,
      x: pointerData.x,
      y: pointerData.y,
    })
  }

  /**
   * Clear mouse pointer from awareness (when leaving canvas)
   */
  function clearMousePointer() {
    if (!provider.value?.awareness) return
    provider.value.awareness.setLocalStateField('mousePointer', null)
  }

  // ============================================================
  // PPTX IMPORT
  // ============================================================

  /**
   * Import slides from PPTX conversion data
   * Used when creating a presentation from a PPTX file
   */
  function importSlidesFromPptx(slidesData) {
    if (!ySlides || !ydoc.value || !slidesData || slidesData.length === 0) {
      console.warn('[CollabPresentation] Cannot import slides - invalid state')
      return false
    }

    isDebugEnabled() && console.log('[CollabPresentation] Importing', slidesData.length, 'slides from PPTX')

    try {
      ydoc.value.transact(() => {
        // Clear existing slides
        while (ySlides.length > 0) {
          ySlides.delete(0, 1)
        }

        // Import each slide
        for (const slideData of slidesData) {
          const newSlide = new Y.Map()
          newSlide.set('id', slideData.id || uuidv4())
          newSlide.set('background', slideData.background || { type: 'solid', value: '#ffffff' })

          // Create objects array
          const yObjects = new Y.Array()
          
          if (slideData.objects && Array.isArray(slideData.objects) && slideData.objects.length > 0) {
            for (const objData of slideData.objects) {
              if (objData && typeof objData === 'object') {
                const yObj = new Y.Map()
                
                // Set all object properties
                Object.entries(objData).forEach(([key, value]) => {
                  if (value !== undefined) {
                    yObj.set(key, value)
                  }
                })
                
                yObjects.push([yObj])
              }
            }
          }
          
          newSlide.set('objects', yObjects)
          ySlides.push([newSlide])
        }
      })

      // Sync to local state
      syncSlidesFromYjs()
      
      isDebugEnabled() && console.log('[CollabPresentation] PPTX import complete')
      return true
    } catch (e) {
      console.error('[CollabPresentation] Error importing PPTX slides:', e)
      return false
    }
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  onUnmounted(() => {
    destroy()
  })

  // ============================================================
  // RETURN
  // ============================================================

  return {
    // State
    isInitialized,
    isLoading,
    error: computed(() => error.value || providerError.value),
    isConnected,
    isSynced,
    status,

    // Presentation data
    meta,
    slides,
    currentSlideIndex,
    currentSlide,
    currentSlideObjects,
    selectedObjectIds,
    commentThreads,

    // Users/presence
    users,
    otherUsers,
    currentUser,
    cursors,
    remoteCursors,
    userSlidePositions,
    getCursorStyle,
    getUserInitials,
    getCursorLabel,

    // Provider (for direct awareness access)
    provider,

    // Permissions
    canEdit: computed(() => collabStore.canEdit),
    canShare: computed(() => collabStore.canShare),
    isOwner: computed(() => collabStore.isOwner),

    // Actions
    init,
    destroy,
    reconnect,
    
    // Undo/Redo (local - your changes only)
    undo,
    redo,
    canUndo,
    canRedo,
    
    // Global Undo/Redo (everyone's changes - Ctrl+Shift+Z)
    globalUndo,
    globalRedo,
    canGlobalUndo,
    canGlobalRedo,

    // Slide operations
    addSlide,
    deleteSlide,
    duplicateSlide,
    moveSlide,
    setSlideBackground,

    // Object operations
    addObject,
    updateObject,
    deleteObject,
    deleteSelectedObjects,
    bringToFront,
    sendToBack,
    duplicateObject,

    // Selection
    selectObject,
    deselectObject,
    clearSelection,

    // Navigation
    goToSlide,
    nextSlide,
    previousSlide,

    // Meta
    setTitle,
    setTheme,

    // Cursor
    updateCursor,
    clearCursor,

    // Mouse pointer
    updateMousePointer,
    clearMousePointer,
    getCollabUserColor,

    // PPTX import
    importSlidesFromPptx,

    // Comments
    addComment,
    replyToComment,
    resolveComment,
    unresolveComment,
    deleteComment,
  }
}

