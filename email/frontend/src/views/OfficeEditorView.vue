<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import { officeApi, loadDocsApi } from '@/services/officeApiService'
import { useOfficePresence } from '@/composables/useOfficePresence'
import { useOfficePluginBridge } from '@/composables/useOfficePluginBridge'
import { OFFICE_PRESENCE_ENABLED } from '@/config/officePresence'
import { getInitials } from '@/utils/collabColors'
import { useShareModalStore } from '@/stores/shareModal'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const { t, locale } = useI18n()
const shareModal = useShareModalStore()
const toast = useToastStore()

const loading = ref(true)
const error = ref(null)
const fileMeta = ref(null)
const role = ref('viewer')
const isOwner = ref(false)
const followedEmail = ref(null)

// Once the editor is live, OnlyOffice's own blue top bar is behind the
// overlay so controls use the light-on-blue style; before that (loading /
// error) the background is the plain app surface, so they need dark text.
const editorReady = computed(() => !loading.value && !error.value)

let docEditor = null
const EDITOR_CONTAINER_ID = 'flowone-office-editor'

const fileId = Number(route.params.fileId)

function openShare() {
  if (!fileMeta.value) return
  shareModal.open(
    {
      id: fileId,
      name: fileMeta.value.name,
      extension: fileMeta.value.extension,
      mime_type: fileMeta.value.mime_type,
    },
    'file',
    { defaultTab: 'collaborate' }
  )
}

// ============================================================
// Inline rename (click the file name in the header to edit)
// ============================================================

const renaming = ref(false)
const renameValue = ref('')
const renameInput = ref(null)

// Split the file name so the user only edits the base; the extension is
// preserved so we never break the document type (.docx, .xlsx, .pptx...).
const fileExt = computed(() => {
  const name = fileMeta.value?.name || ''
  const idx = name.lastIndexOf('.')
  return idx > 0 ? name.slice(idx) : ''
})
const fileBaseName = computed(() => {
  const name = fileMeta.value?.name || ''
  const idx = name.lastIndexOf('.')
  return idx > 0 ? name.slice(0, idx) : name
})

// OnlyOffice tints its toolbar header by document type. We mirror those
// colours so the Back button's background blends into the bar and masks the
// Save button it sits on top of (Save is redundant under co-edit autosave).
const appBarColor = computed(() => {
  const ext = (fileMeta.value?.extension || fileExt.value || '')
    .replace('.', '')
    .toLowerCase()
  if (['xls', 'xlsx', 'xlsm', 'xlsb', 'csv', 'ods'].includes(ext)) return '#3a8056' // spreadsheet green
  if (['ppt', 'pptx', 'ppsx', 'pps', 'odp'].includes(ext)) return '#b75b44' // presentation red
  if (ext === 'pdf') return '#b75b44'
  return '#446995' // document blue (default)
})

const canRename = computed(() =>
  role.value === 'editor' && !loading.value && !error.value && !!fileMeta.value
)

function startRename() {
  if (!canRename.value) return
  renameValue.value = fileBaseName.value
  renaming.value = true
  nextTick(() => {
    renameInput.value?.focus()
    renameInput.value?.select()
  })
}

async function commitRename() {
  if (!renaming.value) return
  // Close immediately so the trailing blur event can't fire this twice.
  renaming.value = false

  const base = renameValue.value.trim()
  if (!base || base === fileBaseName.value) return

  // The office rename also pushes the new title to the live editor session so
  // OnlyOffice's own header (the visible title) updates in place.
  try {
    const res = await officeApi.renameFile(fileId, base + fileExt.value)
    const newName = res.data?.data?.name || base + fileExt.value
    fileMeta.value.name = newName
    toast.success(t('officeEditor.renameSuccess'))
  } catch (e) {
    console.error('[Office] Rename failed', e)
    toast.error(t('officeEditor.renameFailed'))
  }
}

function cancelRename() {
  renaming.value = false
}

// ============================================================
// Presence: awareness room + bridge to the in-editor plugin
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

// Relay remote cursors into the editor plugin as they change.
watch(presence.cursors, (list) => bridge.sendCursors(list), { deep: true })

// If the followed user leaves, drop follow mode.
watch(participants, (list) => {
  if (followedEmail.value && !list.some((u) => u.email === followedEmail.value)) {
    followedEmail.value = null
  }
}, { deep: true })

watch(followedEmail, (email) => bridge.sendFollow(email))

function toggleFollow(user) {
  followedEmail.value = followedEmail.value === user.email ? null : user.email
}

// ============================================================
// Editing-status heartbeat (Drive "X is editing" indicator)
// ============================================================

let heartbeatTimer = null

function sendEditingStatus(isEditing) {
  if (!fileMeta.value?.name) return
  api.post('/drive/editing-status', {
    filename: fileMeta.value.name,
    folder_id: fileMeta.value.folder_id ?? null,
    is_editing: isEditing,
  }).catch(() => { /* presence bookkeeping only */ })
}

