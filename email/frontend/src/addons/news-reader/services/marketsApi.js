import api from '@/services/api'

/**
 * Fetch the markets overview (stocks + crypto) for the dashboard panel.
 *
 * The user's basket selection is stored server-side in their settings
 * file (`news_markets_stocks` / `news_markets_crypto`); the backend
 * reads it on every request so we don't need to forward anything from
 * the client. Cache is keyed per-basket, so users sharing a basket
 * share the cache slot.
 *
 * Returns: { stocks: [{ symbol, name, price, change_pct, sparkline }],
 *            crypto: [{ symbol, name, price, change_pct, sparkline, image }],
 *            updated_at: number }
 */
export async function fetchMarketsOverview() {
  const r = await api.get('/markets/overview')
  return r.data?.data || { stocks: [], crypto: [], updated_at: null }
}

/**
 * Fetch the curated allow-list of stocks + crypto the user can pick
 * from in Settings. The shape matches MarketsService::getAvailable():
 *   {
 *     stocks: [{ symbol, name }],
 *     crypto: [{ id, symbol, name }],
 *     defaults: { stocks: [...], crypto: [...] }
 *   }
 */
export async function fetchMarketsAvailable() {
  const r = await api.get('/markets/available')
  return r.data?.data || { stocks: [], crypto: [], defaults: { stocks: [], crypto: [] } }
}
