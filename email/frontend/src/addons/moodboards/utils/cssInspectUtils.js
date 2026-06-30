/**
 * cssInspectUtils.js
 *
 * Generates production-ready CSS from moodboard items.
 * Uses rem units (base 16px) for scalable properties.
 * Outputs :root variables for globals, named classes for text styles,
 * and element CSS that references var() tokens when linked.
 */
import { getGlobalsMap } from './globalStyleResolver'
import { normalizeSd } from './styleAdapter'
import { figmaToCssRgba, figmaToHex } from './colorConvert'
import {
  fillsToCssBackground, strokesToCssBorder,
  effectsToBoxShadow, effectsToTextShadow, effectsToFilters,
  blendModeToCss,
} from './cssPaintUtils'

const BASE_FONT_SIZE = 16

const BG_NAME_RE = /\bbg\b|\bbackground\b/i

export function isBackgroundItem(item, options = {}) {
  if (item.style_data?.is_background) return true
  if (!options._isExport) return false
  const name = (item.title || '').toLowerCase()
  return BG_NAME_RE.test(name)
}

function rem(px) {
  if (px == null || px === 0) return '0'
  const v = +(px / BASE_FONT_SIZE).toFixed(4).replace(/\.?0+$/, '')
  return v === 0 ? '0' : `${v}rem`
}

export function toCssName(str) {
  if (!str) return 'unnamed'
  return str
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2')
    .replace(/[\s_]+/g, '-')
    .replace(/[^a-zA-Z0-9-]/g, '')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase() || 'unnamed'
}

export function generateRootBlock(globalColors = [], globalGradients = []) {
  const lines = []
  for (const c of globalColors) {
    lines.push(`  --color-${toCssName(c.name)}: ${c.value};`)
  }
  for (const g of globalGradients) {
    lines.push(`  --gradient-${toCssName(g.name)}: ${gradientToCss(g)};`)
  }
  if (!lines.length) return ''
  return `:root {\n  font-size: ${BASE_FONT_SIZE}px;\n${lines.join('\n')}\n}`
}

export function generateTextStyleClasses(globalTextStyles = []) {
  const blocks = []
  for (const ts of globalTextStyles) {
    const cls = toCssName(ts.name)
    const lines = []
    if (ts.font_family) lines.push(`  font-family: '${ts.font_family}', sans-serif;`)
    if (ts.font_weight) lines.push(`  font-weight: ${ts.font_weight};`)
    if (ts.font_size) lines.push(`  font-size: ${rem(ts.font_size)};`)
    if (ts.line_height != null) lines.push(`  line-height: ${ts.line_height};`)
    if (ts.letter_spacing != null && ts.letter_spacing !== 0) lines.push(`  letter-spacing: ${rem(ts.letter_spacing)};`)
    if (ts.text_transform && ts.text_transform !== 'none') lines.push(`  text-transform: ${ts.text_transform};`)
    if (ts.text_color) lines.push(`  color: ${ts.text_color};`)
    if (lines.length) {
      blocks.push(`.${cls} {\n${lines.join('\n')}\n}`)
    }
  }
  return blocks.join('\n\n')
}

export function generateItemCss(item, options = {}) {
  const sections = []

  const layout = generateLayoutCss(item, options)
  if (layout) sections.push({ label: 'Layout', css: layout })

  const fill = generateFillCss(item, options)
  if (fill) sections.push({ label: 'Fill', css: fill })

  const typo = generateTypographyCss(item, options)
  if (typo) sections.push({ label: 'Typography', css: typo })

  const textClip = generateTextClipCss(item)
  if (textClip) sections.push({ label: 'TextClip', css: textClip })

  const border = generateBorderCss(item, options)
  if (border) sections.push({ label: 'Border', css: border })

  const effects = generateEffectsCss(item, options)
  if (effects) sections.push({ label: 'Effects', css: effects })

  return sections
}

export function wrapInSelector(item, rawCss) {
  if (!rawCss) return rawCss
  const parsed = parseComponentName(item.title)
  if (!parsed.base) return rawCss
  const identitySelector = `.${parsed.base}${parsed.variant || ''}`
  const indented = rawCss.split('\n').map(l => `  ${l}`).join('\n')
  return `${identitySelector} {\n${indented}\n}`
}

