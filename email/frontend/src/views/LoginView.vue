<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import TwoFactorVerify from '@/components/TwoFactorVerify.vue'
import api from '@/services/api'
import { useI18n } from 'vue-i18n'
import { appName as currentAppName, nativeOAuthScheme } from '@/services/electronApi'
import { isIOSNativePlatform } from '@/utils/platform'
import { getServerBase, resolveServerBase } from '@/services/serverRegistry'
import { useDeviceLogin } from '@/composables/useDeviceLogin'
import logoUrl from '@/assets/flowone-logo.png'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const theme = useThemeStore()
const { t } = useI18n()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const errorMessage = ref('')
const googleEnabled = ref(false)
const googleLoading = ref(false)
const microsoftEnabled = ref(false)
const microsoftLoading = ref(false)

// True while we're processing the OAuth provider's callback redirect.
// We render a clean loading screen instead of the sign-in form to avoid the
// confusing "login form flashes for a moment before bouncing to the inbox"
// effect that users hit after completing Google consent.
// Phase 2.2: ?handoff= replaces the legacy ?oauth_success= URL (tokens are
// no longer embedded in the redirect; the frontend POSTs the one-time code
// to retrieve them).
const processingOAuthCallback = ref(!!(route.query.handoff || route.query.oauth_success || route.query.oauth_error))

// 2FA state
const requires2FA = ref(false)
const tempToken = ref('')
const twoFactorRef = ref(null)

// App branding (differs for email vs chat desktop apps). The icon is
// now the shared FlowOne PNG logo (rendered via <img> in the template),
// so this only needs to drive the title + subtitle.
const brandConfig = computed(() => {
  if (currentAppName?.startsWith('chat')) {
    return { title: 'FlowOne Chat', subtitle: t('loginView.signInToYourAccount') }
  }
  return { title: 'FlowOne Email', subtitle: t('loginView.signInToYourAccount') }
})

// Footer text varies by app
const footerText = computed(() => {
  const service = currentAppName?.startsWith('chat') ? 'chat' : 'email'
  return t(`loginView.secureAccessPoweredBy.${service}`, { company: 'Pixel Ranger Studio' })
})

// SSO state (Electron only)
const isElectronApp = ref(typeof window !== 'undefined' && !!window.api)
const isCapacitorNative = typeof window !== 'undefined' && !!window.Capacitor?.isNativePlatform?.()
// App Store Guideline 4.8: third-party (Google/Microsoft) login is not offered
// on the native iOS build, so Sign in with Apple is not required and no system
// browser handoff occurs. Web, Electron desktop, and Android keep OAuth.
const isIOSNative = isIOSNativePlatform()
const ssoChecking = ref(false)
const ssoCodeInput = ref('')
const ssoCodeLoading = ref(false)
let ssoCleanup = null

// "Scan to sign in" (Capability B) — native only. The phone shows a QR + a
// 2-digit number that another already-signed-in device approves.
const canDeviceLogin = isCapacitorNative
const loginMode = ref('password') // 'password' | 'qr'
const {
  status: deviceStatus,
  qrDataUrl: deviceQr,
  matchNumber: deviceNumber,
  errorMsg: deviceErrorCode,
  start: startDeviceLogin,
  cancel: cancelDeviceLogin,
} = useDeviceLogin()

const deviceErrorMessages = {
  EMAIL_REQUIRED: () => t('loginView.pleaseEnterEmailAndPassword'),
  SERVER_UNREACHABLE: () => t('loginView.qrServerUnreachable'),
  START_FAILED: () => t('loginView.qrError'),
  EXCHANGE_FAILED: () => t('loginView.qrError'),
  DEVICE_REQUEST_INVALID: () => t('loginView.qrError'),
  DEVICE_POLL_INVALID: () => t('loginView.qrError'),
}

// Human message for the QR panel's terminal/error states.
const deviceMessage = computed(() => {
  if (deviceStatus.value === 'expired') return t('loginView.qrExpired')
  if (deviceStatus.value === 'denied') return t('loginView.qrDenied')
  if (deviceStatus.value === 'error') {
    const fn = deviceErrorMessages[deviceErrorCode.value]
    return fn ? fn() : t('loginView.qrError')
  }
  return ''
})

