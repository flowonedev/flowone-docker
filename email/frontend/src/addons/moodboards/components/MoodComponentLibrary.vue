<template>
  <div :class="embedded
    ? 'flex flex-col h-full overflow-hidden'
    : 'w-72 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden shadow-md'"
  >
    <!-- Header with tab toggle -->
    <div class="px-3 py-2.5 border-b border-surface-200 dark:border-surface-700 bg-surface-50/90 dark:bg-surface-800/90">
      <div class="flex items-center gap-2">
        <div class="min-w-0 flex-1">
          <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-surface-400 dark:text-surface-500">Library</p>
        </div>
        <div class="flex items-center bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg p-0.5">
          <button
            v-for="tab in mainTabs"
            :key="tab.id"
            @click="mainTab = tab.id"
            class="flex items-center justify-center gap-1 px-2.5 py-1 text-[10px] font-medium rounded-md transition-colors"
            :class="mainTab === tab.id
              ? 'bg-surface-900 dark:bg-surface-100 text-white dark:text-surface-900'
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
          >
            <span class="material-symbols-rounded text-sm">{{ tab.icon }}</span>
            {{ tab.label }}
          </button>
        </div>
        <button
          v-if="!embedded"
          @click="$emit('close')"
          class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors flex-shrink-0"
        >
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>
    </div>

    <!-- Category filter chips -->
    <div class="px-3 py-2 flex gap-1 flex-wrap border-b border-surface-200 dark:border-surface-700">
      <button
        v-for="cat in [{ value: null, label: 'All' }, ...activeCategories]"
        :key="cat.value ?? 'all'"
        @click="activeFilter = cat.value"
        class="px-2 py-0.5 text-[10px] font-medium rounded-full transition-colors"
        :class="activeFilter === cat.value
          ? 'bg-primary-500 text-white'
          : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'"
      >
        {{ cat.label }}
      </button>
    </div>

    <!-- Components list -->
    <div :class="embedded ? 'flex-1 overflow-y-auto custom-scrollbar min-h-0' : 'max-h-[380px] overflow-y-auto custom-scrollbar'">
      <div
        v-for="comp in filteredList"
        :key="comp.id"
        class="group flex items-center gap-2.5 px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 border-b border-surface-100 dark:border-surface-700/60 last:border-b-0 cursor-pointer transition-colors"
        @click="$emit('place-component', comp)"
      >
        <div class="w-8 h-8 rounded-md flex items-center justify-center flex-shrink-0"
          :class="mainTab === 'admin' ? 'bg-amber-100 dark:bg-amber-900/30' : 'bg-primary-100 dark:bg-primary-900/30'"
        >
          <span class="material-symbols-rounded text-base"
            :class="mainTab === 'admin' ? 'text-amber-600 dark:text-amber-400' : 'text-primary-600 dark:text-primary-400'"
          >{{ comp.icon || 'web' }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-[11px] font-medium text-surface-800 dark:text-surface-200 truncate leading-tight">{{ comp.name }}</p>
          <p class="text-[9px] text-surface-400 leading-snug truncate">{{ comp.description }}</p>
        </div>
        <span class="text-[9px] text-surface-400 tabular-nums flex-shrink-0">{{ comp.items_data?.length || 0 }}</span>
        <span class="material-symbols-rounded text-sm text-primary-500 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">add_circle</span>
      </div>
      <div v-if="filteredList.length === 0" class="px-4 py-8 text-center text-xs text-surface-400">
        No templates in this category.
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { premadeComponents, premadeCategories } from './premadeComponents'
import { adminComponents, adminCategories } from './premadeAdmin'

defineProps({
  embedded: { type: Boolean, default: false }
})

defineEmits(['close', 'place-component'])

const mainTab = ref('website')
const mainTabs = [
  { id: 'website', label: 'Website', icon: 'web' },
  { id: 'admin', label: 'Admin', icon: 'dashboard' },
]

const activeFilter = ref(null)

watch(mainTab, () => { activeFilter.value = null })

const activeCategories = computed(() =>
  mainTab.value === 'admin' ? adminCategories : premadeCategories
)

const activeList = computed(() =>
  mainTab.value === 'admin' ? adminComponents : premadeComponents
)

const filteredList = computed(() => {
  if (!activeFilter.value) return activeList.value
  return activeList.value.filter(c => c.category === activeFilter.value)
})
</script>
