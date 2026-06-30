<script setup>
/**
 * Rotating hero card at the top of the News dashboard. Auto-advances
 * every ~7 seconds, pauses on hover or while a dot is being dragged.
 * Each slide shows: source/category badge, reading time, headline,
 * excerpt, and a "Read full story" CTA. Click anywhere on the slide
 * (or the CTA) opens the article via the same path the tiles use.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { articleDisplayTitle } from '@/addons/news-reader/utils/articleDisplay'

const props = defineProps({
  items: { type: Array, required: true },
  intervalMs: { type: Number, default: 7000 },
})
const emit = defineEmits(['open', 'filter-source'])

const { t } = useI18n()

const activeIndex = ref(0)
const paused = ref(false)
let timer = null

function clearTimer() {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}
function startTimer() {
  clearTimer()
  if (paused.value || props.items.length <= 1) return
  timer = setInterval(() => {
    activeIndex.value = (activeIndex.value + 1) % props.items.length
  }, props.intervalMs)
}

onMounted(startTimer)
onBeforeUnmount(clearTimer)

watch(() => props.items.length, () => {
  // Reset to start when the items list shrinks past current index.
  if (activeIndex.value >= props.items.length) activeIndex.value = 0
  startTimer()
})
watch(paused, startTimer)

function go(i) {
  activeIndex.value = i
  // Restart the timer so dots feel responsive — clicking a dot shouldn't
  // immediately auto-advance off it.
  startTimer()
}

function step(delta) {
  const n = props.items.length
  if (n <= 1) return
  // (idx + delta + n) % n handles the negative wrap for "prev".
  activeIndex.value = (activeIndex.value + delta + n) % n
  startTimer()
}

const active = computed(() => props.items[activeIndex.value] || null)
const activeTitle = computed(() => articleDisplayTitle(active.value) || t('newsReader.untitled'))
const activeImage = computed(
  () => active.value?.image_url || active.value?.video_thumbnail_url || ''
)
const activeFeedId = computed(() => {
  const v = active.value?.feed_id
  return v != null && v !== '' ? Number(v) : null
})

const sourceLabel = computed(() => {
  if (!active.value) return ''
  const feed = (active.value.feed_title || '').trim()
  if (feed) return feed
  try {
    return new URL(active.value.link).hostname.replace(/^www\./, '')
  } catch (_) {
    return (active.value.feed_category || '').trim()
  }
})

const readMinutes = computed(() => {
  const txt = active.value?.content_text || ''
  if (!txt) return null
  const words = txt.trim().split(/\s+/).length
  return Math.max(1, Math.round(words / 220))
})

const excerpt = computed(() => {
  const txt = (active.value?.content_text || '').trim()
  if (!txt) return ''
  if (txt.length <= 220) return txt
  return txt.slice(0, 217).trimEnd() + '…'
})
</script>

<template>
  <section
    v-if="active"
    class="news-hero relative overflow-hidden rounded-2xl bg-surface-200 dark:bg-surface-800 cursor-pointer"
    @mouseenter="paused = true"
    @mouseleave="paused = false"
    @click="emit('open', active)"
  >
    <!-- Crossfade slides: stacked images that fade between each other -->
    <div class="news-hero-stack">
      <img
        v-for="(item, idx) in items"
        :key="item.id"
        :src="item.image_url || item.video_thumbnail_url || ''"
        alt=""
        class="news-hero-img"
        :class="{ 'news-hero-img-active': idx === activeIndex }"
        loading="lazy"
        referrerpolicy="no-referrer"
      />
      <!-- Always-on gradient for legibility -->
      <div class="news-hero-overlay" />
    </div>

    <!-- Top-left badge -->
    <div class="absolute top-5 left-5 right-5 flex items-start justify-between gap-3 pointer-events-none">
      <button
        v-if="sourceLabel"
        type="button"
        class="news-hero-badge pointer-events-auto inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-[0.18em] text-white"
        :disabled="!activeFeedId"
        @click.stop="activeFeedId && emit('filter-source', { feedId: activeFeedId, label: sourceLabel })"
      >
        {{ sourceLabel }}
      </button>
    </div>

    <!-- Prev / next arrows. Sit on the vertical centre so they're easy
         to reach with a mouse, hidden on touch-only screens where the
         dots + auto-advance carry the navigation. -->
    <button
      v-if="items.length > 1"
      type="button"
      class="news-hero-arrow news-hero-arrow-prev"
      :aria-label="t('newsReader.previous', 'Previous slide')"
      @click.stop="step(-1)"
    >
      <span class="material-symbols-rounded">chevron_left</span>
    </button>
    <button
      v-if="items.length > 1"
      type="button"
      class="news-hero-arrow news-hero-arrow-next"
      :aria-label="t('newsReader.next', 'Next slide')"
      @click.stop="step(1)"
    >
      <span class="material-symbols-rounded">chevron_right</span>
    </button>

    <!-- Pagination dots -->
    <div
      v-if="items.length > 1"
      class="absolute right-5 bottom-5 flex items-center gap-1.5 z-10"
    >
      <button
        v-for="(item, idx) in items"
        :key="item.id"
        type="button"
        class="news-hero-dot"
        :class="idx === activeIndex ? 'news-hero-dot-active' : ''"
        :aria-label="`Slide ${idx + 1}`"
        @click.stop="go(idx)"
      />
    </div>

    <!-- Slide body -->
    <div class="absolute inset-x-0 bottom-0 p-5 sm:p-7 lg:p-8 max-w-3xl">
      <div
        v-if="readMinutes"
        class="mb-2 flex items-center gap-1.5 text-[11px] font-medium text-white/80"
      >
        <span class="material-symbols-rounded text-[14px] leading-none">schedule</span>
        <span>{{ t('newsReader.readingTime', { n: readMinutes }) }}</span>
      </div>

      <h2 class="font-bold leading-[1.1] tracking-tight text-white text-2xl sm:text-3xl lg:text-4xl line-clamp-3">
        {{ activeTitle }}
      </h2>

      <p
        v-if="excerpt"
        class="mt-3 text-sm sm:text-base text-white/85 line-clamp-2 leading-snug max-w-2xl"
      >
        {{ excerpt }}
      </p>

      <div class="mt-4">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-full bg-white text-surface-900 px-4 py-2 text-xs sm:text-sm font-semibold tracking-wide hover:bg-white/90 transition-colors"
          @click.stop="emit('open', active)"
        >
          {{ t('newsReader.readFullStory') }}
          <span class="material-symbols-rounded text-[14px]">arrow_forward</span>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.news-hero {
  min-height: 360px;
  height: 48vh;
  max-height: 520px;
}
@media (min-width: 1024px) {
  .news-hero {
    min-height: 440px;
  }
}

.news-hero-stack {
  position: absolute;
  inset: 0;
}
.news-hero-img {
  position: absolute;
  inset: 0;
  height: 100%;
  width: 100%;
  object-fit: cover;
  opacity: 0;
  transition: opacity 0.7s ease;
}
.news-hero-img-active {
  opacity: 1;
}
.news-hero-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    to top,
    rgba(0, 0, 0, 0.85) 0%,
    rgba(0, 0, 0, 0.45) 45%,
    rgba(0, 0, 0, 0) 80%
  );
}

.news-hero-badge {
  background: rgb(var(--color-primary-500, 168 85 247));
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  transition: transform 0.15s ease, opacity 0.15s ease;
}
.news-hero-badge:not(:disabled):hover {
  transform: translateY(-1px);
}
.news-hero-badge:disabled {
  cursor: default;
}

.news-hero-dot {
  width: 24px;
  height: 4px;
  border-radius: 9999px;
  background: rgba(255, 255, 255, 0.4);
  transition: background 0.2s ease, width 0.2s ease;
}
.news-hero-dot:hover {
  background: rgba(255, 255, 255, 0.7);
}
.news-hero-dot-active {
  width: 32px;
  background: white;
}

.news-hero-arrow {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 10;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 9999px;
  background: rgba(0, 0, 0, 0.55);
  color: white;
  backdrop-filter: blur(6px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
  opacity: 0.85;
  transition: opacity 0.2s ease, background 0.2s ease, transform 0.2s ease;
}
.news-hero-arrow .material-symbols-rounded {
  font-size: 26px;
  line-height: 1;
}
.news-hero-arrow-prev { left: 16px; }
.news-hero-arrow-next { right: 16px; }
.news-hero:hover .news-hero-arrow,
.news-hero-arrow:focus-visible {
  opacity: 1;
}
.news-hero-arrow:hover {
  background: rgba(0, 0, 0, 0.78);
  transform: translateY(-50%) scale(1.05);
}
@media (max-width: 767px) {
  /* On phones the arrows would collide with the title; leave navigation
     to the dots and auto-advance there. */
  .news-hero-arrow { display: none; }
}

@media (prefers-reduced-motion: reduce) {
  .news-hero-img {
    transition: none;
  }
  .news-hero-arrow {
    transition: none;
  }
}
</style>
