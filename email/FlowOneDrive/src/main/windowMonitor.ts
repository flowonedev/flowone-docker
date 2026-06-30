import path from 'path'
import { EventEmitter } from 'events'
import { getActiveWindow as getActiveWindowNative } from './perf/activeWindow'

/**
 * Active Window Monitor
 * 
 * Monitors the currently focused window and checks if its title
 * contains a filename from the Drive folder. Works on Windows and macOS.
 * 
 * This is a supplementary detection method - less reliable than
 * lock files or handle monitoring, but works as a fallback.
 */

export interface WindowInfo {
  title: string
  processName: string
  filePath: string | null
}

export class WindowMonitor extends EventEmitter {
  private syncFolder: string
  private additionalFolders: string[] = []
  private pollInterval: number
  private pollTimer: NodeJS.Timeout | null = null
  private isRunning: boolean = false

  // Track the current window state
  private currentFile: string | null = null
  private currentProcess: string | null = null

  // Cache of files in all monitored folders for matching
  private syncFolderFiles: Map<string, string> = new Map() // basename -> full path
  private filesCacheTime: number = 0
  private readonly FILES_CACHE_TTL = 30000 // 30 seconds

  constructor(syncFolder: string, pollInterval: number = 2000) {
    super()
    this.syncFolder = syncFolder
    this.pollInterval = pollInterval
  }

  /**
   * Register additional folders to monitor (watch folders)
   */
  setAdditionalFolders(folders: string[]): void {
    this.additionalFolders = folders
    this.filesCacheTime = 0 // force cache rebuild
    console.log(`[WindowMonitor] Additional folders: ${folders.length}`)
  }

  private getAllMonitoredFolders(): string[] {
    return [this.syncFolder, ...this.additionalFolders]
  }

  /**
   * Start monitoring active window
   */
  async start(): Promise<void> {
    if (this.isRunning) {
      console.log('[WindowMonitor] Already running')
      return
    }

    console.log('[WindowMonitor] Starting active window monitoring')
    console.log(`[WindowMonitor] Sync folder: ${this.syncFolder}`)
    console.log(`[WindowMonitor] Poll interval: ${this.pollInterval}ms`)

    // Build file cache BEFORE starting polling
    await this.refreshFileCache()
    console.log(`[WindowMonitor] File cache built with ${this.syncFolderFiles.size} files`)
    if (this.syncFolderFiles.size > 0) {
      console.log(`[WindowMonitor] Sample files: ${Array.from(this.syncFolderFiles.keys()).slice(0, 5).join(', ')}`)
    }

    this.isRunning = true
    this.poll()
  }

  /**
   * Stop monitoring
   */
  stop(): void {
    if (this.pollTimer) {
      clearTimeout(this.pollTimer)
      this.pollTimer = null
    }
    this.isRunning = false

    // Emit close event for current file
    if (this.currentFile) {
      this.emit('file-unfocused', this.currentFile, this.currentProcess)
      this.currentFile = null
      this.currentProcess = null
    }

    console.log('[WindowMonitor] Stopped')
  }

  /**
   * Get currently focused file (if any)
   */
  getCurrentFile(): string | null {
    return this.currentFile
  }

  /**
   * Main polling loop
   */
  private async poll(): Promise<void> {
    if (!this.isRunning) return

    try {
      const windowInfo = await this.getActiveWindow()

      // DEBUG: Always log when Cursor/Code window is active
      if (windowInfo && /Cursor|Code/i.test(windowInfo.processName || windowInfo.title)) {
        console.log(`[WindowMonitor] ACTIVE: ${windowInfo.processName} - "${windowInfo.title.substring(0, 80)}"`)
      }

      const detectedFile = windowInfo ? this.matchFileInTitle(windowInfo.title) : null

      // Periodic debug for unmatched windows (1 in 20 polls)
      if (!detectedFile && windowInfo && windowInfo.title && !/electron|FlowOne/i.test(windowInfo.processName)) {
        if (Math.random() < 0.05) {
          console.log(`[WindowMonitor] UNMATCHED: "${windowInfo.processName}" - "${windowInfo.title.substring(0, 120)}"`)
        }
      }

      // Debug: Log when we're checking an editor but found nothing
      if (!detectedFile && windowInfo && /Cursor|Code|Visual Studio/i.test(windowInfo.title)) {
        // Only log occasionally to not spam
        if (Math.random() < 0.2) {
          console.log(`[WindowMonitor] Editor but no match. Title: "${windowInfo.title.substring(0, 100)}"`)
        }
      }

      // Check for state change
      if (detectedFile !== this.currentFile) {
        // Previous file is no longer focused
        if (this.currentFile) {
          console.log(`[WindowMonitor] File UNFOCUSED: ${path.basename(this.currentFile)}`)
          this.emit('file-unfocused', this.currentFile, this.currentProcess)
        }

        // New file is focused
        if (detectedFile) {
          console.log(`[WindowMonitor] File FOCUSED: ${path.basename(detectedFile)} (${windowInfo?.processName})`)
          this.emit('file-focused', detectedFile, windowInfo?.processName || 'Unknown')
        } else if (this.currentFile) {
          // Switched away from a tracked file to something else
          console.log(`[WindowMonitor] Switched away from sync folder files`)
        }

        this.currentFile = detectedFile
        this.currentProcess = windowInfo?.processName || null
      }

    } catch (error) {
      // Don't spam errors
      if (Math.random() < 0.1) {
        console.error('[WindowMonitor] Poll error:', error)
      }
    }

    // Schedule next poll
    if (this.isRunning) {
      this.pollTimer = setTimeout(() => this.poll(), this.pollInterval)
    }
  }

