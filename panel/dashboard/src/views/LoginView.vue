
<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import Toggle from '@/components/Toggle.vue'

const auth = useAuthStore()
const theme = useThemeStore()

const username = ref('')
const password = ref('')
const totpCode = ref('')
const error = ref('')
const loading = ref(false)

// 2FA state
const pending2FA = ref(false)
const tempToken = ref('')
const trustDevice = ref(false)

const handleSubmit = async () => {
  error.value = ''
  loading.value = true

  try {
    const result = await auth.login(username.value, password.value)
    
    // Check if 2FA is required
    if (result?.pending_2fa) {
      pending2FA.value = true
      tempToken.value = result.temp_token
      return
    }
    
    // Force navigation with full page load for clean state
    window.location.href = '/'
  } catch (e) {
    error.value = e.message || 'Login failed'
  } finally {
    loading.value = false
  }
}

const handleVerify2FA = async () => {
  error.value = ''
  loading.value = true

  try {
    await auth.verify2FA(tempToken.value, totpCode.value, trustDevice.value)
    
    // Force navigation - Vue Router sometimes doesn't navigate properly after async auth
    // Using window.location ensures a clean navigation and proper state initialization
    window.location.href = '/'
  } catch (e) {
    error.value = e.message || 'Invalid verification code'
    totpCode.value = ''
  } finally {
    loading.value = false
  }
}

const cancelVerification = () => {
  pending2FA.value = false
  tempToken.value = ''
  totpCode.value = ''
  password.value = ''
  error.value = ''
  trustDevice.value = false
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-100 dark:bg-surface-900 p-4">
    <!-- Background pattern -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
      <div class="absolute -top-1/2 -right-1/2 w-full h-full bg-gradient-to-br from-primary-500/10 to-transparent rounded-full blur-3xl"></div>
      <div class="absolute -bottom-1/2 -left-1/2 w-full h-full bg-gradient-to-tr from-primary-500/5 to-transparent rounded-full blur-3xl"></div>
    </div>

    <div class="w-full max-w-md relative">
      <!-- Theme toggle -->
      <button
        @click="theme.toggle()"
        class="absolute -top-16 right-0 p-2 rounded-xl bg-white dark:bg-surface-800 shadow-sm hover:shadow transition-shadow"
      >
        <span class="material-symbols-rounded">
          {{ theme.isDark ? 'light_mode' : 'dark_mode' }}
        </span>
      </button>

      <!-- Login card -->
      <div class="card p-8">
        <!-- Logo -->
        <div class="text-center mb-8">
          <div class="w-16 h-16 rounded-2xl bg-primary-500 flex items-center justify-center mx-auto mb-4 shadow-glow">
            <span class="material-symbols-rounded text-white text-3xl">
              {{ pending2FA ? 'verified_user' : 'terminal' }}
            </span>
          </div>
          <h1 class="text-2xl font-semibold tracking-tight">
            {{ pending2FA ? 'Verification' : 'VPS Admin' }}
          </h1>
          <p class="text-surface-500 dark:text-surface-400 text-sm mt-1">
            {{ pending2FA ? 'Enter your authenticator code' : 'Sign in to your server panel' }}
          </p>
        </div>

        <!-- Error message -->
        <div v-if="error" class="mb-6 p-3 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20">
          <p class="text-sm text-red-600 dark:text-red-400 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">error</span>
            {{ error }}
          </p>
        </div>

        <!-- Login Form -->
        <form v-if="!pending2FA" @submit.prevent="handleSubmit" class="space-y-5">
          <div>
            <label class="block text-sm font-medium mb-2">Username</label>
            <input
              v-model="username"
              type="text"
              class="input"
              placeholder="admin"
              required
              autofocus
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Password</label>
            <input
              v-model="password"
              type="password"
              class="input"
              placeholder="Enter your password"
              required
            />
          </div>

          <button
            type="submit"
            class="btn-primary w-full"
            :disabled="loading"
          >
            <span v-if="loading" class="spinner"></span>
            <span v-else class="material-symbols-rounded">login</span>
            Sign In
          </button>
        </form>

        <!-- 2FA Verification Form -->
        <form v-else @submit.prevent="handleVerify2FA" class="space-y-5">
          <div>
            <label class="block text-sm font-medium mb-2">Authentication Code</label>
            <input
              v-model="totpCode"
              type="text"
              class="input text-center text-2xl tracking-widest font-mono"
              placeholder="000000"
              maxlength="8"
              autocomplete="one-time-code"
              required
              autofocus
            />
            <p class="text-xs text-surface-500 mt-2 text-center">
              Enter the 6-digit code from your authenticator app or a backup code
            </p>
          </div>

          <!-- Trust this device toggle -->
          <div class="flex items-center justify-between px-1">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-surface-400">devices</span>
              <span class="text-sm">Trust this device for 30 days</span>
            </div>
            <Toggle v-model="trustDevice" />
          </div>

          <div class="space-y-3">
            <button
              type="submit"
              class="btn-primary w-full"
              :disabled="loading || totpCode.length < 6"
            >
              <span v-if="loading" class="spinner"></span>
              <span v-else class="material-symbols-rounded">verified_user</span>
              Verify
            </button>

            <button
              type="button"
              @click="cancelVerification"
              class="btn-secondary w-full"
            >
              <span class="material-symbols-rounded">arrow_back</span>
              Back to Login
            </button>
          </div>
        </form>
      </div>

      <!-- Footer -->
      <p class="text-center text-sm text-surface-400 mt-6">
        Secure server management panel
      </p>
    </div>
  </div>
</template>
