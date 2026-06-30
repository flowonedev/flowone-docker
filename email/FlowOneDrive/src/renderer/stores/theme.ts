import { ref, computed } from 'vue'

export type Theme = 'dark' | 'light'

const theme = ref<Theme>((localStorage.getItem('drive_theme') as Theme) || 'dark')
const isDark = computed(() => theme.value === 'dark')

function applyTheme(t: Theme) {
  document.documentElement.setAttribute('data-theme', t)
  document.documentElement.classList.toggle('dark', t === 'dark')
  localStorage.setItem('drive_theme', t)
  // Keep the Electron native window chrome in sync with the theme.
  const w = window as any
  if (w.api && typeof w.api.setNativeTheme === 'function') {
    try { w.api.setNativeTheme(t) } catch (_) { /* not in Electron */ }
  }
}

export function useThemeStore() {
  function init() {
    applyTheme(theme.value)
  }

  function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    applyTheme(theme.value)
  }

  function setTheme(t: Theme) {
    theme.value = t
    applyTheme(t)
  }

  return { theme, isDark, toggleTheme, setTheme, init }
}
