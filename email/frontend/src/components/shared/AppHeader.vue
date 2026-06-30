<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAccountsStore } from '@/stores/accounts'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useNotificationsStore } from '@/stores/notifications'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import { usePerspectiveStore } from '@/stores/perspective'
import { useOnboardingStore } from '@/stores/onboarding'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import PerspectiveSwitcher from '@/components/shared/PerspectiveSwitcher.vue'
import WeatherChip from '@/components/shared/WeatherChip.vue'
import StorageWatermarkIndicator from '@/components/storage/StorageWatermarkIndicator.vue'
import { isIOSNativePlatform } from '@/utils/platform'
import logoUrl from '@/assets/flowone-logo.png'

// App Store Guideline 4/4.8: no add-account (multi-account uses system-browser
// OAuth) on native iOS - strict single org account.
const iosNative = isIOSNativePlatform()

const props = defineProps({
  currentView: {
    type: String,
    required: true,
    validator: (v) => [
      'email', 'calendar', 'contacts', 'drive', 'boards', 'mood', 'clients',
      'chat', 'team', 'mailing-lists', 'campaigns',
      'financials', 'time', 'settings', 'automation-hub', 'automation-hub-editor',
      'crm-pipeline', 'crm-invoices', 'crm-dashboard', 'crm-executive',
      'crm-automation', 'crm-sequences', 'crm-sharing'
    ].includes(v)
  },
  icon: { type: String, required: true },
  title: { type: String, required: true },
  showMobileMenu: { type: Boolean, default: false },
  // Optional custom avatar — replaces the default icon square
  avatarUrl: { type: String, default: '' },
  avatarColor: { type: String, default: '' },
  avatarText: { type: String, default: '' },
  // When true, hides the brand logo + title block on the left. Used by views
  // (e.g. Email) that move the FlowOne brand into their own left sidebar so the
  // header reclaims the space for a centered search pill.
  hideBranding: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle-sidebar', 'icon-click'])

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const accountsStore = useAccountsStore()
const theme = useThemeStore()
const layout = useLayoutStore()
const todosStore = useTodosStore()
const notificationsStore = useNotificationsStore()
const calendarStore = useCalendarStore()
const toast = useToastStore()

// Bell badge = server notifications + active calendar reminders
const bellBadgeCount = computed(() => (notificationsStore.unreadCount || 0) + (calendarStore.activeReminders?.length || 0))
const { crmProEnabled, moodboardsEnabled, kanbanBoardsEnabled, chatEnabled, emailMarketingEnabled, teamEnabled, calendarEnabled, tasksEnabled, timeTrackerEnabled, automationHubEnabled, fetchAddons } = useAddons()
const perspectiveStore = usePerspectiveStore()
const onboardingStore = useOnboardingStore()
const { locale, t } = useI18n()

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

// Fetch addon status on mount (fire-and-forget, non-blocking)
fetchAddons()

// Dropdown states
const showAccountDropdown = ref(false)
const showMoreMenu = ref(false)
const showLogoutDialog = ref(false)
const isElectronApp = typeof window !== 'undefined' && !!window.api

// Migration notice badge (frontend-only). During a migration cut-over the MX
// records still point at the old host, so outgoing mail won't deliver yet —
// this surfaces a "BETA / SEND BLOCKED" hint next to the brand. It is purely
// informational; sending is NOT disabled in code. Enable it with a build-time
// VITE_BETA_BADGE=true, or at runtime via
// localStorage.setItem('flowone_beta_badge', '1') (then reload).
const showMigrationBadge = ref(false)
try {
  const envFlag = String(import.meta.env?.VITE_BETA_BADGE ?? '').toLowerCase() === 'true'
  const lsFlag = ['1', 'true', 'on'].includes(
    String(localStorage.getItem('flowone_beta_badge') ?? '').toLowerCase()
  )
  showMigrationBadge.value = envFlag || lsFlag
} catch (_) {
  showMigrationBadge.value = false
}

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

// Primary nav items driven by active perspective
const primaryNavItems = computed(() => {
  return perspectiveStore.getPrimaryNavItems(addonFlags.value)
})

// CRM Pro nav items (only shown when addon is enabled)
const crmProNavItems = [
  { id: 'crm-executive', path: '/crm/executive', icon: 'query_stats', labelKey: 'appHeader.crmExecutive' },
  { id: 'crm-dashboard', path: '/crm/dashboard', icon: 'monitoring', labelKey: 'appHeader.crmDashboard' },
  { id: 'crm-pipeline', path: '/crm/pipeline', icon: 'conversion_path', labelKey: 'appHeader.pipeline' },
  { id: 'crm-invoices', path: '/crm/invoices', icon: 'receipt_long', labelKey: 'appHeader.invoices' },
  { id: 'crm-sequences', path: '/crm/sequences', icon: 'route', labelKey: 'appHeader.sequences' },
]

// Pinned items: Email + Calendar always first, regardless of perspective
const pinnedNavItems = computed(() => {
  const items = [
    { id: 'email', path: '/inbox', icon: 'mail', labelKey: 'appHeader.mail' },
  ]
  if (calendarEnabled.value) {
    items.push({ id: 'calendar', path: '/calendar', icon: 'calendar_month', labelKey: 'appHeader.calendar' })
  }
  return items.filter(item => item.id !== props.currentView)
})

// All 5 intelligence layer groups — Email & Calendar excluded (pinned above)
const moreNavGroups = computed(() => {
  // Layer 1: Revenue Intelligence
  const revenueItems = [
    ...(crmProEnabled.value ? [...crmProNavItems] : []),
    ...(kanbanBoardsEnabled.value ? [{ id: 'financials', path: '/financials', icon: 'account_balance', labelKey: 'appHeader.financials' }] : []),
  ]

  // Layer 2: Delivery Intelligence
  const deliveryItems = [
    ...(tasksEnabled.value ? [{ id: 'my-work', path: '/my-work', icon: 'assignment_ind', labelKey: 'appHeader.myWork' }] : []),
    ...(kanbanBoardsEnabled.value ? [{ id: 'boards', path: '/boards', icon: 'dashboard', labelKey: 'appHeader.projects' }] : []),
    ...(timeTrackerEnabled.value ? [{ id: 'time', path: '/time', icon: 'timer', labelKey: 'appHeader.timeTracker' }] : []),
  ]

  // Layer 3: Client Intelligence + Infrastructure (merged to avoid single-item rows)
  const clientInfraItems = [
    { id: 'clients', path: '/clients/overview', icon: 'groups', labelKey: 'appHeader.clients' },
    { id: 'drive', path: '/drive', icon: 'cloud', labelKey: 'appHeader.assetVault' },
  ]

  // Layer 4: Operations Intelligence (without email & calendar — those are pinned)
  const operationsItems = [
    { id: 'contacts', path: '/contacts', icon: 'contacts', labelKey: 'appHeader.contacts' },
    ...(chatEnabled.value ? [{ id: 'chat', path: '/chat', icon: 'chat', labelKey: 'appHeader.chat' }] : []),
    ...(teamEnabled.value ? [{ id: 'team', path: '/team', icon: 'diversity_3', labelKey: 'appHeader.team' }] : []),
    ...(automationHubEnabled.value ? [{ id: 'automation-hub', path: '/automation-hub', icon: 'settings_suggest', labelKey: 'appHeader.automationHub' }] : []),
    ...(emailMarketingEnabled.value ? [
      { id: 'mailing-lists', path: '/mailing-lists', icon: 'contact_mail', labelKey: 'appHeader.emailingLists' },
      { id: 'campaigns', path: '/campaigns', icon: 'campaign', labelKey: 'appHeader.emailCampaigns' },
    ] : []),
    ...(moodboardsEnabled.value ? [{ id: 'mood', path: '/mood', icon: 'dashboard_customize', labelKey: 'appHeader.moodBoards' }] : []),
  ]

  const allGroups = {
    'appHeader.revenueIntelligence': revenueItems,
    'appHeader.operationsIntelligence': operationsItems,
    'appHeader.deliveryIntelligence': deliveryItems,
    'appHeader.clientInfra': clientInfraItems,
  }

  const groupOrder = perspectiveStore.getGroupOrder()
  const groups = []
  for (const labelKey of groupOrder) {
    const items = allGroups[labelKey]
    if (items && items.length > 0) {
      groups.push({ labelKey, items })
    }
  }

  return groups
})

// Flat list for filtering (used by activeMoreNav fallback)
const moreNavItems = computed(() => {
  return moreNavGroups.value.flatMap(g => g.items)
})

// Filter out the current view from nav items
const activePrimaryNav = computed(() =>
  primaryNavItems.value.filter(item => item.id !== props.currentView)
)

// Grouped nav with current view filtered out
const activeMoreNavGroups = computed(() => {
  return moreNavGroups.value
    .map(group => ({
      ...group,
      items: group.items.filter(item => item.id !== props.currentView)
    }))
    .filter(group => group.items.length > 0)
})

const activeMoreNav = computed(() =>
  moreNavItems.value.filter(item => item.id !== props.currentView)
)

// Layer-based icon colors — consistent per intelligence layer
const LAYER_COLORS = {
  purple: 'bg-purple-500/15 text-purple-600 dark:bg-purple-400/15 dark:text-purple-400',
  blue: 'bg-blue-500/15 text-blue-600 dark:bg-blue-400/15 dark:text-blue-400',
  emerald: 'bg-emerald-500/15 text-emerald-600 dark:bg-emerald-400/15 dark:text-emerald-400',
  amber: 'bg-amber-500/15 text-amber-600 dark:bg-amber-400/15 dark:text-amber-400',
  slate: 'bg-slate-500/15 text-slate-600 dark:bg-slate-400/15 dark:text-slate-400',
}
const ICON_COLORS = {
  // Revenue — purple
  query_stats: LAYER_COLORS.purple,
  monitoring: LAYER_COLORS.purple,
  conversion_path: LAYER_COLORS.purple,
  receipt_long: LAYER_COLORS.purple,
  route: LAYER_COLORS.purple,
  account_balance: LAYER_COLORS.purple,
  // Operations — blue
  mail: LAYER_COLORS.blue,
  calendar_month: LAYER_COLORS.blue,
  contacts: LAYER_COLORS.blue,
  chat: LAYER_COLORS.blue,
  diversity_3: LAYER_COLORS.blue,
  settings_suggest: LAYER_COLORS.blue,
  contact_mail: LAYER_COLORS.blue,
  campaign: LAYER_COLORS.blue,
  dashboard_customize: LAYER_COLORS.blue,
  // Delivery — green
  dashboard: LAYER_COLORS.emerald,
  timer: LAYER_COLORS.emerald,
  // Client — gold
  groups: LAYER_COLORS.amber,
  // Infrastructure — slate/gray
  cloud: LAYER_COLORS.slate,
}
const DEFAULT_ICON_COLOR = 'bg-surface-500/15 text-surface-600 dark:bg-surface-400/15 dark:text-surface-400'

// Map current view to its intelligence layer for breadcrumb display
const LAYER_MAP = {
  'email': 'appHeader.operationsIntelligence',
  'calendar': 'appHeader.operationsIntelligence',
  'contacts': 'appHeader.operationsIntelligence',
  'boards': 'appHeader.deliveryIntelligence',
  'financials': 'appHeader.revenueIntelligence',
  'time': 'appHeader.deliveryIntelligence',
  'clients': 'appHeader.clientIntelligence',
  'chat': 'appHeader.operationsIntelligence',
  'team': 'appHeader.operationsIntelligence',
  'mood': 'appHeader.operationsIntelligence',
  'mailing-lists': 'appHeader.operationsIntelligence',
  'campaigns': 'appHeader.operationsIntelligence',
  'automation-hub': 'appHeader.operationsIntelligence',
  'automation-hub-editor': 'appHeader.operationsIntelligence',
  'crm-pipeline': 'appHeader.revenueIntelligence',
  'crm-invoices': 'appHeader.revenueIntelligence',
  'crm-dashboard': 'appHeader.revenueIntelligence',
  'crm-executive': 'appHeader.revenueIntelligence',
  'crm-sequences': 'appHeader.revenueIntelligence',
  'crm-sharing': 'appHeader.revenueIntelligence',
  'drive': 'appHeader.infrastructureIntelligence',
}
const LAYER_ICONS = {
  'appHeader.revenueIntelligence': 'payments',
  'appHeader.deliveryIntelligence': 'engineering',
  'appHeader.clientIntelligence': 'groups',
  'appHeader.operationsIntelligence': 'hub',
  'appHeader.infrastructureIntelligence': 'dns',
}
const currentLayer = computed(() => LAYER_MAP[props.currentView] || null)
const currentLayerIcon = computed(() => currentLayer.value ? LAYER_ICONS[currentLayer.value] : null)

// Flattened groups with current view filtered out (used for the grid)
const appGridGroups = computed(() => {
  return activeMoreNavGroups.value
})

// Account handling
const allAccounts = computed(() => {
  const primaryAccount = {
    id: 'primary',
    account_email: auth.userEmail,
    display_name: auth.displayName,
    is_primary: true,
    is_default: accountsStore.accounts.length === 0,
  }
  return [primaryAccount, ...accountsStore.accounts]
})

const currentAccount = computed(() => {
  if (!accountsStore.activeAccountId || accountsStore.activeAccountId === 'primary') {
    return allAccounts.value[0]
  }
  return allAccounts.value.find(a => a.id === parseInt(accountsStore.activeAccountId)) || allAccounts.value[0]
})

function getAccountInitials(account) {
  if (!account) return '?'
  const name = account.display_name || account.account_email
  return name.substring(0, 2).toUpperCase()
}

// Accent color mapping
const accentColorMap = {
  green: '#22c55e',
  red: '#ef4444',
  purple: '#a855f7',
  blue: '#3b82f6',
  gold: '#eab308',
  mono: '#404040',
  teal: '#14b8a6',
  orange: '#f97316',
  gradient: '#a855f7',
}

const avatarKey = ref(0)

watch(() => theme.accentColor, () => { avatarKey.value++ })
watch(() => accountsStore.activeAccountId, () => { avatarKey.value++ })

function getAccountAccentColor(account) {
  const _ = avatarKey.value
  const accountId = account.id === 'primary' ? 'primary' : account.id
  const accentId = localStorage.getItem(`webmail_accent_${accountId}`)
    || localStorage.getItem('webmail_accent')
    || 'green'
  return accentColorMap[accentId] || accentColorMap.green
}

function getAccountAvatarStyle(account) {
  return { backgroundColor: getAccountAccentColor(account) }
}

async function switchAccount(account) {
  showAccountDropdown.value = false
  const accountId = account.id === 'primary' ? 'primary' : account.id
  // Set the active account (persists to localStorage)
  accountsStore.setActiveAccount(accountId, theme)
  toast.success(`${t('appHeader.switchedTo')} ${account.display_name || account.account_email}`)
  // Reload to refresh all view data for the new account
  setTimeout(() => window.location.reload(), 300)
}

async function logoutAuxiliaryAccount(e, account) {
  e.stopPropagation()

  if (account.is_primary) {
    toast.warning(t('appHeader.useTheMainSignOut'))
    return
  }

  const result = await accountsStore.removeAccountByType(account)

  if (result) {
    toast.success(`${t('appHeader.loggedOutOf')} ${account.account_email}`)
    showAccountDropdown.value = false
  } else {
    toast.error(t('appHeader.failedToLogoutFromAccount'))
  }
}

// Toggle display density
function toggleDensity() {
  const newDensity = theme.displayDensity === 'cosy' ? 'compact' : 'cosy'
  theme.setDisplayDensity(newDensity)
}

// Language switcher
const availableLanguages = [
  { code: 'en', label: 'English', flag: 'EN' },
  { code: 'hu', label: 'Magyar', flag: 'HU' },
]

// The "other" language the button switches TO. With only two languages this
// acts as a direct toggle, so the chip shows the target (e.g. HU while on EN)
// rather than the current language — consistent with the other Display toggles.
const otherLang = computed(() => {
  return availableLanguages.find(l => l.code !== locale.value) || availableLanguages[0]
})

async function switchLanguage(langCode) {
  await setLocale(langCode)
}

// No longer needed - notifications panel is managed by the store globally
</script>

<template>
  <header class="flex items-center justify-between px-3 py-0.5 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex-shrink-0 safe-area-top min-h-safe-top">
    <!-- Left side: mobile menu + logo + title -->
    <div class="flex items-center gap-3">
      <!-- Mobile hamburger (optional) -->
      <button
        v-if="showMobileMenu"
        @click="emit('toggle-sidebar')"
        class="btn-ghost btn-icon md:hidden"
      >
        <span class="material-symbols-rounded">menu</span>
      </button>

      <!-- View icon / custom avatar (hidden when the view supplies its own branding, e.g. Email) -->
      <component
        v-if="!hideBranding"
        :is="icon === 'arrow_back' ? 'button' : 'div'"
        class="w-8 h-8 rounded-lg hidden sm:flex items-center justify-center logo-icon overflow-hidden"
        :class="[
          avatarUrl ? '' :
          avatarColor ? '' :
          (avatarText || icon === 'arrow_back') ? 'bg-primary-500' : '',
          { 'cursor-pointer hover:opacity-80 transition-opacity': icon === 'arrow_back' }
        ]"
        :style="avatarUrl 
          ? { backgroundImage: `url(${avatarUrl})`, backgroundSize: 'cover', backgroundPosition: 'center' }
          : avatarColor 
            ? { backgroundColor: avatarColor }
            : {}"
        @click="icon === 'arrow_back' && emit('icon-click')"
      >
        <!-- Custom avatar text (initials) when no image -->
        <span 
          v-if="!avatarUrl && avatarText"
          class="text-white text-[10px] font-bold uppercase leading-none"
        >{{ avatarText }}</span>
        <!-- Back arrow keeps a material symbol so detail-view back buttons stay readable -->
        <span 
          v-else-if="!avatarUrl && icon === 'arrow_back'"
          class="material-symbols-rounded text-white text-lg"
        >arrow_back</span>
        <!-- All other views show the FlowOne brand logo -->
        <img
          v-else-if="!avatarUrl"
          :src="logoUrl"
          alt="FlowOne"
          class="w-full h-full object-contain"
        />
      </component>

      <!-- View title -->
      <span v-if="!hideBranding" class="font-semibold text-surface-900 dark:text-surface-100 hidden sm:inline">{{ title }}</span>

      <!-- Optional title badge slot -->
      <slot name="title-badge" />

      <!-- Migration notice (frontend-only; informational). Shows during the
           cut-over window when MX still points at the old host. -->
      <div v-if="showMigrationBadge" class="flex items-center gap-1.5">
        <span
          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide
                 bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400"
          title="This account is being migrated"
        >
          <span class="material-symbols-rounded text-xs">science</span>
          Beta
        </span>
        <span
          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide
                 bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400"
          title="Sending is paused until DNS/MX cut-over completes"
        >
          <span class="material-symbols-rounded text-xs">block</span>
          Send blocked
        </span>
      </div>
    </div>

    <!-- Optional center slot (used by Mailbox + Drive views for a global
         search pill). The wrapper is intentionally uncapped — it grows to
         fill ALL space between the left brand block and the right action
         cluster. Width is the slot child's responsibility; if a consumer
         wants to cap it, the child element should set its own max-width. -->
    <div
      v-if="$slots.center"
      class="flex-1 flex px-3 min-w-0"
    >
      <slot name="center" />
    </div>

    <!-- Right side -->
    <div class="flex items-center gap-2">
      <!-- Weather chip (desktop only) -->
      <WeatherChip class="hidden sm:flex" />

      <!-- Storage watermark indicator (hidden when budget is clear/unknown) -->
      <StorageWatermarkIndicator />

      <div class="w-px h-6 bg-surface-200 dark:bg-surface-700 mx-1 hidden sm:block"></div>

      <!-- Platform tour button (desktop only, opt-in via Settings) -->
      <template v-if="onboardingStore.tourButtonEnabled">
        <button
          @click="onboardingStore.open()"
          class="btn-ghost btn-icon hidden sm:flex"
          title="Platform Tour"
        >
          <span class="material-symbols-rounded">rocket_launch</span>
        </button>

        <div class="w-px h-6 bg-surface-200 dark:bg-surface-700 mx-1 hidden sm:block"></div>
      </template>

      <!-- Notifications button -->
      <button
        @click="notificationsStore.togglePanel()"
        class="btn-ghost btn-icon relative"
        :title="$t('appHeader.notifications')"
      >
        <span class="material-symbols-rounded">notifications</span>
        <span
          v-if="bellBadgeCount > 0"
          class="absolute -top-0.5 -right-0.5 w-4 h-4 text-[10px] font-bold bg-red-500 text-white rounded-full flex items-center justify-center"
        >
          {{ bellBadgeCount > 9 ? '9+' : bellBadgeCount }}
        </span>
      </button>

      <!-- Todo button (desktop only) -->
      <button
        v-if="tasksEnabled"
        @click="todosStore.togglePanel()"
        class="btn-ghost btn-icon relative"
        :title="$t('appHeader.todoList')"
        :class="{ 'text-primary-500': todosStore.panelOpen }"
      >
        <span class="material-symbols-rounded">checklist</span>
        <span
          v-if="todosStore.incompleteCount > 0"
          class="absolute -top-0.5 -right-0.5 w-4 h-4 text-[10px] font-bold bg-primary-500 text-white rounded-full flex items-center justify-center"
        >
          {{ todosStore.incompleteCount > 9 ? '9+' : todosStore.incompleteCount }}
        </span>
      </button>

      <div class="w-px h-6 bg-surface-200 dark:bg-surface-700 mx-1 hidden sm:block"></div>

      <!-- Primary Navigation - hidden on small mobile -->
      <div class="hidden sm:flex items-center gap-1">
        <button
          v-for="nav in activePrimaryNav"
          :key="nav.id"
          @click="router.push(nav.path)"
          class="btn-ghost btn-icon"
          :title="$t(nav.labelKey)"
        >
          <span class="material-symbols-rounded">{{ nav.icon }}</span>
        </button>

        <div class="w-px h-6 bg-surface-200 dark:bg-surface-700 mx-1"></div>

        <!-- More Menu Dropdown -->
        <div class="relative">
          <button
            @click="showMoreMenu = !showMoreMenu"
            class="btn-ghost btn-icon"
            :title="$t('appHeader.moreOptions')"
            :class="{ 'bg-surface-100 dark:bg-surface-700': showMoreMenu }"
          >
            <span class="material-symbols-rounded">apps</span>
          </button>

          <!-- More Menu Backdrop -->
          <div
            v-if="showMoreMenu"
            class="fixed inset-0 z-[9998]"
            @click="showMoreMenu = false"
          ></div>

          <!-- App Launcher Grid Panel.
               Width is density-aware and FIXED — do not change these values
               without explicit instruction. The Display row is the tightest
               horizontal constraint. Chips show the TARGET state, so in cosy
               density the density chip reads the wider "Compact" — the cosy
               panel is sized to fit that worst case (the row also flex-wraps as
               a safety net).
                 cosy    => 462px
                 compact => 490px -->
          <div
            v-if="showMoreMenu"
            :class="[
              'absolute right-0 top-full mt-1 bg-white dark:bg-surface-800 rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 z-[9999] max-h-[85vh] overflow-y-auto',
              theme.displayDensity === 'compact' ? 'w-[490px]' : 'w-[462px]'
            ]"
          >
            <!-- Pinned: Email + Calendar always first -->
            <div v-if="pinnedNavItems.length" class="px-3 pt-3 pb-1">
              <div class="flex gap-1">
                <button
                  v-for="nav in pinnedNavItems"
                  :key="nav.id"
                  @click="router.push(nav.path); showMoreMenu = false"
                  class="flex items-center gap-2 px-3 py-2 rounded-xl transition-colors group/tile"
                  :class="nav.id === currentView
                    ? 'bg-primary-50 dark:bg-primary-500/10'
                    : 'hover:bg-surface-50 dark:hover:bg-surface-700/50'"
                >
                  <div
                    class="w-8 h-8 rounded-lg flex items-center justify-center"
                    :class="ICON_COLORS[nav.icon] || DEFAULT_ICON_COLOR"
                  >
                    <span class="material-symbols-rounded text-base">{{ nav.icon }}</span>
                  </div>
                  <span
                    class="text-xs font-medium"
                    :class="nav.id === currentView
                      ? 'text-primary-600 dark:text-primary-400'
                      : 'text-surface-600 dark:text-surface-300'"
                  >{{ $t(nav.labelKey) }}</span>
                </button>
              </div>
            </div>

            <!-- App Grid - grouped by intelligence layer -->
            <div class="px-3 pt-1 pb-1">
              <template v-for="(group, gi) in appGridGroups" :key="gi">
                <div v-if="gi > 0" class="border-t border-surface-100 dark:border-surface-700/50 my-1.5"></div>
                <p v-if="group.labelKey" class="uppercase tracking-wider" :class="group.labelKey === 'appHeader.revenueIntelligence'
                  ? 'mb-1 text-[10px] font-bold text-purple-500 dark:text-purple-400'
                  : 'mb-1 text-[10px] font-semibold text-surface-400'">{{ $t(group.labelKey) }}</p>
                <div class="flex flex-wrap gap-0">
                  <button
                    v-for="nav in group.items"
                    :key="nav.id"
                    @click="router.push(nav.path); showMoreMenu = false"
                    class="flex flex-col items-center gap-1 w-[60px] py-1.5 rounded-lg transition-colors group/tile"
                    :class="nav.id === currentView
                      ? 'bg-primary-50 dark:bg-primary-500/10'
                      : 'hover:bg-surface-50 dark:hover:bg-surface-700/50'"
                  >
                    <div
                      class="w-9 h-9 rounded-lg flex items-center justify-center transition-transform group-hover/tile:scale-105"
                      :class="ICON_COLORS[nav.icon] || DEFAULT_ICON_COLOR"
                    >
                      <span class="material-symbols-rounded text-lg">{{ nav.icon }}</span>
                    </div>
                    <span
                      class="text-[10px] leading-tight text-center line-clamp-2 w-full"
                      :class="nav.id === currentView
                        ? 'font-semibold text-primary-600 dark:text-primary-400'
                        : 'text-surface-600 dark:text-surface-300'"
                    >{{ $t(nav.labelKey) }}</span>
                  </button>
                </div>
              </template>
            </div>

            <!-- Perspective Switcher in More Menu -->
            <div class="border-t border-surface-100 dark:border-surface-700/50 px-3 py-2">
              <p class="mb-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wider">{{ $t('perspective.switchPerspective') }}</p>
              <PerspectiveSwitcher />
            </div>

            <!-- Display Options - compact row -->
            <div class="border-t border-surface-100 dark:border-surface-700/50 px-3 py-2">
              <p class="mb-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wider">{{ $t('appHeader.display') }}</p>
              <div class="flex flex-wrap gap-1.5">
                <!-- Each toggle shows the state you'll GET when you click it
                     (the target), not the current state — e.g. while in the
                     2-column layout the chip reads "3-Column". -->
                <button
                  @click="layout.toggleLayout()"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                >
                  <span class="material-symbols-rounded text-sm">{{ layout.isColumnsLayout ? 'view_agenda' : 'view_column' }}</span>
                  {{ layout.isColumnsLayout ? $t('appHeader.twoColumn') : $t('appHeader.threeColumn') }}
                </button>
                <button
                  @click="toggleDensity()"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                >
                  <span class="material-symbols-rounded text-sm">{{ theme.displayDensity === 'cosy' ? 'density_small' : 'density_medium' }}</span>
                  {{ theme.displayDensity === 'cosy' ? $t('appHeader.compact') : $t('appHeader.cosy') }}
                </button>
                <button
                  @click="theme.toggleTheme()"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                >
                  <span class="material-symbols-rounded text-sm">{{ theme.isDark ? 'light_mode' : 'dark_mode' }}</span>
                  {{ theme.isDark ? $t('appHeader.light') : $t('appHeader.dark') }}
                </button>
                <button
                  @click="router.push('/settings'); showMoreMenu = false"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                >
                  <span class="material-symbols-rounded text-sm">settings</span>
                  {{ $t('appHeader.settings') }}
                </button>
                <button
                  @click="switchLanguage(otherLang.code)"
                  :title="otherLang.label"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                >
                  <span class="material-symbols-rounded text-sm">translate</span>
                  {{ otherLang.flag }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Account Dropdown -->
      <div class="relative sm:pl-2 sm:border-l border-surface-200 dark:border-[rgb(var(--color-border))]">
        <button
          @click="showAccountDropdown = !showAccountDropdown"
          class="flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
        >
          <UserAvatar
            :email="currentAccount.account_email"
            :name="currentAccount.display_name || currentAccount.account_email"
            size="sm"
          />
          <div class="text-left">
            <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate max-w-[120px]">
              {{ currentAccount.display_name || currentAccount.account_email.split('@')[0] }}
            </p>
          </div>
          <span class="material-symbols-rounded text-surface-400 text-sm hidden sm:inline">
            {{ showAccountDropdown ? 'expand_less' : 'expand_more' }}
          </span>
        </button>

        <!-- Dropdown Backdrop -->
        <div
          v-if="showAccountDropdown"
          class="fixed inset-0 z-40"
          @click="showAccountDropdown = false"
        ></div>

        <!-- Account Dropdown Panel -->
        <div
          v-if="showAccountDropdown"
          class="absolute right-0 top-full mt-1 w-72 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 z-50 overflow-hidden"
        >
          <!-- Current account info -->
          <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700">
            <p class="text-sm font-medium text-surface-900 dark:text-surface-100">
              {{ currentAccount.display_name || currentAccount.account_email.split('@')[0] }}
            </p>
            <p class="text-xs text-surface-500">{{ currentAccount.account_email }}</p>
          </div>

          <!-- Accounts List -->
          <div class="max-h-48 overflow-y-auto py-1">
            <div
              v-for="account in allAccounts"
              :key="account.id"
              @click="switchAccount(account)"
              :class="[
                'group w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors cursor-pointer',
                currentAccount.id === account.id ? 'bg-primary-50 dark:bg-primary-500/10' : ''
              ]"
            >
              <UserAvatar
                :email="account.account_email"
                :name="account.display_name || account.account_email"
                size="md"
              />
              <div class="flex-1 min-w-0 text-left">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate flex items-center gap-1.5">
                  <span class="truncate">{{ account.display_name || account.account_email.split('@')[0] }}</span>
                  <span v-if="account.is_primary" class="text-xs text-surface-400">{{ $t('appHeader.primary') }}</span>
                  <!-- Unread count badge -->
                  <span
                    v-if="accountsStore.getUnreadCount(account.is_primary ? 'primary' : account.id) > 0"
                    class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 text-[10px] font-bold bg-red-500 text-white rounded-full"
                  >
                    {{ accountsStore.getUnreadCount(account.is_primary ? 'primary' : account.id) > 99 ? '99+' : accountsStore.getUnreadCount(account.is_primary ? 'primary' : account.id) }}
                  </span>
                </p>
                <p class="text-xs text-surface-500 truncate">{{ account.account_email }}</p>
              </div>
              <!-- Active indicator -->
              <span v-if="currentAccount.id === account.id" class="material-symbols-rounded text-primary-500 shrink-0">check</span>
              <!-- Logout button for non-primary accounts -->
              <button
                v-if="!account.is_primary"
                @click="logoutAuxiliaryAccount($event, account)"
                class="p-1.5 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-400 hover:text-red-500 transition-all shrink-0"
                :title="$t('appHeader.logoutFromThisAccount')"
              >
                <span class="material-symbols-rounded text-lg">logout</span>
              </button>
            </div>
          </div>

          <!-- Actions -->
          <div class="border-t border-surface-200 dark:border-surface-700 pt-1">
            <button
              v-if="!iosNative"
              @click="router.push('/settings?tab=accounts'); showAccountDropdown = false"
              class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-primary-500"
            >
              <span class="material-symbols-rounded">add</span>
              <span class="text-sm font-medium">{{ $t('appHeader.addAccount') }}</span>
            </button>

            <button
              @click="isElectronApp ? (showLogoutDialog = true, showAccountDropdown = false) : (auth.logout(), showAccountDropdown = false)"
              class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500"
            >
              <span class="material-symbols-rounded">logout</span>
              <span class="text-sm font-medium">{{ $t('appHeader.signOut') }}</span>
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
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('appHeader.signOut') }}</h3>
            <p class="text-sm text-surface-500 mt-1">{{ $t('appHeader.logoutPrompt') }}</p>
          </div>
          <div class="space-y-2">
            <button @click="handleLogoutThisApp" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors text-left">
              <span class="material-symbols-rounded text-surface-500">monitor</span>
              <div>
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ $t('appHeader.logoutThisApp') }}</p>
                <p class="text-xs text-surface-500">{{ $t('appHeader.logoutThisAppDesc') }}</p>
              </div>
            </button>
            <button @click="handleLogoutGlobal" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-red-200 dark:border-red-500/30 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors text-left">
              <span class="material-symbols-rounded text-red-500">devices</span>
              <div>
                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $t('appHeader.logoutAllApps') }}</p>
                <p class="text-xs text-surface-500">{{ $t('appHeader.logoutAllAppsDesc') }}</p>
              </div>
            </button>
          </div>
          <button @click="showLogoutDialog = false" class="w-full mt-3 py-2.5 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors">{{ $t('common.cancel') }}</button>
        </div>
      </div>
    </Teleport>
  </header>
</template>

