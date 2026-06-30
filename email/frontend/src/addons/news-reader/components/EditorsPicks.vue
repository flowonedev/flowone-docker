<script setup>
/**
 * 4-up "Editor's picks" row at the bottom of the dashboard. Same tile
 * style as the FeaturedRow, but four-wide on lg+ to feel like a
 * curated end-of-page strip.
 */
import { useI18n } from 'vue-i18n'
import ArticleTile from './ArticleTile.vue'

defineProps({
  items: { type: Array, required: true },
})
const emit = defineEmits(['open', 'filter-source'])

const { t } = useI18n()
</script>

<template>
  <section v-if="items.length" class="news-picks-row">
    <header class="flex items-center justify-between mb-3">
      <h3 class="text-xl font-bold tracking-tight text-surface-900 dark:text-surface-50">
        {{ t('newsReader.editorsPicks') }}
      </h3>
    </header>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
      <ArticleTile
        v-for="(item, idx) in items"
        :key="item.id"
        :article="item"
        variant="short"
        :style="{ '--tile-idx': idx }"
        @open="(a) => emit('open', a)"
        @filter-source="(p) => emit('filter-source', p)"
      />
    </div>
  </section>
</template>
