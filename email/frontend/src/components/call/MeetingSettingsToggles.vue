<template>
  <div :class="containerClass">
    <button
      type="button"
      role="switch"
      tabindex="0"
      :aria-checked="waitingRoom ? 'true' : 'false'"
      :aria-label="waitingRoomLabel || $t('meetingToggles.waitingRoom', 'Waiting room')"
      class="group inline-flex items-center gap-2 select-none cursor-pointer focus:outline-none"
      @click="toggleWaitingRoom"
      @keydown.space.prevent="toggleWaitingRoom"
      @keydown.enter.prevent="toggleWaitingRoom"
    >
      <span
        :class="[
          'relative inline-flex flex-shrink-0 rounded-full transition-colors duration-200 ease-in-out',
          'group-focus-visible:ring-2 group-focus-visible:ring-offset-2 group-focus-visible:ring-offset-white dark:group-focus-visible:ring-offset-surface-900 group-focus-visible:ring-primary-500',
          trackClass,
          waitingRoom ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
        ]"
      >
        <span
          :class="[
            'absolute top-0.5 left-0.5 rounded-full bg-white shadow transition-transform duration-200 ease-in-out',
            knobClass,
            waitingRoom ? knobTranslateOnClass : 'translate-x-0',
          ]"
        ></span>
      </span>
      <span>{{ waitingRoomLabel || $t('meetingToggles.waitingRoom', 'Waiting room') }}</span>
    </button>

    <button
      type="button"
      role="switch"
      tabindex="0"
      :aria-checked="participantsHidden ? 'true' : 'false'"
      :aria-label="workshopModeLabel || $t('meetingToggles.workshopMode', 'Workshop mode')"
      class="group inline-flex items-center gap-2 select-none cursor-pointer focus:outline-none"
      @click="toggleParticipantsHidden"
      @keydown.space.prevent="toggleParticipantsHidden"
      @keydown.enter.prevent="toggleParticipantsHidden"
    >
      <span
        :class="[
          'relative inline-flex flex-shrink-0 rounded-full transition-colors duration-200 ease-in-out',
          'group-focus-visible:ring-2 group-focus-visible:ring-offset-2 group-focus-visible:ring-offset-white dark:group-focus-visible:ring-offset-surface-900 group-focus-visible:ring-amber-500',
          trackClass,
          participantsHidden ? 'bg-amber-500' : 'bg-surface-300 dark:bg-surface-600',
        ]"
      >
        <span
          :class="[
            'absolute top-0.5 left-0.5 rounded-full bg-white shadow transition-transform duration-200 ease-in-out',
            knobClass,
            participantsHidden ? knobTranslateOnClass : 'translate-x-0',
          ]"
        ></span>
      </span>
      <span>{{ workshopModeLabel || $t('meetingToggles.workshopMode', 'Workshop mode') }}</span>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  waitingRoom: { type: Boolean, default: false },
  participantsHidden: { type: Boolean, default: false },
  size: { type: String, default: 'sm' }, // 'sm' | 'md'
  layout: { type: String, default: 'row' }, // 'row' | 'column'
  waitingRoomLabel: { type: String, default: '' },
  workshopModeLabel: { type: String, default: '' },
})

const emit = defineEmits(['update:waitingRoom', 'update:participantsHidden'])

const containerClass = computed(() => {
  const direction = props.layout === 'column' ? 'flex-col gap-2.5' : 'flex-wrap gap-x-4 gap-y-2'
  const text = props.size === 'md'
    ? 'text-sm text-surface-700 dark:text-surface-300'
    : 'text-[11px] text-surface-500 dark:text-surface-400'
  return `flex items-start ${direction} ${text}`
})

const trackClass = computed(() => (props.size === 'md' ? 'h-6 w-11' : 'h-4 w-8'))
const knobClass = computed(() => (props.size === 'md' ? 'h-5 w-5' : 'h-3 w-3'))
const knobTranslateOnClass = computed(() => (props.size === 'md' ? 'translate-x-5' : 'translate-x-4'))

function toggleWaitingRoom() {
  emit('update:waitingRoom', !props.waitingRoom)
}
function toggleParticipantsHidden() {
  emit('update:participantsHidden', !props.participantsHidden)
}
</script>
