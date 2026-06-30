<script setup>
/**
 * ShareGuestLinksTab - guest link management for office-editable files.
 *
 * Token links (view/edit, no login) served at /guest/office/<token>.
 * Extracted from the former OfficeShareModal so it can live inside the
 * unified Collaborate tab. File-only.
 */
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToastStore } from '@/stores/toast'
import { officeApi } from '@/services/officeApiService'
import { getPublicOrigin } from '@/services/serverRegistry'

const props = defineProps({
  fileId: { type: Number, required: true },
})

const emit = defineEmits(['changed'])

const { t } = useI18n()
const toast = useToastStore()

const links = ref([])
const loading = ref(true)
const creating = ref(false)
const newRole = ref('viewer')
const newExpiry = ref(168) // hours; 0 = never

const expiryOptions = [
  { value: 24, labelKey: 'officeEditor.expiry1Day' },
  { value: 168, labelKey: 'officeEditor.expiry7Days' },
  { value: 720, labelKey: 'officeEditor.expiry30Days' },
  { value: 0, labelKey: 'officeEditor.expiryNever' },
]

async function loadLinks() {
  loading.value = true
  try {
    const res = await officeApi.listGuestLinks(props.fileId)
    links.value = res.data?.data?.links || []
  } catch (e) {
    console.error('[Office] Failed to load guest links', e)
  } finally {
    loading.value = false
  }
}

async function createLink() {
  creating.value = true
  try {
    const res = await officeApi.createGuestLink(props.fileId, {
      role: newRole.value,
      expiresInHours: newExpiry.value || null,
    })
    const link = res.data?.data?.link
    if (link?.url) {
      await copyToClipboard(link.url)
      toast.success(t('officeEditor.linkCreatedAndCopied'))
    }
    await loadLinks()
    emit('changed')
  } catch (e) {
    console.error('[Office] Failed to create guest link', e)
    toast.error(e?.response?.data?.message || t('officeEditor.linkCreateFailed'))
  } finally {
    creating.value = false
  }
}

async function revokeLink(token) {
  try {
    await officeApi.revokeGuestLink(token)
    links.value = links.value.filter((l) => l.token !== token)
    toast.success(t('officeEditor.linkRevoked'))
    emit('changed')
  } catch (e) {
    console.error('[Office] Failed to revoke guest link', e)
    toast.error(t('officeEditor.linkRevokeFailed'))
  }
}

function linkUrl(link) {
  return `${getPublicOrigin()}/guest/office/${link.token}`
}

async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text)
  } catch (e) {
    const el = document.createElement('textarea')
    el.value = text
    document.body.appendChild(el)
    el.select()
    document.execCommand('copy')
    document.body.removeChild(el)
  }
}

async function copyLink(link) {
  await copyToClipboard(linkUrl(link))
  toast.success(t('officeEditor.linkCopied'))
}

function formatExpiry(link) {
  if (!link.expires_at) return t('officeEditor.expiryNever')
  return new Date(link.expires_at.replace(' ', 'T')).toLocaleString()
}

onMounted(loadLinks)
</script>

<template>
  <div class="space-y-5">
    <!-- Team access note -->
    <div class="flex items-start gap-2.5 p-3 rounded-xl bg-surface-50 dark:bg-surface-800/60 text-sm text-surface-600 dark:text-surface-300">
      <span class="material-symbols-rounded text-lg text-surface-400 mt-0.5">group</span>
      <p>{{ t('officeEditor.teamAccessNote') }}</p>
    </div>

    <!-- Create link -->
    <div>
      <h3 class="text-sm font-medium text-surface-800 dark:text-surface-200 mb-2">{{ t('officeEditor.createGuestLink') }}</h3>
      <div class="flex flex-wrap items-center gap-2">
        <select v-model="newRole" class="flex-1 min-w-[120px] h-10 px-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 text-sm text-surface-900 dark:text-surface-100">
          <option value="viewer">{{ t('officeEditor.roleViewer') }}</option>
          <option value="editor">{{ t('officeEditor.roleEditor') }}</option>
        </select>
        <select v-model.number="newExpiry" class="flex-1 min-w-[120px] h-10 px-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 text-sm text-surface-900 dark:text-surface-100">
          <option v-for="opt in expiryOptions" :key="opt.value" :value="opt.value">{{ t(opt.labelKey) }}</option>
        </select>
        <button
          @click="createLink"
          :disabled="creating"
          class="h-10 px-4 rounded-lg bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium flex items-center gap-1.5"
        >
          <span class="material-symbols-rounded text-lg">{{ creating ? 'progress_activity' : 'add_link' }}</span>
          {{ t('officeEditor.createLink') }}
        </button>
      </div>
    </div>

    <!-- Existing links -->
    <div>
      <h3 class="text-sm font-medium text-surface-800 dark:text-surface-200 mb-2">{{ t('officeEditor.activeLinks') }}</h3>
      <div v-if="loading" class="py-6 flex justify-center">
        <span class="material-symbols-rounded text-2xl text-primary-500 animate-spin">progress_activity</span>
      </div>
      <p v-else-if="!links.length" class="text-sm text-surface-500 py-2">{{ t('officeEditor.noLinksYet') }}</p>
      <ul v-else class="space-y-2">
        <li
          v-for="link in links"
          :key="link.token"
          class="flex items-center gap-2 p-2.5 rounded-xl border border-surface-200 dark:border-surface-700"
        >
          <span
            class="text-[11px] px-2 py-0.5 rounded-full font-medium"
            :class="link.role === 'editor'
              ? 'bg-primary-100 dark:bg-primary-500/15 text-primary-700 dark:text-primary-300'
              : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-300'"
          >
            {{ link.role === 'editor' ? t('officeEditor.roleEditor') : t('officeEditor.roleViewer') }}
          </span>
          <div class="flex-1 min-w-0">
            <p class="text-xs text-surface-700 dark:text-surface-200 truncate font-mono">{{ linkUrl(link) }}</p>
            <p class="text-[11px]" :class="link.expired ? 'text-red-500' : 'text-surface-400'">
              {{ link.expired ? t('officeEditor.expired') : t('officeEditor.expires') + ': ' + formatExpiry(link) }}
            </p>
          </div>
          <button
            @click="copyLink(link)"
            class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-800 text-surface-500"
            :title="t('officeEditor.copyLink')"
          >
            <span class="material-symbols-rounded text-lg">content_copy</span>
          </button>
          <button
            @click="revokeLink(link.token)"
            class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-red-50 dark:hover:bg-red-500/10 text-red-500"
            :title="t('officeEditor.revokeLink')"
          >
            <span class="material-symbols-rounded text-lg">link_off</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
