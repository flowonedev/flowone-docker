<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useComposeStore } from '@/stores/compose'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useSettingsStore } from '@/stores/settings'
import { useDriveStore } from '@/stores/drive'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useMailingListsStore } from '@/addons/email-marketing/stores/mailingLists'
import { useEmailCampaignsStore } from '@/addons/email-marketing/stores/emailCampaigns'
import { useAddons } from '@/composables/useAddons'
import { useLayoutStore } from '@/stores/layout'
import { useEmailTemplatesStore } from '@/stores/emailTemplates'
import { useRouter } from 'vue-router'
import Modal from './shared/Modal.vue'
import RichTextEditor from './RichTextEditor.vue'
import UserAvatar from './shared/UserAvatar.vue'
import { isSameMailbox, normalizeEmail } from '@/utils/emailNormalizer'
import api from '@/services/api'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const layout = useLayoutStore()
const isMobile = computed(() => layout.isMobile)

const compose = useComposeStore()
const auth = useAuthStore()
const toast = useToastStore()
const settingsStore = useSettingsStore()
const drive = useDriveStore()
const aiStore = useAIStore()
const colleaguesStore = useColleaguesStore()
const mailingListsStore = useMailingListsStore()
const emailCampaignsStore = useEmailCampaignsStore()
const emailTemplatesStore = useEmailTemplatesStore()
const { emailMarketingEnabled, teamEnabled, emailTrackingEnabled, aiAssistantEnabled } = useAddons()
const router = useRouter()

// Ref to the body RichTextEditor instance (for inserting templates at cursor)
const bodyEditorRef = ref(null)

// Bulk send threshold - emails above this count will be queued
const BULK_SEND_THRESHOLD = 10

const showCc = ref(false)
const showBcc = ref(false)
const recipientInput = ref({ to: '', cc: '', bcc: '' })
const fileInput = ref(null)
const dragOver = ref(false)
const dragCounter = ref(0) // Counter to fix flickering on child elements
const lastSaved = ref(null)
const uploadingFiles = ref([]) // Track files being uploaded with their status
const saveIndicatorVisible = ref(false)

// Collapsible recipient display
const RECIPIENT_DISPLAY_LIMIT = 5
const showAllTo = ref(false)
const showAllCc = ref(false)
const showAllBcc = ref(false)

// Drive picker state
const showDrivePicker = ref(false)
const drivePickerFolder = ref(null) // Current folder in picker
const drivePickerPath = ref([{ id: null, name: 'My Drive' }])
const drivePickerLoading = ref(false)
const drivePickerFiles = ref([])
const drivePickerFolders = ref([])
const selectedDriveFiles = ref([])

// Autocomplete state
const suggestions = ref([]) // { type: 'contact'|'group', ...data }
const showSuggestions = ref({ to: false, cc: false, bcc: false })
const selectedSuggestionIndex = ref(-1)
const suggestionRefs = ref({ to: null, cc: null, bcc: null })
let searchTimeout = null

// Team groups for email
const teamGroups = computed(() => colleaguesStore.groups || [])
// Mailing lists for email
const mailingLists = computed(() => mailingListsStore.lists || [])

// Visible recipients (collapsed view)
const visibleToRecipients = computed(() => {
  if (showAllTo.value) return compose.draft.to
  return compose.draft.to.slice(0, RECIPIENT_DISPLAY_LIMIT)
})
const visibleCcRecipients = computed(() => {
  if (showAllCc.value) return compose.draft.cc
  return compose.draft.cc.slice(0, RECIPIENT_DISPLAY_LIMIT)
})
const visibleBccRecipients = computed(() => {
  if (showAllBcc.value) return compose.draft.bcc
  return compose.draft.bcc.slice(0, RECIPIENT_DISPLAY_LIMIT)
})

// Hidden recipient counts
const hiddenToCount = computed(() => Math.max(0, compose.draft.to.length - RECIPIENT_DISPLAY_LIMIT))
const hiddenCcCount = computed(() => Math.max(0, compose.draft.cc.length - RECIPIENT_DISPLAY_LIMIT))
const hiddenBccCount = computed(() => Math.max(0, compose.draft.bcc.length - RECIPIENT_DISPLAY_LIMIT))

// Bulk send confirmation
const showBulkSendConfirm = ref(false)
const bulkSendInfo = ref({ totalRecipients: 0, estimatedTime: '' })

// Track when draft is saved
watch(() => compose.saving, (newVal, oldVal) => {
  if (oldVal === true && newVal === false) {
    lastSaved.value = new Date()
    saveIndicatorVisible.value = true
    // Hide indicator after 3 seconds
    setTimeout(() => {
      saveIndicatorVisible.value = false
    }, 3000)
  }
})

// Live "Draft saved Ns ago" status shown in the header
const nowTick = ref(Date.now())
let nowTickTimer = null
onMounted(() => {
  nowTickTimer = setInterval(() => { nowTick.value = Date.now() }, 1000)
})
onUnmounted(() => {
  if (nowTickTimer) clearInterval(nowTickTimer)
})

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
    compose.draft.body += html
  }
  compose.markAsEdited()
  showTemplatesMenu.value = false
}

// Collapsible signature (visual only - signature still ships in the body)
const signatureCollapsed = ref(false)
const hasSignature = computed(() => (compose.draft.body || '').includes('data-signature'))
function toggleSignature() {
  signatureCollapsed.value = !signatureCollapsed.value
}

// "More" footer menu (hosts "Save as template" + merge tags)
const showMoreMenu = ref(false)

async function handleSaveAsTemplate() {
  showMoreMenu.value = false
  showTemplatesMenu.value = false
  const name = (prompt(t('composeModal.templateNamePrompt')) || '').trim()
  if (!name) return
  const result = await emailTemplatesStore.createTemplate({
    name,
    html_content: compose.draft.body,
  })
  if (result.success) {
    toast.success(t('composeModal.templateSaved'))
  } else {
    toast.error(result.error || t('composeModal.failedToSaveTemplate'))
  }
}

// Show CC/BCC if they have values and load team groups
watch(() => compose.isOpen, (open) => {
  if (open) {
    showCc.value = compose.draft.cc.length > 0
    showBcc.value = compose.draft.bcc.length > 0
    // Reset collapsed recipient states
    showAllTo.value = false
    showAllCc.value = false
    showAllBcc.value = false
    // Reset redesigned-compose UI state
    showTemplatesMenu.value = false
    showMoreMenu.value = false
    signatureCollapsed.value = false
    lastSaved.value = null
    // Load team groups and mailing lists for recipient autocomplete
    if (teamEnabled.value && colleaguesStore.groups.length === 0) {
      colleaguesStore.fetchGroups()
    }
    if (emailMarketingEnabled.value && mailingListsStore.lists.length === 0) {
      mailingListsStore.fetchLists()
    }
  } else if (!open) {
    // Stop tracking when compose is closed
    clientTimeTracker.stopTracking()
  }
})

// Addons load async -- fetch mailing lists once addon becomes available
watch(emailMarketingEnabled, (enabled) => {
  if (enabled && mailingListsStore.lists.length === 0) {
    mailingListsStore.fetchLists()
  }
}, { once: true })

// Track email composing for client time tracking
watch(
  () => [...compose.draft.to, ...compose.draft.cc, ...compose.draft.bcc],
  (recipients) => {
    if (compose.isOpen && recipients.length > 0) {
      // Extract email addresses from recipients
      const emails = recipients.map(r => r.email || r.address || r)
      clientTimeTracker.trackEmailCompose(emails, auth.userEmail, compose.draft.subject)
    }
  },
  { deep: true }
)

/**
 * Fired by the TipTap MentionExtension after a user picks someone from the
 * @-popup. If the `auto_add_mentions_to_recipients` setting is ON (default),
 * we add the mailbox to To: — matching Outlook's behaviour. Idempotent:
 * adding the same address twice is a no-op (compose.addRecipient dedups by
 * canonical lower-cased email).
 *
 * Once added, the chip becomes user-removable like any other recipient —
 * removing it from To: does NOT remove the mention from the body (that's the
 * decoupled-after-commit behaviour the user asked for earlier).
 */
function onMentionCommitted(event) {
  const { email, name } = event.detail || {}
  if (!email) return
  // Default ON when setting key is missing — first-time users get the
  // expected Outlook UX.
  const enabled = settingsStore.settings?.auto_add_mentions_to_recipients !== false
  if (!enabled) return
  const canonical = normalizeEmail(email) || email
  // Skip if already present in To, Cc, or Bcc.
  for (const field of ['to', 'cc', 'bcc']) {
    if ((compose.draft[field] || []).some((r) => isSameMailbox(r.email, canonical))) return
  }
  compose.addRecipient('to', {
    email: canonical,
    name: name || '',
    display: name ? `${name} <${canonical}>` : canonical,
  })
  compose.markAsEdited()
}

function addRecipient(type) {
  const input = recipientInput.value[type].trim()
  if (!input) return
  
  // Parse email
  let email = input
  let name = ''
  
  const match = input.match(/^(.+?)\s*<(.+?)>$/)
  if (match) {
    name = match[1].trim()
    email = match[2].trim()
  }
  
  if (!email.includes('@')) {
    toast.warning(t('composeModal.pleaseEnterAValidEmail'))
    return
  }
  
  compose.addRecipient(type, { email, name, display: name ? `${name} <${email}>` : email })
  compose.markAsEdited()
  recipientInput.value[type] = ''
}

function handleRecipientKeydown(e, type) {
  // Handle suggestion navigation
  if (showSuggestions.value[type] && suggestions.value.length > 0) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      selectedSuggestionIndex.value = Math.min(
        selectedSuggestionIndex.value + 1,
        suggestions.value.length - 1
      )
      return
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault()
      selectedSuggestionIndex.value = Math.max(selectedSuggestionIndex.value - 1, -1)
      return
    }
    if (e.key === 'Enter' && selectedSuggestionIndex.value >= 0) {
      e.preventDefault()
      selectSuggestion(suggestions.value[selectedSuggestionIndex.value], type)
      return
    }
    if (e.key === 'Escape') {
      e.preventDefault()
      closeSuggestions(type)
      return
    }
  }
  
  if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
    e.preventDefault()
    closeSuggestions(type)
    addRecipient(type)
  } else if (e.key === 'Backspace' && !recipientInput.value[type] && compose.draft[type].length > 0) {
    compose.draft[type].pop()
  }
}

