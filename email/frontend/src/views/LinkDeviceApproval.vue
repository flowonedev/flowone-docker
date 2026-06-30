<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()

const requestId = String(route.query.req || '')

// In the native app this page is reached by scanning a QR; offer a way back to
// the inbox once the request resolves (the browser back-stack isn't obvious there).
const isNativeApp = typeof window !== 'undefined' && !!window.Capacitor?.isNativePlatform?.()

function goToInbox() {
  router.push('/inbox')
}

// loading | pending | approved | denied | expired | invalid | error
const state = ref('loading')
const deviceLabel = ref('')
const ipAddress = ref('')
const numbers = ref([])
const submitting = ref(false)
const errorMsg = ref('')
const attemptsLeft = ref(null)

const signedInAs = computed(() => auth.displayName || auth.userEmail || '')

function pad(n) {
  return String(n).padStart(2, '0')
}

async function loadInfo() {
  if (!requestId) {
    state.value = 'invalid'
    return
  }
  try {
    const resp = await api.get('/sso/device/info', { params: { req: requestId } })
    const data = resp.data?.data || {}
    if (data.status && data.status !== 'pending') {
      state.value = data.status === 'consumed' || data.status === 'approved' ? 'approved' : data.status
      return
    }
    deviceLabel.value = data.device_label || t('linkDevice.unknownDevice')
    ipAddress.value = data.ip_address || ''
    numbers.value = Array.isArray(data.numbers) ? data.numbers : []
    state.value = 'pending'
  } catch (e) {
    const code = e.response?.data?.error
    state.value = code === 'DEVICE_REQUEST_INVALID' ? 'invalid' : 'error'
    errorMsg.value = e.response?.data?.message || ''
  }
}

async function approve(number) {
  if (submitting.value) return
  submitting.value = true
  errorMsg.value = ''
  try {
    await api.post('/sso/device/approve', { request_id: requestId, number })
    state.value = 'approved'
  } catch (e) {
    const code = e.response?.data?.error
    if (code === 'DEVICE_NUMBER_MISMATCH') {
      const left = e.response?.data?.attempts_left
      attemptsLeft.value = typeof left === 'number' ? left : null
      if (attemptsLeft.value === 0) {
        state.value = 'denied'
      } else {
        errorMsg.value = t('linkDevice.wrongNumber')
      }
    } else if (code === 'DEVICE_REQUEST_EXPIRED') {
      state.value = 'expired'
    } else if (code === 'DEVICE_REQUEST_NOT_PENDING') {
      state.value = 'denied'
    } else {
      errorMsg.value = e.response?.data?.message || t('linkDevice.genericError')
    }
  } finally {
    submitting.value = false
  }
}

async function deny() {
  if (submitting.value) return
  submitting.value = true
  try {
    await api.post('/sso/device/deny', { request_id: requestId })
  } catch (e) {
    /* best-effort */
  } finally {
    submitting.value = false
    state.value = 'denied'
  }
}

