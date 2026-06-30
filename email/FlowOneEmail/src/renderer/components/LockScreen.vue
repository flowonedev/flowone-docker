<script setup>
/**
 * LockScreen.vue - Full-screen lock overlay for FlowOneEmail
 * 
 * Shown when:
 * - App has been idle beyond the configured timeout
 * - User manually locks the app
 * - System goes to sleep or lock screen
 * - App is minimized (if lockOnMinimize is enabled)
 * 
 * Supports:
 * - PIN entry (4-8 digit keypad)
 * - Touch ID (macOS only, auto-triggered)
 */
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue'

const props = defineProps({
  userEmail: { type: String, default: '' },
  userName: { type: String, default: '' },
})

const emit = defineEmits(['unlocked'])

const pin = ref('')
const pinLength = ref(6) // We'll detect actual length from stored PIN
const error = ref('')
const isVerifying = ref(false)
const biometricAvailable = ref(false)
const shakeTrigger = ref(false)

// PIN input refs
const pinInputs = ref([])

onMounted(async () => {
  // Check biometric availability
  biometricAvailable.value = await window.api.lock.isBiometricAvailable()
  
  // Auto-trigger biometric if available
  if (biometricAvailable.value) {
    attemptBiometric()
  }

  // Focus first PIN input
  await nextTick()
  focusFirstInput()
})

function focusFirstInput() {
  const inputs = document.querySelectorAll('.pin-digit')
  if (inputs.length > 0) {
    inputs[0].focus()
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
    // User cancelled or failed
  } finally {
    isVerifying.value = false
  }
}

async function handlePinInput(index, event) {
  const value = event.target.value

  // Only allow digits
  if (!/^\d*$/.test(value)) {
    event.target.value = ''
    return
  }

  // Update PIN string
  const chars = pin.value.split('')
  chars[index] = value.slice(-1) // Take last character if pasted
  pin.value = chars.join('')

  // Auto-advance to next input
  if (value && index < pinLength.value - 1) {
    const inputs = document.querySelectorAll('.pin-digit')
    if (inputs[index + 1]) {
      inputs[index + 1].focus()
    }
  }

  // Auto-submit when all digits filled
  const filledDigits = pin.value.replace(/\s/g, '').length
  if (filledDigits >= 4) {
    await tryVerifyPin(filledDigits >= pinLength.value)
  }
}

function handlePinKeydown(index, event) {
  // Handle backspace
  if (event.key === 'Backspace') {
    const chars = pin.value.split('')
    
    if (!chars[index] && index > 0) {
      // If current is empty, go back and clear previous
      chars[index - 1] = ''
      pin.value = chars.join('')
      const inputs = document.querySelectorAll('.pin-digit')
      if (inputs[index - 1]) {
        inputs[index - 1].focus()
        inputs[index - 1].value = ''
      }
      event.preventDefault()
    } else {
      chars[index] = ''
      pin.value = chars.join('')
    }
  }
  
  // Handle paste
  if (event.key === 'v' && (event.ctrlKey || event.metaKey)) {
    // Let paste happen, then process
    setTimeout(() => {
      const pasted = event.target.value
      if (/^\d+$/.test(pasted)) {
        const digits = pasted.slice(0, pinLength.value)
        pin.value = digits.padEnd(pinLength.value, ' ')
        const inputs = document.querySelectorAll('.pin-digit')
        digits.split('').forEach((d, i) => {
          if (inputs[i]) inputs[i].value = d
        })
        if (digits.length >= 4) {
          tryVerifyPin(digits.length >= pinLength.value)
        }
      }
    }, 10)
  }
}

async function tryVerifyPin(showErrorOnFail = false) {
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
      
      // Clear PIN
      pin.value = ''
      const inputs = document.querySelectorAll('.pin-digit')
      inputs.forEach(i => { i.value = '' })
      await nextTick()
      focusFirstInput()
    }
  } catch {
    error.value = 'Verification failed'
  } finally {
    isVerifying.value = false
  }
}

// Get user initials for avatar
function getInitials() {
  if (props.userName) {
    return props.userName.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
  }
  if (props.userEmail) {
    return props.userEmail[0].toUpperCase()
  }
  return '?'
}
</script>

<template>
  <div class="lock-screen-overlay">
    <!-- Background blur layer -->
    <div class="lock-backdrop"></div>
    
    <!-- Lock content -->
    <div class="lock-content">
      <!-- User avatar -->
      <div class="lock-avatar">
        <span class="lock-avatar-text">{{ getInitials() }}</span>
      </div>
      
      <!-- Lock icon -->
      <div class="lock-icon-wrap">
        <span class="material-symbols-rounded lock-icon">lock</span>
      </div>
      
      <!-- User info -->
      <p class="lock-user-name">{{ userName || 'Locked' }}</p>
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
        
        <!-- Error message -->
        <p v-if="error" class="pin-error">
          <span class="material-symbols-rounded text-sm align-middle">error</span>
          {{ error }}
        </p>
      </div>
      
      <!-- Biometric button (macOS Touch ID) -->
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
  /* Allow title bar drag area to still work */
  -webkit-app-region: no-drag;
}

.lock-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.92);
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
  margin-bottom: 4px;
}

.lock-avatar-text {
  font-size: 28px;
  font-weight: 700;
  color: white;
  letter-spacing: -0.5px;
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

.lock-user-name {
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
  transition: background 0.2s, border-color 0.2s;
  margin-top: 8px;
}

.biometric-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.14);
  border-color: rgba(255, 255, 255, 0.2);
}

.biometric-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.biometric-btn .material-symbols-rounded {
  font-size: 22px;
}

/* Shake animation for wrong PIN */
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

