<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import QRCode from 'qrcode'
import logoUrl from '@/assets/flowone-logo.png'

const props = defineProps<{
  errorMessage?: string
}>()

let ssoCleanup: (() => void) | null = null
let deviceStatusCleanup: (() => void) | null = null
const ssoChecking = ref(false)

onMounted(async () => {
  // Listen for SSO authentication from main process
  if ((window as any).api?.sso?.onAuthenticated) {
    ssoChecking.value = true
    ssoCleanup = (window as any).api.sso.onAuthenticated(async () => {
      ssoChecking.value = false
      emit('login-success')
    })
    setTimeout(() => { ssoChecking.value = false }, 2000)
  }

  // Live status of an in-progress "scan to sign in" request.
  if ((window as any).api?.sso?.onDeviceStatus) {
    deviceStatusCleanup = (window as any).api.sso.onDeviceStatus((data: { status: string; error?: string }) => {
      if (data.status === 'approved') {
        // Success also arrives via onAuthenticated -> emit('login-success').
        qrStatus.value = 'approved'
      } else if (data.status === 'denied') {
        qrStatus.value = 'denied'
      } else if (data.status === 'expired') {
        qrStatus.value = 'expired'
      } else if (data.status === 'error') {
        qrStatus.value = 'error'
        qrError.value = data.error || 'Sign-in failed'
      }
    })
  }

  // Returning users: reuse the server resolved at their last login.
  try {
    const cfg = await window.api.getConfig?.()
    if (cfg?.apiUrl) apiUrl.value = String(cfg.apiUrl).replace(/\/+$/, '')
    if (cfg?.userEmail && !email.value) email.value = String(cfg.userEmail)
  } catch {}
})

onUnmounted(() => {
  if (ssoCleanup) { ssoCleanup(); ssoCleanup = null }
  if (deviceStatusCleanup) { deviceStatusCleanup(); deviceStatusCleanup = null }
  // Stop any background polling if the user navigates away mid-request.
  if (qrStatus.value === 'waiting' || qrStatus.value === 'starting') {
    try { (window as any).api?.sso?.cancelDeviceLogin?.() } catch {}
  }
})

const emit = defineEmits<{
  'login-success': []
}>()

// Resolved silently from the email domain (email.<domain>) — no UI field.
const apiUrl = ref('')
const email = ref('')
const password = ref('')
const error = ref(props.errorMessage || '')
const isLoading = ref(false)
const showPassword = ref(false)
// Login mode: 'qr' (scan to sign in, the default) or 'password' (first device).
const mode = ref<'qr' | 'password'>('qr')

// "Scan to sign in" device-flow state.
const qrStatus = ref<'idle' | 'starting' | 'waiting' | 'approved' | 'denied' | 'expired' | 'error'>('idle')
const qrDataUrl = ref('')
const qrMatchNumber = ref('')
const qrVerifyUrl = ref('')
const qrError = ref('')

function pad2(n: number | string): string {
  return String(n).padStart(2, '0')
}

// Begin a device-authorization request: resolve the server from the email
// (multi-domain), ask the backend to create a request, then render the QR and
// the match number. Polling happens in the main process.
async function startQrLogin() {
  qrError.value = ''
  error.value = ''

  if (email.value) {
    const derived = await resolveServerForEmail(email.value)
    if (derived) {
      apiUrl.value = derived
      try { await window.api.setConfig?.('apiUrl', derived) } catch {}
    }
  }
  if (!apiUrl.value) {
    qrError.value = 'Enter your email so we can find your server'
    return
  }

  qrStatus.value = 'starting'
  try {
    const res = await (window as any).api.sso.startDeviceLogin(email.value)
    if (!res?.success) {
      qrStatus.value = 'error'
      qrError.value = res?.error === 'NO_SERVER'
        ? 'Enter your email so we can find your server'
        : (res?.error || 'Could not start sign-in')
      return
    }
    qrMatchNumber.value = pad2(res.matchNumber)
    qrVerifyUrl.value = res.verifyUrl
    qrDataUrl.value = await QRCode.toDataURL(res.verifyUrl, { width: 220, margin: 1 })
    qrStatus.value = 'waiting'
  } catch (e: any) {
    qrStatus.value = 'error'
    qrError.value = e?.message || 'Could not start sign-in'
  }
}

