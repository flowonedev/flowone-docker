<template>
  <div class="relative inline-block" ref="rootRef">
    <!-- Trigger button -->
    <button
      @click.stop="toggle"
      class="p-1 rounded-lg hover:bg-primary-50 dark:hover:bg-primary-900/20 text-surface-400 hover:text-primary-500 transition-colors flex-shrink-0"
      :title="title"
    >
      <span class="material-symbols-rounded" style="font-size: 14px;">bookmark_add</span>
    </button>

    <!-- Popover -->
    <div
      v-if="open"
      class="absolute z-50 right-0 top-full mt-1 w-56 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl overflow-hidden"
      @click.stop
    >
      <!-- Preview -->
      <div
        class="h-20 w-full"
        :style="previewBg"
      />

      <!-- Form -->
      <div class="p-3 space-y-2">
        <div>
          <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Name</label>
          <input
            ref="nameInput"
            v-model="styleName"
            class="w-full text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 text-surface-700 dark:text-surface-300 mt-0.5 focus:outline-none focus:border-primary-400"
            :placeholder="namePlaceholder"
            @keydown.enter="onCreate"
            @keydown.escape="open = false"
          />
        </div>

        <button
          @click="onCreate"
          :disabled="!styleName.trim()"
          class="w-full py-1.5 text-[11px] font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg transition-colors"
        >
          Create style
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  type: { type: String, default: 'color' },
  color: { type: String, default: null },
  gradient: { type: Object, default: null },
  title: { type: String, default: 'Save to globals' },
  namePlaceholder: { type: String, default: 'Style name...' },
})

const emit = defineEmits(['save'])

const open = ref(false)
const styleName = ref('')
const nameInput = ref(null)
const rootRef = ref(null)

const previewBg = computed(() => {
  if (props.type === 'gradient' && props.gradient?.stops?.length) {
    const stops = props.gradient.stops.map(s => `${s.color} ${s.position}%`).join(', ')
    const type = props.gradient.type || 'linear'
    if (type === 'radial') return { background: `radial-gradient(circle, ${stops})` }
    return { background: `linear-gradient(${props.gradient.angle || 135}deg, ${stops})` }
  }
  return { backgroundColor: props.color || '#6366f1' }
})

function toggle() {
  open.value = !open.value
  if (open.value) {
    styleName.value = ''
    nextTick(() => nameInput.value?.focus())
  }
}

function onCreate() {
  if (!styleName.value.trim()) return
  emit('save', styleName.value.trim())
  open.value = false
  styleName.value = ''
}

function onClickOutside(e) {
  if (rootRef.value && !rootRef.value.contains(e.target)) {
    open.value = false
  }
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside))
</script>
