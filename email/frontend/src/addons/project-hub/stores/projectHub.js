import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useMailSync, EventTypes } from '@/services/mailSyncSocket'

export const useProjectHubStore = defineStore('projectHub', () => {
  const { on } = useMailSync()
  const wsUnsubs = []
  // =========================================================================
  // State
  // =========================================================================

  // Hierarchy
  const spaces = ref([])
  const unsortedBoards = ref([])
  const hierarchyLoaded = ref(false)
  const hierarchyLoading = ref(false)

  // Active selection
  const activeView = ref('my-work') // 'my-work' | 'folder:{id}' | 'board:{id}' | 'unsorted-board:{id}'
  const activeFolder = ref(null)
  const activeSpace = ref(null)

  // Folder data
  const folderOverview = ref(null)
  const folderOverviewLoading = ref(false)

  // Card assignees (keyed by cardId)
  const cardAssignees = ref({})
  const cardAssigneesLoading = ref(false)

  // Work sessions (keyed by cardId)
  const cardWorkSessions = ref({})

  // Dependencies (keyed by cardId)
  const cardDependencies = ref({})

  // Subtask -> created card links (keyed by parent cardId, then subtaskId)
  const subtaskCardLinks = ref({})
  const cardOriginLinks = ref({})

  // Card layout preference
  const cardLayout = ref(localStorage.getItem('card_layout_preference') || 'modal')

  // Folder view mode (overview, list, board, table, files, links)
  const folderViewMode = ref('overview')

  // Sidebar expanded state (survives route changes)
  const sidebarExpandedSpaces = ref({})
  const sidebarExpandedFolders = ref({})

  // =========================================================================
  // Computed
  // =========================================================================

  const activeSpaceId = computed(() => activeSpace.value?.id ?? null)
  const activeFolderId = computed(() => activeFolder.value?.id ?? null)

  const spacesWithFolders = computed(() => {
    return spaces.value.map(space => ({
      ...space,
      folders: (space.folders || []).map(folder => ({
        ...folder,
        boards: folder.boards || [],
      })),
    }))
  })

  // =========================================================================
  // Hierarchy Actions
  // =========================================================================

  async function fetchHierarchy() {
    if (hierarchyLoading.value) return
    hierarchyLoading.value = true
    try {
      const { data } = await api.get('/project-hub/hierarchy')
      spaces.value = data.spaces || []
      unsortedBoards.value = data.unsorted || []
      hierarchyLoaded.value = true
    } catch (err) {
      console.error('[ProjectHub] fetchHierarchy error:', err)
    } finally {
      hierarchyLoading.value = false
    }
  }

  // =========================================================================
  // Space Actions
  // =========================================================================

  async function createSpace(payload) {
    try {
      const { data } = await api.post('/project-hub/spaces', payload)
      await fetchHierarchy()
      return data
    } catch (err) {
      console.error('[ProjectHub] createSpace error:', err)
      throw err
    }
  }

  async function updateSpace(id, payload) {
    try {
      const { data } = await api.put(`/project-hub/spaces/${id}`, payload)
      await fetchHierarchy()
      return data
    } catch (err) {
      console.error('[ProjectHub] updateSpace error:', err)
      throw err
    }
  }

  async function deleteSpace(id) {
    try {
      await api.delete(`/project-hub/spaces/${id}`)
      await fetchHierarchy()
    } catch (err) {
      console.error('[ProjectHub] deleteSpace error:', err)
      throw err
    }
  }

  async function reorderSpaces(ids) {
    try {
      await api.post('/project-hub/spaces/reorder', { ids })
    } catch (err) {
      console.error('[ProjectHub] reorderSpaces error:', err)
    }
  }

  // =========================================================================
  // Folder Actions
  // =========================================================================

  async function createFolder(spaceId, payload) {
    try {
      const { data } = await api.post(`/project-hub/spaces/${spaceId}/folders`, payload)
      await fetchHierarchy()
      return data
    } catch (err) {
      console.error('[ProjectHub] createFolder error:', err)
      throw err
    }
  }

  async function updateFolder(id, payload) {
    try {
      const { data } = await api.put(`/project-hub/folders/${id}`, payload)
      await fetchHierarchy()
      return data
    } catch (err) {
      console.error('[ProjectHub] updateFolder error:', err)
      throw err
    }
  }

  async function deleteFolder(id) {
    try {
      await api.delete(`/project-hub/folders/${id}`)
      await fetchHierarchy()
    } catch (err) {
      console.error('[ProjectHub] deleteFolder error:', err)
      throw err
    }
  }

  // =========================================================================
  // Folder <-> Board Link Actions
  // =========================================================================

  async function linkBoard(folderId, boardId) {
    try {
      await api.post(`/project-hub/folders/${folderId}/boards`, { board_id: boardId })
      await fetchHierarchy()
    } catch (err) {
      console.error('[ProjectHub] linkBoard error:', err)
      throw err
    }
  }

  async function unlinkBoard(folderId, boardId) {
    try {
      await api.delete(`/project-hub/folders/${folderId}/boards/${boardId}`)
      await fetchHierarchy()
    } catch (err) {
      console.error('[ProjectHub] unlinkBoard error:', err)
      throw err
    }
  }

  // =========================================================================
  // Folder Overview
  // =========================================================================

  async function fetchFolderOverview(folderId) {
    folderOverviewLoading.value = true
    try {
      const { data } = await api.get(`/project-hub/folders/${folderId}/overview`)
      folderOverview.value = data
      return data
    } catch (err) {
      console.error('[ProjectHub] fetchFolderOverview error:', err)
    } finally {
      folderOverviewLoading.value = false
    }
  }

  // =========================================================================
  // Card Assignees
  // =========================================================================

  async function fetchCardAssignees(cardId) {
    cardAssigneesLoading.value = true
    try {
      const { data } = await api.get(`/project-hub/cards/${cardId}/assignees`)
      cardAssignees.value[cardId] = data.assignees || []
      return data.assignees
    } catch (err) {
      console.error('[ProjectHub] fetchCardAssignees error:', err)
      return []
    } finally {
      cardAssigneesLoading.value = false
    }
  }

  /**
   * Batched assignee fetch for many cards in a single HTTP call.
   * Populates `cardAssignees[id]` for every requested card (empty
   * array for cards with no assignees) and returns the flat list.
   * @param {number[]} cardIds
   * @returns {Promise<{ map: Record<number, Array>, flat: Array }>}
   */
  async function fetchCardAssigneesBatch(cardIds) {
    const ids = Array.from(new Set((cardIds || []).map(Number).filter(Boolean)))
    if (ids.length === 0) return { map: {}, flat: [] }
    cardAssigneesLoading.value = true
    try {
      const { data } = await api.post('/project-hub/cards/assignees/batch-fetch', { card_ids: ids })
      const map = data?.assignees || {}
      const flat = []
      for (const id of ids) {
        const list = map[id] || []
        cardAssignees.value[id] = list
        for (const a of list) flat.push(a)
      }
      return { map, flat }
    } catch (err) {
      console.error('[ProjectHub] fetchCardAssigneesBatch error:', err)
      return { map: {}, flat: [] }
    } finally {
      cardAssigneesLoading.value = false
    }
  }

  async function addAssignee(cardId, userEmail, role = 'assignee') {
    try {
      const { data } = await api.post(`/project-hub/cards/${cardId}/assignees`, {
        user_email: userEmail,
        role,
      })
      await fetchCardAssignees(cardId)
      return data
    } catch (err) {
      console.error('[ProjectHub] addAssignee error:', err)
      throw err
    }
  }

  /**
   * Assign many emails to a card in one HTTP call. Used for "assign group"
   * actions where the old per-email loop fired N sequential POSTs.
   * Re-fetches assignees ONCE on success.
   */
  async function addAssigneesBatch(cardId, emails, role = 'assignee') {
    try {
      const { data } = await api.post(`/project-hub/cards/${cardId}/assignees/batch`, {
        emails,
        role,
      })
      // Server returns the refreshed list; use it directly to avoid a follow-up GET.
      if (data?.assignees) {
        cardAssignees.value[cardId] = data.assignees
      } else {
        await fetchCardAssignees(cardId)
      }
      return data
    } catch (err) {
      console.error('[ProjectHub] addAssigneesBatch error:', err)
      throw err
    }
  }

  async function updateAssignee(assigneeId, payload) {
    try {
      const { data } = await api.put(`/project-hub/card-assignees/${assigneeId}`, payload)
      return data
    } catch (err) {
      console.error('[ProjectHub] updateAssignee error:', err)
      throw err
    }
  }

  async function changeAssigneeStatus(assigneeId, status) {
    try {
      const { data } = await api.post(`/project-hub/card-assignees/${assigneeId}/status`, { status })
      return data
    } catch (err) {
      console.error('[ProjectHub] changeAssigneeStatus error:', err)
      throw err
    }
  }

  /**
   * Batched delete: remove many assignee rows in ONE HTTP call.
   * Optionally refresh the assignee list for one card afterward.
   * @param {number[]} assigneeIds
   * @param {number|null} cardId
   * @returns {Promise<{deleted:number}>}
   */
  async function removeAssigneesBatch(assigneeIds, cardId = null) {
    const ids = Array.from(new Set((assigneeIds || []).map(Number).filter(Boolean)))
    if (ids.length === 0) return { deleted: 0 }
    try {
      const { data } = await api.delete('/project-hub/card-assignees/batch', {
        data: { ids },
      })
      if (cardId) await fetchCardAssignees(cardId)
      return { deleted: data?.deleted || 0 }
    } catch (err) {
      console.error('[ProjectHub] removeAssigneesBatch error:', err)
      return { deleted: 0 }
    }
  }

  async function removeAssignee(assigneeId, cardId) {
    try {
      await api.delete(`/project-hub/card-assignees/${assigneeId}`)
      if (cardId) await fetchCardAssignees(cardId)
    } catch (err) {
      console.error('[ProjectHub] removeAssignee error:', err)
      throw err
    }
  }

  // =========================================================================
  // Work Sessions
  // =========================================================================

  async function fetchWorkSessions(cardId, userEmail = null) {
    try {
      const params = userEmail ? { user_email: userEmail } : {}
      const { data } = await api.get(`/project-hub/cards/${cardId}/work-sessions`, { params })
      cardWorkSessions.value[cardId] = data.sessions || []
      return data.sessions
    } catch (err) {
      console.error('[ProjectHub] fetchWorkSessions error:', err)
      return []
    }
  }

  async function logWorkSession(payload) {
    try {
      const { data } = await api.post('/project-hub/work-sessions', payload)
      return data
    } catch (err) {
      console.error('[ProjectHub] logWorkSession error:', err)
      throw err
    }
  }

  // =========================================================================
  // Dependencies
  // =========================================================================

  async function fetchDependencies(cardId) {
    try {
      const { data } = await api.get(`/project-hub/cards/${cardId}/dependencies`)
      cardDependencies.value[cardId] = data
      return data
    } catch (err) {
      console.error('[ProjectHub] fetchDependencies error:', err)
      return { waiting_on: [], blocking: [] }
    }
  }

  async function createDependency(cardId, dependsOnCardId, type = 'finish_to_start') {
    try {
      const { data } = await api.post(`/project-hub/cards/${cardId}/dependencies`, {
        depends_on_card_id: dependsOnCardId,
        type,
      })
      await fetchDependencies(cardId)
      return data
    } catch (err) {
      console.error('[ProjectHub] createDependency error:', err)
      throw err
    }
  }

  async function deleteDependency(depId, cardId) {
    try {
      await api.delete(`/project-hub/dependencies/${depId}`)
      if (cardId) await fetchDependencies(cardId)
    } catch (err) {
      console.error('[ProjectHub] deleteDependency error:', err)
      throw err
    }
  }

  // Aliases used by components
  const fetchCardDependencies = fetchDependencies
  const createCardDependency = createDependency
  const deleteCardDependency = deleteDependency
  const fetchUnreadCount = getUnreadCount

  // =========================================================================
  // Comment Reactions & Read Tracking
  // =========================================================================

  async function toggleReaction(commentId, emoji) {
    try {
      const { data } = await api.post(`/project-hub/comments/${commentId}/reactions`, { emoji })
      return data
    } catch (err) {
      console.error('[ProjectHub] toggleReaction error:', err)
      throw err
    }
  }

  async function markCommentsRead(cardId) {
    try {
      await api.post(`/project-hub/cards/${cardId}/mark-read`)
    } catch (err) {
      console.error('[ProjectHub] markCommentsRead error:', err)
    }
  }

  async function getUnreadCount(cardId) {
    try {
      const { data } = await api.get(`/project-hub/cards/${cardId}/unread-count`)
      return data.unread_count || 0
    } catch (err) {
      return 0
    }
  }

  // =========================================================================
  // Bookmarks
  // =========================================================================

  async function fetchBookmarks(contextType, contextId) {
    const id = contextId || contextType
    try {
      const { data } = await api.get(`/project-hub/folders/${id}/bookmarks`)
      return data.bookmarks || []
    } catch (err) {
      console.error('[ProjectHub] fetchBookmarks error:', err)
      return []
    }
  }

  async function fetchFolderBoards(folderId) {
    try {
      const { data } = await api.get(`/project-hub/folders/${folderId}/boards`)
      return data.boards || []
    } catch (err) {
      console.error('[ProjectHub] fetchFolderBoards error:', err)
      return []
    }
  }

  async function createBookmark(folderId, payload) {
    try {
      const { data } = await api.post(`/project-hub/folders/${folderId}/bookmarks`, payload)
      return data
    } catch (err) {
      console.error('[ProjectHub] createBookmark error:', err)
      throw err
    }
  }

  async function deleteBookmark(id) {
    try {
      await api.delete(`/project-hub/bookmarks/${id}`)
    } catch (err) {
      console.error('[ProjectHub] deleteBookmark error:', err)
      throw err
    }
  }

  // =========================================================================
  // Card Layout
  // =========================================================================

  function setCardLayout(layout) {
    cardLayout.value = layout
    localStorage.setItem('card_layout_preference', layout)
  }

  // =========================================================================
  // Sidebar expand/collapse
  // =========================================================================

  function toggleSidebarSpace(id, forceExpand = false) {
    if (forceExpand) sidebarExpandedSpaces.value[id] = true
    else sidebarExpandedSpaces.value[id] = !sidebarExpandedSpaces.value[id]
  }

  function toggleSidebarFolder(id) {
    sidebarExpandedFolders.value[id] = !sidebarExpandedFolders.value[id]
  }

  function expandSidebarSpace(id) {
    sidebarExpandedSpaces.value[id] = true
  }

  function expandSidebarFolder(id) {
    sidebarExpandedFolders.value[id] = true
  }

  function ensureSpacesExpanded() {
    spaces.value.forEach(s => {
      if (!(s.id in sidebarExpandedSpaces.value)) {
        sidebarExpandedSpaces.value[s.id] = true
      }
    })
  }

  function expandAllSidebar() {
    for (const space of spaces.value) {
      sidebarExpandedSpaces.value[space.id] = true
      for (const folder of (space.folders || [])) {
        sidebarExpandedFolders.value[folder.id] = true
      }
    }
  }

  function collapseAllSidebar() {
    for (const id in sidebarExpandedSpaces.value) {
      sidebarExpandedSpaces.value[id] = false
    }
    for (const id in sidebarExpandedFolders.value) {
      sidebarExpandedFolders.value[id] = false
    }
  }

  // =========================================================================
  // Navigation
  // =========================================================================

  function selectMyWork() {
    activeView.value = 'my-work'
    activeFolder.value = null
    activeSpace.value = null
  }

  function selectFolder(folder, space) {
    activeView.value = `folder:${folder.id}`
    activeFolder.value = folder
    activeSpace.value = space
    expandSidebarFolder(folder.id)
    fetchFolderOverview(folder.id)
  }

  function selectBoard(boardId) {
    activeView.value = `board:${boardId}`
    for (const space of spaces.value) {
      for (const folder of (space.folders || [])) {
        const match = (folder.boards || []).find(b => b.board_id === boardId || b.board_id === Number(boardId))
        if (match) {
          expandSidebarSpace(space.id)
          expandSidebarFolder(folder.id)
          return
        }
      }
    }
  }

  function selectUnsortedBoard(boardId) {
    activeView.value = `unsorted-board:${boardId}`
  }

  function findFolderById(folderId) {
    for (const space of spaces.value) {
      const folder = (space.folders || []).find(f => f.id === folderId)
      if (folder) return folder
    }
    return null
  }

  function findSpaceByFolderId(folderId) {
    for (const space of spaces.value) {
      if ((space.folders || []).some(f => f.id === folderId)) return space
    }
    return null
  }

  // =========================================================================
  // Workload Planner
  // =========================================================================

  async function fetchWorkloadTimeline(startDate, endDate, spaceId = null, filters = {}) {
    try {
      const params = { start_date: startDate, end_date: endDate }
      if (spaceId) params.space_id = spaceId
      if (filters.member_email) params.member_email = filters.member_email
      if (filters.group_id) params.group_id = filters.group_id
      if (filters.label_id) params.label_id = filters.label_id
      const { data } = await api.get('/project-hub/workload/timeline', { params })
      return data.members || []
    } catch (err) {
      console.error('[ProjectHub] fetchWorkloadTimeline error:', err)
      return []
    }
  }

  async function fetchWorkloadLabels() {
    try {
      const { data } = await api.get('/project-hub/workload/labels')
      return data.labels || []
    } catch (err) {
      console.error('[ProjectHub] fetchWorkloadLabels error:', err)
      return []
    }
  }

  async function fetchWorkloadLive() {
    try {
      const { data } = await api.get('/project-hub/workload/live')
      return data.members || []
    } catch (err) {
      console.error('[ProjectHub] fetchWorkloadLive error:', err)
      return []
    }
  }

  async function fetchMemberWorkload(email) {
    try {
      const { data } = await api.get(`/project-hub/workload/member/${encodeURIComponent(email)}`)
      return data.cards || []
    } catch (err) {
      console.error('[ProjectHub] fetchMemberWorkload error:', err)
      return []
    }
  }

  // =========================================================================
  // Subtasks
  // =========================================================================

  async function fetchSubtasks(cardId) {
    try {
      const { data } = await api.get(`/boards/cards/${cardId}/subtasks`)
      return data.subtasks || []
    } catch (err) {
      console.error('[ProjectHub] fetchSubtasks error:', err)
      return []
    }
  }

  async function createSubtask(parentCardId, payload) {
    try {
      const { data } = await api.post(`/boards/cards/${parentCardId}/subtasks`, payload)
      return data
    } catch (err) {
      console.error('[ProjectHub] createSubtask error:', err)
      throw err
    }
  }

  /**
   * Batched subtask create. Sends one POST with up to 100 rows; server
   * does one INSERT + one pubsub event for the whole batch.
   * @param {number} parentCardId
   * @param {Array<{title:string, description?:string, due_date?:string, assigned_to?:string}>} rows
   * @returns {Promise<{success:number, failed:number, subtasks:Array}>}
   */
  async function createSubtasksBatch(parentCardId, rows) {
    if (!rows?.length) return { success: 0, failed: 0, subtasks: [] }
    try {
      const { data } = await api.post(`/boards/cards/${parentCardId}/subtasks/batch`, { rows })
      return {
        success: data?.success || 0,
        failed: data?.failed || 0,
        subtasks: data?.subtasks || [],
      }
    } catch (err) {
      console.error('[ProjectHub] createSubtasksBatch error:', err)
      return { success: 0, failed: rows.length, subtasks: [] }
    }
  }

  async function deleteSubtask(subtaskId) {
    try {
      await api.delete(`/boards/cards/${subtaskId}`)
      return true
    } catch (err) {
      console.error('[ProjectHub] deleteSubtask error:', err)
      throw err
    }
  }

  async function fetchSubtaskCardLinks(cardId) {
    try {
      const { data } = await api.get(`/project-hub/cards/${cardId}/subtask-card-links`)
      const mapped = {}
      ;(data.links || []).forEach(link => {
        mapped[Number(link.subtask_card_id)] = link
      })
      subtaskCardLinks.value[cardId] = mapped
      return mapped
    } catch (err) {
      console.error('[ProjectHub] fetchSubtaskCardLinks error:', err)
      subtaskCardLinks.value[cardId] = {}
      return {}
    }
  }

  async function createSubtaskCardLink(cardId, subtaskId, linkedCardId) {
    try {
      const { data } = await api.post(`/project-hub/cards/${cardId}/subtasks/${subtaskId}/linked-card`, {
        linked_card_id: linkedCardId,
      })
      if (!subtaskCardLinks.value[cardId]) {
        subtaskCardLinks.value[cardId] = {}
      }
      subtaskCardLinks.value[cardId][subtaskId] = data
      return data
    } catch (err) {
      console.error('[ProjectHub] createSubtaskCardLink error:', err)
      throw err
    }
  }

  async function fetchCardOriginLink(cardId) {
    try {
      const { data } = await api.get(`/project-hub/cards/${cardId}/origin-link`)
      cardOriginLinks.value[cardId] = data.link || null
      return data.link || null
    } catch (err) {
      if (err?.response?.status !== 404) {
        console.error('[ProjectHub] fetchCardOriginLink error:', err)
      }
      cardOriginLinks.value[cardId] = null
      return null
    }
  }

  // =========================================================================
  // WebSocket Listeners
  // =========================================================================

  function initWebSocketListeners() {
    // Hierarchy changes -- refresh sidebar tree
    wsUnsubs.push(on(EventTypes.SPACE_UPDATED, () => { fetchHierarchy() }))
    wsUnsubs.push(on(EventTypes.FOLDER_UPDATED, () => {
      fetchHierarchy()
      if (activeFolderId.value) fetchFolderOverview(activeFolderId.value)
    }))

    // Assignee changes -- refresh card assignee list
    wsUnsubs.push(on(EventTypes.CARD_ASSIGNEE_ADDED, (p) => {
      if (p.card_id && cardAssignees.value[p.card_id]) fetchCardAssignees(p.card_id)
    }))
    wsUnsubs.push(on(EventTypes.CARD_ASSIGNEE_UPDATED, (p) => {
      if (p.assignee?.card_id && cardAssignees.value[p.assignee.card_id]) fetchCardAssignees(p.assignee.card_id)
    }))
    wsUnsubs.push(on(EventTypes.CARD_ASSIGNEE_REMOVED, (p) => {
      if (p.card_id && cardAssignees.value[p.card_id]) fetchCardAssignees(p.card_id)
    }))

    // Dependency changes
    wsUnsubs.push(on(EventTypes.CARD_DEPENDENCY_ADDED, (p) => {
      if (p.card_id && cardDependencies.value[p.card_id]) fetchDependencies(p.card_id)
    }))
    wsUnsubs.push(on(EventTypes.CARD_DEPENDENCY_REMOVED, (p) => {
      Object.keys(cardDependencies.value).forEach(cid => fetchDependencies(cid))
    }))

    // Work sessions
    wsUnsubs.push(on(EventTypes.CARD_WORK_SESSION, (p) => {
      if (p.card_id && cardWorkSessions.value[p.card_id]) fetchWorkSessions(p.card_id)
    }))
  }

  function destroyWebSocketListeners() {
    wsUnsubs.forEach(fn => { if (typeof fn === 'function') fn() })
    wsUnsubs.length = 0
  }

  // Auto-init listeners
  initWebSocketListeners()

  // =========================================================================
  // Return
  // =========================================================================

  return {
    // State
    spaces,
    unsortedBoards,
    hierarchyLoaded,
    hierarchyLoading,
    activeView,
    activeFolder,
    activeSpace,
    folderOverview,
    folderOverviewLoading,
    sidebarExpandedSpaces,
    sidebarExpandedFolders,
    cardAssignees,
    cardAssigneesLoading,
    cardWorkSessions,
    cardDependencies,
    subtaskCardLinks,
    cardLayout,
    folderViewMode,

    // Computed
    activeSpaceId,
    activeFolderId,
    spacesWithFolders,

    // Hierarchy
    fetchHierarchy,

    // Spaces
    createSpace,
    updateSpace,
    deleteSpace,
    reorderSpaces,

    // Folders
    createFolder,
    updateFolder,
    deleteFolder,

    // Folder <-> Board
    linkBoard,
    unlinkBoard,

    // Sidebar
    toggleSidebarSpace,
    toggleSidebarFolder,
    expandSidebarSpace,
    expandSidebarFolder,
    ensureSpacesExpanded,
    expandAllSidebar,
    collapseAllSidebar,

    // Folder Overview
    fetchFolderOverview,

    // Assignees
    fetchCardAssignees,
    fetchCardAssigneesBatch,
    addAssignee,
    addAssigneesBatch,
    removeAssigneesBatch,
    updateAssignee,
    changeAssigneeStatus,
    removeAssignee,

    // Work Sessions
    fetchWorkSessions,
    logWorkSession,

    // Dependencies
    fetchDependencies,
    createDependency,
    deleteDependency,
    fetchCardDependencies,
    createCardDependency,
    deleteCardDependency,

    // Comments
    toggleReaction,
    markCommentsRead,
    getUnreadCount,
    fetchUnreadCount,

    // Bookmarks
    fetchBookmarks,
    createBookmark,
    deleteBookmark,

    // Folder Boards
    fetchFolderBoards,

    // Card Layout
    setCardLayout,

    // Navigation
    selectMyWork,
    selectFolder,
    selectBoard,
    selectUnsortedBoard,
    findFolderById,
    findSpaceByFolderId,

    // Workload
    fetchWorkloadTimeline,
    fetchWorkloadLabels,
    fetchWorkloadLive,
    fetchMemberWorkload,

    // Subtasks
    fetchSubtasks,
    createSubtask,
    createSubtasksBatch,
    deleteSubtask,
    fetchSubtaskCardLinks,
    createSubtaskCardLink,
    fetchCardOriginLink,

    // WebSocket
    initWebSocketListeners,
    destroyWebSocketListeners,
  }
})
