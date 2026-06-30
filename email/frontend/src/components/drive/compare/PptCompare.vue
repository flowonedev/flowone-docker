<script setup>
import { ref, watch, computed } from 'vue'
import JSZip from 'jszip'
import * as Diff from 'diff'

const props = defineProps({
  leftContent: Object,
  rightContent: Object,
  leftVersion: Object,
  rightVersion: Object,
  mode: { type: String, default: 'side-by-side' }
})

const leftSlides = ref([])
const rightSlides = ref([])
const activeSlide = ref(0)
const loading = ref(true)
const error = ref(null)
const syncScroll = ref(true)
const showDiffHighlight = ref(true)

// Scroll refs
const leftScrollRef = ref(null)
const rightScrollRef = ref(null)
let isScrolling = false

// Stats
const stats = ref({ changed: 0, addedSlides: 0, removedSlides: 0 })

// Convert base64 to ArrayBuffer
function base64ToArrayBuffer(base64) {
  const base64Data = base64.replace(/^data:[^;]+;base64,/, '')
  const binaryString = atob(base64Data)
  const bytes = new Uint8Array(binaryString.length)
  for (let i = 0; i < binaryString.length; i++) {
    bytes[i] = binaryString.charCodeAt(i)
  }
  return bytes.buffer
}

// Parse PPTX file and extract slides
async function parsePptx(content) {
  if (!content?.content) {
    throw new Error('No content provided')
  }
  
  try {
    const arrayBuffer = base64ToArrayBuffer(content.content)
    const zip = await JSZip.loadAsync(arrayBuffer)
    
    const slides = []
    
    // Find all slide XML files
    const slideFiles = Object.keys(zip.files)
      .filter(name => name.match(/ppt\/slides\/slide\d+\.xml$/))
      .sort((a, b) => {
        const numA = parseInt(a.match(/slide(\d+)/)[1])
        const numB = parseInt(b.match(/slide(\d+)/)[1])
        return numA - numB
      })
    
    for (const slideFile of slideFiles) {
      const slideXml = await zip.file(slideFile).async('string')
      const slideContent = extractTextFromSlideXml(slideXml)
      slides.push({
        name: `Slide ${slides.length + 1}`,
        text: slideContent.text,
        elements: slideContent.elements
      })
    }
    
    return slides
  } catch (e) {
    console.error('PPTX parsing error:', e)
    throw new Error('Failed to parse presentation')
  }
}

// Extract text content from slide XML
function extractTextFromSlideXml(xml) {
  const parser = new DOMParser()
  const doc = parser.parseFromString(xml, 'text/xml')
  
  const elements = []
  let allText = ''
  
  // Find all text elements (a:t tags contain text in PPTX)
  const textNodes = doc.getElementsByTagName('a:t')
  
  // Group text by paragraph (a:p)
  const paragraphs = doc.getElementsByTagName('a:p')
  
  for (let i = 0; i < paragraphs.length; i++) {
    const para = paragraphs[i]
    const texts = para.getElementsByTagName('a:t')
    let paraText = ''
    
    for (let j = 0; j < texts.length; j++) {
      paraText += texts[j].textContent || ''
    }
    
    if (paraText.trim()) {
      elements.push({
        type: 'paragraph',
        text: paraText.trim()
      })
      allText += paraText.trim() + '\n'
    }
  }
  
  return { text: allText.trim(), elements }
}

// Compare slides and get diff
const slideDiff = computed(() => {
  if (!leftSlides.value.length && !rightSlides.value.length) return []
  
  const maxSlides = Math.max(leftSlides.value.length, rightSlides.value.length)
  const result = []
  
  let changedCount = 0
  let addedCount = 0
  let removedCount = 0
  
  for (let i = 0; i < maxSlides; i++) {
    const leftSlide = leftSlides.value[i]
    const rightSlide = rightSlides.value[i]
    
    if (!leftSlide) {
      addedCount++
      result.push({
        index: i,
        type: 'added',
        leftSlide: null,
        rightSlide,
        textDiff: null
      })
    } else if (!rightSlide) {
      removedCount++
      result.push({
        index: i,
        type: 'removed',
        leftSlide,
        rightSlide: null,
        textDiff: null
      })
    } else if (leftSlide.text !== rightSlide.text) {
      changedCount++
      const textDiff = Diff.diffWords(leftSlide.text, rightSlide.text)
      result.push({
        index: i,
        type: 'changed',
        leftSlide,
        rightSlide,
        textDiff
      })
    } else {
      result.push({
        index: i,
        type: 'unchanged',
        leftSlide,
        rightSlide,
        textDiff: null
      })
    }
  }
  
  stats.value = { changed: changedCount, addedSlides: addedCount, removedSlides: removedCount }
  
  return result
})

