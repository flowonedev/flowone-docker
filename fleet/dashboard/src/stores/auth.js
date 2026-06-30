import { defineStore } from 'pinia'
import api from '../services/api'

// Get device fingerprint for trusted device identification
function getDeviceFingerprint() {
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  ctx.textBaseline = 'top'
  ctx.font = '14px Arial'
  ctx.fillText('fingerprint', 2, 2)
  const canvasData = canvas.toDataURL()
  
  const data = [
    navigator.userAgent,
    navigator.language,
    screen.width + 'x' + screen.height,
    new Date().getTimezoneOffset(),
    canvasData.slice(-50)
  ].join('|')
  
  // Simple hash
  let hash = 0
  for (let i = 0; i < data.length; i++) {
    const char = data.charCodeAt(i)
    hash = ((hash << 5) - hash) + char
    hash = hash & hash
  }
  return Math.abs(hash).toString(36)
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: null,
    refreshToken: null,
    pending2FA: false,
    tempToken: null,
    deviceFingerprint: null
  }),

  getters: {
    isAuthenticated: (state) => !!state.token && !!state.user,
    currentUser: (state) => state.user
  },

  actions: {
    initFromStorage() {
      const token = localStorage.getItem('token')
      const refreshToken = localStorage.getItem('refreshToken')
      const user = localStorage.getItem('user')
      this.deviceFingerprint = getDeviceFingerprint()

      if (token && user) {
        this.token = token
        this.refreshToken = refreshToken
        this.user = JSON.parse(user)
        api.setAuthToken(token)
      }
    },

    async login(username, password, trustDevice = false) {
      if (!this.deviceFingerprint) {
        this.deviceFingerprint = getDeviceFingerprint()
      }

      const response = await api.post('/api/auth/login', { 
        username, 
        password,
        device_fingerprint: this.deviceFingerprint
      })
      
      if (response.data.pending_2fa) {
        this.pending2FA = true
        this.tempToken = response.data.temp_token
        return { pending2FA: true }
      }

      this.setAuth(response.data)
      return { success: true }
    },

    async verify2FA(code, trustDevice = false) {
      const response = await api.post('/api/auth/2fa/verify', {
        temp_token: this.tempToken,
        totp_code: code,
        trust_device: trustDevice,
        device_fingerprint: this.deviceFingerprint
      })

      this.pending2FA = false
      this.tempToken = null
      this.setAuth(response.data)
      return { success: true }
    },

    setAuth(data) {
      this.token = data.access_token
      this.refreshToken = data.refresh_token
      this.user = data.user

      localStorage.setItem('token', data.access_token)
      localStorage.setItem('refreshToken', data.refresh_token)
      localStorage.setItem('user', JSON.stringify(data.user))

      api.setAuthToken(data.access_token)
    },

    async refresh() {
      try {
        const response = await api.post('/api/auth/refresh', {
          refresh_token: this.refreshToken
        })

        this.token = response.data.access_token
        localStorage.setItem('token', response.data.access_token)
        api.setAuthToken(response.data.access_token)

        return true
      } catch (error) {
        this.logout()
        return false
      }
    },

    async logout() {
      try {
        await api.post('/api/auth/logout')
      } catch (e) {
        // Ignore errors
      }

      this.user = null
      this.token = null
      this.refreshToken = null

      localStorage.removeItem('token')
      localStorage.removeItem('refreshToken')
      localStorage.removeItem('user')

      api.setAuthToken(null)
    },

    async fetchUser() {
      try {
        const response = await api.get('/api/auth/me')
        this.user = response.data
        localStorage.setItem('user', JSON.stringify(response.data))
      } catch (error) {
        this.logout()
      }
    },

    // 2FA Management
    async get2FAStatus() {
      const response = await api.get('/api/2fa/status')
      return response.data
    },

    async enable2FA() {
      const response = await api.post('/api/2fa/setup')
      return response.data
    },

    async confirm2FA(code) {
      const response = await api.post('/api/2fa/verify-setup', { code: code })
      if (this.user) {
        this.user.totp_enabled = true
        localStorage.setItem('user', JSON.stringify(this.user))
      }
      return response.data
    },

    async disable2FA(password) {
      const response = await api.post('/api/2fa/disable', { password: password })
      if (this.user) {
        this.user.totp_enabled = false
        localStorage.setItem('user', JSON.stringify(this.user))
      }
      return response.data
    },

    async regenerateBackupCodes(password) {
      const response = await api.post('/api/2fa/backup-codes', { password: password })
      return response.data
    },

    // Trusted Devices
    async getTrustedDevices() {
      const response = await api.get('/api/2fa/trusted-devices')
      return response.data
    },

    async removeTrustedDevice(deviceId) {
      const response = await api.delete(`/api/2fa/trusted-devices/${deviceId}`)
      return response.data
    },

    // Sessions
    async getSessions() {
      const response = await api.get('/api/sessions')
      return response.data
    },

    async revokeSession(sessionId) {
      const response = await api.delete(`/api/sessions/${sessionId}`)
      return response.data
    },

    async revokeAllOtherSessions() {
      const response = await api.post('/api/sessions/revoke-others')
      return response.data
    }
  }
})

