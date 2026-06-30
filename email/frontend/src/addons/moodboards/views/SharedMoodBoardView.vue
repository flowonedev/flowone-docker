<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 overflow-hidden">

    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center h-full">
      <div class="flex flex-col items-center gap-4 text-surface-500">
        <span class="material-symbols-rounded text-5xl animate-spin">sync</span>
        <p class="text-sm">Loading shared board...</p>
      </div>
    </div>

    <!-- Password prompt -->
    <div v-else-if="requiresPassword" class="flex items-center justify-center h-full">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl p-8 w-full max-w-sm border border-surface-200 dark:border-surface-700">
        <div class="flex flex-col items-center gap-4 mb-6">
          <div class="w-14 h-14 rounded-2xl bg-primary-50 dark:bg-primary-900/30 flex items-center justify-center">
            <span class="material-symbols-rounded text-3xl text-primary-500">lock</span>
          </div>
          <div class="text-center">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Password Required</h2>
            <p class="text-sm text-surface-500 mt-1">{{ boardName || 'This board' }} is password protected</p>
          </div>
        </div>

        <form @submit.prevent="submitPassword" class="space-y-4">
          <div class="relative">
            <input
              ref="passwordInput"
              v-model="password"
              :type="showPasswordField ? 'text' : 'password'"
              placeholder="Enter password"
              class="w-full px-4 py-3 pr-10 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none"
              :class="{ 'border-red-400 dark:border-red-500': passwordError }"
              autofocus
            />
            <button
              type="button"
              @click="showPasswordField = !showPasswordField"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600"
            >
              <span class="material-symbols-rounded text-lg">{{ showPasswordField ? 'visibility_off' : 'visibility' }}</span>
            </button>
          </div>
          <p v-if="passwordError" class="text-xs text-red-500">{{ passwordError }}</p>
          <button
            type="submit"
            :disabled="!password || verifying"
            class="w-full py-3 px-4 text-sm font-medium rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center gap-2"
          >
            <span v-if="verifying" class="material-symbols-rounded text-sm animate-spin">sync</span>
            {{ verifying ? 'Verifying...' : 'View Board' }}
          </button>
        </form>
      </div>
    </div>

    <!-- Expired link -->
    <div v-else-if="expired" class="flex items-center justify-center h-full">
      <div class="flex flex-col items-center gap-4 text-center px-4">
        <div class="w-16 h-16 rounded-2xl bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-4xl text-amber-500">schedule</span>
        </div>
        <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">Link Expired</h2>
        <p class="text-sm text-surface-500 max-w-xs">
          The share link for "{{ boardName || 'this board' }}" has expired. Please contact the owner for a new link.
        </p>
      </div>
    </div>

    <!-- Not found -->
    <div v-else-if="notFound" class="flex items-center justify-center h-full">
      <div class="flex flex-col items-center gap-4 text-center px-4">
        <div class="w-16 h-16 rounded-2xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-4xl text-red-500">link_off</span>
        </div>
        <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">Board Not Found</h2>
        <p class="text-sm text-surface-500 max-w-xs">
          This share link is invalid or has been removed.
        </p>
      </div>
    </div>

    <!-- Image preloading phase -->
    <div v-else-if="board && preloading" class="flex items-center justify-center h-full" :style="{ backgroundColor: board.background_color || '#f5f5f5' }">
      <div class="flex flex-col items-center gap-5 text-center px-6 max-w-sm">
        <!-- Board color swatch -->
        <div class="w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg"
          :style="{ backgroundColor: board.background_color || '#e5e7eb' }">
          <span class="material-symbols-rounded text-3xl text-white drop-shadow">dashboard_customize</span>
        </div>

        <div>
          <h2 class="text-base font-semibold text-surface-800 dark:text-surface-100">{{ board.name }}</h2>
          <p class="text-xs text-surface-400 mt-1">Loading images...</p>
        </div>

        <!-- Progress bar -->
        <div class="w-64">
          <div class="h-1.5 rounded-full bg-surface-200 dark:bg-surface-700 overflow-hidden">
            <div
              class="h-full rounded-full bg-primary-500 transition-all duration-300 ease-out"
              :style="{ width: preloadProgress + '%' }"
            />
          </div>
          <p class="text-[11px] text-surface-400 mt-2 tabular-nums">
            {{ preloadLoaded }} / {{ preloadTotal }} images
          </p>
        </div>
      </div>
    </div>

    <!-- Board loaded — show canvas -->
    <template v-else-if="board && !preloading">
      <!-- Top bar (hidden during presentation mode) -->
      <div v-if="!store.presentationMode" class="flex items-center justify-between gap-4 px-4 py-2.5 bg-white/90 dark:bg-surface-800/90 backdrop-blur-sm border-b border-surface-200/50 dark:border-surface-700/50 z-30 flex-shrink-0">
        <div class="flex items-center gap-3 min-w-0">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
            :style="{ backgroundColor: board.background_color || '#f5f5f5' }"
          >
            <span class="material-symbols-rounded text-lg text-surface-600">dashboard_customize</span>
          </div>
          <div class="min-w-0">
            <h1 class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate">{{ board.name }}</h1>
            <p class="text-[11px] text-surface-400 truncate">
              Shared by {{ board.owner_email }}
              <span v-if="shareMode === 'edit'" class="ml-1 px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 text-[10px] font-medium">Edit access</span>
              <span v-else class="ml-1 px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-500 text-[10px] font-medium">View only</span>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
          <!-- Zoom controls -->
          <div class="flex items-center gap-1 bg-surface-100 dark:bg-surface-700 rounded-xl px-2 py-1">
            <button @click="canvasRef?.zoomOut?.()" class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-500 transition-colors">
              <span class="material-symbols-rounded text-sm">remove</span>
            </button>
            <span class="text-xs font-medium text-surface-600 dark:text-surface-300 w-10 text-center tabular-nums">{{ Math.round(store.zoom * 100) }}%</span>
            <button @click="canvasRef?.zoomIn?.()" class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-500 transition-colors">
              <span class="material-symbols-rounded text-sm">add</span>
            </button>
          </div>

          <!-- Fit screen -->
          <button
            @click="canvasRef?.fitScreen?.()"
            class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 transition-colors"
            title="Fit to screen"
          >
            <span class="material-symbols-rounded text-lg">fit_screen</span>
          </button>

          <!-- Comments dropdown -->
          <div v-if="comments.allowComments.value" class="relative" ref="sharedCommentMenuRef">
            <button
              @click="showCommentMenu = !showCommentMenu"
              class="relative p-2 rounded-xl transition-colors"
              :class="(isCommentMode || comments.showCommentsPanel.value)
                ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500'"
              title="Comments"
            >
              <span class="material-symbols-rounded text-lg">comment</span>
              <span
                v-if="comments.openThreads.value.length > 0"
                class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[9px] font-bold flex items-center justify-center"
              >
                {{ comments.openThreads.value.length }}
              </span>
            </button>

            <transition name="fade">
              <div
                v-if="showCommentMenu"
                class="absolute right-0 top-full mt-1 w-52 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50"
              >
                <button
                  @click="isCommentMode = !isCommentMode; showCommentMenu = false"
                  class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors"
                  :class="isCommentMode
                    ? 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20'
                    : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'"
                >
                  <span class="material-symbols-rounded text-lg">add_comment</span>
                  <span class="flex-1 text-left">Add Comment</span>
                </button>

                <button
                  @click="toggleCommentsPanel(); showCommentMenu = false"
                  class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors"
                  :class="comments.showCommentsPanel.value
                    ? 'text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20'
                    : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'"
                >
                  <span class="material-symbols-rounded text-lg">chat</span>
                  <span class="flex-1 text-left">Comments Panel</span>
                  <span
                    v-if="comments.openThreads.value.length > 0"
                    class="min-w-[18px] h-4.5 px-1 rounded-full bg-red-500 text-white text-[9px] font-bold flex items-center justify-center"
                  >{{ comments.openThreads.value.length }}</span>
                </button>

                <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>

                <button
                  @click="showCommentPins = !showCommentPins; showCommentMenu = false"
                  class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                >
                  <span class="material-symbols-rounded text-lg">{{ showCommentPins ? 'visibility_off' : 'visibility' }}</span>
                  <span class="flex-1 text-left">{{ showCommentPins ? 'Hide Pins' : 'Show Pins' }}</span>
                </button>
              </div>
            </transition>
          </div>

          <!-- Present button (if board has slides) -->
          <button
            v-if="hasSlides"
            @click="startPresentation"
            class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors text-sm font-medium whitespace-nowrap"
          >
            <span class="material-symbols-rounded text-lg">slideshow</span>
            Present
          </button>
        </div>
      </div>

      <!-- Canvas + Comments panel -->
      <div class="flex-1 flex overflow-hidden">
        <div class="flex-1 relative" @mousemove="onCanvasMouseMove">
          <component
            :is="usePixiRenderer ? PixiCanvas : MoodCanvas"
            ref="canvasRef"
            :board="board"
            @renderer-fallback="onRendererFallback"
            :readonly="shareMode !== 'edit' && !isCommentMode"
            :comment-counts="comments.itemCounts.value"
            :comment-threads="comments.threads.value"
            :active-comment-thread-id="comments.activeThreadId.value"
            :show-comment-pins="showCommentPins"
            v-model:commentMode="isCommentMode"
            @comment-item="onCommentItem"
            @comment-canvas="onCommentCanvas"
            @select-comment-thread="onSelectCommentThread"
          />
        </div>

        <!-- Comments side panel -->
        <MoodCommentsPanel
          v-if="comments.showCommentsPanel.value"
          :threads="comments.threads.value"
          :loading="comments.loading.value"
          :is-public="true"
          :selected-thread-id="comments.activeThreadId.value"
          @close="comments.showCommentsPanel.value = false"
          @add-comment="onAddComment"
          @delete-comment="onDeleteComment"
          @resolve-thread="onResolveThread"
          @unresolve-thread="onUnresolveThread"
          @select-thread="onSelectThread"
          @focus-thread="onFocusThread"
        />
      </div>

      <!-- Comment popover (inline comment creation -- item or canvas) -->
      <MoodCommentPopover
        :visible="!!comments.commentingOnItem.value || !!comments.commentingAtCanvas.value"
        :x="commentPopoverPos.x"
        :y="commentPopoverPos.y"
        :item-id="comments.commentingOnItem.value"
        :pin-x="comments.commentingAtCanvas.value?.canvasX ?? null"
        :pin-y="comments.commentingAtCanvas.value?.canvasY ?? null"
        :is-public="true"
        @submit="onPopoverSubmit"
        @cancel="comments.cancelCommentOnItem()"
      />

      <!-- Filmstrip (when presentation mode) -->
      <MoodFilmstrip
        v-if="store.showFilmstrip && !store.presentationMode"
        @fly-to-slide="onFlyToSlide"
        @start-presentation="startPresentation"
      />
    </template>

    <!-- Presenter overlay -->
    <MoodPresenter
      v-if="store.presentationMode && board"
      :board="board"
      :canvas-ref="canvasRef"
      @exit="onPresentationExit"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useMoodComments } from '@/addons/moodboards/composables/useMoodComments'
