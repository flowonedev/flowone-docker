<template>
  <div
    ref="presenterEl"
    class="fixed inset-0 z-[9999] overflow-hidden pointer-events-none"
    :class="{ 'cursor-none': hudHidden }"
  >
    <!-- NOTE: Background effects (grain, vignette, gradient) are rendered by MoodCanvas itself
         at z-index: 1, above the bg image (z-0) but below the canvas layer at z-[2]. They stay
         correctly behind items in both edit and presentation modes. Do NOT duplicate them here —
         the Presenter sits at z-[9999] above the canvas, so any effects here would layer on top of items. -->

    <!-- Background audio player (invisible, plays during presentation) -->
    <MoodBgAudio
      v-if="bgAudioConfig"
      ref="bgAudioRef"
      :config="bgAudioConfig"
      :playing="audioPlaying"
    />

    <!-- Invisible layer for mouse-activity detection (HUD auto-hide).
         pointer-events: none so clicks pass through to interactive items (YouTube, audio, etc.).
         We use a document-level mousemove listener instead for HUD auto-hide. -->
    <div
      class="absolute inset-0 pointer-events-none"
      style="z-index: 5;"
    />

    <!-- Fade overlay for 'fade' transitions -->
    <transition name="fade-transition">
      <div
        v-if="fadeOverlay"
        class="absolute inset-0 z-10 pointer-events-none"
        :style="{ backgroundColor: board?.background_color || '#1e1e2e' }"
      />
    </transition>

    <!-- HUD (auto-hides after inactivity) -->
    <transition name="hud-fade">
      <div v-if="!hudHidden" class="absolute inset-0 pointer-events-none z-20">
        <!-- Top bar: slide title + close -->
        <div class="absolute top-0 left-0 right-0 flex items-center justify-between px-6 py-4 sm:py-4 py-3 pointer-events-auto bg-gradient-to-b from-black/40 to-transparent">
          <span class="text-white/80 text-sm font-medium truncate mr-4">
            {{ currentSlide?.title || 'Slide ' + (store.currentSlideIndex + 1) }}
          </span>
          <button
            v-if="!isLockedPresentation"
            @click.stop="exitPresentation"
            class="p-3 sm:p-2 rounded-full hover:bg-white/10 text-white/80 hover:text-white transition-colors flex-shrink-0"
            title="Exit (Esc)"
          >
            <span class="material-symbols-rounded text-2xl sm:text-xl">close</span>
          </button>
        </div>

        <!-- Bottom bar: navigation + progress -->
        <div class="absolute bottom-0 left-0 right-0 pointer-events-auto bg-gradient-to-t from-black/40 to-transparent">
          <!-- Progress bar -->
          <div class="px-6">
            <div class="w-full h-1 bg-white/20 rounded-full overflow-hidden mb-3">
              <div
                class="h-full bg-white/70 rounded-full transition-all duration-300"
                :style="{ width: progressPercent + '%' }"
              />
            </div>
          </div>
          <div class="flex items-center gap-3 px-4 pb-4">
            <!-- Nav: prev/next (compact, left side) -->
            <div class="flex items-center gap-1 sm:gap-1 gap-2 flex-shrink-0">
              <button
                @click.stop="prevSlide"
                :disabled="store.currentSlideIndex === 0"
                class="p-2.5 sm:p-1.5 rounded-full hover:bg-white/10 text-white/80 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
              >
                <span class="material-symbols-rounded text-xl sm:text-lg">arrow_back</span>
              </button>
              <span class="text-white/70 text-xs font-mono tabular-nums min-w-[3.5rem] text-center">
                {{ store.currentSlideIndex + 1 }} / {{ store.presentationSlides.length }}
              </span>
              <button
                @click.stop="nextSlide"
                :disabled="store.currentSlideIndex >= store.presentationSlides.length - 1"
                class="p-2.5 sm:p-1.5 rounded-full hover:bg-white/10 text-white/80 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
              >
                <span class="material-symbols-rounded text-xl sm:text-lg">arrow_forward</span>
              </button>
            </div>

            <!-- Slide dots (takes remaining space, scrollable) -->
            <div class="flex-1 min-w-0 overflow-x-auto flex items-center gap-1 scrollbar-hide py-1">
              <button
                v-for="(frame, idx) in store.presentationSlides"
                :key="frame.id"
                @click.stop="goToSlideAnimated(idx)"
                class="rounded-full transition-all duration-200 flex-shrink-0"
                :class="idx === store.currentSlideIndex
                  ? 'w-6 h-2 bg-white'
                  : 'w-2 h-2 bg-white/40 hover:bg-white/60'"
              />
            </div>

            <!-- Tools (right side) -->
            <div class="flex items-center gap-1 sm:gap-1 gap-2 flex-shrink-0">
              <!-- Toggle dot grid background -->
              <button
                @click.stop="toggleBackground"
                class="p-2.5 sm:p-1.5 rounded-full hover:bg-white/10 transition-colors"
                :class="store.presShowBackground ? 'text-white bg-white/10' : 'text-white/40'"
                title="Toggle background (B)"
              >
                <span class="material-symbols-rounded text-xl sm:text-lg">grid_on</span>
              </button>
              <!-- Toggle connection lines -->
              <button
                @click.stop="toggleLines"
                class="p-2.5 sm:p-1.5 rounded-full hover:bg-white/10 transition-colors"
                :class="store.presShowLines ? 'text-white bg-white/10' : 'text-white/40'"
                title="Toggle lines (L)"
              >
                <span class="material-symbols-rounded text-xl sm:text-lg">trending_flat</span>
              </button>
              <!-- Toggle presenter notes -->
              <button
                @click.stop="showNotes = !showNotes"
                class="p-2.5 sm:p-1.5 rounded-full hover:bg-white/10 transition-colors"
                :class="showNotes ? 'text-white bg-white/10' : 'text-white/40'"
                title="Toggle notes (N)"
              >
                <span class="material-symbols-rounded text-xl sm:text-lg">sticky_note_2</span>
              </button>
              <!-- Audio mute/unmute toggle -->
              <button
                v-if="bgAudioConfig"
                @click.stop="toggleAudioMute"
                class="p-2.5 sm:p-1.5 rounded-full hover:bg-white/10 transition-colors"
                :class="audioPlaying ? 'text-white bg-white/10' : 'text-white/40'"
                title="Toggle audio (M)"
              >
                <span class="material-symbols-rounded text-xl sm:text-lg">{{ audioPlaying ? 'volume_up' : 'volume_off' }}</span>
              </button>
              <!-- Bar wave animation when audio is playing -->
              <div v-if="bgAudioConfig && audioPlaying" class="flex items-end gap-[2px] h-4 ml-1" title="Music playing">
                <span class="bar-wave" style="animation-delay: 0s"></span>
                <span class="bar-wave" style="animation-delay: 0.15s"></span>
                <span class="bar-wave" style="animation-delay: 0.3s"></span>
                <span class="bar-wave" style="animation-delay: 0.1s"></span>
                <span class="bar-wave" style="animation-delay: 0.25s"></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Presenter notes panel -->
        <transition name="notes-slide">
          <div
            v-if="showNotes && currentSlide?.presenter_notes"
            class="absolute bottom-20 left-6 right-6 max-h-40 overflow-y-auto pointer-events-auto bg-black/60 backdrop-blur-sm rounded-xl p-4 text-white/90 text-sm leading-relaxed"
          >
            {{ currentSlide.presenter_notes }}
          </div>
        </transition>
      </div>
    </transition>

    <!-- Mobile swipe hint (shows briefly on touch devices) -->
    <transition name="hud-fade">
      <div
        v-if="showSwipeHint"
        class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
        @click="showSwipeHint = false"
      >
        <div class="bg-black/60 backdrop-blur-sm rounded-2xl px-8 py-6 flex flex-col items-center gap-3 max-w-xs text-center pointer-events-auto">
          <span class="material-symbols-rounded text-white/80 text-4xl swipe-anim">swipe</span>
          <span class="text-white/90 text-sm font-medium">Swipe left or right to navigate</span>
          <span class="text-white/50 text-xs">Tap right side to advance, left side to go back</span>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import MoodBgAudio from './MoodBgAudio.vue'

