<template>
  <Teleport to="body">
    <div v-if="show" class="print-preview-overlay" @click.self="$emit('close')">
      <div class="print-preview-container">
        <!-- Header -->
        <header class="print-preview-header">
          <div class="header-left">
            <h2 class="header-title">Print Preview</h2>
            <span class="header-subtitle">{{ documentTitle }}</span>
          </div>
          
          <div class="header-center">
            <span class="page-indicator">
              Page {{ currentPage }} of {{ totalPages }}
            </span>
          </div>
          
          <div class="header-right">
            <button @click="handlePrint" class="btn-print">
              <span class="material-symbols-rounded">print</span>
              Print
            </button>
            <button @click="$emit('close')" class="btn-close">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
        </header>
        
        <!-- Main content area -->
        <div class="print-preview-body">
          <!-- Sidebar with page thumbnails -->
          <aside class="print-preview-sidebar">
            <div class="sidebar-header">
              <span class="sidebar-title">Pages</span>
            </div>
            <div class="thumbnail-list">
              <button
                v-for="page in totalPages"
                :key="page"
                @click="scrollToPage(page)"
                class="thumbnail-item"
                :class="{ active: currentPage === page }"
              >
                <div class="thumbnail-preview">
                  <span class="thumbnail-number">{{ page }}</span>
                </div>
                <span class="thumbnail-label">Page {{ page }}</span>
              </button>
            </div>
          </aside>
          
          <!-- Pages preview area -->
          <div 
            ref="pagesContainer" 
            class="print-preview-pages"
            @scroll="handleScroll"
          >
            <div 
              v-for="(pageContent, index) in pages" 
              :key="index"
              :ref="el => pageRefs[index] = el"
              class="preview-page"
              :data-page="index + 1"
            >
              <!-- Page header (if configured) -->
              <div v-if="headerText" class="page-header-content">
                {{ headerText }}
              </div>
              
              <!-- Page content -->
              <div 
                class="page-content" 
                v-html="pageContent"
              ></div>
              
              <!-- Page footer -->
              <div class="page-footer-content">
                <span v-if="footerText" class="footer-text">{{ footerText }}</span>
                <span v-if="showPageNumbers" class="footer-page-number">{{ index + 1 }}</span>
              </div>
            </div>
            
            <!-- Empty state if no pages -->
            <div v-if="pages.length === 0" class="preview-empty">
              <span class="material-symbols-rounded">description</span>
              <p>No content to preview</p>
            </div>
          </div>
        </div>
        
        <!-- Footer with settings -->
        <footer class="print-preview-footer">
          <div class="footer-settings">
            <!-- Page size selector -->
            <div class="setting-group">
              <label class="setting-label">Page Size</label>
              <select v-model="pageSize" class="setting-select">
                <option value="a4">A4</option>
                <option value="letter">Letter</option>
                <option value="legal">Legal</option>
              </select>
            </div>
            
            <!-- Orientation -->
            <div class="setting-group">
              <label class="setting-label">Orientation</label>
              <div class="setting-toggle-group">
                <button 
                  @click="orientation = 'portrait'"
                  class="toggle-btn"
                  :class="{ active: orientation === 'portrait' }"
                >
                  <span class="material-symbols-rounded">crop_portrait</span>
                  Portrait
                </button>
                <button 
                  @click="orientation = 'landscape'"
                  class="toggle-btn"
                  :class="{ active: orientation === 'landscape' }"
                >
                  <span class="material-symbols-rounded">crop_landscape</span>
                  Landscape
                </button>
              </div>
            </div>
            
            <!-- Page numbers toggle -->
            <div class="setting-group">
              <label class="setting-label">Page Numbers</label>
              <div 
                class="toggle-switch"
                :class="{ active: showPageNumbers }"
                @click="showPageNumbers = !showPageNumbers"
              >
                <div class="toggle-knob"></div>
              </div>
            </div>
          </div>
          
          <div class="footer-actions">
            <button @click="$emit('close')" class="btn-secondary">
              Cancel
            </button>
            <button @click="handlePrint" class="btn-primary">
              <span class="material-symbols-rounded">print</span>
              Print Document
            </button>
          </div>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { getLayoutCalculator } from '../services/pagination/LayoutCalculator.js'
