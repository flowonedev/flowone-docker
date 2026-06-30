<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Tier badge for a drive file row.
 *
 * Renders nothing when tier_state is 'hot' (the default — no reason
 * to show an icon for the normal case). For cold/tiering/recalling/
 * lost rows it renders a small Material icon with a tooltip explaining
 * the state and what the user can expect (downloads may be slower
 * for cold/recalling; lost is the only state that needs operator
 * attention).
 *
 * Sized to fit inline next to a filename in a file list row.
 */
const props = defineProps({
  tierState: { type: String, default: 'hot' },
  // Optional: ISO string of when the row entered its current tier.
  // Shown in the tooltip when present.
  tierChangedAt: { type: String, default: null },
  size: { type: String, default: 'sm' }, // sm | md
})

const { t } = useI18n()

const isVisible = computed(() => {
  return props.tierState && props.tierState !== 'hot'
})

const icon = computed(() => {
  switch (props.tierState) {
    case 'tiering':   return 'cloud_upload'
    case 'cold':      return 'cloud'
    case 'recalling': return 'cloud_download'
    case 'lost':      return 'error'
    default:          return 'cloud_off'
  }
})

const colorClass = computed(() => {
  switch (props.tierState) {
    case 'tiering':   return 'text-blue-500 dark:text-blue-400'
    case 'cold':      return 'text-surface-400 dark:text-surface-500'
    case 'recalling': return 'text-blue-500 dark:text-blue-400 animate-pulse'
    case 'lost':      return 'text-red-500 dark:text-red-400'
    default:          return 'text-surface-400'
  }
})

const tooltip = computed(() => {
  const stateLabel = t(`storage.tier.${props.tierState}`, props.tierState)
  const desc = t(`storage.tier.desc.${props.tierState}`, '')
  if (props.tierChangedAt) {
    const stamped = t('storage.tier.changedAt', { date: formatDate(props.tierChangedAt) })
    return desc ? `${stateLabel} — ${desc} (${stamped})` : `${stateLabel} (${stamped})`
  }
  return desc ? `${stateLabel} — ${desc}` : stateLabel
})

function formatDate(iso) {
  try {
    return new Date(iso).toLocaleDateString()
  } catch {
    return iso
  }
}

const sizeClass = computed(() => props.size === 'md' ? 'text-base' : 'text-sm')
</script>

<template>
  <span
    v-if="isVisible"
    class="material-symbols-rounded inline-flex items-center cursor-help"
    :class="[colorClass, sizeClass]"
    :title="tooltip"
    :aria-label="tooltip"
  >{{ icon }}</span>
</template>
