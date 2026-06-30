<template>
  <!-- Invisible audio layer — no visual output -->
  <!-- YouTube: hidden iframe via YouTube IFrame API -->
  <div v-if="audioConfig && audioConfig.url" class="mood-bg-audio" style="position:fixed;width:0;height:0;overflow:hidden;pointer-events:none;z-index:-1;">
    <!-- YouTube player container -->
    <div v-if="audioConfig.type === 'youtube'" ref="ytContainer" />
    <!-- Direct audio file (loop handled manually when range is set) -->
    <audio
      v-if="audioConfig.type === 'file'"
      ref="audioEl"
      :src="audioConfig.url"
      :loop="!hasLoopRange && audioConfig.loop !== false"
      preload="auto"
      @timeupdate="onFileTimeUpdate"
      @ended="onFileEnded"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  /** bg_audio config object: { type, url, volume, loop, loop_start, loop_end } */
  config: { type: Object, default: null },
  /** Whether audio should be playing (true in presentation mode) */
  playing: { type: Boolean, default: false }
})

const emit = defineEmits(['duration', 'time', 'error'])

const ytContainer = ref(null)
const audioEl = ref(null)

let ytPlayer = null
let ytReady = false
let ytApiLoading = false
let ytLoopInterval = null  // Interval for checking YouTube loop range

const audioConfig = computed(() => {
  if (!props.config) return null
  const raw = typeof props.config === 'string' ? JSON.parse(props.config) : props.config
  if (!raw || !raw.url) return null
  return raw
})

// ─── Time parsing helpers ────────────────────────────────────
/** Parse "m:ss" or "mm:ss" or "h:mm:ss" or raw seconds to total seconds */
function parseTimeToSeconds(str) {
  if (!str || str === '') return null
  // If it's already a number
  if (!isNaN(Number(str))) return Number(str)
  const parts = String(str).split(':').map(Number)
  if (parts.some(isNaN)) return null
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2]
  if (parts.length === 2) return parts[0] * 60 + parts[1]
  return parts[0]
}

const loopStartSec = computed(() => parseTimeToSeconds(audioConfig.value?.loop_start))
const loopEndSec = computed(() => parseTimeToSeconds(audioConfig.value?.loop_end))
const hasLoopRange = computed(() => loopStartSec.value != null && loopEndSec.value != null && audioConfig.value?.loop !== false)

// ─── YouTube video ID extraction ─────────────────────────────
function extractYouTubeId(url) {
  if (!url) return null
  const match1 = url.match(/[?&]v=([a-zA-Z0-9_-]{11})/)
  if (match1) return match1[1]
  const match2 = url.match(/youtu\.be\/([a-zA-Z0-9_-]{11})/)
  if (match2) return match2[1]
  const match3 = url.match(/embed\/([a-zA-Z0-9_-]{11})/)
  if (match3) return match3[1]
  const match4 = url.match(/music\.youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/)
  if (match4) return match4[1]
  return null
}

// ─── YouTube IFrame API (requires internet -- fails gracefully offline) ───
function loadYouTubeApi() {
  return new Promise((resolve, reject) => {
    if (window.YT && window.YT.Player) {
      resolve()
      return
    }
    if (ytApiLoading) {
      const check = setInterval(() => {
        if (window.YT && window.YT.Player) {
          clearInterval(check)
          resolve()
        }
      }, 100)
      return
    }
    if (!navigator.onLine) {
      reject(new Error('YouTube API unavailable offline'))
      return
    }
    ytApiLoading = true
    const tag = document.createElement('script')
    tag.src = 'https://www.youtube.com/iframe_api'
    tag.onerror = () => {
      ytApiLoading = false
      reject(new Error('Failed to load YouTube API'))
    }
    document.head.appendChild(tag)
    window.onYouTubeIframeAPIReady = () => {
      ytApiLoading = false
      resolve()
    }
  })
}

function createYtPlayer(videoId) {
  if (!ytContainer.value) return
  destroyYtPlayer()

  const vol = audioConfig.value?.volume ?? 30
  const loop = audioConfig.value?.loop !== false
  const startSec = loopStartSec.value

  ytPlayer = new window.YT.Player(ytContainer.value, {
    width: 1,
    height: 1,
    videoId,
    playerVars: {
      autoplay: 0,
      controls: 0,
      disablekb: 1,
      fs: 0,
      modestbranding: 1,
      rel: 0,
      // Don't use YT native loop when we have a custom range
      loop: (loop && !hasLoopRange.value) ? 1 : 0,
      playlist: (loop && !hasLoopRange.value) ? videoId : undefined,
      start: startSec ?? undefined,
    },
    events: {
      onReady: (e) => {
        ytReady = true
        e.target.setVolume(vol)
        if (props.playing) {
          if (startSec != null) {
            e.target.seekTo(startSec, true)
          }
          e.target.playVideo()
          startYtLoopCheck()
        }
      },
      onStateChange: (e) => {
        if (e.data === window.YT.PlayerState.ENDED && loop) {
          // Restart from loop_start or 0
          e.target.seekTo(startSec ?? 0, true)
          e.target.playVideo()
        }
        if (e.data === window.YT.PlayerState.PLAYING) {
          startYtLoopCheck()
        } else {
          stopYtLoopCheck()
        }
      },
      onError: (e) => {
        emit('error', 'YouTube playback error: ' + e.data)
      }
    }
  })
}

