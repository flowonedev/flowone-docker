/**
 * driveShareApi - people/group sharing API for Drive files and folders.
 *
 * Unified wrapper over the folder-level collaborator endpoints
 * (/drive/folders/...) and the file-level ones (/drive/files/...),
 * so share UI components can be parametrized by target type.
 */
import api from '@/services/api'

const base = (targetType, id) =>
  targetType === 'folder' ? `/drive/folders/${id}` : `/drive/files/${id}`

/**
 * Current public-link state for a file or folder owned by the caller.
 * Lets the share modal self-hydrate when opened from a place that doesn't
 * carry the item's share_token (office editor, attachment preview).
 * Returns { is_shared, token, url, expires, max_downloads, download_count, has_password }
 * or null on failure.
 */
export async function getShareState(targetType, id) {
  try {
    const response = await api.get(`${base(targetType, id)}/share`)
    if (response.data.success) {
      return response.data.data || null
    }
  } catch (e) {
    console.error('Failed to fetch share state:', e)
  }
  return null
}

/**
 * Notify internal colleagues / groups about an existing public share link.
 * Recipients are passed as ids and resolved to emails server-side (no raw
 * email relay). Returns { success, sent } or { success: false, error }.
 */
export async function notifyShareLink(targetType, id, { userIds = [], groupIds = [] } = {}) {
  try {
    const response = await api.post(`${base(targetType, id)}/share/notify`, {
      user_ids: userIds,
      group_ids: groupIds,
    })
    if (response.data.success) {
      return { success: true, sent: response.data.data?.sent || 0 }
    }
    return { success: false, error: response.data.message }
  } catch (e) {
    return { success: false, error: e.response?.data?.message || 'Failed to send notification' }
  }
}

/**
 * View-only restrictions for a file (owner only).
 * Returns { no_download, no_print } or null on failure.
 */
export async function getRestrictions(fileId) {
  try {
    const response = await api.get(`/drive/files/${fileId}/restrictions`)
    if (response.data.success) {
      return response.data.data || null
    }
  } catch (e) {
    console.error('Failed to fetch restrictions:', e)
  }
  return null
}

/**
 * Update the view-only restrictions for a file (owner only).
 * These apply to recipients with View access; editors are unaffected.
 * Returns { success, no_download, no_print } or { success: false, error }.
 */
export async function updateRestrictions(fileId, { noDownload, noPrint } = {}) {
  try {
    const payload = {}
    if (noDownload !== undefined) payload.no_download = !!noDownload
    if (noPrint !== undefined) payload.no_print = !!noPrint
    const response = await api.patch(`/drive/files/${fileId}/restrictions`, payload)
    if (response.data.success) {
      return { success: true, ...(response.data.data || {}) }
    }
    return { success: false, error: response.data.message }
  } catch (e) {
    return { success: false, error: e.response?.data?.message || 'Failed to update restrictions' }
  }
}

/**
 * Open history for a file (owner only): who opened it, when, and how often.
 * Returns an array of { user_email, open_count, first_opened_at, last_opened_at }.
 */
export async function getAccessLog(fileId) {
  try {
    const response = await api.get(`/drive/files/${fileId}/access-log`)
    if (response.data.success) {
      return response.data.data?.entries || []
    }
  } catch (e) {
    console.error('Failed to fetch access log:', e)
  }
  return []
}

export async function fetchCollaborators(targetType, id) {
  try {
    const response = await api.get(`${base(targetType, id)}/collaborators`)
    if (response.data.success) {
      return response.data.data.collaborators || []
    }
  } catch (e) {
    console.error('Failed to fetch collaborators:', e)
  }
  return []
}

export async function addCollaborator(targetType, id, email, permission = 'viewer') {
  try {
    const response = await api.post(`${base(targetType, id)}/collaborators`, { email, permission })
    if (response.data.success) {
      return { success: true, collaborator: response.data.data.collaborator }
    }
    return { success: false, error: response.data.message }
  } catch (e) {
    return { success: false, error: e.response?.data?.message || 'Failed to add collaborator' }
  }
}

export async function updateCollaboratorPermission(targetType, id, email, permission) {
  try {
    const response = await api.put(
      `${base(targetType, id)}/collaborators/${encodeURIComponent(email)}`,
      { permission }
    )
    return response.data.success
  } catch (e) {
    console.error('Failed to update collaborator permission:', e)
    return false
  }
}

export async function removeCollaborator(targetType, id, email) {
  try {
    const response = await api.delete(
      `${base(targetType, id)}/collaborators/${encodeURIComponent(email)}`
    )
    return response.data.success
  } catch (e) {
    console.error('Failed to remove collaborator:', e)
    return false
  }
}

export async function fetchGroupAccess(targetType, id) {
  try {
    const response = await api.get(`${base(targetType, id)}/group-access`)
    if (response.data.success) {
      const data = response.data.data
      // Folder endpoint returns the array directly, file endpoint wraps it in {groups}
      return Array.isArray(data) ? data : (data.groups || [])
    }
  } catch (e) {
    console.error('Failed to fetch group access:', e)
  }
  return []
}

export async function addFileGroupAccess(fileId, groupId, permission = 'viewer') {
  try {
    const response = await api.post(`/drive/files/${fileId}/group-access`, {
      group_id: groupId,
      permission,
    })
    if (response.data.success) {
      return { success: true }
    }
    return { success: false, error: response.data.message }
  } catch (e) {
    return { success: false, error: e.response?.data?.message || 'Failed to share with group' }
  }
}

export async function removeGroupAccess(targetType, id, groupId) {
  try {
    const response = await api.delete(`${base(targetType, id)}/group-access/${groupId}`)
    return response.data.success
  } catch (e) {
    console.error('Failed to remove group access:', e)
    return false
  }
}
