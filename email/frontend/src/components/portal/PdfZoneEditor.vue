<script setup>
/**
 * PdfZoneEditor - Admin places signature/stamp zones on PDF pages.
 * Each zone is assigned to a signer and has a type (signature, stamp, or both).
 * Zones are stored as percentage coordinates for resolution independence.
 */
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePdfRenderer } from '@/composables/usePdfRenderer'
import { useZoneDrag } from '@/composables/useZoneDrag'

const { t } = useI18n()

const props = defineProps({
  pdfUrl: { type: String, required: true },
  signers: { type: Array, default: () => [] },
  existingZones: { type: Array, default: () => [] },
})

const emit = defineEmits(['save', 'cancel'])

const { pageCount, loading: pdfLoading, error: pdfError, loadPdf, renderPage, destroy } = usePdfRenderer()
const {
  isDragging, isResizing, activeZoneId,
  startCreate, updateCreate, endCreate,
  startMove, updateMove, startResize, updateResize, endDrag,
} = useZoneDrag()

const zones = ref([])
const currentPage = ref(1)
const selectedZoneId = ref(null)
const mode = ref('select')
const newZoneType = ref('signature')
const newZoneSignerIdx = ref(0)
const drawingZone = ref(null)
const containerWidth = ref(700)

const canvasRefs = ref({})
const pageContainerRefs = ref({})
const wrapperRef = ref(null)

const signerColors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#ec4899']

let nextTempId = 1
function tempId() { return `_t${nextTempId++}` }

const currentPageZones = computed(() => zones.value.filter(z => z.page_number === currentPage.value))

const selectedZone = computed(() => {
  if (!selectedZoneId.value) return null
  return zones.value.find(z => (z.id || z._tempId) === selectedZoneId.value) || null
})

function signerColor(signerEmail) {
  const idx = props.signers.findIndex(s => s.email === signerEmail)
  return signerColors[idx % signerColors.length] || signerColors[0]
}

function signerLabel(signerEmail) {
  const s = props.signers.find(s => s.email === signerEmail)
  return s ? (s.name || s.email) : signerEmail || t('pdfZoneEditor.unassigned')
}

const zoneTypeOptions = [
  { value: 'signature', icon: 'draw', label: 'pdfZoneEditor.signature' },
  { value: 'stamp', icon: 'approval', label: 'pdfZoneEditor.stamp' },
  { value: 'signature_and_stamp', icon: 'verified', label: 'pdfZoneEditor.signatureAndStamp' },
]

onMounted(async () => {
  if (props.existingZones?.length) {
    zones.value = props.existingZones.map(z => ({
      ...z,
      x_percent: Number(z.x_percent),
      y_percent: Number(z.y_percent),
      width_percent: Number(z.width_percent),
      height_percent: Number(z.height_percent),
      page_number: Number(z.page_number),
      _tempId: z.id ? undefined : tempId(),
    }))
  }
  await loadPdf(props.pdfUrl)
  await nextTick()
  measureContainer()
  renderCurrentPage()
  window.addEventListener('resize', onResize)
})

onUnmounted(() => {
  destroy()
  window.removeEventListener('resize', onResize)
})

watch(currentPage, () => {
  nextTick(() => renderCurrentPage())
})

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

function onOverlayMouseDown(e) {
  if (mode.value !== 'draw') return
  e.preventDefault()
  const container = pageContainerRefs.value[currentPage.value]
  if (!container) return

  const signerEmail = props.signers[newZoneSignerIdx.value]?.email || ''
  const newZone = {
    _tempId: tempId(),
    document_id: null,
    signer_email: signerEmail,
    zone_type: newZoneType.value,
    page_number: currentPage.value,
    x_percent: 0,
    y_percent: 0,
    width_percent: 0,
    height_percent: 0,
    label: '',
  }

  drawingZone.value = newZone
  startCreate(e, container, (start) => {
    newZone.x_percent = start.x
    newZone.y_percent = start.y
  })
}

