<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="show" class="compare-overlay" @click.self="$emit('close')">
        <div class="compare-modal">
          <!-- Header -->
          <div class="compare-header">
            <div class="header-left">
              <span class="material-symbols-rounded header-icon">{{ documentType === 'presentation' ? 'slideshow' : 'description' }}</span>
              <div>
                <h2 class="header-title">Compare Versions</h2>
                <p class="header-subtitle">{{ documentTitle || 'Document' }}</p>
              </div>
            </div>
            
            <div class="header-center">
              <!-- Version indicators -->
              <div class="version-pill older">
                <span class="material-symbols-rounded" style="font-size: 16px;">restore</span>
                <span>v{{ olderVersion?.version_number }} (older)</span>
              </div>
              <span class="material-symbols-rounded arrow-icon">arrow_forward</span>
              <div class="version-pill newer">
                <span class="material-symbols-rounded" style="font-size: 16px;">history</span>
                <span>v{{ newerVersion?.version_number }} (newer)</span>
              </div>
            </div>
            
            <div class="header-right">
              <!-- View mode toggle -->
              <div class="view-toggle">
                <button 
                  @click="viewMode = 'side'" 
                  class="toggle-btn" 
                  :class="{ active: viewMode === 'side' }"
                  title="Side by Side"
                >
                  <span class="material-symbols-rounded" style="font-size: 18px;">view_column_2</span>
                  <span>Side by Side</span>
                </button>
                <button 
                  @click="viewMode = 'unified'" 
                  class="toggle-btn" 
                  :class="{ active: viewMode === 'unified' }"
                  title="Unified Diff"
                >
                  <span class="material-symbols-rounded" style="font-size: 18px;">difference</span>
                  <span>Diff View</span>
                </button>
              </div>
              
              <button @click="$emit('close')" class="close-btn">
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
          </div>
          
          <!-- Stats bar -->
          <div class="stats-bar">
            <span class="stat-item">
              <span class="stat-dot added"></span>
              <span class="stat-label">+{{ stats.added }} added</span>
            </span>
            <span class="stat-item">
              <span class="stat-dot removed"></span>
              <span class="stat-label">-{{ stats.removed }} removed</span>
            </span>
            <span class="stat-item unchanged">
              {{ stats.unchanged }} unchanged
            </span>
          </div>
          
          <!-- Loading state -->
          <div v-if="isLoading" class="loading-state">
            <span class="material-symbols-rounded spin loading-icon">progress_activity</span>
            <span class="loading-text">Loading version content...</span>
          </div>
          
          <!-- Error state -->
          <div v-else-if="error" class="error-state">
            <span class="material-symbols-rounded error-icon">error</span>
            <span class="error-text">{{ error }}</span>
            <button @click="loadVersions" class="retry-btn">Try again</button>
          </div>
          
          <!-- Diff content -->
          <div v-else class="diff-container">
            <!-- Side by side view -->
            <template v-if="viewMode === 'side'">
              <div class="side-panel older-panel">
                <div class="panel-label">
                  <span class="label-badge older">OLDER</span>
                  <span class="label-version">Version {{ olderVersion?.version_number }}</span>
                  <span class="panel-date">{{ formatDate(olderVersion?.created_at) }}</span>
                </div>
                <div class="panel-content" ref="olderScrollRef" @scroll="syncScroll('older')">
                  <div 
                    v-for="(line, index) in sideBySideDiff.older" 
                    :key="'older-' + index"
                    class="diff-line"
                    :class="line.type"
                  >
                    <span class="line-number">{{ line.lineNumber || '' }}</span>
                    <span class="line-marker">{{ line.marker }}</span>
                    <span class="line-content">{{ line.text }}</span>
                  </div>
                </div>
              </div>
              
              <div class="side-panel newer-panel">
                <div class="panel-label">
                  <span class="label-badge newer">NEWER</span>
                  <span class="label-version">Version {{ newerVersion?.version_number }}</span>
                  <span class="panel-date">{{ formatDate(newerVersion?.created_at) }}</span>
                </div>
                <div class="panel-content" ref="newerScrollRef" @scroll="syncScroll('newer')">
                  <div 
                    v-for="(line, index) in sideBySideDiff.newer" 
                    :key="'newer-' + index"
                    class="diff-line"
                    :class="line.type"
                  >
                    <span class="line-number">{{ line.lineNumber || '' }}</span>
                    <span class="line-marker">{{ line.marker }}</span>
                    <span class="line-content">{{ line.text }}</span>
                  </div>
                </div>
              </div>
            </template>
            
            <!-- Unified diff view -->
            <template v-else>
              <div class="unified-panel">
                <div class="panel-content">
                  <div 
                    v-for="(line, index) in unifiedDiff" 
                    :key="'unified-' + index"
                    class="diff-line"
                    :class="line.type"
                  >
                    <span class="line-number old">{{ line.oldLineNumber || '' }}</span>
                    <span class="line-number new">{{ line.newLineNumber || '' }}</span>
                    <span class="line-marker">{{ line.marker }}</span>
                    <span class="line-content">{{ line.text }}</span>
                  </div>
                </div>
              </div>
            </template>
          </div>
          
          <!-- Footer actions -->
          <div class="compare-footer">
            <div class="footer-info">
              <span class="material-symbols-rounded info-icon">info</span>
              <span class="info-text">
                v{{ olderVersion?.version_number }}: {{ olderSize }} | v{{ newerVersion?.version_number }}: {{ newerSize }}
              </span>
            </div>
            <div class="footer-actions">
              <button @click="restoreVersion(olderVersion)" class="restore-btn older">
                <span class="material-symbols-rounded" style="font-size: 18px;">restore</span>
                Restore older
              </button>
              <button @click="restoreVersion(newerVersion)" class="restore-btn newer">
                <span class="material-symbols-rounded" style="font-size: 18px;">restore</span>
                Restore newer
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { collabVersionApi } from '../services/collabApiService.js'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  show: { type: Boolean, default: false },
  documentUuid: { type: String, required: true },
  documentTitle: { type: String, default: '' },
  documentType: { type: String, default: 'document' }, // 'document' or 'presentation'
  version1: { type: Object, default: null },
  version2: { type: Object, default: null },
})

