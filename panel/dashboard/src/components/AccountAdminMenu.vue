<script setup>
// AccountAdminMenu
// ---------------------------------------------------------------
// Per-mailbox "Account Admin" actions grouped under one kebab menu
// so the Emails tab and the central Overview mail list share the
// exact same controls (and stay tidy as actions grow).
//
//   Owned here (modal + API via useMailAccountAdmin):
//     • Set Quota   — DRIVE + EMAIL (Dovecot) storage limits
//     • Reset 2FA   — wipe secret/backup codes, revoke devices+sessions
//   Delegated to the parent (it already owns these flows):
//     • Reset Password / Suspend / Resume / Delete  → emitted events
//
// On a successful quota/2FA change we emit `changed` so the parent
// can refresh its list. The menu is a right-aligned dropdown on
// desktop and a bottom sheet on small screens.

import { computed, onUnmounted, ref, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import Toggle from '@/components/Toggle.vue'
import { useToastStore } from '@/stores/toast'
import {
  useMailAccountAdmin,
  formatMailboxQuota,
  formatDriveQuota,
} from '@/composables/useMailAccountAdmin'

const props = defineProps({
  account: { type: Object, required: true },
  // Disable suspend/resume while a parent-driven request is in flight.
  suspendPending: { type: Boolean, default: false },
  showDelete: { type: Boolean, default: true },
})

const emit = defineEmits(['reset-password', 'suspend', 'resume', 'delete', 'changed'])

const toast = useToastStore()
const { savingQuota, resetting2fa, setQuotas, reset2fa } = useMailAccountAdmin()

const GB = 1024 * 1024 * 1024

const isSuspended = computed(() => !!(props.account?.suspended ?? props.account?.login_suspended))
const email = computed(() => props.account?.email || '')

// ─── Menu open/close (dropdown desktop, bottom sheet mobile) ───
const menuOpen = ref(false)
const isMobile = ref(false)
let mq = null

if (typeof window !== 'undefined' && window.matchMedia) {
  mq = window.matchMedia('(max-width: 640px)')
  isMobile.value = mq.matches
  const onChange = (e) => { isMobile.value = e.matches }
  mq.addEventListener ? mq.addEventListener('change', onChange) : mq.addListener(onChange)
  onUnmounted(() => {
    mq.removeEventListener ? mq.removeEventListener('change', onChange) : mq.removeListener(onChange)
  })
}

const onEsc = (e) => { if (e.key === 'Escape') menuOpen.value = false }
watch(menuOpen, (open) => {
  if (open) document.addEventListener('keydown', onEsc)
  else document.removeEventListener('keydown', onEsc)
})
onUnmounted(() => document.removeEventListener('keydown', onEsc))

const closeMenu = () => { menuOpen.value = false }

// ─── Quota modal ───
// Both storage fields are entered in GB so the two inputs stay consistent
// (admins kept typing "7" meaning 7 GB into the old MB field). The mailbox
// value is converted to MB on save since Dovecot/quota_mb is stored in MB.
const quotaModal = ref({ open: false, mailboxUnlimited: false, mailboxGb: 5, driveUnlimited: true, driveGb: 5 })

const openQuotaModal = () => {
  closeMenu()
  const mb = Number(props.account?.mailbox_quota_mb ?? 0)
  const driveBytes = Number(props.account?.drive_quota ?? -1)
  quotaModal.value = {
    open: true,
    mailboxUnlimited: !(mb > 0),
    mailboxGb: mb > 0 ? Math.round((mb / 1024) * 100) / 100 : 5,
    driveUnlimited: !(driveBytes >= 0),
    driveGb: driveBytes >= 0 ? Math.round((driveBytes / GB) * 100) / 100 : 5,
  }
}

// Exact MB the email field resolves to (shown under the input so admins see
// what Dovecot will actually enforce).
const mailboxMbPreview = computed(() => {
  const gb = Number(quotaModal.value.mailboxGb)
  if (!Number.isFinite(gb) || gb <= 0) return null
  return Math.round(gb * 1024)
})

const saveQuota = async () => {
  const m = quotaModal.value

  let quotaMb = 0
  if (!m.mailboxUnlimited) {
    const gb = Number(m.mailboxGb)
    if (!Number.isFinite(gb) || gb < 0.1 || gb > 1024) {
      toast.error('Email storage must be between 0.1 GB and 1024 GB (1 TB), or set Unlimited')
      return
    }
    quotaMb = Math.round(gb * 1024)
    // Keep within Dovecot bounds (0.1 GB ≈ 102 MB is already ≥ 100).
    quotaMb = Math.min(1048576, Math.max(100, quotaMb))
  }

  let driveQuotaBytes = -1
  if (!m.driveUnlimited) {
    const gb = Number(m.driveGb)
    if (!Number.isFinite(gb) || gb < 0.1) {
      toast.error('Drive quota must be at least 0.1 GB (100 MB), or set Unlimited')
      return
    }
    driveQuotaBytes = Math.round(gb * GB)
  }

  const ok = await setQuotas(email.value, { quotaMb, driveQuotaBytes })
  if (ok) {
    quotaModal.value.open = false
    emit('changed')
  }
}

// ─── Reset 2FA modal ───
const reset2faModal = ref({ open: false })

const openReset2fa = () => {
  closeMenu()
  reset2faModal.value.open = true
}

const confirmReset2fa = async () => {
  const ok = await reset2fa(email.value)
  if (ok) {
    reset2faModal.value.open = false
    emit('changed')
  }
}

// ─── Delegated actions ───
const onResetPassword = () => { closeMenu(); emit('reset-password', props.account) }
const onSuspend = () => { closeMenu(); emit('suspend', props.account) }
const onResume = () => { closeMenu(); emit('resume', props.account) }
const onDelete = () => { closeMenu(); emit('delete', props.account) }
</script>

<template>
  <div class="relative inline-block text-left">
    <button
      class="btn-ghost btn-sm"
      :title="`Account admin for ${email}`"
      aria-haspopup="true"
      :aria-expanded="menuOpen"
      @click.stop="menuOpen = !menuOpen"
    >
      <span class="material-symbols-rounded">more_vert</span>
    </button>

    <!-- Backdrop catches outside clicks (and dims on mobile sheet) -->
    <div
      v-if="menuOpen"
      class="fixed inset-0 z-40"
      :class="isMobile ? 'bg-black/40' : ''"
      @click="closeMenu"
    />

    <!-- Desktop dropdown -->
    <div
      v-if="menuOpen && !isMobile"
      class="absolute right-0 z-50 mt-1 w-56 origin-top-right rounded-xl border border-surface-200
             dark:border-surface-700 bg-white dark:bg-surface-900 shadow-lg py-1"
      @click.stop
    >
      <p class="px-3 py-2 text-xs font-semibold text-surface-400 truncate">{{ email }}</p>
      <button class="menu-item" @click="onResetPassword">
        <span class="material-symbols-rounded">key</span> Reset Password
      </button>
      <button class="menu-item" @click="openQuotaModal">
        <span class="material-symbols-rounded">database</span> Set Quota
      </button>
      <button class="menu-item" @click="openReset2fa">
        <span class="material-symbols-rounded">shield_lock</span> Reset 2FA
      </button>
      <button
        v-if="isSuspended"
        class="menu-item text-green-600 dark:text-green-400"
        :disabled="suspendPending"
        @click="onResume"
      >
        <span class="material-symbols-rounded">play_circle</span> Resume Login
      </button>
      <button
        v-else
        class="menu-item text-amber-600 dark:text-amber-400"
        @click="onSuspend"
      >
        <span class="material-symbols-rounded">block</span> Suspend Login
      </button>
      <template v-if="showDelete">
        <div class="my-1 border-t border-surface-100 dark:border-surface-800" />
        <button class="menu-item text-red-600 dark:text-red-400" @click="onDelete">
          <span class="material-symbols-rounded">delete</span> Delete Account
        </button>
      </template>
    </div>

    <!-- Mobile bottom sheet -->
    <div
      v-if="menuOpen && isMobile"
      class="fixed inset-x-0 bottom-0 z-50 rounded-t-2xl border-t border-surface-200
             dark:border-surface-700 bg-white dark:bg-surface-900 p-2 pb-6 shadow-2xl"
      @click.stop
    >
      <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-surface-300 dark:bg-surface-600" />
      <p class="px-3 py-2 text-sm font-semibold truncate">{{ email }}</p>
      <button class="menu-item-lg" @click="onResetPassword">
        <span class="material-symbols-rounded">key</span> Reset Password
      </button>
      <button class="menu-item-lg" @click="openQuotaModal">
        <span class="material-symbols-rounded">database</span> Set Quota
      </button>
      <button class="menu-item-lg" @click="openReset2fa">
        <span class="material-symbols-rounded">shield_lock</span> Reset 2FA
      </button>
      <button
        v-if="isSuspended"
        class="menu-item-lg text-green-600 dark:text-green-400"
        :disabled="suspendPending"
        @click="onResume"
      >
        <span class="material-symbols-rounded">play_circle</span> Resume Login
      </button>
      <button v-else class="menu-item-lg text-amber-600 dark:text-amber-400" @click="onSuspend">
        <span class="material-symbols-rounded">block</span> Suspend Login
      </button>
      <button v-if="showDelete" class="menu-item-lg text-red-600 dark:text-red-400" @click="onDelete">
        <span class="material-symbols-rounded">delete</span> Delete Account
      </button>
    </div>

    <!-- ─── Set Quota modal ─── -->
    <Modal
      :show="quotaModal.open"
      :title="`Set quota for ${email}`"
      size="md"
      @close="quotaModal.open = false"
    >
      <div class="space-y-5">
        <!-- Email storage quota -->
        <div class="rounded-xl border border-surface-200 dark:border-surface-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">mail</span>
              <span class="font-medium">Email storage</span>
            </div>
            <label class="flex items-center gap-2 text-sm">
              Unlimited
              <Toggle v-model="quotaModal.mailboxUnlimited" />
            </label>
          </div>
          <p class="text-xs text-surface-500 mb-2">
            Currently using {{ account.size_human ?? '—' }} ·
            current limit {{ formatMailboxQuota(account.mailbox_quota_mb) }}
          </p>
          <div v-if="!quotaModal.mailboxUnlimited">
            <div class="flex items-center gap-2">
              <input
                v-model.number="quotaModal.mailboxGb"
                type="number"
                min="0.1"
                max="1024"
                step="0.1"
                class="input w-40"
              />
              <span class="text-sm text-surface-500">GB</span>
              <span class="text-xs text-surface-400">(0.1 GB – 1 TB)</span>
            </div>
            <p v-if="mailboxMbPreview" class="mt-1 text-xs text-surface-400">
              ≈ {{ mailboxMbPreview.toLocaleString() }} MB enforced by Dovecot
            </p>
          </div>
          <p v-else class="text-sm text-surface-500">No storage limit (enforced by Dovecot).</p>
        </div>

        <!-- Drive quota -->
        <div class="rounded-xl border border-surface-200 dark:border-surface-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">cloud</span>
              <span class="font-medium">Drive (files)</span>
            </div>
            <label class="flex items-center gap-2 text-sm">
              Unlimited
              <Toggle v-model="quotaModal.driveUnlimited" />
            </label>
          </div>
          <p class="text-xs text-surface-500 mb-2">
            Currently using {{ account.drive_used_human ?? '0 B' }} ·
            current limit {{ formatDriveQuota(account.drive_quota) }}
          </p>
          <div v-if="!quotaModal.driveUnlimited" class="flex items-center gap-2">
            <input
              v-model.number="quotaModal.driveGb"
              type="number"
              min="0.1"
              step="0.1"
              class="input w-40"
            />
            <span class="text-sm text-surface-500">GB</span>
            <span class="text-xs text-surface-400">(min 0.1 GB)</span>
          </div>
          <p v-else class="text-sm text-surface-500">No storage limit.</p>
        </div>
      </div>
      <template #footer>
        <button class="btn-secondary" :disabled="savingQuota" @click="quotaModal.open = false">
          Cancel
        </button>
        <button class="btn-primary" :disabled="savingQuota" @click="saveQuota">
          <span v-if="savingQuota" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">save</span>
          Save quota
        </button>
      </template>
    </Modal>

    <!-- ─── Reset 2FA confirm modal ─── -->
    <Modal
      :show="reset2faModal.open"
      :title="`Reset 2FA for ${email}`"
      size="md"
      @close="reset2faModal.open = false"
    >
      <div
        class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-500/10
               dark:border-amber-500/30 px-3 py-3 text-sm text-amber-800 dark:text-amber-200"
      >
        <p class="flex items-start gap-2 font-medium">
          <span class="material-symbols-rounded text-base shrink-0">shield_lock</span>
          Reset two-factor authentication for this mailbox?
        </p>
        <ul class="mt-2 list-disc pl-8 space-y-1">
          <li>Disables 2FA (login with password only)</li>
          <li>Removes the saved authenticator secret and backup codes</li>
          <li>Revokes all trusted devices</li>
          <li>Signs out active webmail sessions</li>
        </ul>
        <p class="mt-2">The user can set up 2FA again from webmail after logging in.</p>
      </div>
      <template #footer>
        <button class="btn-secondary" :disabled="resetting2fa" @click="reset2faModal.open = false">
          Cancel
        </button>
        <button
          class="btn-primary bg-amber-600 hover:bg-amber-700 border-amber-600"
          :disabled="resetting2fa"
          @click="confirmReset2fa"
        >
          <span v-if="resetting2fa" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">lock_reset</span>
          Reset 2FA
        </button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.menu-item {
  @apply w-full flex items-center gap-2 px-3 py-2 text-sm text-left
         hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors
         disabled:opacity-50 disabled:cursor-not-allowed;
}
.menu-item .material-symbols-rounded {
  @apply text-base;
}
.menu-item-lg {
  @apply w-full flex items-center gap-3 px-4 py-3 text-base text-left rounded-lg
         hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors
         disabled:opacity-50 disabled:cursor-not-allowed;
}
</style>
