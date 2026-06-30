import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

// Refresh at most once per (REFRESH_MS) from the same tab.
// The backend already shares the cache across users, so this is just to keep
// individual page navigations cheap.
const REFRESH_MS = 15 * 60 * 1000

export const useWeatherStore = defineStore('weather', () => {
  const current = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const lastFetchedAt = ref(0)

  const available = computed(() => !!current.value && current.value.available !== false)
  const temperature = computed(() => current.value?.temperature_c ?? null)
  const weatherCode = computed(() => current.value?.weather_code ?? null)
  const isDay = computed(() => current.value?.is_day ?? 1)
  const city = computed(() => current.value?.city ?? null)
  const forecast = computed(() => Array.isArray(current.value?.forecast) ? current.value.forecast : [])
  const todayHi = computed(() => forecast.value[0]?.max ?? null)
  const todayLo = computed(() => forecast.value[0]?.min ?? null)

  async function fetch(force = false) {
    const now = Date.now()
    if (!force && current.value && (now - lastFetchedAt.value) < REFRESH_MS) {
      return current.value
    }
    if (loading.value) return current.value

    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/weather/current')
      if (data && data.success && data.data) {
        current.value = data.data
        lastFetchedAt.value = now
      }
    } catch (e) {
      error.value = e?.message || 'weather fetch failed'
    } finally {
      loading.value = false
    }
    return current.value
  }

  function reset() {
    current.value = null
    loading.value = false
    error.value = null
    lastFetchedAt.value = 0
  }

  return {
    current, loading, error,
    available, temperature, weatherCode, isDay, city,
    forecast, todayHi, todayLo,
    fetch, reset,
  }
})
