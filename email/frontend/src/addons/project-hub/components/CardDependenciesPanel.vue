<script setup>
import { ref, onMounted, computed } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import api from '@/services/api'

const props = defineProps({
  cardId: { type: [Number, String], required: true },
  boardId: { type: [Number, String], default: null },
})

const emit = defineEmits(['card-click'])

const hubStore = useProjectHubStore()
const boardsStore = useBoardsStore()

const waitingOn = ref([])
const blocking = ref([])
const loading = ref(false)
const showAdd = ref(false)
const searchQuery = ref('')
const searchResults = ref([])
const searching = ref(false)
const newDepType = ref('finish_to_start')

const depTypes = [
  { value: 'finish_to_start', label: 'Finish to Start', desc: 'Must finish before this starts', icon: 'arrow_forward' },
  { value: 'start_to_start', label: 'Start to Start', desc: 'Must start before this starts', icon: 'start' },
  { value: 'finish_to_finish', label: 'Finish to Finish', desc: 'Must finish before this finishes', icon: 'done_all' },
]

onMounted(fetchDependencies)

async function fetchDependencies() {
  loading.value = true
  try {
    const data = await hubStore.fetchCardDependencies(props.cardId)
    waitingOn.value = data.waiting_on || []
    blocking.value = data.blocking || []
  } catch (err) {
    console.error('Failed to fetch dependencies:', err)
  } finally {
    loading.value = false
  }
}

async function searchCards() {
  if (searchQuery.value.length < 2) {
    searchResults.value = []
    return
  }
  searching.value = true
  try {
    const params = { q: searchQuery.value }
    if (props.boardId) params.board_id = props.boardId
    const { data } = await api.get('/boards/search', { params })
    const cards = data.data?.cards || data.cards || []
    searchResults.value = cards.filter(c => c.id !== Number(props.cardId))
  } catch {
    try {
      const board = boardsStore.currentBoard
      if (board?.lists) {
        const allCards = board.lists.flatMap(l => l.cards || [])
        searchResults.value = allCards
          .filter(c =>
            c.id !== Number(props.cardId) &&
            c.title.toLowerCase().includes(searchQuery.value.toLowerCase())
          )
          .slice(0, 10)
      }
    } catch { searchResults.value = [] }
  } finally {
    searching.value = false
  }
}

async function addDependency(targetCardId) {
  try {
    await hubStore.createCardDependency(props.cardId, targetCardId, newDepType.value)
    await fetchDependencies()
    showAdd.value = false
    searchQuery.value = ''
    searchResults.value = []
  } catch (err) {
    console.error('Failed to add dependency:', err)
  }
}

async function removeDependency(depId) {
  try {
    await hubStore.deleteCardDependency(depId, props.cardId)
    await fetchDependencies()
  } catch (err) {
    console.error('Failed to remove dependency:', err)
  }
}

function getDepTypeLabel(type) {
  return depTypes.find(d => d.value === type)?.label || type
}

