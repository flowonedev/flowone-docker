<script setup>
import { ref, onMounted, computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import {
  fetchTestSimulationPreflight,
  generateTestSimulation,
  listTestSimulationRuns,
  deleteTestSimulationRun,
  deleteAllTestSimulationRuns,
} from '@/services/testSimulationService.js'

const authStore = useAuthStore()
const { userEmail } = storeToRefs(authStore)
const toast = useToastStore()
const router = useRouter()

const allowedDomains = ['pixelranger.hu', 'whiterabbit.hu', 'greyskull.hu']
const canSimulate = computed(() => {
  const e = (userEmail.value || '').toLowerCase()
  const d = e.split('@')[1] || ''
  return allowedDomains.includes(d)
})

const preflight = ref(null)
const loadingPreflight = ref(false)
const runs = ref([])
const loadingRuns = ref(false)
const generating = ref(false)
const promoteAdminAck = ref(false)

const showDeleteRunModal = ref(false)
const showDeleteAllModal = ref(false)
const pendingRunId = ref('')

async function loadPreflight() {
  if (!canSimulate.value) return
  loadingPreflight.value = true
  try {
    preflight.value = await fetchTestSimulationPreflight()
  } catch (e) {
    toast.error(e.response?.data?.message || e.message || 'Preflight failed')
    preflight.value = { ok: false, missing: ['request_failed'], requires_admin_promotion: false, owner_is_admin: false }
  } finally {
    loadingPreflight.value = false
  }
}

async function loadRuns() {
  if (!canSimulate.value) return
  loadingRuns.value = true
  try {
    runs.value = await listTestSimulationRuns()
  } catch (e) {
    toast.error(e.response?.data?.message || e.message || 'Failed to list runs')
  } finally {
    loadingRuns.value = false
  }
}

onMounted(() => {
  loadPreflight()
  loadRuns()
})

const canGenerate = computed(() => {
  if (!preflight.value?.ok) return false
  if (preflight.value.requires_admin_promotion && !promoteAdminAck.value) return false
  return true
})

async function onGenerate() {
  if (!canGenerate.value) return
  generating.value = true
  try {
    const summary = await generateTestSimulation(
      !!(preflight.value?.requires_admin_promotion && promoteAdminAck.value)
    )
    toast.success(`Run ${summary.run_id} created`)
    promoteAdminAck.value = false
    await loadPreflight()
    await loadRuns()
  } catch (e) {
    const r = e.response?.data?.reason
    if (r === 'requires_admin_promotion') {
      toast.error('Confirm admin promotion below, then generate again.')
    } else if (e.response?.status === 503) {
      toast.error('Test simulation is disabled on the server.')
    } else if (e.response?.status === 409) {
      toast.error('Another generation is in progress. Try again in a moment.')
    } else {
      toast.error(e.response?.data?.message || e.message || 'Generate failed')
    }
  } finally {
    generating.value = false
  }
}

function confirmDeleteRun(runId) {
  pendingRunId.value = runId
  showDeleteRunModal.value = true
}

async function doDeleteRun() {
  showDeleteRunModal.value = false
  try {
    await deleteTestSimulationRun(pendingRunId.value)
    toast.success('Run deleted')
    await loadRuns()
    await loadPreflight()
  } catch (e) {
    toast.error(e.response?.data?.message || e.message || 'Delete failed')
  }
  pendingRunId.value = ''
}

async function doDeleteAll() {
  showDeleteAllModal.value = false
  try {
    await deleteAllTestSimulationRuns()
    toast.success('All test runs deleted')
    await loadRuns()
    await loadPreflight()
  } catch (e) {
    toast.error(e.response?.data?.message || e.message || 'Delete all failed')
  }
}

function go(path) {
  router.push(path)
}
</script>

<template>
  <div v-if="!canSimulate" class="text-surface-600 dark:text-surface-400 text-sm">
    Test simulation is not available for this account domain.
  </div>
  <div v-else class="space-y-8 max-w-3xl">
    <section>
      <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">Pre-flight</h2>
      <div v-if="loadingPreflight" class="text-sm text-surface-500">Checking…</div>
      <ul v-else-if="preflight" class="space-y-2 text-sm">
        <li class="flex items-center gap-2">
          <span :class="preflight.ok ? 'text-emerald-500' : 'text-red-500'" class="material-symbols-rounded text-lg">
            {{ preflight.ok ? 'check_circle' : 'error' }}
          </span>
          <span>Database tables / columns</span>
        </li>
        <li class="flex items-center gap-2">
          <span :class="preflight.owner_is_admin ? 'text-emerald-500' : 'text-amber-500'" class="material-symbols-rounded text-lg">
            {{ preflight.owner_is_admin ? 'check_circle' : 'warning' }}
          </span>
          <span>Admin access for Team / Director / Task-Time views</span>
        </li>
        <li v-if="!preflight.ok && preflight.missing?.length" class="text-red-600 dark:text-red-400 pl-8">
          Missing: {{ preflight.missing.join(', ') }}
        </li>
      </ul>
      <div v-if="preflight?.requires_admin_promotion" class="mt-4 p-4 rounded-lg bg-amber-500/10 border border-amber-500/30">
        <p class="text-sm text-surface-800 dark:text-surface-200 mb-2">
          We need to grant your account admin rights on your domain so workload views return data.
          Deleting the test run will restore your previous admin flag if it was changed.
        </p>
        <label class="flex items-center gap-2 text-sm cursor-pointer">
          <input v-model="promoteAdminAck" type="checkbox" class="rounded border-surface-300" />
          I understand and allow temporary admin promotion for this session
        </label>
      </div>
    </section>

    <section class="flex flex-wrap gap-3">
      <button
        type="button"
        class="px-4 py-2 rounded-lg bg-primary-600 text-white text-sm font-medium disabled:opacity-50"
        :disabled="!canGenerate || generating"
        @click="onGenerate"
      >
        {{ generating ? 'Generating…' : 'Generate test data' }}
      </button>
      <button
        type="button"
        class="px-4 py-2 rounded-lg border border-red-500/50 text-red-600 dark:text-red-400 text-sm"
        @click="showDeleteAllModal = true"
      >
        Delete all my test data
      </button>
    </section>

    <section>
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 mb-2">Open workload views</h3>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 dark:bg-surface-700" @click="go('/workload?mode=my-work')">My Work</button>
        <button type="button" class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 dark:bg-surface-700" @click="go('/workload?mode=team')">Team</button>
        <button type="button" class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 dark:bg-surface-700" @click="go('/project-hub/director')">Director</button>
        <button type="button" class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 dark:bg-surface-700" @click="go('/workload?mode=task-time')">Task Time</button>
      </div>
    </section>

    <section>
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 mb-2">Your simulation runs</h3>
      <div v-if="loadingRuns" class="text-sm text-surface-500">Loading…</div>
      <ul v-else class="space-y-3">
        <li
          v-for="run in runs"
          :key="run.run_id"
          class="flex flex-wrap items-center justify-between gap-2 p-3 rounded-lg border border-surface-200 dark:border-surface-600"
        >
          <div>
            <div class="font-mono text-sm">{{ run.run_id }}</div>
            <div class="text-xs text-surface-500">{{ run.created_at }}</div>
            <div v-if="run.summary" class="text-xs mt-1 text-surface-600 dark:text-surface-400">
              {{ run.summary.colleagues }} colleagues
              <span v-if="run.summary.groups">· {{ run.summary.groups }} groups</span>
              · {{ run.summary.boards }} boards
              · {{ (run.summary.parent_cards || 0) + (run.summary.subtask_cards || 0) }} cards
            </div>
          </div>
          <button
            type="button"
            class="text-xs px-2 py-1 rounded text-red-600 dark:text-red-400 border border-red-500/40"
            @click="confirmDeleteRun(run.run_id)"
          >
            Delete
          </button>
        </li>
        <li v-if="!runs.length" class="text-sm text-surface-500">No runs yet.</li>
      </ul>
    </section>

    <ConfirmModal
      :show="showDeleteRunModal"
      title="Delete simulation run?"
      message="This removes all boards, cards, sessions, and simulated colleagues for this run."
      type="danger"
      confirm-text="Delete"
      @confirm="doDeleteRun"
      @cancel="showDeleteRunModal = false"
    />
    <ConfirmModal
      :show="showDeleteAllModal"
      title="Delete all test simulation data?"
      message="Every simulation run you created will be removed."
      type="danger"
      confirm-text="Delete all"
      @confirm="doDeleteAll"
      @cancel="showDeleteAllModal = false"
    />
  </div>
</template>
