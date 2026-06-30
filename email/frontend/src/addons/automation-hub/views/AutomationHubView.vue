<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <AppHeader
      current-view="automation-hub"
      icon="settings_suggest"
      title="Workflows"
    >
      <template #title-badge>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>

    <!-- Sub-header with action -->
    <div class="px-4 sm:px-6 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex items-center justify-between shrink-0 gap-2">
      <p class="text-sm text-surface-500 hidden sm:block">Visual workflow automation for boards, CRM, server monitoring and more</p>
      <div class="flex items-center gap-2 sm:gap-3">
        <button
          @click="showConnections = true"
          class="hidden sm:flex items-center gap-2 px-4 py-2 rounded-full border border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 text-sm font-medium hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">cable</span>
          Connections
        </button>
        <button
          @click="onCreateWorkflow"
          class="flex items-center gap-2 px-5 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">add</span>
          New Workflow
        </button>
      </div>
    </div>

    <!-- Connections Modal -->
    <ConnectionsPanel v-if="showConnections" @close="showConnections = false" />

    <!-- Feature Guide -->
    <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6">
      <!-- Stats row -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">
        <div class="bg-white dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
          <div class="text-2xl font-bold text-surface-800 dark:text-surface-100">{{ workflows.length }}</div>
          <div class="text-xs text-surface-500 dark:text-surface-400 mt-1">Total Workflows</div>
        </div>
        <div class="bg-white dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
          <div class="text-2xl font-bold text-emerald-500 dark:text-emerald-400">{{ activeCount }}</div>
          <div class="text-xs text-surface-500 dark:text-surface-400 mt-1">Active</div>
        </div>
        <div class="bg-white dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
          <div class="text-2xl font-bold text-surface-800 dark:text-surface-100">{{ totalRuns }}</div>
          <div class="text-xs text-surface-500 dark:text-surface-400 mt-1">Total Runs</div>
        </div>
        <div class="bg-white dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
          <div class="text-2xl font-bold text-amber-500 dark:text-amber-400">{{ categoryCounts.server || 0 }}</div>
          <div class="text-xs text-surface-500 dark:text-surface-400 mt-1">Server Monitors</div>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="store.workflowsLoading" class="flex items-center justify-center py-20">
        <span class="material-symbols-rounded text-3xl text-surface-400 dark:text-surface-500 animate-spin">progress_activity</span>
      </div>

      <!-- Empty state -->
      <div v-else-if="workflows.length === 0" class="flex flex-col items-center justify-center py-20">
        <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600">settings_suggest</span>
        <h2 class="text-lg font-semibold text-surface-700 dark:text-surface-300 mt-4">No workflows yet</h2>
        <p class="text-sm text-surface-500 mt-2 max-w-md text-center">
          Create your first workflow to automate tasks across boards, CRM, server monitoring, and Telegram.
        </p>
        <button
          @click="onCreateWorkflow"
          class="mt-6 flex items-center gap-2 px-6 py-2.5 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">add</span>
          Create Workflow
        </button>
      </div>

      <!-- Workflow list -->
      <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <div
          v-for="wf in workflows"
          :key="wf.id"
          class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-surface-500 transition-all cursor-pointer group"
          @click="$router.push({ name: 'automation-hub-editor', params: { id: wf.id } })"
        >
          <div class="p-4">
            <div class="flex items-start gap-3">
              <div class="w-10 h-10 rounded-xl bg-primary-500/10 dark:bg-primary-500/15 flex items-center justify-center shrink-0">
                <span class="material-symbols-rounded text-xl text-primary-500 dark:text-primary-400">
                  {{ getCategoryIcon(wf.category) }}
                </span>
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-sm font-semibold text-surface-800 dark:text-surface-100 truncate">{{ wf.name }}</div>
                <div class="text-xs text-surface-500 dark:text-surface-400 mt-0.5 truncate">{{ wf.description || 'No description' }}</div>
              </div>
              <div
                class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                :class="wf.is_active ? 'bg-emerald-100 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-surface-100 dark:bg-surface-600 text-surface-500 dark:text-surface-400'"
              >
                {{ wf.is_active ? 'Active' : 'Inactive' }}
              </div>
            </div>

            <div class="flex items-center gap-4 mt-4 text-xs text-surface-500">
              <span class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">play_arrow</span>
                {{ wf.run_count || 0 }} runs
              </span>
              <span v-if="wf.last_run_at" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">schedule</span>
                {{ formatDate(wf.last_run_at) }}
              </span>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-1 px-3 py-2 border-t border-surface-100 dark:border-surface-700 opacity-0 group-hover:opacity-100 transition-opacity">
            <button
              @click.stop="onToggle(wf)"
              class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
              :title="wf.is_active ? 'Deactivate' : 'Activate'"
            >
              <span class="material-symbols-rounded text-lg">{{ wf.is_active ? 'toggle_on' : 'toggle_off' }}</span>
            </button>
            <button
              @click.stop="onDuplicate(wf)"
              class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
              title="Duplicate"
            >
              <span class="material-symbols-rounded text-lg">content_copy</span>
            </button>
            <div class="flex-1" />
            <button
              @click.stop="onDelete(wf)"
              class="p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/15 text-surface-400 hover:text-red-500 dark:hover:text-red-400 transition-colors"
              title="Delete"
            >
              <span class="material-symbols-rounded text-lg">delete</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Template picker modal -->
    <WorkflowTemplates v-if="showTemplates" @close="showTemplates = false" @select="onTemplateSelect" />

    <MobileBottomNav v-if="isMobile" />

    <StepGuide
      v-if="showStepGuide"
      :title-key="automationHubGuide.titleKey"
      :subtitle-key="automationHubGuide.subtitleKey"
      :header-icon="automationHubGuide.headerIcon"
      :header-color="automationHubGuide.headerColor"
      :storage-key="automationHubGuide.storageKey"
      :steps="automationHubGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAutomationHubStore } from '../stores/automationHub'
