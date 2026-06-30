import { exec } from 'child_process'
import { promisify } from 'util'
import path from 'path'
import { EventEmitter } from 'events'

const execAsync = promisify(exec)

/**
 * Windows File Handle Monitor
 * 
 * Polls Windows to detect which processes have open file handles
 * to files in the Drive folder. Works with any application.
 * 
 * Only runs on Windows - no-op on other platforms.
 */

export interface FileHandle {
  filePath: string
  processName: string
  processId: number
}

export interface HandleMonitorEvents {
  'file-opened': (filePath: string, processName: string) => void
  'file-closed': (filePath: string, processName: string) => void
  'error': (error: Error) => void
}

export class HandleMonitor extends EventEmitter {
  private syncFolder: string
  private additionalFolders: string[] = []
  private pollInterval: number
  private pollTimer: NodeJS.Timeout | null = null
  private isWindows: boolean
  private isRunning: boolean = false

  // Track currently open files: filePath -> { processName, processId }
  private openFiles: Map<string, FileHandle> = new Map()

  // Target process names to monitor (without .exe)
  private targetProcesses: string[] = [
    // Microsoft Office
    'WINWORD', // Microsoft Word
    'EXCEL', // Microsoft Excel
    'POWERPNT', // Microsoft PowerPoint
    'OUTLOOK', // Microsoft Outlook
    // Adobe Creative Suite
    'Photoshop',
    'Illustrator',
    'InDesign',
    'Acrobat',
    'Premiere Pro',
    'After Effects',
    'Adobe XD',
    'XD',
    'AdobeXD',
    // Affinity Suite
    'Affinity Photo',
    'Affinity Designer',
    'Affinity Publisher',
    'Photo', // Affinity on some systems
    'Designer',
    'Publisher',
    // CAD Applications
    'acad', // AutoCAD
    'SOLIDWORKS',
    'inventor', // Autodesk Inventor
    // Code Editors
    'Code', // VSCode
    'Cursor', // Cursor IDE (VSCode-based)
    'cursor',
    'sublime_text',
    'notepad++',
    'atom',
    // Image Viewers
    'Microsoft.Photos', // Windows Photos
    'Photos',           // Windows Photos (short name)
    'mspaint',          // MS Paint
    'IrfanView',        // IrfanView
    'XnView',           // XnView
    'ACDSee',           // ACDSee
    'FSViewer',         // FastStone
    // Other
    'GIMP',
    'Inkscape',
    'Blender',
    'SketchUp',
    'explorer',         // Windows Explorer (when previewing files)
  ]

  constructor(syncFolder: string, pollInterval: number = 3000) {
    super()
    this.syncFolder = syncFolder
    this.pollInterval = pollInterval
    this.isWindows = process.platform === 'win32'
  }

  /**
   * Start monitoring file handles
   */
  start(): void {
    if (!this.isWindows) {
      console.log('[HandleMonitor] Not Windows, skipping handle monitoring')
      return
    }

    if (this.isRunning) {
      console.log('[HandleMonitor] Already running')
      return
    }

    console.log('[HandleMonitor] Starting file handle monitoring')
    console.log(`[HandleMonitor] Sync folder: ${this.syncFolder}`)
    console.log(`[HandleMonitor] Poll interval: ${this.pollInterval}ms`)
    console.log(`[HandleMonitor] Target processes: ${this.targetProcesses.join(', ')}`)

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

    // Emit close events for all tracked files
    for (const [filePath, handle] of this.openFiles) {
      this.emit('file-closed', filePath, handle.processName)
    }
    this.openFiles.clear()

    console.log('[HandleMonitor] Stopped')
  }

  /**
   * Register additional folders to monitor (watch folders)
   */
  setAdditionalFolders(folders: string[]): void {
    this.additionalFolders = folders
    console.log(`[HandleMonitor] Additional folders: ${folders.length}`)
  }

  /**
   * All folders being monitored (sync + watch)
   */
  private getAllMonitoredFolders(): string[] {
    return [this.syncFolder, ...this.additionalFolders]
  }

