<script setup>
const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    default: 'Confirm'
  },
  message: {
    type: String,
    default: 'Are you sure?'
  },
  confirmText: {
    type: String,
    default: 'Confirm'
  },
  cancelText: {
    type: String,
    default: 'Cancel'
  },
  danger: {
    type: Boolean,
    default: false
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['confirm', 'cancel'])

function confirm() {
  if (!props.loading) {
    emit('confirm')
  }
}

function cancel() {
  if (!props.loading) {
    emit('cancel')
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div 
        v-if="show"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
      >
        <!-- Backdrop -->
        <div 
          class="absolute inset-0 bg-black/50 backdrop-blur-sm"
          @click="cancel"
        ></div>
        
        <!-- Modal -->
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
          <!-- Header -->
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span 
                v-if="danger" 
                class="material-symbols-rounded text-red-500"
              >warning</span>
              {{ title }}
            </h3>
          </div>
          
          <!-- Content -->
          <div class="px-6 py-4">
            <p class="text-surface-600 dark:text-surface-400">
              {{ message }}
            </p>
          </div>
          
          <!-- Footer -->
          <div class="px-6 py-4 bg-surface-50 dark:bg-surface-900 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
            <button
              @click="cancel"
              :disabled="loading"
              class="px-4 py-2 text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-xl transition-colors disabled:opacity-50"
            >
              {{ cancelText }}
            </button>
            <button
              @click="confirm"
              :disabled="loading"
              :class="[
                'px-4 py-2 text-sm font-medium rounded-xl transition-colors flex items-center gap-2 disabled:opacity-50',
                danger 
                  ? 'bg-red-500 hover:bg-red-600 text-white' 
                  : 'bg-primary-500 hover:bg-primary-600 text-white'
              ]"
            >
              <span 
                v-if="loading" 
                class="material-symbols-rounded animate-spin text-lg"
              >progress_activity</span>
              {{ confirmText }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: all 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .relative,
.modal-leave-to .relative {
  transform: scale(0.95);
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>

