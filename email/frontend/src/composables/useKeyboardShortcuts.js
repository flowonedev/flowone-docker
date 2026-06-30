import { onMounted, onUnmounted } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'
import { useComposeStore } from '@/stores/compose'

export function useKeyboardShortcuts() {
  const mailbox = useMailboxStore()
  const compose = useComposeStore()

  function handleKeydown(e) {
    // Don't trigger shortcuts when typing in inputs
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
      // Only allow Escape to close compose (which auto-saves)
      if (e.key === 'Escape' && compose.isOpen) {
        compose.close() // This now auto-saves
      }
      return
    }

    // Compose modal shortcuts
    if (compose.isOpen) {
      if (e.key === 'Escape') {
        compose.close() // This now auto-saves
      }
      return
    }

    const currentIndex = mailbox.messages.findIndex(m => m.uid === mailbox.currentMessage?.uid)

    switch (e.key) {
      // Navigation
      case 'j':
      case 'ArrowDown':
        e.preventDefault()
        if (currentIndex < mailbox.messages.length - 1) {
          mailbox.fetchMessage(mailbox.messages[currentIndex + 1].uid)
        }
        break

      case 'k':
      case 'ArrowUp':
        e.preventDefault()
        if (currentIndex > 0) {
          mailbox.fetchMessage(mailbox.messages[currentIndex - 1].uid)
        }
        break

      case 'Enter':
        if (mailbox.currentMessage) {
          // Already viewing message
        } else if (mailbox.messages.length > 0) {
          mailbox.fetchMessage(mailbox.messages[0].uid)
        }
        break

      // Note: 'c' for compose removed - was triggering at unwanted times

      case 'r':
        if (mailbox.currentMessage) {
          e.preventDefault()
          compose.open(e.shiftKey ? 'replyAll' : 'reply', mailbox.currentMessage)
        }
        break

      case 'f':
        if (mailbox.currentMessage) {
          e.preventDefault()
          compose.open('forward', mailbox.currentMessage)
        }
        break

      case 's':
        if (mailbox.currentMessage) {
          e.preventDefault()
          const starFolder = mailbox.currentMessage.folder || mailbox.currentFolder
          mailbox.setFlag(mailbox.currentMessage.uid, 'flagged', !mailbox.currentMessage.flagged, starFolder)
        }
        break

      case 'u':
        if (mailbox.currentMessage) {
          e.preventDefault()
          const readFolder = mailbox.currentMessage.folder || mailbox.currentFolder
          mailbox.setFlag(mailbox.currentMessage.uid, 'seen', !mailbox.currentMessage.seen, readFolder)
        }
        break

      case 'Delete':
      case 'Backspace':
      case '#':
        e.preventDefault()
        if (mailbox.selectedMessages.length > 0) {
          mailbox.bulkDeleteMessages(mailbox.getSelectedMessagesData())
          mailbox.clearSelection()
        } else if (mailbox.currentMessage) {
          const delFolder = mailbox.currentMessage.folder || mailbox.currentFolder
          mailbox.deleteMessage(mailbox.currentMessage.uid, delFolder)
        }
        break

      case 'Escape':
        mailbox.clearCurrentMessage()
        break

      // Folder navigation
      case 'g':
        // g+i = inbox, g+s = sent, etc. (implemented with a flag)
        break

      // Refresh
      case 'R':
        if (e.shiftKey) {
          e.preventDefault()
          mailbox.fetchMessages()
        }
        break

      // Select
      case 'x':
        if (mailbox.currentMessage) {
          e.preventDefault()
          mailbox.selectMessage(mailbox.currentMessage.uid)
        }
        break

      case '*':
        if (e.shiftKey) {
          e.preventDefault()
          mailbox.selectAllMessages()
        }
        break

      // Search focus (/)
      case '/':
        e.preventDefault()
        document.querySelector('input[type="text"][placeholder*="Search"]')?.focus()
        break
    }
  }

  onMounted(() => {
    document.addEventListener('keydown', handleKeydown)
  })

  onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown)
  })
}

