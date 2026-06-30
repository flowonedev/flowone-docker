<script setup>
// SftpUsersSection
// ---------------------------------------------------------------
// Manages ADDITIONAL restricted SFTP users for a site - the
// chroot-jailed accounts created on top of the primary site user.
// Each user is jailed (OpenSSH internal-sftp) into one folder under
// the site home via a bind mount + ACL. The operator chooses the SFTP
// login name (validated server-side; reserved/existing names are
// refused), plus an optional display label, target folder, auth method,
// password and/or keys.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'

const props = defineProps({
  domain: { type: String, required: true },
  port: { type: Number, default: 22 },
})

const toast = useToastStore()

const users = ref([])
const loading = ref(false)
const creating = ref(false)
const busyId = ref(null)

const homeRoot = computed(() => `/home/${props.domain}`)

const form = ref({
  username: '',
  display_name: '',
  target_path: '',
  auth_type: 'password',
  password: '',
  keys: '',
})
const showCreatePassword = ref(false)

// Mirrors the agent's validateUsernameFormat() so we can fail fast and
// give the same guidance before hitting the server.
const USERNAME_RE = /^[a-z][a-z0-9_-]{2,31}$/

// Stop browsers / password managers from autofilling the panel login
// (username + password) into this form. Autofill runs at page load, so a
// field that is readonly at that moment is skipped; we drop readonly on
// first focus so the user can still type normally.
const allowEdit = (e) => e.target.removeAttribute('readonly')

// Folder picker (read-only browse of the site home tree).
const picker = ref({ show: false, loading: false, path: '', parent: null, selectable: false, dirs: [] })

// Shown once after creation so the operator can copy the generated
// password (it is then only stored encrypted in the vault).
const credential = ref({ show: false, username: '', password: '' })
const confirmDelete = ref({ show: false, id: null, username: '', busy: false })
const keysModal = ref({ show: false, id: null, username: '', keys: [], newKey: '' })
const passwordModal = ref({ show: false, id: null, username: '', password: '', reveal: false })
const sessionsModal = ref({ show: false, id: null, username: '', loading: false, sessions: [], totals: null })

const base = computed(() => `/sites/${encodeURIComponent(props.domain)}/sftp-users`)

const formatBytes = (n) => {
  const b = Number(n) || 0
  if (b < 1024) return `${b} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let v = b / 1024
  let i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v < 10 ? 1 : 0)} ${units[i]}`
}

const formatDuration = (secs) => {
  const s = Number(secs) || 0
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  return `${h}h ${m}m`
}

const statusBadge = (s) => ({
  active: 'badge-success',
  disabled: 'badge-neutral',
  error: 'badge-danger',
  deleting: 'badge-warning',
}[s] || 'badge-neutral')

