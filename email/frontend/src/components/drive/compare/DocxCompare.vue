<script setup>
import { ref, watch, computed } from 'vue'
import { convertDocxToHtml, computeDocxDiff } from '@/utils/docxDiffUtils'

const props = defineProps({
  leftContent: Object,
  rightContent: Object,
  leftVersion: Object,
  rightVersion: Object,
  mode: { type: String, default: 'side-by-side' }
})

const leftHtml = ref('')
const rightHtml = ref('')
const leftEmpty = ref(false)
const rightEmpty = ref(false)
const loading = ref(true)
const error = ref(null)
const viewMode = ref('diff') // 'formatted' or 'diff'
const syncScroll = ref(true)

// Scroll refs
const leftScrollRef = ref(null)
const rightScrollRef = ref(null)
let isScrolling = false

// Both versions converted to nothing renderable -> show an explicit message
// instead of two silent blank panels.
const bothEmpty = computed(() => leftEmpty.value && rightEmpty.value)

// Paragraph-aligned diff + change counts (formatting-aware, handles one
// empty side). Pure computation lives in docxDiffUtils.
const diffResult = computed(() => computeDocxDiff(leftHtml.value, rightHtml.value))
const diffParagraphs = computed(() => diffResult.value.rows)
const stats = computed(() => diffResult.value.stats)

// Sync scroll handlers
function onLeftScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  
  const leftEl = e.target
  const rightEl = rightScrollRef.value
  
  if (rightEl) {
    const scrollPercentage = leftEl.scrollTop / (leftEl.scrollHeight - leftEl.clientHeight)
    rightEl.scrollTop = scrollPercentage * (rightEl.scrollHeight - rightEl.clientHeight)
  }
  
  requestAnimationFrame(() => { isScrolling = false })
}

function onRightScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  
  const rightEl = e.target
  const leftEl = leftScrollRef.value
  
  if (leftEl) {
    const scrollPercentage = rightEl.scrollTop / (rightEl.scrollHeight - rightEl.clientHeight)
    leftEl.scrollTop = scrollPercentage * (leftEl.scrollHeight - leftEl.clientHeight)
  }
  
  requestAnimationFrame(() => { isScrolling = false })
}

