import { defineStore } from 'pinia'
import { ref, shallowRef, computed } from 'vue'

interface SyncedFile {
  id: number
  remoteId: number
  remoteFolderId: number | null
  localPath: string
  filename: string
  checksum: string
  size: number
  mimeType: string
  remoteUpdatedAt: string
  localUpdatedAt: string
  syncStatus: 'synced' | 'pending_upload' | 'pending_download' | 'conflict' | 'error'
  lastSyncAt: string | null
  is_public?: boolean
  isPublic?: boolean
  public?: boolean
  public_token?: string
  publicToken?: string
  has_public_link?: boolean
  hasPublicLink?: boolean
  share_link?: string
  shareLink?: string
  clientId?: number | null
  clientName?: string | null
}

interface SyncedFolder {
  id: number
  remoteId: number
  remoteParentId: number | null
  localPath: string
  name: string
  syncStatus: 'synced' | 'pending' | 'error'
  lastSyncAt: string | null
  is_public?: boolean
  isPublic?: boolean
  public?: boolean
  public_token?: string
  publicToken?: string
  has_public_link?: boolean
  hasPublicLink?: boolean
  share_link?: string
  shareLink?: string
  color?: string
  clientId?: number | null
  clientName?: string | null
}

interface QuotaInfo {
  used: number
  total: number | null
  percentage: number
}

/**
 * Wave C.2 — Vue reactivity hardening for the FlowOne Drive renderer.
 *
 * Why: rendering a 500-folder sidebar tree was firing
 * `allFolders.filter(f => f.remoteParentId === id)` from every node,
 * giving O(n²) work per render. Vue's deep ref tracking on those large
 * arrays also blew up reactivity overhead. This store now:
 *
 *   - keeps the large arrays in `shallowRef` so Vue tracks them as
 *     opaque snapshots (assignment-only) instead of deep proxying every
 *     row
 *   - exposes precomputed `childrenByParent` (Map) and `parentsWithChildren`
 *     (Set) so consumers do `Map.get(id) ?? []` and `Set.has(id)` instead
 *     of full-array filters / .some() per node
 *   - exposes `byId` and `idsByParent` indexes for O(1) lookups by remote id
 *
 * Public API kept stable for existing components; the new helpers are
 * additive.
 */
