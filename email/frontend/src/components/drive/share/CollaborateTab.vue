<script setup>
/**
 * CollaborateTab - Tab 2 of the UnifiedShareModal.
 *
 * Server-side collaboration: People + Groups (viewer/editor), plus Guest links
 * (view/edit, no login) which are strictly file-only and only for
 * office-editable files. Each sub-tab loads lazily on first selection.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShareModalStore } from '@/stores/shareModal'
import { useOfficeStatus } from '@/composables/useOfficeStatus'
import SharePeopleTab from '@/components/drive/share/SharePeopleTab.vue'
import ShareGroupsTab from '@/components/drive/share/ShareGroupsTab.vue'
import ShareGuestLinksTab from '@/components/drive/share/ShareGuestLinksTab.vue'
import ShareRestrictions from '@/components/drive/share/ShareRestrictions.vue'

const props = defineProps({
  item: { type: Object, required: true },
  type: { type: String, default: 'file' }, // 'file' | 'folder'
})

const { t } = useI18n()
const shareModal = useShareModalStore()
const { ensureOfficeStatus, canEditInOffice } = useOfficeStatus()

const sub = ref('people') // 'people' | 'groups' | 'links'
const officeReady = ref(false)

// Resolve the filename from whichever field the opener populated. The share
// modal can be opened with items that carry `name` OR only `original_name`
// (Drive list vs. preview vs. office editor), so we must check both - otherwise
// the office-editable detection silently fails and the guest-links tab vanishes.
const itemFileName = computed(() => props.item?.name || props.item?.original_name || '')

// Guest links: file-only AND office-editable. Never for folders.
const showGuestLinks = computed(
  () => props.type === 'file' && officeReady.value && canEditInOffice(itemFileName.value)
)

const subTabs = computed(() => {
  const tabs = [
    { id: 'people', icon: 'person', label: t('unifiedShare.subPeople') },
    { id: 'groups', icon: 'groups', label: t('unifiedShare.subGroups') },
  ]
  if (showGuestLinks.value) {
    tabs.push({ id: 'links', icon: 'link', label: t('unifiedShare.subGuestLinks') })
  }
  return tabs
})

function onChanged() {
  shareModal.notifyUpdated()
}

onMounted(async () => {
  await ensureOfficeStatus()
  officeReady.value = true
})
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-surface-600 dark:text-surface-400">{{ t('unifiedShare.collaborateDesc') }}</p>

    <!-- Sub-tabs -->
    <div class="flex gap-1 p-1 rounded-xl bg-surface-100 dark:bg-surface-900/50">
      <button
        v-for="st in subTabs"
        :key="st.id"
        @click="sub = st.id"
        :class="[
          'flex-1 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-1.5',
          sub === st.id
            ? 'bg-white dark:bg-surface-700 text-primary-600 dark:text-primary-400 shadow-sm'
            : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300',
        ]"
      >
        <span class="material-symbols-rounded text-base">{{ st.icon }}</span>
        {{ st.label }}
      </button>
    </div>

    <!-- Lazy: each sub-tab mounts (and fetches) only when active -->
    <SharePeopleTab
      v-if="sub === 'people'"
      :item-id="item.id"
      :target-type="type"
      @changed="onChanged"
    />
    <ShareGroupsTab
      v-else-if="sub === 'groups'"
      :item-id="item.id"
      :target-type="type"
      @changed="onChanged"
    />
    <ShareGuestLinksTab
      v-else-if="sub === 'links' && showGuestLinks"
      :file-id="item.id"
      @changed="onChanged"
    />

    <!-- View-only restrictions (files only): apply to anyone with View access -->
    <ShareRestrictions
      v-if="type === 'file' && item?.id"
      :file-id="item.id"
      @changed="onChanged"
    />
  </div>
</template>
