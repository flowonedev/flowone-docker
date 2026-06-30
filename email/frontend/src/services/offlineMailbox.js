/**
 * Offline Mailbox Service - Web Build (IndexedDB-backed)
 *
 * Implements a per-account persistent cache for the web build so that:
 *   - The folder list, the top-N messages of recently-visited folders,
 *     and the bodies of messages read in the last 30 days are kept on
 *     disk in IndexedDB and survive page reloads / tab close.
 *   - Login hydrates from IndexedDB BEFORE any network call so the UI
 *     renders instantly (no empty folder flash, no spinner on cold start).
 *   - WebSocket events (FLAGS_CHANGED, MESSAGE_MOVED, etc.) write
 *     through to IndexedDB so each device's local cache stays current
 *     across the IMAP IDLE -> backend pubsub -> WebSocket fan-out.
 *
 * The Electron desktop build overrides this file via Vite alias
 * (`FlowOneEmail/vite.config.ts`) so its SQLite-backed implementation
 * is used there. This file is the WEB implementation only.
 *
 * DB layout (single DB, version 1):
 *   - folders          keyPath: userEmail
 *   - folder_index     keyPath: [userEmail, folder]
 *   - messages         keyPath: [userEmail, folder, uid], index [userEmail,folder]
 *   - bodies           keyPath: [userEmail, folder, uid]
 *   - folder_activity  keyPath: [userEmail, folder]
 *
 * Per-account isolation: every read/write keys by an `userEmail` so
 * multiple accounts on the same machine cannot see each others data.
 * The mailbox store calls `setActiveUserEmail()` whenever it knows the
 * current account; legacy zero-arg call sites then keep working.
 *
 * UIDVALIDITY guard: callers can pass `expectedUidvalidity` to reads;
 * a mismatch returns null (and wipes the folder cache) so the caller
 * falls back to network.
 *
 * Body cache window: bodies whose message date is older than 30 days
 * are NOT stored. Bodies whose `cachedAt` is older than 30 days are
 * pruned on the next read.
 */

const DB_NAME = 'flowone-mail-cache'
const DB_VERSION = 1
const BODY_AGE_LIMIT_DAYS = 30
const BODY_AGE_LIMIT_MS = BODY_AGE_LIMIT_DAYS * 24 * 60 * 60 * 1000
// Hard ceiling on bodies per account to bound storage growth even within
// the 30-day window. LRU on cachedAt evicts the oldest first when hit.
const BODY_HARD_CAP = 2000

const STORES = {
  folders: 'folders',
  folderIndex: 'folder_index',
  messages: 'messages',
  bodies: 'bodies',
  activity: 'folder_activity',
}

let _activeUserEmail = null
let _dbPromise = null
let _idbSupported = null

function isIndexedDBSupported() {
  if (_idbSupported !== null) return _idbSupported
  try {
    _idbSupported = typeof indexedDB !== 'undefined' && indexedDB !== null
  } catch (_e) {
    _idbSupported = false
  }
  return _idbSupported
}

