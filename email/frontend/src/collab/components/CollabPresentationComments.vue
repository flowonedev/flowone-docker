<template>
  <div class="collab-presentation-comments h-full flex flex-col bg-white border-l border-surface-200">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200">
      <h3 class="font-semibold text-surface-900 flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500" style="font-size: 20px;">comment</span>
        Comments
        <span v-if="totalComments > 0" class="text-sm font-normal text-surface-500">
          ({{ totalComments }})
        </span>
      </h3>
      <div class="flex items-center gap-2">
        <button 
          v-if="canEdit && !isAddingComment"
          @click="startComment(currentSlideIndex)"
          class="flex items-center gap-1.5 px-4 py-1.5 bg-primary-500 hover:bg-primary-600 text-white text-sm rounded-full transition-colors"
          title="Add comment to current slide"
        >
          <span class="material-symbols-rounded" style="font-size: 16px;">add</span>
          Add
        </button>
        <button 
          @click="$emit('close')"
          class="p-1.5 hover:bg-surface-100 rounded-full transition-colors"
        >
          <span class="material-symbols-rounded text-surface-500">close</span>
        </button>
      </div>
    </div>
    
    <!-- Filter tabs -->
    <div class="flex items-center gap-1 px-3 py-2 border-b border-surface-200">
      <button
        @click="filter = 'all'"
        class="px-3 py-1 rounded-full text-sm transition-colors"
        :class="filter === 'all' ? 'bg-primary-500/15 text-primary-600' : 'text-surface-600 hover:bg-surface-100'"
      >
        All
      </button>
      <button
        @click="filter = 'open'"
        class="px-3 py-1 rounded-full text-sm transition-colors"
        :class="filter === 'open' ? 'bg-primary-500/15 text-primary-600' : 'text-surface-600 hover:bg-surface-100'"
      >
        Open
      </button>
      <button
        @click="filter = 'resolved'"
        class="px-3 py-1 rounded-full text-sm transition-colors"
        :class="filter === 'resolved' ? 'bg-primary-500/15 text-primary-600' : 'text-surface-600 hover:bg-surface-100'"
      >
        Resolved
      </button>
      <button
        @click="filter = 'slide'"
        class="px-3 py-1 rounded-full text-sm transition-colors"
        :class="filter === 'slide' ? 'bg-primary-500/15 text-primary-600' : 'text-surface-600 hover:bg-surface-100'"
      >
        This Slide
      </button>
    </div>
    
    <!-- Comments list -->
    <div class="flex-1 overflow-y-auto">
      <!-- Empty state -->
      <div v-if="filteredThreads.length === 0 && !isAddingComment" class="flex flex-col items-center justify-center h-full text-center px-4">
        <span class="material-symbols-rounded text-surface-300 mb-3" style="font-size: 48px;">chat_bubble_outline</span>
        <p class="text-surface-500 text-sm">
          {{ filter === 'slide' ? 'No comments on this slide' : 'No comments yet' }}
        </p>
        <p class="text-surface-400 text-xs mt-1">
          Select an object and click the comment button to add a comment
        </p>
        <button 
          v-if="canEdit"
          @click="startComment(currentSlideIndex)"
          class="mt-4 flex items-center gap-1.5 px-5 py-2 bg-primary-500 hover:bg-primary-600 text-white text-sm rounded-full transition-colors"
        >
          <span class="material-symbols-rounded" style="font-size: 18px;">add_comment</span>
          Add Comment
        </button>
      </div>
      
      <!-- Comment threads -->
      <div v-else class="divide-y divide-surface-100">
        <div
          v-for="thread in filteredThreads"
          :key="thread.id"
          class="p-3 hover:bg-surface-50/50 cursor-pointer transition-colors"
          :class="{ 'opacity-60': thread.resolved }"
          @click="$emit('goto', thread.slideIndex, thread.objectId)"
        >
          <!-- Thread header -->
          <div class="flex items-start gap-2 mb-2">
            <div 
              class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-medium flex-shrink-0"
              :style="{ backgroundColor: thread.author.color }"
            >
              {{ getInitials(thread.author.name) }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-medium text-surface-900 text-sm truncate">
                  {{ thread.author.name }}
                </span>
                <span class="text-surface-400 text-xs flex-shrink-0">
                  {{ formatTime(thread.createdAt) }}
                </span>
                <span 
                  v-if="thread.resolved"
                  class="text-xs px-2 py-0.5 bg-green-100 text-green-600 rounded-full"
                >
                  Resolved
                </span>
              </div>
              <div class="text-xs text-surface-400 flex items-center gap-1 mt-0.5">
                <span class="material-symbols-rounded" style="font-size: 14px;">slideshow</span>
                Slide {{ thread.slideIndex + 1 }}
                <span v-if="thread.objectId" class="ml-1">
                  on object
                </span>
              </div>
            </div>
          </div>
          
          <!-- Comment content -->
          <p class="text-sm text-surface-700 pl-10 line-clamp-2">
            {{ thread.content }}
          </p>
          
          <!-- Reply count -->
          <div 
            v-if="thread.replies && thread.replies.length > 0"
            class="flex items-center gap-1 mt-2 pl-10 text-xs text-surface-500"
          >
            <span class="material-symbols-rounded" style="font-size: 14px;">reply</span>
            {{ thread.replies.length }} {{ thread.replies.length === 1 ? 'reply' : 'replies' }}
          </div>
          
          <!-- Quick actions -->
          <div class="flex items-center gap-1 mt-2 pl-10" v-if="canEdit">
            <button 
              v-if="!thread.resolved"
              @click.stop="resolveThread(thread.id)"
              class="text-xs px-3 py-1 text-surface-500 hover:text-green-600 hover:bg-green-50 rounded-full transition-colors"
            >
              Resolve
            </button>
            <button 
              v-else
              @click.stop="unresolveThread(thread.id)"
              class="text-xs px-3 py-1 text-surface-500 hover:text-primary-600 hover:bg-primary-50 rounded-full transition-colors"
            >
              Reopen
            </button>
            <button 
              @click.stop="replyToThread(thread)"
              class="text-xs px-3 py-1 text-surface-500 hover:text-primary-600 hover:bg-primary-50 rounded-full transition-colors"
            >
              Reply
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- New comment form (when adding) -->
    <div 
      v-if="isAddingComment"
      class="border-t border-surface-200 p-3 bg-primary-50"
    >
      <div class="flex items-center gap-2 mb-3">
        <div 
          class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-medium flex-shrink-0"
          :style="{ backgroundColor: getAvatarColor(user.email) }"
        >
          {{ getInitials(user.name || user.email) }}
        </div>
        <div class="flex-1">
          <div class="text-sm font-medium text-surface-900">{{ user.name || user.email }}</div>
          <div class="text-xs text-surface-500 flex items-center gap-1">
            <span class="material-symbols-rounded" style="font-size: 14px;">slideshow</span>
            Slide {{ pendingComment.slideIndex + 1 }}
            <span v-if="pendingComment.objectId" class="ml-1 text-primary-600">
              on selected object
            </span>
          </div>
        </div>
      </div>
      <textarea
        ref="commentInput"
        v-model="pendingComment.content"
        placeholder="Add your comment..."
        class="w-full px-3 py-2 text-sm border border-surface-200 rounded-xl bg-white text-surface-900 resize-none focus:outline-none focus:ring-2 focus:ring-primary-500"
        rows="3"
        @keydown.enter.ctrl="submitComment"
        @keydown.escape="cancelComment"
      ></textarea>
      <div class="flex items-center justify-between mt-2">
        <span class="text-xs text-surface-400">Ctrl+Enter to submit</span>
        <div class="flex items-center gap-2">
          <button 
            @click="cancelComment"
            class="px-4 py-1.5 text-sm text-surface-600 hover:bg-surface-100 rounded-full transition-colors"
          >
            Cancel
          </button>
          <button 
            @click="submitComment"
            :disabled="!pendingComment.content.trim()"
            class="px-4 py-1.5 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            Comment
          </button>
        </div>
      </div>
    </div>
    
    <!-- Reply form -->
    <div 
      v-else-if="replyingTo"
      class="border-t border-surface-200 p-3"
    >
      <div class="flex items-center gap-2 mb-2 text-sm text-surface-500">
        <span class="material-symbols-rounded" style="font-size: 16px;">reply</span>
        Replying to {{ replyingTo.author.name }}
      </div>
      <textarea
        ref="replyInput"
        v-model="replyContent"
        placeholder="Type your reply..."
        class="w-full px-3 py-2 text-sm border border-surface-200 rounded-xl bg-white text-surface-900 resize-none focus:outline-none focus:ring-2 focus:ring-primary-500"
        rows="2"
        @keydown.enter.ctrl="submitReply"
        @keydown.escape="cancelReply"
      ></textarea>
      <div class="flex items-center justify-end gap-2 mt-2">
        <button 
          @click="cancelReply"
          class="px-4 py-1.5 text-sm text-surface-600 hover:bg-surface-100 rounded-full transition-colors"
        >
          Cancel
        </button>
        <button 
          @click="submitReply"
          :disabled="!replyContent.trim()"
          class="px-4 py-1.5 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          Reply
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue'

const props = defineProps({
  threads: {
    type: Array,
    default: () => [],
  },
  currentSlideIndex: {
    type: Number,
    default: 0,
  },
  canEdit: {
    type: Boolean,
    default: true,
  },
  user: {
    type: Object,
    required: true,
  },
  selectedObjectId: {
    type: String,
    default: null,
  },
  hasSelection: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close', 'goto', 'add', 'resolve', 'unresolve', 'reply'])

// State
const filter = ref('all')
const isAddingComment = ref(false)
const pendingComment = ref({
  slideIndex: 0,
  objectId: null,
  content: '',
  x: 0,
  y: 0,
})
const replyingTo = ref(null)
const replyContent = ref('')
const commentInput = ref(null)
const replyInput = ref(null)

// Computed
const totalComments = computed(() => props.threads.length)

const filteredThreads = computed(() => {
  let threads = [...props.threads]
  
  if (filter.value === 'open') {
    threads = threads.filter(t => !t.resolved)
  } else if (filter.value === 'resolved') {
    threads = threads.filter(t => t.resolved)
  } else if (filter.value === 'slide') {
    threads = threads.filter(t => t.slideIndex === props.currentSlideIndex)
  }
  
  // Sort by date, newest first
  return threads.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt))
})

// Methods
function getInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

function getAvatarColor(email) {
  if (!email) return '#6b7280'
  const colors = ['#F44336', '#E91E63', '#9C27B0', '#673AB7', '#3F51B5', '#2196F3', '#03A9F4', '#00BCD4', '#009688', '#4CAF50', '#8BC34A', '#FF9800']
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = email.charCodeAt(i) + ((hash << 5) - hash)
  return colors[Math.abs(hash) % colors.length]
}

function formatTime(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  
  if (diff < 60000) return 'Just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  if (diff < 604800000) return `${Math.floor(diff / 86400000)}d ago`
  
  return date.toLocaleDateString()
}

function startComment(slideIndex, objectId = null, x = 0, y = 0) {
  isAddingComment.value = true
  pendingComment.value = {
    slideIndex,
    objectId,
    content: '',
    x,
    y,
  }
  
  nextTick(() => {
    commentInput.value?.focus()
  })
}

function cancelComment() {
  isAddingComment.value = false
  pendingComment.value = {
    slideIndex: 0,
    objectId: null,
    content: '',
    x: 0,
    y: 0,
  }
}

function submitComment() {
  if (!pendingComment.value.content.trim()) return
  
  emit('add', {
    slideIndex: pendingComment.value.slideIndex,
    objectId: pendingComment.value.objectId,
    content: pendingComment.value.content.trim(),
    x: pendingComment.value.x,
    y: pendingComment.value.y,
  })
  
  cancelComment()
}

function replyToThread(thread) {
  replyingTo.value = thread
  replyContent.value = ''
  
  nextTick(() => {
    replyInput.value?.focus()
  })
}

function cancelReply() {
  replyingTo.value = null
  replyContent.value = ''
}

function submitReply() {
  if (!replyContent.value.trim() || !replyingTo.value) return
  
  emit('reply', {
    threadId: replyingTo.value.id,
    content: replyContent.value.trim(),
  })
  
  cancelReply()
}

function resolveThread(threadId) {
  emit('resolve', threadId)
}

function unresolveThread(threadId) {
  emit('unresolve', threadId)
}

// Expose for parent component
defineExpose({
  startComment,
})
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>

