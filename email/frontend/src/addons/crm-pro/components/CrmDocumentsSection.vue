<script setup>
/**
 * CrmDocumentsSection - Document management from the CRM side
 * Allows internal users to upload documents, assign signers, track signing progress,
 * and view audit trails.
 */
import { ref, watch, computed, onBeforeUnmount } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import PdfZoneEditor from '@/components/portal/PdfZoneEditor.vue'
import DocumentAnnotationViewer from '@/components/portal/DocumentAnnotationViewer.vue'

const props = defineProps({
  clientId: { type: Number, required: true },
  contacts: { type: Array, default: () => [] },
  portalAccess: { type: Array, default: () => [] },
  linkedBoards: { type: Array, default: () => [] }
})

const toast = useToastStore()

const hasBoard = computed(() => props.linkedBoards.length > 0)
const boardWarningDismissed = ref(false)

// Document list
const documents = ref([])
const loading = ref(false)
const showUploadModal = ref(false)
const showAuditModal = ref(false)
const selectedDocAudit = ref(null)
const auditTrail = ref([])
const auditLoading = ref(false)

// Zone editor state
const showZoneEditor = ref(false)
const zoneEditorDocId = ref(null)
const zoneEditorPdfUrl = ref('')
const zoneEditorSigners = ref([])
const zoneEditorExistingZones = ref([])
const zoneSaving = ref(false)

// Annotation viewer state
const showAnnotationViewer = ref(false)
const annotationDoc = ref(null)
const annotationDocUrl = ref('')
const annotationList = ref([])
const annotationLoading = ref(false)

// Upload form
const uploadForm = ref({
  title: '',
  description: '',
  document_type: 'contract',
  signing_method: 'both',
  signing_deadline: '',
  amount: '',
  currency: 'HUF',
  reference_number: '',
  file: null,
  signers: []
})
const uploading = ref(false)

const documentTypes = [
  { value: 'contract', label: 'Contract' },
  { value: 'invoice', label: 'Invoice' },
  { value: 'proposal', label: 'Proposal' },
  { value: 'quote', label: 'Quote' },
  { value: 'nda', label: 'NDA' },
  { value: 'agreement', label: 'Agreement' },
  { value: 'receipt', label: 'Receipt' },
  { value: 'other', label: 'Other' }
]

watch(() => props.clientId, () => fetchDocuments(), { immediate: true })

async function fetchDocuments() {
  loading.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/portal/documents`)
    if (res.data?.success) {
      documents.value = res.data.data?.documents || []
    }
  } catch (e) {
    documents.value = []
  } finally {
    loading.value = false
  }
}

function resetUploadForm() {
  uploadForm.value = {
    title: '', description: '', document_type: 'contract', signing_method: 'both',
    signing_deadline: '', amount: '', currency: 'HUF', reference_number: '',
    file: null, signers: []
  }
}

function handleFile(e) {
  const files = e.target.files
  if (files?.length) {
    uploadForm.value.file = files[0]
    if (!uploadForm.value.title) {
      uploadForm.value.title = files[0].name.replace(/\.[^/.]+$/, '')
    }
  }
}

function addSigner() {
  uploadForm.value.signers.push({ email: '', name: '', sign_order: uploadForm.value.signers.length })
}

function removeSigner(idx) {
  uploadForm.value.signers.splice(idx, 1)
}

function addSignerFromAccess(access) {
  // Check if already added
  if (uploadForm.value.signers.some(s => s.email === access.email)) return
  uploadForm.value.signers.push({ email: access.email, name: access.name || '', sign_order: uploadForm.value.signers.length })
}

async function submitDocument() {
  if (!uploadForm.value.file) {
    toast.error('Please select a file')
    return
  }
  uploading.value = true
  try {
    const formData = new FormData()
    formData.append('file', uploadForm.value.file)
    formData.append('title', uploadForm.value.title)
    formData.append('description', uploadForm.value.description)
    formData.append('document_type', uploadForm.value.document_type)
    formData.append('signing_method', uploadForm.value.signing_method)
    if (uploadForm.value.signing_deadline) formData.append('signing_deadline', uploadForm.value.signing_deadline)
    if (uploadForm.value.amount) formData.append('amount', uploadForm.value.amount)
    formData.append('currency', uploadForm.value.currency)
    if (uploadForm.value.reference_number) formData.append('reference_number', uploadForm.value.reference_number)
    if (uploadForm.value.signers.length > 0) {
      formData.append('signers', JSON.stringify(uploadForm.value.signers.filter(s => s.email)))
    }

    const res = await api.post(`/clients/${props.clientId}/portal/documents`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    if (res.data?.success) {
      const doc = res.data.data
      const isPdf = uploadForm.value.file?.type === 'application/pdf' || uploadForm.value.file?.name?.endsWith('.pdf')
      showUploadModal.value = false

      if (isPdf && doc?.id) {
        openZoneEditor(doc.id, uploadForm.value.signers.filter(s => s.email))
      } else {
        toast.success('Document uploaded')
      }

      resetUploadForm()
      await fetchDocuments()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to upload document')
  } finally {
    uploading.value = false
  }
}

async function sendForSigning(doc) {
  try {
    await api.post(`/clients/${props.clientId}/portal/documents/${doc.id}/send`)
    toast.success('Document sent for signing')
    await fetchDocuments()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to send document')
  }
}

async function sendReminder(doc) {
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/documents/${doc.id}/remind`)
    toast.success(`Reminder sent to ${res.data?.data?.reminded || 0} signer(s)`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to send reminder')
  }
}

