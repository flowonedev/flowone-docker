/**
 * Build-time stub for `firebase/messaging`.
 *
 * The native apps (FlowOneMobile / FlowOneChatMobile) run the bundled frontend
 * build, so the @capacitor-firebase/messaging plugin must be BUNDLED (not
 * externalized) — its registerPlugin() proxy is what routes to the native FCM
 * bridge on iOS/Android.
 *
 * That plugin's *web* implementation (web.js) statically imports the firebase
 * JS SDK from `firebase/messaging`. We never use FCM on the web build (it's
 * guarded by isNative and, on native, the native bridge is used instead of
 * web.js), so we alias `firebase/messaging` to this stub in vite.config. It
 * satisfies the named-import resolution at build time without pulling the
 * firebase web SDK into the bundle. None of these run in practice: the web.js
 * chunk is never loaded on web (isNative short-circuits) nor on native (bridge).
 */

export const isSupported = () => Promise.resolve(false)
export const getMessaging = () => null
export const getToken = () => Promise.resolve(null)
export const deleteToken = () => Promise.resolve(false)
export const onMessage = () => () => {}
