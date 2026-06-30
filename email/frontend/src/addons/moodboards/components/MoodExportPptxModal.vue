<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="show"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
      >
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="$emit('close')" />

        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col max-h-[80vh]">
          <!-- Header -->
          <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2.5">
              <span class="material-symbols-rounded text-primary-500 text-xl">slideshow</span>
              <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Export PowerPoint</h3>
            </div>
            <button
              @click="$emit('close')"
              class="p-1.5 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
            >
              <span class="material-symbols-rounded text-lg">close</span>
            </button>
          </div>

          <!-- No slides message -->
          <div v-if="!slides.length" class="px-5 py-8 text-center">
            <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-2 block">layers_clear</span>
            <p class="text-sm text-surface-500 dark:text-surface-400">No slides found on this board.</p>
            <p class="text-xs text-surface-400 dark:text-surface-500 mt-1">The entire canvas will be exported as a single slide.</p>
          </div>

          <!-- Slide list -->
          <template v-else>
            <div class="px-5 pt-3 pb-2 flex items-center justify-between flex-shrink-0">
              <span class="text-xs text-surface-400 dark:text-surface-500 font-medium">
                {{ selected.size }} of {{ slides.length }} slides selected
              </span>
              <button
                @click="toggleAll"
                class="px-3 py-1 rounded-full text-[11px] font-medium transition-colors border"
                :class="allSelected
                  ? 'bg-primary-50 dark:bg-primary-900/20 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400'
                  : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
              >
                {{ allSelected ? 'Deselect All' : 'Select All' }}
              </button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 pb-3 space-y-1.5 min-h-0">
              <button
                v-for="slide in slides"
                :key="slide.id"
                @click="toggle(slide.id)"
                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl border transition-all text-left group"
                :class="selected.has(slide.id)
                  ? 'border-primary-400 dark:border-primary-600 bg-primary-50/60 dark:bg-primary-900/20'
                  : 'border-surface-200 dark:border-surface-700 bg-surface-50/50 dark:bg-surface-800/50 hover:border-surface-300 dark:hover:border-surface-600'"
              >
                <!-- Toggle indicator -->
                <div
                  class="w-5 h-5 rounded-full flex-shrink-0 flex items-center justify-center transition-colors"
                  :class="selected.has(slide.id)
                    ? 'bg-primary-500 text-white'
                    : 'bg-surface-200 dark:bg-surface-600 group-hover:bg-surface-300 dark:group-hover:bg-surface-500'"
                >
                  <span v-if="selected.has(slide.id)" class="material-symbols-rounded" style="font-size: 14px;">check</span>
                </div>

                <!-- Slide number -->
                <span
                  class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-xs font-bold flex-shrink-0"
                  :class="selected.has(slide.id)
                    ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300'
                    : 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400'"
                >
                  {{ (slide.slide_order ?? 0) + 1 }}
                </span>

                <!-- Slide title -->
                <span class="flex-1 text-sm font-medium truncate" :class="selected.has(slide.id) ? 'text-surface-800 dark:text-surface-100' : 'text-surface-600 dark:text-surface-400'">
                  {{ slide.title || 'Untitled Slide' }}
                </span>

                <!-- Dimensions hint -->
                <span class="text-[10px] text-surface-400 dark:text-surface-500 tabular-nums flex-shrink-0">
                  {{ Math.round(slide.width || 480) }}&times;{{ Math.round(slide.height || 270) }}
                </span>
              </button>
            </div>
          </template>

          <!-- Footer -->
          <div class="px-5 py-3 bg-surface-50 dark:bg-surface-900 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between flex-shrink-0">
            <button
              @click="$emit('close')"
              class="px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-full transition-colors"
            >
              Cancel
            </button>
            <div class="flex items-center gap-2">
              <button
                v-if="slides.length"
                @click="exportAll"
                class="px-4 py-2 text-sm font-medium text-surface-700 dark:text-surface-300 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-full transition-colors flex items-center gap-1.5"
              >
                <span class="material-symbols-rounded text-base">select_all</span>
                Export All
              </button>
              <button
                @click="exportSelected"
                :disabled="slides.length > 0 && selected.size === 0"
                class="px-5 py-2 text-sm font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-full transition-colors flex items-center gap-1.5"
              >
                <span class="material-symbols-rounded text-base">download</span>
                {{ slides.length === 0 ? 'Export' : `Export ${selected.size > 0 ? selected.size : ''} Selected` }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  show: { type: Boolean, default: false },
  slides: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'export'])

const selected = ref(new Set())

const allSelected = computed(() =>
  props.slides.length > 0 && selected.value.size === props.slides.length
)

watch(() => props.show, (val) => {
  if (val) {
    selected.value = new Set(props.slides.map(s => s.id))
  }
})

function toggle(id) {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}

function toggleAll() {
  if (allSelected.value) {
    selected.value = new Set()
  } else {
    selected.value = new Set(props.slides.map(s => s.id))
  }
}

function exportSelected() {
  if (props.slides.length === 0) {
    emit('export', null)
  } else {
    emit('export', [...selected.value])
  }
}

function exportAll() {
  emit('export', null)
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: all 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-from .relative,
.modal-leave-to .relative {
  transform: scale(0.95);
}
</style>