async function cancelQrLogin() {
  try { await (window as any).api.sso.cancelDeviceLogin() } catch {}
  qrStatus.value = 'idle'
  qrDataUrl.value = ''
  qrMatchNumber.value = ''
  qrVerifyUrl.value = ''
  qrError.value = ''
}

// Same-machine path: open the approval page in the default browser, where the
// user is already signed in to the cloud.
function approveInBrowser() {
  if (qrVerifyUrl.value) {
    window.api.openExternalUrl(qrVerifyUrl.value).catch(() => {})
  }
}

function restartQr() {
  qrStatus.value = 'idle'
  qrDataUrl.value = ''
  startQrLogin()
}

watch(() => props.errorMessage, (newVal) => {
  if (newVal) {
    error.value = newVal
  }
})

// 2FA state
const requires2FA = ref(false)
const tempToken = ref('')
const trustDevice = ref(true)

// Individual code inputs for 2FA
const codeInputs = ref<string[]>(['', '', '', '', '', ''])
const codeInputRefs = ref<HTMLInputElement[]>([])

const fullCode = computed(() => codeInputs.value.join(''))

function handleCodeInput(index: number, event: Event) {
  const input = event.target as HTMLInputElement
  const value = input.value.replace(/\D/g, '')
  
  if (value.length > 0) {
    codeInputs.value[index] = value[0]
    if (index < 5 && value[0]) {
      codeInputRefs.value[index + 1]?.focus()
    }
  }
}

function handleCodeKeydown(index: number, event: KeyboardEvent) {
  if (event.key === 'Backspace' && !codeInputs.value[index] && index > 0) {
    codeInputRefs.value[index - 1]?.focus()
  }
}

function handleCodePaste(event: ClipboardEvent) {
  event.preventDefault()
  const pastedData = event.clipboardData?.getData('text').replace(/\D/g, '')
  if (pastedData) {
    for (let i = 0; i < Math.min(pastedData.length, 6); i++) {
      codeInputs.value[i] = pastedData[i]
    }
    const focusIndex = Math.min(pastedData.length, 5)
    codeInputRefs.value[focusIndex]?.focus()
  }
}

// Discovery host this build asks which backend owns a domain. Public build ->
// flowone.pro; white-label builds set VITE_DISCOVERY_HOST to their own server.
const DISCOVERY_HOST = String(
  (import.meta as any).env?.VITE_DISCOVERY_HOST || 'https://flowone.pro'
).replace(/\/+$/, '')

// Convention fallback: email.<domain> (e.g. robert@magyarszinhaz.hu ->
// https://email.magyarszinhaz.hu). Used only when discovery is unreachable.
function serverForEmail(e: string): string {
  if (!e || !e.includes('@')) return ''
  const domain = e.slice(e.lastIndexOf('@') + 1).trim().toLowerCase()
  return domain ? `https://email.${domain}` : ''
}

// Ask the discovery host which backend hosts this email's domain. Shared
// tenants resolve to flowone.pro; dedicated deployments to email.<domain>.
// Falls back to the convention when discovery is unreachable.
async function resolveServerForEmail(e: string): Promise<string> {
  if (!e || !e.includes('@')) return ''
  const domain = e.slice(e.lastIndexOf('@') + 1).trim().toLowerCase()
  if (!domain) return ''
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), 6000)
  try {
    const res = await fetch(
      `${DISCOVERY_HOST}/api/server-discovery?domain=${encodeURIComponent(domain)}`,
      { method: 'GET', headers: { Accept: 'application/json' }, signal: controller.signal }
    )
    if (res.ok) {
      const data = await res.json()
      const url = data?.api_url ?? data?.data?.api_url
      if (typeof url === 'string' && url) return url.replace(/\/+$/, '')
    }
  } catch {
    /* fall through to convention */
  } finally {
    clearTimeout(timer)
  }
  return serverForEmail(e)
}

// Once the user has typed an email, resolve + persist the server so it is
// ready for password, 2FA, and SSO-code logins.
async function handleEmailBlur() {
  const derived = await resolveServerForEmail(email.value)
  if (!derived) return
  apiUrl.value = derived
  try { await window.api.setConfig?.('apiUrl', derived) } catch {}
}

