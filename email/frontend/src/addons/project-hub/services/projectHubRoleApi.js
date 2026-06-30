import api from '@/services/api'

const BASE = '/project-hub'

export async function fetchRoles() {
  const { data } = await api.get(`${BASE}/roles`)
  return data.roles || []
}

export async function createRole(payload) {
  const { data } = await api.post(`${BASE}/roles`, payload)
  return data
}

export async function updateRole(id, payload) {
  const { data } = await api.put(`${BASE}/roles/${id}`, payload)
  return data
}

export async function deleteRole(id) {
  await api.delete(`${BASE}/roles/${id}`)
}

export async function reorderRoles(ids) {
  await api.post(`${BASE}/roles/reorder`, { ids })
}

export async function fetchRoleStatuses(roleId) {
  const { data } = await api.get(`${BASE}/roles/${roleId}/statuses`)
  return data.statuses || []
}

export async function createRoleStatus(roleId, payload) {
  const { data } = await api.post(`${BASE}/roles/${roleId}/statuses`, payload)
  return data
}

export async function updateRoleStatus(statusId, payload) {
  const { data } = await api.put(`${BASE}/role-statuses/${statusId}`, payload)
  return data
}

export async function deleteRoleStatus(statusId) {
  await api.delete(`${BASE}/role-statuses/${statusId}`)
}

export async function reorderRoleStatuses(roleId, ids) {
  await api.post(`${BASE}/roles/${roleId}/statuses/reorder`, { ids })
}

export async function fetchUserRoles(email) {
  const { data } = await api.get(`${BASE}/users/${encodeURIComponent(email)}/roles`)
  return data.roles || []
}

export async function assignUserRole(email, roleId) {
  const { data } = await api.post(`${BASE}/users/${encodeURIComponent(email)}/roles`, { role_id: roleId })
  return data
}

export async function removeUserRole(email, roleId) {
  await api.delete(`${BASE}/users/${encodeURIComponent(email)}/roles/${roleId}`)
}
