<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { marked } from 'marked'
import aiHelper from '@/services/aiHelper'

const props = defineProps({
  conversationId: {
    type: Number,
    default: null
  },
  messages: {
    type: Array,
    default: () => []
  },
  isTyping: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['send-message', 'run-command', 'analyze-config'])

const messageInput = ref('')
const sending = ref(false)
const messagesEnd = ref(null)

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesEnd.value) {
      messagesEnd.value.scrollIntoView({ behavior: 'smooth' })
    }
  })
}

watch(() => props.messages, () => {
  scrollToBottom()
}, { deep: true })

const sendMessage = async () => {
  if (!messageInput.value.trim() || sending.value) return

  const message = messageInput.value.trim()
  messageInput.value = ''
  sending.value = true

  try {
    await emit('send-message', message)
  } catch (e) {
    console.error('Failed to send message', e)
  } finally {
    sending.value = false
    scrollToBottom()
  }
}

const handleKeyDown = (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    sendMessage()
  }
}

// Configure marked for safe rendering
marked.setOptions({
  breaks: true,
  gfm: true,
  headerIds: false,
  mangle: false
})

const renderMarkdown = (text) => {
  try {
    // First, extract code blocks to preserve them
    const codeBlockRegex = /```(\w+)?\n([\s\S]*?)```/g
    const codeBlocks = []
    let codeBlockIndex = 0
    let processedText = text.replace(codeBlockRegex, (match, lang, code) => {
      const placeholder = `__CODE_BLOCK_${codeBlockIndex}__`
      codeBlocks.push({ placeholder, lang: lang || 'text', code })
      codeBlockIndex++
      return placeholder
    })

    // Clean up markdown artifacts before processing
    processedText = processedText.replace(/\*\*\s*and\s*\*\*:?/gi, '') // Remove "** and **:"
    processedText = processedText.replace(/\band\s+\*\*:?\s*$/gm, '') // Remove trailing "and **:"
    processedText = processedText.replace(/\*\*:/g, ':') // Fix "**:" to just ":"
    processedText = processedText.replace(/=\s*\*\*\s*/g, '= ') // Fix "= **"
    processedText = processedText.replace(/\*\*\s*=/g, ' =') // Fix "** ="
    processedText = processedText.replace(/`([^`\n]+)\*\*`/g, '`$1`') // Fix "`value**`"
    processedText = processedText.replace(/`\*\*([^`\n]+)`/g, '`$1`') // Fix "`**value`"
    processedText = processedText.replace(/(Observation|Note|Analysis|Current Setting)\*\*:?\s*/gi, '') // Remove labels with **

    // Convert [[filepath]] to pill format before markdown processing
    processedText = processedText.replace(/\[\[([^\]]+)\]\]/g, (match, filepath) => {
      return `<span class="config-file-pill">${filepath}</span>`
    })

    // Normalize section headers before markdown processing - handle ALL variations
    // Handle **GOOD:** format
    processedText = processedText.replace(/\*\*GOOD:\*\*/gi, '\n\n---GOOD_HEADER---\n\n')
    processedText = processedText.replace(/\*\*BAD:\*\*/gi, '\n\n---BAD_HEADER---\n\n')
    processedText = processedText.replace(/\*\*ISSUES:\*\*/gi, '\n\n---BAD_HEADER---\n\n')
    processedText = processedText.replace(/\*\*RECOMMENDATIONS:\*\*/gi, '\n\n---RECOMMENDATIONS_HEADER---\n\n')
    
    // Handle GOOD_SECTION, BAD_SECTION etc format (with or without underscores)
    processedText = processedText.replace(/^GOOD[_\s]*SECTION\s*$/gim, '\n\n---GOOD_HEADER---\n\n')
    processedText = processedText.replace(/^BAD[_\s]*SECTION\s*$/gim, '\n\n---BAD_HEADER---\n\n')
    processedText = processedText.replace(/^ISSUES[_\s]*SECTION\s*$/gim, '\n\n---BAD_HEADER---\n\n')
    processedText = processedText.replace(/^RECOMMENDATIONS[_\s]*SECTION\s*$/gim, '\n\n---RECOMMENDATIONS_HEADER---\n\n')
    
    // Handle inline versions too
    processedText = processedText.replace(/GOOD[_\s]*SECTION/gi, '\n\n---GOOD_HEADER---\n\n')
    processedText = processedText.replace(/BAD[_\s]*SECTION/gi, '\n\n---BAD_HEADER---\n\n')
    processedText = processedText.replace(/ISSUES[_\s]*SECTION/gi, '\n\n---BAD_HEADER---\n\n')
    processedText = processedText.replace(/RECOMMENDATIONS[_\s]*SECTION/gi, '\n\n---RECOMMENDATIONS_HEADER---\n\n')

    // Render markdown
    let html = marked.parse(processedText)

    // Clean up any paragraph wrappers around our headers
    html = html.replace(/<p>---GOOD_HEADER---<\/p>/g, '---GOOD_HEADER---')
    html = html.replace(/<p>---BAD_HEADER---<\/p>/g, '---BAD_HEADER---')
    html = html.replace(/<p>---RECOMMENDATIONS_HEADER---<\/p>/g, '---RECOMMENDATIONS_HEADER---')

    // Replace with styled section headers
    html = html.replace(
      /---GOOD_HEADER---/g,
      '<div class="section-header section-good"><span class="material-symbols-rounded">check_circle</span><strong>Good</strong></div>'
    )
    
    html = html.replace(
      /---BAD_HEADER---/g,
      '<div class="section-header section-bad"><span class="material-symbols-rounded">error</span><strong>Issues</strong></div>'
    )

    html = html.replace(
      /---RECOMMENDATIONS_HEADER---/g,
      '<div class="section-header section-recommendations"><span class="material-symbols-rounded">lightbulb</span><strong>Recommendations</strong></div>'
    )

    // Process section content - wrap list items with appropriate styling
    // BAD section
    html = html.replace(
      /(<div class="section-header section-bad">[\s\S]*?<\/div>)([\s\S]*?)(?=<div class="section-header|$)/g,
      (match, header, content) => {
        const processedContent = content
          .replace(/<li>/g, '<li class="list-item list-item-bad"><span class="material-symbols-rounded item-icon">error</span><span class="item-content">')
          .replace(/<\/li>/g, '</span></li>')
        return header + '<div class="section-content section-content-bad">' + processedContent + '</div>'
      }
    )

    // GOOD section
    html = html.replace(
      /(<div class="section-header section-good">[\s\S]*?<\/div>)([\s\S]*?)(?=<div class="section-header|$)/g,
      (match, header, content) => {
        const processedContent = content
          .replace(/<li>/g, '<li class="list-item list-item-good"><span class="material-symbols-rounded item-icon">check_circle</span><span class="item-content">')
          .replace(/<\/li>/g, '</span></li>')
        return header + '<div class="section-content section-content-good">' + processedContent + '</div>'
      }
    )

    // RECOMMENDATIONS section
    html = html.replace(
      /(<div class="section-header section-recommendations">[\s\S]*?<\/div>)([\s\S]*?)(?=<div class="section-header|$)/g,
      (match, header, content) => {
        const processedContent = content
          .replace(/<li>/g, '<li class="list-item list-item-recommendations"><span class="material-symbols-rounded item-icon">lightbulb</span><span class="item-content">')
          .replace(/<\/li>/g, '</span></li>')
        return header + '<div class="section-content section-content-recommendations">' + processedContent + '</div>'
      }
    )

    // Replace code block placeholders with styled code blocks
    codeBlocks.forEach(({ placeholder, lang, code }) => {
      const escapedCode = code
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
      
      html = html.replace(
        placeholder,
        `<div class="bg-surface-900 dark:bg-surface-950 rounded-lg p-3 overflow-x-auto my-2"><pre class="text-sm text-green-400 font-mono"><code class="language-${lang}">${escapedCode}</code></pre></div>`
      )
    })

    return html
  } catch (e) {
    console.error('Markdown rendering error:', e)
    return text.replace(/\n/g, '<br>')
  }
}

const extractCodeBlocks = (text) => {
  const codeBlockRegex = /```(\w+)?\n([\s\S]*?)```/g
  const parts = []
  let lastIndex = 0
  let match

  while ((match = codeBlockRegex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      parts.push({
        type: 'text',
        content: text.substring(lastIndex, match.index)
      })
    }
    parts.push({
      type: 'code',
      language: match[1] || 'text',
      content: match[2]
    })
    lastIndex = match.index + match[0].length
  }

  if (lastIndex < text.length) {
    parts.push({
      type: 'text',
      content: text.substring(lastIndex)
    })
  }

  return parts.length > 0 ? parts : [{ type: 'text', content: text }]
}

const extractCommands = (text) => {
  const commandRegex = /`([^`]+)`/g
  const commands = []
  let match

  while ((match = commandRegex.exec(text)) !== null) {
    const cmd = match[1].trim()
    if (cmd.startsWith('$') || cmd.match(/^(grep|cat|ls|tail|head|find|stat|systemctl|journalctl)/)) {
      commands.push(cmd.replace(/^\$/, '').trim())
    }
  }

  return commands
}
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Messages -->
    <div class="flex-1 overflow-y-auto p-3 space-y-3">
      <div v-if="messages.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-3xl mb-2 block opacity-50">psychology</span>
        <p class="text-sm">Start a conversation with the AI helper</p>
        <p class="text-xs mt-1 text-surface-400">Ask about config issues, email problems, or site crashes</p>
      </div>

      <div
        v-for="(message, index) in messages"
        :key="index"
        :class="[
          'flex gap-2',
          message.role === 'user' ? 'justify-end' : 'justify-start'
        ]"
      >
        <div
          :class="[
            'max-w-[85%] rounded-lg px-3 py-2',
            message.role === 'user'
              ? 'bg-primary-500 text-white text-sm'
              : 'bg-surface-100 dark:bg-surface-800 text-surface-900 dark:text-surface-100'
          ]"
        >
          <div v-if="message.role === 'assistant'" class="space-y-1 markdown-content">
            <div 
              v-html="renderMarkdown(message.content)"
            ></div>

            <!-- Action buttons for commands -->
            <div v-if="message.role === 'assistant'" class="flex flex-wrap gap-1.5 mt-2 pt-2 border-t border-surface-200 dark:border-surface-700">
              <button
                v-for="(cmd, cmdIndex) in extractCommands(message.content)"
                :key="cmdIndex"
                @click="$emit('run-command', cmd)"
                class="px-1.5 py-0.5 text-[10px] rounded bg-primary-500/20 text-primary-600 dark:text-primary-400 hover:bg-primary-500/30 transition-colors flex items-center gap-0.5"
              >
                <span class="material-symbols-rounded text-[10px]">play_arrow</span>
                {{ cmd.substring(0, 25) }}{{ cmd.length > 25 ? '...' : '' }}
              </button>
            </div>
          </div>
          <div v-else class="whitespace-pre-wrap text-sm">{{ message.content }}</div>
        </div>
      </div>

      <div v-if="sending || isTyping" class="flex justify-start">
        <div class="bg-surface-100 dark:bg-surface-800 rounded-lg px-3 py-2">
          <div class="flex items-center gap-1.5 text-sm">
            <span class="material-symbols-rounded animate-spin text-primary-500 text-sm">sync</span>
            <span>{{ sending ? 'Sending...' : 'AI is thinking...' }}</span>
          </div>
        </div>
      </div>

      <div ref="messagesEnd"></div>
    </div>

    <!-- Input -->
    <div class="p-2 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900">
      <div class="flex gap-1.5">
        <textarea
          v-model="messageInput"
          @keydown="handleKeyDown"
          :disabled="sending"
          placeholder="Ask a question..."
          rows="2"
          class="flex-1 px-3 py-1.5 text-sm rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-transparent resize-none disabled:opacity-50"
        ></textarea>
        <button
          @click="sendMessage"
          :disabled="sending || !messageInput.trim()"
          class="px-3 py-1.5 rounded-lg bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1"
        >
          <span class="material-symbols-rounded text-sm">send</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.markdown-content :deep(h1),
.markdown-content :deep(h2),
.markdown-content :deep(h3),
.markdown-content :deep(h4),
.markdown-content :deep(h5),
.markdown-content :deep(h6) {
  @apply font-semibold mt-4 mb-2 leading-tight;
}

.markdown-content :deep(h1) {
  @apply text-lg;
}

.markdown-content :deep(h2) {
  @apply text-base;
}

.markdown-content :deep(h3) {
  @apply text-sm;
}

.markdown-content :deep(p) {
  @apply my-1.5 text-sm;
}

.markdown-content :deep(ul),
.markdown-content :deep(ol) {
  @apply my-1 pl-0 list-none;
}

.markdown-content :deep(li) {
  @apply my-0.5;
}

.markdown-content :deep(strong) {
  @apply font-semibold;
}

.markdown-content :deep(em) {
  @apply italic;
}

.markdown-content :deep(code:not(pre code)) {
  @apply bg-surface-200 dark:bg-surface-700 text-primary-600 dark:text-primary-400 px-1 py-0.5 rounded text-xs font-mono;
}

.markdown-content :deep(blockquote) {
  @apply border-l-2 border-primary-500 pl-3 my-3 italic text-surface-600 dark:text-surface-400 text-sm;
}

.markdown-content :deep(hr) {
  @apply border-0 border-t border-surface-300 dark:border-surface-600 my-4;
}

.markdown-content :deep(table) {
  @apply border-collapse w-full my-3 text-sm;
}

.markdown-content :deep(th),
.markdown-content :deep(td) {
  @apply border border-surface-300 dark:border-surface-600 p-1.5 text-left;
}

.markdown-content :deep(th) {
  @apply bg-surface-100 dark:bg-surface-800 font-semibold;
}

.markdown-content :deep(a) {
  @apply text-primary-600 dark:text-primary-400 underline hover:text-primary-700 dark:hover:text-primary-300;
}

/* Config file pill styling */
.markdown-content :deep(.config-file-pill) {
  @apply inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium
         bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300
         border border-slate-200 dark:border-slate-600 ml-1;
}

/* Section headers */
.markdown-content :deep(.section-header) {
  @apply flex items-center gap-1.5 mt-6 mb-2 pb-1.5 border-b text-sm font-semibold;
}

.markdown-content :deep(.section-header:first-child) {
  @apply mt-2;
}

.markdown-content :deep(.section-header .material-symbols-rounded) {
  @apply text-base;
}

.markdown-content :deep(.section-good) {
  @apply border-green-400/50 text-green-600 dark:text-green-400;
}

.markdown-content :deep(.section-bad) {
  @apply border-red-400/50 text-red-600 dark:text-red-400;
}

.markdown-content :deep(.section-recommendations) {
  @apply border-amber-400/50 text-amber-600 dark:text-amber-400;
}

/* Section content containers */
.markdown-content :deep(.section-content) {
  @apply rounded-lg p-2 mb-4;
}

.markdown-content :deep(.section-content-good) {
  @apply bg-green-50/50 dark:bg-green-500/10;
}

.markdown-content :deep(.section-content-bad) {
  @apply bg-red-50 dark:bg-red-500/15 border border-red-200 dark:border-red-500/30;
}

.markdown-content :deep(.section-content-recommendations) {
  @apply bg-amber-50/50 dark:bg-amber-500/10;
}

/* List items */
.markdown-content :deep(.list-item) {
  @apply flex items-start gap-1.5 p-1.5 rounded-md my-1 text-xs;
}

.markdown-content :deep(.list-item .item-icon) {
  @apply text-sm flex-shrink-0 mt-0.5;
}

.markdown-content :deep(.list-item .item-content) {
  @apply flex-1;
}

.markdown-content :deep(.list-item-good) {
  @apply bg-green-100/50 dark:bg-green-500/10;
}

.markdown-content :deep(.list-item-good .item-icon) {
  @apply text-green-500;
}

.markdown-content :deep(.list-item-bad) {
  @apply bg-red-100 dark:bg-red-500/20 border-l-2 border-red-500;
}

.markdown-content :deep(.list-item-bad .item-icon) {
  @apply text-red-500;
}

.markdown-content :deep(.list-item-bad .item-content) {
  @apply text-red-800 dark:text-red-200;
}

.markdown-content :deep(.list-item-recommendations) {
  @apply bg-amber-100/50 dark:bg-amber-500/10;
}

.markdown-content :deep(.list-item-recommendations .item-icon) {
  @apply text-amber-500;
}
</style>

