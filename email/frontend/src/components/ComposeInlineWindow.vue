<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useSettingsStore } from '@/stores/settings'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useMailingListsStore } from '@/addons/email-marketing/stores/mailingLists'
import { useEmailCampaignsStore } from '@/addons/email-marketing/stores/emailCampaigns'
import { useAddons } from '@/composables/useAddons'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useDriveStore } from '@/stores/drive'
import { useEmailTemplatesStore } from '@/stores/emailTemplates'
import { useRouter } from 'vue-router'
import RichTextEditor from './RichTextEditor.vue'
import UserAvatar from './shared/UserAvatar.vue'
import DriveFilePicker from './DriveFilePicker.vue'
import api from '@/services/api'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import * as windowService from '@/services/composeWindowService'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  win: { type: Object, required: true },
  offsetIndex: { type: Number, default: 0 },
  totalWindows: { type: Number, default: 1 },
})

const MINIMIZED_WIDTH = 320
const EXPANDED_WIDTH = 680
const GAP = 8
const BASE_RIGHT = 24

const windowRight = computed(() => {
  const reverseIdx = props.totalWindows - 1 - props.offsetIndex
  let offset = 0
  for (let i = 0; i < reverseIdx; i++) {
    const otherWin = windowService.getWindows().value[props.totalWindows - 1 - i]
    offset += (otherWin && otherWin.isMinimized ? MINIMIZED_WIDTH : EXPANDED_WIDTH) + GAP
  }
  return BASE_RIGHT + offset
})

const auth = useAuthStore()
const toast = useToastStore()
const settingsStore = useSettingsStore()
const colleaguesStore = useColleaguesStore()
const mailingListsStore = useMailingListsStore()
const emailCampaignsStore = useEmailCampaignsStore()
const { emailMarketingEnabled, teamEnabled, emailTrackingEnabled, aiAssistantEnabled } = useAddons()
const aiStore = useAIStore()
const drive = useDriveStore()
const emailTemplatesStore = useEmailTemplatesStore()
const router = useRouter()

// Ref to the body RichTextEditor instance (for inserting templates at cursor)
const bodyEditorRef = ref(null)

const BULK_SEND_THRESHOLD = 10

const showCc = ref(props.win.draft.cc.length > 0)
const showBcc = ref(props.win.draft.bcc.length > 0)
const recipientInput = ref({ to: '', cc: '', bcc: '' })
const fileInput = ref(null)
const dragOver = ref(false)
const dragCounter = ref(0)
const lastSaved = ref(null)
const uploadingFiles = ref([])
const saveIndicatorVisible = ref(false)
const isExpanded = ref(false)

const suggestions = ref([])
const showSuggestions = ref({ to: false, cc: false, bcc: false })
const selectedSuggestionIndex = ref(-1)
let searchTimeout = null

const teamGroups = computed(() => colleaguesStore.groups || [])
const mailingLists = computed(() => mailingListsStore.lists || [])

const showFromDropdown = ref(false)
const showRewriteOptions = ref(false)
const showDrivePicker = ref(false)
const showSchedulePicker = ref(false)
const customScheduleDate = ref('')
const customScheduleTime = ref('')
const scheduleBannerDismissed = ref(false)
const externalBannerDismissed = ref(false)
const showBulkSendConfirm = ref(false)

const userDomain = computed(() => {
  const email = auth.userEmail || ''
  return email.split('@')[1]?.toLowerCase() || ''
})

const orgDomains = computed(() => {
  const domains = new Set()
  if (userDomain.value) domains.add(userDomain.value)
  const colleagues = colleaguesStore.colleagues || []
  for (const c of colleagues) {
    const d = (c.email || '').split('@')[1]?.toLowerCase()
    if (d) domains.add(d)
  }
  return domains
})

const externalRecipients = computed(() => {
  if (!userDomain.value) return []
  const all = [...props.win.draft.to, ...props.win.draft.cc, ...props.win.draft.bcc]
  return all.filter(r => {
    const recipientDomain = (r.email || '').split('@')[1]?.toLowerCase()
    return recipientDomain && !orgDomains.value.has(recipientDomain)
  })
})
const bulkSendInfo = ref({ totalRecipients: 0, estimatedTime: '' })

const sendAddresses = computed(() => windowService.getSendAddresses())

watch(() => props.win.saving, (newVal, oldVal) => {
  if (oldVal === true && newVal === false) {
    lastSaved.value = new Date()
    saveIndicatorVisible.value = true
    setTimeout(() => { saveIndicatorVisible.value = false }, 3000)
  }
})

// Live "Draft saved Ns ago" status shown in the header
const nowTick = ref(Date.now())
let nowTickTimer = null
const relativeSavedLabel = computed(() => {
  if (!lastSaved.value) return ''
  const seconds = Math.max(0, Math.floor((nowTick.value - lastSaved.value.getTime()) / 1000))
  if (seconds < 5) return t('composeModal.draftSavedJustNow')
  let time
  if (seconds < 60) time = t('composeModal.secondsAgo', { n: seconds })
  else if (seconds < 3600) time = t('composeModal.minutesAgo', { n: Math.floor(seconds / 60) })
  else time = t('composeModal.hoursAgo', { n: Math.floor(seconds / 3600) })
  return t('composeModal.draftSavedAgo', { time })
})

// Templates dropdown
const showTemplatesMenu = ref(false)
const emailTemplates = computed(() => emailTemplatesStore.templates || [])
function toggleTemplatesMenu() {
  showTemplatesMenu.value = !showTemplatesMenu.value
  if (showTemplatesMenu.value && emailTemplatesStore.templates.length === 0) {
    emailTemplatesStore.fetchTemplates()
  }
}
function insertTemplate(tpl) {
  const html = tpl.html_content || tpl.body_html || ''
  if (!html) { showTemplatesMenu.value = false; return }
  const ed = bodyEditorRef.value?.editor
  if (ed) {
    ed.chain().focus().insertContent(html).run()
  } else {
    props.win.draft.body += html
  }
  windowService.markWindowAsEdited(props.win.id)
  showTemplatesMenu.value = false
}

// Collapsible signature (visual only - signature still ships in the body)
const signatureCollapsed = ref(false)
const hasSignature = computed(() => (props.win.draft.body || '').includes('data-signature'))
function toggleSignature() { signatureCollapsed.value = !signatureCollapsed.value }

async function handleSaveAsTemplate() {
  showTemplatesMenu.value = false
  const name = (prompt(t('composeModal.templateNamePrompt')) || '').trim()
  if (!name) return
  const result = await emailTemplatesStore.createTemplate({
    name,
    html_content: props.win.draft.body,
  })
  if (result.success) {
    toast.success(t('composeModal.templateSaved'))
  } else {
    toast.error(result.error || t('composeModal.failedToSaveTemplate'))
  }
}

watch(
  () => [...props.win.draft.to, ...props.win.draft.cc, ...props.win.draft.bcc],
  (recipients) => {
    if (recipients.length > 0) {
      const emails = recipients.map(r => r.email || r.address || r)
      clientTimeTracker.trackEmailCompose(emails, auth.userEmail, props.win.draft.subject)
    }
  },
  { deep: true }
)

const subjectLine = computed(() => {
  if (props.win.draft.subject) return props.win.draft.subject
  switch (props.win.mode) {
    case 'reply': return 'Reply'
    case 'replyAll': return 'Reply All'
    case 'forward': return 'Forward'
    case 'draft': return 'Draft'
    default: return t('composeModal.newMessage') || 'New Message'
  }
})

const modeTitle = computed(() => {
  switch (props.win.mode) {
    case 'reply': return 'Reply'
    case 'replyAll': return 'Reply All'
    case 'forward': return 'Forward'
    case 'draft': return 'Edit Draft'
    default: return 'New Message'
  }
})

