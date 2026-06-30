<script setup>
import { onMounted, computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const emit = defineEmits(['close'])

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()

const threads = computed(() => chatStore.allThreads)
const loading = computed(() => chatStore.loadingAllThreads)

function getConversationLabel(thread) {
  if (thread.conversation_name) return thread.conversation_name
  if (thread.conversation_type === 'channel') return '#' + (thread.conversation_name || 'channel')
  return 'Direct message'
}

function getSender(thread) {
  return colleaguesStore.colleagueById?.[thread.sender_id] || {
    display_name: thread.sender_name,
    email: thread.sender_email,
    avatar_path: thread.sender_avatar
  }
}

function getInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
}

function formatTime(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now - d
  const diffMins = Math.floor(diffMs / 60000)
  if (diffMins < 1) return 'just now'
  if (diffMins < 60) return `${diffMins}m ago`
  const diffHours = Math.floor(diffMins / 60)
  if (diffHours < 24) return `${diffHours}h ago`
  const diffDays = Math.floor(diffHours / 24)
  if (diffDays < 7) return `${diffDays}d ago`
  return d.toLocaleDateString()
}

function truncate(text, max = 80) {
  if (!text) return ''
  // Strip embed/system markers
  const clean = text.replace(/\[embed:\w+:\d+\]/g, '📎 Shared content').replace(/\[voice:\d+\]/g, '🎙 Voice message')
  return clean.length > max ? clean.substring(0, max) + '…' : clean
}

function openThread(thread) {
  // Navigate to the conversation and open the thread
  chatStore.setActiveConversation(thread.conversation_id)
  chatStore.openThread(thread.id)
  emit('close')
}

onMounted(() => {
  chatStore.fetchActiveThreads()
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[10000] flex items-center justify-center">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/40" @click="emit('close')"></div>

      <!-- Panel -->
      <div class="relative w-full max-w-lg mx-4 max-h-[80vh] bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between p-5 border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex-shrink-0">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-xl text-primary-500">forum</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">All Threads</h2>
          </div>
          <button
            @click="emit('close')"
            class="w-9 h-9 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-xl text-surface-500">close</span>
          </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex-1 flex items-center justify-center py-12">
          <span class="material-symbols-rounded text-3xl text-surface-300 animate-spin">progress_activity</span>
        </div>

        <!-- Thread list -->
        <div v-else-if="threads.length > 0" class="flex-1 overflow-y-auto">
          <button
            v-for="thread in threads"
            :key="thread.id"
            @click="openThread(thread)"
            class="w-full flex items-start gap-3 p-4 hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors text-left border-b border-surface-100 dark:border-surface-800 last:border-b-0"
          >
            <!-- Avatar -->
            <UserAvatar
              :colleague="getSender(thread)"
              :email="getSender(thread).email"
              :name="getSender(thread).display_name"
              :avatar-path="getSender(thread).avatar_path || ''"
              size="lg"
            />

            <!-- Content -->
            <div class="flex-1 min-w-0">
              <!-- Sender + conversation -->
              <div class="flex items-center gap-2 mb-0.5">
                <span class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ getSender(thread).display_name || getSender(thread).email }}
                </span>
                <span class="text-xs text-surface-400 flex-shrink-0">in</span>
                <span class="text-xs font-medium text-primary-500 truncate">{{ getConversationLabel(thread) }}</span>
              </div>

              <!-- Message preview -->
              <p class="text-sm text-surface-600 dark:text-surface-400 truncate">
                {{ truncate(thread.content) }}
              </p>

              <!-- Thread stats -->
              <div class="flex items-center gap-3 mt-1.5">
                <span class="flex items-center gap-1 text-xs text-primary-500 font-medium">
                  <span class="material-symbols-rounded text-sm">forum</span>
                  {{ thread.reply_count }} {{ thread.reply_count === 1 ? 'reply' : 'replies' }}
                </span>
                <span class="text-xs text-surface-400">
                  Last reply {{ formatTime(thread.last_reply_at) }}
                </span>
              </div>
            </div>

            <!-- Arrow -->
            <span class="material-symbols-rounded text-lg text-surface-300 flex-shrink-0 mt-1">chevron_right</span>
          </button>
        </div>

        <!-- Empty state -->
        <div v-else class="flex-1 flex flex-col items-center justify-center py-12 text-center px-6">
          <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">forum</span>
          <p class="text-surface-500 font-medium">No threads yet</p>
          <p class="text-xs text-surface-400 mt-1">When you reply to a message in a thread, it will appear here</p>
        </div>
      </div>
    </div>
  </Teleport>
</template>

