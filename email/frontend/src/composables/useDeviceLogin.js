import { ref } from 'vue'
import QRCode from 'qrcode'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { resolveServerBase, isNative } from '@/services/serverRegistry'

/**
 * useDeviceLogin — "scan to sign in" for the device being signed in (Capability B).
 *
 * The phone (or any client) starts an anonymous device request, shows the
 * returned QR (verify_url) plus a 2-digit match number, and polls until another
 * already-signed-in device approves it. On approval it redeems the one-time code
 * via /sso/exchange and hands the tokens to the auth store.
 *
 * Reuses the existing backend (DeviceAuthController + SSOController::exchange) —
 * no backend changes. Mirrors email/FlowOneDrive/src/renderer/components/LoginView.vue.
 */

const POLL_INTERVAL_MS = 2500

export function useDeviceLogin() {
  const auth = useAuthStore()

  // idle | starting | pending | approved | denied | expired | error
  const status = ref('idle')
  const qrDataUrl = ref('')
  const matchNumber = ref(null)
  const verifyUrl = ref('')
  const errorMsg = ref('')

  let requestId = ''
  let pollSecret = ''
  let pollTimer = null
  let deadline = 0
  let cancelled = false

  function stopPolling() {
    if (pollTimer) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  function deviceLabel() {
    try {
      const p = (typeof window !== 'undefined' && window.Capacitor?.getPlatform?.()) || 'web'
      const plat = p === 'ios' ? 'iPhone' : p === 'android' ? 'Android phone' : 'Browser'
      return `FlowOne app (${plat})`
    } catch {
      return 'FlowOne app'
    }
  }

  function reset() {
    stopPolling()
    cancelled = false
    requestId = ''
    pollSecret = ''
    deadline = 0
    qrDataUrl.value = ''
    matchNumber.value = null
    verifyUrl.value = ''
    errorMsg.value = ''
    status.value = 'idle'
  }

  function cancel() {
    cancelled = true
    stopPolling()
    if (status.value === 'pending' || status.value === 'starting') {
      status.value = 'idle'
    }
  }

  async function start(email) {
    reset()
    const addr = String(email || '').trim()
    if (!addr) {
      status.value = 'error'
      errorMsg.value = 'EMAIL_REQUIRED'
      return
    }
    status.value = 'starting'
    cancelled = false
    try {
      // Point the shared api instance at the right backend for this email
      // (multi-domain native). No-op on web (relative URLs hit the origin).
      const base = await resolveServerBase(addr)
      if (isNative && !base) {
        status.value = 'error'
        errorMsg.value = 'SERVER_UNREACHABLE'
        return
      }

      // Pass the target email so the account's already-signed-in sessions can
      // discover this request and pop an approval modal (no QR scan needed).
      const resp = await api.post('/sso/device/start', { device_label: deviceLabel(), email: addr })
      const data = resp.data?.data || {}
      requestId = data.request_id || ''
      pollSecret = data.poll_secret || ''
      matchNumber.value = typeof data.match_number === 'number' ? data.match_number : null
      verifyUrl.value = data.verify_url || ''
      const expiresIn = Number(data.expires_in) || 120
      deadline = Date.now() + expiresIn * 1000

      if (!requestId || !pollSecret || !verifyUrl.value) {
        status.value = 'error'
        errorMsg.value = 'START_FAILED'
        return
      }

      qrDataUrl.value = await QRCode.toDataURL(verifyUrl.value, { width: 240, margin: 1 })
      if (cancelled) return

      status.value = 'pending'
      schedulePoll()
    } catch (e) {
      if (cancelled) return
      status.value = 'error'
      errorMsg.value = e.response?.data?.error || e.response?.data?.message || 'START_FAILED'
    }
  }

  function schedulePoll() {
    stopPolling()
    pollTimer = setTimeout(pollOnce, POLL_INTERVAL_MS)
  }

  async function pollOnce() {
    if (cancelled) return
    if (Date.now() > deadline) {
      status.value = 'expired'
      stopPolling()
      return
    }
    try {
      const resp = await api.post('/sso/device/poll', {
        request_id: requestId,
        poll_secret: pollSecret,
      })
      const data = resp.data?.data || {}
      const s = data.status

      if (s === 'approved') {
        await exchange(data.code)
        return
      }
      if (s === 'denied') {
        status.value = 'denied'
        stopPolling()
        return
      }
      if (s === 'expired' || s === 'consumed') {
        status.value = 'expired'
        stopPolling()
        return
      }
      // still pending — keep polling
      if (!cancelled) schedulePoll()
    } catch (e) {
      const code = e.response?.data?.error
      // A gone/invalid request is terminal; transient errors keep polling.
      if (code === 'DEVICE_REQUEST_INVALID' || code === 'DEVICE_POLL_INVALID') {
        status.value = 'error'
        errorMsg.value = code
        stopPolling()
        return
      }
      if (!cancelled) schedulePoll()
    }
  }

  async function exchange(code) {
    stopPolling()
    if (!code) {
      status.value = 'error'
      errorMsg.value = 'EXCHANGE_FAILED'
      return
    }
    try {
      const resp = await api.post('/sso/exchange', { code, nonce: '' })
      const data = resp.data?.data || {}
      if (!data.access_token) {
        status.value = 'error'
        errorMsg.value = 'EXCHANGE_FAILED'
        return
      }
      auth.setTokens(data)
      status.value = 'approved'
    } catch (e) {
      status.value = 'error'
      errorMsg.value = e.response?.data?.error || e.response?.data?.message || 'EXCHANGE_FAILED'
    }
  }

  return {
    status,
    qrDataUrl,
    matchNumber,
    verifyUrl,
    errorMsg,
    start,
    cancel,
    reset,
  }
}
