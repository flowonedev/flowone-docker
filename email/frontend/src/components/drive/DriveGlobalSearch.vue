<script setup>
import { useI18n } from 'vue-i18n'

defineProps({
  searchQuery: { type: String, default: '' },
  activeFilterCount: { type: Number, default: 0 },
})

const emit = defineEmits([
  'update:searchQuery',
  'toggle-filters',
  'clear-search',
])

const { t } = useI18n()

function onInput(e) {
  emit('update:searchQuery', e.target.value)
}
</script>

<template>
  <!-- Explorer-style search field: rectangular, bordered, magnifier on the right.
       Width is controlled by the parent (no w-full here, it would override it). -->
  <div id="drive-global-search-bar" class="relative">
    <input
      :value="searchQuery"
      type="text"
      name="drive-search"
      autocomplete="off"
      autocorrect="off"
      autocapitalize="off"
      spellcheck="false"
      data-1p-ignore
      data-lpignore="true"
      data-form-type="other"
      class="w-full h-9 pl-3 pr-[4.5rem] rounded-lg bg-white dark:bg-surface-800/60 border border-surface-200 dark:border-surface-700 text-sm text-surface-800 dark:text-surface-100 placeholder:text-surface-400 dark:placeholder:text-surface-500 focus:outline-none focus:border-primary-400 dark:focus:border-primary-500 transition-colors"
      :placeholder="t('driveView.searchInDrive')"
      @input="onInput"
    />
    <div class="absolute right-1 top-1/2 -translate-y-1/2 flex items-center gap-0.5">
      <button
        v-if="searchQuery"
        type="button"
        @click="emit('clear-search')"
        class="p-1 rounded-md hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500"
        :title="t('driveView.clearAll')"
      >
        <span class="material-symbols-rounded text-base">close</span>
      </button>
      <button
        type="button"
        @click="emit('toggle-filters')"
        :class="[
          'relative p-1 rounded-md transition-colors',
          activeFilterCount > 0
            ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-300'
            : 'text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 hover:text-surface-600 dark:hover:text-surface-300'
        ]"
        :title="t('driveView.filterFiles') || 'Filter'"
      >
        <span class="material-symbols-rounded text-base">tune</span>
        <span
          v-if="activeFilterCount > 0"
          class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold rounded-full bg-primary-500 text-white"
        >{{ activeFilterCount }}</span>
      </button>
      <span class="material-symbols-rounded text-surface-400 dark:text-surface-500 text-lg pr-1 pointer-events-none">search</span>
    </div>
  </div>
</template>
