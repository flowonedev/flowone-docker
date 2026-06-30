<script setup>
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useNotificationsStore } from '@/stores/notifications'
import { useAddons } from '@/composables/useAddons'
import { usePerspectiveStore } from '@/stores/perspective'
import { useI18n } from 'vue-i18n'

const router = useRouter()
const { t } = useI18n()
const route = useRoute()
const todosStore = useTodosStore()
const chatStore = useChatStore()
const notificationsStore = useNotificationsStore()
const { kanbanBoardsEnabled, chatEnabled, calendarEnabled, tasksEnabled, timeTrackerEnabled, crmProEnabled, automationHubEnabled, emailMarketingEnabled, teamEnabled, moodboardsEnabled } = useAddons()
const perspectiveStore = usePerspectiveStore()

const addonFlags = computed(() => ({
  tasksEnabled: tasksEnabled.value,
  calendarEnabled: calendarEnabled.value,
  kanbanBoardsEnabled: kanbanBoardsEnabled.value,
  chatEnabled: chatEnabled.value,
  teamEnabled: teamEnabled.value,
  timeTrackerEnabled: timeTrackerEnabled.value,
  crmProEnabled: crmProEnabled.value,
  automationHubEnabled: automationHubEnabled.value,
  emailMarketingEnabled: emailMarketingEnabled.value,
  moodboardsEnabled: moodboardsEnabled.value,
}))

const mobileNavItems = computed(() => perspectiveStore.getMobileNavItems(addonFlags.value).filter(n => n.id !== 'chat'))

// Combined badge: chat unread + missed calls
const chatBadgeCount = computed(() => {
  return (chatStore.totalUnread || 0) + (notificationsStore.missedCallUnreadCount || 0)
})

const showMoreMenu = ref(false)

const props = defineProps({
  showTodoButton: {
    type: Boolean,
    default: true
  }
})

const emit = defineEmits(['toggle-todos'])

function isActive(routeName) {
  if (routeName === 'mailbox') {
    return route.name === 'mailbox' || route.name === 'mailbox-folder' || route.name === 'mailbox-email'
  }
  if (routeName === 'chat') {
    return route.path === '/chat'
  }
  return route.name === routeName
}

function goTo(routeName) {
  showMoreMenu.value = false
  if (routeName === 'chat') {
    router.push('/chat')
  } else {
    router.push({ name: routeName })
  }
}

function toggleTodos() {
  showMoreMenu.value = false
  if (props.showTodoButton) {
    todosStore.togglePanel()
  }
  emit('toggle-todos')
}
</script>