function onOverlayMouseMove(e) {
  if (isDragging.value && drawingZone.value) {
    const container = pageContainerRefs.value[currentPage.value]
    if (container) updateCreate(e, container, drawingZone.value)
    return
  }
  if (isDragging.value && !drawingZone.value) {
    const container = pageContainerRefs.value[currentPage.value]
    const zone = zones.value.find(z => (z.id || z._tempId) === activeZoneId.value)
    if (container && zone) updateMove(e, container, zone)
    return
  }
  if (isResizing.value) {
    const container = pageContainerRefs.value[currentPage.value]
    const zone = zones.value.find(z => (z.id || z._tempId) === activeZoneId.value)
    if (container && zone) updateResize(e, container, zone)
  }
}

function onOverlayMouseUp() {
  if (drawingZone.value) {
    const ok = endCreate(drawingZone.value)
    if (ok) {
      zones.value.push(drawingZone.value)
      selectedZoneId.value = drawingZone.value._tempId
    }
    drawingZone.value = null
    return
  }
  endDrag()
}

function onZoneMouseDown(e, zone) {
  if (mode.value === 'draw') return
  e.stopPropagation()
  e.preventDefault()
  selectedZoneId.value = zone.id || zone._tempId
  const container = pageContainerRefs.value[currentPage.value]
  if (container) startMove(e, container, zone)
}

function onHandleMouseDown(e, zone, handle) {
  e.stopPropagation()
  e.preventDefault()
  selectedZoneId.value = zone.id || zone._tempId
  const container = pageContainerRefs.value[currentPage.value]
  if (container) startResize(e, container, zone, handle)
}

function deleteZone(zone) {
  const zid = zone.id || zone._tempId
  zones.value = zones.value.filter(z => (z.id || z._tempId) !== zid)
  if (selectedZoneId.value === zid) selectedZoneId.value = null
}

function deleteSelected() {
  if (selectedZone.value) deleteZone(selectedZone.value)
}

function emitSave() {
  emit('save', zones.value.map(z => ({
    signer_email: z.signer_email,
    zone_type: z.zone_type,
    page_number: z.page_number,
    x_percent: parseFloat(Number(z.x_percent).toFixed(4)),
    y_percent: parseFloat(Number(z.y_percent).toFixed(4)),
    width_percent: parseFloat(Number(z.width_percent).toFixed(4)),
    height_percent: parseFloat(Number(z.height_percent).toFixed(4)),
    label: z.label || null,
  })))
}

function zoneStyle(z) {
  const color = signerColor(z.signer_email)
  const isSelected = (z.id || z._tempId) === selectedZoneId.value
  return {
    left: `${z.x_percent}%`,
    top: `${z.y_percent}%`,
    width: `${z.width_percent}%`,
    height: `${z.height_percent}%`,
    borderColor: color,
    backgroundColor: `${color}18`,
    outline: isSelected ? `2px solid ${color}` : 'none',
    outlineOffset: '2px',
  }
}

function drawingZoneStyle() {
  if (!drawingZone.value) return { display: 'none' }
  const z = drawingZone.value
  const color = signerColor(z.signer_email)
  return {
    left: `${z.x_percent}%`,
    top: `${z.y_percent}%`,
    width: `${z.width_percent}%`,
    height: `${z.height_percent}%`,
    borderColor: color,
    backgroundColor: `${color}18`,
  }
}

function zoneTypeIcon(type) {
  const opt = zoneTypeOptions.find(o => o.value === type)
  return opt?.icon || 'draw'
}
</script>

