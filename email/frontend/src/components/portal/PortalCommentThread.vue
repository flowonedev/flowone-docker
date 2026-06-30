<script setup>
/**
 * PortalCommentThread - Threaded comment display and input
 * Used in both portal update detail view and the CRM internal view.
 * Supports threaded replies via parent_comment_id.
 */
import { ref, computed } from 'vue'

const props = defineProps({
  comments: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  currentUserEmail: { type: String, default: '' },
  isPortalUser: { type: Boolean, default: true }
})

const emit = defineEmits(['add-comment', 'reply'])

const newComment = ref('')
const replyingTo = ref(null)
const replyText = ref('')

// Build a threaded structure from flat comment list
const threadedComments = computed(() => {
  const rootComments = props.comments.filter(c => !c.parent_comment_id)
  const childMap = {}
  for (const c of props.comments) {
    if (c.parent_comment_id) {
      if (!childMap[c.parent_comment_id]) childMap[c.parent_comment_id] = []
      childMap[c.parent_comment_id].push(c)
    }
  }
  return rootComments.map(c => ({
    ...c,
    replies: childMap[c.id] || []
  }))
})

function formatDate(d) {
  if (!d) return ''
  const date = new Date(d)
  const now = new Date()
  const diff = now - date
  if (diff < 60000) return 'just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function submitComment() {
  const text = newComment.value.trim()
  if (!text) return
  emit('add-comment', { content: text, parent_comment_id: null })
  newComment.value = ''
}

function submitReply(parentId) {
  const text = replyText.value.trim()
  if (!text) return
  emit('add-comment', { content: text, parent_comment_id: parentId })
  replyText.value = ''
  replyingTo.value = null
}

function startReply(comment) {
  replyingTo.value = comment.id
  replyText.value = ''
}

function cancelReply() {
  replyingTo.value = null
  replyText.value = ''
}
</script>

<template>
  <div class="space-y-4">
    <!-- Comments List -->
    <div v-if="threadedComments.length > 0" class="space-y-3">
      <div v-for="comment in threadedComments" :key="comment.id">
        <!-- Root Comment -->
        <div class="flex gap-3">
          <div :class="['w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold',
            comment.author_type === 'internal' 
              ? 'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400' 
              : 'bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400']">
            {{ (comment.author_name || comment.author_email || '?')[0].toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-surface-800 dark:text-surface-200">
                {{ comment.author_name || comment.author_email }}
              </span>
              <span v-if="comment.author_type === 'internal'" 
                    class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400">
                Team
              </span>
              <span class="text-xs text-surface-400">{{ formatDate(comment.created_at) }}</span>
            </div>
            <p class="text-sm text-surface-700 dark:text-surface-300 mt-1 whitespace-pre-wrap">{{ comment.content_text }}</p>
            <button @click="startReply(comment)" class="text-xs text-surface-400 hover:text-primary-500 mt-1">
              Reply
            </button>
          </div>
        </div>

        <!-- Replies -->
        <div v-if="comment.replies.length > 0" class="ml-11 mt-2 space-y-2 border-l-2 border-surface-200 dark:border-surface-700 pl-4">
          <div v-for="reply in comment.replies" :key="reply.id" class="flex gap-3">
            <div :class="['w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold',
              reply.author_type === 'internal' 
                ? 'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400' 
                : 'bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400']">
              {{ (reply.author_name || reply.author_email || '?')[0].toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-sm font-medium text-surface-800 dark:text-surface-200">
                  {{ reply.author_name || reply.author_email }}
                </span>
                <span v-if="reply.author_type === 'internal'" 
                      class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400">
                  Team
                </span>
                <span class="text-xs text-surface-400">{{ formatDate(reply.created_at) }}</span>
              </div>
              <p class="text-sm text-surface-700 dark:text-surface-300 mt-0.5 whitespace-pre-wrap">{{ reply.content_text }}</p>
            </div>
          </div>
        </div>

        <!-- Reply Input -->
        <div v-if="replyingTo === comment.id" class="ml-11 mt-2 pl-4">
          <div class="flex gap-2">
            <textarea 
              v-model="replyText"
              @keydown.enter.meta="submitReply(comment.id)"
              @keydown.enter.ctrl="submitReply(comment.id)"
              rows="2"
              placeholder="Write a reply..."
              class="flex-1 px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 
                     bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 outline-none resize-none"
            ></textarea>
          </div>
          <div class="flex justify-end gap-2 mt-2">
            <button @click="cancelReply" class="text-xs text-surface-400 hover:text-surface-600">Cancel</button>
            <button @click="submitReply(comment.id)" :disabled="!replyText.trim()"
                    class="px-3 py-1 rounded-lg bg-primary-600 text-white text-xs font-medium hover:bg-primary-700 disabled:opacity-50">
              Reply
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty -->
    <p v-else class="text-sm text-surface-400 text-center py-4">No comments yet. Be the first to comment!</p>

    <!-- New Comment Input -->
    <div class="border-t border-surface-200 dark:border-surface-700 pt-4">
      <div class="flex gap-3">
        <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-sm text-primary-600 dark:text-primary-400">person</span>
        </div>
        <div class="flex-1">
          <textarea 
            v-model="newComment"
            @keydown.enter.meta="submitComment"
            @keydown.enter.ctrl="submitComment"
            rows="3"
            placeholder="Write a comment..."
            class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 
                   bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                   focus:ring-2 focus:ring-primary-500 outline-none resize-none"
          ></textarea>
          <div class="flex justify-between items-center mt-2">
            <span class="text-xs text-surface-400">⌘+Enter to submit</span>
            <button @click="submitComment" :disabled="!newComment.trim() || loading"
                    class="px-4 py-1.5 rounded-lg bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 disabled:opacity-50 transition-colors">
              {{ loading ? 'Posting...' : 'Comment' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

