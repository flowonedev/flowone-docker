<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useToastStore } from '@/stores/toast'
import aiHelper from '@/services/aiHelper'
import AIChat from '@/components/AIChat.vue'
import AITerminal from '@/components/AITerminal.vue'
import AIConfigGroup from '@/components/AIConfigGroup.vue'

const toast = useToastStore()

// Tabs
const activeTab = ref('chat')

// State
const conversations = ref([])
const currentConversation = ref(null)
const loading = ref(false)
const showTerminal = ref(false)
const terminalCommand = ref('')
const loadingConfigService = ref(null)

// Context menu state
const contextMenu = ref({
  show: false,
  x: 0,
  y: 0,
  conversationId: null,
})

// Delete confirmation modal
const deleteModal = ref({
  show: false,
  conversationId: null,
  title: '',
})

// Settings state
const settingsLoading = ref(false)
const settingsSaving = ref(false)
const settings = ref({
  openai_api_key: '',
  openai_model: 'gpt-5',
  max_tokens: 2000,
  temperature: 0.3,
})
const originalSettings = ref({})
const showApiKey = ref(false)

const models = [
  { value: 'gpt-5', label: 'GPT-5 (Best performance - Recommended)' },
  { value: 'gpt-5-mini', label: 'GPT-5 Mini (Fast & cost-effective)' },
  { value: 'gpt-5-nano', label: 'GPT-5 Nano (Ultra fast & cheapest)' },
  { value: 'gpt-4o', label: 'GPT-4o (Legacy - Best overall performance)' },
  { value: 'gpt-4o-mini', label: 'GPT-4o Mini (Legacy - Fast & cost-effective)' },
  { value: 'gpt-4-turbo', label: 'GPT-4 Turbo (Legacy - Complex reasoning)' },
  { value: 'gpt-4', label: 'GPT-4 (Legacy - Standard)' },
  { value: 'gpt-3.5-turbo', label: 'GPT-3.5 Turbo (Legacy - Fast & economical)' },
]

// Config file groups
const configGroups = [
  {
    label: 'Email Services',
    icon: 'mail',
    services: [
      { service: 'postfix', label: 'Postfix', icon: 'forward_to_inbox' },
      { service: 'dovecot', label: 'Dovecot', icon: 'inbox' },
      { service: 'email-ssl', label: 'Email SSL', icon: 'lock' },
    ]
  },
  {
    label: 'Web Server',
    icon: 'dns',
    services: [
      { service: 'openlitespeed', label: 'OpenLiteSpeed', icon: 'bolt' },
      { service: 'web-ssl', label: 'SSL Certificates', icon: 'lock' },
    ]
  },
  {
    label: 'System',
    icon: 'computer',
    services: [
      { service: 'ssh', label: 'SSH Config', icon: 'terminal' },
    ]
  },
  {
    label: 'Security',
    icon: 'shield',
    services: [
      { service: 'fail2ban', label: 'Fail2ban', icon: 'security' },
      { service: 'modsec', label: 'ModSecurity', icon: 'verified_user' },
      { service: 'firewall', label: 'Firewall', icon: 'local_fire_department' },
    ]
  },
]

// Load conversations
const loadConversations = async () => {
  loading.value = true
  try {
    conversations.value = await aiHelper.getConversations()
    if (conversations.value.length > 0 && !currentConversation.value) {
      await selectConversation(conversations.value[0].id)
    }
  } catch (e) {
    toast.error('Failed to load conversations')
  } finally {
    loading.value = false
  }
}

// Create new conversation
const createConversation = async () => {
  try {
    const conversation = await aiHelper.createConversation('New Conversation')
    conversations.value.unshift(conversation)
    await selectConversation(conversation.id)
  } catch (e) {
    toast.error('Failed to create conversation')
  }
}

// Select conversation
const selectConversation = async (id) => {
  try {
    currentConversation.value = await aiHelper.getConversation(id)
  } catch (e) {
    toast.error('Failed to load conversation')
  }
}

// Show delete confirmation modal
const showDeleteModal = (id) => {
  const conv = conversations.value.find(c => c.id === id)
  deleteModal.value = {
    show: true,
    conversationId: id,
    title: conv?.title || 'this conversation',
  }
}