function getDepTypeIcon(type) {
  return depTypes.find(d => d.value === type)?.icon || 'link'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-2">
      <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
        <span class="material-symbols-rounded text-lg">account_tree</span>
        Dependencies
      </h4>
      <button
        class="w-6 h-6 flex items-center justify-center rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        @click="showAdd = !showAdd"
      >
        <span class="material-symbols-rounded text-[16px] text-surface-500">{{ showAdd ? 'close' : 'add' }}</span>
      </button>
    </div>

    <!-- Add dependency form -->
    <div v-if="showAdd" class="mb-3 p-3 rounded-xl bg-surface-50 dark:bg-surface-700/50">
      <!-- Type selector -->
      <div class="flex flex-col gap-1 mb-2">
        <button
          v-for="dt in depTypes"
          :key="dt.value"
          class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs transition-all text-left"
          :class="newDepType === dt.value
            ? 'bg-primary-500 text-white'
            : 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-500'"
          @click="newDepType = dt.value"
        >
          <span class="material-symbols-rounded text-[14px]">{{ dt.icon }}</span>
          <span class="font-medium">{{ dt.label }}</span>
          <span class="text-[10px] opacity-75 ml-auto">{{ dt.desc }}</span>
        </button>
      </div>

      <!-- Search for card -->
      <div class="relative">
        <input
          v-model="searchQuery"
          class="w-full px-3 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm outline-none focus:ring-2 focus:ring-primary-500 text-surface-800 dark:text-surface-200"
          placeholder="Search for a task..."
          @input="searchCards"
        />

        <!-- Search results dropdown -->
        <div
          v-if="searchResults.length > 0"
          class="absolute z-10 top-full mt-1 left-0 right-0 bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200 dark:border-surface-700 max-h-48 overflow-y-auto"
        >
          <button
            v-for="card in searchResults"
            :key="card.id"
            class="w-full flex items-center gap-2 px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-700 text-left text-sm"
            @click="addDependency(card.id)"
          >
            <span
              class="w-3 h-3 rounded-sm shrink-0"
              :class="card.completed ? 'bg-green-500' : 'border border-surface-300'"
            ></span>
            <span class="truncate text-surface-800 dark:text-surface-200">{{ card.title }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="py-2 text-center">
      <span class="material-symbols-rounded animate-spin text-primary-500">progress_activity</span>
    </div>

    <!-- Dependency list -->
    <div v-else>
      <!-- "Waiting on" (blocked by) -->
      <div v-if="waitingOn.length > 0" class="mb-2">
        <p class="text-[10px] font-semibold text-surface-400 uppercase tracking-wider mb-1">Blocked by</p>
        <div class="space-y-1">
          <div
            v-for="dep in waitingOn"
            :key="dep.id"
            class="group flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
          >
            <span class="material-symbols-rounded text-[14px] text-amber-500">{{ getDepTypeIcon(dep.type) }}</span>
            <span
              class="text-sm text-surface-700 dark:text-surface-300 truncate flex-1 cursor-pointer hover:text-primary-500"
              @click="emit('card-click', dep.card_id)"
            >
              {{ dep.card_title || `Card #${dep.card_id}` }}
            </span>
            <span class="text-[10px] text-surface-400 hidden sm:inline">{{ getDepTypeLabel(dep.type) }}</span>
            <button
              class="w-5 h-5 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-900/20 transition-all shrink-0"
              @click="removeDependency(dep.id)"
            >
              <span class="material-symbols-rounded text-[14px] text-red-500">close</span>
            </button>
          </div>
        </div>
      </div>

      <!-- "Blocking" -->
      <div v-if="blocking.length > 0" class="mb-2">
        <p class="text-[10px] font-semibold text-surface-400 uppercase tracking-wider mb-1">Blocking</p>
        <div class="space-y-1">
          <div
            v-for="dep in blocking"
            :key="dep.id"
            class="group flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
          >
            <span class="material-symbols-rounded text-[14px] text-red-500">{{ getDepTypeIcon(dep.type) }}</span>
            <span
              class="text-sm text-surface-700 dark:text-surface-300 truncate flex-1 cursor-pointer hover:text-primary-500"
              @click="emit('card-click', dep.card_id)"
            >
              {{ dep.card_title || `Card #${dep.card_id}` }}
            </span>
            <span class="text-[10px] text-surface-400 hidden sm:inline">{{ getDepTypeLabel(dep.type) }}</span>
            <button
              class="w-5 h-5 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-900/20 transition-all shrink-0"
              @click="removeDependency(dep.id)"
            >
              <span class="material-symbols-rounded text-[14px] text-red-500">close</span>
            </button>
          </div>
        </div>
      </div>

      <div v-if="!blocking.length && !waitingOn.length" class="text-xs text-surface-400 italic py-1">
        No dependencies
      </div>
    </div>
  </div>
</template>
