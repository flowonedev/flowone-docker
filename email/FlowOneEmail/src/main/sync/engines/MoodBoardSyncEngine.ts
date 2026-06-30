import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * MoodBoard Sync Engine
 * 
 * Handles synchronization of MoodBoard data:
 * - Mood boards (canvases)
 * - Items (notes, images, text, links, frames, etc.)
 * - Connections (arrows between items)
 * - Todos within todo-list items
 * - Members and sharing
 * - Image sets, uploads, components, palettes
 * 
 * Sync Strategy:
 * - Full sync on startup + periodic refresh
 * - WebSocket events for real-time collaboration (item moved, etc.)
 * - Offline support: All CRUD queued
 */
export class MoodBoardSyncEngine extends BaseSyncEngine {
  entityType = 'mood_board'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull all mood board data from server
   */
  async pullChanges(): Promise<void> {
    console.log('[MoodBoardSync] Pulling changes...')

    // 1. Sync board list
    await this.syncBoards()

    // 2. Sync each board's contents
    const boards = this.db.prepare(
      'SELECT * FROM mood_boards WHERE archived = 0'
    ).all() as any[]

    for (const board of boards) {
      if (board.remote_id) {
        await this.syncBoardContents(board.remote_id)
      }
    }

    // 3. Sync user palettes and components
    await this.syncPalettes()
    await this.syncComponents()
  }

  // ============================================
  // PULL METHODS
  // ============================================

  private async syncBoards(): Promise<void> {
    try {
      const response = await this.api.get('/mood-boards')
      if (response.data.success && response.data.data) {
        const boards = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.boards || []

        for (const board of boards) {
          this.upsertBoard(board)
        }
        console.log(`[MoodBoardSync] Synced ${boards.length} boards`)
      }
    } catch (error: any) {
      // 404 means the Moodboards addon is disabled on the server - not a real error
      if (error.response?.status === 404) {
        console.log('[MoodBoardSync] Moodboards addon not enabled on server, skipping')
        return
      }
      console.error('[MoodBoardSync] Failed to sync boards:', error.message)
      throw error
    }
  }

  private async syncBoardContents(boardRemoteId: number): Promise<void> {
    try {
      const response = await this.api.get(`/mood-boards/${boardRemoteId}`)
      if (response.data.success && response.data.data) {
        const boardData = response.data.data.board || response.data.data

        const localBoard = this.db.prepare(
          'SELECT id FROM mood_boards WHERE remote_id = ?'
        ).get(boardRemoteId) as { id: number } | undefined
        if (!localBoard) return

        // Sync items
        const items = boardData.items || []
        for (const item of items) {
          this.upsertItem(localBoard.id, item)

          // Sync nested todos for todo_list items
          if (item.type === 'todo_list' && item.todos) {
            for (const todo of item.todos) {
              this.upsertTodo(item.id, todo)
            }
          }

          // Sync image set items
          if (item.type === 'image_set' && item.images) {
            for (const img of item.images) {
              this.upsertImageSetItem(item.id, img)
            }
          }
        }

        // Sync connections
        const connections = boardData.connections || []
        for (const conn of connections) {
          this.upsertConnection(localBoard.id, conn)
        }

        // Sync members
        const members = boardData.members || []
        for (const member of members) {
          this.upsertMember(localBoard.id, member)
        }

        console.log(`[MoodBoardSync] Synced contents for board ${boardRemoteId}: ${items.length} items, ${connections.length} connections`)
      }
    } catch (error: any) {
      console.error(`[MoodBoardSync] Failed to sync board ${boardRemoteId}:`, error.message)
    }
  }

  private async syncPalettes(): Promise<void> {
    try {
      const response = await this.api.get('/mood-boards/palettes')
      if (response.data.success && response.data.data) {
        const palettes = Array.isArray(response.data.data) ? response.data.data : response.data.data.palettes || []
        for (const palette of palettes) {
          this.upsertPalette(palette)
        }
        console.log(`[MoodBoardSync] Synced ${palettes.length} palettes`)
      }
    } catch (error: any) {
      console.warn('[MoodBoardSync] Failed to sync palettes:', error.message)
    }
  }

