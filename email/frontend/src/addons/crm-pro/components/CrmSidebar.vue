<script setup>
/**
 * CrmSidebar - Shared sidebar for all CRM Pro views
 * Provides quick navigation between CRM features.
 * Modeled after BoardSidebar.vue for consistent UX.
 */
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

// Section toggles (default open)
const showViews = ref(true)
const showAutomation = ref(true)
const showSettings = ref(true)
const showNavigate = ref(true)

const currentPath = computed(() => route.path)

const viewItems = [
  { path: '/crm/executive', label: 'Overview', icon: 'query_stats' },
  { path: '/crm/dashboard', label: 'Forecast & Reports', icon: 'bar_chart_4_bars' },
  { path: '/crm/invoices', label: 'Invoices', icon: 'receipt_long' },
  { path: '/crm/pipeline', label: 'Deals & Pipeline', icon: 'conversion_path' },
]

const automationItems = [
  { path: '/crm/automation', label: 'Workflows', icon: 'smart_toy' },
  { path: '/crm/sequences', label: 'Deal Follow-ups', icon: 'timeline' },
]

const settingsItems = [
  { path: '/crm/sharing', label: 'Sharing', icon: 'share' },
]

function isActive(path) {
  return currentPath.value === path || currentPath.value.startsWith(path + '/')
}

function navigate(path) {
  if (currentPath.value !== path) {
    router.push(path)
  }
}
</script>

<template>
  <aside class="w-56 flex-shrink-0 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] h-full hidden md:flex flex-col">
    <!-- CRM Header -->
    <div class="p-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <div class="flex items-center gap-2">
        <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-white text-lg">conversion_path</span>
        </div>
        <div class="min-w-0">
          <p class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate">CRM Pro</p>
          <p class="text-[10px] text-surface-400">Sales & Invoicing</p>
        </div>
      </div>
    </div>

    <!-- Scrollable nav -->
    <nav class="flex-1 overflow-y-auto p-3">

      <!-- ═══ GROUP: Views ═══ -->
      <div class="mb-1">
        <button 
          @click="showViews = !showViews"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showViews ? 'expand_more' : 'chevron_right' }}</span>
          Views
        </button>
        <div v-if="showViews" class="mt-0.5 space-y-0.5">
          <button
            v-for="item in viewItems"
            :key="item.path"
            @click="navigate(item.path)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              isActive(item.path)
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
            <span 
              v-if="isActive(item.path)" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Automation ═══ -->
      <div class="mb-1">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showAutomation = !showAutomation"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showAutomation ? 'expand_more' : 'chevron_right' }}</span>
          Workflows
        </button>
        <div v-if="showAutomation" class="mt-0.5 space-y-0.5">
          <button
            v-for="item in automationItems"
            :key="item.path"
            @click="navigate(item.path)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              isActive(item.path)
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
            <span 
              v-if="isActive(item.path)" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Settings ═══ -->
      <div class="mb-1">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showSettings = !showSettings"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showSettings ? 'expand_more' : 'chevron_right' }}</span>
          Settings
        </button>
        <div v-if="showSettings" class="mt-0.5 space-y-0.5">
          <button
            v-for="item in settingsItems"
            :key="item.path"
            @click="navigate(item.path)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              isActive(item.path)
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
            <span 
              v-if="isActive(item.path)" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Navigate ═══ -->
      <div class="mb-1">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showNavigate = !showNavigate"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showNavigate ? 'expand_more' : 'chevron_right' }}</span>
          Navigate
        </button>
        <div v-if="showNavigate" class="mt-0.5 space-y-0.5">
          <button
            @click="navigate('/campaigns')"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              isActive('/campaigns')
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">campaign</span>
            <span>Email Campaigns</span>
            <span 
              v-if="isActive('/campaigns')" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
          <button
            @click="navigate('/clients/overview')"
            class="w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200"
          >
            <span class="material-symbols-rounded text-lg">groups</span>
            <span>Clients Overview</span>
          </button>
        </div>
      </div>

    </nav>
  </aside>
</template>

