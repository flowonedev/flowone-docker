import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Chat Sync Engine
 * 
 * Handles synchronization of chat data:
 * - Conversations (DM, group, channel)
 * - Messages (with attachments, reactions, pinning)
 * - Participants and read receipts
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all conversations + recent messages per conversation
 * - Incremental: WebSocket events for real-time + periodic pull for missed
 * - Offline: Queue sent messages, reactions, read receipts for later sync
 */
export class ChatSyncEngine extends BaseSyncEngine {
  entityType = 'chat'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[ChatSync] Pulling changes...')
    await this.syncConversations()
  }

  /**
   * Sync all conversations and their recent messages
   */
  private async syncConversations(): Promise<void> {
    try {
      const response = await this.api.get('/chat/conversations')
      
      if (response.data.success && response.data.data) {
        const conversations = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.conversations || []
        
        for (const conv of conversations) {
          try {
            this.upsertConversation(conv)
          } catch (upsertErr: any) {
            console.error(`[ChatSync] Failed to upsert conversation ${conv.id}:`, upsertErr?.message || upsertErr)
            continue
          }
          
          // Sync participants if included inline
          if (conv.participants && Array.isArray(conv.participants)) {
            for (const participant of conv.participants) {
              this.upsertParticipant(conv.id, participant)
            }
          }
        }
        
        console.log(`[ChatSync] Synced ${conversations.length} conversations`)
        
        // Fetch details (with participants) and messages per conversation
        for (const conv of conversations) {
          await this.syncConversationDetails(conv.id)
          await this.syncMessages(conv.id)
        }
      }
    } catch (error: any) {
      // 404 means the Chat addon is disabled on the server - not a real error
      if (error.response?.status === 404) {
        console.log('[ChatSync] Chat addon not enabled on server, skipping')
        return
      }
      console.error('[ChatSync] Failed to sync conversations:', error?.message || error?.toString?.() || error)
      throw error
    }
  }

  /**
   * Fetch full conversation details (including participants) from individual endpoint
   */
  private async syncConversationDetails(remoteConversationId: number): Promise<void> {
    try {
      const response = await this.api.get(`/chat/conversations/${remoteConversationId}`)
      
      if (response.data.success && response.data.data) {
        const conv = response.data.data
        
        if (conv.participants && Array.isArray(conv.participants)) {
          for (const participant of conv.participants) {
            this.upsertParticipant(remoteConversationId, participant)
          }
          console.log(`[ChatSync] Synced ${conv.participants.length} participants for conversation ${remoteConversationId}`)
        }
      }
    } catch (error: any) {
      console.error(`[ChatSync] Failed to sync details for conversation ${remoteConversationId}:`, error.message)
    }
  }

  /**
   * Sync messages for a specific conversation
   */
  private async syncMessages(remoteConversationId: number): Promise<void> {
    try {
      const localConv = this.db.prepare(
        'SELECT id FROM chat_conversations WHERE remote_id = ?'
      ).get(remoteConversationId) as { id: number } | undefined
      
      if (!localConv) return
      
      // Get the latest message we have for delta sync
      const latestMsg = this.db.prepare(
        'SELECT remote_id, created_at FROM chat_messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 1'
      ).get(localConv.id) as { remote_id: number; created_at: string } | undefined
      
      const params: any = { limit: 50 }
      if (latestMsg?.remote_id) {
        params.after_id = latestMsg.remote_id
      }
      
      const response = await this.api.get(`/chat/conversations/${remoteConversationId}/messages`, { params })
      
      if (response.data.success && response.data.data) {
        const messages = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.messages || []
        
        for (const msg of messages) {
          this.upsertMessage(localConv.id, msg)
        }
      }
    } catch (error: any) {
      // Don't throw - individual conversation message sync failure shouldn't stop everything
      console.error(`[ChatSync] Failed to sync messages for conversation ${remoteConversationId}:`, error.message)
    }
  }

  /**
   * Insert or update a conversation
   */
  private upsertConversation(conv: any): void {
    this.db.prepare(`
      INSERT INTO chat_conversations (
        remote_id, organization_domain, type, name, avatar, description, settings,
        is_public, slug, topic, purpose, is_default,
        created_by, last_message_at, last_message_preview, last_message_sender_id,
        message_count, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', datetime('now'), datetime('now'))
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name,
        avatar = excluded.avatar,
        description = excluded.description,
        settings = excluded.settings,
        is_public = excluded.is_public,
        slug = excluded.slug,
        topic = excluded.topic,
        purpose = excluded.purpose,
        last_message_at = excluded.last_message_at,
        last_message_preview = excluded.last_message_preview,
        last_message_sender_id = excluded.last_message_sender_id,
        message_count = excluded.message_count,
        sync_status = 'synced',
        updated_at = datetime('now')
    `).run(
      conv.id ?? null,
      conv.organization_domain || conv.domain || '',
      conv.type || 'direct',
      conv.name ?? null,
      conv.avatar ?? null,
      conv.description ?? null,
      conv.settings ? JSON.stringify(conv.settings) : null,
      conv.is_public !== undefined ? (conv.is_public ? 1 : 0) : 1,
      conv.slug ?? null,
      conv.topic ?? null,
      conv.purpose ?? null,
      conv.is_default ? 1 : 0,
      conv.created_by ?? 0,
      conv.last_message_at ?? null,
      conv.last_message_preview ?? null,
      conv.last_message_sender_id ?? null,
      conv.message_count ?? 0
    )
  }

  /**
   * Insert or update a participant
   */
  private upsertParticipant(remoteConvId: number, participant: any): void {
    const localConv = this.db.prepare(
      'SELECT id FROM chat_conversations WHERE remote_id = ?'
    ).get(remoteConvId) as { id: number } | undefined
    
    if (!localConv) return
    
    this.db.prepare(`
      INSERT INTO chat_participants (
        remote_id, conversation_id, colleague_id, last_read_message_id, last_read_at,
        unread_count, is_pinned, is_muted, is_archived, is_admin, added_by, nickname, joined_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
      ON CONFLICT(conversation_id, colleague_id) DO UPDATE SET
        last_read_message_id = excluded.last_read_message_id,
        last_read_at = excluded.last_read_at,
        unread_count = excluded.unread_count,
        is_pinned = excluded.is_pinned,
        is_muted = excluded.is_muted,
        is_archived = excluded.is_archived,
        is_admin = excluded.is_admin,
        nickname = excluded.nickname
    `).run(
      participant.id ?? null,
      localConv.id,
      participant.colleague_id ?? participant.id ?? null,
      participant.last_read_message_id ?? null,
      participant.last_read_at ?? null,
      participant.unread_count ?? 0,
      participant.is_pinned ? 1 : 0,
      participant.is_muted ? 1 : 0,
      participant.is_archived ? 1 : 0,
      participant.is_admin ? 1 : 0,
      participant.added_by ?? null,
      participant.nickname ?? null
    )
  }

  /**
   * Insert or update a message
   */
  private upsertMessage(localConvId: number, msg: any): void {
    this.db.prepare(`
      INSERT INTO chat_messages (
        remote_id, conversation_id, sender_id, content, content_type,
        reply_to_id, attachments, voice_duration,
        is_edited, is_pinned, pinned_at, pinned_by,
        edited_at, deleted_at, sync_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        content = excluded.content,
        is_edited = excluded.is_edited,
        is_pinned = excluded.is_pinned,
        pinned_at = excluded.pinned_at,
        pinned_by = excluded.pinned_by,
        edited_at = excluded.edited_at,
        deleted_at = excluded.deleted_at,
        sync_status = 'synced'
    `).run(
      msg.id,
      localConvId,
      msg.sender_id,
      msg.content || '',
      msg.content_type || 'text',
      msg.reply_to_id || null,
      msg.attachments ? JSON.stringify(msg.attachments) : null,
      msg.voice_duration || null,
      msg.is_edited ? 1 : 0,
      msg.is_pinned ? 1 : 0,
      msg.pinned_at || null,
      msg.pinned_by || null,
      msg.edited_at || null,
      msg.deleted_at || null,
      msg.created_at || new Date().toISOString()
    )
    
    // Sync reactions if present
    if (msg.reactions && Array.isArray(msg.reactions)) {
      const localMsg = this.db.prepare(
        'SELECT id FROM chat_messages WHERE remote_id = ?'
      ).get(msg.id) as { id: number } | undefined
      
      if (localMsg) {
        for (const reaction of msg.reactions) {
          this.db.prepare(`
            INSERT INTO chat_message_reactions (message_id, colleague_id, emoji, created_at)
            VALUES (?, ?, ?, datetime('now'))
            ON CONFLICT(message_id, colleague_id, emoji) DO NOTHING
          `).run(localMsg.id, reaction.colleague_id, reaction.emoji)
        }
      }
    }
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'send_message': {
        const res = await this.api.post(
          `/chat/conversations/${payload.conversation_remote_id}/messages`,
          { content: payload.content, content_type: payload.content_type || 'text', reply_to_id: payload.reply_to_id }
        )
        if (res.data.success && res.data.data?.id) {
          this.db.prepare(`
            UPDATE chat_messages SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      
      case 'edit_message':
        await this.api.put(
          `/chat/conversations/${payload.conversation_remote_id}/messages/${payload.message_remote_id}`,
          { content: payload.content }
        )
        break
        
      case 'delete_message':
        await this.api.delete(
          `/chat/conversations/${payload.conversation_remote_id}/messages/${payload.message_remote_id}`
        )
        break
        
      case 'add_reaction':
        await this.api.post(
          `/chat/messages/${payload.message_remote_id}/reactions`,
          { emoji: payload.emoji }
        )
        break
        
      case 'remove_reaction':
        await this.api.delete(
          `/chat/messages/${payload.message_remote_id}/reactions/${encodeURIComponent(payload.emoji)}`
        )
        break
        
      case 'mark_read':
        await this.api.post(
          `/chat/conversations/${payload.conversation_remote_id}/read`,
          { message_id: payload.message_remote_id }
        )
        break
        
      case 'create_group':
      case 'create_channel': {
        const createRes = await this.api.post('/chat/conversations', payload)
        if (createRes.data.success && createRes.data.data?.id) {
          this.db.prepare(`
            UPDATE chat_conversations SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(createRes.data.data.id, queueItem.entity_id)
        }
        break
      }
        
      case 'pin_message':
        await this.api.post(`/chat/messages/${payload.message_remote_id}/pin`)
        break
        
      case 'unpin_message':
        await this.api.delete(`/chat/messages/${payload.message_remote_id}/pin`)
        break
        
      default:
        console.warn(`[ChatSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[ChatSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'CHAT_MESSAGE_NEW':
      case 'CHAT_MESSAGE_CREATED': {
        const msg = event.payload
        if (msg?.conversation_id) {
          const localConv = this.db.prepare(
            'SELECT id FROM chat_conversations WHERE remote_id = ?'
          ).get(msg.conversation_id) as { id: number } | undefined
          
          if (localConv) {
            this.upsertMessage(localConv.id, msg)
            
            // Update conversation's last message
            this.db.prepare(`
              UPDATE chat_conversations 
              SET last_message_at = ?, last_message_preview = ?, last_message_sender_id = ?,
                  message_count = message_count + 1
              WHERE remote_id = ?
            `).run(msg.created_at, msg.content?.substring(0, 255), msg.sender_id, msg.conversation_id)
          }
        }
        break
      }
        
      case 'CHAT_MESSAGE_UPDATED':
      case 'CHAT_MESSAGE_EDITED': {
        const msg = event.payload
        if (msg?.id) {
          this.db.prepare(`
            UPDATE chat_messages 
            SET content = ?, is_edited = 1, edited_at = datetime('now')
            WHERE remote_id = ?
          `).run(msg.content, msg.id)
        }
        break
      }
        
      case 'CHAT_MESSAGE_DELETED': {
        const msg = event.payload
        if (msg?.id) {
          this.db.prepare(`
            UPDATE chat_messages SET deleted_at = datetime('now') WHERE remote_id = ?
          `).run(msg.id)
        }
        break
      }
        
      case 'CHAT_MESSAGE_PINNED': {
        const msg = event.payload
        if (msg?.id) {
          this.db.prepare(`
            UPDATE chat_messages SET is_pinned = 1, pinned_at = datetime('now'), pinned_by = ? WHERE remote_id = ?
          `).run(msg.pinned_by || null, msg.id)
        }
        break
      }
        
      case 'CHAT_MESSAGE_UNPINNED': {
        const msg = event.payload
        if (msg?.id) {
          this.db.prepare(`
            UPDATE chat_messages SET is_pinned = 0, pinned_at = NULL, pinned_by = NULL WHERE remote_id = ?
          `).run(msg.id)
        }
        break
      }
        
      case 'CHAT_REACTION_ADDED': {
        const r = event.payload
        if (r?.message_id) {
          const localMsg = this.db.prepare(
            'SELECT id FROM chat_messages WHERE remote_id = ?'
          ).get(r.message_id) as { id: number } | undefined
          
          if (localMsg) {
            this.db.prepare(`
              INSERT INTO chat_message_reactions (message_id, colleague_id, emoji, created_at)
              VALUES (?, ?, ?, datetime('now'))
              ON CONFLICT(message_id, colleague_id, emoji) DO NOTHING
            `).run(localMsg.id, r.colleague_id, r.emoji)
          }
        }
        break
      }
        
      case 'CHAT_REACTION_REMOVED': {
        const r = event.payload
        if (r?.message_id) {
          const localMsg = this.db.prepare(
            'SELECT id FROM chat_messages WHERE remote_id = ?'
          ).get(r.message_id) as { id: number } | undefined
          
          if (localMsg) {
            this.db.prepare(`
              DELETE FROM chat_message_reactions WHERE message_id = ? AND colleague_id = ? AND emoji = ?
            `).run(localMsg.id, r.colleague_id, r.emoji)
          }
        }
        break
      }
        
      case 'CHAT_CONVERSATION_CREATED': {
        if (event.payload) {
          this.upsertConversation(event.payload)
          if (event.payload.participants) {
            for (const p of event.payload.participants) {
              this.upsertParticipant(event.payload.id, p)
            }
          }
        }
        break
      }
        
      case 'CHAT_CONVERSATION_UPDATED': {
        if (event.payload) {
          this.upsertConversation(event.payload)
        }
        break
      }
        
      case 'CHAT_READ_RECEIPT': {
        const r = event.payload
        if (r?.conversation_id && r?.colleague_id) {
          const localConv = this.db.prepare(
            'SELECT id FROM chat_conversations WHERE remote_id = ?'
          ).get(r.conversation_id) as { id: number } | undefined
          
          if (localConv) {
            this.db.prepare(`
              UPDATE chat_participants 
              SET last_read_message_id = ?, last_read_at = datetime('now'), unread_count = ?
              WHERE conversation_id = ? AND colleague_id = ?
            `).run(r.message_id, r.unread_count || 0, localConv.id, r.colleague_id)
          }
        }
        break
      }
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getConversations(): any[] {
    return this.db.all(`
      SELECT * FROM chat_conversations 
      ORDER BY last_message_at DESC
    `)
  }

  getMessages(conversationId: number, limit = 50, beforeId?: number): any[] {
    if (beforeId) {
      return this.db.all(`
        SELECT * FROM chat_messages 
        WHERE conversation_id = ? AND id < ? AND deleted_at IS NULL
        ORDER BY created_at DESC LIMIT ?
      `, [conversationId, beforeId, limit])
    }
    return this.db.all(`
      SELECT * FROM chat_messages 
      WHERE conversation_id = ? AND deleted_at IS NULL
      ORDER BY created_at DESC LIMIT ?
    `, [conversationId, limit])
  }

  getParticipants(conversationId: number): any[] {
    return this.db.all(`
      SELECT cp.*, c.email, c.display_name, c.avatar_path, c.status
      FROM chat_participants cp
      JOIN colleagues c ON c.id = cp.colleague_id
      WHERE cp.conversation_id = ?
    `, [conversationId])
  }

  getUnreadCount(): number {
    const row = this.db.get(`
      SELECT COALESCE(SUM(unread_count), 0) as total
      FROM chat_participants
      WHERE is_archived = 0
    `)
    return row?.total || 0
  }
}

