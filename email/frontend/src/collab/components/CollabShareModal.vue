<template>
  <Teleport to="body">
    <div 
      v-if="show"
      class="modal-overlay"
      @click.self="$emit('close')"
    >
      <div class="modal max-w-lg bg-white dark:bg-surface-800" @click.stop>
        <!-- Header -->
        <div class="modal-header bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-primary-500" style="font-size: 24px;">group_add</span>
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Share document</h3>
          </div>
          <button 
            @click="$emit('close')" 
            class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded text-surface-500 dark:text-surface-400">close</span>
          </button>
        </div>
        
        <!-- Body -->
        <div class="modal-body bg-white dark:bg-surface-800">
          <!-- Add people section -->
          <div class="mb-6">
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Add people</label>
            
            <!-- Tabs: Team / Groups / External -->
            <div class="flex gap-1 mb-3 p-1 bg-surface-100 dark:bg-surface-700 rounded-xl">
              <button 
                v-for="tab in tabs" :key="tab.id"
                @click="activeTab = tab.id"
                :class="[
                  'flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors',
                  activeTab === tab.id
                    ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                    : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-sm">{{ tab.icon }}</span>
                {{ tab.label }}
              </button>
            </div>

            <!-- Team tab: colleague picker -->
            <div v-if="activeTab === 'team'" class="space-y-2">
              <div class="flex gap-2">
                <div class="flex-1 relative" ref="peopleDropdownRef">
                  <input
                    v-model="memberSearch"
                    @focus="showMemberDropdown = true"
                    @input="showMemberDropdown = true"
                    type="text"
                    placeholder="Search colleagues..."
                    class="w-full px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 placeholder-surface-400 dark:placeholder-surface-500 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none"
                  />
                  <!-- Colleagues dropdown -->
                  <div
                    v-if="showMemberDropdown && filteredColleagues.length > 0"
                    class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-xl z-20 max-h-48 overflow-y-auto"
                  >
                    <button
                      v-for="colleague in filteredColleagues"
                      :key="colleague.id"
                      @click="selectColleague(colleague)"
                      class="w-full px-3 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 flex items-center gap-2.5"
                    >
                      <div
                        class="w-7 h-7 rounded-full flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"
                        :style="{ backgroundColor: getAvatarColor(colleague.email) }"
                      >
                        {{ getInitials(colleague.display_name || colleague.email) }}
                      </div>
                      <div class="flex-1 min-w-0">
                        <span class="block truncate text-surface-800 dark:text-surface-200">{{ colleague.display_name || colleague.email }}</span>
                        <span v-if="colleague.display_name" class="block text-[10px] text-surface-400 truncate">{{ colleague.email }}</span>
                      </div>
                      <span
                        v-if="colleaguesStore.getColleagueStatus(colleague.email) !== 'offline'"
                        class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"
                      />
                    </button>
                  </div>
                  <div
                    v-else-if="showMemberDropdown && memberSearch && filteredColleagues.length === 0"
                    class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-xl z-20 px-3 py-3"
                  >
                    <p class="text-xs text-surface-400 text-center">No colleagues found</p>
                  </div>
                </div>
                <select
                  v-model="newRole"
                  class="px-2 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
                >
                  <option value="viewer">Viewer</option>
                  <option value="editor">Editor</option>
                </select>
              </div>
              <button
                @click="addCollaborator"
                :disabled="!selectedEmail || isAdding"
                class="w-full flex items-center justify-center gap-1.5 px-4 py-2 text-sm rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none"
              >
                <span v-if="isAdding" class="spinner-sm mr-1"></span>
                <span class="material-symbols-rounded text-sm">person_add</span>
                {{ isAdding ? 'Adding...' : 'Add' }}
              </button>
            </div>

            <!-- Groups tab -->
            <div v-else-if="activeTab === 'groups'" class="space-y-2">
              <div class="flex gap-2">
                <select 
                  v-model="selectedGroupId" 
                  class="flex-1 px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
                >
                  <option :value="null" disabled>Select a group...</option>
                  <option v-for="group in availableGroups" :key="group.id" :value="group.id">
                    {{ group.name }} ({{ group.member_count || 0 }} members)
                  </option>
                </select>
                <select
                  v-model="groupRole"
                  class="px-2 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
                >
                  <option value="viewer">Viewer</option>
                  <option value="editor">Editor</option>
                </select>
              </div>
              <button
                @click="addGroupMembers"
                :disabled="!selectedGroupId || isAddingGroup"
                class="w-full flex items-center justify-center gap-1.5 px-4 py-2 text-sm rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none"
              >
                <span v-if="isAddingGroup" class="spinner-sm mr-1"></span>
                <span class="material-symbols-rounded text-sm">group_add</span>
                {{ isAddingGroup ? 'Adding...' : 'Add Group' }}
              </button>
              <p class="text-xs text-surface-400 dark:text-surface-500 flex items-center gap-1">
                <span class="material-symbols-rounded text-xs">info</span>
                All group members will be added individually.
              </p>
            </div>

            <!-- External tab -->
            <div v-else-if="activeTab === 'external'" class="space-y-2">
              <div class="flex gap-2">
                <input
                  v-model="externalEmail"
                  type="email"
                  placeholder="Enter email address..."
                  class="flex-1 px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 placeholder-surface-400 dark:placeholder-surface-500 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none"
                  @keydown.enter.prevent="addExternalCollaborator"
                />
                <select
                  v-model="newRole"
                  class="px-2 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
                >
                  <option value="viewer">Viewer</option>
                  <option value="editor">Editor</option>
                </select>
              </div>
              <button
                @click="addExternalCollaborator"
                :disabled="!externalEmail || isAdding"
                class="w-full flex items-center justify-center gap-1.5 px-4 py-2 text-sm rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none"
              >
                <span v-if="isAdding" class="spinner-sm mr-1"></span>
                <span class="material-symbols-rounded text-sm">person_add</span>
                {{ isAdding ? 'Adding...' : 'Add External' }}
              </button>
            </div>

            <p v-if="addError" class="text-red-500 text-sm mt-2">{{ addError }}</p>
          </div>
          
          <!-- People with access -->
          <div>
            <label class="block text-sm font-medium mb-3 text-surface-700 dark:text-surface-300">People with access</label>
            
            <!-- Loading state -->
            <div v-if="isLoading" class="flex items-center justify-center py-8">
              <span class="material-symbols-rounded animate-spin text-2xl text-primary-500">progress_activity</span>
            </div>
            
            <!-- Collaborators list -->
            <div v-else class="space-y-1.5 max-h-64 overflow-y-auto">
              <!-- Owner -->
              <div 
                v-if="owner"
                class="flex items-center gap-3 p-3 rounded-xl bg-surface-50 dark:bg-surface-700/50"
              >
                <div 
                  class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-medium flex-shrink-0"
                  :style="{ backgroundColor: getAvatarColor(owner.email) }"
                >
                  {{ getInitials(owner.name || owner.email) }}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium truncate text-surface-900 dark:text-surface-100">
                    {{ owner.name || owner.email }}
                    <span v-if="owner.email === currentUserEmail" class="text-xs text-surface-500 dark:text-surface-400">(you)</span>
                  </div>
                  <div class="text-xs truncate text-surface-500 dark:text-surface-400">{{ owner.email }}</div>
                </div>
                <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400">Owner</span>
              </div>
              
              <!-- Collaborators -->
              <div 
                v-for="collaborator in collaborators"
                :key="collaborator.user_email"
                class="flex items-center gap-3 p-3 rounded-xl bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              >
                <div 
                  class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-medium flex-shrink-0"
                  :style="{ backgroundColor: getAvatarColor(collaborator.user_email) }"
                >
                  {{ getInitials(collaborator.user_name || collaborator.user_email) }}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium truncate text-surface-900 dark:text-surface-100">
                    {{ collaborator.user_name || collaborator.user_email }}
                    <span v-if="collaborator.user_email === currentUserEmail" class="text-xs text-surface-500 dark:text-surface-400">(you)</span>
                  </div>
                  <div class="text-xs truncate text-surface-500 dark:text-surface-400">{{ collaborator.user_email }}</div>
                </div>
                <div class="flex items-center gap-1.5">
                  <select
                    :value="collaborator.role"
                    @change="updateRole(collaborator.user_email, $event.target.value)"
                    :disabled="isUpdating === collaborator.user_email"
                    class="text-xs rounded-lg px-2 py-1 border bg-white dark:bg-surface-700 border-surface-300 dark:border-surface-600 text-surface-900 dark:text-surface-100 outline-none"
                  >
                    <option value="viewer">Viewer</option>
                    <option value="editor">Editor</option>
                  </select>
                  <button
                    @click="removeCollaborator(collaborator.user_email)"
                    :disabled="isRemoving === collaborator.user_email"
                    class="p-1 hover:bg-red-100 dark:hover:bg-red-500/20 rounded-lg transition-colors"
                    title="Remove access"
                  >
                    <span v-if="isRemoving === collaborator.user_email" class="spinner-sm text-red-500"></span>
                    <span v-else class="material-symbols-rounded text-red-500" style="font-size: 16px;">person_remove</span>
                  </button>
                </div>
              </div>
              
              <!-- Empty state -->
              <div 
                v-if="collaborators.length === 0 && !isLoading"
                class="text-center py-6 text-surface-500 dark:text-surface-400"
              >
                <span class="material-symbols-rounded mb-2 text-surface-300 dark:text-surface-600" style="font-size: 40px;">group</span>
                <p class="text-sm">No collaborators yet</p>
                <p class="text-xs mt-0.5">Add people to collaborate in real-time</p>
              </div>
            </div>
          </div>
          
          <!-- Info about real-time collaboration -->
          <div class="mt-5 p-3.5 rounded-xl bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/20">
            <div class="flex gap-2.5">
              <span class="material-symbols-rounded text-blue-600 dark:text-blue-400 flex-shrink-0" style="font-size: 18px;">info</span>
              <div class="text-xs leading-relaxed">
                <p class="font-medium text-blue-800 dark:text-blue-300">Real-time collaboration</p>
                <p class="mt-0.5 text-blue-700 dark:text-blue-400/80">Collaborators will see each other's cursors and changes in real-time. Editors can make changes, viewers can only read.</p>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Footer -->
        <div class="modal-footer bg-white dark:bg-surface-800 border-t border-surface-200 dark:border-surface-700">
          <button 
            @click="$emit('close')"
            class="btn btn-ghost"
          >
            Done
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue'
import { useCollabStore } from '../stores/collabStore.js'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  documentUuid: {
    type: String,
    required: true,
  },
  currentUserEmail: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['close'])

