/**
 * App Lock Service
 * 
 * Provides PIN code and biometric (Face ID / fingerprint) lock after inactivity.
 * When the user leaves the app (tab hidden, phone locked) for longer than the
 * configured timeout, a lock screen overlay appears. The session stays alive —
 * this is NOT a logout, just a quick-unlock gate.
 * 
 * PIN hash stored in localStorage (SHA-256).
 * Biometric uses the Web Authentication API (WebAuthn / platform authenticator).
 * 
 * Separate from idle-logout: idle-logout kills the session after very long
 * inactivity; app-lock is a quick convenience lock for shorter absences.
 */

import { ref, watch } from 'vue'

// ── Reactive state ──────────────────────────────────────────────────
const _enabled = localStorage.getItem('app_lock_enabled') === 'true'
const _hasPin = !!localStorage.getItem('app_lock_pin_hash')

// Determine lock state on page load:
// - If lock was already showing before refresh -> stay locked
// - If inactivity timeout elapsed since last activity -> lock
// - Otherwise -> don't lock (normal refresh)
const _wasLocked = sessionStorage.getItem('app_lock_is_locked') === 'true'
const _lastVisibleTs = parseInt(sessionStorage.getItem('app_lock_last_visible') || '0', 10)
const _timeoutMs = parseInt(localStorage.getItem('app_lock_timeout') || '5', 10) * 60 * 1000

let _shouldLock = false
if (_enabled && _hasPin) {
  if (_wasLocked) {
    _shouldLock = true
  } else if (_lastVisibleTs > 0) {
    _shouldLock = (Date.now() - _lastVisibleTs) >= _timeoutMs
  }
}

const isLocked = ref(_shouldLock)
const isEnabled = ref(_enabled)
const hasPinSet = ref(_hasPin)
const hasBiometric = ref(localStorage.getItem('app_lock_biometric') === 'true')
const lockTimeoutMinutes = ref(parseInt(localStorage.getItem('app_lock_timeout') || '5', 10))
const biometricAvailable = ref(false)
const pinError = ref('')

// Persist lock state so it survives refresh
watch(isLocked, (val) => {
  sessionStorage.setItem('app_lock_is_locked', String(val))
})

// Sync hasPinSet from Electron secure storage on startup
if (window.api && window.api.lock) {
  window.api.lock.hasPin().then(has => {
    hasPinSet.value = has
    if (has && isEnabled.value && _wasLocked) {
      isLocked.value = true
    }
  }).catch(() => {})
}

// ── Internal state ──────────────────────────────────────────────────
let lastVisible = _lastVisibleTs || Date.now()
let visibilityHandler = null
let resumeHandler = null
let activityHandler = null
let _initialized = false

// ── Helpers ─────────────────────────────────────────────────────────

/** SHA-256 hash a string (returns hex) */
async function sha256(text) {
  const encoder = new TextEncoder()
  const data = encoder.encode(text)
  const hashBuffer = await crypto.subtle.digest('SHA-256', data)
  const hashArray = Array.from(new Uint8Array(hashBuffer))
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('')
}

/** Check if the platform supports WebAuthn with a platform authenticator (Face ID, fingerprint) */
async function checkBiometricSupport() {
  try {
    if (!window.PublicKeyCredential) return false
    const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
    biometricAvailable.value = available
    return available
  } catch {
    biometricAvailable.value = false
    return false
  }
}

// ── Electron detection ───────────────────────────────────────────────
const isElectron = !!(window.api && window.api.lock)

// ── PIN management ──────────────────────────────────────────────────

async function setPin(pin) {
  if (!pin || pin.length < 4 || pin.length > 8) {
    throw new Error('PIN must be 4-8 digits')
  }
  if (!/^\d+$/.test(pin)) {
    throw new Error('PIN must contain only digits')
  }

  if (isElectron) {
    const result = await window.api.lock.setPin(pin)
    if (result && !result.success) {
      throw new Error(result.message || 'Failed to set PIN')
    }
  } else {
    const hash = await sha256(pin)
    localStorage.setItem('app_lock_pin_hash', hash)
  }
  hasPinSet.value = true
}

