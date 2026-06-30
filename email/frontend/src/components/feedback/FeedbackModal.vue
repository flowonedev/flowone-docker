<script setup>
import { ref, watch, computed, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useFeedbackStore } from '@/stores/feedback'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/shared/Modal.vue'
import ScreenshotAnnotator from './ScreenshotAnnotator.vue'

const { t } = useI18n()
const route = useRoute()
const feedbackStore = useFeedbackStore()
const toast = useToastStore()

const VIEW_KEYS = [
  { route: 'mailbox',            i18n: 'feedback.viewMailbox' },
  { route: 'mailbox-folder',     i18n: 'feedback.viewMailboxFolder' },
  { route: 'mailbox-email',      i18n: 'feedback.viewMailboxEmail' },
  { route: 'settings',           i18n: 'feedback.viewSettings' },
  { route: 'drive',              i18n: 'feedback.viewDrive' },
  { route: 'drive-folder',       i18n: 'feedback.viewDriveFolder' },
  { route: 'drive-document',     i18n: 'feedback.viewDriveDocument' },
  { route: 'drive-presentation', i18n: 'feedback.viewDrivePresentation' },
  { route: 'calendar',           i18n: 'feedback.viewCalendar' },
  { route: 'boards',             i18n: 'feedback.viewBoards' },
  { route: 'board',              i18n: 'feedback.viewBoard' },
  { route: 'mood',               i18n: 'feedback.viewMood' },
  { route: 'mood-board',         i18n: 'feedback.viewMoodBoard' },
  { route: 'clients',            i18n: 'feedback.viewClients' },
  { route: 'clients-overview',   i18n: 'feedback.viewClientsOverview' },
  { route: 'client',             i18n: 'feedback.viewClient' },
  { route: 'financials',         i18n: 'feedback.viewFinancials' },
  { route: 'time',               i18n: 'feedback.viewTime' },
  { route: 'team',               i18n: 'feedback.viewTeam' },
  { route: 'chat',               i18n: 'feedback.viewChat' },
  { route: 'mailing-lists',      i18n: 'feedback.viewMailingLists' },
  { route: 'campaigns',          i18n: 'feedback.viewCampaigns' },
  { route: 'crm-executive',       i18n: 'feedback.viewCrmExecutive' },
  { route: 'crm-pipeline',       i18n: 'feedback.viewCrmPipeline' },
  { route: 'crm-deal',           i18n: 'feedback.viewCrmDeal' },
  { route: 'crm-invoices',       i18n: 'feedback.viewCrmInvoices' },
  { route: 'crm-invoice',        i18n: 'feedback.viewCrmInvoice' },
  { route: 'crm-dashboard',      i18n: 'feedback.viewCrmDashboard' },
  { route: 'crm-automation',     i18n: 'feedback.viewCrmAutomation' },
  { route: 'crm-sequences',      i18n: 'feedback.viewCrmSequences' },
  { route: 'crm-sharing',        i18n: 'feedback.viewCrmSharing' },
]

const CATEGORIES = [
  { id: 'design',        i18n: 'feedback.categoryDesign',       icon: 'palette' },
  { id: 'error',         i18n: 'feedback.categoryError',        icon: 'bug_report' },
  { id: 'broken_button', i18n: 'feedback.categoryBrokenButton', icon: 'touch_app' },
  { id: 'cant_find',     i18n: 'feedback.categoryCantFind',     icon: 'search_off' },
  { id: 'not_working',   i18n: 'feedback.categoryNotWorking',   icon: 'error_outline' },
  { id: 'feature',       i18n: 'feedback.categoryFeature',      icon: 'lightbulb' },
]

const selectedView = ref('')
const selectedCategory = ref('')
const description = ref('')
const capturing = ref(false)
const annotatorOpen = ref(false)

const canSubmit = computed(() => selectedView.value && selectedCategory.value && description.value.trim().length > 0)

watch(() => feedbackStore.isOpen, (open) => {
  if (open) {
    selectedView.value = feedbackStore.currentView || route.name || ''
    selectedCategory.value = ''
    description.value = ''
    annotatorOpen.value = false
  }
})

