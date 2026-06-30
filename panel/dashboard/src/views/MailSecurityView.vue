<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()

const tabs = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'threats', label: 'Threat Center' },
  { id: 'engine', label: 'Engine' },
  { id: 'antivirus', label: 'Antivirus' },
  { id: 'lists', label: 'Allow / Block' },
  { id: 'userlists', label: 'Per-user lists' },
  { id: 'attachments', label: 'Attachments' },
  { id: 'antispoof', label: 'Anti-spoofing' },
  { id: 'rules', label: 'Mail flow rules' },
  { id: 'geoip', label: 'Geo-IP' },
  { id: 'quarantine', label: 'Quarantine' },
  { id: 'auth', label: 'SPF / DKIM / DMARC' },
  { id: 'score', label: 'Security Score' },
  { id: 'learning', label: 'Learning' },
  { id: 'ai', label: 'AI Analysis' },
  { id: 'virustotal', label: 'VirusTotal' },
  { id: 'reports', label: 'Reports' },
]
const activeTab = ref('dashboard')

const loading = ref(true)
const busy = ref(false)
const syncing = ref(false)

const status = ref(null)
const overview = ref(null)
const scoreForm = ref({ spam_score: 6, reject_score: 15 })

const whitelist = ref([])
const blacklist = ref([])
const newWhite = ref({ type: 'domain', value: '', description: '' })
const newBlack = ref({ type: 'domain', value: '', action: 'reject', description: '' })

const attachmentPolicies = ref([])
const newAttachment = ref({ extension: '', list_type: 'block', action: 'quarantine' })

const userListAvailable = ref(true)
const userListUsers = ref([])
const userSearch = ref('')
const manageEmail = ref('')
const selectedUser = ref(null)
const userLists = ref({ blocked: [], safe: [] })
const userListsLoading = ref(false)
const newUserBlocked = ref({ value: '', apply_domain: false })
const newUserSafe = ref({ value: '', apply_domain: false })

const quarantine = ref({ items: [], total: 0 })
const quarantineSearch = ref('')
const retentionSettings = ref({
  quarantine_retention_days: 30,
  quarantine_digest_to: '',
  quarantine_user_digest_enabled: false,
  quarantine_link_base: '',
  quarantine_link_ttl_days: 7,
})
const retentionLoaded = ref(false)
const retentionBusy = ref(false)
const maintenanceBusy = ref(false)
const userDigestBusy = ref(false)

const importForm = ref({ kind: 'whitelist', csv: '', action: 'reject' })
const importBusy = ref(false)

const impersonation = ref({ entries: [], hosted_domains: [] })
const impersonationLoaded = ref(false)
const impersonationBusy = ref(false)
const newImpersonation = ref({ kind: 'vip_name', value: '', note: '' })
const lookalike = ref({ enabled: true, sensitivity: 'medium' })
const lookalikeBusy = ref(false)

const rules = ref([])
const rulesMode = ref('monitor')
const rulesLoaded = ref(false)
const rulesBusy = ref(false)
const blankRule = () => ({
  id: null,
  name: '',
  enabled: true,
  priority: 100,
  action: 'tag',
  action_arg: '',
  conditions: [{ field: 'from', op: 'domain_is', value: '', name: '' }],
})
const ruleForm = ref(blankRule())
const ruleEditing = ref(false)
const ruleFieldOps = {
  from: ['domain_is', 'equals', 'contains', 'regex'],
  to: ['domain_is', 'equals', 'contains', 'regex'],
  subject: ['contains', 'equals', 'regex'],
  header: ['contains', 'exists', 'regex'],
  score: ['gte'],
  symbol: ['has'],
  attachment: ['ext', 'regex'],
  size: ['gte'],
}

const geoip = ref({ enabled: false, mode: 'deny', countries: '', action: 'reject', domains: [], gateway_mode: 'monitor', hosted_domains: [] })
const geoipLoaded = ref(false)
const geoipBusy = ref(false)
const geoipForm = ref({ enabled: false, mode: 'deny', countries: '', action: 'reject' })
const geoipDomainForm = ref({ domain: '', mode: 'deny', countries: '', action: 'reject' })

const score = ref({ available: true, days: 30, overall: null, distribution: {}, domains: [] })
const scoreLoaded = ref(false)
const scoreLoading = ref(false)
const scoreDays = ref(30)

const threats = ref({ categories: [], severity: {}, total: 0, prev_total: 0, daily: [], recent: [], top_sources: [], top_targets: [] })
const threatsLoaded = ref(false)
const threatsLoading = ref(false)
const threatsDays = ref(30)
const threatFilter = ref({ category: '', severity: '' })

const aiForm = ref({ subject: '', sender: '', content: '' })
const aiBusy = ref(false)
const aiResult = ref(null)
const aiModel = ref('')

const vtConfig = ref({ configured: false, hint: '', cache_ttl_hours: 24 })
const vtConfigForm = ref({ api_key: '', cache_ttl_hours: 24 })
const vtConfigBusy = ref(false)
const vtLoaded = ref(false)
const vtForm = ref({ resource: '', type: 'auto' })
const vtBusy = ref(false)
const vtResult = ref(null)
const vtRecent = ref([])

const learning = ref({
  days: 30,
  loop: {},
  loop_setting: true,
  totals: { spam: 0, ham: 0 },
  source_breakdown: {},
  daily: [],
  recent: [],
  top_users: [],
  webmail: { spam: 0, ham: 0 },
})
const learningLoaded = ref(false)
const learningLoading = ref(false)
const learningBusy = ref(false)
const learningDays = ref(30)

const authLoading = ref(false)
const authLoaded = ref(false)
const authAvailable = ref(true)
const authDomains = ref([])

const reportDays = ref(30)
const reportLoading = ref(false)
const reportLoaded = ref(false)
const reportCsvBusy = ref(false)
const report = ref(null)

const statsLoading = ref(false)
const engineStat = ref(null)
const engineStatAvailable = ref(true)

const clamav = ref(null)
const clamavDetections = ref({ today: 0, week: 0, month: 0, recent: [] })
const clamavLoading = ref(false)
const clamavLoaded = ref(false)
const clamavBusy = ref(false)

const logSource = ref('mail')
const logFilter = ref('')
const logLines = ref([])
const logLoading = ref(false)
const logAvailable = ref(true)
const autoRefresh = ref(false)
let logTimer = null

const engineActions = computed(() => {
  const a = engineStat.value?.actions
  if (!a || typeof a !== 'object') return []
  return Object.entries(a).map(([label, value]) => ({ label, value }))
})

const authSummary = computed(() => {
  const s = { ok: 0, warn: 0, missing: 0 }
  for (const d of authDomains.value) {
    for (const key of ['spf', 'dkim', 'dmarc']) {
      const st = d[key]?.status
      if (st && s[st] !== undefined) s[st]++
    }
  }
  return s
})

function authBadgeClass(status) {
  return status === 'ok' ? 'badge-success' : status === 'warn' ? 'badge-warning' : 'badge-danger'
}

const isMonitorMode = computed(() => !status.value?.milter_wired)

async function loadStatus() {
  try {
    const { data } = await api.get('/mail-security/status')
    status.value = data.data
    if (data.data?.scores) {
      scoreForm.value.spam_score = data.data.scores.add_header ?? 6
      scoreForm.value.reject_score = data.data.scores.reject ?? 15
    }
  } catch (e) {
    status.value = null
  }
}

async function loadOverview() {
  try {
    const { data } = await api.get('/mail-security/overview')
    overview.value = data.data
  } catch (e) {
    overview.value = null
  }
}

async function loadLists() {
  try {
    const [w, b] = await Promise.all([
      api.get('/mail-security/whitelist'),
      api.get('/mail-security/blacklist'),
    ])
    whitelist.value = w.data.data?.entries || []
    blacklist.value = b.data.data?.entries || []
  } catch (e) {
    // leave as-is
  }
}

async function loadAttachments() {
  try {
    const { data } = await api.get('/mail-security/attachment-policy')
    attachmentPolicies.value = data.data?.policies || []
  } catch (e) {
    attachmentPolicies.value = []
  }
}

async function addAttachment() {
  const ext = newAttachment.value.extension.trim().toLowerCase().replace(/^\./, '')
  if (!ext) {
    toast.error('Extension is required')
    return
  }
  try {
    await api.post('/mail-security/attachment-policy', { ...newAttachment.value, extension: ext })
    toast.success('Policy saved')
    newAttachment.value = { extension: '', list_type: 'block', action: 'quarantine' }
    await loadAttachments()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save policy')
  }
}

async function deleteAttachment(id) {
  try {
    await api.delete('/mail-security/attachment-policy', { params: { id } })
    toast.success('Policy removed')
    await loadAttachments()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove policy')
  }
}

async function loadQuarantine() {
  try {
    const { data } = await api.get('/mail-security/quarantine', {
      params: { status: 'quarantined', search: quarantineSearch.value },
    })
    quarantine.value = { items: data.data?.items || [], total: data.data?.total || 0 }
  } catch (e) {
    quarantine.value = { items: [], total: 0 }
  }
}

async function releaseQuarantine(id) {
  try {
    await api.post('/mail-security/quarantine/release', { id })
    toast.success('Message released for delivery')
    await Promise.all([loadQuarantine(), loadOverview()])
  } catch (e) {
    toast.error(e.response?.data?.error || 'Release failed')
  }
}

async function deleteQuarantine(id) {
  if (!confirm('Permanently delete this quarantined message?')) return
  try {
    await api.delete('/mail-security/quarantine', { params: { id } })
    toast.success('Message deleted')
    await Promise.all([loadQuarantine(), loadOverview()])
  } catch (e) {
    toast.error(e.response?.data?.error || 'Delete failed')
  }
}

async function loadRetentionSettings() {
  try {
    const { data } = await api.get('/mail-security/settings')
    const s = data.data?.settings || {}
    retentionSettings.value = {
      quarantine_retention_days: Number(s.quarantine_retention_days ?? 30) || 30,
      quarantine_digest_to: s.quarantine_digest_to || '',
      quarantine_user_digest_enabled: String(s.quarantine_user_digest_enabled ?? '0') === '1',
      quarantine_link_base: s.quarantine_link_base || '',
      quarantine_link_ttl_days: Number(s.quarantine_link_ttl_days ?? 7) || 7,
    }
    retentionLoaded.value = true
  } catch (e) {
    // settings are best-effort; leave defaults
  }
}

async function saveRetentionSettings() {
  retentionBusy.value = true
  try {
    const days = Math.max(1, Number(retentionSettings.value.quarantine_retention_days) || 30)
    await api.put('/mail-security/settings', {
      settings: {
        quarantine_retention_days: String(days),
        quarantine_digest_to: (retentionSettings.value.quarantine_digest_to || '').trim(),
      },
    })
    retentionSettings.value.quarantine_retention_days = days
    toast.success('Retention settings saved')
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    retentionBusy.value = false
  }
}

async function saveUserDigestSettings() {
  const enabled = !!retentionSettings.value.quarantine_user_digest_enabled
  const base = (retentionSettings.value.quarantine_link_base || '').trim().replace(/\/+$/, '')
  if (enabled && !/^https?:\/\/.+/i.test(base)) {
    toast.error('Enter the panel base URL (e.g. https://panel.example.com) before enabling user digests')
    return
  }
  const ttl = Math.min(90, Math.max(1, Number(retentionSettings.value.quarantine_link_ttl_days) || 7))
  userDigestBusy.value = true
  try {
    await api.put('/mail-security/settings', {
      settings: {
        quarantine_user_digest_enabled: enabled ? '1' : '0',
        quarantine_link_base: base,
        quarantine_link_ttl_days: String(ttl),
      },
    })
    retentionSettings.value.quarantine_link_base = base
    retentionSettings.value.quarantine_link_ttl_days = ttl
    toast.success('Self-service digest settings saved')
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    userDigestBusy.value = false
  }
}

async function runQuarantineMaintenance() {
  if (!confirm('Run quarantine cleanup now?\n\nRemoves held messages older than the retention window and (if a digest address is set) sends the summary email. This also runs automatically every day. Continue?')) return
  maintenanceBusy.value = true
  try {
    const { data } = await api.post('/mail-security/quarantine/maintenance')
    const d = data.data || {}
    toast.success(`Cleanup done — expired ${d.expired ?? 0}, purged ${d.purged_rows ?? 0}, swept ${d.orphans_swept ?? 0}`)
    await Promise.all([loadQuarantine(), loadOverview()])
  } catch (e) {
    toast.error(e.response?.data?.error || 'Maintenance failed')
  } finally {
    maintenanceBusy.value = false
  }
}

async function installEngine() {
  busy.value = true
  try {
    const { data } = await api.post('/mail-security/install', {
      spam_score: scoreForm.value.spam_score,
      reject_score: scoreForm.value.reject_score,
    })
    toast.success(data.message || 'Engine installed (monitor-only)')
    await loadStatus()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Install failed')
  } finally {
    busy.value = false
  }
}

// Re-run the managed installer on an already-installed engine to (re)write the
// managed configs (thresholds, ClamAV antivirus, controller bind) and ensure the
// quarantine transport + resolver are present. Fail-open: mail keeps flowing.
async function repairEngine() {
  if (!confirm('Re-provision the engine?\n\nThis rewrites the managed Rspamd configs (spam thresholds, ClamAV antivirus, controller) and ensures the quarantine transport + resolver are in place. Rspamd/ClamAV/Redis are restarted; mail keeps flowing (fail-open). Continue?')) return
  busy.value = true
  try {
    const { data } = await api.post('/mail-security/install', {
      spam_score: scoreForm.value.spam_score,
      reject_score: scoreForm.value.reject_score,
    })
    toast.success(data.message || 'Engine re-provisioned')
    await loadStatus()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Re-provision failed')
  } finally {
    busy.value = false
  }
}

