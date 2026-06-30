/**
 * Shared composable for chat presence status display.
 * Single source of truth for online/offline indicator colors and labels.
 * Used by FloatingChatWidget, ChatSidebar, and ChatConversation.
 */
import { useColleaguesStore } from '@/addons/team/stores/colleagues'

export function useChatPresence() {
  const colleaguesStore = useColleaguesStore()

  /**
   * Get the CSS class for a presence status dot.
   * @param {object|string} participantOrStatus - Participant object (with .email) or status string
   * @returns {string} Tailwind CSS class for the dot color
   */
  function getStatusColor(participantOrStatus) {
    const status = resolveStatus(participantOrStatus)
    switch (status) {
      case 'active': return 'bg-green-500'
      case 'away': return 'bg-amber-500'
      case 'do_not_disturb': return 'bg-red-500'
      case 'dnd': return 'bg-red-500'
      case 'vacation': return 'bg-blue-500'
      default: return 'bg-surface-400'
    }
  }

  /**
   * Get human-readable status label.
   * @param {object|string} participantOrStatus - Participant object (with .email) or status string
   * @returns {string} Status text
   */
  function getStatusText(participantOrStatus) {
    const status = resolveStatus(participantOrStatus)
    switch (status) {
      case 'active': return 'Online'
      case 'away': return 'Away'
      case 'do_not_disturb': return 'Do not disturb'
      case 'dnd': return 'Do not disturb'
      case 'vacation': return 'On vacation'
      default: return 'Offline'
    }
  }

  /**
   * Get custom status text for a participant (e.g. "In a meeting", "Lunch break")
   * @param {object} participantOrStatus - Participant object (with .email)
   * @returns {string|null} Custom status text or null
   */
  function getCustomStatusText(participantOrStatus) {
    if (typeof participantOrStatus !== 'object' || !participantOrStatus?.email) return null
    const colleague = colleaguesStore.colleagueByEmail?.[participantOrStatus.email.toLowerCase()]
    return colleague?.status_text || participantOrStatus?.status_text || null
  }

  /**
   * Get status label for status picker options.
   * @param {string} status - Status string
   * @returns {string} Label text
   */
  function getStatusLabel(status) {
    switch (status) {
      case 'active': return 'Active'
      case 'away': return 'Away'
      case 'do_not_disturb': return 'Do Not Disturb'
      case 'dnd': return 'Do Not Disturb'
      case 'vacation': return 'On Vacation'
      default: return 'Auto'
    }
  }

  /**
   * Resolve the real-time status from a participant object or status string.
   * Always uses colleaguesStore.getColleagueStatus() for real-time presence.
   */
  function resolveStatus(participantOrStatus) {
    if (typeof participantOrStatus === 'object' && participantOrStatus?.email) {
      return colleaguesStore.getColleagueStatus(participantOrStatus.email)
    }
    // If it's a known status string, use it directly
    if (typeof participantOrStatus === 'string') {
      return participantOrStatus
    }
    return 'offline'
  }

  return {
    getStatusColor,
    getStatusText,
    getStatusLabel,
    getCustomStatusText,
    resolveStatus,
  }
}

