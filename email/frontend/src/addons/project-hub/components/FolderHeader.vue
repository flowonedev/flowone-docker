<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { fetchUnseenCount } from '@/addons/project-hub/services/projectHubFileApi'

const LinkBoardDialog = defineAsyncComponent(() => import('./LinkBoardDialog.vue'))

const emit = defineEmits(['switch-tab'])

const hubStore = useProjectHubStore()

const overview = computed(() => hubStore.folderOverview)
const loading = computed(() => hubStore.folderOverviewLoading)
const folder = computed(() => hubStore.activeFolder)
const showLinkDialog = ref(false)
const unseenFileCount = ref(0)

const hasBoards = computed(() => (overview.value?.boards?.length || 0) > 0)
const boardCount = computed(() => overview.value?.boards?.length || 0)
const bookmarks = computed(() => overview.value?.bookmarks || [])

watch(() => folder.value?.id, async (fid) => {
  if (!fid) return
  try {
    unseenFileCount.value = await fetchUnseenCount(fid)
  } catch { unseenFileCount.value = 0 }
}, { immediate: true })

function handleBoardLinked() {
  if (folder.value?.id) {
    hubStore.fetchFolderOverview(folder.value.id)
    hubStore.fetchHierarchy()
  }
}
</script>

<template>
  <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900">
    <!-- Folder name + actions -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <span class="material-symbols-rounded text-2xl text-primary-500">folder</span>
        <div>
          <h2 class="text-lg font-bold text-surface-800 dark:text-surface-100">{{ folder?.name }}</h2>
          <div class="text-xs text-surface-400 mt-0.5 flex items-center gap-2">
            <span v-if="hasBoards" class="inline-flex items-center gap-1">
              <span class="material-symbols-rounded text-[13px]">dashboard</span>
              {{ boardCount }} board{{ boardCount !== 1 ? 's' : '' }}
            </span>
            <span
              v-if="unseenFileCount > 0"
              class="inline-flex items-center gap-1 text-primary-500 font-medium"
            >
              <span class="material-symbols-rounded text-[13px]">folder_open</span>
              {{ unseenFileCount }} new file{{ unseenFileCount !== 1 ? 's' : '' }}
            </span>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-900/40 transition-colors"
          @click="showLinkDialog = true"
        >
          <span class="material-symbols-rounded text-[16px]">link</span>
          {{ hasBoards ? 'Manage Boards' : 'Link Boards' }}
        </button>
      </div>
    </div>

    <!-- Bookmarks row -->
    <div v-if="bookmarks.length > 0" class="flex items-center gap-2 mt-3 flex-wrap">
      <a
        v-for="bm in bookmarks"
        :key="bm.id"
        :href="bm.url"
        target="_blank"
        rel="noopener"
        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-surface-100 dark:bg-surface-700 text-xs text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
      >
        <span class="material-symbols-rounded text-[14px]">bookmark</span>
        {{ bm.title }}
      </a>
    </div>

    <!-- Link Board Dialog -->
    <Teleport to="body">
      <LinkBoardDialog
        v-if="showLinkDialog && folder?.id"
        :folder-id="folder.id"
        :folder-name="folder.name"
        @close="showLinkDialog = false"
        @linked="handleBoardLinked"
      />
    </Teleport>
  </div>
</template>
