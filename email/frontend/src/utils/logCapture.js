const MAX_CONSOLE_ENTRIES = 80
const MAX_NETWORK_ENTRIES = 30
const MAX_ARG_LENGTH = 500
const MAX_RESPONSE_BODY_LENGTH = 2000

const consoleLogs = []
const networkLogs = []

const _origLog = console.log.bind(console)
const _origWarn = console.warn.bind(console)
const _origError = console.error.bind(console)

function serializeArg(arg) {
  if (arg === null) return 'null'
  if (arg === undefined) return 'undefined'
  if (typeof arg === 'string') return arg.slice(0, MAX_ARG_LENGTH)
  if (typeof arg === 'number' || typeof arg === 'boolean') return String(arg)
  if (arg instanceof Error) return `${arg.name}: ${arg.message}`
  try {
    const str = JSON.stringify(arg, (key, value) => {
      if (typeof value === 'string' && value.length > MAX_ARG_LENGTH) {
        return value.slice(0, MAX_ARG_LENGTH) + '...'
      }
      const sensitive = ['token', 'password', 'secret', 'authorization', 'cookie']
      if (typeof key === 'string' && sensitive.some(s => key.toLowerCase().includes(s))) {
        return '[REDACTED]'
      }
      return value
    })
    return (str || '').slice(0, MAX_ARG_LENGTH)
  } catch {
    return '[Unserializable]'
  }
}

function pushEntry(buffer, max, entry) {
  buffer.push(entry)
  if (buffer.length > max) buffer.splice(0, buffer.length - max)
}

function captureConsole(level, origFn, args) {
  pushEntry(consoleLogs, MAX_CONSOLE_ENTRIES, {
    ts: new Date().toISOString(),
    level,
    msg: Array.from(args).map(serializeArg).join(' '),
  })
  origFn(...args)
}

/**
 * Install console interceptors. Call once at app boot, BEFORE any other
 * console override (e.g. the debug-gated console.log in main.js).
 */
export function initConsoleCapture() {
  console.log = (...args) => captureConsole('log', _origLog, args)
  console.warn = (...args) => captureConsole('warn', _origWarn, args)
  console.error = (...args) => captureConsole('error', _origError, args)

  window.addEventListener('error', (event) => {
    pushEntry(consoleLogs, MAX_CONSOLE_ENTRIES, {
      ts: new Date().toISOString(),
      level: 'uncaught',
      msg: `${event.message} at ${event.filename}:${event.lineno}:${event.colno}`,
    })
  })

  window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason
    const msg = reason instanceof Error
      ? `${reason.name}: ${reason.message}`
      : serializeArg(reason)
    pushEntry(consoleLogs, MAX_CONSOLE_ENTRIES, {
      ts: new Date().toISOString(),
      level: 'unhandled_rejection',
      msg,
    })
  })
}

/**
 * Record a network request (call from axios interceptors).
 */
export function recordNetworkEntry(entry) {
  const record = {
    ts: new Date().toISOString(),
    method: entry.method || 'GET',
    url: entry.url || '',
    status: entry.status ?? null,
    duration_ms: entry.duration_ms ?? null,
    error: entry.error || null,
  }

  if (entry.response_body && entry.status && entry.status >= 400) {
    let body = typeof entry.response_body === 'string'
      ? entry.response_body
      : JSON.stringify(entry.response_body)
    if (body.length > MAX_RESPONSE_BODY_LENGTH) {
      body = body.slice(0, MAX_RESPONSE_BODY_LENGTH) + '...'
    }
    record.response_body = body
  }

  pushEntry(networkLogs, MAX_NETWORK_ENTRIES, record)
}

/**
 * Get recent console logs (returns a copy).
 */
export function getConsoleLogs() {
  return [...consoleLogs]
}

/**
 * Get recent network logs (returns a copy).
 */
export function getNetworkLogs() {
  return [...networkLogs]
}
