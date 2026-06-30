<template>
  <div class="w-64 bg-white dark:bg-surface-900 border-r border-surface-200 dark:border-surface-700 flex flex-col h-full">
    <!-- Search -->
    <div class="p-3 border-b border-surface-200 dark:border-surface-700">
      <div class="relative">
        <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search nodes..."
          class="w-full pl-9 pr-3 py-2 bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-800 dark:text-surface-200 placeholder-surface-400 dark:placeholder-surface-500 focus:outline-none focus:border-primary-500 transition-colors"
        />
      </div>
    </div>

    <!-- Node categories -->
    <div class="flex-1 overflow-y-auto p-3 space-y-1" @wheel.stop @mousedown.stop>
      <!-- Category sections -->
      <template v-for="cat in orderedCategories" :key="cat.key">
        <div v-if="cat.groups.length" class="mb-3">
          <!-- Category header -->
          <div class="flex items-center gap-2 mb-2 px-1">
            <span class="material-symbols-rounded text-xs" :class="getCategoryColors(cat.key).text">{{ cat.icon }}</span>
            <span class="text-[10px] font-bold uppercase tracking-wider" :class="getCategoryColors(cat.key).text">{{ cat.label }}</span>
            <div class="flex-1 h-px" :class="cat.key === 'trigger' ? 'bg-amber-500/20' : cat.key === 'logic' ? 'bg-emerald-500/20' : 'bg-blue-500/20'" />
          </div>

          <template v-for="grp in cat.groups" :key="grp.name">
            <div class="text-[10px] font-bold text-surface-400 dark:text-surface-500 uppercase tracking-wider mb-1 mt-2 ml-1">{{ grp.name }}</div>
            <div class="space-y-0.5">
              <div
                v-for="item in grp.items"
                :key="item.type"
                draggable="true"
                class="flex items-center gap-2.5 px-2.5 py-2 cursor-grab active:cursor-grabbing transition-colors border border-transparent"
                :class="item.category === 'trigger'
                  ? 'rounded-xl hover:bg-amber-50 dark:hover:bg-amber-500/5 hover:border-amber-200 dark:hover:border-amber-500/20'
                  : 'rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 hover:border-surface-200 dark:hover:border-surface-600'"
                @dragstart="onDragStart($event, item.type)"
              >
                <div
                  class="w-7 h-7 flex items-center justify-center shrink-0"
                  :class="[getCategoryColors(item.category).bg, item.category === 'trigger' ? 'rounded-full' : 'rounded-lg']"
                >
                  <span class="material-symbols-rounded text-base" :class="getCategoryColors(item.category).text">{{ item.icon }}</span>
                </div>
                <div class="min-w-0">
                  <div class="flex items-center gap-1">
                    <span v-if="item.category === 'trigger'" class="material-symbols-rounded text-amber-500 dark:text-amber-400 text-[10px]">bolt</span>
                    <span class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">{{ item.label }}</span>
                  </div>
                  <div class="text-[10px] text-surface-400 dark:text-surface-500 truncate">{{ item.subtitle }}</div>
                </div>
              </div>
            </div>
          </template>
        </div>
      </template>

      <div v-if="noResults" class="text-center py-8">
        <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">search_off</span>
        <p class="text-xs text-surface-500 mt-2">No nodes found</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useNodeRegistry } from '../../composables/useNodeRegistry'

const { groupedNodes, getCategoryColors } = useNodeRegistry()

const search = ref('')

const CATEGORY_ORDER = [
  { key: 'trigger', label: 'Triggers', icon: 'bolt' },
  { key: 'action', label: 'Actions', icon: 'play_arrow' },
  { key: 'logic', label: 'Logic', icon: 'call_split' },
]

const orderedCategories = computed(() => {
  const q = search.value.toLowerCase().trim()

  return CATEGORY_ORDER.map(cat => {
    const groups = []
    for (const [groupName, items] of Object.entries(groupedNodes.value)) {
      let filtered = items.filter(i => i.category === cat.key)
      if (q) {
        filtered = filtered.filter(i =>
          i.label.toLowerCase().includes(q) ||
          i.subtitle.toLowerCase().includes(q) ||
          i.type.toLowerCase().includes(q)
        )
      }
      if (filtered.length) {
        groups.push({ name: groupName, items: filtered })
      }
    }
    return { ...cat, groups }
  })
})

const noResults = computed(() => orderedCategories.value.every(c => c.groups.length === 0))

function onDragStart(e, nodeType) {
  e.dataTransfer.setData('automation-hub/node-type', nodeType)
  e.dataTransfer.effectAllowed = 'copy'
}
</script>
