/**
 * Operation Queue Service
 * 
 * Manages optimistic updates with automatic rollback on failure.
 * Ensures fast UI response while maintaining data consistency.
 */

import { ref, computed, readonly } from 'vue'

// Operation states
export const OperationState = {
  PENDING: 'pending',
  IN_PROGRESS: 'in_progress',
  COMPLETED: 'completed',
  FAILED: 'failed',
  ROLLED_BACK: 'rolled_back',
}

// Operation types
export const OperationType = {
  SET_FLAG: 'SET_FLAG',
  MOVE_MESSAGE: 'MOVE_MESSAGE',
  DELETE_MESSAGE: 'DELETE_MESSAGE',
  CREATE_FOLDER: 'CREATE_FOLDER',
  DELETE_FOLDER: 'DELETE_FOLDER',
  RENAME_FOLDER: 'RENAME_FOLDER',
}

class Operation {
  constructor(type, payload, optimisticUpdate, rollback) {
    this.id = crypto.randomUUID()
    this.type = type
    this.payload = payload
    this.optimisticUpdate = optimisticUpdate
    this.rollback = rollback
    this.state = OperationState.PENDING
    this.createdAt = Date.now()
    this.error = null
  }
}

class OperationQueue {
  constructor() {
    // All operations (for tracking)
    this.operations = ref(new Map())
    
    // Operations in progress (for UI indication)
    this.inProgressCount = ref(0)
    
    // Failed operations (for retry/review)
    this.failedOperations = ref([])
    
    // Max operations to keep in history
    this.maxHistory = 100
    
    // Timeout for operations (ms)
    this.operationTimeout = 30000
  }

  /**
   * Execute an operation with optimistic update and automatic rollback
   * 
   * @param {string} type - Operation type from OperationType
   * @param {object} payload - Operation payload data
   * @param {function} optimisticUpdate - Function to apply optimistic update (returns state to restore)
   * @param {function} apiCall - Async function that performs the actual API call
   * @param {function} rollback - Function to rollback the optimistic update (receives saved state)
   * @returns {Promise<{success: boolean, data?: any, error?: string}>}
   */
  async execute(type, payload, optimisticUpdate, apiCall, rollback) {
    const operation = new Operation(type, payload, optimisticUpdate, rollback)
    this.operations.value.set(operation.id, operation)
    
    // Step 1: Save current state and apply optimistic update
    let savedState = null
    try {
      savedState = optimisticUpdate()
      operation.state = OperationState.IN_PROGRESS
      this.inProgressCount.value++
    } catch (error) {
      console.error('[OperationQueue] Optimistic update failed:', error)
      operation.state = OperationState.FAILED
      operation.error = 'Failed to apply optimistic update'
      return { success: false, error: operation.error }
    }

    // Step 2: Execute the API call
    try {
      const result = await Promise.race([
        apiCall(),
        new Promise((_, reject) => 
          setTimeout(() => reject(new Error('Operation timed out')), this.operationTimeout)
        )
      ])
      
      // Success
      operation.state = OperationState.COMPLETED
      this.inProgressCount.value--
      this.cleanupOldOperations()
      
      return { success: true, data: result }
      
    } catch (error) {
      console.error('[OperationQueue] API call failed, rolling back:', error)
      
      // Step 3: Rollback on failure
      try {
        rollback(savedState)
        operation.state = OperationState.ROLLED_BACK
      } catch (rollbackError) {
        console.error('[OperationQueue] Rollback failed:', rollbackError)
        operation.state = OperationState.FAILED
      }
      
      operation.error = error.message || 'Operation failed'
      this.inProgressCount.value--
      this.failedOperations.value.push(operation)
      
      // Limit failed operations history
      if (this.failedOperations.value.length > 50) {
        this.failedOperations.value.shift()
      }
      
      return { success: false, error: operation.error }
    }
  }

  /**
   * Helper for setFlag operation
   */
  async setFlag(message, flag, value, updateFn, apiCall) {
    const oldValue = message[flag]
    
    return this.execute(
      OperationType.SET_FLAG,
      { uid: message.uid, flag, value },
      
      // Optimistic update
      () => {
        const saved = { [flag]: oldValue }
        updateFn(message, flag, value)
        return saved
      },
      
      // API call
      apiCall,
      
      // Rollback
      (saved) => {
        updateFn(message, flag, saved[flag])
      }
    )
  }

