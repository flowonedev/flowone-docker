/**
 * Frontend registry of "special" search operators (e.g. `mentions:me`,
 * `is:snoozed`) and their UI metadata + lazy loaders.
 *
 * Why this exists
 * ----------------
 * Smart Views can reference operators whose execution logic lives outside
 * the IMAP query path (mentions live in the DB, snooze lives in another
 * table, etc.). Each such operator may need its own:
 *   - icon, color, label, default name (for the built-in views and the
 *     "Smart View built from this query" UI)
 *   - lazy-loaded fetcher (so we don't bundle the mentions code with the
 *     mailbox view if the user has never used a mentions-backed view)
 *
 * Three classes of operator:
 *   1. eager   — registered at module load (built-in views)
 *   2. lazy    — registered with `() => import('…')` and only resolved
 *                when actually used. The promise is cached forever.
 *   3. unknown — silently ignored (no handler). The view still executes
 *                its IMAP-side operators; the special operator becomes a
 *                no-op. This is the intended phase-2 behaviour for
 *                `mentions:me` until the Phase 3 PR lands.
 *
 * Single source of truth on the frontend for whether an operator is "special".
 */

const eagerHandlers = new Map() // key -> { meta, run(email, value, ctx) }
const lazyLoaders = new Map() // key -> { loader, meta? }
const resolvedLazy = new Map() // key -> Promise<Handler> (cached forever)

/**
 * Register a special-search handler immediately.
 * `meta` is UI metadata used by SmartViewModal / SmartViewsList.
 */
export function registerSpecialSearch(operator, { meta = {}, run = null } = {}) {
  const key = String(operator || '').toLowerCase()
  if (!key) return
  eagerHandlers.set(key, { meta, run })
}

/**
 * Register a special-search handler lazily. The loader runs the first time
 * the operator is referenced; the resolved promise is cached so subsequent
 * calls are free.
 *
 * Loader contract:
 *   () => import('./somewhere').then(m => ({ meta, run }))
 *
 * `meta` can also be passed up-front (third arg or second-arg object) so
 * the UI can render icons/labels for the Smart View BEFORE the lazy chunk
 * is actually loaded. Without this, a Mentions smart view would render
 * blank until the user clicks it for the first time.
 */
export function registerSpecialSearchLazy(operator, loaderOrOpts, maybeMeta = null) {
  const key = String(operator || '').toLowerCase()
  if (!key) return
  const loader = typeof loaderOrOpts === 'function'
    ? loaderOrOpts
    : (typeof loaderOrOpts?.loader === 'function' ? loaderOrOpts.loader : null)
  if (!loader) return
  const meta = (typeof loaderOrOpts === 'object' && loaderOrOpts?.meta)
    ? loaderOrOpts.meta
    : (maybeMeta || null)
  lazyLoaders.set(key, { loader, meta })
}

/**
 * True if any handler (eager OR lazy) is registered. Does NOT trigger the
 * lazy load — safe to call from render paths.
 */
export function hasSpecialSearch(operator) {
  const key = String(operator || '').toLowerCase()
  return eagerHandlers.has(key) || lazyLoaders.has(key)
}

/**
 * Look up the UI metadata for an operator. For lazy handlers we only know
 * the meta after resolution; until then we return whatever was passed to
 * `registerSpecialSearchLazy({ meta })` (if any).
 */
export function getSpecialSearchMeta(operator) {
  const key = String(operator || '').toLowerCase()
  return eagerHandlers.get(key)?.meta ?? lazyLoaders.get(key)?.meta ?? null
}

/**
 * Resolve the handler — triggers the lazy loader if needed. Returns null
 * when no handler is registered.
 */
export function resolveSpecialSearch(operator) {
  const key = String(operator || '').toLowerCase()
  if (eagerHandlers.has(key)) {
    return Promise.resolve(eagerHandlers.get(key))
  }
  if (lazyLoaders.has(key)) {
    if (resolvedLazy.has(key)) return resolvedLazy.get(key)
    const { loader, meta: preMeta } = lazyLoaders.get(key)
    const p = loader()
      .then((mod) => {
        const handler = mod?.default ?? mod
        // Promote to eager so subsequent reads skip the cache miss; merge
        // any pre-registered meta with the meta returned by the loader.
        const merged = { ...handler, meta: { ...(preMeta || {}), ...(handler?.meta || {}) } }
        eagerHandlers.set(key, merged)
        return merged
      })
      .catch((err) => {
        console.error(`[specialSearchRegistry] lazy load failed for "${key}":`, err)
        resolvedLazy.delete(key) // allow retry
        return null
      })
    resolvedLazy.set(key, p)
    return p
  }
  return Promise.resolve(null)
}

/**
 * For diagnostics. Returns the set of currently-known operator keys (both
 * eager and lazy, without forcing resolution).
 */
export function listSpecialSearchOperators() {
  return Array.from(new Set([...eagerHandlers.keys(), ...lazyLoaders.keys()]))
}

// ─────────────────────────────────────────────────────────────────────────
// Default registrations.
//
// `snoozed` is still a stub until the snooze PR ships.
// ─────────────────────────────────────────────────────────────────────────

registerSpecialSearch('snoozed', {
  meta: {
    icon: 'snooze',
    color: 'amber',
    defaultName: 'Snoozed',
    helpText: 'Messages you snoozed for later.',
  },
  run: null, // Wired up when snooze ships.
})
