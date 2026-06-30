<script setup>
import { ref, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import ChatMessage from './ChatMessage.vue'

const emit = defineEmits(['close', 'navigate-to-message'])

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()

const loading = ref(false)
const removing = ref(null) // bookmark ID being removed

onMounted(async () => {
  await loadBookmarks()
})

async function loadBookmarks() {
  loading.value = true
  await chatStore.fetchBookmarks()
  loading.value = false
}

async function removeBookmark(bookmarkId) {
  removing.value = bookmarkId
  try {
    // Delete the bookmark via store (uses the message_id to toggle)
    const bookmark = chatStore.bookmarks.find(b => b.bookmark_id === bookmarkId)
    if (bookmark) {
      await chatStore.toggleBookmark(bookmark.id)
      // Refresh list
      await chatStore.fetchBookmarks()
    }
  } catch (e) {
    console.error('Failed to remove bookmark:', e)
  } finally {
    removing.value = null
  }
}

function navigateToMessage(bookmark) {
  // Navigate to the conversation and highlight the message
  chatStore.setActiveConversation(bookmark.conversation_id)
  emit('navigate-to-message', {
    conversationId: bookmark.conversation_id,
    messageId: bookmark.id
  })
  emit('close')
}

function getSender(bookmark) {
  return colleaguesStore.colleagueById?.[bookmark.sender_id] || {
    id: bookmark.sender_id,
    display_name: bookmark.sender_name,
    email: bookmark.sender_email || ''
  }
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const isToday = d.toDateString() === now.toDateString()
  
  if (isToday) {
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  }
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + 
    ' at ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg max-h-[80vh] flex flex-col">
    <!-- Header -->
    <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between flex-shrink-0">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-xl text-amber-500">bookmark</span>
        <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Saved Messages</h2>
        <span v-if="chatStore.bookmarks.length" class="text-xs text-surface-400 bg-surface-100 dark:bg-surface-700 px-2 py-0.5 rounded-full">
          {{ chatStore.bookmarks.length }}
        </span>
      </div>
      <button @click="$emit('close')" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors">
        <span class="material-symbols-rounded text-xl text-surface-400">close</span>
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center py-12">
      <span class="material-symbols-rounded text-3xl text-surface-300 animate-spin">progress_activity</span>
    </div>

    <!-- Bookmark list -->
    <div v-else class="flex-1 overflow-y-auto">
      <div v-if="chatStore.bookmarks.length" class="divide-y divide-surface-100 dark:divide-surface-700">
        <div
          v-for="bookmark in chatStore.bookmarks"
          :key="bookmark.bookmark_id || bookmark.id"
          class="px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors group"
        >
          <!-- Conversation context -->
          <div class="flex items-center justify-between mb-2">
            <button
              @click="navigateToMessage(bookmark)"
              class="flex items-center gap-1.5 text-xs text-primary-500 hover:text-primary-600 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">
                {{ bookmark.conversation_type === 'channel' ? 'tag' : bookmark.conversation_type === 'group' ? 'group' : 'chat' }}
              </span>
              <span class="font-medium">{{ bookmark.conversation_name || 'Direct Message' }}</span>
              <span class="material-symbols-rounded text-xs">open_in_new</span>
            </button>
            <div class="flex items-center gap-1">
              <span class="text-xs text-surface-400">{{ formatDate(bookmark.bookmarked_at || bookmark.created_at) }}</span>
              <button
                @click="removeBookmark(bookmark.bookmark_id || bookmark.id)"
                class="p-1 opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-500/20 rounded transition-all"
                :disabled="removing === (bookmark.bookmark_id || bookmark.id)"
                title="Remove bookmark"
              >
                <span v-if="removing === (bookmark.bookmark_id || bookmark.id)" class="material-symbols-rounded text-sm text-surface-400 animate-spin">progress_activity</span>
                <span v-else class="material-symbols-rounded text-sm text-red-500">bookmark_remove</span>
              </button>
            </div>
          </div>

          <!-- Message preview -->
          <div class="pl-0" @click="navigateToMessage(bookmark)">
            <div class="flex items-start gap-2 cursor-pointer">
              <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-surface-600 dark:text-surface-400 mb-0.5">
                  {{ bookmark.sender_name || 'Unknown' }}
                </p>
                <p class="text-sm text-surface-900 dark:text-surface-100 line-clamp-3 whitespace-pre-wrap break-words">
                  {{ bookmark.content }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="flex flex-col items-center justify-center py-12 text-center px-4">
        <div class="w-16 h-16 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center mb-4">
          <span class="material-symbols-rounded text-3xl text-amber-500">bookmark</span>
        </div>
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-1">No saved messages</h3>
        <p class="text-xs text-surface-500 max-w-xs">
          Bookmark important messages to find them easily later. Click the bookmark icon on any message to save it.
        </p>
      </div>
    </div>
  </div>
</template>

