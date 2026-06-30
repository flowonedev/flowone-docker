<template>
  <div
    v-if="health && health.has_client"
    class="boardpro-client-health flex items-center gap-2 px-3 py-2 rounded-xl border transition-colors"
    :class="borderClass"
  >
    <CrmHealthBadge
      v-if="health.health_score !== null && health.health_score !== undefined"
      :score="health.health_score"
      :show-label="false"
      size="sm"
    />
    <span v-else class="material-symbols-rounded text-lg text-surface-400">help</span>

    <div class="flex-1 min-w-0">
      <p class="text-xs font-medium text-surface-800 dark:text-surface-200 truncate">
        {{ health.client_name }}
      </p>
      <div class="flex items-center gap-2 mt-0.5">
        <span class="text-xs" :class="tierTextClass">
          {{ tierLabel }}
        </span>
        <span v-if="health.last_contact" class="text-xs text-surface-400">
          Last contact: {{ formatDate(health.last_contact) }}
        </span>
      </div>
    </div>
    <div v-if="health.health_score !== null" class="text-right">
      <span class="text-lg font-bold" :class="tierTextClass">{{ health.health_score }}</span>
      <p class="text-[10px] text-surface-400">/100</p>
    </div>
  </div>
</template>

<script setup>
/**
 * BoardClientHealthBadge — Board sidebar widget showing the linked client's health.
 * Delegates the score/color logic to the shared CrmHealthBadge component so there is
 * ONE source of truth for health-score tiers across the entire app.
 */
import { computed, onMounted, watch, defineAsyncComponent } from 'vue'
import { useBoardProStore } from '../stores/boardPro'

const CrmHealthBadge = defineAsyncComponent(() =>
  import('@/addons/crm-pro/components/CrmHealthBadge.vue')
)

const props = defineProps({
  boardId: { type: Number, required: true },
})

const store = useBoardProStore()
const health = computed(() => store.boardClientHealth)

// Reuse the same tier thresholds as CrmHealthBadge (80/50/20)
const tier = computed(() => {
  const s = health.value?.health_score ?? -1
  if (s >= 80) return { label: 'Healthy', text: 'text-green-600 dark:text-green-400', border: 'border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-900/10' }
  if (s >= 50) return { label: 'Moderate', text: 'text-yellow-600 dark:text-yellow-400', border: 'border-yellow-200 dark:border-yellow-800 bg-yellow-50/50 dark:bg-yellow-900/10' }
  if (s >= 20) return { label: 'At Risk', text: 'text-orange-600 dark:text-orange-400', border: 'border-orange-200 dark:border-orange-800 bg-orange-50/50 dark:bg-orange-900/10' }
  if (s >= 0)  return { label: 'Critical', text: 'text-red-600 dark:text-red-400', border: 'border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-900/10' }
  return { label: 'Unknown', text: 'text-surface-500', border: 'border-surface-200 dark:border-surface-700' }
})

const tierLabel = computed(() => tier.value.label)
const tierTextClass = computed(() => tier.value.text)
const borderClass = computed(() => tier.value.border)

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diff = Math.floor((now - d) / (1000 * 60 * 60 * 24))
  if (diff === 0) return 'Today'
  if (diff === 1) return 'Yesterday'
  if (diff < 7) return `${diff}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

onMounted(() => {
  if (props.boardId) store.fetchBoardClientHealth(props.boardId)
})

watch(() => props.boardId, (id) => {
  if (id) store.fetchBoardClientHealth(id)
})
</script>