async function handleLogin() {
  if (!email.value || !password.value) {
    error.value = 'Please enter email and password'
    return
  }

  const derived = await resolveServerForEmail(email.value)
  if (!derived) {
    error.value = 'Please enter a valid email address'
    return
  }
  apiUrl.value = derived
  try { await window.api.setConfig?.('apiUrl', derived) } catch {}

  error.value = ''
  isLoading.value = true
  
  try {
    const result = await window.api.login(apiUrl.value, email.value, password.value)
    
    if (result.requires2FA) {
      requires2FA.value = true
      tempToken.value = result.tempToken || ''
      setTimeout(() => {
        codeInputRefs.value[0]?.focus()
      }, 100)
    } else if (result.success) {
      emit('login-success')
    } else {
      error.value = result.error || 'Login failed'
    }
  } catch (e: any) {
    error.value = e.message || 'Connection failed'
  } finally {
    isLoading.value = false
  }
}

async function verify2FA() {
  const code = fullCode.value
  if (code.length !== 6) {
    error.value = 'Please enter the 6-digit code'
    return
  }
  
  error.value = ''
  isLoading.value = true
  
  try {
    const result = await window.api.verify2FA(
      apiUrl.value,
      email.value,
      code,
      tempToken.value,
      trustDevice.value
    )
    
    if (result.success) {
      emit('login-success')
    } else {
      error.value = result.error || 'Invalid verification code'
      codeInputs.value = ['', '', '', '', '', '']
      codeInputRefs.value[0]?.focus()
    }
  } catch (e: any) {
    error.value = e.message || 'Verification failed'
  } finally {
    isLoading.value = false
  }
}

function goBack() {
  requires2FA.value = false
  tempToken.value = ''
  codeInputs.value = ['', '', '', '', '', '']
  error.value = ''
}

</script>

