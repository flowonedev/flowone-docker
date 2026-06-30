<script setup>
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n'
import { useChatNotifications } from '../composables/useChatNotifications.js' 
import UserAvatar from '@/components/shared/UserAvatar.vue'
import appLogo from '../assets/chat-logo.png'

const props = defineProps({
  currentView: { type: String, required: true },
  icon: { type: String, required: true },
  title: { type: String, required: true },
  showMobileMenu: { type: Boolean, default: false },
  avatarUrl: { type: String, default: '' },
  avatarColor: { type: String, default: '' },
  avatarText: { type: String, default: '' },
})

const emit = defineEmits(['toggle-sidebar', 'icon-click'])

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const theme = useThemeStore()
const { locale } = useI18n()

const { isDnd, toggleDnd } = useChatNotifications()
const showSettingsMenu = ref(false)
const showLogoutDialog = ref(false)
const isElectronApp = typeof window !== 'undefined' && !!window.api

async function handleLogoutThisApp() {
  showLogoutDialog.value = false
  showAccountDropdown.value = false
  auth.logout()
}

async function handleLogoutGlobal() {
  showLogoutDialog.value = false
  showAccountDropdown.value = false
  if (isElectronApp && window.api?.sso?.logout) {
    await window.api.sso.logout()
  } else {
    auth.logoutEverywhere()
  }
}
const showAccountDropdown = ref(false)
const showLangMenu = ref(false)

const navItems = [
  { id: 'chat', path: '/chat', icon: 'chat', label: 'Chat' },
  { id: 'drive', path: '/drive', icon: 'cloud', label: 'Drive' },
]

// On the chat home header, show the FlowOne Chat brand logo (the app icon)
// instead of the generic `chat` Material glyph. Other section icons (Drive's
// `cloud`, the `arrow_back` button, conversation avatars) are left untouched.
const showBrandLogo = computed(
  () => props.icon === 'chat' && !props.avatarUrl && !props.avatarColor && !props.avatarText
)

const availableLanguages = [
  { code: 'en', label: 'English', flag: 'EN' },
  { code: 'hu', label: 'Magyar', flag: 'HU' },
]

const currentLang = computed(() => {
  return availableLanguages.find(l => l.code === locale.value) || availableLanguages[0]
})

async function switchLanguage(langCode) {
  await setLocale(langCode)
  showLangMenu.value = false
}
</script>

