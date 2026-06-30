<script setup>
/**
 * PortalView - Main layout wrapper for the Client Portal
 * 
 * Handles portal session validation and provides the shell UI
 * (header, nav, content area) for all portal sub-views.
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { usePortalStore } from '@/stores/portal'

const router = useRouter()
const route = useRoute()
const portal = usePortalStore()
const { t } = useI18n()
const loading = ref(true)
const mobileMenuOpen = ref(false)
let refreshInterval = null

const navItems = computed(() => ([
  { name: 'portal-home', label: t('portalView.nav.overview'), icon: 'home' },
  { name: 'portal-updates', label: t('portalView.nav.updates'), icon: 'campaign' },
  { name: 'portal-documents', label: t('portalView.nav.documents'), icon: 'description' },
  { name: 'portal-calls', label: t('portalView.nav.calls'), icon: 'videocam' },
]))

const activeNav = computed(() => {
  // Match child routes to parent nav items
  const name = route.name
  if (name?.startsWith('portal-update')) return 'portal-updates'
  if (name?.startsWith('portal-document')) return 'portal-documents'
  if (name?.startsWith('portal-call')) return 'portal-calls'
  return name || 'portal-home'
})

/** Get badge count for a nav tab */
function getBadgeCount(name) {
  const u = portal.user
  if (!u) return 0
  switch (name) {
    case 'portal-updates': return u.unread_updates || 0
    case 'portal-documents': return u.pending_documents || 0
    case 'portal-calls': return u.active_calls || 0
    default: return 0
  }
}

onMounted(async () => {
  const isValid = await portal.checkAuth()
  if (!isValid) {
    router.replace({ name: 'portal-auth', params: { token: 'request' } })
    return
  }
  loading.value = false

  // Refresh counts every 30 seconds
  refreshInterval = setInterval(() => {
    portal.fetchMe()
  }, 30000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})

// Also refresh counts when navigating between tabs
watch(() => route.name, () => {
  if (portal.isAuthenticated) portal.fetchMe()
})

function navigateTo(name) {
  router.push({ name })
  mobileMenuOpen.value = false
}

async function handleLogout() {
  await portal.logout()
  router.replace({ name: 'portal-auth', params: { token: 'request' } })
}
</script>

<template>
  <!-- Loading spinner -->
  <div v-if="loading" class="min-h-screen bg-surface-50 dark:bg-surface-900 flex items-center justify-center">
    <div class="text-center">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto mb-4"></div>
      <p class="text-surface-500">{{ $t('portalView.loadingPortal') }}</p>
    </div>
  </div>

  <!-- Portal Shell -->
  <div v-else class="min-h-screen bg-surface-50 dark:bg-surface-900">
    <!-- Header -->
    <header class="bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 sticky top-0 z-40">
      <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="flex items-center justify-between h-16">
          <!-- Brand + Client name -->
          <div class="flex items-center gap-3">
            <button @click="mobileMenuOpen = !mobileMenuOpen" class="sm:hidden p-2 -ml-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700">
              <span class="material-symbols-rounded text-xl">menu</span>
            </button>
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-lg text-primary-600 dark:text-primary-400">shield_person</span>
              </div>
              <div>
                <h1 class="text-sm font-semibold text-surface-900 dark:text-white leading-tight">
                  {{ portal.clientName }}
                </h1>
                <p class="text-xs text-surface-500 leading-tight">{{ $t('portalView.clientPortal') }}</p>
              </div>
            </div>
          </div>

          <!-- Desktop Nav -->
          <nav class="hidden sm:flex items-center gap-1">
            <button
              v-for="item in navItems"
              :key="item.name"
              @click="navigateTo(item.name)"
              :class="[
                'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors relative',
                activeNav === item.name
                  ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300'
                  : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
              ]"
            >
              <span class="material-symbols-rounded text-lg">{{ item.icon }}</span>
              {{ item.label }}
              <!-- Badge -->
              <span v-if="getBadgeCount(item.name) > 0"
                    :class="[
                      'inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-white text-xs font-bold',
                      item.name === 'portal-calls' ? 'bg-emerald-500 animate-pulse' : item.name === 'portal-documents' ? 'bg-amber-500' : 'bg-primary-500'
                    ]">
                {{ getBadgeCount(item.name) }}
              </span>
            </button>
          </nav>

          <!-- User info + Logout -->
          <div class="flex items-center gap-3">
            <span class="text-sm text-surface-600 dark:text-surface-300 hidden md:inline">
              {{ portal.userName }}
            </span>
            <button 
              @click="handleLogout"
              class="p-2 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-700 dark:hover:text-surface-200"
              :title="$t('portalView.signOut')"
            >
              <span class="material-symbols-rounded text-xl">logout</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Mobile Nav -->
      <div v-if="mobileMenuOpen" class="sm:hidden border-t border-surface-200 dark:border-surface-700 px-4 py-2 space-y-1">
        <button
          v-for="item in navItems"
          :key="item.name"
          @click="navigateTo(item.name)"
          :class="[
            'flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium transition-colors',
            activeNav === item.name
              ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300'
              : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
        >
          <span class="material-symbols-rounded text-xl">{{ item.icon }}</span>
          {{ item.label }}
          <!-- Badge -->
          <span v-if="getBadgeCount(item.name) > 0"
                :class="[
                  'ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-white text-xs font-bold',
                  item.name === 'portal-calls' ? 'bg-emerald-500 animate-pulse' : item.name === 'portal-documents' ? 'bg-amber-500' : 'bg-primary-500'
                ]">
            {{ getBadgeCount(item.name) }}
          </span>
        </button>
      </div>
    </header>

    <!-- Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
      <router-view />
    </main>
  </div>
</template>

