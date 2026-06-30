import { Container, Graphics, Text, TextStyle } from 'pixi.js'
import { parseColor, applyTransform, drawRoundedRect } from '../../utils/styleToPixi.js'

const _textRes = Math.max(window.devicePixelRatio || 1, 3)

const NOTE_COLORS = {
  yellow: 0xFFF9C4,
  green: 0xC8E6C9,
  blue: 0xBBDEFB,
  pink: 0xF8BBD0,
  purple: 0xE1BEE7,
  orange: 0xFFE0B2,
  gray: 0xE0E0E0,
}

/**
 * Renders sticky note items.
 */
export function createNote(item) {
  const container = new Container()
  container.label = `note-${item.id}`
  drawNote(container, item)
  applyTransform(container, item)
  return container
}

export function updateNote(container, item) {
  container.removeChildren()
  drawNote(container, item)
  applyTransform(container, item)
}

function drawNote(container, item) {
  const sd = item.style_data || {}
  const w = item.width || 200
  const h = item.height || 200
  const noteColor = item.color || sd.note_color || 'yellow'
  const bgColor = NOTE_COLORS[noteColor] || NOTE_COLORS.yellow
  const radius = sd.cornerRadius || 8

  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, radius)
  bg.fill({ color: bgColor })
  container.addChild(bg)

  const shadowG = new Graphics()
  drawRoundedRect(shadowG, 2, 2, w, h, radius)
  shadowG.fill({ color: 0x000000, alpha: 0.08 })
  container.addChildAt(shadowG, 0)

  if (item.title) {
    const titleStyle = new TextStyle({
      fontFamily: 'Inter, sans-serif',
      fontSize: sd.text?.fontSize || 14,
      fontWeight: 'bold',
      fill: 0x333333,
      wordWrap: true,
      wordWrapWidth: w - 24,
    })
    const titleText = new Text({ text: item.title, style: titleStyle, resolution: _textRes })
    titleText.position.set(12, 12)
    container.addChild(titleText)
  }

  if (item.content) {
    const bodyStyle = new TextStyle({
      fontFamily: 'Inter, sans-serif',
      fontSize: (sd.text?.fontSize || 14) - 2,
      fill: 0x555555,
      wordWrap: true,
      wordWrapWidth: w - 24,
    })
    const bodyText = new Text({ text: item.content, style: bodyStyle, resolution: _textRes })
    bodyText.position.set(12, item.title ? 36 : 12)
    container.addChild(bodyText)
  }
}
