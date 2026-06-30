<template>
  <!-- Scroll-driven story overlay — transparent scroll catcher over the main canvas -->
  <teleport to="body">
    <div
      v-if="active"
      ref="scrollContainer"
      class="fixed inset-0 z-40 overflow-y-auto"
      style="scrollbar-width: none; -ms-overflow-style: none;"
      @scroll="onScroll"
      @keydown.escape="$emit('exit')"
      tabindex="0"
    >
      <!-- Invisible scroll spacer: (frames + 1) viewports tall -->
      <div :style="{ height: totalScrollHeight + 'px' }" class="pointer-events-none" />
    </div>

    <!-- HUD layer: progress, dots, title, exit button — sits above the scroll catcher -->
    <template v-if="active">
      <!-- Progress bar -->
      <div class="fixed bottom-0 left-0 right-0 z-50 h-1 bg-black/10">
        <div
          class="h-full bg-primary-500 transition-all duration-100 ease-out"
          :style="{ width: (progress * 100) + '%' }"
        />
      </div>

      <!-- Frame indicator (bottom-right) -->
      <div class="fixed bottom-4 right-4 z-50 flex items-center gap-2 px-3 py-1.5 rounded-full bg-black/60 text-white text-xs font-medium backdrop-blur-sm">
        <span class="material-symbols-rounded text-sm">auto_stories</span>
        <span v-if="currentFrameIndex >= 0">{{ currentFrameIndex + 1 }} / {{ totalFrames }}</span>
        <span v-else>Scroll to explore</span>
      </div>

      <!-- Frame dots (right edge) -->
      <div class="fixed right-4 top-1/2 -translate-y-1/2 z-50 flex flex-col gap-2">
        <button
          v-for="(frame, idx) in frames"
          :key="frame.id"
          class="w-2.5 h-2.5 rounded-full border-2 transition-all duration-300 pointer-events-auto"
          :class="idx === currentFrameIndex
            ? 'bg-primary-500 border-primary-500 scale-125'
            : idx < currentFrameIndex
              ? 'bg-white/60 border-white/60'
              : 'bg-transparent border-white/30'"
          :title="frame.title || `Artboard ${idx + 1}`"
          @click="scrollToFrame(idx)"
        />
      </div>

      <!-- Exit button -->
      <div class="fixed top-4 right-4 z-50">
        <button
          @click="$emit('exit')"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-black/60 text-white text-xs font-medium hover:bg-black/80 backdrop-blur-sm transition-colors pointer-events-auto"
        >
          <span class="material-symbols-rounded text-sm">close</span>
          Exit Story
        </button>
      </div>

      <!-- Title overlay for current frame -->
      <transition name="story-title">
        <div
          v-if="currentFrameTitle"
          :key="currentFrameIndex"
          class="fixed top-8 left-1/2 -translate-x-1/2 z-50 px-6 py-2 rounded-full bg-black/50 text-white text-sm font-semibold backdrop-blur-sm max-w-md text-center truncate pointer-events-none"
        >
          {{ currentFrameTitle }}
        </div>
      </transition>
    </template>
  </teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const props = defineProps({
  active: { type: Boolean, default: false },
  bgColor: { type: String, default: '#111111' }
})

const emit = defineEmits(['exit', 'pan-to-slide'])

const store = useMoodBoardsStore()
const scrollContainer = ref(null)

const frames = computed(() => store.presentationSlides)
const totalFrames = computed(() => frames.value.length)

// Each frame gets one viewport-height of scroll space
const viewportHeight = ref(window.innerHeight)
const totalScrollHeight = computed(() => Math.max(1, totalFrames.value + 1) * viewportHeight.value)

const progress = ref(0)
const currentFrameIndex = ref(-1)
const currentFrameTitle = computed(() => {
  if (currentFrameIndex.value < 0 || currentFrameIndex.value >= frames.value.length) return ''
  return frames.value[currentFrameIndex.value]?.title || ''
})

function onScroll() {
  if (!scrollContainer.value) return
  const scrollTop = scrollContainer.value.scrollTop
  const maxScroll = totalScrollHeight.value - viewportHeight.value
  progress.value = maxScroll > 0 ? Math.min(1, scrollTop / maxScroll) : 0

  const frameFloat = scrollTop / viewportHeight.value
  const frameIdx = Math.min(Math.floor(frameFloat), frames.value.length - 1)
  const frameFrac = frameFloat - Math.floor(frameFloat)

  if (frameIdx >= 0 && frameIdx < frames.value.length) {
    currentFrameIndex.value = frameIdx
    const currentFrame = frames.value[frameIdx]
    const nextFrame = frames.value[frameIdx + 1] || null

    emit('pan-to-slide', {
      frame: currentFrame,
      nextFrame,
      fraction: frameFrac,
      index: frameIdx
    })
  }

  store.scrollStoryProgress = progress.value
}

function scrollToFrame(idx) {
  if (!scrollContainer.value) return
  scrollContainer.value.scrollTo({
    top: idx * viewportHeight.value,
    behavior: 'smooth'
  })
}

function onResize() {
  viewportHeight.value = window.innerHeight
}

watch(() => props.active, (val) => {
  if (val) {
    nextTick(() => {
      scrollContainer.value?.focus()
      if (scrollContainer.value) scrollContainer.value.scrollTop = 0
      currentFrameIndex.value = frames.value.length > 0 ? 0 : -1
      progress.value = 0
      // Pan to first frame immediately
      if (frames.value.length > 0) {
        emit('pan-to-slide', {
          frame: frames.value[0],
          nextFrame: frames.value[1] || null,
          fraction: 0,
          index: 0
        })
      }
    })
  }
})

onMounted(() => window.addEventListener('resize', onResize))
onUnmounted(() => window.removeEventListener('resize', onResize))
</script>

<style scoped>
/* Hide scrollbar but keep scroll functionality */
div::-webkit-scrollbar {
  display: none;
}

.story-title-enter-active {
  transition: opacity 0.4s ease, transform 0.4s ease;
}
.story-title-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.story-title-enter-from {
  opacity: 0;
  transform: translate(-50%, -8px);
}
.story-title-leave-to {
  opacity: 0;
  transform: translate(-50%, 8px);
}
</style>
