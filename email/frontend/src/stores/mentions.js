import { defineStore } from 'pinia'
import api from '@/services/api'
import { normalizeEmail } from '@/utils/emailNormalizer'

/**
 * Mentions store
 * ──────────────
 * Two concerns:
 *
 *   1. Suggestions (used by the TipTap mention extension popup)
 *      - small in-process cache keyed by the lowercased query — typing
 *        `@ro`, `@rob`, `@robe` shouldn't issue three round-trips when the
 *        results are nearly identical.
 *      - 60s TTL; the contacts list moves slowly enough.
 *
 *   2. Per-message mentions: rendered as chips/badges inside the email
 *      view. Cached per message_id for the session.
 */

const SUGGEST_TTL_MS  = 60_000

export const useMentionsStore = defineStore('mentions', () => {
  // ── Suggest cache ────────────────────────────────────────────────────
  const suggestCache = new Map() // q -> { ts, items }

  async function suggest(query, { limit = 8, signal } = {}) {
    const q = String(query || '').trim().toLowerCase()
    if (!q) return []

    const cached = suggestCache.get(q)
    if (cached && Date.now() - cached.ts < SUGGEST_TTL_MS) {
      return cached.items
    }

    try {
      const res = await api.get('/mentions/suggest', {
        params: { q, limit },
        signal,
      })
      const items = res?.data?.success ? (res.data.data.suggestions || []) : []
      // Defensive normalisation in case any server-side path slips a non-canonical
      // address through (e.g. legacy contact rows). The mention extension uses
      // this address as the data-id attribute, which is what the backend parser
      // looks for — they MUST match the canonical form or the round-trip breaks.
      const normalised = items.map((it) => ({
        ...it,
        email: normalizeEmail(it.email) || it.email,
      }))
      suggestCache.set(q, { ts: Date.now(), items: normalised })
      // Cap cache so a long session doesn't bloat memory.
      if (suggestCache.size > 50) {
        const oldestKey = suggestCache.keys().next().value
        suggestCache.delete(oldestKey)
      }
      return normalised
    } catch (e) {
      if (e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED') return []
      console.error('[mentions] suggest failed', e?.response?.data || e.message)
      return []
    }
  }

  // ── Per-message mention chips ────────────────────────────────────────
  const byMessage = new Map() // message_id -> array

  async function fetchMentionsFor(messageId) {
    if (!messageId) return []
    if (byMessage.has(messageId)) return byMessage.get(messageId)
    try {
      const res = await api.get('/mentions/for-message', { params: { message_id: messageId } })
      const list = res?.data?.success ? (res.data.data.mentions || []) : []
      byMessage.set(messageId, list)
      return list
    } catch (e) {
      console.error('[mentions] for-message failed', e?.response?.data || e.message)
      return []
    }
  }

  function invalidate() {
    suggestCache.clear()
    byMessage.clear()
  }

  return {
    // actions
    suggest,
    fetchMentionsFor,
    invalidate,
  }
})