<template>
  <div ref="wrapperRef" class="flex flex-col h-full">
    <!-- Toolbar -->
    <div class="flex items-center gap-3 px-4 py-3 bg-surface-50 dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 flex-wrap">
      <!-- Mode toggle -->
      <div class="flex items-center bg-white dark:bg-surface-700 rounded-xl border border-surface-200 dark:border-surface-600 overflow-hidden">
        <button @click="mode = 'select'"
                :class="['flex items-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors',
                  mode === 'select' ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300' : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-600']">
          <span class="material-symbols-rounded text-sm">arrow_selector_tool</span>
          {{ $t('pdfZoneEditor.selectMode') }}
        </button>
        <button @click="mode = 'draw'"
                :class="['flex items-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors',
                  mode === 'draw' ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300' : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-600']">
          <span class="material-symbols-rounded text-sm">add_box</span>
          {{ $t('pdfZoneEditor.drawZone') }}
        </button>
      </div>

      <!-- Zone type (when drawing) -->
      <div v-if="mode === 'draw'" class="flex items-center gap-2">
        <select v-model="newZoneType"
                class="px-2 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs text-surface-800 dark:text-surface-200">
          <option v-for="opt in zoneTypeOptions" :key="opt.value" :value="opt.value">{{ $t(opt.label) }}</option>
        </select>
        <select v-if="signers.length > 0" v-model="newZoneSignerIdx"
                class="px-2 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs text-surface-800 dark:text-surface-200">
          <option v-for="(s, idx) in signers" :key="idx" :value="idx">{{ s.name || s.email }}</option>
        </select>
      </div>

      <!-- Delete selected -->
      <button v-if="selectedZone" @click="deleteSelected"
              class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
        <span class="material-symbols-rounded text-sm">delete</span>
        {{ $t('pdfZoneEditor.deleteZone') }}
      </button>

      <div class="flex-1"></div>

      <!-- Page navigation -->
      <div class="flex items-center gap-2">
        <button @click="currentPage = Math.max(1, currentPage - 1)" :disabled="currentPage <= 1"
                class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-30">
          <span class="material-symbols-rounded text-lg">chevron_left</span>
        </button>
        <span class="text-xs text-surface-600 dark:text-surface-300 font-medium">
          {{ $t('pdfZoneEditor.pageOf', { current: currentPage, total: pageCount }) }}
        </span>
        <button @click="currentPage = Math.min(pageCount, currentPage + 1)" :disabled="currentPage >= pageCount"
                class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-30">
          <span class="material-symbols-rounded text-lg">chevron_right</span>
        </button>
      </div>
    </div>

    <!-- PDF Content -->
    <div class="flex-1 overflow-auto p-4 bg-surface-100 dark:bg-surface-900">
      <!-- Loading -->
      <div v-if="pdfLoading" class="flex items-center justify-center py-20">
        <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
      </div>

      <!-- Error -->
      <div v-else-if="pdfError" class="text-center py-20">
        <span class="material-symbols-rounded text-4xl text-red-400">error</span>
        <p class="mt-2 text-sm text-surface-500">{{ pdfError }}</p>
      </div>

      <!-- Page -->
      <div v-else class="flex justify-center">
        <div class="relative inline-block"
             :ref="(el) => setPageContainerRef(currentPage, el)"
             @mousedown="onOverlayMouseDown"
             @mousemove="onOverlayMouseMove"
             @mouseup="onOverlayMouseUp"
             @mouseleave="onOverlayMouseUp"
             @touchstart.prevent="onOverlayMouseDown"
             @touchmove.prevent="onOverlayMouseMove"
             @touchend="onOverlayMouseUp"
             :class="[mode === 'draw' ? 'cursor-crosshair' : 'cursor-default']"
             style="user-select: none;">

          <canvas :ref="(el) => setCanvasRef(currentPage, el)" class="block rounded-lg shadow-lg"></canvas>

          <!-- Existing zones on this page -->
          <div v-for="zone in currentPageZones" :key="zone.id || zone._tempId"
               class="absolute border-2 border-dashed rounded-md transition-shadow"
               :style="zoneStyle(zone)"
               @mousedown="onZoneMouseDown($event, zone)"
               @touchstart.prevent="onZoneMouseDown($event, zone)">

            <!-- Zone label -->
            <div class="absolute -top-6 left-0 flex items-center gap-1 px-1.5 py-0.5 rounded-t-md text-white text-[10px] font-medium whitespace-nowrap"
                 :style="{ backgroundColor: signerColor(zone.signer_email) }">
              <span class="material-symbols-rounded" style="font-size: 12px;">{{ zoneTypeIcon(zone.zone_type) }}</span>
              {{ signerLabel(zone.signer_email) }}
            </div>

            <!-- Resize handles (only in select mode, for selected zone) -->
            <template v-if="mode === 'select' && (zone.id || zone._tempId) === selectedZoneId">
              <div v-for="h in ['nw', 'ne', 'sw', 'se']" :key="h"
                   :class="['absolute w-3 h-3 rounded-full border-2 border-white bg-primary-500 z-10',
                     h === 'nw' ? '-top-1.5 -left-1.5 cursor-nw-resize' :
                     h === 'ne' ? '-top-1.5 -right-1.5 cursor-ne-resize' :
                     h === 'sw' ? '-bottom-1.5 -left-1.5 cursor-sw-resize' :
                     '-bottom-1.5 -right-1.5 cursor-se-resize']"
                   @mousedown="onHandleMouseDown($event, zone, h)"
                   @touchstart.prevent="onHandleMouseDown($event, zone, h)">
              </div>
            </template>
          </div>

          <!-- Drawing zone preview -->
          <div v-if="drawingZone" class="absolute border-2 border-dashed rounded-md pointer-events-none"
               :style="drawingZoneStyle()"></div>
        </div>
      </div>
    </div>

    <!-- Zone list sidebar / bottom panel -->
    <div v-if="zones.length > 0" class="border-t border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 p-3 max-h-40 overflow-y-auto">
      <div class="flex flex-wrap gap-2">
        <div v-for="zone in zones" :key="zone.id || zone._tempId"
             @click="selectedZoneId = zone.id || zone._tempId; currentPage = zone.page_number"
             :class="['flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs cursor-pointer transition-colors',
               (zone.id || zone._tempId) === selectedZoneId
                 ? 'border-primary-400 dark:border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                 : 'border-surface-200 dark:border-surface-600 hover:bg-surface-50 dark:hover:bg-surface-700/50']">
          <span class="w-2 h-2 rounded-full flex-shrink-0" :style="{ backgroundColor: signerColor(zone.signer_email) }"></span>
          <span class="material-symbols-rounded" style="font-size: 14px;">{{ zoneTypeIcon(zone.zone_type) }}</span>
          <span class="text-surface-700 dark:text-surface-200">{{ signerLabel(zone.signer_email) }}</span>
          <span class="text-surface-400">P{{ zone.page_number }}</span>
          <button @click.stop="deleteZone(zone)" class="ml-1 text-red-400 hover:text-red-600">
            <span class="material-symbols-rounded" style="font-size: 14px;">close</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Selected zone properties -->
    <div v-if="selectedZone" class="border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/80 p-3">
      <div class="flex items-center gap-3 flex-wrap">
        <div>
          <label class="text-[10px] uppercase tracking-wider text-surface-400 font-medium">{{ $t('pdfZoneEditor.zoneType') }}</label>
          <select v-model="selectedZone.zone_type"
                  class="block mt-0.5 px-2 py-1 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs text-surface-800 dark:text-surface-200">
            <option v-for="opt in zoneTypeOptions" :key="opt.value" :value="opt.value">{{ $t(opt.label) }}</option>
          </select>
        </div>
        <div>
          <label class="text-[10px] uppercase tracking-wider text-surface-400 font-medium">{{ $t('pdfZoneEditor.assignedSigner') }}</label>
          <select v-model="selectedZone.signer_email"
                  class="block mt-0.5 px-2 py-1 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs text-surface-800 dark:text-surface-200">
            <option v-for="s in signers" :key="s.email" :value="s.email">{{ s.name || s.email }}</option>
          </select>
        </div>
        <div>
          <label class="text-[10px] uppercase tracking-wider text-surface-400 font-medium">{{ $t('pdfZoneEditor.label') }}</label>
          <input v-model="selectedZone.label" type="text" :placeholder="$t('pdfZoneEditor.optionalLabel')"
                 class="block mt-0.5 px-2 py-1 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs text-surface-800 dark:text-surface-200 w-40" />
        </div>
      </div>
    </div>

    <!-- Action buttons -->
    <div class="flex items-center justify-between px-4 py-3 bg-white dark:bg-surface-800 border-t border-surface-200 dark:border-surface-700">
      <span class="text-xs text-surface-400">
        {{ $t('pdfZoneEditor.zoneCount', { count: zones.length }) }}
      </span>
      <div class="flex items-center gap-3">
        <button @click="emit('cancel')" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300">
          {{ $t('pdfZoneEditor.cancel') }}
        </button>
        <button @click="emitSave" :disabled="zones.length === 0"
                class="px-6 py-2 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50 transition-colors">
          {{ $t('pdfZoneEditor.saveZones') }}
        </button>
      </div>
    </div>
  </div>
</template>
