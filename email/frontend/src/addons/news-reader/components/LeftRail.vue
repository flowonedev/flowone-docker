<script setup>
/**
 * Persistent left navigation for the News reader.
 *
 * Layout matches the dashboard-style mock: top primary nav (Home / Saved /
 * Videos), a CATEGORIES section that lists the user's interests, and a
 * footer with Settings + a light/dark toggle.
 *
 * Theme: every visual rule is driven by Tailwind `dark:` variants so it
 * tracks the global theme toggle without needing explicit CSS overrides.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { storeToRefs } from 'pinia'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { useThemeStore } from '@/stores/theme'
import { categoryIcon } from '@/addons/news-reader/data/categories'

defineProps({
  storiesCount: { type: Number, default: 0 },
})
const emit = defineEmits(['scroll-top', 'open-settings'])

const { t, te } = useI18n()
const nr = useNewsReaderStore()
const theme = useThemeStore()
const {
  readerFilterChips,
  currentCategory,
  viewBookmarksOnly,
  viewVideosOnly,
  hasBookmarks,
  hasVideos,
  currentFeedId,
  currentFeedMeta,
} = storeToRefs(nr)

/**
 * The sidebar shows a short primary list of categories by default; the
 * user's full interest list is gated behind "More categories" to keep the
 * rail readable when they have a long list.
 */
const PRIMARY_CATEGORY_COUNT = 8
const showAllCategories = ref(false)
const visibleCategories = computed(() => {
  const all = readerFilterChips.value
  if (showAllCategories.value || all.length <= PRIMARY_CATEGORY_COUNT) return all
  return all.slice(0, PRIMARY_CATEGORY_COUNT)
})
const hasMoreCategories = computed(
  () => readerFilterChips.value.length > PRIMARY_CATEGORY_COUNT
)

const isHomeActive = computed(
  () =>
    !currentCategory.value &&
    !viewBookmarksOnly.value &&
    !viewVideosOnly.value &&
    !currentFeedId.value
)

function chipLabel(slug) {
  const key = `newsReader.interest.${slug}`
  return te(key) ? t(key) : slug
}

function selectHome() {
  nr.setCategory('')
  emit('scroll-top')
}
function toggleBookmarks() {
  nr.setBookmarksFilter(!viewBookmarksOnly.value)
  emit('scroll-top')
}
function toggleVideos() {
  nr.setVideosFilter(!viewVideosOnly.value)
  emit('scroll-top')
}
function selectCategory(slug) {
  nr.setCategory(slug)
  emit('scroll-top')
}
function clearSourceFilter() {
  nr.clearFeedFilter()
  emit('scroll-top')
}

const sourceChipLabel = computed(() => {
  if (!currentFeedMeta.value) return ''
  return (
    (currentFeedMeta.value.feed_title || '').trim() ||
    currentFeedMeta.value.canonical_feed_url ||
    ''
  )
})

/**
 * Shared row classes. Idle vs active is the only state distinction; both
 * states declare their colours through Tailwind's `dark:` variants so a
 * theme flip is automatic. Active uses the project's primary accent
 * (purple by default, follows the user's chosen accent).
 */
const baseRowCls =
  'inline-flex items-center gap-3 w-full px-3 py-2.5 rounded-[10px] text-[13px] font-semibold tracking-tight text-left transition-colors'
const idleRowCls =
  'text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-900 dark:hover:text-surface-50'
const activeRowCls = 'bg-primary-500 hover:bg-primary-600 text-white'
</script>