async function loadDocuments() {
  loading.value = true
  error.value = null
  
  try {
    const [left, right] = await Promise.all([
      convertDocxToHtml(props.leftContent),
      convertDocxToHtml(props.rightContent)
    ])
    
    leftHtml.value = left.html
    rightHtml.value = right.html
    leftEmpty.value = left.empty
    rightEmpty.value = right.empty
    
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

watch([() => props.leftContent, () => props.rightContent], () => {
  if (props.leftContent && props.rightContent) {
    loadDocuments()
  }
}, { immediate: true })
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Toolbar -->
    <div class="flex items-center justify-between px-4 py-2 bg-surface-100 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-4">
        <!-- View mode toggle -->
        <div class="flex items-center gap-1 p-1 bg-surface-200 dark:bg-surface-600 rounded-lg">
          <button
            @click="viewMode = 'formatted'"
            :class="[
              'px-3 py-1 text-xs font-medium rounded transition-colors',
              viewMode === 'formatted' 
                ? 'bg-white dark:bg-surface-500 text-surface-900 dark:text-white shadow-sm' 
                : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white'
            ]"
          >
            <span class="flex items-center gap-1">
              <span class="material-symbols-rounded text-sm">article</span>
              Formatted
            </span>
          </button>
          <button
            @click="viewMode = 'diff'"
            :class="[
              'px-3 py-1 text-xs font-medium rounded transition-colors',
              viewMode === 'diff' 
                ? 'bg-white dark:bg-surface-500 text-surface-900 dark:text-white shadow-sm' 
                : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white'
            ]"
          >
            <span class="flex items-center gap-1">
              <span class="material-symbols-rounded text-sm">difference</span>
              Diff View
            </span>
          </button>
        </div>
        
        <!-- Stats -->
        <div class="flex items-center gap-3 text-xs">
          <span v-if="stats.changed > 0" class="flex items-center gap-1.5 text-blue-600 dark:text-blue-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-blue-500"></span>
            {{ stats.changed }} changed
          </span>
          <span v-if="stats.added > 0" class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-green-500"></span>
            +{{ stats.added }} added
          </span>
          <span v-if="stats.removed > 0" class="flex items-center gap-1.5 text-red-600 dark:text-red-400">
            <span class="w-2.5 h-2.5 rounded-sm bg-red-500"></span>
            -{{ stats.removed }} removed
          </span>
        </div>
      </div>
      
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
    
    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500 mx-auto mb-3"></div>
        <p class="text-surface-500">Converting documents...</p>
      </div>
    </div>
    
    <!-- Error -->
    <div v-else-if="error" class="flex-1 flex items-center justify-center">
      <div class="text-center max-w-md">
        <span class="material-symbols-rounded text-5xl text-red-500 mb-4">error</span>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">Conversion Failed</h3>
        <p class="text-surface-500">{{ error }}</p>
        <button 
          @click="loadDocuments"
          class="mt-4 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
        >
          Try Again
        </button>
      </div>
    </div>
    
    <!-- Both versions converted to nothing renderable -->
    <div v-else-if="bothEmpty" class="flex-1 flex items-center justify-center">
      <div class="text-center max-w-md">
        <span class="material-symbols-rounded text-5xl text-surface-400 mb-4">description</span>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">No readable text</h3>
        <p class="text-surface-500">Neither version has text content that can be displayed here. Download a version to open it in Word.</p>
      </div>
    </div>

    <!-- Formatted View - Original HTML side by side -->
    <div v-else-if="viewMode === 'formatted'" class="flex-1 flex overflow-hidden">
      <!-- Left Document -->
      <div class="flex-1 flex flex-col border-r border-surface-200 dark:border-surface-700 min-w-0">
        <div class="px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center gap-2 text-sm">
            <span class="material-symbols-rounded text-amber-500">history</span>
            <span class="font-medium text-amber-700 dark:text-amber-300">
              {{ $t('versionCompare.olderVersion', { number: leftVersion?.version_number }) }}
            </span>
          </div>
        </div>
        <div 
          ref="leftScrollRef"
          @scroll="onLeftScroll"
          class="flex-1 overflow-auto bg-white dark:bg-surface-800 p-6"
        >
          <div class="docx-content" v-html="leftHtml" />
        </div>
      </div>
      
      <!-- Right Document -->
      <div class="flex-1 flex flex-col min-w-0">
        <div class="px-4 py-2 bg-green-50 dark:bg-green-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center gap-2 text-sm">
            <span class="material-symbols-rounded text-green-500">update</span>
            <span class="font-medium text-green-700 dark:text-green-300">
              {{ $t('versionCompare.newerVersion', { number: rightVersion?.version_number }) }}
            </span>
          </div>
        </div>
        <div 
          ref="rightScrollRef"
          @scroll="onRightScroll"
          class="flex-1 overflow-auto bg-white dark:bg-surface-800 p-6"
        >
          <div class="docx-content" v-html="rightHtml" />
        </div>
      </div>
    </div>
    
    <!-- Diff View - Paragraph by paragraph comparison -->
    <div v-else class="flex-1 flex overflow-hidden">
      <!-- Left side -->
      <div class="flex-1 flex flex-col border-r border-surface-200 dark:border-surface-700 min-w-0">
        <div class="px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center gap-2 text-sm">
            <span class="material-symbols-rounded text-amber-500">history</span>
            <span class="font-medium text-amber-700 dark:text-amber-300">
              {{ $t('versionCompare.olderVersion', { number: leftVersion?.version_number }) }}
            </span>
          </div>
        </div>
        <div 
          ref="leftScrollRef"
          @scroll="onLeftScroll"
          class="flex-1 overflow-auto bg-surface-50 dark:bg-surface-900"
        >
          <div 
            v-for="(para, idx) in diffParagraphs" 
            :key="'left-' + idx"
            :class="[
              'px-4 py-3 border-b border-surface-200 dark:border-surface-700 text-sm leading-relaxed min-h-[3rem]',
              para.type === 'removed' ? 'bg-red-50 dark:bg-red-500/10' : '',
              para.type === 'changed' ? 'bg-blue-50 dark:bg-blue-500/10' : '',
              para.type === 'added' ? 'bg-surface-100 dark:bg-surface-800' : '',
              para.type === 'unchanged' ? 'bg-white dark:bg-surface-800' : ''
            ]"
          >
            <!-- Removed paragraph -->
            <template v-if="para.type === 'removed'">
              <span class="inline-flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400 mb-1">
                <span class="material-symbols-rounded text-sm">remove_circle</span> Removed
              </span>
              <p class="mt-1 text-red-800 dark:text-red-200">{{ para.leftText }}</p>
            </template>
            
            <!-- Formatting-only change - render the original markup so the
                 actual styling (strikethrough, bold, ...) is visible -->
            <template v-else-if="para.type === 'changed' && para.formattingChanged">
              <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 mb-1">
                <span class="material-symbols-rounded text-sm">format_paint</span> Formatting changed
              </span>
              <p class="mt-1 docx-content text-surface-700 dark:text-surface-300" v-html="para.leftHtml"></p>
            </template>

            <!-- Changed paragraph - show old with highlights -->
            <template v-else-if="para.type === 'changed'">
              <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 mb-1">
                <span class="material-symbols-rounded text-sm">edit</span> Changed
              </span>
              <p class="mt-1 text-surface-700 dark:text-surface-300">
                <template v-for="(part, pIdx) in para.wordDiff" :key="'left-word-' + pIdx">
                  <span 
                    v-if="part.removed" 
                    class="bg-red-200 dark:bg-red-500/30 text-red-800 dark:text-red-200 px-0.5 rounded line-through"
                  >{{ part.value }}</span>
                  <span v-else-if="!part.added">{{ part.value }}</span>
                </template>
              </p>
            </template>
            
            <!-- Added placeholder -->
            <template v-else-if="para.type === 'added'">
              <span class="text-xs text-surface-400 italic">(new content on right)</span>
            </template>
            
            <!-- Unchanged -->
            <template v-else>
              <p class="text-surface-700 dark:text-surface-300">{{ para.leftText }}</p>
            </template>
          </div>
        </div>
      </div>
      
      <!-- Right side -->
      <div class="flex-1 flex flex-col min-w-0">
        <div class="px-4 py-2 bg-green-50 dark:bg-green-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center gap-2 text-sm">
            <span class="material-symbols-rounded text-green-500">update</span>
            <span class="font-medium text-green-700 dark:text-green-300">
              {{ $t('versionCompare.newerVersion', { number: rightVersion?.version_number }) }}
            </span>
          </div>
        </div>
        <div 
          ref="rightScrollRef"
          @scroll="onRightScroll"
          class="flex-1 overflow-auto bg-surface-50 dark:bg-surface-900"
        >
          <div 
            v-for="(para, idx) in diffParagraphs" 
            :key="'right-' + idx"
            :class="[
              'px-4 py-3 border-b border-surface-200 dark:border-surface-700 text-sm leading-relaxed min-h-[3rem]',
              para.type === 'added' ? 'bg-green-50 dark:bg-green-500/10' : '',
              para.type === 'changed' ? 'bg-blue-50 dark:bg-blue-500/10' : '',
              para.type === 'removed' ? 'bg-surface-100 dark:bg-surface-800' : '',
              para.type === 'unchanged' ? 'bg-white dark:bg-surface-800' : ''
            ]"
          >
            <!-- Added paragraph -->
            <template v-if="para.type === 'added'">
              <span class="inline-flex items-center gap-1 text-xs font-medium text-green-600 dark:text-green-400 mb-1">
                <span class="material-symbols-rounded text-sm">add_circle</span> Added
              </span>
              <p class="mt-1 text-green-800 dark:text-green-200">{{ para.rightText }}</p>
            </template>
            
            <!-- Formatting-only change - render the new markup so the actual
                 styling (strikethrough, bold, ...) is visible -->
            <template v-else-if="para.type === 'changed' && para.formattingChanged">
              <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 mb-1">
                <span class="material-symbols-rounded text-sm">format_paint</span> Formatting changed
              </span>
              <p class="mt-1 docx-content text-surface-700 dark:text-surface-300" v-html="para.rightHtml"></p>
            </template>

            <!-- Changed paragraph - show new with highlights -->
            <template v-else-if="para.type === 'changed'">
              <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 mb-1">
                <span class="material-symbols-rounded text-sm">edit</span> Changed
              </span>
              <p class="mt-1 text-surface-700 dark:text-surface-300">
                <template v-for="(part, pIdx) in para.wordDiff" :key="'right-word-' + pIdx">
                  <span 
                    v-if="part.added" 
                    class="bg-green-200 dark:bg-green-500/30 text-green-800 dark:text-green-200 px-0.5 rounded font-medium"
                  >{{ part.value }}</span>
                  <span v-else-if="!part.removed">{{ part.value }}</span>
                </template>
              </p>
            </template>
            
            <!-- Removed placeholder -->
            <template v-else-if="para.type === 'removed'">
              <span class="text-xs text-surface-400 italic">(removed from left)</span>
            </template>
            
            <!-- Unchanged -->
            <template v-else>
              <p class="text-surface-700 dark:text-surface-300">{{ para.rightText }}</p>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Document content styles - preserves original formatting */
