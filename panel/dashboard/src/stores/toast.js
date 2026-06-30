import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useToastStore = defineStore('toast', () => {
  const toasts = ref([])
  let nextId = 0

  function add(message, type = 'info', duration = 5000) {
    const id = nextId++
    
    toasts.value.push({
      id,
      message,
      type, // 'success', 'error', 'warning', 'info'
    })

    if (duration > 0) {
      setTimeout(() => remove(id), duration)
    }

    return id
  }

  function success(message, duration) {
    return add(message, 'success', duration)
  }

  function error(message, duration) {
    return add(message, 'error', duration)
  }

  function warning(message, duration) {
    return add(message, 'warning', duration)
  }

  function info(message, duration) {
    return add(message, 'info', duration)
  }

  function remove(id) {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index > -1) {
      toasts.value.splice(index, 1)
    }
  }

  function clear() {
    toasts.value = []
  }

  return {
    toasts,
    add,
    success,
    error,
    warning,
    info,
    remove,
    clear
  }
})