  private async syncComponents(): Promise<void> {
    try {
      const response = await this.api.get('/mood-boards/components')
      if (response.data.success && response.data.data) {
        const components = Array.isArray(response.data.data) ? response.data.data : response.data.data.components || []
        for (const comp of components) {
          this.upsertComponent(comp)
        }
        console.log(`[MoodBoardSync] Synced ${components.length} components`)
      }
    } catch (error: any) {
      console.warn('[MoodBoardSync] Failed to sync components:', error.message)
    }
  }

  // ============================================
  // UPSERT METHODS
  // ============================================

  private upsertBoard(board: any): void {
    this.db.prepare(`
      INSERT INTO mood_boards (
        remote_id, owner_email, client_id, name, description,
        background_color, background_image, canvas_width, canvas_height,
        zoom_level, viewport_x, viewport_y, is_template, archived,
        share_token, share_mode, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name,
        description = excluded.description,
        background_color = excluded.background_color,
        background_image = excluded.background_image,
        canvas_width = excluded.canvas_width,
        canvas_height = excluded.canvas_height,
        is_template = excluded.is_template,
        archived = excluded.archived,
        share_token = excluded.share_token,
        share_mode = excluded.share_mode,
        sync_status = 'synced',
        updated_at = excluded.updated_at
    `).run(
      board.id, board.owner_email, board.client_id || null,
      board.name, board.description || null,
      board.background_color || '#f5f5f5', board.background_image || null,
      board.canvas_width || 4000, board.canvas_height || 3000,
      board.zoom_level ?? 1, board.viewport_x || 0, board.viewport_y || 0,
      board.is_template ? 1 : 0, board.archived ? 1 : 0,
      board.share_token || null, board.share_mode || 'off',
      board.created_at || null, board.updated_at || null
    )
  }

  private upsertItem(localBoardId: number, item: any): void {
    this.db.prepare(`
      INSERT INTO mood_board_items (
        remote_id, board_id, parent_id, type, pos_x, pos_y, width, height,
        rotation, z_index, locked, title, content, color, color_data, url,
        drive_file_id, image_url, thumbnail_url, linked_board_id, linked_card_id,
        calendar_event_id, style_data, created_by, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        parent_id = excluded.parent_id,
        pos_x = excluded.pos_x,
        pos_y = excluded.pos_y,
        width = excluded.width,
        height = excluded.height,
        rotation = excluded.rotation,
        z_index = excluded.z_index,
        locked = excluded.locked,
        title = excluded.title,
        content = excluded.content,
        color = excluded.color,
        color_data = excluded.color_data,
        url = excluded.url,
        image_url = excluded.image_url,
        style_data = excluded.style_data,
        sync_status = 'synced',
        updated_at = excluded.updated_at
    `).run(
      item.id, localBoardId, item.parent_id || null, item.type,
      item.pos_x || 0, item.pos_y || 0, item.width || 240, item.height || null,
      item.rotation || 0, item.z_index || 0, item.locked ? 1 : 0,
      item.title || null, item.content || null, item.color || null,
      item.color_data ? JSON.stringify(item.color_data) : null,
      item.url || null, item.drive_file_id || null,
      item.image_url || null, item.thumbnail_url || null,
      item.linked_board_id || null, item.linked_card_id || null,
      item.calendar_event_id || null,
      item.style_data ? JSON.stringify(item.style_data) : null,
      item.created_by || null, item.created_at || null, item.updated_at || null
    )
  }

  private upsertTodo(itemRemoteId: number, todo: any): void {
    const localItem = this.db.prepare(
      'SELECT id FROM mood_board_items WHERE remote_id = ?'
    ).get(itemRemoteId) as { id: number } | undefined
    if (!localItem) return

    this.db.prepare(`
      INSERT INTO mood_board_todos (remote_id, item_id, text, completed, position)
      VALUES (?, ?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        text = excluded.text, completed = excluded.completed, position = excluded.position
    `).run(todo.id, localItem.id, todo.text, todo.completed ? 1 : 0, todo.position || 0)
  }

