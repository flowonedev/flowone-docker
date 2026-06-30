<script setup>
/**
 * CrmPipelineView - Kanban-style sales pipeline
 * Displays deals organized by stage columns with drag-drop support.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import AppHeader from '@/components/shared/AppHeader.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CrmSidebar from '../components/CrmSidebar.vue'
import CrmDealCard from '../components/CrmDealCard.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { crmPipelineGuide } from '@/data/stepGuides'

const router = useRouter()

const toast = useToastStore()

const pipeline = ref([])
const summary = ref({})
const loading = ref(true)
const showNewDeal = ref(false)
const clients = ref([])
const velocityData = ref(null)

const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.crmPipeline

const newDeal = ref({ client_id: '', title: '', expected_value: '', probability: 50, pipeline_stage: 'lead' })
const newDealValueDisplay = ref('')

const stageLabels = {
  lead: 'Lead', contacted: 'Contacted', proposal: 'Proposal',
  negotiation: 'Negotiation', won: 'Won', lost: 'Lost',
}

const stageColors = {
  lead: 'bg-surface-200 dark:bg-surface-600', contacted: 'bg-blue-200 dark:bg-blue-500/40',
  proposal: 'bg-purple-200 dark:bg-purple-500/40', negotiation: 'bg-amber-200 dark:bg-amber-500/40',
  won: 'bg-green-200 dark:bg-green-500/40', lost: 'bg-red-200 dark:bg-red-500/40',
}

const dragData = ref(null)

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(async () => {
  await Promise.all([fetchPipeline(), fetchClients(), fetchVelocity()])
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

async function fetchPipeline() {
  loading.value = true
  try {
    const res = await api.get('/crm/deals/pipeline')
    if (res.data?.success) {
      pipeline.value = res.data.data?.pipeline || []
      summary.value = res.data.data?.summary || {}
    }
  } catch (e) {
    toast.error('Failed to load pipeline')
  } finally {
    loading.value = false
  }
}

async function fetchClients() {
  try {
    const res = await api.get('/clients')
    if (res.data?.success) {
      clients.value = res.data.data?.clients || res.data.data || []
    }
  } catch (e) { /* ignore */ }
}

async function fetchVelocity() {
  try {
    const [forecastRes, funnelRes] = await Promise.all([
      api.get('/crm/reports/forecast', { params: { months: 3 } }),
      api.get('/crm/reports/funnel'),
    ])
    velocityData.value = {
      weighted_forecast: forecastRes.data?.data?.total_weighted ?? 0,
      avg_days_to_close: funnelRes.data?.data?.avg_days_to_close ?? null,
      win_rate: funnelRes.data?.data?.win_rate ?? 0,
    }
  } catch (e) { /* non-critical */ }
}

async function createDeal() {
  if (!newDeal.value.client_id || !newDeal.value.title.trim()) {
    toast.error('Client and title are required')
    return
  }
  try {
    await api.post('/crm/deals', newDeal.value)
    toast.success('Deal created')
    showNewDeal.value = false
    newDeal.value = { client_id: '', title: '', expected_value: '', probability: 50, pipeline_stage: 'lead' }
    newDealValueDisplay.value = ''
    fetchPipeline()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create deal')
  }
}

// Drag & drop handlers
function onDragStart(deal) {
  dragData.value = deal
}

function onDragEnd() {
  dragData.value = null
}

function onDragOver(e) {
  e.preventDefault()
}

async function onDrop(stage) {
  if (!dragData.value || dragData.value.pipeline_stage === stage) return

  const deal = dragData.value
  dragData.value = null

  // Optimistic update
  const oldStageCol = pipeline.value.find(p => p.stage === deal.pipeline_stage)
  const newStageCol = pipeline.value.find(p => p.stage === stage)
  if (oldStageCol) {
    oldStageCol.deals = oldStageCol.deals.filter(d => d.id !== deal.id)
    oldStageCol.count--
  }
  deal.pipeline_stage = stage
  if (newStageCol) {
    newStageCol.deals.push(deal)
    newStageCol.count++
  }

  try {
    const payload = { pipeline_stage: stage }
    if (stage === 'lost') {
      const reason = prompt('Why was this deal lost?')
      if (reason) payload.lost_reason = reason
    }
    await api.put(`/crm/deals/${deal.id}/stage`, payload)
    fetchPipeline()
  } catch (e) {
    toast.error('Failed to update stage')
    fetchPipeline()
  }
}

function formatMoney(v, currency = 'HUF') {
  return new Intl.NumberFormat('hu-HU', { style: 'currency', currency, maximumFractionDigits: 0 }).format(v || 0)
}

function formatNumber(v) {
  return new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 0 }).format(v || 0)
}