// Close delete modal
const closeDeleteModal = () => {
  deleteModal.value.show = false
}

// Confirm delete conversation
const confirmDeleteConversation = async () => {
  const id = deleteModal.value.conversationId
  if (!id) return

  try {
    await aiHelper.deleteConversation(id)
    toast.success('Conversation deleted')
    
    // Remove from list
    conversations.value = conversations.value.filter(c => c.id !== id)
    
    // Clear current conversation if it was deleted
    if (currentConversation.value?.id === id) {
      currentConversation.value = null
      if (conversations.value.length > 0) {
        await selectConversation(conversations.value[0].id)
      }
    }
  } catch (e) {
    toast.error('Failed to delete conversation')
  } finally {
    closeDeleteModal()
  }
}

// Handle right-click context menu
const showContextMenu = (event, conversationId) => {
  event.preventDefault()
  event.stopPropagation()
  
  contextMenu.value = {
    show: true,
    x: event.clientX,
    y: event.clientY,
    conversationId,
  }
}

// Hide context menu
const hideContextMenu = () => {
  contextMenu.value.show = false
}

// Handle context menu delete
const handleContextDelete = () => {
  if (contextMenu.value.conversationId) {
    showDeleteModal(contextMenu.value.conversationId)
  }
  hideContextMenu()
}

// Send message
const sendingMessage = ref(false)
const sendMessage = async (message) => {
  if (!currentConversation.value) {
    await createConversation()
  }

  // Add user message immediately to show it
  if (currentConversation.value && currentConversation.value.messages) {
    currentConversation.value.messages.push({
      role: 'user',
      content: message,
      created_at: new Date().toISOString()
    })
  }

  sendingMessage.value = true
  try {
    const result = await aiHelper.sendMessage(
      currentConversation.value.id,
      message
    )

    // Reload conversation to get updated messages
    await selectConversation(currentConversation.value.id)
  } catch (e) {
    toast.error('Failed to send message')
    // Remove the message we added if it failed
    if (currentConversation.value && currentConversation.value.messages) {
      currentConversation.value.messages.pop()
    }
  } finally {
    sendingMessage.value = false
  }
}

// Run command in terminal
const runCommand = (command) => {
  terminalCommand.value = command
  showTerminal.value = true
}

// Analyze config
const analyzeConfig = async (service, path) => {
  if (!currentConversation.value) {
    await createConversation()
  }

  try {
    const result = await aiHelper.analyzeConfig(service, path)
    
    const message = `I found a config file issue. Please analyze:\n\nService: ${service}\nPath: ${path}\n\nAnalysis:\n${result.analysis}`
    await sendMessage(message)
  } catch (e) {
    toast.error('Failed to analyze config')
  }
}

// Handle config group selection
const handleConfigSelect = async (service) => {
  loadingConfigService.value = service
  
  if (!currentConversation.value) {
    await createConversation()
  }

  try {
    const files = await aiHelper.getConfigFiles(service)
    
    // Read and analyze the actual config files, not just list them
    const message = `Analyze the ${service} configuration files. Read and check them for issues, misconfigurations, and security problems.`
    
    // Add file paths to context so the AI knows which files to read
    const filePaths = files.map(f => f.path).join(', ')
    const fullMessage = `${message}\n\nConfiguration files to analyze:\n${files.map(f => `- ${f.label}: ${f.path}`).join('\n')}`
    
    await sendMessage(fullMessage)
  } catch (e) {
    toast.error('Failed to load config files')
  } finally {
    loadingConfigService.value = null
  }
}

// Settings functions
const loadSettings = async () => {
  settingsLoading.value = true
  try {
    const data = await aiHelper.getSettings()
    settings.value = {
      openai_api_key: data.openai_api_key || '',
      openai_model: data.openai_model || 'gpt-5',
      max_tokens: data.max_tokens || 2000,
      temperature: data.temperature || 0.3,
      response_language: data.response_language || 'en',
    }
    originalSettings.value = { ...settings.value }
  } catch (e) {
    toast.error('Failed to load settings')
  } finally {
    settingsLoading.value = false
  }
}

