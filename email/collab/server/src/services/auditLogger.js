/**
 * Audit Logger for Collab WebSocket Server
 * 
 * Buffers security events and sends them to the Panel's audit log 
 * ingest endpoint in batches.
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

  if (buffer.length >= MAX_BUFFER) {
    flush()
  }

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
 * Flush buffered events to Panel
 */
async function flush() {
  if (buffer.length === 0) return

  const events = [...buffer]
  buffer = []

  const panelUrl = process.env.PANEL_API_URL || 'https://panel.devcon1.hu/api'
  const apiKey = process.env.PANEL_API_KEY || ''

  if (!apiKey) {
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
        source_app: 'collab',
        events,
      }),
      signal: AbortSignal.timeout(5000),
    })

    if (!response.ok) {
      console.error(`[AuditLogger] Panel returned ${response.status}`)
      events.forEach(e => {
        console.log(`[AUDIT-LOCAL] ${e.severity} | ${e.action} | ${e.outcome}`)
      })
    }
  } catch (error) {
    console.error(`[AuditLogger] Failed to send to panel: ${error.message}`)
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

export default { logAudit, logAuthEvent, flushAndStop }

