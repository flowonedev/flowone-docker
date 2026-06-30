import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { getToken } from '@/services/tokenStorage'

export const PERSPECTIVES = {
  EXECUTIVE: 'executive',
  DELIVERY: 'delivery',
  OPERATIONS: 'operations',
}

const PERSPECTIVE_CONFIG = {
  [PERSPECTIVES.EXECUTIVE]: {
    icon: 'query_stats',
    defaultRoute: '/crm/executive',
    fallbackRoute: '/inbox',
    primaryNavIds: ['email', 'calendar', 'crm-executive', 'crm-dashboard', 'crm-pipeline', 'crm-invoices', 'financials', 'clients'],
    mobileNavIds: ['email', 'calendar', 'crm-executive'],
    promotedGroups: ['appHeader.revenueIntelligence', 'appHeader.clientIntelligence'],
  },
  [PERSPECTIVES.DELIVERY]: {
    icon: 'engineering',
    defaultRoute: '/my-work',
    fallbackRoute: '/inbox',
    primaryNavIds: ['email', 'calendar', 'my-work', 'boards', 'workload', 'time', 'clients', 'drive'],
    mobileNavIds: ['email', 'calendar', 'boards'],
    promotedGroups: ['appHeader.deliveryIntelligence', 'appHeader.clientIntelligence'],
  },
  [PERSPECTIVES.OPERATIONS]: {
    icon: 'hub',
    defaultRoute: '/inbox',
    fallbackRoute: '/inbox',
    primaryNavIds: ['email', 'calendar', 'drive'],
    mobileNavIds: ['email', 'calendar', 'drive'],
    promotedGroups: ['appHeader.operationsIntelligence', 'appHeader.infrastructureIntelligence'],
  },
}

const NAV_ITEM_REGISTRY = {
  'email': { id: 'email', path: '/inbox', icon: 'mail', labelKey: 'appHeader.mail', routeName: 'mailbox' },
  'my-work': { id: 'my-work', path: '/my-work', icon: 'assignment_ind', labelKey: 'appHeader.myWork', addon: 'tasks' },
  'calendar': { id: 'calendar', path: '/calendar', icon: 'calendar_month', labelKey: 'appHeader.calendar', addon: 'calendar' },
  'contacts': { id: 'contacts', path: '/contacts', icon: 'contacts', labelKey: 'appHeader.contacts', routeName: 'contacts' },
  'drive': { id: 'drive', path: '/drive', icon: 'cloud', labelKey: 'appHeader.drive' },
  'boards': { id: 'boards', path: '/boards', icon: 'dashboard', labelKey: 'appHeader.projects', addon: 'kanbanBoards' },
  'workload': { id: 'workload', path: '/workload', icon: 'groups', labelKey: 'appHeader.workload', addon: 'projectHub' },
  'chat': { id: 'chat', path: '/chat', icon: 'chat', labelKey: 'appHeader.chat', addon: 'chat' },
  'team': { id: 'team', path: '/team', icon: 'diversity_3', labelKey: 'appHeader.team', addon: 'team' },
  'time': { id: 'time', path: '/time', icon: 'timer', labelKey: 'appHeader.timeTracker', addon: 'timeTracker' },
  'clients': { id: 'clients', path: '/clients/overview', icon: 'groups', labelKey: 'appHeader.clients' },
  'financials': { id: 'financials', path: '/financials', icon: 'account_balance', labelKey: 'appHeader.financials', addon: 'kanbanBoards' },
  'crm-executive': { id: 'crm-executive', path: '/crm/executive', icon: 'query_stats', labelKey: 'appHeader.crmExecutive', addon: 'crmPro' },
  'crm-dashboard': { id: 'crm-dashboard', path: '/crm/dashboard', icon: 'monitoring', labelKey: 'appHeader.crmDashboard', addon: 'crmPro' },
  'crm-pipeline': { id: 'crm-pipeline', path: '/crm/pipeline', icon: 'conversion_path', labelKey: 'appHeader.pipeline', addon: 'crmPro' },
  'crm-invoices': { id: 'crm-invoices', path: '/crm/invoices', icon: 'receipt_long', labelKey: 'appHeader.invoices', addon: 'crmPro' },
  'crm-sequences': { id: 'crm-sequences', path: '/crm/sequences', icon: 'route', labelKey: 'appHeader.sequences', addon: 'crmPro' },
  'automation-hub': { id: 'automation-hub', path: '/automation-hub', icon: 'settings_suggest', labelKey: 'appHeader.automationHub', addon: 'automationHub' },
  'mailing-lists': { id: 'mailing-lists', path: '/mailing-lists', icon: 'contact_mail', labelKey: 'appHeader.emailingLists', addon: 'emailMarketing' },
  'campaigns': { id: 'campaigns', path: '/campaigns', icon: 'campaign', labelKey: 'appHeader.emailCampaigns', addon: 'emailMarketing' },
  'mood': { id: 'mood', path: '/mood', icon: 'dashboard_customize', labelKey: 'appHeader.moodBoards', addon: 'moodboards' },
}

