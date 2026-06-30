<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  msg: { type: Object, required: true },
  busy: { type: String, default: '' },
  addingToCalendar: { type: Boolean, default: false },
})

const emit = defineEmits(['rsvp', 'add-to-calendar'])

const { t } = useI18n()

const event = computed(() => props.msg?.calendar_event || {})

const method = computed(() => String(event.value.method || 'REQUEST').toUpperCase())

const isCancelled = computed(() => method.value === 'CANCEL')

const showRsvp = computed(() => {
  if (isCancelled.value) return false
  return method.value === 'REQUEST' || method.value === ''
})

const myResponse = computed(() => event.value.my_response || null)

const hasResponse = computed(() => !!myResponse.value)

// A given RSVP button is dimmed once a response exists and it's not the chosen one.
function isDimmed(status) {
  return hasResponse.value && myResponse.value !== status
}

const responseLabel = computed(() => {
  if (myResponse.value === 'accepted') return t('meetingInvite.youAccepted')
  if (myResponse.value === 'declined') return t('meetingInvite.youDeclined')
  if (myResponse.value === 'tentative') return t('meetingInvite.youMaybe')
  return ''
})

function onRsvp(status) {
  // One response only: ignore clicks once a response is recorded or while a
  // send is in flight. No re-sending / spamming.
  if (props.busy || hasResponse.value) return
  emit('rsvp', status)
}

function onAdd() {
  emit('add-to-calendar')
}
</script>

<template>
  <div class="meeting-invite-actions mt-3 flex flex-wrap items-center gap-2">
    <!-- Add to Calendar (always first, never dimmed) -->
    <button
      type="button"
      class="rsvp-btn rsvp-calendar"
      :disabled="addingToCalendar"
      @click="onAdd"
    >
      <span v-if="addingToCalendar" class="rsvp-spinner" aria-hidden="true"></span>
      <span class="material-symbols-rounded text-base">event</span>
      <span>{{ t('meetingInvite.addToCalendar') }}</span>
    </button>

    <!-- RSVP buttons -->
    <template v-if="showRsvp">
      <button
        type="button"
        class="rsvp-btn rsvp-accept"
        :class="{ 'rsvp-active': myResponse === 'accepted', 'rsvp-dim': isDimmed('accepted') }"
        :disabled="hasResponse || !!busy"
        @click="onRsvp('accepted')"
      >
        <span v-if="busy === 'accepted'" class="rsvp-spinner" aria-hidden="true"></span>
        <span class="material-symbols-rounded text-base">check</span>
        <span>{{ t('meetingInvite.accept') }}</span>
      </button>

      <button
        type="button"
        class="rsvp-btn rsvp-tentative"
        :class="{ 'rsvp-active': myResponse === 'tentative', 'rsvp-dim': isDimmed('tentative') }"
        :disabled="hasResponse || !!busy"
        @click="onRsvp('tentative')"
      >
        <span v-if="busy === 'tentative'" class="rsvp-spinner" aria-hidden="true"></span>
        <span class="material-symbols-rounded text-base">help</span>
        <span>{{ t('meetingInvite.maybe') }}</span>
      </button>

      <button
        type="button"
        class="rsvp-btn rsvp-decline"
        :class="{ 'rsvp-active': myResponse === 'declined', 'rsvp-dim': isDimmed('declined') }"
        :disabled="hasResponse || !!busy"
        @click="onRsvp('declined')"
      >
        <span v-if="busy === 'declined'" class="rsvp-spinner" aria-hidden="true"></span>
        <span class="material-symbols-rounded text-base">close</span>
        <span>{{ t('meetingInvite.decline') }}</span>
      </button>
    </template>

    <!-- Status pill (shown after a response, or when invite is cancelled) -->
    <span v-if="myResponse" class="rsvp-status" :data-status="myResponse">
      {{ responseLabel }}
    </span>
    <span v-else-if="isCancelled" class="rsvp-status" data-status="declined">
      {{ t('meetingInvite.cancelled') }}
    </span>
  </div>
</template>

<style scoped>
.rsvp-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.15s, border-color 0.15s, color 0.15s, opacity 0.15s;
  border: 1px solid transparent;
  background: transparent;
  color: rgb(var(--text-primary, 30 41 59));
}

.rsvp-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Once a response is chosen, the non-selected RSVP options fade back so
   the active choice (and Add to Calendar) stay prominent. */
.rsvp-btn.rsvp-dim {
  opacity: 0.4;
}
.rsvp-btn.rsvp-dim:hover:not(:disabled) {
  opacity: 0.7;
}

/* The chosen response stays fully visible even though it's now locked. */
.rsvp-btn.rsvp-active:disabled {
  opacity: 1;
}

.rsvp-accept {
  background: rgba(34, 197, 94, 0.12);
  color: #16a34a;
}
.rsvp-accept:hover:not(:disabled) {
  background: rgba(34, 197, 94, 0.2);
}
.rsvp-accept.rsvp-active {
  background: #16a34a;
  color: #fff;
}

.rsvp-tentative {
  background: rgba(245, 158, 11, 0.12);
  color: #d97706;
}
.rsvp-tentative:hover:not(:disabled) {
  background: rgba(245, 158, 11, 0.2);
}
.rsvp-tentative.rsvp-active {
  background: #d97706;
  color: #fff;
}

.rsvp-decline {
  background: rgba(239, 68, 68, 0.12);
  color: #dc2626;
}
.rsvp-decline:hover:not(:disabled) {
  background: rgba(239, 68, 68, 0.2);
}
.rsvp-decline.rsvp-active {
  background: #dc2626;
  color: #fff;
}

.rsvp-calendar {
  background: rgba(139, 92, 246, 0.12);
  color: #7c3aed;
}
.rsvp-calendar:hover:not(:disabled) {
  background: rgba(139, 92, 246, 0.2);
}

.dark .rsvp-btn {
  color: rgb(226 232 240);
}
.dark .rsvp-accept { color: #4ade80; }
.dark .rsvp-tentative { color: #fbbf24; }
.dark .rsvp-decline { color: #f87171; }
.dark .rsvp-calendar { color: #a78bfa; }

.rsvp-status {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 500;
  margin-left: 4px;
}
.rsvp-status[data-status="accepted"] {
  background: rgba(34, 197, 94, 0.15);
  color: #16a34a;
}
.rsvp-status[data-status="tentative"] {
  background: rgba(245, 158, 11, 0.15);
  color: #d97706;
}
.rsvp-status[data-status="declined"] {
  background: rgba(239, 68, 68, 0.15);
  color: #dc2626;
}

.rsvp-spinner {
  width: 12px;
  height: 12px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: rsvp-spin 0.6s linear infinite;
  display: inline-block;
}

@keyframes rsvp-spin {
  to { transform: rotate(360deg); }
}
</style>
