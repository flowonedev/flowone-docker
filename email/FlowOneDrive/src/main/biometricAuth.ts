/**
 * Biometric / PIN Lock Module for FlowOneDrive
 * 
 * Provides:
 * - macOS Touch ID authentication (via systemPreferences.promptTouchID)
 * - PIN-based lock screen (cross-platform, PIN hash stored in safeStorage)
 * - Auto-lock on idle, minimize, and system sleep/lock
 * - IPC handlers for renderer communication
 */

import { systemPreferences, ipcMain, BrowserWindow, powerMonitor } from 'electron'
import crypto from 'crypto'
import { execFile, execSync } from 'child_process'
import { setSecure, getSecure, wipeAllSecureData } from './secureStorage'
import { ConfigStore } from './config'

let mainWindowRef: BrowserWindow | null = null
let idleCheckTimer: ReturnType<typeof setInterval> | null = null
let isLocked = false

// ──────────────────────────────────────────────
// PIN brute force protection
// ──────────────────────────────────────────────

let pinAttempts = 0
const MAX_PIN_ATTEMPTS_SOFT = 5   // exponential backoff starts here
const MAX_PIN_ATTEMPTS_HARD = 10  // wipe all data (stolen device assumed)
let pinLockedUntil = 0

function getPinLockoutMs(): number {
  if (pinAttempts < 3) return 0
  if (pinAttempts < MAX_PIN_ATTEMPTS_SOFT) return 30_000      // 30 seconds
  if (pinAttempts < MAX_PIN_ATTEMPTS_HARD) return 5 * 60_000  // 5 minutes
  return 0 // will wipe instead
}

function isPinLocked(): { locked: boolean; retryAfterMs: number } {
  if (pinLockedUntil <= 0) return { locked: false, retryAfterMs: 0 }
  const remaining = pinLockedUntil - Date.now()
  if (remaining <= 0) {
    pinLockedUntil = 0
    return { locked: false, retryAfterMs: 0 }
  }
  return { locked: true, retryAfterMs: remaining }
}

// ──────────────────────────────────────────────
// Biometric detection
// ──────────────────────────────────────────────

/**
 * Check if biometric authentication (Touch ID / Windows Hello) is available.
 *
 * Returns the cached value synchronously. On macOS the system call is cheap,
 * so we just call through. On Windows the WinRT availability check requires
 * spawning PowerShell + loading Windows.Security assemblies which can take
 * 1-3 s of cold-start time and BLOCKS THE MAIN THREAD when called from
 * `lock-get-settings` on every Settings-tab open. We now compute it once
 * asynchronously at startup (see {@link primeBiometricAvailability}) and
 * return the cached result here.
 */
let biometricAvailableCache: boolean | null = null
let biometricDetectInFlight: Promise<boolean> | null = null

export function isBiometricAvailable(): boolean {
  if (process.platform === 'darwin') {
    return systemPreferences.canPromptTouchID()
  }
  if (process.platform === 'win32') {
    if (biometricAvailableCache !== null) {
      return biometricAvailableCache
    }
    // Not yet detected — kick off detection but don't wait. Renderer can
    // re-query later (or at next launch) once the cache is populated. Default
    // to false so we don't claim biometric is available when it might not be.
    void primeBiometricAvailability()
    return false
  }
  return false
}

/**
 * Detect Windows Hello availability in the background. Safe to call multiple
 * times — we coalesce concurrent calls and never spawn PowerShell more than
 * once per process. The first call is fired from main/index.ts during
 * app.whenReady() so the result is usually ready before the user opens
 * Settings.
 */
