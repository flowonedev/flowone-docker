import api from '@/services/api'

// Folder Files (Drive-backed)

export async function fetchFolderFiles(folderId) {
  const { data } = await api.get(`/project-hub/folders/${folderId}/files`)
  return data.files || []
}

export async function addFileToFolder(folderId, driveFileId, groupName = 'General') {
  const { data } = await api.post(`/project-hub/folders/${folderId}/files`, {
    drive_file_id: driveFileId,
    group_name: groupName,
  })
  return data
}

export async function updateFileGroup(fileId, groupName) {
  await api.put(`/project-hub/folder-files/${fileId}/group`, { group_name: groupName })
}

export async function batchUpdateGroup(ids, groupName) {
  const { data } = await api.put('/project-hub/folder-files/batch-group', {
    ids,
    group_name: groupName,
  })
  return data.updated || 0
}

export async function removeFileFromFolder(fileId) {
  await api.delete(`/project-hub/folder-files/${fileId}`)
}

export async function markFilesSeen(folderId) {
  await api.post(`/project-hub/folders/${folderId}/files/mark-seen`)
}

export async function fetchUnseenCount(folderId) {
  const { data } = await api.get(`/project-hub/folders/${folderId}/files/unseen-count`)
  return data.count || 0
}

export async function fetchUnseenCounts(folderIds) {
  if (!folderIds.length) return {}
  const { data } = await api.get(`/project-hub/folders/unseen-counts?ids=${folderIds.join(',')}`)
  return data.counts || {}
}

export function getExportUrl(folderId, groupName) {
  const base = `/api/project-hub/folders/${folderId}/files/export`
  return groupName ? `${base}?group=${encodeURIComponent(groupName)}` : base
}

export async function fetchFileGroups(folderId) {
  const { data } = await api.get(`/project-hub/folders/${folderId}/files/groups`)
  return data.groups || []
}

// Folder Links

export async function fetchFolderLinks(folderId) {
  const { data } = await api.get(`/project-hub/folders/${folderId}/links`)
  return data.links || []
}

export async function addFolderLink(folderId, payload) {
  const { data } = await api.post(`/project-hub/folders/${folderId}/links`, payload)
  return data
}

export async function updateFolderLink(linkId, payload) {
  await api.put(`/project-hub/folder-links/${linkId}`, payload)
}

export async function deleteFolderLink(linkId) {
  await api.delete(`/project-hub/folder-links/${linkId}`)
}