<template>
  <nav class="mobile-bottom-nav">
    <!-- Perspective-driven nav items -->
    <button
      v-for="nav in mobileNavItems"
      :key="nav.id"
      @click="nav.id === 'email' ? goTo('mailbox') : router.push(nav.path)"
      class="mobile-nav-item relative"
      :class="{ 'active': nav.id === 'email' ? isActive('mailbox') : isActive(nav.id) }"
    >
      <span class="material-symbols-rounded">{{ nav.icon }}</span>
      <span
        v-if="nav.id === 'chat' && chatBadgeCount > 0"
        :class="[
          'absolute top-0.5 right-1/4 w-4 h-4 text-[10px] font-bold text-white rounded-full flex items-center justify-center',
          notificationsStore.missedCallUnreadCount > 0 ? 'bg-red-500' : 'bg-primary-500'
        ]"
      >
        {{ chatBadgeCount > 9 ? '9+' : chatBadgeCount }}
      </span>
      <span class="mobile-nav-label">{{ $t(nav.labelKey) }}</span>
    </button>

    <!-- Chat (always visible) -->
    <button
      @click="router.push('/chat')"
      class="mobile-nav-item relative"
      :class="{ 'active': isActive('chat') }"
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
      <span class="mobile-nav-label">{{ $t('appHeader.chat') }}</span>
    </button>

    <!-- More Menu -->
    <div class="relative">
      <button 
        @click="showMoreMenu = !showMoreMenu"
        class="mobile-nav-item"
        :class="{ 'active': showMoreMenu }"
      >
        <span class="material-symbols-rounded">more_horiz</span>
        <span class="mobile-nav-label">{{ $t('appHeader.more') }}</span>
      </button>
      
      <!-- Fullscreen More Navigation -->
      <Teleport to="body">
        <Transition name="more-panel">
          <div v-if="showMoreMenu" class="more-panel-overlay" @click.self="showMoreMenu = false">
            <div class="more-panel">
              <!-- Header -->
              <div class="more-panel-header">
                <h2 class="text-base font-semibold text-surface-800 dark:text-surface-100">{{ $t('appHeader.more') }}</h2>
                <button @click="showMoreMenu = false" class="w-8 h-8 flex items-center justify-center rounded-full bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400">
                  <span class="material-symbols-rounded text-lg">close</span>
                </button>
              </div>

              <!-- Scrollable content -->
              <div class="more-panel-body">
                <!-- Perspective Switcher -->
                <div class="mb-3">
                  <p class="more-section-label">{{ $t('perspective.switchPerspective') }}</p>
                  <div class="flex items-center bg-surface-100 dark:bg-surface-700/60 rounded-full p-0.5 gap-0.5">
                    <button
                      v-for="p in [
                        { id: 'executive', icon: 'query_stats', label: $t('perspective.executive') },
                        { id: 'delivery', icon: 'engineering', label: $t('perspective.delivery') },
                        { id: 'operations', icon: 'hub', label: $t('perspective.operations') },
                      ]"
                      :key="p.id"
                      @click="perspectiveStore.setPerspective(p.id)"
                      class="flex-1 flex items-center justify-center gap-1 px-2.5 py-2 rounded-full text-xs font-medium transition-all"
                      :class="perspectiveStore.active === p.id
                        ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
                        : 'text-surface-500 dark:text-surface-400'"
                    >
                      <span class="material-symbols-rounded text-sm">{{ p.icon }}</span>
                      <span>{{ p.label }}</span>
                    </button>
                  </div>
                </div>

                <!-- Revenue Intelligence -->
                <template v-if="crmProEnabled || kanbanBoardsEnabled">
                  <p class="more-section-label">{{ $t('mobileBottomNav.revenue') }}</p>
                  <div class="more-section-grid">
                    <template v-if="crmProEnabled">
                      <button @click="goTo('crm-executive')" class="more-grid-item">
                        <span class="more-grid-icon bg-blue-50 dark:bg-blue-500/10 text-blue-500">
                          <span class="material-symbols-rounded">query_stats</span>
                        </span>
                        <span class="more-grid-label">{{ $t('appHeader.crmExecutive') }}</span>
                      </button>
                      <button @click="goTo('crm-pipeline')" class="more-grid-item">
                        <span class="more-grid-icon bg-violet-50 dark:bg-violet-500/10 text-violet-500">
                          <span class="material-symbols-rounded">conversion_path</span>
                        </span>
                        <span class="more-grid-label">{{ $t('appHeader.pipeline') }}</span>
                      </button>
                      <button @click="goTo('crm-invoices')" class="more-grid-item">
                        <span class="more-grid-icon bg-emerald-50 dark:bg-emerald-500/10 text-emerald-500">
                          <span class="material-symbols-rounded">receipt_long</span>
                        </span>
                        <span class="more-grid-label">{{ $t('appHeader.invoices') }}</span>
                      </button>
                    </template>
                    <button v-if="kanbanBoardsEnabled" @click="goTo('financials')" class="more-grid-item">
                      <span class="more-grid-icon bg-amber-50 dark:bg-amber-500/10 text-amber-500">
                        <span class="material-symbols-rounded">account_balance</span>
                      </span>
                      <span class="more-grid-label">{{ $t('appHeader.financials') }}</span>
                    </button>
                  </div>
                </template>

                <!-- Delivery Intelligence -->
                <template v-if="tasksEnabled || kanbanBoardsEnabled || timeTrackerEnabled">
                  <p class="more-section-label">{{ $t('mobileBottomNav.delivery') }}</p>
                  <div class="more-section-grid">
                    <button v-if="tasksEnabled" @click="router.push('/my-work'); showMoreMenu = false" class="more-grid-item">
                      <span class="more-grid-icon bg-teal-50 dark:bg-teal-500/10 text-teal-500">
                        <span class="material-symbols-rounded">assignment_ind</span>
                      </span>
                      <span class="more-grid-label">{{ $t('appHeader.myWork') }}</span>
                    </button>
                    <button v-if="kanbanBoardsEnabled" @click="goTo('boards')" class="more-grid-item">
                      <span class="more-grid-icon bg-indigo-50 dark:bg-indigo-500/10 text-indigo-500">
                        <span class="material-symbols-rounded">dashboard</span>
                      </span>
                      <span class="more-grid-label">{{ $t('appHeader.projects') }}</span>
                    </button>
                    <button v-if="timeTrackerEnabled" @click="goTo('time')" class="more-grid-item">
                      <span class="more-grid-icon bg-orange-50 dark:bg-orange-500/10 text-orange-500">
                        <span class="material-symbols-rounded">timer</span>
                      </span>
                      <span class="more-grid-label">{{ $t('appHeader.timeTracker') }}</span>
                    </button>
                  </div>
                </template>

                <!-- Client Intelligence -->
                <p class="more-section-label">{{ $t('mobileBottomNav.client') }}</p>
                <div class="more-section-grid">
                  <button @click="goTo('clients')" class="more-grid-item">
                    <span class="more-grid-icon bg-pink-50 dark:bg-pink-500/10 text-pink-500">
                      <span class="material-symbols-rounded">groups</span>
                    </span>
                    <span class="more-grid-label">{{ $t('appHeader.clients') }}</span>
                  </button>
                </div>

                <!-- Operations Intelligence -->
                <p class="more-section-label">{{ $t('mobileBottomNav.operations') }}</p>
                <div class="more-section-grid">
                  <button v-if="calendarEnabled" @click="goTo('calendar')" class="more-grid-item">
                    <span class="more-grid-icon bg-sky-50 dark:bg-sky-500/10 text-sky-500">
                      <span class="material-symbols-rounded">calendar_month</span>
                    </span>
                    <span class="more-grid-label">{{ $t('appHeader.calendar') }}</span>
                  </button>
                  <button v-if="teamEnabled" @click="goTo('team')" class="more-grid-item">
                    <span class="more-grid-icon bg-cyan-50 dark:bg-cyan-500/10 text-cyan-500">
                      <span class="material-symbols-rounded">diversity_3</span>
                    </span>
                    <span class="more-grid-label">{{ $t('appHeader.team') }}</span>
                  </button>
                  <button v-if="automationHubEnabled" @click="goTo('automation-hub')" class="more-grid-item">
                    <span class="more-grid-icon bg-purple-50 dark:bg-purple-500/10 text-purple-500">
                      <span class="material-symbols-rounded">settings_suggest</span>
                    </span>
                    <span class="more-grid-label">{{ $t('appHeader.automationHub') }}</span>
                  </button>
                  <button v-if="emailMarketingEnabled" @click="goTo('campaigns')" class="more-grid-item">
                    <span class="more-grid-icon bg-rose-50 dark:bg-rose-500/10 text-rose-500">
                      <span class="material-symbols-rounded">campaign</span>
                    </span>
                    <span class="more-grid-label">{{ $t('appHeader.emailCampaigns') }}</span>
                  </button>
                  <button v-if="moodboardsEnabled" @click="goTo('mood')" class="more-grid-item">
                    <span class="more-grid-icon bg-fuchsia-50 dark:bg-fuchsia-500/10 text-fuchsia-500">
                      <span class="material-symbols-rounded">dashboard_customize</span>
                    </span>
                    <span class="more-grid-label">Moodboards</span>
                  </button>
                </div>

                <!-- Infrastructure -->
                <p class="more-section-label">{{ $t('mobileBottomNav.infrastructure') }}</p>
                <div class="more-section-grid mb-4">
                  <button @click="goTo('drive')" class="more-grid-item">
                    <span class="more-grid-icon bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400">
                      <span class="material-symbols-rounded">cloud</span>
                    </span>
                    <span class="more-grid-label">{{ $t('appHeader.assetVault') }}</span>
                  </button>
                  <button @click="goTo('settings')" class="more-grid-item">
                    <span class="more-grid-icon bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400">
                      <span class="material-symbols-rounded">settings</span>
                    </span>
                    <span class="more-grid-label">{{ $t('appHeader.settings') }}</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </div>
  </nav>