export function generateFullCss(item, options = {}) {
  const parts = []

  const root = generateRootBlock(options.globalColors, options.globalGradients)
  if (root) parts.push(root)

  const textClasses = generateTextStyleClasses(options.globalTextStyles)
  if (textClasses) parts.push(textClasses)

  const sections = generateItemCss(item, options)
  if (sections.length) {
    const rawCss = sections.map(s => s.css).join('\n')
    const wrapped = wrapInSelector(item, rawCss)
    parts.push(`/* Element */\n${wrapped}`)
  }

  return parts.join('\n\n')
}

/**
 * Parse title into CSS classes + optional pseudo variant.
 * Supports comma-separated names: "hero-wrapper, orange" -> classes: ["hero-wrapper", "orange"]
 * Supports colon variant on the LAST segment: "btn, primary:hover" -> variant: ":hover"
 */
export function parseComponentName(name) {
  if (!name) return { base: null, variant: null, classes: [] }
  const segments = name.split(',').map(s => s.trim()).filter(Boolean)
  if (!segments.length) return { base: null, variant: null, classes: [] }

  const classes = []
  let variant = null

  for (let i = 0; i < segments.length; i++) {
    const seg = segments[i]
    const colonIdx = seg.indexOf(':')
    if (colonIdx > 0 && i === segments.length - 1) {
      classes.push(toCssName(seg.substring(0, colonIdx)))
      variant = ':' + toCssName(seg.substring(colonIdx + 1))
    } else {
      classes.push(toCssName(seg))
    }
  }

  return { base: classes[0] || null, variant, classes }
}

export function getItemCssName(item) {
  const name = item.title
  if (!name) return null
  const { classes } = parseComponentName(name)
  return classes[0] || null
}

/**
 * Build a compound CSS selector from parsed classes.
 * "hero-wrapper, orange" -> ".hero-wrapper.orange"
 * "btn, primary:hover"   -> ".btn.primary:hover"
 */
export function buildSelector(parsed) {
  if (!parsed.classes.length) return null
  const chain = parsed.classes.map(c => `.${c}`).join('')
  return `${chain}${parsed.variant || ''}`
}

const CSS_KEY_MAP = {
  color: 'color',
  background: 'background',
  font_size: 'font-size',
  font_weight: 'font-weight',
  font_family: 'font-family',
  text_align: 'text-align',
  padding: 'padding',
  margin: 'margin',
  border_radius: 'border-radius',
  opacity: 'opacity',
  border_color: 'border-color',
  border_width: 'border-width',
}

/**
 * Generate compound CSS override blocks for global classes applied to an item.
 * e.g. ".hero-title.red { color: #ff0000; }"
 */
export function generateCssClassBlocks(item, globalCssClasses) {
  if (!item?.title || !globalCssClasses?.length) return null

  const names = item.title.split(',').map(s => s.trim()).filter(Boolean)
  if (names.length <= 1) return null

  const parsed = parseComponentName(item.title)
  if (!parsed.base) return null

  const classMap = new Map(globalCssClasses.map(c => [c.name.toLowerCase(), c]))
  const blocks = []

  let compoundSoFar = `.${parsed.base}`

  for (let i = 1; i < names.length; i++) {
    const className = toCssName(names[i])
    compoundSoFar += `.${className}`

    const cls = classMap.get(names[i].toLowerCase())
    if (!cls?.properties || !Object.keys(cls.properties).length) continue

    const lines = Object.entries(cls.properties).map(([k, v]) => {
      const cssProp = CSS_KEY_MAP[k] || k.replace(/_/g, '-')
      const unit = ['font_size', 'padding', 'margin', 'border_radius', 'border_width'].includes(k) && !isNaN(v) ? 'px' : ''
      return `  ${cssProp}: ${v}${unit};`
    })
    blocks.push(`${compoundSoFar} {\n${lines.join('\n')}\n}`)
  }

  return blocks.length ? blocks.join('\n\n') : null
}

function getItemLabel(item) {
  return item.title || item.content?.substring(0, 20) || item.type
}

