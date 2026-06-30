import { safeStorage } from 'electron'
import Store from 'electron-store'
import crypto from 'crypto'
import { execSync } from 'child_process'
import os from 'os'

interface SecureData {
  authToken: string | null
  sessionToken: string | null
  deviceId: string | null
}

const encryptedStore = new Store<Record<string, string>>({
  name: 'secure-data',
  defaults: {},
})

let cachedMachineKey: Buffer | null = null

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
  return `${os.hostname()}:${os.userInfo().username}:${process.platform}`
}

function getMachineKey(): Buffer {
  if (cachedMachineKey) return cachedMachineKey
  const machineId = getOsMachineId()
  let salt = encryptedStore.get('_pbkdf2_salt' as any) as string | undefined
  if (!salt) {
    salt = crypto.randomBytes(32).toString('hex')
    encryptedStore.set('_pbkdf2_salt' as any, salt)
  }
  cachedMachineKey = crypto.pbkdf2Sync(machineId, salt, 100_000, 32, 'sha256')
  return cachedMachineKey
}

function softwareEncrypt(value: string): string {
  const key = getMachineKey()
  const iv = crypto.randomBytes(12)
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv)
  const encrypted = Buffer.concat([cipher.update(value, 'utf8'), cipher.final()])
  const authTag = cipher.getAuthTag()
  const packed = Buffer.concat([iv, authTag, encrypted])
  return packed.toString('base64')
}

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

export function setSecure(key: keyof SecureData, value: string | null): void {
  if (!value) {
    encryptedStore.delete(key)
    return
  }
  if (safeStorage.isEncryptionAvailable()) {
    const encrypted = safeStorage.encryptString(value)
    encryptedStore.set(key, encrypted.toString('base64'))
  } else {
    const encrypted = softwareEncrypt(value)
    encryptedStore.set(key, `sw:${encrypted}`)
  }
}

export function getSecure(key: keyof SecureData): string | null {
  const stored = encryptedStore.get(key)
  if (!stored) return null
  try {
    if (stored.startsWith('plain:')) {
      const plainValue = stored.substring(6)
      setSecure(key, plainValue)
      return plainValue
    }
    if (stored.startsWith('sw:')) {
      return softwareDecrypt(stored.substring(3))
    }
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

export function getAuthToken(): string | null { return getSecure('authToken') }
export function setAuthToken(token: string | null): void { setSecure('authToken', token) }
export function getSessionToken(): string | null { return getSecure('sessionToken') }
export function setSessionToken(token: string | null): void { setSecure('sessionToken', token) }
