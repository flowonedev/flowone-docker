/**
 * Printer Service
 *
 * Wraps Electron's built-in printer APIs for local printer discovery
 * and document printing. Used by IPC handlers and the local Express API.
 */

import { BrowserWindow } from 'electron'
import fs from 'fs'
import path from 'path'
import { scanNetworkPrinters, NetworkPrinter } from './networkPrinterScanner'

export interface PrinterInfo {
  name: string
  displayName: string
  status: number
  isDefault: boolean
  options: Record<string, string>
  source: 'local' | 'network'
  ip?: string
  port?: number
  protocol?: string
  model?: string
  location?: string
  mac?: string
}

export interface PrintOptions {
  copies?: number
  silent?: boolean
  duplex?: 'default' | 'long-edge' | 'short-edge'
  pageSize?: string
}

export interface PrintResult {
  success: boolean
  printer: string
  error?: string
}

export class PrinterService {
  private mainWindow: BrowserWindow | null = null

  setWindow(win: BrowserWindow): void {
    this.mainWindow = win
  }

  async getPrinters(): Promise<PrinterInfo[]> {
    if (!this.mainWindow) return []

    try {
      const printers = await this.mainWindow.webContents.getPrintersAsync()
      return printers.map(p => ({
        name: p.name,
        displayName: p.displayName,
        status: p.status,
        isDefault: p.isDefault,
        options: Object.fromEntries(Object.entries(p.options || {})) as Record<string, string>,
        source: 'local' as const,
      }))
    } catch (error: any) {
      console.error('[PrinterService] Failed to get printers:', error.message)
      return []
    }
  }

  async getDefaultPrinter(): Promise<PrinterInfo | null> {
    const printers = await this.getPrinters()
    return printers.find(p => p.isDefault) || null
  }

  private _scanInProgress = false
  private _lastNetworkScan: NetworkPrinter[] = []

  async scanNetwork(onProgress?: (scanned: number, total: number) => void): Promise<PrinterInfo[]> {
    if (this._scanInProgress) {
      return this._lastNetworkScan.map(np => this.networkToLocal(np))
    }

    this._scanInProgress = true
    try {
      const networkPrinters = await scanNetworkPrinters({}, onProgress)
      this._lastNetworkScan = networkPrinters
      return networkPrinters.map(np => this.networkToLocal(np))
    } finally {
      this._scanInProgress = false
    }
  }

  async getAllPrinters(onProgress?: (scanned: number, total: number) => void): Promise<{
    local: PrinterInfo[]
    network: PrinterInfo[]
  }> {
    const [local, network] = await Promise.all([
      this.getPrinters(),
      this.scanNetwork(onProgress),
    ])
    return { local, network }
  }

  private networkToLocal(np: NetworkPrinter): PrinterInfo {
    return {
      name: np.name || `${np.ip}:${np.port}`,
      displayName: np.name || `Network Printer (${np.ip})`,
      status: np.status === 'online' ? 0 : 2,
      isDefault: false,
      options: {},
      source: 'network',
      ip: np.ip,
      port: np.port,
      protocol: np.protocol,
      model: np.model,
      location: np.location,
      mac: np.mac,
    }
  }

