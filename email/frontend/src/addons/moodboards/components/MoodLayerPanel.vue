<template>
  <div :class="embedded
    ? 'flex flex-col h-full overflow-hidden'
    : 'w-72 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl overflow-hidden flex flex-col max-h-[calc(100vh-140px)]'"
  >
    <!-- Header — clean like Figma -->
    <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50/90 dark:bg-surface-800/90 flex items-center justify-between">
      <span class="text-xs font-semibold text-surface-700 dark:text-surface-200">Layers</span>
      <div class="flex items-center gap-1">
        <button
          v-if="!embedded"
          @click="$emit('close')"
          class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
        >
          <span class="material-symbols-rounded text-base">close</span>
        </button>
      </div>
    </div>

    <!-- Layer tree -->
    <div ref="scrollContainer" class="flex-1 overflow-y-auto min-h-0">
      <div class="py-0.5">
        <template v-for="entry in layerTree" :key="entry.key">

          <!-- Master layer header (e.g. Slides) -->
          <div
            v-if="entry.kind === 'master_layer'"
            class="flex items-center gap-1.5 py-1.5 px-3 cursor-pointer border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/90"
            @click="toggleCollapse(entry.key)"
          >
            <span class="material-symbols-rounded text-[14px] text-surface-400 transition-transform" :class="entry.isCollapsed ? '-rotate-90' : ''">
              expand_more
            </span>
            <span class="material-symbols-rounded text-[16px] text-surface-500 dark:text-surface-400">{{ entry.icon }}</span>
            <span class="text-[11px] font-semibold text-surface-600 dark:text-surface-300 uppercase tracking-wide">{{ entry.label }}</span>
            <span class="text-[10px] text-surface-400 ml-auto tabular-nums">{{ entry.childCount }}</span>
          </div>

          <!-- Group header -->
          <div
            v-else-if="entry.kind === 'group'"
            :ref="el => setLayerRowRef(`group_${entry.groupId}`, el)"
            class="flex items-center gap-1.5 py-1.5 cursor-pointer group/grp transition-colors"
            :class="[
              isLegacyGroupSelected(entry)
                ? 'bg-primary-500/15 dark:bg-primary-400/15 layer-selected'
                : '',
              dragOverId === ('group_' + entry.groupId) && dragOverMode === 'before' ? 'border-t-2 border-t-primary-400' : '',
              dragOverId === ('group_' + entry.groupId) && dragOverMode === 'after' ? 'border-b-2 border-b-primary-400' : '',
              dragOverId === ('group_' + entry.groupId) && !dragOverMode ? 'bg-primary-100/60 dark:bg-primary-900/30' : '',
              dragOverId !== ('group_' + entry.groupId) && !isLegacyGroupSelected(entry) ? 'hover:bg-surface-50 dark:hover:bg-surface-700/50' : '',
            ]"
            :style="{ paddingLeft: (12 + entry.depth * 18) + 'px', paddingRight: '8px' }"
            draggable="true"
            @dragstart="onGroupDragStart($event, entry)"
            @dragover.prevent="onGroupDragOver($event, entry)"
            @dragleave="onDragLeave"
            @drop.prevent="onGroupDrop($event, entry)"
            @dragend="onDragEnd"
            @click.exact="selectLegacyGroup(entry, false)"
            @click.shift="selectLegacyGroup(entry, true)"
            @dblclick.stop="enterLegacyGroup(entry)"
            @contextmenu="onGroupContextMenu($event, entry)"
          >
            <button @click.stop="toggleCollapse('group_' + entry.groupId)" class="w-4 h-4 flex items-center justify-center p-0">
              <span class="material-symbols-rounded text-[14px] text-surface-400 transition-transform" :class="entry.isCollapsed ? '-rotate-90' : ''">
                expand_more
              </span>
            </button>
            <span class="material-symbols-rounded text-[16px] text-surface-500 dark:text-surface-400">folder</span>
            <span class="text-[11px] font-medium flex-1"
              :class="isLegacyGroupSelected(entry)
                ? 'text-primary-700 dark:text-primary-300'
                : 'text-surface-700 dark:text-surface-200'"
            >Group</span>
            <span class="text-[10px] text-surface-400 tabular-nums">{{ entry.childCount }}</span>
          </div>

          <!-- Item row — clean Figma-style: icon + name -->
          <div
            v-else
            :ref="el => setLayerRowRef(entry.item.id, el)"
            class="flex items-center gap-1.5 pr-2 h-7 cursor-pointer transition-colors group/layer"
            :style="{ paddingLeft: (12 + entry.depth * 18) + 'px' }"
            :class="[
              store.selectedItemIds.has(entry.item.id)
                ? 'bg-primary-500/15 dark:bg-primary-400/15 layer-selected'
                : store.editingGroupId === entry.item.id
                  ? 'bg-primary-500/8 dark:bg-primary-400/8 ring-1 ring-inset ring-primary-400/30'
                  : 'hover:bg-surface-100/80 dark:hover:bg-surface-700/50',
              entry.item.locked ? 'opacity-60' : '',
              dragOverId === entry.item.id && dragOverMode === 'into' ? 'ring-1 ring-primary-400 ring-inset bg-primary-100/40 dark:bg-primary-900/20' : '',
              dragOverId === entry.item.id && dragOverMode === 'before' ? 'border-t-2 border-t-primary-400' : '',
              dragOverId === entry.item.id && dragOverMode === 'after' ? 'border-b-2 border-b-primary-400' : '',
            ]"
            draggable="true"
            @dragstart="onDragStart($event, entry.item)"
            @dragover.prevent="onDragOver($event, entry.item)"
            @dragleave="onDragLeave"
            @drop.prevent="onDrop($event, entry.item)"
            @dragend="onDragEnd"
            @click.exact="selectAndFly(entry.item, false)"
            @click.shift="selectAndFly(entry.item, true)"
            @dblclick.stop="enterItemGroup(entry.item)"
            @contextmenu="onLayerContextMenu($event, entry.item)"
          >
            <!-- Expand/collapse chevron -->
            <button v-if="entry.hasChildren" @click.stop="toggleCollapse(entry.item.id)" class="w-4 flex-shrink-0 flex items-center justify-center p-0">
              <span class="material-symbols-rounded text-[13px] text-surface-400 transition-transform" :class="entry.isCollapsed ? '-rotate-90' : ''">
                expand_more
              </span>
            </button>
            <span v-else class="w-4 flex-shrink-0"></span>

            <!-- Type icon -->
            <span class="material-symbols-rounded text-[16px] flex-shrink-0" :class="getTypeColor(entry.item.type)">
              {{ getTypeIcon(entry.item.type) }}
            </span>

            <!-- Name -->
            <span
              class="text-[11px] font-medium truncate flex-1 min-w-0"
              :class="store.selectedItemIds.has(entry.item.id)
                ? 'text-primary-700 dark:text-primary-300'
                : 'text-surface-700 dark:text-surface-300'"
            >{{ getItemLabel(entry.item) }}</span>

            <!-- Hover actions: visibility + lock -->
            <div class="flex items-center gap-0 flex-shrink-0 opacity-0 group-hover/layer:opacity-100 transition-opacity"
              :class="{ '!opacity-100': isHidden(entry.item) || entry.item.locked }"
            >
              <button
                @click.stop="toggleVisibility(entry.item)"
                class="w-5 h-5 flex items-center justify-center rounded transition-colors hover:bg-surface-200 dark:hover:bg-surface-600"
                :title="isHidden(entry.item) ? 'Show' : 'Hide'"
              >
                <span class="material-symbols-rounded text-[13px]" :class="isHidden(entry.item) ? 'text-surface-300 dark:text-surface-600' : 'text-surface-400'">
                  {{ isHidden(entry.item) ? 'visibility_off' : 'visibility' }}
                </span>
              </button>
              <button
                @click.stop="store.updateItem(entry.item.id, { locked: !entry.item.locked })"
                class="w-5 h-5 flex items-center justify-center rounded transition-colors hover:bg-surface-200 dark:hover:bg-surface-600"
                :title="entry.item.locked ? 'Unlock' : 'Lock'"
              >
                <span class="material-symbols-rounded text-[13px]" :class="entry.item.locked ? 'text-amber-500' : 'text-surface-400'">
                  {{ entry.item.locked ? 'lock' : 'lock_open' }}
                </span>
              </button>
            </div>
          </div>
        </template>

        <div v-if="!layerTree.length" class="py-8 text-center text-surface-400 text-xs">
          No items on canvas
        </div>
      </div>
    </div>

    <!-- Context menu -->
    <Teleport to="body">
      <div
        v-if="ctxMenu.show"
        class="fixed z-[10010] bg-white dark:bg-surface-800 rounded-lg shadow-2xl border border-surface-200 dark:border-surface-700 py-0.5 min-w-[180px]"
        :style="ctxMenuStyle"
        @click.stop
        @mousedown.stop
      >
        <button @click="ctxCopy" :disabled="!ctxHasSelection" class="lctx-btn" :class="ctxHasSelection ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">content_copy</span>
          <span class="flex-1 text-left">Copy</span>
          <span class="lctx-shortcut">Ctrl+C</span>
        </button>
        <button @click="ctxPaste" class="lctx-btn lctx-normal">
          <span class="material-symbols-rounded text-base">content_paste</span>
          <span class="flex-1 text-left">Paste</span>
          <span class="lctx-shortcut">Ctrl+V</span>
        </button>
        <button @click="ctxDuplicate" :disabled="!ctxHasSelection" class="lctx-btn" :class="ctxHasSelection ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">content_copy</span>
          <span class="flex-1 text-left">Duplicate</span>
          <span class="lctx-shortcut">Ctrl+D</span>
        </button>

        <div class="lctx-divider"></div>

        <button @click="ctxGroup" :disabled="!ctxMultiSelected" class="lctx-btn" :class="ctxMultiSelected ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">group_work</span>
          <span class="flex-1 text-left">Group</span>
          <span class="lctx-shortcut">Ctrl+G</span>
        </button>
        <button @click="ctxUngroup" :disabled="!ctxHasGroups" class="lctx-btn" :class="ctxHasGroups ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">workspaces</span>
          <span class="flex-1 text-left">Ungroup</span>
          <span class="lctx-shortcut">Ctrl+Shift+G</span>
        </button>
        <button @click="store.createRepeatGrid(); closeCtxMenu()" :disabled="!ctxHasSelection" class="lctx-btn" :class="ctxHasSelection ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">grid_view</span>
          <span class="flex-1 text-left">Repeat Grid</span>
        </button>

        <div class="lctx-divider"></div>

        <button v-if="!ctxAllLocked" @click="ctxLock" :disabled="!ctxHasSelection" class="lctx-btn" :class="ctxHasSelection ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">lock</span>
          <span class="flex-1 text-left">Lock</span>
        </button>
        <button v-else @click="ctxUnlock" class="lctx-btn lctx-normal">
          <span class="material-symbols-rounded text-base">lock_open</span>
          <span class="flex-1 text-left">Unlock</span>
        </button>

        <div class="lctx-divider"></div>

        <button @click="ctxBringToFront" :disabled="!ctxHasSelection" class="lctx-btn" :class="ctxHasSelection ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">flip_to_front</span>
          <span class="flex-1 text-left">Bring to Front</span>
          <span class="lctx-shortcut">]</span>
        </button>
        <button @click="ctxSendToBack" :disabled="!ctxHasSelection" class="lctx-btn" :class="ctxHasSelection ? 'lctx-normal' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">flip_to_back</span>
          <span class="flex-1 text-left">Send to Back</span>
          <span class="lctx-shortcut">[</span>
        </button>

        <div class="lctx-divider"></div>

        <button @click="ctxSelectAll" class="lctx-btn lctx-normal">
          <span class="material-symbols-rounded text-base">select_all</span>
          <span class="flex-1 text-left">Select All</span>
          <span class="lctx-shortcut">Ctrl+A</span>
        </button>

        <button @click="ctxDelete" :disabled="!ctxHasSelection" class="lctx-btn" :class="ctxHasSelection ? 'lctx-danger' : 'lctx-disabled'">
          <span class="material-symbols-rounded text-base">delete</span>
          <span class="flex-1 text-left">Delete</span>
          <span class="lctx-shortcut">Del</span>
        </button>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { nextZIndexInScope } from '@/addons/moodboards/utils/layerOrderUtils'

const props = defineProps({
  embedded: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'fly-to-item'])
const store = useMoodBoardsStore()
const scrollContainer = ref(null)
const layerRowRefs = new Map()

function setLayerRowRef(key, el) {
  const refKey = String(key)
  if (el) layerRowRefs.set(refKey, el)
  else layerRowRefs.delete(refKey)
}

// ── Type metadata ──
const TYPE_META = {
  note:           { icon: 'sticky_note_2',     color: 'text-amber-500' },
  image:          { icon: 'image',             color: 'text-blue-500' },
  text:           { icon: 'title',             color: 'text-emerald-500' },
  shape:          { icon: 'shapes',            color: 'text-purple-500' },
  link:           { icon: 'link',              color: 'text-cyan-500' },
  todo_list:      { icon: 'checklist',         color: 'text-green-500' },
  file:           { icon: 'insert_drive_file', color: 'text-slate-500' },
  color_swatch:   { icon: 'color_lens',        color: 'text-pink-500' },
  board_link:     { icon: 'dashboard',         color: 'text-indigo-500' },
  slide:          { icon: 'slideshow',         color: 'text-primary-500' },
  frame:          { icon: 'crop_free',         color: 'text-primary-500' },
  group:          { icon: 'folder',            color: 'text-orange-500' },
  drawing:        { icon: 'draw',              color: 'text-rose-500' },
  image_set:      { icon: 'photo_library',     color: 'text-sky-500' },
  calendar_event: { icon: 'event',             color: 'text-teal-500' },
  folder:         { icon: 'folder',            color: 'text-yellow-600' },
  table:          { icon: 'table_chart',       color: 'text-gray-500' },
  column:         { icon: 'view_column',       color: 'text-violet-500' },
  pen_shape:      { icon: 'ink_pen',           color: 'text-cyan-500' },
  video:          { icon: 'videocam',          color: 'text-red-500' },
  youtube:        { icon: 'smart_display',     color: 'text-red-600' },
  audio:          { icon: 'graphic_eq',        color: 'text-violet-500' },
  repeat_grid:    { icon: 'grid_view',         color: 'text-emerald-500' },
}

function getTypeIcon(type) {
  return TYPE_META[type]?.icon || 'article'
}

function getTypeColor(type) {
  return TYPE_META[type]?.color || 'text-surface-400'
}

function getItemLabel(item) {
  if (item.title) return item.title
  if (item.type === 'text' && item.content) return item.content.substring(0, 40)
  if (item.type === 'color_swatch') return item.color || 'Color'
  if (item.type === 'note' && item.content) return item.content.substring(0, 40)
  if (item.type === 'link') return item.url || 'Link'
  if (item.type === 'shape') return (item.style_data?.shape_type || 'Rectangle')
  if (item.type === 'pen_shape') return 'Pen Shape'
  if (item.type === 'slide') return item.title || 'Slide'
  if (item.type === 'frame') return item.title || 'Artboard'
  if (item.type === 'group') return item.title || 'Group'
  if (item.type === 'repeat_grid') return item.title || 'Repeat Grid'
  if (item.type === 'video') return item.url ? 'Video' : 'Video'
  if (item.type === 'youtube') return item.url || 'YouTube'
  return item.type.replace('_', ' ')
}

// ── Visibility ──
const hiddenItemIds = ref(new Set())

function isHidden(item) {
  return hiddenItemIds.value.has(item.id)
}

function toggleVisibility(item) {
  if (hiddenItemIds.value.has(item.id)) {
    hiddenItemIds.value.delete(item.id)
    store.updateItem(item.id, { style_data: { ...(item.style_data || {}), _hidden: false } })
  } else {
    hiddenItemIds.value.add(item.id)
    store.updateItem(item.id, { style_data: { ...(item.style_data || {}), _hidden: true } })
  }
}

// ── Layer tree (sorted by z-index, built from parent_id) ──
const collapsedGroups = ref(new Set())

let _frozenLayers = null
const filteredLayers = computed(() => {
  if (store.isDragging && _frozenLayers) return _frozenLayers
  const items = [...store.currentItems]
  items.sort((a, b) => (b.z_index || 0) - (a.z_index || 0))
  _frozenLayers = items
  return items
})

let _frozenTreeList = null
const layerTree = computed(() => {
  if (store.isDragging && _frozenTreeList) return _frozenTreeList

  const items = filteredLayers.value
  const itemMap = new Map(items.map(i => [i.id, i]))

  const childrenMap = new Map()
  const rootItems = []

  for (const item of items) {
    if (item.parent_id && itemMap.has(item.parent_id)) {
      if (!childrenMap.has(item.parent_id)) childrenMap.set(item.parent_id, [])
      childrenMap.get(item.parent_id).push(item)
    } else {
      rootItems.push(item)
    }
  }

  for (const [, children] of childrenMap) {
    children.sort((a, b) => (b.z_index || 0) - (a.z_index || 0))
  }

  const result = []

  function flattenItem(item, depth) {
    const children = childrenMap.get(item.id) || []
    const hasChildren = children.length > 0
    const isCollapsed = hasChildren && collapsedGroups.value.has(item.id)

    result.push({
      kind: 'item',
      key: item.id,
      item,
      depth,
      hasChildren,
      childCount: children.length,
      isCollapsed,
    })

    if (hasChildren && !isCollapsed) {
      for (const child of children) {
        flattenItem(child, depth + 1)
      }
    }
  }

  const groupMap = new Map()
  for (const item of rootItems) {
    const gid = item.style_data?.group_id
    if (gid) {
      if (!groupMap.has(gid)) groupMap.set(gid, [])
      groupMap.get(gid).push(item)
    }
  }

  const slideItems = rootItems.filter(i => i.type === 'slide')
  const nonSlideItems = rootItems.filter(i => i.type !== 'slide')

  const usedGroups = new Set()
  for (const item of nonSlideItems) {
    const gid = item.style_data?.group_id
    if (gid) {
      if (!usedGroups.has(gid)) {
        usedGroups.add(gid)
        const groupItems = groupMap.get(gid)
        const isCollapsed = collapsedGroups.value.has('group_' + gid)

        result.push({
          kind: 'group',
          key: 'group_' + gid,
          groupId: gid,
          depth: 0,
          hasChildren: true,
          childCount: groupItems.length,
          isCollapsed,
          children: groupItems,
        })

        if (!isCollapsed) {
          for (const gi of groupItems) {
            flattenItem(gi, 1)
          }
        }
      }
    } else {
      flattenItem(item, 0)
    }
  }

  if (slideItems.length > 0) {
    const slidesCollapsed = collapsedGroups.value.has('master_slides')
    result.push({
      kind: 'master_layer',
      key: 'master_slides',
      label: 'Slides',
      icon: 'slideshow',
      depth: 0,
      hasChildren: true,
      childCount: slideItems.length,
      isCollapsed: slidesCollapsed,
    })

    if (!slidesCollapsed) {
      for (const slide of slideItems) {
        flattenItem(slide, 1)
      }
    }
  }

  _frozenTreeList = result
  return result
})

function toggleCollapse(id) {
  if (collapsedGroups.value.has(id)) {
    collapsedGroups.value.delete(id)
  } else {
    collapsedGroups.value.add(id)
  }
}

watch(() => store.editingGroupId, (gid) => {
  if (!gid) return
  collapsedGroups.value.delete(gid)
  let current = store.getItemById(gid)
  while (current?.parent_id) {
    collapsedGroups.value.delete(current.parent_id)
    current = store.getItemById(current.parent_id)
  }
})

function expandSelectionPath(itemId) {
  const item = store.getItemById(itemId)
  if (!item) return

  if (item.style_data?.group_id) {
    collapsedGroups.value.delete('group_' + item.style_data.group_id)
  }

  let current = item
  while (current?.parent_id) {
    collapsedGroups.value.delete(current.parent_id)
    current = store.getItemById(current.parent_id)
  }
}

function getScrollTargetKey() {
  const selectedIds = [...store.selectedItemIds]
  for (const id of selectedIds) {
    if (layerRowRefs.has(String(id))) return String(id)
  }

  if (store.editingGroupId != null) {
    if (layerRowRefs.has(String(store.editingGroupId))) return String(store.editingGroupId)
    if (layerRowRefs.has(`group_${store.editingGroupId}`)) return `group_${store.editingGroupId}`
  }

  return null
}

function scrollToActiveLayer() {
  const container = scrollContainer.value
  const targetKey = getScrollTargetKey()
  if (!container || !targetKey) return
  const row = layerRowRefs.get(String(targetKey))
  if (!row) return

  const containerRect = container.getBoundingClientRect()
  const rowRect = row.getBoundingClientRect()
  const rowOffsetTop = rowRect.top - containerRect.top + container.scrollTop
  const rowCenter = rowOffsetTop + rowRect.height / 2
  const idealScroll = rowCenter - containerRect.height / 2
  const maxScroll = container.scrollHeight - containerRect.height
  const target = Math.max(0, Math.min(idealScroll, maxScroll))

  container.scrollTo({ top: target, behavior: 'smooth' })
}

watch(() => [...store.selectedItemIds], async (ids) => {
  if (ids.length) {
    expandSelectionPath(ids[0])
  }
  await nextTick()
  scrollToActiveLayer()
})

watch(() => store.editingGroupId, async (gid) => {
  if (gid != null) {
    collapsedGroups.value.delete(gid)
    collapsedGroups.value.delete(`group_${gid}`)
  }
  await nextTick()
  scrollToActiveLayer()
})

watch(layerTree, async () => {
  await nextTick()
  scrollToActiveLayer()
})

function selectAndFly(item, addToSelection) {
  if (!addToSelection && item.parent_id) {
    const parent = store.getItemById(item.parent_id)
    if (parent?.type === 'group' || parent?.type === 'repeat_grid') {
      store.editingGroupId = parent.id
    }
  }
  store.selectItem(item.id, addToSelection)
  emit('fly-to-item', item)
}

function isLegacyGroupSelected(entry) {
  const children = entry?.children || []
  if (!children.length) return false
  return children.every(child => store.selectedItemIds.has(child.id))
}

function selectLegacyGroup(entry, addToSelection) {
  const children = entry?.children || []
  if (!children.length) return

  const ids = children.map(child => child.id)
  const next = new Set(store.selectedItemIds)

  if (addToSelection) {
    const allSelected = ids.every(id => next.has(id))
    for (const id of ids) {
      if (allSelected) next.delete(id)
      else next.add(id)
    }
    store.editingGroupId = null
    store.selectedItemIds = next
  } else {
    store.editingGroupId = null
    store.selectedItemIds = new Set(ids)
  }

  emit('fly-to-item', children[0])
}

const CONTAINER_TYPES = new Set(['frame', 'group', 'artboard', 'column', 'repeat_grid'])

function enterItemGroup(item) {
  if (CONTAINER_TYPES.has(item.type)) {
    if (collapsedGroups.value.has(item.id)) {
      collapsedGroups.value.delete(item.id)
    }
    store.enterGroup(item.id)
    return
  }
  if (item.style_data?.group_id) {
    store.enterGroup(item.id)
  }
}

function enterLegacyGroup(entry) {
  if (collapsedGroups.value.has('group_' + entry.groupId)) {
    collapsedGroups.value.delete('group_' + entry.groupId)
  }
  const children = entry.children || []
  if (children.length) {
    store.editingGroupId = entry.groupId
    store.selectedItemIds = new Set([children[0].id])
  }
}

// ── Drag-and-drop reordering ──
const draggedItem = ref(null)
const draggedGroupId = ref(null)
const dragOverId = ref(null)
const dragOverMode = ref(null)

function isDescendantOf(itemId, potentialAncestorId) {
  let currentId = itemId
  const visited = new Set()
  while (currentId) {
    if (currentId === potentialAncestorId) return true
    if (visited.has(currentId)) return false
    visited.add(currentId)
    const item = store.getItemById(currentId)
    currentId = item?.parent_id || null
  }
  return false
}

function onDragStart(e, item) {
  draggedItem.value = item
  draggedGroupId.value = null
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', item.id)
  if (e.target) e.target.style.opacity = '0.5'
}

function onGroupDragStart(e, groupEntry) {
  draggedGroupId.value = groupEntry.groupId
  draggedItem.value = null
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', 'group_' + groupEntry.groupId)
  if (e.target) e.target.style.opacity = '0.5'
}

function onDragOver(e, item) {
  if (!draggedItem.value && !draggedGroupId.value) return
  if (draggedItem.value?.id === item.id) return
  if (draggedGroupId.value && item.style_data?.group_id === draggedGroupId.value) return
  e.dataTransfer.dropEffect = 'move'
  dragOverId.value = item.id

  const rect = e.currentTarget.getBoundingClientRect()
  const relY = (e.clientY - rect.top) / rect.height

  const canDropInto = item.type === 'frame' || item.type === 'slide' || item.type === 'column' || item.type === 'group' || item.type === 'repeat_grid'
  if (canDropInto && relY > 0.2 && relY < 0.8) {
    if (draggedItem.value && isDescendantOf(item.id, draggedItem.value.id)) {
      dragOverMode.value = relY < 0.5 ? 'before' : 'after'
    } else {
      dragOverMode.value = 'into'
    }
  } else if (relY < 0.5) {
    dragOverMode.value = 'before'
  } else {
    dragOverMode.value = 'after'
  }
}

function onGroupDragOver(e, groupEntry) {
  if (!draggedItem.value && !draggedGroupId.value) return
  if (draggedGroupId.value === groupEntry.groupId) return
  e.dataTransfer.dropEffect = 'move'
  dragOverId.value = 'group_' + groupEntry.groupId

  const rect = e.currentTarget.getBoundingClientRect()
  const midY = rect.top + rect.height / 2
  dragOverMode.value = e.clientY < midY ? 'before' : 'after'
}

function onDragLeave() {
  dragOverId.value = null
  dragOverMode.value = null
}

function _reparentGroupInto(containerId) {
  const groupItems = store.currentItems.filter(i => i.style_data?.group_id === draggedGroupId.value)
  if (!groupItems.length) return
  let z = nextZIndexInScope(store.currentItems, { parentId: containerId, lane: 'content' })
  const updates = groupItems.map(gi => ({ id: gi.id, parent_id: containerId, z_index: z++ }))
  store.batchUpdateItems(updates)
}

function onDrop(e, targetItem) {
  const mode = dragOverMode.value || 'after'
  dragOverId.value = null
  dragOverMode.value = null

  if (draggedItem.value && draggedItem.value.id !== targetItem.id) {
    const isIntoContainer = mode === 'into' && (targetItem.type === 'frame' || targetItem.type === 'slide' || targetItem.type === 'column' || targetItem.type === 'group' || targetItem.type === 'repeat_grid')
    if (isIntoContainer) {
      const targetZ = nextZIndexInScope(store.currentItems, { parentId: targetItem.id, lane: 'content' })
      store.updateItem(draggedItem.value.id, { parent_id: targetItem.id, z_index: targetZ })
    } else {
      const newParentId = targetItem.parent_id || null
      const currentParentId = draggedItem.value.parent_id || null
      if (newParentId !== currentParentId) {
        store.updateItem(draggedItem.value.id, { parent_id: newParentId }, { skipUndo: true })
      }
      store.reorderZIndex(draggedItem.value.id, targetItem.id, mode)
    }
  } else if (draggedGroupId.value) {
    const isIntoContainer = mode === 'into' && (targetItem.type === 'frame' || targetItem.type === 'slide' || targetItem.type === 'column' || targetItem.type === 'group' || targetItem.type === 'repeat_grid')
    if (isIntoContainer) {
      _reparentGroupInto(targetItem.id)
    } else {
      const groupItems = store.currentItems.filter(i => i.style_data?.group_id === draggedGroupId.value)
      const groupParent = groupItems[0]?.parent_id || null
      const targetParent = targetItem.parent_id || null
      if (groupParent !== targetParent) {
        _reparentGroupInto(targetParent)
      }
      store.reorderGroupZIndex(draggedGroupId.value, targetItem.id, mode)
    }
  }

  draggedItem.value = null
  draggedGroupId.value = null
}

function onGroupDrop(e, groupEntry) {
  const mode = dragOverMode.value
  dragOverId.value = null
  dragOverMode.value = null
  const children = groupEntry.children || []
  if (!children.length) return

  const position = mode === 'before' || mode === 'after' ? mode : (
    e.clientY < (e.currentTarget.getBoundingClientRect().top + e.currentTarget.getBoundingClientRect().height / 2) ? 'before' : 'after'
  )

  const targetChild = position === 'after' ? children[children.length - 1] : children[0]

  if (draggedItem.value) {
    const currentParentId = draggedItem.value.parent_id || null
    const targetParent = targetChild.parent_id || null
    if (currentParentId !== targetParent) {
      store.updateItem(draggedItem.value.id, { parent_id: targetParent }, { skipUndo: true })
    }
    store.reorderZIndex(draggedItem.value.id, targetChild.id, position)
  } else if (draggedGroupId.value && draggedGroupId.value !== groupEntry.groupId) {
    const groupItems = store.currentItems.filter(i => i.style_data?.group_id === draggedGroupId.value)
    const groupParent = groupItems[0]?.parent_id || null
    const targetParent = targetChild.parent_id || null
    if (groupParent !== targetParent) {
      _reparentGroupInto(targetParent)
    }
    store.reorderGroupZIndex(draggedGroupId.value, targetChild.id, position)
  }

  draggedItem.value = null
  draggedGroupId.value = null
}

function onDragEnd(e) {
  dragOverId.value = null
  dragOverMode.value = null
  draggedItem.value = null
  draggedGroupId.value = null
  if (e.target) e.target.style.opacity = ''
}

// ── Context menu ──
const ctxMenu = ref({ show: false, x: 0, y: 0, itemId: null })
const CTX_MENU_HEIGHT = 380

const ctxMenuStyle = computed(() => {
  const style = { left: ctxMenu.value.x + 'px' }
  const spaceBelow = window.innerHeight - ctxMenu.value.y
  if (spaceBelow < CTX_MENU_HEIGHT) {
    style.bottom = (window.innerHeight - ctxMenu.value.y) + 'px'
  } else {
    style.top = ctxMenu.value.y + 'px'
  }
  return style
})

function onLayerContextMenu(e, item) {
  e.preventDefault()
  e.stopPropagation()
  if (!store.selectedItemIds.has(item.id)) {
    store.selectItem(item.id, false)
  }
  ctxMenu.value = { show: true, x: e.clientX, y: e.clientY, itemId: item.id }
}

function onGroupContextMenu(e, groupEntry) {
  e.preventDefault()
  e.stopPropagation()
  const children = groupEntry.children || []
  if (children.length) {
    store.selectItem(children[0].id, false)
    for (let i = 1; i < children.length; i++) {
      store.selectItem(children[i].id, true)
    }
  }
  ctxMenu.value = { show: true, x: e.clientX, y: e.clientY, itemId: children[0]?.id || null }
}

function closeCtxMenu() { ctxMenu.value.show = false }

function ctxDelete() { store.deleteSelectedItems(); closeCtxMenu() }
function ctxDuplicate() { store.duplicateSelectedItems(30, 30); closeCtxMenu() }
function ctxCopy() { store.copySelectedItems(); closeCtxMenu() }
function ctxPaste() { store.pasteItems(30, 30); closeCtxMenu() }
function ctxGroup() { store.groupSelectedItems(); closeCtxMenu() }
function ctxUngroup() { store.ungroupSelectedItems(); closeCtxMenu() }

function ctxLock() {
  for (const id of store.selectedItemIds) {
    if (store.getItemById(id)) store.updateItem(id, { locked: true })
  }
  closeCtxMenu()
}

function ctxUnlock() {
  for (const id of store.selectedItemIds) {
    if (store.getItemById(id)) store.updateItem(id, { locked: false })
  }
  closeCtxMenu()
}

function ctxBringToFront() {
  for (const id of store.selectedItemIds) store.bringToFront(id)
  closeCtxMenu()
}

function ctxSendToBack() {
  for (const id of store.selectedItemIds) store.sendToBack(id)
  closeCtxMenu()
}

function ctxSelectAll() { store.selectAll(); closeCtxMenu() }

const ctxHasSelection = computed(() => store.selectedItemIds.size > 0)
const ctxMultiSelected = computed(() => store.selectedItemIds.size >= 2)
const ctxHasGroups = computed(() => store.selectionHasGroups?.() || false)
const ctxAllLocked = computed(() => {
  if (!store.selectedItemIds.size) return false
  for (const id of store.selectedItemIds) {
    const item = store.getItemById(id)
    if (item && !item.locked) return false
  }
  return true
})

function onDocClick() {
  if (ctxMenu.value.show) closeCtxMenu()
}
onMounted(() => document.addEventListener('click', onDocClick))
onUnmounted(() => document.removeEventListener('click', onDocClick))
</script>

<style>
.lctx-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.625rem;
  font-size: 0.75rem;
  text-align: left;
  transition: background-color 0.12s ease, color 0.12s ease;
}
.lctx-normal {
  color: var(--color-surface-800, #1f2937);
}
.lctx-normal:hover {
  background-color: var(--color-surface-100, #f3f4f6);
}
:root.dark .lctx-normal,
.dark .lctx-normal {
  color: var(--color-surface-200, #e5e7eb);
}
:root.dark .lctx-normal:hover,
.dark .lctx-normal:hover {
  background-color: rgba(255, 255, 255, 0.06);
  color: #fff;
}
.lctx-danger {
  color: var(--color-red-600, #dc2626);
}
.lctx-danger:hover {
  background-color: #fef2f2;
}
:root.dark .lctx-danger,
.dark .lctx-danger {
  color: var(--color-red-400, #f87171);
}
:root.dark .lctx-danger:hover,
.dark .lctx-danger:hover {
  background-color: rgba(220, 38, 38, 0.12);
}
.lctx-disabled {
  color: var(--color-surface-400, #9ca3af);
  pointer-events: none;
  opacity: 0.5;
}
.lctx-shortcut {
  font-size: 0.625rem;
  font-family: ui-monospace, monospace;
  color: var(--color-surface-400, #9ca3af);
  letter-spacing: 0.02em;
}
:root.dark .lctx-shortcut,
.dark .lctx-shortcut {
  color: var(--color-surface-500, #6b7280);
}
.lctx-divider {
  height: 1px;
  margin: 0.3rem 0.625rem;
  background-color: var(--color-surface-200, #e5e7eb);
}
:root.dark .lctx-divider,
.dark .lctx-divider {
  background-color: var(--color-surface-700, #374151);
}
</style>
