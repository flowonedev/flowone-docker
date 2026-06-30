<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'

const props = defineProps({
  boardId: {
    type: Number,
    default: null
  },
  clientId: {
    type: Number,
    default: null
  },
  limit: {
    type: Number,
    default: 50
  }
})

const activities = ref([])
const loading = ref(false)
const error = ref(null)

// Action type configurations
const actionConfig = {
  // Board actions
  board_created: { icon: 'dashboard', color: 'text-green-500', verb: 'created board' },
  board_renamed: { icon: 'edit', color: 'text-blue-500', verb: 'renamed board to' },
  board_deleted: { icon: 'delete', color: 'text-red-500', verb: 'deleted board' },
  board_archived: { icon: 'archive', color: 'text-amber-500', verb: 'archived board' },
  board_shared: { icon: 'person_add', color: 'text-primary-500', verb: 'shared board with' },
  board_unshared: { icon: 'person_remove', color: 'text-red-500', verb: 'removed' },
  member_updated: { icon: 'manage_accounts', color: 'text-blue-500', verb: 'updated permissions for' },
  member_role_changed: { icon: 'badge', color: 'text-purple-500', verb: 'changed role for' },
  member_permissions_changed: { icon: 'security', color: 'text-indigo-500', verb: 'updated permissions for' },
  
  // List actions
  list_created: { icon: 'view_column', color: 'text-green-500', verb: 'created list' },
  list_deleted: { icon: 'view_column', color: 'text-red-500', verb: 'deleted list' },
  
  // Card actions
  card_created: { icon: 'add_box', color: 'text-green-500', verb: 'created task' },
  card_completed: { icon: 'check_circle', color: 'text-green-500', verb: 'completed' },
  card_reopened: { icon: 'undo', color: 'text-amber-500', verb: 'reopened' },
  card_renamed: { icon: 'edit', color: 'text-blue-500', verb: 'renamed task to' },
  card_updated: { icon: 'edit', color: 'text-blue-500', verb: 'updated' },
  card_moved: { icon: 'swap_horiz', color: 'text-purple-500', verb: 'moved' },
  card_deleted: { icon: 'delete', color: 'text-red-500', verb: 'deleted task' },
  comment_added: { icon: 'comment', color: 'text-blue-500', verb: 'commented on' },
  
  // Todo/checklist actions
  checklist_created: { icon: 'checklist', color: 'text-green-500', verb: 'added checklist' },
  todo_created: { icon: 'add_task', color: 'text-green-500', verb: 'added todo' },
  todo_completed: { icon: 'task_alt', color: 'text-green-500', verb: 'completed todo' },
  todo_uncompleted: { icon: 'remove_done', color: 'text-amber-500', verb: 'unchecked todo' },
  todo_deleted: { icon: 'delete', color: 'text-red-500', verb: 'deleted todo' },
  
  // Client/contact actions
  client_updated: { icon: 'business', color: 'text-blue-500', verb: 'updated client' },
  contact_updated: { icon: 'person', color: 'text-blue-500', verb: 'updated contact' },
  contact_added: { icon: 'person_add', color: 'text-green-500', verb: 'added contact' },
}

// Group activities by date
const groupedActivities = computed(() => {
  const groups = {}
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const yesterday = new Date(today)
  yesterday.setDate(yesterday.getDate() - 1)
  
  activities.value.forEach(activity => {
    const date = new Date(activity.created_at)
    const actDate = new Date(date.getFullYear(), date.getMonth(), date.getDate())
    
    let groupLabel
    if (actDate.getTime() === today.getTime()) {
      groupLabel = 'Today'
    } else if (actDate.getTime() === yesterday.getTime()) {
      groupLabel = 'Yesterday'
    } else {
      groupLabel = date.toLocaleDateString('en-US', { 
        weekday: 'long',
        month: 'short', 
        day: 'numeric' 
      })
    }
    
    if (!groups[groupLabel]) {
      groups[groupLabel] = []
    }
    groups[groupLabel].push(activity)
  })
  
  return groups
})

// Format relative time
function formatTime(dateStr) {
  const date = new Date(dateStr)
  return date.toLocaleTimeString('en-US', { 
    hour: 'numeric', 
    minute: '2-digit',
    hour12: true 
  })
}

// Get display name for user (remove @domain for brevity)
function getUserDisplay(email) {
  if (!email) return 'Unknown'
  return email.split('@')[0]
}

// PH-specific action overrides when activity_log stores a generic card_updated
// but metadata.ph_action reveals the real action.
const phActionOverrides = {
  assignee_added:     { icon: 'person_add',   color: 'text-blue-500',    verb: 'assigned member on' },
  assignee_removed:   { icon: 'person_remove', color: 'text-orange-500', verb: 'removed member from' },
  status_changed:     { icon: 'swap_horiz',   color: 'text-purple-500',  verb: 'changed status on' },
  dependency_added:   { icon: 'link',          color: 'text-indigo-500',  verb: 'added dependency on' },
  dependency_removed: { icon: 'link_off',      color: 'text-rose-500',    verb: 'removed dependency from' },
  watcher_added:      { icon: 'visibility',    color: 'text-cyan-500',    verb: 'added watcher on' },
  work_session:       { icon: 'timer',         color: 'text-green-500',   verb: 'tracked time on' },
}

function getConfig(actionType, metadata) {
  if (metadata?.ph_action && phActionOverrides[metadata.ph_action]) {
    return phActionOverrides[metadata.ph_action]
  }
  return actionConfig[actionType] || { 
    icon: 'history', 
    color: 'text-surface-500', 
    verb: actionType.replace(/_/g, ' ')
  }
}

