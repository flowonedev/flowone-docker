/**
 * useCollabAwareness Composable
 * 
 * Manages presence and cursor awareness for collaboration.
 * Shows who is currently viewing/editing the document.
 */

import { ref, computed, watch, onUnmounted } from 'vue'
import { useCollabStore } from '../stores/collabStore.js'

/**
 * Track and display collaborator presence
 * 
 * @param {Object} provider - The Hocuspocus provider from useCollabProvider
 */
export function useCollabAwareness(provider) {
  const collabStore = useCollabStore()

  // ============================================================
  // STATE
  // ============================================================

  // All connected users (including self)
  const users = ref([])
  
  // Other users (excluding self)
  const otherUsers = computed(() => {
    return users.value.filter(u => !u.isSelf)
  })

  // Current user info
  const currentUser = computed(() => {
    return users.value.find(u => u.isSelf) || null
  })

  // User cursors for rendering
  const cursors = ref([])

  // ============================================================
  // AWARENESS TRACKING
  // ============================================================

  let cleanupListener = null

  /**
   * Start tracking awareness changes
   */
  function startTracking() {
    const awareness = provider.value?.awareness
    if (!awareness) {
      console.warn('[CollabAwareness] No awareness instance available')
      return
    }

    // Initial state
    updateUsersFromAwareness(awareness)

    // Listen for changes
    const onChange = () => {
      updateUsersFromAwareness(awareness)
    }

    awareness.on('change', onChange)

    // Cleanup function
    cleanupListener = () => {
      awareness.off('change', onChange)
    }
  }

  /**
   * Stop tracking awareness changes
   */
  function stopTracking() {
    if (cleanupListener) {
      cleanupListener()
      cleanupListener = null
    }
    users.value = []
    cursors.value = []
  }

  /**
   * Update users array from awareness states
   */
  function updateUsersFromAwareness(awareness) {
    const states = awareness.getStates()
    const clientId = awareness.clientID
    const newUsers = []
    const newCursors = []

    states.forEach((state, id) => {
      if (!state.user) return

      const isSelf = id === clientId
      const user = {
        clientId: id,
        email: state.user.email,
        name: state.user.name,
        color: state.user.color,
        isSelf,
        cursor: state.cursor || null,
      }

      newUsers.push(user)

      // Collect cursors for non-self users
      if (!isSelf && state.cursor) {
        newCursors.push({
          clientId: id,
          user: state.user,
          ...state.cursor,
        })
      }
    })

    users.value = newUsers
    cursors.value = newCursors

    // Update store
    collabStore.setConnectedUsers(newUsers)
  }

  // ============================================================
  // CURSOR HELPERS
  // ============================================================

  /**
   * Get cursor style for a user (for rendering cursor elements)
   */
  function getCursorStyle(user) {
    return {
      backgroundColor: user.color,
      color: getContrastColor(user.color),
    }
  }

  /**
   * Get contrasting text color (black or white) for a background
   */
  function getContrastColor(hexcolor) {
    const r = parseInt(hexcolor.slice(1, 3), 16)
    const g = parseInt(hexcolor.slice(3, 5), 16)
    const b = parseInt(hexcolor.slice(5, 7), 16)
    const yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000
    return yiq >= 128 ? '#000000' : '#ffffff'
  }

  /**
   * Get user initials for avatar display
   */
  function getUserInitials(user) {
    if (!user.name) return '?'
    const parts = user.name.split(' ')
    if (parts.length >= 2) {
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    }
    return user.name.substring(0, 2).toUpperCase()
  }

  /**
   * Format cursor label (username or email prefix)
   */
  function getCursorLabel(user) {
    return user.name || user.email?.split('@')[0] || 'Anonymous'
  }

  // ============================================================
  // WATCH PROVIDER
  // ============================================================

  // Auto-start tracking when provider connects
  watch(
    () => provider.value?.awareness,
    (awareness) => {
      if (awareness) {
        startTracking()
      } else {
        stopTracking()
      }
    },
    { immediate: true }
  )

  // ============================================================
  // CLEANUP
  // ============================================================

  onUnmounted(() => {
    stopTracking()
  })

  // ============================================================
  // RETURN
  // ============================================================

  return {
    // State
    users,
    otherUsers,
    currentUser,
    cursors,

    // Actions
    startTracking,
    stopTracking,

    // Helpers
    getCursorStyle,
    getContrastColor,
    getUserInitials,
    getCursorLabel,
  }
}

