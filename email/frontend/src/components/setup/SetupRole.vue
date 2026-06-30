<script setup>
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: String, default: '' },
  isDark: Boolean,
  headingCls: String,
  subCls: String,
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const roles = [
  { id: 'business_owner', icon: 'storefront', gradient: 'from-purple-500 via-violet-500 to-indigo-500', glowColor: 'rgba(168, 85, 247, 0.4)' },
  { id: 'admin', icon: 'admin_panel_settings', gradient: 'from-red-500 via-rose-500 to-pink-500', glowColor: 'rgba(239, 68, 68, 0.4)' },
  { id: 'project_manager', icon: 'assignment_ind', gradient: 'from-emerald-500 via-teal-500 to-cyan-500', glowColor: 'rgba(20, 184, 166, 0.4)' },
  { id: 'team_member', icon: 'badge', gradient: 'from-blue-500 via-sky-500 to-cyan-500', glowColor: 'rgba(59, 130, 246, 0.4)' },
]

function si(index) {
  return { style: `animation-delay: ${index * 80 + 80}ms` }
}

function optionCls(active) {
  if (active) return props.isDark
    ? 'border-white/20 bg-white/[0.08] scale-[1.03]'
    : 'border-primary-300 bg-primary-50/60 scale-[1.03]'
  return props.isDark
    ? 'border-white/[0.05] bg-white/[0.02] hover:bg-white/[0.05] hover:border-white/10'
    : 'border-surface-200 bg-surface-50/50 hover:bg-surface-100/60 hover:border-surface-300'
}
</script>

<template>
  <div class="flex-1 flex flex-col px-4 py-6 sm:px-8 sm:py-10">
    <div class="text-center mb-5 sm:mb-8">
      <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">
        {{ t('setupWizard.roleTitle') }}
      </h2>
      <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">
        {{ t('setupWizard.roleSubtitle') }}
      </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4 max-w-lg mx-auto w-full flex-1 content-center">
      <button
        v-for="(r, ri) in roles"
        :key="r.id"
        @click="emit('update:modelValue', r.id)"
        class="si group relative flex items-center gap-3 sm:gap-4 p-3.5 sm:p-5 rounded-2xl border transition-all duration-300 text-left hover:-translate-y-1"
        :class="[optionCls(modelValue === r.id)]"
        v-bind="si(ri + 2)"
      >
        <div
          class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-gradient-to-br flex items-center justify-center shrink-0 transition-all duration-300"
          :class="[r.gradient, modelValue === r.id ? 'shadow-lg' : 'opacity-70 group-hover:opacity-100']"
          :style="modelValue === r.id ? { boxShadow: `0 8px 30px ${r.glowColor}` } : {}"
        >
          <span class="material-symbols-rounded text-white text-lg sm:text-xl">{{ r.icon }}</span>
        </div>

        <div class="flex-1 min-w-0">
          <span class="text-sm font-semibold block" :class="isDark ? 'text-white' : 'text-surface-800'">
            {{ t(`setupWizard.role_${r.id}`) }}
          </span>
          <span class="text-[11px] sm:text-xs" :class="isDark ? 'text-surface-400' : 'text-surface-500'">
            {{ t(`setupWizard.role_${r.id}_desc`) }}
          </span>
        </div>

        <div
          v-if="modelValue === r.id"
          class="absolute top-2.5 right-2.5 sm:top-3 sm:right-3 w-5 h-5 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center"
        >
          <span class="material-symbols-rounded text-white text-xs">check</span>
        </div>
      </button>
    </div>
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
