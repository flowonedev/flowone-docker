<script setup>
import { ref, computed, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'

const emit = defineEmits(['close', 'create-channel', 'select-channel'])

const chatStore = useChatStore()
const toast = useToastStore()

const search = ref('')
const loading = ref(false)
const channels = ref([])
const joiningId = ref(null)

const filteredChannels = computed(() => {
  if (!search.value) return channels.value
  const q = search.value.toLowerCase()
  return channels.value.filter(ch =>
    ch.name?.toLowerCase().includes(q) ||
    ch.slug?.toLowerCase().includes(q) ||
    ch.topic?.toLowerCase().includes(q)
  )
})

const joinedChannels = computed(() => filteredChannels.value.filter(ch => ch.is_member))
const availableChannels = computed(() => filteredChannels.value.filter(ch => !ch.is_member))

async function loadChannels() {
  loading.value = true
  try {
    const result = await chatStore.browseChannels(search.value)
    if (result.success) {
      channels.value = result.channels || []
    }
  } finally {
    loading.value = false
  }
}

async function joinChannel(channelId) {
  joiningId.value = channelId
  try {
    const result = await chatStore.joinChannel(channelId)
    if (result.success) {
      toast.success('Joined channel')
      await loadChannels()
      // Navigate to the channel
      chatStore.setActiveConversation(channelId)
      await chatStore.fetchConversations()
      emit('select-channel', channelId)
    } else {
      toast.error(result.error || 'Failed to join channel')
    }
  } finally {
    joiningId.value = null
  }
}

function openChannel(channelId) {
  chatStore.setActiveConversation(channelId)
  emit('select-channel', channelId)
}

onMounted(loadChannels)
</script>

<template>
  <div class="flex flex-col h-full bg-white dark:bg-[rgb(var(--color-surface))]">
    <!-- Header -->
    <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Browse Channels</h2>
      <button
        @click="emit('close')"
        class="w-9 h-9 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
      >
        <span class="material-symbols-rounded text-xl text-surface-500">close</span>
      </button>
    </div>

    <!-- Search + Create -->
    <div class="p-4 space-y-3">
      <div class="relative">
        <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
        <input
          v-model="search"
          @input="loadChannels"
          type="text"
          placeholder="Search channels..."
          class="w-full pl-10 pr-4 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-full text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
        />
      </div>
      <button
        @click="emit('create-channel')"
        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors"
      >
        <span class="material-symbols-rounded text-lg">add</span>
        Create Channel
      </button>
    </div>

    <!-- Channel lists -->
    <div class="flex-1 overflow-y-auto px-4 pb-4">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-8">
        <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
      </div>

      <template v-else>
        <!-- Your channels -->
        <div v-if="joinedChannels.length > 0" class="mb-6">
          <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">Your Channels</h3>
          <div class="space-y-1">
            <button
              v-for="ch in joinedChannels"
              :key="ch.id"
              @click="openChannel(ch.id)"
              class="w-full flex items-center gap-3 p-3 rounded-xl hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors text-left"
            >
              <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
                :class="ch.is_public ? 'bg-primary-100 dark:bg-primary-500/20' : 'bg-amber-100 dark:bg-amber-500/20'"
              >
                <span class="material-symbols-rounded text-xl" :class="ch.is_public ? 'text-primary-500' : 'text-amber-500'">
                  {{ ch.is_public ? 'tag' : 'lock' }}
                </span>
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="font-medium text-surface-900 dark:text-surface-100 truncate">#{{ ch.slug || ch.name }}</span>
                  <span v-if="ch.is_default" class="px-1.5 py-0.5 bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 text-[10px] font-medium rounded-full">DEFAULT</span>
                </div>
                <p v-if="ch.topic" class="text-xs text-surface-500 truncate mt-0.5">{{ ch.topic }}</p>
                <p class="text-xs text-surface-400 mt-0.5">{{ ch.member_count }} members</p>
              </div>
            </button>
          </div>
        </div>

        <!-- Available to join -->
        <div v-if="availableChannels.length > 0">
          <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">Available Channels</h3>
          <div class="space-y-1">
            <div
              v-for="ch in availableChannels"
              :key="ch.id"
              class="flex items-center gap-3 p-3 rounded-xl hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors"
            >
              <div class="w-10 h-10 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-rounded text-xl text-surface-400">tag</span>
              </div>
              <div class="flex-1 min-w-0">
                <span class="font-medium text-surface-900 dark:text-surface-100 truncate">#{{ ch.slug || ch.name }}</span>
                <p v-if="ch.topic" class="text-xs text-surface-500 truncate mt-0.5">{{ ch.topic }}</p>
                <p class="text-xs text-surface-400 mt-0.5">{{ ch.member_count }} members</p>
              </div>
              <button
                @click="joinChannel(ch.id)"
                :disabled="joiningId === ch.id"
                class="px-4 py-1.5 bg-primary-500 hover:bg-primary-600 disabled:bg-surface-300 text-white text-xs font-medium rounded-full transition-colors flex-shrink-0"
              >
                {{ joiningId === ch.id ? 'Joining...' : 'Join' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Empty -->
        <div v-if="channels.length === 0" class="text-center py-12">
          <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">tag</span>
          <p class="text-surface-500 mb-4">No channels yet</p>
          <button
            @click="emit('create-channel')"
            class="text-primary-500 text-sm font-medium hover:underline"
          >
            Create the first channel
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

