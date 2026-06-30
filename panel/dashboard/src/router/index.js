import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";

const routes = [
  {
    path: "/login",
    name: "login",
    component: () => import("@/views/LoginView.vue"),
    meta: { guest: true },
  },
  {
    path: "/",
    component: () => import("@/layouts/DashboardLayout.vue"),
    meta: { requiresAuth: true },
    children: [
      {
        path: "",
        name: "dashboard",
        component: () => import("@/views/DashboardView.vue"),
      },
      {
        path: "overview",
        name: "overview",
        component: () => import("@/views/OverviewView.vue"),
      },
      // V2 is the only site-management surface. The old synchronous
      // `/sites` and `/sites/:domain` routes were deleted in Phase 5 of
      // the V2 consolidation; legacy SitesView.vue and SiteDetailView.vue
      // were retired alongside them. `/sites-v2` is kept as the
      // canonical path so deep links and bookmarks survive the rename;
      // `/sites` redirects to it.
      {
        path: "sites",
        redirect: { name: "sites-v2" },
      },
      {
        path: "sites-v2",
        name: "sites-v2",
        component: () => import("@/views/SitesV2View.vue"),
        meta: { requiresAdmin: true },
      },
      {
        path: "sites-v2/:domain/manage",
        name: "site-manage-v2",
        component: () => import("@/views/SiteManageV2View.vue"),
        meta: { requiresAdmin: true },
      },
      // Old `/sites/:domain` deep links redirect to the V2 manage view.
      // Query string (e.g. `?tab=wordpress`) is preserved so old links
      // like `/sites/example.com?tab=wordpress` from AppsView and
      // OverviewView land on the right tab.
      {
        path: "sites/:domain",
        redirect: (to) => ({
          name: "site-manage-v2",
          params: { domain: to.params.domain },
          query: to.query,
        }),
      },
      {
        path: "apps",
        redirect: { name: "overview", query: { tab: "wordpress" } },
      },
      {
        path: "files",
        name: "files",
        component: () => import("@/views/FileManagerView.vue"),
      },
      {
        path: "security",
        name: "security",
        component: () => import("@/views/SecurityView.vue"),
      },
      // Redirects for old routes
      {
        path: "services",
        redirect: { name: "overview", query: { tab: "services" } },
      },
      {
        path: "databases",
        redirect: { name: "overview", query: { tab: "databases" } },
      },
      {
        path: "ssl",
        redirect: { name: "overview", query: { tab: "ssl" } },
      },
      {
        path: "mail",
        redirect: { name: "overview", query: { tab: "mail" } },
      },
      {
        path: "dns",
        redirect: { name: "overview", query: { tab: "dns" } },
      },
      {
        path: "backups",
        name: "backups",
        component: () => import("@/views/BackupsView.vue"),
      },
      {
        path: "logs",
        name: "logs",
        component: () => import("@/views/LogsView.vue"),
      },
      {
        path: "settings",
        name: "settings",
        component: () => import("@/views/SettingsView.vue"),
      },
      {
        path: "system",
        name: "system",
        component: () => import("@/views/SystemView.vue"),
        meta: { requiresAdmin: true },
      },
      {
        path: "mail-security",
        name: "mail-security",
        component: () => import("@/views/MailSecurityView.vue"),
        meta: { requiresAdmin: true },
      },
      // Redirects for old system/server config routes
      {
        path: "server-config",
        redirect: { name: "system", query: { tab: "ols" } },
      },
      {
        path: "system-config",
        redirect: { name: "system", query: { tab: "overview" } },
      },
      {
        path: "cron",
        name: "cron",
        component: () => import("@/views/CronView.vue"),
        meta: { requiresAdmin: true },
      },
      {
        path: "nas-storage",
        name: "nas-storage",
        component: () => import("@/views/NASStorageView.vue"),
        meta: { requiresAdmin: true },
      },
      // Redirects for old routes
      {
        path: "php",
        redirect: { name: "system", query: { tab: "php" } },
      },
      {
        path: "mysql",
        redirect: { name: "system", query: { tab: "mysql" } },
      },
      {
        path: "postfix",
        redirect: { name: "system", query: { tab: "postfix" } },
      },
      {
        path: "dovecot",
        redirect: { name: "system", query: { tab: "dovecot" } },
      },
      {
        path: "agent-status",
        name: "agent-status",
        component: () => import("@/views/AgentStatusView.vue"),
      },
      {
        path: "users",
        name: "users",
        component: () => import("@/views/UsersView.vue"),
        meta: { requiresAdmin: true },
      },
      {
        path: "sftp-users",
        name: "sftp-users",
        component: () => import("@/views/SftpUsersView.vue"),
        meta: { requiresAdmin: true },
      },
      {
        path: "billing-management",
        name: "billing-management",
        component: () => import("@/views/BillingManagementView.vue"),
        meta: { requiresSuperAdmin: true },
      },
      {
        path: "clients/:id",
        name: "client-detail",
        component: () => import("@/views/ClientDetailView.vue"),
        meta: { requiresSuperAdmin: true },
      },
      {
        path: "ai-helper",
        name: "ai-helper",
        component: () => import("@/views/AIHelperView.vue"),
        meta: { requiresAuth: true },
      },
      // Redirects for old routes
      {
        path: "clients",
        redirect: { name: "billing-management", query: { tab: "clients" } },
      },
      {
        path: "billing",
        redirect: { name: "billing-management", query: { tab: "billing" } },
      },
      {
        path: "payments",
        redirect: { name: "billing-management", query: { tab: "payments" } },
      },
    ],
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore();

  // Check authentication first
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    next({ name: "login" });
    return;
  }

  // Guest only routes
  if (to.meta.guest && auth.isAuthenticated) {
    next({ name: "dashboard" });
    return;
  }

  // For any authenticated route, make sure user info is loaded
  if (to.meta.requiresAuth && auth.isAuthenticated && !auth.user) {
    await auth.checkAuth();
  }

  if (to.meta.requiresSuperAdmin) {
    if (!auth.isSuperAdmin) {
      next({ name: "dashboard" });
      return;
    }
  }

  if (to.meta.requiresAdmin) {
    if (!auth.isAdmin) {
      next({ name: "dashboard" });
      return;
    }
  }

  next();
});

export default router;
