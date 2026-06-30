<script setup>
// MigrationChecklist
// ---------------------------------------------------------------
// DB-persisted readiness board for an email migration. The admin
// runs the whole migration from the Panel and ticks off phases
// (server deployed, users synced/checked, contacts, calendar, DNS
// cutover, …). State lives server-side via /api/migration-checklist
// so it survives reloads and is shared across admins.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Toggle from '@/components/Toggle.vue'

const toast = useToastStore()

const items = ref([])
const summary = ref({ total: 0, completed: 0, percent: 0 })
const loading = ref(false)
const collapsed = ref(false)
const busyIds = ref(new Set())
const newItem = ref({ label: '', category: 'custom', show: false })

// Friendly labels + ordering for the category groups.
const CATEGORY_META = {
  infrastructure: { label: 'Infrastructure', icon: 'dns' },
  mailboxes: { label: 'Mailboxes', icon: 'mail' },
  contacts: { label: 'Contacts', icon: 'contacts' },
  calendar: { label: 'Calendar', icon: 'calendar_month' },
  dns: { label: 'DNS / Cutover', icon: 'cloud' },
  handover: { label: 'Handover', icon: 'flag' },
  custom: { label: 'Custom', icon: 'star' },
}

const categoryOrder = (key) => {
  const order = ['infrastructure', 'mailboxes', 'contacts', 'calendar', 'dns', 'handover', 'custom']
  const i = order.indexOf(key)
  return i === -1 ? order.length : i
}

// Group items into ordered categories for display.
const groupedItems = computed(() => {
  const groups = {}
  for (const item of items.value) {
    const cat = item.category || 'custom'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(item)
  }
  return Object.keys(groups)
    .sort((a, b) => categoryOrder(a) - categoryOrder(b))
    .map((key) => ({
      key,
      meta: CATEGORY_META[key] || { label: key, icon: 'check_circle' },
      items: groups[key],
      done: groups[key].filter((i) => i.done).length,
    }))
})

const applyResponse = (data) => {
  items.value = Array.isArray(data?.items) ? data.items : []
  summary.value = data?.summary || { total: 0, completed: 0, percent: 0 }
}

const fetchChecklist = async () => {
  loading.value = true
  try {
    const r = await api.get('/migration-checklist')
    if (r.data?.success) applyResponse(r.data.data)
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to load checklist')
  } finally {
    loading.value = false
  }
}

