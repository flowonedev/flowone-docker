<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useHuddleStore } from '@/stores/huddle'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useChatPresence } from '@/composables/useChatPresence'
import api from '@/services/api'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const props = defineProps({
  conversationId: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['start-call', 'join-huddle', 'leave-huddle'])

const huddleStore = useHuddleStore()
const colleaguesStore = useColleaguesStore()
const { getStatusColor } = useChatPresence()

// Local state for viewing huddle status (when not yet joined)
const remoteHuddle = ref(null)
let pollInterval = null

// Use store state when in huddle, remote state when viewing
const activeHuddle = computed(() => {
  if (huddleStore.isInHuddle && huddleStore.conversationId === props.conversationId) {
    return huddleStore.huddle
  }
  return remoteHuddle.value
})

const isMyHuddle = computed(() => {
  return huddleStore.isInHuddle && huddleStore.conversationId === props.conversationId
})

const participants = computed(() => {
  const h = activeHuddle.value
  if (!h?.participants) return []
  return h.participants.map(p => {
    const colleague = colleaguesStore.colleagueById?.[p.colleague_id]
    return {
      id: p.colleague_id,
      display_name: colleague?.display_name || p.display_name || 'Unknown',
      email: colleague?.email || p.email || '',
      avatar: colleague?.avatar_path || p.avatar_path || null,
      is_muted: p.is_muted,
      is_deafened: p.is_deafened,
    }
  })
})

const participantCount = computed(() => participants.value.length)

// Fetch remote huddle state (when not in huddle or viewing another conversation's huddle)
async function fetchRemoteHuddleState() {
  if (isMyHuddle.value) return // Store handles state when we're in it
  try {
    const response = await api.get(`/chat/huddles/active/${props.conversationId}`)
    if (response.data.success) {
      const h = response.data.data?.huddle
      if (h && h.is_active) {
        remoteHuddle.value = h
      } else {
        remoteHuddle.value = null
      }
    }
  } catch (e) {
    // Silent fail
  }
}

async function handleStartHuddle() {
  await huddleStore.startHuddle(props.conversationId)
  emit('start-call', { type: 'huddle', conversationId: props.conversationId })
}

async function handleJoinHuddle() {
  const h = remoteHuddle.value || activeHuddle.value
  if (!h?.id) return
  await huddleStore.joinHuddle(h.id, props.conversationId)
  emit('join-huddle', { conversationId: props.conversationId })
}

async function handleLeaveHuddle() {
  await huddleStore.leaveHuddle()
  emit('leave-huddle', { conversationId: props.conversationId })
}

function handleToggleMute() {
  huddleStore.toggleMute()
}

function handleToggleDeafen() {
  huddleStore.toggleDeafen()
}

// Watch for conversation change
watch(() => props.conversationId, () => {
  remoteHuddle.value = null
  fetchRemoteHuddleState()
})

function startPolling() {
  pollInterval = setInterval(fetchRemoteHuddleState, 5000)
}

function stopPolling() {
  if (pollInterval) {
    clearInterval(pollInterval)
    pollInterval = null
  }
}

onMounted(() => {
  fetchRemoteHuddleState()
  startPolling()
})

onUnmounted(() => {
  stopPolling()
})

function getInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
}

defineExpose({ startHuddle: handleStartHuddle })
</script>

