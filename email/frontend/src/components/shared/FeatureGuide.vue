<script setup>
/**
 * FeatureGuide - Reusable collapsible panel showing module features by addon tier.
 * 
 * All visible strings come from i18n via translation keys in the guide data.
 * The component resolves every *Key prop through t() before rendering.
 */
import { useI18n } from 'vue-i18n'
import { useAddons } from '@/composables/useAddons'

const { t } = useI18n()
const addons = useAddons()

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  /** i18n key for panel title */
  titleKey: { type: String, required: true },
  /** i18n key for footer text */
  footerKey: { type: String, required: true },
  /** i18n key for the intelligence layer name (optional) */
  layerKey: { type: String, default: '' },
  /** Google Material icon for the intelligence layer (optional) */
  layerIcon: { type: String, default: '' },
  /** 
   * Array of tier objects with i18n keys:
   * { 
   *   nameKey, descriptionKey, addonKey, color,
   *   sections: [{ labelKey, featureKeys: [...] }]
   * }
   */
  tiers: { type: Array, required: true },
  /**
   * Cross-module integrations with i18n keys:
   * [{ icon, moduleKey, descriptionKey }]
   */
  integrations: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue'])

function close() {
  emit('update:modelValue', false)
}

const colorMap = {
  primary: {
    active: 'border-primary-300 dark:border-primary-700 bg-primary-50/50 dark:bg-primary-900/10',
    badge: 'bg-primary-500',
    number: 'bg-primary-500',
  },
  blue: {
    active: 'border-blue-300 dark:border-blue-700 bg-blue-50/50 dark:bg-blue-900/10',
    badge: 'bg-blue-500',
    number: 'bg-blue-500',
  },
  emerald: {
    active: 'border-emerald-300 dark:border-emerald-700 bg-emerald-50/50 dark:bg-emerald-900/10',
    badge: 'bg-emerald-500',
    number: 'bg-emerald-500',
  },
  amber: {
    active: 'border-amber-300 dark:border-amber-700 bg-amber-50/50 dark:bg-amber-900/10',
    badge: 'bg-amber-500',
    number: 'bg-amber-500',
  },
  purple: {
    active: 'border-purple-300 dark:border-purple-700 bg-purple-50/50 dark:bg-purple-900/10',
    badge: 'bg-purple-500',
    number: 'bg-purple-500',
  },
  rose: {
    active: 'border-rose-300 dark:border-rose-700 bg-rose-50/50 dark:bg-rose-900/10',
    badge: 'bg-rose-500',
    number: 'bg-rose-500',
  },
}

const inactiveStyle = 'border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50'

function isActive(tier) {
  if (!tier.addonKey) return true
  return !!addons[tier.addonKey]?.value
}

function getTierStyle(tier) {
  return isActive(tier) ? (colorMap[tier.color]?.active || colorMap.primary.active) : inactiveStyle
}

function getNumberStyle(tier) {
  return isActive(tier) ? (colorMap[tier.color]?.number || colorMap.primary.number) : 'bg-surface-400 dark:bg-surface-600'
}

function gridCols() {
  const len = props.tiers.length
  if (len === 1) return 'grid-cols-1'
  if (len === 2) return 'grid-cols-1 md:grid-cols-2'
  return 'grid-cols-1 md:grid-cols-3'
}
</script>

