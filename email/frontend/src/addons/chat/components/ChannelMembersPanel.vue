<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useHuddleStore } from '@/stores/huddle'
import { useChatPresence } from '@/composables/useChatPresence'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import api from '@/services/api'

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()
const huddleStore = useHuddleStore()
const { getStatusColor, getStatusText } = useChatPresence()

const members = ref([])
const loading = ref(false)
const searchQuery = ref('')

const conversationId = computed(() => chatStore.activeConversationId)
const conversation = computed(() => chatStore.activeConversation)

const isChannelOrGroup = computed(() => {
  const type = conversation.value?.type
  return type === 'channel' || type === 'group'
})

async function fetchMembers() {
  if (!conversationId.value || !isChannelOrGroup.value) return
  loading.value = true
  try {
    const type = conversation.value?.type
    const endpoint = type === 'channel'
      ? `/chat/channels/${conversationId.value}/members`
      : `/chat/groups/${conversationId.value}/members`
    const response = await api.get(endpoint)
    if (response.data.success) {
      members.value = response.data.data?.members || []
    }
  } catch (e) {
    console.error('Failed to fetch members:', e)
  } finally {
    loading.value = false
  }
}

watch(conversationId, () => {
  if (chatStore.showMembersPanel) fetchMembers()
})

onMounted(() => {
  fetchMembers()
})

const onlineMembers = computed(() => {
  return filteredMembers.value.filter(m => {
    const status = colleaguesStore.getColleagueStatus(m.email)
    return status === 'active' || status === 'away' || status === 'do_not_disturb' || status === 'dnd'
  })
})

const offlineMembers = computed(() => {
  return filteredMembers.value.filter(m => {
    const status = colleaguesStore.getColleagueStatus(m.email)
    return !status || status === 'offline'
  })
})

const filteredMembers = computed(() => {
  if (!searchQuery.value) return members.value
  const q = searchQuery.value.toLowerCase()
  return members.value.filter(m =>
    m.display_name?.toLowerCase().includes(q) ||
    m.email?.toLowerCase().includes(q)
  )
})

function isSpeaking(email) {
  return huddleStore.speakingParticipants?.has?.(email)
}

function isInHuddle(email) {
  if (!conversationId.value) return false
  const huddle = huddleStore.conversationActiveHuddles?.[conversationId.value]
  if (!huddle?.participants) return false
  return huddle.participants.some(p => p.email === email)
}

function openDm(member) {
  chatStore.openDMWith(member.email)
  chatStore.showMembersPanel = false
}
</script>

<template>
  <aside
    v-if="isChannelOrGroup"
    class="w-60 lg:w-64 border-l border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex flex-col overflow-hidden"
  >
    <!-- Header -->
    <div class="p-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Members</h3>
        <button
          @click="chatStore.toggleMembersPanel()"
          class="w-7 h-7 flex items-center justify-center text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
        >
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>
      <div class="relative">
        <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-surface-400 text-sm">search</span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Find members..."
          class="w-full pl-8 pr-3 py-1.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-xs focus:ring-1 focus:ring-primary-500 outline-none"
        />
      </div>
    </div>

    <!-- Members list -->
    <div class="flex-1 overflow-y-auto">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-6">
        <span class="material-symbols-rounded text-xl text-surface-400 animate-spin">progress_activity</span>
      </div>

      <template v-else>
        <!-- Online -->
        <div v-if="onlineMembers.length > 0" class="pt-3">
          <div class="px-3 pb-1.5">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
              Online -- {{ onlineMembers.length }}
            </span>
          </div>
          <div
            v-for="member in onlineMembers"
            :key="member.id"
            @click="openDm(member)"
            class="flex items-center gap-2.5 px-3 py-1.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors"
          >
            <div class="relative flex-shrink-0">
              <div
                :class="[
                  'rounded-full transition-all',
                  isSpeaking(member.email) ? 'ring-2 ring-green-500 ring-offset-1 dark:ring-offset-[rgb(var(--color-surface))]' : ''
                ]"
              >
                <UserAvatar
                  :colleague="member"
                  :email="member.email"
                  :name="member.display_name"
                  :avatar-path="member.avatar || ''"
                  size="sm"
                />
              </div>
              <span
                :class="[
                  'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white dark:border-[rgb(var(--color-surface))]',
                  getStatusColor(member)
                ]"
              ></span>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5">
                <span class="text-sm text-surface-800 dark:text-surface-200 truncate">{{ member.display_name || member.email.split('@')[0] }}</span>
                <span v-if="member.is_admin" class="material-symbols-rounded text-xs text-amber-500" title="Admin">shield</span>
                <span v-if="isInHuddle(member.email)" class="material-symbols-rounded text-xs text-green-500" title="In huddle">headset_mic</span>
              </div>
              <p v-if="member.job_title" class="text-[11px] text-surface-400 truncate">{{ member.job_title }}</p>
            </div>
          </div>
        </div>

        <!-- Offline -->
        <div v-if="offlineMembers.length > 0" class="pt-3">
          <div class="px-3 pb-1.5">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
              Offline -- {{ offlineMembers.length }}
            </span>
          </div>
          <div
            v-for="member in offlineMembers"
            :key="member.id"
            @click="openDm(member)"
            class="flex items-center gap-2.5 px-3 py-1.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors opacity-60"
          >
            <div class="relative flex-shrink-0">
              <UserAvatar
                :colleague="member"
                :email="member.email"
                :name="member.display_name"
                :avatar-path="member.avatar || ''"
                size="sm"
              />
              <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white dark:border-[rgb(var(--color-surface))] bg-surface-400"></span>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5">
                <span class="text-sm text-surface-600 dark:text-surface-400 truncate">{{ member.display_name || member.email.split('@')[0] }}</span>
                <span v-if="member.is_admin" class="material-symbols-rounded text-xs text-amber-500" title="Admin">shield</span>
              </div>
              <p v-if="member.job_title" class="text-[11px] text-surface-400 truncate">{{ member.job_title }}</p>
            </div>
          </div>
        </div>

        <!-- No results -->
        <div v-if="filteredMembers.length === 0 && !loading" class="flex flex-col items-center py-8 px-4 text-center">
          <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600 mb-2">person_search</span>
          <p class="text-sm text-surface-400">{{ searchQuery ? 'No members match' : 'No members' }}</p>
        </div>
      </template>
    </div>
  </aside>
</template>
