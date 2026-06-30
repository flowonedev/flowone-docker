<script setup>
// OverviewTab
// ---------------------------------------------------------------
// V2-native overview for the site management view. Replaces the
// "overview" section of SiteDetailView.vue (lines 3149-3307).
//
// Differences from the legacy section:
//   - Reads php_version + php_limits + process limits from the
//     legacy /api/sites/{domain} payload (which the V2 reconciler
//     keeps in sync via SiteRowBackfiller). Once Phase 5 deletes
//     the legacy endpoint we'll bring these onto a V2-native
//     resource, but for now they're the SAME data, just rebound.
//   - PHP version change still uses PUT /api/sites/{domain} so the
//     OLS-coupled vhost write happens via the legacy code path.
//     A future ProvisioningAction handler can wrap this in a
//     CHANGE_PHP saga; out of scope here.
//   - Surfaces V2 state pill (active / pending_dns / degraded /
//     suspended / etc.) directly from the V2 row.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useInjectedSiteManage } from '@/composables/useSiteManage'

defineProps({ domain: { type: String, required: true } })

const toast = useToastStore()
const manage = useInjectedSiteManage()
const { legacySite, siteV2, fetchSite, documentRoot } = manage

const phpVersions = [
  { value: 'lsphp82', label: 'PHP 8.2' },
  { value: 'lsphp83', label: 'PHP 8.3' },
  { value: 'lsphp84', label: 'PHP 8.4' },
]

const saving = ref(false)
const health = ref(null)
const healthLoading = ref(false)
const fixing = ref(false)
const fixingIssue = ref(null)

const currentPhpHandler = computed(() => {
  return legacySite.value?.php_handler
    ?? legacySite.value?.php_version
    ?? 'lsphp83'
})

const phpLimits = computed(() => legacySite.value?.php_limits ?? {})
const processLimits = computed(() => legacySite.value?.limits ?? {})

const sslState = computed(() => {
  if (siteV2.value?.ssl_enabled) return 'Active'
  if (siteV2.value?.actual_state === 'pending_dns') return 'Pending'
  return legacySite.value?.ssl ? 'Active' : 'None'
})

