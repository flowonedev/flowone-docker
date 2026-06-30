<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useWeatherStore } from '@/stores/weather'
import { iconUrlFor, labelFor } from '@/utils/weatherIcons'
import WeatherForecastPopover from '@/components/shared/WeatherForecastPopover.vue'

const weather = useWeatherStore()
const open = ref(false)

const iconSrc = computed(() => iconUrlFor(weather.weatherCode, weather.isDay))
const temperatureText = computed(() => {
  const t = weather.temperature
  if (t === null || t === undefined) return ''
  return `${Math.round(t)}°`
})

const tooltip = computed(() => {
  if (!weather.available) return 'Weather unavailable'
  const condition = labelFor(weather.weatherCode)
  const city = weather.city || ''
  const t = weather.temperature !== null && weather.temperature !== undefined
    ? ` (${Math.round(weather.temperature)}°C)`
    : ''
  return city ? `${city} — ${condition}${t}` : `${condition}${t}`
})

const showChip = computed(() => weather.current !== null)

function toggle() {
  if (!weather.available) return
  open.value = !open.value
}

function onKeydown(e) {
  if (e.key === 'Escape' && open.value) {
    open.value = false
  }
}

onMounted(() => {
  weather.fetch()
  window.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div v-if="showChip" class="relative">
    <button
      type="button"
      class="weather-chip flex items-center gap-1 px-1.5 h-8 rounded-lg text-surface-700 dark:text-surface-200 select-none"
      :class="[
        { 'opacity-60': !weather.available },
        { 'bg-surface-100 dark:bg-surface-700': open },
      ]"
      :title="tooltip"
      :aria-expanded="open"
      :aria-haspopup="weather.available ? 'dialog' : undefined"
      :disabled="!weather.available"
      @click="toggle"
    >
      <img
        v-if="iconSrc"
        :src="iconSrc"
        alt=""
        aria-hidden="true"
        class="weather-icon w-7 h-7 shrink-0"
        draggable="false"
      />
      <span
        v-if="temperatureText"
        class="text-sm font-medium tabular-nums leading-none"
      >{{ temperatureText }}</span>
    </button>

    <!-- Click-outside backdrop + popover -->
    <template v-if="open">
      <div class="fixed inset-0 z-[9998]" @click="open = false"></div>
      <WeatherForecastPopover />
    </template>
  </div>
</template>

<style scoped>
.weather-chip {
  transition: background-color 0.15s ease;
}
.weather-chip:hover:not(:disabled) {
  background-color: rgb(var(--color-surface-100, 245 245 244) / 0.6);
}
:global(.dark) .weather-chip:hover:not(:disabled) {
  background-color: rgb(var(--color-surface-700, 68 64 60) / 0.6);
}
.weather-chip:disabled {
  cursor: default;
}
.weather-icon {
  display: block;
}
</style>
