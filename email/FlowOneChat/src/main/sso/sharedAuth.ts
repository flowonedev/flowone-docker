import * as fs from 'fs'
import * as path from 'path'
import * as os from 'os'
import * as crypto from 'crypto'

const SHARED_DIR = path.join(os.homedir(), '.flowone')
const SHARED_AUTH_PATH = path.join(SHARED_DIR, 'shared-auth.json')
const SCHEMA_VERSION = 1
const SALT_LENGTH = 32
const IV_LENGTH = 12
const AUTH_TAG_LENGTH = 16
const PBKDF2_ITERATIONS = 100000

export interface SharedAuthData {
  schemaVersion: number
  userEmail: string
  displayName: string
  baseUrl: string
  seedId: string
  seedSecret: string
  seedCreatedAt: string
  seedExpiresAt: string
  updatedAt: number
  checksum: string
}

function ensureSharedDir(): void {
  if (!fs.existsSync(SHARED_DIR)) {
    fs.mkdirSync(SHARED_DIR, { recursive: true, mode: 0o700 })
  }
}

function getOsMachineId(): string {
  try {
    if (process.platform === 'win32') {
      const { execSync } = require('child_process')
      const result = execSync(
        'reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid',
        { encoding: 'utf-8', timeout: 5000 }
      )
      const match = result.match(/MachineGuid\s+REG_SZ\s+(.+)/)
      if (match) return match[1].trim()
    } else if (process.platform === 'darwin') {
      const { execSync } = require('child_process')
      const result = execSync(
        "ioreg -rd1 -c IOPlatformExpertDevice | awk '/IOPlatformUUID/{print $3}'",
        { encoding: 'utf-8', timeout: 5000 }
      )
      return result.trim().replace(/"/g, '')
    } else {
      if (fs.existsSync('/etc/machine-id')) {
        return fs.readFileSync('/etc/machine-id', 'utf-8').trim()
      }
    }
  } catch (e) {
    console.error('[SharedAuth] Failed to get machine ID:', e)
  }
  return 'flowone-fallback-machine-id'
}

function deriveKey(salt: Buffer): Buffer {
  const machineId = getOsMachineId()
  return crypto.pbkdf2Sync(machineId, salt, PBKDF2_ITERATIONS, 32, 'sha256')
}

function encrypt(data: SharedAuthData): Buffer {
  const salt = crypto.randomBytes(SALT_LENGTH)
  const key = deriveKey(salt)
  const iv = crypto.randomBytes(IV_LENGTH)
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv)

  const json = JSON.stringify(data)
  const encrypted = Buffer.concat([cipher.update(json, 'utf-8'), cipher.final()])
  const authTag = cipher.getAuthTag()

  return Buffer.concat([salt, iv, encrypted, authTag])
}

function decrypt(buf: Buffer): SharedAuthData | null {
  try {
    if (buf.length < SALT_LENGTH + IV_LENGTH + AUTH_TAG_LENGTH + 1) return null

    const salt = buf.subarray(0, SALT_LENGTH)
    const iv = buf.subarray(SALT_LENGTH, SALT_LENGTH + IV_LENGTH)
    const authTag = buf.subarray(buf.length - AUTH_TAG_LENGTH)
    const ciphertext = buf.subarray(SALT_LENGTH + IV_LENGTH, buf.length - AUTH_TAG_LENGTH)

    const key = deriveKey(salt)
    const decipher = crypto.createDecipheriv('aes-256-gcm', key, iv)
    decipher.setAuthTag(authTag)

    const decrypted = Buffer.concat([decipher.update(ciphertext), decipher.final()])
    return JSON.parse(decrypted.toString('utf-8'))
  } catch (e) {
    console.error('[SharedAuth] Decrypt failed:', e)
    return null
  }
}

function computeChecksum(data: Omit<SharedAuthData, 'checksum'>): string {
  const payload = JSON.stringify({
    schemaVersion: data.schemaVersion,
    userEmail: data.userEmail,
    seedId: data.seedId,
    seedCreatedAt: data.seedCreatedAt,
    updatedAt: data.updatedAt,
  })
  return crypto.createHash('sha256').update(payload).digest('hex').substring(0, 16)
}

