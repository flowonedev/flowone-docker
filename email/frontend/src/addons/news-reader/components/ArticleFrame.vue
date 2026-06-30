<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { getApiOrigin } from '@/services/serverRegistry'
import { fetchProxyUrl } from '@/addons/news-reader/services/newsApi'

const props = defineProps({
  src: { type: String, required: true },
})

const { t } = useI18n()
const loaded = ref(false)
const error = ref(false)
const proxiedSrc = ref('')
let timer = null
let activeRequestId = 0

async function loadProxiedUrl() {
  const requestId = ++activeRequestId
  loaded.value = false
  error.value = false
  proxiedSrc.value = ''
  if (!props.src) {
    error.value = true
    loaded.value = true
    return
  }
  try {
    const path = await fetchProxyUrl(props.src)
    if (requestId !== activeRequestId) return
    if (!path) {
      error.value = true
      loaded.value = true
      return
    }
    proxiedSrc.value = path.startsWith('http') ? path : `${getApiOrigin()}${path}`
  } catch (_) {
    if (requestId !== activeRequestId) return
    error.value = true
    loaded.value = true
  }
}

function onLoad() {
  loaded.value = true
  error.value = false
  if (timer) {
    clearTimeout(timer)
    timer = null
  }
}

function onIframeError() {
  error.value = true
  loaded.value = true
}

watch(
  () => props.src,
  () => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => {
      if (!loaded.value) {
        error.value = true
        loaded.value = true
      }
    }, 20000)
    loadProxiedUrl()
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
  activeRequestId++
})
</script>

<template>
  <div class="relative w-full h-full min-h-[200px] bg-surface-100 dark:bg-[rgb(var(--color-surface))] rounded-lg overflow-hidden">
    <div
      v-if="!loaded && !error"
      class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-surface-500 dark:text-surface-400"
    >
      <span class="material-symbols-rounded text-3xl animate-spin">progress_activity</span>
      <span class="text-sm">{{ t('newsReader.frameLoading') }}</span>
    </div>
    <div
      v-else-if="error"
      class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-4 text-center text-sm text-surface-600 dark:text-surface-300"
    >
      <span class="material-symbols-rounded text-4xl text-surface-400 dark:text-surface-500">block</span>
      <p>{{ t('newsReader.frameBlocked') }}</p>
      <a
        :href="src"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-primary-500 text-white font-semibold text-xs hover:bg-primary-600 transition-colors"
      >
        <span class="material-symbols-rounded text-[14px]">open_in_new</span>
        {{ t('newsReader.openInNewTab') }}
      </a>
    </div>
    <iframe
      v-show="loaded && !error && proxiedSrc"
      :src="proxiedSrc"
      class="w-full h-full min-h-[320px] border-0"
      sandbox="allow-same-origin allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox"
      referrerpolicy="no-referrer"
      loading="lazy"
      title="article"
      @load="onLoad"
      @error="onIframeError"
    />
  </div>
</template>