function openDB() {
  if (_dbPromise) return _dbPromise
  if (!isIndexedDBSupported()) {
    _dbPromise = Promise.resolve(null)
    return _dbPromise
  }
  _dbPromise = new Promise((resolve) => {
    let req
    try {
      req = indexedDB.open(DB_NAME, DB_VERSION)
    } catch (e) {
      console.warn('[offlineMailbox] indexedDB.open threw:', e)
      resolve(null)
      return
    }
    req.onupgradeneeded = (event) => {
      const db = event.target.result
      if (!db.objectStoreNames.contains(STORES.folders)) {
        db.createObjectStore(STORES.folders, { keyPath: 'userEmail' })
      }
      if (!db.objectStoreNames.contains(STORES.folderIndex)) {
        db.createObjectStore(STORES.folderIndex, { keyPath: ['userEmail', 'folder'] })
      }
      if (!db.objectStoreNames.contains(STORES.messages)) {
        const ms = db.createObjectStore(STORES.messages, { keyPath: ['userEmail', 'folder', 'uid'] })
        ms.createIndex('byFolder', ['userEmail', 'folder'], { unique: false })
      }
      if (!db.objectStoreNames.contains(STORES.bodies)) {
        const bs = db.createObjectStore(STORES.bodies, { keyPath: ['userEmail', 'folder', 'uid'] })
        bs.createIndex('byUser', 'userEmail', { unique: false })
        bs.createIndex('byCachedAt', 'cachedAt', { unique: false })
      }
      if (!db.objectStoreNames.contains(STORES.activity)) {
        db.createObjectStore(STORES.activity, { keyPath: ['userEmail', 'folder'] })
      }
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => {
      console.warn('[offlineMailbox] indexedDB.open failed:', req.error)
      resolve(null)
    }
    req.onblocked = () => {
      console.warn('[offlineMailbox] indexedDB.open blocked (another tab is holding an older version)')
      resolve(null)
    }
  })
  return _dbPromise
}

function tx(db, storeNames, mode = 'readonly') {
  return db.transaction(Array.isArray(storeNames) ? storeNames : [storeNames], mode)
}

function reqPromise(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

function activeEmail(override) {
  return override || _activeUserEmail || null
}

function isElectron() {
  return typeof window !== 'undefined' && !!window.api
}

// ============================================================================
// Active-user tracking
// ============================================================================

/**
 * The mailbox store calls this on init and whenever the active account
 * changes. All subsequent zero-arg legacy calls (getOfflineFolders,
 * getOfflineMessages, ...) will key by this email.
 */
export function setActiveUserEmail(email) {
  _activeUserEmail = email || null
}

export function getActiveUserEmail() {
  return _activeUserEmail
}

// ============================================================================
// Folder list
// ============================================================================

/**
 * Returns the cached folder list for the active (or given) user,
 * or null when the cache is empty / IndexedDB is unavailable.
 *
 * @returns {Promise<Array|null>} folder array as returned by /mailbox/folders
 */
export async function getOfflineFolders(userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email) return null
  const db = await openDB()
  if (!db) return null
  try {
    const store = tx(db, STORES.folders).objectStore(STORES.folders)
    const row = await reqPromise(store.get(email))
    return row?.folders || null
  } catch (e) {
    console.warn('[offlineMailbox] getOfflineFolders failed:', e)
    return null
  }
}

/**
 * Persist the folder list for an account.
 */
export async function setOfflineFolders(folders, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !Array.isArray(folders)) return false
  const db = await openDB()
  if (!db) return false
  try {
    const store = tx(db, STORES.folders, 'readwrite').objectStore(STORES.folders)
    await reqPromise(store.put({ userEmail: email, folders, updatedAt: Date.now() }))
    return true
  } catch (e) {
    console.warn('[offlineMailbox] setOfflineFolders failed:', e)
    return false
  }
}

// ============================================================================
// Message list (per folder, page 1 only -- list view)
// ============================================================================

/**
 * Returns the cached page-1 message list for the given folder, in the
 * shape the mailbox store expects (matches /mailbox/{folder}/messages
 * response shape).
 *
 * If `expectedUidvalidity` is provided and does not match the cached
 * value, the folder cache is wiped and null is returned so the caller
 * falls back to network.
 *
 * @param {string} folder
 * @param {number} page             - currently only page=1 is cached
 * @param {number} limit            - typically 50
 * @param {number} [expectedUidvalidity]
 * @param {string} [userEmailOverride]
 * @returns {Promise<{messages, page, pages, total, limit, uidvalidity}|null>}
 */