export function primeBiometricAvailability(): Promise<boolean> {
  if (biometricAvailableCache !== null) {
    return Promise.resolve(biometricAvailableCache)
  }
  if (biometricDetectInFlight) {
    return biometricDetectInFlight
  }
  if (process.platform === 'darwin') {
    biometricAvailableCache = systemPreferences.canPromptTouchID()
    return Promise.resolve(biometricAvailableCache)
  }
  if (process.platform !== 'win32') {
    biometricAvailableCache = false
    return Promise.resolve(false)
  }

  biometricDetectInFlight = new Promise<boolean>((resolve) => {
    // execFile (non-blocking) instead of execSync. 5s is enough for cold-start
    // PowerShell + WinRT assembly load; if it overruns we just return false.
    const args = [
      '-NoProfile',
      '-Command',
      'Add-Type -AssemblyName Windows.Security; [Windows.Security.Credentials.UI.UserConsentVerifier, Windows.Security.Credentials.UI, ContentType=WindowsRuntime] | Out-Null; $task = [Windows.Security.Credentials.UI.UserConsentVerifier]::CheckAvailabilityAsync(); while (-not $task.IsCompleted) { Start-Sleep -Milliseconds 50 }; $task.GetResults()',
    ]
    execFile('powershell', args, { encoding: 'utf8', timeout: 5000 }, (err, stdout) => {
      if (err) {
        biometricAvailableCache = false
      } else {
        biometricAvailableCache = (stdout || '').trim() === 'Available'
      }
      biometricDetectInFlight = null
      resolve(biometricAvailableCache)
    })
  })
  return biometricDetectInFlight
}

/**
 * Prompt for biometric authentication
 */
export async function authenticateBiometric(reason: string = 'Unlock FlowOne Drive'): Promise<boolean> {
  if (process.platform === 'darwin') {
    try {
      await systemPreferences.promptTouchID(reason)
      return true
    } catch {
      return false
    }
  }
  if (process.platform === 'win32') {
    try {
      const result = execSync(
        `powershell -NoProfile -Command "Add-Type -AssemblyName Windows.Security; [Windows.Security.Credentials.UI.UserConsentVerifier, Windows.Security.Credentials.UI, ContentType=WindowsRuntime] | Out-Null; $task = [Windows.Security.Credentials.UI.UserConsentVerifier]::RequestVerificationAsync('${reason.replace(/'/g, "''")}'); while (-not $task.IsCompleted) { Start-Sleep -Milliseconds 50 }; $task.GetResults()"`,
        { encoding: 'utf8', timeout: 30000 }
      ).trim()
      return result === 'Verified'
    } catch {
      return false
    }
  }
  return false
}

// ──────────────────────────────────────────────
// PIN management (scrypt — memory-hard KDF)
// ──────────────────────────────────────────────
// Uses Node.js built-in crypto.scryptSync:
//   N=32768 (CPU/memory cost), r=8 (block size), p=1 (parallelization)
//   This makes brute-force ~1000x harder than PBKDF2 by requiring ~32 MB RAM per attempt.
// Format: "scrypt:derivedKey:salt"
// Backward compat: "derivedKey:salt" (PBKDF2) and plain hex (legacy SHA-256)

function getOrCreatePinSalt(): string {
  let salt = getSecure('pinSalt')
  if (!salt) {
    salt = crypto.randomBytes(32).toString('hex')
    setSecure('pinSalt', salt)
  }
  return salt
}

function hashPin(pin: string): string {
  const salt = getOrCreatePinSalt()
  const derived = crypto.scryptSync(pin, salt, 32, { N: 32768, r: 8, p: 1 }).toString('hex')
  return `scrypt:${derived}:${salt}`
}