const emit = defineEmits(['close', 'restore'])

const toast = useToastStore()

// State
const isLoading = ref(false)
const error = ref(null)
const viewMode = ref('side') // 'side' or 'unified'
const olderContent = ref('')
const newerContent = ref('')
const olderScrollRef = ref(null)
const newerScrollRef = ref(null)
const isSyncingScroll = ref(false)

// Computed versions (sorted by version number)
const olderVersion = computed(() => {
  if (!props.version1 || !props.version2) return null
  return props.version1.version_number < props.version2.version_number 
    ? props.version1 
    : props.version2
})

const newerVersion = computed(() => {
  if (!props.version1 || !props.version2) return null
  return props.version1.version_number > props.version2.version_number 
    ? props.version1 
    : props.version2
})

// Size display
const olderSize = computed(() => formatSize(olderContent.value.length))
const newerSize = computed(() => formatSize(newerContent.value.length))

// Stats
const stats = computed(() => {
  const diff = computeDiff(olderContent.value, newerContent.value)
  let added = 0, removed = 0, unchanged = 0
  
  diff.forEach(part => {
    const lines = part.value.split('\n').length - 1 || 1
    if (part.added) added += lines
    else if (part.removed) removed += lines
    else unchanged += lines
  })
  
  return { added, removed, unchanged }
})

// Side-by-side diff
const sideBySideDiff = computed(() => {
  const older = []
  const newer = []
  
  const olderLines = olderContent.value.split('\n')
  const newerLines = newerContent.value.split('\n')
  
  const diff = computeLineDiff(olderLines, newerLines)
  
  let olderLineNum = 1
  let newerLineNum = 1
  
  diff.forEach(change => {
    if (change.type === 'unchanged') {
      change.lines.forEach(line => {
        older.push({ type: 'unchanged', lineNumber: olderLineNum++, marker: '', text: line })
        newer.push({ type: 'unchanged', lineNumber: newerLineNum++, marker: '', text: line })
      })
    } else if (change.type === 'removed') {
      change.lines.forEach(line => {
        older.push({ type: 'removed', lineNumber: olderLineNum++, marker: '-', text: line })
        newer.push({ type: 'empty', lineNumber: '', marker: '', text: '' })
      })
    } else if (change.type === 'added') {
      change.lines.forEach(line => {
        older.push({ type: 'empty', lineNumber: '', marker: '', text: '' })
        newer.push({ type: 'added', lineNumber: newerLineNum++, marker: '+', text: line })
      })
    } else if (change.type === 'modified') {
      // For modified lines, show removed on left, added on right
      const maxLen = Math.max(change.removed.length, change.added.length)
      for (let i = 0; i < maxLen; i++) {
        if (i < change.removed.length) {
          older.push({ 
            type: 'removed', 
            lineNumber: olderLineNum++, 
            marker: '-', 
            text: change.removed[i]
          })
        } else {
          older.push({ type: 'empty', lineNumber: '', marker: '', text: '' })
        }
        
        if (i < change.added.length) {
          newer.push({ 
            type: 'added', 
            lineNumber: newerLineNum++, 
            marker: '+', 
            text: change.added[i]
          })
        } else {
          newer.push({ type: 'empty', lineNumber: '', marker: '', text: '' })
        }
      }
    }
  })
  
  return { older, newer }
})

