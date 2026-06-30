<template>
  <div class="config-editor-wrapper">
    <!-- Zen Mode Overlay -->
    <Teleport to="body">
      <Transition name="zen-fade">
        <div v-if="isZenMode" class="zen-overlay">
          <div class="zen-header">
            <div class="zen-title">
              <span class="material-symbols-rounded">code</span>
              <span>{{ zenTitle || 'Configuration Editor' }}</span>
            </div>
            <!-- Config file selector in Zen Mode -->
            <div v-if="configFiles.length > 0" class="zen-file-selector">
              <select 
                :value="selectedFile" 
                @change="$emit('file-change', $event.target.value)"
                class="zen-file-dropdown"
              >
                <option v-for="file in configFiles" :key="file.path" :value="file.path">
                  {{ file.label }}
                </option>
              </select>
            </div>
            <div class="zen-header-actions">
              <button 
                v-if="service" 
                @click="$emit('open-guide')" 
                class="zen-guide-btn" 
                title="Best Practices Guide"
              >
                <span class="material-symbols-rounded">lightbulb</span>
                <span>Guide</span>
              </button>
              <button 
                v-if="service && aiEnabled" 
                @click="toggleAiPanel" 
                :class="['zen-ai-btn', aiPanelOpen && 'active']" 
                title="AI Config Assistant"
              >
                <span class="material-symbols-rounded">psychology</span>
                <span>AI Assistant</span>
              </button>
              <button @click="toggleZenMode" class="zen-minimize-btn" title="Exit Zen Mode (Esc)">
                <span class="material-symbols-rounded">close_fullscreen</span>
                <span>Minimize</span>
              </button>
            </div>
          </div>
          <div class="zen-content" :class="{ 'split-view': aiPanelOpen }">
            <div class="zen-editor-container" :style="aiPanelOpen ? { width: editorWidth + '%' } : {}">
              <div ref="zenEditorContainer" class="zen-editor"></div>
            </div>
            
            <!-- Resize Handle -->
            <div 
              v-if="aiPanelOpen" 
              class="resize-handle"
              @mousedown="startResize"
              title="Drag to resize"
            >
              <div class="resize-handle-grip"></div>
            </div>
            
            <!-- Context Menu for selected text -->
            <div 
              v-if="showContextMenu" 
              class="editor-context-menu"
              :style="{ left: contextMenuPos.x + 'px', top: contextMenuPos.y + 'px' }"
            >
              <button @click="askAboutSelection" class="context-menu-item">
                <span class="material-symbols-rounded">psychology</span>
                Ask AI about this
              </button>
              <button @click="askIfCorrect" class="context-menu-item">
                <span class="material-symbols-rounded">check_circle</span>
                Is this correct?
              </button>
              <button @click="askToImprove" class="context-menu-item">
                <span class="material-symbols-rounded">auto_fix_high</span>
                How to improve?
              </button>
            </div>
            
            <!-- AI Assistant Panel -->
            <div v-if="aiPanelOpen" class="zen-ai-panel" :style="{ width: (100 - editorWidth) + '%' }">
              <div class="ai-panel-header">
                <div class="ai-panel-title">
                  <span class="material-symbols-rounded">psychology</span>
                  <span>AI Config Assistant</span>
                </div>
                <div class="ai-panel-actions">
                  <button 
                    @click="loadConfigToAi" 
                    :disabled="aiLoading"
                    class="ai-load-btn"
                    :title="configLoaded ? 'Config loaded - click to reload' : 'Load config for AI analysis'"
                  >
                    <span v-if="aiLoading" class="material-symbols-rounded animate-spin">sync</span>
                    <span v-else class="material-symbols-rounded">{{ configLoaded ? 'check_circle' : 'upload_file' }}</span>
                    <span>{{ configLoaded ? 'Loaded' : 'Load Config' }}</span>
                  </button>
                  <button @click="toggleAiPanel" class="ai-close-btn" title="Close AI Panel">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
              </div>
              
              <div class="ai-panel-content">
                <!-- Messages -->
                <div class="ai-messages" ref="aiMessagesContainer">
                  <div v-if="aiMessages.length === 0 && !configLoaded" class="ai-empty-state">
                    <span class="material-symbols-rounded text-4xl mb-3 text-primary-400">psychology</span>
                    <p class="text-surface-300 mb-2">AI Config Assistant</p>
                    <p class="text-surface-500 text-xs mb-4">Click "Load Config" to let the AI analyze your configuration</p>
                    <div class="ai-suggestions">
                      <p class="text-surface-500 text-xs mb-2">Then ask questions like:</p>
                      <button @click="askQuickQuestionWithHint('Is this configuration secure?', 'List top 3 security issues only, brief bullets.')" class="ai-suggestion-btn">
                        Is this configuration secure?
                      </button>
                      <button @click="askQuickQuestionWithHint('What improvements do you suggest?', 'Top 3 improvements only, brief bullet points.')" class="ai-suggestion-btn">
                        What improvements do you suggest?
                      </button>
                      <button @click="askQuickQuestionWithHint('Are there any performance issues?', 'List performance issues briefly, max 3 items.')" class="ai-suggestion-btn">
                        Are there any performance issues?
                      </button>
                    </div>
                  </div>
                  
                  <div v-else-if="aiMessages.length === 0 && configLoaded" class="ai-empty-state">
                    <span class="material-symbols-rounded text-4xl mb-3 text-green-400">check_circle</span>
                    <p class="text-surface-300 mb-2">Config Loaded!</p>
                    <p class="text-surface-500 text-xs mb-4">Ask the AI anything about your {{ service }} configuration</p>
                    <div class="ai-suggestions">
                      <button @click="askQuickQuestionWithHint('Analyze this config for issues', 'List top 3 issues only, brief bullets.')" class="ai-suggestion-btn">
                        Analyze this config for issues
                      </button>
                      <button @click="askQuickQuestionWithHint('Is this breaking anything?', 'Answer Yes or No first, then brief reason.')" class="ai-suggestion-btn">
                        Is this breaking anything?
                      </button>
                      <button @click="askQuickQuestionWithHint('Suggest best practices', 'Top 3 best practice improvements, brief bullets.')" class="ai-suggestion-btn">
                        Suggest best practices
                      </button>
                    </div>
                  </div>
                  
                  <template v-else>
                    <div 
                      v-for="(msg, idx) in aiMessages" 
                      :key="idx"
                      :class="['ai-message', msg.role === 'user' ? 'user' : 'assistant']"
                    >
                      <div v-if="msg.role === 'assistant'" class="ai-message-content" v-html="renderMarkdown(msg.content)"></div>
                      <div v-else class="ai-message-content" v-html="formatUserMessage(msg.content)"></div>
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
                  <textarea
                    v-model="aiInput"
                    @keydown="handleAiKeydown"
                    :disabled="aiTyping"
                    placeholder="Ask about your config..."
                    rows="2"
                    class="ai-input"
                  ></textarea>
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
          <div class="zen-footer">
            <div class="zen-shortcuts">
              <span><kbd>Esc</kbd> Exit</span>
              <span><kbd>Ctrl+S</kbd> Save</span>
              <span><kbd>Ctrl+Z</kbd> Undo</span>
              <span><kbd>Ctrl+F</kbd> Find</span>
            </div>
            <slot name="zen-actions">
              <div class="zen-actions">
                <button @click="toggleZenMode" class="btn-secondary">Cancel</button>
                <button @click="$emit('save')" class="btn-primary" v-if="!readonly">
                  <span class="material-symbols-rounded">save</span>
                  Save
                </button>
              </div>
            </slot>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Normal Editor -->
    <div class="config-editor" :class="{ 'readonly': readonly }">
      <div class="editor-toolbar" v-if="showToolbar">
        <div class="toolbar-left">
          <button v-if="service" @click="checkSyntax" class="toolbar-btn" :disabled="syntaxChecking" title="Check configuration syntax">
            <span v-if="syntaxChecking" class="spinner-sm"></span>
            <span v-else class="material-symbols-rounded">fact_check</span>
            <span>{{ syntaxChecking ? 'Checking...' : 'Check Syntax' }}</span>
          </button>
        </div>
        <div class="toolbar-right">
          <button @click="toggleZenMode" class="toolbar-btn" title="Zen Mode - Full Screen Editor">
            <span class="material-symbols-rounded">open_in_full</span>
            <span>Zen Mode</span>
          </button>
        </div>
      </div>
      <!-- Syntax Result Banner -->
      <div v-if="syntaxResult" class="syntax-result" :class="syntaxResult.valid ? 'syntax-valid' : 'syntax-error'">
        <div class="syntax-result-header">
          <span class="material-symbols-rounded">{{ syntaxResult.valid ? 'check_circle' : 'error' }}</span>
          <span class="syntax-result-title">{{ syntaxResult.valid ? 'Syntax OK' : 'Syntax Errors Found' }}</span>
          <button @click="syntaxResult = null" class="syntax-close">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div v-if="syntaxResult.errors?.length" class="syntax-errors">
          <div v-for="(error, idx) in syntaxResult.errors" :key="idx" class="syntax-error-item">{{ error }}</div>
        </div>
        <div v-if="syntaxResult.warnings?.length" class="syntax-warnings">
          <div v-for="(warning, idx) in syntaxResult.warnings" :key="idx" class="syntax-warning-item">⚠ {{ warning }}</div>
        </div>
      </div>
      <div ref="editorContainer" class="editor-container"></div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick, computed } from 'vue'
import { EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightSpecialChars, drawSelection, highlightActiveLine } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { marked } from 'marked'
import aiHelper from '@/services/aiHelper'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { syntaxHighlighting, HighlightStyle, StreamLanguage } from '@codemirror/language'
import { tags } from '@lezer/highlight'
import { searchKeymap, highlightSelectionMatches, search } from '@codemirror/search'
import api from '@/services/api'

// Language imports
import { html } from '@codemirror/lang-html'
import { css } from '@codemirror/lang-css'
import { javascript } from '@codemirror/lang-javascript'
import { json } from '@codemirror/lang-json'
import { php } from '@codemirror/lang-php'
import { xml } from '@codemirror/lang-xml'
import { sql } from '@codemirror/lang-sql'
import { markdown } from '@codemirror/lang-markdown'

const props = defineProps({
  modelValue: { type: String, default: '' },
  readonly: { type: Boolean, default: false },
  language: { type: String, default: 'conf' }, // conf, ini, nginx, php, html, css, js, json, xml, sql, md
  height: { type: String, default: '500px' },
  showToolbar: { type: Boolean, default: true },
  zenTitle: { type: String, default: '' },
  service: { type: String, default: '' }, // ssh, ols, php, mysql, postfix, dovecot, pdns
  configFiles: { type: Array, default: () => [] }, // [{path: '/etc/...', label: 'main.cf'}]
  selectedFile: { type: String, default: '' },
  aiEnabled: { type: Boolean, default: true }, // Enable AI assistant in zen mode
})

const emit = defineEmits(['update:modelValue', 'save', 'syntax-check', 'open-guide', 'file-change'])

const editorContainer = ref(null)
const zenEditorContainer = ref(null)
const isZenMode = ref(false)
const syntaxChecking = ref(false)
const syntaxResult = ref(null)
let editorView = null
let zenEditorView = null

// AI Assistant state
const aiPanelOpen = ref(false)
const aiMessages = ref([])
const aiInput = ref('')
const aiTyping = ref(false)
const aiLoading = ref(false)
const configLoaded = ref(false)
const aiConversationId = ref(null)
const aiMessagesContainer = ref(null)
const aiMessagesEnd = ref(null)

// Resize state
const editorWidth = ref(60)
const isResizing = ref(false)

// Context menu state
const showContextMenu = ref(false)
const contextMenuPos = ref({ x: 0, y: 0 })
const selectedText = ref('')

// Resize functions
const startResize = (e) => {
  isResizing.value = true
  document.addEventListener('mousemove', doResize)
  document.addEventListener('mouseup', stopResize)
  e.preventDefault()
}

const doResize = (e) => {
  if (!isResizing.value) return
  const container = document.querySelector('.zen-content')
  if (!container) return
  const rect = container.getBoundingClientRect()
  const newWidth = ((e.clientX - rect.left) / rect.width) * 100
  editorWidth.value = Math.max(30, Math.min(80, newWidth))
}

const stopResize = () => {
  isResizing.value = false
  document.removeEventListener('mousemove', doResize)
  document.removeEventListener('mouseup', stopResize)
}

// Context menu functions
const handleEditorContextMenu = (e) => {
  if (!aiPanelOpen.value) return
  
  // Get selection from zen editor
  if (zenEditorView) {
    const selection = zenEditorView.state.selection.main
    if (selection.from !== selection.to) {
      selectedText.value = zenEditorView.state.doc.sliceString(selection.from, selection.to)
      contextMenuPos.value = { x: e.clientX, y: e.clientY }
      showContextMenu.value = true
      e.preventDefault()
    }
  }
}

const hideContextMenu = () => {
  showContextMenu.value = false
}

const askAboutSelection = () => {
  if (!selectedText.value) return
  hideContextMenu()
  const configSnippet = selectedText.value.trim()
  const displayMsg = `What does this do?\n${configSnippet}`
  const aiMsg = `What does this do? One sentence only. No GOOD/BAD sections.

${configSnippet}`
  sendAiMessageWithContext(displayMsg, aiMsg)
}

const askIfCorrect = () => {
  if (!selectedText.value) return
  hideContextMenu()
  const configSnippet = selectedText.value.trim()
  const displayMsg = `Is this correct?\n${configSnippet}`
  const aiMsg = `Is this correct? Answer only YES or NO with one short reason. If NO, tell me what to change it to. No GOOD/BAD sections ever.

${configSnippet}`
  sendAiMessageWithContext(displayMsg, aiMsg)
}

const askToImprove = () => {
  if (!selectedText.value) return
  hideContextMenu()
  const configSnippet = selectedText.value.trim()
  const displayMsg = `How to improve?\n${configSnippet}`
  const aiMsg = `Can this be improved? Say "Already optimal" or "Change to [value] because [reason]". One sentence only. No GOOD/BAD sections.

${configSnippet}`
  sendAiMessageWithContext(displayMsg, aiMsg)
}

