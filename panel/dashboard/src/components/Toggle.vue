<script setup>
const props = defineProps({
  modelValue: Boolean,
  disabled: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue'])

const toggle = () => {
  if (!props.disabled) {
    emit('update:modelValue', !props.modelValue)
  }
}
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :class="[
      'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200',
      modelValue ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
      disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
    ]"
    @click="toggle"
  >
    <span
      :class="[
        'inline-block h-4 w-4 shrink-0 transform rounded-full bg-white shadow-sm transition-transform duration-200',
        modelValue ? 'translate-x-6' : 'translate-x-1'
      ]"
    />
  </button>
</template>

