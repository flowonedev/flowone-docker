import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api, { uploadFormData } from "@/services/api";
import { getToken } from "@/services/tokenStorage";
import { isDebugEnabled } from "@/utils/debug";
import { describeUploadError } from "@/utils/uploadErrors";
import { useSearchStore } from "@/addons/universal-search/stores/search";
import { useAddons } from "@/composables/useAddons";

// Track events silently (non-blocking)
async function trackEvent(eventType, eventData = {}) {
  try {
    await api.post("/statistics/log-event", {
      event_type: eventType,
      event_data: eventData,
    });
  } catch (e) {
    // Silent fail
  }
}

export const useDriveStore = defineStore("drive", () => {
  const folders = ref([]);
  const files = ref([]);
  const allFolders = ref([]); // All folders for filtering

  // Drive-wide search (server-side, partial name match across all folders).
  // When searchActive is true, the view renders these instead of the
  // current-folder folders/files.
  const searchActive = ref(false);
  const searchLoading = ref(false);
  const searchFolders = ref([]);
  const searchFiles = ref([]);
  const currentFolder = ref(null);
  const currentFolderId = ref(null); // Track current folder ID explicitly
  const path = ref([]);
  const quota = ref({ quota: -1, used: 0, available: -1, unlimited: true });
  const loading = ref(false);
  const uploading = ref(false);
  const uploadProgress = ref(0);
  
  // Bulk upload progress tracking
  const bulkUpload = ref({
    active: false,
    total: 0,
    current: 0,
    currentFileName: '',
    currentProgress: 0,
    completed: 0,
    failed: 0,
    failedFiles: []
  });

  // Cache tracking - prevent unnecessary reloads
  const lastFetchedFolderId = ref(null); // Which folder was last fetched
  const lastFetchedTime = ref(null); // When was data last fetched
  const hasFetchedOnce = ref(false); // Track if we've ever fetched
  const CACHE_DURATION = 300000; // 5 minutes cache validity

  // View mode: 'grid' | 'list' | 'compact'.
  // 'compact' is a Windows/macOS file-manager style dense list (single
  // line per item, tiny icons, minimal columns).
  const viewMode = ref((() => {
    const stored = localStorage.getItem("drive_view_mode");
    return ["grid", "list", "compact"].includes(stored) ? stored : "grid";
  })());

  // Trash state
  const trashedItems = ref({ files: [], folders: [] });
  const loadingTrash = ref(false);
  const isTrashView = ref(false);

  // File versions state
  const fileVersions = ref({}); // fileId -> versions array
  const loadingVersions = ref(false);

  // Selection state for multi-select
  const selectedFiles = ref(new Set());
  const selectedFolders = ref(new Set());
  const isSelecting = ref(false);

  // Clipboard for cut/copy/paste
  const clipboard = ref({ mode: null, fileIds: [], folderIds: [], sourceFolderId: null });

  // Chat shared IDs - files/folders that have been shared in chat
  const chatSharedFileIds = ref(new Set());
  const chatSharedFolderIds = ref(new Set());
  const chatSharedLoaded = ref(false);

  // Sharing & Access dashboard state
  const isSharingAccessView = ref(false);
  const sharingOverview = ref(null);
  const loadingSharingOverview = ref(false);

  // Starred + Recent state
  const starredItems = ref({ files: [], folders: [] });
  const recentItems = ref({ files: [], folders: [] });
  const loadingStarred = ref(false);
  const loadingRecent = ref(false);
  const isStarredView = ref(false);
  const isRecentView = ref(false);

  // Sort state -- shared across list/grid components (was local to DriveView)
  const sortField = ref(localStorage.getItem("drive_sort_field") || "name");
  const sortDirection = ref(localStorage.getItem("drive_sort_direction") || "asc");

  // High-level section the Drive UI is showing.
  // 'my-drive' | 'shared' | 'recent' | 'starred' | 'trash' | 'sharing-access'
  const currentSection = computed(() => {
    if (isTrashView.value) return "trash";
    if (isSharingAccessView.value) return "sharing-access";
    if (isSharedView.value) return "shared";
    if (isRecentView.value) return "recent";
    if (isStarredView.value) return "starred";
    return "my-drive";
  });

  // Thumbnail cache - persists across component mounts
  // Keys are file IDs, values are blob URLs or 'loading' state
  const thumbnailCache = ref({});

  // Formatted quota
  const formattedQuota = computed(() => {
    const formatSize = (bytes) => {
      if (bytes >= 1099511627776) return (bytes / 1099511627776).toFixed(2) + " TB";
      if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + " GB";
      if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + " MB";
      if (bytes >= 1024) return (bytes / 1024).toFixed(2) + " KB";
      return bytes + " bytes";
    };

    // Keep tiny-but-nonzero usage visible (e.g. 0.08% would otherwise round to
    // a misleading 0%): show one decimal under 1%, whole numbers above.
    let percentUsed = 0;
    if (!quota.value.unlimited) {
      const q = Number(quota.value.quota) || 0;
      const raw = q > 0 ? (Number(quota.value.used) / q) * 100 : 0;
      percentUsed = raw > 0 && raw < 1 ? Math.round(raw * 10) / 10 : Math.round(raw);
    }

    return {
      quota: quota.value.unlimited
        ? "Unlimited"
        : formatSize(quota.value.quota),
      used: formatSize(quota.value.used),
      available: quota.value.unlimited
        ? "Unlimited"
        : formatSize(quota.value.available),
      percentUsed,
    };
  });

  // Check if cache is valid for a given folder
  function isCacheValid(folderId = null) {
    // Must have fetched at least once
    if (!hasFetchedOnce.value) return false;
    // Must have fetched the same folder
    if (lastFetchedFolderId.value !== folderId) return false;
    // Must have a fetch time
    if (!lastFetchedTime.value) return false;
    // Must be within cache duration
    return Date.now() - lastFetchedTime.value < CACHE_DURATION;
  }

  // Check if we have data for current view (even if stale)
  function hasDataForFolder(folderId = null) {
    return hasFetchedOnce.value && lastFetchedFolderId.value === folderId;
  }

  // Fetch contents - with smart caching
  // Options: { force: boolean, quiet: boolean }
  async function fetchContents(folderId = null, options = {}) {
    const { force = false, quiet = false } = options;

    // Skip fetch if cache is valid and not forcing refresh
    if (!force && isCacheValid(folderId)) {
      isDebugEnabled() && console.log("[Drive] Cache hit - using cached data for folder:", folderId);
      return;
    }

    // If quiet mode and we have data for this folder, skip showing loading
    // but still allow background refresh if cache expired
    const hasData = hasDataForFolder(folderId);
    
    // Prevent duplicate fetches
    if (loading.value) {
      isDebugEnabled() && console.log("[Drive] Already loading, skipping fetch");
      return;
    }

    // Only show loading indicator on first load (no data at all)
    // Never show loading if we already have data (prevents flash)
    if (!hasData && !quiet) {
      loading.value = true;
    }

    currentFolderId.value = folderId;
    // Clear selection when navigating to a different folder
    if (lastFetchedFolderId.value !== folderId) {
      clearSelection();
    }

    isDebugEnabled() && console.log("[Drive] fetchContents - fetching folder:", folderId, "hasData:", hasData);
    try {
      const params = folderId !== null ? { folder_id: folderId } : {};
      const response = await api.get("/drive", { params });

      if (response.data.success) {
        folders.value = response.data.data.folders || [];
        files.value = response.data.data.files || [];
        currentFolder.value = response.data.data.current_folder;
        path.value = response.data.data.path || [];
        quota.value = response.data.data.quota;
        
        // Update cache tracking
        lastFetchedFolderId.value = folderId;
        lastFetchedTime.value = Date.now();
        hasFetchedOnce.value = true;
        
        isDebugEnabled() && console.log("[Drive] Data loaded - folders:", folders.value.length, "files:", files.value.length);
      } else {
        console.error("[Drive] API returned success: false", response.data.message);
      }
    } catch (e) {
      console.error("[Drive] Failed to fetch drive contents:", e);
    } finally {
      loading.value = false;
    }
  }

  // Drive-wide search: queries the server for files/folders matching `query`
  // by name across all folders. Results mirror the folder-listing shape so the
  // grid renders them with no special handling.
  let searchRequestId = 0;
  async function searchDrive(query) {
    const q = (query || "").trim();
    if (q.length < 2) {
      clearDriveSearch();
      return;
    }

    const requestId = ++searchRequestId;
    searchActive.value = true;
    searchLoading.value = true;

    try {
      const response = await api.get("/drive/search", { params: { q } });
      // Ignore stale responses (user kept typing)
      if (requestId !== searchRequestId) return;
      if (response.data.success) {
        searchFolders.value = response.data.data.folders || [];
        searchFiles.value = response.data.data.files || [];
      }
    } catch (e) {
      if (requestId === searchRequestId) {
        console.error("[Drive] Search failed:", e);
        searchFolders.value = [];
        searchFiles.value = [];
      }
    } finally {
      if (requestId === searchRequestId) {
        searchLoading.value = false;
      }
    }
  }

  function clearDriveSearch() {
    searchRequestId++; // invalidate any in-flight request
    searchActive.value = false;
    searchLoading.value = false;
    searchFolders.value = [];
    searchFiles.value = [];
  }

  // Fetch which files/folders have been shared in chat (via embeds)
  async function fetchChatSharedIds(force = false) {
    const { chatEnabled } = useAddons();
    if (!chatEnabled.value) return;
    if (chatSharedLoaded.value && !force) return;
    try {
      const res = await api.get('/chat/shared-drive-ids');
      if (res.data.success) {
        chatSharedFileIds.value = new Set(res.data.data.file_ids || []);
        chatSharedFolderIds.value = new Set(res.data.data.folder_ids || []);
        chatSharedLoaded.value = true;
      }
    } catch (e) {
      // Silent - non-critical
      isDebugEnabled() && console.warn('[Drive] Failed to fetch chat shared IDs:', e);
    }
  }

  function isSharedInChat(itemType, id) {
    if (itemType === 'folder') return chatSharedFolderIds.value.has(id);
    return chatSharedFileIds.value.has(id);
  }

  // --- Sharing & Access Dashboard actions ---
  function enterSharingAccessView() {
    isSharingAccessView.value = true;
    isTrashView.value = false;
    isSharedView.value = false;
    isStarredView.value = false;
    isRecentView.value = false;
    fetchSharingOverview();
  }

  function exitSharingAccessView() {
    isSharingAccessView.value = false;
  }

  async function fetchSharingOverview() {
    loadingSharingOverview.value = true;
    try {
      const response = await api.get('/sharing/overview');
      if (response.data?.success) {
        sharingOverview.value = response.data.data;
      }
    } catch (e) {
      console.error('Failed to fetch sharing overview:', e);
    } finally {
      loadingSharingOverview.value = false;
    }
  }

  async function revokeAccess(type, id, targetEmail = null) {
    try {
      const payload = { type, id };
      if (targetEmail) payload.target_email = targetEmail;
      const response = await api.delete('/sharing/revoke', { data: payload });
      if (response.data?.success) {
        await fetchSharingOverview();
        return true;
      }
    } catch (e) {
      console.error('Failed to revoke access:', e);
    }
    return false;
  }

  async function updateAccessRole(type, id, targetEmail, newRole) {
    try {
      const response = await api.put('/sharing/update-role', {
        type, id, target_email: targetEmail, new_role: newRole
      });
      if (response.data?.success) {
        await fetchSharingOverview();
        return true;
      }
    } catch (e) {
      console.error('Failed to update access role:', e);
    }
    return false;
  }

  async function createFolder(name, parentId = null) {
    try {
      const response = await api.post("/drive/folders", {
        name,
        parent_id: parentId || currentFolder.value?.id,
      });
      if (response.data.success) {
        folders.value.push(response.data.data.folder);
        return { success: true, folder: response.data.data.folder };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      return {
        success: false,
        error: e.response?.data?.message || "Failed to create folder",
      };
    }
  }

  async function renameFolder(id, name) {
    try {
      const response = await api.put(`/drive/folders/${id}`, { name });
      if (response.data.success) {
        const folder = folders.value.find((f) => f.id === id);
        if (folder) folder.name = name;
        return true;
      }
    } catch (e) {
      console.error("Failed to rename folder:", e);
    }
    return false;
  }

  async function updateFolderColor(id, color) {
    try {
      const response = await api.put(`/drive/folders/${id}/color`, { color });
      if (response.data.success) {
        const folder = folders.value.find((f) => f.id === id);
        if (folder) folder.color = color;
        return { success: true, folder: response.data.data.folder };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      console.error("Failed to update folder color:", e);
      return { success: false, error: "Failed to update folder color" };
    }
  }

  async function deleteFolder(id) {
    try {
      const response = await api.delete(`/drive/folders/${id}`);
      if (response.data.success) {
        folders.value = folders.value.filter((f) => f.id !== id);
        return true;
      }
    } catch (e) {
      console.error("Failed to delete folder:", e);
    }
    return false;
  }

  async function uploadFile(file, folderId = null) {
    uploading.value = true;
    uploadProgress.value = 0;

    try {
      const formData = new FormData();
      formData.append("file", file);
      if (folderId || currentFolder.value?.id) {
        formData.append("folder_id", folderId || currentFolder.value.id);
      }

      // Bypasses CapacitorHttp on native (drops multipart file parts); axios on web.
      const body = await uploadFormData("/drive/upload", formData, (pct) => {
        uploadProgress.value = pct;
      });

      if (body.success) {
        const newFile = body.data.file;
        files.value.push(newFile);
        quota.value.used += newFile.size;
        // Track file upload
        trackEvent("drive_file_uploaded", {
          name: newFile.name,
          size: newFile.size,
        });
        if (!quota.value.unlimited) {
          quota.value.available -= newFile.size;
        }
        // Auto-index for search
        const searchStore = useSearchStore();
        searchStore.indexItem('drive_file', newFile.id, newFile);
        return { success: true, file: newFile };
      }
      return { success: false, error: body.message };
    } catch (e) {
      return {
        success: false,
        error: describeUploadError(e),
      };
    } finally {
      uploading.value = false;
      uploadProgress.value = 0;
    }
  }

  async function deleteFile(id) {
    try {
      const file = files.value.find((f) => f.id === id);
      const response = await api.delete(`/drive/files/${id}`);
      if (response.data.success) {
        files.value = files.value.filter((f) => f.id !== id);
        if (file) {
          quota.value.used -= file.size;
          if (!quota.value.unlimited) {
            quota.value.available += file.size;
          }
        }
        // Remove from search index
        const searchStore = useSearchStore();
        searchStore.removeFromIndex('drive_file', id);
        return true;
      }
    } catch (e) {
      console.error("Failed to delete file:", e);
    }
    return false;
  }

  async function renameFile(id, name) {
    try {
      const response = await api.put(`/drive/files/${id}`, { name });
      if (response.data.success) {
        const file = files.value.find((f) => f.id === id);
        if (file) {
          file.original_name = name;
          // Re-index with new name
          const searchStore = useSearchStore();
          searchStore.indexItem('drive_file', id, file);
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to rename file:", e);
    }
    return false;
  }

  async function moveFile(id, folderId) {
    try {
      const response = await api.post(`/drive/files/${id}/move`, {
        folder_id: folderId,
      });
      if (response.data.success) {
        files.value = files.value.filter((f) => f.id !== id);
        return true;
      }
    } catch (e) {
      console.error("Failed to move file:", e);
    }
    return false;
  }

  async function moveFolder(id, parentId) {
    try {
      const response = await api.post(`/drive/folders/${id}/move`, {
        parent_id: parentId,
      });
      if (response.data.success) {
        // Remove from current view if moving to different parent
        folders.value = folders.value.filter((f) => f.id !== id);
        return true;
      }
    } catch (e) {
      console.error("Failed to move folder:", e);
    }
    return false;
  }

  async function createShareLink(
    id,
    expiresHours = null,
    maxDownloads = null,
    password = null,
    isEmailAttachment = false
  ) {
    try {
      const response = await api.post(`/drive/files/${id}/share`, {
        expires_hours: expiresHours,
        max_downloads: maxDownloads,
        password: password,
        is_email_attachment: isEmailAttachment,
      });
      if (response.data.success) {
        const file = files.value.find((f) => f.id === id);
        if (file) file.share_token = response.data.data.token;
        return {
          success: true,
          url: response.data.data.url,
          token: response.data.data.token,
          max_downloads: response.data.data.max_downloads,
          has_password: response.data.data.has_password,
        };
      }
    } catch (e) {
      console.error("Failed to create share link:", e);
    }
    return { success: false };
  }

  // Share an existing Drive file for email (7 days, NOT auto-deleted)
  async function shareForEmail(id, expiresHours = 168) {
    return createShareLink(id, expiresHours, null, null, false);
  }

  async function removeShareLink(id) {
    try {
      const response = await api.delete(`/drive/files/${id}/share`);
      if (response.data.success) {
        const file = files.value.find((f) => f.id === id);
        if (file) file.share_token = null;
        return true;
      }
    } catch (e) {
      console.error("Failed to remove share link:", e);
    }
    return false;
  }

  // Folder sharing functions
  async function createFolderShareLink(
    id,
    expiresHours = null,
    maxDownloads = null,
    password = null
  ) {
    try {
      const response = await api.post(`/drive/folders/${id}/share`, {
        expires_hours: expiresHours,
        max_downloads: maxDownloads,
        password: password,
      });
      if (response.data.success) {
        const folder = folders.value.find((f) => f.id === id);
        if (folder) folder.share_token = response.data.data.token;
        return {
          success: true,
          url: response.data.data.url,
          token: response.data.data.token,
          max_downloads: response.data.data.max_downloads,
          has_password: response.data.data.has_password,
        };
      }
    } catch (e) {
      console.error("Failed to create folder share link:", e);
    }
    return { success: false };
  }

  async function removeFolderShareLink(id) {
    try {
      const response = await api.delete(`/drive/folders/${id}/share`);
      if (response.data.success) {
        const folder = folders.value.find((f) => f.id === id);
        if (folder) folder.share_token = null;
        return true;
      }
    } catch (e) {
      console.error("Failed to remove folder share link:", e);
    }
    return false;
  }

  function getDownloadUrl(id) {
    return `${api.defaults.baseURL}/drive/files/${id}/download`;
  }

  // Request a short-lived signed token so the browser can download a file
  // natively (streamed to disk with its own progress UI) instead of buffering
  // the whole file into memory via fetch()+blob(). Returns:
  //   { status: 'ready', token }                 - go ahead and download
  //   { status: 'restoring', retryAfter }         - cold file, poll again
  //   { status: 'error', message? }               - give up / show error
  async function requestDownloadToken(fileId) {
    try {
      const r = await api.get(`/drive/files/${fileId}/download-token`);
      // Cold-storage restore handshake comes back as a 202 (still 2xx, so it
      // resolves here rather than throwing).
      if (r.status === 202 || r.data?.status === "restoring") {
        return {
          status: "restoring",
          retryAfter: Math.max(2, Math.min(30, Number(r.data?.retry_after) || 5)),
        };
      }
      if (r.data?.success && r.data?.data?.token) {
        return { status: "ready", token: r.data.data.token };
      }
      return { status: "error", message: r.data?.message };
    } catch (e) {
      const status = e.response?.status;
      if (status === 202) {
        return {
          status: "restoring",
          retryAfter: Math.max(2, Math.min(30, Number(e.response?.data?.retry_after) || 5)),
        };
      }
      return { status: "error", message: e.response?.data?.message || e.message };
    }
  }

  // Fetch all folders for tree view and store in allFolders
  async function fetchAllFolders() {
    try {
      const response = await api.get("/drive/folders/all");
      if (response.data.success) {
        allFolders.value = response.data.data.folders || [];
        return response.data.data.folders;
      }
    } catch (e) {
      console.error("Failed to fetch all folders:", e);
    }
    return [];
  }

  // Save email attachment to Drive with auto-folder structure
  // Creates Attachments/YYYY-MM-DD - Subject/ folder
  // If senderEmail provided, checks if client exists and saves to client folder instead
  // sourceFolder/sourceUid/sourcePart let the server tag the saved file
  // back to the IMAP message it came from, so the email view can show a
  // persistent "Saved to Drive" indicator + Share action on this card.
  async function saveEmailAttachment(
    filename,
    content,
    mimeType,
    emailSubject,
    emailDate = null,
    senderEmail = null,
    sourceFolder = null,
    sourceUid = null,
    sourcePart = null
  ) {
    try {
      const response = await api.post("/drive/save-attachment", {
        filename,
        content, // Base64 encoded
        mime_type: mimeType,
        email_subject: emailSubject,
        email_date: emailDate,
        sender_email: senderEmail, // For client folder detection
        source_folder: sourceFolder,
        source_uid: sourceUid,
        source_part: sourcePart,
      });

      if (response.data.success) {
        // Track the file save
        trackEvent("drive_file_uploaded", {
          name: filename,
          size: atob(content).length,
          from_email: true,
        });
        return {
          success: true,
          file: response.data.data.file,
          folder: response.data.data.folder,
          clientFolder: response.data.data.client_folder || null,
        };
      }
      return {
        success: false,
        error: response.data.message || "Failed to save attachment",
      };
    } catch (e) {
      return {
        success: false,
        error: e.response?.data?.message || "Failed to save attachment",
      };
    }
  }

  // Fetch the list of Drive files saved from a specific IMAP message.
  // Returns [{ id, folder_id, filename, part, share_token, share_url, ... }]
  // keyed implicitly by `part` so the email view can match each saved
  // file back to the corresponding attachment card.
  //
  // `attachments` (optional) is the list of IMAP attachments on the
  // message — passing it lets the server fall back to filename+size
  // matching for legacy rows that pre-date source_email_* tracking, and
  // self-heal those rows by backfilling their source columns.
  async function fetchEmailAttachmentsStatus(folder, uid, attachments = null) {
    if (!folder || !uid) return [];
    try {
      // POST when we have attachments (so we can send the array in the
      // body), otherwise GET for the precise-only fast path.
      let response;
      if (Array.isArray(attachments) && attachments.length > 0) {
        const slimmed = attachments.map((a) => ({
          part: a?.part != null ? String(a.part) : null,
          filename: a?.filename || null,
          size: a?.size != null ? Number(a.size) : null,
        }));
        response = await api.post("/drive/email-attachments-status", {
          folder,
          uid,
          attachments: slimmed,
        });
      } else {
        response = await api.get("/drive/email-attachments-status", {
          params: { folder, uid },
        });
      }
      if (response.data?.success) {
        return response.data.data?.files || [];
      }
    } catch (e) {
      // Silent failure: this is a non-critical UX enhancement, falling
      // back to "no saved files known" is the right behavior on error.
      console.warn("fetchEmailAttachmentsStatus failed:", e?.message || e);
    }
    return [];
  }

  // Ensure a Drive file has a public share token, returning a usable
  // share URL. Reuses an existing token if present (so repeat clicks
  // produce the same link) and creates one with sensible defaults
  // otherwise. Used by the email view's per-attachment Share button.
  async function ensureShareLink(fileId, expiresHours = 168) {
    if (!fileId) return { success: false, error: "Missing file id" };
    const result = await createShareLink(fileId, expiresHours, null, null, false);
    if (result?.success && result.url) {
      return { success: true, url: result.url, token: result.token };
    }
    return { success: false, error: "Failed to create share link" };
  }

  // Upload file and create share link (for large attachments)
  // isEmailAttachment=true means file will be auto-deleted after expiry
  // Returns share URL if successful
  async function uploadAndShare(
    file,
    expiresHours = 168,
    isEmailAttachment = true
  ) {
    // Upload the file
    const uploadResult = await uploadFile(file);
    if (!uploadResult.success) {
      return { success: false, error: uploadResult.error };
    }

    // Create share link with 7-day expiry by default
    // Mark as email attachment so it gets auto-deleted after expiry
    const shareResult = await createShareLink(
      uploadResult.file.id,
      expiresHours,
      null,
      null,
      isEmailAttachment
    );
    if (!shareResult.success) {
      return { success: false, error: "Failed to create share link" };
    }

    return {
      success: true,
      file: uploadResult.file,
      url: shareResult.url,
      token: shareResult.token,
    };
  }

  function navigateToFolder(folderId) {
    isDebugEnabled() && console.log("[Drive] navigateToFolder called with:", folderId);
    // Any folder navigation exits Drive-wide search and shows that folder's
    // contents (do this before the early-return so clicking the folder you are
    // "already in" still drops out of search results).
    clearDriveSearch();
    // Leaving virtual sections always pops back into a regular folder view.
    isStarredView.value = false;
    isRecentView.value = false;
    // Don't navigate if already at this folder
    if (currentFolderId.value === folderId && !loading.value) {
      isDebugEnabled() && console.log("[Drive] Already at this folder, skipping");
      return;
    }
    fetchContents(folderId);
    // Record access for Recent view (fire-and-forget, real folders only).
    if (folderId) recordFolderAccess(folderId);
  }

  function navigateUp() {
    if (path.value.length > 1) {
      navigateToFolder(path.value[path.value.length - 2].id);
    } else {
      navigateToFolder(null);
    }
  }

  function navigateToRoot() {
    // Navigating home also drops out of any Drive-wide search.
    clearDriveSearch();
    // Always exit special views when navigating to root
    isTrashView.value = false;
    isSharingAccessView.value = false;
    isStarredView.value = false;
    isRecentView.value = false;

    if (currentFolderId.value === null && !loading.value) {
      isDebugEnabled() && console.log("[Drive] Already at root, skipping");
      return;
    }
    fetchContents(null);
  }

  // Selection functions
  function toggleFileSelection(fileId) {
    if (selectedFiles.value.has(fileId)) {
      selectedFiles.value.delete(fileId);
    } else {
      selectedFiles.value.add(fileId);
    }
    selectedFiles.value = new Set(selectedFiles.value); // Trigger reactivity
  }

  function toggleFolderSelection(folderId) {
    if (selectedFolders.value.has(folderId)) {
      selectedFolders.value.delete(folderId);
    } else {
      selectedFolders.value.add(folderId);
    }
    selectedFolders.value = new Set(selectedFolders.value); // Trigger reactivity
  }

  function selectFile(fileId, addToSelection = false) {
    if (!addToSelection) {
      selectedFiles.value = new Set([fileId]);
      selectedFolders.value = new Set();
    } else {
      selectedFiles.value.add(fileId);
      selectedFiles.value = new Set(selectedFiles.value);
    }
  }

  function selectFolder(folderId, addToSelection = false) {
    if (!addToSelection) {
      selectedFolders.value = new Set([folderId]);
      selectedFiles.value = new Set();
    } else {
      selectedFolders.value.add(folderId);
      selectedFolders.value = new Set(selectedFolders.value);
    }
  }

  function selectAll() {
    selectedFiles.value = new Set(files.value.map((f) => f.id));
    selectedFolders.value = new Set(folders.value.map((f) => f.id));
  }

  function clearSelection() {
    selectedFiles.value = new Set();
    selectedFolders.value = new Set();
  }

  function isFileSelected(fileId) {
    return selectedFiles.value.has(fileId);
  }

  function isFolderSelected(folderId) {
    return selectedFolders.value.has(folderId);
  }

  const hasSelection = computed(() => {
    return selectedFiles.value.size > 0 || selectedFolders.value.size > 0;
  });

  const selectionCount = computed(() => {
    return selectedFiles.value.size + selectedFolders.value.size;
  });

  // Bulk operations
  //
  // bulkDelete / bulkMove fire ONE HTTP request for the whole selection
  // (vs the per-item N-request pattern the legacy loops used). Local
  // state is updated optimistically in a single tick so the UI feels
  // instant; rollback on failure restores everything.

  /**
   * Batch delete files + folders in a single request.
   * @param {number[]} fileIds
   * @param {number[]} folderIds
   */
  async function bulkDelete(fileIds, folderIds) {
    const fileIdArr = Array.from(fileIds || []);
    const folderIdArr = Array.from(folderIds || []);
    if (fileIdArr.length === 0 && folderIdArr.length === 0) {
      return { success: 0, failed: 0 };
    }

    // Snapshot for rollback + quota accounting.
    const deletedFiles = files.value.filter(f => fileIdArr.includes(f.id));
    const deletedFolders = folders.value.filter(f => folderIdArr.includes(f.id));
    const fileIdSet = new Set(fileIdArr);
    const folderIdSet = new Set(folderIdArr);

    // Optimistic local removal.
    files.value = files.value.filter(f => !fileIdSet.has(f.id));
    folders.value = folders.value.filter(f => !folderIdSet.has(f.id));

    try {
      const response = await api.post('/drive/batch-delete', {
        fileIds: fileIdArr,
        folderIds: folderIdArr,
      });
      const data = response.data?.data || {};

      // Reconcile quota using the server's authoritative freedBytes total
      // (handles partial failures correctly: server only counts files it
      // actually deleted).
      const freed = data.freedBytes || 0;
      quota.value.used = Math.max(0, quota.value.used - freed);
      if (!quota.value.unlimited) {
        quota.value.available += freed;
      }

      // Search index cleanup.
      try {
        const searchStore = useSearchStore();
        for (const f of deletedFiles) searchStore.removeFromIndex('drive_file', f.id);
        for (const fo of deletedFolders) searchStore.removeFromIndex('drive_folder', fo.id);
      } catch { /* non-critical */ }

      return {
        success: data.success || 0,
        failed: data.failed || 0,
        errors: data.errors || [],
      };
    } catch (e) {
      console.error('Failed to bulk delete:', e);
      // Rollback optimistic removal.
      files.value = [...files.value, ...deletedFiles];
      folders.value = [...folders.value, ...deletedFolders];
      return { success: 0, failed: fileIdArr.length + folderIdArr.length, error: e?.response?.data?.message };
    }
  }

  /**
   * Batch move files + folders to a single target folder.
   * @param {number[]} fileIds
   * @param {number[]} folderIds
   * @param {number|null} targetFolderId
   */
  async function bulkMove(fileIds, folderIds, targetFolderId) {
    const fileIdArr = Array.from(fileIds || []);
    // Filter out self-move on folders (can't move a folder into itself).
    const folderIdArr = Array.from(folderIds || []).filter(id => id !== targetFolderId);
    if (fileIdArr.length === 0 && folderIdArr.length === 0) {
      return { success: 0, failed: 0 };
    }

    // Snapshot for rollback.
    const movedFiles = files.value.filter(f => fileIdArr.includes(f.id));
    const movedFolders = folders.value.filter(f => folderIdArr.includes(f.id));
    const fileIdSet = new Set(fileIdArr);
    const folderIdSet = new Set(folderIdArr);

    files.value = files.value.filter(f => !fileIdSet.has(f.id));
    folders.value = folders.value.filter(f => !folderIdSet.has(f.id));

    try {
      const response = await api.post('/drive/batch-move', {
        fileIds: fileIdArr,
        folderIds: folderIdArr,
        targetFolderId,
      });
      const data = response.data?.data || {};
      return {
        success: data.success || 0,
        failed: data.failed || 0,
        errors: data.errors || [],
      };
    } catch (e) {
      console.error('Failed to bulk move:', e);
      files.value = [...files.value, ...movedFiles];
      folders.value = [...folders.value, ...movedFolders];
      return { success: 0, failed: fileIdArr.length + folderIdArr.length, error: e?.response?.data?.message };
    }
  }

  async function deleteSelected() {
    const result = await bulkDelete(
      Array.from(selectedFiles.value),
      Array.from(selectedFolders.value)
    );
    clearSelection();
    return result;
  }

  /**
   * Batch move-to-trash for many files + folders in a single request.
   * Optimistically removes items from the active listing; rollback on
   * failure restores them.
   * @param {number[]} fileIds
   * @param {number[]} folderIds
   */
  async function bulkTrash(fileIds, folderIds) {
    const fileIdArr = Array.from(fileIds || []);
    const folderIdArr = Array.from(folderIds || []);
    if (fileIdArr.length === 0 && folderIdArr.length === 0) {
      return { success: 0, failed: 0 };
    }

    const trashedFiles = files.value.filter(f => fileIdArr.includes(f.id));
    const trashedFolders = folders.value.filter(f => folderIdArr.includes(f.id));
    const fileIdSet = new Set(fileIdArr);
    const folderIdSet = new Set(folderIdArr);

    files.value = files.value.filter(f => !fileIdSet.has(f.id));
    folders.value = folders.value.filter(f => !folderIdSet.has(f.id));

    try {
      const response = await api.post('/drive/batch-trash', {
        fileIds: fileIdArr,
        folderIds: folderIdArr,
      });
      const data = response.data?.data || {};
      return {
        success: data.success || 0,
        failed: data.failed || 0,
        errors: data.errors || [],
      };
    } catch (e) {
      console.error('Failed to bulk trash:', e);
      files.value = [...files.value, ...trashedFiles];
      folders.value = [...folders.value, ...trashedFolders];
      return { success: 0, failed: fileIdArr.length + folderIdArr.length, error: e?.response?.data?.message };
    }
  }

  /**
   * Batch restore-from-trash for many files + folders in a single request.
   * Optimistically removes items from the trash listing; rollback on
   * failure restores them.
   * @param {number[]} fileIds
   * @param {number[]} folderIds
   */
  async function bulkRestore(fileIds, folderIds) {
    const fileIdArr = Array.from(fileIds || []);
    const folderIdArr = Array.from(folderIds || []);
    if (fileIdArr.length === 0 && folderIdArr.length === 0) {
      return { success: 0, failed: 0 };
    }

    const restoredFiles = trashedItems.value.files.filter(f => fileIdArr.includes(f.id));
    const restoredFolders = trashedItems.value.folders.filter(f => folderIdArr.includes(f.id));
    const fileIdSet = new Set(fileIdArr);
    const folderIdSet = new Set(folderIdArr);

    trashedItems.value.files = trashedItems.value.files.filter(f => !fileIdSet.has(f.id));
    trashedItems.value.folders = trashedItems.value.folders.filter(f => !folderIdSet.has(f.id));

    try {
      const response = await api.post('/drive/batch-restore', {
        fileIds: fileIdArr,
        folderIds: folderIdArr,
      });
      const data = response.data?.data || {};
      return {
        success: data.success || 0,
        failed: data.failed || 0,
      };
    } catch (e) {
      console.error('Failed to bulk restore:', e);
      trashedItems.value.files = [...trashedItems.value.files, ...restoredFiles];
      trashedItems.value.folders = [...trashedItems.value.folders, ...restoredFolders];
      return { success: 0, failed: fileIdArr.length + folderIdArr.length, error: e?.response?.data?.message };
    }
  }

  async function moveSelected(targetFolderId) {
    const result = await bulkMove(
      Array.from(selectedFiles.value),
      Array.from(selectedFolders.value),
      targetFolderId
    );
    clearSelection();
    return result;
  }

  function clipboardCut() {
    clipboard.value = {
      mode: 'cut',
      fileIds: [...selectedFiles.value],
      folderIds: [...selectedFolders.value],
      sourceFolderId: currentFolderId.value,
    };
  }

  function clipboardCopy() {
    clipboard.value = {
      mode: 'copy',
      fileIds: [...selectedFiles.value],
      folderIds: [...selectedFolders.value],
      sourceFolderId: currentFolderId.value,
    };
  }

  function clipboardClear() {
    clipboard.value = { mode: null, fileIds: [], folderIds: [], sourceFolderId: null };
  }

  const hasClipboard = computed(() => {
    return clipboard.value.mode && (clipboard.value.fileIds.length > 0 || clipboard.value.folderIds.length > 0);
  });

  const clipboardCount = computed(() => {
    return clipboard.value.fileIds.length + clipboard.value.folderIds.length;
  });

  async function clipboardPaste(targetFolderId) {
    if (!clipboard.value.mode) return { success: 0, failed: 0 };

    let results = { success: 0, failed: 0 };

    if (clipboard.value.mode === 'cut') {
      // ONE batched move for the whole clipboard.
      results = await bulkMove(clipboard.value.fileIds, clipboard.value.folderIds, targetFolderId);
      clipboardClear();
    } else if (clipboard.value.mode === 'copy') {
      // Per-item copy is preserved for now: a batched copy endpoint would
      // need to duplicate underlying NAS blobs server-side, which is a
      // separate feature -- see plan A6. Cut (move) is the hot path.
      for (const fileId of clipboard.value.fileIds) {
        try {
          const res = await api.post(`/drive/files/${fileId}/copy`, { folder_id: targetFolderId });
          if (res.data?.success) results.success++;
          else results.failed++;
        } catch {
          results.failed++;
        }
      }
      for (const folderId of clipboard.value.folderIds) {
        if (folderId === targetFolderId) continue;
        try {
          const res = await api.post(`/drive/folders/${folderId}/copy`, { parent_id: targetFolderId });
          if (res.data?.success) results.success++;
          else results.failed++;
        } catch {
          results.failed++;
        }
      }
    }

    clearSelection();
    await fetchContents(targetFolderId, true);
    return results;
  }

  // Load thumbnail with authentication - returns blob URL
  // Uses backend thumbnail endpoint which caches in Redis for performance
  async function loadThumbnail(fileId, sharedFolderId = null) {
    // Use shared folder ID if we're in shared view
    const effectiveSharedFolderId = sharedFolderId || (isSharedView.value ? currentSharedFolder.value?.id : null);
    
    // Create a unique cache key that includes shared folder context
    const cacheKey = effectiveSharedFolderId ? `shared-${effectiveSharedFolderId}-${fileId}` : fileId;
    
    // Check current cache state
    const currentState = thumbnailCache.value[cacheKey];
    
    // Return cached blob URL if available
    if (currentState && currentState !== "loading" && currentState !== "failed") {
      return currentState;
    }

    // Already loading or failed - don't retry
    if (currentState === "loading" || currentState === "failed") {
      return null;
    }

    // Mark as loading (create new object for reactivity)
    thumbnailCache.value = { ...thumbnailCache.value, [cacheKey]: "loading" };

    try {
      const token = getToken("webmail_token");
      const sessionToken = getToken("webmail_session_token");
      const activeAccountId = getToken("webmail_active_account");
      
      // Build auth headers matching what the axios interceptor sends
      const authHeaders = { Authorization: `Bearer ${token}` };
      if (sessionToken) authHeaders["X-Session-Token"] = sessionToken;
      if (activeAccountId && activeAccountId !== "primary") authHeaders["X-Account-Id"] = activeAccountId;
      
      // Use thumbnail endpoint (smaller, Redis-cached) for regular files
      // Fall back to preview for shared files
      const url = effectiveSharedFolderId
        ? `${api.defaults.baseURL}/drive/shared/${effectiveSharedFolderId}/file/${fileId}/preview`
        : `${api.defaults.baseURL}/drive/files/${fileId}/thumbnail`;
      
      const response = await fetch(url, { headers: authHeaders });

      if (response.ok) {
        const blob = await response.blob();
        const blobUrl = URL.createObjectURL(blob);
        // Create new object to trigger reactivity
        thumbnailCache.value = { ...thumbnailCache.value, [cacheKey]: blobUrl };
        return blobUrl;
      }
      
      // If thumbnail fails (e.g., unsupported format like AVIF), try full preview as fallback
      if (!effectiveSharedFolderId && response.status !== 404) {
        const fallbackUrl = `${api.defaults.baseURL}/drive/files/${fileId}/preview`;
        const fallbackResponse = await fetch(fallbackUrl, { headers: authHeaders });
        if (fallbackResponse.ok) {
          const blob = await fallbackResponse.blob();
          const blobUrl = URL.createObjectURL(blob);
          thumbnailCache.value = { ...thumbnailCache.value, [cacheKey]: blobUrl };
          return blobUrl;
        }
      }
    } catch (e) {
      // Silently fail for thumbnail loading - not critical
      // console.error("Failed to load thumbnail:", e);
    }

    // Mark as failed (create new object for reactivity)
    // Use "failed" string instead of null for clearer state management
    thumbnailCache.value = { ...thumbnailCache.value, [cacheKey]: "failed" };
    return null;
  }

  // Get cached thumbnail or trigger load
  function getThumbnailUrl(file, sharedFolderId = null) {
    if (!file?.id) return null;

    // Use shared folder ID if we're in shared view
    const effectiveSharedFolderId = sharedFolderId || (isSharedView.value ? currentSharedFolder.value?.id : null);
    
    // Create a unique cache key that includes shared folder context
    const cacheKey = effectiveSharedFolderId ? `shared-${effectiveSharedFolderId}-${file.id}` : file.id;

    // Access cache to establish reactivity
    const cache = thumbnailCache.value;
    const cached = cache[cacheKey];

    // Return cached blob URL if available and valid
    if (cached && cached !== "loading" && cached !== "failed") {
      return cached;
    }

    // For images, trigger load ONLY if never tried before (undefined)
    // Don't retry if already loading or failed to prevent infinite loops
    if (file.mime_type?.startsWith("image/") && cached === undefined) {
      loadThumbnail(file.id, effectiveSharedFolderId);
    }

    return null;
  }

  // Check if thumbnail is loaded successfully
  function hasThumbnail(fileId, sharedFolderId = null) {
    // Use shared folder ID if we're in shared view
    const effectiveSharedFolderId = sharedFolderId || (isSharedView.value ? currentSharedFolder.value?.id : null);
    const cacheKey = effectiveSharedFolderId ? `shared-${effectiveSharedFolderId}-${fileId}` : fileId;
    const cached = thumbnailCache.value[cacheKey];
    // Only return true for valid blob URLs, not for loading/failed states
    return cached && cached !== "loading" && cached !== "failed";
  }

  // Clear thumbnail cache (on logout, etc.)
  function clearThumbnailCache() {
    // Revoke blob URLs to prevent memory leaks
    Object.values(thumbnailCache.value).forEach((url) => {
      if (
        url &&
        url !== "loading" &&
        typeof url === "string" &&
        url.startsWith("blob:")
      ) {
        URL.revokeObjectURL(url);
      }
    });
    thumbnailCache.value = {};
  }

  // ===== VIEW MODE =====

  function setViewMode(mode) {
    if (!["grid", "list", "compact"].includes(mode)) return;
    viewMode.value = mode;
    localStorage.setItem("drive_view_mode", mode);
  }

  function setSort(field, direction) {
    if (field) sortField.value = field;
    if (direction) sortDirection.value = direction;
    localStorage.setItem("drive_sort_field", sortField.value);
    localStorage.setItem("drive_sort_direction", sortDirection.value);
  }

  function toggleSortDirection() {
    setSort(sortField.value, sortDirection.value === "asc" ? "desc" : "asc");
  }

  // ===== TRASH OPERATIONS =====

  async function fetchTrash() {
    loadingTrash.value = true;
    try {
      const response = await api.get("/drive/trash");
      if (response.data.success) {
        trashedItems.value = {
          files: response.data.data.files || [],
          folders: response.data.data.folders || [],
        };
      }
    } catch (e) {
      console.error("Failed to fetch trash:", e);
    } finally {
      loadingTrash.value = false;
    }
  }

  async function trashFile(id) {
    try {
      const file = files.value.find((f) => f.id === id);
      const response = await api.post(`/drive/files/${id}/trash`);
      if (response.data.success) {
        files.value = files.value.filter((f) => f.id !== id);
        if (file) {
          quota.value.used -= file.size;
          if (!quota.value.unlimited) {
            quota.value.available += file.size;
          }
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to trash file:", e);
    }
    return false;
  }

  async function trashFolder(id) {
    try {
      const response = await api.post(`/drive/folders/${id}/trash`);
      if (response.data.success) {
        folders.value = folders.value.filter((f) => f.id !== id);
        return { success: true };
      }
      return { success: false, error: response.data.message || 'Failed to trash folder' };
    } catch (e) {
      console.error("Failed to trash folder:", e);
      // Return the error message from the server (e.g., 403 for protected folders)
      const errorMessage = e.response?.data?.message || 'Failed to trash folder';
      return { success: false, error: errorMessage, status: e.response?.status };
    }
  }

  async function restoreFile(id) {
    try {
      const response = await api.post(`/drive/files/${id}/restore`);
      if (response.data.success) {
        // Remove from trash list
        trashedItems.value.files = trashedItems.value.files.filter(
          (f) => f.id !== id
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to restore file:", e);
    }
    return false;
  }

  async function restoreFolder(id) {
    try {
      const response = await api.post(`/drive/folders/${id}/restore`);
      if (response.data.success) {
        // Remove from trash list
        trashedItems.value.folders = trashedItems.value.folders.filter(
          (f) => f.id !== id
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to restore folder:", e);
    }
    return false;
  }

  async function emptyTrash() {
    try {
      const response = await api.delete("/drive/trash");
      if (response.data.success) {
        trashedItems.value = { files: [], folders: [] };
        return { success: true, count: response.data.data.deleted_count };
      }
    } catch (e) {
      console.error("Failed to empty trash:", e);
    }
    return { success: false };
  }

  async function permanentlyDelete(id, type) {
    try {
      const response = await api.delete(`/drive/trash/${type}/${id}`);
      if (response.data.success) {
        if (type === "file") {
          trashedItems.value.files = trashedItems.value.files.filter(
            (f) => f.id !== id
          );
        } else {
          trashedItems.value.folders = trashedItems.value.folders.filter(
            (f) => f.id !== id
          );
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to permanently delete:", e);
    }
    return false;
  }

  function enterTrashView() {
    isTrashView.value = true;
    isSharedView.value = false;
    isSharingAccessView.value = false;
    isStarredView.value = false;
    isRecentView.value = false;
    fetchTrash();
  }

  function exitTrashView() {
    isTrashView.value = false;
    // Refresh current folder contents
    fetchContents(currentFolderId.value);
  }

  // ===== STARRED + RECENT =====

  async function fetchStarred() {
    loadingStarred.value = true;
    try {
      const response = await api.get("/drive/starred");
      if (response.data?.success) {
        starredItems.value = {
          files: response.data.data.files || [],
          folders: response.data.data.folders || [],
        };
      }
    } catch (e) {
      console.error("Failed to fetch starred items:", e);
    } finally {
      loadingStarred.value = false;
    }
  }

  async function fetchRecent(limit = 50) {
    loadingRecent.value = true;
    try {
      const response = await api.get(`/drive/recent`, { params: { limit } });
      if (response.data?.success) {
        recentItems.value = {
          files: response.data.data.files || [],
          folders: response.data.data.folders || [],
        };
      }
    } catch (e) {
      console.error("Failed to fetch recent items:", e);
    } finally {
      loadingRecent.value = false;
    }
  }

  function enterStarredView() {
    isStarredView.value = true;
    isRecentView.value = false;
    isTrashView.value = false;
    isSharedView.value = false;
    isSharingAccessView.value = false;
    clearSelection();
    fetchStarred();
  }

  function exitStarredView() {
    isStarredView.value = false;
  }

  function enterRecentView() {
    isRecentView.value = true;
    isStarredView.value = false;
    isTrashView.value = false;
    isSharedView.value = false;
    isSharingAccessView.value = false;
    clearSelection();
    fetchRecent();
  }

  function exitRecentView() {
    isRecentView.value = false;
  }

  /**
   * Toggle the star flag on a file or folder.
   * Optimistically flips local lists and rolls back on failure.
   */
  async function toggleStar(type, id) {
    const collection = type === "folder" ? folders : files;
    const item = collection.value.find((x) => x.id === id);
    const previous = item ? !!item.is_starred : false;
    if (item) item.is_starred = previous ? 0 : 1;

    try {
      const response = await api.post(`/drive/${type}/${id}/star`);
      if (response.data?.success) {
        const newState = !!response.data.data.is_starred;
        if (item) item.is_starred = newState ? 1 : 0;
        // Keep the Starred list in sync if it's currently open.
        if (isStarredView.value) fetchStarred();
        return newState;
      }
      if (item) item.is_starred = previous ? 1 : 0;
      return previous;
    } catch (e) {
      console.error(`Failed to toggle star on ${type}/${id}:`, e);
      if (item) item.is_starred = previous ? 1 : 0;
      return previous;
    }
  }

  async function recordFolderAccess(folderId) {
    if (!folderId) return;
    try {
      await api.post(`/drive/folders/${folderId}/access`);
    } catch (e) {
      // Silent fail - this is a non-critical telemetry call
    }
  }

  // ===== SHARED WITH ME (Collaborators) =====

  const sharedWithMe = ref([]);
  const sharedFilesWithMe = ref([]); // Files shared directly with me (person/group share)
  const loadingSharedWithMe = ref(false);
  const isSharedView = ref(false);
  const currentSharedFolder = ref(null); // The root shared folder (has permission, owner info)
  const sharedFolderPath = ref([]); // Path within shared folder

  async function fetchSharedWithMe() {
    loadingSharedWithMe.value = true;
    try {
      const response = await api.get("/drive/shared-with-me");
      if (response.data.success) {
        sharedWithMe.value = response.data.data.folders || [];
        sharedFilesWithMe.value = response.data.data.files || [];
      }
    } catch (e) {
      console.error("Failed to fetch shared folders:", e);
    } finally {
      loadingSharedWithMe.value = false;
    }
  }

  // Enter a shared folder view
  async function enterSharedFolder(sharedFolder) {
    loading.value = true;
    isSharedView.value = true;
    isTrashView.value = false;
    isSharingAccessView.value = false;
    isStarredView.value = false;
    isRecentView.value = false;
    currentSharedFolder.value = sharedFolder;
    sharedFolderPath.value = [];
    clearSelection();

    try {
      const response = await api.get(`/drive/shared/${sharedFolder.id}`);
      if (response.data.success) {
        folders.value = response.data.data.folders || [];
        files.value = response.data.data.files || [];
        currentFolder.value = response.data.data.folder;
        currentFolderId.value = sharedFolder.id;
        // Update permission from response if available
        if (response.data.data.permission) {
          currentSharedFolder.value.permission = response.data.data.permission;
        }
      }
    } catch (e) {
      console.error("Failed to enter shared folder:", e);
      exitSharedView();
    } finally {
      loading.value = false;
    }
  }

  // Navigate within a shared folder (to subfolders)
  async function navigateSharedSubfolder(subfolderId) {
    if (!isSharedView.value || !currentSharedFolder.value) return;

    loading.value = true;
    clearSelection();

    try {
      // For subfolders, we use the same shared endpoint with the root folder ID
      // but the backend should handle subfolder access
      const response = await api.get(`/drive/shared/${currentSharedFolder.value.id}/subfolder/${subfolderId}`);
      if (response.data.success) {
        folders.value = response.data.data.folders || [];
        files.value = response.data.data.files || [];
        currentFolder.value = response.data.data.folder;
        currentFolderId.value = subfolderId;
        // Update path
        sharedFolderPath.value = response.data.data.path || [];
      }
    } catch (e) {
      console.error("Failed to navigate shared subfolder:", e);
    } finally {
      loading.value = false;
    }
  }

  // Exit shared folder view and return to My Drive
  function exitSharedView() {
    isSharedView.value = false;
    currentSharedFolder.value = null;
    sharedFolderPath.value = [];
    fetchContents(null); // Go back to root of My Drive
  }

  // Check if user can edit in current shared folder
  const canEditSharedFolder = computed(() => {
    if (!isSharedView.value || !currentSharedFolder.value) return false;
    return currentSharedFolder.value.permission === 'editor';
  });

  async function fetchCollaborators(folderId) {
    try {
      const response = await api.get(
        `/drive/folders/${folderId}/collaborators`
      );
      if (response.data.success) {
        return response.data.data.collaborators || [];
      }
    } catch (e) {
      console.error("Failed to fetch collaborators:", e);
    }
    return [];
  }

  async function fetchGroupAccess(folderId) {
    try {
      const response = await api.get(
        `/drive/folders/${folderId}/group-access`
      );
      if (response.data.success) {
        return response.data.data || [];
      }
    } catch (e) {
      console.error("Failed to fetch group access:", e);
    }
    return [];
  }

  async function addCollaborator(folderId, email, permission = "viewer") {
    try {
      const response = await api.post(
        `/drive/folders/${folderId}/collaborators`,
        {
          email,
          permission,
        }
      );
      if (response.data.success) {
        return { success: true, collaborator: response.data.data.collaborator };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      console.error("Failed to add collaborator:", e);
      return {
        success: false,
        error: e.response?.data?.message || "Failed to add collaborator",
      };
    }
  }

  async function removeCollaborator(folderId, email) {
    try {
      const response = await api.delete(
        `/drive/folders/${folderId}/collaborators/${encodeURIComponent(email)}`
      );
      return response.data.success;
    } catch (e) {
      console.error("Failed to remove collaborator:", e);
      return false;
    }
  }

  async function updateCollaboratorPermission(folderId, email, permission) {
    try {
      const response = await api.put(
        `/drive/folders/${folderId}/collaborators/${encodeURIComponent(email)}`,
        {
          permission,
        }
      );
      return response.data.success;
    } catch (e) {
      console.error("Failed to update collaborator permission:", e);
      return false;
    }
  }

  // Get contents of a shared folder (as collaborator)
  async function fetchSharedFolderContents(folderId) {
    loading.value = true;
    try {
      const response = await api.get(`/drive/shared/${folderId}`);
      if (response.data.success) {
        return response.data.data;
      }
    } catch (e) {
      console.error("Failed to fetch shared folder contents:", e);
    } finally {
      loading.value = false;
    }
    return null;
  }

  async function createFolderInShared(parentFolderId, name) {
    try {
      const response = await api.post(`/drive/shared/${parentFolderId}/folders`, {
        name,
      });
      if (response.data.success) {
        return { success: true, folder: response.data.data.folder };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      console.error("Failed to create folder in shared view:", e);
      return {
        success: false,
        error: e.response?.data?.message || "Failed to create folder",
      };
    }
  }

  // Upload to shared folder
  async function uploadToSharedFolder(folderId, file) {
    uploading.value = true;
    uploadProgress.value = 0;

    try {
      const formData = new FormData();
      formData.append("file", file);

      // Bypasses CapacitorHttp on native (drops multipart file parts); axios on web.
      const body = await uploadFormData(
        `/drive/shared/${folderId}/upload`,
        formData,
        (pct) => { uploadProgress.value = pct; }
      );

      if (body.success) {
        return { success: true, file: body.data.file };
      }
      return { success: false, error: body.message };
    } catch (e) {
      return {
        success: false,
        error: describeUploadError(e),
      };
    } finally {
      uploading.value = false;
      uploadProgress.value = 0;
    }
  }

  // Delete from shared folder
  async function deleteFromSharedFolder(fileId) {
    try {
      const response = await api.delete(`/drive/shared/files/${fileId}`);
      return response.data.success;
    } catch (e) {
      console.error("Failed to delete from shared folder:", e);
      return false;
    }
  }

  // ===== FILE VERSIONING =====

  async function uploadFileVersioned(file, folderId = null) {
    uploading.value = true;
    uploadProgress.value = 0;

    try {
      const targetFolderId = folderId || currentFolder.value?.id || null;

      // Uses single-request upload, with automatic small-chunk fallback when the
      // server rejects the body as too large (keeps large photos working).
      const uploadedFile = await uploadFileResilient(file, targetFolderId, (pct) => {
        uploadProgress.value = pct;
      });

      if (uploadedFile) {
        // Check if file already exists in list (version update)
        const existingIndex = files.value.findIndex(
          (f) => f.id === uploadedFile.id
        );
        if (existingIndex >= 0) {
          // Update existing file
          files.value[existingIndex] = uploadedFile;
        } else {
          // Add new file
          files.value.push(uploadedFile);
        }

        quota.value.used += uploadedFile.size;
        trackEvent("drive_file_uploaded", {
          name: uploadedFile.name,
          size: uploadedFile.size,
          versioned: true,
        });
        if (!quota.value.unlimited) {
          quota.value.available -= uploadedFile.size;
        }
        return { success: true, file: uploadedFile };
      }
      return { success: false, error: "Upload failed" };
    } catch (e) {
      return {
        success: false,
        error: describeUploadError(e),
      };
    } finally {
      uploading.value = false;
      uploadProgress.value = 0;
    }
  }

  // Files larger than this are uploaded in chunks. A single large request body
  // is unreliable (it can exceed the ~2GB LSAPI limit, and big multipart POSTs
  // get killed mid-flight -> ERR_CONNECTION_CLOSED / HTTP2 errors). Chunking
  // keeps each request small and adds per-chunk retry, so use it well below the
  // hard limit.
  const CHUNK_THRESHOLD = 100 * 1024 * 1024; // 100 MB
  const CHUNK_SIZE = 64 * 1024 * 1024; // 64 MB per chunk (well under the limit)

  // Fallback chunk size used when a normal upload is rejected for being too large.
  // 1.5 MB stays safely under PHP's conservative defaults (upload_max_filesize 2M,
  // post_max_size 8M), so uploads succeed even if the server limits were never
  // raised. This is the safety net behind the iPhone "No file uploaded" failures
  // (a large photo POST exceeding post_max_size makes PHP discard the whole body).
  const FALLBACK_CHUNK_SIZE = Math.floor(1.5 * 1024 * 1024); // 1.5 MB

  // True when a failure looks like the server rejecting the request body as too
  // large (so retrying with small chunks can succeed). Deliberately does NOT match
  // quota/auth errors, which must surface immediately instead of retrying.
  function isLikelySizeLimitError(e) {
    if (e?.response?.status === 413) return true;
    const contentType = String(e?.response?.headers?.["content-type"] || "").toLowerCase();
    if (contentType.includes("text/html")) return true; // LSAPI/OLS body-too-large page
    // Body killed mid-flight before any response (ERR_CONNECTION_CLOSED etc.)
    if (!e?.response && (e?.message === "Network Error" || e?.code === "ERR_NETWORK")) return true;
    const msg = String(e?.response?.data?.message || e?.message || "").toLowerCase();
    return (
      msg.includes("no file uploaded") ||
      msg.includes("no chunk uploaded") ||
      msg.includes("file data is empty") ||
      msg.includes("post_max_size") ||
      msg.includes("upload_max_filesize") ||
      msg.includes("too large") ||
      msg.includes("exceed")
    );
  }

  function makeUploadId() {
    try {
      if (typeof crypto !== "undefined" && crypto.randomUUID) {
        return crypto.randomUUID().replace(/-/g, "");
      }
    } catch (_) {
      /* fall through */
    }
    return (
      Date.now().toString(36) +
      Math.random().toString(36).slice(2, 12) +
      Math.random().toString(36).slice(2, 12)
    );
  }

  // Upload a single (large) file in chunks. Resolves with the committed file
  // object, or throws on failure. onProgress(pct) reports 0-100 across the
  // whole file. Each chunk is retried a few times to survive transient errors.
  async function uploadFileChunked(file, folderId, onProgress, chunkSize = CHUNK_SIZE) {
    const uploadId = makeUploadId();
    const effectiveChunkSize = chunkSize > 0 ? chunkSize : CHUNK_SIZE;
    const totalChunks = Math.max(1, Math.ceil(file.size / effectiveChunkSize));

    for (let index = 0; index < totalChunks; index++) {
      const start = index * effectiveChunkSize;
      const end = Math.min(start + effectiveChunkSize, file.size);
      const blob = file.slice(start, end);

      let body;
      let attempt = 0;
      while (true) {
        const formData = new FormData();
        formData.append("chunk", blob);
        formData.append("upload_id", uploadId);
        formData.append("chunk_index", index);
        formData.append("total_chunks", totalChunks);
        formData.append("chunk_size", effectiveChunkSize);
        formData.append("file_name", file.name);
        formData.append("file_size", file.size);
        if (folderId) formData.append("folder_id", folderId);

        try {
          // Map this chunk's 0-100 progress onto overall file progress. Bypasses
          // CapacitorHttp on native so the chunk's binary body isn't dropped.
          body = await uploadFormData("/drive/upload-chunk", formData, (chunkPct) => {
            if (typeof onProgress === "function") {
              const uploadedBytes = start + ((chunkPct / 100) * (end - start));
              onProgress(
                Math.min(100, Math.round((uploadedBytes * 100) / file.size))
              );
            }
          });
          break;
        } catch (err) {
          attempt++;
          if (attempt >= 3) throw err;
          await new Promise((r) => setTimeout(r, 1000 * attempt));
        }
      }

      if (!body?.success) {
        throw new Error(body?.message || "Chunk upload failed");
      }

      // The final chunk commits the file and returns the created record.
      if (index === totalChunks - 1) {
        if (typeof onProgress === "function") onProgress(100);
        const uploaded = body.data?.file;
        if (!uploaded) throw new Error("Upload did not finalize");
        return uploaded;
      }
    }

    throw new Error("Upload produced no file");
  }

  // Upload a single file in one request (the fast path for normal-sized files).
  // Throws on failure so callers can decide whether to fall back to chunking.
  async function uploadSingleFile(file, folderId, onProgress) {
    const formData = new FormData();
    formData.append("file", file);
    if (folderId) formData.append("folder_id", folderId);

    // uploadFormData bypasses CapacitorHttp on native (which would otherwise drop
    // the file part of the multipart body), and uses axios with progress on web.
    const body = await uploadFormData("/drive/upload-versioned", formData, onProgress);

    if (!body?.success) {
      throw new Error(body?.message || "Upload failed");
    }
    return body.data.file;
  }

  // Upload one file using the best strategy, transparently falling back to
  // small-chunk upload when the server rejects the request body as too large.
  // This is what makes large photos (e.g. multi-MB iPhone PNGs) upload even when
  // the server's post_max_size / upload_max_filesize are set conservatively.
  async function uploadFileResilient(file, folderId, onProgress) {
    if (file.size > CHUNK_THRESHOLD) {
      try {
        return await uploadFileChunked(file, folderId, onProgress);
      } catch (err) {
        if (!isLikelySizeLimitError(err)) throw err;
        if (typeof onProgress === "function") onProgress(0);
        return await uploadFileChunked(file, folderId, onProgress, FALLBACK_CHUNK_SIZE);
      }
    }

    try {
      return await uploadSingleFile(file, folderId, onProgress);
    } catch (err) {
      if (!isLikelySizeLimitError(err)) throw err;
      if (typeof onProgress === "function") onProgress(0);
      return await uploadFileChunked(file, folderId, onProgress, FALLBACK_CHUNK_SIZE);
    }
  }

  // Bulk upload multiple files with progress tracking
  async function uploadFilesBulk(fileList, folderId = null) {
    const filesToUpload = Array.from(fileList);
    if (filesToUpload.length === 0) return { success: true, completed: 0, failed: 0 };

    // Initialize bulk upload state
    bulkUpload.value = {
      active: true,
      total: filesToUpload.length,
      current: 0,
      currentFileName: '',
      currentProgress: 0,
      completed: 0,
      failed: 0,
      failedFiles: []
    };

    uploading.value = true;
    uploadProgress.value = 0;

    // Overall progress across every file, used by the header Upload button (the
    // "%" / ring) so it reflects real progress instead of sitting at 0%.
    const syncOverall = () => {
      uploadProgress.value = Math.min(
        100,
        Math.round(
          ((bulkUpload.value.current - 1) + bulkUpload.value.currentProgress / 100) /
            bulkUpload.value.total * 100
        )
      );
    };

    for (let i = 0; i < filesToUpload.length; i++) {
      const file = filesToUpload[i];
      bulkUpload.value.current = i + 1;
      bulkUpload.value.currentFileName = file.name;
      bulkUpload.value.currentProgress = 0;

      try {
        const targetFolderId = folderId || currentFolder.value?.id || null;

        // Picks single-request vs chunked automatically and falls back to small
        // chunks if the server rejects the body as too large.
        const uploaded = await uploadFileResilient(file, targetFolderId, (pct) => {
          bulkUpload.value.currentProgress = pct;
          syncOverall();
        });

        // Update files list
        const existingIndex = files.value.findIndex((f) => f.id === uploaded.id);
        if (existingIndex >= 0) {
          files.value[existingIndex] = uploaded;
        } else {
          files.value.push(uploaded);
        }

        quota.value.used += uploaded.size;
        if (!quota.value.unlimited) {
          quota.value.available -= uploaded.size;
        }
        bulkUpload.value.completed++;
        syncOverall();
      } catch (e) {
        bulkUpload.value.failed++;
        bulkUpload.value.failedFiles.push({
          name: file.name,
          error: describeUploadError(e)
        });
      }
    }

    const result = {
      success: bulkUpload.value.failed === 0,
      completed: bulkUpload.value.completed,
      failed: bulkUpload.value.failed,
      failedFiles: bulkUpload.value.failedFiles
    };

    // Keep the progress visible for a moment before clearing
    setTimeout(() => {
      bulkUpload.value.active = false;
      uploading.value = false;
      uploadProgress.value = 0;
    }, 1500);

    return result;
  }

  // Bulk upload to shared folder
  async function uploadToSharedFolderBulk(folderId, fileList) {
    const filesToUpload = Array.from(fileList);
    if (filesToUpload.length === 0) return { success: true, completed: 0, failed: 0 };

    bulkUpload.value = {
      active: true,
      total: filesToUpload.length,
      current: 0,
      currentFileName: '',
      currentProgress: 0,
      completed: 0,
      failed: 0,
      failedFiles: []
    };

    uploading.value = true;

    for (let i = 0; i < filesToUpload.length; i++) {
      const file = filesToUpload[i];
      bulkUpload.value.current = i + 1;
      bulkUpload.value.currentFileName = file.name;
      bulkUpload.value.currentProgress = 0;

      try {
        const formData = new FormData();
        formData.append("file", file);

        // uploadFormData bypasses CapacitorHttp on native (which drops the file
        // part of multipart bodies) and uses axios with progress on web.
        const body = await uploadFormData(
          `/drive/shared/${folderId}/upload`,
          formData,
          (pct) => { bulkUpload.value.currentProgress = pct; }
        );

        if (body?.success) {
          bulkUpload.value.completed++;
        } else {
          bulkUpload.value.failed++;
          bulkUpload.value.failedFiles.push({ name: file.name, error: body?.message });
        }
      } catch (e) {
        bulkUpload.value.failed++;
        bulkUpload.value.failedFiles.push({ 
          name: file.name, 
          error: describeUploadError(e) 
        });
      }
    }

    const result = {
      success: bulkUpload.value.failed === 0,
      completed: bulkUpload.value.completed,
      failed: bulkUpload.value.failed,
      failedFiles: bulkUpload.value.failedFiles
    };

    setTimeout(() => {
      bulkUpload.value.active = false;
      uploading.value = false;
    }, 1500);

    return result;
  }

  async function fetchFileVersions(fileId) {
    loadingVersions.value = true;
    try {
      const response = await api.get(`/drive/files/${fileId}/versions`);
      if (response.data.success) {
        fileVersions.value[fileId] = response.data.data.versions || [];
        return response.data.data.versions;
      }
    } catch (e) {
      console.error("Failed to fetch file versions:", e);
    } finally {
      loadingVersions.value = false;
    }
    return [];
  }

  async function restoreVersion(fileId, versionId) {
    try {
      const response = await api.post(
        `/drive/files/${fileId}/versions/${versionId}/restore`
      );
      if (response.data.success) {
        // Refresh versions list
        await fetchFileVersions(fileId);
        // Refresh file in list
        await fetchContents(currentFolderId.value);
        return true;
      }
    } catch (e) {
      console.error("Failed to restore version:", e);
    }
    return false;
  }

  async function deleteVersion(fileId, versionId) {
    try {
      const response = await api.delete(
        `/drive/files/${fileId}/versions/${versionId}`
      );
      if (response.data.success) {
        // Update versions cache
        if (fileVersions.value[fileId]) {
          fileVersions.value[fileId] = fileVersions.value[fileId].filter(
            (v) => v.id !== versionId
          );
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to delete version:", e);
    }
    return false;
  }

  function getVersionDownloadUrl(fileId, versionId) {
    return `${api.defaults.baseURL}/drive/files/${fileId}/versions/${versionId}/download`;
  }

  async function fetchVersionPreview(fileId, versionId) {
    try {
      const response = await api.get(
        `/drive/files/${fileId}/versions/${versionId}/preview`
      );
      if (response.data.success) {
        return response.data.data;
      }
      throw new Error(response.data.message || "Failed to fetch version preview");
    } catch (e) {
      console.error("Failed to fetch version preview:", e);
      throw e;
    }
  }

  async function pinVersion(fileId, versionId, pinned) {
    try {
      const response = await api.post(
        `/drive/files/${fileId}/versions/${versionId}/pin`,
        { pinned }
      );
      if (response.data.success) {
        const cached = fileVersions.value[fileId];
        if (cached) {
          const v = cached.find((x) => x.id === versionId);
          if (v) v.is_pinned = pinned ? 1 : 0;
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to pin version:", e);
    }
    return false;
  }

  async function setVersionLabel(fileId, versionId, label) {
    try {
      const response = await api.patch(
        `/drive/files/${fileId}/versions/${versionId}`,
        { label }
      );
      if (response.data.success) {
        const cached = fileVersions.value[fileId];
        if (cached) {
          const v = cached.find((x) => x.id === versionId);
          if (v) v.label = label;
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to set version label:", e);
    }
    return false;
  }

  async function fetchVersionsUsage() {
    try {
      const response = await api.get(`/drive/versions/usage`);
      if (response.data.success) {
        return response.data.data.usage;
      }
    } catch (e) {
      console.error("Failed to fetch versions usage:", e);
    }
    return null;
  }

  // Deletes every unpinned version of one file; returns {deleted, freed_bytes} or null
  async function cleanupFileVersions(fileId) {
    try {
      const response = await api.post(`/drive/files/${fileId}/versions/cleanup`);
      if (response.data.success) {
        await fetchFileVersions(fileId);
        return response.data.data;
      }
    } catch (e) {
      console.error("Failed to clean up file versions:", e);
    }
    return null;
  }

  // Deletes every unpinned version account-wide; returns {deleted, freed_bytes} or null
  async function cleanupAllVersions() {
    try {
      const response = await api.post(`/drive/versions/cleanup`);
      if (response.data.success) {
        // Refresh quota (it arrives with folder contents)
        await fetchContents(currentFolderId.value);
        return response.data.data;
      }
    } catch (e) {
      console.error("Failed to clean up versions:", e);
    }
    return null;
  }

  // ===== ACTIVITY TRACKING =====

  async function recordFileAccess(fileId) {
    try {
      await api.post(`/drive/files/${fileId}/access`);
    } catch (e) {
      // Silent fail
    }
  }

  async function getFileDetails(fileId) {
    try {
      const response = await api.get(`/drive/files/${fileId}/details`);
      if (response.data.success) {
        return response.data.data.file;
      }
    } catch (e) {
      console.error("Failed to get file details:", e);
    }
    return null;
  }

  // ===== ACTIVITY LOG (Sync Events) =====

  // Fetch activity log for the panel (all events with pagination)
  async function fetchActivityLog(limit = 50, offset = 0) {
    try {
      const response = await api.get('/drive/sync-events', {
        params: { all: 'true', limit, offset }
      });
      if (response.data.success) {
        return {
          events: response.data.data.events || [],
          total: response.data.data.total || 0
        };
      }
    } catch (e) {
      console.error('Failed to fetch activity log:', e);
    }
    return { events: [], total: 0 };
  }

  // Fetch sync events for real-time polling (recent events since timestamp)
  async function fetchSyncEvents(sinceTimestamp) {
    try {
      const response = await api.get('/drive/sync-events', {
        params: { since: Math.floor(sinceTimestamp / 1000) }
      });
      if (response.data.success) {
        return response.data.data.events || [];
      }
    } catch (e) {
      console.error('Failed to fetch sync events:', e);
    }
    return [];
  }

  // Delete a single activity event
  async function deleteActivityEvent(eventId) {
    try {
      const response = await api.delete(`/drive/sync-events/${eventId}`);
      return response.data.success;
    } catch (e) {
      console.error('Failed to delete activity event:', e);
      return false;
    }
  }

  // Clear all activity events
  async function clearActivityLog() {
    try {
      const response = await api.delete('/drive/sync-events');
      return response.data.success;
    } catch (e) {
      console.error('Failed to clear activity log:', e);
      return false;
    }
  }

  return {
    folders,
    files,
    allFolders,
    currentFolder,
    currentFolderId,
    path,
    quota,
    formattedQuota,
    loading,
    // Drive-wide search
    searchActive,
    searchLoading,
    searchFolders,
    searchFiles,
    searchDrive,
    clearDriveSearch,
    uploading,
    uploadProgress,
    bulkUpload,
    uploadFilesBulk,
    uploadToSharedFolderBulk,
    // View mode
    viewMode,
    setViewMode,
    // Sort state
    sortField,
    sortDirection,
    setSort,
    toggleSortDirection,
    // High-level section (computed)
    currentSection,
    // Trash
    trashedItems,
    loadingTrash,
    isTrashView,
    fetchTrash,
    trashFile,
    trashFolder,
    restoreFile,
    restoreFolder,
    emptyTrash,
    permanentlyDelete,
    enterTrashView,
    exitTrashView,
    // Shared with me (Collaborators)
    sharedWithMe,
    sharedFilesWithMe,
    loadingSharedWithMe,
    isSharedView,
    currentSharedFolder,
    sharedFolderPath,
    canEditSharedFolder,
    fetchSharedWithMe,
    enterSharedFolder,
    navigateSharedSubfolder,
    exitSharedView,
    fetchCollaborators,
    fetchGroupAccess,
    addCollaborator,
    removeCollaborator,
    updateCollaboratorPermission,
    fetchSharedFolderContents,
    createFolderInShared,
    uploadToSharedFolder,
    deleteFromSharedFolder,
    // Versioning
    fileVersions,
    loadingVersions,
    uploadFileVersioned,
    fetchFileVersions,
    restoreVersion,
    deleteVersion,
    getVersionDownloadUrl,
    fetchVersionPreview,
    pinVersion,
    setVersionLabel,
    fetchVersionsUsage,
    cleanupFileVersions,
    cleanupAllVersions,
    // Activity
    recordFileAccess,
    recordFolderAccess,
    getFileDetails,
    // Starred + Recent
    starredItems,
    recentItems,
    loadingStarred,
    loadingRecent,
    isStarredView,
    isRecentView,
    fetchStarred,
    fetchRecent,
    enterStarredView,
    exitStarredView,
    enterRecentView,
    exitRecentView,
    toggleStar,
    // Selection
    selectedFiles,
    selectedFolders,
    hasSelection,
    selectionCount,
    toggleFileSelection,
    toggleFolderSelection,
    selectFile,
    selectFolder,
    selectAll,
    clearSelection,
    isFileSelected,
    isFolderSelected,
    deleteSelected,
    moveSelected,
    bulkDelete,
    bulkMove,
    bulkTrash,
    bulkRestore,
    // Clipboard
    clipboard,
    hasClipboard,
    clipboardCount,
    clipboardCut,
    clipboardCopy,
    clipboardPaste,
    clipboardClear,
    // Operations
    fetchContents,
    fetchAllFolders,
    createFolder,
    renameFolder,
    updateFolderColor,
    deleteFolder,
    uploadFile,
    uploadFileVersioned,
    uploadAndShare,
    saveEmailAttachment,
    fetchEmailAttachmentsStatus,
    ensureShareLink,
    deleteFile,
    renameFile,
    moveFile,
    moveFolder,
    createShareLink,
    shareForEmail,
    removeShareLink,
    createFolderShareLink,
    removeFolderShareLink,
    getDownloadUrl,
    requestDownloadToken,
    navigateToFolder,
    navigateUp,
    navigateToRoot,
    // Thumbnails
    thumbnailCache,
    loadThumbnail,
    getThumbnailUrl,
    hasThumbnail,
    clearThumbnailCache,
    // Activity Log
    fetchActivityLog,
    fetchSyncEvents,
    deleteActivityEvent,
    clearActivityLog,
    // Chat shared indicators
    chatSharedFileIds,
    chatSharedFolderIds,
    fetchChatSharedIds,
    isSharedInChat,
    // Sharing & Access dashboard
    isSharingAccessView,
    sharingOverview,
    loadingSharingOverview,
    enterSharingAccessView,
    exitSharingAccessView,
    fetchSharingOverview,
    revokeAccess,
    updateAccessRole,
  };
});
