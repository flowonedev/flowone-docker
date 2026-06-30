<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useChatPresence } from '@/composables/useChatPresence'
import ChatMessage from './ChatMessage.vue'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'

const chatStore = useChatStore()
const authStore = useAuthStore()
const toast = useToastStore()
const colleaguesStore = useColleaguesStore()
const { getStatusColor } = useChatPresence()

const replyContent = ref('')
const replyInputRef = ref(null)
const messagesContainerRef = ref(null)
const isSending = ref(false)
const alsoSendToChannel = ref(false)

const isOpen = computed(() => chatStore.activeThreadId !== null)
const loading = computed(() => chatStore.loadingThread)
const threadMessages = computed(() => chatStore.threadMessages)

const parentMessage = computed(() => {
  if (!threadMessages.value.length) return null
  return threadMessages.value[0]
})

const replies = computed(() => {
  if (threadMessages.value.length <= 1) return []
  return threadMessages.value.slice(1)
})

const parentSender = computed(() => {
  if (!parentMessage.value) return null
  return colleaguesStore.colleagueById?.[parentMessage.value.sender_id] || {
    id: parentMessage.value.sender_id,
    display_name: parentMessage.value.sender_name,
    email: parentMessage.value.sender_email || ''
  }
})

// Auto-scroll to bottom when new messages arrive
watch(threadMessages, () => {
  nextTick(() => {
    if (messagesContainerRef.value) {
      messagesContainerRef.value.scrollTop = messagesContainerRef.value.scrollHeight
    }
  })
})

async function sendReply() {
  const text = replyContent.value.trim()
  if (!text || isSending.value) return
  
  isSending.value = true
  const result = await chatStore.sendThreadReply(
    chatStore.activeThreadId,
    text,
    chatStore.activeConversationId,
    alsoSendToChannel.value
  )
  
  if (result.success) {
    replyContent.value = ''
  }
  isSending.value = false
  
  nextTick(() => {
    replyInputRef.value?.focus()
  })
}

function handleKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    sendReply()
  }
}

function close() {
  chatStore.closeThread()
}

// Delete thread
const showDeleteThreadModal = ref(false)

const canDeleteThread = computed(() => {
  if (!parentMessage.value) return false
  return parentMessage.value.sender_email?.toLowerCase() === authStore.userEmail?.toLowerCase()
})

async function confirmDeleteThread() {
  showDeleteThreadModal.value = false
  if (!chatStore.activeThreadId) return
  
  const result = await chatStore.deleteThread(chatStore.activeThreadId)
  if (result.success) {
    toast.success('Thread deleted')
  } else {
    toast.error(result.error || 'Failed to delete thread')
  }
}
</script>

<template>
  <transition
    enter-active-class="transition-transform duration-200 ease-out"
    leave-active-class="transition-transform duration-150 ease-in"
    enter-from-class="translate-x-full"
    enter-to-class="translate-x-0"
    leave-from-class="translate-x-0"
    leave-to-class="translate-x-full"
  >
    <div
      v-if="isOpen"
      class="w-80 lg:w-96 border-l border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex flex-col h-full"
    >
      <!-- Header -->
      <div class="px-4 py-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-xl text-surface-500">forum</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Thread</h3>
          <span v-if="replies.length" class="text-xs text-surface-400">
            {{ replies.length }} {{ replies.length === 1 ? 'reply' : 'replies' }}
          </span>
        </div>
        <div class="flex items-center gap-1">
          <button
            v-if="canDeleteThread && replies.length"
            @click="showDeleteThreadModal = true"
            class="p-1.5 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-full transition-colors"
            title="Delete thread"
          >
            <span class="material-symbols-rounded text-xl text-red-400">delete</span>
          </button>
          <button
            @click="close"
            class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-xl text-surface-400">close</span>
          </button>
        </div>
      </div>
      
      <!-- Loading state -->
      <div v-if="loading" class="flex-1 flex items-center justify-center">
        <span class="material-symbols-rounded text-3xl text-surface-300 animate-spin">progress_activity</span>
      </div>
      
      <!-- Thread messages -->
      <div v-else ref="messagesContainerRef" class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        <!-- Parent message -->
        <div v-if="parentMessage" class="pb-3 mb-3 border-b border-surface-200 dark:border-surface-700">
          <ChatMessage
            :message="parentMessage"
            :participant="parentSender"
            :show-timestamp="true"
            :is-group-chat="true"
          />
        </div>
        
        <!-- Replies -->
        <template v-if="replies.length">
          <ChatMessage
            v-for="msg in replies"
            :key="msg.id"
            :message="msg"
            :show-timestamp="true"
            :is-group-chat="true"
          />
        </template>
        
        <div v-else class="text-center py-8 text-surface-400">
          <span class="material-symbols-rounded text-4xl mb-2 block">forum</span>
          <p class="text-sm">No replies yet</p>
          <p class="text-xs mt-1">Be the first to reply</p>
        </div>
      </div>
      
      <!-- Reply input -->
      <div class="px-3 py-2 border-t border-surface-200 dark:border-[rgb(var(--color-border))] flex-shrink-0">
        <!-- Also send to channel toggle -->
        <div class="flex items-center gap-2 mb-2">
          <button
            @click="alsoSendToChannel = !alsoSendToChannel"
            :class="[
              'relative w-9 h-5 rounded-full transition-colors flex-shrink-0',
              alsoSendToChannel ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
                alsoSendToChannel ? 'left-[18px]' : 'left-0.5'
              ]"
            ></span>
          </button>
          <span class="text-xs text-surface-500">
            Also send to {{ chatStore.activeConversation?.type === 'channel' ? 'channel' : 'conversation' }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <textarea
            ref="replyInputRef"
            v-model="replyContent"
            @keydown="handleKeydown"
            placeholder="Reply in thread..."
            rows="1"
            class="flex-1 px-3 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-[16px] resize-none text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none text-surface-900 dark:text-surface-100"
            style="max-height: 100px;"
          ></textarea>
          <button
            @click="sendReply"
            :disabled="!replyContent.trim() || isSending"
            class="w-9 h-9 rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center justify-center disabled:opacity-40"
          >
            <span v-if="isSending" class="material-symbols-rounded text-xl animate-spin">progress_activity</span>
            <span v-else class="material-symbols-rounded text-xl">send</span>
          </button>
        </div>
      </div>
    </div>
  </transition>
  
  <!-- Delete thread confirmation -->
  <ConfirmModal
    :show="showDeleteThreadModal"
    title="Delete Thread"
    message="This will delete all replies in this thread. The parent message will remain. This action cannot be undone."
    confirmText="Delete"
    confirmColor="red"
    @confirm="confirmDeleteThread"
    @cancel="showDeleteThreadModal = false"
  />
</template>

