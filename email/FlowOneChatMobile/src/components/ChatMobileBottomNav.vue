<script setup>
/*
 * Chat-app mobile bottom nav.
 *
 * Drop-in replacement for the shared `@/components/MobileBottomNav.vue` (wired
 * via a Vite alias in vite.config.ts). The shared one renders the full
 * suite nav — Email, Calendar, Boards, Team, etc. — which has no place in the
 * Chat app. This trims it to the destinations the Chat app exposes: Chat,
 * Drive and Settings.
 *
 * Settings used to live in a header dropdown that rendered off-screen on
 * phones; it now opens as a slide-up bottom sheet from this nav.
 */
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useNotificationsStore } from '@/stores/notifications'
import { useThemeStore } from '@/stores/theme'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n'

// Accepted only for API parity with the shared MobileBottomNav (ChatView /
// DriveView pass :show-todo-button="false"); the Chat app has no todo panel.
defineProps({
  showTodoButton: { type: Boolean, default: false },
})

const router = useRouter()
const route = useRoute()
const chatStore = useChatStore()
const notificationsStore = useNotificationsStore()
const theme = useThemeStore()
const { locale } = useI18n()

const chatBadgeCount = computed(
  () => (chatStore.totalUnread || 0) + (notificationsStore.missedCallUnreadCount || 0)
)

const isChatActive = computed(() => route.path === '/chat')
const isDriveActive = computed(() => route.path.startsWith('/drive'))
const isSettingsActive = computed(() => route.path.startsWith('/settings'))

// Settings bottom sheet — replaces the old header settings dropdown.
const showSettingsSheet = ref(false)

const availableLanguages = [
  { code: 'en', label: 'English' },
  { code: 'hu', label: 'Magyar' },
]

async function switchLanguage(code) {
  await setLocale(code)
}

function openFullSettings() {
  showSettingsSheet.value = false
  router.push('/settings')
}
</script>

<template>
  <nav class="mobile-bottom-nav">
    <!-- Chat -->
    <button
      @click="router.push('/chat')"
      class="mobile-nav-item relative"
      :class="{ active: isChatActive }"
    >
      <span class="material-symbols-rounded">chat</span>
      <span
        v-if="chatBadgeCount > 0"
        :class="[
          'absolute top-0.5 right-1/4 w-4 h-4 text-[10px] font-bold text-white rounded-full flex items-center justify-center',
          notificationsStore.missedCallUnreadCount > 0 ? 'bg-red-500' : 'bg-primary-500'
        ]"
      >
        {{ chatBadgeCount > 9 ? '9+' : chatBadgeCount }}
      </span>
      <span class="mobile-nav-label">Chat</span>
    </button>

    <!-- Drive -->
    <button
      @click="router.push('/drive')"
      class="mobile-nav-item"
      :class="{ active: isDriveActive }"
    >
      <span class="material-symbols-rounded">cloud</span>
      <span class="mobile-nav-label">Drive</span>
    </button>

    <!-- Settings -->
    <button
      @click="showSettingsSheet = true"
      class="mobile-nav-item"
      :class="{ active: isSettingsActive || showSettingsSheet }"
    >
      <span class="material-symbols-rounded">tune</span>
      <span class="mobile-nav-label">Settings</span>
    </button>
  </nav>

  <!-- Settings bottom sheet -->
  <Teleport to="body">
    <Transition name="settings-sheet">
      <div
        v-if="showSettingsSheet"
        class="fixed inset-0 z-[9998] bg-black/40"
        @click.self="showSettingsSheet = false"
      >
        <div
          class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[80vh] overflow-y-auto"
          style="-webkit-overflow-scrolling: touch; padding-bottom: env(safe-area-inset-bottom, 0px);"
        >
          <!-- Drag handle -->
          <div class="flex justify-center pt-3 pb-1 sticky top-0 bg-white dark:bg-surface-800 z-10">
            <div class="w-10 h-1 bg-surface-300 dark:bg-surface-600 rounded-full"></div>
          </div>

          <div class="px-4 pb-6 pt-2 space-y-5">
            <!-- Appearance -->
            <div>
              <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2">Appearance</p>
              <button
                @click="theme.toggleTheme()"
                class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center justify-between transition-colors"
              >
                <span class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-lg">{{ theme.isDark ? 'dark_mode' : 'light_mode' }}</span>
                  {{ theme.isDark ? 'Dark mode' : 'Light mode' }}
                </span>
                <span class="text-xs text-surface-400">Tap to switch</span>
              </button>
            </div>

            <!-- Language -->
            <div>
              <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2">Language</p>
              <div class="grid grid-cols-2 gap-2">
                <button
                  v-for="lang in availableLanguages"
                  :key="lang.code"
                  @click="switchLanguage(lang.code)"
                  class="px-3 py-3 rounded-xl text-sm text-left flex items-center justify-between transition-colors"
                  :class="locale === lang.code
                    ? 'bg-primary-500 text-white'
                    : 'bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'"
                >
                  {{ lang.label }}
                  <span v-if="locale === lang.code" class="material-symbols-rounded text-base">check</span>
                </button>
              </div>
            </div>

            <hr class="border-surface-200 dark:border-surface-600" />

            <!-- All settings -->
            <button
              @click="openFullSettings"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3 transition-colors"
            >
              <span class="material-symbols-rounded text-lg">settings</span>
              All settings
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.settings-sheet-enter-active {
  transition: opacity 0.2s ease;
}
.settings-sheet-enter-active > div:last-child {
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.settings-sheet-leave-active {
  transition: opacity 0.15s ease;
}
.settings-sheet-leave-active > div:last-child {
  transition: transform 0.2s ease-in;
}
.settings-sheet-enter-from {
  opacity: 0;
}
.settings-sheet-enter-from > div:last-child {
  transform: translateY(100%);
}
.settings-sheet-leave-to {
  opacity: 0;
}
.settings-sheet-leave-to > div:last-child {
  transform: translateY(100%);
}
</style>