export async function getOfflineMessages(folder, page = 1, limit = 50, expectedUidvalidity, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder || page !== 1) return null
  const db = await openDB()
  if (!db) return null
  try {
    const indexStore = tx(db, STORES.folderIndex).objectStore(STORES.folderIndex)
    const idx = await reqPromise(indexStore.get([email, folder]))
    if (!idx) return null
    if (expectedUidvalidity && idx.uidvalidity && expectedUidvalidity !== idx.uidvalidity) {
      // Caller signalled a UIDVALIDITY mismatch; clear and force fresh fetch.
      await wipeFolderCache(folder, email)
      return null
    }
    if (!idx.uids?.length) return { messages: [], page: 1, pages: 1, total: 0, limit, uidvalidity: idx.uidvalidity }

    const messagesStore = tx(db, STORES.messages).objectStore(STORES.messages)
    const messages = []
    for (const uid of idx.uids.slice(0, limit)) {
      try {
        const msg = await reqPromise(messagesStore.get([email, folder, uid]))
        if (msg) messages.push(msg.message || msg)
      } catch (_e) {
        // Skip individual misses
      }
    }
    return {
      messages,
      page: 1,
      pages: idx.pagination?.pages || 1,
      total: idx.pagination?.total || messages.length,
      limit,
      uidvalidity: idx.uidvalidity || null,
    }
  } catch (e) {
    console.warn('[offlineMailbox] getOfflineMessages failed:', e)
    return null
  }
}

/**
 * Persist a freshly-fetched message page-1 for a folder.
 *
 * @param {string} folder
 * @param {Array}  messages         - array of message header objects (must have .uid)
 * @param {object} pagination       - { page, pages, total, limit }
 * @param {number} [uidvalidity]
 * @param {number} [uidnext]
 * @param {string} [userEmailOverride]
 */
export async function setOfflineMessages(folder, messages, pagination, uidvalidity, uidnext, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder || !Array.isArray(messages)) return false
  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, [STORES.folderIndex, STORES.messages], 'readwrite')
    const indexStore = t.objectStore(STORES.folderIndex)
    const messagesStore = t.objectStore(STORES.messages)

    const uids = messages.map(m => m.uid).filter(u => Number.isFinite(u))
    indexStore.put({
      userEmail: email,
      folder,
      uidvalidity: uidvalidity || null,
      uidnext: uidnext || null,
      uids,
      pagination: pagination || { page: 1, pages: 1, total: uids.length, limit: uids.length },
      updatedAt: Date.now(),
    })
    for (const m of messages) {
      if (!Number.isFinite(m.uid)) continue
      messagesStore.put({ userEmail: email, folder, uid: m.uid, message: m })
    }
    return await txDone(t)
  } catch (e) {
    console.warn('[offlineMailbox] setOfflineMessages failed:', e)
    return false
  }
}

/**
 * Patch a single cached message (flag flip, label change, pin toggle, ...).
 * Silently no-ops if the message is not cached.
 */
export async function patchMessage(folder, uid, partial, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder || !Number.isFinite(uid) || !partial) return false
  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, STORES.messages, 'readwrite')
    const store = t.objectStore(STORES.messages)
    const row = await reqPromise(store.get([email, folder, uid]))
    if (!row) return false
    const msg = row.message || row
    const merged = { ...msg, ...partial }
    await reqPromise(store.put({ userEmail: email, folder, uid, message: merged }))
    return true
  } catch (e) {
    console.warn('[offlineMailbox] patchMessage failed:', e)
    return false
  }
}

/**
 * Remove a message from the cache (deleted or moved away). Also drops
 * its UID from the folder index so the list view doesn't show a ghost.
 */
export async function removeMessage(folder, uid, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder || !Number.isFinite(uid)) return false
  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, [STORES.folderIndex, STORES.messages, STORES.bodies], 'readwrite')
    const indexStore = t.objectStore(STORES.folderIndex)
    const messagesStore = t.objectStore(STORES.messages)
    const bodiesStore = t.objectStore(STORES.bodies)

    messagesStore.delete([email, folder, uid])
    bodiesStore.delete([email, folder, uid])
    const idx = await reqPromise(indexStore.get([email, folder]))
    if (idx?.uids?.length) {
      const filtered = idx.uids.filter(u => u !== uid)
      if (filtered.length !== idx.uids.length) {
        idx.uids = filtered
        if (idx.pagination?.total) idx.pagination.total = Math.max(0, idx.pagination.total - 1)
        indexStore.put(idx)
      }
    }
    return await txDone(t)
  } catch (e) {
    console.warn('[offlineMailbox] removeMessage failed:', e)
    return false
  }
}

