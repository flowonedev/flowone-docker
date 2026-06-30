const DEFAULT_PAGE = { width: 1600, height: 900 }

function esc(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function resolveUrl(url) {
  if (!url) return ''
  if (url.startsWith('data:') || url.startsWith('http://') || url.startsWith('https://')) return url
  if (url.startsWith('/')) return `${window.location.origin}${url}`
  return url
}

function parseSd(item) {
  const sd = item?.style_data
  if (!sd) return {}
  if (typeof sd === 'string') { try { return JSON.parse(sd) || {} } catch { return {} } }
  return sd
}

function parseJson(raw) {
  if (!raw) return null
  if (typeof raw === 'object') return raw
  try { return JSON.parse(raw) } catch { return null }
}

function hexToRgba(hex, alpha) {
  const h = String(hex || '#000').trim()
  if (!h.startsWith('#') || h.length < 7) return `rgba(0,0,0,${alpha})`
  return `rgba(${parseInt(h.slice(1, 3), 16)},${parseInt(h.slice(3, 5), 16)},${parseInt(h.slice(5, 7), 16)},${alpha})`
}

function contrastColor(hex) {
  const h = String(hex || '#000').replace('#', '')
  const r = parseInt(h.slice(0, 2), 16) || 0
  const g = parseInt(h.slice(2, 4), 16) || 0
  const b = parseInt(h.slice(4, 6), 16) || 0
  return (r * 0.299 + g * 0.587 + b * 0.114) > 150 ? '#111827' : '#ffffff'
}

function stripHtml(html) {
  if (!html) return ''
  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>\s*<p[^>]*>/gi, '\n')
    .replace(/<\/div>\s*<div[^>]*>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&nbsp;/g, ' ')
}

function isVisible(item) {
  return !(parseSd(item)._hidden)
}

const MIN_EXPORT_DIM = 40
function resolveItemDims(item) {
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

function getSortedSlides(board) {
  return (board?.items || [])
    .filter(i => i.type === 'slide' && isVisible(i))
    .sort((a, b) => (a.slide_order ?? 9999) - (b.slide_order ?? 9999))
}

function getVisibleNonSlideItems(board) {
  return (board?.items || []).filter(i => i.type !== 'slide' && isVisible(i))
}

function buildPages(board) {
  const slides = getSortedSlides(board)
  const items = getVisibleNonSlideItems(board)

  if (slides.length) {
    return slides.map(slide => {
      const sx = Number(slide.pos_x || 0)
      const sy = Number(slide.pos_y || 0)
      const sw = Math.max(Number(slide.width || DEFAULT_PAGE.width), 1)
      const sh = Math.max(Number(slide.height || DEFAULT_PAGE.height), 1)

      const visible = items.filter(item => {
        const ix = Number(item.pos_x || 0)
        const iy = Number(item.pos_y || 0)
        const d = resolveItemDims(item)
        return !(ix + d.w < sx || ix > sx + sw || iy + d.h < sy || iy > sy + sh)
      })

      return { originX: sx, originY: sy, width: sw, height: sh, items: visible }
    })
  }

  if (!items.length) {
    return [{ originX: 0, originY: 0, width: DEFAULT_PAGE.width, height: DEFAULT_PAGE.height, items: [] }]
  }

  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const item of items) {
    const x = Number(item.pos_x || 0)
    const y = Number(item.pos_y || 0)
    const d = resolveItemDims(item)
    minX = Math.min(minX, x)
    minY = Math.min(minY, y)
    maxX = Math.max(maxX, x + d.w)
    maxY = Math.max(maxY, y + d.h)
  }
  const pad = 32
  return [{
    originX: minX - pad,
    originY: minY - pad,
    width: Math.max(DEFAULT_PAGE.width, maxX - minX + pad * 2),
    height: Math.max(DEFAULT_PAGE.height, maxY - minY + pad * 2),
    items,
  }]
}

