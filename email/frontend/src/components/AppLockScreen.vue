<script setup>
import { ref, onMounted, nextTick, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import {
  isLocked,
  hasBiometric,
  biometricAvailable,
  pinError,
  unlockWithPin,
  unlockWithBiometric,
} from '@/services/appLock'

const auth = useAuthStore()

const pinDigits = ref(['', '', '', '', '', ''])
const pinLength = ref(parseInt(localStorage.getItem('app_lock_pin_length') || '4', 10))
const pinInputs = ref([])
const isVerifying = ref(false)
const showPin = ref(false)

const activeDigits = computed(() => pinDigits.value.slice(0, pinLength.value))
const filledCount = computed(() => activeDigits.value.filter(d => d !== '').length)

function focusInput(index) {
  nextTick(() => {
    const inputs = document.querySelectorAll('.pin-input')
    if (inputs[index]) inputs[index].focus()
  })
}

function onDigitInput(index, event) {
  const value = event.target.value.replace(/\D/g, '')
  if (value.length > 0) {
    pinDigits.value[index] = value[value.length - 1]
    if (index < pinLength.value - 1) {
      focusInput(index + 1)
    } else {
      // All digits entered, auto-verify
      attemptUnlock()
    }
  }
}

function onDigitKeydown(index, event) {
  if (event.key === 'Backspace') {
    if (pinDigits.value[index] === '' && index > 0) {
      focusInput(index - 1)
    } else {
      pinDigits.value[index] = ''
    }
  }
}

function clearPin() {
  pinDigits.value = ['', '', '', '', '', '']
  focusInput(0)
}

async function attemptUnlock() {
  const pin = activeDigits.value.join('')
  if (pin.length < pinLength.value) return

  isVerifying.value = true
  try {
    const success = await unlockWithPin(pin)
    if (!success) {
      // Shake animation + clear
      setTimeout(() => {
        clearPin()
        isVerifying.value = false
      }, 400)
    } else {
      isVerifying.value = false
    }
  } catch {
    clearPin()
    isVerifying.value = false
  }
}

async function handleBiometric() {
  isVerifying.value = true
  try {
    await unlockWithBiometric()
  } catch {
    // Handled by service
  }
  isVerifying.value = false
}

// Focus first input when lock screen appears
onMounted(() => {
  nextTick(() => {
    focusInput(0)
    // Attempt biometric automatically on mount if available
    if (hasBiometric.value && biometricAvailable.value) {
      setTimeout(handleBiometric, 500)
    }
  })
})
</script>

<template>
  <Teleport to="body">
    <Transition name="lock-fade">
      <div
        v-if="isLocked"
        class="fixed inset-0 z-[999999] flex items-center justify-center bg-surface-900 dark:bg-black overflow-hidden"
      >
        <!-- Animated background blobs -->
        <div class="absolute inset-0 overflow-hidden">
          <div class="lock-blob lock-blob-1"></div>
          <div class="lock-blob lock-blob-2"></div>
          <div class="lock-blob lock-blob-3"></div>
        </div>

        <div class="w-full max-w-sm mx-auto px-6 text-center relative z-10">
          <!-- User avatar -->
          <div class="mb-6 flex justify-center">
            <div class="lock-avatar-wrapper">
              <UserAvatar :email="auth.userEmail" size="3xl" class="lock-avatar ring-2 ring-white/10" />
            </div>
          </div>

          <!-- User name -->
          <p class="text-surface-300 text-sm mb-1">Locked</p>
          <p class="text-white font-medium text-lg mb-8">{{ auth.displayName }}</p>

          <!-- PIN dots -->
          <div class="flex justify-center gap-3 mb-6">
            <div
              v-for="(digit, i) in activeDigits"
              :key="i"
              :class="[
                'w-12 h-12 rounded-xl border-2 flex items-center justify-center transition-all duration-200',
                digit !== '' 
                  ? 'border-primary-500 bg-primary-500/10' 
                  : 'border-surface-600 bg-surface-800/50',
                pinError ? 'animate-shake border-red-500' : ''
              ]"
            >
              <input
                :type="showPin ? 'text' : 'password'"
                :value="digit"
                @input="onDigitInput(i, $event)"
                @keydown="onDigitKeydown(i, $event)"
                maxlength="2"
                inputmode="numeric"
                pattern="[0-9]*"
                class="pin-input w-full h-full bg-transparent text-center text-xl font-bold text-white outline-none"
                :disabled="isVerifying"
              />
            </div>
          </div>

          <!-- Error message -->
          <p v-if="pinError" class="text-red-400 text-sm mb-4">{{ pinError }}</p>

          <!-- Show/hide PIN toggle -->
          <button
            @click="showPin = !showPin"
            class="text-surface-400 hover:text-surface-200 text-sm mb-6 inline-flex items-center gap-1 transition-colors"
          >
            <span class="material-symbols-rounded text-base">{{ showPin ? 'visibility_off' : 'visibility' }}</span>
            {{ showPin ? 'Hide PIN' : 'Show PIN' }}
          </button>

          <!-- Biometric button -->
          <div v-if="hasBiometric && biometricAvailable" class="mb-6">
            <button
              @click="handleBiometric"
              :disabled="isVerifying"
              class="w-full py-3 px-4 rounded-xl bg-surface-800 hover:bg-surface-700 border border-surface-600 text-white font-medium flex items-center justify-center gap-2 transition-colors disabled:opacity-50"
            >
              <span class="material-symbols-rounded text-xl">fingerprint</span>
              Use Face ID / Fingerprint
            </button>
          </div>

          <!-- Unlock button (manual) -->
          <button
            @click="attemptUnlock"
            :disabled="filledCount < pinLength || isVerifying"
            class="w-full py-3 px-4 rounded-xl bg-primary-600 hover:bg-primary-500 text-white font-semibold transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2"
          >
            <span v-if="isVerifying" class="material-symbols-rounded text-xl animate-spin">progress_activity</span>
            <span v-else class="material-symbols-rounded text-xl">lock_open</span>
            {{ isVerifying ? 'Verifying...' : 'Unlock' }}
          </button>

          <!-- Hint -->
          <p class="text-surface-500 text-xs mt-6">
            Enter your PIN to continue
          </p>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.lock-fade-enter-active,