import { useMoodGuestSocket } from '@/addons/moodboards/composables/useMoodGuestSocket'
import { useImagePreloader } from '@/addons/moodboards/composables/useImagePreloader'
import MoodCanvas from '../components/MoodCanvas.vue'
import PixiCanvas from '../canvas/PixiCanvas.vue'
import MoodPresenter from '../components/MoodPresenter.vue'
import MoodFilmstrip from '../components/MoodFilmstrip.vue'
import MoodCommentsPanel from '../components/MoodCommentsPanel.vue'
import MoodCommentPopover from '../components/MoodCommentPopover.vue'

const route = useRoute()
const store = useMoodBoardsStore()

const canvasRef = ref(null)
// WebGL (Pixi) is the default renderer; DOM renderer is kept as an explicit fallback
const usePixiRenderer = ref(localStorage.getItem('canvasRenderer') !== 'dom')
/** WebGL canvas reported an unrecoverable error — fall back to the DOM renderer. */
function onRendererFallback() {
  usePixiRenderer.value = false
  localStorage.setItem('canvasRenderer', 'dom')
}
const token = computed(() => route.params.token)

// State
const loading = ref(true)
const requiresPassword = ref(false)
const expired = ref(false)
const notFound = ref(false)
const boardName = ref('')
const password = ref('')
const passwordError = ref('')
const showPasswordField = ref(false)
const verifying = ref(false)
const passwordInput = ref(null)

