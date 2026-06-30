/**
 * Shared Node test runner for the mailsync server test scripts.
 *
 * Node counterpart of backend/tests/lib/test-runner.php. Per
 * .cursor/rules/server-side-testing.mdc every test script gets --help,
 * --verbose, --skip-send, --only=, --smoke, --json, a timestamped log under
 * server/logs/, colour output, named tests grouped by section, signal-safe
 * cleanup, a per-test timeout, and 0/1 exit semantics — all of which live
 * here so individual suites stay small.
 *
 *   import { NodeTestRunner } from './lib/testRunner.js'
 *   const r = new NodeTestRunner('fcm-delivery', process.argv)
 *   r.section('1. PREFLIGHT')
 *   await r.test('redis reachable', async () => { ... })
 *   process.exit(await r.finish())
 *
 * Test infrastructure only; never writes to production data.
 */

import { mkdirSync, appendFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))

const C = {
  reset: '\x1b[0m', green: '\x1b[32m', red: '\x1b[31m',
  yellow: '\x1b[33m', dim: '\x1b[2m', bold: '\x1b[1m',
}

export class NodeTestRunner {
  constructor(name, argv) {
    this.name = name
    this.verbose = false
    this.smoke = false
    this.skipSend = false
    this.json = false
    this.only = []
    this.opts = {}            // --key=value flags (e.g. token, email)
    this.flags = new Set()    // bare --flags (e.g. send)
    this.defaultTimeoutSec = 30

    for (const arg of argv.slice(2)) {
      if (arg === '--help' || arg === '-h') { this._printHelp(); process.exit(0) }
      else if (arg === '--verbose') this.verbose = true
      else if (arg === '--smoke') this.smoke = true
      else if (arg === '--skip-send') this.skipSend = true
      else if (arg === '--json') this.json = true
      else if (arg.startsWith('--only=')) this.only = arg.slice(7).split(',').map(s => s.trim()).filter(Boolean)
      else if (arg.startsWith('--timeout=')) this.defaultTimeoutSec = Math.max(1, parseInt(arg.slice(10), 10) || 30)
      else if (arg.startsWith('--') && arg.includes('=')) { const [k, ...v] = arg.slice(2).split('='); this.opts[k] = v.join('=') }
      else if (arg.startsWith('--')) this.flags.add(arg.slice(2))
    }

    this.useColor = process.stdout.isTTY && !this.json
    this.total = 0; this.passed = 0; this.failed = 0; this.warned = 0
    this.results = []
    this.cleanups = []
    this.currentSection = ''

    const logDir = resolve(__dirname, '..', '..', 'logs')
    try { mkdirSync(logDir, { recursive: true }) } catch { /* best-effort */ }
    const ts = new Date().toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 15)
    this.logFile = resolve(logDir, `${name}-${ts}.log`)

    for (const sig of ['SIGINT', 'SIGTERM']) {
      process.on(sig, async () => {
        await this._runCleanups()
        process.stderr.write(`\n[test-runner] ${sig}; cleanups complete\n`)
        process.exit(sig === 'SIGINT' ? 130 : 143)
      })
    }

