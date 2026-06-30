<script setup>
/**
 * CallChatPanel - In-call chat sidebar for VideoCallRoom.
 *
 * Renders the message list and input. Supports:
 *  - text messages (linkified)
 *  - server-stored attachments (attach button, clipboard paste, drag & drop)
 *    uploaded to the guest-call attachment endpoint, then broadcast by id so
 *    every participant downloads with their OWN token
 *  - legacy base64 image messages (fallback when no upload endpoint is set)
 *
 * The parent owns the message list and the LiveKit data channel; this panel
 * only emits intents (send / send-attachment / send-image).
 */
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'

const props = defineProps({
  /** Chat messages: { sender, identity, message, ts, isLocal, isImage, isFile, attachmentId, name, mime, size } */
  messages: { type: Array, default: () => [] },
  /** Upload/download base, e.g. https://flowone.pro/api/guest/call/TOKEN/attachments. Empty disables uploads. */
  attachmentsBaseUrl: { type: String, default: '' },
  /** Local display name, sent as uploaded_by with uploads. */
  senderName: { type: String, default: '' },
})

const emit = defineEmits(['close', 'send', 'send-attachment', 'send-image', 'notify'])

const MAX_FILE_BYTES = 25 * 1024 * 1024

const chatInput = ref('')
const showEmojiPicker = ref(false)
const chatScrollEl = ref(null)
const fileInputEl = ref(null)
const uploadingCount = ref(0)
const isDragging = ref(false)
let dragDepth = 0

const commonEmojis = [
  '😀','😂','😍','🥰','😎','🤔','👍','👎','👋','🙏',
  '🎉','🔥','❤️','💯','✅','❌','⭐','💡','📎','📸',
  '😊','😢','😡','🤝','👏','💪','🚀','💬','📞','🎯',
]

onMounted(() => {
  nextTick(scrollToBottom)
  window.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
})
watch(() => props.messages.length, () => nextTick(scrollToBottom))

function scrollToBottom() {
  if (chatScrollEl.value) chatScrollEl.value.scrollTop = chatScrollEl.value.scrollHeight
}

// ------ Image lightbox ------

/** { src, name, downloadUrl } of the image being previewed, or null */
const previewImage = ref(null)

function openPreview(src, name = '', downloadUrl = '') {
  if (!src) return
  previewImage.value = { src, name, downloadUrl: downloadUrl || src }
}

function closePreview() {
  previewImage.value = null
}

function onKeydown(e) {
  if (e.key === 'Escape' && previewImage.value) {
    e.stopPropagation()
    closePreview()
  }
}

function sendMessage() {
  const text = chatInput.value.trim()
  if (!text) return
  showEmojiPicker.value = false
  emit('send', text)
  chatInput.value = ''
}

function insertEmoji(emoji) {
  chatInput.value += emoji
}

// ------ Attachments ------

function openFilePicker() {
  fileInputEl.value?.click()
}

function onFileInputChange(event) {
  const files = Array.from(event.target.files || [])
  event.target.value = ''
  files.forEach(uploadAndSend)
}

function handlePaste(event) {
  const items = event.clipboardData?.items
  if (!items) return
  const files = []
  for (const item of items) {
    if (item.kind === 'file') {
      const file = item.getAsFile()
      if (file) files.push(file)
    }
  }
  if (files.length === 0) return
  event.preventDefault()
  files.forEach(uploadAndSend)
}

function onDragEnter(event) {
  if (!event.dataTransfer?.types?.includes?.('Files')) return
  dragDepth++
  isDragging.value = true
}

function onDragLeave() {
  dragDepth = Math.max(0, dragDepth - 1)
  if (dragDepth === 0) isDragging.value = false
}

function onDrop(event) {
  dragDepth = 0
  isDragging.value = false
  const files = Array.from(event.dataTransfer?.files || [])
  files.forEach(uploadAndSend)
}

