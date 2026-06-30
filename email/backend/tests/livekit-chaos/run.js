#!/usr/bin/env node
// @ts-check
/**
 * Node orchestrator for the LiveKit chaos suite. Wraps `npx playwright test` with
 * the same CLI flags the rest of the FlowOne test harness uses:
 *
 *   --help                Show usage
 *   --verbose             Print Playwright output line-by-line
 *   --skip-send           Pass-through to fixtures: skip provisioning, use envs
 *   --smoke               Run only @smoke-tagged scenarios
 *   --json                Emit a single-line summary JSON on stdout
 *   --only=a,b,c          Run only specific scenario file stems (e.g. waiting_room_flow)
 *
 * Exits 0 on all-pass, 1 on any failure, 2 on bad arguments.
 */

const { spawn } = require('child_process')
const fs = require('fs')
const path = require('path')

const HERE = __dirname
const REPORT_PATH = path.join(HERE, 'report.json')

function parseArgs(argv) {
  const out = { help: false, verbose: false, smoke: false, json: false, skipSend: false, only: null, project: null }
  for (const a of argv.slice(2)) {
    if (a === '--help' || a === '-h') out.help = true
    else if (a === '--verbose') out.verbose = true
    else if (a === '--smoke') out.smoke = true
    else if (a === '--json') out.json = true
    else if (a === '--skip-send') out.skipSend = true
    else if (a.startsWith('--only=')) out.only = a.slice('--only='.length).split(',').map((s) => s.trim()).filter(Boolean)
    else if (a.startsWith('--project=')) out.project = a.slice('--project='.length).split(',').map((s) => s.trim()).filter(Boolean)
    else if (a.startsWith('--base-url=')) process.env.FLOWONE_BASE_URL = a.slice('--base-url='.length)
    else {
      console.error('Unknown arg: ' + a)
      process.exit(2)
    }
  }
  return out
}

function usage() {
  console.log('FlowOne LiveKit chaos suite')
  console.log('')
  console.log('Usage: node run.js [--verbose] [--smoke] [--json] [--skip-send] [--only=a,b,...] [--project=desktop-chromium|mobile-ios] [--base-url=URL]')
  console.log('')
  console.log('Scenarios:')
  const dir = path.join(HERE, 'scenarios')
  for (const f of fs.readdirSync(dir).sort()) {
    if (f.endsWith('.spec.js')) console.log('  - ' + f.replace('.spec.js', ''))
  }
}

function resolvePlaywrightCli() {
  // Resolve the Playwright CLI without relying on `exports` (which blocks
  // require.resolve('@playwright/test/cli.js')) or shell PATH (which breaks
  // `npx playwright`). Strategy: read each candidate package's own bin entry
  // and join it against the package root.
  const candidates = ['@playwright/test', 'playwright']
  for (const name of candidates) {
    try {
      const pkgPath = path.join(HERE, 'node_modules', ...name.split('/'), 'package.json')
      if (!fs.existsSync(pkgPath)) continue
      const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'))
      const bin = pkg && pkg.bin
      const rel = typeof bin === 'string' ? bin : (bin && (bin.playwright || bin[name]))
      if (!rel) continue
      const cliPath = path.join(path.dirname(pkgPath), rel)
      if (fs.existsSync(cliPath)) return cliPath
    } catch {}
  }
  // Last resort: the bin symlink under node_modules/.bin (resolve the real
  // target so we can invoke it with `node` rather than as a shell command).
  const binName = process.platform === 'win32' ? 'playwright.cmd' : 'playwright'
  const binPath = path.join(HERE, 'node_modules', '.bin', binName)
  if (fs.existsSync(binPath)) {
    try {
      const real = fs.realpathSync(binPath)
      if (real.endsWith('.js')) return real
      return binPath
    } catch {
      return binPath
    }
  }
  return null
}

function runPlaywright(args) {
  return new Promise((resolve) => {
    const cli = resolvePlaywrightCli()
    if (!cli) {
      resolve({ code: 1, stdout: '', stderr: 'Cannot find @playwright/test CLI; did you run `npm install` in this directory?' })
      return
    }
    const isJsCli = cli.endsWith('.js')
    const cmd = isJsCli ? process.execPath : cli
    const cmdArgs = isJsCli ? [cli, 'test', ...args] : ['test', ...args]
    const child = spawn(cmd, cmdArgs, {
      cwd: HERE,
      env: {
        ...process.env,
        // Force the JSON reporter to write to a known on-disk path so we can
        // always render a structured summary, even when stdout was very long
        // or got truncated by the caller's terminal.
        PLAYWRIGHT_JSON_OUTPUT_NAME: REPORT_PATH,
      },
      stdio: ['ignore', 'pipe', 'pipe'],
      shell: false,
    })
    let stdout = ''
    let stderr = ''
    child.stdout.on('data', (chunk) => {
      stdout += chunk.toString()
      if (process.env.FLOWONE_CHAOS_VERBOSE === '1') process.stdout.write(chunk)
    })
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString()
      if (process.env.FLOWONE_CHAOS_VERBOSE === '1') process.stderr.write(chunk)
    })
    child.on('error', (err) => {
      resolve({ code: 1, stdout, stderr: stderr + '\n[spawn error] ' + (err && err.message || err) })
    })
    child.on('close', (code) => resolve({ code: code ?? 1, stdout, stderr }))
  })
}

