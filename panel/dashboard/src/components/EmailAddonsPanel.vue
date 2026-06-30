<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import Toggle from '@/components/Toggle.vue'

const toast = useToastStore()

// Inline input class to guarantee styles in teleported modals
const inputCls = 'w-full px-4 py-2.5 rounded-xl text-sm bg-surface-50 dark:bg-[rgb(var(--color-bg))] border border-surface-200 dark:border-[rgb(var(--color-border-strong))] text-surface-900 dark:text-surface-100 placeholder:text-surface-400 dark:placeholder:text-surface-500 focus:outline-none focus:ring-2 focus:ring-primary-500/40 focus:border-primary-500 dark:focus:border-primary-400 transition-colors duration-200'
const labelCls = 'block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1.5'

// ── State ───────────────────────────────────────────────────────────
const users = ref([])
const groups = ref([])
const addons = ref([])
const loading = ref(true)
const activeSubTab = ref('addons') // 'addons' | 'users' | 'groups'
const search = ref('')
const activeDomainFilter = ref('all')
const selectedUsers = ref(new Set())
const expandedUser = ref(null) // email of expanded user

// Addon toggle state
const addonLoading = ref(true)
const toggling = ref(null)

// Group modal
const groupModal = ref({ show: false, editing: null })
const groupForm = ref({ name: '', description: '', color: '#6366f1', icon: 'group' })
const groupSaving = ref(false)

// Quick-create group from selection
const quickGroupModal = ref({ show: false })
const quickGroupForm = ref({ name: '', description: '', color: '#6366f1', icon: 'group' })

// Delete confirm modal
const deleteModal = ref({ show: false, group: null })
const deleting = ref(false)

// Add-to-group modal
const addToGroupModal = ref({ show: false, group: null })
const addToGroupSearch = ref('')
const selectedEmails = ref([])

// Assign addon to selection
const assignAddonModal = ref({ show: false })
const assignAddonSlug = ref('')
const assignAddonEnabled = ref(true)

// Assignment state
const assignSaving = ref(null)

// Session history
const userSessions = ref({})   // { [email]: { loading, sessions } }
const sessionsExpanded = ref(null) // email of expanded sessions

// ── Computed ────────────────────────────────────────────────────────

// Extract unique domains from user emails
const domainFilters = computed(() => {
  const domains = {}
  users.value.forEach(u => {
    const domain = u.email.split('@')[1]
    if (domain) {
      domains[domain] = (domains[domain] || 0) + 1
    }
  })
  // Sort by count desc
  return Object.entries(domains)
    .sort((a, b) => b[1] - a[1])
    .map(([domain, count]) => ({ domain, count }))
})

const filteredUsers = computed(() => {
  let list = users.value

  // Domain filter
  if (activeDomainFilter.value !== 'all') {
    list = list.filter(u => u.email.endsWith('@' + activeDomainFilter.value))
  }

  // Text search
  if (search.value) {
    const q = search.value.toLowerCase().trim()
    list = list.filter(u =>
      u.email.toLowerCase().includes(q) ||
      (u.display_name && u.display_name.toLowerCase().includes(q)) ||
      (u.department && u.department.toLowerCase().includes(q)) ||
      (u.last_browser && u.last_browser.toLowerCase().includes(q)) ||
      (u.last_os && u.last_os.toLowerCase().includes(q))
    )
  }

  return list
})

const allFilteredSelected = computed(() => {
  if (filteredUsers.value.length === 0) return false
  return filteredUsers.value.every(u => selectedUsers.value.has(u.email))
})

const someSelected = computed(() => selectedUsers.value.size > 0)

const availableEmailsForGroup = computed(() => {
  if (!addToGroupModal.value.group) return []
  const existing = new Set((addToGroupModal.value.group.members || []).map(m => m.email))
  let list = users.value.filter(u => !existing.has(u.email))
  if (addToGroupSearch.value) {
    const q = addToGroupSearch.value.toLowerCase()
    list = list.filter(u =>
      u.email.toLowerCase().includes(q) ||
      (u.display_name && u.display_name.toLowerCase().includes(q))
    )
  }
  return list
})

const groupColors = [
  '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e',
  '#f97316', '#eab308', '#22c55e', '#14b8a6',
  '#06b6d4', '#3b82f6', '#6b7280', '#78716c',
]

const groupIcons = [
  'group', 'diversity_3', 'business_center', 'school',
  'engineering', 'support_agent', 'design_services', 'code',
  'analytics', 'campaign', 'science', 'shield_person',
]

// ── Fetch ───────────────────────────────────────────────────────────
async function fetchAll(silent = false) {
  if (!silent) loading.value = true
  try {
    const [usersRes, groupsRes, addonsRes] = await Promise.all([
      api.get('/email-addons/users'),
      api.get('/email-addons/groups'),
      api.get('/addons'),
    ])
    if (usersRes.data.success) users.value = usersRes.data.data.users || []
    if (groupsRes.data.success) groups.value = groupsRes.data.data.groups || []
    if (addonsRes.data.success) addons.value = addonsRes.data.data.addons || []
  } catch (e) {
    console.error('Failed to load email addons data:', e)
    if (!silent) toast.error('Failed to load email addons data')
  } finally {
    loading.value = false
    addonLoading.value = false
  }
}

// ── Addon Toggles (global) ──────────────────────────────────────────
async function toggleAddon(addon) {
  try {
    toggling.value = addon.slug
    const { data } = await api.put(`/addons/${addon.slug}/toggle`)
    if (data.success) {
      const idx = addons.value.findIndex(a => a.slug === addon.slug)
      if (idx !== -1) {
        addons.value[idx] = data.data.addon
      }
      toast.success(data.message || 'Addon toggled')
    } else {
      toast.error(data.error || 'Failed to toggle addon')
    }
  } catch (e) {
    toast.error('Failed to toggle addon')
  } finally {
    toggling.value = null
  }
}

// ── User Selection ──────────────────────────────────────────────────
function toggleUserSelect(email) {
  const s = new Set(selectedUsers.value)
  if (s.has(email)) {
    s.delete(email)
  } else {
    s.add(email)
  }
  selectedUsers.value = s
}

function toggleSelectAll() {
  if (allFilteredSelected.value) {
    // Deselect all filtered
    const s = new Set(selectedUsers.value)
    filteredUsers.value.forEach(u => s.delete(u.email))
    selectedUsers.value = s
  } else {
    // Select all filtered
    const s = new Set(selectedUsers.value)
    filteredUsers.value.forEach(u => s.add(u.email))
    selectedUsers.value = s
  }
}

