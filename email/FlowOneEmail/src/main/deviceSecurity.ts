/**
 * Device Security Module for FlowOneEmail
 * 
 * Handles:
 * - Device registration with the server on login
 * - Periodic polling for device status (blocked, wipe_pending)
 * - Remote wipe execution (clear all local data)
 */

import { app, BrowserWindow } from 'electron'
import https from 'https'
import fs from 'fs'
import path from 'path'
import { getOrCreateDeviceId, getDeviceInfo } from './deviceId'
import { wipeAllSecureData, getAuthToken, getSessionToken, setAuthToken, setSessionToken } from './secureStorage'
import { configStore } from './config'

let pollTimer: ReturnType<typeof setInterval> | null = null
let mainWindowRef: BrowserWindow | null = null

const POLL_INTERVAL = 30_000 // 30 seconds

/**
 * Register this device with the server
 * Called after successful login
 */
export async function registerDevice(apiUrl: string, authToken: string, sessionToken?: string | null): Promise<boolean> {
  const info = getDeviceInfo()
  
  try {
    const body = JSON.stringify({
      device_id: info.deviceId,
      device_name: info.deviceName,
      platform: info.platform,
      os: info.os,
      app_version: info.appVersion,
    })
    
    const url = new URL(`${apiUrl}/api/devices/register`)
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${authToken}`,
      'Content-Length': Buffer.byteLength(body).toString(),
      'X-Device-Id': info.deviceId,
    }
    if (sessionToken) {
      headers['X-Session-Token'] = sessionToken
    }
    
    return new Promise((resolve) => {
      const req = https.request({
        hostname: url.hostname,
        port: url.port || 443,
        path: url.pathname,
        method: 'POST',
        headers,
      }, (res) => {
        let data = ''
        res.on('data', (chunk) => data += chunk)
        res.on('end', () => {
          try {
            const result = JSON.parse(data)
            if (result.success) {
              console.log('[DeviceSecurity] Device registered:', info.deviceId)
              resolve(true)
            } else {
              console.warn('[DeviceSecurity] Registration rejected:', result.message)
              // If blocked, trigger logout
              if (result.action === 'blocked') {
                handleForcedLogout('device_blocked')
              }
              resolve(false)
            }
          } catch {
            console.error('[DeviceSecurity] Invalid response from register')
            resolve(false)
          }
        })
      })
      
      req.on('error', (err) => {
        console.error('[DeviceSecurity] Register request failed:', err.message)
        resolve(false)
      })
      
      req.setTimeout(10000, () => {
        req.destroy()
        resolve(false)
      })
      
      req.write(body)
      req.end()
    })
  } catch (err: any) {
    console.error('[DeviceSecurity] Register error:', err.message)
    return false
  }
}

/**
 * Start polling the server for device status changes
 */
export function startStatusPolling(window: BrowserWindow): void {
  mainWindowRef = window
  
  if (pollTimer) {
    clearInterval(pollTimer)
  }
  
  pollTimer = setInterval(checkDeviceStatus, POLL_INTERVAL)
  
  // First check after 5 seconds (let app settle)
  setTimeout(checkDeviceStatus, 5000)
  
  console.log('[DeviceSecurity] Status polling started (every 30s)')
}

/**
 * Stop polling
 */
export function stopStatusPolling(): void {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
  console.log('[DeviceSecurity] Status polling stopped')
}

/**
 * Check device status with the server
 */
async function checkDeviceStatus(): Promise<void> {
  const authToken = getAuthToken()
  const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
  
  if (!authToken) return
  
  const deviceId = getOrCreateDeviceId()
  
  try {
    const url = new URL(`${apiUrl}/api/devices/check?device_id=${encodeURIComponent(deviceId)}`)
    const headers: Record<string, string> = {
      'Authorization': `Bearer ${authToken}`,
      'X-Device-Id': deviceId,
    }
    
    const sessionToken = getSessionToken()
    if (sessionToken) {
      headers['X-Session-Token'] = sessionToken
    }
    
    const result = await new Promise<any>((resolve) => {
      const req = https.request({
        hostname: url.hostname,
        port: url.port || 443,
        path: url.pathname + url.search,
        method: 'GET',
        headers,
      }, (res) => {
        let data = ''
        res.on('data', (chunk) => data += chunk)
        res.on('end', () => {
          try {
            resolve(JSON.parse(data))
          } catch {
            resolve(null)
          }
        })
      })
      
      req.on('error', () => resolve(null))
      req.setTimeout(10000, () => {
        req.destroy()
        resolve(null)
      })
      
      req.end()
    })
    
    if (!result?.success) return
    
    const status = result.data
    
    switch (status?.action) {
      case 'wipe':
        console.log('[DeviceSecurity] WIPE command received!')
        await executeRemoteWipe(apiUrl, authToken)
        break
      case 'logout':
        console.log('[DeviceSecurity] LOGOUT command received, reason:', status.status)
        handleForcedLogout(status.status)
        break
      // 'none' = all good, do nothing
    }
  } catch (err: any) {
    // Silent fail - polling should not disrupt the app
    console.debug('[DeviceSecurity] Status check failed:', err.message)
  }
}

/**
 * Execute remote wipe - destroy all local data
 */
async function executeRemoteWipe(apiUrl: string, authToken: string): Promise<void> {
  console.log('[DeviceSecurity] Executing remote wipe...')
  
  // 1. Wipe secure storage (tokens, credentials)
  wipeAllSecureData()
  
  // 2. Clear config (tokens already wiped in secureStorage above, clear remaining user data)
  setAuthToken(null)
  setSessionToken(null)
  configStore.set('userEmail', null)
  configStore.set('userName', null)
  
  // 3. Delete the SQLite database (emails, calendars, boards, etc.)
  const userDataPath = app.getPath('userData')
  const dbFiles = ['mailflow.db', 'mailflow.db-wal', 'mailflow.db-shm']
  for (const dbFile of dbFiles) {
    const dbPath = path.join(userDataPath, dbFile)
    try {
      if (fs.existsSync(dbPath)) {
        fs.unlinkSync(dbPath)
        console.log('[DeviceSecurity] Deleted:', dbPath)
      }
    } catch (err: any) {
      console.error('[DeviceSecurity] Failed to delete', dbFile, ':', err.message)
    }
  }
  
  // 4. Delete the encrypted secure-data store
  const secureStorePath = path.join(userDataPath, 'secure-data.json')
  try {
    if (fs.existsSync(secureStorePath)) {
      fs.unlinkSync(secureStorePath)
    }
  } catch (err: any) {
    console.error('[DeviceSecurity] Failed to delete secure-data:', err.message)
  }
  
  // 5. Confirm wipe to server
  const deviceId = getOrCreateDeviceId()
  try {
    const body = JSON.stringify({ device_id: deviceId })
    const url = new URL(`${apiUrl}/api/devices/wipe-confirm`)
    
    await new Promise<void>((resolve) => {
      const req = https.request({
        hostname: url.hostname,
        port: url.port || 443,
        path: url.pathname,
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Content-Length': Buffer.byteLength(body).toString(),
        },
      }, () => resolve())
      
      req.on('error', () => resolve())
      req.setTimeout(10000, () => {
        req.destroy()
        resolve()
      })
      
      req.write(body)
      req.end()
    })
    console.log('[DeviceSecurity] Wipe confirmed to server')
  } catch {
    // Best effort - wipe happened locally regardless
  }
  
  // 6. Stop polling
  stopStatusPolling()
  
  // 7. Show login screen
  console.log('[DeviceSecurity] Remote wipe complete. Restarting app...')
  mainWindowRef?.webContents.send('remote-wipe-executed')
  
  // Restart the app
  app.relaunch()
  app.exit(0)
}

/**
 * Handle forced logout (device blocked or session revoked)
 */
function handleForcedLogout(reason: string): void {
  console.log('[DeviceSecurity] Forced logout, reason:', reason)
  
  // Clear auth tokens but keep device ID
  setAuthToken(null)
  setSessionToken(null)
  
  stopStatusPolling()
  
  // Notify renderer
  mainWindowRef?.webContents.send('forced-logout', { reason })
  mainWindowRef?.show()
  mainWindowRef?.focus()
}

/**
 * Set the main window reference (call when window is created)
 */
export function setMainWindow(window: BrowserWindow): void {
  mainWindowRef = window
}

