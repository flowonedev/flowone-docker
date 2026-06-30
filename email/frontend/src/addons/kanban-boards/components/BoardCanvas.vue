<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import { useThemeStore } from '@/stores/theme'
import { isDebugEnabled } from '@/utils/debug'
import BoardCard from './BoardCard.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'

const emit = defineEmits(['open-card'])

const boardsStore = useBoardsStore()
const toast = useToastStore()
const themeStore = useThemeStore()

// Mobile detection
const isMobile = ref(false)

function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

// Confirm modal state
const confirmModal = ref({
  show: false,
  title: '',
  message: '',
  danger: false,
  onConfirm: null
})

function showConfirm(options) {
  return new Promise((resolve) => {
    confirmModal.value = {
      show: true,
      title: options.title || 'Confirm',
      message: options.message || 'Are you sure?',
      danger: options.danger || false,
      onConfirm: () => {
        confirmModal.value.show = false
        resolve(true)
      }
    }
  })
}

function cancelConfirm() {
  confirmModal.value.show = false
}

// State
const addingList = ref(false)
const newListName = ref('')
const addingCardToList = ref(null)
const newCardTitle = ref('')
const editingListId = ref(null)
const editingListName = ref('')
const draggingCard = ref(null)
const draggingList = ref(null)
const dragOverListId = ref(null)
const dragOverCardPosition = ref(null)

// Card context menu
const cardContextMenu = ref({ show: false, x: 0, y: 0, card: null, listId: null })
const showDatePicker = ref(false)
const showCardColorPicker = ref(false)
const showLabelsSubmenu = ref(false)
const showMemberSubmenu = ref(false)
const showMoveSubmenu = ref(false)
const contextDueDate = ref('')
const contextMembers = computed(() => boardsStore.currentMembers || [])
const contextLabels = computed(() => boardsStore.currentLabels || [])

function visibleCards(list) {
  return (list.cards || []).filter(card => !card.parent_card_id)
}

// Label editor
const labelEditor = ref({
  show: false,
  label: null,
  name: '',
  color: '#f97316'
})
const labelColors = [
  '#f97316', '#3b82f6', '#22c55e', '#ef4444', '#a855f7',
  '#ec4899', '#eab308', '#14b8a6', '#6366f1', '#78716c'
]

const listColors = [
  '#ef4444', '#f97316', '#eab308', '#22c55e', '#14b8a6',
  '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#78716c',
  '#991b1b', '#854d0e', '#166534', '#1e40af', '#7e22ce',
]

const cardColors = [
  '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
  '#22c55e', '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9',
  '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef',
  '#ec4899', '#f43f5e', '#78716c', '#64748b', '#1e293b',
  '#991b1b', '#9a3412', '#92400e', '#854d0e', '#3f6212',
  '#166534', '#065f46', '#115e59', '#155e75', '#075985',
  '#1e40af', '#3730a3', '#5b21b6', '#7e22ce', '#a21caf',
  '#9d174d', '#be123c', '#44403c', '#334155', '#0f172a'
]

// List financial settings
const listSettingsModal = ref({
  show: false,
  list: null,
  expected_amount: '',
  invoice_date: '',
  is_milestone: false,
  currency: 'HUF'
})

const availableCurrencies = ['HUF', 'EUR', 'USD', 'RON']

// Methods
function startAddList() {
  addingList.value = true
  nextTick(() => {
    document.getElementById('new-list-input')?.focus()
  })
}

async function addList() {
  if (!newListName.value.trim()) {
    addingList.value = false
    return
  }
  
  const list = await boardsStore.createList(boardsStore.currentBoard.id, {
    name: newListName.value.trim()
  })
  
  if (list) {
    newListName.value = ''
    addingList.value = false
  } else {
    toast.error('Failed to create list')
  }
}

function cancelAddList() {
  addingList.value = false
  newListName.value = ''
}

function startAddCard(listId) {
  addingCardToList.value = listId
  nextTick(() => {
    document.getElementById(`new-card-input-${listId}`)?.focus()
  })
}

async function addCard(listId) {
  if (!newCardTitle.value.trim()) {
    addingCardToList.value = null
    return
  }
  
  const card = await boardsStore.createCard(listId, {
    title: newCardTitle.value.trim()
  })
  
  if (card) {
    newCardTitle.value = ''
    // Keep adding mode open for quick entry
    nextTick(() => {
      document.getElementById(`new-card-input-${listId}`)?.focus()
    })
  } else {
    toast.error('Failed to create card')
  }
}

function cancelAddCard() {
  addingCardToList.value = null
  newCardTitle.value = ''
}

function startEditList(list) {
  editingListId.value = list.id
  editingListName.value = list.name
  nextTick(() => {
    document.getElementById(`edit-list-input-${list.id}`)?.focus()
  })
}

async function saveListName(listId) {
  if (!editingListName.value.trim()) {
    editingListId.value = null
    return
  }
  
  await boardsStore.updateList(listId, { name: editingListName.value.trim() })
  editingListId.value = null
}

async function deleteList(list) {
  if (list.cards.length > 0) {
    const confirmed = await showConfirm({
      title: 'Delete List',
      message: `Delete "${list.name}" and all ${list.cards.length} cards? This cannot be undone.`,
      danger: true
    })
    if (!confirmed) return
  }
  
  if (await boardsStore.deleteList(list.id)) {
    toast.success('List deleted')
  }
}

