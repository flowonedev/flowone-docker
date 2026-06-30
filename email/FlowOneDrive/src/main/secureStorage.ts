/**
 * Secure Storage Module for FlowOneDrive
 * 
 * Uses Electron's safeStorage API to encrypt sensitive data (tokens, credentials)
 * at the OS level. On Windows this uses DPAPI, on macOS it uses Keychain,
 * and on Linux it uses the Secret Service API or libsecret.
 * 
 * Non-sensitive config (UI preferences, sync settings) stays in electron-store (plaintext).
 * Sensitive data (auth tokens, session tokens, NAS credentials) goes through safeStorage.
 */

import { safeStorage } from 'electron'
import Store from 'electron-store'
import crypto from 'crypto'
import { execSync } from 'child_process'
import os from 'os'

interface SecureData {
  authToken: string | null
  sessionToken: string | null
  deviceToken: string | null
  deviceId: string | null
  nasUsername: string | null
  nasPassword: string | null
  lockPinHash: string | null  // PBKDF2 hash of the lock PIN (format: "derivedKey:salt")
  pinSalt: string | null      // Random salt for PIN hashing
}

// Encrypted store: values are stored as base64-encoded encrypted buffers
const encryptedStore = new Store<Record<string, string>>({
  name: 'secure-data',
  defaults: {},
})

// ─── Software encryption fallback (when OS-level safeStorage is unavailable) ───

let cachedMachineKey: Buffer | null = null

/**
 * Get the OS machine identifier (duplicated from deviceId.ts to avoid circular deps)
 */
function getOsMachineId(): string {
  try {
    switch (process.platform) {
      case 'win32': {
        const result = execSync(
          'reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid',
          { encoding: 'utf8', timeout: 5000 }
        )
        const match = result.match(/MachineGuid\s+REG_SZ\s+(.+)/)
        if (match) return match[1].trim()
        break
      }
      case 'darwin': {
        const result = execSync(
          "ioreg -rd1 -c IOPlatformExpertDevice | awk '/IOPlatformUUID/'",
          { encoding: 'utf8', timeout: 5000 }
        )
        const match = result.match(/"IOPlatformUUID"\s*=\s*"(.+)"/)
        if (match) return match[1].trim()
        break
      }
      case 'linux': {
        const fs = require('fs')
        if (fs.existsSync('/etc/machine-id')) {
          return fs.readFileSync('/etc/machine-id', 'utf8').trim()
        }
        if (fs.existsSync('/var/lib/dbus/machine-id')) {
          return fs.readFileSync('/var/lib/dbus/machine-id', 'utf8').trim()
        }
        break
      }
    }
  } catch (err) {
    console.warn('[SecureStorage] Failed to get machine ID:', err)
  }
  // Fallback: hostname + username + platform (not ideal but better than nothing)
  return `${os.hostname()}:${os.userInfo().username}:${process.platform}`
}

/**
 * Derive a 256-bit AES key from the machine ID using PBKDF2
 * Uses a per-install random salt (generated once and persisted) for stronger key derivation.
 * This key is deterministic per-machine+salt so data can be decrypted after restart.
 */
function getMachineKey(): Buffer {
  if (cachedMachineKey) return cachedMachineKey
  const machineId = getOsMachineId()
  
  // Generate a random salt per install and persist it
  // This prevents pre-computation attacks against known machine IDs
  let salt = encryptedStore.get('_pbkdf2_salt' as any) as string | undefined
  if (!salt) {
    salt = crypto.randomBytes(32).toString('hex')
    encryptedStore.set('_pbkdf2_salt' as any, salt)
  }
  
  cachedMachineKey = crypto.pbkdf2Sync(machineId, salt, 100_000, 32, 'sha256')
  return cachedMachineKey
}

/**
 * Encrypt a string with AES-256-GCM using the machine-derived key
 * Returns: base64(iv + authTag + ciphertext)
 */
function softwareEncrypt(value: string): string {
  const key = getMachineKey()
  const iv = crypto.randomBytes(12) // 96-bit IV for GCM
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv)
  const encrypted = Buffer.concat([cipher.update(value, 'utf8'), cipher.final()])
  const authTag = cipher.getAuthTag() // 16 bytes
  // Pack: iv (12) + authTag (16) + ciphertext
  const packed = Buffer.concat([iv, authTag, encrypted])
  return packed.toString('base64')
}

