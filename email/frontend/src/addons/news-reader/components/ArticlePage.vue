<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { storeToRefs } from 'pinia'
import { useNewsReaderStore } from '@/addons/news-reader/stores/newsReader'
import { fetchFullArticle } from '@/addons/news-reader/services/newsApi'
import { articleDisplayTitle } from '@/addons/news-reader/utils/articleDisplay'
import ArticleFrame from './ArticleFrame.vue'

const props = defineProps({
  article: { type: Object, required: true },
})
const emit = defineEmits(['back', 'close'])

const { t } = useI18n()
const mode = ref('reader')
const nr = useNewsReaderStore()
const { bookmarks, itemOrder, loading, listHasMore } = storeToRefs(nr)

/**
 * Position of the current article in the loaded list. Used to disable
 * Prev at the start, and to know whether to load more pages when the
 * user navigates past the end.
 */
const articleIndex = computed(() =>
  itemOrder.value.indexOf(Number(props.article?.id))
)
const hasPrev = computed(() => articleIndex.value > 0)
const hasNext = computed(() =>
  articleIndex.value === -1
    ? false
    : articleIndex.value < itemOrder.value.length - 1 || listHasMore.value
)

const navigating = ref(false)
const swipeOffset = ref(0)
const swipeDirection = ref(0) // -1 prev, 1 next, 0 idle (used for transition class)

async function go(delta) {
  if (navigating.value || !props.article?.id) return
  if (delta < 0 && !hasPrev.value) return
  if (delta > 0 && !hasNext.value) return
  navigating.value = true
  swipeDirection.value = delta
  try {
    await nr.navigateArticle(props.article.id, delta)
  } finally {
    swipeOffset.value = 0
    setTimeout(() => { swipeDirection.value = 0; navigating.value = false }, 320)
  }
}

function onKeydown(e) {
  // Don't hijack arrows while typing in form fields or content-editables
  const tag = (e.target?.tagName || '').toLowerCase()
  if (['input', 'textarea', 'select'].includes(tag) || e.target?.isContentEditable) return
  if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1) }
  else if (e.key === 'ArrowRight') { e.preventDefault(); go(1) }
}

onMounted(() => {
  if (typeof window !== 'undefined') {
    window.addEventListener('keydown', onKeydown)
  }
})
onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('keydown', onKeydown)
  }
})

/**
 * Touch swipe handling: track horizontal pan, ignore if the user is
 * mostly scrolling vertically. Threshold is 80px or 40% of viewport
 * width, whichever is smaller — feels native on phones, doesn't trigger
 * accidentally on small drags.
 */
const touchStart = { x: 0, y: 0, t: 0 }
let touchActive = false
function onTouchStart(e) {
  if (!e.touches?.length) return
  touchStart.x = e.touches[0].clientX
  touchStart.y = e.touches[0].clientY
  touchStart.t = Date.now()
  touchActive = true
  swipeOffset.value = 0
}
function onTouchMove(e) {
  if (!touchActive || !e.touches?.length) return
  const dx = e.touches[0].clientX - touchStart.x
  const dy = e.touches[0].clientY - touchStart.y
  // Lock direction once it's clearly horizontal — kills the conflict
  // with vertical reading scroll.
  if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) {
    swipeOffset.value = dx
  }
}
function onTouchEnd() {
  if (!touchActive) return
  touchActive = false
  const dx = swipeOffset.value
  const elapsed = Date.now() - touchStart.t
  const w = typeof window !== 'undefined' ? window.innerWidth : 1024
  const threshold = Math.min(80, w * 0.18)
  // Treat fast flicks as commits even if they didn't reach the distance
  // threshold (mirrors iOS Photos-style swipe).
  const flick = elapsed < 250 && Math.abs(dx) > 30
  if (dx <= -threshold || (flick && dx < 0)) go(1)
  else if (dx >= threshold || (flick && dx > 0)) go(-1)
  else swipeOffset.value = 0
}

