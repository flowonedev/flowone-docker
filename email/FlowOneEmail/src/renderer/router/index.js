import { createRouter, createWebHistory, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAddons } from '@/composables/useAddons'
import { defineAsyncComponent, h } from 'vue'
import { isElectron } from '@/services/electronApi'

// Reload the app (simplified for Electron)
function reloadApp() {
  window.location.reload()
}

// Async component wrapper with error handling and retry
function createAsyncView(loader, name) {
  return defineAsyncComponent({
    loader,
    loadingComponent: {
      render() {
        return h('div', { 
          style: 'padding:20px;text-align:center;color:#666;' 
        }, `Loading ${name}...`)
      }
    },
    errorComponent: {
      render() {
        return h('div', { 
          style: 'padding:40px;text-align:center;' 
        }, [
          h('p', { style: 'color:#666;margin-bottom:16px;' }, `Failed to load ${name}`),
          h('p', { style: 'color:#999;font-size:12px;margin-bottom:16px;' }, 'Click below to reload the app.'),
          h('button', { 
            onClick: reloadApp,
            style: 'padding:12px 24px;background:#6366f1;color:white;border:none;border-radius:8px;cursor:pointer;font-size:14px;'
          }, 'Reload')
        ])
      }
    },
    delay: 200,
    timeout: 30000,
    onError(error, retry, fail, attempts) {
      console.warn(`Failed to load ${name}, attempt ${attempts}:`, error)
      if (attempts <= 3) {
        setTimeout(retry, 1000)
      } else {
        fail()
      }
    }
  })
}

// Shared component references to prevent remounting when navigating between related routes
const MailboxViewComponent = createAsyncView(() => import('@/views/MailboxView.vue'), 'Mailbox')

