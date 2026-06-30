<template>
  <teleport to="body">
    <div
      class="fixed inset-0 z-[9999] flex flex-col bg-black/90"
      @click.self="$emit('close')"
      @keydown.esc="$emit('close')"
    >
      <!-- Top bar -->
      <div class="flex items-center justify-between px-4 py-3 bg-black/60 backdrop-blur-sm flex-shrink-0">
        <div class="flex items-center gap-3 min-w-0">
          <span class="material-symbols-rounded text-2xl" :style="{ color: fileIconColor }">{{ fileIcon }}</span>
          <div class="min-w-0">
            <p class="font-medium text-white text-sm truncate">{{ fileName }}</p>
            <p class="text-xs text-surface-400">{{ fileSizeLabel }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <!-- Edit in Collab (docx / pptx) -->
          <button
            v-if="canEditInCollab"
            @click="$emit('edit-collab', item)"
            class="px-3 py-1.5 rounded-full text-sm font-medium bg-primary-500 hover:bg-primary-600 text-white transition-colors flex items-center gap-1.5"
          >
            <span class="material-symbols-rounded text-sm">edit_document</span>
            Edit
          </button>
          <!-- Download -->
          <button
            @click="downloadFile"
            class="p-2 rounded-full hover:bg-white/10 text-white transition-colors"
            title="Download"
          >
            <span class="material-symbols-rounded text-xl">download</span>
          </button>
          <!-- Close -->
          <button
            @click="$emit('close')"
            class="p-2 rounded-full hover:bg-white/10 text-white transition-colors"
            title="Close"
          >
            <span class="material-symbols-rounded text-xl">close</span>
          </button>
        </div>
      </div>

      <!-- Preview content -->
      <div class="flex-1 overflow-hidden flex items-center justify-center min-h-0">
        <!-- Loading -->
        <div v-if="loading" class="flex flex-col items-center gap-3 text-white">
          <div class="animate-spin w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full"></div>
          <p class="text-sm text-surface-400">Loading preview...</p>
        </div>

        <!-- Image -->
        <div v-else-if="fileCategory === 'image'" class="flex-1 h-full flex items-center justify-center p-4">
          <img
            v-if="blobUrl"
            :src="blobUrl"
            :alt="fileName"
            class="max-w-full max-h-full object-contain rounded-lg"
          />
        </div>

        <!-- PDF -->
        <div v-else-if="fileCategory === 'pdf'" class="w-full h-full flex flex-col">
          <iframe
            v-if="blobUrl"
            :src="blobUrl"
            class="w-full flex-1 bg-white"
            frameborder="0"
          ></iframe>
          <div v-else class="flex flex-col items-center justify-center h-full text-white gap-4">
            <span class="material-symbols-rounded text-6xl text-red-400">picture_as_pdf</span>
            <p>{{ fileName }}</p>
            <button @click="downloadFile" class="px-4 py-2 rounded-full bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors">
              <span class="material-symbols-rounded text-sm mr-1">download</span>
              Download PDF
            </button>
          </div>
        </div>

        <!-- DOCX (rendered as HTML — always light theme) -->
        <div v-else-if="fileCategory === 'docx'" class="w-full h-full overflow-auto bg-white text-slate-800 file-preview-light">
          <div
            v-if="docxHtml"
            class="docx-preview max-w-4xl mx-auto p-8"
            v-html="docxHtml"
          ></div>
          <div v-else class="flex items-center justify-center h-full text-slate-500">
            <p>Failed to render document</p>
          </div>
        </div>

        <!-- Excel (rendered as HTML table — always light theme) -->
        <div v-else-if="fileCategory === 'excel'" class="w-full h-full flex flex-col bg-white text-slate-800 file-preview-light">
          <!-- Sheet tabs -->
          <div v-if="excelSheets.length > 1" class="flex-shrink-0 flex items-center gap-1 px-4 py-2 bg-slate-100 border-b border-slate-200 overflow-x-auto">
            <button
              v-for="(sheet, idx) in excelSheets"
              :key="idx"
              @click="switchExcelSheet(idx)"
              :class="[
                'px-3 py-1.5 text-sm rounded-t-lg transition-colors whitespace-nowrap',
                activeExcelSheet === idx
                  ? 'bg-white text-green-600 font-medium border-t border-x border-slate-200 -mb-px'
                  : 'text-slate-600 hover:bg-slate-200'
              ]"
            >{{ sheet }}</button>
          </div>
          <div
            v-if="excelHtml"
            class="excel-preview flex-1 overflow-auto p-2"
            v-html="excelHtml"
          ></div>
          <div v-else class="flex items-center justify-center h-full text-slate-500">
            <p>Failed to render spreadsheet</p>
          </div>
        </div>

        <!-- Video -->
        <div v-else-if="fileCategory === 'video'" class="flex-1 h-full flex items-center justify-center">
          <video v-if="blobUrl" :src="blobUrl" controls playsinline class="max-w-full max-h-full" />
        </div>

        <!-- Audio -->
        <div v-else-if="fileCategory === 'audio'" class="flex flex-col items-center justify-center gap-4 text-white">
          <span class="material-symbols-rounded text-6xl text-primary-400">audio_file</span>
          <p class="text-lg font-medium">{{ fileName }}</p>
          <audio v-if="blobUrl" :src="blobUrl" controls class="w-full max-w-md" />
        </div>

        <!-- Text / JSON -->
        <div v-else-if="fileCategory === 'text'" class="w-full h-full overflow-auto bg-surface-900 p-6">
          <pre class="text-sm text-surface-300 whitespace-pre-wrap font-mono">{{ textContent }}</pre>
        </div>

        <!-- PPT (no client-side viewer yet) -->
        <div v-else-if="fileCategory === 'ppt'" class="flex flex-col items-center justify-center gap-4 text-white">
          <span class="material-symbols-rounded text-6xl text-orange-400">slideshow</span>
          <h3 class="text-lg font-semibold">{{ fileName }}</h3>
          <p class="text-surface-400 text-sm max-w-md text-center">
            PowerPoint files can be edited in the collaborative editor or downloaded.
          </p>
          <div class="flex gap-3">
            <button
              v-if="canEditInCollab"
              @click="$emit('edit-collab', item)"
              class="px-4 py-2 rounded-full bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors flex items-center gap-1.5"
            >
              <span class="material-symbols-rounded text-sm">edit_document</span>
              Edit in Editor
            </button>
            <button @click="downloadFile" class="px-4 py-2 rounded-full bg-white/10 hover:bg-white/20 text-white text-sm font-medium transition-colors flex items-center gap-1.5">
              <span class="material-symbols-rounded text-sm">download</span>
              Download
            </button>
          </div>
        </div>

        <!-- Unsupported -->
        <div v-else class="flex flex-col items-center justify-center gap-4 text-white">
          <span class="material-symbols-rounded text-6xl text-surface-500">draft</span>
          <p class="text-surface-400">Preview not available for this file type</p>
          <button @click="downloadFile" class="px-4 py-2 rounded-full bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors flex items-center gap-1.5">
            <span class="material-symbols-rounded text-sm">download</span>
            Download File
          </button>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { getToken } from '@/services/tokenStorage'
