<template>
  <div class="w-[340px] flex-shrink-0 border-l border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex flex-col h-full relative">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">comment</span>
        <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300">Comments</h4>
        <span v-if="totalCount > 0" class="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400">
          {{ totalCount }}
        </span>
      </div>
      <button
        @click="$emit('close')"
        class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
      >
        <span class="material-symbols-rounded text-lg">close</span>
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        @click="activeTab = tab.key"
        class="flex-1 px-3 py-2.5 text-xs font-medium transition-colors border-b-2"
        :class="activeTab === tab.key
          ? 'border-primary-500 text-primary-600 dark:text-primary-400'
          : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
      >
        {{ tab.label }} ({{ tab.count }})
      </button>
    </div>

    <!-- Thread list -->
    <div class="flex-1 overflow-y-auto">
      <div v-if="loading" class="flex items-center justify-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-lg animate-spin mr-2">progress_activity</span>
        <span class="text-xs">Loading...</span>
      </div>

      <div v-else-if="displayedThreads.length === 0" class="flex flex-col items-center justify-center py-12 px-6 text-center">
        <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-3">chat_bubble_outline</span>
        <p class="text-sm text-surface-500 mb-1">No comments yet</p>
        <p class="text-xs text-surface-400">Click on any item on the canvas to leave a comment</p>
      </div>

      <div v-else class="px-3 py-3 space-y-3">
        <div
          v-for="(thread, tIdx) in displayedThreads"
          :key="thread.thread_id"
          class="rounded-xl border cursor-pointer transition-all"
          :class="[
            activeThreadId === thread.thread_id
              ? 'border-primary-400 dark:border-primary-600 bg-primary-50/30 dark:bg-primary-900/10 shadow-sm'
              : 'border-surface-200 dark:border-surface-700 bg-surface-50/50 dark:bg-surface-800/50 hover:border-surface-300 dark:hover:border-surface-600 hover:shadow-sm',
            thread.resolved ? 'opacity-60' : ''
          ]"
          @click="onSelectThread(thread)"
        >
          <!-- Thread header bar -->
          <div class="flex items-center justify-between px-3 py-2 border-b border-surface-200/60 dark:border-surface-700/60">
            <div class="flex items-center gap-1.5">
              <span class="material-symbols-rounded text-xs text-surface-400" style="font-size: 14px;">forum</span>
              <span class="text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide">
                Thread {{ displayedThreads.length - tIdx }}
              </span>
              <span v-if="thread.resolved" class="text-[9px] px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 font-medium">
                Resolved
              </span>
            </div>
            <div class="flex items-center gap-1.5">
              <button
                v-if="isBoardOwner"
                @click.stop="emit('delete-thread', thread.thread_id)"
                class="p-0.5 rounded hover:bg-red-50 dark:hover:bg-red-900/20 text-surface-400 hover:text-red-500 transition-colors"
                title="Delete thread"
              >
                <span class="material-symbols-rounded" style="font-size: 14px;">delete</span>
              </button>
              <span class="text-[10px] text-surface-400">{{ thread.comments.length }} {{ thread.comments.length === 1 ? 'msg' : 'msgs' }}</span>
              <span class="material-symbols-rounded text-xs text-surface-400" style="font-size: 14px;">chevron_right</span>
            </div>
          </div>

          <!-- Stacked message previews (show up to 3, then "N more") -->
          <div class="px-3 py-2 space-y-1.5">
            <div
              v-for="(comment, cIdx) in thread.comments.slice(0, 3)"
              :key="comment.id"
              class="flex items-start gap-2"
            >
              <div
                class="w-5 h-5 rounded-full flex items-center justify-center text-white text-[8px] font-bold flex-shrink-0 mt-0.5"
                :style="{ backgroundColor: avatarColor(comment) }"
              >
                {{ (comment.author_name || '?').charAt(0).toUpperCase() }}
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1">
                  <span class="text-[10px] font-semibold text-surface-700 dark:text-surface-300 truncate">
                    {{ comment.author_name }}
                  </span>
                  <span v-if="comment.is_public" class="text-[8px] px-1 py-px rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 font-medium flex-shrink-0">
                    External
                  </span>
                  <span class="text-[9px] text-surface-400 flex-shrink-0 ml-auto">{{ formatTime(comment.created_at) }}</span>
                </div>
                <p class="text-[11px] text-surface-500 dark:text-surface-400 line-clamp-1 leading-snug">
                  {{ comment.content }}
                </p>
              </div>
            </div>
            <div v-if="thread.comments.length > 3" class="text-[10px] text-primary-500 font-medium pl-7">
              + {{ thread.comments.length - 3 }} more {{ thread.comments.length - 3 === 1 ? 'message' : 'messages' }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- New comment input (board-level) -->
    <div v-if="!activeThread" class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div v-if="isPublic && !guestName" class="space-y-2">
        <div class="flex gap-2">
          <input
            v-model="guestNameInput"
            type="text"
            placeholder="Your name..."
            class="flex-1 px-3 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none focus:ring-1 focus:ring-primary-500"
            @keydown.enter="confirmGuestName"
          />
          <button
            @click="confirmGuestName"
            :disabled="!guestNameInput.trim()"
            class="px-3 py-2 rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none text-xs font-medium"
          >
            Continue
          </button>
        </div>
        <p class="text-[10px] text-surface-400 text-center">Enter your name to start commenting</p>
      </div>
      <div v-else class="flex gap-2">
        <textarea
          ref="newCommentInputRef"
          v-model="newCommentText"
          rows="2"
          placeholder="Add a comment..."
          class="flex-1 px-3 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 resize-none outline-none focus:ring-1 focus:ring-primary-500"
          @keydown.ctrl.enter="submitNewComment"
          @keydown.meta.enter="submitNewComment"
        />
        <button
          @click="submitNewComment"
          :disabled="!newCommentText.trim()"
          class="self-end px-3 py-2 rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none"
        >
          <span class="material-symbols-rounded text-sm">send</span>
        </button>
      </div>
    </div>

    <!-- Expanded thread detail -->
    <transition name="slide-up">
      <div
        v-if="activeThread"
        class="absolute inset-0 bg-white dark:bg-surface-800 flex flex-col z-10"
      >
        <!-- Thread header -->
        <div class="flex items-center gap-2 px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <button
            @click="activeThreadId = null"
            class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400"
          >
            <span class="material-symbols-rounded text-lg">arrow_back</span>
          </button>
          <span class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex-1">Thread</span>
          <button
            v-if="isBoardOwner"
            @click="emit('delete-thread', activeThread.thread_id)"
            class="px-2.5 py-1 text-[11px] font-medium rounded-full bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 transition-colors flex items-center gap-1"
            title="Delete entire thread"
          >
            <span class="material-symbols-rounded text-sm">delete</span>
            Delete
          </button>
          <button
            v-if="!activeThread.resolved"
            @click="onResolve(activeThread.thread_id)"
            class="px-2.5 py-1 text-[11px] font-medium rounded-full bg-green-500/10 text-green-600 dark:text-green-400 hover:bg-green-500/20 transition-colors flex items-center gap-1"
          >
            <span class="material-symbols-rounded text-sm">check_circle</span>
            Resolve
          </button>
          <button
            v-else
            @click="onUnresolve(activeThread.thread_id)"
            class="px-2.5 py-1 text-[11px] font-medium rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors flex items-center gap-1"
          >
            <span class="material-symbols-rounded text-sm">refresh</span>
            Re-open
          </button>
        </div>

        <!-- Comments in thread (chat bubble layout) -->
        <div ref="threadScrollRef" class="flex-1 overflow-y-auto px-3 py-3 space-y-3">
          <div
            v-for="(comment, idx) in activeThread.comments"
            :key="comment.id"
            class="flex gap-2"
            :class="isOwnComment(comment) ? 'flex-row-reverse' : 'flex-row'"
          >
            <!-- Avatar -->
            <div
              class="w-7 h-7 rounded-full flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"
              :style="{ backgroundColor: avatarColor(comment) }"
            >
              {{ (comment.author_name || '?').charAt(0).toUpperCase() }}
            </div>

            <!-- Bubble + meta -->
            <div class="max-w-[78%] min-w-0">
              <!-- Name (show if first message or different author from previous) -->
              <div
                v-if="idx === 0 || activeThread.comments[idx - 1]?.author_name !== comment.author_name"
                class="text-[11px] font-semibold mb-0.5 px-1"
                :class="isOwnComment(comment) ? 'text-right text-primary-500' : 'text-left text-surface-600 dark:text-surface-400'"
              >
                {{ comment.author_name }}
                <span v-if="comment.is_public" class="text-[9px] font-normal text-amber-500 ml-1">Guest</span>
              </div>

              <!-- Bubble -->
              <div
                class="px-3 py-2 rounded-2xl text-xs leading-relaxed whitespace-pre-wrap"
                :class="isOwnComment(comment)
                  ? 'bg-primary-500 text-white rounded-tr-sm'
                  : 'bg-surface-100 dark:bg-surface-700 text-surface-800 dark:text-surface-200 rounded-tl-sm'"
              >
                {{ comment.content }}
              </div>

              <!-- Timestamp + actions -->
              <div
                class="flex items-center gap-1.5 mt-0.5 px-1"
                :class="isOwnComment(comment) ? 'justify-end' : 'justify-start'"
              >
                <span class="text-[9px] text-surface-400">{{ formatTime(comment.created_at) }}</span>
                <button
                  v-if="canDelete(comment)"
                  @click="onDelete(comment.id)"
                  class="text-[9px] text-surface-400 hover:text-red-500 transition-colors flex items-center gap-0.5"
                  title="Delete"
                >
                  <span class="material-symbols-rounded" style="font-size: 11px;">delete</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Reply input -->
        <div v-if="!activeThread.resolved" class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div v-if="isPublic && !guestName" class="flex gap-2">
            <input
              v-model="guestNameInput"
              type="text"
              placeholder="Your name..."
              class="flex-1 px-3 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none focus:ring-1 focus:ring-primary-500"
              @keydown.enter="confirmGuestName"
            />
            <button
              @click="confirmGuestName"
              :disabled="!guestNameInput.trim()"
              class="px-3 py-2 rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none text-xs font-medium"
            >
              Continue
            </button>
          </div>
          <div v-else class="flex gap-2">
            <textarea
              ref="replyInputRef"
              v-model="replyText"
              rows="2"
              placeholder="Reply..."
              class="flex-1 px-3 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 resize-none outline-none focus:ring-1 focus:ring-primary-500"
              @keydown.ctrl.enter="submitReply"
              @keydown.meta.enter="submitReply"
            />
            <button
              @click="submitReply"
              :disabled="!replyText.trim()"
              class="self-end px-3 py-2 rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none"
            >
              <span class="material-symbols-rounded text-sm">send</span>
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'

const props = defineProps({
  threads: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  isPublic: { type: Boolean, default: false },
  currentUserEmail: { type: String, default: '' },
  isBoardOwner: { type: Boolean, default: false },
  selectedThreadId: { type: String, default: null },
})

const emit = defineEmits([
  'close',
  'add-comment',
  'delete-comment',
  'delete-thread',
  'resolve-thread',
  'unresolve-thread',
  'select-thread',
  'focus-thread',
])

const activeTab = ref('all')
const activeThreadId = ref(null)
const replyText = ref('')
const replyInputRef = ref(null)
const newCommentText = ref('')
const newCommentInputRef = ref(null)
const threadScrollRef = ref(null)

const guestNameInput = ref('')
const guestName = ref(localStorage.getItem('mood_comment_guest_name') || '')

watch(guestName, (val) => {
  if (val) localStorage.setItem('mood_comment_guest_name', val)
})

watch(() => props.selectedThreadId, (tid) => {
  if (tid && tid !== activeThreadId.value) {
    activeThreadId.value = tid
    nextTick(() => scrollThreadToBottom())
  }
}, { immediate: true })

const openThreads = computed(() => props.threads.filter(t => !t.resolved))
const resolvedThreads = computed(() => props.threads.filter(t => t.resolved))
const totalCount = computed(() => props.threads.reduce((sum, t) => sum + t.comments.length, 0))

const tabs = computed(() => [
  { key: 'all', label: 'All', count: props.threads.length },
  { key: 'open', label: 'Open', count: openThreads.value.length },
  { key: 'resolved', label: 'Resolved', count: resolvedThreads.value.length },
])

const displayedThreads = computed(() => {
  if (activeTab.value === 'open') return openThreads.value
  if (activeTab.value === 'resolved') return resolvedThreads.value
  return props.threads
})

const activeThread = computed(() =>
  activeThreadId.value
    ? props.threads.find(t => t.thread_id === activeThreadId.value)
    : null
)

function onSelectThread(thread) {
  activeThreadId.value = thread.thread_id
  emit('select-thread', thread)
  emit('focus-thread', thread)
  nextTick(() => {
    replyInputRef.value?.focus()
    scrollThreadToBottom()
  })
}

function scrollThreadToBottom() {
  nextTick(() => {
    if (threadScrollRef.value) {
      threadScrollRef.value.scrollTop = threadScrollRef.value.scrollHeight
    }
  })
}

const AVATAR_COLORS = ['#6366f1','#ec4899','#14b8a6','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#84cc16','#f97316','#10b981']
function avatarColor(comment) {
  const key = (comment.author_email || comment.author_name || '?').toLowerCase().trim()
  let hash = 0
  for (let i = 0; i < key.length; i++) hash = ((hash << 5) - hash) + key.charCodeAt(i)
  return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length]
}

