import chokidar, { FSWatcher } from 'chokidar'
import path from 'path'
import fs from 'fs'
import { SyncEngine } from './syncEngine'
import { DocumentTimeTracker } from './documentTimeTracker'
import { EditingDetector } from './editingDetector'
import { FsEventQueue, type FsEvent, type FsEventType } from './sync/FsEventQueue'

export class FileWatcher {
  private watcher: FSWatcher | null = null
  private lockFileWatcher: FSWatcher | null = null
  private syncFolder: string
  private syncEngine: SyncEngine
  private timeTracker: DocumentTimeTracker | null = null
  private editingDetector: EditingDetector | null = null

  // Wave A.2 — chokidar event backpressure: dedupe, debounce, burst-mode
  private eventQueue: FsEventQueue = new FsEventQueue({
    debounceMs: 250,
    hardFlushMs: 5_000,
    burstThreshold: 100,
    burstWindowMs: 1_000,
    burstDebounceMs: 5_000,
    burstClearMs: 5_000,
  })

  // Patterns for lock files (indicate someone is editing)
  // Microsoft Office: ~$filename.docx
  // LibreOffice: .~lock.filename.odt#
  // AutoCAD: filename.dwl, filename.dwl2
  private lockFilePatterns = {
    office: /^~\$/,                    // ~$filename.docx
    libreoffice: /^\.~lock\..+#$/,     // .~lock.filename.odt#
    autocad: /\.(dwl2?)$/i,            // filename.dwl, filename.dwl2
  }
  
  // Patterns to ignore for SYNCING (but NOT for editing detection)
  private ignorePatterns = [
    /(^|[\/\\])\../, // Hidden files/folders
    /node_modules/,
    /\.tmp$/i,
    /\.temp$/i,
    /~$/,
    /\.swp$/,
    /\.lock$/,
    /desktop\.ini$/i,
    /thumbs\.db$/i,
    /\.DS_Store$/,
    // Microsoft Office temp/lock files - ignore for sync but track for editing
    /~\$/, // ~$filename.docx
    /^\~\$/, // ~$filename at start
    /\.~/, // .~lock files
    // AutoCAD lock files
    /\.dwl2?$/i,
    // Other temp patterns
    /\.bak$/i,
    /\.backup$/i,
    /\.old$/i,
    /\.orig$/i,
    /\.part$/i,
    /\.crdownload$/i, // Chrome partial downloads
  ]

  constructor(
    syncFolder: string, 
    syncEngine: SyncEngine, 
    timeTracker?: DocumentTimeTracker | null,
    editingDetector?: EditingDetector | null
  ) {
    this.syncFolder = syncFolder
    this.syncEngine = syncEngine
    this.timeTracker = timeTracker || null
    this.editingDetector = editingDetector || null
  }

