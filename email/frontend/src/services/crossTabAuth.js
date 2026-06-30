/**
 * Cross-tab auth handoff (same-origin only).
 *
 * The web app keeps its access/session tokens in per-tab `sessionStorage`, so a
 * freshly opened tab has no session even when another tab in the same browser is
 * signed in. That breaks the device-approval ("scan to sign in") page, which is
 * usually opened in a brand-new tab -- either by FlowOne Drive's "Approve in
 * browser" button (launches the OS default browser) or by a phone scanning the
 * QR. The new tab lands on /link-device with empty sessionStorage and gets
 * bounced to a password login even though the user is already signed in next door.
 *
 * This module lets a tokenless tab borrow the live session from an already
 * signed-in sibling tab over a BroadcastChannel.
 *
 * Security: BroadcastChannel is strictly same-origin, so only first-party
 * flowone.pro contexts can participate. Any script able to listen here would
 * already have full access to first-party token storage via XSS, so the handoff
 * does not widen the attack surface. We still scope it tightly:
 *   - only signed-in tabs ever answer (tokenless tabs stay silent),
 *   - each grant is targeted by a random nonce and consumed once,
 *   - it is used solely to authorize a device-link approval the user explicitly
 *     triggered, and the backend still enforces number-matching on approve.
 */
import { getToken } from './tokenStorage'

const CHANNEL_NAME = 'webmail_auth_handoff'
const REQUEST = 'AUTH_REQUEST'
const GRANT = 'AUTH_GRANT'

function openChannel() {
  try {
    return new BroadcastChannel(CHANNEL_NAME)
  } catch (_e) {
    // BroadcastChannel unsupported (older Safari / some iOS) — handoff is a no-op
    // and callers fall back to the password login path.
    return null
  }
}

/**
 * Start answering handoff requests from other tabs. Call once per tab (e.g. from
 * App.vue). Tokens are read lazily on each request, so a tab that signs in later
 * begins serving automatically, and a signed-out tab never answers. Returns a
 * stop() function.
 */
export function startAuthHandoffServer() {
  const channel = openChannel()
  if (!channel) return () => {}

  const onMessage = (event) => {
    const msg = event.data
    if (!msg || msg.type !== REQUEST || !msg.nonce) return

    const accessToken = getToken('webmail_token')
    if (!accessToken) return // this tab isn't signed in — stay silent

    channel.postMessage({
      type: GRANT,
      nonce: msg.nonce,
      access_token: accessToken,
      session_token: getToken('webmail_session_token') || null,
      refresh_token: getToken('webmail_refresh_token') || null,
    })
  }

  channel.addEventListener('message', onMessage)
  return () => {
    try {
      channel.removeEventListener('message', onMessage)
      channel.close()
    } catch (_e) {
      /* already closed */
    }
  }
}

/**
 * Ask sibling tabs for a live session. Resolves with the granted token bundle
 * { access_token, session_token, refresh_token } or null if no signed-in tab
 * answers within `timeoutMs`.
 */
export function requestAuthFromOtherTabs(timeoutMs = 1500) {
  return new Promise((resolve) => {
    const channel = openChannel()
    if (!channel) {
      resolve(null)
      return
    }

    const nonce =
      (typeof crypto !== 'undefined' && crypto.randomUUID?.()) ||
      `${Date.now()}-${Math.random().toString(36).slice(2)}`
    let settled = false

    const finish = (result) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      try {
        channel.removeEventListener('message', onMessage)
        channel.close()
      } catch (_e) {
        /* already closed */
      }
      resolve(result)
    }

    const onMessage = (event) => {
      const msg = event.data
      if (msg && msg.type === GRANT && msg.nonce === nonce && msg.access_token) {
        finish(msg)
      }
    }

    channel.addEventListener('message', onMessage)
    const timer = setTimeout(() => finish(null), timeoutMs)
    channel.postMessage({ type: REQUEST, nonce })
  })
}
