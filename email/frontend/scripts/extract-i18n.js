#!/usr/bin/env node
/**
 * extract-i18n.js
 *
 * Scans all .vue and .js files under frontend/src and extracts translatable
 * English strings into per-module JSON locale files.
 *
 * Usage:  node scripts/extract-i18n.js
 * Output: src/i18n/locales/en/*.json (one file per module)
 *
 * What it extracts:
 *   - Text content between HTML tags:  >Some label</
 *   - title="..." placeholder="..." aria-label="..." attributes
 *   - toast.success/error/info/warning('...') messages
 *   - confirm('...') messages
 *
 * What it skips:
 *   - Dynamic expressions {{ }}, :attr="", v-if, etc.
 *   - Material icon names (single-word lowercase in <span class="material-symbols-rounded">)
 *   - CSS classes, numbers-only, single chars
 *   - Already-translated $t() calls
 */

import { readFileSync, writeFileSync, readdirSync, statSync } from 'fs'
import { join, relative, basename, dirname } from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

const SRC_DIR = join(__dirname, '..', 'src')
const LOCALES_DIR = join(SRC_DIR, 'i18n', 'locales', 'en')

// ============================================================================
// Module mapping: file path -> module name
// ============================================================================
function getModule(filePath) {
  const rel = relative(SRC_DIR, filePath).replace(/\\/g, '/')
  if (rel.startsWith('addons/kanban-boards/')) return 'boards'
  if (rel.startsWith('addons/board-pro/')) return 'board-pro'
  if (rel.startsWith('addons/crm-pro/')) return 'crm'
  if (rel.startsWith('addons/chat/')) return 'chat'
  if (rel.startsWith('addons/moodboards/')) return 'moodboards'
  if (rel.startsWith('addons/calendar/')) return 'calendar'
  if (rel.startsWith('addons/time-tracker/')) return 'time-tracker'
  if (rel.startsWith('addons/email-marketing/')) return 'email'
  if (rel.startsWith('addons/ai-assistant/')) return 'common'
  if (rel.startsWith('addons/reactions/')) return 'common'
  if (rel.startsWith('addons/tasks/')) return 'common'
  if (rel.startsWith('addons/team/')) return 'common'
  if (rel.startsWith('collab/')) return 'collab'
  if (rel.startsWith('components/drive/')) return 'drive'
  if (rel.startsWith('components/clients/')) return 'clients'
  if (rel.startsWith('components/portal/')) return 'portal'
  if (rel.startsWith('components/call/')) return 'chat'
  if (rel.startsWith('components/mindmap/')) return 'common'
  if (rel.startsWith('components/shared/')) return 'common'
  if (rel.startsWith('components/settings/')) return 'settings'
  if (rel.startsWith('views/portal/')) return 'portal'
  if (rel.startsWith('views/SettingsView')) return 'settings'
  if (rel.startsWith('views/Clients')) return 'clients'
  if (rel.startsWith('views/DriveView')) return 'drive'
  if (rel.startsWith('views/LoginView') || rel.startsWith('views/LandingView')) return 'common'
  if (rel.startsWith('views/MailboxView') || rel.startsWith('views/SharedFolder')) return 'email'
  if (rel.startsWith('stores/')) return 'common'
  if (rel.startsWith('components/Compose') || rel.startsWith('components/Email') || rel.startsWith('components/Filter') || rel.startsWith('components/Folder') || rel.startsWith('components/Label') || rel.startsWith('components/Bulk') || rel.startsWith('components/Spam')) return 'email'
  return 'common'
}

// ============================================================================
// Key generation: file path + text -> unique i18n key
// ============================================================================
function fileToPrefix(filePath) {
  const rel = relative(SRC_DIR, filePath).replace(/\\/g, '/')
  // Get component/view name from file
  let name = basename(filePath, '.vue')
  if (name.endsWith('.js')) name = basename(filePath, '.js')
  // camelCase the component name
  name = name
    .replace(/([A-Z])/g, '_$1')
    .replace(/^_/, '')
    .replace(/-/g, '_')
    .toLowerCase()
    .replace(/_([a-z])/g, (_, c) => c.toUpperCase())
  return name
}