  start(): void {
    console.log(`Starting file watcher on: ${this.syncFolder}`)

    // Wave A.2: queue feeds events to the consumer in deduped batches.
    this.eventQueue.setConsumer((batch) => this.processBatch(batch))
    this.eventQueue.on('burst-start', (count) => {
      console.warn(`[FsEventQueue] BURST START — ${count} events in burst window; debounce raised`)
      this.syncEngine?.emit?.('fs-burst-start', count)
    })
    this.eventQueue.on('burst-end', () => {
      console.log('[FsEventQueue] burst end — debounce restored')
      this.syncEngine?.emit?.('fs-burst-end')
    })

    // Main watcher for file syncing (ignores temp/lock files)
    this.watcher = chokidar.watch(this.syncFolder, {
      ignored: this.ignorePatterns,
      persistent: true,
      ignoreInitial: true, // Don't trigger for existing files
      awaitWriteFinish: {
        stabilityThreshold: 200, // Faster - detect write complete in 200ms
        pollInterval: 50,
      },
      depth: 99, // Watch all subdirectories
      usePolling: false, // Use native events for speed
    })

    this.watcher
      .on('add', (filePath) => this.handleEvent('add', filePath))
      .on('change', (filePath) => this.handleEvent('change', filePath))
      .on('unlink', (filePath) => this.handleEvent('unlink', filePath))
      .on('addDir', (dirPath) => this.handleEvent('addDir', dirPath))
      .on('unlinkDir', (dirPath) => this.handleEvent('unlinkDir', dirPath))
      .on('error', (error) => console.error('Watcher error:', error))
      .on('ready', () => console.log('File watcher ready'))
    
    // Wave A.4: lockFileWatcher tuning.
    //
    // Previously this watcher ran with `usePolling: true, interval: 500,
    // depth: 99` AND no ignored filter. With ~5000 files in the sync folder
    // chokidar would `fs.stat` every single one twice a second — a steady
    // ~20k stat calls per minute on the main thread. That's the dominant
    // contributor to event-loop lag during idle.
    //
    // Fixes:
    //   - Tight `ignored` matcher: only the few file patterns that act as
    //     editing locks are observed. Everything else is rejected at the
    //     filesystem-traversal level so chokidar doesn't even stat them.
    //   - `usePolling: false` on local disks (native events catch lock-file
    //     create/unlink fine on local NTFS / APFS / ext4).
    //   - Polling fallback for UNC / network paths only, throttled to
    //     `interval: 2000`. Network-mounted folders don't reliably deliver
    //     change notifications, so polling is required there — but at 2 s
    //     instead of 500 ms it's 4× cheaper.
    const isNetworkPath = this.detectNetworkPath(this.syncFolder)
    const lockIgnoreMatcher = (p: string, stats?: fs.Stats): boolean => {
      // Always allow the root sync folder itself.
      if (p === this.syncFolder) return false
      const base = path.basename(p)
      // Allow directories so chokidar can descend into them.
      if (stats?.isDirectory()) return false
      // Lock-file patterns we actually care about.
      if (base.startsWith('~$')) return false                  // MS Office
      if (base.startsWith('.~lock')) return false              // LibreOffice
      if (/\.dwl2?$/i.test(base)) return false                 // AutoCAD
      // Reject everything else — chokidar will not stat or emit events for it.
      return true
    }

    this.lockFileWatcher = chokidar.watch(this.syncFolder, {
      ignored: lockIgnoreMatcher as any,
      persistent: true,
      ignoreInitial: false, // Detect existing lock files on startup
      depth: 99,
      usePolling: isNetworkPath,
      interval: isNetworkPath ? 2000 : 500,
      binaryInterval: isNetworkPath ? 2000 : 500,
      awaitWriteFinish: false,
      atomic: false,
      ignorePermissionErrors: true,
    })

    if (isNetworkPath) {
      // Wave C.4: route noisy lock watcher debug through sampled logger.
      const { logger } = require('./log/Logger')
      logger.tagged('LockWatcher').info('Network path detected, using polling at 2 s interval')
    } else {
      const { logger } = require('./log/Logger')
      logger.tagged('LockWatcher').info('Local path, using native events (no polling)')
    }

    this.lockFileWatcher
      .on('add', (filePath) => {
        const filename = path.basename(filePath)
        const lockType = this.detectLockFileType(filename)
        if (lockType) {
          // Wave C.4: sampled debug — bursty when lots of files open.
          const { logger } = require('./log/Logger')
          logger.tagged('LockWatcher').debug(`ADD ${lockType} lock file: ${filename}`)
          this.handleLockFile('opened', filePath, lockType)
        }
      })
      .on('unlink', (filePath) => {
        const filename = path.basename(filePath)
        const lockType = this.detectLockFileType(filename)
        if (lockType) {
          const { logger } = require('./log/Logger')
          logger.tagged('LockWatcher').debug(`UNLINK ${lockType} lock file: ${filename}`)
          this.handleLockFile('closed', filePath, lockType)
        }
      })
      .on('error', (error) => console.error('Lock file watcher error:', error))
      .on('ready', () => {
        console.log('Lock file watcher ready - watching for Office/LibreOffice/AutoCAD lock files')
      })
  }

  stop(): void {
    if (this.watcher) {
      this.watcher.close()
      this.watcher = null
    }
    
    if (this.lockFileWatcher) {
      this.lockFileWatcher.close()
      this.lockFileWatcher = null
    }

    // Wave A.2: drain whatever's left in the queue then tear it down.
    this.eventQueue.flushNow().catch(err =>
      console.error('[FileWatcher] Error flushing event queue on stop:', err)
    )
    this.eventQueue.destroy()
  }

  /**
   * Counters for the perf HUD / metrics.
   */
  getEventQueueCounters() {
    return this.eventQueue.getCounters()
  }

