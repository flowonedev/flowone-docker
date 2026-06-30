<template>
  <div class="log-viewer-wrapper">
    <!-- Split view: Logs + AI Panel -->
    <div :class="['flex gap-4', aiPanelOpen ? 'split-view' : '']">
      <!-- Logs Panel -->
      <div :class="aiPanelOpen ? 'w-1/2' : 'w-full'">
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-surface-400">article</span>
              <h3 class="font-medium text-surface-100">
                {{ title }} 
                <span v-if="lines.length" class="text-sm font-normal text-surface-500">({{ lines.length }} lines)</span>
              </h3>
            </div>
            <div class="flex items-center gap-2">
              <!-- Filter Badge -->
              <div v-if="activeFilter" class="badge badge-info mr-2">Filtered: {{ activeFilter }}</div>
              
              <!-- AI Panel Toggle -->
              <button 
                @click="toggleAiPanel" 
                :class="['btn-sm', aiPanelOpen ? 'btn-primary' : 'btn-secondary']"
                title="AI Log Analyzer"
              >
                <span class="material-symbols-rounded text-sm">psychology</span>
                AI Analyzer
              </button>
              
              <!-- Selection Actions -->
              <template v-if="aiPanelOpen">
                <button @click="selectAllErrors" class="btn-secondary btn-xs" title="Select all errors">
                  <span class="material-symbols-rounded text-sm">select_all</span>
                  Select Errors
                </button>
                <button v-if="selectedLines.length > 0" @click="clearSelection" class="btn-secondary btn-xs" title="Clear selection">
                  <span class="material-symbols-rounded text-sm">deselect</span>
                </button>
                <button v-if="selectedLines.length > 0" @click="sendSelectedToAi" class="btn-primary btn-xs" title="Send to AI">
                  <span class="material-symbols-rounded text-sm">send</span>
                  Analyze ({{ selectedLines.length }})
                </button>
              </template>
            </div>
          </div>
          
          <!-- Loading -->
          <div v-if="loading" class="p-8 text-center">
            <span class="spinner"></span>
            <p class="text-surface-500 mt-2">Loading logs...</p>
          </div>
          
          <!-- Log Lines -->
          <div v-else-if="filteredLines.length" class="log-container">
            <div class="max-h-[600px] overflow-y-auto">
              <div 
                v-for="(line, idx) in filteredLines"
                :key="idx"
                :class="[
                  'log-line',
                  getLogLevelClass(line),
                  { 'selected': selectedLines.includes(idx), 'selectable': aiPanelOpen }
                ]"
                @click="aiPanelOpen && toggleLineSelection(idx)"
              >
                <!-- Checkbox -->
                <div v-if="aiPanelOpen" class="log-checkbox">
                  <input 
                    type="checkbox" 
                    :checked="selectedLines.includes(idx)"
                    @click.stop
                    @change="toggleLineSelection(idx)"
                    class="checkbox-sm"
                  />
                </div>
                
                <!-- Level Badge -->
                <span :class="['log-level-badge', getLogLevelClass(line)]">
                  {{ getLogLevel(line) }}
                </span>
                
                <!-- Timestamp -->
                <span class="log-timestamp">{{ getTimestamp(line) }}</span>
                
                <!-- Message -->
                <span class="log-message">{{ getMessage(line) }}</span>
              </div>
            </div>
          </div>
          
          <!-- Empty State -->
          <div v-else class="p-12 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block">article</span>
            <p>No log entries found</p>
          </div>
        </div>
      </div>
      
      <!-- AI Assistant Panel -->
      <div v-if="aiPanelOpen" class="w-1/2">
        <div class="card h-full flex flex-col ai-panel">
          <div class="card-header flex items-center justify-between border-b border-surface-700">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-400">psychology</span>
              <h3 class="font-medium text-white">AI Log Analyzer</h3>
            </div>
            <button @click="toggleAiPanel" class="p-1 hover:bg-surface-700 rounded">
              <span class="material-symbols-rounded text-surface-400">close</span>
            </button>
          </div>
          
          <!-- Messages -->
          <div class="flex-1 overflow-y-auto p-4 space-y-4 min-h-[400px] max-h-[500px]">
            <div v-if="aiMessages.length === 0" class="text-center py-8">
              <span class="material-symbols-rounded text-4xl text-primary-400/50 mb-3 block">psychology</span>
              <p class="text-surface-400 text-sm mb-2">AI Log Analyzer</p>
              <p class="text-surface-500 text-xs mb-4">Select log entries and click "Analyze" to get help</p>
              <div class="space-y-2 text-xs text-surface-500">
                <p>The AI can:</p>
                <ul class="text-left max-w-[200px] mx-auto space-y-1">
                  <li class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm text-green-400">check</span>
                    Explain error causes
                  </li>
                  <li class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm text-green-400">check</span>
                    Suggest fix commands
                  </li>
                  <li class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm text-green-400">check</span>
                    Debug PHP errors
                  </li>
                  <li class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm text-green-400">check</span>
                    Identify patterns
                  </li>
                </ul>
              </div>
            </div>
            
            <template v-else>
              <div 
                v-for="(msg, idx) in aiMessages"
                :key="idx"
                :class="['ai-message', msg.role === 'user' ? 'user' : 'assistant']"
              >
                <div v-if="msg.role === 'assistant'" class="ai-message-content" v-html="renderMarkdown(msg.content)"></div>
                <div v-else class="ai-message-content">{{ msg.content }}</div>
              </div>
            </template>
            
            <div v-if="aiTyping" class="ai-message assistant">
              <div class="ai-typing">
                <span class="material-symbols-rounded animate-spin text-primary-400">sync</span>
                <span>AI is analyzing...</span>
              </div>
            </div>
            <div ref="aiMessagesEnd"></div>
          </div>
          
          <!-- Input -->
          <div class="ai-input-container">
            <input
              v-model="aiInput"
              @keydown.enter="sendAiMessage"
              :disabled="aiTyping"
              placeholder="Ask about errors, request commands..."
              class="ai-input"
            />
            <button 
              @click="sendAiMessage" 
              :disabled="!aiInput.trim() || aiTyping"
              class="ai-send-btn"
            >
              <span class="material-symbols-rounded">send</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { marked } from 'marked'
