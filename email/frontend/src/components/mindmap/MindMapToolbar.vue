<script setup>
import { ref } from 'vue'
import { MINDMAP_MODES } from '@/stores/mindmap'

const props = defineProps({
  zoom: {
    type: Number,
    default: 1
  },
  mode: {
    type: Object,
    default: () => ({})
  },
  availableModes: {
    type: Array,
    default: () => Object.values(MINDMAP_MODES)
  }
})

const emit = defineEmits([
  'zoom-in',
  'zoom-out', 
  'fit-to-view',
  'expand-all',
  'collapse-all',
  'reset-view',
  'reset-layout',
  'change-mode',
  'export'
])

// Show dropdowns
const showShortcuts = ref(false)
const showModeMenu = ref(false)

const shortcuts = [
  { keys: ['Scroll'], action: 'Zoom in/out' },
  { keys: ['Drag bg'], action: 'Pan view' },
  { keys: ['Drag node'], action: 'Move node' },
  { keys: ['+', '-'], action: 'Zoom in/out' },
  { keys: ['0'], action: 'Fit to view' },
  { keys: ['Esc'], action: 'Close' },
]

function selectMode(newMode) {
  emit('change-mode', newMode)
  showModeMenu.value = false
}
</script>

<template>
  <div class="flex items-center justify-between px-4 py-2 bg-surface-900/60 border-b border-surface-700/50">
    <!-- Left: View Controls -->
    <div class="flex items-center gap-1">
      <!-- Zoom Controls -->
      <div class="flex items-center gap-0.5 bg-surface-800/80 rounded-full p-1 border border-surface-700/50">
        <button
          @click="emit('zoom-out')"
          class="toolbar-btn"
          title="Zoom out (-)"
        >
          <span class="material-symbols-rounded text-lg">remove</span>
        </button>
        
        <span class="px-2 text-sm font-medium text-surface-400 min-w-[50px] text-center font-mono">
          {{ Math.round(zoom * 100) }}%
        </span>
        
        <button
          @click="emit('zoom-in')"
          class="toolbar-btn"
          title="Zoom in (+)"
        >
          <span class="material-symbols-rounded text-lg">add</span>
        </button>
      </div>
      
      <div class="w-px h-6 bg-surface-700/50 mx-1"></div>
      
      <!-- Fit & Reset -->
      <button
        @click="emit('fit-to-view')"
        class="toolbar-btn"
        title="Fit to view (0)"
      >
        <span class="material-symbols-rounded text-lg">fit_screen</span>
      </button>
      
      <button
        @click="emit('reset-view')"
        class="toolbar-btn"
        title="Reset view"
      >
        <span class="material-symbols-rounded text-lg">restart_alt</span>
      </button>
      
      <button
        @click="emit('reset-layout')"
        class="toolbar-btn"
        title="Reset node positions"
      >
        <span class="material-symbols-rounded text-lg">grid_view</span>
      </button>
    </div>
    
    <!-- Center: Mode Switcher -->
    <div class="relative">
      <button
        @click="showModeMenu = !showModeMenu"
        class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-800/80 hover:bg-surface-700/80 border border-surface-700/50 transition-colors"
      >
        <span class="material-symbols-rounded text-lg text-primary-400">{{ mode.icon }}</span>
        <span class="text-sm font-medium text-surface-200">{{ mode.label }}</span>
        <span class="material-symbols-rounded text-lg text-surface-500">expand_more</span>
      </button>
      
      <!-- Mode Dropdown -->
      <Transition name="dropdown">
        <div
          v-if="showModeMenu"
          class="absolute left-1/2 -translate-x-1/2 top-full mt-2 w-64 bg-surface-800 rounded-xl shadow-xl border border-surface-700 p-2 z-50"
        >
          <div class="text-xs font-medium text-surface-500 px-3 py-1.5 uppercase tracking-wider">
            Visualization Mode
          </div>
          <button
            v-for="m in availableModes"
            :key="m.id"
            @click="selectMode(m)"
            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors"
            :class="[
              mode.id === m.id 
                ? 'bg-primary-500/15 text-primary-300' 
                : 'hover:bg-surface-700 text-surface-300'
            ]"
          >
            <span 
              class="material-symbols-rounded text-xl"
              :class="mode.id === m.id ? 'text-primary-400' : 'text-surface-500'"
            >
              {{ m.icon }}
            </span>
            <div class="flex-1">
              <div class="text-sm font-medium">{{ m.label }}</div>
              <div class="text-xs text-surface-500 capitalize">{{ m.layout }} layout</div>
            </div>
            <span 
              v-if="mode.id === m.id"
              class="material-symbols-rounded text-lg text-primary-400"
            >
              check
            </span>
          </button>
        </div>
      </Transition>
      
      <!-- Click outside to close -->
      <div 
        v-if="showModeMenu" 
        class="fixed inset-0 z-40" 
        @click="showModeMenu = false"
      ></div>
    </div>
    
    <!-- Right: Actions -->
    <div class="flex items-center gap-1">
      <!-- Expand/Collapse -->
      <button
        @click="emit('expand-all')"
        class="toolbar-btn"
        title="Expand all"
      >
        <span class="material-symbols-rounded text-lg">unfold_more</span>
      </button>
      
      <button
        @click="emit('collapse-all')"
        class="toolbar-btn"
        title="Collapse all"
      >
        <span class="material-symbols-rounded text-lg">unfold_less</span>
      </button>
      
      <div class="w-px h-6 bg-surface-700/50 mx-1"></div>
      
      <!-- Keyboard Shortcuts -->
      <div class="relative">
        <button
          @click="showShortcuts = !showShortcuts"
          @blur="showShortcuts = false"
          class="toolbar-btn"
          title="Keyboard shortcuts"
        >
          <span class="material-symbols-rounded text-lg">keyboard</span>
        </button>
        
        <!-- Shortcuts Dropdown -->
        <Transition name="dropdown">
          <div
            v-if="showShortcuts"
            class="absolute right-0 top-full mt-2 w-56 bg-surface-800 rounded-xl shadow-xl border border-surface-700 p-3 z-50"
          >
            <h4 class="text-sm font-semibold text-surface-100 mb-2">
              Keyboard Shortcuts
            </h4>
            <div class="space-y-1.5">
              <div 
                v-for="(shortcut, i) in shortcuts" 
                :key="i"
                class="flex items-center justify-between text-sm"
              >
                <span class="text-surface-400">{{ shortcut.action }}</span>
                <div class="flex items-center gap-1">
                  <kbd 
                    v-for="key in shortcut.keys" 
                    :key="key"
                    class="px-1.5 py-0.5 bg-surface-700 rounded text-xs font-mono text-surface-300"
                  >
                    {{ key }}
                  </kbd>
                </div>
              </div>
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </div>
</template>

<style scoped>
.toolbar-btn {
  @apply w-8 h-8 flex items-center justify-center rounded-full text-surface-400 hover:bg-surface-700/60 hover:text-surface-200 transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent;
}

.dropdown-enter-active,
.dropdown-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
