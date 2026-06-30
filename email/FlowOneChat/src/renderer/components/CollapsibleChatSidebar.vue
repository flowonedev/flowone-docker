<script setup>
import { ref, computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import OriginalChatSidebar from '@original-chat-sidebar'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const emit = defineEmits(['new-chat', 'select-conversation'])
const chatStore = useChatStore()

const collapsed = ref(false)

const conversations = computed(() => {
  if (!chatStore.conversations) return []
  return [...chatStore.conversations].sort((a, b) => {
    if (a.is_pinned && !b.is_pinned) return -1
    if (!a.is_pinned && b.is_pinned) return 1
    return new Date(b.last_message_at || 0) - new Date(a.last_message_at || 0)
  })
})

function toggleCollapse() {
  collapsed.value = !collapsed.value
}

function selectConversation(conv) {
  emit('select-conversation', conv.id)
}

function getConversationName(conv) {
  if (conv.type === 'channel') {
    return conv.slug ? `#${conv.slug}` : (conv.name || 'Channel')
  }
  if (conv.type === 'group') {
    return conv.name || 'Group'
  }
  const participant = conv.participants?.[0]
  if (!participant) return '?'
  return participant.display_name || participant.email?.split('@')[0] || '?'
}

function getInitials(name) {
  if (!name || name === '?') return '?'
  return name.replace(/^#/, '').charAt(0).toUpperCase()
}
</script>

<template>
  <!-- Collapsed mini sidebar -->
  <aside
    v-if="collapsed"
    class="w-[60px] border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex flex-col overflow-hidden flex-shrink-0"
  >
    <!-- Toggle button -->
    <div class="flex items-center justify-center py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <button
        @click="toggleCollapse"
        class="w-9 h-9 flex items-center justify-center text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
        title="Expand sidebar"
      >
        <span class="material-symbols-rounded text-lg">chevron_right</span>
      </button>
    </div>

    <!-- New chat button -->
    <div class="flex items-center justify-center py-2">
      <button
        @click="emit('new-chat')"
        class="w-10 h-10 flex items-center justify-center bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors"
        title="New Message"
      >
        <span class="material-symbols-rounded text-lg">edit_square</span>
      </button>
    </div>

    <!-- Conversation avatars -->
    <div class="flex-1 overflow-y-auto py-1">
      <div
        v-for="conv in conversations"
        :key="conv.id"
        @click="selectConversation(conv)"
        class="relative flex items-center justify-center py-1.5 cursor-pointer group"
        :title="getConversationName(conv)"
      >
        <!-- Channel icon -->
        <div
          v-if="conv.type === 'channel'"
          :class="[
            'w-10 h-10 rounded-full flex items-center justify-center transition-colors',
            chatStore.activeConversationId === conv.id
              ? 'bg-primary-500 text-white'
              : conv.is_public
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-500 group-hover:bg-primary-200 dark:group-hover:bg-primary-500/30'
                : 'bg-amber-100 dark:bg-amber-500/20 text-amber-500 group-hover:bg-amber-200 dark:group-hover:bg-amber-500/30'
          ]"
        >
          <span class="material-symbols-rounded text-xl">{{ conv.is_public ? 'tag' : 'lock' }}</span>
        </div>

        <!-- Group icon -->
        <div
          v-else-if="conv.type === 'group'"
          :class="[
            'w-10 h-10 rounded-full flex items-center justify-center transition-colors',
            chatStore.activeConversationId === conv.id
              ? 'bg-primary-500 text-white'
              : 'bg-primary-100 dark:bg-primary-500/20 text-primary-500 group-hover:bg-primary-200 dark:group-hover:bg-primary-500/30'
          ]"
        >
          <span class="material-symbols-rounded text-xl">group</span>
        </div>

        <!-- DM avatar -->
        <template v-else>
          <div :class="chatStore.activeConversationId === conv.id ? 'ring-2 ring-primary-500 rounded-full' : ''">
            <UserAvatar
              :email="conv.participants?.[0]?.email || ''"
              :name="conv.participants?.[0]?.display_name || ''"
              :avatar-path="conv.participants?.[0]?.avatar_path || ''"
              size="lg"
              :show-presence="true"
            />
          </div>
        </template>

        <!-- Unread badge -->
        <span
          v-if="conv.unread_count > 0"
          class="absolute top-0.5 right-1 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"
        >
          {{ conv.unread_count > 99 ? '99+' : conv.unread_count }}
        </span>
      </div>
    </div>
  </aside>

  <!-- Expanded original sidebar with toggle -->
  <div v-else class="relative flex flex-shrink-0">
    <OriginalChatSidebar
      class="w-80"
      @new-chat="emit('new-chat')"
      @select-conversation="(id) => emit('select-conversation', id)"
    />
    <!-- Collapse button overlaid at top-left of sidebar -->
    <button
      @click="toggleCollapse"
      class="absolute top-3 right-2 z-10 w-7 h-7 flex items-center justify-center text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-full transition-colors"
      title="Collapse sidebar"
    >
      <span class="material-symbols-rounded text-base">chevron_left</span>
    </button>
  </div>
</template>
