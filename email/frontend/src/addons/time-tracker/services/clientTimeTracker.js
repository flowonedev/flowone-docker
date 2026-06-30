/**
 * Client Time Tracker Service
 *
 * Tracks time spent on activities per client and sends to backend periodically.
 * Activities tracked: email_read, email_compose, calendar_event, board_view,
 * board_task, drive_browse, document_open, document_edit, website_work
 */

import api from "@/services/api";
import * as addonBus from "@/services/addonEventBus";
import { isDebugEnabled } from "@/utils/debug";
import { useAddons } from "@/composables/useAddons";

class ClientTimeTracker {
  constructor() {
    // Current tracking state
    this.currentClientId = null;
    this.currentActivityType = null;
    this.currentEntityId = null;
    this.currentEntityName = null;
    this.trackingStartTime = null;

    // Accumulated time to send (keyed by clientId_activityType_entityId)
    this.pendingTime = {};

    // Configuration
    this.syncIntervalMs = 30000; // Send accumulated time every 30 seconds
    this.minTrackingDurationMs = 5000; // Minimum 5 seconds to count
    this.storageKey = "mailflow_client_time_tracker";

    // Interval reference
    this.syncInterval = null;

    // Cached mappings
    this.boardMapping = {}; // board_id -> { client_id, client_name }
    this.folderMapping = {}; // folder_id -> { client_id, client_name }
    this.moodBoardMapping = {}; // mood_board_id -> { client_id, client_name }
    this.mappingsLoaded = false;

    this.initialized = false;

    // Bind methods
    this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
    this.handleBeforeUnload = this.handleBeforeUnload.bind(this);
  }

  /**
   * Deferred init -- called when addon data is guaranteed to be available
   * (from setClientsStore after bootstrap, or lazily from startTracking).
   */
  ensureInit() {
    if (this.initialized) return;

    const { timeTrackerEnabled } = useAddons();
    if (!timeTrackerEnabled.value) return;

    this.initialized = true;

    this.loadPendingTime();

    document.addEventListener("visibilitychange", this.handleVisibilityChange);
    window.addEventListener("beforeunload", this.handleBeforeUnload);

    this.startPeriodicSync();
  }

  destroy() {
    this.stopTracking();
    this.flushPendingTime();

    document.removeEventListener(
      "visibilitychange",
      this.handleVisibilityChange
    );
    window.removeEventListener("beforeunload", this.handleBeforeUnload);

    if (this.syncInterval) {
      clearInterval(this.syncInterval);
      this.syncInterval = null;
    }
    this.initialized = false;
  }

  loadPendingTime() {
    try {
      const stored = localStorage.getItem(this.storageKey);
      if (!stored) return;

      const data = JSON.parse(stored);
      if (!data || typeof data !== "object") return;

      for (const [key, entry] of Object.entries(data)) {
        if (!entry || typeof entry !== "object") continue;
        if (!this.pendingTime[key]) {
          this.pendingTime[key] = entry;
        } else {
          this.pendingTime[key].seconds += entry.seconds || 0;
        }
      }
    } catch (error) {
      console.error("[TimeTracker] Failed to load pending client time:", error);
    }
  }

  savePendingTime() {
    try {
      if (Object.keys(this.pendingTime).length === 0) {
        localStorage.removeItem(this.storageKey);
        return;
      }
      localStorage.setItem(this.storageKey, JSON.stringify(this.pendingTime));
    } catch (error) {
      console.error("[TimeTracker] Failed to save pending client time:", error);
    }
  }

  clearPendingTimeStorage() {
    try {
      localStorage.removeItem(this.storageKey);
    } catch {
      // ignore storage cleanup errors
    }
  }

  /**
   * Load board and folder mappings from backend
   */
  async loadMappings() {
    if (this.mappingsLoaded) return;
    const { timeTrackerEnabled } = useAddons();
    if (!timeTrackerEnabled.value) return;

    try {
      const { kanbanBoardsEnabled, moodboardsEnabled } = useAddons();
      const [boardRes, folderRes, moodBoardRes] = await Promise.all([
        kanbanBoardsEnabled.value ? api.get("/clients/board-mapping").catch(() => null) : null,
        api.get("/clients/folder-mapping").catch(() => null),
        moodboardsEnabled.value ? api.get("/clients/mood-board-mapping").catch(() => null) : null,
      ]);

      if (boardRes?.data?.success) {
        this.boardMapping = boardRes.data.data.mapping || {};
        isDebugEnabled() && console.log(
          "[TimeTracker] Loaded board mappings:",
          Object.keys(this.boardMapping).length
        );
      }

      if (folderRes?.data?.success) {
        this.folderMapping = folderRes.data.data.mapping || {};
        isDebugEnabled() && console.log(
          "[TimeTracker] Loaded folder mappings:",
          Object.keys(this.folderMapping).length
        );
      }

      if (moodBoardRes?.data?.success) {
        this.moodBoardMapping = moodBoardRes.data.data.mapping || {};
        isDebugEnabled() && console.log(
          "[TimeTracker] Loaded mood board mappings:",
          Object.keys(this.moodBoardMapping).length
        );
      }

      this.mappingsLoaded = true;
    } catch (error) {
      console.error("[TimeTracker] Failed to load mappings:", error);
    }
  }

