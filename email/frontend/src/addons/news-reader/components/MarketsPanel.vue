<script setup>
/**
 * Two-card row showing the Market Overview (stocks) and Crypto.
 *
 * The actual fetching + auto-refresh lives in the markets store; this
 * component just owns the layout and starts/stops the polling loop
 * tied to its own lifecycle so we don't keep hitting upstream APIs
 * when the user closes the reader.
 */
import { onBeforeUnmount, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useMarketsStore } from '@/addons/news-reader/stores/markets'
import MarketCard from './MarketCard.vue'

const { t } = useI18n()
const markets = useMarketsStore()
const { stocks, crypto, loading } = storeToRefs(markets)

onMounted(() => markets.startPolling(60_000))
onBeforeUnmount(() => markets.stopPolling())
</script>

<template>
  <section class="news-markets">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5">
      <MarketCard
        :title="t('newsReader.marketOverview')"
        :rows="stocks"
        :loading="loading"
      />
      <MarketCard
        :title="t('newsReader.crypto')"
        :rows="crypto"
        :loading="loading"
        :show-logo="true"
      />
    </div>
  </section>
</template>
