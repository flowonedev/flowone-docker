import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export const useMailingListsStore = defineStore('mailingLists', () => {
  // State
  const lists = ref([])
  const currentList = ref(null)
  const loading = ref(false)
  const error = ref(null)
  
  // Computed
  const sortedLists = computed(() => {
    return [...lists.value].sort((a, b) => {
      if (a.sort_order !== b.sort_order) {
        return a.sort_order - b.sort_order
      }
      return a.name.localeCompare(b.name)
    })
  })
  
  const listById = computed(() => (id) => {
    return lists.value.find(l => l.id === id)
  })
  
  const loaded = ref(false)

  // Actions
  async function fetchLists(forceReload = false) {
    if (loaded.value && !forceReload) return
    loading.value = true
    error.value = null
    try {
      const response = await api.get('/mailing-lists')
      if (response.data.success) {
        lists.value = response.data.data.lists || []
        loaded.value = true
      }
    } catch (e) {
      error.value = e.message
      console.error('Failed to fetch mailing lists:', e)
    } finally {
      loading.value = false
    }
  }
  
  async function fetchList(id) {
    try {
      const response = await api.get(`/mailing-lists/${id}`)
      if (response.data.success) {
        currentList.value = response.data.data.list
        return response.data.data.list
      }
      return null
    } catch (e) {
      console.error('Failed to fetch mailing list:', e)
      return null
    }
  }
  
  async function createList(data) {
    try {
      const response = await api.post('/mailing-lists', data)
      if (response.data.success) {
        await fetchLists(true)
        return { success: true, id: response.data.data.id }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function updateList(id, data) {
    try {
      const response = await api.put(`/mailing-lists/${id}`, data)
      if (response.data.success) {
        await fetchLists(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function deleteList(id) {
    try {
      const response = await api.delete(`/mailing-lists/${id}`)
      if (response.data.success) {
        await fetchLists(true)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  // Contact actions
  async function addContact(listId, data) {
    try {
      const response = await api.post(`/mailing-lists/${listId}/contacts`, data)
      if (response.data.success) {
        // Refresh list if it's the current one
        if (currentList.value?.id === listId) {
          await fetchList(listId)
        }
        await fetchLists(true) // Update contact counts
        return { success: true, id: response.data.data.id }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function updateContact(contactId, data) {
    try {
      const response = await api.put(`/mailing-lists/contacts/${contactId}`, data)
      if (response.data.success) {
        // Refresh current list
        if (currentList.value) {
          await fetchList(currentList.value.id)
        }
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function deleteContact(contactId) {
    try {
      const response = await api.delete(`/mailing-lists/contacts/${contactId}`)
      if (response.data.success) {
        // Refresh current list
        if (currentList.value) {
          await fetchList(currentList.value.id)
        }
        await fetchLists(true) // Update contact counts
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function bulkDeleteContacts(contactIds) {
    try {
      const response = await api.post('/mailing-lists/contacts/bulk-delete', {
        contact_ids: contactIds
      })
      if (response.data.success) {
        // Refresh current list
        if (currentList.value) {
          await fetchList(currentList.value.id)
        }
        await fetchLists(true) // Update contact counts
        return { success: true, deleted: response.data.data.deleted }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function importContacts(listId, contacts, filename = null) {
    try {
      const response = await api.post(`/mailing-lists/${listId}/import`, {
        contacts,
        filename
      })
      if (response.data.success) {
        // Refresh list
        if (currentList.value?.id === listId) {
          await fetchList(listId)
        }
        await fetchLists(true) // Update contact counts
        return { 
          success: true, 
          imported: response.data.data.imported,
          skipped: response.data.data.skipped,
          errors: response.data.data.errors
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function getListEmails(listId) {
    try {
      const response = await api.get(`/mailing-lists/${listId}/emails`)
      if (response.data.success) {
        return response.data.data.emails || []
      }
      return []
    } catch (e) {
      console.error('Failed to get list emails:', e)
      return []
    }
  }
  
  // Custom Fields
  async function fetchCustomFields(listId) {
    try {
      const response = await api.get(`/mailing-lists/${listId}/custom-fields`)
      if (response.data.success) {
        return response.data.data.fields || []
      }
      return []
    } catch (e) {
      console.error('Failed to fetch custom fields:', e)
      return []
    }
  }
  
  async function createCustomField(listId, data) {
    try {
      const response = await api.post(`/mailing-lists/${listId}/custom-fields`, data)
      if (response.data.success) {
        return { success: true, id: response.data.data.id }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function updateCustomField(fieldId, data) {
    try {
      const response = await api.put(`/mailing-lists/custom-fields/${fieldId}`, data)
      return { success: response.data.success }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function deleteCustomField(fieldId) {
    try {
      const response = await api.delete(`/mailing-lists/custom-fields/${fieldId}`)
      return { success: response.data.success }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  // Helpers
  function getListColor(list) {
    const colors = {
      '#6366f1': 'bg-indigo-500',
      '#8b5cf6': 'bg-violet-500',
      '#ec4899': 'bg-pink-500',
      '#f43f5e': 'bg-rose-500',
      '#ef4444': 'bg-red-500',
      '#f97316': 'bg-orange-500',
      '#eab308': 'bg-yellow-500',
      '#22c55e': 'bg-green-500',
      '#14b8a6': 'bg-teal-500',
      '#06b6d4': 'bg-cyan-500',
      '#3b82f6': 'bg-blue-500',
      '#64748b': 'bg-slate-500',
    }
    return colors[list?.color] || 'bg-indigo-500'
  }
  
  return {
    // State
    lists,
    currentList,
    loading,
    error,
    
    // Computed
    sortedLists,
    listById,
    
    // Actions
    fetchLists,
    fetchList,
    createList,
    updateList,
    deleteList,
    addContact,
    updateContact,
    deleteContact,
    bulkDeleteContacts,
    importContacts,
    getListEmails,
    
    // Custom Fields
    fetchCustomFields,
    createCustomField,
    updateCustomField,
    deleteCustomField,
    
    // Helpers
    getListColor
  }
})

