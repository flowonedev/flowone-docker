<script setup>
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { useToastStore } from '@/stores/toast'
import { INTEREST_CATEGORIES } from '@/addons/news-reader/data/categories'

const { t, te } = useI18n()
const store = useNewsReaderStore()
const toast = useToastStore()
const {
  userInterests,
  busyInterests,
  subscriptions,
  catalog,
} = storeToRefs(store)

const pendingOff = ref(null)
const busySites = ref(new Set())

onMounted(() => {
  // Force a fresh catalog fetch every time settings is opened so newly
  // deployed curated feeds appear without needing a full page reload.
  store.loadCatalog(true)
})

const interestSet = computed(() => new Set(userInterests.value))

function isOn(slug) {
  return interestSet.value.has(slug)
}
function isBusy(slug) {
  return busyInterests.value.has(slug)
}
function isSiteBusy(key) {
  return busySites.value.has(key)
}
function label(slug) {
  const key = `newsReader.interest.${slug}`
  if (te(key)) return t(key)
  const c = INTEREST_CATEGORIES.find((x) => x.slug === slug)
  return c ? c.defaultLabel : slug
}
function iconFor(slug) {
  const c = INTEREST_CATEGORIES.find((x) => x.slug === slug)
  return c ? c.icon : 'label'
}

/**
 * For an interest, return the merged list of:
 *   - curated catalog entries tagged with this category
 *   - user subscriptions tagged with this category that aren't in the catalog
 *
 * Each row knows whether it's currently subscribed and exposes toggle data.
 */
function sitesForInterest(slug) {
  const subs = store.subscribedForCategory(slug)
  const curated = store.curatedForCategory(slug)
  const subByKey = store.subscriptionByUrlKey
  const rows = []
  const seenKeys = new Set()
  for (const c of curated) {
    const key = store.urlKey(c.feed_url)
    seenKeys.add(key)
    const sub = subByKey.get(key) || null
    rows.push({
      key: 'curated-' + c.id,
      name: c.name,
      region: c.region || '',
      feedUrl: c.feed_url,
      siteUrl: c.site_url || '',
      category: c.default_category || slug,
      subscribed: !!sub,
      sub,
      isCustom: false,
    })
  }
  for (const s of subs) {
    const key = store.urlKey(s.canonical_feed_url || s.feed_url || '')
    if (key && !seenKeys.has(key)) {
      rows.push({
        key: 'sub-' + s.id,
        name: s.feed_title || s.canonical_feed_url || s.feed_url,
        region: '',
        feedUrl: s.canonical_feed_url || s.feed_url,
        siteUrl: '',
        category: s.category || slug,
        subscribed: true,
        sub: s,
        isCustom: true,
      })
    }
  }
  return rows
}

function counts(slug) {
  const rows = sitesForInterest(slug)
  return { active: rows.filter((r) => r.subscribed).length, total: rows.length }
}

async function turnOn(slug) {
  const added = await store.enableInterest(slug)
  if (added > 0) {
    toast.success(t('newsReader.interestAdded', { n: added, topic: label(slug) }))
  }
}

function requestOff(slug) {
  const subs = store.subscribedForCategory(slug)
  if (subs.length === 0) {
    store.disableInterest(slug, { removeFeeds: false })
    return
  }
  pendingOff.value = slug
}

async function confirmRemove() {
  const slug = pendingOff.value
  if (!slug) return
  pendingOff.value = null
  const removed = await store.disableInterest(slug, { removeFeeds: true })
  if (removed > 0) {
    toast.success(t('newsReader.interestRemoved', { n: removed, topic: label(slug) }))
  }
}

async function confirmKeep() {
  const slug = pendingOff.value
  if (!slug) return
  pendingOff.value = null
  await store.disableInterest(slug, { removeFeeds: false })
}

function cancelPending() {
  pendingOff.value = null
}

async function togglePill(slug) {
  if (isBusy(slug)) return
  if (pendingOff.value && pendingOff.value !== slug) pendingOff.value = null
  if (isOn(slug)) requestOff(slug)
  else await turnOn(slug)
}

async function toggleSite(slug, site) {
  if (isSiteBusy(site.key)) return
  busySites.value = new Set([...busySites.value, site.key])
  try {
    if (site.subscribed) {
      await store.unsubscribeFromFeed(site.sub.id)
    } else {
      await store.subscribeToFeed(site.feedUrl, site.category || slug)
    }
  } catch (e) {
    toast.error(t('newsReader.saveFailed'))
  } finally {
    const next = new Set(busySites.value)
    next.delete(site.key)
    busySites.value = next
  }
}

