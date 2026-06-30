<script setup>
/**
 * Single market card (one for stocks, one for crypto). Each row shows:
 *   logo / icon  ·  name  ·  price  ·  change %  ·  inline sparkline
 *
 * The sparkline is rendered as a tiny SVG polyline coloured by the
 * change direction (green for positive, red for negative). The card
 * shows a "Loading…" placeholder on first load and a graceful "—"
 * empty state if the upstream API failed.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  title: { type: String, required: true },
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  /** When true, render a small icon URL alongside each row (used by crypto) */
  showLogo: { type: Boolean, default: false },
})

const { t } = useI18n()

function formatPrice(value) {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  const v = Number(value)
  if (!Number.isFinite(v)) return '—'
  // Auto-pick a reasonable number of decimals: prices > 1 show 2 dp,
  // sub-dollar (XRP, DOGE) show 4 dp.
  const decimals = Math.abs(v) >= 1 ? 2 : 4
  return v.toLocaleString(undefined, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
}

function formatChange(pct) {
  if (pct === null || pct === undefined || Number.isNaN(pct)) return ''
  const n = Number(pct)
  if (!Number.isFinite(n)) return ''
  const sign = n > 0 ? '+' : ''
  return `${sign}${n.toFixed(2)}%`
}

/**
 * Build an SVG path-points string from a sparkline array. Normalises
 * the values into a 0..1 range over a 60×20 viewBox so all sparklines
 * render at the same physical size regardless of scale.
 */
function sparkPoints(values) {
  if (!Array.isArray(values) || values.length < 2) return ''
  const min = Math.min(...values)
  const max = Math.max(...values)
  const range = max - min || 1
  const w = 60
  const h = 20
  const stepX = w / (values.length - 1)
  return values
    .map((v, i) => {
      const x = (i * stepX).toFixed(2)
      // Invert Y so higher prices are visually higher on screen.
      const y = (h - ((v - min) / range) * h).toFixed(2)
      return `${x},${y}`
    })
    .join(' ')
}

// Show every row the basket returned. The basket itself is capped at
// 12 in MarketsService::sanitiseBasket(), so the card grows to fit at
// most that many rows. We intentionally do NOT slice further here —
// the previous 6-row cap silently hid the user's selections beyond
// the first six.
const visibleRows = computed(() => props.rows)
</script>

<template>
  <article
    class="market-card flex flex-col rounded-2xl bg-white dark:bg-[rgb(var(--color-surface))] border border-surface-200/70 dark:border-surface-700/60 overflow-hidden"
  >
    <header class="flex items-center justify-between px-4 sm:px-5 pt-4 pb-3">
      <h3 class="text-lg font-bold tracking-tight text-surface-900 dark:text-surface-50">
        {{ title }}
      </h3>
      <span
        v-if="loading && !rows.length"
        class="text-[11px] uppercase tracking-wider text-surface-400 inline-flex items-center gap-1"
      >
        <span class="material-symbols-rounded animate-spin text-[14px]">progress_activity</span>
        {{ t('newsReader.loading') }}
      </span>
    </header>

    <ul v-if="visibleRows.length" class="flex-1 divide-y divide-surface-200/70 dark:divide-surface-700/60">
      <li
        v-for="row in visibleRows"
        :key="row.symbol"
        class="market-row flex items-center gap-3 px-4 sm:px-5 py-2.5"
      >
        <div class="shrink-0 w-7 h-7 rounded-full overflow-hidden bg-surface-100 dark:bg-surface-700 flex items-center justify-center">
          <img
            v-if="showLogo && row.image"
            :src="row.image"
            alt=""
            class="w-full h-full object-cover"
            loading="lazy"
            referrerpolicy="no-referrer"
          />
          <span
            v-else
            class="text-[10px] font-bold text-surface-500 dark:text-surface-300 uppercase"
          >{{ (row.symbol || '').slice(0, 3) }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-semibold text-surface-900 dark:text-surface-50 truncate">
            {{ row.name || row.symbol }}
            <span class="text-[11px] font-normal text-surface-400 ml-1 uppercase tracking-wider">
              {{ row.symbol }}
            </span>
          </div>
        </div>

        <!-- Sparkline -->
        <svg
          v-if="row.sparkline && row.sparkline.length >= 2"
          class="market-spark shrink-0"
          viewBox="0 0 60 20"
          width="60"
          height="20"
          aria-hidden="true"
        >
          <polyline
            :points="sparkPoints(row.sparkline)"
            fill="none"
            stroke="currentColor"
            stroke-width="1.4"
            stroke-linecap="round"
            stroke-linejoin="round"
            :class="Number(row.change_pct) >= 0
              ? 'text-emerald-500'
              : 'text-rose-500'"
          />
        </svg>

        <div class="shrink-0 text-right">
          <div class="text-sm font-semibold tabular-nums text-surface-900 dark:text-surface-50">
            {{ formatPrice(row.price) }}
          </div>
          <div
            v-if="formatChange(row.change_pct)"
            class="text-[11px] font-medium tabular-nums"
            :class="Number(row.change_pct) >= 0
              ? 'text-emerald-600 dark:text-emerald-400'
              : 'text-rose-600 dark:text-rose-400'"
          >
            {{ formatChange(row.change_pct) }}
          </div>
        </div>
      </li>
    </ul>

    <div
      v-else-if="!loading"
      class="flex-1 px-4 sm:px-5 py-8 text-center text-sm text-surface-400 dark:text-surface-500 italic"
    >
      {{ t('newsReader.marketsUnavailable') }}
    </div>
  </article>
</template>

<style scoped>
.market-card {
  min-height: 320px;
}
</style>
