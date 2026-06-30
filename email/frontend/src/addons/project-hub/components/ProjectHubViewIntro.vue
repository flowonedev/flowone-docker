<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  storageKey: { type: String, required: true },
  icon: { type: String, default: 'auto_awesome' },
  title: { type: String, required: true },
  summary: { type: String, required: true },
  sections: { type: Array, default: () => [] },
  benefits: { type: Array, default: () => [] },
  defaultExpanded: { type: Boolean, default: false },
  accent: { type: String, default: 'primary' },
})

const dismissed = ref(false)
const expanded = ref(props.defaultExpanded)

function loadState() {
  try {
    dismissed.value = localStorage.getItem(props.storageKey) === '1'
  } catch (_e) {
    dismissed.value = false
  }
}

function dismiss() {
  dismissed.value = true
  try { localStorage.setItem(props.storageKey, '1') } catch (_e) { /* ignore quota */ }
}

function reopen() {
  try { localStorage.removeItem(props.storageKey) } catch (_e) { /* ignore */ }
  dismissed.value = false
  expanded.value = true
}

loadState()

watch(() => props.storageKey, () => {
  loadState()
  expanded.value = props.defaultExpanded
})
</script>

<template>
  <div class="ph-view-intro mb-5">
    <div
      v-if="!dismissed"
      class="rounded-xl border border-primary-200/60 dark:border-primary-700/40 bg-gradient-to-br from-primary-50/70 via-white to-indigo-50/40 dark:from-primary-900/20 dark:via-surface-800 dark:to-indigo-900/10 overflow-hidden"
    >
      <div class="px-4 py-3 flex items-start gap-3">
        <div class="w-10 h-10 rounded-xl bg-primary-500/15 text-primary-600 dark:text-primary-300 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-xl">{{ icon }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">{{ title }}</h3>
            <span class="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-primary-500/15 text-primary-600 dark:text-primary-300">
              How to use this view
            </span>
          </div>
          <p class="text-xs text-surface-600 dark:text-surface-300 mt-0.5 leading-relaxed">{{ summary }}</p>

          <button
            type="button"
            class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary-600 dark:text-primary-300 hover:text-primary-700 dark:hover:text-primary-200 transition-colors"
            @click="expanded = !expanded"
          >
            <span class="material-symbols-rounded text-sm">{{ expanded ? 'expand_less' : 'expand_more' }}</span>
            {{ expanded ? 'Hide details' : 'What you can do here & how it pays off' }}
          </button>

          <div v-if="expanded" class="mt-3 space-y-4">

            <!-- Detail sections (capability cards) -->
            <div v-for="(section, sIdx) in sections" :key="sIdx" class="space-y-2">
              <h4
                v-if="section.heading"
                class="text-[11px] font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400"
              >
                {{ section.heading }}
              </h4>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2.5 text-xs">
                <div v-for="(item, iIdx) in section.items" :key="iIdx" class="flex items-start gap-2">
                  <span class="material-symbols-rounded text-base text-primary-500 mt-0.5 flex-shrink-0">{{ item.icon || 'check_circle' }}</span>
                  <div class="min-w-0">
                    <div class="font-medium text-surface-800 dark:text-surface-200">{{ item.title }}</div>
                    <div class="text-surface-500 dark:text-surface-400 leading-relaxed">{{ item.body }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Real benefits callout -->
            <div
              v-if="benefits && benefits.length"
              class="rounded-lg border border-emerald-200/60 dark:border-emerald-700/40 bg-emerald-50/60 dark:bg-emerald-900/15 px-3 py-2.5"
            >
              <div class="flex items-center gap-1.5 mb-1.5">
                <span class="material-symbols-rounded text-base text-emerald-600 dark:text-emerald-300">savings</span>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Real benefit</span>
              </div>
              <ul class="space-y-1 text-xs text-surface-700 dark:text-surface-200 leading-relaxed">
                <li v-for="(b, bIdx) in benefits" :key="bIdx" class="flex items-start gap-1.5">
                  <span class="material-symbols-rounded text-sm text-emerald-500 mt-0.5 flex-shrink-0">arrow_right</span>
                  <span v-html="b"></span>
                </li>
              </ul>
            </div>
          </div>
        </div>
        <button
          type="button"
          class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-surface-400 hover:text-surface-700 dark:hover:text-surface-100 hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors"
          title="Dismiss"
          aria-label="Dismiss"
          @click="dismiss"
        >
          <span class="material-symbols-rounded text-base">close</span>
        </button>
      </div>
    </div>

    <!-- Bring-back link once dismissed -->
    <div v-else class="flex items-center justify-end">
      <button
        type="button"
        class="inline-flex items-center gap-1 text-[11px] text-surface-400 hover:text-primary-500 dark:hover:text-primary-300 transition-colors"
        @click="reopen"
      >
        <span class="material-symbols-rounded text-sm">help_outline</span>
        Show how to use this view
      </button>
    </div>
  </div>
</template>
