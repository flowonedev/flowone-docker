<script setup>
// EmailsTab
// ---------------------------------------------------------------
// Email accounts + forwards for the V2 site management view.
// Replaces the "emails" section of SiteDetailView.vue. Uses the
// already V2-compatible /api/mail/accounts and /api/mail/forwards
// routes.
//
// IMAP migration lives in a single central place now — the
// Overview → Mail tab → "Migrate" flow — so it is intentionally
// not duplicated here.
//
// Layout matches the legacy SiteDetailView "Emails" tab the
// operators were used to:
//   - Header row: title + (Open Webmail / Refresh / New Email)
//     buttons.
//   - Mail Server Settings card (IMAP/POP3/SMTP/SMTP Alt +
//     Email Limits).
//   - One big sortable table with Email | Size | Forwards To |
//     Actions, where the Actions column collapses Webmail, Manage
//     Redirects, Reset Password and Delete into per-row icons.
//   - Per-source "Manage Email Redirects" modal that lists current
//     destinations and lets you add / remove / toggle keep-copy
//     in one place.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import Toggle from '@/components/Toggle.vue'
import AccountAdminMenu from '@/components/AccountAdminMenu.vue'

const props = defineProps({ domain: { type: String, required: true } })
const toast = useToastStore()

const accounts = ref([])
const forwards = ref([])
const loading = ref(false)

const newAccount = ref({ user: '', password: '', quota: 5120 })
const showAddAccount = ref(false)
const pwModal = ref({ open: false, email: '', password: '' })
const deleteAccountModal = ref({ open: false, account: null, busy: false })
const suspendModal = ref({ open: false, account: null, reason: '', busy: false })
const suspendPending = ref(null)

// Manage Email Redirects modal — single place to add / remove
// forwards for one source mailbox and toggle the "keep a copy"
// self-forward (legacy parity).
const redirectModal = ref({
  show: false,
  source: '',
  destinations: [],
  newDestination: '',
  keepCopy: false,
  loading: false,
})

// Sort state for the email table. Legacy default was "email asc".
const emailSort = ref({ key: 'email', order: 'asc' })

// Webmail entry point, derived from THIS box's own domain (email.<base>) so
// client servers never link to the operator's webmail. Per-account URL
// appends the email so the webmail login form pre-fills.
const webmailBase = window.location.hostname.replace(/^(panel|vps|www)\./, '')
const webmailUrl = `https://email.${webmailBase}/f`
const getWebmailUrl = (email) =>
  `${webmailUrl}?email=${encodeURIComponent(email)}`

// Defensive: API envelopes are wrappers like { accounts: [...] } /
// { forwards: [...] } / { counts, running } — always coerce to a
// real array before filter/map runs.
const toArray = (v) => Array.isArray(v) ? v : []

const myAccounts = computed(() => toArray(accounts.value).filter((a) =>
  String(a.email || '').endsWith('@' + props.domain)))
const myForwards = computed(() => toArray(forwards.value).filter((f) =>
  String(f.source || '').endsWith('@' + props.domain)))

// Source-email → [destination] map, used by the table and the
// Manage Redirects modal.
const forwardsBySource = computed(() => {
  const map = {}
  for (const fwd of myForwards.value) {
    if (!fwd?.source) continue
    if (!map[fwd.source]) map[fwd.source] = []
    map[fwd.source].push(fwd.destination)
  }
  return map
})

// Sortable email table. Numeric sort on "size" (raw bytes), string
// sort otherwise. Matches the legacy sortedEmails.
const sortedEmails = computed(() => {
  const sorted = [...myAccounts.value]
  sorted.sort((a, b) => {
    let aVal = a[emailSort.value.key]
    let bVal = b[emailSort.value.key]
    if (emailSort.value.key === 'size') {
      aVal = Number(a.size ?? 0)
      bVal = Number(b.size ?? 0)
      return emailSort.value.order === 'asc' ? aVal - bVal : bVal - aVal
    }
    aVal = String(aVal ?? '')
    bVal = String(bVal ?? '')
    return emailSort.value.order === 'asc'
      ? aVal.localeCompare(bVal)
      : bVal.localeCompare(aVal)
  })
  return sorted
})

