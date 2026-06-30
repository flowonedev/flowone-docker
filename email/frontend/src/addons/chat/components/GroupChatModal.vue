<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const props = defineProps({
  show: Boolean,
  editMode: {
    type: Boolean,
    default: false
  },
  conversationId: {
    type: Number,
    default: null
  }
})

const emit = defineEmits(['close', 'created', 'updated', 'back'])

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()
const toast = useToastStore()

// Form state
const groupName = ref('')
const description = ref('')
const selectedMembers = ref([])
const memberSearch = ref('')
const isLoading = ref(false)

// Existing group data (for edit mode)
const existingMembers = ref([])
const originalName = ref('')
const originalDescription = ref('')

// Tabs for different ways to add members
const activeTab = ref('colleagues') // 'colleagues' | 'groups' | 'invite'
const inviteEmail = ref('')
const inviteMessage = ref('')
const inviteSending = ref(false)
const sentInvites = ref([])

// Reset form when modal opens
watch(() => props.show, async (show) => {
  if (show) {
    if (props.editMode && props.conversationId) {
      await loadGroupData()
    } else {
      resetForm()
    }
  }
})

function resetForm() {
  groupName.value = ''
  description.value = ''
  selectedMembers.value = []
  memberSearch.value = ''
  inviteEmail.value = ''
  inviteMessage.value = ''
  activeTab.value = 'colleagues'
  existingMembers.value = []
  originalName.value = ''
  originalDescription.value = ''
  sentInvites.value = []
}

async function loadGroupData() {
  if (!props.conversationId) return
  
  isLoading.value = true
  try {
    // Get conversation details
    const convResponse = await api.get(`/chat/conversations/${props.conversationId}`)
    if (convResponse.data.success) {
      const conv = convResponse.data.data.conversation
      groupName.value = conv.name || ''
      description.value = conv.description || ''
      originalName.value = conv.name || ''
      originalDescription.value = conv.description || ''
    }
    
    // Get members
    const membersResponse = await api.get(`/chat/groups/${props.conversationId}/members`)
    if (membersResponse.data.success) {
      existingMembers.value = membersResponse.data.data.members
      selectedMembers.value = existingMembers.value.map(m => m.id)
    }
  } catch (e) {
    toast.error('Failed to load group data')
  }
  isLoading.value = false
}

// Filtered colleagues for selection
const filteredColleagues = computed(() => {
  let list = colleaguesStore.sortedColleagues
  
  if (memberSearch.value) {
    const q = memberSearch.value.toLowerCase()
    list = list.filter(c => 
      c.display_name?.toLowerCase().includes(q) ||
      c.email.toLowerCase().includes(q)
    )
  }
  
  return list
})

// Colleague groups for quick selection
const colleagueGroups = computed(() => {
  return colleaguesStore.sortedGroups || []
})

function toggleMember(colleagueId) {
  const idx = selectedMembers.value.indexOf(colleagueId)
  if (idx === -1) {
    selectedMembers.value.push(colleagueId)
  } else {
    selectedMembers.value.splice(idx, 1)
  }
}

function isMemberSelected(colleagueId) {
  return selectedMembers.value.includes(colleagueId)
}

function selectAllFromGroup(groupId) {
  const groupMembers = colleaguesStore.colleaguesByGroup[groupId] || []
  for (const member of groupMembers) {
    if (!selectedMembers.value.includes(member.id)) {
      selectedMembers.value.push(member.id)
    }
  }
}

async function createGroupFromColleagueGroup(groupId) {
  const group = colleagueGroups.value.find(g => g.id === groupId)
  if (!group) return
  
  isLoading.value = true
  try {
    const response = await api.post('/chat/groups/from-colleague-group', {
      group_id: groupId,
      name: groupName.value || group.name
    })
    
    if (response.data.success) {
      toast.success(`Group "${response.data.data.conversation.name}" created`)
      emit('created', response.data.data.conversation)
      emit('close')
    } else {
      toast.error(response.data.error || 'Failed to create group')
    }
  } catch (e) {
    toast.error('Failed to create group')
  }
  isLoading.value = false
}

// Check if any changes were made in edit mode
const hasChanges = computed(() => {
  if (!props.editMode) return true
  
  const nameChanged = groupName.value.trim() !== originalName.value.trim()
  const descChanged = description.value.trim() !== originalDescription.value.trim()
  const currentMemberIds = existingMembers.value.map(m => m.id)
  const newMemberIds = selectedMembers.value.filter(id => !currentMemberIds.includes(id))
  const removedMemberIds = currentMemberIds.filter(id => !selectedMembers.value.includes(id))
  const membersChanged = newMemberIds.length > 0 || removedMemberIds.length > 0
  
  return nameChanged || descChanged || membersChanged
})

