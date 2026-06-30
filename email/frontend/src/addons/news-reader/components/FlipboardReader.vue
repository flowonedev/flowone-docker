<script setup>
/**
 * Top-level overlay for the News reader. Owns the teleport, fade
 * transition, the global header, and the article slide-in pane. The
 * actual dashboard layout (left rail + scrolling sections) lives in
 * `NewsReaderShell.vue`.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import * as newsApi from '@/addons/news-reader/services/newsApi'
import NewsReaderShell from './NewsReaderShell.vue'
import ArticlePage from './ArticlePage.vue'
import flowoneLogo from '@/assets/flowone-logo.png'

const { t } = useI18n()
const router = useRouter()
const nr = useNewsReaderStore()
const {
  readerOpen,
  readerArticle,
  itemOrder,
  itemsMap,
} = storeToRefs(nr)

const allItems = computed(() =>
  itemOrder.value.map((id) => itemsMap.value.get(id)).filter(Boolean)
)

const storiesCount = computed(() => allItems.value.length)
const storiesLabel = computed(() =>
  storiesCount.value === 1 ? t('newsReader.storyOne') : t('newsReader.storyMany', { n: storiesCount.value })
)

watch(readerOpen, (open) => {
  if (typeof document === 'undefined') return
  document.body.style.overflow = open ? 'hidden' : ''
})

function close() {
  nr.closeReader()
}

async function markAll() {
  try {
    await newsApi.markAllRead({})
    await nr.loadFeeds()
    await nr.loadItems({ append: false })
  } catch (_) {}
}

/**
 * Header search input. Pushes the trimmed value to the store with a
 * short debounce so each keystroke doesn't fire a request — the store
 * then re-fetches `/news/items?q=...` against the user's full
 * subscription set, not just the rows already paginated into memory.
 *
 * Local `searchInput` is kept for the v-model so the user sees their
 * typing instantly; the network query is whatever lands in the store
 * after the debounce window settles.
 */
const searchInput = ref(nr.searchQuery || '')
let searchDebounce = null
function scheduleSearch(value) {
  searchInput.value = value
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    searchDebounce = null
    nr.setSearch(searchInput.value)
  }, 250)
}
function clearSearch() {
  if (searchDebounce) {
    clearTimeout(searchDebounce)
    searchDebounce = null
  }
  searchInput.value = ''
  nr.setSearch('')
}
onBeforeUnmount(() => {
  if (searchDebounce) clearTimeout(searchDebounce)
})
// When the reader closes, drop any active search so re-opening starts
// fresh on the chronological feed.
watch(readerOpen, (open) => {
  if (!open) {
    if (searchDebounce) {
      clearTimeout(searchDebounce)
      searchDebounce = null
    }
    searchInput.value = ''
    if (nr.searchQuery) nr.setSearch('')
  }
})

function openSettings() {
  close()
  router.push({ name: 'settings', query: { tab: 'news_reader' } }).catch(() => {})
}
</script>