function padNumber(n) {
  return String(n ?? '').padStart(2, '0')
}

function enterQrMode() {
  errorMessage.value = ''
  loginMode.value = 'qr'
}

function exitQrMode() {
  cancelDeviceLogin()
  loginMode.value = 'password'
}

function submitDeviceLogin() {
  errorMessage.value = ''
  if (!email.value) {
    errorMessage.value = t('loginView.pleaseEnterEmailAndPassword')
    return
  }
  startDeviceLogin(email.value)
}

// Once another device approves, the composable has set the session tokens —
// navigate just like a normal login.
watch(deviceStatus, (s) => {
  if (s === 'approved') navigateToMailbox()
})

// Force default green accent and cosy density on the login page
// (previous user's accent/density leaks from localStorage)
theme.setAccentColor('green', false)
theme.setDisplayDensity('cosy', false)

// Check for OAuth callback on mount
onMounted(async () => {
  // In Electron, listen for SSO authentication from main process
  if (isElectronApp.value && window.api?.sso?.onAuthenticated) {
    ssoChecking.value = true
    ssoCleanup = window.api.sso.onAuthenticated(async (userData) => {
      ssoChecking.value = false
      // SSO authenticated, reinitialize auth and navigate
      await auth.initFromElectron?.()
      await navigateToMailbox()
    })
    // Wait up to 2s for SSO, then show login form
    setTimeout(() => { ssoChecking.value = false }, 2000)
  }

  // Third-party login is intentionally hidden on native iOS (App Store
  // Guideline 4.8). On other native (Capacitor Android) the backend is unknown
  // until the user types their email, so we defer the probe to handleEmailBlur.
  // Web/Electron know their origin immediately, so probe on mount.
  if (!isCapacitorNative || getServerBase()) {
    await probeOAuthProviders()
  }
  
  // Phase 2.2: handoff flow. The backend redirects to /login?handoff=<code>
  // after a successful OAuth login. We exchange the single-use code for the
  // actual token payload via POST so the tokens never appear in URLs,
  // browser history, or server access logs.
  const handoffCode = route.query.handoff
  if (handoffCode) {
    try {
      const response = await api.post('/auth/oauth/handoff', { code: String(handoffCode) })
      const tokenData = response.data?.data || response.data
      if (!tokenData || !tokenData.access_token) {
        throw new Error('Missing tokens in handoff response')
      }
      auth.setTokens(tokenData)
      window.location.href = '/inbox'
      return
    } catch (e) {
      errorMessage.value = t('loginView.failedToProcessOauthLogin')
      processingOAuthCallback.value = false
    }
  }

  // Legacy fallback (kept for Redis-outage degraded path on the backend).
  const oauthSuccess = route.query.oauth_success
  if (oauthSuccess) {
    try {
      const tokenData = JSON.parse(atob(oauthSuccess))
      auth.setTokens(tokenData)
      window.location.href = '/inbox'
      return
    } catch (e) {
      errorMessage.value = t('loginView.failedToProcessOauthLogin')
      processingOAuthCallback.value = false
    }
  }
  
  // Handle idle logout redirect
  if (route.query.reason === 'idle') {
    errorMessage.value = t('loginView.loggedOutDueToInactivity')
    router.replace({ name: 'login' })
  }
  
  // Handle OAuth error callback
  const oauthError = route.query.oauth_error
  if (oauthError) {
    const errorMessages = {
      'access_denied': t('loginView.oauthErrors.accessDenied'),
      'no_code': t('loginView.oauthErrors.noCode'),
      'invalid_state': t('loginView.oauthErrors.invalidState'),
      'state_expired': t('loginView.oauthErrors.stateExpired'),
      'token_exchange_failed': t('loginView.oauthErrors.tokenExchangeFailed'),
      'user_info_failed': t('loginView.oauthErrors.userInfoFailed'),
      'oauth_not_configured': t('loginView.oauthErrors.oauthNotConfigured'),
    }
    errorMessage.value = errorMessages[oauthError] || oauthError || t('loginView.oauthLoginFailed')
    // Drop the OAuth error from the URL and let the form re-render with the message.
    processingOAuthCallback.value = false
    router.replace({ name: 'login' })
  }
})

