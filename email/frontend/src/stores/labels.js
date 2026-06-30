import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/services/api'
import { useMailboxStore } from './mailbox'
import { isDebugEnabled } from '@/utils/debug'

export const useLabelsStore = defineStore('labels', () => {
  const labels = ref([])
  const colors = ref({})
  const loading = ref(false)
  
  // Cache tracking
  const lastFetchedTime = ref(null)
  const hasFetchedOnce = ref(false)
  const CACHE_DURATION = 300000 // 5 minutes

  function isCacheValid() {
    if (!hasFetchedOnce.value) return false
    if (!lastFetchedTime.value) return false
    return Date.now() - lastFetchedTime.value < CACHE_DURATION
  }

  async function fetchLabels(options = {}) {
    const { force = false, quiet = false } = options
    
    // Skip fetch if cache is valid
    if (!force && isCacheValid()) {
      isDebugEnabled() && console.log("[Labels] Cache hit - using cached labels data")
      return
    }
    
    // Only show loading on first load (no data at all)
    const hasData = hasFetchedOnce.value && labels.value.length > 0
    if (!hasData && !quiet) {
      loading.value = true
    }
    
    try {
      const response = await api.get('/labels')
      if (response.data.success) {
        labels.value = response.data.data.labels
        colors.value = response.data.data.colors
        lastFetchedTime.value = Date.now()
        hasFetchedOnce.value = true
      }
    } catch (e) {
      console.error('Failed to fetch labels:', e)
    } finally {
      loading.value = false
    }
  }

  async function createLabel(name, color) {
    try {
      const response = await api.post('/labels', { name, color })
      if (response.data.success) {
        labels.value.push(response.data.data.label)
        return response.data.data.label
      }
    } catch (e) {
      console.error('Failed to create label:', e)
    }
    return null
  }

  async function updateLabel(id, name, color) {
    try {
      const response = await api.put(`/labels/${id}`, { name, color })
      if (response.data.success) {
        const index = labels.value.findIndex(l => l.id === id)
        if (index !== -1) {
          labels.value[index] = { ...labels.value[index], name, color }
        }
        
        // Also update label references in all loaded messages
        const mailbox = useMailboxStore()
        mailbox.messages.forEach(msg => {
          if (msg.labels && Array.isArray(msg.labels)) {
            const labelIndex = msg.labels.findIndex(l => l.id === id)
            if (labelIndex !== -1) {
              msg.labels[labelIndex] = { ...msg.labels[labelIndex], name, color }
            }
          }
        })
        
        // Update current message if it has this label
        if (mailbox.currentMessage?.labels) {
          const labelIndex = mailbox.currentMessage.labels.findIndex(l => l.id === id)
          if (labelIndex !== -1) {
            mailbox.currentMessage.labels[labelIndex] = { ...mailbox.currentMessage.labels[labelIndex], name, color }
          }
        }
        
        return true
      }
    } catch (e) {
      console.error('Failed to update label:', e)
    }
    return false
  }

  async function deleteLabel(id) {
    try {
      const response = await api.delete(`/labels/${id}`)
      if (response.data.success) {
        labels.value = labels.value.filter(l => l.id !== id)
        return true
      }
    } catch (e) {
      console.error('Failed to delete label:', e)
    }
    return false
  }

  async function addLabelToMessage(messageId, labelId) {
    if (!messageId) {
      console.error('Cannot add label: no message_id provided')
      return false
    }
    try {
      const response = await api.post('/labels/message', {
        message_id: messageId,
        label_id: labelId
      })
      return response.data?.success !== false
    } catch (e) {
      console.error('Failed to add label:', e.response?.data || e.message)
      return false
    }
  }

  async function removeLabelFromMessage(messageId, labelId) {
    if (!messageId) {
      console.error('Cannot remove label: no message_id provided')
      return false
    }
    isDebugEnabled() && console.log('Removing label:', { message_id: messageId, label_id: labelId })
    try {
      const response = await api.post('/labels/message/remove', {
        message_id: messageId,
        label_id: labelId
      })
      isDebugEnabled() && console.log('Remove label response:', response.data)
      if (response.data?.success === true) {
        return true
      }
      console.error('Remove label returned success=false:', response.data)
      return false
    } catch (e) {
      console.error('Failed to remove label - exception:', e.response?.status, e.response?.data || e.message)
      return false
    }
  }

  function hydrateFromBootstrap(labelsData, colorsData) {
    labels.value = labelsData || []
    if (colorsData) colors.value = colorsData
    lastFetchedTime.value = Date.now()
    hasFetchedOnce.value = true
  }

  return {
    labels,
    colors,
    loading,
    fetchLabels,
    hydrateFromBootstrap,
    createLabel,
    updateLabel,
    deleteLabel,
    addLabelToMessage,
    removeLabelFromMessage,
  }
})

