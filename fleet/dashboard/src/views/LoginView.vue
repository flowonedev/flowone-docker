<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const toast = useToastStore()

const username = ref('')
const password = ref('')
const totpCode = ref('')
const loading = ref(false)
const show2FA = ref(false)
const trustDevice = ref(false)

const handleLogin = async () => {
  if (!username.value || !password.value) {
    toast.error('Please enter username and password')
    return
  }

  loading.value = true

  try {
    const result = await auth.login(username.value, password.value)
    
    if (result.pending2FA) {
      show2FA.value = true
      loading.value = false
      return
    }

    toast.success('Login successful')
    
    const redirect = route.query.redirect || '/'
    router.push(redirect)
  } catch (error) {
    toast.error(error.message || 'Login failed')
  } finally {
    loading.value = false
  }
}

const handleVerify2FA = async () => {
  if (!totpCode.value) {
    toast.error('Please enter the verification code')
    return
  }

  loading.value = true

  try {
    await auth.verify2FA(totpCode.value, trustDevice.value)
    toast.success('Login successful')
    
    const redirect = route.query.redirect || '/'
    router.push(redirect)
  } catch (error) {
    toast.error(error.message || 'Invalid verification code')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-100 dark:bg-surface-950 px-4">
    <div class="w-full max-w-md">
      <!-- Logo -->
      <div class="text-center mb-8">
        <div class="w-16 h-16 bg-primary-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-glow">
          <span class="material-symbols-rounded text-white text-3xl">rocket_launch</span>
        </div>
        <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-100">Fleet Manager</h1>
        <p class="text-surface-500 dark:text-surface-400 mt-2">Manage your server fleet</p>
      </div>

      <!-- Login form -->
      <div class="card">
        <div class="card-body">
          <form v-if="!show2FA" @submit.prevent="handleLogin" class="space-y-5">
            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Username</label>
              <input
                v-model="username"
                type="text"
                class="input"
                placeholder="Enter username"
                autocomplete="username"
                :disabled="loading"
              />
            </div>

            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Password</label>
              <input
                v-model="password"
                type="password"
                class="input"
                placeholder="Enter password"
                autocomplete="current-password"
                :disabled="loading"
              />
            </div>

            <button
              type="submit"
              class="btn-primary w-full"
              :disabled="loading"
            >
              <span v-if="loading" class="spinner"></span>
              <span v-else>Sign In</span>
            </button>
          </form>

          <!-- 2FA form -->
          <form v-else @submit.prevent="handleVerify2FA" class="space-y-5">
            <div class="text-center mb-4">
              <div class="w-14 h-14 bg-primary-500/20 rounded-full flex items-center justify-center mx-auto mb-3">
                <span class="material-symbols-rounded text-3xl text-primary-500">security</span>
              </div>
              <p class="text-surface-500 dark:text-surface-400">Enter the verification code from your authenticator app</p>
            </div>

            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Verification Code</label>
              <input
                v-model="totpCode"
                type="text"
                class="input text-center text-2xl tracking-widest font-mono"
                placeholder="000000"
                maxlength="6"
                autocomplete="one-time-code"
                :disabled="loading"
              />
            </div>

            <!-- Trust Device Toggle -->
            <div class="flex items-center justify-between py-3 px-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl">
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-surface-400">devices</span>
                <span class="text-sm text-surface-600 dark:text-surface-300">Trust this device for 30 days</span>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" v-model="trustDevice" :disabled="loading" />
                <span class="toggle-slider"></span>
              </label>
            </div>

            <button
              type="submit"
              class="btn-primary w-full"
              :disabled="loading"
            >
              <span v-if="loading" class="spinner"></span>
              <span v-else>Verify</span>
            </button>

            <button
              type="button"
              @click="show2FA = false; auth.pending2FA = false; totpCode = ''; trustDevice = false"
              class="btn-ghost w-full"
            >
              Back to Login
            </button>
          </form>
        </div>
      </div>

      <!-- Footer -->
      <div class="text-center mt-6">
        <p class="text-xs text-surface-400 dark:text-surface-500">
          made with <span class="text-red-500">&#9829;</span> by
          <a href="https://pixelranger.hu" target="_blank" class="text-primary-500 hover:text-primary-600 transition-colors font-medium">
            Pixel Ranger Studio
          </a>
        </p>
      </div>
    </div>
  </div>
</template>
