<template>
  <div
    class="absolute inset-0 pointer-events-none overflow-hidden select-none"
    style="z-index: 4;"
  >
    <div :style="containerStyle">
      <div
        v-for="item in visibleMediaItems"
        :key="item.id"
        class="absolute"
        :style="itemPositionStyle(item)"
      >
        <!-- VIDEO -->
        <div
          v-if="item.type === 'video'"
          class="w-full h-full rounded-xl bg-black overflow-hidden relative"
          :style="interactivityStyle(item)"
        >
          <video
            :src="item.url || item.image_url"
            class="w-full h-full object-contain bg-black"
            controls
            preload="metadata"
            playsinline
            :poster="item.style_data?.poster || ''"
          />
          <div
            v-if="item.title"
            class="absolute bottom-0 left-0 right-0 bg-black/70 text-white text-xs p-2 truncate pointer-events-none"
          >{{ item.title }}</div>
        </div>

        <!-- YOUTUBE -->
        <div
          v-else-if="item.type === 'youtube'"
          class="w-full h-full rounded-xl bg-black overflow-hidden relative"
          :style="youtubeWrapperStyle(item)"
        >
          <iframe
            :src="`https://www.youtube.com/embed/${youtubeId(item)}?rel=0`"
            class="w-full h-full border-0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
          />
          <!-- Click shield: iframes swallow pointer events, so keep one over the
               iframe until the item is selected in interactive mode (DOM parity) -->
          <div
            v-if="!youtubeIsInteractive(item)"
            class="absolute inset-0 z-10"
          />
        </div>

        <!-- AUDIO -->
        <div
          v-else-if="item.type === 'audio'"
          class="w-full h-full rounded-2xl overflow-hidden"
          :style="interactivityStyle(item)"
        >
          <MoodAudioPlayer
            :src="item.url"
            :title="item.title || ''"
            :volume="(item.style_data?.audio_volume ?? 80) / 100"
            :loop="!!item.style_data?.audio_loop"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import MoodAudioPlayer from '../../components/MoodAudioPlayer.vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  selectedIds: { type: Set, default: () => new Set() },
  editingItemId: { type: [Number, String], default: null },
  presentationMode: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  animating: { type: Boolean, default: false },
})

const MEDIA_TYPES = new Set(['video', 'youtube', 'audio'])

const containerStyle = computed(() => ({
  transform: `translate(${props.panX}px, ${props.panY}px) scale(${props.zoom})`,
  transformOrigin: '0 0',
  willChange: props.animating ? 'transform' : 'auto',
}))

const visibleMediaItems = computed(() =>
  props.items.filter(i => {
    if (!MEDIA_TYPES.has(i.type)) return false
    if (i.id === props.editingItemId) return false
    if (i.style_data?._hidden) return false
    if (i.style_data?.mask_parent_id) return false
    if (i.type === 'youtube') {
      if (!youtubeId(i)) return false
    } else if (!(i.url || (i.type === 'video' && i.image_url))) {
      return false
    }
    if (props.presentationMode && i.type === 'audio' && i.style_data?.audio_hidden_in_pres) return false
    return true
  })
)

/**
 * Media controls are live once the item is selected (or in readonly/shared
 * views where items can't be dragged anyway). Unselected items stay
 * click-through so the canvas hit-test can select them first.
 */
function isInteractive(item) {
  if (props.presentationMode) return false
  if (props.readonly) return true
  return props.selectedIds.has(item.id)
}

function interactivityStyle(item) {
  return { pointerEvents: isInteractive(item) ? 'auto' : 'none' }
}

function youtubeIsInteractive(item) {
  if (props.presentationMode) {
    return !!item.style_data?._youtubeInteractive
  }
  if (props.readonly) return true
  return props.selectedIds.has(item.id)
}

function youtubeWrapperStyle(item) {
  // Wrapper stays interactive so the click shield can bubble select/drag
  // events to the canvas; the shield is removed once truly interactive.
  if (props.presentationMode && !item.style_data?._youtubeInteractive) {
    return { pointerEvents: 'none' }
  }
  return { pointerEvents: 'auto' }
}

function youtubeId(item) {
  const url = item.url || ''
  if (!url) return null
  let m = url.match(/[?&]v=([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  m = url.match(/youtu\.be\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  m = url.match(/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  m = url.match(/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  return null
}

function itemPositionStyle(item) {
  const x = item.pos_x || 0
  const y = item.pos_y || 0
  const w = item.width || 320
  const h = item.height || 180
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
    mixBlendMode: sd.blend_mode && sd.blend_mode !== 'normal' ? sd.blend_mode : undefined,
  }
}

function normalizeOpacity(value) {
  if (value == null) return 1
  return value > 1 ? value / 100 : value
}
</script>
