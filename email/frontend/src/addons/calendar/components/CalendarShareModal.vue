<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  calendar: { type: Object, required: true },
  show: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'updated'])

const calendarStore = useCalendarStore()
const colleagues = useColleaguesStore()
const toast = useToastStore()

// State
const shares = ref([])
const loading = ref(false)
const saving = ref(false)

// New share form
const shareMode = ref('user') // 'user' or 'group'
const selectedEmail = ref('')
const selectedGroupId = ref(null)
const selectedPermission = ref('view')
const canSeeDetails = ref(true)

// Search / filter
const searchQuery = ref('')

const filteredColleagues = computed(() => {
  if (!searchQuery.value.trim()) return colleagues.sortedColleagues
  const q = searchQuery.value.toLowerCase()
  return colleagues.sortedColleagues.filter(c =>
    (c.display_name || '').toLowerCase().includes(q) ||
    c.email.toLowerCase().includes(q)
  )
})

const availableGroups = computed(() => {
  return colleagues.groups || []
})

// Already shared emails/groups for preventing duplicates
const sharedEmails = computed(() => new Set(shares.value.filter(s => s.shared_with_email).map(s => s.shared_with_email.toLowerCase())))
const sharedGroupIds = computed(() => new Set(shares.value.filter(s => s.shared_with_group_id).map(s => s.shared_with_group_id)))

watch(() => props.show, async (val) => {
  if (val) {
    await loadShares()
    // Ensure colleagues/groups are loaded
    if (colleagues.colleagues.length === 0) {
      colleagues.fetchColleagues()
    }
    if (colleagues.groups.length === 0) {
      colleagues.fetchGroups()
    }
  }
})

async function loadShares() {
  loading.value = true
  shares.value = await calendarStore.getCalendarShares(props.calendar.id)
  loading.value = false
}

async function addShare() {
  if (shareMode.value === 'user') {
    if (!selectedEmail.value) {
      toast.warning('Please select a team member')
      return
    }
    if (sharedEmails.value.has(selectedEmail.value.toLowerCase())) {
      toast.warning('Calendar already shared with this person')
      return
    }
    saving.value = true
    const result = await calendarStore.shareCalendar(props.calendar.id, {
      targetEmail: selectedEmail.value,
      permission: selectedPermission.value,
      canSeeDetails: canSeeDetails.value
    })
    saving.value = false
    
    if (result.success) {
      toast.success(`Calendar shared with ${selectedEmail.value}`)
      selectedEmail.value = ''
      searchQuery.value = ''
      await loadShares()
      emit('updated')
    } else {
      toast.error(result.error || 'Failed to share')
    }
  } else {
    if (!selectedGroupId.value) {
      toast.warning('Please select a group')
      return
    }
    if (sharedGroupIds.value.has(selectedGroupId.value)) {
      toast.warning('Calendar already shared with this group')
      return
    }
    saving.value = true
    const result = await calendarStore.shareCalendar(props.calendar.id, {
      groupId: selectedGroupId.value,
      permission: selectedPermission.value,
      canSeeDetails: canSeeDetails.value
    })
    saving.value = false
    
    if (result.success) {
      const group = availableGroups.value.find(g => g.id === selectedGroupId.value)
      toast.success(`Calendar shared with ${group?.name || 'group'}`)
      selectedGroupId.value = null
      await loadShares()
      emit('updated')
    } else {
      toast.error(result.error || 'Failed to share')
    }
  }
}

async function removeShare(share) {
  const opts = {}
  if (share.shared_with_email) {
    opts.targetEmail = share.shared_with_email
  } else if (share.shared_with_group_id) {
    opts.groupId = share.shared_with_group_id
  }
  
  const result = await calendarStore.unshareCalendar(props.calendar.id, opts)
  
  if (result.success) {
    toast.success('Share removed')
    await loadShares()
    emit('updated')
  } else {
    toast.error(result.error || 'Failed to remove share')
  }
}

function selectColleague(colleague) {
  selectedEmail.value = colleague.email
  searchQuery.value = colleague.display_name || colleague.email
}

function getInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="modal-overlay" @click.self="emit('close')">
      <div class="modal max-w-lg">
        <!-- Header -->
        <div class="modal-header">
          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-full" :style="{ backgroundColor: calendar.color || '#3b82f6' }"></div>
            <h3 class="font-semibold">Share "{{ calendar.name }}"</h3>
          </div>
          <button @click="emit('close')" class="btn-ghost btn-icon">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        
        <!-- Body -->
        <div class="modal-body space-y-5">
          <!-- Share mode tabs -->
          <div class="flex gap-1 bg-surface-100 dark:bg-surface-700 rounded-xl p-1">
            <button
              @click="shareMode = 'user'"
              class="flex-1 py-2 px-3 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-1.5"
              :class="shareMode === 'user' 
                ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' 
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
            >
              <span class="material-symbols-rounded text-lg">person</span>
              Team Member
            </button>
            <button
              @click="shareMode = 'group'"
              class="flex-1 py-2 px-3 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-1.5"
              :class="shareMode === 'group' 
                ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' 
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
            >
              <span class="material-symbols-rounded text-lg">group</span>
              Team Group
            </button>
          </div>
          
          <!-- User sharing -->
          <div v-if="shareMode === 'user'" class="space-y-3">
            <div class="relative">
              <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
              <input
                v-model="searchQuery"
                type="text"
                class="input pl-10"
                placeholder="Search team members..."
                @focus="selectedEmail = ''"
              />
            </div>
            
            <!-- Colleague list -->
            <div v-if="searchQuery && !selectedEmail" class="max-h-40 overflow-y-auto border border-surface-200 dark:border-surface-600 rounded-xl">
              <div
                v-for="c in filteredColleagues"
                :key="c.id"
                @click="selectColleague(c)"
                class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                :class="{ 'opacity-50 pointer-events-none': sharedEmails.has(c.email.toLowerCase()) }"
              >
                <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center text-xs font-semibold text-primary-600 dark:text-primary-400">
                  {{ getInitials(c.display_name || c.email) }}
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ c.display_name || c.email }}</p>
                  <p class="text-xs text-surface-500 truncate">{{ c.email }}</p>
                </div>
                <span v-if="sharedEmails.has(c.email.toLowerCase())" class="text-xs text-surface-400">Already shared</span>
              </div>
              <div v-if="filteredColleagues.length === 0" class="px-3 py-4 text-center text-sm text-surface-500">
                No team members found
              </div>
            </div>
            
            <!-- Selected user chip -->
            <div v-if="selectedEmail" class="flex items-center gap-2 bg-primary-50 dark:bg-primary-500/10 rounded-lg px-3 py-2">
              <span class="material-symbols-rounded text-primary-500 text-lg">person</span>
              <span class="text-sm font-medium text-primary-700 dark:text-primary-300 flex-1">{{ selectedEmail }}</span>
              <button @click="selectedEmail = ''; searchQuery = ''" class="text-primary-400 hover:text-primary-600">
                <span class="material-symbols-rounded text-lg">close</span>
              </button>
            </div>
          </div>
          
          <!-- Group sharing -->
          <div v-else class="space-y-3">
            <div v-if="availableGroups.length === 0" class="text-center py-6 text-surface-500">
              <span class="material-symbols-rounded text-3xl mb-2">group_off</span>
              <p class="text-sm">No team groups available</p>
              <p class="text-xs mt-1">Create groups in the Team section first</p>
            </div>
            
            <div v-else class="max-h-48 overflow-y-auto space-y-1">
              <div
                v-for="group in availableGroups"
                :key="group.id"
                @click="selectedGroupId = group.id"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl cursor-pointer transition-colors border-2"
                :class="[
                  selectedGroupId === group.id
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                    : 'border-transparent hover:bg-surface-100 dark:hover:bg-surface-700',
                  { 'opacity-50 pointer-events-none': sharedGroupIds.has(group.id) }
                ]"
              >
                <div class="w-9 h-9 rounded-lg flex items-center justify-center" :style="{ backgroundColor: group.color + '20' }">
                  <span class="material-symbols-rounded" :style="{ color: group.color }">{{ group.icon || 'group' }}</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ group.name }}</p>
                  <p v-if="group.description" class="text-xs text-surface-500 truncate">{{ group.description }}</p>
                </div>
                <span v-if="sharedGroupIds.has(group.id)" class="text-xs text-surface-400">Already shared</span>
                <span v-else-if="selectedGroupId === group.id" class="material-symbols-rounded text-primary-500">check_circle</span>
              </div>
            </div>
          </div>
          
          <!-- Permission settings -->
          <div class="space-y-3 pt-2 border-t border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between">
              <label class="text-sm font-medium text-surface-700 dark:text-surface-300">Permission</label>
              <div class="flex gap-1 bg-surface-100 dark:bg-surface-700 rounded-lg p-0.5">
                <button
                  @click="selectedPermission = 'view'"
                  class="px-3 py-1 rounded-md text-xs font-medium transition-colors"
                  :class="selectedPermission === 'view'
                    ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                    : 'text-surface-500'"
                >
                  View only
                </button>
                <button
                  @click="selectedPermission = 'edit'"
                  class="px-3 py-1 rounded-md text-xs font-medium transition-colors"
                  :class="selectedPermission === 'edit'
                    ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                    : 'text-surface-500'"
                >
                  Can edit
                </button>
              </div>
            </div>
            
            <div class="flex items-center justify-between">
              <div>
                <label class="text-sm font-medium text-surface-700 dark:text-surface-300">Show event details</label>
                <p class="text-xs text-surface-500">If off, they only see "Busy" blocks</p>
              </div>
              <button
                @click="canSeeDetails = !canSeeDetails"
                class="relative w-11 h-6 rounded-full transition-colors"
                :class="canSeeDetails ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
              >
                <span
                  class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform"
                  :class="canSeeDetails ? 'translate-x-5' : 'translate-x-0'"
                ></span>
              </button>
            </div>
          </div>
          
          <!-- Add share button -->
          <button
            @click="addShare"
            :disabled="saving || (shareMode === 'user' ? !selectedEmail : !selectedGroupId)"
            class="btn-primary w-full"
          >
            <span v-if="saving" class="spinner w-4 h-4"></span>
            <span class="material-symbols-rounded">share</span>
            Share Calendar
          </button>
          
          <!-- Current shares list -->
          <div v-if="shares.length > 0" class="space-y-2">
            <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Currently shared with</h4>
            
            <div class="space-y-1">
              <div
                v-for="share in shares"
                :key="share.id"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/50"
              >
                <!-- Avatar/Icon -->
                <div v-if="share.type === 'user'" class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
                  <span class="material-symbols-rounded text-blue-500 text-lg">person</span>
                </div>
                <div v-else class="w-8 h-8 rounded-lg flex items-center justify-center" :style="{ backgroundColor: (share.group_color || '#6366f1') + '20' }">
                  <span class="material-symbols-rounded" :style="{ color: share.group_color || '#6366f1' }">{{ share.group_icon || 'group' }}</span>
                </div>
                
                <!-- Name -->
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                    {{ share.type === 'user' ? share.shared_with_email : share.group_name }}
                  </p>
                  <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-xs px-1.5 py-0.5 rounded-full" :class="share.permission === 'edit'
                      ? 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400'
                      : 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-400'">
                      {{ share.permission === 'edit' ? 'Can edit' : 'View only' }}
                    </span>
                    <span v-if="!share.can_see_details" class="text-xs text-surface-400 flex items-center gap-0.5">
                      <span class="material-symbols-rounded text-xs">visibility_off</span>
                      Busy/free only
                    </span>
                  </div>
                </div>
                
                <!-- Remove -->
                <button
                  @click="removeShare(share)"
                  class="p-1.5 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/10 text-surface-400 hover:text-red-500 transition-colors"
                  title="Remove share"
                >
                  <span class="material-symbols-rounded text-lg">close</span>
                </button>
              </div>
            </div>
          </div>
          
          <!-- Empty state -->
          <div v-else-if="!loading" class="text-center py-4 text-surface-500">
            <span class="material-symbols-rounded text-3xl mb-1">lock</span>
            <p class="text-sm">This calendar is private</p>
            <p class="text-xs mt-1">Share it with team members or groups above</p>
          </div>
          
          <!-- Loading -->
          <div v-if="loading" class="flex justify-center py-4">
            <span class="spinner w-6 h-6"></span>
          </div>
        </div>
        
        <!-- Footer -->
        <div class="modal-footer">
          <button @click="emit('close')" class="btn-primary">Done</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

