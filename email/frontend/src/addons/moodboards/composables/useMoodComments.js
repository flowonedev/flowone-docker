import { ref, computed } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

/**
 * Composable for mood board comment state & API calls.
 * Works for both authenticated and public (shared) contexts.
 *
 * @param {Object} options
 * @param {import('vue').Ref<number|null>} options.boardId - Board ID (for authenticated calls)
 * @param {import('vue').Ref<string|null>} options.shareToken - Public share token (for public calls)
 * @param {import('vue').Ref<boolean>}     options.isPublic - True when operating via share link
 */
export function useMoodComments({ boardId, shareToken, isPublic } = {}) {
  const threads = ref([])
  const itemCounts = ref({})
  const loading = ref(false)
  const allowComments = ref(true)
  const showCommentsPanel = ref(false)
  const activeThreadId = ref(null)
  const commentingOnItem = ref(null)

  const openThreads = computed(() => threads.value.filter(t => !t.resolved))
  const resolvedThreads = computed(() => threads.value.filter(t => t.resolved))

  const threadsByItem = computed(() => {
    const map = {}
    for (const thread of threads.value) {
      const key = thread.item_id ?? '_board'
      if (!map[key]) map[key] = []
      map[key].push(thread)
    }
    return map
  })

  function apiBase() {
    if (isPublic?.value && shareToken?.value) {
      return `/mood-boards/share/${shareToken.value}`
    }
    return `/mood-boards/${boardId?.value}`
  }

  async function fetchComments() {
    loading.value = true
    try {
      const res = await api.get(`${apiBase()}/comments`)
      if (res.data?.success) {
        threads.value = res.data.data.threads || []
        itemCounts.value = res.data.data.item_counts || {}
        if (res.data.data.allow_comments !== undefined) {
          allowComments.value = res.data.data.allow_comments
        }
      }
    } catch (e) {
      isDebugEnabled() && console.error('[MoodComments] fetchComments failed:', e)
    } finally {
      loading.value = false
    }
  }

  async function addComment(data) {
    try {
      const res = await api.post(`${apiBase()}/comments`, data)
      if (res.data?.success && res.data.data?.comment) {
        const comment = res.data.data.comment
        const tid = comment.thread_id
        const existing = threads.value.find(t => t.thread_id === tid)
        if (existing) {
          existing.comments.push(comment)
        } else {
          threads.value.push({
            thread_id: tid,
            item_id: comment.item_id,
            pin_x: comment.pin_x,
            pin_y: comment.pin_y,
            resolved: false,
            resolved_at: null,
            resolved_by: null,
            comments: [comment],
          })
        }
        updateItemCounts(comment.item_id, 1)
        return comment
      }
      console.error('[MoodComments] addComment: unexpected response', res.data)
      return null
    } catch (e) {
      const serverMsg = e.response?.data?.message || e.message
      console.error('[MoodComments] addComment failed:', serverMsg, e.response?.data || e)
      return null
    }
  }

  async function updateCommentContent(commentId, content) {
    try {
      const res = await api.put(`${apiBase()}/comments/${commentId}`, { content })
      if (res.data?.success && res.data.data?.comment) {
        const updated = res.data.data.comment
        for (const thread of threads.value) {
          const idx = thread.comments.findIndex(c => c.id === commentId)
          if (idx !== -1) {
            thread.comments[idx] = { ...thread.comments[idx], ...updated }
            break
          }
        }
        return updated
      }
      return null
    } catch (e) {
      console.error('[MoodComments] updateComment failed:', e)
      return null
    }
  }

  async function deleteComment(commentId) {
    try {
      const res = await api.delete(`${apiBase()}/comments/${commentId}`)
      if (res.data?.success) {
        for (const thread of threads.value) {
          const idx = thread.comments.findIndex(c => c.id === commentId)
          if (idx !== -1) {
            const removed = thread.comments.splice(idx, 1)[0]
            if (thread.comments.length === 0) {
              threads.value = threads.value.filter(t => t.thread_id !== thread.thread_id)
              updateItemCounts(removed?.item_id, -1)
            }
            break
          }
        }
        return true
      }
      return false
    } catch (e) {
      console.error('[MoodComments] deleteComment failed:', e)
      return false
    }
  }

  async function deleteThread(threadId) {
    try {
      const res = await api.delete(`${apiBase()}/comments/threads/${threadId}`)
      if (res.data?.success) {
        const thread = threads.value.find(t => t.thread_id === threadId)
        if (thread) {
          const itemId = thread.comments[0]?.item_id
          threads.value = threads.value.filter(t => t.thread_id !== threadId)
          if (itemId) updateItemCounts(itemId, -1)
        }
        return true
      }
      return false
    } catch (e) {
      console.error('[MoodComments] deleteThread failed:', e)
      return false
    }
  }

  async function resolveThread(threadId) {
    try {
      const res = await api.post(`${apiBase()}/comments/threads/${threadId}/resolve`)
      if (res.data?.success) {
        const thread = threads.value.find(t => t.thread_id === threadId)
        if (thread) {
          thread.resolved = true
          thread.resolved_at = new Date().toISOString()
        }
        return true
      }
      return false
    } catch (e) {
      console.error('[MoodComments] resolveThread failed:', e)
      return false
    }
  }

  async function unresolveThread(threadId) {
    try {
      const res = await api.post(`${apiBase()}/comments/threads/${threadId}/unresolve`)
      if (res.data?.success) {
        const thread = threads.value.find(t => t.thread_id === threadId)
        if (thread) {
          thread.resolved = false
          thread.resolved_at = null
          thread.resolved_by = null
        }
        return true
      }
      return false
    } catch (e) {
      console.error('[MoodComments] unresolveThread failed:', e)
      return false
    }
  }

  function updateItemCounts(itemId, delta) {
    if (!itemId) return
    const current = itemCounts.value[itemId] || { threads: 0, comments: 0 }
    current.comments += delta
    if (delta > 0 && current.threads === 0) current.threads = 1
    itemCounts.value = { ...itemCounts.value, [itemId]: current }
  }

  /**
   * Receive a real-time comment from WebSocket and merge it into local state.
   */
  function handleRealtimeComment(comment) {
    if (!comment?.thread_id) return
    const tid = comment.thread_id
    const existing = threads.value.find(t => t.thread_id === tid)
    if (existing) {
      if (!existing.comments.find(c => c.id === comment.id)) {
        existing.comments.push(comment)
      }
    } else {
      threads.value.push({
        thread_id: tid,
        item_id: comment.item_id,
        pin_x: comment.pin_x,
        pin_y: comment.pin_y,
        resolved: false,
        resolved_at: null,
        resolved_by: null,
        comments: [comment],
      })
    }
    updateItemCounts(comment.item_id, 1)
  }

  /**
   * Handle real-time thread deletion.
   */
  function handleRealtimeThreadDelete(threadId) {
    const thread = threads.value.find(t => t.thread_id === threadId)
    if (thread) {
      const itemId = thread.comments[0]?.item_id
      threads.value = threads.value.filter(t => t.thread_id !== threadId)
      if (itemId) updateItemCounts(itemId, -1)
    }
  }

  /**
   * Handle real-time thread resolution.
   */
  function handleRealtimeResolve({ thread_id, resolved }) {
    const thread = threads.value.find(t => t.thread_id === thread_id)
    if (thread) {
      thread.resolved = resolved
      if (!resolved) {
        thread.resolved_at = null
        thread.resolved_by = null
      }
    }
  }

  const commentingAtCanvas = ref(null) // { canvasX, canvasY, screenX, screenY }

  function startCommentOnItem(itemId) {
    commentingOnItem.value = itemId
    commentingAtCanvas.value = null
  }

  function startCommentOnCanvas(pos) {
    commentingAtCanvas.value = pos
    commentingOnItem.value = null
  }

  function cancelCommentOnItem() {
    commentingOnItem.value = null
    commentingAtCanvas.value = null
  }

  function $reset() {
    threads.value = []
    itemCounts.value = {}
    loading.value = false
    allowComments.value = true
    showCommentsPanel.value = false
    activeThreadId.value = null
    commentingOnItem.value = null
    commentingAtCanvas.value = null
  }

  return {
    threads,
    itemCounts,
    loading,
    allowComments,
    showCommentsPanel,
    activeThreadId,
    commentingOnItem,
    commentingAtCanvas,
    openThreads,
    resolvedThreads,
    threadsByItem,
    fetchComments,
    addComment,
    updateCommentContent,
    deleteComment,
    deleteThread,
    resolveThread,
    unresolveThread,
    handleRealtimeComment,
    handleRealtimeThreadDelete,
    handleRealtimeResolve,
    startCommentOnItem,
    startCommentOnCanvas,
    cancelCommentOnItem,
    $reset,
  }
}
