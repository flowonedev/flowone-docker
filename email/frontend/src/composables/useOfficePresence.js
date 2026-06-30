/**
 * useOfficePresence - awareness-only Hocuspocus connection for the
 * OnlyOffice editor ("office-file-{id}" ephemeral rooms).
 *
 * The document content lives in the Document Server; this channel only
 * carries presence: who has the file open, their color, and their live
 * cursor/viewport (relayed to the in-editor presence plugin).
 */

import { ref, shallowRef, computed, onBeforeUnmount } from 'vue'
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'
import { officeApi, fetchGuestPresenceToken } from '@/services/officeApiService'
import { getCollabUserColor } from '@/utils/collabColors'

function getCollabWsUrl() {
  if (import.meta.env.VITE_COLLAB_WS_URL) {
    return import.meta.env.VITE_COLLAB_WS_URL
  }
  if (typeof window !== 'undefined' && window.location.hostname !== 'localhost') {
    return `wss://${window.location.hostname}:1234`
  }
  return 'ws://localhost:1234'
}

export function useOfficePresence() {
  const provider = shallowRef(null)
  const ydoc = shallowRef(null)
  const isConnected = ref(false)
  const self = ref(null) // { email, name, color }

  // Other participants, deduped by email: [{ email, name, color, cursor }]
  const others = ref([])

  const cursors = computed(() =>
    others.value.filter((u) => u.cursor && typeof u.cursor === 'object')
  )

  function rebuildOthers() {
    const awareness = provider.value?.awareness
    if (!awareness) {
      others.value = []
      return
    }
    const byEmail = new Map()
    awareness.getStates().forEach((state, clientId) => {
      if (clientId === awareness.clientID) return
      const user = state?.user
      if (!user?.email || user.email === self.value?.email) return
      const existing = byEmail.get(user.email)
      const entry = {
        email: user.email,
        name: user.name || user.email.split('@')[0],
        color: user.color || getCollabUserColor(user.email),
        cursor: state.cursor || null,
      }
      // Same user in two tabs: prefer the state that carries a cursor.
      if (!existing || (!existing.cursor && entry.cursor)) {
        byEmail.set(user.email, entry)
      }
    })
    others.value = Array.from(byEmail.values())
  }

  /**
   * Join a Hocuspocus awareness room under the given identity. Shared by the
   * authenticated and guest connect paths.
   */
  function startProvider({ room, token, identity }) {
    self.value = identity

    ydoc.value = new Y.Doc()
    provider.value = new HocuspocusProvider({
      url: getCollabWsUrl(),
      name: room,
      document: ydoc.value,
      token,
      connect: true,
      preserveConnection: false,
      onConnect: () => { isConnected.value = true },
      onDisconnect: () => { isConnected.value = false },
      onAuthenticationFailed: ({ reason }) => {
        console.warn('[OfficePresence] Auth failed:', reason)
      },
    })

    provider.value.awareness?.setLocalStateField('user', self.value)
    provider.value.awareness?.on('change', rebuildOthers)
    return true
  }

  /**
   * Fetch a presence token for the file and join its awareness room
   * (authenticated user path).
   */
  async function connect(fileId, { name, email } = {}) {
    disconnect()

    const res = await officeApi.getPresenceToken(fileId, { name })
    const data = res.data?.data
    if (!data?.token || !data?.room) {
      throw new Error('Presence token unavailable')
    }

    const userEmail = String(email || data.email || '').toLowerCase()
    return startProvider({
      room: data.room,
      token: data.token,
      identity: {
        email: userEmail,
        name: name || userEmail.split('@')[0],
        color: getCollabUserColor(userEmail),
      },
    })
  }

  /**
   * Join the awareness room as a share-link guest (no FlowOne account). The
   * opaque guest link token IS the auth; the backend mints a presence JWT
   * under a stable guest identity.
   */
  async function connectGuest(linkToken, { name } = {}) {
    disconnect()

    const data = await fetchGuestPresenceToken(linkToken, { name })
    if (!data?.token || !data?.room) {
      throw new Error('Presence token unavailable')
    }

    const guestEmail = String(
      data.email || `guest-${String(linkToken).slice(0, 12)}`
    ).toLowerCase()
    return startProvider({
      room: data.room,
      token: data.token,
      identity: {
        email: guestEmail,
        name: data.name || name || 'Guest',
        color: getCollabUserColor(guestEmail),
      },
    })
  }

  /**
   * Publish the local cursor/viewport (payload comes from the presence
   * plugin running inside the editor iframe).
   */
  function setCursor(cursor) {
    provider.value?.awareness?.setLocalStateField('cursor', cursor || null)
  }

  function disconnect() {
    if (provider.value) {
      try {
        provider.value.awareness?.off('change', rebuildOthers)
        provider.value.destroy()
      } catch (e) { /* already gone */ }
      provider.value = null
    }
    if (ydoc.value) {
      try { ydoc.value.destroy() } catch (e) { /* already gone */ }
      ydoc.value = null
    }
    isConnected.value = false
    others.value = []
  }

  onBeforeUnmount(disconnect)

  return {
    self,
    others,
    cursors,
    isConnected,
    connect,
    connectGuest,
    disconnect,
    setCursor,
  }
}