function startHeartbeat() {
  if (role.value !== 'editor' || heartbeatTimer) return
  sendEditingStatus(true)
  // Backend expires sessions after 5 minutes of silence.
  heartbeatTimer = setInterval(() => sendEditingStatus(true), 120000)
}

function stopHeartbeat() {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer)
    heartbeatTimer = null
    sendEditingStatus(false)
  }
}

// ============================================================
// Editor lifecycle
// ============================================================

// Return target captured by the caller (e.g. the email view passes its
// own URL) so Back restores exactly where the user was. Without it,
// Back falls through to the file's Drive folder.
const backTarget = computed(() => {
  const b = route.query.back
  return typeof b === 'string' && b.startsWith('/') ? b : null
})

function goToDrive() {
  const folderId = fileMeta.value?.folder_id ?? route.query.folder ?? null
  router.push({ name: 'drive', query: folderId ? { folder: String(folderId) } : {} })
}

function goBack() {
  if (backTarget.value) {
    router.push(backTarget.value)
    return
  }
  goToDrive()
}

async function openEditor() {
  loading.value = true
  error.value = null
  try {
    const res = await officeApi.getConfig(fileId, {
      lang: locale.value === 'hu' ? 'hu' : 'en',
      name: authStore.user?.name || authStore.user?.email || undefined,
    })
    const data = res.data?.data
    if (!data?.editor_config || !data?.server_url) {
      throw new Error(res.data?.message || 'Invalid editor configuration')
    }

    fileMeta.value = data.file
    role.value = data.role
    isOwner.value = !!data.is_owner

    if (OFFICE_PRESENCE_ENABLED) bridge.start(data.server_url)

    const DocsAPI = await loadDocsApi(data.server_url)
    docEditor = new DocsAPI.DocEditor(EDITOR_CONTAINER_ID, {
      ...data.editor_config,
      width: '100%',
      height: '100%',
      events: {
        onError: (e) => {
          console.error('[Office] Editor error', e?.data)
        },
      },
    })
    loading.value = false

    startHeartbeat()
    if (OFFICE_PRESENCE_ENABLED) connectPresence()
  } catch (e) {
    console.error('[Office] Failed to open editor', e)
    error.value = e?.response?.data?.message || e.message || 'Failed to open the document'
    loading.value = false
  }
}

async function connectPresence() {
  try {
    await presence.connect(fileId, {
      name: authStore.user?.name || authStore.user?.email || undefined,
      email: authStore.user?.email,
    })
  } catch (e) {
    // Presence is an enhancement - the editor works without it.
    console.warn('[Office] Presence unavailable:', e?.message || e)
  }
}

onMounted(openEditor)

onBeforeUnmount(() => {
  stopHeartbeat()
  if (OFFICE_PRESENCE_ENABLED) presence.disconnect()
  if (docEditor) {
    try { docEditor.destroyEditor() } catch (e) { /* editor already gone */ }
    docEditor = null
  }
})
</script>