  /**
   * Wave A.4: detect whether a path is on a network filesystem so chokidar can
   * pick the right watching strategy.
   *
   * Detection heuristic:
   *   - Windows: UNC paths (`\\server\share\...`) and DFS namespaces.
   *   - macOS: paths under `/Volumes/<name>` that aren't the boot volume.
   *   - Linux: paths under `/mnt`, `/media`, or `/net`.
   *
   * The heuristic is intentionally conservative — false positives are cheap
   * (extra polling, lower CPU than the previous default of always polling),
   * and false negatives only matter for users running DFS namespaces from a
   * mapped drive letter; they can override via env `FLOWONE_DRIVE_FORCE_POLL=1`.
   */
  private detectNetworkPath(p: string): boolean {
    if (process.env.FLOWONE_DRIVE_FORCE_POLL === '1') return true
    if (process.env.FLOWONE_DRIVE_FORCE_POLL === '0') return false
    if (!p) return false
    if (process.platform === 'win32') {
      return p.startsWith('\\\\') || p.startsWith('//')
    }
    if (process.platform === 'darwin') {
      // /Volumes/Macintosh HD is local; everything else under /Volumes is mounted.
      return p.startsWith('/Volumes/') && !/^\/Volumes\/Macintosh HD\b/.test(p)
    }
    return p.startsWith('/mnt/') || p.startsWith('/media/') || p.startsWith('/net/')
  }

  /**
   * Detect the type of lock file
   * Returns: 'office' | 'libreoffice' | 'autocad' | null
   */
  private detectLockFileType(filename: string): 'office' | 'libreoffice' | 'autocad' | null {
    if (this.lockFilePatterns.office.test(filename)) return 'office'
    if (this.lockFilePatterns.libreoffice.test(filename)) return 'libreoffice'
    if (this.lockFilePatterns.autocad.test(filename)) return 'autocad'
    return null
  }

  /**
   * Handle lock file events to detect editing status
   * Supports:
   * - Microsoft Office: ~$filename.docx (strips first 2 chars for long filenames)
   * - LibreOffice: .~lock.filename.odt#
   * - AutoCAD: filename.dwl, filename.dwl2
   */
  private handleLockFile(
    action: 'opened' | 'closed', 
    lockFilePath: string,
    lockType: 'office' | 'libreoffice' | 'autocad'
  ): void {
    const lockFilename = path.basename(lockFilePath)
    const directory = path.dirname(lockFilePath)
    
    console.log(`[LOCK FILE] ========== ${action.toUpperCase()} (${lockType}) ==========`)
    console.log(`[LOCK FILE] Lock file: ${lockFilename}`)
    console.log(`[LOCK FILE] Directory: ${directory}`)
    
    // Determine the real filename based on lock type
    let realFilename = this.resolveRealFilename(lockFilename, directory, lockType)
    
    const relativePath = path.relative(this.syncFolder, directory)
    const realFilePath = path.join(directory, realFilename)
    
    console.log(`[LOCK FILE] Final file: ${realFilename}`)
    console.log(`[LOCK FILE] Full path: ${realFilePath}`)
    console.log(`[LOCK FILE] Relative path: ${relativePath || '(root)'}`)
    
    // Determine process name based on lock type
    const processName = this.getProcessNameForLockType(lockType)
    
    // Route to editing detector if available (preferred - handles deduplication)
    if (this.editingDetector) {
      if (action === 'opened') {
        this.editingDetector.onLockFileDetected(realFilePath, processName)
      } else {
        this.editingDetector.onLockFileRemoved(realFilePath)
      }
      return
    }
    
    // Fallback: Direct time tracker integration (legacy behavior)
    if (action === 'opened') {
      this.syncEngine.setFileEditingStatus(realFilename, relativePath || null, true)
      
      if (this.timeTracker) {
        const folderId = this.syncEngine.getFolderIdForPath(relativePath)
        
        console.log(`[TIME TRACKING] ========== DOCUMENT OPENED ==========`)
        console.log(`[TIME TRACKING] File: ${realFilename}`)
        console.log(`[TIME TRACKING] Folder ID: ${folderId !== null ? folderId : 'NOT FOUND'}`)
        console.log(`[TIME TRACKING] =====================================`)
        
        this.timeTracker.documentOpened(realFilePath, realFilename, folderId)
      }
    } else {
      this.syncEngine.setFileEditingStatus(realFilename, relativePath || null, false)
      
      if (this.timeTracker) {
        console.log(`[TIME TRACKING] ========== DOCUMENT CLOSED ==========`)
        console.log(`[TIME TRACKING] File: ${realFilename}`)
        console.log(`[TIME TRACKING] =====================================`)
        
        this.timeTracker.documentClosed(realFilePath)
      }
    }
  }