async function enableAllForInterest(slug) {
  const rows = sitesForInterest(slug).filter((r) => !r.subscribed && !r.isCustom)
  if (!rows.length) return
  for (const r of rows) {
    busySites.value = new Set([...busySites.value, r.key])
  }
  let added = 0
  for (const r of rows) {
    try {
      await store.subscribeToFeed(r.feedUrl, r.category)
      added++
    } catch (_) {}
  }
  busySites.value = new Set()
  if (added > 0) toast.success(t('newsReader.interestAdded', { n: added, topic: label(slug) }))
}

function selectAll() {
  store.setUserInterests(INTEREST_CATEGORIES.map((c) => c.slug))
}
function selectNone() {
  store.setUserInterests([])
}
function selectDefault() {
  store.setUserInterests(['news', 'world', 'tech', 'business', 'sports'])
}

const activeInterests = computed(() => userInterests.value)

function regionTone(region) {
  switch (region) {
    case 'HU': return 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'
    case 'EN': return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
    case 'US': return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
    default: return 'bg-surface-200 text-surface-600 dark:bg-surface-700 dark:text-surface-300'
  }
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-surface-600 dark:text-surface-400 max-w-2xl">
        {{ t('newsReader.interestsIntro') }}
      </p>
      <div class="flex items-center gap-1.5 text-xs">
        <button type="button" class="px-3 py-1 rounded-full font-medium text-surface-600 dark:text-surface-300 hover:text-primary-600 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors" @click="selectDefault">
          {{ t('newsReader.interestsDefault') }}
        </button>
        <button type="button" class="px-3 py-1 rounded-full font-medium text-surface-600 dark:text-surface-300 hover:text-primary-600 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors" @click="selectAll">
          {{ t('newsReader.interestsAll') }}
        </button>
        <button type="button" class="px-3 py-1 rounded-full font-medium text-surface-600 dark:text-surface-300 hover:text-primary-600 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors" @click="selectNone">
          {{ t('newsReader.interestsNone') }}
        </button>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <button
        v-for="c in INTEREST_CATEGORIES"
        :key="c.slug"
        type="button"
        class="interest-pill inline-flex items-center gap-1.5 pl-2.5 pr-3 py-1.5 rounded-full border text-sm font-medium transition-all"
        :class="[
          isOn(c.slug)
            ? 'bg-primary-500 text-white border-primary-500 shadow-sm'
            : 'bg-white dark:bg-surface-800 border-surface-200 dark:border-surface-600 text-surface-700 dark:text-surface-200 hover:border-primary-300 dark:hover:border-primary-700 hover:text-primary-700 dark:hover:text-primary-300',
          isBusy(c.slug) ? 'opacity-60 cursor-progress' : '',
        ]"
        :disabled="isBusy(c.slug)"
        @click="togglePill(c.slug)"
      >
        <span
          class="material-symbols-rounded text-[16px] shrink-0 transition-colors"
          :class="isOn(c.slug) ? 'text-white' : 'text-surface-400'"
        >{{ c.icon }}</span>
        <span class="truncate">{{ label(c.slug) }}</span>
        <span
          v-if="isOn(c.slug)"
          class="ml-0.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold bg-white/25 text-white"
        >{{ counts(c.slug).active }}</span>
      </button>
    </div>

    <Transition name="confirm">
      <div
        v-if="pendingOff"
        class="rounded-2xl border border-amber-300 dark:border-amber-700/50 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 flex flex-wrap items-center gap-3"
      >
        <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">info</span>
        <span class="flex-1 min-w-[200px] text-sm text-amber-900 dark:text-amber-100">
          {{ t('newsReader.confirmRemoveFeeds', { n: store.subscribedForCategory(pendingOff).length, topic: label(pendingOff) }) }}
        </span>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-700 dark:text-surface-200 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
            @click="confirmKeep"
          >{{ t('newsReader.keepFeeds') }}</button>
          <button
            type="button"
            class="px-3.5 py-1.5 rounded-full text-xs font-semibold bg-red-500 text-white hover:bg-red-600 shadow-sm transition-colors"
            @click="confirmRemove"
          >{{ t('newsReader.removeFeeds', { n: store.subscribedForCategory(pendingOff).length }) }}</button>
          <button
            type="button"
            class="p-1 rounded-full text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors"
            :aria-label="t('newsReader.cancel')"
            @click="cancelPending"
          >
            <span class="material-symbols-rounded text-[18px]">close</span>
          </button>
        </div>
      </div>
    </Transition>

    <div v-if="activeInterests.length > 0" class="space-y-3">
      <div class="flex items-center gap-2">
        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-surface-500 dark:text-surface-400">
          {{ t('newsReader.feedsByInterest') }}
        </span>
        <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700" />
      </div>

      <div
        v-for="slug in activeInterests"
        :key="slug"
        class="rounded-2xl border border-surface-200 dark:border-surface-700 bg-white/60 dark:bg-surface-800/30 overflow-hidden"
      >
        <div class="px-3.5 py-2.5 flex items-center gap-2 border-b border-surface-200 dark:border-surface-700/70">
          <span class="material-symbols-rounded text-primary-500 text-[18px]">{{ iconFor(slug) }}</span>
          <span class="font-semibold text-sm text-surface-800 dark:text-surface-100">{{ label(slug) }}</span>
          <span class="text-xs text-surface-500">
            {{ counts(slug).active }} / {{ counts(slug).total || 0 }}
          </span>
          <span class="flex-1" />
          <button
            v-if="counts(slug).active < counts(slug).total"
            type="button"
            class="text-[11px] px-2.5 py-1 rounded-full font-semibold bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300 hover:bg-primary-100 dark:hover:bg-primary-900/50 transition-colors"
            @click="enableAllForInterest(slug)"
          >
            {{ t('newsReader.enableAll') }}
          </button>
        </div>

        <ul v-if="sitesForInterest(slug).length" class="divide-y divide-surface-200/70 dark:divide-surface-700/60">
          <li
            v-for="site in sitesForInterest(slug)"
            :key="site.key"
            class="px-3.5 py-2.5 flex items-center gap-3 hover:bg-surface-50/70 dark:hover:bg-surface-800/40 transition-colors"
          >
            <span
              class="material-symbols-rounded text-[16px] shrink-0"
              :class="site.subscribed ? 'text-primary-500' : 'text-surface-300 dark:text-surface-600'"
            >
              {{ site.subscribed ? 'check_circle' : 'radio_button_unchecked' }}
            </span>
            <div class="flex-1 min-w-0 flex items-center gap-2">
              <span
                class="text-sm font-medium truncate"
                :class="site.subscribed ? 'text-surface-900 dark:text-surface-50' : 'text-surface-600 dark:text-surface-300'"
              >{{ site.name }}</span>
              <span
                v-if="site.region"
                class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider shrink-0"
                :class="regionTone(site.region)"
              >{{ site.region }}</span>
              <span
                v-if="site.isCustom"
                class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider shrink-0 bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300"
              >{{ t('newsReader.customBadge') }}</span>
            </div>
            <label
              class="relative inline-flex items-center cursor-pointer shrink-0"
              :class="isSiteBusy(site.key) ? 'opacity-60 cursor-progress' : ''"
              :title="site.subscribed ? t('newsReader.enabled') : t('newsReader.disabled')"
            >
              <input
                type="checkbox"
                :checked="site.subscribed"
                :disabled="isSiteBusy(site.key)"
                class="sr-only peer"
                @change="toggleSite(slug, site)"
              />
              <div
                class="w-9 h-5 bg-surface-300 dark:bg-surface-600 peer-focus:ring-2 peer-focus:ring-primary-500 rounded-full peer peer-checked:after:translate-x-4 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary-500 transition-colors"
              />
            </label>
          </li>
        </ul>
        <div v-else class="px-3.5 py-3 text-xs text-surface-400 italic">
          {{ t('newsReader.noFeedsForTopic') }}
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.interest-pill:focus-visible {
  outline: 2px solid currentColor;
  outline-offset: 2px;
}
.confirm-enter-active, .confirm-leave-active {
  transition: opacity 180ms ease, transform 180ms ease;
}
.confirm-enter-from, .confirm-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
@media (prefers-reduced-motion: reduce) {
  .confirm-enter-active, .confirm-leave-active { transition: none; }
}
</style>