function preflight() {
  const issues = []
  if (!process.env.FLOWONE_BASE_URL) issues.push('FLOWONE_BASE_URL is not set')
  try {
    const pwPkg = require.resolve('@playwright/test/package.json', { paths: [HERE] })
    if (!pwPkg) issues.push('@playwright/test not installed (run: npm install)')
  } catch {
    issues.push('@playwright/test not installed (run: npm install)')
  }
  return issues
}

async function main() {
  const args = parseArgs(process.argv)
  if (args.help) {
    usage()
    process.exit(0)
  }

  if (args.verbose) process.env.FLOWONE_CHAOS_VERBOSE = '1'

  const issues = preflight()
  if (issues.length) {
    if (args.json) {
      console.log(JSON.stringify({ status: 'preflight-fail', issues }))
    } else {
      console.error('Pre-flight failed:')
      for (const i of issues) console.error('  - ' + i)
    }
    process.exit(1)
  }

  const pwArgs = []
  if (args.smoke) pwArgs.push('--grep', '@smoke')
  if (args.only && args.only.length) {
    const escaped = args.only.map((n) => n.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')).join('|')
    pwArgs.push('--grep', `(${escaped})`)
  }
  if (args.project && args.project.length) {
    for (const p of args.project) pwArgs.push('--project', p)
  }
  // Always force list+json reporters. Without an explicit list reporter the
  // default `dot` reporter swallows the error body when stdout is not a TTY.
  if (args.verbose) {
    pwArgs.push('--reporter=list,json')
  } else {
    pwArgs.push('--reporter=json')
  }

  try { fs.unlinkSync(REPORT_PATH) } catch {}

  const t0 = Date.now()
  const { code, stdout, stderr } = await runPlaywright(pwArgs)
  const elapsedMs = Date.now() - t0

  let report = null
  try {
    // Always prefer the on-disk report; fall back to stdout scraping.
    if (fs.existsSync(REPORT_PATH)) {
      report = JSON.parse(fs.readFileSync(REPORT_PATH, 'utf8'))
    } else if (stdout) {
      const lastBrace = stdout.lastIndexOf('}')
      const firstBrace = stdout.indexOf('{')
      if (firstBrace >= 0 && lastBrace > firstBrace) {
        report = JSON.parse(stdout.slice(firstBrace, lastBrace + 1))
      }
    }
  } catch {}

  const summary = {
    status: code === 0 ? 'pass' : 'fail',
    exitCode: code,
    elapsedMs,
    passed: 0,
    failed: 0,
    skipped: 0,
    failures: [],
  }
  if (report && Array.isArray(report.suites)) {
    const visit = (suite) => {
      for (const child of suite.suites || []) visit(child)
      for (const spec of suite.specs || []) {
        for (const t of spec.tests || []) {
          const last = t.results && t.results[t.results.length - 1]
          if (!last) continue
          if (last.status === 'passed' || last.status === 'expected') summary.passed++
          else if (last.status === 'skipped') summary.skipped++
          else {
            summary.failed++
            const err = last.error || (last.errors && last.errors[0]) || {}
            const stderrChunks = (last.stderr || []).map((c) => c.text || c).join('').slice(-2000)
            const stdoutChunks = (last.stdout || []).map((c) => c.text || c).join('').slice(-2000)
            summary.failures.push({
              title: spec.title,
              file: spec.file,
              line: spec.line,
              status: last.status,
              duration_ms: last.duration,
              error: {
                message: err.message || String(err),
                stack: err.stack || '',
                snippet: err.snippet || '',
                location: err.location || null,
              },
              stdout_tail: stdoutChunks,
              stderr_tail: stderrChunks,
            })
          }
        }
      }
    }
    for (const s of report.suites) visit(s)
  }

  if (args.json) {
    console.log(JSON.stringify(summary))
  } else {
    console.log('')
    console.log(`Summary: PASS=${summary.passed} FAIL=${summary.failed} SKIP=${summary.skipped} (${summary.elapsedMs}ms)`)
    if (summary.failures.length) {
      console.log('')
      console.log('=== FAILURES ===')
      for (const f of summary.failures) {
        console.log('')
        console.log(`✘ ${f.title}`)
        console.log(`  ${f.file}:${f.line || '?'}  status=${f.status}  duration=${f.duration_ms}ms`)
        if (f.error && f.error.message) {
          console.log('  ── error ──')
          for (const line of String(f.error.message).split(/\r?\n/)) console.log('  ' + line)
        }
        if (f.error && f.error.snippet) {
          console.log('  ── snippet ──')
          for (const line of String(f.error.snippet).split(/\r?\n/)) console.log('  ' + line)
        }
        if (f.error && f.error.stack && !String(f.error.message || '').includes(f.error.stack.split(/\r?\n/)[0])) {
          console.log('  ── stack ──')
          for (const line of String(f.error.stack).split(/\r?\n/).slice(0, 8)) console.log('  ' + line)
        }
        if (f.stderr_tail) {
          console.log('  ── stderr (tail) ──')
          for (const line of String(f.stderr_tail).split(/\r?\n/).slice(-12)) console.log('  ' + line)
        }
      }
    }
    if (code !== 0 && !summary.failures.length) {
      console.log('No structured failures parsed. Raw stderr (tail):')
      console.log(stderr.split(/\r?\n/).slice(-40).join('\n'))
    }
  }
  process.exit(code === 0 ? 0 : 1)
}

main().catch((e) => {
  console.error(e && e.stack || e)
  process.exit(1)
})
