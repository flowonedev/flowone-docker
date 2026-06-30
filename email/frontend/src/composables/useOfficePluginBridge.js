/**
 * useOfficePluginBridge - postMessage channel between the FlowOne page and
 * the "flowone-presence" plugin running inside the OnlyOffice editor iframe.
 *
 * The plugin lives on the Document Server origin (it needs same-origin DOM
 * access to the editor), so all traffic crosses origins via postMessage:
 *
 *   plugin -> host: { flowonePresence, dir:'plugin', type:'ready' }
 *   host -> plugin: { flowonePresence, dir:'host',   type:'init', self }
 *   plugin -> host: { type:'cursor', cursor }          local cursor moved
 *   host -> plugin: { type:'cursors', cursors }        remote cursors to draw
 *   host -> plugin: { type:'follow', email|null }      start/stop follow mode
 *   plugin -> host: { type:'follow-stopped' }          user broke follow
 */

import { onBeforeUnmount } from 'vue'

export function useOfficePluginBridge({ getSelf, onCursor, onFollowStopped, onReady } = {}) {
  let pluginWindow = null
  let pluginOrigin = null
  let allowedOrigin = null
  let listening = false

  function post(payload) {
    if (!pluginWindow) return
    try {
      // Strip Vue reactivity: reactive proxies cannot be structured-cloned
      // by postMessage (DataCloneError), so serialize to plain JSON first.
      const plain = JSON.parse(
        JSON.stringify({ flowonePresence: true, dir: 'host', ...payload })
      )
      pluginWindow.postMessage(plain, pluginOrigin || '*')
    } catch (e) {
      console.warn('[OfficeBridge] postMessage to plugin failed:', e)
    }
  }

  function handleMessage(event) {
    const data = event.data
    if (!data || data.flowonePresence !== true || data.dir !== 'plugin') return
    if (allowedOrigin && event.origin !== allowedOrigin) return

    switch (data.type) {
      case 'ready':
        pluginWindow = event.source
        pluginOrigin = event.origin
        post({ type: 'init', self: getSelf?.() || null })
        onReady?.()
        break
      case 'cursor':
        onCursor?.(data.cursor || null)
        break
      case 'follow-stopped':
        onFollowStopped?.()
        break
      case 'probe':
        // Diagnostic relayed from the plugin (which runs in the cross-origin
        // editor frame) so it is readable from the main app console.
        console.info('[OfficePresence][PROBE]', JSON.stringify(data.probe || {}))
        break
    }
  }

  /**
   * @param {string} serverUrl Document Server URL - only messages from this
   *                           origin are accepted.
   */
  function start(serverUrl) {
    try {
      allowedOrigin = serverUrl ? new URL(serverUrl).origin : null
    } catch (e) {
      allowedOrigin = null
    }
    if (!listening) {
      window.addEventListener('message', handleMessage)
      listening = true
    }
  }

  function stop() {
    if (listening) {
      window.removeEventListener('message', handleMessage)
      listening = false
    }
    pluginWindow = null
    pluginOrigin = null
  }

  const sendCursors = (cursors) => post({ type: 'cursors', cursors })
  const sendFollow = (email) => post({ type: 'follow', email: email || null })
  const isPluginReady = () => !!pluginWindow

  onBeforeUnmount(stop)

  return { start, stop, sendCursors, sendFollow, isPluginReady }
}