function detectTextStyleOverrides(child, options) {
  const globals = getGlobalsMap(child)
  const tsRef = globals.text_style
  if (!tsRef?.id || !options.globalTextStyles) return null

  const globalStyle = options.globalTextStyles.find(s => s.id === tsRef.id)
  if (!globalStyle) return null

  const globalClass = toCssName(globalStyle.name)
  const sd = child.style_data || {}
  const isShape = child.type === 'shape' || child.type === 'pen_shape'
  const overrides = []

  const checks = [
    ['font_family', isShape ? sd.shape_font_family : sd.font_family, globalStyle.font_family, v => `font-family: '${v}', sans-serif;`],
    ['font_weight', isShape ? sd.shape_font_weight : sd.font_weight, globalStyle.font_weight, v => `font-weight: ${v};`],
    ['font_size', isShape ? sd.shape_font_size : sd.font_size, globalStyle.font_size, v => `font-size: ${rem(v)};`],
    ['line_height', isShape ? sd.shape_line_height : sd.line_height, globalStyle.line_height, v => `line-height: ${v};`],
    ['letter_spacing', isShape ? sd.shape_letter_spacing : sd.letter_spacing, globalStyle.letter_spacing, v => `letter-spacing: ${rem(v)};`],
    ['text_transform', isShape ? sd.shape_text_transform : sd.text_transform, globalStyle.text_transform, v => `text-transform: ${v};`],
    ['text_color', isShape ? sd.shape_text_color : sd.text_color, globalStyle.text_color, v => {
      const colorKey = isShape ? 'shape_text_color' : 'text_color'
      const varRef = resolveColorVar(globals[colorKey], options)
      return `color: ${varRef || v};`
    }],
  ]

  for (const [, local, global, fmt] of checks) {
    if (local != null && global != null && String(local) !== String(global)) {
      overrides.push(fmt(local))
    }
  }

  return { globalClass, overrides }
}

/**
 * Generate full CSS for a group + all its children.
 * Includes inferred or explicit flex/grid layout for the group container.
 */
export function generateGroupCss(groupItem, children, options = {}) {
  const parts = []

  if (options.includeGlobals !== false) {
    const root = generateRootBlock(options.globalColors, options.globalGradients)
    if (root) parts.push(root)
    const textClasses = generateTextStyleClasses(options.globalTextStyles)
    if (textClasses) parts.push(textClasses)
  }

  const parsedGroup = parseComponentName(groupItem.title)
  const groupSelector = buildSelector(parsedGroup)
  const groupIdentity = parsedGroup.base ? `.${parsedGroup.base}` : null

  const hasLayout = groupItem.style_data?.layout_mode && groupItem.style_data.layout_mode !== 'none'
  const bgKids = children.filter(c => isBackgroundItem(c, options))
  const layoutKids = children.filter(c => !isBackgroundItem(c, options))

  const containerOpts = { ...options, _children: layoutKids }
  if (options._isRootExport) containerOpts._isRootExport = true
  const containerSections = generateItemCss(groupItem, containerOpts)
  let containerCss = containerSections.map(s => s.css).join('\n')
  const hasBgChildren = bgKids.length > 0
  if (hasLayout || hasBgChildren) {
    const clipContent = options._isExport
      ? false
      : (groupItem.style_data?.clip_content !== undefined ? groupItem.style_data.clip_content : true)
    containerCss = `position: relative;\noverflow: ${clipContent ? 'hidden' : 'visible'};\n${containerCss}`
  }

  if (options._isExport && hasBgChildren) {
    const bgFillLines = []
    for (const bgChild of bgKids) {
      const bgSections = generateItemCss(bgChild, options)
      const fillOnly = bgSections.filter(s => s.label === 'Fill' || s.label === 'Effects')
      bgFillLines.push(...fillOnly.map(s => s.css))

      if (bgChild.height != null) {
        bgFillLines.push(`min-height: ${rem(Math.round(bgChild.height))};`)
      }
    }
    if (bgFillLines.length) {
      containerCss = `${containerCss}\n${bgFillLines.join('\n')}`
    }
  }

  if (groupIdentity && containerCss) {
    parts.push(`${groupIdentity} {\n${indent(containerCss)}\n}`)
  } else if (containerCss) {
    parts.push(`/* group */\n${containerCss}`)
  }

  if (!options._isExport) {
    for (const bgChild of bgKids) {
      const bgName = getItemCssName(bgChild)
      const bgSections = generateItemCss(bgChild, options)
      const bgFillCss = bgSections
        .filter(s => s.label !== 'Layout')
        .map(s => s.css).join('\n')
      const bgCss = `position: absolute;\ninset: 0;\nwidth: 100%;\nheight: 100%;\nz-index: 0;\nobject-fit: cover;\n${bgFillCss}`
      if (bgName && groupIdentity) {
        parts.push(`${groupIdentity} .${bgName} {\n${indent(bgCss)}\n}`)
      } else {
        parts.push(`/* background */\n${bgCss}`)
      }
    }
  }

  const childOpts = options._isRootExport ? { ...options, _isRootExport: false } : options

  for (const child of layoutKids) {
    const childName = getItemCssName(child)
    const childSections = generateItemCss(child, childOpts)
    if (!childSections.length) continue
    let childCss = childSections.map(s => s.css).join('\n')
    const label = getItemLabel(child)

    const tsOverride = options._isExport ? null : detectTextStyleOverrides(child, options)
    const resolvedName = childName || (options._isExport ? `_${child.type || 'el'}-${String(child.id || '').slice(-6)}` : null)

    if (resolvedName && groupIdentity) {
      const selector = `${groupIdentity} .${resolvedName}`
      if (tsOverride && tsOverride.overrides.length > 0) {
        const nonTypo = childSections.filter(s => s.label !== 'Typography')
        const note = `  /* extends .${tsOverride.globalClass} */`
        const overrideLines = tsOverride.overrides.map(l => `  ${l}`).join('\n')
        const extraCss = nonTypo.length ? '\n' + indent(nonTypo.map(s => s.css).join('\n')) : ''
        parts.push(`${selector} {\n${note}\n${overrideLines}${extraCss}\n}`)
      } else if (tsOverride) {
        const nonTypo = childSections.filter(s => s.label !== 'Typography')
        if (nonTypo.length) {
          const css = nonTypo.map(s => s.css).join('\n')
          parts.push(`${selector} {\n  /* applies .${tsOverride.globalClass} */\n${indent(css)}\n}`)
        } else {
          parts.push(`/* ${selector} -- applies .${tsOverride.globalClass} */`)
        }
      } else {
        parts.push(`${selector} {\n${indent(childCss)}\n}`)
      }
    } else if (!resolvedName && childName === null && groupIdentity) {
      parts.push(`/* ${label} */\n${childCss}`)
    } else {
      parts.push(`/* ${label} */\n${childCss}`)
    }
  }

  return parts.join('\n\n')
}

