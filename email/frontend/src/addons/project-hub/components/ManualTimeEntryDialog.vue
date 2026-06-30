<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'

const props = defineProps({
  cardId: { type: [Number, String], required: true },
  cardTitle: { type: String, default: '' },
  canSelectMember: { type: Boolean, default: false },
  memberOptions: { type: Array, default: () => [] },
  allowTaskSelection: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'saved'])

const toast = useToastStore()
const hubStore = useProjectHubStore()
const saving = ref(false)
const loadingTasks = ref(false)
const taskOptions = ref([])

const today = new Date().toISOString().slice(0, 10)
const form = ref({
  date: today,
  hours: 0,
  minutes: 30,
  note: '',
  taskId: Number(props.cardId),
  memberEmail: '',
})

const durationSeconds = computed(() => (form.value.hours * 3600) + (form.value.minutes * 60))
const isValid = computed(() => durationSeconds.value > 0 && form.value.date)
const normalizedMemberOptions = computed(() =>
  (props.memberOptions || [])
    .map(member => {
      const email = member?.email || member?.user_email || ''
      return {
        email,
        label: member?.display_name || email.split('@')[0] || email,
      }
    })
    .filter(member => member.email)
)

async function loadTaskOptions() {
  if (!props.allowTaskSelection) return
  loadingTasks.value = true
  try {
    const subtasks = await hubStore.fetchSubtasks(Number(props.cardId))
    taskOptions.value = [
      { id: Number(props.cardId), title: props.cardTitle || 'Current card', kind: 'card' },
      ...subtasks.map(task => ({
        id: Number(task.id),
        title: task.title,
        kind: 'task',
      })),
    ]
  } finally {
    loadingTasks.value = false
  }
}

onMounted(() => {
  if (props.canSelectMember) {
    form.value.memberEmail = normalizedMemberOptions.value[0]?.email || ''
  }
  loadTaskOptions()
})

function buildTimestamps() {
  const base = new Date(form.value.date + 'T12:00:00')
  const ended = new Date(base.getTime() + durationSeconds.value * 1000)
  const fmt = d => d.toISOString().slice(0, 19).replace('T', ' ')
  return { started_at: fmt(base), ended_at: fmt(ended) }
}

async function submit() {
  if (!isValid.value || saving.value) return
  saving.value = true

  const { started_at, ended_at } = buildTimestamps()

  try {
    await api.post('/project-hub/work-sessions', {
      card_id: Number(form.value.taskId || props.cardId),
      source: 'manual',
      started_at,
      ended_at,
      duration_seconds: durationSeconds.value,
      entity_name: form.value.note.trim() || null,
      user_email: props.canSelectMember ? (form.value.memberEmail || null) : null,
    })
    toast.success('Time entry added')
    emit('saved', Number(form.value.taskId || props.cardId))
    emit('close')
  } catch (err) {
    console.error('[ManualTimeEntry] Failed:', err)
    toast.error(err?.response?.data?.message || 'Failed to add time entry')
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
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-sm mx-4">
        <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="text-base font-bold text-surface-800 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">more_time</span>
            Add Time
          </h3>
          <p v-if="cardTitle" class="text-xs text-surface-400 mt-1 truncate">{{ cardTitle }}</p>
        </div>

        <div class="p-5 space-y-4">
          <div v-if="canSelectMember">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Member</label>
            <select
              v-model="form.memberEmail"
              class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            >
              <option v-for="member in normalizedMemberOptions" :key="member.email" :value="member.email">
                {{ member.label }} ({{ member.email }})
              </option>
            </select>
          </div>

          <div v-if="allowTaskSelection">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Task</label>
            <select
              v-model="form.taskId"
              :disabled="loadingTasks"
              class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 disabled:opacity-60"
            >
              <option v-for="task in taskOptions" :key="task.id" :value="task.id">
                {{ task.kind === 'card' ? 'Card' : 'Task' }}: {{ task.title }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Date</label>
            <input
              v-model="form.date"
              type="date"
              :max="today"
              class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            />
          </div>

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

          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Note (optional)</label>
            <input
              v-model="form.note"
              type="text"
              placeholder="What did you work on?"
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
            {{ saving ? 'Saving...' : 'Add Time' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
