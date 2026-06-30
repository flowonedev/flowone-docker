// @ts-check
/**
 * Page-side helpers exposed via `window.__flowoneChaos` for asserting LiveKit / call state.
 *
 * Usage from a spec:
 *   await installChaosHooks(page)
 *   const state = await page.evaluate(() => window.__flowoneChaos.getState())
 *
 * The hooks listen for VideoCallRoom milestones (room.connect, RoomEvent.Reconnecting,
 * Reconnected, ParticipantConnected, Disconnected, DataReceived) by attaching to the
 * exposed `window.flowoneCallRoom` global the component publishes for tests.
 *
 * Backwards compatible — if the component is not exposing the global, the hooks fall
 * back to scraping DOM-level signals (banner visibility, participant tiles).
 */

const HOOK_SCRIPT = `
(() => {
  if (window.__flowoneChaos) return
  const events = []
  const state = {
    connected: false,
    reconnecting: false,
    reconnects: 0,
    participants: new Map(),
    dataMessages: [],
    disconnectReason: null,
    kicked: false,
    online: navigator.onLine,
    visibility: document.visibilityState,
    dupTab: false,
  }

  function pushEvent(type, detail) {
    events.push({ type, detail, t: Date.now() })
    if (events.length > 500) events.shift()
  }

  window.addEventListener('online', () => { state.online = true; pushEvent('online') })
  window.addEventListener('offline', () => { state.online = false; pushEvent('offline') })
  document.addEventListener('visibilitychange', () => {
    state.visibility = document.visibilityState
    pushEvent('visibility', document.visibilityState)
  })

  function wireLiveKitRoom(room) {
    if (!room || room.__chaosWired) return
    room.__chaosWired = true
    try {
      const LK = window.LiveKitClient || window.livekitClient || null
      const E = (LK && LK.RoomEvent) || {}
      const on = (name, fn) => { try { room.on(name, fn) } catch {} }

      on(E.Connected || 'connected', () => {
        state.connected = true
        state.reconnecting = false
        // Snapshot the participants that were already in the room when we
        // joined — ParticipantConnected only fires for *subsequent* joins.
        try {
          const remote = room.remoteParticipants || room.participants
          if (remote) {
            const iter = typeof remote.values === 'function' ? remote.values() : Object.values(remote)
            for (const p of iter) {
              if (p && p.identity) {
                state.participants.set(p.identity, { name: p.name, identity: p.identity })
              }
            }
          }
        } catch {}
        pushEvent('connected')
      })
      on(E.Reconnecting || 'reconnecting', () => {
        state.reconnecting = true
        pushEvent('reconnecting')
      })
      on(E.Reconnected || 'reconnected', () => {
        state.reconnecting = false
        state.reconnects++
        pushEvent('reconnected')
      })
      on(E.SignalConnected || 'signalConnected', () => {
        pushEvent('signalConnected')
      })
      on(E.Disconnected || 'disconnected', (reason) => {
        state.connected = false
        state.disconnectReason = String(reason ?? '')
        // LiveKit emits DisconnectReason enum numbers (newer client) or
        // string names (older). Recognise both for PARTICIPANT_REMOVED (= 4).
        const kickedByEnum = reason === 4 || state.disconnectReason === '4'
        const kickedByName = /removed|kicked|participant_removed/i.test(state.disconnectReason)
        if (kickedByEnum || kickedByName) {
          state.kicked = true
        }
        pushEvent('disconnected', state.disconnectReason)
      })
      on(E.ParticipantConnected || 'participantConnected', (p) => {
        try {
          state.participants.set(p.identity, { name: p.name, identity: p.identity })
        } catch {}
        pushEvent('participantConnected', p && p.identity)
      })
      on(E.ParticipantDisconnected || 'participantDisconnected', (p) => {
        try { state.participants.delete(p.identity) } catch {}
        pushEvent('participantDisconnected', p && p.identity)
      })
      on(E.DataReceived || 'dataReceived', (payload) => {
        try {
          const decoder = new TextDecoder()
          const text = decoder.decode(payload)
          const parsed = JSON.parse(text)
          state.dataMessages.push(parsed)
          if (state.dataMessages.length > 100) state.dataMessages.shift()
          pushEvent('dataReceived', parsed && parsed.type)
        } catch (e) {
          pushEvent('dataReceived', 'parse-error')
        }
      })
    } catch (e) {
      pushEvent('wireError', String(e && e.message || e))
    }
  }

  Object.defineProperty(window, 'flowoneCallRoom', {
    configurable: true,
    set(v) {
      window.__flowoneRoomStash = v
      wireLiveKitRoom(v)
    },
    get() {
      return window.__flowoneRoomStash || null
    },
  })

  window.__flowoneChaos = {
    getEvents: () => events.slice(),
    getState: () => ({
      ...state,
      participants: Array.from(state.participants.values()),
    }),
    waitForEvent: (typeOrTypes, timeoutMs = 5000, opts = {}) => new Promise((resolve, reject) => {
      const types = Array.isArray(typeOrTypes) ? typeOrTypes : [typeOrTypes]
      const now = Date.now()
      // Default 750ms look-back catches events that fire between an awaited
      // server action returning and the test code calling waitForEvent.
      const since = (opts && typeof opts.since === 'number') ? opts.since : (now - (opts.lookbackMs ?? 750))
      const tick = () => {
        const hit = events.find((e) => types.indexOf(e.type) !== -1 && e.t >= since)
        if (hit) return resolve(hit)
        if (Date.now() - now > timeoutMs) {
          const recent = events
            .slice(-25)
            .map((e) => e.type + (e.detail !== undefined ? '(' + JSON.stringify(e.detail).slice(0, 60) + ')' : '') + '@' + Math.round(e.t - now) + 'ms')
            .join(', ')
          return reject(new Error(
            'Timeout waiting for ' + types.join('|') + ' after ' + timeoutMs + 'ms (since=' + (since - now) + 'ms). ' +
            'Recent events: [' + recent + ']'
          ))
        }
        setTimeout(tick, 80)
      }
      tick()
    }),
    setDupTab: (v) => { state.dupTab = !!v; pushEvent('dupTab', !!v) },
  }
})()
`

async function installChaosHooks(page) {
  await page.addInitScript({ content: HOOK_SCRIPT })
}

async function getCallState(page) {
  return page.evaluate(() => window.__flowoneChaos && window.__flowoneChaos.getState())
}

async function getCallEvents(page) {
  return page.evaluate(() => (window.__flowoneChaos && window.__flowoneChaos.getEvents()) || [])
}

/**
 * Wait until one of the given event types is observed.
 * `type` may be a single string or an array of strings (matches first to fire).
 *
 * @param {object} [opts]
 * @param {number} [opts.lookbackMs=750] Accept events that fired this many ms before the call.
 * @param {number} [opts.since] Absolute Date.now() cut-off (overrides lookbackMs).
 */
async function waitForEvent(page, type, timeoutMs = 5000, opts = {}) {
  return page.evaluate(
    async ({ type, timeoutMs, opts }) => window.__flowoneChaos.waitForEvent(type, timeoutMs, opts),
    { type, timeoutMs, opts },
  )
}

module.exports = {
  installChaosHooks,
  getCallState,
  getCallEvents,
  waitForEvent,
}