const collabStore = useCollabStore()
const colleaguesStore = useColleaguesStore()
const toast = useToastStore()

// Tabs
const tabs = [
  { id: 'team', label: 'Team', icon: 'group' },
  { id: 'groups', label: 'Groups', icon: 'groups' },
  { id: 'external', label: 'External', icon: 'mail' },
]
const activeTab = ref('team')

// Local state
const newRole = ref('editor')
const isLoading = ref(false)
const isAdding = ref(false)
const isUpdating = ref(null)
const isRemoving = ref(null)
const addError = ref('')
const permissions = ref([])

// Team picker state
const memberSearch = ref('')
const selectedEmail = ref('')
const showMemberDropdown = ref(false)
const peopleDropdownRef = ref(null)

// Group state
const selectedGroupId = ref(null)
const groupRole = ref('editor')
const isAddingGroup = ref(false)

// External email state
const externalEmail = ref('')

// Computed
const owner = computed(() => {
  const doc = collabStore.currentDocument
  if (!doc) return null
  return {
    email: doc.owner_email,
    name: doc.owner_name || doc.owner_email,
  }
})

const collaborators = computed(() => {
  return permissions.value.filter(p => p.role !== 'owner')
})

const existingEmails = computed(() => {
  const set = new Set()
  permissions.value.forEach(p => set.add(p.user_email?.toLowerCase()))
  if (owner.value?.email) set.add(owner.value.email.toLowerCase())
  return set
})

