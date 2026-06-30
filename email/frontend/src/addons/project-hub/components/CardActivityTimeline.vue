<template>
  <div class="card-activity-timeline">
    <div v-if="loading && events.length === 0" class="flex items-center justify-center py-8">
      <span class="material-symbols-rounded animate-spin text-surface-400">progress_activity</span>
    </div>

    <div v-else-if="events.length === 0" class="text-sm text-surface-400 dark:text-surface-500 py-8 text-center">
      No activity yet
    </div>

    <template v-else>
      <div class="text-xs text-surface-500 dark:text-surface-400 mb-3">
        {{ total }} event{{ total !== 1 ? 's' : '' }}
      </div>

      <div class="space-y-0 max-h-[480px] overflow-y-auto pr-1">
        <template v-for="(group, date) in groupedByDay" :key="date">
          <div class="sticky top-0 z-10 bg-white dark:bg-surface-800 py-1.5 mb-1">
            <span class="text-[11px] font-semibold text-surface-400 dark:text-surface-500 uppercase tracking-wide">
              {{ formatDayHeader(date) }}
            </span>
          </div>

          <div
            v-for="(ev, idx) in group"
            :key="ev.id"
            class="relative flex items-start gap-2.5 py-2 group"
          >
            <div
              class="absolute left-[11px] top-8 bottom-0 w-px bg-surface-200 dark:bg-surface-700"
              :class="{ 'hidden': idx === group.length - 1 }"
            ></div>

            <div
              class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 z-10"
              :class="ev.action === 'work_session' && ev.details?.source ? iconBgClass(ev.details.source) || iconBgClass(ev.action) : iconBgClass(ev.action)"
            >
              <span class="material-symbols-rounded text-xs text-white">{{ ev.action === 'work_session' && ev.details?.source ? (actionIcon(ev.details.source) || actionIcon(ev.action)) : actionIcon(ev.action) }}</span>
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex items-baseline gap-1.5 flex-wrap">
                <span class="text-xs font-medium text-surface-800 dark:text-surface-200">
                  {{ displayName(ev.user_email) }}
                </span>
                <span class="text-xs text-surface-500 dark:text-surface-400">
                  {{ actionLabel(ev) }}
                </span>
              </div>
              <p v-if="detailText(ev)" class="text-xs text-surface-500 dark:text-surface-400 mt-0.5 line-clamp-2">
                {{ detailText(ev) }}
              </p>
              <span class="text-[11px] text-surface-400 dark:text-surface-500 mt-0.5 block">
                {{ formatTime(ev.created_at) }}
              </span>
            </div>
          </div>
        </template>
      </div>

      <div v-if="hasMore" class="flex justify-center pt-3">
        <button
          @click="loadMore"
          :disabled="loading"
          class="text-xs text-primary-600 dark:text-primary-400 hover:underline disabled:opacity-50"
        >
          {{ loading ? 'Loading...' : 'Load more' }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/services/api'

const props = defineProps({
  cardId: { type: Number, required: true },
})

const events = ref([])
const fileActivities = ref([])
const total = ref(0)
const loading = ref(false)
const offset = ref(0)
const PAGE_SIZE = 50

const hasMore = computed(() => events.value.length < total.value)

const allEvents = computed(() => {
  const mapped = fileActivities.value.map(fa => ({
    id: 'fa-' + fa.id,
    action: 'watch_file_edited',
    user_email: fa.user_email,
    created_at: fa.created_at,
    details: {
      file_name: fa.file_name,
      file_path: fa.file_path,
      duration_seconds: fa.duration_seconds,
      profile_name: fa.profile_name,
      user_display_name: fa.user_display_name,
    },
  }))
  return [...events.value, ...mapped].sort((a, b) => {
    const da = a.created_at || ''
    const db = b.created_at || ''
    return db.localeCompare(da)
  })
})

const groupedByDay = computed(() => {
  const groups = {}
  for (const ev of allEvents.value) {
    const day = ev.created_at ? ev.created_at.substring(0, 10) : 'unknown'
    if (!groups[day]) groups[day] = []
    groups[day].push(ev)
  }
  return groups
})

async function fetchEvents(reset = false) {
  if (loading.value) return
  loading.value = true
  try {
    if (reset) {
      offset.value = 0
      events.value = []
      fileActivities.value = []
    }
    const [actRes, faRes] = await Promise.all([
      api.get(`/project-hub/cards/${props.cardId}/activity`, {
        params: { limit: PAGE_SIZE, offset: offset.value },
      }),
      reset ? api.get(`/watch-folders/file-activity/card/${props.cardId}`).catch(() => ({ data: { data: [] } })) : Promise.resolve(null),
    ])
    const data = actRes.data
    if (reset) {
      events.value = data.events || []
    } else {
      events.value.push(...(data.events || []))
    }
    total.value = data.total || 0
    offset.value = events.value.length
    if (faRes && faRes.data?.data) {
      fileActivities.value = faRes.data.data
    }
  } catch (e) {
    console.error('Failed to load activity', e)
  } finally {
    loading.value = false
  }
}

function loadMore() {
  fetchEvents(false)
}

watch(() => props.cardId, () => fetchEvents(true), { immediate: false })
onMounted(() => fetchEvents(true))

function displayName(email) {
  if (!email) return 'System'
  const local = email.split('@')[0]
  return local.charAt(0).toUpperCase() + local.slice(1)
}

function actionIcon(action) {
  const map = {
    assignee_added: 'person_add',
    assignee_removed: 'person_remove',
    status_changed: 'swap_horiz',
    comment_added: 'chat',
    card_updated: 'edit',
    card_created: 'add_circle',
    card_promoted: 'upgrade',
    work_session: 'timer',
    portal_call: 'video_call',
    calendar_event: 'event',
    dependency_added: 'link',
    dependency_removed: 'link_off',
    watcher_added: 'visibility',
    file_added: 'attach_file',
    card_completed: 'check_circle',
    card_reopened: 'replay',
    watch_file_edited: 'visibility',
  }
  return map[action] || 'info'
}

function iconBgClass(action) {
  const map = {
    assignee_added: 'bg-blue-500',
    assignee_removed: 'bg-orange-500',
    status_changed: 'bg-purple-500',
    comment_added: 'bg-teal-500',
    card_updated: 'bg-amber-500',
    card_created: 'bg-sky-600',
    card_promoted: 'bg-sky-500',
    work_session: 'bg-green-500',
    portal_call: 'bg-emerald-500',
    calendar_event: 'bg-blue-500',
    dependency_added: 'bg-indigo-500',
    dependency_removed: 'bg-rose-500',
    watcher_added: 'bg-cyan-500',
    file_added: 'bg-pink-500',
    card_completed: 'bg-emerald-600',
    card_reopened: 'bg-yellow-500',
    watch_file_edited: 'bg-amber-500',
  }
  return map[action] || 'bg-surface-400'
}

function actionLabel(ev) {
  const d = ev.details || {}
  const map = {
    assignee_added: () => {
      const target = d.assignee_email
      if (target && target.toLowerCase() === (ev.user_email || '').toLowerCase()) return 'was assigned'
      return target ? `assigned ${displayName(target)}` : 'was assigned'
    },
    assignee_removed: () => {
      const target = d.assignee_email
      return target ? `removed ${displayName(target)}` : 'removed an assignee'
    },
    status_changed: () => {
      const who = d.assignee_email
      const status = d.new_status || d.current_status
      if (who && status) return `changed ${displayName(who)}'s status to "${status}"`
      if (status) return `changed status to "${status}"`
      return 'changed status'
    },
    comment_added: () => 'added a comment',
    card_updated: () => {
      const fields = (d.changed_fields || []).filter(f => f !== '_timestamp')
      if (!fields.length) return 'updated the card'
      const labels = fields.map(f => fieldLabel(f))
      return `updated ${labels.join(', ')}`
    },
    card_created: () => 'created this card',
    card_promoted: () => 'promoted from subtask',
    work_session: () => {
      const sec = d.duration_seconds || 0
      const src = d.source
      if (src === 'portal_call') {
        const dur = sec >= 3600 ? `${(sec / 3600).toFixed(1)}h` : `${Math.round(sec / 60)}m`
        return `video call -- ${dur}`
      }
      if (src === 'calendar_event') {
        const dur = sec >= 3600 ? `${(sec / 3600).toFixed(1)}h` : `${Math.round(sec / 60)}m`
        return `meeting -- ${dur}`
      }
      let label = ''
      if (sec >= 3600) label = `tracked ${(sec / 3600).toFixed(1)}h`
      else label = `tracked ${Math.round(sec / 60)}m`
      if (src && src !== 'manual') label += ` (${src.replace('_', ' ')})`
      return label
    },
    dependency_added: () => 'added a dependency',
    dependency_removed: () => 'removed a dependency',
    watcher_added: () => {
      const target = d.watcher_email
      if (target && target.toLowerCase() === (ev.user_email || '').toLowerCase()) return 'started watching'
      return target ? `added ${displayName(target)} as watcher` : 'added a watcher'
    },
    file_added: () => {
      const name = d.filename
      return name ? `attached "${name}"` : 'attached a file'
    },
    card_completed: () => 'completed the card',
    card_reopened: () => 'reopened the card',
    watch_file_edited: () => {
      const sec = d.duration_seconds || 0
      const dur = sec >= 3600 ? `${(sec / 3600).toFixed(1)}h` : `${Math.round(sec / 60)}m`
      const profile = d.profile_name ? ` (${d.profile_name})` : ''
      return `edited "${d.file_name}" for ${dur}${profile}`
    },
  }
  const fn = map[ev.action]
  return fn ? fn() : ev.action.replace(/_/g, ' ')
}

function fieldLabel(field) {
  const map = {
    title: 'title',
    description: 'description',
    due_date: 'due date',
    start_date: 'start date',
    time_estimate_seconds: 'time estimate',
    assigned_to: 'assignee',
    completed: 'completion',
    priority: 'priority',
    card_color: 'color',
    position: 'position',
    list_id: 'list',
  }
  return map[field] || field.replace(/_/g, ' ')
}

function stripHtml(str) {
  if (!str) return ''
  return str.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim()
}

function detailText(ev) {
  const d = ev.details || {}
  if (ev.action === 'comment_added') return stripHtml(d.preview || d.content || '')
  if (ev.action === 'work_session' && d.entity_name) return d.entity_name
  if (ev.action === 'card_created' && d.title) return `"${d.title}"`
  if (ev.action === 'file_added' && d.filename) return d.filename
  return ''
}

function formatDayHeader(dateStr) {
  if (dateStr === 'unknown') return 'Unknown'
  const d = new Date(dateStr + 'T00:00:00')
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const diff = (today - d) / 86400000
  if (diff < 1 && diff >= 0) return 'Today'
  if (diff >= 1 && diff < 2) return 'Yesterday'
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

function formatTime(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr.replace(' ', 'T'))
  return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}
</script>
