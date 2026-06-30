<script setup>
import { ref, watch, onMounted } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

const props = defineProps({
  clientId: {
    type: Number,
    required: true
  }
})

const loading = ref(false)
const debugData = ref(null)
const error = ref(null)
const expanded = ref(true)
const syncing = ref(false)

async function loadDebugInfo() {
  if (!props.clientId) return
  
  loading.value = true
  error.value = null
  
  try {
    const response = await api.get(`/clients/${props.clientId}/debug`)
    if (response.data.success) {
      debugData.value = response.data.data
      isDebugEnabled() && console.log('[ClientDebug] Full debug data:', debugData.value)
    } else {
      error.value = response.data.message || 'Failed to load debug info'
    }
  } catch (e) {
    console.error('[ClientDebug] Error:', e)
    error.value = e.response?.data?.message || e.message || 'Failed to load debug info'
  } finally {
    loading.value = false
  }
}

async function syncDriveFolder() {
  syncing.value = true
  try {
    const response = await api.post(`/clients/${props.clientId}/sync-drive-folder`)
    isDebugEnabled() && console.log('[ClientDebug] Sync result:', response.data)
    if (response.data.success) {
      alert(`Success: ${response.data.message}`)
      loadDebugInfo() // Reload to show updated data
    } else {
      alert(`Failed: ${response.data.error || 'Unknown error'}`)
    }
  } catch (e) {
    console.error('[ClientDebug] Sync error:', e)
    alert(`Error: ${e.response?.data?.error || e.message}`)
  } finally {
    syncing.value = false
  }
}

watch(() => props.clientId, () => {
  loadDebugInfo()
}, { immediate: true })

function formatDate(dateStr) {
  if (!dateStr) return 'N/A'
  return new Date(dateStr).toLocaleString()
}

function formatDuration(seconds) {
  if (!seconds) return '0s'
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  
  if (hours > 0) {
    return `${hours}h ${minutes}m ${secs}s`
  }
  if (minutes > 0) {
    return `${minutes}m ${secs}s`
  }
  return `${secs}s`
}

function getActivityTypeLabel(type) {
  const labels = {
    'email_read': 'Email Read',
    'email_compose': 'Email Compose',
    'calendar_event': 'Calendar Event',
    'board_view': 'Board View',
    'board_task': 'Board Task',
    'drive_browse': 'Drive Browse',
    'document_open': 'Document Open',
    'document_edit': 'Document Edit',
    'mood_board_view': 'Mood Board View',
    'mood_board_edit': 'Mood Board Edit'
  }
  return labels[type] || type
}

function getActivityTypeIcon(type) {
  const icons = {
    'email_read': 'mail',
    'email_compose': 'edit_note',
    'calendar_event': 'event',
    'board_view': 'dashboard',
    'board_task': 'task_alt',
    'drive_browse': 'folder_open',
    'document_open': 'description',
    'document_edit': 'edit_document',
    'mood_board_view': 'dashboard_customize',
    'mood_board_edit': 'palette'
  }
  return icons[type] || 'schedule'
}
</script>

