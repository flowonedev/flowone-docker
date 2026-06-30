<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useToastStore } from '@/stores/toast'
import { useAccountsStore } from '@/stores/accounts'
import AppHeader from '@/components/shared/AppHeader.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import StepGuide from '@/components/shared/StepGuide.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import { featureGuides } from '@/data/featureGuides'
import { teamGuide } from '@/data/stepGuides'

const colleaguesStore = useColleaguesStore()
const toast = useToastStore()
const accountsStore = useAccountsStore()

const isMobile = ref(window.innerWidth < 768)
function updateMobileState() { isMobile.value = window.innerWidth < 768 }

// Feature guide
const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.team

// Presence helpers
function getPresenceClass(email) {
  const status = colleaguesStore.getColleagueStatus(email)
  switch (status) {
    case 'active': return 'bg-green-500'
    case 'away': return 'bg-amber-500'
    case 'do_not_disturb': return 'bg-red-500'
    default: return 'bg-surface-400'
  }
}

function getPresenceTitle(email) {
  const status = colleaguesStore.getColleagueStatus(email)
  switch (status) {
    case 'active': return 'Online'
    case 'away': return 'Away'
    case 'do_not_disturb': return 'Do Not Disturb'
    default: return 'Offline'
  }
}

// State
const loading = ref(true)
const search = ref('')
const selectedGroup = ref(null)
const showCreateGroupModal = ref(false)
const showEditGroupModal = ref(false)
const showEditColleagueModal = ref(false)
const showAddColleagueModal = ref(false)
const editingGroup = ref(null)
const editingColleague = ref(null)
const syncingFromMailServer = ref(false)

// Filter state
const departmentFilter = ref('')
const positionFilter = ref('')
const groupFilter = ref('')
const showFiltersDropdown = ref(false)

// Get unique departments and positions for filter dropdowns
const uniqueDepartments = computed(() => {
  const depts = new Set()
  colleaguesStore.colleagues.forEach(c => {
    if (c.department) depts.add(c.department)
  })
  return Array.from(depts).sort()
})

const uniquePositions = computed(() => {
  const positions = new Set()
  colleaguesStore.colleagues.forEach(c => {
    if (c.job_title) positions.add(c.job_title)
  })
  return Array.from(positions).sort()
})

function getColleagueGroups(colleague) {
  if (!colleague.group_ids || colleague.group_ids.length === 0) return []
  return colleaguesStore.groups.filter(g => colleague.group_ids.includes(g.id))
}

// Form state
const groupForm = ref({
  name: '',
  description: '',
  color: '#6366f1',
  icon: 'group',
  can_see_all_boards: false,
  can_see_all_tasks: false,
  can_manage_members: false,
  can_view_financials: false,
  admin_equivalent: false,
})

const colleagueForm = ref({
  email: '',
  display_name: '',
  job_title: '',
  department: '',
  phone: '',
  is_admin: false
})

// Delete confirmation modal
const showDeleteConfirmModal = ref(false)
const deleteTarget = ref(null)
const deleteTargetType = ref('') // 'colleague' or 'group'
const deleteConfirmInput = ref('')

const deleteConfirmValid = computed(() => deleteConfirmInput.value === 'DELETE')

function openDeleteConfirm(target, type) {
  deleteTarget.value = target
  deleteTargetType.value = type
  deleteConfirmInput.value = ''
  showDeleteConfirmModal.value = true
}

function closeDeleteConfirm() {
  showDeleteConfirmModal.value = false
  deleteTarget.value = null
  deleteTargetType.value = ''
  deleteConfirmInput.value = ''
}

async function confirmDelete() {
  if (!deleteConfirmValid.value || !deleteTarget.value) return

  if (deleteTargetType.value === 'colleague') {
    const result = await colleaguesStore.deleteColleague(deleteTarget.value.id)
    if (result.success) {
      toast.success('Colleague removed from organization')
    } else {
      toast.error(result.error || 'Failed to remove colleague')
    }
  } else if (deleteTargetType.value === 'group') {
    const result = await colleaguesStore.deleteGroup(deleteTarget.value.id)
    if (result.success) {
      toast.success('Group deleted')
      if (selectedGroup.value === deleteTarget.value.id) {
        selectedGroup.value = null
      }
    } else {
      toast.error(result.error || 'Failed to delete group')
    }
  }
  closeDeleteConfirm()
}

// Remove from group confirmation
const showRemoveFromGroupModal = ref(false)
const removeFromGroupTarget = ref(null)

function openRemoveFromGroup(colleague) {
  removeFromGroupTarget.value = colleague
  showRemoveFromGroupModal.value = true
}

function closeRemoveFromGroup() {
  showRemoveFromGroupModal.value = false
  removeFromGroupTarget.value = null
}

async function confirmRemoveFromGroup() {
  if (!removeFromGroupTarget.value || !selectedGroup.value || selectedGroup.value === 'ungrouped') return

  const result = await colleaguesStore.removeMemberFromGroup(selectedGroup.value, removeFromGroupTarget.value.id)
  if (result.success) {
    toast.success('Removed from group')
  } else {
    toast.error(result.error || 'Failed to remove from group')
  }
  closeRemoveFromGroup()
}

// Drag state
const draggingColleague = ref(null)
const dragOverGroup = ref(null)

// Multi-select state
const multiSelectMode = ref(false)
const selectedColleagues = ref(new Set())

// Colors for groups
const groupColors = [
  '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f97316',
  '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6'
]

// Icons for groups
const groupIcons = [
  'group', 'groups', 'engineering', 'design_services', 'palette',
  'code', 'support_agent', 'manage_accounts', 'badge', 'work'
]

// Computed
const filteredColleagues = computed(() => {
  let list = colleaguesStore.sortedColleagues
  
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter(c => 
      c.email.toLowerCase().includes(q) ||
      (c.display_name && c.display_name.toLowerCase().includes(q)) ||
      (c.department && c.department.toLowerCase().includes(q)) ||
      (c.job_title && c.job_title.toLowerCase().includes(q))
    )
  }
  
  // Department filter
  if (departmentFilter.value) {
    list = list.filter(c => c.department === departmentFilter.value)
  }
  
  // Position filter
  if (positionFilter.value) {
    list = list.filter(c => c.job_title === positionFilter.value)
  }

  // Group filter (from toolbar, separate from sidebar selection)
  if (groupFilter.value) {
    if (groupFilter.value === 'ungrouped') {
      list = list.filter(c => !c.group_ids || c.group_ids.length === 0)
    } else {
      const gid = parseInt(groupFilter.value)
      list = list.filter(c => c.group_ids && c.group_ids.includes(gid))
    }
  }
  
  if (selectedGroup.value === 'ungrouped') {
    list = list.filter(c => !c.group_ids || c.group_ids.length === 0)
  } else if (selectedGroup.value) {
    list = list.filter(c => c.group_ids && c.group_ids.includes(selectedGroup.value))
  }
  
  return list
})

