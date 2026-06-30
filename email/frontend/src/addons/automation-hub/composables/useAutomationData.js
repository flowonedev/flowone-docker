import { ref, shallowRef } from 'vue'
import api from '@/services/api'
import automationHubApi from '../services/automationHubApi'

const colleagues = ref([])
const groups = ref([])
const conversations = ref([])
const channels = ref([])
const boards = ref([])
const mailingLists = ref([])
const campaigns = ref([])
const clients = ref([])
const invoices = ref([])
const calendars = ref([])
const driveFolders = ref([])
const sequences = ref([])
const moodboards = ref([])
const connections = ref({})
const printers = ref([])
const driveAvailable = ref(false)
const boardListsCache = shallowRef({})

const loaded = {
  colleagues: false,
  groups: false,
  conversations: false,
  channels: false,
  boards: false,
  mailingLists: false,
  campaigns: false,
  clients: false,
  invoices: false,
  calendars: false,
  driveFolders: false,
  sequences: false,
  moodboards: false,
  connections: false,
  printers: false,
}

const PIPELINE_STAGES = [
  { value: 'lead', label: 'Lead' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'proposal', label: 'Proposal' },
  { value: 'negotiation', label: 'Negotiation' },
  { value: 'won', label: 'Won' },
  { value: 'lost', label: 'Lost' },
]

const INVOICE_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'sent', label: 'Sent' },
  { value: 'viewed', label: 'Viewed' },
  { value: 'partial', label: 'Partially Paid' },
  { value: 'paid', label: 'Paid' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'refunded', label: 'Refunded' },
]

const EXPENSE_CATEGORIES = [
  { value: 'software', label: 'Software' },
  { value: 'hosting', label: 'Hosting' },
  { value: 'marketing', label: 'Marketing' },
  { value: 'travel', label: 'Travel' },
  { value: 'office', label: 'Office' },
  { value: 'salary', label: 'Salary' },
  { value: 'other', label: 'Other' },
]

const STAT_PERIODS = [
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
  { value: '12m', label: 'Last 12 months' },
  { value: 'all', label: 'All time' },
]

async function fetchColleagues() {
  if (loaded.colleagues) return
  loaded.colleagues = true
  try {
    const res = await api.get('/colleagues')
    colleagues.value = res.data?.data?.colleagues || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] colleagues fetch failed:', e.message)
  }
}

async function fetchGroups() {
  if (loaded.groups) return
  loaded.groups = true
  try {
    const res = await api.get('/colleagues/groups')
    groups.value = res.data?.data?.groups || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] groups fetch failed:', e.message)
  }
}

async function fetchConversations() {
  if (loaded.conversations) return
  loaded.conversations = true
  try {
    const res = await api.get('/chat/conversations', { params: { limit: 100 } })
    conversations.value = res.data?.data?.conversations || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] conversations fetch failed:', e.message)
  }
}

async function fetchChannels() {
  if (loaded.channels) return
  loaded.channels = true
  try {
    const res = await api.get('/chat/channels')
    channels.value = res.data?.data?.channels || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] channels fetch failed:', e.message)
  }
}

async function fetchBoards() {
  if (loaded.boards) return
  loaded.boards = true
  try {
    const res = await api.get('/boards')
    boards.value = res.data?.data?.boards || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] boards fetch failed:', e.message)
  }
}

async function fetchBoardLists(boardId) {
  if (!boardId) return []
  const key = String(boardId)
  if (boardListsCache.value[key]) return boardListsCache.value[key]
  try {
    const res = await api.get(`/boards/${boardId}`)
    const lists = res.data?.data?.board?.lists || res.data?.data?.lists || []
    boardListsCache.value = { ...boardListsCache.value, [key]: lists }
    return lists
  } catch (e) {
    console.warn('[AutomationData] board lists fetch failed:', e.message)
    return []
  }
}

