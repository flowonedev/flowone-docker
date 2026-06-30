/**
 * Master catalog of news interest topics.
 *
 * Each entry maps to the `s.category` column on `news_reader_subscriptions`.
 * When a user selects an interest, a chip appears in the reader's filter bar;
 * clicking it filters the feed by that category server-side.
 *
 * The `slug` is what gets stored in the DB and sent in `?category=` queries.
 * `i18nKey` is for the chip label (with fallback to `defaultLabel`).
 * `icon` is a Material Symbols Rounded ligature.
 */

export const INTEREST_CATEGORIES = [
  { slug: 'news', defaultLabel: 'News', icon: 'newspaper' },
  { slug: 'world', defaultLabel: 'World', icon: 'public' },
  { slug: 'politics', defaultLabel: 'Politics', icon: 'gavel' },
  { slug: 'business', defaultLabel: 'Business', icon: 'business_center' },
  { slug: 'money', defaultLabel: 'Money', icon: 'payments' },
  { slug: 'crypto', defaultLabel: 'Crypto', icon: 'currency_bitcoin' },
  { slug: 'tech', defaultLabel: 'Tech', icon: 'memory' },
  { slug: 'ai', defaultLabel: 'AI', icon: 'smart_toy' },
  { slug: 'science', defaultLabel: 'Science', icon: 'science' },
  { slug: 'health', defaultLabel: 'Health', icon: 'favorite' },
  { slug: 'sports', defaultLabel: 'Sports', icon: 'sports_soccer' },
  { slug: 'culture', defaultLabel: 'Culture', icon: 'palette' },
  { slug: 'entertainment', defaultLabel: 'Entertainment', icon: 'movie' },
  { slug: 'gaming', defaultLabel: 'Gaming', icon: 'sports_esports' },
  { slug: 'design', defaultLabel: 'Design', icon: 'design_services' },
  { slug: 'lifestyle', defaultLabel: 'Lifestyle', icon: 'spa' },
  { slug: 'travel', defaultLabel: 'Travel', icon: 'flight' },
  { slug: 'food', defaultLabel: 'Food', icon: 'restaurant' },
  { slug: 'auto', defaultLabel: 'Auto', icon: 'directions_car' },
  { slug: 'space', defaultLabel: 'Space', icon: 'rocket_launch' },
  { slug: 'climate', defaultLabel: 'Climate', icon: 'eco' },
  { slug: 'opinion', defaultLabel: 'Opinion', icon: 'forum' },
  { slug: 'videos', defaultLabel: 'Videos', icon: 'smart_display' },
]

export const DEFAULT_INTERESTS = ['news', 'world', 'tech', 'business', 'sports']

export function findCategoryBySlug(slug) {
  return INTEREST_CATEGORIES.find((c) => c.slug === slug) || null
}

export function categoryLabel(slug) {
  const c = findCategoryBySlug(slug)
  return c ? c.defaultLabel : slug
}

export function categoryIcon(slug) {
  const c = findCategoryBySlug(slug)
  return c ? c.icon : 'label'
}
