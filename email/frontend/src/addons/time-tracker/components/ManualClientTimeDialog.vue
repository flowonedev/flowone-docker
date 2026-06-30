<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: [Number, String], default: null },
  clients: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'saved'])

const toast = useToastStore()
const saving = ref(false)

const categories = [
  { value: 'Meeting', icon: 'groups' },
  { value: 'Research', icon: 'search' },
  { value: 'Admin', icon: 'settings' },
  { value: 'Phone Call', icon: 'call' },
  { value: 'Design', icon: 'palette' },
  { value: 'Development', icon: 'code' },
  { value: 'Review', icon: 'rate_review' },
  { value: 'Other', icon: 'more_horiz' },
]

const today = new Date().toISOString().slice(0, 10)
const form = ref({
  clientId: props.clientId ? Number(props.clientId) : null,
  category: 'Meeting',
  date: today,
  hours: 0,
  minutes: 30,
  note: '',
})

const durationSeconds = computed(() => (form.value.hours * 3600) + (form.value.minutes * 60))
const isValid = computed(() => durationSeconds.value > 0 && form.value.date && form.value.clientId)

const selectedClientName = computed(() => {
  if (!form.value.clientId) return ''
  const client = props.clients.find(c => c.id === form.value.clientId)
  return client?.display_name || client?.domain || ''
})

async function submit() {
  if (!isValid.value || saving.value) return
  saving.value = true

  const entityName = form.value.note.trim()
    ? `${form.value.category}: ${form.value.note.trim()}`
    : form.value.category

  try {
    await api.post(`/clients/${form.value.clientId}/time`, {
      activity_type: 'manual_entry',
      duration_seconds: durationSeconds.value,
      entity_id: form.value.date,
      entity_name: entityName,
    })
    toast.success('Time entry logged')
    emit('saved')
    emit('close')
  } catch (err) {
    console.error('[ManualClientTime] Failed:', err)
    toast.error(err?.response?.data?.message || 'Failed to log time entry')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-[70] flex items-center justify-center bg-black/40"
      @mousedown.self="emit('close')"
    >
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md mx-4">
        <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="text-base font-bold text-surface-800 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">more_time</span>
            Log Time
          </h3>
          <p class="text-xs text-surface-400 mt-1">Manually add time for a client</p>
        </div>

        <div class="p-5 space-y-4">
          <!-- Client picker (if not pre-selected) -->
          <div v-if="!props.clientId">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Client <span class="text-red-500">*</span>
            </label>
            <select
              v-model="form.clientId"
              class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500"
            >
              <option :value="null" disabled>Select a client...</option>
              <option v-for="client in clients" :key="client.id" :value="client.id">
                {{ client.display_name || client.domain }}
              </option>
            </select>
          </div>
          <div v-else class="flex items-center gap-2 px-3 py-2 rounded-xl bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800">
            <span class="material-symbols-rounded text-sm text-primary-500">domain</span>
            <span class="text-sm font-medium text-primary-700 dark:text-primary-300">{{ selectedClientName || `Client #${props.clientId}` }}</span>
          </div>

          <!-- Category -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Category</label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="cat in categories"
                :key="cat.value"
                @click="form.category = cat.value"
                :class="[
                  'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all',
                  form.category === cat.value
                    ? 'bg-primary-500 text-white shadow-sm'
                    : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-sm">{{ cat.icon }}</span>
                {{ cat.value }}
              </button>
            </div>
          </div>

          <!-- Date -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Date</label>
            <input
              v-model="form.date"
              type="date"
              :max="today"
              class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500"
            />
          </div>

          <!-- Duration -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Duration</label>
            <div class="flex items-center gap-3">
              <div class="flex items-center gap-1.5">
                <input
                  v-model.number="form.hours"
                  type="number"
                  min="0"
                  max="23"
                  class="w-16 px-2 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-center text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500"
                />
                <span class="text-xs text-surface-500">hrs</span>
              </div>
              <div class="flex items-center gap-1.5">
                <input
                  v-model.number="form.minutes"
                  type="number"
                  min="0"
                  max="59"
                  class="w-16 px-2 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-center text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500"
                />
                <span class="text-xs text-surface-500">min</span>
              </div>
            </div>
            <p v-if="durationSeconds > 0" class="text-xs text-surface-400 mt-1">
              Total: {{ Math.floor(durationSeconds / 3600) }}h {{ Math.floor((durationSeconds % 3600) / 60) }}m
            </p>
          </div>

          <!-- Note -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Note (optional)</label>
            <input
              v-model="form.note"
              type="text"
              placeholder="Brief description of work done"
              class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500"
            />
          </div>
        </div>

        <div class="px-5 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
          <button
            @click="emit('close')"
            class="px-4 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            Cancel
          </button>
          <button
            @click="submit"
            :disabled="!isValid || saving"
            class="px-5 py-2 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-1.5"
          >
            <span v-if="saving" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
            <span class="material-symbols-rounded text-sm" v-else>check</span>
            {{ saving ? 'Saving...' : 'Log Time' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
