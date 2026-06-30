<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getApiOrigin } from '@/services/serverRegistry'

const route = useRoute()
const token = computed(() => String(route.params.token || ''))

const loading = ref(true)
const error = ref('')
const payload = ref(null)
const password = ref('')
const validated = ref(false)
const validating = ref(false)
const previewFile = ref(null)

const apiBase = computed(() => `${getApiOrigin()}/api/project-hub/share/${token.value}`)

const share = computed(() => payload.value?.share || null)
const files = computed(() => payload.value?.files || [])
const needsPassword = computed(() => !!share.value?.requires_password)

async function loadInfo() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch(`${apiBase.value}/info`, { credentials: 'omit' })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      error.value = data?.error || `Unable to load share (${res.status})`
      payload.value = null
      return
    }
    if (data.success && data.data) {
      payload.value = data.data
    } else {
      error.value = data?.error || 'Invalid response'
    }
  } catch {
    error.value = 'Network error'
  } finally {
    loading.value = false
  }
}

async function submitPassword() {
  validating.value = true
  error.value = ''
  try {
    const res = await fetch(`${apiBase.value}/validate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: password.value }),
      credentials: 'omit',
    })
    const data = await res.json().catch(() => ({}))
    if (res.ok && data.success) {
      validated.value = true
      await loadInfo()
    } else {
      error.value = data?.error === 'rate_limited' ? 'Too many attempts. Try later.' : 'Invalid password'
    }
  } catch {
    error.value = 'Network error'
  } finally {
    validating.value = false
  }
}

function fileDownloadHref(fid) {
  const q = new URLSearchParams()
  if (password.value) q.set('p', password.value)
  const qs = q.toString()
  return `${apiBase.value}/download/${fid}${qs ? `?${qs}` : ''}`
}

function previewHref(file) {
  const mime = String(file.mime_type || '')
  if (mime === 'application/pdf' || mime.startsWith('image/')) {
    const q = new URLSearchParams({ preview: '1' })
    if (password.value) q.set('p', password.value)
    return `${apiBase.value}/download/${file.drive_file_id}?${q.toString()}`
  }
  return null
}

function openPreview(file) {
  previewFile.value = previewHref(file) ? file : null
}

onMounted(loadInfo)
watch(token, () => {
  validated.value = false
  password.value = ''
  previewFile.value = null
  loadInfo()
})
</script>

<template>
  <div class="min-h-screen bg-surface-50 dark:bg-surface-900 text-surface-800 dark:text-surface-100 flex flex-col">
    <header class="border-b border-surface-200 dark:border-surface-700 px-4 py-3 bg-white dark:bg-surface-800">
      <h1 class="text-lg font-semibold">Shared deliverables</h1>
      <p v-if="share?.title" class="text-sm text-surface-500 mt-0.5">{{ share.title }}</p>
    </header>

    <main class="flex-1 max-w-3xl mx-auto w-full p-4 space-y-4">
      <div v-if="loading" class="flex justify-center py-16">
        <span class="material-symbols-rounded animate-spin text-primary-500 text-3xl">progress_activity</span>
      </div>

      <div v-else-if="error" class="rounded-xl border border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-950/30 p-4 text-sm text-red-700 dark:text-red-300">
        {{ error }}
      </div>

      <template v-else>
        <div v-if="share?.message" class="text-sm text-surface-600 dark:text-surface-300 whitespace-pre-wrap border border-surface-200 dark:border-surface-700 rounded-xl p-3 bg-white dark:bg-surface-800">
          {{ share.message }}
        </div>

        <div
          v-if="needsPassword && !validated"
          class="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/20 p-4 space-y-3"
        >
          <p class="text-xs text-amber-800 dark:text-amber-200">This share is password-protected. Enter the password to download or preview files.</p>
          <input
            v-model="password"
            type="password"
            class="w-full rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-900 px-3 py-2 text-sm"
            @keydown.enter.prevent="submitPassword"
          />
          <button
            type="button"
            class="w-full py-2 rounded-lg bg-primary-500 text-white text-sm font-medium disabled:opacity-50"
            :disabled="validating"
            @click="submitPassword"
          >
            {{ validating ? 'Checking…' : 'Unlock downloads' }}
          </button>
        </div>

        <template v-if="files.length">
          <ul class="space-y-2">
            <li
              v-for="f in files"
              :key="f.drive_file_id"
              class="flex items-center justify-between gap-2 rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 px-3 py-2"
            >
              <div class="min-w-0">
                <div class="text-sm font-medium truncate">{{ f.original_name || 'File' }}</div>
                <div v-if="f.unavailable" class="text-[10px] text-amber-600">Unavailable</div>
              </div>
              <div class="flex items-center gap-1 shrink-0">
                <button
                  v-if="previewHref(f) && (!needsPassword || validated)"
                  type="button"
                  class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500"
                  title="Preview"
                  @click="openPreview(f)"
                >
                  <span class="material-symbols-rounded text-lg">visibility</span>
                </button>
                <a
                  v-if="!f.unavailable && (!needsPassword || validated)"
                  class="p-1.5 rounded-lg bg-primary-500 text-white"
                  :href="fileDownloadHref(f.drive_file_id)"
                  target="_blank"
                  rel="noopener"
                  title="Download"
                >
                  <span class="material-symbols-rounded text-lg">download</span>
                </a>
                <span v-else-if="needsPassword && !validated && !f.unavailable" class="text-[10px] text-surface-400 px-1">Locked</span>
              </div>
            </li>
          </ul>

          <div v-if="previewFile" class="rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden bg-black/5 dark:bg-black/30">
            <div class="flex items-center justify-between px-2 py-1 border-b border-surface-200 dark:border-surface-700 text-xs">
              <span class="truncate">{{ previewFile.original_name }}</span>
              <button type="button" class="p-1" @click="previewFile = null">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </div>
            <iframe
              v-if="previewHref(previewFile)"
              class="w-full h-[70vh] bg-white"
              :src="previewHref(previewFile)"
              title="Preview"
            />
          </div>
        </template>
      </template>
    </main>
  </div>
</template>