function indent(css, spaces = 2) {
  const pad = ' '.repeat(spaces)
  return css.split('\n').map(l => `${pad}${l}`).join('\n')
}

// ── Section generators ──

export function generateLayoutCss(item, options = {}) {
  const lines = []
  const isExport = options._isExport
  const isContainer = item.type === 'group' || item.type === 'frame' || item.type === 'repeat_grid' || item.type === 'slide'
  const isLeafText = item.type === 'text' || item.type === 'line'

  if (isExport) {
    if (options._isRootExport && isContainer) {
      if (item.width != null) lines.push(`max-width: ${rem(Math.round(item.width))};`)
      lines.push(`width: 100%;`)
      lines.push(`margin: 0 auto;`)
    } else if (isContainer) {
      lines.push(`width: 100%;`)
    } else if (isLeafText) {
      // text flows naturally, no fixed dimensions
    } else if (item.type === 'shape' || item.type === 'pen_shape') {
      if (item.width != null) lines.push(`max-width: ${rem(Math.round(item.width))};`)
      lines.push(`width: 100%;`)
      if (item.height != null) lines.push(`height: ${rem(Math.round(item.height))};`)
    } else if (item.type === 'image') {
      if (item.width != null) lines.push(`max-width: ${rem(Math.round(item.width))};`)
      lines.push(`width: 100%;`)
    } else {
      if (item.width != null) lines.push(`width: ${rem(Math.round(item.width))};`)
      if (item.height != null) lines.push(`height: ${rem(Math.round(item.height))};`)
    }
  } else {
    if (item.width != null) lines.push(`width: ${rem(Math.round(item.width))};`)
    if (item.height != null) lines.push(`height: ${rem(Math.round(item.height))};`)
  }

  const sd = item.style_data || {}
  const n = normalizeSd(item.type, sd)

  if (item.type === 'pen_shape' && n.vectorPaths?.svgPath) {
    lines.push(`clip-path: path('${n.vectorPaths.svgPath}');`)
  } else if (item.type === 'shape' && n.shapeType) {
    if (n.shapeType === 'ELLIPSE') {
      lines.push(`border-radius: 50%;`)
    } else if (n.shapeType === 'POLYGON') {
      lines.push(`clip-path: polygon(50% 0%, 0% 100%, 100% 100%);`)
    } else if (n.shapeType === 'STAR') {
      lines.push(`clip-path: polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%);`)
    } else {
      appendBorderRadius(n, lines)
    }
  } else {
    appendBorderRadius(n, lines)
  }

  if (item.rotation) lines.push(`transform: rotate(${item.rotation}deg);`)

  if (sd.item_scale != null && sd.item_scale !== 1) {
    lines.push(`scale: ${sd.item_scale};`)
  }

  // Padding (explicit)
  const hasPad = sd.padding != null && sd.padding > 0
  const hasPerSidePad = sd.padding_top || sd.padding_right || sd.padding_bottom || sd.padding_left
  if (hasPad) {
    lines.push(`padding: ${rem(sd.padding)};`)
  } else if (hasPerSidePad) {
    const pt = sd.padding_top || 0, pr = sd.padding_right || 0, pb = sd.padding_bottom || 0, pl = sd.padding_left || 0
    if (pt === pr && pr === pb && pb === pl) lines.push(`padding: ${rem(pt)};`)
    else if (pt === pb && pl === pr) lines.push(`padding: ${rem(pt)} ${rem(pr)};`)
    else lines.push(`padding: ${rem(pt)} ${rem(pr)} ${rem(pb)} ${rem(pl)};`)
  }

  // Margin (explicit, supports 'auto')
  const mRaw = { t: sd.margin_top, r: sd.margin_right, b: sd.margin_bottom, l: sd.margin_left }
  const hasMargin = mRaw.t || mRaw.r || mRaw.b || mRaw.l
  if (hasMargin) {
    const mv = s => s === 'auto' ? 'auto' : rem(s || 0)
    const mt = mv(mRaw.t), mr = mv(mRaw.r), mb = mv(mRaw.b), ml = mv(mRaw.l)
    if (mt === mr && mr === mb && mb === ml) lines.push(`margin: ${mt};`)
    else if (mt === mb && ml === mr) lines.push(`margin: ${mt} ${mr};`)
    else lines.push(`margin: ${mt} ${mr} ${mb} ${ml};`)
  }

  // Layout mode: repeat_grid, flex (auto_layout or layout_mode), grid (layout_mode)
  if (item.type === 'repeat_grid') {
    const cols = sd.grid_columns || 3
    const rows = sd.grid_rows || 3
    const hGap = sd.grid_h_gap ?? 20
    const vGap = sd.grid_v_gap ?? 20
    lines.push(`display: grid;`)
    lines.push(`grid-template-columns: repeat(${cols}, 1fr);`)
    lines.push(`grid-template-rows: repeat(${rows}, 1fr);`)
    if (hGap === vGap) {
      lines.push(`gap: ${rem(hGap)};`)
    } else {
      lines.push(`row-gap: ${rem(vGap)};`)
      lines.push(`column-gap: ${rem(hGap)};`)
    }
  } else if (sd.auto_layout || sd.layout_mode === 'flex') {
    lines.push(`display: flex;`)
    const dir = sd.layout_direction || 'column'
    lines.push(`flex-direction: ${dir === 'horizontal' ? 'row' : dir};`)
    if (sd.layout_gap != null) lines.push(`gap: ${rem(sd.layout_gap)};`)
    if (sd.layout_align) {
      const mapped = { start: 'flex-start', end: 'flex-end', center: 'center', stretch: 'stretch' }
      lines.push(`align-items: ${mapped[sd.layout_align] || sd.layout_align};`)
    }
    if (sd.layout_justify) {
      const mapped = { start: 'flex-start', end: 'flex-end', center: 'center', 'space-between': 'space-between', 'space-around': 'space-around' }
      lines.push(`justify-content: ${mapped[sd.layout_justify] || sd.layout_justify};`)
    }
    if (sd.layout_wrap) lines.push(`flex-wrap: wrap;`)
  } else if (sd.layout_mode === 'grid') {
    lines.push(`display: grid;`)
    const cols = sd.grid_columns || 3
    const rows = sd.grid_rows
    if (typeof cols === 'string' && cols.includes('fr')) {
      lines.push(`grid-template-columns: ${cols};`)
    } else {
      lines.push(`grid-template-columns: repeat(${cols}, 1fr);`)
    }
    if (rows) {
      if (typeof rows === 'string' && rows.includes('fr')) {
        lines.push(`grid-template-rows: ${rows};`)
      } else {
        lines.push(`grid-template-rows: repeat(${rows}, 1fr);`)
      }
    }
    const hGap = sd.grid_h_gap ?? 20
    const vGap = sd.grid_v_gap ?? 20
    if (hGap === vGap) {
      lines.push(`gap: ${rem(hGap)};`)
    } else {
      lines.push(`row-gap: ${rem(vGap)};`)
      lines.push(`column-gap: ${rem(hGap)};`)
    }
    if (sd.grid_align_items) lines.push(`align-items: ${sd.grid_align_items};`)
    if (sd.grid_justify_items) lines.push(`justify-items: ${sd.grid_justify_items};`)
  } else if (item.type === 'group' && options._children?.length >= 2) {
    // Infer flex layout from children positions when no explicit layout_mode
    const inferred = inferFlexLayout(item, options._children)
    if (inferred) {
      lines.push(`display: flex;`)
      lines.push(`flex-direction: ${inferred.direction};`)
      if (inferred.alignItems !== 'stretch') lines.push(`align-items: ${inferred.alignItems};`)
      if (inferred.justifyContent !== 'flex-start') lines.push(`justify-content: ${inferred.justifyContent};`)
      if (inferred.gap > 0) lines.push(`gap: ${rem(inferred.gap)};`)
      const p = inferred.padding
      if (p.top || p.right || p.bottom || p.left) {
        if (p.top === p.right && p.right === p.bottom && p.bottom === p.left) {
          lines.push(`padding: ${rem(p.top)};`)
        } else if (p.top === p.bottom && p.left === p.right) {
          lines.push(`padding: ${rem(p.top)} ${rem(p.right)};`)
        } else {
          lines.push(`padding: ${rem(p.top)} ${rem(p.right)} ${rem(p.bottom)} ${rem(p.left)};`)
        }
      }
    }
  }

  // Per-child flex/grid properties
  if (sd.flex_grow != null && sd.flex_grow !== 0) lines.push(`flex-grow: ${sd.flex_grow};`)
  if (sd.flex_shrink != null && sd.flex_shrink !== 1) lines.push(`flex-shrink: ${sd.flex_shrink};`)
  if (sd.flex_basis != null) lines.push(`flex-basis: ${typeof sd.flex_basis === 'number' ? rem(sd.flex_basis) : sd.flex_basis};`)
  if (sd.align_self) lines.push(`align-self: ${sd.align_self};`)
  if (sd.justify_self) lines.push(`justify-self: ${sd.justify_self};`)
  if (sd.grid_column) lines.push(`grid-column: ${sd.grid_column};`)
  if (sd.grid_row) lines.push(`grid-row: ${sd.grid_row};`)

  return lines.length ? lines.join('\n') : null
}