  /**
   * Refresh mappings (call after linking boards/folders to clients)
   */
  async refreshMappings() {
    this.mappingsLoaded = false;
    await this.loadMappings();
  }

  /**
   * Start tracking time for a client activity
   */
  startTracking(clientId, activityType, entityId = null, entityName = null) {
    const { timeTrackerEnabled } = useAddons();
    if (!timeTrackerEnabled.value) return;

    this.ensureInit();

    // If already tracking same thing, don't restart
    if (
      this.currentClientId === clientId &&
      this.currentActivityType === activityType &&
      this.currentEntityId === entityId
    ) {
      return;
    }

    // Stop previous tracking if any
    if (this.currentClientId) {
      this.stopTracking();
    }

    // Start new tracking
    this.currentClientId = clientId;
    this.currentActivityType = activityType;
    this.currentEntityId = entityId;
    this.currentEntityName = entityName;
    this.trackingStartTime = Date.now();

    isDebugEnabled() && console.log(
      `[TimeTracker] Started tracking: client=${clientId}, activity=${activityType}, entity=${
        entityName || entityId
      }`
    );
  }

  /**
   * Stop tracking and accumulate time
   */
  stopTracking() {
    if (!this.currentClientId || !this.trackingStartTime) {
      this.resetTracking();
      return;
    }

    const elapsedMs = Date.now() - this.trackingStartTime;

    // Only count if above minimum threshold
    if (elapsedMs >= this.minTrackingDurationMs) {
      const elapsedSeconds = Math.round(elapsedMs / 1000);
      this.accumulateTime(
        this.currentClientId,
        this.currentActivityType,
        elapsedSeconds,
        this.currentEntityId,
        this.currentEntityName
      );

      isDebugEnabled() && console.log(
        `[TimeTracker] Stopped tracking: ${elapsedSeconds}s for client=${this.currentClientId}`
      );
    }

    this.resetTracking();
  }

  /**
   * Reset tracking state
   */
  resetTracking() {
    this.currentClientId = null;
    this.currentActivityType = null;
    this.currentEntityId = null;
    this.currentEntityName = null;
    this.trackingStartTime = null;
  }

  /**
   * Accumulate time in pending queue
   */
  accumulateTime(
    clientId,
    activityType,
    seconds,
    entityId = null,
    entityName = null,
    extra = null
  ) {
    const key = `${clientId}_${activityType}_${entityId || ""}`;

    if (!this.pendingTime[key]) {
      this.pendingTime[key] = {
        clientId,
        activityType,
        entityId,
        entityName,
        seconds: 0,
      };
    }

    this.pendingTime[key].seconds += seconds;

    if (entityName) {
      this.pendingTime[key].entityName = entityName;
    }
    if (extra) {
      Object.assign(this.pendingTime[key], extra);
    }

    this.savePendingTime();
  }

  /**
   * Send accumulated time to backend
   */
  async flushPendingTime() {
    const { timeTrackerEnabled } = useAddons();
    if (!timeTrackerEnabled.value) { this.pendingTime = {}; return; }

    const entries = Object.values(this.pendingTime);

    if (entries.length === 0) {
      return;
    }

    // Clear pending immediately to avoid double-sends
    this.pendingTime = {};
    this.savePendingTime();

    // Send each entry to the API
    for (const entry of entries) {
      if (entry.seconds <= 0) continue;

      try {
        await api.post(`/clients/${entry.clientId}/time`, {
          activity_type: entry.activityType,
          duration_seconds: entry.seconds,
          entity_id: entry.entityId,
          entity_name: entry.entityName,
        });

        isDebugEnabled() && console.log(
          `[TimeTracker] Synced ${entry.seconds}s for client=${entry.clientId}, activity=${entry.activityType}`
        );

        addonBus.emit('time:synced', entry);
      } catch (error) {
        const status = error?.response?.status;
        
        // Don't retry on 4xx errors (client not found, unauthorized, bad request)
        // These won't magically succeed on retry and just spam the console
        if (status && status >= 400 && status < 500) {
          isDebugEnabled() && console.warn(
            `[TimeTracker] Dropping time entry for client=${entry.clientId} (HTTP ${status} - not retryable)`
          );
          continue;
        }

        console.error("[TimeTracker] Failed to sync time:", error);

        // Re-add to pending queue for retry (only server errors / network issues)
        const key = `${entry.clientId}_${entry.activityType}_${
          entry.entityId || ""
        }`;
        if (!this.pendingTime[key]) {
          this.pendingTime[key] = entry;
        } else {
          this.pendingTime[key].seconds += entry.seconds;
        }
      }
    }

    this.savePendingTime();
  }

