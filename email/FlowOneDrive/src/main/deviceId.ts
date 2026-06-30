/**
 * Device Fingerprinting Module for FlowOneDrive
 * 
 * Generates a unique, persistent device identifier based on hardware characteristics.
 * This ID is used for:
 * - Device registry (tracking which devices are authorized)
 * - Session-to-device linking
 * - Remote wipe targeting
 * 
 * The ID is deterministic (same machine always produces the same ID) and
 * stored in secure storage for quick access.
 */

import { createHash } from 'crypto'
import os from 'os'
import { execSync } from 'child_process'
import { getDeviceId, setDeviceId } from './secureStorage'

let cachedDeviceId: string | null = null

/**
 * Get the machine ID from the OS
 * Windows: MachineGuid from registry
 * macOS: IOPlatformUUID from IOKit
 * Linux: /etc/machine-id
 */
function getMachineId(): string {
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
        console.warn('[DeviceId] Failed to get machine ID:', err)
    }

    // Fallback: use hostname + username + platform
    return `${os.hostname()}-${os.userInfo().username}-${process.platform}`
}

/**
 * Generate a deterministic device fingerprint
 * Combines machine ID with app identifier to create a unique hash
 */
function generateDeviceFingerprint(): string {
    const machineId = getMachineId()
    const appSalt = 'mailflow-drive-v1'

    const hash = createHash('sha256')
        .update(`${machineId}:${appSalt}`)
        .digest('hex')

    // Return first 32 chars for a clean ID (prefixed differently from Desktop)
    return `mfv-${hash.substring(0, 32)}`
}

/**
 * Get the device ID (generates on first call, caches thereafter)
 */
export function getOrCreateDeviceId(): string {
    // Check memory cache first
    if (cachedDeviceId) return cachedDeviceId

    // Check secure storage
    const stored = getDeviceId()
    if (stored) {
        cachedDeviceId = stored
        return stored
    }

    // Generate new ID
    const newId = generateDeviceFingerprint()
    setDeviceId(newId)
    cachedDeviceId = newId

    console.log('[DeviceId] Generated device ID:', newId)
    return newId
}

/**
 * Get device metadata for registration
 */
export function getDeviceInfo(): {
    deviceId: string
    deviceName: string
    platform: string
    os: string
    appVersion: string
} {
    const packageJson = require('../../package.json')

    return {
        deviceId: getOrCreateDeviceId(),
        deviceName: `${os.hostname()} (Drive)`,
        platform: 'drive',
        os: `${process.platform === 'win32' ? 'Windows' : process.platform === 'darwin' ? 'macOS' : 'Linux'} ${os.release()}`,
        appVersion: packageJson.version || '1.0.0',
    }
}

