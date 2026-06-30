<script setup>
/**
 * PortalDocumentViewer - View and sign a single document
 * Supports both upload and signature pad signing methods.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import portalApi from '@/services/portalApi'
import PdfSignatureViewer from '@/components/portal/PdfSignatureViewer.vue'
import DocumentAnnotationViewer from '@/components/portal/DocumentAnnotationViewer.vue'

const route = useRoute()
const router = useRouter()
const portalToken = localStorage.getItem('portal_session_token') || ''
const { t, locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

const doc = ref(null)
const loading = ref(true)
const error = ref('')
const signMode = ref(null) // 'upload' | 'pad' | null
const signLoading = ref(false)

// Upload signing
const uploadFile = ref(null)
const uploadDragover = ref(false)

// Pad signing
const signatureCanvas = ref(null)
const isDrawing = ref(false)
const hasSignature = ref(false)

// Reject
const showRejectModal = ref(false)
const rejectReason = ref('')
const rejectLoading = ref(false)

// PDF zone signing
const zones = ref([])
const showPdfSigner = ref(false)

// Annotation viewer
const showAnnotations = ref(false)
const annotationDocUrl = ref('')
const portalAnnotations = ref([])

const docId = computed(() => route.params.docId)
const canSign = computed(() => doc.value?.my_signer_status === 'pending')
const signingMethod = computed(() => doc.value?.signing_method || 'both')
const isPdf = computed(() => doc.value?.mime_type === 'application/pdf' || doc.value?.original_name?.endsWith('.pdf'))
const hasZones = computed(() => zones.value.length > 0)
const usePdfViewer = computed(() => isPdf.value && hasZones.value && canSign.value)
const mySignerEmail = computed(() => {
  const portal = JSON.parse(localStorage.getItem('portal_user') || '{}')
  return portal?.email || ''
})
const pdfDownloadUrl = computed(() =>
  `/api/portal/documents/${docId.value}/download?portal_token=${encodeURIComponent(portalToken)}`
)
const displayError = computed(() => {
  const msg = error.value
  return (typeof msg === 'string' && msg.startsWith('portalDocumentViewer.')) ? t(msg) : msg
})

onMounted(async () => {
  await loadDocument()
  await loadZones()
})

async function loadDocument() {
  loading.value = true
  try {
    const res = await portalApi.get(`/portal/documents/${docId.value}`)
    if (res.data?.success) {
      doc.value = res.data.data
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'portalDocumentViewer.failedToLoadDocument'
  } finally {
    loading.value = false
  }
}

async function loadZones() {
  try {
    const res = await portalApi.get(`/portal/documents/${docId.value}/zones`)
    if (res.data?.success) {
      zones.value = res.data.data?.zones || []
    }
  } catch (e) {
    zones.value = []
  }
}

// Annotation methods
async function loadPortalAnnotations() {
  try {
    const res = await portalApi.get(`/portal/documents/${docId.value}/annotations`)
    portalAnnotations.value = res.data?.data?.annotations || []
  } catch { portalAnnotations.value = [] }
}

async function openAnnotationViewer() {
  annotationDocUrl.value = pdfDownloadUrl.value
  await loadPortalAnnotations()
  showAnnotations.value = true
}

function closeAnnotationViewer() {
  showAnnotations.value = false
}

async function handlePortalCreateAnnotation(payload) {
  const formData = new FormData()
  formData.append('page_number', payload.page_number)
  formData.append('x_percent', payload.x_percent)
  formData.append('y_percent', payload.y_percent)
  formData.append('content', payload.content)
  if (payload.attachments?.length) {
    payload.attachments.forEach(f => formData.append('attachment[]', f))
  }
  try {
    await portalApi.post(`/portal/documents/${docId.value}/annotations`, formData)
    await loadPortalAnnotations()
  } catch { /* handled in viewer */ }
}

