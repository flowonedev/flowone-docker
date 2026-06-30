<template>
  <div class="flex flex-col h-full">
    <div class="flex items-center justify-between px-3 py-2 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-1.5">
        <span class="material-symbols-rounded text-sm text-surface-400">image</span>
        <span class="text-xs font-semibold text-surface-700 dark:text-surface-200">Assets</span>
        <span class="text-[10px] text-surface-400 tabular-nums">({{ assets.length }})</span>
      </div>
      <div class="flex items-center gap-0.5">
        <button
          @click="viewMode = 'grid'"
          class="p-1 rounded-md transition-colors"
          :class="viewMode === 'grid' ? 'bg-primary-500/15 text-primary-500' : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300'"
        >
          <span class="material-symbols-rounded text-base">grid_view</span>
        </button>
        <button
          @click="viewMode = 'list'"
          class="p-1 rounded-md transition-colors"
          :class="viewMode === 'list' ? 'bg-primary-500/15 text-primary-500' : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300'"
        >
          <span class="material-symbols-rounded text-base">view_list</span>
        </button>
      </div>
    </div>

    <div class="px-3 py-2 flex-shrink-0">
      <div class="relative">
        <span class="material-symbols-rounded absolute left-2 top-1/2 -translate-y-1/2 text-sm text-surface-400">search</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search assets..."
          class="w-full pl-7 pr-3 py-1.5 text-xs rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-800 dark:text-surface-200 focus:ring-1 focus:ring-primary-500/40 outline-none"
        />
      </div>
    </div>

    <div class="flex items-center gap-1 px-3 pb-2 flex-shrink-0">
      <button
        v-for="f in filters"
        :key="f.key"
        class="px-2 py-0.5 text-[10px] rounded-full transition-colors"
        :class="activeFilter === f.key
          ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
          : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'"
        @click="activeFilter = f.key"
      >{{ f.label }}</button>
    </div>

    <div v-if="filtered.length === 0" class="flex-1 flex flex-col items-center justify-center text-center px-6">
      <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-2">image_not_supported</span>
      <p class="text-xs text-surface-400">No assets found</p>
    </div>

    <!-- Grid view -->
    <div v-else-if="viewMode === 'grid'" class="flex-1 overflow-y-auto custom-scrollbar p-3 pt-0">
      <div class="grid grid-cols-2 gap-2">
        <div
          v-for="item in filtered"
          :key="item.id"
          class="group relative rounded-lg overflow-hidden border border-surface-200 dark:border-surface-700 hover:border-primary-400 dark:hover:border-primary-500 transition-colors cursor-pointer bg-checkerboard"
          @click="$emit('fly-to-item', item.id)"
          @dblclick.stop="getImageSrc(item) && triggerReplace(item)"
        >
          <img
            v-if="getImageSrc(item)"
            :src="getImageSrc(item)"
            :alt="item.title || item.content || 'Asset'"
            class="w-full aspect-square object-contain"
            loading="eager"
          />
          <div v-else class="w-full aspect-square flex items-center justify-center bg-surface-100 dark:bg-surface-700">
            <span class="material-symbols-rounded text-2xl text-surface-300 dark:text-surface-500">{{ item.type === 'video' || item.type === 'youtube' ? 'movie' : 'description' }}</span>
          </div>
          <!-- Loading overlay -->
          <div v-if="replacingId === item.id" class="absolute inset-0 bg-black/60 flex items-center justify-center z-10">
            <span class="material-symbols-rounded text-white text-xl animate-spin">progress_activity</span>
          </div>
          <!-- Hover overlay with actions -->
          <div v-else class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5 z-10">
            <button
              @click.stop="$emit('fly-to-item', item.id)"
              class="w-7 h-7 rounded-full bg-white/20 hover:bg-white/40 backdrop-blur-sm flex items-center justify-center transition-colors"
              title="Go to item"
            >
              <span class="material-symbols-rounded text-white text-sm">near_me</span>
            </button>
            <button
              v-if="getImageSrc(item)"
              @click.stop="triggerReplace(item)"
              class="w-7 h-7 rounded-full bg-white/20 hover:bg-white/40 backdrop-blur-sm flex items-center justify-center transition-colors"
              title="Replace image"
            >
              <span class="material-symbols-rounded text-white text-sm">swap_horiz</span>
            </button>
          </div>
          <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent px-1.5 pb-1 pt-3 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
            <p class="text-[9px] text-white truncate">{{ getLabel(item) }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- List view -->
    <div v-else class="flex-1 overflow-y-auto custom-scrollbar">
      <div
        v-for="item in filtered"
        :key="item.id"
        class="group flex items-center gap-2.5 px-3 py-1.5 hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer transition-colors border-b border-surface-100 dark:border-surface-800"
        @click="$emit('fly-to-item', item.id)"
      >
        <div class="w-9 h-9 rounded-md overflow-hidden flex-shrink-0 border border-surface-200 dark:border-surface-700 bg-checkerboard">
          <img
            v-if="getImageSrc(item)"
            :src="getImageSrc(item)"
            class="w-full h-full object-cover"
            loading="eager"
          />
          <div v-else class="w-full h-full flex items-center justify-center bg-surface-100 dark:bg-surface-700">
            <span class="material-symbols-rounded text-sm text-surface-400">{{ item.type === 'video' || item.type === 'youtube' ? 'movie' : 'description' }}</span>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-[11px] font-medium text-surface-700 dark:text-surface-300 truncate">{{ getLabel(item) }}</p>
          <p class="text-[9px] text-surface-400">{{ getDims(item) }}</p>
        </div>
        <div class="flex items-center gap-0.5 flex-shrink-0">
          <button
            v-if="getImageSrc(item)"
            @click.stop="triggerReplace(item)"
            class="p-1 rounded-md text-surface-400 opacity-0 group-hover:opacity-100 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-600 transition-all"
            title="Replace image"
          >
            <span class="material-symbols-rounded text-sm">swap_horiz</span>
          </button>
          <span class="material-symbols-rounded text-sm text-surface-300 dark:text-surface-600 group-hover:text-primary-500">near_me</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useMoodBoardsStore } from '../stores/moodBoards'

