import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/services/api'
import { getConsoleLogs, getNetworkLogs } from '@/utils/logCapture'

export const useFeedbackStore = defineStore('feedback', () => {
  const isOpen = ref(false)
  const sending = ref(false)
  const currentView = ref('')
  const screenshot = ref(null)

  function open(viewName = '') {
    currentView.value = viewName
    screenshot.value = null
    isOpen.value = true
  }

  function close() {
    isOpen.value = false
    sending.value = false
    screenshot.value = null
  }

  function setScreenshot(dataUrl) {
    screenshot.value = dataUrl
  }

  function clearScreenshot() {
    screenshot.value = null
  }

  async function submit(payload) {
    sending.value = true
    try {
      await api.post('/feedback', {
        view: payload.view,
        view_label: payload.view_label,
        category: payload.category,
        category_label: payload.category_label,
        description: payload.description,
        screenshot: screenshot.value || null,
        user_agent: navigator.userAgent,
        screen_size: `${window.innerWidth}x${window.innerHeight}`,
        url: window.location.href,
        console_logs: getConsoleLogs(),
        network_logs: getNetworkLogs(),
      })
      isOpen.value = false
      screenshot.value = null
      return true
    } catch (e) {
      console.error('Feedback submit failed:', e)
      return false
    } finally {
      sending.value = false
    }
  }

  return { isOpen, sending, currentView, screenshot, open, close, setScreenshot, clearScreenshot, submit }
})
