import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/services/api";
import { useMailSyncSocket, EventTypes } from "@/services/mailSyncSocket";
import { useAuthStore } from "@/stores/auth";
import { useBoardsStore } from "@/addons/kanban-boards/stores/boards";
import { isDebugEnabled } from "@/utils/debug";
import { exportMoodBoardPdf } from "../services/moodBoardPdfExportService.js";
import { exportMoodBoardPptx } from "../services/moodBoardPptxExportService.js";
import { setupWebSocketService } from "../services/moodBoardWebSocketService.js";
import { createUndoRedoService } from "../services/moodBoardUndoService.js";
import { createMotionSettings } from "../services/moodBoardMotionService.js";
import * as _layerOrder from "../utils/layerOrderUtils.js";
import { writeToSystemClipboard, parseFromClipboard } from "../utils/clipboardSerializer.js";
import { deepClone } from "../utils/deepClone.js";

export const useMoodBoardsStore = defineStore("moodBoards", () => {
  const ENTERABLE_CONTAINER_TYPES = new Set(['frame', 'group', 'artboard', 'column', 'repeat_grid', 'slide'])
  // State
  const boards = ref([]);
  const currentBoard = ref(null);
  const loading = ref(false);
  const boardLoading = ref(false);

  // Canvas state (local, not persisted to server on every change)
  const zoom = ref(1);
  const panX = ref(0);
  const panY = ref(0);
  const selectedItemIds = ref(new Set());
  const isDragging = ref(false);
  const draggedItemIds = ref(new Set());
  const isPanning = ref(false);
  const connectingFrom = ref(null); // item id when drawing a connection
  const editingGroupId = ref(null); // when set, user is "inside" a group (Figma-like)

  // Presentation mode state
  const presentationMode = ref(false);
  const currentSlideIndex = ref(0);
  const showFilmstrip = ref(false);
  const focusedSlideId = ref(null); // currently viewed slide in filmstrip
  const presShowLines = ref(true);       // togglable: show connection lines during presentation
  const presShowBackground = ref(true);  // togglable: show dot grid during presentation

  // Public share / read-only state
  const isPublicView = ref(false);      // True when viewing a shared board via public link
  const publicShareMode = ref('view');  // 'view' or 'edit' — what the share link allows
  const publicShareToken = ref(null);   // The share token for the current public view

  // Follow collaborator cursor state
  const followingUser = ref(null); // email of the user being followed
  const followMode = ref('cursor'); // 'cursor' = follow cursor, 'viewport' = lock to their exact viewport

  // Focus mode state
  const focusedItemId = ref(null); // when set, all other items are dimmed

  // Scroll-driven storytelling mode
  const scrollStoryMode = ref(false); // when true, board items reveal as you scroll
  const scrollStoryProgress = ref(0); // 0 to 1, how far through the story

  // Ambient motion state + persistence (extracted to moodBoardMotionService.js)
  const _motionService = createMotionSettings({
    currentBoard, boardLoading, presentationMode, isPublicView,
    isPrePresActive: () => !!_prePresState,
  })
  const {
    motionEnabled, motionCards, motionElements, motionLines,
    motionIntensity, motionCardIntensity, motionSpeed,
    motionLineWave, motionLineSpeed, motionLineDensity,
    motionDrawOn, motionDrawOnTrigger, motionDrawOnSpeed,
    slidesVisible,
    getMotionSettingsPayload, restoreMotionSettings, saveMotionSettings,
  } = _motionService

  // ─── Rulers & Guide lines ──────────────────────────────
  const showRulers = ref(false);           // show/hide ruler bars along canvas edges
  const showGuides = ref(true);            // show/hide dragged guide lines
  const guides = ref([]);                  // { id, axis: 'x'|'y', position: number (canvas coords) }

  function addGuide(axis, position) {
    guides.value.push({ id: Date.now() + Math.random(), axis, position });
    debounceSaveGuides();
  }

  function removeGuide(id) {
    guides.value = guides.value.filter(g => g.id !== id);
    debounceSaveGuides();
  }

  function moveGuide(id, newPosition) {
    const g = guides.value.find(g => g.id === id);
    if (g) g.position = Math.round(newPosition);
    debounceSaveGuides();
  }

  function clearAllGuides() {
    guides.value = [];
    debounceSaveGuides();
  }

  let _guideSaveTimer = null;
  function debounceSaveGuides() {
    if (!currentBoard.value) return;
    // Capture board ID NOW — if the user switches boards during the delay,
    // we must save to the ORIGINAL board, not the newly opened one.
    const boardId = currentBoard.value.id;
    const snapshot = JSON.stringify(guides.value);
    clearTimeout(_guideSaveTimer);
    _guideSaveTimer = setTimeout(async () => {
      try {
        await api.put(`/mood-boards/${boardId}`, {
          guides: snapshot,
        });
      } catch (e) {
        console.error('Failed to save guides:', e);
      }
    }, 800);
  }

  function restoreGuides(data) {
    if (!data) { guides.value = []; return; }
    try {
      guides.value = typeof data === 'string' ? JSON.parse(data) : (Array.isArray(data) ? data : []);
    } catch { guides.value = []; }
  }

  /**
   * Build the API base path for the current board.
   * In public view mode, routes through /mood-boards/share/{token} instead of /mood-boards/{id}.
   */
  function boardApiBase() {
    if (isPublicView.value && publicShareToken.value) {
      return `/mood-boards/share/${publicShareToken.value}`
    }
    return `/mood-boards/${currentBoard.value?.id}`
  }

  function cancelPendingTimers(itemIds) {
    for (const id of itemIds) {
      _itemGeneration[id] = (_itemGeneration[id] || 0) + 1
      if (_updateTimers[id]) {
        clearTimeout(_updateTimers[id])
        delete _updateTimers[id]
        delete _pendingUpdates[id]
        if (_pendingResolvers[id]) {
          _pendingResolvers[id](null)
          delete _pendingResolvers[id]
        }
      }
      // Also remove from the batch queue so a pending _flushBatchQueue
      // won't overwrite the undo with stale data
      delete _batchQueue[id]
    }
    // If the batch queue is now empty, cancel the pending flush entirely
    if (_batchTimer && Object.keys(_batchQueue).length === 0) {
      clearTimeout(_batchTimer)
      _batchTimer = null
    }
  }

  // Undo/redo history (extracted to moodBoardUndoService.js).
  // _locallyCreatedIds: real server IDs created locally, so the WebSocket
  // handler can skip duplicate echoes (shared with the items section below).
  const _locallyCreatedIds = new Set()
  const _undoService = createUndoRedoService({
    currentBoard, selectedItemIds, boardApiBase, cancelPendingTimers,
    locallyCreatedIds: _locallyCreatedIds,
  })
  const {
    undoStack, redoStack,
    pushUndo, clearHistory, undo, redo,
    persistUndoStack: _persistUndoStack,
    restorePersistedStacks: _restorePersistedStacks,
  } = _undoService

  // Folder state
  const folders = ref([]);
  const expandedFolderIds = ref(new Set());

  // Computed
  const activeBoards = computed(() => boards.value.filter((b) => !b.archived));
  const archivedBoards = computed(() => boards.value.filter((b) => b.archived));

  const folderTree = computed(() => {
    const map = {};
    const roots = [];
    const allFolders = folders.value || [];
    allFolders.forEach((f) => {
      map[f.id] = { ...f, children: [], boards: [] };
    });
    allFolders.forEach((f) => {
      const node = map[f.id];
      if (f.parent_id && map[f.parent_id]) {
        map[f.parent_id].children.push(node);
      } else {
        roots.push(node);
      }
    });
    activeBoards.value.forEach((b) => {
      if (b.folder_id && map[b.folder_id]) {
        map[b.folder_id].boards.push(b);
      }
    });
    return roots;
  });

  const unfiledBoards = computed(() =>
    activeBoards.value.filter(
      (b) => !b.folder_id || !folders.value.some((f) => f.id === b.folder_id)
    )
  );

  const currentItems = computed(() => currentBoard.value?.items || []);
  const currentConnections = computed(() => currentBoard.value?.connections || []);
  const currentMembers = computed(() => currentBoard.value?.members || []);
  const currentGroups = computed(() => currentBoard.value?.groups || []);
  const currentLinkedBoards = computed(() => currentBoard.value?.linked_boards || []);

  // O(1) item lookup Map — rebuilt whenever items array changes
  const itemMap = computed(() => {
    const map = new Map()
    for (const item of currentItems.value) {
      map.set(item.id, item)
    }
    return map
  })
  function getItemById(id) {
    return itemMap.value.get(id) ?? null
  }

  // O(1) children lookup — Map<parentId, sortedChildren[]>
  const childrenByParentId = computed(() => {
    const map = new Map()
    for (const item of currentItems.value) {
      if (item.parent_id == null) continue
      let bucket = map.get(item.parent_id)
      if (!bucket) { bucket = []; map.set(item.parent_id, bucket) }
      bucket.push(item)
    }
    for (const bucket of map.values()) {
      bucket.sort((a, b) => (a.z_index || 0) - (b.z_index || 0) || a.id - b.id)
    }
    return map
  })

  function getChildrenOf(parentId) {
    return childrenByParentId.value.get(parentId) || _emptyArr
  }
  const _emptyArr = Object.freeze([])

  const selectedItems = computed(() =>
    currentItems.value.filter((i) => selectedItemIds.value.has(i.id))
  );

  // Presentation slides: items with type === 'slide', sorted by slide_order
  const presentationSlides = computed(() =>
    currentItems.value
      .filter(i => i.type === 'slide')
      .sort((a, b) => (a.slide_order ?? 9999) - (b.slide_order ?? 9999))
  );

  // Backward-compat alias (used in many places, will remove gradually)
  const presentationFrames = presentationSlides;

  // ========================================
  // PRESENTATION ACTIONS
  // ========================================

  // Snapshot of state before presentation (so we can restore on exit)
  let _prePresState = null;

  function startPresentation(startIndex = 0) {
    if (presentationFrames.value.length === 0) return

    // Save current state so we can restore on exit
    _prePresState = {
      motionEnabled: motionEnabled.value,
      zoom: zoom.value,
      panX: panX.value,
      panY: panY.value,
    }
    motionEnabled.value = true

    presentationMode.value = true
    currentSlideIndex.value = Math.max(0, Math.min(startIndex, presentationFrames.value.length - 1))
  }

  function stopPresentation() {
    presentationMode.value = false
    currentSlideIndex.value = 0
    presShowLines.value = true
    presShowBackground.value = true

    // Restore pre-presentation state (zoom, pan, motion)
    if (_prePresState) {
      const savedMotion = _prePresState.motionEnabled
      zoom.value = _prePresState.zoom
      panX.value = _prePresState.panX
      panY.value = _prePresState.panY
      _prePresState = null // clear BEFORE restoring motionEnabled so the save watcher runs
      motionEnabled.value = savedMotion
    }
  }

  function nextSlide() {
    if (currentSlideIndex.value < presentationFrames.value.length - 1) {
      currentSlideIndex.value++
    }
  }

  function prevSlide() {
    if (currentSlideIndex.value > 0) {
      currentSlideIndex.value--
    }
  }

  function goToSlide(index) {
    if (index >= 0 && index < presentationFrames.value.length) {
      currentSlideIndex.value = index
    }
  }

  // ========================================
  // FOLLOW USER ACTIONS
  // ========================================

  function startFollowing(email, mode = 'cursor') {
    followingUser.value = email
    followMode.value = mode || 'cursor'
  }

  function stopFollowing() {
    followingUser.value = null
    followMode.value = 'cursor'
  }

  // Focus mode helpers
  function setFocusItem(itemId) {
    focusedItemId.value = itemId
  }

  function clearFocusItem() {
    focusedItemId.value = null
  }

  function toggleFocusItem(itemId) {
    focusedItemId.value = focusedItemId.value === itemId ? null : itemId
  }

  // ========================================
  // BOARD ACTIONS
  // ========================================

  async function fetchBoards(includeArchived = false) {
    loading.value = true;
    try {
      const params = {};
      if (includeArchived) params.include_archived = "true";
      const response = await api.get("/mood-boards", { params });
      if (response.data.success) {
        boards.value = response.data.data.boards;
      }
    } catch (e) {
      console.error("Failed to fetch mood boards:", e);
    } finally {
      loading.value = false;
    }
  }

  function _stripOrphanConnections(board) {
    if (!board?.connections?.length || !board?.items?.length) return
    const liveIds = new Set(board.items.map(i => i.id))
    const before = board.connections.length
    board.connections = board.connections.filter(
      c => liveIds.has(c.from_item_id) && liveIds.has(c.to_item_id)
    )
    const removed = before - board.connections.length
    if (removed > 0) console.warn(`[moodBoards] Stripped ${removed} orphan connection(s)`)
  }

  async function purgeOrphanConnections() {
    const boardId = currentBoard.value?.id
    if (!boardId) return 0
    try {
      const response = await api.post(`/mood-boards/${boardId}/connections/purge-orphans`)
      if (response.data.success) {
        _stripOrphanConnections(currentBoard.value)
        return response.data.removed || 0
      }
    } catch (e) {
      console.error('purgeOrphanConnections error:', e)
    }
    return 0
  }

  async function reloadBoardData(boardId) {
    try {
      const response = await api.get(`/mood-boards/${boardId}`)
      if (response.data.success) {
        const hadHistory = undoStack.value.length > 0
        currentBoard.value = response.data.data.board
        _stripOrphanConnections(currentBoard.value)
        clearHistory()
        _persistUndoStack()
        if (hadHistory) {
          // Losing undo history silently is confusing — tell the user why
          try {
            const { useToastStore } = await import('@/stores/toast')
            useToastStore().info('Board was updated by a collaborator — undo history was reset')
          } catch { /* toast is non-critical */ }
        }
      }
    } catch (e) {
      console.error('Failed to reload board data:', e)
    }
  }

  // Board-switch race guard: rapid navigation between boards must not let a
  // slower (stale) response overwrite the newer board's state.
  let _boardFetchController = null
  let _boardFetchGen = 0

  async function fetchBoard(id) {
    _boardFetchController?.abort()
    const controller = new AbortController()
    _boardFetchController = controller
    const gen = ++_boardFetchGen
    if (currentBoard.value && currentBoard.value.id !== parseInt(id)) {
      _clearComponentPushTimers()
    }
    boardLoading.value = true;
    try {
      const response = await api.get(`/mood-boards/${id}`, { signal: controller.signal });
      if (gen !== _boardFetchGen) return; // superseded by a newer fetch
      if (response.data.success) {
        currentBoard.value = response.data.data.board;
        _stripOrphanConnections(currentBoard.value);
        // Restore viewport
        zoom.value = parseFloat(currentBoard.value.zoom_level) || 1;
        panX.value = parseInt(currentBoard.value.viewport_x) || 0;
        panY.value = parseInt(currentBoard.value.viewport_y) || 0;

        // Restore undo/redo stacks from IndexedDB (or clear if different board / expired)
        try {
          const { loadUndoStack } = await import('../services/undoIndexedDB.js')
          const saved = await loadUndoStack(id)
          if (gen !== _boardFetchGen) return;
          if (saved) {
            _restorePersistedStacks(saved)
          } else {
            clearHistory()
          }
        } catch (_idbErr) {
          clearHistory()
        }

        // Restore motion/animation settings from saved board data
        if (currentBoard.value.motion_settings) {
          restoreMotionSettings(currentBoard.value.motion_settings);
        }
        
        // Restore guide lines
        restoreGuides(currentBoard.value.guides);
        
        // Subscribe to real-time collaboration events
        subscribeToBoardEvents(id);
        
        // Hydrate global styles store from board data (non-blocking)
        try {
          const { useMoodBoardGlobalStylesStore } = await import('./moodBoardGlobalStyles')
          const gsStore = useMoodBoardGlobalStylesStore()
          gsStore.hydrateFromBoard()
        } catch (_gsErr) { /* global styles store not critical for board load */ }
        
        // Background: auto-generate thumbnails for boards that have images without them.
        // This is a one-time catch-up for boards created before thumbnail support was added.
        // Runs silently — doesn't block the UI or show errors.
        autoGenerateThumbnails(id);

        healBrokenItemDimensions();

        purgeOrphanConnections().catch(() => {});

        return currentBoard.value;
      }
    } catch (e) {
      if (e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED' || e?.name === 'AbortError') return;
      console.error("Failed to fetch mood board:", e);
    } finally {
      if (gen === _boardFetchGen) boardLoading.value = false;
    }
    return null;
  }

  async function createBoard(data) {
    try {
      const response = await api.post("/mood-boards", data);
      if (response.data.success) {
        const board = response.data.data.board;
        boards.value.unshift(board);
        return board;
      }
    } catch (e) {
      console.error("Failed to create mood board:", e);
    }
    return null;
  }

  async function updateBoard(id, data) {
    try {
      const response = await api.put(`/mood-boards/${id}`, data);
      if (response.data.success) {
        const updated = response.data.data.board;
        const idx = boards.value.findIndex((b) => b.id === id);
        if (idx !== -1) boards.value[idx] = { ...boards.value[idx], ...updated };
        if (currentBoard.value?.id === id) {
          currentBoard.value = { ...currentBoard.value, ...updated };
        }
        return updated;
      }
    } catch (e) {
      console.error("Failed to update mood board:", e);
    }
    return null;
  }

  async function deleteBoard(id) {
    try {
      const response = await api.delete(`/mood-boards/${id}`);
      if (response.data.success) {
        boards.value = boards.value.filter((b) => b.id !== id);
        if (currentBoard.value?.id === id) currentBoard.value = null;
        return true;
      }
    } catch (e) {
      console.error("Failed to delete mood board:", e);
    }
    return false;
  }

  async function duplicateBoard(id, name = null) {
    try {
      const response = await api.post(`/mood-boards/${id}/duplicate`, { name });
      if (response.data.success) {
        const board = response.data.data.board;
        boards.value.unshift(board);
        return board;
      }
    } catch (e) {
      console.error("Failed to duplicate mood board:", e);
    }
    return null;
  }

  /**
   * Toggle the "ready" state of a mood board.
   * Returns the updated board or null on failure.
   */
  async function toggleReady(boardId) {
    try {
      const response = await api.post(`/mood-boards/${boardId}/ready`);
      if (response.data.success) {
        const updatedBoard = response.data.data.board;
        // Update in boards list
        const idx = boards.value.findIndex(b => b.id === boardId);
        if (idx !== -1) {
          boards.value[idx] = { ...boards.value[idx], ...updatedBoard };
        }
        // Update currentBoard if it's the one we toggled
        if (currentBoard.value?.id === boardId) {
          currentBoard.value = { ...currentBoard.value, ...updatedBoard };
        }
        return updatedBoard;
      }
    } catch (e) {
      console.error('Failed to toggle ready state:', e);
    }
    return null;
  }

  // ========================================
  // FOLDER ACTIONS
  // ========================================

  async function fetchFolders() {
    try {
      const response = await api.get("/mood-boards/folders");
      if (response.data.success) {
        folders.value = response.data.data.folders;
      }
    } catch (e) {
      console.error("Failed to fetch folders:", e);
    }
  }

  async function createFolder(data) {
    try {
      const response = await api.post("/mood-boards/folders", data);
      if (response.data.success) {
        const folder = response.data.data.folder;
        folders.value.push(folder);
        expandedFolderIds.value.add(folder.id);
        return folder;
      }
    } catch (e) {
      console.error("Failed to create folder:", e);
    }
    return null;
  }

  async function updateFolder(folderId, data) {
    try {
      const response = await api.put(`/mood-boards/folders/${folderId}`, data);
      if (response.data.success) {
        const updated = response.data.data.folder;
        const idx = folders.value.findIndex((f) => f.id === folderId);
        if (idx !== -1) folders.value[idx] = { ...folders.value[idx], ...updated };
        return updated;
      }
    } catch (e) {
      console.error("Failed to update folder:", e);
    }
    return null;
  }

  async function deleteFolder(folderId) {
    try {
      const response = await api.delete(`/mood-boards/folders/${folderId}`);
      if (response.data.success) {
        folders.value = folders.value.filter((f) => f.id !== folderId);
        expandedFolderIds.value.delete(folderId);
        await fetchBoards();
        return true;
      }
    } catch (e) {
      console.error("Failed to delete folder:", e);
    }
    return false;
  }

  async function moveBoard(boardId, folderId) {
    try {
      const response = await api.put(`/mood-boards/${boardId}/move`, {
        folder_id: folderId,
      });
      if (response.data.success) {
        const idx = boards.value.findIndex((b) => b.id === boardId);
        if (idx !== -1) boards.value[idx] = { ...boards.value[idx], folder_id: folderId };
        if (folderId) expandedFolderIds.value.add(folderId);
        return true;
      }
    } catch (e) {
      console.error("Failed to move board:", e);
    }
    return false;
  }

  function toggleFolder(folderId) {
    if (expandedFolderIds.value.has(folderId)) {
      expandedFolderIds.value.delete(folderId);
    } else {
      expandedFolderIds.value.add(folderId);
    }
  }

  // ========================================
  // TEXT CSV EXPORT / IMPORT
  // ========================================

  async function exportTexts(boardId) {
    try {
      const response = await api.get(`/mood-boards/${boardId}/export-texts`, {
        responseType: "blob",
      });
      const blob = new Blob([response.data], { type: "text/csv;charset=utf-8;" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      const board = boards.value.find((b) => b.id === boardId);
      const safeName = (board?.name || "board").replace(/[^a-zA-Z0-9_-]/g, "_");
      link.download = `${safeName}_texts.csv`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
      return true;
    } catch (e) {
      console.error("Failed to export texts:", e);
    }
    return false;
  }

  async function exportPresentation(boardId) {
    try {
      const response = await api.get(
        `/mood-boards/${boardId}/export-presentation`,
        { responseType: "blob" },
      );
      const blob = new Blob([response.data], { type: "text/html" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      const board = boards.value.find((b) => b.id === boardId);
      const safeName = (board?.name || "presentation").replace(
        /[^a-zA-Z0-9_-]/g,
        "_",
      );
      link.download = `${safeName}_presentation.html`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
      return true;
    } catch (e) {
      console.error("Failed to export presentation:", e);
      if (e.response?.data) {
        try {
          const text = await e.response.data.text();
          const json = JSON.parse(text);
          if (json.message) throw new Error(json.message);
        } catch (_) { /* ignore parse errors */ }
      }
    }
    return false;
  }

  async function exportPptx(boardId, selectedSlideIds = null) {
    try {
      const board = currentBoard.value?.id === boardId
        ? currentBoard.value
        : boards.value.find((b) => b.id === boardId);
      if (!board) return false;

      await exportMoodBoardPptx(board, selectedSlideIds);
      return true;
    } catch (e) {
      console.error('Failed to export PPTX:', e);
    }
    return false;
  }

  async function exportPdf(boardId) {
    try {
      const board = currentBoard.value?.id === boardId
        ? currentBoard.value
        : boards.value.find((b) => b.id === boardId);
      if (!board) return false;

      await exportMoodBoardPdf(board, {
        globalColors: parseExportJson(board.design_tokens),
        globalGradients: parseExportJson(board.gradient_palette),
        globalTextStyles: parseExportJson(board.global_text_styles),
        globalCssClasses: parseExportJson(board.global_css_classes),
        boardName: board.name || "Moodboard",
      });
      return true;
    } catch (e) {
      console.error("Failed to export PDF:", e);
    }
    return false;
  }

  function parseExportJson(value) {
    if (!value) return [];
    if (Array.isArray(value)) return value;
    if (typeof value === "string") {
      try {
        const parsed = JSON.parse(value);
        return Array.isArray(parsed) ? parsed : [];
      } catch {
        return [];
      }
    }
    return [];
  }

  async function importTexts(boardId, file) {
    try {
      const text = await file.text();
      const response = await api.post(`/mood-boards/${boardId}/import-texts`, {
        csv: text,
      });
      if (response.data.success) {
        if (currentBoard.value?.id === boardId) {
          await fetchBoard(boardId);
        }
        return response.data.data;
      }
    } catch (e) {
      console.error("Failed to import texts:", e);
      if (e.response?.data?.message) {
        throw new Error(e.response.data.message);
      }
    }
    return null;
  }

  function healBrokenItemDimensions() {
    if (!currentBoard.value?.items?.length) return
    const IMAGE_TYPES = new Set(['image', 'image_set'])
    const MIN_DIM = 40
    const DEFAULT_ASPECT = 3 / 4
    const fixes = []

    for (const item of currentBoard.value.items) {
      const w = Number(item.width) || 0
      const h = Number(item.height) || 0
      if (w > 0 && h > 0) continue

      const sd = item.style_data || {}
      const intrW = sd.original_width || 0
      const intrH = sd.original_height || 0
      let fixedW = w, fixedH = h

      if (IMAGE_TYPES.has(item.type)) {
        if (!fixedW && !fixedH) {
          fixedW = 300
          fixedH = intrW > 0 && intrH > 0
            ? Math.max(MIN_DIM, Math.round(300 * (intrH / intrW)))
            : Math.round(300 * DEFAULT_ASPECT)
        } else if (fixedW > 0 && !fixedH) {
          fixedH = intrW > 0 && intrH > 0
            ? Math.max(MIN_DIM, Math.round(fixedW * (intrH / intrW)))
            : Math.max(MIN_DIM, Math.round(fixedW * DEFAULT_ASPECT))
        } else if (fixedH > 0 && !fixedW) {
          fixedW = intrW > 0 && intrH > 0
            ? Math.max(MIN_DIM, Math.round(fixedH * (intrW / intrH)))
            : Math.max(MIN_DIM, Math.round(fixedH / DEFAULT_ASPECT))
        }
      } else {
        if (!fixedW) fixedW = 200
        if (!fixedH) fixedH = 200
      }

      if (fixedW !== w || fixedH !== h) {
        item.width = fixedW
        item.height = fixedH
        fixes.push({ id: item.id, width: fixedW, height: fixedH })
      }
    }

    if (fixes.length) {
      batchUpdateItems(fixes, { skipUndo: true }).catch(() => {})
    }
  }

  // Auto-generate thumbnails for boards that were created before thumbnail support.
  // Checks if any image items are missing thumbnail_url, and triggers batch generation.
  const _thumbGenDone = new Set() // track boards we've already checked
  async function autoGenerateThumbnails(boardId) {
    if (_thumbGenDone.has(boardId) || isPublicView.value) return
    _thumbGenDone.add(boardId)
    
    // Check if any image items lack thumbnail_url
    const items = currentBoard.value?.items || []
    const needsThumbs = items.some(i =>
      i.type === 'image' && i.image_url && !i.thumbnail_url
    )
    if (!needsThumbs) return
    
    try {
      const res = await api.post(`/mood-boards/${boardId}/generate-thumbnails`)
      if (res.data?.success && res.data.data?.generated > 0) {
        // Reload the board to get updated thumbnail_url values
        const refreshRes = await api.get(`/mood-boards/${boardId}`)
        if (refreshRes.data?.success) {
          const updatedItems = refreshRes.data.data.board.items || []
          // Merge thumbnail_url into existing items (preserving reactive references)
          for (const updated of updatedItems) {
            const existing = items.find(i => i.id === updated.id)
            if (existing && updated.thumbnail_url && !existing.thumbnail_url) {
              existing.thumbnail_url = updated.thumbnail_url
            }
          }
        }
      }
    } catch (e) {
      // Silent — thumbnail generation is non-critical
    }
  }

  // Save viewport state (debounced from the view)
  async function saveViewport() {
    if (!currentBoard.value || isPublicView.value) return;
    try {
      await api.put(`/mood-boards/${currentBoard.value.id}`, {
        zoom_level: zoom.value,
        viewport_x: Math.round(panX.value),
        viewport_y: Math.round(panY.value),
      });
    } catch (e) {
      // Silent - viewport save is non-critical
    }
  }

  // ========================================
  // ITEM ACTIONS
  // ========================================

  // Temporary ID counter for optimistic adds (negative to avoid collision with server IDs)
  let _tempIdCounter = -1
  let _pendingAddCount = 0

  // Temp→server ID remap so in-flight DragManager updates survive the swap
  const _tempToServerId = new Map()
  function _resolveItemId(id) { return _tempToServerId.get(id) ?? id }

  // Tracks temp items that were locally edited (color, content, etc.)
  // before addItem reconciliation completed. Used to preserve those edits.
  const _tempModifiedItems = new Set()

  async function addItem(data, { skipUndo = false } = {}) {
    if (!currentBoard.value) return null;
    if (!currentBoard.value.items) currentBoard.value.items = [];

    // Optimistic: show item instantly with a temporary negative ID
    const tempId = _tempIdCounter--

    let zIndex = data.z_index
    if (zIndex == null) {
      const scope = _layerOrder.layerScope({ parent_id: data.parent_id || null, type: data.type })
      zIndex = _layerOrder.nextZIndexInScope(currentBoard.value.items, scope)
    }

    const tempItem = {
      ...data,
      id: tempId,
      board_id: currentBoard.value.id,
      z_index: zIndex,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    }
    currentBoard.value.items.push(tempItem)

    _pendingAddCount++
    try {
      const response = await api.post(
        `${boardApiBase()}/items`,
        data
      );
      if (response.data.success) {
        const serverItem = response.data.data.item;
        // Register this ID so the WS handler skips the duplicate broadcast
        _locallyCreatedIds.add(serverItem.id)
        setTimeout(() => _locallyCreatedIds.delete(serverItem.id), 15000)

        // If the WS handler already pushed a copy before the API returned,
        // remove that duplicate before we reconcile the temp item.
        const dupeIdx = currentBoard.value.items.findIndex(i => i.id === serverItem.id && i.id !== tempId)
        if (dupeIdx !== -1) currentBoard.value.items.splice(dupeIdx, 1)

        const idx = currentBoard.value.items.findIndex(i => i.id === tempId)
        if (idx !== -1) {
          const existing = currentBoard.value.items[idx]
          const localPos = { pos_x: existing.pos_x, pos_y: existing.pos_y }

          // Snapshot locally edited properties before overwriting with server data.
          // If the user modified style/color/content while the addItem API was in-flight,
          // we must preserve those edits (the server only has the original payload).
          const wasModified = _tempModifiedItems.has(tempId)
          let localEdits = null
          if (wasModified) {
            localEdits = {}
            for (const key of ['style_data', 'color', 'color_data', 'content', 'title', 'locked']) {
              localEdits[key] = existing[key]
            }
            _tempModifiedItems.delete(tempId)
          }

          Object.assign(existing, serverItem)

          // Restore local edits and push them to the server under the real ID
          if (localEdits) {
            const serverUpdate = {}
            for (const key of Object.keys(localEdits)) {
              if (localEdits[key] === undefined) continue
              const localVal = typeof localEdits[key] === 'object' ? JSON.stringify(localEdits[key]) : localEdits[key]
              const serverVal = typeof serverItem[key] === 'object' ? JSON.stringify(serverItem[key]) : serverItem[key]
              if (localVal !== serverVal) {
                existing[key] = localEdits[key]
                serverUpdate[key] = localEdits[key]
              }
            }
            if (Object.keys(serverUpdate).length) {
              setTimeout(() => updateItem(serverItem.id, serverUpdate, { skipUndo: true }), 0)
            }
          }

          if (isDragging.value) {
            existing.pos_x = localPos.pos_x
            existing.pos_y = localPos.pos_y
          } else if (localPos.pos_x !== serverItem.pos_x || localPos.pos_y !== serverItem.pos_y) {
            existing.pos_x = localPos.pos_x
            existing.pos_y = localPos.pos_y
            setTimeout(() => updateItem(serverItem.id, { pos_x: localPos.pos_x, pos_y: localPos.pos_y }, { skipUndo: true }), 0)
          }
        }
        _tempToServerId.set(tempId, serverItem.id)
        setTimeout(() => _tempToServerId.delete(tempId), 60000)
        if (selectedItemIds.value.has(tempId)) {
          const next = new Set(selectedItemIds.value)
          next.delete(tempId)
          next.add(serverItem.id)
          selectedItemIds.value = next
        }
        if (!skipUndo) pushUndo({ type: 'add', itemId: serverItem.id, item: { ...serverItem } })
        return serverItem;
      } else {
        currentBoard.value.items = currentBoard.value.items.filter(i => i.id !== tempId)
      }
    } catch (e) {
      console.error("Failed to add item:", e);
      currentBoard.value.items = currentBoard.value.items.filter(i => i.id !== tempId)
    } finally {
      _pendingAddCount--
    }
    return null;
  }

  // Debounce timers per item to prevent rapid-click race conditions
  // (e.g. clicking A+/A- fast would cause server responses to overwrite optimistic updates)
  const _updateTimers = {}
  const _pendingUpdates = {}
  const _pendingResolvers = {} // resolve functions for orphaned promises
  const _itemGeneration = {} // per-item counter to prevent stale API responses from overwriting undo

  // Tracks items the local user recently modified. WebSocket echo events
  // for these items are suppressed during the cooldown window to prevent
  // flickering (the server echoes our own changes back to us).
  const _localEditTimestamps = {}
  // 2s (was 6s): long cooldowns made genuine remote updates to recently
  // touched items invisible for too long; debounced saves settle well within 2s.
  const WS_ECHO_COOLDOWN_MS = 2000
  function _markLocalEdit(itemId) {
    _localEditTimestamps[itemId] = Date.now()
  }
  function _isLocallyEdited(itemId) {
    const ts = _localEditTimestamps[itemId]
    if (!ts) return false
    if (Date.now() - ts < WS_ECHO_COOLDOWN_MS) return true
    delete _localEditTimestamps[itemId]
    return false
  }

  // ── Component instance: local propagation + debounced API push ──

  const _compPushTimers = {}

  /** Cancel pending component pushes (board close/switch) so a late timer
   *  can't push an old board's item against the newly opened board. */
  function _clearComponentPushTimers() {
    for (const id of Object.keys(_compPushTimers)) {
      clearTimeout(_compPushTimers[id])
      delete _compPushTimers[id]
    }
  }

  function _propagateToSiblingInstances(sourceItem, data) {
    if (!currentBoard.value) return
    const compId = sourceItem.component_id
    const srcInstId = sourceItem.component_instance_id
    const srcIdx = sourceItem.component_item_index
    if (compId == null || srcInstId == null || srcIdx == null) return

    const posFields = new Set(['pos_x', 'pos_y', 'z_index', 'rotation', 'locked', 'parent_id', 'slide_order'])
    const styleKeys = Object.keys(data).filter(k => !posFields.has(k))
    if (!styleKeys.length) return

    for (const item of currentBoard.value.items) {
      if (item.component_id !== compId) continue
      if (item.component_instance_id === srcInstId) continue
      if (item.component_item_index !== srcIdx) continue

      const overrides = item.style_data?._overrides || []
      for (const key of styleKeys) {
        if (overrides.includes(key)) continue
        if (key === 'style_data' && typeof data.style_data === 'object') {
          const merged = { ...(item.style_data || {}) }
          for (const [sk, sv] of Object.entries(data.style_data)) {
            if (sk === '_overrides') continue
            if (!overrides.includes(sk)) merged[sk] = sv
          }
          item.style_data = merged
        } else {
          item[key] = data[key]
        }
      }
      _markLocalEdit(item.id)
    }
  }

  function _scheduleComponentPush(componentId, sourceItem) {
    if (_compPushTimers[componentId]) clearTimeout(_compPushTimers[componentId])
    _compPushTimers[componentId] = setTimeout(async () => {
      delete _compPushTimers[componentId]
      try {
        await api.post(`/mood-boards/components/${componentId}/push-from-item`, {
          item_id: sourceItem.id,
          board_id: currentBoard.value?.id,
        })
      } catch (e) {
        console.error('Component auto-push failed:', e)
      }
    }, 500)
  }

  /**
   * Flush any pending debounced updateItem calls for the given item IDs.
   * Called before drag starts so that in-flight API responses don't race with
   * the drag's position updates. Fires the debounced API call immediately.
   */
  function flushPendingUpdates(itemIds) {
    for (const id of itemIds) {
      if (_updateTimers[id]) {
        clearTimeout(_updateTimers[id])
        delete _updateTimers[id]
        const mergedData = _pendingUpdates[id]
        delete _pendingUpdates[id]
        if (mergedData && currentBoard.value) {
          api.put(`${boardApiBase()}/items/${id}`, mergedData).catch(() => {})
        }
      }
    }
    // Also flush any pending batch queue entries for these items
    const idArr = Array.isArray(itemIds) ? itemIds : [...itemIds]
    if (_batchTimer && idArr.some(id => _batchQueue[id])) {
      _flushBatchQueue()
    }
  }

  async function updateItem(itemId, data, { skipUndo = false, fromPush = false } = {}) {
    if (!currentBoard.value) return null;

    // Always apply optimistic update immediately (no lag for user)
    const idx = currentBoard.value.items.findIndex((i) => i.id === itemId);
    // Record previous state for undo (only if not a drag/position-only update)
    if (idx !== -1 && !skipUndo) {
      const prev = currentBoard.value.items[idx]
      const previousData = {}
      for (const key of Object.keys(data)) {
        previousData[key] = prev[key]
      }
      pushUndo({ type: 'update', itemId, previousData, newData: { ...data } })
    }

    // Component instance: auto-override content edits, propagate style edits
    const _posOnlyFields = new Set(['pos_x', 'pos_y', 'z_index', 'rotation', 'locked', 'parent_id', 'slide_order'])
    if (!fromPush && idx !== -1) {
      const item = currentBoard.value.items[idx]
      if (item.component_id && item.component_instance_id != null) {
        // Auto-add content/title to _overrides when edited (each instance keeps unique text)
        if ('content' in data || 'title' in data) {
          const sd = item.style_data ? { ...item.style_data } : {}
          const ov = new Set(sd._overrides || [])
          if ('content' in data) ov.add('content')
          if ('title' in data) ov.add('title')
          sd._overrides = [...ov]
          item.style_data = sd
          if (!data.style_data) data.style_data = sd
          else data.style_data = { ...data.style_data, _overrides: sd._overrides }
        }

        const isStyleEdit = Object.keys(data).some(k => !_posOnlyFields.has(k))
        if (isStyleEdit) {
          _propagateToSiblingInstances(item, data)
          _scheduleComponentPush(item.component_id, item)
        }
      }
    }

    if (idx !== -1) {
      const item = currentBoard.value.items[idx]
      for (const key of Object.keys(data)) {
        item[key] = data[key]
      }
      _markLocalEdit(itemId)
    }

    // Skip API call for temporary (negative) IDs — item hasn't been created on server yet.
    // The addItem() function will replace the temp item with the real server item once the
    // POST completes, so any local changes will be reconciled then.
    if (typeof itemId === 'number' && itemId < 0) {
      _tempModifiedItems.add(itemId)
      return null
    }

    // Merge with any pending update for this item
    _pendingUpdates[itemId] = { ...(_pendingUpdates[itemId] || {}), ...data }

    // Clear existing timer for this item and resolve the orphaned promise
    if (_updateTimers[itemId]) {
      clearTimeout(_updateTimers[itemId])
      // Resolve the previous caller's promise with null so it doesn't hang forever
      if (_pendingResolvers[itemId]) {
        _pendingResolvers[itemId](null)
        delete _pendingResolvers[itemId]
      }
    }

    // Debounce the API call (300ms) — only the final merged payload is sent
    return new Promise((resolve) => {
      _pendingResolvers[itemId] = resolve
      const genAtSchedule = _itemGeneration[itemId] || 0
      _updateTimers[itemId] = setTimeout(async () => {
        const mergedData = _pendingUpdates[itemId]
        delete _pendingUpdates[itemId]
        delete _updateTimers[itemId]
        delete _pendingResolvers[itemId]

        try {
          const response = await api.put(
            `${boardApiBase()}/items/${itemId}`,
            mergedData
          );
          if (response.data.success) {
            const updated = response.data.data.item;
            const genNow = _itemGeneration[itemId] || 0
            if (!_pendingUpdates[itemId] && updated && genNow === genAtSchedule) {
              const freshIdx = currentBoard.value.items.findIndex((i) => i.id === itemId);
              if (freshIdx !== -1) {
                const item = currentBoard.value.items[freshIdx]
                const posKeys = new Set(['pos_x', 'pos_y', 'width', 'height'])
                const skipPos = isDragging.value || _isLocallyEdited(itemId) || _batchQueue[itemId]
                for (const key of Object.keys(mergedData)) {
                  if (skipPos && posKeys.has(key)) continue
                  if (updated[key] !== undefined) {
                    item[key] = updated[key]
                  }
                }
                if (updated.updated_at) item.updated_at = updated.updated_at
              }
            }
            resolve(updated);
          } else {
            resolve(null);
          }
        } catch (e) {
          // If 404 and we sent a DELETE-like update, the item was removed server-side
          if (e?.response?.status === 404) {
            // Only remove locally if the item is truly gone — verify before removing
            console.warn(`updateItem got 404 for item ${itemId}, keeping local state`);
          } else {
            console.error("Failed to update item:", e);
          }
          // Don't remove items or re-fetch the entire board on transient errors —
          // the optimistic update is still valid and the item exists on the server
          resolve(null);
        }
      }, 300)
    })
  }

  let _batchTimer = null
  const _batchQueue = {}   // itemId -> merged field updates

  function _flushBatchQueue() {
    if (_batchTimer) { clearTimeout(_batchTimer); _batchTimer = null }
    const queued = { ..._batchQueue }
    for (const id in _batchQueue) delete _batchQueue[id]
    const payload = Object.keys(queued).map(id => ({ id: Number(id), ...queued[id] }))
    if (!payload.length || !currentBoard.value) return Promise.resolve()
    return api.put(`${boardApiBase()}/items/batch`, { updates: payload }).catch(e => {
      console.error('Failed to batch update items:', e)
    })
  }

  function batchUpdateItems(updates, { skipUndo = false } = {}) {
    if (!currentBoard.value) return false

    const map = itemMap.value

    // Resolve temp IDs that were swapped to server IDs by addItem
    const resolved = updates.map(u => {
      const rid = _resolveItemId(u.id)
      return rid !== u.id ? { ...u, id: rid } : u
    })

    // Capture previous state for undo before applying optimistic update
    const previousStates = []
    if (!skipUndo) {
      for (const update of resolved) {
        const item = map.get(update.id)
        if (item) {
          const prev = {}
          for (const key of Object.keys(update)) {
            if (key === 'id') continue
            prev[key] = item[key]
          }
          previousStates.push({ id: update.id, ...prev })
        }
      }
    }

    // Optimistic update — mutate in-place to preserve object references
    for (const update of resolved) {
      const item = map.get(update.id)
      if (item) {
        for (const key of Object.keys(update)) {
          if (key === 'id') continue
          item[key] = update[key]
        }
        _markLocalEdit(update.id)
        if (typeof update.id === 'number' && update.id < 0) {
          _tempModifiedItems.add(update.id)
        }
      }
    }

    // Record undo
    if (!skipUndo && previousStates.length) {
      pushUndo({ type: 'batch-update', previousUpdates: previousStates, newUpdates: resolved.map(u => ({ ...u })) })
    }

    // Merge into pending queue — skip temp IDs (server doesn't know them;
    // changes will be pushed after addItem reconciliation instead)
    for (const update of resolved) {
      if (typeof update.id === 'number' && update.id < 0) continue
      const existing = _batchQueue[update.id] || {}
      for (const key of Object.keys(update)) {
        if (key === 'id') continue
        existing[key] = update[key]
      }
      _batchQueue[update.id] = existing
    }

    // Debounce the actual API call — 300ms window merges rapid-fire updates
    clearTimeout(_batchTimer)
    _batchTimer = setTimeout(_flushBatchQueue, 300)

    return true
  }

  async function deleteItem(itemId) {
    if (!currentBoard.value) return false;
    // Capture item before deleting for undo
    const deletedItem = currentBoard.value.items.find(i => i.id === itemId)
    try {
      const response = await api.delete(
        `${boardApiBase()}/items/${itemId}`
      );
      if (response.data.success) {
        currentBoard.value.items = currentBoard.value.items.filter(
          (i) => i.id !== itemId
        );
        // Also remove any connections involving this item
        currentBoard.value.connections = (
          currentBoard.value.connections || []
        ).filter(
          (c) => c.from_item_id !== itemId && c.to_item_id !== itemId
        );
        const nextSel = new Set(selectedItemIds.value)
        nextSel.delete(itemId)
        selectedItemIds.value = nextSel
        if (deletedItem) pushUndo({ type: 'delete', item: { ...deletedItem } })
        return true;
      }
    } catch (e) {
      console.error("Failed to delete item:", e);
    }
    return false;
  }

  async function deleteSelectedItems() {
    let ids = [...selectedItemIds.value];
    if (!ids.length || !currentBoard.value) return

    const originalCount = ids.length
    ids = ids.filter(id => {
      const item = currentBoard.value.items.find(i => i.id === id)
      if (!item) return false
      const v = item.locked
      return !(v === true || v === 1 || v === '1')
    })
    if (!ids.length) {
      if (originalCount > 0) console.warn('[MoodBoard] Delete blocked: all selected items are locked')
      return
    }

    // Cascade: if deleting group containers, also delete their children recursively
    const allIds = new Set(ids)
    let changed = true
    while (changed) {
      changed = false
      for (const item of currentBoard.value.items) {
        if (!allIds.has(item.id) && item.parent_id && allIds.has(item.parent_id)) {
          allIds.add(item.id)
          changed = true
        }
      }
    }
    ids = [...allIds]

    // Capture all items before deleting (for single multi-delete undo)
    const deletedItems = ids
      .map(id => currentBoard.value.items.find(i => i.id === id))
      .filter(Boolean)
      .map(item => ({ ...item }))

    // Optimistically remove from canvas immediately
    const idSet = new Set(ids)
    currentBoard.value.items = currentBoard.value.items.filter(i => !idSet.has(i.id))
    currentBoard.value.connections = (currentBoard.value.connections || []).filter(
      c => !idSet.has(c.from_item_id) && !idSet.has(c.to_item_id)
    )
    selectedItemIds.value.clear()

    // Single undo entry for the whole batch
    if (deletedItems.length) {
      pushUndo({ type: 'multi-delete', items: deletedItems })
    }

    // Fire batch delete to server in background
    try {
      const resp = await api.post(`${boardApiBase()}/items/batch-delete`, { item_ids: ids })
      if (!resp.data?.success) {
        console.warn('[MoodBoard] Batch delete returned success=false:', resp.data)
      }
    } catch (e) {
      console.error('Batch delete failed:', e?.response?.status, e?.response?.data || e.message)
      // Only restore on genuine server errors (5xx) — 4xx means the items
      // are already gone or inaccessible, so the optimistic removal is correct.
      const status = e?.response?.status
      if (status >= 500 && currentBoard.value) {
        currentBoard.value.items.push(...deletedItems)
      }
    }
  }

  /**
   * Batch-add multiple items in a single API call.
   * Items appear optimistically on the canvas and are reconciled once the server responds.
   */
  async function batchAddItems(itemsData) {
    if (!currentBoard.value || !itemsData?.length) return []

    const boardId = currentBoard.value.id

    // Assign temp IDs and collect all items, then append in ONE reactive write
    const newItems = []
    const tempItems = itemsData.map(data => {
      const tempId = --_tempIdCounter
      const item = {
        id: tempId,
        board_id: boardId,
        type: data.type || 'text',
        pos_x: data.pos_x || 0,
        pos_y: data.pos_y || 0,
        width: data.width || null,
        height: data.height || null,
        rotation: data.rotation || 0,
        z_index: data.z_index || 0,
        title: data.title || null,
        content: data.content || null,
        color: data.color || null,
        color_data: data.color_data || null,
        url: data.url || null,
        image_url: data.image_url || null,
        thumbnail_url: data.thumbnail_url || null,
        style_data: data.style_data || {},
        locked: 0,
        component_id: data.component_id || null,
        component_instance_id: data.component_instance_id || null,
        component_item_index: data.component_item_index ?? null,
        _tempId: tempId,
      }
      newItems.push(item)
      return { tempId, payload: data }
    })
    // Single reactive write instead of N individual .push() calls
    currentBoard.value.items = [...currentBoard.value.items, ...newItems]

    // Fire batch-add to server
    _pendingAddCount++
    try {
      const response = await api.post(`${boardApiBase()}/items/batch-add`, {
        items: itemsData,
      })
      if (response.data.success && response.data.data?.items) {
        const serverItems = response.data.data.items
        const realIds = []

        // Build temp lookup for O(1) reconciliation instead of O(n) per item
        const tempLookup = new Map()
        for (const it of currentBoard.value.items) {
          if (it._tempId != null) tempLookup.set(it._tempId, it)
        }

        const serverIdSet = new Set()
        // Track reconciled item references so we can distinguish them from WS-pushed dupes
        const reconciledItems = new Set()

        for (let i = 0; i < serverItems.length; i++) {
          const serverItem = serverItems[i]
          const tempId = tempItems[i]?.tempId

          _locallyCreatedIds.add(serverItem.id)
          setTimeout(() => _locallyCreatedIds.delete(serverItem.id), 15000)

          serverIdSet.add(serverItem.id)

          const existing = tempLookup.get(tempId)
          if (existing) {
            if (selectedItemIds.value.has(tempId)) {
              const next = new Set(selectedItemIds.value)
              next.delete(tempId)
              next.add(serverItem.id)
              selectedItemIds.value = next
            }
            Object.assign(existing, serverItem)
            delete existing._tempId
            reconciledItems.add(existing)
          }
          realIds.push(serverItem.id)
        }

        // Remove WS-pushed duplicates in one pass.
        // Keep reconciled temp items (now bearing server IDs) and unreconciled temps.
        // Remove any OTHER item whose id matches a server-returned id (WS duplicate).
        const beforeLen = currentBoard.value.items.length
        const cleaned = currentBoard.value.items.filter(it => {
          if (it._tempId != null) return true
          if (serverIdSet.has(it.id) && !reconciledItems.has(it)) return false
          return true
        })
        if (cleaned.length !== beforeLen) {
          currentBoard.value.items = cleaned
        }

        return realIds
      }
    } catch (e) {
      console.error('Batch add failed:', e)
      const tempIdSet = new Set(tempItems.map(t => t.tempId))
      currentBoard.value.items = currentBoard.value.items.filter(i => !tempIdSet.has(i.id))
    } finally {
      _pendingAddCount--
    }
    return []
  }

  // ========================================
  // FILE UPLOAD ACTIONS
  // ========================================

  async function uploadFiles(files) {
    if (!currentBoard.value) return [];
    const formData = new FormData();
    for (const file of files) {
      formData.append("files[]", file);
    }
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/upload`,
        formData,
        { headers: { "Content-Type": "multipart/form-data" } }
      );
      if (response.data.success) {
        return response.data.data.uploads;
      }
    } catch (e) {
      console.error("Failed to upload files:", e);
    }
    return [];
  }

  /**
   * Import a Drive file into mood board uploads (backend copies the file).
   * Returns upload info with a publicly accessible URL.
   */
  async function importDriveFile(driveFileId) {
    if (!currentBoard.value) return null;
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/import-drive-file`,
        { drive_file_id: driveFileId }
      );
      if (response.data.success) {
        return response.data.data.upload;
      }
    } catch (e) {
      console.error("Failed to import drive file:", e);
    }
    return null;
  }

  // ========================================
  // IMAGE SET ACTIONS
  // ========================================

  async function addImageToSet(itemId, imageData) {
    if (!currentBoard.value) return null;
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/items/${itemId}/images`,
        imageData
      );
      if (response.data.success) {
        const image = response.data.data.image;
        // Update local state — backend uses "images" property
        const item = currentBoard.value.items.find((i) => i.id === itemId);
        if (item) {
          if (!item.images) item.images = [];
          item.images.push(image);
          // Also sync legacy property if it exists
          if (item.image_set_items) {
            item.image_set_items.push(image);
          }
        }
        return image;
      }
    } catch (e) {
      console.error("Failed to add image to set:", e);
    }
    return null;
  }

  /**
   * Batched add-images-to-image_set. ONE HTTP call regardless of how
   * many files were dropped/selected. Mirrors the local-state updates
   * addImageToSet does for a single image, but for the whole batch.
   * @param {number} itemId
   * @param {Array<object>} imagesData
   * @returns {Promise<{success:number, failed:number, images:Array}>}
   */
  async function addImagesToSetBatch(itemId, imagesData) {
    if (!currentBoard.value) return { success: 0, failed: 0, images: [] };
    if (!imagesData?.length) return { success: 0, failed: 0, images: [] };
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/items/${itemId}/images/batch`,
        { images: imagesData }
      );
      if (response.data.success) {
        const data = response.data.data || {};
        const newImages = data.images || [];
        if (newImages.length) {
          const item = currentBoard.value.items.find((i) => i.id === itemId);
          if (item) {
            if (!item.images) item.images = [];
            item.images.push(...newImages);
            if (item.image_set_items) {
              item.image_set_items.push(...newImages);
            }
          }
        }
        return {
          success: data.success || 0,
          failed: data.failed || 0,
          images: newImages,
        };
      }
    } catch (e) {
      console.error("Failed to bulk-add images to set:", e);
    }
    return { success: 0, failed: imagesData.length, images: [] };
  }

  async function removeImageFromSet(itemId, imageId) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.delete(
        `/mood-boards/${currentBoard.value.id}/images/${imageId}`
      );
      if (response.data.success) {
        // Update local state - backend uses "images", frontend may also use "image_set_items"
        const item = currentBoard.value.items.find((i) => i.id === itemId);
        if (item) {
          if (item.image_set_items) {
            item.image_set_items = item.image_set_items.filter(
              (img) => img.id !== imageId
            );
          }
          if (item.images) {
            item.images = item.images.filter(
              (img) => img.id !== imageId
            );
          }
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to remove image from set:", e);
    }
    return false;
  }

  // ========================================
  // DRAWING ACTIONS
  // ========================================

  async function saveDrawing(drawingPayload, existingItemId = null) {
    if (!currentBoard.value) return null;
    try {
      // 1. Upload the rendered image blob
      const file = new File(
        [drawingPayload.imageBlob],
        `mood_drawing_${Date.now()}.png`,
        { type: "image/png" }
      );
      const uploaded = await uploadFiles([file]);
      const imageUrl = uploaded?.[0]?.url || null;

      if (existingItemId) {
        // 2a. Update existing drawing item
        return await updateItem(existingItemId, {
          content: drawingPayload.drawingData,
          image_url: imageUrl,
          width: drawingPayload.width || 400,
          height: drawingPayload.height || 300,
        });
      } else {
        // 2b. Create new drawing item at center of viewport
        return await addItem({
          type: "drawing",
          pos_x: drawingPayload.pos_x || 100,
          pos_y: drawingPayload.pos_y || 100,
          width: drawingPayload.width || 400,
          height: drawingPayload.height || 300,
          title: "Drawing",
          content: drawingPayload.drawingData,
          image_url: imageUrl,
        });
      }
    } catch (e) {
      console.error("Failed to save drawing:", e);
    }
    return null;
  }

  // ========================================
  // TODO ACTIONS
  // ========================================

  async function addTodo(itemId, text) {
    if (!currentBoard.value) return null;
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/items/${itemId}/todos`,
        { text }
      );
      if (response.data.success) {
        const todo = response.data.data.todo;
        const item = currentBoard.value.items.find((i) => i.id === itemId);
        if (item) {
          if (!item.todos) item.todos = [];
          item.todos.push(todo);
        }
        return todo;
      }
    } catch (e) {
      console.error("Failed to add todo:", e);
    }
    return null;
  }

  async function updateTodo(todoId, data) {
    if (!currentBoard.value) return null;
    try {
      const response = await api.put(
        `/mood-boards/${currentBoard.value.id}/todos/${todoId}`,
        data
      );
      if (response.data.success) {
        return response.data.data.todo;
      }
    } catch (e) {
      console.error("Failed to update todo:", e);
    }
    return null;
  }

  async function deleteTodo(todoId) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.delete(
        `/mood-boards/${currentBoard.value.id}/todos/${todoId}`
      );
      return response.data.success;
    } catch (e) {
      console.error("Failed to delete todo:", e);
      return false;
    }
  }

  // ========================================
  // CONNECTION ACTIONS
  // ========================================

  async function addConnection(data) {
    if (!currentBoard.value) return null;
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/connections`,
        data
      );
      if (response.data.success) {
        const connection = response.data.data.connection;
        if (!currentBoard.value.connections)
          currentBoard.value.connections = [];
        currentBoard.value.connections.push(connection);
        return connection;
      }
    } catch (e) {
      console.error("Failed to add connection:", e);
    }
    return null;
  }

  async function batchAddConnections(connectionsData) {
    if (!currentBoard.value || !connectionsData?.length) return []
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/connections/batch`,
        { connections: connectionsData }
      )
      if (response.data.success && response.data.data?.connections) {
        const created = response.data.data.connections
        if (!currentBoard.value.connections) currentBoard.value.connections = []
        currentBoard.value.connections.push(...created)
        return created
      }
    } catch (e) {
      console.error('Failed to batch add connections:', e)
    }
    return []
  }

  async function updateConnection(connId, data) {
    if (!currentBoard.value) return null;
    try {
      const response = await api.put(
        `/mood-boards/${currentBoard.value.id}/connections/${connId}`,
        data
      );
      if (response.data.success) {
        const updated = response.data.data.connection;
        const existing = (currentBoard.value.connections || []).find(
          (c) => c.id === connId
        );
        if (existing) {
          for (const key in updated) {
            if (key in data) continue;
            if (existing[key] !== updated[key]) {
              existing[key] = updated[key];
            }
          }
        }
        return updated;
      }
    } catch (e) {
      // If 404, the connection no longer exists on the server — remove it locally
      if (e?.response?.status === 404) {
        currentBoard.value.connections = (currentBoard.value.connections || [])
          .filter((c) => c.id !== connId);
      } else {
        console.error("Failed to update connection:", e);
      }
    }
    return null;
  }

  async function deleteConnection(connId) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.delete(
        `/mood-boards/${currentBoard.value.id}/connections/${connId}`
      );
      if (response.data.success) {
        currentBoard.value.connections = (
          currentBoard.value.connections || []
        ).filter((c) => c.id !== connId);
        return true;
      }
    } catch (e) {
      // If 404, already gone on server — clean up locally
      if (e?.response?.status === 404) {
        currentBoard.value.connections = (currentBoard.value.connections || [])
          .filter((c) => c.id !== connId);
        return true;
      }
      console.error("Failed to delete connection:", e);
      return false;
    }
  }

  // ========================================
  // SELECTION
  // ========================================

  function selectItem(itemId, addToSelection = false) {
    const item = currentItems.value.find(i => i.id === itemId)

    if (addToSelection) {
      const next = new Set(selectedItemIds.value)
      if (next.has(itemId)) {
        next.delete(itemId)
      } else {
        next.add(itemId)
      }
      selectedItemIds.value = next
      return
    }

    // Real group / repeat_grid: if item's parent is a container group, select the container
    const parentItem = item?.parent_id ? getItemById(item.parent_id) : null
    const isGroupParent = parentItem?.type === 'group' || parentItem?.type === 'repeat_grid'
    if (isGroupParent && editingGroupId.value !== parentItem.id) {
      editingGroupId.value = null
      selectedItemIds.value = new Set([parentItem.id])
      return
    }
    if (isGroupParent && editingGroupId.value === parentItem.id) {
      selectedItemIds.value = new Set([itemId])
      return
    }

    // Legacy group_id behavior
    const gid = item?.style_data?.group_id
    if (gid && editingGroupId.value !== gid) {
      editingGroupId.value = null
      selectGroup(itemId)
      return
    }
    if (gid && editingGroupId.value === gid) {
      selectedItemIds.value = new Set([itemId])
      return
    }

    editingGroupId.value = null
    selectedItemIds.value = new Set([itemId])
  }

  /**
   * Enter a group (double-click on a group item or group-selected item).
   * Sets editingGroupId and optionally selects a child.
   */
  function enterGroup(itemId) {
    const item = currentItems.value.find(i => i.id === itemId)
    if (!item) return

    // Enter any container item and select its first child
    if (ENTERABLE_CONTAINER_TYPES.has(item.type)) {
      editingGroupId.value = item.id
      const children = currentItems.value.filter(i => i.parent_id === item.id)
      if (children.length) {
        selectedItemIds.value = new Set([children[0].id])
      } else {
        selectedItemIds.value = new Set([item.id])
      }
      return
    }

    // Legacy group_id
    const gid = item.style_data?.group_id
    if (gid) {
      editingGroupId.value = gid
      selectedItemIds.value = new Set([itemId])
    }
  }

  /**
   * Exit group editing mode. Re-selects the group container.
   */
  function exitGroup() {
    if (!editingGroupId.value) return
    const eid = editingGroupId.value

    // Real group item — go up one level (Figma-like hierarchy)
    const groupItem = getItemById(eid)
    if (groupItem && ENTERABLE_CONTAINER_TYPES.has(groupItem.type)) {
      const parentItem = groupItem.parent_id ? getItemById(groupItem.parent_id) : null
      if (parentItem && ENTERABLE_CONTAINER_TYPES.has(parentItem.type)) {
        editingGroupId.value = parentItem.id
      } else {
        editingGroupId.value = null
      }
      selectedItemIds.value = new Set([groupItem.id])
      return
    }

    // Legacy group_id
    editingGroupId.value = null
    const groupItems = currentItems.value.filter(i => i.style_data?.group_id === eid)
    selectedItemIds.value = new Set(groupItems.map(i => i.id))
  }

  function clearSelection() {
    editingGroupId.value = null
    selectedItemIds.value = new Set();
  }

  function selectAll() {
    selectedItemIds.value = new Set(currentItems.value.map((i) => i.id));
  }

  // Scoped z-index helpers: apply z updates returned by layerOrderUtils
  function _applyZUpdates(updates) {
    for (const { id, z_index } of updates) {
      updateItem(id, { z_index })
    }
  }

  function bringToFront(itemId) {
    if (!currentBoard.value?.items) return
    const updates = _layerOrder.buildBringToFront(currentBoard.value.items, itemId)
    _applyZUpdates(updates)
  }

  function sendToBack(itemId) {
    if (!currentBoard.value?.items) return
    const updates = _layerOrder.buildSendToBack(currentBoard.value.items, itemId)
    _applyZUpdates(updates)
  }

  function moveForward(itemId) {
    if (!currentBoard.value?.items) return
    const updates = _layerOrder.buildMoveForward(currentBoard.value.items, itemId)
    _applyZUpdates(updates)
  }

  function moveBackward(itemId) {
    if (!currentBoard.value?.items) return
    const updates = _layerOrder.buildMoveBackward(currentBoard.value.items, itemId)
    _applyZUpdates(updates)
  }

  function reorderZIndex(movedItemId, targetItemId, position = 'before') {
    if (!currentBoard.value?.items) return
    const updates = _layerOrder.buildReorder(currentBoard.value.items, movedItemId, targetItemId, position)
    _applyZUpdates(updates)
  }

  function reorderGroupZIndex(groupId, targetItemId, position = 'before') {
    if (!currentBoard.value?.items) return
    const updates = _layerOrder.buildReorderGroup(currentBoard.value.items, groupId, targetItemId, position)
    _applyZUpdates(updates)
  }

  // ========================================
  // ALIGNMENT (multi-select)
  // ========================================

  function alignItems(direction) {
    const items = selectedItems.value
    if (items.length < 2) return

    const updates = []
    const rects = items.map(i => ({
      id: i.id,
      x: i.pos_x,
      y: i.pos_y,
      w: i.width || 240,
      h: i.height || 120,
    }))

    let ref
    switch (direction) {
      case 'left':
        ref = Math.min(...rects.map(r => r.x))
        for (const r of rects) updates.push({ id: r.id, pos_x: ref })
        break
      case 'right':
        ref = Math.max(...rects.map(r => r.x + r.w))
        for (const r of rects) updates.push({ id: r.id, pos_x: ref - r.w })
        break
      case 'top':
        ref = Math.min(...rects.map(r => r.y))
        for (const r of rects) updates.push({ id: r.id, pos_y: ref })
        break
      case 'bottom':
        ref = Math.max(...rects.map(r => r.y + r.h))
        for (const r of rects) updates.push({ id: r.id, pos_y: ref - r.h })
        break
      case 'center-h': {
        const minX = Math.min(...rects.map(r => r.x))
        const maxX = Math.max(...rects.map(r => r.x + r.w))
        const cx = (minX + maxX) / 2
        for (const r of rects) updates.push({ id: r.id, pos_x: Math.round(cx - r.w / 2) })
        break
      }
      case 'center-v': {
        const minY = Math.min(...rects.map(r => r.y))
        const maxY = Math.max(...rects.map(r => r.y + r.h))
        const cy = (minY + maxY) / 2
        for (const r of rects) updates.push({ id: r.id, pos_y: Math.round(cy - r.h / 2) })
        break
      }
      case 'distribute-h': {
        if (items.length < 3) return
        const sorted = [...rects].sort((a, b) => a.x - b.x)
        const totalWidth = sorted.reduce((s, r) => s + r.w, 0)
        const minX = sorted[0].x
        const maxX = sorted[sorted.length - 1].x + sorted[sorted.length - 1].w
        const gap = (maxX - minX - totalWidth) / (sorted.length - 1)
        let curX = sorted[0].x
        for (const r of sorted) {
          updates.push({ id: r.id, pos_x: Math.round(curX) })
          curX += r.w + gap
        }
        break
      }
      case 'distribute-v': {
        if (items.length < 3) return
        const sorted = [...rects].sort((a, b) => a.y - b.y)
        const totalHeight = sorted.reduce((s, r) => s + r.h, 0)
        const minY = sorted[0].y
        const maxY = sorted[sorted.length - 1].y + sorted[sorted.length - 1].h
        const gap = (maxY - minY - totalHeight) / (sorted.length - 1)
        let curY = sorted[0].y
        for (const r of sorted) {
          updates.push({ id: r.id, pos_y: Math.round(curY) })
          curY += r.h + gap
        }
        break
      }
    }

    if (!updates.length) return

    // When a group/repeat_grid is moved, cascade the same delta to all children
    // so their absolute positions stay correct relative to the parent
    const containerTypes = new Set(['group', 'repeat_grid'])
    const childUpdates = []
    for (const u of updates) {
      const item = items.find(i => i.id === u.id)
      if (!item || !containerTypes.has(item.type)) continue
      const dx = (u.pos_x ?? item.pos_x) - item.pos_x
      const dy = (u.pos_y ?? item.pos_y) - item.pos_y
      if (dx === 0 && dy === 0) continue
      _collectChildDeltas(item.id, dx, dy, childUpdates)
    }
    batchUpdateItems([...updates, ...childUpdates])
  }

  function _collectChildDeltas(parentId, dx, dy, out) {
    const children = currentItems.value.filter(i => i.parent_id === parentId)
    for (const child of children) {
      out.push({ id: child.id, pos_x: (child.pos_x || 0) + dx, pos_y: (child.pos_y || 0) + dy })
      if (child.type === 'group' || child.type === 'repeat_grid') {
        _collectChildDeltas(child.id, dx, dy, out)
      }
    }
  }

  // ========================================
  // SELECT SIMILAR (like Adobe Illustrator)
  // ========================================

  function selectSimilar(criteria) {
    const source = selectedItems.value[0]
    if (!source) return
    const sd = source.style_data || {}
    const items = currentItems.value

    const matches = items.filter(item => {
      if (item.id === source.id) return true
      const isd = item.style_data || {}
      switch (criteria) {
        case 'type':           return item.type === source.type
        case 'font-size':      return item.type === 'text' && source.type === 'text' && isd.font_size === sd.font_size
        case 'font-family':    return item.type === 'text' && source.type === 'text' && (isd.font_family || 'Inter') === (sd.font_family || 'Inter')
        case 'font-weight':    return item.type === 'text' && source.type === 'text' && (isd.font_weight || '400') === (sd.font_weight || '400')
        case 'text-color':     return item.type === 'text' && source.type === 'text' && isd.text_color === sd.text_color
        case 'fill-color':     return item.type === source.type && isd.shape_fill === sd.shape_fill
        case 'border-color':   return isd.shape_border_color === sd.shape_border_color
        case 'border-width':   return (isd.shape_border_width ?? 0) === (sd.shape_border_width ?? 0)
        case 'border-radius':  return (isd.shape_border_radius ?? 0) === (sd.shape_border_radius ?? 0)
        case 'opacity':        return (isd.shape_opacity ?? 100) === (sd.shape_opacity ?? 100)
        case 'size':           return item.width === source.width && item.height === source.height
        default:               return false
      }
    })

    if (matches.length > 0) {
      selectedItemIds.value = new Set(matches.map(i => i.id))
    }
  }

  // ========================================
  // ITEM GROUPING (visual groups — Ctrl+G)
  // ========================================

  /**
   * Group the currently selected items together.
   * Creates a real 'group' item and re-parents selected items into it via parent_id.
   * Supports nested groups: if selected items include existing groups, they become sub-groups.
   */
  async function groupSelectedItems() {
    const items = selectedItems.value
    if (items.length < 2) return null

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const item of items) {
      const x = item.pos_x || 0
      const y = item.pos_y || 0
      const w = item.width || 240
      const h = item.height || 120
      minX = Math.min(minX, x)
      minY = Math.min(minY, y)
      maxX = Math.max(maxX, x + w)
      maxY = Math.max(maxY, y + h)
    }

    const parentIds = new Set(items.map(i => i.parent_id || null))
    const sharedParent = parentIds.size === 1 ? [...parentIds][0] : null

    const group = await addItem({
      type: 'group',
      pos_x: Math.round(minX),
      pos_y: Math.round(minY),
      width: Math.round(maxX - minX) || 100,
      height: Math.round(maxY - minY) || 100,
      parent_id: sharedParent,
      title: 'Group',
    })
    if (!group) return null

    const sorted = _layerOrder.sortByZIndexAsc(items)
    const updates = sorted.map((item, i) => {
      const upd = { id: item.id, parent_id: group.id, z_index: i + 1 }
      if (item.style_data?.group_id) {
        const sd = { ...(item.style_data || {}) }
        delete sd.group_id
        upd.style_data = sd
      }
      return upd
    })
    await batchUpdateItems(updates)

    selectedItemIds.value = new Set([group.id])
    return group
  }

  /**
   * Ungroup selected items. Handles both real group items (type==='group') and
   * legacy style_data.group_id groups.
   */
  async function ungroupSelectedItems() {
    const items = selectedItems.value
    if (!items.length) return

    const groupItemIds = new Set()
    for (const item of items) {
      if (item.type === 'group') groupItemIds.add(item.id)
      if (item.parent_id) {
        const parent = getItemById(item.parent_id)
        if (parent?.type === 'group') groupItemIds.add(parent.id)
      }
    }

    const childIds = new Set()
    for (const gid of groupItemIds) {
      const children = currentItems.value.filter(i => i.parent_id === gid)
      const groupItem = getItemById(gid)
      const grandParent = groupItem?.parent_id || null

      // Recalculate z-indices so children land at the top of the target scope
      // (prevents items from being "sent behind" everything else)
      const targetScope = _layerOrder.layerScope({ parent_id: grandParent, type: children[0]?.type })
      let baseZ = _layerOrder.nextZIndexInScope(currentBoard.value.items, targetScope)
      const sorted = _layerOrder.sortByZIndexAsc(children)
      const upds = sorted.map((child, i) => ({
        id: child.id,
        parent_id: grandParent,
        z_index: baseZ + i,
      }))
      if (upds.length) batchUpdateItems(upds)
      children.forEach(c => childIds.add(c.id))

      // Flush the batch queue NOW and await it so the server reparents
      // children before we delete the group container (prevents orphaning)
      await _flushBatchQueue()

      await deleteItem(gid)
    }

    // Legacy: handle style_data.group_id based groups
    const legacyGroupIds = new Set()
    for (const item of items) {
      const gid = item.style_data?.group_id
      if (gid) legacyGroupIds.add(gid)
    }
    if (legacyGroupIds.size) {
      const allGroupedItems = currentItems.value.filter(i => legacyGroupIds.has(i.style_data?.group_id))
      const upds = allGroupedItems.map(item => {
        const sd = { ...(item.style_data || {}) }
        delete sd.group_id
        return { id: item.id, style_data: sd }
      })
      batchUpdateItems(upds)
      allGroupedItems.forEach(i => childIds.add(i.id))
    }

    if (childIds.size) {
      selectedItemIds.value = childIds
    }
  }

  // ========================================
  // REPEAT GRID (XD-style pattern repeat)
  // ========================================

  async function createRepeatGrid() {
    const items = selectedItems.value
    if (!items.length) return null

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const item of items) {
      minX = Math.min(minX, item.pos_x || 0)
      minY = Math.min(minY, item.pos_y || 0)
      maxX = Math.max(maxX, (item.pos_x || 0) + (item.width || 100))
      maxY = Math.max(maxY, (item.pos_y || 0) + (item.height || 100))
    }

    const cellW = Math.round(maxX - minX) || 100
    const cellH = Math.round(maxY - minY) || 100
    const cols = 3, rows = 3, hGap = 20, vGap = 20

    const parentIds = new Set(items.map(i => i.parent_id || null))
    const sharedParent = parentIds.size === 1 ? [...parentIds][0] : null

    const grid = await addItem({
      type: 'repeat_grid',
      pos_x: Math.round(minX),
      pos_y: Math.round(minY),
      width: cols * cellW + (cols - 1) * hGap,
      height: rows * cellH + (rows - 1) * vGap,
      parent_id: sharedParent,
      title: 'Repeat Grid',
      style_data: {
        grid_columns: cols,
        grid_rows: rows,
        grid_h_gap: hGap,
        grid_v_gap: vGap,
      },
    })
    if (!grid) return null

    const sorted = _layerOrder.sortByZIndexAsc(items)
    const updates = sorted.map((item, i) => ({
      id: item.id, parent_id: grid.id, z_index: i + 1,
    }))
    batchUpdateItems(updates)

    selectedItemIds.value = new Set([grid.id])
    return grid
  }

  function dissolveRepeatGrid() {
    const items = selectedItems.value
    if (!items.length) return
    for (const item of items) {
      if (item.type !== 'repeat_grid') continue
      const children = currentItems.value.filter(i => i.parent_id === item.id)
      const grandParent = item.parent_id || null
      if (children.length) {
        batchUpdateItems(children.map(c => ({ id: c.id, parent_id: grandParent })))
      }
      deleteItem(item.id)
    }
    selectedItemIds.value = new Set()
  }

  /**
   * Convert the currently selected item(s) into a frame.
   * Creates a new frame wrapping the selection, preserving relative positions.
   * Single item: wraps it in a frame with some padding.
   * Multiple items: computes bounding box, creates frame, re-parents all items.
   */
  async function convertToFrame() {
    const items = selectedItems.value
    if (!items.length) return null

    // Compute bounding box of all selected items
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const item of items) {
      const x = item.pos_x || 0
      const y = item.pos_y || 0
      const w = item.width || 240
      const h = item.height || 120
      minX = Math.min(minX, x)
      minY = Math.min(minY, y)
      maxX = Math.max(maxX, x + w)
      maxY = Math.max(maxY, y + h)
    }

    // Add padding around the bounding box
    const pad = 20
    const frameX = minX - pad
    const frameY = minY - pad
    const frameW = (maxX - minX) + pad * 2
    const frameH = (maxY - minY) + pad * 2

    // Create the frame
    const frame = await addItem({
      type: 'frame',
      pos_x: Math.round(frameX),
      pos_y: Math.round(frameY),
      width: Math.round(frameW),
      height: Math.round(frameH),
      style_data: {
        fill_color: '#ffffff',
        clip_content: true,
        padding: pad,
      }
    })
    if (!frame) return null

    // Re-parent selected items into the new frame and renumber z within the new scope
    const sorted = _layerOrder.sortByZIndexAsc(items)
    const updates = sorted.map((item, i) => ({
      id: item.id,
      parent_id: frame.id,
      z_index: i + 1,
    }))
    await batchUpdateItems(updates)

    // Select the new frame
    selectedItemIds.value = new Set([frame.id])
    return frame
  }

  /**
   * Select all items in the same group as the given item.
   * Handles both real group items and legacy style_data.group_id.
   */
  function selectGroup(itemId) {
    const item = currentItems.value.find(i => i.id === itemId)
    if (!item) return false

    // Real group: item is a child of a group container
    const parent = item.parent_id ? getItemById(item.parent_id) : null
    if (parent?.type === 'group') {
      selectedItemIds.value = new Set([parent.id])
      return true
    }

    // Legacy group_id
    const groupId = item.style_data?.group_id
    if (!groupId) return false
    const groupItems = currentItems.value.filter(i => i.style_data?.group_id === groupId)
    if (groupItems.length <= 1) return false
    selectedItemIds.value = new Set(groupItems.map(i => i.id))
    return true
  }

  /**
   * Check if the selection contains any grouped items (real or legacy).
   */
  function selectionHasGroups() {
    return selectedItems.value.some(i =>
      i.type === 'group' ||
      i.style_data?.group_id ||
      (i.parent_id && getItemById(i.parent_id)?.type === 'group')
    )
  }

  // ========================================
  // BOOLEAN SHAPE OPERATIONS
  // ========================================

  /**
   * Check if current selection can perform boolean operations.
   * Requires 2+ shapes/pen_shapes selected.
   */
  function canBooleanOp() {
    const items = selectedItems.value
    if (items.length < 2) return false
    return items.every(i => i.type === 'shape' || i.type === 'pen_shape')
  }

  /**
   * Perform a boolean operation on selected shapes.
   * Creates a new pen_shape from the result and deletes the source shapes.
   *
   * @param {'union'|'subtract'|'intersect'|'exclude'|'flatten'} operation
   */
  async function booleanOp(operation) {
    const items = selectedItems.value.filter(i => i.type === 'shape' || i.type === 'pen_shape')
    if (items.length < 2) return null

    // Sort by z_index so the "first" item is the bottom one (subtract uses order)
    const sorted = [...items].sort((a, b) => (a.z_index || 0) - (b.z_index || 0))

    // Dynamic import to avoid loading the library until needed
    const { performBooleanOp } = await import('@/addons/moodboards/components/utils/booleanOps.js')
    const result = performBooleanOp(operation, sorted)
    if (!result) {
      console.warn(`Boolean ${operation} produced no result`)
      return null
    }

    const boolScope = _layerOrder.layerScope(sorted[0])
    result.z_index = _layerOrder.nextZIndexInScope(currentBoard.value.items, boolScope)

    // Delete source shapes
    const sourceIds = sorted.map(i => i.id)
    for (const id of sourceIds) {
      await deleteItem(id)
    }

    // Add the new combined shape
    const newItem = await addItem(result)
    if (newItem) {
      selectItem(newItem.id, false)
    }
    return newItem
  }

  // ========================================
  // CLIPPING MASK (shape as container)
  // ========================================

  /**
   * Mask selected items with a shape.
   * The shape with the lowest z_index becomes the mask container,
   * all other selected items become masked children.
   */
  function maskSelectedItems() {
    const items = selectedItems.value
    if (items.length < 2) return false

    // Find shape(s) in the selection
    const shapes = items.filter(i => i.type === 'shape' || i.type === 'pen_shape')
    const others = items.filter(i => i.type !== 'shape' && i.type !== 'pen_shape')

    if (!shapes.length || !others.length) return false

    // Use the first shape (lowest z) as the mask container
    const maskShape = shapes.sort((a, b) => (a.z_index || 0) - (b.z_index || 0))[0]

    // Mark all other items as masked children of this shape
    const updates = others.map(item => ({
      id: item.id,
      style_data: {
        ...(item.style_data || {}),
        mask_parent_id: maskShape.id,
        // Store the original position offset relative to the shape
        mask_offset_x: (item.pos_x || 0) - (maskShape.pos_x || 0),
        mask_offset_y: (item.pos_y || 0) - (maskShape.pos_y || 0),
      }
    }))

    // Also mark remaining shapes (if multiple) as children
    for (const s of shapes) {
      if (s.id === maskShape.id) continue
      updates.push({
        id: s.id,
        style_data: {
          ...(s.style_data || {}),
          mask_parent_id: maskShape.id,
          mask_offset_x: (s.pos_x || 0) - (maskShape.pos_x || 0),
          mask_offset_y: (s.pos_y || 0) - (maskShape.pos_y || 0),
        }
      })
    }

    batchUpdateItems(updates)
    return true
  }

  /**
   * Release items from a clipping mask.
   * Restores children to their absolute canvas positions.
   */
  function unmaskItems(maskShapeId) {
    if (!currentBoard.value?.items) return
    const children = currentBoard.value.items.filter(
      i => i.style_data?.mask_parent_id === maskShapeId
    )
    if (!children.length) return

    const maskShape = currentBoard.value.items.find(i => i.id === maskShapeId)
    const updates = children.map(item => {
      const sd = { ...(item.style_data || {}) }
      delete sd.mask_parent_id
      delete sd.mask_offset_x
      delete sd.mask_offset_y
      return {
        id: item.id,
        // Restore absolute position from mask parent position + offset
        pos_x: (maskShape?.pos_x || 0) + (item.style_data?.mask_offset_x || 0),
        pos_y: (maskShape?.pos_y || 0) + (item.style_data?.mask_offset_y || 0),
        style_data: sd
      }
    })
    batchUpdateItems(updates)
  }

  /**
   * Check if the current selection can be masked (has at least 1 shape + 1 other item).
   */
  function canMaskSelection() {
    const items = selectedItems.value
    if (items.length < 2) return false
    const hasShape = items.some(i => i.type === 'shape' || i.type === 'pen_shape')
    const hasOther = items.some(i => i.type !== 'shape' && i.type !== 'pen_shape')
    return hasShape && hasOther
  }

  /**
   * Check if the right-clicked item is a mask container with children.
   */
  function isMaskContainer(itemId) {
    if (!currentBoard.value?.items) return false
    return currentBoard.value.items.some(i => i.style_data?.mask_parent_id === itemId)
  }

  // ========================================
  // COLOR PALETTE (saved per board)
  // ========================================

  function getColorPalette() {
    if (!currentBoard.value) return []
    const raw = currentBoard.value.color_palette
    if (!raw) return []
    try {
      return typeof raw === 'string' ? JSON.parse(raw) : raw
    } catch { return [] }
  }

  async function saveColorPalette(colors) {
    if (!currentBoard.value) return false
    currentBoard.value.color_palette = colors
    const result = await updateBoard(currentBoard.value.id, { color_palette: JSON.stringify(colors) })
    return result !== null
  }

  async function addToPalette(color) {
    const palette = getColorPalette()
    if (palette.includes(color)) return true
    palette.push(color)
    const ok = await saveColorPalette(palette)
    if (ok) {
      try {
        const { useMoodBoardGlobalStylesStore } = await import('./moodBoardGlobalStyles')
        const gs = useMoodBoardGlobalStylesStore()
        const exists = gs.globalColors.some(t => t.value?.toLowerCase() === color.toLowerCase())
        if (!exists) {
          await gs.addGlobalColor({
            id: `gc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
            name: color.toUpperCase(),
            value: color,
          })
        }
      } catch { /* non-critical */ }
    }
    return ok
  }

  async function removeFromPalette(color) {
    const palette = getColorPalette().filter(c => c !== color)
    return await saveColorPalette(palette)
  }

  // ========================================
  // GRADIENT PALETTE (saved per board)
  // ========================================

  function getGradientPalette() {
    if (!currentBoard.value) return []
    const raw = currentBoard.value.gradient_palette
    if (!raw) return []
    try {
      return typeof raw === 'string' ? JSON.parse(raw) : raw
    } catch { return [] }
  }

  async function saveGradientPalette(gradients) {
    if (!currentBoard.value) return
    currentBoard.value.gradient_palette = gradients
    await updateBoard(currentBoard.value.id, { gradient_palette: JSON.stringify(gradients) })
  }

  async function addGradientTopalette(gradient) {
    const palette = getGradientPalette()
    // Check for duplicates by comparing stringified gradient
    const key = JSON.stringify(gradient)
    if (palette.some(g => JSON.stringify(g) === key)) return
    palette.push(gradient)
    await saveGradientPalette(palette)
  }

  async function removeGradientFromPalette(index) {
    const palette = getGradientPalette()
    palette.splice(index, 1)
    await saveGradientPalette(palette)
  }

  // ========================================
  // USER PALETTES (shareable across boards)
  // ========================================

  const userPalettes = ref([])
  const userPalettesLoading = ref(false)

  async function fetchUserPalettes() {
    try {
      userPalettesLoading.value = true
      const response = await api.get('/mood-boards/palettes')
      userPalettes.value = response.data?.data || []
    } catch (e) {
      if (isDebugEnabled()) console.error('fetchUserPalettes error:', e)
    } finally {
      userPalettesLoading.value = false
    }
  }

  async function createUserPalette(data) {
    try {
      const response = await api.post('/mood-boards/palettes', data)
      if (response.data?.success) {
        userPalettes.value.unshift(response.data.data)
        return response.data.data
      }
    } catch (e) {
      if (isDebugEnabled()) console.error('createUserPalette error:', e)
    }
    return null
  }

  async function updateUserPalette(id, data) {
    try {
      const response = await api.put(`/mood-boards/palettes/${id}`, data)
      if (response.data?.success) {
        const idx = userPalettes.value.findIndex(p => p.id === id)
        if (idx >= 0) userPalettes.value[idx] = response.data.data
        return response.data.data
      }
    } catch (e) {
      if (isDebugEnabled()) console.error('updateUserPalette error:', e)
    }
    return null
  }

  async function deleteUserPalette(id) {
    try {
      const response = await api.delete(`/mood-boards/palettes/${id}`)
      if (response.data?.success) {
        userPalettes.value = userPalettes.value.filter(p => p.id !== id)
        return true
      }
    } catch (e) {
      if (isDebugEnabled()) console.error('deleteUserPalette error:', e)
    }
    return false
  }

  async function saveBoardAsUserPalette(name, isShared = false) {
    if (!currentBoard.value) return null
    try {
      const response = await api.post(`/mood-boards/palettes/from-board/${currentBoard.value.id}`, {
        name, is_shared: isShared
      })
      if (response.data?.success) {
        userPalettes.value.unshift(response.data.data)
        return response.data.data
      }
    } catch (e) {
      if (isDebugEnabled()) console.error('saveBoardAsUserPalette error:', e)
    }
    return null
  }

  async function applyUserPaletteToBoard(paletteId, mode = 'merge') {
    if (!currentBoard.value) return null
    try {
      const response = await api.post(`/mood-boards/palettes/${paletteId}/apply/${currentBoard.value.id}`, { mode })
      if (response.data?.success && response.data.data?.board) {
        const board = response.data.data.board
        currentBoard.value.color_palette = board.color_palette
        currentBoard.value.gradient_palette = board.gradient_palette
        return board
      }
    } catch (e) {
      if (isDebugEnabled()) console.error('applyUserPaletteToBoard error:', e)
    }
    return null
  }

  // ========================================
  // BRUSH PRESETS & SETTINGS (saved per board)
  // ========================================

  function getBrushPresets() {
    if (!currentBoard.value) return []
    const raw = currentBoard.value.brush_presets
    if (!raw) return []
    try {
      return typeof raw === 'string' ? JSON.parse(raw) : raw
    } catch { return [] }
  }

  async function saveBrushPresets(presets) {
    if (!currentBoard.value) return
    currentBoard.value.brush_presets = presets
    await updateBoard(currentBoard.value.id, { brush_presets: JSON.stringify(presets) })
  }

  async function addBrushPreset(preset) {
    const presets = getBrushPresets()
    presets.push(preset)
    await saveBrushPresets(presets)
  }

  async function removeBrushPreset(presetId) {
    const presets = getBrushPresets().filter(p => p.id !== presetId)
    await saveBrushPresets(presets)
  }

  function getBrushSettings() {
    if (!currentBoard.value) return null
    const raw = currentBoard.value.brush_settings
    if (!raw) return null
    try {
      return typeof raw === 'string' ? JSON.parse(raw) : raw
    } catch { return null }
  }

  let _brushSettingsTimer = null
  async function saveBrushSettings(settings) {
    if (!currentBoard.value) return
    currentBoard.value.brush_settings = settings
    // Capture board ID NOW — if the user switches boards during the delay,
    // we must save to the ORIGINAL board, not the newly opened one.
    const boardId = currentBoard.value.id
    const payload = JSON.stringify(settings)
    // Debounce DB writes since brush settings change frequently (slider dragging)
    clearTimeout(_brushSettingsTimer)
    _brushSettingsTimer = setTimeout(async () => {
      await updateBoard(boardId, { brush_settings: payload })
    }, 800)
  }

  // ========================================
  // BACKGROUND EFFECTS (saved per board)
  // ========================================

  function getBackgroundEffect() {
    if (!currentBoard.value) return null
    const raw = currentBoard.value.background_effect
    if (!raw) return null
    try {
      return typeof raw === 'string' ? JSON.parse(raw) : raw
    } catch { return null }
  }

  async function saveBackgroundEffect(effect) {
    if (!currentBoard.value) return
    currentBoard.value.background_effect = effect
    await updateBoard(currentBoard.value.id, { background_effect: JSON.stringify(effect) })
  }

  // ========================================
  // CLIPBOARD (Copy / Paste / Duplicate)
  // ========================================

  const clipboard = ref([])
  const clipboardConnections = ref([])

  /**
   * Copy the currently selected items into the clipboard.
   * Stores deep-clones. For containers (group, frame, repeat_grid), also
   * copies all descendant children so the hierarchy is preserved on paste.
   */
  function copySelectedItems() {
    const items = selectedItems.value
    if (!items.length) return 0

    const allIds = new Set(items.map(i => i.id))
    const containerTypes = new Set(['group', 'frame', 'repeat_grid', 'slide', 'artboard'])

    let grew = true
    while (grew) {
      grew = false
      for (const id of [...allIds]) {
        const item = getItemById(id)
        if (item && containerTypes.has(item.type)) {
          for (const child of currentItems.value) {
            if (child.parent_id === id && !allIds.has(child.id)) {
              allIds.add(child.id)
              grew = true
            }
          }
        }
      }
    }

    const allItems = currentItems.value.filter(i => allIds.has(i.id))

    clipboard.value = allItems.map(item => {
      const clone = deepClone(item)
      clone._source_id = clone.id
      delete clone.id
      delete clone.board_id
      delete clone.created_at
      delete clone.updated_at
      if (clone.parent_id && !allIds.has(clone.parent_id)) {
        delete clone.parent_id
      }
      return clone
    })

    const allConns = currentBoard.value?.connections || []
    clipboardConnections.value = allConns
      .filter(c => allIds.has(c.from_item_id) && allIds.has(c.to_item_id))
      .map(c => {
        const clone = deepClone(c)
        clone._source_from = c.from_item_id
        clone._source_to = c.to_item_id
        delete clone.id
        delete clone.board_id
        delete clone.created_at
        return clone
      })

    writeToSystemClipboard(clipboard.value, clipboardConnections.value)
    return clipboard.value.length
  }

  /**
   * Hydrate the internal clipboard from system clipboard text (cross-tab paste).
   * Returns true if FlowOne data was found and loaded.
   */
  function loadClipboardFromText(text) {
    const parsed = parseFromClipboard(text)
    if (!parsed) return false
    clipboard.value = Array.isArray(parsed) ? parsed : parsed.items
    clipboardConnections.value = parsed.connections || []
    return true
  }

  /**
   * Paste items from the clipboard at an offset from their original position.
   * Each paste shifts items by +30px so repeated pastes don't stack exactly.
   */
  async function pasteItems(offsetX = 30, offsetY = 30) {
    if (!clipboard.value.length || !currentBoard.value) return []

    const firstSource = clipboard.value[0]
    const pasteScope = _layerOrder.layerScope({ parent_id: firstSource?.parent_id || null, type: firstSource?.type })
    const baseZ = _layerOrder.nextZIndexInScope(currentBoard.value.items || [], pasteScope)

    const groupIdMap = {}
    for (const source of clipboard.value) {
      const oldGid = source.style_data?.group_id
      if (oldGid && !groupIdMap[oldGid]) {
        groupIdMap[oldGid] = 'grp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8)
      }
    }

    const instanceIdMap = {}
    for (const source of clipboard.value) {
      const oldIid = source.component_instance_id
      if (oldIid && !instanceIdMap[oldIid]) {
        instanceIdMap[oldIid] = crypto.randomUUID ? crypto.randomUUID() : (Date.now().toString(36) + Math.random().toString(36).slice(2, 10))
      }
    }

    const sourceIdToIdx = new Map()
    const sortedClipboard = [...clipboard.value].sort(
      (a, b) => (a.z_index || 0) - (b.z_index || 0)
    )
    for (let i = 0; i < sortedClipboard.length; i++) {
      if (sortedClipboard[i]._source_id != null) {
        sourceIdToIdx.set(sortedClipboard[i]._source_id, i)
      }
    }

    // Build all payloads at once (children created without parent_id)
    const payloads = sortedClipboard.map((source, i) => {
      const payload = {
        ...source,
        pos_x: (source.pos_x || 0) + offsetX,
        pos_y: (source.pos_y || 0) + offsetY,
        z_index: baseZ + i,
      }
      delete payload._source_id
      if (payload.parent_id && sourceIdToIdx.has(payload.parent_id)) {
        delete payload.parent_id
      }
      const oldGid = payload.style_data?.group_id
      if (oldGid && groupIdMap[oldGid]) {
        payload.style_data = { ...payload.style_data, group_id: groupIdMap[oldGid] }
      }
      if (payload.component_instance_id && instanceIdMap[payload.component_instance_id]) {
        payload.component_instance_id = instanceIdMap[payload.component_instance_id]
      }
      delete payload.todos
      delete payload.images
      return payload
    })

    // Phase 1: Single batch API call for ALL items
    const realIds = await batchAddItems(payloads)

    // Build server items map for undo + connection remapping
    const serverItems = realIds.map(id => getItemById(id)).filter(Boolean)

    // Select top-level pasted items
    const topLevelRealIds = realIds.filter((_, i) => {
      const source = sortedClipboard[i]
      return !source?.parent_id || !sourceIdToIdx.has(source.parent_id)
    })
    selectedItemIds.value = new Set(topLevelRealIds.length ? topLevelRealIds : realIds)

    // Update clipboard positions for subsequent paste offset
    clipboard.value = clipboard.value.map(item => ({
      ...item,
      pos_x: (item.pos_x || 0) + offsetX,
      pos_y: (item.pos_y || 0) + offsetY,
    }))
    clipboardConnections.value = clipboardConnections.value.map(c => ({
      ...c,
      bend_x: c.bend_x != null ? c.bend_x + offsetX : null,
      bend_y: c.bend_y != null ? c.bend_y + offsetY : null,
      bend2_x: c.bend2_x != null ? c.bend2_x + offsetX : null,
      bend2_y: c.bend2_y != null ? c.bend2_y + offsetY : null,
    }))

    // Phase 2: Remap parent_ids with real server IDs
    const parentServerUpdates = []
    for (let i = 0; i < sortedClipboard.length; i++) {
      const source = sortedClipboard[i]
      if (source.parent_id && sourceIdToIdx.has(source.parent_id)) {
        const parentIdx = sourceIdToIdx.get(source.parent_id)
        const childId = realIds[i]
        const parentId = realIds[parentIdx]
        if (childId && parentId) {
          parentServerUpdates.push({ id: childId, parent_id: parentId })
          const childItem = getItemById(childId)
          if (childItem) childItem.parent_id = parentId
        }
      }
    }
    if (parentServerUpdates.length) {
      batchUpdateItems(parentServerUpdates, { skipUndo: true })
    }

    if (serverItems.length) {
      pushUndo({ type: 'multi-add', itemIds: realIds, items: serverItems.map(i => ({ ...i })) })
    }

    // Phase 3: Batch-create connections in a single API call
    if (clipboardConnections.value.length && realIds.length) {
      const sourceToReal = new Map()
      for (let i = 0; i < sortedClipboard.length; i++) {
        const srcId = sortedClipboard[i]._source_id
        if (srcId != null && realIds[i]) sourceToReal.set(srcId, realIds[i])
      }
      const connPayloads = []
      for (const conn of clipboardConnections.value) {
        const newFrom = sourceToReal.get(conn._source_from)
        const newTo = sourceToReal.get(conn._source_to)
        if (newFrom && newTo) {
          const connData = { ...conn, from_item_id: newFrom, to_item_id: newTo }
          delete connData._source_from
          delete connData._source_to
          connPayloads.push(connData)
        }
      }
      if (connPayloads.length) {
        batchAddConnections(connPayloads)
      }
    }

    return realIds
  }

  /**
   * Duplicate the currently selected items in-place (Ctrl+D shortcut).
   * Copies then pastes in one action.
   */
  async function duplicateSelectedItems(offsetX = 30, offsetY = 30) {
    const count = copySelectedItems()
    if (!count) return []
    return await pasteItems(offsetX, offsetY)
  }

  // ========================================
  // MEMBER MANAGEMENT
  // ========================================

  async function addMember(email, role = 'editor') {
    if (!currentBoard.value) return false;
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/members`,
        { email, role }
      );
      if (response.data.success) {
        currentBoard.value.members = response.data.data.members;
        return true;
      }
    } catch (e) {
      console.error("Failed to add member:", e);
    }
    return false;
  }

  async function updateMemberRole(email, role) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.put(
        `/mood-boards/${currentBoard.value.id}/members/${encodeURIComponent(email)}`,
        { role }
      );
      if (response.data.success) {
        currentBoard.value.members = response.data.data.members;
        return true;
      }
    } catch (e) {
      console.error("Failed to update member role:", e);
    }
    return false;
  }

  async function removeMember(email) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.delete(
        `/mood-boards/${currentBoard.value.id}/members/${encodeURIComponent(email)}`
      );
      if (response.data.success) {
        currentBoard.value.members = (currentBoard.value.members || []).filter(
          (m) => m.email !== email
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to remove member:", e);
    }
    return false;
  }

  // ========================================
  // GROUP ACCESS
  // ========================================

  async function addGroupAccess(groupId, role = 'editor') {
    if (!currentBoard.value) return false;
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/groups`,
        { group_id: groupId, role }
      );
      if (response.data.success) {
        currentBoard.value.groups = response.data.data.groups;
        return true;
      }
    } catch (e) {
      console.error("Failed to add group access:", e);
    }
    return false;
  }

  async function removeGroupAccess(groupId) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.delete(
        `/mood-boards/${currentBoard.value.id}/groups/${groupId}`
      );
      if (response.data.success) {
        currentBoard.value.groups = (currentBoard.value.groups || []).filter(
          (g) => g.group_id !== groupId
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to remove group access:", e);
    }
    return false;
  }

  // ========================================
  // BOARD LINKING (Mood <-> Kanban)
  // ========================================

  async function linkToKanbanBoard(kanbanBoardId) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.post(
        `/mood-boards/${currentBoard.value.id}/board-links`,
        { kanban_board_id: kanbanBoardId }
      );
      if (response.data.success) {
        currentBoard.value.linked_boards = response.data.data.linked_boards;
        return true;
      }
    } catch (e) {
      console.error("Failed to link to kanban board:", e);
    }
    return false;
  }

  async function unlinkFromKanbanBoard(kanbanBoardId) {
    if (!currentBoard.value) return false;
    try {
      const response = await api.delete(
        `/mood-boards/${currentBoard.value.id}/board-links/${kanbanBoardId}`
      );
      if (response.data.success) {
        currentBoard.value.linked_boards = (currentBoard.value.linked_boards || []).filter(
          (l) => l.kanban_board_id !== kanbanBoardId
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to unlink from kanban board:", e);
    }
    return false;
  }

  async function fetchMoodBoardsForKanban(kanbanBoardId) {
    try {
      const response = await api.get(`/boards/${kanbanBoardId}/mood-boards`);
      if (response.data.success) {
        return response.data.data.mood_boards;
      }
    } catch (e) {
      console.error("Failed to fetch mood boards for kanban:", e);
    }
    return [];
  }

  // ========================================
  // CLIENT BOARDS
  // ========================================

  async function fetchClientBoards(clientId) {
    try {
      const response = await api.get(`/clients/${clientId}/mood-boards`);
      if (response.data.success) {
        return response.data.data.boards;
      }
    } catch (e) {
      console.error("Failed to fetch client mood boards:", e);
    }
    return [];
  }

  async function linkToClient(clientId, boardId) {
    try {
      const response = await api.post(`/clients/${clientId}/mood-boards`, {
        mood_board_id: boardId,
      });
      if (response.data.success) {
        // Update current board's client_id if this is the active board
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.client_id = clientId;
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to link mood board to client:", e);
    }
    return false;
  }

  async function unlinkFromClient(clientId, boardId) {
    try {
      const response = await api.delete(
        `/clients/${clientId}/mood-boards/${boardId}`
      );
      if (response.data.success) {
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.client_id = null;
          currentBoard.value.client = null;
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to unlink mood board from client:", e);
    }
    return false;
  }

  // ========================================
  // PUBLIC SHARING
  // ========================================

  /**
   * Create a public share link for the current board.
   */
  async function createShareLink(boardId, { mode = 'view', password = null, expiresHours = null } = {}) {
    try {
      const response = await api.post(`/mood-boards/${boardId}/share`, {
        mode, password, expires_hours: expiresHours
      });
      if (response.data.success) {
        // Update current board's share info
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.share_token = response.data.data.token;
          currentBoard.value.share_mode = mode;
          currentBoard.value.share_expires = response.data.data.expires_at;
        }
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to create share link:', e);
    }
    return null;
  }

  /**
   * Update an existing share link's settings.
   */
  async function updateShareLink(boardId, data) {
    try {
      const response = await api.put(`/mood-boards/${boardId}/share`, data);
      if (response.data.success) {
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.share_token = response.data.data.token;
          currentBoard.value.share_mode = data.mode || currentBoard.value.share_mode;
          currentBoard.value.share_expires = response.data.data.expires_at;
        }
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to update share link:', e);
    }
    return null;
  }

  /**
   * Remove/disable the public share link.
   */
  async function removeShareLink(boardId) {
    try {
      const response = await api.delete(`/mood-boards/${boardId}/share`);
      if (response.data.success) {
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.share_token = null;
          currentBoard.value.share_mode = 'off';
          currentBoard.value.share_expires = null;
        }
        return true;
      }
    } catch (e) {
      console.error('Failed to remove share link:', e);
    }
    return false;
  }

  /**
   * Fetch share analytics for a board.
   */
  async function fetchShareStats(boardId) {
    try {
      const response = await api.get(`/mood-boards/${boardId}/share/stats`);
      if (response.data.success) {
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to fetch share stats:', e);
    }
    return null;
  }

  /**
   * Fetch all publicly shared boards with summary stats.
   */
  async function fetchSharedBoards() {
    try {
      const response = await api.get('/mood-boards/shared');
      if (response.data.success) {
        return response.data.data.boards;
      }
    } catch (e) {
      console.error('Failed to fetch shared boards:', e);
    }
    return [];
  }

  /**
   * Load a shared board via public token (no authentication).
   * Used by SharedMoodBoardView.
   */
  async function loadSharedBoard(token, password = null) {
    boardLoading.value = true;
    isPublicView.value = true;
    publicShareToken.value = token;
    try {
      const params = {};
      if (password) params.password = password;
      const response = await api.get(`/mood-boards/share/${token}`, { params });
      if (response.data.success) {
        currentBoard.value = response.data.data.board;
        _stripOrphanConnections(currentBoard.value);
        publicShareMode.value = response.data.data.share_mode || 'view';
        zoom.value = parseFloat(currentBoard.value.zoom_level) || 1;
        panX.value = parseInt(currentBoard.value.viewport_x) || 0;
        panY.value = parseInt(currentBoard.value.viewport_y) || 0;
        // Restore motion settings for shared view too
        if (currentBoard.value.motion_settings) {
          restoreMotionSettings(currentBoard.value.motion_settings);
        }
        return { success: true, board: currentBoard.value };
      }
    } catch (e) {
      const resp = e.response?.data;
      if (resp?.requires_password) {
        return { success: false, requires_password: true, board_name: resp.board_name };
      }
      if (resp?.expired) {
        return { success: false, expired: true, board_name: resp.board_name };
      }
      console.error('Failed to load shared board:', e);
      return { success: false, not_found: true };
    } finally {
      boardLoading.value = false;
    }
  }

  /**
   * Track a public share view (analytics).
   */
  async function trackPublicView(token, sessionId, referrer) {
    try {
      await api.post(`/mood-boards/share/${token}/track`, {
        session_id: sessionId,
        referrer: referrer || document.referrer || null
      });
    } catch (e) {
      // Silent fail — analytics shouldn't break the view
    }
  }

  /**
   * Send heartbeat to update view duration.
   */
  async function sendPublicHeartbeat(token, sessionId, durationSeconds, slidesViewed = 0) {
    try {
      await api.put(`/mood-boards/share/${token}/heartbeat`, {
        session_id: sessionId,
        duration: durationSeconds,
        slides_viewed: slidesViewed
      });
    } catch (e) {
      // Silent fail
    }
  }

  // ========================================
  // CANVAS STROKES (inline drawing)
  // ========================================

  async function saveCanvasStrokes(strokes) {
    if (!currentBoard.value) return false;
    try {
      const json = JSON.stringify(strokes);
      const response = await api.put(
        `/mood-boards/${currentBoard.value.id}`,
        { canvas_strokes: json }
      );
      if (response.data.success) {
        currentBoard.value.canvas_strokes = json;
        return true;
      }
    } catch (e) {
      console.error("Failed to save canvas strokes:", e);
    }
    return false;
  }

  // ========================================
  // REAL-TIME COLLABORATION (WebSocket) — delegated to service module
  // ========================================

  const _wsService = setupWebSocketService({
    currentBoard, selectedItemIds, isDragging, draggedItemIds,
    _locallyCreatedIds, getPendingAddCount: () => _pendingAddCount,
    _isLocallyEdited, panX, panY, zoom,
    updateItem, addActivityEntry, reloadBoardData
  })
  const { collaborators, subscribeToBoardEvents, unsubscribeFromBoardEvents, sendCursorPosition, onCommentEvent } = _wsService

  
  // Auto-clean stale collaborators (no cursor update in 30s)
  let _staleCollabTimer = setInterval(() => {
    const now = Date.now()
    collaborators.value = collaborators.value.filter(c => now - c.lastSeen < 30000)
  }, 10000)

  // ========================================
  // ACTIVITY LOG
  // ========================================
  
  const activities = ref([])
  const activitiesLoading = ref(false)
  
  async function fetchActivities(boardId, limit = 100) {
    if (!boardId) return
    activitiesLoading.value = true
    try {
      const response = await api.get(`/mood-boards/${boardId}/activity?limit=${limit}`)
      if (response.data.success) {
        activities.value = response.data.data.activities || []
      }
    } catch (e) {
      isDebugEnabled() && console.error('[MoodBoard] Failed to fetch activities:', e)
    } finally {
      activitiesLoading.value = false
    }
  }
  
  function addActivityEntry(entry) {
    // Prepend new entry (most recent first)
    activities.value.unshift(entry)
    // Keep max 200 entries in memory
    if (activities.value.length > 200) {
      activities.value = activities.value.slice(0, 200)
    }
  }

  // ========================================
  // SNAPSHOTS & TRASH
  // ========================================

  const snapshots = ref([])
  const trashItems = ref([])

  async function fetchSnapshots() {
    if (!currentBoard.value || isPublicView.value) return
    try {
      const response = await api.get(`${boardApiBase()}/snapshots`)
      if (response.data.success) {
        snapshots.value = response.data.data.snapshots || []
      }
    } catch (e) {
      console.error('Failed to fetch snapshots:', e)
    }
  }

  async function createManualSnapshot(label) {
    if (!currentBoard.value || isPublicView.value) return null
    try {
      const response = await api.post(`${boardApiBase()}/snapshots`, { label: label || 'Manual save' })
      if (response.data.success) {
        await fetchSnapshots()
        return response.data.data.snapshot_id
      }
    } catch (e) {
      console.error('Failed to create snapshot:', e)
    }
    return null
  }

  async function restoreSnapshot(snapshotId) {
    if (!currentBoard.value || isPublicView.value) return false
    try {
      const response = await api.post(`${boardApiBase()}/snapshots/${snapshotId}/restore`)
      if (response.data.success) {
        const boardId = currentBoard.value.id
        await reloadBoardData(boardId)
        return true
      }
    } catch (e) {
      console.error('Failed to restore snapshot:', e)
    }
    return false
  }

  async function fetchTrash() {
    if (!currentBoard.value || isPublicView.value) return
    try {
      const response = await api.get(`${boardApiBase()}/trash`)
      if (response.data.success) {
        trashItems.value = response.data.data.items || []
      }
    } catch (e) {
      console.error('Failed to fetch trash:', e)
    }
  }

  async function restoreFromTrash(itemIds) {
    if (!currentBoard.value || !itemIds?.length || isPublicView.value) return false
    try {
      const response = await api.post(`${boardApiBase()}/items/restore-batch`, { item_ids: itemIds })
      if (response.data.success) {
        const boardId = currentBoard.value.id
        await reloadBoardData(boardId)
        return true
      }
    } catch (e) {
      console.error('Failed to restore from trash:', e)
    }
    return false
  }

  // ========================================
  // AI GENERATION
  // ========================================

  const aiGenerating = ref(false)

  function _getViewportCenter() {
    const vw = window.innerWidth - 300
    const vh = window.innerHeight - 120
    return {
      x: Math.round((vw / 2 - panX.value) / zoom.value),
      y: Math.round((vh / 2 - panY.value) / zoom.value),
    }
  }

  async function aiGenerate(prompt, { referenceImage } = {}) {
    if (!currentBoard.value || !prompt?.trim() || isPublicView.value) return null
    aiGenerating.value = true
    try {
      const center = _getViewportCenter()
      const body = {
        prompt,
        viewport_center_x: center.x,
        viewport_center_y: center.y,
      }
      if (referenceImage) body.reference_image = referenceImage
      const response = await api.post(`${boardApiBase()}/ai/generate`, body)
      if (response.data.success && response.data.data?.items) {
        const serverItems = response.data.data.items
        for (const item of serverItems) {
          const exists = currentBoard.value.items.find(i => i.id === item.id)
          if (!exists) {
            currentBoard.value.items.push(item)
          }
        }
        return { success: true, items: serverItems, usage: response.data.usage }
      }
      return { success: false, error: response.data.message || 'Generation failed' }
    } catch (e) {
      const msg = e.response?.data?.message || e.message || 'AI generation failed'
      return { success: false, error: msg }
    } finally {
      aiGenerating.value = false
    }
  }

  async function aiModify(prompt, items, { referenceImage } = {}) {
    if (!currentBoard.value || !prompt?.trim() || !items?.length || isPublicView.value) return null
    aiGenerating.value = true
    try {
      const payload = items.map(i => ({
        id: i.id,
        type: i.type,
        pos_x: i.pos_x,
        pos_y: i.pos_y,
        width: i.width,
        height: i.height,
        content: i.content || '',
        style_data: i.style_data || {},
      }))
      const body = { prompt, items: payload }
      if (referenceImage) body.reference_image = referenceImage
      const response = await api.post(`${boardApiBase()}/ai/modify`, body)
      if (response.data.success && response.data.data?.items) {
        const updatedItems = response.data.data.items
        for (const updated of updatedItems) {
          const idx = currentBoard.value.items.findIndex(i => i.id === updated.id)
          if (idx !== -1) {
            currentBoard.value.items[idx] = { ...currentBoard.value.items[idx], ...updated }
          }
        }
        return { success: true, items: updatedItems, usage: response.data.usage }
      }
      return { success: false, error: response.data.message || 'Modification failed' }
    } catch (e) {
      const msg = e.response?.data?.message || e.message || 'AI modification failed'
      return { success: false, error: msg }
    } finally {
      aiGenerating.value = false
    }
  }

  async function aiVariations(prompt, items, count = 5) {
    if (!currentBoard.value || !items?.length || isPublicView.value) return null
    aiGenerating.value = true
    try {
      const payload = items.map(i => ({
        id: i.id,
        type: i.type,
        pos_x: i.pos_x,
        pos_y: i.pos_y,
        width: i.width,
        height: i.height,
        content: i.content || '',
        style_data: i.style_data || {},
      }))
      const response = await api.post(`${boardApiBase()}/ai/variations`, {
        prompt: prompt || '',
        items: payload,
        count,
      })
      if (response.data.success && response.data.data?.items) {
        const serverItems = response.data.data.items
        for (const item of serverItems) {
          const exists = currentBoard.value.items.find(i => i.id === item.id)
          if (!exists) {
            currentBoard.value.items.push(item)
          }
        }
        return {
          success: true,
          items: serverItems,
          variationCount: response.data.data.variation_count,
          usage: response.data.usage,
        }
      }
      return { success: false, error: response.data.message || 'Variation generation failed' }
    } catch (e) {
      const msg = e.response?.data?.message || e.message || 'AI variation failed'
      return { success: false, error: msg }
    } finally {
      aiGenerating.value = false
    }
  }

  // ========================================
  // RESET
  // ========================================

  function $reset() {
    activities.value = []
    unsubscribeFromBoardEvents()
    // Stop the stale collaborator cleanup timer
    if (_staleCollabTimer) {
      clearInterval(_staleCollabTimer)
      _staleCollabTimer = null
    }
    // Clear pending debounce timers so they don't fire after reset
    clearTimeout(_guideSaveTimer)
    _motionService.clearMotionSaveTimer()
    clearTimeout(_brushSettingsTimer)
    _clearComponentPushTimers()
    boards.value = [];
    folders.value = [];
    expandedFolderIds.value = new Set();
    currentBoard.value = null;
    loading.value = false;
    boardLoading.value = false;
    zoom.value = 1;
    panX.value = 0;
    panY.value = 0;
    selectedItemIds.value = new Set();
    isDragging.value = false;
    draggedItemIds.value = new Set();
    isPanning.value = false;
    connectingFrom.value = null;
    presentationMode.value = false;
    currentSlideIndex.value = 0;
    showFilmstrip.value = false;
    focusedSlideId.value = null;
    followingUser.value = null;
    followMode.value = 'cursor';
    focusedItemId.value = null;
    scrollStoryMode.value = false;
    scrollStoryProgress.value = 0;
    isPublicView.value = false;
    publicShareMode.value = 'view';
    publicShareToken.value = null;
    motionEnabled.value = false;
    motionCards.value = true;
    motionElements.value = true;
    motionLines.value = false;
    motionIntensity.value = 1.5;
    motionCardIntensity.value = 1;
    motionSpeed.value = 1;
    motionLineWave.value = 2;
    motionLineSpeed.value = 0.5;
    motionLineDensity.value = 0.3;
    motionDrawOn.value = true;
    motionDrawOnTrigger.value = 'viewport';
    motionDrawOnSpeed.value = 1;
    slidesVisible.value = false;
    clearHistory();
  }

  // ========================================
  // COMPONENT INSTANCES (linked placement)
  // ========================================

  async function pushComponentChanges(componentId) {
    try {
      const res = await api.post(`/mood-boards/components/${componentId}/push`)
      if (res.data.success && currentBoard.value) {
        // Reload the board to reflect pushed changes
        await fetchBoard(currentBoard.value.id)
      }
      return res.data
    } catch (e) {
      console.error('Push component changes failed:', e)
      return { success: false }
    }
  }

  async function detachComponentInstance(instanceId) {
    if (!currentBoard.value || isPublicView.value) return false
    try {
      const res = await api.post(`${boardApiBase()}/items/detach-component`, {
        instance_id: instanceId,
      })
      if (res.data.success) {
        // Clear component link from local items
        for (const item of currentBoard.value.items) {
          if (item.component_instance_id === instanceId) {
            item.component_id = null
            item.component_instance_id = null
            item.component_item_index = null
          }
        }
      }
      return res.data.success
    } catch (e) {
      console.error('Detach component instance failed:', e)
      return false
    }
  }

  function getInstanceItems(instanceId) {
    if (!currentBoard.value) return []
    return currentBoard.value.items.filter(i => i.component_instance_id === instanceId)
  }

  async function updateComponentFromSelection(componentId) {
    const items = selectedItems.value
    if (!items.length) return null
    const minX = Math.min(...items.map(i => i.pos_x || 0))
    const minY = Math.min(...items.map(i => i.pos_y || 0))
    const itemsData = items.map(item => {
      const clone = deepClone(item)
      delete clone.id
      delete clone.board_id
      delete clone.created_at
      delete clone.updated_at
      delete clone.component_id
      delete clone.component_instance_id
      delete clone.component_item_index
      clone.pos_x = (clone.pos_x || 0) - minX
      clone.pos_y = (clone.pos_y || 0) - minY
      return clone
    })
    try {
      const res = await api.put(`/mood-boards/components/${componentId}`, {
        items_data: itemsData,
      })
      return res.data.success ? res.data.data : null
    } catch (e) {
      console.error('Update component from selection failed:', e)
      return null
    }
  }

  // ========================================
  // DESIGN TOKENS — delegated to moodBoardGlobalStyles store
  // These remain as thin proxies for backward compatibility.
  // ========================================

  const _designTokensFallback = ref([])
  const designTokens = computed(() => _designTokensFallback.value)

  async function fetchDesignTokens() {
    try {
      const { useMoodBoardGlobalStylesStore } = await import('./moodBoardGlobalStyles')
      const gs = useMoodBoardGlobalStylesStore()
      gs.hydrateFromBoard()
      _designTokensFallback.value = gs.globalColors
    } catch (e) { console.error('fetchDesignTokens delegate error:', e) }
  }

  async function saveDesignTokens(tokens) {
    try {
      const { useMoodBoardGlobalStylesStore } = await import('./moodBoardGlobalStyles')
      const gs = useMoodBoardGlobalStylesStore()
      gs.globalColors.value = tokens
      _designTokensFallback.value = tokens
      await import('../services/globalStylesApi').then(m => m.saveGlobalColors(currentBoard.value?.id, tokens))
    } catch (e) { console.error('saveDesignTokens delegate error:', e) }
  }

  async function addDesignToken(token) {
    try {
      const { useMoodBoardGlobalStylesStore } = await import('./moodBoardGlobalStyles')
      await useMoodBoardGlobalStylesStore().addGlobalColor(token)
    } catch (e) { console.error('addDesignToken delegate error:', e) }
  }

  async function removeDesignToken(tokenId) {
    try {
      const { useMoodBoardGlobalStylesStore } = await import('./moodBoardGlobalStyles')
      await useMoodBoardGlobalStylesStore().removeGlobalColor(tokenId)
    } catch (e) { console.error('removeDesignToken delegate error:', e) }
  }

  async function updateDesignTokenColor(tokenId, newColor) {
    try {
      const { useMoodBoardGlobalStylesStore } = await import('./moodBoardGlobalStyles')
      await useMoodBoardGlobalStylesStore().updateGlobalColorValue(tokenId, newColor)
    } catch (e) { console.error('updateDesignTokenColor delegate error:', e) }
  }

  return {
    // State
    boards,
    currentBoard,
    loading,
    boardLoading,
    zoom,
    panX,
    panY,
    selectedItemIds,
    isDragging,
    draggedItemIds,
    isPanning,
    connectingFrom,
    presentationMode,
    currentSlideIndex,
    showFilmstrip,
    focusedSlideId,
    focusedFrameId: focusedSlideId, // backward-compat alias
    presShowLines,
    presShowBackground,
    followingUser,
    followMode,
    focusedItemId,
    scrollStoryMode,
    scrollStoryProgress,

    // Folders
    folders,
    expandedFolderIds,
    folderTree,
    unfiledBoards,
    fetchFolders,
    createFolder,
    updateFolder,
    deleteFolder,
    moveBoard,
    toggleFolder,

    // Text CSV
    exportTexts,
    importTexts,

    // Presentation export
    exportPresentation,
    exportPptx,
    exportPdf,

    // Computed
    activeBoards,
    archivedBoards,
    currentItems,
    itemMap,
    currentConnections,
    currentMembers,
    currentGroups,
    getItemById,
    getChildrenOf,
    currentLinkedBoards,
    selectedItems,
    presentationSlides,
    presentationFrames, // backward-compat alias

    // Board actions
    fetchBoards,
    fetchBoard,
    reloadBoardData,
    createBoard,
    updateBoard,
    deleteBoard,
    duplicateBoard,
    toggleReady,
    saveViewport,

    // Item actions
    addItem,
    updateItem,
    batchUpdateItems,
    batchAddItems,
    flushPendingUpdates,
    deleteItem,
    deleteSelectedItems,

    // Upload actions
    uploadFiles,
    importDriveFile,

    // Drawing actions
    saveDrawing,

    // Image set actions
    addImageToSet,
    addImagesToSetBatch,
    removeImageFromSet,

    // Todo actions
    addTodo,
    updateTodo,
    deleteTodo,

    // Connection actions
    addConnection,
    updateConnection,
    deleteConnection,
    purgeOrphanConnections,

    // Member management
    addMember,
    updateMemberRole,
    removeMember,

    // Group access
    addGroupAccess,
    removeGroupAccess,

    // Board linking
    linkToKanbanBoard,
    unlinkFromKanbanBoard,
    fetchMoodBoardsForKanban,

    // Selection
    editingGroupId,
    selectItem,
    enterGroup,
    exitGroup,
    clearSelection,
    selectAll,
    bringToFront,
    sendToBack,
    moveForward,
    moveBackward,
    reorderZIndex,
    reorderGroupZIndex,

    // Alignment
    alignItems,
    selectSimilar,

    // Item grouping & frame conversion
    groupSelectedItems,
    ungroupSelectedItems,
    createRepeatGrid,
    dissolveRepeatGrid,
    convertToFrame,
    selectGroup,
    selectionHasGroups,

    // Boolean shape operations
    canBooleanOp,
    booleanOp,

    // Clipping mask
    maskSelectedItems,
    unmaskItems,
    canMaskSelection,
    isMaskContainer,

    // Component instances
    pushComponentChanges,
    detachComponentInstance,
    getInstanceItems,
    updateComponentFromSelection,

    // Design tokens
    designTokens,
    fetchDesignTokens,
    saveDesignTokens,
    addDesignToken,
    removeDesignToken,
    updateDesignTokenColor,

    // Color palette
    getColorPalette,
    saveColorPalette,
    addToPalette,
    removeFromPalette,

    // Gradient palette
    getGradientPalette,
    saveGradientPalette,
    addGradientTopalette,
    removeGradientFromPalette,

    // User palettes (shareable across boards)
    userPalettes,
    userPalettesLoading,
    fetchUserPalettes,
    createUserPalette,
    updateUserPalette,
    deleteUserPalette,
    saveBoardAsUserPalette,
    applyUserPaletteToBoard,

    // Brush presets & settings
    getBrushPresets,
    saveBrushPresets,
    addBrushPreset,
    removeBrushPreset,
    getBrushSettings,
    saveBrushSettings,

    // Background effects
    getBackgroundEffect,
    saveBackgroundEffect,

    // Clipboard
    clipboard,
    copySelectedItems,
    loadClipboardFromText,
    pasteItems,
    duplicateSelectedItems,

    // Undo/Redo
    undo,
    redo,
    pushUndo,
    clearHistory,
    undoStack,
    redoStack,

    // Client boards
    fetchClientBoards,
    linkToClient,
    unlinkFromClient,

    // Canvas strokes
    saveCanvasStrokes,

    // Activity log
    activities,
    activitiesLoading,
    fetchActivities,
    addActivityEntry,

    // Snapshots & trash
    snapshots,
    trashItems,
    fetchSnapshots,
    createManualSnapshot,
    restoreSnapshot,
    fetchTrash,
    restoreFromTrash,

    // Collaboration
    collaborators,
    subscribeToBoardEvents,
    unsubscribeFromBoardEvents,
    sendCursorPosition,
    onCommentEvent,

    // Presentation
    startPresentation,
    stopPresentation,
    nextSlide,
    prevSlide,
    goToSlide,

    // Public sharing
    isPublicView,
    publicShareMode,
    publicShareToken,
    createShareLink,
    updateShareLink,
    removeShareLink,
    fetchShareStats,
    fetchSharedBoards,
    loadSharedBoard,
    trackPublicView,
    sendPublicHeartbeat,

    // Follow user
    startFollowing,
    stopFollowing,

    // Focus mode
    setFocusItem,
    clearFocusItem,
    toggleFocusItem,

    // Ambient motion
    motionEnabled,
    motionCards,
    motionElements,
    motionLines,
    motionIntensity,
    motionCardIntensity,
    motionSpeed,
    motionLineWave,
    motionLineSpeed,
    motionLineDensity,
    motionDrawOn,
    motionDrawOnTrigger,
    motionDrawOnSpeed,
    slidesVisible,
    framesVisible: slidesVisible, // backward-compat alias

    // Rulers & Guides
    showRulers,
    showGuides,
    guides,
    addGuide,
    removeGuide,
    moveGuide,
    clearAllGuides,
    restoreGuides,

    // AI generation, modification & variations
    aiGenerating,
    aiGenerate,
    aiModify,
    aiVariations,

    $reset,
  };
});