/**
 * Infer flex layout from children's spatial positions within a group.
 */
function inferFlexLayout(groupItem, children) {
  if (!children || children.length < 2) return null

  const gx = groupItem.pos_x || 0
  const gy = groupItem.pos_y || 0
  const gw = groupItem.width || 1
  const gh = groupItem.height || 1

  const items = children.map(c => ({
    rx: (c.pos_x || 0) - gx,
    ry: (c.pos_y || 0) - gy,
    w: c.width || 0,
    h: c.height || 0,
  }))

  const centerXs = items.map(i => i.rx + i.w / 2)
  const centerYs = items.map(i => i.ry + i.h / 2)
  const avgCx = centerXs.reduce((a, b) => a + b, 0) / items.length
  const avgCy = centerYs.reduce((a, b) => a + b, 0) / items.length
  const maxXDev = Math.max(...centerXs.map(cx => Math.abs(cx - avgCx)))
  const maxYDev = Math.max(...centerYs.map(cy => Math.abs(cy - avgCy)))

  const verticalSpread = maxYDev > maxXDev
  const direction = verticalSpread ? 'column' : 'row'

  const sorted = [...items].sort((a, b) =>
    verticalSpread ? a.ry - b.ry : a.rx - b.rx
  )

  const gaps = []
  for (let i = 1; i < sorted.length; i++) {
    const gap = verticalSpread
      ? sorted[i].ry - (sorted[i - 1].ry + sorted[i - 1].h)
      : sorted[i].rx - (sorted[i - 1].rx + sorted[i - 1].w)
    gaps.push(Math.max(0, Math.round(gap)))
  }
  const avgGap = gaps.length ? Math.round(gaps.reduce((a, b) => a + b, 0) / gaps.length) : 0

  let alignItems = 'flex-start'
  if (verticalSpread) {
    const relAvg = avgCx / gw
    if (relAvg > 0.38 && relAvg < 0.62) alignItems = 'center'
    else if (relAvg >= 0.62) alignItems = 'flex-end'
  } else {
    const relAvg = avgCy / gh
    if (relAvg > 0.38 && relAvg < 0.62) alignItems = 'center'
    else if (relAvg >= 0.62) alignItems = 'flex-end'
  }

  let justifyContent = 'flex-start'
  if (verticalSpread) {
    const topSpace = sorted[0].ry
    const bottomSpace = gh - (sorted[sorted.length - 1].ry + sorted[sorted.length - 1].h)
    if (topSpace > 10 && bottomSpace > 10 && Math.abs(topSpace - bottomSpace) < gh * 0.15) {
      justifyContent = 'center'
    }
  } else {
    const leftSpace = sorted[0].rx
    const rightSpace = gw - (sorted[sorted.length - 1].rx + sorted[sorted.length - 1].w)
    if (leftSpace > 10 && rightSpace > 10 && Math.abs(leftSpace - rightSpace) < gw * 0.15) {
      justifyContent = 'center'
    }
  }

  const minLeft = Math.min(...items.map(i => i.rx))
  const minTop = Math.min(...items.map(i => i.ry))
  const maxRight = Math.max(...items.map(i => i.rx + i.w))
  const maxBottom = Math.max(...items.map(i => i.ry + i.h))

  return {
    direction,
    alignItems,
    justifyContent,
    gap: Math.max(0, avgGap),
    padding: {
      top: Math.max(0, Math.round(minTop)),
      right: Math.max(0, Math.round(gw - maxRight)),
      bottom: Math.max(0, Math.round(gh - maxBottom)),
      left: Math.max(0, Math.round(minLeft)),
    },
  }
}

