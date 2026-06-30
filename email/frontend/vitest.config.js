import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      '@collab': resolve(__dirname, 'src/collab'),
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    include: ['tests/**/*.test.js'],
    coverage: {
      provider: 'v8',
      reportsDirectory: 'tests/coverage',
      include: ['src/**/*.{js,vue}'],
      exclude: ['src/main.js', 'src/router/**', 'node_modules/**'],
    },
  },
})
