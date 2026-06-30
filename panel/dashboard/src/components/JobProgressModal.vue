<script setup>
// JobProgressModal
// ---------------------------------------------------------------
// Reusable modal that watches a single provisioning job, streams
// its event log into the body, and shows the per-step timeline.
// The parent passes a job id and the modal does the rest:
//
//   <JobProgressModal
//     :show="!!activeJobId"
//     :job-id="activeJobId"
//     :title="`Provisioning ${selectedDomain}`"
//     @close="activeJobId = null"
//     @terminal="onTerminal"
//   />
//
// Why this lives in its own component:
//   - SitesV2View and JobsView both want the same UX (live tail +
//     step progress + cancel).
//   - Polling state should be owned by the modal so closing it
//     deterministically tears the polling down (no orphaned
//     setIntervals leaking after navigation).

import { ref, watch, onUnmounted, computed } from 'vue'
import Modal from '@/components/Modal.vue'
import {
  getJob,
  cancelJob,
  retryJob,
  tailJobEvents,
} from '@/services/sitesV2'

const props = defineProps({
  show: { type: Boolean, default: false },
  jobId: { type: [Number, String, null], default: null },
  title: { type: String, default: 'Job progress' },
  // When true and the job lands FAILED/CANCELLED, surface a Retry button.
  allowRetry: { type: Boolean, default: true },
  // When true, surface a Cancel button while the job is QUEUED.
  allowCancel: { type: Boolean, default: true },
  // Auto-close the modal `autoCloseMs` after a successful terminal.
  // 0 = stay open (default).
  autoCloseMs: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'terminal', 'retried'])

const job = ref(null)
const steps = ref([])
const events = ref([])
const refreshing = ref(false)
const cancelling = ref(false)
const retrying = ref(false)
const fatalError = ref(null)
let tailStop = null
let detailPollTimer = null
let autoCloseTimer = null

const isTerminal = computed(() => !!job.value?.terminal)
const statusLabel = computed(() => job.value?.status ?? 'queued')
const elapsed = computed(() => {
  if (!job.value?.started_at) return ''
  const started = job.value.started_at
  const ended = job.value.finished_at ?? new Date().toISOString()
  try {
    const ms = new Date(ended).getTime() - new Date(started).getTime()
    if (ms <= 0) return ''
    const s = Math.round(ms / 1000)
    if (s < 60) return `${s}s`
    return `${Math.floor(s / 60)}m ${s % 60}s`
  } catch {
    return ''
  }
})

const statusClass = computed(() => {
  switch (statusLabel.value) {
    case 'succeeded':
      return 'text-green-700 bg-green-100 dark:bg-green-500/15 dark:text-green-300'
    case 'failed':
      return 'text-red-700 bg-red-100 dark:bg-red-500/15 dark:text-red-300'
    case 'cancelled':
      return 'text-amber-700 bg-amber-100 dark:bg-amber-500/15 dark:text-amber-300'
    case 'running':
      return 'text-blue-700 bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300'
    default:
      return 'text-surface-700 bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-300'
  }
})

// ───── Legacy-skin choreography helpers (presentation only) ─────
// The saga steps come from /api/jobs/{id} with `outcome` strings.
// We translate those into the .step-node visual states defined in
// main.css so the operator gets the legacy SitesView animated
// timeline feeling, but every datum still comes from the real
// saga - no fake progress.

const stepVisualState = (step, index) => {
  // Explicit outcomes always win.
  switch (step?.outcome) {
    case 'success': return 'completed'
    case 'failure':
    case 'timeout': return 'failed'
    case 'partial':
    case 'retry_later': return 'warning'
    case 'skipped': return 'skipped'
  }
  // No outcome yet. If the worker has started it (started_at set)
  // and the job is still running, it's the active step. Otherwise
  // it's queued/pending.
  if (step?.started_at && statusLabel.value === 'running') return 'active'
  // While the job is running, the last step without an outcome is
  // visually the active one (helps when started_at isn't surfaced
  // yet by the API serializer).
  if (statusLabel.value === 'running' && index === lastUncompletedIndex.value) {
    return 'active'
  }
  return 'pending'
}