const filteredColleagues = computed(() => {
  let list = (colleaguesStore.sortedColleagues || []).filter(c =>
    !existingEmails.value.has(c.email?.toLowerCase())
  )
  if (memberSearch.value) {
    const q = memberSearch.value.toLowerCase()
    list = list.filter(c =>
      (c.display_name || '').toLowerCase().includes(q) ||
      c.email.toLowerCase().includes(q)
    )
  }
  return list.slice(0, 15)
})

const availableGroups = computed(() => {
  return colleaguesStore.sortedGroups || colleaguesStore.groups || []
})

// Load permissions when modal opens
watch(() => props.show, async (showing) => {
  if (showing && props.documentUuid) {
    await loadPermissions()
    // Ensure colleagues & groups are loaded
    if (!colleaguesStore.colleagues?.length) {
      colleaguesStore.fetchColleagues()
    }
    colleaguesStore.fetchGroups()
  }
}, { immediate: true })

async function loadPermissions() {
  isLoading.value = true
  try {
    const response = await collabStore.fetchPermissions(props.documentUuid)
    if (response?.success) {
      const seen = new Set()
      const uniquePermissions = (response.data?.permissions || []).filter(p => {
        const email = p.user_email?.toLowerCase()
        if (seen.has(email)) return false
        seen.add(email)
        return true
      })
      permissions.value = uniquePermissions
    }
  } catch (e) {
    console.error('Failed to load permissions:', e)
    toast.error('Failed to load collaborators')
  } finally {
    isLoading.value = false
  }
}