  /**
   * Get active window information.
   *
   * Wave A.3: replaced the per-poll PowerShell (Win) and osascript (mac) calls
   * with the `get-windows` native binding, which avoids spawning a child
   * process every 2 s. The previous implementations spawned 30-60 PS processes
   * per minute, each costing 200-800 ms — the single largest source of main
   * process CPU when the app was idle.
   */
  private async getActiveWindow(): Promise<WindowInfo | null> {
    if (process.platform !== 'win32' && process.platform !== 'darwin') {
      return null
    }
    const win = await getActiveWindowNative({ failSilently: true })
    if (!win) return null
    return {
      title: win.title,
      processName: win.processName,
      filePath: null,
    }
  }

  /**
   * Try to match a filename in the window title to a file in sync folder
   */
  private matchFileInTitle(title: string): string | null {
    if (!title) return null

    // Strip common editor prefixes (unsaved indicators, icons, etc.)
    // Cursor/VSCode use bullet points for unsaved files: "• filename" or "● filename"
    // These may appear as "?" or other characters in console output
    let cleanTitle = title.replace(/^[•●○◯?*\s]+/, '').trim()

    // Periodically refresh file cache in background (don't block)
    const now = Date.now()
    if (now - this.filesCacheTime > this.FILES_CACHE_TTL) {
      this.refreshFileCache().catch(() => { })
    }

    // Log title for debugging - ALWAYS log for Cursor/Code to help debug
    if (/Cursor|Code/i.test(title)) {
      console.log(`[WindowMonitor] Cursor window: "${title.substring(0, 100)}"`)
    }

    // FIRST: Check if the title contains any monitored folder path
    const titleLower = title.toLowerCase().replace(/\\/g, '/')

    for (const folder of this.getAllMonitoredFolders()) {
      if (!folder) continue
      const folderNormalized = folder.toLowerCase().replace(/\\/g, '/')
      if (titleLower.includes(folderNormalized)) {
        for (const [basename, fullPath] of this.syncFolderFiles) {
          if (title.includes(basename)) {
            console.log(`[WindowMonitor] Matched by full path in title: ${basename}`)
            return fullPath
          }
        }
      }
    }

    // SECOND: For apps that show "folder/filename" patterns
    const allFolderNames = this.getAllMonitoredFolders()
      .filter(f => f)
      .map(f => path.basename(f).toLowerCase())
      .filter((v, i, a) => a.indexOf(v) === i) // deduplicate

    for (const folderName of allFolderNames) {
      const escapedName = folderName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
      const patterns = [
        new RegExp(`${escapedName}[/\\\\]([^-\u2013\u2014]+?)\\s*[-\u2013\u2014]`, 'i'),
        new RegExp(`${escapedName}[/\\\\](.+?)\\s*@\\s*\\d+%`, 'i'),
      ]

      for (const pattern of patterns) {
        const match = title.match(pattern)
        if (match) {
          const potentialFile = path.basename(match[1].trim())
          const fullPath = this.findFileInSyncFolder(potentialFile)
          if (fullPath) {
            console.log(`[WindowMonitor] Matched by folder context: ${potentialFile}`)
            return fullPath
          }
        }
      }
    }

    // THIRD: For VSCode/Cursor - check "filename - folder - App" format
    const syncFolderName = path.basename(this.syncFolder).toLowerCase()
    // Format: "filename.ext - FolderName - Cursor" or "filename.ext - Cursor"
    // Use cleanTitle to handle unsaved indicators (• or ●)
    const vscodePattern = /^(.+?)\s+[-–—]\s+(.+?)\s+[-–—]\s+(Cursor|Code|Visual Studio Code)/i
    const vscodeMatch = cleanTitle.match(vscodePattern)
    if (vscodeMatch) {
      const filename = vscodeMatch[1].trim()
      const folderPart = vscodeMatch[2].trim()

      // Check if folderPart contains any monitored folder name
      const folderPartLower = folderPart.toLowerCase()
      const matchesAnyFolder = allFolderNames.some(fn => folderPartLower.includes(fn) || folderPartLower === fn)
      if (matchesAnyFolder) {
        const fullPath = this.findFileInSyncFolder(filename)
        if (fullPath) {
          console.log(`[WindowMonitor] Matched VSCode/Cursor title: ${filename} in ${folderPart}`)
          return fullPath
        }
      }

      // Also check if the folderPart matches any subfolder in our sync folder
      for (const [basename, fullPath] of this.syncFolderFiles) {
        if (basename === filename || basename.toLowerCase() === filename.toLowerCase()) {
          // Check if the folder part matches part of the path
          const relativePath = fullPath.replace(this.syncFolder, '').replace(/^[/\\]/, '')
          const parentFolder = path.dirname(relativePath)
          if (parentFolder && folderPart.includes(parentFolder)) {
            console.log(`[WindowMonitor] Matched VSCode/Cursor by subfolder: ${filename}`)
            return fullPath
          }
        }
      }
    }

    // FOURTH: Simple pattern for editors showing "filename - App" without folder
    // Only match if file exists ONLY ONCE in sync folder (avoid ambiguity)
    // Use cleanTitle to handle unsaved indicators
    const simplePattern = /^([^-–—]+?)\s*[-–—]\s*(Cursor|Code|Visual Studio Code|Notepad\+\+|Sublime)/i
    const simpleMatch = cleanTitle.match(simplePattern)
    if (simpleMatch) {
      const filename = simpleMatch[1].trim()
      const matchingFiles = Array.from(this.syncFolderFiles.entries())
        .filter(([basename]) => basename === filename || basename.toLowerCase() === filename.toLowerCase())

      if (matchingFiles.length === 1) {
        console.log(`[WindowMonitor] Matched unique file in sync folder: ${filename}`)
        return matchingFiles[0][1]
      }
    }

    // FIFTH: Last resort - check if ANY file from sync folder appears in the title
    // For Cursor/VSCode that might show complex titles
    // Use cleanTitle to handle unsaved indicators (• or ●)
    if (/Cursor|Code|Visual Studio/i.test(title)) {
      for (const [basename, fullPath] of this.syncFolderFiles) {
        // Only match if filename appears at the start of the title (most editors show filename first)
        if (cleanTitle.startsWith(basename) || cleanTitle.toLowerCase().startsWith(basename.toLowerCase())) {
          console.log(`[WindowMonitor] Matched file at title start: ${basename}`)
          return fullPath
        }
      }
    }

    // SIXTH: For image viewers - match any image file from sync folder
    if (/Photos|Photo|mspaint|IrfanView|XnView|Preview|ImageGlass/i.test(title)) {
      for (const [basename, fullPath] of this.syncFolderFiles) {
        const ext = path.extname(basename).toLowerCase()
        if (['.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp', '.svg', '.ico'].includes(ext)) {
          if (title.includes(basename)) {
            console.log(`[WindowMonitor] Matched image file: ${basename}`)
            return fullPath
          }
        }
      }
    }

    // SEVENTH: Adobe Creative Suite apps
    // Illustrator: "filename.ai @ 50% (RGB/Preview)"
    // Photoshop:   "filename.psd @ 100% (RGB/8)"
    // InDesign:    "filename.indd - Adobe InDesign"
    // XD (UWP):    "sablon \u2013- Adobe XD" (process: ApplicationFrameHost)
    // Premiere:    "filename.prproj - Adobe Premiere Pro"
    // After Effects: "filename.aep - Adobe After Effects"
    if (/Adobe|Illustrator|Photoshop|InDesign|Premiere|After Effects/i.test(title) ||
        /\.ai\s*@|\.psd\s*@|\.indd\s|\.xd\s|\.prproj\s|\.aep\s/i.test(title)) {

      const adobePatterns = [
        /^(.+?\.\w+)\s*@\s*\d+%/i,                          // "file.ai @ 50% ..."
        /^(.+?\.\w+)\s*[^\w\s].*?Adobe/i,                   // "file.indd - Adobe InDesign" (permissive separator)
        /^(.+?)\s+[^\w\s].*?Adobe\s*(XD|Illustrator|Photoshop|InDesign|Premiere|After Effects)/i,
        /^\*?(.+?\.\w+)\s*@/i,                              // "*file.ai @" (unsaved indicator)
      ]

      for (const pattern of adobePatterns) {
        const match = cleanTitle.match(pattern)
        if (match) {
          const extractedFilename = match[1].trim()
          const fullPath = this.findFileInSyncFolder(extractedFilename)
          if (fullPath) {
            console.log(`[WindowMonitor] Matched Adobe file: ${extractedFilename}`)
            return fullPath
          }

          const basenameOnly = path.basename(extractedFilename)
          if (basenameOnly !== extractedFilename) {
            const fullPath2 = this.findFileInSyncFolder(basenameOnly)
            if (fullPath2) {
              console.log(`[WindowMonitor] Matched Adobe file (basename): ${basenameOnly}`)
              return fullPath2
            }
          }
        }
      }
    }

    return null
  }

