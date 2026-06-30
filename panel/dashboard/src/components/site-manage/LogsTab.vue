<script setup>
// LogsTab
// ---------------------------------------------------------------
// Log tail viewer for the V2 site management view. Replaces the
// "logs" section of SiteDetailView.vue. Read-only - this tab does
// not mutate anything.

import { computed, onMounted, ref, watch } from 'vue'
import api from '@/services/api'

const props = defineProps({ domain: { type: String, required: true } })

const logType = ref('error')
const lines = ref(100)
const text = ref('')
const search = ref('')
const loading = ref(false)
const quickFilters = [
  { id: '', label: 'All' },
  { id: 'PHP Fatal', label: 'PHP Fatal' },
  { id: '404', label: '404s' },
  { id: 'slow', label: 'Slow requests' },
  { id: 'ModSecurity', label: 'ModSec' },
]

// ─── Parsed line shape ───
// Each raw log line is classified into a presentational shape:
//   { raw, severity, timestamp, body }
// severity is one of: 'error' | 'warning' | 'notice' | 'info' | 'debug' | 'access'
// timestamp is the leading bracketed/quoted time, if any.
// body is everything after the recognised timestamp.
// This is pure pattern-matching on the raw line - no API contract
// changes; the log content endpoint still returns plain text.

const TIMESTAMP_RE =
  /^(?:\[)?([A-Za-z]{3}\s+[A-Za-z]{3}\s+\d+\s+[\d:.]+\s+\d{4}|\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?|\d{1,2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s+[+-]\d{4})(?:\])?/

const classifyLine = (line) => {
  const lower = line.toLowerCase()
  let severity = 'info'
  if (logType.value === 'access') {
    // Access logs: classify by HTTP status if present.
    const m = line.match(/"\s*(\d{3})\s+/) || line.match(/\s(\d{3})\s+\d+\s/)
    if (m) {
      const status = Number(m[1])
      if (status >= 500) severity = 'error'
      else if (status >= 400) severity = 'warning'
      else severity = 'access'
    } else {
      severity = 'access'
    }
  } else if (/fatal|emerg|alert|crit/i.test(lower)) {
    severity = 'error'
  } else if (/\berror\b|\[err\]|\bphp\s+error\b|\bexception\b/i.test(lower)) {
    severity = 'error'
  } else if (/\bwarn(?:ing)?\b|\[warn\]|deprecated|modsecurity/i.test(lower)) {
    severity = 'warning'
  } else if (/\bnotice\b|\[notice\]/i.test(lower)) {
    severity = 'notice'
  } else if (/\bdebug\b|\[debug\]/i.test(lower)) {
    severity = 'debug'
  }

  // Strip a leading recognised timestamp into its own field so the
  // body reads cleanly and the timestamp can be muted visually.
  let body = line
  let timestamp = ''
  const tsMatch = line.match(TIMESTAMP_RE)
  if (tsMatch) {
    timestamp = tsMatch[0]
    body = line.slice(tsMatch[0].length).replace(/^\s+/, '')
  }
  return { raw: line, severity, timestamp, body }
}

const filteredLines = computed(() => {
  if (!text.value) return []
  const all = text.value.split('\n')
  const filtered = search.value
    ? all.filter((l) => l.toLowerCase().includes(search.value.toLowerCase()))
    : all
  return filtered.map(classifyLine)
})

// Per-severity classes for the colored border + level badge.
const severityStyle = (sev) => {
  switch (sev) {
    case 'error':
      return {
        row: 'border-l-2 border-red-500/70 hover:bg-red-500/5',
        badge: 'bg-red-500/20 text-red-300',
        body: 'text-red-200',
      }
    case 'warning':
      return {
        row: 'border-l-2 border-amber-500/70 hover:bg-amber-500/5',
        badge: 'bg-amber-500/20 text-amber-200',
        body: 'text-amber-100',
      }
    case 'notice':
      return {
        row: 'border-l-2 border-sky-500/70 hover:bg-sky-500/5',
        badge: 'bg-sky-500/20 text-sky-200',
        body: 'text-sky-100',
      }
    case 'debug':
      return {
        row: 'border-l-2 border-purple-500/60 hover:bg-purple-500/5',
        badge: 'bg-purple-500/20 text-purple-200',
        body: 'text-purple-100',
      }
    case 'access':
      return {
        row: 'border-l-2 border-emerald-500/40 hover:bg-emerald-500/5',
        badge: 'bg-emerald-500/15 text-emerald-200',
        body: 'text-surface-200',
      }
    default:
      return {
        row: 'border-l-2 border-transparent hover:bg-white/5',
        badge: 'bg-surface-700 text-surface-200',
        body: 'text-surface-200',
      }
  }
}

const severityLabel = (sev) => {
  switch (sev) {
    case 'error': return 'ERROR'
    case 'warning': return 'WARN'
    case 'notice': return 'NOTICE'
    case 'debug': return 'DEBUG'
    case 'access': return 'ACCESS'
    default: return 'INFO'
  }
}

const fetchLogs = async () => {
  loading.value = true
  try {
    const r = await api.get(
      `/sites/${encodeURIComponent(props.domain)}/logs`,
      { params: { type: logType.value, lines: lines.value } },
    )
    if (r.data?.success) {
      const d = r.data.data ?? {}
      // Agent returns the tail as an array of lines under `lines`; older
      // shapes may return a pre-joined string under `content`/`text`.
      text.value = Array.isArray(d.lines)
        ? d.lines.join('\n')
        : (d.content ?? d.text ?? '')
    }
  } catch {
    text.value = ''
  } finally {
    loading.value = false
  }
}

watch([logType, lines], fetchLogs)

onMounted(fetchLogs)
</script>

<template>
  <div class="space-y-4">
    <!-- ─── Controls ─── -->
    <div class="card">
      <div class="card-header flex items-center gap-2">
        <span class="material-symbols-rounded text-blue-500">article</span>
        <h3 class="font-semibold">Logs</h3>
        <span
          v-if="!loading && filteredLines.length"
          class="text-xs text-surface-500 dark:text-surface-400 ml-auto"
        >
          {{ filteredLines.length }} line{{ filteredLines.length === 1 ? '' : 's' }}
        </span>
      </div>
      <div class="card-body space-y-3">
        <div class="flex items-center gap-2 flex-wrap">
          <select v-model="logType" class="input w-auto">
            <option value="error">Error log</option>
            <option value="access">Access log</option>
          </select>
          <select v-model.number="lines" class="input w-auto">
            <option :value="50">50 lines</option>
            <option :value="100">100 lines</option>
            <option :value="500">500 lines</option>
            <option :value="1000">1000 lines</option>
          </select>
          <div class="relative flex-1 min-w-[10rem]">
            <span
              class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2
                     text-base text-surface-400"
            >search</span>
            <input
              v-model="search"
              type="text"
              placeholder="Search…"
              class="input pl-10"
            />
          </div>
          <button class="btn-secondary btn-sm" :disabled="loading" @click="fetchLogs">
            <span
              class="material-symbols-rounded text-sm"
              :class="{ 'animate-spin': loading }"
            >refresh</span>
            Refresh
          </button>
        </div>

        <!-- Quick filter pills -->
        <div class="flex gap-1.5 flex-wrap">
          <button
            v-for="f in quickFilters"
            :key="f.id"
            class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
            :class="
              search === f.id
                ? 'bg-primary-500 text-white shadow-sm'
                : 'bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))]'
            "
            @click="search = f.id"
          >
            {{ f.label }}
          </button>
        </div>
      </div>
    </div>

    <!-- ─── Parsed, color-coded log viewer ───
         Each line is classified into a severity (error/warn/notice/
         info/debug/access). The class drives a left border accent
         color, a per-line level badge, and the body text color so
         operators can spot errors at a glance. Pure presentation -
         the underlying log content endpoint is unchanged. -->
    <div
      class="rounded-2xl border border-surface-800 dark:border-[rgb(var(--color-border))]
             bg-surface-900 dark:bg-[rgb(var(--color-bg))]
             font-mono text-xs overflow-auto max-h-[70vh]"
    >
      <div v-if="loading" class="p-4 space-y-1.5">
        <div class="skeleton h-3 w-3/4 rounded" />
        <div class="skeleton h-3 w-1/2 rounded" />
        <div class="skeleton h-3 w-5/6 rounded" />
        <div class="skeleton h-3 w-2/3 rounded" />
        <div class="skeleton h-3 w-3/4 rounded" />
      </div>
      <div
        v-else-if="!filteredLines.length"
        class="p-8 text-center text-surface-400 flex flex-col items-center gap-2"
      >
        <span class="material-symbols-rounded text-3xl">filter_alt_off</span>
        <span>No log lines matching filter.</span>
      </div>
      <div v-else class="py-1">
        <div
          v-for="(line, i) in filteredLines"
          :key="i"
          class="px-3 py-1 flex items-start gap-2 transition-colors"
          :class="severityStyle(line.severity).row"
        >
          <span
            class="px-1.5 rounded text-[9px] font-bold tracking-wider shrink-0 mt-px"
            :class="severityStyle(line.severity).badge"
          >
            {{ severityLabel(line.severity) }}
          </span>
          <span
            v-if="line.timestamp"
            class="text-surface-500 shrink-0 select-none"
          >{{ line.timestamp }}</span>
          <span
            class="flex-1 whitespace-pre-wrap break-all"
            :class="severityStyle(line.severity).body"
          >{{ line.body }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
