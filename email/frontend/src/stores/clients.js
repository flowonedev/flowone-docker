import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/services/api";
import { useSearchStore } from "@/addons/universal-search/stores/search";
import { withOfflineFallback, getOfflineClients } from "@/services/offlineData";

export const useClientsStore = defineStore("clients", () => {
  // State
  const clients = ref([]);
  const currentClient = ref(null);
  const loading = ref(false);
  const clientLoading = ref(false);
  const syncing = ref(false);
  
  const counts = ref({
    attention: 0,
    waiting: 0,
    active: 0,
    total: 0
  });
  
  // Filters
  const statusFilter = ref(null); // null = all, 'attention', 'waiting', 'active'
  const sortBy = ref('status'); // 'status', 'activity', 'name'

  // Computed
  const filteredClients = computed(() => {
    if (!statusFilter.value) {
      return clients.value;
    }
    return clients.value.filter(c => c.status === statusFilter.value);
  });

  const attentionClients = computed(() => 
    clients.value.filter(c => c.status === 'attention')
  );

  const waitingClients = computed(() => 
    clients.value.filter(c => c.status === 'waiting')
  );

  const activeClients = computed(() => 
    clients.value.filter(c => c.status === 'active')
  );

  // ========================================
  // CLIENT ACTIONS
  // ========================================

  async function initClients() {
    loading.value = true;
    try {
      const response = await api.get('/clients/init');
      if (response.data.success) {
        const d = response.data.data;
        clients.value = d.clients || [];
        if (d.counts) counts.value = d.counts;
        return d;
      }
    } catch (e) {
      console.error('Failed to init clients:', e);
    } finally {
      loading.value = false;
    }
    return null;
  }

  async function fetchClients(status = null, sort = 'status') {
    loading.value = true;
    try {
      const result = await withOfflineFallback(
        async () => {
          const params = {};
          if (status) params.status = status;
          if (sort) params.sort = sort;

          const response = await api.get('/clients', { params });
          if (response.data.success) {
            return { clients: response.data.data.clients, counts: response.data.data.counts };
          }
          return null;
        },
        async () => {
          const offlineClients = await getOfflineClients();
          if (offlineClients) return { clients: offlineClients, counts: null };
          return null;
        }
      );
      if (result) {
        clients.value = result.clients;
        if (result.counts) counts.value = result.counts;
      }
    } catch (e) {
      console.error('Failed to fetch clients:', e);
    } finally {
      loading.value = false;
    }
  }

  async function fetchClient(id) {
    clientLoading.value = true;
    try {
      const response = await api.get(`/clients/${id}`);
      if (response.data.success) {
        currentClient.value = response.data.data;
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to fetch client:', e);
    } finally {
      clientLoading.value = false;
    }
    return null;
  }

  async function updateClient(id, data) {
    try {
      // Support both old signature (id, displayName) and new (id, {data})
      const payload = typeof data === 'string' 
        ? { display_name: data }
        : data;
        
      const response = await api.put(`/clients/${id}`, payload);
      if (response.data.success) {
        const updated = response.data.data.client;
        
        // Update in list
        const index = clients.value.findIndex(c => c.id === id);
        if (index !== -1) {
          clients.value[index] = { ...clients.value[index], ...updated };
        }
        
        // Re-index for search
        const searchStore = useSearchStore();
        searchStore.indexItem('client', id, updated);
        
        // Update current if matching
        if (currentClient.value?.client?.id === id) {
          currentClient.value.client = updated;
        }
        
        return updated;
      }
    } catch (e) {
      console.error('Failed to update client:', e);
    }
    return null;
  }

  async function deleteClient(id) {
    try {
      const response = await api.delete(`/clients/${id}`);
      if (response.data.success) {
        clients.value = clients.value.filter(c => c.id !== id);
        if (currentClient.value?.client?.id === id) {
          currentClient.value = null;
        }
        // Remove from search index
        const searchStore = useSearchStore();
        searchStore.removeFromIndex('client', id);
        return true;
      }
    } catch (e) {
      console.error('Failed to delete client:', e);
    }
    return false;
  }

  // ========================================
  // CLIENT DETAILS
  // ========================================

  async function fetchClientThreads(id) {
    try {
      const response = await api.get(`/clients/${id}/threads`);
      if (response.data.success) {
        return response.data.data.threads;
      }
    } catch (e) {
      console.error('Failed to fetch client threads:', e);
    }
    return [];
  }

  async function fetchClientTasks(id) {
    try {
      const response = await api.get(`/clients/${id}/tasks`);
      if (response.data.success) {
        return response.data.data.tasks;
      }
    } catch (e) {
      console.error('Failed to fetch client tasks:', e);
    }
    return [];
  }

  async function fetchClientFiles(id) {
    try {
      const response = await api.get(`/clients/${id}/files`);
      if (response.data.success) {
        return response.data.data.files;
      }
    } catch (e) {
      console.error('Failed to fetch client files:', e);
    }
    return [];
  }

  // ========================================
  // BOARD LINKING
  // ========================================

  async function linkBoard(clientId, boardId) {
    try {
      const response = await api.post(`/clients/${clientId}/boards`, {
        board_id: boardId
      });
      if (response.data.success) {
        // Refresh client data
        await fetchClient(clientId);
        return true;
      }
    } catch (e) {
      console.error('Failed to link board:', e);
    }
    return false;
  }

  async function unlinkBoard(clientId, boardId) {
    try {
      const response = await api.delete(`/clients/${clientId}/boards/${boardId}`);
      if (response.data.success) {
        // Refresh client data
        await fetchClient(clientId);
        return true;
      }
    } catch (e) {
      console.error('Failed to unlink board:', e);
    }
    return false;
  }

  // ========================================
  // DRIVE FOLDER LINKING
  // ========================================

  async function linkDriveFolder(clientId, folderId) {
    try {
      const response = await api.post(`/clients/${clientId}/drive-folder`, {
        folder_id: folderId
      });
      if (response.data.success) {
        // Refresh client data
        await fetchClient(clientId);
        return true;
      }
    } catch (e) {
      console.error('Failed to link Drive folder:', e);
    }
    return false;
  }

  async function unlinkDriveFolder(clientId) {
    try {
      const response = await api.delete(`/clients/${clientId}/drive-folder`);
      if (response.data.success) {
        // Refresh client data
        await fetchClient(clientId);
        return true;
      }
    } catch (e) {
      console.error('Failed to unlink Drive folder:', e);
    }
    return false;
  }

  // ========================================
  // SYNC & RECALCULATE
  // ========================================

  async function syncClients() {
    syncing.value = true;
    try {
      const response = await api.post('/clients/sync');
      if (response.data.success) {
        counts.value = response.data.data.counts;
        // Refresh client list
        await fetchClients(statusFilter.value, sortBy.value);
        return response.data.data.synced;
      }
    } catch (e) {
      console.error('Failed to sync clients:', e);
    } finally {
      syncing.value = false;
    }
    return 0;
  }

  async function recalculateClient(id) {
    try {
      const response = await api.post(`/clients/${id}/recalculate`);
      if (response.data.success) {
        const updated = response.data.data.client;
        
        // Update in list
        const index = clients.value.findIndex(c => c.id === id);
        if (index !== -1) {
          clients.value[index] = updated;
        }
        
        return updated;
      }
    } catch (e) {
      console.error('Failed to recalculate client:', e);
    }
    return null;
  }

  // ========================================
  // CLIENT MERGE
  // ========================================

  async function mergeClients(primaryId, secondaryId) {
    try {
      const response = await api.post('/clients/merge', {
        primary_id: primaryId,
        secondary_id: secondaryId
      });
      if (response.data.success) {
        // Remove secondary from list
        clients.value = clients.value.filter(c => c.id !== secondaryId);
        
        // Update primary in list
        const updated = response.data.data.client;
        const index = clients.value.findIndex(c => c.id === primaryId);
        if (index !== -1) {
          clients.value[index] = updated;
        }
        
        // Update current if matching
        if (currentClient.value?.client?.id === primaryId) {
          currentClient.value.client = updated;
        }
        
        return updated;
      }
    } catch (e) {
      console.error('Failed to merge clients:', e);
    }
    return null;
  }

  // ========================================
  // ASSOCIATED ACCOUNTS
  // ========================================

  async function fetchAssociatedAccounts(clientId) {
    try {
      const response = await api.get(`/clients/${clientId}/associated`);
      if (response.data.success) {
        return response.data.data.associated;
      }
    } catch (e) {
      console.error('Failed to fetch associated accounts:', e);
    }
    return [];
  }

  async function promoteToClient(associatedId) {
    try {
      const response = await api.post(`/clients/${associatedId}/promote`);
      if (response.data.success) {
        // Refresh the clients list
        await fetchClients(statusFilter.value, sortBy.value);
        return response.data.data.client;
      }
    } catch (e) {
      console.error('Failed to promote associated account:', e);
    }
    return null;
  }

  async function markAsAssociated(clientId, primaryClientId) {
    try {
      const response = await api.post(`/clients/${clientId}/mark-associated`, {
        primary_client_id: primaryClientId
      });
      if (response.data.success) {
        // Refresh the clients list
        await fetchClients(statusFilter.value, sortBy.value);
        return response.data.data.client;
      }
    } catch (e) {
      console.error('Failed to mark as associated:', e);
    }
    return null;
  }

  // ========================================
  // SIGNATURE EXTRACTION
  // ========================================

  async function extractSignature(clientId, emailBody) {
    try {
      const response = await api.post(`/clients/${clientId}/extract-signature`, {
        email_body: emailBody
      });
      if (response.data.success) {
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to extract signature:', e);
    }
    return null;
  }

  async function applySignature(clientId, emailBody) {
    try {
      const response = await api.post(`/clients/${clientId}/apply-signature`, {
        email_body: emailBody
      });
      if (response.data.success) {
        const updated = response.data.data.client;
        
        // Update in list
        const index = clients.value.findIndex(c => c.id === clientId);
        if (index !== -1) {
          clients.value[index] = { ...clients.value[index], ...updated };
        }
        
        // Update current if matching
        if (currentClient.value?.client?.id === clientId) {
          currentClient.value.client = updated;
        }
        
        return updated;
      }
    } catch (e) {
      console.error('Failed to apply signature:', e);
    }
    return null;
  }

  // ========================================
  // MULTI-EMAIL SIGNATURE EXTRACTION
  // ========================================

  async function extractSignatureMultiEmail(clientId, autoApply = true) {
    try {
      const response = await api.post(`/clients/${clientId}/extract-contacts`, { 
        auto_apply: autoApply 
      });
      if (response.data.success) {
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to extract contact info from emails:', e);
    }
    return null;
  }

  // ========================================
  // EXPORT & OVERVIEW
  // ========================================

  async function exportClients() {
    try {
      const response = await api.get('/clients/export', {
        responseType: 'blob'
      });
      
      // Create download link
      const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8' });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `clients_export_${new Date().toISOString().split('T')[0]}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
      return true;
    } catch (e) {
      console.error('Failed to export clients:', e);
      return false;
    }
  }

  async function fetchOverview(params = {}) {
    try {
      const response = await api.get('/clients/overview', { params });
      if (response.data.success) {
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to fetch clients overview:', e);
    }
    return null;
  }

  // ========================================
  // FINANCIALS
  // ========================================

  async function fetchClientFinancials(clientId) {
    try {
      const response = await api.get(`/clients/${clientId}/financials`);
      if (response.data.success) {
        return response.data.data;
      }
    } catch (e) {
      console.error('Failed to fetch client financials:', e);
    }
    return null;
  }

  // ========================================
  // FILTERS & SORTING
  // ========================================

  function setStatusFilter(status) {
    statusFilter.value = status;
  }

  function setSortBy(sort) {
    sortBy.value = sort;
    fetchClients(statusFilter.value, sort);
  }

  // ========================================
  // UTILITIES
  // ========================================

  function getStatusLabel(status) {
    switch (status) {
      case 'attention': return 'Attention Needed';
      case 'waiting': return 'Waiting';
      case 'active': return 'Active';
      default: return status;
    }
  }

  function getStatusColor(status) {
    switch (status) {
      case 'attention': return 'red';
      case 'waiting': return 'yellow';
      case 'active': return 'green';
      default: return 'gray';
    }
  }

  function formatLastActivity(dateStr) {
    if (!dateStr) return 'No activity';
    
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    
    return date.toLocaleDateString();
  }

  /**
   * Find client by email domain
   * Used for time tracking to identify which client an email belongs to
   */
  function getClientByDomain(domain) {
    if (!domain) return null;
    domain = domain.toLowerCase();
    return clients.value.find(c => c.domain?.toLowerCase() === domain) || null;
  }

  /**
   * Find client by email address
   * Extracts domain and looks up client
   */
  function getClientByEmail(email) {
    if (!email) return null;
    const domain = email.split('@')[1]?.toLowerCase();
    if (!domain) return null;
    return getClientByDomain(domain);
  }

  /**
   * Find client by linked board ID
   * Returns client if board is linked to them
   */
  function getClientByBoardId(boardId) {
    if (!boardId) return null;
    // Check each client's linked boards
    // This requires cached linked board data
    return clients.value.find(c => c.linked_boards?.some(b => b.board_id === boardId)) || null;
  }

  /**
   * Get a map of domain/email -> client ID for quick lookups
   * Business clients: map['example.com'] = clientId
   * Generic email clients: map['user@gmail.com'] = clientId (domain field stores full email)
   */
  const domainToClientMap = computed(() => {
    const map = {};
    for (const client of clients.value) {
      if (client.domain) {
        map[client.domain.toLowerCase()] = client.id;
      }
    }
    return map;
  });

  // ========================================
  // CLEANUP
  // ========================================

  function clearCurrentClient() {
    currentClient.value = null;
  }

  function $reset() {
    clients.value = [];
    currentClient.value = null;
    loading.value = false;
    clientLoading.value = false;
    syncing.value = false;
    counts.value = { attention: 0, waiting: 0, active: 0, total: 0 };
    statusFilter.value = null;
    sortBy.value = 'status';
  }

  return {
    // State
    clients,
    currentClient,
    loading,
    clientLoading,
    syncing,
    counts,
    statusFilter,
    sortBy,

    // Computed
    filteredClients,
    attentionClients,
    waitingClients,
    activeClients,

    // Client actions
    initClients,
    fetchClients,
    fetchClient,
    updateClient,
    deleteClient,

    // Client details
    fetchClientThreads,
    fetchClientTasks,
    fetchClientFiles,

    // Board linking
    linkBoard,
    unlinkBoard,

    // Drive folder linking
    linkDriveFolder,
    unlinkDriveFolder,

    // Sync & recalculate
    syncClients,
    recalculateClient,

    // Client merge
    mergeClients,

    // Associated accounts
    fetchAssociatedAccounts,
    promoteToClient,
    markAsAssociated,

    // Signature extraction
    extractSignature,
    extractSignatureMultiEmail,
    applySignature,

    // Financials
    fetchClientFinancials,

    // Export & overview
    exportClients,
    fetchOverview,

    // Filters & sorting
    setStatusFilter,
    setSortBy,

    // Utilities
    getStatusLabel,
    getStatusColor,
    formatLastActivity,
    getClientByDomain,
    getClientByEmail,
    getClientByBoardId,
    domainToClientMap,

    // Cleanup
    clearCurrentClient,
    $reset
  };
});

