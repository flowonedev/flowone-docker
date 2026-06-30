<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick, defineAsyncComponent } from 'vue'
import { useRouter } from 'vue-router'
import { useMailboxStore } from '@/stores/mailbox'
import { useComposeStore } from '@/stores/compose'
import { useToastStore } from '@/stores/toast'
import { useLabelsStore } from '@/stores/labels'
import { useLayoutStore } from '@/stores/layout'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useSpamStore } from '@/stores/spam'
import { useThemeStore } from '@/stores/theme'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useDriveStore } from '@/stores/drive'
import { useAddons } from '@/composables/useAddons'
import { useOfficeStatus } from '@/composables/useOfficeStatus'
import { isDebugEnabled } from '@/utils/debug'
import ConfirmModal from './shared/ConfirmModal.vue'
import LabelPicker from './LabelPicker.vue'
import AttachmentPreview from './AttachmentPreview.vue'
import ReactionPicker from '@/addons/reactions/components/ReactionPicker.vue'
import ReactionDisplay from '@/addons/reactions/components/ReactionDisplay.vue'
import IncomingReactionCard from '@/addons/reactions/components/IncomingReactionCard.vue'
import EmailOriginalModal from './EmailOriginalModal.vue'
import SaveToDriveModal from './SaveToDriveModal.vue'
import api from '@/services/api'
import { folderCollectionUrl } from '@/services/mailRouteService'
import { getToken } from '@/services/tokenStorage'
import { processEmailContent } from '@/services/emailContentProcessor'
import EmailIframe from '@/components/EmailIframe.vue'
import MeetingInviteActions from '@/components/MeetingInviteActions.vue'
import { debugLog } from '@/utils/debug'
// Collab editing imports
import { useCollabStore } from '@collab/stores/collabStore'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// Lazy load heavy collab components to separate chunks
const CollabDocumentEditor = defineAsyncComponent(() => 
  import('@collab/components/CollabDocumentEditor.vue')
)
const CollabPresentationEditor = defineAsyncComponent(() => 
  import('@collab/components/CollabPresentationEditor.vue')
)
const CollabShareModal = defineAsyncComponent(() => 
  import('@collab/components/CollabShareModal.vue')
)

const props = defineProps({
  showPlaceholder: {
    type: Boolean,
    default: true
  }
})

const router = useRouter()
const mailbox = useMailboxStore()
const compose = useComposeStore()
const toast = useToastStore()
const labelsStore = useLabelsStore()
const layout = useLayoutStore()
const todosStore = useTodosStore()
const { tasksEnabled, kanbanBoardsEnabled, reactionsEnabled, aiAssistantEnabled } = useAddons()
const settingsStore = useSettingsStore()
const authStore = useAuthStore()
const aiStore = useAIStore()
const spamStore = useSpamStore()
const themeStore = useThemeStore()
const calendarStore = useCalendarStore()
const boardsStore = useBoardsStore()
const collabStore = useCollabStore()
const driveStore = useDriveStore()
// OnlyOffice availability for the attachment "Open in editor" action
// (lazy one-shot fetch, shared app-wide).
const { ensureOfficeStatus, canEditInOffice } = useOfficeStatus()
ensureOfficeStatus()

// Computed to track trusted senders reactively for template re-rendering
// This fixes the issue where the "remote images blocked" banner doesn't update
// when trustedSenders loads after the message is already displayed
// Uses trustedSendersVersion for more reliable reactivity than length
const trustedSendersKey = computed(() => settingsStore.trustedSendersVersion || 0)

// Calendar invite state
const addingToCalendar = ref(false)
const calendarPickerMsg = ref(null)
// RSVP busy state: map uid -> 'accepted'|'declined'|'tentative' while in-flight.
// One response per invite: once a response is recorded we never send again.
const rsvpBusyByUid = ref({})

function rsvpBusyFor(msg) {
  if (!msg) return ''
  return rsvpBusyByUid.value[msg.uid] || ''
}

async function handleRsvp(msg, status) {
  if (!msg?.calendar_event) return
  if (!['accepted', 'declined', 'tentative'].includes(status)) return
  // Already responded -> locked, no re-sending / spamming.
  if (msg.calendar_event.my_response) return
  // A send is already in flight for this message.
  if (rsvpBusyByUid.value[msg.uid]) return

  const folder = msg.folder || mailbox.currentFolder
  if (!folder) {
    toast.error(t('meetingInvite.errorGeneric'))
    return
  }

  rsvpBusyByUid.value = { ...rsvpBusyByUid.value, [msg.uid]: status }
  try {
    const url = folderCollectionUrl(mailbox.folders, folder, `messages/${msg.uid}/rsvp`)
    const headers = {}
    const activeAccountId = getToken('webmail_active_account')
    if (activeAccountId && activeAccountId !== 'primary') {
      headers['X-Account-Id'] = activeAccountId
    }
    const response = await api.post(url, { response: status }, { headers })
    if (response.data?.success) {
      // Mutate in-place so the UI immediately reflects (and locks) the selection.
      if (msg.calendar_event && typeof msg.calendar_event === 'object') {
        msg.calendar_event.my_response = status
      }
      const labelKey = status === 'accepted'
        ? 'meetingInvite.toastAccepted'
        : status === 'declined'
          ? 'meetingInvite.toastDeclined'
          : 'meetingInvite.toastMaybe'
      toast.success(t(labelKey))
    } else {
      toast.error(response.data?.message || t('meetingInvite.errorGeneric'))
    }
  } catch (e) {
    console.error('RSVP failed:', e)
    const apiMsg = e?.response?.data?.message
    toast.error(apiMsg || t('meetingInvite.errorGeneric'))
  } finally {
    const next = { ...rsvpBusyByUid.value }
    delete next[msg.uid]
    rsvpBusyByUid.value = next
  }
}

async function handleAddToCalendarClick(msg) {
  if (!calendarStore.calendars?.length) {
    await calendarStore.fetchCalendars()
  }
  if (calendarStore.calendars?.length === 1) {
    addEventToCalendar(msg, calendarStore.calendars[0].id)
  } else {
    calendarPickerMsg.value = calendarPickerMsg.value?.uid === msg.uid ? null : msg
  }
}

// Close the calendar picker dropdown on any outside click. The picker
// is rendered inside the iframe-adjacent message body, so it needs a
// global click listener (the iframe itself can't see clicks that
// happen outside its body). Listener is registered on the capture
// phase so it fires before any in-app element can stopPropagation.
function handleCalendarPickerOutsideClick(e) {
  if (!calendarPickerMsg.value) return
  // The picker root carries this data attribute; if the click is
  // inside it, leave it open.
  if (e.target?.closest?.('[data-calendar-picker]')) return
  calendarPickerMsg.value = null
}

onMounted(() => {
  document.addEventListener('click', handleCalendarPickerOutsideClick, true)
})

onUnmounted(() => {
  document.removeEventListener('click', handleCalendarPickerOutsideClick, true)
})

async function addEventToCalendar(msg, calendarId = null) {
  if (!msg?.calendar_event) return
  const evt = msg.calendar_event
  addingToCalendar.value = true
  calendarPickerMsg.value = null
  try {
    const attendeeEmails = (evt.attendees || [])
      .map(a => a.email || a)
      .filter(Boolean)
    
    const organizerEmail = evt.organizer_email || ''
    
    // Build participants list from attendees + organizer
    const allEmails = [...new Set([
      ...(organizerEmail ? [organizerEmail] : []),
      ...attendeeEmails,
    ])]
    const participants = allEmails.map(email => ({ email, status: 'accepted' }))
    
    const payload = {
      title: evt.summary || msg.subject || 'Meeting',
      description: evt.description || '',
      location: evt.location || '',
      start_time: evt.dtstart || '',
      end_time: evt.dtend || '',
      participants,
    }
    if (calendarId) payload.calendar_id = calendarId
    
    const response = await api.post('/events', payload)
    if (response.data?.success) {
      toast.success('Event added to calendar')
    } else {
      toast.error('Failed to add event')
    }
  } catch (e) {
    console.error('Add to calendar failed:', e)
    toast.error('Failed to add event to calendar')
  } finally {
    addingToCalendar.value = false
  }
}

// Collab editor state
const showCollabEditor = ref(false)
const collabEditorMode = ref('document')
const collabDocumentId = ref(null)
const collabDocumentTitle = ref('')
const showCollabShareModal = ref(false)

// Open collab share modal
function openCollabShareModal() {
  if (collabDocumentId.value) {
    showCollabShareModal.value = true
  }
}

// Board linking
const showBoardLinkMenu = ref(false)
const linkedBoard = ref(null)
const linkedBoards = ref([]) // All boards this email is linked to (full objects)
const linkedBoardIds = ref([]) // All boards this email is linked to
const boardLinkLoading = ref(false)

// Load reactions using dynamic import to avoid circular dependency
async function loadReactionsForMessage(messageId) {
  if (!reactionsEnabled.value) return
  if (!messageId) return
  
  try {
    const { useReactionsStore } = await import('@/addons/reactions/stores/reactions')
    const reactionsStore = useReactionsStore()
    await reactionsStore.fetchReactions(messageId)
  } catch (e) {
    console.error('Failed to fetch reactions:', e)
  }
}

// Note: Watch for message changes is set up after `message` is defined (see below)

// Helper to extract emails from various formats
function extractEmails(field) {
  const emails = []
  if (!field) return emails
  
  // If it's an array
  if (Array.isArray(field)) {
    field.forEach(item => {
      if (typeof item === 'string') {
        emails.push(item.toLowerCase())
      } else if (item?.email) {
        emails.push(item.email.toLowerCase())
      }
    })
  } 
  // If it's an object with email property
  else if (typeof field === 'object' && field.email) {
    emails.push(field.email.toLowerCase())
  }
  // If it's a string
  else if (typeof field === 'string') {
    emails.push(field.toLowerCase())
  }
  
  return emails
}

// Get all participants (from, to, cc) for a message
function getMessageParticipants(msg) {
  if (!msg) return []
  
  const participants = new Set()
  
  // Add from
  extractEmails(msg.from).forEach(e => participants.add(e))
  
  // Also check from_email field
  if (msg.from_email) {
    participants.add(msg.from_email.toLowerCase())
  }
  
  // Add to
  extractEmails(msg.to).forEach(e => participants.add(e))
  
  // Add cc
  extractEmails(msg.cc).forEach(e => participants.add(e))
  
  // Add current user
  if (authStore?.user?.email) {
    participants.add(authStore.user.email.toLowerCase())
  }
  
  return Array.from(participants)
}

// Add reaction using dynamic import
async function addReactionToMessage(messageId, emoji, participants, subject, snippet) {
  try {
    const { useReactionsStore } = await import('@/addons/reactions/stores/reactions')
    const reactionsStore = useReactionsStore()
    const result = await reactionsStore.addReaction(messageId, emoji, participants, subject, snippet)
    if (result?.action === 'added') {
      toast.success(t('emailView.reactionAdded'))
    } else if (result) {
      toast.info(t('emailView.reactionRemoved'))
    }
  } catch (e) {
    console.error('Failed to add reaction:', e)
    toast.error(t('emailView.failedToAddReaction'))
  }
}

// Get snippet from message body
function getMessageSnippet(msg) {
  if (msg?.body_text) {
    return msg.body_text.substring(0, 200)
  }
  return ''
}

// Handle reaction added (from ReactionPicker component)
function handleReactionAdded(result) {
  if (result?.action === 'added') {
    toast.success(t('emailView.reactionAdded'))
  } else if (result) {
    toast.info(t('emailView.reactionRemoved'))
  }
}

// Handle reaction click on ReactionDisplay
function handleReactionClick(reaction, msg) {
  addReactionToMessage(
    msg.message_id, 
    reaction.emoji, 
    getMessageParticipants(msg), 
    msg.subject, 
    getMessageSnippet(msg)
  )
}

// Image blocking - track which messages have loaded images
const loadedImagesForUids = ref(new Set())

const showDeleteConfirm = ref(false)
const showMoveMenu = ref(null)
const showMoreMenu = ref(null)
const showMobileMoveFolderMenu = ref(false)

// --- Responsive toolbar: progressively collapse action icons into the more menu ---
// Tracks the EmailView root width via ResizeObserver. Each optional action has a
// minimum container width below which it moves from the toolbar into the 3-dot menu.
// Sender text + date stay visible; only icons collapse so the text gets more room.
const emailViewRoot = ref(null)
const emailViewWidth = ref(2000)
let emailViewResizeObserver = null

// Order matters: earlier entries collapse FIRST (rightmost icon -> menu first).
// Threshold = the container width (px) below which the action moves to the menu.
const ACTION_COLLAPSE_THRESHOLDS = {
  boards: 880,
  todo: 800,
  reactions: 720,
  forward: 640,
  replyAll: 560,
}

function isActionInToolbar(action) {
  const threshold = ACTION_COLLAPSE_THRESHOLDS[action]
  if (threshold === undefined) return true
  return emailViewWidth.value >= threshold
}

function isActionInMenu(action) {
  return !isActionInToolbar(action)
}

// True when at least one toolbar action is currently collapsed into the menu,
// so we render a divider between the collapsed group and the regular menu items.
const hasCollapsedActions = computed(() => {
  if (!isDraft.value) {
    if (isActionInMenu('replyAll')) return true
    if (isActionInMenu('forward')) return true
    if (reactionsEnabled.value && isActionInMenu('reactions')) return true
  }
  if (tasksEnabled.value && isActionInMenu('todo')) return true
  if (kanbanBoardsEnabled.value && isActionInMenu('boards')) return true
  return false
})

// Click anywhere on the menu-embedded reaction row to open the inline picker.
// We forward the click to the picker's internal trigger button.
function openInlineReactionPickerFromRow(event) {
  const btn = event.currentTarget?.querySelector?.('button.reaction-trigger')
  btn?.click()
}
const downloadingAttachment = ref(null)
const showAttachmentPreview = ref(false)
const previewAttachmentIndex = ref(0)
const previewMessage = ref(null) // Track which message's attachments we're previewing

// Move menu position (fixed positioning)
const moveMenuPosition = ref({ x: 0, y: 0 })

// Label picker position (fixed positioning)
const labelPickerPosition = ref({ x: 0, y: 0 })

// Attachments popout state
const showAttachmentsPopout = ref(null) // UID of message with open popout
const attachmentsPopoutRef = ref(null)
const attachmentsPopoutPosition = ref({ x: 0, y: 0 })
const ATTACHMENTS_PREVIEW_LIMIT = 6 // Show this many before grouping

// User-collapsed attachment panels (default: expanded / not in set)
const collapsedAttachmentsMessages = ref(new Set())

function isAttachmentsExpanded(msgUid) {
  return !collapsedAttachmentsMessages.value.has(msgUid)
}

function toggleAttachmentsExpanded(msgUid) {
  if (collapsedAttachmentsMessages.value.has(msgUid)) {
    collapsedAttachmentsMessages.value.delete(msgUid)
  } else {
    collapsedAttachmentsMessages.value.add(msgUid)
  }
  collapsedAttachmentsMessages.value = new Set(collapsedAttachmentsMessages.value)
}

// Authenticated image thumbnails (same pattern as SuperSearch.vue)
const attachmentThumbnailCache = ref({})
const failedAttachmentThumbnails = ref(new Set())

function getMessageFolderForAttachments(msg) {
  return msg?.folder || message.value?.folder || mailbox.currentFolder
}

function revokeAllAttachmentThumbnails() {
  const c = attachmentThumbnailCache.value
  for (const k of Object.keys(c)) {
    if (k.endsWith('_loading')) continue
    const url = c[k]
    if (typeof url === 'string' && url.startsWith('blob:')) {
      URL.revokeObjectURL(url)
    }
  }
  attachmentThumbnailCache.value = {}
  failedAttachmentThumbnails.value = new Set()
}

function isImageAttachment(attachment) {
  const f = attachment.filename?.toLowerCase() || ''
  const t = attachment.type?.toLowerCase() || ''
  return t.startsWith('image/') || /\.(jpe?g|png|gif|webp|bmp)$/.test(f)
}

function attachmentThumbFailed(msg, attachment) {
  return failedAttachmentThumbnails.value.has(`${msg.uid}_${attachment.part}`)
}

function getAttachmentThumbnail(msg, attachment) {
  const cacheKey = `${msg.uid}_${attachment.part}`
  if (attachmentThumbFailed(msg, attachment)) return ''
  if (attachmentThumbnailCache.value[cacheKey]) {
    return attachmentThumbnailCache.value[cacheKey]
  }
  if (!attachmentThumbnailCache.value[`${cacheKey}_loading`]) {
    attachmentThumbnailCache.value = {
      ...attachmentThumbnailCache.value,
      [`${cacheKey}_loading`]: true,
    }
    loadAttachmentThumbnail(msg, attachment, cacheKey)
  }
  return ''
}

async function loadAttachmentThumbnail(msg, attachment, cacheKey) {
  try {
    const authHeaders = { Authorization: `Bearer ${getToken('webmail_token')}` }
    const sessionToken = getToken('webmail_session_token')
    if (sessionToken) authHeaders['X-Session-Token'] = sessionToken
    const activeAccountId = getToken('webmail_active_account')
    if (activeAccountId && activeAccountId !== 'primary') {
      authHeaders['X-Account-Id'] = activeAccountId
    }
    const folder = getMessageFolderForAttachments(msg)
    const url = `${api.defaults.baseURL}${folderCollectionUrl(mailbox.folders, folder, `messages/${msg.uid}/attachments/${attachment.part}/thumbnail?size=120`)}`
    const response = await fetch(url, {
      headers: authHeaders,
      credentials: 'include',
    })
    if (response.ok) {
      const blob = await response.blob()
      const next = {
        ...attachmentThumbnailCache.value,
        [cacheKey]: URL.createObjectURL(blob),
      }
      delete next[`${cacheKey}_loading`]
      attachmentThumbnailCache.value = next
    } else {
      // Mark as failed so getAttachmentThumbnail returns '' immediately
      // and the template won't schedule another fetch.
      failedAttachmentThumbnails.value.add(cacheKey)
      failedAttachmentThumbnails.value = new Set(failedAttachmentThumbnails.value)
    }
  } catch (_) {
    failedAttachmentThumbnails.value.add(cacheKey)
    failedAttachmentThumbnails.value = new Set(failedAttachmentThumbnails.value)
  }
}

function handleAttachmentThumbnailError(msg, attachment, event) {
  const cacheKey = `${msg.uid}_${attachment.part}`
  const img = event?.target
  if (img?.src?.startsWith('blob:')) {
    URL.revokeObjectURL(img.src)
  }
  const next = { ...attachmentThumbnailCache.value }
  delete next[cacheKey]
  delete next[`${cacheKey}_loading`]
  attachmentThumbnailCache.value = next
  failedAttachmentThumbnails.value.add(cacheKey)
  failedAttachmentThumbnails.value = new Set(failedAttachmentThumbnails.value)
}

// Get visible attachments (limited for display)
function getVisibleAttachments(msg) {
  if (!msg?.attachments?.length) return []
  if (msg.attachments.length <= ATTACHMENTS_PREVIEW_LIMIT) return msg.attachments
  return msg.attachments.slice(0, ATTACHMENTS_PREVIEW_LIMIT)
}

// Get hidden attachment count
function getHiddenAttachmentCount(msg) {
  if (!msg?.attachments?.length) return 0
  return Math.max(0, msg.attachments.length - ATTACHMENTS_PREVIEW_LIMIT)
}

// Toggle attachments popout
function toggleAttachmentsPopout(event, msg) {
  event.stopPropagation()
  if (showAttachmentsPopout.value === msg.uid) {
    showAttachmentsPopout.value = null
    return
  }
  
  // Position the popout near the "See all" button
  const rect = event.currentTarget.getBoundingClientRect()
  attachmentsPopoutPosition.value = {
    x: rect.left,
    y: rect.bottom + 8
  }
  showAttachmentsPopout.value = msg.uid
}

// Close attachments popout on outside click
function closeAttachmentsPopout() {
  showAttachmentsPopout.value = null
}

// Show Original modal
const showOriginalModal = ref(false)
const originalModalRef = ref(null)

// Use shared unsubscribe composable for state sync across components
import { useUnsubscribe } from '@/composables/useUnsubscribe'
const {
  showUnsubscribeConfirm,
  showUnsubscribeUrlConfirm,
  unsubscribingMessage,
  unsubscribing,
  hasUnsubscribe,
  isUnsubscribed,
  getSenderDisplay,
  initiateUnsubscribe,
  cancelUnsubscribe,
  executeUnsubscribe,
  confirmUrlUnsubscribe,
  cancelUrlUnsubscribe,
} = useUnsubscribe()

// Download all Drive attachments state
const downloadingAllDrive = ref(false)
const downloadAllProgress = ref({ current: 0, total: 0 })

// Per-card in-flight guard for single Drive-attachment downloads, keyed by the
// share URL. Prevents the multi-click download storm: each frantic click used
// to fire another full file stream, and every stream pins a PHP worker server
// side, so a few impatient clicks could saturate the worker pool and stall the
// whole VPS. We now ignore repeat clicks while a download was just triggered.
const downloadingDriveKeys = ref(new Set())

function isDownloadingDrive(driveAtt) {
  return downloadingDriveKeys.value.has(driveAtt?.url)
}

function markDriveDownloading(key, on) {
  const next = new Set(downloadingDriveKeys.value)
  if (on) next.add(key)
  else next.delete(key)
  downloadingDriveKeys.value = next
}

// Save to Drive modal state
const showSaveToDriveModal = ref(false)
const saveToDriveMessage = ref(null)

// Track which attachments of which messages have already been saved to
// Drive. Keyed by `${folder}_${uid}` -> { byPart: { [part]: file }, list: [...] }.
// Populated by `loadSavedDriveStatus()` whenever a message with
// attachments enters the visible thread; mutated again after a fresh
// save so the indicator/Share button appear without a page reload.
const savedDriveByMessage = ref({})
// Per-attachment Share button state ("creating link" spinner). Keyed by
// `${msg.uid}_${attachment.part}`.
const sharingDrive = ref(new Set())

