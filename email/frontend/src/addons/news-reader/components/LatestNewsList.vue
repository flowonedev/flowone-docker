<script setup>
/**
 * Compact list of the latest news items, designed for the main content
 * column (NOT the same as the right-rail `ArticleListItem.vue` used in
 * the article view). Each row has a small thumbnail on the left, source
 * + title + summary in the middle, time + bookmark on the right.
 */
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { articleDisplayTitle } from '@/addons/news-reader/utils/articleDisplay'

defineProps({
  items: { type: Array, required: true },
})
const emit = defineEmits(['open', 'filter-source'])

const { t } = useI18n()
const nr = useNewsReaderStore()
const { bookmarks } = storeToRefs(nr)

function isBookmarked(id) {
  return bookmarks.value.has(Number(id))
}
function toggleBookmark(id) {
  nr.toggleBookmark(id)
}

function sourceLabel(article) {
  const feed = (article.feed_title || '').trim()
  if (feed) return feed
  try {
    return new URL(article.link).hostname.replace(/^www\./, '')
  } catch (_) {
    return (article.feed_category || '').trim()
  }
}
function sourceFeedId(article) {
  const v = article.feed_id
  return v != null && v !== '' ? Number(v) : null
}

function dateLabel(article) {
  const raw = article.published_at || article.created_at
  if (!raw) return ''
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return ''
  const diff = Date.now() - d.getTime()
  const min = Math.round(diff / 60000)
  if (min < 1) return t('newsReader.timeNow')
  if (min < 60) return `${min}m`
  const hr = Math.round(min / 60)
  if (hr < 24) return `${hr}h ago`
  const day = Math.round(hr / 24)
  if (day < 7) return `${day}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function thumbUrl(article) {
  return article.image_url || article.video_thumbnail_url || ''
}

function displayTitle(article) {
  return articleDisplayTitle(article) || t('newsReader.untitled')
}

function summary(article) {
  const txt = (article.content_text || '').trim()
  if (!txt) return ''
  if (txt.length <= 160) return txt
  return txt.slice(0, 157).trimEnd() + '…'
}
</script>

<template>
  <section v-if="items.length" class="news-latest">
    <header class="flex items-center justify-between mb-3">
      <h3 class="text-xl font-bold tracking-tight text-surface-900 dark:text-surface-50">
        {{ t('newsReader.latestNews') }}
      </h3>
    </header>

    <ul
      class="rounded-2xl bg-white dark:bg-[rgb(var(--color-surface))] border border-surface-200/70 dark:border-surface-700/60 divide-y divide-surface-200/70 dark:divide-surface-700/60 overflow-hidden"
    >
      <li
        v-for="article in items"
        :key="article.id"
        class="news-latest-row group flex items-start gap-3 sm:gap-4 px-3 sm:px-4 py-3 transition-colors hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))]"
      >
        <!-- Thumb -->
        <button
          type="button"
          class="shrink-0 w-16 h-16 sm:w-20 sm:h-20 rounded-lg overflow-hidden bg-surface-200 dark:bg-surface-700 cursor-pointer"
          @click="emit('open', article)"
        >
          <img
            v-if="thumbUrl(article)"
            :src="thumbUrl(article)"
            alt=""
            class="h-full w-full object-cover"
            loading="lazy"
            referrerpolicy="no-referrer"
          />
          <div
            v-else
            class="h-full w-full flex items-center justify-center text-surface-400"
          >
            <span class="material-symbols-rounded text-[24px]">newspaper</span>
          </div>
        </button>

        <!-- Body -->
        <button
          type="button"
          class="flex-1 min-w-0 text-left cursor-pointer"
          @click="emit('open', article)"
        >
          <div class="flex items-center gap-2 mb-0.5">
            <button
              type="button"
              class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary-600 dark:text-primary-400 hover:underline truncate disabled:no-underline disabled:cursor-default"
              :disabled="!sourceFeedId(article)"
              @click.stop="sourceFeedId(article) && emit('filter-source', { feedId: sourceFeedId(article), label: sourceLabel(article) })"
            >
              {{ sourceLabel(article) }}
            </button>
          </div>
          <h4
            class="text-sm sm:text-[15px] font-semibold leading-snug text-surface-900 dark:text-surface-50 line-clamp-2"
          >
            <span
              v-if="!article.is_read"
              class="inline-block h-1.5 w-1.5 rounded-full bg-primary-500 mr-1.5 align-middle"
              aria-hidden="true"
            />
            {{ displayTitle(article) }}
          </h4>
          <p
            v-if="summary(article)"
            class="hidden sm:block mt-1 text-[12.5px] text-surface-500 dark:text-surface-400 line-clamp-1"
          >
            {{ summary(article) }}
          </p>
        </button>

        <!-- Right-side time + bookmark -->
        <div class="shrink-0 flex flex-col items-end gap-1.5 pt-0.5">
          <span class="text-[11px] text-surface-500 dark:text-surface-400 whitespace-nowrap">
            {{ dateLabel(article) }}
          </span>
          <button
            type="button"
            class="p-1 rounded-full transition-colors"
            :class="isBookmarked(article.id)
              ? 'text-primary-500'
              : 'text-surface-400 opacity-0 group-hover:opacity-100 hover:text-surface-700 dark:hover:text-surface-200'"
            :aria-label="isBookmarked(article.id) ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
            :title="isBookmarked(article.id) ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
            @click.stop="toggleBookmark(article.id)"
          >
            <span
              class="material-symbols-rounded text-[16px]"
              :style="isBookmarked(article.id) ? `font-variation-settings: 'FILL' 1` : ''"
            >bookmark</span>
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>
