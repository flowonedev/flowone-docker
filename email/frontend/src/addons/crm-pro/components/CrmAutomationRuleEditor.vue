<script setup>
/**
 * CrmAutomationRuleEditor - Full-page form for creating/editing automation rules
 * Trigger type selector -> config -> Action type selector -> config
 * Uses RichTextEditor for email body with template variable support.
 */
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useAddons } from '@/composables/useAddons'
import RichTextEditor from '@/components/RichTextEditor.vue'

const { moodboardsEnabled, kanbanBoardsEnabled, chatEnabled } = useAddons()
const { t } = useI18n()

const props = defineProps({
  rule: { type: Object, default: null },
})

const emit = defineEmits(['saved', 'close'])
const toast = useToastStore()
const saving = ref(false)

const form = ref({
  name: '',
  description: '',
  is_active: 1,
  visibility: 'private',
  trigger_type: 'deal_stage_idle',
  trigger_config: {},
  action_type: 'create_reminder',
  action_config: {},
  shared_with: [],    // [{ email, permission }]
  shared_groups: [],  // [{ group_id, permission }]
})

// Email body HTML for the RichTextEditor (used when action_type === 'send_email')
const emailBodyHtml = ref('')

// Dropdown open/close state
const triggerDropdownOpen = ref(false)
const actionDropdownOpen = ref(false)

// Drive folders browsing
const driveFolders = ref([])
const driveFoldersLoading = ref(false)
const driveFolderSearch = ref('')
const expandedFolderIds = ref(new Set())

// Chat conversations browsing
const chatConversations = ref([])
const chatConversationsLoading = ref(false)
const chatSearch = ref('')

// Email campaigns for email tracking triggers
const emailCampaigns = ref([])
const emailCampaignsLoading = ref(false)

// Tracked links for link filter (email_link_clicked trigger)
const trackedLinks = ref([])
const trackedLinksLoading = ref(false)

// Boards & colleagues for time tracking trigger
const boardsStore = useBoardsStore()
const colleaguesStore = useColleaguesStore()
const boardSearchQuery = ref('')

// Deals browsing (for move_deal_stage action)
const deals = ref([])
const dealsLoading = ref(false)
const dealSearchQuery = ref('')

// Sharing UI state
const sharingSearch = ref('')
const sharingLoading = ref(false)

// Populate form if editing
watch(() => props.rule, async (r) => {
  if (r) {
    form.value = {
      name: r.name || '',
      description: r.description || '',
      is_active: r.is_active ?? 1,
      visibility: r.visibility || 'private',
      trigger_type: r.trigger_type || 'deal_stage_idle',
      trigger_config: { ...(r.trigger_config || {}) },
      action_type: r.action_type || 'create_reminder',
      action_config: { ...(r.action_config || {}) },
      shared_with: [],
      shared_groups: [],
    }
    emailBodyHtml.value = r.action_config?.body_html || r.action_config?.body || ''

    // Load sharing details if rule is shared
    if (r.id && r.visibility === 'shared') {
      await loadShareDetails(r.id)
    }
  }
}, { immediate: true })

async function loadShareDetails(ruleId) {
  sharingLoading.value = true
  try {
    const res = await api.get(`/crm/automation/rules/${ruleId}/shares`)
    if (res.data?.success) {
      const data = res.data.data
      form.value.shared_with = (data.shares || []).map(s => ({
        email: s.shared_with_email,
        permission: s.permission || 'viewer',
        name: s.colleague_name || s.shared_with_email,
        avatar_url: s.avatar_url,
      }))
      form.value.shared_groups = (data.group_shares || []).map(gs => ({
        group_id: gs.group_id,
        permission: gs.permission || 'viewer',
        name: gs.group_name || `Group #${gs.group_id}`,
        color: gs.color,
        icon: gs.icon,
      }))
    }
  } catch (e) {
    console.error('Failed to load sharing details:', e)
  } finally {
    sharingLoading.value = false
  }
}

const isEditing = computed(() => !!props.rule?.id)

// Template variables that can be inserted into email body/subject/messages
// These change dynamically based on the selected trigger type
const commonVars = [
  { key: '{rule_name}', label: 'Rule Name', icon: 'bolt' },
  { key: '{your_name}', label: 'Your Name', icon: 'badge' },
  { key: '{your_email}', label: 'Your Email', icon: 'alternate_email' },
  { key: '{today}', label: 'Today Date', icon: 'calendar_today' },
  { key: '{now}', label: 'Date & Time', icon: 'schedule' },
  { key: '{target_name}', label: 'Target Name', icon: 'label' },
]

const triggerVarMap = {
  deal_stage_idle:     [{ key: '{deal_title}', label: 'Deal Title', icon: 'handshake' }, { key: '{deal_value}', label: 'Deal Value', icon: 'payments' }, { key: '{deal_stage}', label: 'Deal Stage', icon: 'conversion_path' }, { key: '{deal_id}', label: 'Deal ID', icon: 'tag' }, { key: '{client_name}', label: 'Client Name', icon: 'person' }],
  deal_stage_changed:  [{ key: '{deal_title}', label: 'Deal Title', icon: 'handshake' }, { key: '{deal_value}', label: 'Deal Value', icon: 'payments' }, { key: '{deal_stage}', label: 'Deal Stage', icon: 'conversion_path' }, { key: '{deal_id}', label: 'Deal ID', icon: 'tag' }, { key: '{client_name}', label: 'Client Name', icon: 'person' }],
  deal_won:            [{ key: '{deal_title}', label: 'Deal Title', icon: 'handshake' }, { key: '{deal_value}', label: 'Deal Value', icon: 'payments' }, { key: '{deal_stage}', label: 'Deal Stage', icon: 'conversion_path' }, { key: '{client_name}', label: 'Client Name', icon: 'person' }],
  deal_lost:           [{ key: '{deal_title}', label: 'Deal Title', icon: 'handshake' }, { key: '{deal_value}', label: 'Deal Value', icon: 'payments' }, { key: '{deal_stage}', label: 'Deal Stage', icon: 'conversion_path' }, { key: '{client_name}', label: 'Client Name', icon: 'person' }],
  invoice_overdue:     [{ key: '{client_name}', label: 'Client Name', icon: 'person' }, { key: '{client_domain}', label: 'Client Domain', icon: 'language' }, { key: '{invoice_number}', label: 'Invoice #', icon: 'receipt' }, { key: '{invoice_amount}', label: 'Invoice Amount', icon: 'paid' }],
  no_contact_days:     [{ key: '{client_name}', label: 'Client Name', icon: 'person' }, { key: '{client_domain}', label: 'Client Domain', icon: 'language' }, { key: '{client_email}', label: 'Client Email', icon: 'mail' }],
  client_health_low:   [{ key: '{client_name}', label: 'Client Name', icon: 'person' }, { key: '{client_domain}', label: 'Client Domain', icon: 'language' }, { key: '{client_email}', label: 'Client Email', icon: 'mail' }],
  time_spent_reached:  [{ key: '{target_name}', label: 'Project/Client Name', icon: 'work' }, { key: '{total_hours}', label: 'Total Hours', icon: 'timer' }, { key: '{tracked_by}', label: 'Tracked By', icon: 'person' }, { key: '{period}', label: 'Period', icon: 'date_range' }, { key: '{threshold}', label: 'Threshold (hrs)', icon: 'flag' }, { key: '{board_name}', label: 'Board Name', icon: 'dashboard' }, { key: '{client_name}', label: 'Client Name', icon: 'person' }],
  task_changed:        [{ key: '{task_title}', label: 'Task Title', icon: 'task_alt' }, { key: '{task_status}', label: 'Task Status', icon: 'check_circle' }, { key: '{task_priority}', label: 'Task Priority', icon: 'priority_high' }, { key: '{task_due_date}', label: 'Due Date', icon: 'event' }, { key: '{task_assignee}', label: 'Assignee', icon: 'person' }, { key: '{task_id}', label: 'Task ID', icon: 'tag' }, { key: '{board_name}', label: 'Board Name', icon: 'dashboard' }],
  board_closed:        [{ key: '{board_name}', label: 'Board Name', icon: 'dashboard' }, { key: '{board_id}', label: 'Board ID', icon: 'tag' }, { key: '{board_owner}', label: 'Board Owner', icon: 'person' }, { key: '{closed_by}', label: 'Closed By', icon: 'person' }],
  moodboard_ready:     [{ key: '{moodboard_name}', label: 'Moodboard Name', icon: 'palette' }, { key: '{moodboard_id}', label: 'Moodboard ID', icon: 'tag' }, { key: '{moodboard_owner}', label: 'Owner', icon: 'person' }, { key: '{marked_ready_by}', label: 'Marked Ready By', icon: 'person' }],
  colleague_sick_status: [{ key: '{colleague_name}', label: 'Colleague Name', icon: 'person' }, { key: '{colleague_email}', label: 'Colleague Email', icon: 'mail' }, { key: '{colleague_status}', label: 'Status', icon: 'sick' }, { key: '{status_text}', label: 'Status Text', icon: 'chat_bubble' }],
  drive_folder_permission_changed: [{ key: '{folder_name}', label: 'Folder Name', icon: 'folder' }, { key: '{folder_id}', label: 'Folder ID', icon: 'tag' }, { key: '{changed_by}', label: 'Changed By', icon: 'person' }, { key: '{change_detail}', label: 'Change Detail', icon: 'info' }],
  email_opened: [{ key: '{recipient_email}', label: 'Recipient Email', icon: 'mail' }, { key: '{recipient_name}', label: 'Recipient Name', icon: 'person' }, { key: '{email_subject}', label: 'Email Subject', icon: 'subject' }, { key: '{campaign_name}', label: 'Campaign Name', icon: 'campaign' }],
  email_link_clicked: [{ key: '{recipient_email}', label: 'Recipient Email', icon: 'mail' }, { key: '{recipient_name}', label: 'Recipient Name', icon: 'person' }, { key: '{email_subject}', label: 'Email Subject', icon: 'subject' }, { key: '{link_url}', label: 'Clicked Link URL', icon: 'link' }, { key: '{campaign_name}', label: 'Campaign Name', icon: 'campaign' }],
  campaign_engagement_threshold: [{ key: '{recipient_email}', label: 'Recipient Email', icon: 'mail' }, { key: '{recipient_name}', label: 'Recipient Name', icon: 'person' }, { key: '{campaign_name}', label: 'Campaign Name', icon: 'campaign' }, { key: '{engagement_percent}', label: 'Engagement %', icon: 'percent' }, { key: '{links_clicked}', label: 'Links Clicked', icon: 'ads_click' }, { key: '{total_links}', label: 'Total Links', icon: 'link' }],
}

const templateVars = computed(() => {
  const triggerSpecific = triggerVarMap[form.value.trigger_type] || []
  // Merge common + trigger-specific, avoiding duplicates by key
  const seen = new Set()
  const result = []
  for (const v of [...triggerSpecific, ...commonVars]) {
    if (!seen.has(v.key)) {
      seen.add(v.key)
      result.push(v)
    }
  }
  return result
})

