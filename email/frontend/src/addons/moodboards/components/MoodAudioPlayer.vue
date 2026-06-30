<template>
  <div
    ref="playerEl"
    class="mood-audio-player flex flex-col rounded-2xl overflow-hidden h-full select-none"
    :class="compact ? 'p-2.5' : 'p-4'"
    :style="{ backgroundColor: bgColor }"
  >
    <!-- Waveform visualization area -->
    <div class="flex-1 flex items-center justify-center min-h-0 relative">
      <!-- Waveform bars -->
      <div class="flex items-center gap-[2px] h-full w-full px-1" :class="compact ? 'max-h-[40px]' : 'max-h-[60px]'">
        <div
          v-for="(bar, i) in waveformBars"
          :key="i"
          class="flex-1 rounded-full transition-all duration-100"
          :style="{
            height: (isPlaying ? bar.active : bar.idle) + '%',
            backgroundColor: accentColor,
            opacity: progressPercent > 0 && (i / waveformBars.length) <= progressPercent ? 1 : 0.35,
            minHeight: '3px'
          }"
        />
      </div>
    </div>

    <!-- Controls row -->
    <div class="flex items-center gap-2 mt-2">
      <!-- Play / Pause button -->
      <button
        @click.stop="togglePlay"
        @mousedown.stop
        class="flex items-center justify-center rounded-full transition-colors flex-shrink-0"
        :class="compact
          ? 'w-7 h-7 text-sm'
          : 'w-9 h-9 text-lg'"
        :style="{ backgroundColor: accentColor, color: '#fff' }"
        :title="isPlaying ? 'Pause' : 'Play'"
      >
        <span class="material-symbols-rounded" :style="{ fontSize: compact ? '16px' : '20px' }">
          {{ isPlaying ? 'pause' : 'play_arrow' }}
        </span>
      </button>

      <!-- Time + progress -->
      <div class="flex-1 min-w-0 flex flex-col gap-0.5">
        <!-- Title (truncated) -->
        <span
          v-if="title && !compact"
          class="text-[11px] font-medium truncate leading-tight"
          :style="{ color: textColor }"
        >{{ title }}</span>

        <!-- Progress bar (clickable to seek) -->
        <div
          class="relative w-full rounded-full cursor-pointer group"
          :class="compact ? 'h-1' : 'h-1.5'"
          :style="{ backgroundColor: accentColor + '30' }"
          @click.stop="onSeek"
          @mousedown.stop
        >
          <div
            class="absolute left-0 top-0 h-full rounded-full transition-[width] duration-100"
            :style="{ width: (progressPercent * 100) + '%', backgroundColor: accentColor }"
          />
        </div>

        <!-- Time display -->
        <div class="flex justify-between">
          <span class="text-[9px] tabular-nums" :style="{ color: textColor + '99' }">{{ formatTime(currentTime) }}</span>
          <span class="text-[9px] tabular-nums" :style="{ color: textColor + '99' }">{{ formatTime(duration) }}</span>
        </div>
      </div>

      <!-- Volume (hover expand) -->
      <div
        class="relative flex items-center gap-1 flex-shrink-0"
        @mouseenter="showVolume = true"
        @mouseleave="showVolume = false"
      >
        <button
          @click.stop="toggleMute"
          @mousedown.stop
          class="flex items-center justify-center rounded-full transition-colors"
          :class="compact ? 'w-6 h-6' : 'w-7 h-7'"
          :style="{ color: textColor + 'cc' }"
          :title="muted ? 'Unmute' : 'Mute'"
        >
          <span class="material-symbols-rounded" :style="{ fontSize: compact ? '14px' : '16px' }">
            {{ muted ? 'volume_off' : volume > 0.5 ? 'volume_up' : 'volume_down' }}
          </span>
        </button>
        <transition name="vol-slide">
          <input
            v-if="showVolume"
            type="range"
            min="0"
            max="1"
            step="0.01"
            :value="volume"
            @input.stop="onVolumeChange"
            @mousedown.stop
            @click.stop
            class="w-14 h-1 accent-current cursor-pointer"
            :style="{ color: accentColor }"
          />
        </transition>
      </div>
    </div>

    <!-- Hidden audio element -->
    <audio
      ref="audioEl"
      :src="src"
      :loop="loop"
      preload="metadata"
      @loadedmetadata="onMetadata"
      @timeupdate="onTimeUpdate"
      @ended="onEnded"
      @error="onError"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  src: { type: String, default: '' },
  title: { type: String, default: '' },
  volume: { type: Number, default: 0.8 },
  loop: { type: Boolean, default: false },
  autoplay: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  accentColor: { type: String, default: '#6366f1' },
  bgColor: { type: String, default: '#1e1b2e' },
  textColor: { type: String, default: '#e2e8f0' },
})

