<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  item: { type: Object, required: true },
  itemType: { type: String, required: true }, // 'file' | 'folder'
  // Boolean hints from the parent so we can show/hide menu items
  hasVersions: { type: Boolean, default: false }, // file.current_version > 1
  isProtected: { type: Boolean, default: false }, // board-linked / system folder
  isEditedBySelf: { type: Boolean, default: false }, // user is actively editing
})

const emit = defineEmits([
  'open',
  'download',
  'rename',
  'move',
  'copy',
  'share',
  'show-versions',
  'toggle-star',
  'delete',
  'stop-editing',
])

const open = ref(false)
const triggerRef = ref(null)
const menuRef = ref(null)

// Fixed-position coordinates for the teleported menu.
// We anchor the menu's right edge to the trigger button's right edge,
// and flip vertically when there's no room below.
const menuStyle = ref({
  position: 'fixed',
  top: '0px',
  left: '0px',
  visibility: 'hidden',
})

const MENU_WIDTH = 224 // matches w-56 (14rem)
const VIEWPORT_MARGIN = 8

function computePosition() {
  if (!triggerRef.value) return
  const btnRect = triggerRef.value.getBoundingClientRect()
  const menuEl = menuRef.value
  const menuHeight = menuEl ? menuEl.offsetHeight : 320 // fallback estimate
  const menuWidth = menuEl ? menuEl.offsetWidth : MENU_WIDTH

  // Right-align with the trigger button by default.
  let left = btnRect.right - menuWidth
  // Keep the menu within the viewport horizontally.
  const maxLeft = window.innerWidth - menuWidth - VIEWPORT_MARGIN
  if (left > maxLeft) left = maxLeft
  if (left < VIEWPORT_MARGIN) left = VIEWPORT_MARGIN

  // Prefer opening below; flip above if it would overflow.
  const spaceBelow = window.innerHeight - btnRect.bottom - VIEWPORT_MARGIN
  const spaceAbove = btnRect.top - VIEWPORT_MARGIN
  let top
  if (spaceBelow >= menuHeight || spaceBelow >= spaceAbove) {
    top = btnRect.bottom + 4
    // If it still overflows the viewport, clamp it.
    if (top + menuHeight > window.innerHeight - VIEWPORT_MARGIN) {
      top = Math.max(VIEWPORT_MARGIN, window.innerHeight - menuHeight - VIEWPORT_MARGIN)
    }
  } else {
    top = btnRect.top - menuHeight - 4
    if (top < VIEWPORT_MARGIN) top = VIEWPORT_MARGIN
  }

  menuStyle.value = {
    position: 'fixed',
    top: `${Math.round(top)}px`,
    left: `${Math.round(left)}px`,
    visibility: 'visible',
  }
}

async function toggle(e) {
  e.stopPropagation()
  if (open.value) {
    close()
    return
  }
  open.value = true
  // First paint with visibility:hidden so we can measure, then position.
  menuStyle.value = { position: 'fixed', top: '0px', left: '0px', visibility: 'hidden' }
  await nextTick()
  computePosition()
}

function close() {
  open.value = false
}

function pick(name) {
  emit(name)
  close()
}

function onDocClick(e) {
  if (!open.value) return
  const trigger = triggerRef.value
  const menu = menuRef.value
  if (trigger && trigger.contains(e.target)) return
  if (menu && menu.contains(e.target)) return
  close()
}

function onKey(e) {
  if (e.key === 'Escape') close()
}

// Close on scroll/resize -- repositioning while inside a scrolling list is
// jarring; closing is the standard behaviour for row-anchored menus.
function onScroll() {
  if (open.value) close()
}
function onResize() {
  if (open.value) close()
}

onMounted(() => {
  document.addEventListener('mousedown', onDocClick)
  document.addEventListener('keydown', onKey)
  window.addEventListener('scroll', onScroll, true) // capture: catch ancestor scrolls
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', onScroll, true)
  window.removeEventListener('resize', onResize)
})

const isStarred = computed(() => !!props.item?.is_starred)
const isFile = computed(() => props.itemType === 'file')
</script>

<template>
  <div class="relative inline-flex">
    <button
      ref="triggerRef"
      type="button"
      @click="toggle"
      class="p-1.5 rounded-full hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
      :title="$t('driveView.more') || 'More'"
    >
      <span class="material-symbols-rounded text-base">more_vert</span>
    </button>

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        leave-active-class="transition ease-in duration-75"
        enter-from-class="opacity-0 scale-95"
        leave-to-class="opacity-0 scale-95"
      >
        <div
          v-if="open"
          ref="menuRef"
          :style="menuStyle"
          class="z-[60] w-56 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-lg overflow-hidden origin-top-right"
          @click.stop
        >
          <button
            @click="pick('open')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-base text-surface-500">{{ isFile ? 'open_in_new' : 'folder_open' }}</span>
            {{ $t('driveView.open') }}
          </button>

          <button
            v-if="isFile"
            @click="pick('download')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-base text-surface-500">download</span>
            {{ $t('driveView.download') }}
          </button>

          <button
            @click="pick('toggle-star')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span
              class="material-symbols-rounded text-base"
              :class="isStarred ? 'text-amber-500' : 'text-surface-500'"
            >{{ isStarred ? 'star' : 'star_outline' }}</span>
            {{ isStarred ? $t('driveView.removeFromStarred') : $t('driveView.addToStarred') }}
          </button>

          <button
            v-if="isFile && hasVersions"
            @click="pick('show-versions')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-base text-primary-500">history</span>
            {{ $t('driveView.versionHistory') || 'Version History' }}
          </button>

          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

          <button
            @click="pick('rename')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-base text-surface-500">edit</span>
            {{ $t('driveView.rename') }}
          </button>
          <button
            @click="pick('move')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-base text-surface-500">drive_file_move</span>
            {{ $t('driveView.moveTo') || 'Move' }}
          </button>
          <button
            @click="pick('copy')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-base text-surface-500">content_copy</span>
            {{ $t('driveView.copy') }}
          </button>
          <button
            @click="pick('share')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-base text-surface-500">share</span>
            {{ $t('driveView.share') }}
          </button>

          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

          <button
            v-if="isEditedBySelf"
            @click="pick('stop-editing')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-red-50 dark:hover:bg-red-500/10 text-sm text-red-600 dark:text-red-400"
          >
            <span class="material-symbols-rounded text-base">stop</span>
            {{ $t('driveListView.stopEditingThisFile') || 'Stop editing' }}
          </button>

          <button
            v-if="!isProtected"
            @click="pick('delete')"
            class="w-full px-3 py-2 text-left flex items-center gap-3 hover:bg-red-50 dark:hover:bg-red-500/10 text-sm text-red-600 dark:text-red-400"
          >
            <span class="material-symbols-rounded text-base">delete</span>
            {{ $t('driveView.delete') }}
          </button>
          <button
            v-else
            disabled
            class="w-full px-3 py-2 text-left flex items-center gap-3 text-sm text-surface-400 dark:text-surface-600 cursor-not-allowed"
            :title="$t('driveListView.itemboardidCannotDeleteFolderIs') || 'Cannot delete protected folder'"
          >
            <span class="material-symbols-rounded text-base">lock</span>
            {{ $t('driveView.delete') }}
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