async function engineAction(verb) {
  busy.value = true
  try {
    await api.post(`/mail-security/${verb}`)
    toast.success(`Rspamd ${verb}`)
    await loadStatus()
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to ${verb}`)
  } finally {
    busy.value = false
  }
}

async function wireDelivery() {
  if (!confirm('Connect Rspamd into LIVE mail delivery now?\n\nInbound mail will be scanned and spam routed to quarantine. This is fail-open (a Rspamd outage will not block mail), but it changes live delivery. Continue?')) return
  busy.value = true
  try {
    const { data } = await api.post('/mail-security/delivery/wire', { confirm: true })
    toast.success(data.message || 'Delivery wired')
    await loadStatus()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Wiring failed')
  } finally {
    busy.value = false
  }
}

async function unwireDelivery() {
  if (!confirm('Disconnect Rspamd from live mail delivery?\n\nMail will revert to passing through untouched (monitor-only). Continue?')) return
  busy.value = true
  try {
    const { data } = await api.post('/mail-security/delivery/unwire', { confirm: true })
    toast.success(data.message || 'Delivery unwired')
    await loadStatus()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Unwiring failed')
  } finally {
    busy.value = false
  }
}

async function saveScores() {
  busy.value = true
  try {
    await api.put('/mail-security/scores', {
      spam_score: scoreForm.value.spam_score,
      reject_score: scoreForm.value.reject_score,
    })
    toast.success('Scores updated')
    await loadStatus()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update scores')
  } finally {
    busy.value = false
  }
}

async function addEntry(kind) {
  const payload = kind === 'whitelist' ? { ...newWhite.value } : { ...newBlack.value }
  if (!payload.value) {
    toast.error('Value is required')
    return
  }
  try {
    await api.post(`/mail-security/${kind}`, payload)
    toast.success('Entry added')
    if (kind === 'whitelist') newWhite.value = { type: 'domain', value: '', description: '' }
    else newBlack.value = { type: 'domain', value: '', action: 'reject', description: '' }
    await loadLists()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add entry')
  }
}

async function deleteEntry(kind, id) {
  try {
    await api.delete(`/mail-security/${kind}`, { params: { id } })
    toast.success('Entry removed')
    await loadLists()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove entry')
  }
}

// ---- Quick allow-list from report / dashboard widgets --------------------
// The report's "Top spam senders" can surface legitimate clients that were
// caught as false positives. These helpers let an admin allow-list a sender
// (or its whole domain) in one click without leaving the report.

const allowBusy = ref(null) // value currently being added (for button state)

const allowedValues = computed(
  () => new Set((whitelist.value || []).map(e => String(e.value || '').toLowerCase()))
)

function senderDomain(value) {
  const v = String(value || '').toLowerCase()
  const at = v.lastIndexOf('@')
  return at !== -1 ? v.slice(at + 1) : (v.includes('.') ? v : '')
}

// A sender counts as allowed if its exact address OR its domain is allow-listed.
function isAllowed(value) {
  const v = String(value || '').toLowerCase()
  if (!v) return false
  if (allowedValues.value.has(v)) return true
  const dom = senderDomain(v)
  return dom ? allowedValues.value.has(dom) : false
}

async function allowSender(value, asDomain = false) {
  const raw = String(value || '').toLowerCase().trim()
  if (!raw) return
  const isEmail = raw.includes('@')
  const type = (asDomain || !isEmail) ? 'domain' : 'email'
  const toAdd = type === 'domain' ? (senderDomain(raw) || raw) : raw
  if (!toAdd) return
  allowBusy.value = raw
  try {
    await api.post('/mail-security/whitelist', {
      type,
      value: toAdd,
      description: 'Allow-listed from report (false positive)',
    })
    toast.success(`Allow-listed ${type === 'domain' ? 'domain ' : ''}${toAdd}`)
    await loadLists()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to allow-list sender')
  } finally {
    allowBusy.value = null
  }
}

// ---- Quick block-list from detection tables ------------------------------
// Adds a sender (or its whole domain) to the global block list so future mail
// is rejected at delivery. Used from the Antivirus "Detected viruses" table.

const blockBusy = ref(null)

const blockedValues = computed(
  () => new Set((blacklist.value || []).map(e => String(e.value || '').toLowerCase()))
)

// Blocked if the exact address OR its domain is on the block list.
function isBlocked(value) {
  const v = String(value || '').toLowerCase()
  if (!v) return false
  if (blockedValues.value.has(v)) return true
  const dom = senderDomain(v)
  return dom ? blockedValues.value.has(dom) : false
}

async function blockSender(value, asDomain = false) {
  const raw = String(value || '').toLowerCase().trim()
  if (!raw) return
  const isEmail = raw.includes('@')
  const type = (asDomain || !isEmail) ? 'domain' : 'email'
  const toAdd = type === 'domain' ? (senderDomain(raw) || raw) : raw
  if (!toAdd) return
  if (!confirm(`Block ${type === 'domain' ? 'the entire domain ' : ''}${toAdd}? All future mail from it will be rejected at delivery.`)) return
  blockBusy.value = raw
  try {
    await api.post('/mail-security/blacklist', {
      type,
      value: toAdd,
      action: 'reject',
      description: 'Blocked from virus detections',
    })
    toast.success(`Blocked ${type === 'domain' ? 'domain ' : ''}${toAdd}`)
    await loadLists()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to block sender')
  } finally {
    blockBusy.value = null
  }
}

async function loadImpersonation() {
  try {
    const { data } = await api.get('/mail-security/impersonation')
    impersonation.value = {
      entries: data.data?.entries || [],
      hosted_domains: data.data?.hosted_domains || [],
    }
    impersonationLoaded.value = true
  } catch (e) {
    impersonation.value = { entries: [], hosted_domains: [] }
  }
}

async function addImpersonation() {
  const value = (newImpersonation.value.value || '').trim()
  if (!value) {
    toast.error('Value is required')
    return
  }
  impersonationBusy.value = true
  try {
    await api.post('/mail-security/impersonation', { ...newImpersonation.value, value })
    toast.success('Entry added and synced to engine')
    newImpersonation.value = { kind: newImpersonation.value.kind, value: '', note: '' }
    await loadImpersonation()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add entry')
  } finally {
    impersonationBusy.value = false
  }
}

async function deleteImpersonation(id) {
  try {
    await api.delete('/mail-security/impersonation', { params: { id } })
    toast.success('Entry removed')
    await loadImpersonation()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove entry')
  }
}

async function loadLookalikeSettings() {
  try {
    const { data } = await api.get('/mail-security/settings')
    const s = data.data?.settings || {}
    if ('lookalike_enabled' in s) {
      lookalike.value.enabled = ['1', 'true', 'yes', 'on'].includes(String(s.lookalike_enabled).toLowerCase())
    }
    const sens = String(s.lookalike_sensitivity || 'medium').toLowerCase()
    lookalike.value.sensitivity = ['low', 'medium', 'high'].includes(sens) ? sens : 'medium'
  } catch (e) {
    // best-effort; keep defaults (enabled / medium)
  }
}

async function saveLookalike() {
  lookalikeBusy.value = true
  try {
    await api.put('/mail-security/settings', {
      settings: {
        lookalike_enabled: lookalike.value.enabled ? '1' : '0',
        lookalike_sensitivity: lookalike.value.sensitivity,
      },
    })
    toast.success('Lookalike settings saved — applied to the engine')
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save lookalike settings')
  } finally {
    lookalikeBusy.value = false
  }
}

async function loadRules() {
  try {
    const { data } = await api.get('/mail-security/rules')
    rules.value = data.data?.rules || []
    rulesMode.value = data.data?.mode || 'monitor'
    rulesLoaded.value = true
  } catch (e) {
    rules.value = []
  }
}

function addRuleCondition() {
  ruleForm.value.conditions.push({ field: 'from', op: 'domain_is', value: '', name: '' })
}

function removeRuleCondition(i) {
  ruleForm.value.conditions.splice(i, 1)
}

function onConditionFieldChange(cond) {
  const ops = ruleFieldOps[cond.field] || []
  if (!ops.includes(cond.op)) cond.op = ops[0] || 'contains'
}

function editRule(r) {
  ruleForm.value = {
    id: r.id,
    name: r.name,
    enabled: !!r.enabled,
    priority: r.priority,
    action: r.action,
    action_arg: r.action_arg || '',
    conditions: (r.conditions && r.conditions.length)
      ? r.conditions.map(c => ({ field: c.field, op: c.op, value: c.value ?? '', name: c.name ?? '' }))
      : [{ field: 'from', op: 'domain_is', value: '', name: '' }],
  }
  ruleEditing.value = true
  if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'smooth' })
}

function cancelRuleEdit() {
  ruleForm.value = blankRule()
  ruleEditing.value = false
}

async function saveRule() {
  const f = ruleForm.value
  if (!f.name.trim()) {
    toast.error('Rule name is required')
    return
  }
  const conditions = f.conditions
    .filter(c => c.field && c.op && (c.op === 'exists' || String(c.value).trim() !== ''))
    .map(c => {
      const out = { field: c.field, op: c.op, value: String(c.value ?? '').trim() }
      if (c.field === 'header') out.name = String(c.name ?? '').trim()
      return out
    })
  const payload = {
    name: f.name.trim(),
    enabled: f.enabled,
    priority: Number(f.priority) || 100,
    action: f.action,
    action_arg: (f.action_arg || '').trim(),
    conditions,
  }
  rulesBusy.value = true
  try {
    if (f.id) {
      await api.put('/mail-security/rules', { id: f.id, ...payload })
      toast.success('Rule saved')
    } else {
      await api.post('/mail-security/rules', payload)
      toast.success('Rule created')
    }
    cancelRuleEdit()
    await loadRules()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save rule')
  } finally {
    rulesBusy.value = false
  }
}

async function toggleRule(r) {
  try {
    await api.put('/mail-security/rules', {
      id: r.id,
      name: r.name,
      enabled: !r.enabled,
      priority: r.priority,
      action: r.action,
      action_arg: r.action_arg || '',
      conditions: r.conditions || [],
    })
    await loadRules()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update rule')
  }
}

async function deleteRule(id) {
  if (!confirm('Delete this mail flow rule?')) return
  try {
    await api.delete('/mail-security/rules', { params: { id } })
    toast.success('Rule removed')
    await loadRules()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove rule')
  }
}

function ruleSummary(r) {
  const conds = r.conditions || []
  if (!conds.length) return 'matches all mail'
  return conds.map(c => {
    const f = c.field === 'header' ? `header ${c.name}` : c.field
    return `${f} ${c.op}${c.op === 'exists' ? '' : ' "' + c.value + '"'}`
  }).join(' AND ')
}

async function loadGeoip() {
  try {
    const { data } = await api.get('/mail-security/geoip')
    const d = data.data || {}
    geoip.value = {
      enabled: !!d.enabled,
      mode: d.mode || 'deny',
      countries: d.countries || '',
      action: d.action || 'reject',
      domains: d.domains || [],
      gateway_mode: d.gateway_mode || 'monitor',
      hosted_domains: d.hosted_domains || [],
    }
    geoipForm.value = { enabled: !!d.enabled, mode: d.mode || 'deny', countries: d.countries || '', action: d.action || 'reject' }
  } catch (e) {
    // leave defaults
  } finally {
    geoipLoaded.value = true
  }
}

async function saveGeoip() {
  geoipBusy.value = true
  try {
    const { data } = await api.put('/mail-security/geoip', { ...geoipForm.value })
    if (data.data?.countries !== undefined) geoipForm.value.countries = data.data.countries
    toast.success('Geo-IP policy saved')
    await loadGeoip()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save Geo-IP policy')
  } finally {
    geoipBusy.value = false
  }
}

async function addGeoipDomain() {
  const f = geoipDomainForm.value
  if (!f.domain.trim()) { toast.error('Recipient domain is required'); return }
  if (!f.countries.trim()) { toast.error('Add at least one ISO country code (e.g. CN, RU)'); return }
  geoipBusy.value = true
  try {
    await api.post('/mail-security/geoip/domain', { ...f, domain: f.domain.trim() })
    toast.success('Domain override saved')
    geoipDomainForm.value = { domain: '', mode: 'deny', countries: '', action: 'reject' }
    await loadGeoip()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save domain override')
  } finally {
    geoipBusy.value = false
  }
}

async function deleteGeoipDomain(id) {
  if (!confirm('Remove this domain override?')) return
  try {
    await api.delete('/mail-security/geoip/domain', { params: { id } })
    toast.success('Domain override removed')
    await loadGeoip()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove domain override')
  }
}

async function loadScore() {
  scoreLoading.value = true
  try {
    const { data } = await api.get('/mail-security/security-score', { params: { days: scoreDays.value } })
    score.value = data.data || { domains: [] }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load security score')
  } finally {
    scoreLoading.value = false
    scoreLoaded.value = true
  }
}

function gradeText(grade) {
  return {
    A: 'text-emerald-600 dark:text-emerald-400',
    B: 'text-green-600 dark:text-green-400',
    C: 'text-amber-600 dark:text-amber-400',
    D: 'text-orange-600 dark:text-orange-400',
    F: 'text-red-600 dark:text-red-400',
  }[grade] || 'text-surface-500'
}

function barColor(points, max) {
  const pct = max ? points / max : 0
  if (pct >= 0.9) return 'bg-emerald-500'
  if (pct >= 0.6) return 'bg-amber-500'
  if (pct > 0) return 'bg-orange-500'
  return 'bg-red-500'
}

function barPct(points, max) {
  return (max ? Math.max(0, Math.min(1, points / max)) : 0) * 100
}

function recSeverityClass(sev) {
  return {
    high: 'badge-danger',
    medium: 'badge-warning',
    ok: 'badge-success',
  }[sev] || 'badge-info'
}

async function loadThreats() {
  threatsLoading.value = true
  try {
    const { data } = await api.get('/mail-security/threat-center', { params: { days: threatsDays.value } })
    threats.value = data.data || threats.value
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load Threat Center')
  } finally {
    threatsLoading.value = false
    threatsLoaded.value = true
  }
}

const filteredThreats = computed(() => {
  const f = threatFilter.value
  return (threats.value.recent || []).filter(r =>
    (!f.category || r.category === f.category) && (!f.severity || r.severity === f.severity)
  )
})

function severityClass(sev) {
  return {
    critical: 'badge-danger',
    high: 'badge-danger',
    medium: 'badge-warning',
    low: 'badge-info',
  }[sev] || 'badge-info'
}

function severityText(sev) {
  return {
    critical: 'text-red-600 dark:text-red-400',
    high: 'text-orange-600 dark:text-orange-400',
    medium: 'text-amber-600 dark:text-amber-400',
    low: 'text-surface-500 dark:text-surface-400',
  }[sev] || 'text-surface-500'
}

function trendDelta(cur, prev) {
  const d = (cur || 0) - (prev || 0)
  if (d > 0) return { text: '+' + d, cls: 'text-red-600 dark:text-red-400' }
  if (d < 0) return { text: String(d), cls: 'text-emerald-600 dark:text-emerald-400' }
  return { text: '±0', cls: 'text-surface-400' }
}

function fmtTs(ts) {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T'))
  return isNaN(d) ? String(ts) : d.toLocaleString()
}

async function runAiAnalysis() {
  if (!aiForm.value.content.trim() && !aiForm.value.subject.trim()) {
    toast.error('Paste the email content (or at least a subject) to analyze.')
    return
  }
  aiBusy.value = true
  aiResult.value = null
  try {
    const { data } = await api.post('/mail-security/ai-analyze', {
      subject: aiForm.value.subject,
      sender: aiForm.value.sender,
      content: aiForm.value.content,
    })
    aiResult.value = data.data.analysis
    aiModel.value = data.data.model || ''
  } catch (e) {
    toast.error(e.response?.data?.error || 'AI analysis failed')
  } finally {
    aiBusy.value = false
  }
}

function clearAi() {
  aiForm.value = { subject: '', sender: '', content: '' }
  aiResult.value = null
  aiModel.value = ''
}

function verdictClass(v) {
  return { phishing: 'badge-danger', suspicious: 'badge-warning', likely_safe: 'badge-success' }[v] || 'badge-info'
}

function verdictLabel(v) {
  return { phishing: 'Phishing', suspicious: 'Suspicious', likely_safe: 'Likely safe', unknown: 'Inconclusive' }[v] || v
}

function scoreColor(s) {
  if (s === null || s === undefined) return 'text-surface-500'
  if (s >= 70) return 'text-red-600 dark:text-red-400'
  if (s >= 40) return 'text-amber-600 dark:text-amber-400'
  return 'text-emerald-600 dark:text-emerald-400'
}

async function loadVirustotal() {
  try {
    const [{ data: cfg }, { data: recent }] = await Promise.all([
      api.get('/mail-security/virustotal/config'),
      api.get('/mail-security/virustotal/recent'),
    ])
    vtConfig.value = cfg.data || vtConfig.value
    vtConfigForm.value.cache_ttl_hours = vtConfig.value.cache_ttl_hours || 24
    vtRecent.value = recent.data?.items || []
  } catch (e) {
    // best-effort
  } finally {
    vtLoaded.value = true
  }
}

async function saveVirustotalConfig() {
  vtConfigBusy.value = true
  try {
    const payload = { cache_ttl_hours: Number(vtConfigForm.value.cache_ttl_hours) || 24 }
    if (vtConfigForm.value.api_key.trim()) payload.api_key = vtConfigForm.value.api_key.trim()
    await api.put('/mail-security/virustotal/config', payload)
    vtConfigForm.value.api_key = ''
    toast.success('VirusTotal settings saved')
    await loadVirustotal()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save VirusTotal settings')
  } finally {
    vtConfigBusy.value = false
  }
}

async function runVirustotal(force = false) {
  if (!vtForm.value.resource.trim()) {
    toast.error('Enter a URL or file hash to check.')
    return
  }
  vtBusy.value = true
  vtResult.value = null
  try {
    const { data } = await api.post('/mail-security/virustotal/check', {
      resource: vtForm.value.resource.trim(),
      type: vtForm.value.type,
      force,
    })
    vtResult.value = data.data.result
    loadVirustotal()
  } catch (e) {
    toast.error(e.response?.data?.error || 'VirusTotal check failed')
  } finally {
    vtBusy.value = false
  }
}

function vtVerdictClass(v) {
  return {
    malicious: 'badge-danger',
    suspicious: 'badge-warning',
    harmless: 'badge-success',
    pending: 'badge-info',
    unknown: 'badge-info',
  }[v] || 'badge-info'
}

function vtVerdictLabel(v) {
  return {
    malicious: 'Malicious', suspicious: 'Suspicious', harmless: 'Clean',
    pending: 'Queued', unknown: 'Unknown',
  }[v] || v
}

function vtFlagSummary(r) {
  if (!r || r.verdict === 'pending' || r.total <= 0) return ''
  if (r.malicious > 0) return `${r.malicious} of ${r.total} engines flagged this as malicious`
  if (r.suspicious > 0) return `${r.suspicious} of ${r.total} engines flagged this as suspicious (0 malicious)`
  return `Clean — 0 of ${r.total} engines flagged this`
}

function vtHeuristicNote(r) {
  if (!r || r.malicious > 0) return ''
  if (r.suspicious === 1) return 'Only one engine flagged this — usually a predictive/heuristic false positive for legitimate sites.'
  if (r.suspicious >= 2) return `${r.suspicious} engines flagged this heuristically, but none detected actual malware.`
  return ''
}

const impersonationGroups = computed(() => {
  const g = { vip_name: [], protected_domain: [], exempt_sender: [] }
  for (const e of impersonation.value.entries) {
    if (g[e.kind]) g[e.kind].push(e)
  }
  return g
})

async function importList() {
  const csv = (importForm.value.csv || '').trim()
  if (!csv) {
    toast.error('Paste CSV content first')
    return
  }
  importBusy.value = true
  try {
    const payload = { csv }
    if (importForm.value.kind === 'blacklist') payload.action = importForm.value.action
    const { data } = await api.post(`/mail-security/${importForm.value.kind}/import`, payload)
    const d = data.data || {}
    toast.success(`Imported ${d.imported ?? 0}, skipped ${d.skipped ?? 0}`)
    importForm.value.csv = ''
    await loadLists()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Import failed')
  } finally {
    importBusy.value = false
  }
}

async function exportList(kind) {
  try {
    const res = await api.get(`/mail-security/${kind}.csv`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `mailsec-${kind}-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    toast.error('Export failed')
  }
}

async function syncEngine() {
  syncing.value = true
  try {
    await api.post('/mail-security/sync')
    toast.success('Lists synced to Rspamd')
  } catch (e) {
    toast.error(e.response?.data?.error || 'Sync failed')
  } finally {
    syncing.value = false
  }
}

async function loadUserListUsers() {
  try {
    const { data } = await api.get('/mail-security/user-lists/users', {
      params: { search: userSearch.value },
    })
    userListAvailable.value = data.data?.available !== false
    userListUsers.value = data.data?.users || []
  } catch (e) {
    userListUsers.value = []
  }
}

async function selectUser(email) {
  if (!email) return
  selectedUser.value = email
  userListsLoading.value = true
  try {
    const { data } = await api.get('/mail-security/user-lists', { params: { user: email } })
    userLists.value = { blocked: data.data?.blocked || [], safe: data.data?.safe || [] }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load user lists')
    userLists.value = { blocked: [], safe: [] }
  } finally {
    userListsLoading.value = false
  }
}

