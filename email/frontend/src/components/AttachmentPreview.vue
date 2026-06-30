<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'
import { folderCollectionUrl } from '@/services/mailRouteService'
import { isVideoFormatSupported, getUnsupportedFormatMessage } from '@/services/emailContentProcessor'
import { useDriveStore } from '@/stores/drive'
import { useShareModalStore } from '@/stores/shareModal'
import { useMailboxStore } from '@/stores/mailbox'
import { useToastStore } from '@/stores/toast'
import { isDebugEnabled } from '@/utils/debug'
import {
  classifyFile,
  renderDocxToHtml,
  getExcelSheetNames,
  renderExcelSheetToHtml,
} from '@/composables/useFilePreviewRenderer'
import { useOfficeStatus } from '@/composables/useOfficeStatus'

const drive = useDriveStore()
const shareModal = useShareModalStore()
const toast = useToastStore()
const mailbox = useMailboxStore()
const router = useRouter()
const { t } = useI18n()
const { ensureOfficeStatus, canEditInOffice } = useOfficeStatus()

function decodeBase64(str) {
  try { return window.atob(str) } catch { return str }
}

// Word (docx) HTML content rendered via mammoth.
const wordHtmlContent = ref(null)
const wordConversionError = ref(null)

// Excel/CSV preview state. The parsed ArrayBuffer is kept around so
// switching between sheet tabs doesn't re-fetch the attachment.
const excelHtmlContent = ref(null)
const excelSheets = ref([])
const activeExcelSheet = ref(0)
const excelArrayBuffer = ref(null)

const props = defineProps({
  show: Boolean,
  attachments: Array,  // All attachments
  initialIndex: Number, // Starting index
  folder: String,
  uid: Number,
  emailSubject: String,
  emailDate: String,
  senderEmail: String // For client folder detection
})

const emit = defineEmits(['close', 'saved'])

const currentIndex = ref(0)
const loading = ref(false)
const error = ref(null)
const previewData = ref(null)
const previewUrl = ref(null)
const previewCache = ref({}) // Cache loaded attachments
const savingToDrive = ref(false)
const openingInOffice = ref(false)
// Map of IMAP `part` -> saved Drive file row, for the (folder, uid) we
// were opened against. Lets the modal show a "Saved to Drive" indicator
// and replace the Save button with Share + Open-in-Drive once a file
// has been persisted.
const savedDriveByPart = ref({})

// Current attachment based on index
const attachment = computed(() => {
  return props.attachments?.[currentIndex.value] || null
})

// Navigation
const hasMultiple = computed(() => (props.attachments?.length || 0) > 1)
const canGoPrev = computed(() => currentIndex.value > 0)
const canGoNext = computed(() => currentIndex.value < (props.attachments?.length || 1) - 1)

function goToPrev() {
  if (canGoPrev.value) {
    currentIndex.value--
  }
}

function goToNext() {
  if (canGoNext.value) {
    currentIndex.value++
  }
}

function goToIndex(index) {
  if (index >= 0 && index < (props.attachments?.length || 0)) {
    currentIndex.value = index
  }
}

// Keyboard navigation
function handleKeydown(e) {
  if (!props.show) return
  
  if (e.key === 'ArrowLeft') {
    goToPrev()
  } else if (e.key === 'ArrowRight') {
    goToNext()
  } else if (e.key === 'Escape') {
    emit('close')
  }
}

// Determine file type category via the shared classifier, the single
// source of truth used by AttachmentPreview, DriveView and
// MoodFilePreview. Returns one of: image|pdf|docx|doc|excel|ppt|video|
// audio|text|unknown.
const fileType = computed(() => {
  if (!attachment.value) return 'unknown'
  return classifyFile(attachment.value.filename, attachment.value.type)
})

const canPreview = computed(() => {
  return ['image', 'pdf', 'text', 'video', 'audio', 'docx', 'excel'].includes(fileType.value)
})

// Check if video format is supported by HTML5 video element
const isVideoSupported = computed(() => {
  if (fileType.value !== 'video') return true
  return isVideoFormatSupported(attachment.value?.filename)
})