  private upsertImageSetItem(itemRemoteId: number, img: any): void {
    const localItem = this.db.prepare(
      'SELECT id FROM mood_board_items WHERE remote_id = ?'
    ).get(itemRemoteId) as { id: number } | undefined
    if (!localItem) return

    this.db.prepare(`
      INSERT INTO mood_board_image_set_items (
        remote_id, item_id, image_url, thumbnail_url, drive_file_id,
        original_filename, file_size, width_px, height_px, position
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        image_url = excluded.image_url, position = excluded.position
    `).run(
      img.id, localItem.id, img.image_url, img.thumbnail_url || null,
      img.drive_file_id || null, img.original_filename || null,
      img.file_size || null, img.width_px || null, img.height_px || null,
      img.position || 0
    )
  }

  private upsertConnection(localBoardId: number, conn: any): void {
    const fromLocal = this.db.prepare('SELECT id FROM mood_board_items WHERE remote_id = ?')
      .get(conn.from_item_id) as { id: number } | undefined
    const toLocal = this.db.prepare('SELECT id FROM mood_board_items WHERE remote_id = ?')
      .get(conn.to_item_id) as { id: number } | undefined

    if (!fromLocal || !toLocal) {
      console.warn(`[MoodBoardSync] Skipping connection ${conn.id}: items not synced yet (from=${conn.from_item_id}, to=${conn.to_item_id})`)
      return
    }

    this.db.prepare(`
      INSERT INTO mood_board_connections (
        remote_id, board_id, from_item_id, to_item_id, line_style,
        line_color, line_width, arrow_start, arrow_end, label
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        from_item_id = excluded.from_item_id,
        to_item_id = excluded.to_item_id,
        line_style = excluded.line_style,
        line_color = excluded.line_color,
        line_width = excluded.line_width,
        arrow_start = excluded.arrow_start,
        arrow_end = excluded.arrow_end,
        label = excluded.label
    `).run(
      conn.id, localBoardId, fromLocal.id, toLocal.id,
      conn.line_style || 'solid', conn.line_color || '#666666',
      conn.line_width || 2, conn.arrow_start ? 1 : 0,
      conn.arrow_end !== undefined ? (conn.arrow_end ? 1 : 0) : 1,
      conn.label || null
    )
  }

  private upsertMember(localBoardId: number, member: any): void {
    this.db.prepare(`
      INSERT INTO mood_board_members (remote_id, board_id, email, role, invited_by, added_at)
      VALUES (?, ?, ?, ?, ?, ?)
      ON CONFLICT(board_id, email) DO UPDATE SET
        role = excluded.role, invited_by = excluded.invited_by
    `).run(
      member.id || null, localBoardId, member.email,
      member.role || 'editor', member.invited_by || null,
      member.added_at || null
    )
  }

  private upsertPalette(palette: any): void {
    this.db.prepare(`
      INSERT INTO mood_board_user_palettes (
        remote_id, email, name, colors, gradients, is_shared, sync_status
      ) VALUES (?, ?, ?, ?, ?, ?, 'synced')
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name, colors = excluded.colors,
        gradients = excluded.gradients, is_shared = excluded.is_shared,
        sync_status = 'synced'
    `).run(
      palette.id, palette.email, palette.name || 'Untitled Palette',
      JSON.stringify(palette.colors || []),
      JSON.stringify(palette.gradients || []),
      palette.is_shared ? 1 : 0
    )
  }

  private upsertComponent(comp: any): void {
    this.db.prepare(`
      INSERT INTO mood_board_components (
        remote_id, owner_email, name, description, thumbnail_url,
        items_data, is_global, category, sync_status
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced')
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name, description = excluded.description,
        items_data = excluded.items_data, is_global = excluded.is_global,
        category = excluded.category, sync_status = 'synced'
    `).run(
      comp.id, comp.owner_email, comp.name, comp.description || null,
      comp.thumbnail_url || null, JSON.stringify(comp.items_data),
      comp.is_global ? 1 : 0, comp.category || 'custom'
    )
  }