const fetchUsers = async () => {
  loading.value = true
  try {
    const r = await api.get(base.value)
    users.value = r.data?.success ? (r.data.data?.users || []) : []
  } catch {
    users.value = []
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.value = { username: '', display_name: '', target_path: '', auth_type: 'password', password: '', keys: '' }
}

const createUser = async () => {
  const username = form.value.username.trim().toLowerCase()
  if (!USERNAME_RE.test(username)) {
    toast.error('Username must be 3-32 chars: start with a lowercase letter, then letters, digits, _ or -')
    return
  }
  const target = form.value.target_path.trim()
  if (!target) {
    toast.error('Enter a target folder inside the site home')
    return
  }
  creating.value = true
  try {
    const keys = form.value.keys.split('\n').map((k) => k.trim()).filter(Boolean)
    const r = await api.post(base.value, {
      username,
      display_name: form.value.display_name.trim() || null,
      target_path: target,
      auth_type: form.value.auth_type,
      password: form.value.password || null,
      keys,
    })
    if (r.data?.success) {
      const data = r.data.data || {}
      toast.success('SFTP user created')
      if (data.password) {
        credential.value = { show: true, username: data.linux_username, password: data.password }
      }
      resetForm()
      await fetchUsers()
    } else {
      toast.error(r.data?.error || 'Create failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Create failed')
  } finally {
    creating.value = false
  }
}

const openPicker = () => {
  picker.value.show = true
  // Start from whatever is already typed, else default (server picks public_html).
  browseTo(form.value.target_path.trim() || '')
}

const browseTo = async (path) => {
  picker.value.loading = true
  try {
    const r = await api.get(base.value + '/browse', { params: { path: path || undefined } })
    if (r.data?.success) {
      const d = r.data.data || {}
      picker.value.path = d.path || ''
      picker.value.parent = d.parent || null
      picker.value.selectable = !!d.selectable
      picker.value.dirs = d.dirs || []
    } else {
      toast.error(r.data?.error || 'Could not open folder')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Could not open folder')
  } finally {
    picker.value.loading = false
  }
}

const chooseCurrentFolder = () => {
  form.value.target_path = picker.value.path
  picker.value.show = false
}

// Cryptographically strong password. Excludes ambiguous glyphs (0/O,
// 1/l/I) and shell-hostile chars (quotes, backslash, backtick) so it's
// safe to paste into any SFTP client. Guarantees >=1 of each class.
const generateSecurePassword = (length = 32) => {
  const sets = {
    lower: 'abcdefghijkmnpqrstuvwxyz',
    upper: 'ABCDEFGHJKLMNPQRSTUVWXYZ',
    digit: '23456789',
    sym: '!@#$%^&*()-_=+[]',
  }
  const all = sets.lower + sets.upper + sets.digit + sets.sym
  const pick = (chars) => {
    const max = 256 - (256 % chars.length)
    const buf = new Uint8Array(1)
    let b
    do { crypto.getRandomValues(buf); b = buf[0] } while (b >= max)
    return chars[b % chars.length]
  }
  const out = [pick(sets.lower), pick(sets.upper), pick(sets.digit), pick(sets.sym)]
  while (out.length < length) out.push(pick(all))
  for (let i = out.length - 1; i > 0; i--) {
    const max = 256 - (256 % (i + 1))
    const buf = new Uint8Array(1)
    let b
    do { crypto.getRandomValues(buf); b = buf[0] } while (b >= max)
    const j = b % (i + 1)
    ;[out[i], out[j]] = [out[j], out[i]]
  }
  return out.join('')
}

const generateCreatePassword = () => {
  form.value.password = generateSecurePassword(32)
  showCreatePassword.value = true
  toast.info('Strong 32-character password generated')
}

const generateModalPassword = () => {
  passwordModal.value.password = generateSecurePassword(32)
  passwordModal.value.reveal = true
  toast.info('Strong 32-character password generated')
}

const toggleStatus = async (u) => {
  const next = u.status === 'active' ? 'disabled' : 'active'
  busyId.value = u.id
  try {
    const r = await api.put(`${base.value}/${u.id}`, { status: next })
    if (r.data?.success) {
      toast.success(next === 'active' ? 'User enabled' : 'User disabled')
      await fetchUsers()
    } else {
      toast.error(r.data?.error || 'Update failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Update failed')
  } finally {
    busyId.value = null
  }
}

const repair = async (u) => {
  busyId.value = u.id
  try {
    const r = await api.post(`${base.value}/${u.id}/repair`, {})
    if (r.data?.success) {
      const fixes = r.data.data?.fixes || []
      toast.success(fixes.length ? `Repaired: ${fixes.join(', ')}` : 'Nothing to repair')
      await fetchUsers()
    } else {
      toast.error(r.data?.error || 'Repair failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Repair failed')
  } finally {
    busyId.value = null
  }
}

const askDelete = (u) => {
  confirmDelete.value = { show: true, id: u.id, username: u.linux_username, busy: false }
}

const doDelete = async () => {
  confirmDelete.value.busy = true
  try {
    const r = await api.delete(`${base.value}/${confirmDelete.value.id}`)
    if (r.data?.success) {
      toast.success('SFTP user deleted')
      await fetchUsers()
      confirmDelete.value = { show: false, id: null, username: '', busy: false }
    } else {
      toast.error(r.data?.error || 'Delete failed')
      confirmDelete.value.busy = false
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Delete failed')
    confirmDelete.value.busy = false
  }
}

const openKeys = (u) => {
  keysModal.value = { show: true, id: u.id, username: u.linux_username, keys: [...(u.keys || [])], newKey: '' }
}

const addKey = async () => {
  const key = keysModal.value.newKey.trim()
  if (!key) return
  try {
    const r = await api.post(`${base.value}/${keysModal.value.id}/keys`, { key })
    if (r.data?.success) {
      toast.success('Key added')
      keysModal.value.newKey = ''
      await fetchUsers()
      const u = users.value.find((x) => x.id === keysModal.value.id)
      keysModal.value.keys = [...(u?.keys || [])]
    } else {
      toast.error(r.data?.error || 'Add failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Add failed')
  }
}

const removeKey = async (index) => {
  try {
    const r = await api.delete(`${base.value}/${keysModal.value.id}/keys`, { data: { index } })
    if (r.data?.success) {
      toast.success('Key removed')
      await fetchUsers()
      const u = users.value.find((x) => x.id === keysModal.value.id)
      keysModal.value.keys = [...(u?.keys || [])]
    } else {
      toast.error(r.data?.error || 'Remove failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Remove failed')
  }
}

const openPassword = (u) => {
  passwordModal.value = { show: true, id: u.id, username: u.linux_username, password: '', reveal: false }
}

const openSessions = async (u) => {
  sessionsModal.value = { show: true, id: u.id, username: u.linux_username, loading: true, sessions: [], totals: null }
  await loadSessions()
}

const loadSessions = async () => {
  sessionsModal.value.loading = true
  try {
    const r = await api.get(`${base.value}/${sessionsModal.value.id}/sessions`, { params: { limit: 100 } })
    if (r.data?.success) {
      sessionsModal.value.sessions = r.data.data?.sessions || []
      sessionsModal.value.totals = r.data.data?.totals || null
    } else {
      toast.error(r.data?.error || 'Could not load sessions')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Could not load sessions')
  } finally {
    sessionsModal.value.loading = false
  }
}

const savePassword = async () => {
  if ((passwordModal.value.password || '').length < 8) {
    toast.error('Password must be at least 8 characters')
    return
  }
  try {
    const r = await api.post(`${base.value}/${passwordModal.value.id}/password`, {
      password: passwordModal.value.password,
    })
    if (r.data?.success) {
      toast.success('Password updated')
      passwordModal.value = { show: false, id: null, username: '', password: '' }
      await fetchUsers()
    } else {
      toast.error(r.data?.error || 'Update failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Update failed')
  }
}

const copy = (text) => {
  navigator.clipboard?.writeText(text)
  toast.info('Copied')
}

onMounted(fetchUsers)
</script>

<template>
  <div class="card">
    <div class="card-header flex items-center justify-between gap-2 flex-wrap">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-emerald-500">lock_person</span>
        <h3 class="font-semibold">Additional Restricted SFTP Users</h3>
        <span class="badge badge-neutral">jailed</span>
      </div>
      <button class="btn-secondary btn-sm" :disabled="loading" @click="fetchUsers">
        <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': loading }">refresh</span>
      </button>
    </div>

    <div class="card-body space-y-4">
      <p class="text-xs text-surface-500 dark:text-surface-400">
        Each user is chroot-jailed to a single folder under
        <span class="font-mono">{{ homeRoot }}</span> and can only reach that folder over SFTP
        (port <span class="font-mono">{{ port }}</span>). They cannot see the rest of the site.
      </p>

      <!-- ─── List ─── -->
      <div v-if="loading" class="space-y-2">
        <div class="skeleton h-12 w-full rounded-xl" />
        <div class="skeleton h-12 w-full rounded-xl" />
      </div>
      <div
        v-else-if="!users.length"
        class="text-sm text-surface-500 dark:text-surface-400 flex items-center gap-2 py-2"
      >
        <span class="material-symbols-rounded text-base">person_off</span>
        No restricted SFTP users yet.
      </div>
      <ul v-else class="space-y-2">
        <li
          v-for="u in users"
          :key="u.id"
          class="p-3 rounded-xl text-xs
                 border border-surface-200 dark:border-[rgb(var(--color-border))]
                 bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]"
        >
          <div class="flex items-start justify-between gap-2 flex-wrap">
            <div class="min-w-0">
              <p class="font-semibold text-sm flex items-center gap-2 flex-wrap">
                {{ u.display_name || u.linux_username }}
                <span
                  v-if="u.online"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold
                         bg-green-500/15 text-green-600 dark:text-green-400"
                  title="Currently connected"
                >
                  <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75" />
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500" />
                  </span>
                  Online
                </span>
                <span class="badge" :class="statusBadge(u.status)">{{ u.status }}</span>
                <span class="badge badge-neutral">{{ u.auth_type }}</span>
              </p>
              <p class="text-[11px] text-surface-500 dark:text-surface-400">
                user <span class="font-mono">{{ u.linux_username }}</span>
                <button class="btn-ghost btn-xs" title="Copy username" @click="copy(u.linux_username)">
                  <span class="material-symbols-rounded text-xs">content_copy</span>
                </button>
              </p>
              <p class="text-[11px] mt-1">
                <span class="text-surface-500 dark:text-surface-400">Folder:</span>
                <span class="font-mono break-all">{{ u.target_path }}</span>
              </p>

              <!-- Activity at a glance: online state + last login + transfer total -->
              <div class="flex items-center gap-x-3 gap-y-1 flex-wrap text-xs mt-1.5">
                <span
                  v-if="u.online"
                  class="inline-flex items-center gap-1 font-medium text-green-600 dark:text-green-400"
                >
                  <span class="material-symbols-rounded text-sm">wifi</span>
                  Connected now
                </span>
                <span v-else-if="u.last_login_at" class="inline-flex items-center gap-1 text-surface-500 dark:text-surface-400">
                  <span class="material-symbols-rounded text-sm">schedule</span>
                  Last login {{ u.last_login_at }}<span class="font-mono ml-1">{{ u.last_login_ip }}</span>
                </span>
                <span v-else class="inline-flex items-center gap-1 text-surface-400">
                  <span class="material-symbols-rounded text-sm">person_off</span>
                  Never logged in
                </span>
                <span class="inline-flex items-center gap-1 text-surface-600 dark:text-surface-300" title="Total data transferred (all sessions)">
                  <span class="material-symbols-rounded text-sm">swap_vert</span>
                  <span class="font-semibold">{{ formatBytes(u.total_bytes || 0) }}</span>
                </span>
                <button
                  class="inline-flex items-center gap-1 text-surface-500 dark:text-surface-400 hover:text-primary-500"
                  title="View sessions"
                  @click="openSessions(u)"
                >
                  <span class="material-symbols-rounded text-sm">history</span>
                  {{ u.session_count || 0 }} session{{ (u.session_count || 0) === 1 ? '' : 's' }}
                </button>
                <span class="inline-flex items-center gap-1 text-surface-500 dark:text-surface-400">
                  <span class="material-symbols-rounded text-sm">vpn_key</span>
                  {{ u.key_count }}
                </span>
              </div>
            </div>
            <div class="flex items-center gap-1 shrink-0 flex-wrap justify-end">
              <button class="btn-ghost btn-sm" title="Activity & transfers" @click="openSessions(u)">
                <span class="material-symbols-rounded text-sm">history</span>
              </button>
              <button class="btn-ghost btn-sm" title="Manage keys" @click="openKeys(u)">
                <span class="material-symbols-rounded text-sm">vpn_key</span>
              </button>
              <button class="btn-ghost btn-sm" title="Set password" @click="openPassword(u)">
                <span class="material-symbols-rounded text-sm">password</span>
              </button>
              <button class="btn-ghost btn-sm" title="Repair" :disabled="busyId === u.id" @click="repair(u)">
                <span class="material-symbols-rounded text-sm">healing</span>
              </button>
              <button
                class="btn-ghost btn-sm"
                :title="u.status === 'active' ? 'Disable' : 'Enable'"
                :disabled="busyId === u.id || u.status === 'deleting'"
                @click="toggleStatus(u)"
              >
                <span class="material-symbols-rounded text-sm">
                  {{ u.status === 'active' ? 'toggle_on' : 'toggle_off' }}
                </span>
              </button>
              <button class="btn-ghost btn-sm text-red-500" title="Delete" @click="askDelete(u)">
                <span class="material-symbols-rounded text-sm">delete</span>
              </button>
            </div>
          </div>
        </li>
      </ul>

      <!-- ─── Add user ─── -->
      <div class="border-t border-surface-200 dark:border-[rgb(var(--color-border))] pt-4 space-y-2">
        <h4 class="text-sm font-semibold flex items-center gap-1.5">
          <span class="material-symbols-rounded text-primary-500 text-base">person_add</span>
          Add SFTP user
        </h4>
        <div>
          <input
            v-model="form.username"
            type="text"
            name="flowone-sftp-login"
            autocomplete="off"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            data-1p-ignore="true"
            data-lpignore="true"
            data-form-type="other"
            readonly
            @focus="allowEdit"
            class="input w-full text-sm font-mono"
            placeholder="SFTP username (e.g. printshop)"
          />
          <p class="text-[11px] text-surface-500 dark:text-surface-400 mt-1">
            This is the login name. 3-32 chars: start with a lowercase letter, then letters, digits,
            <span class="font-mono">_</span> or <span class="font-mono">-</span>. Must not already exist on the server.
          </p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <input
            v-model="form.display_name"
            type="text"
            name="flowone-sftp-label"
            autocomplete="off"
            data-1p-ignore="true"
            data-lpignore="true"
            data-form-type="other"
            class="input w-full text-sm"
            placeholder="Display label (optional, e.g. Print shop)"
          />
          <select v-model="form.auth_type" class="input w-full text-sm">
            <option value="password">Password only</option>
            <option value="key">SSH key only</option>
            <option value="both">Password + SSH key</option>
          </select>
        </div>
        <div class="flex gap-2">
          <input
            v-model="form.target_path"
            type="text"
            name="flowone-sftp-folder"
            autocomplete="off"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            data-1p-ignore="true"
            data-lpignore="true"
            data-form-type="other"
            readonly
            @focus="allowEdit"
            class="input w-full text-sm font-mono"
            :placeholder="`${homeRoot}/public_html/uploads`"
          />
          <button class="btn-secondary btn-sm shrink-0" type="button" title="Browse folders" @click="openPicker">
            <span class="material-symbols-rounded text-sm">folder_open</span>
            Browse
          </button>
        </div>
        <p class="text-[11px] text-surface-500 dark:text-surface-400">
          Must be a subfolder inside <span class="font-mono">{{ homeRoot }}</span> (the site home and
          <span class="font-mono">public_html</span> root cannot be chosen).
        </p>
        <div v-if="form.auth_type !== 'key'" class="flex gap-2">
          <div class="relative w-full">
            <input
              v-model="form.password"
              :type="showCreatePassword ? 'text' : 'password'"
              name="flowone-sftp-new-password"
              autocomplete="new-password"
              data-1p-ignore="true"
              data-lpignore="true"
              data-form-type="other"
              class="input w-full text-sm font-mono pr-10"
              placeholder="Password (leave blank to auto-generate)"
            />
            <button
              type="button"
              class="absolute inset-y-0 right-0 px-3 flex items-center text-surface-500 hover:text-surface-700 dark:hover:text-surface-200"
              :title="showCreatePassword ? 'Hide' : 'Show'"
              @click="showCreatePassword = !showCreatePassword"
            >
              <span class="material-symbols-rounded text-base">{{ showCreatePassword ? 'visibility_off' : 'visibility' }}</span>
            </button>
          </div>
          <button class="btn-secondary btn-sm shrink-0" type="button" title="Generate a strong password" @click="generateCreatePassword">
            <span class="material-symbols-rounded text-sm">password</span>
            Generate
          </button>
        </div>
        <textarea
          v-if="form.auth_type !== 'password'"
          v-model="form.keys"
          rows="2"
          class="input w-full text-xs font-mono"
          placeholder="One SSH public key per line (optional)"
        ></textarea>
        <div class="flex justify-end">
          <button class="btn-primary btn-sm" :disabled="creating" @click="createUser">
            <span v-if="creating" class="spinner-sm" />
            <span v-else class="material-symbols-rounded text-sm">add</span>
            Create user
          </button>
        </div>
      </div>
    </div>

    <!-- ─── New credential (shown once) ─── -->
    <Modal :show="credential.show" title="SFTP user created" size="md" @close="credential.show = false">
      <p class="text-sm text-surface-600 dark:text-surface-300 mb-3">
        Copy these now - the password is stored encrypted and will not be shown again.
      </p>
      <div class="space-y-2 text-sm">
        <div class="flex items-center justify-between gap-2 p-2 rounded-lg bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))]">
          <span><span class="text-surface-500">User:</span> <span class="font-mono">{{ credential.username }}</span></span>
          <button class="btn-ghost btn-sm" @click="copy(credential.username)">
            <span class="material-symbols-rounded text-sm">content_copy</span>
          </button>
        </div>
        <div class="flex items-center justify-between gap-2 p-2 rounded-lg bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))]">
          <span><span class="text-surface-500">Password:</span> <span class="font-mono">{{ credential.password }}</span></span>
          <button class="btn-ghost btn-sm" @click="copy(credential.password)">
            <span class="material-symbols-rounded text-sm">content_copy</span>
          </button>
        </div>
      </div>
      <template #footer>
        <button class="btn-primary" @click="credential.show = false">Done</button>
      </template>
    </Modal>

    <!-- ─── Keys modal ─── -->
    <Modal :show="keysModal.show" :title="`SSH keys - ${keysModal.username}`" size="lg" @close="keysModal.show = false">
      <div class="space-y-3">
        <div v-if="!keysModal.keys.length" class="text-sm text-surface-500">No keys yet.</div>
        <ul v-else class="space-y-2">
          <li
            v-for="(k, i) in keysModal.keys"
            :key="i"
            class="flex items-center justify-between gap-2 p-2 rounded-lg text-xs
                   bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))]"
          >
            <span class="font-mono truncate">{{ k }}</span>
            <button class="btn-ghost btn-sm text-red-500" @click="removeKey(i)">
              <span class="material-symbols-rounded text-sm">delete</span>
            </button>
          </li>
        </ul>
        <textarea
          v-model="keysModal.newKey"
          rows="2"
          class="input w-full text-xs font-mono"
          placeholder="ssh-ed25519 AAAA…"
        ></textarea>
      </div>
      <template #footer>
        <button class="btn-secondary" @click="keysModal.show = false">Close</button>
        <button class="btn-primary" @click="addKey">
          <span class="material-symbols-rounded text-sm">add</span>
          Add key
        </button>
      </template>
    </Modal>

    <!-- ─── Password modal ─── -->
    <Modal :show="passwordModal.show" :title="`Set password - ${passwordModal.username}`" size="md" @close="passwordModal.show = false">
      <div class="flex gap-2">
        <div class="relative w-full">
          <input
            v-model="passwordModal.password"
            :type="passwordModal.reveal ? 'text' : 'password'"
            name="flowone-sftp-reset-password"
            autocomplete="new-password"
            data-1p-ignore="true"
            data-lpignore="true"
            data-form-type="other"
            class="input w-full text-sm font-mono pr-10"
            placeholder="New password (min 8 characters)"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 px-3 flex items-center text-surface-500 hover:text-surface-700 dark:hover:text-surface-200"
            :title="passwordModal.reveal ? 'Hide' : 'Show'"
            @click="passwordModal.reveal = !passwordModal.reveal"
          >
            <span class="material-symbols-rounded text-base">{{ passwordModal.reveal ? 'visibility_off' : 'visibility' }}</span>
          </button>
        </div>
        <button class="btn-secondary btn-sm shrink-0" type="button" title="Generate a strong password" @click="generateModalPassword">
          <span class="material-symbols-rounded text-sm">password</span>
          Generate
        </button>
      </div>
      <template #footer>
        <button class="btn-secondary" @click="passwordModal.show = false">Cancel</button>
        <button class="btn-primary" @click="savePassword">Save</button>
      </template>
    </Modal>

    <!-- ─── Session activity & transfers ─── -->
    <Modal :show="sessionsModal.show" :title="`Activity - ${sessionsModal.username}`" size="lg" @close="sessionsModal.show = false">
      <div v-if="sessionsModal.loading" class="py-6 flex items-center justify-center text-surface-500">
        <span class="spinner-sm mr-2" /> Loading sessions…
      </div>
      <div v-else>
        <div v-if="sessionsModal.totals" class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
          <div class="p-2 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] text-center">
            <p class="text-[11px] text-surface-500 dark:text-surface-400">Sessions</p>
            <p class="text-sm font-semibold">{{ sessionsModal.totals.sessions }}</p>
          </div>
          <div class="p-2 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] text-center">
            <p class="text-[11px] text-surface-500 dark:text-surface-400">Uploaded</p>
            <p class="text-sm font-semibold">{{ formatBytes(sessionsModal.totals.bytes_uploaded) }}</p>
          </div>
          <div class="p-2 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] text-center">
            <p class="text-[11px] text-surface-500 dark:text-surface-400">Downloaded</p>
            <p class="text-sm font-semibold">{{ formatBytes(sessionsModal.totals.bytes_downloaded) }}</p>
          </div>
          <div class="p-2 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] text-center">
            <p class="text-[11px] text-surface-500 dark:text-surface-400">Total time</p>
            <p class="text-sm font-semibold">{{ formatDuration(sessionsModal.totals.duration_seconds) }}</p>
          </div>
        </div>

        <div v-if="!sessionsModal.sessions.length" class="text-sm text-surface-500 dark:text-surface-400 flex items-center gap-2 py-4">
          <span class="material-symbols-rounded text-base">hourglass_empty</span>
          No sessions recorded yet. Activity appears within ~1 minute of a login.
        </div>
        <div v-else class="overflow-x-auto -mx-1">
          <table class="w-full text-[11px]">
            <thead class="text-surface-500 dark:text-surface-400 text-left">
              <tr class="border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
                <th class="py-1.5 px-1 font-medium">Login</th>
                <th class="py-1.5 px-1 font-medium">Duration</th>
                <th class="py-1.5 px-1 font-medium">From</th>
                <th class="py-1.5 px-1 font-medium text-right">Up</th>
                <th class="py-1.5 px-1 font-medium text-right">Down</th>
                <th class="py-1.5 px-1 font-medium text-right">Files</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="s in sessionsModal.sessions"
                :key="s.id"
                class="border-b border-surface-100 dark:border-[rgb(var(--color-border))]/50"
              >
                <td class="py-1.5 px-1 whitespace-nowrap">
                  {{ s.login_at }}
                  <span v-if="s.status === 'open'" class="badge badge-success ml-1">live</span>
                </td>
                <td class="py-1.5 px-1 whitespace-nowrap">{{ s.status === 'open' ? '—' : formatDuration(s.duration_seconds) }}</td>
                <td class="py-1.5 px-1 font-mono">{{ s.client_ip || '—' }}</td>
                <td class="py-1.5 px-1 text-right whitespace-nowrap">{{ formatBytes(s.bytes_uploaded) }}</td>
                <td class="py-1.5 px-1 text-right whitespace-nowrap">{{ formatBytes(s.bytes_downloaded) }}</td>
                <td class="py-1.5 px-1 text-right whitespace-nowrap">{{ (s.files_uploaded || 0) + (s.files_downloaded || 0) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <template #footer>
        <button class="btn-secondary" :disabled="sessionsModal.loading" @click="loadSessions">
          <span class="material-symbols-rounded text-sm">refresh</span>
          Refresh
        </button>
        <button class="btn-primary" @click="sessionsModal.show = false">Close</button>
      </template>
    </Modal>

    <!-- ─── Delete confirm ─── -->
    <Modal :show="confirmDelete.show" title="Delete SFTP user" size="md" @close="confirmDelete.show = false">
      <p class="text-sm text-surface-600 dark:text-surface-300">
        This removes the Linux account <span class="font-mono">{{ confirmDelete.username }}</span>,
        unmounts its jail, and clears its ACL entries. The site's files are not deleted.
      </p>
      <template #footer>
        <button class="btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.show = false">
          Cancel
        </button>
        <button class="btn-danger" :disabled="confirmDelete.busy" @click="doDelete">
          <span v-if="confirmDelete.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">delete</span>
          Delete
        </button>
      </template>
    </Modal>

    <!-- ─── Folder picker ─── -->
    <Modal :show="picker.show" title="Choose target folder" size="lg" @close="picker.show = false">
      <div class="space-y-3">
        <div class="flex items-center gap-2 text-xs">
          <span class="material-symbols-rounded text-base text-surface-500">folder</span>
          <span class="font-mono break-all">{{ picker.path || '…' }}</span>
        </div>

        <div class="rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]/50 max-h-72 overflow-y-auto">
          <button
            v-if="picker.parent"
            type="button"
            class="w-full flex items-center gap-2 px-3 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-elevated))]"
            @click="browseTo(picker.parent)"
          >
            <span class="material-symbols-rounded text-base text-surface-500">drive_folder_upload</span>
            <span class="font-mono">..</span>
          </button>

          <div v-if="picker.loading" class="px-3 py-4 text-sm text-surface-500 flex items-center gap-2">
            <span class="spinner-sm" /> Loading…
          </div>
          <div
            v-else-if="!picker.dirs.length"
            class="px-3 py-4 text-sm text-surface-500"
          >
            No subfolders here.
          </div>
          <button
            v-for="d in picker.dirs"
            :key="d.path"
            type="button"
            class="w-full flex items-center gap-2 px-3 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-elevated))]"
            @click="browseTo(d.path)"
          >
            <span class="material-symbols-rounded text-base text-amber-500">folder</span>
            <span class="font-mono truncate">{{ d.name }}</span>
          </button>
        </div>

        <p v-if="!picker.selectable && picker.path" class="text-[11px] text-amber-600 dark:text-amber-400">
          This folder cannot be used (it is the site home or public_html root, or too shallow). Open a subfolder.
        </p>
      </div>
      <template #footer>
        <button class="btn-secondary" @click="picker.show = false">Cancel</button>
        <button class="btn-primary" :disabled="!picker.selectable" @click="chooseCurrentFolder">
          <span class="material-symbols-rounded text-sm">check</span>
          Use this folder
        </button>
      </template>
    </Modal>
  </div>
</template>
