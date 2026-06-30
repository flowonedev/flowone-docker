<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useMailingListsStore } from '@/addons/email-marketing/stores/mailingLists'
import { useEmailCampaignsStore } from '@/addons/email-marketing/stores/emailCampaigns'
import { useToastStore } from '@/stores/toast'
import RichTextEditor from '@/components/RichTextEditor.vue'

const props = defineProps({
  campaignId: { type: String, required: true }
})

const emit = defineEmits(['back', 'select-parent'])

const mailingListsStore = useMailingListsStore()
const campaignsStore = useEmailCampaignsStore()
const toast = useToastStore()

const loading = ref(true)
const analytics = ref(null)
const loadError = ref(null)
const activeTab = ref('overview')
const recipientSearch = ref('')
const expandedLink = ref(null)
const expandedRecipient = ref(null)
const mailingLists = ref([])
const selectedMailingListId = ref(null)
const showSendConfirm = ref(false)
const sendingDraft = ref(false)

const editSubject = ref('')
const editBody = ref('')
const editorRef = ref(null)
const savingDraft = ref(false)
const showMergeTagPicker = ref(false)

const mergeTagVariables = [
  { key: '{name}', label: 'Name', icon: 'person' },
  { key: '{email}', label: 'Email', icon: 'mail' },
  { key: '{phone}', label: 'Phone', icon: 'phone' },
  { key: '{position}', label: 'Position', icon: 'badge' },
  { key: '{company}', label: 'Company', icon: 'business' },
]

async function loadAnalytics() {
  loading.value = true
  loadError.value = null
  const result = await campaignsStore.fetchCampaignAnalytics(props.campaignId)
  if (result.success) {
    if (result.campaign?.status === 'draft') {
      editSubject.value = result.campaign.subject || ''
      if (result.campaign.body_html_b64) {
        editBody.value = decodeURIComponent(escape(atob(result.campaign.body_html_b64)))
      } else {
        editBody.value = result.campaign.body_html || ''
      }
      selectedMailingListId.value = result.campaign.mailing_list_id || null
    }
    analytics.value = result
    if (result.campaign?.status === 'draft') {
      mailingLists.value = mailingListsStore.lists?.length ? mailingListsStore.lists : []
      if (!mailingLists.value.length) {
        await mailingListsStore.fetchLists()
        mailingLists.value = mailingListsStore.lists || []
      }
    }
    if (result.campaign?.status === 'draft' && activeTab.value === 'overview') {
      activeTab.value = 'content'
    }
  } else {
    loadError.value = result.error || 'Failed to load analytics'
    console.error('[CampaignDetail] Load failed:', result.error)
  }
  loading.value = false
}

const campaign = computed(() => {
  const c = analytics.value?.campaign
  if (c && c.body_html_b64 && !c._decoded) {
    try {
      c.body_html = decodeURIComponent(escape(atob(c.body_html_b64)))
      c._decoded = true
    } catch (e) { /* ignore decode errors */ }
  }
  return c
})
const isDraft = computed(() => campaign.value?.status === 'draft')
const summary = computed(() => analytics.value?.summary)
const recipients = computed(() => analytics.value?.recipients || [])
const links = computed(() => analytics.value?.links || [])
const blockStats = computed(() => analytics.value?.block_stats || [])
const unsubscribes = computed(() => analytics.value?.unsubscribes || [])
const activityLog = computed(() => analytics.value?.activity_log || [])

const filteredRecipients = computed(() => {
  if (!recipientSearch.value) return recipients.value
  const q = recipientSearch.value.toLowerCase()
  return recipients.value.filter(r =>
    r.recipient_email.toLowerCase().includes(q) ||
    (r.recipient_name || '').toLowerCase().includes(q)
  )
})

const deliveredRecipients = computed(() => recipients.value.filter(r => r.status === 'sent'))
const failedRecipients = computed(() => recipients.value.filter(r => r.status === 'failed'))
const openedRecipients = computed(() => recipients.value.filter(r => r.opened))
const clickedRecipients = computed(() => recipients.value.filter(r => r.clicked))
const unsubRecipients = computed(() => recipients.value.filter(r => r.unsubscribed))

const tabs = computed(() => {
  if (isDraft.value) {
    return [
      { id: 'content', label: 'Email Content', icon: 'article' },
      { id: 'mailing_list', label: 'Mailing List', icon: 'contact_mail' },
      { id: 'activity', label: 'Activity Log', icon: 'history' },
    ]
  }
  return [
    { id: 'overview', label: 'Recipients', icon: 'group' },
    { id: 'content', label: 'Email Content', icon: 'article' },
    { id: 'blocks', label: 'Blocks', icon: 'widgets', count: blockStats.value.length || null },
    { id: 'links', label: 'Links', icon: 'link', count: links.value.length },
    { id: 'unsubscribes', label: 'Unsubscribes', icon: 'unsubscribe', count: unsubscribes.value.length },
    { id: 'activity', label: 'Activity Log', icon: 'history' },
  ]
})

