<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useMailboxStore } from '@/stores/mailbox'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

const router = useRouter()
const boardsStore = useBoardsStore()
const todosStore = useTodosStore()
const mailbox = useMailboxStore()
const toast = useToastStore()

const boardsTabSelectedBoard = ref(null)
const boardsTabCards = ref([])
const boardsTabLoading = ref(false)
const expandedBoardCards = ref(new Set())
const boardLinkedEmails = ref([])
const addingItemToChecklist = ref(null)
const newChecklistItemTitle = ref('')
const editingChecklistItem = ref(null)
const editChecklistItemTitle = ref('')
const draggingOverCard = ref(null)
const uploadingToCard = ref(null)

const STORAGE_KEY = 'todo_panel_boards_tab'

function loadBoardsTabState() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved) {
      const data = JSON.parse(saved)
      boardsTabSelectedBoard.value = data.selectedBoard || null
      expandedBoardCards.value = new Set(data.expandedCards || [])
    }
  } catch (e) {
    console.error('Failed to load boards tab state:', e)
  }
}

function saveBoardsTabState() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      selectedBoard: boardsTabSelectedBoard.value,
      expandedCards: [...expandedBoardCards.value]
    }))
  } catch (e) {
    console.error('Failed to save boards tab state:', e)
  }
}

const boardsTabBoardsList = computed(() => boardsStore.activeBoards || [])

async function fetchBoardCards() {
  if (!boardsTabSelectedBoard.value) {
    boardsTabCards.value = []
    boardLinkedEmails.value = []
    return
  }

  boardsTabLoading.value = true
  try {
    await boardsStore.fetchBoard(boardsTabSelectedBoard.value)
    const emails = await boardsStore.getBoardEmails(boardsTabSelectedBoard.value)
    boardLinkedEmails.value = emails || []

    const cards = []
    for (const list of boardsStore.currentLists) {
      const listCards = list.cards || []
      for (const card of listCards) {
        const fullCard = await boardsStore.getCard(card.id)
        if (fullCard) {
          cards.push({ ...fullCard, listName: list.name, listId: list.id })
        }
      }
    }
    boardsTabCards.value = cards
  } catch (e) {
    console.error('Failed to fetch board cards:', e)
    toast.error('Failed to load board')
  } finally {
    boardsTabLoading.value = false
  }
}

function getBoardLinkedEmail() {
  return boardLinkedEmails.value.length > 0 ? boardLinkedEmails.value[0] : null
}

async function findEmailByMessageId(messageId) {
  try {
    const response = await api.get('/mailbox/search', {
      params: { q: `msgid:${messageId}`, all_folders: true }
    })
    if (response.data.success && response.data.data.messages?.length > 0) {
      const msg = response.data.data.messages[0]
      return { folder: msg.folder, uid: msg.uid }
    }
  } catch (e) {
    console.error('Search by message_id failed:', e)
  }
  return null
}

async function updateEmailLinkLocation(email, newFolder, newUid) {
  try {
    await api.put(`/boards/${boardsTabSelectedBoard.value}/email-link`, {
      old_uid: email.email_uid,
      old_folder: email.email_folder,
      new_uid: newUid,
      new_folder: newFolder
    })
    email.email_folder = newFolder
    email.email_uid = newUid
  } catch (e) {
    isDebugEnabled() && console.log('Failed to update email link location:', e)
  }
}

async function openLinkedEmail(email) {
  if (!email) return
  try {
    try {
      if (mailbox.currentFolder !== email.email_folder) {
        await mailbox.fetchMessages(email.email_folder)
      }
      const message = await mailbox.fetchMessage(email.email_uid)
      if (message) {
        todosStore.closePanel()
        toast.success('Opening email...')
        return
      }
    } catch (e) {
      isDebugEnabled() && console.log('Email not at original location, searching by message_id...')
    }

    if (email.thread_id) {
      const searchResult = await findEmailByMessageId(email.thread_id)
      if (searchResult) {
        updateEmailLinkLocation(email, searchResult.folder, searchResult.uid)
        if (mailbox.currentFolder !== searchResult.folder) {
          await mailbox.fetchMessages(searchResult.folder)
        }
        await mailbox.fetchMessage(searchResult.uid)
        todosStore.closePanel()
        toast.success('Email found in ' + searchResult.folder)
        return
      }
    }

    toast.error('Email not found - it may have been deleted')
  } catch (e) {
    console.error('Failed to open email:', e)
    toast.error('Email not found - it may have been moved or deleted')
  }
}

