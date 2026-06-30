<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useDriveStore } from '@/stores/drive'

const props = defineProps({
  show: Boolean
})

const emit = defineEmits(['close'])

const drive = useDriveStore()

// State
const loading = ref(false)
const events = ref([])
const total = ref(0)
const loadingMore = ref(false)
const deleting = ref(new Set())

// Constants
const EVENTS_PER_PAGE = 50

// Group events by time period
const groupedEvents = computed(() => {
  const groups = {
    today: [],
    yesterday: [],
    thisWeek: [],
    thisMonth: [],
    older: []
  }
  
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const yesterday = new Date(today)
  yesterday.setDate(today.getDate() - 1)
  const thisWeek = new Date(today)
  thisWeek.setDate(today.getDate() - today.getDay()) // Start of week (Sunday)
  const thisMonth = new Date(now.getFullYear(), now.getMonth(), 1)
  
  events.value.forEach(event => {
    const eventDate = new Date(event.timestamp * 1000)
    const eventDay = new Date(eventDate.getFullYear(), eventDate.getMonth(), eventDate.getDate())
    
    if (eventDay >= today) {
      groups.today.push(event)
    } else if (eventDay >= yesterday) {
      groups.yesterday.push(event)
    } else if (eventDay >= thisWeek) {
      groups.thisWeek.push(event)
    } else if (eventDay >= thisMonth) {
      groups.thisMonth.push(event)
    } else {
      groups.older.push(event)
    }
  })
  
  return groups
})

const hasMore = computed(() => events.value.length < total.value)
const isEmpty = computed(() => !loading.value && events.value.length === 0)

// Load events when shown
watch(() => props.show, async (showing) => {
  if (showing) {
    await loadEvents()
  }
}, { immediate: true })

