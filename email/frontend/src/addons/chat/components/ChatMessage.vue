<script setup>
import { ref, computed, nextTick, watch, onUnmounted } from 'vue'
import { getToken } from '@/services/tokenStorage'
import { getApiOrigin } from '@/services/serverRegistry'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'
import { useCallLauncher } from '@/composables/useCallLauncher'
import { useAuthStore } from '@/stores/auth'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useChatPresence } from '@/composables/useChatPresence'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import ChatImageGallery from './ChatImageGallery.vue'
import CollabPreviewModal from './CollabPreviewModal.vue'
import VoiceMessageBubble from './VoiceMessageBubble.vue'
import EmbedDriveFile from './embeds/EmbedDriveFile.vue'
import EmbedDriveFolder from './embeds/EmbedDriveFolder.vue'
import EmbedCalendarEvent from './embeds/EmbedCalendarEvent.vue'
import EmbedBoard from './embeds/EmbedBoard.vue'
import EmbedBoardCard from './embeds/EmbedBoardCard.vue'
import EmbedCollabDoc from './embeds/EmbedCollabDoc.vue'
import EmbedMoodBoard from './embeds/EmbedMoodBoard.vue'
import LinkPreviewCard from './LinkPreviewCard.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import PollMessage from './PollMessage.vue'
import EmojiPicker from 'vue3-emoji-picker'
import 'vue3-emoji-picker/css'

const props = defineProps({
  message: {
    type: Object,
    required: true
  },
  participant: {
    type: Object,
    default: null
  },
  showTimestamp: {
    type: Boolean,
    default: true
  },
  isGroupChat: {
    type: Boolean,
    default: false
  }
})

const chatStore = useChatStore()
const callStore = useCallStore()
const callLauncher = useCallLauncher()
const colleaguesStore = useColleaguesStore()
const { getStatusColor } = useChatPresence()

// Is this message from the current user?
const isOwn = computed(() => {
  const currentColleague = colleaguesStore.currentColleague
  if (currentColleague) {
    return props.message.sender_id === currentColleague.id
  }
  // Fallback: match by email when currentColleague hasn't loaded yet (bootstrap race / OAuth users)
  const auth = useAuthStore()
  const myEmail = auth.userEmail?.toLowerCase()
  if (myEmail && props.message.sender_email) {
    return props.message.sender_email.toLowerCase() === myEmail
  }
  return false
})

// Get the actual sender for this message (for correct avatar in group chats)
const messageSender = computed(() => {
  // First try to find in colleagues store
  const colleague = colleaguesStore.colleagueById?.[props.message.sender_id]
  if (colleague) return colleague
  
  // Fallback to participant prop (for DMs)
  if (props.participant) return props.participant
  
  // Last resort: create a minimal object from message data
  return {
    id: props.message.sender_id,
    display_name: props.message.sender_name,
    email: props.message.sender_email || ''
  }
})

// Extract URLs from message for link previews
const extractedUrls = computed(() => {
  const content = props.message.content || ''
  if (isPollMessage.value || isEmbedMessage.value || isGifMessage.value) return []
  const urlRegex = /https?:\/\/[^\s<\]]+/g
  const matches = content.match(urlRegex) || []
  // Filter out YouTube URLs (handled as embeds) and image URLs
  return matches.filter(url => {
    if (/(?:youtube\.com\/watch|youtu\.be)/.test(url)) return false
    if (/\.(jpg|jpeg|png|gif|webp|svg|bmp)(\?.*)?$/i.test(url)) return false
    return true
  }).slice(0, 3) // Max 3 previews per message
})

