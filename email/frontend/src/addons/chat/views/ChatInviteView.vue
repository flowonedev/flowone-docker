<script setup>
/**
 * ChatInviteView - Processes chat invitation tokens from email links.
 * 
 * Flow: User clicks invite link in email -> logs in -> lands here ->
 * auto-processes the token -> redirects to the chat conversation.
 */
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'
import { useI18n } from 'vue-i18n'

const route = useRoute()
const router = useRouter()
const chatStore = useChatStore()
const toast = useToastStore()
const { t } = useI18n()

const status = ref('processing') // 'processing' | 'success' | 'error'
const errorMessage = ref('')

onMounted(async () => {
  const token = route.params.token
  
  if (!token) {
    status.value = 'error'
    errorMessage.value = 'chatInviteView.invalidInvitationLinkNoToken'
    return
  }
  
  // Initialize chat store if not already
  await chatStore.init()
  
  // Process the invite token
  const result = await chatStore.processInviteToken(token)
  
  if (result.success) {
    status.value = 'success'
    
    if (result.alreadyAccepted) {
      toast.info('You already accepted this invitation.')
    } else {
      toast.success('Invitation accepted! You can now chat.')
    }
    
    // Navigate to the chat view (conversation is already set active by processInviteToken)
    setTimeout(() => {
      router.replace('/chat')
    }, 500)
  } else {
    status.value = 'error'
    errorMessage.value = result.error || 'chatInviteView.couldNotProcessInvitation'
  }
})

function goToChat() {
  router.replace('/chat')
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-900 p-4">
    <div class="max-w-md w-full bg-white dark:bg-surface-800 rounded-2xl shadow-xl p-8 text-center">
      
      <!-- Processing -->
      <template v-if="status === 'processing'">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-3xl text-primary-500 animate-spin">progress_activity</span>
        </div>
        <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">
          {{ $t('chatInviteView.processingInvitation') }}
        </h2>
        <p class="text-surface-500">
          {{ $t('chatInviteView.settingUpConversation') }}
        </p>
      </template>
      
      <!-- Success -->
      <template v-else-if="status === 'success'">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-3xl text-green-500">check_circle</span>
        </div>
        <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">
          {{ $t('chatInviteView.youAreConnected') }}
        </h2>
        <p class="text-surface-500 mb-6">
          {{ $t('chatInviteView.redirectingToConversation') }}
        </p>
        <button
          @click="goToChat"
          class="px-6 py-3 bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors font-medium"
        >
          {{ $t('chatInviteView.goToChat') }}
        </button>
      </template>
      
      <!-- Error -->
      <template v-else>
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-3xl text-red-500">error</span>
        </div>
        <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">
          {{ $t('chatInviteView.invitationProblem') }}
        </h2>
        <p class="text-surface-500 mb-6">
          {{ typeof errorMessage === 'string' && errorMessage.startsWith('chatInviteView.') ? t(errorMessage) : errorMessage }}
        </p>
        <button
          @click="goToChat"
          class="px-6 py-3 bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors font-medium"
        >
          {{ $t('chatInviteView.goToChat') }}
        </button>
      </template>
    </div>
  </div>
</template>

