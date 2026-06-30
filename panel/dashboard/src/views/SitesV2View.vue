<script setup>
// SitesV2View
// ---------------------------------------------------------------
// Operator-grade async sites dashboard backed by the /api/sites/v2
// + /api/jobs endpoints introduced in Steps 6/7/4c.
//
// Why a new view (vs. patching SitesView.vue):
//   - The legacy view is 2200+ lines and synchronous-request-shaped.
//     Layering job IDs + live progress + lifecycle menus into it
//     would push it over the modularity guardrails AND mix two
//     incompatible request flows in one file.
//   - This view stays small and only knows about the new flow:
//     enqueue -> get job id -> tail JobProgressModal -> refresh.
//
// Composition:
//   - Top metrics: counts by actual_state.
//   - Sites table: filterable + searchable list with lifecycle menu
//     and details link per row.
//   - Active jobs panel: shows queued / running / leased jobs across
//     the system so an operator can spot stuck work at a glance.
//   - Job progress modal: pops automatically when an action is fired
//     and is re-openable from the jobs panel.
//
// Polling strategy:
//   - Sites list refreshes on demand and after every successful
//     enqueue/refresh signal from child components.
//   - Active jobs panel auto-refreshes every 5 seconds while the
//     view is visible. We pause when document.hidden is true so we
//     don't hammer the API in background tabs.

import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import JobProgressModal from '@/components/JobProgressModal.vue'
import SiteLifecycleMenu from '@/components/SiteLifecycleMenu.vue'
import CreateSiteV2Modal from '@/components/CreateSiteV2Modal.vue'
import {
  listSites,
  deleteSite,
  purgeTombstone,
  listJobs,
  retryJob,
} from '@/services/sitesV2'
// Templates are still served by the legacy SystemAction endpoints
// (/system/templates/*). We hit them directly via the panel API
// client; no new backend surface is needed for parity with the
// legacy SitesView template UX.
import api from '@/services/api'

const toast = useToastStore()

const loading = ref(false)
const sites = ref([])
const pagination = ref({ page: 1, per_page: 50, total: 0, pages: 0 })

const filters = ref({
  search: '',
  actual_state: '',
})

// Active jobs panel state
const activeJobs = ref([])
const activeJobsLoading = ref(false)
let activeJobsTimer = null

// Job progress modal
const progressModal = ref({
  show: false,
  jobId: null,
  title: '',
})

// Create modal
const createModal = ref(false)

// Delete confirmation
const deleteState = ref({
  show: false,
  site: null,
  busy: false,
  skipSnapshot: false,
})

// Tombstone purge confirmation (Level 3 hard-delete: DB row +
// dependent history + snapshot dir). Only reachable on rows whose
// actual_state is already 'absent'.
const purgeState = ref({
  show: false,
  site: null,
  busy: false,
  preview: null,        // { rows_to_delete, snapshot_present, ... } from dry run
  previewing: false,
  typedDomain: '',      // operator must type the domain to enable the button
})

// Apply-template modal state. The template catalogue is fetched
// once on first open and cached for the rest of the session - it's
// a tiny payload and rarely changes.
const templateModal = ref({
  show: false,
  site: null,
  selectedId: '',
  applying: false,
  reverting: false,
})
const availableTemplates = ref([])
const templatesLoading = ref(false)
const inlineRevertingDomain = ref(null)

// ─────────────────────────────────────────────────────────
// High-level summary derived from the current page response. Parity
// with the legacy SitesView: total, ssl coverage split, and the sum
// of size_bytes across the visible rows. Operators get an immediate
// sense of fleet health without having to scan the table.
const summaryStats = computed(() => {
  let withSsl = 0
  let withoutSsl = 0
  let totalSize = 0
  for (const s of sites.value) {
    if (s.ssl_enabled) withSsl++
    else withoutSsl++
    totalSize += Number(s.size_bytes ?? 0)
  }
  return {
    total: pagination.value?.total ?? sites.value.length,
    withSsl,
    withoutSsl,
    totalSize,
  }
})

const formatTotalSize = (bytes) => {
  if (!bytes || bytes <= 0) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let size = Number(bytes)
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024
    i++
  }
  return `${size < 10 && i > 0 ? size.toFixed(1) : Math.round(size)} ${units[i]}`
}

// ─────────────────────────────────────────────────────────
// Template helpers (parity with legacy SitesView)
// ─────────────────────────────────────────────────────────

const templateTypeLabel = (type) => {
  const labels = {
    site_placeholder: 'Placeholder',
    site_coming_soon: 'Coming Soon',
    site_maintenance: 'Maintenance',
  }
  return labels[type] || type || 'Template'
}

const templateTypeBadgeClass = (type) => {
  const base = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium'
  const classes = {
    site_placeholder: `${base} bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300`,
    site_coming_soon: `${base} bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300`,
    site_maintenance: `${base} bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300`,
  }
  return classes[type] || `${base} bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300`
}

// Capsule status pill (rounded-full so it matches the legacy
// SitesView shape and our other badges). Colors are kept identical
// to the previous semantics so reconciler/state-machine consumers
// see no visual regression - only the rounding and a touch more
// padding changed.
const statePillClass = (state) => {
  const base = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium'
  switch (String(state ?? '').toLowerCase()) {
    case 'active':
      return `${base} bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300`
    case 'provisioning':
    case 'deleting':
      return `${base} bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300`
    case 'pending_dns':
      return `${base} bg-yellow-100 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-300`
    case 'suspended':
      return `${base} bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300`
    case 'archived':
      return `${base} bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300`
    case 'degraded':
      return `${base} bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300`
    case 'failed':
      return `${base} bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300`
    default:
      return `${base} bg-surface-100 text-surface-700 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-300`
  }
}