function verifyPinHash(pin: string, storedHash: string): boolean {
  // Scrypt format: "scrypt:derivedKey:salt"
  if (storedHash.startsWith('scrypt:')) {
    const parts = storedHash.substring(7).split(':')
    if (parts.length === 2) {
      const [storedKey, salt] = parts
      const derived = crypto.scryptSync(pin, salt, 32, { N: 32768, r: 8, p: 1 }).toString('hex')
      return crypto.timingSafeEqual(Buffer.from(derived, 'hex'), Buffer.from(storedKey, 'hex'))
    }
    return false
  }

  const parts = storedHash.split(':')
  if (parts.length === 2) {
    // Legacy salted PBKDF2 format: "derivedKey:salt" (auto-migrate on next setPin)
    const [storedKey, salt] = parts
    const derived = crypto.pbkdf2Sync(pin, salt, 100_000, 32, 'sha256').toString('hex')
    return crypto.timingSafeEqual(Buffer.from(derived, 'hex'), Buffer.from(storedKey, 'hex'))
  }

  // Legacy unsalted SHA-256 format (auto-migrate on next setPin)
  const legacyHash = crypto.createHash('sha256').update(pin).digest('hex')
  return crypto.timingSafeEqual(Buffer.from(legacyHash, 'hex'), Buffer.from(storedHash, 'hex'))
}

export function hasPin(): boolean {
  return !!getSecure('lockPinHash')
}

export function setPin(pin: string): void {
  setSecure('lockPinHash', hashPin(pin))
}

export function verifyPin(pin: string): boolean {
  const stored = getSecure('lockPinHash')
  if (!stored) return false
  return verifyPinHash(pin, stored)
}

export function removePin(): void {
  setSecure('lockPinHash', null)
  setSecure('pinSalt', null)
}

// ──────────────────────────────────────────────
// Lock state management
// ──────────────────────────────────────────────

export function getIsLocked(): boolean {
  return isLocked
}

export function setLocked(locked: boolean): void {
  isLocked = locked
  if (mainWindowRef && !mainWindowRef.isDestroyed()) {
    mainWindowRef.webContents.send(locked ? 'app-locked' : 'app-unlocked')
  }
}

function shouldLock(): boolean {
  const config = ConfigStore.getInstance()
  const { getAuthToken } = require('./secureStorage')
  const authToken = getAuthToken()
  const lockEnabled = config.get('lockEnabled')
  return !!authToken && lockEnabled && hasPin()
}

function triggerLock(): void {
  if (!isLocked && shouldLock()) {
    console.log('[BiometricAuth] Locking app')
    setLocked(true)
  }
}

// ──────────────────────────────────────────────
// Idle timer
// ──────────────────────────────────────────────

function startIdleCheck(): void {
  stopIdleCheck()

  idleCheckTimer = setInterval(() => {
    if (isLocked || !shouldLock()) return

    const config = ConfigStore.getInstance()
    const lockTimeout = config.get('lockTimeout')
    if (lockTimeout <= 0) return

    const idleSeconds = powerMonitor.getSystemIdleTime()
    const timeoutSeconds = lockTimeout * 60

    if (idleSeconds >= timeoutSeconds) {
      triggerLock()
    }
  }, 10_000)
}

function stopIdleCheck(): void {
  if (idleCheckTimer) {
    clearInterval(idleCheckTimer)
    idleCheckTimer = null
  }
}

// ──────────────────────────────────────────────
// System event listeners
// ──────────────────────────────────────────────

function setupSystemListeners(): void {
  powerMonitor.on('lock-screen', () => {
    triggerLock()
  })

  powerMonitor.on('suspend', () => {
    triggerLock()
  })
}

// ──────────────────────────────────────────────
// Public initialization
// ──────────────────────────────────────────────

export function initBiometricAuth(window: BrowserWindow): void {
  mainWindowRef = window

  window.on('minimize', () => {
    const config = ConfigStore.getInstance()
    if (config.get('lockOnMinimize')) {
      triggerLock()
    }
  })

  startIdleCheck()
  setupSystemListeners()

  console.log('[BiometricAuth] Initialized. Biometric available:', isBiometricAvailable())
}

export function cleanupBiometricAuth(): void {
  stopIdleCheck()
}

// ──────────────────────────────────────────────
// IPC handler registration
// ──────────────────────────────────────────────

