import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mockApi, resetAllMocks } from '../../helpers/setup.js'

vi.mock('@/stores/conversations', () => ({
  useConversationsStore: vi.fn(() => ({
    setConversationsFromResponse: vi.fn(),
    fetchConversations: vi.fn(),
    handleFolderRenamed: vi.fn(),
    removeMessageLocally: vi.fn(),
    moveMessage: vi.fn(),
    splitMessage: vi.fn(),
    conversationsByFolder: {},
    getConversationsForFolder: vi.fn(() => ({})),
    updateVersion: 0,
  })),
}))

vi.mock('@/composables/useConversationGrouping', () => ({
  useConversationGrouping: vi.fn(() => ({
    conversations: { value: [] },
  })),
}))

vi.mock('@/stores/filters', () => ({
  useFiltersStore: vi.fn(() => ({
    fetchFilters: vi.fn(),
  })),
}))

import { useMailboxStore } from '@/stores/mailbox.js'

describe('mailbox store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    resetAllMocks()
    store = useMailboxStore()
  })

  describe('initial state', () => {
    it('should have empty folders array', () => {
      expect(store.folders).toEqual([])
    })

    it('should default currentFolder to INBOX', () => {
      expect(store.currentFolder).toBe('INBOX')
    })

    it('should have null currentMessage', () => {
      expect(store.currentMessage).toBe(null)
    })

    it('should have empty selectedMessages array', () => {
      expect(store.selectedMessages).toEqual([])
    })

    it('should default to conversation view enabled', () => {
      expect(store.conversationView).toBe(true)
    })

    it('should have loading as object with false values', () => {
      expect(store.loading.folders).toBe(false)
      expect(store.loading.messages).toBe(false)
      expect(store.loading.message).toBe(false)
    })
  })

  describe('fetchFolders', () => {
    it('should call API and populate folders', async () => {
      const folders = [
        { name: 'INBOX', unseen: 5 },
        { name: 'Sent', unseen: 0 },
        { name: 'Trash', unseen: 0 },
      ]

      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { folders } },
      })

      await store.fetchFolders()

      expect(mockApi.get).toHaveBeenCalledWith('/mailbox/folders')
      expect(store.folders).toEqual(folders)
    })

    it('should handle API error gracefully', async () => {
      mockApi.get.mockRejectedValueOnce(new Error('Network error'))

      await store.fetchFolders()

      expect(store.folders).toEqual([])
    })
  })

  describe('fetchMessages', () => {
    it('should call API with folder and page params', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            messages: [{ uid: 1, subject: 'Hello' }],
            total: 1,
            page: 1,
            pages: 1,
          },
        },
      })

      await store.fetchMessages('INBOX', 1)

      expect(mockApi.get).toHaveBeenCalledWith(
        expect.stringContaining('/mailbox/INBOX/messages'),
        expect.any(Object)
      )
    })

    it('should set loading.messages during fetch', async () => {
      let resolvePromise
      mockApi.get.mockReturnValueOnce(
        new Promise((resolve) => {
          resolvePromise = resolve
        })
      )

      const fetchPromise = store.fetchMessages('INBOX', 1)
      expect(store.loading.messages).toBe(true)

      resolvePromise({
        data: { success: true, data: { messages: [], total: 0, page: 1, pages: 0 } },
      })
      await fetchPromise

      expect(store.loading.messages).toBe(false)
    })
  })

  describe('setFlag', () => {
    it('should call API with flag in URL query string', async () => {
      mockApi.post.mockResolvedValueOnce({ data: { success: true } })
      mockApi.get.mockResolvedValue({ data: { success: true, data: { folders: [] } } })

      await store.setFlag(123, 'seen', true)

      expect(mockApi.post).toHaveBeenCalledWith(
        '/mailbox/INBOX/messages/123/flag?flag=seen&value=1',
        { flag: 'seen', value: true }
      )
    })

    it('should call API with flagged in URL', async () => {
      mockApi.post.mockResolvedValueOnce({ data: { success: true } })
      mockApi.get.mockResolvedValue({ data: { success: true, data: { folders: [] } } })

      await store.setFlag(456, 'flagged', true)

      expect(mockApi.post).toHaveBeenCalledWith(
        '/mailbox/INBOX/messages/456/flag?flag=flagged&value=1',
        { flag: 'flagged', value: true }
      )
    })
  })

  describe('moveMessage', () => {
    it('should call API with target in URL query string', async () => {
      mockApi.post.mockResolvedValue({ data: { success: true } })

      await store.moveMessage(100, 'INBOX', 'Archive')

      expect(mockApi.post).toHaveBeenCalledWith(
        '/mailbox/INBOX/messages/100/move?target=Archive',
        { target: 'Archive' }
      )
    })
  })

  describe('deleteMessage', () => {
    it('should call API to delete message', async () => {
      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      await store.deleteMessage(200, 'INBOX')

      expect(mockApi.delete).toHaveBeenCalledWith(
        expect.stringContaining('/mailbox/INBOX/messages/200'),
        expect.any(Object)
      )
    })
  })

  describe('selection helpers', () => {
    it('should select and deselect messages via array', () => {
      store.selectMessage(1)
      expect(store.selectedMessages.length).toBe(1)
      expect(store.isMessageSelected(1)).toBe(true)

      store.selectMessage(2)
      expect(store.selectedMessages.length).toBe(2)

      store.clearSelection()
      expect(store.selectedMessages.length).toBe(0)
    })

    it('should toggle message selection off when already selected', () => {
      store.selectMessage(1)
      expect(store.isMessageSelected(1)).toBe(true)

      store.selectMessage(1)
      expect(store.isMessageSelected(1)).toBe(false)
      expect(store.selectedMessages.length).toBe(0)
    })
  })

  describe('pinEmail', () => {
    it('should call API with body containing message_id and subject', async () => {
      mockApi.post.mockResolvedValueOnce({ data: { success: true } })

      await store.pinEmail(300, 'INBOX', { message_id: '<test@example>', subject: 'Test' })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/mailbox/INBOX/messages/300/pin',
        { message_id: '<test@example>', subject: 'Test' }
      )
    })
  })

  describe('unpinEmail', () => {
    it('should call API to unpin email', async () => {
      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      await store.unpinEmail(300, 'INBOX')

      expect(mockApi.delete).toHaveBeenCalledWith(
        expect.stringContaining('/mailbox/INBOX/messages/300/pin')
      )
    })
  })

  describe('createFolder', () => {
    it('should call API to create folder', async () => {
      mockApi.post.mockResolvedValueOnce({ data: { success: true } })
      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { folders: [] } },
      })

      await store.createFolder('Projects')

      expect(mockApi.post).toHaveBeenCalledWith(
        '/mailbox/folders',
        expect.objectContaining({ name: 'Projects' })
      )
    })
  })

  describe('search', () => {
    it('should call API with query params', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { messages: [], count: 0 } },
      })

      await store.search('invoice')

      expect(mockApi.get).toHaveBeenCalledWith(
        '/mailbox/search',
        expect.objectContaining({
          params: expect.objectContaining({ q: 'invoice' }),
        })
      )
    })
  })

  describe('unreadCount', () => {
    it('should compute unread count from INBOX unread field', () => {
      store.folders = [
        { name: 'INBOX', unread: 12 },
        { name: 'Sent', unread: 0 },
      ]

      expect(store.unreadCount).toBe(12)
    })

    it('should return 0 when no INBOX folder exists', () => {
      store.folders = []
      expect(store.unreadCount).toBe(0)
    })
  })
})
