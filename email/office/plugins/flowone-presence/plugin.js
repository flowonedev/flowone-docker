/**
 * FlowOne Presence plugin (runs INSIDE the OnlyOffice editor, same origin
 * as the Document Server).
 *
 * Responsibilities:
 *   - capture the local user's pointer + scroll over the editing area and
 *     report it to the FlowOne host page (window.top) via postMessage
 *   - draw the other participants' cursors as a DOM overlay on top of the
 *     editor canvas
 *   - follow mode: mirror the followed user's scroll position; any manual
 *     input (wheel / click / key) breaks the follow and tells the host
 *
 * The host page relays cursor states between participants over the FlowOne
 * collab server (Hocuspocus awareness). This plugin never talks to the
 * network itself.
 *
 * Message protocol (all messages carry { flowonePresence: true }):
 *   plugin -> host  dir:'plugin'  type:'ready' | 'cursor' | 'follow-stopped' | 'probe'
 *   host -> plugin  dir:'host'    type:'init' | 'cursors' | 'follow'
 *
 * A 'cursor' payload is anchored per editor (cursor.mode):
 *   word : { xf, dy, sy }       - fraction of width + document-absolute Y
 *   cell : { col, row, fx, fy, sheet } - anchor cell + fraction inside it
 */

(function (window, undefined) {
  'use strict'

  // Master kill switch for the FlowOne floating cursor overlay. When false the
  // plugin stays completely inert in EVERY editor (Word, cell, slide): it
  // captures nothing, draws nothing, and sends nothing, so the only presence
  // shown is OnlyOffice's own built-in co-editing (selections / text cursor).
  // Flip to true to re-enable the custom free-moving cursors.
  var CURSORS_ENABLED = false

  var SEND_INTERVAL = 80     // ms between local cursor reports
  var TICK_INTERVAL = 150    // scroll watch / re-render / follow poll
  var READY_PING_MS = 1000   // handshake retry until the host answers

  var host = { win: null, origin: null }
  var state = {
    self: null,            // { email, name, color } (set by host 'init')
    remote: [],            // [{ email, name, color, cursor }]
    followEmail: null,
    layer: null,           // overlay container in the editor document
    els: {},               // email -> cursor element
    lastClient: null,      // last local pointer position (client coords)
    lastSend: 0,
    lastScrollY: null,     // Word vertical scroll (px) - change triggers a report
    lastCellKey: null,     // Excel first-visible cell - change = we scrolled
    gridCanvas: null,      // cached spreadsheet grid <canvas> (coord frame ref)
    lastProbeKey: null,    // visible-range signature of the last probe emit
    probeCount: 0,         // cap on diagnostic probe emits
    readyTimer: null,
  }

  // ========================================================================
  // Editor frame access (same origin as this plugin iframe)
  // ========================================================================

  function edoc() {
    return window.parent.document
  }

  function editorArea() {
    var d = edoc()
    return d.getElementById('editor_sdk') || d.body
  }

  /** Vertical document scroll in px. null = not exposed (e.g. cell editor). */
  function getScrollY() {
    try {
      var wc = window.parent.editor && window.parent.editor.WordControl
      if (wc && typeof wc.m_dScrollY === 'number') {
        return wc.m_dScrollY
      }
    } catch (e) { /* internals differ per editor type/version */ }
    return null
  }

  /** Scroll the document to absolute y. Returns false when unsupported. */
  function scrollDocTo(y) {
    try {
      var wc = window.parent.editor && window.parent.editor.WordControl
      if (!wc || typeof wc.m_dScrollY !== 'number') return false
      var api = wc.m_oScrollVerApi
      if (api && typeof api.scrollToY === 'function') {
        api.scrollToY(Math.max(0, y))
        return true
      }
      if (api && typeof api.scrollByY === 'function') {
        api.scrollByY(y - wc.m_dScrollY)
        return true
      }
    } catch (e) { /* unsupported editor */ }
    return false
  }

  /**
   * Editor iframe viewport size. Used to bounds-check the local pointer before
   * reporting it (ignore moves outside the visible editor area).
   */
  function viewport() {
    try {
      var docEl = edoc().documentElement
      return {
        w: window.parent.innerWidth || (docEl && docEl.clientWidth) || 0,
        h: window.parent.innerHeight || (docEl && docEl.clientHeight) || 0,
      }
    } catch (e) {
      return { w: 0, h: 0 }
    }
  }

  /**
   * Which editor we are running inside: 'word' | 'cell' | 'slide' | 'unknown'.
   * A floating pointer is only meaningful where it can be anchored to a shared,
   * scroll-independent coordinate:
   *   - word : document Y offset (WordControl)        -> see word cursor path
   *   - cell : the grid's content origin (A1 pixel)   -> see cell cursor path
   *   - slide: not implemented yet                    -> inert
   * Raw screen pixels are never shared (each viewer scrolls/zooms on their own).
   */
  function editorType() {
    try {
      var info = window.Asc && window.Asc.plugin && window.Asc.plugin.info
      if (info && info.editorType) return info.editorType
    } catch (e) { /* info not ready yet */ }
    return getScrollY() !== null ? 'word' : 'unknown'
  }

  // ---- Spreadsheet (cell) internals -------------------------------------
  // The public builder API exposes no viewport/scroll position, so - exactly
  // as the Word path already does with editor.WordControl - we read the
  // worksheet view from editor internals.

  function cellWorksheet() {
    try {
      var ed = window.parent.editor
      var wb = ed && ed.wb
      if (wb && typeof wb.getWorksheet === 'function') return wb.getWorksheet()
    } catch (e) { /* internals differ per build */ }
    return null
  }

  function cellSheetIndex() {
    try {
      var ed = window.parent.editor
      if (ed && typeof ed.asc_getActiveWorksheetIndex === 'function') {
        return ed.asc_getActiveWorksheetIndex()
      }
    } catch (e) { /* single-sheet fallback */ }
    return 0
  }

  /**
   * Bounding rect of the spreadsheet grid <canvas>. getCellCoord values are
   * expressed in this element's frame, so it is the reference we add to when
   * placing a cursor and subtract from when locating the local pointer. The
   * largest canvas in the editor frame is the grid; cached until detached.
   */
  function gridCanvasRect() {
    var el = state.gridCanvas
    if (!el || !el.isConnected) {
      el = null
      try {
        var canvases = edoc().getElementsByTagName('canvas')
        var best = null, bestArea = 0
        for (var i = 0; i < canvases.length; i++) {
          var b = canvases[i].getBoundingClientRect()
          var area = b.width * b.height
          if (area > bestArea) { bestArea = area; best = canvases[i] }
        }
        el = best
      } catch (e) { el = null }
      state.gridCanvas = el
    }
    return (el || editorArea()).getBoundingClientRect()
  }

  /** Visible cell range { r1, c1, r2, c2 } (0-based) or null. */
  function cellVisibleRange(ws) {
    try {
      var vr = null
      if (typeof ws.getVisibleRange === 'function') vr = ws.getVisibleRange()
      if (!vr && ws.visibleRange) vr = ws.visibleRange
      if (!vr && ws._VisibleRange) vr = ws._VisibleRange
      if (vr && vr.r1 != null && vr.c1 != null) {
        return { r1: vr.r1, c1: vr.c1, r2: vr.r2, c2: vr.c2 }
      }
    } catch (e) { /* shape differs per build */ }
    return null
  }

  /** Pixel rect { x, y, w, h } of a cell in the grid-canvas frame, or null. */
  function cellRect(ws, col, row) {
    try {
      var c = ws.getCellCoord(col, row)
      if (c && typeof c.asc_getX === 'function') {
        return { x: c.asc_getX(), y: c.asc_getY(), w: c.asc_getWidth(), h: c.asc_getHeight() }
      }
    } catch (e) { /* noop */ }
    return null
  }

  /**
   * Cell under the canvas-frame point (mx,my): { col, row, fx, fy } where fx/fy
   * is the fractional position inside the cell. Column x depends only on the
   * column and row y only on the row, so we scan the top row and left column of
   * the visible range - a few dozen cheap getCellCoord calls.
   */
  function findCellAt(ws, vr, mx, my) {
    if (!vr) return null
    var col = -1, row = -1, fx = 0, fy = 0, c, r, rc
    for (c = vr.c1; c <= vr.c2; c++) {
      rc = cellRect(ws, c, vr.r1)
      if (rc && mx >= rc.x && mx < rc.x + rc.w) { col = c; fx = rc.w ? (mx - rc.x) / rc.w : 0; break }
    }
    for (r = vr.r1; r <= vr.r2; r++) {
      rc = cellRect(ws, vr.c1, r)
      if (rc && my >= rc.y && my < rc.y + rc.h) { row = r; fy = rc.h ? (my - rc.y) / rc.h : 0; break }
    }
    return (col < 0 || row < 0) ? null : { col: col, row: row, fx: fx, fy: fy }
  }

  /**
   * Emit the spreadsheet internals + coordinate frame so they can be confirmed
   * from the MAIN app console (the host relays 'probe' messages there). Capped
   * and de-duplicated by visible range so scrolling yields a few useful samples
   * without spamming. Marked [FLOWONE-PROBE].
   */
  function probeCell() {
    if (state.probeCount >= 8) return
    var ws = cellWorksheet()
    var vr = ws ? cellVisibleRange(ws) : null
    var key = vr ? (vr.r1 + ',' + vr.c1 + ',' + vr.r2 + ',' + vr.c2) : 'none'
    if (key === state.lastProbeKey) return
    state.lastProbeKey = key
    state.probeCount += 1

    var o = { editorType: editorType(), n: state.probeCount, hasWs: !!ws, visibleRange: vr }
    try {
      if (ws) {
        o.wsMethods = Object.getOwnPropertyNames(Object.getPrototypeOf(ws))
          .filter(function (k) { return /cell|xy|coord|scroll|offset|visible|range|first|last/i.test(k) })
        o.firstVisibleCellRect = cellRect(ws, vr ? vr.c1 : 0, vr ? vr.r1 : 0)
        o.a1Rect = cellRect(ws, 0, 0)
      }
      var gr = gridCanvasRect(), er = editorArea().getBoundingClientRect()
      o.gridCanvasRect = { left: Math.round(gr.left), top: Math.round(gr.top), w: Math.round(gr.width), h: Math.round(gr.height) }
      o.editorRect = { left: Math.round(er.left), top: Math.round(er.top), w: Math.round(er.width), h: Math.round(er.height) }
    } catch (e) { o.err = String(e) }
    try { console.log('[FLOWONE-PROBE]', JSON.stringify(o)) } catch (e) { /* noop */ }
    postToHost({ type: 'probe', probe: o })
  }

  function removeLayer() {
    if (state.layer && state.layer.parentNode) {
      state.layer.parentNode.removeChild(state.layer)
    }
    state.layer = null
    state.els = {}
  }

  // ========================================================================
  // Host messaging
  // ========================================================================

  function postToHost(payload) {
    payload.flowonePresence = true
    payload.dir = 'plugin'
    try {
      window.top.postMessage(payload, host.origin || '*')
    } catch (e) { /* host gone */ }
  }

  function onHostMessage(event) {
    var data = event.data
    if (!data || data.flowonePresence !== true || data.dir !== 'host') return
    // Lock onto the first host that answers; ignore other origins after that.
    if (host.origin && event.origin !== host.origin) return
    host.win = event.source
    host.origin = event.origin

    if (data.type === 'init') {
      state.self = data.self || null
      stopReadyPing()
    } else if (data.type === 'cursors') {
      state.remote = Array.isArray(data.cursors) ? data.cursors : []
      render()
      followTick()
    } else if (data.type === 'follow') {
      state.followEmail = data.email || null
      followTick()
    }
  }

  function startReadyPing() {
    var attempts = 0
    state.readyTimer = setInterval(function () {
      if (state.self || ++attempts > 60) {
        stopReadyPing()
        return
      }
      postToHost({ type: 'ready' })
    }, READY_PING_MS)
    postToHost({ type: 'ready' })
  }

  function stopReadyPing() {
    if (state.readyTimer) {
      clearInterval(state.readyTimer)
      state.readyTimer = null
    }
  }

  // ========================================================================
  // Local cursor capture
  // ========================================================================

  function reportCursor() {
    if (!state.lastClient) return
    var type = editorType()
    if (type === 'word') { reportWordCursor() }
    else if (type === 'cell') { reportCellCursor() }
    // slide / unknown: nothing to anchor to yet
  }

  // Word: anchor vertically to the document Y so the cursor sticks to the text.
  function reportWordCursor() {
    var vp = viewport()
    var cx = state.lastClient.x
    var cy = state.lastClient.y
    if (cx < 0 || cy < 0 || cx > vp.w || cy > vp.h) return

    var sy = getScrollY()
    if (sy === null) return
    var xf = 0
    var dy = null
    try {
      var rect = editorArea().getBoundingClientRect()
      if (rect.width) xf = (cx - rect.left) / rect.width
      dy = (cy - rect.top) + sy
    } catch (e) { return }

    postToHost({
      type: 'cursor',
      cursor: {
        mode: 'word',
        xf: xf,          // fraction of the editor area width
        dy: dy,          // document-absolute y (anchors the cursor to the text)
        sy: sy,          // sender scroll position (drives follow mode)
        t: Date.now(),
      },
    })
  }

  /**
   * Excel: anchor to the CELL under the pointer (col/row + fraction inside the
   * cell), not screen pixels. The receiver re-resolves that cell to its own
   * on-screen position, so the cursor lands on the same cell no matter how
   * differently the two sides are scrolled, and is hidden when the cell is off
   * the viewer's screen. getCellCoord is reliable for visible cells (it clamps
   * off-screen ones, which is why a pixel-from-A1 anchor failed).
   */
  function reportCellCursor() {
    var ws = cellWorksheet()
    if (!ws) { probeCell(); return }
    var vr = cellVisibleRange(ws)
    var rect = gridCanvasRect()
    var mx = state.lastClient.x - rect.left
    var my = state.lastClient.y - rect.top
    if (mx < 0 || my < 0 || mx > rect.width || my > rect.height) return

    var hit = findCellAt(ws, vr, mx, my)
    if (!hit) { probeCell(); return }

    postToHost({
      type: 'cursor',
      cursor: {
        mode: 'cell',
        col: hit.col, row: hit.row,   // anchor cell (0-based)
        fx: hit.fx, fy: hit.fy,       // fractional position inside the cell
        sheet: cellSheetIndex(),      // hide when peers are on other tabs
        t: Date.now(),
      },
    })
  }

  function onMouseMove(e) {
    state.lastClient = { x: e.clientX, y: e.clientY }
    var now = Date.now()
    if (now - state.lastSend >= SEND_INTERVAL) {
      state.lastSend = now
      reportCursor()
    }
  }

  // ========================================================================
  // Remote cursor rendering (overlay in the editor document)
  // ========================================================================

  var CURSOR_SVG =
    '<svg width="16" height="22" viewBox="0 0 16 22" xmlns="http://www.w3.org/2000/svg">' +
    '<path d="M1 1 L1 16 L5 12.5 L8 20 L11 18.7 L8 11.5 L13.5 11 Z" ' +
    'fill="__COLOR__" stroke="white" stroke-width="1.2"/></svg>'

  function safeColor(c) {
    return /^#[0-9A-Fa-f]{3,8}$/.test(String(c || '')) ? c : '#1E88E5'
  }

  function ensureLayer() {
    if (state.layer && state.layer.parentNode) return state.layer
    var d = edoc()
    var layer = d.createElement('div')
    layer.id = 'flowone-presence-layer'
    layer.style.cssText =
      'position:fixed;left:0;top:0;right:0;bottom:0;pointer-events:none;' +
      'z-index:2147483000;overflow:hidden;'
    d.body.appendChild(layer)
    state.layer = layer
    return layer
  }

  function ensureCursorEl(user) {
    var el = state.els[user.email]
    if (el && el.parentNode) return el
    var d = edoc()
    var color = safeColor(user.color)
    el = d.createElement('div')
    el.style.cssText = 'position:fixed;pointer-events:none;display:none;'
    el.innerHTML = CURSOR_SVG.replace('__COLOR__', color)
    var label = d.createElement('div')
    label.textContent = user.name || user.email
    label.style.cssText =
      'position:absolute;left:12px;top:18px;white-space:nowrap;' +
      'background:' + color + ';color:#fff;font:600 11px/1.6 sans-serif;' +
      'padding:0 6px;border-radius:3px 8px 8px 8px;box-shadow:0 1px 3px rgba(0,0,0,.3);'
    el.appendChild(label)
    ensureLayer().appendChild(el)
    state.els[user.email] = el
    return el
  }

  function render() {
    var type = editorType()
    if (type === 'word') { renderWord(); return }
    probeCell()                     // relayed to the main app console
    if (type === 'cell') { renderCell(); return }
    removeLayer()                   // slide / unknown: inert for now
  }

  /** Remove cursor elements for everyone not in `seen` this frame. */
  function pruneCursors(seen) {
    for (var email in state.els) {
      if (!seen[email]) {
        var dead = state.els[email]
        if (dead && dead.parentNode) dead.parentNode.removeChild(dead)
        delete state.els[email]
      }
    }
  }

  function placeCursor(user, left, top, visible) {
    var el = ensureCursorEl(user)
    el.style.display = visible ? 'block' : 'none'
    if (visible) {
      el.style.left = left + 'px'
      el.style.top = top + 'px'
    }
  }

  function renderWord() {
    var mySy = getScrollY()
    if (mySy === null) { removeLayer(); return }
    var rect = editorArea().getBoundingClientRect()
    if (!ensureLayer()) return
    var seen = {}
    for (var i = 0; i < state.remote.length; i++) {
      var user = state.remote[i]
      var c = user && user.cursor
      if (!user || !user.email || !c || c.dy == null) continue
      seen[user.email] = true
      var left = rect.left + (c.xf || 0) * rect.width
      var top = rect.top + (c.dy - mySy)
      var visible = top >= rect.top - 24 && top <= rect.top + rect.height + 24
        && Date.now() - (c.t || 0) < 60000
      placeCursor(user, left, top, visible)
    }
    pruneCursors(seen)
  }

  function renderCell() {
    var ws = cellWorksheet()
    if (!ws) { removeLayer(); return }
    var vr = cellVisibleRange(ws)
    var rect = gridCanvasRect()
    var mySheet = cellSheetIndex()
    if (!ensureLayer()) return
    var seen = {}
    for (var i = 0; i < state.remote.length; i++) {
      var user = state.remote[i]
      var c = user && user.cursor
      if (!user || !user.email || !c || c.mode !== 'cell' || c.col == null) continue
      seen[user.email] = true
      // Resolve the sender's cell to OUR on-screen position. Visible only when
      // on the same sheet, the cell is within our view, and not gone idle.
      var sameSheet = (c.sheet == null || c.sheet === mySheet)
      var inRange = !!vr && c.row >= vr.r1 && c.row <= vr.r2 && c.col >= vr.c1 && c.col <= vr.c2
      var left = 0, top = 0, visible = false
      if (sameSheet && inRange && Date.now() - (c.t || 0) < 60000) {
        var rc = cellRect(ws, c.col, c.row)
        if (rc) {
          left = rect.left + rc.x + (c.fx || 0) * rc.w
          top = rect.top + rc.y + (c.fy || 0) * rc.h
          visible = true
        }
      }
      placeCursor(user, left, top, visible)
    }
    pruneCursors(seen)
  }

  // ========================================================================
  // Follow mode
  // ========================================================================

  function followTick() {
    if (!state.followEmail) return
    var target = null
    for (var i = 0; i < state.remote.length; i++) {
      if (state.remote[i].email === state.followEmail) {
        target = state.remote[i].cursor
        break
      }
    }
    if (!target || target.sy == null) return
    var mySy = getScrollY()
    if (mySy === null) return
    if (Math.abs(target.sy - mySy) > 4) {
      scrollDocTo(target.sy)
    }
  }

  function breakFollow() {
    if (!state.followEmail) return
    state.followEmail = null
    postToHost({ type: 'follow-stopped' })
  }

  // ========================================================================
  // Boot
  // ========================================================================

  function tick() {
    var type = editorType()
    if (type === 'word') {
      var sy = getScrollY()
      if (sy !== state.lastScrollY) {
        state.lastScrollY = sy
        reportCursor()            // followers track our viewport via sy
      }
    } else if (type === 'cell') {
      // No mouse move fires while scrolling, but our pointer now hovers a new
      // cell - re-report when the visible range (i.e. our scroll) shifts.
      var ws = cellWorksheet()
      var vr = ws ? cellVisibleRange(ws) : null
      var key = vr ? (vr.r1 + ':' + vr.c1) : null
      if (key !== state.lastCellKey) {
        state.lastCellKey = key
        reportCursor()
      }
    }
    // Re-render every tick so remote cursors reposition on our own scroll /
    // window resize even when no new cursor message arrives.
    render()
    followTick()
  }

  function boot() {
    // Reverted: custom cursors are off; rely on OnlyOffice native co-editing.
    if (!CURSORS_ENABLED) return

    var d = edoc() // throws if same-origin access is unavailable

    window.addEventListener('message', onHostMessage)
    d.addEventListener('mousemove', onMouseMove, true)
    d.addEventListener('wheel', breakFollow, true)
    d.addEventListener('mousedown', breakFollow, true)
    d.addEventListener('keydown', breakFollow, true)

    setInterval(tick, TICK_INTERVAL)
    startReadyPing()
  }

  window.Asc = window.Asc || {}
  window.Asc.plugin = window.Asc.plugin || {}

  window.Asc.plugin.init = function () {
    try {
      boot()
    } catch (e) {
      // Editor DOM not reachable - presence silently disabled.
    }
  }

  window.Asc.plugin.button = function () {}
  window.Asc.plugin.onExternalMouseUp = function () {}
})(window, undefined)
