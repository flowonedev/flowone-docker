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
            <div class="zen-footer-left">
              <div class="zen-shortcuts">
                <span><kbd>Esc</kbd> Exit</span>
                <span><kbd>Ctrl+S</kbd> Save</span>
                <span><kbd>Ctrl+Z</kbd> Undo</span>
                <span><kbd>Ctrl+F</kbd> Find</span>
              </div>
              <!-- Syntax Result -->
              <div v-if="syntaxResult" :class="['syntax-result', syntaxResult.valid ? 'valid' : 'invalid']">
                <span class="material-symbols-rounded">{{ syntaxResult.valid ? 'check_circle' : 'error' }}</span>
                {{ syntaxResult.message }}
              </div>
            </div>
            <slot name="zen-actions">
              <div class="zen-actions">
                <button @click="toggleZenMode" class="btn-secondary">Cancel</button>
                <button @click="checkSyntax" :disabled="checkingSyntax" class="btn-secondary" v-if="!readonly">
                  <span v-if="checkingSyntax" class="material-symbols-rounded animate-spin">sync</span>
                  <span v-else class="material-symbols-rounded">fact_check</span>
                  Check Syntax
                </button>
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
          <slot name="toolbar-left"></slot>
        </div>
        <div class="toolbar-right">
          <button @click="toggleZenMode" class="toolbar-btn" title="Zen Mode - Full Screen Editor">
            <span class="material-symbols-rounded">open_in_full</span>
            <span>Zen Mode</span>
          </button>
        </div>
      </div>
      <div ref="editorContainer" class="editor-container"></div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightSpecialChars, drawSelection, highlightActiveLine } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { marked } from 'marked'
import aiHelper from '@/services/aiHelper'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { syntaxHighlighting, HighlightStyle, StreamLanguage } from '@codemirror/language'
import { tags } from '@lezer/highlight'
import { searchKeymap, highlightSelectionMatches, search } from '@codemirror/search'

// Language imports
import { html } from '@codemirror/lang-html'
import { css } from '@codemirror/lang-css'
import { javascript } from '@codemirror/lang-javascript'
import { json } from '@codemirror/lang-json'
import { php } from '@codemirror/lang-php'
import { xml } from '@codemirror/lang-xml'
import { sql } from '@codemirror/lang-sql'

const props = defineProps({
  modelValue: { type: String, default: '' },
  readonly: { type: Boolean, default: false },
  language: { type: String, default: 'conf' },
  height: { type: String, default: '500px' },
  showToolbar: { type: Boolean, default: true },
  zenTitle: { type: String, default: '' },
  service: { type: String, default: '' },
  configFiles: { type: Array, default: () => [] },
  selectedFile: { type: String, default: '' },
  aiEnabled: { type: Boolean, default: true },
  filename: { type: String, default: '' },
})

// Syntax checking state
const checkingSyntax = ref(false)
const syntaxResult = ref(null) // { valid: true/false, message: string }

