import { metrics } from './metrics'

/**
 * Hot-path timing helper — wraps a sync or async function and records its
 * runtime into a histogram named after `label`.
 *
 * Metrics M.3 of drive-perf-fix-v2.
 *
 * Usage:
 *   const result = await time('sync.cycle', () => syncEngine.runSyncCycle('reason'))
 *   ipcMain.handle('get-files', timed('ipc.get-files', async (_evt, folderId) => {
 *     return database.getFiles(folderId)
 *   }))
 */

export function time<T>(label: string, fn: () => T | Promise<T>): T | Promise<T> {
  const start = performance.now()
  let result: T | Promise<T>
  try {
    result = fn()
  } catch (err) {
    metrics.histogram(label).observe(performance.now() - start)
    metrics.counter(`${label}.errors`).inc()
    throw err
  }

  if (result && typeof (result as any).then === 'function') {
    return (result as Promise<T>).then(
      (v) => {
        metrics.histogram(label).observe(performance.now() - start)
        return v
      },
      (err) => {
        metrics.histogram(label).observe(performance.now() - start)
        metrics.counter(`${label}.errors`).inc()
        throw err
      }
    )
  }

  metrics.histogram(label).observe(performance.now() - start)
  return result
}

/**
 * Decorator-style wrapper. Useful for IPC handlers:
 *
 *   ipcMain.handle('get-files', timed('ipc.get-files', async (_evt, folderId) => {
 *     return database.getFiles(folderId)
 *   }))
 */
export function timed<A extends any[], R>(
  label: string,
  fn: (...args: A) => R | Promise<R>
): (...args: A) => R | Promise<R> {
  return (...args: A) => time(label, () => fn(...args)) as R | Promise<R>
}
