<script setup lang="ts">
import { ref, computed } from 'vue'
import { useThemeStore } from '../stores/theme'
import logoUrl from '@/assets/flowone-logo.png'

const isMaximized = ref(false)
const isMac = computed(() => window.api?.platform === 'darwin')
const { theme, toggleTheme } = useThemeStore()

async function minimize() {
  await window.api.minimize()
}

async function maximize() {
  await window.api.maximize()
  isMaximized.value = !isMaximized.value
}

async function close() {
  await window.api.close()
}
</script>

<template>
  <div class="h-8 flex items-center justify-between select-none" style="-webkit-app-region: drag; background: var(--titlebar-bg);">
    <!-- macOS: traffic light space + theme toggle -->
    <div v-if="isMac" class="w-[72px] shrink-0"></div>

    <!-- Windows/Linux: app icon and name -->
    <div v-if="!isMac" class="flex items-center gap-2 px-3">
      <img :src="logoUrl" alt="FlowOne" class="w-5 h-5 object-contain" />
      <span style="color: var(--text-muted); font-size: 13px; font-weight: 500;">FlowOne Drive</span>
    </div>

    <!-- Theme toggle (always visible, both platforms) -->
    <div class="flex items-center" style="-webkit-app-region: no-drag;">
      <button
        @click="toggleTheme"
        class="w-8 h-8 flex items-center justify-center transition-colors"
        style="color: var(--text-dim); border-radius: 6px;"
        :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
      >
        <span class="material-symbols-rounded" style="font-size: 16px;">
          {{ theme === 'dark' ? 'light_mode' : 'dark_mode' }}
        </span>
      </button>

      <!-- Window controls: Windows/Linux only -->
      <template v-if="!isMac">
        <button
          @click="minimize"
          class="w-11 h-8 flex items-center justify-center transition-colors"
          style="color: var(--text-dim);"
        >
          <span class="material-symbols-rounded text-lg">remove</span>
        </button>
        <button
          @click="maximize"
          class="w-11 h-8 flex items-center justify-center transition-colors"
          style="color: var(--text-dim);"
        >
          <span class="material-symbols-rounded text-lg">{{ isMaximized ? 'filter_none' : 'crop_square' }}</span>
        </button>
        <button
          @click="close"
          class="w-11 h-8 flex items-center justify-center hover:bg-red-500 hover:text-white transition-colors"
          style="color: var(--text-dim);"
        >
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </template>
    </div>
  </div>
</template>
