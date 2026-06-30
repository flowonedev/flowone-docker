<template>
  <div class="flex flex-col h-[100dvh] bg-surface-50 dark:bg-surface-950 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <AppHeader
      current-view="automation-hub-editor"
      icon="settings_suggest"
      :title="store.currentWorkflow?.name || 'Workflow Editor'"
    />

    <!-- Toolbar -->
    <WorkflowToolbar
      ref="toolbarRef"
      @test="onTestWorkflow"
      @run="onRunWorkflow"
      @toggle-active="onToggleActive"
    />

    <!-- Error banner -->
    <transition name="slide-down">
      <div v-if="testError" class="flex items-center gap-3 px-4 py-2.5 bg-red-50 dark:bg-red-500/10 border-b border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 text-sm">
        <span class="material-symbols-rounded text-lg">error</span>
        <span class="flex-1">{{ testError }}</span>
        <button @click="testError = ''" class="p-1 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/20 transition-colors">
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>
    </transition>

    <!-- Main area: palette + canvas + config -->
    <div class="flex flex-1 overflow-hidden">
      <!-- Left: node palette (collapsible) -->
      <transition name="slide-left">
        <NodePalette v-if="paletteOpen && !isMobile" />
      </transition>
      <button
        v-if="!paletteOpen && !isMobile"
        @click="paletteOpen = true"
        class="w-10 bg-white dark:bg-surface-900 border-r border-surface-200 dark:border-surface-700 flex items-center justify-center hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors"
        title="Show node palette"
      >
        <span class="material-symbols-rounded text-surface-400">chevron_right</span>
      </button>

      <!-- Center: canvas -->
      <div class="flex-1 relative">
        <WorkflowCanvas />

        <!-- Palette toggle (inside canvas) -->
        <button
          v-if="paletteOpen && !isMobile"
          @click="paletteOpen = false"
          class="absolute top-3 left-3 z-20 p-1.5 rounded-lg bg-white/80 dark:bg-surface-800/80 backdrop-blur border border-surface-200 dark:border-surface-600 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          title="Hide palette"
        >
          <span class="material-symbols-rounded text-surface-500 dark:text-surface-400 text-lg">chevron_left</span>
        </button>

        <!-- Zoom controls -->
        <div class="absolute bottom-4 left-4 z-20 flex items-center gap-1 bg-white/80 dark:bg-surface-800/80 backdrop-blur rounded-xl border border-surface-200 dark:border-surface-600 p-1">
          <button
            @click="store.zoom = Math.max(0.1, store.zoom * 0.85)"
            class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">remove</span>
          </button>
          <span
            class="text-xs text-surface-600 dark:text-surface-300 font-medium w-12 text-center cursor-pointer"
            @click="store.zoom = 1; store.panX = 0; store.panY = 0;"
            title="Reset zoom"
          >{{ Math.round(store.zoom * 100) }}%</span>
          <button
            @click="store.zoom = Math.min(3, store.zoom * 1.15)"
            class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">add</span>
          </button>
        </div>
      </div>

      <!-- Right: node config panel -->
      <NodeConfigPanel />
    </div>

    <!-- Execution log panel -->
    <ExecutionPanel
      :open="execPanelOpen"
      :workflow-id="store.currentWorkflow?.id"
      @close="execPanelOpen = false"
    />

    <!-- Execution panel toggle -->
    <button
      v-if="!execPanelOpen"
      @click="execPanelOpen = true"
      class="absolute bottom-4 right-[340px] z-20 flex items-center gap-1.5 px-4 py-2 rounded-full bg-white/80 dark:bg-surface-800/80 backdrop-blur border border-surface-200 dark:border-surface-600 text-sm text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
    >
      <span class="material-symbols-rounded text-lg">history</span>
      Execution Log
    </button>

    <!-- Loading overlay -->
    <div
      v-if="loading"
      class="absolute inset-0 z-50 bg-white/80 dark:bg-surface-950/80 flex items-center justify-center"
    >
      <div class="flex flex-col items-center gap-3">
        <span class="material-symbols-rounded text-4xl text-primary-500 dark:text-primary-400 animate-spin">progress_activity</span>
        <span class="text-sm text-surface-500 dark:text-surface-400">Loading workflow...</span>
      </div>
    </div>

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAutomationHubStore } from '../stores/automationHub'
import automationHubApi from '../services/automationHubApi'
import AppHeader from '@/components/shared/AppHeader.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import WorkflowToolbar from '../components/toolbar/WorkflowToolbar.vue'
import WorkflowCanvas from '../components/canvas/WorkflowCanvas.vue'
import NodePalette from '../components/sidebar/NodePalette.vue'
import NodeConfigPanel from '../components/sidebar/NodeConfigPanel.vue'
import ExecutionPanel from '../components/execution/ExecutionPanel.vue'

