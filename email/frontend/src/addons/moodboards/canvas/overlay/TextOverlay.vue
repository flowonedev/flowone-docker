<template>
  <div
    class="absolute inset-0 pointer-events-none overflow-hidden select-none"
    style="z-index: 4;"
  >
    <div :style="containerStyle">
      <div
        v-for="item in visibleTextItems"
        :key="item.id"
        class="absolute"
        :style="itemPositionStyle(item)"
      >
        <div
          class="w-full h-full break-words whitespace-pre-wrap overflow-visible"
          :style="itemTextStyle(item)"
          v-html="renderContent(item)"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  editingItemId: { type: [Number, String], default: null },
  animating: { type: Boolean, default: false },
})

const containerStyle = computed(() => ({
  transform: `translate(${props.panX}px, ${props.panY}px) scale(${props.zoom})`,
  transformOrigin: '0 0',
  willChange: props.animating ? 'transform' : 'auto',
}))

const visibleTextItems = computed(() =>
  props.items.filter(i =>
    i.type === 'text'
    && i.id !== props.editingItemId
    && !i.style_data?._hidden
    && !i.style_data?.mask_parent_id
  )
)

function itemPositionStyle(item) {
  const x = item.pos_x || 0
  const y = item.pos_y || 0
  const w = item.width || 200
  const h = item.height || 100
  const rot = item.rotation || 0
  const sd = item.style_data || {}
  const opacity = normalizeOpacity(sd.text_opacity ?? sd.opacity)
  const itemScale = sd.item_scale ?? 1
  const flipX = sd.flip_x ? -1 : 1
  const flipY = sd.flip_y ? -1 : 1
  const scaleX = itemScale * flipX
  const scaleY = itemScale * flipY

  const needsScaleRot = scaleX !== 1 || scaleY !== 1 || rot
  let transform
  if (needsScaleRot) {
    const hw = w / 2
    const hh = h / 2
    transform = `translate(${x + hw}px, ${y + hh}px)`
    if (scaleX !== 1 || scaleY !== 1) transform += ` scale(${scaleX}, ${scaleY})`
    if (rot) transform += ` rotate(${rot}deg)`
    transform += ` translate(${-hw}px, ${-hh}px)`
  } else {
    transform = `translate(${x}px, ${y}px)`
  }

  return {
    width: `${w}px`,
    height: `${h}px`,
    transform,
    transformOrigin: '0 0',
    opacity: opacity < 0.999 ? opacity : undefined,
    mixBlendMode: sd.blend_mode && sd.blend_mode !== 'normal' ? sd.blend_mode : undefined,
  }
}

const ICON_FONTS = new Set(['Material Symbols Rounded', 'Material Symbols Outlined'])

function isIconFont(fontFamily) {
  return ICON_FONTS.has(fontFamily)
}

