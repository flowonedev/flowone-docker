<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import ToastContainer from '@/components/ToastContainer.vue'
import { useIdleTimer } from '@/composables/useIdleTimer'
import api from '@/services/api'

const auth = useAuthStore()
const theme = useThemeStore()
const route = useRoute()
const router = useRouter()

// Idle auto-logout
const { showWarning: showIdleWarning, secondsLeft, stayActive: handleStayActive, doLogout: handleIdleLogout } = useIdleTimer()

const sidebarOpen = ref(true)
const mobileSidebarOpen = ref(false)
const userMenuOpen = ref(false)
const showDebugModal = ref(false)
const tokenCopied = ref(false)
const serverIp = ref('')

// Tooltip for collapsed sidebar
const tooltipVisible = ref(false)
const tooltipText = ref('')
const tooltipPosition = ref({ top: 0, left: 0 })

const showTooltip = (event, text) => {
  if (sidebarOpen.value || mobileSidebarOpen.value) return
  const rect = event.currentTarget.getBoundingClientRect()
  tooltipPosition.value = {
    top: rect.top + rect.height / 2,
    left: rect.right + 12
  }
  tooltipText.value = text
  tooltipVisible.value = true
}

const hideTooltip = () => {
  tooltipVisible.value = false
}

// Close mobile sidebar when route changes
watch(() => route.path, () => {
  mobileSidebarOpen.value = false
})

// Close mobile sidebar when clicking a nav item
const handleNavClick = () => {
  mobileSidebarOpen.value = false
}

const copyToken = async () => {
  const token = localStorage.getItem('token')
  if (token) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(token)
      } else {
        // Fallback for non-HTTPS or older browsers
        const textarea = document.createElement('textarea')
        textarea.value = token
        textarea.style.position = 'fixed'
        textarea.style.left = '-9999px'
        document.body.appendChild(textarea)
        textarea.select()
        document.execCommand('copy')
        document.body.removeChild(textarea)
      }
      tokenCopied.value = true
      setTimeout(() => {
        tokenCopied.value = false
      }, 2000)
    } catch (e) {
      console.error('Failed to copy token', e)
    }
  }
}

const getToken = () => {
  return localStorage.getItem('token') || 'No token found'
}

// API endpoints for quick access
const apiEndpoints = [
  { name: 'System Info', path: '/api/system/info', method: 'GET' },
  { name: 'PowerDNS Config', path: '/api/system/pdns', method: 'GET' },
  { name: 'PowerDNS Status', path: '/api/system/pdns/status', method: 'GET' },
  { name: 'SSH Config', path: '/api/system/ssh', method: 'GET' },
  { name: 'Services List', path: '/api/services', method: 'GET' },
  { name: 'Sites List', path: '/api/sites', method: 'GET' },
  { name: 'SSL Certificates', path: '/api/ssl', method: 'GET' },
  { name: 'DNS Zones', path: '/api/dns/zones', method: 'GET' },
  { name: 'Mail Domains', path: '/api/mail/domains', method: 'GET' },
  { name: 'Mail Accounts', path: '/api/mail/accounts', method: 'GET' },
  { name: 'Databases', path: '/api/databases', method: 'GET' },
  { name: 'Cron Jobs', path: '/api/cron', method: 'GET' },
  { name: 'Backups', path: '/api/backups', method: 'GET' },
  { name: 'Users', path: '/api/users', method: 'GET' },
  { name: 'Agent Status', path: '/api/agent/status', method: 'GET' },
  { name: 'Dashboard', path: '/api/dashboard', method: 'GET' },
  { name: 'Cache Stats', path: '/api/cache/stats', method: 'GET' },
]

const selectedEndpoint = ref('/api/system/pdns')

const getCurlCommand = () => {
  const token = getToken()
  return `curl -s -H "Authorization: Bearer ${token}" https://panel.devcon1.hu${selectedEndpoint.value}`
}