function onNewDealValueBlur() {
  const num = parseInt(String(newDealValueDisplay.value).replace(/[^\d]/g, ''), 10)
  newDeal.value.expected_value = isNaN(num) ? '' : num
  newDealValueDisplay.value = isNaN(num) ? '' : formatNumber(num)
}
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- App Header -->
    <AppHeader
      current-view="crm-pipeline"
      icon="conversion_path"
      title="Deals & Pipeline"
    >
      <template #title-badge>
        <ViewInfoButton view-key="crmPipeline" />
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>

    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <CrmSidebar />

      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 flex flex-col overflow-hidden'">
    <!-- Sub-header with stats + action -->
    <div class="flex flex-wrap items-center justify-between gap-2 px-4 sm:px-6 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))]">
      <div class="flex items-center gap-6 min-w-0">
        <p class="text-sm text-surface-500 truncate">
          {{ summary.active_count || 0 }} deals · {{ formatMoney(summary.pipeline_value) }}
        </p>
        <!-- Velocity stats -->
        <div v-if="velocityData" class="hidden md:flex items-center gap-4 text-xs">
          <span class="flex items-center gap-1 text-surface-500">
            <span class="material-symbols-rounded text-sm text-blue-500">trending_up</span>
            Forecast (3m): <span class="font-semibold text-surface-700 dark:text-surface-200">{{ formatMoney(velocityData.weighted_forecast) }}</span>
          </span>
          <span v-if="velocityData.avg_days_to_close" class="flex items-center gap-1 text-surface-500">
            <span class="material-symbols-rounded text-sm text-amber-500">speed</span>
            Avg close: <span class="font-semibold text-surface-700 dark:text-surface-200">{{ velocityData.avg_days_to_close }}d</span>
          </span>
          <span class="flex items-center gap-1 text-surface-500">
            <span class="material-symbols-rounded text-sm text-green-500">emoji_events</span>
            Win rate: <span class="font-semibold text-surface-700 dark:text-surface-200">{{ velocityData.win_rate }}%</span>
          </span>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button @click="showNewDeal = true"
                class="px-4 py-2 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium flex items-center gap-2 transition-colors">
          <span class="material-symbols-rounded text-lg">add</span>
          New Deal
        </button>
      </div>
    </div>

    <!-- Feature Guide -->
    <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <!-- Kanban Board -->
    <div v-else class="flex-1 overflow-x-auto p-4">
      <div class="flex gap-4 min-w-max h-full">
        <div v-for="col in pipeline" :key="col.stage"
             class="w-72 flex flex-col bg-surface-50 dark:bg-surface-800/50 rounded-xl border border-surface-200 dark:border-surface-700"
             @dragover="onDragOver"
             @drop="onDrop(col.stage)">
          <!-- Column Header -->
          <div class="p-3 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2">
              <div :class="['w-3 h-3 rounded-full', stageColors[col.stage]]"></div>
              <span class="font-semibold text-sm text-surface-800 dark:text-white">{{ stageLabels[col.stage] }}</span>
              <span class="ml-auto text-xs font-medium bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-300 px-2 py-0.5 rounded-full">
                {{ col.count }}
              </span>
            </div>
            <p v-if="col.total_value > 0" class="text-xs text-surface-400 mt-1">{{ formatMoney(col.total_value) }}</p>
          </div>

          <!-- Cards -->
          <div class="flex-1 overflow-auto p-2 space-y-2">
            <CrmDealCard
              v-for="deal in col.deals" :key="deal.id"
              :deal="deal"
              :clients="clients"
              draggable="true"
              @dragstart="onDragStart(deal)"
              @dragend="onDragEnd"
              @updated="fetchPipeline"
            />

            <!-- Empty state -->
            <div v-if="col.deals.length === 0"
                 class="p-4 text-center text-xs text-surface-400 border-2 border-dashed border-surface-200 dark:border-surface-700 rounded-lg">
              Drag deals here
            </div>
          </div>
        </div>
      </div>
    </div>

      </div><!-- end flex-1 content -->
    </div><!-- end flex sidebar+content -->

    <!-- New Deal Modal -->
    <Teleport to="body">
      <div v-if="showNewDeal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showNewDeal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
          <h2 class="text-lg font-bold text-surface-900 dark:text-white mb-4">New Deal</h2>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Client *</label>
              <select v-model="newDeal.client_id"
                      class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                <option value="">Select client...</option>
                <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.display_name || c.domain }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Title *</label>
              <input v-model="newDeal.title" placeholder="Deal title"
                     class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Expected Value</label>
                <input v-model="newDealValueDisplay" type="text" inputmode="numeric" placeholder="0"
                       @blur="onNewDealValueBlur"
                       class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Probability %</label>
                <input v-model.number="newDeal.probability" type="number" min="0" max="100"
                       class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Stage</label>
              <select v-model="newDeal.pipeline_stage"
                      class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                <option v-for="(label, key) in stageLabels" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
          </div>
          <div class="flex justify-end gap-3 mt-6">
            <button @click="showNewDeal = false" class="px-4 py-2 text-sm text-surface-500">Cancel</button>
            <button @click="createDeal"
                    class="px-6 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
              Create Deal
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <StepGuide
      v-if="showStepGuide"
      :title-key="crmPipelineGuide.titleKey"
      :subtitle-key="crmPipelineGuide.subtitleKey"
      :header-icon="crmPipelineGuide.headerIcon"
      :header-color="crmPipelineGuide.headerColor"
      :storage-key="crmPipelineGuide.storageKey"
      :steps="crmPipelineGuide.steps"
      @close="showStepGuide = false"
    />

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>
