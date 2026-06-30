<script setup>
import { ref, computed, onMounted } from 'vue'
import { useDriveStore } from '@/stores/drive'
import { useToastStore } from '@/stores/toast'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import { getPublicOrigin } from '@/services/serverRegistry'

const drive = useDriveStore()
const toast = useToastStore()

const activeTab = ref('shared_by_me') // 'shared_by_me' | 'shared_with_me'

// Collapsible sections
const collapsedSections = ref({})

function toggleSection(key) {
  collapsedSections.value[key] = !collapsedSections.value[key]
}

function isSectionCollapsed(key) {
  return !!collapsedSections.value[key]
}

// Confirm modal for revoke
const showConfirmRevoke = ref(false)
const revokeTarget = ref(null)

// Role editing
const editingRole = ref(null) // { type, id, email, currentRole }

const data = computed(() => drive.sharingOverview)
const loading = computed(() => drive.loadingSharingOverview)

// Count helpers
const sharedByMeCount = computed(() => {
  if (!data.value?.shared_by_me) return 0
  const d = data.value.shared_by_me
  return (d.drive_files?.length || 0) +
    (d.drive_folders?.length || 0) +
    (d.boards?.length || 0) +
    (d.calendars?.length || 0) +
    (d.moodboards?.length || 0) +
    (d.collab_docs?.length || 0)
})

const sharedWithMeCount = computed(() => {
  if (!data.value?.shared_with_me) return 0
  const d = data.value.shared_with_me
  return (d.drive_files?.length || 0) +
    (d.drive_folders?.length || 0) +
    (d.boards?.length || 0) +
    (d.calendars?.length || 0) +
    (d.moodboards?.length || 0) +
    (d.collab_docs?.length || 0)
})

// Section definitions for "Shared by me"
const sharedByMeSections = computed(() => {
  if (!data.value?.shared_by_me) return []
  const d = data.value.shared_by_me
  return [
    { key: 'drive_files', label: 'Drive Files', icon: 'description', items: d.drive_files || [], color: 'blue' },
    { key: 'drive_folders', label: 'Drive Folders', icon: 'folder', items: d.drive_folders || [], color: 'amber' },
    { key: 'boards', label: 'Boards', icon: 'dashboard', items: d.boards || [], color: 'purple' },
    { key: 'calendars', label: 'Calendars', icon: 'calendar_month', items: d.calendars || [], color: 'green' },
    { key: 'moodboards', label: 'Mood Boards', icon: 'palette', items: d.moodboards || [], color: 'pink' },
    { key: 'collab_docs', label: 'Documents', icon: 'edit_document', items: d.collab_docs || [], color: 'cyan' },
  ].filter(s => s.items.length > 0)
})

const sharedWithMeSections = computed(() => {
  if (!data.value?.shared_with_me) return []
  const d = data.value.shared_with_me
  return [
    { key: 'drive_files', label: 'Drive Files', icon: 'description', items: d.drive_files || [], color: 'blue' },
    { key: 'drive_folders', label: 'Drive Folders', icon: 'folder_shared', items: d.drive_folders || [], color: 'amber' },
    { key: 'boards', label: 'Boards', icon: 'dashboard', items: d.boards || [], color: 'purple' },
    { key: 'calendars', label: 'Calendars', icon: 'calendar_month', items: d.calendars || [], color: 'green' },
    { key: 'moodboards', label: 'Mood Boards', icon: 'palette', items: d.moodboards || [], color: 'pink' },
    { key: 'collab_docs', label: 'Documents', icon: 'edit_document', items: d.collab_docs || [], color: 'cyan' },
  ].filter(s => s.items.length > 0)
})

// Role badge colors
function roleBadgeClass(role) {
  const map = {
    viewer: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    editor: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
    admin: 'bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300',
    view: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    edit: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
  }
  return map[role] || 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300'
}

// Section color classes
function sectionHeaderClass(color) {
  const map = {
    blue: 'text-blue-600 dark:text-blue-400',
    amber: 'text-amber-600 dark:text-amber-400',
    purple: 'text-purple-600 dark:text-purple-400',
    green: 'text-green-600 dark:text-green-400',
    pink: 'text-pink-600 dark:text-pink-400',
    cyan: 'text-cyan-600 dark:text-cyan-400',
  }
  return map[color] || ''
}

function sectionBgClass(color) {
  const map = {
    blue: 'bg-blue-50 dark:bg-blue-500/5',
    amber: 'bg-amber-50 dark:bg-amber-500/5',
    purple: 'bg-purple-50 dark:bg-purple-500/5',
    green: 'bg-green-50 dark:bg-green-500/5',
    pink: 'bg-pink-50 dark:bg-pink-500/5',
    cyan: 'bg-cyan-50 dark:bg-cyan-500/5',
  }
  return map[color] || ''
}

