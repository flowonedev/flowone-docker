import { ref } from 'vue'
import { isElectron, showNotification } from '@/services/electronApi'

export const pushStatus = ref('unknown')

class PushNotificationService {
  constructor() { this.initialized = false }

  get isSupported() {
    if (isElectron()) return true
    return 'Notification' in window
  }

  async init() {
    if (this.initialized) return true
    if (!this.isSupported) { pushStatus.value = 'unsupported'; return false }
    if (isElectron()) {
      this.initialized = true
      pushStatus.value = 'subscribed'
      return true
    }
    pushStatus.value = Notification.permission === 'granted' ? 'subscribed'
                     : Notification.permission === 'denied' ? 'denied' : 'unsubscribed'
    this.initialized = true
    return true
  }

  async refreshStatus() {
    if (isElectron()) { pushStatus.value = 'subscribed'; return }
    if (!('Notification' in window)) { pushStatus.value = 'unsupported'; return }
    pushStatus.value = Notification.permission === 'granted' ? 'subscribed'
                     : Notification.permission === 'denied' ? 'denied' : 'unsubscribed'
  }

  async syncExisting() {
    if (isElectron()) { pushStatus.value = 'subscribed'; return true }
    return null
  }

  async subscribe() {
    if (isElectron()) { pushStatus.value = 'subscribed'; return true }
    if (!('Notification' in window)) { pushStatus.value = 'unsupported'; return null }
    const permission = await Notification.requestPermission()
    pushStatus.value = permission === 'granted' ? 'subscribed'
                     : permission === 'denied' ? 'denied' : 'unsubscribed'
    return permission === 'granted' ? true : null
  }

  async unsubscribe() { pushStatus.value = 'unsubscribed' }

  async getStatus() {
    if (isElectron()) return 'subscribed'
    if (!('Notification' in window)) return 'unsupported'
    return Notification.permission === 'granted' ? 'subscribed' : 'unsubscribed'
  }

  async show(title, body, options = {}) {
    if (isElectron()) return showNotification(title, body)
    if ('Notification' in window && Notification.permission === 'granted') {
      const notification = new Notification(title, { body, ...options })
      if (options.onClick) notification.onclick = options.onClick
      return notification
    }
    return null
  }

  listenForNotificationClicks(router) {
    if (isElectron() && window.api) {
      window.api.on('notification-click', (data) => {
        if (data?.type === 'chat' && data?.conversationId) {
          router.push({ path: '/chat', query: { conversation: data.conversationId } })
        }
      })
    }
  }
}

export const pushNotifications = new PushNotificationService()
export default pushNotifications
