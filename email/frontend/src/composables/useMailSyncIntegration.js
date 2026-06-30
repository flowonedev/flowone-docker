/**
 * Mail Sync Integration Composable
 * 
 * Connects the WebSocket client to the Pinia stores for real-time
 * email synchronization. This replaces polling-based sync with
 * event-driven updates.
 */

import { watch, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMailboxStore } from '@/stores/mailbox'
import { useConversationsStore } from '@/stores/conversations'
import { useAuthStore } from '@/stores/auth'
import { useAccountsStore } from '@/stores/accounts'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { usePerspectiveStore } from '@/stores/perspective'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useLabelsStore } from '@/stores/labels'
import { useMailSync, EventTypes, ConnectionState } from '@/services/mailSyncSocket'
import api from '@/services/api'
import browserNotifications from '@/services/browserNotifications'
import notificationSounds from '@/services/notificationSounds'
import {
  patchMessage as patchOfflineMessage,
  removeMessage as removeOfflineMessage,
  addMessage as addOfflineMessage,
  setActiveUserEmail as setOfflineActiveUserEmail,
} from '@/services/offlineMailbox'

import { isDebugEnabled } from '@/utils/debug'

// Reconciliation interval (safety net for missed WebSocket events)
const RECONCILIATION_INTERVAL = 5 * 60 * 1000 // 5 minutes

const DEBOUNCE_MS = 500
const ENTITY_DEBOUNCE_MS = 1000
const VISIBILITY_REFRESH_DEBOUNCE_MS = 1000

function isInboxLikeFolder(folder) {
  if (!folder || typeof folder !== 'string') return false
  const f = folder.toLowerCase()
  return f === 'inbox' || f.endsWith('/inbox') || f.split('/').pop() === 'inbox'
}

