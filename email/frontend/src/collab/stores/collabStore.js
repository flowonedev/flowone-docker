/**
 * Collab Store
 * 
 * Pinia store for managing collaborative document state.
 */

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import collabApi from '../services/collabApiService.js'
import { useSearchStore } from '@/addons/universal-search/stores/search'

export const useCollabStore = defineStore('collab', () => {
  // ============================================================
  // STATE
  // ============================================================

  // Current document
  const currentDocument = ref(null)
  const isLoading = ref(false)
  const error = ref(null)
  
  // Initial content for documents created from Drive files
  const pendingInitialContent = ref(null)
  
  // Initial slides for presentations created from PPTX files
  const pendingInitialSlides = ref(null)
  const pendingPresentationMeta = ref(null)

  // Document list
  const documents = ref([])
  const totalDocuments = ref(0)

  // Permissions for current document
  const permissions = ref([])
  const currentUserRole = ref(null)

  // Version history
  const versions = ref([])

  // Presence/awareness state
  const connectedUsers = ref([])
  const isConnected = ref(false)
  const connectionStatus = ref('disconnected') // 'disconnected', 'connecting', 'connected', 'reconnecting'

  // Comments
  const comments = ref([])
  const commentThreads = ref([])

  // ============================================================
  // COMPUTED
  // ============================================================

  const canEdit = computed(() => {
    return currentUserRole.value === 'owner' || currentUserRole.value === 'editor'
  })

  const canShare = computed(() => {
    return currentUserRole.value === 'owner'
  })

  const canDelete = computed(() => {
    return currentUserRole.value === 'owner'
  })

  const isOwner = computed(() => {
    return currentUserRole.value === 'owner'
  })

  const documentType = computed(() => {
    return currentDocument.value?.type || null
  })

  const isDocument = computed(() => {
    return documentType.value === 'document'
  })

  const isPresentation = computed(() => {
    return documentType.value === 'presentation'
  })

  const activeCollaborators = computed(() => {
    return connectedUsers.value.filter(u => u.email !== currentDocument.value?.owner_email || connectedUsers.value.length === 1)
  })

  // ============================================================
  // ACTIONS - Documents
  // ============================================================

  async function fetchDocuments(params = {}) {
    isLoading.value = true
    error.value = null
    try {
      const response = await collabApi.document.list(params)
      if (response.success) {
        documents.value = response.data.documents
        totalDocuments.value = response.data.total
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function fetchDocument(uuid) {
    isLoading.value = true
    error.value = null
    try {
      const response = await collabApi.document.get(uuid)
      if (response.success) {
        currentDocument.value = response.data.document
        currentUserRole.value = response.data.role
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function createDocument(title, type = 'document', folderId = null) {
    isLoading.value = true
    error.value = null
    try {
      const response = await collabApi.document.create({ title, type, folder_id: folderId })
      if (response.success) {
        const newDoc = response.data.document
        // Add to local list
        documents.value.unshift(newDoc)
        totalDocuments.value++
        // Index for search
        const searchStore = useSearchStore()
        searchStore.indexItem('collab_doc', newDoc.uuid, newDoc)
        return newDoc
      }
      return null
    } catch (e) {
      error.value = e.message
      throw e
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Create a collab document from an existing Drive file
   * This imports the file content into a new collaborative document
   */
  async function createFromDriveFile(driveFile, type = 'document') {
    isLoading.value = true
    error.value = null
    pendingInitialContent.value = null // Clear any previous
    pendingInitialSlides.value = null // Clear any previous
    pendingPresentationMeta.value = null
    try {
      const title = driveFile.original_name || driveFile.name || 'Imported Document'
      const response = await collabApi.document.createFromFile({
        title,
        type,
        drive_file_id: driveFile.id,
      })
      if (response.success) {
        const doc = response.data.document
        documents.value.unshift(doc)
        totalDocuments.value++
        
        // Store initial content if provided (for newly created docs from files)
        if (doc.initial_content) {
          pendingInitialContent.value = {
            uuid: doc.uuid,
            content: doc.initial_content,
          }
        }
        
        // Store initial slides if provided (for presentations from PPTX files)
        if (doc.initial_slides || response.data.initial_slides) {
          pendingInitialSlides.value = {
            uuid: doc.uuid,
            slides: doc.initial_slides || response.data.initial_slides,
            forceReimport: !!response.data.existing,
          }
        }

        if (response.data.presentation_meta) {
          pendingPresentationMeta.value = {
            uuid: doc.uuid,
            meta: response.data.presentation_meta,
          }
        }
        
        return doc
      }
      return null
    } catch (e) {
      error.value = e.message
      throw e
    } finally {
      isLoading.value = false
    }
  }
  
  /**
   * Get and clear pending initial content for a document
   */
  function consumePendingInitialContent(uuid) {
    if (pendingInitialContent.value?.uuid === uuid) {
      const content = pendingInitialContent.value.content
      pendingInitialContent.value = null
      return content
    }
    return null
  }
  
  /**
   * Get and clear pending initial slides for a presentation
   */
  function consumePendingInitialSlides(uuid) {
    if (pendingInitialSlides.value?.uuid === uuid) {
      const { slides, forceReimport } = pendingInitialSlides.value
      pendingInitialSlides.value = null
      return { slides, forceReimport: !!forceReimport }
    }
    return null
  }

  function consumePendingPresentationMeta(uuid) {
    if (pendingPresentationMeta.value?.uuid === uuid) {
      const meta = pendingPresentationMeta.value.meta
      pendingPresentationMeta.value = null
      return meta
    }
    return null
  }

  async function updateDocument(uuid, data) {
    error.value = null
    try {
      const response = await collabApi.document.update(uuid, data)
      if (response.success && currentDocument.value?.uuid === uuid) {
        Object.assign(currentDocument.value, data)
      }
      // Update in list
      const idx = documents.value.findIndex(d => d.uuid === uuid)
      if (idx !== -1) {
        Object.assign(documents.value[idx], data)
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function deleteDocument(uuid) {
    error.value = null
    try {
      const response = await collabApi.document.delete(uuid)
      if (response.success) {
        documents.value = documents.value.filter(d => d.uuid !== uuid)
        totalDocuments.value--
        if (currentDocument.value?.uuid === uuid) {
          currentDocument.value = null
        }
        // Remove from search index
        const searchStore = useSearchStore()
        searchStore.removeFromIndex('collab_doc', uuid)
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function duplicateDocument(uuid) {
    error.value = null
    try {
      const response = await collabApi.document.duplicate(uuid)
      if (response.success) {
        documents.value.unshift(response.data.document)
        totalDocuments.value++
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function getCollabToken(uuid) {
    try {
      const response = await collabApi.document.getCollabToken(uuid)
      return response.data?.token || null
    } catch (e) {
      error.value = e.message
      return null
    }
  }
  
  /**
   * Save document content back to the original Drive file
   */
  async function saveToDrive(uuid, htmlContent, createVersion = true) {
    error.value = null
    try {
      const response = await collabApi.document.saveToDrive(uuid, htmlContent, createVersion)
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  // ============================================================
  // ACTIONS - Permissions
  // ============================================================

  async function fetchPermissions(documentUuid) {
    try {
      const response = await collabApi.permission.list(documentUuid)
      if (response.success) {
        permissions.value = response.data.permissions
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function addCollaborator(documentUuid, email, role) {
    try {
      const response = await collabApi.permission.add(documentUuid, email, role)
      if (response.success) {
        permissions.value.push(response.data.permission)
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function updateCollaboratorRole(documentUuid, email, role) {
    try {
      const response = await collabApi.permission.update(documentUuid, email, role)
      if (response.success) {
        const idx = permissions.value.findIndex(p => p.user_email === email)
        if (idx !== -1) {
          permissions.value[idx].role = role
        }
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function removeCollaborator(documentUuid, email) {
    try {
      const response = await collabApi.permission.remove(documentUuid, email)
      if (response.success) {
        permissions.value = permissions.value.filter(p => p.user_email !== email)
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  // ============================================================
  // ACTIONS - Versions
  // ============================================================

  async function fetchVersions(documentUuid, params = {}) {
    try {
      const response = await collabApi.version.list(documentUuid, params)
      if (response.success) {
        versions.value = response.data.versions
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function createVersion(documentUuid, name) {
    try {
      const response = await collabApi.version.create(documentUuid, name)
      if (response.success) {
        versions.value.unshift(response.data.version)
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function restoreVersion(documentUuid, versionNumber) {
    try {
      const response = await collabApi.version.restore(documentUuid, versionNumber)
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  // ============================================================
  // ACTIONS - Presence
  // ============================================================

  function setConnectionStatus(status) {
    connectionStatus.value = status
    isConnected.value = status === 'connected'
  }

  function setConnectedUsers(users) {
    connectedUsers.value = users
  }

  function addConnectedUser(user) {
    const existing = connectedUsers.value.find(u => u.clientId === user.clientId)
    if (!existing) {
      connectedUsers.value.push(user)
    }
  }

  function removeConnectedUser(clientId) {
    connectedUsers.value = connectedUsers.value.filter(u => u.clientId !== clientId)
  }

  function updateConnectedUser(clientId, updates) {
    const user = connectedUsers.value.find(u => u.clientId === clientId)
    if (user) {
      Object.assign(user, updates)
    }
  }

  // ============================================================
  // ACTIONS - Comments
  // ============================================================

  async function fetchComments(documentUuid) {
    try {
      const response = await collabApi.comment.list(documentUuid)
      if (response.success) {
        comments.value = response.data.comments
        // Group into threads
        const threads = {}
        for (const comment of comments.value) {
          if (!threads[comment.thread_id]) {
            threads[comment.thread_id] = {
              thread_id: comment.thread_id,
              comments: [],
              resolved: comment.resolved_at !== null,
            }
          }
          threads[comment.thread_id].comments.push(comment)
        }
        commentThreads.value = Object.values(threads)
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function addComment(documentUuid, data) {
    try {
      const response = await collabApi.comment.add(documentUuid, data)
      if (response.success) {
        comments.value.push(response.data.comment)
        // Update threads
        await fetchComments(documentUuid)
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  async function resolveThread(documentUuid, threadId) {
    try {
      const response = await collabApi.comment.resolve(documentUuid, threadId)
      if (response.success) {
        const thread = commentThreads.value.find(t => t.thread_id === threadId)
        if (thread) {
          thread.resolved = true
        }
      }
      return response
    } catch (e) {
      error.value = e.message
      throw e
    }
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  function resetState() {
    currentDocument.value = null
    currentUserRole.value = null
    permissions.value = []
    versions.value = []
    connectedUsers.value = []
    isConnected.value = false
    connectionStatus.value = 'disconnected'
    comments.value = []
    commentThreads.value = []
    error.value = null
  }

  return {
    // State
    currentDocument,
    isLoading,
    error,
    documents,
    totalDocuments,
    permissions,
    currentUserRole,
    versions,
    connectedUsers,
    isConnected,
    connectionStatus,
    comments,
    commentThreads,

    // Computed
    canEdit,
    canShare,
    canDelete,
    isOwner,
    documentType,
    isDocument,
    isPresentation,
    activeCollaborators,

    // Actions - Documents
    fetchDocuments,
    fetchDocument,
    createDocument,
    createFromDriveFile,
    consumePendingInitialContent,
    consumePendingInitialSlides,
    consumePendingPresentationMeta,
    updateDocument,
    deleteDocument,
    duplicateDocument,
    getCollabToken,
    saveToDrive,

    // Actions - Permissions
    fetchPermissions,
    addCollaborator,
    updateCollaboratorRole,
    removeCollaborator,

    // Actions - Versions
    fetchVersions,
    createVersion,
    restoreVersion,

    // Actions - Presence
    setConnectionStatus,
    setConnectedUsers,
    addConnectedUser,
    removeConnectedUser,
    updateConnectedUser,

    // Actions - Comments
    fetchComments,
    addComment,
    resolveThread,

    // Cleanup
    resetState,
  }
})

