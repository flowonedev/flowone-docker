/**
 * useAudioLevelDetection - Detect voice activity from a MediaStream
 *
 * Uses Web Audio API AnalyserNode to measure audio frequency data.
 * Returns a reactive `isSpeaking` boolean based on configurable thresholds.
 * Requires consecutive frames above threshold to avoid false triggers from noise.
 */

import { ref, onUnmounted, watch, toRef } from 'vue'

const DEFAULT_FFT_SIZE = 256
const DEFAULT_THRESHOLD = 15
const DEFAULT_CONSECUTIVE_FRAMES = 3
const POLL_INTERVAL_MS = 60

export function useAudioLevelDetection(streamRef, options = {}) {
  const {
    fftSize = DEFAULT_FFT_SIZE,
    threshold = DEFAULT_THRESHOLD,
    consecutiveFrames = DEFAULT_CONSECUTIVE_FRAMES,
  } = options

  const isSpeaking = ref(false)
  const audioLevel = ref(0)

  let audioCtx = null
  let analyser = null
  let sourceNode = null
  let pollTimer = null
  let framesAboveThreshold = 0

  function start(stream) {
    stop()
    if (!stream || !stream.getAudioTracks().length) return

    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)()
      analyser = audioCtx.createAnalyser()
      analyser.fftSize = fftSize
      analyser.smoothingTimeConstant = 0.3

      sourceNode = audioCtx.createMediaStreamSource(stream)
      sourceNode.connect(analyser)

      const bufferLength = analyser.frequencyBinCount
      const dataArray = new Uint8Array(bufferLength)

      pollTimer = setInterval(() => {
        analyser.getByteFrequencyData(dataArray)

        let sum = 0
        for (let i = 0; i < bufferLength; i++) {
          sum += dataArray[i]
        }
        const avg = sum / bufferLength
        audioLevel.value = avg

        if (avg > threshold) {
          framesAboveThreshold++
          if (framesAboveThreshold >= consecutiveFrames) {
            isSpeaking.value = true
          }
        } else {
          framesAboveThreshold = 0
          isSpeaking.value = false
        }
      }, POLL_INTERVAL_MS)
    } catch (e) {
      console.error('[AudioLevelDetection] Failed to initialize:', e)
    }
  }

  function stop() {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
    if (sourceNode) {
      try { sourceNode.disconnect() } catch (_) {}
      sourceNode = null
    }
    if (analyser) {
      try { analyser.disconnect() } catch (_) {}
      analyser = null
    }
    if (audioCtx && audioCtx.state !== 'closed') {
      try { audioCtx.close() } catch (_) {}
      audioCtx = null
    }
    framesAboveThreshold = 0
    isSpeaking.value = false
    audioLevel.value = 0
  }

  // Watch the stream ref for changes
  if (streamRef) {
    const watchable = typeof streamRef === 'function' ? streamRef : toRef(streamRef)
    watch(watchable, (newStream) => {
      if (newStream) {
        start(newStream)
      } else {
        stop()
      }
    }, { immediate: true })
  }

  onUnmounted(() => {
    stop()
  })

  return {
    isSpeaking,
    audioLevel,
    start,
    stop,
  }
}
