import { screenToCanvas } from '../utils/coordTransform.js'

const MASKABLE_TYPES = new Set(['shape', 'pen_shape', 'text'])

/**
 * Handles file drops, Drive file drops, and URL text drops on the canvas.
 */
export default class CanvasDropHandler {
  constructor(store, container, callbacks, spatialIndex = null) {
    this._store = store
    this._container = container
    this._cb = callbacks
    this._spatial = spatialIndex
    this._onDragOver = this._onDragOver.bind(this)
    this._onDrop = this._onDrop.bind(this)
  }

  attach() {
    this._container.addEventListener('dragover', this._onDragOver)
    this._container.addEventListener('drop', this._onDrop)
  }

  detach() {
    this._container.removeEventListener('dragover', this._onDragOver)
    this._container.removeEventListener('drop', this._onDrop)
  }

  _onDragOver(e) {
    e.preventDefault()
    e.dataTransfer.dropEffect = 'copy'
  }

  async _onDrop(e) {
    e.preventDefault()
    const pos = this._toCanvas(e.clientX, e.clientY)

    const driveData = e.dataTransfer.getData('application/x-mood-drive-file')
    if (driveData) {
      try {
        const parsed = JSON.parse(driveData)
        this._cb.onDriveFileDrop?.(parsed, pos)
      } catch { /* ignore parse errors */ }
      return
    }

    const textData = e.dataTransfer.getData('text/plain')
    if (textData && /^https?:\/\//i.test(textData.trim())) {
      this._handleUrlDrop(textData.trim(), pos)
      return
    }

    const files = Array.from(e.dataTransfer.files || [])
    if (files.length) {
      await this._handleFileDrop(files, pos)
    }
  }

  _handleUrlDrop(url, pos) {
    const ytMatch = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/)
    if (ytMatch) {
      this._cb.onAddItem?.({
        type: 'youtube',
        pos_x: pos.x, pos_y: pos.y,
        width: 480, height: 270,
        style_data: { youtube_id: ytMatch[1] },
        url,
      })
    } else {
      this._cb.onAddItem?.({
        type: 'link',
        pos_x: pos.x, pos_y: pos.y,
        width: 240, height: 80,
        url, title: url,
      })
    }
  }

  async _handleFileDrop(files, pos) {
    const sketchFile = files.find(f => f.name.endsWith('.sketch'))
    if (sketchFile) {
      this._cb.onSketchImport?.(sketchFile)
      return
    }

    const svgFile = files.find(f => f.type === 'image/svg+xml' || f.name.endsWith('.svg'))
    if (svgFile) {
      this._cb.onSvgImport?.(svgFile, pos)
      return
    }

    const images = files.filter(f => f.type.startsWith('image/'))
    const videos = files.filter(f => f.type.startsWith('video/'))
    const audios = files.filter(f => f.type.startsWith('audio/'))
    const eps = files.filter(f => /\.(eps|ai)$/i.test(f.name) || f.type.includes('postscript'))
    const others = files.filter(f =>
      !f.type.startsWith('image/') && !f.type.startsWith('video/') &&
      !f.type.startsWith('audio/') && !f.name.endsWith('.sketch') &&
      !f.name.endsWith('.svg') && !/\.(eps|ai)$/i.test(f.name)
    )

    if (images.length === 1) {
      const shapeTarget = this._findMaskableAtPoint(pos)
      if (shapeTarget) {
        this._cb.onShapeMaskDrop?.(images[0], shapeTarget, pos)
      } else {
        this._cb.onImageDrop?.(images[0], pos)
      }
    } else if (images.length > 1) {
      this._cb.onImageSetDrop?.(images, pos)
    }

    for (const video of videos) {
      this._cb.onVideoDrop?.(video, pos)
      pos = { x: pos.x + 20, y: pos.y + 20 }
    }
    for (const audio of audios) {
      this._cb.onAudioDrop?.(audio, pos)
      pos = { x: pos.x + 20, y: pos.y + 20 }
    }
    for (const file of [...eps, ...others]) {
      this._cb.onFileDrop?.(file, pos)
      pos = { x: pos.x + 20, y: pos.y + 20 }
    }
  }

  _findMaskableAtPoint(pos) {
    if (!this._spatial) return null
    const hits = this._spatial.queryPoint(pos.x, pos.y)
    const items = this._store.currentBoard?.items || []
    for (const hit of hits) {
      const item = items.find(i => i.id === hit.id)
      if (!item) continue
      if (!MASKABLE_TYPES.has(item.type)) continue
      if (item.locked === true || item.locked === 1 || item.locked === '1') continue
      return item
    }
    return null
  }

  _toCanvas(screenX, screenY) {
    const rect = this._container.getBoundingClientRect()
    return screenToCanvas(
      screenX - rect.left,
      screenY - rect.top,
      this._store.panX,
      this._store.panY,
      this._store.zoom,
    )
  }
}