// Can submit: in create mode need name + members; in edit mode just need any change
const canSubmit = computed(() => {
  if (isLoading.value) return false
  if (props.editMode) {
    return hasChanges.value
  }
  return groupName.value.trim().length > 0 && selectedMembers.value.length >= 1
})

async function handleSubmit() {
  if (!props.editMode && !groupName.value.trim()) {
    toast.error('Group name is required')
    return
  }
  
  if (!props.editMode && selectedMembers.value.length < 1) {
    toast.error('Select at least one member')
    return
  }
  
  isLoading.value = true
  
  try {
    if (props.editMode && props.conversationId) {
      // Only update name/description if they changed
      const updates = {}
      if (groupName.value.trim() !== originalName.value.trim()) {
        updates.name = groupName.value.trim()
      }
      if (description.value.trim() !== originalDescription.value.trim()) {
        updates.description = description.value.trim()
      }
      
      if (Object.keys(updates).length > 0) {
        await api.patch(`/chat/groups/${props.conversationId}`, updates)
      }
      
      // Add new members
      const currentMemberIds = existingMembers.value.map(m => m.id)
      const newMemberIds = selectedMembers.value.filter(id => !currentMemberIds.includes(id))
      const removedMemberIds = currentMemberIds.filter(id => !selectedMembers.value.includes(id))
      
      if (newMemberIds.length > 0) {
        await api.post(`/chat/groups/${props.conversationId}/members`, {
          member_ids: newMemberIds
        })
      }
      
      // ONE HTTP call regardless of how many members were unchecked.
      // Server does a single DELETE WHERE IN(...).
      if (removedMemberIds.length > 0) {
        await api.delete(`/chat/groups/${props.conversationId}/members`, {
          data: { member_ids: removedMemberIds }
        })
      }
      
      toast.success('Group updated')
      emit('updated')
    } else {
      // Create new group
      const response = await api.post('/chat/groups', {
        name: groupName.value,
        description: description.value,
        member_ids: selectedMembers.value
      })
      
      if (response.data.success) {
        toast.success('Group created')
        emit('created', response.data.data.conversation)
      } else {
        toast.error(response.data.error || 'Failed to create group')
        isLoading.value = false
        return
      }
    }
    
    emit('close')
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save group')
  }
  
  isLoading.value = false
}

// Send invite to external user
async function sendExternalInvite() {
  if (!inviteEmail.value.trim()) {
    toast.error('Email is required')
    return
  }
  
  // Basic email validation
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!emailRegex.test(inviteEmail.value.trim())) {
    toast.error('Please enter a valid email address')
    return
  }
  
  // If editing, use group invite endpoint
  if (props.editMode && props.conversationId) {
    inviteSending.value = true
    try {
      const response = await api.post(`/chat/groups/${props.conversationId}/invite`, {
        email: inviteEmail.value.trim(),
        message: inviteMessage.value.trim() || null
      })
      
      if (response.data.success) {
        toast.success(`Invitation sent to ${inviteEmail.value}`)
        sentInvites.value.push(inviteEmail.value.trim())
        inviteEmail.value = ''
        inviteMessage.value = ''
      } else {
        toast.error(response.data.error || 'Failed to send invitation')
      }
    } catch (e) {
      toast.error(e.response?.data?.error || 'Failed to send invitation')
    }
    inviteSending.value = false
  } else {
    // For new groups, use the general invite endpoint
    inviteSending.value = true
    try {
      const response = await api.post('/chat/invite', {
        email: inviteEmail.value.trim()
      })
      
      if (response.data.success) {
        toast.success(`Invitation sent to ${inviteEmail.value}`)
        sentInvites.value.push(inviteEmail.value.trim())
        inviteEmail.value = ''
        inviteMessage.value = ''
      } else {
        toast.error(response.data.error || 'Failed to send invitation')
      }
    } catch (e) {
      toast.error(e.response?.data?.error || 'Failed to send invitation')
    }
    inviteSending.value = false
  }
}

// Get selected members details for preview
const selectedMemberDetails = computed(() => {
  return selectedMembers.value.map(id => {
    return colleaguesStore.colleagueById[id] || existingMembers.value.find(m => m.id === id)
  }).filter(Boolean)
})

