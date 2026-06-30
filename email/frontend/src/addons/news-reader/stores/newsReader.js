import { defineStore } from 'pinia'
import { ref, shallowRef, computed, markRaw } from 'vue'
import * as newsApi from '@/addons/news-reader/services/newsApi'
import { DEFAULT_INTERESTS, INTEREST_CATEGORIES } from '@/addons/news-reader/data/categories'

const INTERESTS_LS_KEY = 'news_reader.interests'
const BOOKMARKS_LS_KEY = 'news_reader.bookmarks'

function loadStoredInterests() {
  if (typeof localStorage === 'undefined') return [...DEFAULT_INTERESTS]
  try {
    const raw = localStorage.getItem(INTERESTS_LS_KEY)
    if (!raw) return [...DEFAULT_INTERESTS]
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return [...DEFAULT_INTERESTS]
    const valid = new Set(INTEREST_CATEGORIES.map((c) => c.slug))
    return parsed.filter((s) => typeof s === 'string' && valid.has(s))
  } catch (_) {
    return [...DEFAULT_INTERESTS]
  }
}

function loadStoredBookmarks() {
  if (typeof localStorage === 'undefined') return new Set()
  try {
    const raw = localStorage.getItem(BOOKMARKS_LS_KEY)
    if (!raw) return new Set()
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return new Set()
    return new Set(parsed.map((n) => Number(n)).filter((n) => Number.isFinite(n)))
  } catch (_) {
    return new Set()
  }
}

