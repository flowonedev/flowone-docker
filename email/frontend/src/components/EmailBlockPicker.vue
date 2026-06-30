<script setup>
import { ref, onMounted, computed } from 'vue'
import { useEmailTemplatesStore } from '@/stores/emailTemplates'

const emit = defineEmits(['insert', 'close'])

const templatesStore = useEmailTemplatesStore()
const searchQuery = ref('')
const activeCategory = ref('all')

// Fetch custom templates on mount
onMounted(() => {
  if (templatesStore.templates.length === 0) {
    templatesStore.fetchTemplates()
  }
})

// Category list
const categories = computed(() => {
  const cats = [
    { id: 'all', label: 'All', icon: 'apps' },
    { id: 'text', label: 'Text', icon: 'notes' },
    { id: 'media', label: 'Media', icon: 'image' },
    { id: 'layout', label: 'Layout', icon: 'view_column' },
    { id: 'cta', label: 'CTA', icon: 'ads_click' },
  ]
  
  // Add 'custom' category if user has custom templates
  if (templatesStore.templates.length > 0) {
    cats.push({ id: 'custom', label: 'Custom', icon: 'dashboard_customize' })
  }
  
  return cats
})

// Filtered blocks
const filteredBlocks = computed(() => {
  let blocks = templatesStore.allBlocks
  
  // Filter by category
  if (activeCategory.value === 'custom') {
    // "Custom" means user-created templates (not built-in), regardless of their category
    blocks = blocks.filter(b => !b.is_builtin)
  } else if (activeCategory.value !== 'all') {
    blocks = blocks.filter(b => b.category === activeCategory.value)
  }
  
  // Filter by search
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    blocks = blocks.filter(b => 
      b.name.toLowerCase().includes(q) || 
      (b.description && b.description.toLowerCase().includes(q))
    )
  }
  
  return blocks
})

function insertBlock(block) {
  emit('insert', block)
  emit('close')
}
</script>

<template>
  <div class="w-[26rem] max-h-[480px] flex flex-col bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
    <!-- Header -->
    <div class="px-4 pt-3 pb-2 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center gap-2 mb-2">
        <span class="material-symbols-rounded text-primary-500 text-lg">dashboard_customize</span>
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Insert Block</h3>
      </div>
      
      <!-- Search -->
      <div class="relative">
        <span class="material-symbols-rounded text-surface-400 absolute left-2.5 top-1/2 -translate-y-1/2 text-lg">search</span>
        <input
          v-model="searchQuery"
          type="text"
          class="w-full pl-8 pr-3 py-1.5 text-sm rounded-lg bg-surface-100 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 outline-none focus:ring-2 focus:ring-primary-500/40 focus:border-primary-500 text-surface-900 dark:text-surface-100 placeholder-surface-400"
          placeholder="Search blocks..."
          autofocus
        />
      </div>
    </div>
    
    <!-- Category tabs -->
    <div class="flex items-center gap-1 px-3 py-2 border-b border-surface-200 dark:border-surface-700 overflow-x-auto">
      <button
        v-for="cat in categories"
        :key="cat.id"
        @click="activeCategory = cat.id"
        :class="[
          'px-2.5 py-1 rounded-full text-xs font-medium whitespace-nowrap transition-colors',
          activeCategory === cat.id 
            ? 'bg-primary-500 text-white' 
            : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
        ]"
      >
        {{ cat.label }}
      </button>
    </div>
    
    <!-- Block list -->
    <div class="flex-1 overflow-y-auto p-2 space-y-1">
      <!-- Loading state -->
      <div v-if="templatesStore.loading" class="flex items-center justify-center py-8">
        <span class="spinner text-primary-500"></span>
      </div>
      
      <!-- Empty state -->
      <div v-else-if="filteredBlocks.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-3xl mb-1 block">search_off</span>
        <p class="text-sm">No blocks found</p>
      </div>
      
      <!-- Blocks -->
      <button
        v-for="block in filteredBlocks"
        :key="block.id"
        @click="insertBlock(block)"
        class="w-full flex items-start gap-3 p-2.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left group"
      >
        <div class="w-9 h-9 rounded-lg bg-primary-50 dark:bg-primary-500/10 flex items-center justify-center flex-shrink-0 group-hover:bg-primary-100 dark:group-hover:bg-primary-500/20 transition-colors">
          <span class="material-symbols-rounded text-primary-500 text-lg">{{ block.icon || 'dashboard_customize' }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5">
            <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ block.name }}</p>
            <span 
              v-if="block.is_shared" 
              class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
            >
              Team
            </span>
          </div>
          <p v-if="block.description" class="text-xs text-surface-500 truncate mt-0.5">{{ block.description }}</p>
        </div>
        <span class="material-symbols-rounded text-surface-300 dark:text-surface-600 group-hover:text-primary-500 transition-colors text-lg mt-1">add_circle</span>
      </button>
    </div>
    
    <!-- Footer hint -->
    <div class="px-3 py-2 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900/50">
      <p class="text-[11px] text-surface-400 flex items-center gap-1">
        <span class="material-symbols-rounded text-sm">info</span>
        Create custom blocks in Settings &gt; Email Templates
      </p>
    </div>
  </div>
</template>

