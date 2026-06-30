<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useEmailSearchStore } from '@/stores/emailSearch'
import { useLabelsStore } from '@/stores/labels'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  // 'header' = mounted in AppHeader (desktop only, shown at md+).
  // 'inline' = mounted in EmailList (mobile-only fallback, hidden at md+).
  //
  // Both instances share `useEmailSearchStore`, so toggling the filter panel
  // in one would also re-render the teleported panel from the other (Teleport
  // escapes display:none on the parent). To prevent stacked duplicate panels
  // we render the teleported panel ONLY from the instance whose placement
  // matches the current viewport.
  placement: {
    type: String,
    default: 'header',
    validator: (v) => ['header', 'inline'].includes(v),
  },
})

const { t } = useI18n()
const search = useEmailSearchStore()
const labelsStore = useLabelsStore()

// Track which placement currently "owns" the visible search bar. The md
// breakpoint here MUST match the Tailwind class on each instance's wrapper
// (header uses `hidden md:flex`, inline uses `md:hidden`).
const isMdUp = ref(false)
function updateIsMdUp() { isMdUp.value = window.innerWidth >= 768 }
const ownsPanel = computed(() => isMdUp.value
  ? props.placement === 'header'
  : props.placement === 'inline'
)

// Anchor element + filter panel positioning (panel is teleported to body so it
// escapes any overflow:hidden ancestor like AppHeader / EmailList column).
const anchorRef = ref(null)
const panelPos = ref({ top: 0, left: 0, width: 860 })

// Label-dropdown anchor (lives inside the panel; also teleported).
const labelBtnRef = ref(null)
const labelDropdownPos = ref({ top: 0, left: 0, width: 0 })
const showLabelDropdown = ref(false)

// Mirrors useEmailSearchStore.searchQuery via v-model. Two-way wiring lets
// EmailList read the same value through the store without prop drilling.
const queryModel = computed({
  get: () => search.searchQuery,
  set: (v) => { search.searchQuery = v },
})

const activeFilterCount = computed(() => search.activeFilterCount)

// ---------------------------------------------------------------------------
// Filter panel positioning
// ---------------------------------------------------------------------------

function recomputePanelPos() {
  const el = anchorRef.value
  if (!el) return
  const r = el.getBoundingClientRect()
  // If the anchor scrolled offscreen, close the panel — avoids ghost panels
  // hovering over arbitrary parts of the UI.
  if (r.bottom < 0 || r.top > (window.innerHeight || 0)) {
    if (search.showVisualFilter) search.closeFilterPanel()
    return
  }
  // Width clamps so we stay readable on narrow viewports without dragging off
  // the screen edge. Minimum is wide enough that the toggle-row labels
  // ("Has attachment", "Select starred") fit on a single line.
  const desired = Math.min(860, Math.max(560, r.width + 240))
  const vw = window.innerWidth || document.documentElement.clientWidth
  const width = Math.min(desired, vw - 16)
  // Centre under the input, then clamp so we never overflow the viewport.
  let left = r.left + r.width / 2 - width / 2
  if (left < 8) left = 8
  if (left + width > vw - 8) left = vw - 8 - width
  panelPos.value = { top: r.bottom + 4, left, width }
}

function onAnchorClickReposition() {
  // Called when we open the panel — schedule after the v-if mount.
  nextTick(recomputePanelPos)
}

let outsideCleanup = null
watch(() => search.showVisualFilter, (open) => {
  if (outsideCleanup) { outsideCleanup(); outsideCleanup = null }
  if (!open) return
  recomputePanelPos()
  nextTick(() => {
    const onDocMouseDown = (e) => {
      // Don't close when clicking inside the label dropdown (also teleported).
      if (e.target.closest('.email-search-bar-panel')) return
      if (e.target.closest('.email-search-bar-label-dropdown')) return
      const el = anchorRef.value
      if (el && !el.contains(e.target)) {
        search.closeFilterPanel()
        showLabelDropdown.value = false
      }
    }
    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        search.closeFilterPanel()
        showLabelDropdown.value = false
      }
    }
    document.addEventListener('mousedown', onDocMouseDown, true)
    document.addEventListener('keydown', onKeyDown, true)
    outsideCleanup = () => {
      document.removeEventListener('mousedown', onDocMouseDown, true)
      document.removeEventListener('keydown', onKeyDown, true)
    }
  })
})

