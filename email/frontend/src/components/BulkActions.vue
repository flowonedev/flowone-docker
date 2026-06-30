<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'
import { useSpamStore } from '@/stores/spam'
import { useFiltersStore } from '@/stores/filters'
import { useToastStore } from '@/stores/toast'
import FilterModal from './FilterModal.vue'

const mailbox = useMailboxStore()
const spam = useSpamStore()
const filtersStore = useFiltersStore()
const toast = useToastStore()

const showFilterModal = ref(false)
const filterInitialData = ref(null)

const showMoveMenu = ref(false)
const processing = ref(false)

// On phones the selection toolbar shows a reduced, finger-friendly set of
// actions (archive / delete / spam / move). Read/unread and pin stay on desktop
// only, where there is room and a mouse. Breakpoint matches MailboxView (768).
const isMobile = ref(false)
function checkMobile() {
  isMobile.value = typeof window !== 'undefined' && window.innerWidth < 768
}
onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', checkMobile)
})

const selectedCount = computed(() => mailbox.selectedMessages.length)
const hasSelection = computed(() => selectedCount.value > 0)

// Get selected messages by matching against messages list using composite key check
function getSelectedMessageObjects() {
  return mailbox.messages.filter(m => {
    const folder = m.folder || mailbox.currentFolder
    return mailbox.isMessageSelected(m.uid, folder)
  })
}

// Check if all selected messages are pinned
const allPinned = computed(() => {
  const selectedMsgs = getSelectedMessageObjects()
  // Use message's actual folder for virtual views (ALL_MAIL, SEARCH_RESULTS)
  return selectedMsgs.length > 0 && selectedMsgs.every(m => mailbox.isEmailPinned(m.uid, m.folder || mailbox.currentFolder))
})

// Check if we're in a trash folder
const isInTrash = computed(() => {
  const folder = mailbox.folders.find(f => f.name === mailbox.currentFolder)
  return folder?.type === 'trash'
})

const moveFolders = computed(() => {
  return mailbox.folders.filter(f => f.name !== mailbox.currentFolder)
})

async function markAsRead() {
  processing.value = true
  await mailbox.bulkSetFlag(mailbox.getSelectedMessagesData(), 'seen', true)
  mailbox.clearSelection()
  processing.value = false
}

async function markAsUnread() {
  processing.value = true
  await mailbox.bulkSetFlag(mailbox.getSelectedMessagesData(), 'seen', false)
  mailbox.clearSelection()
  processing.value = false
}

async function deleteSelected() {
  processing.value = true
  const selectedData = mailbox.getSelectedMessagesData()
  
  const result = await mailbox.bulkDeleteMessages(selectedData)
  
  mailbox.clearSelection()
  processing.value = false
  
  if (result.success > 0) {
    toast.success(`Deleted ${result.success} message${result.success > 1 ? 's' : ''}`)
  }
  if (result.failed > 0) {
    toast.error(`Failed to delete ${result.failed} message${result.failed > 1 ? 's' : ''}`)
  }
}

async function archiveSelected() {
  processing.value = true
  const selectedData = mailbox.getSelectedMessagesData()
  
  const result = await mailbox.bulkMoveMessages(selectedData, 'INBOX.Archive')
  
  mailbox.clearSelection()
  processing.value = false
  
  if (result.success > 0) {
    toast.success(`Archived ${result.success} message${result.success > 1 ? 's' : ''}`)
  }
}

async function spamSelected() {
  processing.value = true
  const selectedData = mailbox.getSelectedMessagesData()

  const items = selectedData.map(item => ({
    uid: typeof item === 'object' ? item.uid : item,
    folder: (typeof item === 'object' ? item.folder : null) || mailbox.currentFolder,
  }))

  await spam.bulkReportSpam(items)

  mailbox.clearSelection()
  processing.value = false
}

async function restoreSelected() {
  processing.value = true
  const selectedData = mailbox.getSelectedMessagesData()
  const uids = selectedData.map(item => item.uid)
  
  const result = await mailbox.bulkRestoreMessages(uids, 'INBOX')
  
  mailbox.clearSelection()
  processing.value = false
  
  if (result.success > 0) {
    toast.success(`Restored ${result.success} message${result.success > 1 ? 's' : ''} to Inbox`)
  }
  if (result.failed > 0) {
    toast.error(`Failed to restore ${result.failed} message${result.failed > 1 ? 's' : ''}`)
  }
}

function getFolderDisplayName(name) {
  if (name === 'INBOX') return 'Inbox'
  if (name?.startsWith('INBOX.')) {
    // Replace dots with arrows for subfolder paths
    return name.slice(6).replace(/\./g, ' -> ')
  }
  return name?.replace(/\./g, ' -> ') || name
}

async function moveSelected(targetFolder) {
  showMoveMenu.value = false
  processing.value = true
  const selectedData = mailbox.getSelectedMessagesData()
  
  const result = await mailbox.bulkMoveMessages(selectedData, targetFolder)
  
  mailbox.clearSelection()
  processing.value = false
  
  if (result.success > 0) {
    toast.success(`Moved ${result.success} message${result.success > 1 ? 's' : ''}`)
  }
}

function createFilter() {
  const conditions = { match: 'any', rules: [] }
  const senders = new Set()
  const selectedMsgs = getSelectedMessageObjects()
  
  for (const msg of selectedMsgs) {
    if (msg.from_email) {
      senders.add(msg.from_email)
    } else if (msg.from) {
      const match = msg.from.match(/<([^>]+)>/)
      if (match) senders.add(match[1])
      else senders.add(msg.from)
    }
  }
  
  for (const sender of senders) {
    conditions.rules.push({ field: 'from', operator: 'contains', value: sender })
  }
  
  if (conditions.rules.length === 0) {
    conditions.rules.push({ field: 'from', operator: 'contains', value: '' })
  }
  
  filterInitialData.value = {
    name: senders.size === 1 ? `Filter for ${[...senders][0]}` : `Filter for ${senders.size} senders`,
    conditions,
    actions: [{ action: 'move', value: '' }]
  }
  
  showFilterModal.value = true
}

