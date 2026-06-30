import { defineStore } from 'pinia'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '@/services/api'
import { getToken } from '@/services/tokenStorage'

// Minimum viewport width (px) required to show the 3-column layout.
// Below this we auto-fallback to the stacked (Gmail-style) layout so the
// email preview pane is never silently squeezed out of view.
// sidebar (256) + min list (~360) + min email view (~480) ≈ 1100
export const COLUMNS_MIN_WIDTH = 1100

export const useLayoutStore = defineStore('layout', () => {
  // Layout modes: 'columns' (3-column), 'stacked' (Gmail-style)
  const mode = ref(localStorage.getItem('layoutMode') || 'columns')

  // Email view sidebar collapse state (icon-rail mode). Persists in localStorage so
  // the user's preference survives reloads. When true, MailboxView renders FolderRail
  // and pops out the full FolderTree on hover-intent.
  const sidebarCollapsed = ref(
    typeof window !== 'undefined' && localStorage.getItem('emailSidebarCollapsed') === 'true'
  )

  // Track viewport state
  const isMobile = ref(typeof window !== 'undefined' && window.innerWidth < 768)
  const isNarrow = ref(typeof window !== 'undefined' && window.innerWidth < COLUMNS_MIN_WIDTH)
  
  // Force stacked layout on mobile/narrow viewports so users always see the
  // email view full-screen when they click a row (instead of a 0px column).
  const isColumnsLayout = computed(() => !isNarrow.value && mode.value === 'columns')
  const isStackedLayout = computed(() => isNarrow.value || mode.value === 'stacked')
  
  function checkMobile() {
    isMobile.value = window.innerWidth < 768
    isNarrow.value = window.innerWidth < COLUMNS_MIN_WIDTH
  }
  
  // Initialize mobile detection
  if (typeof window !== 'undefined') {
    window.addEventListener('resize', checkMobile)
  }
  
  function setLayout(newMode, saveToServer = true) {
    mode.value = newMode
    localStorage.setItem('layoutMode', newMode)
    
    // Save to server
    if (saveToServer) {
      api.put('/settings', { layout_mode: newMode }).catch(e => {
        console.error('Failed to save layout setting:', e)
      })
    }
  }
  
  function toggleLayout() {
    setLayout(mode.value === 'columns' ? 'stacked' : 'columns')
  }

  function setSidebarCollapsed(value) {
    sidebarCollapsed.value = !!value
    try {
      localStorage.setItem('emailSidebarCollapsed', String(sidebarCollapsed.value))
    } catch (_) { /* private mode / quota — ignore */ }
  }

  function toggleSidebarCollapsed() {
    setSidebarCollapsed(!sidebarCollapsed.value)
  }
  
  // Fetch layout setting from server (for account switching).
  // Prefers the settings store if already loaded to avoid a redundant API call.
  async function fetchSettings() {
    const token = getToken('webmail_token')
    if (!token) return

    try {
      const { useSettingsStore } = await import('@/stores/settings')
      const settingsStore = useSettingsStore()
      if (settingsStore.loaded) {
        if (settingsStore.settings.layout_mode) {
          setLayout(settingsStore.settings.layout_mode, false)
        }
        return
      }
    } catch (_) { /* fallback to API */ }
    
    try {
      const response = await api.get('/settings')
      if (response.data.success) {
        const settings = response.data.data.settings
        if (settings.layout_mode) {
          setLayout(settings.layout_mode, false)
        }
      }
    } catch (e) {
      console.debug('Failed to fetch layout settings:', e.message)
    }
  }
  
  async function initLayout() {
    await fetchSettings()
  }

  function applyFromBootstrap(settings) {
    if (settings.layout_mode) {
      setLayout(settings.layout_mode, false)
    }
  }
  
  return {
    mode,
    isMobile,
    isNarrow,
    isColumnsLayout,
    isStackedLayout,
    sidebarCollapsed,
    setLayout,
    toggleLayout,
    setSidebarCollapsed,
    toggleSidebarCollapsed,
    fetchSettings,
    initLayout,
    applyFromBootstrap,
  }
})