import {
  PAGE_WIDTH,
  PAGE_HEIGHT,
  PAGE_CONTENT_HEIGHT,
  PAGE_MARGIN_TOP,
  PAGE_MARGIN_BOTTOM,
  PAGE_MARGIN_LEFT,
  PAGE_MARGIN_RIGHT,
} from '../services/pagination/constants.js'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  documentTitle: {
    type: String,
    default: 'Untitled Document',
  },
  htmlContent: {
    type: String,
    default: '',
  },
  headerText: {
    type: String,
    default: '',
  },
  footerText: {
    type: String,
    default: '',
  },
  initialShowPageNumbers: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['close', 'print'])

// Refs
const pagesContainer = ref(null)
const pageRefs = ref([])

// State
const currentPage = ref(1)
const pageSize = ref('a4')
const orientation = ref('portrait')
const showPageNumbers = ref(props.initialShowPageNumbers)
const pages = ref([])
const totalPages = computed(() => pages.value.length || 1)

// Page dimensions based on settings
const pageDimensions = computed(() => {
  const sizes = {
    a4: { width: 210, height: 297 },
    letter: { width: 216, height: 279 },
    legal: { width: 216, height: 356 },
  }
  
  const size = sizes[pageSize.value] || sizes.a4
  
  if (orientation.value === 'landscape') {
    return { width: size.height, height: size.width }
  }
  
  return size
})

// Convert mm to px at 96 DPI
const mmToPx = (mm) => Math.round(mm * 96 / 25.4)

/**
 * Parse HTML content and split into pages
 */
function splitContentIntoPages(html) {
  if (!html) {
    pages.value = []
    return
  }
  
  // Create a temporary container to parse HTML
  const parser = new DOMParser()
  const doc = parser.parseFromString(html, 'text/html')
  const body = doc.body
  
  if (!body.children.length) {
    pages.value = [html]
    return
  }
  
  // Get the layout calculator's cached page breaks
  const calculator = getLayoutCalculator()
  const layout = calculator.cache
  
  // Calculate content height per page
  const contentHeight = PAGE_CONTENT_HEIGHT
  
  // Simple approach: split at hard page breaks and estimate soft breaks
  const pageContents = []
  let currentPageHtml = ''
  let currentHeight = 0
  
  // Iterate through all child elements
  for (const node of body.children) {
    // Check for hard page break
    if (node.classList?.contains('hard-page-break') || 
        node.getAttribute?.('data-page-break') === 'true') {
      // Start new page
      if (currentPageHtml) {
        pageContents.push(currentPageHtml)
      }
      currentPageHtml = ''
      currentHeight = 0
      continue
    }
    
    // Estimate element height
    const estimatedHeight = estimateElementHeight(node)
    
    // Check if this element would overflow the page
    if (currentHeight + estimatedHeight > contentHeight && currentPageHtml) {
      // Start new page
      pageContents.push(currentPageHtml)
      currentPageHtml = ''
      currentHeight = 0
    }
    
    // Add element to current page
    currentPageHtml += node.outerHTML
    currentHeight += estimatedHeight
  }
  
  // Add remaining content
  if (currentPageHtml) {
    pageContents.push(currentPageHtml)
  }
  
  pages.value = pageContents.length > 0 ? pageContents : [html]
}

/**
 * Estimate element height based on type
 */
function estimateElementHeight(element) {
  const tag = element.tagName?.toLowerCase() || ''
  const text = element.textContent || ''
  
  // Base heights
  const heights = {
    h1: 52,
    h2: 44,
    h3: 38,
    h4: 34,
    h5: 32,
    h6: 30,
    p: Math.max(28, Math.ceil(text.length / 80) * 24),
    ul: 28 * (element.children?.length || 1),
    ol: 28 * (element.children?.length || 1),
    blockquote: 40,
    pre: Math.max(80, (text.split('\n').length + 1) * 20),
    table: 100,
    hr: 24,
    img: 200,
  }
  
  return heights[tag] || 28
}