    this.log(`=== ${name} — ${new Date().toISOString()} ===`)
    this.log(`verbose=${+this.verbose} smoke=${+this.smoke} skipSend=${+this.skipSend} `
      + `only=${this.only.length ? this.only.join(',') : '(all)'} log=${this.logFile}`)
  }

  _printHelp() {
    process.stdout.write(
      `FlowOne mailsync test runner — ${this.name}\n` +
      `  --help          Show this banner\n` +
      `  --verbose       Extra debug output (stack traces, raw FCM responses)\n` +
      `  --smoke         Quick health check mode (connectivity/config only)\n` +
      `  --skip-send     Skip every FCM network call (no dry-run, no delivery)\n` +
      `  --only=A,B      Run only the named test sections\n` +
      `  --timeout=N     Per-test wall-clock timeout in seconds (default 30)\n` +
      `  --json          Emit final results as JSON\n` +
      `  --token=TOKEN   Real FCM device token for end-to-end validation\n` +
      `  --email=ADDR    Override the test user email (default flowone_test_fcm@flowone.pro)\n` +
      `  --send          Actually DELIVER a push (default is a non-delivering dry-run)\n`
    )
  }

  section(label) { this.currentSection = label; this.log(`\n--- ${label} ---`) }

  shouldRunSection(label) {
    const needle = label.replace(/^\d+\.\s*/, '').toLowerCase()
    // Preflight is dependency setup (Redis/config), not an optional test group,
    // so it ALWAYS runs — otherwise --only=<group> would skip it and leave
    // redis/fcm uninitialized.
    if (needle.includes('preflight')) return true
    if (!this.only.length) return true
    return this.only.some(e => needle.includes(e.toLowerCase()))
  }

  addCleanup(fn) { this.cleanups.push(fn) }

  async _runCleanups() {
    for (const fn of [...this.cleanups].reverse()) {
      try { await fn() } catch (e) { this.log(`cleanup error: ${e.message}`) }
    }
    this.cleanups = []
  }

  async test(name, fn, timeoutSec = null) {
    this.total++
    const timeout = (timeoutSec ?? this.defaultTimeoutSec) * 1000
    const start = Date.now()
    let timer
    const guard = new Promise((_, rej) => {
      timer = setTimeout(() => rej(new Error(`test timed out after ${timeout}ms: ${name}`)), timeout)
    })
    try {
      const result = await Promise.race([Promise.resolve().then(fn), guard])
      const ms = Date.now() - start
      if (result === 'warn' || result === 'skip') {
        this.warned++
        this._record(result.toUpperCase(), name, ms)
      } else {
        this.passed++
        this._record('PASS', name, ms)
      }
    } catch (e) {
      const ms = Date.now() - start
      this.failed++
      this._record('FAIL', name, ms, e.message)
      if (this.verbose && e.stack) this.log(`          ${e.stack.split('\n').slice(1, 3).join('\n          ')}`)
    } finally {
      clearTimeout(timer)
    }
  }

  _record(status, name, ms, error) {
    const tag = { PASS: C.green, FAIL: C.red, WARN: C.yellow, SKIP: C.yellow }[status] || ''
    const label = this.useColor ? `${tag}${status}${C.reset}` : status
    this.log(`  [${label}]  ${name} ${this.useColor ? C.dim : ''}(${ms}ms)${this.useColor ? C.reset : ''}`)
    if (error) this.log(`          -> ${error}`)
    this.results.push({ name, status, ms, ...(error ? { error } : {}) })
  }

  log(msg) {
    process.stdout.write(msg + '\n')
    try {
      const plain = msg.replace(/\x1b\[[0-9;]*m/g, '')
      appendFileSync(this.logFile, `[${new Date().toTimeString().slice(0, 8)}] ${plain}\n`)
    } catch { /* best-effort */ }
  }

  async finish() {
    await this._runCleanups()
    this.log('')
    this.log(`Summary: total=${this.total} passed=${this.passed} failed=${this.failed} warned=${this.warned}`)
    if (this.failed > 0) {
      this.log('Failures:')
      for (const r of this.results) if (r.status === 'FAIL') this.log(`  - ${r.name}: ${r.error || ''}`)
    }
    if (this.json) {
      process.stdout.write(JSON.stringify({
        name: this.name, total: this.total, passed: this.passed,
        failed: this.failed, warned: this.warned, results: this.results,
      }, null, 2) + '\n')
    }
    return this.failed > 0 ? 1 : 0
  }

  assertTrue(cond, msg = 'assertion failed') { if (!cond) throw new Error(msg) }

  assertEquals(expected, actual, msg = 'mismatch') {
    const e = typeof expected === 'object' ? JSON.stringify(expected) : expected
    const a = typeof actual === 'object' ? JSON.stringify(actual) : actual
    if (e !== a) throw new Error(`${msg} expected=${e} actual=${a}`)
  }
}
