<template>
  <svg
    v-if="visible"
    class="absolute inset-0 z-[9992] pointer-events-none"
    style="overflow: visible; width: 100%; height: 100%"
  >
    <!-- Saved measurements -->
    <g
      v-for="m in measurements"
      :key="'meas-' + m.id"
      class="measure-line-group pointer-events-auto"
    >
      <line
        :x1="sx(m.x1)" :y1="sy(m.y1)" :x2="sx(m.x2)" :y2="sy(m.y2)"
        :stroke="measureColor" :stroke-width="measureWidth" stroke-dasharray="6,3"
      />
      <!-- Fat invisible hit area for hover -->
      <line
        :x1="sx(m.x1)" :y1="sy(m.y1)" :x2="sx(m.x2)" :y2="sy(m.y2)"
        stroke="transparent" stroke-width="16"
      />
      <!-- Start dot -->
      <circle :cx="sx(m.x1)" :cy="sy(m.y1)" :r="measureWidth + 2" :fill="measureColor" />
      <!-- End dot -->
      <circle :cx="sx(m.x2)" :cy="sy(m.y2)" :r="measureWidth + 2" :fill="measureColor" />
      <!-- Distance pill -->
      <rect
        :x="midSx(m) - pillWidth(m) / 2"
        :y="midSy(m) - 22"
        :width="pillWidth(m)" height="20" rx="10"
        :fill="measureColor"
      />
      <text
        :x="midSx(m)" :y="midSy(m) - 8"
        text-anchor="middle" fill="white" font-size="11"
        font-family="Inter, system-ui, sans-serif" font-weight="600"
      >{{ m.distance }}px</text>
      <!-- W x H secondary label -->
      <text
        v-if="m.width > 0 && m.height > 0"
        :x="midSx(m)" :y="midSy(m) + 14"
        text-anchor="middle" :fill="measureColor" font-size="10"
        font-family="Inter, system-ui, sans-serif" font-weight="500"
      >{{ m.width }} &times; {{ m.height }}</text>
      <!-- Angle -->
      <text
        :x="midSx(m)"
        :y="midSy(m) + ((m.width > 0 && m.height > 0) ? 26 : 14)"
        text-anchor="middle" :fill="measureColor" font-size="9"
        font-family="Inter, system-ui, sans-serif" font-weight="500" opacity="0.7"
      >{{ m.angle }}&#176;</text>
      <!-- Delete button (hidden until hover) -->
      <g class="measure-close-btn cursor-pointer" @click="$emit('remove', m.id)">
        <circle :cx="sx(m.x2) + 8" :cy="sy(m.y2) - 8" r="7" fill="#ef4444" />
        <text
          :x="sx(m.x2) + 8" :y="sy(m.y2) - 4.5"
          text-anchor="middle" fill="white" font-size="10" font-weight="700"
        >x</text>
      </g>
    </g>

    <!-- Active line being drawn -->
    <g v-if="activeLine">
      <line
        :x1="sx(activeLine.x1)" :y1="sy(activeLine.y1)"
        :x2="sx(activeLine.x2)" :y2="sy(activeLine.y2)"
        :stroke="measureColor" :stroke-width="measureWidth" stroke-dasharray="6,3"
      />
      <circle :cx="sx(activeLine.x1)" :cy="sy(activeLine.y1)" :r="measureWidth + 2" :fill="measureColor" />
      <circle :cx="sx(activeLine.x2)" :cy="sy(activeLine.y2)" :r="measureWidth + 2" :fill="measureColor" />
      <!-- Distance pill -->
      <rect
        :x="midSx(activeLine) - pillWidth(activeLine) / 2"
        :y="midSy(activeLine) - 22"
        :width="pillWidth(activeLine)" height="20" rx="10"
        :fill="measureColor"
      />
      <text
        :x="midSx(activeLine)" :y="midSy(activeLine) - 8"
        text-anchor="middle" fill="white" font-size="11"
        font-family="Inter, system-ui, sans-serif" font-weight="600"
      >{{ activeLine.distance }}px</text>
      <!-- W x H -->
      <text
        v-if="activeLine.width > 0 && activeLine.height > 0"
        :x="midSx(activeLine)" :y="midSy(activeLine) + 14"
        text-anchor="middle" :fill="measureColor" font-size="10"
        font-family="Inter, system-ui, sans-serif" font-weight="500"
      >{{ activeLine.width }} &times; {{ activeLine.height }}</text>
      <!-- Angle -->
      <text
        :x="midSx(activeLine)"
        :y="midSy(activeLine) + ((activeLine.width > 0 && activeLine.height > 0) ? 26 : 14)"
        text-anchor="middle" :fill="measureColor" font-size="9"
        font-family="Inter, system-ui, sans-serif" font-weight="500" opacity="0.7"
      >{{ activeLine.angle }}&#176;</text>
    </g>
  </svg>
</template>

<script setup>
const props = defineProps({
  visible: Boolean,
  measurements: { type: Array, default: () => [] },
  activeLine: Object,
  measureColor: { type: String, default: '#0ea5e9' },
  measureWidth: { type: Number, default: 1.5 },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
})

defineEmits(['remove'])

function sx(canvasX) { return canvasX * props.zoom + props.panX }
function sy(canvasY) { return canvasY * props.zoom + props.panY }
function midSx(m) { return (m.x1 + m.x2) / 2 * props.zoom + props.panX }
function midSy(m) { return (m.y1 + m.y2) / 2 * props.zoom + props.panY }
function pillWidth(m) {
  const text = m.distance + 'px'
  return Math.max(text.length * 7 + 16, 44)
}
</script>

<style scoped>
.measure-close-btn {
  opacity: 0;
  transition: opacity 0.15s ease;
  pointer-events: none;
}
.measure-line-group:hover .measure-close-btn {
  opacity: 1;
  pointer-events: auto;
}
</style>
