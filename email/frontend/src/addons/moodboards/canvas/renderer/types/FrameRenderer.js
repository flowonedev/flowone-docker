import { Container, Graphics, Text, TextStyle } from 'pixi.js'
import {
  getFillStyles, getStrokeStyle, getCornerRadius,
  applyFills, applyStroke, applyTransform, drawRoundedRect,
  getStyleProps, applyEffects
} from '../../utils/styleToPixi.js'
import { drawDashedShape } from '../../utils/dashedStroke.js'

/**
 * Renders frame, group, artboard, column, and repeat_grid items.
 * Children are rendered recursively by the ItemRenderer.
 */
export function createFrame(item) {
  const container = new Container()
  container.label = `frame-${item.id}`
  drawFrameBackground(container, item)
  applyTransform(container, item)
  return container
}

export function updateFrame(container, item) {
  const bg = container.children.find(c => c.label === 'frame-bg')
  const label = container.children.find(c => c.label === 'frame-label')
  const maskChild = container.mask
  if (bg) { container.removeChild(bg); bg.destroy() }
  if (label) { container.removeChild(label); label.destroy() }
  if (maskChild) { container.mask = null; maskChild.destroy?.() }
  container.filters = null
  drawFrameBackground(container, item)
  applyTransform(container, item)
}

function hasBlurOverlay(rawSd, sd) {
  if (rawSd.backdrop_blur_enabled || rawSd.frame_backdrop_blur > 0
    || (rawSd.backdrop_blur_enabled && rawSd.backdrop_blur_amount > 0)
    || sd.effects?.some(e => e.type === 'BACKGROUND_BLUR' && e.visible !== false && e.radius > 0))
    return true
  if (sd.effects?.some(e => e.type === 'LAYER_BLUR' && e.visible !== false && (e.radius ?? 0) > 0))
    return true
  return false
}

function drawFrameBackground(container, item) {
  const rawSd = item.style_data || {}
  const sd = getStyleProps('frame', rawSd)
  const w = item.width || 0
  const h = item.height || 0
  if (w <= 0 || h <= 0) return

  const fillStyles = getFillStyles(sd.fills)
  const strokeStyle = getStrokeStyle(sd.strokes)
  const radius = sd.cornerRadius ?? getCornerRadius(rawSd)

  if (hasBlurOverlay(rawSd, sd)) {
    const g = new Graphics()
    g.label = 'frame-bg'
    drawRoundedRect(g, 0, 0, w, h, radius || 0)
    g.fill({ color: 0x000000, alpha: 0.001 })
    container.addChildAt(g, 0)
  } else {
    if (fillStyles.length || strokeStyle) {
      const g = new Graphics()
      g.label = 'frame-bg'
      drawRoundedRect(g, 0, 0, w, h, radius)
      applyFills(g, fillStyles, w, h)

      if (strokeStyle?.dashPattern) {
        container.addChildAt(g, 0)
        const dashG = new Graphics()
        dashG.label = 'frame-border'
        drawDashedShape(dashG, 'rectangle', w, h, radius, rawSd, strokeStyle, strokeStyle.dashPattern)
        container.addChildAt(dashG, 1)
      } else {
        if (strokeStyle) applyStroke(g, strokeStyle)
        container.addChildAt(g, 0)
      }
    }

    if (sd.effects?.length) {
      applyEffects(container, sd.effects)
    }
  }

  if (rawSd.clip_content !== false && item.type !== 'group') {
    const mask = new Graphics()
    drawRoundedRect(mask, 0, 0, w, h, radius || 0)
    mask.fill({ color: 0xffffff })
    container.addChild(mask)
    container.mask = mask
  }

  if (item.title && item.type !== 'group') {
    const labelStyle = new TextStyle({
      fontFamily: 'Inter, sans-serif',
      fontSize: 11,
      fill: 0x999999,
      fontWeight: '500',
    })
    const label = new Text({ text: item.title, style: labelStyle, resolution: Math.max(window.devicePixelRatio || 1, 3) })
    label.label = 'frame-label'
    label.position.set(0, -16)
    container.addChild(label)
  }
}

