import { Container, Graphics } from 'pixi.js'
import { applyTransform } from '../../utils/styleToPixi.js'

function resolveTextBounds(item) {
  const sd = item.style_data || {}
  const w = item.width || 200
  let h = item.height || 0
  if (!h) {
    const fontSize = sd.font_size || sd.fontSize || 16
    const lineHeight = sd.line_height || 1.4
    const content = item.content || ''
    const lines = Math.max(1, (content.match(/<br|<\/p>|<\/div>|\n/gi) || []).length + 1)
    h = Math.max(40, Math.ceil(fontSize * lineHeight * lines + 16))
  }
  return { w, h }
}

export function createText(item) {
  const container = new Container()
  container.label = `text-${item.id}`
  const { w, h } = resolveTextBounds(item)
  const hitArea = new Graphics()
  hitArea.rect(0, 0, w, h).fill({ color: 0xffffff, alpha: 0.001 })
  container.addChild(hitArea)
  container.alpha = 0
  applyTransform(container, item)
  return container
}

export function updateText(container, item) {
  container.removeChildren()
  const { w, h } = resolveTextBounds(item)
  const hitArea = new Graphics()
  hitArea.rect(0, 0, w, h).fill({ color: 0xffffff, alpha: 0.001 })
  container.addChild(hitArea)
  container.alpha = 0
  applyTransform(container, item)
}
