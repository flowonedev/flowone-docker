/**
 * Viewport-aware positioning for cursor-anchored context menus.
 *
 * Given the cursor coordinates and the rendered menu element, returns
 * coordinates that keep the menu fully inside the viewport:
 * - flips above the cursor when there is not enough room below
 * - flips left of the cursor when there is not enough room to the right
 * - clamps to the viewport edges as a last resort
 */
export function clampMenuToViewport(
  x: number,
  y: number,
  menuEl: HTMLElement | null,
  margin = 8,
): { x: number; y: number } {
  if (!menuEl) return { x, y }

  const menuWidth = menuEl.offsetWidth
  const menuHeight = menuEl.offsetHeight
  const viewportWidth = window.innerWidth
  const viewportHeight = window.innerHeight

  let left = x
  let top = y

  if (left + menuWidth > viewportWidth - margin) {
    left = x - menuWidth
  }
  if (top + menuHeight > viewportHeight - margin) {
    top = y - menuHeight
  }

  left = Math.min(left, viewportWidth - menuWidth - margin)
  top = Math.min(top, viewportHeight - menuHeight - margin)
  if (left < margin) left = margin
  if (top < margin) top = margin

  return { x: Math.round(left), y: Math.round(top) }
}
