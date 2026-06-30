// useSiteManage
// ---------------------------------------------------------------
// Shared state + data loaders for the V2 site management view.
//
// SiteManageV2View itself stays focused on tab navigation and
// per-tab routing. Each tab component pulls its own slice of data
// via this composable, which keeps each tab under the 400-line
// modularity budget without copy-pasting fetch logic.
//
// Conventions:
//   - All API calls go through `@/services/api` (the global axios
//     instance with auth headers).
//   - All loaders are idempotent: calling them again refreshes
//     state in place.
//   - All loaders return the resolved data so callers can `await`
//     them for sequencing.
//   - The composable is intentionally NOT a Pinia store: each view
//     instance gets its own state so navigating away from the
//     manage view drops its cache, matching the legacy
//     SiteDetailView's load-on-mount behavior.

import { ref, computed, inject } from 'vue'
import api from '@/services/api'
import { getSite } from '@/services/sitesV2'

// Tabs grab the shared manage handle via inject(SITE_MANAGE_KEY) so
// SiteManageV2View doesn't have to thread props through every tab.
export const SITE_MANAGE_KEY = Symbol('site-manage')

export function useInjectedSiteManage() {
  const manage = inject(SITE_MANAGE_KEY, null)
  if (!manage) {
    throw new Error(
      'useInjectedSiteManage(): no SiteManageV2View ancestor found. '
        + 'Tab components must be rendered inside SiteManageV2View.'
    )
  }
  return manage
}

export function useSiteManage(domain) {
  // ── Core site state ────────────────────────────────────────
  // siteV2: from /api/sites/v2/{domain} - the V2 row + last job.
  // legacySite: from /api/sites/{domain} - the legacy vhost row
  //   (still the source of truth for OLS-coupled fields like
  //   php_handler, php_limits, vhost text).
  // We keep both around during the V2 migration so OLS-coupled
  // tabs (Overview, FTP, Config) keep working without an
  // additional backend cutover.
  const siteV2 = ref(null)
  const legacySite = ref(null)
  const loading = ref(false)
  const loadError = ref('')

  const fetchSite = async () => {
    loading.value = true
    loadError.value = ''
    try {
      const [v2, legacy] = await Promise.allSettled([
        getSite(domain),
        api.get(`/sites/${encodeURIComponent(domain)}`),
      ])
      if (v2.status === 'fulfilled') {
        siteV2.value = v2.value?.site ?? v2.value ?? null
      } else if (v2.status === 'rejected') {
        // V2 row missing is a hard failure - the view is only
        // for V2-tracked sites. Surface the error so the
        // operator knows to use the legacy /sites page instead.
        loadError.value = v2.reason?.message ?? 'Site not found in V2 registry'
      }
      if (legacy.status === 'fulfilled') {
        legacySite.value = legacy.value?.data?.data ?? legacy.value?.data ?? null
      }
    } finally {
      loading.value = false
    }
  }

  // ── Derived state ──────────────────────────────────────────
  const phpVersion = computed(() => legacySite.value?.php_version ?? '')
  const documentRoot = computed(() => legacySite.value?.document_root
    ?? `/home/${domain}/public_html`)
  const homeDir = computed(() => legacySite.value?.home_dir ?? `/home/${domain}`)
  const siteUser = computed(() => legacySite.value?.sftp_user
    ?? legacySite.value?.system_user
    ?? siteV2.value?.system_user
    ?? '')
  const isVhostMissing = computed(() => {
    return (legacySite.value?.status === 'missing'
      || legacySite.value?.vhost_missing === true)
  })
  const actualState = computed(() => siteV2.value?.actual_state ?? null)

  return {
    domain,
    siteV2,
    legacySite,
    loading,
    loadError,
    fetchSite,
    phpVersion,
    documentRoot,
    homeDir,
    siteUser,
    isVhostMissing,
    actualState,
  }
}
