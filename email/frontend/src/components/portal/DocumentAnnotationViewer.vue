<script setup>
/**
 * DocumentAnnotationViewer - Full-screen document viewer with pin annotations.
 * Supports images and PDFs. Users can click anywhere to place a pin and leave
 * a comment with optional attachment uploads. Works for both CRM and portal sides.
 */
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePdfRenderer } from '@/composables/usePdfRenderer'
import { useDocViewTogether } from '@/composables/useDocViewTogether'

const { t } = useI18n()

const props = defineProps({
  documentUrl: { type: String, required: true },
  documentName: { type: String, default: 'Document' },
  mimeType: { type: String, default: '' },
  annotations: { type: Array, default: () => [] },
  currentUserEmail: { type: String, default: '' },
  readonly: { type: Boolean, default: false },
  mode: { type: String, default: 'internal' }, // 'internal' or 'portal'
  clientId: { type: Number, default: 0 },
  docId: { type: Number, default: 0 },
})

const emit = defineEmits([
  'close',
  'create-annotation',
  'add-comment',
  'resolve-annotation',
  'delete-annotation',
  'delete-comment',
])

// View Together (internal only)
const viewTogether = props.mode === 'internal' && props.clientId && props.docId
  ? useDocViewTogether(props.clientId, props.docId)
  : null

const isPdf = computed(() =>
  props.mimeType === 'application/pdf' || props.documentName?.toLowerCase().endsWith('.pdf')
)
const isImage = computed(() =>
  props.mimeType?.startsWith('image/') ||
  /\.(jpe?g|png|gif|webp|svg|bmp|avif)$/i.test(props.documentName)
)

// PDF state
const { pageCount, loading: pdfLoading, error: pdfError, loadPdf, renderPage, destroy: destroyPdf } = usePdfRenderer()
const currentPage = ref(1)
const canvasRefs = ref({})

// Layout state
const wrapperRef = ref(null)
const contentRef = ref(null)
const containerWidth = ref(800)
const pageHeights = ref({})
const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const isPanning = ref(false)
const panStart = ref({ x: 0, y: 0, px: 0, py: 0 })

// Image state
const imageLoaded = ref(false)
const imageNaturalWidth = ref(0)
const imageNaturalHeight = ref(0)

// Annotation state
const placingPin = ref(false)
const pendingPin = ref(null)
const selectedAnnotationId = ref(null)
const commentText = ref('')
const attachmentFiles = ref([])
const submittingComment = ref(false)
const annotationFilter = ref('all') // 'all', 'open', 'resolved'

const filteredAnnotations = computed(() => {
  const pageAnnotations = props.annotations.filter(a =>
    isPdf.value ? a.page_number === currentPage.value : true
  )
  if (annotationFilter.value === 'all') return pageAnnotations
  return pageAnnotations.filter(a => a.status === annotationFilter.value)
})

const selectedAnnotation = computed(() =>
  props.annotations.find(a => a.id === selectedAnnotationId.value)
)

const totalAnnotations = computed(() => props.annotations.length)
const openAnnotations = computed(() => props.annotations.filter(a => a.status === 'open').length)

// Keyboard shortcuts
function handleKeydown(e) {
  if (e.key === 'Escape') {
    if (pendingPin.value) {
      pendingPin.value = null
      placingPin.value = false
    } else if (selectedAnnotationId.value) {
      selectedAnnotationId.value = null
    } else {
      emit('close')
    }
  }
  if (e.key === '+' || e.key === '=') { zoom.value = Math.min(zoom.value + 0.25, 5) }
  if (e.key === '-') { zoom.value = Math.max(zoom.value - 0.25, 0.25) }
  if (e.key === '0') { zoom.value = 1; panX.value = 0; panY.value = 0 }
}

function handleWheel(e) {
  if (e.ctrlKey || e.metaKey) {
    e.preventDefault()
    const delta = e.deltaY > 0 ? -0.1 : 0.1
    zoom.value = Math.max(0.25, Math.min(5, zoom.value + delta))
  }
}

// Pan support
function startPan(e) {
  if (placingPin.value || e.target.closest('.annotation-pin, .annotation-thread-panel')) return
  if (zoom.value <= 1) return
  isPanning.value = true
  panStart.value = { x: e.clientX, y: e.clientY, px: panX.value, py: panY.value }
}
function updatePan(e) {
  if (!isPanning.value) return
  panX.value = panStart.value.px + (e.clientX - panStart.value.x)
  panY.value = panStart.value.py + (e.clientY - panStart.value.y)
}
function endPan() { isPanning.value = false }

