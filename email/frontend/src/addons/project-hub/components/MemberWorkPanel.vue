<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import AssigneeStatusBadge from './AssigneeStatusBadge.vue'

const props = defineProps({
  member: { type: Object, default: null },
})

const emit = defineEmits(['close'])
const router = useRouter()

const hubStore = useProjectHubStore()
const loading = ref(false)
const cards = ref([])

watch(() => props.member, async (m) => {
  if (!m?.email) { cards.value = []; return }
  loading.value = true
  try {
    cards.value = await hubStore.fetchMemberWorkload(m.email)
  } finally {
    loading.value = false
  }
}, { immediate: true })

const statusOrder = ['working', 'assigned', 'review', 'blocked', 'done']

const groupedCards = computed(() => {
  const map = new Map()
  for (const card of cards.value) {
    const key = card.status || 'assigned'
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(card)
  }
  return statusOrder
    .filter(s => map.has(s))
    .map(s => ({ status: s, cards: map.get(s) }))
})

const stats = computed(() => ({
  total: cards.value.length,
  active: cards.value.filter(c => !c.completed).length,
  done: cards.value.filter(c => c.completed).length,
  overdue: cards.value.filter(c => !c.completed && c.due_date && new Date(c.due_date) < new Date()).length,
}))

function statusLabel(status) {
  const labels = { working: 'In Progress', assigned: 'To Do', review: 'Review', blocked: 'Blocked', done: 'Done' }
  return labels[status] || status
}

function statusIcon(status) {
  const icons = { working: 'play_circle', assigned: 'radio_button_unchecked', review: 'visibility', blocked: 'block', done: 'check_circle' }
  return icons[status] || 'circle'
}

function statusColor(status) {
  const colors = { working: 'text-blue-500', assigned: 'text-surface-400', review: 'text-amber-500', blocked: 'text-red-500', done: 'text-green-500' }
  return colors[status] || 'text-surface-400'
}

function isOverdue(card) {
  return !card.completed && card.due_date && new Date(card.due_date) < new Date()
}

function formatDueDate(date) {
  if (!date) return ''
  const d = new Date(date)
  const now = new Date()
  const diff = Math.floor((d - now) / 86400000)
  if (diff === 0) return 'Today'
  if (diff === 1) return 'Tomorrow'
  if (diff === -1) return 'Yesterday'
  if (diff < -1) return `${Math.abs(diff)}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function openCard(card) {
  emit('close')
  router.push({ name: 'board', params: { id: card.board_id }, query: { card: card.card_id } })
}

function initials(member) {
  if (member?.name) {
    const parts = member.name.split(' ')
    return (parts[0]?.[0] || '') + (parts[1]?.[0] || '')
  }
  return (member?.email?.[0] || '?').toUpperCase()
}
</script>

<template>
  <Teleport to="body">
    <Transition name="member-panel">
      <div
        v-if="member"
        class="fixed inset-0 z-[55]"
        @click.self="emit('close')"
      >
        <div class="absolute right-0 top-0 bottom-0 w-full max-w-md bg-white dark:bg-surface-800 shadow-2xl flex flex-col border-l border-surface-200 dark:border-surface-700">
          <!-- Header -->
          <div class="flex items-center gap-3 px-5 py-4 border-b border-surface-200 dark:border-surface-700 shrink-0">
            <div class="w-9 h-9 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-sm font-bold text-primary-700 dark:text-primary-300">
              {{ initials(member) }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-surface-800 dark:text-surface-100 truncate">
                {{ member.name || member.email }}
              </p>
              <p v-if="member.name" class="text-xs text-surface-400 truncate">{{ member.email }}</p>
            </div>
            <button
              @click="emit('close')"
              class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-surface-500">close</span>
            </button>
          </div>

          <!-- Stats -->
          <div class="grid grid-cols-4 gap-2 px-5 py-3 border-b border-surface-200 dark:border-surface-700 shrink-0">
            <div class="text-center">
              <div class="text-lg font-bold text-surface-800 dark:text-surface-100">{{ stats.total }}</div>
              <div class="text-[10px] text-surface-400">Total</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold text-blue-500">{{ stats.active }}</div>
              <div class="text-[10px] text-surface-400">Active</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold text-green-500">{{ stats.done }}</div>
              <div class="text-[10px] text-surface-400">Done</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold" :class="stats.overdue > 0 ? 'text-red-500' : 'text-surface-300'">{{ stats.overdue }}</div>
              <div class="text-[10px] text-surface-400">Overdue</div>
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
            <div v-if="loading" class="flex items-center justify-center py-12">
              <div class="animate-spin rounded-full h-6 w-6 border-2 border-primary-500 border-t-transparent"></div>
            </div>

            <div v-else-if="cards.length === 0" class="text-center py-12 text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">task_alt</span>
              <p class="text-sm">No tasks assigned</p>
            </div>

            <template v-else>
              <div v-for="group in groupedCards" :key="group.status">
                <div class="flex items-center gap-2 mb-2">
                  <span class="material-symbols-rounded text-base" :class="statusColor(group.status)">{{ statusIcon(group.status) }}</span>
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">{{ statusLabel(group.status) }}</span>
                  <span class="text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ group.cards.length }}</span>
                </div>

                <div class="space-y-1.5">
                  <button
                    v-for="card in group.cards"
                    :key="card.card_id"
                    @click="openCard(card)"
                    class="w-full text-left px-3 py-2.5 bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-xl transition-colors group/card"
                  >
                    <div class="flex items-start gap-2">
                      <div class="flex-1 min-w-0">
                        <p class="text-sm text-surface-800 dark:text-surface-100 truncate group-hover/card:text-primary-600 dark:group-hover/card:text-primary-400 transition-colors">
                          {{ card.card_title }}
                        </p>
                        <div class="flex items-center gap-2 mt-1">
                          <span class="text-[10px] text-surface-400 truncate">{{ card.board_name }}</span>
                          <AssigneeStatusBadge v-if="card.status" :status="card.status" size="xs" />
                        </div>
                      </div>
                      <div v-if="card.due_date" class="shrink-0 text-right">
                        <span
                          class="text-[10px] font-medium px-1.5 py-0.5 rounded"
                          :class="isOverdue(card) ? 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400' : 'text-surface-400'"
                        >
                          {{ formatDueDate(card.due_date) }}
                        </span>
                      </div>
                    </div>
                  </button>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.member-panel-enter-active {
  transition: opacity 0.2s ease;
}
.member-panel-enter-active > div:last-child {
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.member-panel-leave-active {
  transition: opacity 0.15s ease;
}
.member-panel-leave-active > div:last-child {
  transition: transform 0.2s ease-in;
}
.member-panel-enter-from { opacity: 0; }
.member-panel-enter-from > div:last-child { transform: translateX(100%); }
.member-panel-leave-to { opacity: 0; }
.member-panel-leave-to > div:last-child { transform: translateX(100%); }
</style>