const saveSettings = async () => {
  const settingsToSave = { ...settings.value }
  if (settingsToSave.openai_api_key.startsWith('***')) {
    delete settingsToSave.openai_api_key
  }
  
  settingsSaving.value = true
  try {
    await aiHelper.updateSettings(settingsToSave)
    toast.success('Settings saved successfully')
    await loadSettings()
    originalSettings.value = { ...settings.value }
  } catch (e) {
    toast.error('Failed to save settings')
  } finally {
    settingsSaving.value = false
  }
}

const toggleApiKeyVisibility = () => {
  showApiKey.value = !showApiKey.value
  if (showApiKey.value && settings.value.openai_api_key.startsWith('***')) {
    settings.value.openai_api_key = ''
  }
}

const handleApiKeyInput = () => {
  if (settings.value.openai_api_key && !settings.value.openai_api_key.startsWith('***')) {
    showApiKey.value = true
  }
}

// Load settings when switching to settings tab
watch(activeTab, (newTab) => {
  if (newTab === 'settings') {
    loadSettings()
  }
})

// Close context menu on outside click
const handleClickOutside = (event) => {
  if (contextMenu.value.show) {
    hideContextMenu()
  }
}

onMounted(() => {
  loadConversations()
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <div>
    <div class="page-header mb-4">
      <div>
        <h1 class="page-title">AI Helper</h1>
        <p class="text-surface-500 text-sm mt-0.5">Server diagnostic assistant</p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700 mb-4">
      <nav class="tab-nav">
        <button
          @click="activeTab = 'chat'"
          :class="['tab-btn', activeTab === 'chat' ? 'active' : '']"
        >
          <span class="material-symbols-rounded text-lg">chat</span>
          <span class="tab-label">Chat</span>
        </button>
        <button
          @click="activeTab = 'settings'"
          :class="['tab-btn', activeTab === 'settings' ? 'active' : '']"
        >
          <span class="material-symbols-rounded text-lg">tune</span>
          <span class="tab-label">Settings</span>
        </button>
      </nav>
    </div>

    <!-- Settings Tab -->
    <div v-if="activeTab === 'settings'" class="max-w-lg">
      <div v-if="settingsLoading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>

      <div v-else class="space-y-4">
        <!-- OpenAI API Configuration -->
        <div class="card p-4">
          <h3 class="text-sm font-semibold mb-3 flex items-center gap-1.5">
            <span class="material-symbols-rounded text-sm text-primary-500">key</span>
            OpenAI API Key
          </h3>
          
          <div>
            <label class="block text-xs font-medium mb-1.5">
              API Key
              <span class="text-red-500">*</span>
            </label>
            <div class="relative flex items-center">
              <input
                v-model="settings.openai_api_key"
                @input="handleApiKeyInput"
                :type="showApiKey || settings.openai_api_key.startsWith('***') ? 'text' : 'password'"
                :placeholder="settings.openai_api_key.startsWith('***') ? 'Enter new API key to update' : 'sk-...'"
                class="input text-sm pr-9 w-full"
                required
              />
              <button
                @click="toggleApiKeyVisibility"
                type="button"
                class="absolute right-2 p-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                :title="showApiKey ? 'Hide' : 'Show'"
              >
                <span class="material-symbols-rounded text-sm text-surface-500">
                  {{ showApiKey ? 'visibility_off' : 'visibility' }}
                </span>
              </button>
            </div>
            <p class="text-[10px] text-surface-500 mt-1.5">
              Get your key from 
              <a href="https://platform.openai.com/api-keys" target="_blank" class="text-primary-500 hover:underline">
                platform.openai.com
              </a>
              <span v-if="settings.openai_api_key.startsWith('***')" class="block mt-0.5 text-amber-600 dark:text-amber-400">
                Key is set. Enter a new key to update.
              </span>
            </p>
          </div>
        </div>

        <!-- Model Configuration -->
        <div class="card p-4">
          <h3 class="text-sm font-semibold mb-3 flex items-center gap-1.5">
            <span class="material-symbols-rounded text-sm text-primary-500">tune</span>
            Model Settings
          </h3>
          
          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium mb-1.5">Model</label>
              <select v-model="settings.openai_model" class="input text-sm">
                <option v-for="model in models" :key="model.value" :value="model.value">
                  {{ model.label }}
                </option>
              </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium mb-1.5">
                  Max Tokens
                  <span class="text-surface-400 font-normal">(100-20000)</span>
                </label>
                <input
                  v-model.number="settings.max_tokens"
                  type="number"
                  min="100"
                  max="20000"
                  step="100"
                  class="input text-sm"
                />
              </div>

              <div>
                <label class="block text-xs font-medium mb-1.5">
                  Temperature
                  <span class="text-surface-400 font-normal">(0-2)</span>
                </label>
                <input
                  v-model.number="settings.temperature"
                  type="number"
                  min="0"
                  max="2"
                  step="0.1"
                  class="input text-sm"
                />
              </div>
            </div>
            <p class="text-[10px] text-surface-500">
              Lower temperature = focused, higher = creative
            </p>
          </div>

          <!-- Response Language -->
          <div>
            <label class="block text-xs font-medium mb-1.5">
              <span class="material-symbols-rounded text-sm align-middle mr-1">translate</span>
              Response Language
            </label>
            <select v-model="settings.response_language" class="input text-sm">
              <option value="en">🇬🇧 English</option>
              <option value="hu">🇭🇺 Hungarian (Magyar)</option>
            </select>
            <p class="text-[10px] text-surface-500 mt-1">
              AI will respond in this language
            </p>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 pt-2">
          <button
            @click="saveSettings"
            :disabled="settingsSaving"
            class="btn-primary flex items-center gap-1.5 text-sm px-4 py-2"
          >
            <span v-if="settingsSaving" class="spinner"></span>
            <span v-else class="material-symbols-rounded text-sm">save</span>
            {{ settingsSaving ? 'Saving...' : 'Save' }}
          </button>
          <button
            @click="loadSettings"
            :disabled="settingsLoading || settingsSaving"
            class="btn-secondary text-sm px-4 py-2"
          >
            Reset
          </button>
        </div>

        <!-- Info Box -->
        <div class="card p-3 bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30 mt-4">
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-sm text-blue-500 mt-0.5">info</span>
            <p class="text-[11px] text-blue-700 dark:text-blue-300">
              Settings are stored in the database. API key is masked after saving.
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Chat Tab -->
    <div v-if="activeTab === 'chat'" class="flex gap-4 h-[calc(100vh-11rem)]">
      <!-- Left Sidebar - Hidden on mobile, shown as overlay -->
      <div class="hidden md:flex w-56 flex-shrink-0 flex-col bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        
        <!-- Analyze Config Section -->
        <div class="p-3 flex-1 overflow-y-auto">
          <div class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider mb-3">
            Analyze Config
          </div>
          
          <div class="space-y-4">
            <div v-for="group in configGroups" :key="group.label">
              <div class="flex items-center gap-2 text-xs font-medium text-surface-600 dark:text-surface-300 mb-2">
                <span class="material-symbols-rounded text-sm text-surface-400">{{ group.icon }}</span>
                {{ group.label }}
              </div>
              <div class="space-y-1 ml-1">
                <AIConfigGroup
                  v-for="service in group.services"
                  :key="service.service + service.label"
                  :service="service.service"
                  :label="service.label"
                  :icon="service.icon"
                  :loading="loadingConfigService === service.service"
                  @select="handleConfigSelect"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Divider -->
        <div class="border-t border-surface-200 dark:border-surface-700"></div>

        <!-- Chats Section -->
        <div class="p-3">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider">
              Chats
            </div>
            <button
              @click="createConversation"
              class="p-1 rounded-md hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              title="New Chat"
            >
              <span class="material-symbols-rounded text-sm text-primary-500">add</span>
            </button>
          </div>
          
          <div class="space-y-1 max-h-40 overflow-y-auto">
            <div
              v-for="conv in conversations"
              :key="conv.id"
              @contextmenu="showContextMenu($event, conv.id)"
              :class="[
                'group relative flex items-center rounded-lg transition-colors cursor-pointer',
                currentConversation?.id === conv.id
                  ? 'bg-primary-50 dark:bg-primary-500/20'
                  : 'hover:bg-surface-100 dark:hover:bg-surface-700'
              ]"
            >
              <button
                @click="selectConversation(conv.id)"
                class="flex-1 text-left px-2.5 py-2 rounded-lg min-w-0"
                :class="[
                  currentConversation?.id === conv.id
                    ? 'text-primary-700 dark:text-primary-400'
                    : 'text-surface-700 dark:text-surface-300'
                ]"
              >
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm opacity-50">chat</span>
                  <div class="min-w-0">
                    <div class="font-medium truncate text-sm">{{ conv.title }}</div>
                    <div class="text-xs text-surface-500">{{ conv.message_count }} messages</div>
                  </div>
                </div>
              </button>
              <button
                @click.stop="showDeleteModal(conv.id)"
                class="opacity-0 group-hover:opacity-100 p-1.5 rounded-md hover:bg-red-100 dark:hover:bg-red-500/20 text-red-500 transition-opacity mr-1 flex-shrink-0"
                title="Delete"
              >
                <span class="material-symbols-rounded text-sm">delete</span>
              </button>
            </div>
            
            <div v-if="conversations.length === 0" class="text-center py-3 text-xs text-surface-400">
              No conversations yet
            </div>
          </div>
        </div>
      </div>

      <!-- Chat Interface -->
      <div class="flex-1 card p-0 overflow-hidden flex flex-col">
        <AIChat
          v-if="currentConversation"
          :conversation-id="currentConversation.id"
          :messages="currentConversation.messages || []"
          :is-typing="sendingMessage"
          @send-message="sendMessage"
          @run-command="runCommand"
        />
        <div v-else class="flex items-center justify-center h-full text-surface-500">
          <div class="text-center">
            <span class="material-symbols-rounded text-5xl mb-3 block opacity-30">psychology</span>
            <p class="text-sm font-medium">No conversation selected</p>
            <p class="text-xs text-surface-400 mt-1">Create a new chat or select one from the sidebar</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Terminal Modal -->
    <AITerminal
      v-model="showTerminal"
      @command-ready="(cmd) => { terminalCommand = cmd }"
    />

    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <div
        v-if="deleteModal.show"
        class="fixed inset-0 z-50 flex items-center justify-center"
      >
        <!-- Backdrop -->
        <div
          @click="closeDeleteModal"
          class="absolute inset-0 bg-black/50 backdrop-blur-sm"
        ></div>
        
        <!-- Modal -->
        <div class="relative bg-white dark:bg-surface-800 rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden">
          <!-- Header -->
          <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-red-500 text-xl">delete</span>
              </div>
              <div>
                <h3 class="text-base font-semibold text-surface-900 dark:text-white">Delete Conversation</h3>
                <p class="text-xs text-surface-500 mt-0.5">This action cannot be undone</p>
              </div>
            </div>
          </div>
          
          <!-- Content -->
          <div class="px-5 py-4">
            <p class="text-sm text-surface-600 dark:text-surface-400">
              Are you sure you want to delete <span class="font-medium text-surface-900 dark:text-white">"{{ deleteModal.title }}"</span>?
            </p>
          </div>
          
          <!-- Actions -->
          <div class="px-5 py-4 bg-surface-50 dark:bg-surface-900/50 flex items-center justify-end gap-2">
            <button
              @click="closeDeleteModal"
              class="px-4 py-2 text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              @click="confirmDeleteConversation"
              class="px-4 py-2 text-sm font-medium text-white bg-red-500 hover:bg-red-600 rounded-lg transition-colors flex items-center gap-1.5"
            >
              <span class="material-symbols-rounded text-sm">delete</span>
              Delete
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Context Menu -->
    <div
      v-if="contextMenu.show"
      @click="hideContextMenu"
      class="fixed inset-0 z-50"
      style="background: transparent;"
    >
      <div
        @click.stop
        class="absolute bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-md shadow-lg py-0.5 min-w-[140px] z-50"
        :style="{
          left: contextMenu.x + 'px',
          top: contextMenu.y + 'px',
        }"
      >
        <button
          @click="handleContextDelete"
          class="w-full text-left px-3 py-1.5 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center gap-1.5"
        >
          <span class="material-symbols-rounded text-xs">delete</span>
          Delete
        </button>
      </div>
    </div>
  </div>
</template>