const copyCurlCommand = async () => {
  const cmd = getCurlCommand()
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(cmd)
    } else {
      // Fallback for non-HTTPS or older browsers
      const textarea = document.createElement('textarea')
      textarea.value = cmd
      textarea.style.position = 'fixed'
      textarea.style.left = '-9999px'
      document.body.appendChild(textarea)
      textarea.select()
      document.execCommand('copy')
      document.body.removeChild(textarea)
    }
    tokenCopied.value = true
    setTimeout(() => tokenCopied.value = false, 2000)
  } catch (e) {
    console.error('Failed to copy command', e)
  }
}

// Navigation items with role requirements
// adminOnly: visible to admin + super_admin
// superAdminOnly: visible to super_admin only
const allNavigationGroups = [
  {
    items: [
      { name: 'Dashboard', path: '/', icon: 'dashboard' },
    ]
  },
  {
    adminOnly: true,
    items: [
      { name: 'Overview', path: '/overview', icon: 'dns' },
    ]
  },
  {
    items: [
      { name: 'Sites', path: '/sites-v2', icon: 'language' },
      { name: 'Files', path: '/files', icon: 'folder_open' },
    ]
  },
  {
    superAdminOnly: true,
    items: [
      { name: 'Billing', path: '/billing-management', icon: 'payments' },
    ]
  },
  {
    adminOnly: true,
    items: [
      { name: 'System Config', path: '/system', icon: 'computer' },
      { name: 'NAS Storage', path: '/nas-storage', icon: 'hard_drive' },
      { name: 'Cron Jobs', path: '/cron', icon: 'schedule' },
    ]
  },
  {
    label: 'Security',
    adminOnly: true,
    items: [
      { name: 'Security', path: '/security', icon: 'shield' },
      { name: 'Mail Security', path: '/mail-security', icon: 'mark_email_unread' },
      { name: 'Backups', path: '/backups', icon: 'backup' },
      { name: 'Panel Logs', path: '/logs', icon: 'history' },
    ]
  },
  {
    divider: true,
    adminOnly: true,
    items: [
      { name: 'Users', path: '/users', icon: 'manage_accounts' },
      { name: 'SFTP Users', path: '/sftp-users', icon: 'lock_person' },
    ]
  },
  {
    divider: true,
    adminOnly: true,
    items: [
      { name: 'Agent Status', path: '/agent-status', icon: 'monitor_heart' },
    ]
  },
  {
    divider: true,
    adminOnly: true,
    items: [
      { name: 'AI Helper', path: '/ai-helper', icon: 'psychology' },
    ]
  },
]

const navigationGroups = computed(() => {
  if (auth.isSuperAdmin) {
    return allNavigationGroups
  }
  if (auth.isAdmin) {
    return allNavigationGroups.filter(group => !group.superAdminOnly)
  }
  return allNavigationGroups.filter(group => !group.adminOnly && !group.superAdminOnly)
})

// Flatten for header title lookup
const navigation = computed(() => navigationGroups.value.flatMap(g => g.items))

// User role display
const userRoleLabel = computed(() => {
  if (auth.isSuperAdmin) return 'Super Admin'
  if (auth.isAdmin) return 'Admin'
  return 'User'
})

const isActive = (path) => {
  if (path === '/') return route.path === '/'
  return route.path.startsWith(path)
}

const handleLogout = async () => {
  await auth.logout()
  window.location.href = '/login'
}

const goToSettings = () => {
  userMenuOpen.value = false
  router.push('/settings')
}

// Close menu when clicking outside
const closeUserMenu = (e) => {
  if (!e.target.closest('.user-menu-container')) {
    userMenuOpen.value = false
  }
}

const fetchServerIp = async () => {
  try {
    const response = await api.get('/system/info')
    if (response.data?.network?.primary_ip) {
      serverIp.value = response.data.network.primary_ip
    }
  } catch (e) {
    console.error('Failed to fetch server IP', e)
  }
}

