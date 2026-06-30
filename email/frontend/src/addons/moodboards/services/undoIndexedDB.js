import { deepClone } from '../utils/deepClone.js'

const DB_NAME = 'flowone_mood_undo'
const DB_VERSION = 1
const STORE_NAME = 'undo_stacks'
const MAX_AGE_MS = 24 * 60 * 60 * 1000

let _dbPromise = null

function openDB() {
  if (_dbPromise) return _dbPromise
  _dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION)
    req.onupgradeneeded = () => {
      const db = req.result
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'boardId' })
      }
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => {
      _dbPromise = null
      reject(req.error)
    }
  })
  return _dbPromise
}

export async function saveUndoStack(boardId, undoStack, redoStack) {
  try {
    const db = await openDB()
    const tx = db.transaction(STORE_NAME, 'readwrite')
    tx.objectStore(STORE_NAME).put({
      boardId,
      undoStack: deepClone(undoStack),
      redoStack: deepClone(redoStack),
      savedAt: Date.now()
    })
    await new Promise((resolve, reject) => {
      tx.oncomplete = resolve
      tx.onerror = () => reject(tx.error)
    })
  } catch (e) {
    console.warn('[UndoIDB] save failed:', e)
  }
}

export async function loadUndoStack(boardId) {
  try {
    const db = await openDB()
    const tx = db.transaction(STORE_NAME, 'readonly')
    const req = tx.objectStore(STORE_NAME).get(boardId)
    const result = await new Promise((resolve, reject) => {
      req.onsuccess = () => resolve(req.result)
      req.onerror = () => reject(req.error)
    })
    if (!result) return null
    if (Date.now() - result.savedAt > MAX_AGE_MS) {
      await clearUndoStack(boardId)
      return null
    }
    return { undoStack: result.undoStack || [], redoStack: result.redoStack || [] }
  } catch (e) {
    console.warn('[UndoIDB] load failed:', e)
    return null
  }
}

export async function clearUndoStack(boardId) {
  try {
    const db = await openDB()
    const tx = db.transaction(STORE_NAME, 'readwrite')
    tx.objectStore(STORE_NAME).delete(boardId)
  } catch (e) {
    console.warn('[UndoIDB] clear failed:', e)
  }
}