export const useFilesStore = defineStore('files', () => {
  const files = shallowRef<SyncedFile[]>([])
  const folders = shallowRef<SyncedFolder[]>([])
  const allFolders = shallowRef<SyncedFolder[]>([])
  const allFiles = shallowRef<SyncedFile[]>([])
  const currentFolderId = ref<number | null>(null)
  const isLoading = ref(false)

  const folderById = computed<Map<number, SyncedFolder>>(() => {
    const map = new Map<number, SyncedFolder>()
    for (const f of allFolders.value) {
      if (typeof f.remoteId === 'number') map.set(f.remoteId, f)
    }
    return map
  })

  const childrenByParent = computed<Map<number | null, SyncedFolder[]>>(() => {
    const map = new Map<number | null, SyncedFolder[]>()
    for (const f of allFolders.value) {
      const key = f.remoteParentId ?? null
      const arr = map.get(key)
      if (arr) arr.push(f)
      else map.set(key, [f])
    }
    return map
  })

  const parentsWithChildren = computed<Set<number>>(() => {
    const set = new Set<number>()
    for (const f of allFolders.value) {
      if (typeof f.remoteParentId === 'number') set.add(f.remoteParentId)
    }
    return set
  })

  const fileIdsByFolder = computed<Map<number | null, number[]>>(() => {
    const map = new Map<number | null, number[]>()
    for (const f of allFiles.value) {
      const key = f.remoteFolderId ?? null
      const arr = map.get(key)
      if (arr) arr.push(f.remoteId)
      else map.set(key, [f.remoteId])
    }
    return map
  })

  function getChildFolders(parentId: number | null): SyncedFolder[] {
    return childrenByParent.value.get(parentId) ?? []
  }
  function hasChildFolders(folderId: number): boolean {
    return parentsWithChildren.value.has(folderId)
  }
  function getFolderById(id: number): SyncedFolder | undefined {
    return folderById.value.get(id)
  }

  const quota = ref<QuotaInfo>({
    used: 0,
    total: null,
    percentage: 0
  })

  const totalSize = computed(() => {
    return quota.value.used || allFiles.value.reduce((sum, file) => sum + (file.size || 0), 0)
  })

  async function loadFiles(folderId?: number) {
    isLoading.value = true
    currentFolderId.value = folderId ?? null
    
    try {
      const result = await window.api.getFiles(folderId)
      files.value = (result.files || []).map(normalizeFileSharing)
      folders.value = (result.folders || []).map(normalizeFolderSharing)
      
      if (result.quota) {
        quota.value = {
          used: result.quota.used || 0,
          total: result.quota.total || null,
          percentage: result.quota.percentage || 0
        }
      }
      
      if (folderId === undefined) {
        allFiles.value = (result.files || []).map(normalizeFileSharing)
      }
    } catch (e) {
      console.error('Failed to load files:', e)
      files.value = []
      folders.value = []
    } finally {
      isLoading.value = false
    }
  }
  
  async function loadTrash() {
    isLoading.value = true
    currentFolderId.value = null
    
    try {
      const result = await window.api.getTrash()
      files.value = (result.files || []).map((file: any) => ({
        ...normalizeFileSharing(file),
        remoteId: file.id,
        filename: file.original_name || file.filename,
        mimeType: file.mime_type,
        size: file.size,
        trashedAt: file.trashed_at,
        originalLocation: file.original_location,
      }))
      folders.value = (result.folders || []).map((folder: any) => ({
        ...normalizeFolderSharing(folder),
        remoteId: folder.id,
        name: folder.name,
        trashedAt: folder.trashed_at,
        originalLocation: folder.original_location,
      }))
    } catch (e) {
      console.error('Failed to load trash:', e)
      files.value = []
      folders.value = []
    } finally {
      isLoading.value = false
    }
  }
  
  function normalizeFileSharing(file: any): SyncedFile {
    const shareToken = file.share_token || file.publicToken || null
    return {
      ...file,
      isPublic: file.isPublic || !!shareToken,
      publicToken: shareToken,
      hasPublicLink: file.hasPublicLink || !!shareToken,
      shareLink: file.shareLink || null,
      clientId: file.clientId || null,
      clientName: file.clientName || null,
    }
  }
  
  function normalizeFolderSharing(folder: any): SyncedFolder {
    const shareToken = folder.share_token || folder.publicToken || null
    return {
      ...folder,
      isPublic: folder.isPublic || !!shareToken,
      publicToken: shareToken,
      hasPublicLink: folder.hasPublicLink || !!shareToken,
      shareLink: folder.shareLink || null,
      color: folder.color || null,
      clientId: folder.clientId || null,
      clientName: folder.clientName || null,
    }
  }
  
  async function loadAllFolders() {
    try {
      const result = await window.api.getAllFolders()
      console.log('[FilesStore] Loaded all folders:', result.folders?.length || 0)
      allFolders.value = (result.folders || []).map(normalizeFolderSharing)
    } catch (e) {
      console.error('Failed to load all folders:', e)
    }
  }
  
  async function fetchQuota() {
    try {
      const result = await window.api.getQuota()
      if (result) {
        quota.value = {
          used: result.used || 0,
          total: result.total || null,
          percentage: result.percentage || 0
        }
      }
    } catch (e) {
      console.error('Failed to fetch quota:', e)
    }
  }
  
  function clearFiles() {
    files.value = []
    folders.value = []
    allFolders.value = []
    allFiles.value = []
    currentFolderId.value = null
    quota.value = { used: 0, total: null, percentage: 0 }
  }
  
  return {
    files,
    folders,
    allFolders,
    allFiles,
    currentFolderId,
    isLoading,
    totalSize,
    quota,
    folderById,
    childrenByParent,
    parentsWithChildren,
    fileIdsByFolder,
    getChildFolders,
    hasChildFolders,
    getFolderById,
    loadFiles,
    loadTrash,
    loadAllFolders,
    fetchQuota,
    clearFiles,
  }
})

