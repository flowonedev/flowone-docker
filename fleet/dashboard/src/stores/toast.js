import { defineStore } from 'pinia'

export const useToastStore = defineStore('toast', {
  state: () => ({
    toasts: []
  }),

  actions: {
    show(message, type = 'info', duration = 5000) {
      const id = Date.now()
      
      this.toasts.push({
        id,
        message,
        type,
        duration
      })

      if (duration > 0) {
        setTimeout(() => {
          this.remove(id)
        }, duration)
      }

      return id
    },

    success(message, duration = 5000) {
      return this.show(message, 'success', duration)
    },

    error(message, duration = 7000) {
      return this.show(message, 'error', duration)
    },

    warning(message, duration = 5000) {
      return this.show(message, 'warning', duration)
    },

    info(message, duration = 5000) {
      return this.show(message, 'info', duration)
    },

    remove(id) {
      const index = this.toasts.findIndex(t => t.id === id)
      if (index !== -1) {
        this.toasts.splice(index, 1)
      }
    }
  }
})

