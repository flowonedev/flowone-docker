import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Todo Sync Engine
 * 
 * Handles synchronization of todo/task data:
 * - Todo items (title, description, priority, due date, completion)
 * - Reordering and position management
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all todos from /todos
 * - Incremental: WebSocket events for todo changes
 * - Offline: Queue create/update/delete/toggle for later sync
 * 
 * Note: The /todos endpoint is gated by the Tasks addon on the backend.
 * If the addon is disabled, the pull will get a 404 which is handled gracefully.
 */
export class TodoSyncEngine extends BaseSyncEngine {
  entityType = 'todo'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[TodoSync] Pulling changes...')
    await this.syncTodos()
  }

  /**
   * Sync all todos from server
   */
  private async syncTodos(): Promise<void> {
    try {
      const response = await this.api.get('/todos')
      
      if (response.data.success && response.data.data) {
        const todos = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.todos || []
        
        for (const todo of todos) {
          this.upsertTodo(todo)
        }
        
        console.log(`[TodoSync] Synced ${todos.length} todos`)
      }
    } catch (error: any) {
      // 404 means the Tasks addon is disabled on the server - not a real error
      if (error.response?.status === 404) {
        console.log('[TodoSync] Tasks addon not enabled on server, skipping')
        return
      }
      console.error('[TodoSync] Failed to sync todos:', error.message)
      throw error
    }
  }

  /**
   * Insert or update a todo
   */
  private upsertTodo(todo: any): void {
    this.db.prepare(`
      INSERT INTO todos (
        remote_id, title, description, is_completed, due_date,
        priority, position, calendar_event_id, email_uid, email_folder,
        sync_status, created_at, completed_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        title = excluded.title,
        description = excluded.description,
        is_completed = excluded.is_completed,
        due_date = excluded.due_date,
        priority = excluded.priority,
        position = excluded.position,
        calendar_event_id = excluded.calendar_event_id,
        completed_at = excluded.completed_at,
        sync_status = 'synced'
    `).run(
      todo.id,
      todo.title || '',
      todo.description || null,
      todo.is_completed ? 1 : 0,
      todo.due_date || null,
      todo.priority || 0,
      todo.position || 0,
      todo.calendar_event_id || null,
      todo.email_uid || null,
      todo.email_folder || null,
      todo.created_at || new Date().toISOString(),
      todo.completed_at || null
    )
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'create': {
        const res = await this.api.post('/todos', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare(`
            UPDATE todos SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      
      case 'create_from_email': {
        const res = await this.api.post('/todos/from-email', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare(`
            UPDATE todos SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
        
      case 'update':
        if (payload.remote_id) {
          await this.api.put(`/todos/${payload.remote_id}`, payload)
        }
        break
        
      case 'toggle':
        if (payload.remote_id) {
          await this.api.post(`/todos/${payload.remote_id}/toggle`)
        }
        break
        
      case 'delete':
        if (payload.remote_id) {
          await this.api.delete(`/todos/${payload.remote_id}`)
        }
        break
        
      case 'reorder':
        await this.api.post('/todos/reorder', payload)
        break
        
      default:
        console.warn(`[TodoSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[TodoSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'TODO_CREATED':
      case 'TODO_UPDATED':
        if (event.payload) {
          this.upsertTodo(event.payload)
        }
        break
        
      case 'TODO_TOGGLED':
        if (event.payload?.id) {
          this.db.prepare(`
            UPDATE todos 
            SET is_completed = ?, completed_at = ?
            WHERE remote_id = ?
          `).run(
            event.payload.is_completed ? 1 : 0,
            event.payload.completed_at || null,
            event.payload.id
          )
        }
        break
        
      case 'TODO_DELETED':
        if (event.payload?.id) {
          this.db.prepare('DELETE FROM todos WHERE remote_id = ?').run(event.payload.id)
        }
        break
        
      case 'TODO_REORDERED':
        if (event.payload?.order && Array.isArray(event.payload.order)) {
          for (const item of event.payload.order) {
            this.db.prepare(
              'UPDATE todos SET position = ? WHERE remote_id = ?'
            ).run(item.position, item.id)
          }
        }
        break
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getTodos(): any[] {
    return this.db.all('SELECT * FROM todos ORDER BY position ASC, created_at DESC')
  }

  getActiveTodos(): any[] {
    return this.db.all(
      'SELECT * FROM todos WHERE is_completed = 0 ORDER BY position ASC, created_at DESC'
    )
  }

  getCompletedTodos(): any[] {
    return this.db.all(
      'SELECT * FROM todos WHERE is_completed = 1 ORDER BY completed_at DESC'
    )
  }

  getTodoById(remoteId: number): any | null {
    return this.db.get('SELECT * FROM todos WHERE remote_id = ?', [remoteId]) || null
  }
}

