<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    required: true
  },
  showDot: {
    type: Boolean,
    default: true
  }
})

const statusConfig = {
  running: { class: 'badge-success', dot: 'running', label: 'Running' },
  active: { class: 'badge-success', dot: 'running', label: 'Active' },
  enabled: { class: 'badge-success', dot: 'running', label: 'Enabled' },
  success: { class: 'badge-success', dot: 'running', label: 'Success' },
  healthy: { class: 'badge-success', dot: 'running', label: 'Healthy' },
  valid: { class: 'badge-success', dot: 'running', label: 'Valid' },
  
  stopped: { class: 'badge-danger', dot: 'stopped', label: 'Stopped' },
  inactive: { class: 'badge-danger', dot: 'stopped', label: 'Inactive' },
  disabled: { class: 'badge-danger', dot: 'stopped', label: 'Disabled' },
  failed: { class: 'badge-danger', dot: 'stopped', label: 'Failed' },
  error: { class: 'badge-danger', dot: 'stopped', label: 'Error' },
  expired: { class: 'badge-danger', dot: 'stopped', label: 'Expired' },
  invalid: { class: 'badge-danger', dot: 'stopped', label: 'Invalid' },
  
  warning: { class: 'badge-warning', dot: 'unknown', label: 'Warning' },
  pending: { class: 'badge-warning', dot: 'unknown', label: 'Pending' },
  expiring: { class: 'badge-warning', dot: 'unknown', label: 'Expiring' },
  
  unknown: { class: 'badge-neutral', dot: 'unknown', label: 'Unknown' },
}

const config = computed(() => {
  const key = props.status.toLowerCase()
  return statusConfig[key] || statusConfig.unknown
})
</script>

<template>
  <span :class="['badge', config.class]">
    <span v-if="showDot" :class="['status-dot', config.dot]" />
    {{ config.label }}
  </span>
</template>