  /**
   * Helper for moveMessage operation
   */
  async moveMessage(message, messages, sourceFolder, targetFolder, updateFn, apiCall) {
    const messageIndex = messages.findIndex(m => m.uid === message.uid)
    const messageCopy = { ...message }
    
    return this.execute(
      OperationType.MOVE_MESSAGE,
      { uid: message.uid, sourceFolder, targetFolder },
      
      // Optimistic update
      () => {
        const saved = { index: messageIndex, message: messageCopy }
        updateFn('remove', message.uid)
        return saved
      },
      
      // API call
      apiCall,
      
      // Rollback
      (saved) => {
        updateFn('restore', saved.message, saved.index)
      }
    )
  }

  /**
   * Helper for deleteMessage operation
   */
  async deleteMessage(message, messages, updateFn, apiCall) {
    const messageIndex = messages.findIndex(m => m.uid === message.uid)
    const messageCopy = { ...message }
    
    return this.execute(
      OperationType.DELETE_MESSAGE,
      { uid: message.uid },
      
      // Optimistic update
      () => {
        const saved = { index: messageIndex, message: messageCopy }
        updateFn('remove', message.uid)
        return saved
      },
      
      // API call
      apiCall,
      
      // Rollback
      (saved) => {
        updateFn('restore', saved.message, saved.index)
      }
    )
  }

  /**
   * Get operation by ID
   */
  getOperation(id) {
    return this.operations.value.get(id)
  }

  /**
   * Get all pending/in-progress operations for a specific entity
   */
  getActiveOperationsFor(entityType, entityId) {
    const active = []
    for (const [id, op] of this.operations.value) {
      if (op.state === OperationState.PENDING || op.state === OperationState.IN_PROGRESS) {
        if (op.payload[entityType] === entityId) {
          active.push(op)
        }
      }
    }
    return active
  }

  /**
   * Check if there are any operations in progress
   */
  hasInProgress() {
    return this.inProgressCount.value > 0
  }

  /**
   * Get count of failed operations
   */
  getFailedCount() {
    return this.failedOperations.value.length
  }

  /**
   * Clear failed operations
   */
  clearFailed() {
    this.failedOperations.value = []
  }

  /**
   * Clean up old completed operations
   */
  cleanupOldOperations() {
    const now = Date.now()
    const maxAge = 5 * 60 * 1000 // 5 minutes
    
    for (const [id, op] of this.operations.value) {
      if (op.state === OperationState.COMPLETED && now - op.createdAt > maxAge) {
        this.operations.value.delete(id)
      }
    }
    
    // Limit total operations
    if (this.operations.value.size > this.maxHistory) {
      const toRemove = this.operations.value.size - this.maxHistory
      let removed = 0
      for (const [id, op] of this.operations.value) {
        if (op.state === OperationState.COMPLETED) {
          this.operations.value.delete(id)
          removed++
          if (removed >= toRemove) break
        }
      }
    }
  }

  /**
   * Get statistics
   */
  getStats() {
    let pending = 0
    let inProgress = 0
    let completed = 0
    let failed = 0
    let rolledBack = 0
    
    for (const op of this.operations.value.values()) {
      switch (op.state) {
        case OperationState.PENDING: pending++; break
        case OperationState.IN_PROGRESS: inProgress++; break
        case OperationState.COMPLETED: completed++; break
        case OperationState.FAILED: failed++; break
        case OperationState.ROLLED_BACK: rolledBack++; break
      }
    }
    
    return { pending, inProgress, completed, failed, rolledBack, total: this.operations.value.size }
  }
}

// Singleton instance
let instance = null

/**
 * Get the OperationQueue singleton instance
 */
export function useOperationQueue() {
  if (!instance) {
    instance = new OperationQueue()
  }
  return instance
}

/**
 * Vue composable for operation queue
 */
export function useOperations() {
  const queue = useOperationQueue()
  
  return {
    // State
    inProgressCount: readonly(queue.inProgressCount),
    failedOperations: readonly(queue.failedOperations),
    hasInProgress: computed(() => queue.hasInProgress()),
    failedCount: computed(() => queue.getFailedCount()),
    
    // Methods
    execute: queue.execute.bind(queue),
    setFlag: queue.setFlag.bind(queue),
    moveMessage: queue.moveMessage.bind(queue),
    deleteMessage: queue.deleteMessage.bind(queue),
    getStats: queue.getStats.bind(queue),
    clearFailed: queue.clearFailed.bind(queue),
  }
}

