/**
 * Cross-platform active-window adapter built on the `get-windows` npm package.
 *
 * `get-windows` is an ESM-only package that exposes native bindings on
 * Windows / macOS / Linux. Using it eliminates per-poll PowerShell process
 * spawns (200-800 ms each) and per-poll AppleScript spawns. Same data, no
 * child process churn.
 *
 * Wave A.3 of drive-perf-fix-v2.
 *
 * Implementation notes:
 *   - Our main process is compiled to CommonJS, so we wrap the dynamic ESM
 *     import via `Function('return import(...)')()` to prevent TypeScript's
 *     CommonJS down-emit from rewriting it to `require()`.
 *   - The module is loaded lazily on first call and cached in module scope.
 *   - On platforms / environments where the binary is missing or fails to
 *     load (e.g. permissions denied on macOS Accessibility), we fall back
 *     to returning null silently. Callers must tolerate null.
 */

let cachedModule: { activeWindow: (opts?: any) => Promise<any> } | null = null
let loadPromise: Promise<typeof cachedModule> | null = null
let lastLoadError: string | null = null

async function loadGetWindows(): Promise<typeof cachedModule> {
  if (cachedModule) return cachedModule
  if (loadPromise) return loadPromise

  loadPromise = (async () => {
    try {
      // Defeat TS's CJS down-emit so this stays a real dynamic import().
      const dynamicImport: (specifier: string) => Promise<any> =
        new Function('s', 'return import(s)') as any
      const mod = await dynamicImport('get-windows')
      cachedModule = mod
      return mod
    } catch (err: any) {
      lastLoadError = err?.message || String(err)
      console.error('[activeWindow] Failed to load get-windows:', lastLoadError)
      return null
    } finally {
      loadPromise = null
    }
  })()

  return loadPromise
}

export interface ActiveWindowInfo {
  title: string
  processName: string
  bundleId?: string
  pid: number
  url?: string
}

/**
 * Get information about the currently focused window.
 *
 * Returns null when:
 *   - the platform is unsupported,
 *   - permissions are not granted (macOS Accessibility / Screen Recording),
 *   - the package failed to load,
 *   - or no window is focused.
 *
 * The `accessibilityPermission`/`screenRecordingPermission` options are passed
 * as `false` on macOS to keep this call non-blocking and silent — the host app
 * is responsible for prompting the user via the macOS parity work.
 */
export async function getActiveWindow(opts: {
  failSilently?: boolean
} = {}): Promise<ActiveWindowInfo | null> {
  const mod = await loadGetWindows()
  if (!mod || typeof mod.activeWindow !== 'function') return null

  try {
    const win = await mod.activeWindow({
      // Don't block on permission prompts; caller decides when to ask.
      accessibilityPermission: false,
      screenRecordingPermission: false,
    })
    if (!win) return null

    return {
      title: typeof win.title === 'string' ? win.title : '',
      processName: typeof win.owner?.name === 'string' ? win.owner.name : '',
      bundleId: typeof win.owner?.bundleId === 'string' ? win.owner.bundleId : undefined,
      pid: typeof win.owner?.processId === 'number' ? win.owner.processId : -1,
      url: typeof win.url === 'string' ? win.url : undefined,
    }
  } catch (err: any) {
    if (!opts.failSilently) {
      // Throttle log spam: only print once per minute.
      const now = Date.now()
      const w = (globalThis as any).__activeWindowLastErr || 0
      if (now - w > 60_000) {
        ;(globalThis as any).__activeWindowLastErr = now
        console.warn('[activeWindow] activeWindow() error:', err?.message || err)
      }
    }
    return null
  }
}

export function isAvailable(): boolean {
  return cachedModule !== null
}

export function getLastLoadError(): string | null {
  return lastLoadError
}
