<script setup>
// DatabasesTab
// ---------------------------------------------------------------
// Databases + phpMyAdmin SSO + per-user password reset for the V2
// site management view. Replaces the "databases" section of
// SiteDetailView.vue.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useInjectedSiteManage } from '@/composables/useSiteManage'
import Modal from '@/components/Modal.vue'

const props = defineProps({ domain: { type: String, required: true } })
const toast = useToastStore()
// siteV2.db_name is the authoritative DB this site was provisioned with
// (backfilled from the create saga). We use it to match the site's DB
// reliably instead of the lossy domain-prefix heuristic below.
const { siteV2 } = useInjectedSiteManage()

const databases = ref([])
const loading = ref(false)
const showCreate = ref(false)
const creating = ref(false)
const createForm = ref({ name: '', user: '', password: '' })
const pwModal = ref({ open: false, user: '', database: null, password: '', confirm: '' })
const pwSaving = ref(false)
const pwReveal = ref(false)
// Confirm-delete modal state (replaces window.confirm()).
const deleteModal = ref({ open: false, db: null, busy: false })
// Orphaned-database cleanup modal: lists DBs not linked to any site
// (the rogue flowone_* schemas left over from create/delete testing)
// and bulk-deletes the selected ones.
const orphanModal = ref({ open: false, loading: false, busy: false, list: [], selected: [] })

// Defensive: API envelope is { databases: [...] } - guard against the wrapper
// object being assigned to the array ref by accident.
const toArray = (v) => Array.isArray(v) ? v : []

// System schemas the panel must never surface as "site databases".
const SYSTEM_SCHEMAS = new Set([
  'mysql', 'information_schema', 'performance_schema', 'sys',
])

// Heuristic prefixes the old SiteDetailView used to match databases
// belonging to a specific site. The shared hosting habit is to name
// schemas after a stem of the domain (e.g. "examplecom_wp", "exam_db"),
// so we try a few common slices. The blanket "flowone_*" match the
// V2 view shipped with caused unrelated databases to leak into every
// site's tab - this restores the old, site-scoped behaviour.
const getDatabasePrefixes = (domainName) => {
  const clean = String(domainName ?? '').replace(/[.-]/g, '').toLowerCase()
  const parts = String(domainName ?? '').split('.')
  const firstPart = (parts[0] ?? '').toLowerCase()
  return [
    clean.substring(0, 4),
    clean.substring(0, 5),
    clean.substring(0, 6),
    firstPart.substring(0, 4),
    firstPart.substring(0, 5),
    firstPart.substring(0, 6),
    String(domainName ?? '').replace(/\./g, '_').substring(0, 10).toLowerCase(),
  ].filter((p) => p && p.length >= 3)
}

// Mirrors ResourceNameDeriver::dbName() on the agent: flowone_<sanitized>
// where sanitized = lowercase, every non-[a-z0-9_] -> '_', trimmed of '_'.
// We only reproduce the common (<=64 char) case; for pathologically long
// domains the agent appends a stable hash we can't recompute here without
// async crypto, so siteV2.db_name (authoritative) remains the primary match.
const deriveDbName = (domainName) => {
  const sanitized = String(domainName ?? '')
    .toLowerCase()
    .replace(/[^a-z0-9_]/g, '_')
    .replace(/^_+|_+$/g, '')
  return sanitized ? `flowone_${sanitized}` : ''
}

const domainDbs = computed(() => {
  const siteDb = siteV2.value?.db_name || ''
  const derived = deriveDbName(props.domain)
  const prefixes = getDatabasePrefixes(props.domain)
  return toArray(databases.value).filter((d) => {
    const name = String(d.name || '')
    const lower = name.toLowerCase()
    if (!lower || SYSTEM_SCHEMAS.has(lower)) return false
    // 1. Authoritative: the V2 site row's provisioned db_name.
    if (siteDb && name === siteDb) return true
    // 2. Recorded database_links association (surfaced by /databases).
    if (d.site === props.domain || d.site_domain === props.domain) return true
    if (d.linked_site === props.domain) return true
    if (Array.isArray(d.linked_sites) && d.linked_sites.some((s) => s?.domain === props.domain)) return true
    // 3. Derived flowone_<domain> name (covers a just-provisioned DB
    //    before the V2 row reloads).
    if (derived && lower === derived.toLowerCase()) return true
    // 4. Last resort: lossy domain-prefix heuristic.
    for (const prefix of prefixes) {
      if (lower.startsWith(prefix)) return true
    }
    return false
  })
})

