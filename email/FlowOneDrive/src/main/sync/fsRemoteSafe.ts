import fs from 'fs'
import path from 'path'

/**
 * Bounded async filesystem helpers for paths that may live on a network share
 * (NAS, UNC, NFS). Wave A.6 of drive-perf-fix-v2.
 *
 * Why this exists: on Windows, a `fs.existsSync('\\\\dead-host\\share')` call
 * blocks the entire main thread for ~30 s before SMB times out. Today the sync
 * engine uses `fs.existsSync` / `fs.copyFileSync` against UNC paths in many
 * code paths — any of them can freeze the UI when the NAS goes away mid-sync.
 *
 * These wrappers race the operation against a configurable timeout (default
 * 3 s for "is it there?" checks, 60 s for copies). On timeout they reject
 * with a recognizable error so callers can surface a clean status message.
 *
 * Note: timing out a syscall doesn't actually cancel the underlying I/O — the
 * Node thread-pool worker stays busy until the OS gives up. We tolerate this:
 * the goal is to return control to the main JS thread, not to free the libuv
 * worker. With UV_THREADPOOL_SIZE=4 (default), at most 4 concurrent stalls
 * are possible before requests queue, and the 30 s SMB timeout will free
 * them eventually.
 */

const DEFAULT_EXISTS_TIMEOUT_MS = 3_000
const DEFAULT_COPY_TIMEOUT_MS = 60_000
const DEFAULT_MKDIR_TIMEOUT_MS = 10_000
const DEFAULT_STAT_TIMEOUT_MS = 3_000
const DEFAULT_UNLINK_TIMEOUT_MS = 5_000

export class RemoteFsTimeoutError extends Error {
  readonly path: string
  readonly op: string
  readonly timeoutMs: number
  constructor(op: string, p: string, timeoutMs: number) {
    super(`Remote-fs ${op} timed out after ${timeoutMs}ms: ${p}`)
    this.name = 'RemoteFsTimeoutError'
    this.op = op
    this.path = p
    this.timeoutMs = timeoutMs
  }
}

function withTimeout<T>(op: string, p: string, ms: number, work: Promise<T>): Promise<T> {
  let timer: NodeJS.Timeout | null = null
  const timeout = new Promise<T>((_, reject) => {
    timer = setTimeout(() => reject(new RemoteFsTimeoutError(op, p, ms)), ms)
  })
  return Promise.race([work, timeout]).finally(() => {
    if (timer) clearTimeout(timer)
  })
}

/** Returns true if the path is reachable, false on missing or timeout. */
export async function existsSafe(p: string, timeoutMs = DEFAULT_EXISTS_TIMEOUT_MS): Promise<boolean> {
  try {
    await withTimeout('access', p, timeoutMs, fs.promises.access(p))
    return true
  } catch (err: any) {
    if (err instanceof RemoteFsTimeoutError) {
      console.warn(`[fsRemoteSafe] existsSafe TIMEOUT after ${timeoutMs}ms for ${p}`)
      return false
    }
    return false
  }
}

/**
 * Like existsSafe, but distinguishes "missing" from "timeout" so callers can
 * fail fast instead of silently treating a stalled NAS as "file not there".
 */
export async function probeRemote(p: string, timeoutMs = DEFAULT_EXISTS_TIMEOUT_MS): Promise<{ exists: boolean; timedOut: boolean }> {
  try {
    await withTimeout('access', p, timeoutMs, fs.promises.access(p))
    return { exists: true, timedOut: false }
  } catch (err: any) {
    if (err instanceof RemoteFsTimeoutError) {
      return { exists: false, timedOut: true }
    }
    return { exists: false, timedOut: false }
  }
}

export async function statSafe(p: string, timeoutMs = DEFAULT_STAT_TIMEOUT_MS): Promise<fs.Stats | null> {
  try {
    return await withTimeout('stat', p, timeoutMs, fs.promises.stat(p))
  } catch (err: any) {
    if (err instanceof RemoteFsTimeoutError) {
      console.warn(`[fsRemoteSafe] statSafe TIMEOUT after ${timeoutMs}ms for ${p}`)
    }
    return null
  }
}

export async function copyFileSafe(src: string, dst: string, timeoutMs = DEFAULT_COPY_TIMEOUT_MS): Promise<void> {
  await withTimeout('copyFile', dst, timeoutMs, fs.promises.copyFile(src, dst))
}

export async function mkdirSafe(p: string, timeoutMs = DEFAULT_MKDIR_TIMEOUT_MS): Promise<void> {
  await withTimeout('mkdir', p, timeoutMs, fs.promises.mkdir(p, { recursive: true }))
}

export async function unlinkSafe(p: string, timeoutMs = DEFAULT_UNLINK_TIMEOUT_MS): Promise<void> {
  try {
    await withTimeout('unlink', p, timeoutMs, fs.promises.unlink(p))
  } catch (err: any) {
    // ENOENT is fine — caller wanted the file gone.
    if (err?.code === 'ENOENT') return
    throw err
  }
}

export async function renameSafe(from: string, to: string, timeoutMs = DEFAULT_COPY_TIMEOUT_MS): Promise<void> {
  await withTimeout('rename', to, timeoutMs, fs.promises.rename(from, to))
}

/**
 * Path-shape heuristic: is this path probably on a remote filesystem?
 * Same logic as the FileWatcher.detectNetworkPath check; duplicated here so
 * this module has no upstream dependencies.
 */
export function isLikelyRemotePath(p: string): boolean {
  if (process.env.FLOWONE_DRIVE_FORCE_POLL === '1') return true
  if (!p) return false
  if (process.platform === 'win32') return p.startsWith('\\\\') || p.startsWith('//')
  if (process.platform === 'darwin') return p.startsWith('/Volumes/') && !/^\/Volumes\/Macintosh HD\b/.test(p)
  return p.startsWith('/mnt/') || p.startsWith('/media/') || p.startsWith('/net/')
}

export const remoteFs = {
  existsSafe,
  probeRemote,
  statSafe,
  copyFileSafe,
  mkdirSafe,
  unlinkSafe,
  renameSafe,
  isLikelyRemotePath,
  RemoteFsTimeoutError,
}

// Helper for callers that already have an absolute path and just want a
// safe, sane timeout.
export async function ensureDirSafe(p: string, timeoutMs = DEFAULT_MKDIR_TIMEOUT_MS): Promise<void> {
  if (!path.isAbsolute(p)) {
    throw new Error(`ensureDirSafe requires an absolute path, got: ${p}`)
  }
  const probe = await probeRemote(p, Math.min(timeoutMs, DEFAULT_EXISTS_TIMEOUT_MS))
  if (probe.exists) return
  if (probe.timedOut) throw new RemoteFsTimeoutError('access', p, DEFAULT_EXISTS_TIMEOUT_MS)
  await mkdirSafe(p, timeoutMs)
}
