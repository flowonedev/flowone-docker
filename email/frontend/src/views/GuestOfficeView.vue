<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { fetchGuestOfficeConfig, loadDocsApi } from '@/services/officeApiService'
import { useOfficePresence } from '@/composables/useOfficePresence'
import { useOfficePluginBridge } from '@/composables/useOfficePluginBridge'
import { OFFICE_PRESENCE_ENABLED } from '@/config/officePresence'
import { getInitials } from '@/utils/collabColors'

const route = useRoute()
const { t, locale } = useI18n()

const token = String(route.params.token || '')

const step = ref('name') // 'name' | 'loading' | 'editor' | 'error'
const guestName = ref(localStorage.getItem('flowone_office_guest_name') || '')
const error = ref(null)
const fileMeta = ref(null)
const role = ref('viewer')
const followedEmail = ref(null)

let docEditor = null
const EDITOR_CONTAINER_ID = 'flowone-guest-office-editor'

// ============================================================
// Presence: live cursors (same room as authenticated editors)
// ============================================================

const presence = useOfficePresence()
const { others: participants } = presence
const followedUser = computed(() =>
  participants.value.find((u) => u.email === followedEmail.value) || null
)
const bridge = useOfficePluginBridge({
  getSelf: () => presence.self.value,
  onCursor: (cursor) => presence.setCursor(cursor),
  onFollowStopped: () => { followedEmail.value = null },
  onReady: () => bridge.sendCursors(presence.cursors.value),
})

watch(presence.cursors, (list) => bridge.sendCursors(list), { deep: true })
watch(participants, (list) => {
  if (followedEmail.value && !list.some((u) => u.email === followedEmail.value)) {
    followedEmail.value = null
  }
}, { deep: true })
watch(followedEmail, (email) => bridge.sendFollow(email))

function toggleFollow(user) {
  followedEmail.value = followedEmail.value === user.email ? null : user.email
}

async function connectGuestPresence() {
  try {
    await presence.connectGuest(token, { name: guestName.value.trim() })
  } catch (e) {
    // Presence is an enhancement - the editor works without it.
    console.warn('[Office] Guest presence unavailable:', e?.message || e)
  }
}

async function joinDocument() {
  const name = guestName.value.trim()
  if (!name) return
  localStorage.setItem('flowone_office_guest_name', name)

  step.value = 'loading'
  error.value = null
  try {
    const data = await fetchGuestOfficeConfig(token, {
      name,
      lang: locale.value === 'hu' ? 'hu' : 'en',
    })
    fileMeta.value = data.file
    role.value = data.role

    const DocsAPI = await loadDocsApi(data.server_url)
    // Listen for the in-editor presence plugin before it mounts.
    if (OFFICE_PRESENCE_ENABLED) bridge.start(data.server_url)
    step.value = 'editor'
    // Wait for the DOM update so the container div exists before mounting.
    await nextTick()
    docEditor = new DocsAPI.DocEditor(EDITOR_CONTAINER_ID, {
      ...data.editor_config,
      width: '100%',
      height: '100%',
    })
    if (OFFICE_PRESENCE_ENABLED) connectGuestPresence()
  } catch (e) {
    console.error('[Office] Guest join failed', e)
    error.value = e.message || 'This link is invalid, expired or revoked'
    step.value = 'error'
  }
}

onMounted(() => {
  if (!token) {
    error.value = t('officeEditor.guestInvalidLink')
    step.value = 'error'
  }
})

onBeforeUnmount(() => {
  if (OFFICE_PRESENCE_ENABLED) presence.disconnect()
  if (docEditor) {
    try { docEditor.destroyEditor() } catch (e) { /* editor already gone */ }
    docEditor = null
  }
})
</script>