async function togglePinSelected() {
  processing.value = true
  const shouldPin = !allPinned.value
  const selectedMsgs = getSelectedMessageObjects()

  const items = selectedMsgs.map(msg => ({
    uid: msg.uid,
    folder: msg.folder || mailbox.currentFolder,
    message_id: msg.message_id,
    subject: msg.subject,
  }))

  await mailbox.bulkSetPin(items, shouldPin)

  mailbox.clearSelection()
  processing.value = false

  toast.success(shouldPin ? `Pinned ${selectedMsgs.length} message(s)` : `Unpinned ${selectedMsgs.length} message(s)`)
}
</script>

<template>
  <!-- Only render when there's a selection -->
  <div v-if="hasSelection" class="flex items-center gap-1 px-2" :class="{ 'is-mobile': isMobile }">
    <!-- Close & Count -->
    <button @click="mailbox.clearSelection()" class="toolbar-btn" title="Clear selection">
      <span class="material-symbols-rounded text-xl">close</span>
    </button>
    <span class="text-sm font-medium text-surface-600 dark:text-surface-300 px-1 min-w-[2rem]">
      {{ selectedCount }}
    </span>
    
    <div class="toolbar-divider"></div>
    
    <!-- Restore (only in Trash) -->
    <template v-if="isInTrash">
      <button @click="restoreSelected" class="toolbar-btn text-green-600 dark:text-green-400" :disabled="processing" title="Restore to Inbox">
        <span class="material-symbols-rounded text-xl">restore</span>
      </button>
      <div class="toolbar-divider"></div>
    </template>
    
    <!-- Archive, Delete, Spam -->
    <button @click="archiveSelected" class="toolbar-btn" :disabled="processing" title="Archive">
      <span class="material-symbols-rounded text-xl">archive</span>
    </button>
    <!-- Delete: shown on mobile and desktop (permanent in Trash, otherwise move to trash) -->
    <button v-if="isInTrash" @click="deleteSelected" class="toolbar-btn" :disabled="processing" title="Delete permanently">
      <span class="material-symbols-rounded text-xl">delete_forever</span>
    </button>
    <button v-else @click="deleteSelected" class="toolbar-btn" :disabled="processing" title="Delete">
      <span class="material-symbols-rounded text-xl">delete</span>
    </button>
    <button @click="spamSelected" class="toolbar-btn" :disabled="processing" title="Report spam">
      <span class="material-symbols-rounded text-xl">report</span>
    </button>
    
    <!-- Read/Unread + Pin: desktop only -->
    <template v-if="!isMobile">
      <div class="toolbar-divider"></div>
      
      <button @click="markAsUnread" class="toolbar-btn" :disabled="processing" title="Mark as unread">
        <span class="material-symbols-rounded text-xl">mail</span>
      </button>
      <button @click="markAsRead" class="toolbar-btn" :disabled="processing" title="Mark as read">
        <span class="material-symbols-rounded text-xl">drafts</span>
      </button>
      
      <button @click="togglePinSelected" class="toolbar-btn" :disabled="processing" :title="allPinned ? 'Unpin' : 'Pin'">
        <span class="material-symbols-rounded text-xl" :class="allPinned ? 'text-amber-500' : ''">push_pin</span>
      </button>
    </template>
    
    <div class="toolbar-divider"></div>
    
    <!-- Move to -->
    <div class="relative">
      <button @click="showMoveMenu = !showMoveMenu" class="toolbar-btn" :disabled="processing" title="Move to">
        <span class="material-symbols-rounded text-xl">drive_file_move</span>
      </button>
      
      <div 
        v-if="showMoveMenu" 
        class="absolute left-0 top-full mt-1 w-48 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 z-50 py-1 max-h-64 overflow-y-auto"
      >
        <button
          v-for="folder in moveFolders"
          :key="folder.name"
          @click="moveSelected(folder.name)"
          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-200"
        >
          <span class="material-symbols-rounded text-lg text-surface-400">folder</span>
          {{ getFolderDisplayName(folder.name) }}
        </button>
      </div>
    </div>
    
    <div class="toolbar-divider"></div>
    
    <!-- Create Rule -->
    <button @click="createFilter" class="toolbar-btn" title="Create rule from selection">
      <span class="material-symbols-rounded text-xl">rule</span>
    </button>
  </div>
  
  <!-- Backdrop for move menu -->
  <div v-if="showMoveMenu" class="fixed inset-0 z-40" @click="showMoveMenu = false"></div>
  
  <!-- Filter Modal -->
  <FilterModal 
    :show="showFilterModal" 
    :initial-data="filterInitialData"
    @close="showFilterModal = false; mailbox.clearSelection()"
    @saved="showFilterModal = false; mailbox.clearSelection()"
  />
</template>

<style scoped>
.toolbar-btn {
  @apply w-8 h-8 flex items-center justify-center rounded-full text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors disabled:opacity-40 disabled:pointer-events-none;
}

.toolbar-divider {
  @apply w-px h-5 bg-surface-300 dark:bg-surface-600 mx-0.5;
}

/* Mobile: bigger, finger-friendly touch targets (Apple HIG ~44pt minimum). */
.is-mobile {
  @apply gap-1.5;
}
.is-mobile .toolbar-btn {
  @apply w-11 h-11;
}
.is-mobile .toolbar-btn .material-symbols-rounded {
  font-size: 1.625rem;
}
</style>
