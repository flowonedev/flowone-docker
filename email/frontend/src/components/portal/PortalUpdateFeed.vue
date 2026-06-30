<script setup>
/**
 * PortalUpdateFeed - Single update card in the portal feed
 * Shows title, type badge, preview text, file/comment counts, and read status.
 */
import { computed, onMounted, ref } from 'vue'

const props = defineProps({
  update: { type: Object, required: true }
})

const emit = defineEmits(['click', 'markRead'])
const visible = ref(false)
const cardRef = ref(null)

const isRead = computed(() => !!props.update.read_at)
const isPinned = computed(() => !!props.update.is_pinned)
const commentCount = computed(() => props.update.comment_count || 0)
const fileCount = computed(() => props.update.file_count || 0)

const typeBadge = computed(() => {
  const map = {
    general: { label: 'Update', color: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' },
    design: { label: 'Design', color: 'bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' },
    milestone: { label: 'Milestone', color: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300' },
    deliverable: { label: 'Deliverable', color: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' }
  }
  return map[props.update.update_type] || map.general
})

const previewText = computed(() => {
  const text = props.update.content_text || ''
  return text.length > 200 ? text.slice(0, 200) + '...' : text
})

function formatDate(d) {
  if (!d) return ''
  const date = new Date(d)
  const now = new Date()
  const diff = now - date
  if (diff < 60000) return 'just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  if (diff < 604800000) return `${Math.floor(diff / 86400000)}d ago`
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

// Auto-mark as read when card becomes visible
onMounted(() => {
  if (cardRef.value && !isRead.value) {
    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting && !isRead.value) {
        // Delay marking as read to ensure intentional viewing
        setTimeout(() => {
          if (!isRead.value) emit('markRead')
        }, 2000)
        observer.disconnect()
      }
    }, { threshold: 0.5 })
    observer.observe(cardRef.value)
  }
})
</script>

<template>
  <div 
    ref="cardRef"
    @click="emit('click')"
    :class="['relative bg-white dark:bg-surface-800 rounded-xl border p-5 cursor-pointer transition-all hover:shadow-md group',
      isRead 
        ? 'border-surface-200 dark:border-surface-700' 
        : 'border-primary-300 dark:border-primary-500/40 bg-primary-50/30 dark:bg-primary-500/5']"
  >
    <!-- Unread indicator -->
    <div v-if="!isRead" class="absolute top-4 right-4 w-2.5 h-2.5 rounded-full bg-primary-500 animate-pulse"></div>

    <!-- Pinned badge -->
    <div v-if="isPinned" class="absolute top-4 right-4 text-amber-500">
      <span class="material-symbols-rounded text-lg">push_pin</span>
    </div>

    <!-- Type badge + date -->
    <div class="flex items-center gap-2 mb-2">
      <span :class="['text-xs font-medium px-2 py-0.5 rounded-full', typeBadge.color]">
        {{ typeBadge.label }}
      </span>
      <span class="text-xs text-surface-400">{{ formatDate(update.created_at) }}</span>
    </div>

    <!-- Title -->
    <h3 class="text-base font-semibold text-surface-900 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
      {{ update.title }}
    </h3>

    <!-- Preview text -->
    <p v-if="previewText" class="text-sm text-surface-600 dark:text-surface-400 mt-1.5 line-clamp-2">
      {{ previewText }}
    </p>

    <!-- Meta row -->
    <div class="flex items-center gap-4 mt-3 text-xs text-surface-400">
      <span v-if="commentCount > 0" class="flex items-center gap-1">
        <span class="material-symbols-rounded text-sm">chat_bubble</span>
        {{ commentCount }} comment{{ commentCount !== 1 ? 's' : '' }}
      </span>
      <span v-if="fileCount > 0" class="flex items-center gap-1">
        <span class="material-symbols-rounded text-sm">attach_file</span>
        {{ fileCount }} file{{ fileCount !== 1 ? 's' : '' }}
      </span>
      <span v-if="update.mood_board_id" class="flex items-center gap-1">
        <span class="material-symbols-rounded text-sm">dashboard_customize</span>
        Mood Board
      </span>
      <span v-if="update.board_id" class="flex items-center gap-1">
        <span class="material-symbols-rounded text-sm">dashboard</span>
        Board
      </span>
    </div>
  </div>
</template>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>

