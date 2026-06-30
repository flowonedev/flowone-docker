<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'

/**
 * Live infrastructure card for the storage admin dashboard.
 *
 * Surfaces:
 *   - NAS mount status + healthcheck file presence
 *   - Backup mount status + healthcheck file presence
 *   - VPN tunnel interface (tun0) operstate
 *   - DDNS hostname resolution
 *   - Pending request count in the dispatcher queue
 *
 * Polls every 10s — the backend probes are cheap (read /proc/mounts and
 * /sys/class/net, gethostbynamel for DDNS).
 */
const { t } = useI18n()

const data = ref(null)
const loading = ref(false)
const error = ref(null)
let pollHandle = null

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.get('/admin/storage/infra')
    data.value = res?.data?.data ?? res?.data
  } catch (e) {
    error.value = e?.response?.data?.error || e?.message || 'load failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  pollHandle = setInterval(load, 10_000)
})
onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle)
})

function fmtBytes(n) {
  const b = Number(n)
  if (!Number.isFinite(b) || b <= 0) return '—'
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
  const exp = Math.min(Math.floor(Math.log(b) / Math.log(1024)), units.length - 1)
  return `${(b / Math.pow(1024, exp)).toFixed(2)} ${units[exp]}`
}

const nas = computed(() => data.value?.nas)
const backupMount = computed(() => data.value?.backup_mount)
const vpn = computed(() => data.value?.vpn)
const ddns = computed(() => data.value?.ddns)
const requests = computed(() => data.value?.requests)

function statusBadge(ok, label) {
  return {
    label: label || (ok ? t('storage.infra.up', 'up') : t('storage.infra.down', 'down')),
    cls: ok
      ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
      : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
  }
}
</script>

