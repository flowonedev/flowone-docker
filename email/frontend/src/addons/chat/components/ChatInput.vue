<script setup>
import { ref, watch, nextTick, computed, onMounted, onUnmounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'
import api, { uploadChatAttachments } from '@/services/api'
import { describeUploadError } from '@/utils/uploadErrors'
import GifPicker from './GifPicker.vue'
import VoiceRecorder from './VoiceRecorder.vue'
import ChatEmbedPicker from './ChatEmbedPicker.vue'
import MentionAutocomplete from './MentionAutocomplete.vue'
import SlashCommandAutocomplete from './SlashCommandAutocomplete.vue'
import ScheduleMessagePicker from './ScheduleMessagePicker.vue'
import EmojiPicker from 'vue3-emoji-picker'
import 'vue3-emoji-picker/css'

const props = defineProps({
  compact: {
    type: Boolean,
    default: false
  },
  useSafeArea: {
    type: Boolean,
    default: true
  },
  hasFooterNav: {
    type: Boolean,
    default: false
  }
})

const chatStore = useChatStore()
const toast = useToastStore()

const content = ref('')
const textareaRef = ref(null)
const isSending = ref(false)
const showEmojiPicker = ref(false)
const showGifPicker = ref(false)
const showEmbedPicker = ref(false)
const isVoiceRecording = ref(false)

// Mention autocomplete
const showMentionAutocomplete = ref(false)
const mentionQuery = ref('')
const mentionAutocompleteRef = ref(null)

// Slash command autocomplete
const showSlashAutocomplete = ref(false)
const slashQuery = ref('')
const slashAutocompleteRef = ref(null)

// Schedule message
const showSchedulePicker = ref(false)

// File attachments
const fileInput = ref(null)
const pendingFiles = ref([])
const isUploading = ref(false)
// 0-100 upload progress. Web reports real byte progress (axios); the iOS/
// Android native shell can only report 0 then 100, so the UI falls back to an
// indeterminate animation whenever this is still 0 while uploading.
const uploadPct = ref(0)

// Max file size: 50MB
const MAX_FILE_SIZE = 50 * 1024 * 1024

// Detect dark mode for emoji picker
const isDark = computed(() => document.documentElement.classList.contains('dark'))

// Handle paste event for clipboard images (screenshots)
function handlePaste(event) {
  const clipboardData = event.clipboardData || window.clipboardData
  if (!clipboardData) return
  
  const items = clipboardData.items
  if (!items) return
  
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      event.preventDefault()
      const blob = item.getAsFile()
      if (blob) {
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-')
        const ext = item.type.split('/')[1] || 'png'
        const file = new File([blob], `screenshot-${timestamp}.${ext}`, { type: item.type })
        addFiles([file], true)
      }
      break
    }
  }
}

// Handle GIF selection
async function handleGifSelect(gif) {
  showGifPicker.value = false
  
  // Send the GIF URL as a message with special format
  const gifMessage = `[gif:${gif.url}:${gif.width}:${gif.height}]`
  
  isSending.value = true
  const result = await chatStore.sendMessage(gifMessage)
  
  if (!result.success) {
    toast.error(result.error || 'Failed to send GIF')
  }
  
  isSending.value = false
  textareaRef.value?.focus()
}

// Embed picker - share content (drive files, boards, calendar events) in chat
async function handleEmbedSelect(embedContent) {
  showEmbedPicker.value = false
  
  isSending.value = true
  const result = await chatStore.sendMessage(embedContent)
  
  if (!result.success) {
    toast.error(result.error || 'Failed to share content')
  }
  
  isSending.value = false
  textareaRef.value?.focus()
}

// Voice recording
function startVoiceRecording() {
  isVoiceRecording.value = true
}

async function handleVoiceSend({ file, duration, mimeType, waveform }) {
  isVoiceRecording.value = false
  isSending.value = true
  
  try {
    // Upload the voice file as attachment
    const uploadResponse = await uploadChatAttachments(
      `/chat/conversations/${chatStore.activeConversationId}/attachments`,
      [file]
    )
    
    if (!uploadResponse.success) {
      toast.error(uploadResponse.error || uploadResponse.message || 'Failed to upload voice message')
      return
    }
    
    const attachments = uploadResponse.data.attachments
    
    // Encode waveform data in content for playback visualization
    const voiceContent = waveform && waveform.length
      ? `[voice:${duration}:${waveform.map(v => Math.round(v * 100)).join(',')}]`
      : `[voice:${duration}]`
    
    // Send message with voice metadata
    const result = await chatStore.sendMessage(voiceContent, attachments, duration)
    
    if (!result.success) {
      toast.error(result.error || 'Failed to send voice message')
    }
  } catch (e) {
    console.error('[ChatInput] Voice upload failed:', e?.response?.status, e?.response?.data || e?.message || e)
    toast.error(describeUploadError(e))
  } finally {
    isSending.value = false
  }
}

