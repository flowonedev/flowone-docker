import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { ref } from 'vue'
import { mockApi, resetAllMocks } from '../../helpers/setup.js'

vi.mock('@/services/mailSyncSocket', () => ({
  useMailSyncSocket: vi.fn(() => ({
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    send: vi.fn(),
  })),
  EventTypes: {},
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: vi.fn(() => ({
    user: { email: 'test@flowone.pro', name: 'Test' },
    token: 'mock-token',
  })),
}))

vi.mock('@/addons/kanban-boards/stores/boards', () => ({
  useBoardsStore: vi.fn(() => ({
    boards: [],
    fetchBoards: vi.fn(),
  })),
}))

const mockWsService = {
  collaborators: ref([]),
  subscribeToBoardEvents: vi.fn(),
  unsubscribeFromBoardEvents: vi.fn(),
  sendCursorPosition: vi.fn(),
  onCommentEvent: vi.fn(() => vi.fn()),
}

vi.mock('../../../src/addons/moodboards/services/moodBoardWebSocketService.js', () => ({
  setupWebSocketService: vi.fn(() => mockWsService),
}))

vi.mock('../../../src/addons/moodboards/utils/layerOrderUtils.js', () => ({
  layerScope: vi.fn(() => 'default'),
  nextZIndexInScope: vi.fn(() => 1),
}))

vi.mock('../../../src/addons/moodboards/services/undoIndexedDB.js', () => ({
  loadUndoStack: vi.fn(() => null),
  saveUndoStack: vi.fn(),
}))

import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards.js'