function selectColleague(colleague) {
  selectedEmail.value = colleague.email
  memberSearch.value = colleague.display_name || colleague.email
  showMemberDropdown.value = false
}

async function addCollaborator() {
  const emailToAdd = (selectedEmail.value || '').trim().toLowerCase()
  if (!emailToAdd) return
  
  const err = validateEmail(emailToAdd)
  if (err) { addError.value = err; return }
  
  addError.value = ''
  isAdding.value = true
  
  try {
    const response = await collabStore.addCollaborator(props.documentUuid, emailToAdd, newRole.value)
    if (response?.success) {
      await loadPermissions()
      selectedEmail.value = ''
      memberSearch.value = ''
      toast.success(`Added ${emailToAdd} as ${newRole.value}`)
    } else {
      addError.value = response?.error || 'Failed to add collaborator'
    }
  } catch (e) {
    console.error('Failed to add collaborator:', e)
    addError.value = e.response?.data?.error || e.message || 'Failed to add collaborator'
  } finally {
    isAdding.value = false
  }
}

async function addExternalCollaborator() {
  const emailToAdd = (externalEmail.value || '').trim().toLowerCase()
  if (!emailToAdd) return
  
  if (!emailToAdd.includes('@') || !emailToAdd.includes('.')) {
    addError.value = 'Please enter a valid email address'
    return
  }
  
  const err = validateEmail(emailToAdd)
  if (err) { addError.value = err; return }
  
  addError.value = ''
  isAdding.value = true
  
  try {
    const response = await collabStore.addCollaborator(props.documentUuid, emailToAdd, newRole.value)
    if (response?.success) {
      await loadPermissions()
      externalEmail.value = ''
      toast.success(`Added ${emailToAdd} as ${newRole.value}`)
    } else {
      addError.value = response?.error || 'Failed to add collaborator'
    }
  } catch (e) {
    console.error('Failed to add collaborator:', e)
    addError.value = e.response?.data?.error || e.message || 'Failed to add collaborator'
  } finally {
    isAdding.value = false
  }
}