function buildBackgroundCss(board) {
  const fx = parseJson(board?.background_effect)
  const layers = []
  const sizes = []

  if (fx?.gradient?.enabled) {
    const angle = fx.gradient.angle ?? 135
    const opacity = (fx.gradient.opacity ?? 30) / 100
    layers.push(`linear-gradient(${angle}deg, ${hexToRgba(fx.gradient.from || '#000', opacity)}, ${hexToRgba(fx.gradient.to || '#fff', opacity)})`)
    sizes.push('cover')
  }
  if (fx?.vignette?.enabled) {
    const intensity = (fx.vignette.intensity ?? 40) / 100
    layers.push(`radial-gradient(ellipse at center, transparent ${fx.vignette.spread ?? 60}%, rgba(0,0,0,${intensity}) 100%)`)
    sizes.push('cover')
  }
  if (board?.background_image) {
    layers.push(`url("${resolveUrl(board.background_image)}")`)
    sizes.push(board.background_image_size === 'repeat' ? 'auto' : (board.background_image_size || 'cover'))
  }

  let css = `background-color: ${board?.background_color || '#f5f5f5'};`
  if (layers.length) {
    css += ` background-image: ${layers.join(', ')};`
    css += ` background-size: ${sizes.join(', ')};`
    css += ` background-repeat: no-repeat;`
    css += ` background-position: center;`
  }
  return css
}

function itemStyle(item, page) {
  const x = Number(item.pos_x || 0) - page.originX
  const y = Number(item.pos_y || 0) - page.originY
  const d = resolveItemDims(item)
  const rot = Number(item.rotation || 0)
  let s = `position:absolute; left:${x}px; top:${y}px; width:${d.w}px; height:${d.h}px; overflow:hidden;`
  if (rot) s += ` transform:rotate(${rot}deg);`
  return s
}

function renderImageItem(item, page) {
  const sd = parseSd(item)
  const url = resolveUrl(item.image_url || item.thumbnail_url || '')
  if (!url) return ''
  const br = sd.border_radius || sd.shape_border_radius || 0
  const borderCss = br > 0 ? `border-radius:${br}px;` : ''
  const opacity = sd.opacity != null ? `opacity:${sd.opacity};` : ''
  return `<div style="${itemStyle(item, page)} ${opacity}">
    <img src="${esc(url)}" alt="${esc(item.title || '')}" style="width:100%; height:100%; object-fit:cover; display:block; ${borderCss}" />
  </div>`
}

function buildGradientCss(fillType, gradient) {
  if (!fillType || fillType === 'solid') return null
  const stops = gradient?.stops
  if (!stops || stops.length < 2) return null
  const stopsStr = [...stops].sort((a, b) => a.position - b.position)
    .map(s => `${s.color} ${s.position}%`).join(', ')
  if (fillType === 'radial') return `radial-gradient(circle, ${stopsStr})`
  return `linear-gradient(${gradient?.angle ?? 180}deg, ${stopsStr})`
}

function renderTextItem(item, page) {
  const sd = parseSd(item)
  const content = item.content || ''
  if (!content.trim()) return ''

  const fontSize = sd.font_size || 16
  const color = sd.text_color || '#1f2937'
  const fontFamily = sd.font_family || 'Inter, Arial, sans-serif'
  const fontWeight = sd.font_weight || 'normal'
  const textAlign = sd.text_align || 'left'
  const lineHeight = sd.line_height || 1.4
  const letterSpacing = sd.letter_spacing ? `letter-spacing:${sd.letter_spacing}px;` : ''
  const textTransform = sd.text_transform ? `text-transform:${sd.text_transform};` : ''
  const padding = sd.text_padding || 0
  const opacity = sd.opacity != null ? `opacity:${sd.opacity};` : ''

  let gradientStyle = ''
  const gradientCss = buildGradientCss(sd.text_fill_type, sd.text_fill_gradient)
  if (gradientCss) {
    gradientStyle = `background:${gradientCss}; -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;`
  }

  return `<div style="${itemStyle(item, page)} font-size:${fontSize}px; color:${gradientCss ? 'transparent' : color}; font-family:${fontFamily}; font-weight:${fontWeight}; text-align:${textAlign}; line-height:${lineHeight}; ${letterSpacing} ${textTransform} padding:${padding}px; ${opacity} word-wrap:break-word; ${gradientStyle}">${content}</div>`
}

