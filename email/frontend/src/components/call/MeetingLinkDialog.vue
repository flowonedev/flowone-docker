<script setup>
import { useI18n } from 'vue-i18n'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  show: { type: Boolean, default: false },
  meetingLink: { type: String, default: null },
  adminMeetingLink: { type: String, default: null },
  meetingTitle: { type: String, default: '' },
  // Optional overrides so non-calendar callers (e.g. chat "Call Link") can
  // tailor the wording without forking the dialog.
  headerLabel: { type: String, default: '' },
  shareNote: { type: String, default: '' },
  inviteNote: { type: String, default: '' },
  showInviteNote: { type: Boolean, default: true },
})

const emit = defineEmits(['close'])

const { t } = useI18n()
const toast = useToastStore()

/**
 * Join directly — ALWAYS in a new window/tab so the current app view
 * is never replaced by the call screen. Closes the dialog.
 */
function joinLink(link) {
  if (!link) return
  emit('close')
  try {
    const u = new URL(link, window.location.origin)
    window.open(u.href, '_blank', 'noopener')
  } catch (_) {
    window.open(link, '_blank', 'noopener')
  }
}

async function copyToClipboard(value) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    toast.success(t('calendarView.meetingLinkCopiedToClipboard'))
  } catch {
    const textarea = document.createElement('textarea')
    textarea.value = value
    document.body.appendChild(textarea)
    textarea.select()
    try {
      document.execCommand('copy')
      toast.success(t('calendarView.meetingLinkCopiedToClipboard'))
    } finally {
      document.body.removeChild(textarea)
    }
  }
}

function close() {
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="show"
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999] p-4"
        @click.self="close"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
          <div class="bg-gradient-to-r from-primary-500 to-primary-600 px-6 py-5 text-center">
            <span class="material-symbols-rounded text-white text-4xl mb-2 block">videocam</span>
            <h3 class="text-lg font-semibold text-white">{{ headerLabel || t('calendarView.meetingScheduled') }}</h3>
            <p v-if="meetingTitle" class="text-sm text-white/80 mt-1">{{ meetingTitle }}</p>
          </div>

          <div class="px-6 py-5">
            <p class="text-sm text-surface-600 dark:text-surface-400 mb-3">
              {{ shareNote || t('calendarView.shareThisLinkWithParticipants') }}
            </p>

            <div class="flex items-center gap-2 p-3 bg-surface-50 dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-700">
              <span class="material-symbols-rounded text-primary-500 text-lg flex-shrink-0">link</span>
              <p class="text-sm text-surface-700 dark:text-surface-300 truncate flex-1 select-all">{{ meetingLink }}</p>
              <button
                type="button"
                @click="copyToClipboard(meetingLink)"
                class="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-white bg-primary-500 hover:bg-primary-600 rounded-lg transition-colors flex items-center gap-1"
              >
                <span class="material-symbols-rounded text-sm">content_copy</span>
                {{ t('calendarSidebar.copy') }}
              </button>
              <button
                v-if="!adminMeetingLink"
                type="button"
                @click="joinLink(meetingLink)"
                class="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors flex items-center gap-1"
                :title="t('calendarView.joinMeeting')"
              >
                <span class="material-symbols-rounded text-sm">videocam</span>
                {{ t('calendarView.join') }}
              </button>
            </div>

            <div v-if="adminMeetingLink" class="mt-4 space-y-2">
              <p class="text-xs font-semibold text-amber-700 dark:text-amber-400 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">shield_person</span>
                {{ t('calendarView.adminHostLink') }}
              </p>
              <p class="text-xs text-surface-500">{{ t('calendarView.adminHostLinkWarning') }}</p>
              <div class="flex items-center gap-2 p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-200 dark:border-amber-500/30">
                <span class="material-symbols-rounded text-amber-600 text-lg flex-shrink-0">key</span>
                <p class="text-sm text-surface-700 dark:text-surface-300 truncate flex-1 select-all">{{ adminMeetingLink }}</p>
                <button
                  type="button"
                  @click="copyToClipboard(adminMeetingLink)"
                  class="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition-colors flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">content_copy</span>
                  {{ t('calendarSidebar.copy') }}
                </button>
              </div>
              <button
                type="button"
                @click="joinLink(adminMeetingLink)"
                class="w-full px-4 py-2.5 text-sm font-semibold text-white bg-amber-500 hover:bg-amber-600 rounded-xl transition-colors flex items-center justify-center gap-2"
                :title="t('meetingActions.openAdminLinkTitle')"
              >
                <span class="material-symbols-rounded text-lg">videocam</span>
                {{ t('calendarView.startMeeting') }}
              </button>
            </div>

            <p v-if="showInviteNote" class="text-xs text-surface-500 mt-3 text-center">
              {{ inviteNote || t('calendarView.participantsWillReceiveInviteEmail') }}
            </p>
          </div>

          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end">
            <button
              type="button"
              @click="close"
              class="px-5 py-2.5 text-sm font-medium text-white bg-primary-500 hover:bg-primary-600 rounded-xl transition-colors"
            >
              {{ t('calendarShareModal.done') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