function clearSelection() {
  selectedUsers.value = new Set()
}

// ── Expanded User — Per-User Addon Toggles ──────────────────────────
function toggleExpandUser(email) {
  expandedUser.value = expandedUser.value === email ? null : email
  // Fetch sessions when expanding a user (if not already fetched)
  if (expandedUser.value === email && !userSessions.value[email]) {
    fetchUserSessions(email)
  }
}

async function fetchUserSessions(email) {
  userSessions.value[email] = { loading: true, sessions: [] }
  try {
    const { data } = await api.get(`/email-addons/users/${encodeURIComponent(email)}/sessions`)
    if (data.success) {
      userSessions.value[email] = { loading: false, sessions: data.data.sessions || [] }
    } else {
      userSessions.value[email] = { loading: false, sessions: [] }
    }
  } catch (e) {
    console.error('Failed to load sessions for', email, e)
    userSessions.value[email] = { loading: false, sessions: [] }
  }
}

function toggleSessionsPanel(email) {
  sessionsExpanded.value = sessionsExpanded.value === email ? null : email
  if (sessionsExpanded.value === email && !userSessions.value[email]) {
    fetchUserSessions(email)
  }
}

async function toggleUserAddon(user, addonSlug, currentOverride) {
  const key = `user:${user.email}:${addonSlug}`
  assignSaving.value = key
  try {
    if (currentOverride !== undefined && currentOverride !== null) {
      // Has override — remove it (revert to global/group)
      await api.delete('/email-addons/assign', {
        data: {
          addon_slug: addonSlug,
          target_type: 'user',
          target_id: user.email,
        }
      })
    } else {
      // No override — create one (opposite of effective state)
      const effective = getEffectiveState(user, addonSlug)
      await api.put('/email-addons/assign', {
        addon_slug: addonSlug,
        target_type: 'user',
        target_id: user.email,
        enabled: !effective,
      })
    }
    await fetchAll(true)
  } catch (e) {
    toast.error('Failed to update assignment')
  } finally {
    assignSaving.value = null
  }
}

async function setUserAddon(user, addonSlug, enabled) {
  const key = `user:${user.email}:${addonSlug}`
  assignSaving.value = key
  try {
    await api.put('/email-addons/assign', {
      addon_slug: addonSlug,
      target_type: 'user',
      target_id: user.email,
      enabled,
    })
    await fetchAll(true)
  } catch (e) {
    toast.error('Failed to update assignment')
  } finally {
    assignSaving.value = null
  }
}

async function removeUserAddon(user, addonSlug) {
  const key = `user:${user.email}:${addonSlug}`
  assignSaving.value = key
  try {
    await api.delete('/email-addons/assign', {
      data: {
        addon_slug: addonSlug,
        target_type: 'user',
        target_id: user.email,
      }
    })
    await fetchAll(true)
  } catch (e) {
    toast.error('Failed to remove override')
  } finally {
    assignSaving.value = null
  }
}

// ── Bulk assign addon to selected users ─────────────────────────────
function openBulkAssignAddon() {
  assignAddonSlug.value = addons.value[0]?.slug || ''
  assignAddonEnabled.value = true
  assignAddonModal.value.show = true
}

async function bulkAssignAddon() {
  if (!assignAddonSlug.value || selectedUsers.value.size === 0) return
  assignSaving.value = 'bulk'
  try {
    const promises = [...selectedUsers.value].map(email =>
      api.put('/email-addons/assign', {
        addon_slug: assignAddonSlug.value,
        target_type: 'user',
        target_id: email,
        enabled: assignAddonEnabled.value,
      })
    )
    await Promise.all(promises)
    toast.success(`Addon ${assignAddonEnabled.value ? 'enabled' : 'disabled'} for ${selectedUsers.value.size} user(s)`)
    assignAddonModal.value.show = false
    await fetchAll(true)
  } catch (e) {
    toast.error('Failed to bulk assign addon')
  } finally {
    assignSaving.value = null
  }
}

// ── Group CRUD ──────────────────────────────────────────────────────
function openCreateGroup() {
  groupForm.value = { name: '', description: '', color: '#6366f1', icon: 'group' }
  groupModal.value = { show: true, editing: null }
}

function openEditGroup(group) {
  groupForm.value = {
    name: group.name,
    description: group.description || '',
    color: group.color || '#6366f1',
    icon: group.icon || 'group',
  }
  groupModal.value = { show: true, editing: group }
}

