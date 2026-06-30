import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'
import { useToastStore } from '@/stores/toast'

export const useFiltersStore = defineStore('filters', () => {
  const toast = useToastStore()
  const filters = ref([])
  const fields = ref([])
  const operators = ref([])
  const actions = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const applying = ref(false)
  const syncing = ref(false)
  const pendingFilter = ref(null) // For pre-populating filter editor
  const sieveStatus = ref({
    available: false,
    active: false,
    checked: false,
  })
  
  // Per-filter run history
  const filterRunHistory = ref({}) // { filterId: { lastRun, folder, matched, actionsCount, success } }
  
  // Load filter run history from localStorage
  function loadFilterRunHistory() {
    try {
      const stored = localStorage.getItem('filterRunHistory')
      if (stored) {
        filterRunHistory.value = JSON.parse(stored)
      }
    } catch (e) {
      console.error('Failed to load filter run history:', e)
    }
  }
  
  // Save filter run history to localStorage
  function saveFilterRunHistory() {
    try {
      localStorage.setItem('filterRunHistory', JSON.stringify(filterRunHistory.value))
    } catch (e) {
      console.error('Failed to save filter run history:', e)
    }
  }
  
  // Update run history for a specific filter
  function updateFilterRunHistory(filterId, data) {
    filterRunHistory.value[filterId] = {
      ...data,
      lastRun: Date.now()
    }
    saveFilterRunHistory()
  }
  
  // Get run history for a specific filter
  function getFilterRunHistory(filterId) {
    return filterRunHistory.value[filterId] || null
  }
  
  // Initialize history on store creation
  loadFilterRunHistory()

  async function fetchFilters(forceReload = false) {
    if (loaded.value && !forceReload) return
    loading.value = true
    try {
      const response = await api.get('/filters')
      if (response.data.success) {
        filters.value = response.data.data.filters
        fields.value = response.data.data.fields
        operators.value = response.data.data.operators
        actions.value = response.data.data.actions
        loaded.value = true
      }
    } catch (e) {
      console.error('Failed to fetch filters:', e)
    } finally {
      loading.value = false
    }
  }

  function hydrateFromBootstrap(filtersData) {
    if (Array.isArray(filtersData)) {
      filters.value = filtersData
      // Don't set loaded=true here because bootstrap only provides the
      // filter list, not the metadata (fields, operators, actions).
      // fetchFilters() must still run to populate dropdown options.
    }
  }

  async function createFilter(data) {
    try {
      const response = await api.post('/filters', data)
      if (response.data.success) {
        const result = response.data.data
        filters.value.push(result.filter)
        markSieveSynced()
        if (result.sieve_warning) {
          toast.warning(result.sieve_warning)
        }
        return result.filter
      }
    } catch (e) {
      console.error('Failed to create filter:', e)
    }
    return null
  }

  async function updateFilter(id, data) {
    try {
      const response = await api.put(`/filters/${id}`, data)
      if (response.data.success) {
        const result = response.data.data
        const index = filters.value.findIndex(f => f.id === id)
        if (index !== -1) {
          filters.value[index] = result.filter
        }
        markSieveSynced()
        if (result.sieve_warning) {
          toast.warning(result.sieve_warning)
        }
        return result.filter
      }
    } catch (e) {
      console.error('Failed to update filter:', e)
    }
    return null
  }

  async function deleteFilter(id) {
    try {
      const response = await api.delete(`/filters/${id}`)
      if (response.data.success) {
        filters.value = filters.value.filter(f => f.id !== id)
        markSieveSynced()
        const result = response.data.data
        if (result?.sieve_warning) {
          toast.warning(result.sieve_warning)
        }
        return true
      }
    } catch (e) {
      console.error('Failed to delete filter:', e)
    }
    return false
  }

  async function toggleFilter(id, enabled) {
    return updateFilter(id, { enabled })
  }

  function markSieveSynced() {
    if (sieveStatus.value.checked) {
      sieveStatus.value.active = filters.value.some(f => f.enabled)
    }
  }

  async function applyFilters(folder = 'INBOX', filterIds = [], limit = 100, page = 1) {
    applying.value = true
    try {
      const response = await api.post('/filters/apply', {
        folder,
        filter_ids: filterIds,
        limit,
        page
      })
      if (response.data.success) {
        return response.data.data
      }
    } catch (e) {
      console.error('Failed to apply filters:', e)
    } finally {
      applying.value = false
    }
    return null
  }
  
  // Apply a single filter and track its history
  async function applySingleFilter(filterId, folder = 'INBOX', limit = 100, page = 1) {
    const result = await applyFilters(folder, [filterId], limit, page)
    return result
  }
  
  async function setAllFiltersEnabled(enabled) {
    try {
      const response = await api.post('/filters/bulk-toggle', { enabled })
      if (response.data.success) {
        const result = response.data.data
        filters.value.forEach(f => { f.enabled = enabled })
        markSieveSynced()
        if (result.sieve_warning) {
          toast.warning(result.sieve_warning)
        }
        return result
      }
    } catch (e) {
      console.error('Failed to bulk toggle filters:', e)
    }
    return null
  }

  // Sieve (server-side filtering) functions
  async function checkSieveStatus() {
    try {
      const response = await api.get('/filters/sieve/status')
      if (response.data.success) {
        const data = response.data.data
        isDebugEnabled() && console.log('Sieve status response:', data)
        sieveStatus.value = {
          available: data.available,
          active: data.active,
          checked: true,
          error: data.error || null,
          scripts: data.scripts || [],
          debug: data.debug || null,
        }
      }
    } catch (e) {
      console.error('Failed to check Sieve status:', e)
      sieveStatus.value = { available: false, active: false, checked: true, error: e.message }
    }
    return sieveStatus.value
  }

  async function syncToSieve() {
    syncing.value = true
    try {
      const response = await api.post('/filters/sieve/sync')
      if (response.data.success) {
        const data = response.data.data
        sieveStatus.value.active = data.filters_count > 0
        sieveStatus.value.available = true
        return data
      }
    } catch (e) {
      console.error('Failed to sync to Sieve:', e)
    } finally {
      syncing.value = false
    }
    return null
  }

  return {
    filters,
    fields,
    operators,
    actions,
    loading,
    loaded,
    applying,
    syncing,
    pendingFilter,
    sieveStatus,
    filterRunHistory,
    fetchFilters,
    createFilter,
    updateFilter,
    deleteFilter,
    toggleFilter,
    applyFilters,
    applySingleFilter,
    setAllFiltersEnabled,
    updateFilterRunHistory,
    getFilterRunHistory,
    checkSieveStatus,
    syncToSieve,
    hydrateFromBootstrap,
  }
})