// Preloading state (shared composable)
const { preloading, preloadTotal, preloadLoaded, preloadProgress, preloadImages } = useImagePreloader()

// Session tracking
const sessionId = ref('')
const startTime = ref(0)
const slidesViewed = ref(0)
let heartbeatInterval = null

// Comments
const isPublicRef = ref(true)
const comments = useMoodComments({
  shareToken: computed(() => token.value),
  isPublic: isPublicRef,
})
const commentPopoverPos = ref({ x: 0, y: 0 })
const isCommentMode = ref(false)
const showCommentPins = ref(true)
const showCommentMenu = ref(false)
const sharedCommentMenuRef = ref(null)

// Guest WebSocket for cursors + real-time comments
const guestSocket = useMoodGuestSocket()

// Computed
const board = computed(() => store.currentBoard)
const shareMode = computed(() => store.publicShareMode)
const hasSlides = computed(() => store.presentationSlides.length > 0)

// Generate a unique session ID
function generateSessionId() {
  return 'sv_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 10)
}

// Load the board
async function loadBoard(pwd = null) {
  loading.value = true
  requiresPassword.value = false
  expired.value = false
  notFound.value = false
  passwordError.value = ''

  const result = await store.loadSharedBoard(token.value, pwd)

  if (result.success) {
    startTracking()
    loading.value = false

    comments.fetchComments().catch(e => {
      console.warn('[SharedMood] Failed to load comments:', e)
    })

    // Connect guest WebSocket for live cursors + comments
    initGuestSocket()

    await preloadImages(store.currentBoard)

    if (store.presentationSlides.length > 0) {
      await nextTick()
      store.startPresentation()
    }
  } else if (result.requires_password) {
    requiresPassword.value = true
    boardName.value = result.board_name || ''
    loading.value = false
    if (pwd) {
      passwordError.value = 'Incorrect password. Please try again.'
    }
    nextTick(() => passwordInput.value?.focus())
  } else if (result.expired) {
    expired.value = true
    boardName.value = result.board_name || ''
    loading.value = false
  } else {
    notFound.value = true
    loading.value = false
  }
}

