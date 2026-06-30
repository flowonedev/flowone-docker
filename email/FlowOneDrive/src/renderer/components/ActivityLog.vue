<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

interface ActivityItem {
  id: number
  type: 'upload' | 'download' | 'delete' | 'rename' | 'move' | 'create' | 'sync'
  filename: string
  folder?: string
  timestamp: string
  status: 'success' | 'error' | 'pending'
  message?: string
}

const activities = ref<ActivityItem[]>([])
const isLoading = ref(true)
let refreshInterval: NodeJS.Timeout | null = null
let pushUnsubscribe: (() => void) | null = null

onMounted(async () => {
  // Wave C.1: subscribe to push events; keep a 30 s heartbeat fallback to
  // recover from missed events (window reload, etc).
  await loadActivities()

  if ((window.api as any)?.onActivityUpdate) {
    pushUnsubscribe = (window.api as any).onActivityUpdate((activity: any) => {
      if (!activity) return
      const mapped: ActivityItem = {
        id: Date.now(),
        type: (activity.action || activity.type || 'sync') as ActivityItem['type'],
        filename: activity.name || activity.filename || '',
        timestamp: activity.at || new Date().toISOString(),
        status: 'success',
      }
      activities.value = [mapped, ...activities.value].slice(0, 200)
    })
  }

  refreshInterval = setInterval(loadActivities, 30_000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
  if (pushUnsubscribe) {
    pushUnsubscribe()
    pushUnsubscribe = null
  }
})

async function loadActivities() {
  try {
    const result = await window.api.getActivityLog()
    activities.value = result.activities || []
  } catch (e) {
    console.error('Failed to load activities:', e)
  } finally {
    isLoading.value = false
  }
}

function getActivityIcon(type: string): string {
  switch (type) {
    case 'upload': return 'cloud_upload'
    case 'download': return 'cloud_download'
    case 'delete': return 'delete'
    case 'rename': return 'edit'
    case 'move': return 'drive_file_move'
    case 'create': return 'create_new_folder'
    case 'sync': return 'sync'
    default: return 'description'
  }
}

function getActivityColor(status: string): string {
  switch (status) {
    case 'success': return '#22c55e'
    case 'error': return '#ef4444'
    case 'pending': return '#f59e0b'
    default: return '#6b7280'
  }
}

function formatTime(timestamp: string): string {
  const date = new Date(timestamp)
  const now = new Date()
  const diff = now.getTime() - date.getTime()
  const minutes = Math.floor(diff / 60000)
  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)
  
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes}m ago`
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return date.toLocaleDateString()
}

function getActivityDescription(activity: ActivityItem): string {
  switch (activity.type) {
    case 'upload': return `Uploaded ${activity.filename}`
    case 'download': return `Downloaded ${activity.filename}`
    case 'delete': return `Deleted ${activity.filename}`
    case 'rename': return `Renamed ${activity.filename}`
    case 'move': return `Moved ${activity.filename}`
    case 'create': return `Created folder ${activity.filename}`
    case 'sync': return `Synced ${activity.filename}`
    default: return activity.filename
  }
}
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden" style="background: var(--bg-main);">
    <!-- Header -->
    <div style="height: 56px; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 20px; background: var(--bg-main);">
      <span class="material-symbols-rounded" style="font-size: 20px; color: #22c55e; margin-right: 10px;">history</span>
      <h2 style="color: var(--text-primary); font-size: 16px; font-weight: 500;">Activity Log</h2>
    </div>
    
    <!-- Activity list -->
    <div style="flex: 1; overflow-y: auto; padding: 16px;">
      <div v-if="isLoading" style="display: flex; align-items: center; justify-content: center; height: 150px;">
        <span class="material-symbols-rounded animate-spin" style="font-size: 32px; color: #22c55e;">sync</span>
      </div>
      
      <div v-else-if="activities.length === 0" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 200px; text-align: center;">
        <span class="material-symbols-rounded" style="font-size: 48px; color: var(--bg-elevated-hover); margin-bottom: 12px;">history</span>
        <p style="color: var(--text-dim); font-size: 14px;">No recent activity</p>
        <p style="color: var(--text-faint); font-size: 12px; margin-top: 4px;">Your file sync activity will appear here</p>
      </div>
      
      <div v-else style="display: flex; flex-direction: column; gap: 8px;">
        <div
          v-for="activity in activities"
          :key="activity.id"
          style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; background: var(--bg-card); border-radius: 10px; border: 1px solid var(--border);"
        >
          <!-- Icon -->
          <div 
            :style="{ 
              width: '36px', 
              height: '36px', 
              borderRadius: '8px', 
              background: `${getActivityColor(activity.status)}15`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center'
            }"
          >
            <span 
              class="material-symbols-rounded" 
              :style="{ fontSize: '20px', color: getActivityColor(activity.status) }"
            >
              {{ getActivityIcon(activity.type) }}
            </span>
          </div>
          
          <!-- Content -->
          <div style="flex: 1; min-width: 0;">
            <p style="color: var(--text-primary); font-size: 13px; margin-bottom: 2px;">
              {{ getActivityDescription(activity) }}
            </p>
            <p v-if="activity.folder" style="color: var(--text-dim); font-size: 12px;">
              in {{ activity.folder }}
            </p>
            <p v-if="activity.message" :style="{ color: getActivityColor(activity.status), fontSize: '12px', marginTop: '4px' }">
              {{ activity.message }}
            </p>
          </div>
          
          <!-- Time -->
          <span style="color: var(--text-dim); font-size: 11px; white-space: nowrap;">
            {{ formatTime(activity.timestamp) }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