// Format helpers
function formatDate(dateStr) {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  if (isNaN(d)) return '-'
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB'
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return bytes + ' B'
}

function shareUrl(token) {
  if (!token) return ''
  return `${getPublicOrigin()}/share/${token}`
}

function copyShareLink(token) {
  const url = shareUrl(token)
  navigator.clipboard.writeText(url)
  toast.show('Link copied to clipboard', 'success')
}

// Revoke flow
function confirmRevoke(type, id, targetEmail, label) {
  revokeTarget.value = { type, id, targetEmail, label }
  showConfirmRevoke.value = true
}

async function executeRevoke() {
  if (!revokeTarget.value) return
  const { type, id, targetEmail } = revokeTarget.value
  const ok = await drive.revokeAccess(type, id, targetEmail)
  if (ok) {
    toast.show('Access revoked', 'success')
  } else {
    toast.show('Failed to revoke access', 'error')
  }
  showConfirmRevoke.value = false
  revokeTarget.value = null
}

// Role update
function startEditRole(type, id, email, currentRole) {
  editingRole.value = { type, id, email, currentRole }
}

async function saveRole(newRole) {
  if (!editingRole.value) return
  const { type, id, email } = editingRole.value
  const ok = await drive.updateAccessRole(type, id, email, newRole)
  if (ok) {
    toast.show('Role updated', 'success')
  } else {
    toast.show('Failed to update role', 'error')
  }
  editingRole.value = null
}

function cancelEditRole() {
  editingRole.value = null
}

// Available roles per type
function rolesForType(type) {
  const map = {
    drive_folder_collab: ['viewer', 'editor'],
    drive_file_collab: ['viewer', 'editor'],
    board_member: ['viewer', 'editor', 'admin'],
    calendar_share: ['view', 'edit'],
    mood_member: ['viewer', 'editor', 'admin'],
    collab_perm: ['viewer', 'editor'],
  }
  return map[type] || []
}

// Determine the revoke type for shared-by-me items
function revokeTypeForSection(sectionKey, item, hasLink) {
  if (sectionKey === 'drive_files' && hasLink) return 'drive_file_link'
  if (sectionKey === 'drive_files') return 'drive_file_collab'
  if (sectionKey === 'drive_folders' && hasLink) return 'drive_folder_link'
  if (sectionKey === 'drive_folders') return 'drive_folder_collab'
  if (sectionKey === 'boards') return 'board_member'
  if (sectionKey === 'calendars') return 'calendar_share'
  if (sectionKey === 'moodboards') return 'mood_member'
  if (sectionKey === 'collab_docs') return 'collab_perm'
  return ''
}

// Get people list for an item (shared by me)
function getPeople(sectionKey, item) {
  if (sectionKey === 'drive_files') return item.collaborators || []
  if (sectionKey === 'drive_folders') return item.collaborators || []
  if (sectionKey === 'boards') return item.members || []
  if (sectionKey === 'calendars') return item.shared_with || []
  if (sectionKey === 'moodboards') return item.members || []
  if (sectionKey === 'collab_docs') return item.shared_with || []
  return []
}

function getPersonEmail(sectionKey, person) {
  if (sectionKey === 'calendars') return person.email
  return person.email || person.collab_email
}

function getPersonRole(sectionKey, person) {
  return person.role || person.permission || person.collab_permission || person.my_role || person.my_permission || '-'
}

onMounted(() => {
  if (!data.value) {
    drive.fetchSharingOverview()
  }
})
</script>

