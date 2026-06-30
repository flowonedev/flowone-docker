<script setup>
const props = defineProps({
  contextMenu: { type: Object, required: true },
})

const emit = defineEmits([
  'close',
  'favorite-space', 'edit-space', 'assign-client', 'add-folder', 'view-space-time', 'delete-space',
  'edit-folder', 'new-board', 'link-board', 'duplicate-folder', 'view-folder-time', 'delete-folder',
  'rename-board', 'archive-board', 'unlink-board', 'delete-board',
])
</script>

<template>
  <Teleport to="body">
    <div
      v-if="contextMenu.show"
      class="fixed inset-0 z-[9999]"
      @click="emit('close')"
      @contextmenu.prevent="emit('close')"
    >
      <div
        class="absolute bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-lg py-1 min-w-[160px]"
        :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
      >
        <template v-if="contextMenu.type === 'space'">
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('favorite-space', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">{{ contextMenu.item?.is_favorite ? 'star' : 'star_border' }}</span>
            {{ contextMenu.item?.is_favorite ? 'Unfavorite' : 'Favorite' }}
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('edit-space', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">palette</span>
            Color & Icon
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('assign-client', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">person</span>
            {{ contextMenu.item?.client_name ? 'Change Client' : 'Assign Client' }}
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('add-folder', contextMenu.item.id)">
            <span class="material-symbols-rounded text-[16px]">create_new_folder</span>
            Add Folder
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('view-space-time', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">schedule</span>
            View Time
          </button>
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20" @click="emit('delete-space', contextMenu.item.id)">
            <span class="material-symbols-rounded text-[16px]">delete</span>
            Delete Space
          </button>
        </template>

        <template v-if="contextMenu.type === 'folder'">
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('edit-folder', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">edit</span>
            Edit Folder
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('new-board', contextMenu.item.id)">
            <span class="material-symbols-rounded text-[16px]">add</span>
            New Board
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('link-board', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">link</span>
            Link Existing Board
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('duplicate-folder', contextMenu.item.id)">
            <span class="material-symbols-rounded text-[16px]">content_copy</span>
            Duplicate Folder
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('view-folder-time', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">schedule</span>
            View Time
          </button>
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20" @click="emit('delete-folder', contextMenu.item.id)">
            <span class="material-symbols-rounded text-[16px]">delete</span>
            Delete Folder
          </button>
        </template>

        <template v-if="contextMenu.type === 'board'">
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('rename-board', contextMenu.item)">
            <span class="material-symbols-rounded text-[16px]">edit</span>
            Rename Board
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('archive-board', contextMenu.item.board_id)">
            <span class="material-symbols-rounded text-[16px]">archive</span>
            Archive Board
          </button>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('unlink-board', { boardId: contextMenu.item.board_id, folderId: contextMenu.item.folderId })">
            <span class="material-symbols-rounded text-[16px]">link_off</span>
            Remove from Folder
          </button>
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20" @click="emit('delete-board', contextMenu.item.board_id)">
            <span class="material-symbols-rounded text-[16px]">delete</span>
            Delete Board
          </button>
        </template>

        <template v-if="contextMenu.type === 'unsorted-board'">
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700" @click="emit('archive-board', contextMenu.item.board_id)">
            <span class="material-symbols-rounded text-[16px]">archive</span>
            Archive Board
          </button>
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          <button class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20" @click="emit('delete-board', contextMenu.item.board_id)">
            <span class="material-symbols-rounded text-[16px]">delete</span>
            Delete Board
          </button>
        </template>
      </div>
    </div>
  </Teleport>
</template>
