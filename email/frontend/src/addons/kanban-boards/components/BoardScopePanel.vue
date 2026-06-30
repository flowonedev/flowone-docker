<script setup>
/**
 * BoardScopePanel - project dates + hours budget with scope variance.
 *
 * Shows project length, % time elapsed vs % cards done, and budgeted vs
 * tracked hours. When time runs out faster than cards get done, the project
 * is drifting out of scope — that drift is surfaced here.
 */
import { ref, computed } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'

const boardsStore = useBoardsStore()
const toast = useToastStore()

const board = computed(() => boardsStore.currentBoard)
const isOwner = computed(() => board.value?.user_role === 'owner')

const editing = ref(false)
const saving = ref(false)
const form = ref({ start_date: '', end_date: '', budget_hours: '' })

const hasSetup = computed(() => !!(board.value?.start_date || board.value?.end_date || board.value?.budget_hours))

const cards = computed(() => boardsStore.allCards.filter(c => !c.archived))
const doneCount = computed(() => cards.value.filter(c => c.completed).length)
const donePct = computed(() => cards.value.length ? Math.round((doneCount.value / cards.value.length) * 100) : 0)

const trackedSeconds = computed(() => cards.value.reduce((sum, c) => sum + (c.time_spent_seconds || 0), 0))
const trackedHours = computed(() => trackedSeconds.value / 3600)

const elapsedPct = computed(() => {
  if (!board.value?.start_date || !board.value?.end_date) return null
  const start = new Date(board.value.start_date).getTime()
  const end = new Date(board.value.end_date).getTime()
  if (end <= start) return null
  const pct = ((Date.now() - start) / (end - start)) * 100
  return Math.round(Math.min(120, Math.max(0, pct)))
})

const projectDays = computed(() => {
  if (!board.value?.start_date || !board.value?.end_date) return null
  return Math.round((new Date(board.value.end_date) - new Date(board.value.start_date)) / 86400000)
})

const daysLeft = computed(() => {
  if (!board.value?.end_date) return null
  return Math.ceil((new Date(board.value.end_date) - Date.now()) / 86400000)
})

// Positive drift = time is being consumed faster than work is completed
const scopeDrift = computed(() => {
  if (elapsedPct.value === null) return null
  return elapsedPct.value - donePct.value
})

const budgetPct = computed(() => {
  const budget = Number(board.value?.budget_hours || 0)
  if (!budget) return null
  return Math.round((trackedHours.value / budget) * 100)
})

const driftState = computed(() => {
  if (scopeDrift.value === null) return null
  if (scopeDrift.value >= 25) return { label: 'Off track', cls: 'text-red-500', icon: 'error' }
  if (scopeDrift.value >= 10) return { label: 'Drifting', cls: 'text-amber-500', icon: 'warning' }
  return { label: 'On track', cls: 'text-green-500', icon: 'check_circle' }
})

function startEdit() {
  form.value = {
    start_date: board.value?.start_date || '',
    end_date: board.value?.end_date || '',
    budget_hours: board.value?.budget_hours || '',
  }
  editing.value = true
}

async function save() {
  saving.value = true
  try {
    const updated = await boardsStore.updateBoard(board.value.id, {
      start_date: form.value.start_date || null,
      end_date: form.value.end_date || null,
      budget_hours: form.value.budget_hours !== '' ? Number(form.value.budget_hours) : null,
    })
    if (updated) {
      toast.success('Project scope updated')
      editing.value = false
    } else {
      toast.error('Failed to update project scope')
    }
  } finally {
    saving.value = false
  }
}

