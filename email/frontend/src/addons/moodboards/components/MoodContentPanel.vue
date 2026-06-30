<template>
  <div class="mood-content-panel flex flex-col h-full">
    <!-- Filter tabs -->
    <div class="flex items-center gap-1 px-3 py-2 border-b border-surface-100 dark:border-surface-700 flex-shrink-0">
      <button
        v-for="tab in filterTabs"
        :key="tab.key"
        class="px-2.5 py-1 text-xs rounded-lg transition-colors"
        :class="activeFilter === tab.key
          ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
          : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'"
        @click="activeFilter = tab.key"
      >
        {{ tab.label }}
        <span v-if="tab.count > 0" class="ml-1 text-[10px] opacity-60">{{ tab.count }}</span>
      </button>
    </div>

    <!-- Search -->
    <div class="px-3 py-2 flex-shrink-0">
      <div class="relative">
        <span class="material-symbols-rounded absolute left-2 top-1/2 -translate-y-1/2 text-sm text-surface-400">search</span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search content..."
          class="w-full pl-7 pr-3 py-1.5 text-xs rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-800 dark:text-surface-200 focus:ring-1 focus:ring-primary-500/40 outline-none"
        />
      </div>
    </div>

    <!-- Content list -->
    <div class="flex-1 overflow-auto p-3 pt-0">
      <div v-if="filteredItems.length === 0" class="flex flex-col items-center justify-center py-12 text-center">
        <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600 mb-2">
          {{ activeFilter === 'images' ? 'image' : activeFilter === 'text' ? 'text_fields' : 'dashboard' }}
        </span>
        <p class="text-xs text-surface-400">No {{ activeFilter === 'all' ? 'content' : activeFilter }} found</p>
      </div>

      <!-- Images section -->
      <div v-if="showImages && searchedImages.length > 0" class="mb-4">
        <h5 v-if="activeFilter === 'all'" class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-2 px-1">
          Images ({{ searchedImages.length }})
        </h5>
        <div class="grid grid-cols-2 gap-2">
          <div
            v-for="item in searchedImages"
            :key="item.id"
            class="group relative rounded-lg overflow-hidden border border-surface-200 dark:border-surface-700 hover:border-primary-400 dark:hover:border-primary-500 transition-colors bg-surface-100 dark:bg-surface-800"
          >
            <img
              :src="item.thumbnail_url || item.image_url"
              :alt="item.content || item.name || 'Image'"
              class="w-full aspect-[4/3] object-cover cursor-pointer"
              loading="eager"
              @click="previewImage = item"
            />
            <!-- Replace overlay -->
            <div
              v-if="editable"
              class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2"
            >
              <button
                class="p-1.5 rounded-lg bg-white/90 text-surface-700 hover:bg-white transition-colors"
                @click.stop="previewImage = item"
                title="Preview"
              >
                <span class="material-symbols-rounded text-base">visibility</span>
              </button>
              <label
                class="p-1.5 rounded-lg bg-primary-500 text-white hover:bg-primary-600 transition-colors cursor-pointer"
                title="Replace image"
              >
                <span class="material-symbols-rounded text-base">swap_horiz</span>
                <input
                  type="file"
                  accept="image/*"
                  class="hidden"
                  @change="replaceImage(item, $event)"
                />
              </label>
            </div>
            <div v-if="item.content || item.name" class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/60 to-transparent p-1.5 pt-4 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
              <p class="text-[10px] text-white truncate">{{ item.content || item.name }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Text items section -->
      <div v-if="showText && searchedTextItems.length > 0" class="mb-4">
        <h5 v-if="activeFilter === 'all'" class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-2 px-1">
          Text ({{ searchedTextItems.length }})
        </h5>
        <div class="space-y-2">
          <div
            v-for="item in searchedTextItems"
            :key="item.id"
            class="rounded-lg border border-surface-200 dark:border-surface-700 hover:border-primary-400 dark:hover:border-primary-500 transition-colors"
            :class="editingItemId === item.id ? 'ring-2 ring-primary-500/30' : ''"
            :style="item.style_data?.fill_color ? { borderLeftWidth: '3px', borderLeftColor: item.style_data.fill_color } : {}"
          >
            <!-- Edit mode -->
            <div v-if="editingItemId === item.id" class="p-2">
              <textarea
                ref="editTextareaRef"
                v-model="editingContent"
                class="w-full text-xs bg-transparent text-surface-800 dark:text-surface-200 outline-none resize-none min-h-[60px]"
                rows="3"
                @keydown.escape="cancelEdit"
                @keydown.ctrl.enter="saveEdit(item)"
                @keydown.meta.enter="saveEdit(item)"
              />
              <div class="flex items-center justify-between mt-1.5 pt-1.5 border-t border-surface-100 dark:border-surface-700">
                <span class="text-[10px] text-surface-400">Ctrl+Enter to save, Esc to cancel</span>
                <div class="flex gap-1">
                  <button
                    class="px-2 py-0.5 text-[10px] rounded-md bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                    @click="cancelEdit"
                  >
                    Cancel
                  </button>
                  <button
                    class="px-2 py-0.5 text-[10px] rounded-md bg-primary-500 text-white hover:bg-primary-600 transition-colors"
                    @click="saveEdit(item)"
                  >
                    Save
                  </button>
                </div>
              </div>
            </div>

            <!-- View mode -->
            <div
              v-else
              class="p-3 group cursor-pointer"
              @click="editable ? startEdit(item) : null"
            >
              <p class="text-xs text-surface-700 dark:text-surface-300 whitespace-pre-wrap" :class="editable ? 'line-clamp-6' : 'line-clamp-4'">
                {{ stripHtml(item.content) }}
              </p>
              <div class="flex items-center justify-between mt-1.5">
                <p v-if="item.name" class="text-[10px] text-surface-400">{{ item.name }}</p>
                <span v-if="editable" class="text-[10px] text-primary-500 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-0.5">
                  <span class="material-symbols-rounded text-xs">edit</span> Click to edit
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Notes section -->
      <div v-if="showNotes && searchedNotes.length > 0" class="mb-4">
        <h5 v-if="activeFilter === 'all'" class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-2 px-1">
          Notes ({{ searchedNotes.length }})
        </h5>
        <div class="space-y-2">
          <div
            v-for="item in searchedNotes"
            :key="item.id"
            class="rounded-lg border border-surface-200 dark:border-surface-700 hover:border-primary-400 dark:hover:border-primary-500 transition-colors"
            :class="editingItemId === item.id ? 'ring-2 ring-primary-500/30' : ''"
            :style="item.style_data?.fill_color ? { borderLeftWidth: '3px', borderLeftColor: item.style_data.fill_color } : {}"
          >
            <!-- Edit mode -->
            <div v-if="editingItemId === item.id" class="p-2">
              <textarea
                ref="editTextareaRef"
                v-model="editingContent"
                class="w-full text-xs bg-transparent text-surface-800 dark:text-surface-200 outline-none resize-none min-h-[60px]"
                rows="4"
                @keydown.escape="cancelEdit"
                @keydown.ctrl.enter="saveEdit(item)"
                @keydown.meta.enter="saveEdit(item)"
              />
              <div class="flex items-center justify-between mt-1.5 pt-1.5 border-t border-surface-100 dark:border-surface-700">
                <span class="text-[10px] text-surface-400">Ctrl+Enter to save, Esc to cancel</span>
                <div class="flex gap-1">
                  <button
                    class="px-2 py-0.5 text-[10px] rounded-md bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                    @click="cancelEdit"
                  >
                    Cancel
                  </button>
                  <button
                    class="px-2 py-0.5 text-[10px] rounded-md bg-primary-500 text-white hover:bg-primary-600 transition-colors"
                    @click="saveEdit(item)"
                  >
                    Save
                  </button>
                </div>
              </div>
            </div>

            <!-- View mode -->
            <div
              v-else
              class="p-3 group cursor-pointer"
              @click="editable ? startEdit(item) : null"
            >
              <p class="text-xs text-surface-700 dark:text-surface-300 whitespace-pre-wrap" :class="editable ? 'line-clamp-6' : 'line-clamp-4'">
                {{ stripHtml(item.content) || 'Empty note' }}
              </p>
              <div class="flex items-center justify-between mt-1.5">
                <p v-if="item.name" class="text-[10px] text-surface-400">{{ item.name }}</p>
                <span v-if="editable" class="text-[10px] text-primary-500 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-0.5">
                  <span class="material-symbols-rounded text-xs">edit</span> Click to edit
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Colors section -->
      <div v-if="showColors && searchedColors.length > 0" class="mb-4">
        <h5 v-if="activeFilter === 'all'" class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-2 px-1">
          Colors ({{ searchedColors.length }})
        </h5>
        <div class="flex flex-wrap gap-2">
          <div
            v-for="item in searchedColors"
            :key="item.id"
            class="flex items-center gap-2 rounded-lg border border-surface-200 dark:border-surface-700 p-2 hover:border-primary-400 dark:hover:border-primary-500 transition-colors cursor-pointer"
            @click="copyColor(item)"
            :title="'Click to copy ' + (item.style_data?.fill_color || item.color || '')"
          >
            <div
              class="w-8 h-8 rounded-md flex-shrink-0 border border-surface-300 dark:border-surface-600"
              :style="{ backgroundColor: item.style_data?.fill_color || item.color || '#ccc' }"
            />
            <div class="min-w-0">
              <p class="text-[10px] font-mono text-surface-600 dark:text-surface-400 truncate">{{ item.style_data?.fill_color || item.color || '' }}</p>
              <p v-if="item.content" class="text-[10px] text-surface-400 truncate">{{ item.content }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Videos section -->
      <div v-if="showVideos && searchedVideos.length > 0" class="mb-4">
        <h5 v-if="activeFilter === 'all'" class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-2 px-1">
          Videos ({{ searchedVideos.length }})
        </h5>
        <div class="space-y-2">
          <div
            v-for="item in searchedVideos"
            :key="item.id"
            class="flex items-center gap-2 rounded-lg border border-surface-200 dark:border-surface-700 p-2 hover:border-primary-400 dark:hover:border-primary-500 transition-colors"
          >
            <div class="w-10 h-10 rounded bg-surface-200 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-surface-400">
                {{ item.type === 'youtube' ? 'smart_display' : 'videocam' }}
              </span>
            </div>
            <div class="min-w-0">
              <p class="text-xs text-surface-700 dark:text-surface-300 truncate">{{ item.content || item.name || item.url || 'Video' }}</p>
              <p class="text-[10px] text-surface-400 capitalize">{{ item.type === 'youtube' ? 'YouTube' : 'Video' }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="flex items-center justify-between px-3 py-2 border-t border-surface-200 dark:border-surface-700 text-[10px] text-surface-400 flex-shrink-0">
      <span>{{ totalFiltered }} items</span>
      <span v-if="savingItem" class="flex items-center gap-1 text-primary-500">
        <span class="material-symbols-rounded text-xs animate-spin">progress_activity</span> Saving...
      </span>
      <span v-else-if="lastSaved" class="text-green-500">Saved</span>
    </div>

    <!-- Image preview overlay -->
    <Teleport to="body">
      <div
        v-if="previewImage"
        class="fixed inset-0 z-[9999] bg-black/80 flex items-center justify-center p-8"
        @click.self="previewImage = null"
      >
        <div class="relative max-w-4xl max-h-full">
          <img
            :src="previewImage.image_url || previewImage.thumbnail_url"
            :alt="previewImage.content || 'Preview'"
            class="max-w-full max-h-[80vh] object-contain rounded-lg shadow-2xl"
          />
          <div class="absolute top-2 right-2 flex gap-1.5">
            <label
              v-if="editable"
              class="w-8 h-8 rounded-full bg-primary-500 text-white flex items-center justify-center hover:bg-primary-600 transition-colors cursor-pointer"
              title="Replace this image"
            >
              <span class="material-symbols-rounded text-lg">swap_horiz</span>
              <input
                type="file"
                accept="image/*"
                class="hidden"
                @change="replaceImage(previewImage, $event); previewImage = null"
              />
            </label>
            <button
              class="w-8 h-8 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 transition-colors"
              @click="previewImage = null"
            >
              <span class="material-symbols-rounded text-lg">close</span>
            </button>
          </div>
          <p v-if="previewImage.content || previewImage.name" class="text-sm text-white/80 mt-3 text-center">
            {{ previewImage.content || previewImage.name }}
          </p>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
/**
 * MoodContentPanel — Shared component for browsing and editing mood board content
 * in a flat list view. Used in:
 *   1. Board Pro "Mood Split" side panel (readonly or editable)
 *   2. Mood Board editor left sidebar "Content" tab (always editable)
 *
 * Props:
 *   - items: Array of mood board items
 *   - editable: Whether text editing and image replace are enabled
 *   - boardId: The mood board ID (needed for upload/update)
 *
 * Emits:
 *   - update-item: { itemId, data } — parent must handle the actual API call
 *   - replace-image: { itemId, file } — parent must handle upload + update
 */
import { ref, computed, nextTick, watch } from 'vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  editable: { type: Boolean, default: false },
  boardId: { type: [Number, String], default: null },
})

const emit = defineEmits(['update-item', 'replace-image'])

const activeFilter = ref('all')
const searchQuery = ref('')
const previewImage = ref(null)
const editingItemId = ref(null)
const editingContent = ref('')
const editTextareaRef = ref(null)
const savingItem = ref(false)
const lastSaved = ref(false)

// ---------------------------------------------------------------------------
// Item type helpers
// ---------------------------------------------------------------------------
const VISUAL_TYPES = new Set(['image', 'image_set', 'note', 'text', 'color_swatch', 'video', 'youtube'])

const imageItems = computed(() => props.items.filter(i =>
  (i.type === 'image' || i.type === 'image_set') && i.image_url
))

const noteItems = computed(() => props.items.filter(i =>
  i.type === 'note' && i.content
))

const textItems = computed(() => props.items.filter(i =>
  i.type === 'text' && i.content && stripHtml(i.content).trim()
))

const colorItems = computed(() => props.items.filter(i =>
  i.type === 'color_swatch'
))

const videoItems = computed(() => props.items.filter(i =>
  i.type === 'video' || i.type === 'youtube'
))

// Search filtering
function matchesSearch(item) {
  if (!searchQuery.value) return true
  const q = searchQuery.value.toLowerCase()
  const text = stripHtml(item.content || '').toLowerCase()
  const name = (item.name || '').toLowerCase()
  return text.includes(q) || name.includes(q)
}

const searchedImages = computed(() => imageItems.value.filter(matchesSearch))
const searchedTextItems = computed(() => textItems.value.filter(matchesSearch))
const searchedNotes = computed(() => noteItems.value.filter(matchesSearch))
const searchedColors = computed(() => colorItems.value.filter(matchesSearch))
const searchedVideos = computed(() => videoItems.value.filter(matchesSearch))

const showImages = computed(() => activeFilter.value === 'all' || activeFilter.value === 'images')
const showText = computed(() => activeFilter.value === 'all' || activeFilter.value === 'text')
const showNotes = computed(() => activeFilter.value === 'all' || activeFilter.value === 'notes')
const showColors = computed(() => activeFilter.value === 'all' || activeFilter.value === 'colors')
const showVideos = computed(() => activeFilter.value === 'all' || activeFilter.value === 'videos')

const filteredItems = computed(() => {
  switch (activeFilter.value) {
    case 'images': return searchedImages.value
    case 'notes': return searchedNotes.value
    case 'text': return searchedTextItems.value
    case 'colors': return searchedColors.value
    case 'videos': return searchedVideos.value
    default: return [
      ...searchedImages.value,
      ...searchedTextItems.value,
      ...searchedNotes.value,
      ...searchedColors.value,
      ...searchedVideos.value,
    ]
  }
})

const totalFiltered = computed(() => filteredItems.value.length)

const filterTabs = computed(() => [
  { key: 'all', label: 'All', count: filteredItems.value.length },
  { key: 'images', label: 'Images', count: searchedImages.value.length },
  { key: 'text', label: 'Text', count: searchedTextItems.value.length },
  { key: 'notes', label: 'Notes', count: searchedNotes.value.length },
  { key: 'colors', label: 'Colors', count: searchedColors.value.length },
  { key: 'videos', label: 'Videos', count: searchedVideos.value.length },
].filter(t => t.key === 'all' || t.count > 0))

// ---------------------------------------------------------------------------
// Edit text
// ---------------------------------------------------------------------------
function startEdit(item) {
  editingItemId.value = item.id
  editingContent.value = stripHtml(item.content || '')
  nextTick(() => {
    const el = editTextareaRef.value
    if (el) {
      const textarea = Array.isArray(el) ? el[0] : el
      if (textarea) {
        textarea.focus()
        textarea.setSelectionRange(textarea.value.length, textarea.value.length)
      }
    }
  })
}

function cancelEdit() {
  editingItemId.value = null
  editingContent.value = ''
}

async function saveEdit(item) {
  if (!editingContent.value.trim() && !item.content) {
    cancelEdit()
    return
  }

  savingItem.value = true
  lastSaved.value = false

  emit('update-item', {
    itemId: item.id,
    data: { content: editingContent.value },
  })

  editingItemId.value = null
  editingContent.value = ''
  savingItem.value = false
  lastSaved.value = true
  setTimeout(() => { lastSaved.value = false }, 2000)
}

// ---------------------------------------------------------------------------
// Replace image
// ---------------------------------------------------------------------------
function replaceImage(item, event) {
  const file = event?.target?.files?.[0]
  if (!file) return

  emit('replace-image', {
    itemId: item.id,
    file,
  })

  // Reset file input
  if (event?.target) event.target.value = ''
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function stripHtml(html) {
  if (!html) return ''
  return html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').trim()
}

async function copyColor(item) {
  const color = item.style_data?.fill_color || item.color || ''
  if (color && navigator.clipboard) {
    try {
      await navigator.clipboard.writeText(color)
    } catch { /* silent */ }
  }
}
</script>

