<script setup>
import { ref, onMounted, computed } from 'vue'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import api from '@/services/api'

const props = defineProps({
  cardId: { type: [Number, String], required: true },
})

const calStore = useCalendarStore()

const syncMap = ref(null)
const loading = ref(true)
const toggling = ref(false)
const selectedCalendarId = ref(null)

const calendars = computed(() => calStore.calendars || [])

async function loadSyncState() {
  loading.value = true
  try {
    const { data } = await api.get(`/project-hub/cards/${props.cardId}/calendar-sync`)
    syncMap.value = data.sync || null
    if (syncMap.value?.calendar_id) {
      selectedCalendarId.value = Number(syncMap.value.calendar_id)
    }
  } catch (err) {
    console.error('[TaskCalSync] load error:', err)
  } finally {
    loading.value = false
  }
}

async function enableSync() {
  if (!selectedCalendarId.value) return
  toggling.value = true
  try {
    const { data } = await api.post(`/project-hub/cards/${props.cardId}/calendar-sync`, {
      calendar_id: selectedCalendarId.value,
    })
    syncMap.value = data || null
  } catch (err) {
    console.error('[TaskCalSync] enable error:', err)
  } finally {
    toggling.value = false
  }
}

async function disableSync() {
  toggling.value = true
  try {
    await api.delete(`/project-hub/cards/${props.cardId}/calendar-sync`)
    syncMap.value = null
  } catch (err) {
    console.error('[TaskCalSync] disable error:', err)
  } finally {
    toggling.value = false
  }
}

function formatDate(dateStr) {
  if (!dateStr) return 'Never'
  return new Date(dateStr).toLocaleString()
}

onMounted(async () => {
  await calStore.fetchCalendars()
  await loadSyncState()
  if (!selectedCalendarId.value && calendars.value.length > 0) {
    selectedCalendarId.value = calendars.value[0].id
  }
})
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center gap-2">
      <span class="material-symbols-rounded text-lg text-surface-500">calendar_month</span>
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200">Calendar Sync</h3>
    </div>

    <div v-if="loading" class="flex items-center gap-2 text-surface-400 text-sm py-2">
      <span class="material-symbols-rounded animate-spin text-base">progress_activity</span>
      Loading...
    </div>

    <template v-else>
      <!-- Currently synced -->
      <div v-if="syncMap && syncMap.sync_enabled" class="space-y-2">
        <div class="flex items-center gap-2 p-3 rounded-lg bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20">
          <span class="material-symbols-rounded text-base text-green-600 dark:text-green-400">sync</span>
          <div class="flex-1">
            <p class="text-sm text-green-700 dark:text-green-300 font-medium">Synced to calendar</p>
            <p v-if="syncMap.last_synced_at" class="text-xs text-green-600/70 dark:text-green-400/70">
              Last synced: {{ formatDate(syncMap.last_synced_at) }}
            </p>
          </div>
        </div>

        <button
          @click="disableSync"
          :disabled="toggling"
          class="w-full text-xs px-3 py-2 rounded-full border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors flex items-center justify-center gap-1"
        >
          <span v-if="toggling" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
          <span v-else class="material-symbols-rounded text-sm">sync_disabled</span>
          Disable sync
        </button>
      </div>

      <!-- Not synced -->
      <div v-else class="space-y-3">
        <div v-if="calendars.length === 0" class="text-sm text-surface-400 py-2">
          No calendars available. Create a calendar first.
        </div>

        <template v-else>
          <label class="text-xs text-surface-500 font-medium">Select calendar</label>
          <select
            v-model="selectedCalendarId"
            class="w-full text-sm px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-200"
          >
            <option
              v-for="cal in calendars"
              :key="cal.id"
              :value="cal.id"
            >
              {{ cal.name }}
            </option>
          </select>

          <button
            @click="enableSync"
            :disabled="toggling || !selectedCalendarId"
            class="w-full text-xs px-3 py-2 rounded-full bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-50 transition-colors flex items-center justify-center gap-1"
          >
            <span v-if="toggling" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
            <span v-else class="material-symbols-rounded text-sm">sync</span>
            Enable calendar sync
          </button>
        </template>
      </div>
    </template>
  </div>
</template>