// Mirror Validator::databaseName (^[a-zA-Z][a-zA-Z0-9_]{0,63}$) so the
// operator gets an inline warning BEFORE we fire a request that 400s.
const DB_NAME_RE = /^[a-zA-Z][a-zA-Z0-9_]{0,63}$/
const nameError = computed(() => {
  const name = createForm.value.name.trim()
  if (!name) return ''
  if (!DB_NAME_RE.test(name)) {
    return 'Use only letters, numbers and underscores, starting with a letter (max 64 characters).'
  }
  return ''
})

const allDatabases = computed(() =>
  toArray(databases.value).filter((d) => {
    const name = String(d.name || '').toLowerCase()
    return name && !SYSTEM_SCHEMAS.has(name)
  }),
)

// Unwraps { databases: [...] } | [...] | unknown -> always an array.
const unwrapList = (payload, ...keys) => {
  if (Array.isArray(payload)) return payload
  if (payload && typeof payload === 'object') {
    for (const key of keys) {
      if (Array.isArray(payload[key])) return payload[key]
    }
  }
  return []
}

const fetchDatabases = async () => {
  loading.value = true
  try {
    const r = await api.get('/databases')
    if (r.data?.success) {
      databases.value = unwrapList(r.data.data, 'databases', 'items')
    }
  } catch {
    databases.value = []
  } finally {
    loading.value = false
  }
}

const openPhpMyAdmin = async (db) => {
  try {
    const r = await api.post('/phpmyadmin/token', {
      database: db.name,
    })
    const url = r.data?.data?.url
    if (url) window.open(url, '_blank', 'noopener')
    else toast.error(r.data?.error || 'Failed to obtain phpMyAdmin SSO token')
  } catch (e) {
    toast.error(e?.response?.data?.error || 'phpMyAdmin SSO failed')
  }
}

// db.users from /databases is an array of { User, Host } objects.
// Rendering the object directly printed "[object Object]"; these
// helpers extract a clean label and the bare username. Tolerant of a
// plain-string shape too in case another endpoint returns strings.
const userName = (u) => (typeof u === 'string' ? u : (u?.User ?? ''))
const userLabel = (u) => {
  const name = userName(u)
  const host = (u && typeof u === 'object') ? (u.Host ?? '') : ''
  return host && host !== 'localhost' ? `${name}@${host}` : name
}

const generatePassword = () => {
  const chars =
    'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@$%^&*'
  let pw = ''
  for (let i = 0; i < 20; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length))
  return pw
}

