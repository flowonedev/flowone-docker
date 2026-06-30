<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  messageId: {
    type: String,
    required: true
  },
  participants: {
    type: Array,
    required: true
  },
  subject: {
    type: String,
    default: ''
  },
  snippet: {
    type: String,
    default: ''
  },
  // Show as icon button (for toolbar) or inline (for message header)
  mode: {
    type: String,
    default: 'button', // 'button' | 'inline'
  },
  // Position of picker popup
  position: {
    type: String,
    default: 'bottom' // 'bottom' | 'top' | 'left' | 'right'
  }
})

const emit = defineEmits(['reacted'])

// Default emojis (fallback until loaded from store)
const defaultEmojis = [
  { key: 'thumbsup', emoji: '👍' },
  { key: 'heart', emoji: '❤️' },
  { key: 'party', emoji: '🎉' },
  { key: 'laugh', emoji: '😂' },
  { key: 'surprised', emoji: '😲' },
  { key: 'worried', emoji: '😟' },
]

const showPicker = ref(false)
const pickerRef = ref(null)
const buttonRef = ref(null)
const emojis = ref(defaultEmojis)
const isLoading = ref(false)
const userReactions = ref({})

// Load store data lazily
let reactionsStore = null

async function getStore() {
  if (!reactionsStore) {
    const { useReactionsStore } = await import('@/addons/reactions/stores/reactions')
    reactionsStore = useReactionsStore()
    
    // Update emojis from store
    if (reactionsStore.availableEmojis.length > 0) {
      emojis.value = reactionsStore.availableEmojis
    }
  }
  return reactionsStore
}

// Check if user has reacted with specific emoji
function hasReacted(emojiKey) {
  return userReactions.value[emojiKey] || false
}

async function handleEmojiClick(emojiKey) {
  showPicker.value = false
  isLoading.value = true
  
  try {
    const store = await getStore()
    const result = await store.addReaction(
      props.messageId,
      emojiKey,
      props.participants,
      props.subject,
      props.snippet
    )
    
    if (result) {
      emit('reacted', result)
      
      // Update local user reactions state
      if (result.action === 'added') {
        userReactions.value[emojiKey] = true
      } else {
        userReactions.value[emojiKey] = false
      }
    }
  } catch (e) {
    console.error('Failed to add reaction:', e)
  } finally {
    isLoading.value = false
  }
}

function togglePicker() {
  showPicker.value = !showPicker.value
}

// Close picker when clicking outside
function handleClickOutside(e) {
  if (showPicker.value && pickerRef.value && !pickerRef.value.contains(e.target) && !buttonRef.value?.contains(e.target)) {
    showPicker.value = false
  }
}

onMounted(async () => {
  document.addEventListener('click', handleClickOutside)
  
  // Load emojis from store
  try {
    const store = await getStore()
    if (store.availableEmojis.length > 0) {
      emojis.value = store.availableEmojis
    }
    
    // Check current reactions for this message
    const reactions = store.getReactions(props.messageId)
    reactions.forEach(r => {
      if (r.user_reacted) {
        userReactions.value[r.emoji] = true
      }
    })
  } catch (e) {
    console.error('Failed to load reactions store:', e)
  }
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

// Position classes for picker
const positionClasses = computed(() => {
  switch (props.position) {
    case 'top':
      return 'bottom-full mb-2 left-1/2 -translate-x-1/2'
    case 'left':
      return 'right-full mr-2 top-1/2 -translate-y-1/2'
    case 'right':
      return 'left-full ml-2 top-1/2 -translate-y-1/2'
    default: // bottom
      return 'top-full mt-2 left-1/2 -translate-x-1/2'
  }
})
</script>

<template>
  <div class="relative inline-flex">
    <!-- Trigger button -->
    <button
      ref="buttonRef"
      @click.stop="togglePicker"
      :class="[
        'reaction-trigger',
        mode === 'button' 
          ? 'p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors' 
          : 'p-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700'
      ]"
      :title="showPicker ? 'Close reactions' : 'Add reaction'"
    >
      <span class="material-symbols-rounded text-lg text-surface-500 hover:text-primary-500 transition-colors">
        add_reaction
      </span>
    </button>
    
    <!-- Emoji picker popup -->
    <Transition name="picker">
      <div
        v-if="showPicker"
        ref="pickerRef"
        :class="[
          'absolute z-50 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 p-2',
          positionClasses
        ]"
        @click.stop
      >
        <div class="flex items-center gap-1">
          <button
            v-for="emoji in emojis"
            :key="emoji.key"
            @click="handleEmojiClick(emoji.key)"
            :class="[
              'w-10 h-10 flex items-center justify-center rounded-lg text-2xl transition-all hover:scale-110',
              hasReacted(emoji.key) 
                ? 'bg-primary-100 dark:bg-primary-900/50 ring-2 ring-primary-500' 
                : 'hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
            :title="emoji.key"
            :disabled="isLoading"
          >
            {{ emoji.emoji }}
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.picker-enter-active,
.picker-leave-active {
  transition: all 0.15s ease;
}

.picker-enter-from,
.picker-leave-to {
  opacity: 0;
  transform: scale(0.9) translateX(-50%);
}

.picker-enter-to,
.picker-leave-from {
  opacity: 1;
  transform: scale(1) translateX(-50%);
}
</style>