const allTriggerTypes = [
  { value: 'deal_stage_idle', label: 'Deal idle in stage', icon: 'hourglass_top', desc: 'Trigger when a deal stays in a stage for too long', group: 'Deals' },
  { value: 'deal_stage_changed', label: 'Deal stage changed', icon: 'swap_horiz', desc: 'Trigger when a deal enters a specific stage', group: 'Deals' },
  { value: 'deal_won', label: 'Deal won', icon: 'emoji_events', desc: 'Trigger when a deal is marked as won', group: 'Deals' },
  { value: 'deal_lost', label: 'Deal lost', icon: 'cancel', desc: 'Trigger when a deal is marked as lost', group: 'Deals' },
  { value: 'invoice_overdue', label: 'Invoice overdue', icon: 'schedule', desc: 'Trigger when an invoice is overdue by X days', group: 'Clients' },
  { value: 'no_contact_days', label: 'No contact for days', icon: 'person_off', desc: 'Trigger when no activity with client for X days', group: 'Clients' },
  { value: 'client_health_low', label: 'Client health low', icon: 'heart_broken', desc: 'Trigger when client health score drops below threshold', group: 'Clients' },
  { value: 'time_spent_reached', label: 'Tracked time threshold reached', icon: 'timer', desc: 'Trigger when tracked time on a project or client reaches XX hours', group: 'Clients' },
  { value: 'task_changed', label: 'Task changed', icon: 'task_alt', desc: 'Trigger when a task is created, updated, or completed', group: 'Tasks & Boards', requiresKanbanBoards: true },
  { value: 'board_closed', label: 'Board closed', icon: 'check_box', desc: 'Trigger when a board is marked as closed/completed', group: 'Tasks & Boards', requiresKanbanBoards: true },
  { value: 'moodboard_ready', label: 'Moodboard marked as ready', icon: 'palette', desc: 'Trigger when a moodboard is marked as ready', group: 'Tasks & Boards', requiresMoodboards: true },
  { value: 'colleague_sick_status', label: 'Colleague sick status', icon: 'sick', desc: 'Trigger when a team member sets their status to sick', group: 'Team' },
  { value: 'drive_folder_permission_changed', label: 'Drive folder permissions changed', icon: 'folder_shared', desc: 'Trigger when sharing/permissions of a drive folder change', group: 'Drive' },
  { value: 'email_opened', label: 'Email opened', icon: 'mark_email_read', desc: 'Trigger when a recipient opens your tracked email', group: 'Email Tracking' },
  { value: 'email_link_clicked', label: 'Email link clicked', icon: 'ads_click', desc: 'Trigger when a recipient clicks a link in your tracked email', group: 'Email Tracking' },
  { value: 'campaign_engagement_threshold', label: 'Campaign Engagement Threshold', icon: 'trending_up', desc: 'Fires when a recipient reaches a certain engagement level with a campaign', group: 'Email Tracking' },
]

// Filter out addon-gated triggers when their addon is disabled
const triggerTypes = computed(() => {
  return allTriggerTypes.filter(t => {
    if (t.requiresMoodboards && !moodboardsEnabled.value) return false
    if (t.requiresKanbanBoards && !kanbanBoardsEnabled.value) return false
    return true
  })
})

const actionTypes = computed(() => {
  const items = [
    { value: 'create_reminder', label: 'Create follow-up reminder', icon: 'alarm', desc: 'Schedule a reminder for yourself' },
    { value: 'send_email', label: 'Send email', icon: 'mail', desc: 'Send an automated email to the client' },
    { value: 'move_deal_stage', label: 'Move deal to stage', icon: 'arrow_forward', desc: 'Automatically advance the deal pipeline' },
    { value: 'notify_user', label: 'Create notification', icon: 'notifications', desc: 'Get notified about the event' },
    { value: 'start_sequence', label: 'Start email sequence', icon: 'route', desc: 'Enroll client into a drip campaign' },
    { value: 'create_invoice_draft', label: 'Create invoice draft', icon: 'receipt', desc: 'Auto-generate an invoice draft' },
    { value: 'assign_task', label: 'Assign a task', icon: 'add_task', desc: 'Create and assign a task to a colleague' },
  ]
  if (chatEnabled.value) {
    items.push({ value: 'send_chat_message', label: 'Send chat message', icon: 'chat', desc: 'Send a message in chat automatically' })
  }
  items.push({ value: 'reassign_deals', label: 'Reassign deals', icon: 'swap_calls', desc: 'Reassign deals from one user to another' })
  return items
})

// Group trigger types for the UI
const triggerGroups = computed(() => {
  const groups = {}
  triggerTypes.value.forEach(t => {
    const g = t.group || 'Other'
    if (!groups[g]) groups[g] = []
    groups[g].push(t)
  })
  return groups
})

// Selected trigger/action objects
const selectedTrigger = computed(() => triggerTypes.value.find(t => t.value === form.value.trigger_type) || triggerTypes.value[0])
const selectedAction = computed(() => actionTypes.value.find(a => a.value === form.value.action_type) || actionTypes.value[0])

const stageOptions = [
  { value: 'lead', label: 'Lead' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'proposal', label: 'Proposal' },
  { value: 'negotiation', label: 'Negotiation' },
  { value: 'won', label: 'Won' },
  { value: 'lost', label: 'Lost' },
]

// Reset config when trigger/action type changes
watch(() => form.value.trigger_type, (newVal, oldVal) => {
  if (!props.rule || newVal !== oldVal) form.value.trigger_config = getDefaultTriggerConfig()
  // Load drive folders when drive trigger is selected
  if (newVal === 'drive_folder_permission_changed' && driveFolders.value.length === 0) {
    fetchDriveFolders()
  }
  // Load campaigns when email tracking trigger is selected
  if (['email_opened', 'email_link_clicked', 'campaign_engagement_threshold'].includes(newVal) && emailCampaigns.value.length === 0) {
    fetchEmailCampaigns()
  }
  // Load tracked links when email_link_clicked trigger is selected
  if (newVal === 'email_link_clicked') {
    fetchTrackedLinks()
  }
  // Load boards & colleagues when time spent trigger is selected
  if (newVal === 'time_spent_reached') {
    loadBoardsAndColleagues()
  }
})
// Refetch tracked links when campaign selection changes (for link filter)
watch(() => form.value.trigger_config.campaign_id, (newVal) => {
  if (form.value.trigger_type === 'email_link_clicked') {
    fetchTrackedLinks(newVal || '')
  }
})

// Clear link_value when switching link_match mode
watch(() => form.value.trigger_config.link_match, () => {
  if (form.value.trigger_type === 'email_link_clicked') {
    form.value.trigger_config.link_value = ''
  }
})

watch(() => form.value.action_type, (newVal) => {
  if (!props.rule) {
    form.value.action_config = getDefaultActionConfig()
    emailBodyHtml.value = ''
  }
  // Load chat conversations when send_chat_message is selected
  if (newVal === 'send_chat_message' && chatConversations.value.length === 0) {
    fetchChatConversations()
  }
  // Load deals when move_deal_stage is selected
  if (newVal === 'move_deal_stage' && deals.value.length === 0) {
    fetchDeals()
  }
})

function getDefaultTriggerConfig() {
  switch (form.value.trigger_type) {
    case 'deal_stage_idle': return { stage: 'lead', days: 7 }
    case 'deal_stage_changed': return { stage: '' }
    case 'invoice_overdue': return { days: 7 }
    case 'no_contact_days': return { days: 14 }
    case 'client_health_low': return { threshold: 30 }
    case 'task_changed': return { change_type: '', status: '' }
    case 'board_closed': return {}
    case 'moodboard_ready': return {}
    case 'time_spent_reached': return { hours: 10, period: 'week', scope: 'board', board_id: '', colleague_email: '' }
    case 'colleague_sick_status': return { keyword: 'sick' }
    case 'drive_folder_permission_changed': return { change_type: '', folder_id: '' }
    case 'email_opened': return { scope: 'all', campaign_id: '' }
    case 'email_link_clicked': return { scope: 'all', campaign_id: '', link_match: 'any', link_value: '' }
    case 'campaign_engagement_threshold': return { campaign_id: '', metric: 'link_click_rate', threshold: 50 }
    default: return {}
  }
}

function getDefaultActionConfig() {
  switch (form.value.action_type) {
    case 'create_reminder': return { title: '', delay_hours: 0 }
    case 'send_email': return { template_id: '', subject: '', body_html: '' }
    case 'move_deal_stage': return { to_stage: 'contacted', deal_id: '', deal_name: '' }
    case 'notify_user': return { message: '' }
    case 'start_sequence': return { sequence_id: '' }
    case 'assign_task': return { title: '', description: '', assign_to_email: '', due_days: 1, priority: 'normal' }
    case 'send_chat_message': return { message: '', conversation_id: '' }
    case 'reassign_deals': return { new_owner_email: '' }
    default: return {}
  }
}

// --- Email campaigns ---
async function fetchEmailCampaigns() {
  emailCampaignsLoading.value = true
  try {
    const res = await api.get('/email-queue/campaigns', { params: { limit: 200 } })
    if (res.data?.success) {
      emailCampaigns.value = res.data.data?.campaigns || res.data.data || []
    }
  } catch (e) {
    console.error('Failed to load email campaigns:', e)
  } finally {
    emailCampaignsLoading.value = false
  }
}

// --- Tracked links (for link filter) ---
async function fetchTrackedLinks(campaignId = '') {
  trackedLinksLoading.value = true
  try {
    const params = {}
    if (campaignId) params.campaign_id = campaignId
    const res = await api.get('/tracking/links', { params })
    if (res.data?.success) {
      trackedLinks.value = res.data.data?.links || res.data.data || []
    }
  } catch (e) {
    console.error('Failed to load tracked links:', e)
  } finally {
    trackedLinksLoading.value = false
  }
}

// --- Drive folders ---
async function fetchDriveFolders() {
  driveFoldersLoading.value = true
  try {
    const res = await api.get('/drive/folders/all')
    if (res.data?.success) {
      driveFolders.value = res.data.data?.folders || res.data.data || []
    }
  } catch (e) {
    console.error('Failed to load drive folders:', e)
  } finally {
    driveFoldersLoading.value = false
  }
}

// Build folder tree from flat list
const folderTree = computed(() => {
  const folders = driveFolders.value
  if (!folders.length) return []

  const map = {}
  const roots = []

  // First pass: create map
  folders.forEach(f => {
    map[f.id] = { ...f, children: [] }
  })

  // Second pass: build tree
  folders.forEach(f => {
    if (f.parent_id && map[f.parent_id]) {
      map[f.parent_id].children.push(map[f.id])
    } else {
      roots.push(map[f.id])
    }
  })

  return roots
})

// Filter folders by search
const filteredFolderTree = computed(() => {
  const q = driveFolderSearch.value.trim().toLowerCase()
  if (!q) return folderTree.value

  function matchesSearch(folder) {
    if (folder.name?.toLowerCase().includes(q)) return true
    return folder.children?.some(matchesSearch)
  }

  function filterTree(folders) {
    return folders.filter(matchesSearch).map(f => ({
      ...f,
      children: filterTree(f.children || [])
    }))
  }

  return filterTree(folderTree.value)
})

function toggleFolderExpand(folderId) {
  if (expandedFolderIds.value.has(folderId)) {
    expandedFolderIds.value.delete(folderId)
  } else {
    expandedFolderIds.value.add(folderId)
  }
}