  /**
   * Resolve the actual filename from a lock file
   */
  private resolveRealFilename(
    lockFilename: string, 
    directory: string, 
    lockType: 'office' | 'libreoffice' | 'autocad'
  ): string {
    try {
      const filesInDir = fs.readdirSync(directory)
      
      switch (lockType) {
        case 'office': {
          // Office: ~$filename.docx -> filename.docx
          // For long filenames, Office strips first 2 chars: ~$0LP_2026.docx -> 140LP_2026.docx
          const lockPart = lockFilename.replace(/^~\$/, '')
          
          const matchingFile = filesInDir.find(f => {
            if (f.startsWith('~$') || f.startsWith('.~lock')) return false
            return f.endsWith(lockPart) || 
                   f.slice(2) === lockPart || 
                   f === lockPart
          })
          
          return matchingFile || lockPart
        }
        
        case 'libreoffice': {
          // LibreOffice: .~lock.filename.odt# -> filename.odt
          const match = lockFilename.match(/^\.~lock\.(.+)#$/)
          if (match) {
            return match[1]
          }
          return lockFilename
        }
        
        case 'autocad': {
          // AutoCAD: filename.dwl or filename.dwl2 -> filename.dwg
          const baseName = lockFilename.replace(/\.dwl2?$/i, '')
          
          // Look for corresponding .dwg file
          const dwgFile = filesInDir.find(f => 
            f.toLowerCase() === `${baseName.toLowerCase()}.dwg`
          )
          
          return dwgFile || `${baseName}.dwg`
        }
        
        default:
          return lockFilename
      }
    } catch (err) {
      console.error(`[LOCK FILE] Error resolving filename:`, err)
      return lockFilename
    }
  }

  /**
   * Get process name for a lock type (for display purposes)
   */
  private getProcessNameForLockType(lockType: 'office' | 'libreoffice' | 'autocad'): string {
    switch (lockType) {
      case 'office': return 'Microsoft Office'
      case 'libreoffice': return 'LibreOffice'
      case 'autocad': return 'AutoCAD'
      default: return 'Unknown'
    }
  }

  private handleEvent(eventType: FsEventType, itemPath: string): void {
    // Skip if path should be ignored
    if (this.shouldIgnore(itemPath)) {
      return
    }

    // Wave D.4: skip events caused by the engine's own writes/deletes
    // (downloads, folder creation, mirrored remote deletions). Without this,
    // every sync cycle that wrote a file immediately re-triggered the next
    // cycle, keeping the engine permanently in "Syncing...".
    if (this.syncEngine.isSelfWrite(itemPath)) {
      return
    }

    // Wave A.2: push into the event queue. The queue dedupes by path, debounces,
    // and applies burst-mode throttling before invoking processBatch().
    this.eventQueue.push(eventType, itemPath)
  }

  private shouldIgnore(itemPath: string): boolean {
    const relativePath = path.relative(this.syncFolder, itemPath)
    return this.ignorePatterns.some(pattern => pattern.test(relativePath))
  }

  /**
   * Wave A.2: consumer for the FsEventQueue. Receives a deduped batch of events
   * and processes them sequentially. After all per-event work finishes, asks the
   * scheduler for a single coalesced sync cycle.
   */
  private async processBatch(batch: FsEvent[]): Promise<void> {
    if (batch.length === 0) return
    console.log(`[FsEventQueue] processing batch of ${batch.length} event${batch.length === 1 ? '' : 's'}`)

    for (const evt of batch) {
      try {
        await this.processEvent(evt.type, evt.path)
      } catch (error) {
        console.error(`Error processing ${evt.type} for ${evt.path}:`, error)
      }
    }

    // One sync cycle per batch (debounced through SyncScheduler).
    if (typeof (this.syncEngine as any).requestSync === 'function') {
      ;(this.syncEngine as any).requestSync('fs-event-batch')
    }
  }

  private async processEvent(eventType: FsEventType, itemPath: string): Promise<void> {
    const filename = path.basename(itemPath)
    // Wave C.4: sampled — high cardinality during bulk paste / git checkout.
    const { logger } = require('./log/Logger')
    logger.tagged('InstantSync').debug(`${eventType}: ${filename}`)

    switch (eventType) {
      case 'add':
      case 'change':
      case 'unlink':
        // Per-event handling (DB row updates, NAS direct ops). Network sync is
        // coalesced by the SyncScheduler at the end of the batch.
        await this.syncEngine.handleLocalChange(eventType, itemPath)
        break

      case 'addDir':
        await this.syncEngine.handleLocalFolderChange('add', itemPath)
        break

      case 'unlinkDir':
        await this.syncEngine.handleLocalFolderChange('unlink', itemPath)
        break
    }
  }

  // Get current watch paths (for debugging)
  getWatched(): Record<string, string[]> {
    return this.watcher?.getWatched() || {}
  }
}