const lastUncompletedIndex = computed(() => {
  for (let i = steps.value.length - 1; i >= 0; i--) {
    if (!steps.value[i]?.outcome) return i
  }
  return -1
})

// Steps completed = anything with a terminal outcome (success,
// failure, timeout, skipped, partial). retry_later still counts as
// "in progress" and is not added to the completed count.
const completedStepCount = computed(() =>
  steps.value.filter((s) =>
    ['success', 'failure', 'timeout', 'skipped', 'partial'].includes(s?.outcome),
  ).length,
)

const totalStepCount = computed(() => steps.value.length)

const progressPercent = computed(() => {
  if (statusLabel.value === 'succeeded') return 100
  if (totalStepCount.value === 0) return 0
  // We don't know the saga's planned step count in advance; the
  // step list grows as the worker records executions. Use the
  // visible step count as the denominator so the bar advances in
  // step with the activity feed - and snaps to 100% on success.
  return Math.min(
    100,
    Math.round((completedStepCount.value / Math.max(1, totalStepCount.value)) * 100),
  )
})

// Pretty-print a step name like 'SftpUserCreateStep' -> 'Sftp User Create'.
const prettyStepName = (name) => {
  if (!name) return 'Step'
  return String(name)
    .replace(/Step$/, '')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .trim()
}

// Friendly status copy for the result screen.
const resultHeadline = computed(() => {
  switch (statusLabel.value) {
    case 'succeeded': return 'Provisioning complete'
    case 'failed':    return 'Provisioning failed'
    case 'cancelled': return 'Job cancelled'
    case 'running':   return 'Running…'
    case 'queued':    return 'Queued - waiting for a worker'
    default:          return statusLabel.value
  }
})

const resultIcon = computed(() => {
  switch (statusLabel.value) {
    case 'succeeded': return 'rocket_launch'
    case 'failed':    return 'error'
    case 'cancelled': return 'do_not_disturb_on'
    default:          return 'autorenew'
  }
})

const resultIconClass = computed(() => {
  switch (statusLabel.value) {
    case 'succeeded': return 'text-green-500'
    case 'failed':    return 'text-red-500'
    case 'cancelled': return 'text-amber-500'
    default:          return 'text-primary-500 animate-pulse'
  }
})

// Pull the admin URL / domain / failed step out of the job payload
// if present. The shape varies by job type so we coalesce
// defensively - never fabricate values.
const resultDomain = computed(() => {
  const j = job.value || {}
  return j.site_domain || j.payload?.domain || ''
})

const resultAdminUrl = computed(() => {
  const j = job.value || {}
  return j.result?.admin_url || j.payload?.admin_url || ''
})

const failedStep = computed(() => {
  return steps.value.find((s) =>
    s?.outcome === 'failure' || s?.outcome === 'timeout',
  )
})

const refresh = async () => {
  if (!props.jobId) return
  refreshing.value = true
  try {
    const data = await getJob(props.jobId)
    job.value = data?.job ?? null
    steps.value = Array.isArray(data?.steps) ? data.steps : []
    fatalError.value = null
  } catch (e) {
    fatalError.value = e?.message ?? 'Failed to refresh job'
  } finally {
    refreshing.value = false
  }
}

const onCancelClicked = async () => {
  if (!props.jobId || cancelling.value) return
  cancelling.value = true
  try {
    await cancelJob(props.jobId, 'cancelled via UI')
    await refresh()
  } catch (e) {
    fatalError.value = e?.message ?? 'Cancel failed'
  } finally {
    cancelling.value = false
  }
}

const onRetryClicked = async () => {
  if (!props.jobId || retrying.value) return
  retrying.value = true
  try {
    const data = await retryJob(props.jobId, 'retry requested via UI')
    const newId = data?.job?.id
    if (newId) {
      emit('retried', newId)
    }
  } catch (e) {
    fatalError.value = e?.message ?? 'Retry failed'
  } finally {
    retrying.value = false
  }
}

