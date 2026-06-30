<script setup>
import { watch } from 'vue'

const props = defineProps({
  folder: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  expandedFolders: { type: Object, required: true },
  thumbs: { type: Object, required: true },
  attaching: { type: [Number, null], default: null }
})

const emit = defineEmits(['toggle', 'attach', 'load-thumb'])

const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']

function isImage(file) {
  if (file.mime_type?.startsWith('image/')) return true
  const ext = (file.original_name || '').split('.').pop()?.toLowerCase()
  return imageExts.includes(ext)
}

const fileIcons = {
  'application/pdf': 'picture_as_pdf',
  'image/': 'image',
  'video/': 'movie',
  'application/vnd.openxmlformats-officedocument.spreadsheetml': 'table_chart',
  'application/vnd.ms-excel': 'table_chart',
  'application/vnd.openxmlformats-officedocument.wordprocessingml': 'description',
  'application/msword': 'description',
  'application/vnd.openxmlformats-officedocument.presentationml': 'slideshow',
}

function getIcon(mime) {
  if (!mime) return 'insert_drive_file'
  for (const [key, icon] of Object.entries(fileIcons)) {
    if (mime.startsWith(key)) return icon
  }
  return 'insert_drive_file'
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

function countAllFiles(folder) {
  let count = folder.files?.length || 0
  for (const sf of (folder.subfolders || [])) {
    count += countAllFiles(sf)
  }
  return count
}

const isExpanded = () => props.expandedFolders.has(props.folder.id)
const totalFiles = () => countAllFiles(props.folder)

watch(() => props.expandedFolders.has(props.folder.id), (open) => {
  if (open && props.folder.files) {
    for (const f of props.folder.files) {
      if (isImage(f)) emit('load-thumb', f)
    }
  }
})
</script>

<template>
  <div :style="{ marginLeft: depth > 0 ? (depth * 12) + 'px' : '0' }">
    <button
      @click="emit('toggle', folder.id)"
      class="flex items-center gap-2 w-full px-2.5 py-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
    >
      <span
        class="material-symbols-rounded text-sm text-surface-400 transition-transform"
        :class="isExpanded() ? 'rotate-90' : ''"
      >chevron_right</span>
      <span class="material-symbols-rounded text-lg text-amber-500">folder</span>
      <span class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">{{ folder.name }}</span>
      <span class="text-xs text-surface-400 font-normal ml-auto shrink-0">({{ totalFiles() }})</span>
    </button>

    <template v-if="isExpanded()">
      <!-- Nested subfolders (recursive) -->
      <template v-for="sf in (folder.subfolders || [])" :key="sf.id">
        <SubfolderNode
          :folder="sf"
          :depth="depth + 1"
          :expanded-folders="expandedFolders"
          :thumbs="thumbs"
          :attaching="attaching"
          @toggle="(id) => emit('toggle', id)"
          @attach="(file) => emit('attach', file)"
          @load-thumb="(file) => emit('load-thumb', file)"
        />
      </template>

      <!-- Files in this folder -->
      <div v-if="folder.files?.length" class="grid grid-cols-2 md:grid-cols-3 gap-2 mt-2 mb-3" :style="{ marginLeft: ((depth + 1) * 12) + 'px' }">
        <div
          v-for="file in folder.files"
          :key="file.id"
          class="group relative border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden hover:border-primary-300 dark:hover:border-primary-600 transition-colors"
        >
          <div
            v-if="isImage(file)"
            class="aspect-[4/3] bg-surface-100 dark:bg-surface-700 flex items-center justify-center overflow-hidden"
          >
            <img
              v-if="thumbs[file.id] && thumbs[file.id] !== 'loading' && thumbs[file.id] !== 'error'"
              :src="thumbs[file.id]"
              :alt="file.original_name"
              class="w-full h-full object-cover"
            />
            <span v-else class="material-symbols-rounded text-2xl text-surface-300">image</span>
          </div>

          <div
            v-else
            class="aspect-[4/3] bg-surface-50 dark:bg-surface-800 flex flex-col items-center justify-center gap-1.5"
          >
            <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-500">{{ getIcon(file.mime_type) }}</span>
            <span class="text-[10px] text-surface-400 uppercase font-medium">
              {{ (file.original_name || '').split('.').pop()?.toUpperCase() || 'FILE' }}
            </span>
          </div>

          <div class="px-2.5 py-2 border-t border-surface-100 dark:border-surface-700">
            <p class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate">{{ file.original_name }}</p>
            <p class="text-[10px] text-surface-400 mt-0.5">{{ formatSize(file.size) }}</p>
          </div>

          <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
            <button
              @click.stop="emit('attach', file)"
              :disabled="attaching === file.id"
              class="px-3 py-1.5 rounded-full text-xs font-medium bg-white dark:bg-surface-800 text-primary-600 dark:text-primary-400 shadow-lg opacity-0 group-hover:opacity-100 transition-all disabled:opacity-50 flex items-center gap-1"
            >
              <span v-if="attaching === file.id" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
              <template v-else>
                <span class="material-symbols-rounded text-sm">attach_file</span>
                Attach
              </template>
            </button>
          </div>
        </div>
      </div>

      <p v-else-if="!folder.subfolders?.length" class="text-xs text-surface-400 py-2" :style="{ marginLeft: ((depth + 1) * 12 + 10) + 'px' }">
        Empty folder
      </p>
    </template>
  </div>
</template>

<script>
export default {
  name: 'SubfolderNode',
}
</script>