async function saveGroup() {
  if (!groupForm.value.name.trim()) {
    toast.error('Group name is required')
    return
  }
  groupSaving.value = true
  try {
    if (groupModal.value.editing) {
      const { data } = await api.put(`/email-addons/groups/${groupModal.value.editing.id}`, groupForm.value)
      if (data.success) {
        toast.success('Group updated')
        groupModal.value.show = false
        await fetchAll(true)
      } else {
        toast.error(data.error || 'Failed to update group')
      }
    } else {
      const { data } = await api.post('/email-addons/groups', groupForm.value)
      if (data.success) {
        toast.success('Group created')
        groupModal.value.show = false
        await fetchAll(true)
      } else {
        toast.error(data.error || 'Failed to create group')
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save group')
  } finally {
    groupSaving.value = false
  }
}

// Quick-create group from selected users
function openQuickCreateGroup() {
  quickGroupForm.value = { name: '', description: '', color: '#6366f1', icon: 'group' }
  quickGroupModal.value.show = true
}

async function quickCreateGroup() {
  if (!quickGroupForm.value.name.trim()) {
    toast.error('Group name is required')
    return
  }
  groupSaving.value = true
  try {
    const { data } = await api.post('/email-addons/groups', {
      ...quickGroupForm.value,
      members: [...selectedUsers.value],
    })
    if (data.success) {
      toast.success(`Group "${quickGroupForm.value.name}" created with ${selectedUsers.value.size} member(s)`)
      quickGroupModal.value.show = false
      selectedUsers.value = new Set()
      await fetchAll(true)
      activeSubTab.value = 'groups'
    } else {
      toast.error(data.error || 'Failed to create group')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create group')
  } finally {
    groupSaving.value = false
  }
}

async function confirmDeleteGroup() {
  if (!deleteModal.value.group) return
  deleting.value = true
  try {
    const { data } = await api.delete(`/email-addons/groups/${deleteModal.value.group.id}`)
    if (data.success) {
      toast.success(data.message || 'Group deleted')
      deleteModal.value = { show: false, group: null }
      await fetchAll(true)
    } else {
      toast.error(data.error || 'Failed to delete group')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete group')
  } finally {
    deleting.value = false
  }
}

// ── Group Members ───────────────────────────────────────────────────
function openAddMembers(group) {
  addToGroupModal.value = { show: true, group }
  addToGroupSearch.value = ''
  selectedEmails.value = []
}

function toggleEmailSelection(email) {
  const idx = selectedEmails.value.indexOf(email)
  if (idx >= 0) {
    selectedEmails.value.splice(idx, 1)
  } else {
    selectedEmails.value.push(email)
  }
}

async function addSelectedMembers() {
  if (!selectedEmails.value.length) return
  try {
    const { data } = await api.post(`/email-addons/groups/${addToGroupModal.value.group.id}/members`, {
      emails: selectedEmails.value,
    })
    if (data.success) {
      toast.success(data.message || 'Members added')
      addToGroupModal.value.show = false
      await fetchAll(true)
    } else {
      toast.error(data.error || 'Failed to add members')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add members')
  }
}

async function removeMember(group, email) {
  try {
    const { data } = await api.delete(`/email-addons/groups/${group.id}/members/${encodeURIComponent(email)}`)
    if (data.success) {
      toast.success('Member removed')
      await fetchAll(true)
    } else {
      toast.error(data.error || 'Failed to remove member')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove member')
  }
}

// ── Group Addon Assignments ─────────────────────────────────────────
async function toggleGroupAddon(group, addonSlug) {
  const key = `group:${group.id}:${addonSlug}`
  assignSaving.value = key
  try {
    const currentOverride = group.addon_overrides?.[addonSlug]
    if (currentOverride === undefined || currentOverride === null) {
      const globalAddon = addons.value.find(a => a.slug === addonSlug)
      const globalEnabled = globalAddon ? !!globalAddon.enabled : true
      await api.put('/email-addons/assign', {
        addon_slug: addonSlug,
        target_type: 'group',
        target_id: String(group.id),
        enabled: !globalEnabled,
      })
    } else {
      await api.put('/email-addons/assign', {
        addon_slug: addonSlug,
        target_type: 'group',
        target_id: String(group.id),
        enabled: !currentOverride,
      })
    }
    await fetchAll(true)
  } catch (e) {
    toast.error('Failed to update assignment')
  } finally {
    assignSaving.value = null
  }
}

async function removeGroupAddon(group, addonSlug) {
  const key = `group:${group.id}:${addonSlug}`
  assignSaving.value = key
  try {
    await api.delete('/email-addons/assign', {
      data: {
        addon_slug: addonSlug,
        target_type: 'group',
        target_id: String(group.id),
      }
    })
    await fetchAll(true)
  } catch (e) {
    toast.error('Failed to remove assignment')
  } finally {
    assignSaving.value = null
  }
}

// ── Helpers ─────────────────────────────────────────────────────────
function quizBadgeClass(percent) {
  if (percent >= 90) return 'bg-green-100 dark:bg-green-500/15 text-green-700 dark:text-green-400'
  if (percent >= 70) return 'bg-blue-100 dark:bg-blue-500/15 text-blue-700 dark:text-blue-400'
  if (percent >= 50) return 'bg-amber-100 dark:bg-amber-500/15 text-amber-700 dark:text-amber-400'
  return 'bg-red-100 dark:bg-red-500/15 text-red-700 dark:text-red-400'
}

function timeAgo(dateStr) {
  if (!dateStr) return 'never'
  const d = new Date(dateStr)
  const now = new Date()
  const diff = Math.floor((now - d) / 1000)
  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  if (diff < 2592000) return `${Math.floor(diff / 86400)}d ago`
  return d.toLocaleDateString()
}

function formatDuration(seconds) {
  if (!seconds || seconds < 60) return '< 1m'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (h > 24) {
    const d = Math.floor(h / 24)
    const rh = h % 24
    return rh > 0 ? `${d}d ${rh}h` : `${d}d`
  }
  if (h > 0) return m > 0 ? `${h}h ${m}m` : `${h}h`
  return `${m}m`
}

function formatDate(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
    ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

function getEffectiveState(user, addonSlug) {
  // User override?
  if (user.addon_overrides && user.addon_overrides[addonSlug] !== undefined) {
    return user.addon_overrides[addonSlug]
  }
  // Group override?
  for (const g of (user.groups || [])) {
    const group = groups.value.find(gr => gr.id === g.group_id)
    if (group?.addon_overrides?.[addonSlug] !== undefined) {
      return group.addon_overrides[addonSlug]
    }
  }
  // Global
  const globalAddon = addons.value.find(a => a.slug === addonSlug)
  return globalAddon ? !!globalAddon.enabled : false
}

function getEffectiveSource(user, addonSlug) {
  if (user.addon_overrides && user.addon_overrides[addonSlug] !== undefined) return 'user'
  for (const g of (user.groups || [])) {
    const group = groups.value.find(gr => gr.id === g.group_id)
    if (group?.addon_overrides?.[addonSlug] !== undefined) return 'group'
  }
  return 'global'
}

// ── Lifecycle ───────────────────────────────────────────────────────
onMounted(fetchAll)
</script>

<template>
  <!-- Sub-tab navigation -->
  <div class="flex items-center gap-1 bg-surface-100/50 dark:bg-surface-800/50 p-1 rounded-xl w-fit mb-5">
    <button
      @click="activeSubTab = 'addons'"
      :class="[
        'px-4 py-2 text-sm font-medium rounded-lg transition-all',
        activeSubTab === 'addons'
          ? 'bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 shadow-sm'
          : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
      ]"
    >
      <span class="material-symbols-rounded text-base align-middle mr-1.5">extension</span>
      Addons
    </button>
    <button
      @click="activeSubTab = 'users'"
      :class="[
        'px-4 py-2 text-sm font-medium rounded-lg transition-all',
        activeSubTab === 'users'
          ? 'bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 shadow-sm'
          : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
      ]"
    >
      <span class="material-symbols-rounded text-base align-middle mr-1.5">people</span>
      Users
      <span class="ml-1 text-xs text-surface-400">({{ users.length }})</span>
    </button>
    <button
      @click="activeSubTab = 'groups'"
      :class="[
        'px-4 py-2 text-sm font-medium rounded-lg transition-all',
        activeSubTab === 'groups'
          ? 'bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 shadow-sm'
          : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
      ]"
    >
      <span class="material-symbols-rounded text-base align-middle mr-1.5">workspaces</span>
      Groups
      <span class="ml-1 text-xs text-surface-400">({{ groups.length }})</span>
    </button>
  </div>

  <!-- Loading -->
  <div v-if="loading" class="card p-8 text-center">
    <span class="material-symbols-rounded text-2xl text-surface-300 animate-spin">progress_activity</span>
    <p class="text-sm text-surface-400 mt-2">Loading...</p>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       SUB-TAB: ADDONS (Global toggles)
       ═══════════════════════════════════════════════════════════════════ -->
  <div v-else-if="activeSubTab === 'addons'">
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-surface-100 dark:border-[rgb(var(--color-border))] flex items-center gap-3">
        <span class="material-symbols-rounded text-primary-500 text-xl">extension</span>
        <h3 class="text-sm font-semibold">Email App Addons</h3>
        <span class="text-xs text-surface-400">Global toggles — apply to all users unless overridden</span>
      </div>

      <div v-if="addons.length === 0" class="p-5 text-center text-sm text-surface-400">
        No addons available
      </div>

      <div v-else class="divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
        <div
          v-for="addon in addons"
          :key="addon.slug"
          class="px-5 py-4 flex items-center gap-4"
        >
          <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
            :class="addon.enabled ? 'bg-green-50 dark:bg-green-500/10' : 'bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))]'"
          >
            <span class="material-symbols-rounded text-lg"
              :class="addon.enabled ? 'text-green-600 dark:text-green-400' : 'text-surface-400'"
            >{{ addon.icon || 'extension' }}</span>
          </div>

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium">{{ addon.name }}</span>
              <span
                class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full"
                :class="addon.enabled
                  ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400'
                  : 'bg-surface-100 text-surface-500 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-400'"
              >{{ addon.enabled ? 'Active' : 'Disabled' }}</span>
            </div>
            <p class="text-xs text-surface-500 mt-0.5 line-clamp-2">{{ addon.description }}</p>
            <p v-if="addon.enabled && addon.enabled_at" class="text-[10px] text-surface-400 mt-1">
              Enabled {{ new Date(addon.enabled_at).toLocaleDateString() }}
              <template v-if="addon.enabled_by"> by {{ addon.enabled_by }}</template>
            </p>
          </div>

          <Toggle
            :model-value="!!addon.enabled"
            :disabled="toggling === addon.slug"
            @update:model-value="toggleAddon(addon)"
          />
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       SUB-TAB: USERS
       ═══════════════════════════════════════════════════════════════════ -->
  <div v-else-if="activeSubTab === 'users'">
    <div class="card overflow-hidden">
      <!-- Header with search -->
      <div class="px-5 py-4 border-b border-surface-100 dark:border-[rgb(var(--color-border))]">
        <div class="flex items-center gap-3 mb-3">
          <span class="material-symbols-rounded text-primary-500 text-xl">people</span>
          <h3 class="text-sm font-semibold">Email Users</h3>
          <span class="text-xs text-surface-400">Users who have logged in at least once</span>
        </div>

        <!-- Search -->
        <div class="relative">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
          <input
            v-model="search"
            type="text"
            placeholder="Search by email, name, department, browser..."
            class="w-full pl-10 pr-4 py-2 text-sm rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50 text-surface-900 dark:text-surface-100 placeholder-surface-400 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 transition-colors"
          />
          <button
            v-if="search"
            @click="search = ''"
            class="absolute right-3 top-1/2 -translate-y-1/2"
          >
            <span class="material-symbols-rounded text-surface-400 hover:text-surface-600 text-lg">close</span>
          </button>
        </div>

        <!-- Domain filters -->
        <div v-if="domainFilters.length > 1" class="flex flex-wrap items-center gap-1.5 mt-3">
          <button
            @click="activeDomainFilter = 'all'"
            :class="[
              'px-2.5 py-1 text-xs font-medium rounded-full transition-colors border',
              activeDomainFilter === 'all'
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 border-primary-300 dark:border-primary-500/30'
                : 'border-surface-200 dark:border-surface-700 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-800'
            ]"
          >All ({{ users.length }})</button>
          <button
            v-for="df in domainFilters"
            :key="df.domain"
            @click="activeDomainFilter = df.domain"
            :class="[
              'px-2.5 py-1 text-xs font-medium rounded-full transition-colors border',
              activeDomainFilter === df.domain
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 border-primary-300 dark:border-primary-500/30'
                : 'border-surface-200 dark:border-surface-700 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-800'
            ]"
          >@{{ df.domain }} ({{ df.count }})</button>
        </div>
      </div>

      <!-- Bulk action bar -->
      <div
        v-if="someSelected"
        class="px-5 py-2.5 bg-primary-50 dark:bg-primary-500/10 border-b border-primary-200 dark:border-primary-500/20 flex items-center justify-between"
      >
        <div class="flex items-center gap-2 text-sm">
          <span class="font-semibold text-primary-700 dark:text-primary-400">{{ selectedUsers.size }}</span>
          <span class="text-primary-600 dark:text-primary-300">user{{ selectedUsers.size !== 1 ? 's' : '' }} selected</span>
        </div>
        <div class="flex items-center gap-2">
          <button @click="openQuickCreateGroup" class="btn-sm bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-1.5 text-xs font-medium flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">group_add</span>
            Create Group
          </button>
          <button @click="openBulkAssignAddon" class="btn-sm bg-surface-600 hover:bg-surface-700 text-white rounded-lg px-3 py-1.5 text-xs font-medium flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">extension</span>
            Assign Addon
          </button>
          <button @click="clearSelection" class="btn-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 text-xs font-medium px-2">
            Clear
          </button>
        </div>
      </div>

      <!-- Empty -->
      <div v-if="filteredUsers.length === 0" class="p-8 text-center">
        <span class="material-symbols-rounded text-3xl text-surface-300">person_off</span>
        <p class="text-sm text-surface-400 mt-2">
          {{ search || activeDomainFilter !== 'all' ? 'No users match your filters' : 'No email users have logged in yet' }}
        </p>
      </div>

      <!-- Users list -->
      <div v-else class="divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
        <!-- Select all header -->
        <div class="px-5 py-2 bg-surface-50/50 dark:bg-surface-800/30 flex items-center gap-3 text-xs text-surface-400">
          <Toggle
            :model-value="allFilteredSelected"
            @update:model-value="toggleSelectAll"
          />
          <span class="flex-1">Email</span>
          <span class="w-24 text-right hidden sm:block">Last active</span>
          <span class="w-32 text-right hidden md:block">Client</span>
        </div>

        <!-- User rows -->
        <div
          v-for="user in filteredUsers"
          :key="user.email"
          class="group/row"
        >
          <div
            class="px-5 py-3 flex items-center gap-3 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800/30 transition-colors"
            :class="{ 'bg-primary-50/50 dark:bg-primary-500/5': selectedUsers.has(user.email) }"
            @click="toggleExpandUser(user.email)"
          >
            <!-- Selection toggle -->
            <div @click.stop>
              <Toggle
                :model-value="selectedUsers.has(user.email)"
                @update:model-value="toggleUserSelect(user.email)"
              />
            </div>

            <!-- Avatar with online indicator -->
            <div class="relative shrink-0">
              <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center text-xs font-semibold text-primary-700 dark:text-primary-400">
                {{ (user.display_name || user.email)[0].toUpperCase() }}
              </div>
              <span
                v-if="user.is_online"
                class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-green-500 border-2 border-white dark:border-surface-900"
                title="Online now"
              ></span>
            </div>

            <!-- Info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-sm font-medium truncate">{{ user.display_name || user.email.split('@')[0] }}</span>
                <span class="text-xs text-surface-400 font-mono truncate hidden sm:inline">{{ user.email }}</span>
              </div>
              <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <!-- Group badges -->
                <span
                  v-for="g in user.groups"
                  :key="g.group_id"
                  class="inline-flex items-center gap-0.5 text-[10px] font-medium px-1.5 py-0.5 rounded-full"
                  :style="{ backgroundColor: g.color + '20', color: g.color }"
                >
                  <span class="material-symbols-rounded" style="font-size: 10px">{{ g.icon }}</span>
                  {{ g.name }}
                </span>
                <!-- Override indicator -->
                <span
                  v-if="user.addon_overrides && Object.keys(user.addon_overrides).length"
                  class="text-[10px] text-amber-600 dark:text-amber-400"
                >{{ Object.keys(user.addon_overrides).length }} override(s)</span>
                <!-- Quiz score badge -->
                <span
                  v-if="user.quiz_score"
                  class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full"
                  :class="quizBadgeClass(user.quiz_score.percent)"
                  :title="`Quiz: ${user.quiz_score.score}/${user.quiz_score.total} (${user.quiz_score.attempts} attempt${user.quiz_score.attempts !== 1 ? 's' : ''})`"
                >
                  <span class="material-symbols-rounded" style="font-size: 11px">quiz</span>
                  {{ user.quiz_score.percent }}%
                </span>
                <span
                  v-else
                  class="inline-flex items-center gap-1 text-[10px] text-surface-400 px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-800"
                  title="Quiz not taken"
                >
                  <span class="material-symbols-rounded" style="font-size: 11px">quiz</span>
                  --
                </span>
              </div>
            </div>

            <!-- Last active -->
            <div class="w-24 text-right hidden sm:block">
              <span class="text-xs text-surface-400">{{ timeAgo(user.last_active) }}</span>
              <p class="text-[10px] text-surface-400">{{ user.session_count }} session{{ user.session_count !== 1 ? 's' : '' }}</p>
            </div>

            <!-- Client info -->
            <div class="w-32 text-right hidden md:block">
              <span class="text-xs text-surface-500">{{ user.last_browser }}</span>
              <p class="text-[10px] text-surface-400">{{ user.last_os }}</p>
            </div>

            <!-- Expand icon -->
            <span
              class="material-symbols-rounded text-surface-400 transition-transform text-lg"
              :class="{ 'rotate-180': expandedUser === user.email }"
            >expand_more</span>
          </div>

          <!-- Expanded: Per-user addon overrides -->
          <Transition name="expand">
            <div
              v-if="expandedUser === user.email"
              class="px-5 pb-4 pt-1 bg-surface-50/50 dark:bg-surface-800/20 border-t border-surface-100 dark:border-surface-800"
            >
              <!-- Onboarding Quiz Score -->
              <div v-if="user.quiz_score" class="ml-11 mb-4">
                <p class="text-[10px] uppercase font-semibold text-surface-400 tracking-wider mb-2">Onboarding Quiz Result</p>
                <div class="flex items-center gap-4 p-3 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50/50 dark:bg-surface-800/30">
                  <div class="relative w-14 h-14 shrink-0">
                    <svg class="w-14 h-14 -rotate-90" viewBox="0 0 56 56">
                      <circle cx="28" cy="28" r="24" stroke-width="4" fill="none" class="stroke-surface-200 dark:stroke-surface-700" />
                      <circle
                        cx="28" cy="28" r="24" stroke-width="4" fill="none"
                        stroke-linecap="round"
                        :stroke="user.quiz_score.percent >= 90 ? '#10b981' : user.quiz_score.percent >= 70 ? '#3b82f6' : user.quiz_score.percent >= 50 ? '#f59e0b' : '#ef4444'"
                        :stroke-dasharray="150.8"
                        :stroke-dashoffset="150.8 - (150.8 * user.quiz_score.percent / 100)"
                      />
                    </svg>
                    <span
                      class="absolute inset-0 flex items-center justify-center text-sm font-bold"
                      :class="user.quiz_score.percent >= 90 ? 'text-green-500' : user.quiz_score.percent >= 70 ? 'text-blue-500' : user.quiz_score.percent >= 50 ? 'text-amber-500' : 'text-red-500'"
                    >{{ user.quiz_score.percent }}%</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-surface-700 dark:text-surface-200">
                      {{ user.quiz_score.score }} / {{ user.quiz_score.total }} correct
                    </p>
                    <p class="text-xs text-surface-400 mt-0.5">
                      {{ user.quiz_score.attempts }} attempt{{ user.quiz_score.attempts !== 1 ? 's' : '' }}
                      <span class="mx-1">·</span>
                      Last taken: {{ timeAgo(user.quiz_score.taken_at) }}
                    </p>
                    <p class="text-xs mt-1" :class="user.quiz_score.percent >= 90 ? 'text-green-600 dark:text-green-400' : user.quiz_score.percent >= 70 ? 'text-blue-600 dark:text-blue-400' : user.quiz_score.percent >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'">
                      {{ user.quiz_score.percent >= 90 ? 'Excellent - Fully understands the platform' : user.quiz_score.percent >= 70 ? 'Good - Solid understanding' : user.quiz_score.percent >= 50 ? 'Fair - May need additional guidance' : 'Needs training - Recommend re-taking the tour' }}
                    </p>
                  </div>
                </div>
              </div>
              <div v-else class="ml-11 mb-4">
                <p class="text-[10px] uppercase font-semibold text-surface-400 tracking-wider mb-2">Onboarding Quiz Result</p>
                <div class="flex items-center gap-3 p-3 rounded-lg border border-dashed border-surface-200 dark:border-surface-700">
                  <span class="material-symbols-rounded text-surface-300 dark:text-surface-600 text-xl">quiz</span>
                  <p class="text-xs text-surface-400">User has not taken the onboarding quiz yet</p>
                </div>
              </div>

              <p class="text-[10px] uppercase font-semibold text-surface-400 tracking-wider mb-2 ml-11">Per-user addon overrides</p>
              <div class="ml-11 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <div
                  v-for="addon in addons"
                  :key="addon.slug"
                  class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg border transition-colors"
                  :class="[
                    user.addon_overrides?.[addon.slug] !== undefined
                      ? user.addon_overrides[addon.slug]
                        ? 'border-green-200 dark:border-green-500/20 bg-green-50/50 dark:bg-green-500/5'
                        : 'border-red-200 dark:border-red-500/20 bg-red-50/50 dark:bg-red-500/5'
                      : 'border-surface-200 dark:border-surface-700'
                  ]"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <span class="material-symbols-rounded text-sm" :class="getEffectiveState(user, addon.slug) ? 'text-green-500' : 'text-surface-400'">
                      {{ addon.icon || 'extension' }}
                    </span>
                    <div class="min-w-0">
                      <span class="text-xs font-medium truncate block">{{ addon.name }}</span>
                      <span
                        class="text-[9px] uppercase font-semibold"
                        :class="{
                          'text-amber-500': getEffectiveSource(user, addon.slug) === 'user',
                          'text-blue-500': getEffectiveSource(user, addon.slug) === 'group',
                          'text-surface-400': getEffectiveSource(user, addon.slug) === 'global',
                        }"
                      >{{ getEffectiveSource(user, addon.slug) }}</span>
                    </div>
                  </div>
                  <div class="flex items-center gap-1 shrink-0">
                    <button
                      v-if="user.addon_overrides?.[addon.slug] !== undefined"
                      @click.stop="removeUserAddon(user, addon.slug)"
                      class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
                      title="Remove override (use global/group setting)"
                    >
                      <span class="material-symbols-rounded text-xs text-surface-400">undo</span>
                    </button>
                    <Toggle
                      :model-value="getEffectiveState(user, addon.slug)"
                      :disabled="assignSaving === `user:${user.email}:${addon.slug}`"
                      @update:model-value="setUserAddon(user, addon.slug, $event)"
                    />
                  </div>
                </div>
              </div>
              <p class="text-[10px] text-surface-400 mt-2 ml-11">
                <span class="text-amber-500">●</span> user override
                <span class="text-blue-500 ml-2">●</span> group override
                <span class="text-surface-400 ml-2">●</span> global setting
              </p>

              <!-- Session History -->
              <div class="mt-4 ml-11">
                <button
                  @click.stop="toggleSessionsPanel(user.email)"
                  class="flex items-center gap-1.5 text-[10px] uppercase font-semibold text-surface-400 tracking-wider hover:text-surface-600 dark:hover:text-surface-300 transition-colors mb-2"
                >
                  <span
                    class="material-symbols-rounded text-xs transition-transform"
                    :class="{ 'rotate-180': sessionsExpanded === user.email }"
                  >expand_more</span>
                  Session History ({{ user.session_count }} session{{ user.session_count !== 1 ? 's' : '' }})
                  <span
                    v-if="user.is_online"
                    class="inline-flex items-center gap-1 normal-case text-green-600 dark:text-green-400 tracking-normal font-medium"
                  >
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                    Online
                  </span>
                </button>

                <Transition name="expand">
                  <div v-if="sessionsExpanded === user.email">
                    <!-- Loading -->
                    <div v-if="userSessions[user.email]?.loading" class="py-3 text-center">
                      <span class="material-symbols-rounded text-lg text-surface-300 animate-spin">progress_activity</span>
                      <p class="text-xs text-surface-400 mt-1">Loading sessions...</p>
                    </div>

                    <!-- No sessions -->
                    <div v-else-if="!userSessions[user.email]?.sessions?.length" class="py-3 text-center text-xs text-surface-400">
                      No session data available
                    </div>

                    <!-- Session list -->
                    <div v-else class="rounded-lg border border-surface-200 dark:border-surface-700 overflow-hidden">
                      <table class="w-full text-xs">
                        <thead>
                          <tr class="bg-surface-50 dark:bg-surface-800/50 text-surface-400 text-left">
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Logged In</th>
                            <th class="px-3 py-2 font-medium hidden sm:table-cell">Duration</th>
                            <th class="px-3 py-2 font-medium hidden md:table-cell">Browser</th>
                            <th class="px-3 py-2 font-medium hidden lg:table-cell">OS</th>
                            <th class="px-3 py-2 font-medium hidden lg:table-cell">IP / Location</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                          <tr
                            v-for="session in userSessions[user.email].sessions"
                            :key="session.id"
                            class="hover:bg-surface-50/50 dark:hover:bg-surface-800/20 transition-colors"
                          >
                            <!-- Status -->
                            <td class="px-3 py-2">
                              <span
                                v-if="session.is_online"
                                class="inline-flex items-center gap-1 text-green-600 dark:text-green-400 font-medium"
                              >
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                                Active
                              </span>
                              <span v-else class="text-surface-400">Offline</span>
                            </td>
                            <!-- Logged In -->
                            <td class="px-3 py-2">
                              <span class="text-surface-700 dark:text-surface-300">{{ formatDate(session.created_at) }}</span>
                            </td>
                            <!-- Duration -->
                            <td class="px-3 py-2 hidden sm:table-cell">
                              <span class="font-medium text-surface-600 dark:text-surface-300">{{ formatDuration(session.duration_seconds) }}</span>
                            </td>
                            <!-- Browser -->
                            <td class="px-3 py-2 hidden md:table-cell text-surface-500">
                              {{ session.browser || '—' }}
                            </td>
                            <!-- OS -->
                            <td class="px-3 py-2 hidden lg:table-cell text-surface-500">
                              {{ session.os || '—' }}
                            </td>
                            <!-- IP / Location -->
                            <td class="px-3 py-2 hidden lg:table-cell">
                              <span class="text-surface-500 font-mono">{{ session.ip_address || '—' }}</span>
                              <span v-if="session.location" class="text-surface-400 ml-1">· {{ session.location }}</span>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </Transition>
              </div>
            </div>
          </Transition>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       SUB-TAB: GROUPS
       ═══════════════════════════════════════════════════════════════════ -->
  <div v-else-if="activeSubTab === 'groups'">
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-surface-100 dark:border-[rgb(var(--color-border))] flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-primary-500 text-xl">workspaces</span>
          <h3 class="text-sm font-semibold">User Groups</h3>
          <span class="text-xs text-surface-400">Manage groups for addon assignment</span>
        </div>
        <button @click="openCreateGroup" class="btn-primary btn-sm">
          <span class="material-symbols-rounded text-sm">add</span>
          New Group
        </button>
      </div>

      <!-- Empty -->
      <div v-if="groups.length === 0" class="p-8 text-center">
        <span class="material-symbols-rounded text-3xl text-surface-300">workspaces</span>
        <p class="text-sm text-surface-400 mt-2">No groups yet — create one to organize users.</p>
        <button @click="openCreateGroup" class="btn-primary btn-sm mt-3">
          <span class="material-symbols-rounded text-sm">add</span>
          Create First Group
        </button>
      </div>

      <!-- Groups list -->
      <div v-else class="divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
        <div
          v-for="group in groups"
          :key="group.id"
          class="px-5 py-4"
        >
          <!-- Group header -->
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div
                class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                :style="{ backgroundColor: group.color + '20' }"
              >
                <span class="material-symbols-rounded text-lg" :style="{ color: group.color }">{{ group.icon }}</span>
              </div>
              <div>
                <div class="flex items-center gap-2">
                  <span class="text-sm font-semibold">{{ group.name }}</span>
                  <span class="text-xs text-surface-400">{{ group.member_count }} member{{ group.member_count !== 1 ? 's' : '' }}</span>
                </div>
                <p v-if="group.description" class="text-xs text-surface-500 mt-0.5">{{ group.description }}</p>
              </div>
            </div>
            <div class="flex items-center gap-1">
              <button
                @click="openAddMembers(group)"
                class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
                title="Add members"
              >
                <span class="material-symbols-rounded text-lg text-surface-500">person_add</span>
              </button>
              <button
                @click="openEditGroup(group)"
                class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
                title="Edit group"
              >
                <span class="material-symbols-rounded text-lg text-surface-500">edit</span>
              </button>
              <button
                @click="deleteModal = { show: true, group }"
                class="p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
                title="Delete group"
              >
                <span class="material-symbols-rounded text-lg text-red-500">delete</span>
              </button>
            </div>
          </div>

          <!-- Members -->
          <div v-if="group.members && group.members.length" class="mt-3 flex flex-wrap gap-1.5">
            <span
              v-for="member in group.members"
              :key="member.email"
              class="inline-flex items-center gap-1.5 text-xs bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] px-2.5 py-1 rounded-full group/member"
            >
              <span class="w-4 h-4 rounded-full bg-primary-200 dark:bg-primary-500/30 flex items-center justify-center text-[9px] font-bold text-primary-700 dark:text-primary-400">
                {{ (member.display_name || member.email)[0].toUpperCase() }}
              </span>
              <span class="truncate max-w-[140px]">{{ member.display_name || member.email.split('@')[0] }}</span>
              <button
                @click="removeMember(group, member.email)"
                class="opacity-0 group-hover/member:opacity-100 transition-opacity p-0 -mr-1"
                title="Remove from group"
              >
                <span class="material-symbols-rounded text-sm text-surface-400 hover:text-red-500">close</span>
              </button>
            </span>
          </div>
          <div v-else class="mt-3 text-xs text-surface-400 italic">No members yet</div>

          <!-- Addon overrides for this group -->
          <div class="mt-3">
            <p class="text-[10px] uppercase font-semibold text-surface-400 tracking-wider mb-1.5">Addon Overrides</p>
            <div class="flex flex-wrap gap-1.5">
              <template v-for="addon in addons" :key="addon.slug">
                <button
                  :class="[
                    'text-[11px] font-medium px-2 py-1 rounded-lg inline-flex items-center gap-1 transition-all border',
                    group.addon_overrides?.[addon.slug] !== undefined
                      ? group.addon_overrides[addon.slug]
                        ? 'border-green-300 dark:border-green-500/30 bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400'
                        : 'border-red-300 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400'
                      : 'border-surface-200 dark:border-surface-700 text-surface-400 hover:border-surface-300 dark:hover:border-surface-600'
                  ]"
                  @click="toggleGroupAddon(group, addon.slug)"
                  :disabled="assignSaving === `group:${group.id}:${addon.slug}`"
                  :title="group.addon_overrides?.[addon.slug] !== undefined ? 'Click to toggle · Right-click to remove override' : 'Click to add override'"
                  @contextmenu.prevent="group.addon_overrides?.[addon.slug] !== undefined && removeGroupAddon(group, addon.slug)"
                >
                  <span class="material-symbols-rounded" style="font-size: 13px">{{ addon.icon || 'extension' }}</span>
                  {{ addon.name }}
                  <span v-if="group.addon_overrides?.[addon.slug] !== undefined" class="material-symbols-rounded" style="font-size: 11px">
                    {{ group.addon_overrides[addon.slug] ? 'check_circle' : 'cancel' }}
                  </span>
                </button>
              </template>
            </div>
            <p class="text-[10px] text-surface-400 mt-1">Click to toggle override · Right-click to remove override</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ MODALS ═══ -->

  <!-- Create/Edit Group Modal -->
  <Modal
    :show="groupModal.show"
    :title="groupModal.editing ? 'Edit Group' : 'Create Group'"
    size="md"
    @close="groupModal.show = false"
  >
    <div class="space-y-4">
      <div>
        <label :class="labelCls">Name</label>
        <input v-model="groupForm.name" type="text" :class="inputCls" placeholder="e.g. Marketing Team" />
      </div>
      <div>
        <label :class="labelCls">Description</label>
        <input v-model="groupForm.description" type="text" :class="inputCls" placeholder="Optional description" />
      </div>
      <div>
        <label :class="labelCls">Color</label>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="c in groupColors"
            :key="c"
            @click="groupForm.color = c"
            class="w-7 h-7 rounded-lg transition-all"
            :class="groupForm.color === c ? 'ring-2 ring-offset-2 ring-primary-500 dark:ring-offset-surface-900' : 'hover:scale-110'"
            :style="{ backgroundColor: c }"
          ></button>
        </div>
      </div>
      <div>
        <label :class="labelCls">Icon</label>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="ic in groupIcons"
            :key="ic"
            @click="groupForm.icon = ic"
            :class="[
              'w-9 h-9 rounded-lg flex items-center justify-center transition-all',
              groupForm.icon === ic
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 ring-2 ring-primary-500'
                : 'bg-surface-100 dark:bg-surface-800 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ ic }}</span>
          </button>
        </div>
      </div>
    </div>
    <template #footer>
      <button @click="groupModal.show = false" class="btn-secondary">Cancel</button>
      <button @click="saveGroup" class="btn-primary" :disabled="groupSaving || !groupForm.name.trim()">
        <span v-if="groupSaving" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
        {{ groupModal.editing ? 'Update' : 'Create' }}
      </button>
    </template>
  </Modal>

  <!-- Quick Create Group from Selection -->
  <Modal
    :show="quickGroupModal.show"
    title="Create Group from Selection"
    size="md"
    @close="quickGroupModal.show = false"
  >
    <div class="space-y-4">
      <div class="p-3 bg-primary-50 dark:bg-primary-500/10 rounded-lg text-sm text-primary-700 dark:text-primary-400">
        <span class="font-semibold">{{ selectedUsers.size }}</span> user{{ selectedUsers.size !== 1 ? 's' : '' }} will be added to this group.
      </div>
      <div>
        <label :class="labelCls">Group Name</label>
        <input v-model="quickGroupForm.name" type="text" :class="inputCls" placeholder="e.g. Marketing Team" />
      </div>
      <div>
        <label :class="labelCls">Description</label>
        <input v-model="quickGroupForm.description" type="text" :class="inputCls" placeholder="Optional" />
      </div>
      <div>
        <label :class="labelCls">Color</label>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="c in groupColors"
            :key="c"
            @click="quickGroupForm.color = c"
            class="w-7 h-7 rounded-lg transition-all"
            :class="quickGroupForm.color === c ? 'ring-2 ring-offset-2 ring-primary-500 dark:ring-offset-surface-900' : 'hover:scale-110'"
            :style="{ backgroundColor: c }"
          ></button>
        </div>
      </div>
      <div>
        <label :class="labelCls">Icon</label>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="ic in groupIcons"
            :key="ic"
            @click="quickGroupForm.icon = ic"
            :class="[
              'w-9 h-9 rounded-lg flex items-center justify-center transition-all',
              quickGroupForm.icon === ic
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 ring-2 ring-primary-500'
                : 'bg-surface-100 dark:bg-surface-800 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ ic }}</span>
          </button>
        </div>
      </div>
    </div>
    <template #footer>
      <button @click="quickGroupModal.show = false" class="btn-secondary">Cancel</button>
      <button @click="quickCreateGroup" class="btn-primary" :disabled="groupSaving || !quickGroupForm.name.trim()">
        <span v-if="groupSaving" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
        Create Group
      </button>
    </template>
  </Modal>

  <!-- Bulk Assign Addon Modal -->
  <Modal
    :show="assignAddonModal.show"
    title="Assign Addon to Selected Users"
    size="sm"
    @close="assignAddonModal.show = false"
  >
    <div class="space-y-4">
      <div class="p-3 bg-primary-50 dark:bg-primary-500/10 rounded-lg text-sm text-primary-700 dark:text-primary-400">
        Applying to <span class="font-semibold">{{ selectedUsers.size }}</span> user{{ selectedUsers.size !== 1 ? 's' : '' }}
      </div>
      <div>
        <label :class="labelCls">Addon</label>
        <select v-model="assignAddonSlug" :class="inputCls">
          <option v-for="addon in addons" :key="addon.slug" :value="addon.slug">
            {{ addon.name }}
          </option>
        </select>
      </div>
      <div>
        <label :class="labelCls">Action</label>
        <div class="flex gap-3">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" v-model="assignAddonEnabled" :value="true" class="text-green-600 focus:ring-green-500" />
            <span class="text-sm text-green-600 dark:text-green-400 font-medium">Enable</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" v-model="assignAddonEnabled" :value="false" class="text-red-600 focus:ring-red-500" />
            <span class="text-sm text-red-600 dark:text-red-400 font-medium">Disable</span>
          </label>
        </div>
      </div>
    </div>
    <template #footer>
      <button @click="assignAddonModal.show = false" class="btn-secondary">Cancel</button>
      <button @click="bulkAssignAddon" class="btn-primary" :disabled="assignSaving === 'bulk' || !assignAddonSlug">
        <span v-if="assignSaving === 'bulk'" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
        Apply
      </button>
    </template>
  </Modal>

  <!-- Delete Group Confirm -->
  <ConfirmModal
    :show="deleteModal.show"
    title="Delete Group"
    :message="`Delete group '${deleteModal.group?.name}'? This will remove all members and addon overrides for this group.`"
    confirm-text="Delete"
    :danger="true"
    :loading="deleting"
    @confirm="confirmDeleteGroup"
    @cancel="deleteModal = { show: false, group: null }"
  />

  <!-- Add Members Modal -->
  <Modal
    :show="addToGroupModal.show"
    :title="`Add Members to ${addToGroupModal.group?.name || 'Group'}`"
    size="md"
    @close="addToGroupModal.show = false"
  >
    <div class="space-y-3">
      <div class="relative">
        <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
        <input
          v-model="addToGroupSearch"
          type="text"
          class="w-full pl-10 pr-4 py-2 text-sm rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50 text-surface-900 dark:text-surface-100 placeholder-surface-400 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 transition-colors"
          placeholder="Search users..."
        />
      </div>

      <div v-if="availableEmailsForGroup.length === 0" class="text-center py-4 text-sm text-surface-400">
        {{ addToGroupSearch ? 'No matching users' : 'All users are already in this group' }}
      </div>

      <div v-else class="max-h-64 overflow-y-auto divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))] rounded-lg border border-surface-200 dark:border-surface-700">
        <div
          v-for="user in availableEmailsForGroup"
          :key="user.email"
          class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
          @click="toggleEmailSelection(user.email)"
        >
          <Toggle
            :model-value="selectedEmails.includes(user.email)"
            @update:model-value="toggleEmailSelection(user.email)"
          />
          <div class="flex-1 min-w-0">
            <span class="text-sm font-medium">{{ user.display_name || user.email.split('@')[0] }}</span>
            <span class="text-xs text-surface-400 ml-2">{{ user.email }}</span>
          </div>
        </div>
      </div>

      <p v-if="selectedEmails.length" class="text-xs text-primary-600 dark:text-primary-400">
        {{ selectedEmails.length }} user{{ selectedEmails.length !== 1 ? 's' : '' }} selected
      </p>
    </div>
    <template #footer>
      <button @click="addToGroupModal.show = false" class="btn-secondary">Cancel</button>
      <button
        @click="addSelectedMembers"
        class="btn-primary"
        :disabled="!selectedEmails.length"
      >
        Add {{ selectedEmails.length || '' }} Member{{ selectedEmails.length !== 1 ? 's' : '' }}
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}
.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  max-height: 0;
  padding-top: 0;
  padding-bottom: 0;
}
.expand-enter-to,
.expand-leave-from {
  opacity: 1;
  max-height: 2000px;
}
</style>
