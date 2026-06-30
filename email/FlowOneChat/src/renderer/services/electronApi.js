export const isElectron = () => typeof window !== 'undefined' && window.api !== undefined;
export const appName = 'chat';

export const getNetworkStatus = async () => {
  if (!isElectron()) {
    return { isOnline: navigator.onLine, isVerifiedOnline: navigator.onLine };
  }
  return { isOnline: navigator.onLine, isVerifiedOnline: navigator.onLine };
};

export const getAuthToken = async () => {
  if (!isElectron()) return localStorage.getItem('webmail_token');
  return window.api.auth.getToken();
};

export const setAuthToken = async (token, email, name) => {
  if (!isElectron()) {
    localStorage.setItem('webmail_token', token);
    return true;
  }
  return window.api.auth.setToken(token, email, name);
};

export const clearAuth = async () => {
  if (!isElectron()) {
    localStorage.removeItem('webmail_token');
    localStorage.removeItem('webmail_refresh_token');
    localStorage.removeItem('webmail_session_token');
    return true;
  }
  return window.api.auth.clearToken();
};

export const isLoggedIn = async () => {
  if (!isElectron()) return !!localStorage.getItem('webmail_token');
  return window.api.auth.isLoggedIn();
};

export const getConfig = async (key) => {
  if (!isElectron()) return localStorage.getItem(`config_${key}`);
  return window.api.config.get(key);
};

export const setConfig = async (key, value) => {
  if (!isElectron()) {
    localStorage.setItem(`config_${key}`, JSON.stringify(value));
    return true;
  }
  return window.api.config.set(key, value);
};

export const getAllConfig = async () => {
  if (!isElectron()) return {};
  return window.api.config.getAll();
};

export const showNotification = async (title, body) => {
  if (!isElectron()) {
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification(title, { body });
    }
    return true;
  }
  return window.api.notification.show(title, body);
};

export const openExternal = async (url) => {
  if (!isElectron()) { window.open(url, '_blank'); return true; }
  return window.api.openExternal(url);
};

export const getAppVersion = async () => {
  if (!isElectron()) return '1.0.0-web';
  return window.api.getVersion();
};

export const onSyncEvent = (event, callback) => {
  if (!isElectron()) return () => {};
  return window.api.on(event, callback);
};

export const queueChange = async () => null;
export const getPendingCount = async () => 0;
export const triggerSync = () => {};

export default {
  isElectron, getNetworkStatus, getAuthToken, setAuthToken,
  clearAuth, isLoggedIn, getConfig, setConfig, getAllConfig,
  showNotification, openExternal, getAppVersion, onSyncEvent,
  queueChange, getPendingCount, triggerSync,
};
