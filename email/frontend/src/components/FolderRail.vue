<script setup>
import { computed, onMounted } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'
import { useLayoutStore } from '@/stores/layout'
import { useComposeStore } from '@/stores/compose'
import { useSearchStore } from '@/addons/universal-search/stores/search'
import { useSmartViewsStore } from '@/stores/smartViews'
import { useI18n } from 'vue-i18n'
import logoUrl from '@/assets/flowone-logo.png'

const { t } = useI18n()
const mailbox = useMailboxStore()
const layout = useLayoutStore()
const compose = useComposeStore()
const searchStore = useSearchStore()
const smartViews = useSmartViewsStore()

// Lazy fetch: rail mounts on collapse — make sure built-ins+saved are ready
// for the icon strip below. (Store guards against duplicate fetches.)
onMounted(() => { smartViews.fetch() })

// Cap the rail strip so it never overflows on short screens. Built-ins are
// always shown; saved views fill the remaining slots up to RAIL_VIEW_LIMIT.
const RAIL_VIEW_LIMIT = 8
const railSmartViews = computed(() => smartViews.allViews.slice(0, RAIL_VIEW_LIMIT))

function smartViewIconColor(view) {
  const map = {
    primary: 'text-primary-500', red: 'text-red-500', orange: 'text-orange-500',
    amber: 'text-amber-500', yellow: 'text-yellow-500', lime: 'text-lime-500',
    green: 'text-green-500', emerald: 'text-emerald-500', teal: 'text-teal-500',
    cyan: 'text-cyan-500', sky: 'text-sky-500', blue: 'text-blue-500',
    indigo: 'text-indigo-500', violet: 'text-violet-500', purple: 'text-purple-500',
    fuchsia: 'text-fuchsia-500', pink: 'text-pink-500', rose: 'text-rose-500',
  }
  return map[view.color] || 'text-primary-500'
}
function isActiveSmartView(view) {
  return String(smartViews.activeId) === String(view.id)
}

const emit = defineEmits(['folder-selected'])

// Rail folder set — keep it tight. The full panel (FolderTree) handles user
// folders, smart views, reorder, DnD, etc. The rail is just one-tap access to
// the everyday system folders.
const RAIL_TYPES = ['inbox', 'sent', 'drafts', 'trash', 'spam', 'archive']

function systemFolderByType(type) {
  return mailbox.folders.find(f => f.type === type) || null
}

// Virtual folders (not in mailbox.folders) — mirror FolderTree.vue.
const allMailFolder = computed(() => ({
  name: 'ALL_MAIL',
  type: 'all_mail',
  unread: 0,
  total: 0,
  virtual: true,
}))

const scheduledFolder = computed(() => ({
  name: 'SCHEDULED',
  type: 'scheduled',
  unread: 0,
  total: mailbox.scheduledCount || 0,
  virtual: true,
}))

const railFolders = computed(() => {
  const out = []
  // Inbox first
  const inbox = systemFolderByType('inbox') || mailbox.folders.find(f => f.name === 'INBOX')
  if (inbox) out.push(inbox)
  // All Mail (virtual)
  out.push(allMailFolder.value)
  // Sent
  const sent = systemFolderByType('sent')
  if (sent) out.push(sent)
  // Scheduled (virtual)
  out.push(scheduledFolder.value)
  // Remaining system folders in stable order
  for (const t of ['drafts', 'trash', 'spam', 'archive']) {
    const f = systemFolderByType(t)
    if (f) out.push(f)
  }
  // De-dup (defensive — folders can appear under multiple names on some IMAP servers)
  const seen = new Set()
  return out.filter(f => {
    if (!f || seen.has(f.name)) return false
    seen.add(f.name)
    return true
  })
})

const ICONS = {
  inbox: 'inbox',
  all_mail: 'all_inbox',
  sent: 'send',
  scheduled: 'schedule_send',
  drafts: 'draft',
  trash: 'delete',
  spam: 'report',
  junk: 'report',
  archive: 'archive',
}

function iconFor(folder) {
  return ICONS[folder.type] || 'folder'
}

function labelFor(folder) {
  if (folder.name === 'INBOX') return t('folderTree.inbox')
  if (folder.name === 'ALL_MAIL') return t('folderTree.allMail')
  if (folder.name === 'SCHEDULED') return t('folderTree.scheduled')
  const last = folder.name.split('.').pop()
  if (last === 'Sent') return t('folderTree.sent')
  if (last === 'Drafts') return t('folderTree.drafts')
  if (last === 'Deleted Items' || last === 'Trash') return t('folderTree.trash')
  if (last === 'Junk E-mail' || last === 'Spam' || last === 'Junk') return t('folderTree.spam')
  if (last === 'Archive') return t('folderTree.archive')
  return last
}