import api from '@/services/api'
import {
  classifyFile,
  renderDocxToHtml,
  getExcelSheetNames,
  renderExcelSheetToHtml,
} from '@/composables/useFilePreviewRenderer'

const props = defineProps({
  item: { type: Object, required: true }
})

const emit = defineEmits(['close', 'edit-collab'])

const loading = ref(true)
const blobUrl = ref(null)
const docxHtml = ref(null)
const excelHtml = ref(null)
const excelSheets = ref([])
const activeExcelSheet = ref(0)
const textContent = ref('')
// Cached parsed buffer so excel sheet tabs don't re-fetch nor re-decode
// the entire blob on each switch.
const excelArrayBuffer = ref(null)
const rawBlob = ref(null) // Keep raw blob for excel sheet switching

const fileName = computed(() => props.item.title || 'File')
const mimeType = computed(() => props.item.style_data?.mime_type || props.item.mime_type || guessMimeFromName(fileName.value))

const fileSizeLabel = computed(() => {
  const size = props.item.style_data?.file_size || props.item.file_size
  if (!size) return ''
  if (size < 1024) return size + ' B'
  if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB'
  return (size / (1024 * 1024)).toFixed(1) + ' MB'
})

// Single source of truth for the file-bucket classification, shared
// with AttachmentPreview and DriveView via the composable. Note that
// `.doc` collapses to a separate bucket from `.docx` upstream — we
// fold `doc` into `docx` here so the "Edit in Collab" affordance keeps
// its previous behavior, and the unsupported branch handles legacy
// `.doc` via the explicit canEditInCollab gate.
const fileCategory = computed(() => {
  const cat = classifyFile(fileName.value, mimeType.value)
  return cat === 'doc' ? 'docx' : cat
})

