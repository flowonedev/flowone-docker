<template>
  <div
    v-if="open"
    class="absolute bottom-0 left-0 right-0 z-30 bg-white dark:bg-surface-900 border-t border-surface-200 dark:border-surface-700 shadow-2xl transition-all"
    :style="{ height: panelHeight + 'px' }"
  >
    <!-- Resize handle -->
    <div
      class="absolute top-0 left-0 right-0 h-1 cursor-ns-resize hover:bg-primary-500/30"
      @mousedown="onResizeStart"
    />

    <!-- Header -->
    <div class="flex items-center gap-3 px-4 py-2 border-b border-surface-200 dark:border-surface-700">
      <span class="material-symbols-rounded text-lg text-surface-400">history</span>
      <span class="text-sm font-semibold text-surface-700 dark:text-surface-200">Execution Log</span>
      <span class="text-xs text-surface-500">{{ executions.length }} runs</span>
      <div class="flex-1" />
      <button
        @click="loadExecutions"
        class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
      >
        <span class="material-symbols-rounded text-lg">refresh</span>
      </button>
      <button
        @click="$emit('close')"
        class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
      >
        <span class="material-symbols-rounded text-lg">close</span>
      </button>
    </div>

    <!-- Content -->
    <div class="flex h-full" style="height: calc(100% - 44px);">
      <!-- Execution list -->
      <div class="w-80 border-r border-surface-200 dark:border-surface-700 overflow-y-auto">
        <div
          v-for="exec in executions"
          :key="exec.id"
          class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors border-b border-surface-100 dark:border-surface-700/50"
          :class="{ 'bg-surface-50 dark:bg-surface-800': selectedExecId === exec.id }"
          @click="selectExecution(exec)"
        >
          <span
            class="material-symbols-rounded text-lg"
            :class="{
              'text-emerald-500 dark:text-emerald-400': exec.status === 'completed',
              'text-red-500 dark:text-red-400': exec.status === 'failed',
              'text-blue-500 dark:text-blue-400 animate-spin': exec.status === 'running',
              'text-surface-400 dark:text-surface-500': exec.status === 'cancelled',
            }"
          >{{ statusIcon(exec.status) }}</span>
          <div class="flex-1 min-w-0">
            <div class="text-xs text-surface-700 dark:text-surface-200">
              {{ exec.is_test ? 'Test run' : 'Execution' }} #{{ exec.id }}
            </div>
            <div class="text-[10px] text-surface-500">{{ formatTime(exec.started_at) }}</div>
          </div>
          <span class="text-[10px] text-surface-500">
            {{ exec.completed_at ? durationStr(exec.started_at, exec.completed_at) : '...' }}
          </span>
        </div>

        <div v-if="executions.length === 0" class="p-6 text-center">
          <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">inbox</span>
          <p class="text-xs text-surface-500 mt-2">No executions yet</p>
        </div>
      </div>

      <!-- Node execution details -->
      <div class="flex-1 overflow-y-auto p-4">
        <template v-if="selectedExec && nodeExecs.length > 0">
          <div class="space-y-2">
            <div
              v-for="ne in nodeExecs"
              :key="ne.id"
              class="bg-surface-50 dark:bg-surface-800 rounded-lg border border-surface-200 dark:border-surface-700 p-3"
            >
              <div class="flex items-center gap-2 mb-2">
                <span
                  class="material-symbols-rounded text-sm"
                  :class="{
                    'text-emerald-500 dark:text-emerald-400': ne.status === 'completed',
                    'text-red-500 dark:text-red-400': ne.status === 'failed',
                    'text-blue-500 dark:text-blue-400': ne.status === 'running',
                    'text-surface-400 dark:text-surface-500': ne.status === 'skipped' || ne.status === 'pending',
                  }"
                >{{ statusIcon(ne.status) }}</span>
                <span
                  v-if="getNodeIcon(ne)"
                  class="material-symbols-rounded text-sm text-surface-400"
                >{{ getNodeIcon(ne) }}</span>
                <span class="text-xs font-semibold text-surface-700 dark:text-surface-200">
                  {{ getNodeTitle(ne) }}
                </span>
                <span class="text-[10px] text-surface-400 font-mono truncate max-w-[140px]" :title="ne.node_uid">
                  {{ ne.node_uid.substring(0, 8) }}
                </span>
                <span v-if="ne.duration_ms != null" class="text-[10px] text-surface-500 ml-auto">
                  {{ ne.duration_ms }}ms
                </span>
              </div>

              <div v-if="ne.error_message" class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 rounded p-2 mb-2">
                {{ ne.error_message }}
              </div>

              <div v-if="ne.input_data" class="mb-2">
                <div class="text-[10px] font-medium text-surface-400 mb-1">Input</div>
                <pre class="text-[10px] text-surface-600 dark:text-surface-300 bg-surface-100 dark:bg-surface-900 rounded p-2 overflow-x-auto max-h-32">{{ formatJson(ne.input_data) }}</pre>
              </div>

              <div v-if="ne.output_data">
                <div class="text-[10px] font-medium text-surface-400 mb-1">Output</div>
                <pre class="text-[10px] text-surface-600 dark:text-surface-300 bg-surface-100 dark:bg-surface-900 rounded p-2 overflow-x-auto max-h-32">{{ formatJson(ne.output_data) }}</pre>
              </div>
            </div>
          </div>
        </template>
        <template v-else>
          <div class="flex items-center justify-center h-full">
            <p class="text-xs text-surface-500">Select an execution to see details</p>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import automationHubApi from '../../services/automationHubApi'