// Unified diff
const unifiedDiff = computed(() => {
  const lines = []
  const olderLines = olderContent.value.split('\n')
  const newerLines = newerContent.value.split('\n')
  
  const diff = computeLineDiff(olderLines, newerLines)
  
  let oldLineNum = 1
  let newLineNum = 1
  
  diff.forEach(change => {
    if (change.type === 'unchanged') {
      change.lines.forEach(line => {
        lines.push({ 
          type: 'unchanged', 
          oldLineNumber: oldLineNum++, 
          newLineNumber: newLineNum++, 
          marker: ' ', 
          text: line 
        })
      })
    } else if (change.type === 'removed') {
      change.lines.forEach(line => {
        lines.push({ 
          type: 'removed', 
          oldLineNumber: oldLineNum++, 
          newLineNumber: '', 
          marker: '-', 
          text: line 
        })
      })
    } else if (change.type === 'added') {
      change.lines.forEach(line => {
        lines.push({ 
          type: 'added', 
          oldLineNumber: '', 
          newLineNumber: newLineNum++, 
          marker: '+', 
          text: line 
        })
      })
    } else if (change.type === 'modified') {
      change.removed.forEach(line => {
        lines.push({ 
          type: 'removed', 
          oldLineNumber: oldLineNum++, 
          newLineNumber: '', 
          marker: '-', 
          text: line
        })
      })
      change.added.forEach(line => {
        lines.push({ 
          type: 'added', 
          oldLineNumber: '', 
          newLineNumber: newLineNum++, 
          marker: '+', 
          text: line
        })
      })
    }
  })
  
  return lines
})

// Load version content when showing
watch(() => props.show, async (isShowing) => {
  if (isShowing && props.version1 && props.version2) {
    await loadVersions()
  }
}, { immediate: true })

async function loadVersions() {
  isLoading.value = true
  error.value = null
  
  try {
    // Load both versions in parallel
    const [older, newer] = await Promise.all([
      loadVersionContent(olderVersion.value),
      loadVersionContent(newerVersion.value),
    ])
    
    olderContent.value = older
    newerContent.value = newer
  } catch (e) {
    console.error('Failed to load versions:', e)
    error.value = 'Failed to load version content'
  } finally {
    isLoading.value = false
  }
}

async function loadVersionContent(version) {
  if (!version) return ''
  
  try {
    const response = await collabVersionApi.get(props.documentUuid, version.version_number)
    
    // The content might be in different formats - try to extract text
    if (response.data?.content) {
      return extractTextFromContent(response.data.content)
    } else if (response.content) {
      return extractTextFromContent(response.content)
    } else if (response.data?.crdt_state) {
      // Try to decode CRDT state
      return extractTextFromCrdtState(response.data.crdt_state)
    } else if (response.crdt_state) {
      return extractTextFromCrdtState(response.crdt_state)
    }
    
    return `[Version ${version.version_number} content]`
  } catch (e) {
    console.error('Failed to load version:', e)
    return `[Failed to load version ${version.version_number}]`
  }
}

