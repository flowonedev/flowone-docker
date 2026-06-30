<script setup>
/**
 * PortalLoginView - Magic link consumption + request new link
 * 
 * Two modes:
 * 1. With token param: auto-consumes magic link and redirects to portal
 * 2. Without token: shows "request access link" form
 */
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePortalStore } from '@/stores/portal'

const router = useRouter()
const route = useRoute()
const portal = usePortalStore()

const status = ref('loading') // 'loading' | 'consuming' | 'error' | 'request' | 'request-sent'
const errorMessage = ref('')
const errorCode = ref('')
const requestEmail = ref('')
const requestLoading = ref(false)
const requestMessage = ref('')

onMounted(async () => {
  const token = route.params.token

  if (token) {
    // Consuming a magic link
    status.value = 'consuming'
    const result = await portal.consumeMagicLink(token)

    if (result.success) {
      // Redirect to portal home
      router.replace({ name: 'portal-home' })
    } else {
      status.value = 'error'
      errorMessage.value = result.error
      errorCode.value = result.code || 'error'
    }
  } else {
    // Check if already authenticated
    if (portal.isAuthenticated) {
      router.replace({ name: 'portal-home' })
      return
    }
    
    // Check stored session
    const isValid = await portal.checkAuth()
    if (isValid) {
      router.replace({ name: 'portal-home' })
      return
    }
    
    status.value = 'request'
  }
})

async function submitRequestLink() {
  if (!requestEmail.value || requestLoading.value) return
  
  requestLoading.value = true
  const result = await portal.requestLink(requestEmail.value)
  requestLoading.value = false
  
  if (result.success) {
    status.value = 'request-sent'
    requestMessage.value = result.message
  } else {
    requestMessage.value = result.error
  }
}
</script>

<template>
  <div class="min-h-screen bg-surface-50 dark:bg-surface-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <!-- Logo / Brand -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-primary-100 dark:bg-primary-500/20 mb-4">
          <span class="material-symbols-rounded text-3xl text-primary-600 dark:text-primary-400">shield_person</span>
        </div>
        <h1 class="text-2xl font-bold text-surface-900 dark:text-white">{{ $t('portalLoginView.clientPortal') }}</h1>
      </div>

      <!-- Loading / Consuming -->
      <div v-if="status === 'loading' || status === 'consuming'" 
           class="bg-white dark:bg-surface-800 rounded-2xl shadow-sm border border-surface-200 dark:border-surface-700 p-8 text-center">
        <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto mb-4"></div>
        <p class="text-surface-600 dark:text-surface-300">
          {{ status === 'consuming' ? $t('portalLoginView.signingYouIn') : $t('portalLoginView.loading') }}
        </p>
      </div>

      <!-- Error -->
      <div v-else-if="status === 'error'" 
           class="bg-white dark:bg-surface-800 rounded-2xl shadow-sm border border-surface-200 dark:border-surface-700 p-8">
        <div class="text-center mb-6">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-red-100 dark:bg-red-500/20 mb-3">
            <span class="material-symbols-rounded text-2xl text-red-600 dark:text-red-400">
              {{ errorCode === 'already_used' ? 'link_off' : errorCode === 'expired' ? 'timer_off' : errorCode === 'revoked' ? 'block' : 'error' }}
            </span>
          </div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-2">{{ $t('portalLoginView.unableToSignIn') }}</h2>
          <p class="text-surface-600 dark:text-surface-300 text-sm">{{ errorMessage }}</p>
        </div>

        <!-- Request new link form -->
        <div class="border-t border-surface-200 dark:border-surface-700 pt-6 mt-6">
          <p class="text-sm text-surface-500 mb-4">{{ $t('portalLoginView.needANewLinkEnter') }}</p>
          <form @submit.prevent="submitRequestLink" class="space-y-3">
            <input 
              v-model="requestEmail" 
              type="email" 
              :placeholder="$t('portalLoginView.youremailcom')"
              required
              class="w-full px-4 py-3 rounded-xl border border-surface-300 dark:border-surface-600 
                     bg-white dark:bg-surface-700 text-surface-900 dark:text-white
                     focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none text-sm"
            />
            <button 
              type="submit" 
              :disabled="requestLoading"
              class="w-full py-3 px-4 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-medium text-sm
                     disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {{ requestLoading ? $t('portalLoginView.sending') : $t('portalLoginView.requestNewLink') }}
            </button>
          </form>
          <p v-if="requestMessage" class="mt-3 text-sm text-surface-500">{{ requestMessage }}</p>
        </div>
      </div>

      <!-- Request Link Form (no token) -->
      <div v-else-if="status === 'request'"
           class="bg-white dark:bg-surface-800 rounded-2xl shadow-sm border border-surface-200 dark:border-surface-700 p-8">
        <div class="text-center mb-6">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-2">{{ $t('portalLoginView.accessYourPortal') }}</h2>
          <p class="text-surface-600 dark:text-surface-300 text-sm">
            {{ $t('portalLoginView.enterEmailToReceiveLink') }}
          </p>
        </div>
        <form @submit.prevent="submitRequestLink" class="space-y-4">
          <input 
            v-model="requestEmail" 
            type="email" 
            :placeholder="$t('portalLoginView.youremailcom')"
            required
            class="w-full px-4 py-3 rounded-xl border border-surface-300 dark:border-surface-600 
                   bg-white dark:bg-surface-700 text-surface-900 dark:text-white
                   focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none text-sm"
          />
          <button 
            type="submit" 
            :disabled="requestLoading"
            class="w-full py-3 px-4 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-medium text-sm
                   disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {{ requestLoading ? $t('portalLoginView.sending') : $t('portalLoginView.sendSignInLink') }}
          </button>
        </form>
        <p v-if="requestMessage" class="mt-4 text-sm text-center text-surface-500">{{ requestMessage }}</p>
      </div>

      <!-- Request Sent -->
      <div v-else-if="status === 'request-sent'"
           class="bg-white dark:bg-surface-800 rounded-2xl shadow-sm border border-surface-200 dark:border-surface-700 p-8 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-green-100 dark:bg-green-500/20 mb-4">
          <span class="material-symbols-rounded text-2xl text-green-600 dark:text-green-400">mark_email_read</span>
        </div>
        <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-2">{{ $t('portalLoginView.checkYourEmail') }}</h2>
        <p class="text-surface-600 dark:text-surface-300 text-sm">
          {{ requestMessage || $t('portalLoginView.requestSentFallback') }}
        </p>
        <button 
          @click="status = 'request'; requestMessage = ''"
          class="mt-6 text-sm text-primary-600 dark:text-primary-400 hover:underline"
        >
          {{ $t('portalLoginView.tryDifferentEmail') }}
        </button>
      </div>
    </div>
  </div>
</template>