function getPhDetail(metadata) {
  if (!metadata?.ph_action) return null
  const a = metadata.ph_action
  if (a === 'assignee_added' && metadata.assignee_email) return metadata.assignee_email.split('@')[0]
  if (a === 'assignee_removed' && metadata.assignee_email) return metadata.assignee_email.split('@')[0]
  if (a === 'status_changed' && metadata.new_status) return `to "${metadata.new_status}"`
  if (a === 'work_session' && metadata.duration_seconds) {
    const sec = metadata.duration_seconds
    return sec >= 3600 ? `${(sec / 3600).toFixed(1)}h` : `${Math.round(sec / 60)}m`
  }
  return null
}

// Fetch activities
async function fetchActivities() {
  loading.value = true
  error.value = null
  
  try {
    let response
    if (props.boardId) {
      response = await api.get(`/boards/${props.boardId}/activity`, {
        params: { limit: props.limit }
      })
    } else if (props.clientId) {
      response = await api.get(`/clients/${props.clientId}/activity`, {
        params: { limit: props.limit }
      })
    }
    
    if (response?.data?.success) {
      activities.value = response.data.data.activity || []
    }
  } catch (e) {
    console.error('Failed to fetch activity log:', e)
    error.value = 'Failed to load activity'
  } finally {
    loading.value = false
  }
}

// Watch for prop changes
watch(() => [props.boardId, props.clientId], () => {
  if (props.boardId || props.clientId) {
    fetchActivities()
  }
}, { immediate: true })

// Expose refresh method
defineExpose({ refresh: fetchActivities })
</script>

<template>
  <div class="activity-log">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">history</span>
        Activity Log
      </h3>
      <button 
        @click="fetchActivities"
        :disabled="loading"
        class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
        title="Refresh"
      >
        <span 
          class="material-symbols-rounded text-lg text-surface-500"
          :class="{ 'animate-spin': loading }"
        >refresh</span>
      </button>
    </div>
    
    <!-- Loading state -->
    <div v-if="loading && activities.length === 0" class="flex items-center justify-center py-8">
      <span class="material-symbols-rounded text-2xl text-primary-500 animate-spin">progress_activity</span>
    </div>
    
    <!-- Error state -->
    <div v-else-if="error" class="text-center py-8">
      <span class="material-symbols-rounded text-3xl text-red-400 mb-2">error</span>
      <p class="text-sm text-surface-500">{{ error }}</p>
    </div>
    
    <!-- Empty state -->
    <div v-else-if="activities.length === 0" class="text-center py-8">
      <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600 mb-2">history_toggle_off</span>
      <p class="text-sm text-surface-500">No activity yet</p>
    </div>
    
    <!-- Activity list -->
    <div v-else class="space-y-6">
      <div v-for="(groupActivities, date) in groupedActivities" :key="date">
        <!-- Date header -->
        <div class="sticky top-0 z-10 bg-white dark:bg-surface-800 py-1">
          <p class="text-xs font-semibold text-surface-400 uppercase tracking-wide">{{ date }}</p>
        </div>
        
        <!-- Activities -->
        <div class="relative">
          <!-- Timeline line -->
          <div class="absolute left-3.5 top-2 bottom-2 w-px bg-surface-200 dark:bg-surface-700"></div>
          
          <!-- Activity items -->
          <div class="space-y-3">
            <div 
              v-for="activity in groupActivities" 
              :key="activity.id"
              class="relative flex items-start gap-3 pl-1"
            >
              <!-- Icon -->
              <div 
                class="relative z-10 w-6 h-6 rounded-full bg-white dark:bg-surface-800 border-2 border-surface-200 dark:border-surface-700 flex items-center justify-center"
              >
                <span 
                  class="material-symbols-rounded text-sm"
                  :class="getConfig(activity.action_type, activity.metadata).color"
                >{{ getConfig(activity.action_type, activity.metadata).icon }}</span>
              </div>
              
              <!-- Content -->
              <div class="flex-1 min-w-0 pt-0.5">
                <p class="text-sm text-surface-700 dark:text-surface-300">
                  <span class="font-medium">{{ getUserDisplay(activity.user_email) }}</span>{{ ' ' }}<span class="text-surface-500">{{ getConfig(activity.action_type, activity.metadata).verb }}</span>{{ ' ' }}<span v-if="activity.entity_name" class="font-medium">"{{ activity.entity_name }}"</span>
                </p>
                
                <!-- PH-specific detail (assignee name, duration, status) -->
                <p v-if="getPhDetail(activity.metadata)" class="text-xs text-surface-500 mt-0.5">
                  {{ getPhDetail(activity.metadata) }}
                </p>
                
                <!-- Extra details from metadata -->
                <p v-if="activity.metadata?.card_title && activity.entity_type !== 'card'" class="text-xs text-surface-400 mt-0.5">
                  on card "{{ activity.metadata.card_title }}"
                </p>
                <p v-if="activity.metadata?.from_list && activity.metadata?.to_list" class="text-xs text-surface-400 mt-0.5">
                  from "{{ activity.metadata.from_list }}" to "{{ activity.metadata.to_list }}"
                </p>
                <p v-if="activity.metadata?.preview" class="text-xs text-surface-400 mt-0.5 line-clamp-2">
                  "{{ activity.metadata.preview }}"
                </p>
                
                <p class="text-xs text-surface-400 mt-1">{{ formatTime(activity.created_at) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>