// Probe whether Google / Microsoft OAuth login is available on this server.
// On native iOS these stay hidden (App Store Guideline 4.8).
async function probeOAuthProviders() {
  if (isIOSNative) return
  try {
    const response = await api.get('/auth/google/enabled')
    googleEnabled.value = response.data.data?.enabled || false
  } catch (e) {
    // Google OAuth not available
  }
  try {
    const response = await api.get('/auth/microsoft/enabled')
    microsoftEnabled.value = response.data.data?.enabled || false
  } catch (e) {
    // Microsoft OAuth not available
  }
}

// Native: once the user has typed an email, resolve which backend owns the
// domain (via discovery — flowone.pro for shared tenants, email.<domain> for
// dedicated deployments) and probe OAuth availability against that server.
async function handleEmailBlur() {
  if (!isCapacitorNative) return
  const base = await resolveServerBase(email.value)
  if (!base) return
  if (!isIOSNative) await probeOAuthProviders()
}

// Check if we're on iOS Safari (but NOT in Capacitor native shell)
function isIOSSafari() {
  if (window.Capacitor?.isNativePlatform()) return false
  const ua = navigator.userAgent
  const iOS = /iPad|iPhone|iPod/.test(ua)
  const webkit = /WebKit/.test(ua)
  const notChrome = !/CriOS/.test(ua)
  return iOS && webkit && notChrome
}

// Helper to navigate with iOS fallback.
// Always target /inbox directly so we never bounce through "/" -> perspective
// redirect -> /inbox; that intermediate step is what made users feel they
// were being sent to the marketing landing page after Google OAuth.
// Safe post-login destination. Honors ?redirect= (used by the device-approval
// deep link) but only for internal paths, never an absolute/protocol URL, to
// avoid an open-redirect.
function postLoginPath() {
  const r = route.query.redirect
  if (typeof r === 'string' && r.startsWith('/') && !r.startsWith('//')) {
    return r
  }
  return '/inbox'
}

async function navigateToMailbox() {
  const dest = postLoginPath()

  // On iOS Safari (not Capacitor), use hard navigation to avoid blank screen issues
  if (isIOSSafari()) {
    // Verify token was saved to localStorage before navigating
    let attempts = 0
    const maxAttempts = 10
    while (attempts < maxAttempts) {
      const { getToken } = await import('@/services/tokenStorage')
      const savedToken = getToken('webmail_token')
      if (savedToken) {
        break
      }
      await new Promise(resolve => setTimeout(resolve, 100))
      attempts++
    }
    
    // Final delay before navigation
    await new Promise(resolve => setTimeout(resolve, 300))
    
    window.location.href = dest
    return
  }
  
  try {
    await router.replace(dest)
    // Give router time to complete navigation
    await new Promise(resolve => setTimeout(resolve, 100))
    // If still on login page, use fallback hard navigation
    if (window.location.pathname === '/login' || window.location.pathname === '/login/') {
      window.location.href = dest
    }
  } catch (e) {
    // Router failed, use hard navigation
    console.warn('Router navigation failed, using fallback:', e)
    window.location.href = dest
  }
}

async function handleLogin() {
  errorMessage.value = ''
  
  if (!email.value || !password.value) {
    errorMessage.value = t('loginView.pleaseEnterEmailAndPassword')
    return
  }
  
  const result = await auth.login(email.value, password.value)
  
  if (result === true) {
    await navigateToMailbox()
  } else if (result && result.requires_2fa) {
    // 2FA required
    requires2FA.value = true
    tempToken.value = result.temp_token
  } else {
    errorMessage.value = auth.error || t('loginView.loginFailed')
  }
}

