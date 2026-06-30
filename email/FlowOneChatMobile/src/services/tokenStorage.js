const TOKEN_KEYS = [
  'webmail_token',
  'webmail_refresh_token',
  'webmail_session_token',
  'webmail_active_account',
]

export function getToken(key) {
  return localStorage.getItem(key) || null
}

export function setToken(key, value) {
  if (value) {
    localStorage.setItem(key, value)
  } else {
    localStorage.removeItem(key)
  }
}

export function clearAllTokens() {
  for (const key of TOKEN_KEYS) {
    localStorage.removeItem(key)
  }
}

export function hasToken(key) {
  return !!localStorage.getItem(key)
}