describe('moodBoards store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    resetAllMocks()
    store = useMoodBoardsStore()
  })

  describe('initial state', () => {
    it('should have empty boards', () => {
      expect(store.boards).toEqual([])
    })

    it('should have null currentBoard', () => {
      expect(store.currentBoard).toBeNull()
    })

    it('should not be loading', () => {
      expect(store.loading).toBe(false)
      expect(store.boardLoading).toBe(false)
    })

    it('should have default zoom and pan', () => {
      expect(store.zoom).toBe(1)
      expect(store.panX).toBe(0)
      expect(store.panY).toBe(0)
    })

    it('should have empty selection', () => {
      expect(store.selectedItems).toEqual([])
    })

    it('should not be in presentation mode', () => {
      expect(store.presentationMode).toBe(false)
    })

    it('should have empty folders', () => {
      expect(store.folders).toEqual([])
    })
  })

  describe('computed properties', () => {
    it('should compute activeBoards (non-archived)', () => {
      store.boards = [
        { id: 1, name: 'Active Board', archived: false },
        { id: 2, name: 'Archived Board', archived: true },
        { id: 3, name: 'Another Active', archived: false },
      ]

      expect(store.activeBoards).toHaveLength(2)
      expect(store.activeBoards.map((b) => b.id)).toEqual([1, 3])
    })

    it('should compute archivedBoards', () => {
      store.boards = [
        { id: 1, name: 'Active', archived: false },
        { id: 2, name: 'Archived', archived: true },
      ]

      expect(store.archivedBoards).toHaveLength(1)
      expect(store.archivedBoards[0].id).toBe(2)
    })

    it('should compute currentItems from currentBoard', () => {
      expect(store.currentItems).toEqual([])

      store.currentBoard = {
        id: 1,
        items: [{ id: 10, type: 'card' }],
        connections: [],
      }

      expect(store.currentItems).toHaveLength(1)
    })

    it('should compute currentConnections from currentBoard', () => {
      expect(store.currentConnections).toEqual([])

      store.currentBoard = {
        id: 1,
        items: [],
        connections: [{ id: 20, from_item_id: 1, to_item_id: 2 }],
      }

      expect(store.currentConnections).toHaveLength(1)
    })
  })

  describe('fetchBoards', () => {
    it('should GET and populate boards', async () => {
      const boards = [
        { id: 1, name: 'Board A' },
        { id: 2, name: 'Board B' },
      ]

      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { boards } },
      })

      await store.fetchBoards()

      expect(mockApi.get).toHaveBeenCalledWith('/mood-boards', { params: {} })
      expect(store.boards).toEqual(boards)
      expect(store.loading).toBe(false)
    })

    it('should pass include_archived param', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { boards: [] } },
      })

      await store.fetchBoards(true)

      expect(mockApi.get).toHaveBeenCalledWith('/mood-boards', {
        params: { include_archived: 'true' },
      })
    })

    it('should handle API error', async () => {
      mockApi.get.mockRejectedValueOnce(new Error('Network error'))

      await store.fetchBoards()

      expect(store.loading).toBe(false)
    })
  })

  describe('createBoard', () => {
    it('should POST and add board to list', async () => {
      const newBoard = { id: 5, name: 'New Board' }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { board: newBoard } },
      })

      const result = await store.createBoard({ name: 'New Board' })

      expect(mockApi.post).toHaveBeenCalledWith('/mood-boards', { name: 'New Board' })
      expect(result).toEqual(newBoard)
      expect(store.boards[0]).toEqual(newBoard)
    })
  })

  describe('updateBoard', () => {
    it('should PUT and update board in list', async () => {
      store.boards = [{ id: 1, name: 'Old Name' }]
      store.currentBoard = { id: 1, name: 'Old Name' }

      mockApi.put.mockResolvedValueOnce({
        data: { success: true, data: { board: { id: 1, name: 'New Name' } } },
      })

      const result = await store.updateBoard(1, { name: 'New Name' })

      expect(mockApi.put).toHaveBeenCalledWith('/mood-boards/1', { name: 'New Name' })
      expect(result.name).toBe('New Name')
      expect(store.boards[0].name).toBe('New Name')
      expect(store.currentBoard.name).toBe('New Name')
    })
  })

  describe('deleteBoard', () => {
    it('should DELETE and remove board from list', async () => {
      store.boards = [
        { id: 1, name: 'Keep' },
        { id: 2, name: 'Remove' },
      ]
      store.currentBoard = { id: 2, name: 'Remove' }

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.deleteBoard(2)

      expect(mockApi.delete).toHaveBeenCalledWith('/mood-boards/2')
      expect(result).toBe(true)
      expect(store.boards).toHaveLength(1)
      expect(store.currentBoard).toBeNull()
    })
  })

  describe('duplicateBoard', () => {
    it('should POST and add duplicate to list', async () => {
      const dupe = { id: 10, name: 'Board Copy' }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { board: dupe } },
      })

      const result = await store.duplicateBoard(1, 'Board Copy')

      expect(mockApi.post).toHaveBeenCalledWith('/mood-boards/1/duplicate', { name: 'Board Copy' })
      expect(result).toEqual(dupe)
      expect(store.boards[0]).toEqual(dupe)
    })
  })

  describe('toggleReady', () => {
    it('should POST and update board ready state', async () => {
      store.boards = [{ id: 1, name: 'Board', is_ready: false }]
      store.currentBoard = { id: 1, name: 'Board', is_ready: false }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { board: { id: 1, is_ready: true } } },
      })

      const result = await store.toggleReady(1)

      expect(mockApi.post).toHaveBeenCalledWith('/mood-boards/1/ready')
      expect(result.is_ready).toBe(true)
    })
  })

  describe('folder operations', () => {
    it('should fetch folders', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { folders: [{ id: 1, name: 'Folder A' }] } },
      })

      await store.fetchFolders()

      expect(mockApi.get).toHaveBeenCalledWith('/mood-boards/folders')
      expect(store.folders).toHaveLength(1)
    })

    it('should create folder', async () => {
      const folder = { id: 5, name: 'New Folder' }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { folder } },
      })

      const result = await store.createFolder({ name: 'New Folder' })

      expect(mockApi.post).toHaveBeenCalledWith('/mood-boards/folders', { name: 'New Folder' })
      expect(result).toEqual(folder)
      expect(store.folders).toContainEqual(folder)
    })

    it('should delete folder and refetch boards', async () => {
      store.folders = [{ id: 1, name: 'Remove' }]

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })
      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { boards: [] } },
      })

      const result = await store.deleteFolder(1)

      expect(mockApi.delete).toHaveBeenCalledWith('/mood-boards/folders/1')
      expect(result).toBe(true)
      expect(store.folders).toHaveLength(0)
    })

    it('should move board to folder', async () => {
      store.boards = [{ id: 1, name: 'Board', folder_id: null }]

      mockApi.put.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.moveBoard(1, 5)

      expect(mockApi.put).toHaveBeenCalledWith('/mood-boards/1/move', { folder_id: 5 })
      expect(result).toBe(true)
      expect(store.boards[0].folder_id).toBe(5)
    })
  })

  describe('item operations', () => {
    beforeEach(() => {
      store.currentBoard = {
        id: 100,
        items: [],
        connections: [],
        members: [],
        groups: [],
      }
    })

    it('should add item via POST', async () => {
      const serverItem = { id: 50, type: 'card', x: 100, y: 200, board_id: 100 }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { item: serverItem } },
      })

      const result = await store.addItem({ type: 'card', x: 100, y: 200 })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/mood-boards/100/items',
        expect.objectContaining({ type: 'card', x: 100, y: 200 })
      )
      expect(result).toBeDefined()
    })

    it('should delete item and remove related connections', async () => {
      store.currentBoard.items = [
        { id: 50, type: 'card' },
        { id: 51, type: 'card' },
      ]
      store.currentBoard.connections = [
        { id: 1, from_item_id: 50, to_item_id: 51 },
      ]

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.deleteItem(50)

      expect(mockApi.delete).toHaveBeenCalledWith('/mood-boards/100/items/50')
      expect(result).toBe(true)
      expect(store.currentBoard.items).toHaveLength(1)
      expect(store.currentBoard.connections).toHaveLength(0)
    })
  })

  describe('connection operations', () => {
    beforeEach(() => {
      store.currentBoard = {
        id: 100,
        items: [{ id: 1 }, { id: 2 }],
        connections: [],
        members: [],
      }
    })

    it('should add connection', async () => {
      const conn = { id: 10, from_item_id: 1, to_item_id: 2 }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { connection: conn } },
      })

      const result = await store.addConnection({
        from_item_id: 1,
        to_item_id: 2,
      })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/mood-boards/100/connections',
        expect.objectContaining({ from_item_id: 1, to_item_id: 2 })
      )
      expect(result).toEqual(conn)
      expect(store.currentBoard.connections).toHaveLength(1)
    })

    it('should delete connection', async () => {
      store.currentBoard.connections = [
        { id: 10, from_item_id: 1, to_item_id: 2 },
      ]

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.deleteConnection(10)

      expect(mockApi.delete).toHaveBeenCalledWith('/mood-boards/100/connections/10')
      expect(result).toBe(true)
      expect(store.currentBoard.connections).toHaveLength(0)
    })
  })

  describe('member operations', () => {
    beforeEach(() => {
      store.currentBoard = {
        id: 100,
        items: [],
        connections: [],
        members: [{ email: 'owner@flowone.pro', role: 'owner' }],
      }
    })

    it('should add member', async () => {
      mockApi.post.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            members: [
              { email: 'owner@flowone.pro', role: 'owner' },
              { email: 'new@flowone.pro', role: 'editor' },
            ],
          },
        },
      })

      const result = await store.addMember('new@flowone.pro', 'editor')

      expect(mockApi.post).toHaveBeenCalledWith('/mood-boards/100/members', {
        email: 'new@flowone.pro',
        role: 'editor',
      })
      expect(result).toBe(true)
      expect(store.currentBoard.members).toHaveLength(2)
    })

    it('should remove member', async () => {
      store.currentBoard.members.push({ email: 'remove@flowone.pro', role: 'viewer' })

      mockApi.delete.mockResolvedValueOnce({
        data: { success: true, data: { members: [{ email: 'owner@flowone.pro', role: 'owner' }] } },
      })

      const result = await store.removeMember('remove@flowone.pro')

      expect(mockApi.delete).toHaveBeenCalledWith(
        '/mood-boards/100/members/remove%40flowone.pro'
      )
      expect(result).toBe(true)
    })
  })

  describe('share link operations', () => {
    beforeEach(() => {
      store.currentBoard = {
        id: 100,
        share_token: null,
        share_mode: 'off',
        share_expires: null,
      }
    })

    it('should create share link', async () => {
      mockApi.post.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            token: 'share-abc',
            expires_at: '2026-04-01',
            url: 'https://flowone.pro/boards/share/share-abc',
          },
        },
      })

      const result = await store.createShareLink(100, { mode: 'view' })

      expect(mockApi.post).toHaveBeenCalledWith('/mood-boards/100/share', {
        mode: 'view',
        password: null,
        expires_hours: null,
      })
      expect(result.token).toBe('share-abc')
      expect(store.currentBoard.share_token).toBe('share-abc')
    })

    it('should remove share link', async () => {
      store.currentBoard.share_token = 'old-token'
      store.currentBoard.share_mode = 'view'

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      const result = await store.removeShareLink(100)

      expect(mockApi.delete).toHaveBeenCalledWith('/mood-boards/100/share')
      expect(result).toBe(true)
      expect(store.currentBoard.share_token).toBeNull()
      expect(store.currentBoard.share_mode).toBe('off')
    })
  })

  describe('selection helpers', () => {
    beforeEach(() => {
      store.currentBoard = {
        id: 100,
        items: [
          { id: 1, type: 'card' },
          { id: 2, type: 'card' },
          { id: 3, type: 'image' },
        ],
        connections: [],
      }
    })

    it('should compute selectedItems from selectedItemIds', () => {
      store.selectedItemIds = new Set([1, 3])

      expect(store.selectedItems).toHaveLength(2)
      expect(store.selectedItems.map((i) => i.id)).toEqual([1, 3])
    })
  })

  describe('presentation mode', () => {
    it('should track presentation slides', () => {
      store.currentBoard = {
        id: 100,
        items: [
          { id: 1, type: 'card' },
          { id: 2, type: 'slide', slide_order: 1 },
          { id: 3, type: 'slide', slide_order: 0 },
        ],
        connections: [],
      }

      expect(store.presentationSlides).toHaveLength(2)
      expect(store.presentationSlides[0].id).toBe(3)
    })
  })

  describe('container navigation', () => {
    it('enters a frame like a group container', () => {
      store.currentBoard = {
        id: 1,
        items: [
          { id: 10, type: 'frame' },
          { id: 11, type: 'text', parent_id: 10 },
        ],
        connections: [],
      }

      store.enterGroup(10)

      expect(store.editingGroupId).toBe(10)
      expect(store.selectedItemIds).toEqual(new Set([11]))
    })

    it('exits a nested container to its parent container', () => {
      store.currentBoard = {
        id: 1,
        items: [
          { id: 20, type: 'frame' },
          { id: 21, type: 'column', parent_id: 20 },
          { id: 22, type: 'text', parent_id: 21 },
        ],
        connections: [],
      }
      store.editingGroupId = 21
      store.selectedItemIds = new Set([22])

      store.exitGroup()

      expect(store.editingGroupId).toBe(20)
      expect(store.selectedItemIds).toEqual(new Set([21]))
    })
  })
})