async function verifyPin(pin) {
  if (isElectron) {
    return await window.api.lock.verifyPin(pin)
  }
  const storedHash = localStorage.getItem('app_lock_pin_hash')
  if (!storedHash) return false
  const hash = await sha256(pin)
  return hash === storedHash
}

async function removePin() {
  if (isElectron) {
    await window.api.lock.removePin()
  } else {
    localStorage.removeItem('app_lock_pin_hash')
  }
  hasPinSet.value = false
  if (isEnabled.value) {
    disable()
  }
}

// ── Biometric management ────────────────────────────────────────────

// We use a self-contained WebAuthn flow with a random challenge
// The credential is tied to this origin and the user's platform authenticator.
// We store the credential ID in localStorage so we can request it later.

async function registerBiometric() {
  if (!biometricAvailable.value) {
    throw new Error('Biometric authentication is not available on this device')
  }

  const challenge = crypto.getRandomValues(new Uint8Array(32))
  const userId = new TextEncoder().encode('app-lock-user')

  try {
    const credential = await navigator.credentials.create({
      publicKey: {
        rp: { name: 'FlowOne.Pro', id: window.location.hostname },
        user: {
          id: userId,
          name: 'app-lock',
          displayName: 'App Lock',
        },
        challenge,
        pubKeyCredParams: [
          { type: 'public-key', alg: -7 },   // ES256
          { type: 'public-key', alg: -257 },  // RS256
        ],
        authenticatorSelection: {
          authenticatorAttachment: 'platform', // Only built-in (Face ID, fingerprint)
          userVerification: 'required',
          residentKey: 'preferred',
        },
        timeout: 60000,
      },
    })

    if (credential) {
      // Store credential ID as base64
      const credIdArray = new Uint8Array(credential.rawId)
      const credIdB64 = btoa(String.fromCharCode(...credIdArray))
      localStorage.setItem('app_lock_credential_id', credIdB64)
      localStorage.setItem('app_lock_biometric', 'true')
      hasBiometric.value = true
      return true
    }
    return false
  } catch (e) {
    console.warn('[AppLock] Biometric registration failed:', e)
    throw e
  }
}

async function verifyBiometric() {
  const credIdB64 = localStorage.getItem('app_lock_credential_id')
  if (!credIdB64) return false

  const credIdArray = Uint8Array.from(atob(credIdB64), c => c.charCodeAt(0))
  const challenge = crypto.getRandomValues(new Uint8Array(32))

  try {
    const assertion = await navigator.credentials.get({
      publicKey: {
        challenge,
        allowCredentials: [{
          type: 'public-key',
          id: credIdArray,
          transports: ['internal'],
        }],
        userVerification: 'required',
        timeout: 60000,
      },
    })

    return !!assertion
  } catch (e) {
    console.warn('[AppLock] Biometric verification failed:', e)
    return false
  }
}

function removeBiometric() {
  localStorage.removeItem('app_lock_credential_id')
  localStorage.removeItem('app_lock_biometric')
  hasBiometric.value = false
}

// ── Lock / Unlock ───────────────────────────────────────────────────

function lock() {
  if (!isEnabled.value || !hasPinSet.value) return
  isLocked.value = true
  sessionStorage.setItem('app_lock_is_locked', 'true')
  pinError.value = ''
}

async function unlockWithPin(pin) {
  pinError.value = ''
  const valid = await verifyPin(pin)
  if (valid) {
    isLocked.value = false
    lastVisible = Date.now()
    sessionStorage.setItem('app_lock_last_visible', String(lastVisible))
    return true
  }
  pinError.value = 'Incorrect PIN'
  return false
}

async function unlockWithBiometric() {
  pinError.value = ''
  const valid = await verifyBiometric()
  if (valid) {
    isLocked.value = false
    lastVisible = Date.now()
    sessionStorage.setItem('app_lock_last_visible', String(lastVisible))
    return true
  }
  pinError.value = 'Biometric verification failed'
  return false
}

// ── Visibility monitoring ───────────────────────────────────────────

