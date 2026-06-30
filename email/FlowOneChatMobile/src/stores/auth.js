import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import {
  isNative,
  getServerBase,
  setServerBase,
  clearServerBase,
  deriveBaseFromEmail,
  getCachedBase,
  resolveServerBase,
  domainPart,
} from '@/services/serverRegistry'

function withTimeout(promise, timeoutMs = 30000) {
  return Promise.race([
    promise,
    new Promise((_, reject) =>
      setTimeout(() => reject(new Error('Request timeout')), timeoutMs)
    )
  ])
}

export const useAuthStore = defineStore('auth', () => {
  const storedToken = localStorage.getItem('webmail_token')

  // Native relaunch recovery: rebuild the deployment base from the token's
  // subject (the user's email) if it was lost (app update / cleared storage).
  if (isNative && storedToken && !getServerBase()) {
    try {
      const part = storedToken.split('.')[1]
      if (part) {
        const payload = JSON.parse(atob(part.replace(/-/g, '+').replace(/_/g, '/')))
        if (payload?.sub) {
          // Prefer the cached resolution from the last login (shared tenants
          // live on flowone.pro, not email.<domain>); convention is fallback.
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
  const authChecked = ref(false)
  const loginComplete = ref(false)
  const userName = ref(null)
  const userEmailValue = ref(null)

  async function initFromElectron() {}

  const isAuthenticated = computed(() => !!token.value && !!user.value)

  const hasToken = computed(() => !!token.value || !!localStorage.getItem('webmail_token'))

  const userEmail = computed(() => user.value?.email || '')

  const displayName = computed(() => {
    if (userName.value) return userName.value
    return user.value?.display_name || userEmail.value.split('@')[0]
  })

  async function login(email, password) {
    loading.value = true
    error.value = null
    loginComplete.value = false

    try {
      // Native: resolve the deployment for this email before the call (via the
      // discovery host) — flowone.pro for shared tenants, email.<domain> for
      // dedicated deployments.
      if (isNative) {
        await resolveServerBase(email)
      }

      const deviceToken = localStorage.getItem('webmail_device_token')

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

        try {
          localStorage.setItem('webmail_token', data.access_token)
          localStorage.setItem('webmail_refresh_token', data.refresh_token)
          if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
          if (data.device_token) localStorage.setItem('webmail_device_token', data.device_token)
        } catch (storageError) {
          console.warn('Failed to save to localStorage:', storageError)
        }

        token.value = data.access_token
        user.value = data.user
        userEmailValue.value = data.user?.email || email
        userName.value = data.user?.display_name || email.split('@')[0]
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
    try {
      localStorage.setItem('webmail_token', data.access_token)
      localStorage.setItem('webmail_refresh_token', data.refresh_token)
      if (data.session_token) localStorage.setItem('webmail_session_token', data.session_token)
    } catch (_) {}
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
    localStorage.removeItem('webmail_token')
    localStorage.removeItem('webmail_refresh_token')
  }

  async function unregisterPush() {
    try {
      const { pushNotifications } = await import('@/services/pushNotifications')
      await pushNotifications.unregister()
    } catch (_) { /* best-effort; never blocks logout */ }
  }

  async function logout() {
    await unregisterPush()
    try { await api.post('/auth/logout') } catch (_) {}
    await clearAuth()
    localStorage.removeItem('webmail_session_token')
  }

  async function logoutEverywhere() {
    await unregisterPush()
    try { await api.post('/sessions/revoke-all') } catch (_) {}
    await clearAuth()
    localStorage.removeItem('webmail_session_token')
    localStorage.removeItem('webmail_device_token')
    localStorage.removeItem('webmail_active_account')
    // Forget the resolved deployment so the next user re-derives from their email.
    clearServerBase()
  }

  async function checkAuth() {
    if (!token.value) { authChecked.value = true; return false }

    try {
      const response = await withTimeout(api.get('/auth/me'), 15000)
      if (response.data.success) {
        user.value = response.data.data
        userEmailValue.value = response.data.data.email
        userName.value = response.data.data.display_name
        authChecked.value = true
        loginComplete.value = true
        return true
      }
      await clearAuth()
    } catch (e) {
      // Only a real 401 (the api layer has already tried to refresh the token
      // by this point) means the session is dead. Network errors / timeouts
      // must NOT log the user out — that was the cause of the instant logout on
      // launch when the deployment was briefly unreachable. Keep the token and
      // let the app retry once it's back online.
      if (e.response?.status === 401) {
        await clearAuth()
      }
      authChecked.value = true
      return false
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
