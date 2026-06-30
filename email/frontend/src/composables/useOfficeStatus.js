import { ref } from 'vue'
import { officeApi } from '@/services/officeApiService'

/**
 * useOfficeStatus - shared OnlyOffice availability state.
 *
 * Fetches GET /office/status once per app session (module-level cache)
 * and exposes whether the editor is enabled plus which file extensions
 * the backend allows editing for. Consumers: DriveView preview/context
 * menu and AttachmentPreview's "Edit in Office" button.
 */

const officeEnabled = ref(false)
const officeExtensions = ref(['docx', 'xlsx', 'pptx'])

let statusPromise = null

async function fetchStatus() {
  try {
    const res = await officeApi.getStatus()
    officeEnabled.value = !!res.data?.data?.enabled
    if (Array.isArray(res.data?.data?.editable_extensions)) {
      officeExtensions.value = res.data.data.editable_extensions
    }
  } catch (e) {
    officeEnabled.value = false
  }
}

/** Lazily fetch office status; concurrent callers share one request. */
function ensureOfficeStatus() {
  if (!statusPromise) statusPromise = fetchStatus()
  return statusPromise
}

/** Extension (lowercase, no dot) from a filename string. */
function extensionOf(filename) {
  return (filename || '').toLowerCase().split('.').pop() || ''
}

/** True when OnlyOffice is enabled and the filename's extension is editable. */
function canEditInOffice(filename) {
  return officeEnabled.value && officeExtensions.value.includes(extensionOf(filename))
}

export function useOfficeStatus() {
  return {
    officeEnabled,
    officeExtensions,
    ensureOfficeStatus,
    canEditInOffice,
  }
}
