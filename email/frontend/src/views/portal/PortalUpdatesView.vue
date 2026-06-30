<script setup>
/**
 * PortalUpdatesView - Client portal updates feed + single update detail
 * Dual mode:
 * - List mode: shows all updates feed (when no :id param)
 * - Detail mode: shows single update with comments (when :id param present)
 *
 * File previews: audio/video inline players, image lightbox with zoom, PDF embed
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import portalApi from '@/services/portalApi'
import PortalUpdateFeed from '@/components/portal/PortalUpdateFeed.vue'
import PortalCommentThread from '@/components/portal/PortalCommentThread.vue'

const route = useRoute()
const router = useRouter()
const { locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

// Determine mode
const isDetailMode = computed(() => !!route.params.id)

// List state
const updates = ref([])
const listLoading = ref(true)
const listError = ref('')
const page = ref(1)
const totalPages = ref(1)
const unreadCount = ref(0)

// Detail state
const selectedUpdate = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const commentLoading = ref(false)

// Lightbox state
const lightbox = ref({ open: false, src: '', name: '', zoom: 1, panX: 0, panY: 0, dragging: false, startX: 0, startY: 0 })

// ─── File type helpers ───
const AUDIO_EXT = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'wma', 'webm']
const VIDEO_EXT = ['mp4', 'webm', 'ogv', 'mov', 'avi', 'mkv', 'm4v']
const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif']
const PDF_EXT   = ['pdf']

function getExt(name) {
  return (name || '').split('.').pop().toLowerCase()
}
function isAudio(file) { return AUDIO_EXT.includes(getExt(file.original_name)) || (file.mime_type || '').startsWith('audio/') }
function isVideo(file) { return VIDEO_EXT.includes(getExt(file.original_name)) || (file.mime_type || '').startsWith('video/') }
function isImage(file) { return IMAGE_EXT.includes(getExt(file.original_name)) || (file.mime_type || '').startsWith('image/') }
function isPdf(file)   { return PDF_EXT.includes(getExt(file.original_name)) || (file.mime_type || '') === 'application/pdf' }

function fileUrl(file, inline = false) {
  const token = localStorage.getItem('portal_session_token') || ''
  const base = `/api/portal/updates/${selectedUpdate.value.id}/files/${file.id}`
  const params = new URLSearchParams()
  if (token) params.set('portal_token', token)
  if (inline) params.set('inline', '1')
  return `${base}?${params.toString()}`
}

function fileIcon(file) {
  if (isAudio(file)) return 'audio_file'
  if (isVideo(file)) return 'video_file'
  if (isImage(file)) return 'image'
  if (isPdf(file)) return 'picture_as_pdf'
  return 'description'
}

function formatSize(bytes) {
  if (!bytes) return '0 KB'
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

// ─── Lightbox ───
function openLightbox(file) {
  lightbox.value = { open: true, src: fileUrl(file, true), name: file.original_name, zoom: 1, panX: 0, panY: 0, dragging: false, startX: 0, startY: 0 }
  document.addEventListener('keydown', lightboxKeydown)
}
function closeLightbox() {
  lightbox.value.open = false
  document.removeEventListener('keydown', lightboxKeydown)
}
function lightboxKeydown(e) {
  if (e.key === 'Escape') closeLightbox()
  if (e.key === '+' || e.key === '=') lightbox.value.zoom = Math.min(5, lightbox.value.zoom + 0.25)
  if (e.key === '-') { lightbox.value.zoom = Math.max(0.25, lightbox.value.zoom - 0.25); lightbox.value.panX = 0; lightbox.value.panY = 0 }
  if (e.key === '0') { lightbox.value.zoom = 1; lightbox.value.panX = 0; lightbox.value.panY = 0 }
}
function lightboxWheel(e) {
  e.preventDefault()
  const delta = e.deltaY > 0 ? -0.15 : 0.15
  lightbox.value.zoom = Math.max(0.25, Math.min(5, lightbox.value.zoom + delta))
  if (lightbox.value.zoom <= 1) { lightbox.value.panX = 0; lightbox.value.panY = 0 }
}
function lightboxMouseDown(e) {
  if (lightbox.value.zoom > 1) {
    lightbox.value.dragging = true
    lightbox.value.startX = e.clientX - lightbox.value.panX
    lightbox.value.startY = e.clientY - lightbox.value.panY
  }
}
function lightboxMouseMove(e) {
  if (lightbox.value.dragging) {
    lightbox.value.panX = e.clientX - lightbox.value.startX
    lightbox.value.panY = e.clientY - lightbox.value.startY
  }
}
function lightboxMouseUp() { lightbox.value.dragging = false }

onBeforeUnmount(() => document.removeEventListener('keydown', lightboxKeydown))

// Watch route changes
watch(() => route.params.id, (newId) => {
  if (newId) {
    fetchUpdate(newId)
  } else {
    selectedUpdate.value = null
    fetchUpdates()
  }
}, { immediate: false })

onMounted(() => {
  if (isDetailMode.value) {
    fetchUpdate(route.params.id)
  }
  fetchUpdates()
})

async function fetchUpdates() {
  listLoading.value = true
  try {
    const res = await portalApi.get('/portal/updates', { params: { page: page.value, per_page: 20 } })
    if (res.data?.success) {
      updates.value = res.data.data?.updates || []
      totalPages.value = res.data.data?.total_pages || 1
      unreadCount.value = res.data.data?.unread_count || 0
    }
  } catch (e) {
    listError.value = e.response?.data?.message || 'portalUpdatesView.failedToLoadUpdates'
  } finally {
    listLoading.value = false
  }
}

async function fetchUpdate(id) {
  detailLoading.value = true
  detailError.value = ''
  try {
    const res = await portalApi.get(`/portal/updates/${id}`)
    if (res.data?.success) {
      selectedUpdate.value = res.data.data
      // Auto-mark as read
      if (!selectedUpdate.value.read_at) {
        await portalApi.post(`/portal/updates/${id}/read`).catch(() => {})
        selectedUpdate.value.read_at = new Date().toISOString()
      }
    }
  } catch (e) {
    detailError.value = e.response?.data?.message || 'portalUpdatesView.failedToLoadUpdate'
  } finally {
    detailLoading.value = false
  }
}

function openUpdate(update) {
  router.push({ name: 'portal-update', params: { id: update.id } })
}

function backToList() {
  router.push({ name: 'portal-updates' })
}

async function markRead(update) {
  if (update.read_at) return
  try {
    await portalApi.post(`/portal/updates/${update.id}/read`)
    update.read_at = new Date().toISOString()
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  } catch (e) { /* silent */ }
}

