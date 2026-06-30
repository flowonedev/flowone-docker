import { useClientsStore } from '@/stores/clients'
import { useMailboxStore } from '@/stores/mailbox'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'

/**
 * Mind Map Data Transformation Composable
 * Transforms various data sources into unified mind map format
 */
export function useMindMapData() {
  const clientsStore = useClientsStore()
  const mailboxStore = useMailboxStore()
  const calendarStore = useCalendarStore()
  const boardsStore = useBoardsStore()

  /**
   * Transform client data into mind map format
   * @param {Object} clientData - Client detail from store (includes threads, tasks, files)
   * @returns {Object} Mind map root node
   */
  function transformClientToMindMap(clientData) {
    if (!clientData?.client) return null

    const client = clientData.client
    const threads = clientData.threads || []
    const linkedBoards = client.linked_boards || []
    const linkedCalendar = clientData.calendar_events || []

    // Build client root node
    const root = {
      id: `client-${client.id}`,
      type: 'client',
      label: client.display_name || client.name || client.email,
      sublabel: `${client.total_emails || 0} emails`,
      icon: 'person',
      meta: {
        clientId: client.id,
        email: client.email,
        domain: client.domain,
        status: client.status,
        emailCount: client.total_emails || 0,
        lastActivity: client.last_activity,
      },
      children: [],
      linkedTo: [],
    }

    // Add conversation threads as children
    threads.forEach((thread, i) => {
      const conversationNode = {
        id: `conv-${thread.conversation_id || thread.id || i}`,
        type: 'conversation',
        label: thread.subject || 'No Subject',
        icon: 'forum',
        meta: {
          conversationId: thread.conversation_id,
          messageCount: thread.message_count || thread.emails?.length || 0,
          unreadCount: thread.unread_count || 0,
          lastDate: thread.last_date,
          participants: thread.participants || [],
        },
        children: [],
      }

      // Add individual emails in this thread
      const emails = thread.emails || thread.messages || []
      emails.forEach(email => {
        conversationNode.children.push({
          id: `email-${email.message_id || email.uid}`,
          type: 'email',
          label: email.subject || 'No Subject',
          icon: email.seen ? 'mail' : 'mark_email_unread',
          meta: {
            messageId: email.message_id,
            uid: email.uid,
            folder: email.folder || 'INBOX',
            from: email.from_name || email.from_email,
            timestamp: email.timestamp || email.date,
            unread: !email.seen,
            flagged: email.flagged,
            hasAttachment: email.has_attachment,
            preview: email.preview || email.text_body?.substring(0, 100),
          },
          children: [],
          linkedTo: [],
        })
      })

      root.children.push(conversationNode)
    })

    // Add linked boards as connected nodes
    linkedBoards.forEach(board => {
      root.linkedTo.push({
        id: `board-${board.board_id || board.id}`,
        type: 'board',
        label: board.name || board.title,
        icon: 'dashboard',
        meta: {
          boardId: board.board_id || board.id,
          cardCount: board.card_count || 0,
        },
      })
    })

    // Add linked calendar events
    linkedCalendar.forEach(event => {
      root.linkedTo.push({
        id: `cal-${event.id}`,
        type: 'calendar',
        label: event.title,
        icon: 'event',
        meta: {
          eventId: event.id,
          eventDate: event.start_date,
          allDay: event.all_day,
        },
      })
    })

    return root
  }

  /**
   * Transform a conversation/thread into mind map format
   * @param {Object} conversation - Conversation data with messages
   * @returns {Object} Mind map root node
   */
  function transformConversationToMindMap(conversation) {
    if (!conversation) return null

    const messages = conversation.messages || []
    if (!messages.length) return null

    // Sort by date
    const sorted = [...messages].sort((a, b) => 
      new Date(a.timestamp * 1000) - new Date(b.timestamp * 1000)
    )

    // First message is root
    const firstEmail = sorted[0]
    
    // Build message reply tree
    const messageMap = new Map()
    sorted.forEach(msg => {
      messageMap.set(msg.message_id, msg)
    })

    // Create nodes
    function createEmailNode(email, depth = 0) {
      return {
        id: `email-${email.message_id || email.uid}`,
        type: 'email',
        label: email.subject || 'No Subject',
        sublabel: email.from_name || email.from_email,
        icon: email.seen ? 'mail' : 'mark_email_unread',
        meta: {
          messageId: email.message_id,
          uid: email.uid,
          folder: email.folder || 'INBOX',
          from: email.from_name || email.from_email,
          fromEmail: email.from_email,
          timestamp: email.timestamp || email.date,
          unread: !email.seen,
          flagged: email.flagged,
          hasAttachment: email.has_attachment,
          preview: email.preview || email.text_body?.substring(0, 150),
        },
        children: [],
        linkedTo: [],
      }
    }

    // Build tree based on in_reply_to
    const root = createEmailNode(firstEmail)
    const nodeMap = new Map([[firstEmail.message_id, root]])

    sorted.slice(1).forEach(email => {
      const node = createEmailNode(email)
      
      // Find parent by in_reply_to
      let parent = null
      if (email.in_reply_to) {
        const parentId = email.in_reply_to.replace(/^<|>$/g, '')
        parent = nodeMap.get(parentId) || nodeMap.get(`<${parentId}>`)
      }
      
      // If no parent found, attach to root
      if (!parent) {
        parent = root
      }
      
      parent.children.push(node)
      nodeMap.set(email.message_id, node)
    })

    // Add linked calendar events if any
    const linkedEvents = calendarStore.events.filter(e => 
      e.linked_message_id && messages.some(m => m.message_id === e.linked_message_id)
    )
    
    linkedEvents.forEach(event => {
      const emailNode = nodeMap.get(event.linked_message_id)
      if (emailNode) {
        emailNode.linkedTo.push({
          id: `cal-${event.id}`,
          type: 'calendar',
          label: event.title,
          icon: 'event',
          meta: {
            eventId: event.id,
            eventDate: event.start_date,
          },
        })
      }
    })

    return root
  }

  /**
   * Transform folder emails into topic clusters (for AI clustering)
   * @param {Array} emails - Array of emails
   * @param {Array} topics - AI-generated topic clusters
   * @returns {Object} Mind map root node with topics
   */
  function transformTopicsToMindMap(emails, topics) {
    const root = {
      id: 'topics-root',
      type: 'topic',
      label: 'Email Topics',
      icon: 'hub',
      meta: {
        totalEmails: emails.length,
        topicCount: topics.length,
      },
      children: [],
    }

    topics.forEach((topic, i) => {
      const topicNode = {
        id: `topic-${i}`,
        type: 'topic',
        label: topic.name || `Topic ${i + 1}`,
        sublabel: `${topic.emails?.length || 0} emails`,
        icon: 'label',
        meta: {
          keywords: topic.keywords || [],
          emailCount: topic.emails?.length || 0,
        },
        children: [],
      }

      // Add emails in this topic
      topic.emails?.forEach(email => {
        topicNode.children.push({
          id: `email-${email.message_id || email.uid}`,
          type: 'email',
          label: email.subject || 'No Subject',
          icon: 'mail',
          meta: {
            messageId: email.message_id,
            uid: email.uid,
            folder: email.folder,
            from: email.from_name || email.from_email,
            timestamp: email.timestamp,
          },
          children: [],
        })
      })

      root.children.push(topicNode)
    })

    return root
  }

  /**
   * Quick transform from mailbox conversation for context menu
   * @param {Object} conv - Conversation from mailbox store
   * @returns {Object} Mind map data
   */
  function transformQuickConversation(conv) {
    if (!conv) return null

    const messages = conv.messages || [conv]
    
    return transformConversationToMindMap({
      ...conv,
      messages,
    })
  }

  /**
   * Build relationships graph from selected item
   * Shows connections to clients, calendar, boards, etc.
   */
  function buildRelationshipGraph(item, type) {
    // This would query related items and build a interconnected graph
    // For now, returns a simple structure
    const root = {
      id: `${type}-${item.id}`,
      type: type,
      label: item.label || item.title || item.subject,
      icon: type === 'email' ? 'mail' : type === 'board' ? 'dashboard' : 'event',
      meta: item,
      children: [],
      linkedTo: [],
    }

    // Find related items
    if (type === 'email') {
      // Find client by email domain
      const client = clientsStore.getClientByEmail(item.from_email)
      if (client) {
        root.linkedTo.push({
          id: `client-${client.id}`,
          type: 'client',
          label: client.display_name || client.name,
          icon: 'person',
          meta: { clientId: client.id },
        })
      }

      // Find linked calendar events
      const events = calendarStore.events.filter(e => e.linked_message_id === item.message_id)
      events.forEach(event => {
        root.linkedTo.push({
          id: `cal-${event.id}`,
          type: 'calendar',
          label: event.title,
          icon: 'event',
          meta: { eventId: event.id },
        })
      })
    }

    return root
  }

  return {
    transformClientToMindMap,
    transformConversationToMindMap,
    transformTopicsToMindMap,
    transformQuickConversation,
    buildRelationshipGraph,
  }
}