async function archiveList(list) {
  if (await boardsStore.updateList(list.id, { archived: true })) {
    toast.success('List archived')
  }
}

// List financial settings
function openListSettings(list) {
  listSettingsModal.value = {
    show: true,
    list: list,
    expected_amount: list.expected_amount || '',
    invoice_date: list.invoice_date || '',
    is_milestone: list.is_milestone || false,
    currency: list.currency || 'HUF'
  }
}

async function saveListSettings() {
  const { list, expected_amount, invoice_date, is_milestone, currency } = listSettingsModal.value
  if (!list) return
  
  const updates = {
    expected_amount: expected_amount ? parseFloat(expected_amount) : null,
    invoice_date: invoice_date || null,
    is_milestone: is_milestone,
    currency: currency || 'HUF'
  }
  
  if (await boardsStore.updateList(list.id, updates)) {
    toast.success('List settings saved')
    listSettingsModal.value.show = false
  } else {
    toast.error('Failed to save list settings')
  }
}

// Drag and drop - Cards
function onCardDragStart(e, card, listId) {
  isDebugEnabled() && console.log('[DragStart] Card:', card.id, card.title, 'from list:', listId)
  draggingCard.value = { card: { ...card }, fromListId: listId } // Clone the card object
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'card', cardId: card.id, listId }))
  e.target.classList.add('opacity-50')
}

function onCardDragEnd(e) {
  e.target.classList.remove('opacity-50')
  draggingCard.value = null
  dragOverListId.value = null
  dragOverCardPosition.value = null
}

function onListDragOver(e, listId) {
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  dragOverListId.value = listId
}

function onListDragLeave(e) {
  // Only clear if leaving the list entirely
  if (!e.currentTarget.contains(e.relatedTarget)) {
    dragOverListId.value = null
    dragOverCardPosition.value = null
  }
}

function onCardDragOver(e, cardIndex, listId) {
  e.preventDefault()
  e.stopPropagation()
  dragOverListId.value = listId
  dragOverCardPosition.value = cardIndex
}

async function onListDrop(e, targetListId) {
  e.preventDefault()
  
  if (!draggingCard.value) {
    isDebugEnabled() && console.log('[Drop] No dragging card, ignoring')
    return
  }
  
  const { card, fromListId } = draggingCard.value
  let position = dragOverCardPosition.value
  
  isDebugEnabled() && console.log('[Drop] Card:', card.id, card.title, 'from:', fromListId, 'to:', targetListId, 'position:', position)
  
  // If no specific position, add to end
  const targetList = boardsStore.currentBoard.lists.find(l => l.id === targetListId)
  if (position === null && targetList) {
    position = targetList.cards.length
  }
  
  // Move the card
  if (fromListId !== targetListId || position !== null) {
    isDebugEnabled() && console.log('[Drop] Moving card', card.id, 'to list', targetListId, 'at position', position)
    await boardsStore.moveCard(card.id, targetListId, position)
  } else {
    isDebugEnabled() && console.log('[Drop] Same list and no position change, skipping')
  }
  
  draggingCard.value = null
  dragOverListId.value = null
  dragOverCardPosition.value = null
}

// Drag and drop - Lists
function onListDragStart(e, list) {
  draggingList.value = list
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'list', listId: list.id }))
  e.target.classList.add('opacity-50')
}

function onListDragEndList(e) {
  e.target.classList.remove('opacity-50')
  draggingList.value = null
}

function onListDropZone(e, targetIndex) {
  e.preventDefault()
  
  if (!draggingList.value) return
  
  const listIds = boardsStore.currentBoard.lists.map(l => l.id)
  const currentIndex = listIds.indexOf(draggingList.value.id)
  
  if (currentIndex === targetIndex) return
  
  // Reorder
  listIds.splice(currentIndex, 1)
  listIds.splice(targetIndex, 0, draggingList.value.id)
  
  boardsStore.reorderLists(boardsStore.currentBoard.id, listIds)
  draggingList.value = null
}

function openCard(card) {
  emit('open-card', card)
}

// Card context menu
function showCardContext(e, card, listId) {
  e.preventDefault()
  e.stopPropagation()
  showDatePicker.value = false
  showCardColorPicker.value = false

  const menuWidth = 208
  const menuHeight = 380
  const pad = 8
  const vw = window.innerWidth
  const vh = window.innerHeight

  let x = e.clientX
  let y = e.clientY
  if (x + menuWidth + pad > vw) x = vw - menuWidth - pad
  if (y + menuHeight + pad > vh) y = vh - menuHeight - pad
  if (x < pad) x = pad
  if (y < pad) y = pad

  cardContextMenu.value = { show: true, x, y, card, listId }
}

function closeCardContext() {
  cardContextMenu.value.show = false
  showDatePicker.value = false
  showCardColorPicker.value = false
  showLabelsSubmenu.value = false
  showMemberSubmenu.value = false
  showMoveSubmenu.value = false
}

async function moveCardToList(targetListId) {
  if (!cardContextMenu.value.card) return
  await boardsStore.moveCard(cardContextMenu.value.card.id, targetListId)
  closeCardContext()
}

