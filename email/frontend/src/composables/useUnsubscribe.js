import { ref, readonly } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

// Shared state across all components
const unsubscribedMessages = ref(new Set(
  JSON.parse(localStorage.getItem('unsubscribed_messages') || '[]')
))

// Modal states
const showUnsubscribeConfirm = ref(false)
const showUnsubscribeUrlConfirm = ref(false)
const unsubscribingMessage = ref(null)
const pendingUnsubscribeMessage = ref(null)
const unsubscribing = ref(false)

// Save to localStorage
function saveUnsubscribedMessages() {
  localStorage.setItem('unsubscribed_messages', JSON.stringify([...unsubscribedMessages.value]))
}

// Check if message has unsubscribe option
function hasUnsubscribe(msg) {
  return msg?.unsubscribe_url || msg?.unsubscribe_email
}

// Check if message has been unsubscribed
function isUnsubscribed(msg) {
  if (!msg) return false
  return unsubscribedMessages.value.has(msg.uid) || unsubscribedMessages.value.has(msg.message_id)
}

// Get formatted sender string
function getSenderDisplay(msg) {
  if (!msg?.from) return 'this sender'
  const from = Array.isArray(msg.from) ? msg.from[0] : msg.from
  if (!from) return msg.from_name || msg.from_email || 'this sender'
  if (from.name && from.email) return `${from.name} (${from.email})`
  return from.email || from.name || msg.from_name || msg.from_email || 'this sender'
}

// Mark message as unsubscribed
function markAsUnsubscribed(msg) {
  if (msg.uid) unsubscribedMessages.value.add(msg.uid)
  if (msg.message_id) unsubscribedMessages.value.add(msg.message_id)
  saveUnsubscribedMessages()
}

// Initiate unsubscribe (show first confirmation)
function initiateUnsubscribe(msg) {
  unsubscribingMessage.value = msg
  showUnsubscribeConfirm.value = true
}

// Cancel first confirmation
function cancelUnsubscribe() {
  showUnsubscribeConfirm.value = false
  unsubscribingMessage.value = null
}

// Execute unsubscribe
async function executeUnsubscribe() {
  const toast = useToastStore()
  const msg = unsubscribingMessage.value
  if (!msg) return
  
  unsubscribing.value = true
  
  try {
    let type = 'mailto'
    let url = null
    let email = null
    let oneClick = false
    
    // Prefer HTTPS with one-click
    if (msg.unsubscribe_url && msg.unsubscribe_one_click) {
      type = 'https'
      url = msg.unsubscribe_url
      oneClick = true
    } else if (msg.unsubscribe_url) {
      type = 'https'
      url = msg.unsubscribe_url
    } else if (msg.unsubscribe_email) {
      type = 'mailto'
      email = msg.unsubscribe_email
    }
    
    const response = await api.post('/mailbox/unsubscribe', {
      type,
      url,
      email,
      one_click: oneClick
    })
    
    if (response.data.success) {
      const action = response.data.data?.action
      
      if (action === 'unsubscribed') {
        // One-click confirmed by server
        markAsUnsubscribed(msg)
        toast.success('Successfully unsubscribed! The server confirmed your request.')
      } else if (action === 'email_sent') {
        // Email sent
        markAsUnsubscribed(msg)
        toast.success('Unsubscribe request email sent.')
      } else if (action === 'open_url') {
        // URL needs user confirmation - DON'T mark yet
        const unsubUrl = response.data.data?.url
        if (unsubUrl) {
          window.open(unsubUrl, '_blank', 'noopener,noreferrer')
          pendingUnsubscribeMessage.value = msg
          showUnsubscribeUrlConfirm.value = true
        }
      }
    } else {
      toast.error(response.data.message || 'Failed to unsubscribe')
    }
  } catch (e) {
    console.error('Unsubscribe error:', e)
    toast.error('Failed to unsubscribe')
  } finally {
    unsubscribing.value = false
    showUnsubscribeConfirm.value = false
    unsubscribingMessage.value = null
  }
}

// Confirm user completed URL unsubscribe
function confirmUrlUnsubscribe() {
  const toast = useToastStore()
  const msg = pendingUnsubscribeMessage.value
  if (msg) {
    markAsUnsubscribed(msg)
    toast.success('Marked as unsubscribed')
  }
  showUnsubscribeUrlConfirm.value = false
  pendingUnsubscribeMessage.value = null
}

// Cancel URL confirmation
function cancelUrlUnsubscribe() {
  const toast = useToastStore()
  showUnsubscribeUrlConfirm.value = false
  pendingUnsubscribeMessage.value = null
  toast.info('Not marked as unsubscribed')
}

export function useUnsubscribe() {
  return {
    // State (readonly to prevent direct mutation)
    unsubscribedMessages: readonly(unsubscribedMessages),
    showUnsubscribeConfirm,
    showUnsubscribeUrlConfirm,
    unsubscribingMessage: readonly(unsubscribingMessage),
    pendingUnsubscribeMessage: readonly(pendingUnsubscribeMessage),
    unsubscribing: readonly(unsubscribing),
    
    // Functions
    hasUnsubscribe,
    isUnsubscribed,
    getSenderDisplay,
    markAsUnsubscribed,
    initiateUnsubscribe,
    cancelUnsubscribe,
    executeUnsubscribe,
    confirmUrlUnsubscribe,
    cancelUrlUnsubscribe,
  }
}

