<script setup lang="ts">
/**
 * LockScreen.vue - Full-screen lock overlay for FlowOneDrive
 * 
 * Shown when:
 * - App has been idle beyond the configured timeout
 * - User manually locks the app
 * - System goes to sleep or lock screen
 * - App is minimized (if lockOnMinimize is enabled)
 */
import { ref, onMounted, nextTick } from 'vue'

const props = defineProps<{
  userEmail: string
}>()

const emit = defineEmits<{
  unlocked: []
}>()

const pin = ref('')
const pinLength = 4
const error = ref('')
const isVerifying = ref(false)
const biometricAvailable = ref(false)
const shakeTrigger = ref(false)

onMounted(async () => {
  biometricAvailable.value = await window.api.lock.isBiometricAvailable()
  
  if (biometricAvailable.value) {
    attemptBiometric()
  }

  await nextTick()
  focusFirstInput()
})

function focusFirstInput() {
  const inputs = document.querySelectorAll('.pin-digit')
  if (inputs.length > 0) {
    (inputs[0] as HTMLInputElement).focus()
  }
}

async function attemptBiometric() {
  if (!biometricAvailable.value) return
  
  isVerifying.value = true
  error.value = ''
  
  try {
    const success = await window.api.lock.authenticateBiometric()
    if (success) {
      emit('unlocked')
    }
  } catch {
    // User cancelled
  } finally {
    isVerifying.value = false
  }
}

async function handlePinInput(index: number, event: Event) {
  const target = event.target as HTMLInputElement
  const value = target.value

  if (!/^\d*$/.test(value)) {
    target.value = ''
    return
  }

  const chars = pin.value.split('')
  chars[index] = value.slice(-1)
  pin.value = chars.join('')

  if (value && index < pinLength - 1) {
    const inputs = document.querySelectorAll('.pin-digit')
    if (inputs[index + 1]) {
      (inputs[index + 1] as HTMLInputElement).focus()
    }
  }

  const filledDigits = pin.value.replace(/\s/g, '').length
  if (filledDigits >= 4) {
    await tryVerifyPin(filledDigits >= pinLength)
  }
}

function handlePinKeydown(index: number, event: KeyboardEvent) {
  if (event.key === 'Backspace') {
    const chars = pin.value.split('')
    
    if (!chars[index] && index > 0) {
      chars[index - 1] = ''
      pin.value = chars.join('')
      const inputs = document.querySelectorAll('.pin-digit')
      if (inputs[index - 1]) {
        (inputs[index - 1] as HTMLInputElement).focus();
        (inputs[index - 1] as HTMLInputElement).value = ''
      }
      event.preventDefault()
    } else {
      chars[index] = ''
      pin.value = chars.join('')
    }
  }
}

async function tryVerifyPin(showErrorOnFail: boolean = false) {
  const cleanPin = pin.value.replace(/\s/g, '')
  if (cleanPin.length < 4 || isVerifying.value) return
  
  isVerifying.value = true
  error.value = ''
  
  try {
    const valid = await window.api.lock.verifyPin(cleanPin)
    if (valid) {
      emit('unlocked')
      return
    }
    
    // Only show error and reset when all boxes are filled (or showErrorOnFail)
    if (showErrorOnFail) {
      error.value = 'Incorrect PIN'
      shakeTrigger.value = true
      setTimeout(() => { shakeTrigger.value = false }, 500)
      
      pin.value = ''
      const inputs = document.querySelectorAll('.pin-digit')
      inputs.forEach(i => { (i as HTMLInputElement).value = '' })
      await nextTick()
      focusFirstInput()
    }
  } catch {
    error.value = 'Verification failed'
  } finally {
    isVerifying.value = false
  }
}

function getInitials(): string {
  if (props.userEmail) {
    return props.userEmail[0].toUpperCase()
  }
  return '?'
}
</script>

<template>
  <div class="lock-screen-overlay">
    <div class="lock-backdrop"></div>
    
    <div class="lock-content">
      <!-- Avatar -->
      <div class="lock-avatar">
        <span class="lock-avatar-text">{{ getInitials() }}</span>
      </div>
      
      <!-- Lock icon badge -->
      <div class="lock-icon-wrap">
        <span class="material-symbols-rounded lock-icon">lock</span>
      </div>
      
      <!-- Drive label -->
      <p class="lock-app-label">FlowOne Drive</p>
      <p class="lock-user-email">{{ userEmail }}</p>
      
      <!-- PIN input -->
      <div :class="['pin-container', { 'shake': shakeTrigger }]">
        <p class="pin-label">Enter your PIN to unlock</p>
        <div class="pin-inputs">
          <input
            v-for="i in pinLength"
            :key="i"
            type="password"
            inputmode="numeric"
            maxlength="1"
            class="pin-digit"
            :value="pin[i - 1] || ''"
            @input="handlePinInput(i - 1, $event)"
            @keydown="handlePinKeydown(i - 1, $event)"
            :disabled="isVerifying"
            autocomplete="off"
          />
        </div>
        
        <p v-if="error" class="pin-error">
          <span class="material-symbols-rounded text-sm align-middle">error</span>
          {{ error }}
        </p>
      </div>
      
      <!-- Biometric button -->
      <button
        v-if="biometricAvailable"
        @click="attemptBiometric"
        :disabled="isVerifying"
        class="biometric-btn"
      >
        <span class="material-symbols-rounded">fingerprint</span>
        <span>Use Touch ID</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.lock-screen-overlay {
  position: fixed;
  inset: 0;
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  -webkit-app-region: no-drag;
}

.lock-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.94);
  backdrop-filter: blur(24px);
  -webkit-backdrop-filter: blur(24px);
}

.lock-content {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 48px 40px;
  max-width: 380px;
  width: 100%;
}

.lock-avatar {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  display: flex;
  align-items: center;
  justify-content: center;
}

.lock-avatar-text {
  font-size: 28px;
  font-weight: 700;
  color: white;
}

.lock-icon-wrap {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: -24px;
  margin-left: 48px;
  border: 2px solid rgba(15, 23, 42, 0.9);
}

.lock-icon {
  font-size: 18px;
  color: #fbbf24;
}

.lock-app-label {
  font-size: 20px;
  font-weight: 600;
  color: white;
  margin: 0;
}

.lock-user-email {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.5);
  margin: 0 0 16px 0;
}

.pin-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.pin-label {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.6);
  margin: 0;
}

.pin-inputs {
  display: flex;
  gap: 10px;
}

.pin-digit {
  width: 44px;
  height: 52px;
  border: 2px solid rgba(255, 255, 255, 0.15);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.05);
  color: white;
  font-size: 22px;
  font-weight: 600;
  text-align: center;
  outline: none;
  transition: border-color 0.2s, background 0.2s;
  caret-color: transparent;
  -webkit-app-region: no-drag;
}

.pin-digit:focus {
  border-color: #6366f1;
  background: rgba(99, 102, 241, 0.1);
}

.pin-digit:disabled {
  opacity: 0.5;
}

.pin-error {
  font-size: 13px;
  color: #f87171;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 4px;
}

.biometric-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 24px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: white;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s;
  margin-top: 8px;
}

.biometric-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.14);
}

.biometric-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.shake {
  animation: shake 0.4s ease-in-out;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-10px); }
  40% { transform: translateX(10px); }
  60% { transform: translateX(-8px); }
  80% { transform: translateX(8px); }
}
</style>