async function captureScreenshot() {
  capturing.value = true

  try {
    const overlays = document.querySelectorAll('.modal-overlay')
    overlays.forEach(el => el.style.visibility = 'hidden')
    await nextTick()
    await new Promise(resolve => setTimeout(resolve, 150))

    let dataUrl = null

    // Primary: native Screen Capture API (pixel-perfect)
    if (navigator.mediaDevices?.getDisplayMedia) {
      try {
        const stream = await navigator.mediaDevices.getDisplayMedia({
          video: { displaySurface: 'browser' },
          preferCurrentTab: true
        })
        const track = stream.getVideoTracks()[0]

        const video = document.createElement('video')
        video.srcObject = stream
        video.muted = true
        video.playsInline = true
        await video.play()

        // Wait until the video has actual frame dimensions
        await new Promise((resolve) => {
          const check = () => {
            if (video.videoWidth > 0 && video.videoHeight > 0) return resolve()
            requestAnimationFrame(check)
          }
          check()
        })
        // Extra frame to ensure the capture isn't the share-dialog
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)))

        const canvas = document.createElement('canvas')
        canvas.width = video.videoWidth
        canvas.height = video.videoHeight
        canvas.getContext('2d').drawImage(video, 0, 0)
        dataUrl = canvas.toDataURL('image/png', 0.9)

        track.stop()
        stream.getTracks().forEach(t => t.stop())
      } catch {
        // User denied or API not available -- fall through to html2canvas
      }
    }

    // Fallback: html2canvas
    if (!dataUrl) {
      const html2canvas = (await import('html2canvas')).default
      const canvas = await html2canvas(document.body, {
        useCORS: true,
        allowTaint: true,
        scale: 1,
        logging: false,
        backgroundColor: null,
        windowWidth: document.documentElement.clientWidth,
        windowHeight: document.documentElement.clientHeight,
        onclone(clonedDoc) {
          clonedDoc.querySelectorAll('.modal-overlay, [class*="backdrop-blur"]').forEach(el => {
            el.style.display = 'none'
          })
          clonedDoc.querySelectorAll('*').forEach(el => {
            const cs = window.getComputedStyle(el)
            if (cs.backdropFilter !== 'none') el.style.backdropFilter = 'none'
            if (cs.webkitBackdropFilter !== 'none') el.style.webkitBackdropFilter = 'none'
            if (cs.contain !== 'none') el.style.contain = 'none'
          })
        }
      })
      dataUrl = canvas.toDataURL('image/png', 0.85)
    }

    overlays.forEach(el => el.style.visibility = '')
    feedbackStore.setScreenshot(dataUrl)
  } catch (e) {
    console.error('Screenshot capture failed:', e)
    document.querySelectorAll('.modal-overlay').forEach(el => el.style.visibility = '')
    toast.error('Screenshot capture failed')
  } finally {
    capturing.value = false
  }
}

function openAnnotator() {
  annotatorOpen.value = true
}

function onAnnotatorSave(dataUrl) {
  feedbackStore.setScreenshot(dataUrl)
  annotatorOpen.value = false
}

async function submit() {
  if (!canSubmit.value || feedbackStore.sending) return

  const viewEntry = VIEW_KEYS.find(v => v.route === selectedView.value)
  const catEntry = CATEGORIES.find(c => c.id === selectedCategory.value)

  const success = await feedbackStore.submit({
    view: selectedView.value,
    view_label: viewEntry ? t(viewEntry.i18n) : selectedView.value,
    category: selectedCategory.value,
    category_label: catEntry ? t(catEntry.i18n) : selectedCategory.value,
    description: description.value.trim(),
  })

  if (success) {
    toast.success(t('feedback.successMessage'))
  } else {
    toast.error(t('feedback.errorMessage'))
  }
}
</script>

