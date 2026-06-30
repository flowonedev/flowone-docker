import { toRaw } from 'vue'

/**
 * Single-pass deep clone for JSON-ish data.
 *
 * Faster than JSON.parse(JSON.stringify()) (no string round-trip) and, unlike
 * structuredClone, safe on Vue reactive proxies (which throw DataCloneError).
 * Functions and undefined values are dropped, matching JSON semantics.
 */
export function deepClone(value, seen = new WeakMap()) {
  if (value === null || typeof value !== 'object') {
    return typeof value === 'function' ? undefined : value
  }
  const raw = toRaw(value)
  const cached = seen.get(raw)
  if (cached) return cached

  if (Array.isArray(raw)) {
    const out = new Array(raw.length)
    seen.set(raw, out)
    for (let i = 0; i < raw.length; i++) {
      const v = deepClone(raw[i], seen)
      out[i] = v === undefined ? null : v
    }
    return out
  }
  if (raw instanceof Date) return new Date(raw.getTime())

  const out = {}
  seen.set(raw, out)
  for (const key of Object.keys(raw)) {
    const v = deepClone(raw[key], seen)
    if (v !== undefined) out[key] = v
  }
  return out
}
