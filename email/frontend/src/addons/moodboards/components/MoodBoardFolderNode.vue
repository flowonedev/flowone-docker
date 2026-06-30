<template>
  <div class="folder-node">
    <!-- Folder header row -->
    <div
      :class="[
        'flex items-center gap-1 px-2 py-1.5 rounded-lg cursor-pointer transition-all group select-none',
        dragOver ? 'bg-primary-100 dark:bg-primary-900/30 ring-2 ring-primary-400' : 'hover:bg-surface-50 dark:hover:bg-surface-800'
      ]"
      :style="{ paddingLeft: (depth * 16 + 8) + 'px' }"
      @click="store.toggleFolder(folder.id)"
      @contextmenu.prevent="$emit('folder-context', $event, folder)"
      @dragover.prevent="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <span class="material-symbols-rounded text-base text-surface-400 dark:text-surface-500 transition-transform" :class="{ 'rotate-90': isExpanded }">
        chevron_right
      </span>
      <span class="material-symbols-rounded text-lg" :class="folder.color ? '' : 'text-amber-500 dark:text-amber-400'" :style="folder.color ? { color: folder.color } : {}">
        {{ isExpanded ? 'folder_open' : 'folder' }}
      </span>
      <span class="flex-1 text-sm font-medium text-surface-700 dark:text-surface-200 truncate">
        {{ folder.name }}
      </span>
      <span class="text-xs text-surface-400 dark:text-surface-500 opacity-0 group-hover:opacity-100 transition-opacity">
        {{ totalCount }}
      </span>
    </div>

    <!-- Children (sub-folders + boards) shown when expanded -->
    <div v-if="isExpanded" class="folder-children">
      <!-- Sub-folders (recursive) -->
      <MoodBoardFolderNode
        v-for="child in folder.children"
        :key="'f-' + child.id"
        :folder="child"
        :selected-id="selectedId"
        :depth="depth + 1"
        @select="$emit('select', $event)"
        @folder-context="(ev, f) => $emit('folder-context', ev, f)"
        @board-context="(ev, b) => $emit('board-context', ev, b)"
      />

      <!-- Boards in this folder -->
      <button
        v-for="board in folder.boards"
        :key="'b-' + board.id"
        draggable="true"
        @dragstart="onBoardDragStart($event, board)"
        @click="$emit('select', board.id)"
        @contextmenu.prevent="$emit('board-context', $event, board)"
        :class="[
          'w-full text-left py-2 px-3 rounded-xl transition-all group/board',
          selectedId === board.id
            ? 'bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800'
            : 'hover:bg-surface-50 dark:hover:bg-surface-800 border border-transparent'
        ]"
        :style="{ paddingLeft: ((depth + 1) * 16 + 8) + 'px' }"
      >
        <div class="flex items-start gap-3">
          <div
            class="w-8 h-8 rounded-lg flex-shrink-0 flex items-center justify-center"
            :style="{ backgroundColor: board.background_color || '#f5f5f5' }"
          >
            <span class="material-symbols-rounded text-sm" :class="getBoardIconColor(board.background_color)">
              dashboard_customize
            </span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
              {{ board.name }}
            </p>
            <div class="flex items-center gap-2 mt-0.5">
              <span class="text-xs text-surface-500 dark:text-surface-400">
                {{ board.item_count || 0 }} items
              </span>
              <span v-if="board.is_ready" class="text-xs text-green-600 dark:text-green-400 flex items-center gap-0.5 font-medium">
                <span class="material-symbols-rounded text-xs">check_circle</span>
                Ready
              </span>
            </div>
          </div>
        </div>
      </button>

      <!-- Empty folder hint -->
      <div
        v-if="folder.children.length === 0 && folder.boards.length === 0"
        class="text-xs text-surface-400 dark:text-surface-500 italic py-1"
        :style="{ paddingLeft: ((depth + 1) * 16 + 12) + 'px' }"
      >
        Empty folder
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const props = defineProps({
  folder: { type: Object, required: true },
  selectedId: { type: Number, default: null },
  depth: { type: Number, default: 0 },
})

defineEmits(['select', 'folder-context', 'board-context'])

const store = useMoodBoardsStore()
const dragOver = ref(false)

const isExpanded = computed(() => store.expandedFolderIds.has(props.folder.id))

const totalCount = computed(() => {
  let count = props.folder.boards.length
  for (const child of props.folder.children) {
    count += countAll(child)
  }
  return count
})

function countAll(node) {
  let n = node.boards.length
  for (const c of node.children) n += countAll(c)
  return n
}

function getBoardIconColor(bgColor) {
  if (!bgColor) return 'text-surface-500'
  const hex = bgColor.replace('#', '')
  if (hex.length >= 6) {
    const r = parseInt(hex.substr(0, 2), 16)
    const g = parseInt(hex.substr(2, 2), 16)
    const b = parseInt(hex.substr(4, 2), 16)
    const brightness = (r * 299 + g * 587 + b * 114) / 1000
    return brightness < 128 ? 'text-white/70' : 'text-surface-600'
  }
  return 'text-surface-500'
}

function onBoardDragStart(event, board) {
  event.dataTransfer.setData('application/mood-board-id', String(board.id))
  event.dataTransfer.effectAllowed = 'move'
}

function onDragOver(event) {
  if (event.dataTransfer.types.includes('application/mood-board-id')) {
    dragOver.value = true
    event.dataTransfer.dropEffect = 'move'
  }
}

function onDragLeave() {
  dragOver.value = false
}

function onDrop(event) {
  dragOver.value = false
  const boardId = event.dataTransfer.getData('application/mood-board-id')
  if (boardId) {
    store.moveBoard(parseInt(boardId), props.folder.id)
  }
}
</script>
