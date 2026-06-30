<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import { useRouter, useRoute } from 'vue-router'
import { useEmailCampaignsStore } from '@/addons/email-marketing/stores/emailCampaigns'
import { useMailingListsStore } from '@/addons/email-marketing/stores/mailingLists'
import { useToastStore } from '@/stores/toast'
import { useAccountsStore } from '@/stores/accounts'
import { useComposeStore } from '@/stores/compose'
import AppHeader from '@/components/shared/AppHeader.vue'
// ComposeModal moved to App.vue as ComposeWindow for cross-view persistence
import UnsubscribeManager from '@/addons/email-marketing/components/unsubscribes/UnsubscribeManager.vue'
import CreateListFromClients from '@/addons/email-marketing/components/mailing-lists/CreateListFromClients.vue'
import CampaignDetail from '@/addons/email-marketing/components/CampaignDetail.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import StepGuide from '@/components/shared/StepGuide.vue'
import { featureGuides } from '@/data/featureGuides'
import { campaignsGuide } from '@/data/stepGuides'

const router = useRouter()
const route = useRoute()
const campaignsStore = useEmailCampaignsStore()
const toast = useToastStore()
const accountsStore = useAccountsStore()
const composeStore = useComposeStore()
const mailingListsStore = useMailingListsStore()

// Feature guide
const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.emailMarketing

// State
const loading = ref(true)
const selectedCampaign = ref(null)
const selectedCampaignId = ref(null)
const showFailedModal = ref(false)
const showUnsubscribes = ref(false)
const showCreateListFromClients = ref(false)
const failedRecipients = ref([])
const refreshInterval = ref(null)

// Filter state
const statusFilter = ref('all')

// Computed
const filteredCampaigns = computed(() => {
  if (statusFilter.value === 'all') {
    return campaignsStore.campaigns
  }
  if (statusFilter.value === 'processing') {
    return campaignsStore.campaigns.filter(c => ['pending', 'processing'].includes(c.status))
  }
  return campaignsStore.campaigns.filter(c => c.status === statusFilter.value)
})

// Methods
async function createNewCampaign() {
  const result = await campaignsStore.createDraft('New Campaign')
  if (result.success) {
    selectedCampaignId.value = result.campaignId
    const found = campaignsStore.campaigns.find(c => c.campaign_id === result.campaignId)
    if (found) selectedCampaign.value = found
  } else {
    toast.error(result.error || 'Failed to create campaign')
  }
}

async function init() {
  loading.value = true
  await campaignsStore.fetchCampaigns()
  await accountsStore.fetchAccounts()
  mailingListsStore.fetchLists()
  loading.value = false

  const qid = route.query.id
  if (qid) {
    selectedCampaignId.value = qid
    const found = campaignsStore.campaigns.find(c => c.campaign_id === qid)
    if (found) selectedCampaign.value = found
    router.replace({ query: {} })
  }
}

function getStatusColor(status) {
  switch (status) {
    case 'pending': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400'
    case 'processing': return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400'
    case 'completed': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
    case 'paused': return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400'
    case 'cancelled': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'
    case 'draft': return 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-400'
    default: return 'bg-surface-100 text-surface-800 dark:bg-surface-700 dark:text-surface-300'
  }
}

function getStatusIcon(status) {
  switch (status) {
    case 'pending': return 'schedule'
    case 'processing': return 'sync'
    case 'completed': return 'check_circle'
    case 'paused': return 'pause_circle'
    case 'cancelled': return 'cancel'
    case 'draft': return 'edit_note'
    default: return 'help'
  }
}

function formatDate(dateString) {
  if (!dateString) return '-'
  const date = new Date(dateString)
  return date.toLocaleString()
}

async function pauseCampaign(campaign) {
  const result = await campaignsStore.pauseCampaign(campaign.campaign_id)
  if (result.success) {
    toast.success('Campaign paused')
  } else {
    toast.error(result.error || 'Failed to pause campaign')
  }
}

