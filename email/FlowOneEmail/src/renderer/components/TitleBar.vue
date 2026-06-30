<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import logoUrl from '@/assets/flowone-logo.png'

const isMaximized = ref(false)
const isOffline = ref(false)
const isMac = computed(() => window.api?.platform === 'darwin')

async function checkMaximized() {
  if (window.api) {
    isMaximized.value = await window.api.window.isMaximized()
  }
}

function minimize() {
  window.api?.window.minimize()
}

function maximize() {
  window.api?.window.maximize()
  isMaximized.value = !isMaximized.value
}

function close() {
  window.api?.window.close()
}

onMounted(async () => {
  checkMaximized()
  
  if (window.api) {
    window.addEventListener('resize', checkMaximized)
  }
  
  if (window.api?.network?.onStatusChange) {
    window.api.network.onStatusChange((online) => {
      isOffline.value = !online
    })
  }
  
  window.addEventListener('online', () => { isOffline.value = false })
  window.addEventListener('offline', () => { isOffline.value = true })
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMaximized)
})
</script>

<template>
  <div class="h-8 flex items-center select-none draggable-region border-b bg-white dark:bg-surface-900 border-surface-200 dark:border-surface-800">
    <!-- macOS: just a draggable bar with traffic light space -->
    <template v-if="isMac">
      <div class="w-[72px] shrink-0"></div>
      <div v-if="isOffline" class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-amber-500/20 border border-amber-500/30 non-draggable">
        <span class="material-symbols-rounded text-amber-500 text-xs">cloud_off</span>
        <span class="text-[10px] font-medium text-amber-500">Offline</span>
      </div>
    </template>

    <!-- Windows/Linux: app icon, name, offline indicator -->
    <div v-if="!isMac" class="flex items-center gap-2 px-3 h-full non-draggable shrink-0">
      <img :src="logoUrl" alt="FlowOne" class="w-5 h-5 object-contain" />
      <span class="text-xs font-medium text-surface-600 dark:text-surface-300">FlowOne</span>
      
      <div v-if="isOffline" class="flex items-center gap-1.5 ml-2 px-2 py-0.5 rounded-full bg-amber-500/20 border border-amber-500/30">
        <span class="material-symbols-rounded text-amber-500 text-xs">cloud_off</span>
        <span class="text-[10px] font-medium text-amber-500">Offline</span>
      </div>
    </div>
    
    <!-- Spacer (draggable) -->
    <div class="flex-1"></div>
    
    <!-- Window controls: Windows/Linux only -->
    <div v-if="!isMac" class="flex items-center h-full non-draggable">
      <button
        @click="minimize"
        class="h-full w-11 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
        title="Minimize"
      >
        <span class="material-symbols-rounded text-base text-surface-500 dark:text-surface-400">remove</span>
      </button>
      
      <button
        @click="maximize"
        class="h-full w-11 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
        :title="isMaximized ? 'Restore' : 'Maximize'"
      >
        <span class="material-symbols-rounded text-base text-surface-500 dark:text-surface-400">
          {{ isMaximized ? 'filter_none' : 'crop_square' }}
        </span>
      </button>
      
      <button
        @click="close"
        class="close-btn h-full w-11 flex items-center justify-center hover:bg-red-600 transition-colors"
        title="Close"
      >
        <span class="material-symbols-rounded text-base text-surface-500 dark:text-surface-400">close</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.draggable-region {
  -webkit-app-region: drag;
}

.non-draggable {
  -webkit-app-region: no-drag;
}

.close-btn:hover .material-symbols-rounded {
  color: white;
}
</style>