function onResize() {
  if (!search.showVisualFilter) return
  recomputePanelPos()
}

// Capture-phase scroll listener catches scrolls inside ANY ancestor (the
// header, the email-list column, the page), not just window scroll. Without
// capture phase the panel drifts away from the input the moment any scrollable
// parent moves.
function onScroll() {
  if (!search.showVisualFilter) return
  recomputePanelPos()
}

onMounted(() => {
  updateIsMdUp()
  window.addEventListener('resize', onResize)
  window.addEventListener('resize', updateIsMdUp)
  document.addEventListener('scroll', onScroll, { capture: true, passive: true })
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
  window.removeEventListener('resize', updateIsMdUp)
  document.removeEventListener('scroll', onScroll, { capture: true })
  if (outsideCleanup) outsideCleanup()
})

// ---------------------------------------------------------------------------
// Label dropdown
// ---------------------------------------------------------------------------

function toggleLabelDropdown() {
  if (!showLabelDropdown.value && labelBtnRef.value) {
    const rect = labelBtnRef.value.getBoundingClientRect()
    labelDropdownPos.value = { top: rect.bottom + 4, left: rect.left, width: rect.width }
  }
  showLabelDropdown.value = !showLabelDropdown.value
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

function onInput() {
  // Typing path uses the debounced action so we don't hammer IMAP on every
  // keystroke. Explicit Enter is handled by onSubmit (immediate).
  search.debouncedHandleSearch()
}

function onSubmit() {
  search.handleSearch()
}

function onFocus() {
  search.openFilterPanel()
  onAnchorClickReposition()
}

function onClear() {
  search.clearSearch()
}

function onFilterToggle() {
  search.toggleVisualFilter()
  if (search.showVisualFilter) onAnchorClickReposition()
}
</script>

<template>
  <div ref="anchorRef" class="relative w-full">
    <form @submit.prevent="onSubmit" class="relative">
      <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400 text-lg pointer-events-none">
        search
      </span>
      <input
        v-model="queryModel"
        type="text"
        class="input pl-10 pr-20 h-9 w-full text-sm"
        :placeholder="t('emailList.searchEmails')"
        @input="onInput"
        @focus="onFocus"
        @keydown.esc="search.closeFilterPanel()"
      />
      <button
        v-if="queryModel"
        type="button"
        @click.prevent="onClear"
        class="absolute right-10 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600 p-0.5 rounded"
        :title="t('emailList.clearSearch')"
      >
        <span class="material-symbols-rounded text-lg">close</span>
      </button>
      <button
        type="button"
        @click.stop.prevent="onFilterToggle"
        :class="[
          'absolute right-1 top-1/2 -translate-y-1/2 p-1.5 rounded-lg transition-colors',
          activeFilterCount > 0
            ? 'text-primary-500 bg-primary-50 dark:bg-primary-900/30'
            : 'text-surface-400 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-700'
        ]"
        :title="t('emailList.visualFilterBuilder')"
      >
        <span class="material-symbols-rounded text-lg block">filter_alt</span>
        <span
          v-if="activeFilterCount > 0"
          class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-primary-500 text-white text-[10px] rounded-full flex items-center justify-center font-medium"
        >
          {{ activeFilterCount }}
        </span>
      </button>
    </form>

    <!-- Filter panel — teleported so overflow:hidden ancestors don't clip it.
         Only the placement matching the current viewport renders the panel,
         otherwise we'd get two stacked panels (Teleport escapes the v-if's
         CSS visibility but both instances would still mount their teleport). -->
    <Teleport to="body">
      <div
        v-if="search.showVisualFilter && ownsPanel"
        class="email-search-bar-panel fixed max-h-[min(80vh,560px)] overflow-y-auto bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 z-[100]"
        :style="{ top: panelPos.top + 'px', left: panelPos.left + 'px', width: panelPos.width + 'px' }"
      >
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 rounded-t-xl">
          <h4 class="font-semibold text-surface-800 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">filter_alt</span>
            {{ t('emailList.filterEmails') }}
          </h4>
          <button type="button" @click="search.closeFilterPanel()" class="text-surface-400 hover:text-surface-600">
            <span class="material-symbols-rounded text-lg">close</span>
          </button>
        </div>

        <div class="p-3 sm:p-4">
          <!-- Scope selector -->
          <div class="flex gap-2 mb-3 sm:mb-4">
            <button
              type="button"
              @click="search.filterScope = 'current'"
              :class="[
                'flex-1 px-2 sm:px-3 py-1.5 text-xs sm:text-sm rounded-lg border transition-colors',
                search.filterScope === 'current'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                  : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-sm mr-1">folder</span>
              {{ t('emailList.currentFolder') }}
            </button>
            <button
              type="button"
              @click="search.filterScope = 'all'"
              :class="[
                'flex-1 px-2 sm:px-3 py-1.5 text-xs sm:text-sm rounded-lg border transition-colors',
                search.filterScope === 'all'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                  : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-sm mr-1">folder_copy</span>
              {{ t('emailList.allFolders') }}
            </button>
          </div>

          <!-- From / To / Subject -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 sm:gap-3 mb-3 sm:mb-4">
            <div>
              <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ t('emailList.from') }}</label>
              <input v-model="search.visualFilters.from" type="text" class="input w-full text-sm" :placeholder="t('emailList.sender')" />
            </div>
            <div>
              <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ t('emailList.to') }}</label>
              <input v-model="search.visualFilters.to" type="text" class="input w-full text-sm" :placeholder="t('emailList.recipient')" />
            </div>
            <div>
              <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ t('emailList.subject') }}</label>
              <input v-model="search.visualFilters.subject" type="text" class="input w-full text-sm" :placeholder="t('emailList.keywords')" />
            </div>
          </div>

          <!-- Date range -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-3 mb-4">
            <div>
              <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ t('emailList.afterDate') }}</label>
              <input v-model="search.visualFilters.afterDate" type="date" class="input text-sm w-full" />
            </div>
            <div>
              <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ t('emailList.beforeDate') }}</label>
              <input v-model="search.visualFilters.beforeDate" type="date" class="input text-sm w-full" />
            </div>
          </div>

          <!-- Toggle row.
               Grid is 2-cols on mobile (2x2) and 4-cols on desktop (1x4).
               Each toggle maps 1:1 to a `is:`/`has:` Smart View operator so
               the popup state round-trips with the sidebar Smart Views. -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3">
            <label class="flex items-center justify-between p-2.5 rounded-lg bg-surface-100 dark:bg-surface-700/50 cursor-pointer hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors">
              <span class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
                <span class="material-symbols-rounded text-base text-surface-500">attach_file</span>
                {{ t('emailList.hasAttachment') }}
              </span>
              <button
                type="button"
                @click.prevent="search.visualFilters.hasAttachment = !search.visualFilters.hasAttachment"
                :class="['w-9 h-5 rounded-full transition-colors relative flex-shrink-0', search.visualFilters.hasAttachment ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span :class="['absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform', search.visualFilters.hasAttachment ? 'translate-x-4' : 'translate-x-0']"></span>
              </button>
            </label>

            <label class="flex items-center justify-between p-2.5 rounded-lg bg-surface-100 dark:bg-surface-700/50 cursor-pointer hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors">
              <span class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
                <span class="material-symbols-rounded text-base text-surface-500">mark_email_unread</span>
                {{ t('emailList.unread') }}
              </span>
              <button
                type="button"
                @click.prevent="search.visualFilters.isUnread = !search.visualFilters.isUnread"
                :class="['w-9 h-5 rounded-full transition-colors relative flex-shrink-0', search.visualFilters.isUnread ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span :class="['absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform', search.visualFilters.isUnread ? 'translate-x-4' : 'translate-x-0']"></span>
              </button>
            </label>

            <label class="flex items-center justify-between p-2.5 rounded-lg bg-surface-100 dark:bg-surface-700/50 cursor-pointer hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors">
              <span class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
                <span class="material-symbols-rounded text-base text-amber-500" style="font-variation-settings: 'FILL' 1">star</span>
                {{ t('emailList.selectStarred') }}
              </span>
              <button
                type="button"
                @click.prevent="search.visualFilters.isStarred = !search.visualFilters.isStarred"
                :class="['w-9 h-5 rounded-full transition-colors relative flex-shrink-0', search.visualFilters.isStarred ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span :class="['absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform', search.visualFilters.isStarred ? 'translate-x-4' : 'translate-x-0']"></span>
              </button>
            </label>

            <label class="flex items-center justify-between p-2.5 rounded-lg bg-surface-100 dark:bg-surface-700/50 cursor-pointer hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors">
              <span class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
                <span class="material-symbols-rounded text-base text-amber-500" :style="search.visualFilters.isPinned ? 'font-variation-settings: \'FILL\' 1' : ''">push_pin</span>
                {{ t('emailList.pinned') }}
              </span>
              <button
                type="button"
                @click.prevent="search.visualFilters.isPinned = !search.visualFilters.isPinned"
                :class="['w-9 h-5 rounded-full transition-colors relative flex-shrink-0', search.visualFilters.isPinned ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span :class="['absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform', search.visualFilters.isPinned ? 'translate-x-4' : 'translate-x-0']"></span>
              </button>
            </label>
          </div>

          <!-- Labels filter -->
          <div v-if="labelsStore.labels.length > 0" class="mt-4">
            <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">{{ t('emailList.hasLabel') }}</label>
            <div class="relative">
              <button
                ref="labelBtnRef"
                type="button"
                @click="toggleLabelDropdown"
                class="input w-full text-sm text-left flex items-center justify-between"
              >
                <span v-if="search.visualFilters.labels.length === 0" class="text-surface-400">{{ t('emailList.selectLabels') }}</span>
                <span v-else class="flex flex-wrap gap-1">
                  <span
                    v-for="labelId in search.visualFilters.labels"
                    :key="labelId"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs"
                    :style="{ backgroundColor: (labelsStore.labels.find(l => l.id === labelId)?.color || '') + '20', color: labelsStore.labels.find(l => l.id === labelId)?.color }"
                  >
                    <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: labelsStore.labels.find(l => l.id === labelId)?.color }"></span>
                    {{ labelsStore.labels.find(l => l.id === labelId)?.name }}
                  </span>
                </span>
                <span class="material-symbols-rounded text-surface-400">expand_more</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Footer actions -->
        <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between gap-3 bg-surface-50 dark:bg-surface-900 rounded-b-xl">
          <button type="button" @click="search.clearVisualFilters()" class="btn-ghost text-sm" :disabled="activeFilterCount === 0">
            {{ t('notificationPanel.clearAll') }}
          </button>
          <button type="button" @click="search.applyVisualFilter()" class="btn-primary text-sm px-6">
            <span class="material-symbols-rounded text-sm mr-1">search</span>
            {{ t('emailList.filterEmails') }}
          </button>
        </div>
      </div>
    </Teleport>

    <!-- Label dropdown — also teleported; same single-owner guard. -->
    <Teleport to="body">
      <div
        v-if="showLabelDropdown && ownsPanel"
        class="email-search-bar-label-dropdown fixed bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 z-[200] max-h-48 overflow-y-auto"
        :style="{ top: labelDropdownPos.top + 'px', left: labelDropdownPos.left + 'px', width: labelDropdownPos.width + 'px' }"
      >
        <div
          v-for="label in labelsStore.labels"
          :key="label.id"
          @click="search.toggleFilterLabel(label.id)"
          class="flex items-center gap-2 px-3 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer"
        >
          <span class="w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: label.color }"></span>
          <span class="flex-1 text-sm text-surface-700 dark:text-surface-200">{{ label.name }}</span>
          <span
            v-if="search.visualFilters.labels.includes(label.id)"
            class="material-symbols-rounded text-primary-500 text-base"
          >check</span>
        </div>
      </div>
    </Teleport>
  </div>
</template>