const videoUnsupportedMessage = computed(() => {
  if (fileType.value !== 'video' || isVideoSupported.value) return ''
  return getUnsupportedFormatMessage(attachment.value?.filename)
})

// Office formats that have no client-side renderer (legacy .doc and
// .ppt/.pptx) — rendered as a download prompt.
const isOfficeDocFallback = computed(() => {
  return ['doc', 'ppt'].includes(fileType.value)
})

async function refreshSavedDriveStatus() {
  if (!props.folder || !props.uid) return
  const slim = (props.attachments || [])
    .filter((a) => a?.part != null)
    .map((a) => ({ part: a.part, filename: a.filename, size: a.size }))
  const files = await drive.fetchEmailAttachmentsStatus(props.folder, props.uid, slim)
  const byPart = {}
  for (const f of files) {
    if (f?.part) byPart[String(f.part)] = f
  }
  savedDriveByPart.value = byPart
}

const savedDriveFile = computed(() => {
  const p = attachment.value?.part
  if (!p) return null
  return savedDriveByPart.value[String(p)] || null
})

// Office-editable attachment (docx/xlsx/pptx/md) -> show the Edit button.
const canEditAttachment = computed(() => canEditInOffice(attachment.value?.filename))

// Watch for show state
watch(() => props.show, async (show) => {
  if (show) {
    // Set initial index
    currentIndex.value = props.initialIndex || 0
    // Add keyboard listener
    window.addEventListener('keydown', handleKeydown)
    // OnlyOffice availability (cached after the first call) so the Edit
    // button can show for editable formats.
    ensureOfficeStatus()
    // Load current attachment
    await loadAttachment()
    // Load saved-to-Drive status for this message in parallel.
    refreshSavedDriveStatus()
  } else {
    // Cleanup
    window.removeEventListener('keydown', handleKeydown)
    if (previewUrl.value) {
      URL.revokeObjectURL(previewUrl.value)
      previewUrl.value = null
    }
    // Clear cache URLs
    Object.values(previewCache.value).forEach(item => {
      if (item.url) URL.revokeObjectURL(item.url)
    })
    previewCache.value = {}
    previewData.value = null
    error.value = null
  }
})

// Watch for index change
watch(currentIndex, async () => {
  if (props.show && attachment.value) {
    await loadAttachment()
  }
})

async function loadAttachment() {
  if (!attachment.value) return
  
  const cacheKey = attachment.value.part
  
  // Check cache first
  if (previewCache.value[cacheKey]) {
    const cached = previewCache.value[cacheKey]
    previewData.value = cached.data
    previewUrl.value = cached.url
    wordHtmlContent.value = cached.wordHtml || null
    excelArrayBuffer.value = cached.excelArrayBuffer || null
    excelSheets.value = cached.excelSheets || []
    activeExcelSheet.value = cached.activeExcelSheet || 0
    excelHtmlContent.value = cached.excelHtml || null
    return
  }
  
  loading.value = true
  error.value = null
  wordHtmlContent.value = null
  wordConversionError.value = null
  excelArrayBuffer.value = null
  excelSheets.value = []
  activeExcelSheet.value = 0
  excelHtmlContent.value = null
  
  // Clear previous URL if not cached
  if (previewUrl.value && !Object.values(previewCache.value).some(c => c.url === previewUrl.value)) {
    URL.revokeObjectURL(previewUrl.value)
  }
  previewUrl.value = null
  
  try {
    const response = await api.get(
      folderCollectionUrl(mailbox.folders, props.folder, `messages/${props.uid}/attachments/${attachment.value.part}`)
    )
    
    if (response.data.success) {
      previewData.value = response.data.data
      
      // Create blob URL for preview
      const binary = atob(previewData.value.content)
      const array = new Uint8Array(binary.length)
      for (let i = 0; i < binary.length; i++) {
        array[i] = binary.charCodeAt(i)
      }
      const blob = new Blob([array], { type: previewData.value.type })
      previewUrl.value = URL.createObjectURL(blob)

      // Document/spreadsheet rendering via the shared composable. We
      // dispatch on classifyFile()'s output so the same logic that
      // powers DriveView and the moodboards preview drives the email
      // attachment modal too.
      const arrayBuffer = array.buffer
      if (fileType.value === 'docx') {
        try {
          const result = await renderDocxToHtml(arrayBuffer)
          wordHtmlContent.value = result.html
          if (result.messages?.length && isDebugEnabled()) {
            console.log('Mammoth conversion messages:', result.messages)
          }
        } catch (convErr) {
          console.error('Failed to convert DOCX:', convErr)
          wordConversionError.value = 'Could not render document preview'
        }
      } else if (fileType.value === 'excel') {
        try {
          excelArrayBuffer.value = arrayBuffer
          excelSheets.value = await getExcelSheetNames(arrayBuffer)
          if (excelSheets.value.length > 0) {
            excelHtmlContent.value = await renderExcelSheetToHtml(arrayBuffer, 0)
          }
        } catch (convErr) {
          console.error('Failed to parse spreadsheet:', convErr)
        }
      }

      // Cache this attachment (including parsed buffers / HTML) so that
      // navigating away and back doesn't re-fetch or re-parse.
      previewCache.value[cacheKey] = {
        data: previewData.value,
        url: previewUrl.value,
        wordHtml: wordHtmlContent.value,
        excelArrayBuffer: excelArrayBuffer.value,
        excelSheets: excelSheets.value,
        activeExcelSheet: activeExcelSheet.value,
        excelHtml: excelHtmlContent.value,
      }
    } else {
      error.value = 'Failed to load attachment'
    }
  } catch (e) {
    console.error('Failed to load attachment:', e)
    error.value = 'Failed to load attachment'
  } finally {
    loading.value = false
  }
}

