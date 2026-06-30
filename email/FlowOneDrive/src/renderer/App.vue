<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import TitleBar from './components/TitleBar.vue'
import LoginView from './components/LoginView.vue'
import MainView from './components/MainView.vue'
import LockScreen from './components/LockScreen.vue'
import PerfHud from './components/PerfHud.vue'
import { useSyncStore } from './stores/sync'
import { useConfigStore } from './stores/config'

const syncStore = useSyncStore()
const configStore = useConfigStore()

const isLoggedIn = ref(false)
const isLoading = ref(true)
const isAppLocked = ref(false)
const currentView = ref<'files' | 'activity' | 'settings'>('files')
const authErrorMessage = ref('')

onMounted(async () => {
  // Load config
  await configStore.loadConfig()
  
  // Check if logged in
  isLoggedIn.value = !!configStore.config.authToken
  
  // Debug removed
  
  if (isLoggedIn.value) {
    // Start sync status polling
    syncStore.startStatusPolling()
  }
  
  isLoading.value = false
  
  // Listen for navigation events from main process
  const unsubscribeNav = window.api.onNavigate((route) => {
    if (route === '/settings') {
      currentView.value = 'settings'
    }
  })
  
  // Listen for auth-failed events from main process
  const unsubscribeAuth = window.api.onAuthFailed(() => {
    console.log('[App] Auth failed event received - showing login screen')
    handleAuthFailed()
  })
  
  // Listen for SSO authentication from main process (auto-login from sibling apps)
  let unsubscribeSso = () => {}
  if (window.api?.sso?.onAuthenticated) {
    unsubscribeSso = window.api.sso.onAuthenticated(async () => {
      console.log('[App] SSO authenticated - logging in')
      await handleLogin()
    })
  }
  
  // Listen for lock/unlock events from main process
  const unsubscribeLock = window.api.onAppLocked(() => {
    isAppLocked.value = true
  })
  const unsubscribeUnlock = window.api.onAppUnlocked(() => {
    isAppLocked.value = false
  })
  
  // Listen for forced logout
  const unsubscribeForcedLogout = window.api.onForcedLogout(() => {
    handleAuthFailed()
  })
  
  // Check if app was already locked
  window.api.lock.isLocked().then((locked: boolean) => {
    isAppLocked.value = locked
  })
  
  // Listen for debug logs from main process
  const unsubscribeDebug = window.api.onDebugLog((message: string) => {
    console.log(message)
  })
  
  onUnmounted(() => {
    unsubscribeNav()
    unsubscribeAuth()
    unsubscribeSso()
    unsubscribeDebug()
    unsubscribeLock()
    unsubscribeUnlock()
    unsubscribeForcedLogout()
    syncStore.stopStatusPolling()
  })
})

async function handleLogin() {
  isLoggedIn.value = true
  await configStore.loadConfig()
  syncStore.startStatusPolling()
}

async function handleLogout() {
  await window.api.logout()
  isLoggedIn.value = false
  authErrorMessage.value = ''
  syncStore.stopStatusPolling()
  await configStore.loadConfig()
}

function handleAuthFailed() {
  // Auth expired - show login with error message
  isLoggedIn.value = false
  authErrorMessage.value = 'Your session has expired. Please log in again.'
  syncStore.stopStatusPolling()
  configStore.loadConfig()
}
</script>

<template>
  <div class="h-screen flex flex-col overflow-hidden" style="background: var(--bg-main)">
    <TitleBar />
    
    <div v-if="isLoading" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-5xl text-primary-500 animate-spin">sync</span>
        <p class="mt-4 text-surface-400">Loading...</p>
      </div>
    </div>
    
    <template v-else>
      <LoginView 
        v-if="!isLoggedIn" 
        :error-message="authErrorMessage"
        @login-success="handleLogin" 
      />
      
      <MainView 
        v-else 
        v-model:currentView="currentView"
        @logout="handleLogout" 
      />
    </template>
    
    <!-- Lock Screen Overlay -->
    <LockScreen 
      v-if="isAppLocked && isLoggedIn"
      :user-email="configStore.config.userEmail || ''"
      @unlocked="isAppLocked = false"
    />

    <!-- Wave C.5: floating Perf HUD (Ctrl+Shift+P) -->
    <PerfHud />
  </div>
</template>


