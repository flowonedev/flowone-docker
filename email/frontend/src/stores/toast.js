import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useToastStore = defineStore('toast', () => {
  const toasts = ref([])
  let nextId = 0

  function show(message, type = 'info', durationOrOptions = 4000) {
    const id = nextId++
    
    // Support both old API (duration as number) and new API (options object)
    let duration = 4000
    let action = null
    
    if (typeof durationOrOptions === 'number') {
      duration = durationOrOptions
    } else if (typeof durationOrOptions === 'object' && durationOrOptions !== null) {
      duration = durationOrOptions.duration || 4000
      action = durationOrOptions.action || null
    }
    
    toasts.value.push({
      id,
      message,
      type, // 'success', 'error', 'warning', 'info'
      action, // Optional: { label: string, onClick: function }
    })

    if (duration > 0) {
      setTimeout(() => {
        remove(id)
      }, duration)
    }

    return id
  }

  function success(message, durationOrOptions) {
    return show(message, 'success', durationOrOptions)
  }

  function error(message, durationOrOptions = 6000) {
    return show(message, 'error', durationOrOptions)
  }

  function warning(message, durationOrOptions) {
    return show(message, 'warning', durationOrOptions)
  }

  function info(message, durationOrOptions) {
    return show(message, 'info', durationOrOptions)
  }

  function update(id, message) {
    const toast = toasts.value.find(t => t.id === id)
    if (toast) toast.message = message
  }

  function remove(id) {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index !== -1) {
      toasts.value.splice(index, 1)
    }
  }

  function clear() {
    toasts.value = []
  }

  return {
    toasts,
    show,
    success,
    error,
    warning,
    info,
    update,
    remove,
    clear,
  }
})