/**
 * Handle scroll to update current page indicator
 */
function handleScroll() {
  if (!pagesContainer.value) return
  
  const container = pagesContainer.value
  const scrollTop = container.scrollTop
  const pageHeight = mmToPx(pageDimensions.value.height) + 40 // Add gap
  
  const newPage = Math.max(1, Math.ceil((scrollTop + pageHeight / 2) / pageHeight))
  if (newPage !== currentPage.value && newPage <= totalPages.value) {
    currentPage.value = newPage
  }
}

/**
 * Scroll to a specific page
 */
function scrollToPage(pageNumber) {
  const pageElement = pageRefs.value[pageNumber - 1]
  if (pageElement) {
    pageElement.scrollIntoView({ behavior: 'smooth', block: 'start' })
    currentPage.value = pageNumber
  }
}

/**
 * Handle print action
 */
function handlePrint() {
  // Create print-specific styles
  const printStyles = `
    @page {
      size: ${pageDimensions.value.width}mm ${pageDimensions.value.height}mm;
      margin: ${PAGE_MARGIN_TOP / 96 * 25.4}mm ${PAGE_MARGIN_RIGHT / 96 * 25.4}mm ${PAGE_MARGIN_BOTTOM / 96 * 25.4}mm ${PAGE_MARGIN_LEFT / 96 * 25.4}mm;
    }
    
    @media print {
      body * {
        visibility: hidden;
      }
      
      #print-content,
      #print-content * {
        visibility: visible;
      }
      
      #print-content {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
      }
      
      .print-page {
        page-break-after: always;
        min-height: ${pageDimensions.value.height - 50}mm;
      }
      
      .print-page:last-child {
        page-break-after: auto;
      }
      
      .print-page-header {
        position: running(header);
        text-align: center;
        font-size: 10pt;
        color: #666;
      }
      
      .print-page-footer {
        position: running(footer);
        text-align: center;
        font-size: 10pt;
        color: #666;
      }
    }
  `
  
  // Create print container
  const printContainer = document.createElement('div')
  printContainer.id = 'print-content'
  
  // Add pages
  pages.value.forEach((pageHtml, index) => {
    const pageDiv = document.createElement('div')
    pageDiv.className = 'print-page'
    
    // Header
    if (props.headerText) {
      const header = document.createElement('div')
      header.className = 'print-page-header'
      header.textContent = props.headerText
      pageDiv.appendChild(header)
    }
    
    // Content
    const content = document.createElement('div')
    content.className = 'print-page-content'
    content.innerHTML = pageHtml
    pageDiv.appendChild(content)
    
    // Footer
    const footer = document.createElement('div')
    footer.className = 'print-page-footer'
    if (props.footerText) {
      footer.textContent = props.footerText
    }
    if (showPageNumbers.value) {
      footer.textContent += (footer.textContent ? ' | ' : '') + `Page ${index + 1} of ${pages.value.length}`
    }
    if (footer.textContent) {
      pageDiv.appendChild(footer)
    }
    
    printContainer.appendChild(pageDiv)
  })
  
  // Add styles
  const styleElement = document.createElement('style')
  styleElement.textContent = printStyles
  
  // Add to document
  document.body.appendChild(styleElement)
  document.body.appendChild(printContainer)
  
  // Print
  window.print()
  
  // Cleanup
  setTimeout(() => {
    document.body.removeChild(printContainer)
    document.body.removeChild(styleElement)
  }, 1000)
  
  emit('print')
}

// Watch for content changes
watch(() => props.htmlContent, (newContent) => {
  if (props.show && newContent) {
    splitContentIntoPages(newContent)
  }
}, { immediate: true })

