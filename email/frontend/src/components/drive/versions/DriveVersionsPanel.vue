<template>
  <div class="fixed top-0 right-0 bottom-0 w-96 bg-surface-50 dark:bg-surface-800 shadow-2xl border-l border-surface-200 dark:border-surface-700 z-50 flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 bg-surface-100 dark:bg-surface-900/50">
      <div class="flex items-center gap-3">
        <span class="material-symbols-rounded text-primary-500">history</span>
        <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ $t('driveView.versionHistory') }}</h3>
      </div>
      <div class="flex items-center gap-2">
        <button
          v-if="selectedVersions.length === 2"
          @click="emitCompare"
          class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-1"
        >
          <span class="material-symbols-rounded text-lg">compare</span>
          {{ $t('driveView.compare') }}
        </button>
        <button
          @click="$emit('close')"
          class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
        >
          <span class="material-symbols-rounded text-surface-500">close</span>
        </button>
      </div>
    </div>

    <!-- File info -->
    <div v-if="file" class="px-4 py-3 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center gap-3">
        <div :class="['w-10 h-10 rounded-lg flex items-center justify-center', fileIcon.bgColor]">
          <span :class="['material-symbols-rounded', fileIcon.color]">{{ fileIcon.icon }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-surface-900 dark:text-surface-100 truncate">{{ file.original_name }}</p>
          <p class="text-xs text-surface-500">{{ $t('driveView.currentVersion') }}: {{ file.current_version || 1 }}</p>
        </div>
      </div>
    </div>

    <!-- List -->
    <div class="flex-1 overflow-y-auto">
      <div v-if="loading" class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-500"></div>
      </div>

      <div v-else-if="versions.length === 0" class="flex flex-col items-center justify-center py-12 px-4 text-center">
        <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">folder_open</span>
        <p class="text-surface-500">{{ $t('driveView.noPreviousVersions') }}</p>
        <p class="text-sm text-surface-400 mt-1">{{ $t('driveView.uploadTheSameFileAgain') }}</p>
      </div>

      <template v-else>
        <div v-if="versions.length > 1" class="px-4 py-2 bg-surface-100 dark:bg-surface-700/50 text-xs text-surface-500 flex items-center gap-2">
          <span class="material-symbols-rounded text-sm">info</span>
          <span>{{ $t('driveView.select2VersionsToCompare') }}</span>
        </div>

        <div class="divide-y divide-surface-200 dark:divide-surface-700">
          <div
            v-for="version in versions"
            :key="version.id || 'current'"
            :class="[
              'px-4 py-3 hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors cursor-pointer',
              version.is_current ? 'bg-primary-50 dark:bg-primary-500/10' : '',
              isSelected(version) ? 'ring-2 ring-inset ring-primary-500' : ''
            ]"
            @click="toggleSelect(version)"
          >
            <div class="flex items-start gap-3">
              <div
                :class="[
                  'w-5 h-5 mt-0.5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors',
                  isSelected(version) ? 'bg-primary-500 border-primary-500' : 'border-surface-300 dark:border-surface-600'
                ]"
              >
                <span v-if="isSelected(version)" class="material-symbols-rounded text-sm text-white">check</span>
              </div>

              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium text-surface-900 dark:text-surface-100">
                    {{ $t('driveView.versionNumber', { number: version.version_number }) }}
                  </span>
                  <span v-if="version.is_current" class="px-2 py-0.5 text-xs font-medium bg-primary-500 text-white rounded-full">
                    {{ $t('driveView.current') }}
                  </span>
                  <span
                    v-if="Number(version.is_pinned)"
                    class="px-2 py-0.5 text-xs font-medium bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 rounded-full flex items-center gap-0.5"
                    :title="$t('versionsPanel.pinned')"
                  >
                    <span class="material-symbols-rounded text-xs">keep</span>
                    {{ $t('versionsPanel.pinned') }}
                  </span>
                </div>

                <!-- Label (display / inline edit) - history rows only; the
                     current pseudo-row has id=null which would match the
                     idle labelEditingId -->
                <div @click.stop>
                  <div v-if="version.id && labelEditingId === version.id" class="flex items-center gap-1 mt-1">
                    <input
                      v-model="labelDraft"
                      type="text"
                      class="flex-1 px-2 py-1 text-xs rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                      :placeholder="$t('versionsPanel.labelPlaceholder')"
                      maxlength="255"
                      @keydown.enter.prevent="saveLabel(version)"
                      @keydown.esc.prevent="labelEditingId = null"
                      v-focus
                    />
                    <button @click="saveLabel(version)" class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600">
                      <span class="material-symbols-rounded text-base text-emerald-500">check</span>
                    </button>
                    <button @click="labelEditingId = null" class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600">
                      <span class="material-symbols-rounded text-base text-surface-400">close</span>
                    </button>
                  </div>
                  <p
                    v-else-if="version.label"
                    class="text-xs font-medium text-primary-600 dark:text-primary-400 mt-0.5 truncate cursor-text"
                    :title="$t('versionsPanel.editLabel')"
                    @click="startLabelEdit(version)"
                  >
                    <span class="material-symbols-rounded text-xs align-middle">sell</span>
                    {{ version.label }}
                  </p>
                </div>

                <p class="text-sm text-surface-500 mt-0.5">{{ formatVersionDate(version.created_at) }}</p>
                <p class="text-xs text-surface-400 mt-0.5">
                  {{ formatSize(version.size) }}
                  <span v-if="version.modified_by"> {{ $t('driveView.by') }} {{ version.modified_by }}</span>
                </p>
                <p v-if="version.editing_duration_seconds" class="text-xs text-emerald-500 dark:text-emerald-400 mt-1 flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">timer</span>
                  {{ $t('driveView.editedForDuration', { duration: formatEditingDuration(version.editing_duration_seconds) }) }}
                </p>
              </div>

              <!-- Actions (hidden while this row's label is being edited so
                   the inline input + accept/cancel get the full row width) -->
              <div
                v-if="!(version.id && labelEditingId === version.id)"
                class="flex flex-col items-end gap-1"
                @click.stop
              >
                <div class="flex items-center gap-1">
                  <button
                    @click="openPreview(version)"
                    class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-lg transition-colors"
                    :title="$t('versionsPanel.preview')"
                  >
                    <span class="material-symbols-rounded text-lg text-surface-600 dark:text-surface-400">visibility</span>
                  </button>
                  <button
                    @click="download(version)"
                    class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-lg transition-colors"
                    :title="$t('driveView.downloadThisVersion')"
                  >
                    <span class="material-symbols-rounded text-lg text-surface-600 dark:text-surface-400">download</span>
                  </button>
                </div>
                <div v-if="!version.is_current && version.id" class="flex items-center gap-1">
                  <button
                    @click="togglePin(version)"
                    class="p-1.5 hover:bg-amber-100 dark:hover:bg-amber-500/20 rounded-lg transition-colors"
                    :title="Number(version.is_pinned) ? $t('versionsPanel.unpinVersion') : $t('versionsPanel.pinVersion')"
                  >
                    <span :class="['material-symbols-rounded text-lg', Number(version.is_pinned) ? 'text-amber-500' : 'text-surface-400']">keep</span>
                  </button>
                  <button
                    v-if="!version.label"
                    @click="startLabelEdit(version)"
                    class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-lg transition-colors"
                    :title="$t('versionsPanel.addLabel')"
                  >
                    <span class="material-symbols-rounded text-lg text-surface-400">sell</span>
                  </button>
                  <button
                    @click="restore(version)"
                    class="p-1.5 hover:bg-primary-100 dark:hover:bg-primary-500/20 rounded-lg transition-colors"
                    :title="$t('driveView.restoreThisVersion')"
                  >
                    <span class="material-symbols-rounded text-lg text-primary-600 dark:text-primary-400">settings_backup_restore</span>
                  </button>
                  <button
                    @click="remove(version)"
                    class="p-1.5 hover:bg-red-100 dark:hover:bg-red-500/20 rounded-lg transition-colors"
                    :title="$t('driveView.deleteThisVersion')"
                  >
                    <span class="material-symbols-rounded text-lg text-red-500">delete</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Footer: storage usage + cleanup -->
    <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 bg-surface-100 dark:bg-surface-900/50 space-y-2">
      <div v-if="historyCount > 0" class="flex items-center justify-between text-xs text-surface-500">
        <span>
          {{ $t('versionsPanel.versionsCount', historyCount, { count: historyCount }) }}
          · {{ formatSize(historyBytes) }}
          <template v-if="pinnedCount > 0"> · {{ $t('versionsPanel.pinnedCount', { count: pinnedCount }) }}</template>
        </span>
        <button
          v-if="historyCount > pinnedCount"
          @click="confirmCleanup = 'file'"
          class="text-red-500 hover:text-red-600 font-medium"
        >
          {{ $t('versionsPanel.cleanupOld') }}
        </button>
      </div>
      <div v-if="accountUsage" class="flex items-center justify-between text-xs text-surface-400">
        <span>{{ $t('versionsPanel.accountVersionsUsage', { size: formatSize(accountUsage.version_bytes) }) }}</span>
        <button
          v-if="accountUsage.version_count > accountUsage.pinned_count"
          @click="confirmCleanup = 'all'"
          class="text-red-500 hover:text-red-600 font-medium whitespace-nowrap ml-2"
        >
          {{ $t('versionsPanel.cleanupAll') }}
        </button>
      </div>
      <p class="text-[11px] leading-snug text-surface-400">{{ $t('versionsPanel.retentionHint') }}</p>
    </div>

    <!-- Cleanup confirmation (per-file or account-wide) -->
    <Teleport to="body">
      <div v-if="confirmCleanup" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50" @click.self="confirmCleanup = null">
        <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 w-full max-w-md shadow-xl mx-4">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-xl text-red-500">delete_sweep</span>
            </div>
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
              {{ confirmCleanup === 'all' ? $t('versionsPanel.cleanupAllConfirmTitle') : $t('versionsPanel.cleanupConfirmTitle') }}
            </h3>
          </div>
          <p class="text-surface-600 dark:text-surface-400 mb-6">
            {{ confirmCleanup === 'all' ? $t('versionsPanel.cleanupAllConfirmText') : $t('versionsPanel.cleanupConfirmText') }}
          </p>
          <div class="flex justify-end gap-3">
            <button @click="confirmCleanup = null" class="btn-secondary">{{ $t('driveView.cancel') }}</button>
            <button @click="runCleanup" class="btn-primary !bg-red-500 hover:!bg-red-600" :disabled="cleaningUp">
              <span class="material-symbols-rounded">delete_sweep</span>
              {{ confirmCleanup === 'all' ? $t('versionsPanel.cleanupAll') : $t('versionsPanel.cleanupOld') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Two-version compare modal -->
    <VersionCompareModal
      :show="compare.show"
      :file="file"
      :left-version="compare.left"
      :right-version="compare.right"
      :versions="versions"
      @close="compare.show = false"
      @restored="onCompareRestored"
    />

    <!-- Single-version preview overlay -->
    <Teleport to="body">
      <div v-if="preview.show" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60" @click.self="closePreview">
        <div
          :class="[
            'bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-h-[85vh] mx-4 flex flex-col overflow-hidden',
            ['spreadsheet', 'docx'].includes(preview.data?.type) ? 'max-w-5xl' : 'max-w-3xl'
          ]"
        >
          <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-primary-500">visibility</span>
              <span class="font-medium text-surface-900 dark:text-surface-100 truncate">
                {{ file?.original_name }} · {{ $t('driveView.versionNumber', { number: preview.version?.version_number }) }}
              </span>
            </div>
            <button @click="closePreview" class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg">
              <span class="material-symbols-rounded text-surface-500">close</span>
            </button>
          </div>
          <div class="flex-1 overflow-auto p-4 min-h-[200px] flex items-center justify-center">
            <div v-if="preview.loading" class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-500"></div>
            <VersionPreviewContent v-else-if="preview.data" :data="preview.data">
              <template #unsupported>
                <div class="flex flex-col items-center text-center py-8">
                  <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">visibility_off</span>
                  <p class="text-surface-500">{{ $t('versionsPanel.previewUnavailable') }}</p>
                  <button @click="download(preview.version)" class="btn-primary mt-4">
                    <span class="material-symbols-rounded">download</span>
                    {{ $t('driveView.downloadThisVersion') }}
                  </button>
                </div>
              </template>
            </VersionPreviewContent>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDriveStore } from '@/stores/drive'