const activeFiltersCount = computed(() => {
  let count = 0
  if (departmentFilter.value) count++
  if (positionFilter.value) count++
  if (groupFilter.value) count++
  return count
})

function clearFilters() {
  departmentFilter.value = ''
  positionFilter.value = ''
  groupFilter.value = ''
}

// Actions
async function init() {
  loading.value = true
  await colleaguesStore.init()
  loading.value = false
}

async function syncFromMailServer() {
  syncingFromMailServer.value = true
  const result = await colleaguesStore.syncFromMailServer()
  syncingFromMailServer.value = false

  if (result.success) {
    const parts = [`${result.total} found, ${result.db_total || '?'} in DB`]
    if (result.sources) {
      const srcParts = Object.entries(result.sources)
        .filter(([, v]) => v > 0)
        .map(([k, v]) => `${k}: ${v}`)
      if (srcParts.length) parts.push(`[${srcParts.join(', ')}]`)
    }
    toast.success(parts.join(' | '))
    if (result.emails_found?.length) {
      console.log('[Sync] Emails found:', result.emails_found.sort().join(', '))
    }
  } else {
    toast.error(result.error || 'Failed to sync from mail server')
  }
}

// Group management
const groupPermissions = [
  { key: 'can_see_all_boards', label: 'See all boards', desc: 'Members can view every board in the organization', icon: 'dashboard' },
  { key: 'can_see_all_tasks', label: 'See all tasks', desc: 'Members can view all tasks, not just assigned ones', icon: 'task_alt' },
  { key: 'can_manage_members', label: 'Manage board members', desc: 'Can add or remove members from boards', icon: 'group_add' },
  { key: 'can_view_financials', label: 'View financials', desc: 'Access financial data across all boards', icon: 'payments' },
  { key: 'admin_equivalent', label: 'Full admin access', desc: 'All permissions above, equivalent to admin role', icon: 'shield_person' },
]

function openCreateGroup() {
  groupForm.value = {
    name: '', description: '', color: '#6366f1', icon: 'group',
    can_see_all_boards: false, can_see_all_tasks: false,
    can_manage_members: false, can_view_financials: false, admin_equivalent: false,
  }
  showCreateGroupModal.value = true
}

function openEditGroup(group) {
  editingGroup.value = group
  groupForm.value = {
    name: group.name,
    description: group.description || '',
    color: group.color || '#6366f1',
    icon: group.icon || 'group',
    can_see_all_boards: !!group.can_see_all_boards,
    can_see_all_tasks: !!group.can_see_all_tasks,
    can_manage_members: !!group.can_manage_members,
    can_view_financials: !!group.can_view_financials,
    admin_equivalent: !!group.admin_equivalent,
  }
  showEditGroupModal.value = true
}

function hasAnyPermission(group) {
  return group.can_see_all_boards || group.can_see_all_tasks || group.can_manage_members || group.can_view_financials || group.admin_equivalent
}

function getPermissionIcon(group) {
  if (group.admin_equivalent) return 'shield_person'
  if (group.can_see_all_boards) return 'visibility'
  if (group.can_manage_members) return 'manage_accounts'
  if (group.can_view_financials) return 'payments'
  if (group.can_see_all_tasks) return 'task_alt'
  return null
}

async function createGroup() {
  if (!groupForm.value.name.trim()) {
    toast.error('Group name is required')
    return
  }
  
  const result = await colleaguesStore.createGroup(groupForm.value)
  if (result.success) {
    toast.success('Group created')
    showCreateGroupModal.value = false
  } else {
    toast.error(result.error || 'Failed to create group')
  }
}

async function updateGroup() {
  if (!editingGroup.value) return
  
  const result = await colleaguesStore.updateGroup(editingGroup.value.id, groupForm.value)
  if (result.success) {
    toast.success('Group updated')
    showEditGroupModal.value = false
    editingGroup.value = null
  } else {
    toast.error(result.error || 'Failed to update group')
  }
}

function deleteGroup(group) {
  openDeleteConfirm(group, 'group')
}

// Colleague management
function openEditColleague(colleague) {
  editingColleague.value = colleague
  colleagueForm.value = {
    display_name: colleague.display_name || '',
    job_title: colleague.job_title || '',
    department: colleague.department || '',
    phone: colleague.phone || '',
    is_admin: colleague.is_admin || false
  }
  showEditColleagueModal.value = true
}

function openAddColleague() {
  colleagueForm.value = {
    email: '',
    display_name: '',
    job_title: '',
    department: '',
    phone: '',
    is_admin: false
  }
  showAddColleagueModal.value = true
}

async function addColleague() {
  if (!colleagueForm.value.email.trim()) {
    toast.error('Email is required')
    return
  }
  
  const result = await colleaguesStore.addColleague(colleagueForm.value)
  if (result.success) {
    toast.success('Colleague added')
    showAddColleagueModal.value = false
  } else {
    toast.error(result.error || 'Failed to add colleague')
  }
}

async function updateColleague() {
  if (!editingColleague.value) return
  
  const result = await colleaguesStore.updateColleague(editingColleague.value.id, colleagueForm.value)
  if (result.success) {
    toast.success('Colleague updated')
    showEditColleagueModal.value = false
    editingColleague.value = null
  } else {
    toast.error(result.error || 'Failed to update colleague')
  }
}

function deleteColleague(colleague) {
  openDeleteConfirm(colleague, 'colleague')
}

async function toggleAdmin(colleague) {
  const result = await colleaguesStore.updateColleague(colleague.id, {
    is_admin: !colleague.is_admin
  })
  if (result.success) {
    toast.success(colleague.is_admin ? 'Admin rights removed' : 'Admin rights granted')
  } else {
    toast.error(result.error || 'Failed to update admin status')
  }
}

// Multi-select functions
function toggleMultiSelect() {
  multiSelectMode.value = !multiSelectMode.value
  if (!multiSelectMode.value) {
    selectedColleagues.value = new Set()
  }
}

function toggleColleagueSelection(colleagueId) {
  const newSet = new Set(selectedColleagues.value)
  if (newSet.has(colleagueId)) {
    newSet.delete(colleagueId)
  } else {
    newSet.add(colleagueId)
  }
  selectedColleagues.value = newSet
}

function selectAllVisible() {
  const newSet = new Set(selectedColleagues.value)
  for (const c of filteredColleagues.value) {
    newSet.add(c.id)
  }
  selectedColleagues.value = newSet
}

function clearSelection() {
  selectedColleagues.value = new Set()
}

// Drag & Drop
function handleDragStart(e, colleague) {
  // If multi-select mode and this colleague is selected, drag all selected
  if (multiSelectMode.value && selectedColleagues.value.has(colleague.id)) {
    draggingColleague.value = { 
      isMultiple: true, 
      ids: Array.from(selectedColleagues.value),
      count: selectedColleagues.value.size
    }
    e.dataTransfer.setData('text/plain', Array.from(selectedColleagues.value).join(','))
  } else {
    // Otherwise just drag this one colleague
    draggingColleague.value = colleague
    e.dataTransfer.setData('text/plain', colleague.id)
  }
  e.dataTransfer.effectAllowed = 'move'
}