<template>
  <aside
    class="flex h-full min-h-0 flex-col bg-white dark:bg-[rgb(var(--color-bg))] border-r border-surface-200/70 dark:border-surface-700/60"
  >
    <!-- Primary nav: Home / Saved / Videos -->
    <nav class="flex-shrink-0 px-3 pt-4 pb-2 space-y-1">
      <button
        type="button"
        :class="[baseRowCls, isHomeActive ? activeRowCls : idleRowCls]"
        @click="selectHome"
      >
        <span class="material-symbols-rounded text-[18px] leading-none shrink-0">home</span>
        <span class="truncate">{{ t('newsReader.navHome') }}</span>
      </button>
      <button
        v-if="hasBookmarks || viewBookmarksOnly"
        type="button"
        :class="[baseRowCls, viewBookmarksOnly ? activeRowCls : idleRowCls]"
        @click="toggleBookmarks"
      >
        <span
          class="material-symbols-rounded text-[18px] leading-none shrink-0"
          :style="viewBookmarksOnly ? `font-variation-settings: 'FILL' 1` : ''"
        >bookmark</span>
        <span class="truncate">{{ t('newsReader.navSaved') }}</span>
      </button>
      <button
        v-if="hasVideos || viewVideosOnly"
        type="button"
        :class="[baseRowCls, viewVideosOnly ? activeRowCls : idleRowCls]"
        @click="toggleVideos"
      >
        <span
          class="material-symbols-rounded text-[18px] leading-none shrink-0"
          :style="viewVideosOnly ? `font-variation-settings: 'FILL' 1` : ''"
        >play_circle</span>
        <span class="truncate">{{ t('newsReader.navVideos') }}</span>
      </button>

      <!-- Active per-source filter pill -->
      <button
        v-if="currentFeedId && sourceChipLabel"
        type="button"
        :class="[baseRowCls, activeRowCls]"
        :title="t('newsReader.clearSourceFilter')"
        @click="clearSourceFilter"
      >
        <span class="material-symbols-rounded text-[18px] leading-none shrink-0">filter_alt</span>
        <span class="truncate flex-1 text-left">{{ sourceChipLabel }}</span>
        <span class="material-symbols-rounded text-[14px] leading-none opacity-80">close</span>
      </button>
    </nav>

    <!-- Categories -->
    <div
      v-if="readerFilterChips.length"
      class="flex-1 min-h-0 overflow-y-auto px-3 pt-4 pb-2"
    >
      <h4
        class="px-2 pb-2 text-[10px] font-bold tracking-[0.2em] uppercase text-surface-400 dark:text-surface-500"
      >
        {{ t('newsReader.categoriesLabel') }}
      </h4>
      <div class="space-y-0.5">
        <button
          v-for="slug in visibleCategories"
          :key="slug"
          type="button"
          :class="[baseRowCls, currentCategory === slug && !viewBookmarksOnly && !viewVideosOnly && !currentFeedId
            ? activeRowCls
            : idleRowCls]"
          @click="selectCategory(slug)"
        >
          <span class="material-symbols-rounded text-[18px] leading-none shrink-0">{{ categoryIcon(slug) }}</span>
          <span class="truncate">{{ chipLabel(slug) }}</span>
        </button>
        <button
          v-if="hasMoreCategories"
          type="button"
          :class="[baseRowCls, idleRowCls]"
          @click="showAllCategories = !showAllCategories"
        >
          <span class="material-symbols-rounded text-[18px] leading-none shrink-0">
            {{ showAllCategories ? 'expand_less' : 'more_horiz' }}
          </span>
          <span class="truncate">
            {{ showAllCategories ? t('newsReader.showLess') : t('newsReader.moreCategories') }}
          </span>
          <span class="material-symbols-rounded text-[14px] leading-none opacity-60 ml-auto">
            chevron_right
          </span>
        </button>
      </div>
    </div>
    <div v-else class="flex-1" />

    <!-- Footer: Settings + theme toggle -->
    <div
      class="flex-shrink-0 px-3 pt-3 pb-4 border-t border-surface-200/70 dark:border-surface-700/60 space-y-1"
    >
      <button
        type="button"
        :class="[baseRowCls, idleRowCls]"
        @click="emit('open-settings')"
      >
        <span class="material-symbols-rounded text-[18px] leading-none shrink-0">settings</span>
        <span class="truncate">{{ t('newsReader.navSettings') }}</span>
      </button>
      <button
        type="button"
        :class="[baseRowCls, idleRowCls]"
        :title="theme.isDark ? t('newsReader.themeLight') : t('newsReader.themeDark')"
        @click="theme.toggleTheme()"
      >
        <span class="material-symbols-rounded text-[18px] leading-none shrink-0">
          {{ theme.isDark ? 'light_mode' : 'dark_mode' }}
        </span>
        <span class="truncate">
          {{ theme.isDark ? t('newsReader.themeLight') : t('newsReader.themeDark') }}
        </span>
      </button>
    </div>
  </aside>
</template>