const stateDisplay = (state) => {
  const s = String(state ?? '').toLowerCase()
  if (s === 'pending_dns') return 'SSL pending'
  return state
}

const jobStatusPillClass = (status) => {
  const base = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium'
  switch (String(status ?? '').toLowerCase()) {
    case 'queued':
      return `${base} bg-surface-200 text-surface-700 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-200`
    case 'running':
    case 'leased':
      return `${base} bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300`
    case 'succeeded':
      return `${base} bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300`
    case 'failed':
      return `${base} bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300`
    case 'cancelled':
      return `${base} bg-surface-100 text-surface-500 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-400`
    case 'degraded':
      return `${base} bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300`
    default:
      return `${base} bg-surface-100 text-surface-700 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-300`
  }
}

// ─────────────────────────────────────────────────────────
// Data fetchers
// ─────────────────────────────────────────────────────────

const fetchSites = async () => {
  loading.value = true
  try {
    const params = {
      page: pagination.value.page,
      per_page: pagination.value.per_page,
    }
    if (filters.value.search.trim()) {
      params.search = filters.value.search.trim()
    }
    if (filters.value.actual_state) {
      params.actual_state = filters.value.actual_state
    }
    const data = await listSites(params)
    sites.value = data?.sites ?? []
    if (data?.pagination) {
      pagination.value = { ...pagination.value, ...data.pagination }
    }
  } catch (e) {
    toast.error(e?.message ?? 'Failed to load sites')
  } finally {
    loading.value = false
  }
  fetchAttentionCounts()
}

// ─────────────────────────────────────────────────────────
// "Needs attention" counts: tombstone rows (absent) and parked
// failures (failed / degraded) across the WHOLE dataset, not just the
// current page. Tombstones especially used to rot invisibly - a
// deleted site's row lingered and its mail/DNS leftovers only
// surfaced when someone stumbled over them in another view. The
// banner makes them one click away from review + purge.
const attention = ref({ absent: 0, failed: 0, degraded: 0 })

const fetchAttentionCounts = async () => {
  const countFor = async (state) => {
    try {
      const data = await listSites({ actual_state: state, page: 1, per_page: 1 })
      return Number(data?.pagination?.total ?? 0)
    } catch {
      return 0
    }
  }
  const [absent, failed, degraded] = await Promise.all([
    countFor('absent'),
    countFor('failed'),
    countFor('degraded'),
  ])
  attention.value = { absent, failed, degraded }
}

const attentionTotal = computed(() =>
  attention.value.absent + attention.value.failed + attention.value.degraded)

const focusState = (state) => {
  filters.value.actual_state = state
}

const fetchActiveJobs = async () => {
  if (document.hidden) return
  activeJobsLoading.value = true
  try {
    const queued = await listJobs({ status: 'queued', per_page: 25 })
    const running = await listJobs({ status: 'running', per_page: 25 })
    const merged = [
      ...(running?.jobs ?? []),
      ...(queued?.jobs ?? []),
    ]
    merged.sort((a, b) => {
      // running first, then by enqueued_at desc
      if (a.status === 'running' && b.status !== 'running') return -1
      if (a.status !== 'running' && b.status === 'running') return 1
      return String(b.enqueued_at).localeCompare(String(a.enqueued_at))
    })
    activeJobs.value = merged
  } catch {
    // Active jobs panel is non-critical; silent retry on next tick.
  } finally {
    activeJobsLoading.value = false
  }
}

const startActiveJobsPolling = () => {
  fetchActiveJobs()
  if (activeJobsTimer) clearInterval(activeJobsTimer)
  activeJobsTimer = setInterval(fetchActiveJobs, 5000)
}

const stopActiveJobsPolling = () => {
  if (activeJobsTimer) {
    clearInterval(activeJobsTimer)
    activeJobsTimer = null
  }
}

// ─────────────────────────────────────────────────────────
// Actions
// ─────────────────────────────────────────────────────────

const openProgressModal = (jobId, action, domain) => {
  if (!jobId) return
  const title = domain
    ? `${action ?? 'Job'} - ${domain}`
    : `Job #${jobId}`
  progressModal.value = { show: true, jobId, title }
}

const onJobEnqueued = ({ jobId, action, site }) => {
  toast.success(`${action} job #${jobId} enqueued for ${site.domain}`)
  openProgressModal(jobId, action, site.domain)
  fetchActiveJobs()
}

const onLifecycleError = (msg) => {
  toast.error(msg ?? 'Lifecycle action failed')
}

const onProgressClose = () => {
  progressModal.value = { show: false, jobId: null, title: '' }
  // Job finished or was dismissed -> refresh underlying data
  fetchSites()
  fetchActiveJobs()
}

const beginDelete = (site) => {
  deleteState.value = {
    show: true,
    site,
    busy: false,
    skipSnapshot: false,
  }
}

const confirmDelete = async () => {
  const site = deleteState.value.site
  if (!site) return
  deleteState.value.busy = true
  try {
    const payload = deleteState.value.skipSnapshot
      ? { payload: { skip_snapshot: true } }
      : {}
    const data = await deleteSite(site.domain, payload)
    const jobId = data?.job?.id
    if (jobId) {
      openProgressModal(jobId, 'Delete', site.domain)
      toast.success(`Delete job #${jobId} enqueued for ${site.domain}`)
    }
    deleteState.value.show = false
    fetchSites()
    fetchActiveJobs()
  } catch (e) {
    toast.error(e?.message ?? 'Failed to enqueue delete job')
  } finally {
    deleteState.value.busy = false
  }
}

// ─────────────────────────────────────────────────────────
// Tombstone purge (hard-delete: DB + history + snapshot dir)
// ─────────────────────────────────────────────────────────