const canEditInCollab = computed(() => {
  const name = fileName.value.toLowerCase()
  return name.endsWith('.docx') || name.endsWith('.pptx')
})

const fileIcon = computed(() => {
  const cat = fileCategory.value
  const icons = {
    image: 'image',
    pdf: 'picture_as_pdf',
    docx: 'description',
    excel: 'table_chart',
    ppt: 'slideshow',
    video: 'movie',
    audio: 'audio_file',
    text: 'article',
  }
  return icons[cat] || 'insert_drive_file'
})

const fileIconColor = computed(() => {
  const colors = {
    image: '#3b82f6',
    pdf: '#ef4444',
    docx: '#3b82f6',
    excel: '#22c55e',
    ppt: '#f97316',
    video: '#8b5cf6',
    audio: '#ec4899',
    text: '#6b7280',
  }
  return colors[fileCategory.value] || '#9ca3af'
})

function guessMimeFromName(name) {
  const n = name.toLowerCase()
  if (n.endsWith('.pdf')) return 'application/pdf'
  if (n.endsWith('.docx')) return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
  if (n.endsWith('.doc')) return 'application/msword'
  if (n.endsWith('.xlsx')) return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
  if (n.endsWith('.xls')) return 'application/vnd.ms-excel'
  if (n.endsWith('.pptx')) return 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
  if (n.endsWith('.ppt')) return 'application/vnd.ms-powerpoint'
  if (/\.(jpg|jpeg)$/.test(n)) return 'image/jpeg'
  if (n.endsWith('.png')) return 'image/png'
  if (n.endsWith('.gif')) return 'image/gif'
  if (n.endsWith('.webp')) return 'image/webp'
  if (n.endsWith('.svg')) return 'image/svg+xml'
  if (n.endsWith('.mp4')) return 'video/mp4'
  if (n.endsWith('.webm')) return 'video/webm'
  if (n.endsWith('.mp3')) return 'audio/mpeg'
  if (n.endsWith('.txt')) return 'text/plain'
  if (n.endsWith('.json')) return 'application/json'
  return 'application/octet-stream'
}

function getFileUrl() {
  // If item has a url field (local upload or mood board serve URL)
  if (props.item.url) {
    // Relative URL - make it absolute
    if (props.item.url.startsWith('/')) {
      return window.location.origin + props.item.url
    }
    return props.item.url
  }

  // If item has a drive_file_id, use the drive download endpoint
  if (props.item.drive_file_id) {
    return `${api.defaults.baseURL}/drive/files/${props.item.drive_file_id}/download`
  }

  // If item has image_url (for images that were stored as type=file)
  if (props.item.image_url) {
    if (props.item.image_url.startsWith('/')) {
      return window.location.origin + props.item.image_url
    }
    return props.item.image_url
  }

  return null
}

