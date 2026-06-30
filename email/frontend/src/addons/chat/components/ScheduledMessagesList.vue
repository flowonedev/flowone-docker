<script setup>
import { ref, computed, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'

const emit = defineEmits(['close'])

const chatStore = useChatStore()
const toast = useToastStore()

const loading = ref(false)
const cancelling = ref(null) // id of message being cancelled

const scheduledMessages = computed(() => chatStore.scheduledMessages)

onMounted(async () => {
  loading.value = true
  await chatStore.fetchScheduledMessages()
  loading.value = false
})

function formatScheduledTime(isoString) {
  if (!isoString) return ''
  const date = new Date(isoString)
  const now = new Date()
  const diff = date.getTime() - now.getTime()
  
  const options = { 
    weekday: 'short', 
    month: 'short', 
    day: 'numeric', 
    hour: '2-digit', 
    minute: '2-digit' 
  }
  const formatted = date.toLocaleDateString([], options)
  
  if (diff < 0) return `Overdue (${formatted})`
  if (diff < 60 * 60 * 1000) return `In ${Math.ceil(diff / (60 * 1000))} min (${formatted})`
  if (diff < 24 * 60 * 60 * 1000) return `Today at ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
  return formatted
}

function getConversationName(msg) {
  const conv = chatStore.conversations.find(c => c.id === msg.conversation_id)
  if (!conv) return 'Unknown conversation'
  if (conv.type === 'channel') return `#${conv.slug || conv.name || 'channel'}`
  if (conv.type === 'group') return conv.name || 'Group Chat'
  const participant = conv.participants?.[0]
  return participant?.display_name || participant?.email?.split('@')[0] || 'DM'
}

function truncateContent(content, max = 120) {
  if (!content) return ''
  const clean = content.replace(/<[^>]*>?/gm, '').replace(/\[.*?\]/g, '').trim()
  return clean.length > max ? clean.substring(0, max) + '...' : clean
}

async function cancelMessage(id) {
  cancelling.value = id
  const result = await chatStore.cancelScheduledMessage(id)
  if (result.success) {
    toast.success('Scheduled message cancelled')
  } else {
    toast.error(result.error || 'Failed to cancel')
  }
  cancelling.value = null
}

function goToConversation(msg) {
  chatStore.setActiveConversation(msg.conversation_id)
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[10000] flex items-center justify-center">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/40" @click="emit('close')"></div>

      <!-- Modal -->
      <div class="relative w-full max-w-lg mx-4 bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
        <!-- Header -->
        <div class="flex items-center justify-between p-5 border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex-shrink-0">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-xl text-primary-500">schedule_send</span>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Scheduled Messages</h2>
            <span v-if="scheduledMessages.length" class="px-2 py-0.5 bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 text-xs font-medium rounded-full">
              {{ scheduledMessages.length }}
            </span>
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

        <!-- Messages list -->
        <div v-else class="flex-1 overflow-y-auto">
          <div v-if="scheduledMessages.length > 0">
            <div
              v-for="msg in scheduledMessages"
              :key="msg.id"
              class="border-b border-surface-100 dark:border-surface-800 last:border-b-0"
            >
              <div class="p-4">
                <!-- Top row: conversation + time -->
                <div class="flex items-center justify-between mb-2">
                  <button 
                    @click="goToConversation(msg)"
                    class="text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors flex items-center gap-1"
                  >
                    <span class="material-symbols-rounded text-sm">chat</span>
                    {{ getConversationName(msg) }}
                  </button>
                  <span class="text-xs text-surface-400 flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">schedule</span>
                    {{ formatScheduledTime(msg.scheduled_at) }}
                  </span>
                </div>

                <!-- Message content -->
                <p class="text-sm text-surface-700 dark:text-surface-300 mb-3">
                  {{ truncateContent(msg.content) }}
                </p>

                <!-- Actions -->
                <div class="flex items-center gap-2">
                  <button
                    @click="cancelMessage(msg.id)"
                    :disabled="cancelling === msg.id"
                    class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-full transition-colors disabled:opacity-50"
                  >
                    <span v-if="cancelling === msg.id" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
                    <span v-else class="material-symbols-rounded text-sm">cancel</span>
                    Cancel
                  </button>
                  <button
                    @click="goToConversation(msg)"
                    class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
                  >
                    <span class="material-symbols-rounded text-sm">open_in_new</span>
                    Go to chat
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Empty state -->
          <div v-else class="text-center py-12 px-6">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3 block">schedule_send</span>
            <p class="text-surface-500 text-sm font-medium">No scheduled messages</p>
            <p class="text-surface-400 text-xs mt-1">
              Use the clock icon next to the send button to schedule a message for later.
            </p>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

