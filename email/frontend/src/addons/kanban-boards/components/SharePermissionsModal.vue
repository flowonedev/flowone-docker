<script setup>
import { ref, computed, watch } from 'vue'
import { useToastStore } from '@/stores/toast'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import api from '@/services/api'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  member: {
    type: Object,
    default: null
  },
  boardId: {
    type: Number,
    required: true
  },
  isEditing: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'save', 'save-bulk'])

const toast = useToastStore()
const colleaguesStore = useColleaguesStore()

// Tab state
const activeTab = ref('company') // 'company', 'groups', 'manual', or 'external'

// Groups state
const selectedGroupId = ref(null)

// Company users state
const companyUsers = ref([])
const selectedCompanyUsers = ref([])
const loadingCompanyUsers = ref(false)
const companyDomain = ref('')
const showUserDropdown = ref(false)
const searchQuery = ref('')

// Form state
const email = ref('')
const role = ref('editor')
const permissions = ref({
  can_view_financials: false,
  can_view_client: false,
  can_view_contacts: false,
  can_access_drive: false
})
const drivePermission = ref('editor')
const saving = ref(false)

// Filtered company users based on search
const filteredCompanyUsers = computed(() => {
  if (!searchQuery.value) return companyUsers.value
  const query = searchQuery.value.toLowerCase()
  return companyUsers.value.filter(user => user.toLowerCase().includes(query))
})

// External (guest) email
const externalEmail = ref('')

const isExternalEmailValid = computed(() => {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return emailRegex.test(externalEmail.value)
})

// Permission descriptions
const permissionItems = [
  {
    key: 'can_view_financials',
    icon: 'payments',
    label: 'View Financials',
    description: 'Expected amounts, invoices, cash flow projections'
  },
  {
    key: 'can_view_client',
    icon: 'business',
    label: 'View Client Info',
    description: 'Linked client name, domain, status'
  },
  {
    key: 'can_view_contacts',
    icon: 'contacts',
    label: 'View Contacts',
    description: 'Client contact details (email, phone, position)'
  },
  {
    key: 'can_access_drive',
    icon: 'folder',
    label: 'Access Drive Folder',
    description: 'Shared access to the board\'s Drive folder'
  }
]

// Load company users
async function loadCompanyUsers() {
  loadingCompanyUsers.value = true
  try {
    const response = await api.get('/boards/company-users')
    if (response.data.success) {
      companyUsers.value = response.data.data.users || []
      companyDomain.value = response.data.data.domain || ''
    }
  } catch (error) {
    console.error('Failed to load company users:', error)
    companyUsers.value = []
  } finally {
    loadingCompanyUsers.value = false
  }
}

function toggleUserSelection(userEmail) {
  const index = selectedCompanyUsers.value.indexOf(userEmail)
  if (index === -1) {
    selectedCompanyUsers.value.push(userEmail)
  } else {
    selectedCompanyUsers.value.splice(index, 1)
  }
}

function removeSelectedUser(userEmail) {
  const index = selectedCompanyUsers.value.indexOf(userEmail)
  if (index !== -1) {
    selectedCompanyUsers.value.splice(index, 1)
  }
}

// Computed
const title = computed(() => props.isEditing ? 'Edit Member Permissions' : 'Share Board')
const buttonText = computed(() => props.isEditing ? 'Save Changes' : 'Share')

// Watch for member changes (when editing)
watch(() => props.member, (newMember) => {
  if (newMember) {
    email.value = newMember.email
    role.value = newMember.role || 'editor'
    permissions.value = {
      can_view_financials: newMember.can_view_financials || false,
      can_view_client: newMember.can_view_client || false,
      can_view_contacts: newMember.can_view_contacts || false,
      can_access_drive: newMember.can_access_drive || false
    }
    drivePermission.value = newMember.drive_permission || 'viewer'
  }
}, { immediate: true })