function savedDriveKey(msg) {
  if (!msg) return ''
  const folder = msg.folder || mailbox.currentFolder || ''
  return `${folder}_${msg.uid}`
}

async function loadSavedDriveStatus(msg) {
  if (!msg?.uid) return
  if (!msg.attachments?.length) return
  const folder = msg.folder || mailbox.currentFolder
  if (!folder) return
  const key = savedDriveKey(msg)
  // Avoid spamming the endpoint if we already have a result for this key
  // and the attachment list hasn't grown.
  if (savedDriveByMessage.value[key]) return

  // Pass the IMAP attachment list so the server can fall back to
  // filename+size matching for legacy saves (and self-heal the row's
  // source_email_* columns once it finds a match).
  const slim = msg.attachments
    .filter((a) => a?.part != null)
    .map((a) => ({ part: a.part, filename: a.filename, size: a.size }))
  const files = await driveStore.fetchEmailAttachmentsStatus(folder, msg.uid, slim)
  const byPart = {}
  for (const f of files) {
    if (f?.part) byPart[String(f.part)] = f
  }
  savedDriveByMessage.value = {
    ...savedDriveByMessage.value,
    [key]: { byPart, list: files },
  }
}

function getSavedDriveFile(msg, attachment) {
  if (!msg || !attachment?.part) return null
  const key = savedDriveKey(msg)
  const entry = savedDriveByMessage.value[key]
  if (!entry) return null
  return entry.byPart[String(attachment.part)] || null
}

function isSharingDrive(msg, attachment) {
  if (!msg || !attachment?.part) return false
  return sharingDrive.value.has(`${msg.uid}_${attachment.part}`)
}

async function shareSavedAttachment(msg, attachment) {
  const saved = getSavedDriveFile(msg, attachment)
  if (!saved) return

  // Fast path: server already returned a usable share URL.
  if (saved.share_url) {
    await copyShareLinkToClipboard(saved.share_url)
    return
  }

  const sharingKey = `${msg.uid}_${attachment.part}`
  sharingDrive.value.add(sharingKey)
  try {
    const result = await driveStore.ensureShareLink(saved.id)
    if (result?.success && result.url) {
      // Mutate the cached entry so subsequent clicks skip the round trip.
      const key = savedDriveKey(msg)
      const entry = savedDriveByMessage.value[key]
      if (entry?.byPart[String(attachment.part)]) {
        entry.byPart[String(attachment.part)] = {
          ...entry.byPart[String(attachment.part)],
          share_url: result.url,
          share_token: result.token,
        }
      }
      await copyShareLinkToClipboard(result.url)
    } else {
      toast.error(t('emailView.shareLinkFailed') || 'Failed to create share link')
    }
  } finally {
    sharingDrive.value.delete(sharingKey)
  }
}

async function copyShareLinkToClipboard(url) {
  try {
    if (navigator?.clipboard?.writeText) {
      await navigator.clipboard.writeText(url)
    } else {
      const ta = document.createElement('textarea')
      ta.value = url
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
    }
    toast.success(t('emailView.shareLinkCopied') || 'Share link copied')
  } catch (e) {
    // If copy fails (e.g. permissions), fall back to opening the link.
    window.open(url, '_blank', 'noopener,noreferrer')
  }
}

function openSavedDriveFile(msg, attachment) {
  const saved = getSavedDriveFile(msg, attachment)
  if (!saved) return
  const folderId = saved.folder_id || saved.folderId
  if (folderId) {
    router.push({ name: 'drive', query: { folder: folderId, file: saved.id } })
  } else {
    router.push({ name: 'drive', query: { file: saved.id } })
  }
}

function refreshSavedDriveStatus(msg) {
  if (!msg?.uid) return
  const key = savedDriveKey(msg)
  // Drop the cached entry so the next load fetches fresh data.
  if (savedDriveByMessage.value[key]) {
    const next = { ...savedDriveByMessage.value }
    delete next[key]
    savedDriveByMessage.value = next
  }
  return loadSavedDriveStatus(msg)
}

// Download a single Drive attachment via a NATIVE browser download.
//
// The share URL (/drive/share/{token}) is same-origin and the server sends
// Content-Disposition: attachment, so a transient <a download> streams the
// file straight to disk with the browser's own progress UI - instantly, with
// zero RAM buffering. The previous fetch()+blob() approach downloaded the whole
// file into memory first, which is why a 900 MB file sat silently for a long
// time before the save dialog appeared (and could crash the tab). We also
// guard against repeat clicks so one card can't spawn parallel streams.
function downloadDriveFile(driveAtt) {
  if (!driveAtt?.url) return false
  if (isDownloadingDrive(driveAtt)) return false

  const key = driveAtt.url
  markDriveDownloading(key, true)

  try {
    // Make sure the server can label the saved file: prefer an existing ?fn=,
    // otherwise append the known name (server falls back to the DB name too).
    let href = driveAtt.url
    try {
      const u = new URL(driveAtt.url, window.location.origin)
      if (!u.searchParams.get('fn') && driveAtt.name) {
        u.searchParams.set('fn', driveAtt.name)
      }
      href = u.toString()
    } catch (_) { /* keep original url */ }

    const link = document.createElement('a')
    link.href = href
    link.download = driveAtt.name || 'download'
    link.rel = 'noopener'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    return true
  } catch (e) {
    console.error('Drive file download failed:', e)
    window.open(driveAtt.url, '_blank')
    return false
  } finally {
    // Short cooldown so the spinner is visible and rapid double/triple clicks
    // are swallowed, while still allowing a deliberate re-download later.
    setTimeout(() => markDriveDownloading(key, false), 1500)
  }
}

// Download all Drive attachments one by one (each is now an instant native
// trigger). Spacing avoids the browser's "download multiple files" throttle.
async function downloadAllDriveAttachments(msg) {
  const driveAttachments = getDriveAttachments(msg)
  if (driveAttachments.length === 0) return
  if (downloadingAllDrive.value) return

  downloadingAllDrive.value = true
  downloadAllProgress.value = { current: 0, total: driveAttachments.length }

  for (let i = 0; i < driveAttachments.length; i++) {
    downloadAllProgress.value.current = i + 1
    downloadDriveFile(driveAttachments[i])

    if (i < driveAttachments.length - 1) {
      await new Promise(resolve => setTimeout(resolve, 600))
    }
  }

  setTimeout(() => {
    downloadingAllDrive.value = false
    downloadAllProgress.value = { current: 0, total: 0 }
    toast.success(t('emailView.downloadedFromDrive', { count: driveAttachments.length }))
  }, 500)
}

// Open Save to Drive modal for a message's attachments
function openSaveToDrive(msg, singleAttachment = null) {
  const targetMsg = msg || message.value
  saveToDriveMessage.value = {
    ...targetMsg,
    // If single attachment provided, only save that one
    attachments: singleAttachment ? [singleAttachment] : targetMsg.attachments
  }
  showSaveToDriveModal.value = true
}

function closeSaveToDrive() {
  showSaveToDriveModal.value = false
  saveToDriveMessage.value = null
}

// Called when SaveToDriveModal reports a successful save. Refreshes the
// per-message saved-status cache so the cloud_done badge + Share button
// appear on the original attachment cards without a page reload, then
// closes the modal.
async function onAttachmentsSavedToDrive() {
  const target = saveToDriveMessage.value
  if (target) {
    // Modal closes on a 1s timer after saving; kick off the refresh
    // immediately so the indicator appears as soon as the modal slides
    // away. We pass the original message reference (not the wrapped
    // single-attachment shape) so the cache key stays stable.
    const matched = conversationMessages.value.find(m => m.uid === target.uid)
    await refreshSavedDriveStatus(matched || target)
  }
  closeSaveToDrive()
}

// Text selection for todos
const selectedText = ref('')
const selectionPosition = ref({ x: 0, y: 0 })
const showSelectionTooltip = ref(false)

function handleTextSelection(e) {
  // Small delay to let selection settle (especially for double/triple clicks)
  setTimeout(() => {
    const selection = window.getSelection()
    const text = selection?.toString().trim()

    // Don't show the "Add to Todo" tooltip when the selection is inside an
    // editable rich-text editor (compose / inline reply). That editor has its
    // own combined selection popout (color + highlight + Add to Todo), so the
    // two would otherwise overlap.
    const anchor = selection?.anchorNode
    const anchorEl = anchor?.nodeType === 1 ? anchor : anchor?.parentElement
    if (anchorEl?.closest?.('.ProseMirror, [contenteditable="true"]')) {
      showSelectionTooltip.value = false
      selectedText.value = ''
      return
    }
    
    // Allow selections up to 2000 chars for todos (truncate if needed)
    if (text && text.length > 0) {
      // Store up to 2000 chars (will truncate for display if needed)
      selectedText.value = text.substring(0, 2000)
      
      // Get position of selection
      try {
        const range = selection.getRangeAt(0)
        const rect = range.getBoundingClientRect()
        
        // Make sure rect is valid (sometimes getBoundingClientRect returns zeros)
        if (rect.width > 0 && rect.height > 0) {
          selectionPosition.value = {
            x: Math.min(rect.left + rect.width / 2, window.innerWidth - 100),
            y: Math.max(rect.top - 10, 50)
          }
          showSelectionTooltip.value = true
        }
      } catch (err) {
        // Selection might be cleared, ignore
      }
    } else {
      showSelectionTooltip.value = false
      selectedText.value = ''
    }
  }, 10)
}

function hideSelectionTooltip(e) {
  // Don't hide if clicking on the tooltip itself
  if (e?.target?.closest('.selection-tooltip')) {
    return
  }
  
  // Don't hide if clicking inside email body (user might be starting a new selection)
  if (e?.target?.closest('.email-body')) {
    return
  }
  
  // Small delay to allow clicking the tooltip button
  setTimeout(() => {
    if (!document.querySelector('.selection-tooltip:hover')) {
      showSelectionTooltip.value = false
    }
  }, 100)
}

async function createTodoFromSelection() {
  if (!selectedText.value || !message.value) return
  
  const todo = await todosStore.createFromEmail({
    folder: mailbox.currentFolder,
    uid: message.value.uid,
    message_id: message.value.message_id,
    subject: message.value.subject,
    from_email: message.value.from?.[0]?.email,
    date: message.value.date
  }, selectedText.value)
  
  if (todo) {
    toast.success(t('emailView.todoCreatedFromSelection'))
    showSelectionTooltip.value = false
    selectedText.value = ''
    window.getSelection()?.removeAllRanges()
    todosStore.openPanel()
  }
}

async function addEmailToTodo() {
  if (!message.value) return
  
  const todo = await todosStore.createFromEmail({
    folder: mailbox.currentFolder,
    uid: message.value.uid,
    message_id: message.value.message_id,
    subject: message.value.subject,
    from_email: message.value.from?.[0]?.email,
    date: message.value.date,
    snippet: message.value.body_text?.substring(0, 200)
  })
  
  if (todo) {
    toast.success(t('emailView.emailAddedToTodos'))
    todosStore.openPanel()
  }
}

// Board linking
async function toggleBoardLinkMenu() {
  showBoardLinkMenu.value = !showBoardLinkMenu.value
  if (showBoardLinkMenu.value) {
    // Fetch boards list if needed
    if (boardsStore.boards.length === 0) {
      await boardsStore.fetchBoards()
    }
  }
}

function isLinkedToBoard(boardId) {
  return linkedBoardIds.value.includes(boardId)
}

function goToBoardById(boardId) {
  showBoardLinkMenu.value = false
  router.push(`/boards/${boardId}`)
}

// Open board in sidebar panel
function openBoardInSidebar(boardId) {
  todosStore.openPanelWithBoard(boardId)
}

async function linkToBoard(boardId) {
  if (!message.value) return
  
  // Don't link if already linked
  if (isLinkedToBoard(boardId)) {
    toast.info(t('emailView.emailIsAlreadyLinkedTo'))
    return
  }
  
  boardLinkLoading.value = true
  try {
    const emailData = {
      email_uid: message.value.uid,
      email_folder: mailbox.currentFolder,
      email_subject: message.value.subject,
      email_from: message.value.from?.[0]?.email,
      thread_id: message.value.message_id
    }
    
    const link = await boardsStore.linkEmailToBoard(boardId, emailData)
    if (link) {
      linkedBoard.value = await boardsStore.getEmailBoard(message.value.uid, mailbox.currentFolder)
      // Refresh all linked boards
      if (message.value?.message_id) {
        const boards = await boardsStore.getBoardsByThread(message.value.message_id)
        linkedBoards.value = boards || []
        linkedBoardIds.value = boards?.map(b => b.id) || []
      }
      toast.success(t('emailView.emailLinkedToBoard'))
      } else {
        toast.error(t('emailView.failedToLinkEmail'))
    }
  } catch (e) {
    console.error('Failed to link email:', e)
    toast.error(t('emailView.failedToLinkEmail'))
  } finally {
    boardLinkLoading.value = false
    showBoardLinkMenu.value = false
  }
}

async function unlinkFromBoard() {
  if (!linkedBoard.value?.id) return
  
  boardLinkLoading.value = true
  try {
    const success = await boardsStore.unlinkEmailFromBoard(linkedBoard.value.id)
    if (success) {
      linkedBoard.value = null
      toast.success(t('emailView.emailUnlinkedFromBoard'))
      } else {
        toast.error(t('emailView.failedToUnlinkEmail'))
    }
  } catch (e) {
    console.error('Failed to unlink email:', e)
    toast.error(t('emailView.failedToUnlinkEmail'))
  } finally {
    boardLinkLoading.value = false
  }
}

function goToLinkedBoard() {
  if (linkedBoard.value?.board_id) {
    router.push(`/boards/${linkedBoard.value.board_id}`)
  }
}

onMounted(() => {
  document.addEventListener('mouseup', handleTextSelection)
  document.addEventListener('mousedown', hideSelectionTooltip)

  // Observe EmailView root width so the action toolbar can progressively collapse
  // its icons into the 3-dot menu as the pane shrinks (split-view, narrow window).
  if (emailViewRoot.value && typeof ResizeObserver !== 'undefined') {
    emailViewWidth.value = emailViewRoot.value.getBoundingClientRect().width
    emailViewResizeObserver = new ResizeObserver((entries) => {
      for (const entry of entries) {
        emailViewWidth.value = entry.contentRect.width
      }
    })
    emailViewResizeObserver.observe(emailViewRoot.value)
  }
})

onUnmounted(() => {
  document.removeEventListener('mouseup', handleTextSelection)
  document.removeEventListener('mousedown', hideSelectionTooltip)
  revokeAllAttachmentThumbnails()
  if (emailViewResizeObserver) {
    emailViewResizeObserver.disconnect()
    emailViewResizeObserver = null
  }
})

const message = computed(() => mailbox.currentMessage)

// Get all messages in the conversation thread (for conversation view)
// message.value.messages contains fully fetched messages with body content
// Sorted reverse chronologically (newest first at TOP) like modern email
const allConversationMessages = computed(() => {
  if (!message.value) return []
  
  // If conversation view is enabled and message has conversation data with messages array
  // The messages array is populated in selectMessage() with full body content
  if (mailbox.conversationView && message.value.isConversation && message.value.messages) {
    // Sort by timestamp, newest first (reverse chronological order)
    const msgs = [...message.value.messages]
    msgs.sort((a, b) => (b.timestamp || 0) - (a.timestamp || 0))
    return msgs
  }
  
  // Single message
  return [message.value]
})

// Filtered conversation messages based on thread search
const conversationMessages = computed(() => {
  let msgs = allConversationMessages.value
  
  // Apply keyword search filter
  if (threadSearchQuery.value.trim()) {
    const query = threadSearchQuery.value.toLowerCase().trim()
    msgs = msgs.filter(msg => {
      const subject = (msg.subject || '').toLowerCase()
      const bodyText = (msg.body_text || '').toLowerCase()
      const bodyHtml = (msg.body_html || '').toLowerCase()
      const fromEmail = getSenderEmail(msg)?.toLowerCase() || ''
      const fromName = getSenderName(msg)?.toLowerCase() || ''
      
      return subject.includes(query) ||
             bodyText.includes(query) ||
             bodyHtml.includes(query) ||
             fromEmail.includes(query) ||
             fromName.includes(query)
    })
  }
  
  // Apply date range filter (from date)
  if (threadSearchDateFrom.value) {
    const fromDate = new Date(threadSearchDateFrom.value)
    fromDate.setHours(0, 0, 0, 0)
    msgs = msgs.filter(msg => {
      const msgDate = new Date((msg.timestamp || 0) * 1000)
      return msgDate >= fromDate
    })
  }
  
  // Apply date range filter (to date)
  if (threadSearchDateTo.value) {
    const toDate = new Date(threadSearchDateTo.value)
    toDate.setHours(23, 59, 59, 999)
    msgs = msgs.filter(msg => {
      const msgDate = new Date((msg.timestamp || 0) * 1000)
      return msgDate <= toDate
    })
  }
  
  // Apply sent/received filter
  if (threadSearchDirection.value !== 'all') {
    const wantSent = threadSearchDirection.value === 'sent'
    msgs = msgs.filter(msg => isOurMessage(msg) === wantSent)
  }
  
  return msgs
})

// Count of filtered vs total messages
const filteredMessageCount = computed(() => {
  return {
    filtered: conversationMessages.value.length,
    total: allConversationMessages.value.length
  }
})

// Track which message in a conversation is "active" for actions like reply/forward
const activeMessageUid = ref(null)

// Track collapsed messages (collapsed by default except the last one)
const collapsedMessages = ref(new Set())

// Thread search/filter state
const threadSearchQuery = ref('')
const threadSearchDateFrom = ref('')
const threadSearchDateTo = ref('')
const threadSearchDirection = ref('all') // 'all', 'sent', 'received'
const showThreadSearch = ref(false)
const currentOccurrenceIndex = ref(0) // Current occurrence position (0-based)
const totalOccurrences = ref(0) // Total occurrences found
const threadSearchInput = ref(null) // Ref for search input auto-focus
const messagesContainer = ref(null) // Ref for messages scroll container

// Auto-focus search input when panel opens
watch(showThreadSearch, (isOpen) => {
  if (isOpen) {
    nextTick(() => {
      threadSearchInput.value?.focus()
    })
  }
})

// Clear thread search filters
function clearThreadSearch() {
  threadSearchQuery.value = ''
  threadSearchDateFrom.value = ''
  threadSearchDateTo.value = ''
  threadSearchDirection.value = 'all'
  currentOccurrenceIndex.value = 0
  totalOccurrences.value = 0
  // Remove all current-occurrence markers
  document.querySelectorAll('.search-highlight.current-occurrence').forEach(el => {
    el.classList.remove('current-occurrence')
  })
}

// Check if any thread filter is active
const hasActiveThreadFilter = computed(() => {
  return threadSearchQuery.value.trim() !== '' ||
         threadSearchDateFrom.value !== '' ||
         threadSearchDateTo.value !== '' ||
         threadSearchDirection.value !== 'all'
})

// Get all highlight elements in the document
function getAllOccurrences() {
  // We need to expand all messages first to find all occurrences
  return Array.from(document.querySelectorAll('.search-highlight'))
}

// Update occurrence count after DOM renders
function updateOccurrenceCount() {
  nextTick(() => {
    setTimeout(() => {
      const occurrences = getAllOccurrences()
      totalOccurrences.value = occurrences.length
      if (occurrences.length > 0 && currentOccurrenceIndex.value >= occurrences.length) {
        currentOccurrenceIndex.value = 0
      }
    }, 100)
  })
}

// Navigate to next occurrence
function goToNextMatch() {
  const occurrences = getAllOccurrences()
  if (occurrences.length === 0) return
  
  currentOccurrenceIndex.value = (currentOccurrenceIndex.value + 1) % occurrences.length
  scrollToOccurrence(currentOccurrenceIndex.value)
}

// Navigate to previous occurrence
function goToPrevMatch() {
  const occurrences = getAllOccurrences()
  if (occurrences.length === 0) return
  
  currentOccurrenceIndex.value = currentOccurrenceIndex.value === 0 
    ? occurrences.length - 1 
    : currentOccurrenceIndex.value - 1
  scrollToOccurrence(currentOccurrenceIndex.value)
}

// Scroll to a specific occurrence
function scrollToOccurrence(index) {
  const occurrences = getAllOccurrences()
  if (occurrences.length === 0 || index >= occurrences.length) return
  
  const targetEl = occurrences[index]
  if (!targetEl) return
  
  // Remove previous current-occurrence marker
  document.querySelectorAll('.search-highlight.current-occurrence').forEach(el => {
    el.classList.remove('current-occurrence')
  })
  
  // Mark current occurrence
  targetEl.classList.add('current-occurrence')
  
  // Find the parent message and expand it if needed
  const messageEl = targetEl.closest('[id^="message-"]')
  if (messageEl) {
    const uid = messageEl.id.replace('message-', '')
    // Expand if collapsed
    if (collapsedMessages.value.has(parseInt(uid)) || collapsedMessages.value.has(uid)) {
      collapsedMessages.value.delete(parseInt(uid))
      collapsedMessages.value.delete(uid)
      collapsedMessages.value = new Set(collapsedMessages.value)
    }
  }
  
  // Small delay to allow expansion, then scroll
  setTimeout(() => {
    // Find the scrollable container
    const scrollContainer = targetEl.closest('.overflow-y-auto')
    if (!scrollContainer) {
      targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' })
      return
    }
    
    // Get positions
    const containerRect = scrollContainer.getBoundingClientRect()
    const targetRect = targetEl.getBoundingClientRect()
    const currentScroll = scrollContainer.scrollTop
    
    // Calculate position to center the target
    const targetRelativeTop = targetRect.top - containerRect.top
    const containerCenter = containerRect.height / 2
    const scrollAdjustment = targetRelativeTop - containerCenter + (targetRect.height / 2)
    const newScrollTop = currentScroll + scrollAdjustment
    
    scrollContainer.scrollTo({
      top: Math.max(0, newScrollTop),
      behavior: 'smooth'
    })
  }, 100)
}

