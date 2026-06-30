<script setup>
import { ref, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'

const emit = defineEmits(['create'])

const { t } = useI18n()

const expanded = ref(false)
const title = ref('')
const inputRef = ref(null)

async function expand() {
  expanded.value = true
  title.value = ''
  await nextTick()
  inputRef.value?.focus()
}

function collapse() {
  expanded.value = false
  title.value = ''
}

function submit() {
  const value = title.value.trim()
  if (!value) {
    collapse()
    return
  }
  emit('create', { title: value })
  title.value = ''
  // Keep input open for rapid capture; user closes via Esc / blur.
  nextTick(() => inputRef.value?.focus())
}

function onKey(e) {
  if (e.key === 'Enter') {
    e.preventDefault()
    submit()
  } else if (e.key === 'Escape') {
    collapse()
  }
}

function onBlur() {
  if (!title.value.trim()) collapse()
}
</script>

<template>
  <div class="px-4 pt-2 pb-3">
    <Transition name="quick-add" mode="out-in">
      <button
        v-if="!expanded"
        key="resting"
        type="button"
        class="group w-full text-left rounded-2xl border border-dashed border-surface-300 dark:border-surface-600 bg-surface-50/60 dark:bg-surface-800/40 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors pl-4 pr-3 py-3 flex items-center gap-3"
        @click="expand"
      >
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-surface-900 dark:text-surface-100">
            {{ t('tasksPanel.quickAdd.title') }}
          </p>
          <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5 flex items-center gap-1">
            {{ t('tasksPanel.quickAdd.subtitle') }}
            <span class="material-symbols-rounded text-[14px] text-primary-400 opacity-70">auto_awesome</span>
          </p>
        </div>
        <span
          class="w-9 h-9 rounded-full bg-primary-500 group-hover:bg-primary-600 text-white shadow-sm shadow-primary-500/30 flex items-center justify-center shrink-0 transition-colors"
          :aria-label="t('tasksPanel.quickAdd.fabAria')"
        >
          <span class="material-symbols-rounded text-xl">add</span>
        </span>
      </button>

      <div
        v-else
        key="active"
        class="flex items-center gap-2 rounded-2xl border border-primary-500/40 bg-white dark:bg-surface-800 pl-4 pr-2 py-2.5 shadow-sm"
      >
        <span class="material-symbols-rounded text-base text-primary-500 shrink-0">add_task</span>
        <input
          ref="inputRef"
          v-model="title"
          type="text"
          :placeholder="t('tasksPanel.quickAdd.placeholder')"
          class="flex-1 bg-transparent text-sm outline-none text-surface-900 dark:text-surface-100 placeholder:text-surface-400"
          @keydown="onKey"
          @blur="onBlur"
        />
        <button
          type="button"
          class="w-9 h-9 rounded-full bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/40 text-white flex items-center justify-center shrink-0 transition-colors"
          :disabled="!title.trim()"
          :aria-label="t('tasksPanel.quickAdd.add')"
          @mousedown.prevent="submit"
        >
          <span class="material-symbols-rounded text-xl">add</span>
        </button>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.quick-add-enter-active,
.quick-add-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.quick-add-enter-from,
.quick-add-leave-to {
  opacity: 0;
  transform: translateY(4px);
}
</style>
