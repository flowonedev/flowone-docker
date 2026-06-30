<script setup>
/**
 * ViewInfoButton - Shows a small info icon that opens a popover
 * explaining what the current view does.
 * Distinct from HowItWorksButton which is for module-level feature guides.
 *
 * Usage: <ViewInfoButton view-key="boards" />
 * Reads title/description from boards.viewInfo.<viewKey>.title / .description
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  viewKey: { type: String, required: true },
})

const { t } = useI18n()
const infoTitle = computed(() => t(`viewInfo.${props.viewKey}.title`))
const infoDesc = computed(() => t(`viewInfo.${props.viewKey}.description`))

const show = ref(false)
const popoverRef = ref(null)
const buttonRef = ref(null)

function toggle() {
  show.value = !show.value
}

function handleClickOutside(e) {
  if (
    show.value &&
    popoverRef.value && !popoverRef.value.contains(e.target) &&
    buttonRef.value && !buttonRef.value.contains(e.target)
  ) {
    show.value = false
  }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))
</script>

<template>
  <div class="view-info-btn relative hidden sm:inline-flex">
    <button
      ref="buttonRef"
      @click.stop="toggle"
      class="flex items-center justify-center w-7 h-7 rounded-full transition-all duration-200"
      :class="show
        ? 'bg-primary-500 text-white shadow-md'
        : 'bg-primary-500/10 text-primary-500 hover:bg-primary-500 hover:text-white hover:shadow-md'"
    >
      <span class="material-symbols-rounded text-base">info</span>
    </button>

    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 translate-y-1"
    >
      <div
        v-if="show"
        ref="popoverRef"
        class="absolute left-0 top-full mt-2 w-72 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 p-4 z-50"
      >
        <div class="flex items-start gap-2.5">
          <span class="material-symbols-rounded text-lg text-primary-500 mt-0.5 shrink-0">info</span>
          <div>
            <p class="text-sm font-semibold text-surface-900 dark:text-white mb-1">{{ infoTitle }}</p>
            <p class="text-xs text-surface-500 dark:text-surface-400 leading-relaxed">{{ infoDesc }}</p>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
