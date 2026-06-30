<script setup>
import { watch, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  show: Boolean,
  title: String,
  size: {
    type: String,
    default: 'md' // sm, md, lg, xl
  }
})

const emit = defineEmits(['close'])

const sizeClasses = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
  '5xl': 'max-w-5xl',
  '6xl': 'max-w-6xl',
  full: 'max-w-[60rem]'
}

const close = () => {
  emit('close')
}

const handleEsc = (e) => {
  if (e.key === 'Escape' && props.show) {
    close()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleEsc)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleEsc)
})

watch(() => props.show, (show) => {
  if (show) {
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="show"
        class="modal-overlay"
        @mousedown.self="close"
      >
        <div :class="['modal', sizeClasses[size]]" @click.stop @mousedown.stop>
          <!-- Header -->
          <div class="card-header flex items-center justify-between">
            <h3 class="text-lg font-semibold">
              <slot name="title">{{ title }}</slot>
            </h3>
            <button
              @click="close"
              class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            >
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>

          <!-- Body -->
          <div class="card-body">
            <slot />
          </div>

          <!-- Footer -->
          <div v-if="$slots.footer" class="px-6 py-4 border-t border-surface-100 dark:border-surface-800 flex justify-end gap-3">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-active .modal,
.modal-leave-active .modal {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .modal,
.modal-leave-to .modal {
  opacity: 0;
  transform: scale(0.95);
}
</style>

