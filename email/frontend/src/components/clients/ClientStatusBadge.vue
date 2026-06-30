<template>
  <span :class="badgeClass" :title="tooltipText">
    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="dotClass"></span>
    <span v-if="showLabel" class="ml-1">{{ displayLabel }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  status: {
    type: String,
    required: true,
    validator: (value) => ['active', 'waiting', 'attention'].includes(value),
  },
  showLabel: {
    type: Boolean,
    default: true
  },
  lastActivityAt: {
    type: String,
    default: null
  },
  showTime: {
    type: Boolean,
    default: false
  }
});

// Calculate time elapsed since last activity
const timeElapsed = computed(() => {
  if (!props.lastActivityAt) return null;
  
  const lastActivity = new Date(props.lastActivityAt);
  const now = new Date();
  const diffMs = now - lastActivity;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);
  
  if (diffMins < 1) return 'just now';
  if (diffMins < 60) return `${diffMins}m`;
  if (diffHours < 24) return `${diffHours}h`;
  if (diffDays < 7) return `${diffDays}d`;
  if (diffDays < 30) return `${Math.floor(diffDays / 7)}w`;
  return `${Math.floor(diffDays / 30)}mo`;
});

const badgeClass = computed(() => {
  // Smaller badge when no label
  const base = props.showLabel 
    ? 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium'
    : 'inline-flex items-center justify-center w-5 h-5 rounded-full';
  
  switch (props.status) {
    case 'active':
      return `${base} bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-400`;
    case 'waiting':
      return `${base} bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-400`;
    case 'attention':
      return `${base} bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-400`;
    default:
      return `${base} bg-surface-100 text-surface-800 dark:bg-surface-700 dark:text-surface-200`;
  }
});

const dotClass = computed(() => {
  switch (props.status) {
    case 'active':
      return 'bg-green-500';
    case 'waiting':
      return 'bg-yellow-500';
    case 'attention':
      return 'bg-red-500';
    default:
      return 'bg-surface-400';
  }
});

const baseLabel = computed(() => {
  switch (props.status) {
    case 'active':
      return 'Active';
    case 'waiting':
      return 'Waiting';
    case 'attention':
      return 'Attention';
    default:
      return props.status;
  }
});

const displayLabel = computed(() => {
  if (!props.showTime || !timeElapsed.value) {
    return baseLabel.value;
  }
  
  // Show time context based on status
  if (props.status === 'waiting') {
    return `Waiting ${timeElapsed.value}`;
  }
  if (props.status === 'attention') {
    return `${timeElapsed.value} no reply`;
  }
  if (props.status === 'active') {
    return `Active ${timeElapsed.value} ago`;
  }
  
  return baseLabel.value;
});

const tooltipText = computed(() => {
  const timeInfo = timeElapsed.value ? ` (${timeElapsed.value})` : '';
  
  switch (props.status) {
    case 'active':
      return `Client responded${timeInfo}`;
    case 'waiting':
      return `Waiting for client response${timeInfo}`;
    case 'attention':
      return `No response in 14+ days - requires attention${timeInfo}`;
    default:
      return '';
  }
});
</script>