/**
 * Add a newly-arrived message to the cache and to the top of the folder
 * index (newest-first ordering).
 */
export async function addMessage(folder, message, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder || !message || !Number.isFinite(message.uid)) return false
  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, [STORES.folderIndex, STORES.messages], 'readwrite')
    const indexStore = t.objectStore(STORES.folderIndex)
    const messagesStore = t.objectStore(STORES.messages)

    messagesStore.put({ userEmail: email, folder, uid: message.uid, message })

    const idx = (await reqPromise(indexStore.get([email, folder]))) || {
      userEmail: email,
      folder,
      uidvalidity: null,
      uidnext: null,
      uids: [],
      pagination: { page: 1, pages: 1, total: 0, limit: 50 },
      updatedAt: Date.now(),
    }
    // Prepend if not already present (newest-first list view).
    if (!idx.uids.includes(message.uid)) {
      idx.uids = [message.uid, ...idx.uids]
      if (idx.pagination) idx.pagination.total = (idx.pagination.total || 0) + 1
      idx.updatedAt = Date.now()
      indexStore.put(idx)
    }
    return await txDone(t)
  } catch (e) {
    console.warn('[offlineMailbox] addMessage failed:', e)
    return false
  }
}

// ============================================================================
// Bodies (30-day window, hard-capped)
// ============================================================================

/**
 * Returns the cached body for a message, or null. Auto-evicts entries
 * whose cachedAt is older than the 30-day window.
 */
export async function getOfflineMessageBody(folder, uid, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder || !Number.isFinite(uid)) return null
  const db = await openDB()
  if (!db) return null
  try {
    const store = tx(db, STORES.bodies).objectStore(STORES.bodies)
    const row = await reqPromise(store.get([email, folder, uid]))
    if (!row) return null
    if (row.cachedAt && (Date.now() - row.cachedAt) > BODY_AGE_LIMIT_MS) {
      // Pruning happens lazily on read.
      try {
        const delT = tx(db, STORES.bodies, 'readwrite')
        delT.objectStore(STORES.bodies).delete([email, folder, uid])
      } catch (_e) {}
      return null
    }
    return row.body || null
  } catch (e) {
    console.warn('[offlineMailbox] getOfflineMessageBody failed:', e)
    return null
  }
}

/**
 * Cache a body. Refuses to store bodies whose message date is older
 * than 30 days. Enforces a hard per-account body count with simple
 * LRU eviction on cachedAt.
 *
 * @param {string} folder
 * @param {number} uid
 * @param {object} body                  - shape decided by caller (html, text, attachments_meta, ...)
 * @param {number|string|Date} [dateOfMessage] - the message's Date header
 */
export async function setOfflineMessageBody(folder, uid, body, dateOfMessage, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder || !Number.isFinite(uid) || !body) return false

  // Window: skip messages older than 30 days outright.
  let dateMs = null
  if (dateOfMessage) {
    const d = dateOfMessage instanceof Date ? dateOfMessage : new Date(dateOfMessage)
    if (!isNaN(d.getTime())) dateMs = d.getTime()
  }
  if (dateMs !== null && (Date.now() - dateMs) > BODY_AGE_LIMIT_MS) {
    return false
  }

  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, STORES.bodies, 'readwrite')
    const store = t.objectStore(STORES.bodies)
    store.put({
      userEmail: email,
      folder,
      uid,
      body,
      dateOfMessage: dateMs,
      cachedAt: Date.now(),
    })
    await txDone(t)
    // Best-effort LRU eviction.
    await pruneBodiesIfOverCap(db, email).catch(() => {})
    return true
  } catch (e) {
    console.warn('[offlineMailbox] setOfflineMessageBody failed:', e)
    return false
  }
}