<template>
  <Teleport to="body">
    <Transition name="news-reader-fade">
      <div
        v-if="readerOpen"
        class="news-reader-overlay fixed inset-0 z-[99999] flex flex-col bg-surface-50 dark:bg-[rgb(var(--color-bg))] text-surface-900 dark:text-surface-100 overflow-hidden"
        style="overscroll-behavior: contain; touch-action: pan-y;"
      >
        <header
          class="shrink-0 z-30 bg-white/95 dark:bg-[rgb(var(--color-surface))]/95 backdrop-blur-md border-b border-surface-200/70 dark:border-surface-700/60"
        >
          <!-- Header content spans the full width: logo pinned to the
               far left, search + actions pinned to the far right. We
               intentionally do NOT cap the width here (the scrolling
               content below is still capped via .news-shell-stage) so
               the brand never drifts toward the centre on wide screens. -->
          <div class="w-full px-4 sm:px-6 lg:px-8 py-3 flex items-center gap-3">
            <!-- Brand: FlowOne logo + wordmark. Same logo asset and font
                 stack as the rest of the app so the reader feels like a
                 first-class view, not a dropped-in widget. -->
            <div class="flex items-center gap-2 mr-1">
              <img
                :src="flowoneLogo"
                alt=""
                class="w-7 h-7 sm:w-8 sm:h-8 object-contain shrink-0"
              />
              <span class="font-semibold text-surface-900 dark:text-surface-100 hidden sm:inline">
                FlowOne.PRO News
              </span>
            </div>
            <span
              v-if="storiesCount > 0"
              class="hidden sm:inline-flex items-center rounded-full bg-primary-500/15 dark:bg-primary-400/20 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.18em] text-primary-700 dark:text-primary-300"
            >
              {{ storiesLabel }}
            </span>

            <div class="flex-1" />

            <div class="flex items-center gap-1 sm:gap-2 shrink-0">
              <!-- Always-visible search pill. The clear-X sits INSIDE
                   the input on the right, so the only outer × is the
                   close-modal button. Search hits the backend (q= on
                   /news/items) so it covers every subscribed feed, not
                   just the rows already paginated into memory. -->
              <div
                class="hidden md:flex items-center bg-surface-100 dark:bg-[rgb(var(--color-surface-hover))] rounded-full pl-3 pr-1.5 py-1 focus-within:ring-2 focus-within:ring-primary-500/40 transition-all"
              >
                <span class="material-symbols-rounded text-[16px] text-surface-400 mr-1.5 shrink-0">search</span>
                <input
                  :value="searchInput"
                  type="search"
                  class="bg-transparent border-0 outline-none text-sm w-44 lg:w-56 text-surface-700 dark:text-surface-100 placeholder:text-surface-400"
                  :placeholder="t('newsReader.searchPlaceholder')"
                  :aria-label="t('newsReader.search')"
                  @input="scheduleSearch($event.target.value)"
                  @keydown.esc="clearSearch"
                />
                <button
                  v-if="searchInput"
                  type="button"
                  class="ml-1 inline-flex items-center justify-center w-6 h-6 rounded-full text-surface-500 dark:text-surface-300 hover:text-surface-900 dark:hover:text-white hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface))] transition-colors"
                  :aria-label="t('newsReader.searchClear')"
                  :title="t('newsReader.searchClear')"
                  @click="clearSearch"
                >
                  <span class="material-symbols-rounded text-[16px]">close</span>
                </button>
              </div>

              <button
                type="button"
                class="hidden md:inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-surface-700 dark:text-surface-200 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
                @click="markAll"
              >
                <span class="material-symbols-rounded text-[14px]">done_all</span>
                {{ t('newsReader.markAllRead') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center justify-center w-9 h-9 rounded-full text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                :aria-label="t('newsReader.tickerHide')"
                @click="close"
              >
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
          </div>
        </header>

        <div class="relative flex-1 min-h-0">
          <NewsReaderShell @open-settings="openSettings" />

          <Transition name="news-article">
            <div
              v-if="readerArticle"
              class="news-article-pane absolute inset-0 z-40 flex flex-col bg-surface-50 dark:bg-[rgb(var(--color-bg))]"
              style="overscroll-behavior: contain;"
            >
              <ArticlePage
                :article="readerArticle"
                @back="nr.backFromArticle()"
                @close="close"
              />
            </div>
          </Transition>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.news-reader-fade-enter-active,
.news-reader-fade-leave-active {
  transition: opacity 0.25s ease;
}
.news-reader-fade-enter-from,
.news-reader-fade-leave-to {
  opacity: 0;
}

.news-article-enter-active,
.news-article-leave-active {
  transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
  will-change: transform;
}
.news-article-enter-from {
  transform: translate3d(100%, 0, 0);
}
.news-article-leave-to {
  transform: translate3d(100%, 0, 0);
}
.news-article-enter-active {
  box-shadow: -16px 0 30px -12px rgba(0, 0, 0, 0.18);
}

@media (prefers-reduced-motion: reduce) {
  .news-article-enter-active,
  .news-article-leave-active,
  .news-reader-fade-enter-active,
  .news-reader-fade-leave-active {
    transition: none;
  }
}
</style>