// Check syntax function
const checkSyntax = async () => {
  checkingSyntax.value = true
  syntaxResult.value = null
  
  try {
    const filename = (props.filename || props.selectedFile || '').toLowerCase()
    const content = props.modelValue
    const lang = props.language
    
    // Basic syntax checks based on file type
    if (lang === 'php' || filename.endsWith('.php')) {
      if (!content.includes('<?php') && !content.includes('<?=')) {
        syntaxResult.value = { valid: false, message: 'PHP file should start with <?php or <?=' }
        return
      }
      const openBraces = (content.match(/\{/g) || []).length
      const closeBraces = (content.match(/\}/g) || []).length
      if (openBraces !== closeBraces) {
        syntaxResult.value = { valid: false, message: `Unbalanced braces: ${openBraces} opening, ${closeBraces} closing` }
        return
      }
    }
    
    if (lang === 'json' || filename.endsWith('.json')) {
      try {
        JSON.parse(content)
      } catch (e) {
        syntaxResult.value = { valid: false, message: `Invalid JSON: ${e.message}` }
        return
      }
    }
    
    if (lang === 'xml' || lang === 'html' || filename.endsWith('.xml') || filename.endsWith('.html')) {
      const openTags = (content.match(/<[a-zA-Z][^/>]*>/g) || []).length
      const closeTags = (content.match(/<\/[a-zA-Z][^>]*>/g) || []).length
      const selfClosing = (content.match(/<[^>]+\/>/g) || []).length
      if (Math.abs(openTags - closeTags - selfClosing) > 5) {
        syntaxResult.value = { valid: false, message: 'Possible unclosed HTML/XML tags detected' }
        return
      }
    }
    
    if (lang === 'conf' || filename.endsWith('.conf') || filename.endsWith('.cnf') || filename.endsWith('.ini')) {
      const lines = content.split('\n')
      for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim()
        if (!line || line.startsWith('#') || line.startsWith(';') || line.startsWith('[')) continue
        if (line.includes('=') && line.endsWith('=')) {
          syntaxResult.value = { valid: false, message: `Line ${i + 1}: Empty value after '='` }
          return
        }
      }
    }
    
    if (lang === 'shell' || filename.endsWith('.sh') || filename.endsWith('.bash')) {
      if (!content.startsWith('#!')) {
        syntaxResult.value = { valid: false, message: 'Shell script should start with shebang (#!/bin/bash)' }
        return
      }
    }
    
    syntaxResult.value = { valid: true, message: 'Syntax OK' }
  } catch (error) {
    syntaxResult.value = { valid: false, message: error.message }
  } finally {
    checkingSyntax.value = false
  }
}

const emit = defineEmits(['update:modelValue', 'save', 'file-change'])

const editorContainer = ref(null)
const zenEditorContainer = ref(null)
const isZenMode = ref(false)
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
  const aiMsg = `What does this do? One sentence only.\n\n${configSnippet}`
  sendAiMessageWithContext(displayMsg, aiMsg)
}

const askIfCorrect = () => {
  if (!selectedText.value) return
  hideContextMenu()
  const configSnippet = selectedText.value.trim()
  const displayMsg = `Is this correct?\n${configSnippet}`
  const aiMsg = `Is this correct? Answer only YES or NO with one short reason. If NO, tell me what to change it to.\n\n${configSnippet}`
  sendAiMessageWithContext(displayMsg, aiMsg)
}

const askToImprove = () => {
  if (!selectedText.value) return
  hideContextMenu()
  const configSnippet = selectedText.value.trim()
  const displayMsg = `How to improve?\n${configSnippet}`
  const aiMsg = `Can this be improved? Say "Already optimal" or "Change to [value] because [reason]". One sentence only.\n\n${configSnippet}`
  sendAiMessageWithContext(displayMsg, aiMsg)
}

const sendAiMessageWithContext = async (displayMessage, aiMessage) => {
  aiMessages.value.push({ role: 'user', content: displayMessage })
  scrollToAiBottom()
  
  aiTyping.value = true
  try {
    if (!aiConversationId.value) {
      const conversation = await aiHelper.createConversation(`Config: ${props.service}`, 'config')
      aiConversationId.value = conversation.id
    }
    
    const context = {
      type: 'config_question',
      service: props.service
    }
    
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

const toggleAiPanel = () => {
  aiPanelOpen.value = !aiPanelOpen.value
  if (aiPanelOpen.value) {
    nextTick(() => {
      if (zenEditorView) {
        destroyZenEditor()
        createZenEditor()
      }
    })
  }
}

const loadConfigToAi = async () => {
  aiLoading.value = true
  try {
    const conversation = await aiHelper.createConversation(
      `Config: ${props.service} - ${props.selectedFile || 'config'}`,
      'config',
      { service: props.service, file: props.selectedFile }
    )
    aiConversationId.value = conversation.id
    
    const context = {
      type: 'config_analysis',
      service: props.service,
      file: props.selectedFile,
      content: props.modelValue
    }
    
    const response = await aiHelper.sendMessage(
      aiConversationId.value,
      `Analyze this configuration:\n\n\`\`\`\n${props.modelValue}\n\`\`\``,
      context
    )
    
    aiMessages.value = [
      { role: 'user', content: 'Config file loaded for analysis' },
      { role: 'assistant', content: response.message || 'Configuration loaded! Ask me anything.' }
    ]
    configLoaded.value = true
    scrollToAiBottom()
  } catch (e) {
    console.error('Failed to load config to AI:', e)
    aiMessages.value = [{ 
      role: 'assistant', 
      content: 'Failed to connect to AI assistant. Please check that the AI Helper is configured in Settings.' 
    }]
  } finally {
    aiLoading.value = false
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
      content: 'Error communicating with AI. Please try again.' 
    })
  } finally {
    aiTyping.value = false
    scrollToAiBottom()
  }
}