function getStatusColor(status) {
  const map = {
    sent: 'text-green-600 dark:text-green-400',
    failed: 'text-red-600 dark:text-red-400',
    pending: 'text-yellow-600 dark:text-yellow-400',
    rate_limited: 'text-orange-600 dark:text-orange-400',
    skipped_unsubscribed: 'text-surface-500',
    sending: 'text-blue-600 dark:text-blue-400',
  }
  return map[status] || 'text-surface-500'
}

function getStatusIcon(status) {
  const map = {
    sent: 'check_circle',
    failed: 'error',
    pending: 'schedule',
    rate_limited: 'hourglass_top',
    skipped_unsubscribed: 'unsubscribe',
    sending: 'sync',
  }
  return map[status] || 'help'
}

function getStatusLabel(status) {
  const map = {
    sent: 'Delivered',
    failed: 'Failed',
    pending: 'Pending',
    rate_limited: 'Rate Limited',
    skipped_unsubscribed: 'Skipped (Unsubscribed)',
    sending: 'Sending',
  }
  return map[status] || status
}

function getCampaignStatusColor(status) {
  const map = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    paused: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  }
  return map[status] || 'bg-surface-100 text-surface-800'
}

function formatDate(dateString) {
  if (!dateString) return '-'
  return new Date(dateString).toLocaleString()
}