// Expand all messages to find all occurrences when searching
function expandAllForSearch() {
  if (!threadSearchQuery.value.trim()) return
  
  // Expand all messages to count all occurrences
  collapsedMessages.value = new Set()
  collapsedAttachmentsMessages.value = new Set()

  // Update count after expansion
  nextTick(() => {
    setTimeout(() => {
      updateOccurrenceCount()
      // Scroll to first occurrence
      if (totalOccurrences.value > 0) {
        currentOccurrenceIndex.value = 0
        scrollToOccurrence(0)
      }
    }, 150)
  })
}

// Watch for search query changes
watch(() => threadSearchQuery.value, (newVal, oldVal) => {
  if (newVal && newVal !== oldVal && newVal.length >= 2) {
    // Expand all messages and find occurrences
    expandAllForSearch()
  } else if (!newVal) {
    totalOccurrences.value = 0
    currentOccurrenceIndex.value = 0
  }
}, { flush: 'post' })

// Also update when filters change
watch([() => threadSearchDateFrom.value, () => threadSearchDateTo.value, () => threadSearchDirection.value], () => {
  if (threadSearchQuery.value) {
    nextTick(() => {
      setTimeout(updateOccurrenceCount, 200)
    })
  }
})

// Scroll listener to track which occurrence is currently visible
let scrollDebounceTimer = null

function handleScrollForOccurrences() {
  if (!threadSearchQuery.value || totalOccurrences.value === 0) return
  
  // Debounce to avoid too many updates
  if (scrollDebounceTimer) clearTimeout(scrollDebounceTimer)
  scrollDebounceTimer = setTimeout(() => {
    updateCurrentOccurrenceFromScroll()
  }, 100)
}

function updateCurrentOccurrenceFromScroll() {
  const occurrences = getAllOccurrences()
  if (occurrences.length === 0) return
  
  const scrollContainer = messagesContainer.value
  if (!scrollContainer) return
  
  const containerRect = scrollContainer.getBoundingClientRect()
  const containerCenter = containerRect.top + containerRect.height / 2
  
  // Find the occurrence closest to the center of the viewport
  let closestIndex = -1
  let closestDistance = Infinity
  
  occurrences.forEach((el, index) => {
    const rect = el.getBoundingClientRect()
    const elCenter = rect.top + rect.height / 2
    const distance = Math.abs(elCenter - containerCenter)
    
    // Only consider elements that are at least partially visible
    if (rect.bottom > containerRect.top && rect.top < containerRect.bottom) {
      if (distance < closestDistance) {
        closestDistance = distance
        closestIndex = index
      }
    }
  })
  
  // If no visible occurrence found, find the closest one overall
  if (closestIndex === -1) {
    occurrences.forEach((el, index) => {
      const rect = el.getBoundingClientRect()
      const elCenter = rect.top + rect.height / 2
      const distance = Math.abs(elCenter - containerCenter)
      if (distance < closestDistance) {
        closestDistance = distance
        closestIndex = index
      }
    })
  }
  
  if (closestIndex === -1) closestIndex = 0
  
  // Update current index if changed
  if (closestIndex !== currentOccurrenceIndex.value) {
    currentOccurrenceIndex.value = closestIndex
    
    // Update visual marker
    document.querySelectorAll('.search-highlight.current-occurrence').forEach(el => {
      el.classList.remove('current-occurrence')
    })
    if (occurrences[closestIndex]) {
      occurrences[closestIndex].classList.add('current-occurrence')
    }
  }
}

// Clean up on unmount
onUnmounted(() => {
  if (scrollDebounceTimer) clearTimeout(scrollDebounceTimer)
})

