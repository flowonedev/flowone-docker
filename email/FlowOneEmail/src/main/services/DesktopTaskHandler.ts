/**
 * Desktop Task Handler
 *
 * Handles DESKTOP_TASK events from the VPS (via WebSocket or polling)
 * and relays them to FlowOneDrive for execution.
 *
 * Supported task types:
 *   - printer_list:  Get available printers
 *   - printer_print: Print a document
 */

import axios, { AxiosInstance } from 'axios'
import { configStore } from '../config'
import { getAuthToken, getSessionToken } from '../secureStorage'
import { getOrCreateDeviceId } from '../deviceId'
import { getDriveIntegration } from './DriveIntegration'

export class DesktopTaskHandler {
  private api: AxiosInstance
  private pollInterval: NodeJS.Timeout | null = null
  private isProcessing = false

  constructor() {
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    this.api = axios.create({
      baseURL: `${apiUrl}/api`,
      timeout: 15000,
    })

    this.api.interceptors.request.use((config) => {
      const token = getAuthToken()
      if (token) {
        config.headers.Authorization = `Bearer ${token}`
      }
      const sessionToken = getSessionToken()
      if (sessionToken) {
        config.headers['X-Session-Token'] = sessionToken
      }
      const deviceId = getOrCreateDeviceId()
      if (deviceId) {
        config.headers['X-Device-Id'] = deviceId
      }
      return config
    })
  }

  /**
   * Handle a DESKTOP_TASK event from WebSocket
   */
  async handleEvent(event: { task_id: number; task_type: string; [key: string]: any }): Promise<void> {
    console.log(`[DesktopTask] Received task: ${event.task_type} (id: ${event.task_id})`)

    try {
      const result = await this.executeTask(event.task_type, event)
      await this.reportResult(event.task_id, true, result)
    } catch (err: any) {
      console.error(`[DesktopTask] Task ${event.task_id} failed:`, err.message)
      await this.reportResult(event.task_id, false, { error: err.message })
    }
  }

  /**
   * Start polling for pending tasks (fallback if WebSocket broadcast misses)
   */
  startPolling(intervalMs = 10000): void {
    this.stopPolling()
    this.pollInterval = setInterval(() => this.pollPendingTasks(), intervalMs)
    console.log(`[DesktopTask] Started polling (${intervalMs}ms interval)`)
  }

  stopPolling(): void {
    if (this.pollInterval) {
      clearInterval(this.pollInterval)
      this.pollInterval = null
    }
  }

  private async pollPendingTasks(): Promise<void> {
    if (this.isProcessing) return
    this.isProcessing = true

    try {
      const res = await this.api.get('/automation-hub/desktop-tasks/pending')
      const tasks = res.data?.data?.tasks || []

      for (const task of tasks) {
        try {
          const result = await this.executeTask(task.task_type, task)
          await this.reportResult(task.id, true, result)
        } catch (err: any) {
          console.error(`[DesktopTask] Poll task ${task.id} failed:`, err.message)
          await this.reportResult(task.id, false, { error: err.message })
        }
      }
    } catch (err: any) {
      if (err.response?.status !== 401) {
        console.warn('[DesktopTask] Poll failed:', err.message)
      }
    } finally {
      this.isProcessing = false
    }
  }

  private async executeTask(taskType: string, task: any): Promise<any> {
    const drive = getDriveIntegration()

    if (!drive.isDriveAvailable) {
      throw new Error('FlowOneDrive is not running')
    }

    const payload = task.payload || task

    switch (taskType) {
      case 'printer_list': {
        const printers = await drive.getPrinters()
        if (printers === null) {
          throw new Error('Failed to get printers from FlowOneDrive')
        }
        return { printers }
      }

      case 'printer_print': {
        const result = await drive.printDocument({
          printerName: payload.printer_name,
          filePath: payload.file_path,
          htmlContent: payload.html_content,
          copies: payload.copies,
          silent: payload.silent,
          duplex: payload.duplex,
        })
        return result
      }

      default:
        throw new Error(`Unknown task type: ${taskType}`)
    }
  }

  private async reportResult(taskId: number, success: boolean, result: any): Promise<void> {
    try {
      await this.api.post(`/automation-hub/desktop-tasks/${taskId}/result`, {
        success,
        result,
      })
      console.log(`[DesktopTask] Reported result for task ${taskId}: ${success ? 'success' : 'failed'}`)
    } catch (err: any) {
      console.error(`[DesktopTask] Failed to report result for task ${taskId}:`, err.message)
    }
  }

  shutdown(): void {
    this.stopPolling()
  }
}

let instance: DesktopTaskHandler | null = null

export function getDesktopTaskHandler(): DesktopTaskHandler {
  if (!instance) {
    instance = new DesktopTaskHandler()
  }
  return instance
}

export function shutdownDesktopTaskHandler(): void {
  if (instance) {
    instance.shutdown()
    instance = null
  }
}
