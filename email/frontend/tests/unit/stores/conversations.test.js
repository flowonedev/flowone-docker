import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mockApi, resetAllMocks } from '../../helpers/setup.js'

import { useConversationsStore } from '@/stores/conversations.js'

describe('conversations store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    resetAllMocks()
    store = useConversationsStore()
  })

  describe('initial state', () => {
    it('should have empty conversationsByFolder', () => {
      expect(store.conversationsByFolder).toEqual({})
    })

    it('should have empty messageAssignments', () => {
      expect(store.messageAssignments).toEqual({})
    })

    it('should not be loading', () => {
      expect(store.loading).toBe(false)
    })

    it('should not be initialized', () => {
      expect(store.initialized).toBe(false)
    })
  })

  describe('normalizeMessageId', () => {
    it('should strip angle brackets and trim', () => {
      expect(store.normalizeMessageId('<ABC@test.com>')).toBe('ABC@test.com')
    })

    it('should handle id without brackets', () => {
      expect(store.normalizeMessageId('simple@test.com')).toBe('simple@test.com')
    })

    it('should return null for empty input', () => {
      expect(store.normalizeMessageId('')).toBeNull()
      expect(store.normalizeMessageId(null)).toBeNull()
      expect(store.normalizeMessageId(undefined)).toBeNull()
    })
  })

  describe('setConversationsFromResponse', () => {
    it('should populate conversations for folder', () => {
      const conversations = {
        'conv-1': {
          id: 'conv-1',
          subject: 'Test Thread',
          message_count: 3,
          unread_count: 1,
        },
      }

      store.setConversationsFromResponse('INBOX', conversations)

      const result = store.getConversationsForFolder('INBOX')
      expect(result).toBeDefined()
    })
  })

  describe('handleFolderRenamed', () => {
    it('should move conversation data to new folder name', () => {
      store.setConversationsFromResponse('OldFolder', {
        'conv-1': { id: 'conv-1', subject: 'Test' },
      })

      store.handleFolderRenamed('OldFolder', 'NewFolder')

      expect(store.conversationsByFolder['OldFolder']).toBeUndefined()
    })
  })

  describe('clearAll', () => {
    it('should reset all state', () => {
      store.setConversationsFromResponse('INBOX', {
        'conv-1': { id: 'conv-1' },
      })

      store.clearAll()

      expect(store.conversationsByFolder).toEqual({})
      expect(store.messageAssignments).toEqual({})
      expect(store.initialized).toBe(false)
    })
  })

  describe('fetchConversations', () => {
    it('should call conversations API', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            conversations: {
              'conv-abc': {
                id: 'conv-abc',
                subject: 'API Thread',
                message_count: 2,
              },
            },
          },
        },
      })

      await store.fetchConversations('INBOX')

      expect(mockApi.get).toHaveBeenCalledWith(
        '/conversations',
        expect.objectContaining({
          params: expect.objectContaining({ folder: 'INBOX' }),
        })
      )
    })
  })

  describe('checkFolderIndexStatus', () => {
    it('should call status API', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            indexed: true,
            last_uid: 500,
            message_count: 50,
          },
        },
      })

      const status = await store.checkFolderIndexStatus('INBOX')

      expect(mockApi.get).toHaveBeenCalledWith(
        '/conversations/status',
        expect.objectContaining({
          params: expect.objectContaining({ folder: 'INBOX' }),
        })
      )
    })
  })

  describe('assignMessages', () => {
    it('should POST messages to assign endpoint', async () => {
      mockApi.post.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            assignments: {},
            conversations: {},
          },
        },
      })

      const messages = [
        { uid: 1, message_id: '<a@test>', subject: 'A', references: '' },
      ]

      await store.assignMessages('INBOX', messages)

      expect(mockApi.post).toHaveBeenCalledWith(
        '/conversations/assign',
        expect.objectContaining({
          folder: 'INBOX',
          messages: expect.any(Array),
        })
      )
    })
  })

  describe('moveMessage', () => {
    it('should PUT to move endpoint', async () => {
      mockApi.put.mockResolvedValueOnce({
        data: { success: true, data: { conversations: {} } },
      })

      await store.moveMessage('INBOX', '<msg@test>', 'conv-target')

      expect(mockApi.put).toHaveBeenCalledWith(
        '/conversations/move',
        expect.objectContaining({
          folder: 'INBOX',
          message_id: '<msg@test>',
          target_conversation_id: 'conv-target',
        })
      )
    })
  })

  describe('splitMessage', () => {
    it('should POST to split endpoint', async () => {
      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { new_conversation_id: 'conv-new', conversations: {} } },
      })

      await store.splitMessage('INBOX', '<split@test>')

      expect(mockApi.post).toHaveBeenCalledWith(
        '/conversations/split',
        expect.objectContaining({
          folder: 'INBOX',
          message_id: '<split@test>',
        })
      )
    })
  })

  describe('mergeMessages', () => {
    it('should POST to merge endpoint', async () => {
      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { new_conversation_id: 'conv-merged', conversations: {} } },
      })

      await store.mergeMessages('INBOX', '<msg1@test>', '<msg2@test>')

      expect(mockApi.post).toHaveBeenCalledWith(
        '/conversations/merge',
        expect.objectContaining({
          folder: 'INBOX',
          message_id_1: '<msg1@test>',
          message_id_2: '<msg2@test>',
        })
      )
    })
  })

  describe('removeMessageLocally', () => {
    it('should not throw on unknown folder', () => {
      expect(() => store.removeMessageLocally('NonExistent', 999)).not.toThrow()
    })
  })

  describe('isFolderIndexed', () => {
    it('should return false for unknown folder', () => {
      expect(store.isFolderIndexed('INBOX')).toBe(false)
    })
  })
})
