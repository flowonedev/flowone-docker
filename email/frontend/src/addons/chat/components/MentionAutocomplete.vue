<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useChatPresence } from '@/composables/useChatPresence'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const props = defineProps({
  query: {
    type: String,
    default: ''
  },
  conversationId: {
    type: Number,
    default: null
  }
})

const emit = defineEmits(['select', 'close'])

const colleaguesStore = useColleaguesStore()
const chatStore = useChatStore()
const { getStatusColor } = useChatPresence()

const selectedIndex = ref(0)
const listRef = ref(null)

// Get conversation participants or all colleagues
const participants = computed(() => {
  const conv = chatStore.activeConversation
  if (conv?.participants) {
    return conv.participants.map(p => ({
      id: p.id,
      display_name: p.display_name || p.email?.split('@')[0],
      email: p.email,
      avatar_path: p.avatar_path
    }))
  }
  // Fallback to all colleagues
  return (colleaguesStore.colleagues || []).map(c => ({
    id: c.id,
    display_name: c.display_name || c.email?.split('@')[0],
    email: c.email,
    avatar_path: c.avatar_path
  }))
})

const filteredItems = computed(() => {
  const q = props.query.toLowerCase()
  
  // Special items always available
  const specials = [
    { id: 'here', display_name: 'here', email: 'Notify online members', special: true, icon: 'group' },
    { id: 'channel', display_name: 'channel', email: 'Notify all members', special: true, icon: 'campaign' }
  ]

  let items = []

  // Filter specials
  items.push(...specials.filter(s => 
    !q || s.display_name.toLowerCase().includes(q)
  ))

  // Filter colleagues
  const matchedColleagues = participants.value.filter(p => {
    if (!q) return true
    return (
      p.display_name?.toLowerCase().includes(q) ||
      p.email?.toLowerCase().includes(q)
    )
  }).slice(0, 10)

  items.push(...matchedColleagues)

  return items
})

// Reset selection when results change
watch(filteredItems, () => {
  selectedIndex.value = 0
})

function handleKeydown(e) {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    selectedIndex.value = Math.min(selectedIndex.value + 1, filteredItems.value.length - 1)
    scrollToSelected()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
    scrollToSelected()
  } else if (e.key === 'Enter' || e.key === 'Tab') {
    e.preventDefault()
    if (filteredItems.value[selectedIndex.value]) {
      selectItem(filteredItems.value[selectedIndex.value])
    }
  } else if (e.key === 'Escape') {
    emit('close')
  }
}

function scrollToSelected() {
  nextTick(() => {
    const el = listRef.value?.querySelector(`[data-index="${selectedIndex.value}"]`)
    if (el) el.scrollIntoView({ block: 'nearest' })
  })
}

function selectItem(item) {
  if (item.special) {
    emit('select', `@${item.display_name}`)
  } else {
    emit('select', `@${item.display_name}`)
  }
}

// Expose keydown handler for parent component
defineExpose({ handleKeydown })
</script>

<template>
  <div
    v-if="filteredItems.length > 0"
    ref="listRef"
    class="absolute bottom-full left-0 right-0 mb-1 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 max-h-64 overflow-y-auto z-50"
  >
    <div class="py-1">
      <button
        v-for="(item, index) in filteredItems"
        :key="item.id"
        :data-index="index"
        @click="selectItem(item)"
        @mouseenter="selectedIndex = index"
        :class="[
          'w-full flex items-center gap-3 px-4 py-2 text-left transition-colors',
          index === selectedIndex
            ? 'bg-primary-50 dark:bg-primary-500/10'
            : 'hover:bg-surface-50 dark:hover:bg-surface-700'
        ]"
      >
        <!-- Special mention icon -->
        <div v-if="item.special" class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-lg text-primary-500">{{ item.icon }}</span>
        </div>
        <!-- User avatar -->
        <UserAvatar v-else :colleague="item" size="md" />

        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium text-surface-900 dark:text-surface-100">
            @{{ item.display_name }}
          </span>
          <p class="text-xs text-surface-500 truncate">{{ item.email }}</p>
        </div>
      </button>
    </div>
  </div>
</template>