function handleDragEnd() {
  draggingColleague.value = null
  dragOverGroup.value = null
}

function handleDragOver(e, groupId) {
  e.preventDefault()
  dragOverGroup.value = groupId
}

function handleDragLeave() {
  dragOverGroup.value = null
}

async function handleDrop(e, groupId) {
  e.preventDefault()
  dragOverGroup.value = null
  
  if (!draggingColleague.value) return
  
  // Handle multiple colleagues
  if (draggingColleague.value.isMultiple) {
    const ids = draggingColleague.value.ids
    let successCount = 0
    
    for (const colleagueId of ids) {
      const colleague = colleaguesStore.sortedColleagues.find(c => c.id === colleagueId)
      if (!colleague) continue
      
      let newGroupIds = [...(colleague.group_ids || [])]
      
      if (groupId === 'ungrouped') {
        newGroupIds = []
      } else {
        if (!newGroupIds.includes(groupId)) {
          newGroupIds.push(groupId)
        }
      }
      
      const result = await colleaguesStore.setColleagueGroups(colleagueId, newGroupIds)
      if (result.success) successCount++
    }
    
    if (successCount > 0) {
      toast.success(`Added ${successCount} members to group`)
      selectedColleagues.value = new Set() // Clear selection after drop
    } else {
      toast.error('Failed to update groups')
    }
  } else {
    // Single colleague drop
    const colleague = draggingColleague.value
    let newGroupIds = [...(colleague.group_ids || [])]
    
    if (groupId === 'ungrouped') {
      newGroupIds = []
    } else {
      if (!newGroupIds.includes(groupId)) {
        newGroupIds.push(groupId)
      }
    }
    
    const result = await colleaguesStore.setColleagueGroups(colleague.id, newGroupIds)
    if (result.success) {
      toast.success(`Added to group`)
    } else {
      toast.error(result.error || 'Failed to update groups')
    }
  }
  
  draggingColleague.value = null
}

async function removeFromGroup(colleagueId, groupId) {
  const result = await colleaguesStore.removeMemberFromGroup(groupId, colleagueId)
  if (result.success) {
    toast.success('Removed from group')
  } else {
    toast.error(result.error || 'Failed to remove from group')
  }
}

// Lifecycle
onMounted(async () => {
  init()
  window.addEventListener('resize', updateMobileState)
  await accountsStore.fetchAccounts()
})

onUnmounted(() => {
  colleaguesStore.cleanup()
  window.removeEventListener('resize', updateMobileState)
})
</script>

