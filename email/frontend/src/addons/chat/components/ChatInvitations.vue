<script setup>
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const chatStore = useChatStore()
const toast = useToastStore()

const props = defineProps({
  compact: {
    type: Boolean,
    default: false
  }
})

function getInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

function formatDate(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  const now = new Date()
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))
  
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return `${diffDays} days ago`
  return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

async function accept(invitation) {
  const result = await chatStore.acceptInvitation(invitation.id)
  if (result.success) {
    toast.success('Invitation accepted! You can now chat.')
  } else {
    toast.error(result.error || 'Failed to accept invitation')
  }
}

async function decline(invitation) {
  const result = await chatStore.declineInvitation(invitation.id)
  if (result.success) {
    toast.info('Invitation declined')
  } else {
    toast.error(result.error || 'Failed to decline invitation')
  }
}
</script>

<template>
  <div v-if="chatStore.pendingInvitations.length > 0" class="invitation-list">
    <!-- Section header -->
    <div 
      :class="[
        'flex items-center gap-2 px-4 py-2',
        compact ? 'px-3 py-1.5' : ''
      ]"
    >
      <span class="material-symbols-rounded text-amber-500 text-lg">mail</span>
      <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wide">
        Pending Invitations ({{ chatStore.pendingInvitations.length }})
      </span>
    </div>
    
    <!-- Invitation cards -->
    <div
      v-for="inv in chatStore.pendingInvitations"
      :key="inv.id"
      :class="[
        'mx-3 mb-2 rounded-xl border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/5 overflow-hidden',
        compact ? 'mx-2 mb-1.5' : ''
      ]"
    >
      <!-- Inviter info -->
      <div :class="['flex items-center gap-3', compact ? 'px-3 py-2' : 'px-4 py-3']">
        <!-- Avatar -->
        <UserAvatar
          :email="inv.inviter_email"
          :name="inv.inviter_name"
          size="lg"
        />
        
        <!-- Details -->
        <div class="flex-1 min-w-0">
          <p :class="['font-medium text-surface-900 dark:text-surface-100 truncate', compact ? 'text-sm' : '']">
            {{ inv.inviter_name || inv.inviter_email?.split('@')[0] || 'Someone' }}
          </p>
          <p class="text-xs text-surface-500 truncate">
            {{ inv.inviter_email }}
          </p>
          <p v-if="!compact" class="text-xs text-surface-400 mt-0.5">
            Invited {{ formatDate(inv.created_at) }}
          </p>
        </div>
      </div>
      
      <!-- Action buttons -->
      <div :class="['flex items-center gap-2 border-t border-amber-200 dark:border-amber-500/20', compact ? 'px-3 py-2' : 'px-4 py-2.5']">
        <button
          @click="accept(inv)"
          :class="[
            'flex-1 flex items-center justify-center gap-1.5 font-medium rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors',
            compact ? 'py-1.5 text-xs' : 'py-2 text-sm'
          ]"
        >
          <span class="material-symbols-rounded text-base">check</span>
          Accept
        </button>
        <button
          @click="decline(inv)"
          :class="[
            'flex-1 flex items-center justify-center gap-1.5 font-medium rounded-full bg-surface-200 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-600 transition-colors',
            compact ? 'py-1.5 text-xs' : 'py-2 text-sm'
          ]"
        >
          <span class="material-symbols-rounded text-base">close</span>
          Decline
        </button>
      </div>
    </div>
  </div>
</template>