async function handlePortalAddComment(payload) {
  const formData = new FormData()
  formData.append('content', payload.content)
  if (payload.attachments?.length) {
    payload.attachments.forEach(f => formData.append('attachment[]', f))
  }
  try {
    await portalApi.post(`/portal/documents/${docId.value}/annotations/${payload.annotation_id}/comments`, formData)
    await loadPortalAnnotations()
  } catch { /* handled in viewer */ }
}

async function handlePdfSign({ signature_data, stamp_data }) {
  signLoading.value = true
  try {
    await portalApi.post(`/portal/documents/${docId.value}/sign/pad`, {
      signature_data,
      stamp_data,
    })
    showPdfSigner.value = false
    await loadDocument()
  } catch (e) {
    error.value = e.response?.data?.message || 'portalDocumentViewer.failedToSubmitSignature'
  } finally {
    signLoading.value = false
  }
}

function handleFileDrop(e) {
  uploadDragover.value = false
  const files = e.dataTransfer?.files
  if (files?.length) uploadFile.value = files[0]
}

function handleFileSelect(e) {
  const files = e.target.files
  if (files?.length) uploadFile.value = files[0]
}

async function submitUploadSign() {
  if (!uploadFile.value || signLoading.value) return
  signLoading.value = true
  try {
    const formData = new FormData()
    formData.append('file', uploadFile.value)
    await portalApi.post(`/portal/documents/${docId.value}/sign/upload`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    await loadDocument()
    signMode.value = null
    uploadFile.value = null
  } catch (e) {
    error.value = e.response?.data?.message || 'portalDocumentViewer.failedToUploadSignedDocument'
  } finally {
    signLoading.value = false
  }
}

// Signature Pad
function initCanvas() {
  const canvas = signatureCanvas.value
  if (!canvas) return
  const ctx = canvas.getContext('2d')
  canvas.width = canvas.offsetWidth * 2
  canvas.height = canvas.offsetHeight * 2
  ctx.scale(2, 2)
  ctx.lineWidth = 2
  ctx.lineCap = 'round'
  ctx.strokeStyle = '#1a1a1a'
}

function startDraw(e) {
  isDrawing.value = true
  hasSignature.value = true
  const canvas = signatureCanvas.value
  const ctx = canvas.getContext('2d')
  const rect = canvas.getBoundingClientRect()
  const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
  const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
  ctx.beginPath()
  ctx.moveTo(x, y)
}

function draw(e) {
  if (!isDrawing.value) return
  e.preventDefault()
  const canvas = signatureCanvas.value
  const ctx = canvas.getContext('2d')
  const rect = canvas.getBoundingClientRect()
  const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
  const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
  ctx.lineTo(x, y)
  ctx.stroke()
}

function endDraw() {
  isDrawing.value = false
}

function clearSignature() {
  const canvas = signatureCanvas.value
  const ctx = canvas.getContext('2d')
  ctx.clearRect(0, 0, canvas.width, canvas.height)
  hasSignature.value = false
}

async function submitPadSign() {
  if (!hasSignature.value || signLoading.value) return
  signLoading.value = true
  try {
    const canvas = signatureCanvas.value
    const signatureData = canvas.toDataURL('image/png')
    await portalApi.post(`/portal/documents/${docId.value}/sign/pad`, { signature_data: signatureData })
    await loadDocument()
    signMode.value = null
  } catch (e) {
    error.value = e.response?.data?.message || 'portalDocumentViewer.failedToSubmitSignature'
  } finally {
    signLoading.value = false
  }
}

async function submitReject() {
  rejectLoading.value = true
  try {
    await portalApi.post(`/portal/documents/${docId.value}/reject`, { reason: rejectReason.value })
    await loadDocument()
    showRejectModal.value = false
  } catch (e) {
    error.value = e.response?.data?.message || 'portalDocumentViewer.failedToRejectDocument'
  } finally {
    rejectLoading.value = false
  }
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(localeTag.value, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function statusColor(status) {
  const c = { pending: 'text-amber-600', signed: 'text-green-600', rejected: 'text-red-600' }
  return c[status] || 'text-surface-500'
}
</script>

<template>
  <div>
    <button @click="router.push({ name: 'portal-documents' })" 
            class="flex items-center gap-1 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 mb-4">
      <span class="material-symbols-rounded text-lg">arrow_back</span>
      {{ $t('portalDocumentViewer.backToDocuments') }}
    </button>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error && !doc" class="text-center py-12">
      <span class="material-symbols-rounded text-4xl text-red-400">error</span>
      <p class="mt-2 text-surface-500">{{ displayError }}</p>
    </div>

    <!-- Document -->
    <div v-else-if="doc" class="space-y-6">
      <!-- Header Card -->
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-6">
        <div class="flex items-start justify-between mb-4">
          <div>
            <h2 class="text-xl font-bold text-surface-900 dark:text-white">{{ doc.title }}</h2>
            <p class="text-sm text-surface-500 mt-1">{{ doc.document_type }} · {{ $t('portalDocumentViewer.versionLabel', { version: doc.version }) }}</p>
          </div>
          <span :class="['text-sm font-medium px-3 py-1 rounded-full',
            doc.status === 'signed' ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300' :
            doc.status === 'rejected' ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' :
            'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300']">
            {{ doc.status }}
          </span>
        </div>
        <p v-if="doc.description" class="text-sm text-surface-600 dark:text-surface-300 mb-4">{{ doc.description }}</p>

        <!-- Download & Annotate -->
        <div class="flex items-center gap-3">
          <a :href="`/api/portal/documents/${doc.id}/download?portal_token=${encodeURIComponent(portalToken)}`" target="_blank"
             class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-surface-100 dark:bg-surface-700 
                    hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-200 text-sm font-medium transition-colors">
            <span class="material-symbols-rounded text-lg">download</span>
            {{ $t('portalDocumentViewer.downloadWithSize', { size: `${(doc.file_size / 1024).toFixed(0)} KB` }) }}
          </a>
          <button @click="openAnnotationViewer"
                  class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 
                         hover:bg-indigo-100 dark:hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 text-sm font-medium transition-colors">
            <span class="material-symbols-rounded text-lg">chat_add_on</span>
            {{ $t('portalDocumentViewer.viewAnnotate') }}
          </button>
        </div>
      </div>

      <!-- Signers -->
      <div v-if="doc.signers?.length > 0" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-6">
        <h3 class="font-semibold text-surface-900 dark:text-white mb-4">{{ $t('portalDocumentViewer.signers') }}</h3>
        <div class="space-y-3">
          <div v-for="signer in doc.signers" :key="signer.id" class="flex items-center gap-3">
            <span class="material-symbols-rounded text-lg" :class="statusColor(signer.status)">
              {{ signer.status === 'signed' ? 'check_circle' : signer.status === 'rejected' ? 'cancel' : 'schedule' }}
            </span>
            <div>
              <p class="text-sm font-medium text-surface-700 dark:text-surface-200">
                {{ signer.signer_name || signer.signer_email }}
              </p>
              <p class="text-xs text-surface-400">
                {{ signer.status === 'signed'
                  ? $t('portalDocumentViewer.signedAt', { date: formatDate(signer.signed_at) })
                  : signer.status === 'rejected'
                    ? $t('portalDocumentViewer.rejected')
                    : $t('portalDocumentViewer.pending')
                }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- PDF Zone Signing (full interactive view) -->
      <div v-if="usePdfViewer && !showPdfSigner" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-6">
        <h3 class="font-semibold text-surface-900 dark:text-white mb-4">{{ $t('portalDocumentViewer.signThisDocument') }}</h3>
        <div class="flex flex-wrap gap-3">
          <button @click="showPdfSigner = true"
                  class="flex items-center gap-2 px-5 py-3 rounded-xl border-2 border-dashed border-primary-300 dark:border-primary-500/40
                         hover:bg-primary-50 dark:hover:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-medium text-sm transition-colors">
            <span class="material-symbols-rounded">edit_document</span>
            {{ $t('portalDocumentViewer.signOnDocument') }}
          </button>
          <button @click="showRejectModal = true"
                  class="flex items-center gap-2 px-5 py-3 rounded-xl border border-red-200 dark:border-red-500/30
                         hover:bg-red-50 dark:hover:bg-red-500/10 text-red-600 dark:text-red-400 font-medium text-sm transition-colors">
            <span class="material-symbols-rounded">close</span>
            {{ $t('portalDocumentViewer.reject') }}
          </button>
        </div>
      </div>

      <!-- Sign Actions -- fallback for non-PDF or no zones -->
      <div v-if="canSign && !usePdfViewer" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-6">
        <h3 class="font-semibold text-surface-900 dark:text-white mb-4">{{ $t('portalDocumentViewer.signThisDocument') }}</h3>

        <!-- Method Selection -->
        <div v-if="!signMode" class="flex flex-wrap gap-3">
          <button v-if="signingMethod !== 'pad'"
                  @click="signMode = 'upload'"
                  class="flex items-center gap-2 px-5 py-3 rounded-xl border-2 border-dashed border-primary-300 dark:border-primary-500/40
                         hover:bg-primary-50 dark:hover:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-medium text-sm transition-colors">
            <span class="material-symbols-rounded">upload_file</span>
            {{ $t('portalDocumentViewer.uploadSignedCopy') }}
          </button>
          <button v-if="signingMethod !== 'upload'"
                  @click="signMode = 'pad'; $nextTick(initCanvas)"
                  class="flex items-center gap-2 px-5 py-3 rounded-xl border-2 border-dashed border-primary-300 dark:border-primary-500/40
                         hover:bg-primary-50 dark:hover:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-medium text-sm transition-colors">
            <span class="material-symbols-rounded">draw</span>
            {{ $t('portalDocumentViewer.signWithSignaturePad') }}
          </button>
          <button @click="showRejectModal = true"
                  class="flex items-center gap-2 px-5 py-3 rounded-xl border border-red-200 dark:border-red-500/30
                         hover:bg-red-50 dark:hover:bg-red-500/10 text-red-600 dark:text-red-400 font-medium text-sm transition-colors">
            <span class="material-symbols-rounded">close</span>
            {{ $t('portalDocumentViewer.reject') }}
          </button>
        </div>

        <!-- Upload Mode -->
        <div v-if="signMode === 'upload'" class="space-y-4">
          <div 
            @dragover.prevent="uploadDragover = true" 
            @dragleave="uploadDragover = false"
            @drop.prevent="handleFileDrop"
            :class="['border-2 border-dashed rounded-xl p-8 text-center transition-colors',
              uploadDragover ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-300 dark:border-surface-600']"
          >
            <span class="material-symbols-rounded text-3xl text-surface-400 mb-2">upload_file</span>
            <p class="text-sm text-surface-600 dark:text-surface-300 mb-2">
              {{ uploadFile ? uploadFile.name : $t('portalDocumentViewer.dragDropSignedDocument') }}
            </p>
            <label class="inline-block px-4 py-2 rounded-lg bg-primary-600 text-white text-sm font-medium cursor-pointer hover:bg-primary-700">
              {{ $t('portalDocumentViewer.browseFiles') }}
              <input type="file" @change="handleFileSelect" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" />
            </label>
          </div>
          <div class="flex gap-3">
            <button @click="submitUploadSign" :disabled="!uploadFile || signLoading"
                    class="px-6 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 text-white font-medium text-sm disabled:opacity-50 transition-colors">
              {{ signLoading ? $t('portalDocumentViewer.uploading') : $t('portalDocumentViewer.submitSignedDocument') }}
            </button>
            <button @click="signMode = null; uploadFile = null" class="px-4 py-2.5 rounded-xl text-surface-500 hover:text-surface-700 text-sm">
              {{ $t('portalDocumentViewer.cancel') }}
            </button>
          </div>
        </div>

        <!-- Pad Mode -->
        <div v-if="signMode === 'pad'" class="space-y-4">
          <p class="text-sm text-surface-500">{{ $t('portalDocumentViewer.drawYourSignatureBelow') }}</p>
          <canvas 
            ref="signatureCanvas"
            @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseleave="endDraw"
            @touchstart.prevent="startDraw" @touchmove.prevent="draw" @touchend="endDraw"
            class="w-full h-48 border-2 border-surface-300 dark:border-surface-600 rounded-xl bg-white dark:bg-surface-750 cursor-crosshair"
            style="touch-action: none;"
          ></canvas>
          <div class="flex gap-3">
            <button @click="submitPadSign" :disabled="!hasSignature || signLoading"
                    class="px-6 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 text-white font-medium text-sm disabled:opacity-50 transition-colors">
              {{ signLoading ? $t('portalDocumentViewer.submitting') : $t('portalDocumentViewer.submitSignature') }}
            </button>
            <button @click="clearSignature" class="px-4 py-2.5 rounded-xl text-surface-500 hover:text-surface-700 text-sm">
              {{ $t('portalDocumentViewer.clear') }}
            </button>
            <button @click="signMode = null" class="px-4 py-2.5 rounded-xl text-surface-500 hover:text-surface-700 text-sm">
              {{ $t('portalDocumentViewer.cancel') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Error display -->
      <p v-if="error" class="text-sm text-red-500">{{ displayError }}</p>
    </div>

    <!-- PDF Signature Viewer Modal -->
    <Teleport to="body">
      <div v-if="showPdfSigner" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-5xl h-[92vh] flex flex-col overflow-hidden">
          <PdfSignatureViewer
            :pdf-url="pdfDownloadUrl"
            :zones="zones"
            :my-signer-email="mySignerEmail"
            :signing-method="signingMethod"
            :loading="signLoading"
            @submit="handlePdfSign"
            @cancel="showPdfSigner = false"
            class="flex-1 min-h-0"
          />
        </div>
      </div>
    </Teleport>

    <!-- Reject Modal -->
    <Teleport to="body">
      <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showRejectModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-2">{{ $t('portalDocumentViewer.rejectDocument') }}</h3>
          <p class="text-sm text-surface-500 mb-4">{{ $t('portalDocumentViewer.pleaseProvideAReasonFor') }}</p>
          <textarea v-model="rejectReason" rows="3" :placeholder="$t('portalDocumentViewer.reason')"
                    class="w-full px-4 py-3 rounded-xl border border-surface-300 dark:border-surface-600 
                           bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                           focus:ring-2 focus:ring-red-500 outline-none resize-none"></textarea>
          <div class="flex justify-end gap-3 mt-4">
            <button @click="showRejectModal = false" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700">{{ $t('portalDocumentViewer.cancel') }}</button>
            <button @click="submitReject" :disabled="rejectLoading"
                    class="px-6 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-sm font-medium disabled:opacity-50">
              {{ rejectLoading ? $t('portalDocumentViewer.rejecting') : $t('portalDocumentViewer.rejectDocument') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Annotation Viewer -->
    <DocumentAnnotationViewer
      v-if="showAnnotations && annotationDocUrl"
      :document-url="annotationDocUrl"
      :document-name="doc?.original_name || doc?.title || 'Document'"
      :mime-type="doc?.mime_type || ''"
      :annotations="portalAnnotations"
      :current-user-email="mySignerEmail"
      mode="portal"
      @close="closeAnnotationViewer"
      @create-annotation="handlePortalCreateAnnotation"
      @add-comment="handlePortalAddComment"
    />
  </div>
</template>

