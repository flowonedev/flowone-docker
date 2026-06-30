import { defineConfig, Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

// Plugin to remove crossorigin attributes for Electron file:// compatibility
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
  publicDir: 'public',
  build: {
    outDir: '../../dist/renderer',
    emptyOutDir: true,
    // Disable crossorigin for Electron file:// compatibility
    modulePreload: {
      polyfill: false,
    },
    rollupOptions: {
      output: {
        // Disable crossorigin on script tags
        format: 'es',
      },
    },
  },
  // Fix chunk-to-chunk imports: when both the importer and the imported file
  // live in the same assets/ directory, Vite must compute relative paths so
  // we don't end up with doubled paths like assets/assets/Foo.js under file://.
  experimental: {
    renderBuiltUrl(filename, { hostType }) {
      // For JS chunks importing other JS chunks, use relative resolution
      // so the browser correctly resolves sibling files in the same directory.
      if (hostType === 'js') {
        return { relative: true }
      }
      // For HTML (index.html), the base './' already works correctly.
      return filename
    },
  },
  resolve: {
    // Force shared code to use Desktop's single copies of Vue, Pinia, etc.
    // Without this, files from ../frontend/src resolve packages from
    // frontend/node_modules, creating duplicate runtime instances.
    dedupe: ['vue', 'pinia', 'vue-router', '@vue/runtime-core', '@vue/reactivity', '@vue/shared'],
    alias: {
      // Desktop overrides — platform-specific files (most-specific FIRST)
      '@/services/api': resolve(__dirname, 'src/renderer/services/api.js'),
      '@/services/tokenStorage': resolve(__dirname, 'src/renderer/services/tokenStorage.js'),
      '@/services/electronApi': resolve(__dirname, 'src/renderer/services/electronApi.js'),
      '@/services/offlineMailbox': resolve(__dirname, 'src/renderer/services/offlineMailbox.js'),
      '@/services/offlineData': resolve(__dirname, 'src/renderer/services/offlineData.js'),
      '@/services/mailSyncSocket': resolve(__dirname, 'src/renderer/services/mailSyncSocket.js'),
      '@/services/pushNotifications': resolve(__dirname, 'src/renderer/services/pushNotifications.js'),
      '@/stores/auth': resolve(__dirname, 'src/renderer/stores/auth.js'),
      // Shared code — everything else from the web frontend
      '@collab': resolve(__dirname, '../frontend/src/collab'),
      '@': resolve(__dirname, '../frontend/src'),
    },
  },
  server: {
    port: 5174,
  },
})

