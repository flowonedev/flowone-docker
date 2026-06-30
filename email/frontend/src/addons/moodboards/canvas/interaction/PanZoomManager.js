import { screenToCanvas } from '../utils/coordTransform.js'

/**
 * Manages pan/zoom interactions for the PixiJS canvas stage.
 * Handles wheel, touch pinch, space+drag, middle-click drag.
 */
export default class PanZoomManager {
  constructor(store, app, container, { onActivity, onTransform } = {}) {
    this._store = store
    this._app = app
    this._container = container
    this._onActivity = onActivity || (() => {})
    this._onTransform = onTransform || (() => {})
    this._spaceHeld = false
    this._isPanning = false
    this._panStart = null
    this._pinchStartDist = null
    this._pinchStartZoom = null
    this._pinchMid = null
    this._lastTouches = null
    this._saveTimer = null
    this._animFrame = null
    this._animating = false

    this._wheelRaf = null
    this._pendingPanDx = 0
    this._pendingPanDy = 0
    this._pendingZoomDelta = 0
    this._pendingZoomScreen = null

    this._onWheel = this._onWheel.bind(this)
    this._onTouchStart = this._onTouchStart.bind(this)
    this._onTouchMove = this._onTouchMove.bind(this)
    this._onTouchEnd = this._onTouchEnd.bind(this)
    this._onMiddleDown = this._onMiddleDown.bind(this)
    this._onMiddleMove = this._onMiddleMove.bind(this)
    this._onMiddleUp = this._onMiddleUp.bind(this)
  }

  get zoom() { return this._store.zoom }
  get panX() { return this._store.panX }
  get panY() { return this._store.panY }

  attach() {
    const el = this._container
    el.addEventListener('wheel', this._onWheel, { passive: false })
    el.addEventListener('touchstart', this._onTouchStart, { passive: false })
    el.addEventListener('touchmove', this._onTouchMove, { passive: false })
    el.addEventListener('touchend', this._onTouchEnd)
    el.addEventListener('pointerdown', this._onMiddleDown)
  }

  detach() {
    const el = this._container
    el.removeEventListener('wheel', this._onWheel)
    el.removeEventListener('touchstart', this._onTouchStart)
    el.removeEventListener('touchmove', this._onTouchMove)
    el.removeEventListener('touchend', this._onTouchEnd)
    el.removeEventListener('pointerdown', this._onMiddleDown)
    document.removeEventListener('pointermove', this._onMiddleMove)
    document.removeEventListener('pointerup', this._onMiddleUp)
    this._cancelAnimation()
    if (this._wheelRaf) { cancelAnimationFrame(this._wheelRaf); this._wheelRaf = null }
    clearTimeout(this._saveTimer)
  }

  setSpaceHeld(held) {
    this._spaceHeld = held
    if (!held && this._isPanning) {
      this._isPanning = false
      this._panStart = null
      this._scheduleSaveViewport()
    }
  }

  startSpacePan(screenX, screenY) {
    if (!this._spaceHeld) return false
    this._isPanning = true
    this._panStart = { x: screenX, y: screenY, panX: this.panX, panY: this.panY }
    this._store.isPanning = true
    return true
  }

  moveSpacePan(screenX, screenY) {
    if (!this._isPanning || !this._panStart) return
    this._store.panX = this._panStart.panX + (screenX - this._panStart.x)
    this._store.panY = this._panStart.panY + (screenY - this._panStart.y)
    this._updateStage()
    this._onActivity()
  }

  endSpacePan() {
    if (!this._isPanning) return
    this._isPanning = false
    this._panStart = null
    this._store.isPanning = false
    this._scheduleSaveViewport()
  }

  get isPanning() { return this._isPanning }
  get isSpaceHeld() { return this._spaceHeld }

  _onWheel(e) {
    e.preventDefault()
    const isZoom = e.ctrlKey || e.metaKey || e.altKey

    if (isZoom) {
      const rect = this._container.getBoundingClientRect()
      this._pendingZoomScreen = {
        mx: e.clientX - rect.left,
        my: e.clientY - rect.top,
      }
      this._pendingZoomDelta += -e.deltaY * 0.003
    } else {
      const multiplier = e.deltaMode === 1 ? 16 : 1
      this._pendingPanDx -= e.deltaX * multiplier
      this._pendingPanDy -= e.deltaY * multiplier
    }

    this._onActivity()
    this._store.stopFollowing?.()

    if (!this._wheelRaf) {
      this._wheelRaf = requestAnimationFrame(() => {
        this._wheelRaf = null
        this._flushWheel()
      })
    }
  }

  _flushWheel() {
    if (this._pendingZoomScreen && this._pendingZoomDelta) {
      const { mx, my } = this._pendingZoomScreen
      this._zoomToPoint(mx, my, this._pendingZoomDelta)
    }
    this._pendingZoomDelta = 0
    this._pendingZoomScreen = null

    if (this._pendingPanDx || this._pendingPanDy) {
      this._store.panX += this._pendingPanDx
      this._store.panY += this._pendingPanDy
      this._pendingPanDx = 0
      this._pendingPanDy = 0
      this._updateStage()
      this._scheduleSaveViewport()
    }
  }