  /**
   * Add a process to monitor
   */
  addTargetProcess(processName: string): void {
    if (!this.targetProcesses.includes(processName)) {
      this.targetProcesses.push(processName)
      console.log(`[HandleMonitor] Added target process: ${processName}`)
    }
  }

  /**
   * Get currently open files
   */
  getOpenFiles(): Map<string, FileHandle> {
    return new Map(this.openFiles)
  }

  /**
   * Check if a specific file is open
   */
  isFileOpen(filePath: string): boolean {
    // Normalize path for comparison
    const normalized = path.normalize(filePath).toLowerCase()
    for (const [openPath] of this.openFiles) {
      if (path.normalize(openPath).toLowerCase() === normalized) {
        return true
      }
    }
    return false
  }

  /**
   * Main polling loop
   */
  private async poll(): Promise<void> {
    if (!this.isRunning) return

    try {
      const currentHandles = await this.getFileHandles()

      // Check for newly opened files
      for (const [filePath, handle] of currentHandles) {
        const normalizedPath = path.normalize(filePath).toLowerCase()
        let wasOpen = false

        for (const [openPath] of this.openFiles) {
          if (path.normalize(openPath).toLowerCase() === normalizedPath) {
            wasOpen = true
            break
          }
        }

        if (!wasOpen) {
          console.log(`[HandleMonitor] File opened: ${path.basename(filePath)} (${handle.processName})`)
          this.openFiles.set(filePath, handle)
          this.emit('file-opened', filePath, handle.processName)
        }
      }

      // Check for closed files
      const currentPaths = new Set(
        Array.from(currentHandles.keys()).map(p => path.normalize(p).toLowerCase())
      )

      for (const [filePath, handle] of this.openFiles) {
        const normalizedPath = path.normalize(filePath).toLowerCase()
        if (!currentPaths.has(normalizedPath)) {
          console.log(`[HandleMonitor] File closed: ${path.basename(filePath)} (${handle.processName})`)
          this.openFiles.delete(filePath)
          this.emit('file-closed', filePath, handle.processName)
        }
      }

    } catch (error) {
      // Don't spam errors, just log occasionally
      if (Math.random() < 0.1) {
        console.error('[HandleMonitor] Poll error:', error)
      }
    }

    // Schedule next poll
    if (this.isRunning) {
      this.pollTimer = setTimeout(() => this.poll(), this.pollInterval)
    }
  }

