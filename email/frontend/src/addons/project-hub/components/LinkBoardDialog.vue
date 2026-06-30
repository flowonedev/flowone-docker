<script setup>
import { ref, computed, onMounted } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'

const props = defineProps({
  folderId: { type: [Number, String], required: true },
  folderName: { type: String, default: '' },
})

const emit = defineEmits(['close', 'linked'])

const hubStore = useProjectHubStore()
const boardsStore = useBoardsStore()

const loading = ref(true)
const linking = ref(false)
const searchQuery = ref('')
const linkedBoardIds = ref(new Set())

const allBoards = computed(() => boardsStore.boards || [])

const availableBoards = computed(() => {
  return allBoards.value.filter(b => {
    if (linkedBoardIds.value.has(b.id)) return false
    if (!searchQuery.value) return true
    return b.name.toLowerCase().includes(searchQuery.value.toLowerCase())
  })
})

const alreadyLinked = computed(() => {
  return allBoards.value.filter(b => linkedBoardIds.value.has(b.id))
})

onMounted(async () => {
  loading.value = true
  try {
    if (!boardsStore.boards?.length) {
      await boardsStore.fetchBoards()
    }
    const boards = await hubStore.fetchFolderBoards(props.folderId)
    linkedBoardIds.value = new Set(boards.map(b => b.board_id || b.id))
  } catch (err) {
    console.error('LinkBoardDialog: load error', err)
  } finally {
    loading.value = false
  }
})

async function linkBoard(boardId) {
  linking.value = true
  try {
    await hubStore.linkBoard(props.folderId, boardId)
    linkedBoardIds.value.add(boardId)
    emit('linked')
  } catch (err) {
    console.error('LinkBoardDialog: link error', err)
  } finally {
    linking.value = false
  }
}

async function unlinkBoard(boardId) {
  linking.value = true
  try {
    await hubStore.unlinkBoard(props.folderId, boardId)
    linkedBoardIds.value.delete(boardId)
    emit('linked')
  } catch (err) {
    console.error('LinkBoardDialog: unlink error', err)
  } finally {
    linking.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
      <!-- Header -->
      <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 shrink-0">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-bold text-surface-800 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">link</span>
            Link Boards to {{ folderName || 'Folder' }}
          </h3>
          <button
            class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            @click="emit('close')"
          >
            <span class="material-symbols-rounded text-surface-400">close</span>
          </button>
        </div>
        <div class="mt-3">
          <div class="relative">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-[18px]">search</span>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Search boards..."
              class="w-full pl-10 pr-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-800 dark:text-surface-200 text-sm outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
              autofocus
            />
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto p-4 space-y-4">
        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-8">
          <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
        </div>

        <template v-else>
          <!-- Already linked -->
          <div v-if="alreadyLinked.length > 0">
            <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-2 px-1">Linked boards</p>
            <div class="space-y-1">
              <div
                v-for="board in alreadyLinked"
                :key="board.id"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800"
              >
                <span
                  class="w-3 h-3 rounded shrink-0"
                  :style="{ backgroundColor: board.background_color || '#6366f1' }"
                ></span>
                <span class="flex-1 text-sm font-medium text-surface-700 dark:text-surface-300 truncate">
                  {{ board.name }}
                </span>
                <span class="text-xs text-surface-400">{{ board.card_count || 0 }} cards</span>
                <button
                  class="p-1 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg transition-colors"
                  :disabled="linking"
                  @click="unlinkBoard(board.id)"
                  title="Unlink board"
                >
                  <span class="material-symbols-rounded text-red-500 text-[16px]">link_off</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Available boards -->
          <div>
            <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-2 px-1">
              Available boards ({{ availableBoards.length }})
            </p>

            <div v-if="availableBoards.length === 0" class="text-center py-6 text-surface-400">
              <span class="material-symbols-rounded text-3xl mb-2 block">search_off</span>
              <p class="text-sm">{{ searchQuery ? 'No matching boards' : 'All boards are already linked' }}</p>
            </div>

            <div v-else class="space-y-1">
              <div
                v-for="board in availableBoards"
                :key="board.id"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-white dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-700 transition-colors"
              >
                <span
                  class="w-3 h-3 rounded shrink-0"
                  :style="{ backgroundColor: board.background_color || '#6366f1' }"
                ></span>
                <span class="flex-1 text-sm text-surface-700 dark:text-surface-300 truncate">
                  {{ board.name }}
                </span>
                <span class="text-xs text-surface-400">{{ board.card_count || 0 }} cards</span>
                <button
                  class="px-3 py-1 rounded-full text-xs font-medium bg-primary-500 hover:bg-primary-600 text-white transition-colors disabled:opacity-50"
                  :disabled="linking"
                  @click="linkBoard(board.id)"
                >
                  Link
                </button>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Footer -->
      <div class="px-5 py-3 border-t border-surface-200 dark:border-surface-700 shrink-0 flex justify-end">
        <button
          class="px-5 py-2 rounded-full text-sm font-medium bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
          @click="emit('close')"
        >
          Done
        </button>
      </div>
    </div>
  </div>
</template>