// Watch for show state
watch(() => props.show, (isVisible) => {
  if (isVisible) {
    currentPage.value = 1
    splitContentIntoPages(props.htmlContent)
    
    // Reset scroll position
    nextTick(() => {
      if (pagesContainer.value) {
        pagesContainer.value.scrollTop = 0
      }
    })
  }
})

// Initialize on mount
onMounted(() => {
  showPageNumbers.value = props.initialShowPageNumbers
})
</script>

<style scoped>
.print-preview-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  animation: fadeIn 0.2s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.print-preview-container {
  display: flex;
  flex-direction: column;
  width: 95vw;
  height: 95vh;
  max-width: 1400px;
  background: #1a1a1a;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
  animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
  from { 
    opacity: 0;
    transform: translateY(20px);
  }
  to { 
    opacity: 1;
    transform: translateY(0);
  }
}

/* Header */
.print-preview-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 24px;
  background: #252525;
  border-bottom: 1px solid #333;
}

.header-left {
  display: flex;
  align-items: baseline;
  gap: 16px;
}

.header-title {
  font-size: 18px;
  font-weight: 600;
  color: #fff;
  margin: 0;
}

.header-subtitle {
  font-size: 14px;
  color: #888;
}

.header-center {
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
}

.page-indicator {
  font-size: 14px;
  color: #ccc;
  background: #333;
  padding: 6px 16px;
  border-radius: 20px;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.btn-print {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  background: rgb(var(--color-primary-500));
  color: white;
  font-size: 14px;
  font-weight: 600;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-print:hover {
  background: rgb(var(--color-primary-600));
}

.btn-print .material-symbols-rounded {
  font-size: 20px;
}

.btn-close {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background: transparent;
  color: #888;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-close:hover {
  background: #333;
  color: #fff;
}

.btn-close .material-symbols-rounded {
  font-size: 24px;
}

/* Body */
.print-preview-body {
  display: flex;
  flex: 1;
  overflow: hidden;
}

/* Sidebar */
.print-preview-sidebar {
  width: 180px;
  background: #1f1f1f;
  border-right: 1px solid #333;
  display: flex;
  flex-direction: column;
}

.sidebar-header {
  padding: 16px;
  border-bottom: 1px solid #333;
}

.sidebar-title {
  font-size: 12px;
  font-weight: 600;
  color: #888;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.thumbnail-list {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.thumbnail-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 8px;
  background: transparent;
  border: 2px solid transparent;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s;
}

.thumbnail-item:hover {
  background: #2a2a2a;
}

.thumbnail-item.active {
  border-color: rgb(var(--color-primary-500));
  background: rgba(var(--color-primary-500), 0.1);
}

.thumbnail-preview {
  width: 100%;
  aspect-ratio: 210 / 297;
  background: #fff;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.thumbnail-number {
  font-size: 24px;
  font-weight: 600;
  color: #ccc;
}

.thumbnail-label {
  font-size: 11px;
  color: #888;
}

.thumbnail-item.active .thumbnail-label {
  color: rgb(var(--color-primary-400));
}

/* Pages area */
.print-preview-pages {
  flex: 1;
  overflow-y: auto;
  padding: 40px;
  background: #2a2a2a;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 40px;
}

.preview-page {
  width: 794px;
  min-height: 1123px;
  background: #fff;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
  border-radius: 4px;
  padding: 96px;
  box-sizing: border-box;
  position: relative;
  display: flex;
  flex-direction: column;
}

.page-header-content {
  position: absolute;
  top: 48px;
  left: 96px;
  right: 96px;
  text-align: center;
  font-size: 11px;
  color: #666;
}

.page-content {
  flex: 1;
  overflow: hidden;
  font-family: 'Outfit', system-ui, sans-serif;
  font-size: 16px;
  line-height: 1.6;
  color: #1f2937;
}

.page-content :deep(h1) {
  font-size: 28px;
  font-weight: 600;
  margin: 24px 0 12px;
}

.page-content :deep(h2) {
  font-size: 22px;
  font-weight: 600;
  margin: 20px 0 10px;
}

.page-content :deep(h3) {
  font-size: 18px;
  font-weight: 500;
  margin: 16px 0 8px;
}

.page-content :deep(p) {
  margin: 0 0 12px;
}

.page-content :deep(ul),
.page-content :deep(ol) {
  padding-left: 24px;
  margin: 12px 0;
}

.page-content :deep(blockquote) {
  border-left: 4px solid #e4e4e7;
  margin: 16px 0;
  padding-left: 16px;
  color: #71717a;
  font-style: italic;
}

.page-content :deep(pre) {
  background: #f4f4f5;
  border-radius: 8px;
  padding: 16px;
  font-family: monospace;
  font-size: 14px;
  overflow-x: auto;
}

.page-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 16px 0;
}

.page-content :deep(th),
.page-content :deep(td) {
  border: 1px solid #e4e4e7;
  padding: 8px 12px;
  text-align: left;
}

.page-content :deep(th) {
  background: #f4f4f5;
  font-weight: 600;
}

.page-footer-content {
  position: absolute;
  bottom: 48px;
  left: 96px;
  right: 96px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 11px;
  color: #666;
}

.footer-text {
  flex: 1;
}

.footer-page-number {
  text-align: right;
}

.preview-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 80px;
  color: #666;
}

