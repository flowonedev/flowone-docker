<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToastStore } from '@/stores/toast'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import {
  fetchGroupAccess,
  addFileGroupAccess,
  removeGroupAccess as apiRemoveGroupAccess,
} from '@/services/driveShareApi'

const props = defineProps({
  itemId: { type: Number, required: true },
  targetType: { type: String, default: 'file' }, // 'file' | 'folder'
})

const emit = defineEmits(['changed'])

const toast = useToastStore()
const colleaguesStore = useColleaguesStore()

const groupAccess = ref([])
const selectedGroupId = ref(null)
const groupPermission = ref('viewer')
const adding = ref(false)

const isFolder = computed(() => props.targetType === 'folder')

const availableGroups = computed(() => {
  const sharedGroupIds = groupAccess.value.map(g => g.group_id)
  return colleaguesStore.sortedGroups.filter(g => !sharedGroupIds.includes(g.id))
})

async function load() {
  groupAccess.value = await fetchGroupAccess(props.targetType, props.itemId)
}

async function addGroup() {
  if (!selectedGroupId.value) return

  adding.value = true
  try {
    const result = isFolder.value
      ? await colleaguesStore.shareFolderWithGroup(selectedGroupId.value, props.itemId, groupPermission.value)
      : await addFileGroupAccess(props.itemId, selectedGroupId.value, groupPermission.value)

    if (result.success) {
      toast.success('Shared with group')
      selectedGroupId.value = null
      await load()
      emit('changed')
    } else {
      toast.error(result.error || 'Failed to share with group')
    }
  } finally {
    adding.value = false
  }
}

async function removeGroup(groupId) {
  if (await apiRemoveGroupAccess(props.targetType, props.itemId, groupId)) {
    toast.success('Group access removed')
    await load()
    emit('changed')
  } else {
    toast.error('Failed to remove group access')
  }
}

onMounted(async () => {
  await Promise.all([load(), colleaguesStore.fetchGroups()])
})
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-surface-600 dark:text-surface-400">
      Share this {{ isFolder ? 'folder' : 'file' }} with your team groups. All group members will have access.
    </p>

    <!-- Add group form -->
    <div class="flex gap-2">
      <select v-model="selectedGroupId" class="input flex-1">
        <option :value="null" disabled>Select a group...</option>
        <option v-for="group in availableGroups" :key="group.id" :value="group.id">
          {{ group.name }} ({{ group.member_count || 0 }} members)
        </option>
      </select>
      <select v-model="groupPermission" class="input w-28">
        <option value="viewer">Viewer</option>
        <option value="editor">Editor</option>
      </select>
      <button
        @click="addGroup"
        :disabled="adding || !selectedGroupId"
        class="btn-primary px-4"
      >
        <span v-if="adding" class="material-symbols-rounded animate-spin">progress_activity</span>
        <span v-else class="material-symbols-rounded">group_add</span>
      </button>
    </div>

    <div class="text-xs text-surface-500 flex items-center gap-1">
      <span class="material-symbols-rounded text-sm">info</span>
      <span>All members of the selected group will automatically have access.</span>
    </div>

    <!-- Group access list -->
    <div v-if="groupAccess.length === 0" class="text-center py-6 text-surface-500">
      <span class="material-symbols-rounded text-4xl mb-2">groups</span>
      <p>No groups have access yet</p>
    </div>

    <div v-else class="space-y-2 max-h-60 overflow-y-auto">
      <div
        v-for="access in groupAccess"
        :key="access.group_id"
        class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-900/50 rounded-lg"
      >
        <div
          class="w-8 h-8 rounded-lg flex items-center justify-center"
          :style="{ backgroundColor: (access.group_color || '#6366f1') + '20', color: access.group_color || '#6366f1' }"
        >
          <span class="material-symbols-rounded text-sm">{{ access.group_icon || 'group' }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100">
            {{ access.group_name }}
          </p>
          <p class="text-xs text-surface-500">
            {{ access.member_count || 0 }} members
          </p>
        </div>
        <span
          class="text-xs px-2 py-0.5 rounded-full"
          :class="access.permission === 'editor'
            ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400'
            : 'bg-surface-200 text-surface-600 dark:bg-surface-700 dark:text-surface-400'"
        >
          {{ access.permission === 'editor' ? 'Editor' : 'Viewer' }}
        </span>
        <button
          @click="removeGroup(access.group_id)"
          class="p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg"
          title="Remove access"
        >
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>
    </div>
  </div>
</template>
