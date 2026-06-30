import { defineStore } from "pinia";
import { ref, reactive, computed, watch } from "vue";
import api from "@/services/api";
import { useFiltersStore } from "@/stores/filters";
import { useConversationsStore } from "@/stores/conversations";
import { useAccountsStore } from "@/stores/accounts";
import { useConversationGrouping } from "@/composables/useConversationGrouping";
import { isDebugEnabled } from "@/utils/debug";
import { newOpId } from "@/utils/opId";
import {
  withOfflineFallback,
  getOfflineFolders,
  getOfflineMessages,
  getOfflineMessage,
  setOfflineFolders,
  setOfflineMessages,
  patchMessage as patchOfflineMessage,
  removeMessage as removeOfflineMessage,
  addMessage as addOfflineMessage,
  getOfflineMessageBody,
  setOfflineMessageBody,
  recordFolderVisit,
  getTopRecentFolders,
  wipeFolderCache as wipeOfflineFolderCache,
  setActiveUserEmail as setOfflineActiveUserEmail,
} from "@/services/offlineMailbox";
import { makeMessageKey } from "@/services/folderIdentityService";
import { materializeGroups } from "@/services/folderGroupingService";
import { canonicalFolderRoutingMode, folderCollectionUrl, folderResourceUrl } from "@/services/mailRouteService";
import notificationSounds from "@/services/notificationSounds";

// Track events silently (non-blocking)
async function trackEvent(eventType, eventData = {}) {
  try {
    await api.post("/statistics/log-event", {
      event_type: eventType,
      event_data: eventData,
    });
  } catch (e) {
    // Silent fail - don't disrupt main functionality
  }
}

