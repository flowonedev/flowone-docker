/**
 * Audit Logger for Node.js services
 * 
 * Buffers security events and sends them to the Panel's audit log 
 * ingest endpoint in batches. Falls back to console.log if Panel
 * is unreachable.
 */

import { config } from '../config.js'

const FLUSH_INTERVAL_MS = 30000 // 30 seconds
const MAX_BUFFER = 50

let buffer = []
let flushTimer = null

/**
 * Queue an audit event
 */
export function logAudit(action, severity = 'info', outcome = 'success', details = {}, userEmail = null) {
  buffer.push({
    action,
    severity,
    outcome,
    details,
    user_email: userEmail,
    ip_address: details.ip || null,
    actor: userEmail ? 'user' : 'system',
    target: details.target || '',
  })

  // Auto-flush if buffer is full
  if (buffer.length >= MAX_BUFFER) {
    flush()
  }

  // Start periodic flush timer if not already running
  if (!flushTimer) {
    flushTimer = setInterval(flush, FLUSH_INTERVAL_MS)
  }
}

/**
 * Convenience: log auth events
 */
export function logAuthEvent(action, outcome, userEmail, details = {}) {
  const severity = outcome === 'failed' ? 'medium' : 'info'
  logAudit(`ws.auth.${action}`, severity, outcome, details, userEmail)
}

/**
 * Convenience: log connection events  
 */
export function logConnectionEvent(action, details = {}) {
  logAudit(`ws.connection.${action}`, 'info', 'success', details)
}

/**
 * Flush buffered events to Panel
 */
async function flush() {
  if (buffer.length === 0) return

  const events = [...buffer]
  buffer = []

  const panelUrl = process.env.PANEL_API_URL || config.panel?.apiUrl || 'https://panel.devcon1.hu/api'
  const apiKey = process.env.PANEL_API_KEY || config.panel?.apiKey || ''
  const sourceApp = config.service?.name || 'mailsync'

  if (!apiKey) {
    // No API key configured — log locally
    events.forEach(e => {
      console.log(`[AUDIT] ${e.severity} | ${e.action} | ${e.outcome} | ${e.user_email || 'system'}`)
    })
    return
  }

  const url = `${panelUrl.replace(/\/$/, '')}/audit/ingest`

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Api-Key': apiKey,
      },
      body: JSON.stringify({
        source_app: sourceApp,
        events,
      }),
      signal: AbortSignal.timeout(5000),
    })

    if (!response.ok) {
      console.error(`[AuditLogger] Panel returned ${response.status}`)
      // Fallback log
      events.forEach(e => {
        console.log(`[AUDIT-LOCAL] ${e.severity} | ${e.action} | ${e.outcome}`)
      })
    }
  } catch (error) {
    console.error(`[AuditLogger] Failed to send to panel: ${error.message}`)
    // Fallback log
    events.forEach(e => {
      console.log(`[AUDIT-LOCAL] ${e.severity} | ${e.action} | ${e.outcome}`)
    })
  }
}

/**
 * Flush remaining events on shutdown
 */
export function flushAndStop() {
  if (flushTimer) {
    clearInterval(flushTimer)
    flushTimer = null
  }
  return flush()
}

export default { logAudit, logAuthEvent, logConnectionEvent, flushAndStop }