const props = defineProps({
  board: { type: Object, required: true },
  canvasRef: { type: Object, default: null }
})

const emit = defineEmits(['exit'])

const store = useMoodBoardsStore()
const presenterEl = ref(null)
const showNotes = ref(false)

const isLockedPresentation = computed(() => store.isPublicView && store.publicShareMode === 'view')
const hudHidden = ref(false)
const fadeOverlay = ref(false)
const bgAudioRef = ref(null)
const audioPlaying = ref(true) // Start playing by default
const showSwipeHint = ref(false)
let swipeHintTimer = null

let hudTimer = null
let isAnimating = false

// Scroll-to-advance state
let scrollAccum = 0
let scrollCooldown = false
let scrollResetTimer = null

// Touch/swipe state
let touchStartX = 0
let touchStartY = 0
let touchStartTime = 0

const currentSlide = computed(() => store.presentationSlides[store.currentSlideIndex] || null)

// ─── Background Audio ─────────────────────────────────────────
const bgAudioConfig = computed(() => {
  const raw = props.board?.bg_audio
  if (!raw) return null
  try {
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
    return parsed?.url ? parsed : null
  } catch { return null }
})

function toggleAudioMute() {
  audioPlaying.value = !audioPlaying.value
}

// Background effects (grain, gradient, vignette) are rendered by MoodCanvas — not here.
// The Presenter sits at z-[9999] above the canvas, so any effects here would layer on top of items.