async function viewAudit(doc) {
  selectedDocAudit.value = doc
  showAuditModal.value = true
  auditLoading.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/portal/documents/${doc.id}/audit`)
    auditTrail.value = res.data?.data?.audit || []
  } catch (e) {
    auditTrail.value = []
  } finally {
    auditLoading.value = false
  }
}

async function openZoneEditor(docId, signers) {
  zoneEditorDocId.value = docId
  zoneEditorSigners.value = signers || []
  zoneEditorExistingZones.value = []

  // Revoke any previous blob URL to prevent memory leaks
  if (zoneEditorPdfUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(zoneEditorPdfUrl.value)
  }
  zoneEditorPdfUrl.value = ''

  try {
    const blobRes = await api.get(
      `/clients/${props.clientId}/portal/documents/${docId}/download-internal`,
      { responseType: 'blob' }
    )
    zoneEditorPdfUrl.value = URL.createObjectURL(blobRes.data)
  } catch (e) {
    toast.error('Failed to load PDF for zone editing')
    return
  }

  try {
    const res = await api.get(`/clients/${props.clientId}/portal/documents/${docId}/zones`)
    if (res.data?.success) {
      zoneEditorExistingZones.value = res.data.data?.zones || []
    }
  } catch (e) { /* no existing zones */ }

  if (zoneEditorSigners.value.length === 0) {
    try {
      const res = await api.get(`/clients/${props.clientId}/portal/documents`)
      const doc = (res.data?.data?.documents || []).find(d => d.id === docId)
      if (doc?.signers) {
        zoneEditorSigners.value = doc.signers.map(s => ({ email: s.signer_email, name: s.signer_name }))
      }
    } catch (e) { /* ignore */ }
  }

  showZoneEditor.value = true
}

function closeZoneEditor() {
  showZoneEditor.value = false
  if (zoneEditorPdfUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(zoneEditorPdfUrl.value)
    zoneEditorPdfUrl.value = ''
  }
}

async function saveZones(zones) {
  if (!zoneEditorDocId.value) return
  zoneSaving.value = true
  try {
    await api.post(`/clients/${props.clientId}/portal/documents/${zoneEditorDocId.value}/zones`, { zones })
    toast.success('Signature zones saved')
    closeZoneEditor()
    await fetchDocuments()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save zones')
  } finally {
    zoneSaving.value = false
  }
}

// ---- Annotation viewer ----

async function openAnnotationViewer(doc) {
  annotationDoc.value = doc
  annotationLoading.value = true

  if (annotationDocUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(annotationDocUrl.value)
  }
  annotationDocUrl.value = ''

  try {
    const blobRes = await api.get(
      `/clients/${props.clientId}/portal/documents/${doc.id}/download-internal`,
      { responseType: 'blob' }
    )
    annotationDocUrl.value = URL.createObjectURL(blobRes.data)
  } catch {
    toast.error('Failed to load document')
    annotationLoading.value = false
    return
  }

  await loadAnnotations(doc.id)
  annotationLoading.value = false
  showAnnotationViewer.value = true
}

async function loadAnnotations(docId) {
  try {
    const res = await api.get(`/clients/${props.clientId}/portal/documents/${docId}/annotations`)
    annotationList.value = res.data?.data?.annotations || []
  } catch {
    annotationList.value = []
  }
}

function closeAnnotationViewer() {
  showAnnotationViewer.value = false
  if (annotationDocUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(annotationDocUrl.value)
    annotationDocUrl.value = ''
  }
  annotationDoc.value = null
}

async function handleCreateAnnotation(payload) {
  if (!annotationDoc.value) return
  const docId = annotationDoc.value.id

  const formData = new FormData()
  formData.append('page_number', payload.page_number)
  formData.append('x_percent', payload.x_percent)
  formData.append('y_percent', payload.y_percent)
  formData.append('content', payload.content)
  if (payload.attachments?.length) {
    payload.attachments.forEach(f => formData.append('attachment[]', f))
  }

  try {
    await api.post(`/clients/${props.clientId}/portal/documents/${docId}/annotations`, formData)
    await loadAnnotations(docId)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to add annotation')
  }
}

async function handleAddComment(payload) {
  if (!annotationDoc.value) return
  const docId = annotationDoc.value.id

  const formData = new FormData()
  formData.append('content', payload.content)
  if (payload.attachments?.length) {
    payload.attachments.forEach(f => formData.append('attachment[]', f))
  }

  try {
    await api.post(`/clients/${props.clientId}/portal/documents/${docId}/annotations/${payload.annotation_id}/comments`, formData)
    await loadAnnotations(docId)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to add comment')
  }
}

async function handleResolveAnnotation(annotationId) {
  if (!annotationDoc.value) return
  const docId = annotationDoc.value.id
  try {
    await api.put(`/clients/${props.clientId}/portal/documents/${docId}/annotations/${annotationId}`, { status: 'resolved' })
    await loadAnnotations(docId)
  } catch (e) {
    toast.error('Failed to resolve annotation')
  }
}

async function handleDeleteAnnotation(annotationId) {
  if (!annotationDoc.value) return
  const docId = annotationDoc.value.id
  try {
    await api.delete(`/clients/${props.clientId}/portal/documents/${docId}/annotations/${annotationId}`)
    await loadAnnotations(docId)
  } catch (e) {
    toast.error('Failed to delete annotation')
  }
}

async function handleDeleteComment({ annotation_id, comment_id }) {
  if (!annotationDoc.value) return
  const docId = annotationDoc.value.id
  try {
    await api.delete(`/clients/${props.clientId}/portal/documents/${docId}/annotations/${annotation_id}/comments/${comment_id}`)
    await loadAnnotations(docId)
  } catch (e) {
    toast.error('Failed to delete comment')
  }
}

onBeforeUnmount(() => {
  if (annotationDocUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(annotationDocUrl.value)
  }
  if (zoneEditorPdfUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(zoneEditorPdfUrl.value)
  }
})

function statusColor(status) {
  const map = {
    draft: 'text-surface-500', sent: 'text-blue-500', viewed: 'text-indigo-500',
    signing: 'text-amber-500', signed: 'text-green-500', rejected: 'text-red-500',
    expired: 'text-surface-400', archived: 'text-surface-400'
  }
  return map[status] || 'text-surface-500'
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <!-- No Board Warning -->
    <div v-if="!hasBoard && !boardWarningDismissed" class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 dark:border-amber-700/50 bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
      <span class="material-symbols-rounded text-amber-500 mt-0.5">warning</span>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-amber-800 dark:text-amber-300">No Board Linked</p>
        <p class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
          This client has no linked board. Documents will be stored locally instead of in Drive.
          Link a board to this client to enable organized Drive storage with automatic folder structure.
        </p>
      </div>
      <button @click="boardWarningDismissed = true" class="shrink-0 p-0.5 rounded hover:bg-amber-100 dark:hover:bg-amber-800/40">
        <span class="material-symbols-rounded text-sm text-amber-500">close</span>
      </button>
    </div>

    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">description</span>
        Portal Documents
        <span v-if="documents.length" class="text-xs font-normal text-surface-400">({{ documents.length }})</span>
      </h3>
      <button @click="showUploadModal = true; resetUploadForm()"
              class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium 
                     bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 
                     hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors">
        <span class="material-symbols-rounded text-sm">upload_file</span>
        Upload Document
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-4">
      <span class="material-symbols-rounded animate-spin text-surface-400">sync</span>
    </div>

    <!-- Document List -->
    <div v-else-if="documents.length > 0" class="space-y-2">
      <div v-for="doc in documents" :key="doc.id"
           class="flex items-center gap-3 p-3 rounded-lg bg-surface-50 dark:bg-surface-800/50 border border-surface-100 dark:border-surface-700/50">
        <div class="w-9 h-9 rounded-lg bg-surface-200 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-lg" :class="statusColor(doc.status)">description</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate">{{ doc.title }}</p>
          <div class="flex items-center gap-2 text-xs text-surface-400">
            <span :class="statusColor(doc.status)">{{ doc.status }}</span>
            <span v-if="doc.signer_count">· {{ doc.signed_count }}/{{ doc.signer_count }} signed</span>
            <span>{{ formatDate(doc.created_at) }}</span>
          </div>
        </div>
        <div class="flex items-center gap-1 flex-shrink-0">
          <button @click="openAnnotationViewer(doc)"
                  class="p-1.5 rounded-lg hover:bg-indigo-100 dark:hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400"
                  title="View & Annotate">
            <span class="material-symbols-rounded text-lg">chat_add_on</span>
          </button>
          <button v-if="doc.status === 'draft' && doc.mime_type === 'application/pdf'"
                  @click="openZoneEditor(doc.id)"
                  class="p-1.5 rounded-lg hover:bg-purple-100 dark:hover:bg-purple-500/20 text-purple-600 dark:text-purple-400"
                  title="Configure signature zones">
            <span class="material-symbols-rounded text-lg">edit_square</span>
          </button>
          <button v-if="['draft', 'sent', 'viewed'].includes(doc.status)" @click="sendForSigning(doc)"
                  class="p-1.5 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-500/20 text-blue-600 dark:text-blue-400"
                  :title="doc.status === 'draft' ? 'Send for signing' : 'Re-send for signing'">
            <span class="material-symbols-rounded text-lg">send</span>
          </button>
          <button v-if="doc.pending_count > 0 && doc.status !== 'draft'" @click="sendReminder(doc)"
                  class="p-1.5 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-500/20 text-amber-600 dark:text-amber-400"
                  title="Send reminder">
            <span class="material-symbols-rounded text-lg">notification_important</span>
          </button>
          <a v-if="doc.signed_file_path && doc.status === 'signed'"
             :href="`/api/clients/${props.clientId}/portal/documents/${doc.id}/signed-pdf`"
             target="_blank"
             class="p-1.5 rounded-lg hover:bg-green-100 dark:hover:bg-green-500/20 text-green-600 dark:text-green-400"
             title="Download signed PDF">
            <span class="material-symbols-rounded text-lg">verified</span>
          </a>
          <button @click="viewAudit(doc)"
                  class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500"
                  title="View audit trail">
            <span class="material-symbols-rounded text-lg">history</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Empty -->
    <div v-else class="text-center py-6">
      <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">folder_off</span>
      <p class="text-sm text-surface-500 mt-2">No documents uploaded yet</p>
    </div>

    <!-- Upload Document Modal -->
    <Teleport to="body">
      <div v-if="showUploadModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showUploadModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">upload_file</span>
            Upload Document
          </h3>

          <div class="space-y-3">
            <!-- File -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">File *</label>
              <label class="flex items-center gap-2 px-4 py-3 rounded-xl border-2 border-dashed border-surface-300 dark:border-surface-600 
                            hover:border-primary-400 dark:hover:border-primary-500 cursor-pointer transition-colors">
                <span class="material-symbols-rounded text-surface-400">upload_file</span>
                <span class="text-sm text-surface-600 dark:text-surface-300">
                  {{ uploadForm.file ? uploadForm.file.name : 'Choose a file...' }}
                </span>
                <input type="file" @change="handleFile" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" />
              </label>
            </div>

            <!-- Title & Type -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Title</label>
                <input v-model="uploadForm.title" type="text" placeholder="Document title"
                       class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white focus:ring-2 focus:ring-primary-500 outline-none" />
              </div>
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Type</label>
                <select v-model="uploadForm.document_type" 
                        class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white">
                  <option v-for="dt in documentTypes" :key="dt.value" :value="dt.value">{{ dt.label }}</option>
                </select>
              </div>
            </div>

            <!-- Description -->
            <div>
              <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Description</label>
              <textarea v-model="uploadForm.description" rows="2" placeholder="Optional description..."
                        class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white focus:ring-2 focus:ring-primary-500 outline-none resize-none"></textarea>
            </div>

            <!-- Signing method & deadline -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Signing Method</label>
                <select v-model="uploadForm.signing_method"
                        class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white">
                  <option value="both">Upload or Pad</option>
                  <option value="upload">Upload Only</option>
                  <option value="pad">Signature Pad Only</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Deadline</label>
                <input v-model="uploadForm.signing_deadline" type="date"
                       class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white" />
              </div>
            </div>

            <!-- Amount -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Amount</label>
                <input v-model="uploadForm.amount" type="number" step="0.01" placeholder="0.00"
                       class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white" />
              </div>
              <div>
                <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Currency</label>
                <select v-model="uploadForm.currency"
                        class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white">
                  <option value="HUF">HUF</option>
                  <option value="EUR">EUR</option>
                  <option value="USD">USD</option>
                  <option value="GBP">GBP</option>
                </select>
              </div>
            </div>

            <!-- Signers -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="text-xs font-medium text-surface-600 dark:text-surface-300">Signers</label>
                <button @click="addSigner" class="text-xs text-primary-600 dark:text-primary-400 hover:underline">+ Add signer</button>
              </div>
              <!-- Quick add from portal access -->
              <div v-if="portalAccess.length > 0" class="flex flex-wrap gap-1 mb-2">
                <button v-for="pa in portalAccess.filter(a => a.is_active)" :key="pa.id"
                        @click="addSignerFromAccess(pa)"
                        class="text-xs px-2 py-1 rounded-md bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600">
                  + {{ pa.name || pa.email }}
                </button>
              </div>
              <div v-for="(signer, idx) in uploadForm.signers" :key="idx" class="flex gap-2 mb-1">
                <input v-model="signer.email" type="email" placeholder="signer@email.com"
                       class="flex-1 px-3 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs text-surface-900 dark:text-white" />
                <input v-model="signer.name" type="text" placeholder="Name"
                       class="w-32 px-3 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs text-surface-900 dark:text-white" />
                <button @click="removeSigner(idx)" class="text-red-400 hover:text-red-600 p-1">
                  <span class="material-symbols-rounded text-sm">close</span>
                </button>
              </div>
            </div>
          </div>

          <div class="flex justify-end gap-3 mt-6">
            <button @click="showUploadModal = false" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700">Cancel</button>
            <button @click="submitDocument" :disabled="uploading || !uploadForm.file"
                    class="px-6 py-2 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50 transition-colors">
              {{ uploading ? 'Uploading...' : 'Upload Document' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Zone Editor Modal -->
    <Teleport to="body">
      <div v-if="showZoneEditor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-5xl h-[90vh] flex flex-col overflow-hidden">
          <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 dark:border-surface-700">
            <h3 class="text-base font-semibold text-surface-900 dark:text-white flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">edit_square</span>
              Configure Signature Zones
            </h3>
            <button @click="closeZoneEditor" class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700">
              <span class="material-symbols-rounded text-lg text-surface-500">close</span>
            </button>
          </div>
          <PdfZoneEditor
            :pdf-url="zoneEditorPdfUrl"
            :signers="zoneEditorSigners"
            :existing-zones="zoneEditorExistingZones"
            @save="saveZones"
            @cancel="closeZoneEditor"
            class="flex-1 min-h-0"
          />
        </div>
      </div>
    </Teleport>

    <!-- Audit Trail Modal -->
    <Teleport to="body">
      <div v-if="showAuditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showAuditModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6 max-h-[80vh] overflow-y-auto">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-4">
            Audit Trail: {{ selectedDocAudit?.title }}
          </h3>
          <div v-if="auditLoading" class="text-center py-4">
            <span class="material-symbols-rounded animate-spin text-surface-400">sync</span>
          </div>
          <div v-else-if="auditTrail.length > 0" class="space-y-3">
            <div v-for="entry in auditTrail" :key="entry.id" class="flex gap-3">
              <div class="w-2 h-2 rounded-full bg-primary-400 mt-2 flex-shrink-0"></div>
              <div>
                <p class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ entry.action }}</p>
                <p class="text-xs text-surface-400">
                  {{ entry.actor_email || entry.actor_type }} · {{ formatDate(entry.created_at) }}
                </p>
              </div>
            </div>
          </div>
          <div v-else class="text-center py-4 text-sm text-surface-400">No audit entries</div>
          <button @click="showAuditModal = false" class="mt-4 w-full py-2 text-sm text-surface-500 hover:text-surface-700">Close</button>
        </div>
      </div>
    </Teleport>

    <!-- Annotation Viewer -->
    <DocumentAnnotationViewer
      v-if="showAnnotationViewer && annotationDocUrl"
      :document-url="annotationDocUrl"
      :document-name="annotationDoc?.original_name || annotationDoc?.title || 'Document'"
      :mime-type="annotationDoc?.mime_type || ''"
      :annotations="annotationList"
      :current-user-email="''"
      :client-id="props.clientId"
      :doc-id="annotationDoc?.id || 0"
      mode="internal"
      @close="closeAnnotationViewer"
      @create-annotation="handleCreateAnnotation"
      @add-comment="handleAddComment"
      @resolve-annotation="handleResolveAnnotation"
      @delete-annotation="handleDeleteAnnotation"
      @delete-comment="handleDeleteComment"
    />
  </div>
</template>

