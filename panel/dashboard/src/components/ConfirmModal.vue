<script setup>
import { ref, computed, watch } from 'vue'
import Modal from './Modal.vue'

const props = defineProps({
  show: Boolean,
  title: {
    type: String,
    default: 'Confirm Action'
  },
  message: String,
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
  },
  // Require typing this word to confirm (for dangerous actions)
  requireConfirmation: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['confirm', 'cancel'])

const confirmInput = ref('')

// Reset input when modal opens/closes
watch(() => props.show, (newVal) => {
  if (!newVal) {
    confirmInput.value = ''
  }
})

// Check if confirmation requirement is met
const canConfirm = computed(() => {
  if (!props.requireConfirmation) return true
  return confirmInput.value.toLowerCase() === props.requireConfirmation.toLowerCase()
})

const handleConfirm = () => {
  if (canConfirm.value) {
    emit('confirm')
  }
}
</script>

<template>
  <Modal :show="show" :title="title" size="sm" @close="emit('cancel')">
    <p class="text-surface-600 dark:text-surface-400 mb-4">
      {{ message }}
    </p>

    <!-- Confirmation input for dangerous actions -->
    <div v-if="requireConfirmation" class="mt-4">
      <label class="block text-sm font-medium mb-2">
        Type <span class="font-bold text-red-500">{{ requireConfirmation }}</span> to confirm:
      </label>
      <input
        v-model="confirmInput"
        type="text"
        class="input"
        :placeholder="`Type ${requireConfirmation}`"
        @keyup.enter="handleConfirm"
      />
    </div>

    <template #footer>
      <button
        class="btn-secondary"
        @click="emit('cancel')"
        :disabled="loading"
      >
        {{ cancelText }}
      </button>
      <button
        :class="danger ? 'btn-danger' : 'btn-primary'"
        @click="handleConfirm"
        :disabled="loading || !canConfirm"
      >
        <span v-if="loading" class="spinner" />
        {{ confirmText }}
      </button>
    </template>
  </Modal>
</template>
