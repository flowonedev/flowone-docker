<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const prefs = ref([])
const loading = ref(true)
const saving = ref(false)

const typeLabels = {
  ph_assigned: { label: 'Assigned to task', icon: 'assignment_ind' },
  ph_status_changed: { label: 'Status changed', icon: 'swap_horiz' },
  ph_card_updated: { label: 'Task updated', icon: 'edit_note' },
  ph_comment_added: { label: 'New comment', icon: 'comment' },
  ph_dependency_added: { label: 'Dependency added', icon: 'account_tree' },
  ph_dependency_removed: { label: 'Dependency removed', icon: 'account_tree' },
  ph_watcher_added: { label: 'Added as watcher', icon: 'visibility' },
  ph_inactivity: { label: 'Inactivity alert', icon: 'schedule' },
}

async function loadPrefs() {
  loading.value = true
  try {
    const { data } = await api.get('/project-hub/notification-prefs')
    prefs.value = data.prefs || []
  } catch (err) {
    console.error('[NotifPrefs] load error:', err)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    await api.put('/project-hub/notification-prefs', { prefs: prefs.value })
  } catch (err) {
    console.error('[NotifPrefs] save error:', err)
  } finally {
    saving.value = false
  }
}

function toggle(pref, channel) {
  pref[channel] = !pref[channel]
  save()
}

onMounted(loadPrefs)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center gap-2 mb-4">
      <span class="material-symbols-rounded text-lg text-surface-500">notifications_active</span>
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200">Notification Preferences</h3>
    </div>

    <div v-if="loading" class="flex items-center gap-2 text-surface-400 text-sm py-4">
      <span class="material-symbols-rounded animate-spin text-base">progress_activity</span>
      Loading...
    </div>

    <div v-else class="space-y-1">
      <div class="grid grid-cols-[1fr_60px_60px_60px] gap-2 px-3 py-1.5 text-xs text-surface-500 uppercase tracking-wider font-medium">
        <span>Event</span>
        <span class="text-center">In-app</span>
        <span class="text-center">Push</span>
        <span class="text-center">Email</span>
      </div>

      <div
        v-for="pref in prefs"
        :key="pref.notif_type"
        class="grid grid-cols-[1fr_60px_60px_60px] gap-2 items-center px-3 py-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
      >
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-base text-surface-400">
            {{ typeLabels[pref.notif_type]?.icon || 'notifications' }}
          </span>
          <span class="text-sm text-surface-700 dark:text-surface-300">
            {{ typeLabels[pref.notif_type]?.label || pref.notif_type }}
          </span>
        </div>

        <div class="flex justify-center">
          <button
            @click="toggle(pref, 'channel_inapp')"
            :class="[
              'w-9 h-5 rounded-full transition-colors relative',
              pref.channel_inapp ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-all',
                pref.channel_inapp ? 'left-[18px]' : 'left-0.5'
              ]"
            />
          </button>
        </div>

        <div class="flex justify-center">
          <button
            @click="toggle(pref, 'channel_push')"
            :class="[
              'w-9 h-5 rounded-full transition-colors relative',
              pref.channel_push ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-all',
                pref.channel_push ? 'left-[18px]' : 'left-0.5'
              ]"
            />
          </button>
        </div>

        <div class="flex justify-center">
          <button
            @click="toggle(pref, 'channel_email')"
            :class="[
              'w-9 h-5 rounded-full transition-colors relative',
              pref.channel_email ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-all',
                pref.channel_email ? 'left-[18px]' : 'left-0.5'
              ]"
            />
          </button>
        </div>
      </div>
    </div>

    <p v-if="saving" class="text-xs text-surface-400 flex items-center gap-1 mt-2">
      <span class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
      Saving...
    </p>
  </div>
</template>
