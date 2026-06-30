<script setup>
import { ref, computed, onMounted } from 'vue'
import { useDriveStore } from '@/stores/drive'
import { useToastStore } from '@/stores/toast'

const drive = useDriveStore()
const toast = useToastStore()

const showEmptyConfirm = ref(false)

// All trashed items combined
const allTrashedItems = computed(() => {
  return [
    ...drive.trashedItems.folders.map(f => ({ ...f, itemType: 'folder' })),
    ...drive.trashedItems.files.map(f => ({ ...f, itemType: 'file' }))
  ].sort((a, b) => new Date(b.trashed_at) - new Date(a.trashed_at))
})

function getFileIcon(mimeType) {
  if (mimeType?.startsWith('image/')) return 'image'
  if (mimeType?.startsWith('video/')) return 'movie'
  if (mimeType?.startsWith('audio/')) return 'audio_file'
  if (mimeType?.includes('pdf')) return 'picture_as_pdf'
  if (mimeType?.includes('word') || mimeType?.includes('document')) return 'description'
  if (mimeType?.includes('sheet') || mimeType?.includes('excel')) return 'table_chart'
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) return 'slideshow'
  if (mimeType?.includes('zip') || mimeType?.includes('compressed')) return 'folder_zip'
  if (mimeType?.includes('text/')) return 'article'
  return 'draft'
}

function getFileIconColor(mimeType) {
  if (mimeType?.includes('pdf')) return 'text-red-500'
  if (mimeType?.includes('word') || mimeType?.includes('document')) return 'text-blue-500'
  if (mimeType?.includes('sheet') || mimeType?.includes('excel')) return 'text-green-500'
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) return 'text-orange-500'
  if (mimeType?.startsWith('image/')) return 'text-purple-500'
  if (mimeType?.startsWith('video/')) return 'text-pink-500'
  if (mimeType?.startsWith('audio/')) return 'text-teal-500'
  return 'text-surface-500'
}

function formatSize(bytes) {
  if (!bytes) return '0 B'
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB'
  if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB'
  return bytes + ' B'
}

function formatTrashedDate(dateStr) {
  if (!dateStr) return '-'
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))
  
  if (days === 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days} days ago`
  if (days < 30) return `${Math.floor(days / 7)} weeks ago`
  return `${Math.floor(days / 30)} months ago`
}

function getDaysUntilDeletion(dateStr) {
  if (!dateStr) return null
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  const daysSinceTrashed = Math.floor(diff / (1000 * 60 * 60 * 24))
  const daysRemaining = 30 - daysSinceTrashed
  return daysRemaining > 0 ? daysRemaining : 0
}

async function restoreItem(item) {
  let success = false
  if (item.itemType === 'folder') {
    success = await drive.restoreFolder(item.id)
  } else {
    success = await drive.restoreFile(item.id)
  }
  
  if (success) {
    toast.success(`${item.itemType === 'folder' ? 'Folder' : 'File'} restored`)
  } else {
    toast.error('Failed to restore item')
  }
}

async function deleteItemPermanently(item) {
  const success = await drive.permanentlyDelete(item.id, item.itemType)
  
  if (success) {
    toast.success(`${item.itemType === 'folder' ? 'Folder' : 'File'} permanently deleted`)
  } else {
    toast.error('Failed to delete item')
  }
}

async function emptyTrash() {
  const result = await drive.emptyTrash()
  if (result.success) {
    toast.success(`Deleted ${result.count} items permanently`)
    showEmptyConfirm.value = false
  } else {
    toast.error('Failed to empty trash')
  }
}

onMounted(() => {
  drive.fetchTrash()
})
</script>

<template>
  <div class="drive-trash-view p-4">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <button 
          @click="drive.exitTrashView()"
          class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800"
        >
          <span class="material-symbols-rounded text-xl">arrow_back</span>
        </button>
        <div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Trash</h2>
          <p class="text-sm text-surface-500">Items are automatically deleted after 30 days</p>
        </div>
      </div>
      
      <button 
        v-if="allTrashedItems.length > 0"
        @click="showEmptyConfirm = true"
        class="btn-secondary text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
      >
        <span class="material-symbols-rounded">delete_forever</span>
        Empty Trash
      </button>
    </div>
    
    <!-- Loading -->
    <div v-if="drive.loadingTrash" class="flex justify-center py-12">
      <span class="spinner text-primary-500 w-8 h-8"></span>
    </div>
    
    <!-- Empty state -->
    <div v-else-if="allTrashedItems.length === 0" class="text-center py-12">
      <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600 mb-4">delete_outline</span>
      <p class="text-surface-500 dark:text-surface-400 mb-2">Trash is empty</p>
      <p class="text-sm text-surface-400">Deleted files and folders will appear here</p>
    </div>
    
    <!-- Trashed items list -->
    <div v-else class="space-y-1">
      <div 
        v-for="item in allTrashedItems" 
        :key="item.itemType + '-' + item.id"
        class="flex items-center gap-4 px-4 py-3 rounded-xl bg-surface-50 dark:bg-surface-800/50 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors group"
      >
        <!-- Icon -->
        <div class="flex-shrink-0">
          <span 
            v-if="item.itemType === 'folder'" 
            class="material-symbols-rounded text-2xl text-surface-400"
          >folder</span>
          <span 
            v-else 
            :class="['material-symbols-rounded text-2xl', getFileIconColor(item.mime_type)]"
          >{{ getFileIcon(item.mime_type) }}</span>
        </div>
        
        <!-- Info -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
            {{ item.itemType === 'folder' ? item.name : item.original_name }}
          </p>
          <div class="flex items-center gap-3 text-xs text-surface-500">
            <span>{{ item.itemType === 'folder' ? 'Folder' : formatSize(item.size) }}</span>
            <span>From: {{ item.original_location || 'Root' }}</span>
            <span>Deleted {{ formatTrashedDate(item.trashed_at) }}</span>
            <span 
              v-if="getDaysUntilDeletion(item.trashed_at) !== null"
              :class="getDaysUntilDeletion(item.trashed_at) < 7 ? 'text-red-500' : ''"
            >
              ({{ getDaysUntilDeletion(item.trashed_at) }} days left)
            </span>
          </div>
        </div>
        
        <!-- Actions -->
        <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
          <button 
            @click="restoreItem(item)"
            class="btn-secondary text-sm py-1.5 px-3"
            title="Restore"
          >
            <span class="material-symbols-rounded text-base">restore</span>
            Restore
          </button>
          <button 
            @click="deleteItemPermanently(item)"
            class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
            title="Delete permanently"
          >
            <span class="material-symbols-rounded text-lg">delete_forever</span>
          </button>
        </div>
      </div>
    </div>
    
    <!-- Empty Trash Confirmation Modal -->
    <Teleport to="body">
      <div 
        v-if="showEmptyConfirm" 
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        @click.self="showEmptyConfirm = false"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 w-full max-w-md shadow-xl">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-xl text-red-500">delete_forever</span>
            </div>
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Empty Trash?</h3>
          </div>
          
          <p class="text-surface-600 dark:text-surface-400 mb-6">
            This will permanently delete all {{ allTrashedItems.length }} item(s) in the trash. This action cannot be undone.
          </p>
          
          <div class="flex justify-end gap-3">
            <button @click="showEmptyConfirm = false" class="btn-secondary">
              Cancel
            </button>
            <button @click="emptyTrash" class="btn-primary bg-red-500 hover:bg-red-600">
              <span class="material-symbols-rounded">delete_forever</span>
              Empty Trash
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.spinner {
  border: 3px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>

