<script setup>
import { ref, computed, watch } from 'vue'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useComposeStore } from '@/stores/compose'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useMailboxStore } from '@/stores/mailbox'
import { useToastStore } from '@/stores/toast'
import { useRouter } from 'vue-router'
import { isDebugEnabled } from '@/utils/debug'
import { useAddons } from '@/composables/useAddons'

const emit = defineEmits(['refresh'])

const aiStore = useAIStore()
const compose = useComposeStore()
const todosStore = useTodosStore()
const mailbox = useMailboxStore()
const toast = useToastStore()
const router = useRouter()
const { tasksEnabled } = useAddons()

// State for draft reply
const showDraftOptions = ref(false)
const draftInstructions = ref('')
const generatingDraft = ref(false)
const loadingActionIndex = ref(null) // Track which action button is loading

// Current message for reference
const currentMessage = computed(() => mailbox.currentMessage)

// Summary data
const summary = computed(() => {
  isDebugEnabled() && console.log('AISummaryPanel - currentSummary:', aiStore.currentSummary)
  return aiStore.currentSummary
})
const loading = computed(() => aiStore.summarizing)
const error = computed(() => aiStore.summaryError)

// Format main points as bullet points
const mainPoints = computed(() => {
  if (!summary.value?.main_points) return []
  return summary.value.main_points
})

// Get action items
const actionItems = computed(() => {
  if (!summary.value?.action_items) return []
  return summary.value.action_items
})

// Get suggested actions
const suggestedActions = computed(() => {
  if (!summary.value?.suggested_actions) return []
  return summary.value.suggested_actions
})

// Close panel
function closePanel() {
  aiStore.closeSummaryPanel()
}

// Go to settings to configure AI
function goToSettings() {
  closePanel()
  router.push('/settings?tab=ai')
}

// Handle suggested action click
async function handleSuggestedAction(action, index) {
  if (action.type === 'reply') {
    loadingActionIndex.value = index
    
    // Get the email content to reply to
    const emailContent = getEmailContent()
    
    // Generate draft with the action's prompt as instructions
    const result = await aiStore.draftReply(emailContent, null, action.prompt)
    
    loadingActionIndex.value = null
    
    if (result.success) {
      // Open compose first to initialize
      await compose.open('reply', currentMessage.value)
      
      // Build proper body with AI draft, signature, and quoted message
      const aiDraftHtml = formatAIDraftAsHtml(result.draft)
      insertAIDraftIntoCompose(aiDraftHtml)
      
      closePanel()
      toast.success('Draft generated')
    } else {
      toast.error(result.error || 'Failed to generate draft')
    }
  }
}

// Format AI draft text as HTML paragraphs
function formatAIDraftAsHtml(text) {
  if (!text) return ''
  // Split by double newlines for paragraphs
  const paragraphs = text.split(/\n\n+/).filter(p => p.trim())
  return paragraphs.map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('')
}

// Insert AI draft at the beginning of the compose body (before signature and quote)
function insertAIDraftIntoCompose(aiDraftHtml) {
  const currentBody = compose.draft.body || ''
  
  // Remove any leading empty paragraphs from current body
  const cleanedBody = currentBody.replace(/^(\s*<p><br\s*\/?><\/p>\s*)+/i, '')
  
  // Insert AI draft directly at start, then add spacing before remaining content
  if (cleanedBody.trim()) {
    compose.draft.body = aiDraftHtml + '<p><br></p>' + cleanedBody
  } else {
    compose.draft.body = aiDraftHtml
  }
  
  compose.markAsEdited()
}

// Generate custom draft reply
async function generateCustomDraft() {
  if (!draftInstructions.value.trim()) {
    toast.warning('Please enter instructions for the draft')
    return
  }
  
  generatingDraft.value = true
  
  const emailContent = getEmailContent()
  const result = await aiStore.draftReply(emailContent, null, draftInstructions.value)
  
  generatingDraft.value = false
  
  if (result.success) {
    // Open compose first to initialize
    await compose.open('reply', currentMessage.value)
    
    // Build proper body with AI draft, signature, and quoted message
    const aiDraftHtml = formatAIDraftAsHtml(result.draft)
    insertAIDraftIntoCompose(aiDraftHtml)
    
    showDraftOptions.value = false
    draftInstructions.value = ''
    closePanel()
    toast.success('Draft generated')
  } else {
    toast.error(result.error || 'Failed to generate draft')
  }
}

