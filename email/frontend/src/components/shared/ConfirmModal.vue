<script setup>
import Modal from './Modal.vue'

const props = defineProps({
  show: Boolean,
  title: {
    type: String,
    default: 'Confirm',
  },
  message: String,
  confirmText: {
    type: String,
    default: 'Confirm',
  },
  cancelText: {
    type: String,
    default: 'Cancel',
  },
  type: {
    type: String,
    default: 'danger', // 'danger', 'warning', 'primary'
  },
  loading: Boolean,
})

const emit = defineEmits(['confirm', 'cancel'])

const buttonClasses = {
  danger: 'btn-danger',
  warning: 'bg-amber-500 text-white hover:bg-amber-600',
  primary: 'btn-primary',
}
</script>

<template>
  <Modal :show="show" :title="title" size="sm" @close="emit('cancel')">
    <p class="text-surface-600 dark:text-surface-300">{{ message }}</p>
    
    <template #footer>
      <button class="btn-secondary" @click="emit('cancel')" :disabled="loading">
        {{ cancelText }}
      </button>
      <button 
        :class="['btn', buttonClasses[type]]" 
        @click="emit('confirm')" 
        :disabled="loading"
      >
        <span v-if="loading" class="spinner"></span>
        {{ confirmText }}
      </button>
    </template>
  </Modal>
</template>