onMounted(() => {
  if (!colleaguesStore.colleagues.length) {
    colleaguesStore.init()
  }
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div 
        v-if="show"
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999] p-4"
        @click.self="emit('close')"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
          <!-- Header -->
          <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2">
              <button
                v-if="!editMode"
                @click="emit('back')"
                class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
                title="Back"
              >
                <span class="material-symbols-rounded text-surface-500">arrow_back</span>
              </button>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                {{ editMode ? 'Chat Config' : 'New Group Chat' }}
              </h2>
            </div>
            <button
              @click="emit('close')"
              class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-surface-500">close</span>
            </button>
          </div>
          
          <!-- Content -->
          <div class="flex-1 overflow-y-auto p-5">
            <!-- Group Info -->
            <div class="mb-5">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                Group Name *
              </label>
              <input
                v-model="groupName"
                type="text"
                placeholder="e.g. Marketing Team"
                class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
              />
            </div>
            
            <div class="mb-5">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                Description (optional)
              </label>
              <textarea
                v-model="description"
                rows="2"
                placeholder="What's this group about?"
                class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none resize-none"
              ></textarea>
            </div>
            
            <!-- Selected Members Preview -->
            <div v-if="selectedMemberDetails.length > 0" class="mb-4">
              <p class="text-xs font-medium text-surface-500 mb-2">
                {{ selectedMemberDetails.length }} member{{ selectedMemberDetails.length !== 1 ? 's' : '' }} selected
              </p>
              <div class="flex flex-wrap gap-2">
                <div
                  v-for="member in selectedMemberDetails"
                  :key="member.id"
                  class="flex items-center gap-1.5 px-2 py-1 bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 rounded-full text-xs"
                >
                  <span>{{ member.display_name || member.email?.split('@')[0] }}</span>
                  <button
                    @click="toggleMember(member.id)"
                    class="hover:text-primary-900 dark:hover:text-primary-100"
                  >
                    <span class="material-symbols-rounded text-sm">close</span>
                  </button>
                </div>
              </div>
            </div>
            
            <!-- Add Members Tabs -->
            <div class="border-b border-surface-200 dark:border-surface-700 mb-4">
              <div class="flex gap-4">
                <button
                  @click="activeTab = 'colleagues'"
                  :class="[
                    'pb-2 text-sm font-medium border-b-2 transition-colors',
                    activeTab === 'colleagues'
                      ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                      : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                  ]"
                >
                  Colleagues
                </button>
                <button
                  @click="activeTab = 'groups'"
                  :class="[
                    'pb-2 text-sm font-medium border-b-2 transition-colors',
                    activeTab === 'groups'
                      ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                      : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                  ]"
                >
                  From Groups
                </button>
                <button
                  @click="activeTab = 'invite'"
                  :class="[
                    'pb-2 text-sm font-medium border-b-2 transition-colors',
                    activeTab === 'invite'
                      ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                      : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                  ]"
                >
                  Invite External
                </button>
              </div>
            </div>
            
            <!-- Colleagues Tab -->
            <div v-if="activeTab === 'colleagues'">
              <!-- Search -->
              <div class="relative mb-3">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400 text-lg">search</span>
                <input
                  v-model="memberSearch"
                  type="text"
                  placeholder="Search colleagues..."
                  class="w-full pl-9 pr-3 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
                />
              </div>
              
              <!-- Colleagues List -->
              <div class="max-h-52 overflow-y-auto space-y-1">
                <div
                  v-for="colleague in filteredColleagues"
                  :key="colleague.id"
                  @click="toggleMember(colleague.id)"
                  :class="[
                    'flex items-center gap-3 p-2.5 rounded-xl cursor-pointer transition-colors',
                    isMemberSelected(colleague.id)
                      ? 'bg-primary-50 dark:bg-primary-500/10'
                      : 'hover:bg-surface-100 dark:hover:bg-surface-700'
                  ]"
                >
                  <UserAvatar
                    :colleague="colleague"
                    size="lg"
                  />
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                      {{ colleague.display_name || colleague.email.split('@')[0] }}
                    </p>
                    <p class="text-xs text-surface-500 truncate">
                      {{ colleague.job_title || colleague.email }}
                    </p>
                  </div>
                  <div 
                    :class="[
                      'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-colors',
                      isMemberSelected(colleague.id)
                        ? 'bg-primary-500 border-primary-500 text-white'
                        : 'border-surface-300 dark:border-surface-600'
                    ]"
                  >
                    <span v-if="isMemberSelected(colleague.id)" class="material-symbols-rounded text-sm">check</span>
                  </div>
                </div>
                
                <div v-if="filteredColleagues.length === 0" class="text-center py-6 text-surface-500">
                  <span class="material-symbols-rounded text-3xl mb-2 block">person_off</span>
                  <p class="text-sm">No colleagues found</p>
                </div>
              </div>
            </div>
            
            <!-- Groups Tab -->
            <div v-if="activeTab === 'groups'">
              <p class="text-xs text-surface-500 mb-3">
                Create a chat from an existing colleague group, or add all members from a group.
              </p>
              
              <div class="space-y-2">
                <div
                  v-for="group in colleagueGroups"
                  :key="group.id"
                  class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-900 rounded-xl"
                >
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                      <span class="material-symbols-rounded text-primary-500">group</span>
                    </div>
                    <div>
                      <p class="text-sm font-medium text-surface-900 dark:text-surface-100">
                        {{ group.name }}
                      </p>
                      <p class="text-xs text-surface-500">
                        {{ (colleaguesStore.colleaguesByGroup[group.id] || []).length }} members
                      </p>
                    </div>
                  </div>
                  <div class="flex gap-2">
                    <button
                      @click="selectAllFromGroup(group.id)"
                      class="px-3 py-1.5 text-xs font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
                    >
                      Add Members
                    </button>
                    <button
                      @click="createGroupFromColleagueGroup(group.id)"
                      class="px-3 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-500/10 hover:bg-primary-100 dark:hover:bg-primary-500/20 rounded-lg transition-colors"
                    >
                      Create Chat
                    </button>
                  </div>
                </div>
                
                <div v-if="colleagueGroups.length === 0" class="text-center py-6 text-surface-500">
                  <span class="material-symbols-rounded text-3xl mb-2 block">folder_off</span>
                  <p class="text-sm">No colleague groups found</p>
                </div>
              </div>
            </div>
            
            <!-- Invite External Tab -->
            <div v-if="activeTab === 'invite'">
              <p class="text-xs text-surface-500 mb-3">
                Send an invitation email to someone outside your organization. They will receive a link to join this chat.
              </p>
              
              <div class="space-y-3">
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                    Email Address *
                  </label>
                  <input
                    v-model="inviteEmail"
                    type="email"
                    placeholder="colleague@company.com"
                    class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
                    @keyup.enter="sendExternalInvite"
                  />
                </div>
                
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                    Personal Message (optional)
                  </label>
                  <textarea
                    v-model="inviteMessage"
                    rows="2"
                    placeholder="Hi! I'd like to invite you to join our group chat..."
                    class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none resize-none"
                  ></textarea>
                </div>
                
                <button
                  @click="sendExternalInvite"
                  :disabled="inviteSending || !inviteEmail.trim()"
                  class="w-full px-4 py-2.5 text-sm font-medium text-white bg-primary-500 hover:bg-primary-600 rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                >
                  <span v-if="inviteSending" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
                  <span class="material-symbols-rounded text-lg" v-else>send</span>
                  Send Invitation
                </button>
                
                <!-- Sent invites list -->
                <div v-if="sentInvites.length > 0" class="mt-4 pt-3 border-t border-surface-200 dark:border-surface-700">
                  <p class="text-xs font-medium text-surface-500 mb-2">Invitations sent this session</p>
                  <div class="space-y-1.5">
                    <div
                      v-for="email in sentInvites"
                      :key="email"
                      class="flex items-center gap-2 px-3 py-2 bg-emerald-50 dark:bg-emerald-500/10 rounded-lg"
                    >
                      <span class="material-symbols-rounded text-emerald-500 text-lg">check_circle</span>
                      <span class="text-sm text-emerald-700 dark:text-emerald-300">{{ email }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Footer -->
          <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-surface-200 dark:border-surface-700">
            <button
              @click="editMode ? emit('close') : emit('back')"
              class="px-4 py-2.5 text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-xl transition-colors"
            >
              {{ editMode ? 'Cancel' : 'Back' }}
            </button>
            <button
              @click="handleSubmit"
              :disabled="!canSubmit"
              class="px-5 py-2.5 text-sm font-medium text-white bg-primary-500 hover:bg-primary-600 rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
            >
              <span v-if="isLoading" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
              {{ editMode ? 'Save Changes' : 'Create Group' }}
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
</style>

