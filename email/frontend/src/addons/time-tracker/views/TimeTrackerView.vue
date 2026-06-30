<script setup>
import { ref, computed, onMounted, onUnmounted, watch, defineAsyncComponent } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useThemeStore } from '@/stores/theme'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'
import AppHeader from '@/components/shared/AppHeader.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { timeTrackerGuide } from '@/data/stepGuides'

const MyTimeTab = defineAsyncComponent(() => import('@/addons/time-tracker/components/MyTimeTab.vue'))
const TeamTimeTab = defineAsyncComponent(() => import('@/addons/time-tracker/components/TeamTimeTab.vue'))
const CompanyTimeTab = defineAsyncComponent(() => import('@/addons/time-tracker/components/CompanyTimeTab.vue'))
const ManualClientTimeDialog = defineAsyncComponent(() => import('@/addons/time-tracker/components/ManualClientTimeDialog.vue'))

const router = useRouter()
const route = useRoute()
const theme = useThemeStore()
const colleaguesStore = useColleaguesStore()

const showManualDialog = ref(false)

// Team/Company tabs expose every member's tracked time — admin only (matches backend gate)
const isAdmin = computed(() => colleaguesStore.isAdmin)

const tabDefs = computed(() => {
  const tabs = [{ key: 'my', label: 'My Time', icon: 'person', color: 'primary' }]
  if (isAdmin.value) tabs.push({ key: 'team', label: 'Team', icon: 'group', color: 'indigo' })
  if (isAdmin.value) tabs.push({ key: 'company', label: 'Company', icon: 'corporate_fare', color: 'violet' })
  return tabs
})

const activeTab = ref('my')
const myTabRef = ref(null)
const teamTabRef = ref(null)
const companyTabRef = ref(null)

function initTabFromQuery() {
  const t = route.query.tab
  if (t === 'team' && isAdmin.value) activeTab.value = 'team'
  else if (t === 'company' && isAdmin.value) activeTab.value = 'company'
  else activeTab.value = 'my'
}

watch(() => route.query.tab, () => initTabFromQuery())

const viewInfoKey = computed(() => {
  if (activeTab.value === 'team') return 'timeTeam'
  if (activeTab.value === 'company') return 'timeCompany'
  return 'timeMyTime'
})

const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.timeTracker

const period = ref('week')
const selectedClientId = ref(null)
const clients = ref([])

const boardIdFilter = computed(() => route.query.board_id ? Number(route.query.board_id) : null)

const periods = [
  { value: 'today', label: 'Today', icon: 'today' },
  { value: 'week', label: 'This Week', icon: 'date_range' },
  { value: 'month', label: 'This Month', icon: 'calendar_month' },
  { value: 'year', label: 'This Year', icon: 'event_note' },
  { value: 'all', label: 'All Time', icon: 'all_inclusive' },
]

async function loadClients() {
  try {
    const response = await api.get('/clients')
    if (response.data.success) {
      const d = response.data.data
      clients.value = Array.isArray(d) ? d : (d?.clients || [])
    }
  } catch (error) {
    console.error('Failed to load clients:', error)
  }
}

function setTab(key) {
  activeTab.value = key
  router.replace({ query: { ...route.query, tab: key } })
}

function selectClient(id) {
  selectedClientId.value = id
  router.replace({ query: { ...route.query, client_id: id } })
}

function clearClient() {
  selectedClientId.value = null
  const q = { ...route.query }
  delete q.client_id
  router.replace({ query: q })
}

function refreshActiveTab() {
  if (activeTab.value === 'my' && myTabRef.value?.refresh) myTabRef.value.refresh()
  else if (activeTab.value === 'team' && teamTabRef.value?.refresh) teamTabRef.value.refresh()
  else if (activeTab.value === 'company' && companyTabRef.value?.refresh) companyTabRef.value.refresh()
}

function onManualTimeSaved() {
  showManualDialog.value = false
  refreshActiveTab()
}

const isMobile = ref(false)
function checkMobile() { isMobile.value = window.innerWidth < 768 }

