<script setup>
// SslTab
// ---------------------------------------------------------------
// SSL / TLS management. Replaces the "ssl" section of
// SiteDetailView.vue.
//
// Layout matches the legacy SiteDetailView "SSL" tab the operators
// were used to:
//   - Header row: title + Renew / Test / SSL Checker / Issue.
//   - Big SSL Certificate card: status icon tile + status badge,
//     issuer, 4-stat grid (Valid From / Until / Days Remaining /
//     SANs), pill list of SAN domains, optional per-domain Test
//     results panel.
//   - Empty-state card with "Issue Certificate" CTA when no cert.
//   - SSL Security Analysis card: grade tile, score, protocols
//     (TLS 1.2 / 1.3 only), vulnerabilities, cipher configuration,
//     security headers, deductions + Auto Fix All / Manual Fix.
//   - Full Issue SSL modal: DNS pre-flight table (Local / Auth NS
//     / External), Will-include / Will-skip summary, preflight
//     checks with Fix DNS, auto-retry toggle + waiting timer.
//
// All endpoints used here are the canonical V2 routes (the broken
// /api/dns/{domain} paths used by the legacy modal have been
// replaced with /dns/zones/* + /dns/records). The legacy hard-coded
// server IP is gone — we always read it from /ssl/{domain}/dns-test
// to keep this multi-server safe.

import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const props = defineProps({ domain: { type: String, required: true } })
const toast = useToastStore()

// ─── Reactive state ────────────────────────────────────────────
const ssl = ref(null)
const sslList = ref([])
const loading = ref(false)

const submitting = ref(false)
const sslTestLoading = ref(false)
const sslTestResult = ref(null)
const comprehensiveCheck = ref(null)
const comprehensiveLoading = ref(false)
const showSecurityHeadersFix = ref(false)
const fixingSslConfig = ref(false)

const issueSslModal = ref({
  show: false,
  error: null,
  preflight: null,
  autoRetry: false,
  retryCount: 0,
  waitingStartTime: null,
  retryInterval: null,
  fixingDns: false,
  dnsTest: null,
  dnsTestLoading: false,
  dnsSyncing: false,
})

// Ticking clock used to refresh the "Waiting: Xm Ys" label without
// forcing the operator to interact.
const nowTick = ref(Date.now())
let nowTimer = null

// ─── Helpers (ported verbatim from SiteDetailView) ─────────────
const getCertStatus = (cert) => {
  if (!cert) return null
  if (cert.is_expired) return 'expired'
  if (cert.is_self_signed) return 'warning'
  if (cert.days_remaining < 30) return 'expiring'
  return 'valid'
}

const getGradeColor = (grade) => {
  if (!grade) return 'text-surface-500'
  switch (String(grade)[0]) {
    case 'A': return 'text-green-600'
    case 'B': return 'text-lime-600'
    case 'C': return 'text-amber-600'
    case 'D': return 'text-orange-600'
    case 'F': return 'text-red-600'
    default: return 'text-surface-500'
  }
}

const getGradeBgColor = (grade) => {
  if (!grade) return 'bg-surface-100 dark:bg-surface-800'
  switch (String(grade)[0]) {
    case 'A': return 'bg-green-100 dark:bg-green-500/20'
    case 'B': return 'bg-lime-100 dark:bg-lime-500/20'
    case 'C': return 'bg-amber-100 dark:bg-amber-500/20'
    case 'D': return 'bg-orange-100 dark:bg-orange-500/20'
    case 'F': return 'bg-red-100 dark:bg-red-500/20'
    default: return 'bg-surface-100 dark:bg-surface-800'
  }
}

const hasFixableSecurityHeaders = computed(() => {
  if (!comprehensiveCheck.value?.deductions) return false
  return comprehensiveCheck.value.deductions.some((d) =>
    String(d.reason || '').toLowerCase().includes('hsts')
    || String(d.reason || '').toLowerCase().includes('header'),
  )
})

// Only show TLS 1.2 / TLS 1.3 (legacy parity — deprecated protocols
// are hidden from the UI but still considered in the grade).
const filteredProtocols = computed(() => {
  if (!comprehensiveCheck.value?.protocols) return {}
  const allowed = ['TLS1.2', 'TLS1.3']
  return Object.fromEntries(
    Object.entries(comprehensiveCheck.value.protocols)
      .filter(([key]) => allowed.includes(key)),
  )
})

// Drop CSP from the headers grid — it's site-specific and a global
// "missing CSP" status would be misleading.
const filteredSecurityHeaders = computed(() => {
  if (!comprehensiveCheck.value?.security_headers) return {}
  const excluded = ['csp', 'content_security_policy']
  return Object.fromEntries(
    Object.entries(comprehensiveCheck.value.security_headers)
      .filter(([key]) => !excluded.includes(String(key).toLowerCase())),
  )
})

const getSslFixSuggestion = (reason) => {
  const lower = String(reason || '').toLowerCase()
  if (lower.includes('sslv2') || lower.includes('sslv3')) {
    return 'Disable in OpenLiteSpeed: Admin Console → Listeners → SSL → Disable SSLv2/SSLv3'
  }
  if (lower.includes('tls 1.0')) {
    return 'Config tab → vhssl block → set sslProtocol to 24 (TLS 1.2+) or 28 (TLS 1.2 & 1.3)'
  }
  if (lower.includes('tls 1.1')) {
    return 'Config tab → vhssl block → set sslProtocol to 24 (TLS 1.2+)'
  }
  if (lower.includes('no modern tls')) {
    return 'Check the vhssl block in Config tab has correct keyFile and certFile paths.'
  }
  if (lower.includes('forward secrecy')) {
    return 'Enable ECDHE ciphers in vhssl: ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384'
  }
  if (lower.includes('hsts')) {
    return 'Add HSTS via vhost rewrite rules. Click "Manual Fix" above for the snippet.'
  }
  if (lower.includes('insecure cipher')) {
    return 'Update cipher suite: remove RC4, DES, 3DES from the vhssl ciphers list.'
  }
  if (lower.includes('weak cipher')) {
    return 'Consider removing SHA1-based ciphers from the vhssl cipher suite.'
  }
  if (lower.includes('heartbleed') || lower.includes('poodle') || lower.includes('drown')) {
    return 'CRITICAL: Update OpenSSL immediately — apt update && apt upgrade openssl'
  }
  if (lower.includes('tls 1.3')) {
    return 'Great! TLS 1.3 provides the best security and performance.'
  }
  return 'Check OpenLiteSpeed SSL configuration in the Config tab.'
}

