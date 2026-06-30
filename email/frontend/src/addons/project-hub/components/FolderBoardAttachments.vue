<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import api from '@/services/api'

const hubStore = useProjectHubStore()
const boards = ref([])
const loading = ref(false)
const thumbs = ref({})
const expandedBoards = ref(new Set())

const folderId = computed(() => hubStore.activeFolderId)

const totalAttachments = computed(() => {
  let count = 0
  for (const b of boards.value) {
    for (const c of b.cards) count += c.attachments.length
  }
  return count
})

const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']

function isImage(att) {
  if (att.mime_type?.startsWith('image/')) return true
  const ext = (att.name || '').split('.').pop()?.toLowerCase()
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

async function loadThumb(att) {
  if (!att.drive_file_id || thumbs.value[att.id] || !isImage(att)) return
  thumbs.value[att.id] = 'loading'
  try {
    const res = await api.get(`/drive/files/${att.drive_file_id}/preview`, { responseType: 'blob' })
    if (res.data) thumbs.value[att.id] = URL.createObjectURL(res.data)
    else thumbs.value[att.id] = 'error'
  } catch {
    thumbs.value[att.id] = 'error'
  }
}

function loadBoardThumbs(board) {
  for (const card of board.cards) {
    for (const att of card.attachments) {
      if (isImage(att)) loadThumb(att)
    }
  }
}

function toggleBoard(boardId) {
  if (expandedBoards.value.has(boardId)) {
    expandedBoards.value.delete(boardId)
  } else {
    expandedBoards.value.add(boardId)
    const board = boards.value.find(b => b.board_id === boardId)
    if (board) loadBoardThumbs(board)
  }
}

function boardAttCount(board) {
  let c = 0
  for (const card of board.cards) c += card.attachments.length
  return c
}

async function loadData() {
  if (!folderId.value) return
  loading.value = true
  try {
    const { data } = await api.get(`/project-hub/folders/${folderId.value}/board-attachments`)
    boards.value = data.boards || []
    if (boards.value.length > 0) {
      expandedBoards.value.add(boards.value[0].board_id)
      loadBoardThumbs(boards.value[0])
    }
  } catch (err) {
    console.error('[FolderBoardAttachments] load error:', err)
  } finally {
    loading.value = false
  }
}

watch(folderId, (id) => {
  if (id) {
    boards.value = []
    expandedBoards.value.clear()
    loadData()
  }
}, { immediate: true })

onBeforeUnmount(() => {
  for (const url of Object.values(thumbs.value)) {
    if (url && typeof url === 'string' && url.startsWith('blob:')) URL.revokeObjectURL(url)
  }
})
</script>

<template>
  <div v-if="loading" class="flex items-center justify-center py-8">
    <div class="animate-spin rounded-full h-6 w-6 border-2 border-primary-500 border-t-transparent"></div>
  </div>

  <div v-else-if="boards.length" class="space-y-3">
    <div class="flex items-center gap-2 px-1">
      <span class="material-symbols-rounded text-lg text-primary-500">attach_file</span>
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200">
        Board Attachments
        <span class="text-xs text-surface-400 font-normal">({{ totalAttachments }})</span>
      </h3>
    </div>

    <div
      v-for="board in boards"
      :key="board.board_id"
      class="rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden"
    >
      <button
        @click="toggleBoard(board.board_id)"
        class="flex items-center justify-between w-full px-4 py-3 bg-surface-50 dark:bg-surface-800 hover:bg-surface-100 dark:hover:bg-surface-700/70 transition-colors"
      >
        <div class="flex items-center gap-2.5">
          <span class="material-symbols-rounded text-lg text-primary-500">dashboard</span>
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ board.board_name }}</span>
          <span class="text-xs text-surface-400">({{ boardAttCount(board) }})</span>
        </div>
        <span
          class="material-symbols-rounded text-lg text-surface-400 transition-transform"
          :class="expandedBoards.has(board.board_id) ? 'rotate-180' : ''"
        >expand_more</span>
      </button>

      <div v-if="expandedBoards.has(board.board_id)" class="px-4 py-3 space-y-4 bg-white dark:bg-surface-900">
        <div v-for="card in board.cards" :key="card.card_id">
          <p class="text-xs font-medium text-surface-500 dark:text-surface-400 mb-2 flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[14px]">credit_card</span>
            {{ card.card_title }}
          </p>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            <div
              v-for="att in card.attachments"
              :key="att.id"
              class="group relative border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden hover:border-primary-300 dark:hover:border-primary-600 transition-colors"
            >
              <div
                v-if="isImage(att)"
                class="aspect-[4/3] bg-surface-100 dark:bg-surface-700 flex items-center justify-center overflow-hidden"
              >
                <img
                  v-if="thumbs[att.id] && thumbs[att.id] !== 'loading' && thumbs[att.id] !== 'error'"
                  :src="thumbs[att.id]"
                  :alt="att.name"
                  class="w-full h-full object-cover"
                />
                <span v-else-if="thumbs[att.id] === 'loading'" class="material-symbols-rounded text-2xl text-surface-300 animate-pulse">image</span>
                <span v-else class="material-symbols-rounded text-2xl text-surface-300">image</span>
              </div>
              <div
                v-else
                class="aspect-[4/3] bg-surface-50 dark:bg-surface-800 flex flex-col items-center justify-center gap-1"
              >
                <span class="material-symbols-rounded text-2xl text-surface-300 dark:text-surface-500">{{ getIcon(att.mime_type) }}</span>
                <span class="text-[10px] text-surface-400 uppercase font-medium">
                  {{ (att.name || '').split('.').pop()?.toUpperCase() || 'FILE' }}
                </span>
              </div>
              <div class="px-2 py-1.5 border-t border-surface-100 dark:border-surface-700">
                <p class="text-[11px] font-medium text-surface-700 dark:text-surface-300 truncate">{{ att.name }}</p>
                <p v-if="att.size" class="text-[10px] text-surface-400">{{ formatSize(att.size) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