  /**
   * Start periodic sync interval
   */
  startPeriodicSync() {
    if (this.syncInterval) {
      clearInterval(this.syncInterval);
    }

    this.syncInterval = setInterval(() => {
      // First stop current tracking to accumulate time
      if (this.currentClientId && this.trackingStartTime) {
        const elapsedMs = Date.now() - this.trackingStartTime;

        if (elapsedMs >= this.minTrackingDurationMs) {
          const elapsedSeconds = Math.round(elapsedMs / 1000);
          this.accumulateTime(
            this.currentClientId,
            this.currentActivityType,
            elapsedSeconds,
            this.currentEntityId,
            this.currentEntityName
          );

          // Reset start time but keep tracking
          this.trackingStartTime = Date.now();
        }
      }

      // Then flush to backend
      this.flushPendingTime();
    }, this.syncIntervalMs);
  }

  /**
   * Handle visibility change (tab switching)
   */
  handleVisibilityChange() {
    if (document.hidden) {
      // Page is hidden, stop tracking
      this.stopTracking();
    }
    // When page becomes visible again, tracking will resume when user interacts
  }

  /**
   * Handle page unload
   */
  handleBeforeUnload() {
    this.stopTracking();
    this.savePendingTime();

    const entries = Object.values(this.pendingTime);
    const token = sessionStorage.getItem("webmail_token") || localStorage.getItem("webmail_token");

    for (const entry of entries) {
      if (entry.seconds <= 0) continue;

      try {
        const url = `/api/clients/${entry.clientId}/time`;
        const data = JSON.stringify({
          activity_type: entry.activityType,
          duration_seconds: entry.seconds,
          entity_id: entry.entityId,
          entity_name: entry.entityName,
        });

        let sent = false;

        try {
          sent = !!fetch(url, {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: data,
            keepalive: true,
          });
        } catch {
          // fall through to beacon
        }

        if (!sent && navigator.sendBeacon) {
          sent = navigator.sendBeacon(
            url,
            new Blob([data], { type: "application/json" })
          );
        }

        if (sent) {
          delete this.pendingTime[`${entry.clientId}_${entry.activityType}_${entry.entityId || ""}`];
        }
      } catch (error) {
        console.error("[TimeTracker] Failed to beacon time:", error);
      }
    }

    if (Object.keys(this.pendingTime).length === 0) {
      this.clearPendingTimeStorage();
    } else {
      this.savePendingTime();
    }
  }

  // ==========================================================================
  // Helper methods for detecting client from context
  // ==========================================================================

  /**
   * Set the clients store reference for lookups
   * Should be called once from main.js or App.vue
   */
  setClientsStore(store) {
    this.clientsStore = store;
    this.ensureInit();
    if (!this.mappingsLoaded) this.loadMappings();
  }

  hydrateFromInit(data) {
    if (!data) return;
    if (data.board_mapping) this.boardMapping = data.board_mapping;
    if (data.folder_mapping) this.folderMapping = data.folder_mapping;
    if (data.mood_board_mapping) this.moodBoardMapping = data.mood_board_mapping;
    this.mappingsLoaded = true;
  }

  /**
   * Get client ID from email addresses
   * Used when viewing/composing emails
   */
  getClientIdFromEmail(emailAddresses, userEmail) {
    if (!emailAddresses || emailAddresses.length === 0 || !this.clientsStore) {
      return null;
    }

    const map = this.clientsStore.domainToClientMap;
    const userDomain = userEmail?.split("@")[1]?.toLowerCase();

    for (const addr of emailAddresses) {
      const email =
        typeof addr === "string" ? addr : addr?.email || addr?.address;
      if (!email) continue;

      const emailLower = email.toLowerCase();
      const domain = emailLower.split("@")[1];
      if (!domain || domain === userDomain) continue;

      // Try exact email match first (handles generic providers like gmail)
      const byEmail = map[emailLower];
      if (byEmail) return byEmail;

      // Then try domain match (handles business domains)
      const byDomain = map[domain];
      if (byDomain) return byDomain;
    }

    return null;
  }

