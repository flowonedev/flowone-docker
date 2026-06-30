<script setup>
// SftpUsersView
// ---------------------------------------------------------------
// Global (admin) overview of every additional restricted SFTP user
// across all sites. Detailed creation / key / password management
// lives in each site's FTP/SFTP tab; this view is the cross-site
// roll-up with quick lifecycle actions (enable/disable, repair,
// delete) and a deep link into the owning site.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'

const toast = useToastStore()

const users = ref([])
const loading = ref(false)
const busyId = ref(null)
const search = ref('')
const confirmDelete = ref({ show: false, user: null, busy: false })
const sessionsModal = ref({ show: false, user: null, loading: false, sessions: [], totals: null })

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

const openSessions = async (u) => {
  sessionsModal.value = { show: true, user: u, loading: true, sessions: [], totals: null }
  await loadSessions()
}

const loadSessions = async () => {
  const u = sessionsModal.value.user
  if (!u) return
  sessionsModal.value.loading = true
  try {
    const r = await api.get(`${siteBase(u.domain)}/${u.id}/sessions`, { params: { limit: 100 } })
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

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return users.value
  return users.value.filter((u) =>
    [u.domain, u.linux_username, u.display_name, u.target_path]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  )
})

const counts = computed(() => {
  const c = { total: users.value.length, active: 0, disabled: 0, error: 0 }
  for (const u of users.value) {
    if (u.status === 'active') c.active++
    else if (u.status === 'disabled') c.disabled++
    else if (u.status === 'error') c.error++
  }
  return c
})

const statusBadge = (s) => ({
  active: 'badge-success',
  disabled: 'badge-neutral',
  error: 'badge-danger',
  deleting: 'badge-warning',
}[s] || 'badge-neutral')

const siteBase = (domain) => `/sites/${encodeURIComponent(domain)}/sftp-users`

const fetchUsers = async () => {
  loading.value = true
  try {
    const r = await api.get('/sftp-users')
    users.value = r.data?.success ? (r.data.data?.users || []) : []
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to load SFTP users')
    users.value = []
  } finally {
    loading.value = false
  }
}