async function handleGoogleLogin() {
  errorMessage.value = ''
  googleLoading.value = true
  
  // In Electron, use IPC OAuth BrowserWindow
  if (isElectronApp.value && window.api?.oauth?.start) {
    try {
      const result = await window.api.oauth.start('google')
      googleLoading.value = false
      if (result.success) {
        await auth.initFromElectron?.()
        await navigateToMailbox()
      } else {
        errorMessage.value = result.error === 'OAUTH_CANCELLED' 
          ? 'Login cancelled' 
          : (result.error || t('loginView.googleLoginNotAvailable'))
      }
    } catch (e) {
      errorMessage.value = e.message || t('loginView.googleLoginNotAvailable')
      googleLoading.value = false
    }
    return
  }
  
  try {
    const params = {}
    if (isCapacitorNative && nativeOAuthScheme) {
      params.redirect_scheme = nativeOAuthScheme
    }
    const response = await api.get('/auth/google/login', { params })
    if (response.data.success && response.data.data.auth_url) {
      if (isCapacitorNative && nativeOAuthScheme) {
        const { openOAuthBrowser } = await import('@/services/electronApi')
        await openOAuthBrowser(response.data.data.auth_url)
        googleLoading.value = false
      } else {
        window.location.href = response.data.data.auth_url
      }
    } else {
      errorMessage.value = t('loginView.failedToInitializeGoogleLogin')
      googleLoading.value = false
    }
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('loginView.googleLoginNotAvailable')
    googleLoading.value = false
  }
}

async function handleMicrosoftLogin() {
  errorMessage.value = ''
  microsoftLoading.value = true
  
  // In Electron, use IPC OAuth BrowserWindow
  if (isElectronApp.value && window.api?.oauth?.start) {
    try {
      const result = await window.api.oauth.start('microsoft')
      microsoftLoading.value = false
      if (result.success) {
        await auth.initFromElectron?.()
        await navigateToMailbox()
      } else {
        errorMessage.value = result.error === 'OAUTH_CANCELLED'
          ? 'Login cancelled'
          : (result.error || t('loginView.microsoftLoginNotAvailable'))
      }
    } catch (e) {
      errorMessage.value = e.message || t('loginView.microsoftLoginNotAvailable')
      microsoftLoading.value = false
    }
    return
  }
  
  try {
    const params = {}
    if (isCapacitorNative && nativeOAuthScheme) {
      params.redirect_scheme = nativeOAuthScheme
    }
    const response = await api.get('/auth/microsoft/login', { params })
    if (response.data.success && response.data.data.auth_url) {
      if (isCapacitorNative && nativeOAuthScheme) {
        const { openOAuthBrowser } = await import('@/services/electronApi')
        await openOAuthBrowser(response.data.data.auth_url)
        microsoftLoading.value = false
      } else {
        window.location.href = response.data.data.auth_url
      }
    } else {
      errorMessage.value = t('loginView.failedToInitializeMicrosoftLogin')
      microsoftLoading.value = false
    }
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('loginView.microsoftLoginNotAvailable')
    microsoftLoading.value = false
  }
}

const ssoErrorMessages = {
  SSO_CODE_INVALID: 'Invalid connection code. Please check and try again.',
  SSO_CODE_EXPIRED: 'This code has expired. Generate a new one from the web app.',
  SSO_CODE_USED: 'This code has already been used. Generate a new one.',
  SSO_NONCE_MISMATCH: 'Security mismatch. Please generate a new code.',
  SSO_RATE_LIMITED: 'Too many attempts. Please wait a moment and try again.',
  SSO_SEED_INVALID: 'Connection failed. Please generate a new code.',
}

async function handleSSOCodeLogin() {
  if (!ssoCodeInput.value || ssoCodeInput.value.length < 8) return
  ssoCodeLoading.value = true
  errorMessage.value = ''
  try {
    const result = await window.api.sso.exchangeCode(ssoCodeInput.value.trim())
    if (result.success) {
      await auth.initFromElectron?.()
      await navigateToMailbox()
    } else {
      const rawError = result.error || 'Unknown error'
      errorMessage.value = ssoErrorMessages[rawError] || rawError
    }
  } catch (e) {
    errorMessage.value = e.message || 'Code exchange failed'
  } finally {
    ssoCodeLoading.value = false
  }
}

