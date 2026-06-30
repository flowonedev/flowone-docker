<script setup>
/**
 * VideoCallControls
 *
 * Teams-style control cluster for VideoCallRoom (guest links + portal rooms).
 * Mirrors the Microsoft Teams meeting toolbar: a utility group (Chat, People,
 * Waiting room, More) followed by the media group (Camera, Mic, Share, Speaker)
 * and a red "Leave" pill. Each control is an icon with a label underneath.
 *
 * State is owned by VideoCallRoom and passed in via props; every action is
 * emitted back so the parent keeps its self-contained local-state architecture.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useDevicePreferences } from '@/composables/useDevicePreferences'
import DeviceSelectorMenu from './DeviceSelectorMenu.vue'
import CallBarButton from './CallBarButton.vue'

const props = defineProps({
  isMicMuted: { type: Boolean, default: false },
  isCamOff: { type: Boolean, default: false },
  isScreenSharing: { type: Boolean, default: false },
  forceMuted: { type: Boolean, default: false },
  isAdmin: { type: Boolean, default: false },
  isMobile: { type: Boolean, default: false },
  isWorkshopGuest: { type: Boolean, default: false },
  showChat: { type: Boolean, default: false },
  unreadCount: { type: Number, default: 0 },
  allMuted: { type: Boolean, default: false },
  useSpeaker: { type: Boolean, default: true },
  canScreenShare: { type: Boolean, default: false },
  selectedAudioInputId: { type: String, default: null },
  selectedVideoInputId: { type: String, default: null },
  selectedAudioOutputId: { type: String, default: null },
  admissionEnabled: { type: Boolean, default: false },
  showWaitingRoom: { type: Boolean, default: false },
  pendingCount: { type: Number, default: 0 },
  showSidebar: { type: Boolean, default: false },
  participantCount: { type: Number, default: 0 }
})

const emit = defineEmits([
  'toggle-mic',
  'toggle-cam',
  'flip',
  'toggle-speaker',
  'toggle-screen-share',
  'toggle-chat',
  'mute-all',
  'unmute-all',
  'leave',
  'audio-input-change',
  'video-input-change',
  'audio-output-change',
  'toggle-waiting-room',
  'toggle-sidebar'
])

const devicePrefs = useDevicePreferences()

const showDesktopOutput = computed(() =>
  !props.isMobile && devicePrefs.canSwitchAudioOutput && devicePrefs.audioOutputDevices.value.length > 1
)

// ── "More" overflow menu (secondary actions) ────────────────────────────────
const showMore = ref(false)
const moreRoot = ref(null)

const canFlip = computed(() => props.isMobile && !props.isCamOff && !props.isWorkshopGuest)
const hasMoreItems = computed(() => canFlip.value || props.isAdmin)

function toggleMore() {
  showMore.value = !showMore.value
}

function closeMore() {
  showMore.value = false
}

function handleFlip() {
  closeMore()
  emit('flip')
}

function handleMuteAll() {
  closeMore()
  emit(props.allMuted ? 'unmute-all' : 'mute-all')
}

function handleOutsideClick(e) {
  if (!showMore.value) return
  if (moreRoot.value && !moreRoot.value.contains(e.target)) showMore.value = false
}

function handleKey(e) {
  if (e.key === 'Escape' && showMore.value) showMore.value = false
}

onMounted(() => {
  document.addEventListener('mousedown', handleOutsideClick, true)
  document.addEventListener('keydown', handleKey)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', handleOutsideClick, true)
  document.removeEventListener('keydown', handleKey)
})
</script>

<template>
  <div class="flex items-center gap-0.5 sm:gap-1">

    <!-- ── Utility group ── -->
    <!-- Chat -->
    <CallBarButton
      icon="chat"
      label="Chat"
      title="Chat"
      :buttonClass="showChat ? 'text-primary-400 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="emit('toggle-chat')"
    >
      <template #badge>
        <span
          v-if="unreadCount > 0"
          class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-[10px] font-bold text-white flex items-center justify-center"
        >{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
      </template>
    </CallBarButton>

    <!-- People / participants -->
    <CallBarButton
      icon="group"
      label="People"
      title="Participants"
      :buttonClass="showSidebar ? 'text-primary-400 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="emit('toggle-sidebar')"
    >
      <template #badge>
        <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-primary-500 text-[10px] font-bold text-white flex items-center justify-center">
          {{ participantCount }}
        </span>
      </template>
    </CallBarButton>

    <!-- Waiting room (admin) -->
    <CallBarButton
      v-if="admissionEnabled"
      icon="door_front"
      label="Waiting"
      title="Waiting room"
      :buttonClass="showWaitingRoom ? 'text-amber-300 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="emit('toggle-waiting-room')"
    >
      <template #badge>
        <span
          v-if="pendingCount > 0"
          class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-[10px] font-bold text-white flex items-center justify-center animate-pulse"
        >{{ pendingCount }}</span>
      </template>
    </CallBarButton>

    <!-- More (overflow: flip camera, mute all) -->
    <div v-if="hasMoreItems" ref="moreRoot" class="relative">
      <CallBarButton
        icon="more_horiz"
        label="More"
        title="More"
        :buttonClass="showMore ? 'text-white bg-white/10' : 'text-white/90 hover:bg-white/10'"
        @click="toggleMore"
      />
      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0 -translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="showMore"
          class="absolute z-50 top-full mt-2 right-0 min-w-[200px] bg-surface-900/95 backdrop-blur-xl border border-surface-700 rounded-xl shadow-2xl overflow-hidden py-1"
        >
          <button
            v-if="canFlip"
            @click="handleFlip"
            class="w-full flex items-center gap-3 px-3 py-2.5 text-sm text-left text-white/80 hover:bg-white/5 transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-white/60">flip_camera_ios</span>
            Flip camera
          </button>
          <button
            v-if="isAdmin"
            @click="handleMuteAll"
            class="w-full flex items-center gap-3 px-3 py-2.5 text-sm text-left transition-colors hover:bg-white/5"
            :class="allMuted ? 'text-orange-300' : 'text-white/80'"
          >
            <span class="material-symbols-rounded text-lg" :class="allMuted ? 'text-orange-400' : 'text-white/60'">
              {{ allMuted ? 'volume_off' : 'volume_up' }}
            </span>
            {{ allMuted ? 'Allow all to unmute' : 'Mute all participants' }}
          </button>
        </div>
      </Transition>
    </div>

    <!-- Divider (utility | media) -->
    <div class="w-px h-7 bg-white/15 mx-1.5 self-center"></div>

    <!-- ── Media group ── -->
    <!-- Camera -->
    <CallBarButton
      v-if="!isWorkshopGuest"
      :icon="isCamOff ? 'videocam_off' : 'videocam'"
      label="Camera"
      :title="isCamOff ? 'Turn camera on' : 'Turn camera off'"
      :buttonClass="isCamOff ? 'text-white/50 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="emit('toggle-cam')"
    >
      <template #chevron>
        <DeviceSelectorMenu
          kind="videoinput"
          align="down"
          flat
          :selectedDeviceId="selectedVideoInputId"
          @update:selectedDeviceId="id => emit('video-input-change', id)"
        />
      </template>
    </CallBarButton>

    <!-- Microphone -->
    <CallBarButton
      v-if="!isWorkshopGuest"
      :icon="isMicMuted ? 'mic_off' : 'mic'"
      label="Mic"
      :title="forceMuted && !isAdmin ? 'Muted by host' : isMicMuted ? 'Unmute' : 'Mute'"
      :buttonClass="forceMuted && !isAdmin ? 'text-orange-400 hover:bg-white/10' : isMicMuted ? 'text-red-400 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="emit('toggle-mic')"
    >
      <template #badge>
        <span v-if="forceMuted && !isAdmin" class="absolute -bottom-0.5 -right-0.5 w-4 h-4 rounded-full bg-orange-500 flex items-center justify-center">
          <span class="material-symbols-rounded text-white text-[9px]">lock</span>
        </span>
      </template>
      <template #chevron>
        <DeviceSelectorMenu
          kind="audioinput"
          align="down"
          flat
          :selectedDeviceId="selectedAudioInputId"
          @update:selectedDeviceId="id => emit('audio-input-change', id)"
        />
      </template>
    </CallBarButton>

    <!-- Speaker / earpiece (mobile) -->
    <CallBarButton
      v-if="isMobile"
      :icon="useSpeaker ? 'volume_up' : 'hearing'"
      label="Speaker"
      :title="useSpeaker ? 'Switch to earpiece' : 'Switch to speaker'"
      :buttonClass="useSpeaker ? 'text-white/90 hover:bg-white/10' : 'text-purple-400 hover:bg-white/10'"
      @click="emit('toggle-speaker')"
    >
      <template #chevron>
        <DeviceSelectorMenu
          v-if="devicePrefs.canSwitchAudioOutput"
          kind="audiooutput"
          align="down"
          flat
          :selectedDeviceId="selectedAudioOutputId"
          @update:selectedDeviceId="id => emit('audio-output-change', id)"
        />
      </template>
    </CallBarButton>

    <!-- Desktop audio-output picker -->
    <CallBarButton
      v-else-if="showDesktopOutput"
      icon="volume_up"
      label="Audio"
      title="Audio output"
      buttonClass="text-white/90 hover:bg-white/10"
    >
      <template #chevron>
        <DeviceSelectorMenu
          kind="audiooutput"
          align="down"
          flat
          :selectedDeviceId="selectedAudioOutputId"
          @update:selectedDeviceId="id => emit('audio-output-change', id)"
        />
      </template>
    </CallBarButton>

    <!-- Screen share -->
    <CallBarButton
      v-if="canScreenShare && !isWorkshopGuest"
      :icon="isScreenSharing ? 'stop_screen_share' : 'screen_share'"
      label="Share"
      :title="isScreenSharing ? 'Stop sharing' : 'Share screen'"
      :buttonClass="isScreenSharing ? 'text-green-400 hover:bg-white/10' : 'text-white/90 hover:bg-white/10'"
      @click="emit('toggle-screen-share')"
    />

    <!-- Leave (flat red) -->
    <CallBarButton
      class="ml-1"
      icon="call_end"
      label="Leave"
      title="Leave call"
      buttonClass="text-red-400 hover:bg-red-500/15"
      labelClass="text-red-400"
      @click="emit('leave')"
    />
  </div>
</template>