function extractTextFromCrdtState(crdtState) {
  // CRDT state is typically base64 encoded binary data
  // We can't easily decode it without the Y.js library
  // For now, show a summary
  try {
    if (typeof crdtState === 'string' && crdtState.length > 0) {
      // Try to extract readable content
      const decoded = atob(crdtState)
      // Look for readable text patterns
      const textMatches = decoded.match(/[\x20-\x7E]{10,}/g) || []
      if (textMatches.length > 0) {
        return textMatches.join('\n')
      }
    }
  } catch (e) {
    // Ignore decoding errors
  }
  return `[CRDT data - ${formatSize(crdtState?.length || 0)}]`
}

function extractTextFromContent(content) {
  if (typeof content === 'string') {
    // Try to parse if it's JSON
    try {
      const parsed = JSON.parse(content)
      return extractTextFromStructure(parsed)
    } catch {
      return content
    }
  }
  
  if (typeof content === 'object') {
    return extractTextFromStructure(content)
  }
  
  return String(content)
}

function extractTextFromStructure(data) {
  if (!data) return ''
  
  // Check if it's presentation data (has slides)
  if (data.slides || Array.isArray(data)) {
    return extractTextFromPresentation(data)
  }
  
  // Check if it's ProseMirror document data
  if (data.type === 'doc' || data.content) {
    return extractTextFromProseMirror(data)
  }
  
  // Try to stringify nicely
  return JSON.stringify(data, null, 2)
}

function extractTextFromPresentation(data) {
  const slides = data.slides || data
  if (!Array.isArray(slides)) {
    return JSON.stringify(data, null, 2)
  }
  
  let text = ''
  
  slides.forEach((slide, slideIndex) => {
    text += `=== Slide ${slideIndex + 1} ===\n`
    
    // Extract slide background info
    if (slide.background) {
      text += `  Background: ${slide.background.type || 'solid'}`
      if (slide.background.color) text += ` (${slide.background.color})`
      text += '\n'
    }
    
    // Extract objects
    const objects = slide.objects || []
    objects.forEach((obj) => {
      if (obj.type === 'text') {
        // Strip ALL HTML tags from content
        const plainText = obj.content ? stripHtml(obj.content).trim() : ''
        if (plainText) {
          text += `  ${plainText}\n`
        }
      } else if (obj.type === 'shape') {
        text += `  [Shape: ${obj.shapeType || 'rectangle'}]\n`
      } else if (obj.type === 'image') {
        text += `  [Image]\n`
      }
    })
    
    text += '\n'
  })
  
  return text.trim()
}