function renderNoteItem(item, page) {
  const bg = item.color || '#fef3c7'
  const fg = contrastColor(bg)
  const title = item.title || ''
  const content = stripHtml(item.content || '')

  return `<div style="${itemStyle(item, page)} background:${bg}; color:${fg}; padding:10px 12px; border-radius:4px; font-family:Inter, Arial, sans-serif;">
    ${title ? `<div style="font-weight:700; font-size:13px; margin-bottom:4px;">${esc(title)}</div>` : ''}
    ${content ? `<div style="font-size:11px; white-space:pre-wrap;">${esc(content)}</div>` : ''}
  </div>`
}

function renderShapeItem(item, page) {
  const sd = parseSd(item)
  const bg = sd.shape_fill || item.color || sd.background_color || '#6366f1'
  const shapeType = sd.shape_type || 'rectangle'
  const isCircle = ['circle', 'ellipse'].includes(shapeType)
  const br = isCircle ? '50%' : `${sd.shape_border_radius || 0}px`
  const borderWidth = sd.shape_border_width || sd.border_width || 0
  const borderColor = sd.shape_border_color || sd.border_color || '#000'
  const borderCss = borderWidth > 0 ? `border:${borderWidth}px solid ${borderColor};` : ''
  const opacity = sd.opacity != null ? `opacity:${sd.opacity};` : ''

  const textContent = stripHtml(item.content || item.title || sd.shape_text || '')
  const textColor = sd.shape_text_color || '#fff'
  const textSize = sd.shape_font_size || 14

  return `<div style="${itemStyle(item, page)} background:${bg}; border-radius:${br}; ${borderCss} ${opacity} display:flex; align-items:center; justify-content:center;">
    ${textContent ? `<span style="color:${textColor}; font-size:${textSize}px; font-weight:600; text-align:center; padding:4px;">${esc(textContent)}</span>` : ''}
  </div>`
}

function renderColorSwatch(item, page) {
  const bg = item.color || '#6366f1'
  const fg = contrastColor(bg)
  return `<div style="${itemStyle(item, page)} background:${bg}; border-radius:8px; display:flex; align-items:flex-end; justify-content:center; padding:8px;">
    <span style="color:${fg}; font-size:10px; font-family:Consolas, monospace;">${esc(item.color || '')}</span>
  </div>`
}

function renderTodoList(item, page) {
  const todos = item.todos || []
  const title = item.title || ''
  const todoHtml = todos.map(t => {
    const check = t.completed ? '[x]' : '[ ]'
    const strike = t.completed ? 'text-decoration:line-through; color:#9ca3af;' : 'color:#374151;'
    return `<div style="font-size:10px; ${strike} margin-bottom:2px;">${check} ${esc(t.text || '')}</div>`
  }).join('')

  return `<div style="${itemStyle(item, page)} background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:10px 12px; font-family:Inter, Arial, sans-serif;">
    ${title ? `<div style="font-weight:700; font-size:12px; color:#111827; margin-bottom:6px;">${esc(title)}</div>` : ''}
    ${todoHtml}
  </div>`
}

function renderLinkItem(item, page) {
  return `<div style="${itemStyle(item, page)} background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:10px 12px; font-family:Inter, Arial, sans-serif;">
    ${item.title ? `<div style="font-weight:700; font-size:12px; color:#111827;">${esc(item.title)}</div>` : ''}
    ${item.url ? `<div style="font-size:9px; color:#6366f1; margin-top:2px; overflow:hidden; text-overflow:ellipsis;">${esc(item.url)}</div>` : ''}
  </div>`
}

