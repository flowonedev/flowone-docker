<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import MeetingActions from '@/addons/calendar/components/MeetingActions.vue'

const router = useRouter()
const calendar = useCalendarStore()

function countdown(r) {
  return calendar.getEventCountdown({ start_time: r.startTime, end_time: r.endTime })
}

// Defensively hide any reminder whose event has ended (the store also prunes
// these every check cycle; this covers the gap between cycles).
const reminders = computed(() => calendar.activeReminders.filter(r => countdown(r).status !== 'ended'))

function countdownClass(status) {
  switch (status) {
    case 'now':
    case 'imminent':
      return 'bg-red-500 text-white'
    case 'ongoing':
      return 'bg-green-500 text-white'
    case 'upcoming':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400'
    default:
      return 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300'
  }
}

function openEvent(r) {
  router.push({ path: '/calendar', query: { event: r.eventId } })
  calendar.dismissReminder(r.key)
}

onMounted(() => {
  // Ensure the countdown text stays live.
  calendar.startNowTicker()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="reminder-pop">
      <div
        v-if="reminders.length"
        class="fixed top-20 right-4 z-[60] w-80 max-w-[calc(100vw-2rem)]"
      >
        <div class="rounded-2xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 shadow-2xl overflow-hidden">
          <!-- Header -->
          <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">notifications_active</span>
              <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ $t('calendarReminders.title') }}</h3>
            </div>
            <button
              type="button"
              @click="calendar.dismissAllReminders()"
              class="text-xs font-medium text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
            >
              {{ $t('calendarReminders.dismissAll') }}
            </button>
          </div>

          <!-- Reminder list -->
          <div class="max-h-[60vh] overflow-y-auto divide-y divide-surface-100 dark:divide-surface-700">
            <div
              v-for="r in reminders"
              :key="r.key"
              class="p-3"
            >
              <div class="flex items-start gap-2.5">
                <span
                  class="w-1 self-stretch rounded-full flex-shrink-0"
                  :style="{ backgroundColor: r.color || '#a855f7' }"
                ></span>

                <div class="flex-1 min-w-0">
                  <!-- Title + countdown -->
                  <div class="flex items-start justify-between gap-2">
                    <button
                      type="button"
                      @click="openEvent(r)"
                      class="text-left text-sm font-medium text-surface-900 dark:text-surface-100 truncate hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                    >
                      {{ r.title || $t('calendarReminders.untitled') }}
                    </button>
                    <button
                      type="button"
                      @click="calendar.dismissReminder(r.key)"
                      class="flex-shrink-0 w-6 h-6 flex items-center justify-center rounded-full text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                      :title="$t('calendarReminders.dismiss')"
                    >
                      <span class="material-symbols-rounded text-base leading-none">close</span>
                    </button>
                  </div>

                  <div class="mt-1">
                    <span
                      :class="[
                        'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold',
                        countdownClass(countdown(r).status),
                      ]"
                    >
                      <span class="material-symbols-rounded text-xs leading-none">
                        {{ countdown(r).status === 'ongoing' ? 'play_circle' : 'schedule' }}
                      </span>
                      {{ countdown(r).text }}
                    </span>
                  </div>

                  <!-- Meeting actions (time + links + participants) -->
                  <div v-if="r.is_meeting" class="mt-2.5">
                    <MeetingActions
                      :event-id="r.eventId"
                      :start-time="r.startTime"
                      :end-time="r.endTime"
                      :is-host="r.isHost"
                      layout="stack"
                    />
                  </div>

                  <!-- Snooze (non-meeting) -->
                  <div v-else class="mt-2 flex items-center gap-3">
                    <button
                      type="button"
                      @click="calendar.snoozeReminder(r.key, 5)"
                      class="text-[11px] font-medium text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
                    >
                      {{ $t('calendarReminders.snooze5') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.reminder-pop-enter-active,
.reminder-pop-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.reminder-pop-enter-from,
.reminder-pop-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
