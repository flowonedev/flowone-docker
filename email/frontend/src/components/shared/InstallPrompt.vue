<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const showBanner = ref(false)
const deferredPrompt = ref(null)
const isIOS = ref(false)

// Native iOS app — preferred over the PWA "Add to Home Screen" flow now that
// it's published on the App Store.
const APP_STORE_URL = 'https://apps.apple.com/us/app/flowone-pro/id6761392115'

// Check if already dismissed recently (within 7 days)
function isDismissed() {
  const dismissedAt = localStorage.getItem('pwa_install_dismissed')
  if (!dismissedAt) return false
  
  const dismissedDate = new Date(parseInt(dismissedAt))
  const daysSinceDismissed = (Date.now() - dismissedDate) / (1000 * 60 * 60 * 24)
  return daysSinceDismissed < 7
}

// Check if already installed or running in native Capacitor shell
function isStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches ||
         window.navigator.standalone === true ||
         window.Capacitor?.isNativePlatform()
}

// Check if iOS device
function checkIOS() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream
}

function handleBeforeInstallPrompt(e) {
  // Prevent the mini-infobar from appearing on mobile
  e.preventDefault()
  // Save the event for later use
  deferredPrompt.value = e
  
  // Show the install banner if not dismissed and not already installed
  if (!isDismissed() && !isStandalone()) {
    showBanner.value = true
  }
}

async function handleInstallClick() {
  if (!deferredPrompt.value) return
  
  // Show the install prompt
  deferredPrompt.value.prompt()
  
  // Wait for the user's response
  const { outcome } = await deferredPrompt.value.userChoice
  
  if (outcome === 'accepted') {
    showBanner.value = false
  }
  
  // Clear the deferred prompt
  deferredPrompt.value = null
}

function dismissBanner() {
  showBanner.value = false
  localStorage.setItem('pwa_install_dismissed', Date.now().toString())
}

onMounted(() => {
  // Check if iOS
  isIOS.value = checkIOS()
  
  // On iOS, show manual instructions if not standalone
  if (isIOS.value && !isStandalone() && !isDismissed()) {
    showBanner.value = true
  }
  
  // Listen for the beforeinstallprompt event (Chrome, Edge, etc.)
  window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt)
  
  // Hide banner if app gets installed
  window.addEventListener('appinstalled', () => {
    showBanner.value = false
    deferredPrompt.value = null
  })
})

onUnmounted(() => {
  window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt)
})
</script>

<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    leave-active-class="transition-all duration-200 ease-in"
    enter-from-class="translate-y-full opacity-0"
    leave-to-class="translate-y-full opacity-0"
  >
    <div
      v-if="showBanner"
      class="fixed bottom-0 left-0 right-0 z-[100] p-4 pb-safe md:hidden"
    >
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 p-4">
        <div class="flex items-start gap-3">
          <!-- App Icon -->
          <img
            src="/pwa-192x192.png?v=3"
            alt="FlowOne.PRO"
            class="w-12 h-12 rounded-xl flex-shrink-0 object-cover shadow-sm"
          />
          
          <!-- Content -->
          <div class="flex-1 min-w-0">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Install FlowOne.PRO</h3>
            <p v-if="isIOS" class="text-sm text-surface-500 dark:text-surface-400 mt-0.5">
              Get the native app from the App Store
            </p>
            <p v-else class="text-sm text-surface-500 dark:text-surface-400 mt-0.5">
              Add to your home screen for quick access
            </p>
          </div>
          
          <!-- Close Button -->
          <button
            @click="dismissBanner"
            class="text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 -mt-1 -mr-1 p-1"
          >
            <span class="material-symbols-rounded text-xl">close</span>
          </button>
        </div>
        
        <!-- iOS: download the native app from the App Store -->
        <a
          v-if="isIOS"
          :href="APP_STORE_URL"
          target="_blank"
          rel="noopener"
          @click="dismissBanner"
          class="mt-3 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-black text-white py-2.5 font-medium hover:bg-surface-900 transition-colors"
        >
          <svg viewBox="0 0 384 512" class="w-4 h-4 fill-current" aria-hidden="true">
            <path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/>
          </svg>
          Download on the App Store
        </a>

        <!-- Android / Chrome: PWA install -->
        <button
          v-else-if="deferredPrompt"
          @click="handleInstallClick"
          class="mt-3 w-full btn-primary"
        >
          <span class="material-symbols-rounded">download</span>
          Install App
        </button>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.pb-safe {
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}
</style>