// Image preloading is now handled by the shared useImagePreloader composable

// Submit password
async function submitPassword() {
  if (!password.value) return
  verifying.value = true
  passwordError.value = ''
  await loadBoard(password.value)
  verifying.value = false
}

// Start analytics tracking
function startTracking() {
  sessionId.value = generateSessionId()
  startTime.value = Date.now()

  // Track initial view
  store.trackPublicView(token.value, sessionId.value, document.referrer)

  // Heartbeat every 30 seconds
  heartbeatInterval = setInterval(() => {
    const duration = Math.round((Date.now() - startTime.value) / 1000)
    store.sendPublicHeartbeat(token.value, sessionId.value, duration, slidesViewed.value)
  }, 30000)
}

// Stop tracking
function stopTracking() {
  if (heartbeatInterval) {
    clearInterval(heartbeatInterval)
    heartbeatInterval = null
  }
  // Send final heartbeat
  if (sessionId.value && startTime.value) {
    const duration = Math.round((Date.now() - startTime.value) / 1000)
    store.sendPublicHeartbeat(token.value, sessionId.value, duration, slidesViewed.value)
  }
}

// Presentation
function startPresentation() {
  // Request fullscreen synchronously in the click handler to preserve user-gesture chain
  if (document.documentElement.requestFullscreen) {
    document.documentElement.requestFullscreen().catch(() => {})
  }
  store.startPresentation()
}

