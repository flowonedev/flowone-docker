<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { defineAsyncComponent } from 'vue'
import ProjectHubLayout from '@/addons/project-hub/components/ProjectHubLayout.vue'

const CardModal = defineAsyncComponent(() => import('@/addons/kanban-boards/components/CardModal.vue'))

const route = useRoute()
const router = useRouter()

const cardId = computed(() => parseInt(route.params.cardId))
const cardObj = computed(() => ({ id: cardId.value }))

function goBack() {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.push({ name: 'workload', query: { mode: 'my-work' } })
  }
}
</script>

<template>
  <ProjectHubLayout title="Work Hub" icon="monitoring">
    <div class="flex flex-col h-full">
      <div class="flex items-center gap-2 px-4 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0">
        <button
          @click="goBack"
          class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
          title="Back"
        >
          <span class="material-symbols-rounded text-lg">arrow_back</span>
        </button>
        <span class="text-sm text-surface-500">Back to Work Hub</span>
      </div>
      <div class="flex-1 overflow-hidden">
        <CardModal
          v-if="cardId"
          :card="cardObj"
          :inline-mode="true"
          @close="goBack"
        />
      </div>
    </div>
  </ProjectHubLayout>
</template>