function renderFrameItem(item, page) {
  const sd = parseSd(item)
  const label = (sd.frame_label !== false) && item.title
  return `<div style="${itemStyle(item, page)} border:1px solid #d1d5db;">
    ${label ? `<div style="position:absolute; top:3px; left:6px; font-size:8px; color:#9ca3af; font-weight:700; font-family:Inter, Arial, sans-serif;">${esc(item.title)}</div>` : ''}
  </div>`
}

function renderVideoItem(item, page) {
  return `<div style="${itemStyle(item, page)} background:#111827; display:flex; align-items:center; justify-content:center; border-radius:6px;">
    <div style="text-align:center;">
      <div style="color:#a855f7; font-size:12px; font-weight:700;">Video</div>
      ${item.title ? `<div style="color:#fff; font-size:10px; margin-top:4px;">${esc(item.title)}</div>` : ''}
    </div>
  </div>`
}

function renderImageSet(item, page) {
  const images = item.images || []
  if (!images.length) return ''
  const cols = images.length <= 1 ? 1 : (images.length <= 4 ? 2 : 3)
  const w = Math.max(Number(item.width || 0), 1)
  const h = Math.max(Number(item.height || 0), 1)
  const cellW = w / cols
  const rows = Math.ceil(images.length / cols)
  const cellH = h / rows

  const imgs = images.map((img, idx) => {
    const url = resolveUrl(img.image_url || img.thumbnail_url || '')
    if (!url) return ''
    const col = idx % cols
    const row = Math.floor(idx / cols)
    return `<img src="${esc(url)}" style="position:absolute; left:${col * cellW}px; top:${row * cellH}px; width:${cellW}px; height:${cellH}px; object-fit:cover;" />`
  }).join('')

  return `<div style="${itemStyle(item, page)}">${imgs}</div>`
}

function renderDrawingItem(item, page) {
  const raw = item.content
  let data = null
  if (raw) { try { data = typeof raw === 'string' ? JSON.parse(raw) : raw } catch {} }

  const sd = parseSd(item)
  const itemW = Math.max(Number(item.width || 0), 1)
  const itemH = Math.max(Number(item.height || 0), 1)

  let strokes = data?.strokes || []
  if (!strokes.length) strokes = sd.strokes_data || sd.drawing_strokes || []
  if (!strokes.length) return ''

  const vbW = parseInt(data?.width) || sd.original_width || itemW
  const vbH = parseInt(data?.height) || sd.original_height || itemH

  let paths = ''
  for (const stroke of strokes) {
    const color = stroke.color || '#000000'
    if (stroke.svgPath) {
      paths += `<path d="${stroke.svgPath}" fill="${esc(color)}" />`
    } else if (stroke.points?.length >= 2) {
      const pts = stroke.points
      let d = ''
      if (Array.isArray(pts[0])) {
        d = `M${pts[0][0]},${pts[0][1]}` + pts.slice(1).map(p => `L${p[0]},${p[1]}`).join('') + 'Z'
      } else if (pts[0].x != null) {
        d = `M${pts[0].x},${pts[0].y}` + pts.slice(1).map(p => `L${p.x},${p.y}`).join('') + (stroke.closed !== false ? 'Z' : '')
      }
      if (d) paths += `<path d="${d}" fill="${esc(color)}" />`
    }
  }
  if (!paths) return ''

  return `<div style="${itemStyle(item, page)}">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${vbW} ${vbH}" width="${itemW}" height="${itemH}" style="width:100%; height:100%;">${paths}</svg>
  </div>`
}

function renderGenericItem(item, page) {
  return `<div style="${itemStyle(item, page)} background:#f9fafb; border:1px solid #e5e7eb; display:flex; align-items:center; justify-content:center; border-radius:4px;">
    <span style="color:#6b7280; font-size:10px; font-family:Inter, Arial, sans-serif;">${esc(item.title || item.type || '')}</span>
  </div>`
}