<template>
  <div class="client-debug-panel">
    <!-- Header with toggle -->
    <div 
      class="flex items-center justify-between cursor-pointer p-3 bg-red-500/10 border-b border-red-500/20"
      @click="expanded = !expanded"
    >
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-red-500">bug_report</span>
        <span class="text-sm font-bold text-red-600 dark:text-red-400">DEBUG PANEL</span>
        <span class="text-xs text-red-500">(Remove after testing)</span>
      </div>
      <div class="flex items-center gap-2">
        <button 
          @click.stop="loadDebugInfo"
          class="p-1 hover:bg-red-500/20 rounded"
          title="Refresh"
        >
          <span class="material-symbols-rounded text-sm text-red-500" :class="{ 'animate-spin': loading }">refresh</span>
        </button>
        <span class="material-symbols-rounded text-red-500">{{ expanded ? 'expand_less' : 'expand_more' }}</span>
      </div>
    </div>
    
    <!-- Content -->
    <div v-if="expanded" class="p-3 text-xs font-mono space-y-4 max-h-[600px] overflow-y-auto">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-4">
        <span class="material-symbols-rounded animate-spin text-red-500">progress_activity</span>
      </div>
      
      <!-- Error -->
      <div v-else-if="error" class="p-2 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded">
        {{ error }}
      </div>
      
      <!-- Debug Data -->
      <template v-else-if="debugData">
        <!-- Client Info -->
        <div class="border border-surface-300 dark:border-surface-600 rounded p-2">
          <div class="font-bold text-surface-700 dark:text-surface-300 mb-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">apartment</span>
            CLIENT INFO
          </div>
          <table class="w-full">
            <tr><td class="text-surface-500 pr-2">ID:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.client.id }}</td></tr>
            <tr><td class="text-surface-500 pr-2">Domain:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.client.domain }}</td></tr>
            <tr><td class="text-surface-500 pr-2">Display Name:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.client.display_name || '(none)' }}</td></tr>
            <tr><td class="text-surface-500 pr-2">Status:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.client.status }}</td></tr>
            <tr><td class="text-surface-500 pr-2">Owner Email:</td><td class="text-amber-600 font-bold">{{ debugData.client.owner_email }}</td></tr>
            <tr><td class="text-surface-500 pr-2">Drive Folder ID:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.client.drive_folder_id || '(not linked)' }}</td></tr>
            <tr><td class="text-surface-500 pr-2">Created:</td><td class="text-surface-900 dark:text-surface-100">{{ formatDate(debugData.client.created_at) }}</td></tr>
            <tr><td class="text-surface-500 pr-2">Last Activity:</td><td class="text-surface-900 dark:text-surface-100">{{ formatDate(debugData.client.last_activity_at) }}</td></tr>
          </table>
        </div>
        
        <!-- Current User -->
        <div class="border border-surface-300 dark:border-surface-600 rounded p-2">
          <div class="font-bold text-surface-700 dark:text-surface-300 mb-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">person</span>
            CURRENT USER
          </div>
          <table class="w-full">
            <tr><td class="text-surface-500 pr-2">Email:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.current_user.email }}</td></tr>
            <tr>
              <td class="text-surface-500 pr-2">Is Owner:</td>
              <td :class="debugData.current_user.is_owner ? 'text-green-600 font-bold' : 'text-red-600 font-bold'">
                {{ debugData.current_user.is_owner ? 'YES' : 'NO' }}
              </td>
            </tr>
          </table>
        </div>
        
        <!-- Team Members -->
        <div class="border border-surface-300 dark:border-surface-600 rounded p-2">
          <div class="font-bold text-surface-700 dark:text-surface-300 mb-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">group</span>
            TEAM MEMBERS ({{ debugData.team_members.length }})
          </div>
          <div v-if="debugData.team_members.length === 0" class="text-surface-500 italic">No team members</div>
          <div v-else class="space-y-1">
            <div v-for="member in debugData.team_members" :key="member.id" class="flex items-center gap-2 p-1 bg-surface-100 dark:bg-surface-700 rounded">
              <span class="text-surface-900 dark:text-surface-100">{{ member.user_email }}</span>
              <span class="text-surface-500">({{ member.role }})</span>
              <span class="text-surface-400 text-[10px]">added {{ formatDate(member.added_at) }}</span>
            </div>
          </div>
        </div>
        
        <!-- Linked Boards -->
        <div class="border border-surface-300 dark:border-surface-600 rounded p-2">
          <div class="font-bold text-surface-700 dark:text-surface-300 mb-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">dashboard</span>
            LINKED BOARDS ({{ debugData.linked_boards.length }})
          </div>
          <div v-if="debugData.linked_boards.length === 0" class="text-surface-500 italic">No boards linked</div>
          <div v-else class="space-y-1">
            <div v-for="board in debugData.linked_boards" :key="board.id" class="flex items-center gap-2 p-1 bg-surface-100 dark:bg-surface-700 rounded">
              <span class="text-blue-600 dark:text-blue-400">#{{ board.id }}</span>
              <span class="text-surface-900 dark:text-surface-100">{{ board.name }}</span>
              <span class="text-surface-500">({{ board.list_count }} lists, {{ board.card_count }} cards)</span>
            </div>
          </div>
        </div>
        
        <!-- Drive Folder -->
        <div class="border border-surface-300 dark:border-surface-600 rounded p-2">
          <div class="font-bold text-surface-700 dark:text-surface-300 mb-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">folder</span>
            DRIVE FOLDER
          </div>
          <div v-if="!debugData.drive_folder" class="space-y-2">
            <div class="text-surface-500 italic">No Drive folder linked</div>
            <div v-if="debugData.linked_boards.length > 0" class="text-amber-600">
              Boards have folders that could be linked!
              <button 
                @click="syncDriveFolder"
                :disabled="syncing"
                class="ml-2 px-2 py-0.5 bg-amber-500 text-white rounded text-[10px] hover:bg-amber-600 disabled:opacity-50"
              >
                {{ syncing ? 'Syncing...' : 'Sync from Board' }}
              </button>
            </div>
          </div>
          <div v-else-if="debugData.drive_folder.error" class="text-red-500">
            Error: {{ debugData.drive_folder.error }}
            <br>Folder ID: {{ debugData.drive_folder.id }}
          </div>
          <div v-else>
            <table class="w-full">
              <tr><td class="text-surface-500 pr-2">ID:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.drive_folder.id }}</td></tr>
              <tr><td class="text-surface-500 pr-2">Name:</td><td class="text-green-600 font-bold">{{ debugData.drive_folder.name }}</td></tr>
              <tr><td class="text-surface-500 pr-2">Files:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.drive_folder.file_count }}</td></tr>
              <tr><td class="text-surface-500 pr-2">Subfolders:</td><td class="text-surface-900 dark:text-surface-100">{{ debugData.drive_folder.subfolder_count }}</td></tr>
            </table>
          </div>
        </div>
        
        <!-- Calendar Events -->
        <div class="border border-surface-300 dark:border-surface-600 rounded p-2">
          <div class="font-bold text-surface-700 dark:text-surface-300 mb-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">event</span>
            CALENDAR EVENTS LINKED ({{ debugData.calendar_events.length }})
            <span v-if="!debugData.summary.calendar_has_client_id_column" class="text-red-500 text-[10px] ml-2">
              (client_id column missing!)
            </span>
          </div>
          <div v-if="!debugData.summary.calendar_has_client_id_column" class="text-red-500 mb-2">
            The calendar_events table is missing the client_id column. Run migration 011 to add it.
          </div>
          <div v-else-if="debugData.calendar_events.length === 0" class="text-surface-500 italic">
            No calendar events linked to this client
          </div>
          <div v-else class="space-y-1">
            <div v-for="event in debugData.calendar_events" :key="event.id" class="p-1 bg-surface-100 dark:bg-surface-700 rounded">
              <div class="flex items-center gap-2">
                <span class="text-purple-600 dark:text-purple-400">#{{ event.id }}</span>
                <span class="text-surface-900 dark:text-surface-100 font-medium">{{ event.title }}</span>
              </div>
              <div class="text-surface-500 text-[10px]">
                {{ formatDate(event.start_time) }} - {{ formatDate(event.end_time) }}
                <span class="ml-2">({{ event.calendar_name }})</span>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Time Tracking -->
        <div class="border border-cyan-500/30 bg-cyan-500/10 rounded p-2">
          <div class="font-bold text-cyan-700 dark:text-cyan-400 mb-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">timer</span>
            TIME TRACKING
            <span class="text-cyan-600 ml-2">
              Total: {{ formatDuration(debugData.time_tracking?.total_seconds || 0) }}
            </span>
          </div>
          
          <div v-if="debugData.time_tracking?.error" class="text-red-500 mb-2">
            Error: {{ debugData.time_tracking.error }}
          </div>
          
          <template v-else-if="debugData.time_tracking">
            <!-- By Activity Type -->
            <div v-if="debugData.time_tracking.by_activity_type?.length > 0" class="mb-3">
              <div class="text-surface-600 dark:text-surface-400 text-[10px] mb-1 font-semibold">BY ACTIVITY TYPE:</div>
              <div class="space-y-1">
                <div 
                  v-for="(item, idx) in debugData.time_tracking.by_activity_type" 
                  :key="idx" 
                  class="flex items-center justify-between p-1 bg-white/50 dark:bg-surface-800/50 rounded"
                >
                  <div class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm text-cyan-600">{{ getActivityTypeIcon(item.activity_type) }}</span>
                    <span class="text-surface-700 dark:text-surface-300">{{ getActivityTypeLabel(item.activity_type) }}</span>
                    <span class="text-surface-500 text-[10px]">({{ item.user_email }})</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="text-cyan-600 font-bold">{{ formatDuration(item.total_seconds) }}</span>
                    <span class="text-surface-400 text-[10px]">{{ item.entry_count }} entries</span>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- My Time -->
            <div v-if="debugData.time_tracking.my_time" class="mb-3">
              <div class="text-surface-600 dark:text-surface-400 text-[10px] mb-1 font-semibold">MY TIME:</div>
              <div class="grid grid-cols-2 gap-1 text-[10px]">
                <div>Total: <span class="font-bold text-cyan-600">{{ formatDuration(debugData.time_tracking.my_time.total_seconds || 0) }}</span></div>
                <div v-if="debugData.time_tracking.my_time.by_type">
                  <div v-for="(seconds, type) in debugData.time_tracking.my_time.by_type" :key="type">
                    {{ getActivityTypeLabel(type) }}: {{ formatDuration(seconds) }}
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Team Time -->
            <div v-if="debugData.time_tracking.team_time?.members?.length > 0" class="mb-3">
              <div class="text-surface-600 dark:text-surface-400 text-[10px] mb-1 font-semibold">TEAM TIME:</div>
              <div class="space-y-1">
                <div 
                  v-for="member in debugData.time_tracking.team_time.members" 
                  :key="member.user_email"
                  class="flex items-center justify-between text-[10px]"
                >
                  <span>{{ member.user_email }}</span>
                  <span class="font-bold text-cyan-600">{{ formatDuration(member.total_seconds) }}</span>
                </div>
              </div>
            </div>
            
            <!-- Recent Activity Log -->
            <div v-if="debugData.time_tracking.activity_log?.length > 0">
              <div class="text-surface-600 dark:text-surface-400 text-[10px] mb-1 font-semibold">RECENT ACTIVITY (last 20):</div>
              <div class="max-h-32 overflow-y-auto space-y-1">
                <div 
                  v-for="(log, idx) in debugData.time_tracking.activity_log" 
                  :key="idx"
                  class="flex items-center justify-between p-1 bg-white/30 dark:bg-surface-800/30 rounded text-[10px]"
                >
                  <div class="flex items-center gap-1 truncate">
                    <span class="material-symbols-rounded text-xs text-surface-400">{{ getActivityTypeIcon(log.activity_type) }}</span>
                    <span class="text-surface-600 dark:text-surface-400 truncate">{{ log.entity_name || log.activity_type }}</span>
                  </div>
                  <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="text-cyan-600">{{ formatDuration(log.duration_seconds) }}</span>
                    <span class="text-surface-400">{{ log.tracked_date }}</span>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Calendar Event Durations (scheduled meeting time) -->
            <div v-if="debugData.time_tracking.calendar_event_durations" class="mt-3 border-t border-cyan-500/20 pt-3">
              <div class="text-surface-600 dark:text-surface-400 text-[10px] mb-1 font-semibold">
                SCHEDULED EVENT TIME ({{ debugData.time_tracking.calendar_event_durations.event_count || 0 }} completed events):
              </div>
              <div class="text-cyan-600 font-bold">
                {{ formatDuration(debugData.time_tracking.calendar_event_durations.total_seconds || 0) }}
              </div>
              <div v-if="debugData.time_tracking.calendar_event_durations.events?.length > 0" class="mt-2 max-h-24 overflow-y-auto space-y-1">
                <div 
                  v-for="event in debugData.time_tracking.calendar_event_durations.events.slice(0, 5)" 
                  :key="event.id"
                  class="flex items-center justify-between text-[10px] p-1 bg-white/30 dark:bg-surface-800/30 rounded"
                >
                  <span class="truncate flex-1">{{ event.title }}</span>
                  <span class="text-cyan-600 ml-2">{{ formatDuration(event.duration_seconds) }}</span>
                </div>
              </div>
            </div>
            
            <div v-if="!debugData.time_tracking.by_activity_type?.length && !debugData.time_tracking.activity_log?.length && !debugData.time_tracking.calendar_event_durations?.total_seconds" class="text-surface-500 italic">
              No time tracked yet for this client
            </div>
          </template>
        </div>
        
        <!-- Summary -->
        <div class="border border-green-500/30 bg-green-500/10 rounded p-2">
          <div class="font-bold text-green-700 dark:text-green-400 mb-2">CONNECTION SUMMARY</div>
          <div class="grid grid-cols-2 gap-2">
            <div>Team Members: <span class="font-bold">{{ debugData.summary.team_member_count }}</span></div>
            <div>Linked Boards: <span class="font-bold">{{ debugData.summary.board_count }}</span></div>
            <div>Calendar Events: <span class="font-bold">{{ debugData.summary.calendar_event_count }}</span></div>
            <div>Drive Folder: <span class="font-bold">{{ debugData.summary.has_drive_folder ? 'Connected' : 'Not connected' }}</span></div>
            <div>Tracked Time: <span class="font-bold text-cyan-600">{{ formatDuration(debugData.summary.total_time_seconds || 0) }}</span></div>
            <div>Event Time: <span class="font-bold text-cyan-600">{{ formatDuration(debugData.summary.event_duration_seconds || 0) }}</span></div>
            <div class="col-span-2 border-t border-green-500/20 pt-1 mt-1">
              Combined Total: <span class="font-bold text-lg text-cyan-600">{{ formatDuration(debugData.summary.combined_time_seconds || 0) }}</span>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.client-debug-panel {
  @apply bg-red-50 dark:bg-red-900/20 border-2 border-red-500/50 rounded-xl overflow-hidden;
}
</style>

