<script setup>
import { ref, watch } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'

const props = defineProps({
  show: { type: Boolean, default: false },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
})

const emit = defineEmits(['close'])

const boardsStore = useBoardsStore()
const toast = useToastStore()

const bgBlur = ref(0)
const bgOverlayColor = ref('#000000')
const bgOverlayOpacity = ref(0)

const boardColors = [
  '#1e1e26', '#0f766e', '#0369a1', '#7c3aed', '#be185d',
  '#b91c1c', '#c2410c', '#15803d', '#1d4ed8', '#6d28d9',
]
const overlayColors = [
  '#000000', '#ffffff', '#1e1e26', '#0f766e', '#0369a1',
  '#7c3aed', '#be185d', '#b91c1c',
]

watch(() => props.show, (visible) => {
  if (visible) syncFromBoard()
})

function syncFromBoard() {
  const board = boardsStore.currentBoard
  if (!board) return
  bgBlur.value = board.background_blur || 0
  bgOverlayColor.value = board.background_overlay_color || '#000000'
  bgOverlayOpacity.value = board.background_overlay_opacity || 0
}

async function changeBgColor(color) {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, { background_color: color })
  emit('close')
}

async function updateBgBlur() {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, { background_blur: bgBlur.value })
}

async function updateBgOverlay() {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, {
    background_overlay_color: bgOverlayColor.value,
    background_overlay_opacity: bgOverlayOpacity.value,
  })
}

function openBgImageUpload() {
  document.getElementById('board-bg-upload-shared')?.click()
  emit('close')
}

async function handleBgImageUpload(e) {
  const file = e.target.files?.[0]
  if (!file || !boardsStore.currentBoard) return
  if (!file.type.startsWith('image/')) { toast.error('Please select an image file'); return }
  if (file.size > 5 * 1024 * 1024) { toast.error('Image must be less than 5MB'); return }
  try {
    const folderResponse = await api.post('/drive/board-folder', { board_name: boardsStore.currentBoard.name })
    if (!folderResponse.data.success) { toast.error('Failed to create board folder'); return }
    const folderId = folderResponse.data.data.folder.id
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder_id', folderId)
    const response = await api.post('/drive/upload', formData, { headers: { 'Content-Type': 'multipart/form-data' } })
    if (response.data.success) {
      const fileId = response.data.data.file.id
      const shareResponse = await api.post(`/drive/files/${fileId}/share`)
      if (shareResponse.data.success) {
        await boardsStore.updateBoard(boardsStore.currentBoard.id, { background_image: shareResponse.data.data.url })
        toast.success('Background updated')
      }
    } else {
      toast.error('Failed to upload image')
    }
  } catch (err) {
    console.error('Background upload error:', err)
    toast.error('Failed to upload image')
  }
  e.target.value = ''
}

async function removeBgImage() {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, { background_image: null })
  emit('close')
  toast.success('Background image removed')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-50"
      @click="emit('close')"
      @contextmenu.prevent="emit('close')"
    >
      <div
        class="absolute bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 w-72"
        :style="{ left: x + 'px', top: y + 'px' }"
        @click.stop
      >
        <p class="px-3 py-1.5 text-xs font-semibold text-surface-500 uppercase">Background Color</p>
        <div class="px-3 py-2">
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="color in boardColors" :key="color"
              @click="changeBgColor(color)"
              class="w-6 h-6 rounded transition-transform hover:scale-110"
              :style="{ backgroundColor: color }"
              :class="boardsStore.currentBoard?.background_color === color ? 'ring-2 ring-primary-500 ring-offset-1' : ''"
            ></button>
          </div>
        </div>

        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

        <button
          @click="openBgImageUpload"
          class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
        >
          <span class="material-symbols-rounded text-lg">image</span>
          Upload image
        </button>

        <button
          v-if="boardsStore.currentBoard?.background_image"
          @click="removeBgImage"
          class="w-full px-3 py-2 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          Remove image
        </button>

        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

        <div class="px-3 py-2">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-surface-500 uppercase">Blur</span>
            <span class="text-xs text-surface-500">{{ bgBlur }}px</span>
          </div>
          <input
            v-model.number="bgBlur"
            type="range" min="0" max="200" step="1"
            class="w-full h-2 bg-surface-200 dark:bg-surface-700 rounded-lg appearance-none cursor-pointer accent-primary-500"
            @change="updateBgBlur"
          />
        </div>

        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

        <div class="px-3 py-2">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-surface-500 uppercase">Overlay</span>
            <span class="text-xs text-surface-500">{{ bgOverlayOpacity }}%</span>
          </div>
          <div class="flex items-center gap-2 mb-2">
            <div class="flex flex-wrap gap-1">
              <button
                v-for="color in overlayColors" :key="'overlay-' + color"
                @click="bgOverlayColor = color; updateBgOverlay()"
                class="w-5 h-5 rounded transition-transform hover:scale-110"
                :style="{ backgroundColor: color }"
                :class="bgOverlayColor === color ? 'ring-2 ring-primary-500 ring-offset-1 dark:ring-offset-surface-800' : ''"
              ></button>
            </div>
            <input
              v-model="bgOverlayColor"
              type="color"
              class="w-7 h-7 rounded cursor-pointer border-0 p-0"
              @change="updateBgOverlay"
            />
          </div>
          <input
            v-model.number="bgOverlayOpacity"
            type="range" min="0" max="100" step="5"
            class="w-full h-2 bg-surface-200 dark:bg-surface-700 rounded-lg appearance-none cursor-pointer accent-primary-500"
            @change="updateBgOverlay"
          />
        </div>
      </div>
    </div>

    <input
      id="board-bg-upload-shared"
      type="file"
      accept="image/*"
      class="hidden"
      @change="handleBgImageUpload"
    />
  </Teleport>
</template>
