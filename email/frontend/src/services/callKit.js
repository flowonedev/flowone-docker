/**
 * CallKit / Android full-screen-intent bridge.
 *
 * Wires the native `CallNative` Capacitor plugin (iOS PushKit + CallKit /
 * Android full-screen-intent) into the shared LiveKit call store:
 *   - registers the device's VoIP / PushKit token with the backend
 *     (token_kind 'voip') so the server can ring it via APNs VoIP
 *   - reflects the system ring into the store so the in-app IncomingCallModal
 *     stands down (no double UI)
 *   - turns system Accept/Decline into store accept/reject, handling the case
 *     where the VoIP/FSI push beat the WebSocket CALL_INITIATE (app was killed)
 *   - dismisses the system call UI when the call ends in-app
 *
 * Self-guarding: a no-op off-native and in apps without the plugin (the email
 * app), so importing it from the shared frontend is always safe.
 */

import { watch } from 'vue'
import { registerPlugin, Capacitor } from '@capacitor/core'
import api from '@/services/api'
import { useCallStore } from '@/stores/call'
import { useAuthStore } from '@/stores/auth'
import { getApiOrigin, getWsUrl } from '@/services/serverRegistry'
import { nativeLog as nlog } from '@/services/nativeLog'

// App-local custom plugin; present only in the Chat app's native build. In the
// email app / web this proxy's methods reject (UNIMPLEMENTED) and listeners
// never fire — both handled gracefully below.
const CallNative = registerPlugin('CallNative')
const APP_ID = 'com.flowone.chat'

function isNative() {
  return typeof window !== 'undefined' && !!window.Capacitor?.isNativePlatform?.()
}

