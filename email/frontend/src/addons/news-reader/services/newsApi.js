import api from '@/services/api'

export async function fetchNewsCatalog() {
  const r = await api.get('/news/catalog')
  return r.data?.data?.catalog ?? { HU: [], EN: [], US: [] }
}

export async function fetchNewsFeeds() {
  const r = await api.get('/news/feeds')
  return r.data?.data?.feeds ?? []
}

export async function fetchNewsItems(params = {}) {
  const r = await api.get('/news/items', { params })
  return r.data?.data ?? { items: [], next_cursor: null, has_more: false }
}

/**
 * Fetch a fixed list of items by their IDs. Used to materialize the
 * client-side bookmarks filter (bookmarks live in localStorage).
 */
export async function fetchNewsItemsByIds(ids) {
  const list = Array.isArray(ids) ? ids.filter((n) => Number.isFinite(Number(n))) : []
  if (!list.length) return []
  const r = await api.get('/news/items/by-ids', { params: { ids: list.join(',') } })
  return r.data?.data?.items ?? []
}

export async function postNewsSubscription(feedUrl, category = null) {
  const body = { feed_url: feedUrl }
  if (category) body.category = category
  const r = await api.post('/news/subscriptions', body)
  return r.data?.data
}

export async function patchNewsSubscription(id, patch) {
  const r = await api.patch(`/news/subscriptions/${id}`, patch)
  return r.data?.data
}

export async function deleteNewsSubscription(id) {
  await api.delete(`/news/subscriptions/${id}`)
}

export async function markNewsItemRead(id) {
  await api.post(`/news/items/${id}/read`)
}

export async function markNewsItemUnread(id) {
  await api.delete(`/news/items/${id}/read`)
}

export async function markAllRead(body = {}) {
  const r = await api.post('/news/items/read-all', body)
  return r.data?.data
}

export async function refreshNewsFeeds() {
  const r = await api.post('/news/refresh')
  return r.data?.data
}

/**
 * Ask the backend for a short-lived signed URL that the iframe can load.
 * The returned URL is same-origin so X-Frame-Options/CSP from the publisher
 * is bypassed by the server proxy.
 */
export async function fetchProxyUrl(articleUrl) {
  const r = await api.get('/news/proxy-url', { params: { url: articleUrl } })
  return r.data?.data?.proxy_url || ''
}

/**
 * Ask the backend for the full extracted article body. RSS feeds usually
 * ship only a 1–2 sentence summary; this fetches the publisher page server
 * side and returns the cleaned full text.
 *
 * Returns: { status, content_html, word_count, lead_image_url, byline,
 *            site_name, error, cached }
 */
export async function fetchFullArticle(itemId) {
  const r = await api.get(`/news/items/${itemId}/full`)
  return r.data?.data || null
}