const progressPercent = computed(() => {
  if (store.presentationSlides.length <= 1) return 100
  return ((store.currentSlideIndex) / (store.presentationSlides.length - 1)) * 100
})

// Fullscreen viewport dimensions — the actual screen size the user sees
function fsViewport() {
  return { width: window.innerWidth, height: window.innerHeight }
}

// Navigate to a frame with animation
async function navigateToSlide(index, fromIndex = null) {
  if (isAnimating || !props.canvasRef) return
  const slide = store.presentationSlides[index]
  if (!slide) return

  isAnimating = true
  const transition = slide.transition_type || 'fly'
  // Per-slide duration (seconds) — fallback to sensible defaults
  const defaultDur = transition === 'instant' ? 0 : transition === 'fade' ? 0.4 : 0.6
  const durSec = slide.transition_duration != null ? slide.transition_duration : defaultDur
  const duration = Math.round(durSec * 1000) // ms for animateToFrame

  if (transition === 'fade') {
    const fadeHalf = Math.round(duration / 2) || 200
    fadeOverlay.value = true
    await sleep(fadeHalf)
    store.currentSlideIndex = index
    await props.canvasRef.animateToFrame(slide, 0, 'instant', 0, fsViewport())
    await sleep(50)
    fadeOverlay.value = false
    await sleep(fadeHalf)
  } else {
    store.currentSlideIndex = index
    await props.canvasRef.animateToFrame(slide, duration, transition, 0, fsViewport())
  }

  isAnimating = false
}

function nextSlide() {
  if (store.currentSlideIndex < store.presentationSlides.length - 1) {
    navigateToSlide(store.currentSlideIndex + 1, store.currentSlideIndex)
  }
}

function prevSlide() {
  if (store.currentSlideIndex > 0) {
    navigateToSlide(store.currentSlideIndex - 1, store.currentSlideIndex)
  }
}

