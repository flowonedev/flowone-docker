import { ref, computed } from 'vue'

const CACHE_NAME = 'moodboard-offline-v1'
const META_KEY = 'moodboard_offline_cache_meta'

function readMeta() {
  try {
    return JSON.parse(localStorage.getItem(META_KEY) || '{}')
  } catch { return {} }
}

function writeMeta(meta) {
  localStorage.setItem(META_KEY, JSON.stringify(meta))
}

export function useImagePreloader() {
  const preloading = ref(false)
  const preloadTotal = ref(0)
  const preloadLoaded = ref(0)
  const preloadProgress = computed(() =>
    preloadTotal.value > 0
      ? Math.round((preloadLoaded.value / preloadTotal.value) * 100)
      : 0
  )

  const cacheStatus = ref('none')
  const cachedImageCount = ref(0)

  function collectBoardImageUrls(board) {
    const urls = new Set()
    const items = board?.items || []

    for (const item of items) {
      const thumb = item.thumbnail_url
      const full = item.image_url
      if (thumb) urls.add(thumb)
      else if (full) urls.add(full)

      if (item.style_data?.mask_image_url) urls.add(item.style_data.mask_image_url)

      if (item.type === 'image_set' && Array.isArray(item.children)) {
        for (const child of item.children) {
          if (child.thumbnail_url) urls.add(child.thumbnail_url)
          else if (child.image_url) urls.add(child.image_url)
        }
      }

      if (item.type === 'frame' && Array.isArray(item.children)) {
        for (const child of item.children) {
          if (child.image_url) urls.add(child.image_url)
        }
      }
    }

    if (board?.background_image) {
      urls.add(board.background_image)
    }

    return [...urls].filter(u => u && typeof u === 'string')
  }

  async function preloadImages(board, { timeout = 15000, batchSize = 6 } = {}) {
    const imageUrls = collectBoardImageUrls(board)
    if (imageUrls.length === 0) return

    preloading.value = true
    preloadTotal.value = imageUrls.length
    preloadLoaded.value = 0

    for (let i = 0; i < imageUrls.length; i += batchSize) {
      const batch = imageUrls.slice(i, i + batchSize)
      await Promise.allSettled(
        batch.map(url => {
          return new Promise((resolve) => {
            const img = new Image()
            const timer = setTimeout(() => { preloadLoaded.value++; resolve() }, timeout)
            img.onload = () => { clearTimeout(timer); preloadLoaded.value++; resolve() }
            img.onerror = () => { clearTimeout(timer); preloadLoaded.value++; resolve() }
            img.src = url
          })
        })
      )
    }

    preloading.value = false
  }

  async function registerCacheServiceWorker() {
    if (!('serviceWorker' in navigator) || !('caches' in window)) return false
    try {
      const reg = await navigator.serviceWorker.register('/mood-cache-sw.js', { scope: '/' })
      await reg.update()
      return true
    } catch (e) {
      console.warn('Mood cache SW registration failed:', e)
      return false
    }
  }

  async function cleanupLegacyMoodCacheServiceWorker() {
    if (!('serviceWorker' in navigator)) return false
    try {
      const registrations = await navigator.serviceWorker.getRegistrations()
      let removed = false
      for (const registration of registrations) {
        const scriptUrl =
          registration.active?.scriptURL ||
          registration.waiting?.scriptURL ||
          registration.installing?.scriptURL ||
          ''
        if (scriptUrl.includes('/mood-cache-sw.js')) {
          await registration.unregister()
          removed = true
        }
      }
      return removed
    } catch (e) {
      console.warn('Mood cache SW cleanup failed:', e)
      return false
    }
  }

  async function saveBoardForOffline(board) {
    if (!board?.id) return
    if (!('caches' in window)) return

    const imageUrls = collectBoardImageUrls(board)
    if (imageUrls.length === 0) return

    await registerCacheServiceWorker()

    preloading.value = true
    preloadTotal.value = imageUrls.length
    preloadLoaded.value = 0
    cacheStatus.value = 'saving'

    const cache = await caches.open(CACHE_NAME)
    const BATCH_SIZE = 4
    const TIMEOUT_MS = 20000
    let savedCount = 0

    for (let i = 0; i < imageUrls.length; i += BATCH_SIZE) {
      const batch = imageUrls.slice(i, i + BATCH_SIZE)
      await Promise.allSettled(
        batch.map(async (url) => {
          try {
            const existing = await cache.match(url)
            if (existing) {
              savedCount++
              preloadLoaded.value++
              return
            }

            const controller = new AbortController()
            const timer = setTimeout(() => controller.abort(), TIMEOUT_MS)

            const response = await fetch(url, {
              signal: controller.signal,
              mode: 'cors',
              credentials: 'omit',
            })
            clearTimeout(timer)

            if (response.ok) {
              await cache.put(url, response)
              savedCount++
            }
          } catch { /* timeout or network error */ }
          preloadLoaded.value++
        })
      )
    }

    const meta = readMeta()
    meta[board.id] = {
      savedAt: Date.now(),
      imageCount: savedCount,
      totalImages: imageUrls.length,
      boardName: board.name || 'Untitled',
      itemHash: computeBoardHash(board),
    }
    writeMeta(meta)

    cachedImageCount.value = savedCount
    cacheStatus.value = 'saved'
    preloading.value = false
  }

  function computeBoardHash(board) {
    const items = board?.items || []
    const parts = items.map(i => `${i.id}:${i.updated_at || i.type}`).sort()
    if (board?.background_image) parts.push('bg:' + board.background_image)
    let hash = 0
    const str = parts.join('|')
    for (let i = 0; i < str.length; i++) {
      hash = ((hash << 5) - hash + str.charCodeAt(i)) | 0
    }
    return hash
  }

  function checkBoardCacheStatus(board) {
    if (!board?.id) {
      cacheStatus.value = 'none'
      cachedImageCount.value = 0
      return
    }

    const meta = readMeta()
    const entry = meta[board.id]

    if (!entry) {
      cacheStatus.value = 'none'
      cachedImageCount.value = 0
      return
    }

    const currentHash = computeBoardHash(board)
    if (currentHash !== entry.itemHash) {
      cacheStatus.value = 'stale'
      cachedImageCount.value = entry.imageCount || 0
    } else {
      cacheStatus.value = 'saved'
      cachedImageCount.value = entry.imageCount || 0
    }
  }

  function getCacheMeta(boardId) {
    const meta = readMeta()
    return meta[boardId] || null
  }

  async function clearBoardCache(boardId) {
    if (!('caches' in window)) return
    await caches.delete(CACHE_NAME)
    const meta = readMeta()
    if (boardId) {
      delete meta[boardId]
    }
    writeMeta(meta)
    cacheStatus.value = 'none'
    cachedImageCount.value = 0
  }

  function markBoardCacheStale(boardId) {
    if (!boardId) return
    const meta = readMeta()
    if (meta[boardId]) {
      meta[boardId].itemHash = null
      writeMeta(meta)
      cacheStatus.value = 'stale'
    }
  }

  return {
    preloading,
    preloadTotal,
    preloadLoaded,
    preloadProgress,
    cacheStatus,
    cachedImageCount,
    collectBoardImageUrls,
    preloadImages,
    saveBoardForOffline,
    checkBoardCacheStatus,
    getCacheMeta,
    clearBoardCache,
    markBoardCacheStale,
    registerCacheServiceWorker,
    cleanupLegacyMoodCacheServiceWorker,
  }
}
