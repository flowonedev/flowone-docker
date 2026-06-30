<script setup>
/**
 * PortalRequestAccessView - Public page where clients can request a magic link
 * This is a standalone public page (no portal auth required).
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import portalApi from '@/services/portalApi'

const email = ref('')
const isSubmitting = ref(false)
const submitted = ref(false)
const errorMsg = ref('')
const { t } = useI18n()

async function onSubmit() {
  if (!email.value) return
  isSubmitting.value = true
  errorMsg.value = ''
  try {
    await portalApi.post('/portal/request-link', { email: email.value })
    submitted.value = true
  } catch (e) {
    errorMsg.value = e.response?.data?.message || 'portalRequestAccessView.somethingWentWrong'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-900 p-4">
    <div class="w-full max-w-md">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 p-8">
        <div class="text-center mb-6">
          <span class="material-symbols-rounded text-5xl text-primary-500 mb-2">lock_open</span>
          <h1 class="text-2xl font-bold text-surface-900 dark:text-white">{{ $t('portalView.clientPortal') }}</h1>
          <p class="text-sm text-surface-500 mt-1">{{ $t('portalRequestAccessView.requestAccessSubtitle') }}</p>
        </div>

        <!-- Success state -->
        <div v-if="submitted" class="text-center py-4">
          <span class="material-symbols-rounded text-4xl text-green-500 mb-2">mark_email_read</span>
          <h2 class="text-lg font-semibold text-surface-800 dark:text-white mb-2">{{ $t('portalRequestAccessView.checkYourEmail') }}</h2>
          <p class="text-sm text-surface-500">
            {{ $t('portalRequestAccessView.ifAccountAssociatedWith') }}
            <strong class="text-surface-700 dark:text-surface-200">{{ email }}</strong>,
            {{ $t('portalRequestAccessView.youWillReceiveMagicLink') }}
          </p>
          <button @click="submitted = false; email = ''"
                  class="mt-6 text-sm text-primary-600 dark:text-primary-400 hover:underline">
            {{ $t('portalRequestAccessView.tryAnotherEmail') }}
          </button>
        </div>

        <!-- Request form -->
        <form v-else @submit.prevent="onSubmit" class="space-y-4">
          <div>
            <label for="portal-email" class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1.5">
              {{ $t('portalRequestAccessView.emailAddress') }}
            </label>
            <input 
              id="portal-email"
              type="email" 
              v-model="email" 
              required
              :placeholder="$t('portalRequestAccessView.emailPlaceholder')"
              class="w-full px-4 py-3 rounded-xl border border-surface-300 dark:border-surface-600 
                     bg-white dark:bg-surface-700 text-surface-900 dark:text-white
                     placeholder:text-surface-400 text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
              :disabled="isSubmitting"
            />
          </div>

          <p v-if="errorMsg" class="text-sm text-red-500">
            {{ typeof errorMsg === 'string' && errorMsg.startsWith('portalRequestAccessView.') ? t(errorMsg) : errorMsg }}
          </p>

          <button 
            type="submit" 
            :disabled="isSubmitting || !email"
            class="w-full py-3 px-4 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-semibold text-sm
                   disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {{ isSubmitting ? $t('portalRequestAccessView.sending') : $t('portalRequestAccessView.sendMagicLink') }}
          </button>
        </form>

        <p class="text-xs text-center text-surface-400 mt-6">
          {{ $t('portalRequestAccessView.contactProjectManager') }}
        </p>
      </div>
    </div>
  </div>
</template>