  /**
   * Get client ID from a single email address
   */
  getClientIdFromSingleEmail(email, userEmail) {
    if (!email || !this.clientsStore) return null;

    const emailLower = email.toLowerCase();
    const domain = emailLower.split("@")[1];
    const userDomain = userEmail?.split("@")[1]?.toLowerCase();

    if (!domain || domain === userDomain) return null;

    const map = this.clientsStore.domainToClientMap;

    // Try exact email match first (handles generic providers like gmail)
    return map[emailLower] || map[domain] || null;
  }

  /**
   * Get client ID from board ID using cached mapping
   */
  getClientIdFromBoard(boardId) {
    if (!boardId) return null;

    const key = String(boardId);
    const mapping = this.boardMapping[key];

    isDebugEnabled() && console.log("[TimeTracker] getClientIdFromBoard:", {
      boardId,
      key,
      mapping,
      allMappings: this.boardMapping,
    });

    if (mapping) {
      return mapping.client_id;
    }

    // Fallback to checking clientsStore if available
    if (this.clientsStore) {
      const client = this.clientsStore.getClientByBoardId(boardId);
      isDebugEnabled() && console.log("[TimeTracker] Fallback to clientsStore:", {
        boardId,
        client,
      });
      return client?.id || null;
    }

    return null;
  }

  /**
   * Get client ID from drive folder ID using cached mapping
   */
  getClientIdFromFolderId(folderId) {
    if (!folderId) return null;

    const key = String(folderId);
    const mapping = this.folderMapping[key];

    if (mapping) {
      isDebugEnabled() && console.log("[TimeTracker] Found folder mapping:", {
        folderId,
        clientId: mapping.client_id,
      });
      return mapping.client_id;
    }

    // Fallback to checking clientsStore
    if (this.clientsStore) {
      const clients = this.clientsStore.clients;
      const folderIdNum = parseInt(folderId);
      const client = clients.find(
        (c) => parseInt(c.drive_folder_id) === folderIdNum
      );

      if (client) {
        isDebugEnabled() && console.log("[TimeTracker] Found client from store:", {
          folderId,
          clientId: client.id,
        });
        return client.id;
      }
    }

    isDebugEnabled() && console.log("[TimeTracker] No client found for folder:", folderId);
    return null;
  }

  /**
   * Track email reading activity
   */
  trackEmailRead(email, userEmail) {
    if (!email) return;

    const clientId = this.getClientIdFromSingleEmail(
      email.from?.address || email.from_email,
      userEmail
    );

    if (clientId) {
      this.startTracking(
        clientId,
        "email_read",
        email.uid || email.message_id,
        email.subject
      );
    }
  }

  /**
   * Track email composing activity
   */
  trackEmailCompose(recipients, userEmail, subject) {
    if (!recipients || recipients.length === 0) return;

    const clientId = this.getClientIdFromEmail(recipients, userEmail);

    if (clientId) {
      this.startTracking(
        clientId,
        "email_compose",
        null,
        subject || "New email"
      );
    }
  }

  /**
   * Track board/card viewing activity
   */
  async trackBoardActivity(boardId, cardId, cardName, boardName) {
    isDebugEnabled() && console.log("[TimeTracker] trackBoardActivity called:", {
      boardId,
      cardId,
      cardName,
      boardName,
    });
    isDebugEnabled() && console.log(
      "[TimeTracker] Board mapping keys:",
      Object.keys(this.boardMapping)
    );
    isDebugEnabled() && console.log("[TimeTracker] Mappings loaded:", this.mappingsLoaded);

    // Ensure mappings are loaded before tracking
    if (!this.mappingsLoaded) {
      isDebugEnabled() && console.log("[TimeTracker] Mappings not loaded, loading now...");
      await this.loadMappings();
    }

    const clientId = this.getClientIdFromBoard(boardId);
    isDebugEnabled() && console.log("[TimeTracker] Found clientId for board:", clientId);

    if (clientId) {
      const activityType = cardId ? "board_task" : "board_view";
      const entityName = cardId ? cardName : boardName;

      isDebugEnabled() && console.log(
        `[TimeTracker] Starting board tracking: type=${activityType}, client=${clientId}, entity=${entityName}`
      );
      this.startTracking(
        clientId,
        activityType,
        cardId?.toString() || boardId.toString(),
        entityName || "Board"
      );
    } else {
      isDebugEnabled() && console.warn(
        "[TimeTracker] No client found for board:",
        boardId,
        "- available mappings:",
        this.boardMapping
      );
    }
  }

