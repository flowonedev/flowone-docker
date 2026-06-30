import { Container, Graphics, Text, TextStyle } from 'pixi.js'
import { getFillStyles, applyFills, applyTransform, getStyleProps, parseColor } from '../../utils/styleToPixi.js'

/**
 * Renders presentation slide frames (dashed border with label + badge).
 * Visual output must match MoodCanvasItem.vue slide rendering exactly.
 */

let _primaryColor = null

function getPrimaryColor() {
  if (_primaryColor) return _primaryColor
  try {
    const style = getComputedStyle(document.documentElement)
    const rgb = style.getPropertyValue('--color-primary-500').trim()
    if (rgb) {
      const parts = rgb.split(/\s+/)
      if (parts.length === 3) {
        _primaryColor = `rgb(${parts[0]}, ${parts[1]}, ${parts[2]})`
        return _primaryColor
      }
    }
  } catch {}
  _primaryColor = '#6366f1'
  return _primaryColor
}

function getSlideColor(item) {
  const c = item.color
  if (c && c !== '#e0e7ff') return c
  return getPrimaryColor()
}

export function createSlide(item) {
  const container = new Container()
  container.label = `slide-${item.id}`
  drawSlide(container, item)
  applyTransform(container, item)
  return container
}

export function updateSlide(container, item) {
  container.removeChildren()
  container.mask = null
  drawSlide(container, item)
  applyTransform(container, item)
}

function drawSlide(container, item) {
  const rawSd = item.style_data || {}
  const sd = getStyleProps('slide', rawSd)
  const w = item.width || 960
  const h = item.height || 540
  const fillStyles = getFillStyles(sd.fills)
  const slideAccent = getSlideColor(item)
  const { color: accentColor } = parseColor(slideAccent)
  const sw = rawSd.stroke_width ?? 2

  const body = new Container()
  body.label = 'slide-body'

  const bg = new Graphics()
  bg.roundRect(0, 0, w, h, 8)
  if (fillStyles.some(f => f.type !== 'image')) {
    applyFills(bg, fillStyles, w, h)
  } else {
    bg.fill({ color: accentColor, alpha: 0.06 })
  }
  body.addChild(bg)

  const border = new Graphics()
  drawDashedRect(border, 0, 0, w, h, 8)
  border.stroke({ color: accentColor, width: sw, alpha: 1 })
  body.addChild(border)

  drawCenterMarks(body, w, h, accentColor)

  if (sd.clip_content !== false) {
    const mask = new Graphics()
    mask.rect(0, 0, w, h).fill({ color: 0xffffff })
    body.addChild(mask)
    body.mask = mask
  }
  container.addChild(body)

  drawSlideLabel(container, item, w, accentColor)
}

function drawCenterMarks(body, w, h, color) {
  const marks = new Graphics()
  const markLen = 8
  marks.moveTo(w / 2, 0).lineTo(w / 2, markLen)
  marks.moveTo(w / 2, h - markLen).lineTo(w / 2, h)
  marks.moveTo(0, h / 2).lineTo(markLen, h / 2)
  marks.moveTo(w - markLen, h / 2).lineTo(w, h / 2)
  marks.stroke({ color, width: 1, alpha: 0.5 })
  body.addChild(marks)
}

const TEXT_RES = Math.max(window.devicePixelRatio || 1, 3)

function drawSlideLabel(container, item, slideW, accentColor) {
  const title = item.title || 'Slide'

  const titleStyle = new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 11,
    fontWeight: '600',
    fill: accentColor,
  })
  const titleText = new Text({ text: title, style: titleStyle, resolution: TEXT_RES })
  titleText.position.set(0, -22)
  container.addChild(titleText)

  let xCursor = titleText.width + 8

  const dimStr = `${Math.round(item.width || 0)} x ${Math.round(item.height || 0)}`
  const dimStyle = new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 10,
    fontWeight: '400',
    fill: 0x78909C,
  })
  const dimText = new Text({ text: dimStr, style: dimStyle, resolution: TEXT_RES })
  dimText.position.set(xCursor, -21)
  container.addChild(dimText)

  xCursor += dimText.width + 8

  if (item.slide_order != null) {
    const badgeNum = (item.slide_order ?? 0) + 1
    const badgeStyle = new TextStyle({
      fontFamily: 'Inter, sans-serif',
      fontSize: 9,
      fontWeight: '700',
      fill: 0xffffff,
    })
    const badgeText = new Text({ text: String(badgeNum), style: badgeStyle, resolution: TEXT_RES })

    const badgePad = 6
    const badgeW = badgeText.width + badgePad * 2 + 12
    const badgeH = 16
    const badgeBg = new Graphics()
    badgeBg.roundRect(xCursor, -24, badgeW, badgeH, badgeH / 2)
    badgeBg.fill({ color: accentColor })
    container.addChild(badgeBg)

    const iconStyle = new TextStyle({
      fontFamily: 'Material Symbols Rounded',
      fontSize: 10,
      fill: 0xffffff,
    })
    const icon = new Text({ text: 'slideshow', style: iconStyle, resolution: TEXT_RES })
    icon.position.set(xCursor + badgePad, -24 + (badgeH - 10) / 2)
    container.addChild(icon)

    badgeText.position.set(xCursor + badgePad + 12, -24 + (badgeH - 9) / 2)
    container.addChild(badgeText)
  }
}

function drawDashedRect(g, x, y, w, h, dashLen) {
  drawDashedLine(g, x, y, x + w, y, dashLen)
  drawDashedLine(g, x + w, y, x + w, y + h, dashLen)
  drawDashedLine(g, x + w, y + h, x, y + h, dashLen)
  drawDashedLine(g, x, y + h, x, y, dashLen)
}

function drawDashedLine(g, x1, y1, x2, y2, dashLen) {
  const dx = x2 - x1
  const dy = y2 - y1
  const len = Math.hypot(dx, dy)
  const dashes = Math.floor(len / (dashLen * 2))
  const ux = dx / len
  const uy = dy / len

  for (let i = 0; i < dashes; i++) {
    const sx = x1 + ux * i * dashLen * 2
    const sy = y1 + uy * i * dashLen * 2
    g.moveTo(sx, sy)
    g.lineTo(sx + ux * dashLen, sy + uy * dashLen)
  }
}
