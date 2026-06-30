import axios from "axios";
import { acquire, release, getPriorityForUrl, PRIORITY } from "@/services/requestQueue";

const isElectron = () => typeof window !== 'undefined' && window.api !== undefined;

const getBaseURL = async () => {
  if (isElectron()) {
    try {
      const proxyPort = await window.api.getProxyPort();
      if (proxyPort) {
        return `http://127.0.0.1:${proxyPort}/api`;
      }
    } catch (e) {
      console.warn('[API] Could not get proxy port:', e);
    }
    const config = await window.api.config.getAll();
    return (config.apiUrl || 'https://flowone.pro') + '/api';
  }
  return '/api';
};

const api = axios.create({});

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
    if (inflightGets.has(key)) return inflightGets.get(key);
    const promise = queuedRequest(config).finally(() => { inflightGets.delete(key); });
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

let baseURLReady = false;
let pendingBaseURL = null;

async function ensureBaseURL() {
  if (baseURLReady) return;
  if (pendingBaseURL) return pendingBaseURL;
  pendingBaseURL = getBaseURL().then((url) => {
    api.defaults.baseURL = url;
    baseURLReady = true;
    pendingBaseURL = null;
    console.log('[API] Base URL set:', url);
  });
  return pendingBaseURL;
}

api.interceptors.request.use(async (config) => {
  await ensureBaseURL();

  if (!(config.data instanceof FormData)) {
    config.headers['Content-Type'] = config.headers['Content-Type'] || 'application/json';
  }

  let token = null;
  let sessionToken = null;

  if (isElectron()) {
    try {
      token = await window.api.auth.getToken();
    } catch (_) {}
    try {
      const allConfig = await window.api.config.getAll();
      sessionToken = allConfig.sessionToken;
    } catch (_) {}
    if (!token) {
      try { token = localStorage.getItem('webmail_token'); } catch (_) {}
    }
    if (!sessionToken) {
      try { sessionToken = localStorage.getItem('webmail_session_token'); } catch (_) {}
    }
  } else {
    token = localStorage.getItem('webmail_token');
    sessionToken = localStorage.getItem('webmail_session_token');
  }

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  if (sessionToken) {
    config.headers['X-Session-Token'] = sessionToken;
    console.log('[API] Request to', config.url, '- sessionToken: present (' + sessionToken.length + ' chars)');
  }

  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      const url = error.config?.url || '';
      if (!url.includes('/auth/login') && !url.includes('/auth/2fa')) {
        console.warn('[API] 401 received for:', url);
      }
    }
    return Promise.reject(error);
  }
);

export { PRIORITY };
export default api;
