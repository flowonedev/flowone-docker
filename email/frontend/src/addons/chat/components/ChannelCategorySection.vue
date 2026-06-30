<script setup>
import { ref, computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'
import { useHuddleStore } from '@/stores/huddle'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import SidebarHuddlePreview from './SidebarHuddlePreview.vue'

const props = defineProps({
  category: { type: Object, default: null },
  channels: { type: Array, required: true },
  title: { type: String, default: '' },
  isUncategorized: { type: Boolean, default: false },
})

const emit = defineEmits([
  'select-conversation',
  'create-channel',
  'rename-category',
  'delete-category',
  'context-menu',
])

const chatStore = useChatStore()
const callStore = useCallStore()
const huddleStore = useHuddleStore()

const collapsed = ref(false)
const editing = ref(false)
const editName = ref('')
const showCategoryMenu = ref(false)

const sectionTitle = computed(() => {
  if (props.category) return props.category.name
  return props.title || 'Channels'
})

function toggleCollapse() {
  collapsed.value = !collapsed.value
}

function startRename() {
  showCategoryMenu.value = false
  editName.value = props.category?.name || ''
  editing.value = true
}

function finishRename() {
  const name = editName.value.trim()
  if (name && props.category && name !== props.category.name) {
    emit('rename-category', props.category.id, name)
  }
  editing.value = false
}

function cancelRename() {
  editing.value = false
}

function handleDelete() {
  showCategoryMenu.value = false
  emit('delete-category', props.category.id)
}

function getChannelName(conv) {
  return conv.slug ? `#${conv.slug}` : (conv.name || 'Channel')
}

function formatTime(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  const now = new Date()
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))
  if (diffDays === 0) return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return date.toLocaleDateString([], { weekday: 'short' })
  return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function formatPreview(text) {
  if (!text) return ''
  if (/^\[gif:(.+?):(\d+):(\d+)\]$/.test(text)) return 'Sent a GIF'
  if (/^\[embed:(\w+):\d+\]$/.test(text)) return 'Shared content'
  if (/^\[voice:\d/.test(text)) return 'Voice message'
  if (/^\[call:/.test(text)) return 'Call'
  return text.replace(/:([a-z_]+):/g, '[$1]')
}

// Drag and drop
const dragOverIndex = ref(null)

function onDragStart(e, channel, index) {
  e.dataTransfer.setData('application/json', JSON.stringify({
    channelId: channel.id,
    fromCategoryId: props.category?.id || null,
    fromIndex: index,
  }))
  e.dataTransfer.effectAllowed = 'move'
}

function onDragOver(e, index) {
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  dragOverIndex.value = index
}

function onDragLeave() {
  dragOverIndex.value = null
}

function onDrop(e, index) {
  e.preventDefault()
  dragOverIndex.value = null
  try {
    const data = JSON.parse(e.dataTransfer.getData('application/json'))
    if (data.channelId) {
      const targetCategoryId = props.category?.id || null
      if (data.fromCategoryId !== targetCategoryId) {
        chatStore.assignChannelCategory(data.channelId, targetCategoryId)
      }
    }
  } catch (_) { /* ignore */ }
}
</script>

<template>
  <div
    class="channel-category-section"
    @dragover.prevent="onDragOver($event, -1)"
    @drop="onDrop($event, -1)"
    @dragleave="onDragLeave"
  >
    <!-- Category Header -->
    <div class="flex items-center gap-1 px-4 pt-3 pb-1 group">
      <button
        @click="toggleCollapse"
        class="flex items-center gap-1 flex-1 min-w-0"
      >
        <span
          class="material-symbols-rounded text-xs text-surface-400 transition-transform"
          :class="{ '-rotate-90': collapsed }"
        >expand_more</span>
        <template v-if="editing">
          <input
            v-model="editName"
            @keydown.enter="finishRename"
            @keydown.escape="cancelRename"
            @blur="finishRename"
            class="text-[11px] font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400 bg-transparent border-b border-primary-500 outline-none flex-1 min-w-0 py-0"
            ref="renameInput"
            autofocus
            @click.stop
          />
        </template>
        <span v-else class="text-[11px] font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400 truncate">
          {{ sectionTitle }}
        </span>
      </button>

      <!-- Actions -->
      <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
        <button
          v-if="!isUncategorized"
          @click.stop="showCategoryMenu = !showCategoryMenu"
          class="w-5 h-5 flex items-center justify-center text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 rounded transition-colors"
          title="Category options"
        >
          <span class="material-symbols-rounded text-sm">more_horiz</span>
        </button>
        <button
          @click.stop="emit('create-channel', category?.id)"
          class="w-5 h-5 flex items-center justify-center text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 rounded transition-colors"
          title="Create channel"
        >
          <span class="material-symbols-rounded text-sm">add</span>
        </button>
      </div>

      <!-- Category dropdown menu -->
      <Teleport to="body">
        <div v-if="showCategoryMenu && !isUncategorized" class="fixed inset-0 z-[9998]" @click="showCategoryMenu = false"></div>
        <div
          v-if="showCategoryMenu && !isUncategorized"
          class="fixed z-[9999] bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[160px]"
          :style="{ top: '120px', left: '60px' }"
        >
          <button
            @click="startRename"
            class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-base">edit</span>
            Rename
          </button>
          <button
            @click="handleDelete"
            class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-base">delete</span>
            Delete Category
          </button>
        </div>
      </Teleport>
    </div>

    <!-- Channel List -->
    <div v-show="!collapsed" class="pb-1">
      <div v-if="channels.length === 0" class="px-4 py-2">
        <p class="text-xs text-surface-400 italic">No channels</p>
      </div>

      <div
        v-for="(conv, idx) in channels"
        :key="conv.id"
        draggable="true"
        @dragstart="onDragStart($event, conv, idx)"
        @dragover="onDragOver($event, idx)"
        @dragleave="onDragLeave"
        @drop="onDrop($event, idx)"
        @click="emit('select-conversation', conv.id)"
        @contextmenu="emit('context-menu', $event, conv)"
        :class="[
          'flex items-center gap-2.5 px-4 py-2 cursor-pointer transition-colors',
          chatStore.activeConversationId === conv.id
            ? 'bg-primary-50 dark:bg-primary-500/10'
            : 'hover:bg-surface-50 dark:hover:bg-surface-800',
          dragOverIndex === idx ? 'border-t-2 border-primary-500' : ''
        ]"
      >
        <!-- Channel icon -->
        <div
          :class="[
            'w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0',
            conv.is_public ? 'bg-primary-100 dark:bg-primary-500/20' : 'bg-amber-100 dark:bg-amber-500/20'
          ]"
        >
          <span class="material-symbols-rounded text-lg" :class="conv.is_public ? 'text-primary-500' : 'text-amber-500'">
            {{ conv.is_public ? 'tag' : 'lock' }}
          </span>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-2">
            <span
              :class="[
                'text-sm font-medium truncate',
                conv.unread_count > 0
                  ? 'text-surface-900 dark:text-surface-100'
                  : 'text-surface-600 dark:text-surface-400'
              ]"
            >
              {{ getChannelName(conv) }}
            </span>
            <span class="text-[10px] text-surface-400 flex-shrink-0">
              {{ formatTime(conv.last_message_at) }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <p class="text-xs text-surface-500 truncate flex-1">
              {{ formatPreview(conv.last_message_preview) || `${conv.participant_count || conv.participants?.length || 0} members` }}
            </p>
            <div class="flex items-center gap-1 flex-shrink-0">
              <span v-if="conv.is_muted" class="material-symbols-rounded text-xs text-surface-400">notifications_off</span>
              <span
                v-if="conv.unread_count > 0"
                class="min-w-[18px] h-[18px] px-1 bg-primary-500 text-white text-[10px] font-medium rounded-full flex items-center justify-center"
              >
                {{ conv.unread_count > 99 ? '99+' : conv.unread_count }}
              </span>
            </div>
          </div>

        </div>

        <!-- Expanded huddle preview under this channel -->
        <SidebarHuddlePreview
          v-if="huddleStore.conversationActiveHuddles[conv.id] && !callStore.conversationActiveCalls[conv.id]"
          :conversation-id="conv.id"
        />
      </div>
    </div>
  </div>
</template>
