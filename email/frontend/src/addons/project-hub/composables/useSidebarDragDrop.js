import { ref } from 'vue'

export function useSidebarDragDrop(hubStore, api) {
  const dragState = ref({ type: null, id: null, spaceId: null })
  const dropTarget = ref({ type: null, id: null, position: null })

  function onDragStart(e, type, id, spaceId = null) {
    dragState.value = { type, id, spaceId }
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('text/plain', `${type}:${id}`)
    e.target.classList.add('opacity-50')
  }

  function onDragEnd(e) {
    e.target.classList.remove('opacity-50')
    dragState.value = { type: null, id: null, spaceId: null }
    dropTarget.value = { type: null, id: null, position: null }
  }

  function onDragOver(e, type, id, position = 'after') {
    if (dragState.value.type !== type) return
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    dropTarget.value = { type, id, position }
  }

  function onDragLeave() {
    dropTarget.value = { type: null, id: null, position: null }
  }

  async function onDropSpace(e, targetId) {
    e.preventDefault()
    if (dragState.value.type !== 'space' || dragState.value.id === targetId) return
    const spaces = hubStore.spacesWithFolders
    const fromIdx = spaces.findIndex(s => s.id === dragState.value.id)
    const toIdx = spaces.findIndex(s => s.id === targetId)
    if (fromIdx < 0 || toIdx < 0) return
    const reordered = [...spaces]
    const [moved] = reordered.splice(fromIdx, 1)
    reordered.splice(toIdx, 0, moved)
    try {
      await hubStore.reorderSpaces(reordered.map(s => s.id))
    } catch (err) {
      console.error('Failed to reorder spaces:', err)
    }
    dropTarget.value = { type: null, id: null, position: null }
  }

  async function onDropFolder(e, targetId, spaceId) {
    e.preventDefault()
    if (dragState.value.type !== 'folder') return
    const space = hubStore.spacesWithFolders.find(s => s.id === spaceId)
    if (!space) return
    const folders = space.folders || []
    const fromIdx = folders.findIndex(f => f.id === dragState.value.id)
    const toIdx = folders.findIndex(f => f.id === targetId)
    if (fromIdx < 0 || toIdx < 0) return
    const reordered = [...folders]
    const [moved] = reordered.splice(fromIdx, 1)
    reordered.splice(toIdx, 0, moved)
    try {
      await api.post('/project-hub/folders/reorder', { ids: reordered.map(f => f.id) })
      await hubStore.fetchHierarchy()
    } catch (err) {
      console.error('Failed to reorder folders:', err)
    }
    dropTarget.value = { type: null, id: null, position: null }
  }

  function isDropTarget(type, id) {
    return dropTarget.value.type === type && dropTarget.value.id === id
  }

  return {
    dragState, dropTarget,
    onDragStart, onDragEnd, onDragOver, onDragLeave,
    onDropSpace, onDropFolder, isDropTarget,
  }
}