function onWheel(e) {
  if (!store.presentationMode) return
  e.preventDefault()

  // Don't queue scrolls while a transition is playing
  if (isAnimating || scrollCooldown) return

  // Accumulate scroll delta; reset if direction changes
  const delta = e.deltaY
  if ((scrollAccum > 0 && delta < 0) || (scrollAccum < 0 && delta > 0)) {
    scrollAccum = 0
  }
  scrollAccum += delta

  // Clear the idle reset timer on each wheel tick
  clearTimeout(scrollResetTimer)
  scrollResetTimer = setTimeout(() => { scrollAccum = 0 }, 200)

  // Threshold before triggering a slide change (absorbs small trackpad micro-scrolls)
  const THRESHOLD = 60

  if (Math.abs(scrollAccum) >= THRESHOLD) {
    const direction = scrollAccum > 0 ? 1 : -1
    scrollAccum = 0

    if (direction > 0) {
      nextSlide()
    } else {
      prevSlide()
    }

    // Cooldown prevents rapid-fire advances from momentum scrolling
    scrollCooldown = true
    setTimeout(() => { scrollCooldown = false }, 1200)
  }
}

// ─── Touch / Swipe Navigation ─────────────────────────────────
function onTouchStart(e) {
  if (!store.presentationMode) return
  // Show HUD on touch (equivalent of mousemove on desktop)
  onMouseMove()
  // Only track single-finger gestures
  if (e.touches.length !== 1) return
  touchStartX = e.touches[0].clientX
  touchStartY = e.touches[0].clientY
  touchStartTime = Date.now()
}

function onTouchMove(e) {
  if (!store.presentationMode) return
  // Prevent browser scroll/bounce during presentation swipe
  if (e.touches.length === 1) e.preventDefault()
}

function onTouchEnd(e) {
  if (!store.presentationMode || isAnimating) return
  const touch = e.changedTouches[0]
  if (!touch) return

  const dx = touch.clientX - touchStartX
  const dy = touch.clientY - touchStartY
  const dt = Date.now() - touchStartTime
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)

  // Swipe detection: horizontal, fast enough, long enough, and more horizontal than vertical
  if (absDx > 50 && absDx > absDy * 1.5 && dt < 600) {
    if (dx < 0) nextSlide()   // swipe left = next
    else prevSlide()           // swipe right = prev
    return
  }

  // Tap-to-advance: short duration, minimal movement
  if (dt < 300 && absDx < 15 && absDy < 15) {
    // Don't trigger if tapping on HUD buttons
    const target = e.target
    if (target.closest('button') || target.closest('a') || target.closest('[role="button"]')) return

    // Left 25% = previous, rest = next
    const tapX = touch.clientX / window.innerWidth
    if (tapX < 0.25) prevSlide()
    else nextSlide()
  }
}

function goToSlideAnimated(index) {
  if (index !== store.currentSlideIndex) {
    navigateToSlide(index, store.currentSlideIndex)
  }
}

function toggleBackground() {
  store.presShowBackground = !store.presShowBackground
}

function toggleLines() {
  store.presShowLines = !store.presShowLines
}

function exitPresentation() {
  if (!store.presentationMode) return
  if (isLockedPresentation.value) return

  if (document.fullscreenElement) {
    document.exitFullscreen().catch(() => {})
  }
  store.stopPresentation()
  emit('exit')
}

function onMouseMove() {
  hudHidden.value = false
  clearTimeout(hudTimer)
  hudTimer = setTimeout(() => { hudHidden.value = true }, 3000)
}