const onCreate = async () => {
  const name = createForm.value.name.trim()
  if (!name) {
    toast.error('Name required')
    return
  }
  if (nameError.value) {
    toast.error(nameError.value)
    return
  }
  creating.value = true
  try {
    const r = await api.post('/databases', {
      name,
      user: (createForm.value.user || name).trim(),
      password: createForm.value.password || generatePassword(),
      // Send `domain` (the param DatabaseController::create reads) so the
      // DB is linked to this site in database_links.
      domain: props.domain,
    })
    if (r.data?.success) {
      toast.success('Database created')
      showCreate.value = false
      createForm.value = { name: '', user: '', password: '' }
      await fetchDatabases()
    } else {
      toast.error(r.data?.error || 'Create failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || e?.message || 'Create failed')
  } finally {
    creating.value = false
  }
}

const askDelete = (db) => {
  deleteModal.value = { open: true, db, busy: false }
}

const confirmDelete = async () => {
  const db = deleteModal.value.db
  if (!db) return
  deleteModal.value.busy = true
  try {
    const r = await api.delete(`/databases/${encodeURIComponent(db.name)}`)
    if (r.data?.success) {
      toast.success('Database deleted')
      await fetchDatabases()
    } else {
      toast.error(r.data?.error || 'Delete failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Delete failed')
  } finally {
    deleteModal.value = { open: false, db: null, busy: false }
  }
}

const openOrphans = async () => {
  orphanModal.value = { open: true, loading: true, busy: false, list: [], selected: [] }
  try {
    const r = await api.get('/databases/orphans')
    if (r.data?.success) {
      orphanModal.value.list = unwrapList(r.data.data, 'orphans')
    } else {
      toast.error(r.data?.error || 'Failed to load orphaned databases')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to load orphaned databases')
  } finally {
    orphanModal.value.loading = false
  }
}

const toggleOrphan = (name) => {
  const sel = orphanModal.value.selected
  const i = sel.indexOf(name)
  if (i === -1) sel.push(name)
  else sel.splice(i, 1)
}

const allOrphansSelected = computed(() =>
  orphanModal.value.list.length > 0
  && orphanModal.value.selected.length === orphanModal.value.list.length
)

const toggleAllOrphans = () => {
  if (allOrphansSelected.value) {
    orphanModal.value.selected = []
  } else {
    orphanModal.value.selected = orphanModal.value.list.map((d) => d.name)
  }
}

const deleteSelectedOrphans = async () => {
  const names = [...orphanModal.value.selected]
  if (!names.length) return
  orphanModal.value.busy = true
  let ok = 0
  const failed = []
  for (const name of names) {
    try {
      const r = await api.delete(`/databases/${encodeURIComponent(name)}`)
      if (r.data?.success) ok++
      else failed.push(name)
    } catch {
      failed.push(name)
    }
  }
  orphanModal.value.busy = false
  if (ok) toast.success(`Deleted ${ok} database${ok === 1 ? '' : 's'}`)
  if (failed.length) toast.error(`Failed to delete: ${failed.join(', ')}`)
  await fetchDatabases()
  if (failed.length) {
    await openOrphans()
  } else {
    orphanModal.value.open = false
  }
}

const openPw = (user, database = null) => {
  pwReveal.value = false
  pwModal.value = { open: true, user, database, password: '', confirm: '' }
}

// Inline mismatch warning + Save gating for the reset modal.
const pwMismatch = computed(() =>
  !!pwModal.value.confirm && pwModal.value.password !== pwModal.value.confirm
)
const pwValid = computed(() =>
  !!pwModal.value.password && pwModal.value.password === pwModal.value.confirm
)

const savePassword = async () => {
  if (!pwModal.value.password) {
    toast.error('Password required')
    return
  }
  if (pwModal.value.password !== pwModal.value.confirm) {
    toast.error('Passwords do not match')
    return
  }
  pwSaving.value = true
  try {
    const r = await api.post(
      `/db-users/${encodeURIComponent(pwModal.value.user)}/password`,
      {
        password: pwModal.value.password,
        // Re-grant on this DB so a reset also heals a missing/partial grant.
        database: pwModal.value.database || undefined,
      },
    )
    if (r.data?.success) {
      toast.success('Password updated')
      pwModal.value.open = false
    } else {
      toast.error(r.data?.error || 'Password change failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Password change failed')
  } finally {
    pwSaving.value = false
  }
}

onMounted(fetchDatabases)
</script>

<template>
  <div class="space-y-5">
    <div class="card">
      <div class="card-header flex items-center justify-between gap-2 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-amber-500">storage</span>
          <h3 class="font-semibold">Databases</h3>
        </div>
        <div class="flex items-center gap-2">
          <button class="btn-secondary btn-sm" :disabled="loading" @click="fetchDatabases">
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': loading }">refresh</span>
          </button>
          <button
            class="btn-secondary btn-sm"
            title="Find and remove databases not linked to any site"
            @click="openOrphans"
          >
            <span class="material-symbols-rounded text-sm">cleaning_services</span>
            Cleanup orphans
          </button>
          <button class="btn-primary btn-sm" @click="showCreate = true">
            <span class="material-symbols-rounded text-sm">add</span>
            New Database
          </button>
        </div>
      </div>

      <div class="card-body">
        <div v-if="loading" class="space-y-2">
          <div class="skeleton h-10 w-full rounded-xl" />
          <div class="skeleton h-10 w-full rounded-xl" />
          <div class="skeleton h-10 w-3/4 rounded-xl" />
        </div>
        <div
          v-else-if="!domainDbs.length"
          class="py-8 text-center flex flex-col items-center gap-2 text-surface-500 dark:text-surface-400"
        >
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">
            database
          </span>
          <p class="text-sm">No databases linked to this site yet.</p>
          <p
            v-if="getDatabasePrefixes(domain).length"
            class="text-xs"
          >
            Looking for prefixes:
            <span class="font-mono">
              {{ getDatabasePrefixes(domain).slice(0, 3).join(', ') }}…
            </span>
          </p>
          <button class="btn-primary btn-sm" @click="showCreate = true">
            <span class="material-symbols-rounded text-sm">add</span>
            Create database
          </button>
        </div>
        <div v-else class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th class="text-left">Name</th>
                <th class="text-left">Users</th>
                <th class="text-left">Tables</th>
                <th class="text-left">Size</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="db in domainDbs" :key="db.name">
                <td class="font-mono text-xs">
                  <div class="flex items-center gap-2">
                    <div
                      class="w-8 h-8 rounded-lg flex items-center justify-center
                             bg-amber-100 dark:bg-amber-500/15
                             text-amber-600 dark:text-amber-400 shrink-0"
                    >
                      <span class="material-symbols-rounded text-base">database</span>
                    </div>
                    <span class="truncate">{{ db.name }}</span>
                  </div>
                </td>
                <td class="text-xs">
                  <div
                    v-for="(u, i) in (db.users ?? [])"
                    :key="userName(u) + '@' + i"
                    class="flex items-center gap-1"
                  >
                    <span class="font-mono">{{ userLabel(u) }}</span>
                    <button
                      class="btn-ghost btn-sm"
                      title="Change password"
                      @click="openPw(userName(u), db.name)"
                    >
                      <span class="material-symbols-rounded text-xs">key</span>
                    </button>
                  </div>
                </td>
                <td class="text-xs font-mono tabular-nums">{{ db.tables_count ?? db.tables ?? '—' }}</td>
                <td class="text-xs font-mono tabular-nums">{{ db.size_human ?? '—' }}</td>
                <td class="text-right">
                  <button
                    class="btn-ghost btn-sm text-primary-500"
                    title="Open phpMyAdmin"
                    @click="openPhpMyAdmin(db)"
                  >
                    <span class="material-symbols-rounded text-sm">open_in_new</span>
                  </button>
                  <button
                    class="btn-ghost btn-sm text-red-500"
                    title="Delete database"
                    @click="askDelete(db)"
                  >
                    <span class="material-symbols-rounded text-sm">delete</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ─── Available databases hint (parity with old SiteDetailView) ───
         When the prefix heuristic doesn't match anything but there ARE
         databases on the server, show the operator the available names
         so they can spot a mismatched prefix at a glance. -->
    <div
      v-if="!loading && !domainDbs.length && allDatabases.length"
      class="card p-4"
    >
      <p class="text-sm text-surface-500 dark:text-surface-400 mb-3">
        Available databases ({{ allDatabases.length }} total):
      </p>
      <div class="flex flex-wrap gap-2">
        <span
          v-for="db in allDatabases.slice(0, 20)"
          :key="db.name"
          class="text-xs px-2 py-1 rounded-full bg-surface-100 dark:bg-surface-700 font-mono"
        >
          {{ db.name }}
        </span>
        <span
          v-if="allDatabases.length > 20"
          class="text-xs text-surface-400"
        >
          +{{ allDatabases.length - 20 }} more
        </span>
      </div>
    </div>

    <!-- ─── Create modal ─── -->
    <Modal
      :show="showCreate"
      title="New Database"
      size="md"
      @close="showCreate = false"
    >
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-semibold mb-1">Name</label>
          <input
            v-model="createForm.name"
            type="text"
            class="input w-full"
            :class="{ 'border-red-400 dark:border-red-500/50': nameError }"
            placeholder="letters, numbers, underscores"
          />
          <p v-if="nameError" class="mt-1 text-xs text-red-600 dark:text-red-400 flex items-start gap-1">
            <span class="material-symbols-rounded text-sm shrink-0">error</span>
            <span>{{ nameError }}</span>
          </p>
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">
            User
            <span class="text-surface-400 font-normal">(defaults to name)</span>
          </label>
          <input v-model="createForm.user" type="text" class="input w-full" />
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">
            Password
            <span class="text-surface-400 font-normal">(blank = generated)</span>
          </label>
          <input v-model="createForm.password" type="text" class="input w-full font-mono text-sm" />
        </div>
      </div>
      <template #footer>
        <button class="btn-secondary" @click="showCreate = false">Cancel</button>
        <button
          class="btn-primary"
          :disabled="creating || !createForm.name.trim() || !!nameError"
          @click="onCreate"
        >
          <span v-if="creating" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">add</span>
          Create
        </button>
      </template>
    </Modal>

    <!-- ─── Password modal ─── -->
    <Modal
      :show="pwModal.open"
      :title="`Reset password for ${pwModal.user}`"
      size="md"
      @close="pwModal.open = false"
    >
      <div class="space-y-2">
        <div class="relative">
          <input
            v-model="pwModal.password"
            :type="pwReveal ? 'text' : 'password'"
            class="input w-full font-mono pr-10"
            placeholder="New password"
            autocomplete="new-password"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 px-3 flex items-center text-surface-400
                   hover:text-surface-700 dark:hover:text-surface-200"
            :title="pwReveal ? 'Hide passwords' : 'Show passwords'"
            @click="pwReveal = !pwReveal"
          >
            <span class="material-symbols-rounded text-base">
              {{ pwReveal ? 'visibility_off' : 'visibility' }}
            </span>
          </button>
        </div>
        <input
          v-model="pwModal.confirm"
          :type="pwReveal ? 'text' : 'password'"
          class="input w-full font-mono"
          :class="{ 'border-red-400 dark:border-red-500/50': pwMismatch }"
          placeholder="Confirm password"
          autocomplete="new-password"
        />
        <p v-if="pwMismatch" class="text-xs text-red-600 dark:text-red-400 flex items-start gap-1">
          <span class="material-symbols-rounded text-sm shrink-0">error</span>
          <span>Passwords do not match.</span>
        </p>
      </div>
      <template #footer>
        <button class="btn-secondary" @click="pwModal.open = false">Cancel</button>
        <button class="btn-primary" :disabled="pwSaving || !pwValid" @click="savePassword">
          <span v-if="pwSaving" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">save</span>
          Save
        </button>
      </template>
    </Modal>

    <!-- ─── Delete confirmation ─── -->
    <Modal
      :show="deleteModal.open"
      :title="`Delete database ${deleteModal.db?.name ?? ''}`"
      size="md"
      @close="deleteModal.open = false"
    >
      <div
        class="rounded-xl border border-red-300 bg-red-50 dark:bg-red-500/10
               dark:border-red-500/30 px-3 py-2 text-sm text-red-800 dark:text-red-200
               flex items-start gap-2"
      >
        <span class="material-symbols-rounded text-base shrink-0 mt-0.5">warning</span>
        <span>
          This drops the database and every table in it.
          <strong>The data cannot be recovered without a backup.</strong>
        </span>
      </div>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="deleteModal.busy"
          @click="deleteModal.open = false"
        >
          Cancel
        </button>
        <button
          class="btn-danger"
          :disabled="deleteModal.busy"
          @click="confirmDelete"
        >
          <span v-if="deleteModal.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">delete</span>
          Delete database
        </button>
      </template>
    </Modal>

    <!-- ─── Orphaned databases cleanup ─── -->
    <Modal
      :show="orphanModal.open"
      title="Cleanup orphaned databases"
      size="lg"
      @close="orphanModal.open = false"
    >
      <div class="space-y-3">
        <p class="text-sm text-surface-500 dark:text-surface-400">
          These databases are not linked to any site. They are usually left
          over from deleted or failed sites. Select the ones to remove.
        </p>

        <div v-if="orphanModal.loading" class="space-y-2">
          <div class="skeleton h-10 w-full rounded-xl" />
          <div class="skeleton h-10 w-full rounded-xl" />
          <div class="skeleton h-10 w-2/3 rounded-xl" />
        </div>

        <div
          v-else-if="!orphanModal.list.length"
          class="py-8 text-center flex flex-col items-center gap-2 text-surface-500 dark:text-surface-400"
        >
          <span class="material-symbols-rounded text-4xl text-emerald-400">task_alt</span>
          <p class="text-sm">No orphaned databases. Everything is linked to a site.</p>
        </div>

        <div v-else class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th class="w-8">
                  <input
                    type="checkbox"
                    :checked="allOrphansSelected"
                    @change="toggleAllOrphans"
                  />
                </th>
                <th class="text-left">Name</th>
                <th class="text-left">Tables</th>
                <th class="text-left">Size</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="db in orphanModal.list" :key="db.name">
                <td>
                  <input
                    type="checkbox"
                    :checked="orphanModal.selected.includes(db.name)"
                    @change="toggleOrphan(db.name)"
                  />
                </td>
                <td class="font-mono text-xs">
                  <span class="truncate">{{ db.name }}</span>
                </td>
                <td class="text-xs font-mono tabular-nums">{{ db.tables_count ?? '—' }}</td>
                <td class="text-xs font-mono tabular-nums">{{ db.size_human ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="orphanModal.busy"
          @click="orphanModal.open = false"
        >
          Close
        </button>
        <button
          class="btn-danger"
          :disabled="orphanModal.busy || !orphanModal.selected.length"
          @click="deleteSelectedOrphans"
        >
          <span v-if="orphanModal.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">delete_sweep</span>
          Delete selected ({{ orphanModal.selected.length }})
        </button>
      </template>
    </Modal>
  </div>
</template>
