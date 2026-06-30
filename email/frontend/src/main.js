import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import './assets/styles/main.css'
import { isDebugEnabled } from '@/utils/debug'
import { initConsoleCapture } from '@/utils/logCapture'

// Install console capture FIRST so the ring buffer receives every log/warn/error
// before the debug-gate below may suppress console.log output.
initConsoleCapture()

// Global console.log override - silences console.log output when debug is disabled.
// The logCapture layer above still records the entry; this only suppresses display.
const _capturedLog = console.log.bind(console)
console.log = (...args) => {
  if (isDebugEnabled()) _capturedLog(...args)
}

// Global handler for chunk loading failures (happens after deployments when cached JS references old chunks)
const handleChunkLoadError = async (error) => {
  const chunkFailedMessage = /Loading chunk|Failed to fetch dynamically imported module|Loading module|text\/html/i
  
  if (chunkFailedMessage.test(error?.message || error?.reason?.message || '')) {
    console.warn('Chunk loading failed, likely due to deployment update. Clearing cache...')
    
    // Prevent multiple reload attempts
    if (sessionStorage.getItem('chunk_reload_attempted')) {
      console.error('Chunk reload already attempted, showing error to user')
      return
    }
    sessionStorage.setItem('chunk_reload_attempted', 'true')
    
    try {
      // Unregister service workers
      if ('serviceWorker' in navigator) {
        const registrations = await navigator.serviceWorker.getRegistrations()
        for (const registration of registrations) {
          await registration.unregister()
        }
      }
      // Clear caches
      if ('caches' in window) {
        const cacheNames = await caches.keys()
        for (const cacheName of cacheNames) {
          await caches.delete(cacheName)
        }
      }
    } catch (e) {
      console.error('Failed to clear cache:', e)
    }
    
    // Hard reload
    window.location.reload(true)
  }
}

// Listen for unhandled errors and rejections
window.addEventListener('error', (event) => {
  handleChunkLoadError(event.error || event)
})

window.addEventListener('unhandledrejection', (event) => {
  handleChunkLoadError(event)
})

// Clear the reload flag on successful page load
window.addEventListener('load', () => {
  // Only clear after a short delay to ensure app is fully loaded
  setTimeout(() => {
    sessionStorage.removeItem('chunk_reload_attempted')
  }, 3000)
})

// ==========================================================================
// GLOBAL FIX: Prevent modal backdrop close when dragging to select text
// Problem: @click.self on modal backdrops fires when user mousedowns inside
// an input (to select text) and mouseup lands on the backdrop overlay.
// Fix: Track mousedown target. If mousedown started INSIDE the modal content
// but the click fires ON the backdrop, stop propagation in capture phase
// so Vue's @click.self never sees it. Zero changes needed in modal files.
// ==========================================================================
let _modalFixMouseDownTarget = null

document.addEventListener('mousedown', (e) => {
  _modalFixMouseDownTarget = e.target
}, true)

document.addEventListener('click', (e) => {
  if (_modalFixMouseDownTarget && _modalFixMouseDownTarget !== e.target) {
    // The click target differs from where the mousedown started (= drag)
    // Check if the click landed on a modal backdrop element
    const clickTarget = e.target
    if (clickTarget?.matches?.('.fixed.inset-0, .modal-overlay')) {
      // Mousedown was inside modal content, mouseup was on backdrop - block it
      e.stopPropagation()
    }
  }
  _modalFixMouseDownTarget = null
}, true) // capture phase = runs before Vue's event handlers

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(i18n)
app.use(router)

app.mount('#app')


