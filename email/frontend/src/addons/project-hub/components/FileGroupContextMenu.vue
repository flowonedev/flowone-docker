<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
  visible: { type: Boolean, default: false },
  groups: {
    type: Array,
    default: () => ['General', 'Contract', 'Bills', 'Assets'],
  },
})

const emit = defineEmits(['assign', 'close'])

function handleClickOutside(e) {
  if (!e.target.closest('.file-group-context-menu')) {
    emit('close')
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside, true)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside, true)
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="file-group-context-menu fixed z-[9999] min-w-[180px] py-1.5 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-xl"
      :style="{ left: x + 'px', top: y + 'px' }"
    >
      <p class="px-3 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wide">Assign Group</p>
      <button
        v-for="group in groups"
        :key="group"
        class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center gap-2"
        @click="emit('assign', group); emit('close')"
      >
        <span class="material-symbols-rounded text-[16px] text-surface-400">label</span>
        {{ group }}
      </button>
    </div>
  </Teleport>
</template>