function handleVoiceCancel(errorMessage) {
  isVoiceRecording.value = false
  if (errorMessage && typeof errorMessage === 'string') {
    toast.error(errorMessage)
  }
}

// Messenger-style: collapse left icons when typing, show "+" popup menu
const isInputActive = computed(() => content.value.trim().length > 0 || pendingFiles.value.length > 0)
const showPlusMenu = ref(false)

function togglePlusMenu() {
  showPlusMenu.value = !showPlusMenu.value
}

function closePlusMenu() {
  showPlusMenu.value = false
}

// Close plus menu when input becomes empty (icons return inline)
watch(isInputActive, (active) => {
  if (!active) {
    showPlusMenu.value = false
  }
})

// Plus menu action handlers
function plusMenuVoice() {
  closePlusMenu()
  startVoiceRecording()
}

function plusMenuAttach() {
  closePlusMenu()
  handleAttachmentClick()
}

function plusMenuEmbed() {
  closePlusMenu()
  showEmbedPicker.value = true
  showGifPicker.value = false
  showEmojiPicker.value = false
}

function plusMenuGif() {
  closePlusMenu()
  showGifPicker.value = true
  showEmojiPicker.value = false
  showEmbedPicker.value = false
}

// Track whether input is focused (keyboard is open on mobile)
// Used to remove safe-area bottom padding when keyboard covers the home indicator
const isInputFocused = ref(false)

function handleInputFocus() {
  isInputFocused.value = true
  // Close emoji picker when keyboard opens to prevent overlap
  showEmojiPicker.value = false
}

function handleInputBlur() {
  isInputFocused.value = false
}

onMounted(() => {
  // Add paste listener to document for global paste support
  document.addEventListener('paste', handlePaste)
})

onUnmounted(() => {
  document.removeEventListener('paste', handlePaste)
})

// Auto-resize textarea
function adjustHeight() {
  nextTick(() => {
    if (textareaRef.value) {
      textareaRef.value.style.height = 'auto'
      textareaRef.value.style.height = Math.min(textareaRef.value.scrollHeight, 150) + 'px'
    }
  })
}

watch(content, (val) => {
  adjustHeight()
  
  // Send typing indicator
  if (val) {
    chatStore.sendTypingStatus(true)
  }

  // Detect @mention trigger
  const text = val || ''
  const cursorPos = textareaRef.value?.selectionStart || text.length
  const beforeCursor = text.substring(0, cursorPos)
  
  // Check for @mention pattern: @ followed by query text
  const mentionMatch = beforeCursor.match(/@([A-Za-z0-9 ._-]*)$/)
  if (mentionMatch) {
    showMentionAutocomplete.value = true
    mentionQuery.value = mentionMatch[1]
  } else {
    showMentionAutocomplete.value = false
    mentionQuery.value = ''
  }

  // Check for /slash command at start of message
  if (text.startsWith('/') && cursorPos <= text.length) {
    const slashMatch = text.match(/^\/([a-z]*)/)
    if (slashMatch) {
      showSlashAutocomplete.value = true
      slashQuery.value = slashMatch[1]
    } else {
      showSlashAutocomplete.value = false
    }
  } else {
    showSlashAutocomplete.value = false
  }
})

function handleMentionSelect(mention) {
  // Replace the @query with the selected mention
  const text = content.value
  const cursorPos = textareaRef.value?.selectionStart || text.length
  const beforeCursor = text.substring(0, cursorPos)
  const afterCursor = text.substring(cursorPos)
  
  const mentionMatch = beforeCursor.match(/@([A-Za-z0-9 ._-]*)$/)
  if (mentionMatch) {
    const beforeMention = beforeCursor.substring(0, mentionMatch.index)
    content.value = beforeMention + mention + ' ' + afterCursor
    showMentionAutocomplete.value = false
    
    nextTick(() => {
      const newPos = (beforeMention + mention + ' ').length
      textareaRef.value?.setSelectionRange(newPos, newPos)
      textareaRef.value?.focus()
    })
  }
}

function handleSlashSelect(command) {
  content.value = command + ' '
  showSlashAutocomplete.value = false
  nextTick(() => {
    textareaRef.value?.focus()
  })
}

async function handleScheduleMessage(scheduledAt) {
  showSchedulePicker.value = false
  const text = content.value.trim()
  if (!text) return
  
  isSending.value = true
  const result = await chatStore.scheduleMessage(chatStore.activeConversationId, text, scheduledAt)
  if (result.success) {
    content.value = ''
    toast.success('Message scheduled')
  } else {
    toast.error(result.error || 'Failed to schedule message')
  }
  isSending.value = false
}

// File handling
function handleAttachmentClick() {
  fileInput.value?.click()
}

function handleFileSelect(event) {
  const files = Array.from(event.target.files || [])
  addFiles(files)
  // Reset input so same file can be selected again
  event.target.value = ''
}

