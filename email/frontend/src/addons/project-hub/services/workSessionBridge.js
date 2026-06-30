import { on } from '@/services/addonEventBus'
import { useAddons } from '@/composables/useAddons'
import api from '@/services/api'

let registered = false

function isNumericId(val) {
  return val && !isNaN(Number(val)) && Number(val) > 0
}

export function registerWorkSessionBridge() {
  if (registered) return
  registered = true

  on('time:synced', async (entry) => {
    const { projectHubEnabled } = useAddons()
    if (!projectHubEnabled.value) return

    try {
      // NOTE: board_task entries are deliberately NOT forwarded. Card-open time
      // already reaches projecthub_work_sessions as a card_view session via
      // useCardTimer; forwarding board_task too would double-count it in PH.
      if (
        (entry.activityType === 'document_edit' || entry.activityType === 'document_open') &&
        entry.entityId &&
        isNumericId(entry.entityId)
      ) {
        await api.post('/project-hub/work-sessions/drive-bridge', {
          drive_file_id: Number(entry.entityId),
          duration_seconds: entry.seconds,
          file_name: entry.entityName,
        })
      } else if (entry.activityType === 'website_work' && entry.cardId) {
        await api.post('/project-hub/work-sessions', {
          card_id: entry.cardId,
          source: 'website_work',
          duration_seconds: entry.seconds,
          entity_name: entry.entityName,
        })
      }
    } catch (err) {
      console.warn('[WorkSessionBridge] bridge error:', err)
    }
  })
}