async function onBoardsTabBoardSelect() {
  saveBoardsTabState()
  await fetchBoardCards()
}

function toggleBoardCard(cardId) {
  if (expandedBoardCards.value.has(cardId)) {
    expandedBoardCards.value.delete(cardId)
  } else {
    expandedBoardCards.value.add(cardId)
  }
  expandedBoardCards.value = new Set(expandedBoardCards.value)
  saveBoardsTabState()
}

function isBoardCardExpanded(cardId) {
  return expandedBoardCards.value.has(cardId)
}

async function toggleBoardChecklistItem(item) {
  try {
    await boardsStore.toggleChecklistItem(item.id, !item.completed)
    for (const card of boardsTabCards.value) {
      for (const checklist of card.checklists) {
        const foundItem = checklist.items?.find(i => i.id === item.id)
        if (foundItem) {
          foundItem.completed = !item.completed
          break
        }
      }
    }
    boardsTabCards.value = [...boardsTabCards.value]
  } catch (e) {
    console.error('Failed to toggle checklist item:', e)
    toast.error('Failed to update item')
  }
}

function startAddChecklistItem(checklistId) {
  addingItemToChecklist.value = checklistId
  newChecklistItemTitle.value = ''
}

async function addChecklistItem(checklistId) {
  if (!newChecklistItemTitle.value.trim()) {
    addingItemToChecklist.value = null
    return
  }
  try {
    const newItem = await boardsStore.addChecklistItem(checklistId, newChecklistItemTitle.value.trim())
    if (newItem) {
      for (const card of boardsTabCards.value) {
        const checklist = card.checklists?.find(c => c.id === checklistId)
        if (checklist) {
          if (!checklist.items) checklist.items = []
          checklist.items.push(newItem)
          break
        }
      }
      boardsTabCards.value = [...boardsTabCards.value]
      toast.success('Item added')
    }
  } catch (e) {
    console.error('Failed to add checklist item:', e)
    toast.error('Failed to add item')
  }
  addingItemToChecklist.value = null
  newChecklistItemTitle.value = ''
}

function cancelAddChecklistItem() {
  addingItemToChecklist.value = null
  newChecklistItemTitle.value = ''
}

function handleChecklistItemKeydown(e, checklistId) {
  if (e.key === 'Enter') addChecklistItem(checklistId)
  else if (e.key === 'Escape') cancelAddChecklistItem()
}

function startEditChecklistItem(item) {
  editingChecklistItem.value = item.id
  editChecklistItemTitle.value = item.title
}

async function saveChecklistItemEdit(item) {
  if (!editChecklistItemTitle.value.trim()) {
    editingChecklistItem.value = null
    return
  }
  try {
    const updated = await boardsStore.updateChecklistItem(item.id, { title: editChecklistItemTitle.value.trim() })
    if (updated) {
      for (const card of boardsTabCards.value) {
        for (const checklist of card.checklists || []) {
          const foundItem = checklist.items?.find(i => i.id === item.id)
          if (foundItem) {
            foundItem.title = editChecklistItemTitle.value.trim()
            break
          }
        }
      }
      boardsTabCards.value = [...boardsTabCards.value]
    }
  } catch (e) {
    console.error('Failed to update checklist item:', e)
    toast.error('Failed to update item')
  }
  editingChecklistItem.value = null
}

function cancelChecklistItemEdit() {
  editingChecklistItem.value = null
}

function handleChecklistItemEditKeydown(e, item) {
  if (e.key === 'Enter') saveChecklistItemEdit(item)
  else if (e.key === 'Escape') cancelChecklistItemEdit()
}

async function deleteChecklistItem(item, checklistId) {
  try {
    const success = await boardsStore.deleteChecklistItem(item.id)
    if (success) {
      for (const card of boardsTabCards.value) {
        const checklist = card.checklists?.find(c => c.id === checklistId)
        if (checklist && checklist.items) {
          checklist.items = checklist.items.filter(i => i.id !== item.id)
          break
        }
      }
      boardsTabCards.value = [...boardsTabCards.value]
      toast.success('Item deleted')
    }
  } catch (e) {
    console.error('Failed to delete checklist item:', e)
    toast.error('Failed to delete item')
  }
}

