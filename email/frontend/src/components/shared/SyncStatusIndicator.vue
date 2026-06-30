<script setup>
import { computed } from 'vue'
import { useMailSync, ConnectionState } from '@/services/mailSyncSocket'

const { connectionState, lastError } = useMailSync()

const statusClass = computed(() => {
  switch (connectionState.value) {
    case ConnectionState.CONNECTED:
      return 'text-green-500'
    case ConnectionState.CONNECTING:
    case ConnectionState.RECONNECTING:
      return 'text-yellow-500'
    case ConnectionState.DISCONNECTED:
      return 'text-neutral-400'
    default:
      return 'text-neutral-400'
  }
})

const statusIcon = computed(() => {
  switch (connectionState.value) {
    case ConnectionState.CONNECTED:
      return 'cloud_done'
    case ConnectionState.CONNECTING:
    case ConnectionState.RECONNECTING:
      return 'cloud_sync'
    case ConnectionState.DISCONNECTED:
      return 'cloud_off'
    default:
      return 'cloud_off'
  }
})

const statusTitle = computed(() => {
  switch (connectionState.value) {
    case ConnectionState.CONNECTED:
      return 'Real-time sync active'
    case ConnectionState.CONNECTING:
      return 'Connecting to sync server...'
    case ConnectionState.RECONNECTING:
      return 'Reconnecting to sync server...'
    case ConnectionState.DISCONNECTED:
      return lastError.value || 'Disconnected from sync server'
    default:
      return 'Sync status unknown'
  }
})

const isAnimating = computed(() => {
  return connectionState.value === ConnectionState.CONNECTING || 
         connectionState.value === ConnectionState.RECONNECTING
})
</script>

<template>
  <div 
    class="flex items-center gap-1 px-2 py-1 rounded-full text-xs cursor-help transition-all"
    :class="[
      statusClass,
      isAnimating ? 'animate-pulse' : ''
    ]"
    :title="statusTitle"
  >
    <span class="material-symbols-rounded text-sm">{{ statusIcon }}</span>
    <span class="hidden sm:inline opacity-75">
      {{ connectionState === ConnectionState.CONNECTED ? 'Live' : connectionState }}
    </span>
  </div>
</template>