// Helper to strip HTML tags completely
function stripHtml(html) {
  if (!html) return ''
  // Remove HTML tags
  let text = html.replace(/<[^>]*>/g, '')
  // Decode HTML entities
  text = text.replace(/&nbsp;/g, ' ')
  text = text.replace(/&amp;/g, '&')
  text = text.replace(/&lt;/g, '<')
  text = text.replace(/&gt;/g, '>')
  text = text.replace(/&quot;/g, '"')
  text = text.replace(/&#039;/g, "'")
  text = text.replace(/&#x27;/g, "'")
  // Clean up multiple spaces and newlines
  text = text.replace(/\s+/g, ' ')
  return text.trim()
}

function extractTextFromProseMirror(doc) {
  if (!doc) return ''
  
  let text = ''
  
  function traverse(node) {
    if (node.text) {
      text += node.text
    }
    
    if (node.content) {
      node.content.forEach((child, i) => {
        traverse(child)
        // Add newlines between block elements
        if (child.type === 'paragraph' || child.type?.startsWith('heading')) {
          text += '\n'
        }
      })
    }
  }
  
  traverse(doc)
  return text.trim()
}

// Simple line diff algorithm
function computeLineDiff(oldLines, newLines) {
  const result = []
  let i = 0, j = 0
  
  while (i < oldLines.length || j < newLines.length) {
    if (i >= oldLines.length) {
      // Remaining new lines are additions
      result.push({ type: 'added', lines: newLines.slice(j) })
      break
    }
    
    if (j >= newLines.length) {
      // Remaining old lines are removals
      result.push({ type: 'removed', lines: oldLines.slice(i) })
      break
    }
    
    if (oldLines[i] === newLines[j]) {
      // Unchanged line
      const unchangedLines = []
      while (i < oldLines.length && j < newLines.length && oldLines[i] === newLines[j]) {
        unchangedLines.push(oldLines[i])
        i++
        j++
      }
      result.push({ type: 'unchanged', lines: unchangedLines })
    } else {
      // Find next matching line
      const oldLookAhead = findNextMatch(oldLines, i, newLines[j])
      const newLookAhead = findNextMatch(newLines, j, oldLines[i])
      
      if (oldLookAhead !== -1 && (newLookAhead === -1 || oldLookAhead <= newLookAhead)) {
        // Lines were removed from old
        result.push({ type: 'removed', lines: oldLines.slice(i, i + oldLookAhead) })
        i += oldLookAhead
      } else if (newLookAhead !== -1) {
        // Lines were added to new
        result.push({ type: 'added', lines: newLines.slice(j, j + newLookAhead) })
        j += newLookAhead
      } else {
        // Lines were modified
        result.push({ type: 'modified', removed: [oldLines[i]], added: [newLines[j]] })
        i++
        j++
      }
    }
  }
  
  return result
}

function findNextMatch(lines, start, target) {
  for (let k = 0; k < 5 && start + k < lines.length; k++) {
    if (lines[start + k] === target) return k
  }
  return -1
}

// Simple line-level diff for stats
function computeDiff(oldStr, newStr) {
  const parts = []
  const oldLines = oldStr.split('\n')
  const newLines = newStr.split('\n')
  
  oldLines.forEach((line, i) => {
    if (newLines[i] === line) {
      parts.push({ value: line + '\n' })
    } else if (newLines[i]) {
      parts.push({ value: line + '\n', removed: true })
      parts.push({ value: newLines[i] + '\n', added: true })
    } else {
      parts.push({ value: line + '\n', removed: true })
    }
  })
  
  // Remaining new lines
  for (let i = oldLines.length; i < newLines.length; i++) {
    parts.push({ value: newLines[i] + '\n', added: true })
  }
  
  return parts
}


function syncScroll(source) {
  if (isSyncingScroll.value) return
  
  isSyncingScroll.value = true
  
  nextTick(() => {
    const sourceEl = source === 'older' ? olderScrollRef.value : newerScrollRef.value
    const targetEl = source === 'older' ? newerScrollRef.value : olderScrollRef.value
    
    if (sourceEl && targetEl) {
      targetEl.scrollTop = sourceEl.scrollTop
    }
    
    setTimeout(() => {
      isSyncingScroll.value = false
    }, 50)
  })
}

function formatDate(dateStr) {
  if (!dateStr) return 'Unknown'
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  
  if (diff < 3600000) {
    const mins = Math.floor(diff / 60000)
    return mins <= 1 ? 'Just now' : `${mins} min ago`
  }
  
  if (diff < 86400000) {
    const hours = Math.floor(diff / 3600000)
    return `${hours} hour${hours > 1 ? 's' : ''} ago`
  }
  
  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

async function restoreVersion(version) {
  if (!version) return
  
  emit('restore', version)
  emit('close')
}
</script>

<style scoped>
/* ============================================
   VERSION COMPARE MODAL - COMPLETE ISOLATION
   ============================================ */

/* Full-screen overlay with complete isolation */
.compare-overlay {
  position: fixed;
  inset: 0;
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  /* Complete isolation */
  isolation: isolate;
  contain: strict;
}

/* Modal container */
.compare-modal {
  --vc-primary: rgb(var(--color-primary-500, 99 102 241));
  --vc-primary-hover: rgb(var(--color-primary-600, 79 70 229));
  --vc-success: #10b981;
  --vc-danger: #ef4444;
  --vc-warning: #f59e0b;
  
  position: relative;
  width: 100%;
  max-width: 1400px;
  height: 90vh;
  max-height: 900px;
  display: flex;
  flex-direction: column;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
  /* Ensure solid background, no bleed-through */
  contain: content;
}

/* Light mode defaults */
.compare-modal {
  --vc-bg: #ffffff;
  --vc-bg-secondary: #f8fafc;
  --vc-bg-tertiary: #f1f5f9;
  --vc-border: #e2e8f0;
  --vc-text: #1e293b;
  --vc-text-secondary: #64748b;
  --vc-text-muted: #94a3b8;
  --vc-line-added-bg: rgba(16, 185, 129, 0.12);
  --vc-line-removed-bg: rgba(239, 68, 68, 0.12);
  --vc-line-empty-bg: #f1f5f9;
  --vc-btn-bg: #e2e8f0;
  --vc-btn-bg-hover: #cbd5e1;
  background: var(--vc-bg);
  color: var(--vc-text);
}

/* Dark mode */
:root.dark .compare-modal,
.dark .compare-modal {
  --vc-bg: #0f172a;
  --vc-bg-secondary: #1e293b;
  --vc-bg-tertiary: #334155;
  --vc-border: #334155;
  --vc-text: #f1f5f9;
  --vc-text-secondary: #94a3b8;
  --vc-text-muted: #64748b;
  --vc-line-added-bg: rgba(16, 185, 129, 0.15);
  --vc-line-removed-bg: rgba(239, 68, 68, 0.15);
  --vc-line-empty-bg: #1e293b;
  --vc-btn-bg: #334155;
  --vc-btn-bg-hover: #475569;
}

/* ============================================
   HEADER
   ============================================ */
.compare-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 24px;
  background: var(--vc-bg-secondary);
  border-bottom: 1px solid var(--vc-border);
  flex-shrink: 0;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.header-icon {
  font-size: 28px;
  color: var(--vc-primary);
}

.header-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--vc-text);
  margin: 0;
  line-height: 1.3;
}

.header-subtitle {
  font-size: 14px;
  color: var(--vc-text-secondary);
  margin: 0;
  line-height: 1.3;
}

.header-center {
  display: flex;
  align-items: center;
  gap: 12px;
}

.arrow-icon {
  color: var(--vc-text-muted);
  font-size: 20px;
}

.version-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 9999px;
  font-size: 13px;
  font-weight: 500;
}

