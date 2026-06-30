import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import api from '@/services/api'
import { useEmailSearchStore } from '@/stores/emailSearch'
import { useMailboxStore } from '@/stores/mailbox'
import { hasSpecialSearch } from '@/services/specialSearchRegistry'

/**
 * Smart Views store
 * ──────────────────
 * A Smart View is a saved search. The store handles:
 *   - listing (with the four hard-coded built-ins always first)
 *   - CRUD against /smart-views
 *   - reorder (drag-and-drop on the saved list)
 *   - run(view): the single entry point the UI calls to "switch to a view"
 *
 * Execution model
 * ───────────────
 * Every view has a canonical `query` string (`is:unread`, `has:attachment`,
 * `mentions:me`, etc.). To run it we push the string into useEmailSearchStore
 * and call its existing handleSearch() — which already knows how to talk to
 * /mailbox/search. This means Smart Views inherit every feature of the
 * existing search path (debounce, all-folder vs current-folder scope,
 * SEARCH_RESULTS virtual folder, etc.) for free.
 *
 * The active built-in is tracked locally (id like `builtin:unread`); the
 * active saved view is tracked by numeric DB id. Both states clear when the
 * user changes folder or clears search elsewhere.
 *
 * Built-ins
 * ─────────
 * Four hard-coded views that ship with every account. They are NOT in the DB
 * (so they can't be renamed, deleted, or get out of sync). They sit visually
 * above saved views in the list.
 */

export const BUILTIN_SMART_VIEWS = [
  {
    id: 'builtin:unread',
    builtin: true,
    name: 'Unread',
    icon: 'mark_email_unread',
    color: 'primary',
    query: 'is:unread',
    scope: 'all',
  },
  {
    id: 'builtin:attachments',
    builtin: true,
    name: 'With Attachments',
    icon: 'attach_file',
    color: 'violet',
    query: 'has:attachment',
    scope: 'all',
  },
  {
    id: 'builtin:starred',
    builtin: true,
    name: 'Important',
    icon: 'star',
    color: 'amber',
    query: 'is:starred',
    scope: 'all',
  },
  {
    // Backed by the pinned_emails DB table. Backend post-filter lives in
    // MailboxController::search (see the `is:pinned` block). Icon + color
    // match the existing pin glyph used in EmailList / EmailView / BulkActions.
    id: 'builtin:pinned',
    builtin: true,
    name: 'Pinned',
    icon: 'push_pin',
    color: 'amber',
    query: 'is:pinned',
    scope: 'all',
  },
]