  /**
   * Refresh the cache of files in sync folder
   * IMPORTANT: Build new cache first, then swap - never leave cache empty!
   */
  private async refreshFileCache(): Promise<void> {
    const now = Date.now()
    if (now - this.filesCacheTime < this.FILES_CACHE_TTL) {
      return // Cache is still valid
    }

    try {
      const newCache = new Map<string, string>()

      for (const folder of this.getAllMonitoredFolders()) {
        if (!folder) continue
        const files = await this.getAllFiles(folder)
        for (const filePath of files) {
          const basename = path.basename(filePath)
          newCache.set(basename, filePath)
          const withoutExt = path.basename(filePath, path.extname(filePath))
          if (withoutExt !== basename) {
            newCache.set(withoutExt, filePath)
          }
        }
      }

      this.syncFolderFiles = newCache
      this.filesCacheTime = now

    } catch (error) {
      console.error('[WindowMonitor] Failed to refresh file cache:', error)
      // Keep old cache on error - don't clear it!
    }
  }

  /**
   * Find a file in the sync folder by name
   */
  private findFileInSyncFolder(filename: string): string | null {
    // Direct match
    if (this.syncFolderFiles.has(filename)) {
      return this.syncFolderFiles.get(filename) || null
    }

    // Try without extension
    const withoutExt = filename.replace(/\.[^.]+$/, '')
    if (this.syncFolderFiles.has(withoutExt)) {
      return this.syncFolderFiles.get(withoutExt) || null
    }

    // Case-insensitive search
    const lowerFilename = filename.toLowerCase()
    for (const [basename, fullPath] of this.syncFolderFiles) {
      if (basename.toLowerCase() === lowerFilename) {
        return fullPath
      }
    }

    return null
  }

