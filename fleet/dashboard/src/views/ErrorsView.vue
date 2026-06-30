<script setup>
import { ref } from 'vue'

const loading = ref(false)
</script>

<template>
  <div class="animate-fadeIn">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Errors</h1>
    </div>

    <div class="card p-12 text-center">
      <span class="material-symbols-rounded text-6xl text-surface-500 mb-4">construction</span>
      <h2 class="text-xl font-semibold mb-2">Coming Soon</h2>
      <p class="text-surface-400">Error aggregation and management will be available here</p>
    </div>
  </div>
</template>