.version-pill.older {
  background: rgba(245, 158, 11, 0.15);
  color: var(--vc-warning);
}

.version-pill.newer {
  background: rgba(16, 185, 129, 0.15);
  color: var(--vc-success);
}

.header-right {
  display: flex;
  align-items: center;
  gap: 16px;
}

.view-toggle {
  display: flex;
  background: var(--vc-btn-bg);
  border-radius: 9999px;
  padding: 3px;
}

.toggle-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: none;
  background: transparent;
  color: var(--vc-text-secondary);
  font-size: 13px;
  font-weight: 500;
  border-radius: 9999px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.toggle-btn:hover {
  color: var(--vc-text);
}

.toggle-btn.active {
  background: var(--vc-primary);
  color: white;
}

.close-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  background: transparent;
  color: var(--vc-text-secondary);
  border-radius: 9999px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.close-btn:hover {
  background: var(--vc-btn-bg);
  color: var(--vc-text);
}

/* ============================================
   STATS BAR
   ============================================ */
.stats-bar {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 10px 24px;
  background: var(--vc-bg-secondary);
  border-bottom: 1px solid var(--vc-border);
  font-size: 13px;
  flex-shrink: 0;
}

.stat-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.stat-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.stat-dot.added {
  background: var(--vc-success);
}

.stat-dot.removed {
  background: var(--vc-danger);
}

.stat-label {
  color: var(--vc-text);
}

.stat-item.unchanged {
  color: var(--vc-text-muted);
}


/* ============================================
   LOADING / ERROR STATES
   ============================================ */
.loading-state,
.error-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  background: var(--vc-bg);
}

.loading-icon {
  font-size: 32px;
  color: var(--vc-primary);
}

.loading-text,
.error-text {
  color: var(--vc-text-secondary);
}

.error-icon {
  font-size: 32px;
  color: var(--vc-danger);
}

.retry-btn {
  margin-top: 8px;
  padding: 8px 20px;
  background: var(--vc-primary);
  color: white;
  border: none;
  border-radius: 9999px;
  cursor: pointer;
  font-weight: 500;
  font-size: 14px;
  transition: all 0.15s ease;
}

.retry-btn:hover {
  background: var(--vc-primary-hover);
}

.spin {
  animation: vc-spin 1s linear infinite;
}

@keyframes vc-spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* ============================================
   DIFF CONTAINER
   ============================================ */
.diff-container {
  flex: 1;
  display: flex;
  overflow: hidden;
  background: var(--vc-bg);
  min-height: 0;
}

.side-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
}

.side-panel.older-panel {
  border-right: 1px solid var(--vc-border);
}

.unified-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
}

.panel-label {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  background: var(--vc-bg-secondary);
  border-bottom: 1px solid var(--vc-border);
  font-size: 13px;
  color: var(--vc-text);
  font-weight: 500;
  flex-shrink: 0;
}