function handleDrop(event) {
  event.preventDefault()
  const files = Array.from(event.dataTransfer?.files || [])
  addFiles(files)
}

function handleDragOver(event) {
  event.preventDefault()
}

function addFiles(files, fromClipboard = false) {
  for (const file of files) {
    if (file.size > MAX_FILE_SIZE) {
      toast.error(`${file.name} is too large (max 50MB)`)
      continue
    }
    
    const isImage = file.type.startsWith('image/')
    const preview = isImage ? URL.createObjectURL(file) : null
    
    pendingFiles.value.push({
      id: Math.random().toString(36).substr(2, 9),
      file,
      name: file.name,
      size: file.size,
      type: file.type,
      isImage,
      preview,
      fromClipboard
    })
  }
}

function removeFile(fileId) {
  const index = pendingFiles.value.findIndex(f => f.id === fileId)
  if (index > -1) {
    // Revoke object URL if it exists
    if (pendingFiles.value[index].preview) {
      URL.revokeObjectURL(pendingFiles.value[index].preview)
    }
    pendingFiles.value.splice(index, 1)
  }
}

function formatFileSize(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function getFileIcon(mimeType) {
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('video/')) return 'movie'
  if (mimeType.startsWith('audio/')) return 'audio_file'
  if (mimeType.includes('pdf')) return 'picture_as_pdf'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'description'
  if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'table_chart'
  if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('7z')) return 'folder_zip'
  return 'attach_file'
}

// Execute slash commands locally before sending
async function executeSlashCommand(text) {
  // /shrug - append shrug to message
  if (text.startsWith('/shrug')) {
    const rest = text.replace(/^\/shrug\s*/, '')
    content.value = (rest ? rest + ' ' : '') + String.raw`\_(ツ)_/`
    return false // don't send yet, let user see the modified text
  }

  // /clear - clear local chat history
  if (text === '/clear') {
    const convId = chatStore.activeConversationId
    if (convId && chatStore.messages[convId]) {
      chatStore.messages[convId] = []
    }
    toast.success('Local chat history cleared')
    content.value = ''
    return true // consumed
  }

  // /mute - mute current conversation
  if (text === '/mute') {
    const result = await chatStore.toggleMute(chatStore.activeConversationId)
    if (result.success) {
      toast.success('Conversation muted')
    } else {
      toast.error(result.error || 'Failed to mute')
    }
    content.value = ''
    return true
  }

  // /unmute - unmute current conversation
  if (text === '/unmute') {
    const result = await chatStore.toggleMute(chatStore.activeConversationId)
    if (result.success) {
      toast.success('Conversation unmuted')
    } else {
      toast.error(result.error || 'Failed to unmute')
    }
    content.value = ''
    return true
  }

  // /status [text] - set your custom status text
  if (text.startsWith('/status')) {
    const statusText = text.replace(/^\/status\s*/, '').trim()
    try {
      await api.put('/colleagues/me', { status_text: statusText || null })
      toast.success(statusText ? `Status set: ${statusText}` : 'Status cleared')
    } catch (e) {
      toast.error('Failed to set status')
    }
    content.value = ''
    return true
  }

  // /away - toggle away status
  if (text === '/away') {
    try {
      await api.put('/colleagues/me', { status: 'away' })
      toast.success('Status set to Away')
    } catch (e) {
      toast.error('Failed to set status')
    }
    content.value = ''
    return true
  }

  // /topic [text] - set channel topic (only works in channels)
  if (text.startsWith('/topic')) {
    const topicText = text.replace(/^\/topic\s*/, '').trim()
    const conv = chatStore.activeConversation
    if (conv?.type !== 'channel') {
      toast.error('The /topic command only works in channels')
      return true
    }
    try {
      await api.patch(`/chat/channels/${conv.id}/topic`, { topic: topicText })
      toast.success(topicText ? 'Channel topic updated' : 'Channel topic cleared')
    } catch (e) {
      toast.error('Failed to set topic')
    }
    content.value = ''
    return true
  }

  // /remind [time] [message] - set a reminder
  if (text.startsWith('/remind')) {
    const args = text.replace(/^\/remind\s*/, '').trim()
    if (!args) {
      toast.error('Usage: /remind 30m Check email')
      return true
    }
    // Parse time: 5m, 30m, 1h, 2h, 1d
    const timeMatch = args.match(/^(\d+)(m|h|d)\s+(.+)/)
    if (!timeMatch) {
      toast.error('Usage: /remind 30m Check email (supports m=minutes, h=hours, d=days)')
      return true
    }
    const amount = parseInt(timeMatch[1])
    const unit = timeMatch[2]
    const message = timeMatch[3]
    
    let ms = amount * 60 * 1000
    if (unit === 'h') ms = amount * 60 * 60 * 1000
    if (unit === 'd') ms = amount * 24 * 60 * 60 * 1000

    const unitLabels = { m: 'minute', h: 'hour', d: 'day' }
    const label = `${amount} ${unitLabels[unit]}${amount > 1 ? 's' : ''}`
    
    toast.success(`Reminder set for ${label}: "${message}"`)
    
    // Set a local timeout for the reminder
    setTimeout(() => {
      toast.info(`Reminder: ${message}`)
      // Also try to send a browser notification
      if (Notification.permission === 'granted') {
        new Notification('Reminder', { body: message, icon: '/flowone-logo.png?v=2' })
      }
    }, ms)
    
    content.value = ''
    return true
  }

  // /giphy [term] - handled by GIF picker, just open it
  if (text.startsWith('/giphy')) {
    showGifPicker.value = true
    content.value = ''
    return true
  }

  return false // not a slash command, send normally
}

