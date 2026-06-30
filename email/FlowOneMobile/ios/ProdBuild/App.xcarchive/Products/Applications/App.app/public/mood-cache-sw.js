const CACHE_NAME = 'moodboard-offline-v1'

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)

  const isImage = event.request.destination === 'image' ||
    /\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)(\?.*)?$/i.test(url.pathname)

  if (!isImage) return

  event.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(event.request)
      if (cached) return cached

      try {
        const response = await fetch(event.request)
        if (response.ok) {
          cache.put(event.request, response.clone())
        }
        return response
      } catch (err) {
        return new Response('', { status: 503, statusText: 'Offline' })
      }
    })
  )
})

self.addEventListener('message', async (event) => {
  if (event.data?.type === 'CLEAR_BOARD_CACHE') {
    const boardId = event.data.boardId
    const cache = await caches.open(CACHE_NAME)
    const keys = await cache.keys()
    let deleted = 0
    for (const request of keys) {
      await cache.delete(request)
      deleted++
    }
    event.source?.postMessage({ type: 'CACHE_CLEARED', boardId, deleted })
  }
})
