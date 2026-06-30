<script setup>
import { ref, computed, onMounted } from 'vue'
import aiHelper from '@/services/aiHelper'

const props = defineProps({
  service: {
    type: String,
    default: null
  }
})

const issues = ref([])
const loading = ref(false)
const filterSeverity = ref('all')
const showResolved = ref(false)

const severityColors = {
  critical: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400',
  high: 'bg-orange-100 dark:bg-orange-500/20 text-orange-700 dark:text-orange-400',
  medium: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400',
  low: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400',
}

const filteredIssues = computed(() => {
  let filtered = issues.value

  if (filterSeverity.value !== 'all') {
    filtered = filtered.filter(issue => issue.severity === filterSeverity.value)
  }

  if (!showResolved.value) {
    filtered = filtered.filter(issue => !issue.resolved_at)
  }

  return filtered
})

const fetchIssues = async () => {
  loading.value = true
  try {
    issues.value = await aiHelper.getCachedIssues(props.service, showResolved.value)
  } catch (e) {
    console.error('Failed to fetch issues', e)
  } finally {
    loading.value = false
  }
}

const resolveIssue = async (issueId) => {
  try {
    await aiHelper.resolveIssue(issueId)
    await fetchIssues()
  } catch (e) {
    console.error('Failed to resolve issue', e)
  }
}

onMounted(() => {
  fetchIssues()
})

defineExpose({
  refresh: fetchIssues
})
</script>

<template>
  <div class="space-y-1.5">
    <!-- Filters -->
    <div class="flex gap-1 items-center">
      <select
        v-model="filterSeverity"
        class="flex-1 px-1 py-0.5 text-[8px] rounded bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 min-w-0"
      >
        <option value="all">All</option>
        <option value="critical">Crit</option>
        <option value="high">High</option>
        <option value="medium">Med</option>
        <option value="low">Low</option>
      </select>
      <button
        @click="fetchIssues"
        class="p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex-shrink-0"
        title="Refresh"
      >
        <span class="material-symbols-rounded text-[9px] text-surface-500">refresh</span>
      </button>
    </div>

    <label class="flex items-center gap-1 text-[8px] text-surface-500 cursor-pointer">
      <input type="checkbox" v-model="showResolved" class="w-2 h-2 rounded" />
      <span>Resolved</span>
    </label>

    <!-- Issues List -->
    <div v-if="loading" class="text-center py-2">
      <span class="spinner" style="width: 12px; height: 12px;"></span>
    </div>

    <div v-else-if="filteredIssues.length === 0" class="text-center py-2 text-[9px] text-surface-500">
      No issues
    </div>

    <div v-else class="space-y-1 max-h-[calc(100vh-20rem)] overflow-y-auto">
      <div
        v-for="issue in filteredIssues"
        :key="issue.id"
        class="p-1 rounded bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700"
      >
        <div class="flex items-start justify-between gap-0.5">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-0.5 mb-0.5 flex-wrap">
              <span
                :class="['px-0.5 py-0 rounded text-[7px] font-medium uppercase leading-tight', severityColors[issue.severity]]"
              >
                {{ issue.severity.substring(0, 4) }}
              </span>
              <span v-if="issue.service" class="text-[7px] text-surface-400 truncate">
                {{ issue.service }}
              </span>
            </div>
            <p class="text-[9px] text-surface-600 dark:text-surface-300 leading-tight line-clamp-2">
              {{ issue.description }}
            </p>
          </div>
          <button
            v-if="!issue.resolved_at"
            @click="resolveIssue(issue.id)"
            class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors flex-shrink-0"
            title="Resolve"
          >
            <span class="material-symbols-rounded text-[9px] text-green-600 dark:text-green-400">check</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