.lock-fade-leave-active {
  transition: opacity 0.3s ease;
}
.lock-fade-enter-from,
.lock-fade-leave-to {
  opacity: 0;
}

/* Enlarged avatar */
.lock-avatar-wrapper {
  width: 120px;
  height: 120px;
}
.lock-avatar-wrapper :deep(.w-20) {
  width: 120px !important;
  height: 120px !important;
  font-size: 2.5rem !important;
}

/* Animated background blobs */
.lock-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(120px);
  opacity: 0.18;
  will-change: transform;
}

.lock-blob-1 {
  width: 500px;
  height: 500px;
  background: #14b8a6;
  top: -15%;
  left: -10%;
  animation: blob-drift-1 22s ease-in-out infinite alternate;
}

.lock-blob-2 {
  width: 450px;
  height: 450px;
  background: #0d9488;
  bottom: -10%;
  right: -10%;
  animation: blob-drift-2 26s ease-in-out infinite alternate;
}

.lock-blob-3 {
  width: 400px;
  height: 400px;
  background: #e11d48;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  opacity: 0.1;
  animation: blob-drift-3 20s ease-in-out infinite alternate;
}

@keyframes blob-drift-1 {
  0% { transform: translate(0, 0) scale(1); }
  50% { transform: translate(60px, 50px) scale(1.08); }
  100% { transform: translate(-30px, 30px) scale(0.96); }
}
@keyframes blob-drift-2 {
  0% { transform: translate(0, 0) scale(1); }
  50% { transform: translate(-50px, -40px) scale(1.06); }
  100% { transform: translate(30px, -20px) scale(0.98); }
}
@keyframes blob-drift-3 {
  0% { transform: translate(-50%, -50%) scale(1); }
  50% { transform: translate(-40%, -60%) scale(1.1); }
  100% { transform: translate(-55%, -45%) scale(0.95); }
}

/* Hide spinner arrows on number inputs */
.pin-input::-webkit-outer-spin-button,
.pin-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.pin-input[type=number] {
  -moz-appearance: textfield;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-6px); }
  40% { transform: translateX(6px); }
  60% { transform: translateX(-4px); }
  80% { transform: translateX(4px); }
}
.animate-shake {
  animation: shake 0.4s ease-in-out;
}
</style>

