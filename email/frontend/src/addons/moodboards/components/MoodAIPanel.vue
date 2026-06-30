<template>
  <transition name="ai-slide">
    <div
      v-if="open"
      class="absolute bottom-20 right-4 z-[10001] w-[420px] max-h-[520px] flex flex-col bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-2xl shadow-2xl overflow-hidden"
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 bg-surface-100/50 dark:bg-surface-800/50">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-500">auto_awesome</span>
          <span class="text-sm font-semibold text-surface-800 dark:text-surface-200">AI Assistant</span>
        </div>
        <div class="flex items-center gap-1">
          <button
            v-if="messages.length"
            @click="messages = []"
            class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
            title="New chat"
          >
            <span class="material-symbols-rounded text-lg">refresh</span>
          </button>
          <button
            @click="$emit('close')"
            class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-500 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">close</span>
          </button>
        </div>
      </div>

      <!-- Messages area -->
      <div ref="messagesRef" class="flex-1 overflow-y-auto p-4 space-y-3 min-h-[200px]">
        <!-- Welcome — all buttons always visible -->
        <div v-if="!messages.length" class="py-2 space-y-2.5">
          <!-- Selection indicator -->
          <div v-if="hasSelection" class="flex items-center gap-1.5 px-1">
            <span class="material-symbols-rounded text-sm text-primary-500">select_all</span>
            <span class="text-[10px] font-semibold text-primary-500">{{ store.selectedItemIds.size }} item{{ store.selectedItemIds.size > 1 ? 's' : '' }} selected</span>
          </div>

          <!-- Generate layouts -->
          <div>
            <p class="text-[9px] text-surface-400 uppercase tracking-wider font-medium px-1 mb-1.5">
              <span class="material-symbols-rounded text-[10px] align-[-1px] mr-0.5">auto_awesome</span>
              Generate layout
            </p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="example in quickExamples"
                :key="example.label"
                @click="prompt = example.text"
                class="px-2.5 py-1 text-[11px] rounded-full border border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-primary-400 hover:text-primary-500 hover:bg-primary-500/5 transition-colors text-left"
              >
                {{ example.label }}
              </button>
            </div>
          </div>

          <!-- Color variations (requires selection) -->
          <div>
            <p class="text-[9px] text-surface-400 uppercase tracking-wider font-medium px-1 mb-1.5">
              <span class="material-symbols-rounded text-[10px] align-[-1px] mr-0.5">palette</span>
              Color variations
              <span v-if="!hasSelection" class="text-surface-300 dark:text-surface-600 normal-case tracking-normal">(select items first)</span>
            </p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="v in variationExamples"
                :key="v.label"
                @click="sendVariation(v.count, v.text)"
                :disabled="!hasSelection || store.aiGenerating"
                class="px-2.5 py-1 text-[11px] rounded-full border transition-colors text-left disabled:opacity-30 disabled:cursor-not-allowed"
                :class="hasSelection
                  ? 'border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-400 hover:border-amber-400 hover:bg-amber-500/10'
                  : 'border-surface-200 dark:border-surface-700 text-surface-400 dark:text-surface-500'"
              >
                {{ v.label }}
              </button>
            </div>
          </div>

          <!-- Modify selected (requires selection) -->
          <div>
            <p class="text-[9px] text-surface-400 uppercase tracking-wider font-medium px-1 mb-1.5">
              <span class="material-symbols-rounded text-[10px] align-[-1px] mr-0.5">edit_note</span>
              Modify selected
              <span v-if="!hasSelection" class="text-surface-300 dark:text-surface-600 normal-case tracking-normal">(select items first)</span>
            </p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="example in modifyExamples"
                :key="example.label"
                @click="prompt = example.text"
                :disabled="!hasSelection"
                class="px-2.5 py-1 text-[11px] rounded-full border transition-colors text-left disabled:opacity-30 disabled:cursor-not-allowed"
                :class="hasSelection
                  ? 'border-primary-200 dark:border-primary-700 text-primary-600 dark:text-primary-400 hover:border-primary-400 hover:bg-primary-500/5'
                  : 'border-surface-200 dark:border-surface-700 text-surface-400 dark:text-surface-500'"
              >
                {{ example.label }}
              </button>
            </div>
          </div>
        </div>

        <!-- Message history -->
        <div
          v-for="(msg, i) in messages"
          :key="i"
          class="flex gap-2"
          :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
        >
          <div
            class="max-w-[85%] px-3 py-2 rounded-xl text-sm leading-relaxed"
            :class="msg.role === 'user'
              ? 'bg-primary-500 text-white rounded-br-sm'
              : msg.error
                ? 'bg-red-500/10 text-red-400 border border-red-500/20 rounded-bl-sm'
                : 'bg-surface-100 dark:bg-surface-800 text-surface-700 dark:text-surface-300 rounded-bl-sm'"
          >
            <img v-if="msg.image" :src="msg.image" class="h-12 rounded mb-1.5 object-cover" />
            {{ msg.text }}
            <div v-if="msg.count" class="mt-1 text-[11px] opacity-70">
              {{ msg.count }} items {{ msg.modified ? 'modified' : 'added to canvas' }}
            </div>
          </div>
        </div>

        <!-- Loading indicator -->
        <div v-if="store.aiGenerating" class="flex items-center gap-2 px-3 py-2">
          <div class="flex gap-1">
            <span class="w-1.5 h-1.5 rounded-full bg-primary-400 animate-bounce" style="animation-delay: 0ms" />
            <span class="w-1.5 h-1.5 rounded-full bg-primary-400 animate-bounce" style="animation-delay: 150ms" />
            <span class="w-1.5 h-1.5 rounded-full bg-primary-400 animate-bounce" style="animation-delay: 300ms" />
          </div>
          <span class="text-xs text-surface-400">Working on it...</span>
        </div>
      </div>

      <!-- Input area -->
      <div class="border-t border-surface-200 dark:border-surface-700 p-3 space-y-2">
        <!-- Attached image preview -->
        <div v-if="attachedImage" class="relative inline-block">
          <img
            :src="attachedImageUrl"
            class="h-16 rounded-lg border border-surface-200 dark:border-surface-600 object-cover"
          />
          <button
            @click="removeImage"
            class="absolute -top-1.5 -right-1.5 w-5 h-5 flex items-center justify-center rounded-full bg-surface-700 text-white hover:bg-red-500 transition-colors shadow"
          >
            <span class="material-symbols-rounded text-xs">close</span>
          </button>
        </div>

        <div class="flex gap-2">
          <div class="flex-1 flex flex-col gap-1">
            <textarea
              ref="inputRef"
              v-model="prompt"
              @keydown.enter.exact.prevent="send"
              @paste="onPaste"
              :disabled="store.aiGenerating"
              :placeholder="hasSelection ? 'Modify selected items or describe a new layout...' : 'Describe your layout...'"
              rows="2"
              class="w-full px-3 py-2 text-sm bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-xl resize-none outline-none focus:border-primary-400 text-surface-800 dark:text-surface-200 placeholder:text-surface-400 disabled:opacity-50 transition-colors"
            />
          </div>
          <div class="flex flex-col justify-end gap-1">
            <button
              @click="triggerImagePicker"
              :disabled="store.aiGenerating"
              class="w-9 h-9 flex items-center justify-center rounded-xl border border-surface-200 dark:border-surface-600 hover:border-primary-400 hover:bg-primary-500/5 text-surface-400 hover:text-primary-500 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex-shrink-0"
              title="Attach reference image"
            >
              <span class="material-symbols-rounded text-lg">image</span>
            </button>
            <button
              @click="send"
              :disabled="!prompt.trim() || store.aiGenerating"
              class="w-9 h-9 flex items-center justify-center rounded-xl bg-primary-500 hover:bg-primary-600 text-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex-shrink-0"
            >
              <span class="material-symbols-rounded text-lg">send</span>
            </button>
          </div>
        </div>
        <input
          ref="fileInputRef"
          type="file"
          accept="image/png,image/jpeg,image/gif,image/webp"
          class="hidden"
          @change="onFileSelected"
        />
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, nextTick, watch, computed } from 'vue'
import { useMoodBoardsStore } from '../stores/moodBoards'