const route = useRoute()
const store = useAutomationHubStore()

const loading = ref(true)
const paletteOpen = ref(true)
const execPanelOpen = ref(false)
const testError = ref('')
const toolbarRef = ref(null)

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(async () => {
  const id = route.params.id
  if (id) {
    try {
      await store.loadWorkflow(id)
    } catch (e) {
      console.error('[AutomationHub] Failed to load workflow:', e)
    }
  }
  loading.value = false
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onBeforeUnmount(() => {
  store.resetEditor()
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

async function onTestWorkflow() {
  if (!store.currentWorkflow) return
  testError.value = ''

  const hasTrigger = store.nodesArray.some(n => n.type?.startsWith('trigger.'))
  if (!hasTrigger) {
    testError.value = 'Workflow needs a trigger node (amber) as the starting point. Add a Schedule, Manual, Webhook, or other trigger node first.'
    return
  }

  try {
    if (store.isDirty) {
      await store.saveWorkflow()
    }
    const res = await automationHubApi.testWorkflow(store.currentWorkflow.id)
    const executionId = res.data?.data?.execution_id
    if (executionId) {
      store.activeExecutionId = executionId
      execPanelOpen.value = true
    }
  } catch (e) {
    const msg = e.response?.data?.message || e.response?.data?.error || e.message || 'Unknown error'
    testError.value = msg
    console.error('[AutomationHub] Test failed:', msg)
  }
}

async function onRunWorkflow() {
  if (!store.currentWorkflow) return
  testError.value = ''

  const hasTrigger = store.nodesArray.some(n => n.type?.startsWith('trigger.'))
  if (!hasTrigger) {
    testError.value = 'Workflow needs a trigger node to run.'
    return
  }

  if (toolbarRef.value) toolbarRef.value.running = true
  try {
    if (store.isDirty) {
      await store.saveWorkflow()
    }
    const res = await automationHubApi.executeWorkflow(store.currentWorkflow.id)
    const executionId = res.data?.data?.execution_id
    if (executionId) {
      store.activeExecutionId = executionId
      execPanelOpen.value = true
    }
  } catch (e) {
    const msg = e.response?.data?.message || e.response?.data?.error || e.message || 'Unknown error'
    testError.value = msg
    console.error('[AutomationHub] Run failed:', msg)
  } finally {
    if (toolbarRef.value) toolbarRef.value.running = false
  }
}

async function onToggleActive() {
  if (!store.currentWorkflow) return
  try {
    await automationHubApi.toggleWorkflow(store.currentWorkflow.id)
    store.currentWorkflow.is_active = !store.currentWorkflow.is_active
  } catch (e) {
    console.error('[AutomationHub] Toggle failed:', e)
  }
}
</script>

<style scoped>
.slide-left-enter-active,
.slide-left-leave-active {
  transition: all 0.2s ease;
}
.slide-left-enter-from,
.slide-left-leave-to {
  transform: translateX(-100%);
  opacity: 0;
}
</style>
