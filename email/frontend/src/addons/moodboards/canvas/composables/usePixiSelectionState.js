import { computed } from 'vue'
import { computeSelectionBounds, computePerItemBounds } from '../utils/selectionBounds.js'
import { CONTAINER_TYPES } from '../utils/containerTypes.js'

/**
 * Selection-derived state for the Pixi canvas overlays (selection chrome,
 * action bar, rotation handle). Pure computeds over the moodboards store.
 */
export function usePixiSelectionState(store) {
  const selectedBounds = computed(() =>
    computeSelectionBounds(store.selectedItemIds, store.currentBoard?.items || [], CONTAINER_TYPES)
  )

  const perItemBounds = computed(() =>
    store.selectedItemIds.size > 1
      ? computePerItemBounds(store.selectedItemIds, store.currentBoard?.items || [])
      : []
  )

  const editingGroupBounds = computed(() => {
    if (!store.editingGroupId) return null
    const eid = store.editingGroupId
    const items = store.currentBoard?.items || []
    const groupItem = items.find(i => i.id === eid)
    if (groupItem && CONTAINER_TYPES.has(groupItem.type)) {
      return computeSelectionBounds(new Set([eid]), items, CONTAINER_TYPES)
    }
    const memberIds = new Set(
      items.filter(i => i.style_data?.group_id === eid).map(i => i.id)
    )
    if (memberIds.size) return computeSelectionBounds(memberIds, items, CONTAINER_TYPES)
    return null
  })

  const singleSelectedItem = computed(() => {
    if (store.selectedItemIds.size !== 1) return null
    const id = [...store.selectedItemIds][0]
    return (store.currentBoard?.items || []).find(i => i.id === id) || null
  })

  const singleSelectedItemScale = computed(() => {
    const item = singleSelectedItem.value
    return item?.style_data?.item_scale ?? 1
  })

  const selectedItemsRotation = computed(() => {
    const sel = store.selectedItemIds
    if (!sel.size) return 0
    const items = store.currentBoard?.items || []
    let commonRotation = null
    for (const item of items) {
      if (!sel.has(item.id)) continue
      const rot = item.rotation || 0
      if (commonRotation === null) {
        commonRotation = rot
      } else if (Math.abs(commonRotation - rot) > 0.1) {
        return 0
      }
    }
    return commonRotation || 0
  })

  const selectedItemLocked = computed(() => {
    if (store.selectedItemIds.size !== 1) return false
    const items = store.currentBoard?.items || []
    const id = [...store.selectedItemIds][0]
    const item = items.find(i => i.id === id)
    return item?.locked ? true : false
  })

  return {
    selectedBounds, perItemBounds, editingGroupBounds,
    singleSelectedItem, singleSelectedItemScale,
    selectedItemsRotation, selectedItemLocked,
  }
}
