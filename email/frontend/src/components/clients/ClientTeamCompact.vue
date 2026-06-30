<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: {
    type: Number,
    required: true
  },
  teamTime: {
    type: Object,
    default: () => ({ by_member: [] })
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
const adding = ref(false)
const removingId = ref(null)
const expanded = ref(false)

// Combine members with their time data
const membersWithTime = computed(() => {
  const timeByEmail = {}
  for (const m of (props.teamTime?.by_member || [])) {
    timeByEmail[m.email?.toLowerCase()] = m.seconds
  }
  
  // Start with owner
  const result = [{
    email: ownerEmail.value,
    isOwner: true,
    seconds: timeByEmail[ownerEmail.value?.toLowerCase()] || 0
  }]
  
  // Add team members
  for (const member of members.value) {
    result.push({
      email: member.user_email,
      isOwner: false,
      role: member.role,
      seconds: timeByEmail[member.user_email?.toLowerCase()] || 0
    })
  }
  
  return result.sort((a, b) => b.seconds - a.seconds)
})

const totalMembers = computed(() => members.value.length + 1) // +1 for owner

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
      role: 'member'
    })
    
    if (response.data.success) {
      toast.success('Team member added')
      newMemberEmail.value = ''
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

function getInitials(email) {
  if (!email) return '??'
  const name = email.split('@')[0]
  return name.substring(0, 2).toUpperCase()
}

function getColor(email, isOwner) {
  if (isOwner) return 'bg-amber-500'
  const colors = [
    'bg-blue-500', 'bg-green-500', 'bg-purple-500', 'bg-teal-500',
    'bg-red-500', 'bg-pink-500', 'bg-indigo-500', 'bg-cyan-500'
  ]
  const hash = (email || '').split('').reduce((acc, char) => acc + char.charCodeAt(0), 0)
  return colors[hash % colors.length]
}

function formatTime(seconds) {
  if (!seconds || seconds <= 0) return '0s'
  const hours = Math.floor(seconds / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  
  if (hours > 0) {
    return `${hours}h ${mins}m`
  }
  if (mins > 0) {
    return `${mins}m ${secs}s`
  }
  return `${secs}s`
}

watch(() => props.clientId, () => {
  loadMembers()
})

onMounted(() => {
  loadMembers()
})
</script>

<template>
  <div class="client-team-compact">
    <!-- Compact Header with member avatars -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-sm text-primary-500">group</span>
        <span class="text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wider">
          Team ({{ totalMembers }})
        </span>
      </div>
      
      <div class="flex items-center gap-1">
        <!-- Stacked avatars preview -->
        <div class="flex -space-x-2 mr-2">
          <div 
            v-for="(member, idx) in membersWithTime.slice(0, 4)"
            :key="member.email"
            :class="[getColor(member.email, member.isOwner), 'w-6 h-6 rounded-full flex items-center justify-center text-white text-[10px] font-medium border-2 border-white dark:border-surface-800']"
            :style="{ zIndex: 4 - idx }"
            :title="member.email"
          >
            {{ getInitials(member.email) }}
          </div>
          <div 
            v-if="totalMembers > 4"
            class="w-6 h-6 rounded-full bg-surface-300 dark:bg-surface-600 flex items-center justify-center text-[10px] font-medium text-surface-600 dark:text-surface-300 border-2 border-white dark:border-surface-800"
          >
            +{{ totalMembers - 4 }}
          </div>
        </div>
        
        <button
          @click="expanded = !expanded"
          class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded transition-colors"
        >
          <span 
            class="material-symbols-rounded text-lg text-surface-400 transition-transform"
            :class="{ 'rotate-180': expanded }"
          >
            expand_more
          </span>
        </button>
      </div>
    </div>
    
    <!-- Expanded View -->
    <div v-if="expanded" class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-4">
        <span class="material-symbols-rounded text-lg text-surface-400 animate-spin">progress_activity</span>
      </div>
      
      <template v-else>
        <!-- Members List -->
        <div class="space-y-2">
          <div
            v-for="member in membersWithTime"
            :key="member.email"
            class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
          >
            <div 
              :class="[getColor(member.email, member.isOwner), 'w-7 h-7 rounded-full flex items-center justify-center text-white text-[10px] font-medium flex-shrink-0']"
            >
              {{ getInitials(member.email) }}
            </div>
            
            <div class="flex-1 min-w-0">
              <p class="text-xs font-medium text-surface-900 dark:text-surface-100 truncate">
                {{ member.email?.split('@')[0] }}
              </p>
            </div>
            
            <div class="flex items-center gap-2 flex-shrink-0">
              <span 
                v-if="member.isOwner"
                class="text-[10px] px-1.5 py-0.5 bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 rounded-full"
              >
                Owner
              </span>
              
              <span class="text-xs font-semibold text-surface-600 dark:text-surface-400">
                {{ formatTime(member.seconds) }}
              </span>
              
              <button
                v-if="isOwner && !member.isOwner"
                @click.stop="removeMember(member.email)"
                :disabled="removingId === member.email"
                class="p-0.5 text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-colors"
                title="Remove member"
              >
                <span 
                  class="material-symbols-rounded text-sm"
                  :class="{ 'animate-spin': removingId === member.email }"
                >
                  {{ removingId === member.email ? 'progress_activity' : 'close' }}
                </span>
              </button>
            </div>
          </div>
        </div>
        
        <!-- Add Member -->
        <div v-if="isOwner" class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
          <div v-if="showAddForm" class="flex gap-2">
            <input
              v-model="newMemberEmail"
              type="email"
              placeholder="colleague@example.com"
              class="flex-1 px-2 py-1.5 text-xs border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 placeholder-surface-400 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              @keyup.enter="addMember"
            />
            <button
              @click="addMember"
              :disabled="adding || !newMemberEmail.trim()"
              class="px-2 py-1.5 text-xs font-medium bg-primary-500 text-white rounded-lg hover:bg-primary-600 disabled:opacity-50 transition-colors"
            >
              <span v-if="adding" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
              <span v-else class="material-symbols-rounded text-sm">check</span>
            </button>
            <button
              @click="showAddForm = false; newMemberEmail = ''"
              class="p-1.5 text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
          <button
            v-else
            @click="showAddForm = true"
            class="w-full flex items-center justify-center gap-1 py-1.5 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded text-sm">person_add</span>
            Add Team Member
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.client-team-compact {
  @apply bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3;
}
</style>

