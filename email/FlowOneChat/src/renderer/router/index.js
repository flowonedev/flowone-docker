import { createRouter, createWebHashHistory } from 'vue-router'
import { isElectron } from '@/services/electronApi'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/',
      redirect: '/chat',
    },
    {
      path: '/mailbox',
      name: 'mailbox',
      redirect: '/chat',
    },
    {
      path: '/chat',
      name: 'chat',
      component: () => import('@/addons/chat/views/ChatView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/chat/invite/:token',
      name: 'chat-invite',
      component: () => import('@/addons/chat/views/ChatInviteView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/meet/:token',
      name: 'meeting-join',
      component: () => import('@/addons/chat/views/MeetingJoinView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/drive',
      name: 'drive',
      component: () => import('@/views/DriveView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/drive/folder/:folderId',
      name: 'drive-folder',
      component: () => import('@/views/DriveView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/drive/doc/:uuid',
      name: 'drive-document',
      component: () => import('@/views/DriveView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/drive/ppt/:uuid',
      name: 'drive-presentation',
      component: () => import('@/views/DriveView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/calendar',
      name: 'calendar',
      component: () => import('@/addons/calendar/views/CalendarView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/team',
      name: 'team',
      component: () => import('@/addons/team/components/colleagues/ColleagueManager.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('../views/ChatSettingsView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/chat',
    },
  ],
})

router.beforeEach(async (to, from, next) => {
  let hasToken = false

  if (isElectron()) {
    try {
      hasToken = await window.api.auth.isLoggedIn()
    } catch (_) {
      hasToken = !!localStorage.getItem('webmail_token')
    }
  } else {
    hasToken = !!localStorage.getItem('webmail_token')
  }

  if (to.meta.requiresAuth && !hasToken) {
    next('/login')
  } else if (to.meta.guest && hasToken) {
    next('/chat')
  } else {
    next()
  }
})

export default router