// Reset form when modal opens for new member
watch(() => props.show, (isShown) => {
  if (isShown && !props.isEditing) {
    email.value = ''
    role.value = 'editor'
    permissions.value = {
      can_view_financials: false,
      can_view_client: false,
      can_view_contacts: false,
      can_access_drive: false
    }
    drivePermission.value = 'editor'
    activeTab.value = 'company'
    selectedCompanyUsers.value = []
    selectedGroupId.value = null
    searchQuery.value = ''
    showUserDropdown.value = false
    externalEmail.value = ''
    loadCompanyUsers()
    colleaguesStore.fetchGroups()
  }
})

// Methods
function close() {
  emit('close')
}

async function save() {
  // For new members
  if (!props.isEditing) {
    // If company tab, use selected users
    if (activeTab.value === 'company') {
      if (selectedCompanyUsers.value.length === 0) {
        toast.warning('Please select at least one team member')
        return
      }
      
      saving.value = true

      // One bulk emit instead of N per-user emits. The parent listens
      // for @save-bulk and decides whether to loop the existing endpoint
      // client-side or call a future /boards/{id}/share/batch.
      emit('save-bulk', {
        emails: selectedCompanyUsers.value.map(e => e.toLowerCase()),
        role: role.value,
        permissions: {
          ...permissions.value,
          drive_permission: drivePermission.value
        }
      })

      saving.value = false
      return
    }
    
    // If groups tab, share with entire group
    if (activeTab.value === 'groups') {
      if (!selectedGroupId.value) {
        toast.warning('Please select a group')
        return
      }
      
      saving.value = true
      
      // Get group members and add them all
      const members = await colleaguesStore.getGroupMembers(selectedGroupId.value)
      const group = colleaguesStore.sortedGroups.find(g => g.id === selectedGroupId.value)
      
      if (!members || members.length === 0) {
        toast.warning('Selected group has no members')
        saving.value = false
        return
      }

      emit('save-bulk', {
        emails: members.map(m => m.email.toLowerCase()),
        role: role.value,
        permissions: {
          ...permissions.value,
          drive_permission: drivePermission.value
        }
      })

      toast.success(`Added ${members.length} members from ${group?.name || 'group'}`)
      saving.value = false
      return
    }
    
    // External tab - guest email
    if (activeTab.value === 'external') {
      if (!externalEmail.value.trim()) {
        toast.warning('Please enter an email address')
        return
      }
      if (!isExternalEmailValid.value) {
        toast.warning('Please enter a valid email address')
        return
      }

      saving.value = true

      emit('save', {
        email: externalEmail.value.trim().toLowerCase(),
        role: role.value,
        permissions: {
          ...permissions.value,
          drive_permission: drivePermission.value
        }
      })

      saving.value = false
      return
    }

    // Manual tab - single email (internal)
    if (!email.value.trim()) {
      toast.warning('Please enter an email address')
      return
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    if (!emailRegex.test(email.value)) {
      toast.warning('Please enter a valid email address')
      return
    }
  }
  
  saving.value = true
  
  emit('save', {
    email: email.value.trim().toLowerCase(),
    role: role.value,
    permissions: { 
      ...permissions.value,
      drive_permission: drivePermission.value
    }
  })
  
  saving.value = false
}

function togglePermission(key) {
  permissions.value[key] = !permissions.value[key]
}

// Stop propagation to prevent closing modal when clicking inside
function handleContentClick(e) {
  e.stopPropagation()
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div 
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/50 backdrop-blur-sm"
        @mousedown.self="close"
      >
        <div 
          class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden"
          @mousedown.stop="handleContentClick"
        >
          <!-- Header -->
          <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-surface-200 dark:border-surface-700 shrink-0">
            <div class="flex items-center justify-between">
              <h2 class="text-base sm:text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">{{ isEditing ? 'manage_accounts' : 'person_add' }}</span>
                {{ title }}
              </h2>
              <button 
                @click="close"
                class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                <span class="material-symbols-rounded text-surface-500">close</span>
              </button>
            </div>
          </div>
          
          <!-- Content -->
          <div class="p-4 sm:p-6 space-y-4 overflow-y-auto flex-1">
            <!-- Tabs (only for new member) -->
            <div v-if="!isEditing" class="flex gap-1 p-1 bg-surface-100 dark:bg-surface-700 rounded-xl">
              <button
                @click="activeTab = 'company'"
                :class="[
                  'flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-1',
                  activeTab === 'company'
                    ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                    : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg">domain</span>
                <span class="hidden sm:inline">Company</span>
              </button>
              <button
                @click="activeTab = 'groups'"
                :class="[
                  'flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-1',
                  activeTab === 'groups'
                    ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                    : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg">groups</span>
                <span class="hidden sm:inline">Groups</span>
              </button>
              <button
                @click="activeTab = 'manual'"
                :class="[
                  'flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-1',
                  activeTab === 'manual'
                    ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                    : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg">edit</span>
                <span class="hidden sm:inline">Manual</span>
              </button>
              <button
                @click="activeTab = 'external'"
                :class="[
                  'flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-1',
                  activeTab === 'external'
                    ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                    : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg">public</span>
                <span class="hidden sm:inline">External</span>
              </button>
            </div>
            
            <!-- Company tab - Multi-select dropdown -->
            <div v-if="!isEditing && activeTab === 'company'">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Select Team Members
                <span v-if="companyDomain" class="text-xs font-normal text-surface-400 ml-1">(@{{ companyDomain }})</span>
              </label>
              
              <!-- Selected users chips -->
              <div v-if="selectedCompanyUsers.length > 0" class="flex flex-wrap gap-2 mb-2">
                <span 
                  v-for="userEmail in selectedCompanyUsers" 
                  :key="userEmail"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 rounded-full text-sm"
                >
                  <span class="truncate max-w-[150px]">{{ userEmail.split('@')[0] }}</span>
                  <button 
                    @click="removeSelectedUser(userEmail)"
                    class="hover:bg-primary-200 dark:hover:bg-primary-500/30 rounded-full p-0.5"
                  >
                    <span class="material-symbols-rounded text-sm">close</span>
                  </button>
                </span>
              </div>
              
              <!-- Dropdown trigger -->
              <div class="relative">
                <button
                  @click="showUserDropdown = !showUserDropdown"
                  class="w-full px-4 py-2.5 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-left flex items-center justify-between hover:border-surface-300 dark:hover:border-surface-500 transition-all"
                >
                  <span v-if="selectedCompanyUsers.length === 0" class="text-surface-400">
                    Select team members...
                  </span>
                  <span v-else class="text-surface-900 dark:text-surface-100">
                    {{ selectedCompanyUsers.length }} selected
                  </span>
                  <span class="material-symbols-rounded text-surface-400">
                    {{ showUserDropdown ? 'expand_less' : 'expand_more' }}
                  </span>
                </button>
                
                <!-- Dropdown -->
                <div 
                  v-if="showUserDropdown"
                  class="absolute z-10 w-full mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-lg overflow-hidden"
                >
                  <!-- Search -->
                  <div class="p-2 border-b border-surface-200 dark:border-surface-600">
                    <input
                      v-model="searchQuery"
                      type="text"
                      placeholder="Search users..."
                      class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-600 border-0 rounded-lg text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:ring-2 focus:ring-primary-500/20"
                    />
                  </div>
                  
                  <!-- User list -->
                  <div class="max-h-48 overflow-y-auto">
                    <div v-if="loadingCompanyUsers" class="flex items-center justify-center py-6">
                      <span class="material-symbols-rounded text-xl text-primary-500 animate-spin">progress_activity</span>
                    </div>
                    <div v-else-if="filteredCompanyUsers.length === 0" class="py-6 text-center text-sm text-surface-500">
                      No team members found
                    </div>
                    <button
                      v-else
                      v-for="userEmail in filteredCompanyUsers"
                      :key="userEmail"
                      @click="toggleUserSelection(userEmail)"
                      class="w-full px-4 py-2.5 text-left text-sm hover:bg-surface-50 dark:hover:bg-surface-600 flex items-center justify-between transition-colors"
                    >
                      <div class="flex items-center gap-3 min-w-0">
                        <UserAvatar :email="userEmail" size="md" />
                        <span class="truncate text-surface-900 dark:text-surface-100">{{ userEmail }}</span>
                      </div>
                      <span 
                        v-if="selectedCompanyUsers.includes(userEmail)"
                        class="material-symbols-rounded text-primary-500"
                      >check_circle</span>
                    </button>
                  </div>
                </div>
              </div>
              
              <p v-if="companyUsers.length === 0 && !loadingCompanyUsers" class="mt-2 text-xs text-surface-500">
                No other users found in your organization. Use the Manual tab to add external users.
              </p>
            </div>
            
            <!-- Groups tab - Select group -->
            <div v-if="!isEditing && activeTab === 'groups'">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Select Team Group
              </label>
              
              <div v-if="colleaguesStore.sortedGroups.length === 0" class="text-center py-6 text-surface-500">
                <span class="material-symbols-rounded text-4xl mb-2">groups</span>
                <p class="text-sm">No groups available</p>
                <p class="text-xs">Create groups in Team management first</p>
              </div>
              
              <div v-else class="space-y-2 max-h-48 overflow-y-auto">
                <button
                  v-for="group in colleaguesStore.sortedGroups"
                  :key="group.id"
                  @click="selectedGroupId = group.id"
                  :class="[
                    'w-full p-3 rounded-xl border text-left flex items-center gap-3 transition-all',
                    selectedGroupId === group.id
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                      : 'border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500'
                  ]"
                >
                  <div 
                    class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0"
                    :style="{ backgroundColor: (group.color || '#6366f1') + '20', color: group.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded">{{ group.icon || 'group' }}</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ group.name }}</p>
                    <p class="text-xs text-surface-500">{{ group.member_count || 0 }} members</p>
                  </div>
                  <span 
                    v-if="selectedGroupId === group.id"
                    class="material-symbols-rounded text-primary-500"
                  >check_circle</span>
                </button>
              </div>
              
              <p v-if="selectedGroupId" class="mt-3 text-xs text-surface-500 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">info</span>
                All group members will be added to this board with the selected role and permissions.
              </p>
            </div>
            
            <!-- Manual tab - Email input -->
            <div v-if="!isEditing && activeTab === 'manual'">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Email Address
              </label>
              <input
                v-model="email"
                type="email"
                placeholder="colleague@example.com"
                class="w-full px-4 py-2.5 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                @keydown.enter="save"
              />
            </div>
            
            <!-- External tab - Guest/client invitation -->
            <div v-if="!isEditing && activeTab === 'external'">
              <div class="flex items-start gap-3 p-3 mb-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl">
                <span class="material-symbols-rounded text-amber-500 shrink-0 mt-0.5">shield_person</span>
                <div class="text-xs text-amber-700 dark:text-amber-400">
                  <p class="font-medium mb-1">Guest access</p>
                  <p>External users (clients, partners) will be added as guests with restricted permissions. They cannot see client info, contacts, or linked emails unless explicitly granted.</p>
                </div>
              </div>
              
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Guest Email Address
              </label>
              <input
                v-model="externalEmail"
                type="email"
                placeholder="client@external-company.com"
                class="w-full px-4 py-2.5 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                @keydown.enter="save"
              />
              <p class="mt-2 text-xs text-surface-500 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">info</span>
                The user must have a FlowOne account to access the board.
              </p>
            </div>
            
            <!-- Show email for editing -->
            <div v-if="isEditing" class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-700/50 rounded-xl">
              <div class="w-10 h-10 rounded-full bg-primary-500 flex items-center justify-center text-white font-medium">
                {{ email.charAt(0).toUpperCase() }}
              </div>
              <div>
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ email }}</p>
                <p class="text-xs text-surface-500">Member</p>
              </div>
            </div>
            
            <!-- Role selector -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Role
              </label>
              <div class="grid grid-cols-2 gap-2">
                <button
                  @click="role = 'editor'"
                  :class="[
                    'px-4 py-3 rounded-xl border text-sm font-medium transition-all',
                    role === 'editor'
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400'
                      : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-surface-300 dark:hover:border-surface-500'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg block mb-1">edit</span>
                  Editor
                </button>
                <button
                  @click="role = 'viewer'"
                  :class="[
                    'px-4 py-3 rounded-xl border text-sm font-medium transition-all',
                    role === 'viewer'
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400'
                      : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-surface-300 dark:hover:border-surface-500'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg block mb-1">visibility</span>
                  Viewer
                </button>
              </div>
              <p class="mt-2 text-xs text-surface-500">
                {{ role === 'editor' ? 'Can create, edit, and move cards' : 'Can only view the board and cards' }}
              </p>
            </div>
            
            <!-- Permissions -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Permissions
              </label>
              <div class="space-y-1.5">
                <div v-for="perm in permissionItems" :key="perm.key">
                  <button
                    @click="togglePermission(perm.key)"
                    class="w-full flex items-center justify-between p-2.5 sm:p-3 rounded-xl border border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500 transition-all group"
                  >
                    <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                      <span 
                        class="material-symbols-rounded text-lg shrink-0"
                        :class="permissions[perm.key] ? 'text-primary-500' : 'text-surface-400'"
                      >{{ perm.icon }}</span>
                      <div class="text-left min-w-0">
                        <p class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ perm.label }}</p>
                        <p class="text-xs text-surface-400 truncate hidden sm:block">{{ perm.description }}</p>
                      </div>
                    </div>
                    <div
                      :class="[
                        'relative inline-flex h-5 w-9 sm:h-6 sm:w-11 items-center rounded-full transition-colors shrink-0 ml-2',
                        permissions[perm.key] ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                      ]"
                    >
                      <span
                        :class="[
                          'inline-block h-3.5 w-3.5 sm:h-4 sm:w-4 transform rounded-full bg-white transition-transform shadow-sm',
                          permissions[perm.key] ? 'translate-x-4 sm:translate-x-6' : 'translate-x-1'
                        ]"
                      />
                    </div>
                  </button>
                  
                  <!-- Drive permission selector (shown when Drive access is enabled) -->
                  <div 
                    v-if="perm.key === 'can_access_drive' && permissions.can_access_drive"
                    class="mt-2 p-3 bg-surface-50 dark:bg-surface-700/50 rounded-xl space-y-2"
                  >
                    <label class="block text-xs font-medium text-surface-600 dark:text-surface-400">
                      Drive access level
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                      <button
                        @click.stop="drivePermission = 'viewer'"
                        :class="[
                          'px-4 py-2.5 rounded-xl text-xs font-medium transition-all flex items-center justify-center gap-1.5',
                          drivePermission === 'viewer'
                            ? 'bg-amber-500 text-white'
                            : 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-500'
                        ]"
                      >
                        <span class="material-symbols-rounded text-base">visibility</span>
                        View Only
                      </button>
                      <button
                        @click.stop="drivePermission = 'editor'"
                        :class="[
                          'px-4 py-2.5 rounded-xl text-xs font-medium transition-all flex items-center justify-center gap-1.5',
                          drivePermission === 'editor'
                            ? 'bg-primary-500 text-white'
                            : 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-500'
                        ]"
                      >
                        <span class="material-symbols-rounded text-base">edit</span>
                        Can Edit
                      </button>
                    </div>
                    <p class="text-xs text-surface-500">
                      {{ drivePermission === 'editor' ? 'Can upload and delete files' : 'Can view and download files only' }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Footer -->
          <div class="px-4 sm:px-6 py-3 sm:py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2 sm:gap-3 shrink-0">
            <button
              @click="close"
              class="px-3 sm:px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100 transition-colors"
            >
              Cancel
            </button>
            <button
              @click="save"
              :disabled="saving || (!isEditing && activeTab === 'manual' && !email.trim()) || (!isEditing && activeTab === 'company' && selectedCompanyUsers.length === 0) || (!isEditing && activeTab === 'groups' && !selectedGroupId) || (!isEditing && activeTab === 'external' && !isExternalEmailValid)"
              class="px-4 sm:px-5 py-2 bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/50 text-white text-sm font-medium rounded-xl transition-colors flex items-center gap-2"
            >
              <span v-if="saving" class="material-symbols-rounded animate-spin text-lg">progress_activity</span>
              <span>{{ buttonText }}</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: all 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from > div,
.modal-leave-to > div {
  transform: scale(0.95);
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>

