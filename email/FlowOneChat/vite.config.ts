import { defineConfig, Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

function removeCrossorigin(): Plugin {
  return {
    name: 'remove-crossorigin',
    enforce: 'post',
    transformIndexHtml(html) {
      return html.replace(/ crossorigin/g, '')
    }
  }
}

export default defineConfig({
  plugins: [vue(), removeCrossorigin()],
  root: 'src/renderer',
  base: './',
  publicDir: resolve(__dirname, 'src/renderer/public'),
  build: {
    outDir: '../../dist/renderer',
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
      // Chat Desktop overrides (most-specific first)
      '@original-chat-sidebar': resolve(__dirname, '../frontend/src/addons/chat/components/ChatSidebar.vue'),
      [resolve(__dirname, '../frontend/src/addons/chat/components/ChatSidebar.vue')]: resolve(__dirname, 'src/renderer/components/CollapsibleChatSidebar.vue'),
      '@/components/shared/AppHeader.vue': resolve(__dirname, 'src/renderer/components/ChatAppHeader.vue'),
      '@/components/shared/HowItWorksButton.vue': resolve(__dirname, 'src/renderer/components/stubs/HowItWorksButton.vue'),
      '@/components/shared/FeatureGuide.vue': resolve(__dirname, 'src/renderer/components/stubs/FeatureGuide.vue'),
      '@/services/api': resolve(__dirname, 'src/renderer/services/api.js'),
      '@/services/tokenStorage': resolve(__dirname, 'src/renderer/services/tokenStorage.js'),
      '@/services/electronApi': resolve(__dirname, 'src/renderer/services/electronApi.js'),
      '@/services/offlineMailbox': resolve(__dirname, 'src/renderer/services/offlineMailbox.js'),
      '@/services/mailSyncSocket': resolve(__dirname, 'src/renderer/services/mailSyncSocket.js'),
      '@/services/pushNotifications': resolve(__dirname, 'src/renderer/services/pushNotifications.js'),
      '@/stores/auth': resolve(__dirname, 'src/renderer/stores/auth.js'),
      // Shared code from the web frontend
      '@collab': resolve(__dirname, '../frontend/src/collab'),
      '@': resolve(__dirname, '../frontend/src'),
    },
  },
  server: {
    port: 5176,
  },
})
