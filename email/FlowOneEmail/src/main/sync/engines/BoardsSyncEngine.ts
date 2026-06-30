import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase, Board, BoardList, BoardCard } from '../../database/Database'

/**
 * Boards Sync Engine
 * 
 * Handles synchronization of Kanban board data:
 * - Boards (user's boards list)
 * - Lists (columns)
 * - Cards (tasks)
 * - Checklists, comments, labels, attachments
 * 
 * Sync Strategy:
 * - Real-time: WebSocket events for instant updates (card moves, etc.)
 * - Full sync on startup and periodic refresh
 * - Offline support: All CRUD operations work offline
 */
export class BoardsSyncEngine extends BaseSyncEngine {
  entityType = 'board'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[BoardsSync] Pulling changes...')
    
    // 1. Sync board list first
    await this.syncBoards()
    
    // 2. Sync each board's lists and cards
    const boards = this.db.prepare(
      'SELECT * FROM boards WHERE is_archived = 0'
    ).all() as Board[]
    
    for (const board of boards) {
      if (board.remote_id) {
        await this.syncBoardContents(board.remote_id)
      }
    }
  }

  /**
   * Sync board list
   */
  private async syncBoards(): Promise<void> {
    try {
      const response = await this.api.get('/boards')
      
      if (response.data.success && response.data.data) {
        // API returns { data: { boards: [...] } } or { data: [...] }
        const boards = Array.isArray(response.data.data) 
          ? response.data.data 
          : response.data.data.boards || []
        
        for (const board of boards) {
          this.upsertBoard(board)
        }
        
        console.log(`[BoardsSync] Synced ${boards.length} boards`)
      }
    } catch (error: any) {
      // 404 means the Kanban Boards addon is disabled on the server - not a real error
      if (error.response?.status === 404) {
        console.log('[BoardsSync] Kanban Boards addon not enabled on server, skipping')
        return
      }
      console.error('[BoardsSync] Failed to sync boards:', error.message)
      throw error
    }
  }

  /**
   * Sync a board's lists and cards
   */
  private async syncBoardContents(boardRemoteId: number): Promise<void> {
    try {
      const response = await this.api.get(`/boards/${boardRemoteId}`)
      
      if (response.data.success && response.data.data) {
        // API returns { data: { board: {...} } } or { data: {...} }
        const boardData = response.data.data.board || response.data.data
        
        // Get local board ID
        const localBoard = this.db.prepare(
          'SELECT id FROM boards WHERE remote_id = ?'
        ).get(boardRemoteId) as { id: number } | undefined
        
        if (!localBoard) return
        
        // Sync lists
        const lists = boardData.lists || []
        let totalCardsFromLists = 0
        
        for (const list of lists) {
          this.upsertList(localBoard.id, list)
          
          // Cards might be nested inside each list
          if (list.cards && Array.isArray(list.cards)) {
            for (const card of list.cards) {
              // Ensure card has list_id
              card.list_id = card.list_id || list.id
              this.upsertCard(card)
              totalCardsFromLists++
            }
          }
        }
        console.log(`[BoardsSync] Synced ${lists.length} lists for board ${boardRemoteId}`)
        
        // Also check for cards at board level (separate array)
        const boardLevelCards = boardData.cards || []
        for (const card of boardLevelCards) {
          this.upsertCard(card)
        }
        
        const totalCards = totalCardsFromLists + boardLevelCards.length
        if (totalCards > 0) {
          console.log(`[BoardsSync] Synced ${totalCards} cards for board ${boardRemoteId} (${totalCardsFromLists} from lists, ${boardLevelCards.length} from board)`)
        }
        
        // Sync labels
        const labels = boardData.labels || []
        for (const label of labels) {
          this.upsertLabel(localBoard.id, label)
        }
      }
    } catch (error: any) {
      console.error(`[BoardsSync] Failed to sync board ${boardRemoteId}:`, error.message)
    }
  }

  /**
   * Insert or update a board in local DB
   */
  private upsertBoard(board: any): void {
    this.db.prepare(`
      INSERT INTO boards (
        remote_id, name, description, color, background_type, background_value,
        is_archived, is_starred, owner_email, drive_folder_id, client_id,
        sync_status, remote_updated_at, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, datetime('now'))
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name,
        description = excluded.description,
        color = excluded.color,
        background_type = excluded.background_type,
        background_value = excluded.background_value,
        is_archived = excluded.is_archived,
        is_starred = excluded.is_starred,
        drive_folder_id = excluded.drive_folder_id,
        client_id = excluded.client_id,
        sync_status = 'synced',
        remote_updated_at = excluded.remote_updated_at
    `).run(
      board.id,
      board.name,
      board.description || null,
      board.color || null,
      board.background_type || 'color',
      board.background_value || null,
      board.is_archived ? 1 : 0,
      board.is_starred ? 1 : 0,
      board.owner_email || null,
      board.drive_folder_id || null,
      board.client_id || null,
      board.updated_at || null
    )
  }

  /**
   * Insert or update a list in local DB
   */
  private upsertList(boardId: number, list: any): void {
    this.db.prepare(`
      INSERT INTO board_lists (remote_id, board_id, name, position, is_archived, wip_limit, sync_status)
      VALUES (?, ?, ?, ?, ?, ?, 'synced')
      ON CONFLICT(remote_id) DO UPDATE SET
        board_id = excluded.board_id,
        name = excluded.name,
        position = excluded.position,
        is_archived = excluded.is_archived,
        wip_limit = excluded.wip_limit,
        sync_status = 'synced'
    `).run(
      list.id,
      boardId,
      list.name,
      list.position || 0,
      list.is_archived ? 1 : 0,
      list.wip_limit || null
    )
  }

  /**
   * Insert or update a card in local DB
   */
  private upsertCard(card: any): void {
    // Get local list ID
    const localList = this.db.prepare(
      'SELECT id FROM board_lists WHERE remote_id = ?'
    ).get(card.list_id) as { id: number } | undefined
    
    if (!localList) {
      console.warn(`[BoardsSync] List not found for card: ${card.id}`)
      return
    }
    
    this.db.prepare(`
      INSERT INTO board_cards (
        remote_id, list_id, title, description, position, due_date, due_complete,
        cover_image, cover_color, is_archived, assignees, labels, checklist_progress,
        sync_status, remote_updated_at, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, datetime('now'))
      ON CONFLICT(remote_id) DO UPDATE SET
        list_id = excluded.list_id,
        title = excluded.title,
        description = excluded.description,
        position = excluded.position,
        due_date = excluded.due_date,
        due_complete = excluded.due_complete,
        cover_image = excluded.cover_image,
        cover_color = excluded.cover_color,
        is_archived = excluded.is_archived,
        assignees = excluded.assignees,
        labels = excluded.labels,
        checklist_progress = excluded.checklist_progress,
        sync_status = 'synced',
        remote_updated_at = excluded.remote_updated_at
    `).run(
      card.id,
      localList.id,
      card.title,
      card.description || null,
      card.position || 0,
      card.due_date || null,
      card.due_complete ? 1 : 0,
      card.cover_image || null,
      card.cover_color || null,
      card.is_archived ? 1 : 0,
      JSON.stringify(card.assignees || []),
      JSON.stringify(card.labels || []),
      JSON.stringify(card.checklist_progress || { completed: 0, total: 0 }),
      card.updated_at || null
    )
    
    // Sync checklists if present
    if (card.checklists && Array.isArray(card.checklists)) {
      const localCard = this.db.prepare(
        'SELECT id FROM board_cards WHERE remote_id = ?'
      ).get(card.id) as { id: number } | undefined
      
      if (localCard) {
        for (const checklist of card.checklists) {
          this.upsertChecklist(localCard.id, checklist)
        }
      }
    }
  }

  /**
   * Insert or update a board label
   */
  private upsertLabel(boardId: number, label: any): void {
    this.db.prepare(`
      INSERT INTO board_labels (remote_id, board_id, name, color)
      VALUES (?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name,
        color = excluded.color
    `).run(label.id, boardId, label.name || null, label.color)
  }

  /**
   * Insert or update a checklist
   */
  private upsertChecklist(cardId: number, checklist: any): void {
    this.db.prepare(`
      INSERT INTO card_checklists (remote_id, card_id, title, position)
      VALUES (?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        title = excluded.title,
        position = excluded.position
    `).run(checklist.id, cardId, checklist.title, checklist.position || 0)
    
    // Get local checklist ID
    const localChecklist = this.db.prepare(
      'SELECT id FROM card_checklists WHERE remote_id = ?'
    ).get(checklist.id) as { id: number } | undefined
    
    if (localChecklist && checklist.items) {
      for (const item of checklist.items) {
        this.db.prepare(`
          INSERT INTO checklist_items (remote_id, checklist_id, text, is_checked, position)
          VALUES (?, ?, ?, ?, ?)
          ON CONFLICT(remote_id) DO UPDATE SET
            text = excluded.text,
            is_checked = excluded.is_checked,
            position = excluded.position
        `).run(item.id, localChecklist.id, item.text, item.is_checked ? 1 : 0, item.position || 0)
      }
    }
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      // Board actions
      case 'create_board':
        const boardResponse = await this.api.post('/boards', payload)
        if (boardResponse.data.success && boardResponse.data.data?.id) {
          this.db.prepare('UPDATE boards SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(boardResponse.data.data.id, queueItem.entity_id)
        }
        break
        
      case 'update_board':
        await this.api.put(`/boards/${payload.remote_id}`, payload)
        break
        
      case 'delete_board':
        await this.api.delete(`/boards/${payload.remote_id}`)
        break
        
      // List actions
      case 'create_list':
        const listResponse = await this.api.post(`/boards/${payload.board_id}/lists`, payload)
        if (listResponse.data.success && listResponse.data.data?.id) {
          this.db.prepare('UPDATE board_lists SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(listResponse.data.data.id, queueItem.entity_id)
        }
        break
        
      case 'update_list':
        await this.api.put(`/boards/lists/${payload.remote_id}`, payload)
        break
        
      case 'move_list':
        await this.api.post(`/boards/lists/${payload.remote_id}/move`, { position: payload.position })
        break
        
      // Card actions
      case 'create_card':
        const cardResponse = await this.api.post(`/boards/lists/${payload.list_id}/cards`, payload)
        if (cardResponse.data.success && cardResponse.data.data?.id) {
          this.db.prepare('UPDATE board_cards SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(cardResponse.data.data.id, queueItem.entity_id)
        }
        break
        
      case 'update_card':
        await this.api.put(`/boards/cards/${payload.remote_id}`, payload)
        break
        
      case 'move_card':
        await this.api.post(`/boards/cards/${payload.remote_id}/move`, {
          list_id: payload.list_id,
          position: payload.position
        })
        break
        
      case 'archive_card':
        await this.api.post(`/boards/cards/${payload.remote_id}/archive`)
        break
        
      case 'delete_card':
        await this.api.delete(`/boards/cards/${payload.remote_id}`)
        break
        
      default:
        console.warn(`[BoardsSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[BoardsSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      // Board events
      case 'BOARD_CREATED':
      case 'BOARD_UPDATED':
        this.upsertBoard(event.payload)
        break
        
      case 'BOARD_DELETED':
        this.db.prepare('DELETE FROM boards WHERE remote_id = ?').run(event.payload.id)
        break
        
      // List events
      case 'LIST_CREATED':
      case 'LIST_UPDATED':
        const board = this.db.prepare('SELECT id FROM boards WHERE remote_id = ?').get(event.payload.board_id) as { id: number } | undefined
        if (board) {
          this.upsertList(board.id, event.payload)
        }
        break
        
      case 'LIST_DELETED':
        this.db.prepare('DELETE FROM board_lists WHERE remote_id = ?').run(event.payload.id)
        break
        
      case 'LIST_MOVED':
        this.db.prepare('UPDATE board_lists SET position = ? WHERE remote_id = ?')
          .run(event.payload.position, event.payload.id)
        break
        
      // Card events
      case 'CARD_CREATED':
      case 'CARD_UPDATED':
        this.upsertCard(event.payload)
        break
        
      case 'CARD_DELETED':
        this.db.prepare('DELETE FROM board_cards WHERE remote_id = ?').run(event.payload.id)
        break
        
      case 'CARD_MOVED':
        // Update card position and list
        const newList = this.db.prepare('SELECT id FROM board_lists WHERE remote_id = ?').get(event.payload.list_id) as { id: number } | undefined
        if (newList) {
          this.db.prepare('UPDATE board_cards SET list_id = ?, position = ?, local_updated_at = datetime("now") WHERE remote_id = ?')
            .run(newList.id, event.payload.position, event.payload.id || event.payload.card_id)
        }
        break
        
      case 'CARD_ARCHIVED':
        this.db.prepare('UPDATE board_cards SET is_archived = 1 WHERE remote_id = ?')
          .run(event.payload.id)
        break
        
      // Checklist events - handle both naming conventions (PHP sends CHECKLIST_UPDATED, eventTypes.js defines CARD_CHECKLIST_UPDATED)
      case 'CARD_CHECKLIST_UPDATED':
      case 'CHECKLIST_UPDATED':
        // For CHECKLIST_UPDATED, we just need to refresh the card data
        // The payload contains: card_id, item_id, action, completed
        if (event.payload.card_id) {
          // Emit update event so UI can refresh - the full checklist data will be fetched via API
          console.log('[BoardsSync] Checklist updated for card:', event.payload.card_id)
        }
        break
    }
    
    // Emit event for UI update
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL ACTIONS
  // ============================================

  /**
   * Create board (local-first)
   */
  async createBoard(boardData: CreateBoardData): Promise<number> {
    return await this.performAction(
      () => {
        const result = this.db.prepare(`
          INSERT INTO boards (name, description, color, background_type, background_value, sync_status, created_at)
          VALUES (?, ?, ?, ?, ?, 'pending', datetime('now'))
        `).run(
          boardData.name,
          boardData.description || null,
          boardData.color || null,
          boardData.background_type || 'color',
          boardData.background_value || null
        )
        return result.lastInsertRowid as number
      },
      async () => {
        // Handled by queue
      },
      { action: 'create_board', entityId: null, payload: boardData }
    )
  }

  /**
   * Create list (local-first)
   */
  async createList(boardId: number, boardRemoteId: number, name: string, position: number): Promise<number> {
    return await this.performAction(
      () => {
        const result = this.db.prepare(`
          INSERT INTO board_lists (board_id, name, position, sync_status)
          VALUES (?, ?, ?, 'pending')
        `).run(boardId, name, position)
        return result.lastInsertRowid as number
      },
      async () => {},
      { action: 'create_list', entityId: null, payload: { board_id: boardRemoteId, name, position } }
    )
  }

  /**
   * Create card (local-first)
   */
  async createCard(listId: number, listRemoteId: number, title: string, position: number): Promise<number> {
    return await this.performAction(
      () => {
        const result = this.db.prepare(`
          INSERT INTO board_cards (list_id, title, position, sync_status, created_at)
          VALUES (?, ?, ?, 'pending', datetime('now'))
        `).run(listId, title, position)
        return result.lastInsertRowid as number
      },
      async () => {},
      { action: 'create_card', entityId: null, payload: { list_id: listRemoteId, title, position } }
    )
  }

  /**
   * Move card (local-first) - critical for drag-and-drop
   */
  async moveCard(cardId: number, cardRemoteId: number, toListId: number, toListRemoteId: number, position: number): Promise<void> {
    await this.performAction(
      () => {
        this.db.prepare(`
          UPDATE board_cards SET list_id = ?, position = ?, local_updated_at = datetime('now')
          WHERE id = ?
        `).run(toListId, position, cardId)
      },
      async () => {
        await this.api.post(`/boards/cards/${cardRemoteId}/move`, {
          list_id: toListRemoteId,
          position
        })
      },
      { action: 'move_card', entityId: cardId, payload: { remote_id: cardRemoteId, list_id: toListRemoteId, position } }
    )
  }

  /**
   * Update card (local-first)
   */
  async updateCard(cardId: number, cardRemoteId: number, updates: Partial<CreateCardData>): Promise<void> {
    await this.performAction(
      () => {
        const setClauses: string[] = []
        const values: any[] = []
        
        Object.entries(updates).forEach(([key, value]) => {
          if (key === 'assignees' || key === 'labels') {
            setClauses.push(`${key} = ?`)
            values.push(JSON.stringify(value))
          } else {
            setClauses.push(`${key} = ?`)
            values.push(value)
          }
        })
        
        setClauses.push('local_updated_at = datetime("now")')
        values.push(cardId)
        
        this.db.prepare(`
          UPDATE board_cards SET ${setClauses.join(', ')} WHERE id = ?
        `).run(...values)
      },
      async () => {
        await this.api.put(`/boards/cards/${cardRemoteId}`, updates)
      },
      { action: 'update_card', entityId: cardId, payload: { remote_id: cardRemoteId, ...updates } }
    )
  }

  /**
   * Get boards
   */
  getBoards(): Board[] {
    return this.db.getBoards()
  }

  /**
   * Get board with lists and cards
   */
  getBoardWithContents(boardId: number): { board: Board; lists: BoardList[]; cards: BoardCard[] } | null {
    return this.db.getBoardWithLists(boardId)
  }
}

interface CreateBoardData {
  name: string
  description?: string
  color?: string
  background_type?: string
  background_value?: string
  client_id?: number
}

interface CreateCardData {
  title: string
  description?: string
  due_date?: string
  assignees?: string[]
  labels?: number[]
  cover_color?: string
}