const routes = [
  {
    path: '/login',
    name: 'login',
    component: createAsyncView(() => import('@/views/LoginView.vue'), 'Login'),
    meta: { guest: true }
  },
  {
    path: '/',
    name: 'mailbox',
    component: MailboxViewComponent,
    meta: { requiresAuth: true }
  },
  {
    path: '/folder/:folder*',
    name: 'mailbox-folder',
    component: MailboxViewComponent,
    meta: { requiresAuth: true }
  },
  {
    path: '/email/:folder*/message/:uid',
    name: 'mailbox-email',
    component: MailboxViewComponent,
    meta: { requiresAuth: true }
  },
  {
    path: '/settings',
    name: 'settings',
    component: createAsyncView(() => import('@/views/SettingsView.vue'), 'Settings'),
    meta: { requiresAuth: true }
  },
  {
    path: '/drive',
    name: 'drive',
    component: createAsyncView(() => import('@/views/DriveView.vue'), 'Drive'),
    meta: { requiresAuth: true }
  },
  {
    path: '/drive/folder/:folderId',
    name: 'drive-folder',
    component: createAsyncView(() => import('@/views/DriveView.vue'), 'Drive'),
    meta: { requiresAuth: true }
  },
  {
    path: '/drive/doc/:uuid',
    name: 'drive-document',
    component: createAsyncView(() => import('@/views/DriveView.vue'), 'Drive'),
    meta: { requiresAuth: true }
  },
  {
    path: '/drive/ppt/:uuid',
    name: 'drive-presentation',
    component: createAsyncView(() => import('@/views/DriveView.vue'), 'Drive'),
    meta: { requiresAuth: true }
  },
  {
    path: '/calendar',
    name: 'calendar',
    component: createAsyncView(() => import('@/addons/calendar/views/CalendarView.vue'), 'Calendar'),
    meta: { requiresAuth: true, requiresCalendar: true }
  },
  {
    path: '/my-work',
    name: 'my-work',
    component: createAsyncView(() => import('@/addons/tasks/views/MyWorkView.vue'), 'My Work'),
    meta: { requiresAuth: true }
  },
  {
    path: '/boards',
    name: 'boards',
    component: createAsyncView(() => import('@/addons/kanban-boards/views/BoardsView.vue'), 'Boards'),
    meta: { requiresAuth: true, requiresKanbanBoards: true }
  },
  {
    path: '/boards/:id',
    name: 'board',
    component: createAsyncView(() => import('@/addons/kanban-boards/views/BoardsView.vue'), 'Boards'),
    meta: { requiresAuth: true, requiresKanbanBoards: true }
  },
  {
    path: '/mood',
    name: 'mood',
    component: createAsyncView(() => import('@/addons/moodboards/views/MoodBoardView.vue'), 'Mood Boards'),
    meta: { requiresAuth: true, requiresMoodboards: true }
  },
  {
    path: '/mood/:id',
    name: 'mood-board',
    component: createAsyncView(() => import('@/addons/moodboards/views/MoodBoardView.vue'), 'Mood Boards'),
    meta: { requiresAuth: true, requiresMoodboards: true }
  },
  {
    path: '/clients',
    name: 'clients',
    component: createAsyncView(() => import('@/views/ClientsView.vue'), 'Clients'),
    meta: { requiresAuth: true }
  },
  {
    path: '/clients/overview',
    name: 'clients-overview',
    component: createAsyncView(() => import('@/views/ClientsOverviewView.vue'), 'Clients Overview'),
    meta: { requiresAuth: true }
  },
  {
    path: '/clients/:id',
    name: 'client',
    component: createAsyncView(() => import('@/views/ClientsView.vue'), 'Clients'),
    meta: { requiresAuth: true }
  },
  {
    path: '/chat',
    name: 'chat',
    component: createAsyncView(() => import('@/addons/chat/views/ChatView.vue'), 'Chat'),
    meta: { requiresAuth: true, requiresChat: true }
  },
  {
    path: '/chat/invite/:token',
    name: 'chat-invite',
    component: createAsyncView(() => import('@/addons/chat/views/ChatInviteView.vue'), 'Chat Invite'),
    meta: { requiresAuth: true, requiresChat: true }
  },
  {
    path: '/meeting/:meetingId',
    name: 'meeting-join',
    component: createAsyncView(() => import('@/addons/chat/views/MeetingJoinView.vue'), 'Meeting'),
    meta: { requiresAuth: true, requiresChat: true }
  },
  {
    path: '/team',
    name: 'team',
    component: createAsyncView(() => import('@/addons/team/components/colleagues/ColleagueManager.vue'), 'Team'),
    meta: { requiresAuth: true, requiresTeam: true }
  },
  {
    path: '/mailing-lists',
    name: 'mailing-lists',
    component: createAsyncView(() => import('@/addons/email-marketing/components/mailing-lists/MailingListManager.vue'), 'Emailing Lists'),
    meta: { requiresAuth: true, requiresEmailMarketing: true }
  },
  {
    path: '/campaigns',
    name: 'campaigns',
    component: createAsyncView(() => import('@/addons/email-marketing/views/CampaignsView.vue'), 'Campaigns'),
    meta: { requiresAuth: true, requiresEmailMarketing: true }
  },
  {
    path: '/financials',
    name: 'financials',
    component: createAsyncView(() => import('@/addons/kanban-boards/views/FinancialsView.vue'), 'Financials'),
    meta: { requiresAuth: true, requiresKanbanBoards: true }
  },
  {
    path: '/time',
    name: 'time',
    component: createAsyncView(() => import('@/addons/time-tracker/views/TimeTrackerView.vue'), 'Time Tracker'),
    meta: { requiresAuth: true, requiresTimeTracker: true }
  },
  {
    path: '/mood/share/:token',
    name: 'shared-mood-board',
    component: createAsyncView(() => import('@/addons/moodboards/views/SharedMoodBoardView.vue'), 'Shared Mood Board'),
    meta: { public: true }
  },
  {
    path: '/share/folder/:token',
    name: 'shared-folder',
    component: createAsyncView(() => import('@/views/SharedFolderView.vue'), 'Shared Folder'),
    meta: { public: true }
  },

  // =========================================================================
  // Client Portal (public-facing, magic link auth)
  // =========================================================================
  {
    path: '/portal/auth/request',
    name: 'portal-request-access',
    component: createAsyncView(() => import('@/views/portal/PortalRequestAccessView.vue'), 'Request Access'),
    meta: { public: true, portalRoute: true }
  },
  {
    path: '/portal/auth/:token',
    name: 'portal-auth',
    component: createAsyncView(() => import('@/views/portal/PortalLoginView.vue'), 'Portal Login'),
    meta: { public: true, portalRoute: true }
  },
  {
    path: '/portal',
    name: 'portal',
    component: createAsyncView(() => import('@/views/portal/PortalView.vue'), 'Client Portal'),
    meta: { public: true, portalRoute: true },
    children: [
      {
        path: '',
        name: 'portal-home',
        component: createAsyncView(() => import('@/views/portal/PortalHomeView.vue'), 'Portal Home'),
        meta: { public: true, portalRoute: true }
      },
      {
        path: 'updates',
        name: 'portal-updates',
        component: createAsyncView(() => import('@/views/portal/PortalUpdatesView.vue'), 'Portal Updates'),
        meta: { public: true, portalRoute: true }
      },
      {
        path: 'updates/:id',
        name: 'portal-update',
        component: createAsyncView(() => import('@/views/portal/PortalUpdatesView.vue'), 'Portal Update'),
        meta: { public: true, portalRoute: true }
      },
      {
        path: 'documents',
        name: 'portal-documents',
        component: createAsyncView(() => import('@/views/portal/PortalDocumentsView.vue'), 'Portal Documents'),
        meta: { public: true, portalRoute: true }
      },
      {
        path: 'documents/:docId',
        name: 'portal-document',
        component: createAsyncView(() => import('@/views/portal/PortalDocumentViewer.vue'), 'Portal Document'),
        meta: { public: true, portalRoute: true }
      },
      {
        path: 'calls',
        name: 'portal-calls',
        component: createAsyncView(() => import('@/views/portal/PortalCallsView.vue'), 'Portal Calls'),
        meta: { public: true, portalRoute: true }
      },
      {
        path: 'calls/:callId',
        name: 'portal-call-room',
        component: createAsyncView(() => import('@/views/portal/PortalCallRoom.vue'), 'Portal Call Room'),
        meta: { public: true, portalRoute: true }
      },
    ]
  },

  // =========================================================================
  // CRM Pro Routes (gated by addon, requiresAuth)
  // =========================================================================
  {
    path: '/crm/pipeline',
    name: 'crm-pipeline',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmPipelineView.vue'), 'CRM Pipeline'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/deals/:id',
    name: 'crm-deal',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmPipelineView.vue'), 'CRM Deal'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/invoices',
    name: 'crm-invoices',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmInvoicesView.vue'), 'CRM Invoices'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/invoices/:id',
    name: 'crm-invoice',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmInvoicesView.vue'), 'CRM Invoice'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/executive',
    name: 'crm-executive',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmExecutiveView.vue'), 'Executive Summary'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/dashboard',
    name: 'crm-dashboard',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmDashboardView.vue'), 'CRM Dashboard'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/automation',
    name: 'crm-automation',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmAutomationView.vue'), 'CRM Automation'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/sequences',
    name: 'crm-sequences',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmSequencesView.vue'), 'CRM Sequences'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },
  {
    path: '/crm/sharing',
    name: 'crm-sharing',
    component: createAsyncView(() => import('@/addons/crm-pro/views/CrmSharingView.vue'), 'CRM Sharing'),
    meta: { requiresAuth: true, requiresCrmPro: true }
  },

  // =========================================================================
  // Automation Hub Routes (gated by addon, requiresAuth)
  // =========================================================================
  {
    path: '/automation-hub',
    name: 'automation-hub',
    component: createAsyncView(() => import('@/addons/automation-hub/views/AutomationHubView.vue'), 'Automation Hub'),
    meta: { requiresAuth: true, requiresAutomationHub: true }
  },
  {
    path: '/automation-hub/:id',
    name: 'automation-hub-editor',
    component: createAsyncView(() => import('@/addons/automation-hub/views/WorkflowEditorView.vue'), 'Workflow Editor'),
    meta: { requiresAuth: true, requiresAutomationHub: true }
  },

  {
    path: '/:pathMatch(.*)*',
    redirect: '/'
  }
]

// Use hash history in Electron (no web server), web history in browser
const router = createRouter({
  history: isElectron() ? createWebHashHistory() : createWebHistory(),
  routes
})

// Helper to detect iOS (Safari AND Chrome) - not relevant in Electron
function isIOS() {
  if (isElectron()) return false
  const ua = navigator.userAgent
  return /iPad|iPhone|iPod/.test(ua) && !window.MSStream
}

router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore()
  
  // Portal routes have their own auth (magic links) -- always allow
  if (to.meta.portalRoute) {
    next()
    return
  }

  // Public routes (like shared folder) - allow without auth
  if (to.meta.public) {
    next()
    return
  }
  
  // Handle OAuth callback in browser mode
  if (!isElectron() && to.name === 'login' && (to.query.oauth_success || to.query.oauth_error)) {
    next()
    return
  }
  
  // Electron mode: Check auth via IPC
  if (isElectron()) {
    // Check if logged in via Electron store
    const isLoggedIn = await window.api.auth.isLoggedIn()
    
    if (to.meta.requiresAuth && !isLoggedIn) {
      next({ name: 'login' })
      return
    }
    
    if (to.meta.guest && isLoggedIn) {
      next({ name: 'mailbox' })
      return
    }
    
    // Initialize auth state if we have a token
    if (isLoggedIn && !auth.authChecked) {
      await auth.initFromElectron()
      // Try to validate with server (offline support - continue if fails)
      try {
        const isValid = await auth.checkAuth()
        if (!isValid && to.meta.requiresAuth) {
          // Token was confirmed invalid by the server – redirect to login.
          // Note: checkAuth already called clearAuth() internally.
          next({ name: 'login' })
          return
        }
      } catch (e) {
        console.warn('Auth check failed (may be offline):', e)
        // Network error – allow offline access with cached data
      }
    }

    // Gate addon routes -- redirect if addon is disabled
    if (to.meta.requiresCrmPro || to.meta.requiresMoodboards || to.meta.requiresKanbanBoards || to.meta.requiresChat || to.meta.requiresEmailMarketing || to.meta.requiresTeam || to.meta.requiresCalendar || to.meta.requiresTimeTracker || to.meta.requiresAutomationHub) {
      const { crmProEnabled, moodboardsEnabled, kanbanBoardsEnabled, chatEnabled, emailMarketingEnabled, teamEnabled, calendarEnabled, timeTrackerEnabled, automationHubEnabled, fetchAddons, loaded } = useAddons()
      if (!loaded.value) {
        try { await fetchAddons() } catch (e) { /* fail-closed below */ }
      }
      if (to.meta.requiresCrmPro && !crmProEnabled.value) {
        next({ name: 'clients' })
        return
      }
      if (to.meta.requiresMoodboards && !moodboardsEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
      if (to.meta.requiresKanbanBoards && !kanbanBoardsEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
      if (to.meta.requiresChat && !chatEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
      if (to.meta.requiresEmailMarketing && !emailMarketingEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
      if (to.meta.requiresTeam && !teamEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
      if (to.meta.requiresCalendar && !calendarEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
      if (to.meta.requiresTimeTracker && !timeTrackerEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
      if (to.meta.requiresAutomationHub && !automationHubEnabled.value) {
        next({ name: 'mailbox' })
        return
      }
    }
    
    next()
    return
  }
  
  // Browser mode: iOS Safari handling
  const hasStoredToken = localStorage.getItem('webmail_token')
  const hasStateToken = auth.hasToken
  
  if (isIOS()) {
    if (hasStoredToken || hasStateToken) {
      if (to.meta.guest) {
        next({ name: 'mailbox' })
        return
      }
      
      if (hasStoredToken && !hasStateToken) {
        auth.token = hasStoredToken
      }
      
      if (!auth.authChecked) {
        try {
          const isValid = await auth.checkAuth()
          if (!isValid && to.meta.requiresAuth) {
            next({ name: 'login' })
            return
          }
        } catch (e) {
          console.warn('Auth check error on iOS:', e)
        }
      }
      
      // Gate addon routes on iOS too
      if (to.meta.requiresCrmPro || to.meta.requiresMoodboards || to.meta.requiresKanbanBoards || to.meta.requiresChat || to.meta.requiresEmailMarketing || to.meta.requiresTeam || to.meta.requiresCalendar || to.meta.requiresTimeTracker) {
        const { crmProEnabled, moodboardsEnabled, kanbanBoardsEnabled, chatEnabled, emailMarketingEnabled, teamEnabled, calendarEnabled, timeTrackerEnabled, fetchAddons, loaded } = useAddons()
        if (!loaded.value) {
          try { await fetchAddons() } catch (e) { /* fail-closed below */ }
        }
        if (to.meta.requiresCrmPro && !crmProEnabled.value) {
          next({ name: 'clients' })
          return
        }
        if (to.meta.requiresMoodboards && !moodboardsEnabled.value) {
          next({ name: 'mailbox' })
          return
        }
        if (to.meta.requiresKanbanBoards && !kanbanBoardsEnabled.value) {
          next({ name: 'mailbox' })
          return
        }
        if (to.meta.requiresChat && !chatEnabled.value) {
          next({ name: 'mailbox' })
          return
        }
        if (to.meta.requiresEmailMarketing && !emailMarketingEnabled.value) {
          next({ name: 'mailbox' })
          return
        }
        if (to.meta.requiresTeam && !teamEnabled.value) {
          next({ name: 'mailbox' })
          return
        }
        if (to.meta.requiresCalendar && !calendarEnabled.value) {
          next({ name: 'mailbox' })
          return
        }
        if (to.meta.requiresTimeTracker && !timeTrackerEnabled.value) {
          next({ name: 'mailbox' })
          return
        }
      }
      
      next()
      return
    }
    
    if (to.meta.requiresAuth) {
      next({ name: 'login' })
      return
    }
    next()
    return
  }
  
  // Browser mode: Standard flow
  if (!auth.authChecked && auth.hasToken) {
    try {
      const authPromise = auth.checkAuth()
      const timeoutPromise = new Promise((_, reject) => 
        setTimeout(() => reject(new Error('Auth check timeout')), 10000)
      )
      await Promise.race([authPromise, timeoutPromise])
    } catch (e) {
      console.warn('Auth check failed or timed out:', e)
    }
  }
  
  const isLoggedIn = auth.isAuthenticated || auth.loginComplete
  
  if (to.meta.requiresAuth && !isLoggedIn) {
    next({ name: 'login' })
  } else if (to.meta.guest && isLoggedIn) {
    next({ name: 'mailbox' })
  } else if (to.meta.requiresCrmPro || to.meta.requiresMoodboards || to.meta.requiresKanbanBoards || to.meta.requiresChat || to.meta.requiresEmailMarketing || to.meta.requiresTeam || to.meta.requiresCalendar || to.meta.requiresTimeTracker) {
    // Gate addon routes — redirect if addon is disabled
    const { crmProEnabled, moodboardsEnabled, kanbanBoardsEnabled, chatEnabled, emailMarketingEnabled, teamEnabled, calendarEnabled, timeTrackerEnabled, fetchAddons, loaded } = useAddons()
    if (!loaded.value) {
      try { await fetchAddons() } catch (e) { /* fail-closed below */ }
    }
    if (to.meta.requiresCrmPro && !crmProEnabled.value) {
      next({ name: 'clients' })
    } else if (to.meta.requiresMoodboards && !moodboardsEnabled.value) {
      next({ name: 'mailbox' })
    } else if (to.meta.requiresKanbanBoards && !kanbanBoardsEnabled.value) {
      next({ name: 'mailbox' })
    } else if (to.meta.requiresChat && !chatEnabled.value) {
      next({ name: 'mailbox' })
    } else if (to.meta.requiresEmailMarketing && !emailMarketingEnabled.value) {
      next({ name: 'mailbox' })
    } else if (to.meta.requiresTeam && !teamEnabled.value) {
      next({ name: 'mailbox' })
    } else if (to.meta.requiresCalendar && !calendarEnabled.value) {
      next({ name: 'mailbox' })
    } else if (to.meta.requiresTimeTracker && !timeTrackerEnabled.value) {
      next({ name: 'mailbox' })
    } else {
      next()
    }
  } else {
    next()
  }
})

// Listen for logout events (Electron)
if (isElectron()) {
  window.addEventListener('logout', () => {
    router.push({ name: 'login' })
  })
  
  // Listen for auth-failed events from main process (IPC)
  window.api?.on('auth-failed', () => {
    router.push({ name: 'login' })
  })
  
  // Listen for auth-failed events from API interceptor (DOM CustomEvent)
  window.addEventListener('auth-failed', () => {
    console.warn('[Router] auth-failed DOM event — redirecting to login')
    router.push({ name: 'login' })
  })
  
  // Listen for navigate events from main process
  window.api?.on('navigate', (route) => {
    router.push(route)
  })
}

export default router
