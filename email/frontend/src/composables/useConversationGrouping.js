import { computed, watch } from 'vue'
import { useConversationsStore } from '@/stores/conversations'
import { isDebugEnabled } from '@/utils/debug'

function sortTs(msg) {
  if (msg.timestamp) return msg.timestamp
  const d = new Date(msg.date)
  return isNaN(d) ? 0 : d.getTime() / 1000
}

/**
 * Builds the conversations list by merging DB conversation data with
 * canonical message objects from messagesByKey.
 *
 * All reactive dependencies are passed in so this composable has no
 * direct store imports except conversations.
 *
 * @param {import('vue').Reactive<Map>} messagesByKey - canonical store
 * @param {import('vue').Reactive<Map>} folderViews - folder -> key[]
 * @param {import('vue').Ref<string>} currentFolder
 * @param {import('vue').Ref<boolean>} conversationView
 * @param {import('vue').Reactive<Map>} conversationKeys - convId -> key[]
 * @param {import('vue').Ref<number>} refreshTrigger - bumped to force re-eval
 */
export function useConversationGrouping(
  messagesByKey,
  folderViews,
  currentFolder,
  conversationView,
  conversationKeys,
  refreshTrigger
) {
  const conversationsStore = useConversationsStore()

  watch(
    () => conversationsStore.updateVersion,
    () => { refreshTrigger.value++ },
    { immediate: false }
  )

  const conversations = computed(() => {
    const tick = refreshTrigger.value
    if (tick < 0) return []

    const viewKeys = folderViews.get(currentFolder.value) || []
    const msgs = viewKeys.map(k => messagesByKey.get(k)).filter(Boolean)

    const isVirtualFolder =
      currentFolder.value === 'ALL_MAIL' || currentFolder.value === 'SEARCH_RESULTS'

    if (!conversationView.value || isVirtualFolder) {
      return msgs.map(m => ({
        ...m,
        isConversation: false,
        messages: [m],
      }))
    }

    if (msgs.length === 0) return []

    const dbConversations = conversationsStore.getConversationsList(currentFolder.value)

    if (dbConversations && dbConversations.length > 0) {
      return buildFromDb(dbConversations, msgs, currentFolder.value, messagesByKey, conversationKeys, conversationsStore)
    }

    return msgs.map(m => {
      const convId = m.message_id ? `temp:${m.message_id}` : `uid:${currentFolder.value}:${m.uid}`
      return {
        ...m,
        isConversation: false,
        isSplit: false,
        messageCount: 1,
        threadLoaded: false,
        messages: [m],
        hasUnread: !m.seen,
        hasStarred: m.flagged,
        answered: m.answered || false,
        conversationKey: convId,
        conversation_id: convId,
        threadReferences: [m.message_id, m.in_reply_to, ...(m.references || [])].filter(Boolean)
      }
    }).sort((a, b) => sortTs(b) - sortTs(a))
  })

  return { conversations }
}

// --- Heavy lifting extracted into a pure function ---

