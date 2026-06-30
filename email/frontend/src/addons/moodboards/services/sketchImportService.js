/**
 * Orchestrates .sketch file import: parsing, optimistic item creation,
 * chunked background persistence to the server.
 */
export function importSketchFile(store, { sketchImporting, sketchSaveProgress }) {
  return async function doImport(file) {
    if (sketchImporting.value) return
    sketchImporting.value = true
    sketchSaveProgress.value = 0

    try {
      const { parseSketchFile, sketchToMoodItems, getSketchPageNames } = await import('../utils/sketchParser.js')
      sketchSaveProgress.value = 5

      const parsed = await parseSketchFile(file)
      const pageNames = getSketchPageNames(parsed)
      if (!pageNames.length) { sketchImporting.value = false; return }

      async function uploadImageFn(blob, sha) {
        const ext = (sha.split('.').pop() || 'png').toLowerCase()
        const imageFile = new File([blob], `sketch-${sha}`, { type: `image/${ext}` })
        const uploaded = await store.uploadFiles([imageFile])
        return uploaded?.[0]?.url || null
      }

      const items = await sketchToMoodItems(parsed, {
        startX: 100,
        startY: 100,
        uploadImageFn,
        pageIndex: 'all',
        maxItems: Infinity,
        maxDepth: 50,
        minSize: 1,
        onProgress: (p) => { sketchSaveProgress.value = 15 + Math.round(p * 65) },
      })

      const validItems = items.filter(Boolean)
      if (!validItems.length) { sketchImporting.value = false; return }

      sketchSaveProgress.value = 80

      const boardId = store.currentBoard.id
      const tempIdToIndex = new Map()
      const parentPairs = []
      const cleanItems = validItems.map((item, i) => {
        const { _tempId, _tempParentId, ...clean } = item
        if (_tempId) tempIdToIndex.set(_tempId, i)
        if (_tempParentId) parentPairs.push({ itemIndex: i, parentTempId: _tempParentId })
        return clean
      })

      let tempCounter = -Date.now()
      const tempIds = []
      const optimisticItems = cleanItems.map((data) => {
        const tempId = --tempCounter
        tempIds.push(tempId)
        return {
          id: tempId, board_id: boardId,
          type: data.type || 'text',
          pos_x: data.pos_x || 0, pos_y: data.pos_y || 0,
          width: data.width || null, height: data.height || null,
          rotation: data.rotation || 0, z_index: data.z_index || 0,
          title: data.title || null, content: data.content || null,
          color: data.color || null, url: data.url || null,
          image_url: data.image_url || null,
          style_data: data.style_data || {},
          locked: 0, parent_id: null, _tempId: tempId,
        }
      })

      for (const { itemIndex, parentTempId } of parentPairs) {
        const parentIndex = tempIdToIndex.get(parentTempId)
        if (parentIndex != null) optimisticItems[itemIndex].parent_id = tempIds[parentIndex]
      }

      store.currentBoard.items = [...(store.currentBoard.items || []), ...optimisticItems]

      sketchSaveProgress.value = 100
      sketchImporting.value = false

      _persistSketchImportBg(store, boardId, cleanItems, optimisticItems, tempIds, parentPairs, tempIdToIndex)
    } catch (err) {
      console.error('Sketch import error:', err)
      sketchImporting.value = false
    }
  }
}

async function _persistSketchImportBg(store, boardId, cleanItems, optimisticItems, tempIds, parentPairs, tempIdToIndex) {
  const api = (await import('@/services/api')).default
  const CHUNK = 500
  const PARALLEL = 3
  const total = cleanItems.length
  const allRealIds = new Array(total).fill(null)

  try {
    for (let batchStart = 0; batchStart < total; batchStart += CHUNK * PARALLEL) {
      const promises = []
      for (let p = 0; p < PARALLEL; p++) {
        const start = batchStart + p * CHUNK
        if (start >= total) break
        const end = Math.min(start + CHUNK, total)
        const chunk = cleanItems.slice(start, end)
        promises.push(
          api.post(`/mood-boards/${boardId}/items/batch-add`, { items: chunk })
            .then(res => {
              if (res.data.success && res.data.data?.items) {
                const serverItems = res.data.data.items
                for (let j = 0; j < serverItems.length; j++) allRealIds[start + j] = serverItems[j].id
              }
            })
            .catch(e => console.error(`[SketchImport] Chunk ${start} failed:`, e))
        )
      }
      await Promise.all(promises)
    }

    const tempToItem = new Map()
    for (const it of store.currentBoard.items) {
      if (it._tempId != null) tempToItem.set(it._tempId, it)
    }
    for (let i = 0; i < optimisticItems.length; i++) {
      const realId = allRealIds[i]
      if (!realId) continue
      const localItem = tempToItem.get(tempIds[i])
      if (localItem) {
        localItem.id = realId
        delete localItem._tempId
      }
    }

    for (const { itemIndex, parentTempId } of parentPairs) {
      const parentIndex = tempIdToIndex.get(parentTempId)
      if (parentIndex == null) continue
      const parentRealId = allRealIds[parentIndex]
      const childRealId = allRealIds[itemIndex]
      if (parentRealId && childRealId) {
        api.put(`/mood-boards/${boardId}/items/${childRealId}`, { parent_id: parentRealId }).catch(() => {})
      }
    }

    store.currentBoard.items = [...store.currentBoard.items]
  } catch (e) {
    console.error('[SketchImport] Background persistence failed:', e)
  }
}
