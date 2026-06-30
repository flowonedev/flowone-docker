<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'
import StorageInfraCard from './StorageInfraCard.vue'
import StorageConfirmModal from './StorageConfirmModal.vue'
import { useStorageActions } from './useStorageActions.js'

/**
 * Admin-only dashboard surfacing the full Phase 6c/6d/7 state plus
 * the operator control plane (pause/resume/snapshot/verify/drill/
 * freeze). All destructive or queued actions go through the shared
 * confirm modal; results show as a transient toast banner.
 */
const { t } = useI18n()

const loading = ref(false)
const error = ref(null)
const payload = ref(null)
const lastLoadedAt = ref(0)

const { runAction, busy: actionBusy } = useStorageActions()
const toast = ref(null)
let toastTimer = null

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/admin/storage/dashboard')
    payload.value = data?.data ?? data
    lastLoadedAt.value = Date.now()
  } catch (e) {
    error.value = e?.response?.data?.error || e?.message || 'load failed'
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ───── Action modal state ─────
const confirmShow = ref(false)
const pendingAction = ref(null)

function askConfirm(action, copy) {
  pendingAction.value = { action, ...copy }
  confirmShow.value = true
}

async function onConfirmed({ reason }) {
  if (!pendingAction.value) return
  const { action } = pendingAction.value
  const result = await runAction(action, { reason: reason || undefined })
  confirmShow.value = false
  showToast(result)
  if (result.ok) {
    // refresh dashboard so the new pause/freeze state shows up
    await load()
  }
}

function showToast(result) {
  if (toastTimer) clearTimeout(toastTimer)
  toast.value = result
  toastTimer = setTimeout(() => { toast.value = null }, 6000)
}

// ───── Button click handlers (declarative copy → confirm modal) ─────
const reclaimPaused = computed(() => !!reclaim.value?.paused)
const backupPaused  = computed(() => !!backup.value?.paused)
const reclaimEnabled = computed(() => !!reclaim.value?.enabled)
const backupEnabled  = computed(() => !!backup.value?.enabled)

function onReclaimPauseClick() {
  askConfirm('reclaim_pause', {
    title: t('storage.actions.reclaimPause.title', 'Pause reclaim daemon'),
    description: t('storage.actions.reclaimPause.desc', 'The daemon will keep polling but skip every reclaim cycle until resumed. Useful during NAS maintenance.'),
    confirmLabel: t('storage.actions.reclaimPause.confirm', 'Pause daemon'),
    variant: 'warning',
  })
}
function onReclaimResumeClick() {
  askConfirm('reclaim_resume', {
    title: t('storage.actions.reclaimResume.title', 'Resume reclaim daemon'),
    description: t('storage.actions.reclaimResume.desc', 'Removes the pause flag. Daemon resumes reclaim cycles on the next poll tick.'),
    confirmLabel: t('storage.actions.reclaimResume.confirm', 'Resume daemon'),
    variant: 'primary',
  })
}
function onReclaimCycleClick() {
  askConfirm('reclaim_cycle', {
    title: t('storage.actions.reclaimCycle.title', 'Force a reclaim cycle now'),
    description: t('storage.actions.reclaimCycle.desc', 'Queues a single ad-hoc reclaim cycle. The dispatcher will pick it up within ~60s and run it as the daemon user.'),
    confirmLabel: t('storage.actions.reclaimCycle.confirm', 'Queue cycle'),
    variant: 'warning',
  })
}
function onBackupPauseClick() {
  askConfirm('backup_pause', {
    title: t('storage.actions.backupPause.title', 'Pause backup pipeline'),
    description: t('storage.actions.backupPause.desc', 'Cron will skip snapshot + retain runs until resumed. Verify and drill still work.'),
    confirmLabel: t('storage.actions.backupPause.confirm', 'Pause backups'),
    variant: 'warning',
  })
}
function onBackupResumeClick() {
  askConfirm('backup_resume', {
    title: t('storage.actions.backupResume.title', 'Resume backup pipeline'),
    description: t('storage.actions.backupResume.desc', 'Removes the pause flag. Next cron tick will create a snapshot if due.'),
    confirmLabel: t('storage.actions.backupResume.confirm', 'Resume backups'),
    variant: 'primary',
  })
}
function onSnapshotClick() {
  askConfirm('backup_snapshot', {
    title: t('storage.actions.snapshot.title', 'Run snapshot now'),
    description: t('storage.actions.snapshot.desc', 'Queues an ad-hoc rsync snapshot of /mnt/nas-drive → /mnt/vps-backup. Can take hours. Safe to run alongside the scheduled daily snapshot — rsync handles incremental.'),
    confirmLabel: t('storage.actions.snapshot.confirm', 'Queue snapshot'),
    variant: 'primary',
  })
}
function onVerifyClick() {
  askConfirm('backup_verify', {
    title: t('storage.actions.verify.title', 'Verify last snapshot'),
    description: t('storage.actions.verify.desc', 'Spot-checks 50 random files from the most recent snapshot manifest against the HMAC signature. Read-only.'),
    confirmLabel: t('storage.actions.verify.confirm', 'Queue verify'),
    variant: 'primary',
  })
}
function onDrillClick() {
  askConfirm('backup_drill', {
    title: t('storage.actions.drill.title', 'Run restore drill'),
    description: t('storage.actions.drill.desc', 'Picks one random file from a recent snapshot, restores it to /tmp, verifies md5, deletes. Proves end-to-end restorability.'),
    confirmLabel: t('storage.actions.drill.confirm', 'Queue drill'),
    variant: 'primary',
  })
}
function onFreezeClick() {
  askConfirm('freeze', {
    title: t('storage.actions.freeze.title', 'FREEZE all storage subsystems'),
    description: t('storage.actions.freeze.desc', 'Emergency stop. Every NAS-touching subsystem (reclaim daemon, backup pipeline, tier-down worker, drive recall) will refuse to write until you unfreeze. Reads remain allowed. Use this when something is going wrong.'),
    confirmLabel: t('storage.actions.freeze.confirm', 'Freeze everything'),
    variant: 'danger',
    requireTypedConfirm: 'FREEZE',
  })
}
function onUnfreezeClick() {
  askConfirm('unfreeze', {
    title: t('storage.actions.unfreeze.title', 'Unfreeze storage'),
    description: t('storage.actions.unfreeze.desc', 'Lifts the global freeze. Subsystems resume normal behaviour at their next poll/cron tick.'),
    confirmLabel: t('storage.actions.unfreeze.confirm', 'Unfreeze'),
    variant: 'warning',
  })
}

const frozen = computed(() => Boolean(payload.value?.frozen))

const budget = computed(() => payload.value?.budget)
const reclaim = computed(() => payload.value?.reclaim)
const backup = computed(() => payload.value?.backup)
const tierCounts = computed(() => payload.value?.tier_counts ?? {})
const phaseFlags = computed(() => payload.value?.phase_flags ?? {})
const paths = computed(() => payload.value?.paths ?? {})

function fmtBytes(n) {
  const b = Number(n)
  if (!Number.isFinite(b) || b <= 0) return '0 B'
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
  const exp = Math.min(Math.floor(Math.log(b) / Math.log(1024)), units.length - 1)
  return `${(b / Math.pow(1024, exp)).toFixed(2)} ${units[exp]}`
}
function fmtPct(n) {
  if (typeof n !== 'number') return '—'
  return `${n.toFixed(1)}%`
}
function fmtTs(unix) {
  if (!unix) return '—'
  return new Date(Number(unix) * 1000).toLocaleString()
}
function ago(unix) {
  if (!unix) return '—'
  const sec = Math.floor(Date.now() / 1000) - Number(unix)
  if (sec < 60) return `${sec}s`
  if (sec < 3600) return `${Math.floor(sec / 60)}m`
  if (sec < 86400) return `${Math.floor(sec / 3600)}h`
  return `${Math.floor(sec / 86400)}d`
}

const watermarkBadgeClass = computed(() => {
  const w = budget.value?.watermark
  if (w === 'critical') return 'bg-red-600 text-white'
  if (w === 'high')     return 'bg-amber-600 text-white animate-pulse'
  if (w === 'warn')     return 'bg-amber-200 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200'
  return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
})

const reclaimStateClass = computed(() => {
  const s = reclaim.value?.state
  if (s === 'reclaiming') return 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200'
  if (s === 'warming')    return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
  if (s === 'cooldown')   return 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/40 dark:text-cyan-200'
  if (s === 'paused')     return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
  return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-surface-900 dark:text-surface-100">
          {{ t('storage.admin.title', 'Storage Dashboard') }}
        </h1>
        <p class="text-sm text-surface-500 dark:text-surface-400">
          {{ t('storage.admin.subtitle', 'Tiered storage + reclaim daemon + backup pipeline.') }}
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg bg-red-600 hover:bg-red-700 text-white"
          :disabled="actionBusy"
          :title="t('storage.actions.freeze.tooltip', 'Emergency stop — halts every NAS write across all subsystems')"
          @click="onFreezeClick"
        >
          <span class="material-symbols-rounded text-base">ac_unit</span>
          {{ t('storage.actions.freeze.short', 'Freeze') }}
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg bg-amber-500 hover:bg-amber-600 text-white"
          :disabled="actionBusy"
          @click="onUnfreezeClick"
        >
          <span class="material-symbols-rounded text-base">lock_open</span>
          {{ t('storage.actions.unfreeze.short', 'Unfreeze') }}
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600"
          :disabled="loading"
          @click="load"
        >
          <span class="material-symbols-rounded text-base" :class="{ 'animate-spin': loading }">refresh</span>
          {{ loading ? t('storage.admin.loading', 'Loading...') : t('storage.admin.refresh', 'Refresh') }}
        </button>
      </div>
    </header>

    <!-- Action toast -->
    <div
      v-if="toast"
      class="p-3 rounded-lg flex items-start gap-3"
      :class="toast.ok ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200 border border-emerald-200 dark:border-emerald-800'
                       : 'bg-red-50 text-red-900 dark:bg-red-900/30 dark:text-red-200 border border-red-200 dark:border-red-800'"
    >
      <span class="material-symbols-rounded text-base mt-0.5">{{ toast.ok ? 'check_circle' : 'error' }}</span>
      <div class="flex-1 text-sm">
        <p class="font-medium">{{ toast.ok ? toast.message : toast.error }}</p>
        <p v-if="toast.path" class="text-xs font-mono opacity-75 mt-1">{{ toast.path }}</p>
      </div>
      <button class="text-xs opacity-60 hover:opacity-100" @click="toast = null">
        <span class="material-symbols-rounded text-base">close</span>
      </button>
    </div>

    <div v-if="error" class="p-4 rounded-lg bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200">
      {{ error }}
    </div>

    <div v-else-if="payload && !payload.available" class="p-4 rounded-lg bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
      <p class="font-semibold">{{ t('storage.admin.unavailable', 'Storage library not available.') }}</p>
      <p class="text-sm opacity-75">{{ payload.reason }}</p>
    </div>

    <div v-else-if="payload" class="space-y-6">
      <!-- Infrastructure status (live, polls every 10s) -->
      <StorageInfraCard />

      <!-- Budget card -->
      <section class="p-5 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
        <header class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-surface-900 dark:text-surface-100">{{ t('storage.admin.budget', 'Storage Budget') }}</h2>
          <span class="px-2 py-1 rounded-full text-xs font-semibold uppercase" :class="watermarkBadgeClass">
            {{ budget?.watermark || '—' }}
          </span>
        </header>
        <div v-if="budget?.available" class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div class="space-y-1">
            <p class="text-surface-500 dark:text-surface-400">{{ t('storage.admin.vpsLayer', 'VPS layer') }}</p>
            <p class="text-surface-900 dark:text-surface-100 font-medium">
              {{ fmtBytes(budget.vps_used_bytes ?? budget.vps_total_bytes - budget.vps_free_bytes) }}
              / {{ fmtBytes(budget.vps_total_bytes) }}
              <span class="text-surface-500">({{ fmtPct(budget.vps_used_pct) }})</span>
            </p>
            <p class="text-xs text-surface-500">{{ t('storage.admin.free', 'Free') }}: {{ fmtBytes(budget.vps_free_bytes) }}</p>
          </div>
          <div class="space-y-1" v-if="budget.drive_quota_bytes">
            <p class="text-surface-500 dark:text-surface-400">{{ t('storage.admin.logicalLayer', 'Logical drive') }}</p>
            <p class="text-surface-900 dark:text-surface-100 font-medium">
              {{ fmtBytes(budget.drive_used_bytes) }} / {{ fmtBytes(budget.drive_quota_bytes) }}
              <span class="text-surface-500">({{ fmtPct(budget.drive_used_pct) }})</span>
            </p>
            <p class="text-xs text-surface-500">{{ budget.drive_hot_rows }} {{ t('storage.admin.hotRows', 'hot rows') }}</p>
          </div>
        </div>
        <ul v-if="budget?.reasons?.length" class="mt-3 text-xs text-surface-600 dark:text-surface-400 list-disc list-inside">
          <li v-for="r in budget.reasons" :key="r">{{ r }}</li>
        </ul>
        <p v-if="!budget?.available" class="text-sm text-surface-500">{{ budget?.reason || t('storage.admin.unavailable', 'unavailable') }}</p>
      </section>

      <!-- Reclaim card -->
      <section class="p-5 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
        <header class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-surface-900 dark:text-surface-100">{{ t('storage.admin.reclaim', 'Reclaim Daemon') }}</h2>
          <div class="flex items-center gap-2">
            <span v-if="!reclaim?.enabled" class="px-2 py-1 rounded-full text-xs bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200">
              {{ t('storage.admin.killSwitchOff', 'kill switch OFF') }}
            </span>
            <span v-if="reclaim?.paused" class="px-2 py-1 rounded-full text-xs bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
              {{ t('storage.admin.paused', 'paused') }}
            </span>
            <span v-if="reclaim?.state" class="px-2 py-1 rounded-full text-xs font-semibold uppercase" :class="reclaimStateClass">
              {{ reclaim.state }}
            </span>
            <span v-if="reclaim && !reclaim.verified && reclaim.source !== 'absent'" class="px-2 py-1 rounded-full text-xs bg-surface-200 text-surface-600 dark:bg-surface-700 dark:text-surface-300" :title="t('storage.admin.unverifiedHint', 'HMAC key not readable by web user — data shown is unverified')">
              {{ t('storage.admin.unverified', 'unverified') }}
            </span>
          </div>
        </header>
        <div v-if="reclaim?.source === 'absent'" class="text-sm text-surface-500 italic">
          {{ t('storage.admin.noStatePublished', 'No state published yet. The daemon hasn\'t started (kill switch is off) or hasn\'t completed its first tick.') }}
        </div>
        <div v-else-if="reclaim?.source === 'unreadable'" class="text-sm text-red-600 dark:text-red-400">
          {{ t('storage.admin.stateUnreadable', 'State file exists but is not readable. Check filesystem permissions.') }}
        </div>
        <div v-else-if="reclaim" class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
          <p class="text-surface-500">{{ t('storage.admin.lastReason', 'Last decision') }}</p>
          <p class="font-mono text-xs text-surface-700 dark:text-surface-300">{{ reclaim.last_reason || '—' }}</p>
          <p class="text-surface-500">{{ t('storage.admin.lastReclaim', 'Last reclaim') }}</p>
          <p>{{ ago(reclaim.last_reclaim_at) }} {{ t('storage.admin.ago', 'ago') }} <span class="text-xs text-surface-500">({{ fmtTs(reclaim.last_reclaim_at) }})</span></p>
          <template v-if="reclaim.counters">
            <p class="text-surface-500">{{ t('storage.admin.cycles', 'Cycles since boot') }}</p>
            <p>{{ reclaim.counters.cycles ?? 0 }}</p>
            <p class="text-surface-500">{{ t('storage.admin.tieredDown', 'Files tier-down committed') }}</p>
            <p>{{ reclaim.counters.tier_tiered ?? 0 }} <span class="text-xs text-surface-500">({{ fmtBytes(reclaim.counters.bytes_total) }})</span></p>
            <p class="text-surface-500">{{ t('storage.admin.tierFailed', 'Failed') }}</p>
            <p>{{ reclaim.counters.tier_failed ?? 0 }}</p>
          </template>
          <p class="text-surface-500">{{ t('storage.admin.pid', 'PID') }}</p>
          <p class="font-mono text-xs">{{ reclaim.pid || '—' }}</p>
        </div>

        <!-- Reclaim action buttons -->
        <div class="mt-4 pt-3 border-t border-surface-200 dark:border-surface-700 flex flex-wrap items-center gap-2">
          <button
            v-if="!reclaimPaused"
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-amber-100 hover:bg-amber-200 text-amber-800 dark:bg-amber-900/30 dark:hover:bg-amber-900/50 dark:text-amber-200 disabled:opacity-50"
            :disabled="actionBusy"
            @click="onReclaimPauseClick"
          >
            <span class="material-symbols-rounded text-base">pause</span>
            {{ t('storage.actions.pause', 'Pause') }}
          </button>
          <button
            v-else
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-emerald-100 hover:bg-emerald-200 text-emerald-800 dark:bg-emerald-900/30 dark:hover:bg-emerald-900/50 dark:text-emerald-200 disabled:opacity-50"
            :disabled="actionBusy"
            @click="onReclaimResumeClick"
          >
            <span class="material-symbols-rounded text-base">play_arrow</span>
            {{ t('storage.actions.resume', 'Resume') }}
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-surface-100 hover:bg-surface-200 dark:bg-surface-700 dark:hover:bg-surface-600 disabled:opacity-50"
            :disabled="actionBusy || !reclaimEnabled"
            :title="!reclaimEnabled ? t('storage.actions.disabledKillSwitch', 'Kill switch is OFF — flip phase6c_reclaim_daemon on first') : ''"
            @click="onReclaimCycleClick"
          >
            <span class="material-symbols-rounded text-base">bolt</span>
            {{ t('storage.actions.runCycle', 'Run cycle now') }}
          </button>
        </div>
      </section>

      <!-- Backup card -->
      <section class="p-5 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
        <header class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-surface-900 dark:text-surface-100">{{ t('storage.admin.backup', 'Backup Pipeline') }}</h2>
          <div class="flex items-center gap-2">
            <span v-if="!backup?.enabled" class="px-2 py-1 rounded-full text-xs bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200">
              {{ t('storage.admin.killSwitchOff', 'kill switch OFF') }}
            </span>
            <span v-if="backup?.paused" class="px-2 py-1 rounded-full text-xs bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
              {{ t('storage.admin.paused', 'paused') }}
            </span>
            <span v-if="backup && !backup.verified && backup.source !== 'absent'" class="px-2 py-1 rounded-full text-xs bg-surface-200 text-surface-600 dark:bg-surface-700 dark:text-surface-300" :title="t('storage.admin.unverifiedHint', 'HMAC key not readable by web user — data shown is unverified')">
              {{ t('storage.admin.unverified', 'unverified') }}
            </span>
          </div>
        </header>
        <div v-if="backup?.source === 'absent'" class="text-sm text-surface-500 italic">
          {{ t('storage.admin.noStatePublished', 'No state published yet. The pipeline hasn\'t run.') }}
        </div>
        <div v-else-if="backup?.source === 'unreadable'" class="text-sm text-red-600 dark:text-red-400">
          {{ t('storage.admin.stateUnreadable', 'State file exists but is not readable. Check filesystem permissions.') }}
        </div>
        <div v-else-if="backup" class="space-y-4 text-sm">
          <div class="grid grid-cols-2 gap-x-6 gap-y-2">
            <p class="text-surface-500">{{ t('storage.admin.lastSnapshot', 'Last snapshot') }}</p>
            <p>{{ backup.state?.last_snapshot_ok?.date_key || '—' }}
               <span v-if="backup.state?.last_snapshot_ok?.files_total" class="text-xs text-surface-500">
                 · {{ backup.state.last_snapshot_ok.files_total }} files · {{ fmtBytes(backup.state.last_snapshot_ok.bytes_total) }}
               </span>
            </p>
            <p class="text-surface-500">{{ t('storage.admin.lastFailure', 'Last failure') }}</p>
            <p class="text-xs">{{ backup.state?.last_snapshot_failed?.reason || t('storage.admin.none', 'none') }}</p>
            <p class="text-surface-500">{{ t('storage.admin.lastVerify', 'Last verify') }}</p>
            <p>
              <span v-if="backup.state?.last_verify?.ok" class="text-emerald-600 dark:text-emerald-400">OK</span>
              <span v-else-if="backup.state?.last_verify" class="text-red-600 dark:text-red-400">FAIL</span>
              <span v-else>—</span>
              <span v-if="backup.state?.last_verify?.checked" class="text-xs text-surface-500">
                · {{ backup.state.last_verify.checked }} checked
              </span>
            </p>
            <p class="text-surface-500">{{ t('storage.admin.lastDrill', 'Last restore drill') }}</p>
            <p>
              <span v-if="backup.state?.last_drill?.ok" class="text-emerald-600 dark:text-emerald-400">OK</span>
              <span v-else-if="backup.state?.last_drill" class="text-red-600 dark:text-red-400">FAIL</span>
              <span v-else>—</span>
              <span v-if="backup.state?.last_drill?.file" class="text-xs text-surface-500 font-mono">
                · {{ backup.state.last_drill.file }}
              </span>
            </p>
          </div>
          <div v-if="backup.state?.retention" class="pt-3 border-t border-surface-200 dark:border-surface-700">
            <p class="text-xs uppercase text-surface-500 mb-2">{{ t('storage.admin.retention', 'Retention') }}</p>
            <div class="grid grid-cols-3 gap-2 text-sm">
              <div v-for="(meta, kind) in backup.state.retention" :key="kind" class="text-center p-2 rounded bg-surface-50 dark:bg-surface-700/50">
                <p class="text-xs uppercase text-surface-500">{{ kind }}</p>
                <p class="font-semibold text-surface-900 dark:text-surface-100">{{ meta.count }}</p>
                <p class="text-xs text-surface-500 truncate">{{ meta.oldest }} → {{ meta.newest }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Backup action buttons -->
        <div class="mt-4 pt-3 border-t border-surface-200 dark:border-surface-700 flex flex-wrap items-center gap-2">
          <button
            v-if="!backupPaused"
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-amber-100 hover:bg-amber-200 text-amber-800 dark:bg-amber-900/30 dark:hover:bg-amber-900/50 dark:text-amber-200 disabled:opacity-50"
            :disabled="actionBusy"
            @click="onBackupPauseClick"
          >
            <span class="material-symbols-rounded text-base">pause</span>
            {{ t('storage.actions.pause', 'Pause') }}
          </button>
          <button
            v-else
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-emerald-100 hover:bg-emerald-200 text-emerald-800 dark:bg-emerald-900/30 dark:hover:bg-emerald-900/50 dark:text-emerald-200 disabled:opacity-50"
            :disabled="actionBusy"
            @click="onBackupResumeClick"
          >
            <span class="material-symbols-rounded text-base">play_arrow</span>
            {{ t('storage.actions.resume', 'Resume') }}
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-primary-100 hover:bg-primary-200 text-primary-800 dark:bg-primary-900/30 dark:hover:bg-primary-900/50 dark:text-primary-200 disabled:opacity-50"
            :disabled="actionBusy || !backupEnabled"
            :title="!backupEnabled ? t('storage.actions.disabledKillSwitch', 'Kill switch is OFF — flip phase7_nas_backup on first') : ''"
            @click="onSnapshotClick"
          >
            <span class="material-symbols-rounded text-base">camera</span>
            {{ t('storage.actions.snapshot.short', 'Run snapshot') }}
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-surface-100 hover:bg-surface-200 dark:bg-surface-700 dark:hover:bg-surface-600 disabled:opacity-50"
            :disabled="actionBusy"
            @click="onVerifyClick"
          >
            <span class="material-symbols-rounded text-base">fact_check</span>
            {{ t('storage.actions.verify.short', 'Verify') }}
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md bg-surface-100 hover:bg-surface-200 dark:bg-surface-700 dark:hover:bg-surface-600 disabled:opacity-50"
            :disabled="actionBusy"
            @click="onDrillClick"
          >
            <span class="material-symbols-rounded text-base">science</span>
            {{ t('storage.actions.drill.short', 'Restore drill') }}
          </button>
        </div>
      </section>

      <!-- Tier counts card -->
      <section class="p-5 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
        <h2 class="font-semibold text-surface-900 dark:text-surface-100 mb-4">{{ t('storage.admin.tierCounts', 'Files by Tier State') }}</h2>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-sm">
          <div v-for="(meta, kind) in tierCounts" :key="kind" class="p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50 text-center">
            <p class="text-xs uppercase text-surface-500">{{ kind }}</p>
            <p class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ meta.count }}</p>
            <p class="text-xs text-surface-500">{{ fmtBytes(meta.bytes) }}</p>
          </div>
        </div>
      </section>

      <!-- Phase flags + paths -->
      <section class="p-5 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
        <h2 class="font-semibold text-surface-900 dark:text-surface-100 mb-4">{{ t('storage.admin.phaseFlags', 'Phase Flags') }}</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-sm">
          <div v-for="(on, k) in phaseFlags" :key="k" class="flex items-center gap-2 p-2 rounded bg-surface-50 dark:bg-surface-700/50">
            <span class="material-symbols-rounded text-sm" :class="on ? 'text-emerald-500' : 'text-surface-400'">
              {{ on ? 'check_circle' : 'radio_button_unchecked' }}
            </span>
            <span class="font-mono text-xs">{{ k }}</span>
          </div>
        </div>
        <details class="mt-4 text-xs text-surface-500">
          <summary class="cursor-pointer">{{ t('storage.admin.paths', 'State file paths') }}</summary>
          <ul class="mt-2 space-y-1 font-mono">
            <li v-for="(v, k) in paths" :key="k"><span class="opacity-60">{{ k }}:</span> {{ v }}</li>
          </ul>
        </details>
      </section>
    </div>

    <div v-else class="p-8 text-center text-surface-500">
      {{ t('storage.admin.loading', 'Loading...') }}
    </div>

    <StorageConfirmModal
      v-model="confirmShow"
      :title="pendingAction?.title"
      :description="pendingAction?.description"
      :confirm-label="pendingAction?.confirmLabel"
      :variant="pendingAction?.variant || 'primary'"
      :require-typed-confirm="pendingAction?.requireTypedConfirm || ''"
      :busy="actionBusy"
      @confirm="onConfirmed"
    />
  </div>
</template>