// Create todo from action item
async function createTodoFromAction(actionItem) {
  if (!currentMessage.value) return
  
  const todo = await todosStore.createFromEmail({
    folder: mailbox.currentFolder,
    uid: currentMessage.value.uid,
    message_id: currentMessage.value.message_id,
    subject: currentMessage.value.subject,
    from_email: currentMessage.value.from?.[0]?.email,
    date: currentMessage.value.date
  }, actionItem)
  
  if (todo) {
    toast.success('Todo created')
    todosStore.openPanel()
  }
}

// Create all todos from action items as a grouped todo with subtasks
async function createAllTodosFromActions() {
  if (!currentMessage.value || !actionItems.value.length) return
  
  const emailRef = {
    folder: mailbox.currentFolder,
    uid: currentMessage.value.uid,
    message_id: currentMessage.value.message_id,
    subject: currentMessage.value.subject,
    from_email: currentMessage.value.from?.[0]?.email,
    date: currentMessage.value.date
  }
  
  // Join all action items with newlines - backend will auto-create parent + subtasks
  const allActionsText = actionItems.value.join('\n')
  
  const todo = await todosStore.createFromEmail(emailRef, allActionsText)
  
  if (todo) {
    toast.success(`Todo created with ${actionItems.value.length} subtask${actionItems.value.length > 1 ? 's' : ''}`)
    todosStore.openPanel()
  } else {
    toast.error('Failed to create todo')
  }
}

// Get email content for API calls
function getEmailContent() {
  if (!currentMessage.value) return ''
  
  // Build content from conversation messages if available
  if (currentMessage.value.isConversation && currentMessage.value.messages) {
    return currentMessage.value.messages
      .map(msg => {
        const from = msg.from?.[0]?.email || 'Unknown'
        const date = new Date(msg.timestamp * 1000).toLocaleString()
        const body = msg.body_text || stripHtml(msg.body_html) || ''
        return `From: ${from}\nDate: ${date}\nSubject: ${msg.subject}\n\n${body}`
      })
      .join('\n\n---\n\n')
  }
  
  // Single message
  const msg = currentMessage.value
  const from = msg.from?.[0]?.email || 'Unknown'
  const date = new Date(msg.timestamp * 1000).toLocaleString()
  const body = msg.body_text || stripHtml(msg.body_html) || ''
  return `From: ${from}\nDate: ${date}\nSubject: ${msg.subject}\n\n${body}`
}

// Strip HTML tags
function stripHtml(html) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  return doc.body.textContent || ''
}

// Retry summarization
function retrySummarize() {
  if (!currentMessage.value) return
  const content = getEmailContent()
  aiStore.summarize(content)
}
</script>