function onKeyDown(e) {
  if (!store.presentationMode) return

  switch (e.key) {
    case 'ArrowRight':
    case 'PageDown':
      e.preventDefault()
      nextSlide()
      break
    case 'ArrowLeft':
    case 'PageUp':
      e.preventDefault()
      prevSlide()
      break
    case 'Escape':
      e.preventDefault()
      if (!isLockedPresentation.value) exitPresentation()
      break
    case 'n':
    case 'N':
      showNotes.value = !showNotes.value
      break
    case 'b':
    case 'B':
      toggleBackground()
      break
    case 'l':
    case 'L':
      toggleLines()
      break
    case 'm':
    case 'M':
      if (bgAudioConfig.value) toggleAudioMute()
      break
    case 'Home':
      e.preventDefault()
      goToSlideAnimated(0)
      break
    case 'End':
      e.preventDefault()
      goToSlideAnimated(store.presentationSlides.length - 1)
      break
  }
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

// Wait for N animation frames (lets browser settle after fullscreen transition)
function waitFrames(n = 2) {
  return new Promise(resolve => {
    let count = 0
    function tick() {
      if (++count >= n) resolve()
      else requestAnimationFrame(tick)
    }
    requestAnimationFrame(tick)
  })
}

// Enter fullscreen and navigate to first slide on mount
onMounted(async () => {
  document.addEventListener('keydown', onKeyDown)
  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('wheel', onWheel, { passive: false })
  document.addEventListener('touchstart', onTouchStart, { passive: true })
  document.addEventListener('touchmove', onTouchMove, { passive: false })
  document.addEventListener('touchend', onTouchEnd, { passive: true })

  // Listen for fullscreen exit
  document.addEventListener('fullscreenchange', onFullscreenChange)

  // Fullscreen is now requested synchronously in the click handler (MoodBoardView)
  // to preserve the user-gesture chain. Wait for the viewport to settle if we're
  // already in fullscreen, or proceed immediately if fullscreen was blocked.
  if (document.fullscreenElement) {
    await waitFrames(3)
  }

  // Wait for the canvas container to settle into its fixed-inset-0 layout
  await nextTick()
  await waitFrames(2)

  // Animate MoodCanvas to the current slide (0 padding = fill screen edge-to-edge)
  // Retry a few times in case the canvas ref or container dimensions aren't ready yet.
  for (let attempt = 0; attempt < 3; attempt++) {
    if (props.canvasRef && currentSlide.value) {
      await props.canvasRef.animateToFrame(currentSlide.value, 0, 'instant', 0, fsViewport())
      break
    }
    // Canvas ref not ready yet — wait and retry
    await nextTick()
    await waitFrames(2)
  }

  // Start HUD auto-hide timer
  onMouseMove()

  // Show swipe hint on touch devices
  const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0
  if (isTouchDevice) {
    showSwipeHint.value = true
    swipeHintTimer = setTimeout(() => { showSwipeHint.value = false }, 3000)
  }
})

function onFullscreenChange() {
  if (!document.fullscreenElement && store.presentationMode) {
    if (isLockedPresentation.value) {
      document.documentElement.requestFullscreen?.().catch(() => {})
      return
    }
    store.stopPresentation()
    emit('exit')
  }
}

onUnmounted(() => {
  document.removeEventListener('keydown', onKeyDown)
  document.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('wheel', onWheel)
  document.removeEventListener('touchstart', onTouchStart)
  document.removeEventListener('touchmove', onTouchMove)
  document.removeEventListener('touchend', onTouchEnd)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  clearTimeout(hudTimer)
  clearTimeout(scrollResetTimer)
  clearTimeout(swipeHintTimer)
})
</script>

<style scoped>
.fade-transition-enter-active,
.fade-transition-leave-active {
  transition: opacity 0.25s ease;
}
.fade-transition-enter-from,
.fade-transition-leave-to {
  opacity: 0;
}

.hud-fade-enter-active,
.hud-fade-leave-active {
  transition: opacity 0.3s ease;
}
.hud-fade-enter-from,
.hud-fade-leave-to {
  opacity: 0;
}

.notes-slide-enter-active,
.notes-slide-leave-active {
  transition: all 0.2s ease;
}
.notes-slide-enter-from,
.notes-slide-leave-to {
  opacity: 0;
  transform: translateY(10px);
}

/* Hide scrollbar for slide dots overflow */
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

/* Bar wave animation for audio playing indicator */
.bar-wave {
  display: inline-block;
  width: 2px;
  min-height: 3px;
  border-radius: 1px;
  background: currentColor;
  color: rgba(255, 255, 255, 0.7);
  animation: barWave 0.8s ease-in-out infinite alternate;
}
@keyframes barWave {
  0%   { height: 3px; }
  50%  { height: 10px; }
  100% { height: 16px; }
}

/* Swipe hint icon animation */
.swipe-anim {
  animation: swipeLeftRight 1.5s ease-in-out infinite;
}
@keyframes swipeLeftRight {
  0%, 100% { transform: translateX(0); }
  30%  { transform: translateX(-12px); }
  70%  { transform: translateX(12px); }
}
</style>