export function generateFillCss(item, options = {}) {
  const n = normalizeSd(item.type, item.style_data)
  const lines = []

  // Mask image (FlowOne extension)
  if (n._flowone?.mask?.imageUrl) {
    lines.push(`background-image: url('${n._flowone.mask.imageUrl}');`)
    lines.push(`background-size: ${n._flowone.mask.fit || 'cover'};`)
    lines.push(`background-position: center;`)
  } else if (item.type === 'image' && item.image_url) {
    lines.push(`background-image: url('${item.image_url}');`)
    lines.push(`background-size: ${n._flowone?.imageFit || 'cover'};`)
    lines.push(`background-position: center;`)
  } else if (item.type === 'note' || item.type === 'color_swatch') {
    if (item.color) lines.push(`background: ${item.color};`)
  } else {
    const bg = fillsToCssBackground(n.fills, n._globals || {}, options)
    if (bg) lines.push(`background: ${bg};`)
  }

  // Opacity (unified, no more type-switching)
  if (n.opacity < 0.999) {
    lines.push(`opacity: ${n.opacity.toFixed(2)};`)
  }

  return lines.length ? lines.join('\n') : null
}

export function generateTypographyCss(item, options = {}) {
  const n = normalizeSd(item.type, item.style_data)
  const t = n.text
  if (!t) return null

  const lines = []
  const globals = getGlobalsMap(item)

  // Check global text style linkage
  const tsRef = (n._globals || {}).text
  if (tsRef?.id && options.globalTextStyles) {
    const style = options.globalTextStyles.find(s => s.id === tsRef.id)
    if (style) lines.push(`/* applies .${toCssName(style.name)} */`)
  }

  if (t.fontFamily) lines.push(`font-family: '${t.fontFamily}', sans-serif;`)
  if (t.fontWeight) lines.push(`font-weight: ${t.fontWeight};`)
  if (t.fontSize) lines.push(`font-size: ${rem(t.fontSize)};`)
  if (t.lineHeight) {
    const lh = t.lineHeight
    if (typeof lh === 'object') {
      if (lh.unit === 'PERCENT') lines.push(`line-height: ${lh.value / 100};`)
      else if (lh.unit !== 'AUTO') lines.push(`line-height: ${lh.value}px;`)
    } else if (lh != null) {
      lines.push(`line-height: ${typeof lh === 'number' && lh <= 10 ? lh : lh};`)
    }
  }
  if (t.letterSpacing) {
    const ls = t.letterSpacing
    const val = typeof ls === 'object' ? ls.value : ls
    if (val && val !== 0) lines.push(`letter-spacing: ${rem(val)};`)
  }
  if (t.textAlignHorizontal && t.textAlignHorizontal !== 'LEFT') {
    lines.push(`text-align: ${t.textAlignHorizontal.toLowerCase()};`)
  }
  if (t.textCase && t.textCase !== 'ORIGINAL') {
    const map = { UPPER: 'uppercase', LOWER: 'lowercase', TITLE: 'capitalize' }
    if (map[t.textCase]) lines.push(`text-transform: ${map[t.textCase]};`)
  }

  // Text color from first fill or shapeTextColor
  const textColor = n._flowone?.shapeTextColor || (n.fills?.[0]?.color ? figmaToHex(n.fills[0].color) : null)
  if (textColor) {
    const varRef = resolveColorVar((n._globals || {})['fills.0.color'] || globals.text_color || globals.shape_text_color, options)
    lines.push(`color: ${varRef || textColor};`)
  }

  return lines.length ? lines.join('\n') : null
}