<template>
  <!-- h-screen + overflow-y-auto so small windows scroll instead of clipping
       the form; the inner min-h-full flex keeps it centered on tall windows. -->
  <div class="relative h-screen overflow-y-auto bg-gradient-to-br from-surface-100 to-surface-200 dark:from-surface-900 dark:to-surface-950">
    <div class="min-h-full flex items-center justify-center p-4">
    <div class="w-full max-w-md py-6">
      <!-- Logo/Brand -->
      <div class="text-center mb-8">
        <img :src="logoUrl" alt="FlowOne" class="w-16 h-16 mx-auto mb-4 object-contain" />
        <h1 class="text-2xl font-semibold text-surface-900 dark:text-surface-100">FlowOne Drive</h1>
        <p class="text-surface-500 dark:text-surface-400 mt-1">
          {{ requires2FA ? 'Verify your identity' : 'Sign in to sync your files' }}
        </p>
      </div>
      
      <!-- 2FA Verification -->
      <div v-if="requires2FA" class="card p-8">
        <!-- 2FA Icon and Title -->
        <div class="text-center mb-6">
          <div class="w-14 h-14 mx-auto rounded-xl bg-primary-500/20 flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-2xl text-primary-400">shield</span>
          </div>
          <h2 class="text-xl font-semibold mb-2 text-surface-900 dark:text-surface-100">Two-Factor Authentication</h2>
          <p class="text-surface-500 dark:text-surface-400 text-sm">
            Enter the 6-digit code from your authenticator app
          </p>
        </div>
        
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">Verification Code</label>
        
        <div class="bg-surface-100 dark:bg-surface-900/50 rounded-xl p-4 mb-3">
          <div class="flex justify-center gap-2">
            <input
              v-for="(_, index) in 6"
              :key="index"
              :ref="(el) => { if (el) codeInputRefs[index] = el as HTMLInputElement }"
              type="text"
              inputmode="numeric"
              maxlength="1"
              :value="codeInputs[index]"
              @input="handleCodeInput(index, $event)"
              @keydown="handleCodeKeydown(index, $event)"
              @paste="handleCodePaste"
              class="w-10 h-12 text-center text-xl font-semibold rounded-lg bg-transparent border-2 border-surface-200 dark:border-surface-600 text-surface-900 dark:text-white focus:border-primary-500 focus:outline-none transition-colors placeholder-surface-400"
              placeholder="0"
            />
          </div>
        </div>
        
        <p class="text-surface-500 text-xs mb-5">
          You can also use a backup code (e.g., 1234-5678)
        </p>
        
        <!-- Trust this device toggle -->
        <div class="flex items-center justify-between p-4 rounded-xl bg-surface-100 dark:bg-surface-900/50 border border-surface-200 dark:border-surface-700 mb-6">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-surface-400">devices</span>
            <div>
              <p class="text-sm font-medium text-surface-700 dark:text-surface-200">Trust this device</p>
              <p class="text-xs text-surface-500">Skip 2FA for 7 days on this computer</p>
            </div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="trustDevice"
            @click="trustDevice = !trustDevice"
            :class="[
              'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
              trustDevice ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                trustDevice ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
              ]"
            />
          </button>
        </div>
        
        <!-- Error message -->
        <div v-if="error" class="mb-4 p-4 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30">
          <div class="flex items-center gap-2 text-red-600 dark:text-red-400">
            <span class="material-symbols-rounded text-xl">error</span>
            <p class="text-sm">{{ error }}</p>
          </div>
        </div>
        
        <!-- Verify button -->
        <button
          @click="verify2FA"
          :disabled="isLoading || fullCode.length !== 6"
          class="btn-primary w-full mb-4"
        >
          <span v-if="isLoading" class="spinner"></span>
          <span v-else class="material-symbols-rounded">login</span>
          {{ isLoading ? 'Verifying...' : 'Verify' }}
        </button>
        
        <!-- Back to login -->
        <button
          @click="goBack"
          class="w-full flex items-center justify-center gap-2 text-surface-500 hover:text-surface-700 dark:hover:text-white transition-colors py-2"
        >
          <span class="material-symbols-rounded text-lg">arrow_back</span>
          Back to Login
        </button>
        
        <p class="text-center text-surface-500 text-sm mt-4">
          Signing in as <span class="text-surface-700 dark:text-surface-300">{{ email }}</span>
        </p>
      </div>
      
      <!-- QR sign-in ("scan to sign in") — the default, password-free path -->
      <div v-else-if="mode === 'qr'" class="card p-8">
        <!-- Step 1: email -> generate QR -->
        <template v-if="qrStatus === 'idle' || qrStatus === 'starting' || qrStatus === 'error'">
          <p class="text-sm text-surface-500 dark:text-surface-400 mb-5 text-center leading-relaxed">
            Approve from a device where you're already signed in — no password needed.
          </p>
          <div class="mb-4">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Email Address</label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">mail</span>
              <input
                v-model="email"
                type="email"
                class="input pl-12"
                placeholder="you@example.com"
                autocomplete="email"
                autofocus
                @blur="handleEmailBlur"
                @keyup.enter="startQrLogin"
              />
            </div>
          </div>
          <div v-if="qrError" class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-sm text-red-600 dark:text-red-400 text-center">
            {{ qrError }}
          </div>
          <button
            type="button"
            @click="startQrLogin"
            :disabled="qrStatus === 'starting'"
            class="btn-primary w-full"
          >
            <span v-if="qrStatus === 'starting'" class="spinner"></span>
            <span v-else class="material-symbols-rounded">login</span>
            {{ qrStatus === 'starting' ? 'Preparing...' : 'Sign in' }}
          </button>
        </template>

        <!-- Step 2: waiting for approval -->
        <template v-else-if="qrStatus === 'waiting'">
          <div class="text-center">
            <p v-if="email" class="text-sm text-surface-500 dark:text-surface-400 mb-1">
              Signing in as <span class="font-medium text-surface-700 dark:text-surface-200">{{ email }}</span>
            </p>
            <div class="rounded-xl bg-surface-100 dark:bg-surface-900/50 border border-surface-200 dark:border-surface-700 py-4 mb-4 mt-3">
              <p class="text-xs text-surface-500 mb-1">Your number</p>
              <div class="text-4xl font-bold tracking-[0.3em] text-primary-500">{{ qrMatchNumber }}</div>
            </div>
            <p class="text-sm text-surface-600 dark:text-surface-300 mb-4 leading-relaxed">
              On a device where you're already signed in, a prompt will appear —
              tap this number to approve.
            </p>
            <div class="flex items-center justify-center gap-2 text-sm text-surface-500 mb-4">
              <span class="spinner-sm"></span>
              Waiting for approval...
            </div>

            <!-- Secondary: no prompt? scan the QR on a phone, or open the approval page here. -->
            <details class="text-left rounded-xl bg-surface-100 dark:bg-surface-900/50 border border-surface-200 dark:border-surface-700 px-4 py-3 mb-3">
              <summary class="text-xs text-surface-500 cursor-pointer select-none">No prompt? Approve another way</summary>
              <div class="text-center mt-3">
                <div class="inline-block bg-white p-3 rounded-xl mb-3 shadow-sm">
                  <img :src="qrDataUrl" alt="Sign-in QR code" class="w-40 h-40 block" />
                </div>
                <p class="text-xs text-surface-500 mb-3 leading-relaxed">
                  Scan with your phone, or open the approval page in this computer's browser.
                </p>
                <button type="button" @click="approveInBrowser" class="btn-primary w-full">
                  <span class="material-symbols-rounded">open_in_browser</span>
                  Approve in browser
                </button>
              </div>
            </details>

            <button
              type="button"
              @click="cancelQrLogin"
              class="w-full text-sm text-surface-500 hover:text-surface-700 dark:hover:text-white transition-colors py-1"
            >
              Cancel
            </button>
          </div>
        </template>

        <!-- Step 3: resolved (approved / denied / expired) -->
        <template v-else>
          <div class="text-center py-2">
            <div
              class="w-14 h-14 mx-auto rounded-full flex items-center justify-center mb-4"
              :class="qrStatus === 'approved' ? 'bg-green-500/15' : 'bg-red-500/15'"
            >
              <span
                class="material-symbols-rounded text-3xl"
                :class="qrStatus === 'approved' ? 'text-green-500' : 'text-red-500'"
              >
                {{ qrStatus === 'approved' ? 'check_circle' : (qrStatus === 'expired' ? 'timer_off' : 'block') }}
              </span>
            </div>
            <p class="text-surface-700 dark:text-surface-200 font-medium">
              {{ qrStatus === 'approved' ? 'Approved! Signing you in...' : (qrStatus === 'expired' ? 'The request expired.' : 'Sign-in was denied.') }}
            </p>
            <button
              v-if="qrStatus !== 'approved'"
              type="button"
              @click="restartQr"
              class="btn-primary w-full mt-5"
            >
              <span class="material-symbols-rounded">refresh</span>
              Try again
            </button>
          </div>
        </template>

        <button
          type="button"
          @click="mode = 'password'; cancelQrLogin()"
          class="w-full mt-5 text-xs text-primary-500 hover:text-primary-600 hover:underline text-center"
        >
          Sign in with a password instead
        </button>
      </div>

      <!-- Login form (password / first device) -->
      <form v-else @submit.prevent="handleLogin" class="card p-8">
        <!-- Error message -->
        <div v-if="error" class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30">
          <div class="flex items-center gap-2 text-red-600 dark:text-red-400">
            <span class="material-symbols-rounded text-xl">error</span>
            <p class="text-sm">{{ error }}</p>
          </div>
        </div>
        
        <!-- Email -->
        <div class="mb-5">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Email Address</label>
          <div class="relative">
            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">mail</span>
            <input
              v-model="email"
              type="email"
              class="input pl-12"
              placeholder="you@example.com"
              autocomplete="email"
              autofocus
              @blur="handleEmailBlur"
            />
          </div>
        </div>
        
        <!-- Password -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Password</label>
          <div class="relative">
            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">lock</span>
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              class="input pl-12 pr-12"
              placeholder="••••••••"
              autocomplete="current-password"
            />
            <button
              type="button"
              @click="showPassword = !showPassword"
              class="absolute right-4 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200"
            >
              <span class="material-symbols-rounded text-xl">
                {{ showPassword ? 'visibility_off' : 'visibility' }}
              </span>
            </button>
          </div>
        </div>
        
        <!-- Submit -->
        <button
          type="submit"
          class="btn-primary w-full"
          :disabled="isLoading"
        >
          <span v-if="isLoading" class="spinner"></span>
          <span v-else class="material-symbols-rounded">login</span>
          {{ isLoading ? 'Signing in...' : 'Sign In' }}
        </button>

        <button
          type="button"
          @click="mode = 'qr'"
          class="w-full mt-4 text-xs text-primary-500 hover:text-primary-600 hover:underline text-center flex items-center justify-center gap-1"
        >
          <span class="material-symbols-rounded text-sm">qr_code_2</span>
          Scan to sign in instead
        </button>
      </form>
      
      <!-- SSO Checking Overlay -->
      <div v-if="ssoChecking" class="card p-6 mt-4 text-center">
        <span class="spinner text-primary-500 mb-2"></span>
        <p class="text-sm text-surface-500">Checking login...</p>
      </div>

      <!-- Footer -->
      <p class="text-center text-sm text-surface-500 dark:text-surface-400 mt-6 flex items-center justify-center gap-2">
        <span class="material-symbols-rounded text-base">shield</span>
        Secure drive access powered by Pixel Ranger Studio.
      </p>
    </div>
    </div>
  </div>
</template>
