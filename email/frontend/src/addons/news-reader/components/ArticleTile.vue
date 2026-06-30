<script setup>
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { articleDisplayTitle } from '@/addons/news-reader/utils/articleDisplay'

const props = defineProps({
  article: { type: Object, required: true },
  variant: { type: String, default: 'default' },
})
defineEmits(['open', 'filter-source'])

const { t } = useI18n()
const nr = useNewsReaderStore()
const { bookmarks } = storeToRefs(nr)

const bookmarked = computed(() => bookmarks.value.has(Number(props.article.id)))

const displayTitle = computed(
  () => articleDisplayTitle(props.article) || t('newsReader.untitled')
)

const coverImage = computed(
  () => props.article.image_url || props.article.video_thumbnail_url || ''
)

function toggleBookmark() {
  nr.toggleBookmark(props.article.id)
}

/**
 * Source = the publisher / channel / Instagram profile, NOT the category.
 * We prefer the feed title because that's the human-readable name the
 * user knows ("BBC News", "MKBHD", "@nasa"), falling back to the link
 * hostname only if no title is available. The category is shown
 * separately when relevant — clicking the source filters by feed_id, not
 * by category, so they need to be different values.
 */
const sourceLabel = computed(() => {
  const feed = (props.article.feed_title || '').trim()
  if (feed) return feed
  try {
    return new URL(props.article.link).hostname.replace(/^www\./, '')
  } catch (_) {
    return (props.article.feed_category || '').trim()
  }
})

const sourceFeedId = computed(() => {
  const v = props.article.feed_id
  return v != null && v !== '' ? Number(v) : null
})

const badgeColor = computed(() => {
  const k = (sourceLabel.value || 'misc').toLowerCase()
  let h = 0
  for (let i = 0; i < k.length; i++) h = (h * 31 + k.charCodeAt(i)) >>> 0
  const palette = [
    'bg-indigo-600',
    'bg-rose-600',
    'bg-emerald-600',
    'bg-amber-500',
    'bg-cyan-600',
    'bg-fuchsia-600',
    'bg-orange-600',
    'bg-blue-600',
    'bg-teal-600',
    'bg-violet-600',
  ]
  return palette[h % palette.length]
})

const dateLabel = computed(() => {
  const raw = props.article.published_at || props.article.created_at
  if (!raw) return ''
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return ''
  const diff = Date.now() - d.getTime()
  const min = Math.round(diff / 60000)
  if (min < 1) return 'now'
  if (min < 60) return `${min} min`
  const hr = Math.round(min / 60)
  if (hr < 24) return `${hr}h`
  const day = Math.round(hr / 24)
  if (day < 7) return `${day}d`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
})

const readMinutes = computed(() => {
  const txt = props.article.content_text || ''
  if (!txt) return null
  const words = txt.trim().split(/\s+/).length
  return Math.max(1, Math.round(words / 220))
})

const tileClass = computed(() => {
  switch (props.variant) {
    case 'xtall':  return 'min-h-[520px] sm:min-h-[600px]'
    case 'tall':   return 'min-h-[420px] sm:min-h-[480px]'
    case 'medium': return 'min-h-[340px] sm:min-h-[380px]'
    case 'short':  return 'min-h-[200px] sm:min-h-[220px]'
    default:       return 'min-h-[260px] sm:min-h-[290px]'
  }
})

const titleClass = computed(() => {
  switch (props.variant) {
    case 'xtall':  return 'text-2xl sm:text-3xl md:text-4xl'
    case 'tall':   return 'text-xl sm:text-2xl'
    case 'medium': return 'text-lg sm:text-xl'
    case 'short':  return 'text-sm sm:text-base'
    default:       return 'text-base sm:text-lg'
  }
})

const showExcerpt = computed(() => props.variant === 'xtall' || props.variant === 'tall')
</script>

