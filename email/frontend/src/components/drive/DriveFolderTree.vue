<script setup>
import DriveFolderTreeNode from '@/components/drive/DriveFolderTreeNode.vue'

defineProps({
  folders: { type: Array, required: true },
  currentFolderId: { type: [Number, String, null], default: null },
  expanded: { type: Object, required: true },
  dragOverFolder: { type: [Number, String, null], default: null },
  dragOverPosition: { type: String, default: '' },
  draggingFolder: { type: Object, default: null },
  // getFolderColor is passed as a function so the parent stays the single
  // source of truth for sidebar folder colour resolution.
  getFolderColor: { type: Function, required: true },
})

const emit = defineEmits([
  'select',
  'toggle',
  'create-subfolder',
  'context-menu',
  'drag-start',
  'drag-end',
  'drag-over-folder',
  'drag-leave-folder',
  'drop-on-folder',
  'touch-start',
  'touch-move',
  'touch-end',
])
</script>

<template>
  <div class="space-y-0.5">
    <DriveFolderTreeNode
      v-for="folder in folders"
      :key="folder.id"
      :folder="folder"
      :depth="0"
      :current-folder-id="currentFolderId"
      :expanded="expanded"
      :drag-over-folder="dragOverFolder"
      :drag-over-position="dragOverPosition"
      :dragging-folder="draggingFolder"
      :get-folder-color="getFolderColor"
      @select="(id) => emit('select', id)"
      @toggle="(id) => emit('toggle', id)"
      @create-subfolder="(id) => emit('create-subfolder', id)"
      @context-menu="(e, f) => emit('context-menu', e, f)"
      @drag-start="(e, f) => emit('drag-start', e, f)"
      @drag-end="emit('drag-end')"
      @drag-over-folder="(e, f, d) => emit('drag-over-folder', e, f, d)"
      @drag-leave-folder="emit('drag-leave-folder')"
      @drop-on-folder="(e, f) => emit('drop-on-folder', e, f)"
      @touch-start="(e, f) => emit('touch-start', e, f)"
      @touch-move="(e) => emit('touch-move', e)"
      @touch-end="(e) => emit('touch-end', e)"
    />
  </div>
</template>
