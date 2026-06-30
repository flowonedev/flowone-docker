<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { buildSrcdoc } from '@/services/emailSrcdocBuilder'

const props = defineProps({
  html: { type: String, default: '' },
  darkMode: { type: Boolean, default: false },
  uid: { type: [String, Number], default: '' },
  searchQuery: { type: String, default: '' },
})

const emit = defineEmits([
  'link-click',
  'mailto-click',
  'calendar-action',
  'height-changed',
  'text-selected',
  'selection-cleared',
  'body-click',
])

const iframeRef = ref(null)
const iframeHeight = ref(120)
const isLoaded = ref(false)

function generateToken() {
  return Math.random().toString(36).substring(2) + Date.now().toString(36)
}

const renderToken = ref(generateToken())

const srcdoc = computed(() => {
  return buildSrcdoc(props.html, {
    darkMode: props.darkMode,
    uid: String(props.uid),
    token: renderToken.value,
    searchQuery: props.searchQuery,
  })
})

watch(() => [props.html, props.darkMode, props.searchQuery], () => {
  renderToken.value = generateToken()
  isLoaded.value = false
  iframeHeight.value = 120
})

function handleMessage(event) {
  if (!iframeRef.value) return
  if (event.source !== iframeRef.value.contentWindow) return

  const data = event.data
  if (!data || typeof data !== 'object') return
  if (data.token !== renderToken.value) return

  switch (data.type) {
    case 'emailHeight': {
      const h = Math.max(60, Math.min(data.height || 120, 50000))
      iframeHeight.value = h
      isLoaded.value = true
      emit('height-changed', h)
      break
    }
    case 'link': {
      const href = data.href || ''
      if (href.startsWith('mailto:')) {
        emit('mailto-click', href)
      } else if (data.dataset && data.dataset.action) {
        emit('calendar-action', data.dataset)
      } else {
        emit('link-click', href, data.dataset || {})
      }
      break
    }
    case 'selection': {
      const iframeRect = iframeRef.value.getBoundingClientRect()
      const adjustedRect = {
        top: (data.rect?.top || 0) + iframeRect.top,
        left: (data.rect?.left || 0) + iframeRect.left,
        width: data.rect?.width || 0,
        height: data.rect?.height || 0,
      }
      emit('text-selected', {
        text: data.text,
        rect: adjustedRect,
      })
      break
    }
    case 'selectionCleared': {
      emit('selection-cleared')
      break
    }
    case 'bodyClick': {
      emit('body-click')
      break
    }
  }
}

onMounted(() => {
  window.addEventListener('message', handleMessage)
})

onBeforeUnmount(() => {
  window.removeEventListener('message', handleMessage)
})
</script>

<template>
  <div class="email-iframe-container relative">
    <div
      v-if="!isLoaded"
      class="absolute inset-0 flex items-center justify-center bg-white dark:bg-surface-800 rounded-lg"
    >
      <div class="flex items-center gap-2 text-surface-400 dark:text-surface-500 text-sm">
        <span class="material-symbols-rounded animate-spin text-base">progress_activity</span>
        Loading email...
      </div>
    </div>
    <iframe
      ref="iframeRef"
      :srcdoc="srcdoc"
      sandbox="allow-scripts allow-same-origin"
      frameborder="0"
      :style="{ height: iframeHeight + 'px', minHeight: isLoaded ? 'auto' : '120px' }"
      class="w-full border-0 block"
      title="Email content"
      tabindex="0"
    />
  </div>
</template>

<style scoped>
.email-iframe-container {
  overflow: hidden;
}
</style>
