<script setup>
// SiteManageV2View
// ---------------------------------------------------------------
// V2-native per-site management. Replaces the 6900-line
// SiteDetailView.vue with a thin shell that delegates each tab to
// its own focused component (see panel/dashboard/src/components/
// site-manage/*Tab.vue, each kept under the 400-line modularity
// budget).
//
// Route: /sites-v2/:domain/manage
//
// Responsibilities:
//   - load V2 + legacy site state once on mount via useSiteManage
//   - render the tab bar and the active tab component
//   - sync the active tab to ?tab= so deep links work
//   - expose a back-link to /sites-v2 and the live site URL
//
// What this view DOES NOT do:
//   - tab-specific data fetching (each tab handles its own)
//   - styling that overrides Tailwind's design system

import {
  computed,
  defineAsyncComponent,
  onMounted,
  provide,
  ref,
  watch,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  useSiteManage,
  SITE_MANAGE_KEY,
} from '@/composables/useSiteManage'
import StatusBadge from '@/components/StatusBadge.vue'

const route = useRoute()
const router = useRouter()

const domain = computed(() => String(route.params.domain ?? ''))

const tabs = [
  { id: 'overview', label: 'Overview', icon: 'dashboard' },
  { id: 'wordpress', label: 'WordPress', icon: 'edit_note' },
  { id: 'emails', label: 'Emails', icon: 'mail' },
  { id: 'databases', label: 'Databases', icon: 'storage' },
  { id: 'ftp', label: 'FTP/SFTP', icon: 'folder_shared' },
  { id: 'ssl', label: 'SSL', icon: 'verified_user' },
  { id: 'dns', label: 'DNS', icon: 'dns' },
  { id: 'config', label: 'Config', icon: 'code' },
  { id: 'logs', label: 'Logs', icon: 'article' },
]

// Lazy-load each tab so the bundle splits cleanly and the initial
// view payload stays small. defineAsyncComponent is the standard
// Vue 3 pattern here.
const tabComponents = {
  overview: defineAsyncComponent(() =>
    import('@/components/site-manage/OverviewTab.vue')),
  wordpress: defineAsyncComponent(() =>
    import('@/components/site-manage/WordPressTab.vue')),
  emails: defineAsyncComponent(() =>
    import('@/components/site-manage/EmailsTab.vue')),
  databases: defineAsyncComponent(() =>
    import('@/components/site-manage/DatabasesTab.vue')),
  ftp: defineAsyncComponent(() =>
    import('@/components/site-manage/FtpTab.vue')),
  ssl: defineAsyncComponent(() =>
    import('@/components/site-manage/SslTab.vue')),
  dns: defineAsyncComponent(() =>
    import('@/components/site-manage/DnsTab.vue')),
  config: defineAsyncComponent(() =>
    import('@/components/site-manage/ConfigTab.vue')),
  logs: defineAsyncComponent(() =>
    import('@/components/site-manage/LogsTab.vue')),
}

const activeTab = ref(String(route.query.tab ?? 'overview'))
if (!tabComponents[activeTab.value]) activeTab.value = 'overview'

const setTab = (id) => {
  if (!tabComponents[id]) return
  activeTab.value = id
  if (route.query.tab !== id) {
    router.replace({ query: { ...route.query, tab: id } })
  }
}

watch(
  () => route.query.tab,
  (q) => {
    if (q && q !== activeTab.value && tabComponents[q]) {
      activeTab.value = q
    }
  },
)

const manage = useSiteManage(domain.value)
const {
  siteV2,
  legacySite,
  loading,
  loadError,
  actualState,
  fetchSite,
} = manage

// Tabs reach the manage state via inject(SITE_MANAGE_KEY) so we
// don't have to pass a sprawling props object to every tab.
provide(SITE_MANAGE_KEY, manage)

onMounted(async () => {
  if (!domain.value) return
  await fetchSite()
})

