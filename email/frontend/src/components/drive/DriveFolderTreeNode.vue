<script setup>
// Recursive folder-tree node. Renders a single folder row and, when
// expanded, recursively renders its children at any depth (Windows
// Explorer-style). Events bubble up through each level to the parent.
defineProps({
  folder: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  currentFolderId: { type: [Number, String, null], default: null },
  expanded: { type: Object, required: true },
  dragOverFolder: { type: [Number, String, null], default: null },
  dragOverPosition: { type: String, default: '' },
  draggingFolder: { type: Object, default: null },
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

function rowClasses(isActive) {
  return [
    'relative flex items-center gap-1 pr-2 py-1.5 rounded-lg cursor-pointer transition-all text-sm group',
    isActive
      ? 'bg-primary-50 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400'
      : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300',
  ]
}
</script>

<template>
  <div class="folder-tree-item">
    <div
      draggable="true"
      :style="{ paddingLeft: (depth * 16 + 8) + 'px' }"
      @dragstart="emit('drag-start', $event, folder)"
      @dragend="emit('drag-end')"
      @dragover="emit('drag-over-folder', $event, folder, depth)"
      @dragleave="emit('drag-leave-folder')"
      @drop="emit('drop-on-folder', $event, folder)"
      @contextmenu="emit('context-menu', $event, folder)"
      @touchstart="emit('touch-start', $event, folder)"
      @touchmove="emit('touch-move', $event)"
      @touchend="emit('touch-end', $event)"
      :class="[
        ...rowClasses(currentFolderId === folder.id),
        dragOverFolder === folder.id && dragOverPosition === 'inside' ? 'ring-2 ring-primary-500 bg-primary-100 dark:bg-primary-500/30' : '',
        draggingFolder?.id === folder.id ? 'opacity-50' : '',
      ]"
    >
      <div
        v-if="dragOverFolder === folder.id && dragOverPosition === 'before'"
        class="absolute -top-0.5 left-0 right-0 h-0.5 bg-primary-500 rounded-full"
      ></div>
      <div
        v-if="dragOverFolder === folder.id && dragOverPosition === 'after'"
        class="absolute -bottom-0.5 left-0 right-0 h-0.5 bg-primary-500 rounded-full"
      ></div>

      <button
        v-if="folder.children?.length > 0"
        @click.stop="emit('toggle', folder.id)"
        class="w-5 h-5 flex-shrink-0 inline-flex items-center justify-center hover:bg-surface-200 dark:hover:bg-surface-600 rounded"
      >
        <span class="material-symbols-rounded text-sm">
          {{ expanded[folder.id] ? 'expand_more' : 'chevron_right' }}
        </span>
      </button>
      <span v-else class="w-5 h-5 flex-shrink-0"></span>

      <button
        @click="emit('select', folder.id)"
        class="flex-1 flex items-center gap-2 text-left min-w-0"
      >
        <span
          :class="[
            'material-symbols-rounded icon-filled text-lg flex-shrink-0',
            dragOverFolder === folder.id ? 'text-primary-500' : getFolderColor(folder),
          ]"
        >
          {{ dragOverFolder === folder.id && dragOverPosition === 'inside' ? 'folder_open' : 'folder' }}
        </span>
        <span class="truncate">{{ folder.name }}</span>
      </button>

      <button
        @click.stop="emit('create-subfolder', folder.id)"
        class="opacity-0 group-hover:opacity-100 w-5 h-5 flex-shrink-0 inline-flex items-center justify-center hover:bg-surface-200 dark:hover:bg-surface-600 rounded transition-opacity"
        :title="$t('driveView.createSubfolder')"
      >
        <span class="material-symbols-rounded text-sm">add</span>
      </button>
    </div>

    <!-- Children (recursive, any depth) -->
    <div v-if="expanded[folder.id] && folder.children?.length > 0" class="space-y-0.5 mt-0.5">
      <DriveFolderTreeNode
        v-for="child in folder.children"
        :key="child.id"
        :folder="child"
        :depth="depth + 1"
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
  </div>
</template>
