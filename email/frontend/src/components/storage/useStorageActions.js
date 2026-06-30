import { ref } from 'vue'
import api from '@/services/api'

/**
 * Tiny client for the storage admin POST endpoints. Wraps the api
 * calls so the components don't need to know paths or response shape.
 *
 * Every action returns { ok, message, error } so the caller can show
 * a toast in one branch.
 *
 * The endpoints map:
 *   reclaim_pause   -> POST /admin/storage/reclaim/pause
 *   reclaim_resume  -> POST /admin/storage/reclaim/resume
 *   reclaim_cycle   -> POST /admin/storage/reclaim/cycle
 *   backup_pause    -> POST /admin/storage/backup/pause
 *   backup_resume   -> POST /admin/storage/backup/resume
 *   backup_snapshot -> POST /admin/storage/backup/snapshot
 *   backup_verify   -> POST /admin/storage/backup/verify
 *   backup_drill    -> POST /admin/storage/backup/drill
 *   freeze          -> POST /admin/storage/freeze
 *   unfreeze        -> POST /admin/storage/unfreeze
 */

const ENDPOINT_MAP = {
  reclaim_pause:   '/admin/storage/reclaim/pause',
  reclaim_resume:  '/admin/storage/reclaim/resume',
  reclaim_cycle:   '/admin/storage/reclaim/cycle',
  backup_pause:    '/admin/storage/backup/pause',
  backup_resume:   '/admin/storage/backup/resume',
  backup_snapshot: '/admin/storage/backup/snapshot',
  backup_verify:   '/admin/storage/backup/verify',
  backup_drill:    '/admin/storage/backup/drill',
  freeze:          '/admin/storage/freeze',
  unfreeze:        '/admin/storage/unfreeze',
}

export function useStorageActions() {
  const busy = ref(false)
  const lastResult = ref(null)

  async function runAction(actionKey, body = {}) {
    const path = ENDPOINT_MAP[actionKey]
    if (!path) {
      return { ok: false, error: `unknown action ${actionKey}` }
    }
    busy.value = true
    try {
      const res = await api.post(path, body)
      const data = res?.data?.data ?? res?.data ?? {}
      const result = {
        ok: true,
        action: actionKey,
        message: data.message || 'OK',
        path: data.path || null,
        id: data.id || null,
        raw: data,
      }
      lastResult.value = result
      return result
    } catch (e) {
      const result = {
        ok: false,
        action: actionKey,
        error: e?.response?.data?.error || e?.message || 'request failed',
        status: e?.response?.status,
        raw: e?.response?.data,
      }
      lastResult.value = result
      return result
    } finally {
      busy.value = false
    }
  }

  return { runAction, busy, lastResult }
}
