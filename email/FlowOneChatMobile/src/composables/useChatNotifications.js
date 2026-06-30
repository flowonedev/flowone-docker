import { ref, watch, computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'

const dnd = ref(localStorage.getItem('chat_dnd') === 'true')

export function useChatNotifications() {
  const chatStore = useChatStore()
  const callStore = useCallStore()
  let lastKnownUnread = {}
  let initialized = false

  const isDnd = computed(() => dnd.value)

  const totalUnread = computed(() => {
    if (!chatStore.conversations) return 0
    return chatStore.conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0)
  })

  function toggleDnd() {
    dnd.value = !dnd.value
    localStorage.setItem('chat_dnd', dnd.value.toString())
  }

  function setDnd(value) {
    dnd.value = value
    localStorage.setItem('chat_dnd', value.toString())
  }

  function showNativeNotification(title, body) {
    if (dnd.value) return
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification(title, { body })
    }
  }

  function init() {
    if (initialized) return
    initialized = true

    if (chatStore.conversations) {
      for (const conv of chatStore.conversations) {
        lastKnownUnread[conv.id] = conv.unread_count || 0
      }
    }

    watch(
      () => chatStore.conversations?.map(c => ({ id: c.id, unread: c.unread_count, preview: c.last_message_preview, name: c.name })),
      (convs) => {
        if (!convs) return
        for (const conv of convs) {
          const prev = lastKnownUnread[conv.id] || 0
          if (conv.unread > prev && prev >= 0) {
            const fullConv = chatStore.conversations.find(c => c.id === conv.id)
            const name = getConversationDisplayName(fullConv)
            const preview = conv.preview || 'New message'
            showNativeNotification(name, preview.substring(0, 100))
          }
          lastKnownUnread[conv.id] = conv.unread || 0
        }
      },
      { deep: true }
    )

    watch(
      () => callStore.isRinging,
      (ringing) => {
        if (ringing && callStore.callDirection === 'incoming') {
          const callerName = callStore.callerName || callStore.callerEmail?.split('@')[0] || 'Unknown'
          const callType = callStore.callType === 'video' ? 'Video call' : 'Voice call'
          showNativeNotification(`${callType} from ${callerName}`, 'Tap to answer')
        }
      }
    )
  }

  function cleanup() {
    initialized = false
    lastKnownUnread = {}
  }

  return { isDnd, toggleDnd, setDnd, totalUnread, init, cleanup }
}

function getConversationDisplayName(conv) {
  if (!conv) return 'New message'
  if (conv.type === 'channel') return conv.slug ? `#${conv.slug}` : (conv.name || 'Channel')
  if (conv.type === 'group') return conv.name || 'Group Chat'
  const participant = conv.participants?.[0]
  if (!participant) return 'New message'
  return participant.display_name || participant.email?.split('@')[0] || 'New message'
}

export { dnd }