<template>
  <Modal :show="feedbackStore.isOpen" size="lg" @close="feedbackStore.close" :mobileFullscreen="true">
    <template #header>
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-xl text-primary-600 dark:text-primary-400">bug_report</span>
        <h3 class="text-lg font-semibold">{{ $t('feedback.title') }}</h3>
      </div>
    </template>

    <div class="p-4 space-y-4">

      <!-- View / Page selector -->
      <div>
        <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">
          {{ $t('feedback.pageView') }}
        </label>
        <div class="relative">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-base text-surface-400 pointer-events-none">
            web
          </span>
          <select
            v-model="selectedView"
            class="input pl-10 appearance-none cursor-pointer"
          >
            <option value="" disabled>{{ $t('feedback.selectPage') }}</option>
            <option v-for="v in VIEW_KEYS" :key="v.route" :value="v.route">{{ $t(v.i18n) }}</option>
          </select>
          <span class="material-symbols-rounded absolute right-3 top-1/2 -translate-y-1/2 text-base text-surface-400 pointer-events-none">
            expand_more
          </span>
        </div>
      </div>

      <!-- Category selector -->
      <div>
        <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1.5">
          {{ $t('feedback.category') }}
        </label>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="cat in CATEGORIES"
            :key="cat.id"
            @click="selectedCategory = cat.id"
            :class="[
              'flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium transition-colors text-left',
              selectedCategory === cat.id
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-surface-300 dark:hover:border-surface-500'
            ]"
          >
            <span class="material-symbols-rounded text-base flex-shrink-0">{{ cat.icon }}</span>
            <span class="truncate">{{ $t(cat.i18n) }}</span>
          </button>
        </div>
      </div>

      <!-- Description -->
      <div>
        <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">
          {{ $t('feedback.description') }}
        </label>
        <textarea
          v-model="description"
          rows="4"
          class="input resize-none"
          :placeholder="$t('feedback.descriptionPlaceholder')"
        ></textarea>
      </div>

      <!-- Screenshot -->
      <div>
        <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-0.5">
          {{ $t('feedback.screenshot') }}
        </label>
        <p v-if="feedbackStore.screenshot && !capturing" class="text-[11px] text-surface-400 dark:text-surface-500 mb-1.5">
          Click the image to draw arrows, highlight areas, and annotate issues.
        </p>

        <!-- No screenshot yet -->
        <div v-if="!feedbackStore.screenshot && !capturing">
          <button
            @click="captureScreenshot"
            class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-dashed
                   border-surface-300 dark:border-surface-600
                   bg-surface-100 dark:bg-surface-700/50
                   text-sm text-surface-500 dark:text-surface-400
                   hover:border-primary-400 hover:text-primary-600 dark:hover:border-primary-500 dark:hover:text-primary-400
                   transition-colors w-full justify-center"
          >
            <span class="material-symbols-rounded text-lg">screenshot_monitor</span>
            {{ $t('feedback.takeScreenshot') }}
          </button>
        </div>

        <!-- Capturing... -->
        <div v-else-if="capturing" class="flex items-center justify-center gap-2 py-4 text-sm text-surface-400">
          <span class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
          {{ $t('feedback.capturingScreenshot') }}
        </div>

        <!-- Preview -->
        <div v-else class="space-y-2">
          <div
            class="relative group rounded-xl overflow-hidden border border-surface-200 dark:border-surface-700 cursor-pointer"
            @click="openAnnotator"
          >
            <img
              :src="feedbackStore.screenshot"
              alt="Screenshot"
              class="w-full h-auto max-h-48 object-cover object-top"
            />
            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-colors flex items-center justify-center">
              <span class="material-symbols-rounded text-white text-2xl opacity-0 group-hover:opacity-100 transition-opacity">
                draw
              </span>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="openAnnotator"
              class="btn-ghost btn-sm flex items-center gap-1.5"
            >
              <span class="material-symbols-rounded text-sm">draw</span>
              {{ $t('feedback.annotateScreenshot') || 'Annotate' }}
            </button>
            <button
              @click="captureScreenshot"
              class="btn-ghost btn-sm flex items-center gap-1.5"
            >
              <span class="material-symbols-rounded text-sm">refresh</span>
              {{ $t('feedback.retakeScreenshot') }}
            </button>
            <button
              @click="feedbackStore.clearScreenshot()"
              class="btn-ghost btn-sm flex items-center gap-1.5 text-red-500 hover:text-red-600"
            >
              <span class="material-symbols-rounded text-sm">delete</span>
              {{ $t('feedback.removeScreenshot') }}
            </button>
          </div>
        </div>
      </div>

    </div>

    <template #footer>
      <div class="flex items-center justify-between w-full">
        <p class="text-xs text-surface-400">
          {{ $t('feedback.footerNote') }}
        </p>
        <div class="flex items-center gap-2">
          <button
            @click="feedbackStore.close"
            class="btn-ghost text-sm"
          >
            {{ $t('feedback.cancel') }}
          </button>
          <button
            @click="submit"
            :disabled="!canSubmit || feedbackStore.sending"
            class="btn-primary text-sm flex items-center gap-1.5"
          >
            <span v-if="feedbackStore.sending" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
            <span v-else class="material-symbols-rounded text-base">send</span>
            {{ feedbackStore.sending ? $t('feedback.sending') : $t('feedback.send') }}
          </button>
        </div>
      </div>
    </template>
  </Modal>

  <!-- Screenshot annotation editor -->
  <ScreenshotAnnotator
    v-if="annotatorOpen && feedbackStore.screenshot"
    :screenshot="feedbackStore.screenshot"
    @save="onAnnotatorSave"
    @cancel="annotatorOpen = false"
  />
</template>
