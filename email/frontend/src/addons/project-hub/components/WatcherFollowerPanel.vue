<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'

const props = defineProps({
  cardId: { type: [Number, String], required: true },
})

const authStore = useAuthStore()
const colleaguesStore = useColleaguesStore()

const watchers = ref([])
const isWatching = ref(false)
const loading = ref(false)

onMounted(loadWatchers)

async function loadWatchers() {
  loading.value = true
  try {
    const { data } = await api.get(`/project-hub/cards/${props.cardId}/watchers`)
    watchers.value = data.watchers || []
    isWatching.value = data.is_watching || false
  } finally {
    loading.value = false
  }
}

async function toggleWatch() {
  try {
    if (isWatching.value) {
      await api.delete(`/project-hub/cards/${props.cardId}/watchers`)
    } else {
      await api.post(`/project-hub/cards/${props.cardId}/watchers`)
    }
    await loadWatchers()
  } catch (err) {
    console.error('Failed to toggle watch:', err)
  }
}

function getName(email) {
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  return c?.display_name || email?.split('@')[0] || email
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-2">
      <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide flex items-center gap-1.5">
        <span class="material-symbols-rounded text-[16px]">visibility</span>
        Watchers
        <span v-if="watchers.length" class="text-[10px] bg-surface-200 dark:bg-surface-600 rounded-full px-1.5 py-0.5">{{ watchers.length }}</span>
      </h4>
      <button
        class="px-3 py-1 rounded-full text-xs font-medium transition-colors flex items-center gap-1"
        :class="isWatching
          ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
          : 'bg-surface-100 dark:bg-surface-700 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'"
        @click="toggleWatch"
      >
        <span class="material-symbols-rounded text-[14px]">{{ isWatching ? 'visibility_off' : 'visibility' }}</span>
        {{ isWatching ? 'Unwatch' : 'Watch' }}
      </button>
    </div>

    <div v-if="loading" class="text-xs text-surface-400 py-1">Loading...</div>

    <div v-else-if="watchers.length > 0" class="flex flex-wrap gap-1">
      <span
        v-for="w in watchers"
        :key="w.id"
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400"
        :title="w.user_email"
      >
        <span class="material-symbols-rounded text-[12px]">person</span>
        {{ getName(w.user_email) }}
      </span>
    </div>

    <div v-else class="text-xs text-surface-400 italic py-1">No watchers</div>
  </div>
</template>