async function loadPreview() {
  loading.value = true
  const url = getFileUrl()
  if (!url) {
    loading.value = false
    return
  }

  try {
    const token = getToken('webmail_token')
    const response = await fetch(url, {
      headers: token ? { 'Authorization': `Bearer ${token}` } : {}
    })

    if (!response.ok) {
      console.error('Failed to fetch file for preview:', response.status)
      loading.value = false
      return
    }

    const blob = await response.blob()
    rawBlob.value = blob
    const cat = fileCategory.value

    if (cat === 'docx') {
      const arrayBuffer = await blob.arrayBuffer()
      const result = await renderDocxToHtml(arrayBuffer)
      docxHtml.value = result.html
    } else if (cat === 'excel') {
      const arrayBuffer = await blob.arrayBuffer()
      // Cache parsed buffer so sheet-tab switches don't re-decode.
      excelArrayBuffer.value = arrayBuffer
      excelSheets.value = await getExcelSheetNames(arrayBuffer)
      if (excelSheets.value.length > 0) {
        excelHtml.value = await renderExcelSheetToHtml(arrayBuffer, 0)
      }
    } else if (cat === 'text') {
      textContent.value = await blob.text()
    } else {
      blobUrl.value = URL.createObjectURL(blob)
    }
  } catch (e) {
    console.error('Failed to load file preview:', e)
  }

  loading.value = false
}

async function switchExcelSheet(idx) {
  if (idx === activeExcelSheet.value) return
  // Lazily decode the raw blob once; subsequent tab clicks reuse it.
  if (!excelArrayBuffer.value && rawBlob.value) {
    try {
      excelArrayBuffer.value = await rawBlob.value.arrayBuffer()
    } catch (e) {
      console.error('Failed to read excel blob:', e)
      return
    }
  }
  if (!excelArrayBuffer.value) return
  activeExcelSheet.value = idx
  try {
    excelHtml.value = await renderExcelSheetToHtml(excelArrayBuffer.value, idx)
  } catch (e) {
    console.error('Failed to switch excel sheet:', e)
  }
}

function downloadFile() {
  const url = getFileUrl()
  if (!url) return

  // For drive URLs that need auth, download via fetch
  if (url.includes('/api/drive/files/')) {
    const token = getToken('webmail_token')
    fetch(url, {
      headers: token ? { 'Authorization': `Bearer ${token}` } : {}
    })
      .then(r => r.blob())
      .then(blob => {
        const a = document.createElement('a')
        a.href = URL.createObjectURL(blob)
        a.download = fileName.value
        document.body.appendChild(a)
        a.click()
        setTimeout(() => {
          URL.revokeObjectURL(a.href)
          document.body.removeChild(a)
        }, 100)
      })
  } else {
    // For mood board upload URLs (no auth needed)
    const a = document.createElement('a')
    a.href = url
    a.download = fileName.value
    a.target = '_blank'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
  }
}

// Keyboard handler
function onKeyDown(e) {
  if (e.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => {
  loadPreview()
  document.addEventListener('keydown', onKeyDown)
})

onUnmounted(() => {
  if (blobUrl.value) {
    URL.revokeObjectURL(blobUrl.value)
  }
  document.removeEventListener('keydown', onKeyDown)
})
</script>

<style>
/* Force light theme for document previews — never use dark text on dark bg */
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

.docx-preview {
  color: #1e293b;
  line-height: 1.6;
}
.docx-preview h1 { font-size: 1.75rem; font-weight: 700; margin: 1.5rem 0 0.75rem; }
.docx-preview h2 { font-size: 1.4rem; font-weight: 600; margin: 1.25rem 0 0.5rem; }
.docx-preview h3 { font-size: 1.15rem; font-weight: 600; margin: 1rem 0 0.5rem; }
.docx-preview p { margin: 0.5rem 0; }
.docx-preview img { max-width: 100%; height: auto; margin: 1rem 0; }
.docx-preview table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
.docx-preview td, .docx-preview th { border: 1px solid #e2e8f0; padding: 8px 12px; text-align: left; }
.docx-preview th { background: #f8fafc; font-weight: 600; }

.excel-preview table { border-collapse: collapse; width: 100%; font-size: 0.8rem; color: #1e293b; }
.excel-preview td, .excel-preview th { border: 1px solid #e2e8f0; padding: 4px 8px; white-space: nowrap; color: #1e293b; }
.excel-preview th { background: #f1f5f9; font-weight: 600; font-size: 0.75rem; color: #334155; }
.excel-preview tr:nth-child(even) td { background: #f8fafc; }
</style>