onMounted(loadInfo)
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-surface-100 to-surface-200 dark:from-surface-900 dark:to-surface-950">
    <div class="w-full max-w-md">
      <div class="card p-8">
        <!-- Loading -->
        <div v-if="state === 'loading'" class="text-center py-6">
          <span class="spinner text-primary-500 mb-3"></span>
          <p class="text-surface-500 dark:text-surface-400">{{ t('linkDevice.checking') }}</p>
        </div>

        <!-- Pending: show numbers to match -->
        <template v-else-if="state === 'pending'">
          <div class="text-center mb-6">
            <div class="w-14 h-14 mx-auto rounded-xl bg-primary-500/15 flex items-center justify-center mb-4">
              <span class="material-symbols-rounded text-3xl text-primary-500">devices</span>
            </div>
            <h1 class="text-xl font-semibold text-surface-900 dark:text-surface-100">{{ t('linkDevice.title') }}</h1>
            <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">{{ t('linkDevice.subtitle') }}</p>
          </div>

          <div class="rounded-xl bg-surface-100 dark:bg-surface-900/50 border border-surface-200 dark:border-surface-700 p-4 mb-6 space-y-2 text-sm">
            <div class="flex items-center gap-2 text-surface-700 dark:text-surface-200">
              <span class="material-symbols-rounded text-base text-surface-400">computer</span>
              <span class="truncate">{{ deviceLabel }}</span>
            </div>
            <div v-if="ipAddress" class="flex items-center gap-2 text-surface-500">
              <span class="material-symbols-rounded text-base text-surface-400">location_on</span>
              <span>{{ ipAddress }}</span>
            </div>
            <div class="flex items-center gap-2 text-surface-500">
              <span class="material-symbols-rounded text-base text-surface-400">account_circle</span>
              <span>{{ t('linkDevice.signedInAs', { name: signedInAs }) }}</span>
            </div>
          </div>

          <p class="text-center text-sm font-medium text-surface-700 dark:text-surface-200 mb-3">
            {{ t('linkDevice.tapMatching') }}
          </p>

          <div class="grid grid-cols-3 gap-3 mb-4">
            <button
              v-for="n in numbers"
              :key="n"
              type="button"
              :disabled="submitting"
              @click="approve(n)"
              class="py-5 rounded-xl border-2 border-surface-200 dark:border-surface-600 text-2xl font-bold text-surface-900 dark:text-surface-100 hover:border-primary-500 hover:bg-primary-500/10 transition-colors disabled:opacity-50"
            >
              {{ pad(n) }}
            </button>
          </div>

          <div v-if="errorMsg" class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-sm text-red-600 dark:text-red-400 text-center">
            {{ errorMsg }}
            <span v-if="attemptsLeft !== null"> ({{ t('linkDevice.attemptsLeft', { n: attemptsLeft }) }})</span>
          </div>

          <button
            type="button"
            :disabled="submitting"
            @click="deny"
            class="w-full text-sm text-surface-500 hover:text-red-500 transition-colors py-2"
          >
            {{ t('linkDevice.notMe') }}
          </button>
        </template>

        <!-- Approved -->
        <div v-else-if="state === 'approved'" class="text-center py-4">
          <div class="w-16 h-16 mx-auto rounded-full bg-green-500/15 flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-4xl text-green-500">check_circle</span>
          </div>
          <h1 class="text-xl font-semibold text-surface-900 dark:text-surface-100">{{ t('linkDevice.approvedTitle') }}</h1>
          <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">{{ t('linkDevice.approvedBody') }}</p>
        </div>

        <!-- Denied -->
        <div v-else-if="state === 'denied'" class="text-center py-4">
          <div class="w-16 h-16 mx-auto rounded-full bg-red-500/15 flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-4xl text-red-500">block</span>
          </div>
          <h1 class="text-xl font-semibold text-surface-900 dark:text-surface-100">{{ t('linkDevice.deniedTitle') }}</h1>
          <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">{{ t('linkDevice.deniedBody') }}</p>
        </div>

        <!-- Expired / invalid / error -->
        <div v-else class="text-center py-4">
          <div class="w-16 h-16 mx-auto rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-4xl text-surface-500">link_off</span>
          </div>
          <h1 class="text-xl font-semibold text-surface-900 dark:text-surface-100">
            {{ state === 'expired' ? t('linkDevice.expiredTitle') : t('linkDevice.invalidTitle') }}
          </h1>
          <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">
            {{ state === 'expired' ? t('linkDevice.expiredBody') : t('linkDevice.invalidBody') }}
          </p>
        </div>

        <!-- Native app: return to the inbox once the request has resolved -->
        <button
          v-if="isNativeApp && state !== 'loading' && state !== 'pending'"
          type="button"
          @click="goToInbox"
          class="mt-6 w-full py-3 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors"
        >
          {{ t('linkDevice.done') }}
        </button>
      </div>

      <p class="text-center text-xs text-surface-400 dark:text-surface-500 mt-5 flex items-center justify-center gap-1.5">
        <span class="material-symbols-rounded text-sm">shield</span>
        {{ t('linkDevice.securityNote') }}
      </p>
    </div>
  </div>
</template>