function textToKey(text) {
  // Create a short key from the text
  const words = text
    .replace(/[^a-zA-Z0-9\s]/g, '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 5) // Max 5 words for key
  if (words.length === 0) return null
  // camelCase
  return words
    .map((w, i) => i === 0 ? w.toLowerCase() : w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join('')
}

// ============================================================================
// Text extraction patterns
// ============================================================================

// Material icon names to skip (common Google Material Symbols)
const ICON_PATTERN = /^[a-z][a-z_]*$/
const SKIP_TEXTS = new Set([
  // Common non-translatable
  'vs', 'px', 'em', 'rem', '%', '#', '...', '--', '—',
  'OK', 'ok', 'N/A', 'n/a', 'ID', 'id',
  'HUF', 'EUR', 'USD', 'GBP',
  'JSON', 'CSV', 'PDF', 'HTML', 'CSS', 'JS',
  'GET', 'POST', 'PUT', 'DELETE', 'PATCH',
  'AM', 'PM', 'UTC', 'GMT',
])

function shouldSkip(text) {
  const trimmed = text.trim()
  if (!trimmed || trimmed.length < 2) return true
  if (SKIP_TEXTS.has(trimmed)) return true
  // Pure numbers, dates, hex colors
  if (/^[\d.,:%$€£¥+\-*/=<>()]+$/.test(trimmed)) return true
  // Already an i18n call
  if (/\$t\(/.test(trimmed) || /\bt\(/.test(trimmed)) return true
  // Vue dynamic expressions
  if (/^\{\{.*\}\}$/.test(trimmed)) return true
  // CSS class-like strings
  if (/^[a-z][\w-]*$/.test(trimmed) && trimmed.includes('-')) return true
  // Single lowercase word that's likely an icon name
  if (ICON_PATTERN.test(trimmed) && trimmed.length > 2 && trimmed.includes('_')) return true
  // Variable-like: starts with lowercase, has no spaces
  if (/^[a-z_$][\w$]*$/.test(trimmed) && !trimmed.includes(' ')) return true
  // HTML entities or code
  if (/^&\w+;$/.test(trimmed) || /^<[^>]+>$/.test(trimmed)) return true
  // Must contain at least one letter
  if (!/[a-zA-Z]/.test(trimmed)) return true
  return false
}

// ============================================================================
// Extract from a single file
// ============================================================================
function extractFromFile(filePath) {
  const content = readFileSync(filePath, 'utf-8')
  const strings = new Map() // key -> text
  const prefix = fileToPrefix(filePath)

  // 1. Extract text between tags: >Some text</ (template section)
  const templateMatch = content.match(/<template[\s>][\s\S]*<\/template>/)
  if (templateMatch) {
    const template = templateMatch[0]

    // Text between tags
    const tagTextRe = />([^<>{}\n]+)</g
    let m
    while ((m = tagTextRe.exec(template)) !== null) {
      const text = m[1].trim()
      if (shouldSkip(text)) continue
      // Skip if it's inside a {{ }} interpolation
      if (/\{\{/.test(text)) continue
      const key = textToKey(text)
      if (key) strings.set(`${prefix}.${key}`, text)
    }

    // 2. title="...", placeholder="...", aria-label="..."
    const attrRe = /(?:title|placeholder|aria-label)="([^"]+)"/g
    while ((m = attrRe.exec(template)) !== null) {
      const text = m[1].trim()
      if (shouldSkip(text)) continue
      const key = textToKey(text)
      if (key) strings.set(`${prefix}.${key}`, text)
    }
  }

  // 3. Toast messages / confirm() in script section
  const scriptMatch = content.match(/<script[\s>][\s\S]*<\/script>/)
  if (scriptMatch) {
    const script = scriptMatch[0]

    // toast.success('...'), toast.error('...'), etc.
    const toastRe = /toast\.\w+\(\s*[`'"]([^`'"]+)[`'"]/g
    let m
    while ((m = toastRe.exec(script)) !== null) {
      const text = m[1].trim()
      if (shouldSkip(text)) continue
      const key = textToKey(text)
      if (key) strings.set(`${prefix}.${key}`, text)
    }

    // confirm('...')
    const confirmRe = /confirm\(\s*[`'"]([^`'"]+)[`'"]/g
    while ((m = confirmRe.exec(script)) !== null) {
      const text = m[1].trim()
      if (shouldSkip(text)) continue
      const key = textToKey(text)
      if (key) strings.set(`${prefix}.${key}`, text)
    }
  }

  // Also scan .js files (stores, services)
  if (filePath.endsWith('.js')) {
    const toastRe = /toast\.\w+\(\s*[`'"]([^`'"]+)[`'"]/g
    let m
    while ((m = toastRe.exec(content)) !== null) {
      const text = m[1].trim()
      if (shouldSkip(text)) continue
      const key = textToKey(text)
      if (key) strings.set(`${prefix}.${key}`, text)
    }

    const confirmRe = /confirm\(\s*[`'"]([^`'"]+)[`'"]/g
    while ((m = confirmRe.exec(content)) !== null) {
      const text = m[1].trim()
      if (shouldSkip(text)) continue
      const key = textToKey(text)
      if (key) strings.set(`${prefix}.${key}`, text)
    }
  }

  return strings
}

// ============================================================================
// Recursively find files
// ============================================================================
function walkDir(dir, exts = ['.vue', '.js']) {
  const results = []
  for (const entry of readdirSync(dir)) {
    const fullPath = join(dir, entry)
    const stat = statSync(fullPath)
    if (stat.isDirectory()) {
      // Skip node_modules, dist, .git
      if (['node_modules', 'dist', '.git', 'scripts'].includes(entry)) continue
      results.push(...walkDir(fullPath, exts))
    } else if (exts.some(ext => entry.endsWith(ext))) {
      results.push(fullPath)
    }
  }
  return results
}

// ============================================================================
// Build nested object from dot-separated keys
// ============================================================================
function buildNested(flatMap) {
  const result = {}
  const sortedKeys = [...flatMap.keys()].sort()
  for (const key of sortedKeys) {
    const parts = key.split('.')
    let current = result
    for (let i = 0; i < parts.length - 1; i++) {
      if (!current[parts[i]] || typeof current[parts[i]] !== 'object') {
        current[parts[i]] = {}
      }
      current = current[parts[i]]
    }
    current[parts[parts.length - 1]] = flatMap.get(key).replace(/@/g, "{'@'}")
  }
  return result
}

// ============================================================================
// Main
// ============================================================================
function main() {
  console.log('Scanning', SRC_DIR, '...')

  const files = walkDir(SRC_DIR)
  console.log(`Found ${files.length} files to process`)

  // Group extracted strings by module
  const modules = {} // moduleName -> Map(key -> text)

  let totalStrings = 0
  for (const file of files) {
    const mod = getModule(file)
    const strings = extractFromFile(file)
    if (strings.size === 0) continue

    if (!modules[mod]) modules[mod] = new Map()
    for (const [key, text] of strings) {
      modules[mod].set(key, text)
    }
    totalStrings += strings.size
  }

  console.log(`\nExtracted ${totalStrings} strings across ${Object.keys(modules).length} modules:\n`)

  // Write each module's JSON file
  for (const [mod, strings] of Object.entries(modules)) {
    const nested = buildNested(strings)
    const outPath = join(LOCALES_DIR, `${mod}.json`)
    writeFileSync(outPath, JSON.stringify(nested, null, 2) + '\n', 'utf-8')
    console.log(`  ${mod}.json: ${strings.size} strings`)
  }

  console.log(`\nDone! English locale files written to ${LOCALES_DIR}`)
  console.log('Next: translate to Hungarian and replace hardcoded text with $t() calls.')
}

main()