import { useAutomationHubStore } from '../../stores/automationHub'
import { useNodeRegistry } from '../../composables/useNodeRegistry'

const props = defineProps({
  open: { type: Boolean, default: false },
  workflowId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close'])

const store = useAutomationHubStore()
const { getNodeDef } = useNodeRegistry()
const executions = ref([])
const selectedExecId = ref(null)
const selectedExec = ref(null)
const nodeExecs = ref([])
const panelHeight = ref(300)

async function loadExecutions() {
  if (!props.workflowId) return
  try {
    const res = await automationHubApi.listExecutions(props.workflowId)
    executions.value = res.data?.data?.executions || []
  } catch (e) {
    console.error('[ExecutionPanel] Load failed:', e)
  }
}

async function selectExecution(exec) {
  selectedExecId.value = exec.id
  selectedExec.value = exec
  try {
    const res = await automationHubApi.getNodeExecutions(exec.id)
    nodeExecs.value = res.data?.data?.node_executions || []

    const stateMap = new Map()
    for (const ne of nodeExecs.value) {
      stateMap.set(ne.node_uid, { status: ne.status, input: ne.input_data, output: ne.output_data })
    }
    store.executionState = stateMap
  } catch (e) {
    console.error('[ExecutionPanel] Load node execs failed:', e)
  }
}

function getNodeTitle(ne) {
  if (ne.node_label) return ne.node_label
  if (ne.node_type) {
    const def = getNodeDef(ne.node_type)
    if (def) return def.label
  }
  const storeNode = store.nodes?.find(n => n.uid === ne.node_uid)
  if (storeNode) {
    const def = getNodeDef(storeNode.type)
    if (def) return def.label
    return storeNode.type
  }
  return ne.node_uid
}

function getNodeIcon(ne) {
  const type = ne.node_type || store.nodes?.find(n => n.uid === ne.node_uid)?.type
  if (!type) return null
  const def = getNodeDef(type)
  return def?.icon || null
}

function statusIcon(status) {
  return {
    completed: 'check_circle',
    failed: 'error',
    running: 'progress_activity',
    cancelled: 'cancel',
    pending: 'pending',
    skipped: 'block',
  }[status] || 'help'
}

function formatTime(dateStr) {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleString()
}

function durationStr(start, end) {
  if (!start || !end) return ''
  const ms = new Date(end) - new Date(start)
  if (ms < 1000) return ms + 'ms'
  if (ms < 60000) return (ms / 1000).toFixed(1) + 's'
  return Math.floor(ms / 60000) + 'm'
}

function formatJson(data) {
  if (!data) return ''
  try {
    return JSON.stringify(typeof data === 'string' ? JSON.parse(data) : data, null, 2)
  } catch {
    return String(data)
  }
}

let resizing = false
let resizeStartY = 0
let resizeStartH = 0

function onResizeStart(e) {
  resizing = true
  resizeStartY = e.clientY
  resizeStartH = panelHeight.value
  document.addEventListener('mousemove', onResizeMove)
  document.addEventListener('mouseup', onResizeEnd)
}
function onResizeMove(e) {
  if (!resizing) return
  const delta = resizeStartY - e.clientY
  panelHeight.value = Math.max(150, Math.min(600, resizeStartH + delta))
}
function onResizeEnd() {
  resizing = false
  document.removeEventListener('mousemove', onResizeMove)
  document.removeEventListener('mouseup', onResizeEnd)
}

watch(() => props.open, (val) => {
  if (val) loadExecutions()
})

watch(() => props.workflowId, () => {
  if (props.open) loadExecutions()
})
</script>
