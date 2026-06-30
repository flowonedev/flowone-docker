<script setup>
// SiteLifecycleMenu
// ---------------------------------------------------------------
// Compact dropdown that surfaces the SUSPEND / RESUME / ARCHIVE /
// RESTORE lifecycle actions for one site. Each action enqueues a
// queue-backed job via /api/sites/v2/{domain}/... and emits the
// returned job id so the parent can pop a JobProgressModal.
//
// Action visibility is driven by the site's current actual_state:
//   active / degraded   -> Suspend, Archive
//   suspended           -> Resume, Archive
//   archived            -> Restore
//
// We intentionally bury Archive / Restore behind a confirmation
// prompt (those flows are destructive of live state) and gate
// Restore on the operator providing an archive_path. The
// confirmation copy explicitly names the action so the operator
// can't fat-finger the wrong one.

import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import {
  suspendSite,
  resumeSite,
  archiveSite,
  restoreSite,
  listArchives,
} from '@/services/sitesV2'

const props = defineProps({
  site: { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['job-enqueued', 'error', 'refresh'])

const open = ref(false)
const rootRef = ref(null)

const onDocumentClick = (event) => {
  if (!open.value) return
  const el = rootRef.value
  if (el && !el.contains(event.target)) {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onDocumentClick)
})
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocumentClick)
})

const confirmState = ref({
  show: false,
  action: null,
  archivePath: '',
  suspendMessage: '',
  busy: false,
})

// Archive picker state. Populated lazily the first time the operator
// opens the Restore modal so we don't make an API call until the
// action is actually requested. `loading` and `error` drive the
// picker's UI states.
const archivePicker = ref({
  loading: false,
  error: null,
  archives: [],
  loaded: false,
})

const refreshArchiveList = async () => {
  if (!props.site?.domain) return
  archivePicker.value.loading = true
  archivePicker.value.error = null
  try {
    const data = await listArchives(props.site.domain, { limit: 50 })
    archivePicker.value.archives = Array.isArray(data?.archives) ? data.archives : []
    archivePicker.value.loaded = true
    // Default-select the newest entry so the operator can confirm
    // without scrolling for the common case.
    if (
      !confirmState.value.archivePath
      && archivePicker.value.archives.length > 0
    ) {
      confirmState.value.archivePath = archivePicker.value.archives[0].path
    }
  } catch (e) {
    archivePicker.value.error = e?.message ?? 'Failed to load archives'
    archivePicker.value.archives = []
  } finally {
    archivePicker.value.loading = false
  }
}

watch(
  () => [confirmState.value.show, confirmState.value.action],
  ([show, action]) => {
    if (show && action === 'restore' && !archivePicker.value.loaded) {
      refreshArchiveList()
    }
  },
)