function sendAsBase64(file) {
  const reader = new FileReader()
  reader.onload = (e) => {
    if (e.target?.result) emit('send-image', e.target.result)
  }
  reader.readAsDataURL(file)
}

async function uploadAndSend(file) {
  if (!props.attachmentsBaseUrl) {
    // No upload endpoint (e.g. portal rooms): small images still work as base64
    if (file.type?.startsWith('image/')) sendAsBase64(file)
    else emit('notify', 'File attachments are not available in this call')
    return
  }
  if (file.size > MAX_FILE_BYTES) {
    emit('notify', `"${file.name || 'File'}" is too large (max 25MB)`)
    return
  }
  uploadingCount.value++
  try {
    const fd = new FormData()
    fd.append('file', file, file.name || `pasted-${Date.now()}.png`)
    if (props.senderName) fd.append('uploaded_by', props.senderName)
    const res = await fetch(props.attachmentsBaseUrl, { method: 'POST', body: fd })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || !data.success) {
      emit('notify', data.error || 'Upload failed')
      return
    }
    // { id, name, mime, size, is_image }
    emit('send-attachment', data.data)
  } catch {
    emit('notify', 'Upload failed')
  } finally {
    uploadingCount.value--
  }
}

function attachmentUrl(id) {
  return props.attachmentsBaseUrl ? `${props.attachmentsBaseUrl}/${id}` : null
}

// ------ Formatting helpers ------