function onCardDragOver(e, cardId) {
  e.preventDefault()
  draggingOverCard.value = cardId
}

function onCardDragLeave(e) {
  if (!e.currentTarget.contains(e.relatedTarget)) {
    draggingOverCard.value = null
  }
}

async function onCardDrop(e, card) {
  e.preventDefault()
  draggingOverCard.value = null

  const files = Array.from(e.dataTransfer?.files || [])
  const imageFiles = files.filter(f => f.type.startsWith('image/'))

  if (imageFiles.length === 0) {
    toast.warning('Please drop image files only')
    return
  }

  uploadingToCard.value = card.id
  try {
    for (const file of imageFiles) {
      const attachment = await boardsStore.uploadAttachment(card.id, file)
      if (attachment) {
        if (!card.attachments) card.attachments = []
        card.attachments.push(attachment)
      }
    }
    boardsTabCards.value = [...boardsTabCards.value]
    toast.success(`${imageFiles.length} image(s) uploaded`)
  } catch (e) {
    console.error('Failed to upload images:', e)
    toast.error('Failed to upload images')
  } finally {
    uploadingToCard.value = null
  }
}

function getAttachmentThumbnail(att) {
  if (!att) return null
  if (att.thumbnail_url) return att.thumbnail_url
  const name = (att.name || att.original_name || att.filename || '').toLowerCase()
  const mimeType = att.mime_type || ''
  const isImage = mimeType.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(name)
  if (isImage && att.drive_file_id) return `/api/drive/files/${att.drive_file_id}/preview`
  if (isImage && att.url && att.url.startsWith('http')) return att.url
  return null
}

function openAttachment(att) {
  if (!att) return
  if (att.folder_id) {
    router.push({ name: 'drive', query: { folder: att.folder_id } })
  } else if (att.drive_file_id) {
    router.push({ name: 'drive', query: { file: att.drive_file_id } })
  } else if (att.url && att.url.startsWith('http')) {
    window.open(att.url, '_blank')
  }
}

function getChecklistProgress(checklist) {
  if (!checklist.items || checklist.items.length === 0) return { done: 0, total: 0, percent: 0 }
  const done = checklist.items.filter(i => i.completed).length
  const total = checklist.items.length
  return { done, total, percent: Math.round((done / total) * 100) }
}

function getCardProgress(card) {
  if (!card.checklists || card.checklists.length === 0) return null
  let done = 0
  let total = 0
  for (const checklist of card.checklists) {
    if (checklist.items) {
      done += checklist.items.filter(i => i.completed).length
      total += checklist.items.length
    }
  }
  if (total === 0) return null
  return { done, total, percent: Math.round((done / total) * 100) }
}

function formatCardDueDate(dateStr) {
  if (!dateStr) return null
  const date = new Date(dateStr)
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const dueDate = new Date(date.getFullYear(), date.getMonth(), date.getDate())
  const diffDays = Math.ceil((dueDate - today) / (1000 * 60 * 60 * 24))

  if (diffDays < 0) return { text: 'Overdue', class: 'bg-red-500/20 text-red-600 dark:text-red-400' }
  if (diffDays === 0) return { text: 'Today', class: 'bg-amber-500/20 text-amber-600 dark:text-amber-400' }
  if (diffDays === 1) return { text: 'Tomorrow', class: 'bg-amber-500/20 text-amber-600 dark:text-amber-400' }
  if (diffDays < 7) return { text: date.toLocaleDateString([], { weekday: 'short' }), class: 'bg-blue-500/20 text-blue-600 dark:text-blue-400' }
  return { text: date.toLocaleDateString([], { month: 'short', day: 'numeric' }), class: 'bg-surface-500/20 text-surface-600 dark:text-surface-400' }
}

function goToBoard(boardId) {
  router.push(`/boards/${boardId}`)
  todosStore.closePanel()
}

function goToCard(card) {
  router.push(`/boards/${boardsTabSelectedBoard.value}?card=${card.id}`)
  todosStore.closePanel()
}