/**
 * Compute auto-layout child positions (flex, flex-wrap, grid).
 */
export function computeAutoLayout(parentItem, children) {
  const sd = parentItem.style_data || {}
  const mode = sd.layout_mode || (sd.auto_layout ? 'flex' : null)
  if (!mode || mode === 'none') return null

  const padTop = sd.padding_top || sd.layout_padding || 0
  const padRight = sd.padding_right || sd.layout_padding || 0
  const padBottom = sd.padding_bottom || sd.layout_padding || 0
  const padLeft = sd.padding_left || sd.layout_padding || 0
  const parentW = parentItem.width || 0
  const parentH = parentItem.height || 0

  if (mode === 'grid') {
    return computeGridLayout(children, sd, padTop, padRight, padBottom, padLeft, parentW, parentH)
  }

  const direction = sd.layout_direction || 'vertical'
  const isRow = direction === 'row' || direction === 'horizontal'
  const gap = sd.layout_gap || 0
  const align = sd.layout_align || 'start'
  const wrap = !!sd.layout_wrap

  const positions = []
  let cursor = isRow ? padLeft : padTop
  let crossCursor = isRow ? padTop : padLeft
  let lineMaxCross = 0

  for (const child of children) {
    const cw = child.width || 0
    const ch = child.height || 0

    if (wrap && isRow && cursor + cw > parentW - padRight && positions.length > 0) {
      cursor = padLeft
      crossCursor += lineMaxCross + gap
      lineMaxCross = 0
    } else if (wrap && !isRow && cursor + ch > parentH - padBottom && positions.length > 0) {
      cursor = padTop
      crossCursor += lineMaxCross + gap
      lineMaxCross = 0
    }

    let x, y
    if (isRow) {
      x = cursor
      if (align === 'center') y = crossCursor + (parentH - padTop - padBottom - ch) / 2
      else if (align === 'end') y = parentH - padBottom - ch
      else y = crossCursor
      cursor += cw + gap
      lineMaxCross = Math.max(lineMaxCross, ch)
    } else {
      y = cursor
      if (align === 'center') x = crossCursor + (parentW - padLeft - padRight - cw) / 2
      else if (align === 'end') x = parentW - padRight - cw
      else x = crossCursor
      cursor += ch + gap
      lineMaxCross = Math.max(lineMaxCross, cw)
    }

    positions.push({ id: child.id, x, y })
  }

  return positions
}

function computeGridLayout(children, sd, padTop, padRight, padBottom, padLeft, parentW, parentH) {
  const cols = sd.grid_columns || 3
  const rows = sd.grid_rows || Math.ceil(children.length / cols)
  const hGap = sd.grid_h_gap ?? sd.layout_gap ?? 20
  const vGap = sd.grid_v_gap ?? sd.layout_gap ?? 20

  const availW = parentW - padLeft - padRight
  const availH = parentH - padTop - padBottom
  const cellW = (availW - (cols - 1) * hGap) / cols
  const cellH = (availH - (rows - 1) * vGap) / rows

  const positions = []
  for (let i = 0; i < children.length; i++) {
    const child = children[i]
    const childSd = child.style_data || {}
    let col = i % cols
    let row = Math.floor(i / cols)
    if (childSd.grid_column) {
      const gc = parseInt(childSd.grid_column)
      if (!isNaN(gc)) col = gc - 1
    }
    if (childSd.grid_row) {
      const gr = parseInt(childSd.grid_row)
      if (!isNaN(gr)) row = gr - 1
    }
    const x = padLeft + col * (cellW + hGap)
    const y = padTop + row * (cellH + vGap)
    positions.push({ id: child.id, x, y })
  }

  return positions
}
