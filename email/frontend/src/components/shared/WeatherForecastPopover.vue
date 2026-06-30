<script setup>
import { computed } from 'vue'
import { useWeatherStore } from '@/stores/weather'
import { iconUrlFor, labelFor } from '@/utils/weatherIcons'

const weather = useWeatherStore()

const currentIcon = computed(() => iconUrlFor(weather.weatherCode, weather.isDay))
const currentLabel = computed(() => labelFor(weather.weatherCode))

const currentTemp = computed(() => {
  const t = weather.temperature
  return t === null || t === undefined ? '—' : `${Math.round(t)}°`
})

const hiLo = computed(() => {
  const hi = weather.todayHi
  const lo = weather.todayLo
  if (hi === null && lo === null) return ''
  const hiStr = hi !== null ? `H:${Math.round(hi)}°` : ''
  const loStr = lo !== null ? `L:${Math.round(lo)}°` : ''
  return [hiStr, loStr].filter(Boolean).join('  ')
})

// Skip today (index 0) — it's already shown big at the top
const upcomingDays = computed(() => weather.forecast.slice(1))

function dayName(iso) {
  // iso = YYYY-MM-DD; Date treats it as UTC midnight, which is fine for weekday names
  const d = new Date(iso + 'T00:00:00')
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString(undefined, { weekday: 'short' })
}

function iconForDay(code) {
  return iconUrlFor(code, 1) // day variant in the daily list
}
</script>

<template>
  <div
    class="weather-popover absolute right-0 top-full mt-2 w-[420px] rounded-[10px] bg-white dark:bg-surface-800 shadow-xl border border-surface-200 dark:border-surface-700 overflow-hidden z-[9999]"
    role="dialog"
    aria-label="Weather forecast"
  >
    <!-- Header: current conditions -->
    <div class="px-4 pt-4 pb-3 bg-gradient-to-b from-sky-50 to-white dark:from-surface-700/40 dark:to-surface-800">
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0 flex items-center gap-3">
          <p class="text-4xl font-light text-surface-900 dark:text-surface-100 tabular-nums leading-none">
            {{ currentTemp }}
          </p>
          <div class="min-w-0">
            <p class="text-xs font-medium text-surface-500 dark:text-surface-400 uppercase tracking-wide truncate">
              {{ weather.city || 'Current location' }}
            </p>
            <p class="text-sm text-surface-600 dark:text-surface-300">{{ currentLabel }}</p>
            <p v-if="hiLo" class="text-xs text-surface-500 dark:text-surface-400 tabular-nums">{{ hiLo }}</p>
          </div>
        </div>
        <img
          v-if="currentIcon"
          :src="currentIcon"
          alt=""
          aria-hidden="true"
          class="w-14 h-14 shrink-0"
          draggable="false"
        />
      </div>
    </div>

    <!-- 6-day forecast (skipping today) — horizontal row -->
    <div v-if="upcomingDays.length" class="flex items-stretch justify-between gap-1 px-2 py-2">
      <div
        v-for="day in upcomingDays"
        :key="day.date"
        class="flex-1 flex flex-col items-center gap-1 px-1 py-1.5 rounded-[8px]"
      >
        <span class="text-xs font-medium text-surface-700 dark:text-surface-200">
          {{ dayName(day.date) }}
        </span>
        <img
          :src="iconForDay(day.weather_code)"
          alt=""
          aria-hidden="true"
          class="w-7 h-7"
          draggable="false"
        />
        <div class="flex flex-col items-center text-xs tabular-nums leading-tight">
          <span class="text-surface-900 dark:text-surface-100 font-medium">
            {{ day.max !== null ? `${Math.round(day.max)}°` : '—' }}
          </span>
          <span class="text-surface-500 dark:text-surface-400">
            {{ day.min !== null ? `${Math.round(day.min)}°` : '—' }}
          </span>
        </div>
      </div>
    </div>
    <div v-else class="px-4 py-3 text-xs text-surface-500 dark:text-surface-400">
      Forecast not available yet.
    </div>

    <!-- Stale notice -->
    <div
      v-if="weather.current?.stale"
      class="px-4 py-2 text-[11px] text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border-t border-amber-200 dark:border-amber-500/20"
    >
      Showing last cached reading
    </div>
  </div>
</template>