  // ============================================
  // PUSH CHANGES
  // ============================================

  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)

    switch (queueItem.action) {
      case 'create_board': {
        const res = await this.api.post('/mood-boards', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE mood_boards SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'update_board':
        await this.api.put(`/mood-boards/${payload.remote_id}`, payload)
        break
      case 'delete_board':
        await this.api.delete(`/mood-boards/${payload.remote_id}`)
        break

      case 'create_item': {
        const res = await this.api.post(`/mood-boards/${payload.board_id}/items`, payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE mood_board_items SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'update_item':
        await this.api.put(`/mood-boards/items/${payload.remote_id}`, payload)
        break
      case 'delete_item':
        await this.api.delete(`/mood-boards/items/${payload.remote_id}`)
        break
      case 'move_item':
        await this.api.put(`/mood-boards/items/${payload.remote_id}`, {
          pos_x: payload.pos_x,
          pos_y: payload.pos_y,
          width: payload.width,
          height: payload.height,
        })
        break

      case 'create_connection': {
        const res = await this.api.post(`/mood-boards/${payload.board_id}/connections`, payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE mood_board_connections SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'delete_connection':
        await this.api.delete(`/mood-boards/connections/${payload.remote_id}`)
        break

      default:
        console.warn(`[MoodBoardSync] Unknown action: ${queueItem.action}`)
    }
  }

  // ============================================
  // WEBSOCKET EVENT HANDLER
  // ============================================

  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[MoodBoardSync] Handling event: ${event.type}`)

    switch (event.type) {
      case 'MOOD_BOARD_CREATED':
      case 'MOOD_BOARD_UPDATED': {
        const boardData = event.payload.board || event.payload
        this.upsertBoard(boardData)
        break
      }
      case 'MOOD_BOARD_DELETED':
        this.db.prepare('DELETE FROM mood_boards WHERE remote_id = ?').run(event.payload.board_id || event.payload.id)
        break

      case 'MOOD_BOARD_ITEM_CREATED':
      case 'MOOD_BOARD_ITEM_UPDATED': {
        const itemData = event.payload.item || event.payload
        const board = this.db.prepare('SELECT id FROM mood_boards WHERE remote_id = ?')
          .get(itemData.board_id || event.payload.board_id) as { id: number } | undefined
        if (board) this.upsertItem(board.id, itemData)
        break
      }
      case 'MOOD_BOARD_ITEMS_CREATED': {
        const items = event.payload.items || []
        for (const item of items) {
          const board = this.db.prepare('SELECT id FROM mood_boards WHERE remote_id = ?')
            .get(item.board_id || event.payload.board_id) as { id: number } | undefined
          if (board) this.upsertItem(board.id, item)
        }
        break
      }
      case 'MOOD_BOARD_ITEM_DELETED':
        this.db.prepare('DELETE FROM mood_board_items WHERE remote_id = ?').run(event.payload.item_id || event.payload.id)
        break
      case 'MOOD_BOARD_ITEMS_DELETED': {
        const ids = event.payload.item_ids || []
        const del = this.db.prepare('DELETE FROM mood_board_items WHERE remote_id = ?')
        for (const id of ids) del.run(id)
        break
      }
      case 'MOOD_BOARD_ITEMS_MOVED': {
        const updates = event.payload.updates || []
        const upd = this.db.prepare(`
          UPDATE mood_board_items SET pos_x = ?, pos_y = ?, width = ?, height = ?
          WHERE remote_id = ?
        `)
        for (const u of updates) {
          upd.run(u.pos_x, u.pos_y, u.width || null, u.height || null, u.id)
        }
        break
      }

      case 'MOOD_BOARD_CONNECTION_CREATED':
      case 'MOOD_BOARD_CONNECTION_UPDATED': {
        const connBoard = this.db.prepare('SELECT id FROM mood_boards WHERE remote_id = ?')
          .get(event.payload.board_id || event.payload.connection?.board_id) as { id: number } | undefined
        if (connBoard) this.upsertConnection(connBoard.id, event.payload.connection || event.payload)
        break
      }
      case 'MOOD_BOARD_CONNECTION_DELETED':
        this.db.prepare('DELETE FROM mood_board_connections WHERE remote_id = ?').run(event.payload.connection_id || event.payload.id)
        break

      case 'MOOD_BOARD_FULL_REFRESH':
        console.log(`[MoodBoardSync] Full refresh requested for board ${event.payload.board_id}, will re-sync on next cycle`)
        break
    }

    this.emit('data-updated', { type: event.type, payload: event.payload })
  }
}