// Send message with separate display and AI versions (for quick questions - NO full config load)
const sendAiMessageWithContext = async (displayMessage, aiMessage) => {
  // Add clean message to chat for user to see
  aiMessages.value.push({ role: 'user', content: displayMessage })
  scrollToAiBottom()
  
  aiTyping.value = true
  try {
    // Create conversation if needed, but DON'T load full config
    if (!aiConversationId.value) {
      const conversation = await aiHelper.createConversation(`Config: ${props.service}`)
      aiConversationId.value = conversation.id
    }
    
    // Minimal context - just the service info, NOT the full config
    const context = {
      type: 'config_snippet_question',
      service: props.service
    }
    
    // Send the detailed AI message (not shown to user)
    const response = await aiHelper.sendMessage(aiConversationId.value, aiMessage, context)
    aiMessages.value.push({ 
      role: 'assistant', 
      content: response.message || 'I apologize, I could not process that request.' 
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

// AI system prompt for config analysis
const getConfigSystemPrompt = () => {
  const serviceNames = {
    ssh: 'SSH (sshd_config)',
    mysql: 'MySQL/MariaDB',
    postfix: 'Postfix Mail Server',
    dovecot: 'Dovecot IMAP/POP3',
    php: 'PHP',
    ols: 'OpenLiteSpeed',
    pdns: 'PowerDNS'
  }
  const serviceName = serviceNames[props.service] || props.service
  
  return `You are an expert ${serviceName} security and configuration specialist. You know all best practices and most secure settings.

RESPONSE FORMAT depends on the question:

1. "What does this do?" or "Explain"
   → Give 1-2 sentence explanation ONLY. No GOOD/BAD sections. Just explain what the setting does.

2. "Is this correct?" (single setting)
   → Answer: "Yes, this is secure/correct." OR "No. Change to \`value\` - reason"
   → NO GOOD/BAD sections for single settings

3. "Is this correct?" (multiple settings/full config)
   → Use **GOOD:** **ISSUES:** **RECOMMENDATIONS:** format
   → List items briefly with exact fix values

4. "How to improve?" 
   → If already optimal: "Already optimal. No changes needed."
   → If can improve: "Change to \`exact_value\` - reason (security/performance benefit)"
   → Know the BEST PRACTICES: encryption=required, strong ciphers, disable debug, etc.

CRITICAL RULES:
- ALWAYS give EXACT values: "Change to \`encrypt\`" not "enable encryption"
- Be BRIEF - no lengthy explanations unless asked
- Know security best practices for ${serviceName}
- NO [[filepath]] brackets in responses`
}

// Toggle AI panel
const toggleAiPanel = () => {
  aiPanelOpen.value = !aiPanelOpen.value
  if (aiPanelOpen.value) {
    nextTick(() => {
      // Recreate zen editor with new width
      if (zenEditorView) {
        destroyZenEditor()
        createZenEditor()
      }
    })
  }
}

// Load config to AI context
const loadConfigToAi = async () => {
  aiLoading.value = true
  try {
    // Create a new conversation for this config session
    const conversation = await aiHelper.createConversation(`Config: ${props.service} - ${props.selectedFile || 'config'}`)
    aiConversationId.value = conversation.id
    
    // Send initial context with the config content
    const context = {
      type: 'config_analysis',
      service: props.service,
      file: props.selectedFile,
      content: props.modelValue
    }
    
    const response = await aiHelper.sendMessage(
      aiConversationId.value,
      `FULL CONFIG ANALYSIS - Use GOOD/ISSUES/RECOMMENDATIONS sections. Max 3-5 items per section.\n\n\`\`\`\n${props.modelValue}\n\`\`\``,
      context
    )
    
    // Add initial messages
    aiMessages.value = [
      { role: 'user', content: 'Config file loaded for analysis' },
      { role: 'assistant', content: response.message || 'Configuration loaded! I\'ve analyzed your settings. Ask me anything about security, performance, or best practices.' }
    ]
    configLoaded.value = true
    scrollToAiBottom()
  } catch (e) {
    console.error('Failed to load config to AI:', e)
    aiMessages.value = [{ 
      role: 'assistant', 
      content: '❌ Failed to connect to AI assistant. Please check that the AI Helper is configured in Settings > AI Helper.' 
    }]
  } finally {
    aiLoading.value = false
  }
}

// Send message to AI
const sendAiMessage = async () => {
  if (!aiInput.value.trim() || aiTyping.value) return
  
  const message = aiInput.value.trim()
  aiInput.value = ''
  
  // Add user message
  aiMessages.value.push({ role: 'user', content: message })
  scrollToAiBottom()
  
  aiTyping.value = true
  try {
    // If no conversation yet, create one with config context
    if (!aiConversationId.value) {
      await loadConfigToAi()
    }
    
    const context = {
      type: 'config_question',
      service: props.service,
      file: props.selectedFile,
      current_content: props.modelValue
    }
    
    const response = await aiHelper.sendMessage(aiConversationId.value, message, context)
    aiMessages.value.push({ 
      role: 'assistant', 
      content: response.message || 'I apologize, I could not process that request.' 
    })
  } catch (e) {
    console.error('AI message error:', e)
    aiMessages.value.push({ 
      role: 'assistant', 
      content: '❌ Error communicating with AI. Please try again.' 
    })
  } finally {
    aiTyping.value = false
    scrollToAiBottom()
  }
}

// Quick question helper - shows clean question, sends with hidden hint
const askQuickQuestionWithHint = (displayQuestion, hint) => {
  const aiMessage = `${displayQuestion} (${hint})`
  sendAiMessageWithContext(displayQuestion, aiMessage)
}

// Simple quick question (for manual input)
const askQuickQuestion = (question) => {
  aiInput.value = question
  sendAiMessage()
}

// Handle AI input keydown
const handleAiKeydown = (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    sendAiMessage()
  }
}

// Scroll AI messages to bottom
const scrollToAiBottom = () => {
  nextTick(() => {
    if (aiMessagesEnd.value) {
      aiMessagesEnd.value.scrollIntoView({ behavior: 'smooth' })
    }
  })
}

// Render markdown for AI messages
marked.setOptions({ breaks: true, gfm: true, headerIds: false, mangle: false })

// Escape HTML for safe display
const escapeHtml = (text) => {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

// Syntax highlight config code (input should already be escaped)
const highlightConfigSyntax = (code) => {
  return code
    .split('\n')
    .map(line => {
      // Comments
      if (line.trim().startsWith('#')) {
        return `<span class="cfg-comment">${line}</span>`
      }
      // Service definitions (like "smtp inet n - n - - smtpd") - check before = check
      if (line.match(/^\w+\s+(inet|unix|fifo)/)) {
        return `<span class="cfg-service">${line}</span>`
      }
      // -o options
      if (line.trim().startsWith('-o ')) {
        const parts = line.match(/^(\s*-o\s+)(\w+)(=)(.*)$/)
        if (parts) {
          return `${parts[1]}<span class="cfg-key">${parts[2]}</span><span class="cfg-eq">${parts[3]}</span><span class="cfg-val">${parts[4]}</span>`
        }
        return `<span class="cfg-opt">${line}</span>`
      }
      // Key = value pairs
      if (line.includes('=')) {
        const eqIndex = line.indexOf('=')
        const key = line.substring(0, eqIndex)
        const value = line.substring(eqIndex + 1)
        return `<span class="cfg-key">${key}</span><span class="cfg-eq">=</span><span class="cfg-val">${value}</span>`
      }
      return line
    })
    .join('\n')
}

// Format user messages - style the config snippet
const formatUserMessage = (text) => {
  // Check for → separator first
  if (text.includes('→')) {
    const [question, snippet] = text.split('→')
    const escaped = escapeHtml(snippet.trim())
    const highlighted = highlightConfigSyntax(escaped)
    return `${escapeHtml(question.trim())}<pre class="user-config-snippet">${highlighted}</pre>`
  }
  
  // Check for newline separator (question on first line, config on rest)
  const lines = text.split('\n')
  if (lines.length > 1 && lines[0].endsWith('?')) {
    const question = lines[0]
    const snippet = lines.slice(1).join('\n').trim()
    if (snippet) {
      const escaped = escapeHtml(snippet)
      const highlighted = highlightConfigSyntax(escaped)
      return `${escapeHtml(question)}<pre class="user-config-snippet">${highlighted}</pre>`
    }
  }
  
  return escapeHtml(text).replace(/\n/g, '<br>')
}

const renderMarkdown = (text) => {
  try {
    // Clean up formatting
    let processed = text
      // Remove stray code fences
      .replace(/```[\w]*\n?/g, '')
      // Remove [[filepath]] brackets
      .replace(/\[\[([^\]]+)\]\]/g, '$1')
      // Section headers - handle all formats: ## GOOD, **GOOD:**, GOOD:, etc.
      .replace(/^#{1,3}\s*GOOD\s*$/gim, '<div class="ai-section ai-good"><span class="material-symbols-rounded">check_circle</span><strong>Good</strong></div>')
      .replace(/^#{1,3}\s*ISSUES\s*$/gim, '<div class="ai-section ai-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>')
      .replace(/^#{1,3}\s*BAD\s*$/gim, '<div class="ai-section ai-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>')
      .replace(/^#{1,3}\s*RECOMMENDATIONS\s*$/gim, '<div class="ai-section ai-rec"><span class="material-symbols-rounded">lightbulb</span><strong>Recommendations</strong></div>')
      .replace(/^\*\*GOOD:?\*\*\s*$/gim, '<div class="ai-section ai-good"><span class="material-symbols-rounded">check_circle</span><strong>Good</strong></div>')
      .replace(/^\*\*ISSUES:?\*\*\s*$/gim, '<div class="ai-section ai-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>')
      .replace(/^\*\*BAD:?\*\*\s*$/gim, '<div class="ai-section ai-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>')
      .replace(/^\*\*RECOMMENDATIONS:?\*\*\s*$/gim, '<div class="ai-section ai-rec"><span class="material-symbols-rounded">lightbulb</span><strong>Recommendations</strong></div>')
      .replace(/^GOOD:\s*$/gim, '<div class="ai-section ai-good"><span class="material-symbols-rounded">check_circle</span><strong>Good</strong></div>')
      .replace(/^ISSUES:\s*$/gim, '<div class="ai-section ai-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>')
      .replace(/^BAD:\s*$/gim, '<div class="ai-section ai-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>')
      .replace(/^RECOMMENDATIONS:\s*$/gim, '<div class="ai-section ai-rec"><span class="material-symbols-rounded">lightbulb</span><strong>Recommendations</strong></div>')
      // Convert **bold** to <strong>
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      // Convert `code` to inline code
      .replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>')
      // Clean up "Change to X" - make value stand out
      .replace(/Change to\s*\[([^\]]+)\]/gi, 'Change to <code class="fix-value">$1</code>')
    
    // Simple line break handling instead of full markdown parse
    let html = processed
      .split('\n')
      .map(line => line.trim())
      .filter(line => line)
      .map(line => {
        if (line.startsWith('- ')) {
          return `<li>${line.substring(2)}</li>`
        }
        if (line.startsWith('<div class="ai-section')) {
          return line
        }
        if (line.startsWith('<li>')) {
          return line
        }
        return `<p>${line}</p>`
      })
      .join('')
    
    // Wrap consecutive <li> in <ul>
    html = html.replace(/(<li>.*?<\/li>)+/g, '<ul>$&</ul>')
    
    return html
  } catch (e) {
    return text.replace(/\n/g, '<br>')
  }
}

// Reset AI state when config file changes - ALWAYS reset conversation
watch(() => props.selectedFile, () => {
  configLoaded.value = false
  aiMessages.value = []
  aiConversationId.value = null
})

// Syntax check function
const checkSyntax = async () => {
  if (!props.service) {
    emit('syntax-check', { error: 'No service configured for syntax check' })
    return
  }
  
  syntaxChecking.value = true
  syntaxResult.value = null
  
  try {
    // Base64 encode content to bypass WAF/ModSecurity that may block config-like content
    const encodedContent = btoa(unescape(encodeURIComponent(props.modelValue)))
    const response = await api.post('/system/syntax-check', {
      service: props.service,
      content_b64: encodedContent,
    })
    
    syntaxResult.value = response.data.success 
      ? response.data.data 
      : { valid: false, errors: [response.data.error || 'Unknown error'] }
    emit('syntax-check', syntaxResult.value)
    
    // Auto-hide success after 5 seconds
    if (syntaxResult.value.valid) {
      setTimeout(() => {
        if (syntaxResult.value?.valid) {
          syntaxResult.value = null
        }
      }, 5000)
    }
  } catch (e) {
    const errorMsg = e.response?.data?.error || e.message || 'Syntax check failed'
    syntaxResult.value = { valid: false, errors: [errorMsg] }
    emit('syntax-check', syntaxResult.value)
  } finally {
    syntaxChecking.value = false
  }
}

// Custom config file language definition
const configLanguage = StreamLanguage.define({
  token(stream, state) {
    // Comments
    if (stream.match(/^[#;].*/)) {
      return 'comment'
    }
    
    // Block braces
    if (stream.match(/^[{}[\]]/)) {
      return 'brace'
    }
    
    // Strings in quotes
    if (stream.match(/^"[^"]*"/)) {
      return 'string'
    }
    if (stream.match(/^'[^']*'/)) {
      return 'string'
    }
    
    // Key = value pattern (key part)
    if (stream.match(/^[a-zA-Z_][\w.-]*(?=\s*[=:])/)) {
      return 'variableName'
    }
    
    // Directives/keywords at start of line
    if (stream.sol() && stream.match(/^[a-zA-Z_][\w.-]*/)) {
      return 'keyword'
    }
    
    // Numbers
    if (stream.match(/^-?\d+(\.\d+)?[KMGkmg]?/)) {
      return 'number'
    }
    
    // Boolean values
    if (stream.match(/^(yes|no|true|false|on|off|enabled|disabled)\b/i)) {
      return 'bool'
    }
    
    // IP addresses and paths
    if (stream.match(/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/)) {
      return 'number'
    }
    if (stream.match(/^\/[\w./-]+/)) {
      return 'string'
    }
    
    // Section headers [section]
    if (stream.match(/^\[[^\]]+\]/)) {
      return 'heading'
    }
    
    // Skip whitespace
    if (stream.eatSpace()) {
      return null
    }
    
    // Default - consume one character
    stream.next()
    return null
  }
})

// Get language extension based on language prop
const getLanguageExtension = () => {
  switch (props.language) {
    case 'html':
    case 'htm':
      return html()
    case 'css':
    case 'scss':
    case 'less':
      return css()
    case 'js':
    case 'javascript':
    case 'jsx':
    case 'ts':
    case 'typescript':
    case 'tsx':
      return javascript({ jsx: props.language.includes('x'), typescript: props.language.startsWith('ts') })
    case 'json':
      return json()
    case 'php':
      return php()
    case 'xml':
    case 'svg':
      return xml()
    case 'sql':
      return sql()
    case 'md':
    case 'markdown':
      return markdown()
    case 'conf':
    case 'ini':
    case 'nginx':
    case 'env':
    case 'htaccess':
    default:
      return configLanguage
  }
}

// Dark theme highlighting - comprehensive for all languages
const darkHighlightStyle = HighlightStyle.define([
  // Comments
  { tag: tags.comment, color: '#6b7280', fontStyle: 'italic' },
  { tag: tags.lineComment, color: '#6b7280', fontStyle: 'italic' },
  { tag: tags.blockComment, color: '#6b7280', fontStyle: 'italic' },
  { tag: tags.docComment, color: '#6b7280', fontStyle: 'italic' },
  
  // HTML/XML Tags
  { tag: tags.tagName, color: '#22d3ee' },           // Tag names in cyan
  { tag: tags.angleBracket, color: '#6b7280' },      // < > brackets
  { tag: tags.attributeName, color: '#a78bfa' },     // Attributes in purple
  { tag: tags.attributeValue, color: '#34d399' },    // Attribute values in green
  { tag: tags.content, color: '#e2e8f0' },           // Text content
  { tag: tags.documentMeta, color: '#f472b6' },      // DOCTYPE etc
  
  // General syntax
  { tag: tags.variableName, color: '#60a5fa' },
  { tag: tags.definition(tags.variableName), color: '#60a5fa' },
  { tag: tags.propertyName, color: '#60a5fa' },
  { tag: tags.keyword, color: '#c084fc' },
  { tag: tags.controlKeyword, color: '#c084fc' },
  { tag: tags.moduleKeyword, color: '#c084fc' },
  { tag: tags.operatorKeyword, color: '#c084fc' },
  { tag: tags.string, color: '#34d399' },
  { tag: tags.special(tags.string), color: '#34d399' },
  { tag: tags.number, color: '#f97316' },
  { tag: tags.integer, color: '#f97316' },
  { tag: tags.float, color: '#f97316' },
  { tag: tags.bool, color: '#fbbf24' },
  { tag: tags.null, color: '#fbbf24' },
  { tag: tags.brace, color: '#f472b6' },
  { tag: tags.paren, color: '#f472b6' },
  { tag: tags.bracket, color: '#f472b6' },
  { tag: tags.squareBracket, color: '#f472b6' },
  { tag: tags.heading, color: '#22d3ee', fontWeight: 'bold' },
  
  // Functions and classes
  { tag: tags.function(tags.variableName), color: '#fbbf24' },
  { tag: tags.definition(tags.function(tags.variableName)), color: '#fbbf24' },
  { tag: tags.className, color: '#22d3ee' },
  { tag: tags.definition(tags.className), color: '#22d3ee' },
  { tag: tags.typeName, color: '#22d3ee' },
  
  // Operators and punctuation
  { tag: tags.operator, color: '#94a3b8' },
  { tag: tags.punctuation, color: '#94a3b8' },
  { tag: tags.separator, color: '#94a3b8' },
  
  // Special
  { tag: tags.meta, color: '#f472b6' },
  { tag: tags.processingInstruction, color: '#f472b6' },
  { tag: tags.regexp, color: '#fb923c' },
  { tag: tags.escape, color: '#fb923c' },
  { tag: tags.self, color: '#f472b6' },
  { tag: tags.atom, color: '#fbbf24' },
  { tag: tags.unit, color: '#f97316' },
  { tag: tags.link, color: '#60a5fa', textDecoration: 'underline' },
  { tag: tags.url, color: '#60a5fa' },
  
  // CSS specific
  { tag: tags.color, color: '#fb923c' },
  { tag: tags.labelName, color: '#22d3ee' },
])

// Editor theme
const editorTheme = EditorView.theme({
  '&': {
    backgroundColor: '#0f172a',
    color: '#e2e8f0',
    fontSize: '13px',
    fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
  },
  '.cm-content': {
    padding: '16px',
    caretColor: '#60a5fa',
  },
  '.cm-cursor': {
    borderLeftColor: '#60a5fa',
    borderLeftWidth: '2px',
  },
  '.cm-activeLine': {
    backgroundColor: 'rgba(59, 130, 246, 0.1)',
  },
  '.cm-activeLineGutter': {
    backgroundColor: 'rgba(59, 130, 246, 0.1)',
  },
  '.cm-gutters': {
    backgroundColor: '#0f172a',
    color: '#475569',
    border: 'none',
    borderRight: '1px solid #1e293b',
  },
  '.cm-lineNumbers .cm-gutterElement': {
    padding: '0 12px 0 8px',
  },
  '.cm-selectionBackground': {
    backgroundColor: 'rgba(59, 130, 246, 0.3) !important',
  },
  '&.cm-focused .cm-selectionBackground': {
    backgroundColor: 'rgba(59, 130, 246, 0.3) !important',
  },
  '.cm-scroller': {
    overflow: 'auto',
  },
  // Search panel styles
  '.cm-panels': {
    backgroundColor: '#1e293b',
    color: '#e2e8f0',
  },
  '.cm-panels.cm-panels-top': {
    borderBottom: '1px solid #334155',
  },
  '.cm-search': {
    padding: '10px 16px !important',
    display: 'flex !important',
    flexWrap: 'wrap !important',
    gap: '8px !important',
    alignItems: 'center !important',
    fontSize: '13px !important',
  },
  '.cm-search label': {
    display: 'flex !important',
    alignItems: 'center !important',
    gap: '6px !important',
    color: '#94a3b8 !important',
    cursor: 'pointer !important',
    fontSize: '12px !important',
    userSelect: 'none !important',
  },
  // Toggle switch styling for checkboxes
  '.cm-search input[type="checkbox"]': {
    appearance: 'none',
    WebkitAppearance: 'none',
    width: '36px',
    height: '20px',
    backgroundColor: '#475569',
    borderRadius: '10px',
    position: 'relative',
    cursor: 'pointer',
    transition: 'background-color 0.2s',
    flexShrink: '0',
  },
  '.cm-search input[type="checkbox"]::before': {
    content: '""',
    position: 'absolute',
    width: '16px',
    height: '16px',
    borderRadius: '50%',
    top: '2px',
    left: '2px',
    backgroundColor: '#e2e8f0',
    transition: 'transform 0.2s',
  },
  '.cm-search input[type="checkbox"]:checked': {
    backgroundColor: '#3b82f6',
  },
  '.cm-search input[type="checkbox"]:checked::before': {
    transform: 'translateX(16px)',
  },
  '.cm-textfield': {
    backgroundColor: '#0f172a !important',
    border: '1px solid #334155 !important',
    borderRadius: '6px !important',
    color: '#e2e8f0 !important',
    padding: '6px 12px !important',
    fontSize: '13px !important',
    outline: 'none !important',
    minWidth: '160px !important',
    height: '32px !important',
    boxSizing: 'border-box !important',
  },
  '.cm-textfield:focus': {
    borderColor: '#3b82f6 !important',
    boxShadow: '0 0 0 2px rgba(59, 130, 246, 0.15) !important',
  },
  '.cm-textfield::placeholder': {
    color: '#64748b !important',
  },
  '.cm-button': {
    background: 'transparent !important',
    backgroundImage: 'none !important',
    border: '1px solid #334155 !important',
    borderRadius: '6px !important',
    color: '#94a3b8 !important',
    padding: '5px 12px !important',
    fontSize: '13px !important',
    fontWeight: '500 !important',
    cursor: 'pointer',
    transition: 'all 0.15s',
    boxShadow: 'none !important',
    lineHeight: '1.4 !important',
    height: 'auto !important',
    minHeight: '32px !important',
  },
  '.cm-button:hover': {
    background: 'rgba(148, 163, 184, 0.08) !important',
    backgroundImage: 'none !important',
    borderColor: '#475569 !important',
    color: '#e2e8f0 !important',
  },
  '.cm-button:active': {
    transform: 'scale(0.98)',
  },
  '.cm-search .cm-button[name="close"]': {
    background: 'transparent !important',
    backgroundImage: 'none !important',
    padding: '5px 8px !important',
    marginLeft: 'auto',
    color: '#64748b !important',
    border: '1px solid transparent !important',
    borderRadius: '6px !important',
    minHeight: '32px !important',
  },
  '.cm-search .cm-button[name="close"]:hover': {
    color: '#f87171 !important',
    background: 'rgba(248, 113, 113, 0.1) !important',
    borderColor: 'rgba(248, 113, 113, 0.3) !important',
  },
  '.cm-searchMatch': {
    backgroundColor: 'rgba(250, 204, 21, 0.3)',
    borderRadius: '2px',
  },
  '.cm-searchMatch-selected': {
    backgroundColor: 'rgba(59, 130, 246, 0.4)',
  },
}, { dark: true })

// Search panel theme (shared between normal and zen modes)
const searchPanelTheme = {
  '.cm-panels': {
    backgroundColor: '#1e293b',
    color: '#e2e8f0',
    borderBottom: '1px solid #334155',
  },
  '.cm-panels.cm-panels-top': {
    borderBottom: '1px solid #334155',
  },
  '.cm-panels.cm-panels-bottom': {
    borderTop: '1px solid #334155',
  },
  '.cm-search': {
    padding: '10px 16px !important',
    display: 'flex !important',
    flexWrap: 'wrap !important',
    gap: '8px !important',
    alignItems: 'center !important',
    fontSize: '13px !important',
  },
  '.cm-search label': {
    display: 'flex !important',
    alignItems: 'center !important',
    gap: '6px !important',
    color: '#94a3b8 !important',
    cursor: 'pointer !important',
    fontSize: '12px !important',
    userSelect: 'none !important',
  },
  // Toggle switch styling for checkboxes
  '.cm-search input[type="checkbox"]': {
    appearance: 'none',
    WebkitAppearance: 'none',
    width: '36px',
    height: '20px',
    backgroundColor: '#475569',
    borderRadius: '10px',
    position: 'relative',
    cursor: 'pointer',
    transition: 'background-color 0.2s',
    flexShrink: '0',
  },
  '.cm-search input[type="checkbox"]::before': {
    content: '""',
    position: 'absolute',
    width: '16px',
    height: '16px',
    borderRadius: '50%',
    top: '2px',
    left: '2px',
    backgroundColor: '#e2e8f0',
    transition: 'transform 0.2s',
  },
  '.cm-search input[type="checkbox"]:checked': {
    backgroundColor: '#3b82f6',
  },
  '.cm-search input[type="checkbox"]:checked::before': {
    transform: 'translateX(16px)',
  },
  '.cm-textfield': {
    backgroundColor: '#0f172a !important',
    border: '1px solid #334155 !important',
    borderRadius: '6px !important',
    color: '#e2e8f0 !important',
    padding: '6px 12px !important',
    fontSize: '13px !important',
    outline: 'none !important',
    minWidth: '160px !important',
    height: '32px !important',
    boxSizing: 'border-box !important',
  },
  '.cm-textfield:focus': {
    borderColor: '#3b82f6 !important',
    boxShadow: '0 0 0 2px rgba(59, 130, 246, 0.15) !important',
  },
  '.cm-textfield::placeholder': {
    color: '#64748b !important',
  },
  '.cm-button': {
    background: 'transparent !important',
    backgroundImage: 'none !important',
    border: '1px solid #334155 !important',
    borderRadius: '6px !important',
    color: '#94a3b8 !important',
    padding: '5px 12px !important',
    fontSize: '13px !important',
    fontWeight: '500 !important',
    cursor: 'pointer',
    transition: 'all 0.15s',
    boxShadow: 'none !important',
    lineHeight: '1.4 !important',
    height: 'auto !important',
    minHeight: '32px !important',
  },
  '.cm-button:hover': {
    background: 'rgba(148, 163, 184, 0.08) !important',
    backgroundImage: 'none !important',
    borderColor: '#475569 !important',
    color: '#e2e8f0 !important',
  },
  '.cm-button:active': {
    transform: 'scale(0.98)',
  },
  '.cm-search .cm-button[name="close"]': {
    background: 'transparent !important',
    backgroundImage: 'none !important',
    padding: '5px 8px !important',
    marginLeft: 'auto',
    color: '#64748b !important',
    border: '1px solid transparent !important',
    borderRadius: '6px !important',
    minHeight: '32px !important',
  },
  '.cm-search .cm-button[name="close"]:hover': {
    color: '#f87171 !important',
    background: 'rgba(248, 113, 113, 0.1) !important',
    borderColor: 'rgba(248, 113, 113, 0.3) !important',
  },
  '.cm-searchMatch': {
    backgroundColor: 'rgba(250, 204, 21, 0.3)',
    borderRadius: '2px',
  },
  '.cm-searchMatch-selected': {
    backgroundColor: 'rgba(59, 130, 246, 0.4)',
  },
}

// Zen mode theme (larger font, more padding) - same colors as normal mode
const zenEditorTheme = EditorView.theme({
  '&': {
    backgroundColor: '#0f172a',
    color: '#e2e8f0',
    fontSize: '15px',
    fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
  },
  '.cm-content': {
    padding: '24px',
    caretColor: '#60a5fa',
  },
  '.cm-cursor': {
    borderLeftColor: '#60a5fa',
    borderLeftWidth: '2px',
  },
  '.cm-activeLine': {
    backgroundColor: 'rgba(59, 130, 246, 0.08)',
  },
  '.cm-activeLineGutter': {
    backgroundColor: 'rgba(59, 130, 246, 0.08)',
  },
  '.cm-gutters': {
    backgroundColor: '#0f172a',
    color: '#475569',
    border: 'none',
    borderRight: '1px solid #1e293b',
    paddingRight: '8px',
  },
  '.cm-lineNumbers .cm-gutterElement': {
    padding: '0 16px 0 12px',
  },
  '.cm-selectionBackground': {
    backgroundColor: 'rgba(59, 130, 246, 0.3) !important',
  },
  '&.cm-focused .cm-selectionBackground': {
    backgroundColor: 'rgba(59, 130, 246, 0.3) !important',
  },
  '.cm-scroller': {
    overflow: 'auto',
  },
  ...searchPanelTheme,
}, { dark: true })

const createExtensions = (isZen = false) => {
  const extensions = [
    lineNumbers(),
    highlightActiveLineGutter(),
    highlightSpecialChars(),
    history(),
    drawSelection(),
    highlightActiveLine(),
    highlightSelectionMatches(),
    search({ top: true }), // Search panel at top
    keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap]),
    getLanguageExtension(),
    syntaxHighlighting(darkHighlightStyle),
    isZen ? zenEditorTheme : editorTheme,
    EditorView.lineWrapping, // Enable word wrap
    EditorView.updateListener.of((update) => {
      if (update.docChanged) {
        const newValue = update.state.doc.toString()
        emit('update:modelValue', newValue)
        // Sync both editors
        syncEditors(newValue, isZen ? 'zen' : 'normal')
      }
    }),
  ]
  
  if (props.readonly) {
    extensions.push(EditorState.readOnly.of(true))
  }
  
  return extensions
}

const syncEditors = (value, source) => {
  if (source === 'zen' && editorView) {
    const currentValue = editorView.state.doc.toString()
    if (currentValue !== value) {
      editorView.dispatch({
        changes: { from: 0, to: editorView.state.doc.length, insert: value }
      })
    }
  } else if (source === 'normal' && zenEditorView) {
    const currentValue = zenEditorView.state.doc.toString()
    if (currentValue !== value) {
      zenEditorView.dispatch({
        changes: { from: 0, to: zenEditorView.state.doc.length, insert: value }
      })
    }
  }
}

const createEditor = () => {
  if (!editorContainer.value) return
  
  editorView = new EditorView({
    state: EditorState.create({
      doc: props.modelValue,
      extensions: createExtensions(false),
    }),
    parent: editorContainer.value,
  })
}

const createZenEditor = () => {
  if (!zenEditorContainer.value) return
  
  zenEditorView = new EditorView({
    state: EditorState.create({
      doc: props.modelValue,
      extensions: createExtensions(true),
    }),
    parent: zenEditorContainer.value,
  })
  
  // Focus the zen editor
  zenEditorView.focus()
}

const destroyEditor = () => {
  if (editorView) {
    editorView.destroy()
    editorView = null
  }
}

const destroyZenEditor = () => {
  if (zenEditorView) {
    zenEditorView.destroy()
    zenEditorView = null
  }
}

const toggleZenMode = async () => {
  isZenMode.value = !isZenMode.value
  
  if (isZenMode.value) {
    // Prevent body scroll
    document.body.style.overflow = 'hidden'
    await nextTick()
    createZenEditor()
  } else {
    // Restore body scroll
    document.body.style.overflow = ''
    destroyZenEditor()
  }
}

// Handle Escape key to exit zen mode
const handleKeydown = (e) => {
  if (e.key === 'Escape' && isZenMode.value) {
    toggleZenMode()
  }
  // Ctrl+S to save
  if (e.key === 's' && (e.ctrlKey || e.metaKey) && isZenMode.value) {
    e.preventDefault()
    emit('save')
  }
}

watch(() => props.modelValue, (newValue) => {
  if (editorView && newValue !== editorView.state.doc.toString()) {
    editorView.dispatch({
      changes: {
        from: 0,
        to: editorView.state.doc.length,
        insert: newValue
      }
    })
  }
  if (zenEditorView && newValue !== zenEditorView.state.doc.toString()) {
    zenEditorView.dispatch({
      changes: {
        from: 0,
        to: zenEditorView.state.doc.length,
        insert: newValue
      }
    })
  }
})

watch(() => props.readonly, () => {
  destroyEditor()
  destroyZenEditor()
  createEditor()
  if (isZenMode.value) {
    createZenEditor()
  }
})

onMounted(() => {
  createEditor()
  document.addEventListener('keydown', handleKeydown)
  document.addEventListener('click', hideContextMenu)
  document.addEventListener('contextmenu', handleEditorContextMenu)
})

onUnmounted(() => {
  destroyEditor()
  document.removeEventListener('click', hideContextMenu)
  document.removeEventListener('contextmenu', handleEditorContextMenu)
  destroyZenEditor()
  document.removeEventListener('keydown', handleKeydown)
  document.body.style.overflow = ''
})

// Expose for external use
defineExpose({ toggleZenMode, isZenMode, checkSyntax, syntaxResult })
</script>

<style scoped>
.config-editor-wrapper {
  position: relative;
}

.config-editor {
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid #1e293b;
}

.editor-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  background: #0f172a;
  border-bottom: 1px solid #1e293b;
}

.toolbar-left,
.toolbar-right {
  display: flex;
  gap: 8px;
}

.toolbar-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  font-size: 13px;
  color: #94a3b8;
  background: transparent;
  border: 1px solid #334155;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.toolbar-btn:hover:not(:disabled) {
  color: #e2e8f0;
  background: #1e293b;
  border-color: #475569;
}

.toolbar-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.toolbar-btn .material-symbols-rounded {
  font-size: 18px;
}

.spinner-sm {
  width: 16px;
  height: 16px;
  border: 2px solid #334155;
  border-top-color: #60a5fa;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Syntax Result Banner */
.syntax-result {
  padding: 10px 14px;
  border-bottom: 1px solid;
}

.syntax-valid {
  background: rgba(34, 197, 94, 0.1);
  border-color: rgba(34, 197, 94, 0.3);
}

.syntax-error {
  background: rgba(239, 68, 68, 0.1);
  border-color: rgba(239, 68, 68, 0.3);
}

.syntax-result-header {
  display: flex;
  align-items: center;
  gap: 8px;
}

.syntax-valid .syntax-result-header .material-symbols-rounded {
  color: #22c55e;
}

.syntax-error .syntax-result-header .material-symbols-rounded {
  color: #ef4444;
}

.syntax-result-title {
  font-size: 13px;
  font-weight: 500;
  color: #e2e8f0;
  flex: 1;
}

.syntax-close {
  background: transparent;
  border: none;
  color: #64748b;
  cursor: pointer;
  padding: 2px;
  border-radius: 4px;
  display: flex;
}

.syntax-close:hover {
  color: #94a3b8;
  background: rgba(255, 255, 255, 0.1);
}

.syntax-close .material-symbols-rounded {
  font-size: 18px;
}

.syntax-errors,
.syntax-warnings {
  margin-top: 8px;
  font-size: 12px;
  font-family: ui-monospace, monospace;
}

.syntax-error-item {
  color: #fca5a5;
  padding: 4px 0;
  border-bottom: 1px solid rgba(239, 68, 68, 0.2);
}

.syntax-error-item:last-child {
  border-bottom: none;
}

.syntax-warning-item {
  color: #fcd34d;
  padding: 4px 0;
}

.editor-container {
  height: v-bind(height);
}

.editor-container :deep(.cm-editor) {
  height: 100%;
}

.readonly .editor-container :deep(.cm-content) {
  cursor: default;
}

/* Zen Mode Styles */
.zen-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  background: #0f172a;
}

.zen-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 24px;
  background: #0f172a;
  border-bottom: 1px solid #1e293b;
}

.zen-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 16px;
  font-weight: 600;
  color: #e2e8f0;
}

.zen-title .material-symbols-rounded {
  font-size: 22px;
  color: #60a5fa;
}

.zen-file-selector {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: 0 20px;
}

.zen-file-dropdown {
  background: #1e293b;
  border: 1px solid #334155;
  color: #e2e8f0;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  min-width: 280px;
  transition: all 0.2s ease;
}

.zen-file-dropdown:hover {
  border-color: #60a5fa;
  background: #253449;
}

.zen-file-dropdown:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
}

.zen-file-dropdown option {
  background: #1e293b;
  color: #e2e8f0;
  padding: 8px;
}

.zen-header-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.zen-guide-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  font-size: 14px;
  font-weight: 500;
  color: #fbbf24;
  background: rgba(251, 191, 36, 0.1);
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.zen-guide-btn:hover {
  background: rgba(251, 191, 36, 0.2);
  border-color: rgba(251, 191, 36, 0.5);
}

.zen-guide-btn .material-symbols-rounded {
  font-size: 20px;
}

.zen-minimize-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  font-size: 14px;
  font-weight: 500;
  color: #e2e8f0;
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.zen-minimize-btn:hover {
  background: #334155;
  border-color: #475569;
}

.zen-minimize-btn .material-symbols-rounded {
  font-size: 20px;
}

/* AI Assistant Button */
.zen-ai-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  font-size: 14px;
  font-weight: 500;
  color: #e2e8f0;
  background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
  border: 1px solid #7c3aed;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.zen-ai-btn:hover {
  background: linear-gradient(135deg, #7c3aed 0%, #9333ea 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
}

.zen-ai-btn.active {
  background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
  box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
}

.zen-ai-btn .material-symbols-rounded {
  font-size: 20px;
}

/* Split view content */
.zen-content {
  flex: 1;
  display: flex;
  overflow: hidden;
}

.zen-content.split-view .zen-editor-container {
  min-width: 30%;
  max-width: 80%;
  border-right: none;
}

/* Resize Handle */
.resize-handle {
  width: 8px;
  background: #1e293b;
  cursor: col-resize;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s;
  flex-shrink: 0;
}

.resize-handle:hover {
  background: #6366f1;
}

.resize-handle-grip {
  width: 4px;
  height: 40px;
  background: repeating-linear-gradient(
    0deg,
    #475569,
    #475569 2px,
    transparent 2px,
    transparent 6px
  );
  border-radius: 2px;
}

.resize-handle:hover .resize-handle-grip {
  background: repeating-linear-gradient(
    0deg,
    #a5b4fc,
    #a5b4fc 2px,
    transparent 2px,
    transparent 6px
  );
}

/* Context Menu */
.editor-context-menu {
  position: fixed;
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 8px;
  padding: 4px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
  z-index: 10001;
  min-width: 180px;
}

.context-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 10px 14px;
  font-size: 13px;
  color: #e2e8f0;
  background: transparent;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  text-align: left;
  transition: all 0.15s;
}

.context-menu-item:hover {
  background: #334155;
  color: #fff;
}

.context-menu-item .material-symbols-rounded {
  font-size: 18px;
  color: #8b5cf6;
}

.zen-editor-container {
  flex: 1;
  overflow: hidden;
}

.zen-editor {
  height: 100%;
}

.zen-editor :deep(.cm-editor) {
  height: 100%;
}

.zen-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 24px;
  background: #0f172a;
  border-top: 1px solid #1e293b;
}

.zen-shortcuts {
  display: flex;
  gap: 20px;
  font-size: 13px;
  color: #64748b;
}

.zen-shortcuts kbd {
  display: inline-block;
  padding: 2px 6px;
  font-family: ui-monospace, monospace;
  font-size: 11px;
  color: #94a3b8;
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 4px;
  margin-right: 4px;
}

.zen-actions {
  display: flex;
  gap: 12px;
}

/* Transition */
.zen-fade-enter-active,
.zen-fade-leave-active {
  transition: opacity 0.2s ease;
}

.zen-fade-enter-from,
.zen-fade-leave-to {
  opacity: 0;
}

/* AI Assistant Panel - Darker shade for contrast */
.zen-ai-panel {
  width: 45%;
  display: flex;
  flex-direction: column;
  background: #070b14;
  border-left: 2px solid #6366f1;
}

.ai-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: linear-gradient(180deg, #0f1629 0%, #0a0f1a 100%);
  border-bottom: 1px solid #1e293b;
}

.ai-panel-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  color: #e2e8f0;
}

.ai-panel-title .material-symbols-rounded {
  color: #8b5cf6;
}

.ai-panel-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.ai-load-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 500;
  color: #e2e8f0;
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.ai-load-btn:hover:not(:disabled) {
  background: #334155;
  border-color: #6366f1;
}

.ai-load-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.ai-load-btn .material-symbols-rounded {
  font-size: 16px;
}

.ai-close-btn {
  display: flex;
  align-items: center;
  padding: 6px;
  color: #94a3b8;
  background: transparent;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
}

.ai-close-btn:hover {
  color: #e2e8f0;
  background: #334155;
}

.ai-panel-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.ai-messages {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
}

.ai-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  text-align: center;
  padding: 24px;
}

.ai-suggestions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
  max-width: 280px;
}

.ai-suggestion-btn {
  padding: 10px 16px;
  font-size: 12px;
  color: #94a3b8;
  background: #0c1222;
  border: 1px solid #1e293b;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  text-align: left;
}

.ai-suggestion-btn:hover {
  color: #e2e8f0;
  background: #1e293b;
  border-color: #6366f1;
}

.ai-message {
  margin-bottom: 12px;
  max-width: 90%;
}

.ai-message.user {
  margin-left: auto;
}

.ai-message.user .ai-message-content {
  background: #3730a3;
  color: #e0e7ff;
  padding: 10px 14px;
  border-radius: 12px 12px 4px 12px;
  font-size: 13px;
}

.ai-message.user .ai-message-content :deep(.user-config-snippet) {
  display: block;
  background: #1e1b4b;
  color: #c7d2fe;
  padding: 8px 12px;
  border-radius: 6px;
  margin-top: 6px;
  font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
  font-size: 11px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-all;
  overflow-x: auto;
  border: 1px solid #312e81;
}

/* Config syntax highlighting - subtle colors for readability */
.ai-message-content :deep(.cfg-comment),
.user-config-snippet :deep(.cfg-comment) {
  color: #6b7280;
  font-style: italic;
}

.ai-message-content :deep(.cfg-key),
.user-config-snippet :deep(.cfg-key) {
  color: #93c5fd;
}

.ai-message-content :deep(.cfg-eq),
.user-config-snippet :deep(.cfg-eq) {
  color: #9ca3af;
}

.ai-message-content :deep(.cfg-val),
.user-config-snippet :deep(.cfg-val) {
  color: #86efac;
}

.ai-message-content :deep(.cfg-opt),
.user-config-snippet :deep(.cfg-opt) {
  color: #c4b5fd;
}

.ai-message-content :deep(.cfg-service),
.user-config-snippet :deep(.cfg-service) {
  color: #fcd34d;
}

.ai-message-content :deep(.config-code-block) {
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 8px;
  padding: 12px;
  margin: 8px 0;
  overflow-x: auto;
}

.ai-message-content :deep(.config-code-block code) {
  background: transparent;
  color: #cbd5e1;
  font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
  font-size: 11px;
  line-height: 1.6;
  white-space: pre-wrap;
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

.ai-message-content :deep(p) {
  margin: 0 0 8px 0;
}

.ai-message-content :deep(p:last-child) {
  margin-bottom: 0;
}

.ai-message-content :deep(code) {
  background: #1e293b;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
  color: #94a3b8;
}

.ai-message-content :deep(.inline-code) {
  background: #1e293b;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
  color: #93c5fd;
}

.ai-message-content :deep(.fix-value) {
  background: #14532d;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  color: #86efac;
  font-weight: 600;
}

.ai-message-content :deep(pre) {
  background: #050810;
  padding: 12px;
  border-radius: 8px;
  overflow-x: auto;
  margin: 8px 0;
  border: 1px solid #1e293b;
}

.ai-message-content :deep(pre code) {
  background: transparent;
  padding: 0;
  color: #4ade80;
}

.ai-message-content :deep(ul),
.ai-message-content :deep(ol) {
  margin: 8px 0;
  padding-left: 20px;
}

.ai-message-content :deep(li) {
  margin: 4px 0;
}

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

.ai-message-content :deep(.ai-section .material-symbols-rounded) {
  font-size: 16px;
}

.ai-message-content :deep(.ai-good) {
  color: #4ade80;
  border-color: #4ade80;
}

.ai-message-content :deep(.ai-bad) {
  color: #f87171;
  border-color: #f87171;
}

.ai-message-content :deep(.ai-rec) {
  color: #fbbf24;
  border-color: #fbbf24;
}

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
  resize: none;
  outline: none;
  transition: border-color 0.2s;
}

.ai-input:focus {
  border-color: #8b5cf6;
}

.ai-input::placeholder {
  color: #64748b;
}

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
  transition: all 0.2s;
}

.ai-send-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, #7c3aed 0%, #9333ea 100%);
  transform: scale(1.05);
}

.ai-send-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.ai-send-btn .material-symbols-rounded {
  font-size: 20px;
}
</style>