/**
 * Trackpad two-finger horizontal swipe lands as a wheel event with a
 * dominant deltaX. We accumulate it and fire once per gesture (debounced
 * so rapid wheels don't multi-fire). MacOS sends momentum scrolling
 * after the user releases — we ignore those tiny tail events.
 */
let wheelAccum = 0
let wheelLast = 0
function onWheel(e) {
  if (Math.abs(e.deltaX) <= Math.abs(e.deltaY)) return // mostly vertical
  e.preventDefault()
  const now = Date.now()
  if (now - wheelLast > 600) wheelAccum = 0
  wheelLast = now
  wheelAccum += e.deltaX
  if (wheelAccum <= -120) { wheelAccum = 0; go(-1) }
  else if (wheelAccum >= 120) { wheelAccum = 0; go(1) }
}
const bookmarked = computed(() => bookmarks.value.has(Number(props.article.id)))
function toggleBookmark() {
  nr.toggleBookmark(props.article.id)
}

const displayTitle = computed(
  () => articleDisplayTitle(props.article) || t('newsReader.untitled')
)

const isVideo = computed(() => !!props.article?.is_video)


/**
 * YouTube embed URL via the privacy-respecting nocookie domain. YouTube
 * explicitly allows /embed/<id> in third-party iframes, so the standard
 * frame-blocking headers don't apply and we don't need our proxy.
 */
const youtubeEmbedUrl = computed(() => {
  if (!isVideo.value) return ''
  const id = props.article?.video_id || ''
  if (!id) return ''
  return `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?rel=0&modestbranding=1`
})

const youtubeWatchUrl = computed(() => {
  if (!isVideo.value) return ''
  const id = props.article?.video_id || ''
  if (id) return `https://www.youtube.com/watch?v=${encodeURIComponent(id)}`
  return props.article?.link || ''
})

/**
 * Server-side full-article extraction. The RSS feed usually only ships a
 * short summary; we fetch the publisher's page and run a Readability-style
 * extractor on it to get the full body. Cached server-side per item so
 * subsequent opens are instant. Skipped for video items (no body to
 * extract — the YouTube description in `summary` is what we show).
 */
const fullState = ref('idle') // 'idle' | 'loading' | 'ok' | 'failed' | 'skipped'
const fullHtml = ref('')
const fullWordCount = ref(0)

async function loadFullArticle(itemId) {
  if (!itemId) return
  if (isVideo.value) {
    fullState.value = 'skipped'
    fullHtml.value = ''
    fullWordCount.value = 0
    return
  }
  fullState.value = 'loading'
  fullHtml.value = ''
  fullWordCount.value = 0
  try {
    const data = await fetchFullArticle(itemId)
    if (data && data.status === 'ok' && data.content_html) {
      fullHtml.value = data.content_html
      fullWordCount.value = Number(data.word_count) || 0
      fullState.value = 'ok'
    } else if (data && data.status === 'skipped') {
      fullState.value = 'skipped'
    } else {
      fullState.value = 'failed'
    }
  } catch (_) {
    fullState.value = 'failed'
  }
}

watch(
  () => props.article?.id,
  (id) => {
    // Reset to reader tab when the article changes — videos don't have an
    // 'original' iframe view, and we don't want a stale tab selection.
    mode.value = 'reader'
    loadFullArticle(id)
  },
  { immediate: true }
)

const displayHtml = computed(() => {
  if (fullState.value === 'ok' && fullHtml.value) {
    return fullHtml.value
  }
  return props.article?.content_html || props.article?.summary || ''
})

const readingMinutes = computed(() => {
  if (!fullWordCount.value) return 0
  return Math.max(1, Math.round(fullWordCount.value / 220))
})

const sourceName = computed(() => {
  try {
    if (!props.article.link) return ''
    const u = new URL(props.article.link)
    return u.hostname.replace(/^www\./, '')
  } catch (_) {
    return ''
  }
})

const dateLabel = computed(() => {
  const raw = props.article.published_at || props.article.created_at
  if (!raw) return ''
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
})
</script>

