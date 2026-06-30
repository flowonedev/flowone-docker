import { defineStore } from "pinia";
import { ref, watch } from "vue";
import api from "@/services/api";
import { getToken, setToken } from "@/services/tokenStorage";

// Track events silently (non-blocking)
async function trackEvent(eventType, eventData = {}) {
  try {
    await api.post('/statistics/log-event', { event_type: eventType, event_data: eventData })
  } catch (e) {
    // Silent fail
  }
}

export const useThemeStore = defineStore("theme", () => {
  // Track which account's theme we're showing (for per-account localStorage)
  // MUST be defined first since getStoredTheme/getStoredAccent depend on it
  const currentAccountId = ref(
    getToken("webmail_active_account") || "primary"
  );

  const isDark = ref(false);
  const loading = ref(false);

  const availableAccents = [
    { id: "green", name: "Emerald", color: "#22c55e" },
    { id: "red", name: "Rose", color: "#ef4444" },
    { id: "purple", name: "Violet", color: "#a855f7" },
    { id: "blue", name: "Sky", color: "#3b82f6" },
    { id: "gold", name: "Amber", color: "#eab308" },
    {
      id: "mono",
      name: "Mono",
      color: "linear-gradient(135deg, #262626 50%, #fafafa 50%)",
    },
    { id: "teal", name: "Teal", color: "#14b8a6" },
    { id: "orange", name: "Sunset", color: "#f97316" },
    {
      id: "gradient",
      name: "Aurora",
      color: "linear-gradient(135deg, #a855f7 0%, #ec4899 50%, #f97316 100%)",
    },
    {
      id: "verdant",
      name: "Verdant",
      color: "linear-gradient(135deg, #a855f7 0%, #2dd4bf 52%, #22c55e 100%)",
    },
  ];

  // Get per-account localStorage key for theme
  function getThemeKey(accountId = null) {
    const id = accountId || currentAccountId.value || "primary";
    return `webmail_theme_${id}`;
  }

  // Get per-account localStorage key for accent
  function getAccentKey(accountId = null) {
    const id = accountId || currentAccountId.value || "primary";
    return `webmail_accent_${id}`;
  }

  // Get stored theme for current account (with fallback to global)
  function getStoredTheme(accountId = null) {
    const key = getThemeKey(accountId);
    return (
      localStorage.getItem(key) ||
      localStorage.getItem("webmail_theme") ||
      "system"
    );
  }

  // Get stored accent for current account (with fallback to global)
  function getStoredAccent(accountId = null) {
    const key = getAccentKey(accountId);
    return (
      localStorage.getItem(key) ||
      localStorage.getItem("webmail_accent") ||
      "green"
    );
  }

  // Initialize theme and accent AFTER helper functions are defined
  const theme = ref(getStoredTheme());
  const accentColor = ref(getStoredAccent());
  const displayDensity = ref(localStorage.getItem('display_density') || 'cosy');
  const ambientBackground = ref(localStorage.getItem('webmail_ambient_bg') === 'true');

  // Title bar / browser chrome colours. Dark matches the manifest theme_color
  // (#1c1c22); light is the app's light surface (white).
  const THEME_COLOR_DARK = "#1c1c22";
  const THEME_COLOR_LIGHT = "#ffffff";

  function updateThemeColorMeta() {
    if (typeof document === "undefined") return;
    const color = isDark.value ? THEME_COLOR_DARK : THEME_COLOR_LIGHT;
    let meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) {
      meta = document.createElement("meta");
      meta.setAttribute("name", "theme-color");
      document.head.appendChild(meta);
    }
    meta.setAttribute("content", color);
  }

  function updateDarkMode() {
    if (theme.value === "dark") {
      isDark.value = true;
    } else if (theme.value === "light") {
      isDark.value = false;
    } else {
      // System preference
      isDark.value = window.matchMedia("(prefers-color-scheme: dark)").matches;
    }

    if (isDark.value) {
      document.documentElement.classList.add("dark");
    } else {
      document.documentElement.classList.remove("dark");
    }

    // Keep the PWA/browser chrome (installed-app title bar, mobile status bar)
    // in sync with the active theme. Desktop Chromium PWAs colour their window
    // title bar from the live <meta name="theme-color"> value, so updating it
    // here makes the title bar switch between light and dark with the app.
    updateThemeColorMeta();

    // In the Electron desktop apps, tell the main process so the native window
    // chrome (title bar / pre-paint background) follows the theme. We pass the
    // mode ('light' | 'dark' | 'system') so OS-following still works.
    // Guarded so the web build is unaffected.
    if (
      typeof window !== "undefined" &&
      window.api &&
      typeof window.api.setNativeTheme === "function"
    ) {
      try {
        window.api.setNativeTheme(theme.value);
      } catch (_) {
        /* ignore */
      }
    }
  }

  function updateAccentColor() {
    document.documentElement.setAttribute("data-accent", accentColor.value);
  }

  function updateDisplayDensity() {
    document.documentElement.classList.remove('density-cosy', 'density-compact');
    document.documentElement.classList.add(`density-${displayDensity.value}`);
  }

  function updateAmbientBackground() {
    if (ambientBackground.value) {
      document.documentElement.classList.add('ambient-bg');
    } else {
      document.documentElement.classList.remove('ambient-bg');
    }
  }

  function setAmbientBackground(enabled, saveToServer = true) {
    ambientBackground.value = enabled;
    localStorage.setItem('webmail_ambient_bg', enabled ? 'true' : 'false');
    updateAmbientBackground();

    if (saveToServer) {
      api.put("/settings", { ambient_background: enabled }).catch((e) => {
        console.error("Failed to save ambient background setting:", e);
      });
      trackEvent('ambient_bg_changed', { enabled })
    }
  }

  function setDisplayDensity(newDensity, saveToServer = true) {
    displayDensity.value = newDensity;
    localStorage.setItem('display_density', newDensity);
    updateDisplayDensity();

    // Save to server
    if (saveToServer) {
      api.put("/settings", { display_density: newDensity }).catch((e) => {
        console.error("Failed to save display density setting:", e);
      });
      trackEvent('density_changed', { density: newDensity });
    }
  }

  function setTheme(newTheme, saveToServer = true) {
    theme.value = newTheme;
    // Save per-account AND global for backwards compatibility
    localStorage.setItem(getThemeKey(), newTheme);
    localStorage.setItem("webmail_theme", newTheme);
    updateDarkMode();

    // Save to server
    if (saveToServer) {
      api.put("/settings", { theme: newTheme }).catch((e) => {
        console.error("Failed to save theme setting:", e);
      });
      // Track theme change
      trackEvent('theme_changed', { theme: newTheme })
    }
  }

  function setAccentColor(newAccent, saveToServer = true) {
    accentColor.value = newAccent;
    // Save per-account AND global for backwards compatibility
    localStorage.setItem(getAccentKey(), newAccent);
    localStorage.setItem("webmail_accent", newAccent);
    updateAccentColor();

    // Save to server
    if (saveToServer) {
      api.put("/settings", { accent_color: newAccent }).catch((e) => {
        console.error("Failed to save accent color setting:", e);
      });
      // Track accent change
      trackEvent('accent_changed', { accent: newAccent })
    }
  }

  function toggleTheme() {
    // Simple 2-way toggle based on current visual state
    if (isDark.value) {
      setTheme("light");
    } else {
      setTheme("dark");
    }
  }

  // Apply settings directly from settings object (called by settings store)
  function applySettings(settings) {
    if (settings.theme) {
      theme.value = settings.theme;
      localStorage.setItem(getThemeKey(), settings.theme);
      updateDarkMode();
    }
    if (settings.accent_color) {
      accentColor.value = settings.accent_color;
      localStorage.setItem(getAccentKey(), settings.accent_color);
      updateAccentColor();
    }
    if (settings.display_density) {
      displayDensity.value = settings.display_density;
      localStorage.setItem('display_density', settings.display_density);
      updateDisplayDensity();
    }
    if (settings.ambient_background !== undefined) {
      ambientBackground.value = !!settings.ambient_background;
      localStorage.setItem('webmail_ambient_bg', ambientBackground.value ? 'true' : 'false');
      updateAmbientBackground();
    }
  }

  // Called when switching accounts - updates the current account ID and loads its theme
  function switchToAccount(accountId) {
    currentAccountId.value = accountId?.toString() || "primary";
    // Load the stored theme for this account
    theme.value = getStoredTheme(currentAccountId.value);
    accentColor.value = getStoredAccent(currentAccountId.value);
    updateDarkMode();
    updateAccentColor();
  }

  // Fetch settings from server (for account switching).
  // Prefers the settings store if already loaded to avoid a redundant API call.
  async function fetchSettings() {
    const token = getToken("webmail_token");
    if (!token) return;

    // Use already-loaded settings store data when available
    try {
      const { useSettingsStore } = await import('@/stores/settings')
      const settingsStore = useSettingsStore()
      if (settingsStore.loaded) {
        applySettings(settingsStore.settings)
        return
      }
    } catch (_) { /* fallback to API */ }

    loading.value = true;
    try {
      const response = await api.get("/settings");
      if (response.data.success) {
        applySettings(response.data.data.settings);
      }
    } catch (e) {
      console.debug("Failed to fetch theme settings:", e.message);
    } finally {
      loading.value = false;
    }
  }

  function initThemeLocal() {
    currentAccountId.value =
      getToken("webmail_active_account") || "primary";

    theme.value = getStoredTheme();
    accentColor.value = getStoredAccent();
    displayDensity.value = localStorage.getItem('display_density') || 'cosy';
    ambientBackground.value = localStorage.getItem('webmail_ambient_bg') === 'true';
    updateDarkMode();
    updateAccentColor();
    updateDisplayDensity();
    updateAmbientBackground();

    window
      .matchMedia("(prefers-color-scheme: dark)")
      .addEventListener("change", () => {
        if (theme.value === "system") {
          updateDarkMode();
        }
      });
  }

  async function initTheme() {
    initThemeLocal();
    await fetchSettings();
  }

  return {
    theme,
    accentColor,
    displayDensity,
    ambientBackground,
    isDark,
    loading,
    currentAccountId,
    availableAccents,
    setTheme,
    setAccentColor,
    setDisplayDensity,
    setAmbientBackground,
    toggleTheme,
    initTheme,
    initThemeLocal,
    fetchSettings,
    applySettings,
    switchToAccount,
  };
});