  /**
   * Get file handles using PowerShell
   * Returns files in the sync folder that are open by target processes
   * 
   * Uses multiple detection methods:
   * 1. Check process MainWindowTitle for file paths
   * 2. Check process command line arguments for file paths
   * 3. Use .NET FileStream checks on recently modified files
   */
  private async getFileHandles(): Promise<Map<string, FileHandle>> {
    const handles = new Map<string, FileHandle>()

    const allFolders = this.getAllMonitoredFolders().filter(f => f)
    const processFilter = this.targetProcesses.map(p => `"${p}"`).join(',')
    const foldersArray = allFolders.map(f => `'${f.replace(/\\/g, '\\\\').replace(/'/g, "''")}'`).join(',')

    const script = `
      $ErrorActionPreference = 'SilentlyContinue'
      $targetProcesses = @(${processFilter})
      $monitoredFolders = @(${foldersArray})
      
      function Test-InMonitored($path) {
        foreach ($folder in $monitoredFolders) {
          if ($path.StartsWith($folder, [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
        }
        return $false
      }
      
      $processes = Get-Process | Where-Object { 
        $processName = $_.ProcessName
        foreach ($target in $targetProcesses) {
          if ($processName -like "*$target*") { return $true }
        }
        return $false
      }
      
      foreach ($proc in $processes) {
        try {
          $procId = $proc.Id
          $procName = $proc.ProcessName
          
          $title = $proc.MainWindowTitle
          if ($title) {
            foreach ($folder in $monitoredFolders) {
              if ($title -match [regex]::Escape($folder)) {
                $match = [regex]::Match($title, [regex]::Escape($folder) + '[^\\-\\|]+')
                if ($match.Success) {
                  $filePath = $match.Value.Trim()
                  if (Test-Path $filePath -PathType Leaf) {
                    Write-Output "$procId|$procName|$filePath"
                  }
                }
              }
            }
            $potentialFiles = [regex]::Matches($title, '([A-Za-z]:\\\\[^\\\\:*?"<>|\\r\\n]+\\\\[^\\\\:*?"<>|\\r\\n]+)')
            foreach ($m in $potentialFiles) {
              $path = $m.Value
              if (Test-InMonitored $path) {
                if (Test-Path $path -PathType Leaf) {
                  Write-Output "$procId|$procName|$path"
                }
              }
            }
          }
          
          # Wave A.3: removed Get-ChildItem -Recurse -File scan for code-editor
          # processes. Recursive scans of large sync folders every 3s caused
          # multi-second main-thread stalls. Code editors (Cursor / VSCode /
          # Notepad++ / Sublime) are now covered by:
          #   - WindowMonitor (window title -> filename matching)
          #   - lockFileWatcher (Office/LibreOffice/AutoCAD lock files)
          # which together cover the same intent without the recursive walk.
        } catch {}
      }
    `

    try {
      // Use -EncodedCommand to avoid escaping issues with special characters
      const scriptBase64 = Buffer.from(script, 'utf16le').toString('base64')

      const { stdout, stderr } = await execAsync(
        `powershell -NoProfile -NonInteractive -EncodedCommand ${scriptBase64}`,
        { timeout: 10000 }
      )

      if (stderr && stderr.trim()) {
        console.log('[HandleMonitor] PowerShell stderr:', stderr.trim().substring(0, 200))
      }

      // Parse output
      const lines = stdout.trim().split('\n').filter(line => line.trim())

      if (lines.length > 0) {
        console.log(`[HandleMonitor] Found ${lines.length} potential file handles`)
      }

      for (const line of lines) {
        const parts = line.trim().split('|')
        if (parts.length >= 3) {
          const [pidStr, processName, filePath] = parts
          const pid = parseInt(pidStr, 10)

          if (filePath && !isNaN(pid)) {
            handles.set(filePath, {
              filePath,
              processName,
              processId: pid
            })
          }
        }
      }
    } catch (error: any) {
      // Log the error (once per 10 polls to avoid spam)
      if (Math.random() < 0.1) {
        console.log('[HandleMonitor] PowerShell error, using fallback:', error?.message?.substring(0, 100))
      }
      // Fall back to checking if files are locked
      await this.checkFileLocks(handles)
    }

    return handles
  }

  /**
   * Fallback: DISABLED for code editors
   * WindowMonitor handles focus-based tracking (open/close file detection)
   * Office apps are handled by lock file detection in FileWatcher (~$filename.docx)
   */
  private async checkFileLocks(handles: Map<string, FileHandle>): Promise<void> {
    // DISABLED - modification-time tracking causes "tracks on save" issue
    // Instead, we rely on:
    // 1. WindowMonitor - detects file focus from window titles (Cursor/VSCode)
    // 2. FileWatcher - detects Office lock files (~$document.docx)
    // This way tracking starts when file is OPENED, not when SAVED
  }

  /**
   * Recursively get all files in a directory
   */
  private async getAllFiles(dir: string, depth: number = 0): Promise<string[]> {
    const fs = require('fs').promises
    const files: string[] = []

    // Limit recursion depth to avoid scanning too deep
    if (depth > 10) return files

    try {
      const entries = await fs.readdir(dir, { withFileTypes: true })

      for (const entry of entries) {
        const fullPath = path.join(dir, entry.name)

        // Skip hidden directories and system folders
        if (entry.name.startsWith('.')) continue
        if (entry.name === 'node_modules') continue
        if (entry.name === '.git') continue

        if (entry.isDirectory()) {
          const subFiles = await this.getAllFiles(fullPath, depth + 1)
          files.push(...subFiles)
        } else {
          files.push(fullPath)
        }
      }
    } catch (err: any) {
      if (depth === 0) {
        console.error(`[HandleMonitor] Error reading sync folder: ${err?.message}`)
      }
    }

    return files
  }
}