// Detect poll message format: [poll:"Question"|"Option 1"|"Option 2"]
const isPollMessage = computed(() => {
  return /^\[poll:/.test(props.message.content || '')
})

// Context menu
const showMenu = ref(false)
const menuPosition = ref({ x: 0, y: 0 })
const moreButtonRef = ref(null)

function clampMenuPosition(x, y, menuW = 192, menuH = 320) {
  return {
    x: Math.max(8, Math.min(x, window.innerWidth - menuW - 8)),
    y: Math.max(8, Math.min(y, window.innerHeight - menuH - 8))
  }
}

function handleContextMenu(e) {
  e.preventDefault()
  menuPosition.value = clampMenuPosition(e.clientX, e.clientY)
  showMenu.value = true
}

function handleMoreClick(e) {
  const button = e.currentTarget
  const rect = button.getBoundingClientRect()
  menuPosition.value = clampMenuPosition(
    rect.left - 192 + rect.width,
    rect.bottom + 4
  )
  showMenu.value = true
}

function closeMenu() {
  showMenu.value = false
}

// Actions
const isEditing = ref(false)
const editContent = ref('')
const editTextareaRef = ref(null)

function startEdit() {
  closeMenu()
  editContent.value = props.message.content
  isEditing.value = true
  // Focus and auto-resize after Vue updates the DOM
  nextTick(() => {
    if (editTextareaRef.value) {
      editTextareaRef.value.focus()
      autoResizeEditTextarea()
    }
  })
}

function autoResizeEditTextarea() {
  if (editTextareaRef.value) {
    editTextareaRef.value.style.height = 'auto'
    editTextareaRef.value.style.height = Math.min(editTextareaRef.value.scrollHeight, 300) + 'px'
  }
}

async function saveEdit() {
  if (!editContent.value.trim()) return
  
  await chatStore.editMessage(props.message.id, editContent.value)
  isEditing.value = false
}

function cancelEdit() {
  isEditing.value = false
  editContent.value = ''
}

// Delete confirmation modal
const showDeleteModal = ref(false)
const isDeleting = ref(false)

function promptDelete() {
  closeMenu()
  showDeleteModal.value = true
}

async function confirmDelete() {
  isDeleting.value = true
  await chatStore.deleteMessage(props.message.id)
  isDeleting.value = false
  showDeleteModal.value = false
}

function handleReply() {
  closeMenu()
  chatStore.setReplyingTo({
    id: props.message.id,
    content: props.message.content,
    sender_name: props.message.sender_name
  })
}

async function handleTogglePin() {
  closeMenu()
  const result = await chatStore.togglePinMessage(props.message.id)
  if (!result.success) {
    console.error('Failed to toggle pin:', result.error)
  }
}

function handleOpenThread() {
  closeMenu()
  chatStore.openThread(props.message.id)
}

async function handleToggleBookmark() {
  closeMenu()
  const result = await chatStore.toggleBookmark(props.message.id)
  if (result.success) {
    props.message.is_bookmarked = result.bookmarked
  }
}

// Save attachments to Drive
const isSavingToDrive = ref(false)

async function handleSaveToDrive() {
  closeMenu()
  if (!props.message.attachments?.length) return
  
  isSavingToDrive.value = true
  
  try {
    // Use the chat store method to save attachments
    const result = await chatStore.saveMessageAttachmentsToDrive(
      props.message.conversation_id,
      props.message.id
    )
    
    if (result.success) {
      // Show success feedback (toast is handled in store)
    }
  } catch (err) {
    console.error('Failed to save to drive:', err)
  }
  
  isSavingToDrive.value = false
}

// Reactions
const showReactionPicker = ref(false) // 'quick' row visible
const showFullEmojiPicker = ref(false) // full picker expanded
const reactionBtnRef = ref(null)
const messageBubbleRef = ref(null)
const reactionPickerStyle = ref({})
const quickPickerStyle = ref({})

// Detect dark mode for emoji picker
const isDark = computed(() => document.documentElement.classList.contains('dark'))

// Quick reaction emojis (common messenger reactions)
const quickReactions = ['\u{1F44D}', '\u{2764}\u{FE0F}', '\u{1F602}', '\u{1F62E}', '\u{1F622}', '\u{1F621}', '\u{1F44F}', '\u{1F525}']

// Check if emoji is a native unicode emoji (vs Material icon name)
function isNativeEmoji(str) {
  return /[^\x00-\x7F]/.test(str)
}

function toggleReactionPicker() {
  if (showReactionPicker.value) {
    showReactionPicker.value = false
    showFullEmojiPicker.value = false
    return
  }
  // Position quick reactions row directly above the message bubble
  const el = messageBubbleRef.value || reactionBtnRef.value
  if (el) {
    const rect = el.getBoundingClientRect()
    const rowWidth = 370
    const rowHeight = 44
    // Sit right above the bubble with minimal gap
    let top = rect.top - rowHeight - 4
    // Align to the bubble horizontally
    let left = isOwn.value ? rect.right - rowWidth : rect.left
    if (top < 8) top = rect.bottom + 4
    if (left < 8) left = 8
    if (left + rowWidth > window.innerWidth - 8) left = window.innerWidth - rowWidth - 8
    quickPickerStyle.value = {
      top: top + 'px',
      left: left + 'px'
    }
  }
  showReactionPicker.value = true
  showFullEmojiPicker.value = false
}

function expandToFullPicker() {
  // Position full picker above the message bubble
  const el = messageBubbleRef.value || reactionBtnRef.value
  if (el) {
    const rect = el.getBoundingClientRect()
    const pickerWidth = 350
    const pickerHeight = 380
    let top = rect.top - pickerHeight - 4
    let left = isOwn.value ? rect.right - pickerWidth : rect.left
    if (top < 8) top = rect.bottom + 4
    if (left < 8) left = 8
    if (left + pickerWidth > window.innerWidth - 8) left = window.innerWidth - pickerWidth - 8
    reactionPickerStyle.value = {
      top: top + 'px',
      left: left + 'px'
    }
  }
  showFullEmojiPicker.value = true
}

function onSelectReaction(emoji) {
  showReactionPicker.value = false
  showFullEmojiPicker.value = false
  chatStore.toggleReaction(props.message.id, emoji.i)
}

function selectQuickReaction(emoji) {
  showReactionPicker.value = false
  showFullEmojiPicker.value = false
  chatStore.toggleReaction(props.message.id, emoji)
}

async function addQuickReaction(emoji) {
  showReactionPicker.value = false
  showFullEmojiPicker.value = false
  await chatStore.toggleReaction(props.message.id, emoji)
}

onUnmounted(() => {
  showReactionPicker.value = false
  showFullEmojiPicker.value = false
})

function formatTime(dateString) {
  return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

// Get color for emoji icon
function getEmojiColor(emojiName) {
  const emojiColors = {
    'favorite': '#ef4444', // red heart
    'favorite_border': '#ef4444',
    'heart_broken': '#ef4444',
    'thumb_up': '#3b82f6', // blue
    'thumb_down': '#6b7280', // gray
    'sentiment_satisfied': '#fbbf24', // yellow
    'sentiment_very_satisfied': '#fbbf24',
    'sentiment_dissatisfied': '#f59e0b', // orange
    'celebration': '#a855f7', // purple
    'local_fire_department': '#f97316', // orange fire
    'star': '#fbbf24', // yellow star
    'rocket_launch': '#6366f1', // indigo
    'waving_hand': '#fcd34d', // yellow hand
    'volunteer_activism': '#ec4899', // pink
    'check_circle': '#22c55e', // green
    'cancel': '#ef4444', // red
    'warning': '#f59e0b', // orange
    'info': '#3b82f6', // blue
    'help': '#8b5cf6', // purple
    'lightbulb': '#fbbf24', // yellow
    'emoji_objects': '#fbbf24',
    'emoji_events': '#a855f7',
    'mood': '#fbbf24',
    'face': '#fbbf24',
    'visibility': '#6b7280'
  }
  return emojiColors[emojiName] || 'currentColor'
}

// Format preview text (for replies, etc) - hide GIF/emoji raw formats
function formatPreviewText(text) {
  if (!text) return ''
  // Replace GIF format with "GIF"
  if (/^\[gif:(.+?):(\d+):(\d+)\]$/.test(text)) {
    return 'GIF'
  }
  // Replace embed format with friendly label
  const embedMatch = text.match(/^\[embed:(\w+):\d+\]$/)
  if (embedMatch) {
    const labels = { drive_file: 'File', drive_folder: 'Folder', calendar_event: 'Event', board: 'Board', board_card: 'Card', collab_doc: 'Document', mood_board: 'Mood Board' }
    return labels[embedMatch[1]] || 'Shared content'
  }
  // Replace emoji codes with their names
  return text.replace(/:([a-z_]+):/g, '[$1]')
}

// Group reactions by emoji
const groupedReactions = computed(() => {
  if (!props.message.reactions?.length) return []
  
  const groups = {}
  for (const r of props.message.reactions) {
    if (!groups[r.emoji]) {
      groups[r.emoji] = { emoji: r.emoji, count: 0, names: [] }
    }
    groups[r.emoji].count++
    groups[r.emoji].names.push(r.colleague_name || 'Unknown')
  }
  
  return Object.values(groups)
})

// Attachments
const showGallery = ref(false)
const galleryStartIndex = ref(0)

const imageAttachments = computed(() => {
  return (props.message.attachments || []).filter(a => a.category === 'image' || a.type?.startsWith('image/'))
})

const fileAttachments = computed(() => {
  // Exclude audio attachments from voice messages - they render as VoiceMessageBubble
  if (isVoiceMessage.value) return []
  return (props.message.attachments || []).filter(a => a.category !== 'image' && !a.type?.startsWith('image/'))
})

// Voice message detection
const isVoiceMessage = computed(() => {
  return props.message.content_type === 'voice'
})

// Call message detection (missed call, completed call, etc.)
const isCallMessage = computed(() => {
  return props.message.content_type === 'call'
})

// Embed message detection (shared content: drive files, boards, calendar events)
const isEmbedMessage = computed(() => {
  return props.message.content_type === 'embed' || /^\[embed:\w+:\d+\]$/.test(props.message.content || '')
})

const embedData = computed(() => {
  if (!isEmbedMessage.value) return null
  const match = (props.message.content || '').match(/^\[embed:(\w+):(\d+)\]$/)
  return match ? { type: match[1], id: parseInt(match[2]) } : null
})

const embedComponentMap = {
  drive_file: EmbedDriveFile,
  drive_folder: EmbedDriveFolder,
  calendar_event: EmbedCalendarEvent,
  board: EmbedBoard,
  board_card: EmbedBoardCard,
  collab_doc: EmbedCollabDoc,
  mood_board: EmbedMoodBoard,
}

const embedComponent = computed(() => {
  return embedData.value ? embedComponentMap[embedData.value.type] : null
})

const callMessageData = computed(() => {
  if (!isCallMessage.value) return null
  const content = props.message.content || ''
  // Format: [call:status:type:info:callerEmail] or [call:declined:type:rejectorEmail:callerEmail]
  // Duration can contain colons (e.g. "05:30" or "01:23:45"), email is always last (has @)
  const inner = content.replace(/^\[call:/, '').replace(/\]$/, '')
  const parts = inner.split(':')
  if (parts.length < 3) return null
  
  const status = parts[0]     // missed, completed, cancelled, declined
  const callType = parts[1]   // voice, video
  
  // Extract all emails (parts containing @)
  const emails = parts.filter(p => p.includes('@'))
  const callerEmail = emails.length > 0 ? emails[emails.length - 1] : null  // last email = caller
  const callerName = callerEmail ? callerEmail.split('@')[0] : 'Unknown'
  
  // For declined: first email is the rejector, second is caller
  const rejectorEmail = status === 'declined' && emails.length > 1 ? emails[0] : null
  const rejectorName = rejectorEmail ? rejectorEmail.split('@')[0] : null
  
  // Duration info: non-email middle parts joined
  const info = parts.slice(2).filter(p => !p.includes('@')).join(':')
  
  let icon, labelName, labelAction, color
  
  if (status === 'missed') {
    icon = callType === 'video' ? 'missed_video_call' : 'phone_missed'
    labelName = callerName
    labelAction = `Missed ${callType === 'video' ? 'video ' : ''}call`
    color = 'text-red-500 dark:text-red-400'
  } else if (status === 'completed') {
    icon = callType === 'video' ? 'videocam' : 'call'
    const durationStr = info || '00:00'
    labelName = null
    labelAction = callType === 'video' ? `Video call (${durationStr})` : `Call (${durationStr})`
    color = 'text-green-500 dark:text-green-400'
  } else if (status === 'declined') {
    icon = callType === 'video' ? 'videocam_off' : 'call_end'
    labelName = rejectorName || 'Someone'
    labelAction = `${callType === 'video' ? 'Video c' : 'C'}all rejected`
    color = 'text-orange-500 dark:text-orange-400'
  } else if (status === 'cancelled') {
    icon = 'call_end'
    labelName = callerName
    labelAction = `Missed ${callType === 'video' ? 'video ' : ''}call`
    color = 'text-surface-400 dark:text-surface-500'
  } else {
    icon = 'call'
    labelName = null
    labelAction = 'Call'
    color = 'text-surface-400'
  }
  
  return { status, callType, info, icon, labelName, labelAction, color, callerEmail, rejectorEmail }
})

// Call back action for missed/declined calls
function callBack(type) {
  const convId = chatStore.activeConversationId
  if (!convId) return
  const conv = chatStore.conversations.find(c => c.id === convId)
  if (!conv) return
  const auth = useAuthStore()
  const myEmail = auth.userEmail?.toLowerCase()
  const emails = (conv.participants || [])
    .filter(p => p.email?.toLowerCase() !== myEmail)
    .map(p => ({
      email: p.email,
      name: p.display_name || p.name || null,
      avatar: p.avatar || p.avatar_url || null
    }))
  if (emails.length) {
    callLauncher.startCall(convId, type || 'voice', emails)
  }
}

const voiceAttachment = computed(() => {
  if (!isVoiceMessage.value) return null
  const atts = props.message.attachments || []
  return atts.find(a => a.type?.startsWith('audio/') || a.category === 'audio') || atts[0] || null
})

const voiceDuration = computed(() => {
  if (props.message.voice_duration) return parseFloat(props.message.voice_duration)
  // Parse from content: [voice:duration:waveform]
  const match = (props.message.content || '').match(/\[voice:(\d+(?:\.\d+)?)/)
  return match ? parseFloat(match[1]) : 0
})

const voiceWaveform = computed(() => {
  // Parse waveform data from content: [voice:duration:w1,w2,w3,...]
  const match = (props.message.content || '').match(/\[voice:\d+(?:\.\d+)?:([0-9,]+)\]/)
  if (!match) return []
  return match[1].split(',').map(v => parseInt(v) / 100)
})

function openGallery(index = 0) {
  galleryStartIndex.value = index
  showGallery.value = true
}

// For non-image file preview
const showFilePreview = ref(false)
const previewAttachment = ref(null)

function openFilePreview(attachment) {
  previewAttachment.value = attachment
  showFilePreview.value = true
}

function buildAuthenticatedUrl(basePath, conversationId, filename) {
  const url = basePath + '/api/chat/attachments/' + conversationId + '/' + encodeURIComponent(filename)
  // Append JWT token as query param for browser-loaded resources (<img>, <audio>)
  // that cannot send Authorization headers
  const token = getToken('webmail_token')
  return token ? url + '?token=' + encodeURIComponent(token) : url
}

function getAttachmentUrl(att) {
  const basePath = getApiOrigin()
  const path = att.path || att.url || ''
  const match = path.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
  if (match) {
    const [, conversationId, filename] = match
    return buildAuthenticatedUrl(basePath, conversationId, filename)
  }
  console.warn('Unexpected attachment path format:', path, att)
  return ''
}

function getThumbnailUrl(att) {
  const basePath = getApiOrigin()
  if (att.thumbnail) {
    const match = att.thumbnail.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
    if (match) {
      const [, conversationId, filename] = match
      return buildAuthenticatedUrl(basePath, conversationId, filename)
    }
  }
  return getAttachmentUrl(att)
}

// Track failed images
const failedImages = ref(new Set())

function handleImageError(event, imgId) {
  failedImages.value.add(imgId)
  console.warn('Failed to load image:', imgId, event.target.src)
}

function downloadAttachment(att) {
  const link = document.createElement('a')
  link.href = getAttachmentUrl(att)
  link.download = att.original_name || att.filename || 'file'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function getFileIcon(mimeType) {
  if (!mimeType) return 'attach_file'
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('video/')) return 'movie'
  if (mimeType.startsWith('audio/')) return 'audio_file'
  if (mimeType.includes('pdf')) return 'picture_as_pdf'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'description'
  if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'table_chart'
  if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('7z')) return 'folder_zip'
  return 'attach_file'
}

function formatFileSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

// Rich content parsing
const youtubeLinks = computed(() => {
  const content = props.message.content || ''
  const youtubeRegex = /(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]{11})(?:[&?][\w=]*)?/g
  const matches = []
  let match
  while ((match = youtubeRegex.exec(content)) !== null) {
    matches.push({ videoId: match[1], url: match[0] })
  }
  return matches
})

// Escape HTML to prevent XSS
function escapeHtml(text) {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function sanitizeUrl(url) {
  try {
    const decoded = decodeURIComponent(url)
    if (!/^https?:\/\//i.test(decoded)) return ''
    return encodeURI(decoded)
  } catch {
    return /^https?:\/\//i.test(url) ? url : ''
  }
}

// Detect if content looks like code (auto-detect without backticks)
function detectCodeLang(text) {
  // Check for HTML/Vue template code
  if (/<[a-zA-Z][^>]*(?:v-|:|@|class=|id=)[^>]*>/.test(text) || 
      /<\/[a-zA-Z]+>/.test(text) ||
      /<[a-zA-Z][\w-]*\s+[\w-]+="/.test(text)) {
    return 'html'
  }
  // PHP
  if (/<\?php/.test(text) || /\$[a-zA-Z_]\w*/.test(text) && /->|::/.test(text)) {
    return 'php'
  }
  // SQL
  if (/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i.test(text) && /\b(FROM|INTO|TABLE|WHERE)\b/i.test(text)) {
    return 'sql'
  }
  // Python
  if (/\bdef\s+\w+\s*\(/.test(text) || /\bimport\s+\w+/.test(text) && /:$/.test(text)) {
    return 'python'
  }
  // JavaScript/TypeScript patterns
  if (/\b(const|let|var|function|async|await|import|export|class)\b/.test(text) && 
      (/[{};]/.test(text) || /=>/.test(text) || /\bfunction\s*\(/.test(text))) {
    return 'javascript'
  }
  // CSS
  if (/[.#][\w-]+\s*\{/.test(text) || /@(media|keyframes|import)/.test(text)) {
    return 'css'
  }
  // Bash/Shell
  if (/^(#!\/bin\/(ba)?sh|sudo|apt|npm|yarn|cd|ls|mkdir|chmod)/.test(text)) {
    return 'bash'
  }
  return null
}

// Check if entire message looks like code
function isLikelyCodeBlock(text) {
  if (!text || text.length < 20) return false
  
  const lines = text.split('\n')
  if (lines.length < 3) return false
  
  // Require at least one line with leading whitespace (indentation)
  const hasIndentation = lines.some(l => /^\s{2,}\S/.test(l))
  if (!hasIndentation) return false
  
  const codeIndicators = [
    /<[a-zA-Z][\w-]*[\s/>]/, // HTML tags
    /^\s*(const|let|var|function|class|import|export|return|if|else|for|while)\b/m, // JS keywords at line start
    /[{}\[\]();]/, // Brackets
    /^\s*[@.:v-][\w-]+/m, // Vue directives
    /=["'][^"']*["']/, // Attribute assignments
    /\$\w+/, // PHP/bash variables
    /->|=>|::/, // Arrow operators
  ]
  
  let matches = 0
  for (const pattern of codeIndicators) {
    if (pattern.test(text)) matches++
  }
  
  // If multiple code patterns match and has multiple lines, likely code
  return matches >= 2
}

// Check if message is a GIF
const isGifMessage = computed(() => {
  const content = props.message.content || ''
  return /^\[gif:(.+?):(\d+):(\d+)\]$/.test(content)
})

// Parse GIF data from message
const gifData = computed(() => {
  if (!isGifMessage.value) return null
  const content = props.message.content || ''
  const match = content.match(/^\[gif:(.+?):(\d+):(\d+)\]$/)
  if (match) {
    return {
      url: match[1],
      width: parseInt(match[2]),
      height: parseInt(match[3])
    }
  }
  return null
})

// GIF auto-stop after 4 loops (~12 seconds)
const gifPlaying = ref(true)
const gifStaticFrame = ref('') // Stores first frame as data URL
const gifKey = ref(0) // Used to force re-render and restart GIF animation
let gifTimer = null

function captureGifFrame(imgElement) {
  if (!imgElement || gifStaticFrame.value) return
  
  try {
    const canvas = document.createElement('canvas')
    canvas.width = imgElement.naturalWidth || imgElement.width
    canvas.height = imgElement.naturalHeight || imgElement.height
    const ctx = canvas.getContext('2d')
    ctx.drawImage(imgElement, 0, 0)
    gifStaticFrame.value = canvas.toDataURL('image/png')
  } catch (e) {
    // CORS or other error - fall back to showing blurred gif
    console.warn('Could not capture GIF frame:', e)
  }
}

function handleGifLoad(event) {
  // Capture first frame when GIF loads
  captureGifFrame(event.target)
}

function startGifTimer() {
  gifPlaying.value = true
  gifKey.value++ // Force reload of the GIF to restart animation
  
  if (gifTimer) clearTimeout(gifTimer)
  
  // Stop after ~12 seconds (4 loops of ~3 seconds each)
  gifTimer = setTimeout(() => {
    gifPlaying.value = false
  }, 12000)
}

function replayGif() {
  startGifTimer()
}

// Start/restart timer when message changes or GIF mounts
watch(() => props.message.id, () => {
  if (gifTimer) clearTimeout(gifTimer)
  gifPlaying.value = true
  gifStaticFrame.value = ''
  if (isGifMessage.value) {
    startGifTimer()
  }
}, { immediate: true })

// Cleanup timer on unmount
onUnmounted(() => {
  if (gifTimer) clearTimeout(gifTimer)
})

// Reset transient UI state when the underlying message changes (virtual list recycling)
watch(() => props.message.id, () => {
  showReactionPicker.value = false
  showFullEmojiPicker.value = false
  showMenu.value = false
  isEditing.value = false
})

// Convert :emoji: text to Material Symbols icons
function renderEmojis(text) {
  // Match :emoji_name: pattern
  return text.replace(/:([a-z_]+):/g, (match, emojiName) => {
    // Valid Material Symbols emoji names
    const validEmojis = [
      'sentiment_satisfied', 'sentiment_very_satisfied', 'sentiment_dissatisfied',
      'thumb_up', 'thumb_down', 'favorite', 'celebration', 'local_fire_department',
      'waving_hand', 'visibility', 'volunteer_activism', 'star', 'rocket_launch',
      'emoji_objects', 'emoji_events', 'mood', 'face', 'lightbulb', 'check_circle',
      'cancel', 'warning', 'info', 'help', 'heart_broken', 'favorite_border'
    ]
    
    if (validEmojis.includes(emojiName)) {
      return `<span class="material-symbols-rounded inline-flex align-middle text-lg mx-0.5" style="font-size: 1.25em; vertical-align: -0.15em;">${emojiName}</span>`
    }
    return match
  })
}

// Parse and render rich content (cached to avoid re-running regex pipeline on unrelated re-renders)
let _richContentCache = { key: null, value: '' }
const richContent = computed(() => {
  const cacheKey = `${props.message.id}:${props.message.content || ''}:${props.message.is_edited}`
  if (_richContentCache.key === cacheKey) return _richContentCache.value

  let content = props.message.content || ''
  
  // If it's an embed message, return empty (handled by embed component)
  if (isEmbedMessage.value) {
    return ''
  }
  
  // If it's a GIF message, return empty (handled separately)
  if (isGifMessage.value) {
    return ''
  }
  
  // First, extract and placeholder explicit code blocks (with ```)
  const codeBlocks = []
  content = content.replace(/```(\w*)\n?([\s\S]*?)```/g, (match, lang, code) => {
    const index = codeBlocks.length
    codeBlocks.push({ lang: lang || 'plaintext', code: code.trim() })
    return `__CODE_BLOCK_${index}__`
  })
  
  // Auto-detect code blocks (entire message looks like code)
  if (codeBlocks.length === 0 && isLikelyCodeBlock(content)) {
    const detectedLang = detectCodeLang(content) || 'plaintext'
    const index = codeBlocks.length
    codeBlocks.push({ lang: detectedLang, code: content.trim() })
    content = `__CODE_BLOCK_${index}__`
  }
  
  // Extract inline code
  const inlineCodes = []
  content = content.replace(/`([^`]+)`/g, (match, code) => {
    const index = inlineCodes.length
    inlineCodes.push(code)
    return `__INLINE_CODE_${index}__`
  })
  
  // Extract emoji patterns before escaping (to preserve them)
  const emojis = []
  content = content.replace(/:([a-z_]+):/g, (match, emojiName) => {
    const index = emojis.length
    emojis.push(emojiName)
    return `__EMOJI_${index}__`
  })
  
  // Escape HTML in remaining content
  content = escapeHtml(content)
  
  // Highlight @mentions (after escaping, before URL conversion)
  content = content.replace(/@(here|channel|[A-Za-z][A-Za-z0-9.\-_]{0,24}(?:\s[A-Za-z][A-Za-z0-9.\-_]{0,24})?)(?=\s|$|[,.:;!?\)\]\}&lt;]|<br>)/g, (match) => {
    return `<span class="mention-highlight font-semibold text-primary-500 dark:text-primary-400 bg-primary-50 dark:bg-primary-500/10 px-0.5 rounded cursor-pointer">${match}</span>`
  })
  
  // Convert URLs to links (but not YouTube - those get embeds)
  const urlRegex = /https?:\/\/[^\s<]+/g
  content = content.replace(urlRegex, (url) => {
    if (url.match(/(?:youtube\.com\/watch|youtu\.be)/)) {
      return url
    }
    const safeUrl = sanitizeUrl(url)
    if (!safeUrl) return url
    return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer" class="text-blue-400 hover:underline break-all">${url}</a>`
  })
  
  // Restore emojis as Material Symbols icons with colors
  content = content.replace(/__EMOJI_(\d+)__/g, (match, index) => {
    const emojiName = emojis[parseInt(index)]
    const validEmojis = [
      'sentiment_satisfied', 'sentiment_very_satisfied', 'sentiment_dissatisfied',
      'thumb_up', 'thumb_down', 'favorite', 'celebration', 'local_fire_department',
      'waving_hand', 'visibility', 'volunteer_activism', 'star', 'rocket_launch',
      'emoji_objects', 'emoji_events', 'mood', 'face', 'lightbulb', 'check_circle',
      'cancel', 'warning', 'info', 'help', 'heart_broken', 'favorite_border'
    ]
    
    if (validEmojis.includes(emojiName)) {
      // Assign colors based on emoji type
      const emojiColors = {
        'favorite': '#ef4444', // red heart
        'favorite_border': '#ef4444',
        'heart_broken': '#ef4444',
        'thumb_up': '#3b82f6', // blue
        'thumb_down': '#6b7280', // gray
        'sentiment_satisfied': '#fbbf24', // yellow
        'sentiment_very_satisfied': '#fbbf24',
        'sentiment_dissatisfied': '#f59e0b', // orange
        'celebration': '#a855f7', // purple
        'local_fire_department': '#f97316', // orange fire
        'star': '#fbbf24', // yellow star
        'rocket_launch': '#6366f1', // indigo
        'waving_hand': '#fcd34d', // yellow hand
        'volunteer_activism': '#ec4899', // pink
        'check_circle': '#22c55e', // green
        'cancel': '#ef4444', // red
        'warning': '#f59e0b', // orange
        'info': '#3b82f6', // blue
        'help': '#8b5cf6', // purple
        'lightbulb': '#fbbf24', // yellow
        'emoji_objects': '#fbbf24',
        'emoji_events': '#a855f7',
        'mood': '#fbbf24',
        'face': '#fbbf24',
        'visibility': '#6b7280'
      }
      const color = emojiColors[emojiName] || 'currentColor'
      return `<span class="material-symbols-rounded inline-flex align-middle mx-0.5" style="font-size: 1.35em; vertical-align: -0.2em; color: ${color};">${emojiName}</span>`
    }
    return `:${emojiName}:`
  })
  
  // Restore inline code with styling
  content = content.replace(/__INLINE_CODE_(\d+)__/g, (match, index) => {
    const code = escapeHtml(inlineCodes[parseInt(index)])
    return `<code class="px-1.5 py-0.5 rounded bg-surface-200 dark:bg-surface-700 text-sm font-mono text-surface-700 dark:text-surface-200">${code}</code>`
  })
  
  // Restore code blocks with syntax highlighting
  content = content.replace(/__CODE_BLOCK_(\d+)__/g, (match, index) => {
    const { lang, code } = codeBlocks[parseInt(index)]
    const highlightedCode = highlightSyntax(code, lang)
    return `<div class="my-2 rounded-lg overflow-hidden code-block border border-surface-300 dark:border-surface-600">
      <div class="flex items-center justify-between px-3 py-1.5 bg-surface-100 dark:bg-surface-800 text-xs text-surface-500 dark:text-surface-400">
        <span>${lang}</span>
        <button class="copy-btn hover:text-surface-700 dark:hover:text-white transition-colors" title="Copy code">
          <span class="material-symbols-rounded text-sm">content_copy</span>
        </button>
      </div>
      <pre class="p-3 bg-surface-50 dark:bg-surface-900 overflow-x-auto text-sm leading-relaxed"><code class="font-mono text-surface-800 dark:text-surface-200">${highlightedCode}</code></pre>
    </div>`
  })
  
  // Convert newlines to <br>
  content = content.replace(/\n/g, '<br>')
  
  _richContentCache = { key: cacheKey, value: content }
  return content
})

// Syntax highlighting - apply BEFORE escaping
function highlightSyntax(code, lang) {
  if (code.length > 5000) return escapeHtml(code)

  const keywords = {
    javascript: ['const', 'let', 'var', 'function', 'return', 'if', 'else', 'for', 'while', 'class', 'import', 'export', 'from', 'async', 'await', 'try', 'catch', 'throw', 'new', 'this', 'true', 'false', 'null', 'undefined', 'typeof', 'instanceof'],
    typescript: ['const', 'let', 'var', 'function', 'return', 'if', 'else', 'for', 'while', 'class', 'import', 'export', 'from', 'async', 'await', 'try', 'catch', 'throw', 'new', 'this', 'true', 'false', 'null', 'undefined', 'typeof', 'instanceof', 'interface', 'type', 'enum', 'implements', 'extends', 'public', 'private', 'protected', 'readonly'],
    python: ['def', 'class', 'if', 'elif', 'else', 'for', 'while', 'return', 'import', 'from', 'as', 'try', 'except', 'finally', 'with', 'lambda', 'True', 'False', 'None', 'and', 'or', 'not', 'in', 'is', 'pass', 'break', 'continue', 'raise', 'async', 'await'],
    php: ['function', 'class', 'if', 'else', 'elseif', 'for', 'foreach', 'while', 'return', 'use', 'namespace', 'try', 'catch', 'throw', 'new', 'public', 'private', 'protected', 'static', 'const', 'true', 'false', 'null', 'echo', 'print', 'require', 'include', 'extends', 'implements', 'interface', 'trait', 'abstract', 'final'],
    sql: ['SELECT', 'FROM', 'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'ON', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'ORDER', 'BY', 'GROUP', 'HAVING', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'CREATE', 'TABLE', 'ALTER', 'DROP', 'INDEX', 'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES', 'NULL', 'DEFAULT', 'AS', 'DISTINCT', 'LIMIT', 'OFFSET', 'UNION', 'ALL', 'EXISTS', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END'],
    css: ['@import', '@media', '@keyframes', '@font-face', '!important'],
    html: ['DOCTYPE', 'html', 'head', 'body', 'div', 'span', 'p', 'a', 'img', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'form', 'input', 'button', 'script', 'style', 'link', 'meta', 'title'],
    bash: ['if', 'then', 'else', 'fi', 'for', 'do', 'done', 'while', 'case', 'esac', 'function', 'return', 'exit', 'echo', 'read', 'export', 'source', 'alias', 'cd', 'pwd', 'ls', 'mkdir', 'rm', 'cp', 'mv', 'cat', 'grep', 'sed', 'awk', 'chmod', 'chown', 'sudo'],
    json: [],
  }
  
  const langKeywords = keywords[lang?.toLowerCase()] || keywords['javascript']
  
  // Store tokens to avoid overlapping replacements
  const tokens = []
  let result = code
  
  // Match strings first (highest priority)
  result = result.replace(/(["'`])(?:(?!\1)[^\\]|\\.)*\1/g, (match) => {
    const id = tokens.length
    tokens.push(`<span class="hljs-string">${escapeHtml(match)}</span>`)
    return `__TOKEN_${id}__`
  })
  
  // Match comments
  result = result.replace(/(\/\/[^\n]*|\/\*[\s\S]*?\*\/|#[^\n]*)/g, (match) => {
    const id = tokens.length
    tokens.push(`<span class="hljs-comment">${escapeHtml(match)}</span>`)
    return `__TOKEN_${id}__`
  })
  
  // Match numbers
  result = result.replace(/\b(\d+\.?\d*)\b/g, (match) => {
    const id = tokens.length
    tokens.push(`<span class="hljs-number">${escapeHtml(match)}</span>`)
    return `__TOKEN_${id}__`
  })
  
  // Match keywords (case sensitive for most languages, case insensitive for SQL)
  if (langKeywords.length > 0) {
    const flags = lang?.toLowerCase() === 'sql' ? 'gi' : 'g'
    const keywordRegex = new RegExp(`\\b(${langKeywords.join('|')})\\b`, flags)
    result = result.replace(keywordRegex, (match) => {
      const id = tokens.length
      tokens.push(`<span class="hljs-keyword">${escapeHtml(match)}</span>`)
      return `__TOKEN_${id}__`
    })
  }
  
  // Match function calls
  result = result.replace(/\b(\w+)(?=\s*\()/g, (match) => {
    // Skip if already tokenized
    if (match.startsWith('__TOKEN_')) return match
    const id = tokens.length
    tokens.push(`<span class="hljs-function">${escapeHtml(match)}</span>`)
    return `__TOKEN_${id}__`
  })
  
  // Escape remaining content
  result = result.replace(/__TOKEN_(\d+)__|([^_]+|_(?!_TOKEN_))/g, (match, tokenId) => {
    if (tokenId !== undefined) {
      return tokens[parseInt(tokenId)]
    }
    return escapeHtml(match)
  })
  
  return result
}

// Copy code functionality
function copyToClipboard(text) {
  if (navigator.clipboard?.writeText) {
    return navigator.clipboard.writeText(text).catch(() => fallbackCopy(text))
  }
  return fallbackCopy(text)
}

function fallbackCopy(text) {
  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.style.position = 'fixed'
  textarea.style.opacity = '0'
  document.body.appendChild(textarea)
  textarea.select()
  document.execCommand('copy')
  document.body.removeChild(textarea)
}

function handleContentClick(event) {
  const copyBtn = event.target.closest('.copy-btn')
  if (copyBtn) {
    const codeBlock = copyBtn.closest('.code-block')
    const code = codeBlock?.querySelector('code')?.textContent
    if (code) {
      copyToClipboard(code)
      const icon = copyBtn.querySelector('.material-symbols-rounded')
      if (icon) {
        icon.textContent = 'check'
        setTimeout(() => { icon.textContent = 'content_copy' }, 1500)
      }
    }
  }
}
</script>

<template>
  <div 
    :class="[
      'group mb-4 flex transition-colors duration-500',
      isOwn ? 'justify-end' : 'justify-start'
    ]"
    :data-message-id="message.id"
    @contextmenu="handleContextMenu"
  >
    <!-- Other user's avatar -->
    <div v-if="!isOwn" class="relative flex-shrink-0 mr-2 mt-auto">
      <UserAvatar
        :colleague="messageSender"
        :email="messageSender?.email"
        :name="messageSender?.display_name"
        :avatar-path="messageSender?.avatar_path || ''"
        size="md"
        :show-presence="true"
      />
    </div>
    
    <div :class="['max-w-[85%] sm:max-w-[70%] flex flex-col', isOwn ? 'items-end' : 'items-start']">
      <!-- Sender name (for group chats, non-own messages) -->
      <span 
        v-if="props.isGroupChat && !isOwn"
        class="text-xs font-medium text-surface-500 mb-0.5 ml-1"
      >
        {{ message.sender_name || 'Unknown' }}
      </span>
      
      <!-- Reply preview -->
      <div 
        v-if="message.reply_to"
        class="text-xs mb-1 px-3 py-1.5 rounded-lg bg-surface-100 dark:bg-surface-800 text-surface-500 max-w-full truncate"
      >
        <span class="font-medium">{{ message.reply_to.sender_name }}</span>: {{ formatPreviewText(message.reply_to.content) }}
      </div>
      
      <!-- Message row: bubble + hover actions side by side -->
      <div :class="['flex items-center gap-1', isOwn ? 'flex-row-reverse' : 'flex-row']">
      
      <!-- Message bubble -->
      <div 
        ref="messageBubbleRef"
        :class="[
          'relative px-4 py-2.5',
          isOwn 
            ? 'bg-primary-500/10 dark:bg-primary-500/20 text-surface-800 dark:text-surface-100' 
            : 'bg-surface-200 dark:bg-surface-700/50 text-surface-800 dark:text-surface-100',
          message.reply_count > 0
            ? 'border-2 ' + (isOwn ? 'border-primary-500/50' : 'border-primary-400/40 dark:border-primary-500/30')
            : 'border ' + (isOwn ? 'border-primary-500/30' : 'border-surface-300 dark:border-surface-600/50')
        ]"
        :style="{
          borderRadius: isOwn ? '18px 18px 6px 18px' : '18px 18px 18px 6px'
        }"
      >
        <!-- Editing mode -->
        <div v-if="isEditing" class="flex flex-col gap-2 min-w-[300px]">
          <textarea
            ref="editTextareaRef"
            v-model="editContent"
            class="w-full bg-surface-100 dark:bg-surface-800 border border-surface-300 dark:border-surface-600 rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-primary-500 text-surface-900 dark:text-surface-100"
            :rows="Math.max(3, editContent.split('\n').length)"
            :style="{ minHeight: '80px', maxHeight: '300px' }"
            @keydown.enter.exact.prevent="saveEdit"
            @keydown.escape="cancelEdit"
            @input="autoResizeEditTextarea"
          ></textarea>
          <div class="flex gap-2 justify-end">
            <button 
              @click="cancelEdit"
              class="text-xs px-3 py-1.5 rounded-lg bg-surface-200 dark:bg-surface-700 hover:bg-surface-300 dark:hover:bg-surface-600 transition-colors"
            >
              Cancel
            </button>
            <button 
              @click="saveEdit"
              class="text-xs px-3 py-1.5 rounded-lg bg-primary-500 text-white hover:bg-primary-600 transition-colors"
            >
              Save
            </button>
          </div>
        </div>
        
        <!-- Normal view -->
        <template v-else>
          <!-- GIF Message -->
          <div v-if="isGifMessage && gifData" class="gif-message relative">
            <!-- Animated GIF (shown when playing) -->
            <img
              v-if="gifPlaying"
              :key="gifKey"
              :src="gifData.url"
              :style="{ maxWidth: Math.min(gifData.width, 280) + 'px' }"
              class="rounded-lg max-h-[250px] object-contain"
              alt="GIF"
              loading="lazy"
              crossorigin="anonymous"
              @load="handleGifLoad"
            />
            <!-- Static frame (shown when paused) -->
            <img
              v-else
              :src="gifStaticFrame || gifData.url"
              :style="{ maxWidth: Math.min(gifData.width, 280) + 'px' }"
              :class="['rounded-lg max-h-[250px] object-contain', !gifStaticFrame && 'animate-none']"
              alt="GIF (paused)"
            />
            <!-- Paused overlay with replay button -->
            <div 
              v-if="!gifPlaying"
              @click="replayGif"
              class="absolute inset-0 bg-black/30 rounded-lg flex items-center justify-center cursor-pointer hover:bg-black/40 transition-colors"
            >
              <div class="w-11 h-11 rounded-full bg-white/90 flex items-center justify-center shadow-lg hover:scale-105 transition-transform">
                <span class="material-symbols-rounded text-2xl text-surface-700 ml-0.5">play_arrow</span>
              </div>
            </div>
          </div>
          
          <!-- Call Message (missed, completed, cancelled, declined) -->
          <div 
            v-else-if="isCallMessage && callMessageData" 
            :class="[
              'rounded-xl -mx-1 min-w-[200px] max-w-[300px]',
              callMessageData.status === 'missed' || callMessageData.status === 'declined' 
                ? 'bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20' 
                : callMessageData.status === 'completed' 
                  ? 'bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20' 
                  : 'bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700'
            ]"
          >
            <div class="flex items-center gap-3 py-2.5 px-3">
              <div :class="[
                'w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0',
                callMessageData.status === 'missed' || callMessageData.status === 'declined' 
                  ? 'bg-red-100 dark:bg-red-500/20' 
                  : callMessageData.status === 'completed' 
                    ? 'bg-green-100 dark:bg-green-500/20' 
                    : 'bg-surface-200 dark:bg-surface-700'
              ]">
                <span :class="['material-symbols-rounded text-[22px]', callMessageData.color]">
                  {{ callMessageData.icon }}
                </span>
              </div>
              <div class="flex-1 min-w-0">
                <div v-if="callMessageData.labelName" class="text-sm font-bold text-surface-800 dark:text-surface-100 truncate">
                  {{ callMessageData.labelName }}
                </div>
                <div :class="['text-sm font-semibold whitespace-nowrap', callMessageData.color]">
                  {{ callMessageData.labelAction }}
                </div>
              </div>
            </div>
            <!-- Call back button for missed/declined calls -->
            <button 
              v-if="(callMessageData.status === 'missed' || callMessageData.status === 'declined') && !callStore.isInCall"
              @click="callBack(callMessageData.callType)"
              class="flex items-center justify-center gap-1.5 w-full py-2 bg-green-500/10 hover:bg-green-500/20 text-green-600 dark:text-green-400 text-xs font-semibold rounded-b-xl transition-colors border-t border-green-200 dark:border-green-500/20"
            >
              <span class="material-symbols-rounded text-base">call</span>
              Call back
            </button>
          </div>
          
          <!-- Voice Message -->
          <div v-else-if="isVoiceMessage && voiceAttachment" class="-mx-1">
            <VoiceMessageBubble
              :attachment="voiceAttachment"
              :duration="voiceDuration"
              :is-own="isOwn"
              :waveform="voiceWaveform"
              :conversation-id="chatStore.activeConversationId"
            />
          </div>
          
          <!-- Embed Message (shared content: drive, boards, calendar) -->
          <div v-else-if="isEmbedMessage && embedData && embedComponent" class="-mx-1 -my-0.5">
            <component 
              :is="embedComponent"
              :id="embedData.id"
            />
          </div>
          
          <!-- Poll Message -->
          <PollMessage
            v-else-if="isPollMessage"
            :message="message"
            :current-user-id="messageSender?.id"
          />
          
          <!-- Rich content with links, code blocks, etc -->
          <div v-else class="break-words message-content" v-html="richContent" @click="handleContentClick"></div>
          
          <!-- Link Previews (rendered after rich content) -->
          <LinkPreviewCard
            v-for="url in extractedUrls"
            :key="url"
            :url="url"
          />
          
          <!-- YouTube embeds -->
          <div v-if="youtubeLinks.length && !isGifMessage" class="mt-2 space-y-2">
            <div 
              v-for="(yt, i) in youtubeLinks" 
              :key="i"
              class="rounded-lg overflow-hidden"
            >
              <iframe
                :src="`https://www.youtube.com/embed/${yt.videoId}`"
                class="w-full aspect-video max-w-[400px]"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen
              ></iframe>
            </div>
          </div>
          
          <!-- Image Attachments - Grid/Gallery -->
          <div v-if="imageAttachments.length" class="mt-2 max-w-[320px]">
            <div 
              :class="[
                'grid gap-1 rounded-lg overflow-hidden',
                imageAttachments.length === 1 ? 'grid-cols-1' : 
                imageAttachments.length === 2 ? 'grid-cols-2' : 
                imageAttachments.length === 3 ? 'grid-cols-2' : 
                'grid-cols-2'
              ]"
            >
              <div 
                v-for="(img, i) in imageAttachments.slice(0, 4)" 
                :key="img.id || i"
                :class="[
                  'relative cursor-pointer group/img overflow-hidden bg-surface-200 dark:bg-surface-700',
                  imageAttachments.length === 1 ? '' : '',
                  imageAttachments.length === 3 && i === 0 ? 'row-span-2' : ''
                ]"
                @click="openGallery(i)"
              >
                <!-- Show placeholder if image failed to load -->
                <div 
                  v-if="failedImages.has(img.id || i)"
                  :class="[
                    'flex items-center justify-center bg-surface-200 dark:bg-surface-700',
                    imageAttachments.length === 1 ? 'rounded-lg h-[180px]' : 'aspect-square'
                  ]"
                >
                  <span class="material-symbols-rounded text-4xl text-surface-400">image</span>
                </div>
                <img 
                  v-else
                  :src="getThumbnailUrl(img)"
                  :alt="img.original_name || 'Image'"
                  loading="lazy"
                  class="w-full h-full object-cover transition-transform group-hover/img:scale-105"
                  :class="imageAttachments.length === 1 ? 'rounded-lg max-h-[200px]' : 'aspect-square'"
                  @error="handleImageError($event, img.id || i)"
                />
                <!-- Overlay for more images -->
                <div 
                  v-if="i === 3 && imageAttachments.length > 4"
                  class="absolute inset-0 bg-black/60 flex items-center justify-center"
                >
                  <span class="text-white text-xl font-bold">+{{ imageAttachments.length - 4 }}</span>
                </div>
                <!-- Hover overlay -->
                <div class="absolute inset-0 bg-black/0 group-hover/img:bg-black/20 transition-colors flex items-center justify-center opacity-0 group-hover/img:opacity-100">
                  <span class="material-symbols-rounded text-white text-xl drop-shadow-lg">zoom_in</span>
                </div>
              </div>
            </div>
          </div>
          
          <!-- File Attachments (non-images) -->
          <div v-if="fileAttachments.length" class="mt-2 space-y-1">
            <div 
              v-for="(att, i) in fileAttachments" 
              :key="att.id || i"
              :class="[
                'flex items-center gap-3 px-3 py-2 rounded-lg text-sm cursor-pointer transition-colors',
                isOwn 
                  ? 'bg-white/10 hover:bg-white/20' 
                  : 'bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600'
              ]"
              @click="openFilePreview(att)"
            >
              <span class="material-symbols-rounded text-xl flex-shrink-0">
                {{ getFileIcon(att.type) }}
              </span>
              <div class="flex-1 min-w-0">
                <p class="font-medium truncate">{{ att.original_name || att.filename }}</p>
                <p class="text-xs opacity-70">{{ formatFileSize(att.size) }}</p>
              </div>
              <button 
                @click.stop="downloadAttachment(att)"
                class="material-symbols-rounded text-lg opacity-50 hover:opacity-100 p-1 hover:bg-white/10 rounded"
                title="Download"
              >download</button>
            </div>
          </div>
        </template>
        
        <!-- Image Gallery Lightbox -->
        <ChatImageGallery
          v-if="showGallery"
          :images="imageAttachments"
          :initial-index="galleryStartIndex"
          :content-id="`msg_${message.id}_images`"
          @close="showGallery = false"
        />
        
        <!-- File Preview Modal -->
        <CollabPreviewModal
          v-if="showFilePreview && previewAttachment"
          :attachment="previewAttachment"
          :conversation-id="chatStore.activeConversationId"
          @close="showFilePreview = false; previewAttachment = null"
        />
        
      </div>
      
      <!-- Hover actions (side of bubble, FB Messenger style) -->
      <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
        <button 
          ref="reactionBtnRef"
          @click.stop="toggleReactionPicker"
          class="w-7 h-7 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          title="React"
        >
          <span class="material-symbols-rounded text-[18px] text-surface-400">add_reaction</span>
        </button>
        <button 
          @click="handleReply"
          class="w-7 h-7 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          title="Reply"
        >
          <span class="material-symbols-rounded text-[18px] text-surface-400">reply</span>
        </button>
        <button 
          @click="handleMoreClick"
          class="w-7 h-7 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          title="More"
        >
          <span class="material-symbols-rounded text-[18px] text-surface-400">more_horiz</span>
        </button>
      </div>
      
      </div><!-- end message row -->
      
      <!-- Reactions -->
      <div v-if="groupedReactions.length" class="flex flex-wrap gap-1 mt-1">
        <button
          v-for="group in groupedReactions"
          :key="group.emoji"
          @click="addQuickReaction(group.emoji)"
          class="flex items-center gap-1 px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded-full text-xs hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
          :title="group.names.join(', ')"
        >
          <span v-if="isNativeEmoji(group.emoji)" class="text-base leading-none">{{ group.emoji }}</span>
          <span v-else class="material-symbols-rounded text-sm" :style="{ color: getEmojiColor(group.emoji) }">{{ group.emoji }}</span>
          <span class="text-surface-600 dark:text-surface-400">{{ group.count }}</span>
        </button>
      </div>
      
      <!-- Thread reply count -->
      <button
        v-if="message.reply_count > 0"
        @click="handleOpenThread"
        class="flex items-center gap-1.5 mt-1 px-2 py-0.5 text-xs text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-full transition-colors"
      >
        <span class="material-symbols-rounded text-sm">forum</span>
        {{ message.reply_count }} {{ message.reply_count === 1 ? 'reply' : 'replies' }}
      </button>
      
      <!-- Timestamp & edited (only show if showTimestamp is true) -->
      <div v-if="props.showTimestamp || message.is_edited || message.is_pinned" class="flex items-center gap-2 mt-1 px-1">
        <span v-if="props.showTimestamp" class="text-xs text-surface-400">
          {{ formatTime(message.created_at) }}
        </span>
        <span v-if="message.is_edited" class="text-xs text-surface-400 italic">
          (edited)
        </span>
        <span v-if="message.is_pinned" class="text-xs text-primary-500 flex items-center gap-0.5" title="Pinned">
          <span class="material-symbols-rounded text-xs">push_pin</span>
          Pinned
        </span>
      </div>
    </div>
    
    <!-- Reaction picker (teleported) - two stage: quick row, then full picker -->
    <Teleport to="body">
      <div v-if="showReactionPicker" class="fixed inset-0 z-[99998]" @click="showReactionPicker = false; showFullEmojiPicker = false"></div>
      
      <!-- Stage 1: Quick reactions row -->
      <div
        v-if="showReactionPicker && !showFullEmojiPicker"
        class="fixed z-[99999] flex items-center gap-0.5 bg-white dark:bg-surface-800 rounded-full shadow-xl border border-surface-200 dark:border-surface-700 px-2 py-1.5"
        :style="quickPickerStyle"
      >
        <button
          v-for="emoji in quickReactions"
          :key="emoji"
          @click="selectQuickReaction(emoji)"
          class="w-9 h-9 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-all hover:scale-125 text-xl leading-none"
          :title="emoji"
        >{{ emoji }}</button>
        <!-- Expand to full picker -->
        <button
          @click="expandToFullPicker"
          class="w-9 h-9 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors ml-0.5 border-l border-surface-200 dark:border-surface-700 pl-1"
          title="More emojis"
        >
          <span class="material-symbols-rounded text-xl text-surface-400">add</span>
        </button>
      </div>
      
      <!-- Stage 2: Full emoji picker -->
      <div
        v-if="showReactionPicker && showFullEmojiPicker"
        class="fixed z-[99999] emoji-picker-container"
        :style="reactionPickerStyle"
      >
        <EmojiPicker
          :native="true"
          :theme="isDark ? 'dark' : 'light'"
          :display-recent="true"
          :disable-skin-tones="true"
          :hide-group-names="false"
          :hide-search="false"
          @select="onSelectReaction"
        />
      </div>
    </Teleport>
    
    <!-- Context menu -->
    <Teleport to="body">
      <div v-if="showMenu" class="fixed inset-0 z-[99999]" @click="closeMenu"></div>
      <div 
        v-if="showMenu"
        class="fixed z-[99999] w-48 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1"
        :style="{ left: menuPosition.x + 'px', top: menuPosition.y + 'px' }"
      >
        <button
          @click="handleReply"
          class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
        >
          <span class="material-symbols-rounded text-lg">reply</span>
          <span class="text-sm">Reply</span>
        </button>
        
        <!-- Pin/Unpin message -->
        <button
          @click="handleTogglePin"
          class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
        >
          <span class="material-symbols-rounded text-lg">push_pin</span>
          <span class="text-sm">{{ message.is_pinned ? 'Unpin' : 'Pin' }}</span>
        </button>
        
        <!-- Thread -->
        <button
          @click="handleOpenThread"
          class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
        >
          <span class="material-symbols-rounded text-lg">forum</span>
          <span class="text-sm">Thread</span>
        </button>
        
        <!-- Bookmark -->
        <button
          @click="handleToggleBookmark"
          class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
        >
          <span class="material-symbols-rounded text-lg">{{ message.is_bookmarked ? 'bookmark' : 'bookmark_border' }}</span>
          <span class="text-sm">{{ message.is_bookmarked ? 'Remove Bookmark' : 'Bookmark' }}</span>
        </button>
        
        <!-- Save to Drive (if message has attachments) -->
        <button
          v-if="message.attachments?.length"
          @click="handleSaveToDrive"
          :disabled="isSavingToDrive"
          class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
        >
          <span :class="['material-symbols-rounded text-lg', isSavingToDrive && 'animate-spin']">
            {{ isSavingToDrive ? 'progress_activity' : 'cloud_upload' }}
          </span>
          <span class="text-sm">Save to Drive</span>
        </button>
        
        <template v-if="isOwn">
          <button
            @click="startEdit"
            class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
          >
            <span class="material-symbols-rounded text-lg">edit</span>
            <span class="text-sm">Edit</span>
          </button>
          
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          
          <button
            @click="promptDelete"
            class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left text-red-500"
          >
            <span class="material-symbols-rounded text-lg">delete</span>
            <span class="text-sm">Delete</span>
          </button>
        </template>
      </div>
    </Teleport>
    
    <!-- Delete confirmation modal -->
    <ConfirmModal
      :show="showDeleteModal"
      title="Delete Message"
      message="Are you sure you want to delete this message? This action cannot be undone."
      confirm-text="Delete"
      type="danger"
      :loading="isDeleting"
      @confirm="confirmDelete"
      @cancel="showDeleteModal = false"
    />
  </div>