async function handle2FAVerify({ code, trustDevice }) {
  try {
    twoFactorRef.value?.setLoading(true)
    
    const response = await api.post('/2fa/login', {
      email: email.value,
      code: code,
      temp_token: tempToken.value,
      trust_device: trustDevice,
    })
    
    if (response.data.success) {
      // Store tokens first
      auth.setTokens(response.data.data)
      
      // If device is trusted, store the device token
      if (response.data.data.device_token) {
        localStorage.setItem('webmail_device_token', response.data.data.device_token)
      }
      
      // Store session token for session tracking
      if (response.data.data.session_token) {
        const { setToken } = await import('@/services/tokenStorage')
        setToken('webmail_session_token', response.data.data.session_token)
      }
      
      // Give extra time for localStorage to sync on iOS Safari
      const delay = isIOSSafari() ? 500 : 100
      await new Promise(resolve => setTimeout(resolve, delay))
      
      // Stop loading before navigating
      twoFactorRef.value?.setLoading(false)
      
      // Navigate to mailbox
      await navigateToMailbox()
    } else {
      twoFactorRef.value?.setLoading(false)
      twoFactorRef.value?.setError(response.data.message || t('loginView.invalidCode'))
    }
  } catch (e) {
    twoFactorRef.value?.setLoading(false)
    twoFactorRef.value?.setError(e.response?.data?.message || t('loginView.verificationFailed'))
  }
}

function cancel2FA() {
  requires2FA.value = false
  tempToken.value = ''
  password.value = ''
}

onUnmounted(() => {
  if (ssoCleanup) { ssoCleanup(); ssoCleanup = null }
  cancelDeviceLogin()
})
</script>

