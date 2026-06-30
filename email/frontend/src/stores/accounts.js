import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/services/api";
import { getToken, setToken } from "@/services/tokenStorage";
import { isDebugEnabled } from "@/utils/debug";
import { pollerShouldRun, pollerRecordResult } from "@/services/pollerBreaker";

const POLLER_ID_UNREAD = 'accounts.unread-counts';
const POLLER_ID_LINKED_SYNC = 'accounts.linked-sync';

export const useAccountsStore = defineStore("accounts", () => {
  const accounts = ref([]);
  const presets = ref({});
  const loading = ref(false);
  const loaded = ref(false);
  const switching = ref(false);
  const syncing = ref(false);
  const sendAddresses = ref([]);
  const activeAccountId = ref(
    getToken("webmail_active_account") || "primary"
  );
  const googleOAuthEnabled = ref(false);
  const microsoftOAuthEnabled = ref(false);
  const pendingAddAccount = ref(false); // Flag to open add account modal from settings

  // Unread email counts per account (key: account id or 'primary')
  const unreadCounts = ref({});
  let unreadCountsInterval = null;
  let linkedSyncInterval = null;

  // Get theme store lazily at runtime to avoid circular dependency
  // The theme store is loaded via the passed parameter in switchAccount
  // or fetched dynamically when needed

  // The active account object
  const activeAccount = computed(() => {
    if (!activeAccountId.value || activeAccountId.value === "primary")
      return null;
    return (
      accounts.value.find((a) => a.id === parseInt(activeAccountId.value)) ||
      null
    );
  });

  // Get default account
  const defaultAccount = computed(() => {
    return (
      accounts.value.find((a) => a.is_default) || accounts.value[0] || null
    );
  });

  // Separate accounts (for switching)
  const separateAccounts = computed(() => {
    return accounts.value.filter((a) => a.account_type === "separate");
  });

  // Linked accounts (for sync)
  const linkedAccounts = computed(() => {
    return accounts.value.filter((a) => a.account_type === "linked");
  });

  async function fetchAccounts(forceReload = false) {
    if (loaded.value && !forceReload) return;
    if (loading.value) return;

    loading.value = true;
    try {
      const response = await api.get("/accounts");
      if (response.data.success) {
        accounts.value = response.data.data.accounts;
        presets.value = response.data.data.presets;
        googleOAuthEnabled.value =
          response.data.data.google_oauth_enabled || false;
        microsoftOAuthEnabled.value =
          response.data.data.microsoft_oauth_enabled || false;
        loaded.value = true;

        // Validate active account - if invalid secondary account, fall back to primary
        if (activeAccountId.value && activeAccountId.value !== "primary") {
          const accountExists = accounts.value.find(
            (a) => a.id === parseInt(activeAccountId.value)
          );
          if (!accountExists) {
            setActiveAccount("primary");
          }
        }
      }
    } catch (e) {
      console.error("Failed to fetch accounts:", e);
    } finally {
      loading.value = false;
    }
  }

  function hydrateFromBootstrap(data) {
    accounts.value = data.accounts || [];
    presets.value = data.presets || {};
    googleOAuthEnabled.value = data.google_oauth_enabled || false;
    microsoftOAuthEnabled.value = data.microsoft_oauth_enabled || false;
    loaded.value = true;

    if (activeAccountId.value && activeAccountId.value !== "primary") {
      const accountExists = accounts.value.find(
        (a) => a.id === parseInt(activeAccountId.value)
      );
      if (!accountExists) {
        setActiveAccount("primary");
      }
    }
  }

  function setActiveAccount(id, themeStoreParam = null) {
    activeAccountId.value = id?.toString() || "primary";
    setToken("webmail_active_account", activeAccountId.value);

    // Update theme store's current account (for per-account localStorage keys)
    // Theme store is passed from switchAccount() to avoid circular dependencies
    if (themeStoreParam?.switchToAccount) {
      themeStoreParam.switchToAccount(activeAccountId.value);
    }
  }

  // Switch account and refresh all account-specific data
  async function switchAccount(id, stores = {}) {
    if (activeAccountId.value === id?.toString()) return; // Already on this account

    const {
      mailbox,
      labels,
      drive,
      todos,
      calendar,
      filters,
      notifications,
      settings,
      theme,
    } = stores;

    switching.value = true;
    setActiveAccount(id, theme);

    // Clear existing data first to prevent showing old account data
    if (mailbox) {
      mailbox.messages = [];
      mailbox.folders = [];
    }
    if (drive) {
      drive.files = [];
      drive.folders = [];
    }
    if (calendar) {
      calendar.resetCache?.();
    }
    if (notifications) {
      notifications.notifications = [];
    }
    if (todos) {
      todos.todos = [];
    }

    // Reset settings loaded flag to force reload for new account
    if (settings) {
      settings.resetLoaded?.();
    }

    // Refresh all account-specific stores SEQUENTIALLY with a small gap.
    //
    // Phase 1 of the OAuth rewrite. The old code fired 9 parallel HTTP
    // requests on every account switch. Combined with the post-connect
    // loadData() burst (5 more requests), that was ~14 requests in <1s
    // from a single IP — exactly the pattern CPGuard's brute-force layer
    // flags as an attack.
    //
    // Sequencing them spreads the load to ~2 reqs/s and lets each one's
    // result (especially `settings`, which gates theme apply below) be
    // observable as it arrives. The whole switch still completes in
    // well under 2 seconds for a normal account.
    const refreshSteps = [];
    if (settings)      refreshSteps.push(() => settings.fetchSettings?.(true));
    if (mailbox)       refreshSteps.push(() => mailbox.fetchFolders());
    if (labels)        refreshSteps.push(() => labels.fetchLabels());
    if (calendar)      refreshSteps.push(() => calendar.fetchEvents?.());
    if (filters)       refreshSteps.push(() => filters.fetchFilters?.());
    if (todos)         refreshSteps.push(() => todos.fetchTodos?.());
    if (drive)         refreshSteps.push(() => drive.fetchContents?.() || drive.fetchFiles?.());
    if (notifications) refreshSteps.push(() => notifications.fetchNotifications?.());

    for (const step of refreshSteps) {
      try {
        await step();
      } catch (e) {
        console.warn('[switchAccount] step failed (continuing):', e);
      }
      // Small gap between requests so a burst-detecting WAF sees a
      // sustained rate rather than a spike.
      await new Promise((r) => setTimeout(r, 80));
    }

    // Apply theme settings from the newly loaded settings
    if (theme?.applySettings && settings?.settings) {
      const currentSettings = settings.settings;
      isDebugEnabled() && console.log(
        "Applying theme settings after account switch:",
        currentSettings.theme,
        currentSettings.accent_color
      );
      theme.applySettings({
        theme: currentSettings.theme || "system",
        accent_color: currentSettings.accent_color || "green",
      });
    }

    // Fetch messages after folders are loaded
    if (mailbox) {
      await mailbox.fetchMessages("INBOX");
    }

    switching.value = false;
  }

  // Remove all secondary accounts (sign out others)
  async function removeOtherAccounts(currentAccountId) {
    const accountsToRemove = accounts.value.filter(
      (a) => a.id !== parseInt(currentAccountId)
    );

    for (const account of accountsToRemove) {
      await deleteAccount(account.id);
    }

    return accountsToRemove.length;
  }

  async function addAccount(data) {
    try {
      const response = await api.post("/accounts", data);
      if (response.data.success) {
        accounts.value.push(response.data.data.account);
        return { success: true, account: response.data.data.account };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      console.error("Failed to add account:", e);
      return {
        success: false,
        error: e.response?.data?.message || "Failed to add account",
      };
    }
  }

  async function updateAccount(id, data) {
    try {
      const response = await api.put(`/accounts/${id}`, data);
      if (response.data.success) {
        const index = accounts.value.findIndex((a) => a.id === id);
        if (index !== -1) {
          accounts.value[index] = response.data.data.account;
        }
        return { success: true, account: response.data.data.account };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      console.error("Failed to update account:", e);
      return {
        success: false,
        error: e.response?.data?.message || "Failed to update account",
      };
    }
  }

  async function deleteAccount(id) {
    try {
      const response = await api.delete(`/accounts/${id}`);
      if (response.data.success) {
        accounts.value = accounts.value.filter((a) => a.id !== id);
        // If deleted the active account, switch to default
        if (activeAccountId.value === id && defaultAccount.value) {
          setActiveAccount(defaultAccount.value.id);
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to delete account:", e);
    }
    return false;
  }

  async function testConnection(data) {
    try {
      const response = await api.post("/accounts/test", data);
      if (response.data.success) {
        return {
          success: true,
          message: response.data.message,
          imap: response.data.data?.imap || { success: true },
          smtp: response.data.data?.smtp || { tested: false },
        };
      } else {
        return {
          success: false,
          error: response.data.message,
          imap: response.data.data?.imap || { success: false },
          smtp: response.data.data?.smtp || { tested: false },
        };
      }
    } catch (e) {
      return {
        success: false,
        error: e.response?.data?.message || "Connection test failed",
        imap: { success: false, error: e.response?.data?.message },
        smtp: { tested: false },
      };
    }
  }

  async function detectSettings(email) {
    try {
      const response = await api.post("/accounts/detect", { email });
      if (response.data.success) {
        return response.data.data;
      }
    } catch (e) {
      console.error("Failed to detect settings:", e);
    }
    return null;
  }

  async function setDefault(id) {
    try {
      const response = await api.post(`/accounts/${id}/default`);
      if (response.data.success) {
        // Update local state
        accounts.value.forEach((a) => {
          a.is_default = a.id === id;
        });
        return true;
      }
    } catch (e) {
      console.error("Failed to set default account:", e);
    }
    return false;
  }

  // Linked account sync functions
  async function triggerSync(accountId) {
    syncing.value = true;
    try {
      const response = await api.post(`/accounts/${accountId}/sync`);
      if (response.data.success) {
        return {
          success: true,
          fetched: response.data.data.fetched,
          deleted: response.data.data.deleted,
        };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      return {
        success: false,
        error: e.response?.data?.message || "Sync failed",
      };
    } finally {
      syncing.value = false;
    }
  }

  async function processQueue() {
    try {
      const response = await api.post("/accounts/sync/process-queue");
      if (response.data.success) {
        return {
          success: true,
          processed: response.data.data.processed,
        };
      }
      return { success: false };
    } catch (e) {
      return { success: false, error: e.response?.data?.message };
    }
  }

  async function getSyncStatus() {
    try {
      const response = await api.get("/accounts/sync/status");
      if (response.data.success) {
        return response.data.data.accounts;
      }
    } catch (e) {
      console.error("Failed to get sync status:", e);
    }
    return [];
  }

  // Send addresses for compose "From" selector
  async function fetchSendAddresses() {
    try {
      const response = await api.get("/accounts/send-addresses");
      if (response.data.success) {
        sendAddresses.value = response.data.data.addresses;
        return sendAddresses.value;
      }
    } catch (e) {
      console.error("Failed to fetch send addresses:", e);
    }
    return [];
  }

  // Google OAuth methods
  async function getGoogleAuthUrl(options = {}) {
    try {
      const params = new URLSearchParams({
        account_type: options.account_type || "separate",
        sync_frequency: options.sync_frequency || 15,
        leave_on_server: options.leave_on_server ? 1 : 0,
        auto_label: options.auto_label || "",
      });
      const response = await api.get(`/auth/google?${params}`);
      if (response.data.success) {
        return response.data.data.auth_url;
      }
    } catch (e) {
      console.error("Failed to get Google auth URL:", e);
    }
    return null;
  }

  // Phase 3 (orphan cleanup): connectGoogleAccount() removed. The real
  // add-account flow opens the OAuth popup, lets it redirect through
  // /api/auth/google/callback (GET, handled by AccountController::googleCallback),
  // and then closes the popup with window.opener.postMessage(). This
  // POST helper never had a caller in the Vue tree.

  async function deleteOAuthAccount(id) {
    try {
      const response = await api.delete(`/accounts/oauth/${id}`);
      if (response.data.success) {
        accounts.value = accounts.value.filter((a) => a.id !== id);
        return true;
      }
    } catch (e) {
      console.error("Failed to delete OAuth account:", e);
    }
    return false;
  }

  // Remove an account regardless of its type.
  //
  // OAuth and IMAP accounts live in SEPARATE backend tables and can even share
  // the same numeric id, so the delete endpoint MUST be chosen from the account
  // OBJECT the user acted on - never inferred from the id alone. Routing an
  // OAuth (Gmail/Microsoft) account through the IMAP `DELETE /accounts/{id}` is
  // exactly what produced the "404 Not Found / Failed to delete account" error
  // when logging out a Gmail account from the header or switcher.
  async function removeAccountByType(account) {
    if (!account || account.id == null) return false;
    const isOAuth = account.is_oauth === true || account.auth_type === "oauth";
    return isOAuth
      ? deleteOAuthAccount(account.id)
      : deleteAccount(account.id);
  }

  // Microsoft OAuth methods
  async function getMicrosoftAuthUrl(options = {}) {
    try {
      const params = new URLSearchParams({
        account_type: options.account_type || "separate",
        sync_frequency: options.sync_frequency || 15,
        leave_on_server: options.leave_on_server ? 1 : 0,
        auto_label: options.auto_label || "",
      });
      const response = await api.get(`/auth/microsoft?${params}`);
      if (response.data.success) {
        return response.data.data.auth_url;
      }
    } catch (e) {
      console.error("Failed to get Microsoft auth URL:", e);
    }
    return null;
  }

  // Phase 3 (orphan cleanup): connectMicrosoftAccount() removed. Same
  // rationale as connectGoogleAccount above — the real flow is popup +
  // GET /api/auth/microsoft/callback, not a POST exchange from JS.

  // Check for OAuth accounts
  const oauthAccounts = computed(() => {
    return accounts.value.filter((a) => a.auth_type === "oauth");
  });

  // Fetch unread counts for all accounts.
  //
  // The server now serves this entirely from a Redis cache (populated by
  // cron/refresh-unread-counts.php) — see Phase 1 of the OAuth rewrite.
  // The endpoint never opens an IMAP / XOAUTH2 connection, so this poll
  // is now cheap on the server side regardless of how many OAuth Gmail
  // accounts the user has.
  async function fetchUnreadCounts() {
    if (!pollerShouldRun(POLLER_ID_UNREAD)) return;
    try {
      const response = await api.get("/accounts/unread-counts");
      if (response.data.success) {
        unreadCounts.value = response.data.data.counts;
        pollerRecordResult(POLLER_ID_UNREAD, null);
      }
    } catch (e) {
      pollerRecordResult(POLLER_ID_UNREAD, e);
      console.error("Failed to fetch unread counts:", e);
    }
  }

  // Start background polling for unread counts. Two changes from the
  // pre-rewrite behaviour:
  //   * The minimum interval is now 60 seconds — there is no real-time
  //     unread arrival use case that needs faster polling, and the
  //     daemon will push deltas via WS in Phase 2.
  //   * Every tick consults pollerBreaker so sustained 403/5xx errors
  //     (CPGuard ban, server outage) suspend the poller automatically
  //     instead of grinding away forever.
  // Phase 4: pollers replaced by WS push (folder.unread event published by
  // the sync daemon). We keep the function as a thin compatibility shim
  // so existing call sites (MailboxView.vue) continue to work without a
  // sweeping refactor, but the long-interval safety net runs every 30
  // minutes only — enough to recover counts if the WebSocket has been
  // down for an extended period, but no longer the primary delivery path.
  // The Redis-backed /accounts/unread-counts endpoint makes this cheap
  // even if we kept it; we just no longer need the 60-second cadence.
  function startUnreadCountsPolling(intervalMs = 60000) {
    fetchUnreadCounts();

    if (unreadCountsInterval) {
      clearInterval(unreadCountsInterval);
    }

    const SAFETY_NET_MS = 30 * 60 * 1000;
    const safeInterval = Math.max(SAFETY_NET_MS, Number(intervalMs) || SAFETY_NET_MS);
    unreadCountsInterval = setInterval(() => {
      fetchUnreadCounts();
    }, safeInterval);
  }

  function stopUnreadCountsPolling() {
    if (unreadCountsInterval) {
      clearInterval(unreadCountsInterval);
      unreadCountsInterval = null;
    }
  }

  // Get unread count for a specific account
  function getUnreadCount(accountId) {
    const key = accountId === "primary" ? "primary" : accountId?.toString();
    return unreadCounts.value[key] || 0;
  }

  // Linked account auto-sync: periodically sync all linked accounts.
  //
  // Previously this ran one HTTP request per linked account AND
  // drained the queue once per account. The new /accounts/sync/trigger-all
  // endpoint does both in a single request and drains the queue once
  // at the end -- so this is now O(1) network round-trips regardless
  // of how many linked accounts the user has.
  async function syncAllLinkedAccounts() {
    const linked = linkedAccounts.value;
    if (linked.length === 0) return 0;
    if (!linked.some((a) => a.sync_enabled)) return 0;
    if (!pollerShouldRun(POLLER_ID_LINKED_SYNC)) return 0;

    syncing.value = true;
    try {
      const response = await api.post('/accounts/sync/trigger-all');
      if (response.data?.success) {
        pollerRecordResult(POLLER_ID_LINKED_SYNC, null);
        return response.data.data?.imported || 0;
      }
      return 0;
    } catch (e) {
      pollerRecordResult(POLLER_ID_LINKED_SYNC, e);
      console.error('Auto-sync-all failed:', e);
      return 0;
    } finally {
      syncing.value = false;
    }
  }

  // Phase 4: linked-account auto-sync is now driven by the sync daemon
  // (IDLE on each linked IMAP account, push via WS). The browser timer
  // here is a 60-minute safety net only — if the daemon is down or the
  // WebSocket reconnects after a long absence, we trigger one sweep so
  // the user does not stare at stale inboxes indefinitely. Previously
  // this was a 15-minute floor.
  function startLinkedSyncPolling(intervalMs = null) {
    stopLinkedSyncPolling();

    const linked = linkedAccounts.value;
    if (linked.length === 0) return;
    if (!linked.some((a) => a.sync_enabled)) return;

    const SAFETY_NET_MS = 60 * 60 * 1000;
    const pollMs = Math.max(SAFETY_NET_MS, Number(intervalMs) || SAFETY_NET_MS);

    linkedSyncInterval = setInterval(() => {
      syncAllLinkedAccounts();
    }, pollMs);
  }

  function stopLinkedSyncPolling() {
    if (linkedSyncInterval) {
      clearInterval(linkedSyncInterval);
      linkedSyncInterval = null;
    }
  }

  return {
    accounts,
    presets,
    loading,
    loaded,
    switching,
    syncing,
    sendAddresses,
    activeAccountId,
    activeAccount,
    defaultAccount,
    separateAccounts,
    linkedAccounts,
    oauthAccounts,
    googleOAuthEnabled,
    microsoftOAuthEnabled,
    unreadCounts,
    pendingAddAccount,
    fetchAccounts,
    hydrateFromBootstrap,
    setActiveAccount,
    switchAccount,
    removeOtherAccounts,
    addAccount,
    updateAccount,
    deleteAccount,
    deleteOAuthAccount,
    removeAccountByType,
    testConnection,
    detectSettings,
    setDefault,
    triggerSync,
    processQueue,
    getSyncStatus,
    fetchSendAddresses,
    getGoogleAuthUrl,
    // connectGoogleAccount removed in Phase 3 orphan cleanup.
    getMicrosoftAuthUrl,
    // connectMicrosoftAccount removed in Phase 3 orphan cleanup.
    fetchUnreadCounts,
    startUnreadCountsPolling,
    stopUnreadCountsPolling,
    getUnreadCount,
    syncAllLinkedAccounts,
    startLinkedSyncPolling,
    stopLinkedSyncPolling,
  };
});
