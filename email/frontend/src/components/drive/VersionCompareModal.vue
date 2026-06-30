<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDriveStore } from '@/stores/drive'
import { useToastStore } from '@/stores/toast'
import { getToken } from '@/services/tokenStorage'
import ImageCompare from './compare/ImageCompare.vue'
import PdfCompare from './compare/PdfCompare.vue'
import DocxCompare from './compare/DocxCompare.vue'
import SpreadsheetCompare from './compare/SpreadsheetCompare.vue'
import TextDiffCompare from './compare/TextDiffCompare.vue'
import PptCompare from './compare/PptCompare.vue'

const props = defineProps({
  show: Boolean,
  file: Object,
  leftVersion: Object, // Older version
  rightVersion: Object, // Newer version
  versions: { type: Array, default: () => [] }, // full history for in-modal switching
})

const emit = defineEmits(['close', 'restored'])

const { t, locale } = useI18n()
const drive = useDriveStore()
const toast = useToastStore()

// State
const loading = ref(true)
const error = ref(null)
const leftContent = ref(null)
const rightContent = ref(null)
const compareMode = ref('side-by-side')
const fileType = ref('unknown')

// In-modal switchable pair (seeded from props each time the modal opens)
const leftSel = ref(null)
const rightSel = ref(null)

const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

const sortedVersions = computed(() =>
  [...props.versions].sort((a, b) => a.version_number - b.version_number)
)

// Modes per file type
const availableModes = computed(() => {
  const modes = [{ id: 'side-by-side', label: t('versionCompare.modeSideBySide'), icon: 'view_column' }]
  if (fileType.value === 'image') {
    modes.push({ id: 'slider', label: t('versionCompare.modeSlider'), icon: 'compare' })
    modes.push({ id: 'onion', label: t('versionCompare.modeOnion'), icon: 'layers' })
    modes.push({ id: 'pixel', label: t('versionCompare.modePixelDiff'), icon: 'grain' })
  }
  if (fileType.value === 'text') {
    modes.push({ id: 'diff', label: t('versionCompare.modeDiff'), icon: 'difference' })
  }
  if (fileType.value === 'pdf') {
    modes.push({ id: 'text', label: t('versionCompare.modeTextDiff'), icon: 'difference' })
  }
  return modes
})

const compareComponent = computed(() => {
  switch (fileType.value) {
    case 'image': return ImageCompare
    case 'pdf': return PdfCompare
    case 'docx': return DocxCompare
    case 'spreadsheet': return SpreadsheetCompare
    case 'text': return TextDiffCompare
    case 'ppt': return PptCompare
    default: return null
  }
})

watch(() => props.show, async (showing) => {
  if (showing && props.file?.id && props.leftVersion?.version_number != null && props.rightVersion?.version_number != null) {
    leftSel.value = props.leftVersion
    rightSel.value = props.rightVersion
    window.addEventListener('keydown', onKeydown)
    await loadContents()
  } else if (!showing) {
    window.removeEventListener('keydown', onKeydown)
  }
}, { immediate: true })

onUnmounted(() => window.removeEventListener('keydown', onKeydown))

async function loadContents() {
  loading.value = true
  error.value = null
  leftContent.value = null
  rightContent.value = null

  try {
    const [left, right] = await Promise.all([
      fetchVersionContent(leftSel.value),
      fetchVersionContent(rightSel.value),
    ])

    leftContent.value = left
    rightContent.value = right
    fileType.value = left.type || 'unknown'
    compareMode.value = fileType.value === 'text' ? 'diff' : 'side-by-side'
  } catch (e) {
    console.error('Failed to load version contents:', e)
    error.value = e.message || t('versionCompare.loadFailed')
  } finally {
    loading.value = false
  }
}

async function reloadSide(side) {
  loading.value = true
  error.value = null
  try {
    const content = await fetchVersionContent(side === 'left' ? leftSel.value : rightSel.value)
    if (side === 'left') leftContent.value = content
    else rightContent.value = content
    fileType.value = (leftContent.value?.type || content.type) || 'unknown'
  } catch (e) {
    error.value = e.message || t('versionCompare.loadFailed')
  } finally {
    loading.value = false
  }
}

