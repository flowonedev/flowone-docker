<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useDeviceApprovalWatcher } from '@/composables/useDeviceApprovalWatcher'

const { t } = useI18n()
const auth = useAuthStore()
const { pending, start, stop, dismiss, beginAction, approve, deny, block } = useDeviceApprovalWatcher()

// idle | approved | blocked (transient states shown before auto-close)
const phase = ref('idle')
const submitting = ref(false)
const errorMsg = ref('')
const attemptsLeft = ref(null)

const current = computed(() => pending.value)
const signedInAs = computed(() => auth.displayName || auth.userEmail || '')

function pad(n) {
  return String(n).padStart(2, '0')
}

watch(current, () => {
  // Reset transient UI whenever a new request is surfaced.
  phase.value = 'idle'
  errorMsg.value = ''
  attemptsLeft.value = null
})

watch(
  () => auth.isAuthenticated,
  (isAuthed) => {
    if (isAuthed) start()
    else stop()
  },
  { immediate: true }
)

async function onApprove(number) {
  if (submitting.value || !current.value) return
  submitting.value = true
  errorMsg.value = ''
  const reqId = current.value.request_id
  beginAction(reqId)
  try {
    await approve(reqId, number)
    phase.value = 'approved'
    setTimeout(() => dismiss(reqId), 1600)
  } catch (e) {
    const code = e.response?.data?.error
    if (code === 'DEVICE_NUMBER_MISMATCH') {
      const left = e.response?.data?.attempts_left
      attemptsLeft.value = typeof left === 'number' ? left : null
      if (attemptsLeft.value === 0) {
        dismiss(reqId)
      } else {
        errorMsg.value = t('deviceApproval.wrongNumber')
      }
    } else if (code === 'DEVICE_REQUEST_EXPIRED' || code === 'DEVICE_REQUEST_NOT_PENDING' || code === 'DEVICE_REQUEST_INVALID') {
      dismiss(reqId)
    } else {
      errorMsg.value = e.response?.data?.message || t('deviceApproval.genericError')
    }
  } finally {
    submitting.value = false
  }
}

async function onDeny() {
  if (submitting.value || !current.value) return
  submitting.value = true
  const reqId = current.value.request_id
  beginAction(reqId)
  await deny(reqId)
  dismiss(reqId)
  submitting.value = false
}

async function onBlock() {
  if (submitting.value || !current.value) return
  submitting.value = true
  const reqId = current.value.request_id
  beginAction(reqId)
  try {
    await block(reqId)
    phase.value = 'blocked'
    setTimeout(() => dismiss(reqId), 1800)
  } catch (e) {
    errorMsg.value = e.response?.data?.message || t('deviceApproval.genericError')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  if (auth.isAuthenticated) start()
})
onBeforeUnmount(stop)
</script>

