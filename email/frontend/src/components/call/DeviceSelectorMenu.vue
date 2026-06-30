<script setup>
/**
 * DeviceSelectorMenu
 *
 * A small chevron button (^) that opens a popover listing the available
 * devices of a given kind (audioinput / videoinput / audiooutput).
 * Used both as an in-call quick switcher (anchored to a control button)
 * and inline in the pre-call modal as a dropdown.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useDevicePreferences } from '@/composables/useDevicePreferences'

const props = defineProps({
  kind: {
    type: String,
    required: true,
    validator: v => ['audioinput', 'videoinput', 'audiooutput'].includes(v)
  },
  selectedDeviceId: {
    type: String,
    default: null
  },
  disabled: {
    type: Boolean,
    default: false
  },
  // 'up' (popover opens upwards, for in-call control bars) or 'down'
  align: {
    type: String,
    default: 'up'
  },
  // 'chevron' (small floating ^ button) or 'inline' (full-width row, used in modal)
  variant: {
    type: String,
    default: 'chevron'
  },
  // Flat caret (no circle/border) for the Teams-style control bar
  flat: {
    type: Boolean,
    default: false
  },
  // Optional descriptive label rendered above the inline variant
  label: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:selectedDeviceId'])

const prefs = useDevicePreferences()
const open = ref(false)
const rootEl = ref(null)

const devices = computed(() => {
  if (props.kind === 'audioinput') return prefs.audioInputDevices.value
  if (props.kind === 'videoinput') return prefs.videoInputDevices.value
  if (props.kind === 'audiooutput') return prefs.audioOutputDevices.value
  return []
})

const kindIcon = computed(() => {
  if (props.kind === 'audioinput') return 'mic'
  if (props.kind === 'videoinput') return 'videocam'
  return 'volume_up'
})

const kindLabel = computed(() => {
  if (props.kind === 'audioinput') return 'Microphone'
  if (props.kind === 'videoinput') return 'Camera'
  return 'Speaker'
})

const selectedLabel = computed(() => {
  const id = props.selectedDeviceId
  if (!id) {
    return devices.value[0]?.label || `Default ${kindLabel.value.toLowerCase()}`
  }
  const match = devices.value.find(d => d.deviceId === id)
  return match?.label || `Selected ${kindLabel.value.toLowerCase()}`
})

function selectDevice(deviceId) {
  emit('update:selectedDeviceId', deviceId)
  open.value = false
}

function refreshDevices() {
  prefs.enumerate()
}

function toggle() {
  if (props.disabled) return
  if (!open.value) {
    refreshDevices()
  }
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

// Auto-close when devices list shrinks to 0 (rare; e.g. all devices removed)
watch(devices, (list) => {
  if (list.length === 0) open.value = false
})

defineExpose({ close })
</script>

<template>
  <div ref="rootEl" :class="[variant === 'inline' ? 'w-full' : 'relative inline-block']">
    <!-- INLINE VARIANT (used in PreCallDeviceModal / pre-join) -->
    <template v-if="variant === 'inline'">
      <label v-if="label" class="block text-xs font-medium text-white/60 uppercase tracking-wider mb-1.5">
        {{ label }}
      </label>
      <button
        type="button"
        :disabled="disabled || devices.length === 0"
        @click="toggle"
        :class="[
          'w-full flex items-center gap-3 px-3 py-2.5 rounded-xl border transition-colors text-left',
          'bg-surface-800/70 hover:bg-surface-800 border-surface-700',
          'disabled:opacity-50 disabled:cursor-not-allowed'
        ]"
      >
        <span class="material-symbols-rounded text-white/60 text-lg shrink-0">{{ kindIcon }}</span>
        <span class="flex-1 text-sm text-white truncate">{{ selectedLabel }}</span>
        <span
          class="material-symbols-rounded text-white/40 text-lg transition-transform shrink-0"
          :class="{ 'rotate-180': open }"
        >expand_more</span>
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
          class="mt-1 w-full bg-surface-900 border border-surface-700 rounded-xl shadow-xl overflow-hidden"
        >
          <div v-if="!prefs.hasEnumeratedWithLabels.value" class="px-3 py-2 text-xs text-amber-300/80 border-b border-surface-700/60 bg-amber-500/5">
            Allow microphone/camera access to see device names.
          </div>
          <div v-if="devices.length === 0" class="px-3 py-3 text-sm text-white/50">
            No {{ kindLabel.toLowerCase() }} found
          </div>
          <button
            v-for="d in devices"
            :key="d.deviceId"
            type="button"
            @click="selectDevice(d.deviceId)"
            class="w-full flex items-center gap-2 px-3 py-2 text-sm text-left hover:bg-white/5 transition-colors"
            :class="d.deviceId === selectedDeviceId ? 'text-white bg-white/5' : 'text-white/80'"
          >
            <span
              class="material-symbols-rounded text-base shrink-0"
              :class="d.deviceId === selectedDeviceId ? 'text-green-400' : 'text-transparent'"
            >check</span>
            <span class="truncate">{{ d.label || `${kindLabel} ${d.deviceId.slice(0, 6)}` }}</span>
          </button>
        </div>
      </Transition>
    </template>

    <!-- CHEVRON VARIANT (used on in-call control buttons) -->
    <template v-else>
      <button
        type="button"
        :disabled="disabled || devices.length < 2"
        @click="toggle"
        :class="[
          'flex items-center justify-center transition-colors disabled:opacity-0 disabled:pointer-events-none',
          flat
            ? 'w-4 h-7 text-white/60 hover:text-white'
            : 'w-5 h-5 rounded-full bg-surface-900/80 hover:bg-surface-800 border border-white/15 text-white shadow-md'
        ]"
        :title="`Switch ${kindLabel.toLowerCase()}`"
        :aria-label="`Switch ${kindLabel.toLowerCase()}`"
      >
        <span class="material-symbols-rounded text-[14px] leading-none">
          {{ open ? 'expand_more' : 'expand_less' }}
        </span>
      </button>

      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0 translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="open"
          :class="[
            'absolute z-50 min-w-[260px] max-w-[320px] right-0 bg-surface-900/95 backdrop-blur-xl border border-surface-700 rounded-xl shadow-2xl overflow-hidden',
            align === 'up' ? 'bottom-full mb-2' : 'top-full mt-2'
          ]"
        >
          <div class="px-3 py-2 border-b border-surface-700/50 flex items-center gap-2">
            <span class="material-symbols-rounded text-white/60 text-base">{{ kindIcon }}</span>
            <span class="text-xs font-semibold text-white/80 uppercase tracking-wider">{{ kindLabel }}</span>
          </div>
          <div v-if="!prefs.hasEnumeratedWithLabels.value" class="px-3 py-2 text-xs text-amber-300/80 border-b border-surface-700/40">
            Allow access to see device names.
          </div>
          <div v-if="devices.length === 0" class="px-3 py-3 text-sm text-white/50">
            No {{ kindLabel.toLowerCase() }} found
          </div>
          <button
            v-for="d in devices"
            :key="d.deviceId"
            type="button"
            @click="selectDevice(d.deviceId)"
            class="w-full flex items-center gap-2 px-3 py-2 text-sm text-left hover:bg-white/5 transition-colors"
            :class="d.deviceId === selectedDeviceId ? 'text-white bg-white/5' : 'text-white/80'"
          >
            <span
              class="material-symbols-rounded text-base shrink-0"
              :class="d.deviceId === selectedDeviceId ? 'text-green-400' : 'text-transparent'"
            >check</span>
            <span class="truncate">{{ d.label || `${kindLabel} ${d.deviceId.slice(0, 6)}` }}</span>
          </button>
        </div>
      </Transition>
    </template>
  </div>
</template>
