import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { guest: true }
  },
  {
    path: '/',
    component: () => import('../layouts/DashboardLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('../views/DashboardView.vue')
      },
      {
        path: 'servers',
        name: 'servers',
        component: () => import('../views/ServersView.vue')
      },
      {
        path: 'servers/:id',
        name: 'server-detail',
        component: () => import('../views/ServerDetailView.vue')
      },
      {
        path: 'servers/add',
        name: 'add-server',
        component: () => import('../views/AddServerView.vue')
      },
      {
        path: 'blueprints',
        name: 'blueprints',
        component: () => import('../views/BlueprintsView.vue')
      },
      {
        path: 'blueprints/create',
        name: 'create-blueprint',
        component: () => import('../views/CreateBlueprintView.vue')
      },
      {
        path: 'blueprints/:id',
        name: 'blueprint-detail',
        component: () => import('../views/BlueprintDetailView.vue')
      },
      {
        path: 'packages',
        name: 'packages',
        component: () => import('../views/PackagesView.vue')
      },
      {
        path: 'errors',
        name: 'errors',
        component: () => import('../views/ErrorsView.vue')
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('../views/SettingsView.vue')
      }
    ]
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/'
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// Navigation guard
router.beforeEach((to, from, next) => {
  const auth = useAuthStore()
  
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    next({ name: 'login', query: { redirect: to.fullPath } })
  } else if (to.meta.guest && auth.isAuthenticated) {
    next({ name: 'dashboard' })
  } else {
    next()
  }
})

export default router