defineProps({ open: Boolean })
defineEmits(['close'])

const store = useMoodBoardsStore()

const prompt = ref('')
const messages = ref([])
const messagesRef = ref(null)
const inputRef = ref(null)
const fileInputRef = ref(null)

const attachedImage = ref(null)
const attachedImageUrl = ref(null)

const hasSelection = computed(() => store.selectedItemIds.size > 0)

const quickExamples = [
  { label: '4 Feature cards', text: 'Create 4 dark feature cards in a row. Each card has: a Material Symbol icon (use mail, chat, folder, calendar_month), a bold white title, and a short gray description. Use green borders and icon colors.' },
  { label: 'Hero section', text: 'Create a hero section: small gray subtitle text, then a large bold title with the key word in green gradient, then a row of 3 small trust badges with check_circle icons below.' },
  { label: 'Problems + Solutions', text: 'Create a "Problems" header in red, then 4 red-themed cards with warning icons and pain points. Below that, a "Solutions" header in green, then 4 green-themed cards with check_circle icons and benefits.' },
  { label: 'Stats row', text: 'Create a row of 4 stat metric cards. Each has a large colored number (like 1,247 or 89%), a small gray label below, and a small green trend indicator (+12%). Dark card backgrounds.' },
  { label: 'Pricing card', text: 'Create one large card (600px wide) with a green border. Inside: a title, subtitle paragraph, a section labeled "INCLUDED" with 4 feature rows (each with a green check_circle icon and text), and a green CTA button shape at the bottom.' },
  { label: 'Workflow steps', text: 'Create 4 cards in a horizontal row showing a numbered workflow: 1) Email arrives (mail icon) 2) Create task (task_alt icon) 3) Track progress (trending_up icon) 4) Send report (send icon). Each card has a colored number circle, title, and subtitle.' },
  { label: 'Team profiles', text: 'Create 4 team member cards in a row. Each has a colored circle with initials, a bold name, a role in gray, and a short bio. Dark cards with purple accents.' },
  { label: 'Comparison table', text: 'Create a comparison layout: "Before" header in red with 3 red-themed problem cards below, then "After" header in green with 3 green-themed benefit cards below.' },
  { label: 'Feature list', text: 'Create a vertical feature list: a bold title at the top, then 6 rows stacked vertically. Each row has a green check_circle icon on the left and a feature description on the right. Dark background.' },
  { label: 'App showcase', text: 'Create 8 cards (180px, 4 per row) with red border 2px, rounded corners 10px, pale red background, big centered icon, centered title + "replaces: App" subtitle. Cards: task_alt Tasks Todoist, chat Chat Slack, cloud Storage Dropbox, calendar_month Calendar Google, dashboard Boards Trello, handshake CRM HubSpot, edit_document Docs Google, receipt_long Invoices FreshBooks.' },
  { label: 'CTA section', text: 'Create a call-to-action section: a large bold gradient title (green), a subtitle paragraph in gray, and a wide green rounded button shape with bold white "Get Started" text centered inside.' },
  { label: 'Testimonial cards', text: 'Create 3 testimonial/quote cards in a row. Each has a large quote mark icon (format_quote), italic quote text in white, and a bold author name with gray company below. Dark cards, blue accents.' },
]

