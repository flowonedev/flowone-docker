/**
 * Electron API Adapter
 * 
 * Provides offline-first capabilities for the desktop app.
 * Routes requests through the main process when offline,
 * queues write operations for later sync.
 */

/**
 * Check if running in Electron
 */
export const isElectron = () => typeof window !== 'undefined' && window.api !== undefined;
export const appName = 'email';
export const nativeOAuthScheme = null;

/**
 * Get network status
 */
export const getNetworkStatus = async () => {
  if (!isElectron()) {
    return { isOnline: navigator.onLine, isVerifiedOnline: navigator.onLine };
  }
  
  try {
    const status = await window.api.db.getSetting('networkStatus');
    return status ? JSON.parse(status) : { isOnline: navigator.onLine, isVerifiedOnline: navigator.onLine };
  } catch {
    return { isOnline: navigator.onLine, isVerifiedOnline: false };
  }
};

/**
 * Queue a change for sync when back online
 * @param {string} entityType - Type of entity (email, calendar, board, client, etc.)
 * @param {number|null} entityId - ID of the entity (null for create operations)
 * @param {string} action - Action type (create, update, delete, send)
 * @param {object} payload - Data for the operation
 */
export const queueChange = async (entityType, entityId, action, payload) => {
  if (!isElectron()) {
    console.warn('[ElectronApi] queueChange called in non-Electron environment');
    return null;
  }
  
  return window.api.db.queueChange(entityType, entityId, action, payload);
};

/**
 * Get pending changes count
 */
export const getPendingCount = async () => {
  if (!isElectron()) return 0;
  return window.api.db.getPendingCount();
};

/**
 * Trigger a sync operation
 * @param {string} type - Sync type: 'all', 'email', 'calendar', 'board', etc.
 */
export const triggerSync = (type = 'all') => {
  if (!isElectron()) return;
  window.api.send('sync-request', type);
};

/**
 * Subscribe to sync events
 * @param {string} event - Event name
 * @param {function} callback - Callback function
 * @returns {function} Unsubscribe function
 */
export const onSyncEvent = (event, callback) => {
  if (!isElectron()) return () => {};
  return window.api.on(event, callback);
};

/**
 * Get auth token from Electron store
 */
export const getAuthToken = async () => {
  if (!isElectron()) {
    return localStorage.getItem('webmail_token');
  }
  return window.api.auth.getToken();
};

/**
 * Set auth tokens
 */
export const setAuthToken = async (token, email, name) => {
  if (!isElectron()) {
    localStorage.setItem('webmail_token', token);
    return true;
  }
  return window.api.auth.setToken(token, email, name);
};

/**
 * Clear auth
 */
export const clearAuth = async () => {
  if (!isElectron()) {
    localStorage.removeItem('webmail_token');
    localStorage.removeItem('webmail_refresh_token');
    localStorage.removeItem('webmail_session_token');
    return true;
  }
  return window.api.auth.clearToken();
};

/**
 * Check if logged in
 */
export const isLoggedIn = async () => {
  if (!isElectron()) {
    return !!localStorage.getItem('webmail_token');
  }
  return window.api.auth.isLoggedIn();
};

/**
 * Get config value
 */
export const getConfig = async (key) => {
  if (!isElectron()) {
    return localStorage.getItem(`config_${key}`);
  }
  return window.api.config.get(key);
};

/**
 * Set config value
 */
export const setConfig = async (key, value) => {
  if (!isElectron()) {
    localStorage.setItem(`config_${key}`, JSON.stringify(value));
    return true;
  }
  return window.api.config.set(key, value);
};

/**
 * Get all config
 */
export const getAllConfig = async () => {
  if (!isElectron()) {
    return {};
  }
  return window.api.config.getAll();
};

/**
 * Show native notification
 */
export const showNotification = async (title, body) => {
  if (!isElectron()) {
    // Fall back to browser notification
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification(title, { body });
    }
    return true;
  }
  return window.api.notification.show(title, body);
};

/**
 * Open external URL
 */
export const openExternal = async (url) => {
  if (!isElectron()) {
    window.open(url, '_blank');
    return true;
  }
  return window.api.openExternal(url);
};

/**
 * Get app version
 */
export const getAppVersion = async () => {
  if (!isElectron()) {
    return '1.0.0-web';
  }
  return window.api.getVersion();
};

// Export all functions
export default {
  isElectron,
  getNetworkStatus,
  queueChange,
  getPendingCount,
  triggerSync,
  onSyncEvent,
  getAuthToken,
  setAuthToken,
  clearAuth,
  isLoggedIn,
  getConfig,
  setConfig,
  getAllConfig,
  showNotification,
  openExternal,
  getAppVersion,
};