const toggleStatus = async (u) => {
  const next = u.status === 'active' ? 'disabled' : 'active'
  busyId.value = u.id
  try {
    const r = await api.put(`${siteBase(u.domain)}/${u.id}`, { status: next })
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
    const r = await api.post(`${siteBase(u.domain)}/${u.id}/repair`, {})
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
  confirmDelete.value = { show: true, user: u, busy: false }
}

const doDelete = async () => {
  const u = confirmDelete.value.user
  if (!u) return
  confirmDelete.value.busy = true
  try {
    const r = await api.delete(`${siteBase(u.domain)}/${u.id}`)
    if (r.data?.success) {
      toast.success('SFTP user deleted')
      await fetchUsers()
      confirmDelete.value = { show: false, user: null, busy: false }
    } else {
      toast.error(r.data?.error || 'Delete failed')
      confirmDelete.value.busy = false
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Delete failed')
    confirmDelete.value.busy = false
  }
}

onMounted(fetchUsers)
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-xl font-semibold flex items-center gap-2">
          <span class="material-symbols-rounded text-emerald-500">lock_person</span>
          SFTP Users
        </h1>
        <p class="text-sm text-surface-500 dark:text-surface-400">
          Restricted, chroot-jailed SFTP accounts across all sites. Create and manage keys
          from each site's FTP/SFTP tab.
        </p>
      </div>
      <button class="btn-secondary btn-sm" :disabled="loading" @click="fetchUsers">
        <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': loading }">refresh</span>
        Refresh
      </button>
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="card card-body">
        <p class="text-xs uppercase text-surface-500">Total</p>
        <p class="text-2xl font-semibold">{{ counts.total }}</p>
      </div>
      <div class="card card-body">
        <p class="text-xs uppercase text-surface-500">Active</p>
        <p class="text-2xl font-semibold text-emerald-500">{{ counts.active }}</p>
      </div>
      <div class="card card-body">
        <p class="text-xs uppercase text-surface-500">Disabled</p>
        <p class="text-2xl font-semibold">{{ counts.disabled }}</p>
      </div>
      <div class="card card-body">
        <p class="text-xs uppercase text-surface-500">Errored</p>
        <p class="text-2xl font-semibold text-red-500">{{ counts.error }}</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header flex items-center justify-between gap-2 flex-wrap">
        <h3 class="font-semibold">All users</h3>
        <input
          v-model="search"
          type="text"
          class="input text-sm w-full sm:w-64"
          placeholder="Search domain, user, folder…"
        />
      </div>
      <div class="card-body">
        <div v-if="loading" class="space-y-2">
          <div class="skeleton h-12 w-full rounded-xl" />
          <div class="skeleton h-12 w-full rounded-xl" />
          <div class="skeleton h-12 w-full rounded-xl" />
        </div>
        <div
          v-else-if="!filtered.length"
          class="text-sm text-surface-500 dark:text-surface-400 flex items-center gap-2 py-4"
        >
          <span class="material-symbols-rounded text-base">person_off</span>
          No restricted SFTP users found.
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase text-surface-500 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
                <th class="py-2 pr-3">Site</th>
                <th class="py-2 pr-3">User</th>
                <th class="py-2 pr-3">Folder</th>
                <th class="py-2 pr-3">Auth</th>
                <th class="py-2 pr-3">Status</th>
                <th class="py-2 pr-3">Activity</th>
                <th class="py-2 pr-3 text-right">Transferred</th>
                <th class="py-2 pr-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="u in filtered"
                :key="u.id"
                class="border-b border-surface-100 dark:border-[rgb(var(--color-border))]/50"
              >
                <td class="py-2 pr-3">
                  <router-link
                    class="text-primary-500 hover:underline"
                    :to="{ name: 'site-manage-v2', params: { domain: u.domain }, query: { tab: 'ftp' } }"
                  >
                    {{ u.domain }}
                  </router-link>
                </td>
                <td class="py-2 pr-3">
                  <div class="font-medium">{{ u.display_name || u.linux_username }}</div>
                  <div class="text-[11px] font-mono text-surface-500">{{ u.linux_username }}</div>
                </td>
                <td class="py-2 pr-3 font-mono text-[11px] break-all max-w-[260px]">{{ u.target_path }}</td>
                <td class="py-2 pr-3"><span class="badge badge-neutral">{{ u.auth_type }}</span></td>
                <td class="py-2 pr-3">
                  <div class="flex items-center gap-1.5">
                    <span
                      v-if="u.online"
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-500/15 text-green-600 dark:text-green-400"
                      title="Currently connected"
                    >
                      <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75" />
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500" />
                      </span>
                      Online
                    </span>
                    <span class="badge" :class="statusBadge(u.status)">{{ u.status }}</span>
                  </div>
                </td>
                <td class="py-2 pr-3 text-[11px]">
                  <span v-if="u.online" class="text-green-600 dark:text-green-400 font-medium">Connected now</span>
                  <template v-else-if="u.last_login_at">
                    <span class="text-surface-600 dark:text-surface-300">{{ u.last_login_at }}</span><br />
                    <span class="font-mono text-surface-500">{{ u.last_login_ip }}</span>
                  </template>
                  <span v-else class="text-surface-400">never</span>
                </td>
                <td class="py-2 pr-3 text-right whitespace-nowrap">
                  <button
                    class="inline-flex items-center gap-1 font-medium hover:text-primary-500"
                    title="View sessions"
                    @click="openSessions(u)"
                  >
                    <span class="material-symbols-rounded text-sm">swap_vert</span>
                    {{ formatBytes(u.total_bytes || 0) }}
                  </button>
                  <div class="text-[10px] text-surface-500">{{ u.session_count || 0 }} session{{ (u.session_count || 0) === 1 ? '' : 's' }}</div>
                </td>
                <td class="py-2 pr-3">
                  <div class="flex items-center gap-1 justify-end">
                    <button class="btn-ghost btn-sm" title="Activity & transfers" @click="openSessions(u)">
                      <span class="material-symbols-rounded text-sm">history</span>
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
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <Modal
      :show="sessionsModal.show"
      :title="sessionsModal.user ? `Activity - ${sessionsModal.user.linux_username}` : 'Activity'"
      size="lg"
      @close="sessionsModal.show = false"
    >
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

    <Modal :show="confirmDelete.show" title="Delete SFTP user" size="md" @close="confirmDelete.show = false">
      <p v-if="confirmDelete.user" class="text-sm text-surface-600 dark:text-surface-300">
        Delete <span class="font-mono">{{ confirmDelete.user.linux_username }}</span>
        on <span class="font-mono">{{ confirmDelete.user.domain }}</span>? This removes the Linux
        account, unmounts its jail and clears its ACL entries. The site's files are not deleted.
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
  </div>
</template>
