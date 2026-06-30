/**
 * Handles image upload, file upload, image-set creation, sketch import,
 * and related toolbar-driven file workflows.
 */
export function useCanvasUpload(store, { containerRef, screenToCanvasFn, beforeUploadHook }) {
  function getCenterCanvas() {
    const rect = containerRef.value?.getBoundingClientRect()
    if (!rect) return { x: 0, y: 0 }
    return screenToCanvasFn(rect.width / 2, rect.height / 2, store.panX, store.panY, store.zoom)
  }

  async function handleImageUpload(files, pos, asSet = false) {
    if (!files?.length || !store.currentBoard?.id) return
    const hook = beforeUploadHook?.value

    if (asSet && files.length > 1) {
      for (const f of files) { if (hook) await hook(f) }
      try {
        const uploaded = await store.uploadFiles(Array.from(files))
        const imageSetItems = uploaded.map((u, i) => ({
          image_url: u.url,
          thumbnail_url: u.thumbnail_url || u.url,
          position: i,
        }))
        if (imageSetItems.length) {
          store.addItem({
            type: 'image_set',
            pos_x: Math.round(pos.x),
            pos_y: Math.round(pos.y),
            width: 320,
            height: 240,
            title: `Image Set (${files.length})`,
            image_set_items: imageSetItems,
          })
        }
      } catch (e) { console.error('Image set upload failed:', e) }
      return
    }

    let offsetX = 0
    for (const file of files) {
      if (hook) await hook(file)
      try {
        const uploaded = await store.uploadFiles([file])
        const u = uploaded?.[0]
        if (u) {
          const imageUrl = u.url || u.image_url
          const w = Number(u.width_px ?? u.width ?? 0)
          const h = Number(u.height_px ?? u.height ?? 0)
          const hasDims = w > 0 && h > 0
          const displayWidth = hasDims ? Math.min(w, 400) : 300
          const displayHeight = hasDims ? Math.round(displayWidth * (h / w)) : 200
          store.addItem({
            type: 'image',
            pos_x: Math.round(pos.x + offsetX),
            pos_y: Math.round(pos.y),
            width: displayWidth,
            height: displayHeight,
            image_url: imageUrl,
            thumbnail_url: u.thumbnail_url || imageUrl,
            title: file.name,
            style_data: hasDims ? { original_width: w, original_height: h } : undefined,
          })
          offsetX += displayWidth + 20
        }
      } catch (e) { console.error('Image upload failed:', e) }
    }
  }

  async function handleFileUpload(file, type, pos) {
    if (!file || !store.currentBoard?.id) return
    const hook = beforeUploadHook?.value
    if (hook) await hook(file)
    try {
      const uploaded = await store.uploadFiles([file])
      const u = uploaded?.[0]
      if (!u) return
      const isVideo = type === 'video' || file.type?.startsWith('video/')
      const isAudio = type === 'audio' || file.type?.startsWith('audio/')
      const itemData = {
        type: isVideo ? 'video' : isAudio ? 'audio' : 'file',
        pos_x: Math.round(pos.x),
        pos_y: Math.round(pos.y),
        width: isVideo ? 480 : isAudio ? 280 : 220,
        height: isVideo ? 270 : isAudio ? 100 : 180,
        title: file.name,
        url: u.url,
        image_url: u.url,
      }
      if (isAudio) {
        itemData.style_data = {
          audio_volume: 80, audio_loop: false, audio_autoplay: false,
          audio_accent: '#6366f1', audio_bg: '#1e1b2e', audio_text: '#e2e8f0',
        }
      } else if (!isVideo) {
        itemData.style_data = {
          mime_type: file.type || null,
          file_size: file.size || null,
        }
      }
      store.addItem(itemData)
    } catch (e) { console.error('File upload failed:', e) }
  }

  async function onReplaceImage(item) {
    if (item.locked) return
    const input = document.createElement('input')
    input.type = 'file'
    input.multiple = false
    input.accept = 'image/*'
    input.onchange = async (e) => {
      const files = Array.from(e.target.files)
      if (!files.length) return
      try {
        const uploaded = await store.uploadFiles(files)
        if (uploaded?.[0]) {
          const u = uploaded[0]
          await store.updateItem(item.id, {
            image_url: u.url || u.image_url || null,
            thumbnail_url: u.thumbnail_url || u.url || u.image_url || null,
            title: item.title || files[0].name,
          })
        }
      } catch (err) {
        console.error('Failed to replace image:', err)
      }
    }
    input.click()
  }

  async function onAddImagesToSet(item) {
    if (item.locked) return
    const input = document.createElement('input')
    input.type = 'file'
    input.multiple = true
    input.accept = 'image/*'
    input.onchange = async (e) => {
      const files = Array.from(e.target.files)
      if (!files.length) return
      try {
        const uploaded = await store.uploadFiles(files)
        // ONE HTTP call regardless of how many files were selected.
        const payload = uploaded.map(u => ({
          image_url: u.url,
          thumbnail_url: u.thumbnail_url || u.url,
          original_filename: u.original_filename || null,
          width_px: u.width_px || null,
          height_px: u.height_px || null,
        }))
        if (store.addImagesToSetBatch) {
          await store.addImagesToSetBatch(item.id, payload)
        } else if (store.addImageToSet) {
          for (const p of payload) await store.addImageToSet(item.id, p)
        }
        await store.fetchBoard?.(store.currentBoard?.id)
      } catch (err) {
        console.error('Failed to add images to set:', err)
      }
    }
    input.click()
  }

  function handleFileUploadFromToolbar(files) {
    if (!files?.length) return
    getCenterCanvas()
  }

  return {
    handleImageUpload,
    handleFileUpload,
    handleFileUploadFromToolbar,
    onReplaceImage,
    onAddImagesToSet,
    getCenterCanvas,
  }
}