// Current slide diff
const currentSlideDiff = computed(() => {
  return slideDiff.value[activeSlide.value] || null
})

// Sync scroll handlers
function onLeftScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  
  const leftEl = e.target
  const rightEl = rightScrollRef.value
  
  if (rightEl) {
    rightEl.scrollTop = leftEl.scrollTop
  }
  
  requestAnimationFrame(() => { isScrolling = false })
}

function onRightScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  
  const rightEl = e.target
  const leftEl = leftScrollRef.value
  
  if (leftEl) {
    leftEl.scrollTop = rightEl.scrollTop
  }
  
  requestAnimationFrame(() => { isScrolling = false })
}

async function loadPresentations() {
  loading.value = true
  error.value = null
  
  try {
    const [left, right] = await Promise.all([
      parsePptx(props.leftContent),
      parsePptx(props.rightContent)
    ])
    
    leftSlides.value = left
    rightSlides.value = right
    activeSlide.value = 0
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

watch([() => props.leftContent, () => props.rightContent], () => {
  if (props.leftContent && props.rightContent) {
    loadPresentations()
  }
}, { immediate: true })

function getSlideClass(index) {
  const diff = slideDiff.value[index]
  if (!diff) return ''
  
  switch (diff.type) {
    case 'added': return 'border-green-500 bg-green-50 dark:bg-green-500/10'
    case 'removed': return 'border-red-500 bg-red-50 dark:bg-red-500/10'
    case 'changed': return 'border-blue-500 bg-blue-50 dark:bg-blue-500/10'
    default: return 'border-surface-300 dark:border-surface-600'
  }
}
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Toolbar -->
    <div class="flex items-center justify-between px-4 py-2 bg-surface-100 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-4">
        <!-- Stats -->
        <div class="flex items-center gap-3 text-xs">
          <span class="text-surface-600 dark:text-surface-400">
            {{ Math.max(leftSlides.length, rightSlides.length) }} slides
          </span>
          <span v-if="stats.changed > 0" class="flex items-center gap-1.5 text-blue-600 dark:text-blue-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-blue-500"></span>
            {{ stats.changed }} changed
          </span>
          <span v-if="stats.addedSlides > 0" class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-green-500"></span>
            +{{ stats.addedSlides }} slides
          </span>
          <span v-if="stats.removedSlides > 0" class="flex items-center gap-1.5 text-red-600 dark:text-red-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-red-500"></span>
            -{{ stats.removedSlides }} slides
          </span>
        </div>
      </div>
      
      <div class="flex items-center gap-4">
        <!-- Highlight toggle -->
        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer select-none">
          <div 
            @click="showDiffHighlight = !showDiffHighlight"
            :class="[
              'relative w-10 h-5 rounded-full transition-colors cursor-pointer',
              showDiffHighlight ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <div 
              :class="[
                'absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
                showDiffHighlight ? 'translate-x-5' : 'translate-x-0.5'
              ]"
            ></div>
          </div>
          <span class="flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">palette</span>
            Highlight
          </span>
        </label>
        
        <!-- Sync scroll toggle -->
        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer select-none">
          <div 
            @click="syncScroll = !syncScroll"
            :class="[
              'relative w-10 h-5 rounded-full transition-colors cursor-pointer',
              syncScroll ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <div 
              :class="[
                'absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
                syncScroll ? 'translate-x-5' : 'translate-x-0.5'
              ]"
            ></div>
          </div>
          <span class="flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">sync</span>
            Sync scroll
          </span>
        </label>
      </div>
    </div>
    
    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500 mx-auto mb-3"></div>
        <p class="text-surface-500">Parsing presentations...</p>
      </div>
    </div>
    
    <!-- Error -->
    <div v-else-if="error" class="flex-1 flex items-center justify-center">
      <div class="text-center max-w-md">
        <span class="material-symbols-rounded text-5xl text-red-500 mb-4">error</span>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">Parsing Failed</h3>
        <p class="text-surface-500">{{ error }}</p>
        <button 
          @click="loadPresentations"
          class="mt-4 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
        >
          Try Again
        </button>
      </div>
    </div>
    
    <!-- Main content -->
    <div v-else class="flex-1 flex overflow-hidden">
      <!-- Slide Navigator (left sidebar) -->
      <div class="w-48 flex-shrink-0 border-r border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 overflow-y-auto p-2">
        <div class="text-xs font-medium text-surface-500 uppercase tracking-wide mb-2 px-2">Slides</div>
        <div class="space-y-2">
          <button
            v-for="(slide, idx) in slideDiff"
            :key="idx"
            @click="activeSlide = idx"
            :class="[
              'w-full p-2 rounded-lg border-2 text-left transition-all',
              activeSlide === idx ? 'ring-2 ring-primary-500' : '',
              getSlideClass(idx)
            ]"
          >
            <div class="text-xs font-medium text-surface-700 dark:text-surface-300">
              Slide {{ idx + 1 }}
            </div>
            <div class="text-[10px] text-surface-500 mt-0.5">
              <span v-if="slide.type === 'added'" class="text-green-600 dark:text-green-400">New slide</span>
              <span v-else-if="slide.type === 'removed'" class="text-red-600 dark:text-red-400">Removed</span>
              <span v-else-if="slide.type === 'changed'" class="text-blue-600 dark:text-blue-400">Modified</span>
              <span v-else>No changes</span>
            </div>
          </button>
        </div>
      </div>
      
      <!-- Slide Content Comparison -->
      <div class="flex-1 flex overflow-hidden">
        <!-- Left Slide (older) -->
        <div class="flex-1 flex flex-col border-r border-surface-200 dark:border-surface-700 min-w-0">
          <div class="px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-amber-500">history</span>
              <span class="font-medium text-amber-700 dark:text-amber-300">
                Version {{ leftVersion?.version_number }} - Slide {{ activeSlide + 1 }}
              </span>
            </div>
          </div>
          
          <div 
            ref="leftScrollRef"
            @scroll="onLeftScroll"
            class="flex-1 overflow-auto bg-white dark:bg-surface-800 p-6"
          >
            <template v-if="currentSlideDiff">
              <!-- No slide in old version -->
              <div v-if="currentSlideDiff.type === 'added'" class="flex items-center justify-center h-full">
                <div class="text-center text-surface-400">
                  <span class="material-symbols-rounded text-4xl mb-2">add_circle</span>
                  <p>Slide added in newer version</p>
                </div>
              </div>
              
              <!-- Show slide content -->
              <div v-else class="slide-content">
                <template v-if="showDiffHighlight && currentSlideDiff.type === 'changed' && currentSlideDiff.textDiff">
                  <p class="leading-relaxed">
                    <template v-for="(part, idx) in currentSlideDiff.textDiff" :key="idx">
                      <span 
                        v-if="part.removed" 
                        class="bg-red-200 dark:bg-red-500/30 text-red-800 dark:text-red-200 px-0.5 rounded line-through"
                      >{{ part.value }}</span>
                      <span v-else-if="!part.added">{{ part.value }}</span>
                    </template>
                  </p>
                </template>
                <template v-else>
                  <div v-for="(elem, idx) in currentSlideDiff.leftSlide?.elements" :key="idx" class="mb-3">
                    <p class="text-surface-800 dark:text-surface-200">{{ elem.text }}</p>
                  </div>
                </template>
              </div>
            </template>
          </div>
        </div>
        
        <!-- Right Slide (newer) -->
        <div class="flex-1 flex flex-col min-w-0">
          <div class="px-4 py-2 bg-green-50 dark:bg-green-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <div class="flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-green-500">update</span>
              <span class="font-medium text-green-700 dark:text-green-300">
                Version {{ rightVersion?.version_number }} - Slide {{ activeSlide + 1 }}
              </span>
            </div>
          </div>
          
          <div 
            ref="rightScrollRef"
            @scroll="onRightScroll"
            class="flex-1 overflow-auto bg-white dark:bg-surface-800 p-6"
          >
            <template v-if="currentSlideDiff">
              <!-- No slide in new version -->
              <div v-if="currentSlideDiff.type === 'removed'" class="flex items-center justify-center h-full">
                <div class="text-center text-surface-400">
                  <span class="material-symbols-rounded text-4xl mb-2">remove_circle</span>
                  <p>Slide removed in newer version</p>
                </div>
              </div>
              
              <!-- Show slide content -->
              <div v-else class="slide-content">
                <template v-if="showDiffHighlight && currentSlideDiff.type === 'changed' && currentSlideDiff.textDiff">
                  <p class="leading-relaxed">
                    <template v-for="(part, idx) in currentSlideDiff.textDiff" :key="idx">
                      <span 
                        v-if="part.added" 
                        class="bg-green-200 dark:bg-green-500/30 text-green-800 dark:text-green-200 px-0.5 rounded font-medium"
                      >{{ part.value }}</span>
                      <span v-else-if="!part.removed">{{ part.value }}</span>
                    </template>
                  </p>
                </template>
                <template v-else>
                  <div v-for="(elem, idx) in currentSlideDiff.rightSlide?.elements" :key="idx" class="mb-3">
                    <p class="text-surface-800 dark:text-surface-200">{{ elem.text }}</p>
                  </div>
                </template>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-content {
  font-size: 0.95rem;
  line-height: 1.7;
}
</style>