function buildFromDb(dbConversations, msgs, folder, messagesByKey, conversationKeysMap, conversationsStore) {
  const curFolderLower = (folder || '').toLowerCase()
  const uidsInConversations = new Set()

  const msgsByUid = new Map()
  const msgsByMessageId = new Map()
  for (const m of msgs) {
    msgsByUid.set(Number(m.uid), m)
    if (m.message_id) {
      msgsByMessageId.set(m.message_id, m)
    }
  }

  const conversationItems = dbConversations.map(conv => {
    if (!conv) return null

    const dbMembers = conv.messages || []
    if (dbMembers.length === 0) return null

    // Check if thread has been loaded via conversationKeys
    const threadKeys = conversationKeysMap.get(conv.conversation_id)
    const threadLoaded = threadKeys?.length > 0

    const mergedMessages = []
    const seenKeys = new Set()
    let hasCurrentFolderMsg = false

    for (const member of dbMembers) {
      if (!member?.uid) continue
      const memberFolder = (member.folder || '').toLowerCase()
      const uid = Number(member.uid)
      const key = `${uid}-${memberFolder}`
      if (seenKeys.has(key)) continue
      seenKeys.add(key)

      if (memberFolder === curFolderLower && (msgsByUid.has(uid) || (member.message_id && msgsByMessageId.has(member.message_id)))) {
        hasCurrentFolderMsg = true
      }

      // O(1) lookup: current folder messages by UID, then canonical store
      let richMsg = null
      if (memberFolder === curFolderLower) {
        richMsg = msgsByUid.get(uid) || null
        // UID may be stale after move/restore; fall back to message_id match
        if (!richMsg && member.message_id) {
          richMsg = msgsByMessageId.get(member.message_id) || null
        }
      }
      if (!richMsg) {
        const canonicalKey = `${member.folder || folder}:${uid}`
        richMsg = messagesByKey.get(canonicalKey) || null
      }

      // Track the actual UID used (could be corrected via message_id fallback)
      if (richMsg && memberFolder === curFolderLower) {
        uidsInConversations.add(`${curFolderLower}:${Number(richMsg.uid)}`)
      }

      if (richMsg) {
        mergedMessages.push(richMsg)
      } else {
        mergedMessages.push({
          uid: member.uid,
          message_id: member.message_id,
          from: [{ name: member.from_name || '', email: member.from_email || '' }],
          from_email: member.from_email || '',
          from_name: member.from_name || '',
          subject: member.subject,
          date: member.message_date,
          timestamp: member.timestamp,
          folder: member.folder,
          seen: true,
          flagged: false,
          has_attachment: false,
          _isDbPlaceholder: true,
        })
      }
    }

    // Include messages from loaded thread not already listed (cross-folder)
    if (threadKeys?.length > 0) {
      for (const tKey of threadKeys) {
        const tMsg = messagesByKey.get(tKey)
        if (!tMsg) continue
        const tFolder = (tMsg.folder || '').toLowerCase()
        const tKey2 = `${Number(tMsg.uid)}-${tFolder}`
        if (!seenKeys.has(tKey2)) {
          seenKeys.add(tKey2)
          mergedMessages.push(tMsg)
        }
      }
    }

    if (!hasCurrentFolderMsg) return null

    mergedMessages.sort((a, b) => sortTs(b) - sortTs(a))

    const newestMsg = mergedMessages[0]
    if (!newestMsg) return null
    const currentFolderMsg = mergedMessages.find(
      m => m.folder && m.folder.toLowerCase() === curFolderLower
    )
    const firstMsg = currentFolderMsg || newestMsg

    const totalCount = mergedMessages.length
    const isMultiMessage = totalCount > 1

    const allReferences = []
    for (const msg of mergedMessages) {
      if (msg.references) {
        const refs = Array.isArray(msg.references) ? msg.references : [msg.references]
        for (const r of refs) {
          if (r && !allReferences.includes(r)) allReferences.push(r)
        }
      }
      if (msg.in_reply_to && !allReferences.includes(msg.in_reply_to)) {
        allReferences.push(msg.in_reply_to)
      }
      if (msg.message_id && !allReferences.includes(msg.message_id)) {
        allReferences.push(msg.message_id)
      }
    }

    const isSplit = mergedMessages.some(m => conversationsStore.isUserOverride(m.message_id))

    return {
      ...firstMsg,
      date: newestMsg.date,
      timestamp: newestMsg.timestamp,
      subject: newestMsg.subject || firstMsg.subject,
      isConversation: isMultiMessage,
      isSplit,
      messageCount: totalCount,
      threadLoaded,
      messages: mergedMessages,
      hasUnread: mergedMessages.some(m => !m.seen && m.folder && m.folder.toLowerCase() === curFolderLower),
      hasStarred: mergedMessages.some(m => m.flagged),
      answered: mergedMessages.some(m => m.answered) ||
        mergedMessages.some(m => {
          const f = (m.folder || '').toLowerCase()
          return f.includes('sent') && !f.includes('unsent')
        }),
      has_attachment: mergedMessages.some(m => m.has_attachment),
      conversationKey: conv.conversation_id,
      conversation_id: conv.conversation_id,
      threadReferences: allReferences,
      snippet: conv.snippet || newestMsg.snippet || firstMsg.snippet || null,
      body_preview: conv.snippet || newestMsg.body_preview || firstMsg.body_preview || null
    }
  }).filter(Boolean)

  // Collect message_ids already covered by conversation items to prevent
  // duplicates when a restored message gets a new UID but the conversation DB
  // still references the old one (stale UID → placeholder in conversation,
  // new UID → orphan → visual duplicate).
  const messageIdsInConversations = new Set()
  for (const item of conversationItems) {
    if (!item) continue
    for (const m of (item.messages || [])) {
      if (m.message_id) messageIdsInConversations.add(m.message_id)
    }
  }

  const orphanMessages = msgs.filter(m => {
    const key = `${(m.folder || folder).toLowerCase()}:${Number(m.uid)}`
    if (uidsInConversations.has(key)) return false
    // Skip if this message_id is already represented in a conversation
    if (m.message_id && messageIdsInConversations.has(m.message_id)) return false
    return true
  })

  const orphanItems = orphanMessages.map(m => {
    const convId = m.message_id ? `orphan:${m.message_id}` : `orphan:uid:${folder}:${m.uid}`
    return {
      ...m,
      isConversation: false,
      isOrphan: true,
      isSplit: false,
      messageCount: 1,
      threadLoaded: false,
      messages: [m],
      hasUnread: !m.seen,
      hasStarred: m.flagged,
      answered: m.answered || false,
      has_attachment: m.has_attachment,
      conversationKey: convId,
      conversation_id: convId,
      threadReferences: [m.message_id, m.in_reply_to, ...(m.references || [])].filter(Boolean),
      snippet: m.snippet || null,
      body_preview: m.body_preview || null
    }
  })

  return [...conversationItems, ...orphanItems].sort((a, b) => sortTs(b) - sortTs(a))
}
