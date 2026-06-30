<script setup>
/**
 * CallBarButton
 *
 * Microsoft Teams-style call control: a FLAT icon button (no filled circle)
 * with a small text label centered underneath. Matches the Teams meeting
 * toolbar where each control is a transparent icon + label, colored only on
 * state.
 *
 * The optional device caret (#chevron) is rendered as an ABSOLUTE overlay to
 * the right of the icon so it never pushes the icon/label off-center. Count /
 * lock indicators go in the #badge slot (anchored to the button corner).
 *
 * Icon/label colors are passed via `buttonClass` / `labelClass`.
 */
import { useSlots } from 'vue'

defineProps({
  icon: { type: String, required: true },
  label: { type: String, default: '' },
  title: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  buttonClass: { type: String, default: 'text-white/90 hover:bg-white/10' },
  labelClass: { type: String, default: 'text-white/70' }
})

defineEmits(['click'])

const slots = useSlots()
</script>

<template>
  <!-- When a caret exists we reserve space on the right so the icon column
       stays perfectly centered and the caret floats over the reserved space. -->
  <div class="relative flex items-center" :class="{ 'pr-3.5': !!slots.chevron }">
    <div class="flex flex-col items-center justify-center gap-1 select-none min-w-[52px] px-1">
      <div class="relative">
        <button
          type="button"
          :disabled="disabled"
          :title="title"
          :aria-label="title || label"
          @click="$emit('click', $event)"
          :class="[
            'w-9 h-9 rounded-lg flex items-center justify-center transition-colors',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            buttonClass
          ]"
        >
          <span class="material-symbols-rounded text-[22px]">{{ icon }}</span>
        </button>
        <slot name="badge" />
      </div>
      <span
        v-if="label"
        class="hidden sm:block text-[11px] leading-none whitespace-nowrap"
        :class="labelClass"
      >{{ label }}</span>
    </div>
    <!-- Device caret overlay, vertically centered on the icon row -->
    <div v-if="slots.chevron" class="absolute right-0 top-[18px] -translate-y-1/2">
      <slot name="chevron" />
    </div>
  </div>
</template>
