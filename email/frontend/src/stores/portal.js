/**
 * Portal Store - Pinia store for Client Portal state
 * 
 * Manages portal authentication (magic link sessions), user info,
 * and cached portal data. Completely independent from the main auth store.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import portalApi from '@/services/portalApi'

export const usePortalStore = defineStore('portal', () => {
  // Auth state
  const sessionToken = ref(localStorage.getItem('portal_session_token') || null)
  const user = ref(JSON.parse(localStorage.getItem('portal_user') || 'null'))
  const loading = ref(false)
  const authChecked = ref(false)

  // Computed
  const isAuthenticated = computed(() => !!sessionToken.value && !!user.value)
  const clientId = computed(() => user.value?.client_id || null)
  const clientName = computed(() => user.value?.client_name || '')
  const userName = computed(() => user.value?.name || user.value?.email || '')

  /**
   * Consume a magic link token and establish portal session
   */
  async function consumeMagicLink(token) {
    loading.value = true
    try {
      const response = await portalApi.get(`/portal/auth/${token}`)
      if (response.data?.success && response.data?.data) {
        const data = response.data.data
        sessionToken.value = data.session_token
        user.value = data.portal_user

        // Persist
        localStorage.setItem('portal_session_token', data.session_token)
        localStorage.setItem('portal_user', JSON.stringify(data.portal_user))

        authChecked.value = true
        return { success: true }
      }
      return { success: false, error: 'Invalid response' }
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to authenticate'
      const code = err.response?.data?.code || 'error'
      return { success: false, error: msg, code }
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch current portal user info (validates session)
   */
  async function fetchMe() {
    if (!sessionToken.value) {
      authChecked.value = true
      return false
    }
    loading.value = true
    try {
      const response = await portalApi.get('/portal/me')
      if (response.data?.success && response.data?.data) {
        user.value = response.data.data
        localStorage.setItem('portal_user', JSON.stringify(response.data.data))
        authChecked.value = true
        return true
      }
      clearSession()
      return false
    } catch (err) {
      clearSession()
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Request a new magic link by email
   */
  async function requestLink(email) {
    loading.value = true
    try {
      const response = await portalApi.post('/portal/request-link', { email })
      return { success: true, message: response.data?.data?.message || 'Check your email' }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Request failed' }
    } finally {
      loading.value = false
    }
  }

  /**
   * Logout (end portal session)
   */
  async function logout() {
    try {
      if (sessionToken.value) {
        await portalApi.post('/portal/logout')
      }
    } catch (e) {
      // Ignore errors
    }
    clearSession()
  }

  /**
   * Clear session data
   */
  function clearSession() {
    sessionToken.value = null
    user.value = null
    authChecked.value = true
    localStorage.removeItem('portal_session_token')
    localStorage.removeItem('portal_user')
  }

  /**
   * Check if we have a stored session on startup
   */
  async function checkAuth() {
    if (authChecked.value) return isAuthenticated.value
    return await fetchMe()
  }

  return {
    // State
    sessionToken,
    user,
    loading,
    authChecked,

    // Computed
    isAuthenticated,
    clientId,
    clientName,
    userName,

    // Actions
    consumeMagicLink,
    fetchMe,
    requestLink,
    logout,
    clearSession,
    checkAuth,
  }
})

