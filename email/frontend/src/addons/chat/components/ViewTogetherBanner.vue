<script setup>
import { computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()

const emit = defineEmits(['end-session', 'follow-position'])

// Get current user to filter out from display
const currentColleague = computed(() => colleaguesStore.currentColleague)

// Get other participant info
const otherParticipant = computed(() => {
  return chatStore.activeConversation?.participants?.[0] || null
})

// Get other participant name
const otherName = computed(() => {
  const pos = chatStore.otherParticipantPosition
  if (pos?.user?.name) return pos.user.name
  const cursor = chatStore.otherParticipantCursor
  if (cursor?.user?.name) return cursor.user.name
  return otherParticipant.value?.display_name || 'Participant'
})

// Check if other participant is viewing
const otherIsViewing = computed(() => {
  return chatStore.otherParticipantPosition !== null || chatStore.otherParticipantCursor !== null
})

// Get what the other person is looking at
const otherViewingInfo = computed(() => {
  const pos = chatStore.otherParticipantPosition
  if (!pos) return null
  
  const { position, user } = pos
  if (!position) return null
  
  if (position.type === 'image') {
    return `Image ${position.index + 1}`
  } else if (position.type === 'document') {
    return `Page ${position.page}`
  } else if (position.type === 'spreadsheet') {
    return `Cell ${position.cell}`
  }
  return 'Viewing'
})

function endSession() {
  emit('end-session')
}

function toggleFollow() {
  const newState = chatStore.toggleFollowMode()
  if (newState && chatStore.otherParticipantPosition) {
    // Immediately follow to current position
    emit('follow-position', chatStore.otherParticipantPosition)
  }
}

function toggleSyncScroll() {
  chatStore.toggleSyncScrollMode()
}

function getInitials(name) {
  if (!name) return '?'
  return name.substring(0, 2).toUpperCase()
}
</script>

<template>
  <div 
    v-if="chatStore.viewSession"
    class="fixed top-20 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 px-4 py-2 bg-primary-500 text-white rounded-full shadow-lg"
  >
    <span class="material-symbols-rounded text-lg animate-pulse">screen_share</span>
    
    <span class="text-sm font-medium">View Together</span>
    
    <!-- Presenter badge -->
    <span 
      v-if="chatStore.isPresenter" 
      class="text-xs bg-white/20 px-2 py-0.5 rounded-full"
    >
      Presenter
    </span>
    
    <!-- Other participant status -->
    <div class="flex items-center gap-2 pl-2 border-l border-white/30">
      <div 
        :class="[
          'w-6 h-6 rounded-full flex items-center justify-center text-xs font-medium',
          otherIsViewing ? 'bg-white text-primary-500' : 'bg-white/30 text-white'
        ]"
        :title="otherName"
      >
        {{ getInitials(otherName) }}
      </div>
      <span v-if="otherIsViewing" class="text-xs">
        {{ otherViewingInfo || 'Active' }}
      </span>
      <span v-else class="text-xs opacity-70">
        Not viewing
      </span>
    </div>
    
    <!-- Sync Scroll toggle (only for presenter) -->
    <button
      v-if="chatStore.isPresenter"
      @click="toggleSyncScroll"
      :class="[
        'flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-colors',
        chatStore.syncScrollMode 
          ? 'bg-amber-400 text-amber-900' 
          : 'bg-white/20 hover:bg-white/30'
      ]"
      :title="chatStore.syncScrollMode ? 'Stop sync scroll' : 'Enable sync scroll - others will follow your scroll'"
    >
      <span class="material-symbols-rounded text-sm">
        {{ chatStore.syncScrollMode ? 'sync_lock' : 'sync' }}
      </span>
      <span>Sync Scroll</span>
    </button>
    
    <!-- Follow toggle (only for non-presenter when viewing) -->
    <button
      v-if="!chatStore.isPresenter && otherIsViewing && !chatStore.syncScrollMode"
      @click="toggleFollow"
      :class="[
        'flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-colors',
        chatStore.followMode 
          ? 'bg-white text-primary-500' 
          : 'bg-white/20 hover:bg-white/30'
      ]"
      :title="chatStore.followMode ? 'Stop following' : 'Follow their view'"
    >
      <span class="material-symbols-rounded text-sm">
        {{ chatStore.followMode ? 'link' : 'link_off' }}
      </span>
      <span>Follow {{ otherName.split(' ')[0] }}</span>
    </button>
    
    <!-- Sync scroll active indicator (for non-presenter) -->
    <div 
      v-if="!chatStore.isPresenter && chatStore.syncScrollMode"
      class="flex items-center gap-1.5 px-3 py-1 bg-amber-400 text-amber-900 rounded-full text-xs font-medium"
    >
      <span class="material-symbols-rounded text-sm">sync_lock</span>
      <span>Synced to {{ otherName.split(' ')[0] }}</span>
    </div>
    
    <!-- End session button -->
    <button
      @click="endSession"
      class="ml-2 p-1 hover:bg-white/20 rounded-full transition-colors"
      title="End View Together"
    >
      <span class="material-symbols-rounded text-lg">close</span>
    </button>
  </div>
</template>
