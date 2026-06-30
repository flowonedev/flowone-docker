<script setup>
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { useLayoutStore } from '@/stores/layout'
import { articleDisplayTitle } from '@/addons/news-reader/utils/articleDisplay'

const { t } = useI18n()
const store = useNewsReaderStore()
const { tickerExpanded, unreadTotal } = storeToRefs(store)
const layout = useLayoutStore()

const tickerItems = computed(() => {
  const ids = store.itemOrder || []
  const m = store.itemsMap
  return ids
    .map((id) => m.get(id))
    .filter(Boolean)
    .map((it) => ({
      ...it,
      title: articleDisplayTitle(it) || t('newsReader.untitled'),
    }))
})

onMounted(async () => {
  try {
    await store.bootstrap()
  } catch (e) {
    console.warn('[BottomTicker] bootstrap failed', e)
  }
})

function toggleExpand() {
  store.setTickerExpanded(!tickerExpanded.value)
}

const rootClass = computed(() => {
  const base = 'fixed left-0 right-0 z-[45] flex flex-col items-stretch pointer-events-none'
  const nav = layout.isMobile ? ' bottom-[56px]' : ' bottom-0'
  return base + nav
})
</script>

<template>
  <Teleport to="body">
    <div :class="rootClass" class="news-reader-ticker-root">
      <div class="flex justify-center">
        <button
          type="button"
          class="news-ticker-handle pointer-events-auto group inline-flex items-center justify-center px-6 py-2"
          :aria-label="tickerExpanded ? t('newsReader.tickerHide') : t('newsReader.tickerShow')"
          @click="toggleExpand"
        >
          <span
            class="block w-9 h-[4px] rounded-full transition-colors duration-200"
            :class="unreadTotal > 0
              ? 'bg-primary-500 dark:bg-primary-400 group-hover:bg-primary-600 dark:group-hover:bg-primary-300'
              : 'bg-surface-300 dark:bg-surface-600 group-hover:bg-surface-400 dark:group-hover:bg-surface-500'"
          />
        </button>
      </div>

      <div
        v-show="tickerExpanded"
        class="pointer-events-auto h-9 flex items-center bg-white/95 dark:bg-surface-900/95 backdrop-blur-md border-t border-surface-200/60 dark:border-surface-700/60 shadow-[0_-2px_14px_rgba(0,0,0,0.05)]"
      >
        <div class="flex items-center pl-1.5 pr-1 shrink-0 border-r border-surface-200/60 dark:border-surface-700/60">
          <button
            type="button"
            class="p-1 rounded-md text-surface-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-surface-100 dark:hover:bg-surface-800/60 transition-colors"
            :title="t('newsReader.openBrowse')"
            @click="store.openReaderBrowse()"
          >
            <span class="material-symbols-rounded text-[14px] leading-none">grid_view</span>
          </button>
        </div>

        <div v-if="!tickerItems.length" class="px-4 text-[11px] italic text-surface-400">
          {{ t('newsReader.tickerEmpty') }}
        </div>
        <div v-else class="ticker-viewport flex-1 min-w-0 overflow-hidden">
          <div class="ticker-track flex items-center gap-10 whitespace-nowrap">
            <template v-for="(it, idx) in [...tickerItems, ...tickerItems]" :key="`${it.id}-${idx}`">
              <button
                type="button"
                class="inline-flex items-center gap-2 text-left text-[12.5px] text-surface-700 dark:text-surface-200 hover:text-primary-600 dark:hover:text-primary-400 transition-colors max-w-[60vw] sm:max-w-md"
                @click="store.openArticleFromTicker(it)"
              >
                <span
                  v-if="!it.is_read"
                  class="w-1 h-1 rounded-full bg-primary-500 shrink-0"
                  aria-hidden="true"
                />
                <span class="truncate font-medium tracking-tight">{{ it.title }}</span>
              </button>
            </template>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.ticker-viewport {
  mask-image: linear-gradient(90deg, transparent, black 6%, black 94%, transparent);
  -webkit-mask-image: linear-gradient(90deg, transparent, black 6%, black 94%, transparent);
}
.ticker-track {
  animation: news-ticker-scroll 90s linear infinite;
}
.ticker-track:hover {
  animation-play-state: paused;
}
.news-ticker-handle {
  position: relative;
}
@keyframes news-ticker-scroll {
  from {
    transform: translateX(0);
  }
  to {
    transform: translateX(-50%);
  }
}
</style>
