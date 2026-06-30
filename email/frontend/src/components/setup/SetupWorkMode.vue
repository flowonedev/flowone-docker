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

const modes = [
  {
    id: 'solo',
    icon: 'person',
    gradient: 'from-blue-500 via-sky-500 to-cyan-500',
    glowColor: 'rgba(59, 130, 246, 0.4)',
  },
  {
    id: 'team',
    icon: 'groups',
    gradient: 'from-emerald-500 via-teal-500 to-cyan-500',
    glowColor: 'rgba(20, 184, 166, 0.4)',
  },
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
        {{ t('setupWizard.workModeTitle') }}
      </h2>
      <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">
        {{ t('setupWizard.workModeSubtitle') }}
      </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 max-w-lg mx-auto w-full flex-1 content-center">
      <button
        v-for="(m, mi) in modes"
        :key="m.id"
        @click="emit('update:modelValue', m.id)"
        class="si group relative flex flex-col items-center gap-3 sm:gap-4 p-5 sm:p-8 rounded-2xl border transition-all duration-300 text-center hover:-translate-y-1"
        :class="[optionCls(modelValue === m.id)]"
        v-bind="si(mi + 2)"
      >
        <div
          class="w-14 h-14 sm:w-18 sm:h-18 rounded-xl bg-gradient-to-br flex items-center justify-center transition-all duration-300"
          :class="[m.gradient, modelValue === m.id ? 'shadow-lg' : 'opacity-70 group-hover:opacity-100']"
          :style="modelValue === m.id ? { boxShadow: `0 8px 30px ${m.glowColor}` } : {}"
        >
          <span class="material-symbols-rounded text-white text-2xl sm:text-3xl">{{ m.icon }}</span>
        </div>

        <div>
          <span class="text-sm sm:text-base font-semibold block mb-1" :class="isDark ? 'text-white' : 'text-surface-800'">
            {{ t(`setupWizard.workMode_${m.id}`) }}
          </span>
          <span class="text-xs sm:text-sm" :class="isDark ? 'text-surface-400' : 'text-surface-500'">
            {{ t(`setupWizard.workMode_${m.id}_desc`) }}
          </span>
        </div>

        <div
          v-if="modelValue === m.id"
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
.w-18 { width: 4.5rem; }
.h-18 { height: 4.5rem; }
</style>