function sleep(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms))
}

async function atomicWrite(filePath: string, data: Buffer): Promise<void> {
  const tmpPath = `${filePath}.tmp.${process.pid}`
  fs.writeFileSync(tmpPath, data, { mode: 0o600 })

  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      fs.renameSync(tmpPath, filePath)
      return
    } catch (e) {
      if (attempt < 2) await sleep(100 * (attempt + 1))
    }
  }
  // Fallback: copy + unlink
  fs.copyFileSync(tmpPath, filePath)
  try { fs.unlinkSync(tmpPath) } catch {}
}

export function readSharedAuth(): SharedAuthData | null {
  try {
    if (!fs.existsSync(SHARED_AUTH_PATH)) return null
    const buf = fs.readFileSync(SHARED_AUTH_PATH)
    if (buf.length === 0) return null
    const data = decrypt(buf)
    if (!data || data.schemaVersion !== SCHEMA_VERSION) return null
    return data
  } catch (e) {
    console.error('[SharedAuth] Read failed:', e)
    return null
  }
}

export async function writeSharedAuth(data: Omit<SharedAuthData, 'schemaVersion' | 'checksum'>): Promise<void> {
  ensureSharedDir()

  const existing = readSharedAuth()
  // Only write if our seed is newer (or no existing data)
  if (existing && existing.seedCreatedAt > data.seedCreatedAt) {
    console.log('[SharedAuth] Skipping write, existing seed is newer')
    return
  }

  const fullData: SharedAuthData = {
    ...data,
    schemaVersion: SCHEMA_VERSION,
    checksum: '',
  }
  fullData.checksum = computeChecksum(fullData)

  const encrypted = encrypt(fullData)
  await atomicWrite(SHARED_AUTH_PATH, encrypted)
}

export function clearSharedAuth(): void {
  try {
    if (fs.existsSync(SHARED_AUTH_PATH)) {
      fs.unlinkSync(SHARED_AUTH_PATH)
    }
  } catch (e) {
    console.error('[SharedAuth] Clear failed:', e)
  }
}

export class SharedAuthWatcher {
  private pollInterval: ReturnType<typeof setInterval> | null = null
  private fsWatcher: fs.FSWatcher | null = null
  private lastChecksum: string = ''
  private lastWriteTime: number = 0
  private stopped = false

  start(callback: (data: SharedAuthData | null) => void): void {
    this.stopped = false
    ensureSharedDir()

    // Primary: poll every 5 seconds
    this.pollInterval = setInterval(() => {
      if (!this.stopped) this.checkForChanges(callback)
    }, 5000)

    // Optimization: fs.watch triggers immediate check
    try {
      this.fsWatcher = fs.watch(SHARED_DIR, (eventType, filename) => {
        if (filename === 'shared-auth.json' && !this.stopped) {
          setTimeout(() => this.checkForChanges(callback), 100)
        }
      })
      this.fsWatcher.on('error', () => {
        // fs.watch unavailable -- polling is sufficient
      })
    } catch {
      // fs.watch unavailable
    }

    // Initial check
    this.checkForChanges(callback)
  }

  markWrite(): void {
    this.lastWriteTime = Date.now()
  }

  private checkForChanges(callback: (data: SharedAuthData | null) => void): void {
    // Skip self-echo
    if (Date.now() - this.lastWriteTime < 1000) return

    try {
      const data = readSharedAuth()
      const newChecksum = data?.checksum ?? 'empty'
      if (newChecksum !== this.lastChecksum) {
        this.lastChecksum = newChecksum
        callback(data)
      }
    } catch (e) {
      console.error('[SharedAuth] Watch check failed:', e)
    }
  }

  stop(): void {
    this.stopped = true
    if (this.pollInterval) {
      clearInterval(this.pollInterval)
      this.pollInterval = null
    }
    if (this.fsWatcher) {
      this.fsWatcher.close()
      this.fsWatcher = null
    }
  }
}