function formatChatTime(ts) {
  const d = new Date(ts)
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function linkify(text) {
  return String(text || '').replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener" class="text-blue-400 underline hover:text-blue-300 break-all">$1</a>')
}

function formatFileSize(bytes) {
  const b = Number(bytes) || 0
  if (b < 1024) return `${b} B`
  if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`
  return `${(b / (1024 * 1024)).toFixed(1)} MB`
}

function fileIcon(mime) {
  const m = String(mime || '')
  if (m.includes('pdf')) return 'picture_as_pdf'
  if (m.startsWith('audio/')) return 'audio_file'
  if (m.startsWith('video/')) return 'video_file'
  if (m.includes('zip') || m.includes('compressed') || m.includes('tar')) return 'folder_zip'
  if (m.startsWith('image/')) return 'image'
  return 'description'
}
</script>

<template>
  <div
    class="w-80 bg-surface-800/90 backdrop-blur-sm border-l border-surface-700/50 flex flex-col flex-shrink-0 relative"
    @dragenter.prevent="onDragEnter"
    @dragover.prevent
    @dragleave.prevent="onDragLeave"
    @drop.prevent="onDrop"
  >
    <div class="px-4 py-3 border-b border-surface-700/40 flex items-center justify-between">
      <h3 class="text-sm font-semibold text-white flex items-center gap-2">
        <span class="material-symbols-rounded text-base">chat</span>
        Chat
      </h3>
      <button @click="emit('close')" class="text-surface-400 hover:text-white transition-colors">
        <span class="material-symbols-rounded text-lg">close</span>
      </button>
    </div>

    <!-- Drag & drop overlay -->
    <div
      v-if="isDragging"
      class="absolute inset-0 z-20 bg-primary-500/15 border-2 border-dashed border-primary-400 rounded-none
             flex items-center justify-center pointer-events-none"
    >
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl text-primary-300">upload_file</span>
        <p class="text-primary-200 text-sm mt-1 font-medium">Drop to share</p>
      </div>
    </div>

    <div ref="chatScrollEl" class="flex-1 overflow-y-auto p-3 space-y-3" @click="showEmojiPicker = false">
      <div v-if="messages.length === 0" class="text-center text-surface-500 text-xs py-8">
        <span class="material-symbols-rounded text-3xl block mb-2">forum</span>
        No messages yet
      </div>
      <div
        v-for="(msg, i) in messages" :key="i"
        class="flex flex-col"
        :class="msg.isLocal ? 'items-end' : 'items-start'"
      >
        <div class="flex items-center gap-1.5 mb-0.5 px-1">
          <span class="text-[10px] font-medium" :class="msg.isLocal ? 'text-primary-400' : 'text-surface-400'">
            {{ msg.isLocal ? 'You' : msg.sender }}
          </span>
          <span class="text-[9px] text-surface-600">{{ formatChatTime(msg.ts) }}</span>
        </div>

        <!-- Server-stored image attachment -->
        <button
          v-if="msg.isFile && msg.isImage && attachmentUrl(msg.attachmentId)"
          type="button"
          @click="openPreview(attachmentUrl(msg.attachmentId), msg.name)"
          class="rounded-2xl overflow-hidden max-w-[85%] block cursor-zoom-in focus:outline-none focus:ring-2 focus:ring-primary-400"
          :class="msg.isLocal ? 'rounded-tr-sm' : 'rounded-tl-sm'"
          :title="msg.name || 'Shared image'"
        >
          <img :src="attachmentUrl(msg.attachmentId)" class="max-w-full max-h-64 rounded-xl" :alt="msg.name || 'Shared image'" loading="lazy" />
        </button>

        <!-- Server-stored file attachment -->
        <component
          v-else-if="msg.isFile"
          :is="attachmentUrl(msg.attachmentId) ? 'a' : 'div'"
          :href="attachmentUrl(msg.attachmentId) || undefined"
          target="_blank" rel="noopener" download
          class="flex items-center gap-2.5 px-3 py-2.5 rounded-2xl max-w-[85%] bg-surface-700/60 hover:bg-surface-700 transition-colors"
          :class="msg.isLocal ? 'rounded-tr-sm' : 'rounded-tl-sm'"
        >
          <span class="w-9 h-9 rounded-lg bg-primary-500/15 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-primary-300 text-xl">{{ fileIcon(msg.mime) }}</span>
          </span>
          <span class="min-w-0">
            <span class="block text-white/90 text-xs font-medium truncate max-w-[170px]">{{ msg.name || 'Attachment' }}</span>
            <span class="block text-surface-400 text-[10px]">{{ formatFileSize(msg.size) }}</span>
          </span>
          <span v-if="attachmentUrl(msg.attachmentId)" class="material-symbols-rounded text-surface-400 text-base ml-auto flex-shrink-0">download</span>
        </component>

        <!-- Legacy base64 image -->
        <button
          v-else-if="msg.isImage"
          type="button"
          @click="openPreview(msg.message, 'Shared image')"
          class="rounded-2xl overflow-hidden max-w-[85%] block cursor-zoom-in focus:outline-none focus:ring-2 focus:ring-primary-400"
          :class="msg.isLocal ? 'rounded-tr-sm' : 'rounded-tl-sm'"
        >
          <img :src="msg.message" class="max-w-full max-h-64 rounded-xl" alt="Shared image" />
        </button>

        <!-- Text -->
        <div
          v-else
          class="px-3 py-2 rounded-2xl text-sm max-w-[85%] break-words"
          :class="msg.isLocal
            ? 'bg-primary-500/20 text-primary-100 rounded-tr-sm'
            : 'bg-surface-700/60 text-white/90 rounded-tl-sm'"
          v-html="linkify(msg.message)"
        ></div>
      </div>
    </div>

    <!-- Upload in progress -->
    <div v-if="uploadingCount > 0" class="px-4 py-1.5 border-t border-surface-700/40 flex items-center gap-2 text-surface-400 text-xs">
      <span class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
      Uploading {{ uploadingCount > 1 ? `${uploadingCount} files` : 'file' }}…
    </div>

    <Transition name="slide-up">
      <div v-if="showEmojiPicker" class="px-3 py-2 border-t border-surface-700/40 grid grid-cols-10 gap-1">
        <button
          v-for="em in commonEmojis" :key="em"
          @click="insertEmoji(em)"
          class="w-8 h-8 flex items-center justify-center text-lg hover:bg-surface-700/50 rounded-lg transition-colors"
        >{{ em }}</button>
      </div>
    </Transition>

    <div class="p-3 border-t border-surface-700/40">
      <div class="flex items-center gap-1.5">
        <button
          @click="showEmojiPicker = !showEmojiPicker"
          class="w-8 h-8 rounded-full flex items-center justify-center text-surface-400 hover:text-white hover:bg-surface-700/50 transition-all flex-shrink-0"
          :class="{ 'text-yellow-400': showEmojiPicker }"
          title="Emoji"
        >
          <span class="material-symbols-rounded text-lg">sentiment_satisfied</span>
        </button>
        <button
          v-if="attachmentsBaseUrl"
          @click="openFilePicker"
          class="w-8 h-8 rounded-full flex items-center justify-center text-surface-400 hover:text-white hover:bg-surface-700/50 transition-all flex-shrink-0"
          title="Attach files"
        >
          <span class="material-symbols-rounded text-lg">attach_file</span>
        </button>
        <input
          ref="fileInputEl"
          type="file"
          multiple
          class="hidden"
          @change="onFileInputChange"
        />
        <input
          v-model="chatInput"
          type="text"
          placeholder="Type a message..."
          class="flex-1 min-w-0 px-3 py-2 rounded-xl bg-surface-700/50 border border-surface-600 text-white text-sm
                 placeholder-surface-500 focus:ring-1 focus:ring-primary-500 outline-none"
          @keydown.enter="sendMessage"
          @paste="handlePaste"
        />
        <button
          @click="sendMessage"
          :disabled="!chatInput.trim()"
          class="w-9 h-9 rounded-full bg-primary-500 hover:bg-primary-600 disabled:opacity-30 disabled:hover:bg-primary-500
                 flex items-center justify-center text-white transition-all flex-shrink-0"
        >
          <span class="material-symbols-rounded text-base">send</span>
        </button>
      </div>
    </div>

    <!-- Image lightbox -->
    <Teleport to="body">
      <Transition name="lightbox">
        <div
          v-if="previewImage"
          class="fixed inset-0 z-[10000] bg-black/85 backdrop-blur-sm flex flex-col items-center justify-center p-4 sm:p-8"
          @click.self="closePreview"
        >
          <!-- Top bar: filename + actions -->
          <div class="absolute top-0 left-0 right-0 flex items-center justify-between gap-3 px-4 py-3 bg-gradient-to-b from-black/60 to-transparent">
            <p class="text-white/85 text-sm font-medium truncate min-w-0">{{ previewImage.name || 'Image' }}</p>
            <div class="flex items-center gap-1.5 flex-shrink-0">
              <a
                :href="previewImage.downloadUrl"
                download target="_blank" rel="noopener"
                class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-colors"
                title="Download"
                @click.stop
              >
                <span class="material-symbols-rounded text-xl">download</span>
              </a>
              <button
                type="button"
                @click="closePreview"
                class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-colors"
                title="Close (Esc)"
              >
                <span class="material-symbols-rounded text-xl">close</span>
              </button>
            </div>
          </div>

          <img
            :src="previewImage.src"
            :alt="previewImage.name || 'Image'"
            class="max-w-full max-h-full object-contain rounded-xl shadow-2xl select-none"
            @click.stop
          />
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.2s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  max-height: 0;
  opacity: 0;
  overflow: hidden;
}
.slide-up-enter-to,
.slide-up-leave-from {
  max-height: 200px;
  opacity: 1;
}
.lightbox-enter-active,
.lightbox-leave-active {
  transition: opacity 0.2s ease;
}
.lightbox-enter-active img,
.lightbox-leave-active img {
  transition: transform 0.2s ease;
}
.lightbox-enter-from,
.lightbox-leave-to {
  opacity: 0;
}
.lightbox-enter-from img,
.lightbox-leave-to img {
  transform: scale(0.95);
}
</style>
