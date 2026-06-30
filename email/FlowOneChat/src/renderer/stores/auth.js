import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { isElectron } from '@/services/electronApi'

function withTimeout(promise, timeoutMs = 30000) {
  return Promise.race([
    promise,
    new Promise((_, reject) =>
      setTimeout(() => reject(new Error('Request timeout')), timeoutMs)
    )
  ])
}

export const useAuthStore = defineStore('auth', () => {
  const storedToken = isElectron() ? null : localStorage.getItem('webmail_token')

  const user = ref(null)
  const token = ref(storedToken || null)
  const loading = ref(false)
  const error = ref(null)
  const authChecked = ref(false)
  const loginComplete = ref(false)
  const userName = ref(null)
  const userEmailValue = ref(null)

  async function initFromElectron() {
    if (!isElectron()) return
    try {
      const isLoggedIn = await window.api.auth.isLoggedIn()
      if (isLoggedIn) {
        token.value = await window.api.auth.getToken()
        const config = await window.api.config.getAll()
        userEmailValue.value = config.userEmail
        userName.value = config.userName
        loginComplete.value = true
      }
    } catch (e) {
      console.warn('Failed to init auth from Electron store:', e)
    }
  }

  if (isElectron()) initFromElectron()

  const isAuthenticated = computed(() => {
    if (isElectron()) return !!token.value || loginComplete.value
    return !!token.value && !!user.value
  })

  const hasToken = computed(() => {
    if (isElectron()) return !!token.value
    return !!token.value || !!localStorage.getItem('webmail_token')
  })

  const userEmail = computed(() => {
    if (isElectron()) return userEmailValue.value || user.value?.email || ''
    return user.value?.email || ''
  })

  const displayName = computed(() => {
    if (isElectron() && userName.value) return userName.value
    return user.value?.display_name || userEmail.value.split('@')[0]
  })

  async function login(email, password) {
    loading.value = true
    error.value = null
    loginComplete.value = false

    try {
      let deviceToken = null
      if (isElectron()) {
        try { deviceToken = await window.api.config.get('deviceToken') } catch (_) {}
        if (!deviceToken) {
          try { deviceToken = localStorage.getItem('webmail_device_token') } catch (_) {}
        }
      } else {
        deviceToken = localStorage.getItem('webmail_device_token')
      }

      const response = await withTimeout(
        api.post('/auth/login', {
          email, password,
          device_token: deviceToken || undefined,
        }),
        30000
      )

      if (response.data.success) {
        const data = response.data.data

        if (data.requires_2fa) {
          loading.value = false
          return { requires_2fa: true, temp_token: data.temp_token, email: data.email }
        }

        if (isElectron()) {
          await window.api.auth.setToken(
            data.access_token,
            data.user?.email || email,
            data.user?.display_name || email.split('@')[0]
          )
          if (data.session_token) await window.api.config.set('sessionToken', data.session_token)
          if (data.device_token) await window.api.config.set('deviceToken', data.device_token)
          try {
            localStorage.setItem('webmail_token', data.access_token)
            if (data.refresh_token) localStorage.setItem('webmail_refresh_token', data.refresh_token)
            if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
            if (data.device_token) localStorage.setItem('webmail_device_token', data.device_token)
          } catch (_) {}
          userEmailValue.value = data.user?.email || email
          userName.value = data.user?.display_name || email.split('@')[0]
        } else {
          try {
            localStorage.setItem('webmail_token', data.access_token)
            localStorage.setItem('webmail_refresh_token', data.refresh_token)
            if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
            if (data.device_token) localStorage.setItem('webmail_device_token', data.device_token)
          } catch (storageError) {
            console.warn('Failed to save to localStorage:', storageError)
          }
        }

        token.value = data.access_token
        user.value = data.user
        authChecked.value = true
        loginComplete.value = true
        return true
      } else {
        error.value = response.data.message || 'Login failed'
        return false
      }
    } catch (e) {
      if (e.message === 'Request timeout') {
        error.value = 'Login request timed out. Please try again.'
      } else {
        error.value = e.response?.data?.message || 'Connection error'
      }
      return false
    } finally {
      loading.value = false
    }
  }

  async function setTokens(data) {
    if (isElectron()) {
      await window.api.auth.setToken(data.access_token, data.user?.email, data.user?.display_name)
      if (data.session_token) await window.api.config.set('sessionToken', data.session_token)
      if (data.device_token) await window.api.config.set('deviceToken', data.device_token)
      try {
        localStorage.setItem('webmail_token', data.access_token)
        if (data.refresh_token) localStorage.setItem('webmail_refresh_token', data.refresh_token)
        if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
        if (data.device_token) localStorage.setItem('webmail_device_token', data.device_token)
      } catch (_) {}
      userEmailValue.value = data.user?.email
      userName.value = data.user?.display_name
    } else {
      try {
        localStorage.setItem('webmail_token', data.access_token)
        localStorage.setItem('webmail_refresh_token', data.refresh_token)
        if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
      } catch (_) {}
    }
    token.value = data.access_token
    user.value = data.user
    authChecked.value = true
    loginComplete.value = true
  }

  async function clearAuth() {
    token.value = null
    user.value = null
    authChecked.value = false
    loginComplete.value = false
    userEmailValue.value = null
    userName.value = null
    if (isElectron()) {
      await window.api.auth.clearToken()
    } else {
      localStorage.removeItem('webmail_token')
      localStorage.removeItem('webmail_refresh_token')
    }
  }

  async function logout() {
    try { await api.post('/auth/logout') } catch (_) {}
    await clearAuth()
    if (isElectron()) {
      await window.api.config.set('sessionToken', null)
      try {
        localStorage.removeItem('webmail_token')
        localStorage.removeItem('webmail_refresh_token')
        localStorage.removeItem('webmail_session_token')
        localStorage.removeItem('webmail_active_account')
      } catch (_) {}
      window.dispatchEvent(new CustomEvent('logout'))
    } else {
      localStorage.removeItem('webmail_session_token')
      window.location.href = '/login'
    }
  }

  async function logoutEverywhere() {
    try { await api.post('/sessions/revoke-all') } catch (_) {}
    await clearAuth()
    if (isElectron()) {
      await window.api.config.set('sessionToken', null)
      await window.api.config.set('deviceToken', null)
      try {
        localStorage.removeItem('webmail_token')
        localStorage.removeItem('webmail_refresh_token')
        localStorage.removeItem('webmail_session_token')
        localStorage.removeItem('webmail_device_token')
        localStorage.removeItem('webmail_active_account')
      } catch (_) {}
      window.dispatchEvent(new CustomEvent('logout'))
    } else {
      localStorage.removeItem('webmail_session_token')
      localStorage.removeItem('webmail_device_token')
      window.location.href = '/login'
    }
  }

  async function checkAuth() {
    if (isElectron() && !token.value) {
      const isLoggedIn = await window.api.auth.isLoggedIn()
      if (isLoggedIn) {
        token.value = await window.api.auth.getToken()
        try {
          if (token.value) localStorage.setItem('webmail_token', token.value)
          const allConfig = await window.api.config.getAll()
          if (allConfig.sessionToken) localStorage.setItem('webmail_session_token', allConfig.sessionToken)
        } catch (_) {}
      }
    }

    if (!token.value) { authChecked.value = true; return false }

    try {
      const response = await withTimeout(api.get('/auth/me'), 15000)
      if (response.data.success) {
        user.value = response.data.data
        authChecked.value = true
        loginComplete.value = true
        if (isElectron()) {
          userEmailValue.value = response.data.data.email
          userName.value = response.data.data.display_name
        }
        return true
      }
      if (isElectron()) {
        authChecked.value = true
        loginComplete.value = true
        return true
      }
      await clearAuth()
    } catch (e) {
      if (e.response?.status === 401) {
        await clearAuth()
        authChecked.value = true
        return false
      }
      if (isElectron()) {
        authChecked.value = true
        if (!user.value && token.value) {
          user.value = { email: userEmailValue.value || 'offline' }
        }
        loginComplete.value = true
        return true
      }
      await clearAuth()
    }
    authChecked.value = true
    return false
  }

  function isLoginComplete() { return loginComplete.value && isAuthenticated.value }

  return {
    user, token, loading, error, authChecked, loginComplete,
    isAuthenticated, hasToken, userEmail, userName, displayName,
    login, logout, logoutEverywhere, checkAuth, setTokens,
    clearAuth, isLoginComplete, initFromElectron,
  }
})
