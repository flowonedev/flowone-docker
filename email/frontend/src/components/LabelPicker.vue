<script setup>
import { ref, computed } from 'vue'
import { useLabelsStore } from '@/stores/labels'
import { useMailboxStore } from '@/stores/mailbox'
import { useToastStore } from '@/stores/toast'
import ConfirmModal from './shared/ConfirmModal.vue'

const props = defineProps({
  messageId: String,
  messageLabels: {
    type: Array,
    default: () => []
  },
  iconOnly: {
    type: Boolean,
    default: false
  },
  // When true, show dropdown directly without trigger button (for external control)
  inline: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close'])

const labelsStore = useLabelsStore()
const mailbox = useMailboxStore()
const toast = useToastStore()

const showPicker = ref(false)
const showCreate = ref(false)
const newLabelName = ref('')
const selectedColor = ref('#3b82f6')
const creating = ref(false)
const searchQuery = ref('')

// Edit/Delete state
const editingLabel = ref(null)
const editName = ref('')
const editColor = ref('')
const showDeleteConfirm = ref(false)
const labelToDelete = ref(null)
const contextMenuLabel = ref(null)
const contextMenuPos = ref({ x: 0, y: 0 })

const hasLabel = (labelId) => {
  return props.messageLabels.some(l => l.id === labelId)
}

const filteredLabels = computed(() => {
  if (!searchQuery.value.trim()) return labelsStore.labels
  const query = searchQuery.value.toLowerCase()
  return labelsStore.labels.filter(l => l.name.toLowerCase().includes(query))
})

async function toggleLabel(label) {
  if (!props.messageId) {
    toast.error('Cannot add label - message ID not available')
    console.error('No message ID for label toggle')
    return
  }
  
  if (hasLabel(label.id)) {
    const success = await labelsStore.removeLabelFromMessage(props.messageId, label.id)
    if (success) {
      toast.success(`Removed "${label.name}" label`)
    } else {
      toast.error('Failed to remove label')
    }
  } else {
    const success = await labelsStore.addLabelToMessage(props.messageId, label.id)
    if (success) {
      toast.success(`Added "${label.name}" label`)
    } else {
      toast.error('Failed to add label')
    }
  }
}

async function createLabel() {
  if (!newLabelName.value.trim()) return
  
  creating.value = true
  const label = await labelsStore.createLabel(newLabelName.value.trim(), selectedColor.value)
  creating.value = false
  
  if (label) {
    toast.success('Label created')
    newLabelName.value = ''
    showCreate.value = false
    // Auto-apply to current message if we have one
    if (props.messageId) {
      await toggleLabel(label)
    }
  } else {
    toast.error('Failed to create label')
  }
}

// Context menu functions
function openContextMenu(e, label) {
  e.preventDefault()
  e.stopPropagation()
  
  const menuWidth = 140
  const menuHeight = 80
  const padding = 8
  
  let x = e.clientX
  let y = e.clientY
  
  // Check right edge overflow
  if (x + menuWidth > window.innerWidth - padding) {
    x = window.innerWidth - menuWidth - padding
  }
  
  // Check bottom edge overflow
  if (y + menuHeight > window.innerHeight - padding) {
    y = window.innerHeight - menuHeight - padding
  }
  
  // Check left edge overflow
  if (x < padding) {
    x = padding
  }
  
  contextMenuLabel.value = label
  contextMenuPos.value = { x, y }
}

function closeContextMenu() {
  contextMenuLabel.value = null
}

// Edit functions
function startEdit(label) {
  editingLabel.value = label.id
  editName.value = label.name
  editColor.value = label.color
  closeContextMenu()
}

function cancelEdit() {
  editingLabel.value = null
  editName.value = ''
  editColor.value = ''
}

async function saveEdit() {
  if (!editName.value.trim()) return
  
  const success = await labelsStore.updateLabel(editingLabel.value, editName.value.trim(), editColor.value)
  if (success) {
    toast.success('Label updated')
    cancelEdit()
  } else {
    toast.error('Failed to update label')
  }
}

// Delete functions
function confirmDelete(label) {
  labelToDelete.value = label
  showDeleteConfirm.value = true
  closeContextMenu()
}

async function executeDelete() {
  if (!labelToDelete.value) return
  
  const deletedId = labelToDelete.value.id
  const success = await labelsStore.deleteLabel(deletedId)
  if (success) {
    toast.success(`Label "${labelToDelete.value.name}" deleted`)
    mailbox.messages.forEach(msg => {
      if (Array.isArray(msg.labels)) {
        msg.labels = msg.labels.filter(l => l.id !== deletedId)
      }
    })
    if (mailbox.currentMessage?.labels) {
      mailbox.currentMessage.labels = mailbox.currentMessage.labels.filter(l => l.id !== deletedId)
    }
    mailbox.notifyMessagesChanged()
  } else {
    toast.error('Failed to delete label')
  }
  
  showDeleteConfirm.value = false
  labelToDelete.value = null
}

const colorOptions = computed(() => {
  return Object.entries(labelsStore.colors).map(([name, hex]) => ({
    name,
    hex
  }))
})

function closePicker() {
  showPicker.value = false
  emit('close')
}
</script>

<template>
  <div class="relative">
    <!-- Trigger button (only show when not inline mode) -->
    <button
      v-if="!inline"
      @click.stop="showPicker = !showPicker"
      :class="iconOnly 
        ? 'w-8 h-8 flex items-center justify-center rounded-full text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors'
        : 'btn-ghost btn-icon btn-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
      title="Add labels"
    >
      <span class="material-symbols-rounded" :class="iconOnly ? 'text-xl' : 'text-lg'">label</span>
    </button>
    
    <!-- Dropdown picker -->
    <div 
      v-if="showPicker || inline"
      :class="inline 
        ? 'w-72 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 overflow-hidden'
        : 'absolute right-0 top-8 z-50 w-72 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 overflow-hidden'"
      @click.stop
    >
      <div class="p-2 border-b border-surface-100 dark:border-surface-700">
        <p class="text-xs font-medium text-surface-500 uppercase tracking-wider mb-2">Label as:</p>
        <!-- Search input -->
        <div class="relative">
          <input
            v-model="searchQuery"
            type="text"
            class="input text-sm w-full pl-8"
            placeholder="Search labels..."
          />
          <span class="material-symbols-rounded absolute left-2 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
        </div>
      </div>
      
      <!-- Existing labels -->
      <div class="max-h-64 overflow-y-auto p-1">
        <div v-if="filteredLabels.length === 0" class="p-3 text-center text-surface-500 text-sm">
          {{ searchQuery ? 'No labels found' : 'No labels yet' }}
        </div>
        
        <div
          v-for="label in filteredLabels"
          :key="label.id"
          class="group"
        >
          <!-- Edit mode -->
          <div v-if="editingLabel === label.id" class="p-2 bg-surface-50 dark:bg-surface-700 rounded-lg m-1">
            <input
              v-model="editName"
              type="text"
              class="input text-sm mb-2"
              placeholder="Label name..."
              @keyup.enter="saveEdit"
              @keyup.escape="cancelEdit"
              autofocus
            />
            <div class="flex flex-wrap gap-1 mb-2">
              <button
                v-for="color in colorOptions"
                :key="color.name"
                @click="editColor = color.hex"
                class="w-5 h-5 rounded-full transition-transform hover:scale-110"
                :class="{ 'ring-2 ring-offset-1 ring-surface-900 dark:ring-white': editColor === color.hex }"
                :style="{ backgroundColor: color.hex }"
                :title="color.name"
              ></button>
            </div>
            <div class="flex gap-2">
              <button @click="saveEdit" class="btn-primary btn-sm flex-1">Save</button>
              <button @click="cancelEdit" class="btn-ghost btn-sm">Cancel</button>
            </div>
          </div>
          
          <!-- Normal display -->
          <div
            v-else
            class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            @contextmenu="openContextMenu($event, label)"
          >
            <button
              @click="toggleLabel(label)"
              class="flex items-center gap-2 flex-1 min-w-0"
            >
              <span 
                class="w-3 h-3 rounded-full shrink-0"
                :style="{ backgroundColor: label.color }"
              ></span>
              <span class="flex-1 text-left text-sm text-surface-700 dark:text-surface-200 truncate">
                {{ label.name }}
              </span>
              <span 
                v-if="hasLabel(label.id)"
                class="material-symbols-rounded text-lg text-primary-500"
              >
                check
              </span>
            </button>
            
            <!-- More actions button -->
            <button
              @click.stop="openContextMenu($event, label)"
              class="opacity-0 group-hover:opacity-100 w-6 h-6 flex items-center justify-center rounded hover:bg-surface-200 dark:hover:bg-surface-600 transition-all"
            >
              <span class="material-symbols-rounded text-base text-surface-500">more_vert</span>
            </button>
          </div>
        </div>
      </div>
      
      <!-- Create new label -->
      <div class="border-t border-surface-100 dark:border-surface-700 p-2">
        <button
          v-if="!showCreate"
          @click="showCreate = true"
          class="w-full flex items-center gap-2 px-3 py-2 text-sm text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-md transition-colors"
        >
          <span class="material-symbols-rounded text-lg">add</span>
          Create new
        </button>
        
        <div v-else class="space-y-2">
          <input
            v-model="newLabelName"
            type="text"
            class="input text-sm"
            placeholder="Label name..."
            @keyup.enter="createLabel"
            autofocus
          />
          
          <!-- Color picker -->
          <div class="flex flex-wrap gap-1">
            <button
              v-for="color in colorOptions"
              :key="color.name"
              @click="selectedColor = color.hex"
              class="w-5 h-5 rounded-full transition-transform hover:scale-110"
              :class="{ 'ring-2 ring-offset-2 ring-surface-900 dark:ring-white': selectedColor === color.hex }"
              :style="{ backgroundColor: color.hex }"
              :title="color.name"
            ></button>
          </div>
          
          <div class="flex gap-2">
            <button
              @click="createLabel"
              :disabled="creating || !newLabelName.trim()"
              class="btn-primary btn-sm flex-1"
            >
              <span v-if="creating" class="spinner"></span>
              Create
            </button>
            <button
              @click="showCreate = false; newLabelName = ''"
              class="btn-ghost btn-sm"
            >
              Cancel
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Backdrop to close picker (only when not inline - parent controls closing in inline mode) -->
    <div 
      v-if="showPicker && !inline"
      class="fixed inset-0 z-40"
      @click="closePicker"
    ></div>
    
    <!-- Context menu for label actions -->
    <Teleport to="body">
      <div
        v-if="contextMenuLabel"
        class="fixed z-[200] bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[140px]"
        :style="{ left: contextMenuPos.x + 'px', top: contextMenuPos.y + 'px' }"
      >
        <button
          @click="startEdit(contextMenuLabel)"
          class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700"
        >
          <span class="material-symbols-rounded text-base">edit</span>
          Rename
        </button>
        <button
          @click="confirmDelete(contextMenuLabel)"
          class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
        >
          <span class="material-symbols-rounded text-base">delete</span>
          Delete
        </button>
      </div>
      
      <!-- Backdrop for context menu -->
      <div
        v-if="contextMenuLabel"
        class="fixed inset-0 z-[199]"
        @click="closeContextMenu"
      ></div>
    </Teleport>
    
    <!-- Delete confirmation dialog -->
    <ConfirmModal
      :show="showDeleteConfirm"
      title="Delete Label"
      :message="`Are you sure you want to delete the label '${labelToDelete?.name}'? This will remove it from all messages.`"
      confirmText="Delete"
      type="danger"
      @confirm="executeDelete"
      @cancel="showDeleteConfirm = false; labelToDelete = null"
    />
  </div>
</template>
