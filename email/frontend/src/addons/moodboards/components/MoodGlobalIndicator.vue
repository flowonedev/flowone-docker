<template>
  <span
    v-if="globalRef"
    class="inline-flex items-center gap-0.5 cursor-pointer group/gi"
    @click.stop="showPopover = !showPopover"
  >
    <!-- Linked badge -->
    <span
      class="relative flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[8px] font-bold transition-colors"
      :class="badgeClasses"
      :title="`Linked to global: ${globalName}`"
    >
      <span class="material-symbols-rounded" style="font-size: 10px">link</span>
      <span class="max-w-[60px] truncate">{{ globalName }}</span>
    </span>

    <!-- Popover -->
    <Teleport to="body">
      <div
        v-if="showPopover"
        class="fixed inset-0 z-[80]"
        @click.self="showPopover = false"
      >
        <div
          ref="popoverRef"
          class="absolute z-[81] bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-xl shadow-xl p-3 min-w-[180px]"
          :style="popoverStyle"
        >
          <div class="flex items-center gap-2 mb-2">
            <span
              v-if="globalRef.type === 'color'"
              class="w-4 h-4 rounded border border-surface-300 dark:border-surface-600"
              :style="{ backgroundColor: colorValue }"
            />
            <span class="material-symbols-rounded text-sm text-primary-500" v-else>text_fields</span>
            <span class="text-xs font-semibold text-surface-700 dark:text-surface-300">{{ globalName }}</span>
          </div>
          <p class="text-[10px] text-surface-400 mb-3">
            <template v-if="globalRef.type === 'color'">Global Color</template>
            <template v-else>Global Text Style</template>
          </p>
          <button
            @click="detach"
            class="w-full flex items-center justify-center gap-1.5 px-2 py-1.5 text-[11px] font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors border border-red-200 dark:border-red-800"
          >
            <span class="material-symbols-rounded text-sm">link_off</span>
            Detach from Global
          </button>
        </div>
      </div>
    </Teleport>
  </span>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useMoodBoardGlobalStylesStore } from '@/addons/moodboards/stores/moodBoardGlobalStyles'
import { getGlobalRef } from '@/addons/moodboards/utils/globalStyleResolver'

const props = defineProps({
  item: { type: Object, required: true },
  styleKey: { type: String, required: true },
})

const emit = defineEmits(['detached'])

const gsStore = useMoodBoardGlobalStylesStore()
const showPopover = ref(false)
const popoverRef = ref(null)

const globalRef = computed(() => getGlobalRef(props.item, props.styleKey))

const globalName = computed(() => {
  if (!globalRef.value) return ''
  const { type, id } = globalRef.value
  if (type === 'color') {
    const token = gsStore.globalColors.find(c => c.id === id)
    return token?.name || 'Unknown'
  }
  if (type === 'text_style') {
    const style = gsStore.globalTextStyles.find(s => s.id === id)
    return style?.name || 'Unknown'
  }
  return 'Unknown'
})

const colorValue = computed(() => {
  if (globalRef.value?.type !== 'color') return null
  const token = gsStore.globalColors.find(c => c.id === globalRef.value.id)
  return token?.value || '#000'
})

const badgeClasses = computed(() => {
  if (globalRef.value?.type === 'color') {
    return 'bg-violet-100 dark:bg-violet-900/30 text-violet-600 dark:text-violet-400'
  }
  return 'bg-sky-100 dark:bg-sky-900/30 text-sky-600 dark:text-sky-400'
})

const popoverStyle = computed(() => {
  return { top: '50%', left: '50%', transform: 'translate(-50%, -50%)' }
})

function detach() {
  gsStore.unlinkGlobal(props.item.id, props.styleKey)
  showPopover.value = false
  emit('detached')
}
</script>
