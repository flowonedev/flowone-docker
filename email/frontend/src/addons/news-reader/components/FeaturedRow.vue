<script setup>
/**
 * 3-up featured tile row, sitting just under the hero. Uses the
 * existing ArticleTile component with a fixed `medium` variant so the
 * cards land at a consistent height regardless of which articles are
 * shown.
 */
import ArticleTile from './ArticleTile.vue'

defineProps({
  items: { type: Array, required: true },
})
const emit = defineEmits(['open', 'filter-source'])
</script>

<template>
  <section v-if="items.length" class="news-featured-row">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
      <ArticleTile
        v-for="(item, idx) in items"
        :key="item.id"
        :article="item"
        variant="medium"
        :style="{ '--tile-idx': idx }"
        @open="(a) => emit('open', a)"
        @filter-source="(p) => emit('filter-source', p)"
      />
    </div>
  </section>
</template>