const modifyExamples = [
  { label: 'Change color to blue', text: 'Change all colors (backgrounds, borders, icons, text accents) to a blue theme.' },
  { label: 'Make 20% smaller', text: 'Make all items 20% smaller and keep the layout together.' },
  { label: 'Make 20% bigger', text: 'Make all items 20% bigger and keep the layout together.' },
  { label: 'Dark theme', text: 'Change to dark theme: dark backgrounds (#1a1a2e), light text (#e2e8f0), keep accent colors.' },
  { label: 'Light theme', text: 'Change to light theme: white backgrounds (#ffffff), dark text (#1e293b), subtle gray borders.' },
  { label: 'Add borders', text: 'Add a 2px border to all shapes with a matching accent color.' },
  { label: 'Remove borders', text: 'Remove all borders (set shape_border_width to 0).' },
  { label: 'Round corners', text: 'Set all shape corners to 16px rounded.' },
  { label: 'Increase font size', text: 'Increase all font sizes by 4px.' },
  { label: 'Space evenly', text: 'Space all items evenly with 20px gaps between them, keeping the same row structure.' },
  { label: 'Green accent', text: 'Change all accent colors (borders, icons) to green (#22c55e).' },
  { label: 'Red accent', text: 'Change all accent colors (borders, icons) to red (#ef4444).' },
  { label: 'Translate to Hungarian', text: 'Translate all text content to Hungarian. Keep icon names unchanged (they are Material Symbol names, not translatable). Only translate human-readable text.' },
  { label: 'Translate to English', text: 'Translate all text content to English. Keep icon names unchanged (they are Material Symbol names, not translatable). Only translate human-readable text.' },
]