async function archiveCard() {
  if (!cardContextMenu.value.card) return
  await boardsStore.updateCard(cardContextMenu.value.card.id, { archived: true })
  closeCardContext()
  toast.success('Card archived')
}

async function deleteCard() {
  if (!cardContextMenu.value.card) return
  
  const confirmed = await showConfirm({
    title: 'Delete Card',
    message: 'Delete this card permanently? This cannot be undone.',
    danger: true
  })
  if (!confirmed) return
  
  await boardsStore.deleteCard(cardContextMenu.value.card.id)
  closeCardContext()
  toast.success('Card deleted')
}

function openDatePicker() {
  if (!cardContextMenu.value.card) return
  contextDueDate.value = cardContextMenu.value.card.due_date 
    ? cardContextMenu.value.card.due_date.substring(0, 10) 
    : ''
  showCardColorPicker.value = false
  showDatePicker.value = true
}

async function saveDueDate() {
  if (!cardContextMenu.value.card) return
  await boardsStore.updateCard(cardContextMenu.value.card.id, { 
    due_date: contextDueDate.value || null 
  })
  showDatePicker.value = false
  closeCardContext()
  toast.success('Due date updated')
}

async function removeDueDate() {
  if (!cardContextMenu.value.card) return
  await boardsStore.updateCard(cardContextMenu.value.card.id, { due_date: null })
  showDatePicker.value = false
  closeCardContext()
  toast.success('Due date removed')
}

async function assignMember(memberEmail) {
  if (!cardContextMenu.value.card) return
  await boardsStore.updateCard(cardContextMenu.value.card.id, { assigned_to: memberEmail })
  closeCardContext()
  toast.success(memberEmail ? 'Member assigned' : 'Assignment removed')
}

async function toggleLabel(labelId) {
  if (!cardContextMenu.value.card) return
  const card = cardContextMenu.value.card
  const hasLabel = card.labels?.some(l => l.id === labelId)
  
  if (hasLabel) {
    await boardsStore.removeLabelFromCard(card.id, labelId)
  } else {
    await boardsStore.addLabelToCard(card.id, labelId)
  }
}

function openLabelEditor(label, event) {
  event.stopPropagation()
  labelEditor.value = {
    show: true,
    label: label,
    name: label.name,
    color: label.color
  }
}

function openNewLabelEditor(event) {
  event.stopPropagation()
  labelEditor.value = {
    show: true,
    label: null,
    name: '',
    color: '#f97316'
  }
}

async function saveLabel() {
  if (!labelEditor.value.name.trim()) {
    toast.error('Label name is required')
    return
  }
  
  const data = {
    name: labelEditor.value.name.trim(),
    color: labelEditor.value.color
  }
  
  if (labelEditor.value.label) {
    // Update existing label
    const updated = await boardsStore.updateLabel(labelEditor.value.label.id, data)
    if (updated) {
      toast.success('Label updated')
    }
  } else {
    // Create new label
    const created = await boardsStore.createLabel(boardsStore.currentBoard.id, data)
    if (created) {
      toast.success('Label created')
    }
  }
  
  closeLabelEditor()
}

async function deleteLabel() {
  if (!labelEditor.value.label) return
  
  const confirmed = await showConfirm({
    title: 'Delete Label',
    message: `Are you sure you want to delete "${labelEditor.value.label.name}"? This will remove it from all cards.`,
    danger: true
  })
  
  if (confirmed) {
    const success = await boardsStore.deleteLabel(labelEditor.value.label.id)
    if (success) {
      toast.success('Label deleted')
      closeLabelEditor()
    }
  }
}

function closeLabelEditor() {
  labelEditor.value.show = false
}

async function setCardColor(color) {
  if (!cardContextMenu.value.card) return
  await boardsStore.updateCard(cardContextMenu.value.card.id, { card_color: color })
  closeCardContext()
}

async function toggleListCollapsed(list) {
  await boardsStore.updateList(list.id, { collapsed: !list.collapsed })
}

async function setListColor(list, color) {
  await boardsStore.updateList(list.id, { list_color: color })
}

function copyCardLink() {
  if (!cardContextMenu.value.card) return
  const url = `${window.location.origin}/boards/${boardsStore.currentBoard?.id}?card=${cardContextMenu.value.card.id}`
  navigator.clipboard.writeText(url)
  toast.success('Link copied to clipboard')
  closeCardContext()
}

function hexToSolidTint(hex, mixRatio = 0.85) {
  const h = hex.replace('#', '')
  const r = parseInt(h.substr(0, 2), 16) || 0
  const g = parseInt(h.substr(2, 2), 16) || 0
  const b = parseInt(h.substr(4, 2), 16) || 0
  if (themeStore.isDark) {
    const baseR = 30, baseG = 32, baseB = 38
    const darkRatio = 0.65
    const mr = Math.round(r * (1 - darkRatio) + baseR * darkRatio)
    const mg = Math.round(g * (1 - darkRatio) + baseG * darkRatio)
    const mb = Math.round(b * (1 - darkRatio) + baseB * darkRatio)
    return `rgb(${mr}, ${mg}, ${mb})`
  }
  const mr = Math.round(r + (255 - r) * mixRatio)
  const mg = Math.round(g + (255 - g) * mixRatio)
  const mb = Math.round(b + (255 - b) * mixRatio)
  return `rgb(${mr}, ${mg}, ${mb})`
}

