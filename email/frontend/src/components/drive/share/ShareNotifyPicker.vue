<script setup>
/**
 * ShareNotifyPicker - optional "notify" control inside the Share link tab.
 *
 * Lets the owner send the public link to internal colleagues / groups. Only
 * ids are sent; emails are resolved server-side (no arbitrary mail relay).
 * Delivery is an in-app notification, not an external email.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToastStore } from '@/stores/toast'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { notifyShareLink } from '@/services/driveShareApi'

const props = defineProps({
  targetType: { type: String, default: 'file' },
  itemId: { type: Number, required: true },
})

const { t } = useI18n()
const toast = useToastStore()
const colleagues = useColleaguesStore()

const open = ref(false)
const search = ref('')
const selectedUserIds = ref([])
const selectedGroupIds = ref([])
const sending = ref(false)

const filteredColleagues = computed(() => {
  const q = search.value.toLowerCase().trim()
  return colleagues.sortedColleagues
    .filter((c) => {
      if (!q) return true
      return (
        c.email.toLowerCase().includes(q) ||
        (c.display_name && c.display_name.toLowerCase().includes(q))
      )
    })
    .slice(0, 30)
})

const totalSelected = computed(() => selectedUserIds.value.length + selectedGroupIds.value.length)

function toggleUser(id) {
  const i = selectedUserIds.value.indexOf(id)
  if (i === -1) selectedUserIds.value.push(id)
  else selectedUserIds.value.splice(i, 1)
}

function toggleGroup(id) {
  const i = selectedGroupIds.value.indexOf(id)
  if (i === -1) selectedGroupIds.value.push(id)
  else selectedGroupIds.value.splice(i, 1)
}

async function send() {
  if (totalSelected.value === 0) {
    toast.error(t('unifiedShare.notifyNoRecipients'))
    return
  }
  sending.value = true
  try {
    const res = await notifyShareLink(props.targetType, props.itemId, {
      userIds: [...selectedUserIds.value],
      groupIds: [...selectedGroupIds.value],
    })
    if (res.success) {
      toast.success(t('unifiedShare.notifySentToast', { count: res.sent }))
      selectedUserIds.value = []
      selectedGroupIds.value = []
      open.value = false
    } else {
      toast.error(res.error || t('unifiedShare.notifyFailed'))
    }
  } finally {
    sending.value = false
  }
}

onMounted(() => {
  colleagues.fetchColleagues()
  colleagues.fetchGroups()
})
</script>

<template>
  <div class="border-t border-surface-200 dark:border-surface-700 pt-4">
    <button
      type="button"
      @click="open = !open"
      class="w-full flex items-center justify-between text-sm font-medium text-surface-700 dark:text-surface-300"
    >
      <span class="flex items-center gap-1.5">
        <span class="material-symbols-rounded text-lg text-surface-400">notifications</span>
        {{ t('unifiedShare.notifyTitle') }}
      </span>
      <span class="material-symbols-rounded text-surface-400">{{ open ? 'expand_less' : 'expand_more' }}</span>
    </button>

    <div v-if="open" class="mt-3 space-y-3">
      <p class="text-xs text-surface-500">{{ t('unifiedShare.notifyDesc') }}</p>

      <!-- Groups -->
      <div v-if="colleagues.sortedGroups.length" class="space-y-1.5">
        <p class="text-xs font-medium text-surface-500">{{ t('unifiedShare.subGroups') }}</p>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="group in colleagues.sortedGroups"
            :key="group.id"
            type="button"
            @click="toggleGroup(group.id)"
            :class="[
              'px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
              selectedGroupIds.includes(group.id)
                ? 'bg-primary-500 border-primary-500 text-white'
                : 'border-surface-300 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:border-primary-400',
            ]"
          >
            {{ group.name }}
          </button>
        </div>
      </div>

      <!-- Colleagues -->
      <div class="space-y-1.5">
        <p class="text-xs font-medium text-surface-500">{{ t('unifiedShare.subPeople') }}</p>
        <input
          v-model="search"
          type="text"
          :placeholder="t('unifiedShare.notifySearch')"
          class="input w-full text-sm"
        />
        <div class="max-h-40 overflow-y-auto rounded-lg border border-surface-200 dark:border-surface-700 divide-y divide-surface-100 dark:divide-surface-700/60">
          <button
            v-for="c in filteredColleagues"
            :key="c.id"
            type="button"
            @click="toggleUser(c.id)"
            class="w-full px-3 py-2 flex items-center gap-2.5 text-left hover:bg-surface-50 dark:hover:bg-surface-700/50"
          >
            <span
              class="w-4 h-4 rounded flex items-center justify-center border"
              :class="selectedUserIds.includes(c.id)
                ? 'bg-primary-500 border-primary-500 text-white'
                : 'border-surface-300 dark:border-surface-600'"
            >
              <span v-if="selectedUserIds.includes(c.id)" class="material-symbols-rounded text-[13px] leading-none">check</span>
            </span>
            <div class="min-w-0">
              <p class="text-sm text-surface-900 dark:text-surface-100 truncate">{{ c.display_name || c.email.split('@')[0] }}</p>
              <p class="text-xs text-surface-500 truncate">{{ c.email }}</p>
            </div>
          </button>
          <p v-if="filteredColleagues.length === 0" class="px-3 py-3 text-center text-xs text-surface-500">
            {{ t('unifiedShare.notifyNoColleagues') }}
          </p>
        </div>
      </div>

      <button
        @click="send"
        :disabled="sending || totalSelected === 0"
        class="w-full btn-primary"
      >
        <span v-if="sending" class="material-symbols-rounded animate-spin">progress_activity</span>
        <span v-else class="material-symbols-rounded">send</span>
        {{ t('unifiedShare.notifySend') }}<span v-if="totalSelected > 0"> ({{ totalSelected }})</span>
      </button>
    </div>
  </div>
</template>
