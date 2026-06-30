import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
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
      // The shared LoginView.postLoginPath() defaults to /inbox (email app);
      // the chat app has no inbox, so send the post-login landing to /chat.
      path: '/inbox',
      name: 'inbox',
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
      // The Chat app is chat + drive only — no calendar. Redirect any lingering
      // /calendar link (or a stray calendar deep link) back to chat.
      path: '/calendar',
      name: 'calendar',
      redirect: '/chat',
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
      component: () => import('@/views/SettingsView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/chat',
    },
  ],
})

router.beforeEach(async (to, _from, next) => {
  const hasToken = !!localStorage.getItem('webmail_token')

  if (to.meta.requiresAuth && !hasToken) {
    next('/login')
  } else if (to.meta.guest && hasToken) {
    next('/chat')
  } else {
    next()
  }
})

export default router
