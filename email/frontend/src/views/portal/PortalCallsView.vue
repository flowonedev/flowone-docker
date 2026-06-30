<script setup>
/**
 * PortalCallsView - Client portal calls listing
 * Shows active, scheduled, and past calls. Allows joining active calls.
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import portalApi from '@/services/portalApi'

const router = useRouter()
const { t, locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

const calls = ref([])
const loading = ref(true)
const error = ref('')
const displayError = computed(() => {
  const msg = error.value
  return (typeof msg === 'string' && msg.startsWith('portalCallsView.')) ? t(msg) : msg
})

const activeCalls = computed(() => calls.value.filter(c => c.status === 'waiting' || c.status === 'active'))
const pastCalls = computed(() => calls.value.filter(c => c.status === 'ended'))

onMounted(() => fetchCalls())

async function fetchCalls() {
  loading.value = true
  try {
    const res = await portalApi.get('/portal/calls')
    if (res.data?.success) {
      calls.value = res.data.data?.calls || res.data.data || []
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'portalCallsView.failedToLoadCalls'
  } finally {
    loading.value = false
  }
}

function joinCall(call) {
  router.push({ name: 'portal-call-room', params: { callId: call.id } })
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(localeTag.value, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function formatDuration(seconds) {
  if (!seconds) return '—'
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return t('portalCallsView.durationFormat', { m, s })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-xl font-bold text-surface-900 dark:text-white">{{ $t('portalCallsView.calls') }}</h2>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-16">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-16">
      <span class="material-symbols-rounded text-4xl text-red-400">error</span>
      <p class="mt-2 text-surface-500">{{ displayError }}</p>
    </div>

    <div v-else>
      <!-- Active/Scheduled Calls -->
      <div v-if="activeCalls.length > 0" class="mb-8">
        <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 mb-3 flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
          {{ $t('portalCallsView.activeAndScheduled') }}
        </h3>
        <div class="space-y-3">
          <div v-for="call in activeCalls" :key="call.id"
               class="bg-white dark:bg-surface-800 rounded-xl border border-green-200 dark:border-green-500/30 p-4">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-2xl text-green-600 dark:text-green-400">
                  {{ call.status === 'active' ? 'videocam' : 'schedule' }}
                </span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-surface-900 dark:text-white">
                  {{ call.call_type === 'scheduled' ? $t('portalCallsView.scheduledCall') : $t('portalCallsView.quickCall') }}
                </p>
                <p class="text-sm text-surface-500">
                  {{ call.status === 'active'
                    ? $t('portalCallsView.inProgress')
                    : call.scheduled_at
                      ? $t('portalCallsView.scheduledFor', { date: formatDate(call.scheduled_at) })
                      : $t('portalCallsView.waitingForHost')
                  }}
                </p>
              </div>
              <button @click="joinCall(call)"
                      class="px-5 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 text-white font-medium text-sm flex items-center gap-2 transition-colors">
                <span class="material-symbols-rounded text-lg">call</span>
                {{ $t('portalCallsView.join') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty state for active -->
      <div v-else class="text-center py-12 mb-8">
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">call</span>
        <h3 class="text-lg font-semibold text-surface-600 dark:text-surface-300 mt-3">{{ $t('portalCallsView.noActiveCalls') }}</h3>
        <p class="text-sm text-surface-400 mt-1">{{ $t('portalCallsView.yourTeamWillInviteYou') }}</p>
      </div>

      <!-- Past Calls -->
      <div v-if="pastCalls.length > 0">
        <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 mb-3">{{ $t('portalCallsView.pastCalls') }}</h3>
        <div class="space-y-2">
          <div v-for="call in pastCalls" :key="call.id"
               class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center">
                <span class="material-symbols-rounded text-lg text-surface-400">call_end</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-700 dark:text-surface-200">
                  {{ call.call_type === 'scheduled' ? $t('portalCallsView.scheduledCall') : $t('portalCallsView.quickCall') }}
                </p>
                <div class="flex items-center gap-2 text-xs text-surface-400">
                  <span>{{ formatDate(call.ended_at || call.created_at) }}</span>
                  <span v-if="call.duration_seconds">· {{ formatDuration(call.duration_seconds) }}</span>
                  <span v-if="call.had_screen_share" class="flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-xs">screen_share</span> {{ $t('portalCallsView.screenShared') }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

