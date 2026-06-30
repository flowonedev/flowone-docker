import { defineStore } from 'pinia'
import { ref } from 'vue'

interface AppConfig {
  apiUrl: string
  authToken: string | null
  syncFolder: string
  userEmail: string
  syncEnabled: boolean
  notificationsEnabled: boolean
  startMinimized: boolean
  launchAtStartup: boolean
  timeTracking: {
    enabled: boolean
    handleMonitorEnabled: boolean
    windowMonitorEnabled: boolean
    handlePollInterval: number
    windowPollInterval: number
    inactivityTimeout: number
  }
}

export const useConfigStore = defineStore('config', () => {
  const config = ref<AppConfig>({
    apiUrl: '',
    authToken: null,
    syncFolder: '',
    userEmail: '',
    syncEnabled: true,
    notificationsEnabled: true,
    startMinimized: false,
    launchAtStartup: false,
    timeTracking: {
      enabled: true,
      handleMonitorEnabled: true,
      windowMonitorEnabled: true,
      handlePollInterval: 1000,
      windowPollInterval: 1000,
      inactivityTimeout: 300,
    },
  })
  
  async function loadConfig() {
    try {
      const result = await window.api.getConfig()
      config.value = {
        apiUrl: result.apiUrl || '',
        authToken: result.authToken || null,
        syncFolder: result.syncFolder || '',
        userEmail: result.userEmail || '',
        syncEnabled: result.syncEnabled ?? true,
        notificationsEnabled: result.notificationsEnabled ?? true,
        startMinimized: result.startMinimized ?? false,
        launchAtStartup: result.launchAtStartup ?? false,
        timeTracking: result.timeTracking || {
          enabled: true,
          handleMonitorEnabled: true,
          windowMonitorEnabled: true,
          handlePollInterval: 1000,
          windowPollInterval: 1000,
          inactivityTimeout: 300,
        },
      }
    } catch (e) {
      console.error('Failed to load config:', e)
    }
  }
  
  async function saveConfig(updates: Partial<AppConfig>) {
    try {
      // Save each config key individually using setConfig
      for (const [key, value] of Object.entries(updates)) {
        await window.api.setConfig(key, value)
      }
      // Reload config to get updated values
      await loadConfig()
    } catch (e) {
      console.error('Failed to save config:', e)
      throw e
    }
  }
  
  async function setSyncFolder(folderPath: string) {
    try {
      await window.api.selectSyncFolder()
      await loadConfig()
    } catch (e) {
      console.error('Failed to set sync folder:', e)
      throw e
    }
  }
  
  return {
    config,
    loadConfig,
    saveConfig,
    setSyncFolder,
  }
})