function fmtHours(h) {
  return Math.round(h * 10) / 10
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString([], { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div v-if="board" class="px-2 py-2">
    <div class="flex items-center justify-between px-1 mb-1">
      <span class="text-[10px] font-semibold text-surface-400 uppercase tracking-widest">Project Scope</span>
      <button
        v-if="isOwner && !editing"
        class="p-0.5 rounded text-surface-400 hover:text-primary-500 transition-colors"
        :title="hasSetup ? 'Edit dates & budget' : 'Set dates & budget'"
        @click="startEdit"
      >
        <span class="material-symbols-rounded text-sm">{{ hasSetup ? 'edit' : 'add_circle' }}</span>
      </button>
    </div>

    <!-- Edit form -->
    <div v-if="editing" class="space-y-2 px-1">
      <div>
        <label class="text-[10px] text-surface-400 block mb-0.5">Start date</label>
        <input v-model="form.start_date" type="date" class="w-full text-xs px-2 py-1 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-300" />
      </div>
      <div>
        <label class="text-[10px] text-surface-400 block mb-0.5">End date</label>
        <input v-model="form.end_date" type="date" class="w-full text-xs px-2 py-1 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-300" />
      </div>
      <div>
        <label class="text-[10px] text-surface-400 block mb-0.5">Budget (hours)</label>
        <input v-model="form.budget_hours" type="number" min="0" step="0.5" placeholder="e.g. 120" class="w-full text-xs px-2 py-1 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-300" />
      </div>
      <div class="flex gap-1.5 pt-0.5">
        <button :disabled="saving" class="flex-1 px-2 py-1 rounded-lg bg-primary-500 text-white text-xs font-medium hover:bg-primary-600 disabled:opacity-50" @click="save">Save</button>
        <button class="px-2 py-1 rounded-lg text-xs text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700" @click="editing = false">Cancel</button>
      </div>
    </div>

    <!-- Empty state (non-owner sees nothing until set) -->
    <div v-else-if="!hasSetup && isOwner" class="px-1 text-[11px] text-surface-400">
      Set start/end dates and an hours budget to track scope.
    </div>

    <!-- Scope summary -->
    <div v-else-if="hasSetup" class="px-1 space-y-2">
      <div v-if="projectDays" class="flex items-center justify-between text-[11px] text-surface-500">
        <span>{{ fmtDate(board.start_date) }} → {{ fmtDate(board.end_date) }}</span>
        <span v-if="daysLeft !== null" :class="daysLeft < 0 ? 'text-red-500 font-medium' : ''">
          {{ daysLeft < 0 ? `${-daysLeft}d over` : `${daysLeft}d left` }}
        </span>
      </div>

      <!-- Time elapsed vs cards done -->
      <div v-if="elapsedPct !== null">
        <div class="flex items-center justify-between text-[10px] text-surface-400 mb-0.5">
          <span>Time {{ elapsedPct }}%</span>
          <span>Done {{ donePct }}%</span>
        </div>
        <div class="relative h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
          <div class="absolute inset-y-0 left-0 bg-surface-300 dark:bg-surface-500 rounded-full" :style="{ width: Math.min(100, elapsedPct) + '%' }"></div>
          <div class="absolute inset-y-0 left-0 bg-green-500 rounded-full" :style="{ width: donePct + '%' }"></div>
        </div>
        <div v-if="driftState" class="flex items-center gap-1 mt-1" :class="driftState.cls">
          <span class="material-symbols-rounded text-[13px]">{{ driftState.icon }}</span>
          <span class="text-[11px] font-medium">{{ driftState.label }}</span>
          <span v-if="scopeDrift > 0" class="text-[10px] opacity-80">({{ scopeDrift }}% behind)</span>
        </div>
      </div>

      <!-- Budget vs tracked -->
      <div v-if="board.budget_hours">
        <div class="flex items-center justify-between text-[10px] text-surface-400 mb-0.5">
          <span>Hours</span>
          <span :class="budgetPct > 100 ? 'text-red-500 font-medium' : ''">
            {{ fmtHours(trackedHours) }}h / {{ fmtHours(Number(board.budget_hours)) }}h
          </span>
        </div>
        <div class="h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
          <div
            class="h-full rounded-full transition-all"
            :class="budgetPct > 100 ? 'bg-red-500' : budgetPct > 80 ? 'bg-amber-500' : 'bg-blue-500'"
            :style="{ width: Math.min(100, budgetPct) + '%' }"
          ></div>
        </div>
      </div>
    </div>
  </div>
</template>