<template>
  <Transition name="device-approval">
    <div
      v-if="current"
      class="fixed inset-0 z-[9998] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
    >
      <div class="w-full max-w-md card no-ambient p-0 overflow-hidden shadow-2xl !bg-white dark:!bg-[rgb(var(--color-surface))]">
        <!-- Approved (transient) -->
        <div v-if="phase === 'approved'" class="text-center px-7 py-9">
          <div class="w-16 h-16 mx-auto rounded-full bg-green-500/15 flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-4xl text-green-500">check_circle</span>
          </div>
          <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">{{ t('deviceApproval.approvedTitle') }}</h2>
          <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">{{ t('deviceApproval.approvedBody') }}</p>
        </div>

        <!-- Blocked (transient) -->
        <div v-else-if="phase === 'blocked'" class="text-center px-7 py-9">
          <div class="w-16 h-16 mx-auto rounded-full bg-red-500/15 flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-4xl text-red-500">gpp_bad</span>
          </div>
          <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">{{ t('deviceApproval.blockedTitle') }}</h2>
          <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">{{ t('deviceApproval.blockedBody') }}</p>
        </div>

        <!-- Pending: confirm + pick number -->
        <template v-else>
          <!-- Accent header -->
          <div class="px-7 pt-7 pb-5 text-center bg-gradient-to-b from-primary-500/10 to-transparent">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-primary-500/15 ring-4 ring-primary-500/10 flex items-center justify-center mb-3">
              <span class="material-symbols-rounded text-3xl text-primary-500">devices</span>
            </div>
            <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">{{ t('deviceApproval.title') }}</h2>
            <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">{{ t('deviceApproval.subtitle') }}</p>
          </div>

          <div class="px-7 pb-7">
            <div class="rounded-xl bg-surface-100 dark:bg-surface-800/60 border border-surface-200 dark:border-surface-700 divide-y divide-surface-200 dark:divide-surface-700 mb-6 text-sm">
              <div class="flex items-center gap-3 px-4 py-3 text-surface-700 dark:text-surface-200">
                <span class="material-symbols-rounded text-lg text-surface-400">computer</span>
                <span class="truncate">{{ current.device_label || t('deviceApproval.unknownDevice') }}</span>
              </div>
              <div v-if="current.ip_address" class="flex items-center gap-3 px-4 py-3 text-surface-600 dark:text-surface-300">
                <span class="material-symbols-rounded text-lg text-surface-400">location_on</span>
                <span class="font-mono">{{ current.ip_address }}</span>
              </div>
              <div class="flex items-center gap-3 px-4 py-3 text-surface-600 dark:text-surface-300">
                <span class="material-symbols-rounded text-lg text-surface-400">account_circle</span>
                <span class="truncate">{{ t('deviceApproval.signedInAs', { name: signedInAs }) }}</span>
              </div>
            </div>

            <p class="text-center text-sm font-medium text-surface-700 dark:text-surface-200 mb-3">
              {{ t('deviceApproval.tapMatching') }}
            </p>

            <div class="grid grid-cols-3 gap-3 mb-5">
              <button
                v-for="n in current.numbers"
                :key="n"
                type="button"
                :disabled="submitting"
                @click="onApprove(n)"
                class="py-6 rounded-2xl border-2 border-surface-200 dark:border-surface-600 text-3xl font-bold text-surface-900 dark:text-surface-100 hover:border-primary-500 hover:bg-primary-500/10 active:scale-95 transition-all disabled:opacity-50"
              >
                {{ pad(n) }}
              </button>
            </div>

            <div v-if="errorMsg" class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-sm text-red-600 dark:text-red-400 text-center">
              {{ errorMsg }}
              <span v-if="attemptsLeft !== null"> ({{ t('deviceApproval.attemptsLeft', { n: attemptsLeft }) }})</span>
            </div>

            <!-- Not me: decline, or block the IP entirely -->
            <div class="grid grid-cols-2 gap-3">
              <button
                type="button"
                :disabled="submitting"
                @click="onDeny"
                class="py-2.5 rounded-xl border border-surface-200 dark:border-surface-600 text-sm font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors disabled:opacity-50"
              >
                {{ t('deviceApproval.decline') }}
              </button>
              <button
                type="button"
                :disabled="submitting"
                @click="onBlock"
                class="py-2.5 rounded-xl border border-red-300 dark:border-red-500/40 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <span class="material-symbols-rounded text-base">block</span>
                {{ t('deviceApproval.block') }}
              </button>
            </div>

            <p class="text-center text-xs text-surface-400 dark:text-surface-500 mt-4 flex items-center justify-center gap-1.5">
              <span class="material-symbols-rounded text-sm">shield</span>
              {{ t('deviceApproval.securityNote') }}
            </p>
          </div>
        </template>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.device-approval-enter-active,
.device-approval-leave-active {
  transition: opacity 0.2s ease;
}
.device-approval-enter-from,
.device-approval-leave-to {
  opacity: 0;
}
</style>
