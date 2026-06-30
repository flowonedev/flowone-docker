<script setup>
/**
 * UnifiedShareModal - the single sharing dialog used everywhere (Drive list,
 * preview, context menu, Office editor, attachment preview).
 *
 * Mounted once in App.vue. Reads its state from the shareModal store, so any
 * view opens the exact same UI via `shareModal.open(item, type, opts)`.
 *
 * Two purpose-based tabs:
 *   - Share link  -> public token link (view/download) + optional notify
 *   - Collaborate -> people / groups / guest links (view/edit on the server)
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShareModalStore } from '@/stores/shareModal'
import ShareLinkTab from '@/components/drive/share/ShareLinkTab.vue'
import CollaborateTab from '@/components/drive/share/CollaborateTab.vue'

const { t } = useI18n()
const shareModal = useShareModalStore()

const activeTab = ref('link') // 'link' | 'collaborate'

const item = computed(() => shareModal.item)
const type = computed(() => shareModal.type)
const isFolder = computed(() => type.value === 'folder')
const itemName = computed(() => item.value?.name || item.value?.original_name || t('unifiedShare.item'))

// Reset the active tab to the requested default each time the modal opens.
watch(
  () => shareModal.show,
  (showing) => {
    if (showing) activeTab.value = shareModal.defaultTab === 'collaborate' ? 'collaborate' : 'link'
  }
)

const tabs = computed(() => [
  { id: 'link', icon: 'link', label: t('unifiedShare.tabLink') },
  { id: 'collaborate', icon: 'group', label: t('unifiedShare.tabCollaborate') },
])

function close() {
  shareModal.close()
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="shareModal.show && item"
      class="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-black/50"
      @click.self="close"
    >
      <div
        class="w-full max-w-lg bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[88vh]"
        @click.stop
      >
        <!-- Header -->
        <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between shrink-0">
          <div class="flex items-center gap-3 min-w-0">
            <div class="w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center shrink-0">
              <span class="material-symbols-rounded text-xl text-primary-500">{{ isFolder ? 'folder' : 'draft' }}</span>
            </div>
            <div class="min-w-0">
              <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ t('unifiedShare.title') }}</h3>
              <p class="text-sm text-surface-500 truncate max-w-[280px]">{{ itemName }}</p>
            </div>
          </div>
          <button @click="close" class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg shrink-0">
            <span class="material-symbols-rounded text-surface-500">close</span>
          </button>
        </div>

        <!-- Top-level tabs -->
        <div class="flex border-b border-surface-200 dark:border-surface-700 shrink-0">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="[
              'flex-1 px-4 py-3 text-sm font-medium transition-colors',
              activeTab === tab.id
                ? 'text-primary-600 dark:text-primary-400 border-b-2 border-primary-500'
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300',
            ]"
          >
            <span class="material-symbols-rounded text-lg align-middle mr-1">{{ tab.icon }}</span>
            {{ tab.label }}
          </button>
        </div>

        <!-- Content (lazy: each tab mounts/fetches only when selected) -->
        <div class="p-6 overflow-y-auto">
          <ShareLinkTab v-if="activeTab === 'link'" :item="item" :type="type" />
          <CollaborateTab v-else :item="item" :type="type" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
