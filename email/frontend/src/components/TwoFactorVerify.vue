<script setup>
import { ref } from 'vue'

const props = defineProps({
  email: {
    type: String,
    required: true,
  },
})

const emit = defineEmits(['verify', 'cancel'])

const code = ref('')
const trustDevice = ref(false)
const loading = ref(false)
const error = ref('')

function handleSubmit() {
  // Remove spaces and hyphens for validation
  const cleanCode = code.value.replace(/[\s-]/g, '')
  
  if (!cleanCode || cleanCode.length < 6) {
    error.value = 'Please enter a 6-digit code or backup code'
    return
  }
  
  error.value = ''
  loading.value = true
  emit('verify', { code: code.value, trustDevice: trustDevice.value })
}

function handleCancel() {
  emit('cancel')
}

function setLoading(val) {
  loading.value = val
}

function setError(msg) {
  error.value = msg
  loading.value = false
}

defineExpose({
  setLoading,
  setError,
})
</script>

<template>
  <div class="space-y-6">
    <div class="text-center">
      <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
        <span class="material-symbols-rounded text-3xl text-primary-500">security</span>
      </div>
      <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">
        Two-Factor Authentication
      </h2>
      <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">
        Enter the 6-digit code from your authenticator app
      </p>
    </div>
    
    <form @submit.prevent="handleSubmit" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
          Verification Code
        </label>
        <input
          v-model="code"
          type="text"
          class="input text-center text-2xl tracking-widest font-mono"
          placeholder="000000"
          maxlength="10"
          autocomplete="one-time-code"
          autofocus
        />
        <p class="text-xs text-surface-500 mt-2">
          You can also use a backup code (e.g., 1234-5678)
        </p>
      </div>
      
      <div v-if="error" class="p-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 text-sm">
        <span class="material-symbols-rounded text-sm align-middle mr-1">error</span>
        {{ error }}
      </div>
      
      <!-- Trust Device Toggle -->
      <div class="flex items-center justify-between p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-surface-500 text-lg">devices</span>
          <div>
            <p class="text-sm font-medium text-surface-700 dark:text-surface-300">Trust this device</p>
            <p class="text-xs text-surface-500">Skip 2FA for 7 days on this browser</p>
          </div>
        </div>
        <button 
          type="button"
          @click="trustDevice = !trustDevice"
          :class="[
            'relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary-500/50',
            trustDevice ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
          ]"
        >
          <span 
            :class="[
              'absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200',
              trustDevice ? 'translate-x-5' : 'translate-x-0'
            ]"
          ></span>
        </button>
      </div>
      
      <button
        type="submit"
        class="btn-primary w-full"
        :disabled="loading"
      >
        <span v-if="loading" class="spinner"></span>
        <span class="material-symbols-rounded">login</span>
        Verify
      </button>
      
      <button
        type="button"
        @click="handleCancel"
        class="btn-ghost w-full"
        :disabled="loading"
      >
        <span class="material-symbols-rounded">arrow_back</span>
        Back to Login
      </button>
    </form>
    
    <p class="text-xs text-surface-500 text-center">
      Signing in as <strong>{{ email }}</strong>
    </p>
  </div>
</template>