const startTailing = () => {
  stopTailing()
  if (!props.jobId) return

  events.value = []
  tailStop = tailJobEvents(props.jobId, {
    onEvent: (e) => events.value.push(e),
    onTerminal: (status) => {
      // refresh once more so steps reflect final outcome
      refresh()
      emit('terminal', status)
      if (props.autoCloseMs > 0) {
        autoCloseTimer = setTimeout(
          () => emit('close'),
          props.autoCloseMs,
        )
      }
    },
    intervalMs: 1500,
  })

  // Also poll the job-level detail every 4s so the step-by-step
  // panel reflects newly-recorded executions even before the next
  // event fires.
  detailPollTimer = setInterval(refresh, 4000)
  refresh()
}

const stopTailing = () => {
  if (tailStop) {
    tailStop()
    tailStop = null
  }
  if (detailPollTimer) {
    clearInterval(detailPollTimer)
    detailPollTimer = null
  }
  if (autoCloseTimer) {
    clearTimeout(autoCloseTimer)
    autoCloseTimer = null
  }
}

watch(
  () => [props.show, props.jobId],
  ([visible, id]) => {
    if (visible && id) {
      startTailing()
    } else {
      stopTailing()
      if (!visible) {
        // Reset state when the modal closes so a re-open with a
        // different job doesn't briefly show stale data.
        job.value = null
        steps.value = []
        events.value = []
        fatalError.value = null
      }
    }
  },
  { immediate: true },
)

onUnmounted(stopTailing)

const eventLevelClass = (level) => {
  switch (level) {
    case 'error':
      return 'text-red-600 dark:text-red-300'
    case 'warning':
      return 'text-amber-600 dark:text-amber-300'
    case 'info':
      return 'text-surface-700 dark:text-surface-200'
    default:
      return 'text-surface-500 dark:text-surface-400'
  }
}
</script>

