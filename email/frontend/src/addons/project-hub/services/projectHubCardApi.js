import api from '@/services/api'

/**
 * PH-aware card mutation helpers.
 * Routes card updates and comments through /project-hub/cards/{id} proxy
 * endpoints so the backend can fire PH notifications and calendar sync.
 * Returns the same shape as boardsStore methods for drop-in compatibility.
 */

export async function phUpdateCard(cardId, payload) {
  try {
    const response = await api.put(`/project-hub/cards/${cardId}`, payload)
    if (response.data.success) {
      return response.data.data.card
    }
  } catch (e) {
    console.error('[PH] updateCard error:', e)
  }
  return null
}

export async function phAddComment(cardId, content) {
  try {
    const response = await api.post(`/project-hub/cards/${cardId}/comments`, { content })
    const comment = response.data?.data?.comment || response.data
    if (comment?.id) return comment
  } catch (e) {
    console.error('[PH] addComment error:', e)
  }
  return null
}