const updatePhpVersion = async (newVersion) => {
  if (!newVersion || newVersion === currentPhpHandler.value) return
  saving.value = true
  try {
    const r = await api.put(`/sites/${encodeURIComponent(manage.domain)}`, {
      php_version: newVersion,
    })
    if (r.data?.success) {
      toast.success(`PHP version changed to ${newVersion}`)
      await fetchSite()
    } else {
      toast.error(r.data?.error || 'Failed to change PHP version')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to change PHP version')
  } finally {
    saving.value = false
  }
}

const fetchHealth = async () => {
  healthLoading.value = true
  try {
    const r = await api.get(`/sites/${encodeURIComponent(manage.domain)}/validate`)
    if (r.data?.success) health.value = r.data.data
  } catch {
    health.value = null
  } finally {
    healthLoading.value = false
  }
}

const fixAll = async () => {
  fixing.value = true
  try {
    const r = await api.post(`/sites/${encodeURIComponent(manage.domain)}/fix`)
    if (r.data?.success) {
      toast.success(r.data.message || 'Issues fixed')
      await fetchHealth()
    } else {
      toast.error(r.data?.error || 'Fix failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Fix failed')
  } finally {
    fixing.value = false
  }
}

const fixIssue = async (issueType) => {
  fixingIssue.value = issueType
  try {
    const r = await api.post(
      `/sites/${encodeURIComponent(manage.domain)}/fix-issue`,
      { issue_type: issueType },
    )
    if (r.data?.success) {
      toast.success(r.data.message || `Fixed: ${issueType}`)
      await fetchHealth()
    } else {
      toast.error(r.data?.error || `Failed to fix: ${issueType}`)
    }
  } catch {
    toast.error(`Failed to fix: ${issueType}`)
  } finally {
    fixingIssue.value = null
  }
}

const formatBytes = (bytes) => {
  if (!bytes || bytes <= 0) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let n = bytes
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024
    i++
  }
  return `${n.toFixed(n < 10 ? 1 : 0)} ${units[i]}`
}

onMounted(fetchHealth)
</script>

<template>
  <div class="space-y-5">
    <!-- ─── Site Information ─── -->
    <div class="card">
      <div class="card-header flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500">info</span>
        <h3 class="font-semibold">Site Information</h3>
      </div>
      <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-surface-500 dark:text-surface-400 mb-1">Domain</label>
          <p class="font-medium truncate">{{ domain }}</p>
        </div>
        <div>
          <label class="block text-xs text-surface-500 dark:text-surface-400 mb-1">Document Root</label>
          <p class="font-mono text-xs sm:text-sm break-all">{{ documentRoot }}</p>
        </div>
        <div>
          <label class="block text-xs text-surface-500 dark:text-surface-400 mb-1">PHP Version</label>
          <div class="flex items-center gap-2">
            <select
              :value="currentPhpHandler"
              class="input w-full sm:w-auto"
              :disabled="saving"
              @change="updatePhpVersion($event.target.value)"
            >
              <option v-for="p in phpVersions" :key="p.value" :value="p.value">
                {{ p.label }}
              </option>
            </select>
            <span v-if="saving" class="spinner-sm" />
          </div>
        </div>
        <div>
          <label class="block text-xs text-surface-500 dark:text-surface-400 mb-1">V2 State</label>
          <p class="text-sm font-medium">{{ siteV2?.actual_state ?? '—' }}</p>
        </div>
        <div>
          <label class="block text-xs text-surface-500 dark:text-surface-400 mb-1">SSL</label>
          <p class="text-sm">{{ sslState }}</p>
        </div>
        <div>
          <label class="block text-xs text-surface-500 dark:text-surface-400 mb-1">Size</label>
          <p class="text-sm">{{ formatBytes(siteV2?.size_bytes ?? legacySite?.size_bytes) }}</p>
        </div>
      </div>
    </div>

    <!-- ─── PHP & Process Limits ─── -->
    <div
      v-if="Object.keys(phpLimits).length || Object.keys(processLimits).length"
      class="card"
    >
      <div class="card-header flex items-center gap-2">
        <span class="material-symbols-rounded text-purple-500">memory</span>
        <h3 class="font-semibold">PHP &amp; Process Limits</h3>
      </div>
      <div class="card-body space-y-4">
        <div v-if="Object.keys(phpLimits).length" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
          <div
            v-for="(v, k) in phpLimits"
            :key="k"
            class="p-3 rounded-xl bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]
                   border border-surface-100 dark:border-[rgb(var(--color-border))]"
          >
            <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 mb-1">
              {{ k }}
            </p>
            <p class="font-semibold text-sm font-mono tabular-nums">{{ v ?? '—' }}</p>
          </div>
        </div>
        <div
          v-if="Object.keys(processLimits).length"
          class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2"
        >
          <div
            v-for="(v, k) in processLimits"
            :key="k"
            class="p-2.5 rounded-xl bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]
                   border border-surface-100 dark:border-[rgb(var(--color-border))]"
          >
            <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 mb-1">
              {{ k }}
            </p>
            <p class="font-semibold text-sm font-mono tabular-nums">{{ v ?? '—' }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── Site Health ─── -->
    <div class="card">
      <div class="card-header flex items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-emerald-500">health_and_safety</span>
          <h3 class="font-semibold">Site Health</h3>
        </div>
        <div class="flex items-center gap-2">
          <button
            class="btn-secondary btn-sm"
            :disabled="healthLoading"
            @click="fetchHealth"
          >
            <span
              class="material-symbols-rounded text-sm"
              :class="{ 'animate-spin': healthLoading }"
            >refresh</span>
          </button>
          <button
            v-if="health?.fixable_count > 0"
            class="btn-primary btn-sm"
            :disabled="fixing"
            @click="fixAll"
          >
            <span v-if="fixing" class="spinner-sm" />
            <span v-else class="material-symbols-rounded text-sm">build</span>
            Fix All ({{ health.fixable_count }})
          </button>
        </div>
      </div>
      <div class="card-body">
        <div v-if="healthLoading" class="space-y-3">
          <div class="skeleton h-12 w-full rounded-xl" />
          <div class="skeleton h-12 w-full rounded-xl" />
          <div class="skeleton h-12 w-3/4 rounded-xl" />
        </div>
        <div
          v-else-if="!health"
          class="text-center py-6 text-surface-500 dark:text-surface-400 text-sm"
        >
          Health information unavailable.
        </div>
        <div
          v-else-if="(health.issues ?? []).length === 0"
          class="flex items-center gap-2 text-green-600 dark:text-green-400 py-3"
        >
          <span class="material-symbols-rounded">check_circle</span>
          All checks passing.
        </div>
        <ul v-else class="space-y-2">
          <li
            v-for="issue in health.issues"
            :key="issue.type ?? issue.message"
            class="flex items-start justify-between gap-3 p-3 rounded-xl
                   border border-amber-200 dark:border-amber-500/30
                   bg-amber-50 dark:bg-amber-500/5"
          >
            <div class="flex items-start gap-2 min-w-0">
              <span class="material-symbols-rounded text-amber-500 text-base shrink-0 mt-0.5">
                warning
              </span>
              <div class="min-w-0">
                <p class="text-sm font-medium">{{ issue.title ?? issue.type }}</p>
                <p class="text-xs text-surface-500 dark:text-surface-400">
                  {{ issue.message }}
                </p>
              </div>
            </div>
            <button
              v-if="issue.fixable"
              class="btn-secondary btn-sm shrink-0"
              :disabled="fixingIssue === issue.type"
              @click="fixIssue(issue.type)"
            >
              <span v-if="fixingIssue === issue.type" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">build</span>
              Fix
            </button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
