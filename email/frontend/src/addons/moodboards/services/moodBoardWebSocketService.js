import { ref, watchEffect } from 'vue'
import { useMailSyncSocket, EventTypes } from '@/services/mailSyncSocket'
import { useAuthStore } from '@/stores/auth'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { isDebugEnabled } from '@/utils/debug'
import { SENDER_ID } from '@/services/api'

const COLLABORATOR_COLORS = [
  '#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899',
  '#06b6d4', '#84cc16', '#f97316', '#6366f1', '#14b8a6', '#e11d48'
]

function getCollaboratorColor(email) {
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = ((hash << 5) - hash) + email.charCodeAt(i)
  return COLLABORATOR_COLORS[Math.abs(hash) % COLLABORATOR_COLORS.length]
}

export function setupWebSocketService(ctx) {
  const {
    currentBoard, selectedItemIds, isDragging, draggedItemIds,
    _locallyCreatedIds, getPendingAddCount,
    _isLocallyEdited, panX, panY, zoom,
    updateItem, addActivityEntry, reloadBoardData
  } = ctx

  const collaborators = ref([])
  // Plain variable: a ref would trigger reactivity churn at cursor-move frequency
  let cursorThrottleTimer = null
  const wsUnsubscribers = ref([])
  const _commentCallbacks = ref([])
  let _subscribedBoardId = null

  function onCommentEvent(callback) {
    _commentCallbacks.value.push(callback)
    return () => {
      _commentCallbacks.value = _commentCallbacks.value.filter(cb => cb !== callback)
    }
  }

  function subscribeToBoardEvents(boardId) {
    const socket = useMailSyncSocket()
    const authStore = useAuthStore()

    boardId = parseInt(boardId)
    unsubscribeFromBoardEvents(_subscribedBoardId)
    _subscribedBoardId = boardId

    const myEmail = authStore.userEmail?.toLowerCase() || ''

    function sendSubscription() {
      const sent = socket.send({
        type: 'SUBSCRIBE_MOOD_BOARD',
        boardId,
        userName: authStore.displayName || authStore.userEmail?.split('@')[0] || ''
      })
      if (!sent) {
        console.warn('[MoodBoard] WS not connected, subscription queued for reconnect')
      } else {
        isDebugEnabled() && console.log('[MoodBoard] Subscribed to board', boardId)
      }
    }

    sendSubscription()

    const unsubConnected = socket.on(EventTypes.CONNECTED, () => {
      if (currentBoard.value?.id === boardId) {
        isDebugEnabled() && console.log('[MoodBoard] WS reconnected, re-subscribing to board', boardId)
        sendSubscription()
      }
    })
    const unsubReconnected = socket.on(EventTypes.RECONNECTED, () => {
      if (currentBoard.value?.id === boardId) {
        isDebugEnabled() && console.log('[MoodBoard] WS re-established, re-subscribing to board', boardId)
        sendSubscription()
      }
    })

    const _isSelfEvent = (payload) => {
      if (payload.sender_id) return payload.sender_id === SENDER_ID
      return payload.sender_email && payload.sender_email.toLowerCase() === myEmail
    }

    const _seenEventIds = new Set()
    const _MAX_SEEN_IDS = 200
    const _isDuplicateEvent = (payload) => {
      const eid = payload?.event_id
      if (!eid) return false
      if (_seenEventIds.has(eid)) return true
      _seenEventIds.add(eid)
      if (_seenEventIds.size > _MAX_SEEN_IDS) {
        const first = _seenEventIds.values().next().value
        _seenEventIds.delete(first)
      }
      return false
    }

    const _itemExistsById = (id) => {
      const numId = Number(id)
      return currentBoard.value.items.some(i => i.id === id || i.id === numId)
    }

    const unsubItemCreated = socket.on(EventTypes.MOOD_BOARD_ITEM_CREATED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const item = payload.item
      if (!item) return
      if (_locallyCreatedIds.has(item.id) || _locallyCreatedIds.has(Number(item.id))) return
      if (getPendingAddCount() > 0) {
        setTimeout(() => {
          if (currentBoard.value && !_itemExistsById(item.id)) {
            currentBoard.value.items.push(item)
          }
        }, 3000)
        return
      }
      if (!_itemExistsById(item.id)) {
        currentBoard.value.items.push(item)
      }
    })

    const unsubItemsCreated = socket.on('MOOD_BOARD_ITEMS_CREATED', (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const items = payload.items
      if (!Array.isArray(items)) return
      for (const item of items) {
        if (!item) continue
        if (_locallyCreatedIds.has(item.id) || _locallyCreatedIds.has(Number(item.id))) continue
        if (getPendingAddCount() > 0) {
          const capturedItem = { ...item }
          setTimeout(() => {
            if (currentBoard.value && !_itemExistsById(capturedItem.id)) {
              currentBoard.value.items.push(capturedItem)
            }
          }, 3000)
          continue
        }
        if (!_itemExistsById(item.id)) {
          currentBoard.value.items.push(item)
        }
      }
    })

    const unsubItemUpdated = socket.on(EventTypes.MOOD_BOARD_ITEM_UPDATED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const incoming = payload.item
      if (!incoming) return
      if (_isLocallyEdited(incoming.id)) return
      const existing = currentBoard.value.items.find(i => i.id === incoming.id)
      if (existing) {
        const isDragged = isDragging.value && draggedItemIds.value.has(incoming.id)
        for (const key of Object.keys(incoming)) {
          if (isDragged && (key === 'pos_x' || key === 'pos_y')) continue
          existing[key] = incoming[key]
        }
      }
    })

    const unsubItemDeleted = socket.on(EventTypes.MOOD_BOARD_ITEM_DELETED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      currentBoard.value.items = currentBoard.value.items.filter(i => i.id !== payload.item_id)
    })

    const unsubItemsDeleted = socket.on(EventTypes.MOOD_BOARD_ITEMS_DELETED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const ids = new Set((payload.item_ids || []).map(Number))
      currentBoard.value.items = currentBoard.value.items.filter(i => !ids.has(i.id))
      currentBoard.value.connections = (currentBoard.value.connections || []).filter(
        c => !ids.has(c.from_item_id) && !ids.has(c.to_item_id)
      )
    })

    const unsubItemsMoved = socket.on(EventTypes.MOOD_BOARD_ITEMS_MOVED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      for (const update of (payload.updates || [])) {
        if (_isLocallyEdited(update.id)) continue
        const item = currentBoard.value.items.find(i => i.id === update.id)
        if (!item) continue
        const isDragged = isDragging.value && draggedItemIds.value.has(update.id)
        for (const key of Object.keys(update)) {
          if (key === 'id') continue
          if (isDragged && (key === 'pos_x' || key === 'pos_y')) continue
          item[key] = update[key]
        }
      }
    })

    const unsubConnCreated = socket.on(EventTypes.MOOD_BOARD_CONNECTION_CREATED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const conn = payload.connection
      if (conn && !currentBoard.value.connections.find(c => c.id === conn.id)) {
        currentBoard.value.connections.push(conn)
      }
    })

    const unsubConnBatchCreated = socket.on(EventTypes.MOOD_BOARD_CONNECTIONS_BATCH_CREATED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const conns = payload.connections
      if (Array.isArray(conns)) {
        const existingIds = new Set(currentBoard.value.connections.map(c => c.id))
        for (const conn of conns) {
          if (conn && !existingIds.has(conn.id)) {
            currentBoard.value.connections.push(conn)
          }
        }
      }
    })

    const unsubConnDeleted = socket.on(EventTypes.MOOD_BOARD_CONNECTION_DELETED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      currentBoard.value.connections = currentBoard.value.connections.filter(c => c.id !== payload.connection_id)
    })

    const unsubCursor = socket.on(EventTypes.MOOD_BOARD_CURSOR, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      if (payload.user_email?.toLowerCase() === myEmail) return
      const idx = collaborators.value.findIndex(c => c.email === payload.user_email)
      const collab = {
        email: payload.user_email,
        name: payload.user_name || payload.user_email,
        cursor_x: payload.x,
        cursor_y: payload.y,
        view_panX: payload.panX ?? null,
        view_panY: payload.panY ?? null,
        view_zoom: payload.zoom ?? null,
        color: getCollaboratorColor(payload.user_email),
        lastSeen: Date.now()
      }
      if (idx !== -1) {
        collaborators.value[idx] = collab
      } else {
        collaborators.value.push(collab)
      }
    })

    const unsubJoin = socket.on(EventTypes.MOOD_BOARD_PRESENCE_JOIN, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      if (payload.user_email?.toLowerCase() === myEmail) return
      if (!collaborators.value.find(c => c.email === payload.user_email)) {
        collaborators.value.push({
          email: payload.user_email,
          name: payload.user_name || payload.user_email,
          cursor_x: null,
          cursor_y: null,
          color: getCollaboratorColor(payload.user_email),
          lastSeen: Date.now()
        })
      }
    })

    const unsubLeave = socket.on(EventTypes.MOOD_BOARD_PRESENCE_LEAVE, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      collaborators.value = collaborators.value.filter(c => c.email !== payload.user_email)
    })

    const unsubCollaborators = socket.on('MOOD_BOARD_COLLABORATORS', (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      collaborators.value = (payload.collaborators || [])
        .filter(c => c.email?.toLowerCase() !== myEmail)
        .map(c => ({
          email: c.email,
          name: c.name || c.email,
          cursor_x: null,
          cursor_y: null,
          color: getCollaboratorColor(c.email),
          lastSeen: Date.now()
        }))
    })

    async function refreshLinkedCard(cardId) {
      if (!currentBoard.value) return
      const boardsStore = useBoardsStore()
      const fullCard = await boardsStore.getCard(cardId)
      if (!fullCard) return

      const linkedItems = currentBoard.value.items.filter(
        i => i.type === 'board_link' && i.linked_card_id == cardId
      )
      if (!linkedItems.length) return

      const checklistItems = []
      if (fullCard.checklists) {
        for (const cl of fullCard.checklists) {
          for (const item of (cl.items || [])) {
            checklistItems.push({
              text: item.text || item.title,
              completed: item.completed || item.checked || false,
              checklist_name: cl.title || cl.name
            })
          }
        }
      }

      for (const moodItem of linkedItems) {
        let contentObj = {}
        try {
          contentObj = typeof moodItem.content === 'string' ? JSON.parse(moodItem.content) : (moodItem.content || {})
        } catch { contentObj = {} }

        const updatedCardData = {
          ...(contentObj.card_data || {}),
          title: fullCard.title,
          description: fullCard.description,
          due_date: fullCard.due_date,
          completed: fullCard.completed,
          labels: fullCard.labels || [],
          assignees: fullCard.assignees || [],
          checklists: fullCard.checklists || [],
          checklist_items: checklistItems,
          attachment_count: fullCard.attachment_count || 0
        }

        contentObj.card_data = updatedCardData
        moodItem.content = JSON.stringify(contentObj)
        moodItem.title = fullCard.title

        updateItem(moodItem.id, {
          content: moodItem.content,
          title: moodItem.title
        })
      }
    }

    const unsubChecklistUpdated = socket.on(EventTypes.CHECKLIST_UPDATED, (payload) => {
      if (!currentBoard.value || !payload.card_id) return
      const linkedItems = currentBoard.value.items.filter(
        i => i.type === 'board_link' && i.linked_card_id == payload.card_id
      )
      if (!linkedItems.length) return

      if (payload.item_id !== undefined && payload.completed !== undefined) {
        for (const moodItem of linkedItems) {
          try {
            const contentObj = typeof moodItem.content === 'string' ? JSON.parse(moodItem.content) : (moodItem.content || {})
            const items = contentObj.card_data?.checklist_items
            if (items) {
              const target = items.find(t => t.id === payload.item_id)
              if (target) {
                target.completed = !!payload.completed
                moodItem.content = JSON.stringify(contentObj)
              }
            }
          } catch { /* full refresh will fix it */ }
        }
      }

      refreshLinkedCard(payload.card_id)
    })

    const unsubCardUpdated = socket.on(EventTypes.CARD_UPDATED, (payload) => {
      if (!currentBoard.value || !payload.card_id) return
      const hasLinked = currentBoard.value.items.some(
        i => i.type === 'board_link' && i.linked_card_id == payload.card_id
      )
      if (hasLinked) {
        refreshLinkedCard(payload.card_id)
      }
    })

    const unsubActivity = socket.on(EventTypes.MOOD_BOARD_ACTIVITY, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      addActivityEntry({
        id: Date.now(),
        board_id: payload.board_id,
        user_email: payload.user_email,
        user_name: payload.user_name,
        action: payload.action,
        item_id: payload.item_id,
        item_type: payload.item_type,
        item_label: payload.item_label,
        target_item_id: payload.target_item_id,
        target_label: payload.target_label,
        created_at: payload.created_at || new Date().toISOString(),
      })
    })

    const unsubCommentAdded = socket.on(EventTypes.MOOD_BOARD_COMMENT_ADDED, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      for (const cb of _commentCallbacks.value) {
        try { cb('comment_added', payload) } catch (e) { /* non-critical */ }
      }
    })

    const unsubCommentDeleted = socket.on(EventTypes.MOOD_BOARD_COMMENT_DELETED, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      for (const cb of _commentCallbacks.value) {
        try { cb('comment_deleted', payload) } catch (e) { /* non-critical */ }
      }
    })

    const unsubThreadDeleted = socket.on(EventTypes.MOOD_BOARD_THREAD_DELETED, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      for (const cb of _commentCallbacks.value) {
        try { cb('thread_deleted', payload) } catch (e) { /* non-critical */ }
      }
    })

    const unsubThreadResolved = socket.on(EventTypes.MOOD_BOARD_THREAD_RESOLVED, (payload) => {
      if (parseInt(payload.board_id) !== boardId) return
      for (const cb of _commentCallbacks.value) {
        try { cb('thread_resolved', payload) } catch (e) { /* non-critical */ }
      }
    })

    let _deferredRefreshBoardId = null
    const unsubFullRefresh = socket.on('MOOD_BOARD_FULL_REFRESH', (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (isDragging.value) {
        _deferredRefreshBoardId = boardId
        return
      }
      reloadBoardData(boardId)
    })

    const _stopDragWatch = watchEffect(() => {
      if (!isDragging.value && _deferredRefreshBoardId != null) {
        const id = _deferredRefreshBoardId
        _deferredRefreshBoardId = null
        reloadBoardData(id)
      }
    })

    const _boardSkipKeys = new Set(['id', 'items', 'connections', 'zoom_level', 'viewport_x', 'viewport_y'])

    const unsubBoardUpdated = socket.on(EventTypes.MOOD_BOARD_UPDATED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const board = payload.board
      if (!board) return
      for (const key of Object.keys(board)) {
        if (_boardSkipKeys.has(key)) continue
        currentBoard.value[key] = board[key]
      }
    })

    const unsubItemsUpdated = socket.on(EventTypes.MOOD_BOARD_ITEMS_UPDATED, (payload) => {
      if (parseInt(payload.board_id) !== boardId || !currentBoard.value) return
      if (_isSelfEvent(payload)) return
      if (_isDuplicateEvent(payload)) return
      const items = payload.items
      if (!Array.isArray(items)) return
      for (const incoming of items) {
        if (!incoming) continue
        if (_isLocallyEdited(incoming.id)) continue
        const existing = currentBoard.value.items.find(i => i.id === incoming.id)
        if (existing) {
          const isDragged = isDragging.value && draggedItemIds.value.has(incoming.id)
          for (const key of Object.keys(incoming)) {
            if (isDragged && (key === 'pos_x' || key === 'pos_y')) continue
            existing[key] = incoming[key]
          }
        }
      }
    })

    wsUnsubscribers.value = [
      unsubConnected, unsubReconnected,
      unsubItemCreated, unsubItemsCreated, unsubItemUpdated, unsubItemDeleted, unsubItemsDeleted, unsubItemsMoved,
      unsubConnCreated, unsubConnBatchCreated, unsubConnDeleted,
      unsubCursor, unsubJoin, unsubLeave, unsubCollaborators,
      unsubChecklistUpdated, unsubCardUpdated,
      unsubActivity,
      unsubCommentAdded, unsubCommentDeleted, unsubThreadDeleted, unsubThreadResolved,
      unsubFullRefresh, unsubBoardUpdated, unsubItemsUpdated,
      _stopDragWatch
    ]
  }

  function unsubscribeFromBoardEvents(boardIdToLeave) {
    const socket = useMailSyncSocket()
    const leaveId = boardIdToLeave ?? _subscribedBoardId ?? currentBoard.value?.id
    if (leaveId) {
      socket.send({ type: 'UNSUBSCRIBE_MOOD_BOARD', boardId: leaveId })
    }
    _subscribedBoardId = null
    for (const unsub of wsUnsubscribers.value) {
      if (typeof unsub === 'function') unsub()
    }
    wsUnsubscribers.value = []
    collaborators.value = []
    if (cursorThrottleTimer) {
      clearTimeout(cursorThrottleTimer)
      cursorThrottleTimer = null
    }
  }

  function sendCursorPosition(boardId, x, y) {
    if (cursorThrottleTimer) return
    const socket = useMailSyncSocket()
    const authStore = useAuthStore()
    socket.send({
      type: 'MOOD_BOARD_CURSOR_MOVE',
      boardId,
      x: Math.round(x),
      y: Math.round(y),
      panX: Math.round(panX.value),
      panY: Math.round(panY.value),
      zoom: Math.round(zoom.value * 1000) / 1000,
      userName: authStore.displayName || authStore.userEmail?.split('@')[0] || ''
    })
    cursorThrottleTimer = setTimeout(() => {
      cursorThrottleTimer = null
    }, 50)
  }

  return {
    collaborators,
    subscribeToBoardEvents,
    unsubscribeFromBoardEvents,
    sendCursorPosition,
    onCommentEvent
  }
}
