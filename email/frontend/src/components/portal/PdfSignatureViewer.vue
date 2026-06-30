<script setup>
/**
 * PdfSignatureViewer - Portal-side document viewer with interactive signing.
 * Renders the PDF, highlights zones assigned to the current signer,
 * and allows drawing OR uploading a signature plus uploading a company stamp.
 */
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePdfRenderer } from '@/composables/usePdfRenderer'

const { t } = useI18n()

const props = defineProps({
  pdfUrl: { type: String, required: true },
  zones: { type: Array, default: () => [] },
  mySignerEmail: { type: String, default: '' },
  signingMethod: { type: String, default: 'both' },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const { pageCount, loading: pdfLoading, error: pdfError, loadPdf, renderPage, destroy } = usePdfRenderer()

const currentPage = ref(1)
const containerWidth = ref(700)
const wrapperRef = ref(null)
const canvasRefs = ref({})
const pageContainerRefs = ref({})

// Signature state
const signatureMode = ref(null) // 'draw' | 'upload' | null
const showSignatureModal = ref(false)

// Drawing pad state
const signatureCanvas = ref(null)
const isDrawing = ref(false)
const hasDrawn = ref(false)

// Final signature (from either method)
const signatureDataUrl = ref(null)
const signatureSource = ref(null) // 'draw' | 'upload'

// Stamp upload state
const stampFile = ref(null)
const stampPreviewUrl = ref(null)

// Upload refs
const signatureUploadInput = ref(null)
const stampUploadInput = ref(null)

const myZones = computed(() => props.zones.filter(z =>
  !z.signer_email || z.signer_email.toLowerCase() === props.mySignerEmail?.toLowerCase()
))

const otherZones = computed(() => props.zones.filter(z =>
  z.signer_email && z.signer_email.toLowerCase() !== props.mySignerEmail?.toLowerCase()
))

const needsSignature = computed(() => myZones.value.some(z => z.zone_type === 'signature' || z.zone_type === 'signature_and_stamp'))
const needsStamp = computed(() => myZones.value.some(z => z.zone_type === 'stamp' || z.zone_type === 'signature_and_stamp'))

const canSubmit = computed(() => {
  if (needsSignature.value && !signatureDataUrl.value) return false
  if (needsStamp.value && !stampPreviewUrl.value) return false
  return true
})

const currentPageMyZones = computed(() => myZones.value.filter(z => z.page_number === currentPage.value))
const currentPageOtherZones = computed(() => otherZones.value.filter(z => z.page_number === currentPage.value))

const requirementsList = computed(() => {
  const list = []
  if (needsSignature.value) {
    list.push({ key: 'signature', label: t('pdfSignatureViewer.signatureRequired'), done: !!signatureDataUrl.value })
  }
  if (needsStamp.value) {
    list.push({ key: 'stamp', label: t('pdfSignatureViewer.stampRequired'), done: !!stampPreviewUrl.value })
  }
  return list
})

onMounted(async () => {
  await loadPdf(props.pdfUrl)
  await nextTick()
  measureContainer()
  renderCurrentPage()
  window.addEventListener('resize', onResize)
})

onUnmounted(() => {
  destroy()
  window.removeEventListener('resize', onResize)
  if (stampPreviewUrl.value) URL.revokeObjectURL(stampPreviewUrl.value)
})

watch(currentPage, () => nextTick(() => renderCurrentPage()))

function onResize() {
  measureContainer()
  renderCurrentPage()
}

function measureContainer() {
  if (wrapperRef.value) {
    containerWidth.value = Math.min(wrapperRef.value.offsetWidth - 32, 900)
  }
}

async function renderCurrentPage() {
  const canvas = canvasRefs.value[currentPage.value]
  if (!canvas) return
  try {
    await renderPage(currentPage.value, canvas, containerWidth.value)
  } catch (e) {
    console.error('Failed to render page', e)
  }
}

function setCanvasRef(pageNum, el) {
  if (el) canvasRefs.value[pageNum] = el
}

function setPageContainerRef(pageNum, el) {
  if (el) pageContainerRefs.value[pageNum] = el
}

// --- Signature: Draw ---
function openSignatureDraw() {
  signatureMode.value = 'draw'
  showSignatureModal.value = true
  nextTick(initCanvas)
}

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
  hasDrawn.value = true
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

function clearCanvas() {
  const canvas = signatureCanvas.value
  const ctx = canvas.getContext('2d')
  ctx.clearRect(0, 0, canvas.width, canvas.height)
  hasDrawn.value = false
}

function confirmDraw() {
  const canvas = signatureCanvas.value
  signatureDataUrl.value = canvas.toDataURL('image/png')
  signatureSource.value = 'draw'
  showSignatureModal.value = false
  signatureMode.value = null
}

// --- Signature: Upload ---
function openSignatureUpload() {
  signatureMode.value = 'upload'
  showSignatureModal.value = true
}

function handleSignatureFileSelect(e) {
  const file = e.target.files?.[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => {
    signatureDataUrl.value = reader.result
    signatureSource.value = 'upload'
    showSignatureModal.value = false
    signatureMode.value = null
  }
  reader.readAsDataURL(file)
}

function removeSignature() {
  signatureDataUrl.value = null
  signatureSource.value = null
}

// --- Stamp ---
function handleStampFile(e) {
  const files = e.target.files
  if (!files?.length) return
  stampFile.value = files[0]
  if (stampPreviewUrl.value) URL.revokeObjectURL(stampPreviewUrl.value)
  stampPreviewUrl.value = URL.createObjectURL(files[0])
}

function removeStamp() {
  stampFile.value = null
  if (stampPreviewUrl.value) URL.revokeObjectURL(stampPreviewUrl.value)
  stampPreviewUrl.value = null
}

// --- Submit ---
async function submitSignature() {
  if (!canSubmit.value || props.loading) return

  let stampData = null
  if (stampFile.value) {
    stampData = await fileToBase64(stampFile.value)
  }

  emit('submit', {
    signature_data: signatureDataUrl.value,
    stamp_data: stampData,
  })
}

function fileToBase64(file) {
  return new Promise((resolve) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.readAsDataURL(file)
  })
}

function zoneStyle(z, isMine) {
  const color = isMine ? '#6366f1' : '#9ca3af'
  return {
    left: `${z.x_percent}%`,
    top: `${z.y_percent}%`,
    width: `${z.width_percent}%`,
    height: `${z.height_percent}%`,
    borderColor: color,
    backgroundColor: isMine ? `${color}15` : `${color}08`,
  }
}
</script>

<template>
  <div ref="wrapperRef" class="flex flex-col h-full">
    <!-- Toolbar -->
    <div class="flex items-center gap-3 px-4 py-3 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 flex-wrap">
      <!-- Signing actions -->
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Signature button (draw or upload) -->
        <template v-if="needsSignature">
          <div v-if="!signatureDataUrl" class="flex items-center gap-1">
            <button @click="openSignatureDraw"
                    class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium transition-colors border-2 border-dashed border-primary-300 dark:border-primary-500/40 hover:bg-primary-50 dark:hover:bg-primary-500/10 text-primary-700 dark:text-primary-300">
              <span class="material-symbols-rounded text-sm">draw</span>
              {{ $t('pdfSignatureViewer.drawSignature') }}
            </button>
            <span class="text-xs text-surface-400">{{ $t('pdfSignatureViewer.or') }}</span>
            <button @click="showSignatureModal = true; signatureMode = 'upload'"
                    class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium transition-colors border-2 border-dashed border-primary-300 dark:border-primary-500/40 hover:bg-primary-50 dark:hover:bg-primary-500/10 text-primary-700 dark:text-primary-300">
              <span class="material-symbols-rounded text-sm">upload</span>
              {{ $t('pdfSignatureViewer.uploadSignatureImage') }}
            </button>
          </div>
          <div v-else class="flex items-center gap-2">
            <div class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium border-2 border-green-400 dark:border-green-500 bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-300">
              <span class="material-symbols-rounded text-sm">check_circle</span>
              {{ signatureSource === 'draw' ? $t('pdfSignatureViewer.signatureDrawn') : $t('pdfSignatureViewer.signatureUploaded') }}
            </div>
            <button @click="removeSignature"
                    class="p-1 rounded-lg text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
        </template>

        <!-- Stamp upload -->
        <template v-if="needsStamp">
          <label v-if="!stampPreviewUrl"
                 class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium transition-colors border-2 border-dashed border-amber-300 dark:border-amber-500/40 hover:bg-amber-50 dark:hover:bg-amber-500/10 text-amber-700 dark:text-amber-300 cursor-pointer">
            <span class="material-symbols-rounded text-sm">approval</span>
            {{ $t('pdfSignatureViewer.uploadStamp') }}
            <input ref="stampUploadInput" type="file" @change="handleStampFile" accept=".png,.jpg,.jpeg" class="hidden" />
          </label>
          <div v-else class="flex items-center gap-2">
            <div class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium border-2 border-green-400 dark:border-green-500 bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-300">
              <span class="material-symbols-rounded text-sm">check_circle</span>
              {{ $t('pdfSignatureViewer.stampDone') }}
            </div>
            <button @click="removeStamp"
                    class="p-1 rounded-lg text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
        </template>
      </div>

      <div class="flex-1"></div>

      <!-- Requirements checklist (compact) -->
      <div v-if="requirementsList.length" class="hidden sm:flex items-center gap-3 text-xs">
        <div v-for="req in requirementsList" :key="req.key"
             :class="['flex items-center gap-1', req.done ? 'text-green-600 dark:text-green-400' : 'text-surface-400']">
          <span class="material-symbols-rounded text-xs">{{ req.done ? 'check_circle' : 'radio_button_unchecked' }}</span>
          {{ req.label }}
        </div>
      </div>

      <!-- Page navigation -->
      <div class="flex items-center gap-2">
        <button @click="currentPage = Math.max(1, currentPage - 1)" :disabled="currentPage <= 1"
                class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-30">
          <span class="material-symbols-rounded text-lg">chevron_left</span>
        </button>
        <span class="text-xs text-surface-600 dark:text-surface-300 font-medium">
          {{ $t('pdfSignatureViewer.pageOf', { current: currentPage, total: pageCount }) }}
        </span>
        <button @click="currentPage = Math.min(pageCount, currentPage + 1)" :disabled="currentPage >= pageCount"
                class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-30">
          <span class="material-symbols-rounded text-lg">chevron_right</span>
        </button>
      </div>
    </div>

    <!-- PDF -->
    <div class="flex-1 overflow-auto p-4 bg-surface-100 dark:bg-surface-900">
      <div v-if="pdfLoading" class="flex items-center justify-center py-20">
        <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
      </div>
      <div v-else-if="pdfError" class="text-center py-20">
        <span class="material-symbols-rounded text-4xl text-red-400">error</span>
        <p class="mt-2 text-sm text-surface-500">{{ pdfError }}</p>
      </div>
      <div v-else class="flex justify-center">
        <div class="relative inline-block" :ref="(el) => setPageContainerRef(currentPage, el)" style="user-select: none;">
          <canvas :ref="(el) => setCanvasRef(currentPage, el)" class="block rounded-lg shadow-lg"></canvas>

          <!-- My zones (highlighted) -->
          <div v-for="zone in currentPageMyZones" :key="zone.id"
               class="absolute border-2 border-dashed rounded-md"
               :style="zoneStyle(zone, true)">
            <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none gap-0.5">
              <!-- Signature preview -->
              <img v-if="signatureDataUrl && (zone.zone_type === 'signature' || zone.zone_type === 'signature_and_stamp')"
                   :src="signatureDataUrl" class="max-w-full max-h-[60%] object-contain opacity-80" />
              <!-- Stamp preview -->
              <img v-if="stampPreviewUrl && (zone.zone_type === 'stamp' || zone.zone_type === 'signature_and_stamp')"
                   :src="stampPreviewUrl" class="max-w-[40%] max-h-[50%] object-contain opacity-70" />
              <!-- Placeholder if not yet placed -->
              <span v-if="!signatureDataUrl && (zone.zone_type === 'signature' || zone.zone_type === 'signature_and_stamp')"
                    class="material-symbols-rounded text-2xl text-primary-400/50">draw</span>
              <span v-if="!stampPreviewUrl && (zone.zone_type === 'stamp' || zone.zone_type === 'signature_and_stamp')"
                    class="material-symbols-rounded text-xl text-amber-400/50">approval</span>
            </div>
            <div class="absolute -top-5 left-0 px-1.5 py-0.5 rounded-t-md text-white text-[9px] font-medium bg-primary-500 whitespace-nowrap">
              {{ zone.label || $t('pdfSignatureViewer.signHere') }}
            </div>
          </div>

          <!-- Other signers' zones (greyed) -->
          <div v-for="zone in currentPageOtherZones" :key="zone.id"
               class="absolute border border-dashed rounded-md opacity-40 pointer-events-none"
               :style="zoneStyle(zone, false)">
            <div class="absolute -top-5 left-0 px-1.5 py-0.5 rounded-t-md bg-surface-400 text-white text-[9px] font-medium whitespace-nowrap">
              {{ zone.signer_name || zone.signer_email || $t('pdfSignatureViewer.otherSigner') }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Bottom action bar -->
    <div class="flex items-center justify-between px-4 py-3 bg-white dark:bg-surface-800 border-t border-surface-200 dark:border-surface-700">
      <button @click="emit('cancel')" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300">
        {{ $t('pdfSignatureViewer.cancel') }}
      </button>

      <!-- Info text about what gets saved -->
      <div class="hidden md:flex items-center gap-1.5 text-[10px] text-surface-400">
        <span class="material-symbols-rounded text-xs">verified_user</span>
        {{ $t('pdfSignatureViewer.legalNote') }}
      </div>

      <button @click="submitSignature" :disabled="!canSubmit || loading"
              class="px-6 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-medium disabled:opacity-50 transition-colors flex items-center gap-2">
        <div v-if="loading" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></div>
        <span class="material-symbols-rounded text-sm" v-else>send</span>
        {{ loading ? $t('pdfSignatureViewer.submitting') : $t('pdfSignatureViewer.submitSignature') }}
      </button>
    </div>

    <!-- Signature Modal (Draw or Upload) -->
    <Teleport to="body">
      <div v-if="showSignatureModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50" @click.self="showSignatureModal = false; signatureMode = null">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg p-6">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">{{ signatureMode === 'draw' ? 'draw' : 'upload' }}</span>
            {{ signatureMode === 'draw' ? $t('pdfSignatureViewer.drawYourSignature') : $t('pdfSignatureViewer.uploadSignatureTitle') }}
          </h3>

          <!-- Mode tabs -->
          <div class="flex gap-1 mb-4 bg-surface-100 dark:bg-surface-700 rounded-xl p-1">
            <button @click="signatureMode = 'draw'; $nextTick(initCanvas)"
                    :class="signatureMode === 'draw' ? 'bg-white dark:bg-surface-600 shadow-sm' : ''"
                    class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-all">
              <span class="material-symbols-rounded text-sm">draw</span>
              {{ $t('pdfSignatureViewer.drawTab') }}
            </button>
            <button @click="signatureMode = 'upload'"
                    :class="signatureMode === 'upload' ? 'bg-white dark:bg-surface-600 shadow-sm' : ''"
                    class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-all">
              <span class="material-symbols-rounded text-sm">upload</span>
              {{ $t('pdfSignatureViewer.uploadTab') }}
            </button>
          </div>

          <!-- Draw mode -->
          <div v-if="signatureMode === 'draw'">
            <p class="text-sm text-surface-500 mb-3">{{ $t('pdfSignatureViewer.drawBelow') }}</p>
            <canvas
              ref="signatureCanvas"
              @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseleave="endDraw"
              @touchstart.prevent="startDraw" @touchmove.prevent="draw" @touchend="endDraw"
              class="w-full h-48 border-2 border-surface-300 dark:border-surface-600 rounded-xl bg-white dark:bg-surface-750 cursor-crosshair"
              style="touch-action: none;"
            ></canvas>
            <div class="flex gap-3 mt-4">
              <button @click="confirmDraw" :disabled="!hasDrawn"
                      class="px-6 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-medium text-sm disabled:opacity-50 transition-colors">
                {{ $t('pdfSignatureViewer.confirmSignature') }}
              </button>
              <button @click="clearCanvas" class="px-4 py-2.5 rounded-xl text-surface-500 hover:text-surface-700 text-sm">
                {{ $t('pdfSignatureViewer.clear') }}
              </button>
              <button @click="showSignatureModal = false; signatureMode = null" class="px-4 py-2.5 rounded-xl text-surface-500 hover:text-surface-700 text-sm">
                {{ $t('pdfSignatureViewer.cancel') }}
              </button>
            </div>
          </div>

          <!-- Upload mode -->
          <div v-if="signatureMode === 'upload'">
            <p class="text-sm text-surface-500 mb-3">{{ $t('pdfSignatureViewer.uploadSignatureDesc') }}</p>
            <label class="flex flex-col items-center justify-center w-full h-40 border-2 border-dashed border-surface-300 dark:border-surface-600 rounded-xl cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors">
              <span class="material-symbols-rounded text-3xl text-surface-400 mb-2">cloud_upload</span>
              <span class="text-sm text-surface-500">{{ $t('pdfSignatureViewer.clickToUpload') }}</span>
              <span class="text-xs text-surface-400 mt-1">PNG, JPG</span>
              <input ref="signatureUploadInput" type="file" @change="handleSignatureFileSelect" accept=".png,.jpg,.jpeg" class="hidden" />
            </label>
            <div class="flex gap-3 mt-4 justify-end">
              <button @click="showSignatureModal = false; signatureMode = null" class="px-4 py-2.5 rounded-xl text-surface-500 hover:text-surface-700 text-sm">
                {{ $t('pdfSignatureViewer.cancel') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