function resetActivityTimer() {
  if (isLocked.value) return
  lastVisible = Date.now()
  sessionStorage.setItem('app_lock_last_visible', String(lastVisible))
}

function onVisibilityChange() {
  if (!isEnabled.value || !hasPinSet.value) return

  if (document.hidden) {
    lastVisible = Date.now()
    sessionStorage.setItem('app_lock_last_visible', String(lastVisible))
  } else {
    const elapsed = Date.now() - lastVisible
    const timeoutMs = lockTimeoutMinutes.value * 60 * 1000
    if (elapsed >= timeoutMs) {
      lock()
    } else {
      resetActivityTimer()
    }
  }
}

// Also handle mobile resume (iOS/Android PWA coming back from background)
function onResume() {
  if (!isEnabled.value || !hasPinSet.value) return
  const elapsed = Date.now() - lastVisible
  const timeoutMs = lockTimeoutMinutes.value * 60 * 1000
  if (elapsed >= timeoutMs) {
    lock()
  } else {
    resetActivityTimer()
  }
}

// ── Enable / Disable ────────────────────────────────────────────────

function enable() {
  if (!hasPinSet.value) {
    throw new Error('Set a PIN before enabling app lock')
  }
  isEnabled.value = true
  localStorage.setItem('app_lock_enabled', 'true')
  startMonitoring()
}

function disable() {
  isEnabled.value = false
  isLocked.value = false
  localStorage.setItem('app_lock_enabled', 'false')
  stopMonitoring()
}

function setLockTimeout(minutes) {
  lockTimeoutMinutes.value = Math.max(1, Math.min(60, minutes))
  localStorage.setItem('app_lock_timeout', String(lockTimeoutMinutes.value))
}

// ── Lifecycle ───────────────────────────────────────────────────────

function startMonitoring() {
  if (_initialized) return
  _initialized = true

  visibilityHandler = onVisibilityChange
  resumeHandler = onResume

  // Throttled activity handler -- resets inactivity timer on user interaction
  let lastActivityReset = 0
  activityHandler = () => {
    const now = Date.now()
    if (now - lastActivityReset > 10_000) {
      lastActivityReset = now
      resetActivityTimer()
    }
  }

  document.addEventListener('visibilitychange', visibilityHandler)
  window.addEventListener('focus', resumeHandler)
  window.addEventListener('pageshow', resumeHandler)

  document.addEventListener('pointerdown', activityHandler, { passive: true })
  document.addEventListener('keydown', activityHandler, { passive: true })

  if (!isLocked.value) {
    lastVisible = Date.now()
    sessionStorage.setItem('app_lock_last_visible', String(lastVisible))
  }

  checkBiometricSupport()
}

function stopMonitoring() {
  if (!_initialized) return
  _initialized = false

  if (visibilityHandler) {
    document.removeEventListener('visibilitychange', visibilityHandler)
    visibilityHandler = null
  }
  if (resumeHandler) {
    window.removeEventListener('focus', resumeHandler)
    window.removeEventListener('pageshow', resumeHandler)
    resumeHandler = null
  }
  if (activityHandler) {
    document.removeEventListener('pointerdown', activityHandler)
    document.removeEventListener('keydown', activityHandler)
    activityHandler = null
  }
}

/**
 * Initialize app lock — call once from App.vue on mount
 */
function init() {
  checkBiometricSupport()
  if (isEnabled.value && hasPinSet.value) {
    startMonitoring()
  }
}

/**
 * Clean up — call from App.vue on unmount
 */
function destroy() {
  stopMonitoring()
}

// ── Exports ─────────────────────────────────────────────────────────

export {
  // Reactive state
  isLocked,
  isEnabled,
  hasPinSet,
  hasBiometric,
  biometricAvailable,
  lockTimeoutMinutes,
  pinError,

  // PIN
  setPin,
  verifyPin,
  removePin,

  // Biometric
  registerBiometric,
  verifyBiometric,
  removeBiometric,
  checkBiometricSupport,

  // Lock / Unlock
  lock,
  unlockWithPin,
  unlockWithBiometric,

  // Settings
  enable,
  disable,
  setLockTimeout,

  // Lifecycle
  init,
  destroy,
}

