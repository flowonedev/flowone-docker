<script setup>
const props = defineProps({
  icon: { type: String, required: true },
  label: { type: String, required: true },
  active: { type: Boolean, default: false },
  visited: { type: Boolean, default: false },
  dimmed: { type: Boolean, default: false },
  size: { type: String, default: 'md' },
})
</script>

<template>
  <div
    :class="[
      'onb-node flex items-center gap-2 rounded-xl px-3 py-2 border select-none whitespace-nowrap transition-all duration-500 backdrop-blur-md',
      size === 'sm' ? 'text-xs' : 'text-sm',
      active
        ? 'border-primary-500 bg-primary-50/90 dark:bg-primary-900/80 shadow-lg shadow-primary-500/25 onb-node-active'
        : visited
          ? 'border-surface-300 dark:border-surface-600 bg-white/85 dark:bg-surface-800/85'
          : 'border-surface-200 dark:border-surface-700 bg-white/80 dark:bg-surface-800/75',
      dimmed ? 'opacity-30 blur-[5px]' : visited && !active ? 'opacity-70' : 'opacity-100',
    ]"
  >
    <span
      :class="[
        'material-symbols-rounded flex-shrink-0',
        size === 'sm' ? 'text-base' : 'text-lg',
        active
          ? 'text-primary-600 dark:text-primary-400'
          : 'text-surface-500 dark:text-surface-400',
      ]"
    >{{ icon }}</span>
    <span
      :class="[
        'font-medium leading-tight',
        active
          ? 'text-primary-700 dark:text-primary-300'
          : 'text-surface-700 dark:text-surface-300',
      ]"
    >{{ label }}</span>
  </div>
</template>

<style scoped>
.onb-node-active {
  animation: nodeGlow 2s ease-in-out infinite;
}

@keyframes nodeGlow {
  0%, 100% { box-shadow: 0 0 12px 2px rgba(var(--color-primary-500), 0.2); }
  50% { box-shadow: 0 0 24px 6px rgba(var(--color-primary-500), 0.35); }
}

@media (prefers-reduced-motion: reduce) {
  .onb-node-active {
    animation: none;
    box-shadow: 0 0 16px 3px rgba(var(--color-primary-500), 0.25);
  }
}
</style>
