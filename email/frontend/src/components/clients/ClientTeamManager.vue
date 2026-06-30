<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['updated'])

const toast = useToastStore()

// State
const loading = ref(false)
const members = ref([])
const ownerEmail = ref('')
const isOwner = ref(false)
const showAddForm = ref(false)
const newMemberEmail = ref('')
const newMemberRole = ref('member')
const adding = ref(false)
const removingId = ref(null)

// Load members
async function loadMembers() {
  loading.value = true
  try {
    const response = await api.get(`/clients/${props.clientId}/members`)
    if (response.data.success) {
      members.value = response.data.data.members || []
      ownerEmail.value = response.data.data.owner_email || ''
      isOwner.value = response.data.data.is_owner || false
    }
  } catch (error) {
    console.error('Failed to load team members:', error)
    toast.error('Failed to load team members')
  } finally {
    loading.value = false
  }
}

// Add member
async function addMember() {
  if (!newMemberEmail.value?.trim()) {
    toast.error('Please enter an email address')
    return
  }
  
  adding.value = true
  try {
    const response = await api.post(`/clients/${props.clientId}/members`, {
      email: newMemberEmail.value.trim(),
      role: newMemberRole.value
    })
    
    if (response.data.success) {
      toast.success('Team member added')
      newMemberEmail.value = ''
      newMemberRole.value = 'member'
      showAddForm.value = false
      await loadMembers()
      emit('updated')
    } else {
      toast.error(response.data.message || 'Failed to add member')
    }
  } catch (error) {
    console.error('Failed to add member:', error)
    toast.error(error.response?.data?.message || 'Failed to add member')
  } finally {
    adding.value = false
  }
}

// Remove member
async function removeMember(memberEmail) {
  if (!confirm(`Remove ${memberEmail} from this client's team?`)) {
    return
  }
  
  removingId.value = memberEmail
  try {
    const response = await api.delete(`/clients/${props.clientId}/members/${encodeURIComponent(memberEmail)}`)
    
    if (response.data.success) {
      toast.success('Team member removed')
      await loadMembers()
      emit('updated')
    } else {
      toast.error(response.data.message || 'Failed to remove member')
    }
  } catch (error) {
    console.error('Failed to remove member:', error)
    toast.error('Failed to remove member')
  } finally {
    removingId.value = null
  }
}

// Update member role
async function updateRole(memberEmail, newRole) {
  try {
    const response = await api.put(`/clients/${props.clientId}/members/${encodeURIComponent(memberEmail)}`, {
      role: newRole
    })
    
    if (response.data.success) {
      toast.success('Role updated')
      await loadMembers()
    } else {
      toast.error('Failed to update role')
    }
  } catch (error) {
    console.error('Failed to update role:', error)
    toast.error('Failed to update role')
  }
}

// Get initials from email
function getInitials(email) {
  const name = email.split('@')[0]
  return name.substring(0, 2).toUpperCase()
}

// Get color from email (consistent per email)
function getColor(email) {
  const colors = [
    'bg-blue-500', 'bg-green-500', 'bg-purple-500', 'bg-amber-500',
    'bg-red-500', 'bg-teal-500', 'bg-pink-500', 'bg-indigo-500'
  ]
  const hash = email.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0)
  return colors[hash % colors.length]
}

onMounted(() => {
  loadMembers()
})
</script>

<template>
  <div class="client-team-manager">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500">group</span>
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Team Members</h3>
        <span 
          v-if="members.length > 0"
          class="px-1.5 py-0.5 text-xs bg-surface-200 dark:bg-surface-700 rounded-full"
        >
          {{ members.length }}
        </span>
      </div>
      
      <button
        v-if="isOwner && !showAddForm"
        @click="showAddForm = true"
        class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg transition-colors"
      >
        <span class="material-symbols-rounded text-sm">person_add</span>
        Add
      </button>
    </div>
    
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-8">
      <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
    </div>
    
    <template v-else>
      <!-- Add Member Form -->
      <div v-if="showAddForm && isOwner" class="mb-4 p-3 bg-surface-50 dark:bg-surface-800 rounded-lg border border-surface-200 dark:border-surface-700">
        <div class="flex flex-col sm:flex-row gap-2 mb-3">
          <input
            v-model="newMemberEmail"
            type="email"
            placeholder="colleague@example.com"
            class="flex-1 px-3 py-2 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 placeholder-surface-400 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            @keyup.enter="addMember"
          />
          
          <select
            v-model="newMemberRole"
            class="px-3 py-2 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
          >
            <option value="member">Member</option>
          </select>
        </div>
        
        <div class="flex justify-end gap-2">
          <button
            @click="showAddForm = false; newMemberEmail = ''"
            class="px-3 py-1.5 text-sm text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button
            @click="addMember"
            :disabled="adding || !newMemberEmail.trim()"
            class="px-3 py-1.5 text-sm font-medium bg-primary-500 text-white rounded-lg hover:bg-primary-600 disabled:opacity-50 transition-colors"
          >
            <span v-if="adding" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
            <span v-else>Add Member</span>
          </button>
        </div>
      </div>
      
      <!-- Owner -->
      <div class="mb-3 p-2 bg-amber-50 dark:bg-amber-500/10 rounded-lg border border-amber-200 dark:border-amber-500/20">
        <div class="flex items-center gap-3">
          <div 
            class="w-8 h-8 rounded-full bg-amber-500 flex items-center justify-center text-white text-xs font-medium"
          >
            {{ getInitials(ownerEmail) }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ ownerEmail }}</p>
            <p class="text-xs text-amber-600 dark:text-amber-400">Owner</p>
          </div>
        </div>
      </div>
      
      <!-- Members List -->
      <div v-if="members.length > 0" class="space-y-2">
        <div
          v-for="member in members"
          :key="member.id"
          class="flex items-center gap-3 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors"
        >
          <div 
            :class="[getColor(member.user_email), 'w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-medium']"
          >
            {{ getInitials(member.user_email) }}
          </div>
          
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
              {{ member.user_email }}
            </p>
            <p class="text-xs text-surface-500">
              Added {{ new Date(member.added_at).toLocaleDateString() }}
            </p>
          </div>
          
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 text-xs bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 rounded-full capitalize">
              {{ member.role }}
            </span>
            
            <button
              v-if="isOwner"
              @click="removeMember(member.user_email)"
              :disabled="removingId === member.user_email"
              class="p-1 text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-colors"
              title="Remove member"
            >
              <span 
                class="material-symbols-rounded text-sm"
                :class="{ 'animate-spin': removingId === member.user_email }"
              >
                {{ removingId === member.user_email ? 'progress_activity' : 'close' }}
              </span>
            </button>
          </div>
        </div>
      </div>
      
      <!-- Empty State -->
      <div v-else-if="!showAddForm" class="text-center py-6">
        <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">group_off</span>
        <p class="mt-2 text-sm text-surface-500">No team members yet</p>
        <p v-if="isOwner" class="text-xs text-surface-400 mt-1">
          Add colleagues to track their time on this client
        </p>
      </div>
    </template>
  </div>
</template>

<style scoped>
.client-team-manager {
  @apply bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4;
}
</style>

