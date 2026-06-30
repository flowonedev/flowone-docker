<script setup>
/**
 * CallPip - Minimized floating call widget (Picture-in-Picture style)
 * 
 * Shows a small draggable overlay with call info and basic controls
 * when the user minimizes the full call overlay.
 */

import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useCallStore } from '@/stores/call'

const callStore = useCallStore()

// Drag state
const pipRef = ref(null)
const isDragging = ref(false)
const position = ref({ x: 20, y: 20 })
const dragOffset = ref({ x: 0, y: 0 })

// Duration timer
const displayDuration = ref(0)
let durationTimer = null

const formattedDuration = computed(() => {
  const mins = Math.floor(displayDuration.value / 60)
  const secs = displayDuration.value % 60
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
})

const participantName = computed(() => {
  if (callStore.participants.length === 0) return 'Call'
  if (callStore.participants.length === 1) return callStore.participants[0].name
  return `${callStore.participants.length} participants`
})

// Drag handlers
function onMouseDown(e) {
  isDragging.value = true
  dragOffset.value = {
    x: e.clientX - position.value.x,
    y: e.clientY - position.value.y
  }
  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
}

function onMouseMove(e) {
  if (!isDragging.value) return
  position.value = {
    x: Math.max(0, Math.min(window.innerWidth - 280, e.clientX - dragOffset.value.x)),
    y: Math.max(0, Math.min(window.innerHeight - 80, e.clientY - dragOffset.value.y))
  }
}

function onMouseUp() {
  isDragging.value = false
  document.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('mouseup', onMouseUp)
}

// Touch handlers for mobile
function onTouchStart(e) {
  const touch = e.touches[0]
  isDragging.value = true
  dragOffset.value = {
    x: touch.clientX - position.value.x,
    y: touch.clientY - position.value.y
  }
}

function onTouchMove(e) {
  if (!isDragging.value) return
  const touch = e.touches[0]
  position.value = {
    x: Math.max(0, Math.min(window.innerWidth - 280, touch.clientX - dragOffset.value.x)),
    y: Math.max(0, Math.min(window.innerHeight - 80, touch.clientY - dragOffset.value.y))
  }
}

function onTouchEnd() {
  isDragging.value = false
}

onMounted(() => {
  // Position bottom-right initially
  position.value = {
    x: window.innerWidth - 300,
    y: window.innerHeight - 100
  }
  
  durationTimer = setInterval(() => {
    if (callStore.callAnswerTime) {
      displayDuration.value = Math.floor((Date.now() - callStore.callAnswerTime) / 1000)
    }
  }, 1000)
})

onUnmounted(() => {
  if (durationTimer) clearInterval(durationTimer)
  document.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('mouseup', onMouseUp)
})
</script>

<template>
  <Teleport to="body">
    <div
      ref="pipRef"
      class="fixed z-[9998] select-none"
      :style="{ left: position.x + 'px', top: position.y + 'px' }"
      @mousedown="onMouseDown"
      @touchstart.passive="onTouchStart"
      @touchmove.passive="onTouchMove"
      @touchend="onTouchEnd"
    >
      <div class="bg-surface-900/95 backdrop-blur-xl rounded-2xl shadow-2xl border border-surface-700/50 px-4 py-3 flex items-center gap-3 min-w-[260px] cursor-move">
        <!-- Call type indicator with pulse -->
        <div class="relative flex-shrink-0">
          <div class="w-2 h-2 rounded-full bg-green-400 animate-pulse absolute -top-0.5 -right-0.5"></div>
          <span class="material-symbols-rounded text-white/80">
            {{ callStore.callType === 'video' ? 'videocam' : 'call' }}
          </span>
        </div>
        
        <!-- Call info -->
        <div class="flex-1 min-w-0">
          <p class="text-white text-sm font-medium truncate">{{ participantName }}</p>
          <p class="text-white/40 text-xs tabular-nums">{{ formattedDuration }}</p>
        </div>
        
        <!-- Quick controls -->
        <div class="flex items-center gap-1.5 flex-shrink-0">
          <!-- Mute toggle -->
          <button
            @click.stop="callStore.toggleMute()"
            :class="[
              'w-8 h-8 rounded-full flex items-center justify-center transition-colors',
              callStore.isAudioMuted
                ? 'bg-red-500/20 text-red-400'
                : 'bg-white/10 text-white/70 hover:bg-white/20'
            ]"
          >
            <span class="material-symbols-rounded text-base">
              {{ callStore.isAudioMuted ? 'mic_off' : 'mic' }}
            </span>
          </button>
          
          <!-- Expand -->
          <button
            @click.stop="callStore.toggleMinimize()"
            class="w-8 h-8 rounded-full bg-white/10 text-white/70 hover:bg-white/20 flex items-center justify-center transition-colors"
            title="Expand"
          >
            <span class="material-symbols-rounded text-base">open_in_full</span>
          </button>
          
          <!-- Hang up -->
          <button
            @click.stop="callStore.hangUp()"
            class="w-8 h-8 rounded-full bg-red-500 text-white hover:bg-red-600 flex items-center justify-center transition-colors"
            title="End call"
          >
            <span class="material-symbols-rounded text-base">call_end</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

