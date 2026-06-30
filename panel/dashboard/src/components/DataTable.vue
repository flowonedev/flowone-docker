<script setup>
import { computed } from 'vue'

const props = defineProps({
  columns: {
    type: Array,
    required: true
    // { key: string, label: string, class?: string }
  },
  data: {
    type: Array,
    default: () => []
  },
  loading: {
    type: Boolean,
    default: false
  },
  emptyMessage: {
    type: String,
    default: 'No data available'
  }
})

const emit = defineEmits(['row-click'])
</script>

<template>
  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th 
              v-for="col in columns" 
              :key="col.key"
              :class="col.class"
            >
              {{ col.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <!-- Loading state -->
          <tr v-if="loading">
            <td :colspan="columns.length" class="py-12 text-center">
              <div class="flex items-center justify-center gap-3 text-surface-400">
                <span class="spinner"></span>
                <span>Loading...</span>
              </div>
            </td>
          </tr>

          <!-- Empty state -->
          <tr v-else-if="!data.length">
            <td :colspan="columns.length" class="py-12 text-center text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">inbox</span>
              {{ emptyMessage }}
            </td>
          </tr>

          <!-- Data rows -->
          <tr 
            v-else
            v-for="(row, index) in data" 
            :key="index"
            class="cursor-pointer"
            @click="emit('row-click', row)"
          >
            <td 
              v-for="col in columns" 
              :key="col.key"
              :class="col.class"
            >
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                {{ row[col.key] }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