function selectDriveFolder(folder) {
  form.value.trigger_config.folder_id = folder.id
  form.value.trigger_config.folder_name = folder.name
}

function clearDriveFolderSelection() {
  form.value.trigger_config.folder_id = ''
  form.value.trigger_config.folder_name = ''
}

const selectedFolderName = computed(() => {
  if (!form.value.trigger_config.folder_id) return null
  // Check from config first (saved name), then look up from loaded folders
  if (form.value.trigger_config.folder_name) return form.value.trigger_config.folder_name
  const flat = driveFolders.value
  const found = flat.find(f => f.id === form.value.trigger_config.folder_id)
  return found?.name || `Folder #${form.value.trigger_config.folder_id}`
})

// --- Chat conversations ---
async function fetchChatConversations() {
  chatConversationsLoading.value = true
  try {
    const res = await api.get('/chat/conversations', { params: { limit: 200 } })
    if (res.data?.success) {
      chatConversations.value = res.data.data?.conversations || res.data.data || []
    }
  } catch (e) {
    console.error('Failed to load chat conversations:', e)
  } finally {
    chatConversationsLoading.value = false
  }
}

const filteredChatConversations = computed(() => {
  const q = chatSearch.value.trim().toLowerCase()
  const list = chatConversations.value
  if (!q) return list
  return list.filter(c => {
    const name = getChatName(c).toLowerCase()
    return name.includes(q)
  })
})

function getChatName(conv) {
  if (conv.name) return conv.name
  // For DMs, show the other participant's name
  if (conv.participants?.length) {
    return conv.participants.map(p => p.display_name || p.email).join(', ')
  }
  return `Chat #${conv.id}`
}

function getChatIcon(conv) {
  if (conv.type === 'channel' || conv.is_public) return 'tag'
  if (conv.type === 'group') return 'group'
  return 'chat_bubble'
}

function selectChatConversation(conv) {
  form.value.action_config.conversation_id = conv.id
  form.value.action_config.conversation_name = getChatName(conv)
}

function clearChatSelection() {
  form.value.action_config.conversation_id = ''
  form.value.action_config.conversation_name = ''
}

const selectedChatName = computed(() => {
  if (!form.value.action_config.conversation_id) return null
  if (form.value.action_config.conversation_name) return form.value.action_config.conversation_name
  const found = chatConversations.value.find(c => c.id === form.value.action_config.conversation_id)
  return found ? getChatName(found) : `Chat #${form.value.action_config.conversation_id}`
})

// Trigger / Action dropdown selection
function selectTrigger(t) {
  form.value.trigger_type = t.value
  triggerDropdownOpen.value = false
}

function selectAction(a) {
  form.value.action_type = a.value
  actionDropdownOpen.value = false
}

// Close dropdowns when clicking outside
function onClickOutside(e) {
  if (!e.target.closest('.trigger-dropdown-wrapper')) triggerDropdownOpen.value = false
  if (!e.target.closest('.action-dropdown-wrapper')) actionDropdownOpen.value = false
}

// Sharing helpers
const filteredColleaguesForSharing = computed(() => {
  const colleagues = colleaguesStore.colleagues || []
  const q = sharingSearch.value.toLowerCase().trim()
  const alreadyShared = new Set((form.value.shared_with || []).map(s => s.email?.toLowerCase()))
  return colleagues.filter(c => {
    if (alreadyShared.has(c.email?.toLowerCase())) return false
    if (!q) return true
    return c.name?.toLowerCase().includes(q) || c.email?.toLowerCase().includes(q)
  })
})

const availableGroups = computed(() => {
  const groups = colleaguesStore.groups || []
  const alreadyShared = new Set((form.value.shared_groups || []).map(g => g.group_id))
  return groups.filter(g => !alreadyShared.has(g.id))
})

function addColleagueShare(colleague) {
  if (!form.value.shared_with) form.value.shared_with = []
  form.value.shared_with.push({
    email: colleague.email,
    permission: 'viewer',
    name: colleague.name || colleague.email,
    avatar_url: colleague.avatar_url,
  })
  sharingSearch.value = ''
}

function removeColleagueShare(email) {
  form.value.shared_with = (form.value.shared_with || []).filter(s => s.email !== email)
}

function addGroupShare(group) {
  if (!form.value.shared_groups) form.value.shared_groups = []
  form.value.shared_groups.push({
    group_id: group.id,
    permission: 'viewer',
    name: group.name,
    color: group.color,
    icon: group.icon,
  })
}

function removeGroupShare(groupId) {
  form.value.shared_groups = (form.value.shared_groups || []).filter(g => g.group_id !== groupId)
}

onMounted(() => {
  document.addEventListener('click', onClickOutside)
  // Pre-load drive folders if currently editing a drive trigger
  if (form.value.trigger_type === 'drive_folder_permission_changed') {
    fetchDriveFolders()
  }
  // Pre-load chat conversations if currently editing a chat message action
  if (form.value.action_type === 'send_chat_message') {
    fetchChatConversations()
  }
  // Pre-load boards and colleagues if editing a time spent trigger
  if (form.value.trigger_type === 'time_spent_reached') {
    loadBoardsAndColleagues()
  }
  // Pre-load deals if editing a move_deal_stage action
  if (form.value.action_type === 'move_deal_stage') {
    fetchDeals()
  }
  // Pre-load campaigns if editing an email tracking trigger
  if (['email_opened', 'email_link_clicked', 'campaign_engagement_threshold'].includes(form.value.trigger_type)) {
    fetchEmailCampaigns()
  }
  // Pre-load tracked links if editing an email_link_clicked trigger
  if (form.value.trigger_type === 'email_link_clicked') {
    fetchTrackedLinks(form.value.trigger_config.campaign_id || '')
  }
  // Always pre-load colleagues and groups for sharing UI
  if (!colleaguesStore.colleagues?.length) {
    colleaguesStore.fetchColleagues()
  }
  if (!colleaguesStore.groups?.length) {
    colleaguesStore.fetchGroups()
  }
})

async function loadBoardsAndColleagues() {
  if (!boardsStore.boards?.length) {
    await boardsStore.fetchBoards()
  }
  if (!colleaguesStore.colleagues?.length) {
    await colleaguesStore.fetchColleagues()
  }
}

const filteredBoards = computed(() => {
  const boards = boardsStore.boards || []
  const q = boardSearchQuery.value.toLowerCase().trim()
  if (!q) return boards.filter(b => !b.archived)
  return boards.filter(b => !b.archived && b.name?.toLowerCase().includes(q))
})

const selectedBoardName = computed(() => {
  if (!form.value.trigger_config.board_id) return null
  const b = (boardsStore.boards || []).find(b => b.id == form.value.trigger_config.board_id)
  return b?.name || `Board #${form.value.trigger_config.board_id}`
})

// Deals browsing
async function fetchDeals() {
  dealsLoading.value = true
  try {
    const res = await api.get('/crm/deals')
    deals.value = res.data.data?.deals || res.data.data || []
  } catch (e) {
    console.error('Failed to load deals:', e)
  } finally {
    dealsLoading.value = false
  }
}

const filteredDeals = computed(() => {
  const list = deals.value
  const q = dealSearchQuery.value.toLowerCase().trim()
  if (!q) return list
  return list.filter(d =>
    d.title?.toLowerCase().includes(q) ||
    d.client_name?.toLowerCase().includes(q) ||
    d.pipeline_stage?.toLowerCase().includes(q)
  )
})

const selectedDealName = computed(() => {
  if (!form.value.action_config.deal_id) return null
  const d = deals.value.find(d => d.id == form.value.action_config.deal_id)
  return d ? `${d.title} (${d.client_name || 'No client'})` : form.value.action_config.deal_name || null
})

function selectDeal(deal) {
  form.value.action_config.deal_id = deal.id
  form.value.action_config.deal_name = deal.title
}

function clearDeal() {
  form.value.action_config.deal_id = ''
  form.value.action_config.deal_name = ''
}

// Pipeline stage color helper
const stageColors = {
  lead: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400',
  contacted: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-400',
  proposal: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-400',
  negotiation: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400',
  won: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400',
  lost: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400',
}

// Insert a template variable into any text config field
function insertVarToField(fieldName, varKey) {
  if (!form.value.action_config[fieldName]) form.value.action_config[fieldName] = ''
  form.value.action_config[fieldName] += varKey
}

// Insert a template variable into the subject field
const subjectInput = ref(null)
function insertVarToSubject(varKey) {
  insertVarToField('subject', varKey)
  nextTick(() => subjectInput.value?.focus())
}

// Insert a template variable into the rich text editor
function insertVarToBody(varKey) {
  const tag = `<span class="template-var" style="background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:4px;font-size:0.85em;font-weight:500;">${varKey}</span>&nbsp;`
  emailBodyHtml.value = (emailBodyHtml.value || '') + tag
}

async function save() {
  if (!form.value.name.trim()) {
    toast.error('Rule name is required')
    return
  }

  // If action is send_email, capture the rich text editor content
  if (form.value.action_type === 'send_email') {
    form.value.action_config.body_html = emailBodyHtml.value
    form.value.action_config.body = stripHtml(emailBodyHtml.value)
  }

  saving.value = true
  try {
    if (isEditing.value) {
      await api.put(`/crm/automation/rules/${props.rule.id}`, form.value)
    } else {
      await api.post('/crm/automation/rules', form.value)
    }
    emit('saved')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save rule')
  } finally {
    saving.value = false
  }
}

function stripHtml(html) {
  const tmp = document.createElement('div')
  tmp.innerHTML = html || ''
  return tmp.textContent || tmp.innerText || ''
}

const needsTriggerStage = computed(() => ['deal_stage_idle', 'deal_stage_changed'].includes(form.value.trigger_type))
const needsTriggerDays = computed(() => ['deal_stage_idle', 'invoice_overdue', 'no_contact_days'].includes(form.value.trigger_type))
const needsTriggerThreshold = computed(() => form.value.trigger_type === 'client_health_low')
const needsTriggerTaskConfig = computed(() => form.value.trigger_type === 'task_changed')
const needsTriggerTimeSpent = computed(() => form.value.trigger_type === 'time_spent_reached')
const needsTriggerSickKeyword = computed(() => form.value.trigger_type === 'colleague_sick_status')
const needsTriggerDriveFolder = computed(() => form.value.trigger_type === 'drive_folder_permission_changed')
const needsTriggerEmailScope = computed(() => ['email_opened', 'email_link_clicked'].includes(form.value.trigger_type))
const needsTriggerLinkFilter = computed(() => form.value.trigger_type === 'email_link_clicked')
const needsTriggerEngagement = computed(() => form.value.trigger_type === 'campaign_engagement_threshold')
const hasTriggerConfig = computed(() => needsTriggerStage.value || needsTriggerDays.value || needsTriggerThreshold.value || needsTriggerTaskConfig.value || needsTriggerTimeSpent.value || needsTriggerSickKeyword.value || needsTriggerDriveFolder.value || needsTriggerEmailScope.value || needsTriggerEngagement.value)

const inputClass = 'w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-[rgb(var(--color-bg))] text-sm text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 outline-none transition-colors'
const selectClass = inputClass
const labelClass = 'block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1.5'
const smallLabelClass = 'block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1'
</script>

