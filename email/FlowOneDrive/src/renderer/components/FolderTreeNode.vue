<script setup lang="ts">
import { computed } from 'vue'
import { useFilesStore } from '../stores/files'

interface Folder {
  remoteId: number
  remoteParentId: number | null
  name: string
  color?: string
}

const props = defineProps<{
  folder: Folder
  depth: number
  currentFolderId: number | null
  expandedFolders: Set<number>
  isTrash: boolean
  getFolderColor: (folder: Folder) => string
  // Optional: kept for backward compat with callers that still pass these,
  // but the component now reads directly from the memoized store maps.
  getChildFolders?: (parentId: number) => Folder[]
  hasChildFolders?: (folderId: number) => boolean
}>()

const emit = defineEmits<{
  'toggle-expand': [folderId: number, event?: MouseEvent]
  'navigate': [folderId: number, folderName: string]
}>()

// Wave C.2: read directly from precomputed Map / Set in the store. This
// turns the per-node O(n) array filter / .some() into O(1) lookups.
const filesStore = useFilesStore()

const isExpanded = computed(() => props.expandedFolders.has(props.folder.remoteId))
const hasChildren = computed(() => filesStore.hasChildFolders(props.folder.remoteId))
const childFolders = computed(() => filesStore.getChildFolders(props.folder.remoteId) as Folder[])
const isActive = computed(() => !props.isTrash && props.currentFolderId === props.folder.remoteId)

function handleToggle(event: MouseEvent) {
  event.stopPropagation()
  emit('toggle-expand', props.folder.remoteId, event)
}

function handleNavigate() {
  emit('navigate', props.folder.remoteId, props.folder.name)
}
</script>

<template>
  <div>
    <button
      @click="handleNavigate"
      :style="[
        isActive ? 'background: rgba(22,163,74,0.15); color: #22c55e;' : 'color: var(--text-muted);',
        { paddingLeft: (8 + depth * 12) + 'px' }
      ]"
      style="width: 100%; display: flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 6px; text-align: left; font-size: 13px;"
      class="hover:bg-[--bg-elevated] transition-colors group"
    >
      <!-- Expand/collapse chevron -->
      <span 
        v-if="hasChildren"
        @click="handleToggle"
        class="material-symbols-rounded cursor-pointer hover:text-white"
        style="font-size: 16px; transition: transform 0.15s ease;"
        :style="{ transform: isExpanded ? 'rotate(90deg)' : 'rotate(0deg)' }"
      >
        chevron_right
      </span>
      <span v-else style="width: 16px;"></span>
      
      <!-- Folder icon -->
      <span 
        class="material-symbols-rounded" 
        :style="'font-size: 18px; color: ' + getFolderColor(folder)"
      >
        {{ isExpanded && hasChildren ? 'folder_open' : 'folder' }}
      </span>
      
      <!-- Folder name -->
      <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
        {{ folder.name }}
      </span>
    </button>
    
    <!-- Child folders (recursive) -->
    <div v-if="isExpanded && hasChildren">
      <FolderTreeNode
        v-for="child in childFolders"
        :key="child.remoteId"
        :folder="child"
        :depth="depth + 1"
        :current-folder-id="currentFolderId"
        :expanded-folders="expandedFolders"
        :is-trash="isTrash"
        :get-folder-color="getFolderColor"
        @toggle-expand="(id, e) => $emit('toggle-expand', id, e)"
        @navigate="(id, name) => $emit('navigate', id, name)"
      />
    </div>
  </div>
</template>

