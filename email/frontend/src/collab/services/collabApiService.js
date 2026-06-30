/**
 * Collab API Service
 * 
 * HTTP API client for the collaboration system.
 * Uses the main api instance to inherit token refresh, session tracking,
 * multi-account support, and credential handling.
 */

import api from '@/services/api'

/**
 * Document Management
 */
export const collabDocumentApi = {
  /**
   * List all documents the user has access to
   */
  async list(params = {}) {
    const { type, page = 1, limit = 50 } = params
    const response = await api.get('/collab/documents', {
      params: { type, page, limit },
    })
    return response.data
  },

  /**
   * Get a single document by UUID
   */
  async get(uuid) {
    const response = await api.get(`/collab/documents/${uuid}`)
    return response.data
  },

  /**
   * Create a new document
   */
  async create(data) {
    const { title = 'Untitled', type = 'document', folder_id = null } = data
    const response = await api.post('/collab/documents', { title, type, folder_id })
    return response.data
  },

  /**
   * Create a new document from an existing Drive file (import)
   */
  async createFromFile(data) {
    const { title, type = 'document', drive_file_id } = data
    const response = await api.post('/collab/documents/from-file', { title, type, drive_file_id })
    return response.data
  },

  /**
   * Update document metadata (title, etc.)
   */
  async update(uuid, data) {
    const response = await api.patch(`/collab/documents/${uuid}`, data)
    return response.data
  },

  /**
   * Delete a document (soft delete)
   */
  async delete(uuid) {
    const response = await api.delete(`/collab/documents/${uuid}`)
    return response.data
  },

  /**
   * Restore a deleted document
   */
  async restore(uuid) {
    const response = await api.post(`/collab/documents/${uuid}/restore`)
    return response.data
  },

  /**
   * Duplicate a document
   */
  async duplicate(uuid) {
    const response = await api.post(`/collab/documents/${uuid}/duplicate`)
    return response.data
  },

  /**
   * Get collaboration token for WebSocket connection
   */
  async getCollabToken(uuid) {
    const response = await api.get(`/collab/documents/${uuid}/collab-token`)
    return response.data
  },
  
  /**
   * Save document content back to Drive file
   * Only works if document was created from a Drive file
   */
  async saveToDrive(uuid, htmlContent, createVersion = true) {
    const response = await api.post(`/collab/documents/${uuid}/save-to-drive`, {
      html_content: htmlContent,
      create_version: createVersion,
    })
    return response.data
  },
}

/**
 * Permission Management
 */
export const collabPermissionApi = {
  /**
   * List all collaborators on a document
   */
  async list(documentUuid) {
    const response = await api.get(`/collab/documents/${documentUuid}/permissions`)
    return response.data
  },

  /**
   * Add a collaborator
   */
  async add(documentUuid, email, role = 'viewer') {
    const response = await api.post(`/collab/documents/${documentUuid}/permissions`, {
      email,
      role,
    })
    return response.data
  },

  /**
   * Update collaborator role
   */
  async update(documentUuid, email, role) {
    const response = await api.put(`/collab/documents/${documentUuid}/permissions/${encodeURIComponent(email)}`, {
      role,
    })
    return response.data
  },

  /**
   * Remove a collaborator
   */
  async remove(documentUuid, email) {
    const response = await api.delete(`/collab/documents/${documentUuid}/permissions/${encodeURIComponent(email)}`)
    return response.data
  },
}

/**
 * Version History
 */
export const collabVersionApi = {
  /**
   * List version history for a document
   */
  async list(documentUuid, params = {}) {
    const { page = 1, limit = 20 } = params
    const response = await api.get(`/collab/documents/${documentUuid}/versions`, {
      params: { page, limit },
    })
    return response.data
  },

  /**
   * Get a specific version
   */
  async get(documentUuid, versionNumber) {
    const response = await api.get(`/collab/documents/${documentUuid}/versions/${versionNumber}`)
    return response.data
  },

  /**
   * Create a named version (snapshot)
   */
  async create(documentUuid, name) {
    const response = await api.post(`/collab/documents/${documentUuid}/versions`, { name })
    return response.data
  },

  /**
   * Restore to a specific version
   */
  async restore(documentUuid, versionNumber) {
    const response = await api.post(`/collab/documents/${documentUuid}/versions/${versionNumber}/restore`)
    return response.data
  },
}

/**
 * Comments (Phase 2)
 */
export const collabCommentApi = {
  /**
   * List all comments on a document
   */
  async list(documentUuid) {
    const response = await api.get(`/collab/documents/${documentUuid}/comments`)
    return response.data
  },

  /**
   * Add a comment
   */
  async add(documentUuid, data) {
    const { content, threadId, parentId, selectionAnchor } = data
    const response = await api.post(`/collab/documents/${documentUuid}/comments`, {
      content,
      thread_id: threadId,
      parent_id: parentId,
      selection_anchor: selectionAnchor,
    })
    return response.data
  },

  /**
   * Update a comment
   */
  async update(documentUuid, commentId, content) {
    const response = await api.patch(`/collab/documents/${documentUuid}/comments/${commentId}`, {
      content,
    })
    return response.data
  },

  /**
   * Delete a comment
   */
  async delete(documentUuid, commentId) {
    const response = await api.delete(`/collab/documents/${documentUuid}/comments/${commentId}`)
    return response.data
  },

  /**
   * Resolve a comment thread
   */
  async resolve(documentUuid, threadId) {
    const response = await api.post(`/collab/documents/${documentUuid}/comments/threads/${threadId}/resolve`)
    return response.data
  },

  /**
   * Unresolve a comment thread
   */
  async unresolve(documentUuid, threadId) {
    const response = await api.post(`/collab/documents/${documentUuid}/comments/threads/${threadId}/unresolve`)
    return response.data
  },
}

/**
 * Export functionality
 */
export const collabExportApi = {
  /**
   * Export document as DOCX
   */
  async toDocx(documentUuid) {
    const response = await api.get(`/collab/documents/${documentUuid}/export/docx`, {
      responseType: 'blob',
    })
    return response.data
  },

  /**
   * Export presentation as PPTX
   */
  async toPptx(documentUuid) {
    const response = await api.get(`/collab/documents/${documentUuid}/export/pptx`, {
      responseType: 'blob',
    })
    return response.data
  },

  /**
   * Export as PDF
   */
  async toPdf(documentUuid) {
    const response = await api.get(`/collab/documents/${documentUuid}/export/pdf`, {
      responseType: 'blob',
    })
    return response.data
  },
}

export default {
  document: collabDocumentApi,
  permission: collabPermissionApi,
  version: collabVersionApi,
  comment: collabCommentApi,
  export: collabExportApi,
}