function unreadFor(folder) {
  if (folder.name === 'SCHEDULED') return mailbox.scheduledCount || 0
  return folder.unread || 0
}

function isActive(folder) {
  return mailbox.currentFolder === folder.name
}

async function selectFolder(folder) {
  // Use target-folder-scoped clear (see FolderTree.vue and mailbox.js
  // clearFolderView for the "jumps back to Inbox" race rationale).
  if (folder.name === 'ALL_MAIL') {
    mailbox.clearFolderView('ALL_MAIL')
    await mailbox.fetchAllMail()
    mailbox.clearCurrentMessage()
    emit('folder-selected', folder)
    return
  }
  if (folder.name === 'SCHEDULED') {
    mailbox.clearFolderView('SCHEDULED')
    await mailbox.fetchScheduledEmails()
    mailbox.clearCurrentMessage()
    emit('folder-selected', folder)
    return
  }
  await mailbox.fetchMessages(folder.name, 1)
  mailbox.clearCurrentMessage()
  emit('folder-selected', folder)
}

function expand() {
  layout.setSidebarCollapsed(false)
}
</script>

<template>
  <div class="h-full flex flex-col items-center py-3 gap-1">
    <!-- Logo: click to expand the sidebar -->
    <button
      type="button"
      @click="expand"
      class="w-10 h-10 rounded-xl flex items-center justify-center overflow-hidden hover:opacity-80 transition-opacity"
      :title="t('folderTree.expandSidebar')"
    >
      <img :src="logoUrl" alt="FlowOne" class="w-full h-full object-contain" />
    </button>

    <!-- Expand toggle right under the logo -->
    <button
      type="button"
      @click="layout.toggleSidebarCollapsed()"
      class="w-10 h-8 rounded-lg flex items-center justify-center text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
      :title="t('folderTree.expandSidebar')"
    >
      <span class="material-symbols-rounded text-lg">dock_to_right</span>
    </button>

    <div class="w-8 h-px bg-surface-200 dark:bg-surface-700 my-1"></div>

    <!-- Compose -->
    <button
      type="button"
      @click="compose.open('new')"
      class="w-10 h-10 rounded-xl bg-primary-500 hover:bg-primary-600 text-white flex items-center justify-center shadow-sm transition-colors"
      :title="t('folderTree.compose')"
    >
      <span class="material-symbols-rounded text-lg">edit</span>
    </button>

    <!-- Super Search (Ctrl+K) -->
    <button
      type="button"
      @click="searchStore.openSearch()"
      class="w-10 h-10 rounded-xl bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300 border border-surface-200 dark:border-surface-700 flex items-center justify-center transition-colors"
      :title="t('folderTree.searchAll') + ' (Ctrl+K)'"
    >
      <span class="material-symbols-rounded text-lg">manage_search</span>
    </button>

    <div class="w-8 h-px bg-surface-200 dark:bg-surface-700 my-1"></div>

    <!-- Folder icons -->
    <nav class="flex-1 w-full overflow-y-auto flex flex-col items-center gap-0.5">
      <button
        v-for="folder in railFolders"
        :key="folder.name"
        type="button"
        @click="selectFolder(folder)"
        :title="`${labelFor(folder)}${unreadFor(folder) ? ' (' + unreadFor(folder) + ')' : ''}`"
        :class="[
          'relative w-10 h-10 rounded-xl flex items-center justify-center transition-colors',
          isActive(folder)
            ? 'bg-primary-50 dark:bg-primary-500/15 text-primary-600 dark:text-primary-300'
            : 'text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-800 hover:text-surface-700 dark:hover:text-surface-200'
        ]"
      >
        <span class="material-symbols-rounded text-lg">{{ iconFor(folder) }}</span>
        <span
          v-if="unreadFor(folder) > 0"
          class="absolute top-1 right-1 min-w-[8px] h-2 rounded-full bg-primary-500"
        ></span>
      </button>

      <!-- Smart Views — compact icon strip -->
      <div v-if="railSmartViews.length" class="w-8 h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
      <button
        v-for="view in railSmartViews"
        :key="`sv-${view.id}`"
        type="button"
        @click="smartViews.run(view); emit('folder-selected', { name: 'SEARCH_RESULTS', smartView: view })"
        :title="view.name"
        :class="[
          'relative w-10 h-10 rounded-xl flex items-center justify-center transition-colors',
          isActiveSmartView(view)
            ? 'bg-primary-50 dark:bg-primary-500/15'
            : 'hover:bg-surface-100 dark:hover:bg-surface-800'
        ]"
      >
        <span
          class="material-symbols-rounded text-lg"
          :class="isActiveSmartView(view) ? smartViewIconColor(view) : 'text-surface-500 dark:text-surface-400 group-hover:' + smartViewIconColor(view)"
        >
          {{ view.icon || 'filter_alt' }}
        </span>
      </button>
    </nav>
  </div>
</template>
