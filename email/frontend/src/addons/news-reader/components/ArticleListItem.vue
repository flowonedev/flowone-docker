<script setup>
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { articleDisplayTitle } from '@/addons/news-reader/utils/articleDisplay'

const props = defineProps({
  article: { type: Object, required: true },
})
defineEmits(['open', 'filter-source'])

const { t } = useI18n()
const nr = useNewsReaderStore()
const { bookmarks } = storeToRefs(nr)

const bookmarked = computed(() => bookmarks.value.has(Number(props.article.id)))

const displayTitle = computed(
  () => articleDisplayTitle(props.article) || t('newsReader.untitled')
)

function toggleBookmark() {
  nr.toggleBookmark(props.article.id)
}

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

const labelColor = computed(() => {
  const k = (sourceLabel.value || 'misc').toLowerCase()
  let h = 0
  for (let i = 0; i < k.length; i++) h = (h * 31 + k.charCodeAt(i)) >>> 0
  const palette = [
    'text-indigo-600 dark:text-indigo-400',
    'text-rose-600 dark:text-rose-400',
    'text-emerald-600 dark:text-emerald-400',
    'text-amber-600 dark:text-amber-400',
    'text-cyan-600 dark:text-cyan-400',
    'text-fuchsia-600 dark:text-fuchsia-400',
    'text-orange-600 dark:text-orange-400',
    'text-blue-600 dark:text-blue-400',
    'text-teal-600 dark:text-teal-400',
    'text-violet-600 dark:text-violet-400',
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
  if (min < 1) return 'just now'
  if (min < 60) return `${min} min ago`
  const hr = Math.round(min / 60)
  if (hr < 24) return `${hr}h ago`
  const day = Math.round(hr / 24)
  if (day < 7) return `${day}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
})
</script>

<template>
  <div
    class="news-list-item group relative flex w-full items-start gap-3 px-4 py-3 transition-colors hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))]"
  >
    <button
      type="button"
      class="flex-1 min-w-0 text-left cursor-pointer"
      @click="$emit('open', article)"
    >
      <div class="flex items-center gap-2 mb-1">
        <span
          v-if="article.is_video"
          class="material-symbols-rounded text-[14px] leading-none text-red-600 dark:text-red-400 shrink-0"
          style="font-variation-settings: 'FILL' 1;"
          aria-hidden="true"
        >smart_display</span>
        <button
          type="button"
          class="news-source-link text-[10px] font-bold uppercase tracking-[0.18em] truncate text-left transition-colors hover:underline disabled:no-underline disabled:cursor-default"
          :class="article.is_video
            ? 'text-red-600 dark:text-red-400'
            : labelColor"
          :disabled="!sourceFeedId"
          :title="sourceFeedId ? t('newsReader.filterBySource', { source: sourceLabel }) : ''"
          @click.stop="sourceFeedId && $emit('filter-source', { feedId: sourceFeedId, label: sourceLabel })"
        >{{ sourceLabel || (article.is_video ? t('newsReader.videoBadge') : '') }}</button>
        <span class="text-[10px] text-surface-400 dark:text-surface-500 shrink-0">·</span>
        <span class="text-[10px] text-surface-500 dark:text-surface-400 shrink-0">{{ dateLabel }}</span>
      </div>
      <h4 class="text-sm font-semibold leading-snug tracking-tight text-surface-900 dark:text-surface-50 line-clamp-2 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
        <span
          v-if="!article.is_read"
          class="inline-block h-1.5 w-1.5 rounded-full bg-primary-500 mr-1.5 align-middle"
          aria-hidden="true"
        />
        {{ displayTitle }}
      </h4>
    </button>
    <button
      type="button"
      class="shrink-0 -mt-0.5 -mr-1 p-1 rounded-full transition-all"
      :class="bookmarked
        ? 'text-primary-500 opacity-100'
        : 'text-surface-400 opacity-0 group-hover:opacity-100 hover:text-surface-700 dark:hover:text-surface-200'"
      :aria-label="bookmarked ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
      :title="bookmarked ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
      @click.stop="toggleBookmark"
    >
      <span
        class="material-symbols-rounded text-[16px]"
        :style="bookmarked ? `font-variation-settings: 'FILL' 1` : ''"
      >bookmark</span>
    </button>
  </div>
</template>

<style scoped>
.news-list-item {
  animation: news-list-in 0.4s cubic-bezier(0.22, 1, 0.36, 1) backwards;
  animation-delay: calc(min(var(--row-idx, 0), 18) * 28ms);
  will-change: transform, opacity;
}
@keyframes news-list-in {
  from {
    opacity: 0;
    transform: translate3d(12px, 0, 0);
  }
  to {
    opacity: 1;
    transform: translate3d(0, 0, 0);
  }
}
@media (prefers-reduced-motion: reduce) {
  .news-list-item {
    animation: none;
  }
}
</style>