<template>
  <div class="p-4 md:p-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <button
          @click="drive.exitSharingAccessView()"
          class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-xl">arrow_back</span>
        </button>
        <div>
          <h1 class="text-xl font-semibold text-surface-800 dark:text-surface-100">Sharing & Access</h1>
          <p class="text-sm text-surface-500 dark:text-surface-400">Manage all your shared resources in one place</p>
        </div>
      </div>
      <button
        @click="drive.fetchSharingOverview()"
        :disabled="loading"
        class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        title="Refresh"
      >
        <span class="material-symbols-rounded text-xl" :class="loading ? 'animate-spin' : ''">refresh</span>
      </button>
    </div>

    <!-- Sub-tabs -->
    <div class="flex gap-1 p-1 mb-6 bg-surface-100 dark:bg-surface-800 rounded-2xl w-fit">
      <button
        @click="activeTab = 'shared_by_me'"
        :class="[
          'px-5 py-2 rounded-xl text-sm font-medium transition-all',
          activeTab === 'shared_by_me'
            ? 'bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-100 shadow-sm'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200'
        ]"
      >
        Shared by me
        <span
          v-if="sharedByMeCount > 0"
          class="ml-1.5 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-xs font-semibold bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300"
        >{{ sharedByMeCount }}</span>
      </button>
      <button
        @click="activeTab = 'shared_with_me'"
        :class="[
          'px-5 py-2 rounded-xl text-sm font-medium transition-all',
          activeTab === 'shared_with_me'
            ? 'bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-100 shadow-sm'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200'
        ]"
      >
        Shared with me
        <span
          v-if="sharedWithMeCount > 0"
          class="ml-1.5 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-xs font-semibold bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300"
        >{{ sharedWithMeCount }}</span>
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading && !data" class="flex items-center justify-center py-20">
      <span class="material-symbols-rounded text-3xl animate-spin text-primary-500">progress_activity</span>
    </div>

    <!-- Empty state -->
    <div
      v-else-if="(activeTab === 'shared_by_me' && sharedByMeCount === 0) || (activeTab === 'shared_with_me' && sharedWithMeCount === 0)"
      class="flex flex-col items-center justify-center py-20 text-center"
    >
      <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-4">
        {{ activeTab === 'shared_by_me' ? 'share' : 'folder_shared' }}
      </span>
      <p class="text-surface-500 dark:text-surface-400 text-sm">
        {{ activeTab === 'shared_by_me' ? 'You haven\'t shared anything yet' : 'Nothing has been shared with you' }}
      </p>
    </div>

    <!-- SHARED BY ME -->
    <div v-else-if="activeTab === 'shared_by_me'" class="space-y-4">
      <div
        v-for="section in sharedByMeSections"
        :key="section.key"
        class="rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden"
      >
        <!-- Section header -->
        <button
          @click="toggleSection('byme_' + section.key)"
          :class="[
            'w-full flex items-center justify-between px-4 py-3 text-left transition-colors',
            sectionBgClass(section.color)
          ]"
        >
          <div class="flex items-center gap-2">
            <span :class="['material-symbols-rounded text-lg', sectionHeaderClass(section.color)]">{{ section.icon }}</span>
            <span :class="['text-sm font-semibold', sectionHeaderClass(section.color)]">{{ section.label }}</span>
            <span class="text-xs text-surface-400 dark:text-surface-500">({{ section.items.length }})</span>
          </div>
          <span class="material-symbols-rounded text-lg text-surface-400 transition-transform" :class="isSectionCollapsed('byme_' + section.key) ? '-rotate-90' : ''">expand_more</span>
        </button>

        <!-- Section content -->
        <div v-if="!isSectionCollapsed('byme_' + section.key)" class="divide-y divide-surface-100 dark:divide-surface-800">
          <div
            v-for="item in section.items"
            :key="item.id"
            class="px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
          >
            <div class="flex items-start justify-between gap-3">
              <!-- Item info -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="font-medium text-sm text-surface-800 dark:text-surface-100 truncate">
                    {{ item.name || item.title || 'Untitled' }}
                  </span>
                  <span v-if="item.is_email_attachment" class="text-xs px-2 py-0.5 rounded-full bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300">email attachment</span>
                  <span v-if="item.has_public_link" class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300">public link</span>
                </div>

                <!-- File info row -->
                <div class="flex items-center gap-3 text-xs text-surface-400 dark:text-surface-500 flex-wrap">
                  <span v-if="item.size">{{ formatSize(item.size) }}</span>
                  <span v-if="item.created_at">{{ formatDate(item.created_at) }}</span>
                  <span v-if="item.download_count != null" class="flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-xs">download</span>
                    {{ item.download_count }}{{ item.max_downloads ? '/' + item.max_downloads : '' }}
                  </span>
                  <span v-if="item.expires" class="flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-xs">schedule</span>
                    Expires {{ formatDate(item.expires) }}
                  </span>
                </div>

                <!-- Public link row -->
                <div v-if="item.share_token" class="mt-2 flex items-center gap-2">
                  <input
                    type="text"
                    :value="shareUrl(item.share_token)"
                    readonly
                    class="flex-1 text-xs px-2 py-1 rounded-lg bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 text-surface-500 dark:text-surface-400 truncate"
                  />
                  <button
                    @click="copyShareLink(item.share_token)"
                    class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    title="Copy link"
                  >
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                  </button>
                  <button
                    @click="confirmRevoke(section.key === 'drive_files' ? 'drive_file_link' : 'drive_folder_link', item.id, null, item.name || item.title)"
                    class="p-1 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10 text-red-500 transition-colors"
                    title="Remove link"
                  >
                    <span class="material-symbols-rounded text-sm">link_off</span>
                  </button>
                </div>

                <!-- People list -->
                <div v-if="getPeople(section.key, item).length > 0" class="mt-2 space-y-1">
                  <div
                    v-for="person in getPeople(section.key, item)"
                    :key="getPersonEmail(section.key, person)"
                    class="flex items-center justify-between gap-2 py-1"
                  >
                    <div class="flex items-center gap-2 min-w-0">
                      <span class="material-symbols-rounded text-sm text-surface-400">person</span>
                      <span class="text-xs text-surface-600 dark:text-surface-300 truncate">{{ getPersonEmail(section.key, person) }}</span>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <!-- Role badge / editor -->
                      <template v-if="editingRole && editingRole.type === revokeTypeForSection(section.key, item, false) && editingRole.id === item.id && editingRole.email === getPersonEmail(section.key, person)">
                        <select
                          :value="editingRole.currentRole"
                          @change="saveRole($event.target.value)"
                          class="text-xs px-2 py-1 rounded-lg bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-700"
                        >
                          <option v-for="r in rolesForType(editingRole.type)" :key="r" :value="r">{{ r }}</option>
                        </select>
                        <button @click="cancelEditRole()" class="p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700">
                          <span class="material-symbols-rounded text-sm">close</span>
                        </button>
                      </template>
                      <template v-else>
                        <button
                          @click="startEditRole(revokeTypeForSection(section.key, item, false), item.id, getPersonEmail(section.key, person), getPersonRole(section.key, person))"
                          :class="['text-xs px-2 py-0.5 rounded-full cursor-pointer hover:opacity-80 transition-opacity', roleBadgeClass(getPersonRole(section.key, person))]"
                        >{{ getPersonRole(section.key, person) }}</button>
                        <button
                          @click="confirmRevoke(revokeTypeForSection(section.key, item, false), item.id, getPersonEmail(section.key, person), (item.name || item.title) + ' - ' + getPersonEmail(section.key, person))"
                          class="p-0.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10 text-red-400 hover:text-red-500 transition-colors"
                          title="Remove access"
                        >
                          <span class="material-symbols-rounded text-sm">person_remove</span>
                        </button>
                      </template>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- SHARED WITH ME -->
    <div v-else-if="activeTab === 'shared_with_me'" class="space-y-4">
      <div
        v-for="section in sharedWithMeSections"
        :key="section.key"
        class="rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden"
      >
        <!-- Section header -->
        <button
          @click="toggleSection('withme_' + section.key)"
          :class="[
            'w-full flex items-center justify-between px-4 py-3 text-left transition-colors',
            sectionBgClass(section.color)
          ]"
        >
          <div class="flex items-center gap-2">
            <span :class="['material-symbols-rounded text-lg', sectionHeaderClass(section.color)]">{{ section.icon }}</span>
            <span :class="['text-sm font-semibold', sectionHeaderClass(section.color)]">{{ section.label }}</span>
            <span class="text-xs text-surface-400 dark:text-surface-500">({{ section.items.length }})</span>
          </div>
          <span class="material-symbols-rounded text-lg text-surface-400 transition-transform" :class="isSectionCollapsed('withme_' + section.key) ? '-rotate-90' : ''">expand_more</span>
        </button>

        <!-- Section content -->
        <div v-if="!isSectionCollapsed('withme_' + section.key)" class="divide-y divide-surface-100 dark:divide-surface-800">
          <div
            v-for="item in section.items"
            :key="item.id"
            class="px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0 flex-1">
                <span class="font-medium text-sm text-surface-800 dark:text-surface-100 truncate block">
                  {{ item.name || item.title || 'Untitled' }}
                </span>
                <div class="flex items-center gap-3 text-xs text-surface-400 dark:text-surface-500 mt-0.5">
                  <span v-if="item.owner" class="flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-xs">person</span>
                    {{ item.owner }}
                  </span>
                  <span v-if="item.shared_at">{{ formatDate(item.shared_at) }}</span>
                </div>
              </div>
              <span
                :class="['text-xs px-2.5 py-1 rounded-full', roleBadgeClass(item.my_role || item.my_permission)]"
              >{{ item.my_role || item.my_permission || '-' }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm revoke modal -->
    <ConfirmModal
      :show="showConfirmRevoke"
      title="Revoke Access"
      :message="`Remove access for '${revokeTarget?.label}'? This action cannot be undone.`"
      confirm-text="Revoke"
      type="danger"
      @confirm="executeRevoke"
      @cancel="showConfirmRevoke = false; revokeTarget = null"
    />
  </div>
</template>

