<template>
  <div
    class="h-full w-full"
    @dragover.prevent
    @drop.prevent="onDropImages"
  >
    <!-- STACKED VIEW (default — fanned card appearance) -->
    <div
      v-if="!expanded && images.length > 0"
      class="relative w-full h-full flex items-center justify-center"
    >
      <!-- Title bar (hover only, hidden at very low zoom) -->
      <div v-if="store.zoom >= 0.3" class="absolute top-0 left-0 right-0 z-20 px-3 py-1.5 bg-white/80 dark:bg-surface-800/80 backdrop-blur-sm border-b border-surface-200/50 dark:border-surface-700/50 rounded-t-xl flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
        <span class="material-symbols-rounded text-sm text-surface-400">photo_library</span>
        <span class="text-xs font-semibold text-surface-700 dark:text-surface-300 truncate">{{ item.title || 'Image Stack' }}</span>
        <span class="ml-auto bg-primary-500 text-white text-[10px] font-bold rounded-full min-w-[20px] h-5 flex items-center justify-center px-1.5">{{ images.length }}</span>
      </div>

      <!-- Stacked card previews — fanned like playing cards -->
      <div class="relative" :style="stackContainerStyle">
        <div
          v-for="(img, idx) in stackPreviewImages"
          :key="img.id || idx"
          class="absolute rounded-lg overflow-hidden shadow-md border-2 border-white dark:border-surface-700 transition-transform duration-200"
          :class="cardMotionActive ? 'stack-card-wobble' : ''"
          :style="{ ...getStackCardStyle(idx, stackPreviewImages.length), ...getCardMotionStyle(idx) }"
        >
          <img
            v-if="!failedImages[img.id || idx]"
            :src="getResolvedSrc(img)"
            :alt="img.original_filename || 'Image'"
            class="w-full h-full object-cover"
            draggable="false"
            decoding="async"
            @error="onImgError(img, idx)"
          />
          <div v-else class="w-full h-full flex items-center justify-center bg-surface-100 dark:bg-surface-700">
            <span class="material-symbols-rounded text-2xl text-surface-400">broken_image</span>
          </div>
        </div>
      </div>

      <!-- Open stack button (hover only) -->
      <div class="absolute inset-0 z-20 flex items-center justify-center pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200">
        <button
          @click.stop="expanded = true"
          class="pointer-events-auto px-4 py-2 rounded-full bg-white/90 dark:bg-surface-800/90 hover:bg-white dark:hover:bg-surface-700 text-surface-800 dark:text-surface-200 shadow-lg backdrop-blur-sm transition-all hover:scale-105 flex items-center gap-2 text-sm font-medium border border-surface-200/50 dark:border-surface-600/50"
        >
          <span class="material-symbols-rounded text-lg">open_in_full</span>
          Open Stack
        </button>
      </div>
    </div>

    <!-- EXPANDED GRID VIEW (overlay within the item) -->
    <div
      v-else-if="expanded && images.length > 0"
      class="flex flex-col h-full rounded-xl overflow-hidden bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700"
    >
      <!-- Header -->
      <div class="flex items-center gap-2 px-3 py-2 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
        <span class="material-symbols-rounded text-sm text-surface-400">photo_library</span>
        <span class="text-xs font-semibold text-surface-700 dark:text-surface-300 truncate">{{ item.title || 'Image Stack' }}</span>
        <span class="text-[10px] text-surface-400 ml-1">({{ images.length }})</span>
        <div class="ml-auto flex items-center gap-1">
          <button
            v-if="!readonly"
            @click.stop="$emit('add-images')"
            class="p-1 rounded-lg text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors"
            title="Add images"
          >
            <span class="material-symbols-rounded text-base">add_photo_alternate</span>
          </button>
          <button
            @click.stop="expanded = false"
            class="p-1 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            title="Collapse"
          >
            <span class="material-symbols-rounded text-base">close_fullscreen</span>
          </button>
        </div>
      </div>

      <!-- Image grid -->
      <div class="flex-1 overflow-y-auto p-1.5">
        <div class="grid gap-1.5" :class="expandedGridClass">
          <div
            v-for="(img, idx) in images"
            :key="img.id || idx"
            class="relative rounded-lg overflow-hidden group cursor-pointer bg-surface-100 dark:bg-surface-700"
            :class="expandedImageClass"
            @click.stop="openLightbox(idx)"
          >
            <img
              v-if="!failedImages[img.id || idx]"
              :src="getResolvedSrc(img)"
              :alt="img.original_filename || 'Image'"
              class="w-full h-full object-cover"
              draggable="false"
              decoding="async"
              @error="onImgError(img, idx)"
            />
            <div v-else class="w-full h-full flex flex-col items-center justify-center gap-1">
              <span class="material-symbols-rounded text-2xl text-surface-400">broken_image</span>
              <span class="text-[9px] text-surface-400">Failed to load</span>
            </div>
            <!-- Remove button -->
            <button
              v-if="!readonly"
              @click.stop="$emit('remove-image', img.id)"
              class="absolute top-1 right-1 p-0.5 rounded-full bg-black/50 text-white opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-500"
            >
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
            <!-- Filename overlay -->
            <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/50 to-transparent p-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
              <p class="text-[10px] text-white truncate">{{ img.original_filename || '' }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- EMPTY / DROP ZONE -->
    <div
      v-else
      class="flex flex-col items-center justify-center h-full min-h-[120px] rounded-xl bg-surface-50 dark:bg-surface-800 border-2 border-dashed border-surface-300 dark:border-surface-600 text-surface-400 gap-2 p-4"
      @dragover.prevent
      @drop.prevent="onDropImages"
    >
      <span class="material-symbols-rounded text-3xl">add_photo_alternate</span>
      <span class="text-xs text-center leading-relaxed">Drop images here<br>or double-click to browse</span>
    </div>

    <!-- LIGHTBOX / IMAGE SLIDER (teleported to body so it's above everything) -->
    <Teleport to="body">
      <Transition name="lightbox-fade">
        <div
          v-if="lightboxOpen"
          class="fixed inset-0 z-[9999] flex items-center justify-center"
          @click.self="closeLightbox"
        >
          <!-- Backdrop -->
          <div class="absolute inset-0 bg-black/90 backdrop-blur-sm" @click="closeLightbox"></div>

          <!-- Top bar -->
          <div class="absolute top-0 left-0 right-0 z-10 flex items-center justify-between px-4 py-3 bg-gradient-to-b from-black/60 to-transparent">
            <div class="flex items-center gap-3">
              <span class="text-white/90 text-sm font-medium">{{ lightboxIndex + 1 }} / {{ images.length }}</span>
              <span v-if="currentLightboxImage?.original_filename" class="text-white/60 text-xs truncate max-w-[300px]">{{ currentLightboxImage.original_filename }}</span>
            </div>
            <button
              @click="closeLightbox"
              class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors"
            >
              <span class="material-symbols-rounded text-xl">close</span>
            </button>
          </div>

          <!-- Main image area -->
          <div class="relative z-[1] w-full h-full flex items-center justify-center px-16 py-16">
            <img
              v-if="currentLightboxImage && !failedImages[currentLightboxImage.id || lightboxIndex]"
              :src="getResolvedSrc(currentLightboxImage, false)"
              :alt="currentLightboxImage.original_filename || 'Image'"
              class="max-w-full max-h-full object-contain rounded-lg shadow-2xl select-none transition-opacity duration-200"
              draggable="false"
              decoding="async"
              @error="onImgError(currentLightboxImage, lightboxIndex)"
            />
            <div v-else class="flex flex-col items-center gap-3 text-white/50">
              <span class="material-symbols-rounded text-6xl">broken_image</span>
              <span class="text-sm">Failed to load image</span>
            </div>
          </div>

          <!-- Previous button -->
          <button
            v-if="images.length > 1"
            @click.stop="prevImage"
            class="absolute left-3 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110"
          >
            <span class="material-symbols-rounded text-2xl">chevron_left</span>
          </button>

          <!-- Next button -->
          <button
            v-if="images.length > 1"
            @click.stop="nextImage"
            class="absolute right-3 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110"
          >
            <span class="material-symbols-rounded text-2xl">chevron_right</span>
          </button>

          <!-- Thumbnail strip at bottom -->
          <div v-if="images.length > 1" class="absolute bottom-0 left-0 right-0 z-10 bg-gradient-to-t from-black/60 to-transparent py-3 px-4">
            <div class="flex items-center justify-center gap-1.5 overflow-x-auto max-w-full py-1 px-2">
              <button
                v-for="(img, idx) in images"
                :key="img.id || idx"
                @click.stop="lightboxIndex = idx"
                class="flex-shrink-0 w-12 h-12 rounded-lg overflow-hidden border-2 transition-all hover:scale-105"
                :class="idx === lightboxIndex ? 'border-white shadow-lg scale-105' : 'border-transparent opacity-50 hover:opacity-80'"
              >
                <img
                  v-if="!failedImages[img.id || idx]"
                  :src="getResolvedSrc(img)"
                  class="w-full h-full object-cover"
                  draggable="false"
                  decoding="async"
                />
                <div v-else class="w-full h-full bg-surface-700 flex items-center justify-center">
                  <span class="material-symbols-rounded text-xs text-surface-400">broken_image</span>
                </div>
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, ref, reactive, nextTick, onUnmounted } from 'vue'
import { getToken } from '@/services/tokenStorage'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const store = useMoodBoardsStore()

const props = defineProps({
  item: { type: Object, required: true },
  readonly: { type: Boolean, default: false }
})

const emit = defineEmits(['remove-image', 'add-images', 'show-all', 'drop-files'])

const expanded = ref(false)
const failedImages = reactive({})
const blobUrls = reactive({})

// Lightbox state
const lightboxOpen = ref(false)
const lightboxIndex = ref(0)

const currentLightboxImage = computed(() => {
  if (!lightboxOpen.value || lightboxIndex.value < 0 || lightboxIndex.value >= images.value.length) return null
  return images.value[lightboxIndex.value]
})

function openLightbox(idx) {
  lightboxIndex.value = idx
  lightboxOpen.value = true
  // Focus the lightbox for keyboard navigation
  nextTick(() => {
    document.addEventListener('keydown', onLightboxKeydown)
  })
}

function closeLightbox() {
  lightboxOpen.value = false
  document.removeEventListener('keydown', onLightboxKeydown)
}

function nextImage() {
  if (images.value.length <= 1) return
  lightboxIndex.value = (lightboxIndex.value + 1) % images.value.length
}

function prevImage() {
  if (images.value.length <= 1) return
  lightboxIndex.value = (lightboxIndex.value - 1 + images.value.length) % images.value.length
}

function onLightboxKeydown(e) {
  if (!lightboxOpen.value) return
  if (e.key === 'Escape') { closeLightbox(); e.stopPropagation() }
  else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { nextImage(); e.preventDefault() }
  else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { prevImage(); e.preventDefault() }
}

// Backend returns "images"; legacy frontend code may use "image_set_items"
// Use whichever has actual content (non-empty array), preferring "images"
const images = computed(() => {
  const imgs = props.item.images
  const legacy = props.item.image_set_items
  // Prefer "images" if it has content
  if (Array.isArray(imgs) && imgs.length > 0) return imgs
  // Fallback to "image_set_items" if it has content
  if (Array.isArray(legacy) && legacy.length > 0) return legacy
  // Return whichever is an array (even empty)
  return imgs || legacy || []
})

/**
 * Get the resolved image src. If a blob URL was created via auth fallback, use that.
 * Prefers thumbnail_url for faster loading (especially in shared/public views).
 */
function getResolvedSrc(img, preferThumb = true) {
  const key = img.id || img.image_url || img.url
  if (blobUrls[key]) return blobUrls[key]
  // Use thumbnail for faster initial load (image set cards are usually small on screen)
  if (preferThumb && img.thumbnail_url) return img.thumbnail_url
  return img.image_url || img.url
}

/**
 * When an image fails to load, try loading it with auth headers.
 * If that also fails, mark it as failed to show the placeholder.
 */
async function onImgError(img, idx) {
  const src = img.image_url || img.url
  const key = img.id || idx
  if (!src || blobUrls[key]) {
    // Already tried auth fallback or no URL at all
    failedImages[key] = true
    return
  }
  // Try loading via authenticated fetch
  try {
    const token = getToken()
    const res = await fetch(src, {
      headers: token ? { 'Authorization': `Bearer ${token}` } : {}
    })
    if (res.ok) {
      const blob = await res.blob()
      blobUrls[key] = URL.createObjectURL(blob)
    } else {
      failedImages[key] = true
    }
  } catch {
    failedImages[key] = true
  }
}

// Whether individual card wobble is active
const cardMotionActive = computed(() => store.motionEnabled && store.motionCards && store.zoom >= 0.2)

// Per-card motion CSS vars (unique delay + duration per card in the stack)
function cardSeed(idx) {
  let h = 0
  const s = String(props.item.id || '') + '_card_' + idx
  for (let i = 0; i < s.length; i++) { h = ((h << 5) - h) + s.charCodeAt(i); h |= 0 }
  return Math.abs(h)
}

function getCardMotionStyle(idx) {
  if (!cardMotionActive.value) return {}
  const seed = cardSeed(idx)
  // Wide spread: delay 0-8s, duration 2.5-6s, varied direction
  return {
    '--m-delay': ((seed * 7) % 8000) + 'ms',
    '--m-dur': Math.round((2500 + ((seed * 13) % 3500)) * store.motionSpeed) + 'ms',
    '--m-amp': store.motionCardIntensity,
    '--m-dir': seed % 3 === 0 ? 'normal' : seed % 3 === 1 ? 'reverse' : 'alternate',
  }
}

// Show up to 5 cards in the stack preview
const stackPreviewImages = computed(() => images.value.slice(0, Math.min(5, images.value.length)))

// Container size for the stacked cards — scales with item size
const stackContainerStyle = computed(() => {
  const w = (props.item.width || 320) - 40 // padding
  const h = (props.item.height || 240) - 70 // header + hint
  return {
    width: Math.max(120, w) + 'px',
    height: Math.max(80, h) + 'px',
  }
})

/**
 * Compute style for each card in the fanned stack.
 * Cards are rotated and offset from center, like playing cards held in a hand.
 */
function getStackCardStyle(idx, total) {
  const containerW = (props.item.width || 320) - 40
  const containerH = (props.item.height || 240) - 70
  
  // Card size — scales proportionally with container (no hard cap)
  const cardW = Math.max(80, containerW * 0.55)
  const cardH = Math.max(60, containerH * 0.65)
  
  if (total === 1) {
    // Single card — centered, no rotation
    return {
      width: cardW + 'px',
      height: cardH + 'px',
      left: '50%',
      top: '50%',
      transform: 'translate(-50%, -50%)',
      zIndex: 1,
    }
  }
  
  // Fan arc — spread cards from -maxAngle to +maxAngle
  const maxAngle = Math.min(12, 6 * total) // degrees
  const angleStep = total > 1 ? (2 * maxAngle) / (total - 1) : 0
  const angle = -maxAngle + idx * angleStep
  
  // Horizontal spread — scales with container
  const spreadX = Math.min(containerW * 0.12, 10 * (total - 1))
  const offsetStep = total > 1 ? (2 * spreadX) / (total - 1) : 0
  const offsetX = -spreadX + idx * offsetStep
  
  // Slight vertical arc (cards in middle are higher) — scales with container
  const midIdx = (total - 1) / 2
  const normalizedPos = total > 1 ? (idx - midIdx) / midIdx : 0 // -1 to 1
  const arcY = normalizedPos * normalizedPos * Math.max(8, containerH * 0.04)
  
  return {
    width: cardW + 'px',
    height: cardH + 'px',
    left: '50%',
    top: '50%',
    transform: `translate(calc(-50% + ${offsetX}px), calc(-50% + ${arcY}px)) rotate(${angle}deg)`,
    zIndex: idx + 1,
    boxShadow: `0 ${2 + idx}px ${4 + idx * 2}px rgba(0,0,0,${0.08 + idx * 0.03})`,
  }
}

// Grid layout for expanded view
const expandedGridClass = computed(() => {
  const count = images.value.length
  if (count === 1) return 'grid-cols-1'
  if (count === 2) return 'grid-cols-2'
  if (count <= 4) return 'grid-cols-2'
  return 'grid-cols-3'
})

const expandedImageClass = computed(() => {
  const count = images.value.length
  if (count === 1) return 'aspect-video'
  return 'aspect-square'
})

function onDropImages(e) {
  const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'))
  if (files.length > 0) {
    emit('drop-files', files)
  }
}

// Cleanup keyboard listener on unmount
onUnmounted(() => {
  document.removeEventListener('keydown', onLightboxKeydown)
})
</script>

<style scoped>
.lightbox-fade-enter-active,
.lightbox-fade-leave-active {
  transition: opacity 0.2s ease;
}
.lightbox-fade-enter-from,
.lightbox-fade-leave-to {
  opacity: 0;
}

/* Individual card wobble — each card shuffles independently.
   Uses `translate` + `rotate` (individual transform props) so the
   card's base `transform: translate(-50%,-50%) rotate(Xdeg)` is untouched.
   `linear` easing + smooth loop = no jank. `--m-dir` varies direction
   per card. Larger amplitude than element wobble — cards should visibly shuffle.
   --m-amp: 0.5=subtle, 1.5=normal, 3=lively */
.stack-card-wobble {
  will-change: translate, rotate;
  animation: stackWobble var(--m-dur, 4s) linear var(--m-delay, 0s) infinite var(--m-dir, normal);
}
@keyframes stackWobble {
  0%, 100% { translate: 0 0; rotate: 0deg; }
  12.5%    { translate: calc(3px * var(--m-amp, 1)) calc(2px * var(--m-amp, 1));   rotate: calc(0.6deg * var(--m-amp, 1)); }
  25%      { translate: calc(4px * var(--m-amp, 1)) calc(-1px * var(--m-amp, 1));  rotate: calc(-0.3deg * var(--m-amp, 1)); }
  37.5%    { translate: calc(1.5px * var(--m-amp, 1)) calc(-3.5px * var(--m-amp, 1)); rotate: calc(-0.8deg * var(--m-amp, 1)); }
  50%      { translate: calc(-1.5px * var(--m-amp, 1)) calc(-1.5px * var(--m-amp, 1)); rotate: calc(-0.2deg * var(--m-amp, 1)); }
  62.5%    { translate: calc(-3.5px * var(--m-amp, 1)) calc(1px * var(--m-amp, 1)); rotate: calc(0.5deg * var(--m-amp, 1)); }
  75%      { translate: calc(-2.5px * var(--m-amp, 1)) calc(3px * var(--m-amp, 1)); rotate: calc(0.8deg * var(--m-amp, 1)); }
  87.5%    { translate: calc(-0.5px * var(--m-amp, 1)) calc(2.5px * var(--m-amp, 1)); rotate: calc(0.3deg * var(--m-amp, 1)); }
}
</style>
