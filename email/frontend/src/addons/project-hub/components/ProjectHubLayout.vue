<script setup>
import { defineAsyncComponent } from 'vue'
import { useRouter } from 'vue-router'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import AppHeader from '@/components/shared/AppHeader.vue'

const ProjectHubSidebar = defineAsyncComponent(() => import('./ProjectHubSidebar.vue'))

defineProps({
  title: { type: String, required: true },
  icon: { type: String, default: 'hub' },
})

const router = useRouter()
const hubStore = useProjectHubStore()
const boardsStore = useBoardsStore()

function handleBoardSelect(boardId) {
  router.push({ name: 'boards', query: { board: boardId } })
}

function handleFolderSelect(folder) {
  hubStore.selectFolder(folder)
  router.push({ name: 'boards' })
}

function handleMyWork() {
  hubStore.selectMyWork()
  router.push({ name: 'boards' })
}
</script>

<template>
  <div class="flex flex-col h-full bg-surface-50 dark:bg-surface-900">
    <AppHeader
      current-view="boards"
      :icon="icon"
      :title="title"
    >
      <template #title-badge>
        <slot name="header-actions" />
      </template>
    </AppHeader>

    <div class="flex-1 flex overflow-hidden">
      <ProjectHubSidebar
        @select-board="handleBoardSelect"
        @select-folder="handleFolderSelect"
        @select-my-work="handleMyWork"
      />

      <div class="flex-1 overflow-auto">
        <slot />
      </div>
    </div>
  </div>
</template>
