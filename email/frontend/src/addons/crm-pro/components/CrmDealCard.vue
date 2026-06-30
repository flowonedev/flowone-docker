<script setup>
/**
 * CrmDealCard - A single deal card in the pipeline Kanban board
 * Supports drag, inline editing, and quick actions.
 */
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  deal: { type: Object, required: true },
  clients: { type: Array, default: () => [] },
})
const emit = defineEmits(['updated'])
const toast = useToastStore()

const showEdit = ref(false)
const editForm = ref({})
const saving = ref(false)
const expectedValueDisplay = ref('')

const clientName = computed(() => {
  const c = props.clients.find(cl => cl.id === props.deal.client_id)
  return c ? (c.display_name || c.domain) : 'Unknown'
})

/** Days since the deal was last updated (used for stale badges) */
const daysInStage = computed(() => {
  const ref = props.deal.updated_at || props.deal.created_at
  if (!ref) return 0
  const diff = Date.now() - new Date(ref).getTime()
  return Math.floor(diff / 86400000)
})

const staleBadge = computed(() => {
  const d = daysInStage.value
  const stage = props.deal.pipeline_stage
  if (stage === 'won' || stage === 'lost') return null
  if (d > 30) return { text: `${d}d`, color: 'bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400' }
  if (d > 14) return { text: `${d}d`, color: 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400' }
  return null
})

function startEdit() {
  editForm.value = { ...props.deal }
  expectedValueDisplay.value = editForm.value.expected_value ? formatMoney(editForm.value.expected_value) : ''
  showEdit.value = true
}

function onValueBlur() {
  const num = parseInt(String(expectedValueDisplay.value).replace(/[^\d]/g, ''), 10)
  editForm.value.expected_value = isNaN(num) ? '' : num
  expectedValueDisplay.value = isNaN(num) ? '' : formatMoney(num)
}

async function saveEdit() {
  saving.value = true
  try {
    await api.put(`/crm/deals/${props.deal.id}`, editForm.value)
    toast.success('Deal updated')
    showEdit.value = false
    emit('updated')
  } catch (e) {
    toast.error('Failed to update deal')
  } finally {
    saving.value = false
  }
}

async function deleteDeal() {
  if (!confirm(`Delete deal "${props.deal.title}"?`)) return
  try {
    await api.delete(`/crm/deals/${props.deal.id}`)
    toast.success('Deal deleted')
    emit('updated')
  } catch (e) {
    toast.error('Failed to delete')
  }
}

function formatMoney(v) {
  return new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 0 }).format(v || 0)
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 cursor-grab
              hover:shadow-md transition-shadow group"
       @dblclick="startEdit">
    <div class="flex items-start justify-between gap-2 mb-2">
      <h4 class="text-sm font-semibold text-surface-800 dark:text-white leading-tight">{{ deal.title }}</h4>
      <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
        <button @click.stop="startEdit" class="p-1 rounded text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700">
          <span class="material-symbols-rounded text-sm">edit</span>
        </button>
        <button @click.stop="deleteDeal" class="p-1 rounded text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
          <span class="material-symbols-rounded text-sm">delete</span>
        </button>
      </div>
    </div>

    <p class="text-xs text-surface-400 mb-2">{{ clientName }}</p>

    <div class="flex items-center justify-between">
      <span v-if="deal.expected_value" class="text-sm font-bold text-primary-600 dark:text-primary-400">
        {{ formatMoney(deal.expected_value) }} {{ deal.currency || 'HUF' }}
      </span>
      <span v-else class="text-xs text-surface-300">No value set</span>

      <span class="text-xs text-surface-400">{{ deal.probability }}%</span>
    </div>

    <!-- Probability bar -->
    <div class="mt-2 h-1.5 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
      <div class="h-full rounded-full transition-all"
           :class="deal.probability >= 70 ? 'bg-green-500' : deal.probability >= 40 ? 'bg-amber-500' : 'bg-red-400'"
           :style="{ width: deal.probability + '%' }"></div>
    </div>

    <div class="mt-2 flex items-center justify-between">
      <div v-if="deal.expected_close_date" class="flex items-center gap-1 text-xs text-surface-400">
        <span class="material-symbols-rounded text-xs">event</span>
        {{ formatDate(deal.expected_close_date) }}
      </div>
      <span v-else></span>

      <!-- Stale deal badge -->
      <span v-if="staleBadge" :class="['text-[10px] font-semibold px-1.5 py-0.5 rounded-full flex items-center gap-0.5', staleBadge.color]">
        <span class="material-symbols-rounded text-[10px]">schedule</span>
        {{ staleBadge.text }}
      </span>
    </div>

    <!-- Edit Modal -->
    <Teleport to="body">
      <div v-if="showEdit" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showEdit = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6" @click.stop>
          <h3 class="text-lg font-bold text-surface-900 dark:text-white mb-4">Edit Deal</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Client *</label>
              <select v-model="editForm.client_id"
                      class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                <option value="">Select client...</option>
                <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.display_name || c.domain }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Title *</label>
              <input v-model="editForm.title"
                     class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Description</label>
              <textarea v-model="editForm.description" rows="3"
                        class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Expected Value</label>
                <input v-model="expectedValueDisplay" type="text" inputmode="numeric" placeholder="0"
                       @blur="onValueBlur"
                       class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Probability %</label>
                <input v-model.number="editForm.probability" type="number" min="0" max="100"
                       class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Stage</label>
                <select v-model="editForm.pipeline_stage"
                        class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                  <option value="lead">Lead</option>
                  <option value="contacted">Contacted</option>
                  <option value="proposal">Proposal</option>
                  <option value="negotiation">Negotiation</option>
                  <option value="won">Won</option>
                  <option value="lost">Lost</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Close Date</label>
                <input v-model="editForm.expected_close_date" type="date"
                       class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
              </div>
            </div>
          </div>
          <div class="flex justify-end gap-3 mt-6">
            <button @click="showEdit = false" class="px-4 py-2 text-sm text-surface-500">Cancel</button>
            <button @click="saveEdit" :disabled="saving"
                    class="px-6 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50">
              {{ saving ? 'Saving...' : 'Save' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