// Computed
const lists = computed(() => boardsStore.currentLists || [])

// Background image style with blur applied directly
const boardBackgroundStyle = computed(() => {
  const board = boardsStore.currentBoard
  if (!board) return {}
  
  const style = {}
  
  // Background image
  if (board.background_image) {
    style.backgroundImage = `url(${board.background_image})`
    style.backgroundSize = 'cover'
    style.backgroundPosition = 'center'
    style.backgroundRepeat = 'no-repeat'
  }
  
  // Apply background color (solid, no transparency)
  if (board.background_color) {
    style.backgroundColor = board.background_color
  } else if (!board.background_image) {
    style.backgroundColor = '#f1f5f9'
  }
  
  // Apply blur directly to background layer
  const blur = parseInt(board.background_blur) || 0
  if (blur > 0) {
    style.filter = `blur(${blur}px)`
    style.transform = 'scale(1.1)' // Prevent blur edges showing
  }
  
  return style
})

// Overlay style - color tint over the background
const boardOverlayStyle = computed(() => {
  const board = boardsStore.currentBoard
  if (!board) return { backgroundColor: 'transparent' }
  
  const overlayColor = board.background_overlay_color
  const overlayOpacity = parseInt(board.background_overlay_opacity) || 0
  
  if (!overlayColor || overlayOpacity <= 0) {
    return { backgroundColor: 'transparent' }
  }
  
  const hex = overlayColor.replace('#', '')
  const r = parseInt(hex.substr(0, 2), 16) || 0
  const g = parseInt(hex.substr(2, 2), 16) || 0
  const b = parseInt(hex.substr(4, 2), 16) || 0
  const a = overlayOpacity / 100
  
  return {
    backgroundColor: `rgba(${r}, ${g}, ${b}, ${a})`
  }
})
</script>

