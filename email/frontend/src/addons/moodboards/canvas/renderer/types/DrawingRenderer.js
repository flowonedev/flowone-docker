import { Container, Graphics } from 'pixi.js'
import { applyTransform } from '../../utils/styleToPixi.js'

/**
 * Drawing visuals are rendered by DOM SVG in MoodCanvasItem.vue (vector-crisp at any zoom,
 * correct fill rules for self-intersecting perfect-freehand outlines).
 * Pixi provides only an invisible hit-area for interaction — same pattern as TextRenderer.
 */
export function createDrawing(item) {
  const container = new Container()
  container.label = `drawing-${item.id}`
  const w = Number(item.width) || 200
  const h = Number(item.height) || 150
  const hitArea = new Graphics()
  hitArea.rect(0, 0, w, h).fill({ color: 0xffffff, alpha: 0.001 })
  container.addChild(hitArea)
  container.alpha = 0
  applyTransform(container, item)
  return container
}

export function updateDrawing(container, item) {
  container.removeChildren()
  const w = Number(item.width) || 200
  const h = Number(item.height) || 150
  const hitArea = new Graphics()
  hitArea.rect(0, 0, w, h).fill({ color: 0xffffff, alpha: 0.001 })
  container.addChild(hitArea)
  container.alpha = 0
  applyTransform(container, item)
}