const variationExamples = [
  { label: '3 color variations', count: 3, text: '' },
  { label: '5 color variations', count: 5, text: '' },
  { label: '3 warm variations', count: 3, text: 'Use warm color palettes: reds, oranges, ambers, pinks.' },
  { label: '3 cool variations', count: 3, text: 'Use cool color palettes: blues, teals, cyans, greens.' },
  { label: '3 pastel variations', count: 3, text: 'Use soft pastel color palettes with light backgrounds and muted accents.' },
  { label: '3 neon variations', count: 3, text: 'Use vibrant neon color palettes on dark backgrounds.' },
]

function triggerImagePicker() {
  fileInputRef.value?.click()
}

function onFileSelected(e) {
  const file = e.target.files?.[0]
  if (file) processImageFile(file)
  if (fileInputRef.value) fileInputRef.value.value = ''
}

function onPaste(e) {
  const items = e.clipboardData?.items
  if (!items) return
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      e.preventDefault()
      processImageFile(item.getAsFile())
      return
    }
  }
}

const MAX_IMAGE_DIM = 1024

function processImageFile(file) {
  if (!file || !file.type.startsWith('image/')) return

  const reader = new FileReader()
  reader.onload = () => {
    const img = new Image()
    img.onload = () => {
      const canvas = document.createElement('canvas')
      let w = img.width
      let h = img.height
      if (w > MAX_IMAGE_DIM || h > MAX_IMAGE_DIM) {
        const ratio = Math.min(MAX_IMAGE_DIM / w, MAX_IMAGE_DIM / h)
        w = Math.round(w * ratio)
        h = Math.round(h * ratio)
      }
      canvas.width = w
      canvas.height = h
      const ctx = canvas.getContext('2d')
      ctx.drawImage(img, 0, 0, w, h)
      const dataUrl = canvas.toDataURL('image/jpeg', 0.8)
      attachedImageUrl.value = dataUrl
      attachedImage.value = dataUrl.split(',')[1]
    }
    img.src = reader.result
  }
  reader.readAsDataURL(file)
}

function removeImage() {
  attachedImage.value = null
  attachedImageUrl.value = null
}

async function send() {
  const text = prompt.value.trim()
  if (!text || store.aiGenerating) return

  const isModify = hasSelection.value
  const selectedItems = isModify ? [...store.selectedItems] : null
  const imageBase64 = attachedImage.value

  const userLabel = [
    isModify ? `[${store.selectedItemIds.size} items]` : null,
    imageBase64 ? '[image]' : null,
    text,
  ].filter(Boolean).join(' ')

  messages.value.push({ role: 'user', text: userLabel, image: attachedImageUrl.value })
  prompt.value = ''
  removeImage()
  scrollToBottom()

  const opts = imageBase64 ? { referenceImage: imageBase64 } : undefined
  let result
  if (isModify) {
    result = await store.aiModify(text, selectedItems, opts)
  } else {
    result = await store.aiGenerate(text, opts)
  }

  if (result?.success) {
    messages.value.push({
      role: 'assistant',
      text: isModify ? 'Done! Selected items have been updated.' : 'Done! Elements have been placed on your canvas.',
      count: result.items?.length || 0,
      modified: isModify,
    })
  } else {
    messages.value.push({
      role: 'assistant',
      text: result?.error || 'Something went wrong. Make sure your OpenAI API key is configured in Settings.',
      error: true,
    })
  }
  scrollToBottom()
}

async function sendVariation(count, text) {
  if (store.aiGenerating || !hasSelection.value) return

  const selectedItems = [...store.selectedItems]
  messages.value.push({
    role: 'user',
    text: `[${selectedItems.length} items] ${count} color variations${text ? ': ' + text : ''}`,
  })
  scrollToBottom()

  const result = await store.aiVariations(text, selectedItems, count)

  if (result?.success) {
    messages.value.push({
      role: 'assistant',
      text: `Done! ${result.variationCount} color variations placed next to the original.`,
      count: result.items?.length || 0,
    })
  } else {
    messages.value.push({
      role: 'assistant',
      text: result?.error || 'Something went wrong generating variations.',
      error: true,
    })
  }
  scrollToBottom()
}

function scrollToBottom() {
  nextTick(() => {
    if (messagesRef.value) {
      messagesRef.value.scrollTop = messagesRef.value.scrollHeight
    }
  })
}

watch(() => store.aiGenerating, () => scrollToBottom())
</script>

<style scoped>
.ai-slide-enter-active,
.ai-slide-leave-active {
  transition: all 0.2s ease;
}
.ai-slide-enter-from,
.ai-slide-leave-to {
  opacity: 0;
  transform: translateY(12px) scale(0.97);
}
</style>