<template>
  <div 
    class="absolute inset-0 overflow-hidden"
    @click="closeCardContext"
  >
    <!-- Background image layer (blur applied via filter) -->
    <div 
      class="absolute inset-[-20px]"
      :style="boardBackgroundStyle"
    ></div>
    
    <!-- Color overlay layer (always rendered, transparent when not active) -->
    <div 
      class="absolute inset-0 pointer-events-none"
      :style="boardOverlayStyle"
    ></div>
    
    <!-- Content layer -->
    <div class="absolute inset-0 overflow-x-auto overflow-y-hidden">
      <div 
        :class="[
          'flex items-start p-3 md:p-4 min-h-full min-w-max pb-8',
          isMobile ? 'gap-2 snap-x snap-mandatory' : 'gap-4'
        ]"
      >
      <!-- Lists -->
      <template
        v-for="(list, listIndex) in lists"
        :key="list.id"
      >
        <!-- Collapsed list -->
        <div
          v-if="list.collapsed"
          :class="[
            'shrink-0 backdrop-blur-sm rounded-xl flex flex-col cursor-pointer transition-colors',
            isMobile ? 'w-10 max-h-[calc(100vh-180px)]' : 'w-10 max-h-[calc(100vh-160px)]',
            list.list_color ? 'hover:opacity-80' : 'bg-surface-100/90 dark:bg-surface-800/90 hover:bg-surface-200/90 dark:hover:bg-surface-700/90'
          ]"
          :style="list.list_color ? { backgroundColor: hexToSolidTint(list.list_color, 0.80), borderLeft: '3px solid ' + list.list_color } : {}"
          @click="toggleListCollapsed(list)"
          @dragover.prevent="onListDropZone($event, listIndex)"
          draggable="true"
          @dragstart="onListDragStart($event, list)"
          @dragend="onListDragEndList"
          title="Click to expand"
        >
          <div class="flex flex-col items-center py-3 h-full min-h-[120px]">
            <span class="material-symbols-rounded text-sm text-surface-400 mb-2">chevron_right</span>
            <div class="flex-1 flex items-start justify-center">
              <span 
                class="text-xs font-semibold text-surface-700 dark:text-surface-200 whitespace-nowrap"
                style="writing-mode: vertical-rl; text-orientation: mixed;"
              >{{ list.name }}</span>
            </div>
            <span class="text-[10px] font-medium text-surface-500 mt-2">{{ visibleCards(list).length }}</span>
          </div>
        </div>

        <!-- Expanded list -->
        <div
          v-else
          :class="[
            'shrink-0 backdrop-blur-sm rounded-xl flex flex-col',
            isMobile ? 'w-64 snap-start max-h-[calc(100vh-180px)]' : 'w-72 max-h-[calc(100vh-160px)]',
            list.list_color ? '' : 'bg-surface-100/90 dark:bg-surface-800/90'
          ]"
          :style="list.list_color ? { backgroundColor: hexToSolidTint(list.list_color, 0.85), borderTop: '3px solid ' + list.list_color } : {}"
          @dragover.prevent="onListDropZone($event, listIndex)"
        >
          <!-- List header -->
          <div class="p-3 flex items-center justify-between shrink-0">
            <!-- Drag handle -->
            <div 
              class="cursor-grab active:cursor-grabbing p-1 -ml-1 mr-1 rounded hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
              draggable="true"
              @dragstart="onListDragStart($event, list)"
              @dragend="onListDragEndList"
              title="Drag to reorder"
            >
              <span class="material-symbols-rounded text-lg">drag_indicator</span>
            </div>
            
            <div v-if="editingListId === list.id" class="flex-1">
              <input
                :id="`edit-list-input-${list.id}`"
                v-model="editingListName"
                type="text"
                class="w-full px-2 py-1 bg-white dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500"
                @keydown.enter="saveListName(list.id)"
                @keydown.escape="editingListId = null"
                @blur="saveListName(list.id)"
              />
            </div>
            <div v-else class="flex-1 min-w-0">
              <h3 
                @dblclick="startEditList(list)"
                class="font-semibold text-surface-900 dark:text-surface-100 text-sm cursor-pointer flex items-center gap-2"
              >
                {{ list.name }}
                <span class="text-xs font-normal text-surface-500">{{ visibleCards(list).length }}</span>
              </h3>
              <!-- Financial indicator (only visible to users with financial access) -->
              <div v-if="list.expected_amount && boardsStore.canViewFinancials" class="flex items-center gap-1 mt-0.5">
                <span class="material-symbols-rounded text-xs text-green-500">payments</span>
                <span class="text-xs text-green-600 dark:text-green-400 font-medium">
                  {{ list.currency || 'HUF' }} {{ Number(list.expected_amount).toLocaleString() }}
                </span>
              </div>
            </div>
            
            <div class="relative group">
              <button class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500">
                <span class="material-symbols-rounded text-lg">more_horiz</span>
              </button>
              <!-- Dropdown -->
              <div class="absolute right-0 top-full mt-1 w-44 bg-white dark:bg-surface-700 rounded-lg shadow-xl border border-surface-200 dark:border-surface-600 py-1 z-20 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all">
                <button 
                  @click="toggleListCollapsed(list)"
                  class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-600 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg" style="transform: rotate(90deg)">compress</span>
                  Collapse
                </button>
                <button 
                  @click="startEditList(list)"
                  class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-600 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg">edit</span>
                  Rename
                </button>
                <button 
                  v-if="boardsStore.canViewFinancials"
                  @click="openListSettings(list)"
                  class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-600 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg">payments</span>
                  Financials
                </button>
                <div class="border-t border-surface-200 dark:border-surface-600 my-1"></div>
                <p class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wider">List color</p>
                <div class="px-3 py-1.5">
                  <div class="grid grid-cols-5 gap-1.5">
                    <button
                      v-for="lc in listColors"
                      :key="lc"
                      class="w-5 h-5 rounded cursor-pointer hover:scale-125 transition-transform border border-black/10 relative"
                      :style="{ backgroundColor: lc }"
                      @click.stop="setListColor(list, lc)"
                    >
                      <span
                        v-if="list.list_color === lc"
                        class="material-symbols-rounded text-white text-xs absolute inset-0 flex items-center justify-center drop-shadow-sm"
                      >check</span>
                    </button>
                  </div>
                  <button
                    v-if="list.list_color"
                    @click.stop="setListColor(list, null)"
                    class="mt-1.5 w-full px-1 py-1 text-[10px] text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600 rounded flex items-center gap-1 justify-center"
                  >
                    <span class="material-symbols-rounded text-xs">format_color_reset</span>
                    Remove
                  </button>
                </div>
                <div class="border-t border-surface-200 dark:border-surface-600 my-1"></div>
                <button 
                  @click="archiveList(list)"
                  class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-600 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg">archive</span>
                  Archive
                </button>
                <button 
                  @click="deleteList(list)"
                  class="w-full px-3 py-2 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg">delete</span>
                  Delete
                </button>
              </div>
            </div>
          </div>
          
          <!-- Cards container -->
          <div 
            class="flex-1 overflow-y-auto px-2 pb-2 space-y-2"
            :class="{ 'bg-primary-500/10': dragOverListId === list.id }"
            @dragover="onListDragOver($event, list.id)"
            @dragleave="onListDragLeave"
            @drop="onListDrop($event, list.id)"
          >
            <template v-for="(card, cardIndex) in visibleCards(list)" :key="card.id">
              <!-- Drop indicator -->
              <div 
                v-if="dragOverListId === list.id && dragOverCardPosition === cardIndex"
                class="h-1 bg-primary-500 rounded-full mx-1"
              ></div>
              
              <BoardCard
                :card="card"
                draggable="true"
                @click="openCard(card)"
                @contextmenu="showCardContext($event, card, list.id)"
                @open-menu="showCardContext($event, card, list.id)"
                @dragstart="onCardDragStart($event, card, list.id)"
                @dragend="onCardDragEnd"
                @dragover="onCardDragOver($event, cardIndex, list.id)"
              />
            </template>
            
            <!-- Drop indicator at end -->
            <div 
              v-if="dragOverListId === list.id && (dragOverCardPosition === null || dragOverCardPosition >= visibleCards(list).length)"
              class="h-1 bg-primary-500 rounded-full mx-1"
            ></div>
            
            <!-- Add card form -->
            <div v-if="addingCardToList === list.id" class="p-2">
              <textarea
                :id="`new-card-input-${list.id}`"
                v-model="newCardTitle"
                placeholder="Enter card title..."
                rows="2"
                class="w-full px-3 py-2 bg-white dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 resize-none"
                @keydown.enter.prevent="addCard(list.id)"
                @keydown.escape="cancelAddCard"
              ></textarea>
              <div class="flex items-center gap-2 mt-2">
                <button 
                  @click="addCard(list.id)"
                  class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium transition-colors"
                >
                  Add card
                </button>
                <button 
                  @click="cancelAddCard"
                  class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
                >
                  <span class="material-symbols-rounded text-surface-500">close</span>
                </button>
              </div>
            </div>
          </div>
          
          <!-- Add card button -->
          <div v-if="addingCardToList !== list.id" class="p-2 shrink-0">
            <button 
              @click="startAddCard(list.id)"
              class="w-full px-3 py-2 text-sm text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">add</span>
              Add a card
            </button>
          </div>
        </div>
      </template>
      
      <!-- Add list -->
      <div :class="isMobile ? 'w-64 shrink-0 snap-start' : 'w-72 shrink-0'">
        <div v-if="addingList" class="bg-surface-100 dark:bg-surface-800 rounded-xl p-3">
          <input
            id="new-list-input"
            v-model="newListName"
            type="text"
            placeholder="Enter list name..."
            class="w-full px-3 py-2 bg-white dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500"
            @keydown.enter="addList"
            @keydown.escape="cancelAddList"
          />
          <div class="flex items-center gap-2 mt-2">
            <button 
              @click="addList"
              class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium transition-colors"
            >
              Add list
            </button>
            <button 
              @click="cancelAddList"
              class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-surface-500">close</span>
            </button>
          </div>
        </div>
        <button 
          v-else
          @click="startAddList"
          class="w-full px-4 py-3 bg-surface-100/80 hover:bg-surface-200/90 dark:bg-surface-800/50 dark:hover:bg-surface-800 backdrop-blur rounded-xl text-surface-600 dark:text-surface-300 text-sm font-medium flex items-center gap-2 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">add</span>
          <span class="hidden md:inline">Add another list</span>
          <span class="md:hidden">Add list</span>
        </button>
      </div>
    </div>
    </div>
    
    <!-- Card context menu -->
    <Teleport to="body">
      <div 
        v-if="cardContextMenu.show"
        class="fixed inset-0 z-50"
        @click="closeCardContext(); showDatePicker = false; showCardColorPicker = false"
        @contextmenu.prevent="closeCardContext()"
      >
        <div 
          class="absolute bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 w-52 max-h-[calc(100vh-16px)] overflow-y-auto"
          :style="{ left: cardContextMenu.x + 'px', top: cardContextMenu.y + 'px' }"
          @click.stop
        >
          <button
            @click="openCard(cardContextMenu.card); closeCardContext()"
            class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">open_in_full</span>
            Open card
          </button>
          
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          
          <!-- Edit labels submenu -->
          <div class="relative">
            <button
              @click.stop="showLabelsSubmenu = !showLabelsSubmenu; showMemberSubmenu = false; showMoveSubmenu = false; showDatePicker = false; showCardColorPicker = false"
              class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 justify-between"
            >
              <span class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">label</span>
                Edit labels
              </span>
              <span class="material-symbols-rounded text-sm" :class="showLabelsSubmenu ? 'rotate-90' : ''">chevron_right</span>
            </button>
            <div v-if="showLabelsSubmenu" class="absolute left-full top-0 ml-1 w-52 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-2" @click.stop>
              <div v-if="contextLabels.length === 0" class="px-3 py-2 text-sm text-surface-500">
                No labels yet
              </div>
              <div
                v-for="label in contextLabels"
                :key="label.id"
                class="flex items-center gap-1 px-2 py-0.5 hover:bg-surface-50 dark:hover:bg-surface-700/50"
              >
                <button
                  @click="toggleLabel(label.id)"
                  class="flex-1 px-1 py-1.5 text-left text-sm flex items-center gap-2 rounded hover:bg-surface-100 dark:hover:bg-surface-700"
                >
                  <span class="w-4 h-4 rounded" :style="{ backgroundColor: label.color }"></span>
                  <span class="flex-1 text-surface-700 dark:text-surface-300 truncate">{{ label.name || 'Untitled' }}</span>
                  <span 
                    v-if="cardContextMenu.card?.labels?.some(l => l.id === label.id)"
                    class="material-symbols-rounded text-sm text-primary-500"
                  >check</span>
                </button>
                <button
                  @click="openLabelEditor(label, $event)"
                  class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
                  title="Edit label"
                >
                  <span class="material-symbols-rounded text-base">edit</span>
                </button>
              </div>
              <div class="border-t border-surface-200 dark:border-surface-700 mt-2 pt-2 px-2">
                <button
                  @click="openNewLabelEditor($event)"
                  class="w-full px-2 py-1.5 text-left text-sm text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg">add</span>
                  Create new label
                </button>
              </div>
            </div>
          </div>
          
          <!-- Change member submenu -->
          <div class="relative">
            <button
              @click.stop="showMemberSubmenu = !showMemberSubmenu; showLabelsSubmenu = false; showMoveSubmenu = false; showDatePicker = false; showCardColorPicker = false"
              class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 justify-between"
            >
              <span class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">person</span>
                Change member
              </span>
              <span class="material-symbols-rounded text-sm" :class="showMemberSubmenu ? 'rotate-90' : ''">chevron_right</span>
            </button>
            <div v-if="showMemberSubmenu" class="absolute left-full top-0 ml-1 w-48 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-2" @click.stop>
              <button
                @click="assignMember(null)"
                class="w-full px-3 py-1.5 text-left text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-lg">person_remove</span>
                Unassign
              </button>
              <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
              <button
                v-for="member in contextMembers"
                :key="member.email"
                @click="assignMember(member.email)"
                class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
              >
                <div class="w-6 h-6 rounded-full bg-primary-500 text-white text-xs font-medium flex items-center justify-center uppercase">
                  {{ member.email.charAt(0) }}
                </div>
                <span class="flex-1 text-surface-700 dark:text-surface-300 truncate">{{ member.email }}</span>
                <span 
                  v-if="cardContextMenu.card?.assigned_to === member.email"
                  class="material-symbols-rounded text-sm text-primary-500"
                >check</span>
              </button>
            </div>
          </div>
          
          <!-- Edit dates -->
          <div class="relative">
            <button
              @click="openDatePicker"
              class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">schedule</span>
              Edit dates
              <span v-if="cardContextMenu.card?.due_date" class="ml-auto text-xs text-surface-500">
                {{ new Date(cardContextMenu.card.due_date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) }}
              </span>
            </button>
            
            <!-- Date picker popup -->
            <div 
              v-if="showDatePicker"
              class="absolute left-full top-0 ml-1 w-56 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 p-3"
              @click.stop
            >
              <p class="text-xs font-medium text-surface-500 mb-2">Due date</p>
              <input
                v-model="contextDueDate"
                type="date"
                class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500"
              />
              <div class="flex gap-2 mt-3">
                <button
                  @click="saveDueDate"
                  class="flex-1 px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium transition-colors"
                >
                  Save
                </button>
                <button
                  v-if="cardContextMenu.card?.due_date"
                  @click="removeDueDate"
                  class="px-3 py-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg text-sm font-medium transition-colors"
                >
                  Remove
                </button>
              </div>
            </div>
          </div>
          
          <!-- Card color -->
          <button
            @click.stop="showCardColorPicker = !showCardColorPicker; showDatePicker = false"
            class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 justify-between"
          >
            <span class="flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">palette</span>
              Card color
            </span>
            <span class="flex items-center gap-1">
              <span
                v-if="cardContextMenu.card?.card_color"
                class="w-4 h-4 rounded-full border border-black/10"
                :style="{ backgroundColor: cardContextMenu.card.card_color }"
              ></span>
              <span class="material-symbols-rounded text-sm" :class="showCardColorPicker ? 'rotate-90' : ''">chevron_right</span>
            </span>
          </button>
          <div
            v-if="showCardColorPicker"
            class="px-3 pb-3"
            @click.stop
          >
            <div class="grid grid-cols-8 gap-1.5 p-2 bg-surface-50 dark:bg-surface-700/50 rounded-lg">
              <button
                v-for="color in cardColors"
                :key="color"
                class="w-5 h-5 rounded cursor-pointer hover:scale-125 transition-transform border border-black/10 relative"
                :style="{ backgroundColor: color }"
                @click="setCardColor(color)"
              >
                <span
                  v-if="cardContextMenu.card?.card_color === color"
                  class="material-symbols-rounded text-white text-xs absolute inset-0 flex items-center justify-center drop-shadow-sm"
                >check</span>
              </button>
            </div>
            <button
              v-if="cardContextMenu.card?.card_color"
              @click="setCardColor(null)"
              class="mt-1.5 w-full px-2 py-1.5 text-left text-xs text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 rounded flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-sm">format_color_reset</span>
              Remove color
            </button>
          </div>
          
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          
          <!-- Move to list submenu -->
          <div class="relative">
            <button
              @click.stop="showMoveSubmenu = !showMoveSubmenu; showLabelsSubmenu = false; showMemberSubmenu = false; showDatePicker = false; showCardColorPicker = false"
              class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 justify-between"
            >
              <span class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">arrow_forward</span>
                Move
              </span>
              <span class="material-symbols-rounded text-sm" :class="showMoveSubmenu ? 'rotate-90' : ''">chevron_right</span>
            </button>
            <div v-if="showMoveSubmenu" class="absolute left-full top-0 ml-1 w-40 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1" @click.stop>
              <button
                v-for="list in lists"
                :key="list.id"
                @click="moveCardToList(list.id)"
                :class="[
                  'w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2',
                  list.id === cardContextMenu.listId ? 'text-primary-500' : 'text-surface-700 dark:text-surface-300'
                ]"
              >
                {{ list.name }}
                <span v-if="list.id === cardContextMenu.listId" class="material-symbols-rounded text-sm ml-auto">check</span>
              </button>
            </div>
          </div>
          
          <!-- Copy link -->
          <button
            @click="copyCardLink"
            class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">link</span>
            Copy link
          </button>
          
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          
          <button
            @click="archiveCard"
            class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">archive</span>
            Archive
          </button>
          
          <button
            @click="deleteCard"
            class="w-full px-3 py-2 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">delete</span>
            Delete
          </button>
        </div>
      </div>
    </Teleport>
    
    <!-- Label Editor Modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="labelEditor.show"
          class="fixed inset-0 bg-black/50 z-[200] flex items-center justify-center p-4"
          @click.self="closeLabelEditor"
        >
          <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
              <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                {{ labelEditor.label ? 'Edit Label' : 'Create Label' }}
              </h3>
              <button 
                @click="closeLabelEditor"
                class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg"
              >
                <span class="material-symbols-rounded text-surface-500">close</span>
              </button>
            </div>
            
            <div class="p-5 space-y-4">
              <!-- Preview -->
              <div class="flex items-center justify-center">
                <span 
                  class="px-4 py-2 rounded-full text-white font-medium text-sm"
                  :style="{ backgroundColor: labelEditor.color }"
                >
                  {{ labelEditor.name || 'Label preview' }}
                </span>
              </div>
              
              <!-- Name input -->
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                  Name
                </label>
                <input 
                  v-model="labelEditor.name"
                  type="text"
                  placeholder="Label name"
                  class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  @keydown.enter="saveLabel"
                />
              </div>
              
              <!-- Color picker -->
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                  Color
                </label>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-for="color in labelColors"
                    :key="color"
                    @click="labelEditor.color = color"
                    class="w-8 h-8 rounded-lg transition-transform hover:scale-110"
                    :class="labelEditor.color === color ? 'ring-2 ring-offset-2 ring-primary-500 dark:ring-offset-surface-800' : ''"
                    :style="{ backgroundColor: color }"
                  ></button>
                </div>
                <!-- Custom color -->
                <div class="mt-3 flex items-center gap-2">
                  <input 
                    v-model="labelEditor.color"
                    type="color"
                    class="w-10 h-10 rounded cursor-pointer border-0 p-0"
                  />
                  <input 
                    v-model="labelEditor.color"
                    type="text"
                    placeholder="#000000"
                    class="flex-1 px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 text-sm font-mono"
                  />
                </div>
              </div>
            </div>
            
            <div class="px-5 py-4 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between">
              <button 
                v-if="labelEditor.label"
                @click="deleteLabel"
                class="px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg flex items-center gap-1.5"
              >
                <span class="material-symbols-rounded text-lg">delete</span>
                Delete
              </button>
              <div v-else></div>
              
              <div class="flex items-center gap-2">
                <button 
                  @click="closeLabelEditor"
                  class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg"
                >
                  Cancel
                </button>
                <button 
                  @click="saveLabel"
                  :disabled="!labelEditor.name.trim()"
                  class="px-4 py-2 bg-primary-500 hover:bg-primary-600 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg font-medium"
                >
                  {{ labelEditor.label ? 'Save' : 'Create' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Confirm Modal -->
    <ConfirmModal
      :show="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      :danger="confirmModal.danger"
      confirm-text="Delete"
      @confirm="confirmModal.onConfirm?.()"
      @cancel="cancelConfirm"
    />
    
    <!-- List Settings Modal (Financials) -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="listSettingsModal.show" 
          class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
          @click.self="listSettingsModal.show = false"
        >
          <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-green-500">payments</span>
              {{ listSettingsModal.list?.name }} - Financials
            </h3>
            
            <div class="space-y-4">
              <!-- Is Milestone Toggle -->
              <div class="flex items-center justify-between">
                <label class="text-sm font-medium text-surface-700 dark:text-surface-300">
                  Mark as Milestone
                </label>
                <button
                  @click="listSettingsModal.is_milestone = !listSettingsModal.is_milestone"
                  :class="[
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    listSettingsModal.is_milestone ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                      listSettingsModal.is_milestone ? 'translate-x-6' : 'translate-x-1'
                    ]"
                  />
                </button>
              </div>
              
              <!-- Expected Amount with Currency -->
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Expected Amount
                </label>
                <div class="flex gap-2">
                  <select
                    v-model="listSettingsModal.currency"
                    class="px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                  >
                    <option v-for="curr in availableCurrencies" :key="curr" :value="curr">{{ curr }}</option>
                  </select>
                  <input 
                    v-model="listSettingsModal.expected_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    class="flex-1 px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                  />
                </div>
              </div>
              
              <!-- Invoice Date -->
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Invoice Date
                </label>
                <input 
                  v-model="listSettingsModal.invoice_date"
                  type="date"
                  class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                />
                <p class="text-xs text-surface-400 mt-1">
                  Payment date is calculated based on client's payment terms
                </p>
              </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-6">
              <button 
                @click="listSettingsModal.show = false"
                class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                Cancel
              </button>
              <button 
                @click="saveListSettings"
                class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
              >
                Save
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
/* Custom scrollbar for cards container */
.overflow-y-auto::-webkit-scrollbar {
  width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: rgb(var(--color-surface-400) / 0.3);
  border-radius: 3px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: rgb(var(--color-surface-400) / 0.5);
}
</style>