function itemTextStyle(item) {
  const sd = item.style_data || {}
  const textData = sd.text || {}
  const fontSize = getNumeric(sd.font_size ?? sd.fontSize ?? textData.fontSize, 14)
  const padding = getNumeric(sd.text_padding, 12)
  const letterSpacing = getNumeric(sd.letter_spacing, 0)
  const fontFamily = sd.font_family || sd.fontFamily || textData.fontFamily || 'Inter'
  const iconFont = isIconFont(fontFamily)

  const style = {
    fontSize: `${fontSize}px`,
    fontFamily,
    fontWeight: sd.font_weight || sd.fontWeight || textData.fontWeight || '400',
    fontStyle: sd.font_style || textData.fontStyle || 'normal',
    textAlign: String(sd.text_align || sd.textAlign || textData.textAlignHorizontal || textData.textAlign || 'left').toLowerCase(),
    textTransform: sd.text_transform || 'none',
    textDecoration: formatTextDecoration(sd.text_decoration || textData.textDecoration),
    letterSpacing: `${letterSpacing}px`,
    lineHeight: formatLineHeight(sd.line_height),
    padding: `${padding}px`,
    textRendering: 'geometricPrecision',
    WebkitFontSmoothing: 'antialiased',
    MozOsxFontSmoothing: 'grayscale',
    fontFeatureSettings: "'liga'",
  }

  if (iconFont) {
    style.whiteSpace = 'nowrap'
    style.wordWrap = 'normal'
    style.overflow = 'hidden'
    style.direction = 'ltr'
    style.lineHeight = 1
    style.letterSpacing = 'normal'
    style.textTransform = 'none'
  }

  if (sd.text_clip_image) {
    style.backgroundImage = `url(${sd.text_clip_image})`
    style.backgroundSize = sd.text_clip_image_size || 'cover'
    style.backgroundPosition = 'center'
    style.WebkitBackgroundClip = 'text'
    style.backgroundClip = 'text'
    style.WebkitTextFillColor = 'transparent'
    style.color = 'transparent'
  } else {
    const gradientCSS = _buildGradientCSS(sd.text_fill_type, sd.text_color, sd.text_fill_gradient)
    if (gradientCSS) {
      style.background = gradientCSS
      style.WebkitBackgroundClip = 'text'
      style.backgroundClip = 'text'
      style.WebkitTextFillColor = 'transparent'
      style.color = 'transparent'
    } else {
      style.color = sd.text_color || '#333333'
    }
  }

  const strokeW = getNumeric(sd.text_stroke_width, 0)
  const strokeC = sd.text_stroke_color || 'transparent'
  if (strokeW > 0 && strokeC && strokeC !== 'transparent') {
    style.WebkitTextStroke = `${strokeW}px ${strokeC}`
    style.paintOrder = 'stroke fill'
  }

  const shadows = []
  if (sd.text_shadow_enabled) {
    const tsx = getNumeric(sd.text_shadow_x, 1)
    const tsy = getNumeric(sd.text_shadow_y, 2)
    const tsb = getNumeric(sd.text_shadow_blur, 4)
    const tsc = sd.text_shadow_color || '#000000'
    const tso = (sd.text_shadow_opacity ?? 40) / 100
    shadows.push(`${tsx}px ${tsy}px ${tsb}px ${_hexToRgba(tsc, tso)}`)
  }
  if (shadows.length) style.textShadow = shadows.join(', ')

  return style
}

function getNumeric(value, fallback) {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  const parsed = parseFloat(value)
  return Number.isFinite(parsed) ? parsed : fallback
}

function normalizeOpacity(value) {
  if (value == null) return 1
  return value > 1 ? value / 100 : value
}

function formatLineHeight(value) {
  if (value == null) return 1
  const numeric = getNumeric(value, 1)
  if (numeric <= 10) return numeric
  return `${numeric}px`
}

function formatTextDecoration(value) {
  if (!value || value === 'NONE' || value === 'none') return 'none'
  const v = String(value).toUpperCase()
  if (v === 'UNDERLINE') return 'underline'
  if (v === 'STRIKETHROUGH' || v === 'LINE_THROUGH') return 'line-through'
  return String(value).toLowerCase()
}

function renderContent(item) {
  const content = item.content || ''
  if (!content) return '<span style="opacity:0.4">Double-click to edit</span>'
  if (/<[a-z][\s\S]*>/i.test(content)) return _sanitizeHtml(content)
  return content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\r\n/g, '<br>')
    .replace(/\r/g, '<br>')
    .replace(/\n/g, '<br>')
}

function _sanitizeHtml(html) {
  if (!html) return ''
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/\son\w+\s*=\s*"[^"]*"/gi, '')
    .replace(/\son\w+\s*=\s*'[^']*'/gi, '')
    .replace(/javascript\s*:/gi, '')
}

function _buildGradientCSS(fillType, fillColor, gradient) {
  if (!fillType || fillType === 'solid') return null
  const stops = gradient?.stops
  if (!stops || stops.length < 2) return null
  const stopsStr = [...stops].sort((a, b) => a.position - b.position)
    .map(s => `${s.color} ${s.position}%`).join(', ')
  if (fillType === 'radial') return `radial-gradient(circle, ${stopsStr})`
  return `linear-gradient(${gradient?.angle ?? gradient?.gradientAngle ?? 180}deg, ${stopsStr})`
}

function _hexToRgba(hex, alpha) {
  hex = (hex || '#000000').replace('#', '')
  if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]
  const r = parseInt(hex.substring(0, 2), 16) || 0
  const g = parseInt(hex.substring(2, 4), 16) || 0
  const b = parseInt(hex.substring(4, 6), 16) || 0
  return `rgba(${r},${g},${b},${alpha})`
}
</script>
