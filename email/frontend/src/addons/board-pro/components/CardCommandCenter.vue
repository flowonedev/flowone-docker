<template>
  <div class="boardpro-command-center">
    <div v-if="total > 0" class="mb-2">
      <span class="text-xs text-surface-500 dark:text-surface-400">{{ total }} events</span>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-6">
      <span class="material-symbols-rounded animate-spin text-gray-400">progress_activity</span>
    </div>

    <div v-else-if="timeline.length === 0" class="text-xs text-gray-400 dark:text-gray-500 py-4 text-center">
      No timeline events yet
    </div>

    <div v-else class="space-y-0 max-h-80 overflow-y-auto pr-1">
      <div
        v-for="event in timeline"
        :key="event.id"
        class="relative flex items-start gap-2.5 py-2 group"
      >
        <!-- Timeline line -->
        <div class="absolute left-[11px] top-8 bottom-0 w-px bg-gray-200 dark:bg-gray-700 group-last:hidden"></div>

        <!-- Icon -->
        <div
          class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 z-10"
          :class="eventBgClass(event.type)"
        >
          <span class="material-symbols-rounded text-xs text-white">{{ event.icon }}</span>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5">
            <span class="text-xs font-medium" :class="eventTypeColor(event.type)">
              {{ eventTypeLabel(event.type) }}
            </span>
            <span class="text-xs text-gray-400">{{ formatDate(event.date) }}</span>
          </div>
          <p class="text-xs text-gray-700 dark:text-gray-300 mt-0.5 line-clamp-2">
            {{ stripHtml(event.details) }}
          </p>
          <p v-if="event.user" class="text-xs text-gray-400 mt-0.5">{{ event.user }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useBoardProStore } from '../stores/boardPro'

const props = defineProps({
  cardId: { type: Number, required: true },
})

const store = useBoardProStore()

const loading = computed(() => store.cardTimelineLoading)
const timeline = computed(() => store.cardTimeline)
const total = computed(() => store.cardTimelineTotal)

function eventBgClass(type) {
  switch (type) {
    case 'email': return 'bg-blue-500'
    case 'comment': return 'bg-green-500'
    case 'attachment': return 'bg-purple-500'
    case 'time_tracking': return 'bg-orange-500'
    case 'invoice': return 'bg-emerald-600'
    default: return 'bg-gray-400'
  }
}

function eventTypeColor(type) {
  switch (type) {
    case 'email': return 'text-blue-600 dark:text-blue-400'
    case 'comment': return 'text-green-600 dark:text-green-400'
    case 'attachment': return 'text-purple-600 dark:text-purple-400'
    case 'time_tracking': return 'text-orange-600 dark:text-orange-400'
    case 'invoice': return 'text-emerald-600 dark:text-emerald-400'
    default: return 'text-gray-600 dark:text-gray-400'
  }
}

function eventTypeLabel(type) {
  switch (type) {
    case 'email': return 'Email'
    case 'comment': return 'Comment'
    case 'attachment': return 'File'
    case 'time_tracking': return 'Time'
    case 'invoice': return 'Invoice'
    case 'activity': return 'Activity'
    default: return type
  }
}

function stripHtml(str) {
  if (!str) return ''
  return str
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<\/div>/gi, ' ')
    .replace(/<\/p>/gi, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .replace(/\s{2,}/g, ' ')
    .trim()
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now - d
  const diffMin = Math.floor(diffMs / 60000)
  if (diffMin < 1) return 'Just now'
  if (diffMin < 60) return `${diffMin}m ago`
  const diffH = Math.floor(diffMin / 60)
  if (diffH < 24) return `${diffH}h ago`
  const diffD = Math.floor(diffH / 24)
  if (diffD < 7) return `${diffD}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

onMounted(() => {
  store.fetchCardTimeline(props.cardId)
})
</script>