<template>
  <div class="relative min-h-screen flex items-center justify-center bg-gradient-to-br from-surface-100 to-surface-200 dark:from-surface-900 dark:to-surface-950 p-4">
    <!-- Theme toggle -->
    <button 
      @click="theme.toggleTheme()"
      class="absolute top-12 right-4 btn-ghost btn-icon"
    >
      <span class="material-symbols-rounded text-2xl">
        {{ theme.isDark ? 'light_mode' : 'dark_mode' }}
      </span>
    </button>
    
    <div class="w-full max-w-md">
      <!-- Logo/Brand -->
      <div class="text-center mb-8">
        <img
          :src="logoUrl"
          alt="FlowOne.Pro"
          class="w-16 h-16 mx-auto mb-4 object-contain"
        />
        <h1 class="text-2xl font-semibold text-surface-900 dark:text-surface-100">{{ brandConfig.title }}</h1>
        <p class="text-surface-500 dark:text-surface-400 mt-1">{{ brandConfig.subtitle }}</p>
      </div>
      
      <!-- OAuth callback in progress: clean loading screen instead of the form
           flashing while we set tokens and hard-redirect to /inbox. -->
      <div v-if="processingOAuthCallback" class="card p-8 text-center">
        <span class="spinner text-primary-500 mb-4"></span>
        <p class="text-sm text-surface-600 dark:text-surface-300">
          {{ $t('loginView.signingYouIn') }}
        </p>
      </div>

      <!-- 2FA Verification -->
      <div v-else-if="requires2FA" class="card p-8">
        <TwoFactorVerify
          ref="twoFactorRef"
          :email="email"
          @verify="handle2FAVerify"
          @cancel="cancel2FA"
        />
      </div>
      
      <!-- Login form -->
      <form v-else-if="loginMode === 'password'" @submit.prevent="handleLogin" class="card p-8">
        <!-- Error message -->
        <div v-if="errorMessage" class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30">
          <div class="flex items-center gap-2 text-red-600 dark:text-red-400">
            <span class="material-symbols-rounded text-xl">error</span>
            <p class="text-sm">{{ errorMessage }}</p>
          </div>
        </div>
        
        <!-- Email -->
        <div class="mb-5">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
            {{ $t('loginView.emailAddress') }}
          </label>
          <div class="relative">
            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">
              mail
            </span>
            <input
              v-model="email"
              type="email"
              class="input pl-12"
              :placeholder="$t('loginView.youexamplecom')"
              autocomplete="email"
              autofocus
              @blur="handleEmailBlur"
            />
          </div>
        </div>
        
        <!-- Password -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
            {{ $t('loginView.password') }}
          </label>
          <div class="relative">
            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">
              lock
            </span>
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
          :disabled="auth.loading"
        >
          <span v-if="auth.loading" class="spinner"></span>
          <span v-else class="material-symbols-rounded">login</span>
          {{ auth.loading ? $t('loginView.signingIn') : $t('loginView.signIn') }}
        </button>
        
        <!-- OAuth Login Options -->
        <template v-if="googleEnabled || microsoftEnabled">
          <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
              <div class="w-full border-t border-surface-200 dark:border-surface-700"></div>
            </div>
            <div class="relative flex justify-center text-sm">
              <span class="px-3 bg-white dark:bg-surface-800 text-surface-500">{{ $t('loginView.orContinueWith') }}</span>
            </div>
          </div>
          
          <div class="space-y-3">
            <!-- Google Login -->
            <button
              v-if="googleEnabled"
              type="button"
              @click="handleGoogleLogin"
              :disabled="googleLoading"
              class="w-full flex items-center justify-center gap-3 px-4 py-3 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-700 dark:text-surface-200 font-medium hover:bg-surface-50 dark:hover:bg-surface-600 transition-colors"
            >
              <span v-if="googleLoading" class="spinner"></span>
              <template v-else>
                <svg class="w-5 h-5" viewBox="0 0 24 24">
                  <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                  <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                  <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                  <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                {{ $t('loginView.signInWithGoogle') }}
              </template>
            </button>
            
            <!-- Microsoft Login -->
            <button
              v-if="microsoftEnabled"
              type="button"
              @click="handleMicrosoftLogin"
              :disabled="microsoftLoading"
              class="w-full flex items-center justify-center gap-3 px-4 py-3 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-700 dark:text-surface-200 font-medium hover:bg-surface-50 dark:hover:bg-surface-600 transition-colors"
            >
              <span v-if="microsoftLoading" class="spinner"></span>
              <template v-else>
                <svg class="w-5 h-5" viewBox="0 0 23 23">
                  <path fill="#f35325" d="M1 1h10v10H1z"/>
                  <path fill="#81bc06" d="M12 1h10v10H12z"/>
                  <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                  <path fill="#ffba08" d="M12 12h10v10H12z"/>
                </svg>
                {{ $t('loginView.signInWithMicrosoft') }}
              </template>
            </button>
          </div>
        </template>

        <!-- Native: switch to "scan / approve from another device" sign-in -->
        <button
          v-if="canDeviceLogin"
          type="button"
          @click="enterQrMode"
          class="mt-6 w-full flex items-center justify-center gap-2 text-sm font-medium text-primary-500 hover:text-primary-600 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">qr_code_2</span>
          {{ $t('loginView.signInWithAnotherDevice') }}
        </button>
      </form>

      <!-- "Scan to sign in" QR panel (Capability B, native only) -->
      <div v-else-if="loginMode === 'qr'" class="card p-8">
        <div v-if="errorMessage" class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30">
          <div class="flex items-center gap-2 text-red-600 dark:text-red-400">
            <span class="material-symbols-rounded text-xl">error</span>
            <p class="text-sm">{{ errorMessage }}</p>
          </div>
        </div>

        <!-- Approved: signing in -->
        <div v-if="deviceStatus === 'approved'" class="text-center py-6">
          <span class="spinner text-primary-500 mb-4"></span>
          <p class="text-sm text-surface-600 dark:text-surface-300">{{ $t('loginView.signingYouIn') }}</p>
        </div>

        <!-- Pending: show the account + match number to approve on a signed-in device -->
        <div v-else-if="deviceStatus === 'pending'" class="text-center">
          <div class="w-14 h-14 mx-auto rounded-xl bg-primary-500/15 flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-3xl text-primary-500">devices</span>
          </div>
          <p class="text-sm text-surface-500 dark:text-surface-400 mb-1">{{ $t('loginView.deviceSigningInAs') }}</p>
          <p class="text-base font-semibold text-surface-900 dark:text-surface-100 mb-6 break-all">{{ email }}</p>

          <p class="text-sm text-surface-600 dark:text-surface-300 mb-2">{{ $t('loginView.deviceTapThisNumber') }}</p>
          <div class="text-6xl font-bold tracking-widest text-primary-500 mb-6">
            {{ padNumber(deviceNumber) }}
          </div>

          <p class="text-sm text-surface-500 dark:text-surface-400 mb-5">{{ $t('loginView.deviceApproveInstruction') }}</p>

          <div class="flex items-center justify-center gap-2 text-sm text-surface-500">
            <span class="spinner-sm"></span>
            {{ $t('loginView.qrWaiting') }}
          </div>
        </div>

        <!-- Idle / starting / error / expired / denied: email + start button -->
        <div v-else>
          <p class="text-sm text-surface-600 dark:text-surface-300 mb-5">{{ $t('loginView.deviceIntro') }}</p>

          <div v-if="deviceMessage" class="mb-5 p-3 rounded-lg bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 text-sm text-amber-700 dark:text-amber-400 text-center">
            {{ deviceMessage }}
          </div>

          <div class="mb-6">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
              {{ $t('loginView.emailAddress') }}
            </label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">mail</span>
              <input
                v-model="email"
                type="email"
                class="input pl-12"
                :placeholder="$t('loginView.youexamplecom')"
                autocomplete="email"
                @blur="handleEmailBlur"
                @keyup.enter="submitDeviceLogin"
              />
            </div>
          </div>

          <button
            type="button"
            class="btn-primary w-full"
            :disabled="deviceStatus === 'starting'"
            @click="submitDeviceLogin"
          >
            <span v-if="deviceStatus === 'starting'" class="spinner"></span>
            <span v-else class="material-symbols-rounded">login</span>
            {{ deviceStatus === 'starting' ? $t('loginView.signingIn') : $t('loginView.startDeviceLogin') }}
          </button>
        </div>

        <!-- Back to password sign-in -->
        <button
          type="button"
          @click="exitQrMode"
          class="mt-6 w-full flex items-center justify-center gap-2 text-sm font-medium text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">password</span>
          {{ $t('loginView.usePasswordInstead') }}
        </button>
      </div>

      <!-- SSO Code Login (Electron only) -->
      <div v-if="isElectronApp && !requires2FA" class="card p-6 mt-4">
        <p class="text-sm text-surface-500 dark:text-surface-400 mb-3 text-center">Have a connection code from the web app?</p>
        <div class="flex gap-2">
          <input
            v-model="ssoCodeInput"
            type="text"
            class="input flex-1 text-center font-mono tracking-widest"
            placeholder="Enter code"
            maxlength="12"
            @keyup.enter="handleSSOCodeLogin"
          />
          <button
            @click="handleSSOCodeLogin"
            :disabled="ssoCodeLoading || !ssoCodeInput"
            class="px-4 py-2 rounded-full bg-primary-500 text-white font-medium hover:bg-primary-600 transition-colors disabled:opacity-50"
          >
            <span v-if="ssoCodeLoading" class="spinner-sm"></span>
            <span v-else class="material-symbols-rounded text-sm">arrow_forward</span>
          </button>
        </div>
      </div>

      <!-- SSO Checking Overlay (Electron) -->
      <div v-if="ssoChecking" class="card p-6 mt-4 text-center">
        <span class="spinner text-primary-500 mb-2"></span>
        <p class="text-sm text-surface-500">Checking login...</p>
      </div>

      <!-- Footer -->
      <p class="text-center text-sm text-surface-500 dark:text-surface-400 mt-6 flex items-center justify-center gap-2">
        <span class="material-symbols-rounded text-base">shield</span>
        {{ footerText }}
      </p>
    </div>
  </div>
</template>

