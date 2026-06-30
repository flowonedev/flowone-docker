import { isElectron } from '@/services/electronApi'

const TOKEN_KEYS = [
  'webmail_token',
  'webmail_refresh_token',
  'webmail_session_token',
  'webmail_active_account',
];

export function getToken(key) {
  return localStorage.getItem(key) || null
}

export function setToken(key, value) {
  if (value) {
    localStorage.setItem(key, value)
  } else {
    localStorage.removeItem(key)
  }
  if (isElectron() && window.api?.auth) {
    if (key === 'webmail_token' && value) {
      window.api.auth.setToken(value).catch(() => {})
    }
  }
}

export function clearAllTokens() {
  for (const key of TOKEN_KEYS) {
    localStorage.removeItem(key)
  }
  if (isElectron() && window.api?.auth) {
    window.api.auth.clearToken().catch(() => {})
  }
}

export function hasToken(key) {
  return !!localStorage.getItem(key)
}