<template>
  <Teleport to="body">
    <Transition name="feature-guide">
      <div v-if="modelValue" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="close">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[85vh] overflow-y-auto">
          <div class="px-6 py-5">
            <!-- Header -->
            <div class="flex items-center justify-between mb-5">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg text-primary-500">layers</span>
                <h3 class="text-base font-semibold text-surface-800 dark:text-surface-200">{{ t(titleKey) }}</h3>
                <span
                  v-if="layerKey"
                  class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400"
                >
                  <span class="material-symbols-rounded" style="font-size: 12px">{{ layerIcon }}</span>
                  {{ t(layerKey) }}
                </span>
              </div>
              <button 
                @click="close"
                class="p-1.5 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              >
                <span class="material-symbols-rounded text-lg">close</span>
              </button>
            </div>

            <!-- Tiers Grid -->
            <div class="grid gap-3" :class="gridCols()">
              <div 
                v-for="(tier, idx) in tiers"
                :key="tier.nameKey"
                class="rounded-xl border-2 p-4 transition-colors"
                :class="getTierStyle(tier)"
              >
                <!-- Tier Header -->
                <div class="flex items-center gap-2 mb-3">
                  <span 
                    class="flex items-center justify-center w-6 h-6 rounded-full text-white text-xs font-bold"
                    :class="getNumberStyle(tier)"
                  >{{ idx + 1 }}</span>
                  <span class="text-sm font-semibold text-surface-800 dark:text-surface-200">{{ t(tier.nameKey) }}</span>
                  <span 
                    v-if="isActive(tier)" 
                    class="ml-auto px-2 py-0.5 text-[10px] font-semibold rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400"
                  >{{ t('featureGuide.active') }}</span>
                  <span 
                    v-else 
                    class="ml-auto px-2 py-0.5 text-[10px] font-semibold rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400"
                  >{{ t('featureGuide.available') }}</span>
                </div>

                <!-- Description -->
                <p class="text-xs text-surface-500 dark:text-surface-400 mb-3">{{ t(tier.descriptionKey) }}</p>

                <!-- Feature Sections -->
                <div class="space-y-2">
                  <template v-for="section in tier.sections" :key="section.labelKey">
                    <h4 
                      v-if="section.labelKey"
                      class="text-[10px] font-semibold uppercase tracking-wider mt-1 mb-1"
                      :class="isActive(tier) ? 'text-surface-400 dark:text-surface-500' : 'text-surface-300 dark:text-surface-600'"
                    >{{ t(section.labelKey) }}</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1">
                      <div 
                        v-for="featureKey in section.featureKeys" 
                        :key="featureKey"
                        class="flex items-start gap-1.5 text-xs"
                        :class="isActive(tier) ? 'text-surface-600 dark:text-surface-400' : 'text-surface-400 dark:text-surface-500'"
                      >
                        <span 
                          class="material-symbols-rounded text-sm mt-px shrink-0"
                          :class="isActive(tier) ? 'text-green-500' : 'text-surface-300 dark:text-surface-600'"
                        >{{ isActive(tier) ? 'check_circle' : 'radio_button_unchecked' }}</span>
                        {{ t(featureKey) }}
                      </div>
                    </div>
                  </template>
                </div>
              </div>
            </div>

            <!-- Cross-Module Integrations -->
            <div v-if="integrations.length > 0" class="mt-4 pt-3 border-t border-surface-100 dark:border-surface-700">
              <h4 class="text-xs font-semibold text-surface-600 dark:text-surface-400 mb-2 flex items-center gap-1.5">
                <span class="material-symbols-rounded text-sm text-primary-500">hub</span>
                {{ t('featureGuide.worksWithOtherModules') }}
              </h4>
              <div class="flex flex-wrap gap-2">
                <div 
                  v-for="int in integrations" 
                  :key="int.moduleKey"
                  class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-surface-50 dark:bg-surface-700/50 text-xs text-surface-600 dark:text-surface-400"
                >
                  <span class="material-symbols-rounded text-sm text-surface-400">{{ int.icon }}</span>
                  <span class="font-medium">{{ t(int.moduleKey) }}</span>
                  <span class="text-surface-400 dark:text-surface-500">&mdash;</span>
                  <span>{{ t(int.descriptionKey) }}</span>
                </div>
              </div>
            </div>

            <!-- Footer -->
            <p class="mt-3 text-[11px] text-surface-400 dark:text-surface-500 flex items-center gap-1">
              <span class="material-symbols-rounded text-xs">info</span>
              {{ t(footerKey) }}
            </p>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.feature-guide-enter-active,
.feature-guide-leave-active {
  transition: opacity 0.2s ease;
}
.feature-guide-enter-from,
.feature-guide-leave-to {
  opacity: 0;
}
</style>