function onPresentationExit() {
  if (shareMode.value === 'view') return
  store.stopPresentation()
}

function onFlyToSlide(slide) {
  if (canvasRef.value && slide) {
    canvasRef.value.animateToFrame(slide, 500, 'fly')
    store.focusedSlideId = slide.id
  }
}

// --- Guest WebSocket setup ---
const COLLAB_COLORS = ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1','#14b8a6','#e11d48']
function collabColor(email) {
  let h = 0
  for (let i = 0; i < email.length; i++) h = ((h << 5) - h) + email.charCodeAt(i)
  return COLLAB_COLORS[Math.abs(h) % COLLAB_COLORS.length]
}

let guestCursorThrottle = null
let staleInterval = null

function initGuestSocket() {
  const boardId = store.currentBoard?.id
  if (!boardId) return

  const gName = localStorage.getItem('mood_comment_guest_name') || 'Guest'
  const myGuestEmail = `guest_${guestSocket.getOrCreateGuestId()}@mood.guest`

  guestSocket.connect(token.value, boardId, gName)

  // Pipe remote cursors into store.collaborators so MoodCanvas renders them
  guestSocket.on('MOOD_BOARD_CURSOR', (payload) => {
    if (parseInt(payload.board_id) !== boardId) return
    if (payload.user_email === myGuestEmail) return

    const idx = store.collaborators.findIndex(c => c.email === payload.user_email)
    const collab = {
      email: payload.user_email,
      name: payload.user_name || payload.user_email,
      cursor_x: payload.x,
      cursor_y: payload.y,
      view_panX: payload.panX ?? null,
      view_panY: payload.panY ?? null,
      view_zoom: payload.zoom ?? null,
      color: collabColor(payload.user_email),
      lastSeen: Date.now(),
    }
    if (idx >= 0) {
      store.collaborators[idx] = collab
    } else {
      store.collaborators.push(collab)
    }
  })

  guestSocket.on('MOOD_BOARD_PRESENCE_JOIN', (payload) => {
    if (parseInt(payload.board_id) !== boardId) return
    if (payload.user_email === myGuestEmail) return
    if (!store.collaborators.find(c => c.email === payload.user_email)) {
      store.collaborators.push({
        email: payload.user_email,
        name: payload.user_name || payload.user_email,
        cursor_x: null, cursor_y: null,
        color: collabColor(payload.user_email),
        lastSeen: Date.now(),
      })
    }
  })

  guestSocket.on('MOOD_BOARD_PRESENCE_LEAVE', (payload) => {
    if (parseInt(payload.board_id) !== boardId) return
    store.collaborators = store.collaborators.filter(c => c.email !== payload.user_email)
  })

  guestSocket.on('MOOD_BOARD_COMMENT_ADDED', (payload) => {
    if (parseInt(payload.board_id) !== boardId) return
    if (payload.comment) comments.handleRealtimeComment(payload.comment)
  })

  guestSocket.on('MOOD_BOARD_COMMENT_DELETED', (payload) => {
    if (parseInt(payload.board_id) !== boardId) return
    if (payload.comment_id) comments.deleteComment(payload.comment_id)
  })

  guestSocket.on('MOOD_BOARD_THREAD_RESOLVED', (payload) => {
    if (parseInt(payload.board_id) !== boardId) return
    comments.handleRealtimeResolve(payload)
  })

  // Fade stale cursors
  staleInterval = setInterval(() => {
    const now = Date.now()
    store.collaborators = store.collaborators.filter(c => now - (c.lastSeen || 0) < 10_000)
  }, 5000)
}

function onCanvasMouseMove(e) {
  if (guestCursorThrottle || !guestSocket.connected.value || !store.currentBoard?.id) return
  const container = canvasRef.value?.$el || canvasRef.value
  if (!container) return
  const rect = container.getBoundingClientRect()
  const x = (e.clientX - rect.left - store.panX) / store.zoom
  const y = (e.clientY - rect.top - store.panY) / store.zoom
  guestSocket.send({
    type: 'MOOD_BOARD_CURSOR_MOVE',
    boardId: store.currentBoard.id,
    x: Math.round(x),
    y: Math.round(y),
    panX: Math.round(store.panX),
    panY: Math.round(store.panY),
    zoom: Math.round(store.zoom * 1000) / 1000,
    userName: localStorage.getItem('mood_comment_guest_name') || 'Guest',
  })
  guestCursorThrottle = setTimeout(() => { guestCursorThrottle = null }, 50)
}