const formatBytes = (n) => {
  if (typeof n !== 'number' || n < 0) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let v = n
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${v.toFixed(v < 10 && i > 0 ? 1 : 0)} ${units[i]}`
}

const formatArchivedAt = (iso) => {
  if (!iso) return null
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}

const state = computed(() => props.site?.actual_state ?? 'unknown')

const canSuspend = computed(() => ['active', 'degraded'].includes(state.value))
const canResume = computed(() => state.value === 'suspended')
const canArchive = computed(() =>
  ['active', 'degraded', 'suspended', 'failed'].includes(state.value),
)
const canRestore = computed(() => state.value === 'archived')

const actionMap = {
  suspend: {
    title: 'Suspend site',
    body:
      'The vhost will be replaced with a 503 maintenance page. SFTP, DB, and home directory data remain intact. The site can be resumed at any time.',
    confirm: 'Suspend',
    call: (domain, payload) => suspendSite(domain, payload),
    requiresArchivePath: false,
    showSuspendMessage: true,
  },
  resume: {
    title: 'Resume site',
    body:
      'The original vhost config (saved as vhost.conf.suspended-backup) will be restored and OLS reloaded. The site comes back online immediately.',
    confirm: 'Resume',
    call: (domain) => resumeSite(domain),
    requiresArchivePath: false,
    showSuspendMessage: false,
  },
  archive: {
    title: 'Archive site',
    body:
      'A snapshot of the database and home directory is taken, then the live infrastructure (vhost, home, db, sftp) is torn down. The site row stays around in "archived" state and can be restored from the archive store later. This destroys live data once the snapshot is promoted.',
    confirm: 'Archive (destructive)',
    call: (domain, payload) => archiveSite(domain, payload),
    requiresArchivePath: false,
    showSuspendMessage: false,
  },
  restore: {
    title: 'Restore site from archive',
    body:
      'Rebuilds the site infrastructure (vhost, sftp, db) and rehydrates the home directory and database from the archive payload at the path below.',
    confirm: 'Restore',
    call: (domain, payload) => restoreSite(domain, payload),
    requiresArchivePath: true,
    showSuspendMessage: false,
  },
}

const beginAction = (action) => {
  open.value = false
  confirmState.value = {
    show: true,
    action,
    archivePath: '',
    suspendMessage: '',
    busy: false,
  }
}

const cancelAction = () => {
  if (confirmState.value.busy) return
  confirmState.value.show = false
}

const submitAction = async () => {
  const action = confirmState.value.action
  if (!action) return
  const spec = actionMap[action]
  if (!spec) return

  if (spec.requiresArchivePath && !confirmState.value.archivePath.trim()) {
    emit('error', `${spec.title} requires an archive_path.`)
    return
  }

  confirmState.value.busy = true
  try {
    const payload = {}
    if (spec.requiresArchivePath) {
      payload.payload = { archive_path: confirmState.value.archivePath.trim() }
    } else if (spec.showSuspendMessage && confirmState.value.suspendMessage.trim()) {
      payload.payload = { suspend_message: confirmState.value.suspendMessage.trim() }
    }
    const data = await spec.call(props.site.domain, payload)
    const jobId = data?.job?.id
    if (jobId) {
      emit('job-enqueued', { jobId, action, site: props.site })
    }
    confirmState.value.show = false
    emit('refresh')
  } catch (e) {
    emit('error', e?.message ?? `${spec.title} failed`)
  } finally {
    confirmState.value.busy = false
  }
}

</script>

<template>
  <div ref="rootRef" class="relative">
    <button
      type="button"
      class="btn-secondary btn-sm"
      :disabled="disabled"
      @click="open = !open"
    >
      <span class="material-symbols-rounded text-sm">tune</span>
      Lifecycle
      <span class="material-symbols-rounded text-xs">expand_more</span>
    </button>

    <Transition name="dropdown">
      <div
        v-if="open"
        class="absolute right-0 mt-2 w-56 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg z-50 py-1"
      >
        <button
          v-if="canSuspend"
          class="w-full text-left px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center gap-2"
          @click="beginAction('suspend')"
        >
          <span class="material-symbols-rounded text-base text-amber-600">pause_circle</span>
          Suspend
        </button>
        <button
          v-if="canResume"
          class="w-full text-left px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center gap-2"
          @click="beginAction('resume')"
        >
          <span class="material-symbols-rounded text-base text-emerald-600">play_circle</span>
          Resume
        </button>
        <div
          v-if="(canSuspend || canResume) && (canArchive || canRestore)"
          class="my-1 border-t border-slate-100 dark:border-slate-800"
        ></div>
        <button
          v-if="canArchive"
          class="w-full text-left px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center gap-2"
          @click="beginAction('archive')"
        >
          <span class="material-symbols-rounded text-base text-purple-600">archive</span>
          Archive (snapshot + teardown)
        </button>
        <button
          v-if="canRestore"
          class="w-full text-left px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center gap-2"
          @click="beginAction('restore')"
        >
          <span class="material-symbols-rounded text-base text-emerald-700">unarchive</span>
          Restore from archive
        </button>
        <div
          v-if="!canSuspend && !canResume && !canArchive && !canRestore"
          class="px-3 py-2 text-xs text-slate-500"
        >
          No lifecycle actions available in state
          <code class="px-1">{{ state }}</code>.
        </div>
      </div>
    </Transition>

    <Modal
      :show="confirmState.show"
      :title="actionMap[confirmState.action]?.title ?? ''"
      size="md"
      @close="cancelAction"
    >
      <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
        {{ actionMap[confirmState.action]?.body ?? '' }}
      </p>

      <div
        v-if="actionMap[confirmState.action]?.requiresArchivePath"
        class="space-y-2"
      >
        <div class="flex items-center justify-between">
          <label class="block text-xs font-semibold text-slate-500">
            Select an archive
          </label>
          <button
            type="button"
            class="text-xs text-primary-500 hover:underline"
            :disabled="archivePicker.loading"
            @click="refreshArchiveList"
          >
            {{ archivePicker.loading ? 'Loading…' : 'Refresh' }}
          </button>
        </div>

        <div
          v-if="archivePicker.loading && archivePicker.archives.length === 0"
          class="text-xs text-slate-400 px-2 py-3 border border-slate-200 dark:border-slate-700 rounded-md"
        >
          Loading archives…
        </div>

        <div
          v-else-if="archivePicker.error"
          class="text-xs text-red-500 px-2 py-2 border border-red-300 dark:border-red-800 rounded-md"
        >
          {{ archivePicker.error }}
        </div>

        <div
          v-else-if="archivePicker.archives.length === 0"
          class="text-xs text-slate-500 px-2 py-3 border border-dashed border-slate-300 dark:border-slate-700 rounded-md"
        >
          No archives found for
          <code class="px-1 text-slate-600 dark:text-slate-300">{{ site.domain }}</code>.
          Archives are written to
          <code class="px-1 text-slate-600 dark:text-slate-300">
            /var/www/vps-admin/storage/archives/&lt;domain&gt;/
          </code>
          by the Archive action.
        </div>

        <div
          v-else
          class="max-h-64 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-md divide-y divide-slate-100 dark:divide-slate-800"
        >
          <label
            v-for="entry in archivePicker.archives"
            :key="entry.path"
            class="flex items-start gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50"
          >
            <input
              type="radio"
              :value="entry.path"
              v-model="confirmState.archivePath"
              class="mt-1"
            />
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-mono text-xs text-slate-600 dark:text-slate-300 truncate">
                  {{ entry.name }}
                </span>
                <span
                  v-if="entry.job_id"
                  class="px-1.5 py-0.5 rounded text-xs bg-slate-100 dark:bg-slate-800 text-slate-500"
                >
                  job #{{ entry.job_id }}
                </span>
              </div>
              <div class="text-xs text-slate-400 mt-0.5 flex items-center gap-2">
                <span v-if="formatArchivedAt(entry.archived_at)">
                  {{ formatArchivedAt(entry.archived_at) }}
                </span>
                <span v-if="entry.size_bytes !== null && entry.size_bytes !== undefined">
                  · {{ formatBytes(entry.size_bytes) }}
                </span>
              </div>
              <div class="text-[10px] text-slate-400 dark:text-slate-500 font-mono truncate mt-0.5">
                {{ entry.path }}
              </div>
            </div>
          </label>
        </div>
      </div>

      <div
        v-else-if="actionMap[confirmState.action]?.showSuspendMessage"
        class="space-y-1"
      >
        <label class="block text-xs font-semibold text-slate-500"
          >Maintenance message (optional)</label
        >
        <input
          v-model="confirmState.suspendMessage"
          type="text"
          class="input w-full"
          placeholder="Site temporarily unavailable."
          maxlength="200"
          autocomplete="off"
        />
      </div>

      <template #footer>
        <button
          class="btn-secondary"
          :disabled="confirmState.busy"
          @click="cancelAction"
        >
          Cancel
        </button>
        <button
          :class="confirmState.action === 'archive' ? 'btn-danger' : 'btn-primary'"
          :disabled="confirmState.busy"
          @click="submitAction"
        >
          <span v-if="confirmState.busy" class="spinner" />
          {{ actionMap[confirmState.action]?.confirm ?? 'Confirm' }}
        </button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.dropdown-enter-active,
.dropdown-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
