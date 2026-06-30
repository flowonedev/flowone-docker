<script setup>
import { ref, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()

const props = defineProps({
  show: { type: Boolean, default: false },
  cardId: { type: Number, required: true },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const creating = ref(false)
const files = ref([])
const selected = ref(new Set())
const title = ref('')
const message = ref('')
const expiresAt = ref('')
const maxDownloads = ref('')
const password = ref('')
const lastLink = ref('')

async function loadFiles() {
  loading.value = true
  try {
    const { data } = await api.get(`/project-hub/cards/${props.cardId}/drive-files`)
    files.value = data.files || data?.data?.files || []
  } catch {
    files.value = []
    toast.error('Could not load tagged Drive files for this card')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.show,
  (v) => {
    if (v) {
      selected.value = new Set()
      title.value = ''
      message.value = ''
      expiresAt.value = ''
      maxDownloads.value = ''
      password.value = ''
      lastLink.value = ''
      loadFiles()
    }
  },
)

function toggleFile(id) {
  const fid = Number(id)
  const next = new Set(selected.value)
  if (next.has(fid)) next.delete(fid)
  else next.add(fid)
  selected.value = next
}

function close() {
  emit('close')
}

async function createShare() {
  if (selected.value.size === 0) {
    toast.error('Select at least one file')
    return
  }
  creating.value = true
  try {
    const payload = {
      drive_file_ids: [...selected.value],
      title: title.value || null,
      message: message.value || null,
      expires_at: expiresAt.value || null,
      max_downloads: maxDownloads.value === '' ? null : Number(maxDownloads.value),
      password: password.value || null,
    }
    const { data, status } = await api.post(`/project-hub/cards/${props.cardId}/shares`, payload)
    if (status === 201 && data?.share?.share_token) {
      const token = data.share.share_token
      lastLink.value = `${window.location.origin}/share/c/${token}`
      toast.success('Share link created')
    } else {
      toast.error(data?.error || 'Failed to create share')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to create share')
  } finally {
    creating.value = false
  }
}

async function copyLink() {
  if (!lastLink.value) return
  try {
    await navigator.clipboard.writeText(lastLink.value)
    toast.success('Link copied')
  } catch {
    toast.error('Copy failed — copy manually')
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4"
      @click.self="close"
    >
      <div
        class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-hidden flex flex-col border border-surface-200 dark:border-surface-700"
        role="dialog"
        aria-modal="true"
      >
        <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
          <h3 class="text-base font-semibold text-surface-800 dark:text-surface-100">Share deliverables</h3>
          <button type="button" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700" @click="close">
            <span class="material-symbols-rounded text-surface-500">close</span>
          </button>
        </div>

        <div class="p-5 overflow-y-auto space-y-4 text-sm">
          <p class="text-xs text-surface-500">
            Only files tagged with <code class="text-[10px] bg-surface-100 dark:bg-surface-700 px-1 rounded">[PH-{{ cardId }}]</code> in your Drive appear here.
          </p>

          <div v-if="loading" class="flex justify-center py-6">
            <span class="material-symbols-rounded animate-spin text-primary-500">progress_activity</span>
          </div>
          <div v-else-if="!files.length" class="text-center text-surface-500 text-xs py-4">
            No tagged files found. Tag uploads on the card with the PH tag in Drive.
          </div>
          <ul v-else class="max-h-40 overflow-y-auto space-y-1 border border-surface-200 dark:border-surface-600 rounded-lg">
            <li
              v-for="f in files"
              :key="f.id"
              class="flex items-center gap-2 px-2 py-1.5 hover:bg-surface-50 dark:hover:bg-surface-700/40 cursor-pointer"
              @click="toggleFile(f.id)"
            >
              <input type="checkbox" class="rounded border-surface-300" :checked="selected.has(Number(f.id))" @click.stop="toggleFile(f.id)" />
              <span class="truncate text-surface-800 dark:text-surface-200">{{ f.original_name }}</span>
            </li>
          </ul>

          <div class="grid gap-2">
            <label class="text-xs font-medium text-surface-500">Title (optional)</label>
            <input v-model="title" class="input-ph" type="text" placeholder="e.g. Q1 deliverables" />
          </div>
          <div class="grid gap-2">
            <label class="text-xs font-medium text-surface-500">Message (optional)</label>
            <textarea v-model="message" rows="2" class="input-ph" placeholder="Note for the client"></textarea>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1">
              <label class="text-xs font-medium text-surface-500">Expires</label>
              <input v-model="expiresAt" type="datetime-local" class="input-ph" />
            </div>
            <div class="grid gap-1">
              <label class="text-xs font-medium text-surface-500">Max downloads</label>
              <input v-model="maxDownloads" type="number" min="0" class="input-ph" placeholder="Unlimited" />
            </div>
          </div>
          <div class="grid gap-2">
            <label class="text-xs font-medium text-surface-500">Password (optional)</label>
            <input v-model="password" type="password" class="input-ph" autocomplete="new-password" />
          </div>

          <div v-if="lastLink" class="rounded-lg bg-surface-100 dark:bg-surface-900 p-3 space-y-2">
            <div class="text-xs text-surface-600 dark:text-surface-300 break-all">{{ lastLink }}</div>
            <button type="button" class="w-full py-2 rounded-lg bg-primary-500 text-white text-xs font-medium" @click="copyLink">
              Copy link
            </button>
          </div>
        </div>

        <div class="px-5 py-3 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
          <button type="button" class="px-3 py-1.5 rounded-lg text-xs text-surface-600 hover:bg-surface-100 dark:hover:bg-surface-700" @click="close">
            Close
          </button>
          <button
            type="button"
            class="px-4 py-1.5 rounded-lg text-xs font-medium bg-primary-500 text-white disabled:opacity-50"
            :disabled="creating || !files.length"
            @click="createShare"
          >
            {{ creating ? 'Creating…' : 'Create link' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.input-ph {
  @apply w-full rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-900 px-2 py-1.5 text-xs text-surface-800 dark:text-surface-100 outline-none focus:border-primary-500;
}
</style>
