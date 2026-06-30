<template>
  <div
    class="absolute inset-0 pointer-events-none overflow-hidden select-none"
    style="z-index: 4;"
  >
    <div :style="containerStyle">
      <div
        v-for="item in visibleDrawings"
        :key="item.id"
        class="absolute"
        :style="itemPositionStyle(item)"
      >
        <svg
          v-if="item._strokes?.length"
          class="w-full h-full"
          :viewBox="`0 0 ${item._vbW} ${item._vbH}`"
          preserveAspectRatio="xMidYMid meet"
        >
          <path
            v-for="(stroke, si) in item._strokes"
            :key="si"
            :d="stroke.svgPath"
            :fill="stroke.color || '#000000'"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { getStroke } from 'perfect-freehand'

const props = defineProps({
  items: { type: Array, default: () => [] },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  animating: { type: Boolean, default: false },
})

const containerStyle = computed(() => ({
  transform: `translate(${props.panX}px, ${props.panY}px) scale(${props.zoom})`,
  transformOrigin: '0 0',
  willChange: props.animating ? 'transform' : 'auto',
}))

const visibleDrawings = computed(() => {
  return props.items
    .filter(i => i.type === 'drawing' && !i.style_data?._hidden && !i.style_data?.mask_parent_id)
    .map(item => {
      const strokes = resolveStrokes(item)
      const vb = resolveViewBox(item, strokes)
      return { ...item, _strokes: strokes, _vbW: vb.w, _vbH: vb.h }
    })
    .filter(i => i._strokes.length > 0)
})

function resolveStrokes(item) {
  let raw = null
  if (item.content) {
    try {
      const data = typeof item.content === 'string' ? JSON.parse(item.content) : item.content
      if (data?.strokes?.length) raw = data.strokes
    } catch { /* ignore */ }
  }
  if (!raw) {
    let sd = item.style_data || {}
    if (typeof sd === 'string') { try { sd = JSON.parse(sd) } catch { sd = {} } }
    const legacy = sd.strokes_data || sd.drawing_strokes
    if (legacy?.length) raw = legacy
  }
  if (!raw) return []
  return raw.map(ensureSvgPath).filter(s => s.svgPath)
}

function resolveViewBox(item, strokes) {
  let sd = item.style_data || {}
  if (typeof sd === 'string') { try { sd = JSON.parse(sd) } catch { sd = {} } }
  if (item.content) {
    try {
      const data = typeof item.content === 'string' ? JSON.parse(item.content) : item.content
      const cw = parseInt(data?.width)
      const ch = parseInt(data?.height)
      if (cw > 0 && ch > 0) return { w: cw, h: ch }
    } catch { /* ignore */ }
  }
  return { w: sd.original_width || item.width || 200, h: sd.original_height || item.height || 150 }
}

function ensureSvgPath(stroke) {
  if (stroke.svgPath) return stroke
  if (!stroke.points?.length || stroke.points.length < 2) return stroke
  const pts = stroke.points.map(p => Array.isArray(p) ? p : [p.x, p.y, 0.5])
  const sz = stroke.width || 8
  const opts = stroke.options || {}
  const outline = getStroke(pts, {
    size: sz,
    thinning: opts.thinning ?? 0.5,
    smoothing: opts.smoothing ?? 0.7,
    streamline: opts.streamline ?? 0.6,
    easing: (t) => Math.sin((t * Math.PI) / 2),
    start: { taper: opts.taperEnabled !== false ? sz * 0.5 : 0, cap: true, easing: (t) => t * t },
    end: { taper: opts.taperEnabled !== false ? sz * 0.5 : 0, cap: true, easing: (t) => 1 - (1 - t) * (1 - t) },
    simulatePressure: opts.simulatePressure ?? true,
  })
  if (!outline || outline.length < 2) return stroke
  return { ...stroke, svgPath: outlineToSvgPath(outline) }
}

function outlineToSvgPath(outline) {
  const len = outline.length
  let d = `M ${outline[0][0].toFixed(2)} ${outline[0][1].toFixed(2)} Q`
  for (let i = 0; i < len; i++) {
    const [x0, y0] = outline[i]
    const [x1, y1] = outline[(i + 1) % len]
    d += ` ${x0.toFixed(2)} ${y0.toFixed(2)} ${((x0 + x1) / 2).toFixed(2)} ${((y0 + y1) / 2).toFixed(2)}`
  }
  d += ' Z'
  return d
}

function itemPositionStyle(item) {
  const x = item.pos_x || 0
  const y = item.pos_y || 0
  const w = item.width || 200
  const h = item.height || 150
  const rot = item.rotation || 0
  const sd = item.style_data || {}
  const opacity = normalizeOpacity(sd.opacity)
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
  }
}

function normalizeOpacity(value) {
  if (value == null) return 1
  return value > 1 ? value / 100 : value
}
</script>