const askQuickQuestionWithHint = (displayQuestion, hint) => {
  const aiMessage = `${displayQuestion} (${hint})`
  sendAiMessageWithContext(displayQuestion, aiMessage)
}

const handleAiKeydown = (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    sendAiMessage()
  }
}

const scrollToAiBottom = () => {
  nextTick(() => {
    if (aiMessagesEnd.value) {
      aiMessagesEnd.value.scrollIntoView({ behavior: 'smooth' })
    }
  })
}

marked.setOptions({ breaks: true, gfm: true, headerIds: false, mangle: false })

const renderMarkdown = (text) => {
  try {
    let processed = text
      .replace(/```[\w]*\n?/g, '')
      .replace(/\*\*GOOD:?\*\*/gi, '<div class="ai-section ai-good"><span class="material-symbols-rounded">check_circle</span><strong>Good</strong></div>')
      .replace(/\*\*ISSUES:?\*\*/gi, '<div class="ai-section ai-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>')
      .replace(/\*\*RECOMMENDATIONS:?\*\*/gi, '<div class="ai-section ai-rec"><span class="material-symbols-rounded">lightbulb</span><strong>Recommendations</strong></div>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>')
    
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
        return `<p>${line}</p>`
      })
      .join('')
    
    html = html.replace(/(<li>.*?<\/li>)+/g, '<ul>$&</ul>')
    return html
  } catch (e) {
    return text.replace(/\n/g, '<br>')
  }
}

watch(() => props.selectedFile, () => {
  configLoaded.value = false
  aiMessages.value = []
  aiConversationId.value = null
})

// Custom config file language definition
const configLanguage = StreamLanguage.define({
  token(stream) {
    if (stream.match(/^[#;].*/)) return 'comment'
    if (stream.match(/^[{}[\]]/)) return 'brace'
    if (stream.match(/^"[^"]*"/)) return 'string'
    if (stream.match(/^'[^']*'/)) return 'string'
    if (stream.match(/^[a-zA-Z_][\w.-]*(?=\s*[=:])/)) return 'variableName'
    if (stream.sol() && stream.match(/^[a-zA-Z_][\w.-]*/)) return 'keyword'
    if (stream.match(/^-?\d+(\.\d+)?[KMGkmg]?/)) return 'number'
    if (stream.match(/^(yes|no|true|false|on|off|enabled|disabled)\b/i)) return 'bool'
    if (stream.match(/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/)) return 'number'
    if (stream.match(/^\/[\w./-]+/)) return 'string'
    if (stream.match(/^\[[^\]]+\]/)) return 'heading'
    if (stream.eatSpace()) return null
    stream.next()
    return null
  }
})

const getLanguageExtension = () => {
  switch (props.language) {
    case 'html': case 'htm': return html()
    case 'css': case 'scss': case 'less': return css()
    case 'js': case 'javascript': case 'jsx': case 'ts': case 'typescript': case 'tsx':
      return javascript({ jsx: props.language.includes('x'), typescript: props.language.startsWith('ts') })
    case 'json': return json()
    case 'php': return php()
    case 'xml': case 'svg': return xml()
    case 'sql': return sql()
    default: return configLanguage
  }
}

const darkHighlightStyle = HighlightStyle.define([
  { tag: tags.comment, color: '#6b7280', fontStyle: 'italic' },
  { tag: tags.variableName, color: '#60a5fa' },
  { tag: tags.keyword, color: '#c084fc' },
  { tag: tags.string, color: '#34d399' },
  { tag: tags.number, color: '#f97316' },
  { tag: tags.bool, color: '#fbbf24' },
  { tag: tags.brace, color: '#f472b6' },
  { tag: tags.heading, color: '#22d3ee', fontWeight: 'bold' },
  { tag: tags.tagName, color: '#22d3ee' },
  { tag: tags.attributeName, color: '#a78bfa' },
  { tag: tags.attributeValue, color: '#34d399' },
])

const editorTheme = EditorView.theme({
  '&': {
    backgroundColor: '#0f172a',
    color: '#e2e8f0',
    fontSize: '13px',
    fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
  },
  '.cm-content': { padding: '16px', caretColor: '#60a5fa' },
  '.cm-cursor': { borderLeftColor: '#60a5fa', borderLeftWidth: '2px' },
  '.cm-activeLine': { backgroundColor: 'rgba(59, 130, 246, 0.1)' },
  '.cm-activeLineGutter': { backgroundColor: 'rgba(59, 130, 246, 0.1)' },
  '.cm-gutters': { backgroundColor: '#0f172a', color: '#475569', border: 'none', borderRight: '1px solid #1e293b' },
  '.cm-lineNumbers .cm-gutterElement': { padding: '0 12px 0 8px' },
  '.cm-selectionBackground': { backgroundColor: 'rgba(59, 130, 246, 0.3) !important' },
  '.cm-scroller': { overflow: 'auto' },
  '.cm-panels': { backgroundColor: '#1e293b', color: '#e2e8f0' },
  '.cm-textfield': { backgroundColor: '#0f172a !important', border: '1px solid #334155 !important', borderRadius: '6px !important', color: '#e2e8f0 !important', padding: '6px 12px !important' },
  '.cm-button': { background: 'transparent !important', border: '1px solid #334155 !important', borderRadius: '6px !important', color: '#94a3b8 !important', padding: '5px 12px !important' },
  '.cm-searchMatch': { backgroundColor: 'rgba(250, 204, 21, 0.3)', borderRadius: '2px' },
  '.cm-searchMatch-selected': { backgroundColor: 'rgba(59, 130, 246, 0.4)' },
}, { dark: true })

const zenEditorTheme = EditorView.theme({
  '&': {
    backgroundColor: '#0f172a',
    color: '#e2e8f0',
    fontSize: '15px',
    fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
  },
  '.cm-content': { padding: '24px', caretColor: '#60a5fa' },
  '.cm-cursor': { borderLeftColor: '#60a5fa', borderLeftWidth: '2px' },
  '.cm-activeLine': { backgroundColor: 'rgba(59, 130, 246, 0.08)' },
  '.cm-activeLineGutter': { backgroundColor: 'rgba(59, 130, 246, 0.08)' },
  '.cm-gutters': { backgroundColor: '#0f172a', color: '#475569', border: 'none', borderRight: '1px solid #1e293b', paddingRight: '8px' },
  '.cm-lineNumbers .cm-gutterElement': { padding: '0 16px 0 12px' },
  '.cm-selectionBackground': { backgroundColor: 'rgba(59, 130, 246, 0.3) !important' },
  '.cm-scroller': { overflow: 'auto' },
  '.cm-panels': { backgroundColor: '#1e293b', color: '#e2e8f0' },
  '.cm-textfield': { backgroundColor: '#0f172a !important', border: '1px solid #334155 !important', borderRadius: '6px !important', color: '#e2e8f0 !important', padding: '6px 12px !important' },
  '.cm-button': { background: 'transparent !important', border: '1px solid #334155 !important', borderRadius: '6px !important', color: '#94a3b8 !important', padding: '5px 12px !important' },
  '.cm-searchMatch': { backgroundColor: 'rgba(250, 204, 21, 0.3)', borderRadius: '2px' },
  '.cm-searchMatch-selected': { backgroundColor: 'rgba(59, 130, 246, 0.4)' },
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
    search({ top: true }),
    keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap]),
    getLanguageExtension(),
    syntaxHighlighting(darkHighlightStyle),
    isZen ? zenEditorTheme : editorTheme,
    EditorView.lineWrapping,
    EditorView.updateListener.of((update) => {
      if (update.docChanged) {
        const newValue = update.state.doc.toString()
        emit('update:modelValue', newValue)
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
      editorView.dispatch({ changes: { from: 0, to: editorView.state.doc.length, insert: value } })
    }
  } else if (source === 'normal' && zenEditorView) {
    const currentValue = zenEditorView.state.doc.toString()
    if (currentValue !== value) {
      zenEditorView.dispatch({ changes: { from: 0, to: zenEditorView.state.doc.length, insert: value } })
    }
  }
}

const createEditor = () => {
  if (!editorContainer.value) return
  editorView = new EditorView({
    state: EditorState.create({ doc: props.modelValue, extensions: createExtensions(false) }),
    parent: editorContainer.value,
  })
}

const createZenEditor = () => {
  if (!zenEditorContainer.value) return
  zenEditorView = new EditorView({
    state: EditorState.create({ doc: props.modelValue, extensions: createExtensions(true) }),
    parent: zenEditorContainer.value,
  })
  zenEditorView.focus()
}

const destroyEditor = () => { if (editorView) { editorView.destroy(); editorView = null } }
const destroyZenEditor = () => { if (zenEditorView) { zenEditorView.destroy(); zenEditorView = null } }

const toggleZenMode = async () => {
  isZenMode.value = !isZenMode.value
  if (isZenMode.value) {
    document.body.style.overflow = 'hidden'
    await nextTick()
    createZenEditor()
  } else {
    document.body.style.overflow = ''
    destroyZenEditor()
  }
}

const handleKeydown = (e) => {
  if (e.key === 'Escape' && isZenMode.value) toggleZenMode()
  if (e.key === 's' && (e.ctrlKey || e.metaKey) && isZenMode.value) {
    e.preventDefault()
    emit('save')
  }
}

watch(() => props.modelValue, (newValue) => {
  if (editorView && newValue !== editorView.state.doc.toString()) {
    editorView.dispatch({ changes: { from: 0, to: editorView.state.doc.length, insert: newValue } })
  }
  if (zenEditorView && newValue !== zenEditorView.state.doc.toString()) {
    zenEditorView.dispatch({ changes: { from: 0, to: zenEditorView.state.doc.length, insert: newValue } })
  }
})

watch(() => props.readonly, () => {
  destroyEditor()
  destroyZenEditor()
  createEditor()
  if (isZenMode.value) createZenEditor()
})

onMounted(() => {
  createEditor()
  document.addEventListener('keydown', handleKeydown)
  document.addEventListener('click', hideContextMenu)
  document.addEventListener('contextmenu', handleEditorContextMenu)
})

onUnmounted(() => {
  destroyEditor()
  destroyZenEditor()
  document.removeEventListener('keydown', handleKeydown)
  document.removeEventListener('click', hideContextMenu)
  document.removeEventListener('contextmenu', handleEditorContextMenu)
  document.body.style.overflow = ''
})

defineExpose({ toggleZenMode, isZenMode, checkSyntax, syntaxResult, checkingSyntax })
</script>

<style scoped>
.config-editor-wrapper { position: relative; }

.config-editor {
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid var(--color-border);
}

.editor-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
}

.toolbar-left, .toolbar-right { display: flex; gap: 8px; }

.toolbar-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  font-size: 13px;
  color: var(--color-text-muted);
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.toolbar-btn:hover { color: var(--color-text); background: var(--color-bg); border-color: var(--color-primary); }
.toolbar-btn .material-symbols-rounded { font-size: 18px; }

.editor-container { height: v-bind(height); }
.editor-container :deep(.cm-editor) { height: 100%; }

/* Zen Mode */
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

.zen-title .material-symbols-rounded { font-size: 22px; color: #60a5fa; }

.zen-file-selector { flex: 1; display: flex; justify-content: center; padding: 0 20px; }

.zen-file-dropdown {
  background: #1e293b;
  border: 1px solid #334155;
  color: #e2e8f0;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 14px;
  cursor: pointer;
  min-width: 280px;
}

.zen-file-dropdown:hover { border-color: #60a5fa; }

.zen-header-actions { display: flex; align-items: center; gap: 12px; }

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

.zen-ai-btn:hover { background: linear-gradient(135deg, #7c3aed 0%, #9333ea 100%); transform: translateY(-1px); }
.zen-ai-btn.active { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); }
.zen-ai-btn .material-symbols-rounded { font-size: 20px; }

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
}

.zen-minimize-btn:hover { background: #334155; }

.zen-content { flex: 1; display: flex; overflow: hidden; }
.zen-content.split-view .zen-editor-container { min-width: 30%; max-width: 80%; }

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

.resize-handle:hover { background: #6366f1; }

.resize-handle-grip {
  width: 4px;
  height: 40px;
  background: repeating-linear-gradient(0deg, #475569, #475569 2px, transparent 2px, transparent 6px);
  border-radius: 2px;
}

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
}

.context-menu-item:hover { background: #334155; }
.context-menu-item .material-symbols-rounded { font-size: 18px; color: #8b5cf6; }

.zen-editor-container { flex: 1; overflow: hidden; }
.zen-editor { height: 100%; }
.zen-editor :deep(.cm-editor) { height: 100%; }

.zen-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 24px;
  background: #0f172a;
  border-top: 1px solid #1e293b;
}

.zen-footer-left {
  display: flex;
  align-items: center;
  gap: 24px;
}

.zen-shortcuts { display: flex; gap: 20px; font-size: 13px; color: #64748b; }

.syntax-result {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 500;
}

.syntax-result.valid { color: #4ade80; }
.syntax-result.invalid { color: #f87171; }
.syntax-result .material-symbols-rounded { font-size: 18px; }

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

.zen-actions { display: flex; gap: 12px; }

.zen-fade-enter-active, .zen-fade-leave-active { transition: opacity 0.2s ease; }
.zen-fade-enter-from, .zen-fade-leave-to { opacity: 0; }

/* AI Panel */
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

.ai-panel-title .material-symbols-rounded { color: #8b5cf6; }
.ai-panel-actions { display: flex; align-items: center; gap: 8px; }

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
}

.ai-load-btn:hover:not(:disabled) { background: #334155; border-color: #6366f1; }
.ai-load-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.ai-close-btn {
  display: flex;
  padding: 6px;
  color: #94a3b8;
  background: transparent;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.ai-close-btn:hover { color: #e2e8f0; background: #334155; }

.ai-panel-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.ai-messages { flex: 1; overflow-y: auto; padding: 16px; }

.ai-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  text-align: center;
  padding: 24px;
}

.ai-suggestions { display: flex; flex-direction: column; gap: 8px; width: 100%; max-width: 280px; }

.ai-suggestion-btn {
  padding: 10px 16px;
  font-size: 12px;
  color: #94a3b8;
  background: #0c1222;
  border: 1px solid #1e293b;
  border-radius: 8px;
  cursor: pointer;
  text-align: left;
}

.ai-suggestion-btn:hover { color: #e2e8f0; background: #1e293b; border-color: #6366f1; }

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

.ai-message-content :deep(.ai-good) { color: #4ade80; border-color: #4ade80; }
.ai-message-content :deep(.ai-bad) { color: #f87171; border-color: #f87171; }
.ai-message-content :deep(.ai-rec) { color: #fbbf24; border-color: #fbbf24; }

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

.animate-spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

