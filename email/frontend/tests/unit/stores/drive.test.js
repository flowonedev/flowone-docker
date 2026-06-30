import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mockApi, resetAllMocks } from '../../helpers/setup.js'

vi.mock('@/services/tokenStorage', () => ({
  getToken: vi.fn(() => 'mock-token'),
}))

vi.mock('@/addons/universal-search/stores/search', () => ({
  useSearchStore: vi.fn(() => ({
    indexItem: vi.fn(),
    removeFromIndex: vi.fn(),
  })),
}))

import { useDriveStore } from '@/stores/drive.js'

describe('drive store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    resetAllMocks()
    store = useDriveStore()
  })

  describe('initial state', () => {
    it('should have empty folders and files', () => {
      expect(store.folders).toEqual([])
      expect(store.files).toEqual([])
    })

    it('should have null currentFolder', () => {
      expect(store.currentFolder).toBeNull()
    })

    it('should not be loading', () => {
      expect(store.loading).toBe(false)
    })

    it('should not be uploading', () => {
      expect(store.uploading).toBe(false)
      expect(store.uploadProgress).toBe(0)
    })

    it('should have unlimited quota by default', () => {
      expect(store.quota.unlimited).toBe(true)
    })

    it('should default to grid view mode', () => {
      expect(['grid', 'list']).toContain(store.viewMode)
    })

    it('should have empty trash', () => {
      expect(store.trashedItems).toEqual({ files: [], folders: [] })
    })

    it('should have no selection', () => {
      expect(store.hasSelection).toBe(false)
      expect(store.selectionCount).toBe(0)
    })

    it('should have empty clipboard', () => {
      expect(store.hasClipboard).toBeFalsy()
    })
  })

  describe('formattedQuota', () => {
    it('should show unlimited when quota is unlimited', () => {
      expect(store.formattedQuota.quota).toBe('Unlimited')
      expect(store.formattedQuota.percentUsed).toBe(0)
    })
  })

  describe('fetchContents', () => {
    it('should call API and populate state for root', async () => {
      const folders = [{ id: 1, name: 'Documents' }]
      const files = [{ id: 10, name: 'readme.txt', size: 100 }]

      mockApi.get.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            folders,
            files,
            current_folder: null,
            path: [],
            quota: { quota: -1, used: 500, available: -1, unlimited: true },
          },
        },
      })

      await store.fetchContents()

      expect(mockApi.get).toHaveBeenCalledWith('/drive', { params: {} })
      expect(store.folders).toEqual(folders)
      expect(store.files).toEqual(files)
    })

    it('should pass folder_id when provided', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            folders: [],
            files: [],
            current_folder: { id: 5, name: 'Sub' },
            path: [{ id: 5, name: 'Sub' }],
            quota: { quota: -1, used: 0, available: -1, unlimited: true },
          },
        },
      })

      await store.fetchContents(5)

      expect(mockApi.get).toHaveBeenCalledWith('/drive', { params: { folder_id: 5 } })
    })

    it('should handle API error gracefully', async () => {
      mockApi.get.mockRejectedValueOnce(new Error('Server down'))

      await store.fetchContents()

      expect(store.folders).toEqual([])
      expect(store.loading).toBe(false)
    })
  })

  describe('createFolder', () => {
    it('should POST and add folder to state', async () => {
      const newFolder = { id: 2, name: 'New Folder' }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { folder: newFolder } },
      })

      const result = await store.createFolder('New Folder')

      expect(mockApi.post).toHaveBeenCalledWith('/drive/folders', {
        name: 'New Folder',
        parent_id: undefined,
      })
      expect(result.success).toBe(true)
      expect(result.folder).toEqual(newFolder)
      expect(store.folders).toContainEqual(newFolder)
    })
  })

  describe('renameFolder', () => {
    it('should PUT and update local folder name', async () => {
      store.folders = [{ id: 3, name: 'Old Name' }]

      mockApi.put.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.renameFolder(3, 'New Name')

      expect(mockApi.put).toHaveBeenCalledWith('/drive/folders/3', { name: 'New Name' })
      expect(result).toBe(true)
      expect(store.folders[0].name).toBe('New Name')
    })
  })

  describe('updateFolderColor', () => {
    it('should PUT color and update local state', async () => {
      store.folders = [{ id: 4, name: 'Colored', color: null }]

      mockApi.put.mockResolvedValueOnce({
        data: { success: true, data: { folder: { id: 4, color: '#ff0000' } } },
      })

      const result = await store.updateFolderColor(4, '#ff0000')

      expect(mockApi.put).toHaveBeenCalledWith('/drive/folders/4/color', { color: '#ff0000' })
      expect(result.success).toBe(true)
      expect(store.folders[0].color).toBe('#ff0000')
    })
  })

  describe('deleteFolder', () => {
    it('should DELETE and remove folder from state', async () => {
      store.folders = [
        { id: 1, name: 'Keep' },
        { id: 2, name: 'Remove' },
      ]

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.deleteFolder(2)

      expect(mockApi.delete).toHaveBeenCalledWith('/drive/folders/2')
      expect(result).toBe(true)
      expect(store.folders).toHaveLength(1)
      expect(store.folders[0].id).toBe(1)
    })
  })

  describe('uploadFile', () => {
    it('should POST FormData and add file to state', async () => {
      const newFile = { id: 20, name: 'photo.jpg', size: 5000 }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { file: newFile } },
      })

      const fakeFile = new File(['data'], 'photo.jpg', { type: 'image/jpeg' })
      const result = await store.uploadFile(fakeFile)

      expect(mockApi.post).toHaveBeenCalledWith(
        '/drive/upload',
        expect.any(FormData),
        expect.objectContaining({
          headers: { 'Content-Type': 'multipart/form-data' },
        })
      )
      expect(result.success).toBe(true)
      expect(store.files).toContainEqual(newFile)
      expect(store.uploading).toBe(false)
    })
  })

  describe('deleteFile', () => {
    it('should DELETE and remove file from state', async () => {
      store.files = [
        { id: 10, name: 'keep.txt', size: 100 },
        { id: 20, name: 'remove.txt', size: 200 },
      ]
      store.quota = { quota: 10000, used: 300, available: 9700, unlimited: false }

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.deleteFile(20)

      expect(mockApi.delete).toHaveBeenCalledWith('/drive/files/20')
      expect(result).toBe(true)
      expect(store.files).toHaveLength(1)
      expect(store.quota.used).toBe(100)
    })
  })

  describe('renameFile', () => {
    it('should PUT and update local file name', async () => {
      store.files = [{ id: 10, name: 'old.txt', original_name: 'old.txt' }]

      mockApi.put.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.renameFile(10, 'new.txt')

      expect(mockApi.put).toHaveBeenCalledWith('/drive/files/10', { name: 'new.txt' })
      expect(result).toBe(true)
      expect(store.files[0].original_name).toBe('new.txt')
    })
  })

  describe('moveFile', () => {
    it('should POST and remove file from current view', async () => {
      store.files = [{ id: 10, name: 'moved.txt' }]

      mockApi.post.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.moveFile(10, 5)

      expect(mockApi.post).toHaveBeenCalledWith('/drive/files/10/move', { folder_id: 5 })
      expect(result).toBe(true)
      expect(store.files).toHaveLength(0)
    })
  })

  describe('moveFolder', () => {
    it('should POST and remove folder from current view', async () => {
      store.folders = [{ id: 3, name: 'MoveMe' }]

      mockApi.post.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.moveFolder(3, 10)

      expect(mockApi.post).toHaveBeenCalledWith('/drive/folders/3/move', { parent_id: 10 })
      expect(result).toBe(true)
      expect(store.folders).toHaveLength(0)
    })
  })

  describe('createShareLink', () => {
    it('should POST and return share data', async () => {
      store.files = [{ id: 10, name: 'shared.pdf', share_token: null }]

      mockApi.post.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            url: 'https://flowone.pro/share/abc',
            token: 'abc',
            max_downloads: null,
            has_password: false,
          },
        },
      })

      const result = await store.createShareLink(10, 168)

      expect(mockApi.post).toHaveBeenCalledWith('/drive/files/10/share', {
        expires_hours: 168,
        max_downloads: null,
        password: null,
        is_email_attachment: false,
      })
      expect(result.success).toBe(true)
      expect(result.url).toBe('https://flowone.pro/share/abc')
      expect(store.files[0].share_token).toBe('abc')
    })
  })

  describe('removeShareLink', () => {
    it('should DELETE and clear share_token', async () => {
      store.files = [{ id: 10, name: 'shared.pdf', share_token: 'abc' }]

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.removeShareLink(10)

      expect(mockApi.delete).toHaveBeenCalledWith('/drive/files/10/share')
      expect(result).toBe(true)
      expect(store.files[0].share_token).toBeNull()
    })
  })

  describe('fetchAllFolders', () => {
    it('should GET and populate allFolders', async () => {
      const allFoldersList = [
        { id: 1, name: 'Root' },
        { id: 2, name: 'Sub', parent_id: 1 },
      ]

      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { folders: allFoldersList } },
      })

      const result = await store.fetchAllFolders()

      expect(mockApi.get).toHaveBeenCalledWith('/drive/folders/all')
      expect(store.allFolders).toEqual(allFoldersList)
      expect(result).toEqual(allFoldersList)
    })
  })

  describe('trash operations', () => {
    it('should fetch trash items', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            files: [{ id: 1, name: 'trashed.txt' }],
            folders: [{ id: 2, name: 'trashed-folder' }],
          },
        },
      })

      await store.fetchTrash()

      expect(mockApi.get).toHaveBeenCalledWith('/drive/trash')
      expect(store.trashedItems.files).toHaveLength(1)
      expect(store.trashedItems.folders).toHaveLength(1)
    })

    it('should trash a file and update quota', async () => {
      store.files = [{ id: 10, name: 'delete-me.txt', size: 500 }]
      store.quota = { used: 1000, available: 9000, unlimited: false }

      mockApi.post.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.trashFile(10)

      expect(mockApi.post).toHaveBeenCalledWith('/drive/files/10/trash')
      expect(result).toBe(true)
      expect(store.files).toHaveLength(0)
      expect(store.quota.used).toBe(500)
    })

    it('should restore a file from trash', async () => {
      store.trashedItems = {
        files: [{ id: 10, name: 'restore-me.txt' }],
        folders: [],
      }

      mockApi.post.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.restoreFile(10)

      expect(mockApi.post).toHaveBeenCalledWith('/drive/files/10/restore')
      expect(result).toBe(true)
      expect(store.trashedItems.files).toHaveLength(0)
    })

    it('should empty trash', async () => {
      store.trashedItems = {
        files: [{ id: 1 }],
        folders: [{ id: 2 }],
      }

      mockApi.delete.mockResolvedValueOnce({
        data: { success: true, data: { deleted_count: 2 } },
      })

      const result = await store.emptyTrash()

      expect(mockApi.delete).toHaveBeenCalledWith('/drive/trash')
      expect(result.success).toBe(true)
      expect(result.count).toBe(2)
      expect(store.trashedItems).toEqual({ files: [], folders: [] })
    })

    it('should permanently delete a file from trash', async () => {
      store.trashedItems = {
        files: [{ id: 10, name: 'gone.txt' }],
        folders: [],
      }

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.permanentlyDelete(10, 'file')

      expect(mockApi.delete).toHaveBeenCalledWith('/drive/trash/file/10')
      expect(result).toBe(true)
      expect(store.trashedItems.files).toHaveLength(0)
    })
  })

  describe('selection', () => {
    it('should toggle file selection', () => {
      store.toggleFileSelection(1)
      expect(store.isFileSelected(1)).toBe(true)
      expect(store.selectionCount).toBe(1)

      store.toggleFileSelection(1)
      expect(store.isFileSelected(1)).toBe(false)
    })

    it('should toggle folder selection', () => {
      store.toggleFolderSelection(5)
      expect(store.isFolderSelected(5)).toBe(true)

      store.toggleFolderSelection(5)
      expect(store.isFolderSelected(5)).toBe(false)
    })

    it('should select all files and folders', () => {
      store.files = [{ id: 1 }, { id: 2 }]
      store.folders = [{ id: 10 }, { id: 20 }]

      store.selectAll()

      expect(store.selectionCount).toBe(4)
      expect(store.hasSelection).toBe(true)
    })

    it('should clear selection', () => {
      store.toggleFileSelection(1)
      store.toggleFolderSelection(5)

      store.clearSelection()

      expect(store.hasSelection).toBe(false)
      expect(store.selectionCount).toBe(0)
    })
  })

  describe('clipboard', () => {
    it('should set clipboard on copy', () => {
      store.toggleFileSelection(1)
      store.toggleFolderSelection(5)

      store.clipboardCopy()

      expect(store.hasClipboard).toBe(true)
      expect(store.clipboard.mode).toBe('copy')
      expect(store.clipboardCount).toBe(2)
    })

    it('should set clipboard on cut', () => {
      store.toggleFileSelection(2)

      store.clipboardCut()

      expect(store.clipboard.mode).toBe('cut')
      expect(store.clipboardCount).toBe(1)
    })

    it('should clear clipboard', () => {
      store.toggleFileSelection(1)
      store.clipboardCopy()

      store.clipboardClear()

      expect(store.hasClipboard).toBeFalsy()
    })
  })

  describe('view mode', () => {
    it('should switch between grid and list', () => {
      store.setViewMode('list')
      expect(store.viewMode).toBe('list')

      store.setViewMode('grid')
      expect(store.viewMode).toBe('grid')
    })
  })

  describe('navigation', () => {
    it('should navigate to root', () => {
      store.currentFolderId = 5
      store.navigateToRoot()

      expect(store.currentFolderId).toBeNull()
    })
  })
})
