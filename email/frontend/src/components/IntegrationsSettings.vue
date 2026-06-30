<template>
  <div class="space-y-6">
    <!-- Sub-tab navigation -->
    <div class="flex gap-1 p-1 bg-surface-100 dark:bg-surface-800 rounded-xl overflow-x-auto" style="-webkit-overflow-scrolling: touch;">
      <button
        v-for="tab in integrationTabs"
        :key="tab.id"
        @click="activeSubTab = tab.id"
        :class="[
          'flex items-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex-shrink-0',
          activeSubTab === tab.id
            ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200',
        ]"
      >
        <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
        {{ tab.label }}
        <span v-if="isConnected(tab.id)" class="w-2 h-2 rounded-full bg-emerald-500 flex-shrink-0"></span>
      </button>
    </div>

    <!-- AI Sub-tab -->
    <div v-if="activeSubTab === 'ai'">
      <div class="card p-4 sm:p-6 space-y-5">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-violet-500/10 flex-shrink-0">
            <span class="material-symbols-rounded text-xl text-violet-500">auto_awesome</span>
          </div>
          <div class="min-w-0">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">OpenAI</h3>
            <p class="text-xs text-surface-500">Powers AI Assistant features (summarize, rewrite, draft replies)</p>
          </div>
        </div>

        <div :class="[
          'flex items-center gap-3 p-3.5 rounded-xl text-sm',
          aiConfigured
            ? 'bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30'
            : 'bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30'
        ]">
          <span :class="['material-symbols-rounded text-xl', aiConfigured ? 'text-emerald-500' : 'text-amber-500']">
            {{ aiConfigured ? 'check_circle' : 'warning' }}
          </span>
          <span :class="aiConfigured ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'">
            {{ aiConfigured ? 'API key configured and active' : 'API key required to enable AI features' }}
          </span>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">API Key</label>
          <div class="flex gap-2">
            <div class="flex-1 relative">
              <input
                v-model="aiApiKey"
                :type="showAiKey ? 'text' : 'password'"
                class="w-full px-3 py-2.5 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-800 dark:text-surface-200 focus:outline-none focus:border-primary-500 font-mono pr-10"
                placeholder="sk-..."
              />
              <button
                @click="showAiKey = !showAiKey"
                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded text-surface-400 hover:text-surface-600"
              >
                <span class="material-symbols-rounded text-lg">{{ showAiKey ? 'visibility_off' : 'visibility' }}</span>
              </button>
            </div>
            <button
              v-if="aiConfigured"
              @click="clearAiKey"
              class="px-3 py-2 rounded-full text-sm text-red-500 hover:bg-red-500/10 transition-colors flex-shrink-0"
              title="Remove key"
            >
              <span class="material-symbols-rounded text-lg">delete</span>
            </button>
          </div>
          <p class="mt-2 text-xs text-surface-500">
            Get your key at <a href="https://platform.openai.com/api-keys" target="_blank" class="text-primary-500 hover:underline">platform.openai.com/api-keys</a>
          </p>
        </div>

        <div class="flex justify-end">
          <button
            @click="saveAiKey"
            :disabled="saving.ai || (!aiApiKey || aiApiKey === '********')"
            class="px-5 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors disabled:opacity-50"
          >
            {{ saving.ai ? 'Saving...' : 'Save API Key' }}
          </button>
        </div>
      </div>

      <p class="text-xs text-surface-400 dark:text-surface-500 mt-3">
        For model selection, writing style, and prompt customization, see the
        <button @click="$router.push('/settings?tab=ai')" class="text-primary-500 hover:underline">AI Assistant settings</button>.
      </p>
    </div>

    <!-- Mailchimp Sub-tab -->
    <div v-else-if="activeSubTab === 'mailchimp'">
      <div class="card p-4 sm:p-6 space-y-5">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-yellow-500/10 flex-shrink-0">
            <span class="material-symbols-rounded text-xl text-yellow-600 dark:text-yellow-400">mail</span>
          </div>
          <div class="min-w-0">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Mailchimp</h3>
            <p class="text-xs text-surface-500">Manage audiences, subscribers, and campaigns</p>
          </div>
        </div>

        <div :class="[
          'flex items-center gap-3 p-3.5 rounded-xl text-sm',
          connections.mailchimp?.connected
            ? 'bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30'
            : 'bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30'
        ]">
          <span :class="['material-symbols-rounded text-xl', connections.mailchimp?.connected ? 'text-emerald-500' : 'text-amber-500']">
            {{ connections.mailchimp?.connected ? 'check_circle' : 'warning' }}
          </span>
          <span :class="connections.mailchimp?.connected ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'">
            {{ connections.mailchimp?.connected ? 'Connected -- Mailchimp nodes are available in Automation Hub' : 'Not connected -- add your API key to enable Mailchimp nodes' }}
          </span>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">API Key</label>
          <template v-if="connections.mailchimp?.connected && !editing.mailchimp">
            <div class="flex items-center gap-2">
              <div class="flex-1 min-w-0 px-3 py-2.5 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-500 dark:text-surface-400 font-mono tracking-wider truncate">
                {{ connections.mailchimp?.meta?.key_hint || '****connected****' }}
              </div>
              <button @click="editing.mailchimp = true; keys.mailchimp = ''" class="p-2 rounded-full text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex-shrink-0" title="Change key">
                <span class="material-symbols-rounded text-lg">edit</span>
              </button>
              <button @click="disconnectProvider('mailchimp')" class="p-2 rounded-full text-sm text-red-500 hover:bg-red-500/10 transition-colors flex-shrink-0" title="Disconnect">
                <span class="material-symbols-rounded text-lg">link_off</span>
              </button>
            </div>
          </template>
          <template v-else>
            <div class="flex gap-2">
              <input v-model="keys.mailchimp" type="password" class="flex-1 min-w-0 px-3 py-2.5 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-800 dark:text-surface-200 focus:outline-none focus:border-primary-500 font-mono" placeholder="xxxxxxxxxxxxxxxx-us1" />
              <button @click="saveKey('mailchimp')" :disabled="saving.mailchimp || !keys.mailchimp" class="px-4 sm:px-5 py-2 rounded-full bg-yellow-500 text-white text-sm font-medium hover:bg-yellow-600 transition-colors disabled:opacity-50 flex-shrink-0">
                {{ saving.mailchimp ? 'Saving...' : 'Save' }}
              </button>
              <button v-if="editing.mailchimp" @click="editing.mailchimp = false" class="p-2 rounded-full text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex-shrink-0">
                <span class="material-symbols-rounded text-lg">close</span>
              </button>
            </div>
          </template>
          <p class="mt-2 text-xs text-surface-500">
            Get your API key at <a href="https://us1.admin.mailchimp.com/account/api/" target="_blank" class="text-primary-500 hover:underline">mailchimp.com/account/api</a> -- the key format is <code class="text-[10px] bg-surface-100 dark:bg-surface-700 px-1 py-0.5 rounded">xxxxxxxx-us1</code> (the suffix determines the data center).
          </p>
        </div>
      </div>
    </div>

    <!-- Weather Sub-tab -->
    <div v-else-if="activeSubTab === 'weather'">
      <div class="card p-4 sm:p-6 space-y-5">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-sky-500/10 flex-shrink-0">
            <span class="material-symbols-rounded text-xl text-sky-500">cloud</span>
          </div>
          <div class="min-w-0">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">OpenWeatherMap</h3>
            <p class="text-xs text-surface-500">Weather data for automations</p>
          </div>
        </div>

        <div :class="[
          'flex items-center gap-3 p-3.5 rounded-xl text-sm',
          connections.openweathermap?.connected
            ? 'bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30'
            : 'bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30'
        ]">
          <span :class="['material-symbols-rounded text-xl', connections.openweathermap?.connected ? 'text-emerald-500' : 'text-amber-500']">
            {{ connections.openweathermap?.connected ? 'check_circle' : 'warning' }}
          </span>
          <span :class="connections.openweathermap?.connected ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'">
            {{ connections.openweathermap?.connected ? 'Connected -- Weather node is available in Automation Hub' : 'Not connected -- add your API key to enable Weather node' }}
          </span>
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">API Key</label>
          <template v-if="connections.openweathermap?.connected && !editing.weather">
            <div class="flex items-center gap-2">
              <div class="flex-1 min-w-0 px-3 py-2.5 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-500 dark:text-surface-400 font-mono tracking-wider truncate">
                {{ connections.openweathermap?.meta?.key_hint || '****connected****' }}
              </div>
              <button @click="editing.weather = true; keys.weather = ''" class="p-2 rounded-full text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex-shrink-0" title="Change key">
                <span class="material-symbols-rounded text-lg">edit</span>
              </button>
              <button @click="disconnectProvider('openweathermap')" class="p-2 rounded-full text-sm text-red-500 hover:bg-red-500/10 transition-colors flex-shrink-0" title="Disconnect">
                <span class="material-symbols-rounded text-lg">link_off</span>
              </button>
            </div>
          </template>
          <template v-else>
            <div class="flex gap-2">
              <input v-model="keys.weather" type="password" class="flex-1 min-w-0 px-3 py-2.5 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-800 dark:text-surface-200 focus:outline-none focus:border-primary-500 font-mono" placeholder="OpenWeatherMap API key" />
              <button @click="saveKey('weather')" :disabled="saving.weather || !keys.weather" class="px-4 sm:px-5 py-2 rounded-full bg-sky-500 text-white text-sm font-medium hover:bg-sky-600 transition-colors disabled:opacity-50 flex-shrink-0">
                {{ saving.weather ? 'Saving...' : 'Save' }}
              </button>
              <button v-if="editing.weather" @click="editing.weather = false" class="p-2 rounded-full text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex-shrink-0">
                <span class="material-symbols-rounded text-lg">close</span>
              </button>
            </div>
          </template>
          <p class="mt-2 text-xs text-surface-500">
            Get a free API key at <a href="https://openweathermap.org/api" target="_blank" class="text-primary-500 hover:underline">openweathermap.org/api</a>
          </p>
        </div>
      </div>
    </div>

    <!-- Google Sub-tab -->
    <div v-else-if="activeSubTab === 'google'">
      <div class="card p-4 sm:p-6 space-y-5">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-red-500/10 flex-shrink-0">
            <span class="material-symbols-rounded text-xl text-red-500">mail</span>
          </div>
          <div class="min-w-0">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Google</h3>
            <p class="text-xs text-surface-500">Gmail, Contacts, Calendar sync</p>
          </div>
        </div>

        <div :class="[
          'flex items-center gap-3 p-3.5 rounded-xl text-sm',
          connections.google?.connected
            ? 'bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30'
            : 'bg-surface-100 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600'
        ]">
          <span :class="['material-symbols-rounded text-xl', connections.google?.connected ? 'text-emerald-500' : 'text-surface-400']">
            {{ connections.google?.connected ? 'check_circle' : 'info' }}
          </span>
          <span :class="connections.google?.connected ? 'text-emerald-700 dark:text-emerald-300' : 'text-surface-600 dark:text-surface-300'">
            {{ connections.google?.connected ? 'Connected via Webmail OAuth -- Google nodes are available' : 'Google is connected via Webmail OAuth. Link your account in Linked Accounts settings.' }}
          </span>
        </div>

        <p v-if="!iosNative" class="text-xs text-surface-500">
          Google integration is managed through the Webmail OAuth flow. Go to
          <button @click="$router.push('/settings?tab=accounts')" class="text-primary-500 hover:underline">Linked Accounts</button>
          to manage your Google connection.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import automationHubApi from '@/addons/automation-hub/services/automationHubApi'