<template>
  <header class="flex items-center justify-between px-3 py-0.5 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex-shrink-0 safe-area-top min-h-safe-top">
    <!-- Left side -->
    <div class="flex items-center gap-3">
      <button
        v-if="showMobileMenu"
        @click="emit('toggle-sidebar')"
        class="btn-ghost btn-icon md:hidden"
      >
        <span class="material-symbols-rounded">menu</span>
      </button>

      <component
        :is="icon === 'arrow_back' ? 'button' : 'div'"
        class="w-8 h-8 rounded-lg flex items-center justify-center logo-icon overflow-hidden"
        :class="[
          avatarUrl || avatarColor || showBrandLogo ? '' : 'bg-primary-500',
          { 'cursor-pointer hover:opacity-80 transition-opacity': icon === 'arrow_back' }
        ]"
        :style="avatarUrl 
          ? { backgroundImage: `url(${avatarUrl})`, backgroundSize: 'cover', backgroundPosition: 'center' }
          : avatarColor 
            ? { backgroundColor: avatarColor }
            : {}"
        @click="icon === 'arrow_back' && emit('icon-click')"
      >
        <span 
          v-if="!avatarUrl && avatarText"
          class="text-white text-[10px] font-bold uppercase leading-none"
        >{{ avatarText }}</span>
        <img
          v-else-if="showBrandLogo"
          :src="appLogo"
          alt="FlowOne Chat"
          class="w-full h-full object-contain"
        />
        <span 
          v-else-if="!avatarUrl"
          class="material-symbols-rounded text-white text-lg"
        >{{ icon }}</span>
      </component>

      <span class="font-semibold text-surface-900 dark:text-surface-100 hidden sm:inline">{{ title }}</span>
      <slot name="title-badge" />
    </div>

    <!-- Right side -->
    <div class="flex items-center gap-2">
      <!-- DND toggle -->
      <button
        @click="toggleDnd()"
        class="btn-ghost btn-icon relative"
        :title="isDnd ? 'Do Not Disturb (ON) - click to enable notifications' : 'Notifications ON - click for Do Not Disturb'"
      >
        <span class="material-symbols-rounded" :class="isDnd ? 'text-red-500' : ''">
          {{ isDnd ? 'notifications_off' : 'notifications_active' }}
        </span>
        <span
          v-if="isDnd"
          class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-red-500 rounded-full border-2 border-white dark:border-[rgb(var(--color-surface))]"
        ></span>
      </button>

      <div class="w-px h-6 bg-surface-200 dark:bg-surface-700 mx-1 hidden sm:block"></div>

      <!-- Nav: Chat + Drive -->
      <div class="hidden sm:flex items-center gap-1">
        <button
          v-for="nav in navItems"
          :key="nav.id"
          @click="router.push(nav.path)"
          class="btn-ghost btn-icon"
          :class="{ 'text-primary-500 bg-primary-50 dark:bg-primary-500/10': route.path.startsWith(nav.path) }"
          :title="nav.label"
        >
          <span class="material-symbols-rounded">{{ nav.icon }}</span>
        </button>
      </div>

      <div class="w-px h-6 bg-surface-200 dark:bg-surface-700 mx-1 hidden sm:block"></div>

      <!-- Settings dropdown (desktop/tablet only; on phones the bottom nav owns Settings) -->
      <div class="relative hidden md:block">
        <button
          @click="showSettingsMenu = !showSettingsMenu"
          class="btn-ghost btn-icon"
          :class="{ 'bg-surface-100 dark:bg-surface-700': showSettingsMenu }"
          title="Settings"
        >
          <span class="material-symbols-rounded">tune</span>
        </button>

        <div
          v-if="showSettingsMenu"
          class="fixed inset-0 z-[9998]"
          @click="showSettingsMenu = false"
        ></div>

        <div
          v-if="showSettingsMenu"
          class="absolute right-0 top-full mt-1 w-[260px] bg-white dark:bg-surface-800 rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 z-[9999] py-2 px-3"
        >
          <p class="mb-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wider">Display</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              @click="theme.toggleTheme()"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">{{ theme.isDark ? 'dark_mode' : 'light_mode' }}</span>
              {{ theme.isDark ? 'Dark' : 'Light' }}
            </button>
            <button
              @click="router.push('/settings'); showSettingsMenu = false"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">settings</span>
              Settings
            </button>
            <div class="relative">
              <button
                @click.stop="showLangMenu = !showLangMenu"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
              >
                <span class="material-symbols-rounded text-sm">translate</span>
                {{ currentLang.flag }}
              </button>
              <div
                v-if="showLangMenu"
                class="absolute bottom-full left-0 mb-1 rounded-xl bg-white dark:bg-surface-700 shadow-lg border border-surface-200 dark:border-surface-600 py-1 min-w-[140px] z-10"
              >
                <button
                  v-for="lang in availableLanguages"
                  :key="lang.code"
                  @click="switchLanguage(lang.code)"
                  class="w-full flex items-center justify-between px-3 py-1.5 hover:bg-surface-50 dark:hover:bg-surface-600 transition-colors text-left"
                  :class="{ 'bg-primary-50 dark:bg-primary-500/10': locale === lang.code }"
                >
                  <span class="text-xs text-surface-700 dark:text-surface-300">{{ lang.label }}</span>
                  <span v-if="locale === lang.code" class="material-symbols-rounded text-primary-500 text-sm">check</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Mobile nav items -->
          <div class="sm:hidden mt-2 pt-2 border-t border-surface-100 dark:border-surface-700">
            <button
              v-for="nav in navItems"
              :key="nav.id"
              @click="router.push(nav.path); showSettingsMenu = false"
              class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors text-left"
            >
              <span class="material-symbols-rounded text-base">{{ nav.icon }}</span>
              <span class="text-xs text-surface-600 dark:text-surface-300">{{ nav.label }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Account Dropdown -->
      <div class="relative pl-2 border-l border-surface-200 dark:border-[rgb(var(--color-border))]">
        <button
          @click="showAccountDropdown = !showAccountDropdown"
          class="flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
        >
          <UserAvatar
            :email="auth.userEmail || ''"
            :name="auth.displayName || ''"
            size="sm"
          />
          <div class="text-left">
            <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate max-w-[120px]">
              {{ auth.displayName || '' }}
            </p>
          </div>
          <span class="material-symbols-rounded text-surface-400 text-sm">
            {{ showAccountDropdown ? 'expand_less' : 'expand_more' }}
          </span>
        </button>

        <div
          v-if="showAccountDropdown"
          class="fixed inset-0 z-40"
          @click="showAccountDropdown = false"
        ></div>

        <div
          v-if="showAccountDropdown"
          class="absolute right-0 top-full mt-1 w-72 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 z-50 overflow-hidden"
        >
          <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-3 mb-1">
              <UserAvatar
                :email="auth.userEmail || ''"
                :name="auth.displayName || ''"
                size="md"
              />
              <div>
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100">
                  {{ auth.displayName || '' }}
                </p>
                <p class="text-xs text-surface-500">{{ auth.userEmail || '' }}</p>
              </div>
            </div>
          </div>

          <div class="border-t border-surface-200 dark:border-surface-700 pt-1">
            <button
              @click="isElectronApp ? (showLogoutDialog = true, showAccountDropdown = false) : (auth.logout(), showAccountDropdown = false)"
              class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500"
            >
              <span class="material-symbols-rounded">logout</span>
              <span class="text-sm font-medium">Sign Out</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Logout confirmation dialog (Electron only) -->
    <Teleport to="body">
      <div v-if="showLogoutDialog" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showLogoutDialog = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 w-full max-w-sm mx-4 p-6">
          <div class="text-center mb-5">
            <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-red-50 dark:bg-red-500/10 flex items-center justify-center">
              <span class="material-symbols-rounded text-2xl text-red-500">logout</span>
            </div>
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Sign Out</h3>
            <p class="text-sm text-surface-500 mt-1">How would you like to sign out?</p>
          </div>
          <div class="space-y-2">
            <button @click="handleLogoutThisApp" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors text-left">
              <span class="material-symbols-rounded text-surface-500">monitor</span>
              <div>
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100">This app only</p>
                <p class="text-xs text-surface-500">Sign out from FlowOne Chat</p>
              </div>
            </button>
            <button @click="handleLogoutGlobal" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-red-200 dark:border-red-500/30 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors text-left">
              <span class="material-symbols-rounded text-red-500">devices</span>
              <div>
                <p class="text-sm font-medium text-red-600 dark:text-red-400">All FlowOne apps</p>
                <p class="text-xs text-surface-500">Sign out from Email, Chat, and Drive</p>
              </div>
            </button>
          </div>
          <button @click="showLogoutDialog = false" class="w-full mt-3 py-2.5 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors">Cancel</button>
        </div>
      </div>
    </Teleport>
  </header>
</template>