function renderItem(item, page) {
  switch (item.type) {
    case 'image': return renderImageItem(item, page)
    case 'text': return renderTextItem(item, page)
    case 'note': return renderNoteItem(item, page)
    case 'shape':
    case 'pen_shape': return renderShapeItem(item, page)
    case 'color_swatch': return renderColorSwatch(item, page)
    case 'todo_list': return renderTodoList(item, page)
    case 'link': return renderLinkItem(item, page)
    case 'frame':
    case 'column': return renderFrameItem(item, page)
    case 'image_set': return renderImageSet(item, page)
    case 'drawing': return renderDrawingItem(item, page)
    case 'group':
    case 'repeat_grid':
    case 'artboard': return ''
    case 'video':
    case 'youtube': return renderVideoItem(item, page)
    default: return renderGenericItem(item, page)
  }
}

function resolveConnColor(conn, accentColor) {
  const c = conn.line_color
  if (!c || c === 'accent') return accentColor || '#6366f1'
  return c
}

function renderConnections(connections, page, allItems, accentColor) {
  if (!connections?.length) return ''
  const itemMap = {}
  for (const item of allItems) itemMap[item.id] = item

  const defs = []
  const elements = []

  connections.forEach((conn, idx) => {
    const from = itemMap[conn.from_item_id]
    const to = itemMap[conn.to_item_id]
    if (!from || !to) return

    const fromD = resolveItemDims(from)
    const toD = resolveItemDims(to)
    const x1 = Number(from.pos_x || 0) + (conn.from_anchor_x ?? 0.5) * fromD.w - page.originX
    const y1 = Number(from.pos_y || 0) + (conn.from_anchor_y ?? 0.5) * fromD.h - page.originY
    const x2 = Number(to.pos_x || 0) + (conn.to_anchor_x ?? 0.5) * toD.w - page.originX
    const y2 = Number(to.pos_y || 0) + (conn.to_anchor_y ?? 0.5) * toD.h - page.originY

    const baseColor = resolveConnColor(conn, accentColor)
    const width = conn.line_width || 2
    const dash = conn.line_style === 'dashed' ? `stroke-dasharray="${width * 4} ${width * 2}"` : (conn.line_style === 'dotted' ? `stroke-dasharray="${width} ${width * 2}"` : '')

    let stroke = baseColor
    if (conn.gradient_enabled) {
      const gid = `cg${conn.id || idx}`
      const startColor = conn.gradient_color_start || baseColor
      const endColor = conn.gradient_color_end || '#8b5cf6'
      defs.push(`<linearGradient id="${gid}" gradientUnits="userSpaceOnUse" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}">
        <stop offset="0%" stop-color="${startColor}" />
        <stop offset="100%" stop-color="${endColor}" />
      </linearGradient>`)
      stroke = `url(#${gid})`
    }

    elements.push(`<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${stroke}" stroke-width="${width}" stroke-linecap="round" ${dash} />`)
  })

  if (!elements.length) return ''
  const defsBlock = defs.length ? `<defs>${defs.join('')}</defs>` : ''
  return `<svg style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:9999;" xmlns="http://www.w3.org/2000/svg">
    ${defsBlock}
    ${elements.join('\n    ')}
  </svg>`
}

function collectFonts(items) {
  const fonts = new Set()
  const FONT_DIR_MAP = {
    'Inter': 'inter', 'Roboto': 'roboto', 'Open Sans': 'open-sans',
    'Lato': 'lato', 'Montserrat': 'montserrat', 'Poppins': 'poppins',
    'Raleway': 'raleway', 'Source Sans 3': 'source-sans-3', 'Nunito': 'nunito',
    'Work Sans': 'work-sans', 'Outfit': 'outfit',
    'Playfair Display': 'playfair-display', 'Merriweather': 'merriweather',
    'Lora': 'lora', 'PT Serif': 'pt-serif', 'Libre Baskerville': 'libre-baskerville',
    'Oswald': 'oswald', 'Bebas Neue': 'bebas-neue', 'Anton': 'anton',
    'Archivo Black': 'archivo-black', 'Roboto Mono': 'roboto-mono',
    'Source Code Pro': 'source-code-pro', 'Fira Code': 'fira-code',
    'JetBrains Mono': 'jetbrains-mono',
  }
  for (const item of items) {
    const sd = parseSd(item)
    for (const f of [sd.font_family, sd.shape_font_family].filter(Boolean)) {
      if (FONT_DIR_MAP[f]) fonts.add(f)
    }
  }
  return [...fonts].map(f =>
    `<link rel="stylesheet" href="${resolveUrl(`/fonts/${FONT_DIR_MAP[f]}/font.css`)}">`
  ).join('\n  ')
}