<template>
  <div class="flex flex-col h-full min-h-0 bg-surface-50 dark:bg-[rgb(var(--color-bg))]">
    <!-- Single compact toolbar: back on the left, tabs + bookmark + close on the right.
         Replaces the previous two stacked bars (back/title/close + centered tabs).
         All controls are kept reachable; the title is shown in the article body
         instead of duplicated in the chrome. -->
    <div class="flex items-center gap-2 px-3 py-1.5 border-b border-surface-200 dark:border-surface-700 shrink-0 bg-white/80 dark:bg-[rgb(var(--color-surface))]/85 backdrop-blur-md">
      <button
        type="button"
        class="inline-flex items-center justify-center w-8 h-8 rounded-full text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
        :aria-label="t('newsReader.back')"
        :title="t('newsReader.back')"
        @click="emit('back')"
      >
        <span class="material-symbols-rounded text-[20px]">arrow_back</span>
      </button>

      <!-- Prev / Next: keyboard-equivalent buttons. Disabled at the
           edges; loading spinner replaces the icon while the store
           fetches the next page after stepping past the cursor. -->
      <div class="flex items-center gap-0.5 ml-1">
        <button
          type="button"
          class="inline-flex items-center justify-center w-8 h-8 rounded-full transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
          :class="hasPrev
            ? 'text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]'
            : 'text-surface-400 dark:text-surface-600'"
          :disabled="!hasPrev || navigating"
          :aria-label="t('newsReader.prevArticle')"
          :title="t('newsReader.prevArticle') + ' (←)'"
          @click="go(-1)"
        >
          <span class="material-symbols-rounded text-[18px]">chevron_left</span>
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center w-8 h-8 rounded-full transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
          :class="hasNext
            ? 'text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]'
            : 'text-surface-400 dark:text-surface-600'"
          :disabled="!hasNext || navigating"
          :aria-label="t('newsReader.nextArticle')"
          :title="t('newsReader.nextArticle') + ' (→)'"
          @click="go(1)"
        >
          <span
            v-if="navigating && loading"
            class="material-symbols-rounded text-[18px] animate-spin"
          >progress_activity</span>
          <span v-else class="material-symbols-rounded text-[18px]">chevron_right</span>
        </button>
      </div>

      <div class="flex-1" />

      <div class="flex items-center gap-1">
        <button
          type="button"
          class="px-3.5 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wider transition-colors"
          :class="mode === 'reader'
            ? 'bg-primary-500 text-white'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-50'"
          @click="mode = 'reader'"
        >{{ isVideo ? t('newsReader.tabWatch') : t('newsReader.tabReader') }}</button>
        <button
          v-if="!isVideo"
          type="button"
          class="px-3.5 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wider transition-colors"
          :class="mode === 'original'
            ? 'bg-primary-500 text-white'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-50'"
          @click="mode = 'original'"
        >{{ t('newsReader.tabOriginal') }}</button>
        <a
          v-else
          :href="youtubeWatchUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="px-3.5 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wider transition-colors text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-50 inline-flex items-center gap-1"
        >
          <span class="material-symbols-rounded text-[14px] leading-none">open_in_new</span>
          {{ t('newsReader.openOnYouTube') }}
        </a>
      </div>

      <button
        type="button"
        class="ml-1 inline-flex items-center justify-center w-8 h-8 rounded-full transition-colors"
        :class="bookmarked
          ? 'text-primary-500 bg-primary-50 dark:bg-primary-900/30 hover:bg-primary-100 dark:hover:bg-primary-900/50'
          : 'text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-50 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]'"
        :aria-label="bookmarked ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
        :title="bookmarked ? t('newsReader.bookmarkRemove') : t('newsReader.bookmarkAdd')"
        @click="toggleBookmark"
      >
        <span
          class="material-symbols-rounded text-[18px]"
          :style="bookmarked ? `font-variation-settings: 'FILL' 1` : ''"
        >bookmark</span>
      </button>

      <button
        type="button"
        class="inline-flex items-center justify-center w-8 h-8 rounded-full text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
        :aria-label="t('newsReader.tickerHide')"
        :title="t('newsReader.tickerHide')"
        @click="emit('close')"
      >
        <span class="material-symbols-rounded text-[20px]">close</span>
      </button>
    </div>

    <div
      v-if="mode === 'reader'"
      class="news-article-scroll flex-1 overflow-y-auto min-h-0 overscroll-y-contain relative"
      style="touch-action: pan-y; overscroll-behavior: contain;"
      @touchstart.passive="onTouchStart"
      @touchmove.passive="onTouchMove"
      @touchend.passive="onTouchEnd"
      @touchcancel.passive="onTouchEnd"
      @wheel="onWheel"
    >
      <article
        class="news-article-body max-w-3xl mx-auto px-5 sm:px-8 py-8 sm:py-12"
        :class="{
          'news-article-leaving-left': swipeDirection === 1,
          'news-article-leaving-right': swipeDirection === -1,
        }"
        :style="!navigating && swipeOffset !== 0
          ? `transform: translateX(${swipeOffset}px); opacity: ${Math.max(0.4, 1 - Math.abs(swipeOffset) / 600)};`
          : ''"
      >
        <div
          v-if="isVideo && youtubeEmbedUrl"
          class="mb-8 -mx-5 sm:-mx-8 sm:rounded-2xl sm:overflow-hidden bg-black aspect-video"
        >
          <iframe
            :src="youtubeEmbedUrl"
            :title="displayTitle"
            class="w-full h-full"
            frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            referrerpolicy="strict-origin-when-cross-origin"
            allowfullscreen
          />
        </div>
        <div v-else-if="article.image_url" class="mb-8 -mx-5 sm:-mx-8 sm:rounded-2xl sm:overflow-hidden">
          <img
            :src="article.image_url"
            alt=""
            class="w-full max-h-[28rem] object-cover"
            referrerpolicy="no-referrer"
          />
        </div>

        <div class="mb-4 flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.2em] text-primary-600 dark:text-primary-400">
          <span>{{ sourceName }}</span>
        </div>

        <h1 class="font-bold leading-[1.1] tracking-tight text-surface-900 dark:text-surface-50 text-3xl sm:text-4xl md:text-5xl mb-5">
          {{ displayTitle }}
        </h1>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-surface-500 dark:text-surface-400 mb-8">
          <span v-if="article.author" class="font-medium">{{ article.author }}</span>
          <span v-if="article.author && dateLabel" aria-hidden="true">·</span>
          <span v-if="dateLabel">{{ dateLabel }}</span>
          <span v-if="(article.author || dateLabel) && readingMinutes" aria-hidden="true">·</span>
          <span v-if="readingMinutes">{{ t('newsReader.readingTime', { n: readingMinutes }) }}</span>
        </div>

        <div
          v-if="displayHtml"
          class="news-html prose prose-stone dark:prose-invert prose-lg lg:prose-xl max-w-none
                 prose-headings:tracking-tight prose-headings:font-bold
                 prose-h2:mt-10 prose-h2:mb-4
                 prose-h3:mt-8 prose-h3:mb-3
                 prose-p:leading-[1.75]
                 prose-a:text-primary-600 dark:prose-a:text-primary-400 prose-a:no-underline hover:prose-a:underline
                 prose-img:rounded-2xl prose-img:w-full prose-img:my-8
                 prose-figure:my-8
                 prose-figcaption:text-center prose-figcaption:italic
                 prose-blockquote:border-l-4 prose-blockquote:border-primary-500/70 prose-blockquote:not-italic prose-blockquote:font-medium prose-blockquote:text-surface-700 dark:prose-blockquote:text-surface-200
                 prose-ul:my-4 prose-ol:my-4 prose-li:my-1
                 prose-strong:text-surface-900 dark:prose-strong:text-surface-50"
          v-html="displayHtml"
        />
        <p
          v-else-if="article.content_text"
          class="text-lg leading-relaxed text-surface-700 dark:text-surface-300"
        >{{ article.content_text }}</p>

        <div
          v-if="!isVideo && fullState === 'loading'"
          class="mt-6 flex items-center gap-2 text-sm text-surface-500 dark:text-surface-400"
        >
          <span class="material-symbols-rounded text-base animate-spin">progress_activity</span>
          <span>{{ t('newsReader.loadingFull') }}</span>
        </div>
        <div
          v-else-if="!isVideo && fullState === 'failed' && article.link"
          class="mt-8 p-4 rounded-xl border border-surface-200 dark:border-surface-700/60 bg-surface-100/60 dark:bg-[rgb(var(--color-surface))]/60 text-sm text-surface-600 dark:text-surface-300 flex flex-col sm:flex-row sm:items-center gap-3"
        >
          <span class="material-symbols-rounded text-xl text-surface-400 dark:text-surface-500">info</span>
          <div class="flex-1">
            <p class="font-medium text-surface-700 dark:text-surface-200">{{ t('newsReader.fullUnavailableTitle') }}</p>
            <p class="text-xs mt-0.5 opacity-80">{{ t('newsReader.fullUnavailableHint') }}</p>
          </div>
          <a
            :href="article.link"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-primary-500 text-white font-semibold text-xs hover:bg-primary-600 transition-colors shrink-0"
          >
            <span class="material-symbols-rounded text-[14px]">open_in_new</span>
            {{ t('newsReader.openInNewTab') }}
          </a>
        </div>
      </article>
    </div>
    <div v-else class="flex-1 min-h-0 p-2 sm:p-4">
      <ArticleFrame :src="article.link" />
    </div>
  </div>