// Highlight search term in text
function escapeHtml(str) {
  if (str == null) return ''
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function highlightSearchTerm(text, isHtml = false) {
  if (!text || !threadSearchQuery.value.trim()) return text
  
  const query = threadSearchQuery.value.trim()
  if (query.length < 2) return text // Don't highlight very short queries
  
  // Escape regex special characters in the query
  const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const regex = new RegExp(`(${escapedQuery})`, 'gi')
  
  if (isHtml) {
    // For HTML content, we need to be careful not to break tags
    // Split by tags, highlight only text parts
    const parts = text.split(/(<[^>]+>)/g)
    return parts.map(part => {
      if (part.startsWith('<')) return part // This is a tag, don't modify
      return part.replace(regex, '<mark class="search-highlight">$1</mark>')
    }).join('')
  }
  
  return text.replace(regex, '<mark class="search-highlight">$1</mark>')
}

// Initialize collapsed state when conversation changes
watch(() => message.value?.uid, () => {
  activeMessageUid.value = null
  // Collapse all messages except the first one (newest) by default
  collapsedMessages.value = new Set()
  collapsedAttachmentsMessages.value = new Set()
  revokeAllAttachmentThumbnails()
  // Reset thread search filters
  clearThreadSearch()
  showThreadSearch.value = false
  totalOccurrences.value = 0
  currentOccurrenceIndex.value = 0
  
  if (allConversationMessages.value.length > 1) {
    allConversationMessages.value.forEach((msg, idx) => {
      // Collapse all except the first message (newest at top)
      if (idx > 0) {
        collapsedMessages.value.add(msg.uid)
      }
    })
  }
})

// Toggle collapse state for a message
function toggleMessageCollapse(msg) {
  if (collapsedMessages.value.has(msg.uid)) {
    collapsedMessages.value.delete(msg.uid)
  } else {
    collapsedMessages.value.add(msg.uid)
  }
  // Trigger reactivity
  collapsedMessages.value = new Set(collapsedMessages.value)
}

// Check if a message is collapsed
function isMessageCollapsed(msg) {
  return collapsedMessages.value.has(msg.uid)
}

// Expand all messages
function expandAllMessages() {
  collapsedMessages.value = new Set()
  collapsedAttachmentsMessages.value = new Set()
}

// Collapse all but first message (newest)
function collapseAllMessages() {
  collapsedMessages.value = new Set()
  collapsedAttachmentsMessages.value = new Set()
  allConversationMessages.value.forEach((msg, idx) => {
    // Keep first message (newest) expanded
    if (idx > 0) {
      collapsedMessages.value.add(msg.uid)
    }
  })
  collapsedMessages.value = new Set(collapsedMessages.value)
}

// The active message to use for actions (reply, forward, etc.)
// In conversation view, this is the selected message. Otherwise, it's the newest message.
const activeMessage = computed(() => {
  if (!message.value) return null
  
  // If we're in conversation view with multiple messages
  if (conversationMessages.value.length > 1 && activeMessageUid.value) {
    const found = conversationMessages.value.find(m => m.uid === activeMessageUid.value)
    if (found) return found
  }
  
  // Default: Use the first message (newest - since sorted desc)
  if (conversationMessages.value.length > 0) {
    return conversationMessages.value[0]
  }
  
  return message.value
})

// Set active message when clicking on a message in conversation
function setActiveMessage(msg) {
  activeMessageUid.value = msg.uid
  // Expand the message when clicking on it
  if (collapsedMessages.value.has(msg.uid)) {
    collapsedMessages.value.delete(msg.uid)
    collapsedMessages.value = new Set(collapsedMessages.value)
  }
}

// Fetch reactions when message changes (placed here after message is defined)
watch(() => message.value?.message_id, (messageId) => {
  if (messageId) {
    nextTick(() => {
      loadReactionsForMessage(messageId)
    })
  }
}, { immediate: false })

// Check if email is linked to boards
watch(() => message.value?.uid, async (uid) => {
  if (uid && mailbox.currentFolder) {
    // Fetch single board link (for backward compatibility)
    linkedBoard.value = await boardsStore.getEmailBoard(uid, mailbox.currentFolder)
    // Fetch all linked boards by thread
    if (message.value?.message_id) {
      const boards = await boardsStore.getBoardsByThread(message.value.message_id)
      linkedBoards.value = boards || []
      linkedBoardIds.value = boards?.map(b => b.id) || []
    } else {
      linkedBoards.value = []
      linkedBoardIds.value = []
    }
  } else {
    linkedBoard.value = null
    linkedBoards.value = []
    linkedBoardIds.value = []
  }
}, { immediate: true })

// Scroll to specific message when scrollToMessageUid is set
watch(() => mailbox.scrollToMessageUid, (targetUid) => {
  if (targetUid && conversationMessages.value?.length > 0) {
    // Small delay to ensure DOM is updated
    setTimeout(() => {
      nextTick(() => {
        const targetElement = document.getElementById(`message-${targetUid}`)
        if (targetElement) {
          targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' })
          // Add a brief highlight effect
          targetElement.classList.add('highlight-message')
          setTimeout(() => {
            targetElement.classList.remove('highlight-message')
          }, 2000)
        }
        // Clear the scroll target
        mailbox.scrollToMessageUid = null
      })
    }, 100)
  }
}, { immediate: true })

// Also watch for conversation messages loading when we have a pending scroll target
watch(() => conversationMessages.value, (messages) => {
  if (mailbox.scrollToMessageUid && messages?.length > 0) {
    setTimeout(() => {
      nextTick(() => {
        const targetElement = document.getElementById(`message-${mailbox.scrollToMessageUid}`)
        if (targetElement) {
          targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' })
          targetElement.classList.add('highlight-message')
          setTimeout(() => {
            targetElement.classList.remove('highlight-message')
          }, 2000)
        }
        mailbox.scrollToMessageUid = null
      })
    }, 100)
  }

  // Load Drive saved-status for any message in this thread that has
  // attachments, so the indicator + Share action render the moment the
  // attachment cards appear. `immediate: true` covers the case where
  // the message is already in state when EmailView mounts (the common
  // path: user clicks an email in the list).
  if (Array.isArray(messages) && messages.length > 0) {
    for (const m of messages) {
      if (m?.attachments?.length) {
        loadSavedDriveStatus(m)
      }
    }
  }
}, { immediate: true })

// Belt-and-suspenders: re-trigger when the active message UID changes,
// e.g. when the user navigates between threads without remounting the
// EmailView component.
watch(() => message.value?.uid, () => {
  const list = conversationMessages.value || []
  for (const m of list) {
    if (m?.attachments?.length) loadSavedDriveStatus(m)
  }
})

const isDraft = computed(() => {
  return mailbox.currentFolder?.toLowerCase() === 'drafts' || 
         mailbox.currentFolder?.toLowerCase().includes('draft')
})

// Check if current folder is spam/junk
const isSpamFolder = computed(() => {
  const folder = mailbox.currentFolder?.toLowerCase() || ''
  return folder.includes('spam') || folder.includes('junk')
})

// Get sender email from message (handles various formats)
function getSenderEmail(msg) {
  if (!msg) return null
  
  // Handle array format
  if (Array.isArray(msg.from) && msg.from.length > 0) {
    const sender = msg.from[0]
    if (typeof sender === 'object') {
      return sender.email || null
    }
    if (typeof sender === 'string') {
      // Parse "Name <email>" format
      const match = sender.match(/<([^>]+)>/)
      return match ? match[1] : sender
    }
  }
  
  // Handle string format
  if (typeof msg.from === 'string') {
    const match = msg.from.match(/<([^>]+)>/)
    return match ? match[1] : msg.from
  }
  
  // Fallback
  return msg.from_email || null
}

// Check if sender is blocked
function isSenderBlocked(msg) {
  const email = getSenderEmail(msg)
  return email ? spamStore.isSenderBlocked(email) : false
}

// Format spam score for display
function formatSpamScore(msg) {
  if (msg?.spam_score == null) return null
  const score = msg.spam_score
  const threshold = msg.spam_threshold || 5.0
  return { score, threshold, isSpam: score >= threshold }
}

// Report as spam handler
const reportingSpam = ref(false)
const showSpamConfirm = ref(false)
const spamMessage = ref(null)
const blockSenderToo = ref(false)

function initiateReportSpam(msg) {
  spamMessage.value = msg
  blockSenderToo.value = false
  showSpamConfirm.value = true
}

async function executeReportSpam() {
  if (!spamMessage.value) return
  reportingSpam.value = true
  try {
    // Use the message's own folder, not currentFolder: when viewing a virtual
    // folder (All Mail / Search Results) or a cross-folder conversation,
    // currentFolder is not a real IMAP path and the server-side move fails.
    const srcFolder = spamMessage.value.folder || mailbox.currentFolder
    const success = await spamStore.reportSpam(
      srcFolder,
      spamMessage.value.uid,
      { blockSender: blockSenderToo.value }
    )
    if (success) {
      // Clear current message since it moved
      mailbox.clearCurrentMessage()
    }
  } finally {
    reportingSpam.value = false
    showSpamConfirm.value = false
    spamMessage.value = null
  }
}

function cancelReportSpam() {
  showSpamConfirm.value = false
  spamMessage.value = null
  blockSenderToo.value = false
}

// Not spam handler
const markingNotSpam = ref(false)
const showNotSpamConfirm = ref(false)
const notSpamMessage = ref(null)
const addToSafeToo = ref(false)

function initiateNotSpam(msg) {
  notSpamMessage.value = msg
  addToSafeToo.value = false
  showNotSpamConfirm.value = true
}

async function executeNotSpam() {
  if (!notSpamMessage.value) return
  markingNotSpam.value = true
  try {
    // Use the message's own folder, not currentFolder (see executeReportSpam).
    const srcFolder = notSpamMessage.value.folder || mailbox.currentFolder
    const success = await spamStore.notSpam(
      srcFolder,
      notSpamMessage.value.uid,
      { addToSafe: addToSafeToo.value }
    )
    if (success) {
      // Clear current message since it moved
      mailbox.clearCurrentMessage()
    }
  } finally {
    markingNotSpam.value = false
    showNotSpamConfirm.value = false
    notSpamMessage.value = null
  }
}

function cancelNotSpam() {
  showNotSpamConfirm.value = false
  notSpamMessage.value = null
  addToSafeToo.value = false
}

// Block sender handler  
const showBlockConfirm = ref(false)
const blockMessage = ref(null)
const blockingAction = ref(false)

function initiateBlockSender(msg) {
  blockMessage.value = msg
  showBlockConfirm.value = true
}

async function executeBlockSender() {
  if (!blockMessage.value) return
  const email = getSenderEmail(blockMessage.value)
  if (!email) return
  
  blockingAction.value = true
  try {
    // Block the sender
    const blocked = await spamStore.blockSender(email, { reason: 'Blocked from email view' })
    
    if (blocked && blockMessage.value?.uid) {
      const srcFolder = blockMessage.value.folder || mailbox.currentFolder
      await mailbox.deleteMessage(blockMessage.value.uid, srcFolder)
    }
  } finally {
    blockingAction.value = false
    showBlockConfirm.value = false
    blockMessage.value = null
  }
}

function cancelBlockSender() {
  showBlockConfirm.value = false
  blockMessage.value = null
}

// Extract Drive links from email body HTML
function extractDriveLinks(html) {
  if (!html) return []
  
  // Decode HTML entities that might mask drive markers
  let decodedHtml = html
    .replace(/&amp;/g, '&')
    .replace(/&#9729;/g, '☁')
    .replace(/&#x2601;/g, '☁')
    .replace(/&#38;/g, '&')
    .replace(/&nbsp;/g, ' ')
    .replace(/&#160;/g, ' ')
  
  // Check if there's any drive share link in the body - this is the primary indicator
  const hasDriveLinks = /\/(?:api\/)?drive\/share\/[a-zA-Z0-9_-]+/i.test(decodedHtml)
  
  if (!hasDriveLinks) {
    return []
  }
  
  const links = []
  
  // Method 1: Find all links with drive share URLs using regex
  // More flexible pattern that handles various HTML structures
  const linkPatterns = [
    // Standard anchor with href
    /<a[^>]*href\s*=\s*["']([^"']*\/(?:api\/)?drive\/share\/[^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi,
    // Href might be URL-encoded
    /<a[^>]*href\s*=\s*["']([^"']*%2Fdrive%2Fshare%2F[^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi,
  ]
  
  for (const linkRegex of linkPatterns) {
    let match
    while ((match = linkRegex.exec(decodedHtml)) !== null) {
      let url = match[1]
      const innerHtml = match[2]
      
      // Decode URL if needed
      if (url.includes('%2F')) {
        url = decodeURIComponent(url)
      }
      
      // Skip if already have this URL
      if (links.some(l => l.url === url)) continue
      
      // Extract filename - priority: 1) ?fn= param in URL, 2) HTML content, 3) URL path segment
      let name = ''
      
      // Priority 1: Check ?fn= query parameter in the share URL (most reliable)
      try {
        const urlObj = new URL(url, window.location.origin)
        const fnParam = urlObj.searchParams.get('fn')
        if (fnParam) name = fnParam
      } catch (e) { /* ignore URL parse errors */ }
      
      // Priority 2: Extract from HTML content (look for filename with extension)
      if (!name) {
        name = extractFilename(innerHtml)
      }
      
      // Priority 3: Last resort - URL path segment (may be a hash token - avoid)
      if (!name) {
        const urlMatch = url.match(/\/([^\/\?]+?)(?:\?|$)/)
        if (urlMatch) {
          const segment = decodeURIComponent(urlMatch[1])
          // Only use if it looks like a filename (has an extension), not a hash token
          if (segment.includes('.') && segment.length < 200) {
            name = segment
          }
        }
      }
      
      if (!name) continue
      
      // Extract size
      const sizeMatch = innerHtml.match(/(\d+(?:\.\d+)?\s*(?:KB|MB|GB|B|bytes?))/i)
      const sizeText = sizeMatch ? sizeMatch[1] : ''
      
      // Check for expiry
      const hasExpiry = innerHtml.includes('7d') || innerHtml.includes('⏱') || innerHtml.toLowerCase().includes('schedule') || innerHtml.toLowerCase().includes('expires')
      
      links.push({
        url,
        name,
        sizeText,
        isDrive: true,
        willExpire: hasExpiry,
        expiryDays: 7
      })
    }
  }
  
  // Method 2: Fallback - find drive share URLs directly and try to extract surrounding context
  if (links.length === 0) {
    const directUrlRegex = /(https?:\/\/[^"'\s<>]*\/(?:api\/)?drive\/share\/[a-zA-Z0-9_-]+[^"'\s<>]*)/gi
    let urlMatch
    while ((urlMatch = directUrlRegex.exec(decodedHtml)) !== null) {
      const url = urlMatch[1]
      if (links.some(l => l.url === url)) continue
      
      // Priority 1: Extract filename from ?fn= query parameter
      let name = ''
      try {
        const urlObj = new URL(url)
        const fnParam = urlObj.searchParams.get('fn')
        if (fnParam) name = fnParam
      } catch (e) { /* ignore */ }
      
      // Priority 2: Try to find filename in surrounding context
      if (!name) {
        const pos = urlMatch.index
        const context = decodedHtml.substring(Math.max(0, pos - 200), Math.min(decodedHtml.length, pos + url.length + 200))
        name = extractFilename(context) || 'Drive File'
      }
      
      const pos = urlMatch.index
      const context = decodedHtml.substring(Math.max(0, pos - 200), Math.min(decodedHtml.length, pos + url.length + 200))
      const sizeMatch = context.match(/(\d+(?:\.\d+)?\s*(?:KB|MB|GB|B))/i)
      
      links.push({
        url,
        name,
        sizeText: sizeMatch ? sizeMatch[1] : '',
        isDrive: true,
        willExpire: context.includes('7d') || context.includes('⏱'),
        expiryDays: 7
      })
    }
  }
  
  return links
}

// Helper function to extract filename from HTML content
function extractFilename(html) {
  if (!html) return ''
  
  // First try to find text that looks like a filename (has extension)
  const filenameWithExt = html.match(/>([^<]*\.[a-zA-Z0-9]{1,10})</g)
  if (filenameWithExt) {
    for (const m of filenameWithExt) {
      const text = m.replace(/^>|<$/g, '').trim()
      if (text && text.length > 2 && !text.match(/^\d+(\.\d+)?\s*(KB|MB|GB|B)$/i)) {
        return text
      }
    }
  }
  
  // Fallback: find any meaningful text content
  const textMatches = html.match(/>([^<]+)</g)
  if (textMatches) {
    for (const m of textMatches) {
      const text = m.replace(/^>|<$/g, '').trim()
      // Skip size, badges, icons, common labels
      if (text && text.length > 2 && 
          !text.match(/^\d+(\.\d+)?\s*(KB|MB|GB|B|bytes?)$/i) && 
          !text.match(/^(Drive|☁|⏱|7d|⬇|↓|▢|open_in_new|cloud|download|\s*)$/i)) {
        return text
      }
    }
  }
  
  return ''
}

// Remove Drive links section from HTML body for display in our webmail UI
function stripDriveLinksFromBody(html) {
  if (!html) return ''
  
  // Quick check if there are any drive links to strip
  if (!html.includes('/drive/share/')) {
    return html
  }
  
  let cleaned = html
  
  // Try multiple approaches to find and remove the drive section
  
  // Approach 1: Find by "DRIVE ATTACHMENTS" marker text
  let markerPos = cleaned.indexOf('DRIVE ATTACHMENTS')
  if (markerPos === -1) {
    markerPos = cleaned.indexOf('Attachments (via Drive)')
  }
  
  if (markerPos > -1) {
    // Go backwards to find the outermost container
    let sectionStart = cleaned.lastIndexOf('<div', markerPos)
    
    // Look for specific wrapper patterns
    const wrapperPatterns = [
      '<div style="margin-top:',
      '<div style="margin-top: ',
      '<div style="padding-top:',
    ]
    
    for (const pattern of wrapperPatterns) {
      const patternPos = cleaned.lastIndexOf(pattern, markerPos)
      if (patternPos > -1 && patternPos < markerPos && markerPos - patternPos < 500) {
        sectionStart = patternPos
        break
      }
    }
    
    // Find where this section ends by counting div depth
    let depth = 0
    let sectionEnd = cleaned.length
    let i = sectionStart
    
    while (i < cleaned.length) {
      const substr = cleaned.substring(i, i + 6).toLowerCase()
      if (substr.startsWith('<div')) {
        depth++
        i += 4
      } else if (substr === '</div>') {
        depth--
        if (depth === 0) {
          sectionEnd = i + 6
          break
        }
        i += 6
      } else {
        i++
      }
    }
    
    // Remove the section
    cleaned = cleaned.substring(0, sectionStart) + cleaned.substring(sectionEnd)
  }
  
  // Approach 2: Remove any remaining orphan drive link tables/divs
  // This catches cases where the structure is different
  cleaned = cleaned.replace(/<table[^>]*>[\s\S]*?\/drive\/share\/[\s\S]*?<\/table>/gi, '')
  
  // Empty paragraph cleanup removed -- iframe renders email HTML natively
  
  return cleaned.trim()
}

// Get Drive attachments for a message
function getDriveAttachments(msg) {
  const links = extractDriveLinks(msg?.body_html)
  // Debug: log extraction results
  if (msg?.body_html && (msg.body_html.includes('/drive/share/') || msg.body_html.includes('DRIVE'))) {
    isDebugEnabled() && console.log('[Drive] Body contains drive markers, extracted:', links.length, 'links')
    if (links.length === 0) {
      isDebugEnabled() && console.log('[Drive] Body HTML preview:', msg.body_html.substring(0, 500))
    }
  }
  return links
}

function getProcessedBody(msg) {
  if (!msg?.body_html) return ''
  
  let html = stripDriveLinksFromBody(msg.body_html)
  
  const senderEmail = msg.from?.[0]?.email
  const isTrusted = settingsStore.isTrustedSender(senderEmail)
  const imagesLoadedForUid = loadedImagesForUids.value.has(msg.uid)
  const blockRemoteEnabled = settingsStore.settings.block_remote_images
  const shouldBlockImages = blockRemoteEnabled && !imagesLoadedForUid && !isTrusted
  
  debugLog('[TRUSTED-SENDER] getProcessedBody:', {
    uid: msg.uid,
    senderEmail,
    isTrusted,
    imagesLoadedForUid,
    blockRemoteEnabled,
    shouldBlockImages,
    trustedSendersLoaded: settingsStore.trustedSendersLoaded,
    trustedSendersList: settingsStore.trustedSenders,
  })
  
  return processEmailContent(html, { blockRemoteImages: shouldBlockImages })
}

// Dark mode detection for iframe -- reactive via theme store
const isDarkMode = computed(() => themeStore.isDark)

// Handle link clicks from EmailIframe
function handleIframeLinkClick(href, dataset) {
  if (!href) return

  if (href.includes('/drive/share/') || href.includes('%2Fdrive%2Fshare%2F')) {
    let driveUrl = href
    if (href.includes('google.com/url')) {
      try {
        const googleUrl = new URL(href)
        const actualUrl = googleUrl.searchParams.get('q')
        if (actualUrl) driveUrl = actualUrl
      } catch (e) { /* use href as-is */ }
    }
    let filename = 'download'
    try {
      const urlObj = new URL(driveUrl, window.location.origin)
      const fnParam = urlObj.searchParams.get('fn')
      if (fnParam) filename = fnParam
    } catch (e) { /* ignore */ }
    downloadDriveFile({ url: driveUrl, name: filename })
  } else if (href.startsWith('http://') || href.startsWith('https://')) {
    window.open(href, '_blank', 'noopener,noreferrer')
  } else {
    window.open(href, '_blank', 'noopener,noreferrer')
  }
}

// Handle mailto clicks from EmailIframe
function handleIframeMailtoClick(href) {
  if (!href) return
  try {
    const mailtoUrl = new URL(href)
    const email = mailtoUrl.pathname
    const params = new URLSearchParams(mailtoUrl.search)
    compose.open('new')
    setTimeout(() => {
      if (email) {
        compose.draft.to = [{ email, name: '', display: email }]
      }
      if (params.get('subject')) compose.draft.subject = params.get('subject')
      if (params.get('body')) compose.draft.body = params.get('body')
      if (params.get('cc')) {
        compose.draft.cc = params.get('cc').split(',').map(e => ({ email: e.trim(), name: '', display: e.trim() }))
      }
      if (params.get('bcc')) {
        compose.draft.bcc = params.get('bcc').split(',').map(e => ({ email: e.trim(), name: '', display: e.trim() }))
      }
    }, 100)
  } catch (e) { /* ignore invalid mailto */ }
}

// Handle calendar action from EmailIframe
function handleIframeCalendarAction(dataset) {
  if (dataset?.action === 'add-to-calendar') {
    const msg = message.value?.isConversation
      ? message.value?.messages?.find(m => m.calendar_event)
      : message.value
    if (msg?.calendar_event) {
      handleAddToCalendarClick(msg)
    }
  }
}

// Handle text selection from EmailIframe
function handleIframeTextSelected(data) {
  if (data?.text && data.text.length > 0) {
    selectedText.value = data.text.substring(0, 2000)
    if (data.rect && data.rect.width > 0 && data.rect.height > 0) {
      selectionPosition.value = {
        x: Math.min(data.rect.left + data.rect.width / 2, window.innerWidth - 100),
        y: Math.max(data.rect.top - 10, 50)
      }
      showSelectionTooltip.value = true
    }
  }
}

function handleIframeSelectionCleared() {
  showSelectionTooltip.value = false
  selectedText.value = ''
}

// Check if a message has blocked images
function messageHasBlockedImages(msg) {
  if (!msg?.body_html) return false
  const senderEmail = msg.from?.[0]?.email
  
  // If images already loaded or sender is trusted, no blocked images
  if (loadedImagesForUids.value.has(msg.uid) || settingsStore.isTrustedSender(senderEmail)) {
    return false
  }
  
  // Check if settings have image blocking enabled
  if (!settingsStore.settings.block_remote_images) return false
  
  // Check if there are remote images in the HTML
  const remoteImageRegex = /<img[^>]*src\s*=\s*["']https?:\/\//i
  return remoteImageRegex.test(msg.body_html)
}

// Load images for a specific message
function loadImagesForMessage(msg) {
  loadedImagesForUids.value.add(msg.uid)
  // Force re-render by updating the Set
  loadedImagesForUids.value = new Set(loadedImagesForUids.value)
}

// Trust sender and load images
async function trustSenderAndLoadImages(msg) {
  const senderEmail = msg.from?.[0]?.email
  if (senderEmail) {
    const success = await settingsStore.addTrustedSender(senderEmail)
    if (success) {
      toast.success(t('emailView.addedSenderToTrusted', { email: senderEmail }))
    } else {
      toast.error(t('emailView.failedToAddTrustedSender'))
    }
  }
  loadImagesForMessage(msg)
}

// Reset loaded images when message changes
watch(() => message.value?.uid, () => {
  // Clear loaded images for previous message (optional - keep in Set for session)
})

function formatDate(timestamp) {
  if (!timestamp) return ''
  
  // If timestamp is a string (datetime format), parse it directly
  let date
  if (typeof timestamp === 'string') {
    date = new Date(timestamp)
  } else {
    // Unix timestamp (seconds) - convert to milliseconds
    date = new Date(timestamp * 1000)
  }
  
  // Check for invalid date
  if (isNaN(date.getTime())) return ''
  
  return date.toLocaleString([], {
    weekday: 'short',
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatTime(timestamp) {
  if (!timestamp) return ''
  
  // If timestamp is a string (datetime format), parse it directly
  let date
  if (typeof timestamp === 'string') {
    date = new Date(timestamp)
  } else {
    // Unix timestamp (seconds) - convert to milliseconds
    date = new Date(timestamp * 1000)
  }
  
  // Check for invalid date
  if (isNaN(date.getTime())) return ''
  
  return date.toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  })
}

// Format scheduled send time in a human-readable way
function formatScheduledTime(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  if (isNaN(date.getTime())) return dateStr
  
  const now = new Date()
  const diffMs = date.getTime() - now.getTime()
  const diffMins = Math.round(diffMs / 60000)
  const diffHours = Math.round(diffMs / 3600000)
  const diffDays = Math.round(diffMs / 86400000)
  
  let relative = ''
  if (diffMs < 0) {
    relative = ' (overdue)'
  } else if (diffMins < 60) {
    relative = ` (in ${diffMins} min)`
  } else if (diffHours < 24) {
    relative = ` (in ${diffHours}h)`
  } else if (diffDays < 7) {
    relative = ` (in ${diffDays} days)`
  }
  
  return date.toLocaleString([], {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }) + relative
}

// Edit a scheduled email (opens compose modal, cancels existing schedule)
async function editScheduledEmail(scheduleId) {
  const result = await compose.openScheduledEmail(scheduleId)
  if (result.success) {
    toast.success(t('emailView.editingScheduledEmailSchedulePaused'))
    mailbox.currentMessage = null
    if (mailbox.currentFolder === 'SCHEDULED') {
      await mailbox.fetchScheduledEmails()
    }
  } else {
    toast.error(result.error || t('emailView.editingScheduledEmailSchedulePaused'))
  }
}

// Cancel a scheduled email
async function cancelScheduledEmail(scheduleId) {
  const result = await compose.cancelScheduledEmail(scheduleId)
  if (result.success) {
    toast.success(t('emailView.scheduledEmailCancelled'))
    mailbox.currentMessage = null
    if (mailbox.currentFolder === 'SCHEDULED') {
      await mailbox.fetchScheduledEmails()
    }
  } else {
    toast.error(result.error || t('emailView.scheduledEmailCancelled'))
  }
}

function getFirstRecipient() {
  const msg = message.value
  if (!msg?.to) return ''
  
  if (Array.isArray(msg.to) && msg.to.length > 0) {
    const recipient = msg.to[0]
    if (typeof recipient === 'object') {
      return recipient.name || recipient.email || ''
    }
    return recipient
  }
  
  if (typeof msg.to === 'string') {
    const match = msg.to.match(/([^<]+)?<?([^>]+)?>?/)
    if (match) {
      return match[1]?.trim() || match[2] || msg.to
    }
    return msg.to
  }
  
  return ''
}

// Mobile thread navigation
const currentMessageIndex = ref(0)

function navigateToPreviousMessage() {
  if (currentMessageIndex.value > 0) {
    currentMessageIndex.value--
    scrollToMessageByIndex(currentMessageIndex.value)
  }
}

function navigateToNextMessage() {
  if (currentMessageIndex.value < allConversationMessages.value.length - 1) {
    currentMessageIndex.value++
    scrollToMessageByIndex(currentMessageIndex.value)
  }
}

function scrollToMessageByIndex(index) {
  const messages = document.querySelectorAll('[data-message-index]')
  if (messages[index]) {
    messages[index].scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
}

function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function formatRecipients(recipients) {
  if (!recipients) return ''
  
  // Ensure it's an array
  const arr = Array.isArray(recipients) ? recipients : [recipients]
  if (arr.length === 0) return ''
  
  return arr.map(r => {
    if (typeof r === 'string') return r
    return r.display || r.email || r.name || ''
  }).filter(Boolean).join(', ')
}

// Get recipients as array for clickable rendering
function getRecipientsArray(recipients) {
  if (!recipients) return []
  const arr = Array.isArray(recipients) ? recipients : [recipients]
  return arr.map(r => {
    if (typeof r === 'string') {
      return { display: r, email: r }
    }
    return {
      display: r.display || (r.name ? `${r.name} <${r.email}>` : r.email) || '',
      email: r.email || r.display || ''
    }
  }).filter(r => r.display)
}

// Copy email to clipboard
async function copyEmail(email) {
  try {
    await navigator.clipboard.writeText(email)
    toast.success(t('emailView.copiedEmail', { email }))
  } catch (err) {
    // Fallback for older browsers
    const textArea = document.createElement('textarea')
    textArea.value = email
    document.body.appendChild(textArea)
    textArea.select()
    document.execCommand('copy')
    document.body.removeChild(textArea)
    toast.success(t('emailView.copiedEmail', { email }))
  }
}

function getFolderDisplayName(name) {
  if (name === 'INBOX') return 'Inbox'
  if (name?.startsWith('INBOX.')) {
    // Replace dots with arrows for subfolder paths
    return name.slice(6).replace(/\./g, ' -> ')
  }
  return name?.replace(/\./g, ' -> ') || name
}

function goBack() {
  mailbox.clearCurrentMessage()
}

function reply(msg = null) {
  const targetMsg = msg || activeMessage.value
  if (targetMsg) {
    compose.open('reply', targetMsg)
  }
}

function replyAll(msg = null) {
  const targetMsg = msg || activeMessage.value
  if (targetMsg) {
    compose.open('replyAll', targetMsg)
  }
}

function forward(msg = null) {
  const targetMsg = msg || activeMessage.value
  if (targetMsg) {
    compose.open('forward', targetMsg)
  }
}

function editDraft() {
  compose.openDraft(message.value)
  // Clear current message since the draft will be modified/replaced by auto-save
  mailbox.clearCurrentMessage()
}

async function toggleStar() {
  const folder = message.value.folder || mailbox.currentFolder
  await mailbox.setFlag(message.value.uid, 'flagged', !message.value.flagged, folder)
}

async function togglePin() {
  const folder = message.value.folder || mailbox.currentFolder
  await mailbox.togglePin(message.value.uid, folder, {
    message_id: message.value.message_id,
    subject: message.value.subject
  })
}

async function toggleRead() {
  const folder = message.value.folder || mailbox.currentFolder
  await mailbox.setFlag(message.value.uid, 'seen', !message.value.seen, folder)
}

// Toggle star for a specific message in conversation
async function toggleMessageStar(msg) {
  const newValue = !msg.flagged
  const folder = msg.folder || message.value?.folder || mailbox.currentFolder
  await mailbox.setFlag(msg.uid, 'flagged', newValue, folder)
  // Update local state
  msg.flagged = newValue
}

// Track which message's label picker is open
const openLabelPickerUid = ref(null)

function toggleMessageLabelPicker(event, msg) {
  event.stopPropagation()
  if (openLabelPickerUid.value === msg.uid) {
    openLabelPickerUid.value = null
  } else {
    // Calculate position with viewport clamping
    const rect = event.currentTarget.getBoundingClientRect()
    const popupWidth = 288 // w-72
    const popupHeight = 400 // approximate max height
    const padding = 8
    
    let x = rect.left
    let y = rect.bottom + 4
    
    // Clamp to viewport
    if (x + popupWidth > window.innerWidth - padding) {
      x = window.innerWidth - popupWidth - padding
    }
    if (x < padding) x = padding
    
    if (y + popupHeight > window.innerHeight - padding) {
      y = rect.top - popupHeight - 4 // Show above if no room below
      if (y < padding) y = padding
    }
    
    labelPickerPosition.value = { x, y }
    openLabelPickerUid.value = msg.uid
  }
}

// Toggle move menu with position
const folderSearchQuery = ref('')
const filteredMoveFolders = computed(() => {
  if (!folderSearchQuery.value.trim()) return moveFolders.value
  const query = folderSearchQuery.value.toLowerCase()
  return moveFolders.value.filter(f => 
    getFolderDisplayName(f.name).toLowerCase().includes(query)
  )
})

function toggleMoveMenu(event, msg) {
  event.stopPropagation()
  if (showMoveMenu.value === msg.uid) {
    showMoveMenu.value = null
    folderSearchQuery.value = ''
  } else {
    // Calculate position with viewport clamping
    const rect = event.currentTarget.getBoundingClientRect()
    const popupWidth = 256 // w-64
    const popupHeight = 350 // approximate max height
    const padding = 8
    
    let x = rect.left
    let y = rect.bottom + 4
    
    // Clamp to viewport
    if (x + popupWidth > window.innerWidth - padding) {
      x = window.innerWidth - popupWidth - padding
    }
    if (x < padding) x = padding
    
    if (y + popupHeight > window.innerHeight - padding) {
      y = rect.top - popupHeight - 4 // Show above if no room below
      if (y < padding) y = padding
    }
    
    moveMenuPosition.value = { x, y }
    showMoveMenu.value = msg.uid
    folderSearchQuery.value = ''
  }
}

async function archive() {
  const srcFolder = message.value.folder || mailbox.currentFolder
  const success = await mailbox.moveMessage(message.value.uid, srcFolder, 'INBOX.Archive')
  if (success) {
    mailbox.fetchFolders(true)
    toast.success(t('emailView.archived'))
  }
}

async function markSpam() {
  const spamFolder = mailbox.folders.find(f => f.type === 'spam' || f.type === 'junk')
  const srcFolder = message.value.folder || mailbox.currentFolder
  const success = await mailbox.moveMessage(message.value.uid, srcFolder, spamFolder?.name || 'INBOX.Spam')
  if (success) {
    mailbox.fetchFolders(true)
    toast.success(t('emailView.movedToSpam'))
  }
}

async function moveToFolder(folder) {
  showMoveMenu.value = false
  const srcFolder = message.value.folder || mailbox.currentFolder
  const success = await mailbox.moveMessage(message.value.uid, srcFolder, folder)
  if (success) {
    mailbox.fetchFolders(true)
    toast.success(t('emailView.move') + ': ' + getFolderDisplayName(folder))
  }
}

async function deleteMessage() {
  showDeleteConfirm.value = false
  const srcFolder = message.value.folder || mailbox.currentFolder
  await mailbox.deleteMessage(message.value.uid, srcFolder)
  mailbox.fetchFolders(true)
}

function openAttachmentPreview(attachment, msg = null) {
  // Track which message's attachments we're previewing
  previewMessage.value = msg || message.value
  // Find index of this attachment
  const index = previewMessage.value?.attachments?.findIndex(a => a.part === attachment.part) || 0
  previewAttachmentIndex.value = index
  showAttachmentPreview.value = true
}

function closeAttachmentPreview() {
  showAttachmentPreview.value = false
  previewMessage.value = null
}

// Refresh the per-message saved-status cache when the preview modal
// reports a successful save, so the original attachment card under the
// modal picks up the cloud_done badge as soon as the user closes it.
async function onPreviewAttachmentSaved(payload) {
  const target = previewMessage.value
  if (!target?.uid) return
  if (payload?.uid && payload.uid !== target.uid) return
  await refreshSavedDriveStatus(target)
}

async function downloadAttachment(attachment, event, msg = null) {
  if (event) event.stopPropagation()
  downloadingAttachment.value = attachment.part
  
  // Use the message's actual folder, not the virtual folder
  const targetMsg = msg || message.value
  const folder = targetMsg?.folder || mailbox.currentFolder
  
  try {
    const response = await api.get(
      folderCollectionUrl(mailbox.folders, folder, `messages/${targetMsg.uid}/attachments/${attachment.part}`)
    )
    
    if (response.data.success) {
      const data = response.data.data
      const blob = base64ToBlob(data.content, data.type)
      const url = URL.createObjectURL(blob)
      
      const link = document.createElement('a')
      link.href = url
      link.download = data.filename
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
    }
  } catch (e) {
    toast.error(t('emailView.failedToDownloadAttachment'))
  } finally {
    downloadingAttachment.value = null
  }
}

// Check if attachment can be edited in collab editor
function canEditAttachmentInCollab(attachment) {
  const filename = attachment.filename?.toLowerCase() || ''
  const mimeType = attachment.type?.toLowerCase() || ''
  
  // Check for DOCX
  if (/\.docx$/.test(filename) || mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
    return true
  }
  // Check for PPTX
  if (/\.pptx$/.test(filename) || mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation') {
    return true
  }
  return false
}

// Attachment is editable in some editor: OnlyOffice (docx/xlsx/pptx/md)
// when available, legacy collab editor (docx/pptx) as fallback.
function canEditAttachmentDoc(attachment) {
  return canEditInOffice(attachment?.filename) || canEditAttachmentInCollab(attachment)
}

// Get the type of collab document for an attachment
function getCollabTypeForAttachment(attachment) {
  const filename = attachment.filename?.toLowerCase() || ''
  const mimeType = attachment.type?.toLowerCase() || ''
  
  if (/\.pptx$/.test(filename) || mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation') {
    return 'presentation'
  }
  return 'document'
}

// Per-attachment guard so double-clicks can't trigger duplicate saves.
const openingEditorKeys = ref(new Set())

// "Open in editor" on an attachment card. Both editors work on Drive
// files, so the attachment must live in Drive first: reuse the
// already-saved file when one is known, otherwise save it once (tagged
// with the source message so the saved-status lookup recognizes it from
// then on). OnlyOffice is the primary editor; the legacy collab editor
// is the docx/pptx fallback when Office is unavailable.
async function openAttachmentInEditor(attachment, msg) {
  const guardKey = `${msg?.uid}_${attachment?.part}`
  if (openingEditorKeys.value.has(guardKey)) return
  openingEditorKeys.value.add(guardKey)
  try {
    let driveFile = getSavedDriveFile(msg, attachment)

    if (!driveFile) {
      toast.info(t('emailView.savingToDriveAndOpening'))

      const folder = msg?.folder || mailbox.currentFolder

      // Fetch attachment content from IMAP
      const attResponse = await api.get(
        folderCollectionUrl(mailbox.folders, folder, `messages/${msg.uid}/attachments/${attachment.part}`)
      )
      if (!attResponse.data.success) {
        toast.error(t('emailView.failedToOpenInEditor'))
        return
      }
      const attData = attResponse.data.data

      const result = await driveStore.saveEmailAttachment(
        attData.filename || attachment.filename,
        attData.content,
        attData.type || attachment.type || 'application/octet-stream',
        msg.subject || 'Email',
        msg.date,
        msg.from?.[0]?.email,
        folder,
        msg.uid,
        attachment.part
      )
      if (!result.success || !result.file) {
        toast.error(t('emailView.failedToSaveAttachmentTo'))
        return
      }
      driveFile = result.file
      // Update the card badge (cloud_done + Share) without a reload.
      refreshSavedDriveStatus(msg)
    }

    if (canEditInOffice(attachment.filename)) {
      // Pass the current mail URL so the editor's Back button returns
      // here (exact folder + open message) instead of Drive.
      const query = { back: router.currentRoute.value.fullPath }
      if (driveFile.folder_id) query.folder = String(driveFile.folder_id)
      router.push({
        name: 'office-editor',
        params: { fileId: String(driveFile.id) },
        query,
      })
      return
    }

    // Legacy collab editor fallback. Saved-status rows carry `filename`
    // instead of `original_name`, so normalize the title field.
    const type = getCollabTypeForAttachment(attachment)
    const document = await collabStore.createFromDriveFile(
      { ...driveFile, original_name: driveFile.original_name || driveFile.filename || attachment.filename },
      type
    )
    if (document) {
      collabDocumentId.value = document.uuid
      collabDocumentTitle.value = attachment.filename
      collabEditorMode.value = type
      showCollabEditor.value = true
    }
  } catch (error) {
    console.error('Failed to open attachment in editor:', error)
    toast.error(t('emailView.failedToOpenInEditor'))
  } finally {
    openingEditorKeys.value.delete(guardKey)
  }
}

// Close collab editor
function closeCollabEditor() {
  showCollabEditor.value = false
  collabDocumentId.value = null
  collabDocumentTitle.value = ''
  // Reset collab store state (clears connected users, etc.)
  collabStore.resetState()
}

function getAttachmentIcon(attachment) {
  const filename = attachment.filename?.toLowerCase() || ''
  const mimeType = attachment.type?.toLowerCase() || ''
  
  if (mimeType.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|svg)$/.test(filename)) {
    return 'image'
  }
  if (mimeType === 'application/pdf' || filename.endsWith('.pdf')) {
    return 'picture_as_pdf'
  }
  if (/\.(doc|docx)$/.test(filename) || mimeType.includes('word')) {
    return 'description'
  }
  if (/\.(xls|xlsx)$/.test(filename) || mimeType.includes('spreadsheet')) {
    return 'table_chart'
  }
  if (/\.(ppt|pptx)$/.test(filename) || mimeType.includes('presentation')) {
    return 'slideshow'
  }
  if (mimeType.startsWith('video/') || /\.(mp4|webm|mov|avi|mkv|wmv)$/.test(filename)) {
    return 'movie'
  }
  if (mimeType.startsWith('audio/') || /\.(mp3|wav|ogg)$/.test(filename)) {
    return 'audio_file'
  }
  if (/\.(zip|rar|7z|tar|gz|tar\.gz)$/.test(filename) || mimeType.includes('zip')) {
    return 'folder_zip'
  }
  return 'attachment'
}

/** Tailwind classes for non-image attachment icon tiles */
function getAttachmentIconColorClasses(attachment) {
  const filename = attachment.filename?.toLowerCase() || ''
  const mimeType = attachment.type?.toLowerCase() || ''
  if (mimeType === 'application/pdf' || filename.endsWith('.pdf')) {
    return 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/40'
  }
  if (/\.(doc|docx)$/.test(filename) || mimeType.includes('word')) {
    return 'text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/40'
  }
  if (/\.(xls|xlsx|csv)$/.test(filename) || mimeType.includes('spreadsheet')) {
    return 'text-emerald-600 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-900/40'
  }
  if (/\.(ppt|pptx)$/.test(filename) || mimeType.includes('presentation')) {
    return 'text-orange-600 dark:text-orange-400 bg-orange-100 dark:bg-orange-900/40'
  }
  if (mimeType.startsWith('video/') || /\.(mp4|webm|mov|avi|mkv|wmv)$/.test(filename)) {
    return 'text-purple-600 dark:text-purple-400 bg-purple-100 dark:bg-purple-900/40'
  }
  if (mimeType.startsWith('audio/') || /\.(mp3|wav|ogg|flac|aac)$/.test(filename)) {
    return 'text-pink-600 dark:text-pink-400 bg-pink-100 dark:bg-pink-900/40'
  }
  if (/\.(zip|rar|7z|tar|gz|tar\.gz)$/.test(filename) || mimeType.includes('zip')) {
    return 'text-amber-700 dark:text-amber-400 bg-amber-100 dark:bg-amber-900/40'
  }
  if (mimeType.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|svg|bmp)$/.test(filename)) {
    return 'text-cyan-600 dark:text-cyan-400 bg-cyan-100 dark:bg-cyan-900/40'
  }
  return 'text-surface-600 dark:text-surface-300 bg-surface-200/90 dark:bg-surface-700/60'
}

function getDriveFileIcon(filename) {
  const name = filename?.toLowerCase() || ''
  
  if (/\.(jpg|jpeg|png|gif|webp|svg|bmp)$/.test(name)) return 'image'
  if (/\.pdf$/.test(name)) return 'picture_as_pdf'
  if (/\.(doc|docx|txt|rtf)$/.test(name)) return 'description'
  if (/\.(xls|xlsx|csv)$/.test(name)) return 'table_chart'
  if (/\.(ppt|pptx)$/.test(name)) return 'slideshow'
  if (/\.(mp4|webm|mov|avi|mkv|wmv)$/.test(name)) return 'movie'
  if (/\.(mp3|wav|ogg|flac|aac)$/.test(name)) return 'audio_file'
  if (/\.(zip|rar|7z|tar|gz|tar\.gz)$/.test(name)) return 'folder_zip'
  
  // Default icon for files - folder style like in screenshot
  return 'inventory_2'
}

function base64ToBlob(base64, type) {
  const binary = atob(base64)
  const array = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    array[i] = binary.charCodeAt(i)
  }
  return new Blob([array], { type })
}

const moveFolders = computed(() => {
  return mailbox.folders.filter(f => f.name !== mailbox.currentFolder)
})

function updateMessageLabels(messageId, labels) {
  const msg = mailbox.messages.find(m => m.message_id === messageId)
  if (msg) msg.labels = labels
}

// Helper to get sender name from various formats
function getSenderName(msg) {
  if (!msg) return 'Unknown'
  
  // Handle array format
  if (Array.isArray(msg.from) && msg.from.length > 0) {
    const sender = msg.from[0]
    if (typeof sender === 'object') {
      return sender.name || sender.email || 'Unknown'
    }
    if (typeof sender === 'string') {
      // Parse "Name <email>" format
      const match = sender.match(/^([^<]+)\s*</)
      return match ? match[1].trim() : sender
    }
  }
  
  // Handle string format
  if (typeof msg.from === 'string') {
    const match = msg.from.match(/^([^<]+)\s*</)
    return match ? match[1].trim() : msg.from
  }
  
  // Fallback to from_email or from_name
  return msg.from_name || msg.from_email || 'Unknown'
}

// Check if a message is sent by the current user
function isOurMessage(msg) {
  if (!msg) return false
  
  // Check the is_sent flag set by backend
  if (msg.is_sent) return true
  
  // Check if the message is from a Sent folder
  const folderLower = (msg.folder || '').toLowerCase()
  if (folderLower.includes('sent') || folderLower.includes('küldött') || folderLower.includes('elküldött')) {
    return true
  }
  
  // Fallback: check if the sender matches current user's email
  const userEmail = authStore?.user?.email?.toLowerCase()
  if (!userEmail) return false
  
  const fromEmail = getSenderEmail(msg)?.toLowerCase()
  return fromEmail === userEmail
}

// Show original message (raw headers and source)
function showOriginal() {
  showOriginalModal.value = true
  nextTick(() => {
    originalModalRef.value?.fetchRawMessage()
  })
}

// Check if current email has cached summary
const hasCachedSummary = computed(() => {
  if (!message.value) return false
  return aiStore.hasCachedSummary(
    mailbox.currentFolder,
    message.value.uid,
    message.value.message_id
  )
})

// Get cached summary info
const cachedSummaryInfo = computed(() => {
  if (!message.value) return null
  return aiStore.getSummaryCacheInfo(
    mailbox.currentFolder,
    message.value.uid,
    message.value.message_id
  )
})

// AI Summarize (forceRefresh = true to ignore cache)
async function summarizeEmail(forceRefresh = false) {
  if (!message.value) {
    toast.error(t('emailView.noEmailSelected'))
    return
  }
  
  // Cache info for this email (including user email for AI context)
  const cacheInfo = {
    folder: mailbox.currentFolder,
    uid: message.value.uid,
    messageId: message.value.message_id,
    userEmail: authStore.userEmail
  }
  
  // Build content from conversation messages if available
  // IMPORTANT: Include To and Cc headers so AI knows who sent/received each message
  let emailContent = ''
  
  if (message.value.isConversation && message.value.messages) {
    emailContent = message.value.messages
      .map(msg => {
        const from = msg.from?.[0]?.email || 'Unknown'
        const to = msg.to?.map(t => t.email).join(', ') || 'Unknown'
        const cc = msg.cc?.map(c => c.email).join(', ') || ''
        const date = new Date(msg.timestamp * 1000).toLocaleString()
        const body = msg.body_text || stripHtmlForAI(msg.body_html) || ''
        let headers = `From: ${from}\nTo: ${to}`
        if (cc) headers += `\nCc: ${cc}`
        headers += `\nDate: ${date}\nSubject: ${msg.subject}`
        return `${headers}\n\n${body}`
      })
      .join('\n\n---\n\n')
  } else {
    const msg = message.value
    const from = msg.from?.[0]?.email || 'Unknown'
    const to = msg.to?.map(t => t.email).join(', ') || 'Unknown'
    const cc = msg.cc?.map(c => c.email).join(', ') || ''
    const date = new Date(msg.timestamp * 1000).toLocaleString()
    const body = msg.body_text || stripHtmlForAI(msg.body_html) || ''
    let headers = `From: ${from}\nTo: ${to}`
    if (cc) headers += `\nCc: ${cc}`
    headers += `\nDate: ${date}\nSubject: ${msg.subject}`
    emailContent = `${headers}\n\n${body}`
  }
  
  if (!emailContent.trim() || emailContent.length < 20) {
    toast.error(t('emailView.emailContentIsEmpty'))
    return
  }
  
  // Open the panel and clear previous summary
  aiStore.openSummaryPanel()
  aiStore.clearSummary()
  
  // Start summarizing (with cache support)
  const result = await aiStore.summarize(emailContent, cacheInfo, forceRefresh)
  
  if (!result.success) {
    if (result.too_long) {
      toast.warning(result.error, { duration: 8000 })
    } else {
      console.error('Summarize failed:', result.error)
    }
  } else if (result.cached) {
    toast.info(t('emailView.usingCachedSummary', { hours: result.hoursRemaining }))
  }
}

// Strip HTML for AI processing
function stripHtmlForAI(html) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  return doc.body.textContent || ''
}
</script>

<template>
  <div ref="emailViewRoot" class="h-full flex flex-col bg-white dark:bg-[rgb(var(--color-surface))]">
    <!-- Empty state -->
    <div v-if="!message && showPlaceholder" class="flex-1 flex flex-col items-center justify-center text-surface-400">
      <span class="material-symbols-rounded text-6xl mb-3">mail</span>
      <p class="text-lg">{{ $t('emailView.selectAnEmailToRead') }}</p>
      <p class="text-xs mt-8 text-surface-300 dark:text-surface-600">
        made with <span class="text-red-400">&#9829;</span> by Pixel Ranger Studio
      </p>
    </div>
    
    <!-- Loading -->
    <div v-else-if="mailbox.loading.message" class="flex-1 flex items-center justify-center">
      <span class="spinner text-primary-500"></span>
    </div>
    
    <!-- Message content -->
    <template v-else-if="message">
      <!-- MOBILE: Simple top bar like Apple Mail -->
      <div v-if="layout.isMobile" class="h-14 flex items-center justify-between px-3 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900">
        <!-- Left: Back + message count -->
        <div class="flex items-center gap-2">
          <button @click="goBack" class="p-2 -ml-2 rounded-full hover:bg-surface-200 dark:hover:bg-surface-700" :title="$t('emailView.back')">
            <span class="material-symbols-rounded text-xl">arrow_back</span>
          </button>
          <span v-if="(message.messageCount || allConversationMessages.length) > 1" class="text-sm text-surface-500">
            {{ message.messageCount || allConversationMessages.length }}
          </span>
        </div>
        
        <!-- Right: Thread navigation arrows -->
        <div v-if="allConversationMessages.length > 1" class="flex items-center gap-1">
          <button
            @click="navigateToPreviousMessage"
            class="p-2 rounded-full hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500"
            :title="$t('emailView.previousMessage')"
          >
            <span class="material-symbols-rounded text-xl">expand_less</span>
          </button>
          <button
            @click="navigateToNextMessage"
            class="p-2 rounded-full hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500"
            :title="$t('emailView.nextMessage')"
          >
            <span class="material-symbols-rounded text-xl">expand_more</span>
          </button>
        </div>
      </div>
      
      <!-- DESKTOP: Full conversation header with all controls (3-column and 2-column desktop) -->
      <div v-if="!layout.isMobile" class="px-6 py-4 border-b border-surface-100 dark:border-surface-700">
        <!-- Back button for stacked layout (2-column mode) -->
        <div v-if="layout.isStackedLayout" class="flex items-center gap-3 mb-3">
          <button 
            @click="goBack" 
            class="p-2 -ml-2 rounded-full hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors" 
            :title="$t('emailView.backToMessages')"
          >
            <span class="material-symbols-rounded text-xl text-surface-600 dark:text-surface-400">arrow_back</span>
          </button>
          <span class="text-sm text-surface-500">{{ $t('emailView.backToMessages') }}</span>
        </div>
        
        <!-- Subject row -->
        <h1 class="text-xl font-semibold text-surface-900 dark:text-surface-100 flex items-center flex-wrap gap-2">
          <span
            v-if="message.important"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400"
            :title="$t('emailView.important')"
          >
            <span class="material-symbols-rounded text-sm" style="font-variation-settings: 'FILL' 1">priority_high</span>
            {{ $t('emailView.important') }}
          </span>
          <span>{{ message.subject || '(No subject)' }}</span>
        </h1>
        <div class="text-xs text-surface-400 mt-1">[UID: {{ message.uid }}]</div>
        
        <!-- Controls row: Labels, Reactions, Messages count + Action Badges -->
        <div v-if="allConversationMessages.length > 1" class="flex items-center justify-between gap-2 mt-3">
          <!-- Left group: Pin, Labels, Reactions, Messages count -->
          <div class="flex items-center gap-2 flex-wrap">
            <!-- Pin button -->
            <button 
              @click="togglePin"
              :class="[
                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium transition-colors',
                mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder)
                  ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300' 
                  : 'bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-600 dark:text-surface-400'
              ]"
              :title="mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder) ? $t('emailList.unpin') : $t('emailList.pin')"
            >
              <span class="material-symbols-rounded text-sm" :style="mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder) ? 'font-variation-settings: \'FILL\' 1' : ''">push_pin</span>
              {{ mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder) ? $t('emailList.unpin') : $t('emailList.pin') }}
            </button>
            
            <!-- Labels -->
            <span
              v-for="label in message.labels"
              :key="label.id"
              class="inline-flex items-center h-6 px-2.5 rounded-full text-[11px] font-medium text-white"
              :style="{ backgroundColor: label.color }"
            >
              {{ label.name }}
            </span>
            
            <!-- Reactions from main message -->
            <ReactionDisplay 
              v-if="reactionsEnabled && message.message_id"
              :message-id="message.message_id"
              compact
              @click="(reaction) => handleReactionClick(reaction, message)"
            />
            
            <!-- Messages count badge -->
            <span 
              :class="[
                'h-6 px-2.5 text-[11px] font-medium rounded-full inline-flex items-center',
                hasActiveThreadFilter 
                  ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300' 
                  : 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300'
              ]"
            >
              <template v-if="hasActiveThreadFilter">
                {{ filteredMessageCount.filtered }}/{{ filteredMessageCount.total }} messages
              </template>
              <template v-else>
                {{ message.messageCount || allConversationMessages.length }}
              </template>
            </span>
          </div>
          
          <!-- Right group: Action Badges - Spam, Block, Summarize -->
          <div class="flex items-center gap-2 flex-shrink-0">
            <!-- Unsubscribe button -->
            <button 
              v-if="hasUnsubscribe(message)"
              @click.stop="!isUnsubscribed(message) && initiateUnsubscribe(message)" 
              :class="[
                'px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium',
                isUnsubscribed(message) 
                  ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 cursor-default' 
                  : 'bg-amber-50 dark:bg-amber-900/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 text-amber-600 dark:text-amber-400'
              ]"
              :title="isUnsubscribed(message) ? $t('emailList.unsubscribed') : $t('emailView.unsubscribe')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">{{ isUnsubscribed(message) ? 'check_circle' : 'unsubscribe' }}</span>
              <span class="hidden sm:inline">{{ isUnsubscribed(message) ? $t('emailList.unsubscribed') : $t('emailView.unsubscribe') }}</span>
            </button>
            
            <!-- Not Spam button - only in spam folder -->
            <button 
              v-if="isSpamFolder"
              @click.stop="initiateNotSpam(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-green-50 dark:bg-green-900/30 hover:bg-green-100 dark:hover:bg-green-900/50 text-green-600 dark:text-green-400"
              :title="$t('emailView.markAsNotSpam')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">verified_user</span>
              <span class="hidden sm:inline">{{ $t('emailView.notSpam') }}</span>
            </button>
            
            <!-- Report Spam button - only when NOT in spam folder -->
            <button 
              v-if="!isSpamFolder && !isDraft"
              @click.stop="initiateReportSpam(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-surface-100 dark:bg-surface-700 hover:bg-red-50 dark:hover:bg-red-900/30 text-surface-600 dark:text-surface-300 hover:text-red-600 dark:hover:text-red-400"
              :title="$t('emailView.reportAsSpam')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">report</span>
              <span class="hidden sm:inline">{{ $t('emailView.spam') }}</span>
            </button>
            
            <!-- Block sender button -->
            <button 
              v-if="!isDraft && !isSenderBlocked(message)"
              @click.stop="initiateBlockSender(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-600 dark:text-surface-300"
              :title="$t('emailView.blockThisSender')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">block</span>
              <span class="hidden sm:inline">{{ $t('emailView.block') }}</span>
            </button>
            
            <!-- Sender blocked indicator -->
            <span 
              v-if="!isDraft && isSenderBlocked(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400"
              :title="$t('emailView.senderIsBlocked')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">block</span>
              <span class="hidden sm:inline">{{ $t('emailView.blocked') }}</span>
            </span>
            
            <!-- AI Summarize button - only show if AI addon enabled and configured -->
            <template v-if="aiAssistantEnabled && aiStore.isConfigured">
              <!-- Show cached summary indicator -->
              <button 
                v-if="hasCachedSummary && !aiStore.summarizing"
                @click="summarizeEmail(false)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300 hover:bg-green-200 dark:hover:bg-green-500/30 transition-colors"
                :title="`View cached summary (valid for ${cachedSummaryInfo?.hoursRemaining || 0}h) - Click again to refresh`"
              >
                <span class="material-symbols-rounded text-base">check_circle</span>
                Summary
                <span class="text-xs opacity-70">({{ cachedSummaryInfo?.hoursRemaining || 0 }}h)</span>
              </button>
              <!-- Refresh button for cached summaries -->
              <button 
                v-if="hasCachedSummary && !aiStore.summarizing"
                @click="summarizeEmail(true)"
                class="p-1.5 rounded-full text-surface-400 hover:text-primary-600 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors"
                :title="$t('emailView.generateNewSummary')"
              >
                <span class="material-symbols-rounded text-base">refresh</span>
              </button>
              <!-- Normal summarize button when no cache -->
              <button 
                v-if="!hasCachedSummary"
                @click="summarizeEmail(false)"
                :disabled="aiStore.summarizing"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 hover:bg-primary-200 dark:hover:bg-primary-500/30 transition-colors disabled:opacity-50"
                :title="$t('emailView.summarizeWithAi')"
              >
                <span v-if="aiStore.summarizing" class="spinner-xs"></span>
                <span v-else class="material-symbols-rounded text-base">auto_awesome</span>
                Summarize
              </button>
            </template>
            
            <!-- Expand/Collapse all buttons -->
            <button
              @click="expandAllMessages"
              class="p-1.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
              :title="$t('emailView.expandAllMessages')"
            >
              <span class="material-symbols-rounded text-lg">unfold_more</span>
            </button>
            <button
              @click="collapseAllMessages"
              class="p-1.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
              :title="$t('emailView.collapseAllMessages')"
            >
              <span class="material-symbols-rounded text-lg">unfold_less</span>
            </button>
          </div>
        </div>
        
        <!-- Single message (no thread) - show labels + action badges -->
        <div v-else class="flex items-center justify-between gap-2 mt-3">
          <!-- Left group: Pin, Labels, Reactions -->
          <div class="flex items-center gap-2 flex-wrap">
            <!-- Pin button -->
            <button 
              @click="togglePin"
              :class="[
                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium transition-colors',
                mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder)
                  ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300' 
                  : 'bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-600 dark:text-surface-400'
              ]"
              :title="mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder) ? $t('emailList.unpin') : $t('emailList.pin')"
            >
              <span class="material-symbols-rounded text-sm" :style="mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder) ? 'font-variation-settings: \'FILL\' 1' : ''">push_pin</span>
              {{ mailbox.isEmailPinned(message.uid, message.folder || mailbox.currentFolder) ? $t('emailList.unpin') : $t('emailList.pin') }}
            </button>
            
            <!-- Labels -->
            <span
              v-for="label in message.labels"
              :key="label.id"
              class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium text-white"
              :style="{ backgroundColor: label.color }"
            >
              {{ label.name }}
            </span>
            
            <!-- Reactions from main message -->
            <ReactionDisplay 
              v-if="reactionsEnabled && message.message_id"
              :message-id="message.message_id"
              compact
              @click="(reaction) => handleReactionClick(reaction, message)"
            />
            
            <!-- Linked Board Pills -->
            <template v-if="kanbanBoardsEnabled">
              <button
                v-for="board in linkedBoards"
                :key="board.id"
                @click="openBoardInSidebar(board.id)"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-colors group bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 hover:bg-primary-200 dark:hover:bg-primary-500/30"
                :title="'Open in sidebar: ' + board.name"
              >
                <span class="material-symbols-rounded text-sm">dashboard</span>
                <span class="max-w-[100px] truncate">{{ board.name }}</span>
                <span class="material-symbols-rounded text-xs opacity-60 group-hover:opacity-100">right_panel_open</span>
              </button>
            </template>
          </div>
          
          <!-- Right group: Action Badges -->
          <div class="flex items-center gap-2 flex-shrink-0">
            <!-- Unsubscribe button -->
            <button 
              v-if="hasUnsubscribe(message)"
              @click.stop="!isUnsubscribed(message) && initiateUnsubscribe(message)" 
              :class="[
                'px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium',
                isUnsubscribed(message) 
                  ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 cursor-default' 
                  : 'bg-amber-50 dark:bg-amber-900/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 text-amber-600 dark:text-amber-400'
              ]"
              :title="isUnsubscribed(message) ? $t('emailList.unsubscribed') : $t('emailView.unsubscribe')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">{{ isUnsubscribed(message) ? 'check_circle' : 'unsubscribe' }}</span>
              <span class="hidden sm:inline">{{ isUnsubscribed(message) ? $t('emailList.unsubscribed') : $t('emailView.unsubscribe') }}</span>
            </button>
            
            <!-- Not Spam button - only in spam folder -->
            <button 
              v-if="isSpamFolder"
              @click.stop="initiateNotSpam(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-green-50 dark:bg-green-900/30 hover:bg-green-100 dark:hover:bg-green-900/50 text-green-600 dark:text-green-400"
              :title="$t('emailView.markAsNotSpam')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">verified_user</span>
              <span class="hidden sm:inline">{{ $t('emailView.notSpam') }}</span>
            </button>
            
            <!-- Report Spam button - only when NOT in spam folder -->
            <button 
              v-if="!isSpamFolder && !isDraft"
              @click.stop="initiateReportSpam(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-surface-100 dark:bg-surface-700 hover:bg-red-50 dark:hover:bg-red-900/30 text-surface-600 dark:text-surface-300 hover:text-red-600 dark:hover:text-red-400"
              :title="$t('emailView.reportAsSpam')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">report</span>
              <span class="hidden sm:inline">{{ $t('emailView.spam') }}</span>
            </button>
            
            <!-- Block sender button -->
            <button 
              v-if="!isDraft && !isSenderBlocked(message)"
              @click.stop="initiateBlockSender(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full transition-colors flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-600 dark:text-surface-300"
              :title="$t('emailView.blockThisSender')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">block</span>
              <span class="hidden sm:inline">{{ $t('emailView.block') }}</span>
            </button>
            
            <!-- Sender blocked indicator -->
            <span 
              v-if="!isDraft && isSenderBlocked(message)"
              class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-full flex items-center gap-1 sm:gap-1.5 text-xs sm:text-sm font-medium bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400"
              :title="$t('emailView.senderIsBlocked')"
            >
              <span class="material-symbols-rounded text-sm sm:text-base">block</span>
              <span class="hidden sm:inline">{{ $t('emailView.blocked') }}</span>
            </span>
            
            <!-- AI Summarize button - only show if AI addon enabled and configured -->
            <template v-if="aiAssistantEnabled && aiStore.isConfigured">
              <!-- Show cached summary indicator -->
              <button 
                v-if="hasCachedSummary && !aiStore.summarizing"
                @click="summarizeEmail(false)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300 hover:bg-green-200 dark:hover:bg-green-500/30 transition-colors"
                :title="`View cached summary (valid for ${cachedSummaryInfo?.hoursRemaining || 0}h) - Click again to refresh`"
              >
                <span class="material-symbols-rounded text-base">check_circle</span>
                Summary
                <span class="text-xs opacity-70">({{ cachedSummaryInfo?.hoursRemaining || 0 }}h)</span>
              </button>
              <!-- Refresh button for cached summaries -->
              <button 
                v-if="hasCachedSummary && !aiStore.summarizing"
                @click="summarizeEmail(true)"
                class="p-1.5 rounded-full text-surface-400 hover:text-primary-600 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors"
                :title="$t('emailView.generateNewSummary')"
              >
                <span class="material-symbols-rounded text-base">refresh</span>
              </button>
              <!-- Normal summarize button when no cache -->
              <button 
                v-if="!hasCachedSummary"
                @click="summarizeEmail(false)"
                :disabled="aiStore.summarizing"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 hover:bg-primary-200 dark:hover:bg-primary-500/30 transition-colors disabled:opacity-50"
                :title="$t('emailView.summarizeWithAi')"
              >
                <span v-if="aiStore.summarizing" class="spinner-xs"></span>
                <span v-else class="material-symbols-rounded text-base">auto_awesome</span>
                Summarize
              </button>
            </template>
          </div>
        </div>
      </div>
      
      <!-- MOBILE: Clean sender/subject header like Apple Mail -->
      <div v-if="layout.isMobile" class="px-4 py-3 bg-white dark:bg-surface-900 border-b border-surface-200 dark:border-surface-700">
        <!-- Sender row -->
        <div class="flex items-center gap-3 mb-2">
          <!-- Avatar -->
          <div class="w-10 h-10 rounded-lg bg-primary-500 flex items-center justify-center flex-shrink-0">
            <span class="text-white font-semibold text-lg">
              {{ (getSenderName(message) || 'U')[0].toUpperCase() }}
            </span>
          </div>
          <!-- Sender info -->
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-surface-900 dark:text-surface-100 truncate">{{ getSenderName(message) }}</p>
            <p class="text-sm text-surface-500 dark:text-surface-400 truncate">To: {{ getFirstRecipient() }}</p>
            <p v-if="message.cc?.length" class="text-xs text-surface-400 dark:text-surface-500 truncate">Cc: {{ formatRecipients(message.cc) }}</p>
          </div>
          <!-- Time -->
          <span class="text-sm text-surface-500 dark:text-surface-400 flex-shrink-0">{{ formatTime(message.timestamp) }}</span>
        </div>
        <!-- Subject -->
        <h1 class="text-lg font-bold text-surface-900 dark:text-surface-100 flex items-center flex-wrap gap-2">
          <span
            v-if="message.important"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400"
            :title="$t('emailView.important')"
          >
            <span class="material-symbols-rounded text-sm" style="font-variation-settings: 'FILL' 1">priority_high</span>
            {{ $t('emailView.important') }}
          </span>
          <span>{{ message.subject || '(No subject)' }}</span>
        </h1>
      </div>

      <!-- Scheduled Email Banner -->
      <div v-if="message.isScheduled" class="mx-6 mt-3 mb-1 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50">
        <div class="flex items-center gap-3 flex-wrap">
          <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-xl">schedule_send</span>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
              Scheduled to send {{ formatScheduledTime(message.scheduled_at) }}
            </p>
            <p v-if="message.timezone" class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
              Timezone: {{ message.timezone }}
            </p>
          </div>
          <div class="flex items-center gap-2 flex-shrink-0">
            <button
              @click="editScheduledEmail(message.schedule_id)"
              class="inline-flex items-center gap-1.5 px-4 h-8 rounded-full text-sm font-medium bg-amber-600 hover:bg-amber-700 text-white transition-colors"
            >
              <span class="material-symbols-rounded text-base">edit</span>
              Edit
            </button>
            <button
              @click="cancelScheduledEmail(message.schedule_id)"
              class="inline-flex items-center gap-1.5 px-4 h-8 rounded-full text-sm font-medium bg-surface-200 dark:bg-surface-700 hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-700 dark:text-surface-300 hover:text-red-600 dark:hover:text-red-400 transition-colors"
            >
              <span class="material-symbols-rounded text-base">cancel</span>
              Cancel send
            </button>
          </div>
        </div>
      </div>
      
      <!-- Thread Search Bar - Only show for conversations with multiple messages (DESKTOP ONLY) -->
      <div v-if="allConversationMessages.length > 1 && !layout.isMobile" class="px-6 py-2 border-b border-surface-100 dark:border-surface-700 bg-surface-50 dark:bg-surface-900/50">
        <!-- Single row: Toggle button + all controls inline -->
        <div class="flex items-center gap-3">
          <!-- Toggle button -->
          <button
            @click="showThreadSearch = !showThreadSearch"
            :class="[
              'flex items-center gap-2 px-4 h-8 rounded-full text-sm font-medium transition-colors shrink-0',
              showThreadSearch || hasActiveThreadFilter
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
                : 'bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-600'
            ]"
          >
            <span class="material-symbols-rounded text-lg">search</span>
            Search in thread
          </button>
          
          <!-- Inline controls (when expanded) -->
          <template v-if="showThreadSearch">
            <!-- Search input -->
            <div class="relative flex-1 min-w-[180px] max-w-sm">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-lg text-surface-400">search</span>
              <input
                ref="threadSearchInput"
                v-model="threadSearchQuery"
                type="text"
                placeholder="Search keyword..."
                class="w-full pl-10 pr-8 h-8 rounded-full bg-white dark:bg-surface-800 border border-surface-300 dark:border-surface-600 text-surface-900 dark:text-surface-100 placeholder-surface-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent text-sm"
                @keydown.enter.prevent="goToNextMatch"
                @keydown.up.prevent="goToPrevMatch"
                @keydown.down.prevent="goToNextMatch"
                @keydown.escape="showThreadSearch = false"
              />
              <button
                v-if="threadSearchQuery"
                @click="threadSearchQuery = ''"
                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-full hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
              >
                <span class="material-symbols-rounded text-base">close</span>
              </button>
            </div>
            
            <!-- Navigation buttons (when matches found) -->
            <div v-if="totalOccurrences > 0 && threadSearchQuery" class="flex items-center gap-1 shrink-0">
              <span class="text-sm text-primary-600 dark:text-primary-400 font-medium px-2">{{ currentOccurrenceIndex + 1 }}/{{ totalOccurrences }}</span>
              <button @click="goToPrevMatch" :disabled="totalOccurrences <= 1" class="h-8 w-8 flex items-center justify-center rounded-full hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-500 disabled:opacity-30" title="Previous">
                <span class="material-symbols-rounded text-lg">expand_less</span>
              </button>
              <button @click="goToNextMatch" :disabled="totalOccurrences <= 1" class="h-8 w-8 flex items-center justify-center rounded-full hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-500 disabled:opacity-30" title="Next">
                <span class="material-symbols-rounded text-lg">expand_more</span>
              </button>
            </div>
            <span v-else-if="threadSearchQuery && threadSearchQuery.length >= 2 && totalOccurrences === 0" class="text-sm text-red-500 font-medium shrink-0 px-2">No matches</span>
            
            <!-- Divider -->
            <div class="h-6 w-px bg-surface-300 dark:bg-surface-600 shrink-0"></div>
            
            <!-- Date range -->
            <div class="flex items-center gap-2 shrink-0">
              <span class="text-sm text-surface-500 font-medium">Date:</span>
              <input v-model="threadSearchDateFrom" type="date" class="px-3 h-8 rounded-full bg-white dark:bg-surface-800 border border-surface-300 dark:border-surface-600 text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500 text-sm" :max="threadSearchDateTo || undefined" />
              <span class="text-surface-400 text-sm">-</span>
              <input v-model="threadSearchDateTo" type="date" class="px-3 h-8 rounded-full bg-white dark:bg-surface-800 border border-surface-300 dark:border-surface-600 text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500 text-sm" :min="threadSearchDateFrom || undefined" />
            </div>
            
            <!-- Divider -->
            <div class="h-6 w-px bg-surface-300 dark:bg-surface-600 shrink-0"></div>
            
            <!-- Direction filter -->
            <div class="flex items-center gap-2 shrink-0">
              <span class="text-sm text-surface-500 font-medium">Show:</span>
              <div class="flex rounded-full bg-surface-200 dark:bg-surface-700 h-8 items-center p-0.5">
                <button @click="threadSearchDirection = 'all'" :class="['px-3 h-7 rounded-full text-sm font-medium transition-colors', threadSearchDirection === 'all' ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-200']">All</button>
                <button @click="threadSearchDirection = 'sent'" :class="['px-3 h-7 rounded-full text-sm font-medium transition-colors flex items-center gap-1', threadSearchDirection === 'sent' ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-200']">
                  <span class="material-symbols-rounded text-sm">arrow_upward</span>Sent
                </button>
                <button @click="threadSearchDirection = 'received'" :class="['px-3 h-7 rounded-full text-sm font-medium transition-colors flex items-center gap-1', threadSearchDirection === 'received' ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-200']">
                  <span class="material-symbols-rounded text-sm">arrow_downward</span>Received
                </button>
              </div>
            </div>
            
            <!-- Clear button -->
            <button v-if="hasActiveThreadFilter" @click="clearThreadSearch" class="h-8 w-8 flex items-center justify-center rounded-full hover:bg-red-50 dark:hover:bg-red-900/20 text-red-500 shrink-0" title="Clear filters">
              <span class="material-symbols-rounded text-lg">close</span>
            </button>
            
            <!-- Results summary -->
            <span v-if="(threadSearchDateFrom || threadSearchDateTo || threadSearchDirection !== 'all') && filteredMessageCount.filtered !== filteredMessageCount.total" class="text-sm text-surface-500 shrink-0">
              {{ filteredMessageCount.filtered }}/{{ filteredMessageCount.total }}
            </span>
          </template>
          
          <!-- Collapsed state indicators -->
          <template v-else-if="hasActiveThreadFilter">
            <span class="text-sm text-primary-600 dark:text-primary-400">
              {{ filteredMessageCount.filtered }}/{{ filteredMessageCount.total }} messages
            </span>
            <button
              @click.stop="clearThreadSearch"
              class="h-8 w-8 flex items-center justify-center rounded-full hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300"
              title="Clear filters"
            >
              <span class="material-symbols-rounded text-lg">close</span>
            </button>
          </template>
        </div>
        
      </div>
      
      <!-- Messages in thread -->
      <div 
        ref="messagesContainer"
        class="flex-1 overflow-y-auto divide-y divide-surface-200 dark:divide-surface-700"
        @scroll="handleScrollForOccurrences"
      >
        <!-- No results message when filter is active but returns 0 -->
        <div 
          v-if="hasActiveThreadFilter && conversationMessages.length === 0" 
          class="flex flex-col items-center justify-center py-16 text-center"
        >
          <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">search_off</span>
          <p class="text-surface-500 dark:text-surface-400 text-lg mb-1">No messages match your search</p>
          <p class="text-surface-400 dark:text-surface-500 text-sm mb-4">
            {{ message.messageCount || allConversationMessages.length }} messages in this thread don't match the current filters
          </p>
          <button
            @click="clearThreadSearch"
            class="px-4 py-2 rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors text-sm font-medium"
          >
            Clear filters
          </button>
        </div>
        
        <div 
          v-for="(msg, index) in conversationMessages" 
          :key="msg.uid || index"
          :id="`message-${msg.uid}`"
          @click="setActiveMessage(msg)"
          :class="[
            'relative transition-colors duration-500',
            isMessageCollapsed(msg) ? 'cursor-pointer' : 'cursor-default',
            { 'bg-primary-50/30 dark:bg-primary-500/5': isOurMessage(msg) && !(hasActiveThreadFilter && index === currentMatchIndex) },
            { 'bg-primary-50/50 dark:bg-primary-500/10': conversationMessages.length > 1 && activeMessageUid === msg.uid },
            isMessageCollapsed(msg) && conversationMessages.length > 1 ? 'hover:bg-surface-50 dark:hover:bg-surface-800/50' : '',
            { 'current-search-match': hasActiveThreadFilter && index === currentMatchIndex }
          ]"
        >
          <!-- Message header - HIDDEN on mobile (we have cleaner header at top), shown on desktop -->
          <div class="hidden sm:block px-6 py-4">
            <div class="flex items-start gap-4">
              <!-- Collapse toggle (only show if multiple messages) -->
              <button 
                v-if="conversationMessages.length > 1"
                @click.stop="toggleMessageCollapse(msg)"
                class="flex-shrink-0 p-1 -ml-2 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
                :title="isMessageCollapsed(msg) ? 'Expand message' : 'Collapse message'"
              >
                <span class="material-symbols-rounded text-lg transition-transform" :class="{ 'rotate-180': !isMessageCollapsed(msg) }">
                  expand_more
                </span>
              </button>
              
              <!-- Direction indicator -->
              <div class="flex flex-col items-center gap-1">
                <span 
                  :class="[
                    'material-symbols-rounded text-sm',
                    isOurMessage(msg) ? 'text-primary-500' : 'text-surface-400'
                  ]"
                  :title="isOurMessage(msg) ? 'Sent' : 'Received'"
                >
                  {{ isOurMessage(msg) ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
              
              <!-- Avatar -->
              <div 
                :class="[
                  'w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0',
                  isOurMessage(msg) ? 'bg-primary-500/30' : 'bg-primary-500/20'
                ]"
              >
                <span class="text-primary-600 dark:text-primary-400 font-medium text-lg">
                  {{ isOurMessage(msg) ? 'Y' : (getSenderName(msg) || 'U')[0].toUpperCase() }}
                </span>
              </div>
              
              <!-- Details + Actions container - 2 column layout -->
              <div class="flex-1 min-w-0 flex gap-4">
                <!-- Left column: Name, Email, To -->
                <div class="min-w-0 flex-1 flex flex-col justify-center">
                  <!-- Row 1: Name -->
                  <div class="flex items-center gap-2">
                    <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                      {{ isOurMessage(msg) ? 'You' : getSenderName(msg) }}
                    </p>
                    <span 
                      v-if="isOurMessage(msg)"
                      class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 flex-shrink-0"
                    >
                      Sent
                    </span>
                    <!-- Linked account indicator -->
                    <span 
                      v-if="msg.linked_account"
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 text-xs flex-shrink-0"
                      :title="'Synced from ' + msg.linked_account"
                    >
                      <span class="material-symbols-rounded text-sm">link</span>
                      {{ msg.linked_account.split('@')[0] }}
                    </span>
                  </div>
                  <!-- Row 2: Email -->
                  <p class="text-sm text-surface-500 truncate">
                    <span 
                      class="hover:text-primary-600 dark:hover:text-primary-400 cursor-pointer hover:underline"
                      @click.stop="copyEmail(isOurMessage(msg) ? authStore?.user?.email : getSenderEmail(msg))"
                      :title="'Click to copy: ' + (isOurMessage(msg) ? authStore?.user?.email : getSenderEmail(msg))"
                    >{{ isOurMessage(msg) ? authStore?.user?.email : getSenderEmail(msg) }}</span>
                  </p>
                  <!-- Row 3: To recipients -->
                  <p v-if="msg.to?.length" class="text-sm text-surface-500 truncate">
                    <span>To: </span>
                    <template v-for="(recipient, idx) in getRecipientsArray(msg.to)" :key="idx">
                      <span 
                        class="hover:text-primary-600 dark:hover:text-primary-400 cursor-pointer hover:underline"
                        @click.stop="copyEmail(recipient.email)"
                        :title="'Click to copy: ' + recipient.email"
                      >{{ recipient.display }}</span><span v-if="idx < getRecipientsArray(msg.to).length - 1">, </span>
                    </template>
                  </p>
                  <!-- Row 4: CC recipients -->
                  <p v-if="msg.cc?.length" class="text-sm text-surface-500 truncate">
                    <span class="text-surface-400">Cc: </span>
                    <template v-for="(recipient, idx) in getRecipientsArray(msg.cc)" :key="'cc-' + idx">
                      <span 
                        class="hover:text-primary-600 dark:hover:text-primary-400 cursor-pointer hover:underline"
                        @click.stop="copyEmail(recipient.email)"
                        :title="'Click to copy: ' + recipient.email"
                      >{{ recipient.display }}</span><span v-if="idx < getRecipientsArray(msg.cc).length - 1">, </span>
                    </template>
                  </p>
                </div>
                
                <!-- Right column: Date + Actions stacked -->
                <div class="flex-shrink-0 flex flex-col items-end gap-1">
                  <!-- Row 1: Date -->
                  <span class="text-sm text-surface-500 whitespace-nowrap">
                    {{ formatDate(msg.timestamp) }}
                  </span>
                  
                  <!-- Row 2: Actions/Toolbar -->
                  <div class="hidden sm:flex items-center gap-0.5">
                    <!-- Reply group -->
                    <template v-if="!isDraft">
                      <button 
                        @click.stop="reply(msg)" 
                        class="p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                        :title="$t('emailView.reply')"
                      >
                        <span class="material-symbols-rounded text-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200">reply</span>
                      </button>
                      <button 
                        v-if="isActionInToolbar('replyAll')"
                        @click.stop="replyAll(msg)" 
                        class="p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                        :title="$t('emailView.replyAll')"
                      >
                        <span class="material-symbols-rounded text-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200">reply_all</span>
                      </button>
                      <button 
                        v-if="isActionInToolbar('forward')"
                        @click.stop="forward(msg)" 
                        class="p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                        :title="$t('emailView.forward')"
                      >
                        <span class="material-symbols-rounded text-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200">forward</span>
                      </button>
                    
                    <!-- Reactions -->
                      <ReactionPicker
                        v-if="reactionsEnabled && msg.message_id && isActionInToolbar('reactions')"
                        :message-id="msg.message_id"
                        :participants="getMessageParticipants(msg)"
                        :subject="msg.subject"
                        :snippet="getMessageSnippet(msg)"
                        mode="button"
                        position="bottom"
                        @reacted="handleReactionAdded"
                      />
                    </template>
                    
                    <!-- Edit Draft (for drafts only) -->
                    <button 
                      v-if="isDraft"
                      @click.stop="editDraft" 
                      class="p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                      :title="$t('emailView.editDraft')"
                    >
                      <span class="material-symbols-rounded text-lg text-primary-500">edit</span>
                    </button>
                    
                    <!-- Todo -->
                    <button 
                      v-if="tasksEnabled && isActionInToolbar('todo')"
                      @click.stop="addEmailToTodo()" 
                      class="p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                      :title="$t('emailView.addToTodo')"
                    >
                      <span class="material-symbols-rounded text-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200">add_task</span>
                    </button>
                    
                    <!-- Board Link (wrapper kept while popup is open so the picker has
                         a mount point even when the icon has collapsed into the menu) -->
                    <div v-if="kanbanBoardsEnabled && (isActionInToolbar('boards') || showBoardLinkMenu)" class="relative">
                      <button 
                        v-if="isActionInToolbar('boards') && linkedBoard"
                        @click.stop="goToLinkedBoard()" 
                        class="p-1.5 rounded-full bg-primary-100 dark:bg-primary-500/20 hover:bg-primary-200 dark:hover:bg-primary-500/30 transition-colors"
                        :title="'Go to board: ' + linkedBoard.board_name"
                      >
                        <span class="material-symbols-rounded text-lg text-primary-500">dashboard</span>
                      </button>
                      <button 
                        v-else-if="isActionInToolbar('boards')"
                        @click.stop="toggleBoardLinkMenu()" 
                        class="p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                        :title="$t('emailView.linkToBoard')"
                      >
                        <span class="material-symbols-rounded text-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200">dashboard</span>
                      </button>
                      
                      <!-- Board link menu -->
                      <div 
                        v-if="showBoardLinkMenu" 
                        class="absolute right-0 top-full mt-1 w-64 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 z-50 py-2"
                      >
                        <div class="px-3 pb-2 text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center justify-between">
                          <span>Link to Board</span>
                          <span v-if="linkedBoardIds.length > 0" class="text-primary-500 normal-case font-medium">
                            {{ linkedBoardIds.length }} linked
                          </span>
                        </div>
                        <div v-if="boardsStore.boards.length === 0 && !boardsStore.loading" class="px-3 py-4 text-center text-surface-500 text-sm">
                          No boards yet.<br>
                          <router-link to="/boards" class="text-primary-500 hover:underline">Create one</router-link>
                        </div>
                        <div v-else-if="boardsStore.loading" class="flex items-center justify-center py-4">
                          <span class="material-symbols-rounded animate-spin text-primary-500">progress_activity</span>
                        </div>
                        <div v-else class="max-h-64 overflow-y-auto">
                          <button
                            v-for="board in boardsStore.activeBoards"
                            :key="board.id"
                            @click.stop="isLinkedToBoard(board.id) ? goToBoardById(board.id) : linkToBoard(board.id)"
                            :disabled="boardLinkLoading"
                            :class="[
                              'w-full px-3 py-2 text-left text-sm flex items-center gap-2 disabled:opacity-50',
                              isLinkedToBoard(board.id)
                                ? 'bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 hover:bg-green-100 dark:hover:bg-green-900/50'
                                : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200'
                            ]"
                            :title="isLinkedToBoard(board.id) ? 'Click to open board' : 'Click to link'"
                          >
                            <span 
                              class="w-4 h-4 rounded flex-shrink-0" 
                              :style="{ backgroundColor: board.background_color || '#0ea5e9' }"
                            ></span>
                            <span class="truncate flex-1">{{ board.name }}</span>
                            <span 
                              v-if="isLinkedToBoard(board.id)"
                              class="material-symbols-rounded text-sm text-green-500"
                              title="Linked - Click to open"
                            >open_in_new</span>
                          </button>
                        </div>
                        <div class="border-t border-surface-200 dark:border-surface-700 mt-2 pt-2">
                          <router-link 
                            to="/boards" 
                            class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-primary-500"
                          >
                            <span class="material-symbols-rounded text-lg">add</span>
                            Create new board
                          </router-link>
                        </div>
                      </div>
                    </div>
                    
                    <div class="w-px h-4 bg-surface-200 dark:bg-surface-600 mx-0.5"></div>
                    
                    <!-- More actions dropdown -->
                    <div class="relative">
                      <button 
                        @click.stop="showMoreMenu = showMoreMenu === msg.uid ? null : msg.uid" 
                        class="p-1.5 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                        :title="$t('emailView.moreActions')"
                      >
                        <span class="material-symbols-rounded text-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200">more_vert</span>
                      </button>
                      
                      <!-- More actions menu -->
                      <div 
                        v-if="showMoreMenu === msg.uid" 
                        class="absolute right-0 top-full mt-1 w-52 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 z-50 py-1"
                      >
                        <!-- Collapsed toolbar actions (appear when there's no room in the toolbar) -->
                        <button
                          v-if="!isDraft && isActionInMenu('replyAll')"
                          @click.stop="replyAll(msg); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
                        >
                          <span class="material-symbols-rounded text-lg text-surface-400">reply_all</span>
                          {{ $t('emailView.replyAll') }}
                        </button>
                        <button
                          v-if="!isDraft && isActionInMenu('forward')"
                          @click.stop="forward(msg); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
                        >
                          <span class="material-symbols-rounded text-lg text-surface-400">forward</span>
                          {{ $t('emailView.forward') }}
                        </button>
                        <div
                          v-if="!isDraft && reactionsEnabled && msg.message_id && isActionInMenu('reactions')"
                          @click.stop="openInlineReactionPickerFromRow($event)"
                          class="w-full px-3 py-2 text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200 cursor-pointer"
                        >
                          <ReactionPicker
                            :message-id="msg.message_id"
                            :participants="getMessageParticipants(msg)"
                            :subject="msg.subject"
                            :snippet="getMessageSnippet(msg)"
                            mode="button"
                            position="left"
                            @reacted="(r) => { handleReactionAdded(r); showMoreMenu = null }"
                          />
                          <span>{{ $t('emailView.addReaction') }}</span>
                        </div>
                        <button
                          v-if="tasksEnabled && isActionInMenu('todo')"
                          @click.stop="addEmailToTodo(); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
                        >
                          <span class="material-symbols-rounded text-lg text-surface-400">add_task</span>
                          {{ $t('emailView.addToTodo') }}
                        </button>
                        <button
                          v-if="kanbanBoardsEnabled && isActionInMenu('boards') && !linkedBoard"
                          @click.stop="toggleBoardLinkMenu(); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
                        >
                          <span class="material-symbols-rounded text-lg text-surface-400">dashboard</span>
                          {{ $t('emailView.linkToBoard') }}
                        </button>
                        <button
                          v-if="kanbanBoardsEnabled && isActionInMenu('boards') && linkedBoard"
                          @click.stop="goToLinkedBoard(); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-primary-600 dark:text-primary-400"
                        >
                          <span class="material-symbols-rounded text-lg">dashboard</span>
                          {{ 'Go to: ' + linkedBoard.board_name }}
                        </button>
                        <div
                          v-if="hasCollapsedActions"
                          class="border-t border-surface-200 dark:border-surface-700 my-1"
                        ></div>

                        <button
                          @click.stop="toggleMessageStar(msg); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
                        >
                          <span
                            class="material-symbols-rounded text-lg"
                            :class="msg.flagged ? 'text-amber-400' : 'text-surface-400'"
                            :style="msg.flagged ? 'font-variation-settings: \'FILL\' 1' : ''"
                          >star</span>
                          {{ msg.flagged ? $t('emailView.unstar') : $t('emailView.star') }}
                        </button>
                        <button
                          @click.stop="toggleMoveMenu($event, msg); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
                        >
                          <span class="material-symbols-rounded text-lg text-surface-400">drive_file_move</span>
                          {{ $t('emailView.moveToFolder') }}
                        </button>
                        <button
                          @click.stop="toggleMessageLabelPicker($event, msg); showMoreMenu = null"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
                        >
                          <span class="material-symbols-rounded text-lg text-surface-400">label</span>
                          {{ $t('emailView.labels') }}
                        </button>
                        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                        <button @click.stop="archive(); showMoreMenu = null" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200">
                          <span class="material-symbols-rounded text-lg text-surface-400">archive</span>
                          Archive
                        </button>
                        <button @click.stop="toggleRead(); showMoreMenu = null" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200">
                          <span class="material-symbols-rounded text-lg text-surface-400">{{ message.seen ? 'mail' : 'drafts' }}</span>
                          {{ message.seen ? 'Mark as unread' : 'Mark as read' }}
                        </button>
                        <button @click.stop="markSpam(); showMoreMenu = null" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200">
                          <span class="material-symbols-rounded text-lg text-surface-400">report</span>
                          Report spam
                        </button>
                        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                        <button @click.stop="showDeleteConfirm = true; showMoreMenu = null" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-red-600 dark:text-red-400">
                          <span class="material-symbols-rounded text-lg">delete</span>
                          Delete
                        </button>
                        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                        <button @click.stop="showOriginal(); showMoreMenu = null" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200">
                          <span class="material-symbols-rounded text-lg text-surface-400">code</span>
                          Show original
                        </button>
                      </div>
                    </div>
                    
                  </div>
                </div>
              </div>
              
            </div>
          </div>
          
          <!-- Attachments (hidden when collapsed) -->
          <div v-if="(!isMessageCollapsed(msg) || conversationMessages.length === 1) && (msg.attachments?.length || getDriveAttachments(msg).length)" class="attachments-section px-2.5 sm:px-6 py-2.5 sm:py-3 border-t border-surface-100 dark:border-surface-700">
            <!-- Drive attachments header with Download All -->
            <div v-if="getDriveAttachments(msg).length" class="flex items-center justify-between mb-3">
              <span class="text-xs font-medium text-surface-500 dark:text-surface-400 uppercase tracking-wide">
                DRIVE ATTACHMENTS ({{ getDriveAttachments(msg).length }})
              </span>
              <button
                v-if="getDriveAttachments(msg).length >= 2"
                @click="downloadAllDriveAttachments(msg)"
                :disabled="downloadingAllDrive"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-900/50 transition-colors disabled:opacity-50"
              >
                <span v-if="downloadingAllDrive" class="flex items-center gap-1.5">
                  <span class="spinner w-3 h-3"></span>
                  {{ downloadAllProgress.current }}/{{ downloadAllProgress.total }}
                </span>
                <span v-else class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">cloud_download</span>
                  Download All
                </span>
              </button>
            </div>
            
            <!-- Regular attachments header with Save to Drive (clickable to expand/collapse) -->
            <div v-if="msg.attachments?.length" class="attachments-header flex items-center justify-between mb-2">
              <button
                @click="toggleAttachmentsExpanded(msg.uid)"
                class="flex items-center gap-2 text-xs font-medium text-surface-500 dark:text-surface-400 uppercase tracking-wide hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
              >
                <span 
                  class="material-symbols-rounded text-base transition-transform" 
                  :class="{ '-rotate-90': !isAttachmentsExpanded(msg.uid) }"
                >expand_more</span>
                ATTACHMENTS ({{ msg.attachments.length }})
              </button>
              <button
                @click="openSaveToDrive(msg)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-900/50 transition-colors"
              >
                <span class="material-symbols-rounded text-sm">cloud_upload</span>
                Save to Drive
              </button>
            </div>
            
            <!-- Regular attachments (card grid; "See all" popout when more than limit) — only shown when expanded -->
            <div v-if="msg.attachments?.length && isAttachmentsExpanded(msg.uid)" class="attachments-grid flex flex-wrap gap-3 mb-3 relative">
              <div
                v-for="attachment in getVisibleAttachments(msg)"
                :key="attachment.part"
                class="attachment-card relative flex items-center gap-2.5 pl-2 pr-2 py-2 min-w-[200px] max-w-[280px] rounded-lg bg-surface-100 dark:bg-surface-800 border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400 dark:focus-visible:ring-primary-500 transition-all cursor-pointer group select-none"
                :class="getSavedDriveFile(msg, attachment)
                  ? 'border-emerald-300/70 dark:border-emerald-600/40 hover:border-emerald-400 dark:hover:border-emerald-500/60 hover:bg-emerald-50/40 dark:hover:bg-emerald-900/20'
                  : 'border-surface-200/80 dark:border-surface-600/60 hover:border-primary-300/70 dark:hover:border-primary-600/50 hover:bg-surface-200/60 dark:hover:bg-surface-700/50'"
                role="button"
                tabindex="0"
                @click="openAttachmentPreview(attachment, msg)"
                @keydown.enter.prevent="openAttachmentPreview(attachment, msg)"
                @keydown.space.prevent="openAttachmentPreview(attachment, msg)"
              >
                <div
                  v-if="isImageAttachment(attachment) && getAttachmentThumbnail(msg, attachment) && !attachmentThumbFailed(msg, attachment)"
                  class="w-10 h-10 rounded-md overflow-hidden shrink-0 bg-surface-200 dark:bg-surface-700"
                >
                  <img
                    :src="getAttachmentThumbnail(msg, attachment)"
                    :alt="attachment.filename || ''"
                    class="w-full h-full object-cover"
                    @error="handleAttachmentThumbnailError(msg, attachment, $event)"
                  />
                </div>
                <div
                  v-else
                  class="w-10 h-10 rounded-md shrink-0 flex items-center justify-center"
                  :class="getAttachmentIconColorClasses(attachment)"
                >
                  <span class="material-symbols-rounded text-2xl leading-none">{{ getAttachmentIcon(attachment) }}</span>
                </div>
                <div class="flex flex-col min-w-0 flex-1 pr-1">
                  <span class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate" :title="attachment.filename">{{ attachment.filename }}</span>
                  <span class="text-xs text-surface-500 dark:text-surface-400 flex items-center gap-1">
                    <span>{{ formatSize(attachment.size) }}</span>
                    <span
                      v-if="getSavedDriveFile(msg, attachment)"
                      class="inline-flex items-center gap-0.5 text-emerald-600 dark:text-emerald-400"
                      :title="$t('emailView.savedToDrive')"
                    >
                      <span aria-hidden="true">·</span>
                      <span class="material-symbols-rounded text-[14px] leading-none">cloud_done</span>
                      <span>{{ $t('emailView.savedShort') }}</span>
                    </span>
                  </span>
                </div>
                <!-- Persistent Drive badge (always visible when saved, so the
                     user can see at a glance which attachments are in Drive) -->
                <span
                  v-if="getSavedDriveFile(msg, attachment)"
                  class="absolute top-1 left-1 inline-flex items-center justify-center w-4 h-4 rounded-full bg-emerald-500/90 text-white shadow-sm"
                  :title="$t('emailView.savedToDrive')"
                  aria-hidden="true"
                >
                  <span class="material-symbols-rounded text-[12px] leading-none">cloud_done</span>
                </span>
                <div class="absolute top-1 right-1 flex items-center gap-0.5 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity">
                  <button
                    v-if="canEditAttachmentDoc(attachment)"
                    type="button"
                    @click.stop="openAttachmentInEditor(attachment, msg)"
                    class="p-1 rounded-md bg-white/80 dark:bg-surface-900/70 backdrop-blur hover:bg-primary-100 dark:hover:bg-primary-900/60 text-primary-600 dark:text-primary-400 shadow-sm"
                    :title="$t('emailView.openInEditor')"
                  >
                    <span class="material-symbols-rounded text-sm">edit_document</span>
                  </button>
                  <!-- Saved variant: Share + Open in Drive -->
                  <template v-if="getSavedDriveFile(msg, attachment)">
                    <button
                      type="button"
                      @click.stop="shareSavedAttachment(msg, attachment)"
                      :disabled="isSharingDrive(msg, attachment)"
                      class="p-1 rounded-md bg-white/80 dark:bg-surface-900/70 backdrop-blur hover:bg-emerald-100 dark:hover:bg-emerald-900/60 text-emerald-600 dark:text-emerald-400 shadow-sm disabled:opacity-50"
                      :title="$t('emailView.copyShareLink')"
                    >
                      <span v-if="isSharingDrive(msg, attachment)" class="spinner w-3.5 h-3.5 inline-block"></span>
                      <span v-else class="material-symbols-rounded text-sm">share</span>
                    </button>
                    <button
                      type="button"
                      @click.stop="openSavedDriveFile(msg, attachment)"
                      class="p-1 rounded-md bg-white/80 dark:bg-surface-900/70 backdrop-blur hover:bg-primary-100 dark:hover:bg-primary-900/60 text-primary-600 dark:text-primary-400 shadow-sm"
                      :title="$t('emailView.openInDrive')"
                    >
                      <span class="material-symbols-rounded text-sm">folder_open</span>
                    </button>
                  </template>
                  <!-- Unsaved variant: Save to Drive -->
                  <button
                    v-else
                    type="button"
                    @click.stop="openSaveToDrive(msg, attachment)"
                    class="p-1 rounded-md bg-white/80 dark:bg-surface-900/70 backdrop-blur hover:bg-primary-100 dark:hover:bg-primary-900/60 text-primary-600 dark:text-primary-400 shadow-sm"
                    :title="$t('emailView.saveToDrive')"
                  >
                    <span class="material-symbols-rounded text-sm">cloud_upload</span>
                  </button>
                  <button
                    type="button"
                    @click.stop="downloadAttachment(attachment, $event, msg)"
                    :disabled="downloadingAttachment === attachment.part"
                    class="p-1 rounded-md bg-white/80 dark:bg-surface-900/70 backdrop-blur hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200 shadow-sm disabled:opacity-50"
                    :title="$t('emailView.download')"
                  >
                    <span v-if="downloadingAttachment === attachment.part" class="spinner w-3.5 h-3.5 inline-block"></span>
                    <span v-else class="material-symbols-rounded text-sm">download</span>
                  </button>
                </div>
              </div>
              
              <!-- "See all" button when more than preview limit attachments -->
              <button
                v-if="getHiddenAttachmentCount(msg) > 0"
                @click="toggleAttachmentsPopout($event, msg)"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary-100 dark:bg-primary-900/40 hover:bg-primary-200 dark:hover:bg-primary-800/60 text-primary-700 dark:text-primary-300 text-sm font-medium transition-colors"
              >
                <span class="material-symbols-rounded text-base">add</span>
                {{ getHiddenAttachmentCount(msg) }} more
              </button>
              
              <!-- Attachments popout modal (compact) -->
              <Teleport to="body">
                <div 
                  v-if="showAttachmentsPopout === msg.uid"
                  class="fixed inset-0 z-[100]"
                  @click="closeAttachmentsPopout"
                >
                  <div 
                    ref="attachmentsPopoutRef"
                    class="absolute bg-white dark:bg-surface-800 rounded-lg shadow-2xl border border-surface-200 dark:border-surface-700 py-2 max-w-xs w-[280px]"
                    :style="{ left: attachmentsPopoutPosition.x + 'px', top: attachmentsPopoutPosition.y + 'px' }"
                    @click.stop
                  >
                    <!-- Header (sticky) -->
                    <div class="flex items-center justify-between px-3 pb-2 mb-1 border-b border-surface-200 dark:border-surface-700">
                      <span class="text-xs font-medium text-surface-500 dark:text-surface-400">
                        {{ msg.attachments.length }} attachments
                      </span>
                      <button @click="closeAttachmentsPopout" class="p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400">
                        <span class="material-symbols-rounded text-base">close</span>
                      </button>
                    </div>
                    
                    <!-- Attachment list (scrollable) -->
                    <div class="space-y-0.5 max-h-[240px] overflow-y-auto">
                      <div
                        v-for="attachment in msg.attachments"
                        :key="'popout-' + attachment.part"
                        class="flex items-center gap-2 px-3 py-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer transition-colors group"
                        @click="openAttachmentPreview(attachment, msg); closeAttachmentsPopout()"
                      >
                        <span class="material-symbols-rounded text-base text-surface-400 flex-shrink-0">{{ getAttachmentIcon(attachment) }}</span>
                        <span class="flex-1 min-w-0 text-xs text-surface-700 dark:text-surface-300 truncate">{{ attachment.filename }}</span>
                        <span
                          v-if="getSavedDriveFile(msg, attachment)"
                          class="material-symbols-rounded text-[14px] leading-none text-emerald-500 flex-shrink-0"
                          :title="$t('emailView.savedToDrive')"
                        >cloud_done</span>
                        <span class="text-[10px] text-surface-400 flex-shrink-0">{{ formatSize(attachment.size) }}</span>
                        <button
                          v-if="getSavedDriveFile(msg, attachment)"
                          @click.stop="shareSavedAttachment(msg, attachment)"
                          :disabled="isSharingDrive(msg, attachment)"
                          class="p-1 rounded hover:bg-emerald-100 dark:hover:bg-emerald-900/40 text-emerald-500 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0"
                          :title="$t('emailView.copyShareLink')"
                        >
                          <span v-if="isSharingDrive(msg, attachment)" class="spinner w-3.5 h-3.5 inline-block"></span>
                          <span v-else class="material-symbols-rounded text-sm">share</span>
                        </button>
                        <button
                          @click.stop="downloadAttachment(attachment, $event, msg); closeAttachmentsPopout()"
                          class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0"
                          :title="$t('emailView.download')"
                        >
                          <span class="material-symbols-rounded text-sm">download</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </Teleport>
            </div>
            
            <!-- Drive attachments - card grid layout -->
            <div v-if="getDriveAttachments(msg).length" class="flex flex-wrap gap-3">
              <button
                v-for="(driveAtt, idx) in getDriveAttachments(msg)"
                :key="'drive-' + idx"
                @click.prevent="downloadDriveFile(driveAtt)"
                :disabled="isDownloadingDrive(driveAtt)"
                class="flex items-center gap-3 px-4 py-3 min-w-[260px] max-w-[420px] rounded-xl bg-primary-50 dark:bg-primary-900/30 border border-primary-200/60 dark:border-primary-700/40 hover:border-primary-300 dark:hover:border-primary-600 hover:bg-primary-100/80 dark:hover:bg-primary-800/40 transition-all group cursor-pointer text-left disabled:opacity-70 disabled:cursor-wait"
              >
                <div class="w-11 h-11 rounded-lg bg-primary-200 dark:bg-primary-700/60 flex items-center justify-center shrink-0">
                  <span v-if="isDownloadingDrive(driveAtt)" class="spinner w-5 h-5 text-primary-600 dark:text-primary-300"></span>
                  <span v-else class="material-symbols-rounded text-xl text-primary-600 dark:text-primary-300">{{ getDriveFileIcon(driveAtt.name) }}</span>
                </div>
                <div class="flex flex-col min-w-0 flex-1">
                  <span class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate" :title="driveAtt.name">{{ driveAtt.name }}</span>
                  <div class="flex items-center gap-2 text-xs whitespace-nowrap">
                    <span class="text-surface-500 dark:text-surface-400">{{ driveAtt.sizeText }}</span>
                    <span v-if="driveAtt.willExpire" class="inline-flex items-center gap-0.5 text-amber-600 dark:text-amber-400">
                      <span class="material-symbols-rounded text-[11px]">schedule</span>
                      {{ driveAtt.expiryDays }}d
                    </span>
                    <span class="inline-flex items-center gap-0.5 text-primary-600 dark:text-primary-400">
                      <span class="material-symbols-rounded text-[11px]">cloud</span>
                      Drive
                    </span>
                  </div>
                </div>
              </button>
            </div>
          </div>
          
          <!-- Blocked Images Banner (hidden when collapsed) -->
          <!-- Only show after trusted senders have loaded to prevent false positives -->
          <div 
            v-if="settingsStore.trustedSendersLoaded && (!isMessageCollapsed(msg) || conversationMessages.length === 1) && messageHasBlockedImages(msg)" 
            class="mx-4 mt-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg flex items-center justify-between gap-3"
          >
            <div class="flex items-center gap-2 text-amber-700 dark:text-amber-400">
              <span class="material-symbols-rounded">hide_image</span>
              <span class="text-sm">{{ $t('emailView.remoteImagesAreHiddenTo') }}</span>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <button 
                @click="loadImagesForMessage(msg)"
                class="text-sm px-3 py-1.5 rounded-full bg-amber-100 dark:bg-amber-800 text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-700 transition-colors"
              >
                Load Images
              </button>
              <button 
                @click="trustSenderAndLoadImages(msg)"
                class="text-sm px-3 py-1.5 rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors"
              >
                Trust Sender
              </button>
            </div>
          </div>
          
          <!-- Collapsed preview (shown when message is collapsed) -->
          <div 
            v-if="isMessageCollapsed(msg) && conversationMessages.length > 1" 
            class="px-2.5 sm:px-6 pb-2.5 sm:pb-4 -mt-2"
            @click.stop="toggleMessageCollapse(msg)"
          >
            <p class="text-sm text-surface-500 dark:text-surface-400 line-clamp-2 cursor-pointer hover:text-surface-700 dark:hover:text-surface-300">
              {{ getMessageSnippet(msg) }}
            </p>
          </div>
          
          <!-- Body (hidden when collapsed) -->
          <template v-if="!isMessageCollapsed(msg) || conversationMessages.length === 1">
            <div class="px-2.5 py-2.5 sm:px-4 sm:py-4 bg-surface-100 dark:bg-surface-800">
              <!-- Incoming reaction card for detected reaction emails -->
              <IncomingReactionCard
                v-if="reactionsEnabled && msg.is_reaction_email && msg.reaction_emoji"
                :emoji="msg.reaction_emoji"
                :from="msg.from"
                :subject="msg.subject"
                :confidence="msg.reaction_confidence"
                :date="msg.date"
              />
              
              <!-- Email body (always shown, even below reaction card as fallback) -->
              <!-- :key includes trustedSendersKey to force re-render when trusted senders load -->
              <div class="email-body-wrapper rounded-lg shadow-sm overflow-hidden">
                <EmailIframe
                  v-if="msg.body_html"
                  :key="`body-${msg.uid}-${settingsStore.trustedSendersLoaded}-${trustedSendersKey}-${loadedImagesForUids.size}`"
                  :html="getProcessedBody(msg)"
                  :dark-mode="isDarkMode"
                  :uid="msg.uid"
                  :search-query="threadSearchQuery"
                  @link-click="handleIframeLinkClick"
                  @mailto-click="handleIframeMailtoClick"
                  @calendar-action="handleIframeCalendarAction"
                  @text-selected="handleIframeTextSelected"
                  @selection-cleared="handleIframeSelectionCleared"
                  @body-click="calendarPickerMsg = null"
                />
                <pre v-else-if="threadSearchQuery && threadSearchQuery.trim()" class="whitespace-pre-wrap font-sans text-sm p-4 bg-white dark:bg-surface-800 text-gray-700 dark:text-surface-300" v-html="highlightSearchTerm(escapeHtml(msg.body_text), true)"></pre>
                <pre v-else class="whitespace-pre-wrap font-sans text-sm p-4 bg-white dark:bg-surface-800 text-gray-700 dark:text-surface-300" v-text="msg.body_text"></pre>
              </div>

              <!-- Meeting invite RSVP / Add-to-Calendar bar (shows for any invite with a parsed calendar_event,
                   whether or not the email itself includes an HTML body) -->
              <div v-if="msg.calendar_event" class="relative" data-calendar-picker>
                <MeetingInviteActions
                  :msg="msg"
                  :busy="rsvpBusyFor(msg)"
                  :adding-to-calendar="addingToCalendar && calendarPickerMsg?.uid === msg.uid"
                  @rsvp="(status) => handleRsvp(msg, status)"
                  @add-to-calendar="handleAddToCalendarClick(msg)"
                />

                <!-- Calendar picker dropdown (floats ABOVE the action bar when clicking Add to Calendar) -->
                <div
                  v-if="calendarPickerMsg?.uid === msg.uid && calendarStore.calendars?.length > 1"
                  class="absolute left-0 bottom-full mb-2 z-50 bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200 dark:border-surface-700 py-1 min-w-[220px]"
                >
                  <div class="px-4 py-2 text-xs font-medium text-surface-400 uppercase tracking-wide">{{ t('meetingInvite.selectCalendar') }}</div>
                  <button
                    v-for="cal in calendarStore.calendars"
                    :key="cal.id"
                    class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
                    @click.stop="addEventToCalendar(msg, cal.id)"
                  >
                    <span class="w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: cal.color || '#8b5cf6' }"></span>
                    <span class="text-surface-700 dark:text-surface-200">{{ cal.name }}</span>
                    <span v-if="cal.is_default" class="text-xs text-surface-400 ml-auto">default</span>
                  </button>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </template>
    
    <!-- Delete confirmation -->
    <ConfirmModal
      :show="showDeleteConfirm"
      :title="$t('emailView.deleteMessage')"
      message="Are you sure you want to delete this message?"
      confirm-text="Delete"
      type="danger"
      @confirm="deleteMessage"
      @cancel="showDeleteConfirm = false"
    />
    
    <!-- Unsubscribe confirmation modal -->
    <ConfirmModal
      :show="showUnsubscribeConfirm"
      title="Unsubscribe"
      :message="unsubscribingMessage ? `Unsubscribe from emails like this from ${getSenderDisplay(unsubscribingMessage)}?` : 'Unsubscribe from this mailing list?'"
      :confirm-text="unsubscribing ? 'Unsubscribing...' : 'Unsubscribe'"
      type="warning"
      :loading="unsubscribing"
      @confirm="executeUnsubscribe"
      @cancel="cancelUnsubscribe"
    >
      <template #default>
        <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">
          This will send an unsubscribe request to the sender. You may still receive a few more emails before the request is processed.
        </p>
      </template>
    </ConfirmModal>
    
    <!-- Second confirmation after URL opened - ask if user completed unsubscribe -->
    <ConfirmModal
      :show="showUnsubscribeUrlConfirm"
      title="Did you complete the unsubscribe?"
      message="A new tab was opened for you to confirm the unsubscribe. Did you complete the process on that page?"
      confirm-text="Yes, I unsubscribed"
      cancel-text="No, I didn't"
      type="info"
      @confirm="confirmUrlUnsubscribe"
      @cancel="cancelUrlUnsubscribe"
    >
      <template #default>
        <div class="mt-3 p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg border border-amber-200 dark:border-amber-800">
          <p class="text-sm text-amber-700 dark:text-amber-300 flex items-start gap-2">
            <span class="material-symbols-rounded text-base flex-shrink-0 mt-0.5">info</span>
            <span>Only click "Yes" if you successfully confirmed the unsubscribe on the website. If the page required you to click a button or fill a form, make sure you completed that step.</span>
          </p>
        </div>
      </template>
    </ConfirmModal>
    
    <!-- Report Spam confirmation modal -->
    <ConfirmModal
      :show="showSpamConfirm"
      title="Report as Spam"
      :message="spamMessage ? `Mark this email from ${getSenderDisplay(spamMessage)} as spam?` : 'Mark this email as spam?'"
      :confirm-text="reportingSpam ? 'Reporting...' : 'Report Spam'"
      type="warning"
      :loading="reportingSpam"
      @confirm="executeReportSpam"
      @cancel="cancelReportSpam"
    >
      <template #default>
        <div class="mt-3 space-y-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            This will move the email to spam and help train the spam filter.
          </p>
          <label class="flex items-center gap-2 cursor-pointer">
            <input 
              type="checkbox" 
              v-model="blockSenderToo"
              class="w-4 h-4 rounded border-surface-300 text-primary-600 focus:ring-primary-500"
            />
            <span class="text-sm text-surface-700 dark:text-surface-300">Also block this sender</span>
          </label>
        </div>
      </template>
    </ConfirmModal>
    
    <!-- Not Spam confirmation modal -->
    <ConfirmModal
      :show="showNotSpamConfirm"
      title="Not Spam"
      :message="notSpamMessage ? `Mark this email from ${getSenderDisplay(notSpamMessage)} as not spam?` : 'Mark this email as not spam?'"
      :confirm-text="markingNotSpam ? 'Moving...' : 'Not Spam'"
      type="info"
      :loading="markingNotSpam"
      @confirm="executeNotSpam"
      @cancel="cancelNotSpam"
    >
      <template #default>
        <div class="mt-3 space-y-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            This will move the email to your inbox and help train the spam filter.
          </p>
          <label class="flex items-center gap-2 cursor-pointer">
            <input 
              type="checkbox" 
              v-model="addToSafeToo"
              class="w-4 h-4 rounded border-surface-300 text-primary-600 focus:ring-primary-500"
            />
            <span class="text-sm text-surface-700 dark:text-surface-300">{{ $t('emailView.addSenderToSafeList') }}</span>
          </label>
        </div>
      </template>
    </ConfirmModal>
    
    <!-- Block Sender confirmation modal -->
    <ConfirmModal
      :show="showBlockConfirm"
      title="Block Sender"
      :message="blockMessage ? `Block all future emails from ${getSenderDisplay(blockMessage)}?` : 'Block this sender?'"
      :confirm-text="blockingAction ? 'Blocking...' : 'Block Sender'"
      type="danger"
      :loading="blockingAction"
      @confirm="executeBlockSender"
      @cancel="cancelBlockSender"
    >
      <template #default>
        <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">
          Future emails from this sender will be automatically moved to spam.
        </p>
      </template>
    </ConfirmModal>
    
    <!-- Move menu (Teleported for correct positioning) -->
    <Teleport to="body">
      <div 
        v-if="showMoveMenu" 
        class="fixed inset-0 z-[100]"
        @click="showMoveMenu = null; folderSearchQuery = ''"
      >
        <div 
          class="fixed w-64 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 overflow-hidden"
          :style="{ left: moveMenuPosition.x + 'px', top: moveMenuPosition.y + 'px' }"
          @click.stop
        >
          <!-- Header with search -->
          <div class="p-2 border-b border-surface-100 dark:border-surface-700">
            <p class="text-xs font-medium text-surface-500 uppercase tracking-wider mb-2">Move to:</p>
            <div class="relative">
              <input
                v-model="folderSearchQuery"
                type="text"
                class="input text-sm w-full pl-8"
                :placeholder="$t('emailView.searchFolders')"
                @click.stop
              />
              <span class="material-symbols-rounded absolute left-2 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
            </div>
          </div>
          
          <!-- Folder list -->
          <div class="max-h-64 overflow-y-auto p-1">
            <div v-if="filteredMoveFolders.length === 0" class="p-3 text-center text-surface-500 text-sm">
              {{ folderSearchQuery ? $t('emailView.noMatches') : $t('emailView.noMatches') }}
            </div>
            <button
              v-for="folder in filteredMoveFolders"
              :key="folder.name"
              @click.stop="moveToFolder(folder.name)"
              class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 rounded-md flex items-center gap-2 text-surface-700 dark:text-surface-200 transition-colors"
            >
              <span class="material-symbols-rounded text-lg text-surface-400">folder</span>
              <span class="truncate">{{ getFolderDisplayName(folder.name) }}</span>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Label picker (Teleported for correct positioning) -->
    <Teleport to="body">
      <div 
        v-if="openLabelPickerUid" 
        class="fixed inset-0 z-[100]"
        @click="openLabelPickerUid = null"
      >
        <div 
          class="fixed"
          :style="{ left: labelPickerPosition.x + 'px', top: labelPickerPosition.y + 'px' }"
          @click.stop
        >
          <LabelPicker
            :message-id="conversationMessages.find(m => m.uid === openLabelPickerUid)?.message_id"
            :message-labels="conversationMessages.find(m => m.uid === openLabelPickerUid)?.labels || []"
            @close="openLabelPickerUid = null"
            inline
          />
        </div>
      </div>
    </Teleport>
    
    <!-- Attachment Preview Modal -->
    <AttachmentPreview
      :show="showAttachmentPreview"
      :attachments="previewMessage?.attachments || message?.attachments || []"
      :initial-index="previewAttachmentIndex"
      :folder="previewMessage?.folder || message?.folder || mailbox.currentFolder"
      :uid="previewMessage?.uid || message?.uid"
      :email-subject="previewMessage?.subject || message?.subject"
      :email-date="previewMessage?.date || message?.date"
      :sender-email="previewMessage?.from?.[0]?.email || previewMessage?.from_email || message?.from?.[0]?.email || message?.from_email"
      @close="closeAttachmentPreview"
      @saved="onPreviewAttachmentSaved"
    />
    
    <!-- Email Original Modal (Show Original like Gmail) -->
    <EmailOriginalModal
      ref="originalModalRef"
      :show="showOriginalModal"
      :folder="message?.folder || mailbox.currentFolder"
      :uid="message?.uid"
      @close="showOriginalModal = false"
    />
    
    <!-- Save to Drive Modal -->
    <SaveToDriveModal
      :show="showSaveToDriveModal"
      :attachments="saveToDriveMessage?.attachments || []"
      :folder="saveToDriveMessage?.folder || mailbox.currentFolder"
      :uid="saveToDriveMessage?.uid"
      :subject="saveToDriveMessage?.subject"
      :sender-email="saveToDriveMessage?.from_email || saveToDriveMessage?.from?.email"
      @close="closeSaveToDrive"
      @saved="onAttachmentsSavedToDrive"
    />
    
    <!-- Text selection tooltip for creating todo -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="tasksEnabled && showSelectionTooltip && selectedText"
          class="selection-tooltip fixed z-[100] transform -translate-x-1/2 -translate-y-full"
          :style="{ left: selectionPosition.x + 'px', top: selectionPosition.y + 'px' }"
        >
          <button 
            @click="createTodoFromSelection"
            class="flex items-center gap-1.5 px-3 py-1.5 bg-surface-900 dark:bg-surface-100 text-white dark:text-surface-900 rounded-lg shadow-lg text-sm font-medium hover:bg-surface-800 dark:hover:bg-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">add_task</span>
            Add to Todo
          </button>
          <div class="absolute left-1/2 -translate-x-1/2 top-full w-0 h-0 border-l-[6px] border-r-[6px] border-t-[6px] border-transparent border-t-surface-900 dark:border-t-surface-100"></div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- MOBILE: Bottom action bar (teleported to body to escape overflow-hidden parent) -->
    <Teleport to="body">
      <Transition name="slide-up-nav">
        <nav 
          v-if="layout.isMobile && message"
          class="mobile-bottom-nav"
        >
          <button 
            @click="showDeleteConfirm = true"
            class="mobile-nav-item"
            title="Delete"
          >
            <span class="material-symbols-rounded">delete</span>
            <span class="mobile-nav-label">Delete</span>
          </button>
          
          <button 
            @click="showMobileMoveFolderMenu = !showMobileMoveFolderMenu"
            class="mobile-nav-item"
            title="Move"
          >
            <span class="material-symbols-rounded">folder</span>
            <span class="mobile-nav-label">{{ $t('emailView.move') }}</span>
          </button>
          
          <button 
            @click="reply(message)"
            class="mobile-nav-item"
            :title="$t('emailView.reply')"
          >
            <span class="material-symbols-rounded">reply</span>
            <span class="mobile-nav-label">{{ $t('emailView.reply') }}</span>
          </button>
          
          <button 
            @click="compose.open()"
            class="mobile-nav-item"
            :title="$t('emailView.compose')"
          >
            <span class="material-symbols-rounded">edit_square</span>
            <span class="mobile-nav-label">{{ $t('emailView.compose') }}</span>
          </button>
        </nav>
      </Transition>
    </Teleport>
    
    <!-- Mobile move folder menu -->
    <Teleport to="body">
      <Transition name="slide-up">
        <div 
          v-if="showMobileMoveFolderMenu"
          class="fixed bottom-14 left-0 right-0 bg-white dark:bg-surface-800 border-t border-surface-200 dark:border-surface-700 rounded-t-2xl shadow-2xl z-50 max-h-[60vh] overflow-y-auto safe-area-bottom"
        >
          <div class="p-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between sticky top-0 bg-white dark:bg-surface-800">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ $t('emailView.moveToFolder') }}</h3>
            <button @click="showMobileMoveFolderMenu = false" class="p-1 text-surface-400">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          <div class="py-2">
            <button
              v-for="folder in moveFolders"
              :key="folder.name"
              @click="moveToFolder(folder.name); showMobileMoveFolderMenu = false"
              class="w-full px-4 py-3 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200"
            >
              <span class="material-symbols-rounded text-xl text-surface-400">folder</span>
              <span>{{ getFolderDisplayName(folder.name) }}</span>
            </button>
          </div>
        </div>
      </Transition>
      <div v-if="showMobileMoveFolderMenu" class="fixed inset-0 bg-black/30 z-40" @click="showMobileMoveFolderMenu = false"></div>
    </Teleport>
    
    <!-- Collab Editor Modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="showCollabEditor"
          class="fixed inset-0 z-50 bg-surface-50 dark:bg-surface-900 flex flex-col"
        >
          <!-- Header -->
          <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
            <div class="flex items-center gap-3">
              <button 
                @click="closeCollabEditor"
                class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
                :title="$t('emailView.closeEditor')"
              >
                <span class="material-symbols-rounded">arrow_back</span>
              </button>
              <span class="material-symbols-rounded text-primary-500">
                {{ collabEditorMode === 'presentation' ? 'slideshow' : 'article' }}
              </span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 truncate max-w-md">
                {{ collabDocumentTitle }}
              </h2>
            </div>
            <div class="flex items-center gap-2">
              <span class="text-sm text-surface-500">
                {{ collabEditorMode === 'presentation' ? 'Presentation' : 'Document' }}
              </span>
            </div>
          </div>
          
          <!-- Editor Content -->
          <div class="flex-1 overflow-hidden">
            <CollabDocumentEditor 
              v-if="collabEditorMode === 'document' && collabDocumentId"
              :document-uuid="collabDocumentId"
              :user="{ email: authStore.user?.email, name: authStore.user?.name || authStore.user?.email }"
              @close="closeCollabEditor"
              @share="openCollabShareModal"
            />
            <CollabPresentationEditor 
              v-else-if="collabEditorMode === 'presentation' && collabDocumentId"
              :document-uuid="collabDocumentId"
              :user="{ email: authStore.user?.email, name: authStore.user?.name || authStore.user?.email }"
              @close="closeCollabEditor"
              @share="openCollabShareModal"
            />
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Collab Share Modal -->
    <CollabShareModal
      v-if="showCollabShareModal && collabDocumentId"
      :show="showCollabShareModal"
      :document-uuid="collabDocumentId"
      :current-user-email="authStore.user?.email"
      @close="showCollabShareModal = false"
    />
    
  </div>