  _zoomToPoint(screenX, screenY, delta) {
    const oldZoom = this.zoom
    const newZoom = Math.max(0.005, oldZoom * (1 + delta))
    const canvasPt = screenToCanvas(screenX, screenY, this.panX, this.panY, oldZoom)
    this._store.zoom = newZoom
    this._store.panX = screenX - canvasPt.x * newZoom
    this._store.panY = screenY - canvasPt.y * newZoom
    this._updateStage()
    this._scheduleSaveViewport()
  }

  _onTouchStart(e) {
    e.preventDefault()
    if (e.touches.length === 2) {
      const [t0, t1] = e.touches
      this._pinchStartDist = Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY)
      this._pinchStartZoom = this.zoom
      const rect = this._container.getBoundingClientRect()
      this._pinchMid = {
        x: (t0.clientX + t1.clientX) / 2 - rect.left,
        y: (t0.clientY + t1.clientY) / 2 - rect.top,
      }
      this._lastTouches = [
        { x: t0.clientX, y: t0.clientY },
        { x: t1.clientX, y: t1.clientY },
      ]
    } else if (e.touches.length === 1) {
      this._panStart = {
        x: e.touches[0].clientX,
        y: e.touches[0].clientY,
        panX: this.panX,
        panY: this.panY,
      }
      this._isPanning = true
    }
    this._store.stopFollowing?.()
  }

  _onTouchMove(e) {
    e.preventDefault()
    if (e.touches.length === 2 && this._pinchStartDist) {
      const [t0, t1] = e.touches
      const dist = Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY)
      const scale = dist / this._pinchStartDist
      const rect = this._container.getBoundingClientRect()
      const mx = (t0.clientX + t1.clientX) / 2 - rect.left
      const my = (t0.clientY + t1.clientY) / 2 - rect.top

      if (this._lastTouches) {
        const prevMx = (this._lastTouches[0].x + this._lastTouches[1].x) / 2
        const prevMy = (this._lastTouches[0].y + this._lastTouches[1].y) / 2
        this._store.panX += (mx + rect.left) - prevMx
        this._store.panY += (my + rect.top) - prevMy
      }

      const canvasPt = screenToCanvas(mx, my, this.panX, this.panY, this.zoom)
      this._store.zoom = Math.max(0.005, this._pinchStartZoom * scale)
      this._store.panX = mx - canvasPt.x * this._store.zoom
      this._store.panY = my - canvasPt.y * this._store.zoom

      this._lastTouches = [
        { x: t0.clientX, y: t0.clientY },
        { x: t1.clientX, y: t1.clientY },
      ]
      this._updateStage()
      this._onActivity()
    } else if (e.touches.length === 1 && this._panStart) {
      const t = e.touches[0]
      this._store.panX = this._panStart.panX + (t.clientX - this._panStart.x)
      this._store.panY = this._panStart.panY + (t.clientY - this._panStart.y)
      this._updateStage()
      this._onActivity()
    }
  }

  _onTouchEnd(e) {
    if (e.touches.length < 2) {
      this._pinchStartDist = null
      this._pinchStartZoom = null
      this._lastTouches = null
    }
    if (e.touches.length === 0) {
      this._isPanning = false
      this._panStart = null
      this._scheduleSaveViewport()
    }
  }

  _onMiddleDown(e) {
    if (e.button !== 1) return
    e.preventDefault()
    this._isPanning = true
    this._panStart = { x: e.clientX, y: e.clientY, panX: this.panX, panY: this.panY }
    document.addEventListener('pointermove', this._onMiddleMove)
    document.addEventListener('pointerup', this._onMiddleUp)
  }

  _onMiddleMove(e) {
    if (!this._panStart) return
    this._store.panX = this._panStart.panX + (e.clientX - this._panStart.x)
    this._store.panY = this._panStart.panY + (e.clientY - this._panStart.y)
    this._updateStage()
    this._onActivity()
  }

  _onMiddleUp() {
    this._isPanning = false
    this._panStart = null
    document.removeEventListener('pointermove', this._onMiddleMove)
    document.removeEventListener('pointerup', this._onMiddleUp)
    this._scheduleSaveViewport()
  }

  zoomIn() {
    const rect = this._container.getBoundingClientRect()
    this._zoomToPoint(rect.width / 2, rect.height / 2, 0.15)
  }

  zoomOut() {
    const rect = this._container.getBoundingClientRect()
    this._zoomToPoint(rect.width / 2, rect.height / 2, -0.15)
  }

  zoomReset() {
    this._store.zoom = 1
    this._store.panX = 0
    this._store.panY = 0
    this._updateStage()
    this._scheduleSaveViewport()
  }

  fitScreen(items) {
    if (!items || items.length === 0) return
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const item of items) {
      const x = item.pos_x || 0
      const y = item.pos_y || 0
      const w = item.width || 0
      const h = item.height || 0
      if (x < minX) minX = x
      if (y < minY) minY = y
      if (x + w > maxX) maxX = x + w
      if (y + h > maxY) maxY = y + h
    }
    const pad = 80
    const rect = this._container.getBoundingClientRect()
    const vw = rect.width - pad * 2
    const vh = rect.height - pad * 2
    const bw = maxX - minX
    const bh = maxY - minY
    if (bw <= 0 || bh <= 0) return
    const z = Math.min(vw / bw, vh / bh, 3)
    this._store.zoom = z
    this._store.panX = (rect.width - bw * z) / 2 - minX * z
    this._store.panY = (rect.height - bh * z) / 2 - minY * z
    this._updateStage()
    this._scheduleSaveViewport()
  }

  animateToFrame(frame, duration = 500, transition = 'fly', padding = 40, viewportOverride = null) {
    return new Promise((resolve) => {
      if (!frame) { resolve(); return }

      const rect = viewportOverride
        ? { width: viewportOverride.width, height: viewportOverride.height }
        : this._container.getBoundingClientRect()

      const fw = frame.width || 200
      const fh = frame.height || 200
      const fx = frame.pos_x || 0
      const fy = frame.pos_y || 0

      const scaleX = (rect.width - padding * 2) / fw
      const scaleY = (rect.height - padding * 2) / fh
      const targetZ = Math.max(0.005, Math.min(scaleX, scaleY))

      const targetCX = fx + fw / 2
      const targetCY = fy + fh / 2
      const targetPanX = (rect.width / 2) - targetCX * targetZ
      const targetPanY = (rect.height / 2) - targetCY * targetZ

      if (transition === 'instant' || duration <= 0) {
        this._store.zoom = targetZ
        this._store.panX = targetPanX
        this._store.panY = targetPanY
        this._updateStage()
        resolve()
        return
      }

      this._cancelAnimation()

      const startZ = this.zoom
      const startCX = (rect.width / 2 - this.panX) / startZ
      const startCY = (rect.height / 2 - this.panY) / startZ
      const startLogZ = Math.log(startZ)
      const targetLogZ = Math.log(targetZ)

      const zoomRatio = Math.abs(targetLogZ - startLogZ) / Math.LN2
      const worldDist = Math.sqrt((targetCX - startCX) ** 2 + (targetCY - startCY) ** 2)
      const avgZoom = (startZ + targetZ) / 2
      const screenDist = (worldDist * avgZoom) / Math.max(rect.width, rect.height)
      const travelFactor = Math.max(1, zoomRatio * 0.8, screenDist * 0.5)
      const effectiveDuration = Math.round(Math.min(duration * travelFactor, 2500))

      const startTime = performance.now()
      this._animating = true

      function easeInOutCubic(t) {
        return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2
      }

      const animate = (now) => {
        const elapsed = now - startTime
        const progress = Math.min(1, elapsed / effectiveDuration)
        const eased = easeInOutCubic(progress)

        const cx = startCX + (targetCX - startCX) * eased
        const cy = startCY + (targetCY - startCY) * eased
        const z = Math.exp(startLogZ + (targetLogZ - startLogZ) * eased)

        this._store.zoom = z
        this._store.panX = (rect.width / 2) - cx * z
        this._store.panY = (rect.height / 2) - cy * z
        this._updateStage()

        if (progress < 1) {
          this._animFrame = requestAnimationFrame(animate)
        } else {
          this._animating = false
          this._scheduleSaveViewport()
          resolve()
        }
      }
      this._animFrame = requestAnimationFrame(animate)
    })
  }

  panToCanvasPoint(cx, cy, duration = 150) {
    const rect = this._container.getBoundingClientRect()
    const targetPanX = rect.width / 2 - cx * this.zoom
    const targetPanY = rect.height / 2 - cy * this.zoom

    if (duration <= 0) {
      this._store.panX = targetPanX
      this._store.panY = targetPanY
      this._updateStage()
      return
    }

    this._cancelAnimation()
    const startPanX = this.panX
    const startPanY = this.panY
    const startTime = performance.now()
    this._animating = true

    const animate = (now) => {
      const elapsed = now - startTime
      const t = Math.min(elapsed / duration, 1)
      const ease = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2
      this._store.panX = startPanX + (targetPanX - startPanX) * ease
      this._store.panY = startPanY + (targetPanY - startPanY) * ease
      this._updateStage()

      if (t < 1) {
        this._animFrame = requestAnimationFrame(animate)
      } else {
        this._animating = false
      }
    }
    this._animFrame = requestAnimationFrame(animate)
  }

  _cancelAnimation() {
    if (this._animFrame) {
      cancelAnimationFrame(this._animFrame)
      this._animFrame = null
    }
    this._animating = false
  }

  get isAnimating() { return this._animating }

  _updateStage() {
    if (!this._app?.stage) return
    const stage = this._app.stage
    stage.position.set(this._store.panX, this._store.panY)
    stage.scale.set(this._store.zoom)
    this._onTransform()
  }

  _scheduleSaveViewport() {
    clearTimeout(this._saveTimer)
    this._saveTimer = setTimeout(() => {
      this._store.saveViewport?.()
    }, 500)
  }
}