function formatTime(dateString) {
  if (!dateString) return '-'
  return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatUrl(url) {
  if (!url) return ''
  try {
    const u = new URL(url)
    return u.hostname + u.pathname.substring(0, 40) + (u.pathname.length > 40 ? '...' : '')
  } catch {
    return url.substring(0, 50) + (url.length > 50 ? '...' : '')
  }
}

function getLogIcon(eventType) {
  const map = {
    queued: 'playlist_add',
    sent: 'send',
    failed: 'error',
    paused: 'pause',
    resumed: 'play_arrow',
    completed: 'check_circle',
    cancelled: 'cancel',
    retry: 'refresh',
  }
  return map[eventType] || 'info'
}

function getLogColor(eventType) {
  const map = {
    queued: 'text-blue-500',
    sent: 'text-green-500',
    failed: 'text-red-500',
    paused: 'text-orange-500',
    resumed: 'text-green-500',
    completed: 'text-green-600',
    cancelled: 'text-red-500',
    retry: 'text-yellow-500',
  }
  return map[eventType] || 'text-surface-500'
}

function toggleRecipient(recipientId) {
  expandedRecipient.value = String(expandedRecipient.value) === String(recipientId) ? null : recipientId
}

function minuteKey(date) {
  const d = new Date(date || 0)
  return `${d.getFullYear()}-${String(d.getMonth()).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`
}

function groupOpenEvents(events) {
  if (!events || !events.length) return events
  const grouped = []
  const seen = new Map()
  for (const ev of events) {
    const key = `${ev.ip || ''}|${minuteKey(ev.at)}`
    if (seen.has(key)) {
      seen.get(key).count++
    } else {
      const entry = { ...ev, count: 1 }
      seen.set(key, entry)
      grouped.push(entry)
    }
  }
  return grouped
}

function groupClickEvents(events) {
  if (!events || !events.length) return events
  const grouped = []
  const seen = new Map()
  for (const ev of events) {
    const key = `${ev.url || ''}|${ev.ip || ''}|${minuteKey(ev.clicked_at)}`
    if (seen.has(key)) {
      const existing = seen.get(key)
      existing.count++
      if (!existing.email && ev.email) existing.email = ev.email
    } else {
      const entry = { ...ev, count: 1 }
      seen.set(key, entry)
      grouped.push(entry)
    }
  }
  return grouped
}

function groupLinkTabEvents(events) {
  if (!events || !events.length) return events
  const grouped = []
  const seen = new Map()
  for (const ev of events) {
    const key = `${ev.ip || ''}|${minuteKey(ev.at)}`
    if (seen.has(key)) {
      const existing = seen.get(key)
      existing.count++
      if (!existing.email && ev.email) existing.email = ev.email
    } else {
      const entry = { ...ev, count: 1 }
      seen.set(key, entry)
      grouped.push(entry)
    }
  }
  return grouped
}

function getBlockIcon(blockType) {
  const map = {
    text: 'notes',
    media: 'image',
    layout: 'view_column',
    cta: 'ads_click',
    custom: 'dashboard_customize',
  }
  return map[blockType] || 'widgets'
}

function formatShortUrl(url) {
  if (!url) return ''
  try {
    const u = new URL(url)
    return u.hostname + u.pathname.substring(0, 30) + (u.pathname.length > 30 ? '...' : '')
  } catch {
    return url.substring(0, 40) + (url.length > 40 ? '...' : '')
  }
}

async function assignMailingList() {
  if (!campaign.value) return
  const result = await campaignsStore.updateDraft(campaign.value.campaign_id, {
    mailing_list_id: selectedMailingListId.value
  })
  if (result.success) {
    if (analytics.value?.campaign) {
      analytics.value.campaign.mailing_list_id = selectedMailingListId.value
      const list = mailingListsStore.lists.find(l => l.id === selectedMailingListId.value)
      analytics.value.campaign.mailing_list_name = list?.name || null
    }
  }
}

async function saveDraftContent() {
  if (!campaign.value) return
  savingDraft.value = true
  
  const editorHtml = editorRef.value?.editor?.getHTML?.() || ''
  const bodyToSave = editorHtml || editBody.value
  
  const result = await campaignsStore.updateDraft(campaign.value.campaign_id, {
    subject: editSubject.value,
    body_html: bodyToSave,
    body_text: '',
  })
  savingDraft.value = false
  if (result.success) {
    toast.success('Draft saved')
    showMergeTagPicker.value = false
    editBody.value = bodyToSave
    if (analytics.value?.campaign) {
      analytics.value.campaign.subject = editSubject.value
      analytics.value.campaign.body_html = bodyToSave
    }
  } else {
    toast.error(result.error || 'Failed to save draft')
  }
}

function onEditorUpdate(val) {
  editBody.value = val
}

function insertMergeTag(tag) {
  editBody.value += tag
  showMergeTagPicker.value = false
}

async function handleSendDraft() {
  if (!campaign.value) return
  sendingDraft.value = true
  const editorHtml = editorRef.value?.editor?.getHTML?.() || editBody.value
  const saveResult = await campaignsStore.updateDraft(campaign.value.campaign_id, {
    subject: editSubject.value,
    body_html: editorHtml,
    body_text: '',
  })
  if (!saveResult.success) {
    sendingDraft.value = false
    toast.error('Failed to save draft before sending')
    return
  }
  const result = await campaignsStore.finalizeDraft(campaign.value.campaign_id)
  sendingDraft.value = false
  showSendConfirm.value = false
  if (result.success) {
    toast.success(result.message || 'Campaign queued for sending!')
    await loadAnalytics()
  } else {
    toast.error(result.error || 'Failed to send campaign')
  }
}

watch(() => props.campaignId, () => {
  if (props.campaignId) loadAnalytics()
})

onMounted(() => loadAnalytics())
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <!-- Header -->
    <div class="flex items-center gap-3 px-6 py-4 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
      <button @click="emit('back')" class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
        <span class="material-symbols-rounded text-xl">arrow_back</span>
      </button>
      <div class="flex-1 min-w-0" v-if="campaign">
        <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 truncate">{{ campaign.subject }}</h2>
        <p class="text-sm text-surface-500">Created {{ formatDate(campaign.created_at) }}</p>
        <div v-if="campaign.source === 'crm_automation'" class="flex items-center gap-1.5 mt-0.5 text-xs">
          <span class="material-symbols-rounded text-sm text-purple-400">smart_toy</span>
          <span class="text-purple-600 dark:text-purple-400 font-medium">Triggered by automation</span>
          <template v-if="campaign.parent_campaign_id && campaign.parent_campaign_subject">
            <span class="text-surface-400">from</span>
            <span
              class="text-primary-500 hover:text-primary-600 cursor-pointer font-medium"
              @click="$emit('select-parent', campaign.parent_campaign_id)"
            >
              {{ campaign.parent_campaign_subject }}
            </span>
          </template>
        </div>
      </div>
      <span v-if="campaign" :class="['px-3 py-1 rounded-full text-xs font-semibold', getCampaignStatusColor(campaign.status)]">
        {{ campaign.status }}
      </span>
      <button @click="loadAnalytics" class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors" title="Refresh">
        <span class="material-symbols-rounded text-xl" :class="loading ? 'animate-spin' : ''">refresh</span>
      </button>
    </div>
    
    <!-- Loading -->
    <div v-if="loading && !analytics" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        <p class="mt-2 text-surface-500">Loading analytics...</p>
      </div>
    </div>
    
    <!-- Error State -->
    <div v-else-if="loadError" class="flex-1 flex items-center justify-center">
      <div class="text-center max-w-md">
        <span class="material-symbols-rounded text-5xl text-red-400">error</span>
        <h3 class="mt-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Failed to load analytics</h3>
        <p class="mt-2 text-sm text-surface-500">{{ loadError }}</p>
        <button @click="loadAnalytics" class="mt-4 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors inline-flex items-center gap-2">
          <span class="material-symbols-rounded text-lg">refresh</span>
          Try Again
        </button>
      </div>
    </div>
    
    <template v-else-if="analytics">
      <!-- Draft Header -->
      <div v-if="isDraft" class="px-6 py-4 bg-surface-50 dark:bg-surface-900 border-b border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-4 flex-wrap">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 text-sm text-surface-500">
              <span class="material-symbols-rounded text-base">contact_mail</span>
              <span v-if="campaign.mailing_list_name">Mailing List: <strong class="text-surface-700 dark:text-surface-300">{{ campaign.mailing_list_name }}</strong></span>
              <span v-else class="italic">No mailing list assigned</span>
            </div>
          </div>
          <button
            @click="showSendConfirm = true"
            :disabled="!campaign.body_html || !campaign.mailing_list_id"
            class="px-4 py-2 bg-green-500 hover:bg-green-600 disabled:bg-surface-300 disabled:cursor-not-allowed text-white rounded-full text-sm font-medium transition-colors flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">send</span>
            Send Campaign
          </button>
        </div>
      </div>
      
      <!-- Summary Cards -->
      <div v-if="!isDraft" class="px-6 py-4 bg-surface-50 dark:bg-surface-900 border-b border-surface-200 dark:border-surface-700">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
          <div class="bg-white dark:bg-surface-800 rounded-xl p-3 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-base text-blue-500">group</span>
              <span class="text-xs text-surface-500 uppercase tracking-wide">Recipients</span>
            </div>
            <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ summary.total_recipients }}</p>
          </div>
          
          <div class="bg-white dark:bg-surface-800 rounded-xl p-3 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-base text-green-500">check_circle</span>
              <span class="text-xs text-surface-500 uppercase tracking-wide">Delivered</span>
            </div>
            <p class="text-xl font-bold text-green-600 dark:text-green-400">{{ summary.sent }}</p>
          </div>
          
          <div class="bg-white dark:bg-surface-800 rounded-xl p-3 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-base text-primary-500">visibility</span>
              <span class="text-xs text-surface-500 uppercase tracking-wide">Opens</span>
            </div>
            <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ summary.unique_opens }}</p>
            <p class="text-xs text-surface-500">{{ summary.open_rate }}% rate</p>
          </div>
          
          <div class="bg-white dark:bg-surface-800 rounded-xl p-3 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-base text-blue-500">ads_click</span>
              <span class="text-xs text-surface-500 uppercase tracking-wide">Clicks</span>
            </div>
            <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ summary.unique_clickers }}</p>
            <p class="text-xs text-surface-500">{{ summary.click_rate }}% rate</p>
          </div>
          
          <div class="bg-white dark:bg-surface-800 rounded-xl p-3 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-base text-red-500">error</span>
              <span class="text-xs text-surface-500 uppercase tracking-wide">Bounced</span>
            </div>
            <p class="text-xl font-bold text-red-600 dark:text-red-400">{{ summary.failed }}</p>
            <p class="text-xs text-surface-500">{{ summary.bounce_rate }}% rate</p>
          </div>
          
          <div class="bg-white dark:bg-surface-800 rounded-xl p-3 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-base text-orange-500">unsubscribe</span>
              <span class="text-xs text-surface-500 uppercase tracking-wide">Unsubs</span>
            </div>
            <p class="text-xl font-bold text-orange-600 dark:text-orange-400">{{ summary.unsubscribes }}</p>
            <p class="text-xs text-surface-500">{{ summary.unsubscribe_rate }}% rate</p>
          </div>
          
          <div class="bg-white dark:bg-surface-800 rounded-xl p-3 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-base text-yellow-500">schedule</span>
              <span class="text-xs text-surface-500 uppercase tracking-wide">Pending</span>
            </div>
            <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ summary.pending }}</p>
          </div>
        </div>
      </div>
      
      <!-- Tabs -->
      <div class="flex items-center gap-1 px-6 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 overflow-x-auto">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'flex items-center gap-1.5 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === tab.id
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
          {{ tab.label }}
          <span v-if="tab.count != null" class="text-xs bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ tab.count }}</span>
        </button>
      </div>
      
      <!-- Tab Content -->
      <div class="flex-1 overflow-y-auto">
        
        <!-- Recipients Tab -->
        <div v-if="activeTab === 'overview'" class="p-6">
          <!-- Quick filters -->
          <div class="flex items-center gap-3 mb-4 flex-wrap">
            <div class="relative flex-1 min-w-[200px] max-w-sm">
              <span class="material-symbols-rounded text-lg text-surface-400 absolute left-3 top-1/2 -translate-y-1/2">search</span>
              <input
                v-model="recipientSearch"
                placeholder="Search recipients..."
                class="w-full pl-10 pr-4 py-2 bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500"
              />
            </div>
            <div class="flex items-center gap-2 text-xs">
              <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 rounded-full">
                <span class="material-symbols-rounded text-sm">visibility</span>
                {{ openedRecipients.length }} opened
              </span>
              <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 rounded-full">
                <span class="material-symbols-rounded text-sm">ads_click</span>
                {{ clickedRecipients.length }} clicked
              </span>
              <span class="inline-flex items-center gap-1 px-2 py-1 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 rounded-full">
                <span class="material-symbols-rounded text-sm">error</span>
                {{ failedRecipients.length }} failed
              </span>
            </div>
          </div>
          
          <!-- Recipients Table with expandable rows -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-surface-50 dark:bg-surface-700/50 text-left">
                    <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400 w-8"></th>
                    <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Recipient</th>
                    <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Status</th>
                    <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Opened</th>
                    <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Clicked</th>
                    <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Sent At</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="r in filteredRecipients" :key="r.id">
                    <!-- Main row -->
                    <tr
                      @click="toggleRecipient(r.id)"
                      class="border-t border-surface-100 dark:border-surface-700/50 hover:bg-surface-50 dark:hover:bg-surface-700/30 cursor-pointer"
                      :class="String(expandedRecipient) === String(r.id) ? 'bg-surface-50 dark:bg-surface-700/30' : ''"
                    >
                      <td class="px-4 py-3 w-8">
                        <span 
                          class="material-symbols-rounded text-sm text-surface-400 transition-transform duration-200"
                          :class="String(expandedRecipient) === String(r.id) ? 'rotate-90' : ''"
                        >chevron_right</span>
                      </td>
                      <td class="px-4 py-3">
                        <div class="font-medium text-surface-900 dark:text-surface-100">{{ r.recipient_email }}</div>
                        <div v-if="r.recipient_name" class="text-xs text-surface-500">{{ r.recipient_name }}</div>
                      </td>
                      <td class="px-4 py-3">
                        <span :class="['inline-flex items-center gap-1', getStatusColor(r.status)]">
                          <span class="material-symbols-rounded text-sm">{{ getStatusIcon(r.status) }}</span>
                          <span class="text-xs font-medium">{{ getStatusLabel(r.status) }}</span>
                        </span>
                        <div v-if="r.error_message" class="text-xs text-red-500 mt-0.5 max-w-[200px] truncate" :title="r.error_message">{{ r.error_message }}</div>
                      </td>
                      <td class="px-4 py-3">
                        <template v-if="r.opened">
                          <span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400">
                            <span class="material-symbols-rounded text-sm">visibility</span>
                            <span class="text-xs font-medium">{{ r.open_count }}x</span>
                          </span>
                          <div class="text-xs text-surface-500">{{ formatTime(r.first_open_at) }}</div>
                        </template>
                        <span v-else class="text-xs text-surface-400">-</span>
                      </td>
                      <td class="px-4 py-3">
                        <template v-if="r.clicked">
                          <span class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400">
                            <span class="material-symbols-rounded text-sm">ads_click</span>
                            <span class="text-xs font-medium">{{ r.click_count || 0 }}x</span>
                          </span>
                        </template>
                        <span v-else class="text-xs text-surface-400">-</span>
                      </td>
                      <td class="px-4 py-3 text-xs text-surface-500">
                        {{ formatDate(r.sent_at) }}
                      </td>
                    </tr>
                    
                    <!-- Expanded detail row -->
                    <tr v-if="String(expandedRecipient) === String(r.id)">
                      <td colspan="6" class="px-0 py-0 bg-surface-50/50 dark:bg-surface-900/50">
                        <div class="px-6 py-4 space-y-4">
                          
                          <!-- Open Events -->
                          <div v-if="r.open_events && r.open_events.length > 0">
                            <h4 class="text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                              <span class="material-symbols-rounded text-sm text-green-500">visibility</span>
                              Open Events ({{ r.open_events.length }})
                            </h4>
                            <div class="ml-5 space-y-1">
                              <div 
                                v-for="(ev, i) in groupOpenEvents(r.open_events)" 
                                :key="'open-' + i"
                                class="flex items-center gap-3 text-xs py-1.5 border-l-2 border-green-300 dark:border-green-700 pl-3"
                              >
                                <span class="text-surface-900 dark:text-surface-100 font-medium">{{ formatDate(ev.at) }}</span>
                                <span v-if="ev.count > 1" class="px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-semibold text-[10px]">{{ ev.count }}x</span>
                                <span v-if="ev.ip" class="text-surface-400 font-mono text-[11px]">{{ ev.ip }}</span>
                              </div>
                            </div>
                          </div>
                          
                          <!-- Click Events -->
                          <div v-if="r.clicked_links && r.clicked_links.length > 0">
                            <h4 class="text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                              <span class="material-symbols-rounded text-sm text-blue-500">ads_click</span>
                              Click Events ({{ r.clicked_links.length }})
                            </h4>
                            <div class="ml-5 space-y-1">
                              <div 
                                v-for="(ev, i) in groupClickEvents(r.clicked_links)" 
                                :key="'click-' + i"
                                class="flex items-center gap-3 text-xs py-1.5 border-l-2 border-blue-300 dark:border-blue-700 pl-3"
                              >
                                <span class="text-surface-900 dark:text-surface-100 font-medium">{{ formatDate(ev.clicked_at) }}</span>
                                <span v-if="ev.count > 1" class="px-1.5 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-semibold text-[10px]">{{ ev.count }}x</span>
                                <a :href="ev.url" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline truncate max-w-[300px]" :title="ev.url">
                                  {{ formatShortUrl(ev.url) }}
                                </a>
                                <span v-if="ev.ip" class="text-surface-400 font-mono text-[11px]">{{ ev.ip }}</span>
                              </div>
                            </div>
                          </div>
                          
                          <!-- Unsubscribed notice -->
                          <div v-if="r.unsubscribed" class="flex items-center gap-2 text-xs text-orange-600 dark:text-orange-400">
                            <span class="material-symbols-rounded text-sm">unsubscribe</span>
                            <span class="font-medium">Recipient has unsubscribed</span>
                          </div>
                          
                          <!-- Error details -->
                          <div v-if="r.error_message" class="flex items-start gap-2 text-xs text-red-600 dark:text-red-400">
                            <span class="material-symbols-rounded text-sm mt-0.5">error</span>
                            <div>
                              <span class="font-medium">Error:</span> {{ r.error_message }}
                              <div v-if="r.attempts" class="text-surface-500 mt-0.5">{{ r.attempts }} attempt(s)</div>
                            </div>
                          </div>
                          
                          <!-- No tracking data -->
                          <div v-if="(!r.open_events || r.open_events.length === 0) && (!r.clicked_links || r.clicked_links.length === 0) && !r.unsubscribed && !r.error_message" class="text-xs text-surface-400 italic">
                            No tracking events recorded for this recipient
                          </div>
                          
                        </div>
                      </td>
                    </tr>
                  </template>
                  <tr v-if="filteredRecipients.length === 0">
                    <td colspan="6" class="px-4 py-8 text-center text-surface-500">
                      {{ recipientSearch ? 'No recipients match your search' : 'No recipients found' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        
        <!-- Email Content Tab -->
        <div v-if="activeTab === 'content'" class="p-6">
          
          <!-- Draft: always show inline editor -->
          <div v-if="isDraft" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-700/50">
              <div class="flex items-center gap-2 text-sm font-medium text-surface-900 dark:text-surface-100">
                <span class="material-symbols-rounded text-lg text-primary-500">edit</span>
                Email Content
              </div>
              <div class="flex items-center gap-2">
                <div class="relative">
                  <button
                    @click="showMergeTagPicker = !showMergeTagPicker"
                    class="px-3 py-1.5 bg-surface-100 dark:bg-surface-600 hover:bg-surface-200 dark:hover:bg-surface-500 text-surface-700 dark:text-surface-300 rounded-full text-xs font-medium transition-colors flex items-center gap-1"
                  >
                    <span class="material-symbols-rounded text-sm">data_object</span>
                    Variables
                  </button>
                  <div
                    v-if="showMergeTagPicker"
                    class="absolute right-0 top-full mt-1 w-52 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 z-50"
                  >
                    <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase">Merge Tags</p>
                    <button
                      v-for="tag in mergeTagVariables"
                      :key="tag.key"
                      @click="insertMergeTag(tag.key)"
                      class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
                    >
                      <span class="material-symbols-rounded text-base text-surface-400">{{ tag.icon }}</span>
                      <span class="flex-1">{{ tag.label }}</span>
                      <code class="text-xs text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded">{{ tag.key }}</code>
                    </button>
                  </div>
                </div>
                <button
                  @click="saveDraftContent"
                  :disabled="savingDraft"
                  class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 disabled:bg-primary-300 text-white rounded-full text-xs font-medium transition-colors flex items-center gap-1"
                >
                  <span v-if="savingDraft" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
                  <span v-else class="material-symbols-rounded text-sm">save</span>
                  {{ savingDraft ? 'Saving...' : 'Save' }}
                </button>
              </div>
            </div>
            
            <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700">
              <div class="flex items-center gap-2">
                <label class="text-xs font-medium text-surface-500 w-16">Subject</label>
                <input
                  v-model="editSubject"
                  type="text"
                  class="flex-1 px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500"
                  placeholder="Email subject..."
                />
              </div>
            </div>
            
            <div class="p-4">
              <RichTextEditor ref="editorRef" :modelValue="editBody" @update:modelValue="onEditorUpdate" />
            </div>
          </div>
          
          <!-- Sent/Completed: read-only preview -->
          <div v-else-if="campaign?.body_html" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <div class="flex items-center gap-2 px-4 py-3 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-700/50">
              <span class="material-symbols-rounded text-lg text-surface-500">subject</span>
              <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ campaign.subject }}</span>
            </div>
            <iframe
              :srcdoc="campaign.body_html"
              sandbox="allow-same-origin"
              class="w-full border-0"
              style="min-height: 500px; height: 60vh;"
            ></iframe>
          </div>
          
          <!-- No content (non-draft) -->
          <div v-else class="text-center py-16">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">article</span>
            <p class="mt-3 text-surface-500">No email content available</p>
          </div>
        </div>
        
        <!-- Blocks Tab -->
        <div v-if="activeTab === 'blocks'" class="p-6">
          <div v-if="blockStats.length === 0" class="text-center py-16">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">widgets</span>
            <p class="mt-3 text-surface-500">No block tracking data available</p>
            <p class="mt-1 text-xs text-surface-400">Blocks inserted via the block picker in new campaigns will be tracked automatically</p>
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="block in blockStats"
              :key="block.block_id"
              class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden"
            >
              <div class="flex items-center gap-3 px-4 py-3">
                <div class="w-9 h-9 rounded-lg bg-primary-50 dark:bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-primary-500 text-lg">{{ getBlockIcon(block.block_type) }}</span>
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-semibold text-surface-900 dark:text-surface-100">{{ block.block_name || 'Unknown Block' }}</p>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-surface-100 dark:bg-surface-700 text-surface-500 uppercase tracking-wide">{{ block.block_type }}</span>
                  </div>
                  <p class="text-xs text-surface-500 mt-0.5">{{ block.unique_clickers }} unique clicker{{ block.unique_clickers !== 1 ? 's' : '' }} ({{ block.click_rate }}% rate)</p>
                </div>
                <div class="text-right">
                  <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ block.total_clicks }}</p>
                  <p class="text-[10px] text-surface-400 uppercase tracking-wide">clicks</p>
                </div>
              </div>
              
              <div v-if="block.links && block.links.length > 0" class="border-t border-surface-100 dark:border-surface-700/50 px-4 py-2">
                <div
                  v-for="(blink, i) in block.links"
                  :key="i"
                  class="flex items-center gap-2 py-1 text-xs"
                >
                  <span class="material-symbols-rounded text-sm text-blue-400">link</span>
                  <a :href="blink.url" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline truncate flex-1" :title="blink.url">{{ formatUrl(blink.url) }}</a>
                  <span class="text-surface-500 font-medium">{{ blink.total_clicks }} click{{ blink.total_clicks !== 1 ? 's' : '' }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Links Tab -->
        <div v-if="activeTab === 'links'" class="p-6">
          <div v-if="links.length === 0" class="text-center py-16">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">link_off</span>
            <p class="mt-3 text-surface-500">No tracked links in this campaign</p>
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="(link, idx) in links"
              :key="idx"
              class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden"
            >
              <!-- Link Header -->
              <button
                @click="expandedLink = expandedLink === idx ? null : idx"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors text-left"
              >
                <span class="material-symbols-rounded text-lg text-blue-500">link</span>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-medium text-primary-600 dark:text-primary-400 truncate" :title="link.url">{{ formatUrl(link.url) }}</p>
                    <span v-if="link.block_name" class="flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 whitespace-nowrap">
                      <span class="material-symbols-rounded text-xs">{{ getBlockIcon(link.block_type) }}</span>
                      {{ link.block_name }}
                    </span>
                  </div>
                  <p class="text-xs text-surface-500">{{ link.total_clicks }} total clicks from {{ link.unique_clickers }} unique visitor{{ link.unique_clickers !== 1 ? 's' : '' }}</p>
                </div>
                <div class="flex items-center gap-3">
                  <span class="text-lg font-bold text-surface-900 dark:text-surface-100">{{ link.total_clicks }}</span>
                  <span class="material-symbols-rounded text-surface-400 transition-transform" :class="expandedLink === idx ? 'rotate-180' : ''">expand_more</span>
                </div>
              </button>
              
              <!-- Link Click Events -->
              <div v-if="expandedLink === idx" class="border-t border-surface-200 dark:border-surface-700">
                <div class="max-h-64 overflow-y-auto">
                  <div
                    v-for="(ev, i) in groupLinkTabEvents(link.events)"
                    :key="i"
                    class="flex items-center gap-3 px-4 py-2 text-sm border-b border-surface-100 dark:border-surface-700/50 last:border-0"
                  >
                    <span class="material-symbols-rounded text-sm text-blue-400">ads_click</span>
                    <span class="font-medium text-surface-900 dark:text-surface-100 flex-1 truncate">{{ ev.email }}</span>
                    <span v-if="ev.count > 1" class="px-1.5 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-semibold text-[10px]">{{ ev.count }}x</span>
                    <span class="text-xs text-surface-400">{{ ev.ip }}</span>
                    <span class="text-xs text-surface-500 whitespace-nowrap">{{ formatDate(ev.at) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Unsubscribes Tab -->
        <div v-if="activeTab === 'unsubscribes'" class="p-6">
          <div v-if="unsubscribes.length === 0" class="text-center py-16">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">mood</span>
            <p class="mt-3 text-surface-500">No unsubscribes from this campaign</p>
          </div>
          <div v-else class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <table class="w-full text-sm">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-700/50 text-left">
                  <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Email</th>
                  <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Reason</th>
                  <th class="px-4 py-3 font-medium text-surface-600 dark:text-surface-400">Date</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="unsub in unsubscribes"
                  :key="unsub.unsubscribed_email"
                  class="border-t border-surface-100 dark:border-surface-700/50"
                >
                  <td class="px-4 py-3 font-medium text-surface-900 dark:text-surface-100">{{ unsub.unsubscribed_email }}</td>
                  <td class="px-4 py-3 text-surface-500">{{ unsub.reason || '-' }}</td>
                  <td class="px-4 py-3 text-surface-500 text-xs">{{ formatDate(unsub.unsubscribed_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        
        <!-- Mailing List Tab (Draft only) -->
        <div v-if="activeTab === 'mailing_list'" class="p-6">
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-6 max-w-lg">
            <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-primary-500">contact_mail</span>
              Assign Mailing List
            </h3>
            <select
              v-model="selectedMailingListId"
              @change="assignMailingList"
              class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500"
            >
              <option :value="null">-- Select a mailing list --</option>
              <option v-for="list in mailingLists" :key="list.id" :value="list.id">
                {{ list.name }} ({{ list.contact_count || 0 }} contacts)
              </option>
            </select>
            <p class="mt-3 text-xs text-surface-400">
              Recipients from the selected list will receive the email when you send the campaign.
            </p>
          </div>
        </div>
        
        <!-- Activity Log Tab -->
        <div v-if="activeTab === 'activity'" class="p-6">
          <div v-if="activityLog.length === 0" class="text-center py-16">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">history</span>
            <p class="mt-3 text-surface-500">No activity logged yet</p>
          </div>
          <div v-else class="space-y-0.5">
            <div
              v-for="(event, idx) in activityLog"
              :key="idx"
              class="flex items-start gap-3 px-4 py-3 bg-white dark:bg-surface-800 rounded-lg border border-surface-100 dark:border-surface-700/50"
            >
              <span :class="['material-symbols-rounded text-lg mt-0.5', getLogColor(event.event_type)]">{{ getLogIcon(event.event_type) }}</span>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-surface-900 dark:text-surface-100">
                  <span class="font-medium capitalize">{{ event.event_type }}</span>
                  <span v-if="event.recipient_email" class="text-surface-500"> - {{ event.recipient_email }}</span>
                </p>
                <p v-if="event.message" class="text-xs text-surface-500 mt-0.5">{{ event.message }}</p>
              </div>
              <span class="text-xs text-surface-400 whitespace-nowrap">{{ formatDate(event.created_at) }}</span>
            </div>
          </div>
        </div>
        
      </div>
    </template>
    
    <!-- Send Confirmation Modal -->
    <Teleport to="body">
      <div v-if="showSendConfirm" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="showSendConfirm = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
          <div class="text-center">
            <span class="material-symbols-rounded text-4xl text-green-500">send</span>
            <h3 class="mt-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Send Campaign?</h3>
            <p class="mt-2 text-sm text-surface-500">
              This will queue the campaign for sending to all contacts in the assigned mailing list. This action cannot be undone.
            </p>
          </div>
          <div class="mt-6 flex justify-end gap-2">
            <button @click="showSendConfirm = false" class="px-4 py-2 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 rounded-full text-sm font-medium transition-colors">
              Cancel
            </button>
            <button
              @click="handleSendDraft"
              :disabled="sendingDraft"
              class="px-4 py-2 bg-green-500 hover:bg-green-600 disabled:bg-green-300 text-white rounded-full text-sm font-medium transition-colors flex items-center gap-2"
            >
              <span v-if="sendingDraft" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
              <span class="material-symbols-rounded text-lg" v-else>send</span>
              {{ sendingDraft ? 'Sending...' : 'Send Now' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