  /**
   * Print a local file by loading it in a hidden BrowserWindow.
   * Supports PDF, HTML, and text files.
   */
  async printFile(filePath: string, printerName: string, options: PrintOptions = {}): Promise<PrintResult> {
    if (!fs.existsSync(filePath)) {
      return { success: false, printer: printerName, error: `File not found: ${filePath}` }
    }

    const ext = path.extname(filePath).toLowerCase()
    const supportedExtensions = ['.pdf', '.html', '.htm', '.txt', '.png', '.jpg', '.jpeg']
    if (!supportedExtensions.includes(ext)) {
      return { success: false, printer: printerName, error: `Unsupported file type: ${ext}` }
    }

    return new Promise((resolve) => {
      const printWindow = new BrowserWindow({
        show: false,
        webPreferences: { nodeIntegration: false, contextIsolation: true },
      })

      const cleanup = () => {
        try { printWindow.close() } catch {}
      }

      const timeout = setTimeout(() => {
        cleanup()
        resolve({ success: false, printer: printerName, error: 'Print timed out after 30s' })
      }, 30000)

      printWindow.webContents.on('did-finish-load', () => {
        const electronPrintOptions: Electron.WebContentsPrintOptions = {
          silent: options.silent !== false,
          printBackground: true,
          deviceName: printerName,
          copies: options.copies || 1,
        }

        if (options.duplex && options.duplex !== 'default') {
          electronPrintOptions.duplexMode = options.duplex === 'long-edge' ? 'longEdge' : 'shortEdge'
        }

        printWindow.webContents.print(electronPrintOptions, (success, failureReason) => {
          clearTimeout(timeout)
          cleanup()
          if (success) {
            console.log(`[PrinterService] Printed ${filePath} to ${printerName}`)
            resolve({ success: true, printer: printerName })
          } else {
            console.error(`[PrinterService] Print failed: ${failureReason}`)
            resolve({ success: false, printer: printerName, error: failureReason || 'Unknown print error' })
          }
        })
      })

      printWindow.webContents.on('did-fail-load', (_event, errorCode, errorDescription) => {
        clearTimeout(timeout)
        cleanup()
        resolve({ success: false, printer: printerName, error: `Failed to load file: ${errorDescription} (${errorCode})` })
      })

      const fileUrl = `file://${filePath.replace(/\\/g, '/')}`
      printWindow.loadURL(fileUrl).catch((err) => {
        clearTimeout(timeout)
        cleanup()
        resolve({ success: false, printer: printerName, error: `Failed to load: ${err.message}` })
      })
    })
  }

  /**
   * Print HTML content directly (useful for formatted reports, receipts, etc.)
   */
  async printHtml(htmlContent: string, printerName: string, options: PrintOptions = {}): Promise<PrintResult> {
    return new Promise((resolve) => {
      const printWindow = new BrowserWindow({
        show: false,
        webPreferences: { nodeIntegration: false, contextIsolation: true },
      })

      const cleanup = () => {
        try { printWindow.close() } catch {}
      }

      const timeout = setTimeout(() => {
        cleanup()
        resolve({ success: false, printer: printerName, error: 'Print timed out after 30s' })
      }, 30000)

      printWindow.webContents.on('did-finish-load', () => {
        const electronPrintOptions: Electron.WebContentsPrintOptions = {
          silent: options.silent !== false,
          printBackground: true,
          deviceName: printerName,
          copies: options.copies || 1,
        }

        if (options.duplex && options.duplex !== 'default') {
          electronPrintOptions.duplexMode = options.duplex === 'long-edge' ? 'longEdge' : 'shortEdge'
        }

        printWindow.webContents.print(electronPrintOptions, (success, failureReason) => {
          clearTimeout(timeout)
          cleanup()
          if (success) {
            resolve({ success: true, printer: printerName })
          } else {
            resolve({ success: false, printer: printerName, error: failureReason || 'Unknown print error' })
          }
        })
      })

      printWindow.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(htmlContent)}`).catch((err) => {
        clearTimeout(timeout)
        cleanup()
        resolve({ success: false, printer: printerName, error: `Failed to load HTML: ${err.message}` })
      })
    })
  }

  /**
   * Generate PDF from HTML content
   */
  async printToPdf(htmlContent: string): Promise<{ success: boolean; data?: Buffer; error?: string }> {
    return new Promise((resolve) => {
      const pdfWindow = new BrowserWindow({
        show: false,
        webPreferences: { nodeIntegration: false, contextIsolation: true },
      })

      const cleanup = () => {
        try { pdfWindow.close() } catch {}
      }

      const timeout = setTimeout(() => {
        cleanup()
        resolve({ success: false, error: 'PDF generation timed out after 30s' })
      }, 30000)

      pdfWindow.webContents.on('did-finish-load', async () => {
        try {
          const pdfData = await pdfWindow.webContents.printToPDF({
            printBackground: true,
          })
          clearTimeout(timeout)
          cleanup()
          resolve({ success: true, data: pdfData })
        } catch (err: any) {
          clearTimeout(timeout)
          cleanup()
          resolve({ success: false, error: err.message })
        }
      })

      pdfWindow.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(htmlContent)}`).catch((err) => {
        clearTimeout(timeout)
        cleanup()
        resolve({ success: false, error: `Failed to load HTML: ${err.message}` })
      })
    })
  }
}

let instance: PrinterService | null = null

export function getPrinterService(): PrinterService {
  if (!instance) {
    instance = new PrinterService()
  }
  return instance
}