const beginPurge = async (site) => {
  purgeState.value = {
    show: true,
    site,
    busy: false,
    preview: null,
    previewing: true,
    typedDomain: '',
  }
  // Run a dry-run immediately so the modal can show the operator
  // exactly what's about to be wiped.
  try {
    const data = await purgeTombstone(site.domain, { dry_run: true })
    purgeState.value.preview = data
  } catch (e) {
    toast.error(e?.message ?? 'Failed to preview purge')
    purgeState.value.show = false
  } finally {
    purgeState.value.previewing = false
  }
}

const purgeConfirmed = computed(() =>
  Boolean(
    purgeState.value.site &&
      purgeState.value.typedDomain.trim() === purgeState.value.site.domain,
  ),
)

const confirmPurge = async () => {
  const site = purgeState.value.site
  if (!site || !purgeConfirmed.value) return
  purgeState.value.busy = true
  try {
    const data = await purgeTombstone(site.domain, { dry_run: false })
    const removedRows = data?.rows_deleted
      ? Object.values(data.rows_deleted).reduce((a, b) => a + (b || 0), 0)
      : 0
    const snapshotMsg = data?.snapshot_removed
      ? ' (snapshot dir removed)'
      : data?.snapshot_error
      ? ` (snapshot cleanup warning: ${data.snapshot_error})`
      : ''
    toast.success(
      `Purged ${site.domain}: ${removedRows} rows deleted${snapshotMsg}`,
    )
    purgeState.value.show = false
    fetchSites()
    fetchActiveJobs()
  } catch (e) {
    toast.error(e?.message ?? 'Failed to purge tombstone')
  } finally {
    purgeState.value.busy = false
  }
}

// ─────────────────────────────────────────────────────────
// Templates (apply / revert) - calls legacy /system/templates/*
// ─────────────────────────────────────────────────────────

const openTemplateModal = async (site) => {
  templateModal.value = {
    show: true,
    site,
    selectedId: site.template_type || '',
    applying: false,
    reverting: false,
  }
  // Lazy-load the template catalogue on first open. The endpoint
  // returns both site templates and error pages keyed by id; we
  // only surface entries whose id starts with `site_`.
  if (!availableTemplates.value.length) {
    templatesLoading.value = true
    try {
      const response = await api.get('/system/templates')
      if (response.data?.success) {
        availableTemplates.value = Object.entries(response.data.data?.templates || {})
          .filter(([id]) => id.startsWith('site_'))
          .map(([id, t]) => ({ ...t, id }))
      }
    } catch (e) {
      toast.error(e?.response?.data?.error ?? 'Failed to load templates')
    } finally {
      templatesLoading.value = false
    }
  }
}

