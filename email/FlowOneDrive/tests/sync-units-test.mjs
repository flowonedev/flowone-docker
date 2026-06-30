#!/usr/bin/env node
/**
 * sync-units-test.mjs — unit tests for the Wave D sync-spinner fixes.
 *
 * Covers:
 *   - UploadQueue: promise settlement guarantees (success, final failure,
 *     deadline expiry, destroy, no retry after abandonment)
 *   - SyncScheduler: minimum inter-cycle gap, requestImmediate bypass,
 *     pause/resume preservation of deferred runs
 *
 * Run (from email/FlowOneDrive):
 *   node tests/sync-units-test.mjs [--verbose] [--json] [--only=queue,scheduler] [--skip-build] [--help]
 *
 * The script compiles the main process first (npx tsc -p tsconfig.main.json)
 * unless --skip-build is given, then tests the compiled dist/ output.
 * Exit code 0 = all pass, 1 = any failure.
 */

import { spawnSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import fs from 'node:fs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(__dirname, '..')
const require = createRequire(import.meta.url)

// ---------------------------------------------------------------- CLI flags
const args = process.argv.slice(2)
const FLAGS = {
  help: args.includes('--help'),
  verbose: args.includes('--verbose'),
  json: args.includes('--json'),
  skipBuild: args.includes('--skip-build'),
  only: (args.find(a => a.startsWith('--only=')) || '').replace('--only=', '')
    .split(',').filter(Boolean),
}

if (FLAGS.help) {
  console.log(`Usage: node tests/sync-units-test.mjs [options]

Options:
  --help          Show this help
  --verbose       Extra debug output (stack traces)
  --json          Output results as JSON
  --only=a,b      Run only listed groups (queue, scheduler)
  --skip-build    Skip the tsc build, use existing dist/ output

Run from the email/FlowOneDrive directory.`)
  process.exit(0)
}

// ------------------------------------------------------------------- build
if (!FLAGS.skipBuild) {
  if (!FLAGS.json) console.log('[build] npx tsc -p tsconfig.main.json ...')
  const res = spawnSync('npx', ['tsc', '-p', 'tsconfig.main.json'], {
    cwd: ROOT,
    shell: process.platform === 'win32',
    stdio: FLAGS.verbose ? 'inherit' : 'pipe',
  })
  if (res.status !== 0) {
    console.error('[build] tsc failed — fix compile errors first')
    if (!FLAGS.verbose && res.stdout) console.error(String(res.stdout))
    process.exit(1)
  }
}

const distQueue = path.join(ROOT, 'dist', 'main', 'sync', 'UploadQueue.js')
const distSched = path.join(ROOT, 'dist', 'main', 'sync', 'SyncScheduler.js')
if (!fs.existsSync(distQueue) || !fs.existsSync(distSched)) {
  console.error(`[build] compiled output missing (${distQueue}); run without --skip-build`)
  process.exit(1)
}
const { UploadQueue } = require(distQueue)
const { SyncScheduler } = require(distSched)

// --------------------------------------------------------------- harness
const GREEN = '\x1b[32m', RED = '\x1b[31m', YELLOW = '\x1b[33m', RESET = '\x1b[0m'
const results = []
const TEST_TIMEOUT_MS = 5_000

const sleep = (ms) => new Promise(r => setTimeout(r, ms))
async function waitFor(cond, timeoutMs = 2_000, stepMs = 10) {
  const start = Date.now()
  while (!cond()) {
    if (Date.now() - start > timeoutMs) throw new Error(`waitFor timeout after ${timeoutMs}ms`)
    await sleep(stepMs)
  }
}
function assert(actual, expected, label) {
  if (actual !== expected) throw new Error(`${label}: expected ${expected}, got ${actual}`)
}
function assertTrue(value, label) {
  if (!value) throw new Error(`${label}: expected truthy, got ${value}`)
}

async function runTest(group, name, fn) {
  if (FLAGS.only.length && !FLAGS.only.includes(group)) return
  const start = Date.now()
  let outcome = 'PASS', error = null
  try {
    await Promise.race([
      fn(),
      sleep(TEST_TIMEOUT_MS).then(() => { throw new Error(`test timeout after ${TEST_TIMEOUT_MS}ms`) }),
    ])
  } catch (err) {
    outcome = 'FAIL'
    error = err
  }
  const ms = Date.now() - start
  results.push({ group, name, outcome, ms, error: error ? error.message : null })
  if (!FLAGS.json) {
    const color = outcome === 'PASS' ? GREEN : RED
    console.log(`  ${color}[${outcome}]${RESET} ${name} (${ms}ms)`)
    if (error && FLAGS.verbose) console.log(error.stack)
    else if (error) console.log(`         ${RED}${error.message}${RESET}`)
  }
}

// ---------------------------------------------------------- queue tests
async function queueTests() {
  if (!FLAGS.json) console.log('\n--- 1. UPLOAD QUEUE ---')

  await runTest('queue', 'enqueue resolves on worker success', async () => {
    const q = new UploadQueue({ defaultDeadlineMs: 2_000 })
    q.setWorker(async () => {})
    await q.enqueue({ n: 1 })
    assert(q.getCounters().succeeded_total, 1, 'succeeded_total')
    q.destroy()
  })

  await runTest('queue', 'enqueue rejects after retries exhausted', async () => {
    const q = new UploadQueue({ baseBackoffMs: 10, maxBackoffMs: 20, defaultMaxAttempts: 2, defaultDeadlineMs: 2_000 })
    q.setWorker(async () => { throw new Error('boom') })
    let rejected = null
    await q.enqueue({ n: 1 }).catch(e => { rejected = e })
    assertTrue(rejected, 'promise rejected')
    assert(q.getCounters().failed_total, 1, 'failed_total')
    assert(q.getCounters().retried_total, 1, 'retried_total')
    q.destroy()
  })

  await runTest('queue', 'deadline rejects job stranded in PAUSED queue (the spinner hang)', async () => {
    const q = new UploadQueue({ defaultDeadlineMs: 150 })
    q.setWorker(async () => {})
    q.pause()
    let rejected = null
    const started = Date.now()
    await q.enqueue({ n: 1 }).catch(e => { rejected = e })
    assertTrue(rejected && /deadline/.test(rejected.message), 'rejected with deadline error')
    assertTrue(Date.now() - started < 1_000, 'settled promptly, not hung')
    assert(q.getCounters().expired_total, 1, 'expired_total')
    q.destroy()
  })

  await runTest('queue', 'deadline rejects while worker is hung in-flight', async () => {
    const q = new UploadQueue({ defaultDeadlineMs: 150 })
    q.setWorker(() => new Promise(() => {})) // never settles
    let rejected = null
    await q.enqueue({ n: 1 }).catch(e => { rejected = e })
    assertTrue(rejected && /deadline/.test(rejected.message), 'rejected with deadline error')
    q.destroy()
  })

  await runTest('queue', 'destroy rejects all pending promises', async () => {
    const q = new UploadQueue({ defaultDeadlineMs: 60_000 })
    q.setWorker(async () => {})
    q.pause()
    const outcomes = []
    const p1 = q.enqueue({ n: 1 }).catch(e => outcomes.push(e.message))
    const p2 = q.enqueue({ n: 2 }).catch(e => outcomes.push(e.message))
    q.destroy()
    await Promise.all([p1, p2])
    assert(outcomes.length, 2, 'both rejected')
    assertTrue(outcomes.every(m => /destroyed/.test(m)), 'rejected with destroy error')
  })

  await runTest('queue', 'expired job is abandoned (no retries after deadline)', async () => {
    let attempts = 0
    const q = new UploadQueue({ baseBackoffMs: 500, defaultMaxAttempts: 5, defaultDeadlineMs: 150 })
    q.setWorker(async () => { attempts += 1; throw new Error('boom') })
    await q.enqueue({ n: 1 }).catch(() => {})
    await sleep(800) // past the first retry's backoff window
    assert(attempts, 1, 'attempts after expiry')
    q.destroy()
  })

  await runTest('queue', 'bulk enqueue settles every promise (no listener-pair hang)', async () => {
    const q = new UploadQueue({ defaultDeadlineMs: 5_000, concurrency: 3 })
    q.setWorker(async () => { await sleep(1) })
    const all = await Promise.allSettled(Array.from({ length: 50 }, (_, i) => q.enqueue({ i })))
    assertTrue(all.every(r => r.status === 'fulfilled'), 'all 50 fulfilled')
    assert(q.getCounters().succeeded_total, 50, 'succeeded_total')
    q.destroy()
  })
}

// ------------------------------------------------------- scheduler tests
async function schedulerTests() {
  if (!FLAGS.json) console.log('\n--- 2. SYNC SCHEDULER ---')

  await runTest('scheduler', 'queued follow-up honors minimum inter-cycle gap', async () => {
    const sched = new SyncScheduler({ debounceMs: 10, minGapMs: 300 })
    const starts = [], ends = []
    sched.setRunCycle(async () => { starts.push(Date.now()); await sleep(50); ends.push(Date.now()) })
    sched.requestImmediate('first')
    await sleep(20)
    sched.request('second') // arrives while first is in flight -> queued
    await waitFor(() => starts.length === 2, 3_000)
    const gap = starts[1] - ends[0]
    assertTrue(gap >= 250, `gap between cycles >= 250ms (got ${gap}ms)`)
    assertTrue(sched.getCounters().cycle_deferred_gap >= 1, 'cycle_deferred_gap counted')
  })

  await runTest('scheduler', 'requestImmediate bypasses the gap (Sync Now button)', async () => {
    const sched = new SyncScheduler({ debounceMs: 10, minGapMs: 5_000 })
    const starts = []
    sched.setRunCycle(async () => { starts.push(Date.now()) })
    sched.requestImmediate('first')
    await waitFor(() => starts.length === 1, 1_000)
    const before = Date.now()
    sched.requestImmediate('user-sync-now')
    await waitFor(() => starts.length === 2, 1_000)
    assertTrue(starts[1] - before < 200, `immediate start (took ${starts[1] - before}ms)`)
  })

  await runTest('scheduler', 'pause preserves gap-deferred run; resume executes it', async () => {
    const sched = new SyncScheduler({ debounceMs: 10, minGapMs: 300 })
    const starts = []
    sched.setRunCycle(async () => { starts.push(Date.now()) })
    sched.requestImmediate('first')
    await waitFor(() => starts.length === 1, 1_000)
    sched.request('deferred') // within gap -> deferAfterGap
    await sleep(50)
    sched.pause()
    await sleep(400) // gap timer would have fired by now if not cleared
    assert(starts.length, 1, 'no run while paused')
    sched.resume()
    await waitFor(() => starts.length === 2, 2_000)
  })

  await runTest('scheduler', 'requests while paused are dropped, resume() recovers queued one', async () => {
    const sched = new SyncScheduler({ debounceMs: 10, minGapMs: 0 })
    const starts = []
    sched.setRunCycle(async () => { starts.push(Date.now()); await sleep(100) })
    sched.requestImmediate('first')
    await sleep(20)
    sched.request('while-busy') // queued behind in-flight
    sched.pause()
    await sleep(200)
    assert(starts.length, 1, 'queued follow-up not run while paused')
    sched.resume()
    await waitFor(() => starts.length === 2, 1_000)
  })
}

// ------------------------------------------------------------------ main
const t0 = Date.now()
if (!FLAGS.json) console.log('FlowOne Drive — sync unit tests (Wave D spinner fixes)')

await queueTests()
await schedulerTests()

const passed = results.filter(r => r.outcome === 'PASS').length
const failed = results.filter(r => r.outcome === 'FAIL')

if (FLAGS.json) {
  console.log(JSON.stringify({ passed, failed: failed.length, total: results.length, elapsed_ms: Date.now() - t0, results }, null, 2))
} else {
  console.log(`\n=== SUMMARY: ${passed} passed, ${failed.length} failed, ${results.length} total (${Date.now() - t0}ms) ===`)
  for (const f of failed) {
    console.log(`  ${RED}FAILED${RESET} [${f.group}] ${f.name}: ${f.error}`)
  }
  if (failed.length === 0) console.log(`${GREEN}All tests passed.${RESET}`)
}

process.exit(failed.length === 0 ? 0 : 1)