const ADDON_KEY_MAP = {
  tasks: 'tasksEnabled',
  calendar: 'calendarEnabled',
  kanbanBoards: 'kanbanBoardsEnabled',
  chat: 'chatEnabled',
  team: 'teamEnabled',
  timeTracker: 'timeTrackerEnabled',
  crmPro: 'crmProEnabled',
  automationHub: 'automationHubEnabled',
  emailMarketing: 'emailMarketingEnabled',
  moodboards: 'moodboardsEnabled',
  projectHub: 'projectHubEnabled',
}

export const usePerspectiveStore = defineStore('perspective', () => {
  const active = ref(localStorage.getItem('webmail_perspective') || PERSPECTIVES.OPERATIONS)
  const loading = ref(false)

  const config = computed(() => PERSPECTIVE_CONFIG[active.value] || PERSPECTIVE_CONFIG[PERSPECTIVES.OPERATIONS])

  function setPerspective(value, saveToServer = true) {
    if (!Object.values(PERSPECTIVES).includes(value)) return
    active.value = value
    localStorage.setItem('webmail_perspective', value)

    if (saveToServer) {
      api.put('/settings', { perspective: value }).catch(e => {
        console.error('Failed to save perspective setting:', e)
      })
    }
  }

  function filterByAddons(itemIds, addonFlags) {
    return itemIds
      .map(id => NAV_ITEM_REGISTRY[id])
      .filter(item => {
        if (!item) return false
        if (!item.addon) return true
        // Hide My Work when Project Hub is active (absorbed into hub sidebar)
        if (item.id === 'my-work' && addonFlags.projectHubEnabled) return false
        const flagKey = ADDON_KEY_MAP[item.addon]
        return flagKey ? addonFlags[flagKey] : true
      })
  }

  function getPrimaryNavItems(addonFlags) {
    return filterByAddons(config.value.primaryNavIds, addonFlags)
  }

  function getMobileNavItems(addonFlags) {
    return filterByAddons(config.value.mobileNavIds, addonFlags)
  }

  function getDefaultRoute(addonFlags) {
    const route = config.value.defaultRoute
    const primaryItems = getPrimaryNavItems(addonFlags)
    const targetItem = Object.values(NAV_ITEM_REGISTRY).find(item => item.path === route)

    if (targetItem && targetItem.addon) {
      const flagKey = ADDON_KEY_MAP[targetItem.addon]
      if (flagKey && !addonFlags[flagKey]) {
        return config.value.fallbackRoute
      }
    }

    return route
  }

  function getGroupOrder() {
    const promoted = config.value.promotedGroups
    const allGroups = [
      'appHeader.revenueIntelligence',
      'appHeader.operationsIntelligence',
      'appHeader.deliveryIntelligence',
      'appHeader.clientInfra',
    ]
    const normalised = promoted.map(g => {
      if (g === 'appHeader.clientIntelligence' || g === 'appHeader.infrastructureIntelligence') return 'appHeader.clientInfra'
      return g
    })
    const unique = [...new Set(normalised)]
    const rest = allGroups.filter(g => !unique.includes(g))
    return [...unique, ...rest]
  }

  async function fetchSettings() {
    const token = getToken('webmail_token')
    if (!token) return

    // Use already-loaded settings store data when available
    try {
      const { useSettingsStore } = await import('@/stores/settings')
      const settingsStore = useSettingsStore()
      if (settingsStore.loaded) {
        applySettings(settingsStore.settings)
        return
      }
    } catch (_) { /* fallback to API */ }

    loading.value = true
    try {
      const response = await api.get('/settings')
      if (response.data.success) {
        const settings = response.data.data.settings
        if (settings.perspective && Object.values(PERSPECTIVES).includes(settings.perspective)) {
          active.value = settings.perspective
          localStorage.setItem('webmail_perspective', settings.perspective)
        }
      }
    } catch (e) {
      console.debug('Failed to fetch perspective settings:', e.message)
    } finally {
      loading.value = false
    }
  }

  function applySettings(settings) {
    if (settings.perspective && Object.values(PERSPECTIVES).includes(settings.perspective)) {
      active.value = settings.perspective
      localStorage.setItem('webmail_perspective', settings.perspective)
    }
  }

  async function initPerspective() {
    await fetchSettings()
  }

  return {
    active,
    loading,
    config,
    setPerspective,
    getPrimaryNavItems,
    getMobileNavItems,
    getDefaultRoute,
    getGroupOrder,
    filterByAddons,
    fetchSettings,
    applySettings,
    initPerspective,
    PERSPECTIVES,
    NAV_ITEM_REGISTRY,
    ADDON_KEY_MAP,
  }
})
