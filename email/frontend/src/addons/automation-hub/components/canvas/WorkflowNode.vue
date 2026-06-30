<template>
  <div
    class="absolute group"
    :style="nodeStyle"
    @mousedown="$emit('mousedown', $event)"
  >
    <!-- Node card -->
    <div
      class="relative w-[220px] shadow-lg border transition-all duration-150 overflow-hidden"
      :class="[cardClasses, shapeClass, connecting && inputPorts.length ? 'cursor-crosshair' : '', isDisabled ? 'opacity-45 grayscale' : '']"
      @mouseup="onCardMouseUp"
    >
      <!-- Category accent strip -->
      <div class="absolute left-0 top-0 bottom-0 w-[3px]" :class="[colors.dot, isTrigger ? 'rounded-l-2xl' : 'rounded-l-xl']" />

      <!-- Disabled badge -->
      <div v-if="isDisabled" class="absolute top-1 right-1.5 z-10">
        <span class="material-symbols-rounded text-sm text-surface-400" title="Disabled">block</span>
      </div>

      <!-- Content -->
      <div class="flex items-center gap-3 pl-4 pr-3 py-3">
        <!-- Icon -->
        <div
          class="w-8 h-8 flex items-center justify-center shrink-0"
          :class="[colors.bg, isTrigger ? 'rounded-full' : 'rounded-lg']"
        >
          <span class="material-symbols-rounded text-lg" :class="colors.text">{{ nodeDef?.icon || 'settings' }}</span>
        </div>

        <!-- Text -->
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-1.5">
            <span v-if="isTrigger" class="material-symbols-rounded text-amber-500 dark:text-amber-400 text-xs">bolt</span>
            <span class="text-sm font-semibold text-surface-800 dark:text-surface-100 truncate">
              {{ node.label || nodeDef?.label || 'Node' }}
            </span>
          </div>
          <div class="text-[11px] text-surface-500 dark:text-surface-400 truncate">
            {{ isDisabled ? 'Disabled' : (nodeDef?.subtitle || node.type) }}
          </div>
        </div>
      </div>

      <!-- Execution status overlay -->
      <div
        v-if="executionStatus"
        class="absolute top-1.5 right-1.5"
      >
        <span
          v-if="executionStatus.status === 'running'"
          class="material-symbols-rounded text-sm text-blue-400 animate-spin"
        >progress_activity</span>
        <span
          v-else-if="executionStatus.status === 'completed'"
          class="material-symbols-rounded text-sm text-emerald-400"
        >check_circle</span>
        <span
          v-else-if="executionStatus.status === 'failed'"
          class="material-symbols-rounded text-sm text-red-400"
        >error</span>
        <span
          v-else-if="executionStatus.status === 'skipped'"
          class="material-symbols-rounded text-sm text-surface-500"
        >block</span>
      </div>
    </div>

    <!-- Input ports (left side) -->
    <div
      v-for="(port, idx) in inputPorts"
      :key="'in-' + port.id"
      class="absolute flex items-center"
      :style="inputPortStyle(idx, inputPorts.length)"
    >
      <div
        class="w-[10px] h-[10px] rounded-full border-2 border-surface-300 dark:border-surface-500 bg-white dark:bg-surface-800 hover:border-primary-400 hover:bg-primary-400/20 transition-colors cursor-crosshair -ml-[5px]"
        :class="{ 'border-primary-400 bg-primary-400/30': isPortConnected(node.uid, port.id, false) }"
        @mouseup="$emit('port-drag-end', { nodeUid: node.uid, portId: port.id })"
      />
      <span
        v-if="port.label"
        class="text-[9px] text-surface-500 dark:text-surface-400 ml-1.5 whitespace-nowrap"
      >{{ port.label }}</span>
    </div>

    <!-- Output ports (right side) -->
    <div
      v-for="(port, idx) in outputPorts"
      :key="'out-' + port.id"
      class="absolute flex items-center"
      :style="outputPortStyle(idx, outputPorts.length)"
    >
      <span
        v-if="port.label"
        class="text-[9px] text-surface-500 dark:text-surface-400 mr-1.5 whitespace-nowrap"
      >{{ port.label }}</span>
      <div
        class="w-[10px] h-[10px] rounded-full border-2 border-surface-300 dark:border-surface-500 bg-white dark:bg-surface-800 hover:border-primary-400 hover:bg-primary-400/20 transition-colors cursor-crosshair"
        :class="{ 'border-primary-400 bg-primary-400/30': isPortConnected(node.uid, port.id, true) }"
        @mousedown.stop="onOutputPortDrag($event, port.id)"
      />
      <!-- Quick-add button -->
      <button
        class="w-5 h-5 ml-1 rounded-full bg-surface-100 dark:bg-surface-700 border border-surface-300 dark:border-surface-500 flex items-center justify-center opacity-0 group-hover:opacity-100 hover:bg-primary-500 hover:border-primary-400 transition-all"
        @click.stop="onQuickAdd(port.id, $event)"
        @mousedown.stop
      >
        <span class="material-symbols-rounded text-xs text-surface-500 dark:text-surface-300">add</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useAutomationHubStore } from '../../stores/automationHub'