export function generateTextClipCss(item) {
  const n = normalizeSd(item.type, item.style_data)
  const lines = []

  if (n._flowone?.textClip?.imageUrl) {
    lines.push(`background-image: url('${n._flowone.textClip.imageUrl}');`)
    lines.push(`background-size: ${n._flowone.textClip.imageSize || 'cover'};`)
    lines.push(`background-position: center;`)
    lines.push(`-webkit-background-clip: text;`)
    lines.push(`-webkit-text-fill-color: transparent;`)
  } else if (n.fills?.[0] && n.fills[0].type !== 'SOLID' && n.fills[0].visible) {
    const bg = fillsToCssBackground(n.fills, {}, {})
    if (bg) {
      lines.push(`background: ${bg};`)
      lines.push(`-webkit-background-clip: text;`)
      lines.push(`-webkit-text-fill-color: transparent;`)
    }
  }

  if (n.strokes?.length && n.strokeWeight > 0 && item.type === 'text') {
    const stroke = n.strokes[0]
    if (stroke?.visible && stroke.color) {
      lines.push(`-webkit-text-stroke: ${n.strokeWeight}px ${figmaToCssRgba(stroke.color)};`)
    }
  }

  return lines.length ? lines.join('\n') : null
}

export function generateBorderCss(item) {
  const n = normalizeSd(item.type, item.style_data)
  const lines = []

  if (item.type === 'line') {
    const stroke = n.strokes?.[0]
    if (stroke?.visible && stroke.color) lines.push(`stroke: ${figmaToCssRgba(stroke.color)};`)
    if (n.strokeWeight) lines.push(`stroke-width: ${n.strokeWeight}px;`)
    if (stroke?.dashPattern) lines.push(`stroke-dasharray: ${stroke.dashPattern};`)
  } else {
    const border = strokesToCssBorder(n.strokes, n.strokeWeight, n.strokeAlign, n._globals || {}, {})
    if (border) lines.push(`border: ${border};`)
  }

  return lines.length ? lines.join('\n') : null
}