// Pin placement
function handleContentClick(e) {
  if (!placingPin.value || isPanning.value) return

  const overlay = e.currentTarget
  const rect = overlay.getBoundingClientRect()
  const xPercent = ((e.clientX - rect.left) / rect.width) * 100
  const yPercent = ((e.clientY - rect.top) / rect.height) * 100

  pendingPin.value = {
    page_number: currentPage.value,
    x_percent: Math.max(0, Math.min(100, xPercent)),
    y_percent: Math.max(0, Math.min(100, yPercent)),
  }
  placingPin.value = false
}

async function submitNewAnnotation() {
  if (!pendingPin.value || !commentText.value.trim()) return
  submittingComment.value = true

  emit('create-annotation', {
    ...pendingPin.value,
    content: commentText.value.trim(),
    attachments: attachmentFiles.value,
  })

  pendingPin.value = null
  commentText.value = ''
  attachmentFiles.value = []
  submittingComment.value = false
}

function submitReply() {
  if (!selectedAnnotationId.value || !commentText.value.trim()) return
  submittingComment.value = true

  emit('add-comment', {
    annotation_id: selectedAnnotationId.value,
    content: commentText.value.trim(),
    attachments: attachmentFiles.value,
  })

  commentText.value = ''
  attachmentFiles.value = []
  submittingComment.value = false
}

function handleAttachmentSelect(e) {
  const files = Array.from(e.target.files || [])
  attachmentFiles.value.push(...files)
  e.target.value = ''
}

function removeAttachment(idx) {
  attachmentFiles.value.splice(idx, 1)
}

// PDF navigation
function goToPage(page) {
  currentPage.value = Math.max(1, Math.min(pageCount.value, page))
}

// Resize observer
let resizeObserver = null
function observeResize() {
  if (!wrapperRef.value) return
  resizeObserver = new ResizeObserver(entries => {
    for (const entry of entries) {
      containerWidth.value = entry.contentRect.width - 48
    }
  })
  resizeObserver.observe(wrapperRef.value)
}

// PDF rendering
async function renderCurrentPage() {
  if (!isPdf.value || !pageCount.value) return
  const canvas = canvasRefs.value[currentPage.value]
  if (!canvas) return
  try {
    const result = await renderPage(currentPage.value, canvas, containerWidth.value)
    pageHeights.value[currentPage.value] = result.height
  } catch (e) {
    console.error('Failed to render page', e)
  }
}

watch(currentPage, () => { nextTick(renderCurrentPage) })
watch(containerWidth, () => { nextTick(renderCurrentPage) })

// Image load
function onImageLoad(e) {
  imageLoaded.value = true
  imageNaturalWidth.value = e.target.naturalWidth
  imageNaturalHeight.value = e.target.naturalHeight
}

