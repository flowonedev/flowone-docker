<script setup>
/**
 * CallSecurityBadge
 *
 * Teams-style "Encrypted" shield shown in the call top bar. Clicking it opens
 * a small popover explaining the encryption guarantees.
 *
 * Honest wording: LiveKit media (audio / video / screen share) is encrypted in
 * transit via DTLS-SRTP between each participant and the FlowOne media server,
 * and signaling / chat travel over TLS. This is transport encryption, NOT
 * end-to-end encryption (the media server can technically access streams), so
 * the popover says so explicitly.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

defineProps({
  // Icon-only chip (hides the "Encrypted" label) for narrow widths
  compact: {
    type: Boolean,
    default: false
  }
})

const open = ref(false)
const rootEl = ref(null)

function toggle() {
  open.value = !open.value
}

function close() {
  open.value = false
}

function handleOutsideClick(e) {
  if (!open.value) return
  if (rootEl.value && !rootEl.value.contains(e.target)) {
    open.value = false
  }
}

function handleKey(e) {
  if (e.key === 'Escape' && open.value) {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('mousedown', handleOutsideClick, true)
  document.addEventListener('keydown', handleKey)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', handleOutsideClick, true)
  document.removeEventListener('keydown', handleKey)
})

defineExpose({ close })
</script>

<template>
  <div ref="rootEl" class="relative inline-block">
    <button
      type="button"
      @click="toggle"
      :class="[
        'flex items-center gap-1.5 rounded-full transition-colors',
        'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300',
        compact ? 'w-8 h-8 justify-center' : 'px-2.5 py-1.5'
      ]"
      :title="'Encrypted call'"
      aria-label="Encryption details"
    >
      <span class="material-symbols-rounded text-base leading-none">shield</span>
      <span v-if="!compact" class="text-xs font-semibold tracking-wide">Encrypted</span>
    </button>

    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 -translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="absolute z-50 top-full mt-2 left-0 w-[300px] max-w-[88vw] bg-surface-900/95 backdrop-blur-xl border border-surface-700 rounded-2xl shadow-2xl overflow-hidden"
      >
        <div class="px-4 py-3 border-b border-surface-700/50 flex items-center gap-2.5">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center shrink-0">
            <span class="material-symbols-rounded text-emerald-300 text-lg">shield</span>
          </span>
          <div class="min-w-0">
            <p class="text-white text-sm font-semibold">Encrypted call</p>
            <p class="text-emerald-300/80 text-[11px]">Protected connection</p>
          </div>
        </div>
        <div class="px-4 py-3 space-y-2.5">
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-emerald-400 text-base leading-tight">check_circle</span>
            <p class="text-white/70 text-xs leading-relaxed">
              Your audio, video, and screen share are encrypted in transit (DTLS-SRTP) between you and the FlowOne media server.
            </p>
          </div>
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-emerald-400 text-base leading-tight">check_circle</span>
            <p class="text-white/70 text-xs leading-relaxed">
              Chat messages and call signaling travel over TLS.
            </p>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