import aiHelper from '@/services/aiHelper'

const props = defineProps({
  lines: { type: Array, default: () => [] },
  title: { type: String, default: 'Logs' },
  service: { type: String, default: 'server' },
  loading: { type: Boolean, default: false },
  filter: { type: String, default: '' },
})

const emit = defineEmits(['refresh'])

// State
const aiPanelOpen = ref(false)
const selectedLines = ref([])
const aiMessages = ref([])
const aiInput = ref('')
const aiTyping = ref(false)
const aiConversationId = ref(null)
const aiMessagesEnd = ref(null)
const activeFilter = ref('')

// Filter lines based on active filter
const filteredLines = computed(() => {
  if (!activeFilter.value) return props.lines
  const filter = activeFilter.value.toLowerCase()
  return props.lines.filter(line => line.toLowerCase().includes(filter))
})

// Log parsing helpers
const getLogLevel = (line) => {
  const lower = line.toLowerCase()
  if (lower.includes('[error]') || lower.includes('error:') || lower.includes('fatal')) return 'ERR'
  if (lower.includes('[warn]') || lower.includes('warning:')) return 'WARN'
  if (lower.includes('[info]') || lower.includes('info:')) return 'INF'
  if (lower.includes('[debug]') || lower.includes('debug:')) return 'DBG'
  return 'INF'
}

const getLogLevelClass = (line) => {
  const level = getLogLevel(line)
  switch (level) {
    case 'ERR': return 'level-error'
    case 'WARN': return 'level-warn'
    case 'DBG': return 'level-debug'
    default: return 'level-info'
  }
}