import { useNodeRegistry } from '../../composables/useNodeRegistry'

const props = defineProps({
  node: { type: Object, required: true },
  selected: { type: Boolean, default: false },
  connecting: { type: Boolean, default: false },
  executionStatus: { type: Object, default: null },
})

const emit = defineEmits(['mousedown', 'port-drag-start', 'port-drag-end', 'quick-add'])

const store = useAutomationHubStore()
const { getNodeDef, getCategoryColors } = useNodeRegistry()

const nodeDef = computed(() => getNodeDef(props.node.type))
const colors = computed(() => getCategoryColors(props.node.category))
const isTrigger = computed(() => props.node.category === 'trigger')
const isLogic = computed(() => props.node.category === 'logic')
const isDisabled = computed(() => !!props.node.config?.disabled)

const inputPorts = computed(() => nodeDef.value?.inputs || [])
const outputPorts = computed(() => nodeDef.value?.outputs || [{ id: 'output', label: '' }])

const NODE_HEIGHT = 64

const nodeStyle = computed(() => ({
  left: props.node.x + 'px',
  top: props.node.y + 'px',
}))

const shapeClass = computed(() => {
  if (isTrigger.value) return 'rounded-2xl'
  if (isLogic.value) return 'rounded-lg'
  return 'rounded-xl'
})

const cardClasses = computed(() => {
  const classes = ['bg-white dark:bg-surface-800']

  if (isTrigger.value) {
    classes.push('border-amber-300 dark:border-amber-500/30')
  }

  if (props.selected) {
    classes.push('ring-2 ring-primary-400 border-primary-500/50')
  } else if (!isTrigger.value) {
    classes.push('border-surface-200 dark:border-surface-600/50 hover:border-surface-300 dark:hover:border-surface-500')
  } else {
    classes.push('hover:border-amber-400 dark:hover:border-amber-400/50')
  }

  if (props.executionStatus?.status === 'running') {
    classes.push('animate-pulse ring-2 ring-blue-400/50')
  } else if (props.executionStatus?.status === 'failed') {
    classes.push('ring-2 ring-red-400/50')
  }

  return classes.join(' ')
})

function inputPortStyle(idx, count) {
  const spacing = NODE_HEIGHT / (count + 1)
  return {
    left: '0px',
    top: (spacing * (idx + 1)) + 'px',
    transform: 'translateY(-50%)',
  }
}

function outputPortStyle(idx, count) {
  const spacing = NODE_HEIGHT / (count + 1)
  return {
    left: '220px',
    top: (spacing * (idx + 1)) + 'px',
    transform: 'translateX(-5px) translateY(-50%)',
  }
}

function isPortConnected(nodeUid, portId, isOutput) {
  return store.edges.some(e =>
    isOutput
      ? (e.sourceUid === nodeUid && e.sourcePort === portId)
      : (e.targetUid === nodeUid && e.targetPort === portId)
  )
}

function onCardMouseUp() {
  if (props.connecting && inputPorts.value.length) {
    emit('port-drag-end', { nodeUid: props.node.uid, portId: inputPorts.value[0].id })
  }
}

function onOutputPortDrag(e, portId) {
  emit('port-drag-start', { nodeUid: props.node.uid, portId })
}

function onQuickAdd(portId, e) {
  emit('quick-add', {
    nodeUid: props.node.uid,
    portId,
    screenX: e.clientX,
    screenY: e.clientY,
  })
}
</script>