// Upload files and send message
async function handleSend() {
  const text = content.value.trim()
  const hasFiles = pendingFiles.value.length > 0
  
  if (!text && !hasFiles) return
  if (isSending.value) return

  // Try executing as slash command first (only if no files)
  if (text.startsWith('/') && !hasFiles) {
    const consumed = await executeSlashCommand(text)
    if (consumed) return
  }
  
  // Close all pickers immediately to prevent accidental interactions
  showEmojiPicker.value = false
  showGifPicker.value = false
  showEmbedPicker.value = false
  
  isSending.value = true
  let attachments = null
  
  // Upload files first if any
  const hadClipboardFiles = hasFiles && pendingFiles.value.some(f => f.fromClipboard)
  
  // Wrapped in try/finally so the spinner (isSending/isUploading) ALWAYS clears,
  // even on an unexpected throw — otherwise a failed upload leaves the UI stuck.
  try {
    if (hasFiles) {
      isUploading.value = true
      uploadPct.value = 0
      try {
        const response = await uploadChatAttachments(
          `/chat/conversations/${chatStore.activeConversationId}/attachments`,
          pendingFiles.value.map(f => f.file),
          (pct) => { uploadPct.value = pct }
        )
        
        if (!response.success) {
          toast.error(response.error || response.message || 'Failed to upload files')
          return
        }
        attachments = response.data.attachments
      } catch (e) {
        console.error('[ChatInput] File upload failed:', e?.response?.status, e?.response?.data || e?.message || e)
        toast.error(describeUploadError(e))
        return
      } finally {
        isUploading.value = false
        uploadPct.value = 0
      }
    }
    
    // Send message (with or without attachments)
    const convId = chatStore.activeConversationId
    const result = await chatStore.sendMessage(text || '', attachments)
    
    if (result.success) {
      content.value = ''
      pendingFiles.value.forEach(f => {
        if (f.preview) URL.revokeObjectURL(f.preview)
      })
      pendingFiles.value = []
      adjustHeight()
      
      if (hadClipboardFiles && attachments?.length) {
        const attIds = attachments.map(a => a.id)
        chatStore.saveAttachmentsToDrive(convId, attIds).catch(() => {})
      }
    } else {
      toast.error(result.error || 'Failed to send message')
    }
  } finally {
    isSending.value = false
    isUploading.value = false
    uploadPct.value = 0
  }
  
  // Focus back on input
  nextTick(() => {
    textareaRef.value?.focus()
  })
}