</template>

<style scoped>
/* Article body follows the user's finger while swiping (live transform
   set via :style), then snaps with a quick eased animation when a
   navigation commit happens. The leaving-left/right keyframes match the
   swipe direction so the next article appears to enter from the
   appropriate edge. */
.news-article-body {
  transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1),
              opacity 220ms cubic-bezier(0.22, 1, 0.36, 1);
  will-change: transform, opacity;
}
.news-article-leaving-left {
  animation: news-article-leave-left 220ms cubic-bezier(0.4, 0, 1, 1) forwards;
}
.news-article-leaving-right {
  animation: news-article-leave-right 220ms cubic-bezier(0.4, 0, 1, 1) forwards;
}
@keyframes news-article-leave-left {
  to { transform: translateX(-30vw); opacity: 0; }
}
@keyframes news-article-leave-right {
  to { transform: translateX(30vw); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .news-article-body { transition: none; }
  .news-article-leaving-left,
  .news-article-leaving-right { animation: none; }
}

/* News article body — extra polish on top of @tailwindcss/typography
   so the reader feels like a real magazine page, not a flat dump.
   Tweaks are targeted at the extracted HTML coming from
   ArticleExtractorService (publisher article bodies). */

/* Images: keep them properly sized, give them a touch of bleed on
   wide screens so the layout breathes. */
.news-html :deep(img),
.news-html :deep(picture > img) {
  max-width: 100%;
  height: auto;
  display: block;
  margin-left: auto;
  margin-right: auto;
}

/* Author headshots / byline avatars are tagged with this class by the
   server-side extractor (ArticleExtractorService::looksLikeAuthorAvatar).
   They get the inline-avatar treatment so they don't take over the
   layout as a hero image. Overrides the wide-screen bleed below by
   resetting margins and setting a fixed pixel size. */
.news-html :deep(img.news-author-avatar) {
  display: inline-block !important;
  width: 56px !important;
  max-width: 56px !important;
  height: 56px !important;
  min-height: 0 !important;
  margin: 0 0.5rem 0 0 !important;
  padding: 0 !important;
  border-radius: 9999px !important;
  object-fit: cover !important;
  vertical-align: middle !important;
  /* Cancel any figure/breakout sizing inherited from the wide-screen
     rules below. */
  float: none !important;
}
/* If the avatar is wrapped in a `<p>` (publishers often do this for
   bylines like "By <a><img>Aimee Hart</a>"), keep the paragraph from
   stretching to image-hero proportions. */
.news-html :deep(p:has(> img.news-author-avatar)),
.news-html :deep(p:has(> a > img.news-author-avatar)) {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.95em;
}

/* Figures (image + caption). Break out slightly on large screens. */
.news-html :deep(figure) {
  margin: 2rem 0;
}
@media (min-width: 1024px) {
  .news-html :deep(figure),
  .news-html :deep(figure > img),
  .news-html :deep(p > img) {
    /* Subtle bleed effect so hero-style images feel intentional
       without breaking the centred text column. */
    margin-left: -2rem;
    margin-right: -2rem;
    width: calc(100% + 4rem);
    max-width: calc(100% + 4rem);
  }
  .news-html :deep(figcaption) {
    margin-left: 2rem;
    margin-right: 2rem;
  }
}

.news-html :deep(figcaption) {
  font-size: 0.875rem;
  color: rgb(120 113 108);
  text-align: center;
  margin-top: 0.75rem;
  line-height: 1.5;
}
:global(.dark) .news-html :deep(figcaption) {
  color: rgb(168 162 158);
}

/* Lead paragraph: bigger, slightly looser, sets the tone of the
   article — same trick most newspaper sites use. We only target the
   FIRST paragraph that's a direct child of the prose container so we
   don't accidentally style every paragraph after a heading. */
.news-html :deep(> p:first-of-type) {
  font-size: 1.25em;
  line-height: 1.7;
  color: rgb(63 63 70);
  margin-bottom: 1.5em;
}
:global(.dark) .news-html :deep(> p:first-of-type) {
  color: rgb(228 228 231);
}

/* Pull-quote style for blockquotes that aren't inline. */
.news-html :deep(blockquote) {
  font-size: 1.15em;
  line-height: 1.6;
  padding: 0.5rem 0 0.5rem 1.5rem;
  margin: 2rem 0;
}
.news-html :deep(blockquote p) {
  margin: 0.25em 0;
}

/* Headings — restore a clear visual hierarchy on top of prose. */
.news-html :deep(h2) {
  font-size: 1.6em;
  line-height: 1.25;
  border-top: 1px solid rgb(228 228 231);
  padding-top: 1.5rem;
}
:global(.dark) .news-html :deep(h2) {
  border-top-color: rgb(63 63 70 / 0.6);
}
.news-html :deep(h3) {
  font-size: 1.3em;
  line-height: 1.3;
}

/* Sometimes extractors emit consecutive <br>s as paragraph breaks.
   Collapse runs of more than one to a sensible spacer. */
.news-html :deep(br + br) {
  display: block;
  content: "";
  margin-top: 0.75em;
}

/* Lists: tighter rhythm than prose default so feature lists don't
   feel stretched. */
.news-html :deep(ul),
.news-html :deep(ol) {
  padding-left: 1.5em;
}
.news-html :deep(ul) {
  list-style: disc;
}
.news-html :deep(ol) {
  list-style: decimal;
}

/* Inline code + code blocks: subtle pill styling. */
.news-html :deep(code) {
  background: rgb(244 244 245);
  padding: 0.1em 0.4em;
  border-radius: 0.375rem;
  font-size: 0.9em;
}
:global(.dark) .news-html :deep(code) {
  background: rgb(63 63 70 / 0.6);
}
.news-html :deep(pre) {
  background: rgb(24 24 27);
  color: rgb(244 244 245);
  padding: 1rem 1.25rem;
  border-radius: 0.75rem;
  overflow-x: auto;
  margin: 1.5rem 0;
}
.news-html :deep(pre code) {
  background: transparent;
  padding: 0;
  color: inherit;
}
</style>
