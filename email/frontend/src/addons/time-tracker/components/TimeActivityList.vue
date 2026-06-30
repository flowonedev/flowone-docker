<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  activities: {
    type: Array,
    default: () => []
  },
  showUser: {
    type: Boolean,
    default: true
  },
  maxVisible: {
    type: Number,
    default: 10
  }
})

// Activity metadata
const activityMeta = {
  email_read: { label: 'Email Read', icon: 'mail', color: 'blue' },
  email_compose: { label: 'Email Compose', icon: 'edit_note', color: 'indigo' },
  calendar_event: { label: 'Calendar Event', icon: 'event', color: 'green' },
  board_view: { label: 'Board View', icon: 'dashboard', color: 'purple' },
  board_task: { label: 'Board Task', icon: 'task_alt', color: 'amber' },
  drive_browse: { label: 'Drive Browse', icon: 'folder_open', color: 'teal' },
  document_open: { label: 'Document Open', icon: 'description', color: 'orange' },
  document_edit: { label: 'Document Edit', icon: 'edit_document', color: 'red' },
  website_work: { label: 'Website Work', icon: 'language', color: 'cyan' },
  mood_board_view: { label: 'Mood Board View', icon: 'dashboard_customize', color: 'pink' },
  mood_board_edit: { label: 'Mood Board Edit', icon: 'palette', color: 'pink' },
  client_call: { label: 'Client Calls', icon: 'videocam', color: 'emerald' }
}

// State for expanded groups
const expandedGroups = ref({})

// Group activities by type and consolidate
const groupedActivities = computed(() => {
  if (!props.activities?.length) return []
  
  const groups = {}
  
  for (const activity of props.activities) {
    const key = `${activity.activity_type}_${activity.user_email || 'unknown'}`
    
    if (!groups[key]) {
      groups[key] = {
        key,
        activityType: activity.activity_type,
        userEmail: activity.user_email,
        totalSeconds: 0,
        entries: [],
        meta: activityMeta[activity.activity_type] || { label: activity.activity_type, icon: 'schedule', color: 'gray' }
      }
    }
    
    groups[key].totalSeconds += activity.duration_seconds || 0
    groups[key].entries.push(activity)
  }
  
  // Sort by total time descending
  return Object.values(groups).sort((a, b) => b.totalSeconds - a.totalSeconds)
})

// Document edits specifically (for special consolidation display)
const documentEditGroups = computed(() => {
  return groupedActivities.value.filter(g => 
    g.activityType === 'document_edit' || g.activityType === 'document_open'
  )
})

const otherGroups = computed(() => {
  return groupedActivities.value.filter(g => 
    g.activityType !== 'document_edit' && g.activityType !== 'document_open'
  )
})

// Total document edits
const totalDocumentEdits = computed(() => {
  let count = 0
  let seconds = 0
  for (const group of documentEditGroups.value) {
    count += group.entries.length
    seconds += group.totalSeconds
  }
  return { count, seconds }
})

const showAllActivities = ref(false)