  /**
   * Recursively get all files in a directory
   */
  private async getAllFiles(dir: string): Promise<string[]> {
    const fs = require('fs').promises
    const files: string[] = []

    try {
      const entries = await fs.readdir(dir, { withFileTypes: true })

      for (const entry of entries) {
        const fullPath = path.join(dir, entry.name)
        const ext = path.extname(entry.name).toLowerCase()

        // Skip hidden files/directories, temp files, log files, and system files
        if (entry.name.startsWith('.') ||
          entry.name.startsWith('~$') ||
          entry.name.endsWith('.tmp') ||
          entry.name.endsWith('.temp') ||
          ext === '.log' ||                              // Log files (Adobe creates these)
          ext === '.bak' ||                              // Backup files
          ext === '.swp' ||                              // Vim swap files
          ext === '.lock' ||                             // Lock files
          ext === '.db' ||                               // Database files (Thumbs.db)
          /-\d{4}-\d{2}-\d{2}\.log$/i.test(entry.name) || // Dated log files
          /^Recovery/i.test(entry.name) ||               // Recovery files
          /^Thumbs\.db$/i.test(entry.name) ||           // Windows thumbnail cache
          /^desktop\.ini$/i.test(entry.name)) {         // Windows folder settings
          continue
        }

        if (entry.isDirectory()) {
          const subFiles = await this.getAllFiles(fullPath)
          files.push(...subFiles)
        } else {
          files.push(fullPath)
        }
      }
    } catch {
      // Directory might not exist or be inaccessible
    }

    return files
  }
}