export function useMailSyncIntegration() {
  const route = useRoute()
  const router = useRouter()
  const mailbox = useMailboxStore()
  const conversations = useConversationsStore()
  const auth = useAuthStore()
  const accountsStore = useAccountsStore()

  // Push the current account email into the offline cache module so
  // write-through calls below key by the right user. Cheap & idempotent.
  function syncOfflineActiveUserEmail() {
    try {
      const email = accountsStore?.activeAccount?.email
      if (email) setOfflineActiveUserEmail(email)
    } catch (_e) {
      // Accounts store not ready yet; write-through silently no-ops.
    }
  }
  const theme = useThemeStore()
  const layout = useLayoutStore()
  const perspectiveStore = usePerspectiveStore()
  const labelsStore = useLabelsStore()
  const boards = useBoardsStore()
  const calendar = useCalendarStore()
  const todos = useTodosStore()
  const { 
    connectionState, 
    isConnected, 
    lastError,
    connect, 
    disconnect, 
    on, 
    off,
    subscribeToFolder,
  } = useMailSync()

  // Instance-scoped debounce timers (no cross-instance collisions).
  // Per-folder timers prevent fetches for different folders from cancelling
  // each other. The old shared `fetchMessagesDebounceTimer` was a major
  // contributor to the "jumps back to Inbox" race: a queued incremental
  // fetch for folder A would be cancelled (or run) at the wrong moment
  // when a fetch for folder B raced through the same timer slot.
  let fetchFoldersDebounceTimer = null
  const fullFetchTimers = new Map()         // per-folder timers for debouncedFetchMessages
  const incrementalFetchTimers = new Map()  // per-folder timers for debouncedIncrementalFetch
  let genericFullFetchTimer = null          // fallback timer when no folder passed
  let genericIncrementalTimer = null
  let boardUpdateDebounceTimer = null
  let boardEntityUpdateDebounceTimer = null
  let pendingBoardEntityRefresh = false
  let calendarUpdateDebounceTimer = null
  let todoUpdateDebounceTimer = null
  let visibilityRefreshTimer = null
  let emailRuleDebounceTimer = null
  let pendingRulePayloads = []

  // "New mail didn't land yet" reconcile trackers.
  //
  // The IMAP IDLE daemon detects new mail and pushes MESSAGE_NEW /
  // FOLDER_COUNTS instantly (straight off the live IMAP connection), but the
  // /delta and /messages endpoints both read from the DB mirror once a folder
  // is marked `synced`. The daemon does NOT write new mail into that mirror
  // (unlike deletions, which it enqueues for immediate apply), so the very
  // first incremental fetch fired ~500ms after the event can race AHEAD of the
  // mirror and come back with zero new messages -- the unread badge updates
  // (it comes from the event payload), but the row never appears until the
  // periodic reconciliation, a tab refocus, or a manual refresh.
  //
  // These trackers re-run the existing incremental fetch with backoff until the
  // expected UID (carried by the push event) actually lands in the store, then
  // stop. This is purely additive: every retry goes through the same
  // fetchMessagesSince path (and all its UIDVALIDITY / pending-removal guards)
  // that a manual refresh already uses, and it self-limits.
  const newMailReconcileTimers = new Map()  // folder -> timeoutId
  const newMailReconcileState = new Map()   // folder -> { expectedUid, attempt }
  // Backoff schedule (ms). Capped so a permanently-stuck mirror can never spin
  // forever; the 5-minute reconciliation remains the long-stop safety net.
  const NEW_MAIL_RECONCILE_DELAYS = [1200, 2000, 3000, 4500, 6000]

  // Track when the last WS event arrived (for smart reconciliation)
  let lastEventTimestamp = 0
  function touchEventTimestamp() { lastEventTimestamp = Date.now() }

  function debouncedFetchFolders(mb) {
    if (fetchFoldersDebounceTimer) clearTimeout(fetchFoldersDebounceTimer)
    fetchFoldersDebounceTimer = setTimeout(() => {
      mb.fetchFolders(true)
      fetchFoldersDebounceTimer = null
    }, DEBOUNCE_MS)
  }

  // Every fetchMessages / fetchMessagesSince invocation that originates
  // from a WebSocket sync event (FOLDER_COUNTS, MESSAGE_NEW, etc.) MUST
  // pass { suppressIfLocallyConsistent: true } so the OAuth view-
  // stability guard inside mailbox.js can suppress destructive page-1
  // replaces during Gmail-IMAP-replica flap windows. User-initiated
  // calls (refresh button, folder switch, pagination) call
  // mb.fetchMessages directly without this option and always do a full
  // replace, which is the correct behavior for explicit user intent.
  const SYNC_OPTIONS = Object.freeze({ suppressIfLocallyConsistent: true })

  function debouncedFetchMessages(mb, folder = null) {
    if (folder) {
      const t = fullFetchTimers.get(folder)
      if (t) clearTimeout(t)
      fullFetchTimers.set(folder, setTimeout(() => {
        fullFetchTimers.delete(folder)
        if (mb.currentFolder !== folder) return
        mb.fetchMessages(folder, 1, SYNC_OPTIONS)
      }, DEBOUNCE_MS))
      return
    }

    if (genericFullFetchTimer) clearTimeout(genericFullFetchTimer)
    genericFullFetchTimer = setTimeout(() => {
      genericFullFetchTimer = null
      mb.fetchMessages(null, 1, SYNC_OPTIONS)
    }, DEBOUNCE_MS)
  }

  /**
   * Incremental message fetch (debounced).
   * Uses the CONDSTORE-backed /delta endpoint (via mb.fetchMessagesSince) to
   * fetch new messages + flag changes + deleted UIDs in a single round trip.
   * Falls back to full fetch only when no cached data exists.
   *
   * The setTimeout callback guards against "user navigated away during the
   * debounce window" so a stale fetch for folder A cannot synchronously set
   * currentFolder back to A when the user has already switched to folder B.
   * This is the root fix for the "jumps back to Inbox" bug.
   */
  function debouncedIncrementalFetch(mb, folder = null) {
    if (folder) {
      const t = incrementalFetchTimers.get(folder)
      if (t) clearTimeout(t)
      incrementalFetchTimers.set(folder, setTimeout(async () => {
        incrementalFetchTimers.delete(folder)
        if (mb.currentFolder !== folder) return

        const highestUid = mb.getHighestUid(folder)
        if (highestUid > 0) {
          const result = await mb.fetchMessagesSince(folder, highestUid)
          isDebugEnabled() && console.log(`[MailSync] Incremental fetch ${folder}: ${result.count} new messages (since UID ${highestUid})`)
        } else {
          mb.fetchMessages(folder, 1, SYNC_OPTIONS)
        }
      }, DEBOUNCE_MS))
      return
    }

    if (genericIncrementalTimer) clearTimeout(genericIncrementalTimer)
    genericIncrementalTimer = setTimeout(async () => {
      genericIncrementalTimer = null
      const target = mb.currentFolder
      if (!target) return

      const highestUid = mb.getHighestUid(target)
      if (highestUid > 0) {
        const result = await mb.fetchMessagesSince(target, highestUid)
        isDebugEnabled() && console.log(`[MailSync] Incremental fetch ${target}: ${result.count} new messages (since UID ${highestUid})`)
      } else {
        mb.fetchMessages(target, 1, SYNC_OPTIONS)
      }
    }, DEBOUNCE_MS)
  }

  /**
   * Clear any pending reconcile state/timer for a folder.
   */
  function clearNewMailReconcile(folder) {
    const t = newMailReconcileTimers.get(folder)
    if (t) clearTimeout(t)
    newMailReconcileTimers.delete(folder)
    newMailReconcileState.delete(folder)
  }

  /**
   * Ensure a just-arrived message (identified by the push event's UID) actually
   * becomes visible, retrying the incremental fetch with backoff to ride out
   * the DB-mirror lag described above. No-op once the UID is already present.
   *
   * @param {object} mb - mailbox store
   * @param {string} folder - the folder the new mail landed in (must be current)
   * @param {number} expectedUid - highest UID we expect to see after this event
   */
  function scheduleNewMailReconcile(mb, folder, expectedUid) {
    const uid = Number(expectedUid)
    if (!folder || !Number.isFinite(uid) || uid <= 0) return
    // Virtual folders refresh through their own (non-/delta) paths.
    if (folder === 'ALL_MAIL' || folder === 'SEARCH_RESULTS' || folder === 'SCHEDULED') return
    // Already have it (the immediate incremental fetch may have won the race).
    if (mb.getHighestUid(folder) >= uid) return

    const existing = newMailReconcileState.get(folder)
    if (existing) {
      // Keep waiting on the highest target UID, but don't restart the backoff
      // from zero on every event in a burst.
      if (uid > existing.expectedUid) existing.expectedUid = uid
      return
    }

    newMailReconcileState.set(folder, { expectedUid: uid, attempt: 0 })
    armNewMailReconcile(mb, folder)
  }

  function armNewMailReconcile(mb, folder) {
    const state = newMailReconcileState.get(folder)
    if (!state) return
    if (state.attempt >= NEW_MAIL_RECONCILE_DELAYS.length) {
      // Out of attempts. The 5-minute reconciliation / tab-focus refresh is the
      // long-stop; give up quietly so we never spin against a stuck mirror.
      clearNewMailReconcile(folder)
      return
    }
    const delay = NEW_MAIL_RECONCILE_DELAYS[state.attempt]
    state.attempt += 1
    const t = setTimeout(() => runNewMailReconcile(mb, folder), delay)
    newMailReconcileTimers.set(folder, t)
  }

  async function runNewMailReconcile(mb, folder) {
    newMailReconcileTimers.delete(folder)
    const state = newMailReconcileState.get(folder)
    if (!state) return

    // User navigated away, or the UID already landed via another path.
    if (mb.currentFolder !== folder || mb.getHighestUid(folder) >= state.expectedUid) {
      clearNewMailReconcile(folder)
      return
    }

    const highestUid = mb.getHighestUid(folder)
    try {
      if (highestUid > 0) {
        const result = await mb.fetchMessagesSince(folder, highestUid)
        isDebugEnabled() && console.log(
          `[MailSync] New-mail reconcile ${folder} (attempt ${state.attempt}/${NEW_MAIL_RECONCILE_DELAYS.length}): ` +
          `${result.count} new (waiting for UID ${state.expectedUid}, have ${mb.getHighestUid(folder)})`
        )
      } else {
        await mb.fetchMessages(folder, 1, SYNC_OPTIONS)
      }
    } catch (e) {
      isDebugEnabled() && console.warn('[MailSync] New-mail reconcile fetch failed:', e)
    }

    // Re-check after the fetch resolves; schedule the next attempt if needed.
    if (mb.currentFolder !== folder || mb.getHighestUid(folder) >= state.expectedUid) {
      clearNewMailReconcile(folder)
      return
    }
    armNewMailReconcile(mb, folder)
  }

  // Track cleanup functions
  const cleanupFunctions = ref([])
  
  // Reconciliation timer
  let reconciliationTimer = null

  /**
   * Initialize WebSocket connection and event handlers
   */
  async function init() {
    // Get auth token
    const token = auth.token
    if (!token) {
      isDebugEnabled() && console.log('[MailSyncIntegration] No auth token, skipping WebSocket connection')
      return
    }

    // Connect to WebSocket server
    await connect(token)

    // Register event handlers
    registerEventHandlers()

    // Start reconciliation timer (safety net)
    startReconciliation()

    // Refresh data when tab becomes visible (catches missed events from background throttling)
    document.addEventListener('visibilitychange', handleVisibilityChange)
  }

  /**
   * Register handlers for all event types
   */
  function registerEventHandlers() {
    const safePush = (unsub) => { if (unsub) cleanupFunctions.value.push(unsub) }

    safePush(on(EventTypes.MESSAGE_NEW, handleNewMessage))
    safePush(on(EventTypes.MESSAGE_DELETED, handleMessageDeleted))
    safePush(on(EventTypes.MESSAGE_MOVED, handleMessageMoved))
    safePush(on(EventTypes.FLAGS_CHANGED, handleFlagsChanged))
    safePush(on(EventTypes.FOLDER_COUNTS, handleFolderCounts))
    safePush(on(EventTypes.CONVERSATION_UPDATED, handleConversationUpdated))
    safePush(on(EventTypes.FOLDER_CHANGED, handleFolderChanged))
    safePush(on(EventTypes.SETTINGS_CHANGED, handleSettingsChanged))
    safePush(on(EventTypes.PIN_CHANGED, handlePinChanged))
    safePush(on(EventTypes.LABELS_CHANGED, handleLabelsChanged))
    safePush(on(EventTypes.CONNECTED, handleConnected))
    safePush(on(EventTypes.RECONNECTED, handleReconnected))
    safePush(on(EventTypes.SYNC_GAP_DETECTED, handleSyncGapDetected))
    safePush(on(EventTypes.BOARD_UPDATED, handleBoardUpdated))
    safePush(on(EventTypes.LIST_UPDATED, handleListUpdated))
    safePush(on(EventTypes.CARD_UPDATED, handleCardUpdated))
    safePush(on(EventTypes.CALENDAR_UPDATED, handleCalendarUpdated))
    safePush(on(EventTypes.CHECKLIST_UPDATED, handleChecklistUpdated))
    safePush(on(EventTypes.TODO_UPDATED, handleTodoUpdated))
  }

  /**
   * Handle board updated event - view-aware, debounced
   * Only fetches if user is actively on the boards view.
   * BoardsView.onMounted will fetch fresh data on next visit.
   */
  function handleBoardUpdated(payload) {
    isDebugEnabled() && console.log('[MailSync] Board updated:', payload)
    
    // Debounce: batch rapid board events into a single fetch
    if (boardUpdateDebounceTimer) clearTimeout(boardUpdateDebounceTimer)
    boardUpdateDebounceTimer = setTimeout(() => {
      // Check route INSIDE debounce callback (route may have changed since event arrived)
      const isOnBoardsView = route.path.startsWith('/boards')
      if (!isOnBoardsView) {
        isDebugEnabled() && console.log('[MailSync] Board update ignored - not on boards view')
        return
      }
      if (boards.fetchBoards) boards.fetchBoards()
      boardUpdateDebounceTimer = null
    }, ENTITY_DEBOUNCE_MS)
  }

  /**
   * Granular, debounced, route-gated refresh of the currently-OPEN board.
   *
   * LIST_UPDATED / CARD_UPDATED / CHECKLIST_UPDATED were previously no-ops to
   * avoid the flicker of a full board-list refetch. The cost was that a change
   * made on another device (or by a collaborator) never appeared until a
   * manual refresh. We instead refetch ONLY the open board (boards.fetchBoard
   * is a single-board GET) and only when:
   *   - the user is on the /boards view, and
   *   - the event targets the open board (board_id match) or carries no
   *     board_id (CHECKLIST_UPDATED only has card_id; its card lives in the
   *     open board in the common case).
   * Any matching event inside the debounce window flips the refresh flag so a
   * burst of mixed sub-events collapses into one quiet refetch.
   */
  function noteBoardEntityEvent(payload) {
    const open = boards.currentBoard
    const eventBoardId = payload?.board_id
    if (open?.id && (eventBoardId == null || Number(eventBoardId) === Number(open.id))) {
      pendingBoardEntityRefresh = true
    }
    if (boardEntityUpdateDebounceTimer) clearTimeout(boardEntityUpdateDebounceTimer)
    boardEntityUpdateDebounceTimer = setTimeout(() => {
      boardEntityUpdateDebounceTimer = null
      const needed = pendingBoardEntityRefresh
      pendingBoardEntityRefresh = false
      if (!needed) return
      if (!route.path.startsWith('/boards')) return
      const cur = boards.currentBoard
      if (cur?.id && boards.fetchBoard) boards.fetchBoard(cur.id, { silent: true })
    }, ENTITY_DEBOUNCE_MS)
  }

  /**
   * Handle list updated event - granular open-board refresh (debounced).
   */
  function handleListUpdated(payload) {
    isDebugEnabled() && console.log('[MailSync] List updated:', payload)
    noteBoardEntityEvent(payload)
  }

  /**
   * Handle card updated event - granular open-board refresh (debounced).
   * CardModal keeps its own subscription for the open-card detail view; this
   * keeps the board columns themselves in sync cross-device.
   */
  function handleCardUpdated(payload) {
    isDebugEnabled() && console.log('[MailSync] Card updated:', payload)
    noteBoardEntityEvent(payload)
  }

  /**
   * Handle calendar updated event - view-aware, debounced.
   * On CalendarView: full refresh so the user sees changes immediately.
   * Off CalendarView: invalidate cache + refresh reminders. The next
   * calendar visit will fetch fresh data automatically.
   */
  function handleCalendarUpdated(payload) {
    isDebugEnabled() && console.log('[MailSync] Calendar updated:', payload)
    
    if (calendarUpdateDebounceTimer) clearTimeout(calendarUpdateDebounceTimer)
    calendarUpdateDebounceTimer = setTimeout(() => {
      const isOnCalendarView = route.path.startsWith('/calendar')
      if (isOnCalendarView) {
        if (calendar.fetchCalendars) calendar.fetchCalendars({ force: true })
        if (calendar.fetchEvents) calendar.fetchEvents(null, null, { force: true })
      } else {
        if (calendar.invalidateCalendarsCache) calendar.invalidateCalendarsCache()
        if (calendar.invalidateEventsCache) calendar.invalidateEventsCache()
      }
      // Always keep today's reminders fresh (lightweight single-day query)
      if (calendar.fetchTodayEventsForReminders) calendar.fetchTodayEventsForReminders()
      calendarUpdateDebounceTimer = null
    }, ENTITY_DEBOUNCE_MS)
  }

  /**
   * Handle checklist item updated event
   * Note: CardModal has its own subscription that handles reloading when open
   * We intentionally do nothing here to avoid triggering board reloads
   */
  function handleChecklistUpdated(payload) {
    // CardModal handles the open-card detail view via its own subscription;
    // this keeps the board's per-card checklist progress badge in sync
    // cross-device. CHECKLIST_UPDATED carries only card_id (no board_id), so
    // noteBoardEntityEvent refreshes the open board when on /boards.
    isDebugEnabled() && console.log('[MailSync] Checklist updated:', payload?.card_id)
    noteBoardEntityEvent(payload)
  }

  /**
   * Handle todo updated event - view-aware, debounced
   * Only fetches if the todo panel is open. TodoPanel watches panelOpen
   * and fetches on open, so data will be fresh on next panel open.
   */
  function handleTodoUpdated(payload) {
    isDebugEnabled() && console.log('[MailSync] Todo updated:', payload)
    
    // Debounce: batch rapid todo events
    if (todoUpdateDebounceTimer) clearTimeout(todoUpdateDebounceTimer)
    todoUpdateDebounceTimer = setTimeout(() => {
      // Check panel state INSIDE debounce callback (may have changed since event arrived)
      if (!todos.panelOpen) {
        isDebugEnabled() && console.log('[MailSync] Todo update ignored - panel closed')
        return
      }
      if (todos.fetchTodos) todos.fetchTodos()
      todoUpdateDebounceTimer = null
    }, ENTITY_DEBOUNCE_MS)
  }

  const VIRTUAL_FOLDERS = ['ALL_MAIL', 'SEARCH_RESULTS']

  function isVirtualFolder(f) {
    return VIRTUAL_FOLDERS.includes(f)
  }

  /**
   * Remove a message from the canonical store AND from the current virtual
   * folder view (ALL_MAIL / SEARCH_RESULTS) so the UI updates instantly.
   */
  function removeFromVirtualView(uid, realFolder) {
    mailbox.removeMessageFromList(uid, realFolder)

    const cur = mailbox.currentFolder
    if (!isVirtualFolder(cur)) return

    const viewKeys = mailbox.folderViews.get(cur)
    if (!viewKeys) return
    const key = `${realFolder}:${Number(uid)}`
    const idx = viewKeys.indexOf(key)
    if (idx !== -1) viewKeys.splice(idx, 1)
  }

  /**
   * Handle new message event
   * Uses debounced refresh to avoid IMAP overload on rapid events
   */
  async function handleNewMessage(payload) {
    isDebugEnabled() && console.log('[MailSync] New message received:', payload)
    touchEventTimestamp()
    
    const { folder } = payload
    
    // Refresh folder counts (lightweight)
    debouncedFetchFolders(mailbox)
    
    // If this is the current folder, do an incremental fetch (only new messages)
    if (folder === mailbox.currentFolder) {
      debouncedIncrementalFetch(mailbox, folder)
      // The /delta endpoint reads the DB mirror, which the daemon hasn't
      // written this message into yet. If the first fetch above races ahead of
      // the mirror, retry with backoff until the pushed UID actually appears.
      const newUid = payload.uid != null ? Number(payload.uid) : 0
      if (newUid > 0) scheduleNewMailReconcile(mailbox, folder, newUid)
    } else if (isVirtualFolder(mailbox.currentFolder)) {
      debouncedFetchMessages(mailbox)
    }

    // Desktop notification for new mail (INBOX-like folders only)
    if (isInboxLikeFolder(folder)) {
      try {
        const fromAddr = (payload.from || '').trim().toLowerCase()
        const selfEmail = (auth.userEmail || '').trim().toLowerCase()
        const isFromSelf = !!(fromAddr && selfEmail && fromAddr === selfEmail)
        const userAlreadyViewingFolder = document.hasFocus() && mailbox.currentFolder === folder

        // Outlook-style new-mail chime. Plays for ANY new inbox mail (even when
        // the inbox is focused / from yourself, matching Outlook), gated only by
        // the "new email" + sound prefs. Independent of OS notification
        // permission, and not blocked by the popup-suppression guards below.
        if (localStorage.getItem('notification_new_email') !== 'false') {
          notificationSounds.playEmailSound()
        }

        if (!isFromSelf && !userAlreadyViewingFolder) {
          const uid = payload.uid != null ? Number(payload.uid) : null
          if (uid && uid > 0) {
            browserNotifications.showNewEmail(
              {
                uid,
                from_name: payload.from_name,
                from_email: payload.from,
                subject: payload.subject,
              },
              {
                onClick: () => {
                  router.push({
                    path: '/inbox',
                    query: {
                      folder: encodeURIComponent(folder),
                      message: String(uid),
                    },
                  })
                },
              }
            )
          }
        }
      } catch (e) {
        isDebugEnabled() && console.warn('[MailSync] New email notification skipped:', e)
      }
    }

    // Debounced Board Pro email auto-link rule evaluation
    pendingRulePayloads.push({
      uid: payload.uid,
      folder: payload.folder,
      subject: payload.subject || '',
      from: payload.from || '',
      from_name: payload.from_name || '',
      date: payload.date || null,
      preview: payload.preview || '',
    })
    if (emailRuleDebounceTimer) clearTimeout(emailRuleDebounceTimer)
    emailRuleDebounceTimer = setTimeout(async () => {
      const batch = pendingRulePayloads.splice(0)
      emailRuleDebounceTimer = null
      for (const item of batch) {
        try {
          await api.post('/board-pro/evaluate-email-rules', item)
        } catch (e) {
          isDebugEnabled() && console.warn('[MailSync] Email rule evaluation failed:', e)
        }
      }
    }, DEBOUNCE_MS)
  }

  /**
   * Handle message deleted event
   */
  function handleMessageDeleted(payload) {
    isDebugEnabled() && console.log('[MailSync] Message deleted:', payload)
    touchEventTimestamp()
    
    const { folder, uid } = payload
    
    if (uid) {
      if (folder === mailbox.currentFolder) {
        mailbox.removeMessageFromList(uid, folder)
        // Mark this removal as locally-applied so the FOLDER_COUNTS
        // event that follows doesn't trigger a redundant page-1 refetch
        // that would destroy scroll position. See handleFolderCounts.
        mailbox.markLocalRemoval && mailbox.markLocalRemoval(folder, 1)
      } else if (isVirtualFolder(mailbox.currentFolder)) {
        removeFromVirtualView(uid, folder)
        mailbox.markLocalRemoval && mailbox.markLocalRemoval(folder, 1)
      }
      conversations.removeMessageLocally(folder, uid)
      // IndexedDB write-through: cross-device delete must not ghost
      // back in on the next page load.
      if (folder && Number.isFinite(Number(uid))) {
        syncOfflineActiveUserEmail()
        removeOfflineMessage(folder, Number(uid)).catch(() => {})
      }
    }
    
    // Refresh folder counts from IMAP
    debouncedFetchFolders(mailbox)
  }

  /**
   * Handle message moved event
   */
  function handleMessageMoved(payload) {
    isDebugEnabled() && console.log('[MailSync] Message moved:', payload)
    touchEventTimestamp()
    
    const { sourceFolder, targetFolder, oldUid } = payload
    
    if (oldUid) {
      if (sourceFolder === mailbox.currentFolder) {
        mailbox.removeMessageFromList(oldUid, sourceFolder)
        // Mark this removal as locally-applied so the FOLDER_COUNTS
        // event that follows doesn't trigger a redundant page-1 refetch
        // that would destroy scroll position. See handleFolderCounts.
        mailbox.markLocalRemoval && mailbox.markLocalRemoval(sourceFolder, 1)
      } else if (isVirtualFolder(mailbox.currentFolder)) {
        removeFromVirtualView(oldUid, sourceFolder)
        mailbox.markLocalRemoval && mailbox.markLocalRemoval(sourceFolder, 1)
      }
      conversations.removeMessageLocally(sourceFolder, oldUid)
      // IndexedDB: remove from source folder. The target-folder entry
      // will populate when the incremental fetch below writes through
      // via upsertMessages.
      if (sourceFolder && Number.isFinite(Number(oldUid))) {
        syncOfflineActiveUserEmail()
        removeOfflineMessage(sourceFolder, Number(oldUid)).catch(() => {})
      }
    }
    
    // If viewing the target folder, incrementally fetch the moved message
    if (targetFolder === mailbox.currentFolder) {
      debouncedIncrementalFetch(mailbox, targetFolder)
    } else if (isVirtualFolder(mailbox.currentFolder)) {
      debouncedFetchMessages(mailbox)
    }
    
    // Refresh folder counts (lightweight)
    debouncedFetchFolders(mailbox)
  }

  /**
   * Handle flags changed event
   * Backend sends: { folder, uid, flags: { flag, value, imapFlags } }
   * OR from IMAP IDLE: { folder, uid, flags: ['\\Seen', '\\Flagged'] }
   */
  function handleFlagsChanged(payload) {
    isDebugEnabled() && console.log('[MailSyncIntegration] Flags changed:', payload)
    touchEventTimestamp()
    
    const { folder, uid, flags } = payload
    
    // Extract flag info - handle both formats
    let flagName, flagValue
    if (flags && typeof flags === 'object' && !Array.isArray(flags)) {
      flagName = flags.flag
      flagValue = flags.value
    }
    
    const isRelevant = uid && (folder === mailbox.currentFolder || isVirtualFolder(mailbox.currentFolder))
    
    if (isRelevant) {
      // Phase 2 DB-as-truth: optimistic flag protection (pendingFlags) is
      // no longer needed -- server commits are DB-transactional and the
      // FLAGS_CHANGED events published from the outbox pump arrive with
      // confirmed: true after IMAP acknowledges, so we can apply the
      // server payload directly without worrying about racing an
      // optimistic update.
      let updates = {}
      
      if (flagName !== undefined && flagValue !== undefined) {
        updates[flagName.toLowerCase()] = flagValue
      } else if (Array.isArray(flags)) {
        // The array form is the FULL authoritative flag set from IMAP IDLE,
        // so absence of a flag means it was cleared. Setting seen only when
        // \Seen is present would silently drop external mark-UNREAD (the
        // message would stay read on this device). Mirror presence/absence.
        updates.seen = flags.includes('\\Seen')
        updates.flagged = flags.includes('\\Flagged')
      }
      
      const message = mailbox.findMessageByUid(uid, folder)
      if (message) {
        Object.assign(message, updates)
        mailbox.notifyMessagesChanged()
      } else {
        isDebugEnabled() && console.log('[MailSyncIntegration] FLAGS_CHANGED for unknown UID, refreshing messages', { folder, uid })
        debouncedFetchMessages(mailbox)
      }

      // IndexedDB write-through so cross-device flag flips persist
      // through a page reload even if the message is not in RAM.
      if (folder && Number.isFinite(Number(uid)) && Object.keys(updates).length) {
        syncOfflineActiveUserEmail()
        patchOfflineMessage(folder, Number(uid), updates).catch(() => {})
      }
    }
    
    // Refresh folder counts from IMAP (source of truth). Debounced so a
    // batch of FLAGS_CHANGED events collapses to one folder-count refresh.
    debouncedFetchFolders(mailbox)
  }

  /**
   * Handle pin changed event
   * Backend sends: { folder, uid, pinned }
   */
  function handlePinChanged(payload) {
    isDebugEnabled() && console.log('[MailSyncIntegration] Pin changed:', payload)

    const { folder, uid, pinned } = payload
    if (!folder || !uid) return

    if (pinned) {
      const alreadyPinned = mailbox.pinnedEmails.some(
        p => p.folder === folder && p.uid === uid
      )
      if (!alreadyPinned) {
        const msg = mailbox.findMessageByUid(uid, folder)
        mailbox.pinnedEmails.unshift({
          folder,
          uid,
          message_id: msg?.message_id || null,
          subject: msg?.subject || '',
          pinned_at: new Date().toISOString(),
        })
      }
    } else {
      mailbox.pinnedEmails = mailbox.pinnedEmails.filter(
        p => !(p.folder === folder && p.uid === uid)
      )
    }

    const msg = mailbox.findMessageByUid(uid, folder)
    if (msg) {
      msg.pinned = !!pinned
    }
    // IndexedDB write-through.
    if (folder && Number.isFinite(Number(uid))) {
      syncOfflineActiveUserEmail()
      patchOfflineMessage(folder, Number(uid), { pinned: !!pinned }).catch(() => {})
    }
  }

  /**
   * Handle labels changed event
   * Backend sends: { messageId, labelId, action, label }
   */
  function handleLabelsChanged(payload) {
    isDebugEnabled() && console.log('[MailSyncIntegration] Labels changed:', payload)

    const { messageId, labelId, action, label } = payload
    if (!messageId || !labelId) return

    const updateMessageLabels = (msg) => {
      if (!msg || !msg.message_id) return
      const normalizedId = msg.message_id.replace(/^<|>$/g, '')
      if (normalizedId !== messageId && msg.message_id !== messageId) return

      if (!Array.isArray(msg.labels)) msg.labels = []

      if (action === 'add' && label) {
        if (!msg.labels.some(l => l.id === labelId)) {
          msg.labels.push(label)
        }
      } else if (action === 'remove') {
        msg.labels = msg.labels.filter(l => l.id !== labelId)
      }
    }

    mailbox.messages.forEach(updateMessageLabels)
    if (mailbox.currentMessage) {
      updateMessageLabels(mailbox.currentMessage)
    }
    mailbox.notifyMessagesChanged()

    // IndexedDB write-through: walk the in-RAM message list and patch
    // each matching cached entry. We do not have direct uid here; the
    // updateMessageLabels closure has already located the matching RAM
    // message(s), so reuse its decision by reading mailbox.messages.
    const touched = mailbox.messages.filter(m => {
      if (!m || !m.message_id) return false
      const normalizedId = m.message_id.replace(/^<|>$/g, '')
      return normalizedId === messageId || m.message_id === messageId
    })
    if (touched.length) {
      syncOfflineActiveUserEmail()
      for (const m of touched) {
        if (m.folder && Number.isFinite(Number(m.uid))) {
          patchOfflineMessage(m.folder, Number(m.uid), { labels: m.labels }).catch(() => {})
        }
      }
    }
  }

  /**
   * Handle folder counts event (server-authoritative)
   * IMAP is the source of truth - just update local state
   */
  function handleFolderCounts(payload) {
    isDebugEnabled() && console.log('[MailSyncIntegration] Folder counts:', payload)
    touchEventTimestamp()
    
    const { folder, total, uidnext, uidvalidity } = payload
    
    // Find and update folder in the list
    const folderData = mailbox.folders.find(f => f.name === folder)
    if (folderData) {
      const prevUidnext = folderData.uidnext
      const prevUidvalidity = folderData.uidvalidity
      const prevTotal = folderData.total
      
      // IMAP-single-source-of-truth: the unread BADGE is owned exclusively by
      // the authoritative IMAP STATUS numbers returned by our own fetches
      // (/mailbox/folders, /messages folderStatus, /delta counts). We do NOT
      // trust the unread value pushed on this websocket event: it is computed
      // by the background sync engine at a DIFFERENT moment than the fetch, and
      // applying it is exactly what made the badge jump (0 -> 4 -> 0) and a
      // read message flash back to unread. This event is used ONLY as a
      // "something changed, go re-read from IMAP" trigger below.
      if (total !== undefined) folderData.total = total
      if (uidnext !== undefined) folderData.uidnext = uidnext
      if (uidvalidity !== undefined) folderData.uidvalidity = uidvalidity

      const uidnextAdvanced = uidnext && prevUidnext && uidnext > prevUidnext
      const uidvalidityChanged = prevUidvalidity && uidvalidity && prevUidvalidity !== uidvalidity
      const totalDecreased = total !== undefined && prevTotal !== undefined && total < prevTotal
      const folderChanged = uidnextAdvanced || uidvalidityChanged || totalDecreased

      // Fast-path: only the unread counter changed (no new mail, no
      // structural change, no expunge). This is the common shape when
      // Gmail-side bulk read activity is happening (server cron, mobile
      // read storm, thread auto-read). We intentionally do NOTHING here:
      // the pushed unread number is not trusted (see above), and the
      // authoritative badge is reconciled by the next /delta poll / folder
      // refresh / folder-open, all of which read IMAP STATUS directly. This
      // is what keeps the badge from jittering during a read storm AND from
      // ever showing a number that disagrees with IMAP.
      if (!folderChanged) {
        return
      }

      // Outlook-style new-mail chime, source-agnostic. Many accounts/servers
      // signal new inbox mail via FOLDER_COUNTS (UIDNEXT advanced) WITHOUT a
      // paired MESSAGE_NEW event, so the sound must also fire here, regardless
      // of which view is currently open. The per-type throttle in the sound
      // service dedupes this against the MESSAGE_NEW path so we never double-ding.
      if (uidnextAdvanced && isInboxLikeFolder(folder) && localStorage.getItem('notification_new_email') !== 'false') {
        notificationSounds.playEmailSound()
      }

      // ALL_MAIL has no IMAP IDLE subscription of its own, so MESSAGE_NEW
      // never arrives while the user sits there. FOLDER_COUNTS for any real
      // folder is our only signal — fan it out to refresh the aggregated
      // view so new mail appears without a manual refresh.
      // (SEARCH_RESULTS is a frozen query snapshot; we don't auto-refresh it.)
      if (folderChanged && mailbox.currentFolder === 'ALL_MAIL') {
        isDebugEnabled() && console.log(`[MailSync] Folder ${folder} changed while in ALL_MAIL — refreshing aggregated view`)
        debouncedFetchMessages(mailbox)
        return
      }

      if (folder !== mailbox.currentFolder) return
      
      // UIDVALIDITY changed = folder rebuilt, must do full refresh
      if (uidvalidityChanged) {
        isDebugEnabled() && console.log('[MailSync] UIDVALIDITY changed, full refresh')
        debouncedFetchMessages(mailbox)
        return
      }
      
      // UIDNEXT advanced = new messages arrived, do incremental fetch
      if (uidnextAdvanced) {
        isDebugEnabled() && console.log(`[MailSync] UIDNEXT advanced ${prevUidnext} -> ${uidnext}, incremental fetch`)
        debouncedIncrementalFetch(mailbox, folder)
        // Same DB-mirror race as MESSAGE_NEW: retry until the new highest UID
        // (uidnext - 1) lands. Covers servers/paths that emit FOLDER_COUNTS
        // without a paired MESSAGE_NEW.
        scheduleNewMailReconcile(mailbox, folder, uidnext - 1)
        return
      }
      
      // Total decreased = messages were expunged (moved/deleted).
      //
      // We must distinguish two cases:
      //
      //   (a) Local-driven decrease: the user (or another tab/device for
      //       the same account) moved/deleted an email and the local view
      //       has ALREADY been adjusted by removeMessageFromList. In this
      //       case the FOLDER_COUNTS event is just confirming what the
      //       view already shows -- refreshing would be redundant AND
      //       destructive, because fetchMessages(page 1) REPLACES
      //       folderViews with only the top page, wiping any pages 2+ the
      //       user had scrolled in. That bug surfaced as "entire months
      //       of emails disappear when I move one email" (especially
      //       noticeable on OAuth/Gmail accounts).
      //
      //   (b) Remote-driven decrease: a cross-device action (mail read on
      //       phone, server-side filter, retention rule, etc.) shrank the
      //       folder without any local action. Here we genuinely need to
      //       refetch.
      //
      // The mailbox store tracks every local-applied removal via
      // markLocalRemoval / consumeLocalRemovals. If the actual decrease
      // matches the count of pending local removals, we consume them and
      // skip the refresh entirely. Any "excess" decrease (something the
      // local view did NOT already account for) still triggers a refresh.
      //
      // Pending-removal counters expire after 30s so a dropped
      // FOLDER_COUNTS event can never permanently suppress refreshes.
      if (totalDecreased) {
        const actualDecrease = Math.max(0, prevTotal - total)
        const consumed = mailbox.consumeLocalRemovals
          ? mailbox.consumeLocalRemovals(folder, actualDecrease)
          : 0
        const unexplainedDecrease = actualDecrease - consumed
        if (unexplainedDecrease <= 0) {
          isDebugEnabled() && console.log(
            `[MailSync] FOLDER_COUNTS totalDecreased=${actualDecrease} on ${folder} fully consumed by local removals - skipping refresh`
          )
          return
        }
        isDebugEnabled() && console.log(
          `[MailSync] FOLDER_COUNTS totalDecreased=${actualDecrease} on ${folder}, ${consumed} consumed locally, ${unexplainedDecrease} unexplained - refreshing`
        )
        debouncedFetchMessages(mailbox)
      }
    }
  }

  /**
   * Handle conversation updated event
   *
   * Backend publishes CONVERSATION_UPDATED whenever a thread's membership
   * changes -- including as a side effect of every move/delete the user
   * just performed locally. For OAuth accounts with pending local
   * removals we skip the incremental message refetch here, because:
   *
   *   - The local view is already authoritative (optimistic removal
   *     applied, FOLDER_COUNTS suppression in place).
   *   - The /delta endpoint can return Gmail-IMAP-replica-flapped
   *     uidvalidity, tripping the destructive UIDVALIDITY branch in
   *     fetchMessagesSince.
   *   - Even an incremental fetch can spuriously surface "new" UIDs
   *     for messages already present under different replica orderings.
   *
   * Conversations themselves still refresh (DB-backed, separate from
   * IMAP) so threading stays accurate.
   *
   * Regular IMAP accounts: skipped check is gated on
   * isCurrentAccountOAuth(), so they go through the existing
   * incremental fetch unchanged.
   */
  function handleConversationUpdated(payload) {
    isDebugEnabled() && console.log('[MailSync] Conversation updated:', payload)
    touchEventTimestamp()
    
    const { conversationId, folder } = payload
    
    // Refresh conversations for the folder (hits database, not IMAP)
    conversations.fetchConversations(folder)
    
    // If this is the current folder, do incremental fetch (avoids full IMAP reload)
    if (folder === mailbox.currentFolder) {
      const isOAuthWithPending =
        mailbox.isCurrentAccountOAuth &&
        mailbox.hasPendingLocalRemovals &&
        mailbox.isCurrentAccountOAuth() &&
        mailbox.hasPendingLocalRemovals(folder)
      if (isOAuthWithPending) {
        isDebugEnabled() && console.log(
          `[MailSync] Suppressed CONVERSATION_UPDATED incremental fetch for ${folder} (OAuth + pending local removals)`
        )
        return
      }
      debouncedIncrementalFetch(mailbox, folder)
    }
  }

  /**
   * Handle folder changed event (created/renamed/deleted).
   *
   * Wave 2 P2 contract: every backend-published FOLDER_CHANGED event
   * carries a `folder_identity_version` field. We update our local
   * baseline atomically with the event so the reconnect-time drift check
   * (in handleReconnected) only fires when an event was genuinely missed.
   */
  function handleFolderChanged(payload) {
    isDebugEnabled() && console.log('[MailSync] Folder changed:', payload)

    const { action, folder, newName, folder_identity_version } = payload

    // Update the version baseline FIRST so a refresh that races with
    // this event sees the post-event number.
    if (folder_identity_version !== undefined) {
      mailbox.setFolderIdentityVersion(folder_identity_version)
    }

    // Handle different actions
    if (action === 'renamed' && newName) {
      // Rename keys in the canonical message store + folderViews cache
      mailbox.renameFolderInStore(folder, newName)

      // Migrate conversation cache from oldName -> newName. Without this,
      // tabs OTHER than the originating one keep stale conversation
      // entries keyed by the old folder path. The originating tab does
      // this synchronously inside mailboxStore.renameFolder().
      try {
        conversations.handleFolderRenamed(folder, newName)
      } catch (convErr) {
        console.warn('[MailSync] handleFolderRenamed failed:', convErr)
      }

      // If the current folder was renamed, update to the new name
      if (mailbox.currentFolder === folder) {
        mailbox.currentFolder = newName
      }

      // Update folder in local list without full refresh
      const folderObj = mailbox.folders.find(f => f.name === folder)
      if (folderObj) {
        folderObj.name = newName
      }
    } else if (action === 'deleted') {
      // If current folder was deleted, go to INBOX
      if (mailbox.currentFolder === folder) {
        mailbox.fetchMessages('INBOX')
      }

      // Remove folder from local list
      const idx = mailbox.folders.findIndex(f => f.name === folder)
      if (idx !== -1) {
        mailbox.folders.splice(idx, 1)
      }
    }

    // Refresh folder list for create/delete (rename already updated locally)
    if (action !== 'renamed') {
      mailbox.fetchFolders(true)
    }

    // Refresh conversations if we have them loaded for this folder
    if (conversations.conversationsByFolder[mailbox.currentFolder]) {
      conversations.fetchConversations(mailbox.currentFolder)
    }
  }

  /**
   * Handle settings changed event (theme, accent color, density, layout)
   * Syncs visual settings across all devices in real-time
   */
  function handleSettingsChanged(payload) {
    isDebugEnabled() && console.log('[MailSync] Settings changed:', payload)
    
    const { settings } = payload
    if (!settings) return
    
    // Apply theme changes
    if (settings.theme) {
      theme.setTheme(settings.theme, false) // false = don't save back to server
    }
    
    // Apply accent color changes
    if (settings.accent_color) {
      theme.setAccentColor(settings.accent_color, false)
    }
    
    // Apply display density changes
    if (settings.display_density) {
      theme.setDisplayDensity(settings.display_density, false)
    }
    
    // Apply layout mode changes
    if (settings.layout_mode) {
      layout.setLayout(settings.layout_mode, false)
    }
    
    // Apply perspective changes
    if (settings.perspective) {
      perspectiveStore.setPerspective(settings.perspective, false)
    }
  }

  /**
   * Run email rule catchup against recent INBOX messages.
   * Called after connect/reconnect so rules evaluate emails that arrived while offline.
   */
  let emailRuleCatchupTimer = null
  function runEmailRuleCatchup(delayMs = 3000) {
    if (emailRuleCatchupTimer) clearTimeout(emailRuleCatchupTimer)
    emailRuleCatchupTimer = setTimeout(async () => {
      try {
        const res = await api.post('/board-pro/evaluate-email-rules-catchup', { folder: 'INBOX' })
        const data = res?.data?.data
        if (data && data.length > 0) {
          isDebugEnabled() && console.log('[MailSyncIntegration] Email rule catchup processed', data.length, 'results')
        }
      } catch (e) {
        // Non-critical, silently ignore (addon may not be active)
      }
    }, delayMs)
  }

  /**
   * Handle connected event
   * Refresh folders + messages to ensure fresh data on every connection
   */
  const WS_FRESH_SKIP_MS = 10000 // Skip WS refetch if initMailbox completed <10s ago

  async function handleConnected(payload) {
    isDebugEnabled() && console.log('[MailSyncIntegration] Connected to sync server')
    touchEventTimestamp()
    
    // Subscribe to current folder for IMAP IDLE
    subscribeToFolder(mailbox.currentFolder)

    // Skip refetch if initMailbox just completed (data is fresh)
    const initAge = Date.now() - (mailbox.lastInitAt || 0)
    if (initAge < WS_FRESH_SKIP_MS) {
      isDebugEnabled() && console.log('[MailSyncIntegration] Skipping connected refetch - initMailbox completed', initAge, 'ms ago')
      runEmailRuleCatchup(5000)
      return
    }

    // Skip if no folders loaded yet (initial page load — MailboxView will call initMailbox)
    if (mailbox.folders.length === 0) {
      isDebugEnabled() && console.log('[MailSyncIntegration] Skipping connected refetch - no folders yet, waiting for initMailbox')
      runEmailRuleCatchup(5000)
      return
    }

    mailbox.fetchFolders(true)

    if (mailbox.currentFolder) {
      const highestUid = mailbox.getHighestUid(mailbox.currentFolder)
      if (highestUid > 0) {
        await mailbox.fetchMessagesSince(mailbox.currentFolder, highestUid)
      } else {
        mailbox.fetchMessages(mailbox.currentFolder, 1)
      }
    }

    runEmailRuleCatchup(5000)
  }

  /**
   * Handle reconnected event.
   *
   * Wave 2 P2 contract: on every reconnect, fetch the live
   * folder_identity_version from /mailbox/folders/identity-version and
   * compare against our cached baseline. Any drift means we missed at
   * least one FOLDER_CHANGED event during the disconnect, so we cannot
   * trust the in-memory caches keyed by folder path; the safe response
   * is a full folder + message refetch via invalidateAllFoldersFromDrift.
   *
   * The endpoint is Redis-only and ~1ms, so calling it on every
   * reconnect is cheap.
   */
  async function handleReconnected(payload) {
    isDebugEnabled() && console.log('[MailSyncIntegration] Reconnected to sync server')
    touchEventTimestamp()

    // Skip if initMailbox just completed or hasn't run yet
    const initAge = Date.now() - (mailbox.lastInitAt || 0)
    if (initAge < WS_FRESH_SKIP_MS || mailbox.folders.length === 0) {
      isDebugEnabled() && console.log('[MailSyncIntegration] Skipping reconnected refetch - data fresh or not yet loaded')
      return
    }

    // Folder-identity drift check FIRST so we know whether the cheaper
    // incremental refresh path is safe or whether we need a full nuke.
    let driftDetected = false
    try {
      const res = await api.get('/mailbox/folders/identity-version')
      const remote = Number(res?.data?.data?.folder_identity_version) || 0
      if (mailbox.isFolderIdentityVersionStale(remote)) {
        driftDetected = true
        await mailbox.invalidateAllFoldersFromDrift('reconnect_version_mismatch')
        // Update baseline now that we've recovered
        mailbox.setFolderIdentityVersion(remote)
      } else if (remote > 0) {
        // Versions match; just keep our baseline up to date in case Redis
        // restarted and counter rolled over (the setter is monotonic).
        mailbox.setFolderIdentityVersion(remote)
      }
    } catch (e) {
      console.warn('[MailSyncIntegration] identity-version check failed:', e)
    }

    if (driftDetected) {
      // invalidateAllFoldersFromDrift already refetched folders + messages
      runEmailRuleCatchup(3000)
      return
    }

    mailbox.fetchFolders(true)

    if (mailbox.currentFolder) {
      const highestUid = mailbox.getHighestUid(mailbox.currentFolder)
      if (highestUid > 0) {
        await mailbox.fetchMessagesSince(mailbox.currentFolder, highestUid)
      } else {
        mailbox.fetchMessages(mailbox.currentFolder, 1)
      }
    }

    // Catchup email rules for any emails that arrived during disconnect
    runEmailRuleCatchup(3000)
  }

  /**
   * Handle sync gap detected (events were lost during disconnect).
   * Prefers the CONDSTORE delta path so we don't blindly overwrite optimistic
   * read/flagged state with a stale full fetch (the historic cause of the
   * "mark-as-read keeps reverting" Gmail OAuth regression). Falls back to a
   * full fetch only when we have no cached UIDs to delta from.
   */
  async function handleSyncGapDetected() {
    console.warn('[MailSyncIntegration] Sync gap detected — refreshing')
    mailbox.fetchFolders(true)
    if (mailbox.currentFolder) {
      const highestUid = mailbox.getHighestUid(mailbox.currentFolder)
      if (highestUid > 0) {
        await mailbox.fetchMessagesSince(mailbox.currentFolder, highestUid)
      } else {
        await mailbox.fetchMessages(mailbox.currentFolder, 1)
      }
    }
    if (calendar.fetchCalendars) calendar.fetchCalendars({ force: true })
    if (calendar.fetchEvents) calendar.fetchEvents(null, null, { force: true, quiet: true })
    if (calendar.fetchTodayEventsForReminders) calendar.fetchTodayEventsForReminders()
  }

  /**
   * Handle page visibility change (tab/window becomes visible again).
   * Browsers throttle background tabs, so WebSocket events may have been
   * received but the debounced fetches delayed or skipped. Force a full
   * refresh when the user returns to ensure the UI matches IMAP state.
   */
  const TAB_FOCUS_THROTTLE_MS = 30000 // Throttle tab-focus refresh to once per 30s
  let lastVisibilityRefreshAt = 0

  function handleVisibilityChange() {
    if (document.hidden) return

    const now = Date.now()
    if (now - lastVisibilityRefreshAt < TAB_FOCUS_THROTTLE_MS) return
    lastVisibilityRefreshAt = now

    // Debounce: rapid tab-switching shouldn't fire multiple refreshes
    if (visibilityRefreshTimer) clearTimeout(visibilityRefreshTimer)
    visibilityRefreshTimer = setTimeout(async () => {
      visibilityRefreshTimer = null
      if (!isConnected.value && !auth.token) return

      isDebugEnabled() && console.log('[MailSyncIntegration] Tab visible — checking for changes')
      mailbox.fetchFolders(true)
      if (route.path.startsWith('/calendar')) {
        if (calendar.fetchCalendars) calendar.fetchCalendars({ force: true })
        if (calendar.fetchEvents) calendar.fetchEvents(null, null, { force: true, quiet: true })
      } else {
        if (calendar.invalidateCalendarsCache) calendar.invalidateCalendarsCache()
        if (calendar.invalidateEventsCache) calendar.invalidateEventsCache()
      }
      if (calendar.fetchTodayEventsForReminders) calendar.fetchTodayEventsForReminders()

      if (mailbox.currentFolder) {
        await mailbox.revalidateActiveFolder()
      }
    }, VISIBILITY_REFRESH_DEBOUNCE_MS)
  }

  /**
   * Start reconciliation timer (safety net)
   * This is a longer interval than the old polling, just to ensure
   * we don't miss anything if WebSocket events are lost
   */
  function startReconciliation() {
    stopReconciliation()

    reconciliationTimer = setInterval(async () => {
      if (!isConnected.value || !mailbox.currentFolder) return

      isDebugEnabled() && console.log('[MailSync] Reconciliation: checking sync state')

      // Always refresh folder list (lightweight, catches external changes)
      mailbox.fetchFolders(true)

      await mailbox.revalidateActiveFolder()

      // Sync-engine health probe (Phase 4 of the Gmail-like plan).
      // Polls the server-side mirror state and surfaces any folder
      // stuck in a non-synced status. Cheap (single endpoint, returns
      // aggregate per-status counts plus an attention list).
      await checkSyncEngineHealth()
    }, RECONCILIATION_INTERVAL)
  }

  /**
   * Phase 4: query the sync-engine status endpoint and react to
   * unhealthy folders. The endpoint surface is intentionally small:
   *   { synced, pending, initial_syncing, failed, uidvalidity_reset,
   *     total_folders, attention_folders: [{folder_path,status,last_error,attempts}] }
   *
   * Reactions:
   *   - status='failed' + attempts >= 5 -> warn in console once per session
   *   - status='uidvalidity_reset'      -> force a full fetch on next visit
   *                                        (mirror was wiped on the server)
   *   - status='initial_syncing'        -> debug log (normal during onboarding)
   */
  const _seenAttentionWarnings = new Set()
  async function checkSyncEngineHealth() {
    try {
      const res = await api.get('/mailbox/sync-stats')
      const stats = res?.data?.data
      if (!stats || typeof stats !== 'object') return

      const attention = Array.isArray(stats.attention_folders) ? stats.attention_folders : []
      if (attention.length === 0) {
        // Clear any stale warnings so a recovered folder doesn't stay silent forever
        _seenAttentionWarnings.clear()
        return
      }

      for (const folder of attention) {
        const key = `${folder.folder_path}|${folder.status}`
        if (folder.status === 'failed' && (folder.attempts ?? 0) >= 5) {
          if (!_seenAttentionWarnings.has(key)) {
            console.warn(
              `[MailSync] Sync engine: folder "${folder.folder_path}" failed ${folder.attempts}x`,
              folder.last_error || ''
            )
            _seenAttentionWarnings.add(key)
          }
        } else if (folder.status === 'uidvalidity_reset') {
          isDebugEnabled() && console.log(
            `[MailSync] Sync engine: ${folder.folder_path} is resetting (UIDVALIDITY changed server-side)`
          )
        } else if (folder.status === 'initial_syncing') {
          isDebugEnabled() && console.log(
            `[MailSync] Sync engine: ${folder.folder_path} is still doing initial backfill`
          )
        }
      }
    } catch (e) {
      // 404 (endpoint not deployed yet) or 5xx -> ignore silently. The
      // reconciler still does its primary revalidateActiveFolder pass.
      isDebugEnabled() && console.debug('[MailSync] sync-stats probe skipped:', e?.message || e)
    }
  }

  /**
   * Stop reconciliation timer
   */
  function stopReconciliation() {
    if (reconciliationTimer) {
      clearInterval(reconciliationTimer)
      reconciliationTimer = null
    }
  }

  /**
   * Cleanup on unmount
   */
  function cleanup() {
    // Unregister all event handlers
    for (const unsubscribe of cleanupFunctions.value) {
      unsubscribe()
    }
    cleanupFunctions.value = []
    
    // Clear all debounce timers
    if (fetchFoldersDebounceTimer) { clearTimeout(fetchFoldersDebounceTimer); fetchFoldersDebounceTimer = null }
    for (const t of fullFetchTimers.values()) clearTimeout(t)
    fullFetchTimers.clear()
    for (const t of incrementalFetchTimers.values()) clearTimeout(t)
    incrementalFetchTimers.clear()
    if (genericFullFetchTimer) { clearTimeout(genericFullFetchTimer); genericFullFetchTimer = null }
    if (genericIncrementalTimer) { clearTimeout(genericIncrementalTimer); genericIncrementalTimer = null }
    if (boardUpdateDebounceTimer) { clearTimeout(boardUpdateDebounceTimer); boardUpdateDebounceTimer = null }
    if (boardEntityUpdateDebounceTimer) { clearTimeout(boardEntityUpdateDebounceTimer); boardEntityUpdateDebounceTimer = null; pendingBoardEntityRefresh = false }
    if (calendarUpdateDebounceTimer) { clearTimeout(calendarUpdateDebounceTimer); calendarUpdateDebounceTimer = null }
    if (todoUpdateDebounceTimer) { clearTimeout(todoUpdateDebounceTimer); todoUpdateDebounceTimer = null }
    if (emailRuleCatchupTimer) { clearTimeout(emailRuleCatchupTimer); emailRuleCatchupTimer = null }
    if (emailRuleDebounceTimer) { clearTimeout(emailRuleDebounceTimer); emailRuleDebounceTimer = null }
    pendingRulePayloads = []
    if (visibilityRefreshTimer) { clearTimeout(visibilityRefreshTimer); visibilityRefreshTimer = null }
    for (const t of newMailReconcileTimers.values()) clearTimeout(t)
    newMailReconcileTimers.clear()
    newMailReconcileState.clear()
    
    // Remove visibility listener
    document.removeEventListener('visibilitychange', handleVisibilityChange)
    
    // Stop reconciliation
    stopReconciliation()
    
    // Disconnect WebSocket
    disconnect()
  }

  /**
   * Watch for folder changes to subscribe to IDLE
   */
  watch(() => mailbox.currentFolder, (newFolder, oldFolder) => {
    if (isConnected.value && newFolder !== oldFolder) {
      subscribeToFolder(newFolder)
    }
  })

  /**
   * Watch for auth changes
   */
  watch(() => auth.token, (newToken, oldToken) => {
    if (newToken && !oldToken) {
      // User logged in
      init()
    } else if (!newToken && oldToken) {
      // User logged out
      cleanup()
    }
  })

  return {
    // State
    connectionState,
    isConnected,
    lastError,
    
    // Methods
    init,
    cleanup,
    
    // Exposed for debugging
    startReconciliation,
    stopReconciliation,
  }
}