<template>
  <div class="fixed inset-0 flex flex-col bg-surface-50 dark:bg-surface-950">
    <!-- Name entry -->
    <div v-if="step === 'name'" class="flex-1 flex items-center justify-center p-4">
      <div class="w-full max-w-md bg-white dark:bg-surface-900 rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 p-6 sm:p-8">
        <div class="flex items-center gap-3 mb-6">
          <span class="material-symbols-rounded text-3xl text-primary-500">description</span>
          <div>
            <h1 class="text-lg font-semibold text-surface-900 dark:text-surface-50">
              {{ t('officeEditor.guestTitle') }}
            </h1>
            <p class="text-sm text-surface-500">{{ t('officeEditor.guestSubtitle') }}</p>
          </div>
        </div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
          {{ t('officeEditor.guestYourName') }}
        </label>
        <input
          v-model="guestName"
          type="text"
          maxlength="60"
          :placeholder="t('officeEditor.guestNamePlaceholder')"
          class="w-full px-3 py-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
          @keyup.enter="joinDocument"
        />
        <button
          @click="joinDocument"
          :disabled="!guestName.trim()"
          class="w-full mt-4 h-11 rounded-lg bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium flex items-center justify-center gap-2"
        >
          <span class="material-symbols-rounded text-lg">login</span>
          {{ t('officeEditor.guestOpenDocument') }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-else-if="step === 'loading'" class="flex-1 flex flex-col items-center justify-center gap-3">
      <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
      <p class="text-sm text-surface-500">{{ t('officeEditor.openingDocument') }}</p>
    </div>

    <!-- Error -->
    <div v-else-if="step === 'error'" class="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center">
      <span class="material-symbols-rounded text-5xl text-red-400">link_off</span>
      <p class="text-base font-medium text-surface-800 dark:text-surface-100">{{ error }}</p>
    </div>

    <!-- Editor -->
    <template v-else>
      <!-- FlowOne strip (branding + presence avatars). Hidden while in-editor
           presence is disabled so guests don't get a redundant header stacked
           on top of OnlyOffice's own title bar (which already shows the name). -->
      <div
        v-if="OFFICE_PRESENCE_ENABLED"
        class="h-11 flex-shrink-0 flex items-center gap-2 px-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900"
      >
        <span class="font-semibold text-sm text-primary-600 dark:text-primary-400">FlowOne</span>
        <span class="text-surface-300 dark:text-surface-600">/</span>
        <span class="flex-1 min-w-0 text-sm text-surface-800 dark:text-surface-200 truncate">
          {{ fileMeta?.name }}
        </span>

        <span
          v-if="OFFICE_PRESENCE_ENABLED && followedUser"
          class="flex items-center gap-1 text-[11px] text-surface-500 flex-shrink-0"
        >
          <span class="material-symbols-rounded text-[14px]">visibility</span>
          {{ t('officeEditor.followingUser', { name: followedUser.name }) }}
        </span>

        <!-- Live participants (click an avatar to follow that user) -->
        <div v-if="OFFICE_PRESENCE_ENABLED && participants.length" class="flex items-center flex-shrink-0">
          <button
            v-for="user in participants"
            :key="user.email"
            @click="toggleFollow(user)"
            class="relative w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-semibold text-white -ml-1 first:ml-0 border border-white/60 transition-transform hover:scale-110 hover:z-10"
            :class="followedEmail === user.email ? 'ring-2 ring-offset-1 ring-primary-400 z-10' : ''"
            :style="{ backgroundColor: user.color }"
            :title="followedEmail === user.email
              ? t('officeEditor.stopFollowing', { name: user.name })
              : t('officeEditor.followUser', { name: user.name })"
          >
            {{ getInitials(user.name) }}
          </button>
        </div>

        <span
          v-if="role === 'viewer'"
          class="text-[11px] px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-800 text-surface-500"
        >
          {{ t('officeEditor.viewOnly') }}
        </span>
      </div>
      <div class="flex-1 relative min-h-0">
        <div :id="EDITOR_CONTAINER_ID" class="w-full h-full"></div>
      </div>
    </template>
  </div>
</template>
