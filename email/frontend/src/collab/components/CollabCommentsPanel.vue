<template>
  <div class="collab-comments-panel" style="background: #ffffff; border-left: 1px solid #e2e8f0;">
    <!-- Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; background: #ffffff;">
      <h2 style="font-size: 16px; font-weight: 600; color: #18181b; margin: 0;">Comments</h2>
      <button 
        @click="$emit('close')"
        style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border: none; background: transparent; border-radius: 8px; cursor: pointer; color: #71717a;"
        onmouseover="this.style.background='#f4f4f5'"
        onmouseout="this.style.background='transparent'"
      >
        <span class="material-symbols-rounded" style="font-size: 20px;">close</span>
      </button>
    </div>
    
    <!-- Tabs -->
    <div style="display: flex; border-bottom: 1px solid #e2e8f0; background: #ffffff;">
      <button 
        @click="activeTab = 'all'"
        style="flex: 1; padding: 10px 16px; font-size: 13px; font-weight: 500; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid transparent;"
        :style="activeTab === 'all' ? { borderColor: 'rgb(var(--color-primary-500))', color: 'rgb(var(--color-primary-500))' } : { color: '#71717a' }"
      >
        All ({{ allComments.length }})
      </button>
      <button 
        @click="activeTab = 'open'"
        style="flex: 1; padding: 10px 16px; font-size: 13px; font-weight: 500; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid transparent;"
        :style="activeTab === 'open' ? { borderColor: 'rgb(var(--color-primary-500))', color: 'rgb(var(--color-primary-500))' } : { color: '#71717a' }"
      >
        Open ({{ openThreads.length }})
      </button>
      <button 
        @click="activeTab = 'resolved'"
        style="flex: 1; padding: 10px 16px; font-size: 13px; font-weight: 500; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid transparent;"
        :style="activeTab === 'resolved' ? { borderColor: 'rgb(var(--color-primary-500))', color: 'rgb(var(--color-primary-500))' } : { color: '#71717a' }"
      >
        Resolved ({{ resolvedThreads.length }})
      </button>
    </div>
    
    <!-- Comments list -->
    <div style="flex: 1; overflow-y: auto; background: #ffffff;">
      <!-- Empty state -->
      <div 
        v-if="displayedThreads.length === 0" 
        style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; padding: 48px 24px;"
      >
        <span class="material-symbols-rounded" style="font-size: 48px; color: #d4d4d8; margin-bottom: 12px;">comment</span>
        <p style="color: #71717a; margin: 0 0 4px 0;">No comments yet</p>
        <p style="color: #a1a1aa; font-size: 13px; margin: 0;">Select text in the document and click the comment icon to add a comment</p>
      </div>
      
      <!-- Thread list -->
      <div v-else>
        <div 
          v-for="thread in displayedThreads"
          :key="thread.id"
          style="padding: 16px; cursor: pointer; transition: background 0.15s; border-bottom: 1px solid #f4f4f5;"
          :style="{ 
            background: selectedThreadId === thread.id ? '#eff6ff' : '#ffffff',
            opacity: thread.resolved ? 0.6 : 1
          }"
          @click="selectThread(thread)"
        >
          <!-- Thread header with quoted text -->
          <div v-if="thread.quotedText" style="margin-bottom: 12px;">
            <div style="font-size: 11px; color: #a1a1aa; margin-bottom: 4px;">Commenting on:</div>
            <div :style="{ background: 'rgb(var(--color-primary-50))', borderLeft: '3px solid rgb(var(--color-primary-500))', padding: '8px 12px', fontSize: '13px', color: '#3f3f46', fontStyle: 'italic', borderRadius: '0 8px 8px 0' }">
              "{{ thread.quotedText }}"
            </div>
          </div>
          
          <!-- Comments in thread -->
          <div v-for="(comment, idx) in thread.comments" :key="comment.id" style="margin-bottom: 12px;">
            <div style="display: flex; align-items: flex-start; gap: 10px;">
              <!-- Avatar -->
              <div 
                style="width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: 600; flex-shrink: 0;"
                :style="{ backgroundColor: getAvatarColor(comment.author.email) }"
              >
                {{ getInitials(comment.author.name || comment.author.email) }}
              </div>
              
              <!-- Content -->
              <div style="flex: 1; min-width: 0;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 2px;">
                  <span style="font-weight: 500; font-size: 13px; color: #18181b;">{{ comment.author.name || comment.author.email }}</span>
                  <span style="font-size: 11px; color: #a1a1aa;">{{ formatTime(comment.createdAt) }}</span>
                </div>
                <p style="font-size: 13px; color: #3f3f46; margin: 0; line-height: 1.5;">{{ comment.content }}</p>
              </div>
            </div>
          </div>
          
          <!-- Reply input (only for selected thread) -->
          <div 
            v-if="selectedThreadId === thread.id && !thread.resolved" 
            style="margin-top: 16px;"
            @click.stop
          >
            <div style="display: flex; align-items: flex-start; gap: 10px;">
              <div 
                style="width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: 600; flex-shrink: 0;"
                :style="{ backgroundColor: getAvatarColor(user.email) }"
              >
                {{ getInitials(user.name || user.email) }}
              </div>
              <div style="flex: 1;">
                <textarea
                  ref="replyInput"
                  v-model="replyText"
                  placeholder="Reply..."
                  rows="2"
                  style="width: 100%; padding: 10px 12px; font-size: 13px; border: 1px solid #e4e4e7; border-radius: 8px; background: #ffffff; color: #18181b; resize: none; outline: none; font-family: inherit;"
                  @click.stop
                  @mousedown.stop
                  @keydown.enter.ctrl="addReply(thread)"
                  @keydown.enter.meta="addReply(thread)"
                  @focus="$event.target.style.borderColor = 'rgb(var(--color-primary-500))'"
                  @blur="$event.target.style.borderColor = '#e4e4e7'"
                ></textarea>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 8px;">
                  <button 
                    @click.stop="resolveThread(thread)"
                    style="font-size: 12px; color: #71717a; background: none; border: none; cursor: pointer; padding: 0;"
                  >
                    Mark as resolved
                  </button>
                  <button 
                    @click.stop="addReply(thread)"
                    :disabled="!replyText.trim()"
                    :style="{ padding: '6px 14px', fontSize: '13px', fontWeight: '500', background: 'rgb(var(--color-primary-500))', color: 'white', border: 'none', borderRadius: '6px', cursor: replyText.trim() ? 'pointer' : 'not-allowed', opacity: replyText.trim() ? 1 : 0.5 }"
                  >
                    Reply
                  </button>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Resolved badge -->
          <div v-if="thread.resolved" style="margin-top: 12px; display: flex; align-items: center; gap: 4px; font-size: 12px; color: #22c55e;">
            <span class="material-symbols-rounded" style="font-size: 14px;">check_circle</span>
            Resolved
            <button 
              @click.stop="unresolveThread(thread)"
              style="margin-left: 8px; color: #a1a1aa; background: none; border: none; cursor: pointer; font-size: 12px;"
            >
              Re-open
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Add comment (appears when text is selected) -->
    <div v-if="hasSelection && !selectedThreadId" style="padding: 16px; border-top: 1px solid #e2e8f0; background: #ffffff;">
      <div style="background: #f8fafc; border-radius: 12px; padding: 16px;">
        <div style="font-size: 11px; color: #71717a; margin-bottom: 6px;">Selected text:</div>
        <div :style="{ fontSize: '13px', color: 'rgb(var(--color-primary-500))', fontStyle: 'italic', marginBottom: '12px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }">"{{ selectedText }}"</div>
        <textarea
          v-model="newCommentText"
          placeholder="Add a comment..."
          rows="2"
          style="width: 100%; padding: 10px 12px; font-size: 13px; border: 1px solid #e4e4e7; border-radius: 8px; background: #ffffff; color: #18181b; resize: none; outline: none; font-family: inherit;"
          @keydown.enter.ctrl="addNewComment"
          @keydown.enter.meta="addNewComment"
          @focus="$event.target.style.borderColor = 'rgb(var(--color-primary-500))'"
          @blur="$event.target.style.borderColor = '#e4e4e7'"
        ></textarea>
        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px;">
          <button 
            @click="cancelNewComment"
            style="padding: 6px 14px; font-size: 13px; font-weight: 500; background: #f4f4f5; color: #3f3f46; border: none; border-radius: 6px; cursor: pointer;"
          >
            Cancel
          </button>
          <button 
            @click="addNewComment"
            :disabled="!newCommentText.trim()"
            :style="{ padding: '6px 14px', fontSize: '13px', fontWeight: '500', background: 'rgb(var(--color-primary-500))', color: 'white', border: 'none', borderRadius: '6px', cursor: newCommentText.trim() ? 'pointer' : 'not-allowed', opacity: newCommentText.trim() ? 1 : 0.5 }"
          >
            Comment
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  threads: { type: Array, default: () => [] },
  user: { type: Object, required: true },
  selectedText: { type: String, default: '' },
  hasSelection: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'add-comment', 'add-reply', 'resolve', 'unresolve', 'select-thread'])