async function fetchVersionContent(version) {
  if (!version || !props.file?.id) {
    throw new Error(t('versionCompare.invalidVersion'))
  }
  const versionId = version.is_current ? 'current' : version.id
  if (!versionId) {
    throw new Error(t('versionCompare.invalidVersion'))
  }
  return await drive.fetchVersionPreview(props.file.id, versionId)
}

// ── In-modal switching ──

function versionKey(v) {
  return v?.id ?? 'current'
}

function onSelectVersion(side, key) {
  const version = sortedVersions.value.find((v) => String(versionKey(v)) === String(key))
  if (!version) return
  if (side === 'left') {
    if (versionKey(version) === versionKey(rightSel.value)) return
    leftSel.value = version
  } else {
    if (versionKey(version) === versionKey(leftSel.value)) return
    rightSel.value = version
  }
  reloadSide(side)
}

// Arrow keys step the OLDER side through history (clamped below the newer side)
function stepLeft(direction) {
  const list = sortedVersions.value
  if (!list.length || !leftSel.value) return
  const idx = list.findIndex((v) => versionKey(v) === versionKey(leftSel.value))
  const next = idx + direction
  if (next < 0 || next >= list.length) return
  if (list[next].version_number >= (rightSel.value?.version_number ?? Infinity)) return
  leftSel.value = list[next]
  reloadSide('left')
}

function onKeydown(e) {
  if (!props.show) return
  if (e.key === 'Escape') {
    e.preventDefault()
    close()
  } else if (e.key === 'ArrowLeft') {
    e.preventDefault()
    stepLeft(-1)
  } else if (e.key === 'ArrowRight') {
    e.preventDefault()
    stepLeft(1)
  }
}

function swapSides() {
  const tmpV = leftSel.value
  leftSel.value = rightSel.value
  rightSel.value = tmpV
  const tmpC = leftContent.value
  leftContent.value = rightContent.value
  rightContent.value = tmpC
}

// ── Per-side actions ──

async function restoreSide(version) {
  if (!version?.id || version.is_current) return
  const success = await drive.restoreVersion(props.file.id, version.id)
  if (success) {
    toast.success(t('driveView.versionRestoredSuccessfully'))
    emit('restored')
    close()
  } else {
    toast.error(t('driveView.failedToRestoreVersion'))
  }
}

function downloadSide(version) {
  const token = getToken('webmail_token')
  const isCurrent = version?.is_current || !version?.id
  const url = isCurrent
    ? drive.getDownloadUrl(props.file.id)
    : drive.getVersionDownloadUrl(props.file.id, version.id)
  const name = isCurrent
    ? props.file?.original_name || 'file'
    : `v${version.version_number}_${props.file?.original_name || 'file'}`

  fetch(url, { headers: { Authorization: `Bearer ${token}` } })
    .then((res) => res.blob())
    .then((blob) => {
      const downloadUrl = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = downloadUrl
      a.download = name
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(downloadUrl)
    })
    .catch(() => toast.error(t('driveView.failedToDownloadVersion')))
}

function close() {
  emit('close')
}

// ── Formatters ──

function formatSize(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString(localeTag.value, { month: 'short', day: 'numeric', year: 'numeric' })
    + ' ' + d.toLocaleTimeString(localeTag.value, { hour: '2-digit', minute: '2-digit' })
}

