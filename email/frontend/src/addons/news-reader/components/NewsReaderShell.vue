<script setup>
/**
 * Top-level layout for the News reader (the dashboard-style view that
 * replaces the previous single-column masonry).
 *
 * Owns the persistent left rail + the main scrolling content column.
 * Composition (top to bottom in the main column):
 *   1. HeroCarousel        -- rotating featured stories
 *   2. FeaturedRow         -- 3-up tiles
 *   3. MarketsPanel        -- market overview + crypto cards
 *   4. LatestNewsList      -- compact list
 *   5. EditorsPicks        -- 4-up tiles
 *
 * The reader's header (title + search + close + mark all read) is owned
 * by the parent (FlipboardReader.vue) so the existing teleport / overlay
 * wiring stays untouched.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import * as newsApi from '@/addons/news-reader/services/newsApi'
import LeftRail from './LeftRail.vue'
import HeroCarousel from './HeroCarousel.vue'
import FeaturedRow from './FeaturedRow.vue'
import MarketsPanel from './MarketsPanel.vue'
import LatestNewsList from './LatestNewsList.vue'
import EditorsPicks from './EditorsPicks.vue'

const emit = defineEmits(['close', 'open-article', 'open-settings'])

const { t } = useI18n()
const router = useRouter()
const nr = useNewsReaderStore()
const {
  itemOrder,
  itemsMap,
  listHasMore,
  loading,
  currentCategory,
  viewBookmarksOnly,
  viewVideosOnly,
  currentFeedId,
  searchQuery,
} = storeToRefs(nr)

/**
 * "Home" view = no active filter (category, source, bookmarks-only,
 * videos-only, or search). Today the only thing this gates is the
 * Markets / Crypto panel — the dashboard widget belongs on the home
 * feed and would be a distraction on a category drilldown. Hero,
 * featured, latest, and editor's picks all render on every view so
 * a filtered page still has the magazine layout (big image -> 3
 * tiles -> compact list -> 4 picks tiles).
 */
const isHomeView = computed(() =>
  !currentCategory.value
  && !currentFeedId.value
  && !viewBookmarksOnly.value
  && !viewVideosOnly.value
  && !((searchQuery.value || '').trim())
)

const allItems = computed(() =>
  itemOrder.value.map((id) => itemsMap.value.get(id)).filter(Boolean)
)

/**
 * Slice the loaded list into the four sections of the dashboard layout.
 * Hero gets the first ~5 image-bearing items; featured the next 3; latest
 * is a compact list of everything (image-bearing or not); editor's picks
 * highlights 4 visually-strong tiles further down the list. We keep the
 * sections disjoint so the user doesn't see the same headline twice in
 * different sections of the page.
 */
const itemsWithImage = computed(() =>
  allItems.value.filter((a) => !!a.image_url || !!a.video_thumbnail_url)
)

const HERO_COUNT = 5
const FEATURED_COUNT = 3
const PICKS_COUNT = 4

const heroItems = computed(() => itemsWithImage.value.slice(0, HERO_COUNT))
const featuredItems = computed(
  () => itemsWithImage.value.slice(HERO_COUNT, HERO_COUNT + FEATURED_COUNT)
)
const picksItems = computed(() => {
  const used = HERO_COUNT + FEATURED_COUNT
  // Pull picks from later in the list so they feel curated rather than
  // a continuation of the featured row. If we don't have enough imagery,
  // gracefully shorten.
  return itemsWithImage.value.slice(used, used + PICKS_COUNT)
})
const latestItems = computed(() => {
  // Hero + featured items are rendered as their own visual rows above
  // this list (on every view, home or filtered) — exclude them here
  // so the same headline doesn't appear twice on the page. Picks are
  // intentionally allowed to overlap with this list because they're a
  // curated visual highlight, not a continuation of the chronological
  // feed.
  const skip = new Set(
    [...heroItems.value, ...featuredItems.value].map((a) => a.id)
  )
  return allItems.value.filter((a) => !skip.has(a.id))
})

const sectionTitle = computed(() => {
  if (viewBookmarksOnly.value) return t('newsReader.navSaved')
  if (viewVideosOnly.value) return t('newsReader.navVideos')
  if (currentFeedId.value) return t('newsReader.bySource')
  if (currentCategory.value) {
    const key = `newsReader.interest.${currentCategory.value}`
    return t(key, currentCategory.value)
  }
  return t('newsReader.navHome')
})

async function openArticle(article) {
  if (!article) return
  try {
    await newsApi.markNewsItemRead(article.id)
    article.is_read = true
  } catch (_) {}
  nr.openReader(article)
}

function applySourceFilter({ feedId }) {
  if (!feedId) return
  nr.setFeedFilter(feedId)
  scrollMainToTop()
}

async function loadMore() {
  await nr.loadItems({ append: true })
}

