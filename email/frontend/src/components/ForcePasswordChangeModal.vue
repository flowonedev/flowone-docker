<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()

const newPassword = ref('')
const confirmPassword = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const done = ref(false)

// Mirror the backend complexity rules (min 12 + upper/lower/digit/special).
const rules = computed(() => {
  const p = newPassword.value
  return [
    { key: 'len', label: 'At least 12 characters', ok: p.length >= 12 },
    { key: 'upper', label: 'One uppercase letter', ok: /[A-Z]/.test(p) },
    { key: 'lower', label: 'One lowercase letter', ok: /[a-z]/.test(p) },
    { key: 'digit', label: 'One number', ok: /[0-9]/.test(p) },
    { key: 'special', label: 'One special character', ok: /[^A-Za-z0-9]/.test(p) },
  ]
})

const allRulesMet = computed(() => rules.value.every((r) => r.ok))
const matches = computed(() => confirmPassword.value.length > 0 && newPassword.value === confirmPassword.value)
const canSubmit = computed(() => allRulesMet.value && matches.value && !submitting.value)

async function submit() {
  if (!canSubmit.value) return

  submitting.value = true
  try {
    const response = await api.put('/settings/password', {
      new_password: newPassword.value,
    })

    if (response.data.success) {
      auth.clearForcePasswordChange()
      done.value = true
      // The current session still holds the old (temporary) password, which is
      // now invalid for IMAP — sign the user out so they re-authenticate.
      setTimeout(finish, 2000)
    } else {
      toast.error(response.data.message || 'Failed to change password')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to change password')
  } finally {
    submitting.value = false
  }
}

async function finish() {
  try {
    await auth.logout()
  } catch (e) {
    // Ignore — we redirect regardless.
  }
  router.push({ name: 'login' }).catch(() => {})
}
</script>

<template>
  <div class="fixed inset-0 z-[100000] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="w-full max-w-md rounded-2xl bg-white dark:bg-surface-800 shadow-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <!-- Header -->
      <div class="px-6 pt-6 pb-4 flex items-start gap-3 border-b border-surface-100 dark:border-surface-700">
        <div class="shrink-0 w-11 h-11 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">lock_reset</span>
        </div>
        <div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Set a new password</h2>
          <p class="text-sm text-surface-500 dark:text-surface-400 mt-0.5">
            For your security, please choose a new password before continuing.
          </p>
        </div>
      </div>

      <!-- Success state -->
      <div v-if="done" class="px-6 py-10 text-center">
        <div class="mx-auto w-14 h-14 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center mb-4">
          <span class="material-symbols-rounded text-3xl text-green-600 dark:text-green-400">check_circle</span>
        </div>
        <p class="font-medium text-surface-900 dark:text-surface-100">Password updated</p>
        <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">
          Please sign in again with your new password.
        </p>
        <button class="btn-primary mt-5 w-full justify-center" @click="finish">
          Continue to sign in
        </button>
      </div>

      <!-- Form -->
      <form v-else @submit.prevent="submit" class="px-6 py-5 space-y-4">
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">New password</label>
          <div class="relative">
            <input
              v-model="newPassword"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              class="input w-full pr-10"
              placeholder="Enter a new password"
            />
            <button
              type="button"
              class="absolute inset-y-0 right-0 px-3 flex items-center text-surface-400 hover:text-surface-600"
              tabindex="-1"
              @click="showPassword = !showPassword"
            >
              <span class="material-symbols-rounded text-base">{{ showPassword ? 'visibility_off' : 'visibility' }}</span>
            </button>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Confirm new password</label>
          <input
            v-model="confirmPassword"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            class="input w-full"
            placeholder="Re-enter the new password"
          />
          <p v-if="confirmPassword.length > 0 && !matches" class="text-xs text-red-500 mt-1">
            Passwords do not match
          </p>
        </div>

        <!-- Requirements checklist -->
        <ul class="space-y-1.5 text-sm">
          <li v-for="rule in rules" :key="rule.key" class="flex items-center gap-2">
            <span
              class="material-symbols-rounded text-base"
              :class="rule.ok ? 'text-green-500' : 'text-surface-300 dark:text-surface-600'"
            >
              {{ rule.ok ? 'check_circle' : 'radio_button_unchecked' }}
            </span>
            <span :class="rule.ok ? 'text-surface-700 dark:text-surface-300' : 'text-surface-400'">{{ rule.label }}</span>
          </li>
        </ul>

        <button type="submit" class="btn-primary w-full justify-center" :disabled="!canSubmit">
          <span v-if="submitting" class="material-symbols-rounded animate-spin">progress_activity</span>
          <span>{{ submitting ? 'Saving…' : 'Save new password' }}</span>
        </button>
      </form>
    </div>
  </div>
</template>
