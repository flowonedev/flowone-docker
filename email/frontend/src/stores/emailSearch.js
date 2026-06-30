import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'
import { useLabelsStore } from '@/stores/labels'

/**
 * Email search store — lifted out of EmailList.vue so the search bar can live
 * in the AppHeader's centre slot (desktop) while EmailList reads the results
 * the search produced.
 *
 * Action surface mirrors what EmailList used to do locally:
 *   - searchQuery + visualFilters + filterScope: form state
 *   - showVisualFilter: filter panel open/close
 *   - handleSearch(): immediate (Enter / explicit submit)
 *   - debouncedHandleSearch(): 300ms debounce — used by all typing paths and
 *     the route-sync watcher so we never hammer IMAP/the API on every keystroke.
 *   - applyVisualFilter(): closes the filter panel and runs the composed query.
 *   - clearSearch() / clearVisualFilters(): reset + refetch the prior folder.
 *
 * The store calls the existing mailbox actions (`search`, `searchAllFolders`,
 * `fetchMessages`) — no new backend code is needed.
 */
export const useEmailSearchStore = defineStore('emailSearch', () => {
  const searchQuery = ref('')

  const visualFilters = ref({
    from: '',
    to: '',
    subject: '',
    hasAttachment: false,
    isUnread: false,
    isStarred: false,
    isPinned: false,
    afterDate: '',
    beforeDate: '',
    labels: [], // selected label IDs
  })

  // 'all' = search across every folder (SEARCH_RESULTS virtual folder),
  // 'current' = restrict to mailbox.currentFolder.
  const filterScope = ref('all')

  // Folder to return to when clearing search from SEARCH_RESULTS.
  const preSearchFolder = ref(null)

  // Visual filter panel open state.
  const showVisualFilter = ref(false)

  function emptyFilters() {
    return {
      from: '',
      to: '',
      subject: '',
      hasAttachment: false,
      isUnread: false,
      isStarred: false,
      isPinned: false,
      afterDate: '',
      beforeDate: '',
      labels: [],
    }
  }

  const activeFilterCount = computed(() => {
    let count = 0
    if (searchQuery.value.trim()) count++
    const f = visualFilters.value
    if (f.from) count++
    if (f.to) count++
    if (f.subject) count++
    if (f.hasAttachment) count++
    if (f.isUnread) count++
    if (f.isStarred) count++
    if (f.isPinned) count++
    if (f.afterDate) count++
    if (f.beforeDate) count++
    if (f.labels.length > 0) count++
    return count
  })

  function openFilterPanel() {
    showVisualFilter.value = true
    // Only auto-prime visualFilters from the active searchQuery when the
    // panel has no filters set yet — otherwise we'd clobber whatever the
    // user (or a Smart View) already put there. parseQueryToFilters
    // understands operator syntax (is:unread, has:attachment, …) so the
    // toggles light up correctly instead of dumping the raw operator
    // string into the From field.
    const q = searchQuery.value.trim()
    if (q && !hasAnyVisualFilters()) {
      Object.assign(visualFilters.value, parseQueryToFilters(q))
    }
  }

  function hasAnyVisualFilters() {
    const f = visualFilters.value
    return !!(
      f.from || f.to || f.subject ||
      f.hasAttachment || f.isUnread || f.isStarred || f.isPinned ||
      f.afterDate || f.beforeDate ||
      (f.labels && f.labels.length > 0)
    )
  }

  /**
   * Parse a canonical search-syntax string into a structured visualFilters
   * object. This is the inverse of buildSearchQuery — it lets the Filter
   * Emails popup reflect a query that was set by something other than the
   * popup itself (Smart Views, ?search= deep links, raw typing).
   *
   * Supported operators (must match buildSearchQuery output):
   *   from:val, to:val, subject:val, after:val, before:val
   *   has:attachment, is:unread, is:starred, label:name
   * Quoted values ("foo bar") are unquoted. Any free text left after the
   * operators are stripped becomes the From field (so a plain keyword
   * search like "invoice" pre-fills the sender field by default).
   * Unknown operators are left as free text so the user sees them.
   */
  function parseQueryToFilters(query) {
    const filters = emptyFilters()
    if (!query) return filters

    const labelsStore = useLabelsStore()
    const tokenRe = /(\w+):(?:"([^"]*)"|(\S+))/g
    let remaining = query
    let m
    while ((m = tokenRe.exec(query)) !== null) {
      const key = m[1].toLowerCase()
      const value = (m[2] ?? m[3] ?? '').trim()
      let consumed = true
      switch (key) {
        case 'from':    filters.from = value; break
        case 'to':      filters.to = value; break
        case 'subject': filters.subject = value; break
        case 'after':   filters.afterDate = value; break
        case 'before':  filters.beforeDate = value; break
        case 'has':
          if (value.toLowerCase() === 'attachment') filters.hasAttachment = true
          else consumed = false
          break
        case 'is':
          if (value.toLowerCase() === 'unread') filters.isUnread = true
          else if (value.toLowerCase() === 'starred') filters.isStarred = true
          else if (value.toLowerCase() === 'pinned') filters.isPinned = true
          else consumed = false
          break
        case 'label': {
          const label = labelsStore.labels.find(l => l.name === value)
          if (label && !filters.labels.includes(label.id)) filters.labels.push(label.id)
          break
        }
        default:
          consumed = false
      }
      if (consumed) remaining = remaining.replace(m[0], '')
    }
    const leftover = remaining.replace(/\s+/g, ' ').trim()
    if (leftover && !filters.from) filters.from = leftover
    return filters
  }

  function closeFilterPanel() {
    showVisualFilter.value = false
  }

  function toggleVisualFilter() {
    if (showVisualFilter.value) closeFilterPanel()
    else openFilterPanel()
  }

  function toggleFilterLabel(labelId) {
    const idx = visualFilters.value.labels.indexOf(labelId)
    if (idx === -1) visualFilters.value.labels.push(labelId)
    else visualFilters.value.labels.splice(idx, 1)
  }

  /**
   * Compose the canonical search-syntax string from the structured filters.
   * Mirrors the original EmailList implementation: label names with spaces
   * get quoted.
   */
  function buildSearchQuery() {
    const parts = []
    const f = visualFilters.value
    const labelsStore = useLabelsStore()

    if (f.from) parts.push(`from:${f.from}`)
    if (f.to) parts.push(`to:${f.to}`)
    if (f.subject) parts.push(`subject:${f.subject}`)
    if (f.hasAttachment) parts.push('has:attachment')
    if (f.isUnread) parts.push('is:unread')
    if (f.isStarred) parts.push('is:starred')
    if (f.isPinned) parts.push('is:pinned')
    if (f.afterDate) parts.push(`after:${f.afterDate}`)
    if (f.beforeDate) parts.push(`before:${f.beforeDate}`)
    f.labels.forEach(labelId => {
      const label = labelsStore.labels.find(l => l.id === labelId)
      if (label) {
        const name = label.name.includes(' ') ? `"${label.name}"` : label.name
        parts.push(`label:${name}`)
      }
    })

    return parts.join(' ')
  }

  /**
   * Run the current `searchQuery` immediately. Called on Enter / explicit
   * submit. Typing paths should call `debouncedHandleSearch` instead.
   */
  function handleSearch() {
    const mailbox = useMailboxStore()
    closeFilterPanel()
    const trimmed = searchQuery.value.trim()
    if (trimmed) {
      if (mailbox.currentFolder && mailbox.currentFolder !== 'SEARCH_RESULTS') {
        preSearchFolder.value = mailbox.currentFolder
      }
      filterScope.value = 'all'
      mailbox.searchAllFolders(trimmed)
    } else {
      if (mailbox.currentFolder === 'SEARCH_RESULTS') {
        mailbox.fetchMessages(preSearchFolder.value || 'INBOX')
      } else {
        mailbox.fetchMessages()
      }
    }
  }

  // 300ms trailing debounce for typing / route-sync paths so we never spam
  // IMAP. Cancelled when the user clears search, hits Enter, or switches
  // folder mid-typing.
  let _debounceTimer = null
  function debouncedHandleSearch(delay = 300) {
    if (_debounceTimer) clearTimeout(_debounceTimer)
    _debounceTimer = setTimeout(() => {
      _debounceTimer = null
      handleSearch()
    }, delay)
  }
  function cancelDebounced() {
    if (_debounceTimer) { clearTimeout(_debounceTimer); _debounceTimer = null }
  }

  function applyVisualFilter() {
    const mailbox = useMailboxStore()
    const query = buildSearchQuery()
    searchQuery.value = query
    closeFilterPanel()
    cancelDebounced()

    if (query) {
      if (filterScope.value === 'all') {
        if (mailbox.currentFolder && mailbox.currentFolder !== 'SEARCH_RESULTS') {
          preSearchFolder.value = mailbox.currentFolder
        }
        mailbox.searchAllFolders(query)
      } else {
        mailbox.search(query)
      }
    } else {
      if (mailbox.currentFolder === 'SEARCH_RESULTS') {
        mailbox.fetchMessages(preSearchFolder.value || 'INBOX')
      } else {
        mailbox.fetchMessages()
      }
    }
  }

  function clearSearch() {
    const mailbox = useMailboxStore()
    cancelDebounced()
    searchQuery.value = ''
    visualFilters.value = emptyFilters()
    filterScope.value = 'all'
    closeFilterPanel()
    if (mailbox.currentFolder === 'SEARCH_RESULTS') {
      mailbox.fetchMessages(preSearchFolder.value || 'INBOX')
    } else {
      mailbox.fetchMessages()
    }
  }

  function clearVisualFilters() {
    const mailbox = useMailboxStore()
    cancelDebounced()
    visualFilters.value = emptyFilters()
    filterScope.value = 'all'
    searchQuery.value = ''
    closeFilterPanel()
    if (mailbox.currentFolder === 'SEARCH_RESULTS') {
      mailbox.fetchMessages(preSearchFolder.value || 'INBOX')
    } else {
      mailbox.fetchMessages()
    }
  }

  /**
   * Prime the store from a `?search=foo` URL query parameter (Clients deep-link
   * etc.). Routed through immediate handleSearch — debounce is for typing only.
   */
  function primeFromUrl(query) {
    const mailbox = useMailboxStore()
    if (!query) return
    searchQuery.value = query
    filterScope.value = 'all'
    if (mailbox.currentFolder && mailbox.currentFolder !== 'SEARCH_RESULTS') {
      preSearchFolder.value = mailbox.currentFolder
    }
    mailbox.searchAllFolders(query)
  }

  return {
    // state
    searchQuery,
    visualFilters,
    filterScope,
    preSearchFolder,
    showVisualFilter,
    // computed
    activeFilterCount,
    // actions
    openFilterPanel,
    closeFilterPanel,
    toggleVisualFilter,
    toggleFilterLabel,
    buildSearchQuery,
    parseQueryToFilters,
    handleSearch,
    debouncedHandleSearch,
    cancelDebounced,
    applyVisualFilter,
    clearSearch,
    clearVisualFilters,
    primeFromUrl,
  }
})
