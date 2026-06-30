<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  id: { type: String, required: true },
  label: { type: String, required: true },
  icon: { type: String, default: 'list' },
  count: { type: Number, default: 0 },
  // Visual color of the icon + count pill: neutral | danger | warning | success | info
  tone: { type: String, default: 'neutral' },
  defaultOpen: { type: Boolean, default: false },
  hideWhenEmpty: { type: Boolean, default: false }
})

const STORAGE_KEY = 'todo_section_open'

function loadOpenState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const map = JSON.parse(raw)
    return map[props.id]
  } catch {
    return null
  }
}

function persistOpenState(value) {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    const map = raw ? JSON.parse(raw) : {}
    map[props.id] = value
    localStorage.setItem(STORAGE_KEY, JSON.stringify(map))
  } catch {
    // localStorage may be unavailable (private mode, quota); ignore.
  }
}

const persisted = loadOpenState()
const open = ref(persisted === null ? props.defaultOpen : persisted)

watch(open, (v) => persistOpenState(v))

const toneClasses = computed(() => {
  switch (props.tone) {
    case 'danger':
      return { icon: 'text-red-500', pill: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400' }
    case 'warning':
      return { icon: 'text-amber-500', pill: 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400' }
    case 'success':
      return { icon: 'text-emerald-500', pill: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400' }
    case 'info':
      return { icon: 'text-primary-500', pill: 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400' }
    default:
      return { icon: 'text-surface-500', pill: 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300' }
  }
})

const hidden = computed(() => props.hideWhenEmpty && props.count === 0)
</script>

<template>
  <section v-if="!hidden" class="px-2">
    <div
      class="w-full flex items-center gap-2 px-2 py-2.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors cursor-pointer"
      @click="open = !open"
    >
      <span class="material-symbols-rounded text-base" :class="toneClasses.icon">{{ icon }}</span>
      <span class="text-xs font-semibold tracking-wide uppercase text-surface-700 dark:text-surface-300">
        {{ label }}
      </span>
      <span
        class="ml-1 min-w-[1.25rem] px-1.5 py-0.5 text-[10px] font-semibold rounded-full text-center"
        :class="toneClasses.pill"
      >
        {{ count }}
      </span>
      <span class="flex-1"></span>
      <div v-if="$slots.action && open" class="flex items-center" @click.stop>
        <slot name="action" />
      </div>
      <span class="material-symbols-rounded text-base text-surface-400 transition-transform" :class="{ '-rotate-90': !open }">
        expand_more
      </span>
    </div>

    <Transition name="slide-fade">
      <div v-if="open" class="pt-1 pb-2 space-y-2">
        <slot />
      </div>
    </Transition>
  </section>
</template>

<style scoped>
.slide-fade-enter-active,
.slide-fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
  overflow: hidden;
}
.slide-fade-enter-from,
.slide-fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