function platform() {
  const p = Capacitor.getPlatform?.()
  return p === 'ios' || p === 'android' ? p : 'unknown'
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

class CallKitBridge {
  constructor() {
    this.initialized = false
    this.voipToken = null
    this._registeredToken = null
    this._handles = []
    this._visHandler = null
    this._ensuring = false
    this._store = null
    this._stopWatch = null
    this._stopAuthWatch = null
  }

  store() {
    if (!this._store) this._store = useCallStore()
    return this._store
  }

  async deviceInfo() {
    try {
      const { Device } = await import('@capacitor/device')
      const id = await Device.getId()
      const info = await Device.getInfo()
      return {
        deviceId: id?.identifier || id?.uuid || null,
        deviceName:
          [info?.manufacturer, info?.model].filter(Boolean).join(' ') ||
          info?.name || info?.model || null,
      }
    } catch (_e) {
      return { deviceId: null, deviceName: null }
    }
  }

  /**
   * Register the VoIP/PushKit token with the backend (distinct token_kind).
   * Retries on failure so a cold-start race (auth/network not ready yet) can't
   * permanently drop the registration. Returns true once stored.
   */
  async registerVoipToken(token, { retries = 4 } = {}) {
    if (!token) return false
    this.voipToken = token
    const { deviceId, deviceName } = await this.deviceInfo()
    for (let attempt = 1; attempt <= retries; attempt++) {
      try {
        await api.post('/push/native-register', {
          platform: platform(),
          token,
          token_kind: 'voip',
          device_id: deviceId || undefined,
          device_name: deviceName || undefined,
          app_id: APP_ID,
        })
        this._registeredToken = token
        nlog('[CallKit] VoIP token registered with backend OK: ' + String(token).slice(0, 16) + '…')
        return true
      } catch (e) {
        nlog('[CallKit] VoIP register attempt ' + attempt + '/' + retries +
          ' FAILED: status=' + (e?.response?.status ?? '?') + ' msg=' + (e?.message || ''))
        if (attempt < retries) await sleep(1500 * attempt)
      }
    }
    return false
  }

  /**
   * Ensure the device's current VoIP token is registered. The PushKit token is
   * issued at app launch (before the webview/login), so we poll the native
   * bridge a few times in case it isn't readable the instant we ask, then
   * register with retry. Idempotent and safe to call repeatedly (e.g. resume).
   */
  async ensureVoipRegistered({ pollAttempts = 12, pollDelayMs = 1000 } = {}) {
    if (!isNative() || this._ensuring) return false
    this._ensuring = true
    try {
      for (let i = 1; i <= pollAttempts; i++) {
        let token = null
        try {
          const res = await CallNative.getVoipToken()
          token = res?.token || null
        } catch (e) {
          nlog('[CallKit] getVoipToken() threw: ' + (e?.message || ''))
        }
        if (token) {
          if (this._registeredToken === token) return true
          nlog('[CallKit] getVoipToken() -> ' + String(token).slice(0, 16) + '… (attempt ' + i + ')')
          if (await this.registerVoipToken(token)) return true
        } else {
          nlog('[CallKit] getVoipToken() EMPTY (attempt ' + i + '/' + pollAttempts + ')')
        }
        await sleep(pollDelayMs)
      }
      nlog('[CallKit] ensureVoipRegistered gave up after ' + pollAttempts + ' attempts')
      return false
    } finally {
      this._ensuring = false
    }
  }

  /**
   * Hand the native layer everything it needs to answer a call entirely on its
   * own while the WebView is suspended (phone locked): the API base (to fetch a
   * LiveKit token), the mailsync WS URL + auth token (to signal CALL_ANSWER),
   * and our email (the LiveKit participant identity — must match the WebView's
   * so the foreground hand-off cleanly replaces the native participant).
   * Idempotent and safe to call repeatedly; a no-op off-native / pre-login.
   */
  async pushSession() {
    if (!isNative()) return false
    try {
      const auth = useAuthStore()
      const token = auth.token
      const email = auth.userEmail
      if (!token || !email) {
        nlog('[CallKit] setSession skipped — no token/email yet')
        return false
      }
      // The native engine signals CALL_ANSWER over the mailsync WS and fetches a
      // LiveKit token over HTTP, so BOTH endpoints must be fully resolved. On
      // native they come from the persisted per-deployment base, which is '' for
      // a beat right after launch. Pushing then would persist a useless session
      // (empty wsUrl => caller never stops ringing), so wait until both resolve.
      const origin = getApiOrigin()
      const wsUrl = getWsUrl()
      if (!origin || !wsUrl) {
        nlog('[CallKit] setSession skipped — server base not resolved yet (origin=' +
          (origin || 'EMPTY') + ' ws=' + (wsUrl ? 'set' : 'EMPTY') + ')')
        return false
      }
      await CallNative.setSession({
        apiBase: origin + '/api',
        wsUrl,
        token,
        email,
      })
      nlog('[CallKit] setSession pushed (email=' + email + ' api=' + origin + '/api ws=set)')
      return true
    } catch (e) {
      nlog('[CallKit] setSession FAILED: ' + (e?.message || ''))
      return false
    }
  }

  async init() {
    if (this.initialized || !isNative()) return false
    nlog('[CallKit] init() starting — wiring native call bridge')
    try {
      const handles = []
      // Native pushes us a fresh PushKit/VoIP token (and reissues on rotation).
      handles.push(await CallNative.addListener('voipToken', (e) => {
        nlog('[CallKit] voipToken event -> ' + (e?.token ? (String(e.token).slice(0, 16) + '…') : 'EMPTY'))
        if (e?.token) this.registerVoipToken(e.token)
      }))
      // The OS is presenting (or about to present) the ring for this call.
      handles.push(await CallNative.addListener('incomingCall', (e) => {
        if (e?.callId) this.store().markNativeRing(e.callId)
      }))
      // User tapped Accept on the system call screen. Forward the FULL call
      // info from the VoIP push so the store can join the call straight away —
      // it must not wait for a WS CALL_INITIATE, which never arrives once
      // CallKit has backgrounded the app and suspended its socket.
      handles.push(await CallNative.addListener('callAnswered', (e) => {
        const s = this.store()
        nlog('[CallKit] callAnswered evt callId=' + (e?.callId || '') +
          ' conv=' + (e?.conversationId || '') + ' type=' + (e?.callType || '') +
          ' storeStatus=' + s.callStatus + ' dir=' + s.callDirection +
          ' storeCallId=' + (s.callId || ''))
        s.acceptFromNative(e?.callId || null, {
          callId: e?.callId || null,
          conversationId: e?.conversationId || null,
          callType: e?.callType || 'voice',
          callerEmail: e?.callerEmail || null,
          callerName: e?.callerName || null,
        })
      }))
      // User tapped Decline on the system call screen.
      handles.push(await CallNative.addListener('callDeclined', (e) => {
        this.store().rejectFromNative(e?.callId || null, 'declined')
      }))
      // System/remote teardown (we sent an end signal, or CallKit ended it).
      handles.push(await CallNative.addListener('callEnded', (e) => {
        this.store().clearNativeRing(e?.callId || null)
      }))
      // User toggled mute on the system call UI (CallKit lock screen). Reflect
      // it in the store so the in-app mic state/UI matches after a hand-off.
      handles.push(await CallNative.addListener('callMuted', (e) => {
        this.store().setMutedFromNative(!!e?.muted)
      }))
      this._handles = handles

      // Prime the VoIP token (covers the case where the token event fired
      // before listeners were attached, e.g. cold start). Poll + retry in the
      // background so a token/auth lag can't permanently drop registration;
      // don't block init() on it.
      this.ensureVoipRegistered()

      // Hand the native engine the session so it can answer while we're asleep.
      this.pushSession()

      // Keep the persisted native session FRESH, and push it as soon as the
      // credentials are complete. pushSession() needs BOTH the auth token AND
      // the user email. On a cold start the token is restored from storage
      // synchronously, but the email only arrives a tick later once checkAuth()
      // populates user.value — and the token value itself never changes, so a
      // token-ONLY watch would miss that late email and the session would never
      // be pushed (hasSession stays false, and a locked-screen answer can't
      // signal the caller, who rings until timeout). Watch both so a late email,
      // a token refresh, or a re-login all re-push. pushSession() self-guards.
      const auth = useAuthStore()
      this._stopAuthWatch = watch(
        () => [auth.token, auth.userEmail],
        () => { this.pushSession() }
      )

      // Re-assert registration whenever the app returns to the foreground —
      // catches token rotation and any first attempt that raced auth/network.
      // visibilitychange fires on iOS app resume in the WKWebView, with no
      // extra Capacitor plugin dependency.
      if (typeof document !== 'undefined') {
        this._visHandler = () => {
          if (document.visibilityState === 'visible') {
            this.ensureVoipRegistered({ pollAttempts: 3 })
            this.pushSession()
          }
        }
        document.addEventListener('visibilitychange', this._visHandler)
      }

      // When our call leaves the ringing/active lifecycle (answered/declined/
      // ended in-app, or remote teardown via WS), dismiss any lingering system
      // call UI so CallKit / the Android FSI never strands the user.
      const store = this.store()
      this._stopWatch = watch(
        () => store.callStatus,
        (status, prev) => {
          const wasLive = prev === 'ringing' || prev === 'active' || prev === 'initiating'
          if (wasLive && (status === 'ended' || status === 'idle')) {
            const id = store.callId || store.nativeRingCallId
            this.endCall(id)
          }
        }
      )

      this.initialized = true
      nlog('[CallKit] init() complete')
      return true
    } catch (e) {
      // Plugin absent (email app) or registration failed — stay a no-op.
      nlog('[CallKit] init() FAILED early: ' + (e?.message || ''))
      return false
    }
  }

  /** Tell the native side to dismiss the system call UI for this call. */
  async endCall(callId) {
    if (!isNative()) return
    try {
      await CallNative.endCall({ callId: callId ? String(callId) : '' })
    } catch (_e) { /* plugin absent / nothing to dismiss */ }
  }
}

export const callKit = new CallKitBridge()
export default callKit