<template>
  <Modal :show="show" :title="title" size="xl" @close="emit('close')">
    <div v-if="!jobId" class="text-sm text-surface-500">
      No job selected.
    </div>

    <div v-else class="space-y-5">
      <!-- ─── Animated hero header ───
           Pulsing rocket while the saga runs, swapping to a calm
           checkmark / red error icon once we hit a terminal state.
           Mirrors the legacy create choreography exactly. -->
      <div
        class="relative overflow-hidden rounded-2xl p-5 border
               border-surface-200 dark:border-[rgb(var(--color-border))]
               bg-gradient-to-br from-primary-50 via-white to-surface-50
               dark:from-primary-500/10 dark:via-[rgb(var(--color-surface-elevated))] dark:to-[rgb(var(--color-surface))]"
      >
        <div class="flex items-start gap-4">
          <div
            class="w-14 h-14 rounded-2xl flex items-center justify-center
                   bg-white dark:bg-[rgb(var(--color-surface))] shadow-sm
                   border border-surface-200 dark:border-[rgb(var(--color-border))]"
          >
            <span
              class="material-symbols-rounded text-3xl"
              :class="resultIconClass"
            >
              {{ resultIcon }}
            </span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span
                class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase tracking-wide"
                :class="statusClass"
              >
                {{ statusLabel }}
              </span>
              <span class="text-xs text-surface-500 dark:text-surface-400">
                Job #{{ jobId }}<template v-if="job?.type"> · {{ job.type }}</template>
              </span>
              <span v-if="elapsed" class="text-xs text-surface-500 dark:text-surface-400">
                · {{ elapsed }}
              </span>
            </div>
            <h3 class="text-lg font-semibold mt-1">{{ resultHeadline }}</h3>
            <p v-if="resultDomain" class="text-sm text-surface-500 dark:text-surface-400 truncate">
              {{ resultDomain }}
            </p>

            <!-- Progress bar: gradient fill, smooth width transition. -->
            <div class="mt-3 space-y-1.5">
              <div class="flex items-center justify-between text-xs text-surface-500 dark:text-surface-400">
                <span>
                  <template v-if="totalStepCount > 0">
                    {{ completedStepCount }} of {{ totalStepCount }} steps
                  </template>
                  <template v-else>
                    Waiting for first step…
                  </template>
                </span>
                <span class="font-mono tabular-nums">{{ progressPercent }}%</span>
              </div>
              <div class="progress-track">
                <div
                  class="progress-bar-fill"
                  :style="{ width: `${progressPercent}%` }"
                />
              </div>
            </div>
          </div>

          <div class="flex items-center gap-2 self-start">
            <button
              class="btn btn-sm btn-secondary"
              :disabled="refreshing"
              @click="refresh"
            >
              <span class="material-symbols-rounded text-sm">refresh</span>
              <span class="hidden sm:inline">Refresh</span>
            </button>
            <button
              v-if="allowCancel && statusLabel === 'queued'"
              class="btn btn-sm btn-secondary"
              :disabled="cancelling"
              @click="onCancelClicked"
            >
              {{ cancelling ? 'Cancelling…' : 'Cancel' }}
            </button>
            <button
              v-if="
                allowRetry &&
                (statusLabel === 'failed' || statusLabel === 'cancelled')
              "
              class="btn btn-sm btn-primary"
              :disabled="retrying"
              @click="onRetryClicked"
            >
              {{ retrying ? 'Retrying…' : 'Retry' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="fatalError"
        class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-500/10 dark:border-red-500/30
               text-red-700 dark:text-red-200 px-3 py-2 text-sm"
      >
        {{ fatalError }}
      </div>

      <!-- ─── Success result screen ───
           Renders ONLY when the saga is succeeded. Pure presentation
           layered on whatever the job already returned in its
           payload/result - we never invent credentials. -->
      <div
        v-if="statusLabel === 'succeeded'"
        class="rounded-2xl border border-green-200 dark:border-green-500/30
               bg-green-50 dark:bg-green-500/5 p-4 space-y-3"
      >
        <div class="flex items-center gap-2 text-green-700 dark:text-green-300 font-medium">
          <span class="material-symbols-rounded">verified</span>
          Site is live
        </div>
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
          <a
            v-if="resultDomain"
            :href="`https://${resultDomain}`"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-2 px-3 py-2 rounded-xl
                   bg-white dark:bg-[rgb(var(--color-surface))]
                   border border-surface-200 dark:border-[rgb(var(--color-border))]
                   hover:border-primary-400 transition-colors"
          >
            <span class="material-symbols-rounded text-primary-500">open_in_new</span>
            <span class="font-mono truncate">{{ resultDomain }}</span>
          </a>
          <a
            v-if="resultAdminUrl"
            :href="resultAdminUrl"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-2 px-3 py-2 rounded-xl
                   bg-white dark:bg-[rgb(var(--color-surface))]
                   border border-surface-200 dark:border-[rgb(var(--color-border))]
                   hover:border-primary-400 transition-colors"
          >
            <span class="material-symbols-rounded text-primary-500">admin_panel_settings</span>
            <span class="font-mono truncate">Open admin</span>
          </a>
        </div>
      </div>

      <!-- ─── Failure result screen ───
           Surfaces the failing step + its error so the operator
           can decide whether to retry or escalate. -->
      <div
        v-if="statusLabel === 'failed' && failedStep"
        class="rounded-2xl border border-red-200 dark:border-red-500/30
               bg-red-50 dark:bg-red-500/5 p-4 space-y-2"
      >
        <div class="flex items-center gap-2 text-red-700 dark:text-red-300 font-medium">
          <span class="material-symbols-rounded">report</span>
          Failed at <code class="font-mono">{{ prettyStepName(failedStep.step_name) }}</code>
        </div>
        <pre
          v-if="failedStep.error"
          class="text-xs font-mono whitespace-pre-wrap text-red-700 dark:text-red-300
                 max-h-32 overflow-auto"
        >{{ failedStep.error }}</pre>
      </div>

      <div class="grid md:grid-cols-5 gap-4">
        <!-- ─── Step choreography (left column, wider) ───
             Legacy-style vertical step list with connector lines and
             .step-node circles whose visual state is mapped directly
             from the live saga event stream. -->
        <div class="md:col-span-3">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-surface-500 mb-3">
            Saga timeline
          </h4>

          <div v-if="!steps.length" class="step-row pending">
            <div class="flex flex-col items-center">
              <div class="step-node pending">
                <span class="material-symbols-rounded text-sm">hourglass_empty</span>
              </div>
            </div>
            <div class="flex-1 text-sm text-surface-500">
              Waiting for the worker to claim this job…
            </div>
          </div>

          <ul v-else class="space-y-0">
            <li
              v-for="(step, idx) in steps"
              :key="step.id"
              class="step-row"
              :class="stepVisualState(step, idx)"
            >
              <!-- Node + connector column -->
              <div class="flex flex-col items-center self-stretch">
                <div
                  class="step-node"
                  :class="stepVisualState(step, idx)"
                >
                  <template v-if="stepVisualState(step, idx) === 'completed'">
                    <span class="material-symbols-rounded text-sm">check</span>
                  </template>
                  <template v-else-if="stepVisualState(step, idx) === 'failed'">
                    <span class="material-symbols-rounded text-sm">close</span>
                  </template>
                  <template v-else-if="stepVisualState(step, idx) === 'warning'">
                    <span class="material-symbols-rounded text-sm">priority_high</span>
                  </template>
                  <template v-else-if="stepVisualState(step, idx) === 'skipped'">
                    <span class="material-symbols-rounded text-sm">remove</span>
                  </template>
                  <template v-else-if="stepVisualState(step, idx) === 'active'">
                    <span class="material-symbols-rounded text-sm">autorenew</span>
                  </template>
                  <template v-else>
                    {{ idx + 1 }}
                  </template>
                </div>
                <div
                  v-if="idx < steps.length - 1"
                  class="step-connector"
                  :class="stepVisualState(step, idx) === 'completed' ? 'completed' : ''"
                />
              </div>

              <!-- Label + detail -->
              <div class="flex-1 min-w-0 pb-2">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                  <div class="font-medium">
                    {{ prettyStepName(step.step_name) }}
                  </div>
                  <div
                    v-if="step.duration_ms"
                    class="text-xs text-surface-500 dark:text-surface-400 font-mono tabular-nums"
                  >
                    {{ step.duration_ms }}ms
                  </div>
                </div>
                <div
                  v-if="step.error"
                  class="text-xs text-red-600 dark:text-red-300 mt-0.5 truncate"
                  :title="step.error"
                >
                  {{ step.error }}
                </div>
                <div
                  v-else-if="stepVisualState(step, idx) === 'active'"
                  class="text-xs text-primary-600 dark:text-primary-400 mt-0.5"
                >
                  Running…
                </div>
                <div
                  v-else-if="step.outcome && step.outcome !== 'success'"
                  class="text-xs text-surface-500 dark:text-surface-400 mt-0.5"
                >
                  {{ step.outcome }}
                </div>
              </div>
            </li>
          </ul>
        </div>

        <!-- ─── Live event log (right column, terminal styling) ─── -->
        <div class="md:col-span-2">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-surface-500 mb-2 flex items-center gap-2">
            <span>Live log</span>
            <span
              v-if="!isTerminal"
              class="pulse-dot bg-emerald-500"
              aria-hidden="true"
            />
          </h4>
          <div
            class="font-mono text-xs rounded-xl p-3 max-h-80 overflow-auto
                   bg-surface-900 dark:bg-[rgb(var(--color-bg))]
                   text-surface-100 dark:text-surface-200
                   border border-surface-800 dark:border-[rgb(var(--color-border))]"
          >
            <div v-if="!events.length" class="text-surface-400">
              No events yet.
            </div>
            <div
              v-for="ev in events"
              :key="ev.id"
              class="py-0.5 flex gap-2"
              :class="eventLevelClass(ev.level)"
            >
              <span class="text-surface-500">
                {{
                  ev.occurred_at
                    ? new Date(ev.occurred_at)
                        .toISOString()
                        .substr(11, 8)
                    : '--:--:--'
                }}
              </span>
              <span v-if="ev.step_name" class="text-surface-400">
                [{{ ev.step_name }}]
              </span>
              <span class="flex-1 break-all">{{ ev.message }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <button class="btn btn-secondary" @click="emit('close')">
        Close
      </button>
    </template>
  </Modal>
</template>