function buildPrintHtml(board, pages, accentColor) {
  const title = board.name || 'Moodboard'
  const bgCss = buildBackgroundCss(board)
  const pageItems = pages.flatMap(p => p.items)
  const allBoardItems = (board?.items || []).filter(i => i.type !== 'slide' && isVisible(i))
  const fontLinks = collectFonts(pageItems)
  const connections = board.connections || []
  const pageW = pages[0]?.width || DEFAULT_PAGE.width
  const pageH = pages[0]?.height || DEFAULT_PAGE.height
  const isLandscape = pageW >= pageH

  const sections = pages.map(page => {
    const sorted = [...page.items].sort((a, b) => (a.z_index || 0) - (b.z_index || 0))
    const itemsHtml = sorted.map(item => renderItem(item, page)).join('\n      ')
    return `<section class="mood-page" style="${bgCss}">
      ${itemsHtml}
    </section>`
  }).join('\n')

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${esc(title)}</title>
  ${fontLinks}
  <style>
    @page {
      size: ${isLandscape ? 'A4 landscape' : 'A4 portrait'};
      margin: 0;
    }
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    html, body {
      margin: 0; padding: 0;
      background: #1e293b;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      color-adjust: exact;
    }
    body {
      display: flex; flex-direction: column; align-items: center;
      gap: 24px; padding: 24px;
    }
    .mood-page {
      width: ${pageW}px;
      height: ${pageH}px;
      position: relative;
      overflow: hidden;
      page-break-after: always;
      break-after: page;
      box-shadow: 0 16px 48px rgba(0,0,0,0.4);
      flex-shrink: 0;
    }
    .mood-page:last-child {
      page-break-after: auto;
      break-after: auto;
    }
    @media print {
      html, body { background: transparent; gap: 0; padding: 0; }
      .mood-page { box-shadow: none; width: 100vw; height: 100vh; }
    }
  </style>
</head>
<body>
${sections}
<script>
(function() {
  var imgs = Array.from(document.images);
  var loaded = imgs.length
    ? Promise.all(imgs.map(function(img) {
        return img.complete ? Promise.resolve() : new Promise(function(r) {
          img.onload = img.onerror = r;
        });
      }))
    : Promise.resolve();

  var fontsReady = document.fonts && document.fonts.ready
    ? document.fonts.ready.catch(function(){})
    : Promise.resolve();

  Promise.all([loaded, fontsReady]).then(function() {
    setTimeout(function() { window.focus(); window.print(); }, 600);
  });

  window.addEventListener('afterprint', function() {
    setTimeout(function() { window.close(); }, 200);
  }, { once: true });
})();
</script>
</body>
</html>`
}

function readAccentColor() {
  try {
    const rgb = getComputedStyle(document.documentElement)
      .getPropertyValue('--color-primary-500').trim()
    if (rgb) {
      const parts = rgb.split(/\s+/)
      if (parts.length === 3) return `rgb(${parts[0]}, ${parts[1]}, ${parts[2]})`
    }
  } catch {}
  return '#6366f1'
}

export async function exportMoodBoardPdf(board, exportOptions = {}) {
  if (!board) return false

  const pages = buildPages(board)
  if (!pages.length) return false

  const accentColor = readAccentColor()

  const printWindow = window.open('', '_blank')
  if (!printWindow) return false

  try {
    const html = buildPrintHtml(board, pages, accentColor)
    printWindow.document.open()
    printWindow.document.write(html)
    printWindow.document.close()
    return true
  } catch (error) {
    console.error('PDF export failed:', error)
    try { printWindow.close() } catch {}
    throw error
  }
}