async function addGroupMembers() {
  if (!selectedGroupId.value) return
  
  isAddingGroup.value = true
  addError.value = ''
  
  try {
    // Get group members
    const group = (colleaguesStore.sortedGroups || colleaguesStore.groups || []).find(g => g.id === selectedGroupId.value)
    if (!group) {
      addError.value = 'Group not found'
      return
    }
    
    // Fetch group members
    let members = group.members || []
    if (!members.length && colleaguesStore.getGroupMembers) {
      const result = await colleaguesStore.getGroupMembers(selectedGroupId.value)
      members = result?.members || result || []
    }
    
    if (!members.length) {
      // Fallback: use sortedColleagues filtered by group
      addError.value = 'No members found in this group'
      return
    }
    
    let added = 0
    let skipped = 0
    
    for (const member of members) {
      const email = (member.email || member.user_email || '').toLowerCase()
      if (!email || existingEmails.value.has(email)) {
        skipped++
        continue
      }
      try {
        await collabStore.addCollaborator(props.documentUuid, email, groupRole.value)
        added++
      } catch (e) {
        console.warn(`Failed to add group member ${email}:`, e)
        skipped++
      }
    }
    
    await loadPermissions()
    selectedGroupId.value = null
    
    if (added > 0) {
      toast.success(`Added ${added} group member${added > 1 ? 's' : ''} as ${groupRole.value}`)
    }
    if (skipped > 0 && added === 0) {
      addError.value = 'All group members already have access'
    }
  } catch (e) {
    console.error('Failed to add group members:', e)
    addError.value = 'Failed to add group members'
  } finally {
    isAddingGroup.value = false
  }
}

function validateEmail(email) {
  if (existingEmails.value.has(email)) {
    return 'This person already has access'
  }
  if (owner.value?.email?.toLowerCase() === email) {
    return 'This person is the document owner'
  }
  return null
}

async function updateRole(email, newRoleValue) {
  isUpdating.value = email
  try {
    const response = await collabStore.updateCollaboratorRole(props.documentUuid, email, newRoleValue)
    if (response?.success) {
      const idx = permissions.value.findIndex(p => p.user_email === email)
      if (idx !== -1) {
        permissions.value[idx].role = newRoleValue
      }
      toast.success(`Updated role for ${email}`)
    } else {
      toast.error(response?.error || 'Failed to update role')
      await loadPermissions()
    }
  } catch (e) {
    console.error('Failed to update role:', e)
    toast.error('Failed to update role')
    await loadPermissions()
  } finally {
    isUpdating.value = null
  }
}

async function removeCollaborator(email) {
  isRemoving.value = email
  try {
    const response = await collabStore.removeCollaborator(props.documentUuid, email)
    if (response?.success) {
      permissions.value = permissions.value.filter(p => p.user_email !== email)
      toast.success(`Removed ${email} from document`)
    } else {
      toast.error(response?.error || 'Failed to remove collaborator')
    }
  } catch (e) {
    console.error('Failed to remove collaborator:', e)
    toast.error('Failed to remove collaborator')
  } finally {
    isRemoving.value = null
  }
}

// Click outside to close dropdown
function handleClickOutside(e) {
  if (peopleDropdownRef.value && !peopleDropdownRef.value.contains(e.target)) {
    showMemberDropdown.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

// Helpers
function getInitials(name) {
  return name
    .split(/[\s@]/)
    .slice(0, 2)
    .map(n => n[0])
    .join('')
    .toUpperCase()
}

function getAvatarColor(email) {
  const colors = [
    '#F44336', '#E91E63', '#9C27B0', '#673AB7',
    '#3F51B5', '#2196F3', '#03A9F4', '#00BCD4',
    '#009688', '#4CAF50', '#8BC34A', '#FF9800'
  ]
  let hash = 0
  for (let i = 0; i < email.length; i++) {
    hash = email.charCodeAt(i) + ((hash << 5) - hash)
  }
  return colors[Math.abs(hash) % colors.length]
}
</script>

<style scoped>
.spinner-sm {
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: spin 0.75s linear infinite;
  display: inline-block;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
