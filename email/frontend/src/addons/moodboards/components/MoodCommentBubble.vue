<template>
  <div
    class="absolute z-[50] pointer-events-auto cursor-pointer group"
    :style="bubblePosition"
    @click.stop="$emit('click', itemId)"
  >
    <div
      class="relative flex items-center justify-center w-6 h-6 rounded-full shadow-md transition-all duration-150 group-hover:scale-110"
      :class="hasUnresolved
        ? 'bg-primary-500 text-white ring-2 ring-primary-300 dark:ring-primary-700'
        : 'bg-surface-400 text-white ring-2 ring-surface-200 dark:ring-surface-600 opacity-60 group-hover:opacity-100'"
    >
      <span class="material-symbols-rounded" style="font-size: 14px;">chat_bubble</span>
      <span
        v-if="count > 1"
        class="absolute -top-1.5 -right-1.5 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[9px] font-bold flex items-center justify-center shadow-sm"
      >
        {{ count > 99 ? '99+' : count }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  itemId: { type: Number, required: true },
  count: { type: Number, default: 0 },
  hasUnresolved: { type: Boolean, default: true },
  offsetX: { type: Number, default: -4 },
  offsetY: { type: Number, default: -4 },
})

defineEmits(['click'])

const bubblePosition = computed(() => ({
  top: `${props.offsetY}px`,
  right: `${props.offsetX}px`,
}))
</script>
