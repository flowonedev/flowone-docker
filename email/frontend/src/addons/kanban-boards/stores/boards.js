import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/services/api";
import { isDebugEnabled } from "@/utils/debug";
import { useSearchStore } from "@/addons/universal-search/stores/search";
import { useAddons } from "@/composables/useAddons";
import { withOfflineFallback, getOfflineBoards } from "@/services/offlineData";

export const useBoardsStore = defineStore("boards", () => {
  // State
  const boards = ref([]);
  const currentBoard = ref(null);
  const loading = ref(false);
  const boardLoading = ref(false);
  const viewMode = ref("board"); // 'board', 'table', 'calendar', 'timeline', 'financials'

  // Cache for email-board links (key: "uid:folder") - using reactive object for proper reactivity
  const emailBoardCache = ref({});

  // Computed
  const activeBoards = computed(() => boards.value.filter((b) => !b.archived));
  const archivedBoards = computed(() => boards.value.filter((b) => b.archived));

  const currentLists = computed(() => currentBoard.value?.lists || []);
  const currentLabels = computed(() => currentBoard.value?.labels || []);
  const currentMembers = computed(() => currentBoard.value?.members || []);

  // Permission checks for current user
  const canViewFinancials = computed(() => {
    if (!currentBoard.value) return false;
    return currentBoard.value.can_view_financials === true;
  });
  
  const canViewClient = computed(() => {
    if (!currentBoard.value) return false;
    return currentBoard.value.can_view_client === true;
  });
  
  const canViewContacts = computed(() => {
    if (!currentBoard.value) return false;
    return currentBoard.value.can_view_contacts === true;
  });
  
  const canViewEmails = computed(() => {
    if (!currentBoard.value) return false;
    return currentBoard.value.can_view_emails === true;
  });
  
  const canAccessDrive = computed(() => {
    if (!currentBoard.value) return false;
    return currentBoard.value.can_access_drive === true;
  });

  // Get all cards across all lists for the current board
  const allCards = computed(() => {
    if (!currentBoard.value?.lists) return [];
    return currentBoard.value.lists.flatMap((list) =>
      list.cards
        .filter((card) => !card.parent_card_id)
        .map((card) => ({
        ...card,
        list_name: list.name,
        list_id: list.id,
      }))
    );
  });

  // Cards grouped by due date status
  const cardsByDueStatus = computed(() => {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const nextWeek = new Date(today);
    nextWeek.setDate(nextWeek.getDate() + 7);

    return {
      overdue: allCards.value.filter(
        (c) => c.due_date && new Date(c.due_date) < today && !c.completed
      ),
      today: allCards.value.filter((c) => {
        if (!c.due_date || c.completed) return false;
        const due = new Date(c.due_date);
        return due >= today && due < tomorrow;
      }),
      thisWeek: allCards.value.filter((c) => {
        if (!c.due_date || c.completed) return false;
        const due = new Date(c.due_date);
        return due >= tomorrow && due < nextWeek;
      }),
      later: allCards.value.filter((c) => {
        if (!c.due_date || c.completed) return false;
        return new Date(c.due_date) >= nextWeek;
      }),
      noDue: allCards.value.filter((c) => !c.due_date && !c.completed),
    };
  });

  // ========================================
  // BOARD ACTIONS
  // ========================================

  async function fetchBoards(includeArchived = false) {
    const { kanbanBoardsEnabled } = useAddons();
    if (!kanbanBoardsEnabled.value) return;

    loading.value = true;
    try {
      const result = await withOfflineFallback(
        async () => {
          const response = await api.get("/boards", {
            params: { include_archived: includeArchived },
          });
          if (response.data.success) return response.data.data.boards;
          return null;
        },
        async () => {
          return await getOfflineBoards();
        }
      );
      if (result) {
        boards.value = result;
      }
    } catch (e) {
      console.error("Failed to fetch boards:", e);
    } finally {
      loading.value = false;
    }
  }

  async function fetchBoard(boardId, options = {}) {
    // Skip when kanban boards addon is disabled
    const { kanbanBoardsEnabled } = useAddons();
    if (!kanbanBoardsEnabled.value) return;

    const { silent = false } = options;
    boardLoading.value = true;
    try {
      const response = await api.get(`/boards/${boardId}`);
      if (response.data.success) {
        currentBoard.value = response.data.data.board;
        return response.data.data.board;
      }
    } catch (e) {
      // Don't log 404s as errors - board may have been deleted
      if (!silent && e.response?.status !== 404) {
        console.error("Failed to fetch board:", e);
      }
    } finally {
      boardLoading.value = false;
    }
    return null;
  }

  async function createBoard(data) {
    try {
      const response = await api.post("/boards", data);
      if (response.data.success) {
        boards.value.unshift(response.data.data.board);
        return response.data.data.board;
      }
    } catch (e) {
      console.error("Failed to create board:", e);
    }
    return null;
  }

  async function updateBoard(boardId, data) {
    try {
      const response = await api.put(`/boards/${boardId}`, data);
      if (response.data.success) {
        const updatedBoard = response.data.data.board;
        const index = boards.value.findIndex((b) => b.id === boardId);
        if (index !== -1) {
          boards.value[index] = { ...boards.value[index], ...updatedBoard };
        }
        if (currentBoard.value?.id === boardId) {
          currentBoard.value = { ...currentBoard.value, ...updatedBoard };
        }
        return updatedBoard;
      }
    } catch (e) {
      console.error("Failed to update board:", e);
    }
    return null;
  }

  async function deleteBoard(boardId) {
    try {
      const response = await api.delete(`/boards/${boardId}`);
      if (response.data.success) {
        boards.value = boards.value.filter((b) => b.id !== boardId);
        if (currentBoard.value?.id === boardId) {
          currentBoard.value = null;
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to delete board:", e);
    }
    return false;
  }

  async function archiveBoard(boardId) {
    return updateBoard(boardId, { archived: true });
  }

  async function unarchiveBoard(boardId) {
    return updateBoard(boardId, { archived: false });
  }

  async function closeBoard(boardId) {
    try {
      const response = await api.post(`/boards/${boardId}/close`);
      if (response.data.success) {
        const updated = response.data.data.board;
        const idx = boards.value.findIndex(b => b.id === boardId);
        if (idx !== -1) boards.value[idx] = { ...boards.value[idx], ...updated };
        if (currentBoard.value?.id === boardId) Object.assign(currentBoard.value, updated);
        return updated;
      }
    } catch (e) {
      console.error('Failed to close board:', e);
    }
    return null;
  }

  async function reopenBoard(boardId) {
    try {
      const response = await api.post(`/boards/${boardId}/reopen`);
      if (response.data.success) {
        const updated = response.data.data.board;
        const idx = boards.value.findIndex(b => b.id === boardId);
        if (idx !== -1) boards.value[idx] = { ...boards.value[idx], ...updated };
        if (currentBoard.value?.id === boardId) Object.assign(currentBoard.value, updated);
        return updated;
      }
    } catch (e) {
      console.error('Failed to reopen board:', e);
    }
    return null;
  }

  // ========================================
  // MEMBER ACTIONS
  // ========================================

  async function addMember(boardId, email, role = "editor") {
    try {
      const response = await api.post(`/boards/${boardId}/members`, {
        email,
        role,
      });
      if (response.data.success) {
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.members = response.data.data.members;
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to add member:", e);
    }
    return false;
  }

  async function updateMemberRole(boardId, email, role) {
    try {
      const response = await api.put(
        `/boards/${boardId}/members/${encodeURIComponent(email)}`,
        { role }
      );
      return response.data.success;
    } catch (e) {
      console.error("Failed to update member:", e);
    }
    return false;
  }

  async function removeMember(boardId, email) {
    try {
      const response = await api.delete(
        `/boards/${boardId}/members/${encodeURIComponent(email)}`
      );
      if (response.data.success && currentBoard.value?.id === boardId) {
        currentBoard.value.members = currentBoard.value.members.filter(
          (m) => m.email.toLowerCase() !== email.toLowerCase()
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to remove member:", e);
    }
    return false;
  }

  // ========================================
  // LIST ACTIONS
  // ========================================

  async function createList(boardId, data) {
    try {
      const response = await api.post(`/boards/${boardId}/lists`, data);
      if (response.data.success) {
        const newList = response.data.data.list;
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.lists.push(newList);
        }
        return newList;
      }
    } catch (e) {
      console.error("Failed to create list:", e);
    }
    return null;
  }

  async function updateList(listId, data) {
    try {
      const response = await api.put(`/boards/lists/${listId}`, data);
      if (response.data.success) {
        const updatedList = response.data.data.list;
        if (currentBoard.value) {
          const index = currentBoard.value.lists.findIndex(
            (l) => l.id === listId
          );
          if (index !== -1) {
            currentBoard.value.lists[index] = updatedList;
          }
        }
        return updatedList;
      }
    } catch (e) {
      console.error("Failed to update list:", e);
    }
    return null;
  }

  async function deleteList(listId) {
    try {
      const response = await api.delete(`/boards/lists/${listId}`);
      if (response.data.success && currentBoard.value) {
        currentBoard.value.lists = currentBoard.value.lists.filter(
          (l) => l.id !== listId
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to delete list:", e);
    }
    return false;
  }

  async function reorderLists(boardId, listIds) {
    // Optimistic update
    if (currentBoard.value?.id === boardId) {
      const reordered = listIds
        .map((id) => currentBoard.value.lists.find((l) => l.id === id))
        .filter(Boolean);
      currentBoard.value.lists = reordered;
    }

    try {
      const response = await api.post("/boards/lists/reorder", {
        board_id: boardId,
        list_ids: listIds,
      });
      return response.data.success;
    } catch (e) {
      console.error("Failed to reorder lists:", e);
      // Reload board on failure
      await fetchBoard(boardId);
    }
    return false;
  }

  // ========================================
  // CARD ACTIONS
  // ========================================

  async function createCard(listId, data) {
    try {
      const response = await api.post(`/boards/lists/${listId}/cards`, data);
      if (response.data.success) {
        const newCard = response.data.data.card;
        if (currentBoard.value) {
          const list = currentBoard.value.lists.find((l) => l.id === listId);
          if (list) {
            list.cards.push(newCard);
          }
        }
        // Auto-index the new card for search
        const searchStore = useSearchStore();
        searchStore.indexItem('card', newCard.id, newCard);
        return newCard;
      }
    } catch (e) {
      console.error("Failed to create card:", e);
    }
    return null;
  }

  async function getCard(cardId) {
    try {
      const response = await api.get(`/boards/cards/${cardId}`);
      if (response.data.success) {
        return response.data.data.card;
      }
    } catch (e) {
      console.error("Failed to get card:", e);
    }
    return null;
  }

  async function updateCard(cardId, data) {
    try {
      const response = await api.put(`/boards/cards/${cardId}`, data);
      if (response.data.success) {
        const updatedCard = response.data.data.card;
        updateCardInState(cardId, updatedCard);
        // Re-index the updated card for search
        const searchStore = useSearchStore();
        searchStore.indexItem('card', cardId, updatedCard);
        return updatedCard;
      }
    } catch (e) {
      console.error("Failed to update card:", e);
    }
    return null;
  }

  async function deleteCard(cardId) {
    try {
      const response = await api.delete(`/boards/cards/${cardId}`);
      if (response.data.success && currentBoard.value) {
        for (const list of currentBoard.value.lists) {
          const index = list.cards.findIndex((c) => c.id === cardId);
          if (index !== -1) {
            list.cards.splice(index, 1);
            break;
          }
        }
        // Remove from search index
        const searchStore = useSearchStore();
        searchStore.removeFromIndex('card', cardId);
        return true;
      }
    } catch (e) {
      console.error("Failed to delete card:", e);
    }
    return false;
  }

  async function moveCard(cardId, newListId, position = null) {
    isDebugEnabled() && console.log("[moveCard] Called:", { cardId, newListId, position });

    // Find old location for optimistic rollback
    let oldListId = null;
    let oldIndex = -1;
    let cardData = null;

    if (currentBoard.value) {
      for (const list of currentBoard.value.lists) {
        const idx = list.cards.findIndex((c) => c.id === cardId);
        if (idx !== -1) {
          oldListId = list.id;
          oldIndex = idx;
          cardData = { ...list.cards[idx] }; // Clone the card to avoid reference issues
          isDebugEnabled() && console.log("[moveCard] Found card at:", {
            listId: list.id,
            listName: list.name,
            index: idx,
            cardTitle: cardData.title,
          });
          // Optimistic remove from old list
          list.cards.splice(idx, 1);
          break;
        }
      }

      // Optimistic add to new list
      if (cardData) {
        const newList = currentBoard.value.lists.find(
          (l) => l.id === newListId
        );
        if (newList) {
          isDebugEnabled() && console.log("[moveCard] Moving to list:", {
            listId: newList.id,
            listName: newList.name,
            position,
          });
          if (position !== null) {
            newList.cards.splice(position, 0, cardData);
          } else {
            newList.cards.push(cardData);
          }
        }
      } else {
        console.warn("[moveCard] Card not found in any list!");
      }
    }

    try {
      const response = await api.post(`/boards/cards/${cardId}/move`, {
        list_id: newListId,
        position,
      });

      if (response.data.success) {
        // Update with server response
        updateCardInState(cardId, response.data.data.card);
        return response.data.data.card;
      }
    } catch (e) {
      console.error("Failed to move card:", e);
      // Rollback on failure
      if (oldListId !== null && cardData && currentBoard.value) {
        // Remove from new position
        const newList = currentBoard.value.lists.find(
          (l) => l.id === newListId
        );
        if (newList) {
          const newIdx = newList.cards.findIndex((c) => c.id === cardId);
          if (newIdx !== -1) {
            newList.cards.splice(newIdx, 1);
          }
        }
        // Restore to old position
        const oldList = currentBoard.value.lists.find(
          (l) => l.id === oldListId
        );
        if (oldList) {
          oldList.cards.splice(oldIndex, 0, cardData);
        }
      }
    }
    return null;
  }

  async function reorderCards(listId, cardIds) {
    // Optimistic update
    if (currentBoard.value) {
      const list = currentBoard.value.lists.find((l) => l.id === listId);
      if (list) {
        const reordered = cardIds
          .map((id) => list.cards.find((c) => c.id === id))
          .filter(Boolean);
        list.cards = reordered;
      }
    }

    try {
      const response = await api.post("/boards/cards/reorder", {
        list_id: listId,
        card_ids: cardIds,
      });
      return response.data.success;
    } catch (e) {
      console.error("Failed to reorder cards:", e);
      // Reload board on failure
      if (currentBoard.value) {
        await fetchBoard(currentBoard.value.id);
      }
    }
    return false;
  }

  // Helper to update card in state
  function updateCardInState(cardId, updatedCard) {
    if (!currentBoard.value) return;

    for (const list of currentBoard.value.lists) {
      const index = list.cards.findIndex((c) => c.id === cardId);
      if (index !== -1) {
        list.cards[index] = { ...list.cards[index], ...updatedCard };
        return;
      }
    }
  }

  // ========================================
  // LABEL ACTIONS
  // ========================================

  async function createLabel(boardId, data) {
    try {
      const response = await api.post(`/boards/${boardId}/labels`, data);
      if (response.data.success) {
        const newLabel = response.data.data.label;
        if (currentBoard.value?.id === boardId) {
          currentBoard.value.labels.push(newLabel);
        }
        return newLabel;
      }
    } catch (e) {
      console.error("Failed to create label:", e);
    }
    return null;
  }

  async function updateLabel(labelId, data) {
    try {
      const response = await api.put(`/boards/labels/${labelId}`, data);
      if (response.data.success) {
        const updatedLabel = response.data.data.label;
        if (currentBoard.value) {
          const index = currentBoard.value.labels.findIndex(
            (l) => l.id === labelId
          );
          if (index !== -1) {
            currentBoard.value.labels[index] = updatedLabel;
          }
        }
        return updatedLabel;
      }
    } catch (e) {
      console.error("Failed to update label:", e);
    }
    return null;
  }

  async function deleteLabel(labelId) {
    try {
      const response = await api.delete(`/boards/labels/${labelId}`);
      if (response.data.success && currentBoard.value) {
        currentBoard.value.labels = currentBoard.value.labels.filter(
          (l) => l.id !== labelId
        );
        return true;
      }
    } catch (e) {
      console.error("Failed to delete label:", e);
    }
    return false;
  }

  async function addLabelToCard(cardId, labelId) {
    try {
      const response = await api.post(`/boards/cards/${cardId}/labels`, {
        label_id: labelId,
      });
      if (response.data.success) {
        // Find card and add label
        if (currentBoard.value) {
          const label = currentBoard.value.labels.find((l) => l.id === labelId);
          for (const list of currentBoard.value.lists) {
            const card = list.cards.find((c) => c.id === cardId);
            if (card && label) {
              if (!card.labels) card.labels = [];
              if (!card.labels.find((l) => l.id === labelId)) {
                card.labels.push(label);
              }
              break;
            }
          }
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to add label to card:", e);
    }
    return false;
  }

  async function removeLabelFromCard(cardId, labelId) {
    try {
      const response = await api.delete(
        `/boards/cards/${cardId}/labels/${labelId}`
      );
      if (response.data.success && currentBoard.value) {
        for (const list of currentBoard.value.lists) {
          const card = list.cards.find((c) => c.id === cardId);
          if (card && card.labels) {
            card.labels = card.labels.filter((l) => l.id !== labelId);
            break;
          }
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to remove label from card:", e);
    }
    return false;
  }

  // ========================================
  // CHECKLIST ACTIONS
  // ========================================

  async function createChecklist(cardId, title = "Checklist") {
    try {
      const response = await api.post(`/boards/cards/${cardId}/checklists`, {
        title,
      });
      if (response.data.success) {
        return response.data.data.checklist;
      }
    } catch (e) {
      console.error("Failed to create checklist:", e);
    }
    return null;
  }

  async function updateChecklist(checklistId, data) {
    try {
      const response = await api.put(`/boards/checklists/${checklistId}`, data);
      if (response.data.success) {
        return response.data.data.checklist;
      }
    } catch (e) {
      console.error("Failed to update checklist:", e);
    }
    return null;
  }

  async function deleteChecklist(checklistId) {
    try {
      const response = await api.delete(`/boards/checklists/${checklistId}`);
      return response.data.success;
    } catch (e) {
      console.error("Failed to delete checklist:", e);
    }
    return false;
  }

  async function addChecklistItem(checklistId, title) {
    try {
      const response = await api.post(
        `/boards/checklists/${checklistId}/items`,
        { title }
      );
      if (response.data.success) {
        return response.data.data.item;
      }
    } catch (e) {
      console.error("Failed to add checklist item:", e);
    }
    return null;
  }

  async function updateChecklistItem(itemId, data) {
    try {
      const response = await api.put(`/boards/checklist-items/${itemId}`, data);
      if (response.data.success) {
        return response.data.data.item;
      }
    } catch (e) {
      console.error("Failed to update checklist item:", e);
    }
    return null;
  }

  async function deleteChecklistItem(itemId) {
    try {
      const response = await api.delete(`/boards/checklist-items/${itemId}`);
      return response.data.success;
    } catch (e) {
      console.error("Failed to delete checklist item:", e);
    }
    return false;
  }

  async function toggleChecklistItem(itemId, completed) {
    return updateChecklistItem(itemId, { completed });
  }

  // ========================================
  // ATTACHMENT ACTIONS
  // ========================================

  async function uploadAttachment(cardId, file, folderId = null) {
    try {
      const formData = new FormData();
      formData.append("file", file);
      if (folderId) formData.append("folder_id", folderId);

      const response = await api.post(
        `/boards/cards/${cardId}/attachments`,
        formData,
        {
          headers: { "Content-Type": "multipart/form-data" },
        }
      );

      if (response.data.success) {
        return response.data.data.attachment;
      }
    } catch (e) {
      console.error("Failed to upload attachment:", e);
    }
    return null;
  }

  async function addDriveAttachment(cardId, driveFileId, fileName, folderId = null) {
    try {
      const payload = { drive_file_id: driveFileId, file_name: fileName }
      if (folderId) payload.folder_id = folderId
      const response = await api.post(
        `/boards/cards/${cardId}/attachments/drive`,
        payload
      );

      if (response.data.success) {
        return response.data.data.attachment;
      }
    } catch (e) {
      console.error("Failed to add drive attachment:", e);
    }
    return null;
  }

  async function addUrlAttachment(cardId, url, name) {
    try {
      const response = await api.post(
        `/boards/cards/${cardId}/attachments/url`,
        { url, name }
      );
      if (response.data.success) {
        return response.data.data.attachment;
      }
    } catch (e) {
      console.error("Failed to add URL attachment:", e);
    }
    return null;
  }

  async function deleteAttachment(attachmentId) {
    try {
      const response = await api.delete(`/boards/attachments/${attachmentId}`);
      return response.data.success;
    } catch (e) {
      console.error("Failed to delete attachment:", e);
    }
    return false;
  }

  async function setCardCover(cardId, attachmentId) {
    try {
      const response = await api.post(`/boards/cards/${cardId}/cover`, {
        attachment_id: attachmentId,
      });
      return response.data.success;
    } catch (e) {
      console.error("Failed to set card cover:", e);
    }
    return false;
  }

  // ========================================
  // COMMENT ACTIONS
  // ========================================

  async function addComment(cardId, content, parentCommentId = null, mentions = null) {
    try {
      const payload = { content }
      if (parentCommentId) payload.parent_comment_id = parentCommentId
      if (mentions && mentions.length) payload.mentions = mentions
      const response = await api.post(`/boards/cards/${cardId}/comments`, payload);
      if (response.data.success) {
        return response.data.data.comment;
      }
    } catch (e) {
      console.error("Failed to add comment:", e);
    }
    return null;
  }

  async function updateComment(commentId, content) {
    try {
      const response = await api.put(`/boards/comments/${commentId}`, {
        content,
      });
      if (response.data.success) {
        return response.data.data.comment;
      }
    } catch (e) {
      console.error("Failed to update comment:", e);
    }
    return null;
  }

  async function deleteComment(commentId) {
    try {
      const response = await api.delete(`/boards/comments/${commentId}`);
      return response.data.success;
    } catch (e) {
      console.error("Failed to delete comment:", e);
    }
    return false;
  }

  // ========================================
  // SEARCH & FILTER
  // ========================================

  async function searchCards(query, boardId = null) {
    try {
      const params = { q: query };
      if (boardId) params.board_id = boardId;

      const response = await api.get("/boards/search", { params });
      if (response.data.success) {
        return response.data.data.cards;
      }
    } catch (e) {
      console.error("Failed to search cards:", e);
    }
    return [];
  }

  async function getCardsByDueDate(startDate, endDate, boardId = null) {
    try {
      const params = { start_date: startDate, end_date: endDate };
      if (boardId) params.board_id = boardId;

      const response = await api.get("/boards/cards/due", { params });
      if (response.data.success) {
        return response.data.data.cards;
      }
    } catch (e) {
      console.error("Failed to get cards by due date:", e);
    }
    return [];
  }

  async function getAssignedCards(boardId = null) {
    try {
      const params = boardId ? { board_id: boardId } : {};

      const response = await api.get("/boards/cards/assigned", { params });
      if (response.data.success) {
        return response.data.data.cards;
      }
    } catch (e) {
      console.error("Failed to get assigned cards:", e);
    }
    return [];
  }

  // ========================================
  // VIEW MODE
  // ========================================

  function setViewMode(mode) {
    viewMode.value = mode;
    localStorage.setItem("board_view_mode", mode);
  }

  function initViewMode() {
    const saved = localStorage.getItem("board_view_mode");
    if (
      saved &&
      ["board", "table", "calendar", "timeline", "financials", "revenue", "time_tracking", "client_view", "mood_split"].includes(saved)
    ) {
      viewMode.value = saved;
    }
  }

  // ========================================
  // EMAIL-BOARD LINKING
  // ========================================

  async function linkEmailToBoard(boardId, emailData) {
    try {
      isDebugEnabled() && console.log("Linking email to board:", { boardId, emailData });
      const response = await api.post(`/boards/${boardId}/emails`, emailData);
      if (response.data.success) {
        // Clear cache for this email to force re-fetch
        clearEmailBoardCache(emailData.email_uid, emailData.email_folder);
        return response.data.data.link;
      }
      console.error("Link email response error:", response.data);
    } catch (e) {
      console.error("Failed to link email to board:", e);
      if (e.response?.data) {
        console.error("Server error details:", e.response.data);
      }
    }
    return null;
  }

  async function unlinkEmailFromBoard(linkId, emailUid = null, folder = null) {
    try {
      const response = await api.delete(`/boards/emails/${linkId}`);
      if (response.data.success) {
        // Clear cache for this email
        if (emailUid && folder) {
          clearEmailBoardCache(emailUid, folder);
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to unlink email:", e);
    }
    return false;
  }

  async function getBoardEmails(boardId) {
    try {
      const response = await api.get(`/boards/${boardId}/emails`);
      if (response.data.success) {
        return response.data.data.emails;
      }
    } catch (e) {
      console.error("Failed to get board emails:", e);
    }
    return [];
  }

  async function getEmailBoard(emailUid, folder) {
    const { kanbanBoardsEnabled } = useAddons();
    if (!kanbanBoardsEnabled.value) return null;

    const cacheKey = `${emailUid}:${folder}`;

    // Check cache first
    if (cacheKey in emailBoardCache.value) {
      return emailBoardCache.value[cacheKey];
    }

    try {
      const response = await api.get("/boards/email-link", {
        params: { uid: emailUid, folder },
      });
      if (response.data.success) {
        const board = response.data.data.board;
        emailBoardCache.value[cacheKey] = board;
        return board;
      }
    } catch (e) {
      // Cache null result to avoid repeated failed requests
      emailBoardCache.value[cacheKey] = null;
      console.error("Failed to get email board:", e);
    }
    return null;
  }

  // Get cached board link for an email (sync, for display in lists)
  function getCachedEmailBoard(emailUid, folder) {
    const cacheKey = `${emailUid}:${folder}`;
    return emailBoardCache.value[cacheKey] || null;
  }

  // Check if email board link is cached
  function isEmailBoardCached(emailUid, folder) {
    const cacheKey = `${emailUid}:${folder}`;
    return cacheKey in emailBoardCache.value;
  }

  // Batch fetch email board links for a list of emails
  async function fetchEmailBoardsBatch(emails, folder) {
    // Skip entirely when kanban boards addon is disabled
    const { kanbanBoardsEnabled } = useAddons();
    if (!kanbanBoardsEnabled.value) return;

    isDebugEnabled() && console.log(
      "[BoardsStore] fetchEmailBoardsBatch called with",
      emails?.length,
      "emails, folder:",
      folder
    );
    if (!emails || emails.length === 0) return;

    // Filter to only uncached emails
    const uncachedEmails = emails.filter(
      (e) => !isEmailBoardCached(e.uid, folder)
    );
    isDebugEnabled() && console.log("[BoardsStore] Uncached emails:", uncachedEmails.length);
    if (uncachedEmails.length === 0) return;

    try {
      const response = await api.post("/boards/email-links-batch", {
        emails: uncachedEmails.map((e) => ({ uid: e.uid, folder })),
      });

      isDebugEnabled() && console.log("[BoardsStore] Batch response:", response.data);

      if (response.data.success) {
        const links = response.data.data.links || {};
        isDebugEnabled() && console.log(
          "[BoardsStore] Links received:",
          Object.keys(links).length,
          "links:",
          links
        );

        // Cache all results (including nulls for unlinked emails)
        uncachedEmails.forEach((email) => {
          const cacheKey = `${email.uid}:${folder}`;
          emailBoardCache.value[cacheKey] = links[email.uid] || null;
        });
      }
    } catch (e) {
      console.error("Failed to batch fetch email boards:", e);
      // Cache nulls to avoid repeated failures
      uncachedEmails.forEach((email) => {
        const cacheKey = `${email.uid}:${folder}`;
        if (!(cacheKey in emailBoardCache.value)) {
          emailBoardCache.value[cacheKey] = null;
        }
      });
    }
  }

  // Clear email board cache (useful after linking/unlinking)
  function clearEmailBoardCache(emailUid = null, folder = null) {
    if (emailUid && folder) {
      delete emailBoardCache.value[`${emailUid}:${folder}`];
    } else {
      emailBoardCache.value = {};
    }
  }

  // Cache for thread-to-boards mapping
  const threadBoardsCache = ref({});

  async function getBoardsByThread(threadId, forceRefresh = false) {
    const { kanbanBoardsEnabled } = useAddons();
    if (!kanbanBoardsEnabled.value) return [];
    if (!threadId) return [];

    // Return cached if available
    if (!forceRefresh && threadId in threadBoardsCache.value) {
      return threadBoardsCache.value[threadId];
    }

    try {
      const response = await api.get("/boards/by-thread", {
        params: { thread_id: threadId },
      });
      if (response.data.success) {
        const boards = response.data.data.boards;
        threadBoardsCache.value[threadId] = boards;
        return boards;
      }
    } catch (e) {
      console.error("Failed to get boards by thread:", e);
    }
    return [];
  }

  // ========================================
  // PROGRESS REPORTS
  // ========================================

  async function getProgressSinceLastReport(boardId) {
    try {
      const response = await api.get(`/boards/${boardId}/progress`);
      if (response.data.success) {
        return response.data.data.progress;
      }
    } catch (e) {
      console.error("Failed to get progress:", e);
    }
    return null;
  }

  async function generateProgressReportPreview(boardId) {
    try {
      isDebugEnabled() && console.log("Fetching progress report for board:", boardId);
      const response = await api.get(`/boards/${boardId}/progress-report`);
      isDebugEnabled() && console.log("Progress report response:", response.data);

      if (response.data.success) {
        const html = response.data.data?.html;
        if (html && html.length > 0) {
          return html;
        }
        console.warn("Progress report returned empty HTML");
      } else {
        console.warn("Progress report request failed:", response.data.message);
      }
    } catch (e) {
      console.error("Failed to generate progress report:", e);
      if (e.response?.data) {
        console.error("Server response:", e.response.data);
      }
    }
    return null;
  }

  async function sendProgressReport(boardId, recipients, subject = null) {
    try {
      const response = await api.post(
        `/boards/${boardId}/progress-report/send`,
        {
          recipients,
          subject,
        }
      );
      return response.data.success;
    } catch (e) {
      console.error("Failed to send progress report:", e);
    }
    return false;
  }

  async function getProgressReportHistory(boardId) {
    try {
      const response = await api.get(
        `/boards/${boardId}/progress-report/history`
      );
      if (response.data.success) {
        return response.data.data.history;
      }
    } catch (e) {
      console.error("Failed to get report history:", e);
    }
    return [];
  }

  // ========================================
  // CLEANUP
  // ========================================

  function clearCurrentBoard() {
    currentBoard.value = null;
  }

  function $reset() {
    boards.value = [];
    currentBoard.value = null;
    loading.value = false;
    boardLoading.value = false;
    viewMode.value = "board";
  }

  return {
    // State
    boards,
    currentBoard,
    loading,
    boardLoading,
    viewMode,

    // Computed
    activeBoards,
    archivedBoards,
    currentLists,
    currentLabels,
    currentMembers,
    canViewFinancials,
    canViewClient,
    canViewContacts,
    canViewEmails,
    canAccessDrive,
    allCards,
    cardsByDueStatus,

    // Board actions
    fetchBoards,
    fetchBoard,
    createBoard,
    updateBoard,
    deleteBoard,
    archiveBoard,
    unarchiveBoard,
    closeBoard,
    reopenBoard,

    // Member actions
    addMember,
    updateMemberRole,
    removeMember,

    // List actions
    createList,
    updateList,
    deleteList,
    reorderLists,

    // Card actions
    createCard,
    getCard,
    updateCard,
    updateCardInState,
    deleteCard,
    moveCard,
    reorderCards,

    // Label actions
    createLabel,
    updateLabel,
    deleteLabel,
    addLabelToCard,
    removeLabelFromCard,

    // Checklist actions
    createChecklist,
    updateChecklist,
    deleteChecklist,
    addChecklistItem,
    updateChecklistItem,
    deleteChecklistItem,
    toggleChecklistItem,

    // Attachment actions
    uploadAttachment,
    addDriveAttachment,
    addUrlAttachment,
    deleteAttachment,
    setCardCover,

    // Comment actions
    addComment,
    updateComment,
    deleteComment,

    // Search & filter
    searchCards,
    getCardsByDueDate,
    getAssignedCards,

    // Email-board linking
    linkEmailToBoard,
    unlinkEmailFromBoard,
    getBoardEmails,
    getEmailBoard,
    getBoardsByThread,
    getCachedEmailBoard,
    isEmailBoardCached,
    fetchEmailBoardsBatch,
    clearEmailBoardCache,

    // Progress reports
    getProgressSinceLastReport,
    generateProgressReportPreview,
    sendProgressReport,
    getProgressReportHistory,

    // View mode
    setViewMode,
    initViewMode,

    // Cleanup
    clearCurrentBoard,
    $reset,
  };
});