<template>
  <article
    role="button"
    tabindex="0"
    class="news-tile group relative block w-full overflow-hidden rounded-xl bg-surface-200 dark:bg-surface-800 cursor-pointer"
    :class="tileClass"
    @click="$emit('open', article)"
    @keydown.enter="$emit('open', article)"
  >
    <img
      v-if="coverImage"
      :src="coverImage"
      alt=""
      class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.05]"
      loading="lazy"
      referrerpolicy="no-referrer"
    />

    <div
      class="absolute inset-0"
      :class="coverImage
        ? 'bg-gradient-to-t from-black/85 via-black/40 to-transparent'
        : 'bg-gradient-to-br from-surface-700 to-surface-900'"
    />

    <div
      v-if="article.is_video"
      class="pointer-events-none absolute inset-0 flex items-center justify-center"
      aria-hidden="true"
    >
      <span
        class="news-tile-play flex items-center justify-center w-14 h-14 sm:w-16 sm:h-16 rounded-full bg-black/55 backdrop-blur-sm ring-1 ring-white/20 shadow-xl transition-transform duration-300 group-hover:scale-110"
      >
        <span class="material-symbols-rounded text-white text-[34px] sm:text-[40px]" style="font-variation-settings: 'FILL' 1;">play_arrow</span>
      </span>
    </div>

    <div class="absolute top-3 left-3 right-3 flex items-start justify-between gap-2">
      <button
        type="button"
        class="news-source-badge inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-[0.18em] text-white shadow-md transition-transform"
        :class="article.is_video ? 'bg-red-600' : badgeColor"
        :title="t('newsReader.filterBySource', { source: sourceLabel })"
        :disabled="!sourceFeedId"
        @click.stop="sourceFeedId && $emit('filter-source', { feedId: sourceFeedId, label: sourceLabel })"
      >
        <span
          v-if="article.is_video"
          class="material-symbols-rounded text-[11px] leading-none"
          style="font-variation-settings: 'FILL' 1;"
        >smart_display</span>
        {{ sourceLabel || (article.is_video ? t('newsReader.videoBadge') : '') }}
      </button>
      <span
        v-if="!article.is_read"
        class="inline-block h-2 w-2 rounded-full bg-primary-400 ring-2 ring-black/30"
        aria-label="Unread"
      />
    </div>

    <div class="absolute inset-x-0 bottom-0 p-4 sm:p-5">
      <div
        v-if="readMinutes"
        class="mb-1.5 flex items-center gap-1 text-[10px] font-medium text-white/70"
      >
        <span class="material-symbols-rounded text-[12px] leading-none">schedule</span>
        <span>{{ readMinutes }} min read</span>
        <span v-if="dateLabel" class="opacity-70">· {{ dateLabel }}</span>
      </div>

      <h3
        class="font-bold leading-[1.15] tracking-tight text-white line-clamp-3"
        :class="titleClass"
      >
        {{ displayTitle }}
      </h3>

      <p
        v-if="showExcerpt && article.content_text"
        class="mt-2 text-sm text-white/80 line-clamp-2 leading-snug"
      >
        {{ article.content_text }}
      </p>
    </div>

    <button
      type="button"
      class="absolute bottom-3 right-3 z-10 hidden sm:inline-flex items-center justify-center w-8 h-8 rounded-full backdrop-blur-sm transition-colors"
      :class="bookmarked
        ? 'bg-primary-500/90 text-white hover:bg-primary-600'
        : 'bg-black/30 text-white/90 hover:text-white hover:bg-black/50'"
      :aria-label="bookmarked ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
      :title="bookmarked ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
      @click.stop="toggleBookmark"
    >
      <span
        class="material-symbols-rounded text-[16px]"
        :style="bookmarked ? `font-variation-settings: 'FILL' 1` : ''"
      >bookmark</span>
    </button>
  </article>
</template>

<style scoped>
/* Source badge: clickable filter trigger. The slight lift on hover signals
   interactivity without competing with the tile's main hover (image
   zoom). Disabled state (no feed_id) drops the lift. */
.news-source-badge:not(:disabled) {
  cursor: pointer;
}
.news-source-badge:not(:disabled):hover {
  transform: translateY(-1px) scale(1.05);
}
.news-source-badge:disabled {
  cursor: default;
}

/* 3D unfold cascade — each tile starts tilted back, hinging from its
   top-left corner as if it's been folded up against the corner of the
   viewport, then rotates flat into place. The transform-origin is the
   top-left corner so the rotation looks like a hinge. The cascade reads
   from top-left outward via the staggered animation-delay.
   Rendered inside a parent with `perspective` (.news-masonry) so the
   3D depth is real, not faked. */
.news-tile {
  animation: news-tile-unfold 0.7s cubic-bezier(0.22, 1, 0.36, 1) backwards;
  animation-delay: calc(min(var(--tile-idx, 0), 24) * 28ms);
  transform-origin: top left;
  transform-style: preserve-3d;
  will-change: transform, opacity;
  backface-visibility: hidden;
}
.news-tile:focus-visible {
  outline: 2px solid currentColor;
  outline-offset: 3px;
}
@keyframes news-tile-unfold {
  0% {
    opacity: 0;
    transform: translate3d(-12px, -28px, -120px)
               rotateX(72deg)
               rotateY(-12deg)
               rotateZ(-3deg);
  }
  60% {
    opacity: 1;
  }
  100% {
    opacity: 1;
    transform: translate3d(0, 0, 0)
               rotateX(0)
               rotateY(0)
               rotateZ(0);
  }
}
/* Larger feature tile gets a slightly slower / taller-falling unfold so
   it reads as the hero. */
.news-tile.news-masonry-feature {
  animation-duration: 0.85s;
}
@media (prefers-reduced-motion: reduce) {
  .news-tile {
    animation: news-tile-fade-in 0.25s ease-out backwards;
    animation-delay: 0ms;
    transform: none;
  }
  @keyframes news-tile-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
  }
}
</style>