const canSend = computed(() => {
  return props.win.draft.to.length > 0 && props.win.draft.subject.trim().length > 0
})

function addRecipient(type) {
  const input = recipientInput.value[type].trim()
  if (!input) return
  let email = input
  let name = ''
  const match = input.match(/^(.+?)\s*<(.+?)>$/)
  if (match) { name = match[1].trim(); email = match[2].trim() }
  if (!email.includes('@')) { toast.warning(t('composeModal.pleaseEnterAValidEmail')); return }
  windowService.addWindowRecipient(props.win.id, type, { email, name, display: name ? `${name} <${email}>` : email })
  windowService.markWindowAsEdited(props.win.id)
  recipientInput.value[type] = ''
}

function handleRecipientKeydown(e, type) {
  if (showSuggestions.value[type] && suggestions.value.length > 0) {
    if (e.key === 'ArrowDown') { e.preventDefault(); selectedSuggestionIndex.value = Math.min(selectedSuggestionIndex.value + 1, suggestions.value.length - 1); return }
    if (e.key === 'ArrowUp') { e.preventDefault(); selectedSuggestionIndex.value = Math.max(selectedSuggestionIndex.value - 1, -1); return }
    if (e.key === 'Enter' && selectedSuggestionIndex.value >= 0) { e.preventDefault(); selectSuggestion(suggestions.value[selectedSuggestionIndex.value], type); return }
    if (e.key === 'Escape') { e.preventDefault(); closeSuggestions(type); return }
  }
  if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') { e.preventDefault(); closeSuggestions(type); addRecipient(type) }
  else if (e.key === 'Backspace' && !recipientInput.value[type] && props.win.draft[type].length > 0) { props.win.draft[type].pop() }
}

function handleRecipientBlur(type) {
  setTimeout(() => { closeSuggestions(type); if (recipientInput.value[type].trim()) addRecipient(type) }, 200)
}

async function handleRecipientInput(type) {
  const query = recipientInput.value[type].trim()
  if (searchTimeout) clearTimeout(searchTimeout)
  if (query.length < 1) { closeSuggestions(type); return }
  searchTimeout = setTimeout(async () => {
    try {
      const response = await api.get('/contacts/search', { params: { q: query, limit: 8 } })
      if (response.data.success) {
        const addedEmails = props.win.draft[type].map(r => r.email.toLowerCase())
        const contacts = response.data.data.contacts.filter(c => !addedEmails.includes(c.email.toLowerCase())).map(c => ({ ...c, type: 'contact' }))
        const queryLower = query.toLowerCase()
        const matchingGroups = teamEnabled.value ? teamGroups.value.filter(g => g.name.toLowerCase().includes(queryLower)).map(g => ({ ...g, type: 'group' })) : []
        const matchingLists = emailMarketingEnabled.value ? mailingLists.value.filter(l => l.name.toLowerCase().includes(queryLower)).map(l => ({ ...l, type: 'mailing_list' })) : []
        suggestions.value = [...matchingGroups.slice(0, 2), ...matchingLists.slice(0, 2), ...contacts]
        if (suggestions.value.length > 0) { showSuggestions.value[type] = true; selectedSuggestionIndex.value = -1 }
        else { closeSuggestions(type) }
      }
    } catch (e) { /* silent */ }
  }, 150)
}

function selectSuggestion(item, type) {
  if (item.type === 'group') selectGroup(item, type)
  else if (item.type === 'mailing_list') selectMailingList(item, type)
  else {
    windowService.addWindowRecipient(props.win.id, type, { email: item.email, name: item.name, display: item.display || item.email })
    windowService.markWindowAsEdited(props.win.id)
    recipientInput.value[type] = ''
    closeSuggestions(type)
  }
}

async function selectGroup(group, type) {
  try {
    const members = await colleaguesStore.getGroupMembers(group.id)
    if (members && members.length > 0) {
      const addedEmails = props.win.draft[type].map(r => r.email.toLowerCase())
      let addedCount = 0
      for (const member of members) {
        if (!addedEmails.includes(member.email.toLowerCase())) {
          windowService.addWindowRecipient(props.win.id, type, { email: member.email, name: member.display_name || member.email.split('@')[0], display: member.display_name || member.email })
          addedCount++
        }
      }
      if (addedCount > 0) { windowService.markWindowAsEdited(props.win.id); toast.success(t('composeModal.addedRecipients', { count: addedCount, name: group.name })) }
      else { toast.info(t('composeModal.allGroupMembersAreAlready')) }
    } else { toast.warning(t('composeModal.thisGroupHasNoMembers')) }
  } catch (e) { toast.error(t('composeModal.failedToLoadGroupMembers')) }
  recipientInput.value[type] = ''
  closeSuggestions(type)
}

async function selectMailingList(list, type) {
  try {
    const emails = await mailingListsStore.getListEmails(list.id)
    if (emails && emails.length > 0) {
      const addedEmails = props.win.draft[type].map(r => r.email.toLowerCase())
      let addedCount = 0
      for (const contact of emails) {
        if (!addedEmails.includes(contact.email.toLowerCase())) {
          windowService.addWindowRecipient(props.win.id, type, { email: contact.email, name: contact.name || contact.email.split('@')[0], display: contact.name || contact.email })
          addedCount++
        }
      }
      if (addedCount > 0) { windowService.markWindowAsEdited(props.win.id); toast.success(t('composeModal.addedRecipients', { count: addedCount, name: list.name })) }
      else { toast.info(t('composeModal.allContactsAreAlreadyAdded')) }
    } else { toast.warning(t('composeModal.thisMailingListHasNo')) }
  } catch (e) { toast.error(t('composeModal.failedToLoadMailingList')) }
  recipientInput.value[type] = ''
  closeSuggestions(type)
}

function closeSuggestions(type) {
  showSuggestions.value[type] = false
  suggestions.value = []
  selectedSuggestionIndex.value = -1
}

async function saveSuggestion(item) {
  if (!item || !item.email || item.is_saved) return
  try {
    const res = await api.post('/contacts/save', { email: item.email, name: item.name || '' })
    if (res.data && res.data.success) {
      item.is_saved = true
      item.is_synced = true
      item.justSaved = true
      if (res.data.data && res.data.data.contact) item.contact_id = res.data.data.contact.id
      toast.success(t('composeModal.savedToContacts', { name: item.name || item.email }))
    }
  } catch (e) {
    console.error('Failed to save contact:', e)
    toast.error(t('composeModal.failedToSaveContact'))
  }
}

async function handleRecipientFocus(type) {
  if (!recipientInput.value[type].trim()) {
    if (emailMarketingEnabled.value && mailingListsStore.lists.length === 0) mailingListsStore.fetchLists()
    try {
      const response = await api.get('/contacts/recent', { params: { limit: 5 } })
      if (response.data.success) {
        const addedEmails = props.win.draft[type].map(r => r.email.toLowerCase())
        const contacts = response.data.data.contacts.filter(c => !addedEmails.includes(c.email.toLowerCase())).map(c => ({ ...c, type: 'contact' }))
        const groups = teamEnabled.value ? teamGroups.value.map(g => ({ ...g, type: 'group' })) : []
        const lists = emailMarketingEnabled.value ? mailingLists.value.map(l => ({ ...l, type: 'mailing_list' })) : []
        suggestions.value = [...groups, ...lists, ...contacts]
        if (suggestions.value.length > 0) { showSuggestions.value[type] = true; selectedSuggestionIndex.value = -1 }
      }
    } catch (e) { /* silent */ }
  }
}

