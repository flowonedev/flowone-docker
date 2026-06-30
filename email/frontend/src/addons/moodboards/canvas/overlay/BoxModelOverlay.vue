<template>
  <div v-if="overlay" class="absolute pointer-events-none" :style="containerStyle" style="z-index: 20;">
    <!-- Padding bands (green) -->
    <div v-if="overlay.pt" class="absolute" :style="{ top: 0, left: overlay.pl + 'px', right: overlay.pr + 'px', height: overlay.pt + 'px', background: 'rgba(147,196,125,0.4)' }">
      <span class="absolute inset-0 flex items-center justify-center text-green-900 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.pt - 2) + 'px', opacity: overlay.pt >= 8 ? 1 : 0 }">{{ overlay.pt }}</span>
    </div>
    <div v-if="overlay.pb" class="absolute" :style="{ bottom: 0, left: overlay.pl + 'px', right: overlay.pr + 'px', height: overlay.pb + 'px', background: 'rgba(147,196,125,0.4)' }">
      <span class="absolute inset-0 flex items-center justify-center text-green-900 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.pb - 2) + 'px', opacity: overlay.pb >= 8 ? 1 : 0 }">{{ overlay.pb }}</span>
    </div>
    <div v-if="overlay.pl" class="absolute" :style="{ top: overlay.pt + 'px', bottom: overlay.pb + 'px', left: 0, width: overlay.pl + 'px', background: 'rgba(147,196,125,0.4)' }">
      <span class="absolute inset-0 flex items-center justify-center text-green-900 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.pl - 2) + 'px', opacity: overlay.pl >= 8 ? 1 : 0 }">{{ overlay.pl }}</span>
    </div>
    <div v-if="overlay.pr" class="absolute" :style="{ top: overlay.pt + 'px', bottom: overlay.pb + 'px', right: 0, width: overlay.pr + 'px', background: 'rgba(147,196,125,0.4)' }">
      <span class="absolute inset-0 flex items-center justify-center text-green-900 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.pr - 2) + 'px', opacity: overlay.pr >= 8 ? 1 : 0 }">{{ overlay.pr }}</span>
    </div>
    <!-- Margin bands (orange) -->
    <div v-if="overlay.mt" class="absolute" :style="{ top: -overlay.mt + 'px', left: -overlay.ml + 'px', right: -overlay.mr + 'px', height: overlay.mt + 'px', background: 'rgba(246,178,107,0.35)' }">
      <span class="absolute inset-0 flex items-center justify-center text-orange-800 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.mt - 2) + 'px', opacity: overlay.mt >= 8 ? 1 : 0 }">{{ overlay.mt }}</span>
    </div>
    <div v-if="overlay.mb" class="absolute" :style="{ bottom: -overlay.mb + 'px', left: -overlay.ml + 'px', right: -overlay.mr + 'px', height: overlay.mb + 'px', background: 'rgba(246,178,107,0.35)' }">
      <span class="absolute inset-0 flex items-center justify-center text-orange-800 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.mb - 2) + 'px', opacity: overlay.mb >= 8 ? 1 : 0 }">{{ overlay.mb }}</span>
    </div>
    <div v-if="overlay.ml" class="absolute" :style="{ top: -overlay.mt + 'px', bottom: -overlay.mb + 'px', left: -overlay.ml + 'px', width: overlay.ml + 'px', background: 'rgba(246,178,107,0.35)' }">
      <span class="absolute inset-0 flex items-center justify-center text-orange-800 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.ml - 2) + 'px', opacity: overlay.ml >= 8 ? 1 : 0 }">{{ overlay.ml }}</span>
    </div>
    <div v-if="overlay.mr" class="absolute" :style="{ top: -overlay.mt + 'px', bottom: -overlay.mb + 'px', right: -overlay.mr + 'px', width: overlay.mr + 'px', background: 'rgba(246,178,107,0.35)' }">
      <span class="absolute inset-0 flex items-center justify-center text-orange-800 font-mono select-none" :style="{ fontSize: Math.min(10, overlay.mr - 2) + 'px', opacity: overlay.mr >= 8 ? 1 : 0 }">{{ overlay.mr }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: Object,
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  selected: Boolean,
  presentationMode: Boolean,
})

const overlay = computed(() => {
  if (!props.selected || props.presentationMode || !props.item) return null
  const sd = props.item.style_data || {}
  const n = v => (v === 'auto' || !v) ? 0 : v
  const pt = sd.padding_top || 0, pr = sd.padding_right || 0
  const pb = sd.padding_bottom || 0, pl = sd.padding_left || 0
  const mt = n(sd.margin_top), mr = n(sd.margin_right)
  const mb = n(sd.margin_bottom), ml = n(sd.margin_left)
  if (!pt && !pr && !pb && !pl && !mt && !mr && !mb && !ml) return null
  return { pt, pr, pb, pl, mt, mr, mb, ml }
})

const containerStyle = computed(() => {
  if (!props.item) return {}
  const x = (props.item.pos_x || 0) * props.zoom + props.panX
  const y = (props.item.pos_y || 0) * props.zoom + props.panY
  const w = (props.item.width || 0) * props.zoom
  const h = (props.item.height || 0) * props.zoom
  return { left: x + 'px', top: y + 'px', width: w + 'px', height: h + 'px' }
})
</script>
