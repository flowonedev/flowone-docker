import axios from "axios"
import { acquire, release, getPriorityForUrl, PRIORITY } from "@/services/requestQueue"
import { getApiOrigin, isNative } from "@/services/serverRegistry"
import { getToken, setToken, clearAllTokens } from "@/services/tokenStorage"

// Stable per-session id. Part of the shared frontend api.js public surface —
// shared modules (e.g. the moodboards websocket service) import it to tag their
// own messages, so this override must re-export it too.
export const SENDER_ID = crypto.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`

const api = axios.create({
  // Resolved per-request below so native targets the user's deployment
  // (https://email.<domain>); '' on web for same-origin relative URLs.
  baseURL: getApiOrigin() + '/api',
})

const inflightGets = new Map()

function buildDedupeKey(config) {
  const url = config.url || ''
  const params = config.params
    ? JSON.stringify(config.params, Object.keys(config.params).sort())
    : ''
  return `GET:${url}:${params}`
}

const originalRequest = api.request.bind(api)

function queuedRequest(config) {
  const priority = config._priority ?? getPriorityForUrl(config.url)
  return acquire(priority)
    .then(() => originalRequest(config))
    .finally(() => release())
}

api.request = function pipelinedRequest(config) {
  const method = (config.method || 'get').toLowerCase()
  if (method === 'get') {
    const key = buildDedupeKey(config)
    if (inflightGets.has(key)) return inflightGets.get(key)
    const promise = queuedRequest(config).finally(() => { inflightGets.delete(key) })
    inflightGets.set(key, promise)
    return promise
  }
  return queuedRequest(config)
}

;['get', 'head', 'delete', 'options'].forEach((method) => {
  api[method] = function (url, config) {
    return api.request({ ...config, method, url })
  }
})

;['post', 'put', 'patch'].forEach((method) => {
  api[method] = function (url, data, config) {
    return api.request({ ...config, method, url, data })
  }
})

api.interceptors.request.use(async (config) => {
  // Point each call at the resolved deployment (native) or relative '' (web).
  config.baseURL = getApiOrigin() + '/api'

  if (!(config.data instanceof FormData)) {
    config.headers['Content-Type'] = config.headers['Content-Type'] || 'application/json'
  }

  const token = localStorage.getItem('webmail_token')
  const sessionToken = localStorage.getItem('webmail_session_token')

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  if (sessionToken) {
    config.headers['X-Session-Token'] = sessionToken
  }

  return config
})

// ============================================================
// Token refresh on 401 (mirrors the shared frontend api.js).
//
// On a 401 for a non-auth endpoint we transparently refresh the access token
// via POST /api/auth/refresh (the refresh token rides in the body AND an
// HttpOnly cookie), retry the original request once, and queue any concurrent
// requests so they all replay with the rotated token. If refresh fails we clear
// auth and let the App.vue watcher redirect to /login (Capacitor-safe: no hard
// window.location which breaks under base './').
// ============================================================

let isRefreshing = false
let refreshSubscribers = []
let isHandling401 = false

// Cross-tab token sync (harmless on native; keeps web/PWA tabs aligned so they
// never refresh with an already-rotated token).
let tokenChannel = null
try {
  tokenChannel = new BroadcastChannel('webmail_token_sync')
  tokenChannel.onmessage = (event) => {
    if (event.data?.type === 'TOKEN_REFRESHED') {
      const { access_token, refresh_token } = event.data
      if (access_token) setToken('webmail_token', access_token)
      if (refresh_token) setToken('webmail_refresh_token', refresh_token)
    }
  }
} catch (_e) {
  /* BroadcastChannel unsupported (older Safari/iOS) — graceful degradation */
}

function onTokenRefreshed(newAccessToken) {
  refreshSubscribers.forEach((cb) => cb(newAccessToken))
  refreshSubscribers = []
}

function addRefreshSubscriber(cb) {
  refreshSubscribers.push(cb)
}

async function attemptTokenRefresh() {
  const refreshToken = getToken('webmail_refresh_token')
  const sessionToken = getToken('webmail_session_token')
  if (!sessionToken) return null

  try {
    // Raw axios so the request/response interceptors don't recurse.
    const response = await axios.post(
      getApiOrigin() + '/api/auth/refresh',
      { refresh_token: refreshToken || undefined },
      {
        headers: {
          'Content-Type': 'application/json',
          'X-Session-Token': sessionToken,
        },
        withCredentials: true,
      }
    )
    const data = response.data?.data
    if (data?.access_token && data?.refresh_token) {
      setToken('webmail_token', data.access_token)
      setToken('webmail_refresh_token', data.refresh_token)
      try {
        tokenChannel?.postMessage({
          type: 'TOKEN_REFRESHED',
          access_token: data.access_token,
          refresh_token: data.refresh_token,
        })
      } catch (_e) { /* best-effort */ }
      return data.access_token
    }
    return null
  } catch (err) {
    console.warn('[API] Token refresh failed:', err.response?.status)
    return null
  }
}

async function clearAuthAndRedirect() {
  if (isHandling401) return
  isHandling401 = true

  clearAllTokens()
  localStorage.removeItem('webmail_token')
  localStorage.removeItem('webmail_refresh_token')
  localStorage.removeItem('webmail_session_token')

  // Reset the reactive auth store so the App.vue watcher drives the redirect to
  // /login and tears down chat sockets/pollers.
  try {
    const { useAuthStore } = await import('@/stores/auth')
    await useAuthStore().clearAuth()
  } catch (_e) { /* store not ready — token clear above still logs out */ }

  // Fallback navigation in case the watcher isn't mounted.
  try {
    const { default: router } = await import('../router/index.js')
    if (router.currentRoute.value.path !== '/login') {
      await router.replace('/login')
    }
  } catch (_e) { /* ignore */ }

  isHandling401 = false
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config

    if (error.response?.status === 401) {
      const url = originalRequest?.url || ''

      // Pre-auth endpoints must never trigger a refresh: login/2fa are
      // nonsensical to refresh, and /auth/refresh itself would recurse.
      // NOTE: /auth/me is intentionally NOT in this list — an expired access
      // token on /auth/me should self-heal via refresh + retry rather than
      // logging the user out (that caused the instant logout on launch).
      const isNoRefreshEndpoint =
        url.includes('/auth/login') ||
        url.includes('/auth/2fa') ||
        url.includes('/auth/refresh')
      if (isNoRefreshEndpoint) {
        return Promise.reject(error)
      }

      // Already refreshed and retried once but still 401 — the session is
      // genuinely dead: clear auth and let App.vue redirect to /login.
      if (originalRequest?._retry) {
        clearAuthAndRedirect()
        return Promise.reject(error)
      }

      // A refresh is already running — queue this request to replay after it.
      if (isRefreshing) {
        return new Promise((resolve) => {
          addRefreshSubscriber((newToken) => {
            originalRequest._retry = true
            originalRequest.headers = originalRequest.headers || {}
            originalRequest.headers.Authorization = `Bearer ${newToken}`
            resolve(api(originalRequest))
          })
        })
      }

      isRefreshing = true
      const newToken = await attemptTokenRefresh()
      isRefreshing = false

      if (newToken) {
        onTokenRefreshed(newToken)
        originalRequest._retry = true
        originalRequest.headers = originalRequest.headers || {}
        originalRequest.headers.Authorization = `Bearer ${newToken}`
        return api(originalRequest)
      }

      clearAuthAndRedirect()
    }

    return Promise.reject(error)
  }
)

// ============================================================
// Multipart upload helper (CapacitorHttp-safe) -- mirrors the shared
// frontend api.js (commit 69d2cee).
//
// This native shell enables CapacitorHttp, which patches XMLHttpRequest/fetch
// and routes them through native code. That native layer CANNOT serialize a
// multipart FormData containing a binary File/Blob - the file part is dropped,
// so the server receives an empty $_FILES and replies "No file uploaded".
//
// Capacitor stashes the ORIGINAL WebView fetch at window.CapacitorWebFetch.
// Using it sends a proper multipart body straight from the WebView. On web we
// keep the normal axios pipeline so onUploadProgress still works.
// ============================================================

function nativeWebFetch() {
  if (typeof window === "undefined") return null
  const f = window.CapacitorWebFetch
  return typeof f === "function" ? f.bind(window) : null
}

// Auth headers mirroring the request interceptor, MINUS X-Sender-Id (not in the
// backend CORS Allow-Headers, so a cross-origin native fetch preflight fails if
// it is sent).
function buildUploadAuthHeaders() {
  const headers = {}
  const token = getToken("webmail_token")
  if (token) headers.Authorization = `Bearer ${token}`
  const sessionToken = getToken("webmail_session_token")
  if (sessionToken) headers["X-Session-Token"] = sessionToken
  const activeAccountId = getToken("webmail_active_account")
  if (activeAccountId && activeAccountId !== "primary") {
    headers["X-Account-Id"] = activeAccountId
  }
  return headers
}

/**
 * POST a multipart FormData payload and return the parsed JSON body
 * ({ success, data, message }). Uses the CapacitorHttp-bypass on native and the
 * normal axios pipeline (with progress) everywhere else.
 *
 * @param {string} path API path beginning with '/'
 * @param {FormData} formData
 * @param {(pct:number)=>void} [onProgress] 0-100 progress callback
 * @returns {Promise<object>} parsed response body
 */
// Hard ceiling on a single upload request. Long enough for a large photo on a
// slow cell connection, short enough that a stalled request fails loudly with a
// "timed out" error instead of spinning forever with no feedback.
const UPLOAD_TIMEOUT_MS = 120000

export async function uploadFormData(path, formData, onProgress) {
  const webFetch = isNative ? nativeWebFetch() : null

  if (webFetch) {
    if (typeof onProgress === "function") onProgress(0)
    const url = getApiOrigin() + "/api" + path

    // Without a timeout the native WebView fetch can hang indefinitely (NAS
    // stall, dead connection, WAF black-holing the body), leaving the UI stuck
    // on a spinner. AbortController turns that into a surfaced "timed out" error.
    const controller =
      typeof AbortController !== "undefined" ? new AbortController() : null
    const timer = controller
      ? setTimeout(() => controller.abort(), UPLOAD_TIMEOUT_MS)
      : null

    let res
    try {
      // No Content-Type header: the WebView sets multipart/form-data + boundary.
      res = await webFetch(url, {
        method: "POST",
        headers: buildUploadAuthHeaders(),
        body: formData,
        credentials: "include",
        signal: controller?.signal,
      })
    } catch (err) {
      // Normalize an AbortController timeout to the same shape axios uses
      // (code 'ECONNABORTED') so callers/describeUploadError report it uniformly.
      if (err?.name === "AbortError") {
        const e = new Error("Upload timed out")
        e.code = "ECONNABORTED"
        throw e
      }
      throw err
    } finally {
      if (timer) clearTimeout(timer)
    }

    let body = null
    try {
      body = await res.json()
    } catch (_) {
      /* non-JSON (e.g. an HTML error page) */
    }

    if (!res.ok) {
      // Chat backend returns { error }, drive backend returns { message }.
      const message =
        body?.error || body?.message || `Upload failed (HTTP ${res.status})`
      const err = new Error(message)
      err.response = {
        status: res.status,
        data: body,
        headers: { "content-type": res.headers?.get?.("content-type") || "" },
      }
      throw err
    }

    if (typeof onProgress === "function") onProgress(100)
    return body || {}
  }

  // Web / desktop: normal pipeline keeps real byte-level upload progress.
  const response = await api.post(path, formData, {
    headers: { "Content-Type": "multipart/form-data" },
    timeout: UPLOAD_TIMEOUT_MS,
    onUploadProgress: (e) => {
      if (typeof onProgress === "function" && e.total) {
        onProgress(Math.round((e.loaded * 100) / e.total))
      }
    },
  })
  return response.data
}

/**
 * Read a File/Blob as a raw base64 string (no "data:*;base64," prefix).
 */
function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = typeof reader.result === "string" ? reader.result : ""
      const comma = result.indexOf(",")
      resolve(comma >= 0 ? result.slice(comma + 1) : result)
    }
    reader.onerror = () => reject(reader.error || new Error("Failed to read file"))
    reader.readAsDataURL(file)
  })
}

/**
 * Upload chat attachments. On the native shells CapacitorHttp strips the binary
 * part out of a multipart FormData in transit, so we send the files as base64
 * JSON over the same native transport every other request already uses. Web and
 * desktop keep multipart so real byte-level upload progress still works. Returns
 * the parsed response body ({ success, data, ... }).
 *
 * @param {string} path API path, e.g. '/chat/conversations/123/attachments'
 * @param {File[]|FileList} files
 * @param {(pct:number)=>void} [onProgress] 0-100 progress callback
 */
export async function uploadChatAttachments(path, files, onProgress) {
  const list = Array.from(files || [])

  if (isNative) {
    // CapacitorHttp cannot report byte-level progress, so we only signal start
    // and finish; the UI shows an indeterminate spinner while pct stays 0.
    if (typeof onProgress === "function") onProgress(0)
    const payloadFiles = await Promise.all(
      list.map(async (file) => ({
        name: file.name,
        type: file.type || "application/octet-stream",
        data: await fileToBase64(file),
      }))
    )
    const response = await api.post(
      path,
      { files: payloadFiles },
      { timeout: UPLOAD_TIMEOUT_MS }
    )
    if (typeof onProgress === "function") onProgress(100)
    return response.data
  }

  const formData = new FormData()
  list.forEach((file) => formData.append("files[]", file))
  return uploadFormData(path, formData, onProgress)
}

export { PRIORITY }
export default api
