<script setup>
import { computed } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { useLayoutStore } from '@/stores/layout'
import ComposeModal from './ComposeModal.vue'
import ComposeInline from './ComposeInline.vue'

const settingsStore = useSettingsStore()
const layout = useLayoutStore()

const useInlineCompose = computed(() => {
  if (layout.isMobile) return false
  return settingsStore.settings.compose_style === 'inline'
})
</script>

<template>
  <ComposeInline v-if="useInlineCompose" />
  <ComposeModal v-else />
</template>
