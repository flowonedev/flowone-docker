import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { isElectron } from '@/services/electronApi'

// Helper to detect iOS (Safari AND Chrome) - not relevant in Electron but keep for browser mode
function isIOS() {
  if (isElectron()) return false
  const ua = navigator.userAgent
  return /iPad|iPhone|iPod/.test(ua) && !window.MSStream
}

// Force localStorage sync on iOS (can have async issues)
function syncLocalStorage() {
  if (isIOS()) {
    try {
      localStorage.getItem('webmail_token')
    } catch (e) {
      console.warn('localStorage sync failed:', e)
    }
  }
}

// Timeout wrapper for API calls
function withTimeout(promise, timeoutMs = 30000) {
  return Promise.race([
    promise,
    new Promise((_, reject) => 
      setTimeout(() => reject(new Error('Request timeout')), timeoutMs)
    )
  ])
}

export const useAuthStore = defineStore('auth', () => {
  // Initialize token - will be set asynchronously in Electron
  syncLocalStorage()
  const storedToken = isElectron() ? null : localStorage.getItem('webmail_token')
  
  const user = ref(null)
  const token = ref(storedToken || null)
  const loading = ref(false)
  const error = ref(null)
  const authChecked = ref(false)
  const loginComplete = ref(false)
  const userName = ref(null)
  const userEmailValue = ref(null)

  // Initialize from Electron store
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

  // Call init immediately if in Electron
  if (isElectron()) {
    initFromElectron()
  }

  const isAuthenticated = computed(() => {
    if (isElectron()) {
      return !!token.value || loginComplete.value
    }
    if (isIOS()) {
      return !!token.value || loginComplete.value
    }
    return !!token.value && !!user.value
  })
  
  const hasToken = computed(() => {
    if (isElectron()) {
      return !!token.value
    }
    return !!token.value || !!localStorage.getItem('webmail_token')
  })
  
  const userEmail = computed(() => {
    if (isElectron()) {
      return userEmailValue.value || user.value?.email || ''
    }
    return user.value?.email || ''
  })
  
  const displayName = computed(() => {
    if (isElectron() && userName.value) {
      return userName.value
    }
    return user.value?.display_name || userEmail.value.split('@')[0]
  })

  async function login(email, password) {
    loading.value = true
    error.value = null
    loginComplete.value = false

    try {
      let deviceToken = null
      if (isElectron()) {
        try {
          deviceToken = await window.api.config.get('deviceToken')
        } catch (_) {}
        if (!deviceToken) {
          try { deviceToken = localStorage.getItem('webmail_device_token') } catch (_) {}
        }
      } else {
        deviceToken = localStorage.getItem('webmail_device_token')
      }
      
      const response = await withTimeout(
        api.post('/auth/login', { 
          email, 
          password,
          device_token: deviceToken || undefined,
        }),
        30000
      )
      
      if (response.data.success) {
        const data = response.data.data
        
        if (data.requires_2fa) {
          loading.value = false
          return {
            requires_2fa: true,
            temp_token: data.temp_token,
            email: data.email,
          }
        }
        
        // IMPORTANT: Store tokens in persistent storage BEFORE setting reactive state.
        // Setting token.value triggers isAuthenticated → App.vue watchers → API calls.
        // Those API calls read tokens via IPC (secureStorage / configStore), so the
        // tokens MUST be persisted before any reactive effects fire.
        
        if (isElectron()) {
          // Store via Electron IPC FIRST (before reactive state triggers watchers)
          await window.api.auth.setToken(
            data.access_token,
            data.user?.email || email,
            data.user?.display_name || email.split('@')[0]
          )
          
          // Store session token
          if (data.session_token) {
            console.log('[Auth] Storing session token:', data.session_token.length, 'chars')
            await window.api.config.set('sessionToken', data.session_token)
          } else {
            console.warn('[Auth] Login response did NOT include session_token!')
          }
          
          // Persist trusted device token in config store (survives logout)
          if (data.device_token) {
            await window.api.config.set('deviceToken', data.device_token)
          }

          // Also mirror tokens into localStorage so shared code (e.g. drive.js
          // fetch() calls) that reads via getToken() / localStorage works correctly.
          try {
            localStorage.setItem('webmail_token', data.access_token)
            if (data.refresh_token) localStorage.setItem('webmail_refresh_token', data.refresh_token)
            if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
            if (data.device_token) localStorage.setItem('webmail_device_token', data.device_token)
          } catch (_) { /* localStorage may not be available in some Electron contexts */ }
          
          userEmailValue.value = data.user?.email || email
          userName.value = data.user?.display_name || email.split('@')[0]
        } else {
          // Browser mode - use localStorage
          try {
            localStorage.setItem('webmail_token', data.access_token)
            localStorage.setItem('webmail_refresh_token', data.refresh_token)
            
            if (data.session_token) {
              localStorage.setItem('webmail_session_token', data.session_token)
            }
            
            if (data.device_token) {
              localStorage.setItem('webmail_device_token', data.device_token)
            }
            
            syncLocalStorage()
          } catch (storageError) {
            console.warn('Failed to save to localStorage:', storageError)
          }
        }
        
        // NOW set reactive state — tokens are safely persisted, so any watcher
        // that fires API calls will find them via IPC / localStorage.
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
    // Store tokens in persistent storage BEFORE setting reactive state
    // (same pattern as login() — prevents race with isAuthenticated watchers)
    
    if (isElectron()) {
      await window.api.auth.setToken(
        data.access_token,
        data.user?.email,
        data.user?.display_name
      )
      
      // Store session token if provided (e.g. from 2FA login)
      if (data.session_token) {
        console.log('[Auth] setTokens: storing session token:', data.session_token.length, 'chars')
        await window.api.config.set('sessionToken', data.session_token)
      }
      
      // Persist trusted device token in config store (survives logout)
      if (data.device_token) {
        await window.api.config.set('deviceToken', data.device_token)
      }

      // Mirror tokens into localStorage for shared code (drive.js fetch() etc.)
      try {
        localStorage.setItem('webmail_token', data.access_token)
        if (data.refresh_token) localStorage.setItem('webmail_refresh_token', data.refresh_token)
        if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
        if (data.device_token) localStorage.setItem('webmail_device_token', data.device_token)
      } catch (_) { /* localStorage may not be available */ }
      
      userEmailValue.value = data.user?.email
      userName.value = data.user?.display_name
    } else {
      const saveToStorage = () => {
        try {
          localStorage.setItem('webmail_token', data.access_token)
          localStorage.setItem('webmail_refresh_token', data.refresh_token)
          
          if (data.session_token) {
            localStorage.setItem('webmail_session_token', data.session_token)
          }
          
          syncLocalStorage()
          return true
        } catch (storageError) {
          console.warn('Failed to save to localStorage:', storageError)
          return false
        }
      }
      
      if (!saveToStorage() && isIOS()) {
        setTimeout(saveToStorage, 100)
      }
    }
    
    // NOW set reactive state — triggers isAuthenticated watchers safely
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
    try {
      await api.post('/auth/logout')
    } catch (e) {
      // Ignore logout errors
    }
    
    await clearAuth()
    
    if (isElectron()) {
      await window.api.config.set('sessionToken', null)
      // Clear mirrored localStorage tokens but keep device_token for trusted device feature
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
    try {
      await api.post('/sessions/revoke-all')
    } catch (e) {
      // Ignore errors
    }
    
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
    // In Electron, check if we have a stored token first
    if (isElectron() && !token.value) {
      const isLoggedIn = await window.api.auth.isLoggedIn()
      if (isLoggedIn) {
        token.value = await window.api.auth.getToken()
        
        // Mirror tokens into localStorage so shared code (drive.js fetch() etc.)
        // can read them via getToken() which uses localStorage
        try {
          if (token.value) localStorage.setItem('webmail_token', token.value)
          const allConfig = await window.api.config.getAll()
          if (allConfig.sessionToken) localStorage.setItem('webmail_session_token', allConfig.sessionToken)
        } catch (_) {}
      }
    }
    
    if (!token.value) {
      authChecked.value = true
      return false
    }

    try {
      const response = await withTimeout(api.get('/auth/me'), 15000)
      if (response.data.success) {
        user.value = response.data.data
        authChecked.value = true
        loginComplete.value = true
        
        // Update stored user info in Electron
        if (isElectron()) {
          userEmailValue.value = response.data.data.email
          userName.value = response.data.data.display_name
        }
        
        return true
      }
      // Response success was false — in Electron keep offline session, in browser clear
      if (isElectron()) {
        console.warn('Auth check: success=false, keeping Electron session offline')
        authChecked.value = true
        loginComplete.value = true
        return true
      }
      await clearAuth()
    } catch (e) {
      const is401 = e.response?.status === 401
      
      if (is401) {
        console.warn('Token invalid (401), clearing auth')
        await clearAuth()
        authChecked.value = true
        return false
      }
      
      // In Electron or iOS, allow offline / degraded mode for ANY server error
      // (network errors, 500s, 503s, timeouts). Only a confirmed 401 should clear auth.
      if (isElectron() || isIOS()) {
        console.warn('Auth check failed (server/network error), keeping session:', e.message)
        authChecked.value = true
        if (!user.value && token.value) {
          if (isElectron()) {
            user.value = { email: userEmailValue.value || 'offline' }
          } else {
            user.value = { email: 'loading...' }
          }
        }
        loginComplete.value = true
        return true
      }
      
      await clearAuth()
    }
    authChecked.value = true
    return false
  }

  function isLoginComplete() {
    return loginComplete.value && isAuthenticated.value
  }

  function hydrateFromBootstrap(userData) {
    if (userData) {
      user.value = userData
      authChecked.value = true
      loginComplete.value = true
      if (userData.email) userEmailValue.value = userData.email
      if (userData.display_name) userName.value = userData.display_name
    }
  }

  return {
    user,
    token,
    loading,
    error,
    authChecked,
    loginComplete,
    isAuthenticated,
    hasToken,
    userEmail,
    userName,
    displayName,
    login,
    logout,
    logoutEverywhere,
    checkAuth,
    setTokens,
    clearAuth,
    isLoginComplete,
    initFromElectron,
    hydrateFromBootstrap,
  }
})
