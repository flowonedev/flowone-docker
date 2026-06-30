import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  root: '.',
  base: './',
  publicDir: resolve(__dirname, 'src/public'),
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    modulePreload: {
      polyfill: false,
    },
    rollupOptions: {
      output: {
        format: 'es',
      },
    },
  },
  experimental: {
    renderBuiltUrl(filename, { hostType }) {
      if (hostType === 'js') {
        return { relative: true }
      }
      return filename
    },
  },
  resolve: {
    dedupe: ['vue', 'pinia', 'vue-router', '@vue/runtime-core', '@vue/reactivity', '@vue/shared'],
    alias: {
      '@original-chat-sidebar': resolve(__dirname, '../frontend/src/addons/chat/components/ChatSidebar.vue'),
      [resolve(__dirname, '../frontend/src/addons/chat/components/ChatSidebar.vue')]: resolve(__dirname, 'src/components/CollapsibleChatSidebar.vue'),
      '@/components/shared/AppHeader.vue': resolve(__dirname, 'src/components/ChatAppHeader.vue'),
      // The shared mobile bottom nav lists the full suite (Email, Calendar,
      // Boards, ...). The Chat app only ships Chat + Drive, so swap it for the
      // trimmed chat-only nav.
      '@/components/MobileBottomNav.vue': resolve(__dirname, 'src/components/ChatMobileBottomNav.vue'),
      '@/components/shared/HowItWorksButton.vue': resolve(__dirname, 'src/components/stubs/HowItWorksButton.vue'),
      '@/components/shared/FeatureGuide.vue': resolve(__dirname, 'src/components/stubs/FeatureGuide.vue'),
      '@/services/api': resolve(__dirname, 'src/services/api.js'),
      '@/services/tokenStorage': resolve(__dirname, 'src/services/tokenStorage.js'),
      '@/services/electronApi': resolve(__dirname, 'src/services/electronApi.js'),
      '@/services/offlineMailbox': resolve(__dirname, 'src/services/offlineMailbox.js'),
      '@/services/mailSyncSocket': resolve(__dirname, 'src/services/mailSyncSocket.js'),
      '@/services/pushNotifications': resolve(__dirname, 'src/services/pushNotifications.js'),
      '@/stores/auth': resolve(__dirname, 'src/stores/auth.js'),
      // @capacitor-firebase/messaging's web impl imports the firebase web SDK,
      // which we never use (FCM runs natively via the Capacitor bridge). Alias
      // it to the shared stub so the build resolves without bundling firebase.
      'firebase/messaging': resolve(__dirname, '../frontend/src/stubs/firebaseMessagingStub.js'),
      '@collab': resolve(__dirname, '../frontend/src/collab'),
      '@': resolve(__dirname, '../frontend/src'),
    },
  },
  server: {
    port: 5177,
  },
})
