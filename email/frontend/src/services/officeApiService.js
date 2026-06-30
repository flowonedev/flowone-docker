import api from './api'
import { getApiOrigin } from './serverRegistry'

/**
 * officeApiService - REST client for the OnlyOffice integration.
 *
 * The Document Server itself is never called from here; the backend builds
 * a signed editor config and the DS api.js script (loaded from the office
 * server origin) renders the editor with it.
 */
export const officeApi = {
  /** Editor availability + Document Server URL. */
  getStatus: () => api.get('/office/status'),

  /** Signed editor config for a Drive file. */
  getConfig: (fileId, { lang, name } = {}) =>
    api.get(`/office/files/${fileId}/config`, { params: { lang, name } }),

  /** Collab-server JWT for the live-presence room (cursors + follow). */
  getPresenceToken: (fileId, { name } = {}) =>
    api.get(`/office/files/${fileId}/presence-token`, { params: { name } }),

  /** Create a blank docx/xlsx/pptx in Drive. */
  createFile: ({ type, title, folderId }) =>
    api.post('/office/files/new', { type, title, folder_id: folderId ?? null }),

  /**
   * Rename a file from inside the editor. Unlike the generic Drive rename,
   * this also pushes the new title to the live Document Server session so the
   * editor header updates in place. Returns the canonical new name.
   */
  renameFile: (fileId, name) =>
    api.put(`/office/files/${fileId}/name`, { name }),

  /** Guest share links. */
  listGuestLinks: (fileId) => api.get(`/office/files/${fileId}/guest-links`),
  createGuestLink: (fileId, { role, expiresInHours, label } = {}) =>
    api.post(`/office/files/${fileId}/guest-links`, {
      role,
      expires_in_hours: expiresInHours,
      label,
    }),
  revokeGuestLink: (token) => api.delete(`/office/guest-links/${token}`),
}

/**
 * Public guest endpoint (no auth - the opaque token IS the auth).
 * Uses plain fetch so no Authorization/session headers are attached.
 */
export async function fetchGuestOfficeConfig(token, { name, lang } = {}) {
  const params = new URLSearchParams()
  if (name) params.set('name', name)
  if (lang) params.set('lang', lang)
  const qs = params.toString() ? `?${params.toString()}` : ''
  const res = await fetch(`${getApiOrigin()}/api/guest/office/${encodeURIComponent(token)}/config${qs}`)
  const body = await res.json().catch(() => null)
  if (!res.ok || !body?.success) {
    throw new Error(body?.message || 'This link is invalid, expired or revoked')
  }
  return body.data
}

/**
 * Public guest presence token (no auth - the opaque token IS the auth).
 * Lets a share-link guest join the live-cursor awareness room so their
 * pointer is broadcast and they can see everyone else's.
 */
export async function fetchGuestPresenceToken(token, { name } = {}) {
  const params = new URLSearchParams()
  if (name) params.set('name', name)
  const qs = params.toString() ? `?${params.toString()}` : ''
  const res = await fetch(
    `${getApiOrigin()}/api/guest/office/${encodeURIComponent(token)}/presence-token${qs}`
  )
  const body = await res.json().catch(() => null)
  if (!res.ok || !body?.success) {
    throw new Error(body?.message || 'Presence unavailable')
  }
  return body.data
}

/**
 * Load the Document Server's api.js exactly once and return window.DocsAPI.
 */
let docsApiPromise = null
export function loadDocsApi(serverUrl) {
  if (window.DocsAPI) return Promise.resolve(window.DocsAPI)
  if (docsApiPromise) return docsApiPromise

  docsApiPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = `${serverUrl.replace(/\/$/, '')}/web-apps/apps/api/documents/api.js`
    script.async = true
    script.onload = () => {
      if (window.DocsAPI) {
        resolve(window.DocsAPI)
      } else {
        reject(new Error('Document Server script loaded but DocsAPI is missing'))
      }
    }
    script.onerror = () => {
      docsApiPromise = null
      reject(new Error('Failed to load the Document Server script'))
    }
    document.head.appendChild(script)
  })

  return docsApiPromise
}