<template>
  <Teleport to="body">
    <!-- Backdrop -->
    <Transition name="fade">
      <div 
        v-if="aiStore.summaryPanelOpen" 
        class="fixed inset-0 bg-black/30 z-40 lg:hidden"
        @click="closePanel"
      ></div>
    </Transition>
    
    <!-- Panel -->
    <Transition name="slide">
      <div 
        v-if="aiStore.summaryPanelOpen"
        class="fixed right-0 top-0 h-full w-full max-w-md bg-white dark:bg-surface-800 shadow-2xl z-50 flex flex-col"
      >
        <!-- Header -->
        <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between bg-surface-50 dark:bg-surface-900">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">auto_awesome</span>
            <h2 class="font-semibold text-surface-900 dark:text-surface-100">AI Summary</h2>
          </div>
          <div class="flex items-center gap-1">
            <button 
              @click="$emit('refresh')" 
              :disabled="loading"
              class="btn-ghost btn-icon"
              title="Generate new summary"
            >
              <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span>
            </button>
            <button @click="closePanel" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
        </div>
        
        <!-- Content -->
        <div class="flex-1 overflow-y-auto">
          <!-- Loading -->
          <div v-if="loading" class="flex flex-col items-center justify-center py-16 px-4">
            <span class="spinner text-primary-500 mb-4"></span>
            <p class="text-surface-500">Analyzing email...</p>
            <p class="text-xs text-surface-400 mt-1">This may take a few seconds</p>
          </div>
          
          <!-- Too lengthy warning -->
          <div v-else-if="error && error.includes('too lengthy')" class="p-4">
            <div class="p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl">
              <div class="flex items-start gap-3">
                <span class="material-symbols-rounded text-amber-500 mt-0.5">warning</span>
                <div>
                  <p class="font-medium text-amber-700 dark:text-amber-400">Conversation too long</p>
                  <p class="text-sm text-amber-600 dark:text-amber-400 mt-1">{{ error }}</p>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Error -->
          <div v-else-if="error" class="p-4">
            <div class="p-4 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 rounded-xl">
              <div class="flex items-start gap-3">
                <span class="material-symbols-rounded text-red-500 mt-0.5">error</span>
                <div>
                  <p class="font-medium text-red-700 dark:text-red-400">Failed to summarize</p>
                  <p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ error }}</p>
                </div>
              </div>
              
              <!-- Debug Info -->
              <div v-if="aiStore.summaryDebug" class="mt-4 p-3 bg-surface-100 dark:bg-surface-800 rounded-lg text-xs font-mono">
                <p class="font-bold mb-2 text-surface-600 dark:text-surface-400">Debug Info:</p>
                <div class="space-y-1 text-surface-600 dark:text-surface-400">
                  <p v-if="aiStore.summaryDebug.content_received_length">Content received: {{ aiStore.summaryDebug.content_received_length }} chars</p>
                  <p v-if="aiStore.summaryDebug.input_length">Input length: {{ aiStore.summaryDebug.input_length }} chars</p>
                  <p v-if="aiStore.summaryDebug.prompt_length">Prompt length: {{ aiStore.summaryDebug.prompt_length }} chars</p>
                  <p v-if="aiStore.summaryDebug.raw_response_length !== undefined">Response length: {{ aiStore.summaryDebug.raw_response_length }} chars</p>
                  <div v-if="aiStore.summaryDebug.raw_response" class="mt-2">
                    <p class="font-bold">Raw response:</p>
                    <pre class="mt-1 p-2 bg-surface-900 text-green-400 rounded overflow-x-auto whitespace-pre-wrap max-h-32">{{ aiStore.summaryDebug.raw_response }}</pre>
                  </div>
                  <div v-if="aiStore.summaryDebug.prompt_preview" class="mt-2">
                    <p class="font-bold">Prompt preview:</p>
                    <pre class="mt-1 p-2 bg-surface-900 text-yellow-400 rounded overflow-x-auto whitespace-pre-wrap max-h-32">{{ aiStore.summaryDebug.prompt_preview }}</pre>
                  </div>
                </div>
              </div>
              
              <div class="flex gap-2 mt-4">
                <button @click="retrySummarize" class="btn-secondary btn-sm">
                  <span class="material-symbols-rounded">refresh</span>
                  Retry
                </button>
                <button v-if="error.includes('not configured')" @click="goToSettings" class="btn-primary btn-sm">
                  <span class="material-symbols-rounded">settings</span>
                  Configure AI
                </button>
              </div>
            </div>
          </div>
          
          <!-- Summary Content -->
          <div v-else-if="summary" class="p-4 space-y-6">
            <!-- Topic -->
            <div>
              <h3 class="text-xs font-medium text-surface-500 uppercase tracking-wide mb-2">Topic</h3>
              <p class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                {{ summary.topic || 'Email Summary' }}
              </p>
            </div>
            
            <!-- Context -->
            <div v-if="summary.context">
              <h3 class="text-xs font-medium text-surface-500 uppercase tracking-wide mb-2">Context</h3>
              <p class="text-surface-700 dark:text-surface-300">{{ summary.context }}</p>
            </div>
            
            <!-- Main Points -->
            <div v-if="mainPoints.length > 0">
              <h3 class="text-xs font-medium text-surface-500 uppercase tracking-wide mb-2">Main Points</h3>
              <ul class="space-y-2">
                <li 
                  v-for="(point, index) in mainPoints" 
                  :key="index"
                  class="flex items-start gap-2 text-surface-700 dark:text-surface-300"
                >
                  <span class="material-symbols-rounded text-primary-500 text-sm mt-1 shrink-0">check_circle</span>
                  <span>{{ point }}</span>
                </li>
              </ul>
            </div>
            
            <!-- Action Items -->
            <div v-if="actionItems.length > 0">
              <div class="flex items-center justify-between mb-2">
                <h3 class="text-xs font-medium text-surface-500 uppercase tracking-wide">Action Items</h3>
                <button
                  @click="createAllTodosFromActions"
                  class="flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-colors"
                  title="Create todos from all action items"
                >
                  <span class="material-symbols-rounded text-sm">playlist_add</span>
                  Create All
                </button>
              </div>
              <ul class="space-y-2">
                <li 
                  v-for="(item, index) in actionItems" 
                  :key="index"
                  class="flex items-start gap-2 p-3 rounded-xl bg-surface-50 dark:bg-surface-800 group hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                >
                  <span class="material-symbols-rounded text-amber-500 text-lg shrink-0 mt-0.5">task_alt</span>
                  <span class="flex-1 text-sm text-surface-700 dark:text-surface-300 leading-relaxed">{{ item }}</span>
                  <button
                    v-if="tasksEnabled"
                    @click="createTodoFromAction(item)"
                    class="p-1.5 rounded-lg text-surface-400 hover:text-primary-500 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors opacity-0 group-hover:opacity-100 shrink-0"
                    title="Add to Todo"
                  >
                    <span class="material-symbols-rounded text-lg">add_task</span>
                  </button>
                </li>
              </ul>
            </div>
          </div>
          
          <!-- Not configured -->
          <div v-else class="flex flex-col items-center justify-center py-16 px-4 text-center">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">auto_awesome</span>
            <p class="text-surface-500">AI Assistant</p>
            <p class="text-sm text-surface-400 mt-1">Click "Summarize" on an email to get started</p>
          </div>
        </div>
        
        <!-- Actions Footer -->
        <div v-if="summary && !loading" class="border-t border-surface-200 dark:border-surface-700 p-4 bg-surface-50 dark:bg-surface-900 space-y-3">
          <!-- Suggested Actions -->
          <div v-if="suggestedActions.length > 0" class="space-y-2">
            <p class="text-xs font-medium text-surface-500">Quick Actions</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="(action, index) in suggestedActions"
                :key="index"
                @click="handleSuggestedAction(action, index)"
                :disabled="loadingActionIndex !== null"
                class="flex items-center px-3 py-1.5 rounded-full text-sm font-medium bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 hover:bg-primary-200 dark:hover:bg-primary-500/30 transition-colors disabled:opacity-50"
              >
                <span v-if="loadingActionIndex === index" class="spinner-xs mr-1.5"></span>
                <span v-else class="material-symbols-rounded text-sm mr-1.5">reply</span>
                {{ action.label }}
              </button>
            </div>
          </div>
          
          <!-- Custom Draft -->
          <div v-if="showDraftOptions" class="space-y-2">
            <textarea
              v-model="draftInstructions"
              class="input min-h-[80px] text-sm"
              placeholder="What should the reply say? (e.g., 'Politely decline the meeting')"
            ></textarea>
            <div class="flex gap-2">
              <button @click="showDraftOptions = false" class="btn-ghost btn-sm flex-1">
                Cancel
              </button>
              <button 
                @click="generateCustomDraft" 
                class="btn-primary btn-sm flex-1"
                :disabled="generatingDraft || !draftInstructions.trim()"
              >
                <span v-if="generatingDraft" class="spinner-xs"></span>
                <span class="material-symbols-rounded text-sm">edit</span>
                Generate
              </button>
            </div>
          </div>
          
          <!-- Default Actions -->
          <div v-else class="flex gap-2">
            <button 
              @click="showDraftOptions = true"
              class="btn-secondary btn-sm flex-1"
            >
              <span class="material-symbols-rounded text-sm">edit</span>
              Custom Draft
            </button>
            <button 
              @click="closePanel"
              class="btn-ghost btn-sm"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.spinner-xs {
  display: inline-block;
  width: 12px;
  height: 12px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>