export const useNewsReaderStore = defineStore('newsReader', () => {
  const subscriptions = ref([])
  const itemsMap = shallowRef(new Map())
  const itemOrder = ref([])
  const listCursor = ref(null)
  const listHasMore = ref(true)
  const loading = ref(false)
  const readerOpen = ref(false)
  const readerArticle = ref(null)
  const tickerExpanded = ref(
    typeof localStorage !== 'undefined' ? localStorage.getItem('news_reader.ticker_open') !== '0' : true
  )
  const tickerUnreadOnly = ref(false)
  const currentCategory = ref('')
  const currentFeedId = ref(null)
  const viewBookmarksOnly = ref(false)
  const viewVideosOnly = ref(false)
  // Free-text search query that gets pushed to the backend on every
  // /news/items request. Empty string == no search filter. The header
  // input debounces user typing into setSearch() so the server only
  // sees stable terms.
  const searchQuery = ref('')
  const userInterests = ref(loadStoredInterests())
  const catalog = ref({ HU: [], EN: [], US: [], VIDEO: [] })
  const catalogLoaded = ref(false)
  const busyInterests = ref(new Set())
  const bookmarks = ref(loadStoredBookmarks())

  const hasBookmarks = computed(() => bookmarks.value.size > 0)

  /**
   * True when the user is subscribed to at least one video feed (YouTube
   * channel/playlist). Drives visibility of the 'Videos' filter chip.
   */
  const hasVideos = computed(() =>
    subscriptions.value.some((s) => (s.feed_kind || '') === 'video')
  )

  const unreadTotal = computed(() =>
    subscriptions.value.reduce((n, s) => n + (Number(s.unread_count) || 0), 0)
  )

  const availableCategories = computed(() => {
    const set = new Set()
    for (const s of subscriptions.value) {
      const c = (s.category || '').trim()
      if (c) set.add(c)
    }
    return Array.from(set).sort((a, b) => a.localeCompare(b))
  })

  const subscribedCategoriesSet = computed(() => {
    const s = new Set()
    for (const sub of subscriptions.value) {
      const c = (sub.category || '').trim()
      if (c) s.add(c)
    }
    return s
  })

  const interestChips = computed(() =>
    userInterests.value.map((slug) => ({
      slug,
      hasFeeds: subscribedCategoriesSet.value.has(slug),
    }))
  )

  /**
   * Filter chips for the reader: ONLY interests that actually have at least
   * one subscribed feed. Empty interests are hidden from the reader filter
   * bar to avoid dead chips that show "no headlines".
   *
   * The 'videos' slug is intentionally excluded — it has its own dedicated
   * kind-based chip (in front of the interest list) which is more accurate
   * than the category-based one (it also catches manually-added YouTube
   * channels the user didn't tag).
   */
  const readerFilterChips = computed(() => {
    const subbed = subscribedCategoriesSet.value
    const interestSet = new Set(userInterests.value)
    const skip = new Set(['videos'])
    const chips = []
    for (const slug of userInterests.value) {
      if (skip.has(slug)) continue
      if (subbed.has(slug)) chips.push(slug)
    }
    for (const slug of subbed) {
      if (skip.has(slug)) continue
      if (!interestSet.has(slug) && !chips.includes(slug)) chips.push(slug)
    }
    return chips
  })

  const allCurated = computed(() => [
    ...(catalog.value.HU || []),
    ...(catalog.value.EN || []),
    ...(catalog.value.US || []),
    ...(catalog.value.VIDEO || []),
  ])

  /**
   * Loose URL key for comparing curated catalog URLs to canonical
   * subscription URLs. Mirrors the obvious normalization the backend's
   * UrlNormalizer performs (lowercase host, strip trailing slash, drop
   * protocol differences) without pulling in the full PHP logic.
   */
  function urlKey(u) {
    if (!u) return ''
    let s = String(u).trim().toLowerCase()
    s = s.replace(/^https?:\/\//, '')
    s = s.replace(/^www\./, '')
    s = s.replace(/\/+$/, '')
    return s
  }

  const subscribedUrlSet = computed(() => {
    const s = new Set()
    for (const sub of subscriptions.value) {
      if (sub.canonical_feed_url) s.add(urlKey(sub.canonical_feed_url))
      if (sub.feed_url) s.add(urlKey(sub.feed_url))
    }
    return s
  })

  const subscriptionByUrlKey = computed(() => {
    const m = new Map()
    for (const sub of subscriptions.value) {
      if (sub.canonical_feed_url) m.set(urlKey(sub.canonical_feed_url), sub)
      if (sub.feed_url) m.set(urlKey(sub.feed_url), sub)
    }
    return m
  })

  function curatedForCategory(slug) {
    return allCurated.value.filter((c) => (c.default_category || '') === slug)
  }

  function subscribedForCategory(slug) {
    return subscriptions.value.filter((s) => (s.category || '') === slug)
  }

  async function loadCatalog(force = false) {
    if (catalogLoaded.value && !force) return
    try {
      const c = await newsApi.fetchNewsCatalog()
      catalog.value = c || { HU: [], EN: [], US: [], VIDEO: [] }
      catalogLoaded.value = true
    } catch (_) {
      catalog.value = { HU: [], EN: [], US: [], VIDEO: [] }
    }
  }

  function setInterestBusy(slug, busy) {
    const next = new Set(busyInterests.value)
    if (busy) next.add(slug)
    else next.delete(slug)
    busyInterests.value = next
  }

  /**
   * Enable an interest and auto-subscribe to all curated feeds in that
   * category that the user is not already subscribed to.
   * Returns the count of newly-added feeds.
   */
  async function enableInterest(slug) {
    if (!userInterests.value.includes(slug)) {
      userInterests.value = [...userInterests.value, slug]
      persistInterests()
    }
    setInterestBusy(slug, true)
    let added = 0
    try {
      await loadCatalog()
      const toAdd = curatedForCategory(slug).filter(
        (c) => !subscribedUrlSet.value.has(urlKey(c.feed_url))
      )
      for (const c of toAdd) {
        try {
          await newsApi.postNewsSubscription(c.feed_url, c.default_category || null)
          added++
        } catch (_) {}
      }
      if (added > 0) await loadFeeds()
    } finally {
      setInterestBusy(slug, false)
    }
    return added
  }

  /**
   * Disable an interest. If `removeFeeds` is true, also unsubscribe from all
   * feeds tagged with this category. Returns the count of removed feeds.
   */
  async function disableInterest(slug, { removeFeeds = false } = {}) {
    userInterests.value = userInterests.value.filter((s) => s !== slug)
    persistInterests()
    if (!removeFeeds) return 0
    setInterestBusy(slug, true)
    let removed = 0
    try {
      const subs = subscribedForCategory(slug)
      for (const s of subs) {
        try {
          await newsApi.deleteNewsSubscription(s.id)
          removed++
        } catch (_) {}
      }
      if (removed > 0) await loadFeeds()
    } finally {
      setInterestBusy(slug, false)
    }
    return removed
  }

  async function subscribeToFeed(feedUrl, category = null) {
    const res = await newsApi.postNewsSubscription(feedUrl, category)
    await loadFeeds()
    return res
  }

  async function unsubscribeFromFeed(id) {
    await newsApi.deleteNewsSubscription(id)
    subscriptions.value = subscriptions.value.filter((s) => s.id !== id)
  }

  function persistInterests() {
    if (typeof localStorage === 'undefined') return
    try {
      localStorage.setItem(INTERESTS_LS_KEY, JSON.stringify(userInterests.value))
    } catch (_) {}
  }

  function persistBookmarks() {
    if (typeof localStorage === 'undefined') return
    try {
      localStorage.setItem(
        BOOKMARKS_LS_KEY,
        JSON.stringify(Array.from(bookmarks.value))
      )
    } catch (_) {}
  }

  function isBookmarked(id) {
    return bookmarks.value.has(Number(id))
  }

  function toggleBookmark(id) {
    const n = Number(id)
    if (!Number.isFinite(n)) return false
    const next = new Set(bookmarks.value)
    let nowOn
    if (next.has(n)) {
      next.delete(n)
      nowOn = false
    } else {
      next.add(n)
      nowOn = true
    }
    bookmarks.value = next
    persistBookmarks()
    // If we just removed a bookmark while the bookmarks filter is active,
    // drop it from the visible list. If it was the last one, fall back to
    // the regular feed so the user isn't stranded on an empty view.
    if (!nowOn && viewBookmarksOnly.value) {
      if (next.size === 0) {
        setBookmarksFilter(false)
      } else {
        itemOrder.value = itemOrder.value.filter((itemId) => itemId !== n)
      }
    }
    return nowOn
  }

  function setUserInterests(slugs) {
    const valid = new Set(INTEREST_CATEGORIES.map((c) => c.slug))
    userInterests.value = (Array.isArray(slugs) ? slugs : [])
      .filter((s) => typeof s === 'string' && valid.has(s))
    persistInterests()
  }

  function toggleInterest(slug) {
    const idx = userInterests.value.indexOf(slug)
    if (idx === -1) userInterests.value = [...userInterests.value, slug]
    else userInterests.value = userInterests.value.filter((s) => s !== slug)
    persistInterests()
  }

  function persistTickerOpen() {
    try {
      localStorage.setItem('news_reader.ticker_open', tickerExpanded.value ? '1' : '0')
    } catch (_) {}
  }

  function setTickerExpanded(v) {
    tickerExpanded.value = !!v
    persistTickerOpen()
  }

  function mergeItems(rows) {
    const m = new Map(itemsMap.value)
    for (const row of rows) {
      const copy = { ...row }
      if (copy.summary) copy.summary = markRaw(String(copy.summary))
      if (copy.content_html) copy.content_html = markRaw(String(copy.content_html))
      m.set(copy.id, copy)
    }
    itemsMap.value = m
  }

  async function loadFeeds() {
    subscriptions.value = await newsApi.fetchNewsFeeds()
  }

  async function loadItems({ append = false } = {}) {
    if (loading.value) return
    loading.value = true
    try {
      // 20 per request: small enough that each "Load more" click in
      // the dashboard adds a digestible chunk (the user explicitly
      // asked for 20, not 50, after observing the previous 50-row
      // bursts felt overwhelming). The first call still uses 20 so
      // the dashboard renders fast and the user can scroll into more
      // content on demand.
      const params = { limit: 20, unread_only: tickerUnreadOnly.value ? 1 : 0 }
      if (currentCategory.value) params.category = currentCategory.value
      if (currentFeedId.value) params.feed_id = currentFeedId.value
      if (viewVideosOnly.value) params.kind = 'video'
      // When a search term is active we widen the page size so the user
      // gets a meaningful match set in one round-trip instead of hitting
      // the cursor button repeatedly. The 20-row default is fine for the
      // usual chronological feed, but search across all subscriptions
      // benefits from a deeper first page.
      const q = (searchQuery.value || '').trim()
      if (q) {
        params.q = q
        params.limit = 100
      }
      if (append && listCursor.value) params.cursor = listCursor.value
      if (!append) {
        listCursor.value = null
        listHasMore.value = true
        itemOrder.value = []
        itemsMap.value = new Map()
      }
      const data = await newsApi.fetchNewsItems(params)
      const rows = data.items || []
      mergeItems(rows)
      const ids = append ? [...itemOrder.value] : []
      for (const row of rows) {
        if (!ids.includes(row.id)) ids.push(row.id)
      }
      itemOrder.value = ids
      listCursor.value = data.next_cursor || null
      listHasMore.value = !!data.has_more
    } finally {
      loading.value = false
    }
  }

  async function setCategory(cat) {
    const next = (cat || '').trim()
    if (
      currentCategory.value === next
      && !currentFeedId.value
      && !viewBookmarksOnly.value
      && !viewVideosOnly.value
    ) return
    viewBookmarksOnly.value = false
    viewVideosOnly.value = false
    currentFeedId.value = null
    currentCategory.value = next
    await loadItems({ append: false })
  }

  /**
   * Filter the reader feed to a single source (publisher / channel /
   * Instagram profile). Triggered by clicking the source badge on a tile
   * or sidebar row. Mutually exclusive with the kind/bookmark filters —
   * setting a feed clears the category but keeps it conceptually as a
   * sub-filter (we still pass `feed_id` AND `category` if set).
   */
  async function setFeedFilter(feedId) {
    const next = feedId ? Number(feedId) : null
    if (currentFeedId.value === next) {
      if (next === null) return
      // Toggling the same feed off
      currentFeedId.value = null
      await loadItems({ append: false })
      return
    }
    viewBookmarksOnly.value = false
    viewVideosOnly.value = false
    currentCategory.value = ''
    currentFeedId.value = next
    await loadItems({ append: false })
  }

  function clearFeedFilter() {
    return setFeedFilter(null)
  }

  /**
   * Switch between the regular feed view and the bookmarks-only view.
   * Bookmarks are stored client-side (localStorage) and are decoupled
   * from category/subscription state — turning this on clears the
   * current category and fetches every bookmarked item by ID so the
   * user sees ALL their saved articles, not just the ones currently
   * paginated.
   */
  async function setBookmarksFilter(on) {
    const next = !!on
    if (viewBookmarksOnly.value === next) return
    viewBookmarksOnly.value = next
    if (next) {
      currentCategory.value = ''
      currentFeedId.value = null
      viewVideosOnly.value = false
      await loadBookmarkedItems()
    } else {
      await loadItems({ append: false })
    }
  }

  /**
   * Switch between mixed feed and the videos-only view (any item from a
   * feed_kind='video' source). Backend filtering happens via the same
   * /news/items endpoint with `kind=video`.
   */
  async function setVideosFilter(on) {
    const next = !!on
    if (viewVideosOnly.value === next) return
    viewVideosOnly.value = next
    if (next) {
      currentCategory.value = ''
      currentFeedId.value = null
      viewBookmarksOnly.value = false
    }
    await loadItems({ append: false })
  }

  /**
   * Update the active free-text search query and reload the feed from
   * the backend. Called by FlipboardReader's debounced input handler so
   * each keystroke doesn't fire a request. Passing the same value is a
   * no-op, and clearing the query (`''`) reverts to the regular feed.
   */
  async function setSearch(q) {
    const next = (q == null ? '' : String(q)).trim().slice(0, 200)
    if (searchQuery.value === next) return
    searchQuery.value = next
    // Bookmarks-only is a client-side IDs query and bypasses the
    // /news/items search path, so re-loading it would just discard the
    // search. Switching back to the regular feed when search starts is
    // the least surprising behaviour.
    if (viewBookmarksOnly.value && next !== '') {
      viewBookmarksOnly.value = false
    }
    // A previous loadItems() may still be in flight (loading.value ==
    // true); its early-return guard would silently drop our reload and
    // leave the UI showing the stale result set. Wait it out — the
    // existing call settles in <1s so this is effectively a yield.
    while (loading.value) {
      await new Promise((resolve) => setTimeout(resolve, 50))
    }
    await loadItems({ append: false })
  }

  async function loadBookmarkedItems() {
    if (loading.value) return
    loading.value = true
    try {
      listCursor.value = null
      listHasMore.value = false
      const ids = Array.from(bookmarks.value)
      if (!ids.length) {
        itemOrder.value = []
        itemsMap.value = new Map()
        return
      }
      const rows = await newsApi.fetchNewsItemsByIds(ids)
      itemsMap.value = new Map()
      mergeItems(rows)
      itemOrder.value = rows.map((r) => r.id)
    } finally {
      loading.value = false
    }
  }

  async function bootstrap() {
    await loadFeeds()
    await loadItems({ append: false })
    try {
      await newsApi.refreshNewsFeeds()
      await loadFeeds()
      await loadItems({ append: false })
    } catch (_) {}
  }

  function openReader(article) {
    readerArticle.value = article
    readerOpen.value = true
  }

  function closeReader() {
    readerOpen.value = false
    readerArticle.value = null
  }

  async function openArticleFromTicker(article) {
    try {
      await newsApi.markNewsItemRead(article.id)
      article.is_read = true
    } catch (_) {}
    openReader(article)
  }

  function openReaderBrowse() {
    readerArticle.value = null
    readerOpen.value = true
  }

  function backFromArticle() {
    readerArticle.value = null
  }

  /**
   * Return the subscription metadata for the currently-filtered source so
   * the toolbar can show its title / favicon. Cheap O(N) scan over the
   * subscription list (always small — a few dozen at most).
   */
  const currentFeedMeta = computed(() => {
    if (!currentFeedId.value) return null
    return (
      subscriptions.value.find((s) => Number(s.feed_id) === Number(currentFeedId.value))
      || null
    )
  })

  /**
   * Navigate to the article at `currentArticleId`'s neighbour (delta = -1
   * for previous, +1 for next). When stepping past the end of the loaded
   * list we transparently call `loadItems({ append: true })` so the user
   * doesn't get stuck — same behaviour as the visible "Load more" button.
   * Also marks the destination as read, mirroring the click-to-open path.
   * Returns the new article id, or null if there's no neighbour.
   */
  async function navigateArticle(currentArticleId, delta) {
    const ids = itemOrder.value
    if (!ids.length) return null
    const idx = ids.indexOf(Number(currentArticleId))
    if (idx === -1) return null
    const nextIdx = idx + delta
    if (nextIdx < 0) return null
    if (nextIdx >= ids.length) {
      if (!listHasMore.value || loading.value) return null
      try {
        await loadItems({ append: true })
      } catch (_) { return null }
      if (nextIdx >= itemOrder.value.length) return null
    }
    const nextId = itemOrder.value[nextIdx]
    const next = itemsMap.value.get(nextId)
    if (!next) return null
    try {
      await newsApi.markNewsItemRead(nextId)
      next.is_read = true
    } catch (_) {}
    readerArticle.value = next
    return nextId
  }

  return {
    subscriptions,
    itemsMap,
    itemOrder,
    listCursor,
    listHasMore,
    loading,
    readerOpen,
    readerArticle,
    tickerExpanded,
    tickerUnreadOnly,
    currentCategory,
    currentFeedId,
    currentFeedMeta,
    viewBookmarksOnly,
    viewVideosOnly,
    searchQuery,
    userInterests,
    catalog,
    busyInterests,
    bookmarks,
    hasBookmarks,
    hasVideos,
    unreadTotal,
    availableCategories,
    subscribedCategoriesSet,
    subscribedUrlSet,
    subscriptionByUrlKey,
    interestChips,
    readerFilterChips,
    allCurated,
    curatedForCategory,
    subscribedForCategory,
    urlKey,
    isBookmarked,
    toggleBookmark,
    setTickerExpanded,
    setCategory,
    setBookmarksFilter,
    setVideosFilter,
    setSearch,
    setFeedFilter,
    clearFeedFilter,
    navigateArticle,
    loadBookmarkedItems,
    setUserInterests,
    toggleInterest,
    enableInterest,
    disableInterest,
    loadFeeds,
    loadCatalog,
    loadItems,
    bootstrap,
    openReader,
    closeReader,
    openArticleFromTicker,
    backFromArticle,
    openReaderBrowse,
    subscribeToFeed,
    unsubscribeFromFeed,
  }
})
