/**
 * Native diagnostic log bridge.
 *
 * Mirrors a line to the JS console AND the iOS/Android native unified log via
 * the app-local `CallNative` plugin. On release iOS builds Safari Web Inspector
 * can't attach, so routing key call-flow diagnostics through the native plugin
 * is the only way to see them under `devicectl process launch --console`
 * (deploy script `--logs`).
 *
 * Self-guarding: off-native and in the email app the plugin is absent / its
 * method rejects, so every call is a no-op. Kept dependency-free (only
 * @capacitor/core) so both services/callKit.js and stores/call.js can import it
 * without creating a circular dependency.
 */

import { registerPlugin } from '@capacitor/core'

const CallNative = registerPlugin('CallNative')

export function nativeLog(msg) {
  try { console.log(msg) } catch (_e) { /* no console */ }
  try { CallNative.nativeLog({ msg: String(msg) })?.catch?.(() => {}) } catch (_e) { /* plugin absent */ }
}

export default nativeLog