  /**
   * Track calendar event activity
   */
  trackCalendarEvent(event) {
    isDebugEnabled() && console.log("[TimeTracker] trackCalendarEvent called:", {
      id: event?.id,
      title: event?.title,
      client_id: event?.client_id,
    });

    if (!event?.client_id) {
      isDebugEnabled() && console.warn(
        "[TimeTracker] Calendar event has no client_id, skipping tracking"
      );
      return;
    }

    isDebugEnabled() && console.log(
      `[TimeTracker] Starting calendar tracking: client=${event.client_id}, event=${event.title}`
    );
    this.startTracking(
      event.client_id,
      "calendar_event",
      event.id?.toString(),
      event.title
    );
  }

  /**
   * Track drive folder browsing activity
   */
  trackDriveBrowse(folderId, folderName) {
    if (!folderId) return;

    const clientId = this.getClientIdFromFolderId(folderId);

    if (clientId) {
      this.startTracking(
        clientId,
        "drive_browse",
        folderId.toString(),
        folderName || "Folder"
      );
    }
  }

  /**
   * Track document editing activity (from desktop app)
   */
  trackDocumentEdit(clientId, fileName, durationSeconds, driveFileId = null) {
    if (!clientId || !fileName) return;

    this.accumulateTime(
      clientId,
      "document_edit",
      durationSeconds,
      driveFileId ? String(driveFileId) : fileName,
      fileName
    );
  }

  /**
   * Track drive browsing by client ID directly (when we already know the client)
   */
  trackDriveBrowseForClient(clientId, folderId, folderName) {
    if (!clientId) return;

    this.startTracking(
      clientId,
      "drive_browse",
      folderId?.toString() || null,
      folderName || "Client Files"
    );
  }

  /**
   * Track website work activity (from desktop app)
   */
  trackWebsiteWork(clientId, boardId, domain, displayName, durationSeconds, cardId = null) {
    if (!clientId || !domain) return;

    isDebugEnabled() && console.log(
      `[TimeTracker] trackWebsiteWork: client=${clientId}, domain=${domain}, duration=${durationSeconds}s, cardId=${cardId}`
    );

    this.accumulateTime(
      clientId,
      "website_work",
      durationSeconds,
      domain,
      displayName || domain,
      cardId ? { cardId } : null
    );
  }

  /**
   * Get client ID from mood board ID using cached mapping
   */
  getClientIdFromMoodBoard(moodBoardId) {
    if (!moodBoardId) return null;

    const key = String(moodBoardId);
    const mapping = this.moodBoardMapping[key];

    if (mapping) {
      isDebugEnabled() && console.log("[TimeTracker] Found mood board mapping:", {
        moodBoardId,
        clientId: mapping.client_id,
      });
      return mapping.client_id;
    }

    return null;
  }

  /**
   * Track mood board activity (viewing or editing)
   * @param {number} moodBoardId - The mood board ID
   * @param {string} boardName - The board name for display
   * @param {boolean} isEditing - Whether the user is actively editing (adding/modifying items)
   * @param {number|null} clientId - Optional client ID if already known from the board data
   */
  async trackMoodBoardActivity(moodBoardId, boardName, isEditing = false, clientId = null) {
    if (!moodBoardId) return;

    // Ensure mappings are loaded
    if (!this.mappingsLoaded) {
      await this.loadMappings();
    }

    // Try to find client ID from mapping if not provided
    const resolvedClientId = clientId || this.getClientIdFromMoodBoard(moodBoardId);

    isDebugEnabled() && console.log("[TimeTracker] trackMoodBoardActivity:", {
      moodBoardId,
      boardName,
      isEditing,
      resolvedClientId,
    });

    if (resolvedClientId) {
      const activityType = isEditing ? "mood_board_edit" : "mood_board_view";
      this.startTracking(
        resolvedClientId,
        activityType,
        moodBoardId.toString(),
        boardName || "Mood Board"
      );
    } else {
      isDebugEnabled() && console.log(
        "[TimeTracker] No client found for mood board:",
        moodBoardId
      );
    }
  }
}

// Export singleton instance
const clientTimeTracker = new ClientTimeTracker();
export default clientTimeTracker;

// Also export class for testing
export { ClientTimeTracker };