.docx-content {
  font-size: 0.9rem;
  line-height: 1.7;
  color: #1f2937;
}

.dark .docx-content {
  color: #e5e7eb;
}

.docx-content :deep(p) {
  margin-bottom: 1em;
}

.docx-content :deep(h1) {
  font-size: 1.5rem;
  font-weight: 700;
  margin-bottom: 0.75em;
  margin-top: 1em;
}

.docx-content :deep(h2) {
  font-size: 1.25rem;
  font-weight: 600;
  margin-bottom: 0.5em;
  margin-top: 0.75em;
}

.docx-content :deep(h3) {
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 0.5em;
}

.docx-content :deep(a) {
  color: #3b82f6;
  text-decoration: underline;
}

.docx-content :deep(a:hover) {
  color: #1d4ed8;
}

.docx-content :deep(strong),
.docx-content :deep(b) {
  font-weight: 600;
}

.docx-content :deep(em),
.docx-content :deep(i) {
  font-style: italic;
}

.docx-content :deep(u) {
  text-decoration: underline;
}

.docx-content :deep(s),
.docx-content :deep(strike) {
  text-decoration: line-through;
}

.docx-content :deep(img) {
  max-width: 100%;
  height: auto;
  margin: 1em 0;
}

.docx-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 1em 0;
}

.docx-content :deep(th),
.docx-content :deep(td) {
  border: 1px solid #e5e7eb;
  padding: 0.5rem 0.75rem;
  text-align: left;
}

.dark .docx-content :deep(th),
.dark .docx-content :deep(td) {
  border-color: #374151;
}

.docx-content :deep(th) {
  background: #f9fafb;
  font-weight: 600;
}

.dark .docx-content :deep(th) {
  background: #1f2937;
}

.docx-content :deep(ul),
.docx-content :deep(ol) {
  margin-left: 1.5em;
  margin-bottom: 1em;
}

.docx-content :deep(li) {
  margin-bottom: 0.35em;
}

.docx-content :deep(ul) {
  list-style-type: disc;
}

.docx-content :deep(ol) {
  list-style-type: decimal;
}

.docx-content :deep(blockquote) {
  border-left: 3px solid #e5e7eb;
  padding-left: 1em;
  margin: 1em 0;
  color: #6b7280;
}

.dark .docx-content :deep(blockquote) {
  border-color: #4b5563;
  color: #9ca3af;
}

.docx-content :deep(sub) {
  font-size: 0.75em;
  vertical-align: sub;
}

.docx-content :deep(sup) {
  font-size: 0.75em;
  vertical-align: super;
}
</style>