export function generateEffectsCss(item) {
  const n = normalizeSd(item.type, item.style_data)
  const lines = []

  const boxShadow = effectsToBoxShadow(n.effects, n._globals || {}, {})
  if (boxShadow) lines.push(`box-shadow: ${boxShadow};`)

  const textShadow = effectsToTextShadow(n.effects)
  if (textShadow) lines.push(`text-shadow: ${textShadow};`)

  const filters = effectsToFilters(n.effects)
  if (filters.filter) lines.push(`filter: ${filters.filter};`)
  if (filters.backdropFilter) lines.push(`backdrop-filter: ${filters.backdropFilter};`)

  const bm = blendModeToCss(n.blendMode)
  if (bm && bm !== 'normal') lines.push(`mix-blend-mode: ${bm};`)

  return lines.length ? lines.join('\n') : null
}

// ── Helpers ──

function appendBorderRadius(n, lines) {
  if (n.rectangleCornerRadii) {
    const [tl, tr, br, bl] = n.rectangleCornerRadii
    if (tl || tr || br || bl) {
      if (tl === tr && tr === br && br === bl) {
        lines.push(`border-radius: ${rem(tl)};`)
      } else {
        lines.push(`border-radius: ${rem(tl)} ${rem(tr)} ${rem(br)} ${rem(bl)};`)
      }
    }
  } else if (n.cornerRadius > 0) {
    lines.push(`border-radius: ${rem(n.cornerRadius)};`)
  }
}

function resolveColorVar(globalRef, options) {
  if (!globalRef?.id || !options.globalColors) return null
  const token = options.globalColors.find(c => c.id === globalRef.id)
  if (!token) return null
  return `var(--color-${toCssName(token.name)})`
}

function resolveGradientVar(globalRef, options) {
  if (!globalRef?.id || !options.globalGradients) return null
  const token = options.globalGradients.find(g => g.id === globalRef.id)
  if (!token) return null
  return `var(--gradient-${toCssName(token.name)})`
}

function gradientToCss(gradient) {
  if (!gradient) return 'transparent'
  const angle = gradient.angle ?? 180
  const stops = (gradient.stops || [])
    .map(s => `${s.color} ${s.position != null ? s.position + '%' : ''}`)
    .join(', ')
  const type = gradient.type || 'linear'
  if (type === 'radial') return `radial-gradient(circle, ${stops})`
  return `linear-gradient(${angle}deg, ${stops})`
}