onMounted(() => {
  document.addEventListener('click', closeUserMenu)
  fetchServerIp()
})

onUnmounted(() => {
  document.removeEventListener('click', closeUserMenu)
})
</script>

<template>
  <div class="min-h-screen bg-surface-100 dark:bg-surface-950 flex">
    <!-- Mobile sidebar backdrop -->
    <transition
      enter-active-class="transition-opacity duration-300"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-300"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div 
        v-if="mobileSidebarOpen" 
        class="fixed inset-0 bg-black/50 z-40 lg:hidden"
        @click="mobileSidebarOpen = false"
      ></div>
    </transition>

    <!-- Sidebar -->
    <aside 
      :class="[
        'fixed inset-y-0 left-0 z-50 flex flex-col transition-all duration-300',
        'bg-white dark:bg-surface-850 border-r border-surface-200 dark:border-surface-700',
        // Desktop: show based on sidebarOpen state
        'lg:translate-x-0',
        sidebarOpen ? 'lg:w-64' : 'lg:w-20',
        // Mobile/Tablet: slide in/out
        mobileSidebarOpen ? 'translate-x-0 w-64' : '-translate-x-full w-64'
      ]"
    >
      <!-- Logo -->
      <div :class="['h-16 flex items-center border-b border-surface-100 dark:border-surface-700', sidebarOpen || mobileSidebarOpen ? 'px-4' : 'lg:px-0 lg:justify-center px-4']">
        <div :class="['flex items-center gap-3', !sidebarOpen && !mobileSidebarOpen && 'lg:justify-center']">
          <div class="w-10 h-10 rounded-xl bg-primary-500 flex items-center justify-center shrink-0">
            <span class="material-symbols-rounded text-white text-xl">terminal</span>
          </div>
          <span v-if="sidebarOpen || mobileSidebarOpen" class="font-semibold text-lg tracking-tight">
            DEVCON Panel
          </span>
        </div>
        <!-- Mobile close button -->
        <button 
          @click="mobileSidebarOpen = false"
          class="lg:hidden ml-auto p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded">close</span>
        </button>
      </div>

      <!-- Navigation -->
      <nav :class="['flex-1 overflow-y-auto overflow-x-visible', sidebarOpen || mobileSidebarOpen ? 'p-3' : 'lg:p-2 lg:px-2 p-3']">
        <template v-for="(group, groupIndex) in navigationGroups" :key="groupIndex">
          <!-- Group separator (not for first group) -->
          <div 
            v-if="groupIndex > 0" 
            class="my-3 mx-2 border-t border-surface-200 dark:border-surface-700"
          />
          
          <!-- Group label -->
          <div 
            v-if="group.label && (sidebarOpen || mobileSidebarOpen)" 
            class="px-3 py-2 text-xs font-semibold text-surface-400 dark:text-surface-500 uppercase tracking-wider"
          >
            {{ group.label }}
          </div>
          
          <!-- Group items -->
          <div class="space-y-1">
            <RouterLink
              v-for="item in group.items"
              :key="item.path"
              :to="item.path"
              @click="handleNavClick"
              @mouseenter="showTooltip($event, item.name)"
              @mouseleave="hideTooltip"
              :class="[
                'nav-item',
                isActive(item.path) && 'active',
                !sidebarOpen && !mobileSidebarOpen && 'lg:justify-center'
              ]"
            >
              <span class="material-symbols-rounded icon" :class="isActive(item.path) && 'icon-filled'">
                {{ item.icon }}
              </span>
              <span v-if="sidebarOpen || mobileSidebarOpen" class="lg:inline" :class="{ 'hidden': !sidebarOpen }">{{ item.name }}</span>
            </RouterLink>
          </div>
        </template>
      </nav>

      <!-- Sidebar footer -->
      <div :class="['p-3 border-t border-surface-100 dark:border-surface-700', !sidebarOpen && !mobileSidebarOpen && 'lg:flex lg:flex-col lg:items-center']">
        <!-- Credits -->
        <div v-if="sidebarOpen || mobileSidebarOpen" class="text-center mb-3 px-2">
          <p class="text-xs text-surface-400 dark:text-surface-500">
            made with <span class="text-red-500">&#9829;</span> by
          </p>
          <a 
            href="https://pixelranger.hu" 
            target="_blank" 
            class="text-xs font-medium text-primary-500 hover:text-primary-600 transition-colors"
          >
            Pixel Ranger Studio
          </a>
        </div>
        
        <!-- Desktop sidebar toggle -->
        <button 
          @click="sidebarOpen = !sidebarOpen"
          @mouseenter="showTooltip($event, 'Expand sidebar')"
          @mouseleave="hideTooltip"
          :class="['nav-item justify-center hidden lg:flex', !sidebarOpen ? 'w-auto' : 'w-full']"
        >
          <span class="material-symbols-rounded icon">
            {{ sidebarOpen ? 'chevron_left' : 'chevron_right' }}
          </span>
        </button>
      </div>
    </aside>

    <!-- Main content -->
    <div :class="['flex-1 transition-all duration-300 ml-0', sidebarOpen ? 'lg:ml-64' : 'lg:ml-20']">
      <!-- Top bar -->
      <header class="h-16 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 sticky top-0 z-30">
        <div class="h-full px-4 sm:px-6 flex items-center justify-between">
          <!-- Left side -->
          <div class="flex items-center gap-3">
            <!-- Mobile hamburger menu -->
            <button 
              @click="mobileSidebarOpen = true"
              class="lg:hidden p-2 -ml-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              <span class="material-symbols-rounded">menu</span>
            </button>
            <h1 class="text-lg font-medium truncate">
              {{ route.path === '/settings' ? 'Settings' : navigation.find(n => isActive(n.path))?.name || 'Dashboard' }}
            </h1>
            <!-- Server IP -->
            <div v-if="serverIp" class="hidden sm:flex items-center gap-1.5 px-3 py-1 bg-surface-100 dark:bg-surface-700 rounded-lg text-sm">
              <span class="material-symbols-rounded text-base text-surface-500">dns</span>
              <span class="font-mono text-surface-600 dark:text-surface-300">{{ serverIp }}</span>
            </div>
          </div>

          <!-- Right side -->
          <div class="flex items-center gap-2 sm:gap-3">
            <!-- Theme toggle -->
            <button
              @click="theme.toggle()"
              class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              :title="theme.isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            >
              <span class="material-symbols-rounded">
                {{ theme.isDark ? 'light_mode' : 'dark_mode' }}
              </span>
            </button>

            <!-- User menu -->
            <div class="relative user-menu-container">
              <button
                @click.stop="userMenuOpen = !userMenuOpen"
                class="flex items-center gap-2 px-2 sm:px-3 py-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                :class="userMenuOpen && 'bg-surface-100 dark:bg-surface-700'"
              >
                <div class="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                  <span class="material-symbols-rounded text-primary-600 dark:text-primary-400 text-lg">person</span>
                </div>
                <span class="text-sm font-medium hidden sm:inline">{{ auth.user?.username || 'Admin' }}</span>
                <span class="material-symbols-rounded text-surface-400 text-lg hidden sm:inline">
                  {{ userMenuOpen ? 'expand_less' : 'expand_more' }}
                </span>
              </button>

              <!-- Dropdown -->
              <transition
                enter-active-class="transition duration-100 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-75 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
              >
                <div 
                  v-if="userMenuOpen"
                  class="absolute right-0 mt-2 w-56 origin-top-right rounded-xl bg-white dark:bg-surface-800 shadow-lg border border-surface-200 dark:border-surface-700 py-1"
                >
                  <div class="px-4 py-3 border-b border-surface-100 dark:border-surface-700">
                    <p class="text-sm font-medium">{{ auth.user?.username || 'Admin' }}</p>
                    <p class="text-xs text-surface-500">{{ userRoleLabel }}</p>
                  </div>

                  <div class="py-1">
                    <button
                      @click="goToSettings"
                      class="w-full px-4 py-2 text-left text-sm flex items-center gap-3 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
                    >
                      <span class="material-symbols-rounded text-lg">settings</span>
                      Settings
                    </button>
                    <button
                      @click="goToSettings"
                      class="w-full px-4 py-2 text-left text-sm flex items-center gap-3 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
                    >
                      <span class="material-symbols-rounded text-lg">verified_user</span>
                      Security
                      <span v-if="auth.user?.totp_enabled" class="ml-auto text-xs px-1.5 py-0.5 rounded bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400">
                        2FA
                      </span>
                    </button>
                  </div>

                  <div class="border-t border-surface-100 dark:border-surface-700 py-1">
                    <button
                      @click="handleLogout"
                      class="w-full px-4 py-2 text-left text-sm flex items-center gap-3 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
                    >
                      <span class="material-symbols-rounded text-lg">logout</span>
                      Sign Out
                    </button>
                  </div>
                </div>
              </transition>
            </div>
          </div>
        </div>
      </header>

      <!-- Page content -->
      <main class="p-4 sm:p-6">
        <RouterView />
      </main>
    </div>

    <!-- Toast notifications -->
    <ToastContainer />

    <!-- Debug Button (Super Admin Only) -->
    <button
      v-if="auth.isSuperAdmin"
      @click="showDebugModal = true"
      class="fixed bottom-4 right-4 w-12 h-12 rounded-full bg-surface-800 dark:bg-surface-700 text-white shadow-lg hover:bg-surface-700 dark:hover:bg-surface-600 transition-colors flex items-center justify-center z-50"
      title="Debug Tools"
    >
      <span class="material-symbols-rounded">bug_report</span>
    </button>

    <!-- Debug Modal -->
    <Teleport to="body">
      <transition name="modal">
        <div 
          v-if="showDebugModal" 
          class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        >
          <div class="fixed inset-0 bg-black/50" @click="showDebugModal = false"></div>
          <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
            <div class="flex items-center justify-between p-4 border-b dark:border-surface-700">
              <h3 class="text-lg font-semibold flex items-center gap-2">
                <span class="material-symbols-rounded text-amber-500">bug_report</span>
                Debug Tools
              </h3>
              <button @click="showDebugModal = false" class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors">
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
            
            <div class="p-6 space-y-6 overflow-y-auto max-h-[60vh]">
              <!-- JWT Token -->
              <div>
                <label class="block text-sm font-medium mb-2">JWT Access Token</label>
                <div class="relative">
                  <textarea 
                    :value="getToken()"
                    readonly
                    rows="4"
                    class="w-full font-mono text-xs p-3 bg-surface-100 dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-700 resize-none"
                  ></textarea>
                  <button
                    @click="copyToken"
                    :class="[
                      'absolute top-2 right-2 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center gap-1',
                      tokenCopied 
                        ? 'bg-green-500 text-white' 
                        : 'bg-surface-200 dark:bg-surface-700 hover:bg-surface-300 dark:hover:bg-surface-600'
                    ]"
                  >
                    <span class="material-symbols-rounded text-sm">{{ tokenCopied ? 'check' : 'content_copy' }}</span>
                    {{ tokenCopied ? 'Copied!' : 'Copy' }}
                  </button>
                </div>
              </div>

              <!-- API Endpoint Selector -->
              <div>
                <label class="block text-sm font-medium mb-2">Quick API Test</label>
                <div class="flex gap-2 mb-3">
                  <select 
                    v-model="selectedEndpoint"
                    class="flex-1 px-3 py-2 bg-surface-100 dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-700 text-sm"
                  >
                    <option v-for="ep in apiEndpoints" :key="ep.path" :value="ep.path">
                      {{ ep.name }} ({{ ep.method }})
                    </option>
                  </select>
                  <button
                    @click="copyCurlCommand"
                    :class="[
                      'px-4 py-2 rounded-xl text-sm font-medium transition-colors flex items-center gap-1 whitespace-nowrap',
                      tokenCopied 
                        ? 'bg-green-500 text-white' 
                        : 'bg-primary-500 hover:bg-primary-600 text-white'
                    ]"
                  >
                    <span class="material-symbols-rounded text-sm">{{ tokenCopied ? 'check' : 'content_copy' }}</span>
                    {{ tokenCopied ? 'Copied!' : 'Copy cURL' }}
                  </button>
                </div>
                <div class="p-3 bg-surface-100 dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-700 font-mono text-xs overflow-x-auto whitespace-pre-wrap break-all">
                  <code class="text-green-600 dark:text-green-400">curl</code>
                  <code> -s -H </code>
                  <code class="text-amber-600 dark:text-amber-400">"Authorization: Bearer ..."</code>
                  <code> \<br/>  https://panel.devcon1.hu</code><code class="text-blue-500">{{ selectedEndpoint }}</code>
                </div>
              </div>

              <!-- User Info -->
              <div>
                <label class="block text-sm font-medium mb-2">Current User</label>
                <div class="p-3 bg-surface-100 dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-700 font-mono text-xs">
                  <div><span class="text-surface-500">Username:</span> {{ auth.user?.username }}</div>
                  <div><span class="text-surface-500">Role:</span> {{ auth.user?.role }}</div>
                  <div><span class="text-surface-500">ID:</span> {{ auth.user?.id }}</div>
                </div>
              </div>
            </div>

            <div class="p-4 border-t dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50">
              <button 
                @click="showDebugModal = false"
                class="w-full btn-secondary"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- Sidebar Tooltip (Teleported to body) -->
    <Teleport to="body">
      <transition
        enter-active-class="transition-all duration-150 ease-out"
        enter-from-class="opacity-0 translate-x-1"
        enter-to-class="opacity-100 translate-x-0"
        leave-active-class="transition-all duration-100 ease-in"
        leave-from-class="opacity-100 translate-x-0"
        leave-to-class="opacity-0 translate-x-1"
      >
        <div 
          v-if="tooltipVisible && !sidebarOpen && !mobileSidebarOpen"
          class="fixed z-[9999] px-3 py-2 text-sm font-medium text-white bg-surface-900 dark:bg-surface-700 rounded-lg shadow-xl pointer-events-none"
          :style="{ 
            top: tooltipPosition.top + 'px', 
            left: tooltipPosition.left + 'px',
            transform: 'translateY(-50%)'
          }"
        >
          {{ tooltipText }}
          <div class="absolute right-full top-1/2 -translate-y-1/2 border-[6px] border-transparent border-r-surface-900 dark:border-r-surface-700"></div>
        </div>
      </transition>
    </Teleport>

    <!-- Idle Auto-Logout Warning -->
    <Teleport to="body">
      <transition name="modal">
        <div
          v-if="showIdleWarning"
          class="fixed inset-0 z-[200] flex items-center justify-center p-4"
        >
          <div class="fixed inset-0 bg-black/60"></div>
          <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="p-6 text-center space-y-4">
              <div class="w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center mx-auto">
                <span class="material-symbols-rounded text-3xl text-amber-600 dark:text-amber-400">schedule</span>
              </div>
              <h3 class="text-lg font-semibold">Session Expiring</h3>
              <p class="text-sm text-surface-500">
                You will be logged out in
                <span class="font-bold text-amber-600 dark:text-amber-400 text-lg">{{ secondsLeft }}s</span>
                due to inactivity.
              </p>
              <div class="flex gap-3 pt-2">
                <button
                  @click="handleIdleLogout"
                  class="flex-1 btn-secondary"
                >
                  Sign Out
                </button>
                <button
                  @click="handleStayActive"
                  class="flex-1 btn-primary"
                >
                  Stay Active
                </button>
              </div>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </div>
</template>