import { useAutomationData } from '@/addons/automation-hub/composables/useAutomationData'
import { useNodeRegistry } from '@/addons/automation-hub/composables/useNodeRegistry'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useToastStore } from '@/stores/toast'
import { isIOSNativePlatform } from '@/utils/platform'

// App Store Guideline 4/4.8: Linked Accounts (Google connect) is not available
// on native iOS, so hide the link that points there.
const iosNative = isIOSNativePlatform()

const props = defineProps({
  initialSubTab: { type: String, default: '' },
})

const { connections, fetchConnections, resetConnectionsCache } = useAutomationData()
const { refreshRegistry } = useNodeRegistry()
const aiStore = useAIStore()
const toast = useToastStore()

const integrationTabs = [
  { id: 'ai', label: 'AI (OpenAI)', icon: 'auto_awesome' },
  { id: 'mailchimp', label: 'Mailchimp', icon: 'mail' },
  { id: 'weather', label: 'Weather', icon: 'cloud' },
  { id: 'google', label: 'Google', icon: 'mail' },
]

const activeSubTab = ref(props.initialSubTab || 'ai')

const keys = reactive({ mailchimp: '', weather: '' })
const saving = reactive({ ai: false, mailchimp: false, weather: false })
const editing = reactive({ mailchimp: false, weather: false })