</template>

<style scoped>
/* Rich content styling */
.message-content :deep(a) {
  word-break: break-all;
}

.message-content :deep(pre) {
  margin: 0;
  white-space: pre-wrap;
  word-wrap: break-word;
  word-break: break-word;
  overflow-wrap: break-word;
}

.message-content :deep(code) {
  font-family: 'JetBrains Mono', 'Fira Code', 'SF Mono', Consolas, monospace;
}

/* Code block container */
.message-content :deep(.code-block) {
  margin-left: -0.5rem;
  margin-right: -0.5rem;
}

/* Syntax highlighting - Light theme (VS Code Light+) */
.message-content :deep(.hljs-keyword) {
  color: #0000ff;
  font-weight: 500;
}

.message-content :deep(.hljs-string) {
  color: #a31515;
}

.message-content :deep(.hljs-number) {
  color: #098658;
}

.message-content :deep(.hljs-function) {
  color: #795e26;
}

.message-content :deep(.hljs-comment) {
  color: #008000;
  font-style: italic;
}

/* Syntax highlighting - Dark theme (VS Code Dark+) */
:root.dark .message-content :deep(.hljs-keyword),
.dark .message-content :deep(.hljs-keyword) {
  color: #c586c0;
}

:root.dark .message-content :deep(.hljs-string),
.dark .message-content :deep(.hljs-string) {
  color: #ce9178;
}

:root.dark .message-content :deep(.hljs-number),
.dark .message-content :deep(.hljs-number) {
  color: #b5cea8;
}

:root.dark .message-content :deep(.hljs-function),
.dark .message-content :deep(.hljs-function) {
  color: #dcdcaa;
}

:root.dark .message-content :deep(.hljs-comment),
.dark .message-content :deep(.hljs-comment) {
  color: #6a9955;
}

/* Mobile: slightly larger message text for readability */
@media (max-width: 768px) {
  .message-content {
    font-size: 0.975rem !important;
    line-height: 1.5 !important;
  }
}
</style>