import { useToastStore } from '@/stores/toast'
import { getToken } from '@/services/tokenStorage'
import VersionCompareModal from '@/components/drive/VersionCompareModal.vue'
import VersionPreviewContent from '@/components/drive/versions/VersionPreviewContent.vue'

const props = defineProps({
  file: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const { t, locale } = useI18n()
const drive = useDriveStore()
const toast = useToastStore()

const versions = ref([])
const loading = ref(false)
const selectedVersions = ref([])
const accountUsage = ref(null)
const confirmCleanup = ref(null) // null | 'file' | 'all'
const cleaningUp = ref(false)
const labelEditingId = ref(null)
const labelDraft = ref('')
const preview = ref({ show: false, loading: false, data: null, version: null })
const compare = ref({ show: false, left: null, right: null })

const vFocus = { mounted: (el) => el.focus() }

const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

const historyVersions = computed(() => versions.value.filter((v) => !v.is_current && v.id))
const historyCount = computed(() => historyVersions.value.length)
const historyBytes = computed(() => historyVersions.value.reduce((sum, v) => sum + Number(v.size || 0), 0))
const pinnedCount = computed(() => historyVersions.value.filter((v) => Number(v.is_pinned)).length)

const fileIcon = computed(() => getFileIconInfo(props.file?.mime_type))

watch(() => props.file?.id, load, { immediate: false })
onMounted(load)

async function load() {
  if (!props.file?.id) return
  loading.value = true
  selectedVersions.value = []
  try {
    versions.value = await drive.fetchFileVersions(props.file.id)
    drive.fetchVersionsUsage().then((u) => { accountUsage.value = u })
  } finally {
    loading.value = false
  }
}

// ── Selection / compare ──

function toggleSelect(version) {
  const key = version.id || 'current'
  const idx = selectedVersions.value.findIndex((v) => (v.id || 'current') === key)
  if (idx >= 0) {
    selectedVersions.value.splice(idx, 1)
  } else {
    if (selectedVersions.value.length >= 2) selectedVersions.value.shift()
    selectedVersions.value.push(version)
  }
}

function isSelected(version) {
  const key = version.id || 'current'
  return selectedVersions.value.some((v) => (v.id || 'current') === key)
}

function emitCompare() {
  if (selectedVersions.value.length !== 2) return
  const sorted = [...selectedVersions.value].sort((a, b) => a.version_number - b.version_number)
  compare.value = { show: true, left: sorted[0], right: sorted[1] }
}

async function onCompareRestored() {
  compare.value = { show: false, left: null, right: null }
  await load()
  drive.fetchContents(drive.currentFolderId)
}

// ── Actions ──

async function restore(version) {
  if (version.is_current || !version.id) {
    toast.info(t('driveView.thisIsAlreadyTheCurrent'))
    return
  }
  const success = await drive.restoreVersion(props.file.id, version.id)
  if (success) {
    toast.success(t('driveView.versionRestoredSuccessfully'))
    await load()
  } else {
    toast.error(t('driveView.failedToRestoreVersion'))
  }
}

async function remove(version) {
  if (version.is_current || !version.id) {
    toast.error(t('driveView.cannotDeleteTheCurrentVersion'))
    return
  }
  const success = await drive.deleteVersion(props.file.id, version.id)
  if (success) {
    toast.success(t('driveView.versionDeleted'))
    versions.value = versions.value.filter((v) => v.id !== version.id)
  } else {
    toast.error(t('driveView.failedToDeleteVersion'))
  }
}

function download(version) {
  const token = getToken('webmail_token')
  if (version.is_current || !version.id) {
    triggerDownload(drive.getDownloadUrl(props.file.id), token, props.file.original_name || 'file')
    return
  }
  const url = drive.getVersionDownloadUrl(props.file.id, version.id)
  triggerDownload(url, token, `v${version.version_number}_${props.file?.original_name || 'file'}`)
}

function triggerDownload(url, token, downloadName) {
  fetch(url, { headers: { Authorization: `Bearer ${token}` } })
    .then((res) => res.blob())
    .then((blob) => {
      const downloadUrl = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = downloadUrl
      a.download = downloadName
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(downloadUrl)
    })
    .catch(() => toast.error(t('driveView.failedToDownloadVersion')))
}

async function togglePin(version) {
  const next = !Number(version.is_pinned)
  const success = await drive.pinVersion(props.file.id, version.id, next)
  if (success) {
    version.is_pinned = next ? 1 : 0
  } else {
    toast.error(t('versionsPanel.pinFailed'))
  }
}

function startLabelEdit(version) {
  if (!version.id) return
  labelEditingId.value = version.id
  labelDraft.value = version.label || ''
}

async function saveLabel(version) {
  if (!version.id) return
  const label = labelDraft.value.trim() || null
  const success = await drive.setVersionLabel(props.file.id, version.id, label)
  if (success) {
    version.label = label
    toast.success(t('versionsPanel.labelSaved'))
  } else {
    toast.error(t('versionsPanel.labelFailed'))
  }
  labelEditingId.value = null
}

async function openPreview(version) {
  preview.value = { show: true, loading: true, data: null, version }
  try {
    const versionId = version.is_current || !version.id ? 'current' : version.id
    preview.value.data = await drive.fetchVersionPreview(props.file.id, versionId)
  } catch (e) {
    preview.value.data = { type: 'unsupported' }
  } finally {
    preview.value.loading = false
  }
}

function closePreview() {
  preview.value = { show: false, loading: false, data: null, version: null }
}

async function runCleanup() {
  const scope = confirmCleanup.value
  cleaningUp.value = true
  try {
    const result = scope === 'all'
      ? await drive.cleanupAllVersions()
      : await drive.cleanupFileVersions(props.file.id)
    if (result) {
      toast.success(t('versionsPanel.cleanupDone', { count: result.deleted, size: formatSize(result.freed_bytes) }))
      confirmCleanup.value = null
      await load()
    } else {
      toast.error(t('versionsPanel.cleanupFailed'))
    }
  } finally {
    cleaningUp.value = false
  }
}

// ── Formatters ──

function formatSize(bytes) {
  if (!bytes) return '0 B'
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB'
  if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB'
  return bytes + ' B'
}

function formatVersionDate(dateStr) {
  if (!dateStr) return t('driveView.unknown')
  const date = new Date(dateStr)
  const diffDays = Math.floor((new Date() - date) / 86400000)
  if (diffDays === 0) {
    return t('driveView.todayAt', { time: date.toLocaleTimeString(localeTag.value, { hour: '2-digit', minute: '2-digit' }) })
  }
  if (diffDays === 1) {
    return t('driveView.yesterdayAt', { time: date.toLocaleTimeString(localeTag.value, { hour: '2-digit', minute: '2-digit' }) })
  }
  if (diffDays < 7) {
    return t('driveView.daysAgo', diffDays, { count: diffDays })
  }
  return date.toLocaleDateString(localeTag.value, { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatEditingDuration(seconds) {
  if (!seconds) return null
  const hours = Math.floor(seconds / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  if (hours > 0) return `${hours}h ${mins}m`
  if (mins > 0) return `${mins}m ${secs}s`
  return `${secs}s`
}

function getFileIconInfo(mimeType) {
  if (mimeType?.startsWith('image/')) return { icon: 'image', color: 'text-pink-500', bgColor: 'bg-pink-100 dark:bg-pink-500/20' }
  if (mimeType?.startsWith('video/')) return { icon: 'movie', color: 'text-purple-500', bgColor: 'bg-purple-100 dark:bg-purple-500/20' }
  if (mimeType?.startsWith('audio/')) return { icon: 'audio_file', color: 'text-violet-500', bgColor: 'bg-violet-100 dark:bg-violet-500/20' }
  if (mimeType?.includes('pdf')) return { icon: 'picture_as_pdf', color: 'text-red-500', bgColor: 'bg-red-100 dark:bg-red-500/20' }
  if (mimeType?.includes('spreadsheet') || mimeType?.includes('sheet') || mimeType?.includes('excel') || mimeType?.includes('csv')) return { icon: 'table_chart', color: 'text-green-600', bgColor: 'bg-green-100 dark:bg-green-500/20' }
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) return { icon: 'slideshow', color: 'text-orange-500', bgColor: 'bg-orange-100 dark:bg-orange-500/20' }
  if (mimeType?.includes('word') || mimeType?.includes('msword') || mimeType?.includes('wordprocessing')) return { icon: 'description', color: 'text-blue-600', bgColor: 'bg-blue-100 dark:bg-blue-500/20' }
  if (mimeType?.includes('zip') || mimeType?.includes('compressed') || mimeType?.includes('archive')) return { icon: 'folder_zip', color: 'text-amber-600', bgColor: 'bg-amber-100 dark:bg-amber-500/20' }
  if (mimeType?.includes('text/')) return { icon: 'article', color: 'text-slate-500', bgColor: 'bg-slate-100 dark:bg-slate-500/20' }
  return { icon: 'draft', color: 'text-surface-500', bgColor: 'bg-surface-100 dark:bg-surface-500/20' }
}
</script>
