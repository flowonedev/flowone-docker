/**
 * Notification Sounds Service
 *
 * Plays short notification sounds for new incoming email (Outlook-style chime)
 * and new chat messages (Teams-style pop).
 *
 * Primary playback uses real audio files in public/sounds/ (new-email.mp3 /
 * new-chat.mp3) decoded through a shared, gesture-unlocked AudioContext. If a
 * file can't be loaded or decoded, it falls back to a synthesized tone so a
 * sound still plays. Regenerate the files with:
 *   node tools/generate-notification-sounds.cjs
 *
 * Activation is controlled by the existing `notification_sound` localStorage
 * key (the "Notification Sounds" toggle in Settings). Per-type throttling
 * prevents rapid message bursts from stacking into noise.
 */

import { isDebugEnabled } from '@/utils/debug'

const BASE = (import.meta.env && import.meta.env.BASE_URL) || '/'

// Files in public/sounds/ keep a stable, un-fingerprinted URL, so replacing
// their CONTENT while keeping the filename leaves the browser/PWA serving the
// previously cached audio (the "I changed the sound but still hear the old
// one" bug). Bump SOUND_VERSION whenever the sound files change to force a
// fresh fetch past the HTTP cache.
// v3: the "new sounds" commit replaced both mp3s but kept v2, so already-cached
// clients (cache: 'force-cache' below) kept playing the old audio. Bump forces
// the new audio to be fetched under a fresh URL.
const SOUND_VERSION = '3'
const SOUND_URLS = {
  email: `${BASE}sounds/new-email.mp3?v=${SOUND_VERSION}`,
  chat: `${BASE}sounds/new-chat.mp3?v=${SOUND_VERSION}`,
}

// Shared AudioContext, lazily created and reused across plays.
let sharedCtx = null
// Decoded audio buffers, keyed by url.
const bufferCache = new Map()
const bufferPending = new Map()

// Per-type throttle so a burst of events can't stack into a cacophony.
const MIN_GAP_MS = 1500
const lastPlayedAt = { email: 0, chat: 0 }

function isEnabled() {
  try {
    return localStorage.getItem('notification_sound') !== 'false'
  } catch (_) {
    return true
  }
}

function getContext() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext
    if (!Ctx) return null
    if (!sharedCtx || sharedCtx.state === 'closed') {
      sharedCtx = new Ctx()
    }
    if (sharedCtx.state === 'suspended') {
      sharedCtx.resume().catch(() => {})
    }
    return sharedCtx
  } catch (e) {
    isDebugEnabled() && console.warn('[NotificationSounds] AudioContext unavailable:', e)
    return null
  }
}

