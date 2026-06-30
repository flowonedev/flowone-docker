<script setup>
/**
 * SmartViewsList — the "Smart Views" section that lives inside FolderTree.
 *
 * Renders:
 *   1. Section header with collapse toggle.
 *   2. Built-in views (Unread, Attachments, Important, Mentions).
 *   3. User-saved views (drag-and-drop reorderable).
 *
 * Built-ins are immutable — clicking opens them via smartViews.run(); the
 * inline ⋯ menu is hidden for them. User-saved views get the menu (edit /
 * delete) and drag handles.
 *
 * Active highlighting:
 *   smartViews.activeId is set by run(); cleared whenever the mailbox folder
 *   changes back to a non-search folder (handled in mailbox.$subscribe via
 *   bindToMailbox()).
 *
 * Note: there is intentionally NO "+ new" button in this surface. Smart
 * Views are created from the search bar's "Save as Smart View" affordance,
 * which carries the current search context. The SmartViewModal is mounted
 * here only to handle edit-mode for user-saved views (triggered by the ⋯
 * button on each row).
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useSmartViewsStore } from '@/stores/smartViews'
import { useI18n } from 'vue-i18n'
import SmartViewModal from '@/components/SmartViewModal.vue'

const smartViews = useSmartViewsStore()
const { t } = useI18n()

const collapsed = ref(localStorage.getItem('webmail_smart_views_collapsed') === 'true')
function toggleCollapsed() {
  collapsed.value = !collapsed.value
  localStorage.setItem('webmail_smart_views_collapsed', String(collapsed.value))
}

// Modal state — only used for edit-mode (no create from this surface).
const showModal = ref(false)
const editing = ref(null)
function openEdit(view) {
  editing.value = view
  showModal.value = true
}

// Drag-and-drop reorder (saved views only)
const dragId = ref(null)
function onDragStart(e, view) {
  if (view.builtin) return
  dragId.value = view.id
  e.dataTransfer.effectAllowed = 'move'
}
function onDragOver(e, view) {
  if (view.builtin || dragId.value === null) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
}
async function onDrop(e, target) {
  e.preventDefault()
  if (target.builtin) { dragId.value = null; return }
  const fromId = dragId.value
  dragId.value = null
  if (!fromId || fromId === target.id) return

  const ids = smartViews.savedViews.map(v => v.id)
  const fromIdx = ids.indexOf(fromId)
  const toIdx   = ids.indexOf(target.id)
  if (fromIdx < 0 || toIdx < 0) return
  ids.splice(toIdx, 0, ids.splice(fromIdx, 1)[0])
  await smartViews.reorder(ids)
}

const savedCount = computed(() => smartViews.savedViews.length)

let unbindMailbox = null
onMounted(async () => {
  await smartViews.fetch()
  unbindMailbox = smartViews.bindToMailbox()
})
onUnmounted(() => { if (unbindMailbox) unbindMailbox() })

function viewKey(v) { return `sv-${v.id}` }
function isActive(v) { return String(smartViews.activeId) === String(v.id) }

/**
 * Icon colour rules.
 *
 * Default (inactive): inherit the surface grey from the row — same look as
 * the folder list. The user-saved colour is stored on the view but only
 * surfaces on hover/active, so the sidebar stays visually calm.
 *
 * Active view: tint with the saved colour so the user can see which view is
 * currently driving the search results. Falls back to primary if no colour
 * was set (or the colour is unknown to the palette below).
 */
function iconColorClass(view) {
  if (!isActive(view)) return ''
  // Tailwind-safe full class strings (so JIT picks them up).
  const map = {
    primary: 'text-primary-500', red: 'text-red-500', orange: 'text-orange-500',
    amber: 'text-amber-500', yellow: 'text-yellow-500', lime: 'text-lime-500',
    green: 'text-green-500', emerald: 'text-emerald-500', teal: 'text-teal-500',
    cyan: 'text-cyan-500', sky: 'text-sky-500', blue: 'text-blue-500',
    indigo: 'text-indigo-500', violet: 'text-violet-500', purple: 'text-purple-500',
    fuchsia: 'text-fuchsia-500', pink: 'text-pink-500', rose: 'text-rose-500',
  }
  return map[view.color] || 'text-primary-500'
}
</script>

<template>
  <div class="mt-4 pt-3 border-t border-surface-200 dark:border-surface-700">
    <!-- Section header -->
    <div class="flex items-center px-3 mb-2">
      <button
        class="flex items-center gap-1 text-xs font-medium text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 uppercase tracking-wider"
        @click="toggleCollapsed"
      >
        <span class="material-symbols-rounded text-sm">
          {{ collapsed ? 'chevron_right' : 'expand_more' }}
        </span>
        {{ t('smartViews.section') }}
        <span v-if="savedCount > 0" class="text-surface-400">({{ savedCount }})</span>
      </button>
    </div>

    <!-- View list -->
    <ul v-if="!collapsed" class="space-y-0.5">
      <li v-for="view in smartViews.allViews" :key="viewKey(view)">
        <div
          :class="[
            'folder-item w-full group transition-all',
            { 'active': isActive(view) }
          ]"
          :draggable="!view.builtin"
          @dragstart="onDragStart($event, view)"
          @dragover="onDragOver($event, view)"
          @drop="onDrop($event, view)"
        >
          <button
            class="flex items-center gap-2 flex-1 min-w-0"
            @click="smartViews.run(view)"
          >
            <span class="material-symbols-rounded text-lg" :class="iconColorClass(view)">
              {{ view.icon || 'filter_alt' }}
            </span>
            <span class="flex-1 text-left truncate text-sm">{{ view.name }}</span>
          </button>
          <button
            v-if="!view.builtin"
            class="opacity-0 group-hover:opacity-100 h-6 w-6 flex items-center justify-center rounded text-surface-400 hover:text-surface-700 dark:hover:text-surface-200"
            :title="t('smartViews.edit')"
            @click.stop="openEdit(view)"
          >
            <span class="material-symbols-rounded text-base">more_horiz</span>
          </button>
        </div>
      </li>

      <li v-if="smartViews.loading && !smartViews.hasFetchedOnce" class="px-3 py-1 text-xs text-surface-400">
        {{ t('smartViews.loading') }}
      </li>
    </ul>

    <SmartViewModal
      :show="showModal"
      :view="editing"
      @close="showModal = false"
    />
  </div>
</template>
