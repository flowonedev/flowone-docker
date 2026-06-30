import { ref } from 'vue'
import api from '@/services/api'

/**
 * useDeviceApprovalWatcher — pushes a "someone is trying to sign in" prompt to
 * already-signed-in sessions.
 *
 * While the user is signed in, this polls GET /sso/device/pending for device
 * sign-in requests TARGETED at their account (a new phone / laptop / Drive app
 * that started a request with this email). When one appears it is surfaced so a
 * modal can show the device, IP and three numbers; the user taps the number
 * that matches the one shown on the new device to approve it.
 *
 * This replaces having to scan the QR with the signed-in device — the QR /
 * verify_url path still works as a fallback.
 *
 * Cross-tab / cross-session dismissal: once a request is resolved (approved,
 * denied, blocked or expired) it stops appearing in /sso/device/pending, so the
 * poll below clears any prompt whose request_id has vanished from the list. That
 * closes the modal on EVERY signed-in tab/session within one poll cycle, not just
 * the one that acted. A same-origin BroadcastChannel makes sibling tabs in the
 * same browser close instantly instead of waiting for the next poll.
 */

const POLL_INTERVAL_MS = 3000
const BROADCAST_CHANNEL = 'webmail_device_approval'
const MSG_RESOLVED = 'DEVICE_REQUEST_RESOLVED'

export function useDeviceApprovalWatcher() {
  // The request currently surfaced to the user (or null).
  const pending = ref(null)

  let timer = null
  let active = false
  // request_ids the user already acted on / dismissed, so we don't re-prompt.
  const handled = new Set()
  // The request_id this tab is actively resolving (approve/deny/block in flight
  // or showing its success/blocked animation). The poll must not auto-dismiss it
  // out from under the local transient UI; the action handler dismisses it.
  let actingOn = null
  // Same-origin channel so sibling tabs dismiss instantly when one resolves.
  let channel = null

  function openChannel() {
    if (channel) return channel
    try {
      channel = new BroadcastChannel(BROADCAST_CHANNEL)
      channel.addEventListener('message', onBroadcast)
    } catch (_e) {
      // BroadcastChannel unsupported (older Safari / some iOS) — polling still
      // dismisses cross-tab within a cycle, this is only the instant path.
      channel = null
    }
    return channel
  }

  function closeChannel() {
    if (!channel) return
    try {
      channel.removeEventListener('message', onBroadcast)
      channel.close()
    } catch (_e) {
      /* already closed */
    }
    channel = null
  }

  function onBroadcast(event) {
    const msg = event.data
    if (!msg || msg.type !== MSG_RESOLVED || !msg.request_id) return
    // Another tab in this browser resolved it — close ours immediately.
    dismiss(msg.request_id)
  }

  function broadcastResolved(requestId) {
    if (!requestId || !channel) return
    try {
      channel.postMessage({ type: MSG_RESOLVED, request_id: requestId })
    } catch (_e) {
      /* best-effort */
    }
  }

  function schedule() {
    clearTimer()
    if (!active) return
    timer = setTimeout(pollOnce, POLL_INTERVAL_MS)
  }

  function clearTimer() {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
  }

  async function pollOnce() {
    if (!active) return
    // NOTE: we intentionally poll even when the tab is hidden/backgrounded, so a
    // sign-in started while you're looking at your phone is still caught. The
    // request is tiny and short-lived requests would otherwise be missed.
    //
    // We poll even while a prompt is showing so that a request resolved
    // elsewhere (approved in another tab, signed in via the Drive/mobile app, or
    // simply expired) is detected and the modal is dismissed here too.
    try {
      const resp = await api.get('/sso/device/pending')
      const list = resp.data?.data?.requests || []
      const liveIds = new Set(list.map((r) => r && r.request_id).filter(Boolean))

      if (pending.value) {
        // The surfaced request vanished from the pending list → it was resolved
        // or expired somewhere. Clear it, unless this tab is the one resolving it
        // (its own action handler owns the dismiss + success animation).
        if (!liveIds.has(pending.value.request_id) && actingOn !== pending.value.request_id) {
          pending.value = null
        }
      }

      // Surface the next unhandled request only when nothing is shown.
      if (!pending.value && active) {
        const next = list.find((r) => r && r.request_id && !handled.has(r.request_id))
        if (next) {
          pending.value = next
        }
      }
    } catch (_e) {
      // Transient/network errors: keep whatever is shown and keep polling.
    } finally {
      schedule()
    }
  }

  function start() {
    if (active) return
    active = true
    openChannel()
    // Small initial delay so it doesn't race app bootstrap.
    timer = setTimeout(pollOnce, 1200)
  }

  function stop() {
    active = false
    clearTimer()
    closeChannel()
    actingOn = null
    pending.value = null
  }

  // Mark a request as locally resolving so the poll won't yank it from under the
  // success/blocked transient. Called by the modal when the user taps an action.
  function beginAction(requestId) {
    if (requestId) actingOn = requestId
  }

  function dismiss(requestId) {
    if (requestId) handled.add(requestId)
    if (actingOn === requestId) actingOn = null
    if (pending.value && pending.value.request_id === requestId) {
      pending.value = null
    }
  }

  async function approve(requestId, number) {
    const resp = await api.post('/sso/device/approve', { request_id: requestId, number })
    // Resolved — tell sibling tabs to close their prompt right away.
    broadcastResolved(requestId)
    return resp.data?.data || {}
  }

  async function deny(requestId) {
    try {
      await api.post('/sso/device/deny', { request_id: requestId })
    } catch (_e) {
      // best-effort
    }
    broadcastResolved(requestId)
  }

  async function block(requestId) {
    const resp = await api.post('/sso/device/block', { request_id: requestId })
    broadcastResolved(requestId)
    return resp.data?.data || {}
  }

  return { pending, start, stop, dismiss, beginAction, approve, deny, block }
}
