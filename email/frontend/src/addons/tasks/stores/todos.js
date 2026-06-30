import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useSearchStore } from '@/addons/universal-search/stores/search'
import { useAddons } from '@/composables/useAddons'
import { withOfflineFallback, getOfflineTodos } from '@/services/offlineData'

// Track events silently (non-blocking)
async function trackEvent(eventType, eventData = {}) {
  try {
    await api.post('/statistics/log-event', { event_type: eventType, event_data: eventData })
  } catch (e) {
    // Silent fail - don't disrupt main functionality
  }
}

export const useTodosStore = defineStore('todos', () => {
  const todos = ref([])
  const loading = ref(false)
  const panelOpen = ref(false)

  // For opening panel with a specific board selected
  const pendingBoardId = ref(null)

  const incompleteTodos = computed(() => todos.value.filter(t => !t.completed))
  const completedTodos = computed(() => todos.value.filter(t => t.completed))
  const incompleteCount = computed(() => incompleteTodos.value.length)

  const loaded = ref(false)

  async function fetchTodos(forceReload = false) {
    const { tasksEnabled } = useAddons()
    if (!tasksEnabled.value) return
    if (loaded.value && !forceReload) return
    loading.value = true
    try {
      const result = await withOfflineFallback(
        async () => {
          // Always include completed tasks so today's progress ring and the
          // Completed section have truthful data.
          const response = await api.get('/todos', {
            params: { include_completed: true }
          })
          if (response.data.success) return response.data.data.todos
          return null
        },
        async () => {
          return await getOfflineTodos()
        }
      )
      if (result) {
        todos.value = result
        loaded.value = true
      }
    } catch (e) {
      console.error('Failed to fetch todos:', e)
    } finally {
      loading.value = false
    }
  }

  function hydrateFromBootstrap(data) {
    if (data.todos) {
      todos.value = data.todos
      loaded.value = true
    }
  }

  async function createTodo(data) {
    try {
      const response = await api.post('/todos', data)
      if (response.data.success) {
        const newTodo = response.data.data.todo
        todos.value.unshift(newTodo)
        // Track task creation
        trackEvent('task_created', { title: data.title })
        // Auto-index for search
        const searchStore = useSearchStore()
        searchStore.indexItem('todo', newTodo.id, newTodo)
        return newTodo
      }
    } catch (e) {
      console.error('Failed to create todo:', e)
    }
    return null
  }

  async function createFromEmail(emailData, selectedText = null) {
    const { tasksEnabled } = useAddons()
    if (!tasksEnabled.value) return null
    try {
      const response = await api.post('/todos/from-email', {
        folder: emailData.folder,
        uid: emailData.uid,
        message_id: emailData.message_id,
        subject: emailData.subject,
        from: emailData.from_email || emailData.from,
        date: emailData.date,
        snippet: emailData.snippet || emailData.body_text?.substring(0, 200),
        selected_text: selectedText
      })
      if (response.data.success) {
        todos.value.unshift(response.data.data.todo)
        return response.data.data.todo
      }
    } catch (e) {
      console.error('Failed to create todo from email:', e)
    }
    return null
  }

  // Helper to find a todo (either root or subtodo) and update it
  function findAndUpdateTodo(id, updatedTodo) {
    // Check root todos
    const index = todos.value.findIndex(t => t.id === id)
    if (index !== -1) {
      // Preserve subtodos if the updated todo doesn't include them
      const existingSubtodos = todos.value[index].subtodos
      todos.value[index] = { ...updatedTodo, subtodos: updatedTodo.subtodos || existingSubtodos || [] }
      return true
    }
    
    // Check subtodos
    for (const todo of todos.value) {
      if (todo.subtodos) {
        const subIndex = todo.subtodos.findIndex(s => s.id === id)
        if (subIndex !== -1) {
          todo.subtodos[subIndex] = updatedTodo
          return true
        }
      }
    }
    return false
  }
  
  // Helper to find and remove a todo (either root or subtodo)
  function findAndRemoveTodo(id) {
    // Check root todos
    const index = todos.value.findIndex(t => t.id === id)
    if (index !== -1) {
      todos.value.splice(index, 1)
      return true
    }
    
    // Check subtodos
    for (const todo of todos.value) {
      if (todo.subtodos) {
        const subIndex = todo.subtodos.findIndex(s => s.id === id)
        if (subIndex !== -1) {
          todo.subtodos.splice(subIndex, 1)
          return true
        }
      }
    }
    return false
  }

  async function updateTodo(id, data) {
    try {
      const response = await api.put(`/todos/${id}`, data)
      if (response.data.success) {
        const updatedTodo = response.data.data.todo
        findAndUpdateTodo(id, updatedTodo)
        // Re-index for search
        const searchStore = useSearchStore()
        searchStore.indexItem('todo', id, updatedTodo)
        return updatedTodo
      }
    } catch (e) {
      console.error('Failed to update todo:', e)
    }
    return null
  }

  async function toggleTodo(id) {
    try {
      const response = await api.post(`/todos/${id}/toggle`)
      if (response.data.success) {
        const updatedTodo = response.data.data.todo
        findAndUpdateTodo(id, updatedTodo)
        // Track task completion if now completed
        if (updatedTodo.completed) {
          trackEvent('task_completed', { title: updatedTodo.title })
        }
        return updatedTodo
      }
    } catch (e) {
      console.error('Failed to toggle todo:', e)
    }
    return null
  }

  async function deleteTodo(id) {
    try {
      const response = await api.delete(`/todos/${id}`)
      if (response.data.success) {
        findAndRemoveTodo(id)
        // Remove from search index
        const searchStore = useSearchStore()
        searchStore.removeFromIndex('todo', id)
        return true
      }
    } catch (e) {
      console.error('Failed to delete todo:', e)
    }
    return false
  }

  // Create a subtodo under a parent todo
  async function createSubtodo(parentId, title) {
    try {
      const response = await api.post('/todos', {
        title,
        parent_id: parentId
      })
      if (response.data.success) {
        // Add subtodo to parent's subtodos array
        const parentTodo = todos.value.find(t => t.id === parentId)
        if (parentTodo) {
          if (!parentTodo.subtodos) {
            parentTodo.subtodos = []
          }
          parentTodo.subtodos.push(response.data.data.todo)
        }
        return response.data.data.todo
      }
    } catch (e) {
      console.error('Failed to create subtodo:', e)
    }
    return null
  }

  /**
   * Delete every completed todo on the server in a single request, then
   * prune them from the local list. The backend also wipes them from the
   * universal search index, so the frontend does NOT loop over ids here.
   *
   * Returns `{ deleted, error? }` so callers can surface the real reason
   * when the server rejects the request (e.g. route missing on a stale
   * deploy, opcache cold, auth lost).
   */
  async function deleteAllCompleted() {
    try {
      const response = await api.delete('/todos/completed')
      if (response.data?.success) {
        todos.value = todos.value.filter(t => !t.completed)
        return { deleted: response.data.data?.deleted ?? 0 }
      }
      const msg = response.data?.error || response.data?.message || 'unknown error'
      console.error('deleteAllCompleted: server returned non-success:', response.data)
      return { deleted: 0, error: msg }
    } catch (e) {
      const status = e?.response?.status
      const body = e?.response?.data
      const msg = body?.error || body?.message || e?.message || 'network error'
      console.error('deleteAllCompleted failed:', { status, body, error: e })
      return { deleted: 0, error: status ? `HTTP ${status}: ${msg}` : msg }
    }
  }

  async function reorderTodos(todoIds) {
    try {
      const response = await api.post('/todos/reorder', { todo_ids: todoIds })
      if (response.data.success) {
        todos.value = response.data.data.todos
        return true
      }
    } catch (e) {
      console.error('Failed to reorder todos:', e)
    }
    return false
  }

  function openPanel() {
    panelOpen.value = true
    fetchTodos()
  }

  function closePanel() {
    panelOpen.value = false
  }

  function togglePanel() {
    if (panelOpen.value) {
      closePanel()
    } else {
      openPanel()
    }
  }

  // Open panel with boards tab and specific board selected
  function openPanelWithBoard(boardId) {
    pendingBoardId.value = boardId
    panelOpen.value = true
  }
  
  function clearPendingBoard() {
    pendingBoardId.value = null
  }

  return {
    todos,
    loading,
    panelOpen,
    pendingBoardId,
    incompleteTodos,
    completedTodos,
    incompleteCount,
    fetchTodos,
    hydrateFromBootstrap,
    createTodo,
    createSubtodo,
    createFromEmail,
    updateTodo,
    toggleTodo,
    deleteTodo,
    deleteAllCompleted,
    reorderTodos,
    openPanel,
    closePanel,
    togglePanel,
    openPanelWithBoard,
    clearPendingBoard
  }
})


