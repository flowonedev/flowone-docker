#!/usr/bin/env node
/**
 * Generate the FlowOne notification sounds as real .wav files so they can be
 * played/tested locally (double-click) and, if desired, shipped as static
 * assets. The synthesis matches email/frontend/src/services/notificationSounds.js:
 *   - Email (Outlook-style): D5 (587.33Hz) -> A5 (880Hz) soft ascending chime
 *   - Chat  (Teams-style):   C5 (523.25Hz) -> G4 (392Hz) soft descending pop
 *
 * Usage:  node tools/generate-notification-sounds.cjs
 * Output: email/frontend/public/sounds/new-email.wav
 *         email/frontend/public/sounds/new-chat.wav
 */

const fs = require('fs')
const path = require('path')

const SAMPLE_RATE = 44100

function renderTones(totalSeconds, tones) {
  const total = Math.ceil(totalSeconds * SAMPLE_RATE)
  const buf = new Float32Array(total)
  const attack = 0.012

  for (const { freq, start, duration, peak, type } of tones) {
    const startSample = Math.floor(start * SAMPLE_RATE)
    const len = Math.floor(duration * SAMPLE_RATE)
    for (let i = 0; i < len; i++) {
      const t = i / SAMPLE_RATE
      let env
      if (t < attack) {
        env = (t / attack) * peak
      } else {
        const dt = (t - attack) / (duration - attack)
        env = peak * Math.pow(0.0008 / peak, Math.min(1, dt))
      }
      const phase = 2 * Math.PI * freq * t
      const w = type === 'triangle'
        ? (2 / Math.PI) * Math.asin(Math.sin(phase))
        : Math.sin(phase)
      const idx = startSample + i
      if (idx < buf.length) buf[idx] += env * w
    }
  }
  return buf
}

function floatToWav(float32) {
  const numSamples = float32.length
  const blockAlign = 2 // mono, 16-bit
  const dataSize = numSamples * blockAlign
  const buffer = Buffer.alloc(44 + dataSize)

  buffer.write('RIFF', 0)
  buffer.writeUInt32LE(36 + dataSize, 4)
  buffer.write('WAVE', 8)
  buffer.write('fmt ', 12)
  buffer.writeUInt32LE(16, 16)
  buffer.writeUInt16LE(1, 20) // PCM
  buffer.writeUInt16LE(1, 22) // mono
  buffer.writeUInt32LE(SAMPLE_RATE, 24)
  buffer.writeUInt32LE(SAMPLE_RATE * blockAlign, 28)
  buffer.writeUInt16LE(blockAlign, 32)
  buffer.writeUInt16LE(16, 34)
  buffer.write('data', 36)
  buffer.writeUInt32LE(dataSize, 40)

  let offset = 44
  for (let i = 0; i < numSamples; i++) {
    let s = Math.max(-1, Math.min(1, float32[i]))
    buffer.writeInt16LE(Math.round(s * 32767), offset)
    offset += 2
  }
  return buffer
}

const emailBuf = renderTones(0.6, [
  { freq: 587.33, start: 0.0, duration: 0.12, peak: 0.45, type: 'triangle' },
  { freq: 880.0, start: 0.10, duration: 0.45, peak: 0.55, type: 'sine' },
])

const chatBuf = renderTones(0.4, [
  { freq: 523.25, start: 0.0, duration: 0.14, peak: 0.5, type: 'sine' },
  { freq: 392.0, start: 0.11, duration: 0.18, peak: 0.45, type: 'sine' },
])

const outDir = path.join(__dirname, '..', 'email', 'frontend', 'public', 'sounds')
fs.mkdirSync(outDir, { recursive: true })

const emailPath = path.join(outDir, 'new-email.wav')
const chatPath = path.join(outDir, 'new-chat.wav')
fs.writeFileSync(emailPath, floatToWav(emailBuf))
fs.writeFileSync(chatPath, floatToWav(chatBuf))

console.log('Generated:')
console.log('  ' + emailPath)
console.log('  ' + chatPath)