async function handleSend() {
  if (props.win.campaignDraftId) return handleSaveCampaignDraft()
  if (props.win.draft.to.length === 0) { toast.warning(t('composeModal.pleaseAddAtLeastOne')); return }
  const totalRecipients = props.win.draft.to.length + props.win.draft.cc.length + props.win.draft.bcc.length
  const isCampaign = props.win.forceCampaign || (emailMarketingEnabled.value && totalRecipients > BULK_SEND_THRESHOLD)
  if (isCampaign) {
    const estimatedTime = emailCampaignsStore.calculateEstimatedTime(totalRecipients)
    bulkSendInfo.value = { totalRecipients, estimatedTime }
    showBulkSendConfirm.value = true
    return
  }
  const result = await windowService.sendFromWindow(props.win.id, settingsStore)
  if (result.success) {
    if (result.undoSend) {
      const totalDelay = result.delay
      const toastId = toast.success(t('composeModal.sendingInSeconds', { seconds: totalDelay }), {
        duration: (totalDelay + 1) * 1000,
        action: { label: t('composeModal.undo'), onClick: () => { clearInterval(countdownTimer); undoDelayedSend(result.schedule_id) } },
      })
      let remaining = totalDelay - 1
      const countdownTimer = setInterval(() => {
        if (remaining > 0) {
          toast.update(toastId, t('composeModal.sendingInSeconds', { seconds: remaining }))
          remaining--
        } else {
          clearInterval(countdownTimer)
          toast.update(toastId, t('composeModal.emailSent'))
        }
      }, 1000)
    } else {
      toast.success(t('composeModal.emailSent'))
    }
  } else {
    toast.error(result.error || t('composeModal.failedToSendEmail'))
  }
}

async function undoDelayedSend(scheduleId) {
  const { useComposeStore } = await import('@/stores/compose')
  const compose = useComposeStore()
  const result = await compose.openScheduledEmail(scheduleId, { undoSend: true })
  if (result.success) toast.info(t('composeModal.sendCancelled'))
  else if (result.tooLate) toast.warning(t('composeModal.undoTooLate'))
  else toast.error(result.error || t('composeModal.failedToSendEmail'))
}

async function handleSaveCampaignDraft() {
  const result = await emailCampaignsStore.updateDraft(props.win.campaignDraftId, {
    subject: props.win.draft.subject, body_html: props.win.draft.body, body_text: '', attachments: props.win.draft.attachments,
  })
  if (result.success) { toast.success('Draft saved'); await windowService.closeWindow(props.win.id, false); emailCampaignsStore.fetchCampaigns() }
  else { toast.error(result.error || 'Failed to save draft') }
}

async function handleBulkSend() {
  showBulkSendConfirm.value = false
  const emailData = {
    to: props.win.draft.to.map(r => ({ email: r.email, name: r.name || '' })),
    cc: props.win.draft.cc.map(r => ({ email: r.email, name: r.name || '' })),
    bcc: props.win.draft.bcc.map(r => ({ email: r.email, name: r.name || '' })),
    subject: props.win.draft.subject, body_html: props.win.draft.body, body_text: '', from_name: '',
    attachments: props.win.draft.attachments, in_reply_to: props.win.draft.in_reply_to,
    references: props.win.draft.references, track_read: emailTrackingEnabled.value,
  }
  const wasForceCampaign = props.win.forceCampaign
  const result = await emailCampaignsStore.queueBulkEmail(emailData)
  if (result.success) {
    toast.success(result.message || t('composeModal.campaignQueued', { count: result.totalRecipients }))
    await windowService.closeWindow(props.win.id, false)
    if (wasForceCampaign) emailCampaignsStore.fetchCampaigns()
    else { setTimeout(() => { if (confirm(t('composeModal.wouldYouLikeToView'))) router.push('/campaigns') }, 500) }
  } else { toast.error(result.error || t('composeModal.failedToQueueCampaign')) }
}

async function handleSaveDraft() {
  const success = await windowService.saveDraftForWindow(props.win.id)
  if (success) toast.success(t('composeModal.draftSaved'))
  else toast.error(t('composeModal.failedToSaveDraft'))
}

async function handleClose() {
  if (props.win.hasUserEdits) {
    const hasRecipients = props.win.draft.to.length > 0 || props.win.draft.cc.length > 0 || props.win.draft.bcc.length > 0
    const hasSubject = props.win.draft.subject.trim() !== ''
    const hasAttachments = props.win.draft.attachments.length > 0
    if (hasRecipients || hasSubject || hasAttachments) {
      toast.info(t('composeModal.draftSavedAutomatically'))
    }
  }
  await windowService.closeWindow(props.win.id)
}

function handleMinimize() { windowService.minimizeWindow(props.win.id) }
function handleMaximize() { windowService.maximizeWindow(props.win.id) }

function handleFileSelect(e) {
  const files = e.target.files
  if (files) uploadFiles(Array.from(files))
  e.target.value = ''
}

function handleDragEnter(e) { e.preventDefault(); dragCounter.value++; dragOver.value = true }
function handleDragLeave(e) { e.preventDefault(); dragCounter.value--; if (dragCounter.value === 0) dragOver.value = false }
function handleDrop(e) {
  e.preventDefault(); dragCounter.value = 0; dragOver.value = false
  const files = e.dataTransfer?.files
  if (files) uploadFiles(Array.from(files))
}

async function uploadFiles(files) {
  windowService.markWindowAsEdited(props.win.id)
  for (const file of files) {
    const thresholdMB = settingsStore.settings.large_attachment_threshold ?? 10
    const thresholdBytes = thresholdMB * 1024 * 1024
    const isLargeFile = thresholdBytes > 0 && file.size > thresholdBytes
    const uploadEntry = { id: Date.now() + Math.random(), name: file.name, size: file.size, isLargeFile, status: isLargeFile ? 'uploading_to_drive' : 'uploading', error: null }
    uploadingFiles.value.push(uploadEntry)
    const result = await windowService.uploadAttachmentToWindow(props.win.id, file, settingsStore)
    const idx = uploadingFiles.value.findIndex(f => f.id === uploadEntry.id)
    if (idx !== -1) {
      if (!result.success) {
        uploadingFiles.value[idx].status = 'error'
        uploadingFiles.value[idx].error = result.error || 'Upload failed'
        setTimeout(() => { uploadingFiles.value = uploadingFiles.value.filter(f => f.id !== uploadEntry.id) }, 3000)
      } else { uploadingFiles.value.splice(idx, 1) }
    }
  }
}

function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function toggleExpand() { isExpanded.value = !isExpanded.value }

function handleBodyUpdate(val) {
  props.win.draft.body = val
  windowService.markWindowAsEdited(props.win.id)
}

function handleSubjectInput() {
  windowService.markWindowAsEdited(props.win.id)
}

const fromDisplayLabel = computed(() => {
  const addr = props.win.fromAddress
  if (!addr) return auth.userEmail
  const label = addr.name ? `${addr.name} <${addr.email}>` : addr.email
  return addr.is_primary ? `${label} (${t('composeModal.primary')})` : label
})

function selectFromAddress(addr) {
  windowService.setWindowFromAddress(props.win.id, addr, settingsStore)
  showFromDropdown.value = false
}

const scheduleSuggestion = computed(() => {
  const now = new Date()
  const hour = now.getHours()
  const day = now.getDay()
  if (day >= 1 && day <= 5 && hour >= 9 && hour < 17) return null
  const target = new Date(now)
  if (day === 5 && hour >= 17) { target.setDate(target.getDate() + 3) }
  else if (day === 6) { target.setDate(target.getDate() + 2) }
  else if (day === 0) { target.setDate(target.getDate() + 1) }
  else if (hour >= 17) { target.setDate(target.getDate() + 1) }
  target.setHours(9, 0, 0, 0)
  return target
})

const scheduleSuggestionLabel = computed(() => {
  if (!scheduleSuggestion.value) return ''
  const d = scheduleSuggestion.value
  const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  return `${days[d.getDay()]}, ${months[d.getMonth()]} ${d.getDate()} at ${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`
})