function handleRecipientBlur(type) {
  // Delay blur to allow click on suggestions
  setTimeout(() => {
    closeSuggestions(type)
    if (recipientInput.value[type].trim()) {
      addRecipient(type)
    }
  }, 200)
}

// Autocomplete functions
async function handleRecipientInput(type) {
  const query = recipientInput.value[type].trim()
  
  // Clear previous timeout
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }
  
  if (query.length < 1) {
    closeSuggestions(type)
    return
  }
  
  // Debounce the search
  searchTimeout = setTimeout(async () => {
    try {
      const response = await api.get('/contacts/search', { params: { q: query, limit: 8 } })
      if (response.data.success) {
        // Filter out already added recipients
        const addedEmails = compose.draft[type].map(r => r.email.toLowerCase())
        const contacts = response.data.data.contacts.filter(
          c => !addedEmails.includes(c.email.toLowerCase())
        ).map(c => ({ ...c, type: 'contact' }))
        
        // Search team groups by name (only if team addon is enabled)
        const queryLower = query.toLowerCase()
        const matchingGroups = teamEnabled.value
          ? teamGroups.value.filter(g => g.name.toLowerCase().includes(queryLower)).map(g => ({ ...g, type: 'group' }))
          : []
        
        // Search mailing lists by name (only if email marketing addon is enabled)
        const matchingLists = emailMarketingEnabled.value
          ? mailingLists.value.filter(l => l.name.toLowerCase().includes(queryLower)).map(l => ({ ...l, type: 'mailing_list' }))
          : []
        
        // Combine: groups first, then mailing lists, then contacts
        suggestions.value = [...matchingGroups.slice(0, 2), ...matchingLists.slice(0, 2), ...contacts]
        
        if (suggestions.value.length > 0) {
          showSuggestions.value[type] = true
          selectedSuggestionIndex.value = -1
        } else {
          closeSuggestions(type)
        }
      }
    } catch (e) {
      console.error('Failed to fetch contact suggestions:', e)
    }
  }, 150)
}

function selectSuggestion(item, type) {
  if (item.type === 'group') {
    selectGroup(item, type)
  } else if (item.type === 'mailing_list') {
    selectMailingList(item, type)
  } else {
    compose.addRecipient(type, {
      email: item.email,
      name: item.name,
      display: item.display || item.email
    })
    compose.markAsEdited()
    recipientInput.value[type] = ''
    closeSuggestions(type)
  }
}

// Select a team group - adds all members as recipients
async function selectGroup(group, type) {
  try {
    const members = await colleaguesStore.getGroupMembers(group.id)
    if (members && members.length > 0) {
      // Add all members as recipients
      const addedEmails = compose.draft[type].map(r => r.email.toLowerCase())
      let addedCount = 0
      for (const member of members) {
        if (!addedEmails.includes(member.email.toLowerCase())) {
          compose.addRecipient(type, {
            email: member.email,
            name: member.display_name || member.email.split('@')[0],
            display: member.display_name || member.email
          })
          addedCount++
        }
      }
      if (addedCount > 0) {
        compose.markAsEdited()
        toast.success(t('composeModal.addedRecipients', { count: addedCount, name: group.name }))
      } else {
        toast.info(t('composeModal.allGroupMembersAreAlready'))
      }
    } else {
      toast.warning(t('composeModal.thisGroupHasNoMembers'))
    }
  } catch (e) {
    console.error('Failed to get group members:', e)
    toast.error(t('composeModal.failedToLoadGroupMembers'))
  }
  recipientInput.value[type] = ''
  closeSuggestions(type)
}

// Select a mailing list - adds all contacts as recipients
async function selectMailingList(list, type) {
  try {
    const emails = await mailingListsStore.getListEmails(list.id)
    if (emails && emails.length > 0) {
      // Add all contacts as recipients
      const addedEmails = compose.draft[type].map(r => r.email.toLowerCase())
      let addedCount = 0
      for (const contact of emails) {
        if (!addedEmails.includes(contact.email.toLowerCase())) {
          compose.addRecipient(type, {
            email: contact.email,
            name: contact.name || contact.email.split('@')[0],
            display: contact.name || contact.email
          })
          addedCount++
        }
      }
      if (addedCount > 0) {
        compose.markAsEdited()
        toast.success(t('composeModal.addedRecipients', { count: addedCount, name: list.name }))
      } else {
        toast.info(t('composeModal.allContactsAreAlreadyAdded'))
      }
    } else {
      toast.warning(t('composeModal.thisMailingListHasNo'))
    }
  } catch (e) {
    console.error('Failed to get mailing list contacts:', e)
    toast.error(t('composeModal.failedToLoadMailingList'))
  }
  recipientInput.value[type] = ''
  closeSuggestions(type)
}

function closeSuggestions(type) {
  showSuggestions.value[type] = false
  suggestions.value = []
  selectedSuggestionIndex.value = -1
}

// Save a suggested recipient into the real (synced) address book without
// leaving compose. Promotes it out of the non-synced "Other contacts" pool.
async function saveSuggestion(item) {
  if (!item || !item.email || item.is_saved) return
  try {
    const res = await api.post('/contacts/save', { email: item.email, name: item.name || '' })
    if (res.data && res.data.success) {
      item.is_saved = true
      item.is_synced = true
      item.justSaved = true
      if (res.data.data && res.data.data.contact) {
        item.contact_id = res.data.data.contact.id
      }
      toast.success(t('composeModal.savedToContacts', { name: item.name || item.email }))
    }
  } catch (e) {
    console.error('Failed to save contact:', e)
    toast.error(t('composeModal.failedToSaveContact'))
  }
}

// Load recent contacts, team groups, and mailing lists when focus without input
async function handleRecipientFocus(type) {
  if (!recipientInput.value[type].trim()) {
    // Ensure mailing lists are loaded
    if (emailMarketingEnabled.value && mailingListsStore.lists.length === 0) {
      mailingListsStore.fetchLists()
    }
    
    try {
      const response = await api.get('/contacts/recent', { params: { limit: 5 } })
      if (response.data.success) {
        // Filter out already added recipients
        const addedEmails = compose.draft[type].map(r => r.email.toLowerCase())
        const contacts = response.data.data.contacts.filter(
          c => !addedEmails.includes(c.email.toLowerCase())
        ).map(c => ({ ...c, type: 'contact' }))
        
        // Show all team groups first (only if team addon is enabled)
        const groups = teamEnabled.value
          ? teamGroups.value.map(g => ({ ...g, type: 'group' }))
          : []
        
        // Show all mailing lists (only if email marketing addon is enabled)
        const lists = emailMarketingEnabled.value
          ? mailingLists.value.map(l => ({ ...l, type: 'mailing_list' }))
          : []
        
        // Combine: groups first, then mailing lists, then recent contacts
        suggestions.value = [...groups, ...lists, ...contacts]
        
        if (suggestions.value.length > 0) {
          showSuggestions.value[type] = true
          selectedSuggestionIndex.value = -1
        }
      }
    } catch (e) {
      // Silently fail - suggestions are not critical
    }
  }
}

async function handleSaveCampaignDraft() {
  const result = await emailCampaignsStore.updateDraft(compose.campaignDraftId, {
    subject: compose.draft.subject,
    body_html: compose.draft.body,
    body_text: '',
    attachments: compose.draft.attachments,
  })
  if (result.success) {
    toast.success('Draft saved')
    compose.close()
    emailCampaignsStore.fetchCampaigns()
  } else {
    toast.error(result.error || 'Failed to save draft')
  }
}

const showMergeTagPicker = ref(false)
const mergeTagVariables = [
  { key: '{name}', label: 'Name', icon: 'person' },
  { key: '{email}', label: 'Email', icon: 'mail' },
  { key: '{phone}', label: 'Phone', icon: 'phone' },
  { key: '{position}', label: 'Position', icon: 'badge' },
  { key: '{company}', label: 'Company', icon: 'business' },
]

function insertMergeTag(tag) {
  compose.draft.body += tag
  compose.markAsEdited()
  showMergeTagPicker.value = false
}