async function fetchMailingLists() {
  if (loaded.mailingLists) return
  loaded.mailingLists = true
  try {
    const res = await api.get('/mailing-lists')
    mailingLists.value = res.data?.data?.lists || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] mailing lists fetch failed:', e.message)
  }
}

async function fetchCampaigns() {
  if (loaded.campaigns) return
  loaded.campaigns = true
  try {
    const res = await api.get('/email-queue/campaigns', { params: { limit: 100 } })
    campaigns.value = res.data?.data?.campaigns || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] campaigns fetch failed:', e.message)
  }
}

async function fetchClients() {
  if (loaded.clients) return
  loaded.clients = true
  try {
    const res = await api.get('/clients')
    clients.value = res.data?.data?.clients || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] clients fetch failed:', e.message)
  }
}

async function fetchInvoices() {
  if (loaded.invoices) return
  loaded.invoices = true
  try {
    const res = await api.get('/crm/invoices', { params: { limit: 100 } })
    invoices.value = res.data?.data?.invoices || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] invoices fetch failed:', e.message)
  }
}

async function fetchCalendars() {
  if (loaded.calendars) return
  loaded.calendars = true
  try {
    const res = await api.get('/calendars')
    calendars.value = res.data?.data?.calendars || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] calendars fetch failed:', e.message)
  }
}

async function fetchDriveFolders() {
  if (loaded.driveFolders) return
  loaded.driveFolders = true
  try {
    const res = await api.get('/drive/folders/all')
    driveFolders.value = res.data?.data?.folders || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] drive folders fetch failed:', e.message)
  }
}

async function fetchSequences() {
  if (loaded.sequences) return
  loaded.sequences = true
  try {
    const res = await api.get('/crm/sequences')
    sequences.value = res.data?.data?.sequences || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] sequences fetch failed:', e.message)
  }
}

async function fetchMoodboards() {
  if (loaded.moodboards) return
  loaded.moodboards = true
  try {
    const res = await api.get('/mood-boards')
    moodboards.value = res.data?.data?.boards || res.data?.data || []
  } catch (e) {
    console.warn('[AutomationData] moodboards fetch failed:', e.message)
  }
}

async function fetchConnections() {
  if (loaded.connections) return
  loaded.connections = true
  try {
    const res = await automationHubApi.getConnections()
    connections.value = res.data?.data?.connections || res.data?.connections || {}
  } catch (e) {
    console.warn('[AutomationData] connections fetch failed:', e.message)
  }
}

function resetConnectionsCache() {
  loaded.connections = false
  connections.value = {}
}

async function fetchPrinters() {
  if (loaded.printers) return
  loaded.printers = true
  try {
    const [statusOk, printerRes] = await Promise.all([
      automationHubApi.checkDriveStatus(),
      automationHubApi.getLocalPrinters(),
    ])
    driveAvailable.value = statusOk
    if (printerRes) {
      printers.value = printerRes
    }
  } catch {
    driveAvailable.value = false
  }
}

function resetPrintersCache() {
  loaded.printers = false
  printers.value = []
  driveAvailable.value = false
}

export function useAutomationData() {
  return {
    colleagues,
    groups,
    conversations,
    channels,
    boards,
    mailingLists,
    campaigns,
    clients,
    invoices,
    boardListsCache,
    pipelineStages: PIPELINE_STAGES,
    invoiceStatuses: INVOICE_STATUSES,
    expenseCategories: EXPENSE_CATEGORIES,
    statPeriods: STAT_PERIODS,
    fetchColleagues,
    fetchGroups,
    fetchConversations,
    fetchChannels,
    fetchBoards,
    fetchBoardLists,
    fetchMailingLists,
    fetchCampaigns,
    fetchClients,
    fetchInvoices,
    calendars,
    driveFolders,
    sequences,
    moodboards,
    connections,
    fetchCalendars,
    fetchDriveFolders,
    fetchSequences,
    fetchMoodboards,
    fetchConnections,
    resetConnectionsCache,
    printers,
    driveAvailable,
    fetchPrinters,
    resetPrintersCache,
  }
}