function toggleGroup(key) {
  expandedGroups.value[key] = !expandedGroups.value[key]
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

function getColorClasses(color) {
  const colors = {
    blue: { bg: 'bg-blue-100 dark:bg-blue-500/20', text: 'text-blue-600 dark:text-blue-400', bar: 'bg-blue-500' },
    indigo: { bg: 'bg-indigo-100 dark:bg-indigo-500/20', text: 'text-indigo-600 dark:text-indigo-400', bar: 'bg-indigo-500' },
    green: { bg: 'bg-green-100 dark:bg-green-500/20', text: 'text-green-600 dark:text-green-400', bar: 'bg-green-500' },
    purple: { bg: 'bg-purple-100 dark:bg-purple-500/20', text: 'text-purple-600 dark:text-purple-400', bar: 'bg-purple-500' },
    amber: { bg: 'bg-amber-100 dark:bg-amber-500/20', text: 'text-amber-600 dark:text-amber-400', bar: 'bg-amber-500' },
    teal: { bg: 'bg-teal-100 dark:bg-teal-500/20', text: 'text-teal-600 dark:text-teal-400', bar: 'bg-teal-500' },
    orange: { bg: 'bg-orange-100 dark:bg-orange-500/20', text: 'text-orange-600 dark:text-orange-400', bar: 'bg-orange-500' },
    red: { bg: 'bg-red-100 dark:bg-red-500/20', text: 'text-red-600 dark:text-red-400', bar: 'bg-red-500' },
    cyan: { bg: 'bg-cyan-100 dark:bg-cyan-500/20', text: 'text-cyan-600 dark:text-cyan-400', bar: 'bg-cyan-500' },
    gray: { bg: 'bg-gray-100 dark:bg-gray-500/20', text: 'text-gray-600 dark:text-gray-400', bar: 'bg-gray-500' }
  }
  return colors[color] || colors.gray
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div class="time-activity-list">
    <!-- Document Edits Consolidated (if any) -->
    <div v-if="totalDocumentEdits.count > 0" class="mb-3">
      <div 
        @click="expandedGroups['documents'] = !expandedGroups['documents']"
        class="flex items-center justify-between p-3 rounded-xl cursor-pointer transition-colors"
        :class="[
          getColorClasses('red').bg,
          'hover:opacity-90'
        ]"
      >
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-red-500 flex items-center justify-center">
            <span class="material-symbols-rounded text-white text-xl">edit_document</span>
          </div>
          <div>
            <p class="font-semibold text-surface-900 dark:text-surface-100">
              {{ totalDocumentEdits.count }} file {{ totalDocumentEdits.count === 1 ? 'change' : 'changes' }}
            </p>
            <p class="text-xs text-surface-500">Document editing</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="font-bold text-lg" :class="getColorClasses('red').text">
            {{ formatDuration(totalDocumentEdits.seconds) }}
          </span>
          <span class="material-symbols-rounded text-surface-400 transition-transform" :class="{ 'rotate-180': expandedGroups['documents'] }">
            expand_more
          </span>
        </div>
      </div>
      
      <!-- Expanded document list -->
      <div v-if="expandedGroups['documents']" class="mt-2 ml-4 space-y-1 animate-fadeIn">
        <div 
          v-for="group in documentEditGroups" 
          :key="group.key"
          class="space-y-1"
        >
          <div 
            v-for="(entry, idx) in group.entries.slice(0, showAllActivities ? undefined : 5)" 
            :key="idx"
            class="flex items-center justify-between p-2 rounded-lg bg-surface-50 dark:bg-surface-800 text-sm"
          >
            <div class="flex items-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-surface-400 text-sm">description</span>
              <span class="truncate text-surface-700 dark:text-surface-300">{{ entry.entity_name || 'Document' }}</span>
              <span v-if="showUser" class="text-xs text-surface-400 hidden sm:inline">{{ entry.user_email?.split('@')[0] }}</span>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <span class="text-red-600 dark:text-red-400 font-medium">{{ formatDuration(entry.duration_seconds) }}</span>
              <span class="text-xs text-surface-400">{{ formatDate(entry.tracked_date) }}</span>
            </div>
          </div>
        </div>
        
        <button 
          v-if="totalDocumentEdits.count > 5 && !showAllActivities"
          @click.stop="showAllActivities = true"
          class="w-full py-2 text-xs text-primary-600 dark:text-primary-400 hover:underline"
        >
          Show all {{ totalDocumentEdits.count }} files
        </button>
      </div>
    </div>
    
    <!-- Other Activity Groups -->
    <div class="space-y-2">
      <div 
        v-for="group in otherGroups.slice(0, showAllActivities ? undefined : maxVisible)"
        :key="group.key"
      >
        <div 
          @click="toggleGroup(group.key)"
          class="flex items-center justify-between p-3 rounded-xl cursor-pointer transition-colors"
          :class="[
            getColorClasses(group.meta.color).bg,
            'hover:opacity-90'
          ]"
        >
          <div class="flex items-center gap-3">
            <div 
              class="w-10 h-10 rounded-xl flex items-center justify-center"
              :class="getColorClasses(group.meta.color).bar"
            >
              <span class="material-symbols-rounded text-white text-xl">{{ group.meta.icon }}</span>
            </div>
            <div>
              <p class="font-semibold text-surface-900 dark:text-surface-100">
                {{ group.meta.label }}
                <span v-if="group.entries.length > 1" class="text-surface-500 font-normal text-sm">
                  ({{ group.entries.length }})
                </span>
              </p>
              <p v-if="showUser" class="text-xs text-surface-500">{{ group.userEmail?.split('@')[0] }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <span class="font-bold text-lg" :class="getColorClasses(group.meta.color).text">
              {{ formatDuration(group.totalSeconds) }}
            </span>
            <span 
              v-if="group.entries.length > 1"
              class="material-symbols-rounded text-surface-400 transition-transform" 
              :class="{ 'rotate-180': expandedGroups[group.key] }"
            >
              expand_more
            </span>
          </div>
        </div>
        
        <!-- Expanded entries -->
        <div v-if="expandedGroups[group.key] && group.entries.length > 1" class="mt-2 ml-4 space-y-1 animate-fadeIn">
          <div 
            v-for="(entry, idx) in group.entries.slice(0, 10)" 
            :key="idx"
            class="flex items-center justify-between p-2 rounded-lg bg-surface-50 dark:bg-surface-800 text-sm"
          >
            <div class="flex items-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-surface-400 text-sm">{{ group.meta.icon }}</span>
              <span class="truncate text-surface-700 dark:text-surface-300">{{ entry.entity_name || group.meta.label }}</span>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <span :class="getColorClasses(group.meta.color).text" class="font-medium">{{ formatDuration(entry.duration_seconds) }}</span>
              <span class="text-xs text-surface-400">{{ formatDate(entry.tracked_date) }}</span>
            </div>
          </div>
          <p v-if="group.entries.length > 10" class="text-xs text-surface-400 text-center py-1">
            +{{ group.entries.length - 10 }} more entries
          </p>
        </div>
      </div>
    </div>
    
    <!-- Show more button -->
    <button 
      v-if="otherGroups.length > maxVisible && !showAllActivities"
      @click="showAllActivities = true"
      class="w-full mt-3 py-2 text-sm text-primary-600 dark:text-primary-400 hover:underline flex items-center justify-center gap-1"
    >
      <span class="material-symbols-rounded text-sm">expand_more</span>
      Show {{ otherGroups.length - maxVisible }} more activities
    </button>
    
    <!-- Empty state -->
    <div v-if="!groupedActivities.length" class="text-center py-8">
      <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">hourglass_empty</span>
      <p class="mt-2 text-sm text-surface-500">No activities tracked yet</p>
    </div>
  </div>
</template>

<style scoped>
.animate-fadeIn {
  animation: fadeIn 0.2s ease-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>