const statePill = computed(() => {
  const s = actualState.value
  if (!s) return null
  const map = {
    active: { tone: 'success', label: 'Active' },
    provisioning: { tone: 'info', label: 'Provisioning' },
    pending_dns: { tone: 'warning', label: 'SSL pending' },
    degraded: { tone: 'warning', label: 'Degraded' },
    failed: { tone: 'danger', label: 'Failed' },
    suspended: { tone: 'muted', label: 'Suspended' },
    archived: { tone: 'muted', label: 'Archived' },
    absent: { tone: 'muted', label: 'Absent' },
    deleting: { tone: 'warning', label: 'Deleting' },
  }
  return map[s] ?? { tone: 'muted', label: s }
})
</script>

<template>
  <div class="px-4 py-4 space-y-5">
    <!-- ─── Header ───
         Legacy-skin: rounded domain icon tile, capsule state badge,
         clear subtitle and a quick-action group. The state badge
         uses the project-wide StatusBadge so updates to its visual
         language propagate everywhere. -->
    <div>
      <router-link
        to="/sites-v2"
        class="text-xs text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1 mb-2"
      >
        <span class="material-symbols-rounded text-sm">arrow_back</span>
        Back to Sites
      </router-link>
      <div class="page-header">
        <div class="flex items-center gap-3 min-w-0">
          <div
            class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0
                   bg-primary-100 dark:bg-primary-500/20
                   text-primary-600 dark:text-primary-400"
          >
            <span class="material-symbols-rounded text-2xl">language</span>
          </div>
          <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <h1 class="page-title truncate">{{ domain }}</h1>
              <StatusBadge
                v-if="statePill"
                :status="statePill.tone"
                :label="statePill.label"
              />
            </div>
            <p class="text-sm text-surface-500 dark:text-surface-400">
              Per-site management - settings, databases, SSL, DNS and more.
            </p>
            <p
              v-if="loadError"
              class="text-xs text-red-600 dark:text-red-400 mt-1"
            >
              {{ loadError }}
            </p>
          </div>
        </div>
        <div class="action-buttons">
          <a
            :href="`https://${domain}`"
            target="_blank"
            rel="noopener"
            class="btn-secondary btn-sm"
          >
            <span class="material-symbols-rounded text-sm">open_in_new</span>
            Open site
          </a>
          <button
            class="btn-secondary btn-sm"
            :disabled="loading"
            @click="fetchSite()"
          >
            <span
              class="material-symbols-rounded text-sm"
              :class="{ 'animate-spin': loading }"
            >refresh</span>
            Refresh
          </button>
        </div>
      </div>
    </div>

    <!-- ─── Tabs ───
         Use the shared .tabs-container / .tab-nav helpers so this
         shell matches every other tabbed surface in the panel
         (Mail, Drive, etc.). Active tab gets a subtle background
         tint plus the primary underline. -->
    <div class="tabs-container">
      <div class="tab-nav">
        <button
          v-for="t in tabs"
          :key="t.id"
          class="tab-btn gap-2"
          :class="activeTab === t.id ? 'active' : ''"
          @click="setTab(t.id)"
        >
          <span class="material-symbols-rounded tab-icon">{{ t.icon }}</span>
          <span class="tab-label">{{ t.label }}</span>
        </button>
      </div>
    </div>

    <!-- ─── Tab content ─── -->
    <div v-if="domain">
      <Suspense>
        <component
          :is="tabComponents[activeTab]"
          :key="activeTab"
          :domain="domain"
        />
        <template #fallback>
          <div class="card">
            <div class="card-body space-y-3">
              <div class="skeleton h-6 w-40 rounded" />
              <div class="skeleton h-4 w-full rounded" />
              <div class="skeleton h-4 w-3/4 rounded" />
              <div class="skeleton h-32 w-full rounded-xl" />
            </div>
          </div>
        </template>
      </Suspense>
    </div>
  </div>
</template>
