<script setup>
import { computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useAuthStore } from '@/stores/auth'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()
const authStore = useAuthStore()

// Same source StatusPicker reads from, so the rail avatar + presence dot stay
// in sync with the status the user sets.
const currentColleague = computed(() => colleaguesStore.currentColleague)

const railActions = [
  { key: 'status', icon: 'sentiment_satisfied', label: 'Status' },
  { key: 'threads', icon: 'forum', label: 'Threads' },
  { key: 'saved', icon: 'bookmark', label: 'Saved' },
  { key: 'scheduled', icon: 'schedule_send', label: 'Scheduled' },
]
</script>

<template>
  <div
    class="chat-rail flex flex-col items-center w-16 flex-shrink-0 bg-white dark:bg-[rgb(var(--color-surface))] border-r border-surface-200 dark:border-[rgb(var(--color-border))] py-3"
  >
    <!-- Secondary panels (Status / Threads / Saved / Scheduled) -->
    <div class="flex flex-col items-center gap-1.5 flex-1 w-full px-1">
      <button
        v-for="action in railActions"
        :key="action.key"
        @click="chatStore.openPanel(action.key)"
        :title="action.label"
        :aria-label="action.label"
        :aria-pressed="chatStore.activePanel === action.key"
        :class="[
          'relative w-full flex flex-col items-center justify-center gap-0.5 py-2 rounded-xl transition-all active:scale-95',
          chatStore.activePanel === action.key
            ? 'bg-primary-50 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400'
            : 'text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-primary-500 dark:hover:text-primary-400'
        ]"
      >
        <span class="material-symbols-rounded text-[22px] leading-none">{{ action.icon }}</span>
        <span class="text-[10px] font-medium leading-none">{{ action.label }}</span>
      </button>
    </div>

    <!-- Current user avatar + presence dot: click opens the Status picker -->
    <button
      @click="chatStore.openPanel('status')"
      title="Set your status"
      aria-label="Set your status"
      class="mt-2 rounded-full ring-2 ring-transparent hover:ring-primary-400 transition-all active:scale-95"
    >
      <UserAvatar
        :colleague="currentColleague"
        :email="authStore.userEmail"
        size="lg"
        show-presence
      />
    </button>
  </div>
</template>