function getTomorrowMorningLabel() { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' at 9:00 AM' }
function getTomorrowAfternoonLabel() { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(14, 0, 0, 0); return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' at 2:00 PM' }
function getNextMondayLabel() { const d = new Date(); d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(9, 0, 0, 0); return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' at 9:00 AM' }

async function doScheduleSend(date) {
  const isoString = date.toISOString().slice(0, 19).replace('T', ' ')
  const result = await windowService.scheduleSendFromWindow(props.win.id, isoString)
  if (result.success) {
    toast.success(t('composeModal.emailScheduledFor', { date: date.toLocaleString() }))
    showSchedulePicker.value = false
  } else {
    toast.error(result.error || t('composeModal.failedToScheduleEmail'))
  }
}

function handleScheduleSend() { if (scheduleSuggestion.value) doScheduleSend(scheduleSuggestion.value) }

function handleCustomSchedule() {
  if (!customScheduleDate.value || !customScheduleTime.value) { toast.warning(t('composeModal.pleaseSelectBothDateAnd')); return }
  const dateTime = new Date(`${customScheduleDate.value}T${customScheduleTime.value}`)
  if (isNaN(dateTime.getTime()) || dateTime <= new Date()) { toast.warning(t('composeModal.pleaseSelectAFutureDate')); return }
  doScheduleSend(dateTime)
}

function scheduleQuick(preset) {
  let date
  if (preset === 'tomorrow_morning') { date = new Date(); date.setDate(date.getDate() + 1); date.setHours(9, 0, 0, 0) }
  else if (preset === 'tomorrow_afternoon') { date = new Date(); date.setDate(date.getDate() + 1); date.setHours(14, 0, 0, 0) }
  else if (preset === 'next_monday') { date = new Date(); date.setDate(date.getDate() + ((8 - date.getDay()) % 7 || 7)); date.setHours(9, 0, 0, 0) }
  if (date) { doScheduleSend(date); showSchedulePicker.value = false }
}

function stripHtml(html) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  return doc.body.textContent || ''
}

async function rewriteWithAI(style = null) {
  const bodyText = stripHtml(props.win.draft.body)
  if (!bodyText.trim()) { toast.warning(t('composeModal.pleaseWriteSomeTextFirst')); return }
  const rewriteStyle = style || aiStore.writingStyle
  const result = await aiStore.rewrite(bodyText, rewriteStyle)
  if (result.success) {
    props.win.draft.body = `<p>${result.text.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>')}</p>`
    windowService.markWindowAsEdited(props.win.id)
    toast.success(t('composeModal.textRewritten'))
    showRewriteOptions.value = false
  } else {
    toast.error(result.error || t('composeModal.failedToRewriteText'))
  }
}

async function handleDriveFileSelected(files) {
  showDrivePicker.value = false
  const fileList = Array.isArray(files) ? files : [files]
  if (fileList.length === 0) return
  windowService.markWindowAsEdited(props.win.id)
  for (const file of fileList) {
    let shareUrl = null
    if (file.share_token) {
      const baseUrl = window.location.origin
      const fnParam = file.original_name ? `?fn=${encodeURIComponent(file.original_name)}` : ''
      shareUrl = `${baseUrl}/api/drive/share/${file.share_token}${fnParam}`
    } else {
      const result = await drive.shareForEmail(file.id, 168)
      if (result.success) { shareUrl = result.url }
      else { toast.error(t('composeModal.failedToShareFile', { name: file.original_name })); continue }
    }
    props.win.draft.attachments.push({
      name: file.original_name,
      size: file.size,
      driveLink: true,
      url: shareUrl,
      fileId: file.id,
      fromDrive: true,
      willExpire: true,
    })
  }
  toast.success(t('composeModal.attachedFilesFromDrive', { count: fileList.length }))
}

const fromDropdownRef = ref(null)
function handleGlobalClick(e) {
  if (showFromDropdown.value && fromDropdownRef.value && !fromDropdownRef.value.contains(e.target)) {
    showFromDropdown.value = false
  }
  if (showRewriteOptions.value) showRewriteOptions.value = false
}
onMounted(() => {
  document.addEventListener('click', handleGlobalClick, true)
  nowTickTimer = setInterval(() => { nowTick.value = Date.now() }, 1000)
})
onUnmounted(() => {
  document.removeEventListener('click', handleGlobalClick, true)
  if (nowTickTimer) clearInterval(nowTickTimer)
})
</script>