const aiApiKey = ref('')
const showAiKey = ref(false)
const aiConfigured = ref(false)

onMounted(async () => {
  resetConnectionsCache()
  fetchConnections()
  await loadAiStatus()
})

async function loadAiStatus() {
  const data = await aiStore.fetchSettings()
  if (data) {
    aiConfigured.value = data.configured
    if (data.configured) aiApiKey.value = '********'
  }
}

function isConnected(tabId) {
  if (tabId === 'ai') return aiConfigured.value
  if (tabId === 'mailchimp') return !!connections.value.mailchimp?.connected
  if (tabId === 'weather') return !!connections.value.openweathermap?.connected
  if (tabId === 'google') return !!connections.value.google?.connected
  return false
}

async function saveAiKey() {
  if (!aiApiKey.value || aiApiKey.value === '********') return
  saving.ai = true
  try {
    const result = await aiStore.saveSettings({ ai_api_key: aiApiKey.value })
    if (result.success) {
      aiConfigured.value = true
      aiApiKey.value = '********'
      toast.success('AI API key saved')
    } else {
      toast.error(result.error || 'Failed to save AI key')
    }
  } catch (e) {
    toast.error('Failed to save AI key')
  } finally {
    saving.ai = false
  }
}

async function clearAiKey() {
  saving.ai = true
  try {
    const result = await aiStore.saveSettings({ ai_api_key: '' })
    if (result.success) {
      aiConfigured.value = false
      aiApiKey.value = ''
      toast.success('AI API key removed')
    }
  } catch (e) {
    toast.error('Failed to remove AI key')
  } finally {
    saving.ai = false
  }
}

async function saveKey(provider) {
  const providerMap = { mailchimp: 'mailchimp', weather: 'openweathermap' }
  const backendProvider = providerMap[provider] || provider
  saving[provider] = true
  try {
    await automationHubApi.saveConnection({ provider: backendProvider, api_key: keys[provider] })
    keys[provider] = ''
    editing[provider] = false
    resetConnectionsCache()
    await fetchConnections()
    refreshRegistry()
    toast.success(`${provider.charAt(0).toUpperCase() + provider.slice(1)} connected`)
  } catch (e) {
    toast.error(`Failed to save ${provider} key`)
  } finally {
    saving[provider] = false
  }
}

async function disconnectProvider(provider) {
  try {
    await automationHubApi.disconnectProvider({ provider })
    resetConnectionsCache()
    await fetchConnections()
    refreshRegistry()
    toast.success(`${provider} disconnected`)
  } catch (e) {
    toast.error(`Failed to disconnect ${provider}`)
  }
}
</script>