/**
 * Decrypt a string encrypted with softwareEncrypt
 */
function softwareDecrypt(encoded: string): string {
  const key = getMachineKey()
  const packed = Buffer.from(encoded, 'base64')
  const iv = packed.subarray(0, 12)
  const authTag = packed.subarray(12, 28)
  const ciphertext = packed.subarray(28)
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, iv)
  decipher.setAuthTag(authTag)
  const decrypted = Buffer.concat([decipher.update(ciphertext), decipher.final()])
  return decrypted.toString('utf8')
}

/**
 * Check if OS-level encryption is available
 */
export function isEncryptionAvailable(): boolean {
  return safeStorage.isEncryptionAvailable()
}

/**
 * Encrypt and store a value
 */
export function setSecure(key: keyof SecureData, value: string | null): void {
  if (!value) {
    encryptedStore.delete(key)
    return
  }

  if (safeStorage.isEncryptionAvailable()) {
    const encrypted = safeStorage.encryptString(value)
    encryptedStore.set(key, encrypted.toString('base64'))
  } else {
    // Fallback: AES-256-GCM encryption with machine-derived key
    console.warn('[SecureStorage] OS encryption not available, using software AES-256-GCM fallback')
    const encrypted = softwareEncrypt(value)
    encryptedStore.set(key, `sw:${encrypted}`)
  }
}

/**
 * Retrieve and decrypt a value
 */
export function getSecure(key: keyof SecureData): string | null {
  const stored = encryptedStore.get(key)
  if (!stored) return null

  try {
    // Legacy plaintext fallback — read it, then re-encrypt on next set
    if (stored.startsWith('plain:')) {
      const plainValue = stored.substring(6)
      // Opportunistically re-encrypt with current best method
      setSecure(key, plainValue)
      return plainValue
    }

    // Software-encrypted fallback (AES-256-GCM with machine-derived key)
    if (stored.startsWith('sw:')) {
      return softwareDecrypt(stored.substring(3))
    }

    // OS-level safeStorage encrypted data
    if (safeStorage.isEncryptionAvailable()) {
      const buffer = Buffer.from(stored, 'base64')
      return safeStorage.decryptString(buffer)
    }

    return null
  } catch (err) {
    console.error(`[SecureStorage] Failed to decrypt ${key}:`, err)
    encryptedStore.delete(key)
    return null
  }
}

/**
 * Delete a secure value
 */
export function deleteSecure(key: keyof SecureData): void {
  encryptedStore.delete(key)
}

/**
 * Wipe all secure data (used for remote wipe and logout)
 */
export function wipeAllSecureData(): void {
  encryptedStore.clear()
}

/**
 * Get auth token from secure storage
 */
export function getAuthToken(): string | null {
  return getSecure('authToken')
}

/**
 * Set auth token in secure storage
 */
export function setAuthToken(token: string | null): void {
  setSecure('authToken', token)
}

/**
 * Get session token from secure storage
 */
export function getSessionToken(): string | null {
  return getSecure('sessionToken')
}

/**
 * Set session token in secure storage
 */
export function setSessionToken(token: string | null): void {
  setSecure('sessionToken', token)
}

/**
 * Get device token from secure storage (for 2FA trusted device)
 */
export function getDeviceToken(): string | null {
  return getSecure('deviceToken')
}

/**
 * Set device token in secure storage
 */
export function setDeviceToken(token: string | null): void {
  setSecure('deviceToken', token)
}

/**
 * Get device ID from secure storage
 */
export function getDeviceId(): string | null {
  return getSecure('deviceId')
}

/**
 * Set device ID in secure storage
 */
export function setDeviceId(id: string | null): void {
  setSecure('deviceId', id)
}

/**
 * Get NAS credentials from secure storage
 */
export function getNasCredentials(): { username: string | null; password: string | null } {
  return {
    username: getSecure('nasUsername'),
    password: getSecure('nasPassword'),
  }
}

/**
 * Set NAS credentials in secure storage
 */
export function setNasCredentials(username: string | null, password: string | null): void {
  setSecure('nasUsername', username)
  setSecure('nasPassword', password)
}