async function loadBuffer(url) {
  if (bufferCache.has(url)) return bufferCache.get(url)
  if (bufferPending.has(url)) return bufferPending.get(url)

  const ctx = getContext()
  if (!ctx) return null

  const promise = (async () => {
    const res = await fetch(url, { cache: 'force-cache' })
    if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`)
    // A missing file on an SPA server returns index.html (text/html) with a 200.
    // Decoding HTML as audio fails into the synth fallback, which looks like
    // "the old sound still plays". Catch it here and warn loudly so the real
    // cause (file not deployed to the web root) is obvious.
    const contentType = (res.headers.get('content-type') || '').toLowerCase()
    const looksAudio =
      contentType.includes('audio') ||
      contentType.includes('mpeg') ||
      contentType.includes('octet-stream')
    if (contentType && !looksAudio) {
      console.warn(
        `[NotificationSounds] ${url} returned "${contentType}" instead of audio. ` +
          'The sound file is missing on the server (SPA index.html fallback) — ' +
          'upload dist/sounds/*.mp3 to the web root. Falling back to a synthesized tone.'
      )
      throw new Error(`Non-audio response (${contentType}) for ${url}`)
    }
    const arrayBuf = await res.arrayBuffer()
    const audioBuf = await ctx.decodeAudioData(arrayBuf)
    bufferCache.set(url, audioBuf)
    bufferPending.delete(url)
    return audioBuf
  })()

  bufferPending.set(url, promise)
  return promise
}

async function playFile(url) {
  const ctx = getContext()
  if (!ctx) return false
  const buffer = await loadBuffer(url)
  if (!buffer) return false
  const source = ctx.createBufferSource()
  source.buffer = buffer
  const gain = ctx.createGain()
  gain.gain.value = 0.9
  source.connect(gain)
  gain.connect(ctx.destination)
  source.start()
  return true
}

/**
 * Schedule a single synthesized tone (fallback when the file can't load).
 */
function tone(ctx, { freq, start, duration, peak = 0.12, type = 'sine' }) {
  const gain = ctx.createGain()
  gain.gain.setValueAtTime(0.0001, start)
  gain.gain.exponentialRampToValueAtTime(peak, start + 0.012)
  gain.gain.exponentialRampToValueAtTime(0.0001, start + duration)
  gain.connect(ctx.destination)

  const osc = ctx.createOscillator()
  osc.type = type
  osc.frequency.value = freq
  osc.connect(gain)
  osc.start(start)
  osc.stop(start + duration)
}

function synthEmail() {
  const ctx = getContext()
  if (!ctx) return
  const t = ctx.currentTime + 0.01
  tone(ctx, { freq: 587.33, start: t, duration: 0.12, peak: 0.10, type: 'triangle' })
  tone(ctx, { freq: 880.0, start: t + 0.10, duration: 0.42, peak: 0.13, type: 'sine' })
}

function synthChat() {
  const ctx = getContext()
  if (!ctx) return
  const t = ctx.currentTime + 0.01
  tone(ctx, { freq: 523.25, start: t, duration: 0.14, peak: 0.11, type: 'sine' })
  tone(ctx, { freq: 392.0, start: t + 0.11, duration: 0.18, peak: 0.10, type: 'sine' })
}

function throttled(kind) {
  const now = Date.now()
  if (now - lastPlayedAt[kind] < MIN_GAP_MS) return true
  lastPlayedAt[kind] = now
  return false
}

function play(kind, synthFallback, { force = false } = {}) {
  if (!force && !isEnabled()) return
  if (!force && throttled(kind)) return
  try {
    playFile(SOUND_URLS[kind]).then((ok) => {
      if (!ok) {
        try { synthFallback() } catch (_) {}
      }
    }).catch((e) => {
      isDebugEnabled() && console.warn(`[NotificationSounds] file play failed (${kind}), using synth:`, e)
      try { synthFallback() } catch (_) {}
    })
  } catch (e) {
    isDebugEnabled() && console.warn(`[NotificationSounds] ${kind} sound failed:`, e)
    try { synthFallback() } catch (_) {}
  }
}

function playEmailSound(opts) {
  play('email', synthEmail, opts)
}

function playChatSound(opts) {
  play('chat', synthChat, opts)
}

// One-time gesture warmup: browsers start AudioContext suspended until the
// user interacts with the page. Create + resume it on the first gesture and
// preload the buffers so later (async) notification sounds play instantly.
let warmed = false
function warmup() {
  if (warmed) return
  warmed = true
  const ctx = getContext()
  if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {})
  loadBuffer(SOUND_URLS.email).catch(() => {})
  loadBuffer(SOUND_URLS.chat).catch(() => {})
}

if (typeof window !== 'undefined') {
  const onFirstGesture = () => {
    warmup()
    window.removeEventListener('pointerdown', onFirstGesture)
    window.removeEventListener('keydown', onFirstGesture)
  }
  window.addEventListener('pointerdown', onFirstGesture, { once: true })
  window.addEventListener('keydown', onFirstGesture, { once: true })
}

export default {
  isEnabled,
  playEmailSound,
  playChatSound,
}
