<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  show: Boolean,
  title: String,
  size: {
    type: String,
    default: 'md', // 'sm', 'md', 'lg', 'xl', 'full'
  },
  closable: {
    type: Boolean,
    default: true,
  },
  mobileFullscreen: {
    type: Boolean,
    default: false,
  },
})

// Detect mobile
const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 640
}
onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
})
onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

const useMobileSlide = computed(() => props.mobileFullscreen && isMobile.value)

const emit = defineEmits(['close'])

// Track where mousedown started to prevent closing when dragging text selection outside modal
const mouseDownOnOverlay = ref(false)

function close() {
  if (props.closable) {
    emit('close')
  }
}

function handleOverlayMouseDown(e) {
  // Only mark as overlay click if the click is directly on the overlay, not bubbled from children
  mouseDownOnOverlay.value = e.target === e.currentTarget
}

function handleOverlayMouseUp(e) {
  // Only close if both mousedown AND mouseup happened on the overlay
  // This prevents closing when user drags text selection outside the modal
  if (mouseDownOnOverlay.value && e.target === e.currentTarget) {
    close()
  }
  mouseDownOnOverlay.value = false
}

function handleKeydown(e) {
  if (e.key === 'Escape' && props.show && props.closable) {
    close()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})

const sizeClasses = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
  '2xl': 'max-w-5xl',
  '3xl': 'max-w-6xl',
  '4xl': 'max-w-7xl w-[90vw]',
  full: 'max-w-[95vw] max-h-[95vh]',
  fullscreen: 'w-screen h-[100dvh] sm:w-[calc(100vw-3rem)] sm:h-[calc(100vh-3rem)] max-w-none max-h-none rounded-none sm:rounded-2xl',
}
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-200"
      leave-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div v-if="show" :class="['modal-overlay', { 'mobile-fullscreen-overlay': useMobileSlide }]" @mousedown="handleOverlayMouseDown" @mouseup="handleOverlayMouseUp">
        <Transition
          :enter-active-class="useMobileSlide ? 'transition-transform duration-300 ease-out' : 'transition-all duration-200'"
          :leave-active-class="useMobileSlide ? 'transition-transform duration-200 ease-in' : 'transition-all duration-150'"
          :enter-from-class="useMobileSlide ? 'translate-y-full' : 'opacity-0 scale-95'"
          :leave-to-class="useMobileSlide ? 'translate-y-full' : 'opacity-0 scale-95'"
        >
          <div v-if="show" :class="['modal', useMobileSlide ? 'mobile-fullscreen-modal' : sizeClasses[size]]">
            <div v-if="(title || $slots.header) && !useMobileSlide" class="modal-header">
              <slot name="header">
                <h3 class="text-lg font-semibold">{{ title }}</h3>
              </slot>
              <!-- Only show built-in close button when using default header (no custom header slot) -->
              <button v-if="closable && !$slots.header" @click="close" class="btn-close">
                <span class="material-symbols-rounded text-xl">close</span>
              </button>
            </div>
            
            <!-- Mobile fullscreen header slot -->
            <slot v-if="useMobileSlide" name="mobile-header">
              <div class="mobile-modal-header">
                <button @click="close" class="p-2 -ml-2 text-surface-400">
                  <span class="material-symbols-rounded text-2xl">close</span>
                </button>
                <h3 class="text-lg font-semibold flex-1 text-center">{{ title }}</h3>
                <div class="w-10"></div>
              </div>
            </slot>
            
            <div :class="useMobileSlide ? 'mobile-modal-body' : 'modal-body'">
              <slot></slot>
            </div>
            
            <div v-if="$slots.footer && !useMobileSlide" class="modal-footer">
              <slot name="footer"></slot>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.btn-close {
  @apply w-8 h-8 flex items-center justify-center rounded-lg
         text-surface-500 hover:text-surface-700 dark:hover:text-surface-200
         hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]
         transition-colors;
}

/* Mobile fullscreen modal styles */
.mobile-fullscreen-overlay {
  @apply items-end justify-center p-0;
}

.mobile-fullscreen-modal {
  @apply w-full h-[95vh] max-w-none rounded-t-2xl rounded-b-none m-0 flex flex-col;
}

.mobile-modal-header {
  @apply flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700;
}

.mobile-modal-body {
  @apply flex-1 overflow-y-auto;
}
</style>