const formatCheckName = (check) => {
  const names = {
    domain_valid: 'Domain Valid',
    dns_resolves: 'DNS Resolves',
    webroot_exists: 'Webroot Exists',
    webroot_writable: 'Webroot Writable',
    acme_accessible: 'ACME Challenge Accessible',
    port_80_open: 'Port 80 Open',
  }
  return names[check]
    || String(check).replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())
}

const sslWaitingTime = computed(() => {
  if (!issueSslModal.value.waitingStartTime) return null
  const elapsed = nowTick.value - issueSslModal.value.waitingStartTime
  const minutes = Math.floor(elapsed / 60000)
  const seconds = Math.floor((elapsed % 60000) / 1000)
  return `${minutes}m ${seconds}s`
})

// ─── API envelope helpers ──────────────────────────────────────
const unwrapList = (payload, ...keys) => {
  if (Array.isArray(payload)) return payload
  if (payload && typeof payload === 'object') {
    for (const key of keys) {
      if (Array.isArray(payload[key])) return payload[key]
    }
  }
  return []
}

// Match certs against the current domain in the order operators
// expect: exact .domain, then SANs / .domains list, then
// mail.<domain> as a fallback (covers MX-only certs).
const matchCertForDomain = (certs, dom) => {
  if (!Array.isArray(certs) || certs.length === 0) return null
  let hit = certs.find((c) => c?.domain === dom)
  if (hit) return hit
  hit = certs.find((c) => {
    const sans = Array.isArray(c?.sans) ? c.sans : []
    const domains = Array.isArray(c?.domains) ? c.domains : []
    return sans.includes(dom) || domains.includes(dom)
  })
  if (hit) return hit
  hit = certs.find((c) => c?.domain === `mail.${dom}`)
  return hit ?? null
}

// ─── Data loading ──────────────────────────────────────────────
const fetchSsl = async () => {
  loading.value = true
  try {
    const r = await api.get('/ssl').catch(() => null)
    if (r?.data?.success) {
      sslList.value = unwrapList(r.data.data, 'certificates', 'certs', 'items')
      ssl.value = matchCertForDomain(sslList.value, props.domain)
    } else {
      sslList.value = []
      ssl.value = null
    }
    const saved = await api.get(
      `/ssl/${encodeURIComponent(props.domain)}/comprehensive/saved`,
    ).catch(() => null)
    if (saved?.data?.success) {
      comprehensiveCheck.value = saved.data.data ?? null
    }
  } finally {
    loading.value = false
  }
}

// ─── Issue / renew flow (full legacy flow with auto-retry) ─────
const issueSsl = async (isRetry = false) => {
  submitting.value = true
  issueSslModal.value.error = null
  if (!isRetry) {
    issueSslModal.value.retryCount = 0
    issueSslModal.value.waitingStartTime = null
  }
  try {
    const r = await api.post(`/ssl/${encodeURIComponent(props.domain)}/issue`, {})
    if (r.data?.success) {
      toast.success('SSL certificate issued successfully!')
      stopSslAutoRetry()
      closeIssueSslModal()
      await fetchSsl()
    } else {
      handleSslError(r.data || { error: 'Failed to issue SSL' })
    }
  } catch (e) {
    handleSslError(e?.response?.data || { error: 'Failed to issue SSL' })
  } finally {
    submitting.value = false
  }
}

const handleSslError = (data) => {
  issueSslModal.value.error = data?.error || 'Failed to issue SSL'
  issueSslModal.value.preflight = data?.preflight || null
  const isDnsIssue = data?.preflight?.checks?.dns_resolves === false
  if (isDnsIssue && !issueSslModal.value.waitingStartTime) {
    issueSslModal.value.waitingStartTime = Date.now()
  }
  if (isDnsIssue) issueSslModal.value.retryCount++
}

// Fix DNS from the Issue SSL modal. Uses the V2 /dns/zones/* +
// /dns/records routes (the legacy modal used non-existent
// /dns/{domain} paths). The apex A record uses the IP returned by
// /ssl/{domain}/dns-test — no hard-coded IP.
const fixDnsFromSslModal = async () => {
  issueSslModal.value.fixingDns = true
  try {
    let zoneExists = false
    try {
      const check = await api.get(
        `/dns/zones/${encodeURIComponent(props.domain)}`,
      )
      zoneExists = !!check.data?.success && !!check.data?.data
    } catch (e) {
      if (e?.response?.status !== 404) throw e
    }

    if (!zoneExists) {
      const create = await api.post('/dns/zones', { name: props.domain })
      if (!create.data?.success) {
        toast.error(create.data?.error || 'Failed to create DNS zone')
        return
      }
      toast.success('DNS zone created. Wait 2-5 min for propagation.')
      setTimeout(() => issueSsl(true), 2000)
      return
    }

    const records = await api.get(
      `/dns/zones/${encodeURIComponent(props.domain)}/records`,
    )
    const list = records.data?.data?.records ?? records.data?.data ?? []
    const hasApex = list.some(
      (r) => r.type === 'A' && (r.name === props.domain || r.name === '@'),
    )

    if (!hasApex) {
      const serverIp = issueSslModal.value.dnsTest?.server_ip || ''
      if (!serverIp) {
        toast.warning('Run DNS test first to detect the server IP, then click Fix DNS again.')
        return
      }
      await api.post('/dns/records', {
        zone: props.domain,
        name: props.domain,
        type: 'A',
        content: serverIp,
        ttl: 3600,
      })
      toast.success('Added apex A record. Retrying SSL in a moment…')
    } else {
      toast.info('DNS zone and A record present. Retrying SSL…')
    }
    setTimeout(() => issueSsl(true), 2000)
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to fix DNS')
  } finally {
    issueSslModal.value.fixingDns = false
  }
}

