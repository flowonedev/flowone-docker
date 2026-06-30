<script setup>
import { ref, computed, watch } from 'vue'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  open: { type: Boolean, default: false },
  todo: { type: Object, default: null }
})

const emit = defineEmits(['close', 'converted'])

const todosStore = useTodosStore()
const boardsStore = useBoardsStore()
const toast = useToastStore()

const selectedBoardId = ref(null)
const selectedListId = ref(null)
const creatingNewBoard = ref(false)
const newBoardName = ref('')
const newBoardLoading = ref(false)
const convertLoading = ref(false)

const availableBoards = computed(() => boardsStore.activeBoards || [])

const selectedBoardLists = computed(() => {
  if (!selectedBoardId.value) return []
  if (boardsStore.currentBoard?.id === selectedBoardId.value) {
    return boardsStore.currentLists
  }
  return []
})

watch(() => props.open, async (isOpen) => {
  if (!isOpen) {
    creatingNewBoard.value = false
    newBoardName.value = ''
    return
  }
  selectedBoardId.value = null
  selectedListId.value = null
  if (boardsStore.boards.length === 0) {
    await boardsStore.fetchBoards()
  }
})

async function onBoardSelect() {
  if (selectedBoardId.value) {
    await boardsStore.fetchBoard(selectedBoardId.value)
    if (boardsStore.currentLists.length > 0) {
      selectedListId.value = boardsStore.currentLists[0].id
    }
  }
}

function startCreateBoard() {
  creatingNewBoard.value = true
  newBoardName.value = ''
}

function cancelCreateBoard() {
  creatingNewBoard.value = false
  newBoardName.value = ''
}

async function createNewBoard() {
  if (!newBoardName.value.trim()) {
    toast.warning('Please enter a board name')
    return
  }
  newBoardLoading.value = true
  try {
    const board = await boardsStore.createBoard({ name: newBoardName.value.trim() })
    if (board) {
      toast.success('Board created')
      selectedBoardId.value = board.id
      await boardsStore.fetchBoard(board.id)
      if (boardsStore.currentLists.length > 0) {
        selectedListId.value = boardsStore.currentLists[0].id
      }
      creatingNewBoard.value = false
      newBoardName.value = ''
    } else {
      toast.error('Failed to create board')
    }
  } catch (e) {
    console.error('Create board error:', e)
    toast.error('Failed to create board')
  } finally {
    newBoardLoading.value = false
  }
}

async function convertToCard() {
  if (!props.todo || !selectedBoardId.value || !selectedListId.value) {
    toast.warning('Please select a board and list')
    return
  }

  convertLoading.value = true
  try {
    const todo = props.todo

    // Description carries quoted text + email reference for context inside the card.
    let description = ''
    if (todo.ref_selected_text) description += todo.ref_selected_text + '\n\n'
    if (todo.ref_subject) description += `Original email: ${todo.ref_subject}`
    if (todo.ref_from) description += `\nFrom: ${todo.ref_from}`
    if (!description) description = 'Created from todo.'

    const card = await boardsStore.createCard(selectedListId.value, {
      title: todo.title,
      description: description.trim(),
      due_date: todo.due_date
    })

    if (!card) {
      toast.error('Failed to create card')
      return
    }

    if (todo.ref_uid && todo.ref_folder) {
      const emailData = {
        email_uid: todo.ref_uid,
        email_folder: todo.ref_folder,
        email_subject: todo.ref_subject,
        email_from: todo.ref_from,
        thread_id: todo.ref_message_id
      }
      await boardsStore.linkEmailToBoard(selectedBoardId.value, emailData)
    }

    if (todo.subtodos && todo.subtodos.length > 0) {
      const checklist = await boardsStore.createChecklist(card.id, 'Subtasks')
      if (checklist) {
        for (const subtask of todo.subtodos) {
          const item = await boardsStore.addChecklistItem(checklist.id, subtask.title)
          if (item && subtask.completed) {
            await boardsStore.toggleChecklistItem(item.id, true)
          }
        }
      }
    }

    await todosStore.deleteTodo(todo.id)
    toast.success('Task converted to board card')
    emit('converted', { todo, boardId: selectedBoardId.value, card })
    emit('close')
  } catch (e) {
    console.error('Convert to card error:', e)
    toast.error('Failed to convert task')
  } finally {
    convertLoading.value = false
  }
}

function cancel() {
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="open"
        class="fixed inset-0 bg-black/50 z-[9999] flex items-center justify-center p-4"
        @click.self="cancel"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
          <div class="p-6">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">dashboard</span>
              Convert to Board Card
            </h2>

            <p class="text-sm text-surface-600 dark:text-surface-400 mb-4">
              Move this task to a board for better collaboration and tracking.
            </p>

            <div class="space-y-4">
              <div v-if="creatingNewBoard" class="space-y-3">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                  New Board Name
                </label>
                <input
                  v-model="newBoardName"
                  type="text"
                  placeholder="Enter board name..."
                  class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500"
                  @keydown.enter="createNewBoard"
                  @keydown.escape="cancelCreateBoard"
                  autofocus
                />
                <div class="flex gap-2">
                  <button
                    class="px-3 py-2 text-sm text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100"
                    @click="cancelCreateBoard"
                  >
                    Cancel
                  </button>
                  <button
                    class="px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/50 text-white rounded-full font-medium flex items-center gap-2"
                    :disabled="!newBoardName.trim() || newBoardLoading"
                    @click="createNewBoard"
                  >
                    <span v-if="newBoardLoading" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
                    {{ newBoardLoading ? 'Creating...' : 'Create Board' }}
                  </button>
                </div>
              </div>

              <div v-else>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                  Select Board
                </label>
                <select
                  v-model="selectedBoardId"
                  @change="onBoardSelect"
                  class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500"
                >
                  <option :value="null">Choose a board...</option>
                  <option v-for="board in availableBoards" :key="board.id" :value="board.id">
                    {{ board.name }}
                  </option>
                </select>

                <button
                  class="mt-2 flex items-center gap-1.5 text-sm text-primary-500 hover:text-primary-600 font-medium"
                  @click="startCreateBoard"
                >
                  <span class="material-symbols-rounded text-lg">add</span>
                  Create new board
                </button>
              </div>

              <div v-if="selectedBoardId && !creatingNewBoard">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                  Select List
                </label>
                <select
                  v-model="selectedListId"
                  class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500"
                >
                  <option :value="null">Choose a list...</option>
                  <option v-for="list in selectedBoardLists" :key="list.id" :value="list.id">
                    {{ list.name }}
                  </option>
                </select>
              </div>

              <div v-if="availableBoards.length === 0 && !creatingNewBoard" class="text-center py-4">
                <p class="text-sm text-surface-500 mb-3">No boards found.</p>
                <button
                  class="text-sm text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1.5 mx-auto"
                  @click="startCreateBoard"
                >
                  <span class="material-symbols-rounded text-lg">add</span>
                  Create your first board
                </button>
              </div>
            </div>
          </div>

          <div class="px-6 py-4 bg-surface-50 dark:bg-surface-900 flex justify-end gap-3">
            <button
              class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100"
              @click="cancel"
            >
              Cancel
            </button>
            <button
              class="px-6 py-2 bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/50 text-white rounded-full font-medium flex items-center gap-2"
              :disabled="!selectedBoardId || !selectedListId || convertLoading"
              @click="convertToCard"
            >
              <span v-if="convertLoading" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
              {{ convertLoading ? 'Converting...' : 'Convert' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
