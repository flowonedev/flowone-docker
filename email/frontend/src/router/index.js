import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAddons } from '@/composables/useAddons'
import { defineAsyncComponent, h } from 'vue'

// Clear service worker cache and reload
async function clearCacheAndReload() {
  try {
    // Unregister service workers
    if ('serviceWorker' in navigator) {
      const registrations = await navigator.serviceWorker.getRegistrations()
      for (const registration of registrations) {
        await registration.unregister()
      }
    }
    // Clear caches
    if ('caches' in window) {
      const cacheNames = await caches.keys()
      for (const cacheName of cacheNames) {
        await caches.delete(cacheName)
      }
    }
  } catch (e) {
    console.error('Failed to clear cache:', e)
  }
  // Hard reload
  window.location.reload(true)
}

// Async component wrapper with error handling and retry for iOS compatibility
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
          h('p', { style: 'color:#999;font-size:12px;margin-bottom:16px;' }, 'This usually happens after an update. Click below to refresh.'),
          h('button', { 
            onClick: clearCacheAndReload,
            style: 'padding:12px 24px;background:#6366f1;color:white;border:none;border-radius:8px;cursor:pointer;font-size:14px;'
          }, 'Clear Cache & Reload')
        ])
      }
    },
    delay: 200,
    timeout: 30000,
    onError(error, retry, fail, attempts) {
      console.warn(`Failed to load ${name}, attempt ${attempts}:`, error)
      if (attempts <= 3) {
        // Wait a bit before retry
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
    path: '/',
    name: 'root',
    redirect: () => {
      const perspective = localStorage.getItem('webmail_perspective') || 'operations'
      const routeMap = {
        executive: '/crm/executive',
        delivery: '/my-work',
        operations: '/inbox',
      }
      return routeMap[perspective] || '/inbox'
    }
  },
  {
    path: '/privacy-policy',
    name: 'privacy-policy',
    component: createAsyncView(() => import('@/views/PrivacyPolicyView.vue'), 'Privacy Policy'),
    meta: { public: true }
  },
  {
    path: '/privacy',
    name: 'flowone-privacy',
    component: createAsyncView(() => import('@/views/FlowOnePrivacyView.vue'), 'Privacy Policy'),
    meta: { public: true }
  },
  {
    path: '/terms',
    name: 'flowone-terms',
    component: createAsyncView(() => import('@/views/FlowOneTermsView.vue'), 'Terms of Service'),
    meta: { public: true }
  },
  {
    path: '/login',
    name: 'login',
    component: createAsyncView(() => import('@/views/LoginView.vue'), 'Login'),
    meta: { guest: true }
  },
  {
    path: '/inbox',
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
    // "Scan to sign in" approval page. A desktop app's QR / "Approve in browser"
    // button lands here with ?req=<id>; an already-signed-in session approves it.
    // requiresAuth: the guard first tries a cross-tab session handoff (borrow the
    // login from a signed-in sibling tab), and only if that fails bounces to the
    // password login and back (preserving ?req= via loginTarget).
    path: '/link-device',
    name: 'link-device',
    component: createAsyncView(() => import('@/views/LinkDeviceApproval.vue'), 'Approve device'),
    meta: { requiresAuth: true }
  },
  {
    // Phase 8: Admin-only storage dashboard. Admin gate is enforced
    // server-side by /admin/storage/dashboard. Non-admins reaching
    // this route will see the 403 banner rendered by the dashboard.
    path: '/admin/storage',
    name: 'admin-storage',
    component: createAsyncView(() => import('@/views/StorageAdminView.vue'), 'Storage Dashboard'),
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
    path: '/office/:fileId',
    name: 'office-editor',
    component: createAsyncView(() => import('@/views/OfficeEditorView.vue'), 'Office'),
    meta: { requiresAuth: true }
  },
  {
    path: '/calendar',
    name: 'calendar',
    component: createAsyncView(() => import('@/addons/calendar/views/CalendarView.vue'), 'Calendar'),
    meta: { requiresAuth: true, requiresCalendar: true }
  },
  {
    path: '/contacts',
    name: 'contacts',
    component: createAsyncView(() => import('@/addons/contacts/views/ContactsView.vue'), 'Contacts'),
    meta: { requiresAuth: true }
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
    path: '/boards/folder/:folderId',
    name: 'board-folder',
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
    path: '/financials',
    name: 'financials',
    component: createAsyncView(() => import('@/addons/kanban-boards/views/FinancialsView.vue'), 'Financials'),
    meta: { requiresAuth: true, requiresKanbanBoards: true }
  },
  {
    path: '/workload',
    name: 'workload',
    component: createAsyncView(() => import('@/addons/project-hub/views/WorkloadPlannerView.vue'), 'Workload Planner'),
    meta: { requiresAuth: true, requiresProjectHub: true }
  },
  {
    path: '/workload/card/:cardId',
    name: 'workload-card',
    component: createAsyncView(() => import('@/addons/project-hub/views/TaskDetailView.vue'), 'Task Detail'),
    meta: { requiresAuth: true, requiresProjectHub: true }
  },
  {
    path: '/project-hub/director',
    name: 'ph-director',
    component: createAsyncView(() => import('@/addons/project-hub/views/DirectorDashboardView.vue'), 'Director Dashboard'),
    meta: { requiresAuth: true, requiresProjectHub: true }
  },
  {
    path: '/project-hub/settings',
    name: 'ph-settings',
    component: createAsyncView(() => import('@/addons/project-hub/views/ProjectHubSettingsView.vue'), 'Project Hub Settings'),
    meta: { requiresAuth: true, requiresProjectHub: true }
  },
  {
    path: '/time',
    name: 'time',
    component: createAsyncView(() => import('@/addons/time-tracker/views/TimeTrackerView.vue'), 'Time Tracker'),
    meta: { requiresAuth: true, requiresTimeTracker: true }
  },
  {
    path: '/time/breakdown',
    redirect: to => ({ path: '/workload', query: { mode: 'task-time', ...to.query } })
  },
  {
    path: '/team',
    name: 'team',
    component: createAsyncView(() => import('@/addons/team/components/colleagues/ColleagueManager.vue'), 'Team'),
    meta: { requiresAuth: true, requiresTeam: true }
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
    path: '/meet/:token',
    name: 'meeting-join',
    component: createAsyncView(() => import('@/addons/chat/views/MeetingJoinView.vue'), 'Meeting'),
    meta: { requiresAuth: true }
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
    component: createAsyncView(() => import('@/addons/email-marketing/views/CampaignsView.vue'), 'Email Campaigns'),
    meta: { requiresAuth: true, requiresEmailMarketing: true }
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
  {
    path: '/share/card/:token',
    name: 'ph-card-share',
    component: createAsyncView(() => import('@/addons/project-hub/views/PublicCardShareView.vue'), 'Shared card deliverables'),
    meta: { public: true }
  },
  {
    path: '/share/c/:token',
    name: 'ph-card-share-short',
    component: createAsyncView(() => import('@/addons/project-hub/views/PublicCardShareView.vue'), 'Shared card deliverables'),
    meta: { public: true }
  },

  // =========================================================================
  // Guest Call (one-click video call link, no auth)
  // =========================================================================
  {
    path: '/guest/call/:token',
    name: 'guest-call',
    component: createAsyncView(() => import('@/views/GuestCallView.vue'), 'Guest Call'),
    meta: { public: true }
  },

  // =========================================================================
  // Guest Office document (shared edit/view link, no auth)
  // =========================================================================
  {
    path: '/guest/office/:token',
    name: 'guest-office',
    component: createAsyncView(() => import('@/views/GuestOfficeView.vue'), 'Shared Document'),
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

  {
    path: '/:pathMatch(.*)*',
    redirect: () => {
      const perspective = localStorage.getItem('webmail_perspective') || 'operations'
      const routeMap = {
        executive: '/crm/executive',
        delivery: '/my-work',
        operations: '/inbox',
      }
      return routeMap[perspective] || '/inbox'
    }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// Helper to detect iOS (Safari AND Chrome)
function isIOS() {
  const ua = navigator.userAgent
  return /iPad|iPhone|iPod/.test(ua) && !window.MSStream
}

router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore()

  // When a protected route bounces to login, preserve where the user was
  // headed so we can return them there (used by the device-approval deep link
  // so a QR scanned while signed out lands back on /link-device after login).
  const loginTarget = to.name === 'link-device'
    ? { name: 'login', query: { redirect: to.fullPath } }
    : { name: 'login' }
  
  // Portal routes have their own auth (magic links) — always allow
  if (to.meta.portalRoute) {
    next()
    return
  }

  // Public routes (like shared folder, privacy policy) - allow without auth
  if (to.meta.public) {
    next()
    return
  }
  
  // CRITICAL: Allow OAuth callback to login page even if user appears logged in
  // The oauth_success param contains new tokens that need to be processed by LoginView
  // Without this check, the router would redirect to mailbox BEFORE LoginView can process the tokens
  if (to.name === 'login' && (to.query.oauth_success || to.query.oauth_error)) {
    next()
    return
  }
  
  // CRITICAL FIX FOR iOS SAFARI:
  // On iOS Safari, localStorage can be async and auth checks can fail due to timing
  // If we have ANY token in localStorage or state, allow navigation to protected routes
  // The actual API calls will handle auth failures gracefully
  const { getToken, setToken } = await import('@/services/tokenStorage')
  let hasStoredToken = getToken('webmail_token')
  let hasStateToken = auth.hasToken

  // Device-approval deep link: the approval tab is almost always a freshly opened
  // tab (Drive's "Approve in browser" launches the OS default browser; a phone QR
  // scan opens a new tab), whose per-tab sessionStorage has no token even though
  // another tab in this same browser IS signed in. Before bouncing to a password
  // login, borrow the live session from a signed-in sibling tab over a same-origin
  // BroadcastChannel so approval is truly painless. Falls through to the login
  // redirect-back (loginTarget) when no signed-in tab answers.
  if (to.name === 'link-device' && !hasStoredToken && !hasStateToken) {
    try {
      const { requestAuthFromOtherTabs } = await import('@/services/crossTabAuth')
      const granted = await requestAuthFromOtherTabs(1500)
      if (granted?.access_token) {
        setToken('webmail_token', granted.access_token)
        if (granted.session_token) setToken('webmail_session_token', granted.session_token)
        if (granted.refresh_token) setToken('webmail_refresh_token', granted.refresh_token)
        auth.token = granted.access_token
        auth.checkAuthLocal()
        hasStoredToken = getToken('webmail_token')
        hasStateToken = auth.hasToken
      }
    } catch (_e) {
      /* handoff unsupported — fall back to the login redirect-back below */
    }
  }

  if (isIOS()) {
    if (hasStoredToken || hasStateToken) {
      // We have some form of token - but verify it's still valid
      if (to.meta.guest) {
        // Going to login but have token - go to mailbox
        next({ name: 'mailbox' })
        return
      }
      
      // Going to protected route - sync token and do auth check
      if (hasStoredToken && !hasStateToken) {
        auth.token = hasStoredToken
      }
      
      // Local JWT check: instant, no network call. Server validates via bootstrap.
      if (!auth.authChecked) {
        const isValid = auth.checkAuthLocal()
        if (!isValid && to.meta.requiresAuth) {
          next(loginTarget)
          return
        }
      }
      
      // Gate addon routes on iOS too
      if (to.meta.requiresCrmPro || to.meta.requiresMoodboards || to.meta.requiresKanbanBoards || to.meta.requiresChat || to.meta.requiresEmailMarketing || to.meta.requiresTeam || to.meta.requiresCalendar || to.meta.requiresTimeTracker || to.meta.requiresAutomationHub || to.meta.requiresProjectHub) {
        const { crmProEnabled, moodboardsEnabled, kanbanBoardsEnabled, chatEnabled, emailMarketingEnabled, teamEnabled, calendarEnabled, timeTrackerEnabled, automationHubEnabled, projectHubEnabled, fetchAddons, loaded } = useAddons()
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
        if (to.meta.requiresProjectHub && !projectHubEnabled.value) {
          next({ name: 'boards' })
          return
        }
      }
      
      next()
      return
    }
    // No token at all on iOS - check if going to protected route
    if (to.meta.requiresAuth) {
      next(loginTarget)
      return
    }
    next()
    return
  }
  
  // Non-iOS browsers: local JWT check (instant, no network call).
  // Server-side validation happens via the bootstrap call in App.vue.
  if (!auth.authChecked && auth.hasToken) {
    auth.checkAuthLocal()
  }
  
  const isLoggedIn = auth.isAuthenticated || auth.loginComplete
  
  // Now check if authenticated
  if (to.meta.requiresAuth && !isLoggedIn) {
    next(loginTarget)
  } else if (to.meta.guest && isLoggedIn) {
    next({ name: 'mailbox' })
  } else if (to.meta.requiresCrmPro || to.meta.requiresMoodboards || to.meta.requiresKanbanBoards || to.meta.requiresChat || to.meta.requiresEmailMarketing || to.meta.requiresTeam || to.meta.requiresCalendar || to.meta.requiresTimeTracker || to.meta.requiresAutomationHub || to.meta.requiresProjectHub) {
    // Gate addon routes — redirect if addon is disabled
    const { crmProEnabled, moodboardsEnabled, kanbanBoardsEnabled, chatEnabled, emailMarketingEnabled, teamEnabled, calendarEnabled, timeTrackerEnabled, automationHubEnabled, projectHubEnabled, fetchAddons, loaded } = useAddons()
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
    } else if (to.meta.requiresAutomationHub && !automationHubEnabled.value) {
      next({ name: 'mailbox' })
    } else if (to.meta.requiresProjectHub && !projectHubEnabled.value) {
      next({ name: 'boards' })
    } else {
      next()
    }
  } else if (to.name === 'my-work') {
    const { projectHubEnabled, fetchAddons, loaded } = useAddons()
    if (!loaded.value) {
      try { await fetchAddons() } catch (e) { /* ignore */ }
    }
    if (projectHubEnabled.value) {
      next({ name: 'workload', query: { mode: 'my-work' } })
    } else {
      next()
    }
  } else {
    next()
  }
})

export default router

