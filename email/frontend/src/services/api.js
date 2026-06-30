import axios from "axios";
import { getToken, setToken, clearAllTokens } from "./tokenStorage";
import { recordNetworkEntry } from "@/utils/logCapture";
import { acquire, release, getPriorityForUrl, PRIORITY } from "./requestQueue";
import { getApiOrigin, isNative } from "./serverRegistry";
import { pauseAllPollers, looksLikeSecurityBlock } from "./pollerBreaker";

export const SENDER_ID = crypto.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`

const api = axios.create({
  // baseURL is resolved per-request in the interceptor below so that the
  // native apps can target the correct per-deployment server (email.<domain>)
  // once the user's email domain is known at login. On web this stays ''/api.
  baseURL: getApiOrigin() + "/api",
  headers: {
    "Content-Type": "application/json",
  },
  withCredentials: true,
});

// ---------------------------------------------------------------------------
// Smart request pipeline: queue + deduplication
//
// 1) Concurrency queue  -- max 6 in-flight requests, priority-sorted.
//    Prevents page-load bursts from overwhelming the server.
// 2) GET deduplication   -- identical concurrent GETs share one HTTP request.
//    e.g. 3x /settings from different stores = 1 actual call.
//
// All HTTP methods are overridden so every call goes through this pipeline.
// ---------------------------------------------------------------------------
const inflightGets = new Map();

function buildDedupeKey(config) {
  const url = config.url || '';
  const params = config.params
    ? JSON.stringify(config.params, Object.keys(config.params).sort())
    : '';
  return `GET:${url}:${params}`;
}

const originalRequest = api.request.bind(api);

function queuedRequest(config) {
  const priority = config._priority ?? getPriorityForUrl(config.url);
  return acquire(priority)
    .then(() => originalRequest(config))
    .finally(() => release());
}

api.request = function pipelinedRequest(config) {
  const method = (config.method || 'get').toLowerCase();

  // Defense-in-depth: refuse calls with a null/missing URL. Without this,
  // axios concatenates baseURL with `null` (or '') and dispatches requests
  // like GET /api/?page=1&limit=50 which mask real bugs as confusing 404s.
  // Callers that build URLs from helpers that may return null (e.g.
  // folderCollectionUrl during the folders-not-yet-hydrated race) MUST
  // guard before calling -- this is the last-line backstop.
  if (config.url === null || config.url === undefined || config.url === '') {
    const err = new Error('[API] Request rejected: URL is null/empty. This usually means a folder/resource id was unresolved at call time.');
    err.config = config;
    err.code = 'ERR_INVALID_URL';
    return Promise.reject(err);
  }

  if (method === 'get') {
    const key = buildDedupeKey(config);
    if (inflightGets.has(key)) {
      return inflightGets.get(key);
    }
    const promise = queuedRequest(config).finally(() => {
      inflightGets.delete(key);
    });
    inflightGets.set(key, promise);
    return promise;
  }

  return queuedRequest(config);
};

['get', 'head', 'delete', 'options'].forEach((method) => {
  api[method] = function (url, config) {
    return api.request({ ...config, method, url });
  };
});
['post', 'put', 'patch'].forEach((method) => {
  api[method] = function (url, data, config) {
    return api.request({ ...config, method, url, data });
  };
});

// Track if we're already handling a 401 to prevent multiple redirects
let isHandling401 = false;

// Track if a token refresh is in progress (prevent concurrent refresh requests)
let isRefreshing = false;
let refreshSubscribers = [];

// ============================================================
// Cross-tab token sync via BroadcastChannel
// When one tab refreshes tokens, broadcast to all other tabs
// so they use the new tokens and never attempt to refresh with
// the old (already-rotated) refresh token. Prevents the
// "refresh token replay attack" false positive that kills sessions.
// ============================================================
let tokenChannel = null;
try {
  tokenChannel = new BroadcastChannel('webmail_token_sync');
  tokenChannel.onmessage = (event) => {
    if (event.data?.type === 'TOKEN_REFRESHED') {
      const { access_token, refresh_token } = event.data;
      if (access_token) setToken('webmail_token', access_token);
      if (refresh_token) setToken('webmail_refresh_token', refresh_token);
      console.log('[API] Tokens synced from another tab via BroadcastChannel');
    }
  };
} catch (e) {
  // BroadcastChannel not supported (older Safari, some iOS) — graceful degradation
  // The backend grace period still protects multi-tab scenarios
}

function onTokenRefreshed(newAccessToken) {
  refreshSubscribers.forEach((cb) => cb(newAccessToken));
  refreshSubscribers = [];
}

function addRefreshSubscriber(cb) {
  refreshSubscribers.push(cb);
}

// Request interceptor to add auth token, session token, and active account
api.interceptors.request.use((config) => {
  // Resolve the backend per request. On native this points the call at the
  // user's deployment (https://email.<domain>); on web it's '' (relative).
  // Done here (not at create time) because the server is unknown until the
  // user's email domain is captured at login.
  config.baseURL = getApiOrigin() + "/api";

  // Let axios auto-set Content-Type with proper boundary for FormData uploads
  if (config.data instanceof FormData) {
    if (typeof config.headers?.delete === 'function') {
      config.headers.delete('Content-Type');
    }
    delete config.headers['Content-Type'];
    delete config.headers['content-type'];
  }

  const token = getToken("webmail_token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  // Add session token for session tracking
  const sessionToken = getToken("webmail_session_token");
  if (sessionToken) {
    config.headers["X-Session-Token"] = sessionToken;
  }

  // Unique per-tab ID so the backend can tag WS broadcasts
  config.headers["X-Sender-Id"] = SENDER_ID;

  // Add active account ID for multi-account support
  const activeAccountId = getToken("webmail_active_account");
  if (activeAccountId && activeAccountId !== "primary") {
    config.headers["X-Account-Id"] = activeAccountId;
  }

  // Send cookies (for HttpOnly refresh token)
  config.withCredentials = true;

  return config;
});

// ============================================================
// Multipart upload helper (CapacitorHttp-safe)
//
// The native shells enable CapacitorHttp, which patches XMLHttpRequest/fetch
// and routes them through native code. That native layer CANNOT serialize a
// multipart FormData that contains a binary File/Blob - the file part is
// dropped, so the server receives an empty $_FILES and replies "No file
// uploaded". (Desktop/web is unaffected: it uses the real browser XHR.)
//
// Capacitor stashes the ORIGINAL WebView fetch at window.CapacitorWebFetch.
// Using it sends a proper multipart body straight from the WebView. The backend
// already allows the native localhost / capacitor:// origin via credentialed
// CORS, so this round-trips fine. On web we keep the normal axios pipeline so
// real upload progress (onUploadProgress) still works.
// ============================================================

function nativeWebFetch() {
  if (typeof window === "undefined") return null;
  const f = window.CapacitorWebFetch;
  return typeof f === "function" ? f.bind(window) : null;
}

// Auth headers mirroring the request interceptor, MINUS X-Sender-Id: that header
// is not in the backend's CORS Allow-Headers, and a cross-origin native fetch
// would fail preflight if it were sent. It only tags WS broadcasts for dedupe,
// which is non-critical for an upload (the device refetches after uploading).
function buildUploadAuthHeaders() {
  const headers = {};
  const token = getToken("webmail_token");
  if (token) headers.Authorization = `Bearer ${token}`;
  const sessionToken = getToken("webmail_session_token");
  if (sessionToken) headers["X-Session-Token"] = sessionToken;
  const activeAccountId = getToken("webmail_active_account");
  if (activeAccountId && activeAccountId !== "primary") {
    headers["X-Account-Id"] = activeAccountId;
  }
  return headers;
}

/**
 * POST a multipart FormData payload and return the parsed JSON body
 * ({ success, data, message }). Transparently uses the CapacitorHttp-bypass on
 * native and the normal axios pipeline (with progress) everywhere else.
 *
 * @param {string} path API path beginning with '/', e.g. '/drive/upload-versioned'
 * @param {FormData} formData
 * @param {(pct:number)=>void} [onProgress] 0-100 progress callback
 * @returns {Promise<object>} parsed response body
 */
// Hard ceiling on a single upload request. Long enough for a large photo on a
// slow cell connection, short enough that a stalled request fails loudly with a
// "timed out" error instead of spinning forever with no feedback.
const UPLOAD_TIMEOUT_MS = 120000;

export async function uploadFormData(path, formData, onProgress) {
  const webFetch = isNative ? nativeWebFetch() : null;

  if (webFetch) {
    if (typeof onProgress === "function") onProgress(0);
    const url = getApiOrigin() + "/api" + path;

    // Without a timeout the native WebView fetch can hang indefinitely (NAS
    // stall, dead connection, WAF black-holing the body), leaving the UI stuck
    // on a spinner. AbortController turns that into a surfaced "timed out" error.
    const controller =
      typeof AbortController !== "undefined" ? new AbortController() : null;
    const timer = controller
      ? setTimeout(() => controller.abort(), UPLOAD_TIMEOUT_MS)
      : null;

    let res;
    try {
      // No Content-Type header: the WebView sets multipart/form-data + boundary.
      res = await webFetch(url, {
        method: "POST",
        headers: buildUploadAuthHeaders(),
        body: formData,
        credentials: "include",
        signal: controller?.signal,
      });
    } catch (err) {
      // Normalize an AbortController timeout to the same shape axios uses
      // (code 'ECONNABORTED') so callers/describeUploadError report it uniformly.
      if (err?.name === "AbortError") {
        const e = new Error("Upload timed out");
        e.code = "ECONNABORTED";
        throw e;
      }
      throw err;
    } finally {
      if (timer) clearTimeout(timer);
    }

    let body = null;
    try {
      body = await res.json();
    } catch (_) {
      /* non-JSON (e.g. an HTML error page) */
    }

    if (!res.ok) {
      // Chat backend returns { error }, drive backend returns { message }.
      const message =
        body?.error || body?.message || `Upload failed (HTTP ${res.status})`;
      const err = new Error(message);
      err.response = {
        status: res.status,
        data: body,
        headers: { "content-type": res.headers?.get?.("content-type") || "" },
      };
      throw err;
    }

    if (typeof onProgress === "function") onProgress(100);
    return body || {};
  }

  // Web / desktop: normal pipeline keeps real byte-level upload progress.
  const response = await api.post(path, formData, {
    headers: { "Content-Type": "multipart/form-data" },
    timeout: UPLOAD_TIMEOUT_MS,
    onUploadProgress: (e) => {
      if (typeof onProgress === "function" && e.total) {
        onProgress(Math.round((e.loaded * 100) / e.total));
      }
    },
  });
  return response.data;
}

/**
 * Read a File/Blob as a raw base64 string (no "data:*;base64," prefix).
 */
function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = typeof reader.result === "string" ? reader.result : "";
      const comma = result.indexOf(",");
      resolve(comma >= 0 ? result.slice(comma + 1) : result);
    };
    reader.onerror = () => reject(reader.error || new Error("Failed to read file"));
    reader.readAsDataURL(file);
  });
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
  const list = Array.from(files || []);

  if (isNative) {
    // CapacitorHttp cannot report byte-level progress, so we only signal start
    // and finish; the UI shows an indeterminate spinner while pct stays 0.
    if (typeof onProgress === "function") onProgress(0);
    const payloadFiles = await Promise.all(
      list.map(async (file) => ({
        name: file.name,
        type: file.type || "application/octet-stream",
        data: await fileToBase64(file),
      }))
    );
    const response = await api.post(
      path,
      { files: payloadFiles },
      { timeout: UPLOAD_TIMEOUT_MS }
    );
    if (typeof onProgress === "function") onProgress(100);
    return response.data;
  }

  const formData = new FormData();
  list.forEach((file) => formData.append("files[]", file));
  return uploadFormData(path, formData, onProgress);
}

/**
 * Clear service worker cache (important for iOS PWA)
 */
async function clearServiceWorkerCache() {
  try {
    if ('caches' in window) {
      const cacheNames = await caches.keys();
      await Promise.all(cacheNames.map(name => caches.delete(name)));
    }
  } catch (e) {
    console.warn('Failed to clear cache:', e);
  }
}

/**
 * Clear all auth tokens and redirect to login.
 * Skips redirect for public routes (shared boards/folders) — visitors have no
 * auth tokens and stray 401s from non-critical calls must not kick them out.
 */
async function clearAuthAndRedirect() {
  // Prevent multiple redirects
  if (isHandling401) return;

  // Never redirect if we're on a public route — these don't require login
  const path = window.location.pathname;
  if (path.startsWith("/mood/share/") || path.startsWith("/share/") || path.startsWith("/portal")) {
    return;
  }

  isHandling401 = true;
  
  // Clear tokens from both session and local storage
  setToken("webmail_token", null);
  setToken("webmail_refresh_token", null);
  setToken("webmail_session_token", null);
  localStorage.removeItem("webmail_token");
  localStorage.removeItem("webmail_refresh_token");
  localStorage.removeItem("webmail_session_token");

  // Clear service worker cache to prevent serving stale cached app
  await clearServiceWorkerCache();

  // Only redirect if not already on login page
  if (!path.includes("/login")) {
    // Force hard redirect to break out of PWA cached state
    window.location.href = "/login";
  } else {
    isHandling401 = false;
  }
}

/**
 * Attempt to refresh the access token using the refresh token.
 * On success: stores new tokens and returns the new access token.
 * On failure: clears auth and redirects to login.
 */
async function attemptTokenRefresh() {
  const refreshToken = getToken("webmail_refresh_token");
  const sessionToken = getToken("webmail_session_token");
  
  if (!sessionToken) {
    return null;
  }
  
  try {
    // Use raw axios to avoid interceptors (prevent infinite loop)
    // Send refresh_token in body if available; server also checks HttpOnly cookie
    const response = await axios.post(getApiOrigin() + "/api/auth/refresh", {
      refresh_token: refreshToken || undefined,
    }, {
      headers: {
        "Content-Type": "application/json",
        "X-Session-Token": sessionToken,
      },
      withCredentials: true, // Send HttpOnly cookie
    });
    
    const data = response.data?.data;
    if (data?.access_token && data?.refresh_token) {
      setToken("webmail_token", data.access_token);
      setToken("webmail_refresh_token", data.refresh_token);
      
      // Broadcast new tokens to all other tabs so they don't use the old
      // (already-rotated) refresh token and trigger a false replay detection
      try {
        tokenChannel?.postMessage({
          type: 'TOKEN_REFRESHED',
          access_token: data.access_token,
          refresh_token: data.refresh_token,
        });
      } catch (e) { /* BroadcastChannel may not be available */ }
      
      return data.access_token;
    }
    
    return null;
  } catch (err) {
    console.warn("[API] Token refresh failed:", err.response?.status);
    return null;
  }
}

// Stamp request start time for duration tracking
api.interceptors.request.use((config) => {
  config._startTime = Date.now()
  return config
})

// Record every response (success + error) into the network log buffer
api.interceptors.response.use(
  (response) => {
    const cfg = response.config || {}
    recordNetworkEntry({
      method: (cfg.method || 'get').toUpperCase(),
      url: cfg.url,
      status: response.status,
      duration_ms: cfg._startTime ? Date.now() - cfg._startTime : null,
    })
    return response
  },
  (error) => {
    const cfg = error.config || {}
    recordNetworkEntry({
      method: (cfg.method || 'get').toUpperCase(),
      url: cfg.url,
      status: error.response?.status ?? null,
      duration_ms: cfg._startTime ? Date.now() - cfg._startTime : null,
      error: error.message || 'Network error',
      response_body: error.response?.data ?? null,
    })
    return Promise.reject(error)
  }
)

// Response interceptor for 429 rate limiting.
//
// Phase 1 of the OAuth rewrite: retry AT MOST ONCE. The old policy of 3
// retries with synthetic delays amplified rate-limit events into "5
// concurrent requests * 3 retries each = 20 hits in a window" which
// fed CPGuard's brute-force detector. With a single retry, the worst
// case is 2x the original traffic instead of 4x.
//
// We also only retry if the server gave us an explicit Retry-After
// header (or retry_after field). When the limit is from CPGuard or
// ModSec, neither is present, so we fall through to the security-block
// detector below.
const MAX_429_RETRIES = 1
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const config = error.config
    if (error.response?.status === 429 && config) {
      const retryAfter =
        error.response?.data?.retry_after ??
        error.response?.headers?.['retry-after']
      // No Retry-After hint = probably not our own rate limiter. Don't retry,
      // just propagate the error and let the breaker handle it.
      if (retryAfter === undefined || retryAfter === null) {
        return Promise.reject(error)
      }
      config._retryCount = (config._retryCount || 0) + 1
      if (config._retryCount <= MAX_429_RETRIES) {
        const waitMs = Math.max(500, Number(retryAfter) * 1000)
        console.warn(`[API] Rate limited (429) on ${config.url} - retry ${config._retryCount}/${MAX_429_RETRIES} in ${waitMs}ms`)
        await new Promise(resolve => setTimeout(resolve, waitMs))
        return api(config)
      }
    }
    return Promise.reject(error)
  }
)

// Response interceptor for security-plugin 403s.
//
// Phase 1 of the OAuth rewrite. CPGuard / ModSec / Cloudflare WAF
// return 403 with a non-JSON HTML body when they ban the IP. None of
// our pollers handle 403 — they swallow it and tick again on schedule.
// Each tick refreshes the ban timer, so the user stays locked out long
// after the underlying behaviour stopped.
//
// We detect the security-block signature here and pause ALL pollers
// for 15 minutes via the breaker, giving CPGuard a chance to age out
// the ban window. The user can still drive the app interactively (the
// breaker only affects setInterval-driven background traffic).
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (looksLikeSecurityBlock(error)) {
      pauseAllPollers('security_block_403', 15 * 60 * 1000)
    }
    return Promise.reject(error)
  }
)

// Response interceptor: OAuth re-consent required.
// Backend returns 401 + action=oauth_reauth_required when the stored OAuth refresh
// token is unusable (decrypt failed, revoked, XOAUTH2 rejected, etc). Since the
// login flow now grants full Gmail scope in a single consent, hitting this path
// means the user's grant has actually become invalid - the only correct recovery
// is to send them through "Sign in with Google" again. Clear tokens and redirect
// to /login. The merged-flow consent screen will re-establish a working session.
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const data = error.response?.data;
    if (error.response?.status === 401 && data?.action === 'oauth_reauth_required') {
      console.warn('[API] OAuth re-auth required - clearing session and redirecting to login', {
        reason: data.reason,
        provider: data.provider,
      });
      clearAuthAndRedirect();
    }
    // Account suspended from the admin panel: the session is otherwise still
    // "valid", so don't attempt a token refresh — log the user out immediately.
    if (error.response?.status === 401 && data?.reason === 'account_suspended') {
      console.warn('[API] Account suspended - clearing session and redirecting to login');
      clearAuthAndRedirect();
    }
    return Promise.reject(error);
  }
);

// Response interceptor for error handling (with token refresh)
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;
    
    // Handle 401 errors (token expired or session invalid)
    if (error.response?.status === 401) {
      // OAuth re-auth signal is fully handled by the dedicated interceptor above
      // (clears tokens + redirects to /login). Just reject here so callers stop.
      if (error.response.data?.action === 'oauth_reauth_required') {
        return Promise.reject(error);
      }
      // Account suspended is also handled by the dedicated interceptor above
      // (immediate logout). A token refresh would just succeed and loop, so stop.
      if (error.response.data?.reason === 'account_suspended') {
        return Promise.reject(error);
      }
      // On public routes (shared boards/folders/portal), 401s are expected for any
      // non-public API call. Just reject silently — never attempt refresh or redirect.
      const path = window.location.pathname;
      if (path.startsWith("/mood/share/") || path.startsWith("/share/") || path.startsWith("/portal")) {
        return Promise.reject(error);
      }

      // Skip refresh for auth endpoints and already-retried requests
      const isAuthEndpoint = originalRequest?.url?.includes('/auth/');
      if (isAuthEndpoint || originalRequest._retry) {
        if (!originalRequest?.url?.includes('/auth/me')) {
          clearAuthAndRedirect();
        }
        return Promise.reject(error);
      }
      
      // If a refresh is already in progress, queue this request
      if (isRefreshing) {
        return new Promise((resolve) => {
          addRefreshSubscriber((newToken) => {
            originalRequest.headers.Authorization = `Bearer ${newToken}`;
            originalRequest._retry = true;
            resolve(api(originalRequest));
          });
        });
      }
      
      // Attempt token refresh
      isRefreshing = true;
      const newToken = await attemptTokenRefresh();
      isRefreshing = false;
      
      if (newToken) {
        // Refresh succeeded — retry original request + all queued requests
        onTokenRefreshed(newToken);
        originalRequest.headers.Authorization = `Bearer ${newToken}`;
        originalRequest._retry = true;
        return api(originalRequest);
      }
      
      // Refresh failed — clear auth and redirect to login
      clearAuthAndRedirect();
    }

    return Promise.reject(error);
  }
);

export default api;
