<script setup>
/**
 * ShareRestrictions - view-only restriction toggles for a Drive file.
 *
 * Two switches ("Block downloading" / "Block printing") that apply ONLY to
 * recipients with View access (people shares, group shares, guest viewer links
 * and the public link). Editors and the owner can always download, edit and
 * print. Printing can never be fully prevented, so it is best-effort.
 */
import { ref, onMounted } from 'vue'
import { useToastStore } from '@/stores/toast'
import { getRestrictions, updateRestrictions } from '@/services/driveShareApi'

const props = defineProps({
  fileId: { type: Number, required: true },
})

const emit = defineEmits(['changed'])

const toast = useToastStore()

const noDownload = ref(false)
const noPrint = ref(false)
const loading = ref(true)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const data = await getRestrictions(props.fileId)
    if (data) {
      noDownload.value = !!data.no_download
      noPrint.value = !!data.no_print
    }
  } finally {
    loading.value = false
  }
}

async function save(field, value) {
  // Optimistic update; revert on failure.
  const prev = field === 'no_download' ? noDownload.value : noPrint.value
  if (field === 'no_download') noDownload.value = value
  else noPrint.value = value

  saving.value = true
  try {
    const res = await updateRestrictions(props.fileId, {
      noDownload: noDownload.value,
      noPrint: noPrint.value,
    })
    if (res.success) {
      toast.success('Restrictions updated')
      emit('changed')
    } else {
      if (field === 'no_download') noDownload.value = prev
      else noPrint.value = prev
      toast.error(res.error || 'Failed to update restrictions')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-xl border border-surface-200 dark:border-surface-700 p-3.5 space-y-3">
    <div class="flex items-start gap-2">
      <span class="material-symbols-rounded text-lg text-surface-400 mt-0.5">visibility_lock</span>
      <div>
        <p class="text-sm font-medium text-surface-800 dark:text-surface-200">View-only restrictions</p>
        <p class="text-xs text-surface-500 dark:text-surface-400">
          Applies to people and links with <strong>View</strong> access. Editors can still download, edit and print.
        </p>
      </div>
    </div>

    <div v-if="loading" class="flex items-center gap-2 text-xs text-surface-400 py-1">
      <span class="material-symbols-rounded animate-spin text-base">progress_activity</span>
      Loading…
    </div>

    <div v-else class="space-y-2.5">
      <!-- Block downloading -->
      <button
        type="button"
        role="switch"
        :aria-checked="noDownload ? 'true' : 'false'"
        :disabled="saving"
        class="group w-full flex items-center justify-between gap-3 select-none cursor-pointer focus:outline-none disabled:opacity-60"
        @click="save('no_download', !noDownload)"
      >
        <span class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-lg text-surface-400">file_download_off</span>
          Block downloading
        </span>
        <span
          :class="[
            'relative inline-flex flex-shrink-0 h-6 w-11 rounded-full transition-colors duration-200 ease-in-out',
            noDownload ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
          ]"
        >
          <span
            :class="[
              'absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 ease-in-out',
              noDownload ? 'translate-x-5' : 'translate-x-0',
            ]"
          ></span>
        </span>
      </button>

      <!-- Block printing -->
      <button
        type="button"
        role="switch"
        :aria-checked="noPrint ? 'true' : 'false'"
        :disabled="saving"
        class="group w-full flex items-center justify-between gap-3 select-none cursor-pointer focus:outline-none disabled:opacity-60"
        @click="save('no_print', !noPrint)"
      >
        <span class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-lg text-surface-400">print_disabled</span>
          Block printing
        </span>
        <span
          :class="[
            'relative inline-flex flex-shrink-0 h-6 w-11 rounded-full transition-colors duration-200 ease-in-out',
            noPrint ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
          ]"
        >
          <span
            :class="[
              'absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 ease-in-out',
              noPrint ? 'translate-x-5' : 'translate-x-0',
            ]"
          ></span>
        </span>
      </button>

      <p v-if="noPrint" class="text-[11px] text-surface-400 flex items-start gap-1">
        <span class="material-symbols-rounded text-sm">info</span>
        Printing is hidden in the viewer, but it cannot be fully prevented (e.g. screenshots).
      </p>
    </div>
  </div>
</template>