const getTimestamp = (line) => {
  // Try to extract timestamp from common formats
  const patterns = [
    /^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/,
    /^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/,
    /^\[(\d{2}\/\w{3}\/\d{4}:\d{2}:\d{2}:\d{2})/,
  ]
  
  for (const pattern of patterns) {
    const match = line.match(pattern)
    if (match) return match[1]
  }
  return ''
}

const getMessage = (line) => {
  // Remove timestamp and clean up message
  let msg = line
  const timestamp = getTimestamp(line)
  if (timestamp) {
    msg = line.substring(line.indexOf(timestamp) + timestamp.length).trim()
  }
  // Remove common prefixes
  msg = msg.replace(/^\[?\w+\]?\s*:?\s*/, '')
  return msg.substring(0, 500) // Limit length
}

// Selection
const toggleLineSelection = (idx) => {
  const index = selectedLines.value.indexOf(idx)
  if (index > -1) {
    selectedLines.value.splice(index, 1)
  } else {
    selectedLines.value.push(idx)
  }
}

const selectAllErrors = () => {
  selectedLines.value = filteredLines.value
    .map((line, idx) => getLogLevel(line) === 'ERR' ? idx : -1)
    .filter(idx => idx !== -1)
}

const clearSelection = () => {
  selectedLines.value = []
}

// AI Panel
const toggleAiPanel = () => {
  aiPanelOpen.value = !aiPanelOpen.value
  if (!aiPanelOpen.value) {
    clearSelection()
  }
}

const sendSelectedToAi = async () => {
  if (selectedLines.value.length === 0) return
  
  const selectedLogs = selectedLines.value
    .sort((a, b) => a - b)
    .map(idx => filteredLines.value[idx])
    .filter(Boolean)
  
  const userMessage = `Analyze these ${selectedLogs.length} log entries`
  aiMessages.value.push({ role: 'user', content: userMessage })
  scrollToAiBottom()
  
  aiTyping.value = true
  try {
    // Create or reuse conversation
    if (!aiConversationId.value) {
      const conversation = await aiHelper.createConversation(
        `Log Analysis: ${props.service}`,
        'logs',
        { service: props.service }
      )
      aiConversationId.value = conversation.id
    }
    
    const response = await aiHelper.analyzeLogs(selectedLogs, props.service)
    aiConversationId.value = response.conversation_id
    
    aiMessages.value.push({ 
      role: 'assistant', 
      content: response.message || 'Analysis complete.' 
    })
    
    clearSelection()
  } catch (e) {
    console.error('AI analysis error:', e)
    aiMessages.value.push({ 
      role: 'assistant', 
      content: 'Error analyzing logs. Please check AI Helper configuration in Settings.' 
    })
  } finally {
    aiTyping.value = false
    scrollToAiBottom()
  }
}

const sendAiMessage = async () => {
  if (!aiInput.value.trim() || aiTyping.value) return
  
  const message = aiInput.value.trim()
  aiInput.value = ''
  
  aiMessages.value.push({ role: 'user', content: message })
  scrollToAiBottom()
  
  aiTyping.value = true
  try {
    if (!aiConversationId.value) {
      const conversation = await aiHelper.createConversation(
        `Log Analysis: ${props.service}`,
        'logs',
        { service: props.service }
      )
      aiConversationId.value = conversation.id
    }
    
    const context = {
      type: 'log_analysis',
      service: props.service,
      recent_logs: props.lines.slice(-50)
    }
    
    const response = await aiHelper.sendMessage(aiConversationId.value, message, context)
    aiMessages.value.push({ 
      role: 'assistant', 
      content: response.message || 'I could not process that request.' 
    })
  } catch (e) {
    console.error('AI message error:', e)
    aiMessages.value.push({ 
      role: 'assistant', 
      content: 'Error communicating with AI. Please try again.' 
    })
  } finally {
    aiTyping.value = false
    scrollToAiBottom()
  }
}

const scrollToAiBottom = () => {
  nextTick(() => {
    if (aiMessagesEnd.value) {
      aiMessagesEnd.value.scrollIntoView({ behavior: 'smooth' })
    }
  })
}

// Markdown rendering
marked.setOptions({ breaks: true, gfm: true, headerIds: false, mangle: false })

const renderMarkdown = (text) => {
  try {
    let processed = text
      .replace(/```[\w]*\n?/g, '')
      .replace(/\*\*Summary:?\*\*/gi, '<div class="ai-section ai-summary"><span class="material-symbols-rounded">summarize</span><strong>Summary</strong></div>')
      .replace(/\*\*Issues Found:?\*\*/gi, '<div class="ai-section ai-issues"><span class="material-symbols-rounded">error</span><strong>Issues Found</strong></div>')
      .replace(/\*\*Recommended Actions:?\*\*/gi, '<div class="ai-section ai-actions"><span class="material-symbols-rounded">build</span><strong>Recommended Actions</strong></div>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>')
    
    let html = processed
      .split('\n')
      .map(line => line.trim())
      .filter(line => line)
      .map(line => {
        if (line.startsWith('- ') || line.match(/^\d+\.\s/)) {
          return `<li>${line.replace(/^[-\d.]+\s*/, '')}</li>`
        }
        if (line.startsWith('<div class="ai-section')) return line
        return `<p>${line}</p>`
      })
      .join('')
    
    html = html.replace(/(<li>.*?<\/li>)+/g, '<ul>$&</ul>')
    return html
  } catch (e) {
    return text.replace(/\n/g, '<br>')
  }
}

// Reset AI on service change
watch(() => props.service, () => {
  aiMessages.value = []
  aiConversationId.value = null
  clearSelection()
})

// Set filter from prop
watch(() => props.filter, (val) => {
  activeFilter.value = val
})
</script>

<style scoped>
.log-viewer-wrapper { width: 100%; }

.split-view { display: flex; gap: 1rem; }

.card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
}

.card-header {
  padding: 12px 16px;
  border-bottom: 1px solid var(--color-border);
}

.log-container {
  background: #0f172a;
  border-radius: 0 0 12px 12px;
}

.log-line {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 6px 12px;
  font-family: ui-monospace, monospace;
  font-size: 12px;
  border-bottom: 1px solid #1e293b;
  transition: background 0.15s;
}

.log-line:last-child { border-bottom: none; }
.log-line.selectable { cursor: pointer; }
.log-line.selectable:hover { background: rgba(99, 102, 241, 0.1); }
.log-line.selected { background: rgba(99, 102, 241, 0.2); }

.log-checkbox { flex-shrink: 0; padding-top: 2px; }

.checkbox-sm {
  width: 14px;
  height: 14px;
  accent-color: #6366f1;
}

.log-level-badge {
  flex-shrink: 0;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
}

.level-error .log-level-badge, .log-level-badge.level-error { background: rgba(239, 68, 68, 0.2); color: #f87171; }
.level-warn .log-level-badge, .log-level-badge.level-warn { background: rgba(251, 191, 36, 0.2); color: #fbbf24; }
.level-info .log-level-badge, .log-level-badge.level-info { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
.level-debug .log-level-badge, .log-level-badge.level-debug { background: rgba(148, 163, 184, 0.2); color: #94a3b8; }

.log-timestamp {
  flex-shrink: 0;
  color: #64748b;
  min-width: 140px;
}

.log-message {
  flex: 1;
  color: #e2e8f0;
  word-break: break-word;
}

.level-error .log-message { color: #fca5a5; }
.level-warn .log-message { color: #fcd34d; }

/* AI Panel */
.ai-panel {
  background: #070b14;
  border-color: #1e293b;
}

.ai-message { margin-bottom: 12px; max-width: 90%; }
.ai-message.user { margin-left: auto; }

.ai-message.user .ai-message-content {
  background: #3730a3;
  color: #e0e7ff;
  padding: 10px 14px;
  border-radius: 12px 12px 4px 12px;
  font-size: 13px;
}

.ai-message.assistant .ai-message-content {
  background: #0c1222;
  color: #e2e8f0;
  padding: 12px 16px;
  border-radius: 12px 12px 12px 4px;
  font-size: 13px;
  line-height: 1.5;
  border: 1px solid #1e293b;
}

.ai-message-content :deep(p) { margin: 0 0 8px 0; }
.ai-message-content :deep(p:last-child) { margin-bottom: 0; }
.ai-message-content :deep(code), .ai-message-content :deep(.inline-code) { background: #1e293b; padding: 2px 6px; border-radius: 4px; font-size: 12px; color: #93c5fd; }
.ai-message-content :deep(ul), .ai-message-content :deep(ol) { margin: 8px 0; padding-left: 20px; }
.ai-message-content :deep(li) { margin: 4px 0; }

.ai-message-content :deep(.ai-section) {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 12px 0 8px 0;
  padding-bottom: 4px;
  border-bottom: 1px solid #334155;
  font-size: 12px;
  font-weight: 600;
}

.ai-message-content :deep(.ai-summary) { color: #60a5fa; border-color: #60a5fa; }
.ai-message-content :deep(.ai-issues) { color: #f87171; border-color: #f87171; }
.ai-message-content :deep(.ai-actions) { color: #4ade80; border-color: #4ade80; }

.ai-typing {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: #0c1222;
  border: 1px solid #1e293b;
  border-radius: 12px;
  font-size: 13px;
  color: #94a3b8;
}

.ai-input-container {
  display: flex;
  gap: 8px;
  padding: 12px 16px;
  background: #0a0f1a;
  border-top: 1px solid #1e293b;
}

.ai-input {
  flex: 1;
  padding: 10px 14px;
  font-size: 13px;
  color: #e2e8f0;
  background: #070b14;
  border: 1px solid #1e293b;
  border-radius: 8px;
  outline: none;
}

.ai-input:focus { border-color: #8b5cf6; }
.ai-input::placeholder { color: #64748b; }

.ai-send-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  color: white;
  background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
  border: none;
  border-radius: 8px;
  cursor: pointer;
}

.ai-send-btn:hover:not(:disabled) { background: linear-gradient(135deg, #7c3aed 0%, #9333ea 100%); }
.ai-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.badge {
  padding: 4px 8px;
  font-size: 11px;
  font-weight: 500;
  border-radius: 9999px;
}

.badge-info { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }

.btn-sm {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  font-size: 13px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-xs {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  font-size: 12px;
  border-radius: 4px;
  cursor: pointer;
}

.btn-secondary {
  color: var(--color-text-muted);
  background: transparent;
  border: 1px solid var(--color-border);
}

.btn-secondary:hover { color: var(--color-text); background: var(--color-bg); }

.btn-primary {
  color: white;
  background: var(--color-primary);
  border: 1px solid var(--color-primary);
}

.btn-primary:hover { background: var(--color-primary-hover); }

.spinner {
  width: 24px;
  height: 24px;
  border: 3px solid var(--color-border);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin: 0 auto;
}

.animate-spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

