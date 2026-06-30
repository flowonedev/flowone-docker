<script setup>
/**
 * ShareLinkTab - Tab 1 of the UnifiedShareModal.
 *
 * Public token link to view/download a file or folder (password / expiry /
 * download limit), plus an optional picker to notify colleagues/groups about
 * the link. Self-hydrates its current link state on mount (lazy).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDriveStore } from '@/stores/drive'
import { useToastStore } from '@/stores/toast'
import { useShareModalStore } from '@/stores/shareModal'
import { getShareState } from '@/services/driveShareApi'
import ShareNotifyPicker from '@/components/drive/share/ShareNotifyPicker.vue'

const props = defineProps({
  item: { type: Object, required: true },
  type: { type: String, default: 'file' }, // 'file' | 'folder'
})

const { t } = useI18n()
const drive = useDriveStore()
const toast = useToastStore()
const shareModal = useShareModalStore()

const isFolder = computed(() => props.type === 'folder')

const loading = ref(true)
const creating = ref(false)
const hasLink = ref(false)
const shareUrl = ref('')

const expiry = ref(null)
const maxDownloads = ref(null)
const password = ref('')
const showPassword = ref(false)

const expiryOptions = [
  { value: null, label: t('unifiedShare.expiryNever') },
  { value: 1, label: t('unifiedShare.expiry1Hour') },
  { value: 24, label: t('unifiedShare.expiry1Day') },
  { value: 168, label: t('unifiedShare.expiry7Days') },
  { value: 720, label: t('unifiedShare.expiry30Days') },
  { value: 2160, label: t('unifiedShare.expiry90Days') },
]

const downloadOptions = [
  { value: null, label: t('unifiedShare.downloadsUnlimited') },
  { value: 1, label: t('unifiedShare.downloads1') },
  { value: 5, label: t('unifiedShare.downloads5') },
  { value: 10, label: t('unifiedShare.downloads10') },
  { value: 25, label: t('unifiedShare.downloads25') },
  { value: 100, label: t('unifiedShare.downloads100') },
]

async function loadState() {
  loading.value = true
  try {
    const state = await getShareState(props.type, props.item.id)
    if (state?.is_shared) {
      hasLink.value = true
      shareUrl.value = state.url || ''
    } else {
      hasLink.value = false
      shareUrl.value = ''
    }
  } finally {
    loading.value = false
  }
}

async function createLink() {
  creating.value = true
  try {
    const result = isFolder.value
      ? await drive.createFolderShareLink(props.item.id, expiry.value, maxDownloads.value, password.value || null)
      : await drive.createShareLink(props.item.id, expiry.value, maxDownloads.value, password.value || null)

    if (result.success) {
      shareUrl.value = result.url
      hasLink.value = true
      toast.success(t('unifiedShare.linkCreatedToast'))
      shareModal.notifyUpdated()
    } else {
      toast.error(result.error || t('unifiedShare.linkCreateFailed'))
    }
  } finally {
    creating.value = false
  }
}

async function removeLink() {
  const ok = isFolder.value
    ? await drive.removeFolderShareLink(props.item.id)
    : await drive.removeShareLink(props.item.id)

  if (ok) {
    hasLink.value = false
    shareUrl.value = ''
    password.value = ''
    maxDownloads.value = null
    expiry.value = null
    toast.success(t('unifiedShare.linkRemovedToast'))
    shareModal.notifyUpdated()
  } else {
    toast.error(t('unifiedShare.linkRemoveFailed'))
  }
}

function copyLink() {
  if (!shareUrl.value) return
  navigator.clipboard.writeText(shareUrl.value)
  toast.success(t('unifiedShare.linkCopiedToast'))
}

onMounted(loadState)
</script>

<template>
  <div class="space-y-4">
    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-8">
      <span class="material-symbols-rounded animate-spin text-2xl text-primary-500">progress_activity</span>
    </div>

    <!-- Existing link -->
    <template v-else-if="hasLink">
      <div class="flex items-center gap-2">
        <input :value="shareUrl" readonly class="input flex-1 text-sm bg-surface-50 dark:bg-surface-900/50" />
        <button @click="copyLink" class="btn-primary px-4">
          <span class="material-symbols-rounded">content_copy</span>
        </button>
      </div>

      <div class="flex items-center gap-2 text-sm text-surface-500">
        <span class="material-symbols-rounded text-lg text-green-500">check_circle</span>
        <span>{{ isFolder ? t('unifiedShare.anyoneCanView') : t('unifiedShare.anyoneCanDownload') }}</span>
      </div>

      <button
        @click="removeLink"
        class="w-full btn-secondary text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
      >
        <span class="material-symbols-rounded">link_off</span>
        {{ t('unifiedShare.removeLink') }}
      </button>

      <!-- Notify colleagues / groups about the link -->
      <ShareNotifyPicker :target-type="type" :item-id="item.id" />
    </template>

    <!-- Create link -->
    <template v-else>
      <p class="text-sm text-surface-600 dark:text-surface-400">
        {{ t('unifiedShare.linkTabDesc') }}
      </p>

      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
          {{ t('unifiedShare.linkExpires') }}
        </label>
        <select v-model="expiry" class="input w-full">
          <option v-for="opt in expiryOptions" :key="String(opt.value)" :value="opt.value">{{ opt.label }}</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
          {{ t('unifiedShare.downloadLimit') }}
        </label>
        <select v-model="maxDownloads" class="input w-full">
          <option v-for="opt in downloadOptions" :key="String(opt.value)" :value="opt.value">{{ opt.label }}</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
          {{ t('unifiedShare.passwordOptional') }}
        </label>
        <div class="relative">
          <input
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            name="flowone-share-link-password"
            autocomplete="new-password"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
            data-1p-ignore
            data-lpignore="true"
            data-form-type="other"
            class="input w-full pr-10"
            :placeholder="t('unifiedShare.passwordPlaceholder')"
          />
          <button
            type="button"
            @click="showPassword = !showPassword"
            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-surface-400 hover:text-surface-600"
          >
            <span class="material-symbols-rounded text-lg">{{ showPassword ? 'visibility_off' : 'visibility' }}</span>
          </button>
        </div>
      </div>

      <button @click="createLink" :disabled="creating" class="w-full btn-primary">
        <span v-if="creating" class="material-symbols-rounded animate-spin">progress_activity</span>
        <span v-else class="material-symbols-rounded">link</span>
        {{ t('unifiedShare.createLink') }}
      </button>
    </template>
  </div>
</template>