async function resumeCampaign(campaign) {
  const result = await campaignsStore.resumeCampaign(campaign.campaign_id)
  if (result.success) {
    toast.success('Campaign resumed')
  } else {
    toast.error(result.error || 'Failed to resume campaign')
  }
}

async function cancelCampaign(campaign) {
  if (!confirm(`Are you sure you want to cancel this campaign? ${campaign.total_recipients - campaign.sent_count} unsent emails will be deleted.`)) {
    return
  }
  
  const result = await campaignsStore.cancelCampaign(campaign.campaign_id)
  if (result.success) {
    toast.success('Campaign cancelled')
  } else {
    toast.error(result.error || 'Failed to cancel campaign')
  }
}

async function deleteCampaign(campaign) {
  if (!confirm(`Permanently delete "${campaign.subject}"? This will remove all data including tracking, analytics and logs. This cannot be undone.`)) {
    return
  }
  
  const result = await campaignsStore.deleteCampaign(campaign.campaign_id)
  if (result.success) {
    toast.success('Campaign deleted')
  } else {
    toast.error(result.error || 'Failed to delete campaign')
  }
}

async function viewFailed(campaign) {
  selectedCampaign.value = campaign
  const result = await campaignsStore.getFailedRecipients(campaign.campaign_id)
  failedRecipients.value = result.failed || []
  showFailedModal.value = true
}

async function retryFailed(campaign) {
  const result = await campaignsStore.retryFailed(campaign.campaign_id)
  if (result.success) {
    toast.success(`${result.retried} emails queued for retry`)
    showFailedModal.value = false
  } else {
    toast.error(result.error || 'Failed to retry')
  }
}

function selectCampaign(campaign) {
  selectedCampaign.value = campaign
  selectedCampaignId.value = campaign.campaign_id
}

function selectCampaignById(campaignId) {
  const found = campaignsStore.campaigns.find(c => c.campaign_id === campaignId)
  if (found) {
    selectCampaign(found)
  }
}

// Auto-refresh campaigns list
function startAutoRefresh() {
  refreshInterval.value = setInterval(async () => {
    await campaignsStore.fetchCampaigns()
  }, 15000)
}

function stopAutoRefresh() {
  if (refreshInterval.value) {
    clearInterval(refreshInterval.value)
    refreshInterval.value = null
  }
}

// Mobile detection
const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