async function pruneBodiesIfOverCap(db, email) {
  try {
    const t = tx(db, STORES.bodies)
    const idx = t.objectStore(STORES.bodies).index('byUser')
    const rows = await reqPromise(idx.getAll(email))
    if (!rows || rows.length <= BODY_HARD_CAP) return
    rows.sort((a, b) => (a.cachedAt || 0) - (b.cachedAt || 0))
    const toDelete = rows.slice(0, rows.length - BODY_HARD_CAP)
    if (!toDelete.length) return
    const wt = tx(db, STORES.bodies, 'readwrite')
    const ws = wt.objectStore(STORES.bodies)
    for (const r of toDelete) {
      ws.delete([r.userEmail, r.folder, r.uid])
    }
    await txDone(wt)
  } catch (e) {
    console.warn('[offlineMailbox] pruneBodiesIfOverCap failed:', e)
  }
}

// ============================================================================
// Folder visit tracking (powers smart prefetch top-10)
// ============================================================================

export async function recordFolderVisit(folder, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder) return false
  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, STORES.activity, 'readwrite')
    const store = t.objectStore(STORES.activity)
    const row = (await reqPromise(store.get([email, folder]))) || {
      userEmail: email,
      folder,
      visitCount: 0,
      lastVisitedAt: 0,
    }
    row.visitCount = (row.visitCount || 0) + 1
    row.lastVisitedAt = Date.now()
    store.put(row)
    return await txDone(t)
  } catch (e) {
    console.warn('[offlineMailbox] recordFolderVisit failed:', e)
    return false
  }
}

/**
 * Returns the user's top N most-recently-visited folders, newest first.
 * Excludes folders explicitly listed in `exclude`.
 */
export async function getTopRecentFolders(limit = 10, exclude = [], userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email) return []
  const db = await openDB()
  if (!db) return []
  try {
    const store = tx(db, STORES.activity).objectStore(STORES.activity)
    const all = await reqPromise(store.getAll())
    const excludeSet = new Set((exclude || []).map(s => (s || '').toLowerCase()))
    return all
      .filter(r => r.userEmail === email && r.folder && !excludeSet.has(r.folder.toLowerCase()))
      .sort((a, b) => (b.lastVisitedAt || 0) - (a.lastVisitedAt || 0))
      .slice(0, limit)
      .map(r => r.folder)
  } catch (e) {
    console.warn('[offlineMailbox] getTopRecentFolders failed:', e)
    return []
  }
}

// ============================================================================
// Wipe operations
// ============================================================================

/**
 * Wipes all cached data for a single folder (used on UIDVALIDITY drift).
 */
export async function wipeFolderCache(folder, userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email || !folder) return false
  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, [STORES.folderIndex, STORES.messages, STORES.bodies], 'readwrite')
    t.objectStore(STORES.folderIndex).delete([email, folder])
    // Delete messages and bodies for this folder via index range.
    const msgIdx = t.objectStore(STORES.messages).index('byFolder')
    const cursorReq = msgIdx.openCursor(IDBKeyRange.only([email, folder]))
    cursorReq.onsuccess = (ev) => {
      const c = ev.target.result
      if (c) {
        t.objectStore(STORES.messages).delete(c.primaryKey)
        c.continue()
      }
    }
    // Bodies have no folder index; iterate the limited byUser range and
    // delete matches. Bounded by BODY_HARD_CAP so it's cheap.
    const bodyIdx = t.objectStore(STORES.bodies).index('byUser')
    const bcReq = bodyIdx.openCursor(IDBKeyRange.only(email))
    bcReq.onsuccess = (ev) => {
      const c = ev.target.result
      if (c) {
        if (c.value.folder === folder) {
          t.objectStore(STORES.bodies).delete(c.primaryKey)
        }
        c.continue()
      }
    }
    return await txDone(t)
  } catch (e) {
    console.warn('[offlineMailbox] wipeFolderCache failed:', e)
    return false
  }
}