const emit = defineEmits(['play', 'pause', 'ended', 'timeupdate'])

const playerEl = ref(null)
const audioEl = ref(null)
const isPlaying = ref(false)
const currentTime = ref(0)
const duration = ref(0)
const muted = ref(false)
const showVolume = ref(false)
const hasError = ref(false)

// Generate pseudo-random waveform bars
const waveformBars = computed(() => {
  const count = props.compact ? 20 : 32
  const bars = []
  // Use title hash for consistent waveform per track
  let seed = 0
  for (const c of (props.src || 'audio')) seed = ((seed << 5) - seed + c.charCodeAt(0)) | 0
  for (let i = 0; i < count; i++) {
    seed = (seed * 16807 + 7) % 2147483647
    const h = 25 + (seed % 55)
    // Active bars pulse slightly when playing
    bars.push({ idle: h, active: h + (seed % 20) })
  }
  return bars
})

const progressPercent = computed(() => {
  if (!duration.value) return 0
  return currentTime.value / duration.value
})

// --- Audio controls ---

function togglePlay() {
  if (!audioEl.value || !props.src) return
  if (isPlaying.value) {
    audioEl.value.pause()
    isPlaying.value = false
    emit('pause')
  } else {
    audioEl.value.volume = props.volume
    audioEl.value.play().then(() => {
      isPlaying.value = true
      emit('play')
    }).catch(() => {})
  }
}

function toggleMute() {
  if (!audioEl.value) return
  muted.value = !muted.value
  audioEl.value.muted = muted.value
}

function onVolumeChange(e) {
  if (!audioEl.value) return
  const v = parseFloat(e.target.value)
  audioEl.value.volume = v
  muted.value = v === 0
}

function onSeek(e) {
  if (!audioEl.value || !duration.value) return
  const rect = e.currentTarget.getBoundingClientRect()
  const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
  audioEl.value.currentTime = ratio * duration.value
}

function onMetadata() {
  if (audioEl.value) {
    duration.value = audioEl.value.duration || 0
  }
}

function onTimeUpdate() {
  if (audioEl.value) {
    currentTime.value = audioEl.value.currentTime || 0
    emit('timeupdate', currentTime.value)
  }
}

function onEnded() {
  isPlaying.value = false
  emit('ended')
}

function onError() {
  hasError.value = true
  isPlaying.value = false
}

function formatTime(sec) {
  if (!sec || !isFinite(sec)) return '0:00'
  const m = Math.floor(sec / 60)
  const s = Math.floor(sec % 60)
  return `${m}:${s.toString().padStart(2, '0')}`
}

// Expose play/pause for external control (auto-play on viewport, presentation mode)
function play() {
  if (audioEl.value && props.src && !isPlaying.value) {
    audioEl.value.volume = props.volume
    audioEl.value.play().then(() => { isPlaying.value = true }).catch(() => {})
  }
}

function pause() {
  if (audioEl.value && isPlaying.value) {
    audioEl.value.pause()
    isPlaying.value = false
  }
}

// Watch volume prop changes
watch(() => props.volume, (v) => {
  if (audioEl.value) audioEl.value.volume = v
})

// Watch src changes
watch(() => props.src, () => {
  isPlaying.value = false
  currentTime.value = 0
  duration.value = 0
  hasError.value = false
})

defineExpose({ play, pause, isPlaying })
</script>

<style scoped>
.vol-slide-enter-active,
.vol-slide-leave-active {
  transition: width 0.15s ease, opacity 0.15s ease;
  overflow: hidden;
}
.vol-slide-enter-from,
.vol-slide-leave-to {
  width: 0;
  opacity: 0;
}
</style>