export const useSmartViewsStore = defineStore('smartViews', () => {
  const savedViews = ref([])      // backend rows
  const loading = ref(false)
  const hasFetchedOnce = ref(false)
  const activeId = ref(null)       // string for builtin:*, number for saved

  /** Built-ins always first, then user-saved (already server-ordered). */
  const allViews = computed(() => [...BUILTIN_SMART_VIEWS, ...savedViews.value])

  const activeView = computed(() => allViews.value.find(v => String(v.id) === String(activeId.value)) ?? null)

  /**
   * True if the view references at least one special operator that has a
   * registered handler (mentions, snoozed). Pure UI signal — informs the
   * "this view's data is provided by a special handler" badge in the list.
   */
  function isSpecial(view) {
    if (!view?.query) return false
    return /\b(snoozed):/i.test(view.query) && hasSpecialSearch(view.query.match(/\b(snoozed):/i)?.[1])
  }

  async function fetch({ force = false } = {}) {
    if (loading.value) return
    if (hasFetchedOnce.value && !force) return
    loading.value = true
    try {
      const res = await api.get('/smart-views')
      if (res.data?.success) {
        savedViews.value = res.data.data.smart_views || []
        hasFetchedOnce.value = true
      }
    } catch (e) {
      console.error('[smartViews] fetch failed', e)
    } finally {
      loading.value = false
    }
  }

  async function create(payload) {
    try {
      const res = await api.post('/smart-views', payload)
      if (res.data?.success) {
        savedViews.value.push(res.data.data.smart_view)
        return res.data.data.smart_view
      }
      return null
    } catch (e) {
      console.error('[smartViews] create failed', e?.response?.data || e.message)
      throw e
    }
  }

  async function update(id, payload) {
    try {
      const res = await api.put(`/smart-views/${id}`, payload)
      if (res.data?.success) {
        const updated = res.data.data.smart_view
        const idx = savedViews.value.findIndex(v => v.id === id)
        if (idx >= 0) savedViews.value[idx] = updated
        return updated
      }
      return null
    } catch (e) {
      console.error('[smartViews] update failed', e?.response?.data || e.message)
      throw e
    }
  }

  async function remove(id) {
    try {
      const res = await api.delete(`/smart-views/${id}`)
      if (res.data?.success) {
        savedViews.value = savedViews.value.filter(v => v.id !== id)
        if (activeId.value === id) activeId.value = null
        return true
      }
      return false
    } catch (e) {
      console.error('[smartViews] delete failed', e?.response?.data || e.message)
      return false
    }
  }

  async function reorder(orderedIds) {
    // Optimistic: reorder locally first, then send.
    const prev = savedViews.value.slice()
    const byId = new Map(prev.map(v => [v.id, v]))
    savedViews.value = orderedIds.map(id => byId.get(id)).filter(Boolean)
    try {
      const res = await api.patch('/smart-views/reorder', { order: orderedIds })
      if (res.data?.success) {
        savedViews.value = res.data.data.smart_views || savedViews.value
        return true
      }
      savedViews.value = prev
      return false
    } catch (e) {
      console.error('[smartViews] reorder failed', e?.response?.data || e.message)
      savedViews.value = prev
      return false
    }
  }

  /**
   * Run a Smart View. Pushes the query into the search store and runs it
   * through the same code path as the search bar (so scope, debounce,
   * SEARCH_RESULTS virtual folder all behave identically).
   *
   * Also mirrors the view's query into the structured `visualFilters`
   * object so the Filter Emails popup's toggles (Unread, Has attachment,
   * Select starred, …) reflect what the Smart View applied. Without this,
   * the popup looked empty even when a view was active.
   *
   * For now we always run all-folder (scope='all'); per-folder scope is a
   * future improvement — the column exists in the DB but the runner doesn't
   * yet read it.
   */
  function run(view) {
    if (!view?.query) return
    const search = useEmailSearchStore()
    activeId.value = view.id
    search.searchQuery = view.query
    search.filterScope = 'all'
    search.visualFilters = search.parseQueryToFilters(view.query)
    search.handleSearch()
  }

  /** Clear active highlight when the user navigates away from search. */
  function clearActive() {
    activeId.value = null
  }

  /**
   * Auto-clear when the mailbox folder changes back to a non-search folder
   * OR when the user mutates searchQuery so it no longer matches the active
   * view (typing, popup-driven filter changes, ?search= deep links, etc.).
   * Returns a combined unsubscribe function — call it from a teardown.
   */
  function bindToMailbox() {
    const mailbox = useMailboxStore()
    const search = useEmailSearchStore()

    const offMailbox = mailbox.$subscribe((_mutation, state) => {
      if (state.currentFolder && state.currentFolder !== 'SEARCH_RESULTS' && activeId.value) {
        activeId.value = null
      }
    })

    const offQuery = watch(() => search.searchQuery, (newQ) => {
      if (!activeId.value) return
      const view = allViews.value.find(v => String(v.id) === String(activeId.value))
      if (view && view.query !== newQ) activeId.value = null
    })

    return () => { offMailbox(); offQuery() }
  }

  /** Build a default Smart View payload from the current search/filter state. */
  function draftFromCurrentSearch() {
    const search = useEmailSearchStore()
    const query = search.searchQuery?.trim() || search.buildSearchQuery()
    return {
      name: '',
      icon: 'filter_alt',
      color: 'primary',
      query,
      filters_json: JSON.parse(JSON.stringify(search.visualFilters || {})),
      scope: search.filterScope === 'current' ? 'folder' : 'all',
    }
  }

  function hydrateFromBootstrap(views) {
    if (!Array.isArray(views)) return
    savedViews.value = views
    hasFetchedOnce.value = true
  }

  return {
    // state
    savedViews,
    loading,
    hasFetchedOnce,
    activeId,
    // computed
    allViews,
    activeView,
    // helpers
    isSpecial,
    draftFromCurrentSearch,
    // actions
    fetch,
    create,
    update,
    remove,
    reorder,
    run,
    clearActive,
    bindToMailbox,
    hydrateFromBootstrap,
  }
})