<template>
  <div class="colleague-manager h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Top bar -->
    <AppHeader
      current-view="team"
      icon="diversity_3"
      title="Team"
    >
      <template #title-badge>
        <span 
          v-if="colleaguesStore.colleagues.length > 0"
          class="px-2 py-0.5 text-xs font-medium bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 rounded-full"
        >
          {{ colleaguesStore.colleagues.length }}
        </span>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>
    
    <!-- Main content -->
    <div :class="isMobile ? 'flex-1 flex flex-col min-w-0' : 'flex-1 flex overflow-hidden'">
      <!-- Groups sidebar - hidden on mobile -->
      <aside class="w-64 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] hidden md:flex flex-col overflow-hidden">
        <!-- New Group button -->
        <div class="p-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
          <button
            v-if="colleaguesStore.isAdmin"
            @click="openCreateGroup"
            class="btn-secondary btn-sm w-full"
          >
            <span class="material-symbols-rounded">add</span>
            New Group
          </button>
        </div>
        
        <!-- Groups list -->
        <div class="flex-1 overflow-y-auto p-2">
          
          <!-- All colleagues -->
          <button
            @click="selectedGroup = null"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-1',
              selectedGroup === null 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg">groups</span>
            <span class="flex-1 text-left">All Colleagues</span>
            <span class="text-xs text-surface-500">{{ colleaguesStore.colleagues.length }}</span>
          </button>
          
          <!-- Ungrouped -->
          <div
            @click="selectedGroup = 'ungrouped'"
            @dragover="handleDragOver($event, 'ungrouped')"
            @dragleave="handleDragLeave"
            @drop="handleDrop($event, 'ungrouped')"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-3 cursor-pointer',
              selectedGroup === 'ungrouped' 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700',
              dragOverGroup === 'ungrouped' ? 'ring-2 ring-primary-500 ring-offset-2' : ''
            ]"
          >
            <span class="material-symbols-rounded text-lg">person_off</span>
            <span class="flex-1 text-left">Ungrouped</span>
            <span class="text-xs text-surface-500">{{ colleaguesStore.colleaguesByGroup['ungrouped']?.length || 0 }}</span>
          </div>
          
          <!-- Group list -->
          <div class="space-y-1">
            <div
              v-for="group in colleaguesStore.sortedGroups"
              :key="group.id"
              @click="selectedGroup = group.id"
              @dragover="handleDragOver($event, group.id)"
              @dragleave="handleDragLeave"
              @drop="handleDrop($event, group.id)"
              :class="[
                'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors cursor-pointer group/item',
                selectedGroup === group.id 
                  ? 'bg-primary-50 dark:bg-primary-500/10' 
                  : 'hover:bg-surface-100 dark:hover:bg-surface-700',
                dragOverGroup === group.id ? 'ring-2 ring-primary-500 ring-offset-2' : ''
              ]"
            >
              <div 
                class="w-6 h-6 rounded-lg flex items-center justify-center"
                :style="{ backgroundColor: group.color + '20', color: group.color }"
              >
                <span class="material-symbols-rounded text-sm">{{ group.icon || 'group' }}</span>
              </div>
              <span 
                class="flex-1 text-left font-medium truncate"
                :class="selectedGroup === group.id ? 'text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
              >
                {{ group.name }}
              </span>
              <span
                v-if="getPermissionIcon(group)"
                class="material-symbols-rounded text-xs flex-shrink-0"
                :class="group.admin_equivalent ? 'text-amber-500' : 'text-primary-400'"
                :title="group.admin_equivalent ? 'Full admin access' : 'Elevated permissions'"
              >{{ getPermissionIcon(group) }}</span>
              <span class="text-xs text-surface-500">{{ group.member_count || 0 }}</span>
              
              <!-- Edit/Delete buttons (admin) -->
              <div 
                v-if="colleaguesStore.isAdmin"
                class="hidden group-hover/item:flex items-center gap-0.5"
              >
                <button
                  @click.stop="openEditGroup(group)"
                  class="p-1 hover:bg-surface-200 dark:hover:bg-surface-600 rounded transition-colors"
                >
                  <span class="material-symbols-rounded text-sm text-surface-500">edit</span>
                </button>
                <button
                  @click.stop="deleteGroup(group)"
                  class="p-1 hover:bg-red-100 dark:hover:bg-red-500/20 rounded transition-colors"
                >
                  <span class="material-symbols-rounded text-sm text-red-500">delete</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <!-- Mobile group chips (replaces sidebar on mobile) -->
      <div v-if="isMobile" class="flex items-center gap-2 px-4 py-2.5 bg-white dark:bg-[rgb(var(--color-surface))] border-b border-surface-200 dark:border-[rgb(var(--color-border))] overflow-x-auto flex-shrink-0" style="-webkit-overflow-scrolling: touch;">
        <button
          @click="selectedGroup = null"
          :class="[
            'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors flex-shrink-0',
            selectedGroup === null
              ? 'bg-primary-500 text-white'
              : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'
          ]"
        >
          <span class="material-symbols-rounded text-sm">groups</span>
          All {{ colleaguesStore.colleagues.length }}
        </button>
        <button
          @click="selectedGroup = 'ungrouped'"
          :class="[
            'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors flex-shrink-0',
            selectedGroup === 'ungrouped'
              ? 'bg-primary-500 text-white'
              : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'
          ]"
        >
          <span class="material-symbols-rounded text-sm">person_off</span>
          Ungrouped {{ colleaguesStore.colleaguesByGroup['ungrouped']?.length || 0 }}
        </button>
        <button
          v-for="group in colleaguesStore.sortedGroups"
          :key="'chip-' + group.id"
          @click="selectedGroup = group.id"
          :class="[
            'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors flex-shrink-0',
            selectedGroup === group.id
              ? 'bg-primary-500 text-white'
              : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'
          ]"
        >
          <span class="material-symbols-rounded text-sm" :style="selectedGroup !== group.id ? { color: group.color } : {}">{{ group.icon || 'group' }}</span>
          {{ group.name }}
          <span v-if="getPermissionIcon(group)" class="material-symbols-rounded text-[10px]" :class="group.admin_equivalent ? 'text-amber-400' : 'text-primary-300'">{{ getPermissionIcon(group) }}</span>
          {{ group.member_count || 0 }}
        </button>
      </div>
      
      <!-- Colleagues list -->
      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 flex flex-col overflow-hidden bg-surface-50 dark:bg-surface-900'">
        <!-- Sub-header with search and actions -->
        <div :class="[
          'bg-white dark:bg-[rgb(var(--color-surface))] border-b border-surface-200 dark:border-[rgb(var(--color-border))]',
          isMobile ? 'px-4 py-2.5 space-y-2' : 'flex items-center justify-between gap-4 px-6 py-3'
        ]">
          <div class="relative" :class="isMobile ? 'w-full' : 'flex-1 max-w-md'">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input
              v-model="search"
              type="text"
              placeholder="Search colleagues..."
              class="w-full pl-10 pr-4 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          
          <div class="flex items-center gap-2" :class="isMobile && 'justify-between'">
            <!-- Multi-select toggle (hidden on mobile) -->
            <button
              v-if="!isMobile"
              @click="toggleMultiSelect"
              :class="[
                'flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-full transition-colors',
                multiSelectMode 
                  ? 'bg-red-500 text-white' 
                  : 'bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
              ]"
              :title="multiSelectMode ? 'Exit multi-select' : 'Select multiple'"
            >
              <span class="material-symbols-rounded text-lg">{{ multiSelectMode ? 'close' : 'checklist' }}</span>
              <span class="hidden sm:inline">{{ multiSelectMode ? 'Done' : 'Select' }}</span>
            </button>
            
            <!-- Filters dropdown -->
            <div class="relative">
              <button
                @click="showFiltersDropdown = !showFiltersDropdown"
                :class="[
                  'flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-full transition-colors',
                  activeFiltersCount > 0 
                    ? 'bg-primary-500 text-white' 
                    : 'bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-lg">filter_list</span>
                <span class="hidden sm:inline">Filters</span>
                <span v-if="activeFiltersCount > 0" class="w-5 h-5 rounded-full bg-white text-primary-500 text-xs font-bold flex items-center justify-center">
                  {{ activeFiltersCount }}
                </span>
              </button>
              
              <!-- Filters Dropdown -->
              <div v-if="showFiltersDropdown" class="fixed inset-0 z-40" @click="showFiltersDropdown = false"></div>
              <div 
                v-if="showFiltersDropdown"
                class="absolute right-0 top-full mt-2 w-64 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 p-4 z-50"
              >
                <div class="flex items-center justify-between mb-3">
                  <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Filters</h4>
                  <button 
                    v-if="activeFiltersCount > 0"
                    @click="clearFilters"
                    class="text-xs text-primary-500 hover:underline"
                  >
                    Clear all
                  </button>
                </div>
                
                <!-- Department filter -->
                <div class="mb-3">
                  <label class="block text-xs font-medium text-surface-500 mb-1">Department</label>
                  <select 
                    v-model="departmentFilter"
                    class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500"
                  >
                    <option value="">All departments</option>
                    <option v-for="dept in uniqueDepartments" :key="dept" :value="dept">{{ dept }}</option>
                  </select>
                </div>
                
                <!-- Position filter -->
                <div class="mb-3">
                  <label class="block text-xs font-medium text-surface-500 mb-1">Position</label>
                  <select 
                    v-model="positionFilter"
                    class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500"
                  >
                    <option value="">All positions</option>
                    <option v-for="pos in uniquePositions" :key="pos" :value="pos">{{ pos }}</option>
                  </select>
                </div>
                
                <!-- Group filter -->
                <div>
                  <label class="block text-xs font-medium text-surface-500 mb-1">Group</label>
                  <select 
                    v-model="groupFilter"
                    class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500"
                  >
                    <option value="">All groups</option>
                    <option value="ungrouped">Ungrouped</option>
                    <option v-for="group in colleaguesStore.sortedGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
                  </select>
                </div>
              </div>
            </div>
            
            <!-- Sync button (admin only) -->
            <button
              v-if="colleaguesStore.isAdmin"
              @click="syncFromMailServer"
              :disabled="syncingFromMailServer"
              class="flex items-center gap-2 px-3 py-2 text-sm font-medium bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 rounded-full hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors disabled:opacity-50"
            >
              <span 
                class="material-symbols-rounded text-lg"
                :class="{ 'animate-spin': syncingFromMailServer }"
              >
                {{ syncingFromMailServer ? 'progress_activity' : 'sync' }}
              </span>
              <span class="hidden sm:inline">Sync</span>
            </button>
            
            <!-- Add colleague (admin only) -->
            <button
              v-if="colleaguesStore.isAdmin"
              @click="openAddColleague"
              class="flex items-center gap-2 px-4 py-2 text-sm font-medium bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors"
            >
              <span class="material-symbols-rounded text-lg">person_add</span>
              <span class="hidden sm:inline">Add Member</span>
            </button>
          </div>
        </div>
        
        <!-- Feature Guide -->
        <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
        
        <!-- Multi-select bar -->
        <div 
          v-if="multiSelectMode" 
          class="px-4 py-2 bg-primary-50 dark:bg-primary-500/10 border-b border-primary-200 dark:border-primary-500/20 flex items-center justify-between"
        >
          <div class="flex items-center gap-3">
            <span class="text-sm font-medium text-primary-700 dark:text-primary-300">
              {{ selectedColleagues.size }} selected
            </span>
            <button 
              @click="selectAllVisible" 
              class="text-sm text-primary-600 dark:text-primary-400 hover:underline"
            >
              Select all visible
            </button>
            <button 
              v-if="selectedColleagues.size > 0"
              @click="clearSelection" 
              class="text-sm text-surface-500 hover:underline"
            >
              Clear
            </button>
          </div>
          <p class="text-xs text-primary-600 dark:text-primary-400">
            Drag selected members to a group
          </p>
        </div>
        
        <!-- Scrollable content -->
        <div class="flex-1 overflow-auto">
          <!-- Loading -->
          <div v-if="loading" class="flex items-center justify-center py-12">
            <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">progress_activity</span>
          </div>
          
          <!-- Empty state -->
          <div v-else-if="filteredColleagues.length === 0" class="flex flex-col items-center justify-center py-12 text-center px-4">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">group_off</span>
            <p class="text-surface-600 dark:text-surface-400">
              {{ search ? 'No colleagues found matching your search' : 'No colleagues yet' }}
            </p>
            <p v-if="colleaguesStore.isAdmin && !search" class="text-sm text-surface-500 mt-1">
              Click "Sync" to import colleagues from mail server
            </p>
          </div>
          
          <!-- Mobile card layout -->
          <div v-else-if="isMobile" class="divide-y divide-surface-200 dark:divide-surface-700">
            <div
              v-for="colleague in filteredColleagues"
              :key="'m-' + colleague.id"
              @click="colleaguesStore.isAdmin ? openEditColleague(colleague) : null"
              class="flex items-center gap-3 px-4 py-3 bg-white dark:bg-[rgb(var(--color-surface))] active:bg-surface-50 dark:active:bg-surface-800 transition-colors"
            >
              <UserAvatar
                :colleague="colleague"
                size="lg"
                :show-presence="true"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5">
                  <span class="font-medium text-surface-900 dark:text-surface-100 truncate">
                    {{ colleague.display_name || colleague.email.split('@')[0] }}
                  </span>
                  <span 
                    v-if="colleague.is_admin"
                    class="material-symbols-rounded text-xs text-amber-500 flex-shrink-0"
                  >
                    verified
                  </span>
                </div>
                <p class="text-sm text-surface-500 truncate">{{ colleague.job_title || colleague.email }}</p>
                <p v-if="colleague.department" class="text-xs text-surface-400 truncate">{{ colleague.department }}</p>
                <div v-if="getColleagueGroups(colleague).length" class="flex flex-wrap gap-1 mt-1">
                  <span
                    v-for="group in getColleagueGroups(colleague)"
                    :key="group.id"
                    class="inline-flex items-center gap-0.5 px-1.5 py-0 rounded-full text-[10px] font-medium"
                    :style="{ backgroundColor: group.color + '18', color: group.color }"
                  >
                    <span class="material-symbols-rounded text-[10px]">{{ group.icon || 'group' }}</span>
                    {{ group.name }}
                  </span>
                </div>
              </div>
              <div class="flex items-center gap-1 flex-shrink-0">
                <a
                  v-if="colleague.phone"
                  :href="'tel:' + colleague.phone"
                  @click.stop
                  class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-100 dark:bg-surface-700 text-surface-500"
                >
                  <span class="material-symbols-rounded text-lg">call</span>
                </a>
                <span class="material-symbols-rounded text-lg text-surface-300 dark:text-surface-600">chevron_right</span>
              </div>
            </div>
          </div>

          <!-- Desktop table view -->
          <table v-else class="w-full">
            <thead class="sticky top-0 bg-surface-100 dark:bg-surface-800 z-10">
              <tr class="text-left text-[10px] font-bold text-surface-400 uppercase tracking-wider">
                <th class="w-8 px-2 py-1.5"></th>
                <th class="px-2 py-1.5">Name</th>
                <th class="px-2 py-1.5">Email</th>
                <th class="px-2 py-1.5">Phone</th>
                <th class="px-2 py-1.5">Position</th>
                <th class="px-2 py-1.5">Department</th>
                <th class="px-2 py-1.5">Groups</th>
                <th class="w-16 px-2 py-1.5 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-100 dark:divide-surface-700/40">
              <tr
                v-for="colleague in filteredColleagues"
                :key="colleague.id"
                draggable="true"
                @dragstart="handleDragStart($event, colleague)"
                @dragend="handleDragEnd"
                @click="multiSelectMode ? toggleColleagueSelection(colleague.id) : null"
                :class="[
                  'group transition-colors',
                  multiSelectMode ? 'cursor-pointer' : 'cursor-move',
                  selectedColleagues.has(colleague.id) 
                    ? 'bg-primary-50 dark:bg-primary-500/10' 
                    : 'hover:bg-surface-50/60 dark:hover:bg-surface-800/40',
                  draggingColleague?.id === colleague.id || (draggingColleague?.isMultiple && draggingColleague.ids?.includes(colleague.id)) ? 'opacity-50' : ''
                ]"
              >
                <!-- Checkbox -->
                <td class="px-2 py-1.5">
                  <div 
                    v-if="multiSelectMode"
                    @click.stop="toggleColleagueSelection(colleague.id)"
                    :class="[
                      'w-4 h-4 rounded border-2 flex items-center justify-center transition-all cursor-pointer',
                      selectedColleagues.has(colleague.id) 
                        ? 'bg-primary-500 border-primary-500 text-white' 
                        : 'border-surface-300 dark:border-surface-600 hover:border-primary-400'
                    ]"
                  >
                    <span v-if="selectedColleagues.has(colleague.id)" class="material-symbols-rounded text-[10px]">check</span>
                  </div>
                </td>
                
                <!-- Name -->
                <td class="px-2 py-1.5">
                  <div class="flex items-center gap-2">
                    <UserAvatar
                      :colleague="colleague"
                      size="sm"
                      :show-presence="true"
                    />
                    <div class="flex items-center gap-1">
                      <span class="text-[13px] font-medium text-surface-800 dark:text-surface-200">
                        {{ colleague.display_name || colleague.email.split('@')[0] }}
                      </span>
                      <span 
                        v-if="colleague.is_admin"
                        class="material-symbols-rounded text-[11px] text-amber-500"
                        title="Admin"
                      >
                        verified
                      </span>
                    </div>
                  </div>
                </td>
                
                <!-- Email -->
                <td class="px-2 py-1.5">
                  <span class="text-[12px] text-primary-500">{{ colleague.email }}</span>
                </td>
                
                <!-- Phone -->
                <td class="px-2 py-1.5 text-[12px] text-surface-500 dark:text-surface-400">
                  {{ colleague.phone || '-' }}
                </td>
                
                <!-- Position -->
                <td class="px-2 py-1.5 text-[12px] text-surface-500 dark:text-surface-400">
                  {{ colleague.job_title || '-' }}
                </td>
                
                <!-- Department -->
                <td class="px-2 py-1.5 text-[12px] text-surface-500 dark:text-surface-400">
                  {{ colleague.department || '-' }}
                </td>
                
                <!-- Groups -->
                <td class="px-2 py-1.5">
                  <div class="flex flex-wrap gap-0.5">
                    <span
                      v-for="group in getColleagueGroups(colleague)"
                      :key="group.id"
                      class="inline-flex items-center gap-0.5 px-1.5 py-px rounded-full text-[10px] font-medium"
                      :style="{ backgroundColor: group.color + '18', color: group.color, border: '1px solid ' + group.color + '30' }"
                    >
                      <span class="material-symbols-rounded text-[10px]">{{ group.icon || 'group' }}</span>
                      {{ group.name }}
                    </span>
                    <span
                      v-if="getColleagueGroups(colleague).length === 0"
                      class="text-[11px] text-surface-400"
                    >-</span>
                  </div>
                </td>
                
                <!-- Actions -->
                <td class="px-2 py-1.5">
                  <div class="flex items-center justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                      v-if="colleaguesStore.isAdmin && selectedGroup && selectedGroup !== 'ungrouped'"
                      @click.stop="openRemoveFromGroup(colleague)"
                      class="p-1 hover:bg-amber-100 dark:hover:bg-amber-500/20 rounded-md transition-colors"
                      title="Remove from this group"
                    >
                      <span class="material-symbols-rounded text-[15px] text-amber-600 dark:text-amber-400">group_remove</span>
                    </button>
                    <button
                      v-if="colleaguesStore.isAdmin"
                      @click.stop="openEditColleague(colleague)"
                      class="p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-md transition-colors"
                      title="Edit"
                    >
                      <span class="material-symbols-rounded text-[15px] text-surface-500">edit</span>
                    </button>
                    <button
                      v-if="colleaguesStore.isAdmin"
                      @click.stop="toggleAdmin(colleague)"
                      class="p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-md transition-colors"
                      :title="colleague.is_admin ? 'Remove admin' : 'Make admin'"
                    >
                      <span 
                        class="material-symbols-rounded text-[15px]"
                        :class="colleague.is_admin ? 'text-amber-500' : 'text-surface-400'"
                      >
                        shield_person
                      </span>
                    </button>
                    <button
                      v-if="colleaguesStore.isAdmin"
                      @click.stop="deleteColleague(colleague)"
                      class="p-1 hover:bg-red-100 dark:hover:bg-red-500/20 rounded-md transition-colors"
                      title="Remove from organization"
                    >
                      <span class="material-symbols-rounded text-[15px] text-red-500">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    
    <!-- Mobile Bottom Navigation -->
    <MobileBottomNav v-if="isMobile" />

    <!-- Create Group Modal -->
    <Teleport to="body">
      <div 
        v-if="showCreateGroupModal"
        class="fixed inset-0 bg-black/50 flex z-50"
        :class="isMobile ? 'items-end' : 'items-center justify-center'"
        @click.self="showCreateGroupModal = false"
      >
        <div :class="[
          'bg-white dark:bg-surface-800 shadow-2xl w-full p-6',
          isMobile ? 'rounded-t-2xl max-h-[90vh] overflow-y-auto' : 'rounded-2xl max-w-md'
        ]">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Create Group</h2>
          
          <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-1">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Name</label>
              <input
                v-model="groupForm.name"
                type="text"
                placeholder="e.g., Development Team"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Description</label>
              <textarea
                v-model="groupForm.description"
                rows="2"
                placeholder="Optional description"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 resize-none"
              ></textarea>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Color</label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="color in groupColors"
                  :key="color"
                  @click="groupForm.color = color"
                  class="w-8 h-8 rounded-lg transition-transform hover:scale-110"
                  :class="{ 'ring-2 ring-offset-2 ring-surface-900 dark:ring-surface-100': groupForm.color === color }"
                  :style="{ backgroundColor: color }"
                ></button>
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Icon</label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="icon in groupIcons"
                  :key="icon"
                  @click="groupForm.icon = icon"
                  class="w-10 h-10 rounded-lg flex items-center justify-center transition-all"
                  :class="groupForm.icon === icon 
                    ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 ring-2 ring-primary-500' 
                    : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'"
                >
                  <span class="material-symbols-rounded">{{ icon }}</span>
                </button>
              </div>
            </div>

            <!-- Group Permissions -->
            <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
              <label class="block text-sm font-semibold text-surface-700 dark:text-surface-300 mb-2">
                <span class="material-symbols-rounded text-sm align-middle mr-1">admin_panel_settings</span>
                Group Permissions
              </label>
              <p class="text-xs text-surface-400 mb-3">Control what members of this group can access across the organization.</p>
              <div class="space-y-1.5">
                <button
                  v-for="perm in groupPermissions"
                  :key="perm.key"
                  type="button"
                  @click="groupForm[perm.key] = !groupForm[perm.key]"
                  class="flex items-center justify-between w-full px-3 py-2.5 rounded-xl border transition-all"
                  :class="groupForm[perm.key]
                    ? 'border-primary-300 dark:border-primary-500/40 bg-primary-50/50 dark:bg-primary-500/5'
                    : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'"
                >
                  <div class="flex items-center gap-2.5 min-w-0">
                    <span
                      class="material-symbols-rounded text-base"
                      :class="groupForm[perm.key] ? 'text-primary-500' : 'text-surface-400'"
                    >{{ perm.icon }}</span>
                    <div class="text-left min-w-0">
                      <span class="block text-[13px] font-medium leading-tight" :class="groupForm[perm.key] ? 'text-surface-900 dark:text-surface-100' : 'text-surface-600 dark:text-surface-400'">
                        {{ perm.label }}
                      </span>
                      <span class="block text-[10px] leading-snug mt-0.5 text-surface-400">
                        {{ perm.desc }}
                      </span>
                    </div>
                  </div>
                  <div
                    :class="[
                      'relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 ml-3',
                      groupForm[perm.key] ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span
                      :class="[
                        'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform shadow-sm',
                        groupForm[perm.key] ? 'translate-x-4' : 'translate-x-0.5'
                      ]"
                    />
                  </div>
                </button>
              </div>
            </div>
          </div>
          
          <div class="flex justify-end gap-3 mt-6">
            <button
              @click="showCreateGroupModal = false"
              class="px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              @click="createGroup"
              class="px-4 py-2 text-sm font-medium bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
            >
              Create Group
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Edit Group Modal -->
    <Teleport to="body">
      <div 
        v-if="showEditGroupModal"
        class="fixed inset-0 bg-black/50 flex z-50"
        :class="isMobile ? 'items-end' : 'items-center justify-center'"
        @click.self="showEditGroupModal = false"
      >
        <div :class="[
          'bg-white dark:bg-surface-800 shadow-2xl w-full p-6',
          isMobile ? 'rounded-t-2xl max-h-[90vh] overflow-y-auto' : 'rounded-2xl max-w-md'
        ]">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Edit Group</h2>
          
          <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-1">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Name</label>
              <input
                v-model="groupForm.name"
                type="text"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Description</label>
              <textarea
                v-model="groupForm.description"
                rows="2"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 resize-none"
              ></textarea>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Color</label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="color in groupColors"
                  :key="color"
                  @click="groupForm.color = color"
                  class="w-8 h-8 rounded-lg transition-transform hover:scale-110"
                  :class="{ 'ring-2 ring-offset-2 ring-surface-900 dark:ring-surface-100': groupForm.color === color }"
                  :style="{ backgroundColor: color }"
                ></button>
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Icon</label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="icon in groupIcons"
                  :key="icon"
                  @click="groupForm.icon = icon"
                  class="w-10 h-10 rounded-lg flex items-center justify-center transition-all"
                  :class="groupForm.icon === icon 
                    ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 ring-2 ring-primary-500' 
                    : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'"
                >
                  <span class="material-symbols-rounded">{{ icon }}</span>
                </button>
              </div>
            </div>

            <!-- Group Permissions -->
            <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
              <label class="block text-sm font-semibold text-surface-700 dark:text-surface-300 mb-2">
                <span class="material-symbols-rounded text-sm align-middle mr-1">admin_panel_settings</span>
                Group Permissions
              </label>
              <p class="text-xs text-surface-400 mb-3">Control what members of this group can access across the organization.</p>
              <div class="space-y-1.5">
                <button
                  v-for="perm in groupPermissions"
                  :key="perm.key"
                  type="button"
                  @click="groupForm[perm.key] = !groupForm[perm.key]"
                  class="flex items-center justify-between w-full px-3 py-2.5 rounded-xl border transition-all"
                  :class="groupForm[perm.key]
                    ? 'border-primary-300 dark:border-primary-500/40 bg-primary-50/50 dark:bg-primary-500/5'
                    : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'"
                >
                  <div class="flex items-center gap-2.5 min-w-0">
                    <span
                      class="material-symbols-rounded text-base"
                      :class="groupForm[perm.key] ? 'text-primary-500' : 'text-surface-400'"
                    >{{ perm.icon }}</span>
                    <div class="text-left min-w-0">
                      <span class="block text-[13px] font-medium leading-tight" :class="groupForm[perm.key] ? 'text-surface-900 dark:text-surface-100' : 'text-surface-600 dark:text-surface-400'">
                        {{ perm.label }}
                      </span>
                      <span class="block text-[10px] leading-snug mt-0.5 text-surface-400">
                        {{ perm.desc }}
                      </span>
                    </div>
                  </div>
                  <div
                    :class="[
                      'relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 ml-3',
                      groupForm[perm.key] ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span
                      :class="[
                        'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform shadow-sm',
                        groupForm[perm.key] ? 'translate-x-4' : 'translate-x-0.5'
                      ]"
                    />
                  </div>
                </button>
              </div>
            </div>
          </div>
          
          <div class="flex justify-end gap-3 mt-6">
            <button
              @click="showEditGroupModal = false"
              class="px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              @click="updateGroup"
              class="px-4 py-2 text-sm font-medium bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
            >
              Save Changes
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Add Colleague Modal -->
    <Teleport to="body">
      <div 
        v-if="showAddColleagueModal"
        class="fixed inset-0 bg-black/50 flex z-50"
        :class="isMobile ? 'items-end' : 'items-center justify-center'"
        @click.self="showAddColleagueModal = false"
      >
        <div :class="[
          'bg-white dark:bg-surface-800 shadow-2xl w-full p-6',
          isMobile ? 'rounded-t-2xl max-h-[90vh] overflow-y-auto' : 'rounded-2xl max-w-md'
        ]">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Add Colleague</h2>
          
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Email *</label>
              <input
                v-model="colleagueForm.email"
                type="email"
                placeholder="colleague@yourcompany.com"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Display Name</label>
              <input
                v-model="colleagueForm.display_name"
                type="text"
                placeholder="John Doe"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Job Title</label>
                <input
                  v-model="colleagueForm.job_title"
                  type="text"
                  placeholder="Developer"
                  class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Department</label>
                <input
                  v-model="colleagueForm.department"
                  type="text"
                  placeholder="Engineering"
                  class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
                />
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Phone</label>
              <input
                v-model="colleagueForm.phone"
                type="tel"
                placeholder="+36 1 234 5678"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <button
              type="button"
              @click="colleagueForm.is_admin = !colleagueForm.is_admin"
              class="flex items-center justify-between w-full p-3 rounded-xl border border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500 transition-all"
            >
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-lg" :class="colleagueForm.is_admin ? 'text-amber-500' : 'text-surface-400'">shield_person</span>
                <div class="text-left">
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">Grant admin access</p>
                  <p class="text-xs text-surface-400">Can manage colleagues and groups</p>
                </div>
              </div>
              <div
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  colleagueForm.is_admin ? 'bg-amber-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-sm',
                    colleagueForm.is_admin ? 'translate-x-6' : 'translate-x-1'
                  ]"
                />
              </div>
            </button>
          </div>
          
          <div class="flex justify-end gap-3 mt-6">
            <button
              @click="showAddColleagueModal = false"
              class="px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              @click="addColleague"
              class="px-4 py-2 text-sm font-medium bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
            >
              Add Colleague
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Edit Colleague Modal -->
    <Teleport to="body">
      <div 
        v-if="showEditColleagueModal"
        class="fixed inset-0 bg-black/50 flex z-50"
        :class="isMobile ? 'items-end' : 'items-center justify-center'"
        @click.self="showEditColleagueModal = false"
      >
        <div :class="[
          'bg-white dark:bg-surface-800 shadow-2xl w-full p-6',
          isMobile ? 'rounded-t-2xl max-h-[90vh] overflow-y-auto' : 'rounded-2xl max-w-md'
        ]">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">
            Edit {{ editingColleague?.display_name || editingColleague?.email }}
          </h2>
          
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Display Name</label>
              <input
                v-model="colleagueForm.display_name"
                type="text"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Job Title</label>
                <input
                  v-model="colleagueForm.job_title"
                  type="text"
                  class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Department</label>
                <input
                  v-model="colleagueForm.department"
                  type="text"
                  class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
                />
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Phone</label>
              <input
                v-model="colleagueForm.phone"
                type="tel"
                class="w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <button
              type="button"
              @click="colleagueForm.is_admin = !colleagueForm.is_admin"
              class="flex items-center justify-between w-full p-3 rounded-xl border border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500 transition-all"
            >
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-lg" :class="colleagueForm.is_admin ? 'text-amber-500' : 'text-surface-400'">shield_person</span>
                <div class="text-left">
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">Admin access</p>
                  <p class="text-xs text-surface-400">Can manage colleagues and groups</p>
                </div>
              </div>
              <div
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  colleagueForm.is_admin ? 'bg-amber-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-sm',
                    colleagueForm.is_admin ? 'translate-x-6' : 'translate-x-1'
                  ]"
                />
              </div>
            </button>
          </div>
          
          <div class="flex justify-between mt-6">
            <button
              @click="showEditColleagueModal = false; deleteColleague(editingColleague)"
              class="px-4 py-2 text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors flex items-center gap-1.5"
            >
              <span class="material-symbols-rounded text-lg">delete</span>
              Delete
            </button>
            <div class="flex gap-3">
              <button
                @click="showEditColleagueModal = false"
                class="px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                Cancel
              </button>
              <button
                @click="updateColleague"
                class="px-4 py-2 text-sm font-medium bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
              >
                Save Changes
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <div
        v-if="showDeleteConfirmModal"
        class="fixed inset-0 bg-black/50 flex z-50"
        :class="isMobile ? 'items-end' : 'items-center justify-center'"
        @click.self="closeDeleteConfirm"
      >
        <div :class="[
          'bg-white dark:bg-surface-800 shadow-2xl w-full p-6',
          isMobile ? 'rounded-t-2xl max-h-[90vh] overflow-y-auto' : 'rounded-2xl max-w-md'
        ]">
          <!-- Warning icon -->
          <div class="flex items-center justify-center w-14 h-14 mx-auto mb-4 rounded-full bg-red-100 dark:bg-red-500/15">
            <span class="material-symbols-rounded text-3xl text-red-500">warning</span>
          </div>

          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 text-center mb-2">
            {{ deleteTargetType === 'colleague' ? 'Remove from Organization' : 'Delete Group' }}
          </h2>

          <p class="text-sm text-surface-500 dark:text-surface-400 text-center mb-1">
            <template v-if="deleteTargetType === 'colleague'">
              You are about to permanently remove
              <strong class="text-surface-900 dark:text-surface-100">{{ deleteTarget?.display_name || deleteTarget?.email }}</strong>
              from the organization. This action cannot be undone.
            </template>
            <template v-else>
              You are about to delete the group
              <strong class="text-surface-900 dark:text-surface-100">{{ deleteTarget?.name }}</strong>.
              Members will not be removed from the organization.
            </template>
          </p>

          <p class="text-xs text-surface-400 text-center mb-5">
            All associated data will be removed permanently.
          </p>

          <!-- Type DELETE input -->
          <div class="mb-5">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
              Type <span class="font-mono font-bold text-red-500 bg-red-50 dark:bg-red-500/10 px-1.5 py-0.5 rounded">DELETE</span> to confirm
            </label>
            <input
              v-model="deleteConfirmInput"
              type="text"
              placeholder="DELETE"
              class="w-full px-4 py-3 border-2 rounded-xl bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 text-center font-mono text-lg tracking-widest focus:outline-none transition-colors"
              :class="deleteConfirmInput.length > 0 && !deleteConfirmValid
                ? 'border-red-300 dark:border-red-500/50 focus:border-red-500 focus:ring-2 focus:ring-red-500/20'
                : deleteConfirmValid
                  ? 'border-green-300 dark:border-green-500/50 focus:border-green-500 focus:ring-2 focus:ring-green-500/20'
                  : 'border-surface-300 dark:border-surface-600 focus:border-surface-400 focus:ring-2 focus:ring-surface-400/20'"
              @keydown.enter="deleteConfirmValid && confirmDelete()"
            />
          </div>

          <div class="flex gap-3">
            <button
              @click="closeDeleteConfirm"
              class="flex-1 px-4 py-2.5 text-sm font-medium text-surface-600 dark:text-surface-400 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl transition-colors"
            >
              Cancel
            </button>
            <button
              @click="confirmDelete"
              :disabled="!deleteConfirmValid"
              class="flex-1 px-4 py-2.5 text-sm font-medium rounded-xl transition-all flex items-center justify-center gap-2"
              :class="deleteConfirmValid
                ? 'bg-red-500 text-white hover:bg-red-600 shadow-sm'
                : 'bg-red-200 dark:bg-red-500/20 text-red-300 dark:text-red-500/40 cursor-not-allowed'"
            >
              <span class="material-symbols-rounded text-lg">delete_forever</span>
              {{ deleteTargetType === 'colleague' ? 'Remove' : 'Delete' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Remove from Group Confirmation Modal -->
    <Teleport to="body">
      <div
        v-if="showRemoveFromGroupModal"
        class="fixed inset-0 bg-black/50 flex z-50"
        :class="isMobile ? 'items-end' : 'items-center justify-center'"
        @click.self="closeRemoveFromGroup"
      >
        <div :class="[
          'bg-white dark:bg-surface-800 shadow-2xl w-full p-6',
          isMobile ? 'rounded-t-2xl max-h-[90vh] overflow-y-auto' : 'rounded-2xl max-w-sm'
        ]">
          <div class="flex items-center justify-center w-12 h-12 mx-auto mb-4 rounded-full bg-amber-100 dark:bg-amber-500/15">
            <span class="material-symbols-rounded text-2xl text-amber-600 dark:text-amber-400">group_remove</span>
          </div>

          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 text-center mb-2">
            Remove from Group
          </h2>

          <p class="text-sm text-surface-500 dark:text-surface-400 text-center mb-6">
            Remove
            <strong class="text-surface-900 dark:text-surface-100">{{ removeFromGroupTarget?.display_name || removeFromGroupTarget?.email }}</strong>
            from
            <strong class="text-surface-900 dark:text-surface-100">{{ colleaguesStore.sortedGroups.find(g => g.id === selectedGroup)?.name }}</strong>?
            <br/>
            <span class="text-xs text-surface-400 mt-1 inline-block">They will remain in the organization.</span>
          </p>

          <div class="flex gap-3">
            <button
              @click="closeRemoveFromGroup"
              class="flex-1 px-4 py-2.5 text-sm font-medium text-surface-600 dark:text-surface-400 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl transition-colors"
            >
              Cancel
            </button>
            <button
              @click="confirmRemoveFromGroup"
              class="flex-1 px-4 py-2.5 text-sm font-medium bg-amber-500 text-white hover:bg-amber-600 rounded-xl transition-colors shadow-sm flex items-center justify-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">group_remove</span>
              Remove
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <StepGuide
      v-if="showStepGuide"
      :title-key="teamGuide.titleKey"
      :subtitle-key="teamGuide.subtitleKey"
      :header-icon="teamGuide.headerIcon"
      :header-color="teamGuide.headerColor"
      :storage-key="teamGuide.storageKey"
      :steps="teamGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<style scoped>
/* Custom styles for colleague manager */
</style>

