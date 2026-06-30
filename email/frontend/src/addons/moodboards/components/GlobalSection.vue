<template>
  <div class="border-b border-surface-200 dark:border-surface-700">
    <button
      @click="$emit('toggle')"
      class="w-full flex items-center gap-2 px-3 py-2 bg-surface-100 dark:bg-[#1c1c22] hover:bg-surface-200 dark:hover:bg-[#24242c] transition-colors"
    >
      <span class="material-symbols-rounded text-sm text-surface-400 transition-transform" :class="collapsed ? '-rotate-90' : ''">expand_more</span>
      <span class="material-symbols-rounded text-sm" :class="collapsed ? 'text-surface-400' : 'text-primary-500'">{{ icon }}</span>
      <span class="text-[10px] font-semibold uppercase tracking-wider flex-1 text-left" :class="collapsed ? 'text-surface-400' : 'text-surface-700 dark:text-surface-300'">{{ title }}</span>
      <span v-if="!hideCount" class="text-[9px] text-surface-400 tabular-nums">{{ count }}</span>
    </button>
    <div v-show="!collapsed" class="px-3 pb-3">
      <slot />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  icon: { type: String, required: true },
  count: { type: Number, default: 0 },
  collapsed: { type: Boolean, default: false },
  hideCount: { type: Boolean, default: false },
})
defineEmits(['toggle'])
</script>