async function handleSend() {
  if (compose.campaignDraftId) {
    return handleSaveCampaignDraft()
  }
  
  if (compose.draft.to.length === 0) {
    toast.warning(t('composeModal.pleaseAddAtLeastOne'))
    return
  }
  
  // Count total recipients
  const totalRecipients = compose.draft.to.length + compose.draft.cc.length + compose.draft.bcc.length
  
  // Force campaign mode (e.g. compose opened from campaigns page) or auto-detect bulk
  const isCampaign = compose.forceCampaign || (emailMarketingEnabled.value && totalRecipients > BULK_SEND_THRESHOLD)
  
  if (isCampaign) {
    const estimatedTime = emailCampaignsStore.calculateEstimatedTime(totalRecipients)
    bulkSendInfo.value = { totalRecipients, estimatedTime }
    showBulkSendConfirm.value = true
    return
  }
  
  // Normal send for small recipient lists
  const result = await compose.send()
  
  if (result.success) {
    if (result.undoSend) {
      const totalDelay = result.delay
      const toastId = toast.success(
        t('composeModal.sendingInSeconds', { seconds: totalDelay }),
        {
          duration: (totalDelay + 1) * 1000,
          action: {
            label: t('composeModal.undo'),
            onClick: () => { clearInterval(countdownTimer); undoDelayedSend(result.schedule_id) },
          },
        }
      )
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
  const result = await compose.openScheduledEmail(scheduleId, { undoSend: true })
  if (result.success) {
    toast.info(t('composeModal.sendCancelled'))
  } else if (result.tooLate) {
    toast.warning(t('composeModal.undoTooLate'))
  } else {
    toast.error(result.error || t('composeModal.failedToSendEmail'))
  }
}

async function handleBulkSend() {
  showBulkSendConfirm.value = false
  
  // Prepare email data for queue
  const emailData = {
    to: compose.draft.to.map(r => ({ email: r.email, name: r.name || '' })),
    cc: compose.draft.cc.map(r => ({ email: r.email, name: r.name || '' })),
    bcc: compose.draft.bcc.map(r => ({ email: r.email, name: r.name || '' })),
    subject: compose.draft.subject,
    body_html: compose.draft.body,
    body_text: '',
    from_name: '',
    attachments: compose.draft.attachments,
    in_reply_to: compose.draft.in_reply_to,
    references: compose.draft.references,
    track_read: emailTrackingEnabled.value
  }
  
  const wasForceCampaign = compose.forceCampaign
  const result = await emailCampaignsStore.queueBulkEmail(emailData)
  
  if (result.success) {
    toast.success(result.message || t('composeModal.campaignQueued', { count: result.totalRecipients }))
    compose.close()
    
    if (wasForceCampaign) {
      emailCampaignsStore.fetchCampaigns()
    } else {
      setTimeout(() => {
        if (confirm(t('composeModal.wouldYouLikeToView'))) {
          router.push('/campaigns')
        }
      }, 500)
    }
  } else {
    toast.error(result.error || t('composeModal.failedToQueueCampaign'))
  }
}

function cancelBulkSend() {
  showBulkSendConfirm.value = false
}

async function handleSaveDraft() {
  const success = await compose.saveDraft()
  if (success) {
    toast.success(t('composeModal.draftSaved'))
  } else {
    toast.error(t('composeModal.failedToSaveDraft'))
  }
}

async function handleClose() {
  // Only show "draft saved" toast if the user actually made edits and there's real content
  // The compose store's close() method handles the actual save logic via hasUserEdits + hasContent()
  if (compose.hasUserEdits) {
    // Check for real content (not just signature)
    const hasRecipients = compose.draft.to.length > 0 || compose.draft.cc.length > 0 || compose.draft.bcc.length > 0
    const hasSubject = compose.draft.subject.trim() !== ''
    const hasAttachments = compose.draft.attachments.length > 0
    if (hasRecipients || hasSubject || hasAttachments) {
      toast.info(t('composeModal.draftSavedAutomatically'))
    }
  }
  await compose.close()
}

function handleFileSelect(e) {
  const files = e.target.files
  if (files) {
    uploadFiles(Array.from(files))
  }
  e.target.value = ''
}

function handleDragEnter(e) {
  e.preventDefault()
  dragCounter.value++
  dragOver.value = true
}

function handleDragLeave(e) {
  e.preventDefault()
  dragCounter.value--
  if (dragCounter.value === 0) {
    dragOver.value = false
  }
}

function handleDrop(e) {
  e.preventDefault()
  dragCounter.value = 0
  dragOver.value = false
  
  const files = e.dataTransfer?.files
  if (files) {
    uploadFiles(Array.from(files))
  }
}

async function uploadFiles(files) {
  // Mark as edited when user attaches files
  compose.markAsEdited()
  
  const thresholdMB = settingsStore.settings.large_attachment_threshold ?? 10
  const thresholdBytes = thresholdMB * 1024 * 1024
  
  // Upload every file CONCURRENTLY rather than one-at-a-time. Sending several
  // images used to queue them sequentially, so the user waited through each
  // round-trip with no sense of overall progress. Each file still owns its own
  // uploading chip (spinner + name), so the user sees them all in flight and
  // clearing one by one as they finish.
  await Promise.all(files.map(async (file) => {
    const isLargeFile = thresholdBytes > 0 && file.size > thresholdBytes
    
    // Add to uploading list with status
    const uploadEntry = {
      id: Date.now() + Math.random(),
      name: file.name,
      size: file.size,
      isLargeFile,
      status: isLargeFile ? 'uploading_to_drive' : 'uploading',
      error: null
    }
    uploadingFiles.value.push(uploadEntry)
    
    const result = await compose.uploadAttachment(file)
    
    // Remove from uploading list
    const idx = uploadingFiles.value.findIndex(f => f.id === uploadEntry.id)
    if (idx !== -1) {
      if (!result.success) {
        // Show error briefly then remove
        uploadingFiles.value[idx].status = 'error'
        uploadingFiles.value[idx].error = result.error || 'Upload failed'
        setTimeout(() => {
          uploadingFiles.value = uploadingFiles.value.filter(f => f.id !== uploadEntry.id)
        }, 3000)
      } else {
        // Success - remove immediately
        uploadingFiles.value.splice(idx, 1)
      }
    }
  }))
}

function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

// Drive picker functions
async function openDrivePicker() {
  showDrivePicker.value = true
  selectedDriveFiles.value = []
  drivePickerFolder.value = null
  drivePickerPath.value = [{ id: null, name: 'My Drive' }]
  await loadDriveContents(null)
}

async function loadDriveContents(folderId) {
  drivePickerLoading.value = true
  try {
    const response = await api.get('/drive', { params: { folder_id: folderId || '' } })
    if (response.data.success) {
      drivePickerFiles.value = response.data.data.files || []
      drivePickerFolders.value = response.data.data.folders || []
      drivePickerFolder.value = folderId
    }
  } catch (e) {
    console.error('Failed to load drive contents:', e)
    toast.error(t('composeModal.failedToLoadDriveContents'))
  }
  drivePickerLoading.value = false
}

async function navigateDrivePickerTo(folderId, folderName) {
  if (folderId === null) {
    drivePickerPath.value = [{ id: null, name: 'My Drive' }]
  } else {
    // Check if navigating back
    const existingIndex = drivePickerPath.value.findIndex(p => p.id === folderId)
    if (existingIndex !== -1) {
      drivePickerPath.value = drivePickerPath.value.slice(0, existingIndex + 1)
    } else {
      drivePickerPath.value.push({ id: folderId, name: folderName })
    }
  }
  await loadDriveContents(folderId)
}

function toggleDriveFileSelection(file) {
  const idx = selectedDriveFiles.value.findIndex(f => f.id === file.id)
  if (idx === -1) {
    selectedDriveFiles.value.push(file)
  } else {
    selectedDriveFiles.value.splice(idx, 1)
  }
}

function isDriveFileSelected(file) {
  return selectedDriveFiles.value.some(f => f.id === file.id)
}

async function attachSelectedDriveFiles() {
  if (selectedDriveFiles.value.length === 0) {
    toast.warning(t('composeModal.pleaseSelectAtLeastOne'))
    return
  }
  
  // Mark as edited when user attaches files from Drive
  compose.markAsEdited()
  
  const filesToAttach = [...selectedDriveFiles.value]
  showDrivePicker.value = false
  
  for (const file of filesToAttach) {
    // Check if file already has a share link
    let shareUrl = null
    if (file.share_token) {
      // Already shared - include filename in URL for proper download naming
      const baseUrl = window.location.origin
      const fnParam = file.original_name ? `?fn=${encodeURIComponent(file.original_name)}` : ''
      shareUrl = `${baseUrl}/api/drive/share/${file.share_token}${fnParam}`
    } else {
      // Create share link (7 days, NOT auto-deleted since it's user's file)
      const result = await drive.shareForEmail(file.id, 168)
      if (result.success) {
        shareUrl = result.url
      } else {
        toast.error(t('composeModal.failedToShareFile', { name: file.original_name }))
        continue
      }
    }
    
    // Add as virtual attachment (link will be added to body when sending)
    // Note: Share link expires in 7 days, but file itself stays in Drive (not auto-deleted)
    compose.draft.attachments.push({
      name: file.original_name,
      size: file.size,
      driveLink: true,
      url: shareUrl,
      fileId: file.id,
      fromDrive: true, // Mark as attached from Drive (not uploaded)
      willExpire: true, // Share LINK expires in 7 days (file stays in Drive)
    })
  }
  
  toast.success(t('composeModal.attachedFilesFromDrive', { count: filesToAttach.length }))
}

function closeDrivePicker() {
  showDrivePicker.value = false
  selectedDriveFiles.value = []
}

const modeTitle = computed(() => {
  switch (compose.mode) {
    case 'reply': return 'Reply'
    case 'replyAll': return 'Reply All'
    case 'forward': return 'Forward'
    case 'draft': return 'Edit Draft'
    default: return 'New Message'
  }
})

const canSend = computed(() => {
  return compose.draft.to.length > 0 && compose.draft.subject.trim().length > 0
})

// AI Rewrite functionality
const showRewriteOptions = ref(false)
const selectedStyle = ref('')

// Schedule send banner state
const scheduleBannerDismissed = ref(false)
const externalBannerDismissed = ref(false)
const showSchedulePicker = ref(false)
const customScheduleDate = ref('')
const customScheduleTime = ref('')

// Get user's domain from their email
const userDomain = computed(() => {
  const email = auth.userEmail || ''
  return email.split('@')[1]?.toLowerCase() || ''
})

// Get list of colleagues' domains (organization domains)
const orgDomains = computed(() => {
  const domains = new Set()
  if (userDomain.value) {
    domains.add(userDomain.value)
  }
  // Also add domains from colleagues if available
  const colleagues = colleaguesStore.colleagues || []
  for (const c of colleagues) {
    const d = (c.email || '').split('@')[1]?.toLowerCase()
    if (d) domains.add(d)
  }
  return domains
})

// External recipients (outside organization)
const externalRecipients = computed(() => {
  if (!userDomain.value) return []
  const all = [...compose.draft.to, ...compose.draft.cc, ...compose.draft.bcc]
  return all.filter(r => {
    const recipientDomain = (r.email || '').split('@')[1]?.toLowerCase()
    return recipientDomain && !orgDomains.value.has(recipientDomain)
  })
})

// Smart schedule suggestion: if current time is outside 09:00-17:00 on weekdays,
// suggest next business day at 9:00 AM
const scheduleSuggestion = computed(() => {
  const now = new Date()
  const hour = now.getHours()
  const day = now.getDay() // 0=Sun, 6=Sat
  
  const isWeekend = day === 0 || day === 6
  const isOutsideHours = hour < 9 || hour >= 17
  
  if (!isWeekend && !isOutsideHours) return null
  
  // Find next business day at 9:00 AM
  const next = new Date(now)
  next.setHours(9, 0, 0, 0)
  
  if (isWeekend) {
    // Move to Monday
    const daysToMonday = day === 0 ? 1 : (8 - day)
    next.setDate(next.getDate() + daysToMonday)
  } else if (hour >= 17) {
    // After work hours - suggest tomorrow (or Monday if Friday)
    next.setDate(next.getDate() + 1)
    if (next.getDay() === 0) next.setDate(next.getDate() + 1) // Sun -> Mon
    if (next.getDay() === 6) next.setDate(next.getDate() + 2) // Sat -> Mon
  }
  // If before 9 AM, suggest same day at 9 AM (already set)
  
  return next
})

const scheduleSuggestionLabel = computed(() => {
  if (!scheduleSuggestion.value) return ''
  const d = scheduleSuggestion.value
  const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  const dayName = days[d.getDay()]
  const monthName = months[d.getMonth()]
  const date = d.getDate()
  const hours = d.getHours()
  const minutes = d.getMinutes().toString().padStart(2, '0')
  const ampm = hours >= 12 ? 'PM' : 'AM'
  const h12 = hours % 12 || 12
  return `${dayName}, ${monthName} ${date} at ${h12}:${minutes} ${ampm}`
})

function handleScheduleSend() {
  if (!scheduleSuggestion.value) return
  doScheduleSend(scheduleSuggestion.value)
}

function handleCustomSchedule() {
  if (!customScheduleDate.value || !customScheduleTime.value) {
    toast.warning(t('composeModal.pleaseSelectBothDateAnd'))
    return
  }
  const dateTime = new Date(`${customScheduleDate.value}T${customScheduleTime.value}`)
  if (isNaN(dateTime.getTime()) || dateTime <= new Date()) {
    toast.warning(t('composeModal.pleaseSelectAFutureDate'))
    return
  }
  doScheduleSend(dateTime)
}

async function doScheduleSend(date) {
  if (compose.draft.to.length === 0) {
    toast.warning(t('composeModal.pleaseAddAtLeastOne'))
    return
  }
  const isoString = date.toISOString().slice(0, 19).replace('T', ' ')
  const result = await compose.scheduleSend(isoString)
  if (result.success) {
    toast.success(t('composeModal.emailScheduledFor', { date: scheduleSuggestionLabel.value || date.toLocaleString() }))
    showSchedulePicker.value = false
  } else {
    toast.error(result.error || t('composeModal.failedToScheduleEmail'))
  }
}

// Quick schedule helpers
function getNextBusinessDay(daysAhead = 1, hour = 9) {
  const d = new Date()
  d.setDate(d.getDate() + daysAhead)
  d.setHours(hour, 0, 0, 0)
  // Skip weekends
  while (d.getDay() === 0 || d.getDay() === 6) {
    d.setDate(d.getDate() + 1)
  }
  return d
}

function formatScheduleLabel(d) {
  const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  const h = d.getHours()
  const ampm = h >= 12 ? 'PM' : 'AM'
  const h12 = h % 12 || 12
  return `${days[d.getDay()]}, ${months[d.getMonth()]} ${d.getDate()} at ${h12}:${d.getMinutes().toString().padStart(2, '0')} ${ampm}`
}

function getTomorrowMorningLabel() {
  return formatScheduleLabel(getNextBusinessDay(1, 9))
}

function getTomorrowAfternoonLabel() {
  return formatScheduleLabel(getNextBusinessDay(1, 14))
}

function getNextMondayLabel() {
  const d = new Date()
  const day = d.getDay()
  const daysUntilMonday = day === 0 ? 1 : (8 - day)
  const monday = new Date(d)
  monday.setDate(monday.getDate() + daysUntilMonday)
  monday.setHours(9, 0, 0, 0)
  return formatScheduleLabel(monday)
}

function scheduleQuick(preset) {
  let date
  if (preset === 'tomorrow_morning') {
    date = getNextBusinessDay(1, 9)
  } else if (preset === 'tomorrow_afternoon') {
    date = getNextBusinessDay(1, 14)
  } else if (preset === 'next_monday') {
    const d = new Date()
    const day = d.getDay()
    const daysUntilMonday = day === 0 ? 1 : (8 - day)
    date = new Date(d)
    date.setDate(date.getDate() + daysUntilMonday)
    date.setHours(9, 0, 0, 0)
  }
  if (date) {
    doScheduleSend(date)
    showSchedulePicker.value = false
  }
}

// Reset banner state when compose opens
watch(() => compose.isOpen, (open) => {
  if (open) {
    scheduleBannerDismissed.value = false
    externalBannerDismissed.value = false
    showSchedulePicker.value = false
  }
})

// Zen mode state
const zenMode = ref(false)

function toggleZenMode() {
  zenMode.value = !zenMode.value
}

// Strip HTML for AI processing
function stripHtml(html) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  return doc.body.textContent || ''
}

// Rewrite the email body with AI
async function rewriteWithAI(style = null) {
  const bodyText = stripHtml(compose.draft.body)
  
  if (!bodyText.trim()) {
    toast.warning(t('composeModal.pleaseWriteSomeTextFirst'))
    return
  }
  
  const rewriteStyle = style || selectedStyle.value || aiStore.writingStyle
  
  const result = await aiStore.rewrite(bodyText, rewriteStyle)
  
  if (result.success) {
    // Convert plain text back to HTML paragraphs
    const paragraphs = result.rewritten.split('\n\n').filter(p => p.trim())
    const htmlContent = paragraphs.map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('')
    compose.draft.body = htmlContent
    compose.markAsEdited()
    toast.success(t('composeModal.textRewritten'))
    showRewriteOptions.value = false
  } else {
    toast.error(result.error || t('composeModal.failedToRewriteText'))
  }
}
</script>

<template>
  <Modal 
    :show="compose.isOpen" 
    :title="modeTitle"
    :size="zenMode ? 'fullscreen' : 'xl'"
    :mobileFullscreen="true"
    @close="handleClose()"
  >
    <!-- Mobile header with close, attach and send -->
    <template #mobile-header>
      <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 bg-surface-900">
        <button @click="handleClose()" class="p-2 -ml-2 text-surface-400 hover:text-surface-200">
          <span class="material-symbols-rounded text-2xl">close</span>
        </button>
        <h3 class="text-base font-medium text-surface-100">{{ modeTitle }}</h3>
        <div class="flex items-center gap-2">
          <button @click="fileInput?.click()" class="p-2 text-surface-400 hover:text-surface-200">
            <span class="material-symbols-rounded text-xl">attachment</span>
          </button>
          <input
            ref="fileInput"
            type="file"
            multiple
            class="hidden"
            @change="handleFileSelect"
          />
          <button 
            @click="handleSend" 
            :disabled="compose.sending || !canSend"
            class="w-10 h-10 rounded-full bg-primary-500 text-white flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="compose.sending" class="material-symbols-rounded animate-spin text-xl">progress_activity</span>
            <span v-else class="material-symbols-rounded text-xl">arrow_upward</span>
          </button>
        </div>
      </div>
    </template>
    <template #header>
      <div class="flex items-center gap-3 min-w-0">
        <div class="w-9 h-9 rounded-xl bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 flex items-center justify-center shrink-0">
          <span class="material-symbols-rounded text-xl">send</span>
        </div>
        <div class="min-w-0">
          <h3 class="text-base font-semibold leading-tight truncate">{{ modeTitle }}</h3>
          <p v-if="relativeSavedLabel" class="flex items-center gap-1.5 text-xs text-surface-500 leading-tight mt-0.5">
            <span class="w-1.5 h-1.5 rounded-full bg-green-500 shrink-0"></span>
            {{ relativeSavedLabel }}
          </p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button 
          @click="toggleZenMode" 
          class="btn-icon"
          :title="zenMode ? $t('composeModal.exitZenMode') : $t('composeModal.enterZenMode')"
        >
          <span class="material-symbols-rounded text-xl">
            {{ zenMode ? 'close_fullscreen' : 'open_in_full' }}
          </span>
        </button>
        <button @click="handleClose()" class="btn-icon">
          <span class="material-symbols-rounded text-xl">close</span>
        </button>
      </div>
    </template>
    
    <div 
      :class="[
        zenMode ? 'space-y-4 flex flex-col flex-1 min-h-0' : 'space-y-4',
        isMobile ? 'px-4 py-3' : '',
        'relative'
      ]"
      @dragover.prevent
      @dragenter="handleDragEnter"
      @dragleave="handleDragLeave"
      @drop="handleDrop"
    >
      <!-- Smart Banners (schedule + external) - desktop only -->
      <div
        v-if="!isMobile && ((scheduleSuggestion && !scheduleBannerDismissed && compose.draft.to.length > 0) || (externalRecipients.length > 0 && !externalBannerDismissed))"
        class="grid gap-2"
        :class="(scheduleSuggestion && !scheduleBannerDismissed && compose.draft.to.length > 0) && (externalRecipients.length > 0 && !externalBannerDismissed) ? 'grid-cols-2' : 'grid-cols-1'"
      >
        <!-- Schedule Send Banner -->
        <div 
          v-if="scheduleSuggestion && !scheduleBannerDismissed && compose.draft.to.length > 0"
          class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/20"
        >
          <span class="material-symbols-rounded text-base text-primary-500 shrink-0">schedule_send</span>
          <p class="flex-1 text-xs text-surface-600 dark:text-surface-400 truncate">
            <span class="font-medium text-surface-800 dark:text-surface-200">{{ scheduleSuggestionLabel }}</span>
          </p>
          <button
            @click="handleScheduleSend"
            class="px-2.5 py-0.5 text-xs font-medium rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors whitespace-nowrap"
          >
            {{ $t('composeModal.schedule') }}
          </button>
          <button
            @click="scheduleBannerDismissed = true"
            class="p-0.5 rounded text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors shrink-0"
          >
            <span class="material-symbols-rounded text-base">close</span>
          </button>
        </div>

        <!-- External Recipient Warning Banner -->
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
          <button
            @click="externalBannerDismissed = true"
            class="p-0.5 rounded text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors shrink-0"
          >
            <span class="material-symbols-rounded text-base">close</span>
          </button>
        </div>
      </div>

      <!-- From (desktop only — mobile sends from default account) -->
      <div v-if="!isMobile" class="flex items-center gap-2">
        <label class="w-16 text-sm text-surface-500 text-right">{{ $t('composeModal.from') }}</label>
        <div class="flex-1 relative">
          <!-- Simple display if only one address -->
          <div v-if="compose.sendAddresses.length <= 1" class="px-3 py-2 text-sm text-surface-600 dark:text-surface-300">
            {{ auth.userEmail }}
          </div>
          <!-- Dropdown selector if multiple addresses available -->
          <select 
            v-else
            :value="compose.fromAddress?.email || auth.userEmail"
            @change="compose.setFromAddress(compose.sendAddresses.find(a => a.email === $event.target.value))"
            class="input text-sm w-full"
          >
            <option 
              v-for="addr in compose.sendAddresses" 
              :key="addr.email" 
              :value="addr.email"
            >
              {{ addr.name ? `${addr.name} <${addr.email}>` : addr.email }}
              {{ addr.is_primary ? $t('composeModal.primary') : '' }}
              {{ addr.account_type === 'linked' ? $t('composeModal.linked') : '' }}
            </option>
          </select>
        </div>
      </div>
      
      <!-- To -->
      <div :class="isMobile ? 'space-y-2 pb-3 border-b border-surface-200 dark:border-surface-700' : 'flex items-start gap-2'">
        <div :class="isMobile ? 'flex items-center gap-2' : 'contents'">
          <label :class="isMobile ? 'text-sm text-surface-500 shrink-0' : 'w-16 text-sm text-surface-500 text-right pt-2'">{{ $t('composeModal.to') }}</label>
          <div v-if="isMobile" class="flex items-center gap-3 ml-auto">
            <button v-if="!showCc" @click="showCc = true" class="inline-flex items-center gap-1 text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors">
              <span class="material-symbols-rounded text-sm">person_add</span>{{ $t('composeModal.cc') }}
            </button>
            <button v-if="!showBcc" @click="showBcc = true" class="inline-flex items-center gap-1 text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors">
              <span class="material-symbols-rounded text-sm">person_add</span>{{ $t('composeModal.bcc') }}
            </button>
          </div>
        </div>
        <div class="flex-1 relative">
          <div class="flex flex-wrap gap-2 p-2 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border-strong))] bg-surface-50 dark:bg-[rgb(var(--color-bg))] focus-within:ring-2 focus-within:ring-primary-500/40 focus-within:border-primary-500">
            <span
              v-for="(recipient, i) in visibleToRecipients"
              :key="i"
              class="inline-flex items-center gap-1.5 pl-1 pr-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 text-sm"
            >
              <UserAvatar :email="recipient.email" :name="recipient.name" size="xs" />
              <span class="truncate max-w-[200px]">{{ recipient.name || recipient.email }}</span>
              <button @click="compose.removeRecipient('to', recipient.email)" class="hover:text-primary-900 shrink-0">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
            <!-- +XX more chip -->
            <button
              v-if="hiddenToCount > 0 && !showAllTo"
              @click="showAllTo = true"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300 text-sm hover:bg-surface-300 dark:hover:bg-surface-500 transition-colors font-medium"
            >
              {{ $t('composeModal.moreCount', { count: hiddenToCount }) }}
            </button>
            <!-- Show less chip -->
            <button
              v-if="showAllTo && compose.draft.to.length > RECIPIENT_DISPLAY_LIMIT"
              @click="showAllTo = false"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300 text-sm hover:bg-surface-300 dark:hover:bg-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">unfold_less</span>
              {{ $t('composeModal.less') }}
            </button>
            <input
              v-model="recipientInput.to"
              @keydown="handleRecipientKeydown($event, 'to')"
              @input="handleRecipientInput('to')"
              @focus="handleRecipientFocus('to')"
              @blur="handleRecipientBlur('to')"
              type="text"
              class="flex-1 min-w-[120px] bg-transparent outline-none text-sm py-1"
              :placeholder="$t('composeModal.addRecipient')"
              autocomplete="off"
            />
          </div>
          <!-- Autocomplete suggestions -->
          <div 
            v-if="showSuggestions.to && suggestions.length > 0"
            class="absolute z-50 mt-1 w-full bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 max-h-56 overflow-y-auto"
          >
            <button
              v-for="(item, i) in suggestions"
              :key="item.type === 'group' ? `group-${item.id}` : item.type === 'mailing_list' ? `list-${item.id}` : item.email"
              @mousedown.prevent="selectSuggestion(item, 'to')"
              :class="[
                'w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors',
                selectedSuggestionIndex === i ? 'bg-primary-50 dark:bg-primary-900/30' : ''
              ]"
            >
              <!-- Team Group -->
              <template v-if="item.type === 'group'">
                <div class="flex items-center gap-2">
                  <span 
                    class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs"
                    :style="{ backgroundColor: item.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded text-sm">group</span>
                  </span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1">
                      {{ item.name }}
                      <span class="text-xs text-primary-500 bg-primary-50 dark:bg-primary-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.team') }}</span>
                    </div>
                    <div class="text-xs text-surface-500">
                      {{ $t('composeModal.members', { count: item.member_count || 0 }) }}
                    </div>
                  </div>
                </div>
              </template>
              <!-- Mailing List -->
              <template v-else-if="item.type === 'mailing_list'">
                <div class="flex items-center gap-2">
                  <span 
                    class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs"
                    :style="{ backgroundColor: item.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded text-sm">{{ item.icon || 'mail' }}</span>
                  </span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1">
                      {{ item.name }}
                      <span class="text-xs text-orange-500 bg-orange-50 dark:bg-orange-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.list') }}</span>
                    </div>
                    <div class="text-xs text-surface-500">
                      {{ $t('composeModal.contacts', { count: item.contact_count || 0 }) }}
                    </div>
                  </div>
                </div>
              </template>
              <!-- Contact -->
              <template v-else>
                <div class="flex items-center gap-2">
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                      <span class="truncate">{{ item.name || item.email }}</span>
                      <span v-if="item.is_synced" class="material-symbols-rounded text-[15px] text-primary-500" :title="$t('composeModal.savedContact')">bookmark</span>
                      <span v-else-if="item.is_saved" class="material-symbols-rounded text-[15px] text-surface-400" :title="$t('composeModal.otherContact')">bookmark_border</span>
                      <span v-if="item.is_client" class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.client') }}</span>
                    </div>
                    <div v-if="item.name" class="text-xs text-surface-500 truncate">
                      {{ item.email }}
                    </div>
                  </div>
                  <span
                    v-if="!item.is_saved"
                    role="button"
                    tabindex="-1"
                    @mousedown.prevent.stop="saveSuggestion(item)"
                    class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full text-surface-400 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    :title="$t('composeModal.saveToContacts')"
                  >
                    <span class="material-symbols-rounded text-[18px]">person_add</span>
                  </span>
                  <span v-else-if="item.justSaved" class="shrink-0 material-symbols-rounded text-[18px] text-emerald-500">check_circle</span>
                </div>
              </template>
            </button>
          </div>
        </div>
        <div v-if="!isMobile" class="flex items-center gap-3 pt-2">
          <button v-if="!showCc" @click="showCc = true" class="inline-flex items-center gap-1 text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors">
            <span class="material-symbols-rounded text-sm">person_add</span>{{ $t('composeModal.cc') }}
          </button>
          <button v-if="!showBcc" @click="showBcc = true" class="inline-flex items-center gap-1 text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors">
            <span class="material-symbols-rounded text-sm">person_add</span>{{ $t('composeModal.bcc') }}
          </button>
        </div>
      </div>
      
      <!-- Cc -->
      <div v-if="showCc" :class="[
        'flex items-start gap-2',
        isMobile ? 'pb-3 border-b border-surface-200 dark:border-surface-700' : ''
      ]">
        <label :class="isMobile ? 'text-sm text-surface-500 shrink-0 pt-2' : 'w-16 text-sm text-surface-500 text-right pt-2'">{{ $t('composeModal.cc') }}</label>
        <div class="flex-1 relative">
          <div class="flex flex-wrap gap-2 p-2 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border-strong))] bg-surface-50 dark:bg-[rgb(var(--color-bg))] focus-within:ring-2 focus-within:ring-primary-500/40 focus-within:border-primary-500">
            <span
              v-for="(recipient, i) in visibleCcRecipients"
              :key="i"
              class="inline-flex items-center gap-1.5 pl-1 pr-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-700 dark:text-surface-200 text-sm"
            >
              <UserAvatar :email="recipient.email" :name="recipient.name" size="xs" />
              <span class="truncate max-w-[200px]">{{ recipient.name || recipient.email }}</span>
              <button @click="compose.removeRecipient('cc', recipient.email)" class="shrink-0">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
            <!-- +XX more chip -->
            <button
              v-if="hiddenCcCount > 0 && !showAllCc"
              @click="showAllCc = true"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-300 dark:bg-surface-600 text-surface-600 dark:text-surface-300 text-sm hover:bg-surface-400 dark:hover:bg-surface-500 transition-colors font-medium"
            >
              {{ $t('composeModal.moreCount', { count: hiddenCcCount }) }}
            </button>
            <!-- Show less chip -->
            <button
              v-if="showAllCc && compose.draft.cc.length > RECIPIENT_DISPLAY_LIMIT"
              @click="showAllCc = false"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-300 dark:bg-surface-600 text-surface-600 dark:text-surface-300 text-sm hover:bg-surface-400 dark:hover:bg-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">unfold_less</span>
              {{ $t('composeModal.less') }}
            </button>
            <input
              v-model="recipientInput.cc"
              @keydown="handleRecipientKeydown($event, 'cc')"
              @input="handleRecipientInput('cc')"
              @focus="handleRecipientFocus('cc')"
              @blur="handleRecipientBlur('cc')"
              type="text"
              class="flex-1 min-w-[150px] bg-transparent outline-none text-sm py-1"
              :placeholder="$t('composeModal.addCc')"
              autocomplete="off"
            />
          </div>
          <!-- Autocomplete suggestions -->
          <div 
            v-if="showSuggestions.cc && suggestions.length > 0"
            class="absolute z-50 mt-1 w-full bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 max-h-56 overflow-y-auto"
          >
            <button
              v-for="(item, i) in suggestions"
              :key="item.type === 'group' ? `group-${item.id}` : item.type === 'mailing_list' ? `list-${item.id}` : item.email"
              @mousedown.prevent="selectSuggestion(item, 'cc')"
              :class="[
                'w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors',
                selectedSuggestionIndex === i ? 'bg-primary-50 dark:bg-primary-900/30' : ''
              ]"
            >
              <!-- Team Group -->
              <template v-if="item.type === 'group'">
                <div class="flex items-center gap-2">
                  <span 
                    class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs"
                    :style="{ backgroundColor: item.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded text-sm">group</span>
                  </span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1">
                      {{ item.name }}
                      <span class="text-xs text-primary-500 bg-primary-50 dark:bg-primary-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.team') }}</span>
                    </div>
                    <div class="text-xs text-surface-500">
                      {{ $t('composeModal.members', { count: item.member_count || 0 }) }}
                    </div>
                  </div>
                </div>
              </template>
              <!-- Mailing List -->
              <template v-else-if="item.type === 'mailing_list'">
                <div class="flex items-center gap-2">
                  <span 
                    class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs"
                    :style="{ backgroundColor: item.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded text-sm">{{ item.icon || 'mail' }}</span>
                  </span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1">
                      {{ item.name }}
                      <span class="text-xs text-orange-500 bg-orange-50 dark:bg-orange-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.list') }}</span>
                    </div>
                    <div class="text-xs text-surface-500">
                      {{ $t('composeModal.contacts', { count: item.contact_count || 0 }) }}
                    </div>
                  </div>
                </div>
              </template>
              <!-- Contact -->
              <template v-else>
                <div class="flex items-center gap-2">
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                      <span class="truncate">{{ item.name || item.email }}</span>
                      <span v-if="item.is_synced" class="material-symbols-rounded text-[15px] text-primary-500" :title="$t('composeModal.savedContact')">bookmark</span>
                      <span v-else-if="item.is_saved" class="material-symbols-rounded text-[15px] text-surface-400" :title="$t('composeModal.otherContact')">bookmark_border</span>
                      <span v-if="item.is_client" class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.client') }}</span>
                    </div>
                    <div v-if="item.name" class="text-xs text-surface-500 truncate">
                      {{ item.email }}
                    </div>
                  </div>
                  <span
                    v-if="!item.is_saved"
                    role="button"
                    tabindex="-1"
                    @mousedown.prevent.stop="saveSuggestion(item)"
                    class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full text-surface-400 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    :title="$t('composeModal.saveToContacts')"
                  >
                    <span class="material-symbols-rounded text-[18px]">person_add</span>
                  </span>
                  <span v-else-if="item.justSaved" class="shrink-0 material-symbols-rounded text-[18px] text-emerald-500">check_circle</span>
                </div>
              </template>
            </button>
          </div>
        </div>
      </div>
      
      <!-- Bcc -->
      <div v-if="showBcc" :class="[
        'flex items-start gap-2',
        isMobile ? 'pb-3 border-b border-surface-200 dark:border-surface-700' : ''
      ]">
        <label :class="isMobile ? 'text-sm text-surface-500 shrink-0 pt-2' : 'w-16 text-sm text-surface-500 text-right pt-2'">{{ $t('composeModal.bcc') }}</label>
        <div class="flex-1 relative">
          <div class="flex flex-wrap gap-2 p-2 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border-strong))] bg-surface-50 dark:bg-[rgb(var(--color-bg))] focus-within:ring-2 focus-within:ring-primary-500/40 focus-within:border-primary-500">
            <span
              v-for="(recipient, i) in visibleBccRecipients"
              :key="i"
              class="inline-flex items-center gap-1.5 pl-1 pr-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-700 dark:text-surface-200 text-sm"
            >
              <UserAvatar :email="recipient.email" :name="recipient.name" size="xs" />
              <span class="truncate max-w-[200px]">{{ recipient.name || recipient.email }}</span>
              <button @click="compose.removeRecipient('bcc', recipient.email)" class="shrink-0">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
            <!-- +XX more chip -->
            <button
              v-if="hiddenBccCount > 0 && !showAllBcc"
              @click="showAllBcc = true"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-300 dark:bg-surface-600 text-surface-600 dark:text-surface-300 text-sm hover:bg-surface-400 dark:hover:bg-surface-500 transition-colors font-medium"
            >
              {{ $t('composeModal.moreCount', { count: hiddenBccCount }) }}
            </button>
            <!-- Show less chip -->
            <button
              v-if="showAllBcc && compose.draft.bcc.length > RECIPIENT_DISPLAY_LIMIT"
              @click="showAllBcc = false"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-300 dark:bg-surface-600 text-surface-600 dark:text-surface-300 text-sm hover:bg-surface-400 dark:hover:bg-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">unfold_less</span>
              {{ $t('composeModal.less') }}
            </button>
            <input
              v-model="recipientInput.bcc"
              @keydown="handleRecipientKeydown($event, 'bcc')"
              @input="handleRecipientInput('bcc')"
              @focus="handleRecipientFocus('bcc')"
              @blur="handleRecipientBlur('bcc')"
              type="text"
              class="flex-1 min-w-[150px] bg-transparent outline-none text-sm py-1"
              :placeholder="$t('composeModal.addBcc')"
              autocomplete="off"
            />
          </div>
          <!-- Autocomplete suggestions -->
          <div 
            v-if="showSuggestions.bcc && suggestions.length > 0"
            class="absolute z-50 mt-1 w-full bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 max-h-56 overflow-y-auto"
          >
            <button
              v-for="(item, i) in suggestions"
              :key="item.type === 'group' ? `group-${item.id}` : item.type === 'mailing_list' ? `list-${item.id}` : item.email"
              @mousedown.prevent="selectSuggestion(item, 'bcc')"
              :class="[
                'w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors',
                selectedSuggestionIndex === i ? 'bg-primary-50 dark:bg-primary-900/30' : ''
              ]"
            >
              <!-- Team Group -->
              <template v-if="item.type === 'group'">
                <div class="flex items-center gap-2">
                  <span 
                    class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs"
                    :style="{ backgroundColor: item.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded text-sm">group</span>
                  </span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1">
                      {{ item.name }}
                      <span class="text-xs text-primary-500 bg-primary-50 dark:bg-primary-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.team') }}</span>
                    </div>
                    <div class="text-xs text-surface-500">
                      {{ $t('composeModal.members', { count: item.member_count || 0 }) }}
                    </div>
                  </div>
                </div>
              </template>
              <!-- Mailing List -->
              <template v-else-if="item.type === 'mailing_list'">
                <div class="flex items-center gap-2">
                  <span 
                    class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs"
                    :style="{ backgroundColor: item.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded text-sm">{{ item.icon || 'mail' }}</span>
                  </span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1">
                      {{ item.name }}
                      <span class="text-xs text-orange-500 bg-orange-50 dark:bg-orange-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.list') }}</span>
                    </div>
                    <div class="text-xs text-surface-500">
                      {{ $t('composeModal.contacts', { count: item.contact_count || 0 }) }}
                    </div>
                  </div>
                </div>
              </template>
              <!-- Contact -->
              <template v-else>
                <div class="flex items-center gap-2">
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                      <span class="truncate">{{ item.name || item.email }}</span>
                      <span v-if="item.is_synced" class="material-symbols-rounded text-[15px] text-primary-500" :title="$t('composeModal.savedContact')">bookmark</span>
                      <span v-else-if="item.is_saved" class="material-symbols-rounded text-[15px] text-surface-400" :title="$t('composeModal.otherContact')">bookmark_border</span>
                      <span v-if="item.is_client" class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded">{{ $t('composeModal.client') }}</span>
                    </div>
                    <div v-if="item.name" class="text-xs text-surface-500 truncate">
                      {{ item.email }}
                    </div>
                  </div>
                  <span
                    v-if="!item.is_saved"
                    role="button"
                    tabindex="-1"
                    @mousedown.prevent.stop="saveSuggestion(item)"
                    class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full text-surface-400 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    :title="$t('composeModal.saveToContacts')"
                  >
                    <span class="material-symbols-rounded text-[18px]">person_add</span>
                  </span>
                  <span v-else-if="item.justSaved" class="shrink-0 material-symbols-rounded text-[18px] text-emerald-500">check_circle</span>
                </div>
              </template>
            </button>
          </div>
        </div>
      </div>
      
      <!-- Subject -->
      <div :class="[
        'flex items-center gap-2',
        isMobile ? 'pb-3 border-b border-surface-200 dark:border-surface-700' : ''
      ]">
        <label :class="isMobile ? 'text-sm text-surface-500 shrink-0' : 'w-16 text-sm text-surface-500 text-right'">{{ $t('composeModal.subject') }}</label>
        <input
          v-model="compose.draft.subject"
          @input="compose.markAsEdited()"
          type="text"
          class="flex-1 bg-transparent outline-none text-sm py-2 text-surface-800 dark:text-surface-100 placeholder:text-surface-400"
          :placeholder="$t('composeModal.addSubject')"
        />
      </div>
      
      <!-- Body -->
      <div :class="zenMode ? 'flex-1 flex flex-col min-h-0' : ''" @mention:committed="onMentionCommitted">
        <div :class="['relative', { 'compose-signature-collapsed': signatureCollapsed }, zenMode ? 'flex-1 flex flex-col min-h-0' : '']">
          <!-- Templates dropdown (floats over the editor's top-right, no extra row) -->
          <div v-if="!isMobile" :class="['absolute right-3 z-30', zenMode ? 'top-16' : 'top-2']">
            <button
              @click="toggleTemplatesMenu"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-surface-200 dark:border-surface-700 bg-white/90 dark:bg-surface-800/90 backdrop-blur-sm shadow-sm text-sm text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            >
              <span class="material-symbols-rounded text-base">description</span>
              {{ $t('composeModal.templates') }}
              <span class="material-symbols-rounded text-base transition-transform" :class="showTemplatesMenu ? 'rotate-180' : ''">arrow_drop_down</span>
            </button>
            <!-- Templates dropdown -->
            <div
              v-if="showTemplatesMenu"
              class="absolute right-0 mt-1 w-64 max-h-72 overflow-y-auto bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50"
            >
              <button
                @click="handleSaveAsTemplate"
                class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-base text-surface-400">bookmark_add</span>
                {{ $t('composeModal.saveAsTemplate') }}
              </button>
              <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
              <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase tracking-wide">{{ $t('composeModal.templates') }}</p>
              <div v-if="emailTemplatesStore.loading" class="px-3 py-3 text-sm text-surface-500 flex items-center gap-2">
                <span class="spinner-sm text-surface-400"></span>
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
          <!-- Backdrop for templates dropdown -->
          <div v-if="showTemplatesMenu" class="fixed inset-0 z-20" @click="showTemplatesMenu = false"></div>

          <RichTextEditor 
            ref="bodyEditorRef"
            v-model="compose.draft.body" 
            @update:modelValue="compose.markAsEdited()" 
            :zenMode="zenMode"
            :hideToolbar="isMobile"
            :aiEnabled="aiAssistantEnabled"
            :toolbarBottom="!zenMode"
            :minimalToolbar="!zenMode"
            :placeholder="$t('composeModal.startWriting')"
          />
        </div>

        <!-- Signature show/hide pill -->
        <div v-if="hasSignature" class="mt-2">
          <button
            @click="toggleSignature"
            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-sm transition-transform" :class="signatureCollapsed ? '' : 'rotate-180'">expand_more</span>
            {{ signatureCollapsed ? $t('composeModal.showSignature') : $t('composeModal.hideSignature') }}
          </button>
        </div>
      </div>
      
      <!-- Attachments & Upload Progress -->
      <div v-if="compose.draft.attachments.length > 0 || uploadingFiles.length > 0" class="flex flex-wrap gap-2 pt-2">
        <!-- Uploading files -->
        <div
          v-for="upload in uploadingFiles"
          :key="upload.id"
          :class="[
            'inline-flex items-center gap-2 px-3 py-2 rounded-xl',
            upload.status === 'error' 
              ? 'bg-red-50 dark:bg-red-500/20 border border-red-200 dark:border-red-500/30'
              : upload.isLargeFile
                ? 'bg-primary-50 dark:bg-primary-500/20 border border-primary-200 dark:border-primary-500/30'
                : 'bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-700'
          ]"
        >
          <!-- Spinner or error icon -->
          <span v-if="upload.status === 'error'" class="material-symbols-rounded text-lg text-red-500">error</span>
          <span v-else class="spinner-sm" :class="upload.isLargeFile ? 'text-primary-500' : 'text-surface-500'"></span>
          
          <div class="flex flex-col">
            <span class="text-sm">{{ upload.name }}</span>
            <span v-if="upload.status === 'error'" class="text-xs text-red-500">{{ upload.error }}</span>
            <span v-else-if="upload.isLargeFile" class="text-xs text-primary-500">{{ $t('composeModal.uploadingToDrive') }}</span>
            <span v-else class="text-xs text-surface-500">{{ $t('composeModal.uploading') }}</span>
          </div>
          <span class="text-xs text-surface-400">{{ formatSize(upload.size) }}</span>
        </div>
        
        <!-- Completed attachments -->
        <div
          v-for="(attachment, i) in compose.draft.attachments"
          :key="'att-' + i"
          :class="[
            'inline-flex items-center gap-2 px-3 py-2 rounded-xl',
            attachment.driveLink 
              ? 'bg-primary-50 dark:bg-primary-500/20 border border-primary-200 dark:border-primary-500/30' 
              : 'bg-surface-100 dark:bg-surface-800'
          ]"
        >
          <span :class="['material-symbols-rounded text-lg', attachment.driveLink ? 'text-primary-500' : 'text-surface-500']">
            {{ attachment.driveLink ? 'cloud' : 'attachment' }}
          </span>
          <span class="text-sm">{{ attachment.name || attachment.filename || 'attachment' }}</span>
          <span class="text-xs text-surface-500">{{ formatSize(attachment.size) }}</span>
          <span v-if="attachment.driveLink && attachment.fromDrive" class="text-xs text-primary-500">{{ $t('composeModal.fromDrive') }}</span>
          <span v-else-if="attachment.driveLink" class="text-xs text-primary-500">{{ $t('composeModal.uploadedToDrive') }}</span>
          <button @click="compose.removeAttachment(i)" class="text-surface-400 hover:text-red-500">
            <span class="material-symbols-rounded text-sm">close</span>
          </button>
        </div>
      </div>
      
      <!-- Drag overlay (desktop only) -->
      <div
        v-if="dragOver && !isMobile"
        class="absolute inset-0 flex items-center justify-center bg-primary-500/10 border-2 border-dashed border-primary-500 rounded-2xl z-10"
      >
        <div class="text-center">
          <span class="material-symbols-rounded text-5xl text-primary-500">upload_file</span>
          <p class="text-primary-600 dark:text-primary-400 mt-2">{{ $t('composeModal.dropFilesToAttach') }}</p>
        </div>
      </div>
    </div>
    
    <template #footer>
      <div class="compose-footer-actions flex items-center gap-1 w-full">
        <!-- Attach button -->
        <button @click="fileInput?.click()" class="btn-ghost shrink-0 whitespace-nowrap" :title="$t('composeModal.attach')">
          <span class="material-symbols-rounded">attachment</span>
          <span class="hidden sm:inline">{{ $t('composeModal.attach') }}</span>
        </button>
        <input
          ref="fileInput"
          type="file"
          multiple
          class="hidden"
          @change="handleFileSelect"
        />
        
        <!-- Attach from Drive button -->
        <button @click="openDrivePicker" class="btn-ghost shrink-0 whitespace-nowrap" :title="$t('composeModal.fromDriveBtn')">
          <span class="material-symbols-rounded">cloud</span>
          <span class="hidden sm:inline">{{ $t('composeModal.drive') }}</span>
        </button>

        <!-- Schedule send -->
        <button
          @click="showSchedulePicker = !showSchedulePicker"
          :disabled="compose.draft.to.length === 0"
          class="btn-ghost shrink-0 whitespace-nowrap"
          :title="$t('composeModal.scheduleSend')"
        >
          <span class="material-symbols-rounded">schedule</span>
          <span class="hidden sm:inline">{{ $t('composeModal.schedule') }}</span>
        </button>

        <!-- Mark as Important toggle -->
        <button
          @click="compose.draft.important = !compose.draft.important; compose.markAsEdited()"
          :class="['btn-ghost shrink-0 whitespace-nowrap', compose.draft.important ? 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10' : '']"
          :aria-pressed="compose.draft.important"
          :title="$t('composeModal.importantHint')"
        >
          <span class="material-symbols-rounded" :style="compose.draft.important ? 'font-variation-settings: \'FILL\' 1' : ''">flag</span>
          <span class="hidden sm:inline">{{ $t('composeModal.important') }}</span>
        </button>

        <!-- More menu (merge tags / variables - campaign drafts only) -->
        <div v-if="compose.campaignDraftId || compose.forceCampaign" class="relative shrink-0">
          <button 
            @click="showMoreMenu = !showMoreMenu"
            class="btn-ghost"
            :title="$t('composeModal.more')"
          >
            <span class="material-symbols-rounded">more_horiz</span>
          </button>
          
          <div 
            v-if="showMoreMenu" 
            class="absolute bottom-full left-0 mb-2 w-56 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50"
          >
            <p class="px-3 py-1 text-xs font-medium text-surface-500 uppercase">Merge Tags</p>
            <button
              v-for="tag in mergeTagVariables"
              :key="tag.key"
              @click="insertMergeTag(tag.key); showMoreMenu = false"
              class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-base text-surface-400">{{ tag.icon }}</span>
              <span class="flex-1">{{ tag.label }}</span>
              <code class="text-xs text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded">{{ tag.key }}</code>
            </button>
          </div>
        </div>
        <div v-if="showMoreMenu" class="fixed inset-0 z-40" @click="showMoreMenu = false"></div>
        
        <div class="flex-1 min-w-[8px]"></div>
        
        <!-- Save draft -->
        <button @click="handleSaveDraft" class="btn-secondary shrink-0 whitespace-nowrap" :disabled="compose.saving">
          <span v-if="compose.saving" class="spinner"></span>
          <span v-else class="material-symbols-rounded">save</span>
          {{ $t('composeModal.saveDraft') }}
        </button>
        
        <!-- Send split button with schedule dropdown -->
        <div class="relative inline-flex shrink-0 whitespace-nowrap">
          <button @click="handleSend" class="btn-primary !rounded-r-none" :disabled="compose.sending || compose.draft.to.length === 0">
            <span v-if="compose.sending" class="spinner"></span>
            <span v-else class="material-symbols-rounded">{{ compose.campaignDraftId ? 'save' : 'send' }}</span>
            {{ compose.campaignDraftId ? 'Save Draft' : $t('composeModal.send') }}
          </button>
          <button
            @click="showSchedulePicker = !showSchedulePicker"
            class="btn-primary !rounded-l-none px-1.5 border-l border-white/25"
            :disabled="compose.sending || compose.draft.to.length === 0"
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

            <button
              @click="scheduleQuick('tomorrow_morning')"
              class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg text-amber-500">light_mode</span>
              <div>
                <div class="text-surface-900 dark:text-surface-100">{{ $t('composeModal.tomorrowMorning') }}</div>
                <div class="text-xs text-surface-500">{{ getTomorrowMorningLabel() }}</div>
              </div>
            </button>

            <button
              @click="scheduleQuick('tomorrow_afternoon')"
              class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg text-orange-500">wb_twilight</span>
              <div>
                <div class="text-surface-900 dark:text-surface-100">{{ $t('composeModal.tomorrowAfternoon') }}</div>
                <div class="text-xs text-surface-500">{{ getTomorrowAfternoonLabel() }}</div>
              </div>
            </button>

            <button
              @click="scheduleQuick('next_monday')"
              class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-3"
            >
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
                <input
                  v-model="customScheduleDate"
                  type="date"
                  :min="new Date().toISOString().split('T')[0]"
                  class="input text-sm flex-1"
                />
                <input
                  v-model="customScheduleTime"
                  type="time"
                  class="input text-sm w-24"
                />
              </div>
              <button
                @click="handleCustomSchedule(); showSchedulePicker = false"
                :disabled="!customScheduleDate || !customScheduleTime"
                class="w-full btn-primary text-sm py-1.5"
              >
                <span class="material-symbols-rounded text-base">schedule_send</span>
                {{ $t('composeModal.schedule') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Backdrop for schedule picker -->
        <div
          v-if="showSchedulePicker"
          class="fixed inset-0 z-40"
          @click="showSchedulePicker = false"
        ></div>
      </div>
    </template>
  </Modal>
  
  <!-- Drive Picker Modal -->
  <Modal 
    :show="showDrivePicker" 
    :title="$t('composeModal.attachFromDrive')"
    size="lg"
    @close="closeDrivePicker"
  >
    <!-- Breadcrumb navigation -->
    <div class="flex items-center gap-1 text-sm mb-4 overflow-x-auto">
      <template v-for="(crumb, idx) in drivePickerPath" :key="crumb.id ?? 'root'">
        <span v-if="idx > 0" class="text-surface-400">/</span>
        <button 
          @click="navigateDrivePickerTo(crumb.id, crumb.name)"
          :class="[
            'px-2 py-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors whitespace-nowrap',
            idx === drivePickerPath.length - 1 ? 'font-semibold text-primary-500' : 'text-surface-600 dark:text-surface-400'
          ]"
        >
          {{ crumb.name }}
        </button>
      </template>
    </div>
    
    <!-- Loading state -->
    <div v-if="drivePickerLoading" class="flex items-center justify-center py-12">
      <span class="spinner text-primary-500"></span>
    </div>
    
    <!-- Empty state -->
    <div v-else-if="drivePickerFolders.length === 0 && drivePickerFiles.length === 0" class="text-center py-12 text-surface-500">
      <span class="material-symbols-rounded text-5xl mb-2">folder_off</span>
      <p>{{ $t('composeModal.thisFolderIsEmpty') }}</p>
    </div>
    
    <!-- Files and folders list -->
    <div v-else class="max-h-96 overflow-y-auto space-y-1">
      <!-- Folders -->
      <button
        v-for="folder in drivePickerFolders"
        :key="'folder-' + folder.id"
        @click="navigateDrivePickerTo(folder.id, folder.name)"
        class="w-full flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
      >
        <span class="material-symbols-rounded text-xl text-yellow-500">folder</span>
        <span class="flex-1 truncate">{{ folder.name }}</span>
        <span class="material-symbols-rounded text-surface-400">chevron_right</span>
      </button>
      
      <!-- Files -->
      <button
        v-for="file in drivePickerFiles"
        :key="'file-' + file.id"
        @click="toggleDriveFileSelection(file)"
        :class="[
          'w-full flex items-center gap-3 px-3 py-2 rounded-lg transition-colors text-left',
          isDriveFileSelected(file) 
            ? 'bg-primary-100 dark:bg-primary-500/20 ring-2 ring-primary-500'
            : 'hover:bg-surface-100 dark:hover:bg-surface-700'
        ]"
      >
        <span :class="['material-symbols-rounded text-xl', isDriveFileSelected(file) ? 'text-primary-500' : 'text-surface-400']">
          {{ isDriveFileSelected(file) ? 'check_circle' : 'description' }}
        </span>
        <div class="flex-1 min-w-0">
          <p class="truncate text-sm">{{ file.original_name }}</p>
          <p class="text-xs text-surface-500">{{ formatSize(file.size) }}</p>
        </div>
        <span v-if="file.share_token" class="text-xs px-2 py-0.5 rounded bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400">
          {{ $t('composeModal.shared') }}
        </span>
      </button>
    </div>
    
    <!-- Selection summary -->
    <div v-if="selectedDriveFiles.length > 0" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
      <p class="text-sm text-surface-600 dark:text-surface-400">
        {{ $t('composeModal.filesSelected', { count: selectedDriveFiles.length }) }}
      </p>
    </div>
    
    <template #footer>
      <div class="flex items-center justify-between w-full">
        <p class="text-xs text-surface-500">
          <span class="material-symbols-rounded text-sm align-middle">info</span>
          {{ $t('composeModal.driveShareInfo') }}
        </p>
        <div class="flex items-center gap-2">
          <button @click="closeDrivePicker" class="btn-secondary">
            {{ $t('composeModal.cancel') }}
          </button>
          <button 
            @click="attachSelectedDriveFiles" 
            class="btn-primary"
            :disabled="selectedDriveFiles.length === 0"
          >
            <span class="material-symbols-rounded">attach_file</span>
            {{ selectedDriveFiles.length > 0 ? $t('composeModal.attachWithCount', { count: selectedDriveFiles.length }) : $t('composeModal.attach') }}
          </button>
        </div>
      </div>
    </template>
  </Modal>
  
  <!-- Bulk Send Confirmation Modal -->
  <Teleport to="body">
    <div 
      v-if="showBulkSendConfirm" 
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-[60] p-4"
      @click.self="cancelBulkSend"
    >
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <!-- Header -->
        <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
            <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">campaign</span>
          </div>
          <div>
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
              {{ compose.forceCampaign ? 'Send as Campaign' : $t('composeModal.bulkEmailDetected') }}
            </h3>
            <p class="text-sm text-surface-500">
              {{ compose.forceCampaign ? 'This email will be queued as a campaign with tracking and unsubscribe support.' : $t('composeModal.largeRecipientListWillBe') }}
            </p>
          </div>
        </div>
        
        <!-- Content -->
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
          
          <p class="text-sm text-surface-600 dark:text-surface-400">
            {{ $t('composeModal.bulkSendRateLimitInfo') }}
          </p>
        </div>
        
        <!-- Footer -->
        <div class="p-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
          <button @click="cancelBulkSend" class="btn-secondary">
            {{ $t('composeModal.cancel') }}
          </button>
          <button @click="handleBulkSend" class="btn-primary">
            <span class="material-symbols-rounded">send</span>
            {{ $t('composeModal.queueCampaign') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.spinner-sm {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.btn-icon {
  @apply w-8 h-8 flex items-center justify-center rounded-lg
         text-surface-500 hover:text-surface-700 dark:hover:text-surface-200
         hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]
         transition-colors;
}

/* Visually collapse the signature block inside the editor (it still ships in the email) */
.compose-signature-collapsed :deep([data-signature]) {
  display: none;
}

/* Render the compose action footer (Attach / Drive / Schedule / Important /
   Save Draft / Send) at COMPACT proportions in COSY density, without resizing
   the window — so Save Draft / Send are never clipped.

   The footer is a non-wrapping flex row of 6 .shrink-0 buttons. It overflows in
   cosy purely because the buttons are big: each btn-* bakes in `@apply btn`
   (px-5 = 1.25rem side padding, 14px text), so ~190px of width across the row is
   pure chrome. Compact already trims this via `.density-compact .btn`
   (0.375rem 0.75rem / 12px) plus a tighter footer and 20px icons.

   We reproduce ALL of that for THIS footer only, in cosy. The button rules must
   target the concrete .btn-ghost/.btn-secondary/.btn-primary classes — the
   elements carry those, not a literal `.btn` class (`btn` is inlined via @apply),
   so a `.btn` selector would never match them. Editor, recipients, window size
   and every other modal/UI are left exactly as they are.

   NOTE: the side padding lives on the parent .modal-footer (owned by Modal.vue),
   and the footer row itself is a flex item — it cannot reclaim that space via its
   own width (flex-shrink cancels it). We therefore target the real .modal-footer
   element via :has(), matched only when it contains this compose footer.

   CRITICAL: every selector here wraps its FULL chain in :global(...). The mixed
   form `:global(.density-cosy) .compose-footer-actions .btn-ghost` is mis-compiled
   by the scoped-CSS transform — it drops everything after :global() and collapses
   to a bare `.density-cosy{...}`, which silently does nothing (and leaks onto
   <html>). Keeping the whole chain inside :global() is the only form that survives
   the build. `.compose-footer-actions` is unique to this footer, so going fully
   global is safe and scopes the effect to exactly these buttons. */
:global(.density-cosy .modal-footer:has(.compose-footer-actions)) {
  padding-left: 1rem;
  padding-right: 1rem;
}

:global(.density-cosy .compose-footer-actions .material-symbols-rounded) {
  font-size: 18px;
}

:global(.density-cosy .compose-footer-actions .btn-ghost),
:global(.density-cosy .compose-footer-actions .btn-secondary),
:global(.density-cosy .compose-footer-actions .btn-primary) {
  padding: 0.25rem 0.375rem;
  font-size: 0.6875rem;
  line-height: 1rem;
  gap: 0.25rem;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