const activeTab = ref('all')
const selectedThreadId = ref(null)
const replyText = ref('')
const newCommentText = ref('')

const allComments = computed(() => props.threads.flatMap(t => t.comments))
const openThreads = computed(() => props.threads.filter(t => !t.resolved))
const resolvedThreads = computed(() => props.threads.filter(t => t.resolved))

const displayedThreads = computed(() => {
  switch (activeTab.value) {
    case 'open': return openThreads.value
    case 'resolved': return resolvedThreads.value
    default: return props.threads
  }
})

function selectThread(thread) {
  if (selectedThreadId.value === thread.id) {
    selectedThreadId.value = null
  } else {
    selectedThreadId.value = thread.id
    replyText.value = ''
    emit('select-thread', thread)
  }
}

function addReply(thread) {
  if (!replyText.value.trim()) return
  emit('add-reply', { threadId: thread.id, content: replyText.value.trim() })
  replyText.value = ''
}

function addNewComment() {
  if (!newCommentText.value.trim() || !props.hasSelection) return
  emit('add-comment', { content: newCommentText.value.trim(), quotedText: props.selectedText })
  newCommentText.value = ''
}

function cancelNewComment() {
  newCommentText.value = ''
}

function resolveThread(thread) {
  emit('resolve', thread.id)
  selectedThreadId.value = null
}

function unresolveThread(thread) {
  emit('unresolve', thread.id)
}

function getInitials(name) {
  return name.split(/[\s@]/).slice(0, 2).map(n => n[0]).join('').toUpperCase()
}

function getAvatarColor(email) {
  const colors = ['#F44336', '#E91E63', '#9C27B0', '#673AB7', '#3F51B5', '#2196F3', '#03A9F4', '#00BCD4', '#009688', '#4CAF50', '#8BC34A', '#FF9800']
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = email.charCodeAt(i) + ((hash << 5) - hash)
  return colors[Math.abs(hash) % colors.length]
}

function formatTime(timestamp) {
  if (!timestamp) return ''
  const date = new Date(timestamp)
  const now = new Date()
  const diff = now - date
  if (diff < 60000) return 'Just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  if (diff < 604800000) return `${Math.floor(diff / 86400000)}d ago`
  return date.toLocaleDateString()
}
</script>

<style scoped>
.collab-comments-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  width: 320px;
  min-width: 280px;
  max-width: 400px;
}
</style>
