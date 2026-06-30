<template>
  <div class="boardpro-mood-ref w-[380px] min-w-[320px] h-full bg-surface-50 dark:bg-surface-900 flex flex-col">
    <!-- Loading -->
    <div v-if="moodLoading" class="flex items-center justify-center h-full">
      <span class="material-symbols-rounded animate-spin text-2xl text-surface-400">progress_activity</span>
    </div>

    <!-- No board selected — picker -->
    <div v-else-if="!selectedMoodBoard" class="flex flex-col items-center justify-center h-full p-6 text-center">
      <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-3">dashboard</span>
      <p class="text-sm text-surface-500 dark:text-surface-400 mb-4">Select a Mood Board to reference alongside your board</p>

      <div v-if="availableMoodBoards.length === 0" class="text-xs text-surface-400">
        <p>No mood boards found.</p>
        <p class="mt-1">Create a mood board first to use split view.</p>
      </div>

      <select
        v-else
        v-model="selectedMoodBoardId"
        class="px-4 py-2 text-sm rounded-xl border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500/40 focus:border-primary-500 outline-none min-w-[200px]"
        @change="loadMoodBoard"
      >
        <option :value="null" disabled>Choose a Mood Board...</option>
        <option v-for="mb in availableMoodBoards" :key="mb.id" :value="mb.id">
          {{ mb.name }}
        </option>
      </select>
    </div>

    <!-- Board loaded — show editable content panel -->
    <template v-else>
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
        <div class="flex items-center gap-2 min-w-0">
          <div
            class="w-5 h-5 rounded flex-shrink-0"
            :style="{ backgroundColor: selectedMoodBoard.background_color || '#333' }"
          ></div>
          <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 truncate">
            {{ selectedMoodBoard.name }}
          </h4>
        </div>
        <div class="flex items-center gap-1 flex-shrink-0">
          <button
            class="px-2.5 py-1 text-xs rounded-lg bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-500/20 transition-colors flex items-center gap-1"
            @click="openInMoodBoard"
            title="Open in full Mood Board editor"
          >
            <span class="material-symbols-rounded text-sm">open_in_new</span>
            Open
          </button>
          <button
            class="p-1 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
            @click="closeMoodBoard"
            title="Close"
          >
            <span class="material-symbols-rounded text-base">close</span>
          </button>
        </div>
      </div>

      <!-- Shared content panel with editing enabled -->
      <MoodContentPanel
        :items="allItems"
        :editable="true"
        :board-id="selectedMoodBoard?.id"
        @update-item="onUpdateItem"
        @replace-image="onReplaceImage"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import MoodContentPanel from '@/addons/moodboards/components/MoodContentPanel.vue'
import api from '@/services/api'

const router = useRouter()
const moodStore = useMoodBoardsStore()

const moodLoading = ref(false)
const selectedMoodBoardId = ref(null)
const selectedMoodBoard = ref(null)
const allItems = ref([])

const availableMoodBoards = computed(() => moodStore.boards || [])

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
onMounted(async () => {
  moodLoading.value = true
  try {
    await moodStore.fetchBoards()
  } finally {
    moodLoading.value = false
  }
})

async function loadMoodBoard() {
  if (!selectedMoodBoardId.value) return
  moodLoading.value = true
  try {
    const board = await moodStore.fetchBoard(selectedMoodBoardId.value)
    if (board) {
      selectedMoodBoard.value = board
      allItems.value = board.items || []
    }
  } catch (e) {
    console.error('[BoardPro] Failed to load mood board:', e)
  } finally {
    moodLoading.value = false
  }
}

function closeMoodBoard() {
  selectedMoodBoard.value = null
  selectedMoodBoardId.value = null
  allItems.value = []
}

function openInMoodBoard() {
  if (selectedMoodBoard.value?.id) {
    router.push(`/mood/${selectedMoodBoard.value.id}`)
  }
}

// ---------------------------------------------------------------------------
// Edit handlers — use direct API calls since the mood board
// is NOT loaded as moodStore.currentBoard from the Kanban context
// ---------------------------------------------------------------------------
async function onUpdateItem({ itemId, data }) {
  const boardId = selectedMoodBoard.value?.id
  if (!boardId) return
  try {
    const res = await api.put(`/mood-boards/${boardId}/items/${itemId}`, data)
    // Update local copy
    const item = allItems.value.find(i => i.id === itemId)
    if (item) {
      Object.assign(item, data)
      // Also apply server response if available
      if (res.data?.data) Object.assign(item, res.data.data)
    }
  } catch (e) {
    console.error('[MoodSplit] Failed to update item:', e)
  }
}

async function onReplaceImage({ itemId, file }) {
  const boardId = selectedMoodBoard.value?.id
  if (!boardId) return
  try {
    // Upload via mood board upload API
    const formData = new FormData()
    formData.append('files[]', file)
    const uploadRes = await api.post(`/mood-boards/${boardId}/upload`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    if (uploadRes.data?.success && uploadRes.data.data?.uploads?.length > 0) {
      const newUrl = uploadRes.data.data.uploads[0].url
      // Update the item with the new image URL
      await api.put(`/mood-boards/${boardId}/items/${itemId}`, {
        image_url: newUrl,
        thumbnail_url: null,
      })
      // Update local copy
      const item = allItems.value.find(i => i.id === itemId)
      if (item) {
        item.image_url = newUrl
        item.thumbnail_url = null
      }
    }
  } catch (e) {
    console.error('[MoodSplit] Failed to replace image:', e)
  }
}
</script>
