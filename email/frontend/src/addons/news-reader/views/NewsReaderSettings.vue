<script setup>
import { computed, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { storeToRefs } from 'pinia'
import { useToastStore } from '@/stores/toast'
import { useSettingsStore } from '@/stores/settings'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { useMarketsStore } from '@/addons/news-reader/stores/markets'
import * as newsApi from '@/addons/news-reader/services/newsApi'
import { fetchMarketsAvailable } from '@/addons/news-reader/services/marketsApi'
import InterestPicker from '@/addons/news-reader/components/InterestPicker.vue'

const { t } = useI18n()
const toast = useToastStore()
const settingsStore = useSettingsStore()
const marketsStore = useMarketsStore()
const store = useNewsReaderStore()
const { subscriptions: feeds } = storeToRefs(store)
const { settings: userSettings } = storeToRefs(settingsStore)

/**
 * Classify a subscription so the row can render the right icon + colour.
 * `feed_kind` comes from the backend (news/video). Legacy rows with
 * other kinds fall back to a generic "news" badge.
 */
function feedClassification(s) {
  const kind = (s?.feed_kind || 'news').toLowerCase()
  if (kind === 'video') return { kind: 'video', icon: 'smart_display', label: t('newsReader.videoBadge'), variant: 'video' }
  return { kind: 'news', icon: 'rss_feed', label: t('newsReader.newsBadge'), variant: 'news' }
}

const customUrl = ref('')
const loading = ref(false)

/**
 * Markets panel basket selection. We load the curated allow-list from
 * the server once on mount, then drive the checkbox grids from local
 * Set objects so toggling feels instant. Saves are debounced via a
 * single "Save" button per section to avoid spamming PUT /settings on
 * every checkbox flip.
 */
const marketsLoaded = ref(false)
const marketsLoading = ref(false)
const marketsSaving = ref(false)
const availableStocks = ref([])
const availableCrypto = ref([])
const marketsDefaults = ref({ stocks: [], crypto: [] })
const selectedStocks = ref(new Set())
const selectedCrypto = ref(new Set())

async function loadMarketsAvailable() {
  if (marketsLoading.value) return
  marketsLoading.value = true
  try {
    const data = await fetchMarketsAvailable()
    availableStocks.value = data.stocks || []
    availableCrypto.value = data.crypto || []
    marketsDefaults.value = data.defaults || { stocks: [], crypto: [] }
    // Seed the checkbox state from saved settings (if present) or
    // defaults. The settings store may not be loaded yet on first
    // mount; we re-seed once it is.
    seedMarketsSelection()
    marketsLoaded.value = true
  } catch (_) {
    toast.error(t('newsReader.loadFailed'))
  } finally {
    marketsLoading.value = false
  }
}

function seedMarketsSelection() {
  const savedStocks = userSettings.value?.news_markets_stocks
  const savedCrypto = userSettings.value?.news_markets_crypto
  selectedStocks.value = new Set(
    Array.isArray(savedStocks) && savedStocks.length
      ? savedStocks
      : marketsDefaults.value.stocks
  )
  selectedCrypto.value = new Set(
    Array.isArray(savedCrypto) && savedCrypto.length
      ? savedCrypto
      : marketsDefaults.value.crypto
  )
}

function toggleStock(symbol) {
  if (selectedStocks.value.has(symbol)) selectedStocks.value.delete(symbol)
  else selectedStocks.value.add(symbol)
  // Trigger reactivity (Set mutation isn't observed)
  selectedStocks.value = new Set(selectedStocks.value)
}
function toggleCrypto(id) {
  if (selectedCrypto.value.has(id)) selectedCrypto.value.delete(id)
  else selectedCrypto.value.add(id)
  selectedCrypto.value = new Set(selectedCrypto.value)
}

const stocksDirty = computed(() => {
  const saved = userSettings.value?.news_markets_stocks
  const current = Array.from(selectedStocks.value).sort().join(',')
  const baseline = (Array.isArray(saved) && saved.length
    ? [...saved]
    : [...marketsDefaults.value.stocks]
  ).sort().join(',')
  return current !== baseline
})
const cryptoDirty = computed(() => {
  const saved = userSettings.value?.news_markets_crypto
  const current = Array.from(selectedCrypto.value).sort().join(',')
  const baseline = (Array.isArray(saved) && saved.length
    ? [...saved]
    : [...marketsDefaults.value.crypto]
  ).sort().join(',')
  return current !== baseline
})

async function saveMarkets() {
  if (marketsSaving.value) return
  if (selectedStocks.value.size === 0 && selectedCrypto.value.size === 0) {
    toast.error(t('newsReader.marketsAtLeastOne'))
    return
  }
  marketsSaving.value = true
  try {
    await settingsStore.updateSettings({
      news_markets_stocks: Array.from(selectedStocks.value),
      news_markets_crypto: Array.from(selectedCrypto.value),
    })
    toast.success(t('newsReader.marketsSaved'))
    // Force the markets panel to refetch so the new basket shows up
    // immediately when the user re-opens the reader.
    await marketsStore.load()
  } catch (e) {
    toast.error(t('newsReader.saveFailed'))
  } finally {
    marketsSaving.value = false
  }
}

function resetMarketsToDefaults() {
  selectedStocks.value = new Set(marketsDefaults.value.stocks)
  selectedCrypto.value = new Set(marketsDefaults.value.crypto)
}

async function reload() {
  loading.value = true
  try {
    await store.loadFeeds()
  } catch (e) {
    toast.error(t('newsReader.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  reload()
  // Make sure user settings are loaded before we seed the checkboxes,
  // otherwise saved selections would flicker as defaults first.
  if (!settingsStore.loaded) {
    try { await settingsStore.fetchSettings() } catch (_) {}
  }
  loadMarketsAvailable()
})

async function addFeed(url, category = null) {
  if (!url) return
  try {
    await store.subscribeToFeed(url, category)
    toast.success(t('newsReader.subscribed'))
  } catch (e) {
    toast.error(e?.response?.data?.message || t('newsReader.subscribeFailed'))
  }
}

async function toggleSub(sub, enabled) {
  try {
    await newsApi.patchNewsSubscription(sub.id, { is_enabled: enabled })
    sub.is_enabled = enabled
  } catch (_) {
    toast.error(t('newsReader.saveFailed'))
    await reload()
  }
}

async function removeSub(id) {
  try {
    await store.unsubscribeFromFeed(id)
  } catch (_) {
    toast.error(t('newsReader.saveFailed'))
  }
}

async function markAllRead() {
  try {
    await newsApi.markAllRead({})
    await reload()
  } catch (_) {
    toast.error(t('newsReader.saveFailed'))
  }
}

function addCustom() {
  const u = customUrl.value.trim()
  if (!u) return
  addFeed(u)
  customUrl.value = ''
}
</script>

<template>
  <!-- Full panel width to match the other Settings tabs (Project Hub,
       AI Assistant, Integrations all inherit the panel's width rather
       than clamping themselves to a narrow column). On very wide
       displays we still cap at 7xl so the rows don't stretch to
       unreadable lengths. -->
  <div class="space-y-8 w-full max-w-7xl">
    <div>
      <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-1 flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500">newspaper</span>
        {{ t('newsReader.settingsTitle') }}
      </h2>
      <p class="text-sm text-surface-600 dark:text-surface-400">{{ t('newsReader.settingsIntro') }}</p>
    </div>

    <section class="card p-4 md:p-6 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-medium flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500 text-[20px]">interests</span>
          {{ t('newsReader.interestsTitle') }}
        </h3>
        <button type="button" class="btn-sm btn-secondary inline-flex items-center gap-1.5" :disabled="loading" @click="markAllRead">
          <span class="material-symbols-rounded text-[16px]">done_all</span>
          {{ t('newsReader.markAllRead') }}
        </button>
      </div>
      <InterestPicker />
    </section>

    <section class="card p-4 md:p-6 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-medium">
          {{ t('newsReader.yourFeeds') }}
          <span v-if="feeds.length" class="text-xs text-surface-400 ml-1">({{ feeds.length }})</span>
        </h3>
      </div>
      <div v-if="loading && !feeds.length" class="text-sm text-surface-500">{{ t('newsReader.loading') }}…</div>
      <ul v-else class="divide-y divide-surface-200 dark:divide-surface-700">
        <li
          v-for="s in feeds"
          :key="s.id"
          class="py-3.5 flex items-center gap-3 group"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 min-w-0">
              <!-- Kind badge: shows at a glance whether this row is an
                   RSS news feed or a YouTube channel. -->
              <span
                :class="[
                  'feed-kind-pill shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-[0.16em] text-white',
                  feedClassification(s).variant === 'video' ? 'bg-red-600'
                  : 'bg-surface-500 dark:bg-surface-600',
                ]"
                :title="feedClassification(s).label"
              >
                <span
                  class="material-symbols-rounded text-[11px] leading-none"
                  style="font-variation-settings: 'FILL' 1;"
                >{{ feedClassification(s).icon }}</span>
                {{ feedClassification(s).label }}
              </span>
              <div class="font-medium truncate text-surface-900 dark:text-surface-50">
                {{ s.feed_title || s.canonical_feed_url }}
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-0.5 text-xs text-surface-500">
              <span v-if="s.category" class="inline-flex items-center px-2 py-0.5 rounded-full bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 text-[10px] font-semibold uppercase tracking-wider">
                {{ s.category }}
              </span>
              <span>{{ t('newsReader.unread') }}: {{ s.unread_count ?? 0 }}</span>
            </div>
          </div>

          <label
            class="relative inline-flex items-center cursor-pointer shrink-0"
            :title="s.is_enabled ? t('newsReader.enabled') : t('newsReader.disabled')"
          >
            <input
              type="checkbox"
              :checked="!!s.is_enabled"
              class="sr-only peer"
              @change="toggleSub(s, $event.target.checked)"
            />
            <div
              class="w-10 h-5 bg-surface-300 dark:bg-surface-600 peer-focus:ring-2 peer-focus:ring-primary-500 rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary-500 transition-colors"
            />
          </label>

          <button
            type="button"
            class="shrink-0 p-2 rounded-lg text-surface-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
            :title="t('newsReader.remove')"
            :aria-label="t('newsReader.remove')"
            @click="removeSub(s.id)"
          >
            <span class="material-symbols-rounded text-[18px]">delete</span>
          </button>
        </li>
        <li v-if="!feeds.length && !loading" class="py-6 text-sm text-surface-500 text-center italic">
          {{ t('newsReader.noFeeds') }}
        </li>
      </ul>
    </section>

    <section class="card p-4 md:p-6 space-y-3">
      <div>
        <h3 class="font-medium">{{ t('newsReader.addCustom') }}</h3>
        <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">{{ t('newsReader.addCustomHint') }}</p>
      </div>
      <div class="flex gap-2 flex-wrap">
        <input
          v-model="customUrl"
          type="text"
          class="input flex-1 min-w-[200px]"
          :placeholder="t('newsReader.feedUrlPlaceholder')"
          @keyup.enter="addCustom"
        />
        <button type="button" class="btn-primary inline-flex items-center gap-1.5" @click="addCustom">
          <span class="material-symbols-rounded text-[16px]">add</span>
          {{ t('newsReader.add') }}
        </button>
      </div>
    </section>

    <!-- Markets panel basket selection -->
    <section class="card p-4 md:p-6 space-y-5">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="font-medium flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500 text-[20px]">trending_up</span>
            {{ t('newsReader.marketsSettingsTitle') }}
          </h3>
          <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">{{ t('newsReader.marketsSettingsIntro') }}</p>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="btn-sm btn-secondary inline-flex items-center gap-1.5"
            :disabled="marketsLoading || marketsSaving"
            @click="resetMarketsToDefaults"
          >
            <span class="material-symbols-rounded text-[16px]">restart_alt</span>
            {{ t('newsReader.marketsResetDefaults') }}
          </button>
          <button
            type="button"
            class="btn-sm btn-primary inline-flex items-center gap-1.5"
            :disabled="marketsSaving || marketsLoading || (!stocksDirty && !cryptoDirty)"
            @click="saveMarkets"
          >
            <span
              class="material-symbols-rounded text-[16px]"
              :class="marketsSaving ? 'animate-spin' : ''"
            >{{ marketsSaving ? 'progress_activity' : 'save' }}</span>
            {{ t('newsReader.marketsSave') }}
          </button>
        </div>
      </div>

      <div v-if="marketsLoading && !marketsLoaded" class="text-sm text-surface-500">
        {{ t('newsReader.loading') }}…
      </div>

      <template v-else>
        <!-- Stocks group -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <h4 class="text-xs font-bold uppercase tracking-[0.18em] text-surface-500 dark:text-surface-400">
              {{ t('newsReader.marketsStocksGroup') }}
            </h4>
            <span class="text-[11px] text-surface-400">{{ selectedStocks.size }} / {{ availableStocks.length }}</span>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-1.5">
            <label
              v-for="row in availableStocks"
              :key="row.symbol"
              class="markets-pick flex items-center gap-2.5 px-3 py-2 rounded-lg border cursor-pointer transition-colors"
              :class="selectedStocks.has(row.symbol)
                ? 'border-primary-500 bg-primary-500/10 text-surface-900 dark:text-surface-50'
                : 'border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200'"
            >
              <input
                type="checkbox"
                class="h-3.5 w-3.5 accent-primary-500 shrink-0"
                :checked="selectedStocks.has(row.symbol)"
                @change="toggleStock(row.symbol)"
              />
              <span class="text-sm font-semibold truncate">{{ row.name }}</span>
              <span class="text-[10px] uppercase tracking-wider text-surface-400 ml-auto shrink-0">
                {{ row.symbol }}
              </span>
            </label>
          </div>
        </div>

        <!-- Crypto group -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <h4 class="text-xs font-bold uppercase tracking-[0.18em] text-surface-500 dark:text-surface-400">
              {{ t('newsReader.marketsCryptoGroup') }}
            </h4>
            <span class="text-[11px] text-surface-400">{{ selectedCrypto.size }} / {{ availableCrypto.length }}</span>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-1.5">
            <label
              v-for="row in availableCrypto"
              :key="row.id"
              class="markets-pick flex items-center gap-2.5 px-3 py-2 rounded-lg border cursor-pointer transition-colors"
              :class="selectedCrypto.has(row.id)
                ? 'border-primary-500 bg-primary-500/10 text-surface-900 dark:text-surface-50'
                : 'border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200'"
            >
              <input
                type="checkbox"
                class="h-3.5 w-3.5 accent-primary-500 shrink-0"
                :checked="selectedCrypto.has(row.id)"
                @change="toggleCrypto(row.id)"
              />
              <span class="text-sm font-semibold truncate">{{ row.name }}</span>
              <span class="text-[10px] uppercase tracking-wider text-surface-400 ml-auto shrink-0">
                {{ row.symbol }}
              </span>
            </label>
          </div>
        </div>

        <p class="text-[11px] text-surface-400 dark:text-surface-500">
          {{ t('newsReader.marketsLimitHint') }}
        </p>
      </template>
    </section>
  </div>
</template>

