<template>
  <div v-if="visible" class="absolute inset-0 pointer-events-none" style="z-index: 30;">
    <div
      v-for="h in handles"
      :key="h.corner"
      class="absolute w-[8px] h-[8px] rounded-full bg-primary-400 border border-white z-30 cursor-pointer hover:bg-primary-500 hover:scale-125 transition-all pointer-events-auto"
      :style="handleStyle(h)"
      :title="'Drag to adjust corner radius (' + h.corner.toUpperCase() + ')'"
      @pointerdown.stop="startDrag($event, h.corner)"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useMoodBoardsStore } from '../../stores/moodBoards.js'

const props = defineProps({
  item: Object,
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
})

const store = useMoodBoardsStore()

const visible = computed(() => {
  if (!props.item) return false
  if (props.item.type !== 'shape') return false
  const st = props.item.style_data?.shape_type || 'rectangle'
  return st === 'rectangle' || !st
})

const corners = computed(() => {
  const sd = props.item?.style_data || {}
  const r = sd.shape_border_radius || 0
  return {
    tl: sd.shape_border_radius_tl ?? r,
    tr: sd.shape_border_radius_tr ?? r,
    br: sd.shape_border_radius_br ?? r,
    bl: sd.shape_border_radius_bl ?? r,
  }
})

const handles = computed(() => {
  if (!visible.value) return []
  const w = props.item.width || 200
  const h = props.item.height || 200
  const maxR = Math.min(w, h) / 2
  const c = corners.value
  const clamp = (v) => Math.min(v, maxR)
  const minOff = 12
  return [
    { corner: 'tl', x: Math.max(clamp(c.tl), minOff), y: Math.max(clamp(c.tl), minOff) },
    { corner: 'tr', x: w - Math.max(clamp(c.tr), minOff), y: Math.max(clamp(c.tr), minOff) },
    { corner: 'br', x: w - Math.max(clamp(c.br), minOff), y: h - Math.max(clamp(c.br), minOff) },
    { corner: 'bl', x: Math.max(clamp(c.bl), minOff), y: h - Math.max(clamp(c.bl), minOff) },
  ]
})

function handleStyle(h) {
  const ix = (props.item.pos_x || 0)
  const iy = (props.item.pos_y || 0)
  const sx = (ix + h.x) * props.zoom + props.panX
  const sy = (iy + h.y) * props.zoom + props.panY
  const scale = Math.min(1, Math.max(0.4, props.zoom))
  return {
    left: sx + 'px',
    top: sy + 'px',
    transform: `translate(-50%, -50%) scale(${scale})`,
  }
}

function startDrag(e, corner) {
  e.preventDefault()
  const startX = e.clientX
  const startY = e.clientY
  const w = props.item.width || 200
  const h = props.item.height || 200
  const maxR = Math.min(w, h) / 2
  const sd = props.item.style_data || {}
  const startR = sd[`shape_border_radius_${corner}`] ?? sd.shape_border_radius ?? 0

  const onMove = (ev) => {
    const dx = (ev.clientX - startX) / props.zoom
    const dy = (ev.clientY - startY) / props.zoom
    let delta
    if (corner === 'tl') delta = (dx + dy) / 2
    else if (corner === 'tr') delta = (-dx + dy) / 2
    else if (corner === 'br') delta = (-dx - dy) / 2
    else delta = (dx - dy) / 2

    const newR = Math.round(Math.max(0, Math.min(maxR, startR + delta)))
    const linked = sd.shape_corners_linked !== false
    if (linked) {
      store.updateItem(props.item.id, {
        style_data: {
          ...sd,
          shape_border_radius: newR,
          shape_border_radius_tl: newR,
          shape_border_radius_tr: newR,
          shape_border_radius_br: newR,
          shape_border_radius_bl: newR,
        },
      }, { skipUndo: true })
    } else {
      store.updateItem(props.item.id, {
        style_data: { ...sd, [`shape_border_radius_${corner}`]: newR },
      }, { skipUndo: true })
    }
  }

  const onUp = () => {
    document.removeEventListener('pointermove', onMove)
    document.removeEventListener('pointerup', onUp)
  }

  document.addEventListener('pointermove', onMove)
  document.addEventListener('pointerup', onUp)
}
</script>