export function registerBiometricIpcHandlers(): void {
  ipcMain.handle('lock-biometric-available', () => {
    return isBiometricAvailable()
  })

  ipcMain.handle('lock-biometric-auth', async () => {
    const result = await authenticateBiometric()
    if (result) {
      setLocked(false)
    }
    return result
  })

  ipcMain.handle('lock-has-pin', () => {
    return hasPin()
  })

  ipcMain.handle('lock-set-pin', (_event, pin: string) => {
    if (!pin || !/^\d{4,8}$/.test(pin)) {
      return { success: false, message: 'PIN must be 4-8 digits' }
    }
    try {
      setPin(pin)
      return { success: true }
    } catch (err: any) {
      console.error('[BiometricAuth] Failed to set PIN:', err.message)
      return { success: false, message: 'Failed to save PIN: ' + err.message }
    }
  })

  ipcMain.handle('lock-verify-pin', (_event, pin: string) => {
    // Check brute force lockout
    const lockStatus = isPinLocked()
    if (lockStatus.locked) {
      return { success: false, locked: true, retryAfterMs: lockStatus.retryAfterMs }
    }

    const valid = verifyPin(pin)
    if (valid) {
      pinAttempts = 0
      pinLockedUntil = 0
      setLocked(false)
      return { success: true }
    }

    // Failed attempt
    pinAttempts++

    // Hard limit: wipe all data (stolen device assumed)
    if (pinAttempts >= MAX_PIN_ATTEMPTS_HARD) {
      console.log('[BiometricAuth] Max PIN attempts reached - wiping all secure data')
      wipeAllSecureData()
      setLocked(false) // unlock because there's nothing left to protect
      if (mainWindowRef && !mainWindowRef.isDestroyed()) {
        mainWindowRef.webContents.send('force-logout', { reason: 'pin_wipe' })
      }
      return { success: false, wiped: true }
    }

    // Soft lockout with exponential backoff
    const lockoutMs = getPinLockoutMs()
    if (lockoutMs > 0) {
      pinLockedUntil = Date.now() + lockoutMs
    }

    return {
      success: false,
      attemptsRemaining: MAX_PIN_ATTEMPTS_HARD - pinAttempts,
      retryAfterMs: lockoutMs,
    }
  })

  ipcMain.handle('lock-remove-pin', () => {
    removePin()
    const config = ConfigStore.getInstance()
    config.set('lockEnabled', false)
    setLocked(false)
    return true
  })

  ipcMain.handle('lock-get-settings', () => {
    const config = ConfigStore.getInstance()
    return {
      lockEnabled: config.get('lockEnabled'),
      lockTimeout: config.get('lockTimeout'),
      lockOnMinimize: config.get('lockOnMinimize'),
      hasPin: hasPin(),
      biometricAvailable: isBiometricAvailable(),
      isLocked: isLocked,
    }
  })

  ipcMain.handle('lock-set-settings', (_event, settings: {
    lockEnabled?: boolean
    lockTimeout?: number
    lockOnMinimize?: boolean
  }) => {
    const config = ConfigStore.getInstance()
    if (settings.lockEnabled !== undefined) {
      if (settings.lockEnabled && !hasPin()) {
        return { success: false, message: 'Set a PIN before enabling lock' }
      }
      config.set('lockEnabled', settings.lockEnabled)
    }
    if (settings.lockTimeout !== undefined) {
      config.set('lockTimeout', settings.lockTimeout)
    }
    if (settings.lockOnMinimize !== undefined) {
      config.set('lockOnMinimize', settings.lockOnMinimize)
    }
    return { success: true }
  })

  ipcMain.handle('lock-now', () => {
    // For manual lock, only require PIN to be set (user explicitly wants to lock)
    if (!hasPin()) {
      console.log('[BiometricAuth] lock-now: No PIN set, cannot lock')
      return false
    }
    console.log('[BiometricAuth] lock-now: Locking app manually')
    setLocked(true)
    return true
  })

  ipcMain.handle('lock-is-locked', () => {
    return isLocked
  })
}