onMounted(async () => {
  if (!colleaguesStore.loaded) {
    try { await colleaguesStore.fetchColleagues() } catch (e) { /* tabs stay personal-only */ }
  }
  await loadClients()
  if (route.query.client_id) selectedClientId.value = Number(route.query.client_id)
  else if (route.query.client) selectedClientId.value = Number(route.query.client)
  if (route.query.period) period.value = route.query.period
  initTabFromQuery()
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => { window.removeEventListener('resize', checkMobile) })
</script>

<template>
  <div class="h-[100dvh] flex flex-col ambient-tint" :class="[theme.isDark ? 'dark bg-surface-900' : 'bg-gray-50', isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden']">
    <AppHeader current-view="time" icon="timer" title="Time Tracker">
      <template #title-badge>
        <ViewInfoButton :view-key="viewInfoKey" />
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>

    <main class="flex-1 overflow-y-auto p-4 lg:p-6">
      <!-- Tab bar -->
      <div class="flex items-center gap-1 mb-5 bg-white dark:bg-surface-800 rounded-full p-1 shadow-sm border border-surface-200 dark:border-surface-700 w-fit">
        <button
          v-for="tab in tabDefs" :key="tab.key"
          @click="setTab(tab.key)"
          :class="[
            'flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-full transition-all',
            activeTab === tab.key
              ? `bg-${tab.color}-500 text-white shadow-sm`
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
        >
          <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
          <span class="hidden sm:inline">{{ tab.label }}</span>
        </button>
      </div>

      <!-- Shared filters (period + client + refresh + log) -->
      <div class="flex flex-wrap items-center gap-3 mb-6">
        <div class="flex bg-white dark:bg-surface-800 rounded-full p-1 shadow-sm border border-surface-200 dark:border-surface-700">
          <button
            v-for="p in periods" :key="p.value" @click="period = p.value"
            :class="[
              'flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-full transition-all',
              period === p.value
                ? 'bg-primary-500 text-white shadow-sm'
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ p.icon }}</span>
            <span class="hidden sm:inline">{{ p.label }}</span>
          </button>
        </div>

        <div class="relative">
          <select
            v-model="selectedClientId"
            class="appearance-none pl-10 pr-8 py-2 rounded-full bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 text-sm text-surface-700 dark:text-surface-300 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 min-w-[180px]"
          >
            <option :value="null">All Clients</option>
            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.display_name || c.domain }}</option>
          </select>
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">groups</span>
          <span class="material-symbols-rounded absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 text-sm">expand_more</span>
        </div>

        <button @click="refreshActiveTab" class="p-2 rounded-lg text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors" title="Refresh">
          <span class="material-symbols-rounded">refresh</span>
        </button>

        <button
          @click="showManualDialog = true"
          class="flex items-center gap-1.5 px-4 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors shadow-sm"
        >
          <span class="material-symbols-rounded text-lg">more_time</span>
          <span class="hidden sm:inline">Log Time</span>
        </button>
      </div>

      <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />

      <!-- Tab content -->
      <MyTimeTab
        v-show="activeTab === 'my'"
        ref="myTabRef"
        :period="period"
        :client-id="selectedClientId"
        :clients="clients"
        @select-client="selectClient"
        @clear-client="clearClient"
      />

      <TeamTimeTab
        v-if="isAdmin && activeTab === 'team'"
        ref="teamTabRef"
        :period="period"
        :client-id="selectedClientId"
      />

      <CompanyTimeTab
        v-if="isAdmin && activeTab === 'company'"
        ref="companyTabRef"
        :period="period"
        :client-id="selectedClientId"
      />
    </main>

    <MobileBottomNav v-if="isMobile" />

    <StepGuide
      v-if="showStepGuide"
      :title-key="timeTrackerGuide.titleKey"
      :subtitle-key="timeTrackerGuide.subtitleKey"
      :header-icon="timeTrackerGuide.headerIcon"
      :header-color="timeTrackerGuide.headerColor"
      :storage-key="timeTrackerGuide.storageKey"
      :steps="timeTrackerGuide.steps"
      @close="showStepGuide = false"
    />

    <ManualClientTimeDialog
      v-if="showManualDialog"
      :client-id="selectedClientId"
      :clients="clients"
      @close="showManualDialog = false"
      @saved="onManualTimeSaved"
    />
  </div>
</template>
