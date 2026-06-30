<script setup>
import { ref, computed, onMounted, watch } from 'vue'

const props = defineProps({
  messageId: {
    type: String,
    required: true
  },
  // Compact mode for email list, full mode for email view
  compact: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['click'])

const reactions = ref([])

// Load reactions from store lazily
let reactionsStore = null

async function getStore() {
  if (!reactionsStore) {
    const { useReactionsStore } = await import('@/addons/reactions/stores/reactions')
    reactionsStore = useReactionsStore()
  }
  return reactionsStore
}

async function loadReactions() {
  if (!props.messageId) return
  
  try {
    const store = await getStore()
    reactions.value = store.getReactions(props.messageId) || []
  } catch (e) {
    console.error('Failed to load reactions:', e)
  }
}

// Watch for message changes
watch(() => props.messageId, () => {
  loadReactions()
}, { immediate: false })

// Load on mount
onMounted(() => {
  loadReactions()
  
  // Set up interval to refresh reactions from store cache
  const interval = setInterval(loadReactions, 2000)
  
  // Cleanup
  return () => clearInterval(interval)
})

// Total reaction count
const totalCount = computed(() => {
  return reactions.value.reduce((sum, r) => sum + r.count, 0)
})

function handleClick(reaction) {
  emit('click', reaction)
}
</script>

<template>
  <div v-if="reactions.length > 0" class="reaction-display flex items-center gap-1">
    <!-- Compact mode: just show emoji + count -->
    <template v-if="compact">
      <div 
        v-for="reaction in reactions.slice(0, 3)"
        :key="reaction.emoji"
        :class="[
          'inline-flex items-center gap-1 h-6 px-2 rounded-full text-xs',
          reaction.user_reacted 
            ? 'bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300' 
            : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'
        ]"
        :title="`${reaction.reactors?.map(r => r.name || r.email).join(', ') || ''}`"
      >
        <span class="text-sm">{{ reaction.emoji_char }}</span>
        <span v-if="reaction.count > 1" class="font-medium">{{ reaction.count }}</span>
      </div>
      <span 
        v-if="reactions.length > 3" 
        class="text-xs text-surface-500"
      >
        +{{ reactions.length - 3 }}
      </span>
    </template>
    
    <!-- Full mode: show all with tooltip -->
    <template v-else>
      <button
        v-for="reaction in reactions"
        :key="reaction.emoji"
        @click="handleClick(reaction)"
        :class="[
          'inline-flex items-center gap-1 px-2 py-1 rounded-full text-sm transition-colors cursor-pointer',
          reaction.user_reacted 
            ? 'bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300 hover:bg-primary-200 dark:hover:bg-primary-800/50' 
            : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
        ]"
        :title="`${reaction.reactors?.map(r => r.name || r.email).join(', ') || ''}`"
      >
        <span class="text-base">{{ reaction.emoji_char }}</span>
        <span class="font-medium">{{ reaction.count }}</span>
      </button>
    </template>
  </div>
</template>