async function switchExcelSheet(idx) {
  if (idx === activeExcelSheet.value || !excelArrayBuffer.value) return
  activeExcelSheet.value = idx
  try {
    excelHtmlContent.value = await renderExcelSheetToHtml(excelArrayBuffer.value, idx)
  } catch (e) {
    console.error('Failed to switch excel sheet:', e)
    return
  }
  // Update cache with the freshly-rendered sheet so re-opening this
  // attachment lands on the same tab.
  const cacheKey = attachment.value?.part
  if (cacheKey && previewCache.value[cacheKey]) {
    previewCache.value[cacheKey].activeExcelSheet = idx
    previewCache.value[cacheKey].excelHtml = excelHtmlContent.value
  }
}

function download() {
  if (!previewUrl.value || !previewData.value) return
  
  const link = document.createElement('a')
  link.href = previewUrl.value
  link.download = previewData.value.filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

async function saveToDrive() {
  if (!previewData.value || savingToDrive.value) return
  
  savingToDrive.value = true
  try {
    const result = await drive.saveEmailAttachment(
      previewData.value.filename,
      previewData.value.content, // Already base64
      previewData.value.type,
      props.emailSubject || 'Email',
      props.emailDate,
      props.senderEmail, // Pass sender email for client folder detection
      props.folder || null,
      props.uid || null,
      attachment.value?.part || null
    )

    if (result.success) {
      // Show different message if saved to client folder
      const location = result.clientFolder 
        ? `${result.clientFolder.name}/Attachments/${result.folder.name}`
        : `Attachments/${result.folder.name}`
      toast.success(`Saved to ${location}`)
      // Refresh saved-status so the Save button switches to Share + Open.
      await refreshSavedDriveStatus()
      // Let parent refresh its own saved-status cache too, so the
      // attachment card behind this modal shows the cloud_done badge
      // immediately after the modal closes.
      emit('saved', {
        folder: props.folder,
        uid: props.uid,
        part: attachment.value?.part,
      })
    } else {
      toast.error(result.error || 'Failed to save to Drive')
    }
  } catch (e) {
    toast.error('Failed to save to Drive')
  } finally {
    savingToDrive.value = false
  }
}

// Open the same unified share modal used everywhere (Tab 1: share link).
function shareSaved() {
  const saved = savedDriveFile.value
  if (!saved) return
  shareModal.open(
    {
      id: saved.id,
      name: saved.name || saved.filename || attachment.value?.filename || '',
    },
    'file',
    { defaultTab: 'link' }
  )
}

function openSavedInDrive() {
  const saved = savedDriveFile.value
  if (!saved) return
  const folderId = saved.folder_id || saved.folderId
  if (folderId) {
    router.push({ name: 'drive', query: { folder: folderId, file: saved.id } })
  } else {
    router.push({ name: 'drive', query: { file: saved.id } })
  }
  emit('close')
}

function openSavedInOffice(saved) {
  const folderId = saved.folder_id || saved.folderId
  // Pass the current mail URL so the editor's Back button returns to
  // the email (exact folder + open message) instead of Drive.
  const query = { back: router.currentRoute.value.fullPath }
  if (folderId) query.folder = String(folderId)
  router.push({
    name: 'office-editor',
    params: { fileId: String(saved.id) },
    query,
  })
  emit('close')
}

// "Edit" button: OnlyOffice edits Drive files only, so an unsaved
// attachment is first persisted via the existing save-to-Drive flow,
// then opened in the editor.
async function editInOffice() {
  if (openingInOffice.value) return

  const existing = savedDriveFile.value
  if (existing) {
    openSavedInOffice(existing)
    return
  }

  openingInOffice.value = true
  try {
    await saveToDrive()
    const saved = savedDriveFile.value
    if (saved) openSavedInOffice(saved)
  } finally {
    openingInOffice.value = false
  }
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function getFileIcon() {
  return getFileIconFor(attachment.value)
}

function getFileIconFor(att) {
  if (!att) return 'attachment'
  
  const filename = att.filename?.toLowerCase() || ''
  const mimeType = att.type?.toLowerCase() || ''
  
  if (mimeType.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|svg|bmp|ico)$/.test(filename)) {
    return 'image'
  }
  if (mimeType === 'application/pdf' || filename.endsWith('.pdf')) {
    return 'picture_as_pdf'
  }
  if (/\.(doc|docx)$/.test(filename) || mimeType.includes('word')) {
    return 'description'
  }
  if (/\.(xls|xlsx)$/.test(filename) || mimeType.includes('spreadsheet') || mimeType.includes('excel')) {
    return 'table_chart'
  }
  if (/\.(ppt|pptx)$/.test(filename) || mimeType.includes('presentation') || mimeType.includes('powerpoint')) {
    return 'slideshow'
  }
  if (mimeType.startsWith('video/') || /\.(mp4|webm|ogg|mov|avi)$/.test(filename)) {
    return 'movie'
  }
  if (mimeType.startsWith('audio/') || /\.(mp3|wav|ogg|m4a)$/.test(filename)) {
    return 'audio_file'
  }
  if (mimeType.startsWith('text/') || /\.(txt|csv|json|xml|html|css|js|md)$/.test(filename)) {
    return 'article'
  }
  return 'attachment'
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div 
        v-if="show" 
        class="fixed inset-0 z-[200] flex items-center justify-center p-4"
        @click.self="emit('close')"
      >
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')"></div>
        
        <!-- Modal -->
        <div class="relative w-full max-w-5xl h-[90vh] bg-white dark:bg-surface-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
          <!-- Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-3 min-w-0">
              <span class="material-symbols-rounded text-2xl text-surface-500">{{ getFileIcon() }}</span>
              <div class="min-w-0">
                <h3 class="font-semibold text-surface-900 dark:text-surface-100 truncate">
                  {{ attachment?.filename }}
                </h3>
                <p class="text-sm text-surface-500">
                  {{ formatSize(attachment?.size) }}
                  <span v-if="hasMultiple" class="ml-2 text-primary-500">
                    {{ currentIndex + 1 }} / {{ attachments.length }}
                  </span>
                </p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <!-- Saved-to-Drive badge in the header so the user knows
                   without doubt that this attachment lives in Drive. -->
              <span
                v-if="savedDriveFile"
                class="hidden sm:inline-flex items-center gap-1 px-2 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-xs font-medium"
                :title="$t('emailView.savedToDrive')"
              >
                <span class="material-symbols-rounded text-[14px] leading-none">cloud_done</span>
                {{ $t('emailView.savedToDrive') }}
              </span>
              <!-- Edit in Office (OnlyOffice) for docx/xlsx/pptx/md.
                   Saves to Drive first when the attachment isn't there yet. -->
              <button
                v-if="canEditAttachment"
                @click="editInOffice"
                :disabled="!previewData || openingInOffice || savingToDrive"
                class="btn-ghost btn-icon"
                :title="$t('emailView.editInOffice')"
              >
                <span v-if="openingInOffice" class="material-symbols-rounded animate-spin">progress_activity</span>
                <span v-else class="material-symbols-rounded">edit_document</span>
              </button>
              <!-- Saved variant: Share + Open in Drive -->
              <template v-if="savedDriveFile">
                <button
                  @click="shareSaved"
                  class="btn-ghost btn-icon"
                  :title="$t('driveView.share')"
                >
                  <span class="material-symbols-rounded">share</span>
                </button>
                <button
                  @click="openSavedInDrive"
                  class="btn-ghost btn-icon"
                  :title="$t('emailView.openInDrive')"
                >
                  <span class="material-symbols-rounded">folder_open</span>
                </button>
              </template>
              <!-- Unsaved variant: Save to Drive -->
              <button
                v-else
                @click="saveToDrive"
                :disabled="!previewData || savingToDrive"
                class="btn-ghost btn-icon"
                :title="savingToDrive ? 'Saving...' : 'Save to Drive'"
              >
                <span v-if="savingToDrive" class="material-symbols-rounded animate-spin">progress_activity</span>
                <span v-else class="material-symbols-rounded">cloud_upload</span>
              </button>
              <button 
                @click="download"
                :disabled="!previewUrl"
                class="btn-ghost btn-icon"
                title="Download"
              >
                <span class="material-symbols-rounded">download</span>
              </button>
              <button @click="emit('close')" class="btn-ghost btn-icon" title="Close">
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
          </div>
          
          <!-- Navigation arrows (for multiple attachments) -->
          <template v-if="hasMultiple">
            <!-- Previous button -->
            <button 
              v-if="canGoPrev"
              @click="goToPrev"
              class="absolute left-4 top-1/2 -translate-y-1/2 z-10 w-12 h-12 rounded-full bg-black/50 hover:bg-black/70 text-white flex items-center justify-center transition-colors"
            >
              <span class="material-symbols-rounded text-3xl">chevron_left</span>
            </button>
            
            <!-- Next button -->
            <button 
              v-if="canGoNext"
              @click="goToNext"
              class="absolute right-4 top-1/2 -translate-y-1/2 z-10 w-12 h-12 rounded-full bg-black/50 hover:bg-black/70 text-white flex items-center justify-center transition-colors"
            >
              <span class="material-symbols-rounded text-3xl">chevron_right</span>
            </button>
          </template>
          
          <!-- Content -->
          <div class="flex-1 overflow-hidden p-4 bg-surface-100 dark:bg-surface-800 flex items-center justify-center">
            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center">
              <span class="spinner text-primary-500 w-10 h-10"></span>
            </div>
            
            <!-- Error -->
            <div v-else-if="error" class="flex flex-col items-center justify-center text-surface-500">
              <span class="material-symbols-rounded text-5xl mb-3">error</span>
              <p>{{ error }}</p>
              <button @click="loadAttachment" class="btn-primary mt-4">
                Try Again
              </button>
            </div>
            
            <!-- Image Preview -->
            <div v-else-if="fileType === 'image' && previewUrl" class="flex items-center justify-center w-full h-full">
              <img 
                :src="previewUrl" 
                :alt="attachment?.filename"
                class="max-w-full max-h-full object-contain rounded-lg shadow-lg"
              />
            </div>
            
            <!-- PDF Preview -->
            <div v-else-if="fileType === 'pdf' && previewUrl" class="w-full h-full">
              <iframe 
                :src="previewUrl"
                class="w-full h-full rounded-lg border-0"
                :title="attachment?.filename"
              ></iframe>
            </div>
            
            <!-- Video Preview -->
            <div v-else-if="fileType === 'video'" class="flex items-center justify-center w-full h-full">
              <!-- Supported video format -->
              <video 
                v-if="isVideoSupported && previewUrl"
                :src="previewUrl"
                controls
                class="max-w-full max-h-full rounded-lg shadow-lg"
              >
                Your browser does not support video playback.
              </video>
              
              <!-- Unsupported video format -->
              <div v-else class="flex flex-col items-center justify-center text-center">
                <span class="material-symbols-rounded text-6xl mb-4 text-surface-400">movie</span>
                <p class="text-lg font-medium text-surface-700 dark:text-surface-300 mb-2">
                  {{ attachment?.filename }}
                </p>
                <p class="text-surface-500 mb-6 max-w-md">
                  {{ videoUnsupportedMessage || 'This video format cannot be played in browser. Download to view with a video player.' }}
                </p>
                <button @click="download" :disabled="!previewUrl" class="btn-primary flex items-center gap-2">
                  <span class="material-symbols-rounded">download</span>
                  Download Video
                </button>
              </div>
            </div>
            
            <!-- Audio Preview -->
            <div v-else-if="fileType === 'audio' && previewUrl" class="flex items-center justify-center">
              <audio :src="previewUrl" controls class="w-full max-w-md">
                Your browser does not support audio playback.
              </audio>
            </div>
            
            <!-- Text Preview -->
            <div v-else-if="fileType === 'text' && previewData" class="w-full h-full overflow-auto">
              <pre class="bg-white dark:bg-surface-900 p-4 rounded-lg text-sm font-mono text-surface-700 dark:text-surface-300 whitespace-pre-wrap">{{ decodeBase64(previewData.content) }}</pre>
            </div>
            
            <!-- Word Document Preview (DOCX, via mammoth) -->
            <div v-else-if="fileType === 'docx' && wordHtmlContent" class="w-full h-full overflow-auto">
              <div
                class="word-preview file-preview-light bg-white p-8 rounded-lg shadow-lg max-w-4xl mx-auto prose prose-sm"
                v-html="wordHtmlContent"
              ></div>
            </div>

            <!-- DOCX Conversion Error -->
            <div v-else-if="fileType === 'docx' && wordConversionError" class="flex flex-col items-center justify-center text-center">
              <span class="material-symbols-rounded text-6xl mb-4 text-surface-400">description</span>
              <p class="text-lg font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ attachment?.filename }}
              </p>
              <p class="text-surface-500 mb-6">
                {{ wordConversionError }}<br>
                Download to view in Microsoft Office.
              </p>
              <button @click="download" class="btn-primary flex items-center gap-2">
                <span class="material-symbols-rounded">download</span>
                Download File
              </button>
            </div>

            <!-- Excel / CSV Preview (XLSX/XLS/CSV via xlsx) -->
            <div v-else-if="fileType === 'excel' && excelHtmlContent" class="w-full h-full overflow-auto flex flex-col">
              <!-- Sheet tabs (only shown for multi-sheet workbooks; .csv has a single sheet) -->
              <div v-if="excelSheets.length > 1" class="flex items-center gap-1 px-2 pt-2 border-b border-surface-200 dark:border-surface-700 overflow-x-auto flex-shrink-0">
                <button
                  v-for="(sheetName, idx) in excelSheets"
                  :key="sheetName + idx"
                  @click="switchExcelSheet(idx)"
                  :class="[
                    'px-3 py-1.5 text-xs font-medium rounded-t-md whitespace-nowrap transition-colors',
                    idx === activeExcelSheet
                      ? 'bg-white dark:bg-surface-900 text-primary-600 dark:text-primary-400 border border-b-0 border-surface-200 dark:border-surface-700'
                      : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200'
                  ]"
                >
                  {{ sheetName }}
                </button>
              </div>
              <div class="flex-1 overflow-auto">
                <div
                  class="excel-preview file-preview-light bg-white p-4 rounded-lg shadow-lg mx-auto"
                  v-html="excelHtmlContent"
                ></div>
              </div>
            </div>

            <!-- Office formats with no client-side renderer (.doc, .ppt/.pptx) -->
            <div v-else-if="isOfficeDocFallback" class="flex flex-col items-center justify-center text-center">
              <span class="material-symbols-rounded text-6xl mb-4 text-surface-400">{{ getFileIcon() }}</span>
              <p class="text-lg font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ attachment?.filename }}
              </p>
              <p class="text-surface-500 mb-6">
                <template v-if="canEditAttachment">
                  This document type can't be previewed inline.<br>
                  Open it in the Office editor, or download it.
                </template>
                <template v-else>
                  This document type can't be previewed in browser.<br>
                  Download to view in Microsoft Office.
                </template>
              </p>
              <div class="flex items-center gap-3">
                <button
                  v-if="canEditAttachment"
                  @click="editInOffice"
                  :disabled="!previewData || openingInOffice || savingToDrive"
                  class="btn-primary flex items-center gap-2"
                >
                  <span v-if="openingInOffice" class="material-symbols-rounded animate-spin">progress_activity</span>
                  <span v-else class="material-symbols-rounded">edit_document</span>
                  {{ $t('emailView.openInOffice') }}
                </button>
                <button
                  @click="download"
                  :class="canEditAttachment ? 'btn-ghost flex items-center gap-2' : 'btn-primary flex items-center gap-2'"
                >
                  <span class="material-symbols-rounded">download</span>
                  Download File
                </button>
              </div>
            </div>
            
            <!-- Unknown file type -->
            <div v-else class="flex flex-col items-center justify-center text-center">
              <span class="material-symbols-rounded text-6xl mb-4 text-surface-400">attachment</span>
              <p class="text-lg font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ attachment?.filename }}
              </p>
              <p class="text-surface-500 mb-6">
                This file type cannot be previewed.
              </p>
              <button @click="download" class="btn-primary flex items-center gap-2">
                <span class="material-symbols-rounded">download</span>
                Download File
              </button>
            </div>
          </div>
          
          <!-- Thumbnail strip / dots indicator -->
          <div v-if="hasMultiple" class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
            <div class="flex items-center justify-center gap-2 overflow-x-auto">
              <button
                v-for="(att, index) in attachments"
                :key="att.part"
                @click="goToIndex(index)"
                :class="[
                  'flex-shrink-0 px-3 py-2 rounded-lg text-xs font-medium transition-all flex items-center gap-2',
                  index === currentIndex 
                    ? 'bg-primary-500 text-white' 
                    : 'bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-300 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-sm">{{ getFileIconFor(att) }}</span>
                <span class="truncate max-w-24">{{ att.filename }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: all 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .relative,
.modal-leave-to .relative {
  transform: scale(0.95);
}

/* Force readable text in Word document preview - override white/light text from Word */
.word-preview :deep(*) {
  color: #1f2937 !important; /* gray-800 */
}

.word-preview :deep(a) {
  color: #2563eb !important; /* blue-600 */
}

.word-preview :deep(h1),
.word-preview :deep(h2),
.word-preview :deep(h3),
.word-preview :deep(h4),
.word-preview :deep(h5),
.word-preview :deep(h6) {
  color: #111827 !important; /* gray-900 */
}
</style>

<style>
/* Force light theme for document previews — keep text readable on the
   white preview pane even when the rest of the app is in dark mode.
   Mirrors MoodFilePreview's `.file-preview-light` block so docx/xlsx/
   csv look identical across all three preview surfaces. Unscoped on
   purpose: v-html content has no scope attributes. */
.file-preview-light,
.file-preview-light *,
.file-preview-light td,
.file-preview-light th,
.file-preview-light p,
.file-preview-light span,
.file-preview-light div,
.file-preview-light h1,
.file-preview-light h2,
.file-preview-light h3,
.file-preview-light h4,
.file-preview-light li,
.file-preview-light a {
  color: #1e293b !important;
}

.excel-preview table {
  border-collapse: collapse;
  width: 100%;
  font-size: 0.8rem;
  color: #1e293b;
}
.excel-preview td,
.excel-preview th {
  border: 1px solid #e2e8f0;
  padding: 4px 8px;
  white-space: nowrap;
  color: #1e293b;
}
.excel-preview th {
  background: #f1f5f9;
  font-weight: 600;
  font-size: 0.75rem;
  color: #334155;
}
.excel-preview tr:nth-child(even) td {
  background: #f8fafc;
}
</style>

