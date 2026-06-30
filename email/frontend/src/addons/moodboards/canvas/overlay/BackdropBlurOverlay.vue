<template>
  <div
    class="absolute inset-0 pointer-events-none select-none"
    style="z-index: 4;"
  >
    <div :style="containerStyle">
      <div
        v-for="item in blurItems"
        :key="item.id"
        class="absolute"
        :style="itemStyle(item)"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { getStyleProps } from '../utils/styleToPixi.js'
import {
  fillsToCssBackground,
  strokesToCssBorder,
  effectsToBoxShadow,
  cornerRadiusToCss,
} from '../../utils/cssPaintUtils.js'

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

const blurItems = computed(() => {
  return props.items.filter(item => {
    if (item.style_data?._hidden || item.style_data?.mask_parent_id) return false
    const normalized = getStyleProps(item.type, item.style_data || {})
    if (!normalized.effects?.length) return false
    return normalized.effects.some(
      e => (e.type === 'BACKGROUND_BLUR' || e.type === 'LAYER_BLUR')
        && e.visible !== false && (e.radius ?? 0) > 0
    )
  })
})

function itemStyle(item) {
  const sd = item.style_data || {}
  const x = item.pos_x || 0
  const y = item.pos_y || 0
  const w = item.width || 100
  const h = item.height || 100
  const rot = item.rotation || 0
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

  const normalized = getStyleProps(item.type, sd)

  const bgBlurFx = normalized.effects?.find(
    e => e.type === 'BACKGROUND_BLUR' && e.visible !== false && (e.radius ?? 0) > 0
  )
  const layerBlurFx = normalized.effects?.find(
    e => e.type === 'LAYER_BLUR' && e.visible !== false && (e.radius ?? 0) > 0
  )

  const shapeType = sd.shape_type || 'rectangle'
  let borderRadius = '0'
  if (shapeType === 'circle' || shapeType === 'ellipse') {
    borderRadius = '50%'
  } else {
    borderRadius = cornerRadiusToCss(
      normalized.cornerRadius,
      normalized.rectangleCornerRadii
    ) || '0'
  }

  const background = fillsToCssBackground(normalized.fills) || 'transparent'
  const border = strokesToCssBorder(
    normalized.strokes,
    normalized.strokeWeight ?? sd.shape_border_width ?? sd.stroke_width ?? 0,
    normalized.strokeAlign || 'INSIDE',
    {},
    {}
  )
  const boxShadow = effectsToBoxShadow(normalized.effects)

  const opVal = normalized.opacity ?? 1
  const opacity = opVal < 0.999 ? opVal : undefined

  const style = {
    width: `${w}px`,
    height: `${h}px`,
    transform,
    transformOrigin: '0 0',
    borderRadius,
    background,
    overflow: layerBlurFx ? 'visible' : 'hidden',
    opacity,
  }

  if (bgBlurFx) {
    style.backdropFilter = `blur(${bgBlurFx.radius}px)`
    style.WebkitBackdropFilter = `blur(${bgBlurFx.radius}px)`
  }
  if (layerBlurFx) style.filter = `blur(${layerBlurFx.radius}px)`
  if (border) style.border = border
  if (boxShadow) style.boxShadow = boxShadow

  return style
}
</script>