function isOwnComment(comment) {
  // Authenticated user: match by email only
  if (props.currentUserEmail) {
    if (!comment.author_email) return false
    return comment.author_email.toLowerCase() === props.currentUserEmail.toLowerCase()
  }
  // Public/guest user: match by guest name
  if (props.isPublic) {
    const gn = guestName.value || localStorage.getItem('mood_comment_guest_name')
    if (gn && comment.author_name && comment.is_public) {
      return comment.author_name.toLowerCase() === gn.toLowerCase()
    }
  }
  return false
}

function canDelete(comment) {
  if (props.isBoardOwner) return true
  if (!props.currentUserEmail) return false
  return comment.author_email && comment.author_email.toLowerCase() === props.currentUserEmail.toLowerCase()
}

function onDelete(commentId) {
  emit('delete-comment', commentId)
}

function onResolve(threadId) {
  emit('resolve-thread', threadId)
}

function onUnresolve(threadId) {
  emit('unresolve-thread', threadId)
}

function submitReply() {
  const text = replyText.value.trim()
  if (!text) return
  if (props.isPublic && !guestName.value) return

  emit('add-comment', {
    thread_id: activeThreadId.value,
    content: text,
    author_name: props.isPublic ? guestName.value : undefined,
  })
  replyText.value = ''
  scrollThreadToBottom()
}

function confirmGuestName() {
  const name = guestNameInput.value.trim()
  if (name) {
    guestName.value = name
    nextTick(() => newCommentInputRef.value?.focus())
  }
}

function submitNewComment() {
  const text = newCommentText.value.trim()
  if (!text) return
  if (props.isPublic && !guestName.value) return

  emit('add-comment', {
    content: text,
    item_id: null,
    author_name: props.isPublic ? guestName.value : undefined,
  })
  newCommentText.value = ''
}

function formatTime(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now - d
  const diffMin = Math.floor(diffMs / 60000)
  if (diffMin < 1) return 'Just now'
  if (diffMin < 60) return `${diffMin}m ago`
  const diffHours = Math.floor(diffMin / 60)
  if (diffHours < 24) return `${diffHours}h ago`
  const diffDays = Math.floor(diffHours / 24)
  if (diffDays < 7) return `${diffDays}d ago`
  return d.toLocaleDateString()
}
</script>

<style scoped>
.slide-up-enter-active { transition: transform 0.2s ease-out; }
.slide-up-leave-active { transition: transform 0.15s ease-in; }
.slide-up-enter-from { transform: translateX(100%); }
.slide-up-leave-to { transform: translateX(100%); }
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