import automationHubApi from '../services/automationHubApi'
import AppHeader from '@/components/shared/AppHeader.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import WorkflowTemplates from '../components/templates/WorkflowTemplates.vue'
import ConnectionsPanel from '../components/settings/ConnectionsPanel.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import StepGuide from '@/components/shared/StepGuide.vue'
import { featureGuides } from '@/data/featureGuides'
import { automationHubGuide } from '@/data/stepGuides'

const router = useRouter()
const showConnections = ref(false)
const store = useAutomationHubStore()
const showTemplates = ref(false)
const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.automationHub

const workflows = computed(() => store.workflows)
const activeCount = computed(() => workflows.value.filter(w => w.is_active).length)
const totalRuns = computed(() => workflows.value.reduce((sum, w) => sum + (w.run_count || 0), 0))
const categoryCounts = computed(() => {
  const counts = {}
  for (const w of workflows.value) {
    counts[w.category] = (counts[w.category] || 0) + 1
  }
  return counts
})

function getCategoryIcon(category) {
  const icons = {
    board: 'view_kanban',
    crm: 'business_center',
    server: 'dns',
    telegram: 'send',
    custom: 'settings_suggest',
  }
  return icons[category] || 'settings_suggest'
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  if (diff < 60000) return 'Just now'
  if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago'
  if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago'
  return d.toLocaleDateString()
}

function onCreateWorkflow() {
  showTemplates.value = true
}

async function onTemplateSelect(template) {
  showTemplates.value = false
  try {
    let wf
    if (!template) {
      wf = await store.createWorkflow()
    } else {
      wf = await store.createWorkflowFromTemplate(template)
    }
    if (wf) {
      router.push({ name: 'automation-hub-editor', params: { id: wf.id } })
    }
  } catch (e) {
    console.error('[AutomationHub] Create failed:', e)
  }
}

async function onToggle(wf) {
  try {
    await automationHubApi.toggleWorkflow(wf.id)
    wf.is_active = !wf.is_active
  } catch (e) {
    console.error('[AutomationHub] Toggle failed:', e)
  }
}

async function onDuplicate(wf) {
  try {
    await automationHubApi.duplicateWorkflow(wf.id)
    store.fetchWorkflows()
  } catch (e) {
    console.error('[AutomationHub] Duplicate failed:', e)
  }
}

async function onDelete(wf) {
  if (!confirm(`Delete workflow "${wf.name}"?`)) return
  try {
    await store.deleteWorkflow(wf.id)
  } catch (e) {
    console.error('[AutomationHub] Delete failed:', e)
  }
}

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  store.fetchWorkflows()
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
</script>