const toggleSort = (key) => {
  if (emailSort.value.key === key) {
    emailSort.value.order = emailSort.value.order === 'asc' ? 'desc' : 'asc'
  } else {
    emailSort.value = { key, order: 'asc' }
  }
}

// Robust unwrapper for API list payloads: accepts a flat array OR
// a wrapped object like { accounts: [...] } / { forwards: [...] }
// / { running: [...] }.
const unwrapList = (payload, ...keys) => {
  if (Array.isArray(payload)) return payload
  if (payload && typeof payload === 'object') {
    for (const key of keys) {
      if (Array.isArray(payload[key])) return payload[key]
    }
  }
  return []
}

const fetchAll = async () => {
  loading.value = true
  try {
    const [a, f] = await Promise.allSettled([
      api.get('/mail/accounts'),
      api.get('/mail/forwards'),
    ])
    accounts.value = a.status === 'fulfilled' && a.value.data?.success
      ? unwrapList(a.value.data.data, 'accounts', 'items')
      : []
    forwards.value = f.status === 'fulfilled' && f.value.data?.success
      ? unwrapList(f.value.data.data, 'forwards', 'items')
      : []
  } finally {
    loading.value = false
  }
}

const addAccount = async () => {
  if (!newAccount.value.user || !newAccount.value.password) {
    toast.error('User and password required')
    return
  }
  try {
    const r = await api.post('/mail/accounts', {
      email: `${newAccount.value.user}@${props.domain}`,
      password: newAccount.value.password,
      quota: Number(newAccount.value.quota) || 5120,
    })
    if (r.data?.success) {
      toast.success('Account created')
      showAddAccount.value = false
      newAccount.value = { user: '', password: '', quota: 5120 }
      await fetchAll()
    } else {
      toast.error(r.data?.error || 'Create failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Create failed')
  }
}

const askRemoveAccount = (a) => {
  deleteAccountModal.value = { open: true, account: a, busy: false }
}

const confirmRemoveAccount = async () => {
  const a = deleteAccountModal.value.account
  if (!a) return
  deleteAccountModal.value.busy = true
  try {
    const r = await api.delete(`/mail/accounts/${encodeURIComponent(a.email)}`)
    if (r.data?.success) {
      toast.success('Account deleted')
      await fetchAll()
    } else {
      toast.error(r.data?.error || 'Delete failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Delete failed')
  } finally {
    deleteAccountModal.value = { open: false, account: null, busy: false }
  }
}

// Suspend blocks login (Outlook/webmail/IMAP/SMTP) but keeps receiving mail.
const askSuspendAccount = (account) => {
  suspendModal.value = { open: true, account, reason: '', busy: false }
}

const confirmSuspendAccount = async () => {
  const account = suspendModal.value.account
  if (!account?.email) return
  suspendModal.value.busy = true
  try {
    const r = await api.post(
      `/mail/accounts/${encodeURIComponent(account.email)}/suspend`,
      { reason: suspendModal.value.reason || undefined },
    )
    if (r.data?.success) {
      account.suspended = true
      toast.success('Login suspended (mail still being received)')
      suspendModal.value = { open: false, account: null, reason: '', busy: false }
    } else {
      toast.error(r.data?.error || 'Suspend failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Suspend failed')
  } finally {
    if (suspendModal.value.account) suspendModal.value.busy = false
  }
}

const resumeAccount = async (account) => {
  if (!account?.email || suspendPending.value) return
  suspendPending.value = account.email
  try {
    const r = await api.post(`/mail/accounts/${encodeURIComponent(account.email)}/resume`)
    if (r.data?.success) {
      account.suspended = false
      toast.success('Login resumed')
    } else {
      toast.error(r.data?.error || 'Resume failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Resume failed')
  } finally {
    suspendPending.value = null
  }
}

const resetPassword = async () => {
  if (!pwModal.value.password) {
    toast.error('Password required')
    return
  }
  try {
    const r = await api.post(
      `/mail/accounts/${encodeURIComponent(pwModal.value.email)}/password`,
      { password: pwModal.value.password },
    )
    if (r.data?.success) {
      toast.success('Password updated')
      pwModal.value.open = false
    } else {
      toast.error(r.data?.error || 'Update failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Update failed')
  }
}

// ─── Manage Redirects modal handlers ───
// keepCopy is implemented as a self-forward (source === destination)
// — legacy parity.
const openRedirectModal = (email) => {
  const existing = forwardsBySource.value[email] || []
  redirectModal.value = {
    show: true,
    source: email,
    destinations: existing.filter((d) => d !== email),
    newDestination: '',
    keepCopy: existing.includes(email),
    loading: false,
  }
}

const closeRedirectModal = () => {
  redirectModal.value = {
    show: false,
    source: '',
    destinations: [],
    newDestination: '',
    keepCopy: false,
    loading: false,
  }
}

const addRedirect = async () => {
  const dest = redirectModal.value.newDestination.trim().toLowerCase()
  if (!dest) {
    toast.error('Enter a destination email')
    return
  }
  if (!dest.includes('@') || !dest.includes('.')) {
    toast.error('Invalid email format')
    return
  }
  if (
    redirectModal.value.destinations.includes(dest) ||
    dest === redirectModal.value.source
  ) {
    toast.error('This forward already exists')
    return
  }
  redirectModal.value.loading = true
  try {
    const r = await api.post('/mail/forwards', {
      source: redirectModal.value.source,
      destination: dest,
    })
    if (!r.data?.success) {
      throw new Error(r.data?.error || 'Failed to add forward')
    }
    redirectModal.value.destinations.push(dest)
    redirectModal.value.newDestination = ''
    await fetchAll()
    toast.success('Forward added')
  } catch (e) {
    toast.error(e?.response?.data?.error || e.message || 'Failed to add forward')
  } finally {
    redirectModal.value.loading = false
  }
}

const removeRedirect = async (destination) => {
  redirectModal.value.loading = true
  try {
    const r = await api.delete(
      `/mail/forwards/${encodeURIComponent(redirectModal.value.source)}`,
      { data: { destination } },
    )
    if (!r.data?.success) {
      throw new Error(r.data?.error || 'Failed to remove forward')
    }
    redirectModal.value.destinations = redirectModal.value.destinations.filter(
      (d) => d !== destination,
    )
    await fetchAll()
    toast.success('Forward removed')
  } catch (e) {
    toast.error(e?.response?.data?.error || e.message || 'Failed to remove')
  } finally {
    redirectModal.value.loading = false
  }
}

const toggleKeepCopy = async () => {
  const source = redirectModal.value.source
  const shouldKeep = !redirectModal.value.keepCopy
  redirectModal.value.loading = true
  try {
    if (shouldKeep) {
      const r = await api.post('/mail/forwards', {
        source,
        destination: source,
      })
      if (!r.data?.success) {
        throw new Error(r.data?.error || 'Failed to enable keep copy')
      }
      toast.success('Local copy enabled')
    } else {
      const r = await api.delete(
        `/mail/forwards/${encodeURIComponent(source)}`,
        { data: { destination: source } },
      )
      if (!r.data?.success) {
        throw new Error(r.data?.error || 'Failed to disable keep copy')
      }
      toast.success('Local copy disabled')
    }
    redirectModal.value.keepCopy = shouldKeep
    await fetchAll()
  } catch (e) {
    toast.error(e?.response?.data?.error || e.message || 'Failed to update')
  } finally {
    redirectModal.value.loading = false
  }
}

onMounted(fetchAll)
</script>

<template>
  <div class="space-y-6" data-emails-tab="v2-restored">
    <!-- ─── Header row (matches legacy SiteDetailView) ─── -->
    <div class="flex justify-between items-center flex-wrap gap-2">
      <h3 class="font-semibold">Email Accounts</h3>
      <div class="flex gap-2 flex-wrap">
        <a
          :href="webmailUrl"
          target="_blank"
          rel="noopener"
          class="btn-secondary btn-sm"
        >
          <span class="material-symbols-rounded">mail</span>
          Open Webmail
        </a>
        <button
          class="btn-secondary btn-sm"
          :disabled="loading"
          title="Refresh"
          @click="fetchAll"
        >
          <span
            class="material-symbols-rounded"
            :class="{ 'animate-spin': loading }"
          >refresh</span>
        </button>
        <button class="btn-primary btn-sm" @click="showAddAccount = true">
          <span class="material-symbols-rounded">add</span>
          New Email
        </button>
      </div>
    </div>

    <!-- ─── Mail Server Settings ─── -->
    <div class="card p-4 bg-surface-50 dark:bg-surface-800/50">
      <h4 class="font-medium text-sm mb-3 flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500 text-base">dns</span>
        Mail Server Settings
      </h4>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
        <div>
          <span class="text-surface-500">IMAP:</span>
          <span class="font-mono ml-2">mail.{{ domain }}:993</span>
        </div>
        <div>
          <span class="text-surface-500">POP3:</span>
          <span class="font-mono ml-2">mail.{{ domain }}:995</span>
        </div>
        <div>
          <span class="text-surface-500">SMTP:</span>
          <span class="font-mono ml-2">mail.{{ domain }}:465</span>
        </div>
        <div>
          <span class="text-surface-500">SMTP Alt:</span>
          <span class="font-mono ml-2">mail.{{ domain }}:587</span>
        </div>
      </div>
      <div class="border-t border-surface-200 dark:border-surface-700 pt-3 mt-3">
        <h4 class="font-medium text-sm mb-3">Email Limits</h4>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <span class="text-surface-500">Per Hour:</span>
            <span class="font-medium ml-2">100 emails</span>
          </div>
          <div>
            <span class="text-surface-500">Per Day:</span>
            <span class="font-medium ml-2">1,000 emails</span>
          </div>
          <div>
            <span class="text-surface-500">Max size:</span>
            <span class="font-medium ml-2">25 MB</span>
          </div>
          <div>
            <span class="text-surface-500">Recipients:</span>
            <span class="font-medium ml-2">50 per msg</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── Big email table (matches legacy SiteDetailView) ─── -->
    <div class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th
              class="cursor-pointer select-none"
              @click="toggleSort('email')"
            >
              <div class="flex items-center gap-1">
                Email
                <span
                  v-if="emailSort.key === 'email'"
                  class="material-symbols-rounded text-sm"
                >
                  {{ emailSort.order === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
            </th>
            <th
              class="cursor-pointer select-none"
              @click="toggleSort('size')"
            >
              <div class="flex items-center gap-1">
                Size
                <span
                  v-if="emailSort.key === 'size'"
                  class="material-symbols-rounded text-sm"
                >
                  {{ emailSort.order === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
            </th>
            <th>Forwards To</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading && !sortedEmails.length">
            <td colspan="4" class="py-8 text-center text-surface-400">
              <span class="material-symbols-rounded animate-spin align-middle">progress_activity</span>
              Loading…
            </td>
          </tr>
          <tr v-else-if="!sortedEmails.length">
            <td colspan="4" class="py-8 text-center text-surface-400">
              No email accounts for this domain
            </td>
          </tr>
          <tr v-for="account in sortedEmails" :key="account.email">
            <td>
              <div class="flex items-center gap-3 min-w-0">
                <div
                  class="w-10 h-10 rounded-xl shrink-0
                         bg-blue-100 dark:bg-blue-500/20
                         flex items-center justify-center"
                >
                  <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">
                    person
                  </span>
                </div>
                <div class="min-w-0">
                  <span class="font-medium truncate block">{{ account.email }}</span>
                  <span
                    v-if="account.suspended"
                    class="mt-0.5 inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400"
                    title="Login is blocked (IMAP/POP3/SMTP/webmail). Incoming mail is still being received."
                  >
                    <span class="material-symbols-rounded text-xs">block</span>
                    Suspended
                  </span>
                </div>
              </div>
            </td>
            <td>
              <span class="font-medium">{{ account.size_human ?? '—' }}</span>
              <span class="block text-xs text-surface-400">
                of {{ account.mailbox_quota_human ?? 'Unlimited' }}
              </span>
            </td>
            <td>
              <div
                v-if="forwardsBySource[account.email]?.length"
                class="flex flex-wrap gap-1"
              >
                <span
                  v-for="dest in forwardsBySource[account.email]"
                  :key="dest"
                  class="text-xs px-2 py-1 rounded-full
                         bg-primary-100 dark:bg-primary-500/20
                         text-primary-600 dark:text-primary-400"
                >
                  {{ dest }}
                </span>
              </div>
              <span v-else class="text-surface-400">—</span>
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <a
                  :href="getWebmailUrl(account.email)"
                  target="_blank"
                  rel="noopener"
                  class="btn-ghost btn-sm"
                  :title="`Open Webmail as ${account.email}`"
                >
                  <span class="material-symbols-rounded">mail</span>
                </a>
                <button
                  class="btn-ghost btn-sm"
                  title="Manage Redirects"
                  @click="openRedirectModal(account.email)"
                >
                  <span class="material-symbols-rounded">forward_to_inbox</span>
                </button>
                <AccountAdminMenu
                  :account="account"
                  :suspend-pending="suspendPending === account.email"
                  @reset-password="(a) => pwModal = { open: true, email: a.email, password: '' }"
                  @suspend="askSuspendAccount"
                  @resume="resumeAccount"
                  @delete="askRemoveAccount"
                  @changed="fetchAll"
                />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ─── Add account modal ─── -->
    <Modal
      :show="showAddAccount"
      title="New Email Account"
      size="md"
      @close="showAddAccount = false"
    >
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-semibold mb-1">Username</label>
          <div class="flex">
            <input v-model="newAccount.user" type="text" class="input flex-1 rounded-r-none" />
            <span class="input rounded-l-none bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-500">
              @{{ domain }}
            </span>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">Password</label>
          <input v-model="newAccount.password" type="text" class="input w-full font-mono text-sm" />
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">Quota (MB)</label>
          <input v-model.number="newAccount.quota" type="number" class="input w-full" />
        </div>
      </div>
      <template #footer>
        <button class="btn-secondary" @click="showAddAccount = false">Cancel</button>
        <button class="btn-primary" @click="addAccount">
          <span class="material-symbols-rounded text-sm">add</span>
          Create
        </button>
      </template>
    </Modal>

    <!-- ─── Password modal ─── -->
    <Modal
      :show="pwModal.open"
      :title="`Reset password for ${pwModal.email}`"
      size="md"
      @close="pwModal.open = false"
    >
      <input
        v-model="pwModal.password"
        type="text"
        class="input w-full font-mono"
        placeholder="New password"
      />
      <template #footer>
        <button class="btn-secondary" @click="pwModal.open = false">Cancel</button>
        <button class="btn-primary" @click="resetPassword">
          <span class="material-symbols-rounded text-sm">save</span>
          Save
        </button>
      </template>
    </Modal>

    <!-- ─── Delete account confirm ─── -->
    <Modal
      :show="deleteAccountModal.open"
      :title="`Delete ${deleteAccountModal.account?.email ?? 'account'}`"
      size="md"
      @close="deleteAccountModal.open = false"
    >
      <div
        class="rounded-xl border border-red-300 bg-red-50 dark:bg-red-500/10
               dark:border-red-500/30 px-3 py-2 text-sm text-red-800 dark:text-red-200
               flex items-start gap-2"
      >
        <span class="material-symbols-rounded text-base shrink-0 mt-0.5">warning</span>
        <span>
          This removes the mailbox AND every message inside it from disk.
          <strong>Mail data cannot be recovered without a backup.</strong>
        </span>
      </div>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="deleteAccountModal.busy"
          @click="deleteAccountModal.open = false"
        >
          Cancel
        </button>
        <button
          class="btn-danger"
          :disabled="deleteAccountModal.busy"
          @click="confirmRemoveAccount"
        >
          <span v-if="deleteAccountModal.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">delete</span>
          Delete account
        </button>
      </template>
    </Modal>

    <!-- ─── Suspend login confirm ─── -->
    <Modal
      :show="suspendModal.open"
      :title="`Suspend ${suspendModal.account?.email ?? 'account'}`"
      size="md"
      @close="suspendModal.open = false"
    >
      <div class="space-y-4">
        <div
          class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-500/10
                 dark:border-amber-500/30 px-3 py-2 text-sm text-amber-800 dark:text-amber-200
                 flex items-start gap-2"
        >
          <span class="material-symbols-rounded text-base shrink-0 mt-0.5">block</span>
          <span>
            This blocks login everywhere (Outlook, phones, IMAP/POP3, SMTP and webmail) and
            disconnects any open sessions immediately.
            <strong>Incoming mail keeps being received</strong> and will be waiting when you resume.
          </span>
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">Reason (optional)</label>
          <input
            v-model="suspendModal.reason"
            type="text"
            class="input w-full"
            maxlength="255"
            placeholder="e.g. offboarding, suspected compromise"
          />
        </div>
      </div>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="suspendModal.busy"
          @click="suspendModal.open = false"
        >
          Cancel
        </button>
        <button
          class="btn-primary bg-amber-600 hover:bg-amber-700 border-amber-600"
          :disabled="suspendModal.busy"
          @click="confirmSuspendAccount"
        >
          <span v-if="suspendModal.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">block</span>
          Suspend login
        </button>
      </template>
    </Modal>

    <!-- ─── Manage Email Redirects modal (per-source) ─── -->
    <Modal
      :show="redirectModal.show"
      title="Manage Email Redirects"
      size="md"
      @close="closeRedirectModal"
    >
      <div class="space-y-5">
        <div class="p-4 bg-blue-50 dark:bg-blue-500/10 rounded-xl">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-blue-500">mail</span>
            <div class="min-w-0">
              <p class="text-sm text-blue-700 dark:text-blue-300">
                Managing redirects for:
              </p>
              <p class="font-semibold text-blue-800 dark:text-blue-200 truncate">
                {{ redirectModal.source }}
              </p>
            </div>
          </div>
        </div>

        <div class="space-y-2">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">
            Current Forwards
            <span class="text-surface-400 font-normal">({{ redirectModal.destinations.length }})</span>
          </label>

          <div v-if="redirectModal.destinations.length" class="space-y-2">
            <div
              v-for="dest in redirectModal.destinations"
              :key="dest"
              class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-xl group"
            >
              <div class="flex items-center gap-3 min-w-0">
                <span class="material-symbols-rounded text-primary-500">arrow_forward</span>
                <span class="font-medium truncate">{{ dest }}</span>
              </div>
              <button
                class="btn-ghost btn-sm text-red-500"
                :disabled="redirectModal.loading"
                title="Remove forward"
                @click="removeRedirect(dest)"
              >
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
          </div>

          <div
            v-else
            class="p-4 text-center text-surface-400 bg-surface-50 dark:bg-surface-800 rounded-xl"
          >
            <span class="material-symbols-rounded text-2xl mb-1 block">forward_to_inbox</span>
            <p class="text-sm">No forwards configured</p>
          </div>
        </div>

        <div class="space-y-2">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">
            Add New Forward
          </label>
          <div class="flex gap-2">
            <input
              v-model="redirectModal.newDestination"
              type="email"
              class="input flex-1"
              placeholder="recipient@example.com"
              @keyup.enter="addRedirect"
            />
            <button
              class="btn-primary"
              :disabled="redirectModal.loading || !redirectModal.newDestination"
              @click="addRedirect"
            >
              <span v-if="redirectModal.loading" class="spinner-sm" />
              <span v-else class="material-symbols-rounded">add</span>
            </button>
          </div>
        </div>

        <div class="flex items-center justify-between p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div class="pr-4 min-w-0">
            <p class="font-medium">Keep a copy locally</p>
            <p class="text-sm text-surface-500">Also deliver to the original mailbox</p>
          </div>
          <Toggle
            :model-value="redirectModal.keepCopy"
            :disabled="redirectModal.loading"
            @update:model-value="toggleKeepCopy"
          />
        </div>
      </div>
      <template #footer>
        <button type="button" class="btn-secondary" @click="closeRedirectModal">
          Close
        </button>
      </template>
    </Modal>

  </div>
</template>