function manageLookup() {
  const email = manageEmail.value.trim().toLowerCase()
  if (!email) return
  selectUser(email)
}

function resyncToast(resync) {
  if (resync && resync.synced) {
    toast.success('Saved and mailbox resynced')
  } else {
    toast.success('Saved')
    if (resync && resync.warning) {
      toast.error('Mailbox resync pending: ' + resync.warning)
    }
  }
}

async function addUserEntry(kind) {
  const form = kind === 'blocked' ? newUserBlocked.value : newUserSafe.value
  if (!form.value) {
    toast.error('Sender email is required')
    return
  }
  try {
    const { data } = await api.post(`/mail-security/user-lists/${kind}`, {
      user: selectedUser.value,
      value: form.value,
      apply_domain: form.apply_domain,
    })
    resyncToast(data.data?.resync)
    if (kind === 'blocked') newUserBlocked.value = { value: '', apply_domain: false }
    else newUserSafe.value = { value: '', apply_domain: false }
    await selectUser(selectedUser.value)
    loadUserListUsers()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add entry')
  }
}

async function deleteUserEntry(kind, id) {
  try {
    const { data } = await api.delete(`/mail-security/user-lists/${kind}`, {
      params: { user: selectedUser.value, id },
    })
    resyncToast(data.data?.resync)
    await selectUser(selectedUser.value)
    loadUserListUsers()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove entry')
  }
}

async function loadAuth() {
  authLoading.value = true
  try {
    const { data } = await api.get('/mail-security/auth-status')
    authAvailable.value = data.data?.available !== false
    authDomains.value = data.data?.domains || []
    authLoaded.value = true
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load auth status')
  } finally {
    authLoading.value = false
  }
}

async function loadReport() {
  reportLoading.value = true
  try {
    const { data } = await api.get('/mail-security/report', { params: { days: reportDays.value } })
    report.value = data.data
    reportLoaded.value = true
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load report')
  } finally {
    reportLoading.value = false
  }
}

async function loadClamav() {
  clamavLoading.value = true
  try {
    const { data } = await api.get('/mail-security/clamav')
    clamav.value = data.data?.clamav || null
    clamavDetections.value = data.data?.detections || { today: 0, week: 0, month: 0, recent: [] }
    clamavLoaded.value = true
  } catch (e) {
    clamav.value = null
  } finally {
    clamavLoading.value = false
  }
}

async function updateSignatures() {
  clamavBusy.value = true
  try {
    const { data } = await api.post('/mail-security/clamav/update')
    if (data.data?.clamav) clamav.value = data.data.clamav
    toast.success('ClamAV signatures updated')
  } catch (e) {
    toast.error(e.response?.data?.error || 'Signature update failed')
  } finally {
    clamavBusy.value = false
  }
}

async function restartClamav() {
  if (!confirm('Restart the ClamAV daemon? Scanning briefly pauses (mail keeps flowing fail-open).')) return
  clamavBusy.value = true
  try {
    const { data } = await api.post('/mail-security/clamav/restart')
    if (data.data?.clamav) clamav.value = data.data.clamav
    toast.success('ClamAV restarted')
  } catch (e) {
    toast.error(e.response?.data?.error || 'Restart failed')
  } finally {
    clamavBusy.value = false
  }
}