<template>
  <div class="flex-1 overflow-auto">
    <!-- Top bar -->
    <div class="sticky top-0 z-10 flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))]">
      <div class="flex items-center gap-3">
        <button @click="emit('close')" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors">
          <span class="material-symbols-rounded text-xl text-surface-500">arrow_back</span>
        </button>
        <div>
          <h2 class="text-lg font-bold text-surface-900 dark:text-white">
            {{ isEditing ? t('crmAutomationRuleEditor.editAutomationRule') : t('crmAutomationRuleEditor.newAutomationRule') }}
          </h2>
          <p class="text-xs text-surface-400">{{ t('crmAutomationRuleEditor.defineATriggerConditionAnd') }}</p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <button @click="emit('close')" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors">{{ t('crmAutomationRuleEditor.cancel') }}</button>
        <button @click="save" :disabled="saving"
                class="px-6 py-2.5 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50 flex items-center gap-2 transition-colors">
          <span v-if="saving" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
          {{ saving ? t('crmAutomationRuleEditor.savingEllipsis') : (isEditing ? t('crmAutomationRuleEditor.updateRule') : t('crmAutomationRuleEditor.createRule')) }}
        </button>
      </div>
    </div>

    <!-- Content — full width -->
    <div class="p-6 space-y-6">

      <!-- Name & Description — full width row -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label :class="labelClass">{{ t('crmAutomationRuleEditor.ruleName') }}</label>
          <input v-model="form.name" :placeholder="t('crmAutomationRuleEditor.egFollowUpStaleProposals')" :class="inputClass" />
        </div>
        <div>
          <label :class="labelClass">{{ t('crmAutomationRuleEditor.description') }}</label>
          <input v-model="form.description" :placeholder="t('crmAutomationRuleEditor.whatDoesThisRuleDo')" :class="inputClass" />
        </div>
      </div>

      <!-- Visibility & Sharing -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl border border-surface-200 dark:border-[rgb(var(--color-border))] overflow-hidden">
        <div class="px-5 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex items-center justify-between">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white flex items-center gap-2">
            <span class="material-symbols-rounded text-base" :class="form.visibility === 'shared' ? 'text-green-500' : 'text-surface-400'">{{ form.visibility === 'shared' ? 'group' : 'lock' }}</span>
            {{ form.visibility === 'shared' ? t('crmAutomationRuleEditor.sharedRule') : t('crmAutomationRuleEditor.privateRule') }}
          </h3>
          <button
            @click="form.visibility = form.visibility === 'shared' ? 'private' : 'shared'"
            :class="['relative w-11 h-6 rounded-full transition-colors flex-shrink-0', form.visibility === 'shared' ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600']"
          >
            <span :class="['absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform', form.visibility === 'shared' ? 'left-[22px]' : 'left-0.5']"></span>
          </button>
        </div>

        <!-- Sharing panel (expanded when shared) -->
        <transition name="slide">
          <div v-if="form.visibility === 'shared'" class="p-5 space-y-4">
            <p class="text-xs text-surface-400">{{ t('crmAutomationRuleEditor.shareThisAutomationWithColleagues') }}</p>

            <!-- Group shares -->
            <div>
              <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1.5">{{ t('crmAutomationRuleEditor.shareWithGroups') }}</label>
              <div v-if="form.shared_groups?.length" class="flex flex-wrap gap-2 mb-2">
                <div v-for="gs in form.shared_groups" :key="gs.group_id"
                  class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-800">
                  <span class="material-symbols-rounded text-sm" :style="`color:${gs.color || '#6366f1'}`">{{ gs.icon || 'group' }}</span>
                  <span class="text-xs font-medium text-surface-700 dark:text-surface-200">{{ gs.name }}</span>
                  <select v-model="gs.permission" class="text-[10px] bg-transparent border-0 outline-none text-surface-500 cursor-pointer">
                    <option value="viewer">{{ t('crmAutomationRuleEditor.viewer') }}</option>
                    <option value="editor">{{ t('crmAutomationRuleEditor.editor') }}</option>
                  </select>
                  <button @click="removeGroupShare(gs.group_id)" class="text-surface-400 hover:text-red-500 transition-colors">
                    <span class="material-symbols-rounded text-sm">close</span>
                  </button>
                </div>
              </div>
              <div v-if="availableGroups.length" class="flex flex-wrap gap-1">
                <button v-for="group in availableGroups" :key="group.id"
                  @click="addGroupShare(group)"
                  class="flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors border border-dashed border-surface-300 dark:border-surface-600 text-surface-500">
                  <span class="material-symbols-rounded text-sm" :style="`color:${group.color || '#6366f1'}`">{{ group.icon || 'group' }}</span>
                  {{ group.name }}
                </button>
              </div>
              <p v-else-if="!form.shared_groups?.length" class="text-[11px] text-surface-400 italic">{{ t('crmAutomationRuleEditor.noGroupsAvailable') }}</p>
            </div>

            <!-- Individual shares -->
            <div>
              <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1.5">{{ t('crmAutomationRuleEditor.shareWithColleagues') }}</label>
              <div v-if="form.shared_with?.length" class="space-y-1.5 mb-2">
                <div v-for="share in form.shared_with" :key="share.email"
                  class="flex items-center gap-2.5 px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-800">
                  <div class="w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
                    <span class="text-[10px] font-bold text-primary-600 dark:text-primary-400">{{ (share.name || share.email).charAt(0).toUpperCase() }}</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">{{ share.name || share.email }}</p>
                    <p v-if="share.name" class="text-[10px] text-surface-400 truncate">{{ share.email }}</p>
                  </div>
                  <select v-model="share.permission" class="text-[10px] bg-transparent border-0 outline-none text-surface-500 cursor-pointer">
                    <option value="viewer">{{ t('crmAutomationRuleEditor.viewer') }}</option>
                    <option value="editor">{{ t('crmAutomationRuleEditor.editor') }}</option>
                  </select>
                  <button @click="removeColleagueShare(share.email)" class="text-surface-400 hover:text-red-500 transition-colors">
                    <span class="material-symbols-rounded text-sm">close</span>
                  </button>
                </div>
              </div>
              <!-- Search & add -->
              <div class="relative">
                <div class="flex items-center gap-2 px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-[rgb(var(--color-bg))]">
                  <span class="material-symbols-rounded text-sm text-surface-400">person_add</span>
                  <input v-model="sharingSearch" :placeholder="t('crmAutomationRuleEditor.searchColleaguesToAdd')" class="w-full text-xs bg-transparent outline-none text-surface-800 dark:text-surface-100 placeholder-surface-400" />
                </div>
                <div v-if="sharingSearch && filteredColleaguesForSharing.length" class="absolute left-0 right-0 top-full mt-1 z-20 bg-white dark:bg-[rgb(var(--color-surface))] border border-surface-200 dark:border-surface-600 rounded-xl shadow-lg max-h-40 overflow-y-auto">
                  <button v-for="c in filteredColleaguesForSharing.slice(0, 8)" :key="c.id"
                    @click="addColleagueShare(c)"
                    class="w-full flex items-center gap-2.5 px-3 py-2 text-left hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors">
                    <div class="w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="text-[10px] font-bold text-primary-600 dark:text-primary-400">{{ (c.name || c.email).charAt(0).toUpperCase() }}</span>
                    </div>
                    <div class="min-w-0">
                      <p class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">{{ c.name || c.email }}</p>
                      <p v-if="c.name" class="text-[10px] text-surface-400 truncate">{{ c.email }}</p>
                    </div>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </transition>
      </div>

      <!-- Row 1: Trigger (full width) -->
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl border border-surface-200 dark:border-[rgb(var(--color-border))] overflow-visible">
          <div class="px-5 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
            <h3 class="text-sm font-semibold text-surface-900 dark:text-white flex items-center gap-2">
              <span class="material-symbols-rounded text-blue-500">bolt</span>
              {{ t('crmAutomationRuleEditor.whenThisHappens') }}
            </h3>
          </div>

          <!-- Trigger selector dropdown -->
          <div class="p-5 space-y-4">
            <div class="trigger-dropdown-wrapper relative">
              <!-- Selected trigger button -->
              <button
                @click.stop="triggerDropdownOpen = !triggerDropdownOpen"
                class="w-full flex items-center gap-3 p-3.5 rounded-xl border transition-all text-left"
                :class="triggerDropdownOpen
                  ? 'border-blue-500 ring-2 ring-blue-500/20 bg-blue-50/50 dark:bg-blue-500/5'
                  : 'border-surface-300 dark:border-surface-600 hover:border-surface-400 dark:hover:border-surface-500 bg-white dark:bg-[rgb(var(--color-bg))]'"
              >
                <span class="w-9 h-9 rounded-lg bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">{{ selectedTrigger.icon }}</span>
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-surface-900 dark:text-white">{{ selectedTrigger.label }}</p>
                  <p class="text-[11px] text-surface-400 leading-tight truncate">{{ selectedTrigger.desc }}</p>
                </div>
                <span class="material-symbols-rounded text-surface-400 text-xl transition-transform" :class="triggerDropdownOpen ? 'rotate-180' : ''">expand_more</span>
              </button>

              <!-- Dropdown panel -->
              <div
                v-if="triggerDropdownOpen"
                class="absolute z-50 top-full left-0 right-0 mt-1 bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] shadow-xl max-h-[380px] overflow-y-auto"
              >
                <div v-for="(items, group) in triggerGroups" :key="group" class="px-2 pt-2 first:pt-3">
                  <p class="text-[10px] uppercase tracking-wider font-semibold text-surface-400 mb-1 px-2">{{ group }}</p>
                  <button
                    v-for="t in items" :key="t.value"
                    @click="selectTrigger(t)"
                    :class="[
                      'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors mb-0.5',
                      form.trigger_type === t.value
                        ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300'
                        : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200'
                    ]"
                  >
                    <span class="material-symbols-rounded text-lg" :class="form.trigger_type === t.value ? 'text-blue-500' : 'text-surface-400'">{{ t.icon }}</span>
                    <div class="min-w-0 flex-1">
                      <p class="text-sm font-medium">{{ t.label }}</p>
                      <p class="text-[11px] text-surface-400 leading-tight">{{ t.desc }}</p>
                    </div>
                    <span v-if="form.trigger_type === t.value" class="material-symbols-rounded text-blue-500 text-lg">check</span>
                  </button>
                </div>
                <div class="h-2"></div>
              </div>
            </div>

            <!-- Trigger Config -->
            <div v-if="hasTriggerConfig" class="p-4 rounded-xl bg-surface-50 dark:bg-[rgb(var(--color-bg))] space-y-3">
              <!-- Deal stage -->
              <div v-if="needsTriggerStage">
                <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.stage') }}</label>
                <select v-model="form.trigger_config.stage" :class="selectClass">
                  <option value="">Any stage</option>
                  <option v-for="s in stageOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </div>
              <div v-if="needsTriggerDays">
                <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.days') }}</label>
                <input v-model.number="form.trigger_config.days" type="number" min="1" max="365" placeholder="7" :class="inputClass" />
              </div>
              <!-- Health threshold -->
              <div v-if="needsTriggerThreshold">
                <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.healthScoreThreshold') }}</label>
                <input v-model.number="form.trigger_config.threshold" type="number" min="1" max="100" placeholder="30" :class="inputClass" />
              </div>
              <!-- Task changed config -->
              <template v-if="needsTriggerTaskConfig">
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.changeType') }}</label>
                  <select v-model="form.trigger_config.change_type" :class="selectClass">
                    <option value="">{{ t('crmAutomationRuleEditor.anyChange') }}</option>
                    <option value="created">{{ t('crmAutomationRuleEditor.created') }}</option>
                    <option value="updated">{{ t('crmAutomationRuleEditor.updated') }}</option>
                    <option value="completed">{{ t('crmAutomationRuleEditor.completed') }}</option>
                    <option value="deleted">{{ t('crmAutomationRuleEditor.deleted') }}</option>
                  </select>
                </div>
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.taskStatus') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.optionalFilter') }}</span></label>
                  <select v-model="form.trigger_config.status" :class="selectClass">
                    <option value="">{{ t('crmAutomationRuleEditor.anyStatus') }}</option>
                    <option value="pending">{{ t('crmAutomationRuleEditor.pending') }}</option>
                    <option value="in_progress">{{ t('crmAutomationRuleEditor.inProgress') }}</option>
                    <option value="completed">{{ t('crmAutomationRuleEditor.completed') }}</option>
                    <option value="cancelled">{{ t('crmAutomationRuleEditor.cancelled') }}</option>
                  </select>
                </div>
              </template>
              <!-- Time spent reached config -->
              <template v-if="needsTriggerTimeSpent">
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label :class="smallLabelClass">When tracked time reaches</label>
                    <div class="flex items-center gap-2">
                      <input v-model.number="form.trigger_config.hours" type="number" min="1" max="9999" placeholder="10" :class="inputClass" class="!w-24" />
                      <span class="text-sm text-surface-500 font-medium whitespace-nowrap">{{ t('crmAutomationRuleEditor.hours') }}</span>
                    </div>
                  </div>
                  <div>
                    <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.withinPeriod') }}</label>
                    <select v-model="form.trigger_config.period" :class="selectClass">
                      <option value="day">{{ t('crmAutomationRuleEditor.perDay') }}</option>
                      <option value="week">{{ t('crmAutomationRuleEditor.perWeek') }}</option>
                      <option value="month">{{ t('crmAutomationRuleEditor.perMonth') }}</option>
                      <option value="quarter">{{ t('crmAutomationRuleEditor.perQuarter') }}</option>
                    </select>
                  </div>
                </div>
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.trackWhat') }}</label>
                  <select v-model="form.trigger_config.scope" :class="selectClass">
                    <option value="board">{{ t('crmAutomationRuleEditor.perProjectBoard') }}</option>
                    <option value="client">{{ t('crmAutomationRuleEditor.perClientAllActivities') }}</option>
                  </select>
                </div>
                <!-- Board picker (when scope is board) -->
                <div v-if="form.trigger_config.scope === 'board'">
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.specificProject') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.optionalLeaveEmptyForAny') }}</span></label>
                  <!-- Selected board pill -->
                  <div v-if="selectedBoardName" class="flex items-center gap-2 p-2.5 rounded-xl border border-primary-300 dark:border-primary-500/40 bg-primary-50 dark:bg-primary-500/10 mb-2">
                    <span class="material-symbols-rounded text-primary-500 text-lg">dashboard</span>
                    <span class="text-sm font-medium text-primary-700 dark:text-primary-300 flex-1 truncate">{{ selectedBoardName }}</span>
                    <button @click="form.trigger_config.board_id = ''" class="p-0.5 rounded hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors">
                      <span class="material-symbols-rounded text-primary-400 text-base">close</span>
                    </button>
                  </div>
                  <!-- Board browser -->
                  <div class="rounded-xl border border-surface-200 dark:border-surface-600 bg-white dark:bg-[rgb(var(--color-bg))] overflow-hidden">
                    <div class="px-3 py-2 border-b border-surface-100 dark:border-surface-700">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-surface-400 text-base">search</span>
                        <input v-model="boardSearchQuery" :placeholder="t('crmAutomationRuleEditor.searchBoards')" class="w-full text-sm bg-transparent outline-none text-surface-800 dark:text-surface-100 placeholder-surface-400" />
                      </div>
                    </div>
                    <div class="max-h-[180px] overflow-y-auto py-1 px-1">
                      <!-- Any board option -->
                      <button @click="form.trigger_config.board_id = ''"
                        :class="['w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-colors mb-0.5',
                          !form.trigger_config.board_id ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-medium' : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-600 dark:text-surface-300']">
                        <span class="material-symbols-rounded text-base" :class="!form.trigger_config.board_id ? 'text-primary-500' : 'text-surface-400'">select_all</span>
                        <span class="text-sm">{{ t('crmAutomationRuleEditor.anyBoardProject') }}</span>
                        <span v-if="!form.trigger_config.board_id" class="material-symbols-rounded text-primary-500 text-sm ml-auto">check</span>
                      </button>
                      <button v-for="b in filteredBoards" :key="b.id" @click="form.trigger_config.board_id = b.id"
                        :class="['w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-colors mb-0.5',
                          form.trigger_config.board_id == b.id ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-medium' : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200']">
                        <span class="material-symbols-rounded text-base" :class="form.trigger_config.board_id == b.id ? 'text-primary-500' : 'text-surface-400'">dashboard</span>
                        <span class="text-sm truncate flex-1">{{ b.name }}</span>
                        <span v-if="form.trigger_config.board_id == b.id" class="material-symbols-rounded text-primary-500 text-sm flex-shrink-0">check</span>
                      </button>
                    </div>
                  </div>
                </div>
                <!-- Colleague filter -->
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.trackTimeBy') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.whosTrackedHoursToWatch') }}</span></label>
                  <select v-model="form.trigger_config.colleague_email" :class="selectClass">
                    <option value="">Myself (rule owner)</option>
                    <option v-for="c in (colleaguesStore.colleagues || [])" :key="c.email" :value="c.email">
                      {{ c.display_name || c.email }}
                    </option>
                  </select>
                  <p class="text-[10px] text-surface-400 mt-1">
                    {{ form.trigger_config.colleague_email ? t('crmAutomationRuleEditor.trackingHoursLoggedBy', { email: form.trigger_config.colleague_email }) : t('crmAutomationRuleEditor.trackingYourOwnLoggedHours') }}
                  </p>
                </div>
              </template>
              <!-- Colleague sick keyword -->
              <template v-if="needsTriggerSickKeyword">
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.statusKeywordToMatch') }}</label>
                  <input v-model="form.trigger_config.keyword" :placeholder="t('crmAutomationRuleEditor.sick')" :class="inputClass" />
                  <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.triggersWhenAColleaguesStatus') }}</p>
                </div>
              </template>
              <!-- Drive folder permission config -->
              <template v-if="needsTriggerDriveFolder">
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.permissionChangeType') }}</label>
                  <select v-model="form.trigger_config.change_type" :class="selectClass">
                    <option value="">Any change</option>
                    <option value="added">Collaborator added</option>
                    <option value="removed">Collaborator removed</option>
                    <option value="updated">Permission updated</option>
                  </select>
                </div>
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.watchSpecificFolder') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.optional') }}</span></label>

                  <!-- Selected folder pill -->
                  <div v-if="selectedFolderName" class="flex items-center gap-2 p-2.5 rounded-xl border border-blue-300 dark:border-blue-500/40 bg-blue-50 dark:bg-blue-500/10 mb-2">
                    <span class="material-symbols-rounded text-blue-500 text-lg">folder</span>
                    <span class="text-sm font-medium text-blue-700 dark:text-blue-300 flex-1 truncate">{{ selectedFolderName }}</span>
                    <button @click="clearDriveFolderSelection" class="p-0.5 rounded hover:bg-blue-100 dark:hover:bg-blue-500/20 transition-colors">
                      <span class="material-symbols-rounded text-blue-400 text-base">close</span>
                    </button>
                  </div>

                  <!-- Folder browser -->
                  <div class="rounded-xl border border-surface-200 dark:border-surface-600 bg-white dark:bg-[rgb(var(--color-bg))] overflow-hidden">
                    <!-- Search -->
                    <div class="px-3 py-2 border-b border-surface-100 dark:border-surface-700">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-surface-400 text-base">search</span>
                        <input
                          v-model="driveFolderSearch"
                          :placeholder="t('crmAutomationRuleEditor.searchFolders')"
                          class="w-full text-sm bg-transparent outline-none text-surface-800 dark:text-surface-100 placeholder-surface-400"
                        />
                      </div>
                    </div>

                    <!-- Loading -->
                    <div v-if="driveFoldersLoading" class="px-4 py-6 text-center">
                      <span class="material-symbols-rounded text-xl text-surface-300 animate-spin">progress_activity</span>
                      <p class="text-xs text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.loadingFolders') }}</p>
                    </div>

                    <!-- Empty -->
                    <div v-else-if="!filteredFolderTree.length" class="px-4 py-6 text-center">
                      <span class="material-symbols-rounded text-xl text-surface-300">folder_off</span>
                      <p class="text-xs text-surface-400 mt-1">{{ driveFolderSearch ? 'No folders match your search' : 'No folders found' }}</p>
                    </div>

                    <!-- Folder tree -->
                    <div v-else class="max-h-[200px] overflow-y-auto py-1">
                      <div class="px-1">
                        <!-- "All folders" option -->
                        <button
                          @click="clearDriveFolderSelection"
                          :class="[
                            'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-sm transition-colors',
                            !form.trigger_config.folder_id
                              ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 font-medium'
                              : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-600 dark:text-surface-300'
                          ]"
                        >
                          <span class="material-symbols-rounded text-base" :class="!form.trigger_config.folder_id ? 'text-blue-500' : 'text-surface-400'">folder_copy</span>
                          All folders
                          <span v-if="!form.trigger_config.folder_id" class="material-symbols-rounded text-blue-500 text-sm ml-auto">check</span>
                        </button>

                        <!-- Recursive folder tree component -->
                        <template v-for="folder in filteredFolderTree" :key="folder.id">
                          <div class="folder-item">
                            <button
                              @click="selectDriveFolder(folder)"
                              :class="[
                                'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-sm transition-colors group',
                                form.trigger_config.folder_id === folder.id
                                  ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 font-medium'
                                  : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200'
                              ]"
                            >
                              <!-- Expand/collapse toggle -->
                              <button
                                v-if="folder.children?.length"
                                @click.stop="toggleFolderExpand(folder.id)"
                                class="w-5 h-5 flex items-center justify-center rounded hover:bg-surface-200 dark:hover:bg-surface-600 flex-shrink-0 -ml-1"
                              >
                                <span class="material-symbols-rounded text-sm text-surface-400" :class="expandedFolderIds.has(folder.id) ? 'rotate-90' : ''">chevron_right</span>
                              </button>
                              <span v-else class="w-5 flex-shrink-0"></span>

                              <span class="material-symbols-rounded text-base flex-shrink-0" :class="form.trigger_config.folder_id === folder.id ? 'text-blue-500' : 'text-amber-500 dark:text-amber-400'">
                                {{ expandedFolderIds.has(folder.id) ? 'folder_open' : 'folder' }}
                              </span>
                              <span class="truncate flex-1">{{ folder.name }}</span>
                              <span v-if="folder.children?.length" class="text-[10px] text-surface-400 flex-shrink-0">{{ folder.children.length }}</span>
                              <span v-if="form.trigger_config.folder_id === folder.id" class="material-symbols-rounded text-blue-500 text-sm flex-shrink-0">check</span>
                            </button>

                            <!-- Children (nested) -->
                            <div v-if="folder.children?.length && expandedFolderIds.has(folder.id)" class="pl-5">
                              <template v-for="child in folder.children" :key="child.id">
                                <div class="folder-item">
                                  <button
                                    @click="selectDriveFolder(child)"
                                    :class="[
                                      'w-full flex items-center gap-2 px-3 py-1.5 rounded-lg text-left text-sm transition-colors',
                                      form.trigger_config.folder_id === child.id
                                        ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 font-medium'
                                        : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-600 dark:text-surface-300'
                                    ]"
                                  >
                                    <button
                                      v-if="child.children?.length"
                                      @click.stop="toggleFolderExpand(child.id)"
                                      class="w-5 h-5 flex items-center justify-center rounded hover:bg-surface-200 dark:hover:bg-surface-600 flex-shrink-0 -ml-1"
                                    >
                                      <span class="material-symbols-rounded text-sm text-surface-400" :class="expandedFolderIds.has(child.id) ? 'rotate-90' : ''">chevron_right</span>
                                    </button>
                                    <span v-else class="w-5 flex-shrink-0"></span>

                                    <span class="material-symbols-rounded text-base flex-shrink-0" :class="form.trigger_config.folder_id === child.id ? 'text-blue-500' : 'text-amber-400 dark:text-amber-500'">
                                      {{ expandedFolderIds.has(child.id) ? 'folder_open' : 'folder' }}
                                    </span>
                                    <span class="truncate flex-1">{{ child.name }}</span>
                                    <span v-if="child.children?.length" class="text-[10px] text-surface-400 flex-shrink-0">{{ child.children.length }}</span>
                                    <span v-if="form.trigger_config.folder_id === child.id" class="material-symbols-rounded text-blue-500 text-sm flex-shrink-0">check</span>
                                  </button>

                                  <!-- Third level -->
                                  <div v-if="child.children?.length && expandedFolderIds.has(child.id)" class="pl-5">
                                    <button
                                      v-for="grandchild in child.children" :key="grandchild.id"
                                      @click="selectDriveFolder(grandchild)"
                                      :class="[
                                        'w-full flex items-center gap-2 px-3 py-1.5 rounded-lg text-left text-sm transition-colors',
                                        form.trigger_config.folder_id === grandchild.id
                                          ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 font-medium'
                                          : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-600 dark:text-surface-300'
                                      ]"
                                    >
                                      <span class="w-5 flex-shrink-0"></span>
                                      <span class="material-symbols-rounded text-sm flex-shrink-0" :class="form.trigger_config.folder_id === grandchild.id ? 'text-blue-500' : 'text-amber-400'">folder</span>
                                      <span class="truncate flex-1">{{ grandchild.name }}</span>
                                      <span v-if="form.trigger_config.folder_id === grandchild.id" class="material-symbols-rounded text-blue-500 text-sm flex-shrink-0">check</span>
                                    </button>
                                  </div>
                                </div>
                              </template>
                            </div>
                          </div>
                        </template>
                      </div>
                    </div>
                  </div>
                  <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.leaveEmptyToWatchAll') }}</p>
                </div>
              </template>

              <!-- Campaign Engagement Threshold Config -->
              <template v-if="needsTriggerEngagement">
                <div>
                  <label :class="smallLabelClass">Campaign <span class="text-surface-400 font-normal">(optional)</span></label>
                  <div v-if="emailCampaignsLoading" class="flex items-center gap-2 py-2 text-xs text-surface-400">
                    <span class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
                    Loading campaigns...
                  </div>
                  <select v-else v-model="form.trigger_config.campaign_id" :class="selectClass">
                    <option value="">Any campaign</option>
                    <option v-for="c in emailCampaigns" :key="c.campaign_id" :value="c.campaign_id">
                      {{ c.name || c.subject || 'Untitled Campaign' }}
                    </option>
                  </select>
                  <p class="text-[10px] text-surface-400 mt-1">Leave empty to apply to all campaigns</p>
                </div>
                <div>
                  <label :class="smallLabelClass">Engagement Metric</label>
                  <select v-model="form.trigger_config.metric" :class="selectClass">
                    <option value="link_click_rate">Link Click Rate (%)</option>
                    <option value="video_link_click_rate">Video Link Click Rate (%)</option>
                    <option value="opened">Email Opened (count)</option>
                  </select>
                </div>
                <div>
                  <label :class="smallLabelClass">
                    Threshold {{ form.trigger_config.metric === 'opened' ? '(open count)' : '(percentage)' }}
                  </label>
                  <div class="flex items-center gap-3">
                    <input
                      v-model.number="form.trigger_config.threshold"
                      type="range"
                      :min="1"
                      :max="100"
                      class="flex-1 accent-primary-500"
                    />
                    <span class="text-sm font-medium text-surface-700 dark:text-surface-300 w-12 text-right">
                      {{ form.trigger_config.threshold }}{{ form.trigger_config.metric !== 'opened' ? '%' : '' }}
                    </span>
                  </div>
                </div>
              </template>

              <!-- Email tracking scope + Link filter (2-column layout) -->
              <div v-if="needsTriggerEmailScope || needsTriggerLinkFilter" class="grid grid-cols-1 md:grid-cols-2 gap-4 !-mx-4 !px-4">
                <!-- Left column: Email Scope + Specific Campaign -->
                <div v-if="needsTriggerEmailScope" class="space-y-3">
                  <div>
                    <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.emailScope') }}</label>
                    <select v-model="form.trigger_config.scope" :class="selectClass">
                      <option value="all">All tracked emails</option>
                      <option value="campaigns">Campaign emails only</option>
                      <option value="regular">Regular emails only (non-campaign)</option>
                    </select>
                    <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.chooseWhichTypesOfEmails') }}</p>
                  </div>

                  <div v-if="form.trigger_config.scope === 'campaigns'">
                    <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.specificCampaign') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.optional') }}</span></label>
                    <div v-if="emailCampaignsLoading" class="flex items-center gap-2 py-2 text-xs text-surface-400">
                      <span class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
                      Loading campaigns...
                    </div>
                    <select v-else v-model="form.trigger_config.campaign_id" :class="selectClass">
                      <option value="">Any campaign</option>
                      <option v-for="c in emailCampaigns" :key="c.campaign_id" :value="c.campaign_id">
                        {{ c.name || c.subject || 'Untitled Campaign' }}
                      </option>
                    </select>
                    <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.leaveEmptyToMatchAll') }}</p>
                  </div>
                </div>

                <!-- Right column: Link Filter + Select Link -->
                <div v-if="needsTriggerLinkFilter" class="space-y-3">
                  <div>
                    <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.linkFilter') }}</label>
                    <select v-model="form.trigger_config.link_match" :class="selectClass">
                      <option value="any">{{ t('crmAutomationRuleEditor.anyLinkClicked') }}</option>
                      <option value="exact">{{ t('crmAutomationRuleEditor.specificLink') }}</option>
                      <option value="contains">{{ t('crmAutomationRuleEditor.linkUrlContains') }}</option>
                    </select>
                  </div>

                  <!-- Specific link picker -->
                  <div v-if="form.trigger_config.link_match === 'exact'">
                    <label :class="smallLabelClass">Select Link</label>

                    <!-- Selected link pill -->
                    <div v-if="form.trigger_config.link_value" class="flex items-center gap-2 p-2.5 rounded-xl border border-green-300 dark:border-green-500/40 bg-green-50 dark:bg-green-500/10 mb-2">
                      <span class="material-symbols-rounded text-green-500 text-lg">link</span>
                      <span class="text-sm font-medium text-green-700 dark:text-green-300 flex-1 truncate">{{ form.trigger_config.link_value }}</span>
                      <button @click="form.trigger_config.link_value = ''" class="p-0.5 rounded hover:bg-green-100 dark:hover:bg-green-500/20 transition-colors">
                        <span class="material-symbols-rounded text-green-400 text-base">close</span>
                      </button>
                    </div>

                    <!-- Link browser -->
                    <div class="rounded-xl border border-surface-200 dark:border-surface-600 bg-white dark:bg-[rgb(var(--color-bg))] overflow-hidden">
                      <!-- Loading -->
                      <div v-if="trackedLinksLoading" class="px-4 py-6 text-center">
                        <span class="material-symbols-rounded text-xl text-surface-300 animate-spin">progress_activity</span>
                        <p class="text-xs text-surface-400 mt-1">Loading tracked links...</p>
                      </div>

                      <!-- Empty -->
                      <div v-else-if="!trackedLinks.length" class="px-4 py-6 text-center">
                        <span class="material-symbols-rounded text-xl text-surface-300">link_off</span>
                        <p class="text-xs text-surface-400 mt-1">No tracked links found</p>
                        <p class="text-[10px] text-surface-400 mt-0.5">Links appear here after sending tracked emails</p>
                      </div>

                      <!-- Link list -->
                      <div v-else class="max-h-[200px] overflow-y-auto py-1 px-1">
                        <button
                          v-for="link in trackedLinks" :key="link.original_url"
                          @click="form.trigger_config.link_value = link.original_url"
                          :class="[
                            'w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-left transition-colors mb-0.5',
                            form.trigger_config.link_value === link.original_url
                              ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-300 font-medium'
                              : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200'
                          ]"
                        >
                          <span class="material-symbols-rounded text-base flex-shrink-0" :class="form.trigger_config.link_value === link.original_url ? 'text-green-500' : 'text-surface-400'">link</span>
                          <div class="flex-1 min-w-0">
                            <p class="text-sm truncate">{{ link.original_url }}</p>
                            <p class="text-[10px] text-surface-400">
                              {{ link.total_clicks || 0 }} click{{ link.total_clicks == 1 ? '' : 's' }}
                              <template v-if="link.unique_clickers"> · {{ link.unique_clickers }} unique</template>
                            </p>
                          </div>
                          <span v-if="form.trigger_config.link_value === link.original_url" class="material-symbols-rounded text-green-500 text-sm flex-shrink-0">check</span>
                        </button>
                      </div>
                    </div>
                    <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.triggerOnlyWhenExactLink') }}</p>
                  </div>

                  <!-- URL contains text input -->
                  <div v-if="form.trigger_config.link_match === 'contains'">
                    <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.urlContains') }}</label>
                    <input
                      v-model="form.trigger_config.link_value"
                      :class="inputClass"
                      :placeholder="t('crmAutomationRuleEditor.egPricingSignupDemo')"
                    />
                    <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.triggerOnlyWhenUrlContains') }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      <!-- Flow connector: Trigger -> Action -->
      <div class="flex justify-center pointer-events-none relative z-0" style="margin-top:-1px;margin-bottom:-1px;height:100px;">
        <svg width="20" height="100" viewBox="0 0 20 100" fill="none">
          <defs>
            <linearGradient id="grad-trigger-action" x1="10" y1="0" x2="10" y2="100" gradientUnits="userSpaceOnUse">
              <stop offset="0%" stop-color="#3b82f6" />
              <stop offset="100%" stop-color="#a855f7" />
            </linearGradient>
          </defs>
          <line x1="10" y1="0" x2="10" y2="92" stroke="url(#grad-trigger-action)" stroke-width="2" />
          <polygon points="4,84 10,92 16,84" fill="#a855f7" />
          <circle r="3" fill="#3b82f6" opacity="0.9">
            <animateMotion dur="2s" repeatCount="indefinite" path="M10,0 L10,92" begin="0s" />
          </circle>
          <circle r="3" fill="#a855f7" opacity="0.9">
            <animateMotion dur="2s" repeatCount="indefinite" path="M10,0 L10,92" begin="-1s" />
          </circle>
        </svg>
      </div>

      <!-- Row 2: Action (full width) -->
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl border border-surface-200 dark:border-[rgb(var(--color-border))] overflow-visible">
          <div class="px-5 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
            <h3 class="text-sm font-semibold text-surface-900 dark:text-white flex items-center gap-2">
              <span class="material-symbols-rounded text-purple-500">play_arrow</span>
              {{ t('crmAutomationRuleEditor.doThis') }}
            </h3>
          </div>

          <!-- Action selector dropdown -->
          <div class="p-5 space-y-4">
            <div class="action-dropdown-wrapper relative">
              <!-- Selected action button -->
              <button
                @click.stop="actionDropdownOpen = !actionDropdownOpen"
                class="w-full flex items-center gap-3 p-3.5 rounded-xl border transition-all text-left"
                :class="actionDropdownOpen
                  ? 'border-purple-500 ring-2 ring-purple-500/20 bg-purple-50/50 dark:bg-purple-500/5'
                  : 'border-surface-300 dark:border-surface-600 hover:border-surface-400 dark:hover:border-surface-500 bg-white dark:bg-[rgb(var(--color-bg))]'"
              >
                <span class="w-9 h-9 rounded-lg bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-purple-600 dark:text-purple-400">{{ selectedAction.icon }}</span>
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-surface-900 dark:text-white">{{ selectedAction.label }}</p>
                  <p class="text-[11px] text-surface-400 leading-tight truncate">{{ selectedAction.desc }}</p>
                </div>
                <span class="material-symbols-rounded text-surface-400 text-xl transition-transform" :class="actionDropdownOpen ? 'rotate-180' : ''">expand_more</span>
              </button>

              <!-- Dropdown panel -->
              <div
                v-if="actionDropdownOpen"
                class="absolute z-50 top-full left-0 right-0 mt-1 bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] shadow-xl max-h-[380px] overflow-y-auto py-2 px-2"
              >
                <button
                  v-for="a in actionTypes" :key="a.value"
                  @click="selectAction(a)"
                  :class="[
                    'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors mb-0.5',
                    form.action_type === a.value
                      ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300'
                      : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg" :class="form.action_type === a.value ? 'text-purple-500' : 'text-surface-400'">{{ a.icon }}</span>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ a.label }}</p>
                    <p class="text-[11px] text-surface-400 leading-tight">{{ a.desc }}</p>
                  </div>
                  <span v-if="form.action_type === a.value" class="material-symbols-rounded text-purple-500 text-lg">check</span>
                </button>
              </div>
            </div>

            <!-- Action Config -->
            <div class="p-4 rounded-xl bg-surface-50 dark:bg-[rgb(var(--color-bg))] space-y-3">
              <!-- Reminder config -->
              <template v-if="form.action_type === 'create_reminder'">
                <div>
                  <label :class="smallLabelClass">Reminder Title</label>
                  <input v-model="form.action_config.title" :placeholder="t('crmAutomationRuleEditor.followUpWithClientnameAbout')" :class="inputClass" />
                  <div class="flex flex-wrap gap-1 mt-1.5">
                    <button v-for="v in templateVars" :key="v.key" @click="insertVarToField('title', v.key)"
                      class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors cursor-pointer">
                      <span class="material-symbols-rounded" style="font-size:10px">{{ v.icon }}</span>{{ v.key }}
                    </button>
                  </div>
                </div>
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.delayHoursAfterTrigger') }}</label>
                  <input v-model.number="form.action_config.delay_hours" type="number" min="0" placeholder="0" :class="inputClass" />
                </div>
              </template>

              <!-- Move stage config -->
              <template v-if="form.action_type === 'move_deal_stage'">
                <!-- Deal picker -->
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.whichDeal') }}</label>
                  <p class="text-[11px] text-surface-400 mb-2">{{ t('crmAutomationRuleEditor.leaveEmptyToMoveThe') }}</p>

                  <!-- Selected deal display -->
                  <div v-if="selectedDealName" class="flex items-center justify-between px-4 py-2.5 rounded-xl border border-primary-300 dark:border-primary-600 bg-primary-50 dark:bg-primary-500/10 mb-2">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-sm text-primary-500">handshake</span>
                      <span class="text-sm font-medium text-primary-700 dark:text-primary-300">{{ selectedDealName }}</span>
                    </div>
                    <button @click="clearDeal" class="text-xs text-surface-400 hover:text-red-500 transition-colors">
                      <span class="material-symbols-rounded text-sm">close</span>
                    </button>
                  </div>

                  <!-- Deal search & list -->
                  <div class="border border-surface-200 dark:border-surface-600 rounded-xl overflow-hidden">
                    <div class="flex items-center gap-2 px-3 py-2 border-b border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-800/50">
                      <span class="material-symbols-rounded text-sm text-surface-400">search</span>
                      <input v-model="dealSearchQuery" :placeholder="t('crmAutomationRuleEditor.searchDealsByNameClient')" class="w-full text-sm bg-transparent outline-none text-surface-800 dark:text-surface-100 placeholder-surface-400" />
                    </div>

                    <div v-if="dealsLoading" class="px-4 py-6 text-center">
                      <span class="material-symbols-rounded text-2xl text-surface-300 animate-spin">progress_activity</span>
                      <p class="text-xs text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.loadingDeals') }}</p>
                    </div>

                    <div v-else class="max-h-56 overflow-y-auto divide-y divide-surface-100 dark:divide-surface-700">
                      <!-- Trigger deal option -->
                      <button @click="clearDeal"
                        class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors"
                        :class="!form.action_config.deal_id ? 'bg-primary-50 dark:bg-primary-500/10' : ''">
                        <span class="material-symbols-rounded text-base" :class="!form.action_config.deal_id ? 'text-primary-500' : 'text-surface-400'">auto_awesome</span>
                        <div>
                          <p class="text-sm font-medium" :class="!form.action_config.deal_id ? 'text-primary-700 dark:text-primary-300' : 'text-surface-700 dark:text-surface-200'">{{ t('crmAutomationRuleEditor.triggeredDealAutomatic') }}</p>
                          <p class="text-[11px] text-surface-400">{{ t('crmAutomationRuleEditor.useWhicheverDealTriggeredThis') }}</p>
                        </div>
                      </button>

                      <button v-for="deal in filteredDeals" :key="deal.id"
                        @click="selectDeal(deal)"
                        class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors"
                        :class="form.action_config.deal_id == deal.id ? 'bg-primary-50 dark:bg-primary-500/10' : ''">
                        <span class="material-symbols-rounded text-base" :class="form.action_config.deal_id == deal.id ? 'text-primary-500' : 'text-surface-400'">handshake</span>
                        <div class="flex-1 min-w-0">
                          <div class="flex items-center gap-2">
                            <p class="text-sm font-medium truncate" :class="form.action_config.deal_id == deal.id ? 'text-primary-700 dark:text-primary-300' : 'text-surface-700 dark:text-surface-200'">{{ deal.title }}</p>
                            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide flex-shrink-0" :class="stageColors[deal.pipeline_stage] || 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300'">{{ deal.pipeline_stage }}</span>
                          </div>
                          <p class="text-[11px] text-surface-400 truncate">
                            {{ deal.client_name || 'No client' }}
                            <template v-if="deal.value"> · {{ Number(deal.value).toLocaleString() }} {{ deal.currency || '' }}</template>
                          </p>
                        </div>
                        <span v-if="form.action_config.deal_id == deal.id" class="material-symbols-rounded text-sm text-primary-500 flex-shrink-0">check_circle</span>
                      </button>

                      <div v-if="filteredDeals.length === 0 && !dealsLoading" class="px-4 py-4 text-center">
                        <p class="text-xs text-surface-400">{{ t('crmAutomationRuleEditor.noDealsFound') }}</p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Stage selector -->
                <div>
                  <label :class="smallLabelClass">Move to Stage</label>
                  <select v-model="form.action_config.to_stage" :class="selectClass">
                    <option v-for="s in stageOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                  </select>
                </div>
              </template>

              <!-- Notify config -->
              <template v-if="form.action_type === 'notify_user'">
                <div>
                  <label :class="smallLabelClass">Notification Message</label>
                  <input v-model="form.action_config.message" :placeholder="t('crmAutomationRuleEditor.alertBoardnameHasBeenClosed')" :class="inputClass" />
                  <div class="flex flex-wrap gap-1 mt-1.5">
                    <button v-for="v in templateVars" :key="v.key" @click="insertVarToField('message', v.key)"
                      class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors cursor-pointer">
                      <span class="material-symbols-rounded" style="font-size:10px">{{ v.icon }}</span>{{ v.key }}
                    </button>
                  </div>
                </div>
              </template>

              <!-- Sequence config -->
              <template v-if="form.action_type === 'start_sequence'">
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.sequenceId') }}</label>
                  <input v-model.number="form.action_config.sequence_id" type="number" min="1" :placeholder="t('crmAutomationRuleEditor.enterSequenceId')" :class="inputClass" />
                </div>
              </template>

              <!-- Email config note (subject + body are in the full-width section below) -->
              <template v-if="form.action_type === 'send_email'">
                <p class="text-xs text-surface-400 flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">arrow_downward</span>
                  {{ t('crmAutomationRuleEditor.configureSubjectAndEmail') }}
                </p>
              </template>

              <!-- Invoice draft -->
              <template v-if="form.action_type === 'create_invoice_draft'">
                <p class="text-xs text-surface-400">{{ t('crmAutomationRuleEditor.anInvoiceDraftWillBe') }}</p>
              </template>

              <!-- Assign task config -->
              <template v-if="form.action_type === 'assign_task'">
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.taskTitle') }}</label>
                  <input v-model="form.action_config.title" :placeholder="t('crmAutomationRuleEditor.egReviewBoardnameDeliverables')" :class="inputClass" />
                  <div class="flex flex-wrap gap-1 mt-1.5">
                    <button v-for="v in templateVars" :key="v.key" @click="insertVarToField('title', v.key)"
                      class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors cursor-pointer">
                      <span class="material-symbols-rounded" style="font-size:10px">{{ v.icon }}</span>{{ v.key }}
                    </button>
                  </div>
                </div>
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.description') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.optional') }}</span></label>
                  <input v-model="form.action_config.description" :placeholder="t('crmAutomationRuleEditor.additionalDetailsAboutTargetname')" :class="inputClass" />
                  <div class="flex flex-wrap gap-1 mt-1.5">
                    <button v-for="v in templateVars" :key="v.key" @click="insertVarToField('description', v.key)"
                      class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors cursor-pointer">
                      <span class="material-symbols-rounded" style="font-size:10px">{{ v.icon }}</span>{{ v.key }}
                    </button>
                  </div>
                </div>
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.assignToEmail') }}</label>
                  <input v-model="form.action_config.assign_to_email" type="email" :placeholder="t('crmAutomationRuleEditor.colleaguecompanycom')" :class="inputClass" />
                  <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.leaveEmptyToAssignTo') }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.dueInDays') }}</label>
                    <input v-model.number="form.action_config.due_days" type="number" min="0" max="365" placeholder="1" :class="inputClass" />
                  </div>
                  <div>
                    <label :class="smallLabelClass">Priority</label>
                    <select v-model="form.action_config.priority" :class="selectClass">
                      <option value="low">{{ t('crmAutomationRuleEditor.low') }}</option>
                      <option value="medium">{{ t('crmAutomationRuleEditor.medium') }}</option>
                      <option value="high">{{ t('crmAutomationRuleEditor.high') }}</option>
                      <option value="urgent">{{ t('crmAutomationRuleEditor.urgent') }}</option>
                    </select>
                  </div>
                </div>
              </template>

              <!-- Send chat message config -->
              <template v-if="form.action_type === 'send_chat_message'">
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.message') }}</label>
                  <textarea v-model="form.action_config.message" :placeholder="t('crmAutomationRuleEditor.egMoodboardnameHasBeenMarked')" rows="3" :class="inputClass" />
                  <div class="flex flex-wrap gap-1 mt-1.5">
                    <button v-for="v in templateVars" :key="v.key" @click="insertVarToField('message', v.key)"
                      class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors cursor-pointer">
                      <span class="material-symbols-rounded" style="font-size:10px">{{ v.icon }}</span>{{ v.key }}
                    </button>
                  </div>
                </div>
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.sendTo') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.optionalLeaveEmptyForAuto') }}</span></label>

                  <!-- Selected conversation pill -->
                  <div v-if="selectedChatName" class="flex items-center gap-2 p-2.5 rounded-xl border border-purple-300 dark:border-purple-500/40 bg-purple-50 dark:bg-purple-500/10 mb-2">
                    <span class="material-symbols-rounded text-purple-500 text-lg">chat</span>
                    <span class="text-sm font-medium text-purple-700 dark:text-purple-300 flex-1 truncate">{{ selectedChatName }}</span>
                    <button @click="clearChatSelection" class="p-0.5 rounded hover:bg-purple-100 dark:hover:bg-purple-500/20 transition-colors">
                      <span class="material-symbols-rounded text-purple-400 text-base">close</span>
                    </button>
                  </div>

                  <!-- Chat browser -->
                  <div class="rounded-xl border border-surface-200 dark:border-surface-600 bg-white dark:bg-[rgb(var(--color-bg))] overflow-hidden">
                    <!-- Search -->
                    <div class="px-3 py-2 border-b border-surface-100 dark:border-surface-700">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-surface-400 text-base">search</span>
                        <input
                          v-model="chatSearch"
                          :placeholder="t('crmAutomationRuleEditor.searchConversations')"
                          class="w-full text-sm bg-transparent outline-none text-surface-800 dark:text-surface-100 placeholder-surface-400"
                        />
                      </div>
                    </div>

                    <!-- Loading -->
                    <div v-if="chatConversationsLoading" class="px-4 py-6 text-center">
                      <span class="material-symbols-rounded text-xl text-surface-300 animate-spin">progress_activity</span>
                      <p class="text-xs text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.loadingConversations') }}</p>
                    </div>

                    <!-- Empty -->
                    <div v-else-if="!filteredChatConversations.length" class="px-4 py-6 text-center">
                      <span class="material-symbols-rounded text-xl text-surface-300">forum</span>
                      <p class="text-xs text-surface-400 mt-1">{{ chatSearch ? t('crmAutomationRuleEditor.noChatsMatchSearch') : t('crmAutomationRuleEditor.noConversationsFound') }}</p>
                    </div>

                    <!-- Conversation list -->
                    <div v-else class="max-h-[220px] overflow-y-auto py-1 px-1">
                      <!-- Auto DM option -->
                      <button
                        @click="clearChatSelection"
                        :class="[
                          'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors mb-0.5',
                          !form.action_config.conversation_id
                            ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 font-medium'
                            : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-600 dark:text-surface-300'
                        ]"
                      >
                        <span class="w-8 h-8 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
                          <span class="material-symbols-rounded text-base" :class="!form.action_config.conversation_id ? 'text-purple-500' : 'text-surface-400'">send</span>
                        </span>
                        <div class="min-w-0 flex-1">
                          <p class="text-sm font-medium">{{ t('crmAutomationRuleEditor.autoDm') }}</p>
                          <p class="text-[10px] text-surface-400 leading-tight">{{ t('crmAutomationRuleEditor.sendAsDirectMessageTo') }}</p>
                        </div>
                        <span v-if="!form.action_config.conversation_id" class="material-symbols-rounded text-purple-500 text-sm">check</span>
                      </button>

                      <!-- Conversation items -->
                      <button
                        v-for="conv in filteredChatConversations" :key="conv.id"
                        @click="selectChatConversation(conv)"
                        :class="[
                          'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors mb-0.5',
                          form.action_config.conversation_id === conv.id
                            ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 font-medium'
                            : 'hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-700 dark:text-surface-200'
                        ]"
                      >
                        <!-- Avatar / icon -->
                        <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden"
                             :class="form.action_config.conversation_id === conv.id ? 'bg-purple-100 dark:bg-purple-500/20' : 'bg-surface-100 dark:bg-surface-700'">
                          <img
                            v-if="conv.participants?.length === 1 && conv.participants[0].avatar_path"
                            :src="conv.participants[0].avatar_path"
                            class="w-full h-full object-cover"
                          />
                          <span v-else class="material-symbols-rounded text-base"
                                :class="form.action_config.conversation_id === conv.id ? 'text-purple-500' : 'text-surface-400'">
                            {{ getChatIcon(conv) }}
                          </span>
                        </div>

                        <div class="min-w-0 flex-1">
                          <p class="text-sm font-medium truncate">{{ getChatName(conv) }}</p>
                          <p v-if="conv.last_message_preview" class="text-[10px] text-surface-400 leading-tight truncate">{{ conv.last_message_preview }}</p>
                          <p v-else-if="conv.topic" class="text-[10px] text-surface-400 leading-tight truncate">{{ conv.topic }}</p>
                        </div>

                        <!-- Type badge -->
                        <span v-if="conv.type === 'channel' || conv.is_public" class="text-[9px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-surface-100 dark:bg-surface-700 text-surface-500 flex-shrink-0">{{ t('crmAutomationRuleEditor.channel') }}</span>
                        <span v-else-if="conv.type === 'group'" class="text-[9px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-surface-100 dark:bg-surface-700 text-surface-500 flex-shrink-0">{{ t('crmAutomationRuleEditor.group') }}</span>

                        <span v-if="form.action_config.conversation_id === conv.id" class="material-symbols-rounded text-purple-500 text-sm flex-shrink-0">check</span>
                      </button>
                    </div>
                  </div>
                  <p class="text-[10px] text-surface-400 mt-1">Leave empty to automatically send a DM to the target contact</p>
                </div>
              </template>

              <!-- Reassign deals config -->
              <template v-if="form.action_type === 'reassign_deals'">
                <div>
                  <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.newDealOwnerEmail') }}</label>
                  <input v-model="form.action_config.new_owner_email" type="email" :placeholder="t('crmAutomationRuleEditor.newownercompanycom')" :class="inputClass" />
                  <p class="text-[10px] text-surface-400 mt-1">{{ t('crmAutomationRuleEditor.allMatchingDealsWillBe') }}</p>
                </div>
              </template>
            </div>
          </div>
        </div>

      <!-- Flow connector: Action -> Email (only when send_email) -->
      <div v-if="form.action_type === 'send_email'" class="flex justify-center pointer-events-none relative z-0" style="margin-top:-1px;margin-bottom:-1px;height:100px;">
        <svg width="20" height="100" viewBox="0 0 20 100" fill="none">
          <defs>
            <linearGradient id="grad-action-email" x1="10" y1="0" x2="10" y2="100" gradientUnits="userSpaceOnUse">
              <stop offset="0%" stop-color="#a855f7" />
              <stop offset="100%" stop-color="#06b6d4" />
            </linearGradient>
          </defs>
          <line x1="10" y1="0" x2="10" y2="92" stroke="url(#grad-action-email)" stroke-width="2" />
          <polygon points="4,84 10,92 16,84" fill="#06b6d4" />
          <circle r="3" fill="#a855f7" opacity="0.9">
            <animateMotion dur="2s" repeatCount="indefinite" path="M10,0 L10,92" begin="0s" />
          </circle>
          <circle r="3" fill="#06b6d4" opacity="0.9">
            <animateMotion dur="2s" repeatCount="indefinite" path="M10,0 L10,92" begin="-1s" />
          </circle>
        </svg>
      </div>

      <!-- Row 3: Email Config (full-width, only when action is send_email) -->
      <div v-if="form.action_type === 'send_email'" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl border border-surface-200 dark:border-[rgb(var(--color-border))] overflow-hidden">

        <!-- Subject -->
        <div class="px-5 pt-5 pb-4 space-y-3 border-b border-surface-100 dark:border-[rgb(var(--color-border))]">
          <div>
            <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.subject') }}</label>
            <input ref="subjectInput" v-model="form.action_config.subject" :placeholder="t('crmAutomationRuleEditor.egBoardBoardnameHasBeen')" :class="inputClass" />
            <div class="flex flex-wrap gap-1 mt-1.5">
              <button v-for="v in templateVars" :key="v.key" @click="insertVarToSubject(v.key)"
                class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors cursor-pointer">
                <span class="material-symbols-rounded" style="font-size:10px">{{ v.icon }}</span>{{ v.key }}
              </button>
            </div>
          </div>
          <div>
            <label :class="smallLabelClass">{{ t('crmAutomationRuleEditor.templateId') }} <span class="text-surface-400 font-normal">{{ t('crmAutomationRuleEditor.optionalOverridesBody') }}</span></label>
            <input v-model.number="form.action_config.template_id" type="number" min="0" :placeholder="t('crmAutomationRuleEditor.leaveEmptyForCustomContent')" :class="inputClass" />
          </div>
        </div>

        <!-- Email Body Header -->
        <div class="px-5 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex items-center justify-between">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">edit_note</span>
            {{ t('crmAutomationRuleEditor.emailBody') }}
          </h3>
          <span class="text-xs text-surface-400">{{ t('crmAutomationRuleEditor.clickAVariableToInsert') }}</span>
        </div>

        <!-- Template Variables Bar -->
        <div class="px-5 py-3 border-b border-surface-100 dark:border-[rgb(var(--color-border))] flex flex-wrap gap-1.5">
          <button
            v-for="v in templateVars" :key="v.key"
            @click="insertVarToBody(v.key)"
            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-500/30 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors"
          >
            <span class="material-symbols-rounded text-xs">{{ v.icon }}</span>
            {{ v.key }}
          </button>
        </div>

        <!-- Rich Text Editor -->
        <div class="p-5">
          <RichTextEditor
            v-model="emailBodyHtml"
            :placeholder="t('crmAutomationRuleEditor.writeYourEmailContentUse')"
            :compact="false"
            :showAI="false"
          />
        </div>
      </div>

    </div>
  </div>
</template>