// Comment handlers (broadcast via WS after REST success)
async function onAddComment(data) {
  const result = await comments.addComment(data)
  if (result) {
    guestSocket.send({ type: 'MOOD_BOARD_COMMENT_BROADCAST', boardId: store.currentBoard?.id, comment: result })
  }
}

async function onDeleteComment(commentId) {
  const ok = await comments.deleteComment(commentId)
  if (ok) {
    guestSocket.send({ type: 'MOOD_BOARD_COMMENT_DELETE_BROADCAST', boardId: store.currentBoard?.id, commentId })
  }
}

async function onResolveThread(threadId) {
  const ok = await comments.resolveThread(threadId)
  if (ok) {
    guestSocket.send({ type: 'MOOD_BOARD_THREAD_RESOLVE_BROADCAST', boardId: store.currentBoard?.id, threadId, resolved: true })
  }
}

async function onUnresolveThread(threadId) {
  const ok = await comments.unresolveThread(threadId)
  if (ok) {
    guestSocket.send({ type: 'MOOD_BOARD_THREAD_RESOLVE_BROADCAST', boardId: store.currentBoard?.id, threadId, resolved: false })
  }
}

function onSelectThread(thread) {
  comments.activeThreadId.value = thread.thread_id
}

function onCommentItem({ itemId, screenX, screenY }) {
  commentPopoverPos.value = { x: screenX, y: screenY }
  comments.startCommentOnItem(itemId)
}

function onCommentCanvas({ canvasX, canvasY, screenX, screenY }) {
  commentPopoverPos.value = { x: screenX, y: screenY }
  comments.startCommentOnCanvas({ canvasX, canvasY, screenX, screenY })
}

function toggleCommentsPanel() {
  comments.showCommentsPanel.value = !comments.showCommentsPanel.value
  if (comments.showCommentsPanel.value) {
    comments.activeThreadId.value = null
  }
}

function onSelectCommentThread(thread) {
  comments.activeThreadId.value = thread.thread_id
  comments.showCommentsPanel.value = true
}

function onFocusThread(thread) {
  if (thread.pin_x != null && thread.pin_y != null) {
    const container = canvasRef.value?.$el || canvasRef.value
    if (!container) return
    const rect = container.getBoundingClientRect()
    const targetX = -(thread.pin_x * store.zoom) + rect.width / 2
    const targetY = -(thread.pin_y * store.zoom) + rect.height / 2
    store.panX = targetX
    store.panY = targetY
  }
  comments.activeThreadId.value = thread.thread_id
}

async function onPopoverSubmit(data) {
  const result = await comments.addComment(data)
  if (result) {
    guestSocket.send({ type: 'MOOD_BOARD_COMMENT_BROADCAST', boardId: store.currentBoard?.id, comment: result })
    comments.cancelCommentOnItem()
    if (!comments.showCommentsPanel.value) {
      comments.showCommentsPanel.value = true
    }
    comments.activeThreadId.value = result.thread_id
  }
}

function onSharedDocClick(e) {
  if (showCommentMenu.value && sharedCommentMenuRef.value && !sharedCommentMenuRef.value.contains(e.target)) {
    showCommentMenu.value = false
  }
}

// Lifecycle
onMounted(() => {
  store.$reset()
  store.isPublicView = true
  loadBoard()
  document.addEventListener('click', onSharedDocClick)
})

onUnmounted(() => {
  stopTracking()
  guestSocket.disconnect()
  if (staleInterval) clearInterval(staleInterval)
  document.removeEventListener('click', onSharedDocClick)
  store.$reset()
})
</script>

<style scoped>
/* Material Symbols loaded globally via /fonts/core.css in index.html */
</style>

