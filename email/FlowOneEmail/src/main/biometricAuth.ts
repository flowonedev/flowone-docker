/**
 * Biometric / PIN Lock Module for FlowOneEmail
 * 
 * Provides:
 * - macOS Touch ID authentication (via systemPreferences.promptTouchID)
 * - PIN-based lock screen (cross-platform, PIN hash stored in safeStorage)
 * - Auto-lock on idle, minimize, and system sleep/lock
 * - IPC handlers for renderer communication
 */

import { systemPreferences, ipcMain, BrowserWindow, powerMonitor } from 'electron'
import crypto from 'crypto'
import { setSecure, getSecure, getAuthToken } from './secureStorage'
import { configStore } from './config'

let mainWindowRef: BrowserWindow | null = null
let idleCheckTimer: ReturnType<typeof setInterval> | null = null
let isLocked = false

// ──────────────────────────────────────────────
// Biometric detection
// ──────────────────────────────────────────────

/**
 * Check if biometric authentication (Touch ID / Windows Hello) is available
 */
export function isBiometricAvailable(): boolean {
  if (process.platform === 'darwin') {
    return systemPreferences.canPromptTouchID()
  }
  // Windows Hello is not natively supported in Electron
  // Users on Windows use PIN lock instead
  return false
}

/**
 * Prompt for biometric authentication
 * Returns true if authenticated, false if failed or cancelled
 */
export async function authenticateBiometric(reason: string = 'Unlock FlowOne Email'): Promise<boolean> {
  if (process.platform === 'darwin') {
    try {
      await systemPreferences.promptTouchID(reason)
      return true
    } catch {
      return false
    }
  }
  return false
}

// ──────────────────────────────────────────────
// PIN management
// ──────────────────────────────────────────────

const PIN_ITERATIONS = 100_000

function hashPinWithSalt(pin: string, salt: string): string {
  return crypto.pbkdf2Sync(pin, salt, PIN_ITERATIONS, 32, 'sha256').toString('hex')
}

export function hasPin(): boolean {
  return !!getSecure('lockPinHash')
}

export function setPin(pin: string): void {
  const salt = crypto.randomBytes(16).toString('hex')
  const hash = hashPinWithSalt(pin, salt)
  setSecure('lockPinSalt', salt)
  setSecure('lockPinHash', hash)
}

export function verifyPin(pin: string): boolean {
  const stored = getSecure('lockPinHash')
  if (!stored) return false
  const salt = getSecure('lockPinSalt')
  if (!salt) {
    // Legacy unsalted hash -- verify with old method, then migrate
    const legacyHash = crypto.createHash('sha256').update(pin).digest('hex')
    if (stored === legacyHash) {
      setPin(pin)
      return true
    }
    return false
  }
  return stored === hashPinWithSalt(pin, salt)
}

export function removePin(): void {
  setSecure('lockPinHash', null)
  setSecure('lockPinSalt', null)
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
  // Only lock if user is logged in, lock is enabled, and a PIN is set
  const authToken = getAuthToken()
  const lockEnabled = configStore.get('lockEnabled')
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

    const lockTimeout = configStore.get('lockTimeout')
    if (lockTimeout <= 0) return  // 0 = manual lock only

    const idleSeconds = powerMonitor.getSystemIdleTime()
    const timeoutSeconds = lockTimeout * 60

    if (idleSeconds >= timeoutSeconds) {
      triggerLock()
    }
  }, 10_000) // Check every 10 seconds
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
  // Lock on system lock screen
  powerMonitor.on('lock-screen', () => {
    triggerLock()
  })

  // Lock on system suspend/sleep
  powerMonitor.on('suspend', () => {
    triggerLock()
  })
}

// ──────────────────────────────────────────────
// Public initialization
// ──────────────────────────────────────────────

/**
 * Initialize the biometric/lock module.
 * Call once after the main window is created and app is ready.
 */
export function initBiometricAuth(window: BrowserWindow): void {
  mainWindowRef = window

  // Listen for window minimize if lockOnMinimize is enabled
  window.on('minimize', () => {
    if (configStore.get('lockOnMinimize')) {
      triggerLock()
    }
  })

  // Start idle monitoring
  startIdleCheck()

  // System-level events
  setupSystemListeners()

  console.log('[BiometricAuth] Initialized. Biometric available:', isBiometricAvailable())
}

/**
 * Clean up timers and listeners.
 */
export function cleanupBiometricAuth(): void {
  stopIdleCheck()
}

// ──────────────────────────────────────────────
// IPC handler registration
// ──────────────────────────────────────────────

/**
 * Register all IPC handlers for the lock screen.
 * Call once during app setup (before window creation is fine).
 */
export function registerBiometricIpcHandlers(): void {
  // Check if biometric (Touch ID) is available
  ipcMain.handle('lock-biometric-available', () => {
    return isBiometricAvailable()
  })

  // Prompt biometric authentication
  ipcMain.handle('lock-biometric-auth', async () => {
    const result = await authenticateBiometric()
    if (result) {
      setLocked(false)
    }
    return result
  })

  // Check if a PIN has been set
  ipcMain.handle('lock-has-pin', () => {
    return hasPin()
  })

  // Set a new PIN
  ipcMain.handle('lock-set-pin', (_event, pin: string) => {
    if (!pin || pin.length < 4 || pin.length > 8) {
      return { success: false, message: 'PIN must be 4-8 digits' }
    }
    setPin(pin)
    return { success: true }
  })

  // Verify PIN and unlock
  ipcMain.handle('lock-verify-pin', (_event, pin: string) => {
    const valid = verifyPin(pin)
    if (valid) {
      setLocked(false)
    }
    return valid
  })

  // Remove PIN (disables lock)
  ipcMain.handle('lock-remove-pin', () => {
    removePin()
    configStore.set('lockEnabled', false)
    setLocked(false)
    return true
  })

  // Get lock settings
  ipcMain.handle('lock-get-settings', () => {
    return {
      lockEnabled: configStore.get('lockEnabled'),
      lockTimeout: configStore.get('lockTimeout'),
      lockOnMinimize: configStore.get('lockOnMinimize'),
      hasPin: hasPin(),
      biometricAvailable: isBiometricAvailable(),
      isLocked: isLocked,
    }
  })

  // Update lock settings
  ipcMain.handle('lock-set-settings', (_event, settings: {
    lockEnabled?: boolean
    lockTimeout?: number
    lockOnMinimize?: boolean
  }) => {
    if (settings.lockEnabled !== undefined) {
      // Don't allow enabling lock without a PIN
      if (settings.lockEnabled && !hasPin()) {
        return { success: false, message: 'Set a PIN before enabling lock' }
      }
      configStore.set('lockEnabled', settings.lockEnabled)
    }
    if (settings.lockTimeout !== undefined) {
      configStore.set('lockTimeout', settings.lockTimeout)
    }
    if (settings.lockOnMinimize !== undefined) {
      configStore.set('lockOnMinimize', settings.lockOnMinimize)
    }
    return { success: true }
  })

  // Manual lock trigger from renderer
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

  // Get lock state
  ipcMain.handle('lock-is-locked', () => {
    return isLocked
  })
}