<template>
  <section class="p-5 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
    <header class="flex items-center justify-between mb-4">
      <h2 class="font-semibold text-surface-900 dark:text-surface-100">
        {{ t('storage.infra.title', 'Infrastructure') }}
      </h2>
      <div class="flex items-center gap-2 text-xs text-surface-500">
        <span v-if="loading" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
        <span>{{ t('storage.infra.pollHint', 'auto-refresh 10s') }}</span>
      </div>
    </header>

    <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>

    <div v-else-if="data && !data.available" class="text-sm text-amber-700 dark:text-amber-300">
      {{ data.reason }}
    </div>

    <div v-else-if="data" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
      <!-- NAS mount -->
      <div class="p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50 space-y-1">
        <div class="flex items-center justify-between">
          <p class="font-medium text-surface-900 dark:text-surface-100">{{ t('storage.infra.nasMount', 'NAS mount') }}</p>
          <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadge(nas?.mounted).cls">
            {{ nas?.mounted ? t('storage.infra.mounted', 'mounted') : t('storage.infra.notMounted', 'not mounted') }}
          </span>
        </div>
        <p class="text-xs font-mono text-surface-500">{{ nas?.mount }}</p>
        <p v-if="nas?.mounted" class="text-xs text-surface-600 dark:text-surface-400">
          {{ fmtBytes(nas.free_bytes) }} {{ t('storage.infra.free', 'free') }} / {{ fmtBytes(nas.total_bytes) }}
          <span v-if="nas.used_pct !== null"> · {{ nas.used_pct }}%</span>
        </p>
        <p class="text-xs flex items-center gap-1" :class="nas?.healthcheck ? 'text-emerald-600' : 'text-amber-600'">
          <span class="material-symbols-rounded text-xs">{{ nas?.healthcheck ? 'verified' : 'warning' }}</span>
          {{ nas?.healthcheck ? t('storage.infra.hcOk', '.healthcheck present') : t('storage.infra.hcMissing', '.healthcheck missing') }}
        </p>
      </div>

      <!-- Backup mount -->
      <div class="p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50 space-y-1">
        <div class="flex items-center justify-between">
          <p class="font-medium text-surface-900 dark:text-surface-100">{{ t('storage.infra.backupMount', 'Backup mount') }}</p>
          <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadge(backupMount?.mounted).cls">
            {{ backupMount?.mounted ? t('storage.infra.mounted', 'mounted') : t('storage.infra.notMounted', 'not mounted') }}
          </span>
        </div>
        <p class="text-xs font-mono text-surface-500">{{ backupMount?.mount }}</p>
        <p v-if="backupMount?.mounted" class="text-xs text-surface-600 dark:text-surface-400">
          {{ fmtBytes(backupMount.free_bytes) }} {{ t('storage.infra.free', 'free') }} / {{ fmtBytes(backupMount.total_bytes) }}
          <span v-if="backupMount.used_pct !== null"> · {{ backupMount.used_pct }}%</span>
        </p>
        <p class="text-xs flex items-center gap-1" :class="backupMount?.healthcheck ? 'text-emerald-600' : 'text-amber-600'">
          <span class="material-symbols-rounded text-xs">{{ backupMount?.healthcheck ? 'verified' : 'warning' }}</span>
          {{ backupMount?.healthcheck ? t('storage.infra.hcOk', '.healthcheck present') : t('storage.infra.hcMissing', '.healthcheck missing') }}
        </p>
      </div>

      <!-- VPN -->
      <div class="p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50 space-y-1">
        <div class="flex items-center justify-between">
          <p class="font-medium text-surface-900 dark:text-surface-100">{{ t('storage.infra.vpn', 'VPN tunnel') }}</p>
          <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadge(vpn?.up).cls">
            {{ vpn?.up ? t('storage.infra.up', 'up') : t('storage.infra.down', 'down') }}
          </span>
        </div>
        <p class="text-xs font-mono text-surface-500">{{ vpn?.interface }} · {{ vpn?.unit }}</p>
      </div>

      <!-- DDNS -->
      <div class="p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50 space-y-1">
        <div class="flex items-center justify-between">
          <p class="font-medium text-surface-900 dark:text-surface-100">{{ t('storage.infra.ddns', 'DDNS') }}</p>
          <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadge(!!ddns?.resolved).cls">
            {{ ddns?.resolved ? t('storage.infra.resolved', 'resolved') : t('storage.infra.unresolved', 'unresolved') }}
          </span>
        </div>
        <p class="text-xs font-mono text-surface-500">{{ ddns?.hostname }}</p>
        <p v-if="ddns?.resolved?.length" class="text-xs text-surface-600 dark:text-surface-400 font-mono">
          {{ ddns.resolved.join(', ') }}
        </p>
      </div>

      <!-- Request queue -->
      <div class="p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50 space-y-1 md:col-span-2 lg:col-span-1">
        <div class="flex items-center justify-between">
          <p class="font-medium text-surface-900 dark:text-surface-100">{{ t('storage.infra.queue', 'Dispatcher queue') }}</p>
          <span class="px-2 py-0.5 rounded-full text-xs font-semibold"
                :class="(requests?.pending?.length ?? 0) === 0
                          ? 'bg-surface-200 text-surface-700 dark:bg-surface-700 dark:text-surface-300'
                          : 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/40 dark:text-cyan-200'">
            {{ requests?.pending?.length ?? 0 }} {{ t('storage.infra.pending', 'pending') }}
          </span>
        </div>
        <p class="text-xs font-mono text-surface-500">{{ requests?.dir }}</p>
        <ul v-if="requests?.pending?.length" class="text-xs space-y-0.5 text-surface-600 dark:text-surface-400">
          <li v-for="p in requests.pending.slice(0, 3)" :key="p.name" class="font-mono truncate">
            {{ p.name }}
          </li>
        </ul>
        <p v-if="requests && !requests.writable" class="text-xs text-amber-600 dark:text-amber-300">
          {{ t('storage.infra.queueNotWritable', 'Queue dir is not writable by web user — buttons will fail until perms are fixed.') }}
        </p>
      </div>
    </div>
  </section>
</template>