loadBoardsTabState()

onMounted(() => {
  if (boardsStore.boards.length === 0) {
    boardsStore.fetchBoards()
  }
  if (boardsTabSelectedBoard.value) {
    fetchBoardCards()
  }
})

watch(() => todosStore.panelOpen, (isOpen) => {
  if (isOpen && boardsStore.boards.length === 0) {
    boardsStore.fetchBoards()
  }
  if (isOpen && boardsTabSelectedBoard.value) {
    fetchBoardCards()
  }
})

watch(() => todosStore.pendingBoardId, async (boardId) => {
  if (boardId) {
    if (boardsStore.boards.length === 0) {
      await boardsStore.fetchBoards()
    }
    boardsTabSelectedBoard.value = boardId
    saveBoardsTabState()
    await fetchBoardCards()
    todosStore.clearPendingBoard()
  }
}, { immediate: true })

defineExpose({ selectedBoard: boardsTabSelectedBoard, goToBoard })
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden">
    <div class="p-3 border-b border-surface-200 dark:border-surface-700">
      <select
        v-model="boardsTabSelectedBoard"
        @change="onBoardsTabBoardSelect"
        class="w-full px-3 py-2.5 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500"
      >
        <option :value="null">Select a board...</option>
        <option v-for="board in boardsTabBoardsList" :key="board.id" :value="board.id">
          {{ board.name }}
        </option>
      </select>
    </div>

    <div class="flex-1 overflow-y-auto p-3 space-y-2 bg-surface-50 dark:bg-transparent">
      <div v-if="boardsTabLoading" class="flex justify-center py-8">
        <span class="material-symbols-rounded text-3xl text-primary-500 animate-spin">progress_activity</span>
      </div>

      <div v-else-if="!boardsTabSelectedBoard" class="text-center py-12 px-4">
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 block mb-3">dashboard</span>
        <p class="text-surface-600 dark:text-surface-400">Select a board above</p>
        <p class="text-sm text-surface-500 mt-1">View and manage tasks from your boards</p>
      </div>

      <div v-else-if="boardsTabCards.length === 0" class="text-center py-12 px-4">
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 block mb-3">inbox</span>
        <p class="text-surface-600 dark:text-surface-400">No cards in this board</p>
        <button class="mt-3 text-sm text-primary-500 hover:text-primary-600 font-medium" @click="goToBoard(boardsTabSelectedBoard)">
          Open board to add cards →
        </button>
      </div>

      <template v-else>
        <div
          v-for="card in boardsTabCards"
          :key="card.id"
          class="bg-white dark:bg-surface-800 rounded-xl overflow-hidden border border-surface-200 dark:border-transparent shadow-sm"
        >
          <div class="p-3">
            <div class="flex items-start gap-2">
              <button
                class="mt-0.5 w-5 h-5 flex items-center justify-center shrink-0 text-surface-400 hover:text-surface-600 transition-colors"
                @click="toggleBoardCard(card.id)"
              >
                <span class="material-symbols-rounded text-lg transition-transform" :class="{ '-rotate-90': !isBoardCardExpanded(card.id) }">
                  expand_more
                </span>
              </button>

              <div class="flex-1 min-w-0">
                <div class="flex items-start gap-2 flex-wrap">
                  <span
                    class="text-sm font-medium text-surface-900 dark:text-surface-100 cursor-pointer hover:text-surface-600"
                    @click="toggleBoardCard(card.id)"
                  >
                    {{ card.title }}
                  </span>

                  <span class="px-1.5 py-0.5 text-xs bg-surface-100 dark:bg-surface-700 text-surface-500 rounded">
                    {{ card.listName }}
                  </span>

                  <span
                    v-if="!isBoardCardExpanded(card.id) && getCardProgress(card)"
                    class="px-1.5 py-0.5 text-xs bg-surface-100 dark:bg-surface-700 text-surface-500 rounded-full"
                  >
                    {{ getCardProgress(card).done }}/{{ getCardProgress(card).total }}
                  </span>

                  <span
                    v-if="card.due_date && formatCardDueDate(card.due_date)"
                    class="px-1.5 py-0.5 text-xs rounded-full"
                    :class="formatCardDueDate(card.due_date).class"
                  >
                    {{ formatCardDueDate(card.due_date).text }}
                  </span>
                </div>

                <button
                  v-if="getBoardLinkedEmail()"
                  class="mt-1 flex items-center gap-1.5 text-xs text-primary-500 hover:text-primary-600 transition-colors group/email"
                  @click.stop="openLinkedEmail(getBoardLinkedEmail())"
                >
                  <span class="material-symbols-rounded text-sm">mail</span>
                  <span class="truncate max-w-[200px] group-hover/email:underline">
                    {{ getBoardLinkedEmail().email_subject || 'Linked email' }}
                  </span>
                  <span class="material-symbols-rounded text-xs opacity-0 group-hover/email:opacity-100 transition-opacity">open_in_new</span>
                </button>

                <p
                  v-if="!isBoardCardExpanded(card.id) && card.description && !getBoardLinkedEmail()"
                  class="mt-1 text-xs text-surface-500 dark:text-surface-400 truncate"
                >
                  {{ card.description }}
                </p>
              </div>

              <button
                class="p-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-primary-500"
                title="Open card"
                @click="goToCard(card)"
              >
                <span class="material-symbols-rounded text-lg">open_in_new</span>
              </button>
            </div>
          </div>

          <div
            v-if="isBoardCardExpanded(card.id) && card.checklists?.length"
            class="border-t border-surface-200 dark:border-surface-700 px-3 py-2 bg-surface-50 dark:bg-surface-800/50 space-y-3"
          >
            <div v-for="checklist in card.checklists" :key="checklist.id">
              <div class="flex items-center gap-2 mb-1.5">
                <span class="material-symbols-rounded text-sm text-surface-400">checklist</span>
                <span class="text-xs font-medium text-surface-700 dark:text-surface-300">{{ checklist.name }}</span>
                <span class="text-xs text-surface-500">
                  {{ getChecklistProgress(checklist).done }}/{{ getChecklistProgress(checklist).total }}
                </span>
                <div class="flex-1 h-1 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                  <div
                    class="h-full bg-primary-500 transition-all duration-300"
                    :style="{ width: getChecklistProgress(checklist).percent + '%' }"
                  ></div>
                </div>
              </div>

              <div class="space-y-1 pl-5">
                <div
                  v-for="item in checklist.items"
                  :key="item.id"
                  class="flex items-center gap-2 py-1 group/item"
                >
                  <button
                    class="w-4 h-4 rounded border-2 flex items-center justify-center shrink-0 transition-colors"
                    :class="item.completed ? 'bg-primary-500 border-primary-500 text-white' : 'border-surface-400 dark:border-surface-500 hover:border-primary-500'"
                    @click="toggleBoardChecklistItem(item)"
                  >
                    <span v-if="item.completed" class="material-symbols-rounded text-xs">check</span>
                  </button>

                  <input
                    v-if="editingChecklistItem === item.id"
                    v-model="editChecklistItemTitle"
                    type="text"
                    class="flex-1 px-2 py-0.5 text-xs bg-white dark:bg-surface-700 border border-primary-500/50 rounded outline-none text-surface-800 dark:text-surface-200"
                    @keydown="(e) => handleChecklistItemEditKeydown(e, item)"
                    @blur="saveChecklistItemEdit(item)"
                    autofocus
                  />
                  <span
                    v-else
                    class="text-xs flex-1 cursor-pointer"
                    :class="item.completed ? 'text-surface-400 dark:text-surface-500 line-through' : 'text-surface-700 dark:text-surface-300'"
                    @dblclick="startEditChecklistItem(item)"
                  >
                    {{ item.title }}
                  </span>

                  <div class="flex items-center gap-0.5 opacity-0 group-hover/item:opacity-100 transition-opacity">
                    <button
                      v-if="editingChecklistItem !== item.id"
                      class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
                      title="Edit"
                      @click="startEditChecklistItem(item)"
                    >
                      <span class="material-symbols-rounded text-sm text-surface-400 hover:text-surface-600">edit</span>
                    </button>
                    <button class="p-0.5 rounded hover:bg-red-500/20" title="Delete" @click="deleteChecklistItem(item, checklist.id)">
                      <span class="material-symbols-rounded text-sm text-surface-400 hover:text-red-500">delete</span>
                    </button>
                  </div>
                </div>

                <div v-if="addingItemToChecklist === checklist.id" class="flex items-center gap-2 py-1">
                  <span class="material-symbols-rounded text-sm text-primary-500">add</span>
                  <input
                    v-model="newChecklistItemTitle"
                    type="text"
                    placeholder="Add item..."
                    class="flex-1 px-2 py-1 text-xs bg-white dark:bg-surface-700 border border-primary-500/50 rounded outline-none text-surface-800 dark:text-surface-200"
                    @keydown="(e) => handleChecklistItemKeydown(e, checklist.id)"
                    @blur="addChecklistItem(checklist.id)"
                    autofocus
                  />
                  <button class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-600" @click="cancelAddChecklistItem">
                    <span class="material-symbols-rounded text-sm text-surface-500">close</span>
                  </button>
                </div>

                <button
                  v-else
                  class="flex items-center gap-1.5 w-full py-1 text-xs text-surface-500 hover:text-primary-500 transition-colors"
                  @click="startAddChecklistItem(checklist.id)"
                >
                  <span class="material-symbols-rounded text-sm">add</span>
                  Add item
                </button>
              </div>
            </div>
          </div>

          <div
            v-else-if="isBoardCardExpanded(card.id) && (!card.checklists || card.checklists.length === 0)"
            class="border-t border-surface-200 dark:border-surface-700 px-3 py-3 bg-surface-50 dark:bg-surface-800/50"
          >
            <p class="text-xs text-surface-500 text-center">No checklists on this card</p>
          </div>

          <div
            v-if="isBoardCardExpanded(card.id)"
            class="border-t border-surface-200 dark:border-surface-700 px-3 py-2 transition-colors"
            :class="draggingOverCard === card.id ? 'bg-primary-500/10 border-primary-500/30' : 'bg-surface-50 dark:bg-surface-800/50'"
            @dragover="onCardDragOver($event, card.id)"
            @dragleave="onCardDragLeave($event)"
            @drop="onCardDrop($event, card)"
          >
            <div
              class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg border-2 border-dashed transition-colors"
              :class="draggingOverCard === card.id ? 'border-primary-500 bg-primary-500/10' : 'border-surface-300 dark:border-surface-600 hover:border-primary-500/50'"
            >
              <span v-if="uploadingToCard === card.id" class="material-symbols-rounded text-sm text-primary-500 animate-spin">progress_activity</span>
              <span v-else class="material-symbols-rounded text-sm text-surface-400">add_photo_alternate</span>
              <span class="text-xs text-surface-500">
                {{ uploadingToCard === card.id ? 'Uploading...' : 'Drop images here' }}
              </span>
            </div>

            <div v-if="card.attachments?.length" class="mt-2 flex items-center gap-2 flex-wrap">
              <div
                v-for="att in card.attachments"
                :key="att.id"
                class="w-[3.25rem] h-[3.25rem] rounded-lg overflow-hidden bg-surface-200 dark:bg-surface-600 flex-shrink-0 cursor-pointer hover:ring-2 hover:ring-primary-400 transition-shadow"
                :title="att.name || att.original_name || att.filename"
                @click="openAttachment(att)"
              >
                <img
                  v-if="getAttachmentThumbnail(att)"
                  :src="getAttachmentThumbnail(att)"
                  :alt="att.name || att.filename"
                  class="w-full h-full object-cover"
                  @error="$event.target.style.display = 'none'"
                />
                <span v-else class="w-full h-full flex items-center justify-center text-surface-400">
                  <span class="material-symbols-rounded text-xl">description</span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 bg-white dark:bg-transparent">
      <button
        v-if="boardsTabSelectedBoard"
        class="w-full px-4 py-2 text-sm bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300 rounded-lg font-medium flex items-center justify-center gap-2 transition-colors"
        @click="goToBoard(boardsTabSelectedBoard)"
      >
        <span class="material-symbols-rounded text-lg">open_in_new</span>
        Open Full Board
      </button>
      <p v-else class="text-xs text-surface-500 text-center">
        Select a board to view its tasks
      </p>
    </div>
  </div>
</template>