async function downloadReportCsv() {
  reportCsvBusy.value = true
  try {
    const res = await api.get('/mail-security/report.csv', {
      params: { days: reportDays.value },
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `mail-security-report-${reportDays.value}d.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    toast.error('CSV export failed')
  } finally {
    reportCsvBusy.value = false
  }
}

async function loadEngineStats() {
  statsLoading.value = true
  try {
    const { data } = await api.get('/mail-security/engine-stats')
    engineStatAvailable.value = data.data?.available !== false
    engineStat.value = data.data?.stat || null
  } catch (e) {
    engineStatAvailable.value = false
    engineStat.value = null
  } finally {
    statsLoading.value = false
  }
}

async function loadMailLog() {
  logLoading.value = true
  try {
    const { data } = await api.get('/mail-security/logs', {
      params: { source: logSource.value, lines: 150, filter: logFilter.value },
    })
    logAvailable.value = data.data?.available !== false
    logLines.value = data.data?.lines || []
  } catch (e) {
    logLines.value = []
  } finally {
    logLoading.value = false
  }
}

function stopAutoRefresh() {
  if (logTimer) {
    clearInterval(logTimer)
    logTimer = null
  }
  autoRefresh.value = false
}

function toggleAutoRefresh() {
  if (autoRefresh.value) {
    stopAutoRefresh()
    return
  }
  autoRefresh.value = true
  loadMailLog()
  logTimer = setInterval(loadMailLog, 5000)
}

function changeLogSource() {
  loadMailLog()
}

async function loadLearning() {
  learningLoading.value = true
  try {
    const { data } = await api.get('/mail-security/learning', { params: { days: learningDays.value } })
    learning.value = data.data || learning.value
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load learning activity')
  } finally {
    learningLoading.value = false
    learningLoaded.value = true
  }
}

async function toggleLearningLoop(target) {
  learningBusy.value = true
  try {
    await api.put('/mail-security/learning', { enabled: target })
    toast.success(target ? 'Learning loop enabled' : 'Learning loop disabled')
    await loadLearning()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update learning loop')
  } finally {
    learningBusy.value = false
  }
}

async function toggleBayesAutolearn(target) {
  learningBusy.value = true
  try {
    await api.put('/mail-security/learning/autolearn', { enabled: target })
    toast.success(target ? 'Bayes autolearn enabled' : 'Bayes autolearn disabled')
    await loadLearning()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update Bayes autolearn')
  } finally {
    learningBusy.value = false
  }
}

function learnDirectionClass(direction) {
  return direction === 'spam'
    ? 'badge-danger'
    : direction === 'ham'
      ? 'badge-success'
      : 'badge-info'
}

function learnSourceLabel(src) {
  return {
    imapsieve: 'IMAP client',
    webmail: 'Webmail',
    autolearn: 'Auto-learn',
    admin: 'Admin',
  }[src] || src
}

const learnSourcesView = computed(() => {
  const out = []
  const order = ['imapsieve', 'webmail', 'autolearn', 'admin']
  const seen = new Set()
  for (const key of order) {
    const v = learning.value.source_breakdown?.[key]
    if (v) { out.push({ source: key, ...v }); seen.add(key) }
  }
  for (const key of Object.keys(learning.value.source_breakdown || {})) {
    if (!seen.has(key)) out.push({ source: key, ...(learning.value.source_breakdown[key] || {}) })
  }
  return out
})

const learnDailyMax = computed(() => {
  let m = 0
  for (const d of (learning.value.daily || [])) {
    m = Math.max(m, (d.spam || 0) + (d.ham || 0))
  }
  return m
})

watch(activeTab, (tab, prev) => {
  if (tab === 'auth' && !authLoaded.value) loadAuth()
  if (tab === 'reports' && !reportLoaded.value) loadReport()
  if (tab === 'antivirus' && !clamavLoaded.value) loadClamav()
  if (tab === 'antispoof' && !impersonationLoaded.value) { loadImpersonation(); loadLookalikeSettings() }
  if (tab === 'rules' && !rulesLoaded.value) loadRules()
  if (tab === 'geoip' && !geoipLoaded.value) loadGeoip()
  if (tab === 'score' && !scoreLoaded.value) loadScore()
  if (tab === 'threats' && !threatsLoaded.value) loadThreats()
  if (tab === 'virustotal' && !vtLoaded.value) loadVirustotal()
  if (tab === 'learning' && !learningLoaded.value) loadLearning()
  if (tab === 'quarantine' && !retentionLoaded.value) loadRetentionSettings()
  if (tab === 'engine') {
    loadEngineStats()
    loadMailLog()
  }
  if (prev === 'engine') stopAutoRefresh()
})

onMounted(async () => {
  loading.value = true
  await Promise.all([loadStatus(), loadOverview(), loadLists(), loadAttachments(), loadQuarantine(), loadUserListUsers()])
  loading.value = false
})

onUnmounted(stopAutoRefresh)
</script>

<template>
  <div class="p-4 sm:p-6">
    <!-- Page header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Mail Security</h1>
        <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">Rspamd + ClamAV gateway, quarantine and policies</p>
      </div>
      <span v-if="status" class="badge" :class="isMonitorMode ? 'badge-warning' : 'badge-success'">
        <span class="status-dot" :class="isMonitorMode ? 'unknown' : 'running'"></span>
        {{ isMonitorMode ? 'Monitor-only (delivery unaffected)' : 'Active (filtering live mail)' }}
      </span>
    </div>

    <!-- Tabs: wrap onto multiple rows so every tab is visible without a
         horizontal scrollbar (this view has many tabs; important on laptops
         like a MacBook Air where a single scrolling row hides options). -->
    <div class="mb-6 border-b border-surface-200 dark:border-surface-700 pb-3">
      <nav class="flex flex-wrap gap-1.5">
        <button
          v-for="t in tabs"
          :key="t.id"
          @click="activeTab = t.id"
          class="px-2.5 sm:px-3 py-1.5 text-xs sm:text-sm font-medium rounded-lg whitespace-nowrap transition-colors"
          :class="activeTab === t.id
            ? 'bg-primary-500 text-white shadow-sm'
            : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]'"
        >
          {{ t.label }}
        </button>
      </nav>
    </div>

    <div v-if="loading" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
      <span class="spinner-sm"></span> Loading…
    </div>

    <!-- DASHBOARD -->
    <div v-else-if="activeTab === 'dashboard'" class="space-y-4 sm:space-y-6">
      <div class="grid-responsive-4">
        <div class="stat-card">
          <div class="stat-value">{{ overview?.messages_today ?? 0 }}</div>
          <div class="stat-label">Messages today</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ overview?.spam_today ?? 0 }}</div>
          <div class="stat-label">Spam blocked today</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ overview?.quarantined ?? 0 }}</div>
          <div class="stat-label">In quarantine</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ overview?.virus_today ?? 0 }}</div>
          <div class="stat-label">Virus detections today</div>
        </div>
      </div>

      <div class="grid-responsive-3">
        <div class="stat-card">
          <div class="stat-value text-2xl">{{ overview?.spf_fail_today ?? 0 }}</div>
          <div class="stat-label">SPF failures today</div>
        </div>
        <div class="stat-card">
          <div class="stat-value text-2xl">{{ overview?.dkim_fail_today ?? 0 }}</div>
          <div class="stat-label">DKIM failures today</div>
        </div>
        <div class="stat-card">
          <div class="stat-value text-2xl">{{ overview?.dmarc_fail_today ?? 0 }}</div>
          <div class="stat-label">DMARC failures today</div>
        </div>
      </div>

      <div class="grid-responsive-2">
        <div class="card">
          <div class="card-header-responsive">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Top flagged senders (7d)</h3>
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">Senders marked spam/rejected. Use Allow for legitimate contacts.</p>
          </div>
          <div class="card-body-responsive">
            <p v-if="!overview?.top_senders?.length" class="text-sm text-surface-400">No data yet.</p>
            <ul class="text-sm divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
              <li v-for="s in overview?.top_senders" :key="s.sender" class="flex items-center gap-2 py-2 first:pt-0">
                <span class="min-w-0 flex-1 truncate font-mono text-xs text-surface-700 dark:text-surface-300" :title="s.sender">{{ s.sender }}</span>
                <span class="shrink-0 tabular-nums text-surface-500 dark:text-surface-400">{{ s.cnt }}</span>
                <span v-if="isAllowed(s.sender)" class="shrink-0 badge badge-success">Allowed</span>
                <button
                  v-else
                  @click="allowSender(s.sender)"
                  :disabled="allowBusy === String(s.sender).toLowerCase()"
                  class="shrink-0 btn btn-ghost btn-sm"
                  title="Add this address to the global allow list"
                >Allow</button>
              </li>
            </ul>
          </div>
        </div>
        <div class="card">
          <div class="card-header-responsive">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Most-targeted domains (7d)</h3>
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">Your hosted domains receiving the most spam (recipients, not senders).</p>
          </div>
          <div class="card-body-responsive">
            <p v-if="!overview?.top_domains?.length" class="text-sm text-surface-400">No data yet.</p>
            <ul class="text-sm divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
              <li v-for="d in overview?.top_domains" :key="d.domain" class="flex items-center justify-between gap-3 py-2 first:pt-0">
                <span class="min-w-0 flex-1 truncate font-mono text-xs text-surface-700 dark:text-surface-300" :title="d.domain">{{ d.domain }}</span>
                <span class="shrink-0 tabular-nums text-surface-500 dark:text-surface-400">{{ d.cnt }}</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- THREAT CENTER -->
    <div v-else-if="activeTab === 'threats'" class="space-y-4 sm:space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-surface-500 dark:text-surface-400 max-w-3xl">
          Detected threats across all domains, bucketed by severity and category (malware, phishing, impersonation, spam, policy, auth). Sourced from the live scan history.
        </p>
        <div class="flex items-center gap-2">
          <select v-model.number="threatsDays" @change="loadThreats" class="input max-w-[10rem]">
            <option :value="7">Last 7 days</option>
            <option :value="30">Last 30 days</option>
            <option :value="90">Last 90 days</option>
          </select>
          <button @click="loadThreats" :disabled="threatsLoading" class="btn btn-secondary btn-sm">{{ threatsLoading ? 'Loading…' : 'Refresh' }}</button>
        </div>
      </div>

      <div v-if="threatsLoading && !threats.recent.length" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
        <span class="spinner-sm"></span> Aggregating threats…
      </div>

      <template v-else>
        <!-- Severity summary -->
        <div class="grid-responsive-4">
          <div class="stat-card"><div class="stat-value text-red-600 dark:text-red-400">{{ threats.severity.critical || 0 }}</div><div class="stat-label">Critical</div></div>
          <div class="stat-card"><div class="stat-value text-orange-600 dark:text-orange-400">{{ threats.severity.high || 0 }}</div><div class="stat-label">High</div></div>
          <div class="stat-card"><div class="stat-value text-amber-600 dark:text-amber-400">{{ threats.severity.medium || 0 }}</div><div class="stat-label">Medium</div></div>
          <div class="stat-card"><div class="stat-value text-surface-500 dark:text-surface-400">{{ threats.severity.low || 0 }}</div><div class="stat-label">Low</div></div>
        </div>

        <!-- Total + trend -->
        <div class="card">
          <div class="card-body-responsive flex flex-wrap items-center gap-x-8 gap-y-2">
            <div>
              <span class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ threats.total }}</span>
              <span class="text-sm text-surface-500 dark:text-surface-400 ml-2">threats in the last {{ threats.days }} days</span>
            </div>
            <div class="text-sm">
              vs previous {{ threats.days }} days:
              <span :class="trendDelta(threats.total, threats.prev_total).cls" class="font-semibold">{{ trendDelta(threats.total, threats.prev_total).text }}</span>
            </div>
          </div>
        </div>

        <!-- Category breakdown -->
        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">By category</h3></div>
          <div class="card-body-responsive">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
              <div v-for="c in threats.categories" :key="c.key"
                   class="rounded-lg border border-surface-200 dark:border-surface-700 p-3 cursor-pointer transition-colors"
                   :class="threatFilter.category === c.key ? 'bg-surface-100 dark:bg-surface-800' : ''"
                   @click="threatFilter.category = threatFilter.category === c.key ? '' : c.key">
                <div class="text-xs uppercase tracking-wide" :class="severityText(c.severity)">{{ c.severity }}</div>
                <div class="text-sm text-surface-600 dark:text-surface-300">{{ c.label }}</div>
                <div class="flex items-baseline gap-2">
                  <span class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ c.count }}</span>
                  <span class="text-xs" :class="trendDelta(c.count, c.prev).cls">{{ trendDelta(c.count, c.prev).text }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent threats -->
        <div class="card">
          <div class="card-header-responsive flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Recent threats</h3>
            <div class="flex items-center gap-2">
              <select v-model="threatFilter.category" class="input max-w-[11rem] text-sm">
                <option value="">All categories</option>
                <option v-for="c in threats.categories" :key="c.key" :value="c.key">{{ c.label }}</option>
              </select>
              <select v-model="threatFilter.severity" class="input max-w-[10rem] text-sm">
                <option value="">All severities</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
              </select>
            </div>
          </div>
          <div class="card-body-responsive">
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr><th>When</th><th>Severity</th><th>Category</th><th>From</th><th>To / domain</th><th>Score</th><th>Signal</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(r, i) in filteredThreats" :key="i">
                    <td class="whitespace-nowrap text-xs text-surface-500 dark:text-surface-400">{{ fmtTs(r.ts) }}</td>
                    <td><span class="badge" :class="severityClass(r.severity)">{{ r.severity }}</span></td>
                    <td class="text-sm">{{ r.category }}</td>
                    <td class="text-xs text-surface-600 dark:text-surface-300 truncate max-w-[14rem]" :title="r.sender">{{ r.sender || '—' }}</td>
                    <td class="text-xs text-surface-600 dark:text-surface-300 truncate max-w-[14rem]" :title="r.recipient">{{ r.recipient || r.domain || '—' }}</td>
                    <td class="text-xs font-mono">{{ r.score !== null ? r.score.toFixed(1) : '—' }}</td>
                    <td class="text-xs text-surface-500 dark:text-surface-400 truncate max-w-[12rem]" :title="r.symbol">{{ r.symbol || r.event_type }}</td>
                  </tr>
                  <tr v-if="!filteredThreats.length"><td colspan="7" class="text-surface-400">No threats match the current filters in this period.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Top sources + targets -->
        <div class="grid-responsive-2">
          <div class="card">
            <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Top threat sources</h3></div>
            <div class="card-body-responsive">
              <p v-if="!threats.top_sources.length" class="text-sm text-surface-400">No data yet.</p>
              <ul class="text-sm space-y-2">
                <li v-for="s in threats.top_sources" :key="s.value" class="flex justify-between gap-4">
                  <span class="truncate text-surface-700 dark:text-surface-300">{{ s.value }}</span>
                  <span class="text-surface-500 dark:text-surface-400">{{ s.count }}</span>
                </li>
              </ul>
            </div>
          </div>
          <div class="card">
            <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Most-targeted domains</h3></div>
            <div class="card-body-responsive">
              <p v-if="!threats.top_targets.length" class="text-sm text-surface-400">No data yet.</p>
              <ul class="text-sm space-y-2">
                <li v-for="t in threats.top_targets" :key="t.value" class="flex justify-between gap-4">
                  <span class="truncate text-surface-700 dark:text-surface-300">{{ t.value }}</span>
                  <span class="text-surface-500 dark:text-surface-400">{{ t.count }}</span>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Daily breakdown -->
        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Daily breakdown</h3></div>
          <div class="card-body-responsive">
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>Date</th><th>Total</th><th>Malware</th><th>Phishing</th><th>Impersonation</th><th>Spam</th><th>Policy</th><th>Auth</th></tr></thead>
                <tbody>
                  <tr v-for="d in threats.daily" :key="d.day">
                    <td class="whitespace-nowrap">{{ d.day }}</td>
                    <td class="font-medium">{{ d.total }}</td>
                    <td :class="d.malware ? 'text-red-600 dark:text-red-400' : 'text-surface-400'">{{ d.malware }}</td>
                    <td :class="d.phishing ? 'text-orange-600 dark:text-orange-400' : 'text-surface-400'">{{ d.phishing }}</td>
                    <td :class="d.impersonation ? 'text-orange-600 dark:text-orange-400' : 'text-surface-400'">{{ d.impersonation }}</td>
                    <td>{{ d.spam }}</td>
                    <td>{{ d.policy }}</td>
                    <td class="text-surface-500 dark:text-surface-400">{{ d.auth }}</td>
                  </tr>
                  <tr v-if="!threats.daily.length"><td colspan="8" class="text-surface-400">No threats recorded in this period yet.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ENGINE -->
    <div v-else-if="activeTab === 'engine'" class="space-y-4 sm:space-y-6">
      <div v-if="isMonitorMode" class="card border-amber-300 dark:border-amber-500/40">
        <div class="card-body-responsive flex items-start gap-3">
          <span class="material-symbols-rounded text-amber-500">info</span>
          <p class="text-sm text-surface-600 dark:text-surface-300">
            <b class="text-amber-600 dark:text-amber-400">Monitor-only.</b> Rspamd is installed and scoring mail, but Postfix is not yet pointed at it, so nothing is filtered, quarantined, or rejected. Wiring the milter is a separate canary step.
          </p>
        </div>
      </div>

      <div class="grid-responsive-2">
        <div class="card">
          <div class="card-header-responsive flex items-center gap-2">
            <span class="status-dot" :class="status?.rspamd?.running ? 'running' : 'stopped'"></span>
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Rspamd</h3>
          </div>
          <div class="card-body-responsive space-y-3">
            <p class="text-sm text-surface-600 dark:text-surface-300">
              Installed: <b>{{ status?.rspamd?.installed ? 'yes' : 'no' }}</b> ·
              Running: <b>{{ status?.rspamd?.running ? 'yes' : 'no' }}</b> ·
              Version: <b>{{ status?.rspamd?.version || '-' }}</b>
            </p>
            <div class="action-buttons">
              <button :disabled="busy" @click="engineAction('start')" class="btn btn-secondary btn-sm">Start</button>
              <button :disabled="busy" @click="engineAction('stop')" class="btn btn-secondary btn-sm">Stop</button>
              <button :disabled="busy" @click="engineAction('restart')" class="btn btn-secondary btn-sm">Restart</button>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header-responsive flex items-center gap-2">
            <span class="status-dot" :class="status?.clamav?.daemon_running ? 'running' : 'stopped'"></span>
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">ClamAV</h3>
          </div>
          <div class="card-body-responsive">
            <p class="text-sm text-surface-600 dark:text-surface-300">
              Installed: <b>{{ status?.clamav?.installed ? 'yes' : 'no' }}</b> ·
              Daemon: <b>{{ status?.clamav?.daemon_running ? 'running' : 'stopped' }}</b> ·
              Freshclam: <b>{{ status?.clamav?.freshclam_running ? 'running' : 'stopped' }}</b>
            </p>
          </div>
        </div>
      </div>

      <div v-if="status?.rspamd?.installed" class="card">
        <div class="card-header-responsive flex items-center gap-2">
          <span class="material-symbols-rounded text-surface-400 text-base">verified_user</span>
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">Threat protection</h3>
        </div>
        <div class="card-body-responsive space-y-2">
          <div class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-300">
            <span class="status-dot" :class="status?.protection?.phishing ? 'running' : 'stopped'"></span>
            Phishing detection (heuristics + OpenPhish / Phishtank): <b>{{ status?.protection?.phishing ? 'on' : 'off' }}</b>
          </div>
          <div class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-300">
            <span class="status-dot" :class="status?.protection?.reputation ? 'running' : 'stopped'"></span>
            Sender reputation (IP / SPF / DKIM, Redis-backed): <b>{{ status?.protection?.reputation ? 'on' : 'off' }}</b>
          </div>
          <p class="text-xs text-surface-400">
            Phishing hits show on the Dashboard as <b>phish</b> events. Both only add score (fail-open) — they never reject on their own.
          </p>
        </div>
      </div>

      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Spam score thresholds</h3></div>
        <div class="card-body-responsive space-y-3">
          <div class="flex flex-wrap items-end gap-4">
            <label class="text-sm">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Spam (add header)</span>
              <input v-model.number="scoreForm.spam_score" type="number" step="0.1" class="input max-w-[8rem]" />
            </label>
            <label class="text-sm">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Reject</span>
              <input v-model.number="scoreForm.reject_score" type="number" step="0.1" class="input max-w-[8rem]" />
            </label>
            <button :disabled="busy || !status?.rspamd?.installed" @click="saveScores" class="btn btn-primary btn-sm">Save scores</button>
          </div>
          <p class="text-xs text-surface-400">
            Bayes self-learning and the IMAP "mark as spam" feedback loop are managed on the
            <button @click="activeTab = 'learning'" class="text-primary-600 dark:text-primary-400 hover:underline">Learning tab</button>.
          </p>
        </div>
      </div>

      <div v-if="status?.rspamd?.installed" class="card" :class="isMonitorMode ? '' : 'border-green-300 dark:border-green-500/40'">
        <div class="card-header-responsive flex items-center gap-2">
          <span class="status-dot" :class="isMonitorMode ? 'unknown' : 'running'"></span>
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">Delivery wiring</h3>
        </div>
        <div class="card-body-responsive space-y-4">
          <p class="text-sm text-surface-600 dark:text-surface-300">
            Connects Rspamd into the live inbound mail path (milter) and routes spam to quarantine.
            <b class="text-surface-700 dark:text-surface-200">Fail-open:</b> if Rspamd is down, mail still flows.
          </p>
          <div class="text-sm space-y-1.5 text-surface-600 dark:text-surface-300">
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="status?.delivery?.milter_present ? 'running' : 'stopped'"></span>
              Milter connected: <b>{{ status?.delivery?.milter_present ? 'yes' : 'no' }}</b>
            </div>
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="status?.delivery?.quarantine_routing ? 'running' : 'stopped'"></span>
              Quarantine routing: <b>{{ status?.delivery?.quarantine_routing ? 'active' : 'off' }}</b>
            </div>
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="status?.delivery?.fail_open ? 'running' : 'unknown'"></span>
              Fail-open (milter_default_action): <b>{{ status?.delivery?.milter_default_action || '—' }}</b>
            </div>
          </div>
          <div class="action-buttons">
            <button v-if="isMonitorMode" :disabled="busy || !status?.rspamd?.running" @click="wireDelivery" class="btn btn-primary btn-sm">
              {{ busy ? 'Working…' : 'Wire delivery (go live)' }}
            </button>
            <button v-else :disabled="busy" @click="unwireDelivery" class="btn btn-danger btn-sm">
              {{ busy ? 'Working…' : 'Unwire (back to monitor-only)' }}
            </button>
          </div>
          <p v-if="isMonitorMode" class="text-xs text-surface-400">Engine must be running to wire delivery.</p>
        </div>
      </div>

      <div v-if="status?.rspamd?.installed" class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Maintenance</h3></div>
        <div class="card-body-responsive space-y-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            Re-applies the managed engine configuration (spam thresholds, ClamAV antivirus, controller binding) and ensures the quarantine transport and resolver are in place. Mail keeps flowing during the restart (fail-open). Use this after an upgrade or to repair a partial install.
          </p>
          <button :disabled="busy" @click="repairEngine" class="btn btn-secondary btn-sm">
            {{ busy ? 'Working…' : 'Re-provision engine (repair config)' }}
          </button>
        </div>
      </div>

      <div v-if="status?.rspamd?.installed" class="card">
        <div class="card-header-responsive flex items-center justify-between gap-2">
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">Engine activity</h3>
          <button @click="loadEngineStats" :disabled="statsLoading" class="btn btn-ghost btn-sm">
            {{ statsLoading ? 'Refreshing…' : 'Refresh' }}
          </button>
        </div>
        <div class="card-body-responsive">
          <p v-if="!engineStatAvailable" class="text-sm text-surface-400">Stats unavailable — the engine may be stopped.</p>
          <template v-else>
            <div class="grid-responsive-4">
              <div class="stat-card">
                <div class="stat-value">{{ engineStat?.scanned ?? 0 }}</div>
                <div class="stat-label">Scanned</div>
              </div>
              <div class="stat-card">
                <div class="stat-value">{{ engineStat?.learned ?? 0 }}</div>
                <div class="stat-label">Learned</div>
              </div>
              <div class="stat-card">
                <div class="stat-value">{{ engineStat?.connections ?? 0 }}</div>
                <div class="stat-label">Connections</div>
              </div>
              <div class="stat-card">
                <div class="stat-value">{{ engineStat?.control_connections ?? 0 }}</div>
                <div class="stat-label">Control conns</div>
              </div>
            </div>
            <div v-if="engineActions.length" class="table-responsive mt-4">
              <table class="table">
                <thead><tr><th>Action</th><th class="text-right">Count</th></tr></thead>
                <tbody>
                  <tr v-for="a in engineActions" :key="a.label">
                    <td class="capitalize">{{ a.label }}</td>
                    <td class="text-right">{{ a.value }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
      </div>

      <div v-if="status?.rspamd?.installed" class="card">
        <div class="card-header-responsive flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">Live mail activity</h3>
          <div class="flex flex-wrap items-center gap-2">
            <select v-model="logSource" @change="changeLogSource" class="input max-w-[10rem] btn-sm">
              <option value="mail">Postfix (mail.log)</option>
              <option value="rspamd">Rspamd</option>
            </select>
            <input v-model="logFilter" @keyup.enter="loadMailLog" placeholder="filter…" class="input max-w-[12rem] btn-sm" />
            <button @click="loadMailLog" :disabled="logLoading" class="btn btn-secondary btn-sm">
              {{ logLoading ? 'Loading…' : 'Refresh' }}
            </button>
            <button @click="toggleAutoRefresh" class="btn btn-sm" :class="autoRefresh ? 'btn-primary' : 'btn-ghost'">
              {{ autoRefresh ? 'Auto: on' : 'Auto: off' }}
            </button>
          </div>
        </div>
        <div class="card-body-responsive">
          <p v-if="!logAvailable" class="text-sm text-surface-400">Log file not found on this server.</p>
          <p v-else-if="!logLines.length" class="text-sm text-surface-400">No matching log lines.</p>
          <pre v-else class="text-xs leading-relaxed bg-surface-900 text-surface-100 dark:bg-black/40 rounded-lg p-3 overflow-x-auto max-h-96 overflow-y-auto whitespace-pre-wrap break-all">{{ logLines.join('\n') }}</pre>
        </div>
      </div>

      <div v-if="!status?.rspamd?.installed" class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Install engine</h3></div>
        <div class="card-body-responsive space-y-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">Installs Rspamd + ClamAV in monitor-only mode. Does not modify Postfix. This can take a few minutes.</p>
          <button :disabled="busy" @click="installEngine" class="btn btn-primary">
            <span v-if="busy" class="spinner-sm"></span>
            {{ busy ? 'Installing…' : 'Install (monitor-only)' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ANTIVIRUS (ClamAV) -->
    <div v-else-if="activeTab === 'antivirus'" class="space-y-4 sm:space-y-6">
      <div class="flex items-center justify-between gap-3">
        <p class="text-sm text-surface-500 dark:text-surface-400">
          ClamAV virus scanning runs inside Rspamd (fail-open: a scanner outage never blocks mail).
        </p>
        <div class="flex items-center gap-2">
          <button @click="loadClamav" :disabled="clamavLoading" class="btn btn-secondary btn-sm">
            {{ clamavLoading ? 'Loading…' : 'Refresh' }}
          </button>
        </div>
      </div>

      <div v-if="clamavLoading && !clamav" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
        <span class="spinner-sm"></span> Querying ClamAV…
      </div>

      <div v-else-if="!clamav || !clamav.installed" class="card">
        <div class="card-body-responsive text-surface-500 dark:text-surface-400">
          ClamAV is not installed. Run the engine install from the Engine tab.
        </div>
      </div>

      <template v-else>
        <!-- Health -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="stat-card">
            <div class="stat-label">Scanner daemon</div>
            <div class="stat-value flex items-center gap-2">
              <span class="status-dot" :class="clamav.daemon_running ? 'running' : 'stopped'"></span>
              <span :class="clamav.daemon_running ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                {{ clamav.daemon_running ? 'Running' : 'Stopped' }}
              </span>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Signature updater (freshclam)</div>
            <div class="stat-value flex items-center gap-2">
              <span class="status-dot" :class="clamav.freshclam_running ? 'running' : 'stopped'"></span>
              <span :class="clamav.freshclam_running ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'">
                {{ clamav.freshclam_running ? 'Running' : 'Stopped' }}
              </span>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Rspamd antivirus module</div>
            <div class="stat-value flex items-center gap-2">
              <span class="status-dot" :class="clamav.antivirus_module ? 'running' : 'stopped'"></span>
              <span :class="clamav.antivirus_module ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'">
                {{ clamav.antivirus_module ? 'Wired' : 'Not wired' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Signature database -->
        <div class="card">
          <div class="card-header-responsive flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Signature database</h3>
            <div class="flex flex-wrap items-center gap-2">
              <button @click="updateSignatures" :disabled="clamavBusy" class="btn btn-primary btn-sm">
                {{ clamavBusy ? 'Working…' : 'Update signatures now' }}
              </button>
              <button @click="restartClamav" :disabled="clamavBusy" class="btn btn-ghost btn-sm">Restart ClamAV</button>
            </div>
          </div>
          <div class="card-body-responsive">
            <div class="table-responsive">
              <table class="table">
                <tbody>
                  <tr><td class="text-surface-500 dark:text-surface-400">Engine</td><td class="text-right">{{ clamav.engine_version || '—' }}</td></tr>
                  <tr><td class="text-surface-500 dark:text-surface-400">Signature DB version</td><td class="text-right">{{ clamav.db_version || '—' }}</td></tr>
                  <tr><td class="text-surface-500 dark:text-surface-400">Signature DB date</td><td class="text-right">{{ clamav.db_date || '—' }}</td></tr>
                  <tr><td class="text-surface-500 dark:text-surface-400">Total signatures</td><td class="text-right">{{ clamav.signatures != null ? clamav.signatures.toLocaleString() : '—' }}</td></tr>
                  <tr><td class="text-surface-500 dark:text-surface-400">DB file updated</td><td class="text-right">{{ clamav.db_updated ? new Date(clamav.db_updated).toLocaleString() : '—' }}</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Detections -->
        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Virus detections</h3></div>
          <div class="card-body-responsive">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <div class="stat-card"><div class="stat-value">{{ clamavDetections.today }}</div><div class="stat-label">Today</div></div>
              <div class="stat-card"><div class="stat-value">{{ clamavDetections.week }}</div><div class="stat-label">Last 7 days</div></div>
              <div class="stat-card"><div class="stat-value">{{ clamavDetections.month }}</div><div class="stat-label">Last 30 days</div></div>
              <div class="stat-card"><div class="stat-value">{{ clamav.recent_detections != null ? clamav.recent_detections : '—' }}</div><div class="stat-label">Recent (engine history)</div></div>
            </div>
            <p class="text-xs text-surface-400 mt-3">Daily/weekly/monthly counts come from the mail security event log; "recent" is live from the Rspamd scan history.</p>
          </div>
        </div>

        <!-- Detection detail: which message, who sent it, and what malware -->
        <div class="card">
          <div class="card-header-responsive">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Detected viruses</h3>
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">
              The actual infected messages ClamAV caught (most recent first). Virus mail is rejected at delivery, so it never reaches the mailbox.
            </p>
          </div>
          <div class="card-body-responsive">
            <p v-if="!clamavDetections.recent || !clamavDetections.recent.length" class="text-sm text-surface-400">
              No virus detections recorded yet.
            </p>
            <div v-else class="table-responsive">
              <table class="table">
                <thead>
                  <tr><th>When</th><th>Malware</th><th>From</th><th>To</th><th class="text-right">Block sender</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(r, i) in clamavDetections.recent" :key="i">
                    <td class="whitespace-nowrap text-xs text-surface-500 dark:text-surface-400">{{ fmtTs(r.ts) }}</td>
                    <td class="text-xs"><span class="badge badge-danger font-mono break-all">{{ r.symbol || 'Unknown malware' }}</span></td>
                    <td class="text-xs font-mono text-surface-700 dark:text-surface-300 truncate max-w-[16rem]" :title="r.sender">{{ r.sender || '—' }}</td>
                    <td class="text-xs font-mono text-surface-700 dark:text-surface-300 truncate max-w-[16rem]" :title="r.recipient">{{ r.recipient || r.domain || '—' }}</td>
                    <td class="text-right whitespace-nowrap">
                      <span v-if="isBlocked(r.sender)" class="badge badge-danger">Blocked</span>
                      <div v-else-if="r.sender" class="inline-flex items-center gap-1">
                        <button
                          @click="blockSender(r.sender)"
                          :disabled="blockBusy === String(r.sender).toLowerCase()"
                          class="btn btn-ghost btn-sm text-red-600 dark:text-red-400"
                          title="Reject all future mail from this exact address"
                        >Block</button>
                        <button
                          v-if="senderDomain(r.sender)"
                          @click="blockSender(r.sender, true)"
                          :disabled="blockBusy === String(r.sender).toLowerCase()"
                          class="btn btn-ghost btn-sm text-red-600 dark:text-red-400"
                          :title="'Reject all future mail from the whole domain ' + senderDomain(r.sender)"
                        >+domain</button>
                      </div>
                      <span v-else class="text-surface-400">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- LISTS -->
    <div v-else-if="activeTab === 'lists'" class="space-y-4 sm:space-y-6">
      <div class="card">
        <div class="card-body-responsive flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            Changes save automatically and push to the engine. Use Sync if the engine was offline during an edit.
          </p>
          <button @click="syncEngine" :disabled="syncing" class="btn btn-secondary btn-sm">
            {{ syncing ? 'Syncing…' : 'Sync to engine' }}
          </button>
        </div>
      </div>

      <!-- Bulk import / export -->
      <div class="card">
        <div class="card-header-responsive flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">Import / export (CSV)</h3>
          <div class="flex flex-wrap items-center gap-2">
            <button @click="exportList('whitelist')" class="btn btn-ghost btn-sm">Export allow list</button>
            <button @click="exportList('blacklist')" class="btn btn-ghost btn-sm">Export block list</button>
          </div>
        </div>
        <div class="card-body-responsive space-y-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            One entry per line: <code>value</code>, <code>type,value</code>, or <code>type,value,action,description</code>. Type (email / domain / ip / cidr) is auto-detected when omitted; <code>action</code> applies to the block list only. Blank lines and <code>#</code> comments are ignored. Imported entries are pushed to the engine automatically.
          </p>
          <div class="flex flex-wrap items-center gap-3">
            <select v-model="importForm.kind" class="input max-w-[12rem]">
              <option value="whitelist">Into allow list</option>
              <option value="blacklist">Into block list</option>
            </select>
            <select v-if="importForm.kind === 'blacklist'" v-model="importForm.action" class="input max-w-[12rem]">
              <option value="reject">default action: reject</option>
              <option value="quarantine">default action: quarantine</option>
            </select>
          </div>
          <textarea v-model="importForm.csv" rows="5" placeholder="spammer@example.com&#10;domain,baddomain.tld&#10;ip,203.0.113.5,reject,known abuse source" class="input w-full font-mono text-xs"></textarea>
          <div>
            <button @click="importList" :disabled="importBusy" class="btn btn-primary btn-sm">
              {{ importBusy ? 'Importing…' : 'Import CSV' }}
            </button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Global allow list</h3></div>
        <div class="card-body-responsive space-y-4">
          <div class="flex flex-wrap items-end gap-3">
            <select v-model="newWhite.type" class="input max-w-[8rem]">
              <option value="email">email</option><option value="domain">domain</option>
              <option value="ip">ip</option><option value="cidr">cidr</option>
            </select>
            <input v-model="newWhite.value" placeholder="value" class="input max-w-[16rem]" />
            <input v-model="newWhite.description" placeholder="description (optional)" class="input max-w-[16rem]" />
            <button @click="addEntry('whitelist')" class="btn btn-primary btn-sm">Add</button>
          </div>
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>Type</th><th>Value</th><th>Description</th><th class="text-right">Actions</th></tr></thead>
              <tbody>
                <tr v-for="e in whitelist" :key="e.id">
                  <td><span class="badge badge-neutral">{{ e.type }}</span></td>
                  <td>{{ e.value }}</td>
                  <td class="text-surface-500 dark:text-surface-400">{{ e.description }}</td>
                  <td class="text-right"><button @click="deleteEntry('whitelist', e.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
                </tr>
                <tr v-if="!whitelist.length"><td colspan="4" class="text-surface-400">No entries.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Global block list</h3></div>
        <div class="card-body-responsive space-y-4">
          <div class="flex flex-wrap items-end gap-3">
            <select v-model="newBlack.type" class="input max-w-[8rem]">
              <option value="email">email</option><option value="domain">domain</option>
              <option value="ip">ip</option><option value="cidr">cidr</option>
            </select>
            <input v-model="newBlack.value" placeholder="value" class="input max-w-[16rem]" />
            <select v-model="newBlack.action" class="input max-w-[10rem]">
              <option value="reject">reject</option><option value="quarantine">quarantine</option>
            </select>
            <input v-model="newBlack.description" placeholder="description (optional)" class="input max-w-[16rem]" />
            <button @click="addEntry('blacklist')" class="btn btn-danger btn-sm">Add</button>
          </div>
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>Type</th><th>Value</th><th>Action</th><th>Description</th><th class="text-right">Actions</th></tr></thead>
              <tbody>
                <tr v-for="e in blacklist" :key="e.id">
                  <td><span class="badge badge-neutral">{{ e.type }}</span></td>
                  <td>{{ e.value }}</td>
                  <td><span class="badge" :class="e.action === 'reject' ? 'badge-danger' : 'badge-warning'">{{ e.action }}</span></td>
                  <td class="text-surface-500 dark:text-surface-400">{{ e.description }}</td>
                  <td class="text-right"><button @click="deleteEntry('blacklist', e.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
                </tr>
                <tr v-if="!blacklist.length"><td colspan="5" class="text-surface-400">No entries.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- PER-USER LISTS -->
    <div v-else-if="activeTab === 'userlists'" class="space-y-4 sm:space-y-6">
      <div v-if="!userListAvailable" class="card">
        <div class="card-body-responsive text-surface-500 dark:text-surface-400">
          The Email app isn't detected on this server, so there are no per-user lists to manage.
        </div>
      </div>

      <template v-else>
        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Per-user allow / block</h3></div>
          <div class="card-body-responsive space-y-4">
            <p class="text-sm text-surface-500 dark:text-surface-400">
              Manage a mailbox owner's personal safe and blocked senders. Saving regenerates their mail filter so the change takes effect on delivery; MailFlow's existing filtering is otherwise unchanged.
            </p>
            <div class="flex flex-wrap items-end gap-3">
              <input v-model="manageEmail" @keyup.enter="manageLookup" placeholder="user@example.com" class="input max-w-[18rem]" />
              <button @click="manageLookup" class="btn btn-primary btn-sm">Manage user</button>
              <div class="flex-1"></div>
              <input v-model="userSearch" @keyup.enter="loadUserListUsers" placeholder="search users…" class="input max-w-[14rem]" />
              <button @click="loadUserListUsers" class="btn btn-secondary btn-sm">Search</button>
            </div>
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>User</th><th>Safe</th><th>Blocked</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                  <tr v-for="u in userListUsers" :key="u.user_email">
                    <td>{{ u.user_email }}</td>
                    <td><span class="badge badge-success">{{ u.safe_count }}</span></td>
                    <td><span class="badge badge-danger">{{ u.blocked_count }}</span></td>
                    <td class="text-right"><button @click="selectUser(u.user_email)" class="btn btn-ghost btn-sm">Manage</button></td>
                  </tr>
                  <tr v-if="!userListUsers.length"><td colspan="4" class="text-surface-400">No users with custom lists yet. Use “Manage user” above to start one.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div v-if="selectedUser" class="card">
          <div class="card-header-responsive flex items-center justify-between">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ selectedUser }}</h3>
            <button @click="selectedUser = null" class="btn btn-ghost btn-sm">Close</button>
          </div>
          <div class="card-body-responsive">
            <div v-if="userListsLoading" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
              <span class="spinner-sm"></span> Loading…
            </div>
            <div v-else class="grid gap-6 lg:grid-cols-2">
              <div class="space-y-3">
                <h4 class="font-medium text-surface-900 dark:text-surface-100">Safe senders</h4>
                <div class="flex flex-wrap items-center gap-2">
                  <input v-model="newUserSafe.value" @keyup.enter="addUserEntry('safe')" placeholder="sender@example.com" class="input flex-1 min-w-[12rem]" />
                  <label class="flex items-center gap-1 text-sm text-surface-500 dark:text-surface-400">
                    <input type="checkbox" v-model="newUserSafe.apply_domain" /> domain
                  </label>
                  <button @click="addUserEntry('safe')" class="btn btn-primary btn-sm">Add</button>
                </div>
                <div class="table-responsive">
                  <table class="table">
                    <thead><tr><th>Sender</th><th>Domain</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                      <tr v-for="e in userLists.safe" :key="e.id">
                        <td>{{ e.safe_email }}</td>
                        <td class="text-surface-500 dark:text-surface-400">{{ e.safe_domain || '—' }}</td>
                        <td class="text-right"><button @click="deleteUserEntry('safe', e.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
                      </tr>
                      <tr v-if="!userLists.safe.length"><td colspan="3" class="text-surface-400">No safe senders.</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="space-y-3">
                <h4 class="font-medium text-surface-900 dark:text-surface-100">Blocked senders</h4>
                <div class="flex flex-wrap items-center gap-2">
                  <input v-model="newUserBlocked.value" @keyup.enter="addUserEntry('blocked')" placeholder="sender@example.com" class="input flex-1 min-w-[12rem]" />
                  <label class="flex items-center gap-1 text-sm text-surface-500 dark:text-surface-400">
                    <input type="checkbox" v-model="newUserBlocked.apply_domain" /> domain
                  </label>
                  <button @click="addUserEntry('blocked')" class="btn btn-primary btn-sm">Add</button>
                </div>
                <div class="table-responsive">
                  <table class="table">
                    <thead><tr><th>Sender</th><th>Domain</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                      <tr v-for="e in userLists.blocked" :key="e.id">
                        <td>{{ e.blocked_email }}</td>
                        <td class="text-surface-500 dark:text-surface-400">{{ e.blocked_domain || '—' }}</td>
                        <td class="text-right"><button @click="deleteUserEntry('blocked', e.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
                      </tr>
                      <tr v-if="!userLists.blocked.length"><td colspan="3" class="text-surface-400">No blocked senders.</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ATTACHMENTS -->
    <div v-else-if="activeTab === 'attachments'" class="card">
      <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Attachment policies</h3></div>
      <div class="card-body-responsive space-y-4">
        <p class="text-sm text-surface-500 dark:text-surface-400">
          Blocked extensions are matched on attachment filenames by the engine. Each extension's action is enforced individually:
          <strong>Quarantine</strong> holds the message for admin review (a known virus is still rejected), while
          <strong>Reject</strong> blocks it outright at SMTP. Changes save and sync automatically.
        </p>
        <div class="flex flex-wrap items-end gap-3">
          <input v-model="newAttachment.extension" @keyup.enter="addAttachment" placeholder="extension (e.g. exe)" class="input max-w-[12rem]" />
          <select v-model="newAttachment.list_type" class="input max-w-[8rem]">
            <option value="block">block</option><option value="allow">allow</option>
          </select>
          <select v-model="newAttachment.action" class="input max-w-[14rem]" title="What happens to mail carrying this attachment type">
            <option value="quarantine">quarantine (hold for review)</option>
            <option value="reject">reject (block at SMTP)</option>
          </select>
          <button @click="addAttachment" class="btn btn-primary btn-sm">Add</button>
        </div>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>Extension</th><th>List</th><th>Action</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
              <tr v-for="p in attachmentPolicies" :key="p.id">
                <td class="font-mono">.{{ p.extension }}</td>
                <td><span class="badge" :class="p.list_type === 'block' ? 'badge-danger' : 'badge-success'">{{ p.list_type }}</span></td>
                <td><span class="badge" :class="p.action === 'reject' ? 'badge-danger' : 'badge-warning'">{{ p.action === 'reject' ? 'reject' : 'quarantine' }}</span></td>
                <td class="text-right"><button @click="deleteAttachment(p.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
              </tr>
              <tr v-if="!attachmentPolicies.length"><td colspan="4" class="text-surface-400">No policies.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ANTI-SPOOFING / CEO FRAUD -->
    <div v-else-if="activeTab === 'antispoof'" class="space-y-4 sm:space-y-6">
      <div class="card">
        <div class="card-body-responsive space-y-2">
          <p class="text-sm text-surface-600 dark:text-surface-300">
            Detects <b>CEO fraud / business email compromise</b>: a message whose <b>display name</b> matches one of your protected VIPs but is sent from an outside address (<code>MAILSEC_CEO_SPOOF</code>), and mail that forges <b>your own domain</b> from outside without authentication (<code>MAILSEC_INTERNAL_SPOOF</code>). Hits add score and show on the Dashboard as <b>phish</b> — fail-open, never an outright reject on their own.
          </p>
          <p class="text-sm text-surface-600 dark:text-surface-300">
            It also catches <b>lookalike domains</b> (<code>MAILSEC_LOOKALIKE_DOMAIN</code>): senders whose domain is a typo, homoglyph (<code>devc0n1.hu</code>), TLD swap (<code>devcon1.com</code> when you own <code>devcon1.hu</code>) or combosquat (<code>secure-devcon1.com</code>) of one of your protected domains. Your real domains and their subdomains are never flagged.
          </p>
          <p class="text-xs text-surface-400">Changes save and sync to the engine automatically.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Lookalike detection</h3></div>
        <div class="card-body-responsive space-y-4">
          <label class="flex items-center gap-3 text-sm">
            <input type="checkbox" v-model="lookalike.enabled" class="h-4 w-4" />
            <span class="text-surface-700 dark:text-surface-200">Enable lookalike domain detection (<code>MAILSEC_LOOKALIKE_DOMAIN</code>)</span>
          </label>
          <label class="text-sm block max-w-[26rem]">
            <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Sensitivity</span>
            <select v-model="lookalike.sensitivity" :disabled="!lookalike.enabled" class="input w-full">
              <option value="low">Low — homoglyph &amp; TLD-swap only (fewest false positives)</option>
              <option value="medium">Medium — also 1-char typos &amp; combosquats (recommended)</option>
              <option value="high">High — aggressive: up to 2-char typos, shorter brands</option>
            </select>
          </label>
          <p class="text-xs text-surface-400">
            Strong matches (homoglyph, TLD swap) score 7.0; looser ones (typo, combosquat) score ~4.9. Changes are pushed to the engine automatically — no re-provision needed.
          </p>
          <button @click="saveLookalike" :disabled="lookalikeBusy" class="btn btn-primary btn-sm">
            {{ lookalikeBusy ? 'Saving…' : 'Save lookalike settings' }}
          </button>
        </div>
      </div>

      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Add entry</h3></div>
        <div class="card-body-responsive">
          <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Type</span>
              <select v-model="newImpersonation.kind" class="input max-w-[16rem]">
                <option value="vip_name">VIP / exec display name</option>
                <option value="exempt_sender">Exempt sender (never flag)</option>
                <option value="protected_domain">Extra protected domain</option>
              </select>
            </label>
            <label class="text-sm flex-1 min-w-[14rem]">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">
                {{ newImpersonation.kind === 'vip_name' ? 'Display name (e.g. Jane Doe)' : newImpersonation.kind === 'exempt_sender' ? 'Email address' : 'Domain' }}
              </span>
              <input v-model="newImpersonation.value" @keyup.enter="addImpersonation"
                     :placeholder="newImpersonation.kind === 'vip_name' ? 'Jane Doe' : newImpersonation.kind === 'exempt_sender' ? 'jane.personal@gmail.com' : 'example.com'"
                     class="input w-full" />
            </label>
            <label class="text-sm flex-1 min-w-[10rem]">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Note (optional)</span>
              <input v-model="newImpersonation.note" @keyup.enter="addImpersonation" placeholder="e.g. CFO" class="input w-full" />
            </label>
            <button @click="addImpersonation" :disabled="impersonationBusy" class="btn btn-primary btn-sm">
              {{ impersonationBusy ? 'Adding…' : 'Add' }}
            </button>
          </div>
        </div>
      </div>

      <div class="grid-responsive-2">
        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Protected VIP / exec names</h3></div>
          <div class="card-body-responsive">
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>Display name</th><th>Note</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                  <tr v-for="e in impersonationGroups.vip_name" :key="e.id">
                    <td class="font-medium text-surface-900 dark:text-surface-100">{{ e.value }}</td>
                    <td class="text-surface-500 dark:text-surface-400">{{ e.note || '—' }}</td>
                    <td class="text-right"><button @click="deleteImpersonation(e.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
                  </tr>
                  <tr v-if="!impersonationGroups.vip_name.length"><td colspan="3" class="text-surface-400">No VIP names yet — add your executives to catch display-name spoofing.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Exempt senders</h3></div>
          <div class="card-body-responsive">
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>Address</th><th>Note</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                  <tr v-for="e in impersonationGroups.exempt_sender" :key="e.id">
                    <td class="font-mono text-sm">{{ e.value }}</td>
                    <td class="text-surface-500 dark:text-surface-400">{{ e.note || '—' }}</td>
                    <td class="text-right"><button @click="deleteImpersonation(e.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
                  </tr>
                  <tr v-if="!impersonationGroups.exempt_sender.length"><td colspan="3" class="text-surface-400">None. Add a VIP's real external address here so it's never flagged.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Protected domains</h3></div>
        <div class="card-body-responsive space-y-3">
          <div>
            <p class="text-xs text-surface-500 dark:text-surface-400 mb-2">Your hosted mail domains are protected automatically (used for internal-spoof <i>and</i> lookalike detection):</p>
            <div class="flex flex-wrap gap-2">
              <span v-for="d in impersonation.hosted_domains" :key="d" class="badge badge-success">{{ d }}</span>
              <span v-if="!impersonation.hosted_domains.length" class="text-surface-400 text-sm">No hosted domains detected.</span>
            </div>
          </div>
          <div v-if="impersonationGroups.protected_domain.length">
            <p class="text-xs text-surface-500 dark:text-surface-400 mb-2">Extra protected domains you added:</p>
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>Domain</th><th>Note</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                  <tr v-for="e in impersonationGroups.protected_domain" :key="e.id">
                    <td class="font-mono text-sm">{{ e.value }}</td>
                    <td class="text-surface-500 dark:text-surface-400">{{ e.note || '—' }}</td>
                    <td class="text-right"><button @click="deleteImpersonation(e.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Remove</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MAIL FLOW RULES -->
    <div v-else-if="activeTab === 'rules'" class="space-y-4 sm:space-y-6">
      <div class="card">
        <div class="card-body-responsive space-y-2">
          <p class="text-sm text-surface-600 dark:text-surface-300">
            Build rules that match inbound mail on sender, recipient, subject, headers, score, symbols, attachments or size, then take an action. Rules run in <b>priority order (lowest first)</b> and the <b>first match wins</b>. All conditions in a rule must match (AND) — use multiple rules for OR.
          </p>
          <p v-if="rulesMode !== 'active'" class="text-xs rounded-md bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 px-3 py-2">
            Gateway is in <b>monitor</b> mode: rules only tag/log (a marker header is added, nothing is blocked). Enforcing actions (reject / quarantine / delete / move) take effect once delivery is wired (Engine tab → active).
          </p>
          <p v-else class="text-xs rounded-md bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 px-3 py-2">
            Gateway is <b>active</b>: rules enforce their action on the live mail path.
          </p>
        </div>
      </div>

      <!-- Builder -->
      <div class="card">
        <div class="card-header-responsive">
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ ruleEditing ? 'Edit rule' : 'New rule' }}</h3>
        </div>
        <div class="card-body-responsive space-y-4">
          <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm flex-1 min-w-[14rem]">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Name</span>
              <input v-model="ruleForm.name" placeholder="e.g. Block invoices from outside" class="input w-full" />
            </label>
            <label class="text-sm w-28">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Priority</span>
              <input v-model.number="ruleForm.priority" type="number" min="1" class="input w-full" />
            </label>
            <label class="flex items-center gap-2 text-sm pb-2">
              <input type="checkbox" v-model="ruleForm.enabled" class="h-4 w-4" />
              <span class="text-surface-700 dark:text-surface-200">Enabled</span>
            </label>
          </div>

          <div class="space-y-2">
            <span class="block text-sm text-surface-500 dark:text-surface-400">Conditions (all must match)</span>
            <div v-for="(c, i) in ruleForm.conditions" :key="i" class="flex flex-wrap items-center gap-2">
              <select v-model="c.field" @change="onConditionFieldChange(c)" class="input w-36">
                <option value="from">From</option>
                <option value="to">To</option>
                <option value="subject">Subject</option>
                <option value="header">Header</option>
                <option value="score">Spam score</option>
                <option value="symbol">Has symbol</option>
                <option value="attachment">Attachment</option>
                <option value="size">Size (bytes)</option>
              </select>
              <input v-if="c.field === 'header'" v-model="c.name" placeholder="Header name" class="input w-40" />
              <select v-model="c.op" class="input w-32">
                <option v-for="op in (ruleFieldOps[c.field] || [])" :key="op" :value="op">{{ op }}</option>
              </select>
              <input v-if="c.op !== 'exists'" v-model="c.value"
                     :placeholder="c.field === 'symbol' ? 'PHISHING' : c.field === 'attachment' && c.op === 'ext' ? 'exe' : c.field === 'score' || c.field === 'size' ? '6' : 'value'"
                     class="input flex-1 min-w-[10rem]" />
              <button @click="removeRuleCondition(i)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400" :disabled="ruleForm.conditions.length <= 1">✕</button>
            </div>
            <button @click="addRuleCondition" class="btn btn-ghost btn-sm">+ Add condition</button>
            <p class="text-xs text-surface-400">Tip: leave all conditions empty (remove them) to match every message — useful for a catch-all at low priority.</p>
          </div>

          <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm w-44">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Action</span>
              <select v-model="ruleForm.action" class="input w-full">
                <option value="tag">Tag (add header)</option>
                <option value="quarantine">Quarantine (hold)</option>
                <option value="move">Move to Junk</option>
                <option value="reject">Reject (notify sender)</option>
                <option value="delete">Delete (silent discard)</option>
              </select>
            </label>
            <label v-if="ruleForm.action === 'tag' || ruleForm.action === 'reject'" class="text-sm flex-1 min-w-[14rem]">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">
                {{ ruleForm.action === 'tag' ? 'Header (Name: Value) — optional' : 'Reject message text — optional' }}
              </span>
              <input v-model="ruleForm.action_arg"
                     :placeholder="ruleForm.action === 'tag' ? 'X-Flagged: yes' : 'Message rejected by policy'"
                     class="input w-full" />
            </label>
          </div>

          <div class="flex items-center gap-2">
            <button @click="saveRule" :disabled="rulesBusy" class="btn btn-primary btn-sm">
              {{ rulesBusy ? 'Saving…' : (ruleEditing ? 'Save rule' : 'Create rule') }}
            </button>
            <button v-if="ruleEditing" @click="cancelRuleEdit" class="btn btn-ghost btn-sm">Cancel</button>
          </div>
        </div>
      </div>

      <!-- Rules list -->
      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Rules</h3></div>
        <div class="card-body-responsive">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th class="w-16">Priority</th>
                  <th>Name</th>
                  <th>Match</th>
                  <th>Action</th>
                  <th>On</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in rules" :key="r.id" :class="{ 'opacity-50': !r.enabled }">
                  <td class="font-mono text-sm">{{ r.priority }}</td>
                  <td class="font-medium text-surface-900 dark:text-surface-100">{{ r.name }}</td>
                  <td class="text-xs text-surface-500 dark:text-surface-400">{{ ruleSummary(r) }}</td>
                  <td><span class="badge" :class="r.action === 'reject' || r.action === 'delete' ? 'badge-danger' : r.action === 'quarantine' ? 'badge-warning' : 'badge-info'">{{ r.action }}</span></td>
                  <td>
                    <button @click="toggleRule(r)" class="btn btn-sm" :class="r.enabled ? 'btn-primary' : 'btn-ghost'">
                      {{ r.enabled ? 'On' : 'Off' }}
                    </button>
                  </td>
                  <td class="text-right whitespace-nowrap">
                    <button @click="editRule(r)" class="btn btn-ghost btn-sm">Edit</button>
                    <button @click="deleteRule(r.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Delete</button>
                  </td>
                </tr>
                <tr v-if="!rules.length"><td colspan="6" class="text-surface-400">No rules yet — create one above.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- GEO-IP -->
    <div v-else-if="activeTab === 'geoip'" class="space-y-4 sm:space-y-6">
      <div class="card">
        <div class="card-body-responsive space-y-2">
          <p class="text-sm text-surface-600 dark:text-surface-300">
            Filter inbound mail by the <b>sender's country</b>, resolved from the connecting server's IP via Rspamd's ASN lookup (no MaxMind database required). A <b>deny</b> list blocks the listed countries; an <b>allow</b> list blocks every country <i>except</i> those listed. Per-recipient-domain overrides take precedence over the global policy. Mail with no resolvable country is never blocked (fail-open).
          </p>
          <p v-if="geoip.gateway_mode !== 'active'" class="text-xs rounded-md bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 px-3 py-2">
            Gateway is in <b>monitor</b> mode: Geo-IP only tags matched mail (an <code>X-Devcon-Geoip-Monitor</code> header is added, nothing is blocked). Enforcement takes effect once delivery is wired (Engine tab → active).
          </p>
          <p v-else class="text-xs rounded-md bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 px-3 py-2">
            Gateway is <b>active</b>: Geo-IP enforces its action on the live mail path.
          </p>
        </div>
      </div>

      <!-- Global policy -->
      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Global policy</h3></div>
        <div class="card-body-responsive space-y-4">
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="geoipForm.enabled" class="h-4 w-4" />
            <span class="text-surface-700 dark:text-surface-200">Enable global Geo-IP filtering for all recipient domains</span>
          </label>
          <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm w-44">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Mode</span>
              <select v-model="geoipForm.mode" class="input w-full">
                <option value="deny">Deny listed countries</option>
                <option value="allow">Allow only listed</option>
              </select>
            </label>
            <label class="text-sm w-44">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Action</span>
              <select v-model="geoipForm.action" class="input w-full">
                <option value="reject">Reject (notify sender)</option>
                <option value="quarantine">Quarantine (hold)</option>
                <option value="tag">Tag (add header)</option>
              </select>
            </label>
          </div>
          <label class="text-sm block">
            <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Countries — ISO 3166-1 alpha-2 codes, comma-separated</span>
            <input v-model="geoipForm.countries" placeholder="CN, RU, KP" class="input w-full" />
          </label>
          <div>
            <button @click="saveGeoip" :disabled="geoipBusy" class="btn btn-primary btn-sm">{{ geoipBusy ? 'Saving…' : 'Save global policy' }}</button>
          </div>
        </div>
      </div>

      <!-- Per-domain overrides -->
      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Per-domain overrides</h3></div>
        <div class="card-body-responsive space-y-4">
          <p class="text-xs text-surface-400">An override fully replaces the global policy for mail addressed to that recipient domain.</p>
          <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm flex-1 min-w-[12rem]">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Recipient domain</span>
              <input v-model="geoipDomainForm.domain" list="geoip-hosted-domains" placeholder="example.com" class="input w-full" />
              <datalist id="geoip-hosted-domains">
                <option v-for="d in geoip.hosted_domains" :key="d" :value="d" />
              </datalist>
            </label>
            <label class="text-sm w-36">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Mode</span>
              <select v-model="geoipDomainForm.mode" class="input w-full">
                <option value="deny">Deny</option>
                <option value="allow">Allow only</option>
              </select>
            </label>
            <label class="text-sm w-40">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Action</span>
              <select v-model="geoipDomainForm.action" class="input w-full">
                <option value="reject">Reject</option>
                <option value="quarantine">Quarantine</option>
                <option value="tag">Tag</option>
              </select>
            </label>
          </div>
          <label class="text-sm block">
            <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Countries</span>
            <input v-model="geoipDomainForm.countries" placeholder="CN, RU" class="input w-full" />
          </label>
          <div>
            <button @click="addGeoipDomain" :disabled="geoipBusy" class="btn btn-primary btn-sm">Add / update override</button>
          </div>

          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Domain</th>
                  <th>Mode</th>
                  <th>Countries</th>
                  <th>Action</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in geoip.domains" :key="d.id">
                  <td class="font-medium text-surface-900 dark:text-surface-100">{{ d.domain }}</td>
                  <td><span class="badge" :class="d.mode === 'allow' ? 'badge-info' : 'badge-warning'">{{ d.mode }}</span></td>
                  <td class="font-mono text-xs">{{ d.countries }}</td>
                  <td><span class="badge" :class="d.action === 'reject' ? 'badge-danger' : d.action === 'quarantine' ? 'badge-warning' : 'badge-info'">{{ d.action }}</span></td>
                  <td class="text-right whitespace-nowrap">
                    <button @click="deleteGeoipDomain(d.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Delete</button>
                  </td>
                </tr>
                <tr v-if="!geoip.domains.length"><td colspan="5" class="text-surface-400">No per-domain overrides — the global policy applies to all domains.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- QUARANTINE -->
    <div v-else-if="activeTab === 'quarantine'" class="space-y-4 sm:space-y-6">
      <div class="card">
        <div class="card-body-responsive text-sm text-surface-500 dark:text-surface-400">
          Hold-and-release quarantine. Release delivers the message normally; Delete removes it permanently.
          <span v-if="status?.quarantine?.ready" class="text-green-600 dark:text-green-400"> Infrastructure ready.</span>
          <span v-else class="text-amber-600 dark:text-amber-400"> Run engine install to provision the quarantine transport.</span>
          Messages appear here once the gateway is wired to route spam into the quarantine transport.
        </div>
      </div>

      <!-- Retention + digest -->
      <div class="card">
        <div class="card-header-responsive">
          <h3 class="font-semibold">Retention &amp; digest</h3>
        </div>
        <div class="card-body-responsive space-y-4">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            Held messages older than the retention window are removed automatically every day (the held copy is purged from the spool). If a digest address is set, a daily summary of quarantine activity is emailed to it. Leave the digest address blank to disable digest emails.
          </p>
          <div class="flex flex-col sm:flex-row sm:items-end gap-3">
            <label class="flex-1">
              <span class="block text-xs text-surface-500 dark:text-surface-400 mb-1">Retention (days)</span>
              <input v-model.number="retentionSettings.quarantine_retention_days" type="number" min="1" max="3650" class="input max-w-[10rem]" />
            </label>
            <label class="flex-1">
              <span class="block text-xs text-surface-500 dark:text-surface-400 mb-1">Daily digest email (optional)</span>
              <input v-model="retentionSettings.quarantine_digest_to" type="email" placeholder="admin@example.com" class="input" />
            </label>
            <button :disabled="retentionBusy" @click="saveRetentionSettings" class="btn btn-primary btn-sm">
              {{ retentionBusy ? 'Saving…' : 'Save' }}
            </button>
            <button :disabled="maintenanceBusy" @click="runQuarantineMaintenance" class="btn btn-secondary btn-sm">
              {{ maintenanceBusy ? 'Running…' : 'Run cleanup now' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Self-service per-user digest -->
      <div class="card">
        <div class="card-header-responsive">
          <h3 class="font-semibold">Self-service digest (per recipient)</h3>
        </div>
        <div class="card-body-responsive space-y-4">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            When enabled, each recipient who has new held mail gets a daily email listing <em>their</em> quarantined messages with a secure one-click link to review and Release, Allow the sender, or Delete — no login required. Links are signed and expire automatically. Sent by the same daily cleanup job; leave disabled to keep quarantine admin-only.
          </p>
          <label class="flex items-center gap-2">
            <input v-model="retentionSettings.quarantine_user_digest_enabled" type="checkbox" class="h-4 w-4" />
            <span class="text-sm">Email recipients a daily digest of their own held mail</span>
          </label>
          <div class="flex flex-col sm:flex-row sm:items-end gap-3">
            <label class="flex-1">
              <span class="block text-xs text-surface-500 dark:text-surface-400 mb-1">Panel base URL (used to build links)</span>
              <input v-model="retentionSettings.quarantine_link_base" type="url" placeholder="https://panel.example.com" class="input" />
            </label>
            <label class="flex-1 sm:flex-none">
              <span class="block text-xs text-surface-500 dark:text-surface-400 mb-1">Link expiry (days)</span>
              <input v-model.number="retentionSettings.quarantine_link_ttl_days" type="number" min="1" max="90" class="input max-w-[8rem]" />
            </label>
            <button :disabled="userDigestBusy" @click="saveUserDigestSettings" class="btn btn-primary btn-sm">
              {{ userDigestBusy ? 'Saving…' : 'Save' }}
            </button>
          </div>
          <p class="text-xs text-surface-400">
            The base URL must point to this panel and be reachable by your users' browsers. Each link opens a confirmation page before any action is taken, so mail scanners cannot release or delete messages by pre-fetching the link.
          </p>
        </div>
      </div>

      <div class="card">
      <div class="card-header-responsive flex flex-col sm:flex-row sm:items-center gap-3">
        <input v-model="quarantineSearch" @keyup.enter="loadQuarantine" placeholder="Search sender / recipient / subject" class="input flex-1" />
        <button @click="loadQuarantine" class="btn btn-secondary btn-sm">Search</button>
      </div>
      <div class="card-body-responsive space-y-3">
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>Date</th><th>Sender</th><th>Recipient</th><th>Subject</th><th>Score</th><th>Reason</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
              <tr v-for="m in quarantine.items" :key="m.id">
                <td class="whitespace-nowrap">{{ m.created_at }}</td>
                <td class="truncate-responsive">{{ m.sender }}</td>
                <td class="truncate-responsive">{{ m.recipient }}</td>
                <td class="truncate-responsive">{{ m.subject }}</td>
                <td>{{ m.spam_score ?? '—' }}</td>
                <td class="text-surface-500 dark:text-surface-400">{{ m.reason || '—' }}</td>
                <td class="text-right whitespace-nowrap">
                  <button @click="releaseQuarantine(m.id)" class="btn btn-primary btn-sm mr-1">Release</button>
                  <button @click="deleteQuarantine(m.id)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">Delete</button>
                </td>
              </tr>
              <tr v-if="!quarantine.items.length"><td colspan="7" class="text-surface-400">Quarantine is empty.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      </div>
    </div>

    <!-- AUTH placeholder -->
    <div v-else-if="activeTab === 'auth'" class="space-y-4 sm:space-y-6">
      <div class="flex items-center justify-between">
        <p class="text-sm text-surface-500 dark:text-surface-400">Live SPF, DKIM and DMARC status for your mail domains.</p>
        <button @click="loadAuth" :disabled="authLoading" class="btn btn-secondary btn-sm">
          {{ authLoading ? 'Checking…' : 'Refresh' }}
        </button>
      </div>

      <div v-if="authLoading && !authDomains.length" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
        <span class="spinner-sm"></span> Resolving DNS…
      </div>

      <div v-else-if="!authAvailable" class="card">
        <div class="card-body-responsive text-surface-500 dark:text-surface-400">No mail domains are configured on this server.</div>
      </div>

      <template v-else>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="stat-card"><div class="stat-label">Passing</div><div class="stat-value text-green-600 dark:text-green-400">{{ authSummary.ok }}</div></div>
          <div class="stat-card"><div class="stat-label">Needs attention</div><div class="stat-value text-amber-600 dark:text-amber-400">{{ authSummary.warn }}</div></div>
          <div class="stat-card"><div class="stat-label">Missing</div><div class="stat-value text-red-600 dark:text-red-400">{{ authSummary.missing }}</div></div>
        </div>

        <div class="card">
          <div class="card-body-responsive">
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>Domain</th><th>SPF</th><th>DKIM</th><th>DMARC</th></tr></thead>
                <tbody>
                  <tr v-for="d in authDomains" :key="d.domain">
                    <td class="font-medium text-surface-900 dark:text-surface-100">{{ d.domain }}</td>
                    <td v-for="key in ['spf','dkim','dmarc']" :key="key">
                      <span class="badge" :class="authBadgeClass(d[key].status)" :title="d[key].value || ''">{{ d[key].status }}</span>
                      <div class="text-xs text-surface-500 dark:text-surface-400 mt-1">{{ d[key].detail }}</div>
                    </td>
                  </tr>
                  <tr v-if="!authDomains.length"><td colspan="4" class="text-surface-400">No mail domains found.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- SECURITY SCORE -->
    <div v-else-if="activeTab === 'score'" class="space-y-4 sm:space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-surface-500 dark:text-surface-400 max-w-3xl">
          Per-domain posture score (0–100): authentication hardening — SPF/DKIM/DMARC (60 pts) — plus inbound hygiene from spam / phishing / malware exposure (40 pts). Worst-scoring domains are listed first.
        </p>
        <div class="flex items-center gap-2">
          <select v-model.number="scoreDays" @change="loadScore" class="input max-w-[10rem]">
            <option :value="7">Last 7 days</option>
            <option :value="30">Last 30 days</option>
            <option :value="90">Last 90 days</option>
          </select>
          <button @click="loadScore" :disabled="scoreLoading" class="btn btn-secondary btn-sm">{{ scoreLoading ? 'Scoring…' : 'Refresh' }}</button>
        </div>
      </div>

      <div v-if="scoreLoading && !score.domains.length" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
        <span class="spinner-sm"></span> Resolving DNS + scoring…
      </div>

      <div v-else-if="!score.available" class="card">
        <div class="card-body-responsive text-surface-500 dark:text-surface-400">No mail domains are configured on this server.</div>
      </div>

      <template v-else>
        <!-- Overall -->
        <div class="card" v-if="score.overall">
          <div class="card-body-responsive flex flex-wrap items-center gap-6">
            <div class="text-center min-w-[7rem]">
              <div class="text-5xl font-bold" :class="gradeText(score.overall.grade)">{{ score.overall.score }}</div>
              <div class="text-sm text-surface-500 dark:text-surface-400">Overall · grade <span :class="gradeText(score.overall.grade)" class="font-semibold">{{ score.overall.grade }}</span></div>
            </div>
            <div class="flex-1 grid grid-cols-5 gap-2 min-w-[16rem]">
              <div v-for="g in ['A','B','C','D','F']" :key="g" class="stat-card text-center">
                <div class="stat-value" :class="gradeText(g)">{{ score.distribution[g] || 0 }}</div>
                <div class="stat-label">{{ g }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Per-domain -->
        <div v-for="d in score.domains" :key="d.domain" class="card">
          <div class="card-body-responsive space-y-3">
            <div class="flex items-center justify-between gap-3">
              <div class="font-semibold text-surface-900 dark:text-surface-100">{{ d.domain }}</div>
              <div class="flex items-center gap-2">
                <span class="text-2xl font-bold" :class="gradeText(d.grade)">{{ d.score }}</span>
                <span class="badge" :class="(d.grade === 'A' || d.grade === 'B') ? 'badge-success' : d.grade === 'C' ? 'badge-warning' : 'badge-danger'">{{ d.grade }}</span>
              </div>
            </div>

            <div class="space-y-2">
              <div v-for="f in [
                     { key: 'spf', label: 'SPF', o: d.auth.spf },
                     { key: 'dkim', label: 'DKIM', o: d.auth.dkim },
                     { key: 'dmarc', label: 'DMARC', o: d.auth.dmarc },
                   ]" :key="f.key" class="flex items-center gap-3">
                <div class="w-16 text-xs text-surface-500 dark:text-surface-400">{{ f.label }}</div>
                <div class="flex-1 h-2 rounded-full bg-surface-200 dark:bg-surface-700 overflow-hidden">
                  <div class="h-full rounded-full" :class="barColor(f.o.points, f.o.max)" :style="{ width: barPct(f.o.points, f.o.max) + '%' }"></div>
                </div>
                <div class="w-40 text-right text-xs text-surface-500 dark:text-surface-400">{{ f.o.points }}/{{ f.o.max }} · {{ f.o.detail }}</div>
              </div>
              <div class="flex items-center gap-3">
                <div class="w-16 text-xs text-surface-500 dark:text-surface-400">Hygiene</div>
                <div class="flex-1 h-2 rounded-full bg-surface-200 dark:bg-surface-700 overflow-hidden">
                  <div class="h-full rounded-full" :class="barColor(d.hygiene.points, d.hygiene.max)" :style="{ width: barPct(d.hygiene.points, d.hygiene.max) + '%' }"></div>
                </div>
                <div class="w-40 text-right text-xs text-surface-500 dark:text-surface-400">
                  {{ d.hygiene.points }}/{{ d.hygiene.max }}<span v-if="d.hygiene.low_data" class="text-surface-400"> · low data</span>
                </div>
              </div>
            </div>

            <div class="text-xs text-surface-400" v-if="!d.hygiene.low_data">
              {{ d.hygiene.volume }} msgs · spam {{ Math.round(d.hygiene.spam_rate * 100) }}% · phishing {{ Math.round(d.hygiene.phish_rate * 100) }}% · malware {{ Math.round(d.hygiene.virus_rate * 100) }}%
            </div>
            <div class="text-xs text-surface-400" v-else>Not enough inbound volume in this window to score hygiene.</div>

            <div class="space-y-1.5">
              <div v-for="(r, i) in d.recommendations" :key="i" class="flex items-start gap-2">
                <span class="badge mt-0.5" :class="recSeverityClass(r.severity)">{{ r.severity }}</span>
                <span class="text-sm text-surface-700 dark:text-surface-200">{{ r.text }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- AI ANALYSIS -->
    <div v-else-if="activeTab === 'ai'" class="space-y-4 sm:space-y-6">
      <p class="text-sm text-surface-500 dark:text-surface-400 max-w-3xl">
        Paste a suspicious email — raw headers + body, or just the body — and get an AI threat assessment: a phishing / impersonation verdict, a 0–100 risk score, the indicators it found and a recommended action. Uses the model configured in AI Helper settings.
      </p>

      <div class="card">
        <div class="card-body-responsive space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="text-sm block">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Subject (optional)</span>
              <input v-model="aiForm.subject" type="text" class="input w-full" placeholder="e.g. Your account will be suspended" />
            </label>
            <label class="text-sm block">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">From / sender (optional)</span>
              <input v-model="aiForm.sender" type="text" class="input w-full" placeholder="e.g. security@paypa1.com" />
            </label>
          </div>
          <label class="text-sm block">
            <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Message content</span>
            <textarea v-model="aiForm.content" rows="10" class="input w-full font-mono text-xs" placeholder="Paste the full email here (raw headers + body, or just the body)…"></textarea>
          </label>
          <div class="flex items-center gap-2">
            <button @click="runAiAnalysis" :disabled="aiBusy" class="btn btn-primary btn-sm">{{ aiBusy ? 'Analyzing…' : 'Analyze' }}</button>
            <button @click="clearAi" :disabled="aiBusy" class="btn btn-secondary btn-sm">Clear</button>
          </div>
        </div>
      </div>

      <div v-if="aiBusy" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
        <span class="spinner-sm"></span> Asking the model…
      </div>

      <div v-else-if="aiResult" class="card">
        <div class="card-body-responsive space-y-4">
          <div class="flex flex-wrap items-center gap-4">
            <span class="badge text-sm" :class="verdictClass(aiResult.verdict)">{{ verdictLabel(aiResult.verdict) }}</span>
            <div v-if="aiResult.score !== null && aiResult.score !== undefined" class="flex items-baseline gap-2">
              <span class="text-3xl font-bold" :class="scoreColor(aiResult.score)">{{ aiResult.score }}</span>
              <span class="text-sm text-surface-500 dark:text-surface-400">/ 100 risk</span>
            </div>
            <span class="text-sm text-surface-500 dark:text-surface-400">confidence: {{ aiResult.confidence }}</span>
            <span v-if="aiResult.recommended_action" class="text-sm text-surface-500 dark:text-surface-400">· suggested: <span class="font-medium text-surface-700 dark:text-surface-200">{{ aiResult.recommended_action }}</span></span>
          </div>

          <p v-if="aiResult.summary" class="text-sm text-surface-700 dark:text-surface-200">{{ aiResult.summary }}</p>

          <div v-if="aiResult.indicators && aiResult.indicators.length">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-2">Indicators</h4>
            <ul class="space-y-1.5">
              <li v-for="(ind, i) in aiResult.indicators" :key="i" class="flex items-start gap-2">
                <span class="badge mt-0.5" :class="severityClass(ind.severity)">{{ ind.severity }}</span>
                <span class="text-sm text-surface-700 dark:text-surface-200"><span class="font-medium">{{ ind.type }}:</span> {{ ind.detail }}</span>
              </li>
            </ul>
          </div>

          <div v-if="aiResult.recommendations && aiResult.recommendations.length">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-2">Recommendations</h4>
            <ul class="list-disc pl-5 space-y-1">
              <li v-for="(r, i) in aiResult.recommendations" :key="i" class="text-sm text-surface-700 dark:text-surface-200">{{ r }}</li>
            </ul>
          </div>

          <p v-if="aiResult.parse_error" class="text-xs text-amber-600 dark:text-amber-400">The model did not return structured output; showing its raw response above.</p>
          <p v-if="aiModel" class="text-xs text-surface-400">Model: {{ aiModel }}</p>
        </div>
      </div>
    </div>

    <!-- VIRUSTOTAL -->
    <div v-else-if="activeTab === 'virustotal'" class="space-y-4 sm:space-y-6">
      <p class="text-sm text-surface-500 dark:text-surface-400 max-w-3xl">
        Look up a URL or file hash against <a href="https://www.virustotal.com/" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 hover:underline">VirusTotal</a>'s 70+ engines. Results are cached to respect the free-tier limits (4 requests/min, 500/day).
      </p>

      <!-- Lookup -->
      <div class="card">
        <div class="card-body-responsive space-y-4">
          <div class="flex flex-col sm:flex-row gap-3 sm:items-end">
            <label class="text-sm block flex-1">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">URL or file hash (MD5 / SHA-1 / SHA-256)</span>
              <input v-model="vtForm.resource" type="text" class="input w-full" placeholder="https://example.com/login  or  44d88612fea8a8f36de82e1278abb02f" @keyup.enter="runVirustotal(false)" />
            </label>
            <label class="text-sm block">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Type</span>
              <select v-model="vtForm.type" class="input max-w-[10rem]">
                <option value="auto">Auto-detect</option>
                <option value="url">URL</option>
                <option value="file">File hash</option>
              </select>
            </label>
            <button @click="runVirustotal(false)" :disabled="vtBusy || !vtConfig.configured" class="btn btn-primary btn-sm">{{ vtBusy ? 'Checking…' : 'Check' }}</button>
          </div>
          <p v-if="!vtConfig.configured" class="text-xs text-amber-600 dark:text-amber-400">Add a VirusTotal API key below to enable lookups.</p>
        </div>
      </div>

      <!-- Result -->
      <div v-if="vtResult" class="card">
        <div class="card-body-responsive space-y-3">
          <div class="flex flex-wrap items-center gap-4">
            <span class="badge text-sm" :class="vtVerdictClass(vtResult.verdict)">{{ vtVerdictLabel(vtResult.verdict) }}</span>
            <span v-if="vtFlagSummary(vtResult)" class="text-sm text-surface-600 dark:text-surface-300">{{ vtFlagSummary(vtResult) }}</span>
            <span v-if="vtResult.cached" class="badge badge-info">cached</span>
            <button v-if="vtResult.total > 0 || vtResult.verdict === 'pending'" @click="runVirustotal(true)" :disabled="vtBusy" class="btn btn-secondary btn-sm">Re-check</button>
          </div>

          <p v-if="vtHeuristicNote(vtResult)" class="text-xs text-surface-500 dark:text-surface-400">{{ vtHeuristicNote(vtResult) }}</p>

          <div v-if="vtResult.total > 0" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="stat-card"><div class="stat-value text-red-600 dark:text-red-400">{{ vtResult.malicious }}</div><div class="stat-label">Malicious</div></div>
            <div class="stat-card"><div class="stat-value text-amber-600 dark:text-amber-400">{{ vtResult.suspicious }}</div><div class="stat-label">Suspicious</div></div>
            <div class="stat-card"><div class="stat-value text-emerald-600 dark:text-emerald-400">{{ vtResult.harmless }}</div><div class="stat-label">Harmless</div></div>
            <div class="stat-card"><div class="stat-value text-surface-500 dark:text-surface-400">{{ vtResult.undetected }}</div><div class="stat-label">Undetected</div></div>
          </div>

          <p v-if="vtResult.message" class="text-sm text-surface-600 dark:text-surface-300">{{ vtResult.message }}</p>
          <p class="text-xs text-surface-500 dark:text-surface-400 break-all">{{ vtResult.resource }}</p>
          <a v-if="vtResult.permalink" :href="vtResult.permalink" target="_blank" rel="noopener" class="text-xs text-primary-600 dark:text-primary-400 hover:underline">View full report on VirusTotal →</a>
        </div>
      </div>

      <!-- Recent lookups -->
      <div class="card" v-if="vtRecent.length">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Recent lookups</h3></div>
        <div class="card-body-responsive">
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>When</th><th>Verdict</th><th>Type</th><th>Resource</th><th>Detections</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in vtRecent" :key="i">
                  <td class="whitespace-nowrap text-xs text-surface-500 dark:text-surface-400">{{ fmtTs(r.checked_at) }}</td>
                  <td><span class="badge" :class="vtVerdictClass(r.verdict)">{{ vtVerdictLabel(r.verdict) }}</span></td>
                  <td class="text-xs">{{ r.resource_type }}</td>
                  <td class="text-xs text-surface-600 dark:text-surface-300 truncate max-w-[22rem]" :title="r.resource">
                    <a v-if="r.permalink" :href="r.permalink" target="_blank" rel="noopener" class="hover:underline">{{ r.resource }}</a>
                    <span v-else>{{ r.resource }}</span>
                  </td>
                  <td class="text-xs whitespace-nowrap">{{ r.malicious }}/{{ r.total }}<span v-if="r.suspicious > 0" class="text-amber-600 dark:text-amber-400"> (+{{ r.suspicious }} susp)</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- API key config -->
      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">VirusTotal settings</h3></div>
        <div class="card-body-responsive space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="text-sm block">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">API key <span v-if="vtConfig.configured" class="text-emerald-600 dark:text-emerald-400">(configured: {{ vtConfig.hint }})</span></span>
              <input v-model="vtConfigForm.api_key" type="password" autocomplete="off" class="input w-full" :placeholder="vtConfig.configured ? 'Leave blank to keep current key' : 'Paste your VirusTotal API key'" />
            </label>
            <label class="text-sm block">
              <span class="block text-surface-500 dark:text-surface-400 mb-1.5">Cache results for (hours)</span>
              <input v-model.number="vtConfigForm.cache_ttl_hours" type="number" min="1" max="720" class="input max-w-[10rem]" />
            </label>
          </div>
          <p class="text-xs text-surface-400">
            Get a free API key from your <a href="https://www.virustotal.com/gui/my-apikey" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 hover:underline">VirusTotal account</a>. The key is stored in the panel database and only ever returned to the UI as a masked hint.
          </p>
          <button @click="saveVirustotalConfig" :disabled="vtConfigBusy" class="btn btn-primary btn-sm">{{ vtConfigBusy ? 'Saving…' : 'Save settings' }}</button>
        </div>
      </div>
    </div>

    <!-- LEARNING (reactive Bayes feedback loop) -->
    <div v-else-if="activeTab === 'learning'" class="space-y-4 sm:space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <select v-model.number="learningDays" @change="loadLearning" class="input max-w-[10rem] text-sm">
            <option :value="7">Last 7 days</option>
            <option :value="30">Last 30 days</option>
            <option :value="90">Last 90 days</option>
          </select>
          <button @click="loadLearning" :disabled="learningLoading" class="btn btn-secondary btn-sm">
            {{ learningLoading ? 'Loading…' : 'Refresh' }}
          </button>
        </div>
        <span class="badge" :class="learning.loop?.enabled ? 'badge-success' : 'badge-warning'">
          <span class="status-dot" :class="learning.loop?.enabled ? 'running' : 'unknown'"></span>
          {{ learning.loop?.enabled ? 'Learning loop active' : 'Learning loop off (webmail only)' }}
        </span>
      </div>

      <!-- Loop status + toggle -->
      <div class="card">
        <div class="card-header-responsive flex items-center justify-between gap-2">
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">Reactive learning loop</h3>
        </div>
        <div class="card-body-responsive space-y-3">
          <p class="text-sm text-surface-600 dark:text-surface-300">
            When enabled, dragging a message into <b>Junk</b>/<b>Spam</b> in any IMAP client (Outlook, Apple Mail, Thunderbird, webmail…) trains the Bayes classifier as spam. Dragging it back out trains it as ham. Webmail's existing "Report Spam" buttons continue to work unchanged — both paths log here.
          </p>

          <div class="text-sm space-y-1.5 text-surface-600 dark:text-surface-300">
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="learning.loop?.pigeonhole_present ? 'running' : 'stopped'"></span>
              Dovecot pigeonhole (sievec): <b>{{ learning.loop?.pigeonhole_present ? 'present' : 'missing' }}</b>
            </div>
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="learning.loop?.imap_sieve_loaded ? 'running' : 'stopped'"></span>
              imap_sieve plugin loaded: <b>{{ learning.loop?.imap_sieve_loaded ? 'yes' : 'no' }}</b>
            </div>
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="learning.loop?.wrapper_present ? 'running' : 'stopped'"></span>
              Learn wrapper deployed: <b>{{ learning.loop?.wrapper_present ? 'yes' : 'no' }}</b>
            </div>
            <div class="flex items-center gap-2">
              <span class="status-dot" :class="learning.loop?.sieve_spam_present && learning.loop?.sieve_ham_present ? 'running' : 'stopped'"></span>
              Sieve scripts compiled: <b>{{ learning.loop?.sieve_spam_present && learning.loop?.sieve_ham_present ? 'yes' : 'no' }}</b>
            </div>
            <div v-if="(learning.loop?.spool_pending ?? 0) > 0" class="flex items-center gap-2 text-amber-600 dark:text-amber-400">
              <span class="status-dot unknown"></span>
              Spool backlog: <b>{{ learning.loop.spool_pending }}</b> event(s) waiting for the next ingester pass.
            </div>
          </div>

          <div class="action-buttons">
            <button
              v-if="!learning.loop?.enabled"
              @click="toggleLearningLoop(true)"
              :disabled="learningBusy || !learning.loop?.pigeonhole_present"
              class="btn btn-primary btn-sm"
            >
              {{ learningBusy ? 'Working…' : 'Enable learning loop' }}
            </button>
            <button
              v-else
              @click="toggleLearningLoop(false)"
              :disabled="learningBusy"
              class="btn btn-secondary btn-sm"
            >
              {{ learningBusy ? 'Working…' : 'Disable learning loop' }}
            </button>
          </div>
          <p v-if="!learning.loop?.pigeonhole_present" class="text-xs text-amber-600 dark:text-amber-400">
            Install <code class="text-xs">dovecot-sieve</code> (and ensure <code class="text-xs">sievec</code> is on PATH) before enabling the loop.
          </p>
          <p class="text-xs text-surface-400">
            Users with <b>Spam Filter Training</b> turned off in webmail are still respected — the wrapper checks an opt-out list refreshed every minute. Disabling here removes only the IMAP-client hooks; the webmail "Report Spam" path keeps working.
          </p>
        </div>
      </div>

      <!-- Bayes autolearn + webmail preferences -->
      <div class="grid-responsive-2">
        <div class="card">
          <div class="card-header-responsive flex items-center justify-between gap-2">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Automatic Bayes self-learning</h3>
            <span class="badge" :class="learning.loop?.bayes?.autolearn ? 'badge-success' : 'badge-warning'">
              {{ learning.loop?.bayes?.autolearn ? 'On' : 'Off' }}
            </span>
          </div>
          <div class="card-body-responsive space-y-3">
            <p class="text-sm text-surface-600 dark:text-surface-300">
              Independent of the user-feedback loop above. When on, Rspamd trains the Bayes corpus from every message that scores deep in spam or ham territory — no user action needed. Turn it off if you want the corpus to be fed exclusively by user reports.
            </p>
            <div class="action-buttons">
              <button
                v-if="!learning.loop?.bayes?.autolearn"
                @click="toggleBayesAutolearn(true)"
                :disabled="learningBusy"
                class="btn btn-primary btn-sm"
              >
                {{ learningBusy ? 'Working…' : 'Enable autolearn' }}
              </button>
              <button
                v-else
                @click="toggleBayesAutolearn(false)"
                :disabled="learningBusy"
                class="btn btn-secondary btn-sm"
              >
                {{ learningBusy ? 'Working…' : 'Disable autolearn' }}
              </button>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Webmail spam preferences</h3></div>
          <div class="card-body-responsive space-y-2">
            <p v-if="!learning.spam_prefs?.available" class="text-sm text-surface-400">
              MailFlow's <code class="text-xs">webmail_spam_settings</code> table isn't present on this host. The IMAP learning loop will work, but per-user opt-out won't be available until the webmail app has run at least once.
            </p>
            <template v-else>
              <div class="grid grid-cols-2 gap-3 text-sm">
                <div>
                  <div class="text-xs uppercase tracking-wide text-surface-400">Users tracked</div>
                  <div class="text-lg font-bold">{{ learning.spam_prefs.users_total }}</div>
                </div>
                <div>
                  <div class="text-xs uppercase tracking-wide text-surface-400">Opted out of training</div>
                  <div class="text-lg font-bold" :class="learning.spam_prefs.users_opted_out > 0 ? 'text-amber-600 dark:text-amber-400' : ''">{{ learning.spam_prefs.users_opted_out }}</div>
                </div>
              </div>
              <div v-if="learning.spam_prefs.folders?.length" class="pt-2 border-t border-surface-200 dark:border-surface-700">
                <div class="text-xs uppercase tracking-wide text-surface-400 mb-1">Spam-folder names in use</div>
                <ul class="text-xs space-y-1">
                  <li v-for="f in learning.spam_prefs.folders" :key="f.folder" class="flex justify-between">
                    <span class="font-mono">{{ f.folder }}</span>
                    <span class="text-surface-500 dark:text-surface-400">{{ f.users }} user(s)</span>
                  </li>
                </ul>
              </div>
              <p class="text-xs text-surface-400 pt-1">
                Each MailFlow user controls their own <b>Spam Filter Training</b> + spam folder name. Toggle them in the webmail UI; the panel mirrors the aggregate here.
              </p>
            </template>
          </div>
        </div>
      </div>

      <!-- Bayes corpus + this-window totals -->
      <div class="grid-responsive-4">
        <div class="stat-card">
          <div class="stat-value">{{ learning.loop?.bayes?.learned_spam ?? '—' }}</div>
          <div class="stat-label">Bayes learned spam (lifetime)</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ learning.loop?.bayes?.learned_ham ?? '—' }}</div>
          <div class="stat-label">Bayes learned ham (lifetime)</div>
        </div>
        <div class="stat-card">
          <div class="stat-value text-red-600 dark:text-red-400">{{ learning.totals?.spam ?? 0 }}</div>
          <div class="stat-label">Reported spam ({{ learning.days }}d)</div>
        </div>
        <div class="stat-card">
          <div class="stat-value text-emerald-600 dark:text-emerald-400">{{ learning.totals?.ham ?? 0 }}</div>
          <div class="stat-label">Marked not-spam ({{ learning.days }}d)</div>
        </div>
      </div>

      <!-- Source breakdown -->
      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Where feedback is coming from</h3></div>
        <div class="card-body-responsive">
          <p v-if="!learnSourcesView.length" class="text-sm text-surface-400">No learning activity in the window.</p>
          <div v-else class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Source</th>
                  <th class="text-right">Reported spam</th>
                  <th class="text-right">Marked not-spam</th>
                  <th class="text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in learnSourcesView" :key="row.source">
                  <td>{{ learnSourceLabel(row.source) }}</td>
                  <td class="text-right">{{ row.spam || 0 }}</td>
                  <td class="text-right">{{ row.ham || 0 }}</td>
                  <td class="text-right font-bold">{{ (row.spam || 0) + (row.ham || 0) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-surface-400 mt-2">
            "IMAP client" = anyone dragging messages to/from Junk in Outlook, Apple Mail, Thunderbird, etc. "Webmail" = the existing Report Spam buttons in MailFlow.
          </p>
        </div>
      </div>

      <!-- Daily trend -->
      <div v-if="learning.daily?.length" class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Daily trend</h3></div>
        <div class="card-body-responsive">
          <div class="space-y-1.5">
            <div v-for="d in learning.daily" :key="d.day" class="flex items-center gap-3 text-xs">
              <div class="w-24 text-surface-500 dark:text-surface-400">{{ d.day }}</div>
              <div class="flex-1 h-3 rounded bg-surface-100 dark:bg-surface-800 overflow-hidden flex">
                <div class="bg-red-500" :style="{ width: (learnDailyMax ? ((d.spam || 0) / learnDailyMax * 100) : 0) + '%' }"></div>
                <div class="bg-emerald-500" :style="{ width: (learnDailyMax ? ((d.ham || 0) / learnDailyMax * 100) : 0) + '%' }"></div>
              </div>
              <div class="w-32 text-right tabular-nums">
                <span class="text-red-600 dark:text-red-400">{{ d.spam || 0 }}</span>
                <span class="text-surface-400"> / </span>
                <span class="text-emerald-600 dark:text-emerald-400">{{ d.ham || 0 }}</span>
              </div>
            </div>
          </div>
          <p class="text-xs text-surface-400 mt-2">Red = spam reports, green = ham reports.</p>
        </div>
      </div>

      <!-- Top reporters -->
      <div v-if="learning.top_users?.length" class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Top reporters</h3></div>
        <div class="card-body-responsive">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>User</th>
                  <th class="text-right">Reported spam</th>
                  <th class="text-right">Marked not-spam</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in learning.top_users" :key="u.user_email">
                  <td class="font-mono text-xs">{{ u.user_email }}</td>
                  <td class="text-right">{{ u.spam || 0 }}</td>
                  <td class="text-right">{{ u.ham || 0 }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Recent events -->
      <div class="card">
        <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Recent feedback</h3></div>
        <div class="card-body-responsive">
          <p v-if="!learning.recent?.length" class="text-sm text-surface-400">No recent activity. Move a message to Junk in any client to test the loop.</p>
          <div v-else class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Direction</th>
                  <th>Source</th>
                  <th>User</th>
                  <th>Sender</th>
                  <th class="text-right">Trained</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, idx) in learning.recent" :key="idx">
                  <td class="text-xs whitespace-nowrap">{{ fmtTs(r.ts) }}</td>
                  <td><span class="badge" :class="learnDirectionClass(r.direction)">{{ r.direction === 'spam' ? 'Spam' : 'Ham' }}</span></td>
                  <td class="text-xs">{{ learnSourceLabel(r.source) }}</td>
                  <td class="font-mono text-xs">{{ r.user_email || '—' }}</td>
                  <td class="font-mono text-xs">{{ r.sender || '—' }}</td>
                  <td class="text-right text-xs">
                    <span v-if="r.opted_out" class="text-surface-400">opted out</span>
                    <span v-else-if="r.rspamc_rc === 0" class="text-emerald-600 dark:text-emerald-400">yes</span>
                    <span v-else-if="r.rspamc_rc === null" class="text-surface-400">—</span>
                    <span v-else class="text-amber-600 dark:text-amber-400" :title="'rspamc rc=' + r.rspamc_rc">failed</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- REPORTS placeholder -->
    <div v-else-if="activeTab === 'reports'" class="space-y-4 sm:space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <select v-model.number="reportDays" @change="loadReport" class="input max-w-[10rem]">
            <option :value="7">Last 7 days</option>
            <option :value="30">Last 30 days</option>
            <option :value="90">Last 90 days</option>
          </select>
          <button @click="loadReport" :disabled="reportLoading" class="btn btn-secondary btn-sm">
            {{ reportLoading ? 'Loading…' : 'Refresh' }}
          </button>
        </div>
        <button @click="downloadReportCsv" :disabled="reportCsvBusy" class="btn btn-primary btn-sm">
          {{ reportCsvBusy ? 'Exporting…' : 'Export CSV' }}
        </button>
      </div>

      <div v-if="reportLoading && !report" class="flex items-center gap-3 text-surface-500 dark:text-surface-400">
        <span class="spinner-sm"></span> Building report…
      </div>

      <template v-else-if="report">
        <div class="grid-responsive-4">
          <div class="stat-card"><div class="stat-value">{{ report.totals.total }}</div><div class="stat-label">Messages processed</div></div>
          <div class="stat-card"><div class="stat-value text-amber-600 dark:text-amber-400">{{ report.totals.spam }}</div><div class="stat-label">Spam / rejected</div></div>
          <div class="stat-card"><div class="stat-value text-red-600 dark:text-red-400">{{ report.totals.virus }}</div><div class="stat-label">Virus detections</div></div>
          <div class="stat-card"><div class="stat-value">{{ report.totals.quarantine }}</div><div class="stat-label">Quarantined</div></div>
        </div>

        <div class="grid-responsive-3">
          <div class="stat-card"><div class="stat-value text-2xl">{{ report.totals.spf_fail }}</div><div class="stat-label">SPF failures</div></div>
          <div class="stat-card"><div class="stat-value text-2xl">{{ report.totals.dkim_fail }}</div><div class="stat-label">DKIM failures</div></div>
          <div class="stat-card"><div class="stat-value text-2xl">{{ report.totals.dmarc_fail }}</div><div class="stat-label">DMARC failures</div></div>
        </div>

        <div class="card">
          <div class="card-header-responsive"><h3 class="font-semibold text-surface-900 dark:text-surface-100">Daily breakdown</h3></div>
          <div class="card-body-responsive">
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>Date</th><th>Total</th><th>Clean</th><th>Spam</th><th>Rejected</th><th>Quarantined</th><th>Virus</th></tr></thead>
                <tbody>
                  <tr v-for="d in report.daily" :key="d.day">
                    <td class="whitespace-nowrap">{{ d.day }}</td>
                    <td>{{ d.total }}</td>
                    <td class="text-surface-500 dark:text-surface-400">{{ d.clean }}</td>
                    <td>{{ d.spam }}</td>
                    <td>{{ d.reject }}</td>
                    <td>{{ d.quarantine }}</td>
                    <td>{{ d.virus }}</td>
                  </tr>
                  <tr v-if="!report.daily.length"><td colspan="7" class="text-surface-400">No events recorded in this period yet.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="grid-responsive-2">
          <div class="card">
            <div class="card-header-responsive">
              <h3 class="font-semibold text-surface-900 dark:text-surface-100">Top flagged senders</h3>
              <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">
                From-addresses whose mail was marked spam or rejected. If one is a legitimate
                contact, use <span class="font-medium">Allow</span> to add it to the global allow list.
              </p>
            </div>
            <div class="card-body-responsive">
              <p v-if="!report.top_senders.length" class="text-sm text-surface-400">No data yet.</p>
              <ul class="text-sm divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
                <li v-for="s in report.top_senders" :key="s.sender" class="flex items-center gap-2 py-2 first:pt-0">
                  <span class="min-w-0 flex-1 truncate font-mono text-xs text-surface-700 dark:text-surface-300" :title="s.sender">{{ s.sender }}</span>
                  <span class="shrink-0 tabular-nums text-surface-500 dark:text-surface-400">{{ s.cnt }}</span>
                  <span v-if="isAllowed(s.sender)" class="shrink-0 badge badge-success">Allowed</span>
                  <div v-else class="shrink-0 flex items-center gap-1">
                    <button
                      @click="allowSender(s.sender)"
                      :disabled="allowBusy === String(s.sender).toLowerCase()"
                      class="btn btn-ghost btn-sm"
                      title="Add this address to the global allow list"
                    >Allow</button>
                    <button
                      v-if="senderDomain(s.sender)"
                      @click="allowSender(s.sender, true)"
                      :disabled="allowBusy === String(s.sender).toLowerCase()"
                      class="btn btn-ghost btn-sm text-surface-500 dark:text-surface-400"
                      :title="'Allow the whole domain ' + senderDomain(s.sender)"
                    >+domain</button>
                  </div>
                </li>
              </ul>
            </div>
          </div>
          <div class="card">
            <div class="card-header-responsive">
              <h3 class="font-semibold text-surface-900 dark:text-surface-100">Most-targeted domains</h3>
              <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">
                Your hosted (recipient) domains that received the most spam — these are the targets,
                not the senders. Their own outbound mail is not being flagged.
              </p>
            </div>
            <div class="card-body-responsive">
              <p v-if="!report.top_domains.length" class="text-sm text-surface-400">No data yet.</p>
              <ul class="text-sm divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
                <li v-for="d in report.top_domains" :key="d.domain" class="flex items-center justify-between gap-3 py-2 first:pt-0">
                  <span class="min-w-0 flex-1 truncate font-mono text-xs text-surface-700 dark:text-surface-300" :title="d.domain">{{ d.domain }}</span>
                  <span class="shrink-0 tabular-nums text-surface-500 dark:text-surface-400">{{ d.cnt }}</span>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