const toggleSslAutoRetry = (enabled) => {
  issueSslModal.value.autoRetry = enabled
  if (enabled) {
    issueSslModal.value.retryInterval = setInterval(() => {
      if (
        issueSslModal.value.show
        && issueSslModal.value.autoRetry
        && !submitting.value
      ) {
        issueSsl(true)
      }
    }, 60000)
  } else {
    stopSslAutoRetry()
  }
}

const stopSslAutoRetry = () => {
  if (issueSslModal.value.retryInterval) {
    clearInterval(issueSslModal.value.retryInterval)
    issueSslModal.value.retryInterval = null
  }
  issueSslModal.value.autoRetry = false
}

const closeIssueSslModal = () => {
  stopSslAutoRetry()
  issueSslModal.value = {
    show: false,
    error: null,
    preflight: null,
    autoRetry: false,
    retryCount: 0,
    waitingStartTime: null,
    retryInterval: null,
    fixingDns: false,
    dnsTest: null,
    dnsTestLoading: false,
    dnsSyncing: false,
  }
}

// ─── DNS pre-flight inside the modal ───────────────────────────
const runDnsTest = async () => {
  issueSslModal.value.dnsTestLoading = true
  issueSslModal.value.dnsTest = null
  try {
    const r = await api.get(`/ssl/${encodeURIComponent(props.domain)}/dns-test`)
    if (r.data?.success) {
      issueSslModal.value.dnsTest = r.data.data
    } else {
      toast.error(r.data?.error || 'DNS test failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'DNS test failed')
  } finally {
    issueSslModal.value.dnsTestLoading = false
  }
}

const syncDns = async () => {
  issueSslModal.value.dnsSyncing = true
  try {
    const r = await api.post(
      `/dns/zones/${encodeURIComponent(props.domain)}/sync`,
    )
    if (r.data?.success) {
      if (r.data.data?.all_synced) {
        toast.success('DNS synced to all nameservers!')
      } else {
        toast.warning('Sync initiated — some nameservers may need more time.')
      }
      await runDnsTest()
    } else {
      toast.error(r.data?.error || 'DNS sync failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'DNS sync failed')
  } finally {
    issueSslModal.value.dnsSyncing = false
  }
}

// ─── Per-domain certificate test (top-of-page Test button) ────
const testSslCertificate = async () => {
  sslTestLoading.value = true
  sslTestResult.value = null
  try {
    const r = await api.get(`/ssl/${encodeURIComponent(props.domain)}/test`)
    if (r.data?.success) {
      sslTestResult.value = r.data.data
      if (r.data.data?.all_valid) {
        toast.success('All domains have valid SSL!')
      } else {
        toast.warning('Some domains failed the SSL test')
      }
    } else {
      toast.error(r.data?.error || 'SSL test failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'SSL test failed')
  } finally {
    sslTestLoading.value = false
  }
}

const openSslChecker = () => {
  window.open(`https://dnschecker.org/#A/${props.domain}`, '_blank')
}

// ─── Comprehensive SSL Analysis (grade, protocols, ciphers, …) ─
const runComprehensiveCheck = async (forceRefresh = false) => {
  comprehensiveLoading.value = true
  try {
    const url = `/ssl/${encodeURIComponent(props.domain)}/comprehensive${forceRefresh ? '?refresh=1' : ''}`
    const r = await api.get(url)
    if (r.data?.success) {
      const data = r.data.data
      if (forceRefresh) {
        data.from_cache = false
        data.from_database = false
      }
      comprehensiveCheck.value = data
      if (!forceRefresh && (data.from_cache || data.from_database)) {
        toast.info('Showing saved results (click Re-scan for fresh analysis)')
      } else {
        toast.success(`SSL Analysis complete — Grade: ${data.grade}`)
      }
    } else {
      toast.error(r.data?.error || 'SSL analysis failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'SSL analysis failed')
  } finally {
    comprehensiveLoading.value = false
  }
}

const fixSslConfig = async () => {
  fixingSslConfig.value = true
  try {
    const r = await api.post(
      `/ssl/${encodeURIComponent(props.domain)}/fix-config`,
      {},
    )
    if (r.data?.success) {
      const data = r.data.data
      toast.success(`SSL configuration fixed! Changes: ${data?.changes?.join(', ') || 'Updated'}`)
      comprehensiveCheck.value = null
      showSecurityHeadersFix.value = false
      setTimeout(() => runComprehensiveCheck(true), 2000)
    } else {
      toast.error(r.data?.error || 'Failed to fix SSL configuration')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to fix SSL configuration')
  } finally {
    fixingSslConfig.value = false
  }
}

// ─── Lifecycle ─────────────────────────────────────────────────
onMounted(() => {
  fetchSsl()
  nowTimer = setInterval(() => {
    nowTick.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  stopSslAutoRetry()
  if (nowTimer) {
    clearInterval(nowTimer)
    nowTimer = null
  }
})
</script>

<template>
  <div class="space-y-6" data-ssl-tab="v2-restored">
    <!-- ─── Header row ─── -->
    <div class="flex justify-between items-center flex-wrap gap-2">
      <h3 class="font-semibold">SSL Certificate</h3>
      <div class="flex gap-2 flex-wrap">
        <template v-if="ssl">
          <button class="btn-secondary btn-sm" @click="issueSslModal.show = true">
            <span class="material-symbols-rounded">autorenew</span>
            Renew
          </button>
          <button
            class="btn-secondary btn-sm"
            :disabled="sslTestLoading"
            @click="testSslCertificate"
          >
            <span v-if="sslTestLoading" class="spinner-sm mr-1" />
            <span v-else class="material-symbols-rounded">verified_user</span>
            {{ sslTestLoading ? 'Testing…' : 'Test' }}
          </button>
          <button class="btn-secondary btn-sm" @click="openSslChecker">
            <span class="material-symbols-rounded">open_in_new</span>
            SSL Checker
          </button>
        </template>
        <button
          v-if="!ssl || ssl.is_expired"
          class="btn-primary btn-sm"
          @click="issueSslModal.show = true"
        >
          <span class="material-symbols-rounded">add</span>
          Issue Certificate
        </button>
        <button
          class="btn-secondary btn-sm"
          :disabled="loading"
          title="Refresh"
          @click="fetchSsl"
        >
          <span
            class="material-symbols-rounded"
            :class="{ 'animate-spin': loading }"
          >refresh</span>
        </button>
      </div>
    </div>

    <!-- ─── Cert summary card ─── -->
    <div v-if="ssl" class="card p-6">
      <div class="flex items-start gap-4">
        <div
          :class="[
            'w-16 h-16 rounded-2xl flex items-center justify-center shrink-0',
            getCertStatus(ssl) === 'valid'
              ? 'bg-green-100 dark:bg-green-500/20'
              : getCertStatus(ssl) === 'expired'
                ? 'bg-red-100 dark:bg-red-500/20'
                : 'bg-amber-100 dark:bg-amber-500/20'
          ]"
        >
          <span
            :class="[
              'material-symbols-rounded text-3xl',
              getCertStatus(ssl) === 'valid'
                ? 'text-green-600'
                : getCertStatus(ssl) === 'expired'
                  ? 'text-red-600'
                  : 'text-amber-600'
            ]"
          >verified_user</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-3 mb-2 flex-wrap">
            <h4 class="font-semibold text-lg truncate">{{ ssl.domain || domain }}</h4>
            <StatusBadge :status="getCertStatus(ssl) || 'unknown'" />
          </div>
          <p class="text-surface-500 mb-4 truncate">
            {{ ssl.issuer || ssl.ca || '—' }}
          </p>

          <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
              <span class="text-surface-500">Valid From</span>
              <p class="font-medium">{{ ssl.valid_from || '—' }}</p>
            </div>
            <div>
              <span class="text-surface-500">Valid Until</span>
              <p class="font-medium">{{ ssl.valid_to || (ssl.expires_at ? new Date(ssl.expires_at).toLocaleString() : '—') }}</p>
            </div>
            <div>
              <span class="text-surface-500">Days Remaining</span>
              <p
                class="font-medium"
                :class="ssl.days_remaining != null && ssl.days_remaining < 30 ? 'text-amber-500' : ''"
              >
                {{ ssl.days_remaining ?? '—' }}
              </p>
            </div>
            <div>
              <span class="text-surface-500">SANs</span>
              <p class="font-medium">
                {{ ssl.sans?.length || ssl.domains?.length || 1 }}
                domain{{ ((ssl.sans?.length || ssl.domains?.length || 1)) > 1 ? 's' : '' }}
              </p>
            </div>
          </div>

          <div
            v-if="(ssl.sans?.length || ssl.domains?.length)"
            class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700"
          >
            <span class="text-surface-500 text-sm block mb-2">
              Domains covered by this certificate
            </span>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="san in (ssl.sans?.length ? ssl.sans : ssl.domains)"
                :key="san"
                class="inline-flex items-center gap-1 px-3 py-1
                       bg-green-100 dark:bg-green-500/20
                       text-green-700 dark:text-green-400
                       rounded-full text-sm font-mono"
              >
                <span class="material-symbols-rounded text-sm">verified</span>
                {{ san }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Per-domain SSL Test results -->
      <div
        v-if="sslTestResult"
        class="mt-4 p-4 bg-surface-50 dark:bg-surface-800 rounded-xl"
      >
        <div class="flex items-center justify-between mb-3">
          <h5 class="font-medium text-sm flex items-center gap-2">
            <span
              class="material-symbols-rounded text-lg"
              :class="sslTestResult.all_valid ? 'text-green-500' : 'text-red-500'"
            >
              {{ sslTestResult.all_valid ? 'check_circle' : 'error' }}
            </span>
            Certificate Test Results
          </h5>
          <button
            class="text-surface-400 hover:text-surface-600"
            @click="sslTestResult = null"
          >
            <span class="material-symbols-rounded text-sm">close</span>
          </button>
        </div>
        <div class="space-y-2">
          <div
            v-for="(result, d) in sslTestResult.domains"
            :key="d"
            class="flex items-center justify-between p-2 rounded-lg"
            :class="result.valid
              ? 'bg-green-50 dark:bg-green-500/10'
              : 'bg-red-50 dark:bg-red-500/10'"
          >
            <span class="text-sm font-mono truncate">{{ d }}</span>
            <div class="flex items-center gap-2 shrink-0">
              <span
                class="text-xs"
                :class="result.valid ? 'text-green-600' : 'text-red-600'"
              >
                {{ result.http_code ? `HTTP ${result.http_code}` : 'Connection failed' }}
              </span>
              <span
                class="material-symbols-rounded text-sm"
                :class="result.valid ? 'text-green-500' : 'text-red-500'"
              >
                {{ result.valid ? 'check_circle' : 'cancel' }}
              </span>
            </div>
          </div>
        </div>
        <p
          v-if="sslTestResult.all_valid"
          class="text-xs text-green-600 mt-3 flex items-center gap-1"
        >
          <span class="material-symbols-rounded text-sm">verified</span>
          All domains have valid SSL and are responding correctly
        </p>
        <p
          v-else
          class="text-xs text-red-600 mt-3 flex items-center gap-1"
        >
          <span class="material-symbols-rounded text-sm">warning</span>
          Some domains failed the SSL test
        </p>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="card p-12 text-center">
      <span class="material-symbols-rounded text-5xl text-surface-300 mb-4 block">
        lock_open
      </span>
      <h4 class="text-lg font-medium mb-2">No SSL Certificate</h4>
      <p class="text-surface-500 mb-4">
        This site doesn't have an SSL certificate yet.
      </p>
      <button class="btn-primary" @click="issueSslModal.show = true">
        <span class="material-symbols-rounded">add</span>
        Issue Certificate
      </button>
    </div>

    <!-- ─── Comprehensive SSL Analysis ─── -->
    <div class="card p-6">
      <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <div>
          <h4 class="font-semibold">SSL Security Analysis</h4>
          <p class="text-sm text-surface-500">
            Comprehensive TLS/SSL security check with grading
          </p>
        </div>
        <div class="flex gap-2">
          <button
            v-if="comprehensiveCheck"
            class="btn-secondary btn-sm"
            :disabled="comprehensiveLoading"
            @click="runComprehensiveCheck(true)"
          >
            <span
              class="material-symbols-rounded"
              :class="{ 'animate-spin': comprehensiveLoading }"
            >refresh</span>
            {{ comprehensiveLoading ? 'Scanning…' : 'Re-scan' }}
          </button>
          <button
            v-if="!comprehensiveCheck"
            class="btn-primary btn-sm"
            :disabled="comprehensiveLoading"
            @click="runComprehensiveCheck(false)"
          >
            <span v-if="comprehensiveLoading" class="spinner-sm mr-1" />
            <span v-else class="material-symbols-rounded">security</span>
            {{ comprehensiveLoading ? 'Analyzing…' : 'Run Analysis' }}
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div
        v-if="comprehensiveLoading && !comprehensiveCheck"
        class="text-center py-8"
      >
        <div class="spinner mx-auto mb-4" />
        <p class="text-surface-500">Running comprehensive SSL analysis…</p>
        <p class="text-xs text-surface-400 mt-1">This may take 30-60 seconds</p>
      </div>

      <!-- Results -->
      <div v-else-if="comprehensiveCheck" class="space-y-6">
        <!-- Grade tile -->
        <div class="flex items-center gap-6 flex-wrap">
          <div
            :class="[
              'w-24 h-24 rounded-2xl flex items-center justify-center shrink-0',
              getGradeBgColor(comprehensiveCheck.grade),
            ]"
          >
            <span
              :class="[
                'text-4xl font-bold',
                getGradeColor(comprehensiveCheck.grade),
              ]"
            >
              {{ comprehensiveCheck.grade || '—' }}
            </span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 mb-2 flex-wrap">
              <span class="text-lg font-semibold">
                Security Score: {{ comprehensiveCheck.score ?? '—' }}/100
              </span>
              <span
                v-if="comprehensiveCheck.from_cache || comprehensiveCheck.from_database"
                class="text-xs text-surface-400 bg-surface-100 dark:bg-surface-700 px-2 py-1 rounded-full"
              >
                {{ comprehensiveCheck.from_cache ? 'Cached' : 'Saved' }}
              </span>
            </div>
            <p class="text-sm text-surface-500">
              Scanned {{ comprehensiveCheck.scanned_at || comprehensiveCheck.checked_at || '—' }}
              <span v-if="comprehensiveCheck.scan_duration">
                ({{ comprehensiveCheck.scan_duration }}s)
              </span>
            </p>
          </div>
        </div>

        <!-- Protocol Support -->
        <div v-if="Object.keys(filteredProtocols).length">
          <h5 class="font-medium text-sm mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">dns</span>
            Protocol Support
          </h5>
          <div class="grid grid-cols-2 gap-2">
            <div
              v-for="(info, proto) in filteredProtocols"
              :key="proto"
              :class="[
                'p-3 rounded-lg text-center text-sm',
                info.supported
                  ? (info.secure
                    ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400'
                    : 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400')
                  : 'bg-surface-100 dark:bg-surface-700 text-surface-400'
              ]"
            >
              <div class="font-mono font-medium">{{ proto }}</div>
              <div class="text-xs mt-1">
                <span
                  v-if="info.supported"
                  class="material-symbols-rounded text-sm align-middle"
                >
                  {{ info.secure ? 'check_circle' : 'warning' }}
                </span>
                <span
                  v-else
                  class="material-symbols-rounded text-sm align-middle"
                >remove</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Vulnerabilities -->
        <div v-if="Object.keys(comprehensiveCheck.vulnerabilities || {}).length">
          <h5 class="font-medium text-sm mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">bug_report</span>
            Vulnerability Scan
          </h5>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            <div
              v-for="(vuln, id) in comprehensiveCheck.vulnerabilities"
              :key="id"
              :class="[
                'p-3 rounded-lg text-sm',
                vuln.vulnerable
                  ? 'bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30'
                  : 'bg-green-50 dark:bg-green-500/10'
              ]"
            >
              <div class="flex items-center justify-between">
                <span class="font-medium">{{ vuln.name || id }}</span>
                <span
                  :class="[
                    'material-symbols-rounded text-sm',
                    vuln.vulnerable ? 'text-red-500' : 'text-green-500'
                  ]"
                >
                  {{ vuln.vulnerable ? 'error' : 'check_circle' }}
                </span>
              </div>
              <div
                v-if="vuln.vulnerable && vuln.severity"
                class="text-xs text-red-600 dark:text-red-400 mt-1"
              >
                {{ vuln.severity }}
              </div>
            </div>
          </div>
        </div>

        <!-- Cipher Configuration -->
        <div v-if="comprehensiveCheck.ciphers">
          <h5 class="font-medium text-sm mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">enhanced_encryption</span>
            Cipher Configuration
          </h5>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div
              :class="[
                'p-3 rounded-lg text-center',
                comprehensiveCheck.ciphers.forward_secrecy
                  ? 'bg-green-50 dark:bg-green-500/10'
                  : 'bg-amber-50 dark:bg-amber-500/10'
              ]"
            >
              <span
                :class="[
                  'material-symbols-rounded text-2xl',
                  comprehensiveCheck.ciphers.forward_secrecy
                    ? 'text-green-600'
                    : 'text-amber-600'
                ]"
              >
                {{ comprehensiveCheck.ciphers.forward_secrecy ? 'lock' : 'lock_open' }}
              </span>
              <p class="text-xs mt-1 font-medium">Forward Secrecy</p>
            </div>
            <div
              :class="[
                'p-3 rounded-lg text-center',
                comprehensiveCheck.ciphers.aead
                  ? 'bg-green-50 dark:bg-green-500/10'
                  : 'bg-surface-100 dark:bg-surface-700'
              ]"
            >
              <span
                :class="[
                  'material-symbols-rounded text-2xl',
                  comprehensiveCheck.ciphers.aead
                    ? 'text-green-600'
                    : 'text-surface-400'
                ]"
              >
                {{ comprehensiveCheck.ciphers.aead ? 'verified_user' : 'shield' }}
              </span>
              <p class="text-xs mt-1 font-medium">AEAD Ciphers</p>
            </div>
            <div
              :class="[
                'p-3 rounded-lg text-center',
                (comprehensiveCheck.ciphers.insecure?.length || 0) === 0
                  ? 'bg-green-50 dark:bg-green-500/10'
                  : 'bg-red-50 dark:bg-red-500/10'
              ]"
            >
              <span
                :class="[
                  'material-symbols-rounded text-2xl',
                  (comprehensiveCheck.ciphers.insecure?.length || 0) === 0
                    ? 'text-green-600'
                    : 'text-red-600'
                ]"
              >
                {{ (comprehensiveCheck.ciphers.insecure?.length || 0) === 0 ? 'check_circle' : 'error' }}
              </span>
              <p class="text-xs mt-1 font-medium">
                {{ (comprehensiveCheck.ciphers.insecure?.length || 0) === 0 ? 'No Insecure' : `${comprehensiveCheck.ciphers.insecure.length} Insecure` }}
              </p>
            </div>
            <div
              :class="[
                'p-3 rounded-lg text-center',
                (comprehensiveCheck.ciphers.weak?.length || 0) === 0
                  ? 'bg-green-50 dark:bg-green-500/10'
                  : 'bg-amber-50 dark:bg-amber-500/10'
              ]"
            >
              <span
                :class="[
                  'material-symbols-rounded text-2xl',
                  (comprehensiveCheck.ciphers.weak?.length || 0) === 0
                    ? 'text-green-600'
                    : 'text-amber-600'
                ]"
              >
                {{ (comprehensiveCheck.ciphers.weak?.length || 0) === 0 ? 'check_circle' : 'warning' }}
              </span>
              <p class="text-xs mt-1 font-medium">
                {{ (comprehensiveCheck.ciphers.weak?.length || 0) === 0 ? 'No Weak' : `${comprehensiveCheck.ciphers.weak.length} Weak` }}
              </p>
            </div>
          </div>
        </div>

        <!-- Security Headers -->
        <div v-if="Object.keys(filteredSecurityHeaders).length">
          <h5 class="font-medium text-sm mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">policy</span>
            Security Headers
          </h5>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            <div
              v-for="(header, key) in filteredSecurityHeaders"
              :key="key"
              :class="[
                'p-3 rounded-lg text-center text-sm',
                header.present
                  ? 'bg-green-50 dark:bg-green-500/10'
                  : 'bg-surface-100 dark:bg-surface-700'
              ]"
            >
              <span
                :class="[
                  'material-symbols-rounded text-lg',
                  header.present ? 'text-green-600' : 'text-surface-400'
                ]"
              >
                {{ header.present ? 'check_circle' : 'remove_circle' }}
              </span>
              <p class="text-xs mt-1 font-medium uppercase">
                {{ String(key).replace(/_/g, ' ') }}
              </p>
            </div>
          </div>
        </div>

        <!-- Deductions / Auto Fix -->
        <div v-if="comprehensiveCheck.deductions?.length">
          <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h5 class="font-medium text-sm flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">format_list_bulleted</span>
              Issues &amp; How to Fix
            </h5>
            <div class="flex gap-2">
              <button
                class="btn-primary btn-sm"
                :disabled="fixingSslConfig"
                @click="fixSslConfig"
              >
                <span v-if="fixingSslConfig" class="spinner-sm mr-1" />
                <span v-else class="material-symbols-rounded">auto_fix_high</span>
                {{ fixingSslConfig ? 'Fixing…' : 'Auto Fix All' }}
              </button>
              <button
                v-if="hasFixableSecurityHeaders"
                class="btn-secondary btn-sm"
                @click="showSecurityHeadersFix = !showSecurityHeadersFix"
              >
                <span class="material-symbols-rounded">code</span>
                {{ showSecurityHeadersFix ? 'Hide Code' : 'Manual Fix' }}
              </button>
            </div>
          </div>

          <div
            v-if="showSecurityHeadersFix"
            class="mb-4 p-4 bg-surface-800 rounded-xl"
          >
            <p class="text-sm text-surface-300 mb-3">
              Add this to your vhost config (Config tab → Edit Config) inside the
              <code class="text-primary-400">context / {</code> block:
            </p>
            <pre class="text-xs text-green-400 bg-surface-900 p-3 rounded-lg overflow-x-auto"><code># Security Headers
rewrite  {
  rules                   &lt;&lt;END_RULES
RewriteRule .* - [E=HSTS:max-age=31536000;includeSubDomains;preload]
RewriteRule .* - [E=XFO:SAMEORIGIN]
RewriteRule .* - [E=XCTO:nosniff]
RewriteRule .* - [E=XSS:1;mode=block]
RewriteRule .* - [E=RP:strict-origin-when-cross-origin]
END_RULES
}
extraHeaders            Strict-Transport-Security: %{HSTS}e\n\
                        X-Frame-Options: %{XFO}e\n\
                        X-Content-Type-Options: %{XCTO}e\n\
                        X-XSS-Protection: %{XSS}e\n\
                        Referrer-Policy: %{RP}e</code></pre>
            <p class="text-xs text-surface-400 mt-2">
              After adding, click "Save" and reload OpenLiteSpeed. Then re-scan SSL.
            </p>
          </div>

          <div class="space-y-2">
            <div
              v-for="(ded, idx) in comprehensiveCheck.deductions"
              :key="idx"
              :class="[
                'p-3 rounded-lg text-sm',
                ded.severity === 'bonus'
                  ? 'bg-green-50 dark:bg-green-500/10'
                  : ded.severity === 'critical'
                    ? 'bg-red-50 dark:bg-red-500/10'
                    : ded.severity === 'high'
                      ? 'bg-orange-50 dark:bg-orange-500/10'
                      : ded.severity === 'medium'
                        ? 'bg-amber-50 dark:bg-amber-500/10'
                        : 'bg-surface-50 dark:bg-surface-800'
              ]"
            >
              <div class="flex items-center justify-between mb-1 gap-2">
                <span class="font-medium">{{ ded.reason }}</span>
                <span
                  :class="[
                    'font-mono font-medium shrink-0',
                    ded.points > 0 ? 'text-green-600' : 'text-red-600'
                  ]"
                >
                  {{ ded.points > 0 ? '+' : '' }}{{ ded.points }}
                </span>
              </div>
              <p class="text-xs text-surface-500 dark:text-surface-400">
                {{ getSslFixSuggestion(ded.reason) }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty analysis -->
      <div v-else class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-4xl mb-2 block">security</span>
        <p>Run a comprehensive SSL analysis to check protocol support,</p>
        <p>vulnerabilities, and cipher configuration.</p>
      </div>
    </div>

    <!-- ─── Issue / Renew SSL modal ─── -->
    <Modal
      :show="issueSslModal.show"
      title="Issue SSL Certificate"
      size="lg"
      @close="closeIssueSslModal"
    >
      <div class="space-y-4">
        <!-- Initial — no DNS test, no error -->
        <div
          v-if="!issueSslModal.error && !issueSslModal.dnsTest"
          class="text-surface-600 dark:text-surface-400"
        >
          <p>
            Issue a Let's Encrypt SSL certificate for
            <strong>{{ domain }}</strong>?
          </p>
          <p class="text-sm mt-2">
            Make sure DNS is properly configured and pointing to this server.
          </p>

          <div class="mt-4 p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <div class="flex items-center justify-between gap-2 flex-wrap">
              <div>
                <p class="font-medium text-sm">Test DNS Configuration</p>
                <p class="text-xs text-surface-500">
                  Check if all domains resolve correctly before issuing
                </p>
              </div>
              <button
                class="btn-secondary btn-sm"
                :disabled="issueSslModal.dnsTestLoading"
                @click="runDnsTest"
              >
                <span v-if="issueSslModal.dnsTestLoading" class="spinner-sm mr-1" />
                <span v-else class="material-symbols-rounded text-sm mr-1">troubleshoot</span>
                {{ issueSslModal.dnsTestLoading ? 'Testing…' : 'Test DNS' }}
              </button>
            </div>
            <div class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
              <p class="text-xs text-surface-500 flex items-start gap-2">
                <span class="material-symbols-rounded text-sm mt-0.5">info</span>
                <span>
                  This test checks if <strong>{{ domain }}</strong>,
                  <strong>www.{{ domain }}</strong>, and
                  <strong>mail.{{ domain }}</strong> resolve correctly from multiple
                  DNS sources. The certificate will include all domains that pass
                  the test.
                </span>
              </p>
            </div>
          </div>
        </div>

        <!-- DNS test results -->
        <div v-if="issueSslModal.dnsTest" class="space-y-4">
          <div class="p-3 bg-surface-100 dark:bg-surface-800 rounded-xl text-sm">
            <div class="flex items-center justify-between gap-2 flex-wrap">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">dns</span>
                <span>
                  Server IP:
                  <strong>{{ issueSslModal.dnsTest.server_ip || '—' }}</strong>
                </span>
              </div>
            </div>
          </div>

          <div class="border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
              <thead class="bg-surface-50 dark:bg-surface-800">
                <tr>
                  <th class="px-4 py-2 text-left font-medium">Domain</th>
                  <th class="px-4 py-2 text-center font-medium">Local</th>
                  <th class="px-4 py-2 text-center font-medium">Auth NS</th>
                  <th class="px-4 py-2 text-center font-medium">External</th>
                  <th class="px-4 py-2 text-center font-medium">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-surface-200 dark:divide-surface-700">
                <tr
                  v-for="(result, domainName) in issueSslModal.dnsTest.domains"
                  :key="domainName"
                  class="hover:bg-surface-50 dark:hover:bg-surface-800/50"
                >
                  <td class="px-4 py-2 font-mono text-xs">{{ domainName }}</td>
                  <td class="px-4 py-2 text-center">
                    <span
                      v-if="result.local"
                      class="material-symbols-rounded text-sm text-green-500"
                    >check_circle</span>
                    <span
                      v-else
                      class="material-symbols-rounded text-sm text-red-500"
                    >cancel</span>
                  </td>
                  <td class="px-4 py-2 text-center">
                    <span
                      v-if="result.authoritative"
                      class="material-symbols-rounded text-sm text-green-500"
                    >check_circle</span>
                    <span
                      v-else
                      class="material-symbols-rounded text-sm text-red-500"
                    >cancel</span>
                  </td>
                  <td class="px-4 py-2 text-center">
                    <span
                      v-if="result.external"
                      class="material-symbols-rounded text-sm text-green-500"
                    >check_circle</span>
                    <span
                      v-else
                      class="material-symbols-rounded text-sm text-amber-500"
                      title="External DNS (8.8.8.8) not resolving — may be negative cached"
                    >warning</span>
                  </td>
                  <td class="px-4 py-2 text-center">
                    <span
                      v-if="result.ready"
                      class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full
                             bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400"
                    >
                      <span class="material-symbols-rounded text-sm">check</span> Ready
                    </span>
                    <span
                      v-else
                      class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full
                             bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400"
                    >
                      <span class="material-symbols-rounded text-sm">close</span> Not Ready
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-3 bg-green-50 dark:bg-green-500/10 rounded-xl">
              <p class="text-xs font-medium text-green-700 dark:text-green-400 mb-2">
                Will Include in Certificate
              </p>
              <ul class="text-xs space-y-1">
                <li
                  v-for="d in issueSslModal.dnsTest.will_include"
                  :key="d"
                  class="flex items-center gap-1 text-green-600 dark:text-green-300"
                >
                  <span class="material-symbols-rounded text-sm">check</span>
                  {{ d }}
                </li>
                <li
                  v-if="!issueSslModal.dnsTest.will_include?.length"
                  class="text-surface-500"
                >None</li>
              </ul>
            </div>
            <div class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl">
              <p class="text-xs font-medium text-amber-700 dark:text-amber-400 mb-2">
                Will Skip
              </p>
              <ul class="text-xs space-y-1">
                <li
                  v-for="item in issueSslModal.dnsTest.will_skip"
                  :key="item.domain"
                  class="text-amber-600 dark:text-amber-300"
                >
                  <span class="font-medium">{{ item.domain }}</span>
                  <span class="text-surface-500 block text-[10px]">{{ item.reason }}</span>
                </li>
                <li
                  v-if="!issueSslModal.dnsTest.will_skip?.length"
                  class="text-surface-500"
                >None — all domains ready!</li>
              </ul>
            </div>
          </div>

          <div
            v-if="issueSslModal.dnsTest.summary?.warnings?.length"
            class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-200 dark:border-amber-500/20"
          >
            <p class="text-xs font-medium text-amber-700 dark:text-amber-400 mb-2 flex items-center gap-1">
              <span class="material-symbols-rounded text-sm">info</span>
              Notes
            </p>
            <ul class="text-xs space-y-1 text-amber-600 dark:text-amber-300">
              <li
                v-for="(warning, idx) in issueSslModal.dnsTest.summary.warnings"
                :key="idx"
              >
                {{ warning }}
              </li>
            </ul>
          </div>

          <div class="flex justify-center gap-3 flex-wrap">
            <button
              class="btn-secondary btn-sm"
              :disabled="issueSslModal.dnsTestLoading || issueSslModal.dnsSyncing"
              @click="runDnsTest"
            >
              <span v-if="issueSslModal.dnsTestLoading" class="spinner-sm mr-1" />
              <span v-else class="material-symbols-rounded text-sm mr-1">refresh</span>
              {{ issueSslModal.dnsTestLoading ? 'Testing…' : 'Retest DNS' }}
            </button>
            <button
              class="btn-primary btn-sm"
              :disabled="issueSslModal.dnsTestLoading || issueSslModal.dnsSyncing"
              title="Force sync DNS records to all nameservers"
              @click="syncDns"
            >
              <span v-if="issueSslModal.dnsSyncing" class="spinner-sm mr-1" />
              <span v-else class="material-symbols-rounded text-sm mr-1">sync</span>
              {{ issueSslModal.dnsSyncing ? 'Syncing…' : 'Sync DNS' }}
            </button>
          </div>
        </div>

        <!-- Error state -->
        <div v-if="issueSslModal.error" class="space-y-4">
          <div class="p-4 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-200 dark:border-amber-500/20">
            <div class="flex items-start gap-3">
              <span class="material-symbols-rounded text-amber-500 text-xl">schedule</span>
              <div>
                <p class="font-medium text-amber-700 dark:text-amber-400">
                  Waiting for DNS Propagation
                </p>
                <p class="text-sm text-amber-600 dark:text-amber-300 mt-1">
                  {{ issueSslModal.error }}
                </p>
              </div>
            </div>
          </div>

          <div
            v-if="issueSslModal.preflight?.checks"
            class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl"
          >
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
              <p class="text-sm font-medium">Preflight Checks</p>
              <button
                v-if="issueSslModal.preflight?.checks?.dns_resolves === false"
                class="text-xs px-3 py-1.5 rounded-full bg-primary-500 text-white
                       hover:bg-primary-600 transition-colors flex items-center gap-1"
                :disabled="issueSslModal.fixingDns"
                @click="fixDnsFromSslModal"
              >
                <span v-if="issueSslModal.fixingDns" class="spinner-sm" />
                <span v-else class="material-symbols-rounded text-sm">build</span>
                {{ issueSslModal.fixingDns ? 'Fixing…' : 'Fix DNS' }}
              </button>
            </div>
            <div class="space-y-2 text-sm">
              <div
                v-for="(passed, check) in issueSslModal.preflight.checks"
                :key="check"
                class="flex items-center justify-between"
              >
                <span class="text-surface-600 dark:text-surface-400">
                  {{ formatCheckName(check) }}
                </span>
                <span
                  v-if="passed"
                  class="text-green-500 flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">check_circle</span> OK
                </span>
                <span
                  v-else
                  class="text-red-500 flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">cancel</span> Failed
                </span>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-between text-sm text-surface-500">
            <div class="flex items-center gap-4">
              <span v-if="sslWaitingTime">
                <span class="material-symbols-rounded text-sm align-middle">timer</span>
                Waiting: {{ sslWaitingTime }}
              </span>
              <span v-if="issueSslModal.retryCount > 0">
                Attempts: {{ issueSslModal.retryCount }}
              </span>
            </div>
          </div>

          <label class="flex items-center justify-between p-3 bg-surface-100 dark:bg-surface-800/50 rounded-xl cursor-pointer">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-primary-500">autorenew</span>
              <div>
                <p class="font-medium text-sm">Auto-retry every minute</p>
                <p class="text-xs text-surface-500">Automatically retry until DNS propagates</p>
              </div>
            </div>
            <input
              type="checkbox"
              class="w-5 h-5"
              :checked="issueSslModal.autoRetry"
              @change="toggleSslAutoRetry($event.target.checked)"
            />
          </label>

          <div
            v-if="issueSslModal.autoRetry"
            class="flex items-center gap-2 text-sm text-primary-500"
          >
            <span class="spinner-sm" />
            <span>Auto-retry active — will retry in ~60 seconds</span>
          </div>
        </div>
      </div>

      <template #footer>
        <button class="btn-secondary" @click="closeIssueSslModal">
          {{ issueSslModal.error ? 'Close' : 'Cancel' }}
        </button>
        <button
          class="btn-primary"
          :disabled="submitting || (issueSslModal.dnsTest && issueSslModal.dnsTest.ready_to_issue === false)"
          @click="issueSsl(false)"
        >
          <span v-if="submitting" class="spinner-sm mr-1" />
          <span
            v-else
            class="material-symbols-rounded mr-1"
          >{{ issueSslModal.error ? 'refresh' : 'lock' }}</span>
          {{ issueSslModal.error ? 'Retry Now' : 'Issue Certificate' }}
        </button>
      </template>
    </Modal>
  </div>
</template>
