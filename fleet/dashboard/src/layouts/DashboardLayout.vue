<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import ToastContainer from '../components/ToastContainer.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const sidebarOpen = ref(true)
const mobileSidebarOpen = ref(false)
const userMenuOpen = ref(false)
const isDark = ref(true)

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

const handleNavClick = () => {
  mobileSidebarOpen.value = false
}

// Navigation groups (like Panel)
const navigationGroups = [
  {
    items: [
      { name: 'Dashboard', path: '/', icon: 'dashboard' },
    ]
  },
  {
    items: [
      { name: 'Servers', path: '/servers', icon: 'dns' },
      { name: 'Blueprints', path: '/blueprints', icon: 'inventory_2' },
      { name: 'Packages', path: '/packages', icon: 'package_2' },
    ]
  },
  {
    label: 'Monitoring',
    items: [
      { name: 'Errors', path: '/errors', icon: 'error_outline' },
    ]
  },
  {
    divider: true,
    items: [
      { name: 'Settings', path: '/settings', icon: 'settings' },
    ]
  },
]

// Flatten for header title lookup
const navigation = computed(() => navigationGroups.flatMap(g => g.items))

const isActive = (path) => {
  if (path === '/') return route.path === '/'
  return route.path.startsWith(path)
}

const handleLogout = async () => {
  await auth.logout()
  router.push('/login')
}

const goToSettings = () => {
  userMenuOpen.value = false
  router.push('/settings')
}

const toggleTheme = () => {
  isDark.value = !isDark.value
  if (isDark.value) {
    document.documentElement.classList.add('dark')
  } else {
    document.documentElement.classList.remove('dark')
  }
  localStorage.setItem('theme', isDark.value ? 'dark' : 'light')
}

// Close menu when clicking outside
const closeUserMenu = (e) => {
  if (!e.target.closest('.user-menu-container')) {
    userMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', closeUserMenu)
  // Load theme preference
  const savedTheme = localStorage.getItem('theme')
  if (savedTheme === 'light') {
    isDark.value = false
    document.documentElement.classList.remove('dark')
  } else {
    isDark.value = true
    document.documentElement.classList.add('dark')
  }
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
        'bg-[rgb(var(--color-surface))] border-r border-[rgb(var(--color-border))]',
        'lg:translate-x-0',
        sidebarOpen ? 'lg:w-64' : 'lg:w-20',
        mobileSidebarOpen ? 'translate-x-0 w-64' : '-translate-x-full w-64'
      ]"
    >
      <!-- Logo -->
      <div :class="['h-16 flex items-center border-b border-[rgb(var(--color-border))]', sidebarOpen || mobileSidebarOpen ? 'px-4' : 'lg:px-0 lg:justify-center px-4']">
        <div :class="['flex items-center gap-3', !sidebarOpen && !mobileSidebarOpen && 'lg:justify-center']">
          <div class="w-10 h-10 rounded-xl bg-primary-500 flex items-center justify-center shrink-0">
            <span class="material-symbols-rounded text-white text-xl">rocket_launch</span>
          </div>
          <span v-if="sidebarOpen || mobileSidebarOpen" class="font-semibold text-lg tracking-tight text-[rgb(var(--color-text))]">
            Fleet Manager
          </span>
        </div>
        <!-- Mobile close button -->
        <button 
          @click="mobileSidebarOpen = false"
          class="lg:hidden ml-auto p-2 rounded-lg hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
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
            class="my-3 mx-2 border-t border-[rgb(var(--color-border))]"
          />
          
          <!-- Group label -->
          <div 
            v-if="group.label && (sidebarOpen || mobileSidebarOpen)" 
            class="px-3 py-2 text-xs font-semibold text-[rgb(var(--color-text-muted))] uppercase tracking-wider"
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
      <div :class="['p-3 border-t border-[rgb(var(--color-border))]', !sidebarOpen && !mobileSidebarOpen && 'lg:flex lg:flex-col lg:items-center']">
        <!-- Credits -->
        <div v-if="sidebarOpen || mobileSidebarOpen" class="text-center mb-3 px-2">
          <p class="text-xs text-[rgb(var(--color-text-muted))]">
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
      <header class="h-16 bg-[rgb(var(--color-surface))] border-b border-[rgb(var(--color-border))] sticky top-0 z-30">
        <div class="h-full px-4 sm:px-6 flex items-center justify-between">
          <!-- Left side -->
          <div class="flex items-center gap-3">
            <!-- Mobile hamburger menu -->
            <button 
              @click="mobileSidebarOpen = true"
              class="lg:hidden p-2 -ml-2 rounded-xl hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
            >
              <span class="material-symbols-rounded">menu</span>
            </button>
            <h1 class="text-lg font-medium truncate text-[rgb(var(--color-text))]">
              {{ route.path === '/settings' ? 'Settings' : navigation.find(n => isActive(n.path))?.name || 'Dashboard' }}
            </h1>
          </div>

          <!-- Right side -->
          <div class="flex items-center gap-2 sm:gap-3">
            <!-- Add server button -->
            <RouterLink to="/servers/add" class="btn-primary btn-sm hidden sm:inline-flex">
              <span class="material-symbols-rounded text-lg">add</span>
              Add Server
            </RouterLink>

            <!-- Theme toggle -->
            <button
              @click="toggleTheme"
              class="p-2 rounded-xl hover:bg-[rgb(var(--color-surface-hover))] transition-colors text-[rgb(var(--color-text-muted))]"
              :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            >
              <span class="material-symbols-rounded">
                {{ isDark ? 'light_mode' : 'dark_mode' }}
              </span>
            </button>

            <!-- User menu -->
            <div class="relative user-menu-container">
              <button
                @click.stop="userMenuOpen = !userMenuOpen"
                class="flex items-center gap-2 px-2 sm:px-3 py-2 rounded-xl hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
                :class="userMenuOpen && 'bg-[rgb(var(--color-surface-hover))]'"
              >
                <div class="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                  <span class="material-symbols-rounded text-primary-600 dark:text-primary-400 text-lg">person</span>
                </div>
                <span class="text-sm font-medium hidden sm:inline text-[rgb(var(--color-text))]">{{ auth.user?.username || 'Admin' }}</span>
                <span class="material-symbols-rounded text-[rgb(var(--color-text-muted))] text-lg hidden sm:inline">
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
                  class="absolute right-0 mt-2 w-56 origin-top-right rounded-xl bg-[rgb(var(--color-surface))] shadow-lg border border-[rgb(var(--color-border))] py-1"
                >
                  <div class="px-4 py-3 border-b border-[rgb(var(--color-border))]">
                    <p class="text-sm font-medium text-[rgb(var(--color-text))]">{{ auth.user?.username || 'Admin' }}</p>
                    <p class="text-xs text-[rgb(var(--color-text-muted))]">{{ auth.user?.role || 'Administrator' }}</p>
                  </div>

                  <div class="py-1">
                    <button
                      @click="goToSettings"
                      class="w-full px-4 py-2 text-left text-sm flex items-center gap-3 hover:bg-[rgb(var(--color-surface-hover))] transition-colors text-[rgb(var(--color-text))]"
                    >
                      <span class="material-symbols-rounded text-lg">settings</span>
                      Settings
                    </button>
                  </div>

                  <div class="border-t border-[rgb(var(--color-border))] py-1">
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

    <!-- Sidebar Tooltip -->
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
  </div>
</template>