async function addComment({ content, parent_comment_id }) {
  if (!selectedUpdate.value) return
  commentLoading.value = true
  try {
    const res = await portalApi.post(`/portal/updates/${selectedUpdate.value.id}/comments`, {
      content,
      parent_comment_id
    })
    if (res.data?.success && res.data.data) {
      if (!selectedUpdate.value.comments) selectedUpdate.value.comments = []
      selectedUpdate.value.comments.push(res.data.data)
    }
  } catch (e) {
    // Could show error toast
  } finally {
    commentLoading.value = false
  }
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(localeTag.value, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div>
    <!-- =================== DETAIL MODE =================== -->
    <div v-if="isDetailMode">
      <!-- Back button -->
      <button @click="backToList"
              class="flex items-center gap-1 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 mb-4">
        <span class="material-symbols-rounded text-lg">arrow_back</span>
        {{ $t('portalUpdatesView.backToUpdates') }}
      </button>

      <!-- Loading -->
      <div v-if="detailLoading" class="text-center py-16">
        <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
      </div>

      <!-- Error -->
      <div v-else-if="detailError" class="text-center py-16">
        <span class="material-symbols-rounded text-4xl text-red-400">error</span>
        <p class="mt-2 text-surface-500">{{ typeof detailError === 'string' && detailError.startsWith('portalUpdatesView.') ? $t(detailError) : detailError }}</p>
      </div>

      <!-- Update Detail -->
      <div v-else-if="selectedUpdate" class="space-y-6">
        <!-- Main content card -->
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-6">
          <!-- Type badge -->
          <div class="flex items-center gap-2 mb-3">
            <span :class="['text-xs font-medium px-2.5 py-1 rounded-full',
              selectedUpdate.update_type === 'design' ? 'bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' :
              selectedUpdate.update_type === 'milestone' ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300' :
              selectedUpdate.update_type === 'deliverable' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' :
              'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300']">
              {{ selectedUpdate.update_type }}
            </span>
            <span class="text-sm text-surface-400">{{ formatDate(selectedUpdate.created_at) }}</span>
          </div>

          <h1 class="text-2xl font-bold text-surface-900 dark:text-white mb-4">{{ selectedUpdate.title }}</h1>

          <!-- HTML content -->
          <div v-if="selectedUpdate.content_html" 
               class="prose dark:prose-invert max-w-none text-sm" 
               v-html="selectedUpdate.content_html">
          </div>
          <p v-else-if="selectedUpdate.content_text" 
             class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-wrap">
            {{ selectedUpdate.content_text }}
          </p>

          <!-- Mood board link -->
          <div v-if="selectedUpdate.mood_board_share_token" class="mt-4 p-3 rounded-lg bg-surface-50 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600">
            <a :href="`/mood/share/${selectedUpdate.mood_board_share_token}`" target="_blank"
               class="flex items-center gap-2 text-primary-600 dark:text-primary-400 hover:underline text-sm font-medium">
              <span class="material-symbols-rounded text-lg">dashboard_customize</span>
              {{ $t('portalUpdatesView.viewMoodBoard') }}
              <span class="material-symbols-rounded text-sm">open_in_new</span>
            </a>
          </div>

          <!-- Files with inline previews -->
          <div v-if="selectedUpdate.files?.length > 0" class="mt-6">
            <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-200 mb-3 flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">attach_file</span>
              {{ $t('portalUpdatesView.attachedFilesCount', selectedUpdate.files.length, { count: selectedUpdate.files.length }) }}
            </h4>
            <div class="space-y-3">
              <div v-for="file in selectedUpdate.files" :key="file.id"
                   class="rounded-xl bg-surface-50 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600 overflow-hidden">

                <!-- ═══ AUDIO PLAYER ═══ -->
                <div v-if="isAudio(file)" class="p-4">
                  <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-lg bg-violet-100 dark:bg-violet-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="material-symbols-rounded text-xl text-violet-600 dark:text-violet-400">audio_file</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">{{ file.original_name }}</p>
                      <p class="text-xs text-surface-400">{{ formatSize(file.file_size) }}</p>
                    </div>
                    <a :href="fileUrl(file)" download
                       class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                       :title="$t('portalUpdatesView.download')">
                      <span class="material-symbols-rounded text-lg">download</span>
                    </a>
                  </div>
                  <audio controls preload="metadata" class="w-full h-10 rounded-lg"
                         :src="fileUrl(file, true)">
                    {{ $t('portalUpdatesView.audioNotSupported') }}
                  </audio>
                </div>

                <!-- ═══ VIDEO PLAYER ═══ -->
                <div v-else-if="isVideo(file)">
                  <video controls preload="metadata" 
                         class="w-full max-h-[500px] bg-black"
                         :src="fileUrl(file, true)">
                    {{ $t('portalUpdatesView.videoNotSupported') }}
                  </video>
                  <div class="flex items-center gap-3 px-4 py-3">
                    <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="material-symbols-rounded text-lg text-blue-600 dark:text-blue-400">video_file</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">{{ file.original_name }}</p>
                      <p class="text-xs text-surface-400">{{ formatSize(file.file_size) }}</p>
                    </div>
                    <a :href="fileUrl(file)" download
                       class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                       :title="$t('portalUpdatesView.download')">
                      <span class="material-symbols-rounded text-lg">download</span>
                    </a>
                  </div>
                </div>

                <!-- ═══ IMAGE PREVIEW ═══ -->
                <div v-else-if="isImage(file)">
                  <div class="relative group cursor-pointer" @click="openLightbox(file)">
                    <img :src="fileUrl(file, true)" :alt="file.original_name" 
                         class="w-full max-h-[500px] object-contain bg-surface-100 dark:bg-surface-900"
                         loading="lazy" />
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100">
                      <div class="bg-black/60 backdrop-blur-sm rounded-full p-3">
                        <span class="material-symbols-rounded text-2xl text-white">zoom_in</span>
                      </div>
                    </div>
                  </div>
                  <div class="flex items-center gap-3 px-4 py-3">
                    <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="material-symbols-rounded text-lg text-emerald-600 dark:text-emerald-400">image</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">{{ file.original_name }}</p>
                      <p class="text-xs text-surface-400">{{ formatSize(file.file_size) }}</p>
                    </div>
                    <a :href="fileUrl(file)" download
                       class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                       :title="$t('portalUpdatesView.download')">
                      <span class="material-symbols-rounded text-lg">download</span>
                    </a>
                  </div>
                </div>

                <!-- ═══ PDF EMBED ═══ -->
                <div v-else-if="isPdf(file)">
                  <iframe :src="fileUrl(file, true)" 
                          class="w-full h-[600px] border-0 bg-white">
                  </iframe>
                  <div class="flex items-center gap-3 px-4 py-3">
                    <div class="w-8 h-8 rounded-lg bg-red-100 dark:bg-red-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="material-symbols-rounded text-lg text-red-600 dark:text-red-400">picture_as_pdf</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">{{ file.original_name }}</p>
                      <p class="text-xs text-surface-400">{{ formatSize(file.file_size) }}</p>
                    </div>
                    <a :href="fileUrl(file)" download
                       class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                       :title="$t('portalUpdatesView.download')">
                      <span class="material-symbols-rounded text-lg">download</span>
                    </a>
                  </div>
                </div>

                <!-- ═══ OTHER FILES (download only) ═══ -->
                <a v-else :href="fileUrl(file)" download
                   class="flex items-center gap-3 p-4 hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors">
                  <div class="w-10 h-10 rounded-lg bg-surface-200 dark:bg-surface-600 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-rounded text-xl text-surface-500">{{ fileIcon(file) }}</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">{{ file.original_name }}</p>
                    <p class="text-xs text-surface-400">{{ formatSize(file.file_size) }}</p>
                  </div>
                  <span class="material-symbols-rounded text-lg text-surface-400">download</span>
                </a>
              </div>
            </div>
          </div>
        </div>

        <!-- Comments Section -->
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-6">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-surface-500">chat_bubble</span>
            {{ $t('portalUpdatesView.comments') }}
            <span v-if="selectedUpdate.comments?.length" class="text-xs text-surface-400 font-normal">
              ({{ selectedUpdate.comments.length }})
            </span>
          </h3>
          <PortalCommentThread
            :comments="selectedUpdate.comments || []"
            :loading="commentLoading"
            @add-comment="addComment"
          />
        </div>
      </div>
    </div>

    <!-- =================== LIST MODE =================== -->
    <div v-else>
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 class="text-xl font-bold text-surface-900 dark:text-white">{{ $t('portalUpdatesView.projectUpdates') }}</h2>
          <p v-if="unreadCount > 0" class="text-sm text-primary-600 dark:text-primary-400 mt-0.5">
            {{ $t('portalUpdatesView.unreadUpdatesCount', unreadCount, { count: unreadCount }) }}
          </p>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="listLoading && updates.length === 0" class="text-center py-16">
        <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
      </div>

      <!-- Error -->
      <div v-else-if="listError" class="text-center py-16">
        <span class="material-symbols-rounded text-4xl text-red-400">error</span>
        <p class="mt-2 text-surface-500">{{ typeof listError === 'string' && listError.startsWith('portalUpdatesView.') ? $t(listError) : listError }}</p>
        <button @click="fetchUpdates" class="mt-4 text-primary-600 hover:underline text-sm">{{ $t('portalUpdatesView.retry') }}</button>
      </div>

      <!-- Empty -->
      <div v-else-if="updates.length === 0" class="text-center py-16">
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">update</span>
        <h3 class="text-lg font-semibold text-surface-600 dark:text-surface-300 mt-3">{{ $t('portalUpdatesView.noUpdatesYet') }}</h3>
        <p class="text-sm text-surface-400 mt-1">{{ $t('portalUpdatesView.yourTeamWillPushUpdates') }}</p>
      </div>

      <!-- Update Feed -->
      <div v-else class="space-y-4">
        <PortalUpdateFeed
          v-for="update in updates"
          :key="update.id"
          :update="update"
          @click="openUpdate(update)"
          @mark-read="markRead(update)"
        />

        <!-- Load more -->
        <div v-if="page < totalPages" class="text-center pt-4">
          <button @click="page++; fetchUpdates()" :disabled="listLoading"
                  class="px-6 py-2.5 rounded-xl bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-200 
                         text-sm font-medium hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-50 transition-colors">
            {{ listLoading ? $t('portalUpdatesView.loading') : $t('portalUpdatesView.loadMore') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════ IMAGE LIGHTBOX ═══════════ -->
    <Teleport to="body">
      <Transition name="lightbox">
        <div v-if="lightbox.open" 
             class="fixed inset-0 z-[9999] bg-black/90 backdrop-blur-sm flex flex-col"
             @wheel.prevent="lightboxWheel"
             @mouseup="lightboxMouseUp"
             @mousemove="lightboxMouseMove">
          <!-- Toolbar -->
          <div class="flex items-center justify-between px-4 py-3 bg-black/50 flex-shrink-0">
            <p class="text-sm text-white/80 truncate max-w-[60%]">{{ lightbox.name }}</p>
            <div class="flex items-center gap-1">
              <button @click="lightbox.zoom = Math.max(0.25, lightbox.zoom - 0.25); if(lightbox.zoom <= 1) { lightbox.panX = 0; lightbox.panY = 0 }"
                      class="p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" :title="$t('portalUpdatesView.zoomOut')">
                <span class="material-symbols-rounded text-xl">zoom_out</span>
              </button>
              <span class="text-sm text-white/60 w-14 text-center tabular-nums">{{ Math.round(lightbox.zoom * 100) }}%</span>
              <button @click="lightbox.zoom = Math.min(5, lightbox.zoom + 0.25)"
                      class="p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" :title="$t('portalUpdatesView.zoomIn')">
                <span class="material-symbols-rounded text-xl">zoom_in</span>
              </button>
              <button @click="lightbox.zoom = 1; lightbox.panX = 0; lightbox.panY = 0"
                      class="p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" :title="$t('portalUpdatesView.reset0')">
                <span class="material-symbols-rounded text-xl">fit_screen</span>
              </button>
              <div class="w-px h-6 bg-white/20 mx-1"></div>
              <button @click="closeLightbox"
                      class="p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" :title="$t('portalUpdatesView.closeEsc')">
                <span class="material-symbols-rounded text-xl">close</span>
              </button>
            </div>
          </div>
          <!-- Image -->
          <div class="flex-1 flex items-center justify-center overflow-hidden"
               :class="lightbox.zoom > 1 ? 'cursor-grab' : 'cursor-zoom-in'"
               @mousedown="lightboxMouseDown"
               @click.self="closeLightbox">
            <img :src="lightbox.src" :alt="lightbox.name"
                 class="max-w-full max-h-full object-contain select-none transition-transform duration-150"
                 :style="{ transform: `scale(${lightbox.zoom}) translate(${lightbox.panX / lightbox.zoom}px, ${lightbox.panY / lightbox.zoom}px)` }"
                 draggable="false" />
          </div>
          <!-- Hint -->
          <div class="text-center py-2 text-xs text-white/40 flex-shrink-0">
            {{ $t('portalUpdatesView.lightboxHint') }}
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.lightbox-enter-active, .lightbox-leave-active { transition: opacity 0.2s ease; }
.lightbox-enter-from, .lightbox-leave-to { opacity: 0; }
</style>
