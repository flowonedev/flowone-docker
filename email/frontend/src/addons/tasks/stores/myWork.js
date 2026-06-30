import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useAddons } from '@/composables/useAddons'

export const useMyWorkStore = defineStore('myWork', () => {
  const items = ref([])
  const loading = ref(false)
  const error = ref(null)
  const kanbanEnabled = ref(false)
  const dbStats = ref({ completed_total: 0, completed_this_week: 0 })

  const groupBy = ref(localStorage.getItem('mywork_group_by') || 'date')
  const viewMode = ref(localStorage.getItem('mywork_view_mode') || 'table')
  const filterPriority = ref(localStorage.getItem('mywork_filter_priority') || 'all')
  const filterSource = ref(localStorage.getItem('mywork_filter_source') || 'all')
  const includeCompleted = ref(localStorage.getItem('mywork_include_completed') === 'true')
  const searchQuery = ref('')

  function setGroupBy(value) {
    groupBy.value = value
    localStorage.setItem('mywork_group_by', value)
  }

  function setViewMode(value) {
    viewMode.value = value
    localStorage.setItem('mywork_view_mode', value)
  }

  function setFilterPriority(value) {
    filterPriority.value = value
    localStorage.setItem('mywork_filter_priority', value)
  }

  function setFilterSource(value) {
    filterSource.value = value
    localStorage.setItem('mywork_filter_source', value)
  }

  function setIncludeCompleted(value) {
    includeCompleted.value = value
    localStorage.setItem('mywork_include_completed', value ? 'true' : 'false')
  }

  // --- Pending save tracking ---
  const _pendingSaves = new Set()

  function trackSave(promise) {
    _pendingSaves.add(promise)
    promise.finally(() => _pendingSaves.delete(promise))
    return promise
  }

  // --- Optimistic local mutations ---

  function findItem(id) {
    return items.value.find(i => i.id === id)
  }

  function updateItemLocally(id, changes) {
    const idx = items.value.findIndex(i => i.id === id)
    if (idx === -1) return null
    const prev = items.value[idx]
    items.value[idx] = { ...prev, ...changes }
    if ('completed' in changes && changes.completed !== prev.completed) {
      const delta = changes.completed ? 1 : -1
      dbStats.value = {
        ...dbStats.value,
        completed_total: Math.max(0, (dbStats.value.completed_total || 0) + delta),
        completed_this_week: Math.max(0, (dbStats.value.completed_this_week || 0) + delta)
      }
    }
    return items.value[idx]
  }

  function removeItemLocally(id) {
    items.value = items.value.filter(i => i.id !== id)
  }

  function addSubtodoLocally(itemId, subtodo) {
    const item = findItem(itemId)
    if (!item) return
    const subtodos = [...(item.subtodos || []), subtodo]
    updateItemLocally(itemId, { subtodos })
  }

  function removeSubtodoLocally(itemId, subtodoId) {
    const item = findItem(itemId)
    if (!item) return
    const subtodos = (item.subtodos || []).filter(s => s.id !== subtodoId)
    updateItemLocally(itemId, { subtodos })
  }

  function toggleSubtodoLocally(itemId, subtodoId) {
    const item = findItem(itemId)
    if (!item) return
    const subtodos = (item.subtodos || []).map(s =>
      s.id === subtodoId ? { ...s, completed: !s.completed } : s
    )
    updateItemLocally(itemId, { subtodos })
  }

  function toggleChecklistItemLocally(itemId, checklistItemId) {
    const item = findItem(itemId)
    if (!item) return
    const checklists = (item.checklists || []).map(cl => ({
      ...cl,
      items: (cl.items || []).map(ci =>
        ci.id === checklistItemId ? { ...ci, completed: !ci.completed } : ci
      )
    }))
    updateItemLocally(itemId, { checklists })
  }

  function removeChecklistItemLocally(itemId, checklistItemId) {
    const item = findItem(itemId)
    if (!item) return
    const checklists = (item.checklists || []).map(cl => ({
      ...cl,
      items: (cl.items || []).filter(ci => ci.id !== checklistItemId)
    }))
    updateItemLocally(itemId, { checklists })
  }

  function addChecklistItemLocally(itemId, checklistId, newItem) {
    const item = findItem(itemId)
    if (!item) return
    const checklists = (item.checklists || []).map(cl => {
      if (cl.id === checklistId) {
        return { ...cl, items: [...(cl.items || []), newItem] }
      }
      return cl
    })
    updateItemLocally(itemId, { checklists })
  }

  async function backgroundRefresh() {
    if (_pendingSaves.size > 0) {
      await Promise.all([..._pendingSaves])
    }
    const { tasksEnabled, fetchAddons } = useAddons()
    if (!tasksEnabled.value) {
      await fetchAddons()
      if (!tasksEnabled.value) return
    }
    try {
      const response = await api.get('/my-work', {
        params: { include_completed: includeCompleted.value }
      })
      if (response.data.success) {
        const { todos, assigned_cards, kanban_enabled, stats } = response.data.data
        items.value = normalizeItems(todos || [], assigned_cards || [])
        kanbanEnabled.value = !!kanban_enabled
        if (stats) dbStats.value = stats
      }
    } catch (e) {
      console.error('Background refresh failed:', e)
    }
  }

  function normalizeItems(todos, assignedCards) {
    const normalized = []

    for (const todo of todos) {
      normalized.push({
        id: `todo_${todo.id}`,
        rawId: todo.id,
        type: 'todo',
        title: todo.title,
        completed: !!todo.completed,
        completedAt: todo.completed_at,
        dueDate: todo.due_date,
        priority: todo.priority || 'normal',
        boardName: null,
        boardId: null,
        boardDriveFolderId: null,
        listName: null,
        labels: [],
        subtodos: todo.subtodos || [],
        checklists: [],
        refSubject: todo.ref_subject,
        refFrom: todo.ref_from,
        refFolder: todo.ref_folder,
        refUid: todo.ref_uid,
        refSelectedText: todo.ref_selected_text,
        createdAt: todo.created_at,
        updatedAt: todo.updated_at,
        _raw: todo
      })
    }

    for (const card of assignedCards) {
      normalized.push({
        id: `card_${card.id}`,
        rawId: card.id,
        type: 'card',
        title: card.title,
        completed: !!card.completed,
        completedAt: card.completed_at,
        dueDate: card.due_date,
        priority: card.priority || 'normal',
        boardName: card.board_name,
        boardId: card.board_id,
        boardDriveFolderId: card.board_drive_folder_id || null,
        listName: card.list_name,
        labels: card.labels || [],
        subtodos: [],
        checklists: card.checklists || [],
        refSubject: null,
        refFrom: null,
        refFolder: null,
        refUid: null,
        refSelectedText: null,
        description: card.description,
        assignedTo: card.assigned_to,
        startDate: card.start_date,
        coverColor: card.cover_color || card.card_color,
        createdAt: card.created_at,
        updatedAt: card.updated_at,
        _raw: card
      })
    }

    return normalized
  }

  async function fetchMyWork() {
    const { tasksEnabled, fetchAddons } = useAddons()
    if (!tasksEnabled.value) {
      await fetchAddons()
      if (!tasksEnabled.value) return
    }

    loading.value = true
    error.value = null
    try {
      const response = await api.get('/my-work', {
        params: { include_completed: includeCompleted.value }
      })
      if (response.data.success) {
        const { todos, assigned_cards, kanban_enabled, stats } = response.data.data
        items.value = normalizeItems(todos || [], assigned_cards || [])
        kanbanEnabled.value = !!kanban_enabled
        if (stats) dbStats.value = stats
      }
    } catch (e) {
      console.error('Failed to fetch my work:', e)
      error.value = 'Failed to load tasks'
    } finally {
      loading.value = false
    }
  }

  const filteredItems = computed(() => {
    let result = items.value

    if (!includeCompleted.value) {
      result = result.filter(i => !i.completed)
    }

    if (filterPriority.value !== 'all') {
      result = result.filter(i => i.priority === filterPriority.value)
    }

    if (filterSource.value === 'todos') {
      result = result.filter(i => i.type === 'todo')
    } else if (filterSource.value === 'cards') {
      result = result.filter(i => i.type === 'card')
    }

    if (searchQuery.value.trim()) {
      const q = searchQuery.value.toLowerCase()
      result = result.filter(i =>
        i.title.toLowerCase().includes(q) ||
        (i.boardName && i.boardName.toLowerCase().includes(q)) ||
        (i.listName && i.listName.toLowerCase().includes(q))
      )
    }

    return result
  })

  function getDateCategory(dateStr) {
    if (!dateStr) return 'no_date'
    const now = new Date()
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const due = new Date(dateStr)
    const dueDay = new Date(due.getFullYear(), due.getMonth(), due.getDate())
    const diffMs = dueDay - today
    const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24))

    if (diffDays < 0) return 'overdue'
    if (diffDays === 0) return 'today'
    if (diffDays === 1) return 'tomorrow'
    if (diffDays <= 7) return 'this_week'
    if (diffDays <= 30) return 'this_month'
    return 'later'
  }

  const dateGroupOrder = ['overdue', 'today', 'tomorrow', 'this_week', 'this_month', 'later', 'no_date']
  const dateGroupLabels = {
    overdue: 'Overdue',
    today: 'Today',
    tomorrow: 'Tomorrow',
    this_week: 'This Week',
    this_month: 'This Month',
    later: 'Later',
    no_date: 'No Due Date'
  }
  const dateGroupIcons = {
    overdue: 'warning',
    today: 'today',
    tomorrow: 'event',
    this_week: 'date_range',
    this_month: 'calendar_month',
    later: 'schedule',
    no_date: 'event_busy'
  }
  const dateGroupColors = {
    overdue: 'text-red-500',
    today: 'text-amber-500',
    tomorrow: 'text-amber-400',
    this_week: 'text-blue-500',
    this_month: 'text-blue-400',
    later: 'text-surface-500',
    no_date: 'text-surface-400'
  }

  const groupedByDate = computed(() => {
    const groups = {}
    for (const key of dateGroupOrder) {
      groups[key] = []
    }
    for (const item of filteredItems.value) {
      const cat = getDateCategory(item.dueDate)
      groups[cat].push(item)
    }
    return dateGroupOrder
      .filter(key => groups[key].length > 0)
      .map(key => ({
        key,
        label: dateGroupLabels[key],
        icon: dateGroupIcons[key],
        colorClass: dateGroupColors[key],
        items: groups[key]
      }))
  })

  const groupedByBoard = computed(() => {
    const map = {}
    for (const item of filteredItems.value) {
      const key = item.type === 'todo' ? '__personal__' : `board_${item.boardId}`
      if (!map[key]) {
        map[key] = {
          key,
          label: item.type === 'todo' ? 'Personal Tasks' : item.boardName,
          icon: item.type === 'todo' ? 'task_alt' : 'dashboard',
          boardId: item.boardId,
          items: []
        }
      }
      map[key].items.push(item)
    }
    const groups = Object.values(map)
    groups.sort((a, b) => {
      if (a.key === '__personal__') return -1
      if (b.key === '__personal__') return 1
      return a.label.localeCompare(b.label)
    })
    return groups
  })

  const priorityOrder = ['high', 'normal', 'low']
  const priorityLabels = { high: 'High Priority', normal: 'Medium Priority', low: 'Low Priority' }
  const priorityIcons = { high: 'priority_high', normal: 'drag_handle', low: 'low_priority' }
  const priorityColors = { high: 'text-red-500', normal: 'text-amber-500', low: 'text-blue-500' }

  const groupedByPriority = computed(() => {
    const groups = {}
    for (const key of priorityOrder) {
      groups[key] = []
    }
    for (const item of filteredItems.value) {
      const p = priorityOrder.includes(item.priority) ? item.priority : 'normal'
      groups[p].push(item)
    }
    return priorityOrder
      .filter(key => groups[key].length > 0)
      .map(key => ({
        key,
        label: priorityLabels[key],
        icon: priorityIcons[key],
        colorClass: priorityColors[key],
        items: groups[key]
      }))
  })

  const groupedByStatus = computed(() => {
    const map = {}
    for (const item of filteredItems.value) {
      const key = item.type === 'todo'
        ? (item.completed ? 'completed' : 'to_do')
        : (item.listName || 'Unknown')
      if (!map[key]) {
        map[key] = {
          key,
          label: key === 'to_do' ? 'To Do' : key === 'completed' ? 'Completed' : key,
          icon: key === 'completed' ? 'check_circle' : key === 'to_do' ? 'radio_button_unchecked' : 'view_kanban',
          items: []
        }
      }
      map[key].items.push(item)
    }
    return Object.values(map)
  })

  const currentGroups = computed(() => {
    switch (groupBy.value) {
      case 'board': return groupedByBoard.value
      case 'priority': return groupedByPriority.value
      case 'status': return groupedByStatus.value
      default: return groupedByDate.value
    }
  })

  const totalCount = computed(() => filteredItems.value.length)
  const completedCount = computed(() => dbStats.value.completed_total || 0)
  const overdueCount = computed(() => filteredItems.value.filter(i => getDateCategory(i.dueDate) === 'overdue').length)
  const completedThisWeek = computed(() => dbStats.value.completed_this_week || 0)
  const dueTodayCount = computed(() => filteredItems.value.filter(i => getDateCategory(i.dueDate) === 'today').length)
  const highPriorityCount = computed(() => filteredItems.value.filter(i => i.priority === 'high' && !i.completed).length)

  return {
    items,
    loading,
    error,
    includeCompleted,
    kanbanEnabled,
    dbStats,
    groupBy,
    viewMode,
    filterPriority,
    filterSource,
    searchQuery,
    setGroupBy,
    setViewMode,
    setFilterPriority,
    setFilterSource,
    setIncludeCompleted,
    fetchMyWork,
    backgroundRefresh,
    trackSave,
    findItem,
    updateItemLocally,
    removeItemLocally,
    addSubtodoLocally,
    removeSubtodoLocally,
    toggleSubtodoLocally,
    toggleChecklistItemLocally,
    removeChecklistItemLocally,
    addChecklistItemLocally,
    filteredItems,
    currentGroups,
    groupedByDate,
    groupedByBoard,
    groupedByPriority,
    groupedByStatus,
    totalCount,
    completedCount,
    overdueCount,
    completedThisWeek,
    dueTodayCount,
    highPriorityCount
  }
})