<template>
  <!-- Active huddle bar (shown when a huddle is active in this conversation) -->
  <div v-if="activeHuddle" class="mx-3 mb-2">
    <div class="bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30 rounded-xl px-4 py-3">
      <!-- Huddle active, user not in it -->
      <div v-if="!isMyHuddle && activeHuddle" class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="relative">
            <span class="material-symbols-rounded text-xl text-green-600 dark:text-green-400 animate-pulse">headset_mic</span>
          </div>
          <div>
            <p class="text-sm font-medium text-green-800 dark:text-green-300">Huddle in progress</p>
            <div class="flex items-center gap-1 mt-0.5">
              <div class="flex -space-x-1.5">
                <div
                  v-for="(p, i) in participants.slice(0, 4)"
                  :key="p.id"
                  :class="[
                    'rounded-full transition-all',
                    huddleStore.speakingParticipants?.has?.(p.email?.toLowerCase()) ? 'ring-2 ring-green-400 ring-offset-1 dark:ring-offset-green-900' : ''
                  ]"
                >
                  <UserAvatar
                    :colleague="p"
                    :avatar-path="p.avatar"
                    size="xs"
                    class="border border-green-50 dark:border-green-900"
                    :title="p.display_name"
                  />
                </div>
              </div>
              <span class="text-xs text-green-600 dark:text-green-400 ml-1">
                {{ participantCount }} {{ participantCount === 1 ? 'person' : 'people' }}
              </span>
            </div>
          </div>
        </div>
        <button
          @click="handleJoinHuddle"
          :disabled="huddleStore.loading"
          class="px-4 py-1.5 text-sm font-medium bg-green-500 text-white rounded-full hover:bg-green-600 transition-colors disabled:opacity-50"
        >
          {{ huddleStore.loading ? 'Joining...' : 'Join' }}
        </button>
      </div>

      <!-- User is in the huddle -->
      <div v-else-if="isMyHuddle" class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-xl text-green-600 dark:text-green-400">headset_mic</span>
          <div>
            <div class="flex items-center gap-2">
              <p class="text-sm font-medium text-green-800 dark:text-green-300">In huddle</p>
              <span v-if="huddleStore.elapsedTime" class="text-xs text-green-600 dark:text-green-400 font-mono">{{ huddleStore.elapsedTime }}</span>
            </div>
            <div class="flex items-center gap-1 mt-0.5">
              <div class="flex -space-x-1.5">
                <div
                  v-for="(p, i) in participants.slice(0, 4)"
                  :key="p.id"
                  :class="[
                    'rounded-full transition-all',
                    huddleStore.speakingParticipants?.has?.(p.email?.toLowerCase()) ? 'ring-2 ring-green-400 ring-offset-1 dark:ring-offset-green-900' : ''
                  ]"
                >
                  <UserAvatar
                    :colleague="p"
                    :avatar-path="p.avatar"
                    size="xs"
                    class="border border-green-50 dark:border-green-900"
                    :title="p.display_name"
                  />
                </div>
              </div>
              <span v-if="participantCount > 4" class="text-xs text-green-600 dark:text-green-400 ml-1">
                +{{ participantCount - 4 }}
              </span>
            </div>
          </div>
        </div>

        <!-- Controls -->
        <div class="flex items-center gap-1">
          <button
            @click="handleToggleMute"
            :class="[
              'w-8 h-8 rounded-full flex items-center justify-center transition-colors',
              huddleStore.isMuted 
                ? 'bg-red-100 dark:bg-red-500/20 text-red-500' 
                : 'bg-green-200 dark:bg-green-700 text-green-700 dark:text-green-300 hover:bg-green-300 dark:hover:bg-green-600'
            ]"
            :title="huddleStore.isMuted ? 'Unmute' : 'Mute'"
          >
            <span class="material-symbols-rounded text-lg">{{ huddleStore.isMuted ? 'mic_off' : 'mic' }}</span>
          </button>
          <button
            @click="handleToggleDeafen"
            :class="[
              'w-8 h-8 rounded-full flex items-center justify-center transition-colors',
              huddleStore.isDeafened 
                ? 'bg-red-100 dark:bg-red-500/20 text-red-500' 
                : 'bg-green-200 dark:bg-green-700 text-green-700 dark:text-green-300 hover:bg-green-300 dark:hover:bg-green-600'
            ]"
            :title="huddleStore.isDeafened ? 'Undeafen' : 'Deafen'"
          >
            <span class="material-symbols-rounded text-lg">{{ huddleStore.isDeafened ? 'headset_off' : 'headset' }}</span>
          </button>
          <button
            @click="handleLeaveHuddle"
            class="w-8 h-8 rounded-full bg-red-500 text-white flex items-center justify-center hover:bg-red-600 transition-colors ml-1"
            title="Leave huddle"
          >
            <span class="material-symbols-rounded text-lg">call_end</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Start huddle button (shown when no active huddle) -->
  <div v-else class="hidden">
    <!-- The start button is typically in the conversation header, not here -->
    <!-- This component only renders the active huddle bar -->
  </div>
</template>