<template>
  <div class="fixed inset-0 z-40 flex flex-col bg-white dark:bg-surface-900">
    <!-- Editor / states (fills the whole screen; the FlowOne controls are a
         floating layer over OnlyOffice's own top bar - no white header bar) -->
    <div class="flex-1 relative min-h-0">
      <!-- Floating controls layered over OnlyOffice's blue top bar.
           The container ignores pointer events so OnlyOffice stays clickable;
           only the two button clusters capture clicks. -->
      <div class="office-overlay absolute inset-x-0 top-0 z-10 flex items-center justify-between pointer-events-none">
        <!-- LEFT: back + drive-folder, flat icons matching OnlyOffice's toolbar -->
        <div class="office-overlay__left flex items-center gap-1 pointer-events-auto flex-shrink-0">
          <button
            @click="goBack"
            :class="[
              'ov-icon-btn',
              editorReady ? 'ov-icon-btn--cover' : 'ov-icon-btn--surface'
            ]"
            :style="editorReady ? { backgroundColor: appBarColor } : null"
            :title="backTarget ? t('officeEditor.backToEmail') : t('officeEditor.backToDrive')"
          >
            <span class="material-symbols-rounded">arrow_back</span>
          </button>
          <!-- When opened from an email, Back returns there; this extra
               button still offers the file's Drive folder. -->
          <button
            v-if="backTarget"
            @click="goToDrive"
            :class="[
              'ov-icon-btn',
              editorReady ? 'ov-icon-btn--cover' : 'ov-icon-btn--surface'
            ]"
            :style="editorReady ? { backgroundColor: appBarColor } : null"
            :title="t('officeEditor.backToDrive')"
          >
            <span class="material-symbols-rounded">folder_open</span>
          </button>
        </div>

        <!-- CENTER: invisible click target over OnlyOffice's own title so the
             user can rename in place without duplicating the title text. -->
        <div class="office-overlay__center absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 pointer-events-auto flex items-center">
          <div
            v-if="renaming"
            class="flex items-center h-6 px-1.5 rounded bg-white ring-1 ring-primary-500/60"
          >
            <input
              ref="renameInput"
              v-model="renameValue"
              type="text"
              class="text-sm text-surface-900 bg-transparent border-0 min-w-0 w-44 outline-none"
              @keydown.enter.prevent="commitRename"
              @keydown.esc.prevent="cancelRename"
              @blur="commitRename"
            />
            <span class="text-sm text-surface-400 ml-0.5 flex-shrink-0">{{ fileExt }}</span>
          </div>
          <button
            v-else-if="canRename"
            type="button"
            @click="startRename"
            :title="t('officeEditor.renameFile')"
            class="h-6 px-2 text-sm font-medium text-transparent max-w-[360px] truncate cursor-pointer"
          >
            {{ fileMeta?.name }}
          </button>
        </div>

        <!-- RIGHT: status + participants + share (flat, matches the doc toolbar) -->
        <div class="office-overlay__right flex items-center gap-1.5 pointer-events-auto flex-shrink-0">
          <span
            v-if="role === 'viewer' && editorReady"
            class="flex items-center gap-1 text-[11px] text-white/80 flex-shrink-0"
          >
            <span class="material-symbols-rounded text-[14px]">visibility</span>
            {{ t('officeEditor.viewOnly') }}
          </span>
          <span
            v-if="OFFICE_PRESENCE_ENABLED && followedUser"
            class="flex items-center gap-1 text-[11px] text-white/80 flex-shrink-0"
          >
            <span class="material-symbols-rounded text-[14px]">visibility</span>
            {{ t('officeEditor.followingUser', { name: followedUser.name }) }}
          </span>

          <!-- Live participants (click an avatar to follow that user) -->
          <div v-if="OFFICE_PRESENCE_ENABLED && participants.length" class="flex items-center">
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
              <span
                v-if="followedEmail === user.email"
                class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-primary-500 border border-white flex items-center justify-center"
              >
                <span class="material-symbols-rounded text-[8px] text-white leading-none">visibility</span>
              </span>
            </button>
          </div>

          <button
            v-if="role === 'editor' && editorReady"
            @click="openShare"
            class="ov-icon-btn ov-icon-btn--bar"
            :title="t('officeEditor.share')"
          >
            <span class="material-symbols-rounded">share</span>
          </button>
        </div>
      </div>

      <div v-if="loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3">
        <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        <p class="text-sm text-surface-500">{{ t('officeEditor.openingDocument') }}</p>
      </div>
      <div v-else-if="error" class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center">
        <span class="material-symbols-rounded text-5xl text-red-400">error</span>
        <p class="text-base font-medium text-surface-800 dark:text-surface-100">{{ error }}</p>
        <button @click="goBack" class="btn-primary mt-2">{{ backTarget ? t('officeEditor.backToEmail') : t('officeEditor.backToDrive') }}</button>
      </div>
      <div :id="EDITOR_CONTAINER_ID" class="w-full h-full"></div>
    </div>
  </div>
</template>

<style scoped>
/* Floating overlay aligned to OnlyOffice's title row. The insets clear
   OnlyOffice's own chrome (quick-access + "..." on the left, mode + avatar +
   search on the right); tweak these three values to nudge the controls. */
.office-overlay {
  height: 32px;
  --ov-left-inset: 9px;
  --ov-right-inset: 43px;
  /* Size of the Back/folder mask that hides OnlyOffice's Save (etc.) icon. */
  --ov-cover-w: 32px;
  --ov-cover-h: 26px;
}

.office-overlay__left {
  margin-left: var(--ov-left-inset);
  /* Lower margin-bottom => sits lower (content centre = 16 - margin/2). */
  margin-bottom: 5px;
}

.office-overlay__right {
  margin-right: var(--ov-right-inset);
  margin-bottom: 6px;
}

/* Flat icon buttons that read like OnlyOffice's own toolbar icons:
   no background, light icon, slightly smaller than the default. */
.ov-icon-btn {
  width: 20px;
  height: 20px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  background: transparent;
  flex-shrink: 0;
  transition: color 0.12s ease;
}

.ov-icon-btn .material-symbols-rounded {
  font-size: 16px;
}

/* Over OnlyOffice's coloured bar: muted-white icon, full white on hover. */
.ov-icon-btn--bar {
  color: rgba(255, 255, 255, 0.82);
}
.ov-icon-btn--bar:hover {
  color: #fff;
}

/* Back/folder doubling as a mask over OnlyOffice's Save icon: a solid block
   tinted (inline) to the editor's header colour so the icon beneath is hidden.
   Slightly larger than a normal icon button so it fully covers the target. */
.ov-icon-btn--cover {
  width: var(--ov-cover-w);
  height: var(--ov-cover-h);
  border-radius: 0;
  color: rgba(255, 255, 255, 0.92);
}
.ov-icon-btn--cover:hover {
  color: #fff;
}

/* Before the editor mounts (plain app surface behind): dark icon. */
.ov-icon-btn--surface {
  color: rgb(71 85 105);
}
.ov-icon-btn--surface:hover {
  color: rgb(15 23 42);
}
:global(.dark) .ov-icon-btn--surface {
  color: rgb(203 213 225);
}
:global(.dark) .ov-icon-btn--surface:hover {
  color: #fff;
}
</style>
