<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'

/**
 * PerfHud — floating performance HUD toggled by Ctrl+Shift+P.
 *
 * Wave C.5 of drive-perf-fix-v2.
 *
 * Shows live metrics from main: event-loop p99/p95/p50, sync scheduler
 * counters, upload queue depth + lanes, hash skip rate, registered
 * intervals (with tick / skipped / error counts), recent log lines,
 * histograms (sync cycle, db write p95, IPC handler latency), counters
 * (cycle_skipped_due_to_inflight, ipc.errors), gauges (queue depths),
 * rates (events/min, files/sec).
 *
 * Designed to be a debug surface — every metric is read-only. The HUD
 * itself polls `get-perf-snapshot` every 1500 ms while visible. When
 * hidden, polling stops entirely so the HUD never causes the freezes
 * it's diagnosing.
 */

const visible = ref(false)
const snapshot = ref<any>(null)
const lastError = ref<string | null>(null)
let refreshTimer: NodeJS.Timeout | null = null

function onKeydown(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'P' || e.key === 'p')) {
    e.preventDefault()
    toggle()
  }
}

function toggle() {
  visible.value = !visible.value
  if (visible.value) {
    refresh()
    refreshTimer = setInterval(refresh, 1500)
  } else if (refreshTimer) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
}

async function refresh() {
  try {
    const fn = (window.api as any)?.getPerfSnapshot
    if (!fn) {
      lastError.value = 'getPerfSnapshot bridge missing'
      return
    }
    snapshot.value = await fn()
    lastError.value = null
  } catch (e: any) {
    lastError.value = e?.message ?? String(e)
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
})
onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  if (refreshTimer) clearInterval(refreshTimer)
})

const eventLoop = computed(() => snapshot.value?.eventLoop ?? null)
const scheduler = computed(() => snapshot.value?.scheduler ?? null)
const uploadQueue = computed(() => snapshot.value?.uploadQueue ?? null)
const intervals = computed(() => snapshot.value?.intervals ?? [])
const histograms = computed(() => snapshot.value?.metrics?.histograms ?? {})
const counters = computed(() => snapshot.value?.metrics?.counters ?? {})
const gauges = computed(() => snapshot.value?.metrics?.gauges ?? {})
const rates = computed(() => snapshot.value?.metrics?.rates ?? {})
const recentLogs = computed(() => snapshot.value?.recentLogs ?? [])
const hashSkip = computed(() => snapshot.value?.hashSkip ?? null)
const dbQueue = computed(() => snapshot.value?.db ?? null)
const logLevel = computed(() => snapshot.value?.logLevel ?? 'info')

function fmtMs(v: number | null | undefined): string {
  if (v == null) return '—'
  return `${v.toFixed ? v.toFixed(1) : v}ms`
}

function logColor(level: string): string {
  switch (level) {
    case 'error': return '#ef4444'
    case 'warn':  return '#f59e0b'
    case 'info':  return '#22c55e'
    case 'debug': return '#60a5fa'
    case 'trace': return '#9ca3af'
    default:      return '#d1d5db'
  }
}

function eventLoopColor(p99: number): string {
  if (p99 >= 200) return '#ef4444'
  if (p99 >= 100) return '#f59e0b'
  if (p99 >= 50)  return '#fde047'
  return '#22c55e'
}

