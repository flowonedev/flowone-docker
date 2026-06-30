<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'

const props = defineProps({
  folder: { type: Object, default: null },
  spaceId: { type: [Number, String], required: true },
})

const emit = defineEmits(['close', 'saved'])

const hubStore = useProjectHubStore()

const name = ref(props.folder?.name || '')
const color = ref(props.folder?.color || '')
const saving = ref(false)
const availableBoards = ref([])
const linkedBoardIds = ref([])
const loadingBoards = ref(false)

const isEdit = computed(() => !!props.folder?.id)

watch(() => props.folder, (f) => {
  if (f) {
    name.value = f.name || ''
    color.value = f.color || ''
  }
})

onMounted(async () => {
  if (isEdit.value) {
    loadingBoards.value = true
    try {
      const boards = await hubStore.fetchFolderBoards(props.folder.id)
      linkedBoardIds.value = boards.map(b => b.board_id || b.id)
    } catch { /* ignore */ } finally {
      loadingBoards.value = false
    }
  }
})

async function save() {
  if (!name.value.trim()) return
  saving.value = true
  try {
    if (isEdit.value) {
      await hubStore.updateFolder(props.folder.id, { name: name.value.trim(), color: color.value })
    } else {
      await hubStore.createFolder(props.spaceId, { name: name.value.trim(), color: color.value })
    }
    emit('saved')
    emit('close')
  } catch (err) {
    console.error('Failed to save folder:', err)
  } finally {
    saving.value = false
  }
}

async function handleDelete() {
  if (!props.folder?.id) return
  if (!confirm('Delete this folder? Boards inside will become unsorted.')) return
  try {
    await hubStore.deleteFolder(props.folder.id)
    emit('close')
  } catch (err) {
    console.error('Failed to delete folder:', err)
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
      <h3 class="text-lg font-bold text-surface-800 dark:text-surface-100 mb-4">
        {{ isEdit ? 'Edit Folder' : 'New Folder' }}
      </h3>

      <!-- Name -->
      <div class="mb-4">
        <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1">Folder Name</label>
        <input
          v-model="name"
          class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-2 focus:ring-primary-500"
          placeholder="Folder name"
          @keydown.enter="save"
          autofocus
        />
      </div>

      <!-- Linked boards (read-only summary for edit mode) -->
      <div v-if="isEdit" class="mb-4">
        <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1">Linked Boards</label>
        <div v-if="loadingBoards" class="text-sm text-surface-400">Loading...</div>
        <div v-else-if="linkedBoardIds.length === 0" class="text-sm text-surface-400 italic">No boards linked yet</div>
        <div v-else class="text-sm text-surface-600 dark:text-surface-300">
          {{ linkedBoardIds.length }} board{{ linkedBoardIds.length !== 1 ? 's' : '' }} linked
        </div>
        <p class="text-xs text-surface-400 mt-1">Drag boards into this folder from the sidebar to link them</p>
      </div>

      <!-- Actions -->
      <div class="flex items-center justify-between mt-6">
        <button
          v-if="isEdit"
          class="text-sm text-red-500 hover:text-red-600 transition-colors"
          @click="handleDelete"
        >
          Delete folder
        </button>
        <div v-else></div>

        <div class="flex items-center gap-2">
          <button
            class="px-4 py-2 rounded-full text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            @click="emit('close')"
          >
            Cancel
          </button>
          <button
            class="px-4 py-2 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50"
            :disabled="!name.trim() || saving"
            @click="save"
          >
            {{ saving ? 'Saving...' : (isEdit ? 'Save' : 'Create') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