function handleKeydown(e) {
  // Forward keyboard events to mention autocomplete when open
  if (showMentionAutocomplete.value && mentionAutocompleteRef.value) {
    if (['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(e.key)) {
      mentionAutocompleteRef.value.handleKeydown(e)
      return
    }
  }
  
  // Forward keyboard events to slash autocomplete when open
  if (showSlashAutocomplete.value && slashAutocompleteRef.value) {
    if (['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(e.key)) {
      slashAutocompleteRef.value.handleKeydown(e)
      return
    }
  }
  
  // Send on Enter (without Shift)
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    handleSend()
  }
}

function toggleEmojiPicker() {
  const opening = !showEmojiPicker.value
  if (opening && window.innerWidth < 640) {
    // On mobile: blur input first to dismiss keyboard, then show picker
    // This prevents the picker from overlapping with the keyboard
    textareaRef.value?.blur()
    // Small delay to let keyboard close and viewport settle before positioning
    setTimeout(() => {
      showEmojiPicker.value = true
    }, 150)
    return
  }
  showEmojiPicker.value = !showEmojiPicker.value
}

function onSelectEmoji(emoji) {
  // Insert the native emoji character
  content.value += emoji.i
  // On mobile, close picker after selecting one emoji to avoid overlap with keyboard
  if (window.innerWidth < 640) {
    showEmojiPicker.value = false
  }
  // On desktop, keep picker open for multiple emoji selection
}

// Emoji picker position (computed from emoji button location)
// Uses visualViewport.height on mobile to avoid overlap with the software keyboard
const emojiButtonRef = ref(null)
const emojiPickerStyle = computed(() => {
  if (!emojiButtonRef.value) {
    return { bottom: '80px', right: '16px' }
  }
  const rect = emojiButtonRef.value.getBoundingClientRect()
  const isMobileView = window.innerWidth < 640
  // On mobile PWA, window.innerHeight includes the keyboard area.
  // visualViewport.height is the ACTUAL visible area above the keyboard.
  const vh = window.visualViewport?.height || window.innerHeight
  if (isMobileView) {
    return {
      bottom: (vh - rect.top + 8) + 'px',
      left: '0px',
      right: '0px'
    }
  }
  return {
    bottom: (window.innerHeight - rect.top + 8) + 'px',
    right: (window.innerWidth - rect.right) + 'px'
  }
})

// Computed
const canSend = computed(() => {
  return (content.value.trim() || pendingFiles.value.length > 0) && !isSending.value
})

const imageCount = computed(() => pendingFiles.value.filter(f => f.isImage).length)
const fileCount = computed(() => pendingFiles.value.filter(f => !f.isImage).length)
</script>

<template>
  <div 
    :class="[
      'chat-input-bar px-3 py-1 bg-white dark:bg-[rgb(var(--color-surface))] border-t border-surface-200 dark:border-[rgb(var(--color-border))] relative z-10',
      props.useSafeArea && !props.hasFooterNav && !isInputFocused.value && 'input-safe-area',
      props.hasFooterNav && !isInputFocused.value && 'input-with-footer'
    ]"
    @drop="handleDrop"
    @dragover="handleDragOver"
  >
    <!-- Pending attachments preview -->
    <div v-if="pendingFiles.length" class="mb-3">
      <div class="flex flex-wrap gap-2">
        <!-- Image previews -->
        <div 
          v-for="file in pendingFiles.filter(f => f.isImage)" 
          :key="file.id"
          class="relative group"
        >
          <img 
            :src="file.preview" 
            :alt="file.name"
            class="h-20 w-20 object-cover rounded-lg border border-surface-200 dark:border-surface-700"
          />
          <!-- Uploading overlay: covers each image so it's obvious the send is in progress -->
          <div
            v-if="isUploading"
            class="absolute inset-0 rounded-lg bg-black/45 flex items-center justify-center"
          >
            <span class="material-symbols-rounded text-white text-xl leading-none animate-spin">progress_activity</span>
          </div>
          <button
            v-if="!isUploading"
            @click="removeFile(file.id)"
            class="absolute -top-1.5 -right-1.5 w-4 h-4 bg-surface-800/80 hover:bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all"
          >
            <span class="material-symbols-rounded" style="font-size: 10px; line-height: 1;">close</span>
          </button>
        </div>
        
        <!-- Non-image file previews -->
        <div 
          v-for="file in pendingFiles.filter(f => !f.isImage)" 
          :key="file.id"
          class="relative group flex items-center gap-2 px-3 py-2 bg-surface-100 dark:bg-surface-800 rounded-lg"
          :class="isUploading && 'opacity-70'"
        >
          <span v-if="isUploading" class="material-symbols-rounded text-primary-500 animate-spin">progress_activity</span>
          <span v-else class="material-symbols-rounded text-surface-500">{{ getFileIcon(file.type) }}</span>
          <div class="min-w-0">
            <p class="text-sm font-medium truncate max-w-[150px]">{{ file.name }}</p>
            <p class="text-xs text-surface-400">{{ formatFileSize(file.size) }}</p>
          </div>
          <button
            v-if="!isUploading"
            @click="removeFile(file.id)"
            class="ml-2 p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded transition-colors"
          >
            <span class="material-symbols-rounded text-sm text-surface-400">close</span>
          </button>
        </div>
      </div>

      <!-- Upload progress: count + bar (determinate on web, indeterminate on native) -->
      <div v-if="isUploading" class="mt-2">
        <div class="flex items-center gap-1.5 mb-1">
          <span class="material-symbols-rounded text-primary-500 text-base leading-none animate-spin">progress_activity</span>
          <span class="text-xs text-surface-500 dark:text-surface-400">
            Uploading {{ pendingFiles.length }} {{ pendingFiles.length === 1 ? 'file' : 'files' }}…
            <span v-if="uploadPct > 0">{{ uploadPct }}%</span>
          </span>
        </div>
        <div class="h-1 rounded-full bg-surface-200 dark:bg-surface-700 overflow-hidden">
          <div
            class="h-full bg-primary-500 rounded-full"
            :class="uploadPct > 0 ? 'transition-all duration-200' : 'upload-bar-indeterminate'"
            :style="uploadPct > 0 ? { width: uploadPct + '%' } : null"
          ></div>
        </div>
      </div>
    </div>
    
    <!-- Voice recorder (replaces normal input when recording) -->
    <div v-if="isVoiceRecording" class="flex items-center gap-2 py-1">
      <VoiceRecorder
        @send="handleVoiceSend"
        @cancel="handleVoiceCancel"
      />
    </div>
    
    <!-- Normal input area -->
    <div v-else class="flex items-end gap-1.5">
      <!-- Left action buttons (Messenger-style: collapse when typing) -->
      <div class="flex items-end flex-shrink-0 relative mb-0.5">
        <!-- COLLAPSED STATE: "+" button with popup menu -->
        <Transition name="plus-btn">
          <div v-if="isInputActive" class="relative">
            <button
              @click="togglePlusMenu"
              class="w-10 h-10 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-all flex-shrink-0"
              :class="showPlusMenu ? 'bg-surface-100 dark:bg-surface-700' : ''"
              title="Show actions"
            >
              <span 
                class="material-symbols-rounded text-primary-500 text-2xl leading-none transition-transform duration-200"
                :class="showPlusMenu ? 'rotate-45' : ''"
              >add_circle</span>
            </button>
            
            <!-- Plus menu popup (appears above the button) -->
            <Transition name="plus-menu">
              <div 
                v-if="showPlusMenu"
                class="absolute bottom-full left-0 mb-2 w-56 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1.5 z-50"
              >
                <button
                  @click="plusMenuVoice"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors text-left"
                >
                  <span class="material-symbols-rounded text-xl text-primary-500">mic</span>
                  <span class="text-sm text-surface-700 dark:text-surface-300">Send a voice clip</span>
                </button>
                <button
                  @click="plusMenuAttach"
                  :disabled="isUploading"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors text-left"
                >
                  <span class="material-symbols-rounded text-xl text-primary-500">attach_file</span>
                  <span class="text-sm text-surface-700 dark:text-surface-300">Attach a file</span>
                </button>
                <button
                  @click="plusMenuEmbed"
                  :disabled="isUploading || isSending"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors text-left"
                >
                  <span class="material-symbols-rounded text-xl text-primary-500">share</span>
                  <span class="text-sm text-surface-700 dark:text-surface-300">Share content</span>
                </button>
                <button
                  @click="plusMenuGif"
                  :disabled="isUploading || isSending"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors text-left"
                >
                  <span class="material-symbols-rounded text-xl text-primary-500">gif_box</span>
                  <span class="text-sm text-surface-700 dark:text-surface-300">Choose a GIF</span>
                </button>
              </div>
            </Transition>
            
            <!-- Backdrop to close plus menu -->
            <div v-if="showPlusMenu" class="fixed inset-0 z-40" @click="closePlusMenu"></div>
          </div>
        </Transition>
        
        <!-- EXPANDED STATE: Inline action icons (when not typing) -->
        <Transition name="left-actions">
          <div v-if="!isInputActive" class="flex items-center gap-0.5 overflow-hidden">
            <!-- Embed picker button (share drive, boards, calendar) -->
            <button
              @click="showEmbedPicker = !showEmbedPicker; showGifPicker = false; showEmojiPicker = false"
              :disabled="isUploading || isSending"
              class="w-10 h-10 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors flex-shrink-0"
              title="Share content"
            >
              <span class="material-symbols-rounded text-surface-500 text-2xl leading-none">add_circle</span>
            </button>
            
            <!-- Attachment button -->
            <button
              @click="handleAttachmentClick"
              :disabled="isUploading"
              class="w-10 h-10 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors flex-shrink-0"
              title="Attach file"
            >
              <span class="material-symbols-rounded text-surface-500 text-2xl leading-none">attach_file</span>
            </button>
            
            <!-- GIF button -->
            <button
              @click="showGifPicker = !showGifPicker"
              :disabled="isUploading || isSending"
              class="w-10 h-10 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors flex-shrink-0"
              title="Send GIF"
            >
              <span class="material-symbols-rounded text-surface-500 text-2xl leading-none">gif_box</span>
            </button>
          </div>
        </Transition>
        
        <!-- Pickers rendered OUTSIDE overflow-hidden so they don't get clipped -->
        <ChatEmbedPicker
          v-if="showEmbedPicker"
          @select="handleEmbedSelect"
          @close="showEmbedPicker = false"
        />
        
        <GifPicker
          v-if="showGifPicker"
          @select="handleGifSelect"
          @close="showGifPicker = false"
        />
      </div>
      
      <!-- Input container -->
      <div class="flex-1 relative min-w-0">
        <!-- Mention autocomplete dropdown -->
        <MentionAutocomplete
          v-if="showMentionAutocomplete"
          ref="mentionAutocompleteRef"
          :query="mentionQuery"
          :conversation-id="chatStore.activeConversationId"
          @select="handleMentionSelect"
          @close="showMentionAutocomplete = false"
        />
        
        <!-- Slash command autocomplete dropdown -->
        <SlashCommandAutocomplete
          v-if="showSlashAutocomplete"
          ref="slashAutocompleteRef"
          :query="slashQuery"
          @select="handleSlashSelect"
          @close="showSlashAutocomplete = false"
        />
        
        <textarea
          ref="textareaRef"
          v-model="content"
          @keydown="handleKeydown"
          @focus="handleInputFocus"
          @blur="handleInputBlur"
          :placeholder="pendingFiles.length ? 'Add a message (optional)...' : 'Type a message...'"
          rows="1"
          class="w-full px-4 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 resize-none text-sm sm:text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none pr-11 text-surface-900 dark:text-surface-100 overflow-hidden rounded-[20px] chat-textarea"
          style="line-height: 1.5; vertical-align: middle;"
          :disabled="isSending"
        ></textarea>
        
        <!-- Emoji button (inside input, pinned to bottom-right) -->
        <div class="absolute right-1.5 bottom-0.5">
          <button
            ref="emojiButtonRef"
            @click="toggleEmojiPicker"
            class="w-9 h-9 flex items-center justify-center hover:bg-surface-200 dark:hover:bg-surface-700 rounded-full transition-colors"
            title="Add emoji"
          >
            <span class="material-symbols-rounded text-surface-400 text-2xl leading-none">sentiment_satisfied</span>
          </button>
          
          <!-- Emoji picker dropdown - teleported to body for proper z-index stacking -->
          <Teleport to="body">
            <div v-if="showEmojiPicker" class="fixed inset-0 z-[9998]" @click="showEmojiPicker = false"></div>
            <div 
              v-if="showEmojiPicker"
              class="fixed z-[9999] emoji-picker-container"
              :style="emojiPickerStyle"
            >
              <EmojiPicker
                :native="true"
                :theme="isDark ? 'dark' : 'light'"
                :display-recent="true"
                :disable-skin-tones="true"
                :hide-group-names="false"
                :hide-search="false"
                @select="onSelectEmoji"
              />
            </div>
          </Teleport>
        </div>
      </div>
      
      <!-- Send / Mic / Schedule buttons -->
      <div class="flex-shrink-0 flex items-end gap-0.5 mb-0.5">
        <!-- Schedule message button (only when there's content) -->
        <div v-if="canSend" class="relative">
          <button
            @click="showSchedulePicker = !showSchedulePicker"
            class="w-8 h-8 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
            title="Schedule message"
          >
            <span class="material-symbols-rounded text-xl leading-none text-surface-400">schedule_send</span>
          </button>
          <ScheduleMessagePicker
            v-if="showSchedulePicker"
            @schedule="handleScheduleMessage"
            @close="showSchedulePicker = false"
          />
        </div>
        
        <button
          v-if="canSend"
          @click="handleSend"
          class="w-10 h-10 rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center justify-center"
          :title="pendingFiles.length ? `Send ${pendingFiles.length} file(s)` : 'Send message (Enter)'"
        >
          <span v-if="isSending || isUploading" class="material-symbols-rounded text-2xl leading-none animate-spin">progress_activity</span>
          <span v-else class="material-symbols-rounded text-2xl leading-none">send</span>
        </button>
        <button
          v-else
          @click="startVoiceRecording"
          class="w-10 h-10 rounded-full bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors flex items-center justify-center"
          title="Record voice message"
        >
          <span class="material-symbols-rounded text-2xl leading-none text-surface-500">mic</span>
        </button>
      </div>
    </div>
    
    <!-- Hidden file input -->
    <input 
      ref="fileInput"
      type="file" 
      class="hidden" 
      accept="*/*"
      multiple
      @change="handleFileSelect"
    />
  </div>
</template>

<style scoped>
/* Indeterminate upload bar: slides a short segment across the track when the
   transport can't report byte-level progress (the iOS/Android native shell,
   which only reports 0 then 100). */
.upload-bar-indeterminate {
  width: 40%;
  animation: upload-indeterminate 1.1s ease-in-out infinite;
}
@keyframes upload-indeterminate {
  0% { margin-left: -40%; }
  100% { margin-left: 100%; }
}

/* Safe area for mobile home indicator - compact padding */
.input-safe-area {
  padding-top: 10px;
  padding-bottom: 10px;
}

/* When there's a mobile footer nav below the input */
.input-with-footer {
  padding-bottom: calc(3.75rem + env(safe-area-inset-bottom, 0px));
}

/* ---- Messenger-style left actions collapse/expand ---- */

/* "+" button fade in/out */
.plus-btn-enter-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.plus-btn-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.plus-btn-enter-from {
  opacity: 0;
  transform: scale(0.6);
}
.plus-btn-leave-to {
  opacity: 0;
  transform: scale(0.6);
}

/* Plus menu popup animation */
.plus-menu-enter-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.plus-menu-leave-active {
  transition: opacity 0.1s ease, transform 0.1s ease;
}
.plus-menu-enter-from {
  opacity: 0;
  transform: translateY(8px) scale(0.95);
}
.plus-menu-leave-to {
  opacity: 0;
  transform: translateY(8px) scale(0.95);
}

/* Left action icons slide/collapse */
.left-actions-enter-active {
  transition: max-width 0.25s ease, opacity 0.2s ease 0.05s;
}
.left-actions-leave-active {
  transition: max-width 0.2s ease 0.02s, opacity 0.15s ease;
}
.left-actions-enter-from {
  max-width: 0;
  opacity: 0;
}
.left-actions-enter-to {
  max-width: 200px;
  opacity: 1;
}
.left-actions-leave-from {
  max-width: 200px;
  opacity: 1;
}
.left-actions-leave-to {
  max-width: 0;
  opacity: 0;
}

/* Desktop: 26px icons */
@media (min-width: 769px) {
  .material-symbols-rounded {
    font-size: 26px !important;
    line-height: 1 !important;
  }
}

/* Mobile: bigger icons, tap targets, and text in chat input */
@media (max-width: 768px) {
  .material-symbols-rounded {
    font-size: 30px !important;
    line-height: 1 !important;
  }

  button {
    min-width: 2.5rem;
    min-height: 2.5rem;
  }

  /* Bigger input text on mobile (16px prevents iOS auto-zoom) */
  .chat-textarea {
    font-size: 1rem !important;
    line-height: 1.5 !important;
  }
}
</style>

<style>
/* ====================================================
   Emoji Picker Overrides (vue3-emoji-picker)
   Uses the library's own CSS custom properties.
   NOTE: No :deep() here - this is a global <style> block
   ==================================================== */

/* Hide emoji picker footer (preview text / skin tone) */
.v3-emoji-picker .v3-footer {
  display: none !important;
}

/* -- Light theme overrides -- */
.emoji-picker-container .v3-emoji-picker {
  --v3-picker-bg: #ffffff;
  --v3-picker-fg: #1f2937;
  --v3-picker-border: #e5e7eb;
  --v3-picker-input-bg: #f9fafb;
  --v3-picker-input-border: #d1d5db;
  --v3-picker-emoji-hover: #f3f4f6;
  width: 350px !important;
  max-width: 90vw !important;
  height: 380px !important;
  box-shadow: 0 10px 40px rgba(0,0,0,0.15) !important;
  border-radius: 16px !important;
}

/* -- Dark theme overrides -- uses the app's actual CSS variables
   --color-surface: 28 28 34       (dark charcoal, NOT slate/blue)
   --color-surface-elevated: 38 38 46
   --color-surface-hover: 48 48 58
   --color-border: 50 50 62
   --color-border-strong: 70 70 85  */
.dark .emoji-picker-container .v3-emoji-picker,
.emoji-picker-container .v3-emoji-picker.v3-color-theme-dark {
  --v3-picker-bg: rgb(var(--color-surface, 28 28 34));
  --v3-picker-fg: rgb(var(--color-text, 228 228 231));
  --v3-picker-border: rgb(var(--color-border, 50 50 62));
  --v3-picker-input-bg: rgb(var(--color-surface-elevated, 38 38 46));
  --v3-picker-input-border: rgb(var(--color-border-strong, 70 70 85));
  --v3-picker-input-focus-border: rgb(var(--color-border-strong, 70 70 85));
  --v3-picker-emoji-hover: rgb(var(--color-surface-hover, 48 48 58));
  --v3-group-image-filter: invert(1);
  box-shadow: 0 10px 40px rgba(0,0,0,0.4) !important;
}

/* Group name headers - match theme bg for sticky scroll */
.dark .emoji-picker-container .v3-emoji-picker .v3-body .v3-body-inner .v3-group h5 {
  background: rgb(var(--color-surface, 28 28 34)) !important;
  color: rgb(var(--color-text, 228 228 231)) !important;
}

/* Search input in dark mode */
.dark .emoji-picker-container .v3-emoji-picker .v3-search input {
  background: rgb(var(--color-surface-elevated, 38 38 46)) !important;
  border-color: rgb(var(--color-border-strong, 70 70 85)) !important;
  color: rgb(var(--color-text, 228 228 231)) !important;
}

/* Bigger emoji buttons */
.emoji-picker-container .v3-emoji-picker .v3-body .v3-body-inner .v3-group .v3-emojis button {
  font-size: 26px !important;
  flex-basis: 14.28% !important;
  max-width: 14.28% !important;
}

/* Mobile: full width picker with even larger emojis */
@media (max-width: 640px) {
  .emoji-picker-container .v3-emoji-picker {
    width: 100vw !important;
    max-width: 100vw !important;
    height: 300px !important;
    border-radius: 16px 16px 0 0 !important;
  }

  .emoji-picker-container .v3-emoji-picker .v3-body .v3-body-inner .v3-group .v3-emojis button {
    font-size: 30px !important;
    flex-basis: 14.28% !important;
    max-width: 14.28% !important;
  }
}
</style>
