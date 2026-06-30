<script setup>
import { ref, nextTick, onBeforeUnmount } from 'vue'

const props = defineProps({
  icon: { type: String, required: true },
  title: { type: String, default: '' },
  panelTitle: { type: String, default: 'Color' },
  presets: { type: Array, default: () => [] },
  indicatorColor: { type: String, default: null },
  removeLabel: { type: String, default: 'None' },
  placement: { type: String, default: 'bottom' }, // 'bottom' | 'top'
  compact: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const open = ref(false)
const triggerRef = ref(null)
const panelPos = ref({ top: '0px', left: '0px' })
const customColor = ref(props.indicatorColor || '#000000')

const PANEL_WIDTH = 196
const PANEL_HEIGHT = 168

function computePosition() {
  const el = triggerRef.value
  if (!el) return
  const rect = el.getBoundingClientRect()
  let left = rect.left
  left = Math.max(8, Math.min(left, window.innerWidth - PANEL_WIDTH - 8))
  let top
  if (props.placement === 'top') {
    top = rect.top - PANEL_HEIGHT - 6
    if (top < 8) top = rect.bottom + 6
  } else {
    top = rect.bottom + 6
    if (top + PANEL_HEIGHT > window.innerHeight - 8) {
      top = Math.max(8, rect.top - PANEL_HEIGHT - 6)
    }
  }
  panelPos.value = { top: `${top}px`, left: `${left}px` }
}

function toggle() {
  if (open.value) {
    open.value = false
    return
  }
  open.value = true
  nextTick(computePosition)
}

function close() {
  open.value = false
}

function choose(color) {
  emit('select', color)
  open.value = false
}

function onCustomInput(e) {
  customColor.value = e.target.value
  emit('select', e.target.value)
}

function onKeydown(e) {
  if (e.key === 'Escape' && open.value) {
    open.value = false
  }
}

window.addEventListener('keydown', onKeydown)
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

defineExpose({ close })
</script>

<template>
  <div class="relative inline-flex">
    <button
      ref="triggerRef"
      type="button"
      :title="title"
      @click.stop="toggle"
      :class="[
        'flex flex-col items-center justify-center rounded-lg transition-colors',
        compact ? 'p-1' : 'p-1.5',
        open
          ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400'
          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200',
      ]"
    >
      <span class="material-symbols-rounded text-lg leading-none">{{ icon }}</span>
      <span
        class="block rounded-full mt-0.5"
        :style="{
          width: compact ? '14px' : '16px',
          height: '3px',
          backgroundColor: indicatorColor || 'transparent',
          border: indicatorColor ? 'none' : '1px solid rgb(148 163 184 / 0.5)',
        }"
      ></span>
    </button>

    <Teleport to="body">
      <div v-if="open">
        <div class="fixed inset-0 z-[10000]" data-color-panel @click="close"></div>
        <div
          data-color-panel
          class="fixed z-[10001] bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 p-2.5"
          :style="{ ...panelPos, width: PANEL_WIDTH + 'px' }"
          @click.stop
        >
          <p class="px-1 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-surface-500">
            {{ panelTitle }}
          </p>
          <div class="grid grid-cols-6 gap-1.5">
            <button
              v-for="c in presets"
              :key="c"
              type="button"
              :title="c"
              @click="choose(c)"
              class="w-6 h-6 rounded-md border border-surface-200 dark:border-surface-600 hover:scale-110 transition-transform"
              :style="{ backgroundColor: c }"
            ></button>
          </div>

          <div class="flex items-center gap-2 mt-2.5 pt-2.5 border-t border-surface-100 dark:border-surface-700">
            <label class="flex items-center gap-1.5 text-xs text-surface-600 dark:text-surface-300 cursor-pointer">
              <input
                type="color"
                :value="customColor"
                @input="onCustomInput"
                class="w-6 h-6 p-0 border border-surface-200 dark:border-surface-600 rounded-md cursor-pointer bg-transparent"
              />
              Custom
            </label>
            <button
              type="button"
              @click="choose(null)"
              class="ml-auto flex items-center gap-1 px-2 py-1 rounded-md text-xs text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">format_color_reset</span>
              {{ removeLabel }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
