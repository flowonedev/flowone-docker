<script setup>
/**
 * Surfaces folders the most recent All Mail scan could not fully read.
 *
 * Reads `allMailDegraded` and `allMailDegradedDismissed` from the mailbox
 * store. The banner only renders for the All Mail view; when there are no
 * degraded folders, or the user has dismissed the banner for the current
 * payload, nothing renders.
 *
 * Per the Wave 1 contract every entry carries:
 *   { folder_path, folder_display, state, total, retrieved, bad_uids,
 *     bad_uids_truncated_count, last_attempt_at, retry_after,
 *     failure_reason, fallback_stage, request_id }
 */
import { computed, ref } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'

const mailbox = useMailboxStore()
const detailsOpen = ref(false)

const isAllMail = computed(() => mailbox.currentFolder === 'ALL_MAIL')
const items = computed(() => mailbox.allMailDegraded || [])
const hasDegraded = computed(() => items.value.length > 0)
const isVisible = computed(
  () => isAllMail.value && hasDegraded.value && !mailbox.allMailDegradedDismissed
)

function dismiss() {
  mailbox.dismissAllMailDegraded()
}

async function retry() {
  // Force a refresh of the All Mail view so the backend re-attempts
  // the degraded folders. The circuit breaker decides whether to actually
  // try the IMAP fetch again or keep the folder quarantined.
  detailsOpen.value = false
  await mailbox.fetchAllMail(mailbox.pagination?.page || 1)
}

function stateClass(state) {
  if (state === 'quarantined') {
    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200'
  }
  if (state === 'deleted') {
    return 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200'
  }
  return 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-200'
}

function formatTime(iso) {
  if (!iso) return ''
  try {
    const d = new Date(iso)
    return d.toLocaleString()
  } catch (_) {
    return iso
  }
}
</script>

<template>
  <div
    v-if="isVisible"
    class="border border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-700 dark:bg-orange-950/40 dark:text-orange-100 rounded-md px-3 py-2 mb-2 flex flex-col gap-1.5"
    role="status"
    aria-live="polite"
  >
    <div class="flex items-center gap-2">
      <span class="material-symbols-rounded text-base">report_problem</span>
      <span class="text-sm flex-1">
        Some folders could not be fully read on the last All Mail scan
        ({{ items.length }} {{ items.length === 1 ? 'folder' : 'folders' }}).
        Pinned and labelled mail still works as expected.
      </span>
      <button
        type="button"
        @click="detailsOpen = !detailsOpen"
        class="text-xs underline hover:no-underline"
      >
        {{ detailsOpen ? 'Hide' : 'Details' }}
      </button>
      <button
        type="button"
        @click="retry"
        class="text-xs underline hover:no-underline"
      >
        Retry
      </button>
      <button
        type="button"
        @click="dismiss"
        aria-label="Dismiss"
        class="text-xs px-1 hover:opacity-70"
      >
        <span class="material-symbols-rounded text-sm">close</span>
      </button>
    </div>

    <ul v-if="detailsOpen" class="mt-1 text-xs space-y-1">
      <li
        v-for="entry in items"
        :key="entry.folder_path"
        class="flex flex-wrap items-center gap-2 border-t border-orange-200/60 dark:border-orange-700/40 pt-1 first:border-t-0 first:pt-0"
      >
        <span class="font-mono">{{ entry.folder_display || entry.folder_path }}</span>
        <span
          class="inline-block px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide"
          :class="stateClass(entry.state)"
        >
          {{ entry.state }}
        </span>
        <span class="opacity-80">
          {{ entry.retrieved }} / {{ entry.total }} read
        </span>
        <span v-if="entry.fallback_stage" class="opacity-70">
          via {{ entry.fallback_stage }}
        </span>
        <span v-if="entry.bad_uids && entry.bad_uids.length" class="opacity-70">
          {{ entry.bad_uids.length }} bad uid{{ entry.bad_uids.length === 1 ? '' : 's' }}
          <span v-if="entry.bad_uids_truncated_count">
            (+{{ entry.bad_uids_truncated_count }} more)
          </span>
        </span>
        <span v-if="entry.retry_after" class="opacity-70">
          retry after {{ formatTime(entry.retry_after) }}
        </span>
        <span v-if="entry.failure_reason" class="opacity-70 italic basis-full pl-1">
          {{ entry.failure_reason }}
        </span>
      </li>
      <li v-if="items[0]?.request_id" class="opacity-60 text-[10px] pt-1">
        request id: <span class="font-mono">{{ items[0].request_id }}</span>
      </li>
    </ul>
  </div>
</template>
