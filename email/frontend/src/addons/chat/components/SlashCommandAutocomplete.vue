<script setup>
import { ref, computed, watch, nextTick } from 'vue'

const props = defineProps({
  query: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['select', 'close'])

const selectedIndex = ref(0)
const listRef = ref(null)

// Built-in slash commands
const commands = [
  { name: 'poll', description: 'Create a quick poll', icon: 'ballot', usage: '/poll "Question" "Option 1" "Option 2"' },
  { name: 'shrug', description: 'Append a shrug', icon: 'sentiment_neutral', usage: '/shrug [message]' },
  { name: 'giphy', description: 'Search for a GIF', icon: 'gif_box', usage: '/giphy [search term]' },
  { name: 'remind', description: 'Set a reminder', icon: 'alarm', usage: '/remind [time] [message]' },
  { name: 'mute', description: 'Mute this conversation', icon: 'notifications_off', usage: '/mute' },
  { name: 'unmute', description: 'Unmute this conversation', icon: 'notifications', usage: '/unmute' },
  { name: 'topic', description: 'Set channel topic', icon: 'topic', usage: '/topic [text]' },
  { name: 'status', description: 'Set your status', icon: 'mood', usage: '/status [emoji] [text]' },
  { name: 'away', description: 'Toggle away status', icon: 'do_not_disturb', usage: '/away' },
  { name: 'clear', description: 'Clear chat history (local)', icon: 'delete_sweep', usage: '/clear' },
]

const filteredCommands = computed(() => {
  const q = props.query.toLowerCase()
  if (!q) return commands
  return commands.filter(c => c.name.startsWith(q))
})

watch(filteredCommands, () => {
  selectedIndex.value = 0
})

function handleKeydown(e) {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    selectedIndex.value = Math.min(selectedIndex.value + 1, filteredCommands.value.length - 1)
    scrollToSelected()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
    scrollToSelected()
  } else if (e.key === 'Enter' || e.key === 'Tab') {
    e.preventDefault()
    if (filteredCommands.value[selectedIndex.value]) {
      emit('select', '/' + filteredCommands.value[selectedIndex.value].name)
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

defineExpose({ handleKeydown })
</script>

<template>
  <div
    v-if="filteredCommands.length > 0"
    ref="listRef"
    class="absolute bottom-full left-0 right-0 mb-1 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 max-h-64 overflow-y-auto z-50"
  >
    <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700">
      <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">Commands</p>
    </div>
    <div class="py-1">
      <button
        v-for="(cmd, index) in filteredCommands"
        :key="cmd.name"
        :data-index="index"
        @click="$emit('select', '/' + cmd.name)"
        @mouseenter="selectedIndex = index"
        :class="[
          'w-full flex items-center gap-3 px-4 py-2 text-left transition-colors',
          index === selectedIndex
            ? 'bg-primary-50 dark:bg-primary-500/10'
            : 'hover:bg-surface-50 dark:hover:bg-surface-700'
        ]"
      >
        <div class="w-8 h-8 rounded-lg bg-surface-100 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-lg text-surface-500">{{ cmd.icon }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium text-surface-900 dark:text-surface-100">/{{ cmd.name }}</span>
          <p class="text-xs text-surface-500 truncate">{{ cmd.description }}</p>
        </div>
      </button>
    </div>
  </div>
</template>

