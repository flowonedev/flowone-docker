<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  /** Epoch ms from mailbox.getLastRefreshed(folder), or null */
  timestamp: {
    type: Number,
    default: null,
  },
})

const { t, locale } = useI18n()

const tick = ref(0)
let intervalId = null

onMounted(() => {
  intervalId = window.setInterval(() => {
    tick.value++
  }, 15_000)
})

onBeforeUnmount(() => {
  if (intervalId != null) {
    clearInterval(intervalId)
    intervalId = null
  }
})

watch(
  () => props.timestamp,
  () => {
    tick.value++
  }
)

const absoluteTitle = computed(() => {
  if (props.timestamp == null) return ''
  const d = new Date(props.timestamp)
  const timeStr = new Intl.DateTimeFormat(locale.value || undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(d)
  return t('refreshIndicator.lastRefreshedAt', { time: timeStr })
})

const relativeLabel = computed(() => {
  tick.value
  if (props.timestamp == null) return ''
  const now = Date.now()
  const diffSec = Math.floor((now - props.timestamp) / 1000)
  if (diffSec < 45) return t('refreshIndicator.justNow')

  const diffMin = Math.floor(diffSec / 60)
  if (diffMin < 60) {
    const n = Math.max(1, diffMin)
    return t('refreshIndicator.minutesAgo', n, { count: n })
  }

  const diffHr = Math.floor(diffMin / 60)
  if (diffHr < 48) {
    return t('refreshIndicator.hoursAgo', diffHr, { count: diffHr })
  }

  const d = new Date(props.timestamp)
  const yesterday = new Date(now)
  yesterday.setDate(yesterday.getDate() - 1)
  const isYesterday =
    d.getDate() === yesterday.getDate() &&
    d.getMonth() === yesterday.getMonth() &&
    d.getFullYear() === yesterday.getFullYear()

  if (isYesterday) {
    const timeOnly = new Intl.DateTimeFormat(locale.value || undefined, {
      timeStyle: 'short',
    }).format(d)
    return t('refreshIndicator.yesterday', { time: timeOnly })
  }

  return new Intl.DateTimeFormat(locale.value || undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(d)
})
</script>

<template>
  <span
    v-if="timestamp != null"
    class="text-xs text-surface-400 select-none whitespace-nowrap max-w-[7rem] truncate"
    :title="absoluteTitle"
  >
    {{ relativeLabel }}
  </span>
</template>