.preview-empty .material-symbols-rounded {
  font-size: 64px;
  opacity: 0.5;
}

/* Footer */
.print-preview-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 24px;
  background: #252525;
  border-top: 1px solid #333;
}

.footer-settings {
  display: flex;
  align-items: center;
  gap: 32px;
}

.setting-group {
  display: flex;
  align-items: center;
  gap: 12px;
}

.setting-label {
  font-size: 13px;
  color: #888;
}

.setting-select {
  padding: 8px 12px;
  background: #333;
  color: #fff;
  border: 1px solid #444;
  border-radius: 8px;
  font-size: 13px;
  cursor: pointer;
  outline: none;
}

.setting-select:focus {
  border-color: rgb(var(--color-primary-500));
}

.setting-toggle-group {
  display: flex;
  gap: 4px;
  background: #333;
  padding: 4px;
  border-radius: 8px;
}

.toggle-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  background: transparent;
  color: #888;
  border: none;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.15s;
}

.toggle-btn:hover {
  color: #fff;
}

.toggle-btn.active {
  background: rgb(var(--color-primary-500));
  color: #fff;
}

.toggle-btn .material-symbols-rounded {
  font-size: 18px;
}

.toggle-switch {
  width: 44px;
  height: 24px;
  background: #444;
  border-radius: 12px;
  position: relative;
  cursor: pointer;
  transition: all 0.2s;
}

.toggle-switch.active {
  background: rgb(var(--color-primary-500));
}

.toggle-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  background: white;
  border-radius: 50%;
  transition: all 0.2s;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}

.toggle-switch.active .toggle-knob {
  left: 22px;
}

.footer-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.btn-secondary {
  padding: 10px 20px;
  background: #333;
  color: #fff;
  font-size: 14px;
  font-weight: 500;
  border: 1px solid #444;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-secondary:hover {
  background: #444;
}

.btn-primary {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 24px;
  background: rgb(var(--color-primary-500));
  color: white;
  font-size: 14px;
  font-weight: 600;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-primary:hover {
  background: rgb(var(--color-primary-600));
}

.btn-primary .material-symbols-rounded {
  font-size: 20px;
}

/* Responsive */
@media (max-width: 1200px) {
  .print-preview-sidebar {
    width: 140px;
  }
  
  .preview-page {
    width: 100%;
    max-width: 794px;
  }
}

@media (max-width: 768px) {
  .print-preview-sidebar {
    display: none;
  }
  
  .print-preview-pages {
    padding: 20px;
    gap: 20px;
  }
  
  .preview-page {
    padding: 48px;
    min-height: auto;
  }
  
  .footer-settings {
    flex-wrap: wrap;
    gap: 16px;
  }
  
  .header-center {
    display: none;
  }
}
</style>