const mainScroll = ref(null)
function scrollMainToTop() {
  if (mainScroll.value && typeof mainScroll.value.scrollTo === 'function') {
    mainScroll.value.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

function openSettings() {
  emit('close')
  router.push({ name: 'settings', query: { tab: 'news_reader' } }).catch(() => {})
  emit('open-settings')
}

// Reset scroll whenever a filter changes — avoids the user wondering why
// the page didn't move when they tap a category. Watch combined keys
// instead of a single ref so any of the relevant pivots resets it.
watch(
  () => [
    currentCategory.value,
    currentFeedId.value,
    viewBookmarksOnly.value,
    viewVideosOnly.value,
  ],
  () => {
    scrollMainToTop()
  }
)
</script>

<template>
  <div class="news-shell flex h-full min-h-0 w-full">
    <!-- Left rail (hidden on mobile; bottom ticker is the mobile entry) -->
    <div class="news-shell-rail shrink-0 hidden md:block">
      <LeftRail
        :stories-count="allItems.length"
        @scroll-top="scrollMainToTop"
        @open-settings="openSettings"
      />
    </div>

    <!-- Main scrolling column -->
    <div
      ref="mainScroll"
      class="flex-1 min-w-0 overflow-y-auto overscroll-y-contain"
      style="touch-action: pan-y; overscroll-behavior: contain;"
    >
      <div class="news-shell-stage mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
        <!-- Empty state -->
        <div v-if="!allItems.length" class="py-32 text-center">
          <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">newspaper</span>
          <div class="mt-4 text-xl text-surface-500 dark:text-surface-400 italic">
            {{ t('newsReader.tickerEmpty') }}
          </div>
        </div>

        <template v-else>
          <!-- Active section label (shows when filtered) -->
          <div
            v-if="!viewBookmarksOnly && !viewVideosOnly && !currentCategory && !currentFeedId"
            class="sr-only"
          >
            {{ sectionTitle }}
          </div>

          <!-- Hero: shown on every view (home, category, source,
               videos, bookmarks). On filtered views it's still useful
               as the "headline" tile, populated from the items that
               match the current filter rather than the whole feed. -->
          <HeroCarousel
            v-if="heroItems.length"
            :items="heroItems"
            @open="openArticle"
            @filter-source="applySourceFilter"
          />

          <!-- 3 Featured tiles -->
          <FeaturedRow
            v-if="featuredItems.length"
            :items="featuredItems"
            class="mt-6"
            @open="openArticle"
            @filter-source="applySourceFilter"
          />

          <!-- Markets / Crypto: HOME ONLY. Category and source views
               deliberately hide it so the dashboard widgets don't
               distract from the headlines the user just filtered to. -->
          <MarketsPanel v-if="isHomeView" class="mt-8" />

          <!-- Latest news (compact list).
               Excludes the items already promoted into the hero /
               featured rows so we never show the same headline twice
               on the page. No client-side slice: showing every item
               already in memory means clicking "Load more" actually
               grows the visible list. The old slice(0, 12) was the
               cause of the "story count grows but I don't see new
               headlines" bug. -->
          <LatestNewsList
            v-if="latestItems.length"
            :items="latestItems"
            class="mt-8"
            @open="openArticle"
            @filter-source="applySourceFilter"
          />

          <!-- Editor's picks (the second visual row, lower in the
               page). Always shown when we have enough imagery,
               regardless of filter — this is the rhythm change the
               user expects: big image -> 3 tiles -> list -> 4 tiles. -->
          <EditorsPicks
            v-if="picksItems.length"
            :items="picksItems"
            class="mt-8"
            @open="openArticle"
            @filter-source="applySourceFilter"
          />

          <!-- Load more -->
          <div v-if="listHasMore" class="mt-10 mb-4 flex justify-center">
            <button
              type="button"
              class="inline-flex items-center gap-2 rounded-full bg-surface-900 dark:bg-white text-white dark:text-surface-900 px-5 py-2.5 text-sm font-semibold tracking-wide hover:opacity-90 transition-opacity disabled:opacity-50"
              :disabled="loading"
              @click="loadMore"
            >
              <span v-if="loading" class="material-symbols-rounded animate-spin text-[16px]">progress_activity</span>
              <span v-else class="material-symbols-rounded text-[16px]">expand_more</span>
              {{ loading ? t('newsReader.loading') : t('newsReader.loadMore') }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.news-shell-rail {
  width: 240px;
}
@media (min-width: 1280px) {
  .news-shell-rail {
    width: 260px;
  }
}

.news-shell-stage {
  width: 100%;
  max-width: 1400px;
}
@media (min-width: 1920px) {
  .news-shell-stage { max-width: 1600px; }
}
@media (min-width: 2300px) {
  .news-shell-stage { max-width: 1900px; }
}
</style>
