import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useMailSync, EventTypes } from '@/services/mailSyncSocket'
import { isDebugEnabled } from '@/utils/debug'

export const useColleaguesStore = defineStore('colleagues', () => {
  // Get mail sync socket
  const mailSync = useMailSync()
  // State
  const colleagues = ref([])
  const groups = ref([])
  const currentColleague = ref(null) // Current user's colleague profile
  const isAdmin = ref(false)
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref(null)
  
  // Presence tracking: Map of email -> { status, lastActivity }
  const presence = ref({})
  const presenceSubscribed = ref(false)
  
  // Computed
  const colleaguesByGroup = computed(() => {
    const map = {}
    for (const group of groups.value) {
      map[group.id] = colleagues.value.filter(c => 
        c.group_ids && c.group_ids.includes(group.id)
      )
    }
    // Ungrouped colleagues
    map['ungrouped'] = colleagues.value.filter(c => 
      !c.group_ids || c.group_ids.length === 0
    )
    return map
  })
  
  const colleagueById = computed(() => {
    const map = {}
    for (const c of colleagues.value) {
      map[c.id] = c
    }
    return map
  })
  
  const colleagueByEmail = computed(() => {
    const map = {}
    for (const c of colleagues.value) {
      map[c.email.toLowerCase()] = c
    }
    return map
  })
  
  const groupById = computed(() => {
    const map = {}
    for (const g of groups.value) {
      map[g.id] = g
    }
    return map
  })
  
  const sortedColleagues = computed(() => {
    return [...colleagues.value].sort((a, b) => {
      // Online users first
      const aStatus = getColleagueStatus(a.email)
      const bStatus = getColleagueStatus(b.email)
      const aOnline = aStatus !== 'offline'
      const bOnline = bStatus !== 'offline'
      
      if (aOnline !== bOnline) {
        return aOnline ? -1 : 1
      }
      
      // Then by name
      return (a.display_name || a.email).localeCompare(b.display_name || b.email)
    })
  })
  
  // Get colleague status from presence (real-time)
  function getColleagueStatus(email) {
    // Check real-time presence from WebSocket
    const p = presence.value[email?.toLowerCase()]
    if (p) return p.status

    // No presence data = user is not connected = offline
    // (Presence data is populated when users connect via WebSocket)
    return 'offline'
  }

  function getColleagueCurrentView(email) {
    const p = presence.value[email?.toLowerCase()]
    return p?.currentView || null
  }
  
  // Get online colleagues count
  const onlineCount = computed(() => {
    return Object.values(presence.value).filter(p => p.status !== 'offline').length
  })
  
  const sortedGroups = computed(() => {
    return [...groups.value].sort((a, b) => {
      if (a.sort_order !== b.sort_order) {
        return a.sort_order - b.sort_order
      }
      return a.name.localeCompare(b.name)
    })
  })
  
  // Actions
  async function fetchColleagues(forceReload = false) {
    if (loaded.value && colleagues.value.length > 0 && !forceReload) return
    loading.value = true
    error.value = null
    try {
      const response = await api.get('/colleagues')
      if (response.data.success) {
        colleagues.value = response.data.data.colleagues || []
        isAdmin.value = response.data.data.is_admin || false
      }
    } catch (e) {
      error.value = e.message
      console.error('Failed to fetch colleagues:', e)
    } finally {
      loading.value = false
    }
  }
  
  async function fetchGroups(forceReload = false) {
    if (loaded.value && groups.value.length > 0 && !forceReload) return
    try {
      const response = await api.get('/colleagues/groups')
      if (response.data.success) {
        groups.value = response.data.data.groups || []
      }
    } catch (e) {
      console.error('Failed to fetch groups:', e)
    }
  }
  
  async function fetchMe(force = false) {
    if (currentColleague.value && !force) return
    try {
      const response = await api.get('/colleagues/me')
      if (response.data.success) {
        const me = response.data.data.colleague
        currentColleague.value = me
        isAdmin.value = me?.is_admin || false
        // Keep the colleagues list in sync so avatars resolved by email/id
        // (header, chat, mentions, etc.) reflect the fresh data immediately.
        if (me?.id) {
          const idx = colleagues.value.findIndex(c => c.id === me.id)
          if (idx !== -1) {
            colleagues.value[idx] = { ...colleagues.value[idx], ...me }
          }
        }
      }
    } catch (e) {
      console.error('Failed to fetch current colleague:', e)
    }
  }

  /**
   * Optimistically apply the current user's new avatar everywhere it is
   * resolved from the store (top-right header via :email, chat messages via
   * colleagueById, etc.) so the change is visible instantly without waiting
   * for a WebSocket round-trip or a full refetch.
   * @param {string|null} avatarPath - new avatar_path, or null when removed
   */
  function setMyAvatar(avatarPath) {
    const path = avatarPath || null
    if (currentColleague.value) {
      currentColleague.value = { ...currentColleague.value, avatar_path: path }
    }
    const myEmail = currentColleague.value?.email?.toLowerCase()
    const myId = currentColleague.value?.id
    const idx = colleagues.value.findIndex(c =>
      (myId && c.id === myId) || (myEmail && c.email?.toLowerCase() === myEmail)
    )
    if (idx !== -1) {
      colleagues.value[idx] = { ...colleagues.value[idx], avatar_path: path }
    }
  }
  
  async function updateMyProfile(data) {
    try {
      const response = await api.put('/colleagues/me', data)
      if (response.data.success) {
        currentColleague.value = null
        await fetchMe()
        await fetchColleagues(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function syncFromMailServer() {
    try {
      const response = await api.post('/colleagues/sync')
      if (response.data.success) {
        await fetchColleagues(true)
        const d = response.data.data || {}
        return { 
          success: true, 
          synced: d.synced,
          total: d.total,
          db_total: d.db_total,
          sources: d.sources,
          emails_found: d.emails_found
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function addColleague(data) {
    try {
      const response = await api.post('/colleagues', data)
      if (response.data.success) {
        await fetchColleagues(true)
        return { success: true, id: response.data.data.id }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function updateColleague(id, data) {
    try {
      const response = await api.put(`/colleagues/${id}`, data)
      if (response.data.success) {
        await fetchColleagues(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function deleteColleague(id) {
    try {
      const response = await api.delete(`/colleagues/${id}`)
      if (response.data.success) {
        await fetchColleagues(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  // Group actions
  async function createGroup(data) {
    try {
      const response = await api.post('/colleagues/groups', data)
      if (response.data.success) {
        await fetchGroups(true)
        return { success: true, id: response.data.data.id }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function updateGroup(id, data) {
    try {
      const response = await api.put(`/colleagues/groups/${id}`, data)
      if (response.data.success) {
        await fetchGroups(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function deleteGroup(id) {
    try {
      const response = await api.delete(`/colleagues/groups/${id}`)
      if (response.data.success) {
        await fetchGroups(true)
        await fetchColleagues(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function addMembersToGroup(groupId, colleagueIds) {
    try {
      const response = await api.post(`/colleagues/groups/${groupId}/members`, {
        colleague_ids: colleagueIds
      })
      if (response.data.success) {
        await fetchGroups(true)
        await fetchColleagues(true)
        return { success: true, added: response.data.data.added }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function removeMemberFromGroup(groupId, colleagueId) {
    try {
      const response = await api.delete(`/colleagues/groups/${groupId}/members/${colleagueId}`)
      if (response.data.success) {
        await fetchGroups(true)
        await fetchColleagues(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function setColleagueGroups(colleagueId, groupIds) {
    try {
      const response = await api.put(`/colleagues/${colleagueId}/groups`, {
        group_ids: groupIds
      })
      if (response.data.success) {
        // Refresh both colleagues AND groups to update member counts
        await Promise.all([fetchColleagues(true), fetchGroups(true)])
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  // My effective group permissions
  const myPermissions = ref(null)

  async function fetchMyPermissions() {
    try {
      const response = await api.get('/colleagues/me/permissions')
      if (response.data.success) {
        myPermissions.value = response.data.data.permissions || {}
      }
    } catch (e) {
      console.error('Failed to fetch permissions:', e)
    }
  }

  // Group sharing
  async function shareFolderWithGroup(groupId, folderId, permission = 'viewer') {
    try {
      const response = await api.post(`/colleagues/groups/${groupId}/share/folder`, {
        folder_id: folderId,
        permission
      })
      return response.data.success 
        ? { success: true } 
        : { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function shareBoardWithGroup(groupId, boardId, canEdit = false, canViewFinancials = false) {
    try {
      const response = await api.post(`/colleagues/groups/${groupId}/share/board`, {
        board_id: boardId,
        can_edit: canEdit,
        can_view_financials: canViewFinancials
      })
      return response.data.success 
        ? { success: true } 
        : { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function shareCalendarWithGroup(groupId, calendarId, canEdit = false, canSeeDetails = true) {
    try {
      const response = await api.post(`/colleagues/groups/${groupId}/share/calendar`, {
        calendar_id: calendarId,
        can_edit: canEdit,
        can_see_details: canSeeDetails
      })
      return response.data.success 
        ? { success: true } 
        : { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  // Get members of a specific group
  async function getGroupMembers(groupId) {
    try {
      const response = await api.get(`/colleagues/groups/${groupId}/members`)
      if (response.data.success) {
        return response.data.data || []
      }
      return []
    } catch (e) {
      console.error('Failed to fetch group members:', e)
      return []
    }
  }
  
  // Initialize with data and set up WebSocket listeners
  async function init() {
    if (!loaded.value) {
      await fetchMe()
      await Promise.all([
        fetchColleagues(),
        fetchGroups(),
        fetchMyPermissions()
      ])
    } else if (!currentColleague.value) {
      await fetchMe()
    }
    
    // Listen for real-time updates (always wire up, even if data was from bootstrap)
    mailSync.on(EventTypes.COLLEAGUE_UPDATED, handleColleagueUpdate)
    mailSync.on(EventTypes.COLLEAGUE_GROUP_UPDATED, handleGroupUpdate)
    
    // Subscribe to presence updates
    subscribeToPresence()
  }

  function hydrateFromBootstrap(teamData, userEmail) {
    if (!teamData) return
    if (Array.isArray(teamData.colleagues)) {
      colleagues.value = teamData.colleagues
    }
    if (Array.isArray(teamData.groups)) {
      groups.value = teamData.groups
    }
    if (teamData.is_admin !== undefined) {
      isAdmin.value = teamData.is_admin
    }
    if (teamData.my_permissions) {
      myPermissions.value = teamData.my_permissions
    }

    // Set currentColleague from the bootstrapped colleague list
    if (Array.isArray(teamData.colleagues)) {
      let me = null
      if (teamData.me_colleague_id) {
        me = teamData.colleagues.find(c => c.id === teamData.me_colleague_id)
      }
      if (!me && userEmail) {
        const email = userEmail.toLowerCase()
        me = teamData.colleagues.find(c => c.email?.toLowerCase() === email)
      }
      if (me) {
        currentColleague.value = me
        isAdmin.value = me.is_admin || isAdmin.value
      }
    }

    loaded.value = true
  }
  
  // Track cross-domain emails we've already subscribed to
  const crossDomainSubscribedEmails = ref(new Set())
  
  // Presence subscription and handlers
  function subscribeToPresence() {
    if (presenceSubscribed.value) return
    
    // Listen for presence events
    mailSync.on(EventTypes.PRESENCE_ONLINE, handlePresenceOnline)
    mailSync.on(EventTypes.PRESENCE_OFFLINE, handlePresenceOffline)
    mailSync.on(EventTypes.PRESENCE_STATUS_CHANGED, handlePresenceStatusChanged)
    mailSync.on(EventTypes.PRESENCE_BULK_UPDATE, handlePresenceBulkUpdate)
    
    // Subscribe when connected/reconnected
    const sendSubscription = () => {
      isDebugEnabled() && console.log('[Presence] Sending subscribe request to server')
      mailSync.subscribeToPresence()
      
      // Re-subscribe to cross-domain users on reconnect
      if (crossDomainSubscribedEmails.value.size > 0) {
        mailSync.subscribeToPresenceUsers(Array.from(crossDomainSubscribedEmails.value))
      }
    }
    
    // Listen for connection events to (re)subscribe
    mailSync.on(EventTypes.CONNECTED, sendSubscription)
    mailSync.on(EventTypes.RECONNECTED, sendSubscription)
    
    // If already connected, subscribe immediately
    if (mailSync.isConnected?.value) {
      sendSubscription()
    }
    
    presenceSubscribed.value = true
  }
  
  /**
   * Subscribe to presence updates for specific cross-domain users.
   * Called after chat conversations are loaded to track online status
   * of chat partners from different email domains.
   * @param {string[]} emails - Array of email addresses (cross-domain chat partners)
   */
  function subscribeToCrossDomainUsers(emails) {
    if (!emails || emails.length === 0) return
    
    // Filter to only new emails we haven't subscribed to yet
    const newEmails = emails.filter(e => !crossDomainSubscribedEmails.value.has(e.toLowerCase()))
    if (newEmails.length === 0) return
    
    // Track them
    for (const email of newEmails) {
      crossDomainSubscribedEmails.value.add(email.toLowerCase())
    }
    
    // Send to server
    if (mailSync.isConnected?.value) {
      isDebugEnabled() && console.log('[Presence] Subscribing to cross-domain users:', newEmails)
      mailSync.subscribeToPresenceUsers(newEmails)
      
      // Safety net: request a fresh presence update after a short delay
      // to handle any edge cases where the initial subscription response was missed
      setTimeout(() => {
        if (mailSync.isConnected?.value) {
          isDebugEnabled() && console.log('[Presence] Requesting delayed presence refresh for cross-domain users')
          mailSync.subscribeToPresenceUsers(Array.from(crossDomainSubscribedEmails.value))
        }
      }, 3000)
    } else {
      isDebugEnabled() && console.log('[Presence] Not connected, cross-domain subscriptions queued for reconnect:', newEmails.length)
    }
  }
  
  // NOTE: The mailSync socket dispatches handlers as handler(payload, fullEvent)
  // so the first parameter IS the payload directly, not the full event object.
  
  function handlePresenceOnline(payload) {
    isDebugEnabled() && console.log('[Presence] User online:', payload)
    const { userEmail, status, lastActivity, currentView } = payload || {}
    if (userEmail) {
      presence.value = {
        ...presence.value,
        [userEmail.toLowerCase()]: { status: status || 'active', lastActivity, currentView: currentView || null }
      }
      
      updateColleagueStatus(userEmail, status || 'active')
    }
  }
  
  function handlePresenceOffline(payload) {
    isDebugEnabled() && console.log('[Presence] User offline:', payload)
    const { userEmail } = payload || {}
    if (userEmail) {
      presence.value = {
        ...presence.value,
        [userEmail.toLowerCase()]: { status: 'offline', lastActivity: Date.now() }
      }
      
      updateColleagueStatus(userEmail, 'offline')
    }
  }
  
  function handlePresenceStatusChanged(payload) {
    isDebugEnabled() && console.log('[Presence] Status changed:', payload)
    const { userEmail, status, lastActivity, currentView } = payload || {}
    if (userEmail) {
      const existing = presence.value[userEmail?.toLowerCase()] || {}
      presence.value = {
        ...presence.value,
        [userEmail.toLowerCase()]: { status, lastActivity, currentView: currentView ?? existing.currentView ?? null }
      }
      
      updateColleagueStatus(userEmail, status)
    }
  }
  
  function handlePresenceBulkUpdate(payload) {
    const { presence: presenceData } = payload || {}
    if (presenceData && typeof presenceData === 'object') {
      // Normalize keys to lowercase
      const normalized = {}
      for (const [email, data] of Object.entries(presenceData)) {
        normalized[email.toLowerCase()] = data
      }
      
      const onlineUsers = Object.entries(normalized).filter(([, d]) => d.status === 'active' || d.status === 'away')
      isDebugEnabled() && console.log(`[Presence] Bulk update: ${Object.keys(normalized).length} users (${onlineUsers.length} online)`, 
        onlineUsers.map(([e, d]) => `${e}:${d.status}`).join(', '))
      
      presence.value = { ...presence.value, ...normalized }
      
      // Update colleague objects
      for (const [email, data] of Object.entries(normalized)) {
        updateColleagueStatus(email, data.status)
      }
    }
  }
  
  // Update colleague's status in the colleagues array
  function updateColleagueStatus(email, status) {
    const index = colleagues.value.findIndex(c => c.email.toLowerCase() === email.toLowerCase())
    if (index !== -1) {
      colleagues.value[index] = {
        ...colleagues.value[index],
        status
      }
    }
  }
  
  // Update own presence status
  function setMyStatus(status) {
    if (['active', 'away', 'do_not_disturb'].includes(status)) {
      mailSync.updatePresenceStatus(status)
    }
  }
  
  // Request a fresh presence update from the server
  function refreshPresence() {
    if (mailSync.isConnected?.value) {
      isDebugEnabled() && console.log('[Presence] Requesting fresh presence data')
      mailSync.requestPresenceRefresh()
    }
  }
  
  function handleColleagueUpdate(payload) {
    const { action, colleague_id, colleague } = payload || {}
    
    if (action === 'deleted') {
      colleagues.value = colleagues.value.filter(c => c.id !== colleague_id)
    } else if ((action === 'updated' || action === 'profile_updated' || action === 'avatar_changed' || action === 'status_changed') && colleague) {
      // Update inline for instant reactivity (status_text, display_name, presence, etc.)
      const idx = colleagues.value.findIndex(c => c.id === colleague_id)
      if (idx !== -1) {
        colleagues.value[idx] = { ...colleagues.value[idx], ...colleague }
      } else {
        // New colleague we don't have yet - add them
        colleagues.value.push(colleague)
      }
      // Also update currentColleague if this is our own profile
      if (currentColleague.value && currentColleague.value.id === colleague_id) {
        currentColleague.value = { ...currentColleague.value, ...colleague }
      }
    } else if (action === 'created' || action === 'groups_changed') {
      fetchColleagues(true)
    }
  }
  
  function handleGroupUpdate(payload) {
    const { action, group_id } = payload || {}
    
    fetchGroups(true)
    if (action === 'members_changed' || action === 'deleted') {
      fetchColleagues(true)
    }
  }
  
  // Cleanup
  function cleanup() {
    mailSync.off(EventTypes.COLLEAGUE_UPDATED, handleColleagueUpdate)
    mailSync.off(EventTypes.COLLEAGUE_GROUP_UPDATED, handleGroupUpdate)
  }
  
  // Helper to get colleague avatar URL
  function getAvatarUrl(colleague) {
    if (!colleague?.avatar_path) return null
    const filename = colleague.avatar_path.split('/').pop()
    const base = api.defaults.baseURL || '/api'
    return `${base}/colleagues/avatar/${filename}`
  }
  
  // Helper to get colleague initials
  function getInitials(colleague) {
    if (!colleague) return '??'
    const name = colleague.display_name || colleague.email.split('@')[0]
    const parts = name.split(/[\s._-]+/)
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase()
    }
    return name.substring(0, 2).toUpperCase()
  }
  
  // Helper to get consistent color for colleague
  function getColleagueColor(colleague) {
    const colors = [
      'bg-blue-500', 'bg-green-500', 'bg-purple-500', 'bg-amber-500',
      'bg-red-500', 'bg-teal-500', 'bg-pink-500', 'bg-indigo-500',
      'bg-cyan-500', 'bg-orange-500', 'bg-lime-500', 'bg-rose-500'
    ]
    const email = colleague?.email || ''
    const hash = email.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0)
    return colors[hash % colors.length]
  }
  
  return {
    // State
    colleagues,
    groups,
    currentColleague,
    isAdmin,
    loading,
    loaded,
    error,
    presence,
    myPermissions,
    
    // Computed
    colleaguesByGroup,
    colleagueById,
    colleagueByEmail,
    groupById,
    sortedColleagues,
    sortedGroups,
    onlineCount,
    
    // Actions
    fetchColleagues,
    fetchGroups,
    fetchMe,
    setMyAvatar,
    updateMyProfile,
    syncFromMailServer,
    addColleague,
    updateColleague,
    deleteColleague,
    createGroup,
    updateGroup,
    deleteGroup,
    addMembersToGroup,
    removeMemberFromGroup,
    setColleagueGroups,
    shareFolderWithGroup,
    shareBoardWithGroup,
    shareCalendarWithGroup,
    getGroupMembers,
    fetchMyPermissions,
    init,
    cleanup,
    
    // Presence
    getColleagueStatus,
    getColleagueCurrentView,
    setMyStatus,
    subscribeToPresence,
    subscribeToCrossDomainUsers,
    refreshPresence,
    
    // Helpers
    getAvatarUrl,
    getInitials,
    getColleagueColor,
    hydrateFromBootstrap,
  }
})

