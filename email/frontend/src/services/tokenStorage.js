/**
 * Secure Token Storage Utility
 * 
 * For PWA (mobile): uses localStorage for persistence across app restarts
 * For web browser: uses sessionStorage (cleared when tab closes, XSS-resistant)
 * 
 * Automatically detects PWA mode and uses appropriate storage.
 * Provides a single source of truth for all token access across the app.
 */

const TOKEN_KEYS = [
  'webmail_token',
  'webmail_refresh_token', 
  'webmail_session_token',
  'webmail_active_account',
];

/**
 * Detect if app is running in a persistent installed context (PWA standalone
 * OR the native Capacitor shell). In these contexts tokens must survive an app
 * cold start, so they go to localStorage. A plain browser tab keeps using
 * sessionStorage (cleared on close, XSS-resistant).
 *
 * Cached on first call since this state doesn't change during a session.
 */
let _isPWACached = null;
function isPWA() {
  if (_isPWACached !== null) return _isPWACached;

  // Native Capacitor app (iOS/Android): WKWebView/Chrome wipes sessionStorage on
  // app kill, so we MUST persist to localStorage or the user is logged out every
  // time they close the app.
  try {
    if (window.Capacitor?.isNativePlatform?.()) {
      _isPWACached = true;
      return true;
    }
  } catch (_e) { /* Capacitor not present */ }

  // Check for standalone display mode (iOS Safari, Android Chrome)
  if (window.matchMedia('(display-mode: standalone)').matches) {
    _isPWACached = true;
    return true;
  }
  // Check for iOS standalone (window.navigator.standalone)
  if (window.navigator.standalone === true) {
    _isPWACached = true;
    return true;
  }
  // Check if launched from home screen (Android)
  if (window.matchMedia('(display-mode: fullscreen)').matches) {
    _isPWACached = true;
    return true;
  }
  _isPWACached = false;
  return false;
}

/**
 * Get the appropriate storage based on PWA mode
 */
function getStorage() {
  return isPWA() ? localStorage : sessionStorage;
}

/**
 * Get a token value, checking appropriate storage first, then migrating from other storage
 */
export function getToken(key) {
  const storage = getStorage();
  const otherStorage = isPWA() ? sessionStorage : localStorage;
  
  // Check primary storage first
  let val = storage.getItem(key);
  if (val) return val;
  
  // Fall back to other storage for migration
  val = otherStorage.getItem(key);
  if (val) {
    storage.setItem(key, val);
    otherStorage.removeItem(key);
    return val;
  }
  return null;
}

/**
 * Set a token value in appropriate storage (and clean up other storage)
 */
export function setToken(key, value) {
  const storage = getStorage();
  const otherStorage = isPWA() ? sessionStorage : localStorage;
  
  if (value) {
    storage.setItem(key, value);
  } else {
    storage.removeItem(key);
  }
  // Clean up other storage entry
  otherStorage.removeItem(key);
}

/**
 * Clear all auth tokens from both storages
 */
export function clearAllTokens() {
  for (const key of TOKEN_KEYS) {
    sessionStorage.removeItem(key);
    localStorage.removeItem(key);
  }
}

/**
 * Check if a token exists in either storage
 */
export function hasToken(key) {
  return !!(sessionStorage.getItem(key) || localStorage.getItem(key));
}