// Lifecycle
onMounted(() => {
  init()
  startAutoRefresh()
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  stopAutoRefresh()
  window.removeEventListener('resize', checkMobile)
})
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="campaigns"
      icon="campaign"
      title="Email Campaigns"
    >
      <template #title-badge>
        <span 
          v-if="campaignsStore.activeCampaigns.length > 0"
          class="px-2 py-0.5 text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-full"
        >
          {{ campaignsStore.activeCampaigns.length }} active
        </span>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>
    
    <!-- Main content -->
    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <!-- Sidebar (hidden on mobile) -->
      <aside class="w-64 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] hidden md:flex flex-col overflow-hidden">
        <!-- New Campaign button -->
        <div class="p-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))] space-y-2">
          <button
            @click="createNewCampaign()"
            class="btn-primary btn-sm w-full"
          >
            <span class="material-symbols-rounded">add</span>
            New Campaign
          </button>
          <button
            @click="showCreateListFromClients = true"
            class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-full text-sm font-medium bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
          >
            <span class="material-symbols-rounded text-base">group_add</span>
            Create List from Clients
          </button>
        </div>
        
        <!-- Filters -->
        <div class="flex-1 overflow-y-auto p-2">
          <button
            @click="statusFilter = 'all'"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-1',
              statusFilter === 'all' 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg">inbox</span>
            <span class="flex-1 text-left">All Campaigns</span>
            <span class="text-xs text-surface-500">{{ campaignsStore.campaigns.length }}</span>
          </button>
          
          <button
            @click="statusFilter = 'draft'"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-1',
              statusFilter === 'draft' 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg text-surface-500">edit_note</span>
            <span class="flex-1 text-left">Drafts</span>
            <span class="text-xs text-surface-500">{{ campaignsStore.draftCampaigns.length }}</span>
          </button>
          
          <button
            @click="statusFilter = 'processing'"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-1',
              statusFilter === 'processing' 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg text-blue-500">sync</span>
            <span class="flex-1 text-left">Sending</span>
            <span class="text-xs text-surface-500">{{ campaignsStore.activeCampaigns.length }}</span>
          </button>
          
          <button
            @click="statusFilter = 'paused'"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-1',
              statusFilter === 'paused' 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg text-orange-500">pause_circle</span>
            <span class="flex-1 text-left">Paused</span>
            <span class="text-xs text-surface-500">{{ campaignsStore.pausedCampaigns.length }}</span>
          </button>
          
          <button
            @click="statusFilter = 'completed'"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-1',
              statusFilter === 'completed' 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg text-green-500">check_circle</span>
            <span class="flex-1 text-left">Completed</span>
            <span class="text-xs text-surface-500">{{ campaignsStore.completedCampaigns.length }}</span>
          </button>
        </div>
        
        <!-- Navigation & Management -->
        <div class="px-2 pb-2 space-y-1 border-t border-surface-200 dark:border-[rgb(var(--color-border))] pt-2">
          <button
            @click="router.push('/mailing-lists')"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-primary-400">contact_mail</span>
            <span class="flex-1 text-left">Mailing Lists</span>
            <span class="material-symbols-rounded text-base text-surface-400">arrow_forward</span>
          </button>
          <button
            @click="showUnsubscribes = true"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-red-400">unsubscribe</span>
            <span class="flex-1 text-left">Unsubscribes</span>
          </button>
          <button
            @click="router.push('/crm/automation')"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-purple-400">smart_toy</span>
            <span class="flex-1 text-left">Automations</span>
            <span class="material-symbols-rounded text-base text-surface-400">arrow_forward</span>
          </button>
        </div>

        <!-- Rate Limits Info -->
        <div class="p-4 border-t border-surface-200 dark:border-[rgb(var(--color-border))]">
          <div class="text-xs text-surface-500 mb-2">Server Limits</div>
          <div class="text-xs text-surface-600 dark:text-surface-400">
            <div class="flex justify-between mb-1">
              <span>Hourly:</span>
              <span>100 emails</span>
            </div>
            <div class="flex justify-between">
              <span>Daily:</span>
              <span>500 emails</span>
            </div>
          </div>
        </div>
      </aside>
      
      <!-- Campaign Content Area -->
      <div :class="isMobile ? 'flex-1 min-w-0 bg-surface-50 dark:bg-surface-900' : 'flex-1 flex flex-col overflow-hidden bg-surface-50 dark:bg-surface-900'">
        
        <!-- Feature Guide -->
        <div v-if="showFeatureGuide" class="px-4 pt-4">
          <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
        </div>

        <!-- Campaign Detail View -->
        <CampaignDetail
          v-if="selectedCampaignId"
          :campaign-id="selectedCampaignId"
          @back="selectedCampaignId = null; selectedCampaign = null"
          @select-parent="selectCampaignById"
        />
        
        <!-- Campaign List -->
        <template v-else>
          <!-- Loading State -->
          <div v-if="loading" class="flex-1 flex items-center justify-center">
            <div class="text-center">
              <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
              <p class="mt-2 text-surface-500">Loading campaigns...</p>
            </div>
          </div>
          
          <!-- Empty State -->
          <div v-else-if="filteredCampaigns.length === 0" class="flex-1 flex items-center justify-center">
            <div class="text-center max-w-md">
              <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600">campaign</span>
              <h3 class="mt-4 text-lg font-semibold text-surface-900 dark:text-surface-100">No campaigns yet</h3>
              <p class="mt-2 text-surface-500">
                Compose a campaign to queue emails for sending with tracking and unsubscribe support.
              </p>
              <div class="mt-4 flex justify-center">
                <button @click="createNewCampaign()" class="btn-primary">
                  <span class="material-symbols-rounded">add</span>
                  New Campaign
                </button>
              </div>
            </div>
          </div>
          
          <!-- Campaign Cards -->
          <div v-else class="flex-1 overflow-y-auto p-6">
            <div class="flex flex-wrap gap-4 max-w-5xl mx-auto">
              <div
                v-for="campaign in filteredCampaigns"
                :key="campaign.campaign_id"
                @click="selectCampaign(campaign)"
                class="campaign-card bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 hover:shadow-md hover:border-primary-300 dark:hover:border-primary-600 px-4 py-3 cursor-pointer transition-all"
              >
                <!-- Header row -->
                <div class="flex items-center justify-between gap-2 mb-2">
                  <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate flex-1 min-w-0">
                    {{ campaign.subject }}
                  </h3>
                  <div class="flex items-center gap-1.5 shrink-0">
                    <span :class="['px-2 py-0.5 rounded-full text-[11px] font-medium flex items-center gap-0.5', getStatusColor(campaign.status)]">
                      <span class="material-symbols-rounded text-xs" :class="campaign.status === 'processing' ? 'animate-spin' : ''">
                        {{ getStatusIcon(campaign.status) }}
                      </span>
                      {{ campaign.status }}
                    </span>
                    <button
                      v-if="['completed', 'cancelled'].includes(campaign.status)"
                      @click.stop="deleteCampaign(campaign)"
                      class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                      title="Delete campaign"
                    >
                      <span class="material-symbols-rounded text-base">delete</span>
                    </button>
                  </div>
                </div>
                
                <!-- Progress row (non-draft) -->
                <div v-if="campaign.status !== 'draft'" class="mb-2">
                  <div class="flex items-center justify-between text-xs mb-1">
                    <span class="text-surface-500">
                      {{ campaign.sent_count }} / {{ campaign.total_recipients }} sent
                    </span>
                    <span class="font-medium text-surface-700 dark:text-surface-300">
                      {{ campaign.progress_percent || 0 }}%
                    </span>
                  </div>
                  <div class="w-full h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div 
                      class="h-full rounded-full transition-all duration-500"
                      :class="campaign.status === 'completed' ? 'bg-green-500' : 'bg-primary-500'"
                      :style="{ width: `${campaign.progress_percent || 0}%` }"
                    ></div>
                  </div>
                </div>

                <!-- Draft info -->
                <div v-else class="mb-2 flex items-center gap-2 text-xs text-surface-500">
                  <span class="material-symbols-rounded text-sm">contact_mail</span>
                  <span v-if="campaign.mailing_list_name" class="text-surface-700 dark:text-surface-300">{{ campaign.mailing_list_name }}</span>
                  <span v-else class="text-surface-400 italic">No mailing list assigned</span>
                </div>
                
                <!-- Source / parent campaign link -->
                <div v-if="campaign.source === 'crm_automation'" class="mb-2 flex items-center gap-1.5 text-xs">
                  <span class="material-symbols-rounded text-sm text-purple-400">smart_toy</span>
                  <span class="text-purple-600 dark:text-purple-400 font-medium">Automation</span>
                  <template v-if="campaign.parent_campaign_id && campaign.parent_campaign_subject">
                    <span class="material-symbols-rounded text-xs text-surface-400">arrow_back</span>
                    <span
                      class="text-primary-500 hover:text-primary-600 cursor-pointer truncate max-w-[180px]"
                      :title="campaign.parent_campaign_subject"
                      @click.stop="selectCampaignById(campaign.parent_campaign_id)"
                    >
                      {{ campaign.parent_campaign_subject }}
                    </span>
                  </template>
                </div>
                
                <!-- Footer row -->
                <div class="flex items-center justify-between text-xs text-surface-500">
                  <span>{{ formatDate(campaign.created_at) }}</span>
                  <div class="flex items-center gap-2">
                    <span v-if="campaign.failed_count > 0" class="text-red-500">
                      {{ campaign.failed_count }} failed
                    </span>
                    <button
                      v-if="campaign.status === 'processing' || campaign.status === 'pending'"
                      @click.stop="pauseCampaign(campaign)"
                      class="px-2 py-0.5 rounded-full bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 hover:bg-orange-200 dark:hover:bg-orange-900/50 transition-colors flex items-center gap-0.5 text-[11px] font-medium"
                    >
                      <span class="material-symbols-rounded text-xs">pause</span>
                      Pause
                    </button>
                    <button
                      v-if="campaign.status === 'paused'"
                      @click.stop="resumeCampaign(campaign)"
                      class="px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-900/50 transition-colors flex items-center gap-0.5 text-[11px] font-medium"
                    >
                      <span class="material-symbols-rounded text-xs">play_arrow</span>
                      Resume
                    </button>
                    <span class="material-symbols-rounded text-surface-400 text-base">chevron_right</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
    
    <!-- ComposeWindow is now rendered globally in App.vue -->
    
    <!-- Unsubscribe Manager Modal -->
    <UnsubscribeManager v-if="showUnsubscribes" @close="showUnsubscribes = false" />
    
    <!-- Create List from Clients Modal -->
    <CreateListFromClients v-if="showCreateListFromClients" @close="showCreateListFromClients = false" @created="showCreateListFromClients = false" />
    
    <!-- Failed Recipients Modal -->
    <Teleport to="body">
      <div 
        v-if="showFailedModal" 
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
        @click.self="showFailedModal = false"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-hidden">
          <!-- Header -->
          <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Failed Recipients</h3>
            <button @click="showFailedModal = false" class="btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <!-- Content -->
          <div class="p-4 overflow-y-auto max-h-96">
            <div v-if="failedRecipients.length === 0" class="text-center py-8 text-surface-500">
              No failed recipients
            </div>
            <div v-else class="space-y-2">
              <div 
                v-for="(recipient, index) in failedRecipients" 
                :key="index"
                class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg"
              >
                <div class="flex items-center justify-between">
                  <div>
                    <p class="font-medium text-surface-900 dark:text-surface-100">{{ recipient.recipient_email }}</p>
                    <p v-if="recipient.recipient_name" class="text-sm text-surface-500">{{ recipient.recipient_name }}</p>
                  </div>
                  <span class="text-xs text-surface-500">{{ recipient.attempts }} attempts</span>
                </div>
                <p v-if="recipient.error_message" class="mt-1 text-sm text-red-600 dark:text-red-400">
                  {{ recipient.error_message }}
                </p>
              </div>
            </div>
          </div>
          
          <!-- Footer -->
          <div class="p-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
            <button @click="showFailedModal = false" class="btn-secondary">
              Close
            </button>
            <button 
              v-if="failedRecipients.length > 0"
              @click="retryFailed(selectedCampaign)"
              class="btn-primary"
            >
              <span class="material-symbols-rounded">refresh</span>
              Retry All
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <MobileBottomNav v-if="isMobile" />

    <StepGuide
      v-if="showStepGuide"
      :title-key="campaignsGuide.titleKey"
      :subtitle-key="campaignsGuide.subtitleKey"
      :header-icon="campaignsGuide.headerIcon"
      :header-color="campaignsGuide.headerColor"
      :storage-key="campaignsGuide.storageKey"
      :steps="campaignsGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<style scoped>
.campaign-card {
  flex: 1 1 100%;
  min-width: 0;
  max-width: 100%;
}

@media (min-width: 768px) {
  .campaign-card {
    flex: 1 1 calc(50% - 0.5rem);
    max-width: calc(50% - 0.5rem);
  }
}

@media (min-width: 1280px) {
  .campaign-card {
    flex: 1 1 calc(33.333% - 0.667rem);
    max-width: calc(33.333% - 0.667rem);
  }
}

.btn-icon {
  @apply p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors;
}

.btn-ghost {
  @apply p-2 rounded-lg text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors;
}

.btn-primary {
  @apply px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-full font-medium transition-colors flex items-center gap-2;
}

.btn-secondary {
  @apply px-4 py-2 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 rounded-full font-medium transition-colors flex items-center gap-2;
}

.btn-sm {
  @apply text-sm px-3 py-1.5;
}
</style>