async function loadEvents() {
  loading.value = true
  try {
    const result = await drive.fetchActivityLog(EVENTS_PER_PAGE, 0)
    events.value = result.events || []
    total.value = result.total || 0
  } catch (e) {
    console.error('Failed to load activity log:', e)
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  
  loadingMore.value = true
  try {
    const result = await drive.fetchActivityLog(EVENTS_PER_PAGE, events.value.length)
    events.value = [...events.value, ...(result.events || [])]
    total.value = result.total || 0
  } catch (e) {
    console.error('Failed to load more:', e)
  } finally {
    loadingMore.value = false
  }
}

async function deleteEvent(eventId) {
  if (deleting.value.has(eventId)) return
  
  deleting.value.add(eventId)
  try {
    await drive.deleteActivityEvent(eventId)
    events.value = events.value.filter(e => e.id !== eventId)
    total.value = Math.max(0, total.value - 1)
  } catch (e) {
    console.error('Failed to delete event:', e)
  } finally {
    deleting.value.delete(eventId)
  }
}

async function clearAll() {
  if (!confirm('Clear all activity history? This cannot be undone.')) return
  
  loading.value = true
  try {
    await drive.clearActivityLog()
    events.value = []
    total.value = 0
  } catch (e) {
    console.error('Failed to clear activity log:', e)
  } finally {
    loading.value = false
  }
}

function close() {
  emit('close')
}

function getEventIcon(eventType) {
  const icons = {
    'file_created': 'add_circle',
    'file_updated': 'sync',
    'file_deleted': 'delete',
    'folder_created': 'create_new_folder',
    'folder_updated': 'folder',
    'folder_deleted': 'folder_delete'
  }
  return icons[eventType] || 'info'
}

function getEventColor(eventType) {
  const colors = {
    'file_created': 'text-green-400',
    'file_updated': 'text-blue-400',
    'file_deleted': 'text-red-400',
    'folder_created': 'text-green-400',
    'folder_updated': 'text-amber-400',
    'folder_deleted': 'text-red-400'
  }
  return colors[eventType] || 'text-surface-400'
}

function getEventAction(event) {
  const actions = {
    'file_created': 'created',
    'file_updated': event.new_version ? `updated to v${event.new_version}` : 'updated',
    'file_deleted': 'deleted',
    'folder_created': 'created',
    'folder_updated': 'updated',
    'folder_deleted': 'deleted'
  }
  return actions[event.event_type] || event.event_type
}

function formatRelativeTime(timestamp) {
  const now = Date.now()
  const eventTime = timestamp * 1000
  const diff = now - eventTime
  
  const minutes = Math.floor(diff / 60000)
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)
  
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes}m ago`
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  
  const date = new Date(eventTime)
  return date.toLocaleDateString('en-GB', { 
    day: 'numeric', 
    month: 'short'
  })
}

function formatFullTime(timestamp) {
  const date = new Date(timestamp * 1000)
  return date.toLocaleString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}
</script>

<template>
  <Teleport to="body">
    <Transition name="slide-right">
      <div 
        v-if="show" 
        class="fixed inset-y-0 right-0 z-50 flex"
        @click.self="close"
      >
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/30" @click="close"></div>
        
        <!-- Panel -->
        <div class="relative ml-auto w-full min-w-[340px] max-w-md bg-surface-50 dark:bg-surface-900 shadow-xl flex flex-col h-full">
          <!-- Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-2xl text-surface-500 dark:text-surface-400">
                history
              </span>
              <div>
                <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                  Activity Log
                </h2>
                <p class="text-sm text-surface-500 dark:text-surface-400">
                  {{ total }} {{ total === 1 ? 'event' : 'events' }}
                </p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button 
                v-if="events.length > 0"
                @click="clearAll"
                class="px-3 py-1.5 text-xs font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors"
                title="Clear all"
              >
                Clear All
              </button>
              <button 
                @click="close"
                class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                <span class="material-symbols-rounded text-surface-500 dark:text-surface-400">close</span>
              </button>
            </div>
          </div>
          
          <!-- Content -->
          <div class="flex-1 overflow-y-auto">
            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center py-12">
              <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
            </div>
            
            <!-- Empty state -->
            <div v-else-if="isEmpty" class="flex flex-col items-center justify-center py-16 px-6 text-center">
              <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-4">
                inbox
              </span>
              <h3 class="text-lg font-medium text-surface-700 dark:text-surface-300 mb-2">
                No activity yet
              </h3>
              <p class="text-sm text-surface-500 dark:text-surface-400">
                File updates and changes will appear here
              </p>
            </div>
            
            <template v-else>
              <!-- Today -->
              <div v-if="groupedEvents.today.length > 0" class="border-b border-surface-200 dark:border-surface-700">
                <div class="px-6 py-3 bg-surface-100 dark:bg-surface-800/50 sticky top-0">
                  <h3 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider">
                    Today
                  </h3>
                </div>
                <div class="divide-y divide-surface-100 dark:divide-surface-800">
                  <div 
                    v-for="event in groupedEvents.today" 
                    :key="event.id"
                    class="group relative px-6 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/30 transition-colors"
                  >
                    <div class="flex items-start gap-3">
                      <span :class="['material-symbols-rounded text-xl mt-0.5', getEventColor(event.event_type)]">
                        {{ getEventIcon(event.event_type) }}
                      </span>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm text-surface-800 dark:text-surface-200 truncate font-medium">
                          {{ event.file_name || 'Unknown file' }}
                        </p>
                        <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">
                          {{ getEventAction(event) }}
                          <span v-if="event.modified_by"> by {{ event.modified_by }}</span>
                        </p>
                        <p class="text-xs text-surface-400 dark:text-surface-500 mt-1" :title="formatFullTime(event.timestamp)">
                          {{ formatRelativeTime(event.timestamp) }}
                        </p>
                      </div>
                      <button 
                        @click.stop="deleteEvent(event.id)"
                        :disabled="deleting.has(event.id)"
                        class="opacity-0 group-hover:opacity-100 p-1.5 text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-all"
                        title="Remove from log"
                      >
                        <span v-if="deleting.has(event.id)" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
                        <span v-else class="material-symbols-rounded text-lg">close</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Yesterday -->
              <div v-if="groupedEvents.yesterday.length > 0" class="border-b border-surface-200 dark:border-surface-700">
                <div class="px-6 py-3 bg-surface-100 dark:bg-surface-800/50 sticky top-0">
                  <h3 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider">
                    Yesterday
                  </h3>
                </div>
                <div class="divide-y divide-surface-100 dark:divide-surface-800">
                  <div 
                    v-for="event in groupedEvents.yesterday" 
                    :key="event.id"
                    class="group relative px-6 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/30 transition-colors"
                  >
                    <div class="flex items-start gap-3">
                      <span :class="['material-symbols-rounded text-xl mt-0.5', getEventColor(event.event_type)]">
                        {{ getEventIcon(event.event_type) }}
                      </span>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm text-surface-800 dark:text-surface-200 truncate font-medium">
                          {{ event.file_name || 'Unknown file' }}
                        </p>
                        <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">
                          {{ getEventAction(event) }}
                          <span v-if="event.modified_by"> by {{ event.modified_by }}</span>
                        </p>
                        <p class="text-xs text-surface-400 dark:text-surface-500 mt-1" :title="formatFullTime(event.timestamp)">
                          {{ formatRelativeTime(event.timestamp) }}
                        </p>
                      </div>
                      <button 
                        @click.stop="deleteEvent(event.id)"
                        :disabled="deleting.has(event.id)"
                        class="opacity-0 group-hover:opacity-100 p-1.5 text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-all"
                        title="Remove from log"
                      >
                        <span v-if="deleting.has(event.id)" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
                        <span v-else class="material-symbols-rounded text-lg">close</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- This Week -->
              <div v-if="groupedEvents.thisWeek.length > 0" class="border-b border-surface-200 dark:border-surface-700">
                <div class="px-6 py-3 bg-surface-100 dark:bg-surface-800/50 sticky top-0">
                  <h3 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider">
                    This Week
                  </h3>
                </div>
                <div class="divide-y divide-surface-100 dark:divide-surface-800">
                  <div 
                    v-for="event in groupedEvents.thisWeek" 
                    :key="event.id"
                    class="group relative px-6 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/30 transition-colors"
                  >
                    <div class="flex items-start gap-3">
                      <span :class="['material-symbols-rounded text-xl mt-0.5', getEventColor(event.event_type)]">
                        {{ getEventIcon(event.event_type) }}
                      </span>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm text-surface-800 dark:text-surface-200 truncate font-medium">
                          {{ event.file_name || 'Unknown file' }}
                        </p>
                        <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">
                          {{ getEventAction(event) }}
                          <span v-if="event.modified_by"> by {{ event.modified_by }}</span>
                        </p>
                        <p class="text-xs text-surface-400 dark:text-surface-500 mt-1" :title="formatFullTime(event.timestamp)">
                          {{ formatRelativeTime(event.timestamp) }}
                        </p>
                      </div>
                      <button 
                        @click.stop="deleteEvent(event.id)"
                        :disabled="deleting.has(event.id)"
                        class="opacity-0 group-hover:opacity-100 p-1.5 text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-all"
                        title="Remove from log"
                      >
                        <span v-if="deleting.has(event.id)" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
                        <span v-else class="material-symbols-rounded text-lg">close</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- This Month -->
              <div v-if="groupedEvents.thisMonth.length > 0" class="border-b border-surface-200 dark:border-surface-700">
                <div class="px-6 py-3 bg-surface-100 dark:bg-surface-800/50 sticky top-0">
                  <h3 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider">
                    This Month
                  </h3>
                </div>
                <div class="divide-y divide-surface-100 dark:divide-surface-800">
                  <div 
                    v-for="event in groupedEvents.thisMonth" 
                    :key="event.id"
                    class="group relative px-6 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/30 transition-colors"
                  >
                    <div class="flex items-start gap-3">
                      <span :class="['material-symbols-rounded text-xl mt-0.5', getEventColor(event.event_type)]">
                        {{ getEventIcon(event.event_type) }}
                      </span>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm text-surface-800 dark:text-surface-200 truncate font-medium">
                          {{ event.file_name || 'Unknown file' }}
                        </p>
                        <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">
                          {{ getEventAction(event) }}
                          <span v-if="event.modified_by"> by {{ event.modified_by }}</span>
                        </p>
                        <p class="text-xs text-surface-400 dark:text-surface-500 mt-1" :title="formatFullTime(event.timestamp)">
                          {{ formatRelativeTime(event.timestamp) }}
                        </p>
                      </div>
                      <button 
                        @click.stop="deleteEvent(event.id)"
                        :disabled="deleting.has(event.id)"
                        class="opacity-0 group-hover:opacity-100 p-1.5 text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-all"
                        title="Remove from log"
                      >
                        <span v-if="deleting.has(event.id)" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
                        <span v-else class="material-symbols-rounded text-lg">close</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Older -->
              <div v-if="groupedEvents.older.length > 0">
                <div class="px-6 py-3 bg-surface-100 dark:bg-surface-800/50 sticky top-0">
                  <h3 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider">
                    Older
                  </h3>
                </div>
                <div class="divide-y divide-surface-100 dark:divide-surface-800">
                  <div 
                    v-for="event in groupedEvents.older" 
                    :key="event.id"
                    class="group relative px-6 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/30 transition-colors"
                  >
                    <div class="flex items-start gap-3">
                      <span :class="['material-symbols-rounded text-xl mt-0.5', getEventColor(event.event_type)]">
                        {{ getEventIcon(event.event_type) }}
                      </span>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm text-surface-800 dark:text-surface-200 truncate font-medium">
                          {{ event.file_name || 'Unknown file' }}
                        </p>
                        <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">
                          {{ getEventAction(event) }}
                          <span v-if="event.modified_by"> by {{ event.modified_by }}</span>
                        </p>
                        <p class="text-xs text-surface-400 dark:text-surface-500 mt-1" :title="formatFullTime(event.timestamp)">
                          {{ formatRelativeTime(event.timestamp) }}
                        </p>
                      </div>
                      <button 
                        @click.stop="deleteEvent(event.id)"
                        :disabled="deleting.has(event.id)"
                        class="opacity-0 group-hover:opacity-100 p-1.5 text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-all"
                        title="Remove from log"
                      >
                        <span v-if="deleting.has(event.id)" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
                        <span v-else class="material-symbols-rounded text-lg">close</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Load more -->
              <div v-if="hasMore" class="p-4">
                <button 
                  @click="loadMore"
                  :disabled="loadingMore"
                  class="w-full px-4 py-2 text-sm font-medium text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg transition-colors disabled:opacity-50"
                >
                  <span v-if="loadingMore" class="flex items-center justify-center gap-2">
                    <span class="animate-spin material-symbols-rounded text-base">progress_activity</span>
                    Loading...
                  </span>
                  <span v-else>Load more</span>
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.slide-right-enter-active,
.slide-right-leave-active {
  transition: all 0.3s ease;
}

.slide-right-enter-active .relative,
.slide-right-leave-active .relative {
  transition: transform 0.3s ease;
}

.slide-right-enter-from,
.slide-right-leave-to {
  opacity: 0;
}

.slide-right-enter-from .relative,
.slide-right-leave-to .relative {
  transform: translateX(100%);
}
</style>

