import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

/**
 * ConversationsStore - Persistent conversation management
 * 
 * Works with the backend to store and retrieve conversation assignments.
 * Provides consistent counts and enables drag & drop message moving.
 */
export const useConversationsStore = defineStore('conversations', () => {
  // State
  const conversationsByFolder = ref({}) // folder -> { conversation_id -> conversation data }
  const conversationListByFolder = ref({}) // folder -> array of display-ready conversations
  const messageAssignments = ref({})    // message_id -> conversation_id
  const userOverrides = ref({})         // message_id -> conversation_id (for user splits/moves)
  const folderIndexStatus = ref({})     // folder -> { indexed, lastUid, messageCount }
  const loading = ref(false)
  const lastFetchedFolder = ref(null)
  const initialized = ref(false)
  const updateVersion = ref(0)          // Incremented after updates to trigger reactivity
  const lastFetchTimestamps = {}        // folder -> Date.now() of last successful fetch/set
  
  // ==========================================
  // LIGHTWEIGHT CACHING (only status, not full data)
  // ==========================================
  const CACHE_STATUS_KEY = 'conv_folder_status'
  
  /**
   * Load folder index status from localStorage (instant, tiny data)
   * Only stores: { folder: { indexed, lastUid, messageCount } }
   */
  function loadStatusFromCache() {
    try {
      const cached = localStorage.getItem(CACHE_STATUS_KEY)
      if (cached) {
        folderIndexStatus.value = JSON.parse(cached) || {}
      }
    } catch (e) {
      // Ignore
    }
  }
  
  /**
   * Save folder index status to localStorage (tiny, ~1KB max)
   */
  function saveStatusToCache() {
    try {
      localStorage.setItem(CACHE_STATUS_KEY, JSON.stringify(folderIndexStatus.value))
    } catch (e) {
      // Ignore
    }
  }
  
  // Load cached status on store initialization
  loadStatusFromCache()

  // Get conversations for current folder
  const getConversationsForFolder = computed(() => {
    return (folder) => {
      return conversationsByFolder.value[normalizeFolder(folder)] || {}
    }
  })

  // Normalize message ID to match backend format
  function normalizeMessageId(id) {
    if (!id) return null
    return id.replace(/^<|>$/g, '').trim()
  }

  // Normalize folder name for consistent storage/lookup (case-insensitive)
  // This fixes issues where same folder has different casing in database
  function normalizeFolder(folder) {
    if (!folder) return ''
    return folder.toLowerCase()
  }

  // Get conversation ID for a message
  function getConversationId(messageId) {
    const normalized = normalizeMessageId(messageId)
    return messageAssignments.value[normalized] || null
  }

  // Check if a message has been user-overridden (split/moved)
  function isUserOverride(messageId) {
    if (!messageId) return false
    const normalized = normalizeMessageId(messageId)
    return !!userOverrides.value[normalized]
  }

  // Get the override conversation ID for a message (if exists)
  function getUserOverrideConversationId(messageId) {
    if (!messageId) return null
    const normalized = normalizeMessageId(messageId)
    return userOverrides.value[normalized] || null
  }

  // ==========================================
  // DB-FIRST LOADING (Outlook-style)
  // ==========================================

  /**
   * Check if a folder has been indexed (DB-first check)
   * Returns cached status INSTANTLY if available, then updates from API
   */
  async function checkFolderIndexStatus(folder, skipApiCall = false) {
    if (!folder) return { indexed: false, lastUid: 0, messageCount: 0, uidvalidity: 0 }

    // INSTANT: Return from local cache if available
    const cachedStatus = folderIndexStatus.value[folder]
    if (cachedStatus && skipApiCall) {
      return cachedStatus
    }
    
    // If we have cached status, return it immediately but still fetch update
    if (cachedStatus) {
      // Background update from API (don't await)
      api.get('/conversations/status', { params: { folder } }).then(response => {
        if (response.data.success) {
          folderIndexStatus.value[folder] = {
            indexed: response.data.data.indexed,
            lastUid: response.data.data.lastUid,
            messageCount: response.data.data.messageCount,
            uidvalidity: response.data.data.uidvalidity || 0,
            indexedAt: response.data.data.indexedAt
          }
          saveStatusToCache()
        }
      }).catch(() => {})
      
      return cachedStatus
    }

    // No cache - need to fetch from API
    try {
      const response = await api.get('/conversations/status', { params: { folder } })
      
      if (response.data.success) {
        const status = {
          indexed: response.data.data.indexed,
          lastUid: response.data.data.lastUid,
          messageCount: response.data.data.messageCount,
          uidvalidity: response.data.data.uidvalidity || 0,
          indexedAt: response.data.data.indexedAt
        }
        
        // Cache the status
        folderIndexStatus.value[folder] = status
        saveStatusToCache()
        
        isDebugEnabled() && console.log(`[Conversations] Folder ${folder} index status:`, status)
        return status
      }
      
      return { indexed: false, lastUid: 0, messageCount: 0, uidvalidity: 0 }
    } catch (e) {
      console.error('[Conversations] Failed to check index status:', e)
      return { indexed: false, lastUid: 0, messageCount: 0, uidvalidity: 0 }
    }
  }

  /**
   * Get cached folder index status (synchronous, instant)
   */
  function getFolderIndexStatus(folder) {
    return folderIndexStatus.value[folder] || { indexed: false, lastUid: 0, messageCount: 0, uidvalidity: 0 }
  }

  /**
   * Index a folder for the first time (called after IMAP fetch)
   * This marks the folder as indexed and stores all conversations
   */
  async function indexFolder(folder, messages, lastUid) {
    if (!folder || !messages) return null

    try {
      loading.value = true
      
      // Prepare messages with required fields
      const messagesToIndex = messages.map(m => ({
        uid: m.uid,
        message_id: m.message_id,
        subject: m.subject,
        date: m.date,
        from: m.from,
        references: m.references,
        in_reply_to: m.in_reply_to,
        has_attachment: m.has_attachment
      }))

      const response = await api.post('/conversations/index', {
        folder,
        messages: messagesToIndex,
        last_uid: lastUid
      })

      if (response.data.success) {
        const result = response.data.data
        
        // Store conversations
        const indexed = {}
        result.conversations.forEach(conv => {
          indexed[conv.conversation_id] = conv
        })
        // ATOMIC BATCH UPDATE for consistent reactivity
        conversationsByFolder.value = {
          ...conversationsByFolder.value,
          [folder]: indexed
        }
        conversationListByFolder.value = {
          ...conversationListByFolder.value,
          [folder]: result.conversations
        }
        updateVersion.value++ // Trigger reactivity
        
        // Update index status
        folderIndexStatus.value[folder] = {
          indexed: true,
          lastUid: result.lastUid,
          messageCount: result.messageCount
        }
        
        // Build message assignments
        result.conversations.forEach(conv => {
          if (conv.messages) {
            conv.messages.forEach(msg => {
              if (msg.message_id) {
                const normalized = normalizeMessageId(msg.message_id)
                messageAssignments.value[normalized] = conv.conversation_id
              }
            })
          }
        })

        isDebugEnabled() && console.log(`[Conversations] Indexed ${result.messageCount} messages into ${result.conversationCount} conversations`)
        
        return result
      }
      
      return null
    } catch (e) {
      console.error('[Conversations] Failed to index folder:', e)
      return null
    } finally {
      loading.value = false
    }
  }

  /**
   * Sync new messages incrementally (called for folders already indexed)
   */
  async function syncNewMessages(folder, sinceUid) {
    if (!folder) return null

    try {
      const response = await api.post('/conversations/sync', {
        folder,
        since_uid: sinceUid
      })

      if (response.data.success) {
        const result = response.data.data
        
        if (result.synced > 0) {
          isDebugEnabled() && console.log(`[Conversations] Synced ${result.synced} new messages`)
          
          // Update conversations - ATOMIC BATCH UPDATE for consistent reactivity
          const indexed = {}
          result.conversations.forEach(conv => {
            indexed[conv.conversation_id] = conv
          })
          conversationsByFolder.value = {
            ...conversationsByFolder.value,
            [folder]: indexed
          }
          conversationListByFolder.value = {
            ...conversationListByFolder.value,
            [folder]: result.conversations
          }
          updateVersion.value++ // Trigger reactivity
          
          // Update assignments
          Object.entries(result.assignments || {}).forEach(([msgId, convId]) => {
            messageAssignments.value[msgId] = convId
          })
          
          // Update index status
          folderIndexStatus.value[folder] = {
            indexed: true,
            lastUid: result.lastUid,
            messageCount: (folderIndexStatus.value[folder]?.messageCount || 0) + result.synced
          }
        }
        
        return result
      }
      
      return null
    } catch (e) {
      console.error('[Conversations] Failed to sync new messages:', e)
      return null
    }
  }

  /**
   * Get display-ready conversation list for a folder
   * Checks both stores for consistency - conversationListByFolder is primary,
   * conversationsByFolder is fallback (used after move/split/merge operations)
   */
  function getConversationsList(folder) {
    const normalizedFolder = normalizeFolder(folder)
    // Primary: use conversationListByFolder (set by setConversationsFromResponse)
    if (conversationListByFolder.value[normalizedFolder]?.length > 0) {
      return conversationListByFolder.value[normalizedFolder]
    }
    // Fallback: build from conversationsByFolder (set by move/split/merge)
    const convs = conversationsByFolder.value[normalizedFolder]
    if (!convs) return []
    return Object.values(convs).sort((a, b) => 
      new Date(b.latest_date) - new Date(a.latest_date)
    )
  }

  /**
   * Check if folder is indexed (synchronous)
   */
  function isFolderIndexed(folder) {
    return folderIndexStatus.value[normalizeFolder(folder)]?.indexed || false
  }

  /**
   * Fetch conversations for a folder from backend
   * Uses in-memory cache if available, then fetches from API
   */
  const CONVERSATION_REFETCH_COOLDOWN = 10000 // 10s

  async function fetchConversations(folder, backgroundOnly = false) {
    if (!folder) return []
    const normalizedFolder = normalizeFolder(folder)

    // Skip re-fetch if data was set/fetched very recently (e.g. from initMailbox response)
    const lastTs = lastFetchTimestamps[normalizedFolder]
    if (lastTs && (Date.now() - lastTs < CONVERSATION_REFETCH_COOLDOWN) && conversationListByFolder.value[normalizedFolder]?.length > 0) {
      return conversationListByFolder.value[normalizedFolder]
    }
    
    // If we already have in-memory data and only background update requested
    if (backgroundOnly && conversationListByFolder.value[normalizedFolder]?.length > 0) {
      // Fire background update
      fetchConversationsFromApi(folder).catch(() => {})
      return conversationListByFolder.value[normalizedFolder]
    }

    // Fetch from API
    return await fetchConversationsFromApi(folder)
  }
  
  /**
   * Internal: Fetch conversations from API
   */
  async function fetchConversationsFromApi(folder) {
    try {
      loading.value = true
      const normalizedFolder = normalizeFolder(folder)
      const response = await api.get('/conversations', { params: { folder } })
      
      if (response.data.success) {
        const conversations = response.data.data.conversations || []
        
        isDebugEnabled() && console.log('[Conversations] Fetched from API:', conversations.length, 'conversations')
        
        // Don't overwrite existing valid data with empty data (protects against race conditions)
        const existingData = conversationListByFolder.value[normalizedFolder]
        if (conversations.length === 0 && existingData?.length > 0) {
          isDebugEnabled() && console.log('[Conversations] Skipping empty API response - keeping existing data')
          return existingData
        }
        
        // Index by conversation_id
        const indexed = {}
        conversations.forEach(conv => {
          indexed[conv.conversation_id] = conv
          
          // Build message assignments from conversation members
          if (conv.messages) {
            conv.messages.forEach(msg => {
              if (msg.message_id) {
                const normalized = normalizeMessageId(msg.message_id)
                messageAssignments.value[normalized] = conv.conversation_id
              }
            })
          }
        })
        
        // ATOMIC BATCH UPDATE for consistent reactivity
        // Use normalized folder name to ensure consistent storage regardless of API response case
        conversationsByFolder.value = {
          ...conversationsByFolder.value,
          [normalizedFolder]: indexed
        }
        conversationListByFolder.value = {
          ...conversationListByFolder.value,
          [normalizedFolder]: conversations
        }
        updateVersion.value++ // Trigger reactivity
        lastFetchedFolder.value = normalizedFolder
        initialized.value = true
        lastFetchTimestamps[normalizedFolder] = Date.now()
        
        return conversations
      }
      return []
    } catch (e) {
      console.error('[Conversations] Failed to fetch:', e)
      return []
    } finally {
      loading.value = false
    }
  }

  /**
   * Assign messages to conversations (batch)
   * Called when fetching messages to ensure they're in the DB
   * @param {string} folder - Folder name
   * @param {array} messages - Messages to assign
   * @param {string} forceConversationId - Optional: force all messages to this conversation ID
   */
  async function assignMessages(folder, messages, forceConversationId = null) {
    if (!folder || !messages || messages.length === 0) return {}

    try {
      // Only send messages that have a message_id
      const validMessages = messages.filter(m => m.message_id).map(m => ({
        uid: m.uid,
        message_id: m.message_id,
        subject: m.subject,
        date: m.date,
        from: m.from,
        references: m.references,
        in_reply_to: m.in_reply_to
      }))

      if (validMessages.length === 0) return {}

      const payload = {
        folder,
        messages: validMessages
      }
      
      // If forceConversationId is provided, send it to keep messages in same conversation
      if (forceConversationId) {
        payload.force_conversation_id = forceConversationId
        isDebugEnabled() && console.log('[Conversations] Forcing assignment to conversation:', forceConversationId)
      }

      const response = await api.post('/conversations/assign', payload)

      if (response.data.success) {
        const assignments = response.data.data.assignments || {}
        
        isDebugEnabled() && console.log('[Conversations] Assignments received:', Object.keys(assignments).length)
        
        // Update local cache
        Object.entries(assignments).forEach(([messageId, conversationId]) => {
          messageAssignments.value[messageId] = conversationId
        })

        // Refresh conversation metadata
        await fetchConversations(folder)
        
        isDebugEnabled() && console.log('[Conversations] After fetch, conversations:', Object.keys(conversationsByFolder.value[normalizeFolder(folder)] || {}).length)

        return assignments
      }
      return {}
    } catch (e) {
      console.error('[Conversations] Failed to assign messages:', e)
      return {}
    }
  }

  /**
   * Move a message to a different conversation (user action)
   */
  async function moveMessage(folder, messageId, targetConversationId) {
    if (!folder || !messageId || !targetConversationId) {
      console.error('[Conversations] moveMessage: missing required params')
      return null
    }

    // Get current conversation for logging
    const fromConversation = messageAssignments.value[messageId] || 'unknown'

    // PREVENT same-conversation move (causes UI reactivity issues)
    if (fromConversation === targetConversationId) {
      console.warn('[Conversations] Ignoring move to same conversation')
      window.__logConvOp?.('MOVE', {
        folder,
        messageId,
        fromConversation,
        toConversation: targetConversationId,
        success: false,
        error: 'Same conversation - operation skipped'
      })
      return null
    }

    // CAPTURE BEFORE STATE for debugging
    const beforeState = { fromConv: null, toConv: null }
    const normalizedFolder = normalizeFolder(folder)
    const currentConvs = conversationsByFolder.value[normalizedFolder] || {}
    if (currentConvs[fromConversation]) {
      beforeState.fromConv = {
        id: fromConversation,
        uids: [...(currentConvs[fromConversation].uids || [])],
        messageCount: currentConvs[fromConversation].message_count
      }
    }
    if (currentConvs[targetConversationId]) {
      beforeState.toConv = {
        id: targetConversationId,
        uids: [...(currentConvs[targetConversationId].uids || [])],
        messageCount: currentConvs[targetConversationId].message_count
      }
    }

    try {
      const response = await api.put('/conversations/move', {
        folder,
        message_id: messageId,
        target_conversation_id: targetConversationId
      })

      if (response.data.success) {
        // Update local assignment
        messageAssignments.value[messageId] = targetConversationId

        // CAPTURE AFTER STATE for debugging
        const afterState = { fromConv: null, toConv: null }

        // Update conversation data from response
        if (response.data.data.conversations) {
          const conversations = response.data.data.conversations
          const indexed = {}
          conversations.forEach(conv => {
            indexed[conv.conversation_id] = conv
            // Capture after state for debugging
            if (conv.conversation_id === fromConversation) {
              afterState.fromConv = {
                id: fromConversation,
                uids: [...(conv.uids || [])],
                messageCount: conv.message_count
              }
            }
            if (conv.conversation_id === targetConversationId) {
              afterState.toConv = {
                id: targetConversationId,
                uids: [...(conv.uids || [])],
                messageCount: conv.message_count
              }
            }
          })
          // ATOMIC BATCH UPDATE - ensures Vue processes both changes together
          // This prevents reactivity race conditions that cause UI to break
          conversationsByFolder.value = {
            ...conversationsByFolder.value,
            [normalizedFolder]: indexed
          }
          conversationListByFolder.value = {
            ...conversationListByFolder.value,
            [normalizedFolder]: conversations
          }
          updateVersion.value++ // Trigger reactivity
        }

        // Log operation for debug panel with before/after state
        window.__logConvOp?.('MOVE', {
          folder,
          messageId,
          fromConversation,
          toConversation: targetConversationId,
          conversationsCount: response.data.data.conversations?.length,
          conversationsData: response.data.data.conversations,
          beforeState,
          afterState,
          success: true
        })

        return {
          moved: true,
          targetConversationId,
          conversations: response.data.data.conversations
        }
      }
      
      window.__logConvOp?.('MOVE', { folder, messageId, success: false, error: 'API returned false' })
      return null
    } catch (e) {
      console.error('[Conversations] Failed to move message:', e)
      window.__logConvOp?.('MOVE', { folder, messageId, success: false, error: e.message })
      return null
    }
  }

  /**
   * Split a message into a new conversation
   */
  async function splitMessage(folder, messageId) {
    if (!folder || !messageId) {
      console.error('[Conversations] splitMessage: missing required params')
      return null
    }

    // Get current conversation for logging
    const fromConversation = messageAssignments.value[normalizeMessageId(messageId)] || 'unknown'
    const normalizedFolder = normalizeFolder(folder)

    try {
      const response = await api.post('/conversations/split', {
        folder,
        message_id: messageId
      })

      if (response.data.success) {
        const newConversationId = response.data.data.new_conversation_id
        const normalized = normalizeMessageId(messageId)

        // Track this as user override (for UI grouping)
        userOverrides.value[normalized] = newConversationId
        
        // Update local assignment
        messageAssignments.value[normalized] = newConversationId

        // Update conversation data from response
        if (response.data.data.conversations) {
          const conversations = response.data.data.conversations
          const indexed = {}
          conversations.forEach(conv => {
            indexed[conv.conversation_id] = conv
          })
          
          isDebugEnabled() && console.log('[CONV-DEBUG] splitMessage updating store', {
            folder: normalizedFolder,
            conversationsCount: conversations.length,
            conversationIds: conversations.map(c => c.conversation_id),
            conversationMsgCounts: conversations.map(c => ({ id: c.conversation_id, count: c.message_count }))
          })
          
          // ATOMIC BATCH UPDATE - ensures Vue processes both changes together
          // This prevents reactivity race conditions that cause UI to break
          conversationsByFolder.value = {
            ...conversationsByFolder.value,
            [normalizedFolder]: indexed
          }
          conversationListByFolder.value = {
            ...conversationListByFolder.value,
            [normalizedFolder]: conversations
          }
          
          isDebugEnabled() && console.log('[CONV-DEBUG] splitMessage incrementing updateVersion', {
            oldVersion: updateVersion.value,
            newVersion: updateVersion.value + 1
          })
          updateVersion.value++ // Trigger reactivity
        }

        // Log operation for debug panel
        window.__logConvOp?.('SPLIT', {
          folder,
          messageId,
          fromConversation,
          newConversation: newConversationId,
          conversationsCount: response.data.data.conversations?.length,
          conversationsData: response.data.data.conversations, // Full data for analysis
          success: true
        })

        return {
          split: true,
          newConversationId,
          conversations: response.data.data.conversations
        }
      }
      
      window.__logConvOp?.('SPLIT', { folder, messageId, success: false, error: 'API returned false' })
      return null
    } catch (e) {
      console.error('[Conversations] Failed to split message:', e)
      window.__logConvOp?.('SPLIT', { folder, messageId, success: false, error: e.message })
      return null
    }
  }

  /**
   * Reset user override (restore auto-grouping)
   */
  async function resetOverride(folder, messageId) {
    if (!folder || !messageId) return false

    try {
      const response = await api.delete('/conversations/override', {
        data: { folder, message_id: messageId }
      })

      if (response.data.success) {
        const normalized = normalizeMessageId(messageId)
        
        // Clear local override tracking
        delete userOverrides.value[normalized]
        delete messageAssignments.value[normalized]

        // Refresh conversations
        await fetchConversations(folder)

        // Log operation for debug panel
        window.__logConvOp?.('RESET', {
          folder,
          messageId,
          success: true
        })

        return true
      }
      
      window.__logConvOp?.('RESET', { folder, messageId, success: false, error: 'API returned false' })
      return false
    } catch (e) {
      console.error('[Conversations] Failed to reset override:', e)
      window.__logConvOp?.('RESET', { folder, messageId, success: false, error: e.message })
      return false
    }
  }

  /**
   * Merge two standalone emails into a new conversation
   * Used when dragging one email onto another
   */
  async function mergeMessages(folder, messageId1, messageId2) {
    if (!folder || !messageId1 || !messageId2) {
      console.error('[Conversations] mergeMessages: missing required params')
      return null
    }

    const normalizedFolder = normalizeFolder(folder)

    try {
      const response = await api.post('/conversations/merge', {
        folder,
        message_id_1: messageId1,
        message_id_2: messageId2
      })

      if (response.data.success) {
        const newConversationId = response.data.data.new_conversation_id
        const norm1 = normalizeMessageId(messageId1)
        const norm2 = normalizeMessageId(messageId2)

        // Track both as user overrides
        userOverrides.value[norm1] = newConversationId
        userOverrides.value[norm2] = newConversationId
        
        // Update local assignments
        messageAssignments.value[norm1] = newConversationId
        messageAssignments.value[norm2] = newConversationId

        // Update conversation data from response
        if (response.data.data.conversations) {
          const conversations = response.data.data.conversations
          const indexed = {}
          conversations.forEach(conv => {
            indexed[conv.conversation_id] = conv
          })
          // ATOMIC BATCH UPDATE - ensures Vue processes both changes together
          // This prevents reactivity race conditions that cause UI to break
          conversationsByFolder.value = {
            ...conversationsByFolder.value,
            [normalizedFolder]: indexed
          }
          conversationListByFolder.value = {
            ...conversationListByFolder.value,
            [normalizedFolder]: conversations
          }
          updateVersion.value++ // Trigger reactivity
        }

        // Log operation for debug panel
        window.__logConvOp?.('MERGE', {
          folder,
          messageId: `${messageId1?.substring(0, 20)}... + ${messageId2?.substring(0, 20)}...`,
          newConversation: newConversationId,
          conversationsCount: response.data.data.conversations?.length,
          conversationsData: response.data.data.conversations, // Full data for analysis
          success: true
        })

        return {
          merged: true,
          newConversationId,
          conversations: response.data.data.conversations
        }
      }
      
      window.__logConvOp?.('MERGE', { folder, messageId: `${messageId1} + ${messageId2}`, success: false, error: 'API returned false' })
      return null
    } catch (e) {
      console.error('[Conversations] Failed to merge messages:', e)
      window.__logConvOp?.('MERGE', { folder, messageId: `${messageId1} + ${messageId2}`, success: false, error: e.message })
      return null
    }
  }

  /**
   * Get conversation info for a specific message
   */
  async function getConversationForMessage(folder, messageId, uid = null) {
    try {
      const params = { folder }
      if (messageId) params.message_id = messageId
      if (uid) params.uid = uid

      const response = await api.get('/conversations/for-message', { params })
      
      if (response.data.success) {
        const conversationId = response.data.data.conversation_id
        if (conversationId && messageId) {
          messageAssignments.value[messageId] = conversationId
        }
        return conversationId
      }
      return null
    } catch (e) {
      console.error('[Conversations] Failed to get conversation for message:', e)
      return null
    }
  }

  /**
   * Migrate old JSON splits to new system
   */
  async function migrateSplits(splits) {
    if (!splits || Object.keys(splits).length === 0) return 0

    try {
      const response = await api.post('/conversations/migrate-splits', { splits })
      
      if (response.data.success) {
        isDebugEnabled() && console.log(`[Conversations] Migrated ${response.data.data.migrated} splits`)
        return response.data.data.migrated
      }
      return 0
    } catch (e) {
      console.error('[Conversations] Failed to migrate splits:', e)
      return 0
    }
  }

  /**
   * Clear all cached data (for logout)
   */
  function clearAll() {
    conversationsByFolder.value = {}
    messageAssignments.value = {}
    lastFetchedFolder.value = null
    initialized.value = false
  }
  
  /**
   * Remove a message (by UID) from local conversation data.
   * Called immediately after deletion so buildFromDb doesn't create ghost placeholders
   * from stale data while waiting for the backend to refresh.
   */
  function removeMessageLocally(folder, uid) {
    const normalizedFolder = normalizeFolder(folder)
    const numUid = Number(uid)

    const list = conversationListByFolder.value[normalizedFolder]
    if (!list || list.length === 0) return

    let changed = false
    const updatedList = []

    for (const conv of list) {
      if (!conv.messages) {
        updatedList.push(conv)
        continue
      }

      const hadMember = conv.messages.some(
        m => Number(m.uid) === numUid && (m.folder || '').toLowerCase() === normalizedFolder
      )

      if (!hadMember) {
        updatedList.push(conv)
        continue
      }

      changed = true
      const filteredMessages = conv.messages.filter(
        m => !(Number(m.uid) === numUid && (m.folder || '').toLowerCase() === normalizedFolder)
      )

      if (filteredMessages.length === 0) continue

      const filteredUids = (conv.uids || []).filter(u => Number(u) !== numUid)

      updatedList.push({
        ...conv,
        messages: filteredMessages,
        uids: filteredUids,
        message_count: filteredMessages.length
      })
    }

    if (changed) {
      const indexed = {}
      updatedList.forEach(c => { indexed[c.conversation_id] = c })

      conversationsByFolder.value = {
        ...conversationsByFolder.value,
        [normalizedFolder]: indexed
      }
      conversationListByFolder.value = {
        ...conversationListByFolder.value,
        [normalizedFolder]: updatedList
      }
      updateVersion.value++
    }
  }

  /**
   * Handle folder rename - update local data keys
   * Called after successful folder rename to keep local state consistent
   * Backend has already updated the database records
   */
  function handleFolderRenamed(oldName, newName) {
    isDebugEnabled() && console.log(`[Conversations] Handling folder rename: ${oldName} -> ${newName}`)
    
    const normalizedOld = normalizeFolder(oldName)
    const normalizedNew = normalizeFolder(newName)
    
    // Clear old folder data (will be refetched with new name)
    if (conversationsByFolder.value[normalizedOld]) {
      delete conversationsByFolder.value[normalizedOld]
    }
    if (conversationListByFolder.value[normalizedOld]) {
      delete conversationListByFolder.value[normalizedOld]
    }
    if (folderIndexStatus.value[normalizedOld]) {
      delete folderIndexStatus.value[normalizedOld]
    }
    
    // Also handle child folders (e.g., INBOX.Work -> INBOX.Projects means INBOX.Work.Sub -> INBOX.Projects.Sub)
    const oldPrefix = normalizedOld + '.'
    const newPrefix = normalizedNew + '.'
    
    // Clear child folder data
    Object.keys(conversationsByFolder.value).forEach(key => {
      if (key.startsWith(oldPrefix)) {
        delete conversationsByFolder.value[key]
      }
    })
    Object.keys(conversationListByFolder.value).forEach(key => {
      if (key.startsWith(oldPrefix)) {
        delete conversationListByFolder.value[key]
      }
    })
    Object.keys(folderIndexStatus.value).forEach(key => {
      if (key.startsWith(oldPrefix)) {
        delete folderIndexStatus.value[key]
      }
    })
    
    // Save updated status cache
    saveStatusToCache()
    
    // Clear lastFetchedFolder if it was the renamed folder
    if (lastFetchedFolder.value === normalizedOld || lastFetchedFolder.value?.startsWith(oldPrefix)) {
      lastFetchedFolder.value = null
    }
    
    isDebugEnabled() && console.log('[Conversations] Cleared stale data for renamed folder')
  }

  /**
   * Mark migration as complete (legacy cleanup)
   * This runs once to ensure local storage flag is set
   */
  async function migrateExistingSplits() {
    const migrationKey = 'conversations_migration_v2'
    if (localStorage.getItem(migrationKey) === 'done') {
      return
    }
    
    // Migration to database-only storage is complete
    localStorage.setItem(migrationKey, 'done')
    isDebugEnabled() && console.log('[Conversations] Migration marked complete - using database-only storage')
  }

  /**
   * Set conversations directly from API response (avoids separate API call)
   * Called when MailboxController returns conversations with messages
   */
  function setConversationsFromResponse(folder, conversations) {
    if (!folder || !Array.isArray(conversations)) return
    
    const normalizedFolder = normalizeFolder(folder)
    isDebugEnabled() && console.log('[Conversations] Setting from response:', conversations.length, 'conversations for', normalizedFolder)
    
    // Don't overwrite existing valid data with empty data (protects against race conditions)
    const existingData = conversationListByFolder.value[normalizedFolder]
    if (conversations.length === 0 && existingData?.length > 0) {
      isDebugEnabled() && console.log('[Conversations] Skipping empty response - keeping existing data')
      return
    }
    
    // Index by conversation_id
    const indexed = {}
    conversations.forEach(conv => {
      indexed[conv.conversation_id] = conv
      
      // Build message assignments from conversation members
      if (conv.messages) {
        conv.messages.forEach(msg => {
          if (msg.message_id) {
            const normalized = normalizeMessageId(msg.message_id)
            messageAssignments.value[normalized] = conv.conversation_id
          }
        })
      }
    })
    
    // ATOMIC BATCH UPDATE for consistent reactivity
    // Use normalized folder name to ensure consistent storage
    conversationsByFolder.value = {
      ...conversationsByFolder.value,
      [normalizedFolder]: indexed
    }
    conversationListByFolder.value = {
      ...conversationListByFolder.value,
      [normalizedFolder]: conversations
    }
    updateVersion.value++ // Trigger reactivity
    initialized.value = true
    lastFetchTimestamps[normalizedFolder] = Date.now()
    
    // Update folder index status
    folderIndexStatus.value[normalizedFolder] = {
      indexed: true,
      lastUid: Math.max(0, ...conversations.map(c => c.latest_uid || 0)),
      messageCount: conversations.reduce((sum, c) => sum + (c.message_count || 0), 0)
    }
    saveStatusToCache()
  }

  return {
    // State
    conversationsByFolder,
    conversationListByFolder,
    messageAssignments,
    userOverrides,
    folderIndexStatus,
    loading,
    initialized,
    updateVersion, // Reactive trigger for computed properties

    // Getters
    getConversationsForFolder,
    getConversationId,
    getConversationsList,
    normalizeMessageId,
    isUserOverride,
    getUserOverrideConversationId,
    getFolderIndexStatus,
    isFolderIndexed,

    // Actions - DB-first loading
    checkFolderIndexStatus,
    indexFolder,
    syncNewMessages,

    // Actions - Legacy/compatibility
    fetchConversations,
    assignMessages,
    moveMessage,
    splitMessage,
    mergeMessages,
    resetOverride,
    getConversationForMessage,
    migrateSplits,
    migrateExistingSplits,
    clearAll,
    
    // Actions - Direct data setting (from bundled API responses)
    setConversationsFromResponse,
    
    // Actions - Cache management
    handleFolderRenamed,

    // Actions - Local mutation (optimistic updates)
    removeMessageLocally
  }
})

