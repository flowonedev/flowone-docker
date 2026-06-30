export function hexClean(color) {
  if (!color) return '333333'
  const c = String(color).trim()
  if (c.startsWith('#')) return c.slice(1).padEnd(6, '0')
  if (/^[0-9a-fA-F]{3,8}$/.test(c)) return c.padEnd(6, '0')
  const rgbMatch = c.match(/^rgba?\(\s*(\d+)\s*[,\s]\s*(\d+)\s*[,\s]\s*(\d+)/)
  if (rgbMatch) {
    const r = Math.min(255, Number(rgbMatch[1]))
    const g = Math.min(255, Number(rgbMatch[2]))
    const b = Math.min(255, Number(rgbMatch[3]))
    return ((1 << 24) | (r << 16) | (g << 8) | b).toString(16).slice(1)
  }
  return '333333'
}

export function escapeXml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

export function parseSd(item) {
  const sd = item.style_data || {}
  if (typeof sd === 'string') {
    try { return JSON.parse(sd) || {} } catch { return {} }
  }
  return sd
}

const MIN_EXPORT_DIM = 40
export function resolveItemDimensions(item) {
  const sd = parseSd(item)
  const intrW = sd.original_width || 0
  const intrH = sd.original_height || 0
  let w = Number(item.width) || 0
  let h = Number(item.height) || 0

  if (w > 0 && h > 0) return { w, h }

  const isImage = item.type === 'image' || item.type === 'image_set'
  if (!w && !h) {
    w = isImage ? 300 : 200
    h = (intrW > 0 && intrH > 0)
      ? Math.max(MIN_EXPORT_DIM, Math.round(w * (intrH / intrW)))
      : (isImage ? 225 : 200)
  } else if (w > 0 && !h) {
    h = (intrW > 0 && intrH > 0)
      ? Math.max(MIN_EXPORT_DIM, Math.round(w * (intrH / intrW)))
      : Math.max(MIN_EXPORT_DIM, Math.round(w * 0.75))
  } else if (h > 0 && !w) {
    w = (intrW > 0 && intrH > 0)
      ? Math.max(MIN_EXPORT_DIM, Math.round(h * (intrW / intrH)))
      : Math.max(MIN_EXPORT_DIM, Math.round(h / 0.75))
  }
  return { w, h }
}

export async function rasterizeSvg(svgString, width, height) {
  const blob = new Blob([svgString], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  try {
    const img = new Image()
    img.width = width
    img.height = height
    await new Promise((resolve, reject) => {
      img.onload = resolve
      img.onerror = reject
      img.src = url
    })
    const canvas = document.createElement('canvas')
    canvas.width = width * 2
    canvas.height = height * 2
    const ctx = canvas.getContext('2d')
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL('image/png')
  } finally {
    URL.revokeObjectURL(url)
  }
}
