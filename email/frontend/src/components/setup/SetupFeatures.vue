<script setup>
import { useI18n } from 'vue-i18n'

const props = defineProps({
  addons: { type: Object, required: true },
  isTeam: Boolean,
  isDark: Boolean,
  headingCls: String,
  subCls: String,
  newsReaderRegion: { type: String, default: 'HU' },
})

const emit = defineEmits(['toggle', 'setNewsReaderRegion'])

const { t } = useI18n()

const modules = [
  { slug: 'kanban_boards', icon: 'view_kanban' },
  { slug: 'board_pro', icon: 'analytics' },
  { slug: 'project_hub', icon: 'hub' },
  { slug: 'crm_pro', icon: 'business_center' },
  { slug: 'chat', icon: 'chat' },
  { slug: 'team', icon: 'diversity_3' },
  { slug: 'automation_hub', icon: 'settings_suggest' },
  { slug: 'email_marketing', icon: 'campaign' },
  { slug: 'moodboards', icon: 'dashboard_customize' },
  { slug: 'news_reader', icon: 'newspaper' },
]

function si(index) {
  return { style: `animation-delay: ${index * 60 + 80}ms` }
}

function optionCls(active) {
  if (active) return props.isDark
    ? 'border-white/20 bg-white/[0.08]'
    : 'border-primary-300 bg-primary-50/60'
  return props.isDark
    ? 'border-white/[0.05] bg-white/[0.02] hover:bg-white/[0.05] hover:border-white/10'
    : 'border-surface-200 bg-surface-50/50 hover:bg-surface-100/60 hover:border-surface-300'
}
</script>

<template>
  <div class="flex-1 flex flex-col px-4 py-5 sm:px-8 sm:py-8">
    <div class="text-center mb-4 sm:mb-6">
      <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">
        {{ t('setupWizard.featuresTitle') }}
      </h2>
      <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">
        {{ t('setupWizard.featuresSubtitle') }}
      </p>
    </div>

    <div class="w-full max-w-md mx-auto space-y-2 sm:space-y-2.5 overflow-y-auto flex-1 min-h-0">
      <button
        v-for="(m, mi) in modules"
        :key="m.slug"
        @click="emit('toggle', m.slug)"
        class="si w-full flex items-center justify-between px-3 py-2.5 sm:px-4 sm:py-3 rounded-xl border transition-all duration-300 hover:-translate-y-0.5"
        :class="[optionCls(addons[m.slug])]"
        v-bind="si(mi + 2)"
      >
        <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
          <span
            class="material-symbols-rounded text-lg shrink-0"
            :class="addons[m.slug] ? 'text-primary-500' : (isDark ? 'text-surface-500' : 'text-surface-400')"
          >{{ m.icon }}</span>
          <div class="text-left min-w-0">
            <span class="block text-[13px] sm:text-sm font-medium leading-tight" :class="headingCls">
              {{ t(`setupWizard.mod_${m.slug}`) }}
            </span>
            <span class="block text-[10px] sm:text-[11px] leading-snug mt-0.5" :class="subCls">
              {{ t(`setupWizard.mod_${m.slug}_desc`) }}
            </span>
          </div>
        </div>

        <div
          class="relative w-10 h-5.5 rounded-full transition-colors duration-300 shrink-0 ml-3"
          :class="addons[m.slug] ? 'bg-primary-500' : (isDark ? 'bg-surface-600' : 'bg-surface-300')"
          style="width: 2.5rem; height: 1.375rem;"
        >
          <div
            class="absolute top-0.5 w-4.5 h-4.5 rounded-full bg-white shadow-sm transition-transform duration-300"
            style="width: 1.125rem; height: 1.125rem;"
            :class="addons[m.slug] ? 'translate-x-[18px]' : 'translate-x-0.5'"
          ></div>
        </div>
      </button>
    </div>

    <div
      v-if="addons.news_reader"
      class="w-full max-w-md mx-auto mt-4 pt-4 border-t border-surface-200/50 dark:border-white/10"
    >
      <p class="text-xs mb-2 text-center" :class="subCls">{{ t('setupWizard.newsReaderRegionHint') }}</p>
      <div class="flex gap-2 justify-center flex-wrap">
        <button
          v-for="r in ['HU', 'EN', 'US']"
          :key="r"
          type="button"
          class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors"
          :class="props.newsReaderRegion === r ? (isDark ? 'border-primary-400 bg-primary-500/20 text-primary-300' : 'border-primary-400 bg-primary-50 text-primary-700') : (isDark ? 'border-white/10 bg-white/[0.04] text-surface-300' : 'border-surface-200 bg-surface-50 text-surface-600')"
          @click="emit('setNewsReaderRegion', r)"
        >{{ r }}</button>
      </div>
    </div>

    <p class="si text-center text-[10px] sm:text-[11px] mt-3 shrink-0" :class="isDark ? 'text-surface-500' : 'text-surface-400'" v-bind="si(12)">
      {{ t('setupWizard.featuresHint') }}
    </p>
  </div>
</template>

<style scoped>
.si {
  opacity: 0;
  animation: revealUp 0.55s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes revealUp {
  from { opacity: 0; transform: translateY(22px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
</style>