<template>
  <!-- Minimized bar -->
  <Transition name="compose-slide">
    <div
      v-if="win.isMinimized"
      class="fixed bottom-0 z-[9995] w-[320px] cursor-pointer select-none"
      :style="{ right: windowRight + 'px' }"
      @click="handleMaximize"
    >
      <div class="flex items-center gap-2 px-4 py-2.5 bg-surface-100 dark:bg-surface-800 text-surface-800 dark:text-white rounded-t-xl shadow-2xl border border-b-0 border-surface-200 dark:border-surface-700">
        <span class="material-symbols-rounded text-lg text-primary-500">edit</span>
        <span class="flex-1 text-sm font-medium truncate">{{ subjectLine }}</span>
        <button @click.stop="handleMaximize" class="p-1 hover:bg-surface-200 dark:hover:bg-white/10 rounded transition-colors" title="Expand">
          <span class="material-symbols-rounded text-lg text-surface-500 dark:text-surface-300">open_in_full</span>
        </button>
        <button @click.stop="handleClose" class="p-1 hover:bg-surface-200 dark:hover:bg-white/10 rounded transition-colors" title="Close">
          <span class="material-symbols-rounded text-lg text-surface-500 dark:text-surface-300">close</span>
        </button>
      </div>
    </div>
  </Transition>

  <!-- Expanded compose window -->
  <Transition name="compose-slide">
    <div
      v-if="!win.isMinimized"
      :class="[
        'flex flex-col bg-white dark:bg-surface-900 shadow-2xl border border-surface-200 dark:border-surface-700 overflow-hidden',
        isExpanded
          ? 'fixed inset-4 rounded-2xl z-[9996]'
          : 'fixed bottom-0 z-[9995] w-[680px] max-h-[calc(100vh-80px)] rounded-t-xl'
      ]"
      :style="isExpanded ? {} : { right: windowRight + 'px' }"
    >
      <!-- Header bar -->
      <div class="flex items-center gap-2 px-3 py-2 bg-surface-100 dark:bg-surface-800 text-surface-800 dark:text-white shrink-0 cursor-default rounded-t-xl border-b border-surface-200 dark:border-surface-700">
        <div class="w-7 h-7 rounded-lg bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 flex items-center justify-center shrink-0">
          <span class="material-symbols-rounded text-base">send</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium truncate leading-tight">{{ modeTitle }}</div>
          <div v-if="relativeSavedLabel" class="flex items-center gap-1 text-[11px] text-surface-500 leading-tight">
            <span class="w-1.5 h-1.5 rounded-full bg-green-500 shrink-0"></span>
            {{ relativeSavedLabel }}
          </div>
        </div>

        <button @click="toggleExpand" class="p-1 hover:bg-surface-200 dark:hover:bg-white/10 rounded transition-colors" :title="isExpanded ? 'Restore' : 'Expand'">
          <span class="material-symbols-rounded text-lg text-surface-500 dark:text-surface-300">{{ isExpanded ? 'close_fullscreen' : 'open_in_full' }}</span>
        </button>
        <button @click="handleMinimize" class="p-1 hover:bg-surface-200 dark:hover:bg-white/10 rounded transition-colors" title="Minimize">
          <span class="material-symbols-rounded text-lg text-surface-500 dark:text-surface-300">minimize</span>
        </button>
        <button @click="handleClose" class="p-1 hover:bg-surface-200 dark:hover:bg-white/10 rounded transition-colors" title="Close">
          <span class="material-symbols-rounded text-lg text-surface-500 dark:text-surface-300">close</span>
        </button>
      </div>

      <!-- Body -->
      <div
        class="flex-1 flex flex-col min-h-0 overflow-y-auto relative"
        @dragover.prevent
        @dragenter="handleDragEnter"
        @dragleave="handleDragLeave"
        @drop="handleDrop"
      >
        <!-- Smart Banners -->
        <div
          v-if="(scheduleSuggestion && !scheduleBannerDismissed && win.draft.to.length > 0) || (externalRecipients.length > 0 && !externalBannerDismissed)"
          class="px-3 pt-2 grid gap-2"
          :class="(scheduleSuggestion && !scheduleBannerDismissed && win.draft.to.length > 0) && (externalRecipients.length > 0 && !externalBannerDismissed) ? 'grid-cols-2' : 'grid-cols-1'"
        >
          <div
            v-if="scheduleSuggestion && !scheduleBannerDismissed && win.draft.to.length > 0"
            class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/20"
          >
            <span class="material-symbols-rounded text-base text-primary-500 shrink-0">schedule_send</span>
            <p class="flex-1 text-xs text-surface-600 dark:text-surface-400 truncate">
              <span class="font-medium text-surface-800 dark:text-surface-200">{{ scheduleSuggestionLabel }}</span>
            </p>
            <button @click="handleScheduleSend" class="px-2.5 py-0.5 text-xs font-medium rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors whitespace-nowrap">
              {{ $t('composeModal.schedule') }}
            </button>
            <button @click="scheduleBannerDismissed = true" class="p-0.5 rounded text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors shrink-0">
              <span class="material-symbols-rounded text-base">close</span>
            </button>
          </div>

          <div
            v-if="externalRecipients.length > 0 && !externalBannerDismissed"
            class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20"
          >
            <span class="material-symbols-rounded text-base text-amber-500 shrink-0">info</span>
            <p class="flex-1 text-xs text-surface-600 dark:text-surface-400 truncate">
              <span v-if="externalRecipients.length === 1">
                {{ $t('composeModal.external') }} <span class="font-medium text-surface-800 dark:text-surface-200">{{ externalRecipients[0].name || externalRecipients[0].email.split('@')[0] }}</span>
                <span class="text-surface-400">({{ externalRecipients[0].email.split('@')[1] }})</span>
              </span>
              <span v-else>
                {{ $t('composeModal.externalRecipients', { count: externalRecipients.length }) }}
              </span>
            </p>
            <button @click="externalBannerDismissed = true" class="p-0.5 rounded text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors shrink-0">
              <span class="material-symbols-rounded text-base">close</span>
            </button>
          </div>
        </div>

        <div class="px-3 py-2 space-y-1.5 shrink-0">
          <!-- From -->
          <div class="flex items-center gap-2 text-sm">
            <label class="w-10 text-surface-500 text-right shrink-0">{{ $t('composeModal.from') }}</label>
            <div v-if="sendAddresses.length <= 1" class="flex-1 px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-950 text-surface-600 dark:text-surface-300 truncate">{{ auth.userEmail }}</div>
            <div v-else ref="fromDropdownRef" class="flex-1 relative">
              <button
                @click="showFromDropdown = !showFromDropdown"
                class="w-full flex items-center gap-2 px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-950 hover:bg-surface-100 dark:hover:bg-surface-900 transition-colors text-left"
              >
                <span class="flex-1 truncate text-surface-800 dark:text-surface-200">
                  {{ fromDisplayLabel }}
                </span>
                <span class="material-symbols-rounded text-sm text-surface-400 shrink-0 transition-transform" :class="showFromDropdown ? 'rotate-180' : ''">expand_more</span>
              </button>
              <div
                v-if="showFromDropdown"
                class="absolute z-50 mt-1 w-full bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 max-h-48 overflow-y-auto"
              >
                <button
                  v-for="addr in sendAddresses"
                  :key="addr.email"
                  @mousedown.prevent="selectFromAddress(addr)"
                  :class="[
                    'w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors',
                    (win.fromAddress?.email || auth.userEmail) === addr.email ? 'bg-primary-50 dark:bg-primary-900/30' : ''
                  ]"
                >
                  <div class="font-medium text-surface-900 dark:text-surface-100">
                    {{ addr.name ? `${addr.name} <${addr.email}>` : addr.email }}
                  </div>
                  <div v-if="addr.is_primary" class="text-xs text-primary-500">{{ $t('composeModal.primary') }}</div>
                </button>
              </div>
            </div>
          </div>

          <!-- To -->
          <div class="flex items-start gap-2 text-sm">
            <label class="w-10 text-surface-500 text-right pt-1.5 shrink-0">{{ $t('composeModal.to') }}</label>
            <div class="flex-1 relative">
              <div class="flex flex-wrap gap-1.5 p-1.5 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-950 focus-within:ring-2 focus-within:ring-primary-500/40 focus-within:border-primary-500">
                <span
                  v-for="(r, i) in win.draft.to"
                  :key="i"
                  class="inline-flex items-center gap-1 pl-0.5 pr-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 text-xs"
                >
                  <UserAvatar :email="r.email" :name="r.name" size="xs" />
                  <span class="truncate max-w-[160px]">{{ r.name || r.email }}</span>
                  <button @click="windowService.removeWindowRecipient(win.id, 'to', r.email)" class="hover:text-primary-900 shrink-0"><span class="material-symbols-rounded text-xs">close</span></button>
                </span>
                <input
                  v-model="recipientInput.to"
                  @keydown="handleRecipientKeydown($event, 'to')"
                  @input="handleRecipientInput('to')"
                  @focus="handleRecipientFocus('to')"
                  @blur="handleRecipientBlur('to')"
                  type="text"
                  class="flex-1 min-w-[100px] bg-transparent outline-none text-sm py-0.5"
                  :placeholder="win.draft.to.length === 0 ? $t('composeModal.addRecipient') : ''"
                  autocomplete="off"
                />
              </div>
              <!-- Suggestions dropdown -->
              <div
                v-if="showSuggestions.to && suggestions.length > 0"
                class="absolute z-50 mt-1 w-full bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 max-h-48 overflow-y-auto"
              >
                <button
                  v-for="(item, i) in suggestions"
                  :key="item.type === 'group' ? `group-${item.id}` : item.type === 'mailing_list' ? `list-${item.id}` : item.email"
                  @mousedown.prevent="selectSuggestion(item, 'to')"
                  :class="['w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors', selectedSuggestionIndex === i ? 'bg-primary-50 dark:bg-primary-900/30' : '']"
                >
                  <template v-if="item.type === 'group'">
                    <div class="flex items-center gap-2">
                      <span class="w-6 h-6 rounded-full flex items-center justify-center text-white text-xs" :style="{ backgroundColor: item.color || '#6366f1' }">
                        <span class="material-symbols-rounded text-xs">group</span>
                      </span>
                      <div class="flex-1 min-w-0">
                        <div class="font-medium text-surface-900 dark:text-surface-100">{{ item.name }} <span class="text-xs text-primary-500 bg-primary-50 dark:bg-primary-900/30 px-1 py-0.5 rounded">{{ $t('composeModal.team') }}</span></div>
                        <div class="text-xs text-surface-500">{{ $t('composeModal.members', { count: item.member_count || 0 }) }}</div>
                      </div>
                    </div>
                  </template>
                  <template v-else-if="item.type === 'mailing_list'">
                    <div class="flex items-center gap-2">
                      <span class="w-6 h-6 rounded-full flex items-center justify-center text-white text-xs" :style="{ backgroundColor: item.color || '#6366f1' }">
                        <span class="material-symbols-rounded text-xs">{{ item.icon || 'mail' }}</span>
                      </span>
                      <div class="flex-1 min-w-0">
                        <div class="font-medium text-surface-900 dark:text-surface-100">{{ item.name }} <span class="text-xs text-orange-500 bg-orange-50 dark:bg-orange-900/30 px-1 py-0.5 rounded">{{ $t('composeModal.list') }}</span></div>
                        <div class="text-xs text-surface-500">{{ $t('composeModal.contacts', { count: item.contact_count || 0 }) }}</div>
                      </div>
                    </div>
                  </template>
                  <template v-else>
                    <div class="flex items-center gap-2">
                      <div class="flex-1 min-w-0">
                        <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                          <span class="truncate">{{ item.name || item.email }}</span>
                          <span v-if="item.is_synced" class="material-symbols-rounded text-[15px] text-primary-500" :title="$t('composeModal.savedContact')">bookmark</span>
                          <span v-else-if="item.is_saved" class="material-symbols-rounded text-[15px] text-surface-400" :title="$t('composeModal.otherContact')">bookmark_border</span>
                          <span v-if="item.is_client" class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.client') }}</span>
                        </div>
                        <div v-if="item.name" class="text-xs text-surface-500 truncate">{{ item.email }}</div>
                      </div>
                      <span v-if="!item.is_saved" role="button" tabindex="-1" @mousedown.prevent.stop="saveSuggestion(item)" class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full text-surface-400 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors" :title="$t('composeModal.saveToContacts')">
                        <span class="material-symbols-rounded text-[18px]">person_add</span>
                      </span>
                      <span v-else-if="item.justSaved" class="shrink-0 material-symbols-rounded text-[18px] text-emerald-500">check_circle</span>
                    </div>
                  </template>
                </button>
              </div>
            </div>
            <div class="flex items-center gap-2 pt-1.5 shrink-0">
              <button v-if="!showCc" @click="showCc = true" class="inline-flex items-center gap-1 text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors">
                <span class="material-symbols-rounded text-sm">person_add</span>{{ $t('composeModal.cc') }}
              </button>
              <button v-if="!showBcc" @click="showBcc = true" class="inline-flex items-center gap-1 text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors">
                <span class="material-symbols-rounded text-sm">person_add</span>{{ $t('composeModal.bcc') }}
              </button>
            </div>
          </div>

          <!-- Cc -->
          <div v-if="showCc" class="flex items-start gap-2 text-sm">
            <label class="w-10 text-surface-500 text-right pt-1.5 shrink-0">Cc</label>
            <div class="flex-1 relative">
              <div class="flex flex-wrap gap-1.5 p-1.5 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-950 focus-within:ring-2 focus-within:ring-primary-500/40 focus-within:border-primary-500">
                <span v-for="(r, i) in win.draft.cc" :key="i" class="inline-flex items-center gap-1 pl-0.5 pr-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-700 dark:text-surface-200 text-xs">
                  <UserAvatar :email="r.email" :name="r.name" size="xs" />
                  <span class="truncate max-w-[160px]">{{ r.name || r.email }}</span>
                  <button @click="windowService.removeWindowRecipient(win.id, 'cc', r.email)" class="shrink-0"><span class="material-symbols-rounded text-xs">close</span></button>
                </span>
                <input
                  v-model="recipientInput.cc"
                  @keydown="handleRecipientKeydown($event, 'cc')"
                  @input="handleRecipientInput('cc')"
                  @focus="handleRecipientFocus('cc')"
                  @blur="handleRecipientBlur('cc')"
                  type="text"
                  class="flex-1 min-w-[100px] bg-transparent outline-none text-sm py-0.5"
                  :placeholder="$t('composeModal.addCc')"
                  autocomplete="off"
                />
              </div>
              <div v-if="showSuggestions.cc && suggestions.length > 0" class="absolute z-50 mt-1 w-full bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 max-h-48 overflow-y-auto">
                <button
                  v-for="(item, i) in suggestions"
                  :key="item.email || item.id"
                  @mousedown.prevent="selectSuggestion(item, 'cc')"
                  :class="['w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors', selectedSuggestionIndex === i ? 'bg-primary-50 dark:bg-primary-900/30' : '']"
                >
                  <div class="flex items-center gap-2">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                        <span class="truncate">{{ item.name || item.email }}</span>
                        <span v-if="item.is_synced" class="material-symbols-rounded text-[15px] text-primary-500" :title="$t('composeModal.savedContact')">bookmark</span>
                        <span v-else-if="item.is_saved" class="material-symbols-rounded text-[15px] text-surface-400" :title="$t('composeModal.otherContact')">bookmark_border</span>
                        <span v-if="item.is_client" class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.client') }}</span>
                      </div>
                      <div v-if="item.name && item.email" class="text-xs text-surface-500 truncate">{{ item.email }}</div>
                    </div>
                    <span v-if="!item.is_saved" role="button" tabindex="-1" @mousedown.prevent.stop="saveSuggestion(item)" class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full text-surface-400 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors" :title="$t('composeModal.saveToContacts')">
                      <span class="material-symbols-rounded text-[18px]">person_add</span>
                    </span>
                    <span v-else-if="item.justSaved" class="shrink-0 material-symbols-rounded text-[18px] text-emerald-500">check_circle</span>
                  </div>
                </button>
              </div>
            </div>
          </div>

          <!-- Bcc -->
          <div v-if="showBcc" class="flex items-start gap-2 text-sm">
            <label class="w-10 text-surface-500 text-right pt-1.5 shrink-0">Bcc</label>
            <div class="flex-1 relative">
              <div class="flex flex-wrap gap-1.5 p-1.5 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-950 focus-within:ring-2 focus-within:ring-primary-500/40 focus-within:border-primary-500">
                <span v-for="(r, i) in win.draft.bcc" :key="i" class="inline-flex items-center gap-1 pl-0.5 pr-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-700 dark:text-surface-200 text-xs">
                  <UserAvatar :email="r.email" :name="r.name" size="xs" />
                  <span class="truncate max-w-[160px]">{{ r.name || r.email }}</span>
                  <button @click="windowService.removeWindowRecipient(win.id, 'bcc', r.email)" class="shrink-0"><span class="material-symbols-rounded text-xs">close</span></button>
                </span>
                <input
                  v-model="recipientInput.bcc"
                  @keydown="handleRecipientKeydown($event, 'bcc')"
                  @input="handleRecipientInput('bcc')"
                  @focus="handleRecipientFocus('bcc')"
                  @blur="handleRecipientBlur('bcc')"
                  type="text"
                  class="flex-1 min-w-[100px] bg-transparent outline-none text-sm py-0.5"
                  :placeholder="$t('composeModal.addBcc')"
                  autocomplete="off"
                />
              </div>
              <div v-if="showSuggestions.bcc && suggestions.length > 0" class="absolute z-50 mt-1 w-full bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 max-h-48 overflow-y-auto">
                <button
                  v-for="(item, i) in suggestions"
                  :key="item.email || item.id"
                  @mousedown.prevent="selectSuggestion(item, 'bcc')"
                  :class="['w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors', selectedSuggestionIndex === i ? 'bg-primary-50 dark:bg-primary-900/30' : '']"
                >
                  <div class="flex items-center gap-2">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                        <span class="truncate">{{ item.name || item.email }}</span>
                        <span v-if="item.is_synced" class="material-symbols-rounded text-[15px] text-primary-500" :title="$t('composeModal.savedContact')">bookmark</span>
                        <span v-else-if="item.is_saved" class="material-symbols-rounded text-[15px] text-surface-400" :title="$t('composeModal.otherContact')">bookmark_border</span>
                        <span v-if="item.is_client" class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.client') }}</span>
                      </div>
                      <div v-if="item.name && item.email" class="text-xs text-surface-500 truncate">{{ item.email }}</div>
                    </div>
                    <span v-if="!item.is_saved" role="button" tabindex="-1" @mousedown.prevent.stop="saveSuggestion(item)" class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full text-surface-400 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors" :title="$t('composeModal.saveToContacts')">
                      <span class="material-symbols-rounded text-[18px]">person_add</span>
                    </span>
                    <span v-else-if="item.justSaved" class="shrink-0 material-symbols-rounded text-[18px] text-emerald-500">check_circle</span>
                  </div>
                </button>
              </div>
            </div>
          </div>

          <!-- Subject -->
          <div class="flex items-center gap-2 text-sm">
            <label class="w-10 text-surface-500 text-right shrink-0">{{ $t('composeModal.subject') }}</label>
            <input
              v-model="win.draft.subject"
              @input="handleSubjectInput"
              type="text"
              class="flex-1 px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-950 text-sm text-surface-800 dark:text-surface-200 outline-none focus:ring-2 focus:ring-primary-500/40 focus:border-primary-500"
              :placeholder="$t('composeModal.addSubject')"
            />
          </div>
        </div>

        <!-- Divider -->
        <div class="border-t border-surface-200 dark:border-surface-700"></div>

        <!-- Rich text editor (Templates floats over the top-right, no extra row) -->
        <div :class="['relative flex-1 min-h-[180px]', { 'compose-signature-collapsed': signatureCollapsed }]">
          <div class="absolute top-1.5 right-2 z-30">
            <button
              @click="toggleTemplatesMenu"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-surface-200 dark:border-surface-700 bg-white/90 dark:bg-surface-800/90 backdrop-blur-sm shadow-sm text-xs text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">description</span>
              {{ $t('composeModal.templates') }}
              <span class="material-symbols-rounded text-sm transition-transform" :class="showTemplatesMenu ? 'rotate-180' : ''">arrow_drop_down</span>
            </button>
            <div
              v-if="showTemplatesMenu"
              class="absolute right-0 mt-1 w-60 max-h-64 overflow-y-auto bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-[60]"
            >
              <button
                @click.stop="handleSaveAsTemplate"
                class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-base text-surface-400">bookmark_add</span>
                {{ $t('composeModal.saveAsTemplate') }}
              </button>
              <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
              <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase tracking-wide">{{ $t('composeModal.templates') }}</p>
              <div v-if="emailTemplatesStore.loading" class="px-3 py-3 text-sm text-surface-500 flex items-center gap-2">
                <span class="material-symbols-rounded animate-spin text-base">progress_activity</span>
                {{ $t('composeModal.loadingTemplates') }}
              </div>
              <template v-else>
                <button
                  v-for="tpl in emailTemplates"
                  :key="tpl.id"
                  @click="insertTemplate(tpl)"
                  class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-base text-surface-400">description</span>
                  <span class="flex-1 truncate">{{ tpl.name }}</span>
                </button>
                <p v-if="emailTemplates.length === 0" class="px-3 py-3 text-sm text-surface-400">{{ $t('composeModal.noTemplates') }}</p>
              </template>
            </div>
          </div>
          <div v-if="showTemplatesMenu" class="fixed inset-0 z-20" @click="showTemplatesMenu = false"></div>
          <RichTextEditor
            ref="bodyEditorRef"
            :modelValue="win.draft.body"
            @update:modelValue="handleBodyUpdate"
            :aiEnabled="aiAssistantEnabled"
            :toolbarBottom="true"
            :minimalToolbar="true"
            :placeholder="$t('composeModal.startWriting')"
          />
        </div>

        <!-- Signature show/hide pill -->
        <div v-if="hasSignature" class="px-3 pt-2">
          <button
            @click="toggleSignature"
            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-sm transition-transform" :class="signatureCollapsed ? '' : 'rotate-180'">expand_more</span>
            {{ signatureCollapsed ? $t('composeModal.showSignature') : $t('composeModal.hideSignature') }}
          </button>
        </div>

        <!-- Attachments -->
        <div v-if="win.draft.attachments.length > 0 || uploadingFiles.length > 0" class="px-3 py-2 border-t border-surface-200 dark:border-surface-700 overflow-y-auto max-h-[360px]">
          <div class="flex flex-wrap gap-1.5">
            <div v-for="upload in uploadingFiles" :key="upload.id"
              :class="['inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs', upload.status === 'error' ? 'bg-red-50 dark:bg-red-500/20' : 'bg-surface-100 dark:bg-surface-800']"
            >
              <span v-if="upload.status === 'error'" class="material-symbols-rounded text-sm text-red-500">error</span>
              <span v-else class="material-symbols-rounded text-sm animate-spin text-surface-500">progress_activity</span>
              <span>{{ upload.name }}</span>
              <span class="text-surface-400">{{ formatSize(upload.size) }}</span>
            </div>
            <div v-for="(att, i) in win.draft.attachments" :key="'att-' + i"
              :class="['inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs', att.driveLink ? 'bg-primary-50 dark:bg-primary-500/20' : 'bg-surface-100 dark:bg-surface-800']"
            >
              <span :class="['material-symbols-rounded text-sm', att.driveLink ? 'text-primary-500' : 'text-surface-500']">{{ att.driveLink ? 'cloud' : 'attachment' }}</span>
              <span>{{ att.name || att.filename || 'attachment' }}</span>
              <span class="text-surface-400">{{ formatSize(att.size) }}</span>
              <button @click="windowService.removeWindowAttachment(win.id, i)" class="text-surface-400 hover:text-red-500"><span class="material-symbols-rounded text-xs">close</span></button>
            </div>
          </div>
        </div>

        <!-- Drag overlay -->
        <div v-if="dragOver" class="absolute inset-0 flex items-center justify-center bg-primary-500/10 border-2 border-dashed border-primary-500 rounded-2xl z-10">
          <div class="text-center">
            <span class="material-symbols-rounded text-4xl text-primary-500">upload_file</span>
            <p class="text-primary-600 dark:text-primary-400 mt-1 text-sm">{{ $t('composeModal.dropFilesToAttach') }}</p>
          </div>
        </div>
      </div>

      <!-- Footer toolbar -->
      <div class="compose-inline-footer-actions flex items-center gap-1 px-3 py-2 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-950 shrink-0">
        <!-- Attach -->
        <button @click="fileInput?.click()" class="btn-ghost text-sm shrink-0 whitespace-nowrap" :title="$t('composeModal.attach')">
          <span class="material-symbols-rounded text-base">attachment</span>
          <span class="hidden lg:inline">{{ $t('composeModal.attach') }}</span>
        </button>
        <input ref="fileInput" type="file" multiple class="hidden" @change="handleFileSelect" />

        <!-- From Drive -->
        <button @click="showDrivePicker = true" class="btn-ghost text-sm shrink-0 whitespace-nowrap" :title="$t('composeModal.fromDriveBtn')">
          <span class="material-symbols-rounded text-base">cloud</span>
          <span class="hidden lg:inline">{{ $t('composeModal.drive') }}</span>
        </button>

        <!-- Schedule send -->
        <button
          @click.stop="showSchedulePicker = !showSchedulePicker"
          :disabled="!canSend"
          class="btn-ghost text-sm shrink-0 whitespace-nowrap"
          :title="$t('composeModal.scheduleSend')"
        >
          <span class="material-symbols-rounded text-base">schedule</span>
          <span class="hidden lg:inline">{{ $t('composeModal.schedule') }}</span>
        </button>

        <!-- Mark as Important toggle -->
        <button
          @click="win.draft.important = !win.draft.important; windowService.markWindowAsEdited(win.id)"
          :class="['btn-ghost text-sm shrink-0 whitespace-nowrap', win.draft.important ? 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10' : '']"
          :aria-pressed="win.draft.important"
          :title="$t('composeModal.importantHint')"
        >
          <span class="material-symbols-rounded text-base" :style="win.draft.important ? 'font-variation-settings: \'FILL\' 1' : ''">flag</span>
          <span class="hidden lg:inline">{{ $t('composeModal.important') }}</span>
        </button>

        <div class="flex-1 min-w-[8px]"></div>

        <!-- Save Draft -->
        <button @click="handleSaveDraft" class="btn-secondary text-sm py-1.5 px-3 shrink-0 whitespace-nowrap" :disabled="win.saving">
          <span v-if="win.saving" class="material-symbols-rounded animate-spin text-base">progress_activity</span>
          <span v-else class="material-symbols-rounded text-base">save</span>
          {{ $t('composeModal.saveDraft') }}
        </button>

        <!-- Send split button with schedule dropdown -->
        <div class="relative inline-flex shrink-0 whitespace-nowrap">
          <button @click="handleSend" class="btn-primary text-sm py-1.5 px-4 !rounded-r-none" :disabled="win.sending || !canSend">
            <span v-if="win.sending" class="material-symbols-rounded animate-spin text-base">progress_activity</span>
            <span v-else class="material-symbols-rounded text-base">{{ win.campaignDraftId ? 'save' : 'send' }}</span>
            {{ win.campaignDraftId ? 'Save' : $t('composeModal.send') }}
          </button>
          <button
            @click.stop="showSchedulePicker = !showSchedulePicker"
            class="btn-primary text-sm py-1.5 px-1.5 !rounded-l-none border-l border-white/25"
            :disabled="win.sending || !canSend"
            :title="$t('composeModal.scheduleSend')"
          >
            <span class="material-symbols-rounded text-lg">arrow_drop_down</span>
          </button>

          <!-- Schedule picker dropdown -->
          <div
            v-if="showSchedulePicker"
            class="absolute bottom-full right-0 mb-2 w-72 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 z-50"
          >
            <p class="px-4 py-1.5 text-xs font-medium text-surface-500 uppercase tracking-wide">{{ $t('composeModal.scheduleSend') }}</p>

            <button
              v-if="scheduleSuggestion"
              @click="handleScheduleSend(); showSchedulePicker = false"
              class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg text-primary-500">wb_sunny</span>
              <div>
                <div class="text-surface-900 dark:text-surface-100">{{ $t('composeModal.workHours') }}</div>
                <div class="text-xs text-surface-500">{{ scheduleSuggestionLabel }}</div>
              </div>
            </button>

            <button @click="scheduleQuick('tomorrow_morning')" class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-3">
              <span class="material-symbols-rounded text-lg text-amber-500">light_mode</span>
              <div>
                <div class="text-surface-900 dark:text-surface-100">{{ $t('composeModal.tomorrowMorning') }}</div>
                <div class="text-xs text-surface-500">{{ getTomorrowMorningLabel() }}</div>
              </div>
            </button>

            <button @click="scheduleQuick('tomorrow_afternoon')" class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-3">
              <span class="material-symbols-rounded text-lg text-orange-500">wb_twilight</span>
              <div>
                <div class="text-surface-900 dark:text-surface-100">{{ $t('composeModal.tomorrowAfternoon') }}</div>
                <div class="text-xs text-surface-500">{{ getTomorrowAfternoonLabel() }}</div>
              </div>
            </button>

            <button @click="scheduleQuick('next_monday')" class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-3">
              <span class="material-symbols-rounded text-lg text-blue-500">next_week</span>
              <div>
                <div class="text-surface-900 dark:text-surface-100">{{ $t('composeModal.nextMonday') }}</div>
                <div class="text-xs text-surface-500">{{ getNextMondayLabel() }}</div>
              </div>
            </button>

            <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

            <div class="px-4 py-2">
              <p class="text-xs font-medium text-surface-500 mb-2">{{ $t('composeModal.customDateTime') }}</p>
              <div class="flex gap-2 mb-2">
                <input v-model="customScheduleDate" type="date" :min="new Date().toISOString().split('T')[0]" class="input text-sm flex-1" />
                <input v-model="customScheduleTime" type="time" class="input text-sm w-24" />
              </div>
              <button @click="handleCustomSchedule(); showSchedulePicker = false" :disabled="!customScheduleDate || !customScheduleTime" class="w-full btn-primary text-sm py-1.5">
                <span class="material-symbols-rounded text-base">schedule_send</span>
                {{ $t('composeModal.schedule') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Backdrop for schedule picker -->
        <div v-if="showSchedulePicker" class="fixed inset-0 z-40" @click="showSchedulePicker = false"></div>
      </div>
    </div>
  </Transition>

  <!-- Bulk send confirm -->
  <div
    v-if="showBulkSendConfirm"
    class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9997] p-4"
    @click.self="showBulkSendConfirm = false"
  >
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
      <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
          <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">campaign</span>
        </div>
        <div>
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('composeModal.bulkEmailDetected') }}</h3>
          <p class="text-sm text-surface-500">{{ $t('composeModal.largeRecipientListWillBe') }}</p>
        </div>
      </div>
      <div class="p-4">
        <div class="bg-surface-50 dark:bg-surface-900 rounded-lg p-4 mb-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-surface-600 dark:text-surface-400">{{ $t('composeModal.totalRecipients') }}</span>
            <span class="font-semibold text-surface-900 dark:text-surface-100">{{ bulkSendInfo.totalRecipients }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-surface-600 dark:text-surface-400">{{ $t('composeModal.estimatedTime') }}</span>
            <span class="font-semibold text-surface-900 dark:text-surface-100">{{ bulkSendInfo.estimatedTime }}</span>
          </div>
        </div>
      </div>
      <div class="p-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
        <button @click="showBulkSendConfirm = false" class="btn-secondary">{{ $t('composeModal.cancel') }}</button>
        <button @click="handleBulkSend" class="btn-primary"><span class="material-symbols-rounded">send</span> {{ $t('composeModal.queueCampaign') }}</button>
      </div>
    </div>
  </div>

  <!-- Drive Picker -->
  <DriveFilePicker
    :show="showDrivePicker"
    :title="$t('composeModal.attachFromDrive')"
    :multiple="true"
    @select="handleDriveFileSelected"
    @cancel="showDrivePicker = false"
  />
</template>

<style scoped>
.compose-slide-enter-active,
.compose-slide-leave-active {
  transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.2s ease;
}
.compose-slide-enter-from {
  transform: translateY(100%);
  opacity: 0;
}
.compose-slide-leave-to {
  transform: translateY(100%);
  opacity: 0;
}

/* Visually collapse the signature block inside the editor (it still ships in the email) */
.compose-signature-collapsed :deep([data-signature]) {
  display: none;
}

/* Render the inline (Gmail-style) compose footer (Attach / Drive / Schedule /
   Important / Save Draft / Send) at COMPACT proportions while in COSY density,
   so the fixed-width 680px window never clips the Send button.

   The footer is a non-wrapping flex row of 6 .shrink-0 buttons. In cosy each
   btn-* inherits the base `.btn` chrome (px-5 = 1.25rem side padding, 14px
   text), so the row overflows the window and the trailing Send button gets cut
   off. Compact density already fits because `.density-compact .btn` trims that
   padding/font — we reproduce the same trimming here, for cosy only, scoped to
   exactly this footer.

   CRITICAL: every selector wraps its FULL chain in :global(...). The mixed form
   `:global(.density-cosy) .compose-inline-footer-actions .btn-ghost` is
   mis-compiled by the scoped-CSS transform (it drops everything after :global()
   and collapses to a bare `.density-cosy{...}`). Keeping the whole chain inside
   :global() is the only form that survives the build. The density class lives on
   <html>, and `.compose-inline-footer-actions` is unique to this footer, so going
   fully global is both necessary (the window is teleported/fixed) and safe. */
:global(.density-cosy .compose-inline-footer-actions .btn-ghost),
:global(.density-cosy .compose-inline-footer-actions .btn-secondary),
:global(.density-cosy .compose-inline-footer-actions .btn-primary) {
  padding: 0.375rem 0.5rem;
  font-size: 0.75rem;
  line-height: 1rem;
  gap: 0.375rem;
}
</style>