const toggleItem = async (item) => {
  if (busyIds.value.has(item.id)) return
  busyIds.value.add(item.id)
  const next = !item.done
  try {
    const r = await api.put(`/migration-checklist/${item.id}`, { done: next })
    if (r.data?.success) {
      applyResponse(r.data.data)
    } else {
      toast.error(r.data?.error || 'Update failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Update failed')
  } finally {
    busyIds.value.delete(item.id)
  }
}

let notesTimer = null
const saveNotes = (item) => {
  clearTimeout(notesTimer)
  const id = item.id
  const notes = item.notes ?? ''
  notesTimer = setTimeout(async () => {
    try {
      await api.put(`/migration-checklist/${id}`, { notes })
    } catch (e) {
      toast.error('Failed to save note')
    }
  }, 600)
}

const addCustomItem = async () => {
  const label = newItem.value.label.trim()
  if (!label) {
    toast.error('Enter a label')
    return
  }
  try {
    const r = await api.post('/migration-checklist', {
      label,
      category: newItem.value.category || 'custom',
    })
    if (r.data?.success) {
      applyResponse(r.data.data)
      newItem.value = { label: '', category: 'custom', show: false }
      toast.success('Item added')
    } else {
      toast.error(r.data?.error || 'Failed to add')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to add')
  }
}

const removeItem = async (item) => {
  try {
    const r = await api.delete(`/migration-checklist/${item.id}`)
    if (r.data?.success) {
      applyResponse(r.data.data)
    } else {
      toast.error(r.data?.error || 'Failed to remove')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to remove')
  }
}

onMounted(fetchChecklist)
defineExpose({ refresh: fetchChecklist })
</script>

<template>
  <div class="card p-4">
    <!-- Header + overall progress -->
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <button
        class="flex items-center gap-2 min-w-0"
        @click="collapsed = !collapsed"
      >
        <span class="material-symbols-rounded text-primary-500">checklist</span>
        <h4 class="font-semibold">Migration Readiness</h4>
        <span
          class="px-2 py-0.5 text-xs font-medium rounded-full
                 bg-primary-100 dark:bg-primary-500/20
                 text-primary-600 dark:text-primary-400"
        >
          {{ summary.completed }} / {{ summary.total }}
        </span>
        <span class="material-symbols-rounded text-surface-400 text-base">
          {{ collapsed ? 'expand_more' : 'expand_less' }}
        </span>
      </button>
      <div class="flex items-center gap-2">
        <button
          class="btn-ghost btn-sm"
          :disabled="loading"
          title="Refresh"
          @click="fetchChecklist"
        >
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span>
        </button>
        <button class="btn-secondary btn-sm" @click="newItem.show = !newItem.show">
          <span class="material-symbols-rounded">add</span>
          Add step
        </button>
      </div>
    </div>

    <!-- Overall progress bar -->
    <div class="mt-3">
      <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-2">
        <div
          class="h-2 rounded-full transition-all duration-500"
          :class="summary.percent === 100
            ? 'bg-gradient-to-r from-green-500 to-green-400'
            : 'bg-gradient-to-r from-primary-500 to-primary-400'"
          :style="{ width: `${summary.percent}%` }"
        />
      </div>
      <p class="text-xs text-surface-500 mt-1">{{ summary.percent }}% complete</p>
    </div>

    <!-- Add custom step -->
    <div
      v-if="newItem.show"
      class="mt-3 flex flex-col sm:flex-row gap-2 p-3 bg-surface-50 dark:bg-surface-800 rounded-xl"
    >
      <input
        v-model="newItem.label"
        type="text"
        class="input flex-1"
        placeholder="Custom step label…"
        @keyup.enter="addCustomItem"
      />
      <select v-model="newItem.category" class="input sm:w-44">
        <option value="infrastructure">Infrastructure</option>
        <option value="mailboxes">Mailboxes</option>
        <option value="contacts">Contacts</option>
        <option value="calendar">Calendar</option>
        <option value="dns">DNS / Cutover</option>
        <option value="handover">Handover</option>
        <option value="custom">Custom</option>
      </select>
      <button class="btn-primary btn-sm" @click="addCustomItem">
        <span class="material-symbols-rounded">add</span>
        Add
      </button>
    </div>

    <!-- Category groups -->
    <div v-show="!collapsed" class="mt-4 space-y-5">
      <div v-for="group in groupedItems" :key="group.key">
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-rounded text-surface-400 text-base">{{ group.meta.icon }}</span>
          <h5 class="text-xs font-semibold uppercase tracking-wide text-surface-500">
            {{ group.meta.label }}
          </h5>
          <span class="text-xs text-surface-400">{{ group.done }} / {{ group.items.length }}</span>
        </div>

        <div class="space-y-2">
          <div
            v-for="item in group.items"
            :key="item.id"
            class="flex items-center gap-3 p-3 rounded-xl transition-colors"
            :class="item.done
              ? 'bg-green-50 dark:bg-green-500/10'
              : 'bg-surface-50 dark:bg-surface-800'"
          >
            <Toggle
              :model-value="item.done"
              :disabled="busyIds.has(item.id)"
              @update:model-value="toggleItem(item)"
            />
            <div class="flex-1 min-w-0">
              <p
                class="text-sm font-medium truncate"
                :class="item.done ? 'text-green-700 dark:text-green-300' : ''"
              >
                {{ item.label }}
              </p>
              <p v-if="item.done && item.done_by" class="text-xs text-surface-400 truncate">
                by {{ item.done_by }}<span v-if="item.done_at"> · {{ item.done_at }}</span>
              </p>
            </div>
            <input
              v-model="item.notes"
              type="text"
              class="input input-sm hidden md:block w-48 text-xs"
              placeholder="Notes…"
              @input="saveNotes(item)"
            />
            <button
              v-if="item.is_custom"
              class="btn-ghost btn-sm text-red-500"
              title="Remove custom step"
              @click="removeItem(item)"
            >
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
        </div>
      </div>

      <p v-if="!groupedItems.length && !loading" class="text-sm text-surface-400 text-center py-4">
        No checklist items yet.
      </p>
    </div>
  </div>
</template>
