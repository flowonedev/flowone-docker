import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'
import { resolve } from 'path'
import { createHash } from 'crypto'
import { readFileSync, writeFileSync } from 'fs'

/**
 * Custom Vite plugin: Subresource Integrity (SRI)
 * Adds integrity="sha384-..." and crossorigin="anonymous" to all <script> and <link> tags
 * in the built index.html. Prevents tampering with JS/CSS bundles.
 */
function sriPlugin() {
  return {
    name: 'vite-plugin-sri',
    enforce: 'post',
    apply: 'build',
    closeBundle() {
      const distDir = resolve(__dirname, 'dist')
      const indexPath = resolve(distDir, 'index.html')
      let html
      try {
        html = readFileSync(indexPath, 'utf8')
      } catch {
        return // index.html not found, skip
      }

      // Add integrity to <script src="..."> tags
      html = html.replace(/<script([^>]+)src="(\/assets\/[^"]+)"([^>]*)>/g, (match, pre, src, post) => {
        if (match.includes('integrity')) return match
        try {
          const content = readFileSync(resolve(distDir, src.replace(/^\//, '')))
          const hash = createHash('sha384').update(content).digest('base64')
          const crossorigin = match.includes('crossorigin') ? '' : ' crossorigin="anonymous"'
          return `<script${pre}src="${src}"${post} integrity="sha384-${hash}"${crossorigin}>`
        } catch {
          return match
        }
      })

      // Add integrity to <link rel="stylesheet" href="..."> tags
      html = html.replace(/<link([^>]+)href="(\/assets\/[^"]+\.css)"([^>]*)>/g, (match, pre, href, post) => {
        if (match.includes('integrity')) return match
        try {
          const content = readFileSync(resolve(distDir, href.replace(/^\//, '')))
          const hash = createHash('sha384').update(content).digest('base64')
          const crossorigin = match.includes('crossorigin') ? '' : ' crossorigin="anonymous"'
          return `<link${pre}href="${href}"${post} integrity="sha384-${hash}"${crossorigin}>`
        } catch {
          return match
        }
      })

      writeFileSync(indexPath, html)
      console.log('[SRI] Added integrity hashes to index.html')
    }
  }
}

export default defineConfig({
  plugins: [
    vue(),
    sriPlugin(),
    VitePWA({
      registerType: 'prompt',
      includeAssets: ['flowone-logo.png', 'apple-touch-icon.png'],
      manifest: {
        name: 'FlowOne.PRO',
        short_name: 'FlowOne.PRO',
        description: 'Business email, collaboration and productivity platform by Pixel Ranger Studio',
        theme_color: '#1c1c22',
        background_color: '#1a1a20',
        display: 'standalone',
        orientation: 'portrait',
        start_url: '/',
        scope: '/',
        icons: [
          // ?v=3 busts the installed-PWA icon cache: Chromium refreshes the
          // app/taskbar icon when a manifest icon URL changes (new FlowOne logo).
          {
            src: 'pwa-192x192.png?v=3',
            sizes: '192x192',
            type: 'image/png'
          },
          {
            src: 'pwa-512x512.png?v=3',
            sizes: '512x512',
            type: 'image/png'
          },
          {
            src: 'pwa-512x512.png?v=3',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable'
          }
        ]
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2}'],
        maximumFileSizeToCacheInBytes: 6 * 1024 * 1024, // 6 MiB (livekit-client + self-hosted Material Symbols font)
        // PWA update policy: update silently under the hood, no user prompt.
        // A new build is precached in the background and activated immediately
        // (skipWaiting is triggered from the app), but clientsClaim is false so
        // the new worker does NOT take over already-open tabs mid-session. This
        // avoids breaking the running app (e.g. stale lazy-loaded chunks); users
        // simply get the new version on their next page refresh.
        clientsClaim: false,
        cleanupOutdatedCaches: true,
        // Import push notification handler into the generated service worker
        importScripts: ['/push-sw.js'],
        // CRITICAL: Exclude /api/ from navigation fallback to prevent SW returning index.html for API requests
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'google-fonts-cache',
              expiration: {
                maxEntries: 10,
                maxAgeSeconds: 60 * 60 * 24 * 365 // 1 year
              },
              cacheableResponse: {
                statuses: [0, 200]
              }
            }
          },
          {
            urlPattern: /^https:\/\/fonts\.gstatic\.com\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'gstatic-fonts-cache',
              expiration: {
                maxEntries: 10,
                maxAgeSeconds: 60 * 60 * 24 * 365 // 1 year
              },
              cacheableResponse: {
                statuses: [0, 200]
              }
            }
          },
          {
            // Only cache GET requests to /api/ that return JSON (not binary downloads)
            urlPattern: /\/api\/(?!drive\/download|drive\/test-zip).*/i,
            handler: 'NetworkOnly',
            options: {
              cacheName: 'api-cache'
            }
          }
        ]
      }
    })
  ],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      '@collab': resolve(__dirname, 'src/collab'),
      // The @capacitor-firebase/messaging web implementation imports the firebase
      // web SDK, which we never use (FCM runs natively via the Capacitor bridge;
      // the web build is guarded by isNative). Alias it to a stub so the build
      // resolves without pulling the firebase web SDK into the bundle.
      'firebase/messaging': resolve(__dirname, 'src/stubs/firebaseMessagingStub.js'),
    },
  },
  server: {
    port: 3001,
    host: true,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY || 'http://localhost:8000',
        changeOrigin: true,
      },
      '/mailsync_ws': {
        target: process.env.VITE_WS_PROXY || 'ws://localhost:1235',
        ws: true,
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    // NOTE: the native apps (FlowOneMobile) load this exact bundled `dist`, so
    // Capacitor plugins must be BUNDLED — their registerPlugin() proxy is what
    // routes to the native bridge. Do NOT externalize @capacitor/* plugins: a
    // bare import left in the bundle cannot be resolved inside a WebView.
  },
})

