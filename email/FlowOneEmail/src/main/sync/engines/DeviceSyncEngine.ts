import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Device Sync Engine
 * 
 * Handles synchronization of device registry data:
 * - Registered devices (desktop, web, drive clients)
 * - Device status (active, blocked, wipe_pending, wiped)
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all devices from /devices
 * - Incremental: WebSocket events for device status changes
 * - This is primarily a read-only cache of server-managed device data
 */
export class DeviceSyncEngine extends BaseSyncEngine {
  entityType = 'device'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[DeviceSync] Pulling changes...')
    await this.syncDevices()
  }

  /**
   * Sync all devices from server
   */
  private async syncDevices(): Promise<void> {
    try {
      const response = await this.api.get('/devices')
      
      if (response.data.success && response.data.data) {
        const devices = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.devices || []
        
        for (const device of devices) {
          this.upsertDevice(device)
        }
        
        console.log(`[DeviceSync] Synced ${devices.length} devices`)
      }
    } catch (error: any) {
      console.error('[DeviceSync] Failed to sync devices:', error.message)
      throw error
    }
  }

  /**
   * Insert or update a device
   */
  private upsertDevice(device: any): void {
    this.db.prepare(`
      INSERT INTO devices (
        remote_id, email, device_id, device_name, platform, os,
        app_version, status, last_ip, last_seen_at,
        wipe_requested_at, wipe_confirmed_at,
        sync_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        device_name = excluded.device_name,
        platform = excluded.platform,
        os = excluded.os,
        app_version = excluded.app_version,
        status = excluded.status,
        last_ip = excluded.last_ip,
        last_seen_at = excluded.last_seen_at,
        wipe_requested_at = excluded.wipe_requested_at,
        wipe_confirmed_at = excluded.wipe_confirmed_at,
        sync_status = 'synced'
    `).run(
      device.id,
      device.email || '',
      device.device_id || '',
      device.device_name || null,
      device.platform || 'web',
      device.os || null,
      device.app_version || null,
      device.status || 'active',
      device.last_ip || null,
      device.last_seen_at || null,
      device.wipe_requested_at || null,
      device.wipe_confirmed_at || null,
      device.created_at || new Date().toISOString()
    )
  }

  /**
   * Push a queued change to server
   * Devices are primarily server-managed, but we support block/unblock/wipe actions
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'block':
        if (payload.remote_id) {
          await this.api.post(`/devices/${payload.remote_id}/block`)
        }
        break
        
      case 'unblock':
        if (payload.remote_id) {
          await this.api.post(`/devices/${payload.remote_id}/unblock`)
        }
        break
        
      case 'wipe':
        if (payload.remote_id) {
          await this.api.post(`/devices/${payload.remote_id}/wipe`)
        }
        break
        
      default:
        console.warn(`[DeviceSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[DeviceSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'DEVICE_REGISTERED':
      case 'DEVICE_UPDATED':
        if (event.payload) {
          this.upsertDevice(event.payload)
        }
        break
        
      case 'DEVICE_BLOCKED':
        if (event.payload?.id) {
          this.db.prepare(
            "UPDATE devices SET status = 'blocked' WHERE remote_id = ?"
          ).run(event.payload.id)
        }
        break
        
      case 'DEVICE_UNBLOCKED':
        if (event.payload?.id) {
          this.db.prepare(
            "UPDATE devices SET status = 'active' WHERE remote_id = ?"
          ).run(event.payload.id)
        }
        break
        
      case 'DEVICE_WIPE_REQUESTED':
        if (event.payload?.id) {
          this.db.prepare(
            "UPDATE devices SET status = 'wipe_pending', wipe_requested_at = datetime('now') WHERE remote_id = ?"
          ).run(event.payload.id)
        }
        break
        
      case 'DEVICE_WIPED':
        if (event.payload?.id) {
          this.db.prepare(
            "UPDATE devices SET status = 'wiped', wipe_confirmed_at = datetime('now') WHERE remote_id = ?"
          ).run(event.payload.id)
        }
        break
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getDevices(): any[] {
    return this.db.all('SELECT * FROM devices ORDER BY last_seen_at DESC')
  }

  getActiveDevices(): any[] {
    return this.db.all(
      "SELECT * FROM devices WHERE status = 'active' ORDER BY last_seen_at DESC"
    )
  }

  getDeviceById(remoteId: number): any | null {
    return this.db.get('SELECT * FROM devices WHERE remote_id = ?', [remoteId]) || null
  }
}