</template>

<style scoped>
/* Fullscreen More Panel */
.more-panel-overlay {
  position: fixed;
  inset: 0;
  z-index: 100;
  background: rgba(0, 0, 0, 0.4);
}

.more-panel {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  top: 4rem;
  z-index: 101;
  background: white;
  border-radius: 1.5rem 1.5rem 0 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

:deep(.dark) .more-panel,
.dark .more-panel {
  background: rgb(var(--color-surface));
}

.more-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.875rem 1.25rem;
  flex-shrink: 0;
}

.more-panel-body {
  flex: 1;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 0 1.25rem 1.5rem;
}

.more-section-label {
  @apply text-[10px] font-semibold text-surface-400 uppercase tracking-wider mb-2 mt-3;
}

.more-section-label:first-child {
  margin-top: 0;
}

.more-section-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0;
}

.more-grid-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.375rem;
  padding: 0.5rem 0.25rem;
  border-radius: 0.75rem;
  transition: background-color 0.15s;
}

.more-grid-item:active {
  @apply bg-surface-100 dark:bg-surface-700;
}

.more-grid-icon {
  width: 3.25rem;
  height: 3.25rem;
  border-radius: 1rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

.more-grid-icon .material-symbols-rounded {
  font-size: 1.5rem;
}

.more-grid-label {
  @apply text-[11px] font-medium text-surface-700 dark:text-surface-300 text-center leading-tight;
}

/* Slide-up transition */
.more-panel-enter-active {
  transition: opacity 0.2s ease;
}
.more-panel-enter-active .more-panel {
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.more-panel-leave-active {
  transition: opacity 0.15s ease;
}
.more-panel-leave-active .more-panel {
  transition: transform 0.2s ease-in;
}
.more-panel-enter-from {
  opacity: 0;
}
.more-panel-enter-from .more-panel {
  transform: translateY(100%);
}
.more-panel-leave-to {
  opacity: 0;
}
.more-panel-leave-to .more-panel {
  transform: translateY(100%);
}
</style>