onMounted(async () => {
  document.addEventListener('keydown', handleKeydown)
  await nextTick()
  observeResize()
  if (isPdf.value) {
    await loadPdf(props.documentUrl)
    await nextTick()
    await renderCurrentPage()
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
  resizeObserver?.disconnect()
  if (isPdf.value) destroyPdf()
})

function setCanvasRef(pageNum) {
  return (el) => { if (el) canvasRefs.value[pageNum] = el }
}

function pinStyle(ann) {
  return {
    left: ann.x_percent + '%',
    top: ann.y_percent + '%',
  }
}

function pinColor(ann) {
  if (ann.status === 'resolved') return 'bg-green-500'
  if (ann.created_by_type === 'portal') return 'bg-purple-500'
  return 'bg-primary-500'
}

function formatTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleString(undefined, {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
  })
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[100] flex bg-black/80 backdrop-blur-sm" @keydown.esc="emit('close')">

      <!-- Left: Document viewport -->
      <div ref="wrapperRef" class="flex-1 flex flex-col min-w-0 overflow-hidden">

        <!-- Toolbar -->
        <div class="flex items-center gap-2 px-4 py-2 bg-surface-900/90 border-b border-surface-700 shrink-0">
          <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-surface-700 text-surface-300">
            <span class="material-symbols-rounded text-lg">close</span>
          </button>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-white truncate">{{ documentName }}</p>
            <p v-if="isPdf && pageCount" class="text-xs text-surface-400">
              Page {{ currentPage }} / {{ pageCount }}
            </p>
          </div>

          <!-- Annotation filter pills -->
          <div class="flex items-center gap-1 bg-surface-800 rounded-full px-1 py-0.5">
            <button @click="annotationFilter = 'all'"
                    :class="annotationFilter === 'all' ? 'bg-surface-600 text-white' : 'text-surface-400 hover:text-white'"
                    class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors">
              All ({{ totalAnnotations }})
            </button>
            <button @click="annotationFilter = 'open'"
                    :class="annotationFilter === 'open' ? 'bg-amber-600 text-white' : 'text-surface-400 hover:text-white'"
                    class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors">
              Open ({{ openAnnotations }})
            </button>
            <button @click="annotationFilter = 'resolved'"
                    :class="annotationFilter === 'resolved' ? 'bg-green-600 text-white' : 'text-surface-400 hover:text-white'"
                    class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors">
              Resolved
            </button>
          </div>

          <!-- Zoom controls -->
          <div class="flex items-center gap-1">
            <button @click="zoom = Math.max(0.25, zoom - 0.25)" class="p-1 rounded hover:bg-surface-700 text-surface-400">
              <span class="material-symbols-rounded text-sm">remove</span>
            </button>
            <span class="text-xs text-surface-300 w-12 text-center">{{ Math.round(zoom * 100) }}%</span>
            <button @click="zoom = Math.min(5, zoom + 0.25)" class="p-1 rounded hover:bg-surface-700 text-surface-400">
              <span class="material-symbols-rounded text-sm">add</span>
            </button>
            <button @click="zoom = 1; panX = 0; panY = 0" class="p-1 rounded hover:bg-surface-700 text-surface-400" title="Reset zoom">
              <span class="material-symbols-rounded text-sm">fit_screen</span>
            </button>
          </div>

          <!-- Pin mode toggle -->
          <button v-if="!readonly"
                  @click="placingPin = !placingPin; pendingPin = null"
                  :class="placingPin ? 'bg-primary-600 text-white' : 'bg-surface-700 text-surface-300 hover:bg-surface-600'"
                  class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors">
            <span class="material-symbols-rounded text-sm">push_pin</span>
            {{ placingPin ? 'Click to place pin...' : 'Add Pin' }}
          </button>

          <!-- View Together (internal only) -->
          <button v-if="viewTogether"
                  @click="viewTogether.isActive.value ? viewTogether.endSession() : viewTogether.startSession()"
                  :class="viewTogether.isActive.value ? 'bg-amber-600 text-white' : 'bg-surface-700 text-surface-300 hover:bg-surface-600'"
                  class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors">
            <span class="material-symbols-rounded text-sm">visibility</span>
            {{ viewTogether.isActive.value ? 'Stop Viewing Together' : 'View Together' }}
          </button>

          <!-- PDF pagination -->
          <div v-if="isPdf && pageCount > 1" class="flex items-center gap-1">
            <button @click="goToPage(currentPage - 1)" :disabled="currentPage <= 1"
                    class="p-1 rounded hover:bg-surface-700 text-surface-400 disabled:opacity-30">
              <span class="material-symbols-rounded text-sm">chevron_left</span>
            </button>
            <input type="number" :value="currentPage" @change="goToPage(+$event.target.value)" min="1" :max="pageCount"
                   class="w-12 text-center text-xs bg-surface-800 border border-surface-600 rounded px-1 py-0.5 text-white" />
            <button @click="goToPage(currentPage + 1)" :disabled="currentPage >= pageCount"
                    class="p-1 rounded hover:bg-surface-700 text-surface-400 disabled:opacity-30">
              <span class="material-symbols-rounded text-sm">chevron_right</span>
            </button>
          </div>
        </div>

        <!-- Document content area -->
        <div class="flex-1 overflow-auto flex items-center justify-center p-6"
             @wheel="handleWheel"
             @mousedown="startPan" @mousemove="updatePan" @mouseup="endPan" @mouseleave="endPan"
             :class="{ 'cursor-crosshair': placingPin, 'cursor-grab': !placingPin && zoom > 1, 'cursor-grabbing': isPanning }">

          <div ref="contentRef" class="relative"
               :style="{ transform: `scale(${zoom}) translate(${panX / zoom}px, ${panY / zoom}px)`, transformOrigin: 'center center', transition: isPanning ? 'none' : 'transform 0.15s ease' }">

            <!-- PDF Page -->
            <div v-if="isPdf" class="relative">
              <div v-if="pdfLoading" class="flex items-center justify-center h-96">
                <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
              </div>
              <div v-else-if="pdfError" class="flex items-center justify-center h-96 text-red-400 text-sm">
                {{ pdfError }}
              </div>
              <div v-else class="relative">
                <canvas :ref="setCanvasRef(currentPage)" class="block rounded shadow-xl"></canvas>
                <!-- Pin overlay for this page -->
                <div class="absolute inset-0" @click="handleContentClick">
                  <div v-for="ann in filteredAnnotations" :key="ann.id"
                       class="annotation-pin absolute -translate-x-1/2 -translate-y-full z-10 group"
                       :style="pinStyle(ann)"
                       @click.stop="selectedAnnotationId = ann.id">
                    <div :class="[pinColor(ann), selectedAnnotationId === ann.id ? 'ring-2 ring-white scale-125' : 'hover:scale-110']"
                         class="w-6 h-6 rounded-full flex items-center justify-center shadow-lg transition-transform cursor-pointer">
                      <span class="material-symbols-rounded text-white text-xs">push_pin</span>
                    </div>
                    <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-0.5 bg-surface-900 text-white text-[10px] rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                      {{ ann.created_by_name || ann.created_by_email }} - {{ ann.comments?.length || 0 }} comment(s)
                    </div>
                  </div>

                  <!-- Pending pin -->
                  <div v-if="pendingPin && pendingPin.page_number === currentPage"
                       class="annotation-pin absolute -translate-x-1/2 -translate-y-full z-20"
                       :style="{ left: pendingPin.x_percent + '%', top: pendingPin.y_percent + '%' }">
                    <div class="w-6 h-6 rounded-full bg-red-500 ring-2 ring-white flex items-center justify-center shadow-lg animate-pulse">
                      <span class="material-symbols-rounded text-white text-xs">add</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Image -->
            <div v-else-if="isImage" class="relative">
              <img :src="documentUrl" :alt="documentName" @load="onImageLoad"
                   class="max-w-full rounded shadow-xl select-none"
                   :style="{ maxHeight: '80vh' }" draggable="false" />
              <div v-if="imageLoaded" class="absolute inset-0" @click="handleContentClick">
                <div v-for="ann in filteredAnnotations" :key="ann.id"
                     class="annotation-pin absolute -translate-x-1/2 -translate-y-full z-10 group"
                     :style="pinStyle(ann)"
                     @click.stop="selectedAnnotationId = ann.id">
                  <div :class="[pinColor(ann), selectedAnnotationId === ann.id ? 'ring-2 ring-white scale-125' : 'hover:scale-110']"
                       class="w-6 h-6 rounded-full flex items-center justify-center shadow-lg transition-transform cursor-pointer">
                    <span class="material-symbols-rounded text-white text-xs">push_pin</span>
                  </div>
                  <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-0.5 bg-surface-900 text-white text-[10px] rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                    {{ ann.created_by_name || ann.created_by_email }} - {{ ann.comments?.length || 0 }} comment(s)
                  </div>
                </div>

                <div v-if="pendingPin"
                     class="annotation-pin absolute -translate-x-1/2 -translate-y-full z-20"
                     :style="{ left: pendingPin.x_percent + '%', top: pendingPin.y_percent + '%' }">
                  <div class="w-6 h-6 rounded-full bg-red-500 ring-2 ring-white flex items-center justify-center shadow-lg animate-pulse">
                    <span class="material-symbols-rounded text-white text-xs">add</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Unsupported type -->
            <div v-else class="flex flex-col items-center justify-center h-96 text-surface-400">
              <span class="material-symbols-rounded text-5xl mb-3">description</span>
              <p class="text-sm">Preview not available for this file type</p>
              <a :href="documentUrl" target="_blank" class="mt-3 text-sm text-primary-400 hover:underline flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">download</span>
                Download
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Annotation panel -->
      <div class="annotation-thread-panel w-80 bg-surface-900 border-l border-surface-700 flex flex-col shrink-0">

        <!-- New annotation form (when pending pin exists) -->
        <div v-if="pendingPin" class="flex flex-col h-full">
          <div class="px-4 py-3 border-b border-surface-700 flex items-center gap-2">
            <span class="material-symbols-rounded text-red-400 text-lg">push_pin</span>
            <span class="text-sm font-medium text-white">New Annotation</span>
            <button @click="pendingPin = null" class="ml-auto p-1 rounded hover:bg-surface-700 text-surface-400">
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
          <div class="flex-1 p-4 flex flex-col gap-3">
            <p class="text-xs text-surface-400">
              Page {{ pendingPin.page_number }} &mdash;
              Position: {{ pendingPin.x_percent.toFixed(1) }}%, {{ pendingPin.y_percent.toFixed(1) }}%
            </p>
            <textarea v-model="commentText" rows="4" placeholder="Write your comment..."
                      class="w-full px-3 py-2 bg-surface-800 border border-surface-600 rounded-lg text-sm text-white placeholder-surface-500 resize-none focus:ring-1 focus:ring-primary-500 outline-none"></textarea>

            <!-- Attachment area -->
            <div>
              <label class="flex items-center gap-1.5 text-xs text-surface-400 cursor-pointer hover:text-surface-200">
                <span class="material-symbols-rounded text-sm">attach_file</span>
                Attach image/screenshot
                <input type="file" accept="image/*" multiple @change="handleAttachmentSelect" class="hidden" />
              </label>
              <div v-if="attachmentFiles.length" class="mt-2 flex flex-wrap gap-2">
                <div v-for="(file, idx) in attachmentFiles" :key="idx"
                     class="flex items-center gap-1 bg-surface-800 rounded px-2 py-1 text-xs text-surface-300">
                  <span class="truncate max-w-[120px]">{{ file.name }}</span>
                  <button @click="removeAttachment(idx)" class="text-surface-500 hover:text-red-400">
                    <span class="material-symbols-rounded text-xs">close</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div class="px-4 py-3 border-t border-surface-700">
            <button @click="submitNewAnnotation" :disabled="!commentText.trim() || submittingComment"
                    class="w-full px-4 py-2 rounded-full bg-primary-600 hover:bg-primary-500 text-white text-sm font-medium disabled:opacity-50 transition-colors">
              {{ submittingComment ? 'Saving...' : 'Add Annotation' }}
            </button>
          </div>
        </div>

        <!-- Selected annotation thread -->
        <div v-else-if="selectedAnnotation" class="flex flex-col h-full">
          <div class="px-4 py-3 border-b border-surface-700 flex items-center gap-2">
            <div :class="pinColor(selectedAnnotation)" class="w-5 h-5 rounded-full flex items-center justify-center">
              <span class="material-symbols-rounded text-white text-xs">push_pin</span>
            </div>
            <span class="text-sm font-medium text-white flex-1 truncate">
              {{ selectedAnnotation.created_by_name || selectedAnnotation.created_by_email }}
            </span>
            <div class="flex items-center gap-1">
              <button v-if="!readonly && selectedAnnotation.status === 'open'"
                      @click="emit('resolve-annotation', selectedAnnotation.id)"
                      class="p-1 rounded hover:bg-surface-700 text-green-400" title="Mark resolved">
                <span class="material-symbols-rounded text-sm">check_circle</span>
              </button>
              <button v-if="!readonly"
                      @click="emit('delete-annotation', selectedAnnotation.id); selectedAnnotationId = null"
                      class="p-1 rounded hover:bg-surface-700 text-red-400" title="Delete">
                <span class="material-symbols-rounded text-sm">delete</span>
              </button>
              <button @click="selectedAnnotationId = null" class="p-1 rounded hover:bg-surface-700 text-surface-400">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </div>
          </div>

          <!-- Comment list -->
          <div class="flex-1 overflow-y-auto p-4 space-y-3">
            <div v-for="comment in (selectedAnnotation.comments || [])" :key="comment.id"
                 class="bg-surface-800 rounded-xl p-3">
              <div class="flex items-center gap-2 mb-1">
                <div class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
                     :class="comment.author_type === 'portal' ? 'bg-purple-500' : 'bg-primary-500'">
                  {{ (comment.author_name || comment.author_email || '?')[0].toUpperCase() }}
                </div>
                <span class="text-xs font-medium text-surface-200 truncate">
                  {{ comment.author_name || comment.author_email }}
                </span>
                <span class="text-[10px] text-surface-500 ml-auto shrink-0">{{ formatTime(comment.created_at) }}</span>
              </div>
              <p class="text-sm text-surface-300 whitespace-pre-wrap">{{ comment.content }}</p>

              <!-- Attachments -->
              <div v-if="comment.attachments?.length" class="mt-2 flex flex-wrap gap-1.5">
                <div v-for="att in comment.attachments" :key="att.id"
                     class="flex items-center gap-1 bg-surface-700 rounded px-2 py-0.5 text-[10px] text-surface-300 cursor-pointer hover:bg-surface-600">
                  <span class="material-symbols-rounded text-xs">image</span>
                  <span class="truncate max-w-[100px]">{{ att.original_name }}</span>
                </div>
              </div>

              <button v-if="!readonly && comment.author_email === currentUserEmail"
                      @click="emit('delete-comment', { annotation_id: selectedAnnotation.id, comment_id: comment.id })"
                      class="mt-1 text-[10px] text-surface-500 hover:text-red-400 flex items-center gap-0.5">
                <span class="material-symbols-rounded text-xs">delete</span> Delete
              </button>
            </div>
          </div>

          <!-- Reply form -->
          <div v-if="!readonly" class="px-4 py-3 border-t border-surface-700">
            <div class="flex gap-2">
              <textarea v-model="commentText" rows="2" placeholder="Reply..."
                        class="flex-1 px-3 py-2 bg-surface-800 border border-surface-600 rounded-lg text-sm text-white placeholder-surface-500 resize-none focus:ring-1 focus:ring-primary-500 outline-none"></textarea>
              <div class="flex flex-col gap-1">
                <label class="p-1.5 rounded-lg bg-surface-800 border border-surface-600 hover:bg-surface-700 cursor-pointer text-surface-400">
                  <span class="material-symbols-rounded text-sm">attach_file</span>
                  <input type="file" accept="image/*" multiple @change="handleAttachmentSelect" class="hidden" />
                </label>
                <button @click="submitReply" :disabled="!commentText.trim()"
                        class="p-1.5 rounded-lg bg-primary-600 hover:bg-primary-500 text-white disabled:opacity-50">
                  <span class="material-symbols-rounded text-sm">send</span>
                </button>
              </div>
            </div>
            <div v-if="attachmentFiles.length" class="mt-2 flex flex-wrap gap-1.5">
              <div v-for="(file, idx) in attachmentFiles" :key="idx"
                   class="flex items-center gap-1 bg-surface-800 rounded px-2 py-0.5 text-[10px] text-surface-300">
                <span class="truncate max-w-[80px]">{{ file.name }}</span>
                <button @click="removeAttachment(idx)" class="text-surface-500 hover:text-red-400">
                  <span class="material-symbols-rounded text-[10px]">close</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Default: annotation list overview -->
        <div v-else class="flex flex-col h-full">
          <div class="px-4 py-3 border-b border-surface-700">
            <span class="text-sm font-medium text-white flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-primary-400">comment</span>
              Annotations ({{ totalAnnotations }})
            </span>
          </div>
          <div class="flex-1 overflow-y-auto">
            <div v-if="!props.annotations.length" class="p-6 text-center text-surface-500 text-sm">
              <span class="material-symbols-rounded text-3xl block mb-2">chat_add_on</span>
              <p>No annotations yet.</p>
              <p v-if="!readonly" class="text-xs mt-1">Click "Add Pin" to start annotating.</p>
            </div>
            <button v-for="ann in props.annotations" :key="ann.id"
                    @click="selectedAnnotationId = ann.id; currentPage = ann.page_number"
                    class="w-full text-left px-4 py-3 hover:bg-surface-800 border-b border-surface-800 transition-colors">
              <div class="flex items-center gap-2">
                <div :class="pinColor(ann)" class="w-4 h-4 rounded-full shrink-0 flex items-center justify-center">
                  <span class="material-symbols-rounded text-white text-[10px]">push_pin</span>
                </div>
                <span class="text-xs font-medium text-surface-200 truncate">
                  {{ ann.created_by_name || ann.created_by_email }}
                </span>
                <span class="text-[10px] text-surface-500 ml-auto">
                  {{ ann.comment_count || ann.comments?.length || 0 }}
                  <span class="material-symbols-rounded text-[10px] align-middle">chat_bubble</span>
                </span>
              </div>
              <p v-if="ann.comments?.[0]?.content" class="text-xs text-surface-400 mt-1 line-clamp-2">
                {{ ann.comments[0].content }}
              </p>
              <div class="flex items-center gap-2 mt-1">
                <span class="text-[10px] text-surface-500">Page {{ ann.page_number }}</span>
                <span :class="ann.status === 'resolved' ? 'text-green-500' : 'text-amber-500'" class="text-[10px]">
                  {{ ann.status }}
                </span>
              </div>
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
