/**
 * Device Security Module for FlowOneDrive
 * 
 * Handles:
 * - Device registration with the server on login
 * - Periodic polling for device status (blocked, wipe_pending)
 * - Remote wipe execution (clear all local data including synced files)
 */

import { app, BrowserWindow } from 'electron'
import fs from 'fs'
import path from 'path'
import { getOrCreateDeviceId, getDeviceInfo } from './deviceId'
import { wipeAllSecureData, setAuthToken, setSessionToken, setDeviceToken } from './secureStorage'
import { ConfigStore } from './config'

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
    const axios = require('axios')
    const headers: Record<string, string> = {
      'Authorization': `Bearer ${authToken}`,
      'X-Device-Id': info.deviceId,
    }
    if (sessionToken) {
      headers['X-Session-Token'] = sessionToken
    }
    
    const response = await axios.post(`${apiUrl}/api/devices/register`, {
      device_id: info.deviceId,
      device_name: info.deviceName,
      platform: info.platform,
      os: info.os,
      app_version: info.appVersion,
    }, { headers, timeout: 10000 })
    
    if (response.data.success) {
      console.log('[DeviceSecurity] Device registered:', info.deviceId)
      return true
    }
    
    console.warn('[DeviceSecurity] Registration rejected:', response.data.message)
    if (response.data.action === 'blocked') {
      handleForcedLogout('device_blocked')
    }
    return false
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
  const config = ConfigStore.getInstance()
  const { getAuthToken, getSessionToken } = require('./secureStorage')
  const authToken = getAuthToken()
  const apiUrl = config.get('apiUrl')
  
  if (!authToken || !apiUrl) return
  
  const deviceId = getOrCreateDeviceId()
  
  try {
    const axios = require('axios')
    const headers: Record<string, string> = {
      'Authorization': `Bearer ${authToken}`,
      'X-Device-Id': deviceId,
    }
    
    const sessionToken = getSessionToken()
    if (sessionToken) {
      headers['X-Session-Token'] = sessionToken
    }
    
    const response = await axios.get(`${apiUrl}/api/devices/check`, {
      params: { device_id: deviceId },
      headers,
      timeout: 10000,
    })
    
    if (!response.data?.success) return
    
    const status = response.data.data
    
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
 * Execute remote wipe - destroy all local data including synced files
 */
async function executeRemoteWipe(apiUrl: string, authToken: string): Promise<void> {
  console.log('[DeviceSecurity] Executing remote wipe...')
  
  const config = ConfigStore.getInstance()
  
  // 1. Wipe secure storage (tokens, credentials)
  wipeAllSecureData()
  
  // 2. Delete synced files in the sync folder
  const syncFolder = config.get('syncFolder')
  if (syncFolder && fs.existsSync(syncFolder)) {
    try {
      // Remove all contents of sync folder but keep the folder itself
      const entries = fs.readdirSync(syncFolder)
      for (const entry of entries) {
        const fullPath = path.join(syncFolder, entry)
        try {
          const stat = fs.statSync(fullPath)
          if (stat.isDirectory()) {
            fs.rmSync(fullPath, { recursive: true, force: true })
          } else {
            fs.unlinkSync(fullPath)
          }
        } catch (err: any) {
          console.error('[DeviceSecurity] Failed to delete:', fullPath, err.message)
        }
      }
      console.log('[DeviceSecurity] Sync folder wiped:', syncFolder)
    } catch (err: any) {
      console.error('[DeviceSecurity] Failed to wipe sync folder:', err.message)
    }
  }
  
  // 3. Delete the local database
  const userDataPath = app.getPath('userData')
  const dbFiles = ['mailflow-drive.db', 'mailflow-drive.db-wal', 'mailflow-drive.db-shm']
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
  
  // 4. Delete config and secure stores
  const storesToDelete = ['secure-data.json', 'mailflow-drive-config.json']
  for (const storeName of storesToDelete) {
    const storePath = path.join(userDataPath, storeName)
    try {
      if (fs.existsSync(storePath)) {
        fs.unlinkSync(storePath)
      }
    } catch (err: any) {
      console.error('[DeviceSecurity] Failed to delete', storeName, ':', err.message)
    }
  }
  
  // 5. Clear tokens from secure storage + config
  setAuthToken(null)
  setSessionToken(null)
  setDeviceToken(null)
  config.set('userEmail', null)
  
  // 6. Confirm wipe to server
  const deviceId = getOrCreateDeviceId()
  try {
    const axios = require('axios')
    await axios.post(`${apiUrl}/api/devices/wipe-confirm`, 
      { device_id: deviceId },
      { 
        headers: { 'Authorization': `Bearer ${authToken}` },
        timeout: 10000,
      }
    )
    console.log('[DeviceSecurity] Wipe confirmed to server')
  } catch {
    // Best effort
  }
  
  // 7. Stop polling
  stopStatusPolling()
  
  // 8. Restart app
  console.log('[DeviceSecurity] Remote wipe complete. Restarting app...')
  mainWindowRef?.webContents.send('remote-wipe-executed')
  
  app.relaunch()
  app.exit(0)
}

/**
 * Handle forced logout (device blocked or session revoked)
 */
function handleForcedLogout(reason: string): void {
  console.log('[DeviceSecurity] Forced logout, reason:', reason)
  
  setAuthToken(null)
  setSessionToken(null)
  
  stopStatusPolling()
  
  // Notify renderer
  mainWindowRef?.webContents.send('forced-logout', { reason })
  mainWindowRef?.show()
  mainWindowRef?.focus()
}

/**
 * Set the main window reference
 */
export function setMainWindow(window: BrowserWindow): void {
  mainWindowRef = window
}

