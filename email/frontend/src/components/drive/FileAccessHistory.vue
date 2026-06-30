<script setup>
/**
 * FileAccessHistory - "who opened this file, when, and how many times".
 *
 * Owner-only open history shown in the file Properties panel. Reads the
 * aggregated access log from the backend (one row per viewer).
 */
import { ref, onMounted, watch } from 'vue'
import { getAccessLog } from '@/services/driveShareApi'

const props = defineProps({
  fileId: { type: Number, required: true },
})

const entries = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    entries.value = await getAccessLog(props.fileId)
  } finally {
    loading.value = false
  }
}

watch(() => props.fileId, load)
onMounted(load)

function getInitials(email) {
  if (!email) return '?'
  return email.charAt(0).toUpperCase()
}

function getAvatarColor(email) {
  if (!email) return 'bg-surface-500'
  const colors = [
    'bg-red-500', 'bg-orange-500', 'bg-amber-500', 'bg-yellow-500',
    'bg-lime-500', 'bg-green-500', 'bg-emerald-500', 'bg-teal-500',
    'bg-cyan-500', 'bg-sky-500', 'bg-blue-500', 'bg-indigo-500',
    'bg-violet-500', 'bg-purple-500', 'bg-fuchsia-500', 'bg-pink-500',
  ]
  let hash = 0
  for (let i = 0; i < email.length; i++) {
    hash = email.charCodeAt(i) + ((hash << 5) - hash)
  }
  return colors[Math.abs(hash) % colors.length]
}

function formatDateTime(date) {
  if (!date) return 'Unknown'
  const d = new Date(String(date).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return 'Unknown'
  return d.toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <div>
    <div v-if="loading" class="flex items-center gap-2 text-sm text-surface-400 py-2">
      <span class="material-symbols-rounded animate-spin text-base">progress_activity</span>
      Loading…
    </div>

    <div v-else-if="entries.length === 0" class="p-4 bg-surface-800 rounded-lg">
      <p class="text-sm font-medium text-surface-300">No opens yet</p>
      <p class="text-xs text-surface-500 mt-1">When someone opens this file, it will appear here</p>
    </div>

    <div v-else class="space-y-2">
      <div
        v-for="entry in entries"
        :key="entry.user_email"
        class="flex items-center gap-3 p-2 rounded-lg bg-surface-800/50"
      >
        <div
          :class="[
            'w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-medium flex-shrink-0',
            getAvatarColor(entry.user_email),
          ]"
        >
          {{ getInitials(entry.user_email) }}
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-200 truncate">{{ entry.user_email }}</p>
          <p class="text-xs text-surface-500">Last opened {{ formatDateTime(entry.last_opened_at) }}</p>
        </div>
        <span
          class="text-xs font-medium px-2 py-0.5 rounded-full bg-surface-700 text-surface-300 flex-shrink-0"
          :title="`Opened ${entry.open_count} time${entry.open_count === 1 ? '' : 's'}`"
        >
          {{ entry.open_count }}&times;
        </span>
      </div>
    </div>
  </div>
</template>