async function setLogLevel(level: string) {
  try {
    const fn = (window.api as any)?.setLogLevel
    if (fn) await fn(level)
    refresh()
  } catch { /* ignore */ }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      style="position: fixed; right: 12px; bottom: 12px; z-index: 99999; width: 460px;
             max-height: 80vh; overflow: auto; background: rgba(15,15,20,0.94);
             border: 1px solid #2a2a32; border-radius: 12px; padding: 14px 16px;
             box-shadow: 0 16px 40px rgba(0,0,0,0.6); color: #d1d5db;
             font-family: 'JetBrains Mono', 'Consolas', monospace; font-size: 11px;
             line-height: 1.55;"
    >
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
        <h3 style="font-size: 13px; font-weight: 600; color: #22c55e;">FlowOne Drive — Perf HUD</h3>
        <button @click="toggle" style="color: #9ca3af; font-size: 11px;">close (Ctrl+Shift+P)</button>
      </div>

      <div v-if="lastError" style="color: #ef4444; margin-bottom: 8px;">
        Snapshot error: {{ lastError }}
      </div>

      <!-- Event loop -->
      <section style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">Event loop</div>
        <div v-if="eventLoop">
          <span :style="{ color: eventLoopColor(eventLoop.p99Ms), fontWeight: 700 }">
            p99 {{ fmtMs(eventLoop.p99Ms) }}
          </span>
          · p95 {{ fmtMs(eventLoop.p95Ms) }}
          · p50 {{ fmtMs(eventLoop.p50Ms) }}
          · max {{ fmtMs(eventLoop.maxMs) }}
        </div>
        <div v-else style="color: #6b7280;">no samples yet</div>
      </section>

      <!-- Scheduler -->
      <section style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">Sync scheduler</div>
        <div v-if="scheduler">
          ran {{ scheduler.cyclesRan }} · queued {{ scheduler.cyclesQueued }}
          · coalesced {{ scheduler.cyclesCoalesced }}
          · skipped-inflight {{ scheduler.cyclesSkippedDueToInflight }}
        </div>
        <div v-else style="color: #6b7280;">no scheduler</div>
      </section>

      <!-- Upload queue -->
      <section style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">Upload queue</div>
        <div v-if="uploadQueue">
          depth {{ uploadQueue.total_depth }} (lane0 {{ uploadQueue.lane0_depth }} · lane1 {{ uploadQueue.lane1_depth }})
          · in-flight {{ uploadQueue.in_flight }}
          · ok {{ uploadQueue.succeeded_total }}
          · fail {{ uploadQueue.failed_total }}
          · retried {{ uploadQueue.retried_total }}
        </div>
        <div v-else style="color: #6b7280;">queue not initialised</div>
      </section>

      <!-- Hash skip -->
      <section v-if="hashSkip" style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">Hash skip (Wave A.7)</div>
        <div>
          rate {{ Math.round((hashSkip.rate || 0) * 100) }}%
          · skips {{ hashSkip.skips }}
          · recomputes {{ hashSkip.recomputes }}
        </div>
      </section>

      <!-- DB queue -->
      <section v-if="dbQueue" style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">DB queue</div>
        <div>
          ops {{ dbQueue.totalOps }}
          · last {{ dbQueue.lastDurationMs ?? '—' }}ms
          · max {{ dbQueue.maxDurationMs ?? '—' }}ms
        </div>
      </section>

      <!-- Histograms -->
      <section v-if="Object.keys(histograms).length" style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">Histograms</div>
        <div v-for="(v, k) in histograms" :key="k" style="margin-left: 6px;">
          {{ k }}: p50 {{ fmtMs((v as any).p50) }} · p95 {{ fmtMs((v as any).p95) }} · p99 {{ fmtMs((v as any).p99) }} · n={{ (v as any).count }}
        </div>
      </section>

      <!-- Rates / counters / gauges -->
      <section v-if="Object.keys(rates).length || Object.keys(counters).length || Object.keys(gauges).length" style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">Rates / counters / gauges</div>
        <div v-for="(v, k) in rates" :key="`r-${k}`" style="margin-left: 6px;">
          {{ k }} (rate): {{ (v as any).perMin }}/min · {{ (v as any).per5Min }}/5min
        </div>
        <div v-for="(v, k) in counters" :key="`c-${k}`" style="margin-left: 6px;">
          {{ k }}: {{ v }}
        </div>
        <div v-for="(v, k) in gauges" :key="`g-${k}`" style="margin-left: 6px;">
          {{ k }} (gauge): {{ v }}
        </div>
      </section>

      <!-- Intervals -->
      <section v-if="intervals.length" style="margin-bottom: 10px;">
        <div style="font-weight: 600; color: #ffffff; margin-bottom: 4px;">Intervals</div>
        <div v-for="i in intervals" :key="i.name" style="margin-left: 6px;">
          {{ i.name }} @ {{ i.intervalMs }}ms · ticks {{ i.ticks }}
          · skipped {{ i.skipped }} · errors {{ i.errors }}
          · last {{ i.lastDurationMs ?? '—' }}ms
          <span v-if="i.paused" style="color: #f59e0b;"> (paused)</span>
        </div>
      </section>

      <!-- Logger -->
      <section style="margin-bottom: 10px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
          <div style="font-weight: 600; color: #ffffff;">Recent logs ({{ logLevel }})</div>
          <div>
            <button v-for="lvl in ['error','warn','info','debug','trace']" :key="lvl"
                    @click="setLogLevel(lvl)"
                    :style="{ marginLeft: '4px', padding: '1px 6px', borderRadius: '4px',
                              background: lvl === logLevel ? '#22c55e' : '#1f2937',
                              color: lvl === logLevel ? '#04150a' : '#9ca3af',
                              fontSize: '10px' }">
              {{ lvl }}
            </button>
          </div>
        </div>
        <div style="max-height: 160px; overflow: auto; background: #0a0a0d; padding: 6px 8px; border-radius: 6px;">
          <div v-for="(l, idx) in recentLogs" :key="idx" style="white-space: pre-wrap; word-break: break-all;">
            <span :style="{ color: logColor((l as any).level) }">{{ (l as any).level.toUpperCase() }}</span>
            <span style="color: #6b7280;"> [{{ (l as any).channel }}]</span>
            <span> {{ (l as any).message }}</span>
          </div>
          <div v-if="!recentLogs.length" style="color: #6b7280;">no log entries yet</div>
        </div>
      </section>
    </div>
  </Teleport>
</template>
