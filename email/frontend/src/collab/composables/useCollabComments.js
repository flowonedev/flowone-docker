/**
 * useCollabComments Composable
 * 
 * Manages collaborative comments using Y.js for real-time sync.
 * Comments are stored in the Y.js document and sync automatically.
 */

import { ref, computed, watch, onUnmounted } from 'vue'
import * as Y from 'yjs'

/**
 * Generate a unique ID
 */
function generateId() {
  return Date.now().toString(36) + Math.random().toString(36).substr(2, 9)
}

/**
 * Setup collaborative comments for a document
 * 
 * @param {Object} options
 * @param {Ref} options.ydoc - Y.js document reference
 * @param {Object} options.user - Current user { email, name }
 */
export function useCollabComments(options = {}) {
  const { ydoc, user } = options

  // ============================================================
  // STATE
  // ============================================================

  const threads = ref([])
  const yComments = ref(null)
  const isInitialized = ref(false)

  // ============================================================
  // INITIALIZATION
  // ============================================================

  /**
   * Initialize comments from Y.js document
   */
  function init() {
    if (!ydoc?.value || isInitialized.value) return

    // Get or create the comments Y.Map
    yComments.value = ydoc.value.getMap('comments')

    // Load existing comments
    loadComments()

    // Listen for changes
    yComments.value.observe(handleCommentsChange)

    isInitialized.value = true
  }

  /**
   * Load comments from Y.js into local state
   */
  function loadComments() {
    if (!yComments.value) return

    const loadedThreads = []
    
    yComments.value.forEach((threadData, threadId) => {
      if (threadData && typeof threadData === 'object') {
        loadedThreads.push({
          id: threadId,
          ...threadData,
          comments: threadData.comments || []
        })
      }
    })

    // Sort by creation time (newest first)
    loadedThreads.sort((a, b) => {
      const aTime = a.comments[0]?.createdAt || 0
      const bTime = b.comments[0]?.createdAt || 0
      return bTime - aTime
    })

    threads.value = loadedThreads
  }

  /**
   * Handle Y.js changes
   */
  function handleCommentsChange(event) {
    loadComments()
  }

  // Watch for ydoc changes to re-initialize
  if (ydoc) {
    watch(ydoc, (newYdoc) => {
      if (newYdoc && !isInitialized.value) {
        init()
      }
    }, { immediate: true })
  }

  // ============================================================
  // COMPUTED
  // ============================================================

  const openThreads = computed(() => {
    return threads.value.filter(t => !t.resolved)
  })

  const resolvedThreads = computed(() => {
    return threads.value.filter(t => t.resolved)
  })

  const totalComments = computed(() => {
    return threads.value.reduce((sum, t) => sum + t.comments.length, 0)
  })

  // ============================================================
  // ACTIONS
  // ============================================================

  /**
   * Add a new comment thread
   */
  function addThread(data) {
    if (!yComments.value || !user?.value) return null

    const threadId = generateId()
    const now = Date.now()

    const thread = {
      id: threadId,
      quotedText: data.quotedText || '',
      selectionFrom: data.selectionFrom || null,
      selectionTo: data.selectionTo || null,
      resolved: false,
      resolvedAt: null,
      resolvedBy: null,
      comments: [{
        id: generateId(),
        content: data.content,
        author: {
          email: user.value.email,
          name: user.value.name || user.value.email.split('@')[0]
        },
        createdAt: now,
        updatedAt: now
      }],
      createdAt: now
    }

    // Store in Y.js (will sync to all clients)
    ydoc.value.transact(() => {
      yComments.value.set(threadId, thread)
    })

    return thread
  }

  /**
   * Add a reply to an existing thread
   */
  function addReply(threadId, content) {
    if (!yComments.value || !user?.value) return null

    const existingThread = yComments.value.get(threadId)
    if (!existingThread) return null

    const now = Date.now()
    const newComment = {
      id: generateId(),
      content,
      author: {
        email: user.value.email,
        name: user.value.name || user.value.email.split('@')[0]
      },
      createdAt: now,
      updatedAt: now
    }

    // Update thread with new comment
    const updatedThread = {
      ...existingThread,
      comments: [...existingThread.comments, newComment]
    }

    ydoc.value.transact(() => {
      yComments.value.set(threadId, updatedThread)
    })

    return newComment
  }

  /**
   * Resolve a thread
   */
  function resolveThread(threadId) {
    if (!yComments.value || !user?.value) return

    const existingThread = yComments.value.get(threadId)
    if (!existingThread) return

    const updatedThread = {
      ...existingThread,
      resolved: true,
      resolvedAt: Date.now(),
      resolvedBy: {
        email: user.value.email,
        name: user.value.name || user.value.email.split('@')[0]
      }
    }

    ydoc.value.transact(() => {
      yComments.value.set(threadId, updatedThread)
    })
  }

  /**
   * Unresolve a thread
   */
  function unresolveThread(threadId) {
    if (!yComments.value) return

    const existingThread = yComments.value.get(threadId)
    if (!existingThread) return

    const updatedThread = {
      ...existingThread,
      resolved: false,
      resolvedAt: null,
      resolvedBy: null
    }

    ydoc.value.transact(() => {
      yComments.value.set(threadId, updatedThread)
    })
  }

  /**
   * Delete a comment
   */
  function deleteComment(threadId, commentId) {
    if (!yComments.value) return

    const existingThread = yComments.value.get(threadId)
    if (!existingThread) return

    const updatedComments = existingThread.comments.filter(c => c.id !== commentId)

    // If no comments left, delete the thread
    if (updatedComments.length === 0) {
      ydoc.value.transact(() => {
        yComments.value.delete(threadId)
      })
    } else {
      const updatedThread = {
        ...existingThread,
        comments: updatedComments
      }
      ydoc.value.transact(() => {
        yComments.value.set(threadId, updatedThread)
      })
    }
  }

  /**
   * Delete an entire thread
   */
  function deleteThread(threadId) {
    if (!yComments.value) return

    ydoc.value.transact(() => {
      yComments.value.delete(threadId)
    })
  }

  /**
   * Update a comment's content
   */
  function updateComment(threadId, commentId, newContent) {
    if (!yComments.value) return

    const existingThread = yComments.value.get(threadId)
    if (!existingThread) return

    const updatedComments = existingThread.comments.map(c => {
      if (c.id === commentId) {
        return {
          ...c,
          content: newContent,
          updatedAt: Date.now()
        }
      }
      return c
    })

    const updatedThread = {
      ...existingThread,
      comments: updatedComments
    }

    ydoc.value.transact(() => {
      yComments.value.set(threadId, updatedThread)
    })
  }

  /**
   * Get thread by ID
   */
  function getThread(threadId) {
    return threads.value.find(t => t.id === threadId)
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  function destroy() {
    if (yComments.value) {
      yComments.value.unobserve(handleCommentsChange)
    }
    threads.value = []
    isInitialized.value = false
  }

  onUnmounted(() => {
    destroy()
  })

  // ============================================================
  // RETURN
  // ============================================================

  return {
    // State
    threads,
    isInitialized,

    // Computed
    openThreads,
    resolvedThreads,
    totalComments,

    // Actions
    init,
    addThread,
    addReply,
    resolveThread,
    unresolveThread,
    deleteComment,
    deleteThread,
    updateComment,
    getThread,
    destroy
  }
}