export const useMailboxStore = defineStore("mailbox", () => {
  // Returns the email of the currently active mailbox account, used to
  // key per-user IndexedDB partitions. Returns null when nothing is
  // known yet (pre-login, or accounts store still warming up).
  function getCurrentUserEmail() {
    try {
      const accountsStore = useAccountsStore();
      const active = accountsStore?.activeAccount;
      if (active?.email) return active.email;
    } catch (_e) {}
    return null;
  }

  // Push the active email into the offline-cache module whenever it
  // becomes known. Cheap and idempotent.
  function syncActiveUserEmailToCache() {
    const email = getCurrentUserEmail();
    if (email) setOfflineActiveUserEmail(email);
  }

  // State
  const folders = ref([]);
  const currentFolder = ref("INBOX");
  const currentMessage = ref(null);
  // Selection uses composite keys: "folder:uid" to ensure uniqueness across folders
  // (IMAP UIDs are only unique within a folder, not globally)
  const selectedMessages = ref([]);

  // Wave 2 P2: per-account folder identity version. Bumped server-side on
  // every folder rename / move / delete. Hydrated from the bootstrap payload,
  // updated by every WebSocket FOLDER_CHANGED event, and re-fetched from
  // /mailbox/folders/identity-version on reconnect. A higher value than
  // ours means we missed at least one event and must invalidate folder
  // caches before serving stale data. 0 means "unknown" (Redis was down or
  // bootstrap pre-dates the field).
  const folderIdentityVersion = ref(0);

  // ====== CANONICAL MESSAGE STORE ======
  // A message exists once in memory. All views store only keys.
  const messagesByKey = reactive(new Map());   // "folder:uid" -> message object
  const folderViews = reactive(new Map());     // folder -> ordered key[]
  const conversationKeys = reactive(new Map()); // convId -> ordered key[]

  // RAM cap. Higher than before because IndexedDB now holds the cold
  // data; RAM is a hot subset. Eviction at 10k is rare in practice and
  // never costs anything because IndexedDB still has the cold record.
  const MESSAGES_BY_KEY_MAX = 10000;

  // Per-folder coalescing of IndexedDB writes. upsertMessages runs many
  // times in tight loops during a fetch; without this, every upsert
  // would queue its own IDB transaction. We instead batch within a
  // 150ms window and write once per folder.
  const _idbWriteQueue = new Map(); // folder -> { uids:Set, timer:number }
  function scheduleIndexedDBWrite(folder, uids) {
    if (!folder) return;
    let entry = _idbWriteQueue.get(folder);
    if (!entry) {
      entry = { uids: new Set(), timer: null };
      _idbWriteQueue.set(folder, entry);
    }
    for (const uid of uids) entry.uids.add(uid);
    if (entry.timer) return;
    entry.timer = setTimeout(() => {
      _idbWriteQueue.delete(folder);
      flushIndexedDBWriteForFolder(folder, entry.uids).catch(() => {});
    }, 150);
  }

  async function flushIndexedDBWriteForFolder(folder, _uidSet) {
    syncActiveUserEmailToCache();
    const folderObj = folders.value.find(f => f.name === folder) || null;
    const viewKeys = folderViews.get(folder) || [];
    const messagesForView = [];
    for (const k of viewKeys) {
      const m = messagesByKey.get(k);
      if (m) messagesForView.push(m);
    }
    if (!messagesForView.length) return;
    // Use the current pagination only when the active folder matches.
    const pageMeta = (currentFolder.value === folder && pagination?.value)
      ? pagination.value
      : { page: 1, pages: 1, total: messagesForView.length, limit: messagesForView.length };
    await setOfflineMessages(
      folder,
      messagesForView,
      pageMeta,
      folderObj?.uidvalidity || null,
      folderObj?.uidnext || null,
    );
  }

  // Fields where a "thinner" incoming value must never replace a richer cached
  // value. List-style endpoints used to ship a single-recipient `to` and an
  // empty `cc`; merging them blindly clobbered the full recipient arrays from
  // the single-message fetch and broke Reply-All. Even with the backend fix,
  // we keep this guard so any future thin payload (older server, search index,
  // websocket envelope, etc.) cannot regress recipients or bodies.
  const PRESERVE_IF_RICHER_FIELDS = new Set([
    'to',
    'cc',
    'bcc',
    'reply_to',
    'attachments',
    'body_html',
    'body_text',
  ]);

  function shouldKeepExisting(field, existingVal, incomingVal) {
    if (!PRESERVE_IF_RICHER_FIELDS.has(field)) return false;
    // Never accept null/undefined as a replacement
    if (incomingVal === undefined || incomingVal === null) return true;
    // For arrays: keep existing if it has more entries
    if (Array.isArray(existingVal) && Array.isArray(incomingVal)) {
      return existingVal.length > incomingVal.length;
    }
    // For strings (body_html, body_text): keep existing if it's longer and
    // incoming is empty. Don't second-guess non-empty body updates because
    // sanitization/edits legitimately shorten bodies.
    if (typeof existingVal === 'string' && typeof incomingVal === 'string') {
      return existingVal.length > 0 && incomingVal.length === 0;
    }
    return false;
  }

  /**
   * Compose the canonical store key for a message.
   *
   * Wave 2 contract: when the backend sends folder_id (UUIDv7), we key by
   * folder_id so renames don't lose the message in the cache. The legacy
   * `folder:uid` form is still accepted on read to keep the dual-write
   * window working.
   *
   * Read order:
   *   1. folder_id:uid    (preferred; rename-safe)
   *   2. folder:uid       (legacy; pre-folder-id and dual-write fallback)
   */
  function buildMessageKey(folderId, folder, uid) {
    return makeMessageKey(folderId, folder, uid);
  }
  function legacyMessageKey(folder, uid) {
    return makeMessageKey(null, folder, uid);
  }

  /**
   * Single entry point for all message data entering the store.
   * Updates existing objects in-place to preserve Vue reactivity references.
   *
   * As of the Phase 2 DB-as-truth refactor, the server is the single
   * source of truth for flag state: every read endpoint is fed from
   * MariaDB (which is updated transactionally before the HTTP response
   * returns), so this function no longer needs the Gmail "stale read"
   * guard nor the `pendingFlags` re-apply logic that used to defend
   * optimistic UI against eventually-consistent IMAP reads. The
   * optimistic update written by setFlag/move/delete and the
   * authoritative server payload received from CONDSTORE delta and
   * FLAGS_CHANGED are now both consistent with the DB.
   */
  function upsertMessage(msg, folder = null) {
    const f = folder || msg.folder || currentFolder.value;
    const fid = msg.folder_id || null;
    const key = buildMessageKey(fid, f, msg.uid);

    // Legacy-key migration: when we receive a folder_id for a message we've
    // previously stored under the folder:uid key, move the entry to the new
    // key in place to preserve identity for the rest of the request.
    if (fid) {
      const legacyKey = legacyMessageKey(f, msg.uid);
      if (legacyKey !== key && messagesByKey.has(legacyKey) && !messagesByKey.has(key)) {
        messagesByKey.set(key, messagesByKey.get(legacyKey));
        messagesByKey.delete(legacyKey);
      }
    }

    // Don't resurrect a message the user just deleted/moved. A refetch or
    // /delta that raced the optimistic removal would otherwise flicker the
    // row back in until server-side sync caught up. We guard BOTH branches:
    // even if a stale entry is still present, a tombstone means the user's
    // delete is authoritative until the server confirms it (which clears the
    // tombstone). We still return the key so callers that track ordering keep
    // working; the `messages` computed simply skips keys with no backing
    // entry in messagesByKey.
    if (isMessageTombstoned(f, msg.uid)) {
      if (messagesByKey.has(key)) messagesByKey.delete(key);
      return key;
    }

    const existing = messagesByKey.get(key);
    if (existing) {
      for (const [k, v] of Object.entries(msg)) {
        if (shouldKeepExisting(k, existing[k], v)) continue;
        if (existing[k] !== v) existing[k] = v;
      }
      if (!existing.folder) existing.folder = f;
      if (fid && !existing.folder_id) existing.folder_id = fid;
    } else {
      messagesByKey.set(key, { ...msg, folder: f, folder_id: fid });
    }
    return key;
  }

  /**
   * Upsert a batch of messages and return ordered keys.
   */
  function upsertMessages(msgs, folder = null) {
    const keys = [];
    // Track which folders we touched so we can schedule one debounced
    // IndexedDB write-through per folder (instead of N transactions).
    const touchedFolders = new Map(); // folder -> Set<uid>
    for (const msg of msgs) {
      const f = folder || msg.folder || currentFolder.value;
      const key = upsertMessage(msg, f);
      keys.push(key);
      if (f && Number.isFinite(msg.uid)) {
        let set = touchedFolders.get(f);
        if (!set) { set = new Set(); touchedFolders.set(f, set); }
        set.add(msg.uid);
      }
    }
    // Schedule write-through (debounced) for each folder that received messages.
    for (const [f, uidSet] of touchedFolders) {
      scheduleIndexedDBWrite(f, uidSet);
    }
    return keys;
  }

  /**
   * Evict messages not referenced by current views.
   * Only runs when messagesByKey exceeds the size threshold.
   */
  function evictUnprotectedMessages() {
    if (messagesByKey.size <= MESSAGES_BY_KEY_MAX) return;
    const protectedKeys = new Set();
    // Protect current folder view
    const currentViewKeys = folderViews.get(currentFolder.value);
    if (currentViewKeys) for (const k of currentViewKeys) protectedKeys.add(k);
    // Protect current conversation
    if (currentMessage.value?.conversationKey) {
      const convKeys = conversationKeys.get(currentMessage.value.conversationKey);
      if (convKeys) for (const k of convKeys) protectedKeys.add(k);
    }
    let evicted = 0;
    for (const key of messagesByKey.keys()) {
      if (!protectedKeys.has(key)) {
        messagesByKey.delete(key);
        evicted++;
        if (messagesByKey.size <= MESSAGES_BY_KEY_MAX * 0.75) break;
      }
    }
    // Clean stale keys from non-current folder views
    if (evicted > 0) {
      for (const [folder, keys] of folderViews.entries()) {
        if (folder === currentFolder.value) continue;
        const cleaned = keys.filter(k => messagesByKey.has(k));
        if (cleaned.length !== keys.length) {
          if (cleaned.length === 0) folderViews.delete(folder);
          else folderViews.set(folder, cleaned);
        }
      }
    }
  }

  /**
   * Clear messages for current folder view (replaces external `mailbox.messages = []`).
   */
  function clearMessages() {
    folderViews.set(currentFolder.value, []);
    conversationsRefreshTrigger.value++;
  }

  // Target-folder-scoped view reset. Use this instead of `mailbox.messages = []`
  // when the caller wants to wipe a *specific* folder's cached view -- e.g.
  // FolderTree pre-clearing ALL_MAIL before fetchAllMail() runs. The writable
  // `messages` setter writes to currentFolder.value which is the WRONG folder
  // when called pre-navigation: that variant ends up wiping the source folder's
  // cached view, causing getHighestUid() to return 0 and forcing the next
  // background-sync fetch to fall back to a full page-1 reload that
  // synchronously yanks currentFolder back to the source. See the
  // "jumps back to Inbox" race fix.
  function clearFolderView(folderName) {
    if (!folderName) return;
    folderViews.set(folderName, []);
    conversationsRefreshTrigger.value++;
  }

  /**
   * Derived messages array for the current folder.
   * External consumers read this via `mailbox.messages`.
   * Writable setter handles `mailbox.messages = []` for backward compatibility.
   */
  const messages = computed({
    get() {
      const keys = folderViews.get(currentFolder.value);
      if (!keys || keys.length === 0) return [];
      const result = [];
      for (const k of keys) {
        const msg = messagesByKey.get(k);
        if (msg) result.push(msg);
      }
      return result;
    },
    set(val) {
      if (!val || val.length === 0) {
        folderViews.set(currentFolder.value, []);
      } else {
        const keys = upsertMessages(val, currentFolder.value);
        folderViews.set(currentFolder.value, keys);
      }
      conversationsRefreshTrigger.value++;
    }
  });

  // Phase 2 DB-as-truth refactor: the pendingFlags optimistic-protection
  // map has been removed. Server writes are now DB-transactional and
  // committed before the HTTP response returns, so the brief
  // eventual-consistency window that pendingFlags used to defend against
  // can no longer occur. Optimistic updates remain (for UI snappiness)
  // but they are no longer "protected" because nothing stale can land.

  // Local-action removal tracking.
  //
  // Every move/delete that already adjusted the local view (via
  // removeMessageFromList) bumps a per-folder counter. When the server's
  // FOLDER_COUNTS event arrives reporting that total decreased, the sync
  // integration layer consumes from this counter to decide whether a full
  // page-1 refetch is needed.
  //
  // Without this, every move triggers a debouncedFetchMessages that
  // REPLACES folderViews with the page-1 result, destroying scroll
  // position and making older months "disappear" when the user is
  // scrolled down. With this, locally-driven decreases are accounted for
  // by the optimistic update alone, and only genuinely-remote decreases
  // (cross-device action) trigger a refresh.
  //
  // Stale entries are bounded by REMOVAL_TTL so a missing FOLDER_COUNTS
  // event can never permanently suppress refreshes for a folder.
  const localPendingRemovals = ref({}); // {folder: {count, expiresAt}}
  const REMOVAL_TTL = 30000; // 30s -- longer than any reasonable WS round trip

  // UID-level deletion tombstones.
  //
  // When the user deletes/moves a message we optimistically drop it from the
  // list (removeMessageFromList) AND record a (folder,uid) tombstone here.
  // upsertMessage refuses to re-insert a tombstoned key, so a refetch or a
  // /delta that *raced* the optimistic removal can no longer flicker the row
  // back in (the "disappears -> reappears -> disappears" bug). Tombstones
  // self-expire after REMOVAL_TTL, and are cleared the moment the server
  // confirms the deletion via /delta deletedUids -- so a genuine
  // re-delivery later is never permanently hidden.
  const deletedTombstones = new Map(); // "folder:uid" -> expiresAt (ms)

  function tombstoneKeyOf(folder, uid) {
    return `${folder || currentFolder.value}:${Number(uid)}`;
  }

  function tombstoneMessage(folder, uid) {
    if (uid === null || uid === undefined) return;
    // Opportunistic prune so a long session can't grow this unbounded.
    if (deletedTombstones.size > 500) {
      const now = Date.now();
      for (const [k, exp] of deletedTombstones) {
        if (now >= exp) deletedTombstones.delete(k);
      }
    }
    deletedTombstones.set(tombstoneKeyOf(folder, uid), Date.now() + REMOVAL_TTL);
  }

  function isMessageTombstoned(folder, uid) {
    const k = tombstoneKeyOf(folder, uid);
    const exp = deletedTombstones.get(k);
    if (!exp) return false;
    if (Date.now() >= exp) {
      deletedTombstones.delete(k);
      return false;
    }
    return true;
  }

  function clearMessageTombstone(folder, uid) {
    deletedTombstones.delete(tombstoneKeyOf(folder, uid));
  }

  function markLocalRemoval(folder, count = 1) {
    if (!folder || count <= 0) return;
    const existing = localPendingRemovals.value[folder];
    const now = Date.now();
    const baseCount = (existing && now < existing.expiresAt) ? existing.count : 0;
    localPendingRemovals.value = {
      ...localPendingRemovals.value,
      [folder]: { count: baseCount + count, expiresAt: now + REMOVAL_TTL },
    };
  }

  /**
   * Consume up to `count` pending local removals for a folder.
   * Returns the number actually consumed.
   */
  function consumeLocalRemovals(folder, count = 1) {
    if (!folder || count <= 0) return 0;
    const existing = localPendingRemovals.value[folder];
    if (!existing) return 0;
    if (Date.now() >= existing.expiresAt) {
      const copy = { ...localPendingRemovals.value };
      delete copy[folder];
      localPendingRemovals.value = copy;
      return 0;
    }
    const consumed = Math.min(existing.count, count);
    const remaining = existing.count - consumed;
    if (remaining <= 0) {
      const copy = { ...localPendingRemovals.value };
      delete copy[folder];
      localPendingRemovals.value = copy;
    } else {
      localPendingRemovals.value = {
        ...localPendingRemovals.value,
        [folder]: { count: remaining, expiresAt: existing.expiresAt },
      };
    }
    return consumed;
  }

  /**
   * Roll back a previously-marked removal (e.g. when an API call fails
   * after we optimistically bumped the counter).
   */
  function unmarkLocalRemoval(folder, count = 1) {
    if (!folder || count <= 0) return;
    consumeLocalRemovals(folder, count);
  }

  /**
   * Read-only check: does this folder currently have any pending
   * local removals (not yet consumed by FOLDER_COUNTS)? Stale entries
   * past their TTL are treated as absent.
   */
  function hasPendingLocalRemovals(folder) {
    if (!folder) return false;
    const entry = localPendingRemovals.value[folder];
    if (!entry) return false;
    if (Date.now() >= entry.expiresAt) return false;
    return entry.count > 0;
  }

  /**
   * Detect whether the user's currently-connected account is an OAuth
   * provider (Gmail / Microsoft). Used to gate OAuth-specific defenses
   * (page-1 stability guard, UIDVALIDITY-flush suppression) so that
   * regular IMAP accounts (Dovecot etc) are completely unaffected.
   *
   * Detection order:
   *  1. Active SECONDARY account flagged as is_oauth or auth_type=oauth
   *     -- authoritative for users who added a Gmail/Microsoft account
   *     on top of their primary login.
   *  2. Folder-name heuristic ([Gmail]/ or [Google Mail]/ prefix) --
   *     covers the case where the PRIMARY login itself is an OAuth
   *     connection (the accounts store has no entry for primary).
   *
   * Both checks are cheap and side-effect-free, suitable for hot paths.
   */
  function isCurrentAccountOAuth() {
    try {
      const accountsStore = useAccountsStore();
      const active = accountsStore?.activeAccount;
      if (active?.is_oauth === true) return true;
      if (active?.auth_type === "oauth") return true;
    } catch (e) {
      // Accounts store unavailable -- fall through to heuristic.
    }
    // Heuristic fallback: Gmail-namespaced folders mean we're talking
    // to Gmail's IMAP backend, regardless of how the user logged in.
    for (const f of folders.value) {
      const name = f?.name || "";
      if (name.startsWith("[Gmail]/") || name.startsWith("[Google Mail]/")) {
        return true;
      }
    }
    return false;
  }

  // Helper: Create composite selection key
  function makeSelectionKey(folder, uid) {
    return `${folder}:${uid}`;
  }

  // Helper: Parse composite selection key
  function parseSelectionKey(key) {
    const idx = key.lastIndexOf(':');
    if (idx === -1) return { folder: currentFolder.value, uid: parseInt(key) };
    return {
      folder: key.substring(0, idx),
      uid: parseInt(key.substring(idx + 1))
    };
  }

  // Helper: Get folder for a message (from message or currentFolder)
  function getMessageFolder(message) {
    return message?.folder || currentFolder.value;
  }


  // O(1) message lookup via the canonical messagesByKey map.
  // For virtual folders (ALL_MAIL, SEARCH_RESULTS), scans by UID with optional folderHint.
  function findMessageByUid(uid, folder = null, folderHint = null) {
    const f = folder || currentFolder.value;
    const numUid = Number(uid);

    if (f === 'ALL_MAIL' || f === 'SEARCH_RESULTS') {
      if (folderHint && folderHint !== 'ALL_MAIL' && folderHint !== 'SEARCH_RESULTS') {
        const direct = messagesByKey.get(`${folderHint}:${numUid}`);
        if (direct) return direct;
      }
      const suffix = `:${numUid}`;
      let fallback = null;
      for (const [key, msg] of messagesByKey.entries()) {
        if (key.endsWith(suffix) && Number(msg.uid) === numUid) {
          if (folderHint && msg.folder === folderHint) return msg;
          if (!fallback) fallback = msg;
        }
      }
      return fallback;
    }

    return messagesByKey.get(`${f}:${numUid}`) || null;
  }

  // Helper: Resolve the actual IMAP folder for a message in virtual folder contexts
  function resolveMessageFolder(uid, folder = null) {
    const provided = folder || currentFolder.value;
    if (provided !== 'ALL_MAIL' && provided !== 'SEARCH_RESULTS') {
      return provided;
    }
    const msg = findMessageByUid(uid, provided);
    return msg?.folder || null;
  }

  // Check if a message is selected
  function isMessageSelected(uid, folder = null) {
    const f = folder || currentFolder.value;
    return selectedMessages.value.includes(makeSelectionKey(f, uid));
  }
  // Default to conversation view, persist in localStorage
  const conversationView = ref(
    localStorage.getItem("conversationView") !== "false"
  ); // Default true
  const expandedConversations = ref(new Set()); // Track which conversations are expanded
  const scrollToMessageUid = ref(null); // Track which message to scroll to in conversation view

  const pagination = ref({
    page: 1,
    pages: 0,
    total: 0,
    limit: 50,
  });

  const loading = ref({
    folders: false,
    messages: false,
    message: false,
    // True only during a manual/background revalidation when the list already has data.
    // Drives the refresh icon spin without triggering the full-area "first load" spinner.
    refreshing: false,
  });

  // Timestamp of last successful initMailbox (used by WS to skip redundant refetches)
  const lastInitAt = ref(0)

  // Bulk operation progress
  const bulkProgress = ref({
    active: false,
    current: 0,
    total: 0,
    action: "", // 'delete', 'move', etc.
  });

  // Pinned emails (stored in DB, not IMAP)
  const pinnedEmails = ref([]); // Array of { folder, uid, message_id, subject, pinned_at }
  const pinnedEmailsLoaded = ref(false);


  // All Mail grouping mode: 'date' or 'folder'
  const allMailGroupMode = ref(localStorage.getItem('allMailGroupMode') || 'date');
  
  function setAllMailGroupMode(mode) {
    allMailGroupMode.value = mode;
    localStorage.setItem('allMailGroupMode', mode);
  }

  // Folders the most recent All Mail scan could not fully read. Each entry
  // matches the backend `degraded_folders[]` payload shape (state,
  // last_attempt_at, retry_after, failure_reason, fallback_stage,
  // request_id). Empty array == healthy.
  const allMailDegraded = ref([]);
  // True when the user dismissed the banner; reset on next degraded payload.
  const allMailDegradedDismissed = ref(false);
  function dismissAllMailDegraded() {
    allMailDegradedDismissed.value = true;
  }

  // Wave 3 sidebar grouping. User-defined groups are loaded from a future
  // /folder-groups endpoint; default to []. The materialized tree is
  // re-computed reactively from the canonical folder list and the user's
  // group config.
  const folderGroupsConfig = ref([]);
  const folderGroups = computed(() => materializeGroups(folders.value, folderGroupsConfig.value));
  function setFolderGroupsConfig(config) {
    folderGroupsConfig.value = Array.isArray(config) ? config : [];
  }
  // Wave 3 routing flag. Single source of truth for "use canonical
  // /m/slug--folder_id routes". Defaults to 'off' until the four-counter
  // telemetry gate clears.
  const canonicalRoutingMode = computed(() => canonicalFolderRoutingMode());

  // Getters
  const unreadCount = computed(() => {
    const inbox = folders.value.find((f) => f.name === "INBOX");
    return inbox?.unread || 0;
  });

  const currentFolderData = computed(() => {
    return folders.value.find((f) => f.name === currentFolder.value) || null;
  });

  // Local trigger that forces conversations computed to re-evaluate
  const conversationsRefreshTrigger = ref(0);

  // Conversations store (needed for conversation operations throughout the store)
  const conversationsStore = useConversationsStore();

  // Conversation grouping extracted to composable (includes watch on conversationsStore.updateVersion)
  const { conversations } = useConversationGrouping(
    messagesByKey, folderViews, currentFolder, conversationView, conversationKeys, conversationsRefreshTrigger
  );

  // Actions

  /**
   * Single-request mailbox init: fetches folders + INBOX page 1 + conversations
   * from GET /mailbox/init. Replaces separate fetchFolders + fetchMessages on first load.
   */
  async function initMailbox() {
    syncActiveUserEmailToCache()

    // Hydrate-render-revalidate: try IndexedDB FIRST so the UI shows
    // folder list + INBOX immediately, even on a cold login over a
    // slow connection. If the cache is empty we keep the spinner up
    // until the network response arrives.
    //
    // IMPORTANT: we hydrate folder STRUCTURE (names, hierarchy,
    // SPECIAL-USE flags, identity) from IDB but DELIBERATELY zero out
    // the count fields (unread / total / uidnext / uidvalidity). The
    // cached counts can be minutes/hours stale; rendering them
    // produces the "INBOX shows 3 unread for a moment then drops to
    // 0" flicker. We let the network (which IS authoritative) fill
    // them in moments later. The sidebar shows folder names instantly
    // and badges appear once they are known to be correct.
    let cacheHydrated = false
    try {
      const cachedFolders = await getOfflineFolders()
      const cachedInbox = await getOfflineMessages('INBOX', 1, 50)
      if (cachedFolders?.length) {
        folders.value = cachedFolders.map(f => ({
          ...f,
          unread: 0,
          total: 0,
          uidnext: null,
          uidvalidity: null,
          _countsHydrating: true,
        }))
        if (cachedInbox?.messages?.length) {
          currentFolder.value = 'INBOX'
          const keys = upsertMessages(cachedInbox.messages, 'INBOX')
          folderViews.set('INBOX', keys)
          cachedInbox.messages.forEach(m => seenMessageUids.value.add(`INBOX:${m.uid}`))
          if (cachedInbox.page || cachedInbox.pages || cachedInbox.total) {
            pagination.value = {
              page: cachedInbox.page || 1,
              pages: cachedInbox.pages || 1,
              total: cachedInbox.total || cachedInbox.messages.length,
              limit: cachedInbox.limit || 50,
            }
          }
        }
        // Drop spinners immediately so the cached state is what the
        // user sees. The network round-trip continues silently below.
        loading.value.folders = false
        loading.value.messages = false
        cacheHydrated = true
      }
    } catch (e) {
      console.warn('[Mailbox] IndexedDB hydrate failed; falling through to network', e)
    }

    if (!cacheHydrated) {
      loading.value.folders = true
      loading.value.messages = true
    }

    try {
      const result = await withOfflineFallback(
        async () => {
          const response = await api.get('/mailbox/init')
          if (!response.data?.success) return null
          return response.data.data
        },
        async () => {
          const offlineFolders = await getOfflineFolders()
          const offlineInbox = await getOfflineMessages('INBOX', 1, 50)
          if (!offlineFolders) return null
          return {
            folders: offlineFolders,
            messages: offlineInbox?.messages || [],
            pagination: offlineInbox ? {
              page: offlineInbox.page,
              pages: offlineInbox.pages,
              total: offlineInbox.total,
              limit: offlineInbox.limit,
            } : null,
            conversations: null,
            _offline: true,
          }
        }
      )

      if (!result) return cacheHydrated ? true : false
      const data = result

      if (data.folders) {
        folders.value = data.folders
        // Write-through so the next page load is instant.
        if (!data._offline) setOfflineFolders(data.folders).catch(() => {})
      }

      if (data.messages) {
        currentFolder.value = 'INBOX'
        const keys = upsertMessages(data.messages, 'INBOX')
        folderViews.set('INBOX', keys)
        data.messages.forEach(m => seenMessageUids.value.add(`INBOX:${m.uid}`))
      }

      if (data.pagination) {
        pagination.value = {
          page: data.pagination.page,
          pages: data.pagination.pages,
          total: data.pagination.total,
          limit: data.pagination.limit,
        }
      }

      if (data.conversations) {
        const conversationsStore = useConversationsStore()
        conversationsStore.setConversationsFromResponse('INBOX', data.conversations)
      }

      // Hydrate pinned emails from init response
      if (Array.isArray(data.pinned)) {
        pinnedEmails.value = data.pinned
        pinnedEmailsLoaded.value = true
      }

      // Hydrate scheduled count from init response
      if (data.scheduled_count !== undefined) {
        scheduledCount.value = data.scheduled_count
      }

      if (!data._offline && window.api?.db?.cacheEmails && data.messages?.length > 0) {
        window.api.db.cacheEmails('INBOX', data.messages).catch(() => {})
      }

      if (!data._offline) {
        prefetchRelatedFolderMessages('INBOX')
        // Smart prefetch of the user's most-used folders. Fires in
        // background; never blocks the UI.
        prefetchSmart().catch(() => {})
        lastInitAt.value = Date.now()
      }
      return data
    } catch (e) {
      console.error('[Mailbox] initMailbox failed:', e)
      return cacheHydrated ? true : false
    } finally {
      loading.value.folders = false
      loading.value.messages = false
    }
  }

  /**
   * Smart background prefetch after initMailbox. Warms up:
   *   - the standard folders (Sent/Drafts/Junk/Trash/Archive, identified
   *     by RFC 6154 SPECIAL-USE flag when present, else by name)
   *   - the user's top-10 most-recently-visited folders (from
   *     IndexedDB folder_activity)
   *
   * Fetches page 1 (50 messages) for each, persists to IndexedDB.
   * Parallelism is capped at 5 to avoid stampeding the backend.
   */
  async function prefetchSmart() {
    syncActiveUserEmailToCache()
    if (!folders.value?.length) return

    // 1) Identify standard folders.
    const STANDARD_FLAGS = new Set(['\\Sent', '\\Drafts', '\\Junk', '\\Trash', '\\Archive'])
    const STANDARD_NAME_HINTS = [
      'sent', 'drafts', 'junk', 'spam', 'trash', 'deleted', 'archive', 'all mail',
    ]
    const standardFolderNames = []
    for (const f of folders.value) {
      if (!f?.name || f.name === 'INBOX') continue
      const flags = Array.isArray(f.flags) ? f.flags : []
      const matchesFlag = flags.some(fl => STANDARD_FLAGS.has(fl))
      const matchesName = STANDARD_NAME_HINTS.some(h => f.name.toLowerCase().includes(h))
      if (matchesFlag || matchesName) standardFolderNames.push(f.name)
    }
    // Dedup, INBOX excluded (already loaded by init).
    const standardSet = new Set(standardFolderNames.slice(0, 8))

    // 2) Top-10 most-recently-visited folders.
    const exclude = ['INBOX', ...standardSet]
    const recent = await getTopRecentFolders(10, exclude).catch(() => [])
    // Cross-check against actual folder list to skip ghosts.
    const validNames = new Set(folders.value.map(f => f.name))
    const recentValid = recent.filter(n => validNames.has(n))

    const targets = [...standardSet, ...recentValid]
    if (!targets.length) return

    const CONCURRENCY = 5
    const queue = targets.slice()
    const workers = Array.from({ length: Math.min(CONCURRENCY, queue.length) }, async () => {
      while (queue.length) {
        const folder = queue.shift()
        if (!folder) break
        try {
          // Resolve to canonical /folders/{id}/messages URL. Skip the
          // folder when the id can't be resolved (folder list still
          // hydrating, or the folder vanished server-side) -- prevents
          // the literal "null" URL bug that surfaced as 404s on
          // /api/null/messages.
          const base = folderCollectionUrl(folders.value, folder, 'messages')
          if (!base) continue
          const url = `${base}?page=1&limit=50`
          const resp = await api.get(url)
          const payload = resp?.data?.data
          if (payload?.messages?.length) {
            const folderObj = folders.value.find(f => f.name === folder)
            await setOfflineMessages(
              folder,
              payload.messages,
              {
                page: payload.page || 1,
                pages: payload.pages || 1,
                total: payload.total || payload.messages.length,
                limit: payload.limit || 50,
              },
              folderObj?.uidvalidity || null,
              folderObj?.uidnext || null,
            )
          }
        } catch (e) {
          // Best-effort; never crash init on a single failing folder.
          isDebugEnabled() && console.warn('[Mailbox] prefetchSmart failed for', folder, e)
        }
      }
    })
    await Promise.allSettled(workers)
  }

  // IMAP is the single source of truth - always fetch from server.
  // Falls back to SQLite when the network is unavailable.
  async function fetchFolders(quietOrOptions = false) {
    const options = typeof quietOrOptions === 'boolean' 
      ? { quiet: quietOrOptions } 
      : { quiet: false, ...quietOrOptions };
    
    const { quiet } = options;

    // Only show loading on first load
    const hasData = folders.value.length > 0;
    if (!hasData && !quiet) {
      loading.value.folders = true;
    }
    
    try {
      const newFolders = await withOfflineFallback(
        async () => {
          const response = await api.get("/mailbox/folders");
          if (response.data.success) return response.data.data.folders;
          return null;
        },
        async () => {
          return await getOfflineFolders();
        }
      );

      if (newFolders) {
        if (folders.value.length > 0) {
          const newMap = new Map(newFolders.map(f => [f.name, f]));
          
          const existingNames = new Set(folders.value.map(f => f.name));
          const newNames = new Set(newFolders.map(f => f.name));
          const structureChanged = existingNames.size !== newNames.size || 
            [...existingNames].some(n => !newNames.has(n)) ||
            [...newNames].some(n => !existingNames.has(n));
          
          if (structureChanged) {
            folders.value = newFolders;
          } else {
            for (const folder of folders.value) {
              const updated = newMap.get(folder.name);
              if (updated) {
                folder.total = updated.total;
                folder.unread = updated.unread;
                folder.uidnext = updated.uidnext;
                folder.uidvalidity = updated.uidvalidity;
                // Backfill identity fields a freshly created folder lacks until
                // the server round-trip lands. Without folder_id, apiCollectionUrl()
                // resolves to null and clicking the folder silently no-ops.
                if (updated.folder_id && !folder.folder_id) folder.folder_id = updated.folder_id;
                if (updated.path && !folder.path) folder.path = updated.path;
              }
            }
          }
        } else {
          folders.value = newFolders;
        }
        // Write-through to IndexedDB so the next cold load is instant.
        syncActiveUserEmailToCache();
        setOfflineFolders(newFolders).catch(() => {});
      }
    } catch (e) {
      console.error("Failed to fetch folders:", e);
    } finally {
      loading.value.folders = false;
    }
  }

  // Remove a message from the canonical store and folder view
  function removeMessageFromList(uid, folder = null) {
    const targetFolder = folder || currentFolder.value;
    const nUid = Number(uid);
    // Record the tombstone unconditionally (even if the key isn't in the
    // map under this exact form) so a racing refetch/delta cannot re-add it.
    tombstoneMessage(targetFolder, uid);

    // Resolve EVERY store key that maps to (targetFolder, uid). A message can
    // be keyed either as the legacy `folder:uid` form or the rename-safe
    // `id:<uuid>:uid` form (folderIdentityService.makeMessageKey). If we only
    // matched the legacy form, an All Mail / search row stored under an id:
    // key would never actually leave messagesByKey -- and because
    // upsertMessage's tombstone gate only fires on the *new-insert* branch, a
    // refetch would update the still-present entry straight back into view
    // (the "delete -> reappears" bug in All Mail).
    const legacyKey = `${targetFolder}:${nUid}`;
    const keysToRemove = new Set();
    if (messagesByKey.has(legacyKey)) {
      keysToRemove.add(legacyKey);
    } else {
      for (const [k, m] of messagesByKey) {
        if (Number(m?.uid) === nUid && (m.folder || targetFolder) === targetFolder) {
          keysToRemove.add(k);
        }
      }
    }

    if (keysToRemove.size > 0) {
      // Splice the keys out of every view that referenced them -- the real
      // folder view AND the active virtual view (ALL_MAIL / SEARCH_RESULTS),
      // which both point at the same key strings.
      for (const [, viewKeys] of folderViews) {
        for (let i = viewKeys.length - 1; i >= 0; i--) {
          if (keysToRemove.has(viewKeys[i])) viewKeys.splice(i, 1);
        }
      }
      for (const k of keysToRemove) messagesByKey.delete(k);
      conversationsRefreshTrigger.value++;
    }
    if (currentMessage.value?.uid === uid && (!currentMessage.value.folder || currentMessage.value.folder === targetFolder)) {
      currentMessage.value = null;
    }
    pruneStaleSelections();
    // IndexedDB write-through so a deleted/moved message doesn't ghost
    // back in on next page reload.
    syncActiveUserEmailToCache();
    removeOfflineMessage(targetFolder, Number(uid)).catch(() => {});
  }

  // Prune selection keys that reference messages no longer in the list.
  // After deletes/moves, selectedMessages can hold stale composite keys
  // which cause the "select all" checkbox to appear checked incorrectly.
  function pruneStaleSelections() {
    if (selectedMessages.value.length === 0) return;
    const validKeys = new Set(
      messages.value.map((m) => makeSelectionKey(getMessageFolder(m), m.uid))
    );
    const before = selectedMessages.value.length;
    selectedMessages.value = selectedMessages.value.filter((k) => validKeys.has(k));
    if (selectedMessages.value.length !== before) {
      isDebugEnabled() && console.log('[Selection] Pruned stale keys:', before - selectedMessages.value.length);
    }
  }

  // Refresh folders in background (no loading indicator)
  function refreshFoldersQuietly() {
    fetchFolders(true);
  }

  async function createFolder(name, parent = null) {
    try {
      isDebugEnabled() && console.log('mailbox.createFolder called:', { name, parent })
      const response = await api.post("/mailbox/folders", { name, parent });
      isDebugEnabled() && console.log('mailbox.createFolder response:', response.data)
      if (response.data.success) {
        // Add folder locally without full reload (prevents flicker). Match the
        // real folder shape (path + folder_id) so Vue reactivity stays
        // consistent and fetchFolders' partial-update path can backfill the id.
        const fullName = parent ? `${parent}.${name}` : name;
        folders.value.push({
          name: fullName,
          path: fullName,
          folder_id: null,
          total: 0,
          unread: 0,
          type: "user",
        });

        // Sync with server NOW (not deferred) so the new folder gets its
        // folder_id before the user can click it. Without this the click
        // resolves a null URL and the fetch is queued forever.
        await fetchFolders(true);

        // Drain any fetch that was queued while folder_id was still null, so a
        // stale request can't survive indefinitely after the identity lands.
        const pending = pendingMessageFetch.value;
        pendingMessageFetch.value = null;
        if (pending) {
          fetchMessages(pending.folder, pending.page, pending.options).catch(() => {});
        }
        return true;
      }
      return false;
    } catch (e) {
      console.error("Failed to create folder:", e);
      return false;
    }
  }

  async function renameFolder(oldName, newName, newParent = undefined) {
    try {
      // A folder created moments ago has no folder_id until the background
      // fetchFolders() lands, so apiResourceUrl() resolves to null during that
      // window. Refresh once and retry rather than firing a null-URL request.
      let url = apiResourceUrl(oldName);
      if (!url) {
        await fetchFolders(true);
        url = apiResourceUrl(oldName);
        if (!url) {
          console.warn('[Mailbox] renameFolder: folder identity not ready yet for', oldName);
          return false;
        }
      }
      const data = { name: newName };
      if (newParent !== undefined) {
        data.parent = newParent;
      }
      const response = await api.put(url, data);
      if (response.data.success) {
        // Update folders locally without full reload (prevents flicker)
        const actualNewName =
          response.data.data?.newName ||
          response.data.data?.folder ||
          (newParent ? `${newParent}.${newName}` : newName);
        updateFolderNameLocally(oldName, actualNewName);

        // Migrate the canonical message store keys from oldName:* to
        // actualNewName:*. Without this, messages already cached in
        // memory keep their old folder prefix and become unreachable
        // until the next full refetch. The other-tab path already does
        // this in useMailSyncIntegration.handleFolderChanged via the WS
        // event; the originating tab needs the same fix-up locally
        // because it doesn't receive its own publishFolderChanged.
        try {
          renameFolderInStore(oldName, actualNewName);
        } catch (renameStoreError) {
          console.warn('[Mailbox] renameFolderInStore failed:', renameStoreError);
        }

        // Clear conversation store data for old folder
        try {
          const conversationsStore = useConversationsStore();
          conversationsStore.handleFolderRenamed(oldName, actualNewName);
        } catch (convError) {
          console.warn('[Mailbox] Failed to update conversation store after rename:', convError);
        }

        // Sync with server quietly in background (to get accurate counts, etc.)
        setTimeout(() => fetchFolders(true), 500);

        // Refresh filters if any were updated (backend updates filter folder references)
        if (response.data.data?.filters_updated > 0) {
          try {
            const filtersStore = useFiltersStore();
            await filtersStore.fetchFilters(true);
            isDebugEnabled() && console.log(
              `[Mailbox] Refreshed filters after folder rename (${response.data.data.filters_updated} updated)`
            );
          } catch (e) {
            console.warn("Failed to refresh filters after folder rename:", e);
          }
        }

        return true;
      }
      return false;
    } catch (e) {
      console.error("Failed to rename folder:", e);
      return false;
    }
  }

  // Update folder name locally without API call (for optimistic updates)
  function updateFolderNameLocally(oldName, newName) {
    // Safety check - ensure folders.value is an array
    if (!Array.isArray(folders.value)) {
      console.error("updateFolderNameLocally: folders.value is not an array");
      return;
    }

    // Create new folder objects to ensure Vue reactivity picks up changes
    const updatedFolders = folders.value.map((f) => {
      if (f.name === oldName) {
        // The folder being renamed
        return { ...f, name: newName };
      } else if (f.name.startsWith(oldName + ".")) {
        // Child folder - update the prefix
        const newChildName = newName + "." + f.name.slice(oldName.length + 1);
        return { ...f, name: newChildName };
      }
      return f;
    });

    folders.value = updatedFolders;
  }

  // Remove folder locally without API call (for optimistic updates)
  function removeFolderLocally(name) {
    // Remove the folder and all its children
    folders.value = folders.value.filter(
      (f) => f.name !== name && !f.name.startsWith(name + ".")
    );
  }

  async function deleteFolder(name) {
    try {
      // Same post-create window guard as renameFolder: resolve the folder_id
      // (refresh once if a brand-new folder hasn't synced) before issuing the
      // delete, so we never send a null URL.
      let url = apiResourceUrl(name);
      if (!url) {
        await fetchFolders(true);
        url = apiResourceUrl(name);
        if (!url) {
          console.warn('[Mailbox] deleteFolder: folder identity not ready yet for', name);
          return false;
        }
      }
      const response = await api.delete(url);
      if (response.data.success) {
        // Remove folder locally without full reload (prevents flicker)
        removeFolderLocally(name);

        // If we were viewing the deleted folder, switch to INBOX
        if (currentFolder.value === name) {
          await fetchMessages("INBOX");
        }

        // Sync with server quietly in background
        setTimeout(() => fetchFolders(true), 500);
        return true;
      }
      return false;
    } catch (e) {
      console.error("Failed to delete folder:", e);
      return false;
    }
  }

  // Track UIDs we've seen to detect new incoming messages
  const seenMessageUids = ref(new Set());

  // ========================================
  // FOLDER STATE MANAGEMENT (for instant switching)
  // ========================================

  /**
   * Clear a folder's UI and trigger refresh (used when emptying trash/spam)
   */
  async function clearFolderCompletely(folder) {
    folderViews.set(folder, []);
    if (currentFolder.value === folder) {
      pagination.value = { page: 1, pages: 0, total: 0, limit: 50 };
      expandedConversations.value = new Set();
    }
    conversationsRefreshTrigger.value++;
    await fetchFolders(true);
  }

  // smartMergeMessages removed -- replaced by upsertMessages() + folderViews

  // Scroll position tracking for UI (simple state, no IndexedDB)
  const folderScrollPositions = ref({});

  function setFolderScrollPosition(folder, scrollTop) {
    folderScrollPositions.value[folder] = scrollTop;
  }

  function getFolderScrollPosition(folder) {
    return folderScrollPositions.value[folder] || 0;
  }

  /** Per-folder epoch ms when list was last successfully synced (reactive for UI). */
  const lastRefreshedAtByFolder = reactive({});

  function touchLastRefreshed(folder) {
    if (!folder) return;
    lastRefreshedAtByFolder[folder] = Date.now();
  }

  function getLastRefreshed(folder) {
    if (!folder) return null;
    const t = lastRefreshedAtByFolder[folder];
    return typeof t === "number" ? t : null;
  }

  /** Full page-1 refresh of the currently selected folder (no list wipe; stale-while-revalidate). */
  async function refreshCurrentFolder() {
    const f = currentFolder.value;
    if (!f || f === "SEARCH_RESULTS") return;
    loading.value.refreshing = true;
    try {
      if (f === "ALL_MAIL") return await fetchAllMail(1);
      if (f === "SCHEDULED") return await fetchScheduledEmails();
      return await fetchMessages(f, 1);
    } finally {
      loading.value.refreshing = false;
    }
  }

  /**
   * UIDNEXT/UIDVALIDITY-aware revalidation for the active folder.
   * Used by tab visibility, background interval, and reconciliation.
   *
   * For the virtual ALL_MAIL view, the cheap path is: refresh the folder
   * list (already done by callers) and re-run the aggregated search.
   * The /mailbox/search?all_folders=true endpoint is a single round trip,
   * so this is acceptable on the periodic and reconciliation cadences.
   */
  async function revalidateActiveFolder() {
    const folder = currentFolder.value;
    if (!folder || folder === "SEARCH_RESULTS" || folder === "SCHEDULED") {
      return { skipped: true };
    }
    if (folder === "ALL_MAIL") {
      loading.value.refreshing = true;
      try {
        return await fetchAllMail(1);
      } finally {
        loading.value.refreshing = false;
      }
    }
    loading.value.refreshing = true;
    try {
      const syncState = await checkFolderSyncState(folder);
      if (!syncState) {
        return await fetchMessages(folder, 1);
      }

      const folderData = folders.value.find((fd) => fd.name === folder);
      if (folderData?.uidvalidity && syncState.uidvalidity && folderData.uidvalidity !== syncState.uidvalidity) {
        return await fetchMessages(folder, 1);
      }

      if (folderData) {
        if (syncState.exists !== undefined) folderData.total = syncState.exists;
        if (syncState.uidnext) folderData.uidnext = syncState.uidnext;
        if (syncState.uidvalidity) folderData.uidvalidity = syncState.uidvalidity;
      }

      const highest = getHighestUid(folder);
      if (highest > 0 && syncState.uidnext && syncState.uidnext <= highest + 1) {
        touchLastRefreshed(folder);
        return { unchanged: true };
      }

      if (highest > 0) {
        return await fetchMessagesSince(folder, highest);
      }
      return await fetchMessages(folder, 1);
    } catch (e) {
      console.warn("[Mailbox] revalidateActiveFolder failed:", e);
      return await fetchMessages(folder, 1);
    } finally {
      loading.value.refreshing = false;
    }
  }

  // Monotonic counter — incremented on every real fetchMessages call.
  // After each await we check that the token still matches; if not, a newer
  // request has already started (user switched folders) and we discard results.
  let fetchMessagesToken = 0

  // Holds a fetchMessages call that arrived before the folder list was
  // hydrated for the active account. The watcher below re-fires it once
  // folders.value transitions from empty -> populated, recovering the
  // early-fire race transparently.
  const pendingMessageFetch = ref(null)

  // Same race-guard for SINGLE-message fetches (deep links like
  // /email/:folder/message/:uid). The MailboxView route watcher runs
  // `immediate: true`, so on a hard reload the watcher fires before
  // initMailbox finishes hydrating folders.value. Without this slot,
  // fetchMessageFromFolder() would call apiCollectionUrl() too early,
  // get null back, and api.js would reject the request with
  // "URL is null/empty".
  const pendingSingleMessageFetch = ref(null)

  watch(
    () => folders.value.length,
    (count) => {
      if (count > 0 && pendingMessageFetch.value) {
        const p = pendingMessageFetch.value
        pendingMessageFetch.value = null
        fetchMessages(p.folder, p.page, p.options).catch(() => {})
      }
      if (count > 0 && pendingSingleMessageFetch.value) {
        const p = pendingSingleMessageFetch.value
        pendingSingleMessageFetch.value = null
        fetchMessageFromFolder(p.uid, p.folder, p.skipCache).catch(() => {})
      }
    },
  )

  async function fetchMessages(folder = null, page = 1, options = {}) {
    const targetFolder = folder || currentFolder.value;
    
    // Handle virtual folders - redirect to proper handlers
    if (targetFolder === 'ALL_MAIL') {
      return fetchAllMail(page);
    }
    if (targetFolder === 'SCHEDULED') {
      return fetchScheduledEmails();
    }
    if (targetFolder === 'SEARCH_RESULTS') {
      console.warn('[Mailbox] Cannot refresh SEARCH_RESULTS - user must re-search');
      return;
    }

    // Folder-id race guard. Post-cutover the backend ONLY exposes
    // /folders/{folder_id}/* and apiCollectionUrl() returns null when
    // the folder list for the active account hasn't hydrated yet
    // (e.g. caller awaits setActiveAccount but skips fetchFolders, or
    // an early page-1 fetch fires before initMailbox finishes). Letting
    // axios run with a null URL produces GET /api/?page=1&limit=50 -> 404.
    // Queue the fetch so the folders-populated watcher re-fires it.
    if (targetFolder && !apiCollectionUrl(targetFolder, 'messages')) {
      pendingMessageFetch.value = { folder: targetFolder, page, options };
      isDebugEnabled() && console.warn(
        '[Mailbox] fetchMessages deferred: folder list not yet hydrated for', targetFolder
      );
      return;
    }

    // Caller-trace for debugging Gmail OAuth view shuffles. Every page-1 call
    // is a potential view-replace; logging the caller lets us identify any
    // path that is bypassing the OAuth stability guard below. The Error stack
    // gives us the JS call site without throwing.
    if (isDebugEnabled() && page === 1) {
      const stack = (new Error('fetchMessages caller trace')).stack || ''
      console.log(
        `[Mailbox] fetchMessages page-1 call: folder=${targetFolder} options=${JSON.stringify(options)} oauth=${isCurrentAccountOAuth()} pendingRemovals=${hasPendingLocalRemovals(targetFolder)}`,
        '\n', stack.split('\n').slice(1, 6).join('\n')
      )
    }

    // Claim token BEFORE any state mutations so concurrent calls
    // that overlap during the await never corrupt shared state.
    const token = ++fetchMessagesToken

    // Background-sync fetches (from useMailSyncIntegration with
    // suppressIfLocallyConsistent) must NEVER reassign currentFolder.
    // Otherwise a debounced "incremental fetch for INBOX" can fire after
    // the user has clicked All Mail and synchronously yank them back to
    // INBOX before our network call even returns. The debounce guard
    // in useMailSyncIntegration also catches this, but defense-in-depth
    // ensures any other internal caller (reconciliation timer,
    // visibility refresh, etc.) cannot trigger the same race.
    const isBackgroundSync = options?.suppressIfLocallyConsistent === true
    if (folder && !isBackgroundSync) {
      currentFolder.value = folder;
    } else if (folder && isBackgroundSync && currentFolder.value !== folder) {
      // User navigated away mid-debounce. Skip silently; the user is on
      // a different folder and doesn't want this stale fetch's data
      // affecting their view. The data would only be written to
      // folderViews.get(folder) anyway (not the current view), but
      // doing the network round-trip + write would still trigger a
      // bunch of reactive updates the user doesn't care about.
      isDebugEnabled() && console.log(
        `[Mailbox] Skip background fetch for ${folder} (user on ${currentFolder.value})`
      )
      return
    }

    const conversationsStore = useConversationsStore();

    // Hydrate-render-revalidate: on a folder switch (page 1, no in-RAM
    // view yet) try IndexedDB first so the user sees cached headers
    // before the network response lands. The network call below still
    // runs and replaces the view with fresh data; this only kills the
    // empty-folder flash.
    //
    // We render the MESSAGE rows from cache but DELIBERATELY do not
    // patch pagination totals from the cache: those numbers can be
    // hours-stale and would briefly contradict the freshly fetched
    // folder.total / folder.unread. Pagination only updates from the
    // network response.
    let renderedFromCache = false;
    if (
      page === 1 &&
      !options?.skipCache &&
      currentFolder.value === targetFolder &&
      (!folderViews.get(targetFolder) || folderViews.get(targetFolder).length === 0)
    ) {
      try {
        syncActiveUserEmailToCache();
        const folderData = folders.value.find((f) => f.name === targetFolder);
        const cached = await getOfflineMessages(
          targetFolder,
          1,
          50,
          folderData?.uidvalidity || undefined
        );
        // Token-guard: a newer fetch may have started while IDB was
        // doing its read; if so, drop the cached results.
        if (token === fetchMessagesToken && cached?.messages?.length) {
          const keys = upsertMessages(cached.messages, targetFolder);
          folderViews.set(targetFolder, keys);
          cached.messages.forEach(m => seenMessageUids.value.add(`${targetFolder}:${m.uid}`));
          renderedFromCache = true;
        }
      } catch (e) {
        // Best-effort; cache miss falls through to network.
        isDebugEnabled() && console.warn('[Mailbox] fetchMessages IDB hydrate failed:', e);
      }
    }
    
    // Show loading only when we have NEITHER an in-RAM view NOR cached headers
    const hasExistingData = (messages.value.length > 0 && currentFolder.value === targetFolder) || renderedFromCache;
    if (!hasExistingData) {
      loading.value.messages = true;
    }
    
    try {
      const responseData = await withOfflineFallback(
        async () => {
          const folderData = folders.value.find((f) => f.name === targetFolder);
          const limit = Math.max(pagination.value.limit, 50);
          const params = { page, limit };
          
          if (folderData?.uidvalidity) {
            params.client_uidvalidity = folderData.uidvalidity;
          }
          
          const response = await api.get(
            apiCollectionUrl(targetFolder, "messages"),
            { params }
          );
          if (response.data.success) return response.data.data;
          return null;
        },
        async () => {
          const limit = Math.max(pagination.value.limit, 50);
          const offlineResult = await getOfflineMessages(targetFolder, page, limit);
          if (offlineResult?.success) return { ...offlineResult.data, _offline: true };
          return null;
        }
      );

      // Another fetchMessages call started while we were awaiting — discard stale results.
      if (token !== fetchMessagesToken) return

      if (responseData) {
        const newMessages = responseData.messages;

        // Track new incoming emails (only in INBOX folder, skip when offline)
        if (!responseData._offline && targetFolder === "INBOX" && seenMessageUids.value.size > 0) {
          const newlyReceivedMessages = newMessages.filter(
            (msg) => !seenMessageUids.value.has(`${targetFolder}:${msg.uid}`) && !msg.seen
          );
          for (const msg of newlyReceivedMessages) {
            trackEvent("email_received", {
              from: msg.from_email || msg.from?.address,
              from_name: msg.from_name || msg.from?.name,
              subject: msg.subject,
              has_attachments: msg.has_attachment || false,
            });
          }
          // Outlook-style new-mail chime. This is a universal insertion point:
          // it fires whenever genuinely new, unread INBOX mail is merged into
          // the view, regardless of how it was discovered (WebSocket event,
          // folder-count refresh, or background polling). Throttled in the
          // sound service so it never double-dings.
          if (newlyReceivedMessages.length > 0 && localStorage.getItem('notification_new_email') !== 'false') {
            notificationSounds.playEmailSound();
          }
        }

        if (targetFolder === "INBOX") {
          newMessages.forEach((msg) => seenMessageUids.value.add(`${targetFolder}:${msg.uid}`));
          if (seenMessageUids.value.size > 5000) {
            seenMessageUids.value.clear();
            newMessages.forEach((msg) => seenMessageUids.value.add(`${targetFolder}:${msg.uid}`));
          }
        }

        if (!newMessages || newMessages.length === 0) {
          if (page === 1) {
            folderViews.set(targetFolder, []);
          }
        } else {
          const keys = upsertMessages(newMessages, targetFolder);

          // OAuth view-stability guard (Layer 1, always-on, no overlap gate).
          //
          // Gmail's IMAP backend is distributed and eventually-consistent:
          // two consecutive page-1 queries against the same folder can
          // return materially different "top 25" results depending on
          // which replica responds, especially during the brief cache
          // rebuild window that follows a move/delete (when we just
          // invalidated Redis on the server). Blindly replacing
          // folderViews with such a flapped page-1 wipes the user's
          // current view -- visible as "different months of emails
          // disappear after I move one email" on Gmail accounts.
          //
          // The guard fires whenever ALL of the following are true:
          //   - we're on page 1 (the only path that replaces, not appends)
          //   - the current account is OAuth (Gmail / Microsoft)
          //   - there are unconsumed local removals on this folder (i.e.
          //     we just performed a move/delete; the server is mid-rebuild)
          //   - we already have data in this folder (don't suppress the
          //     very first load)
          //
          // EARLIER VERSION HAD A 50% OVERLAP THRESHOLD. We removed it.
          // Live testing showed Gmail's replica flap often returns
          // mostly-the-same top-25 (60-80% overlap) with a few swapped
          // UIDs, which is still visually disruptive (the user sees the
          // top items reshuffle and a different "next" email) but did NOT
          // trip the < 50% gate. The pendingRemovals counter has its own
          // 30-second TTL -- that is the right safety valve, not the
          // overlap ratio. After the TTL expires, consumeLocalRemovals
          // returns 0 and the guard naturally disengages, so legitimate
          // refreshes flow through normally a few seconds later.
          //
          // NOTE: we intentionally DO NOT gate on options.suppressIfLocallyConsistent
          // either. Several internal paths (revalidateActiveFolder, periodic
          // revalidation interval, route watchers, tab-visibility refresh)
          // call fetchMessages(folder, 1) directly without going through
          // the WebSocket sync layer, so an option gate would let the bug
          // slip through every time one of those paths fired during the
          // post-move cache-rebuild window. pendingRemovals is the only
          // gate we need.
          //
          // We still upsert messages into the canonical store and update
          // the authoritative pagination total, so search and folder
          // counts stay correct. We just don't yank the user's view.
          //
          // Regular IMAP accounts (Dovecot etc) skip this branch entirely
          // via the isCurrentAccountOAuth() gate, so their behavior is
          // bit-for-bit identical to before.
          let suppressedReplace = false;
          if (page === 1 && isCurrentAccountOAuth()) {
            const existingKeys = folderViews.get(targetFolder) || [];
            if (existingKeys.length > 0 && hasPendingLocalRemovals(targetFolder)) {
              const newKeySet = new Set(keys);
              let overlapCount = 0;
              for (const k of existingKeys) {
                if (newKeySet.has(k)) overlapCount++;
              }
              const overlapRatio = overlapCount / Math.max(existingKeys.length, keys.length);
              isDebugEnabled() && console.log(
                `[Mailbox] Suppressed page-1 replace for ${targetFolder} (OAuth + pending local removals, overlap=${overlapCount}/${existingKeys.length} = ${(overlapRatio * 100).toFixed(0)}%, callerOptions=${JSON.stringify(options)}) -- pending-removal TTL active, view trusted`
              );
              suppressedReplace = true;
            }
          }
          if (!suppressedReplace) {
            folderViews.set(targetFolder, keys);
          }
          evictUnprotectedMessages();
        }
        pruneStaleSelections();
        pagination.value = {
          page: responseData.page,
          pages: responseData.pages,
          total: responseData.total,
          limit: responseData.limit,
        };
        
        if (!responseData._offline && window.api?.db?.cacheEmails && newMessages.length > 0) {
          window.api.db.cacheEmails(targetFolder, newMessages).catch(() => {});
        }
        
        if (responseData.conversations) {
          conversationsStore.setConversationsFromResponse(targetFolder, responseData.conversations);
        }
        
        if (responseData.folderStatus) {
          const status = responseData.folderStatus;
          const localFolder = folders.value.find((f) => f.name === targetFolder);
          if (localFolder) {
            if (localFolder.uidvalidity && status.uidvalidity && localFolder.uidvalidity !== status.uidvalidity) {
              // OAuth view-stability guard (Layer 1).
              // Mirror of fetchMessagesSince's UIDVALIDITY guard. Gmail can
              // momentarily report a different UIDVALIDITY during the Redis
              // cache rebuild window that follows a local move/delete.
              // Wiping every folder cache for what turns out to be a
              // transient flap is exactly what causes the user-visible
              // "different months of emails disappear" symptom -- so when
              // the account is OAuth AND we have unconsumed local removals,
              // we treat it as a likely flap and skip the destructive flush.
              // The local uidvalidity field is left untouched in that case
              // so a genuine change will be re-detected on the next call
              // once the pending-removal TTL expires (30s).
              if (isCurrentAccountOAuth() && hasPendingLocalRemovals(targetFolder)) {
                isDebugEnabled() && console.warn(
                  `[Mailbox] Suppressed inline UIDVALIDITY flush for ${targetFolder} (OAuth + pending local removals, server=${status.uidvalidity} local=${localFolder.uidvalidity}) -- likely cache-rebuild flap`
                );
              } else {
                console.warn(`[UIDVALIDITY] Changed for ${targetFolder}: ${localFolder.uidvalidity} -> ${status.uidvalidity} - flushing caches`);
                messagesByKey.clear();
                folderViews.clear();
                conversationKeys.clear();
                seenMessageUids.value.clear();
                prefetchedFolders.clear();
                localFolder.uidvalidity = status.uidvalidity;
              }
            } else {
              localFolder.uidvalidity = status.uidvalidity;
            }
            localFolder.uidnext = status.uidnext;
            localFolder.total = status.total;
            localFolder.unread = status.unread;
          }
        }
        
        if (!responseData._offline) {
          prefetchRelatedFolderMessages(targetFolder);
        }

        touchLastRefreshed(targetFolder);
      }
    } catch (e) {
      console.error("Failed to fetch messages:", e);
    } finally {
      loading.value.messages = false;
    }
  }


  /**
   * Get the highest UID we currently hold for a folder.
   * Returns 0 if the folder has no cached messages.
   */
  function getHighestUid(folder) {
    const viewKeys = folderViews.get(folder)
    if (!viewKeys || viewKeys.length === 0) return 0
    let max = 0
    for (const key of viewKeys) {
      const msg = messagesByKey.get(key)
      if (msg && msg.uid > max) max = msg.uid
    }
    return max
  }

  /**
   * Get the stored UIDNEXT for a folder (from folder status).
   * Returns 0 if unknown.
   */
  function getFolderUidnext(folder) {
    const f = folders.value.find(fd => fd.name === folder)
    return f?.uidnext || 0
  }

  /**
   * Incremental fetch backed by the /delta endpoint (RFC 7162 CONDSTORE).
   *
   * Returns three classes of change in a single round trip:
   *   - newMessages : UIDs > sinceUid (handled by getMessagesSince on the server)
   *   - flagChanges : per-UID flag deltas since `since_modseq` (CONDSTORE)
   *   - deletedUids : UIDs vanished from the server-side view
   *
   * Crucially, CONDSTORE returns ONLY messages whose flags actually changed.
   * Unchanged messages are never reported, which means a stale fresh-connection
   * read on Gmail's distributed backend can no longer overwrite a user's
   * optimistic "read" state - the source of the "mark-as-read keeps reverting"
   * regression. The legacy /messages/since path returned no flag information at
   * all and relied on a 30s pending-flag TTL to mask the race.
   *
   * Return shape stays `{ count, uidnext }` so existing callers
   * (revalidateActiveFolder, useMailSyncIntegration, etc.) keep working.
   */
  async function fetchMessagesSince(folder, sinceUid) {
    if (!folder || !sinceUid) return { count: 0, uidnext: 0 }

    // Virtual folders (All Mail / Search / Scheduled) have no IMAP identity,
    // so there is no per-folder /delta endpoint to hit. A reconnect/sync-gap
    // refresh can land here with currentFolder === 'ALL_MAIL'; building a
    // delta URL for it resolves to null and the api layer rejects it
    // ("URL is null/empty"). These views refresh through their own paths
    // (fetchAllMail / search / fetchScheduledEmails), so skip the delta.
    if (folder === 'ALL_MAIL' || folder === 'SEARCH_RESULTS' || folder === 'SCHEDULED') {
      return { count: 0, uidnext: 0 }
    }

    const folderObj = folders.value.find(f => f.name === folder)
    const sinceUidvalidity = folderObj?.uidvalidity || 0
    const sinceModseq = folderObj?.highest_modseq || 0

    try {
      const response = await api.get(
        apiCollectionUrl(folder, "delta"),
        {
          params: {
            since_uid: sinceUid,
            since_uidvalidity: sinceUidvalidity,
            since_modseq: sinceModseq,
            include_counts: 1,
            limit: 100,
          }
        }
      )

      if (!response.data.success) return { count: 0, uidnext: 0 }

      const data = response.data.data || {}
      const newMessages = data.newMessages || []
      const flagChanges = data.flagChanges || []
      const deletedUids = data.deletedUids || []
      const uidnext = data.uidnext || 0
      const highestModseq = data.highest_modseq || 0

      // UIDVALIDITY guard: backend explicitly signals via uidvalidityChanged,
      // but we also defend against stale clients with a value mismatch check.
      if (data.uidvalidityChanged || (
        data.uidvalidity && folderObj?.uidvalidity && folderObj.uidvalidity !== data.uidvalidity
      )) {
        // OAuth view-stability guard (Layer 1).
        //
        // Gmail's IMAP backend can report a transiently-different
        // UIDVALIDITY during the Redis cache rebuild window that follows
        // a local move/delete. A genuine UIDVALIDITY change (folder was
        // rebuilt server-side) is extremely rare; a flapped reading
        // during cache rebuild is common on Gmail and would otherwise
        // trigger a full clearFolderFromStore + page-1 wipe -- exactly
        // the "different months disappear" bug the user reports.
        //
        // If we have pending local removals AND we're on OAuth, treat
        // this as a likely flap and skip the destructive flush. A real
        // UIDVALIDITY change will be re-detected on the next /delta call
        // once the pending-removal TTL expires (30s).
        //
        // Regular IMAP accounts (Dovecot etc) skip this guard via the
        // isCurrentAccountOAuth() gate -- identical behavior to before.
        if (hasPendingLocalRemovals(folder) && isCurrentAccountOAuth()) {
          isDebugEnabled() && console.warn(
            `[Mailbox] Suppressed UIDVALIDITY flush for ${folder} (OAuth + pending local removals, server=${data.uidvalidity} local=${folderObj?.uidvalidity}) -- likely cache-rebuild flap`
          )
          return { count: 0, uidnext }
        }
        console.warn(`[Mailbox] UIDVALIDITY changed during incremental (${folderObj?.uidvalidity} -> ${data.uidvalidity}), flushing`)
        clearFolderFromStore(folder)
        if (folderObj && data.uidvalidity) folderObj.uidvalidity = data.uidvalidity
        await fetchMessages(folder, 1)
        return { count: -1, uidnext }
      }

      // 1) Drop messages the server reports as vanished.
      if (deletedUids.length > 0) {
        const existingKeys = folderViews.get(folder) || []
        const survivors = []
        for (const k of existingKeys) {
          const msg = messagesByKey.get(k)
          if (msg && deletedUids.includes(Number(msg.uid))) {
            messagesByKey.delete(k)
            continue
          }
          survivors.push(k)
        }
        if (survivors.length !== existingKeys.length) {
          folderViews.set(folder, survivors)
        }
        // Server has now confirmed these UIDs are gone, so the optimistic
        // tombstones have done their job -- clear them so a reused UID (rare,
        // post-UIDVALIDITY) can never be permanently suppressed.
        for (const u of deletedUids) clearMessageTombstone(folder, u)
      }

      // 2) Apply CONDSTORE flag changes. These are authoritative - they come
      //    from UID FETCH ... (CHANGEDSINCE modseq), which only returns UIDs
      //    whose flags actually moved server-side. We clear any matching
      //    pending-flag entry so the optimistic-protection layer doesn't keep
      //    masking the now-confirmed state.
      if (flagChanges.length > 0) {
        for (const change of flagChanges) {
          const uid = Number(change.uid)
          if (!uid) continue
          const msg = findMessageByUid(uid, folder)
          if (msg) {
            if (typeof change.seen === 'boolean') msg.seen = change.seen
            if (typeof change.flagged === 'boolean') msg.flagged = change.flagged
            if (typeof change.answered === 'boolean') msg.answered = change.answered
            if (typeof change.deleted === 'boolean') msg.deleted = change.deleted
            if (change.modseq) msg.modseq = change.modseq
          }
        }
        notifyMessagesChanged()
      }

      // 3) Merge in new messages.
      if (newMessages.length > 0) {
        // Outlook-style new-mail chime. The /delta endpoint is the primary
        // path that incremental refreshes (WebSocket MESSAGE_NEW / FOLDER_COUNTS
        // and background polling) funnel through, so detecting genuinely new,
        // unread INBOX mail here guarantees the sound fires no matter which
        // transport delivered it. Mirrors the seenMessageUids logic in
        // fetchMessages so we never ding on the initial load; throttled in the
        // sound service so it never double-dings.
        if (folder === 'INBOX' && seenMessageUids.value.size > 0) {
          const newlyReceived = newMessages.filter(
            (m) => !seenMessageUids.value.has(`${folder}:${m.uid}`) && !m.seen
          )
          if (newlyReceived.length > 0 && localStorage.getItem('notification_new_email') !== 'false') {
            notificationSounds.playEmailSound()
          }
        }
        if (folder === 'INBOX') {
          newMessages.forEach((m) => seenMessageUids.value.add(`${folder}:${m.uid}`))
        }

        const newKeys = upsertMessages(newMessages, folder)

        const existingKeys = folderViews.get(folder) || []
        const existingSet = new Set(existingKeys)
        for (const k of newKeys) {
          if (!existingSet.has(k)) existingKeys.unshift(k)
        }

        existingKeys.sort((a, b) => {
          const ma = messagesByKey.get(a)
          const mb = messagesByKey.get(b)
          if (!ma || !mb) return 0
          const da = ma.date ? new Date(ma.date).getTime() : 0
          const db = mb.date ? new Date(mb.date).getTime() : 0
          return db - da
        })

        folderViews.set(folder, existingKeys)

        if (window.api?.db?.cacheEmails) {
          window.api.db.cacheEmails(folder, newMessages).catch(() => {})
        }
      }

      touchLastRefreshed(folder)

      // Advance folder metadata. highest_modseq MUST advance monotonically or
      // the next /delta call would re-request changes we already applied.
      if (folderObj) {
        if (uidnext) folderObj.uidnext = uidnext
        if (data.uidvalidity) folderObj.uidvalidity = data.uidvalidity
        if (highestModseq && highestModseq > (folderObj.highest_modseq || 0)) {
          folderObj.highest_modseq = highestModseq
        }
        if (data.counts) {
          if (typeof data.counts.total === 'number') folderObj.total = data.counts.total
          if (typeof data.counts.unread === 'number') folderObj.unread = data.counts.unread
        }
      }

      return { count: newMessages.length, uidnext }
    } catch (e) {
      console.error('[Mailbox] fetchMessagesSince (delta) failed:', e)
      return { count: 0, uidnext: 0 }
    }
  }

  /**
   * Lightweight UIDNEXT check: returns { uidnext, uidvalidity, total, unread }
   * without fetching any messages. Used by reconciliation to decide
   * whether a full or incremental fetch is needed.
   */
  async function checkFolderSyncState(folder) {
    try {
      const response = await api.get(
        apiCollectionUrl(folder, "sync-state")
      )
      if (response.data.success) {
        return response.data.data
      }
    } catch (e) {
      // Fallback: caller should do a full refresh
    }
    return null
  }

  /**
   * Pre-fetch messages from related folders (like Sent) to get accurate thread counts
   * This runs in background after loading a folder so conversation counts are correct
   */
  const prefetchedFolders = new Set();
  const PREFETCH_SET_MAX = 200;
  async function prefetchRelatedFolderMessages(currentFolderName) {
    // Only prefetch once per session per folder
    const cacheKey = `${currentFolderName}:prefetched`;
    if (prefetchedFolders.has(cacheKey)) return;
    if (prefetchedFolders.size >= PREFETCH_SET_MAX) prefetchedFolders.clear();
    prefetchedFolders.add(cacheKey);
    
    // Find the Sent folder
    const sentFolder = folders.value.find(f => 
      f.name.toLowerCase().includes('sent') || 
      f.name === 'INBOX.Sent' ||
      f.name === 'Sent'
    );
    
    if (!sentFolder || sentFolder.name === currentFolderName) return;
    
    try {
      // Fetch recent sent messages (last 100 - should cover most active threads)
      const response = await api.get(apiCollectionUrl(sentFolder.name, "messages"), {
        params: { limit: 100, page: 1 }
      });
      
      // Backend auto-assigns and returns conversations, store them
      if (response.data.success && response.data.data.conversations) {
        const conversationsStore = useConversationsStore();
        conversationsStore.setConversationsFromResponse(sentFolder.name, response.data.data.conversations);
        // Re-fetch current folder conversations since sent messages may affect counts
        await conversationsStore.fetchConversations(currentFolderName);
      }
    } catch (e) {
      // Silent fail - this is a background optimization
    }
  }

  // Track in-flight requests to prevent duplicate fetches
  const pendingFetches = new Map();
  // Track failed message fetches to prevent infinite retries
  const failedFetches = new Map();

  /**
   * Fetch a single message from API
   */
  async function fetchSingleMessage(folder, msgUid) {
    const normalizedFolder = findFolderByPath(folder);
    const cacheKey = `${normalizedFolder}:${msgUid}`;

    try {
      // Check if this message has failed recently (prevent infinite retries)
      const failedInfo = failedFetches.get(cacheKey);
      if (failedInfo && failedInfo.count >= 2) {
        const timeSinceLastAttempt = Date.now() - failedInfo.lastAttempt;
        if (timeSinceLastAttempt < 60000) {
          return null;
        }
        failedFetches.delete(cacheKey);
      }

      // Check if already fetching (deduplication)
      if (pendingFetches.has(cacheKey)) {
        return pendingFetches.get(cacheKey);
      }

      const fetchPromise = (async () => {
        const result = await withOfflineFallback(
          async () => {
            const response = await api.get(
              apiCollectionUrl(normalizedFolder, `messages/${msgUid}`)
            );
            if (response.data.success) {
              failedFetches.delete(cacheKey);
              const body = response.data.data;
              if (window.api?.db?.cacheEmailBody && (body.body_html || body.body_text)) {
                window.api.db.cacheEmailBody(normalizedFolder, msgUid, body.body_html || '', body.body_text || '').catch(() => {});
              }
              // Web IndexedDB write-through: only stores bodies whose
              // message date is within the 30-day window. Function
              // returns false for older messages.
              if (body.body_html || body.body_text) {
                syncActiveUserEmailToCache();
                setOfflineMessageBody(
                  normalizedFolder,
                  Number(msgUid),
                  {
                    body_html: body.body_html || '',
                    body_text: body.body_text || '',
                    attachments: Array.isArray(body.attachments) ? body.attachments : [],
                  },
                  body.date || body.date_received || body.received_at || null,
                ).catch(() => {});
              }
              return response.data.data;
            }
            return null;
          },
          async () => {
            // First try in-process Electron alias offline message store.
            const offlineResult = await getOfflineMessage(normalizedFolder, msgUid);
            if (offlineResult?.success) {
              failedFetches.delete(cacheKey);
              return offlineResult.data;
            }
            // Web fallback: IndexedDB body cache (30-day window).
            syncActiveUserEmailToCache();
            const cachedBody = await getOfflineMessageBody(normalizedFolder, Number(msgUid));
            if (cachedBody && (cachedBody.body_html || cachedBody.body_text)) {
              failedFetches.delete(cacheKey);
              const headerMsg = messagesByKey.get(cacheKey) || { uid: Number(msgUid), folder: normalizedFolder };
              return {
                ...headerMsg,
                body_html: cachedBody.body_html,
                body_text: cachedBody.body_text,
                attachments: cachedBody.attachments || [],
              };
            }
            return null;
          }
        );
        return result;
      })();

      pendingFetches.set(cacheKey, fetchPromise);
      try {
        return await fetchPromise;
      } finally {
        pendingFetches.delete(cacheKey);
      }
    } catch (e) {
      pendingFetches.delete(cacheKey);

      // If 404 or 500, this message doesn't exist - remove from list
      if (e.response?.status === 404 || e.response?.status === 500) {
        removeMessageFromList(msgUid, normalizedFolder || currentFolder.value);
        failedFetches.set(cacheKey, { count: 99, lastAttempt: Date.now() });
      } else {
        const existing = failedFetches.get(cacheKey) || { count: 0 };
        failedFetches.set(cacheKey, {
          count: existing.count + 1,
          lastAttempt: Date.now(),
        });
        console.error(`Failed to fetch message: ${cacheKey}`, e.message || e);
      }

      return null;
    }
  }

  // Clear a message's body data so next open fetches fresh
  function clearMessageBody(folder, uid) {
    const msg = messagesByKey.get(`${folder}:${uid}`);
    if (msg) {
      delete msg.body_html;
      delete msg.body_text;
    }
  }

  // Rename folder keys in canonical store
  function renameFolderInStore(oldFolder, newFolder) {
    const keysToMove = [];
    for (const key of messagesByKey.keys()) {
      if (key.startsWith(`${oldFolder}:`)) keysToMove.push(key);
    }
    const newViewKeys = [];
    for (const oldKey of keysToMove) {
      const uid = oldKey.substring(oldFolder.length + 1);
      const newKey = `${newFolder}:${uid}`;
      const msg = messagesByKey.get(oldKey);
      msg.folder = newFolder;
      messagesByKey.delete(oldKey);
      messagesByKey.set(newKey, msg);
      newViewKeys.push(newKey);
    }
    if (folderViews.has(oldFolder)) {
      folderViews.delete(oldFolder);
      if (newViewKeys.length > 0) folderViews.set(newFolder, newViewKeys);
    }
  }

  // Clear all canonical store entries for a folder
  function clearFolderFromStore(folder) {
    const keysToDelete = [];
    for (const key of messagesByKey.keys()) {
      if (key.startsWith(`${folder}:`)) keysToDelete.push(key);
    }
    for (const key of keysToDelete) messagesByKey.delete(key);
    folderViews.delete(folder);
  }

  // Track in-progress conversation background loads to prevent duplicate work
  const _convLoadInProgress = new Set();

  // Load conversation messages in background (for instant display)
  // Uses batch API to fetch all messages in a single request
  async function loadConversationMessagesBackground(
    uid,
    conversationItem,
    mainMessage
  ) {
    const convKey = conversationItem.conversationKey;
    if (_convLoadInProgress.has(convKey)) {
      isDebugEnabled() && console.log('[CONV-DEBUG] loadConversationMessagesBackground SKIPPED (already in progress)', { convKey });
      return;
    }
    _convLoadInProgress.add(convKey);
    try {
      isDebugEnabled() && console.log('[CONV-DEBUG] loadConversationMessagesBackground START', {
        uid,
        conversationKey: convKey,
        conversationIsConv: conversationItem.isConversation,
        conversationMsgCount: conversationItem.messageCount
      });

      await fetchThreadMessages(convKey);
      const threadMsgs = getConversationMessages(
        conversationItem.conversationKey
      );
      
      isDebugEnabled() && console.log('[CONV-DEBUG] threadMsgs fetched', {
        count: threadMsgs?.length,
        uids: threadMsgs?.map(m => m.uid)
      });

      // Filter out the main message we already have
      const messagesToFetch = threadMsgs.filter(
        (msg) =>
          !(
            msg.uid === uid &&
            (!msg.folder || msg.folder === currentFolder.value)
          )
      );

      if (messagesToFetch.length === 0) {
        isDebugEnabled() && console.log('[CONV-DEBUG] No additional messages to fetch, keeping current view', { uid });
        return;
      }

      // Build batch request for all messages at once
      const requests = messagesToFetch.map((msg) => ({
        folder: msg.folder || currentFolder.value,
        uid: msg.uid,
      }));

      // Single API call to fetch all conversation messages
      const response = await api.post("/mailbox/messages/batch-multi", {
        requests,
        skip_cache: false,
      });

      const additionalMessages = [];
      if (response.data?.success && response.data?.data?.messages) {
        const rawMessages = response.data.data.messages;
        const fetchedMessages = {};
        for (const [k, v] of Object.entries(rawMessages)) {
          fetchedMessages[k.toLowerCase()] = v;
        }

        for (const msg of messagesToFetch) {
          const folder = msg.folder || currentFolder.value;
          const key = `${folder}:${msg.uid}`.toLowerCase();
          const msgData = fetchedMessages[key];

          if (msgData && (msgData.body_html || msgData.body_text)) {
            upsertMessage({ ...msgData, is_sent: msg.is_sent || folder.toLowerCase() !== currentFolder.value.toLowerCase(), folder }, folder);
            const canonicalKey = `${folder}:${Number(msg.uid)}`;
            additionalMessages.push(messagesByKey.get(canonicalKey) || { ...msgData, folder });
          }
        }
      }

      const allMessages = [mainMessage, ...additionalMessages];
      allMessages.sort((a, b) => (b.timestamp || 0) - (a.timestamp || 0));

      // Update current message with full conversation
      if (currentMessage.value?.conversationKey === conversationItem.conversationKey) {
        currentMessage.value = {
          ...conversationItem,
          ...mainMessage,
          folder: currentFolder.value,  // Remember which folder this message is from
          messages: allMessages,
          isConversation: allMessages.length > 1,
          messageCount: allMessages.length,
        };
      }

      // Mark any newly-discovered unread messages in this folder as read.
      // markOpenedAsRead ran before this background load, so it only knew
      // about messages that existed at click-time. Thread/batch fetches may
      // pull in additional messages whose \Seen flag is not set on IMAP,
      // causing the conversation to flip back to "unread" in the list.
      const updatedConversation = {
        ...conversationItem,
        messages: allMessages,
      };
      markOpenedAsRead(uid, updatedConversation);
    } catch (e) {
      console.error("Background conversation load failed:", e);
    } finally {
      _convLoadInProgress.delete(convKey);
    }
  }

  /**
   * Batch fetch multiple messages (for pre-fetching conversations)
   * Uses server-side batch endpoint for efficiency
   * @param {string} folder - Folder name
   * @param {number[]} uids - Array of message UIDs
   * @param {boolean} skipCache - Force fresh fetch
   * @returns {object} Map of uid -> message data
   */
  async function fetchMessagesBatch(folder, uids, skipCache = false) {
    if (!uids || uids.length === 0) return {};

    const results = {};
    const uidsToFetch = [];

    if (!skipCache) {
      for (const uid of uids) {
        const existing = messagesByKey.get(`${folder}:${uid}`);
        if (existing?.body_html || existing?.body_text) {
          results[uid] = existing;
        } else {
          uidsToFetch.push(uid);
        }
      }
    } else {
      uidsToFetch.push(...uids);
    }

    if (uidsToFetch.length > 0) {
      try {
        const response = await api.post(
          apiCollectionUrl(folder, "messages/batch"),
          { uids: uidsToFetch, skip_cache: skipCache }
        );

        if (response.data.success && response.data.data.messages) {
          for (const [uid, message] of Object.entries(response.data.data.messages)) {
            upsertMessage({ ...message, folder }, folder);
            results[uid] = messagesByKey.get(`${folder}:${uid}`) || message;
          }
        }
      } catch (e) {
        console.error("Batch fetch failed:", e);
        for (const uid of uidsToFetch) {
          try {
            const message = await fetchSingleMessage(folder, uid);
            if (message) results[uid] = message;
          } catch {
            // Skip failed fetches
          }
        }
      }
    }

    return results;
  }

  /**
   * Fetch a message from a specific folder (used for search results)
   * This doesn't change the current folder, just fetches and displays the message
   */
  async function fetchMessageFromFolder(uid, folder, skipCache = false) {
    const cacheKey = `${folder}:${uid}`;

    // Check canonical store for body
    if (!skipCache) {
      const existing = messagesByKey.get(cacheKey);
      if (existing?.body_html || existing?.body_text) {
        isDebugEnabled() && console.log(`[Cache] Canonical hit for search result: ${cacheKey}`);
        currentMessage.value = { ...existing, folder, fromSearchResults: true };
        markOpenedAsRead(uid, null, folder);
        return;
      }
    }

    // Folder-id race guard. Deep-link routes (/email/:folder/message/:uid)
    // can land here before initMailbox has hydrated folders.value, in
    // which case apiCollectionUrl() returns null and api.js rejects the
    // request with "URL is null/empty". Queue the fetch so the
    // folders-populated watcher re-fires it once the folder list is in.
    if (folder && !apiCollectionUrl(folder, `messages/${uid}`)) {
      pendingSingleMessageFetch.value = { uid, folder, skipCache };
      isDebugEnabled() && console.warn(
        '[Mailbox] fetchMessageFromFolder deferred: folder list not yet hydrated for', folder
      );
      return;
    }

    loading.value.message = true;
    try {
      const response = await api.get(
        apiCollectionUrl(folder, `messages/${uid}`)
      );

      if (response.data.success) {
        const data = response.data.data;
        upsertMessage({ ...data, folder }, folder);
        currentMessage.value = {
          ...(messagesByKey.get(cacheKey) || data),
          folder,
          fromSearchResults: true
        };
        markOpenedAsRead(uid, null, folder);
      }
    } catch (e) {
      console.error("Failed to fetch message from folder:", e);
    } finally {
      loading.value.message = false;
    }
  }

  /**
   * Mark all messages in a conversation (target folder only) as read.
   * For single messages, marks just that one UID. Handles optimistic UI update,
   * pending-flag protection, folder unread count, and server flag calls.
   *
   * @param {number} uid - The clicked message UID
   * @param {object|null} conversationItem - Conversation object (null for single messages)
   * @param {string|null} explicitFolder - Override folder (for virtual folder contexts)
   */
  function markOpenedAsRead(uid, conversationItem = null, explicitFolder = null) {
    const folder = explicitFolder || currentFolder.value;
    const folderNorm = normalizeFolderName(folder);
    const msgsToMark = [];

    if (conversationItem && conversationView.value && conversationItem.messages?.length > 0) {
      for (const msg of conversationItem.messages) {
        const mf = normalizeFolderName(msg.folder || folder);
        if (mf !== folderNorm) continue;
        const localMsg = findMessageByUid(msg.uid, msg.folder || folderNorm);
        if (localMsg && !localMsg.seen) {
          msgsToMark.push({ uid: msg.uid, localMsg });
        }
      }
    } else {
      const localMsg = findMessageByUid(uid, folderNorm);
      if (localMsg && !localMsg.seen) {
        msgsToMark.push({ uid, localMsg });
      }
    }

    if (msgsToMark.length === 0) return;

    for (const { uid: msgUid, localMsg } of msgsToMark) {
      const msgFolder = normalizeFolderName(localMsg.folder || folderNorm);
      // Capture the pre-optimistic state so we can roll back on API failure.
      // Previously the catch block only cleared the pending-flag protection
      // marker but left localMsg.seen = true and the decremented folder
      // unread count in place. That meant a failed flag write produced a
      // permanently-stale read state in the UI (until the next CONDSTORE
      // delta or FOLDER_COUNTS event arrived and overrode it minutes
      // later -- if it ever did).
      const wasUnread = !localMsg.seen;
      localMsg.seen = true;

      const folderObj = wasUnread
        ? folders.value.find((f) => f.name === msgFolder)
        : null;
      const decrementedFolder = folderObj && folderObj.unread > 0;
      if (decrementedFolder) folderObj.unread--;

      api.post(
        apiCollectionUrl(msgFolder, `messages/${msgUid}/flag?flag=seen&value=1`),
        { flag: "seen", value: true, clientOpId: newOpId() }
      ).catch((e) => {
        if (wasUnread) {
          localMsg.seen = false;
          if (decrementedFolder) folderObj.unread++;
        }
        conversationsRefreshTrigger.value++;
        console.error("[auto-mark-read] FAILED, reverted:", e?.response?.status, e?.response?.data?.message || e?.response?.data, { uid: msgUid, folder: msgFolder });
      });
    }

    conversationsRefreshTrigger.value++;
  }

  async function fetchMessage(uid, skipCache = false, folder = null) {
    // Skip if this exact message is already open and conversation data is loaded or loading
    if (!skipCache && currentMessage.value?.uid === uid) {
      const cm = currentMessage.value;
      const alreadyHasConversation = cm.isConversation && cm.messages?.length > 1;
      const convKey = cm.conversationKey;
      const loadInProgress = convKey && _convLoadInProgress.has(convKey);
      if (alreadyHasConversation || loadInProgress) {
        isDebugEnabled() && console.log('[Mailbox] fetchMessage SKIPPED (already open/loading)', { uid, alreadyHasConversation, loadInProgress });
        return;
      }
    }

    // If in virtual folder (search results or all mail), try to find the message's actual folder
    if (currentFolder.value === 'SEARCH_RESULTS' || currentFolder.value === 'ALL_MAIL') {
      const targetFolder = folder || findMessageByUid(uid, folder)?.folder;
      if (targetFolder) {
        return fetchMessageFromFolder(uid, targetFolder, skipCache);
      }
      console.warn(`[Mailbox] fetchMessage in ${currentFolder.value} but couldn't find message folder for UID ${uid}`);
    }

    const cacheKey = `${currentFolder.value}:${uid}`;

    // IndexedDB body cache (web 30-day window): if a recent body was
    // cached on a previous session, merge it into the canonical RAM
    // store so the "Check canonical store" branch below renders it
    // instantly. The network call still fires (revalidate) and will
    // patch any newer content.
    if (!skipCache) {
      const ramHasBody = messagesByKey.get(cacheKey)?.body_html || messagesByKey.get(cacheKey)?.body_text;
      if (!ramHasBody) {
        try {
          syncActiveUserEmailToCache();
          const cachedBody = await getOfflineMessageBody(currentFolder.value, Number(uid));
          if (cachedBody && (cachedBody.body_html || cachedBody.body_text)) {
            upsertMessage(
              {
                uid: Number(uid),
                folder: currentFolder.value,
                body_html: cachedBody.body_html || '',
                body_text: cachedBody.body_text || '',
                attachments: cachedBody.attachments || [],
              },
              currentFolder.value,
            );
          }
        } catch (_e) {
          // Best-effort; falls through to network.
        }
      }
    }

    // Check canonical store for body (synchronous, instant)
    const cachedMsg = !skipCache ? messagesByKey.get(cacheKey) : null;
    if (cachedMsg && (cachedMsg.body_html || cachedMsg.body_text)) {
      isDebugEnabled() && console.log(`[Cache] Canonical body hit: ${cacheKey}`);

      const conversationItem = conversationView.value
        ? conversations.value.find((c) => c.uid === uid)
        : null;

      if (conversationItem && conversationView.value) {
        currentMessage.value = {
          ...conversationItem,
          ...cachedMsg,
          folder: currentFolder.value,
          messages: [cachedMsg],
          isConversation: false,
          messageCount: 1,
        };
        loadConversationMessagesBackground(uid, conversationItem, cachedMsg);
      } else {
        const convId = cachedMsg.message_id ? `temp:${cachedMsg.message_id}` : `uid:${currentFolder.value}:${uid}`;
        currentMessage.value = {
          ...cachedMsg,
          folder: currentFolder.value,
          conversation_id: convId,
          conversationKey: convId
        };
      }

      markOpenedAsRead(uid, conversationItem);
      return;
    }

    loading.value.message = true;
    try {
      const conversationItem = conversationView.value
        ? conversations.value.find((c) => c.uid === uid)
        : null;

      let messageData = await fetchSingleMessage(currentFolder.value, uid, skipCache);

      if (!messageData && conversationItem && conversationItem.messages?.length > 1) {
        isDebugEnabled() && console.log("[Mailbox] Main message failed, trying alternate from conversation");
        for (const altMsg of conversationItem.messages) {
          if (altMsg.uid !== uid) {
            const altData = await fetchSingleMessage(currentFolder.value, altMsg.uid, skipCache);
            if (altData) { messageData = altData; break; }
          }
        }
      }

      if (!messageData) {
        console.info("Message not available:", uid);
        currentMessage.value = null;
        loading.value.message = false;
        setTimeout(() => { fetchMessages().catch(() => {}); fetchFolders(true); }, 100);
        return;
      }

      // Upsert body onto canonical object
      upsertMessage({ ...messageData, folder: currentFolder.value }, currentFolder.value);
      const canonical = messagesByKey.get(cacheKey) || messageData;

      if (conversationItem && conversationView.value) {
        currentMessage.value = {
          ...conversationItem,
          ...canonical,
          folder: currentFolder.value,
          messages: [canonical],
          isConversation: false,
          messageCount: 1,
        };
        loading.value.message = false;
        loadConversationMessagesBackground(uid, conversationItem, canonical);
      } else {
        const convId = canonical.message_id ? `temp:${canonical.message_id}` : `uid:${currentFolder.value}:${uid}`;
        currentMessage.value = {
          ...canonical,
          folder: currentFolder.value,
          conversation_id: convId,
          conversationKey: convId
        };
        loading.value.message = false;
      }

      markOpenedAsRead(uid, conversationItem);
    } catch (e) {
      if (e.response?.status === 404) {
        console.info("Message not found (may have been moved/deleted):", uid);
        removeMessageFromList(uid, currentFolder.value);
        currentMessage.value = null;
        // Refresh folder in background
        setTimeout(() => {
          fetchMessages().catch(() => {});
          fetchFolders(true);
        }, 300);
      } else {
        console.error("Failed to fetch message:", e);
      }
    } finally {
      loading.value.message = false;
    }
  }

  async function setFlag(uid, flag, value, folder = null) {
    const targetFolder = resolveMessageFolder(uid, folder);
    if (!targetFolder) {
      console.error("Cannot set flag: unknown message folder");
      return false;
    }
    const msg = findMessageByUid(uid, targetFolder);

    // Optimistic: update local state immediately
    const previousValue = msg ? msg[flag] : undefined;
    if (msg) {
      msg[flag] = value;
    }
    if (currentMessage.value?.uid === uid && (!currentMessage.value.folder || currentMessage.value.folder === targetFolder)) {
      currentMessage.value[flag] = value;
    }
    conversationsRefreshTrigger.value++;
    // IndexedDB write-through so a page reload preserves the flag.
    syncActiveUserEmailToCache();
    patchOfflineMessage(targetFolder, Number(uid), { [flag]: value }).catch(() => {});

    try {
      await api.post(
        apiCollectionUrl(targetFolder, `messages/${uid}/flag?flag=${encodeURIComponent(flag)}&value=${value ? '1' : '0'}`),
        {
          flag,
          value,
          clientOpId: newOpId(),
        }
      );

      fetchFolders(true);
      return true;
    } catch (e) {
      console.error("[setFlag] FAILED:", e?.response?.status, e?.response?.data, { uid, flag, value, targetFolder });
      if (msg && previousValue !== undefined) {
        msg[flag] = previousValue;
      }
      if (currentMessage.value?.uid === uid) {
        currentMessage.value[flag] = previousValue;
      }
      conversationsRefreshTrigger.value++;
      // Revert IndexedDB write-through on failure.
      if (previousValue !== undefined) {
        patchOfflineMessage(targetFolder, Number(uid), { [flag]: previousValue }).catch(() => {});
      }
      return false;
    }
  }

  // === PIN FUNCTIONALITY (Database-backed, not IMAP) ===

  /**
   * Fetch all pinned emails for the current user
   */
  async function fetchPinnedEmails() {
    try {
      const response = await api.get('/mailbox/pinned');
      if (response.data.success) {
        pinnedEmails.value = response.data.data || [];
        pinnedEmailsLoaded.value = true;
      }
      return pinnedEmails.value;
    } catch (e) {
      console.error("Failed to fetch pinned emails:", e);
      return [];
    }
  }

  /**
   * Pin an email
   */
  async function pinEmail(uid, folder = null, messageData = {}) {
    const targetFolder = resolveMessageFolder(uid, folder);
    if (!targetFolder) {
      console.error("Cannot pin message: unknown folder");
      return false;
    }
    try {
      await api.post(
        apiCollectionUrl(targetFolder, `messages/${uid}/pin`),
        {
          message_id: messageData.message_id,
          subject: messageData.subject,
        }
      );

      // Update local state (match by folder+uid for virtual views)
      const msg = findMessageByUid(uid, targetFolder);
      if (msg) {
        msg.pinned = true;
      }
      if (currentMessage.value?.uid === uid && (!currentMessage.value.folder || currentMessage.value.folder === targetFolder)) {
        currentMessage.value.pinned = true;
      }

      // Add to pinned emails list
      pinnedEmails.value.unshift({
        folder: targetFolder,
        uid,
        message_id: messageData.message_id,
        subject: messageData.subject,
        pinned_at: new Date().toISOString(),
      });

      return true;
    } catch (e) {
      console.error("Failed to pin email:", e);
      return false;
    }
  }

  /**
   * Unpin an email
   */
  async function unpinEmail(uid, folder = null) {
    const targetFolder = resolveMessageFolder(uid, folder);
    if (!targetFolder) {
      console.error("Cannot unpin message: unknown folder");
      return false;
    }
    try {
      await api.delete(
        apiCollectionUrl(targetFolder, `messages/${uid}/pin`)
      );

      // Update local state (match by folder+uid for virtual views)
      const msg = findMessageByUid(uid, targetFolder);
      if (msg) {
        msg.pinned = false;
      }
      if (currentMessage.value?.uid === uid && (!currentMessage.value.folder || currentMessage.value.folder === targetFolder)) {
        currentMessage.value.pinned = false;
      }

      // Remove from pinned emails list
      pinnedEmails.value = pinnedEmails.value.filter(
        (p) => !(p.folder === targetFolder && p.uid === uid)
      );

      return true;
    } catch (e) {
      console.error("Failed to unpin email:", e);
      return false;
    }
  }

  /**
   * Toggle pin state of an email
   */
  async function togglePin(uid, folder = null, messageData = {}) {
    const targetFolder = resolveMessageFolder(uid, folder);
    const isPinned = isEmailPinned(uid, targetFolder);
    
    if (isPinned) {
      return unpinEmail(uid, targetFolder);
    } else {
      return pinEmail(uid, targetFolder, messageData);
    }
  }

  /**
   * Check if an email is pinned
   */
  function isEmailPinned(uid, folder = null) {
    const targetFolder = resolveMessageFolder(uid, folder);
    return pinnedEmails.value.some(
      (p) => p.folder === targetFolder && p.uid === uid
    );
  }

  /**
   * Move a single message. Server is source of truth -- waits for confirmation, then refreshes.
   * @param {number} uid
   * @param {string} sourceFolder - real IMAP folder (required)
   * @param {string} targetFolder - destination folder (required)
   */
  async function moveMessage(uid, sourceFolder, targetFolder) {
    if (!sourceFolder || !targetFolder) {
      console.error("moveMessage: sourceFolder and targetFolder are required", { uid, sourceFolder, targetFolder });
      return false;
    }
    if (sourceFolder === targetFolder) return true;

    try {
      await api.post(
        apiCollectionUrl(sourceFolder, `messages/${uid}/move?target=${encodeURIComponent(targetFolder)}`),
        { target: targetFolder, clientOpId: newOpId() }
      );

      removeMessageFromList(uid, sourceFolder);
      markLocalRemoval(sourceFolder, 1);
      conversationsStore.removeMessageLocally(sourceFolder, uid);
      conversationsRefreshTrigger.value++;

      trackEvent("email_moved", { from_folder: sourceFolder, to_folder: targetFolder });
      return true;
    } catch (e) {
      const serverMsg = e.response?.data?.message || e.message || 'Unknown error';
      console.error("Failed to move message:", serverMsg);
      return serverMsg;
    }
  }

  /**
   * Delete a single message. Server is source of truth -- waits for confirmation, then refreshes.
   * @param {number} uid
   * @param {string} sourceFolder - real IMAP folder (required)
   * @param {boolean} permanent
   */
  async function deleteMessage(uid, sourceFolder, permanent = false) {
    if (!sourceFolder) {
      console.error("deleteMessage: sourceFolder is required", { uid, sourceFolder });
      return false;
    }

    try {
      await api.delete(
        apiCollectionUrl(sourceFolder, `messages/${uid}`),
        { params: { permanent, clientOpId: newOpId() } }
      );

      removeMessageFromList(uid, sourceFolder);
      markLocalRemoval(sourceFolder, 1);
      conversationsStore.removeMessageLocally(sourceFolder, uid);
      conversationsRefreshTrigger.value++;

      trackEvent("email_deleted", { folder: sourceFolder, permanent });
      return true;
    } catch (e) {
      console.error("Failed to delete message:", e);
      return false;
    }
  }

  async function refetchAfterBulkOperation() {
    refreshFoldersQuietly();
    try {
      await fetchMessages();
    } catch (e) {
      // Non-critical
    }
  }

  /**
   * Batch move: single API call for multiple messages.
   * @param {Array<{uid: number, folder: string}>} items
   * @param {string} targetFolder
   */
  async function bulkMoveMessages(items, targetFolder) {
    if (items.length === 0) return { success: 0, failed: 0 };
    if (!targetFolder) return { success: 0, failed: items.length };

    const msgList = items.map(item => ({
      uid: typeof item === 'object' ? item.uid : item,
      folder: typeof item === 'object' ? (item.folder || currentFolder.value) : currentFolder.value,
    }));

    bulkProgress.value = { active: true, current: 0, total: msgList.length, action: "moving" };

    try {
      const response = await api.post('/mailbox/batch-move', {
        messages: msgList,
        target: targetFolder,
        clientOpId: newOpId(),
      });

      const data = response.data?.data || {};
      const result = { success: data.success || 0, failed: data.failed || 0 };

      const removalsByFolder = {};
      for (const msg of msgList) {
        removeMessageFromList(msg.uid, msg.folder);
        conversationsStore.removeMessageLocally(msg.folder, msg.uid);
        removalsByFolder[msg.folder] = (removalsByFolder[msg.folder] || 0) + 1;
      }
      for (const [folder, count] of Object.entries(removalsByFolder)) {
        markLocalRemoval(folder, count);
      }
      conversationsRefreshTrigger.value++;

      if (data.errors?.length) {
        console.warn("Batch move partial errors:", data.errors);
      }

      bulkProgress.value = { active: false, current: msgList.length, total: msgList.length, action: "moving" };
      await refetchAfterBulkOperation();
      return result;
    } catch (e) {
      console.error("Batch move failed:", e);
      bulkProgress.value.active = false;
      await refetchAfterBulkOperation();
      return { success: 0, failed: msgList.length };
    }
  }

  /**
   * Batch delete: single API call for multiple messages.
   * @param {Array<{uid: number, folder: string}>} items
   * @param {boolean} permanent
   */
  async function bulkDeleteMessages(items, permanent = false) {
    if (items.length === 0) return { success: 0, failed: 0 };

    const msgList = items.map(item => ({
      uid: typeof item === 'object' ? item.uid : item,
      folder: typeof item === 'object' ? (item.folder || currentFolder.value) : currentFolder.value,
    }));

    bulkProgress.value = { active: true, current: 0, total: msgList.length, action: permanent ? "permanently deleting" : "deleting" };

    try {
      const response = await api.post('/mailbox/batch-delete', {
        messages: msgList,
        permanent,
        clientOpId: newOpId(),
      });

      const data = response.data?.data || {};
      const result = { success: data.success || 0, failed: data.failed || 0 };

      const removalsByFolder = {};
      for (const msg of msgList) {
        removeMessageFromList(msg.uid, msg.folder);
        conversationsStore.removeMessageLocally(msg.folder, msg.uid);
        removalsByFolder[msg.folder] = (removalsByFolder[msg.folder] || 0) + 1;
      }
      for (const [folder, count] of Object.entries(removalsByFolder)) {
        markLocalRemoval(folder, count);
      }
      conversationsRefreshTrigger.value++;

      if (data.errors?.length) {
        console.warn("Batch delete partial errors:", data.errors);
      }

      bulkProgress.value = { active: false, current: msgList.length, total: msgList.length, action: "deleting" };
      await refetchAfterBulkOperation();
      return result;
    } catch (e) {
      console.error("Batch delete failed:", e);
      bulkProgress.value.active = false;
      await refetchAfterBulkOperation();
      return { success: 0, failed: msgList.length };
    }
  }

  // Restore message from Trash to INBOX
  async function restoreMessage(uid, targetFolder = "INBOX") {
    try {
      const sourceFolder = currentFolder.value;

      await api.post(
        apiCollectionUrl(sourceFolder, `messages/${uid}/restore`),
        {
          target_folder: targetFolder,
        }
      );

      // Remove from current list and conversation store (message gets a new UID in target)
      removeMessageFromList(uid, sourceFolder);
      markLocalRemoval(sourceFolder, 1);
      conversationsStore.removeMessageLocally(sourceFolder, uid);
      conversationsRefreshTrigger.value++;

      // Refresh folder counts and target folder conversations
      fetchFolders(true);

      // Refresh target folder conversations so the new UID is properly indexed
      try {
        await conversationsStore.fetchConversations(targetFolder);
      } catch (e) {
        // Non-critical
      }

      return true;
    } catch (e) {
      console.error("Failed to restore message:", e);
      return false;
    }
  }

  // Restore all messages from Trash to INBOX
  async function restoreAllFromTrash(targetFolder = "INBOX") {
    try {
      const sourceFolder = currentFolder.value;

      const response = await api.post(
        apiCollectionUrl(sourceFolder, "restore-all"),
        {
          target_folder: targetFolder,
        }
      );

      if (response.data.success) {
        // Clear all messages from source folder view and canonical store
        const viewKeys = folderViews.get(sourceFolder) || [];
        const removedCount = viewKeys.length;
        for (const key of viewKeys) {
          const msg = messagesByKey.get(key);
          if (msg) conversationsStore.removeMessageLocally(sourceFolder, msg.uid);
          messagesByKey.delete(key);
        }
        folderViews.set(sourceFolder, []);
        if (removedCount > 0) markLocalRemoval(sourceFolder, removedCount);
        currentMessage.value = null;
        conversationsRefreshTrigger.value++;
        fetchFolders(true);

        // Refresh target folder conversations for new UIDs
        try {
          await conversationsStore.fetchConversations(targetFolder);
        } catch (e) {
          // Non-critical
        }

        return response.data.data;
      }
      return null;
    } catch (e) {
      console.error("Failed to restore all messages:", e);
      return null;
    }
  }

  // Bulk restore selected messages
  async function bulkRestoreMessages(uids, targetFolder = "INBOX") {
    if (uids.length === 0) return { success: 0, failed: 0 };

    bulkProgress.value = {
      active: true,
      current: 0,
      total: uids.length,
      action: "restoring",
    };

    let success = 0;
    let failed = 0;

    for (const uid of uids) {
      const result = await restoreMessage(uid, targetFolder);
      if (result) {
        success++;
      } else {
        failed++;
      }
      bulkProgress.value.current++;
    }

    bulkProgress.value.active = false;

    // Refetch current page so the list fills back up with remaining messages
    await refetchAfterBulkOperation();

    return { success, failed };
  }

  /**
   * Bulk flag toggle (mark read/unread/star/unstar across many messages).
   *
   * The backend has no batch-flag endpoint after the post-daemon revert, so
   * we fan out individual flag calls in parallel. Each call is a small POST
   * that Dovecot answers in < 10ms, so even 100 messages finish in a fraction
   * of a second. We do one fetchFolders(true) at the end instead of per-call
   * (which is what the single setFlag does) to avoid hammering /mailbox/folders.
   *
   * @param {Array<{uid:number, folder?:string}>|Array<number>} items
   * @param {'seen'|'flagged'|'answered'|'deleted'|'draft'} flag
   * @param {boolean} value
   * @returns {Promise<{success:number, failed:number}>}
   */
  async function bulkSetFlag(items, flag, value) {
    if (!items || items.length === 0) return { success: 0, failed: 0 };

    const normalized = items.map(item => ({
      uid: typeof item === 'object' ? item.uid : item,
      folder: typeof item === 'object' ? (item.folder || currentFolder.value) : currentFolder.value,
    }));

    bulkProgress.value = {
      active: true,
      current: 0,
      total: normalized.length,
      action: value ? `marking ${flag}` : `unmarking ${flag}`,
    };

    // Optimistic local updates -- match the single setFlag() pattern.
    for (const { uid, folder } of normalized) {
      const msg = findMessageByUid(uid, folder);
      if (msg) msg[flag] = value;
      if (currentMessage.value?.uid === uid && (!currentMessage.value.folder || currentMessage.value.folder === folder)) {
        currentMessage.value[flag] = value;
      }
    }
    conversationsRefreshTrigger.value++;

    const promises = normalized.map(async ({ uid, folder }) => {
      try {
        await api.post(
          apiCollectionUrl(folder, `messages/${uid}/flag?flag=${encodeURIComponent(flag)}&value=${value ? '1' : '0'}`),
          { flag, value, clientOpId: newOpId() }
        );
        bulkProgress.value.current++;
        return true;
      } catch (e) {
        console.error(`[bulkSetFlag] FAILED uid=${uid} folder=${folder}:`, e?.response?.status, e?.response?.data);
        const msg = findMessageByUid(uid, folder);
        if (msg) msg[flag] = !value;
        bulkProgress.value.current++;
        return false;
      }
    });

    const results = await Promise.all(promises);
    const success = results.filter(Boolean).length;
    const failed = results.length - success;

    bulkProgress.value.active = false;
    conversationsRefreshTrigger.value++;

    // One folder-count refresh at the end instead of N (the single setFlag
    // does it per-call, which is wasteful when called from a loop).
    fetchFolders(true);

    return { success, failed };
  }

  /**
   * Bulk pin/unpin across many messages.
   * Reuses pinEmail/unpinEmail so the local pinnedEmails.value list stays
   * coherent. Sequential (not parallel) because the list ops are not
   * concurrency-safe and pinning is a rare bulk action anyway.
   *
   * @param {Array<{uid:number, folder?:string, message_id?:string, subject?:string}>} items
   * @param {boolean} shouldPin
   * @returns {Promise<{success:number, failed:number}>}
   */
  async function bulkSetPin(items, shouldPin) {
    if (!items || items.length === 0) return { success: 0, failed: 0 };

    const normalized = items.map(item => ({
      uid: item.uid,
      folder: item.folder || currentFolder.value,
      message_id: item.message_id,
      subject: item.subject,
    }));

    bulkProgress.value = {
      active: true,
      current: 0,
      total: normalized.length,
      action: shouldPin ? 'pinning' : 'unpinning',
    };

    let success = 0;
    let failed = 0;
    for (const item of normalized) {
      let ok;
      if (shouldPin) {
        ok = await pinEmail(item.uid, item.folder, { message_id: item.message_id, subject: item.subject });
      } else {
        ok = await unpinEmail(item.uid, item.folder);
      }
      if (ok) success++; else failed++;
      bulkProgress.value.current++;
    }

    bulkProgress.value.active = false;
    return { success, failed };
  }

  // Signal that message properties changed (flags, labels, etc.)
  // Forces the conversations computed to re-evaluate.
  // Call after directly mutating message objects outside of store actions.
  function notifyMessagesChanged() {
    conversationsRefreshTrigger.value++;
  }

  async function search(query, filters = {}) {
    if (currentFolder.value === 'ALL_MAIL' || currentFolder.value === 'SEARCH_RESULTS') {
      return searchAllFolders(query, filters);
    }

    // Shares the monotonic fetchMessagesToken so the most recent request
    // always wins and stale single-folder search responses are discarded.
    const token = ++fetchMessagesToken;
    const searchFolder = currentFolder.value;
    loading.value.messages = true;
    try {
      const response = await api.get("/mailbox/search", {
        params: {
          q: query,
          folder: searchFolder,
          ...filters,
        },
      });

      if (token !== fetchMessagesToken) return;

      if (response.data.success) {
        const incoming = response.data.data.messages;
        const keys = upsertMessages(incoming, searchFolder);
        if (token !== fetchMessagesToken) return;
        folderViews.set(searchFolder, keys);
        pagination.value = {
          page: 1,
          pages: 1,
          total: response.data.data.count,
          limit: pagination.value.limit,
        };
      }
    } catch (e) {
      console.error("Search failed:", e);
    } finally {
      if (token === fetchMessagesToken) loading.value.messages = false;
    }
  }

  async function searchAllFolders(query, filters = {}) {
    // Shares the monotonic fetchMessagesToken so a newer search, folder
    // switch, or fetchMessages/fetchAllMail fences off this in-flight request.
    // Without it, slow earlier searches (from the typing debounce) resolve
    // after faster later ones and wipe the result list to "0 / 0 messages".
    const token = ++fetchMessagesToken;
    loading.value.messages = true;
    try {
      const response = await api.get("/mailbox/search", {
        params: {
          q: query,
          all_folders: true,
          ...filters,
        },
      });

      if (token !== fetchMessagesToken) return;

      if (response.data.success) {
        const incoming = response.data.data.messages;
        // For search results, upsert each with its own real folder
        const keys = upsertMessages(incoming);
        if (token !== fetchMessagesToken) return;
        currentFolder.value = "SEARCH_RESULTS";
        folderViews.set("SEARCH_RESULTS", keys);
        pagination.value = {
          page: 1,
          pages: 1,
          total: response.data.data.count,
          limit: pagination.value.limit,
        };
      }
    } catch (e) {
      console.error("Search all folders failed:", e);
    } finally {
      if (token === fetchMessagesToken) loading.value.messages = false;
    }
  }

  // Virtual "All Mail" folder - fetches messages from all folders.
  //
  // Shares the monotonic fetchMessagesToken with fetchMessages so an
  // in-flight per-folder request gets fenced off (its results discarded)
  // the moment the user switches to All Mail, and vice-versa. Without
  // this fence, a stale fetchMessages('INBOX') response can resolve
  // mid-fetchAllMail and silently overwrite pagination / repopulate the
  // INBOX view, contributing to the "jumps back to Inbox" race.
  async function fetchAllMail(page = 1) {
    const token = ++fetchMessagesToken;
    loading.value.messages = true;
    currentFolder.value = "ALL_MAIL";

    try {
      const response = await api.get("/mailbox/search", {
        params: {
          q: "",
          all_folders: true,
          page,
          limit: pagination.value.limit,
        },
      });

      if (token !== fetchMessagesToken) return;

      if (response.data.success) {
        const data = response.data.data;
        const keys = upsertMessages(data.messages);
        if (token !== fetchMessagesToken) return;
        folderViews.set("ALL_MAIL", keys);
        pagination.value = {
          page: data.page ?? 1,
          pages: data.pages ?? 1,
          total: data.total ?? data.count,
          limit: data.limit ?? pagination.value.limit,
        };
        // Surface degraded folders (from Wave 1 backend); reset dismissal
        // whenever a new payload arrives so a fresh failure is visible.
        const nextDegraded = Array.isArray(data.degraded_folders)
          ? data.degraded_folders
          : [];
        const prevSig = JSON.stringify(
          allMailDegraded.value.map((f) => f.folder_path).sort()
        );
        const nextSig = JSON.stringify(
          nextDegraded.map((f) => f.folder_path).sort()
        );
        allMailDegraded.value = nextDegraded;
        if (prevSig !== nextSig) {
          allMailDegradedDismissed.value = false;
        }
        touchLastRefreshed("ALL_MAIL");
      }
    } catch (e) {
      console.error("Failed to fetch all mail:", e);
    } finally {
      if (token === fetchMessagesToken) loading.value.messages = false;
    }
  }

  // ====== MAILBOX STORAGE QUOTA ======
  // Dovecot-enforced mailbox limit + current usage (via IMAP GETQUOTAROOT).
  // Only meaningful when the server returns an enforced limit; unlimited /
  // OAuth accounts report enabled=false and the sidebar card stays hidden.
  const mailboxQuota = ref({ enabled: false, used_bytes: 0, limit_bytes: 0, unlimited: true });

  const formattedMailboxQuota = computed(() => {
    const q = mailboxQuota.value || {};
    const formatSize = (bytes) => {
      bytes = Number(bytes) || 0;
      if (bytes >= 1099511627776) return (bytes / 1099511627776).toFixed(2) + " TB";
      if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + " GB";
      if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + " MB";
      if (bytes >= 1024) return (bytes / 1024).toFixed(2) + " KB";
      return bytes + " bytes";
    };
    const used = Number(q.used_bytes) || 0;
    const limit = Number(q.limit_bytes) || 0;
    const hasLimit = !!q.enabled && limit > 0 && !q.unlimited;

    // Keep tiny-but-nonzero usage visible (0.08% would otherwise look like 0%).
    let percentUsed = 0;
    if (hasLimit) {
      const raw = (used / limit) * 100;
      percentUsed = raw > 0 && raw < 1 ? Math.round(raw * 10) / 10 : Math.round(raw);
    }

    return {
      enabled: hasLimit,
      used: formatSize(used),
      quota: hasLimit ? formatSize(limit) : "Unlimited",
      percentUsed,
    };
  });

  async function fetchMailboxQuota() {
    try {
      const response = await api.get("/mailbox/quota");
      if (response.data?.success && response.data.data) {
        mailboxQuota.value = response.data.data;
      }
    } catch (_e) {
      // Non-fatal: the quota card simply stays hidden.
    }
  }

  // Scheduled emails count (for sidebar badge)
  const scheduledCount = ref(0);

  async function fetchScheduledEmails() {
    const token = ++fetchMessagesToken;
    loading.value.messages = true;
    currentFolder.value = "SCHEDULED";

    try {
      const response = await api.get("/messages/scheduled");
      if (token !== fetchMessagesToken) return;

      if (response.data.success) {
        const scheduled = response.data.data.scheduled || [];
        scheduledCount.value = scheduled.length;
        
        // Map scheduled emails to a format compatible with EmailList
        // scheduled_at comes from DB in UTC (session set to +00:00).
        // Append 'Z' so JS Date parses as UTC → displays in user's local TZ.
        const utcMark = (dt) => dt && !dt.endsWith('Z') ? dt.replace(' ', 'T') + 'Z' : dt;
        const mappedScheduled = scheduled.map(item => ({
          uid: `scheduled_${item.schedule_id}`,
          message_id: `scheduled_${item.schedule_id}`,
          subject: item.subject || '(No subject)',
          from_name: 'Me',
          from_email: '',
          to: item.recipients || [],
          date: utcMark(item.scheduled_at),
          seen: true,
          flagged: false,
          folder: 'SCHEDULED',
          body_preview: item.recipients?.length ? `To: ${item.recipients.map(r => r.name || r.email || r).join(', ')}` : '',
          isScheduled: true,
          schedule_id: item.schedule_id,
          scheduled_at: utcMark(item.scheduled_at),
          schedule_status: item.status,
          schedule_error: item.error_message || null,
          timezone: item.timezone,
          created_at: item.created_at,
        }));
        if (token !== fetchMessagesToken) return;
        const keys = upsertMessages(mappedScheduled, 'SCHEDULED');
        folderViews.set('SCHEDULED', keys);

        pagination.value = {
          page: 1,
          pages: 1,
          total: scheduled.length,
          limit: scheduled.length,
        };
      }
    } catch (e) {
      console.error("Failed to fetch scheduled emails:", e);
      if (token === fetchMessagesToken) folderViews.set('SCHEDULED', []);
    } finally {
      if (token === fetchMessagesToken) loading.value.messages = false;
    }
  }

  // Refresh scheduled count without changing current view
  async function refreshScheduledCount() {
    try {
      const response = await api.get("/messages/scheduled");
      if (response.data.success) {
        scheduledCount.value = (response.data.data.scheduled || []).length;
      }
    } catch (e) {
      // Silent fail
    }
  }

  // Preview a scheduled email (read-only, does NOT cancel it)
  async function previewScheduledEmail(scheduleId, listItem) {
    try {
      const response = await api.get(`/messages/schedule/${scheduleId}`);
      if (!response.data.success || !response.data.data?.scheduled) {
        console.error('Scheduled email not found');
        return;
      }

      const scheduled = response.data.data.scheduled;
      const payload = scheduled.email_payload || {};
      // Mark scheduled_at as UTC for correct JS Date parsing
      const utcMark = (dt) => dt && !dt.endsWith('Z') ? dt.replace(' ', 'T') + 'Z' : dt;
      const scheduledAtUTC = utcMark(scheduled.scheduled_at);

      // Build a currentMessage-compatible object for EmailView to display
      currentMessage.value = {
        uid: listItem?.uid || `scheduled_${scheduleId}`,
        message_id: `scheduled_${scheduleId}`,
        subject: payload.subject || '(No subject)',
        from: [{ name: payload.from_name || 'Me', email: payload.from_email || '' }],
        from_name: payload.from_name || 'Me',
        from_email: payload.from_email || '',
        to: Array.isArray(payload.to) ? payload.to.map(r => ({
          name: r.name || '',
          email: r.email || r,
        })) : [],
        cc: Array.isArray(payload.cc) ? payload.cc.map(r => ({
          name: r.name || '',
          email: r.email || r,
        })) : [],
        bcc: Array.isArray(payload.bcc) ? payload.bcc.map(r => ({
          name: r.name || '',
          email: r.email || r,
        })) : [],
        body_html: payload.body_html || '',
        body_text: payload.body_text || '',
        date: scheduledAtUTC,
        timestamp: Math.floor(new Date(scheduledAtUTC).getTime() / 1000),
        seen: true,
        flagged: false,
        has_attachment: (payload.attachments || []).length > 0,
        attachments: payload.attachments || [],
        folder: 'SCHEDULED',
        isScheduled: true,
        schedule_id: scheduleId,
        scheduled_at: scheduledAtUTC,
        timezone: scheduled.timezone,
      };
    } catch (e) {
      console.error('Failed to preview scheduled email:', e);
    }
  }

  function selectMessage(uid, folder = null) {
    const f = folder || currentFolder.value;
    const key = makeSelectionKey(f, uid);
    if (selectedMessages.value.includes(key)) {
      selectedMessages.value = selectedMessages.value.filter(
        (k) => k !== key
      );
    } else {
      selectedMessages.value.push(key);
    }
  }

  function selectAllMessages() {
    selectedMessages.value = messages.value.map((m) => 
      makeSelectionKey(getMessageFolder(m), m.uid)
    );
  }

  function selectNone() {
    selectedMessages.value = [];
  }

  function selectRead() {
    selectedMessages.value = messages.value
      .filter((m) => m.seen)
      .map((m) => makeSelectionKey(getMessageFolder(m), m.uid));
  }

  function selectUnread() {
    selectedMessages.value = messages.value
      .filter((m) => !m.seen)
      .map((m) => makeSelectionKey(getMessageFolder(m), m.uid));
  }

  function selectStarred() {
    selectedMessages.value = messages.value
      .filter((m) => m.flagged)
      .map((m) => makeSelectionKey(getMessageFolder(m), m.uid));
  }

  function selectUnstarred() {
    selectedMessages.value = messages.value
      .filter((m) => !m.flagged)
      .map((m) => makeSelectionKey(getMessageFolder(m), m.uid));
  }

  function clearSelection() {
    selectedMessages.value = [];
  }

  // Auto-clear selections when the active folder changes so stale composite
  // keys from the previous folder can never affect bulk operations.
  watch(currentFolder, () => {
    clearSelection()
  })

  // Get UIDs from selected messages (for backward compatibility with bulk operations)
  // Returns array of { folder, uid } objects
  function getSelectedMessagesData() {
    return selectedMessages.value.map(parseSelectionKey);
  }

  function clearCurrentMessage() {
    currentMessage.value = null;
  }

  function toggleConversationView() {
    conversationView.value = !conversationView.value;
    localStorage.setItem("conversationView", conversationView.value.toString());
    expandedConversations.value.clear();
    conversationKeys.clear();
  }

  async function toggleConversationExpanded(key) {
    if (expandedConversations.value.has(key)) {
      expandedConversations.value.delete(key);
      expandedConversations.value = new Set(expandedConversations.value);
    } else {
      await fetchThreadMessages(key);
      expandedConversations.value.add(key);
      expandedConversations.value = new Set(expandedConversations.value);
      conversationsRefreshTrigger.value++;
    }
  }

  async function fetchThreadMessages(conversationKey, forceRefresh = false) {
    // Return immediately if we already have thread data (unless forcing refresh)
    if (!forceRefresh && conversationKeys.has(conversationKey) && conversationKeys.get(conversationKey).length > 0) {
      isDebugEnabled() && console.log(`[Thread] Cache hit for ${conversationKey} - ${conversationKeys.get(conversationKey).length} messages`);
      return;
    }

    const conv = conversations.value.find(
      (c) => c.conversationKey === conversationKey
    );
    if (!conv) return;

    const normalizeId = (id) => (id ? id.replace(/^<|>$/g, "").trim() : null);
    const seenMsgIds = new Set();
    const seenUidKeys = new Set();
    const collectedKeys = [];

    // Collect existing conversation messages
    for (const msg of (conv.messages || [])) {
      if (!msg.folder) continue;
      const msgFolder = msg.folder;
      const normId = normalizeId(msg.message_id);
      const uidKey = `${msg.uid}-${msgFolder}`;
      if (seenUidKeys.has(uidKey)) continue;
      upsertMessage({ ...msg, is_sent: msgFolder.toLowerCase() !== currentFolder.value.toLowerCase(), folder: msgFolder }, msgFolder);
      collectedKeys.push(`${msgFolder}:${Number(msg.uid)}`);
      if (normId) seenMsgIds.add(normId);
      seenUidKeys.add(uidKey);
    }

    // Try database-first approach using conversation_id (faster than IMAP)
    let usedDatabaseApi = false;

    if (conv.conversation_id) {
      try {
        isDebugEnabled() && console.log(`[Thread] Trying database API for conversation: ${conv.conversation_id}`);
        const dbResponse = await api.get(`/conversations/${encodeURIComponent(conv.conversation_id)}/messages/global`);

        if (dbResponse.data.success && dbResponse.data.data.messages?.length > 0) {
          for (const msg of dbResponse.data.data.messages) {
            if (!msg.folder) continue;
            const normId = normalizeId(msg.message_id);
            const uidKey = `${msg.uid}-${msg.folder}`;
            if (seenMsgIds.has(normId) || seenUidKeys.has(uidKey)) continue;
            const f = msg.folder;
            const dbMsg = { ...msg, is_sent: f.toLowerCase() !== currentFolder.value.toLowerCase(), folder: f };
            if (dbMsg.seen === undefined) dbMsg.seen = true;
            upsertMessage(dbMsg, f);
            collectedKeys.push(`${f}:${Number(msg.uid)}`);
            if (normId) seenMsgIds.add(normId);
            seenUidKeys.add(uidKey);
          }
          usedDatabaseApi = true;
        }
      } catch (e) {
        console.warn("[Thread] Database API failed, falling back to IMAP:", e.message);
      }
    }

    // FALLBACK: IMAP search
    if (!usedDatabaseApi) {
      try {
        isDebugEnabled() && console.log(`[Thread] Using IMAP search (slow path)`);
        const params = { current_folder: currentFolder.value };
        if (conv.threadReferences?.length > 0) params.references = JSON.stringify(conv.threadReferences);
        if (conv.message_id) params.message_id = conv.message_id;

        const response = await api.get("/mailbox/thread", { params });
        if (response.data.success && response.data.data.messages) {
          for (const msg of response.data.data.messages) {
            if (!msg.folder) continue;
            const normId = normalizeId(msg.message_id);
            const uidKey = `${msg.uid}-${msg.folder}`;
            if (seenMsgIds.has(normId) || seenUidKeys.has(uidKey)) continue;
            const f = msg.folder;
            const imapMsg = { ...msg, is_sent: f.toLowerCase() !== currentFolder.value.toLowerCase(), folder: f };
            if (imapMsg.seen === undefined) imapMsg.seen = true;
            upsertMessage(imapMsg, f);
            collectedKeys.push(`${f}:${Number(msg.uid)}`);
            if (normId) seenMsgIds.add(normId);
            seenUidKeys.add(uidKey);
          }
        }
      } catch (e) {
        console.error("Failed to fetch thread messages from API:", e);
      }
    }

    // Sort keys by timestamp (newest first) and store
    collectedKeys.sort((a, b) => {
      const ma = messagesByKey.get(a);
      const mb = messagesByKey.get(b);
      return ((mb?.timestamp || 0) - (ma?.timestamp || 0));
    });

    conversationKeys.set(conversationKey, collectedKeys);
    isDebugEnabled() && console.log(`[Thread] Stored ${collectedKeys.length} keys for ${conversationKey}`);

    // Pre-fetch bodies for instant display
    const threadMsgs = collectedKeys.map(k => messagesByKey.get(k)).filter(Boolean);
    await prefetchThreadBodies(threadMsgs);
  }
  
  /**
   * Pre-fetch all message bodies for a thread in one batch call
   * This ensures instant display when user clicks on thread messages
   */
  async function prefetchThreadBodies(threadMsgs) {
    if (!threadMsgs || threadMsgs.length === 0) return;

    const byFolder = {};
    for (const msg of threadMsgs) {
      if (!msg.uid || typeof msg.uid !== 'number' || msg.uid <= 0) continue;
      const folder = msg.folder || currentFolder.value;
      const key = `${folder}:${msg.uid}`;
      const existing = messagesByKey.get(key);
      if (existing?.body_html || existing?.body_text) continue;
      if (!byFolder[folder]) byFolder[folder] = [];
      byFolder[folder].push(msg.uid);
    }

    for (const [folder, uids] of Object.entries(byFolder)) {
      if (uids.length === 0) continue;
      try {
        const response = await api.post(
          apiCollectionUrl(folder, "messages/batch"),
          { uids }
        );
        if (response.data.success && response.data.data.messages) {
          for (const [uid, message] of Object.entries(response.data.data.messages)) {
            const existing = messagesByKey.get(`${folder}:${Number(uid)}`);
            const merged = { ...message, folder };
            if (existing?.seen && !merged.seen) merged.seen = true;
            upsertMessage(merged, folder);
          }
        }
      } catch (e) {
        console.warn(`[Thread] Failed to prefetch bodies from ${folder}:`, e.message);
      }
    }
  }

  // Get messages for a conversation by resolving keys from the canonical store
  function getConversationMessages(conversationKey) {
    // Check conversationKeys (populated by fetchThreadMessages)
    const keys = conversationKeys.get(conversationKey);
    if (keys?.length > 0) {
      return keys.map(k => messagesByKey.get(k)).filter(Boolean);
    }
    // Try conversation_id as alternate key
    const conv = conversations.value.find(
      (c) => c.conversationKey === conversationKey || c.conversation_id === conversationKey
    );
    if (conv?.conversation_id) {
      const altKeys = conversationKeys.get(conv.conversation_id);
      if (altKeys?.length > 0) {
        return altKeys.map(k => messagesByKey.get(k)).filter(Boolean);
      }
    }
    return conv?.messages || [];
  }

  /**
   * Add a message to an existing conversation thread
   * Called after sending a reply to immediately update the UI
   */
  /**
   * Clear thread caches - used after split to force rebuild
   */
  function clearThreadCaches() {
    conversationKeys.clear();
    expandedConversations.value.clear();
    expandedConversations.value = new Set();
  }

  function addMessageToThread(conversationKey, message) {
    if (!conversationKey || !message) return;

    const enrichedMessage = {
      ...message,
      is_sent: true,
      seen: true,
      timestamp: message.timestamp || Math.floor(Date.now() / 1000),
      uid: message.uid || `sent-${Date.now()}`,
      folder: message.folder || currentFolder.value,
    };

    const msgFolder = enrichedMessage.folder;
    const key = upsertMessage(enrichedMessage, msgFolder);

    // Initialize conversationKeys if not exists
    if (!conversationKeys.has(conversationKey)) {
      const conv = conversations.value.find(
        (c) => c.conversationKey === conversationKey || c.conversation_id === conversationKey
      );
      const existingKeys = (conv?.messages || []).map(m => {
        const f = m.folder || currentFolder.value;
        return `${f}:${Number(m.uid)}`;
      });
      conversationKeys.set(conversationKey, existingKeys);
    }

    const keys = conversationKeys.get(conversationKey);
    if (!keys.includes(key)) {
      keys.unshift(key);
      // Re-sort by timestamp (newest first)
      keys.sort((a, b) => {
        const ma = messagesByKey.get(a);
        const mb = messagesByKey.get(b);
        return ((mb?.timestamp || 0) - (ma?.timestamp || 0));
      });
      conversationsRefreshTrigger.value++;
    }
  }

  function isConversationExpanded(key) {
    return expandedConversations.value.has(key);
  }

  /**
   * Find actual folder name by path (case-insensitive lookup)
   * Handles URL-style paths like 'inbox.work.greyskull' and finds 'INBOX.work.greyskull'
   * @param {string} folderPath - The folder path to look up
   * @returns {string} - The actual folder name from the folders list, or the input if not found
   */
  function findFolderByPath(folderPath) {
    if (!folderPath) return "INBOX";
    if (!folders.value || folders.value.length === 0) return folderPath;

    // Handle INBOX prefix normalization
    let normalizedPath = folderPath;
    if (normalizedPath.toLowerCase().startsWith("inbox.")) {
      normalizedPath = "INBOX." + normalizedPath.substring(6);
    } else if (normalizedPath.toLowerCase() === "inbox") {
      normalizedPath = "INBOX";
    }

    // Try exact match first
    const exact = folders.value.find((f) => f.name === normalizedPath);
    if (exact) return exact.name;

    // Try case-insensitive match
    const lowerPath = normalizedPath.toLowerCase();
    const found = folders.value.find((f) => f.name.toLowerCase() === lowerPath);
    return found ? found.name : normalizedPath;
  }

  /**
   * Normalize a folder name to match the actual folder in the list
   * This is used to ensure cache keys and API calls use consistent folder names
   * @param {string} folderName - The folder name to normalize
   * @returns {string} - The normalized folder name
   */
  function normalizeFolderName(folderName) {
    return findFolderByPath(folderName);
  }

  /**
   * Wave 2 P2: emit canonical /folders/{folder_id}/* HTTP URLs when the
   * current folder list knows the folder_id. Falls back to legacy
   * /mailbox/{folder}/* when the id isn't known yet (fresh accounts /
   * brand-new folders). The backend serves both shapes via the dual-route
   * registration in routes.php; the cutover gate flips to canonical-only
   * once `legacy_route_hits_24h` stays at 0 for 7 days.
   *
   * IMPORTANT: these are HTTP URLs (network), NOT Vue Router URLs (address
   * bar). The address bar stays path-shaped for Gmail-style UX.
   */
  function apiCollectionUrl(folder, subpath = "") {
    return folderCollectionUrl(folders.value, folder, subpath);
  }
  function apiResourceUrl(folder, subpath = "") {
    return folderResourceUrl(folders.value, folder, subpath);
  }

  // ===========================================
  // PERSISTENT CONVERSATION MANAGEMENT
  // ===========================================

  /**
   * Move a message to a different conversation (user action)
   * This persists to the database and updates the UI
   */
  async function moveMessageToConversation(messageId, targetConversationId, folder = null) {
    const targetFolder = folder || currentFolder.value;
    const conversationsStore = useConversationsStore();
    
    // Get source conversation ID before move (to invalidate its cache)
    const sourceConversationId = conversationsStore.getConversationId(messageId);
    
    isDebugEnabled() && console.log('[CONV-DEBUG] moveMessageToConversation START', {
      messageId,
      sourceConversationId,
      targetConversationId,
      currentMessageUid: currentMessage.value?.uid,
      currentMessageIsConv: currentMessage.value?.isConversation
    });
    
    const result = await conversationsStore.moveMessage(
      targetFolder,
      messageId,
      targetConversationId
    );
    
    isDebugEnabled() && console.log('[CONV-DEBUG] moveMessage result', result);
    
    if (result?.moved) {
      if (sourceConversationId) conversationKeys.delete(sourceConversationId);
      if (targetConversationId) conversationKeys.delete(targetConversationId);
      clearMessageBody(targetFolder, messageId);
      conversationsRefreshTrigger.value++;

      isDebugEnabled() && console.log('[CONV-DEBUG] moveMessageToConversation DONE', {
        currentMessageUid: currentMessage.value?.uid,
        currentMessageIsConv: currentMessage.value?.isConversation
      });

      return true;
    }
    return false;
  }

  /**
   * Split a message into a new conversation (user action)
   * This persists to the database and updates the UI
   */
  async function splitMessageToNewConversation(messageId, folder = null) {
    const targetFolder = folder || currentFolder.value;
    const conversationsStore = useConversationsStore();

    const sourceConversationId = conversationsStore.getConversationId(messageId);

    isDebugEnabled() && console.log('[CONV-DEBUG] splitMessageToNewConversation START', {
      messageId,
      sourceConversationId,
      currentMessageUid: currentMessage.value?.uid,
      currentMessageIsConv: currentMessage.value?.isConversation,
      currentMessageConvKey: currentMessage.value?.conversationKey
    });

    const result = await conversationsStore.splitMessage(targetFolder, messageId);

    isDebugEnabled() && console.log('[CONV-DEBUG] splitMessage result', result);

    if (result?.split) {
      if (sourceConversationId) conversationKeys.delete(sourceConversationId);
      clearMessageBody(targetFolder, messageId);
      conversationsRefreshTrigger.value++;

      isDebugEnabled() && console.log('[CONV-DEBUG] splitMessageToNewConversation DONE', {
        newConversationId: result.newConversationId,
        currentMessageUid: currentMessage.value?.uid,
        currentMessageIsConv: currentMessage.value?.isConversation,
        currentMessageConvKey: currentMessage.value?.conversationKey
      });

      return result.newConversationId;
    }
    return null;
  }

  /**
   * Get conversation list for a folder (from backend)
   */
  function getConversationsForFolder(folder = null) {
    const targetFolder = folder || currentFolder.value;
    return conversationsStore.getConversationsList(targetFolder);
  }

  /**
   * Wave 2 P2: update the locally-cached folder_identity_version baseline.
   * Called from three places:
   *   1. bootstrap hydration (initial value at app load)
   *   2. WebSocket FOLDER_CHANGED event handler (atomic with the event)
   *   3. /mailbox/folders/identity-version polling on reconnect
   *
   * Monotonic: never moves backwards. Treat 0 as "unknown / Redis was down"
   * and only persist non-zero values; 0 must not overwrite a real number.
   */
  function setFolderIdentityVersion(value) {
    const v = Number(value) || 0;
    if (v <= 0) return;
    if (v > folderIdentityVersion.value) {
      folderIdentityVersion.value = v;
    }
  }

  /**
   * Compare a remote folder_identity_version against our local baseline.
   * Returns true when the remote value is higher (= we missed events),
   * which is the signal for callers to invalidate folder caches.
   * Returns false when versions match or remote is older / 0 / unknown.
   */
  function isFolderIdentityVersionStale(remoteVersion) {
    const remote = Number(remoteVersion) || 0;
    if (remote <= 0) return false;
    return remote > folderIdentityVersion.value;
  }

  /**
   * Invalidate every folder-keyed cache and refetch the folder list.
   * Called when a missed-event drift is detected. Best-effort: this is a
   * recovery path so we don't bother surfacing failures to the user; the
   * next event-driven update will repair anything we missed.
   */
  async function invalidateAllFoldersFromDrift(reason = 'unknown') {
    try {
      console.warn(`[mailbox] folder identity drift detected (reason=${reason}); invalidating folder caches`);
      messagesByKey.clear();
      folderViews.clear();
      try {
        if (conversationsStore?.clearAll) {
          conversationsStore.clearAll();
        }
      } catch (e) { /* non-critical */ }
      await fetchFolders(true);
      if (currentFolder.value) {
        await fetchMessages(currentFolder.value, 1);
      }
    } catch (e) {
      console.error('[mailbox] invalidateAllFoldersFromDrift error:', e);
    }
  }

  return {
    // State
    folders,
    messages,
    currentFolder,
    currentMessage,
    selectedMessages,
    pagination,
    loading,
    bulkProgress,
    conversationView,
    expandedConversations,
    scrollToMessageUid,

    // Canonical store
    messagesByKey,
    folderViews,
    conversationKeys,
    upsertMessage,
    upsertMessages,
    clearMessages,
    clearFolderView,
    findMessageByUid,

    // Local-action removal tracking (consumed by useMailSyncIntegration
    // to suppress redundant page-1 refetches on totalDecreased events
    // that the local view already accounts for).
    markLocalRemoval,
    consumeLocalRemovals,
    unmarkLocalRemoval,
    hasPendingLocalRemovals,
    isCurrentAccountOAuth,

    // Getters
    unreadCount,
    currentFolderData,
    conversations,
    notifyMessagesChanged,

    // Actions
    fetchFolders,
    refreshFoldersQuietly,
    removeMessageFromList,
    createFolder,
    renameFolder,
    deleteFolder,
    fetchMessages,
    refreshCurrentFolder,
    revalidateActiveFolder,
    getLastRefreshed,
    fetchMessage,
    fetchMessageFromFolder,
    setFlag,
    bulkSetFlag,
    moveMessage,
    deleteMessage,
    bulkDeleteMessages,
    bulkMoveMessages,
    restoreMessage,
    restoreAllFromTrash,
    bulkRestoreMessages,
    search,
    searchAllFolders,
    fetchAllMail,
    fetchScheduledEmails,
    refreshScheduledCount,
    previewScheduledEmail,
    scheduledCount,
    mailboxQuota,
    formattedMailboxQuota,
    fetchMailboxQuota,
    selectMessage,
    isMessageSelected,
    getSelectedMessagesData,
    makeSelectionKey,
    parseSelectionKey,
    getMessageFolder,
    getConversationMessages,
    addMessageToThread,
    clearThreadCaches,
    selectAllMessages,
    selectNone,
    selectRead,
    selectUnread,
    selectStarred,
    selectUnstarred,
    clearSelection,
    clearCurrentMessage,
    toggleConversationView,
    toggleConversationExpanded,
    isConversationExpanded,

    // Message fetching helpers
    fetchMessagesBatch,
    fetchMessagesSince,
    checkFolderSyncState,
    getHighestUid,
    getFolderUidnext,

    // Folder scroll position (UI state)
    folderScrollPositions,
    setFolderScrollPosition,
    getFolderScrollPosition,

    // Folder helpers
    clearFolderCompletely,
    clearMessageBody,
    renameFolderInStore,
    clearFolderFromStore,
    findFolderByPath,
    normalizeFolderName,

    // Pin functionality
    pinnedEmails,
    pinnedEmailsLoaded,
    fetchPinnedEmails,
    pinEmail,
    unpinEmail,
    togglePin,
    bulkSetPin,
    isEmailPinned,

    // Combined init (folders + INBOX in one request)
    initMailbox,
    lastInitAt,

    // All Mail grouping
    allMailGroupMode,
    setAllMailGroupMode,

    // All Mail degraded folders (Wave 1)
    allMailDegraded,
    allMailDegradedDismissed,
    dismissAllMailDegraded,

    // Wave 3 grouping + routing scaffolding (off by default behind feature flag)
    folderGroupsConfig,
    folderGroups,
    setFolderGroupsConfig,
    canonicalRoutingMode,

    // Conversation management (persistent)
    moveMessageToConversation,
    splitMessageToNewConversation,
    getConversationsForFolder,

    // Wave 2 P2: folder identity version (frontend cache invalidation)
    folderIdentityVersion,
    setFolderIdentityVersion,
    isFolderIdentityVersionStale,
    invalidateAllFoldersFromDrift,
  };
});