const emit = defineEmits(['fly-to-item', 'replace-image'])
const store = useMoodBoardsStore()
const replacingId = ref(null)

const search = ref('')
const viewMode = ref('grid')
const activeFilter = ref('all')

const VIDEO_TYPES = new Set(['video', 'youtube'])
const FILE_TYPES = new Set(['file'])

const filters = [
  { key: 'all', label: 'All' },
  { key: 'images', label: 'Images' },
  { key: 'videos', label: 'Videos' },
  { key: 'files', label: 'Files' },
]

function hasAnyImage(item) {
  if (item.image_url || item.thumbnail_url) return true
  const sd = item.style_data
  if (sd?.mask_image_url || sd?.text_clip_image) return true
  return false
}

function getImageSrc(item) {
  if (item.thumbnail_url) return item.thumbnail_url
  if (item.image_url) return item.image_url
  const sd = item.style_data
  if (sd?.mask_image_url) return sd.mask_image_url
  if (sd?.text_clip_image) return sd.text_clip_image
  return null
}

const assets = computed(() => {
  if (!store.currentBoard?.items) return []
  return store.currentBoard.items.filter(i => {
    if (i.type === 'image' || i.type === 'image_set') return true
    if (VIDEO_TYPES.has(i.type) || FILE_TYPES.has(i.type)) return true
    if (hasAnyImage(i)) return true
    return false
  })
})

const filtered = computed(() => {
  let items = assets.value
  if (activeFilter.value === 'images') {
    items = items.filter(i => !VIDEO_TYPES.has(i.type) && !FILE_TYPES.has(i.type))
  } else if (activeFilter.value === 'videos') {
    items = items.filter(i => VIDEO_TYPES.has(i.type))
  } else if (activeFilter.value === 'files') {
    items = items.filter(i => FILE_TYPES.has(i.type))
  }

  if (search.value.trim()) {
    const q = search.value.toLowerCase()
    items = items.filter(i => getLabel(i).toLowerCase().includes(q))
  }
  return items
})

function getLabel(item) {
  return item.title || item.content || item.name || item.type.replace('_', ' ')
}

function getDims(item) {
  const w = item.width ? Math.round(item.width) : '?'
  const h = item.height ? Math.round(item.height) : '?'
  return `${w} x ${h}`
}

function triggerReplace(item) {
  const input = document.createElement('input')
  input.type = 'file'
  input.multiple = false
  input.accept = 'image/*'
  input.onchange = async (e) => {
    const files = Array.from(e.target.files)
    if (!files.length) return
    replacingId.value = item.id
    try {
      const uploaded = await store.uploadFiles(files)
      if (!uploaded?.[0]) return
      const newUrl = uploaded[0].url
      const sd = item.style_data || {}

      if (sd.text_clip_image) {
        store.updateItem(item.id, {
          style_data: { ...sd, text_clip_image: newUrl }
        })
      } else if (sd.mask_image_url) {
        store.updateItem(item.id, {
          style_data: { ...sd, mask_image_url: newUrl }
        })
      } else {
        store.updateItem(item.id, {
          image_url: newUrl,
          thumbnail_url: uploaded[0].thumbnail_url || newUrl,
          title: item.title || files[0].name
        })
      }
    } catch (err) {
      console.error('Failed to replace image:', err)
    } finally {
      replacingId.value = null
    }
  }
  input.click()
}
</script>

<style scoped>
.bg-checkerboard {
  background-image: linear-gradient(45deg, #e5e7eb 25%, transparent 25%),
    linear-gradient(-45deg, #e5e7eb 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, #e5e7eb 75%),
    linear-gradient(-45deg, transparent 75%, #e5e7eb 75%);
  background-size: 12px 12px;
  background-position: 0 0, 0 6px, 6px -6px, -6px 0;
}
.dark .bg-checkerboard {
  background-image: linear-gradient(45deg, #1e293b 25%, transparent 25%),
    linear-gradient(-45deg, #1e293b 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, #1e293b 75%),
    linear-gradient(-45deg, transparent 75%, #1e293b 75%);
}
</style>