</template>

<style scoped>
.toolbar-btn {
  @apply w-8 h-8 flex items-center justify-center rounded-full text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors;
}

.toolbar-divider {
  @apply w-px h-5 bg-surface-300 dark:bg-surface-600 mx-0.5;
}

.highlight-message {
  @apply bg-primary-100/50 dark:bg-primary-500/20;
}

/* Email iframe container */
.email-iframe-container {
  min-height: 60px;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.spinner-xs {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Slide down transition for thread search panel */
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  max-height: 0;
  margin-top: 0;
}

.slide-down-enter-to,
.slide-down-leave-from {
  opacity: 1;
  max-height: 200px;
}

/* Search highlight styling - pill shape */
:deep(.search-highlight) {
  background-color: rgb(var(--color-primary-200));
  color: rgb(var(--color-primary-900));
  padding: 0.15em 0.5em;
  border-radius: 9999px;
  font-weight: 500;
  transition: all 0.2s ease;
}

.dark :deep(.search-highlight) {
  background-color: rgb(var(--color-primary-500) / 0.3);
  color: rgb(var(--color-primary-100));
}

/* Current occurrence - more prominent */
:deep(.search-highlight.current-occurrence) {
  background-color: rgb(var(--color-primary-500));
  color: white;
  font-weight: 700;
  box-shadow: 0 2px 8px rgb(var(--color-primary-500) / 0.5);
  transform: scale(1.05);
}

.dark :deep(.search-highlight.current-occurrence) {
  background-color: rgb(var(--color-primary-400));
  color: rgb(var(--color-primary-950));
  box-shadow: 0 2px 8px rgb(var(--color-primary-400) / 0.5);
}

/* Current match pulse animation */
.search-highlight-pulse {
  animation: searchPulse 1.5s ease-out;
}

@keyframes searchPulse {
  0% {
    box-shadow: 0 0 0 0 rgb(var(--color-primary-500) / 0.6);
  }
  50% {
    box-shadow: 0 0 0 8px rgb(var(--color-primary-500) / 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgb(var(--color-primary-500) / 0);
  }
}

/* Current match indicator border */
.current-search-match {
  border-left: 3px solid rgb(var(--color-primary-500)) !important;
  background-color: rgb(var(--color-primary-50)) !important;
}

.dark .current-search-match {
  background-color: rgb(var(--color-primary-500) / 0.1) !important;
}

/* Slide up transition for mobile menus */
.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.25s ease-out;
}

.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(100%);
}

/* Slide up transition for bottom nav bar */
.slide-up-nav-enter-active,
.slide-up-nav-leave-active {
  transition: transform 0.3s ease-out;
}

.slide-up-nav-enter-from,
.slide-up-nav-leave-to {
  transform: translateY(100%);
}

/* Safe area for notched devices */
.safe-area-bottom {
  padding-bottom: env(safe-area-inset-bottom, 0);
}
</style>

<!-- Unscoped styles removed: email body now renders inside an isolated iframe -->