function versionTitle(v) {
  if (!v) return ''
  let label = t('driveView.versionNumber', { number: v.version_number })
  if (v.is_current) label += ` (${t('driveView.current')})`
  if (v.label) label += ` · ${v.label}`
  return label
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/80"
        @click.self="close"
      >
        <div class="w-full h-full max-w-[95vw] max-h-[95vh] bg-white dark:bg-surface-900 rounded-xl shadow-2xl flex flex-col overflow-hidden m-4">
          <!-- Header -->
          <div class="flex items-center justify-between gap-4 px-6 py-3 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
            <div class="flex items-center gap-4 min-w-0">
              <div class="flex items-center gap-2 flex-shrink-0">
                <span class="material-symbols-rounded text-2xl text-primary-500">compare</span>
                <div class="min-w-0">
                  <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 leading-tight">
                    {{ $t('versionCompare.title') }}
                  </h2>
                  <p class="text-sm text-surface-500 truncate max-w-[220px]">{{ file?.original_name }}</p>
                </div>
              </div>

              <!-- Version pickers + metadata -->
              <div class="flex items-center gap-2">
                <!-- Left (older) chip -->
                <div class="flex flex-col gap-0.5 px-3 py-1.5 bg-amber-100 dark:bg-amber-500/20 rounded-lg">
                  <div class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm text-amber-600 dark:text-amber-400">history</span>
                    <select
                      v-if="sortedVersions.length"
                      :value="String(leftSel?.id ?? 'current')"
                      @change="onSelectVersion('left', $event.target.value)"
                      class="bg-transparent text-sm font-medium text-amber-700 dark:text-amber-300 focus:outline-none cursor-pointer"
                      :title="$t('versionCompare.switchVersion')"
                    >
                      <option
                        v-for="v in sortedVersions"
                        :key="versionKey(v)"
                        :value="String(versionKey(v))"
                        :disabled="versionKey(v) === versionKey(rightSel)"
                        class="text-surface-900"
                      >{{ versionTitle(v) }}</option>
                    </select>
                    <span v-else class="text-sm font-medium text-amber-700 dark:text-amber-300">
                      {{ versionTitle(leftSel) }}
                    </span>
                    <button
                      @click="downloadSide(leftSel)"
                      class="p-0.5 rounded hover:bg-amber-200 dark:hover:bg-amber-500/30"
                      :title="$t('driveView.downloadThisVersion')"
                    >
                      <span class="material-symbols-rounded text-sm text-amber-700 dark:text-amber-300">download</span>
                    </button>
                    <button
                      v-if="leftSel && !leftSel.is_current && leftSel.id"
                      @click="restoreSide(leftSel)"
                      class="p-0.5 rounded hover:bg-amber-200 dark:hover:bg-amber-500/30"
                      :title="$t('driveView.restoreThisVersion')"
                    >
                      <span class="material-symbols-rounded text-sm text-amber-700 dark:text-amber-300">settings_backup_restore</span>
                    </button>
                  </div>
                  <span class="text-[10px] text-amber-700/70 dark:text-amber-300/70 leading-none">
                    {{ formatDate(leftSel?.created_at) }} · {{ formatSize(leftSel?.size) }}<template v-if="leftSel?.modified_by"> · {{ leftSel.modified_by }}</template>
                  </span>
                </div>

                <!-- Swap -->
                <button
                  @click="swapSides"
                  class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
                  :title="$t('versionCompare.swapSides')"
                >
                  <span class="material-symbols-rounded text-surface-500">swap_horiz</span>
                </button>

                <!-- Right (newer) chip -->
                <div class="flex flex-col gap-0.5 px-3 py-1.5 bg-green-100 dark:bg-green-500/20 rounded-lg">
                  <div class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm text-green-600 dark:text-green-400">update</span>
                    <select
                      v-if="sortedVersions.length"
                      :value="String(rightSel?.id ?? 'current')"
                      @change="onSelectVersion('right', $event.target.value)"
                      class="bg-transparent text-sm font-medium text-green-700 dark:text-green-300 focus:outline-none cursor-pointer"
                      :title="$t('versionCompare.switchVersion')"
                    >
                      <option
                        v-for="v in sortedVersions"
                        :key="versionKey(v)"
                        :value="String(versionKey(v))"
                        :disabled="versionKey(v) === versionKey(leftSel)"
                        class="text-surface-900"
                      >{{ versionTitle(v) }}</option>
                    </select>
                    <span v-else class="text-sm font-medium text-green-700 dark:text-green-300">
                      {{ versionTitle(rightSel) }}
                    </span>
                    <button
                      @click="downloadSide(rightSel)"
                      class="p-0.5 rounded hover:bg-green-200 dark:hover:bg-green-500/30"
                      :title="$t('driveView.downloadThisVersion')"
                    >
                      <span class="material-symbols-rounded text-sm text-green-700 dark:text-green-300">download</span>
                    </button>
                    <button
                      v-if="rightSel && !rightSel.is_current && rightSel.id"
                      @click="restoreSide(rightSel)"
                      class="p-0.5 rounded hover:bg-green-200 dark:hover:bg-green-500/30"
                      :title="$t('driveView.restoreThisVersion')"
                    >
                      <span class="material-symbols-rounded text-sm text-green-700 dark:text-green-300">settings_backup_restore</span>
                    </button>
                  </div>
                  <span class="text-[10px] text-green-700/70 dark:text-green-300/70 leading-none">
                    {{ formatDate(rightSel?.created_at) }} · {{ formatSize(rightSel?.size) }}<template v-if="rightSel?.modified_by"> · {{ rightSel.modified_by }}</template>
                  </span>
                </div>
              </div>
            </div>

            <div class="flex items-center gap-4 flex-shrink-0">
              <!-- Mode toggle -->
              <div v-if="!loading && !error && availableModes.length > 1" class="flex items-center gap-1 p-1 bg-surface-100 dark:bg-surface-700 rounded-lg">
                <button
                  v-for="mode in availableModes"
                  :key="mode.id"
                  @click="compareMode = mode.id"
                  :class="[
                    'px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1',
                    compareMode === mode.id
                      ? 'bg-white dark:bg-surface-600 text-primary-600 dark:text-primary-400 shadow-sm'
                      : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">{{ mode.icon }}</span>
                  {{ mode.label }}
                </button>
              </div>

              <button
                @click="close"
                class="p-2 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
                :title="$t('versionCompare.close')"
              >
                <span class="material-symbols-rounded text-surface-500">close</span>
              </button>
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-hidden">
            <div v-if="loading" class="flex items-center justify-center h-full">
              <div class="text-center">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-500 mx-auto mb-4"></div>
                <p class="text-surface-500">{{ $t('versionCompare.loading') }}</p>
              </div>
            </div>

            <div v-else-if="error" class="flex items-center justify-center h-full">
              <div class="text-center max-w-md">
                <span class="material-symbols-rounded text-5xl text-red-500 mb-4">error</span>
                <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">
                  {{ $t('versionCompare.loadFailedTitle') }}
                </h3>
                <p class="text-surface-500">{{ error }}</p>
                <button
                  @click="loadContents"
                  class="mt-4 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
                >
                  {{ $t('versionCompare.tryAgain') }}
                </button>
              </div>
            </div>

            <div v-else-if="!compareComponent" class="flex items-center justify-center h-full">
              <div class="text-center max-w-md">
                <span class="material-symbols-rounded text-5xl text-surface-400 mb-4">block</span>
                <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">
                  {{ $t('versionCompare.notAvailableTitle') }}
                </h3>
                <p class="text-surface-500">{{ $t('versionCompare.notAvailableText') }}</p>
              </div>
            </div>

            <component
              v-else
              :is="compareComponent"
              :left-content="leftContent"
              :right-content="rightContent"
              :left-version="leftSel"
              :right-version="rightSel"
              :mode="compareMode"
              class="h-full"
            />
          </div>

          <!-- Footer -->
          <div class="flex items-center justify-between px-6 py-3 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
            <div class="flex items-center gap-6 text-sm text-surface-500">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded bg-amber-500"></span>
                <span>v{{ leftSel?.version_number }}: {{ formatSize(leftSel?.size) }}</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded bg-green-500"></span>
                <span>v{{ rightSel?.version_number }}: {{ formatSize(rightSel?.size) }}</span>
              </div>
              <span class="hidden md:inline text-xs text-surface-400">{{ $t('versionCompare.keyboardHint') }}</span>
            </div>
            <button
              @click="close"
              class="px-4 py-2 bg-surface-200 dark:bg-surface-700 hover:bg-surface-300 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 rounded-lg transition-colors"
            >
              {{ $t('versionCompare.close') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