const applyTemplate = async () => {
  const tm = templateModal.value
  if (!tm.site || !tm.selectedId) return
  tm.applying = true
  try {
    const response = await api.post(`/system/templates/${tm.selectedId}/apply`, {
      domain: tm.site.domain,
      filename: 'index.html',
    })
    if (response.data?.success) {
      toast.success(`Template applied to ${tm.site.domain}`)
      // Optimistic update so the table reflects the change without
      // waiting for the next fetchSites() round-trip.
      const row = sites.value.find((s) => s.domain === tm.site.domain)
      if (row) {
        row.template_type = tm.selectedId
        row.has_template_backup = true
      }
      tm.show = false
      fetchSites()
    } else {
      toast.error(response.data?.error || 'Failed to apply template')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to apply template')
  } finally {
    tm.applying = false
  }
}

const revertTemplateFromModal = async () => {
  const tm = templateModal.value
  if (!tm.site) return
  tm.reverting = true
  try {
    const response = await api.post(`/system/templates/revert/${tm.site.domain}`, {
      filename: 'index.html',
      remove_backup: true,
    })
    if (response.data?.success) {
      toast.success(`Reverted ${tm.site.domain} to original`)
      const row = sites.value.find((s) => s.domain === tm.site.domain)
      if (row) {
        row.template_type = null
        row.has_template_backup = false
      }
      tm.show = false
      fetchSites()
    } else {
      toast.error(response.data?.error || 'Failed to revert template')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to revert template')
  } finally {
    tm.reverting = false
  }
}

// Inline "restore" icon next to the template badge in the row.
// Same endpoint as the modal's restore, but no modal involved -
// quick path for the operator who just wants to undo.
const inlineRevertTemplate = async (site) => {
  if (!site.has_template_backup) return
  inlineRevertingDomain.value = site.domain
  try {
    const response = await api.post(`/system/templates/revert/${site.domain}`, {
      filename: 'index.html',
      remove_backup: true,
    })
    if (response.data?.success) {
      toast.success(`Reverted ${site.domain} to original`)
      site.template_type = null
      site.has_template_backup = false
    } else {
      toast.error(response.data?.error || `Failed to revert ${site.domain}`)
    }
  } catch (e) {
    const msg = e?.response?.data?.error || ''
    if (msg.includes('No backup') || msg.includes('not found')) {
      toast.warning(`${site.domain} has no template backup to revert`)
      site.template_type = null
      site.has_template_backup = false
    } else {
      toast.error(msg || 'Failed to revert template')
    }
  } finally {
    inlineRevertingDomain.value = null
  }
}

const onCreated = ({ jobId, domain }) => {
  createModal.value = false
  if (jobId) {
    openProgressModal(jobId, 'Create', domain)
    toast.success(`Create job #${jobId} enqueued for ${domain}`)
  }
  fetchSites()
  fetchActiveJobs()
}

const retryJobAction = async (job) => {
  try {
    const data = await retryJob(job.id, 'manual retry from sites view')
    const newId = data?.job?.id
    if (newId) {
      openProgressModal(newId, `Retry ${job.type ?? ''}`, job.site_domain)
      toast.success(`Retry job #${newId} enqueued for ${job.site_domain}`)
    }
    fetchActiveJobs()
  } catch (e) {
    toast.error(e?.message ?? 'Failed to retry job')
  }
}

// ─────────────────────────────────────────────────────────
// Lifecycle
// ─────────────────────────────────────────────────────────

const onVisibilityChange = () => {
  if (document.hidden) {
    stopActiveJobsPolling()
  } else {
    startActiveJobsPolling()
  }
}

// Instant search + filter: debounce typing so each keystroke doesn't
// hammer the API, but still feels live (no Enter / Apply required).
let searchDebounceTimer = null
watch(
  () => [filters.value.search, filters.value.actual_state],
  () => {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    searchDebounceTimer = setTimeout(() => {
      pagination.value.page = 1
      fetchSites()
    }, 250)
  },
)

onMounted(() => {
  fetchSites()
  startActiveJobsPolling()
  document.addEventListener('visibilitychange', onVisibilityChange)
})

onBeforeUnmount(() => {
  stopActiveJobsPolling()
  document.removeEventListener('visibilitychange', onVisibilityChange)
})

const refreshAll = () => {
  fetchSites()
  fetchActiveJobs()
}

const siteStateLower = (site) => String(site?.actual_state ?? '').toLowerCase()

const formatTime = (iso) => {
  if (!iso) return '-'
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}

// ─────────────────────────────────────────────────────────
// Column formatters - mirror the legacy SitesView so the two
// views render the same data identically until the legacy view
// is retired.
// ─────────────────────────────────────────────────────────

const formatSize = (bytes) => {
  const n = Number(bytes)
  if (!Number.isFinite(n) || n <= 0) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = n
  let i = 0
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${v.toFixed(1)} ${units[i]}`
}

const phpDisplay = (site) => {
  const raw = String(site?.php_version ?? '').trim()
  if (!raw) return null
  // Schema stores "lsphp83" / "8.3" / "lsphp83 / 8.3" depending on era.
  const m = raw.match(/(\d)\.?(\d)/)
  return m ? `PHP ${m[1]}.${m[2]}` : raw
}

const docRoot = (site) =>
  site?.document_root || `/home/${site?.domain ?? ''}/public_html`

const sslExpiryHint = (site) => {
  if (!site?.ssl_expires_at) return null
  try {
    const d = new Date(site.ssl_expires_at)
    return d.toLocaleDateString()
  } catch {
    return site.ssl_expires_at
  }
}

const isActuallyDeleted = (site) =>
  ['deleting', 'archived', 'absent'].includes(siteStateLower(site))

// A tombstone is a row whose saga-driven deletion completed. Only
// tombstones are eligible for the hard-purge button. `deleting` and
// `archived` rows are NOT tombstones - they are still managed by the
// saga / restore pipeline.
const isTombstone = (site) => siteStateLower(site) === 'absent'

// Settings/database routes live in the legacy view today. The plan
// rewires them when the edit saga lands; for now we deep-link.
// V2 manage view replaces the legacy /sites/<domain> page.
// /sites-v2/<domain>/manage?tab=overview is the canonical row-action
// target now; the legacy /sites route + SiteDetailView.vue are
// scheduled for deletion in Phase 5 of the consolidation plan.
const manageHref = (site) => ({
  name: 'site-manage-v2',
  params: { domain: site.domain },
})
const manageDatabasesHref = (site) => ({
  name: 'site-manage-v2',
  params: { domain: site.domain },
  query: { tab: 'databases' },
})
</script>

<template>
  <div class="px-4 py-4 space-y-6">
    <!-- ───── Header ─────
         Legacy-skin: large branded title with rounded icon tile,
         subtitle, and a right-aligned action group. Mirrors the
         look operators were used to in SitesView.vue. -->
    <div class="page-header">
      <div class="flex items-center gap-3 min-w-0">
        <div
          class="w-10 h-10 rounded-2xl flex items-center justify-center
                 bg-primary-100 dark:bg-primary-500/20
                 text-primary-600 dark:text-primary-400 shrink-0"
        >
          <span class="material-symbols-rounded">language</span>
        </div>
        <div class="min-w-0">
          <h1 class="page-title">Sites</h1>
          <p class="text-sm text-surface-500 dark:text-surface-400">
            Queue-backed provisioning with live job progress and lifecycle controls.
          </p>
        </div>
      </div>
      <div class="action-buttons">
        <button class="btn-secondary" :disabled="loading" @click="refreshAll">
          <span class="material-symbols-rounded text-sm">refresh</span>
          Refresh
        </button>
        <button class="btn-primary" @click="createModal = true">
          <span class="material-symbols-rounded text-sm">add</span>
          Provision site
        </button>
      </div>
    </div>

    <!-- ───── Summary cards (parity with legacy SitesView) ─────
         Uses the project's .stat-card / .stat-value tokens so the
         dark-mode background lands on rgb(var(--color-surface-elevated))
         instead of slate-900. Result: cards feel elevated against the
         page background, exactly like the legacy view. -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
      <div class="stat-card">
        <p class="text-surface-500 text-xs sm:text-sm">Total Sites</p>
        <p class="stat-value">{{ summaryStats.total }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-xs sm:text-sm">With SSL</p>
        <p class="stat-value text-green-600 dark:text-green-400">
          {{ summaryStats.withSsl }}
        </p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-xs sm:text-sm">Without SSL</p>
        <p class="stat-value text-amber-600 dark:text-amber-400">
          {{ summaryStats.withoutSsl }}
        </p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-xs sm:text-sm">Total Size</p>
        <p class="stat-value">{{ formatTotalSize(summaryStats.totalSize) }}</p>
      </div>
    </div>

    <!-- ───── Needs-attention banner ─────
         Surfaces tombstones (absent) and parked failures across the
         whole dataset so leftovers can't rot invisibly. Each chip
         applies the matching state filter. -->
    <div
      v-if="attentionTotal > 0"
      class="card p-3 flex flex-wrap items-center gap-3 border-amber-300/60
             dark:border-amber-500/30 bg-amber-50/60 dark:bg-amber-500/5"
    >
      <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">warning</span>
      <span class="text-sm text-surface-700 dark:text-surface-200">
        {{ attentionTotal }} site row(s) need review:
      </span>
      <button
        v-if="attention.absent > 0"
        class="btn-secondary btn-sm"
        @click="focusState('absent')"
      >
        {{ attention.absent }} deleted (tombstone) — review &amp; purge
      </button>
      <button
        v-if="attention.failed > 0"
        class="btn-secondary btn-sm"
        @click="focusState('failed')"
      >
        {{ attention.failed }} failed
      </button>
      <button
        v-if="attention.degraded > 0"
        class="btn-secondary btn-sm"
        @click="focusState('degraded')"
      >
        {{ attention.degraded }} degraded
      </button>
    </div>

    <!-- ───── Active jobs panel ─────
         Legacy-skin: card aesthetic, pulsing "Live" dot when work
         is in flight, capsule pills and richer empty state. The
         5-second polling logic is untouched. -->
    <div class="card overflow-hidden">
      <div class="card-header flex items-center justify-between">
        <div class="flex items-center gap-2 text-sm font-semibold">
          <span class="material-symbols-rounded text-base text-primary-500">bolt</span>
          Active jobs
          <span
            v-if="activeJobs.length > 0"
            class="pulse-dot bg-green-500 ml-1"
            aria-hidden="true"
          />
          <span
            v-if="activeJobsLoading"
            class="text-xs font-normal text-surface-400 ml-1"
          >refreshing…</span>
        </div>
        <span class="text-xs text-surface-500 dark:text-surface-400">
          {{ activeJobs.length }} in flight
        </span>
      </div>
      <div
        v-if="activeJobs.length === 0"
        class="px-6 py-8 text-sm text-surface-500 dark:text-surface-400
               flex items-center gap-3"
      >
        <span class="material-symbols-rounded text-2xl text-surface-300 dark:text-surface-600">
          done_all
        </span>
        <span>No queued or running jobs - everything is settled.</span>
      </div>
      <div
        v-else
        class="divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]"
      >
        <div
          v-for="job in activeJobs"
          :key="job.id"
          class="px-6 py-3 flex flex-wrap items-center justify-between gap-3
                 hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))]
                 transition-colors duration-100"
        >
          <div class="flex items-center gap-3 min-w-0">
            <span :class="jobStatusPillClass(job.status)">
              <span v-if="job.status === 'running'" class="spinner-sm" />
              {{ job.status }}
            </span>
            <code class="text-xs text-surface-500 dark:text-surface-400">#{{ job.id }}</code>
            <span class="font-semibold uppercase text-xs tracking-wide">{{ job.type }}</span>
            <span class="truncate text-sm">{{ job.site_domain }}</span>
            <span
              v-if="job.current_step"
              class="text-xs text-surface-500 dark:text-surface-400 truncate"
            >
              → {{ job.current_step }}
            </span>
            <span class="text-xs text-surface-500 dark:text-surface-400">
              attempt {{ job.attempts }}/{{ job.max_attempts }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <button
              class="btn-secondary btn-sm"
              @click="openProgressModal(job.id, job.type, job.site_domain)"
            >
              <span class="material-symbols-rounded text-xs">visibility</span>
              Open
            </button>
            <button
              v-if="job.terminal"
              class="btn-secondary btn-sm"
              @click="retryJobAction(job)"
            >
              <span class="material-symbols-rounded text-xs">refresh</span>
              Retry
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── Filters ───── -->
    <div class="card p-3 flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[200px]">
        <span
          class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2
                 text-base text-surface-400"
        >search</span>
        <input
          v-model="filters.search"
          type="text"
          class="input pl-10"
          placeholder="Search domain…"
        />
      </div>
      <select v-model="filters.actual_state" class="input">
        <option value="">All live states</option>
        <option value="active">active</option>
        <option value="provisioning">provisioning</option>
        <option value="pending_dns">SSL pending (pending_dns)</option>
        <option value="suspended">suspended</option>
        <option value="archived">archived</option>
        <option value="degraded">degraded</option>
        <option value="failed">failed</option>
        <option value="deleting">deleting</option>
        <option value="absent">deleted (absent)</option>
      </select>
      <span class="text-xs text-surface-500 dark:text-surface-400 ml-auto">
        {{ pagination.total }} total
      </span>
    </div>

    <!-- ───── Sites table ─────
         Legacy-skin: card aesthetic with skeleton placeholder
         rows during initial load and a richer empty state. Hover
         transitions are softer (100ms) to feel responsive without
         flashing. -->
    <div class="card overflow-hidden">
      <div class="table-responsive">
      <table class="table min-w-full">
        <thead>
          <tr>
            <th class="text-left">Domain</th>
            <th class="text-left hidden lg:table-cell">Document root</th>
            <th class="text-left">PHP</th>
            <th class="text-left hidden sm:table-cell">Size</th>
            <th class="text-left">SSL</th>
            <th class="text-left hidden md:table-cell">Template</th>
            <th class="text-left">Status</th>
            <th class="text-left hidden md:table-cell">Updated</th>
            <th class="text-right w-1">Actions</th>
          </tr>
        </thead>
        <tbody>
          <template v-if="loading && sites.length === 0">
            <tr v-for="n in 5" :key="`skel-${n}`">
              <td><div class="skeleton h-5 w-40 rounded" /></td>
              <td class="hidden lg:table-cell"><div class="skeleton h-4 w-56 rounded" /></td>
              <td><div class="skeleton h-5 w-12 rounded-full" /></td>
              <td class="hidden sm:table-cell"><div class="skeleton h-4 w-16 rounded" /></td>
              <td><div class="skeleton h-4 w-10 rounded" /></td>
              <td class="hidden md:table-cell"><div class="skeleton h-5 w-20 rounded-full" /></td>
              <td><div class="skeleton h-5 w-20 rounded-full" /></td>
              <td class="hidden md:table-cell"><div class="skeleton h-4 w-24 rounded" /></td>
              <td><div class="skeleton h-6 w-20 rounded ml-auto" /></td>
            </tr>
          </template>
          <tr v-else-if="sites.length === 0">
            <td colspan="9" class="text-center py-12">
              <div class="flex flex-col items-center gap-3 text-surface-500 dark:text-surface-400">
                <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">
                  travel_explore
                </span>
                <div class="text-sm">No sites match the current filters.</div>
                <button class="btn-primary btn-sm" @click="createModal = true">
                  <span class="material-symbols-rounded text-sm">add</span>
                  Provision your first site
                </button>
              </div>
            </td>
          </tr>
          <tr v-for="site in sites" :key="site.id">
            <td>
              <div class="flex items-center gap-3 min-w-0">
                <!-- Domain icon tile: rounded primary-tinted square +
                     a small green dot when a template is applied.
                     Mirrors the legacy SitesView row affordance so
                     scanning a long list is fast. -->
                <div class="relative shrink-0">
                  <div
                    class="w-9 h-9 rounded-xl flex items-center justify-center
                           bg-primary-100 dark:bg-primary-500/20
                           text-primary-600 dark:text-primary-400"
                  >
                    <span class="material-symbols-rounded text-lg">language</span>
                  </div>
                  <span
                    v-if="site.has_template_backup"
                    class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full
                           bg-emerald-500 border-2 border-white dark:border-[rgb(var(--color-surface))]"
                    title="Template applied"
                  />
                </div>
                <router-link
                  :to="`/sites-v2/${site.domain}/manage`"
                  class="font-medium hover:text-primary-500 truncate transition-colors duration-100"
                  :title="site.domain"
                >
                  {{ site.domain }}
                </router-link>
              </div>
            </td>
            <td class="hidden lg:table-cell">
              <span
                class="text-xs text-surface-500 dark:text-surface-400 font-mono truncate block max-w-[260px]"
                :title="docRoot(site)"
              >
                {{ docRoot(site) }}
              </span>
            </td>
            <td>
              <span
                v-if="phpDisplay(site)"
                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                       bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300"
              >
                {{ phpDisplay(site) }}
              </span>
              <span v-else class="text-surface-400">—</span>
            </td>
            <td class="hidden sm:table-cell text-surface-600 dark:text-surface-300">
              {{ formatSize(site.size_bytes) }}
            </td>
            <td>
              <span
                v-if="site.ssl_enabled"
                class="inline-flex items-center gap-1 text-green-600 dark:text-green-400"
                :title="sslExpiryHint(site) ? `Expires ${sslExpiryHint(site)}` : 'TLS certificate present'"
              >
                <span class="material-symbols-rounded text-base">lock</span>
                <span class="text-xs hidden sm:inline">Secure</span>
              </span>
              <span
                v-else-if="siteStateLower(site) === 'pending_dns'"
                class="inline-flex items-center gap-1 text-yellow-600 dark:text-yellow-400"
                title="SSL deferred: waiting for DNS propagation. Reconciler retries every 5 minutes."
              >
                <span class="material-symbols-rounded text-base animate-pulse">hourglass_top</span>
                <span class="text-xs hidden sm:inline">Pending</span>
              </span>
              <span v-else class="inline-flex items-center gap-1 text-surface-400">
                <span class="material-symbols-rounded text-base">lock_open</span>
              </span>
            </td>
            <!-- Template badge + inline revert. has_template_backup is
                 derived server-side from a JOIN on template_deployments;
                 see ProvisioningAction::serialiseSiteRow. -->
            <td class="hidden md:table-cell">
              <div v-if="site.has_template_backup" class="flex items-center gap-1">
                <span :class="templateTypeBadgeClass(site.template_type)">
                  {{ templateTypeLabel(site.template_type) }}
                </span>
                <button
                  class="btn-ghost btn-sm text-emerald-500 hover:bg-emerald-50 dark:hover:bg-emerald-500/10"
                  title="Revert to original index.html"
                  :disabled="inlineRevertingDomain === site.domain"
                  @click="inlineRevertTemplate(site)"
                >
                  <span
                    v-if="inlineRevertingDomain === site.domain"
                    class="spinner-sm"
                  />
                  <span v-else class="material-symbols-rounded text-sm">restore</span>
                </button>
              </div>
              <span v-else class="text-surface-400 text-sm">—</span>
            </td>
            <td>
              <span
                :class="statePillClass(site.actual_state)"
                :title="siteStateLower(site) === 'pending_dns'
                  ? 'SSL deferred: waiting for DNS propagation. Reconciler retries every 5 minutes.'
                  : site.actual_state"
              >
                <span
                  v-if="['provisioning','deleting','pending_dns'].includes(siteStateLower(site))"
                  class="pulse-dot bg-current opacity-70"
                  aria-hidden="true"
                />
                {{ stateDisplay(site.actual_state) }}
              </span>
              <span
                v-if="site.desired_state && site.desired_state !== 'active'"
                class="ml-1 text-xs text-surface-400"
                :title="`Desired state: ${site.desired_state}`"
              >
                → {{ site.desired_state }}
              </span>
            </td>
            <td class="hidden md:table-cell text-xs text-surface-500 dark:text-surface-400">
              {{ formatTime(site.updated_at) }}
            </td>
            <td class="px-3 py-2 text-right">
              <div class="inline-flex items-center gap-0.5 sm:gap-1 justify-end">
                <!-- Settings: opens the V2-native per-site management
                     view at /sites-v2/<domain>/manage. -->
                <router-link
                  :to="manageHref(site)"
                  class="btn-ghost btn-sm text-primary-500"
                  title="Manage site"
                >
                  <span class="material-symbols-rounded text-sm">settings</span>
                </router-link>
                <!-- Database: deep-links to the Databases tab inside
                     the V2 manage view. -->
                <router-link
                  v-if="site.db_name"
                  :to="manageDatabasesHref(site)"
                  class="btn-ghost btn-sm text-amber-500 hidden sm:inline-flex"
                  :title="`Manage database: ${site.db_name}`"
                >
                  <span class="material-symbols-rounded text-sm">database</span>
                </router-link>
                <a
                  :href="`https://${site.domain}`"
                  target="_blank"
                  rel="noopener"
                  class="btn-ghost btn-sm hidden sm:inline-flex"
                  title="Open site in new tab"
                >
                  <span class="material-symbols-rounded text-sm">open_in_new</span>
                </a>
                <!-- Apply / manage template (parity with legacy SitesView).
                     Hidden for tombstones - no point templating a site
                     whose files have been torn down. -->
                <button
                  v-if="!isTombstone(site)"
                  :class="site.has_template_backup
                    ? 'btn-ghost btn-sm text-emerald-500 hover:bg-emerald-50 dark:hover:bg-emerald-500/10 hidden sm:inline-flex'
                    : 'btn-ghost btn-sm text-purple-500 hover:bg-purple-50 dark:hover:bg-purple-500/10 hidden sm:inline-flex'"
                  :title="site.has_template_backup
                    ? 'Template applied - click to manage / change'
                    : 'Apply a template (placeholder / coming soon / maintenance)'"
                  @click="openTemplateModal(site)"
                >
                  <span class="material-symbols-rounded text-sm">
                    {{ site.has_template_backup ? 'check_circle' : 'web' }}
                  </span>
                </button>
                <SiteLifecycleMenu
                  :site="site"
                  @job-enqueued="onJobEnqueued"
                  @error="onLifecycleError"
                  @refresh="refreshAll"
                />
                <!-- Live site: enqueue DELETE saga. -->
                <button
                  v-if="!isTombstone(site)"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  :disabled="isActuallyDeleted(site)"
                  :title="isActuallyDeleted(site)
                    ? 'Site is already in a non-active terminal state (deleting/archived). Wait for it to settle into absent before purging.'
                    : 'Enqueue DELETE job (snapshot, then teardown)'"
                  @click="beginDelete(site)"
                >
                  <span class="material-symbols-rounded text-sm">delete</span>
                </button>
                <!-- Tombstone (actual_state=absent): hard-purge. Removes
                     the DB row, every history table for this domain,
                     and the on-disk snapshot tree. Irreversible. -->
                <button
                  v-else
                  class="btn-ghost btn-sm text-red-700 hover:bg-red-100 dark:hover:bg-red-700/20"
                  title="Hard-purge tombstone (DB + history + snapshots). Irreversible."
                  @click="beginPurge(site)"
                >
                  <span class="material-symbols-rounded text-sm">delete_forever</span>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <!-- ───── Job progress modal ───── -->
    <JobProgressModal
      v-if="progressModal.show"
      :show="progressModal.show"
      :job-id="progressModal.jobId"
      :title="progressModal.title"
      @close="onProgressClose"
    />

    <!-- ───── Create site modal ───── -->
    <CreateSiteV2Modal
      v-if="createModal"
      :show="createModal"
      @close="createModal = false"
      @created="onCreated"
    />

    <!-- ───── Delete confirmation ───── -->
    <Modal
      :show="deleteState.show"
      :title="`Delete ${deleteState.site?.domain ?? ''}`"
      size="md"
      @close="deleteState.show = false"
    >
      <p class="mb-3 text-sm text-surface-600 dark:text-surface-300">
        This enqueues a DELETE saga. A pre-delete snapshot is taken by
        default so the site can be restored from the archive store if
        needed. The vhost, home directory, database, and SFTP user are
        torn down once the snapshot is on disk.
      </p>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="deleteState.skipSnapshot" type="checkbox" />
        Skip snapshot (destructive, cannot be restored)
      </label>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="deleteState.busy"
          @click="deleteState.show = false"
        >
          Cancel
        </button>
        <button
          class="btn-danger"
          :disabled="deleteState.busy"
          @click="confirmDelete"
        >
          <span v-if="deleteState.busy" class="spinner" />
          Delete
        </button>
      </template>
    </Modal>

    <!-- ───── Tombstone hard-purge confirmation ───── -->
    <Modal
      :show="purgeState.show"
      :title="`Hard-purge ${purgeState.site?.domain ?? ''}`"
      size="md"
      @close="purgeState.show = false"
    >
      <div
        class="mb-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-700 dark:bg-red-900/30 dark:text-red-200"
      >
        <div class="flex items-start gap-2">
          <span class="material-symbols-rounded text-base mt-0.5">warning</span>
          <div>
            <div class="font-medium">This is irreversible.</div>
            <div class="text-xs mt-1">
              The site row, every history record for this domain
              (audit, jobs, events, step executions), and the on-disk
              snapshot tree will be permanently removed. After purge
              you cannot restore this site from its existing
              snapshots and the audit trail is gone.
            </div>
          </div>
        </div>
      </div>

      <div v-if="purgeState.previewing" class="text-sm text-surface-500 mb-3">
        <span class="spinner inline-block align-middle mr-2" />
        Calculating what will be removed…
      </div>

      <div
        v-else-if="purgeState.preview"
        class="mb-3 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-3 text-sm"
      >
        <div class="font-medium mb-2">Will be removed:</div>
        <ul class="space-y-1 text-surface-700 dark:text-surface-300">
          <li
            v-for="(count, table) in purgeState.preview.rows_to_delete"
            :key="table"
            class="flex justify-between"
          >
            <span class="font-mono text-xs">{{ table }}</span>
            <span class="tabular-nums">{{ count }} row{{ count === 1 ? '' : 's' }}</span>
          </li>
          <li class="flex justify-between border-t border-surface-200 dark:border-[rgb(var(--color-border))] pt-1 mt-1">
            <span class="font-mono text-xs truncate" :title="purgeState.preview.snapshot_dir">
              {{ purgeState.preview.snapshot_dir }}
            </span>
            <span class="tabular-nums">
              {{ purgeState.preview.snapshot_present ? 'present' : 'absent' }}
            </span>
          </li>
        </ul>
      </div>

      <label class="block text-sm text-surface-600 dark:text-surface-300 mb-1">
        Type the domain
        <span class="font-mono font-medium">{{ purgeState.site?.domain }}</span>
        to confirm:
      </label>
      <input
        v-model="purgeState.typedDomain"
        type="text"
        class="input w-full"
        :placeholder="purgeState.site?.domain"
        autocomplete="off"
        @keyup.enter="purgeConfirmed && confirmPurge()"
      />

      <template #footer>
        <button
          class="btn-secondary"
          :disabled="purgeState.busy"
          @click="purgeState.show = false"
        >
          Cancel
        </button>
        <button
          class="btn-danger"
          :disabled="purgeState.busy || !purgeConfirmed"
          @click="confirmPurge"
        >
          <span v-if="purgeState.busy" class="spinner" />
          Purge permanently
        </button>
      </template>
    </Modal>

    <!-- ───── Apply Template modal ───── -->
    <Modal
      :show="templateModal.show"
      :title="`Apply template — ${templateModal.site?.domain ?? ''}`"
      @close="templateModal.show = false"
    >
      <div class="space-y-4">
        <p class="text-sm text-surface-500 dark:text-surface-400">
          Apply a site template to
          <strong>{{ templateModal.site?.domain }}</strong>. This replaces the
          <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-xs">index.html</code>
          file at the document root. The current file is backed up first so
          you can restore it later.
        </p>

        <!-- Catalogue loading -->
        <div
          v-if="templatesLoading"
          class="flex items-center justify-center py-8 text-surface-500"
        >
          <span class="spinner" />
          <span class="ml-3">Loading templates…</span>
        </div>

        <!-- Template options -->
        <div v-else-if="availableTemplates.length" class="space-y-2">
          <label class="text-sm font-medium">Select template</label>
          <div class="space-y-2">
            <label
              v-for="tmpl in availableTemplates"
              :key="tmpl.id"
              class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition-all"
              :class="templateModal.selectedId === tmpl.id
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-[rgb(var(--color-border))] hover:border-primary-300'"
            >
              <input
                v-model="templateModal.selectedId"
                type="radio"
                :value="tmpl.id"
                class="sr-only"
              />
              <span
                class="material-symbols-rounded text-xl"
                :class="templateModal.selectedId === tmpl.id ? 'text-primary-500' : 'text-surface-400'"
              >
                {{
                  tmpl.id === 'site_placeholder' ? 'rocket_launch'
                  : tmpl.id === 'site_coming_soon' ? 'schedule'
                  : tmpl.id === 'site_maintenance' ? 'construction'
                  : 'web'
                }}
              </span>
              <div class="min-w-0">
                <p class="font-medium text-sm">{{ tmpl.name || tmpl.id }}</p>
                <p
                  v-if="tmpl.description"
                  class="text-xs text-surface-500 dark:text-surface-400 truncate"
                >
                  {{ tmpl.description }}
                </p>
              </div>
            </label>
          </div>
        </div>
        <div v-else class="text-sm text-surface-500 italic">
          No site templates available. Add some to
          <code class="text-xs">/var/www/vps-admin/templates/</code> first.
        </div>

        <!-- Already-applied banner with restore action -->
        <div
          v-if="templateModal.site?.has_template_backup"
          class="p-4 bg-emerald-50 dark:bg-emerald-500/10 border-2 border-emerald-300 dark:border-emerald-500/30 rounded-xl"
        >
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-emerald-500 text-xl">check_circle</span>
            <div class="flex-1">
              <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300 mb-1">
                Template already applied
              </p>
              <p class="text-xs text-emerald-600 dark:text-emerald-400 mb-3">
                Current type:
                <strong>{{ templateTypeLabel(templateModal.site?.template_type) }}</strong>
              </p>
              <button
                class="btn-sm rounded-full bg-emerald-500 hover:bg-emerald-600 text-white flex items-center gap-1"
                :disabled="templateModal.reverting"
                @click="revertTemplateFromModal"
              >
                <span v-if="templateModal.reverting" class="spinner" />
                <span v-else class="material-symbols-rounded text-sm">restore</span>
                Restore original
              </button>
            </div>
          </div>
        </div>

        <!-- Warning -->
        <div
          class="p-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl"
        >
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-amber-500 text-lg">warning</span>
            <p class="text-sm text-amber-700 dark:text-amber-300">
              This overwrites the existing
              <code class="text-xs">index.html</code>. A timestamped backup is
              created automatically.
            </p>
          </div>
        </div>
      </div>

      <template #footer>
        <button class="btn-secondary" @click="templateModal.show = false">
          Cancel
        </button>
        <button
          class="btn-primary"
          :disabled="!templateModal.selectedId || templateModal.applying"
          @click="applyTemplate"
        >
          <span v-if="templateModal.applying" class="spinner" />
          <span v-else class="material-symbols-rounded text-sm">web</span>
          Apply template
        </button>
      </template>
    </Modal>
  </div>
</template>

