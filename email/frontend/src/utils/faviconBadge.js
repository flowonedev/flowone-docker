/**
 * Dynamic favicon badge - overlays a red notification dot/count on the favicon.
 * Similar to how Facebook shows unread notifications on the browser tab icon.
 */

let originalFaviconUrl = null
let faviconCanvas = null
let faviconCtx = null
let faviconImg = null
let faviconReady = false

/**
 * Initialize by loading the original favicon into a canvas.
 */
function init() {
  if (faviconReady) return

  const link = document.getElementById('dynamic-favicon')
  if (!link) return

  originalFaviconUrl = link.href

  faviconCanvas = document.createElement('canvas')
  faviconCanvas.width = 64
  faviconCanvas.height = 64
  faviconCtx = faviconCanvas.getContext('2d')

  faviconImg = new Image()
  faviconImg.crossOrigin = 'anonymous'
  faviconImg.onload = () => {
    faviconReady = true
  }
  faviconImg.src = originalFaviconUrl
}

/**
 * Update the favicon with a notification badge.
 * @param {number} count - Unread count. 0 = no badge (restore original).
 */
function updateBadge(count) {
  if (!faviconReady || !faviconCtx || !faviconImg) {
    // Try to init and retry once after image loads
    init()
    if (faviconImg && !faviconReady) {
      faviconImg.onload = () => {
        faviconReady = true
        updateBadge(count)
      }
    }
    return
  }

  const link = document.getElementById('dynamic-favicon')
  if (!link) return

  const size = 64
  faviconCtx.clearRect(0, 0, size, size)

  // Draw original favicon
  faviconCtx.drawImage(faviconImg, 0, 0, size, size)

  if (count > 0) {
    // Draw red circle badge (top-right)
    const badgeRadius = count > 9 ? 16 : 14
    const badgeX = size - badgeRadius - 1
    const badgeY = badgeRadius + 1

    // Red circle with slight shadow
    faviconCtx.beginPath()
    faviconCtx.arc(badgeX, badgeY, badgeRadius + 2, 0, 2 * Math.PI)
    faviconCtx.fillStyle = '#ffffff'
    faviconCtx.fill()

    faviconCtx.beginPath()
    faviconCtx.arc(badgeX, badgeY, badgeRadius, 0, 2 * Math.PI)
    faviconCtx.fillStyle = '#ef4444'
    faviconCtx.fill()

    // Draw count text
    const text = count > 99 ? '99' : String(count)
    faviconCtx.fillStyle = '#ffffff'
    faviconCtx.font = `bold ${count > 9 ? 18 : 22}px sans-serif`
    faviconCtx.textAlign = 'center'
    faviconCtx.textBaseline = 'middle'
    faviconCtx.fillText(text, badgeX, badgeY + 1)

    // Update favicon to canvas data
    link.type = 'image/png'
    link.href = faviconCanvas.toDataURL('image/png')
  } else {
    // Restore original favicon (PNG since the rebrand)
    link.type = 'image/png'
    link.href = originalFaviconUrl
  }
}

export { init as initFaviconBadge, updateBadge as updateFaviconBadge }