/**
 * Wipes ALL cached data for an account (used on logout).
 */
export async function wipeAccountCache(userEmailOverride) {
  const email = activeEmail(userEmailOverride)
  if (!email) return false
  const db = await openDB()
  if (!db) return false
  try {
    const t = tx(db, Object.values(STORES), 'readwrite')
    t.objectStore(STORES.folders).delete(email)
    for (const storeName of [STORES.folderIndex, STORES.messages, STORES.activity]) {
      const store = t.objectStore(storeName)
      const cursorReq = store.openCursor()
      cursorReq.onsuccess = (ev) => {
        const c = ev.target.result
        if (c) {
          // Compound keys -- first element is userEmail.
          if (Array.isArray(c.primaryKey) && c.primaryKey[0] === email) {
            store.delete(c.primaryKey)
          }
          c.continue()
        }
      }
    }
    const bodyIdx = t.objectStore(STORES.bodies).index('byUser')
    const bcReq = bodyIdx.openCursor(IDBKeyRange.only(email))
    bcReq.onsuccess = (ev) => {
      const c = ev.target.result
      if (c) {
        t.objectStore(STORES.bodies).delete(c.primaryKey)
        c.continue()
      }
    }
    return await txDone(t)
  } catch (e) {
    console.warn('[offlineMailbox] wipeAccountCache failed:', e)
    return false
  }
}

// ============================================================================
// Transaction helper
// ============================================================================

function txDone(t) {
  return new Promise((resolve) => {
    t.oncomplete = () => resolve(true)
    t.onerror = () => {
      console.warn('[offlineMailbox] tx error:', t.error)
      resolve(false)
    }
    t.onabort = () => {
      console.warn('[offlineMailbox] tx aborted:', t.error)
      resolve(false)
    }
  })
}

// ============================================================================
// Compatibility shims expected by existing call sites
// ============================================================================

/**
 * Wraps an online call with an optional offline fallback. On the web,
 * the fallback fires when the online call throws OR returns null.
 */
export async function withOfflineFallback(onlineCall, offlineCall) {
  try {
    const result = await onlineCall()
    if (result !== null && result !== undefined) return result
    if (typeof offlineCall === 'function') return await offlineCall()
    return result
  } catch (e) {
    if (typeof offlineCall === 'function') {
      try {
        return await offlineCall()
      } catch (_e2) {
        throw e
      }
    }
    throw e
  }
}

// Single-message fetch shim. Kept null for now; bodies are read via
// getOfflineMessageBody directly from the mailbox store.
export async function getOfflineMessage() { return null }
export async function fetchEmailBody() { return null }

// Status / sync no-op shims (kept for binary-compat with the Electron API).
export async function shouldUseOffline() { return false }
export async function canUseOfflineFallback() {
  return isIndexedDBSupported()
}
export async function getSyncStatus() {
  return { isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true, pendingCount: 0 }
}
export async function triggerSync() { return false }
export async function syncEmailBodies() { return { synced: 0, total: 0 } }
export async function getEmailsNeedingBodies() { return 0 }
export async function prepareForOffline() { return false }

// ============================================================================
// Default export
// ============================================================================

export default {
  isElectron,
  setActiveUserEmail,
  getActiveUserEmail,

  // Folder list
  getOfflineFolders,
  setOfflineFolders,

  // Message list
  getOfflineMessages,
  setOfflineMessages,
  patchMessage,
  removeMessage,
  addMessage,

  // Bodies
  getOfflineMessageBody,
  setOfflineMessageBody,

  // Activity / prefetch
  recordFolderVisit,
  getTopRecentFolders,

  // Wipes
  wipeFolderCache,
  wipeAccountCache,

  // Compatibility shims
  withOfflineFallback,
  getOfflineMessage,
  fetchEmailBody,
  shouldUseOffline,
  canUseOfflineFallback,
  getSyncStatus,
  triggerSync,
  syncEmailBodies,
  getEmailsNeedingBodies,
  prepareForOffline,
}