.panel-date {
  margin-left: auto;
  color: var(--vc-text-muted);
  font-weight: 400;
}

.label-badge {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 4px;
}

.label-badge.older {
  background: rgba(245, 158, 11, 0.15);
  color: var(--vc-warning);
}

.label-badge.newer {
  background: rgba(16, 185, 129, 0.15);
  color: var(--vc-success);
}

.label-version {
  color: var(--vc-text);
}

/* ============================================
   DIFF CONTENT - CLEAN CODE VIEW
   ============================================ */
.panel-content {
  flex: 1;
  overflow-y: auto;
  overflow-x: auto;
  background: var(--vc-bg);
  /* Clean monospace styling */
  font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', 'Monaco', monospace;
  font-size: 13px;
  line-height: 1.7;
}

.diff-line {
  display: flex;
  min-height: 26px;
  border: none;
  /* Reset any inherited styles */
  margin: 0;
  padding: 0;
}

.diff-line.unchanged {
  background: var(--vc-bg);
}

.diff-line.added {
  background: var(--vc-line-added-bg);
}

.diff-line.removed {
  background: var(--vc-line-removed-bg);
}

.diff-line.empty {
  background: var(--vc-line-empty-bg);
}

.line-number {
  width: 50px;
  padding: 2px 10px;
  text-align: right;
  color: var(--vc-text-muted);
  background: var(--vc-bg-tertiary);
  flex-shrink: 0;
  user-select: none;
  font-size: 12px;
  border: none;
}

.unified-panel .line-number {
  width: 45px;
}

.unified-panel .line-number.old {
  border-right: 1px solid var(--vc-border);
}

.line-marker {
  width: 24px;
  text-align: center;
  flex-shrink: 0;
  font-weight: 700;
  font-size: 14px;
  padding: 2px 0;
}

.diff-line.added .line-marker {
  color: var(--vc-success);
}

.diff-line.removed .line-marker {
  color: var(--vc-danger);
}

.line-content {
  flex: 1;
  padding: 2px 16px;
  white-space: pre;
  color: var(--vc-text);
  min-width: 0;
  /* Override any inherited editor styles */
  background: transparent !important;
  border: none !important;
  outline: none !important;
  box-shadow: none !important;
}

.diff-line.added .line-content {
  color: var(--vc-success);
}

.diff-line.removed .line-content {
  color: var(--vc-danger);
}


/* ============================================
   FOOTER
   ============================================ */
.compare-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 24px;
  background: var(--vc-bg-secondary);
  border-top: 1px solid var(--vc-border);
  flex-shrink: 0;
}

.footer-info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.info-icon {
  font-size: 16px;
  color: var(--vc-text-muted);
}

.info-text {
  font-size: 14px;
  color: var(--vc-text-secondary);
}

.footer-actions {
  display: flex;
  gap: 12px;
}

.restore-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  font-size: 13px;
  font-weight: 500;
  border: 1px solid var(--vc-border);
  border-radius: 9999px;
  background: var(--vc-bg);
  color: var(--vc-text);
  cursor: pointer;
  transition: all 0.15s ease;
}

.restore-btn:hover {
  background: var(--vc-btn-bg);
}

.restore-btn.older:hover {
  border-color: var(--vc-warning);
  background: rgba(245, 158, 11, 0.1);
}

.restore-btn.newer:hover {
  border-color: var(--vc-success);
  background: rgba(16, 185, 129, 0.1);
}

/* ============================================
   TRANSITIONS
   ============================================ */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.25s ease;
}

.modal-fade-enter-active .compare-modal,
.modal-fade-leave-active .compare-modal {
  transition: transform 0.25s ease, opacity 0.25s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.modal-fade-enter-from .compare-modal,
.modal-fade-leave-to .compare-modal {
  transform: scale(0.96) translateY(10px);
  opacity: 0;
}

/* ============================================
   SCROLLBAR STYLING
   ============================================ */
.panel-content::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.panel-content::-webkit-scrollbar-track {
  background: var(--vc-bg-tertiary);
}

.panel-content::-webkit-scrollbar-thumb {
  background: var(--vc-border);
  border-radius: 4px;
}

.panel-content::-webkit-scrollbar-thumb:hover {
  background: var(--vc-text-muted);
}
</style>
