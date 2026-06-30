import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'
import api from '@/services/api'
import { getToken, setToken, clearAllTokens, hasToken as hasStoredToken } from '@/services/tokenStorage'
import {
  isNative,
  getApiOrigin,
  getServerBase,
  setServerBase,
  clearServerBase,
  deriveBaseFromEmail,
  getCachedBase,
  resolveServerBase,
  domainPart,
} from '@/services/serverRegistry'

// Helper to detect iOS (Safari AND Chrome)
function isIOS() {
  const ua = navigator.userAgent
  return /iPad|iPhone|iPod/.test(ua) && !window.MSStream
}

// Force storage sync on iOS (can have async issues)
function syncStorage() {
  if (isIOS()) {
    try {
      getToken('webmail_token')
    } catch (e) {
      console.warn('storage sync failed:', e)
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
  // Initialize token - force sync for iOS
  syncStorage()
  const storedToken = getToken('webmail_token')

  // Native relaunch recovery: if a token survived but the resolved server did
  // not (app update / cleared storage), rebuild the deployment base from the
  // token subject (the user's email) so API + WS calls route correctly before
  // any re-login happens.
  if (isNative && storedToken && !getServerBase()) {
    try {
      const part = storedToken.split('.')[1]
      if (part) {
        const payload = JSON.parse(
          atob(part.replace(/-/g, '+').replace(/_/g, '/'))
        )
        if (payload?.sub) {
          // Prefer the cached resolution from the last login (handles shared
          // tenants whose backend is flowone.pro, not email.<domain>); fall
          // back to the convention only if nothing was cached.
          const cached = getCachedBase(domainPart(payload.sub))
          setServerBase(cached || deriveBaseFromEmail(payload.sub))
        }
      }
    } catch (_e) {
      /* malformed token - login will re-derive */
    }
  }
  
  const user = ref(null)
  const token = ref(storedToken || null)
  const loading = ref(false)
  const error = ref(null)
  const authChecked = ref(false) // Track if auth has been validated with server
  const loginComplete = ref(false) // Track if login process completed successfully

  // For iOS Safari: isAuthenticated is more permissive
  const isAuthenticated = computed(() => {
    if (isIOS()) {
      // On iOS, having a token OR loginComplete is enough
      return !!token.value || loginComplete.value
    }
    return !!token.value && !!user.value
  })
  const hasToken = computed(() => !!token.value || hasStoredToken('webmail_token'))
  const userEmail = computed(() => user.value?.email || '')
  const displayName = computed(() => user.value?.display_name || userEmail.value.split('@')[0])
  // True when the account must set a new password before using the app
  // (migrated mailbox / admin-flagged). Surfaced from login + /auth/me.
  const forcePasswordChange = computed(() => !!user.value?.force_password_change)

  async function login(email, password) {
    loading.value = true
    error.value = null
    loginComplete.value = false

    try {
      // Native: resolve the deployment for this email BEFORE the call (via the
      // discovery host), so the login request and everything after target the
      // correct backend — flowone.pro for shared tenants, email.<domain> for
      // dedicated deployments.
      if (isNative) {
        await resolveServerBase(email)
      }

      // Check for trusted device token
      const deviceToken = localStorage.getItem('webmail_device_token')
      
      // Use timeout to prevent infinite hanging on iOS
      const response = await withTimeout(
        api.post('/auth/login', { 
          email, 
          password,
          device_token: deviceToken || undefined, // Send device token if exists
        }),
        30000
      )
      
      if (response.data.success) {
        const data = response.data.data
        
        // Check if 2FA is required
        if (data.requires_2fa) {
          loading.value = false
          return {
            requires_2fa: true,
            temp_token: data.temp_token,
            email: data.email,
          }
        }
        
        // Normal login - store tokens
        token.value = data.access_token
        user.value = data.user
        authChecked.value = true
        
        // Store tokens in sessionStorage (secure, per-tab)
        try {
          setToken('webmail_token', data.access_token)
          setToken('webmail_refresh_token', data.refresh_token)
          
          // Store session token for session tracking
          if (data.session_token) {
            setToken('webmail_session_token', data.session_token)
          }
          
          // Device token stays in localStorage (needs to persist across sessions for 7-day trusted device)
          if (data.device_token) {
            localStorage.setItem('webmail_device_token', data.device_token)
          }
          
          syncStorage()
        } catch (storageError) {
          console.warn('Failed to save tokens:', storageError)
        }
        
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

  function setTokens(data) {
    // Set state first
    token.value = data.access_token
    user.value = data.user
    authChecked.value = true
    loginComplete.value = true
    
    // Save to sessionStorage (secure, per-tab)
    const saveToStorage = () => {
      try {
        setToken('webmail_token', data.access_token)
        setToken('webmail_refresh_token', data.refresh_token)
        if (data.session_token) {
          setToken('webmail_session_token', data.session_token)
        }
        syncStorage()
        return true
      } catch (storageError) {
        console.warn('Failed to save tokens:', storageError)
        return false
      }
    }
    
    // Try immediately
    if (!saveToStorage() && isIOS()) {
      // Retry after a short delay on iOS
      setTimeout(saveToStorage, 100)
    }
    
    // Store device trust token (persists across sessions in localStorage)
    if (data.device_token) {
      localStorage.setItem('webmail_device_token', data.device_token)
    }
  }

  function clearAuth() {
    token.value = null
    user.value = null
    authChecked.value = false
    clearAllTokens()
  }

  async function logout() {
    // Drop this device's native push token first so a re-used device never
    // receives the previous user's notifications. Best-effort; never blocks.
    if (isNative) {
      try {
        const { unregisterNativePush } = await import('@/services/nativePush')
        await unregisterNativePush()
      } catch (_e) {
        // ignore
      }
    }

    try {
      await api.post('/auth/logout')
    } catch (e) {
      // Ignore logout errors
    }

    // Wipe the IndexedDB cache for this account so the next user on
    // this device cannot see leftover headers/bodies. Best-effort; we
    // do not block logout if the wipe fails.
    try {
      const email = user.value?.email
      const { setActiveUserEmail, wipeAccountCache } = await import('@/services/offlineMailbox')
      if (email) {
        setActiveUserEmail(email)
        await wipeAccountCache(email)
      }
    } catch (_e) {
      // ignore
    }

    clearAuth()
    // Note: We DON'T remove device_token on normal logout - it should persist for 7 days
    
    // Redirect to login page
    window.location.href = '/login'
  }
  
  // Full logout - also clears trusted device (sign out everywhere)
  async function logoutEverywhere() {
    if (isNative) {
      try {
        const { unregisterNativePush } = await import('@/services/nativePush')
        await unregisterNativePush()
      } catch (_e) {
        // ignore
      }
    }

    try {
      await api.post('/sessions/revoke-all')
    } catch (e) {
      // Ignore errors
    }

    try {
      const email = user.value?.email
      const { setActiveUserEmail, wipeAccountCache } = await import('@/services/offlineMailbox')
      if (email) {
        setActiveUserEmail(email)
        await wipeAccountCache(email)
      }
    } catch (_e) {
      // ignore
    }

    clearAuth()
    localStorage.removeItem('webmail_device_token')
    // Forget the resolved deployment so the next user re-derives from their
    // own email domain on the login screen.
    clearServerBase()
    
    window.location.href = '/login'
  }

  /**
   * Decode JWT payload without signature verification.
   * Safe for client-side routing decisions -- server validates on every API call.
   */
  function decodeTokenPayload(jwt) {
    try {
      const parts = jwt.split('.')
      if (parts.length !== 3) return null
      const base64 = parts[1].replace(/-/g, '+').replace(/_/g, '/')
      const json = decodeURIComponent(
        atob(base64).split('').map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join('')
      )
      return JSON.parse(json)
    } catch {
      return null
    }
  }

  /**
   * Local-only auth check: decode the JWT, verify expiry, populate a minimal
   * user object from the `sub` claim. No network call -- instant.
   * Server-side validation happens via bootstrap (or any subsequent API call).
   */
  function checkAuthLocal() {
    if (!token.value) {
      authChecked.value = true
      return false
    }

    const payload = decodeTokenPayload(token.value)
    if (!payload || !payload.sub) {
      clearAuth()
      authChecked.value = true
      return false
    }

    const nowSec = Math.floor(Date.now() / 1000)
    if (payload.exp && payload.exp < nowSec) {
      clearAuth()
      authChecked.value = true
      return false
    }

    if (!user.value) {
      user.value = { email: payload.sub }
    }
    authChecked.value = true
    loginComplete.value = true
    return true
  }

  /**
   * Clear the force-password-change flag locally after the user has
   * successfully set a new password (the backend already cleared it in the DB).
   */
  function clearForcePasswordChange() {
    if (user.value) {
      user.value = { ...user.value, force_password_change: false }
    }
  }

  /**
   * Set full user profile from bootstrap data (replaces the /auth/me call).
   */
  function hydrateFromBootstrap(userData) {
    if (userData) {
      user.value = userData
      authChecked.value = true
      loginComplete.value = true
    }
  }

  async function checkAuth() {
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
        return true
      }
      clearAuth()
    } catch (e) {
      const is401 = e.response?.status === 401
      
      if (is401) {
        try {
          const refreshToken = getToken('webmail_refresh_token')
          const sessionToken = getToken('webmail_session_token')
          
          if (refreshToken || sessionToken) {
            const refreshResponse = await axios.post(getApiOrigin() + '/api/auth/refresh', {
              refresh_token: refreshToken || undefined,
            }, {
              headers: {
                'Content-Type': 'application/json',
                'X-Session-Token': sessionToken || undefined,
              },
              withCredentials: true,
            })
            
            if (refreshResponse.data?.success && refreshResponse.data?.data?.access_token) {
              const data = refreshResponse.data.data
              token.value = data.access_token
              setToken('webmail_token', data.access_token)
              if (data.refresh_token) {
                setToken('webmail_refresh_token', data.refresh_token)
              }
              const retryResponse = await withTimeout(api.get('/auth/me'), 10000)
              if (retryResponse.data.success) {
                user.value = retryResponse.data.data
                authChecked.value = true
                loginComplete.value = true
                return true
              }
            }
          }
        } catch (refreshError) {
          console.warn('Token refresh failed during auth check:', refreshError)
        }
        
        console.warn('Token invalid (401), clearing auth')
        clearAuth()
        authChecked.value = true
        
        if (isIOS() && !window.location.pathname.includes('/login')) {
          const isPWA = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone
          if (!isPWA) {
            window.location.href = '/login'
          }
        }
        return false
      }
      
      if ((isIOS() || window.matchMedia('(display-mode: standalone)').matches) && !e.response) {
        console.warn('Auth check failed (network error), keeping session for PWA:', e.message)
        authChecked.value = true
        if (!user.value && token.value) {
          user.value = { email: 'loading...' }
        }
        return true
      }
      
      clearAuth()
    }
    authChecked.value = true
    return false
  }

  // Helper to check if login is fully complete (for iOS navigation)
  function isLoginComplete() {
    return loginComplete.value && isAuthenticated.value
  }

  /**
   * Initialize auth state from Electron's secure storage.
   * Called after SSO auto-login when the main process has already
   * obtained tokens but the renderer's auth store doesn't know yet.
   */
  async function initFromElectron() {
    if (typeof window === 'undefined' || !window.api?.auth?.getToken) return false
    try {
      const electronToken = await window.api.auth.getToken()
      if (!electronToken) return false

      token.value = electronToken
      setToken('webmail_token', electronToken)

      const payload = decodeTokenPayload(electronToken)
      if (payload?.sub) {
        user.value = { email: payload.sub }
        authChecked.value = true
        loginComplete.value = true
        return true
      }
    } catch (e) {
      console.error('[Auth] initFromElectron failed:', e)
    }
    return false
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
    displayName,
    forcePasswordChange,
    clearForcePasswordChange,
    login,
    logout,
    logoutEverywhere,
    checkAuth,
    checkAuthLocal,
    hydrateFromBootstrap,
    setTokens,
    clearAuth,
    isLoginComplete,
    initFromElectron,
  }
})

