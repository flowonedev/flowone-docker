<template>
  <g 
    class="collab-slide-shape cursor-pointer"
    :style="{ pointerEvents: 'all' }"
    @click.stop="$emit('select', $event.shiftKey)"
  >
    <!-- Rectangle -->
    <rect
      v-if="object.shapeType === 'rectangle'"
      :x="object.x"
      :y="object.y"
      :width="object.width"
      :height="object.height"
      :fill="object.fill"
      :stroke="object.stroke"
      :stroke-width="object.strokeWidth"
      :transform="rotationTransform"
      :rx="object.borderRadius || 0"
    />
    
    <!-- Legacy Ellipse / Circle support -->
    <ellipse
      v-else-if="object.shapeType === 'ellipse'"
      :cx="object.x + object.width / 2"
      :cy="object.y + object.height / 2"
      :rx="object.width / 2"
      :ry="object.height / 2"
      :fill="object.fill"
      :stroke="object.stroke"
      :stroke-width="object.strokeWidth"
      :transform="rotationTransform"
    />
    
    <!-- Legacy Triangle support -->
    <polygon
      v-else-if="object.shapeType === 'triangle'"
      :points="trianglePoints"
      :fill="object.fill"
      :stroke="object.stroke"
      :stroke-width="object.strokeWidth"
      :transform="rotationTransform"
    />
    
    <!-- Legacy Line support -->
    <line
      v-else-if="object.shapeType === 'line'"
      :x1="object.x"
      :y1="object.y + object.height / 2"
      :x2="object.x + object.width"
      :y2="object.y + object.height / 2"
      :stroke="object.stroke || object.fill"
      :stroke-width="object.strokeWidth || 2"
      :transform="rotationTransform"
    />
    
    <!-- Legacy Arrow support -->
    <g v-else-if="object.shapeType === 'arrow'" :transform="rotationTransform">
      <line
        :x1="object.x"
        :y1="object.y + object.height / 2"
        :x2="object.x + object.width - 15"
        :y2="object.y + object.height / 2"
        :stroke="object.stroke || object.fill"
        :stroke-width="object.strokeWidth || 2"
      />
      <polygon
        :points="arrowHeadPoints"
        :fill="object.stroke || object.fill"
      />
    </g>
    
    <!-- Material Symbol Icons -->
    <text
      v-else-if="shapeDef?.isIcon"
      :x="object.x + object.width / 2"
      :y="object.y + object.height / 2"
      :font-size="iconFontSize"
      font-family="'Material Symbols Rounded'"
      :fill="object.fill || '#2196F3'"
      text-anchor="middle"
      dominant-baseline="central"
      :transform="rotationTransform"
      style="font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 48;"
    >{{ shapeDef.iconName }}</text>
    
    <!-- Extended shapes using path-based rendering -->
    <path
      v-else-if="shapePath"
      :d="shapePath"
      :fill="shapeDef?.noFill ? 'none' : (object.fill || '#2196F3')"
      :stroke="object.stroke || '#1976D2'"
      :stroke-width="object.strokeWidth || 2"
      :transform="pathTransform"
    />
    
    <!-- Fallback for unknown shapes -->
    <rect
      v-else
      :x="object.x"
      :y="object.y"
      :width="object.width"
      :height="object.height"
      :fill="object.fill || '#cccccc'"
      :stroke="object.stroke || '#999999'"
      :stroke-width="object.strokeWidth || 2"
      :transform="rotationTransform"
    />
    
    <!-- Selection outline -->
    <rect
      v-if="selected"
      :x="object.x - 2"
      :y="object.y - 2"
      :width="object.width + 4"
      :height="object.height + 4"
      fill="none"
      stroke="#2196F3"
      stroke-width="2"
      stroke-dasharray="4,4"
      :transform="rotationTransform"
    />
  </g>
</template>

<script setup>
import { computed } from 'vue'
import { getShapeDefinition, getShapeSvgPath } from '../data/shapeLibrary.js'

const props = defineProps({
  object: {
    type: Object,
    required: true,
  },
  selected: {
    type: Boolean,
    default: false,
  },
  scale: {
    type: Number,
    default: 1,
  },
})

defineEmits(['select'])

// Get shape definition from library
const shapeDef = computed(() => {
  const shapeType = props.object.shapeType
  // Skip legacy shapes that have dedicated rendering
  if (['rectangle', 'ellipse', 'triangle', 'line', 'arrow'].includes(shapeType)) {
    return null
  }
  return getShapeDefinition(shapeType)
})

// Generate SVG path for extended shapes
const shapePath = computed(() => {
  if (!shapeDef.value || !shapeDef.value.getSvgPath) return null
  return getShapeSvgPath(props.object.shapeType, props.object.width, props.object.height)
})

// Calculate font size for icon shapes (use smaller dimension to fit)
const iconFontSize = computed(() => {
  const minDim = Math.min(props.object.width, props.object.height)
  return minDim * 0.85 // 85% of smaller dimension for good fit
})

// Transform for path-based shapes (includes translation to position)
const pathTransform = computed(() => {
  let transform = `translate(${props.object.x}, ${props.object.y})`
  if (props.object.rotation) {
    const cx = props.object.width / 2
    const cy = props.object.height / 2
    transform += ` rotate(${props.object.rotation} ${cx} ${cy})`
  }
  return transform
})

const rotationTransform = computed(() => {
  if (!props.object.rotation) return ''
  
  const cx = props.object.x + props.object.width / 2
  const cy = props.object.y + props.object.height / 2
  return `rotate(${props.object.rotation} ${cx} ${cy})`
})

const trianglePoints = computed(() => {
  const { x, y, width, height } = props.object
  const topX = x + width / 2
  const topY = y
  const bottomLeftX = x
  const bottomLeftY = y + height
  const bottomRightX = x + width
  const bottomRightY = y + height
  
  return `${topX},${topY} ${bottomLeftX},${bottomLeftY} ${bottomRightX},${bottomRightY}`
})

const arrowHeadPoints = computed(() => {
  const { x, y, width, height } = props.object
  const tipX = x + width
  const tipY = y + height / 2
  const backX = x + width - 15
  const backTopY = y + height / 2 - 8
  const backBottomY = y + height / 2 + 8
  
  return `${tipX},${tipY} ${backX},${backTopY} ${backX},${backBottomY}`
})
</script>