/** Poll YouTube current time to enforce loop_end boundary */
function startYtLoopCheck() {
  stopYtLoopCheck()
  if (!hasLoopRange.value) return
  ytLoopInterval = setInterval(() => {
    if (!ytPlayer || !ytReady) return
    try {
      const currentTime = ytPlayer.getCurrentTime()
      const endTime = loopEndSec.value
      const startTime = loopStartSec.value ?? 0
      if (endTime != null && currentTime >= endTime) {
        ytPlayer.seekTo(startTime, true)
      }
    } catch (_) {}
  }, 250) // Check every 250ms
}

function stopYtLoopCheck() {
  if (ytLoopInterval) {
    clearInterval(ytLoopInterval)
    ytLoopInterval = null
  }
}

function destroyYtPlayer() {
  stopYtLoopCheck()
  if (ytPlayer) {
    try { ytPlayer.destroy() } catch (_) {}
    ytPlayer = null
    ytReady = false
  }
}

// ─── File audio loop range handlers ──────────────────────────
function onFileTimeUpdate() {
  if (!audioEl.value || !hasLoopRange.value) return
  const endTime = loopEndSec.value
  if (endTime != null && audioEl.value.currentTime >= endTime) {
    audioEl.value.currentTime = loopStartSec.value ?? 0
  }
}

function onFileEnded() {
  if (!audioEl.value) return
  if (audioConfig.value?.loop !== false) {
    audioEl.value.currentTime = loopStartSec.value ?? 0
    audioEl.value.play().catch(() => {})
  }
}

// ─── Playback control ────────────────────────────────────────
function play() {
  if (audioConfig.value?.type === 'youtube' && ytPlayer && ytReady) {
    if (loopStartSec.value != null) {
      ytPlayer.seekTo(loopStartSec.value, true)
    }
    ytPlayer.playVideo()
    startYtLoopCheck()
  } else if (audioConfig.value?.type === 'file' && audioEl.value) {
    if (loopStartSec.value != null) {
      audioEl.value.currentTime = loopStartSec.value
    }
    audioEl.value.play().catch(() => {})
  }
}

function pause() {
  if (audioConfig.value?.type === 'youtube' && ytPlayer && ytReady) {
    ytPlayer.pauseVideo()
    stopYtLoopCheck()
  } else if (audioConfig.value?.type === 'file' && audioEl.value) {
    audioEl.value.pause()
  }
}

function setVolume(vol) {
  const v = Math.max(0, Math.min(100, vol))
  if (audioConfig.value?.type === 'youtube' && ytPlayer && ytReady) {
    ytPlayer.setVolume(v)
  } else if (audioConfig.value?.type === 'file' && audioEl.value) {
    audioEl.value.volume = v / 100
  }
}

// ─── Watch playing prop ──────────────────────────────────────
watch(() => props.playing, (val) => {
  if (val) play()
  else pause()
})

// ─── Watch config changes ────────────────────────────────────
watch(audioConfig, async (cfg, oldCfg) => {
  if (!cfg || !cfg.url) {
    destroyYtPlayer()
    return
  }

  if (cfg.type === 'youtube') {
    const videoId = extractYouTubeId(cfg.url)
    const oldVideoId = oldCfg ? extractYouTubeId(oldCfg.url) : null

    if (videoId && videoId !== oldVideoId) {
      await loadYouTubeApi()
      await nextTick()
      createYtPlayer(videoId)
    } else if (ytPlayer && ytReady) {
      // Volume or loop config changed
      setVolume(cfg.volume ?? 30)
      // Restart loop check in case range changed
      if (props.playing && hasLoopRange.value) startYtLoopCheck()
      else stopYtLoopCheck()
    }
  } else if (cfg.type === 'file') {
    destroyYtPlayer()
    await nextTick()
    if (audioEl.value) {
      audioEl.value.volume = (cfg.volume ?? 30) / 100
      audioEl.value.loop = !hasLoopRange.value && cfg.loop !== false
      if (props.playing) {
        if (loopStartSec.value != null) {
          audioEl.value.currentTime = loopStartSec.value
        }
        audioEl.value.play().catch(() => {})
      }
    }
  }
}, { deep: true })

// ─── Init on mount ───────────────────────────────────────────
onMounted(async () => {
  if (!audioConfig.value || !audioConfig.value.url) return

  if (audioConfig.value.type === 'youtube') {
    const videoId = extractYouTubeId(audioConfig.value.url)
    if (videoId) {
      await loadYouTubeApi()
      await nextTick()
      createYtPlayer(videoId)
    }
  } else if (audioConfig.value.type === 'file' && audioEl.value) {
    audioEl.value.volume = (audioConfig.value.volume ?? 30) / 100
    if (props.playing) {
      if (loopStartSec.value != null) {
        audioEl.value.currentTime = loopStartSec.value
      }
      audioEl.value.play().catch(() => {})
    }
  }
})

onUnmounted(() => {
  destroyYtPlayer()
})

// Expose for parent components
defineExpose({ play, pause, setVolume })
</script>

