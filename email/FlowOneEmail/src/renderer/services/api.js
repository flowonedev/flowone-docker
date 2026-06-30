import axios from "axios";
import { acquire, release, getPriorityForUrl, PRIORITY } from "@/services/requestQueue";

/**
 * Detect if running in Electron environment
 */
const isElectron = () => typeof window !== 'undefined' && window.api !== undefined;

/**
 * Get the API base URL
 * - In Electron: use local proxy server (which forwards to remote API)
 * - In browser: use relative /api path
 */
const getBaseURL = async () => {
  if (isElectron()) {
    // Get proxy port from main process
    try {
      const proxyPort = await window.api.getProxyPort();
      if (proxyPort) {
        return `http://127.0.0.1:${proxyPort}/api`;
      }
    } catch (e) {
      console.warn('[API] Could not get proxy port, using direct URL:', e);
    }
    // Fallback to direct URL
    const config = await window.api.config.getAll();
    return (config.apiUrl || 'https://flowone.pro') + '/api';
  }
  return '/api';
};

// Create axios instance
const api = axios.create({
  headers: {
    "Content-Type": "application/json",
  },
});

// ---------------------------------------------------------------------------
// Smart request pipeline: queue + deduplication (mirrors web frontend)
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

// Initialize baseURL (will be set properly on first request)
let baseURLInitialized = false;

// Request interceptor to add auth token, session token, and active account
api.interceptors.request.use(async (config) => {
  // Set baseURL on first request
  if (!baseURLInitialized) {
    config.baseURL = await getBaseURL();
    api.defaults.baseURL = config.baseURL;
    baseURLInitialized = true;
  }

  if (config.data instanceof FormData) {
    if (typeof config.headers?.delete === 'function') {
      config.headers.delete('Content-Type');
    }
    delete config.headers['Content-Type'];
    delete config.headers['content-type'];
  }

  // Get auth token from appropriate source
  let token;
  if (isElectron()) {
    token = await window.api.auth.getToken();
  } else {
    token = localStorage.getItem("webmail_token");
  }
  
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  // Add session token for session tracking
  let sessionToken;
  if (isElectron()) {
    const allConfig = await window.api.config.getAll();
    sessionToken = allConfig.sessionToken;
    // Debug: log session token presence on first few requests
    if (!config._sessionLogged) {
      console.log('[API] Request to', config.url, '- sessionToken:', sessionToken ? `present (${sessionToken.length} chars)` : 'MISSING');
      config._sessionLogged = true;
    }
  } else {
    sessionToken = localStorage.getItem("webmail_session_token");
  }
  
  if (sessionToken) {
    config.headers["X-Session-Token"] = sessionToken;
  }

  // Add active account ID for multi-account support (browser only for now)
  if (!isElectron()) {
    const activeAccountId = localStorage.getItem("webmail_active_account");
    if (activeAccountId && activeAccountId !== "primary") {
      config.headers["X-Account-Id"] = activeAccountId;
    }
  }

  return config;
});

/**
 * Clear all auth tokens and redirect to login
 */
async function clearAuthAndRedirect() {
  // Prevent multiple redirects
  if (isHandling401) return;
  isHandling401 = true;
  
  if (isElectron()) {
    // Clear auth via Electron IPC
    await window.api.auth.clearToken();
    // Navigate to login (router will handle this)
    window.dispatchEvent(new CustomEvent('auth-failed'));
  } else {
    // Browser mode
    localStorage.removeItem("webmail_token");
    localStorage.removeItem("webmail_refresh_token");
    localStorage.removeItem("webmail_session_token");

    // Only redirect if not already on login page
    if (!window.location.pathname.includes("/login")) {
      window.location.href = "/login";
    }
  }
  
  isHandling401 = false;
}

// Response interceptor for 429 rate limiting (retry with exponential backoff)
const MAX_429_RETRIES = 3;
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const config = error.config;
    if (error.response?.status === 429 && config) {
      config._retryCount = (config._retryCount || 0) + 1;
      if (config._retryCount <= MAX_429_RETRIES) {
        const retryAfter = error.response?.data?.retry_after || error.response?.headers?.['retry-after'] || 2;
        const waitMs = (Number(retryAfter) + config._retryCount) * 1000;
        console.warn(`[API] Rate limited (429) on ${config.url} - retry ${config._retryCount}/${MAX_429_RETRIES} in ${waitMs}ms`);
        await new Promise(resolve => setTimeout(resolve, waitMs));
        return api(config);
      }
    }
    return Promise.reject(error);
  }
);

// Response interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    // Handle 401 errors (token expired or session invalid)
    if (error.response?.status === 401) {
      const isAuthCheck = error.config?.url?.includes('/auth/me');
      const responseData = error.response?.data;
      
      // Detect "ghost session" — JWT is valid but session password is missing.
      // This happens when the config store was wiped but secure storage (JWT)
      // survived, or when the server-side session expired.
      // The backend returns { reason: 'no_password', action: 'logout' }.
      const isGhostSession = responseData?.reason === 'no_password' || responseData?.action === 'logout';

      if (isElectron()) {
        if (isGhostSession) {
          // Ghost session: JWT works but IMAP password is gone — must re-login
          console.warn('[API] Ghost session detected (no_password) from', error.config?.url, '— forcing re-login');
          await clearAuthAndRedirect();
        } else if (!isAuthCheck) {
          // Regular 401 from non-auth endpoint — don't clear auth in Electron
          console.warn('[API] 401 from', error.config?.url, '– not clearing auth (Electron, non-auth-check)');
        }
      } else {
        // Browser mode: clear tokens and redirect on non-auth-check 401
        if (!isAuthCheck) {
          await clearAuthAndRedirect();
        }
      }
    }

    return Promise.reject(error);
  }
);

/**
 * Helper function to check if we're online
 */
export const isOnline = () => {
  if (isElectron()) {
    // Main process tracks this more reliably
    return navigator.onLine;
  }
  return navigator.onLine;
};

/**
 * Export isElectron for use by other modules
 */
export { isElectron };

export default api;
