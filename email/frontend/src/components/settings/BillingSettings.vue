<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const loadingBlocks = ref(false)

const settings = ref({
  provider: 'none',
  api_key: '',
  billingo_block_id: null,
  szamlazz_agent_key: '',
  company_name: '',
  company_address: '',
  company_tax_number: '',
  company_eu_tax_number: '',
  company_bank_account: '',
  company_bank_name: '',
  company_email: '',
  company_phone: '',
  default_currency: 'HUF',
  default_tax_rate: 27.00,
  default_payment_terms_days: 8,
  default_payment_method: 'bank_transfer',
  default_language: 'hu',
  auto_save_to_drive: true,
})

const hasApiKey = ref(false)
const apiKeyMasked = ref('')
const hasSzamlazzKey = ref(false)
const szamlazzKeyMasked = ref('')
const invoiceBlocks = ref([])

const providers = [
  { value: 'none', label: 'No Provider', icon: 'block', desc: 'Manual invoice management only' },
  { value: 'billingo', label: 'Billingo', icon: 'receipt_long', desc: 'Billingo.hu - REST API v3' },
  { value: 'szamlazz', label: 'Szamlazz.hu', icon: 'description', desc: 'Szamlazz.hu - XML Agent API' },
]

const currencies = ['HUF', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'PLN', 'RON']
const languages = [
  { value: 'hu', label: 'Hungarian' },
  { value: 'en', label: 'English' },
  { value: 'de', label: 'German' },
]
const paymentMethods = [
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'paypal', label: 'PayPal' },
  { value: 'other', label: 'Other' },
]

const showApiKeyField = computed(() => settings.value.provider === 'billingo')
const showSzamlazzField = computed(() => settings.value.provider === 'szamlazz')
const showBlockSelect = computed(() => settings.value.provider === 'billingo' && invoiceBlocks.value.length > 0)

onMounted(async () => {
  await fetchSettings()
})

async function fetchSettings() {
  loading.value = true
  try {
    const res = await api.get('/billing/settings')
    if (res.data?.success && res.data.data) {
      const d = res.data.data
      settings.value.provider = d.provider || 'none'
      settings.value.billingo_block_id = d.billingo_block_id || null
      settings.value.company_name = d.company_name || ''
      settings.value.company_address = d.company_address || ''
      settings.value.company_tax_number = d.company_tax_number || ''
      settings.value.company_eu_tax_number = d.company_eu_tax_number || ''
      settings.value.company_bank_account = d.company_bank_account || ''
      settings.value.company_bank_name = d.company_bank_name || ''
      settings.value.company_email = d.company_email || ''
      settings.value.company_phone = d.company_phone || ''
      settings.value.default_currency = d.default_currency || 'HUF'
      settings.value.default_tax_rate = parseFloat(d.default_tax_rate) || 27
      settings.value.default_payment_terms_days = parseInt(d.default_payment_terms_days) || 8
      settings.value.default_payment_method = d.default_payment_method || 'bank_transfer'
      settings.value.default_language = d.default_language || 'hu'
      settings.value.auto_save_to_drive = d.auto_save_to_drive == 1

      hasApiKey.value = d.has_api_key || false
      apiKeyMasked.value = d.api_key_masked || ''
      hasSzamlazzKey.value = d.has_szamlazz_agent_key || false
      szamlazzKeyMasked.value = d.szamlazz_agent_key_masked || ''

      // Clear sensitive fields (don't prefill)
      settings.value.api_key = ''
      settings.value.szamlazz_agent_key = ''
    }
  } catch (e) {
    toast.error('Failed to load billing settings')
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  saving.value = true
  try {
    const payload = { ...settings.value }
    // Only send API key if user entered a new one
    if (!payload.api_key) delete payload.api_key
    if (!payload.szamlazz_agent_key) delete payload.szamlazz_agent_key
    payload.auto_save_to_drive = payload.auto_save_to_drive ? 1 : 0

    const res = await api.put('/billing/settings', payload)
    if (res.data?.success) {
      toast.success('Billing settings saved')
      await fetchSettings()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save settings')
  } finally {
    saving.value = false
  }
}

async function testConnection() {
  testing.value = true
  try {
    const res = await api.post('/billing/test-connection')
    if (res.data?.success) {
      toast.success(res.data.message || 'Connection successful')
      // If blocks returned, store them
      if (res.data.data?.invoice_blocks) {
        invoiceBlocks.value = res.data.data.invoice_blocks
      }
    } else {
      toast.error(res.data?.message || 'Connection failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Connection test failed')
  } finally {
    testing.value = false
  }
}

async function fetchBlocks() {
  loadingBlocks.value = true
  try {
    const res = await api.get('/billing/invoice-blocks')
    if (res.data?.success) {
      invoiceBlocks.value = res.data.data?.blocks || []
    }
  } catch (e) {
    // Blocks only relevant for Billingo
  } finally {
    loadingBlocks.value = false
  }
}

watch(() => settings.value.provider, (val) => {
  if (val === 'billingo' && hasApiKey.value) {
    fetchBlocks()
  }
})
</script>

<template>
  <div class="space-y-6">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">sync</span>
    </div>

    <template v-else>
      <!-- Provider Selection -->
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-500">account_balance</span>
          Billing Provider
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <button
            v-for="p in providers" :key="p.value"
            @click="settings.provider = p.value"
            :class="[
              'p-4 rounded-xl border-2 text-left transition-all',
              settings.provider === p.value
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'
            ]"
          >
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-lg" :class="settings.provider === p.value ? 'text-primary-500' : 'text-surface-400'">{{ p.icon }}</span>
              <span class="text-sm font-medium text-surface-900 dark:text-white">{{ p.label }}</span>
            </div>
            <p class="text-xs text-surface-500">{{ p.desc }}</p>
          </button>
        </div>
      </div>

      <!-- API Key Section (Billingo) -->
      <div v-if="showApiKeyField" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-amber-500">key</span>
          Billingo API Key
        </h3>
        <div v-if="hasApiKey" class="mb-3 flex items-center gap-2 text-sm">
          <span class="material-symbols-rounded text-green-500 text-lg">check_circle</span>
          <span class="text-surface-600 dark:text-surface-300">Current key: <code class="bg-surface-100 dark:bg-surface-700 px-2 py-0.5 rounded text-xs">{{ apiKeyMasked }}</code></span>
        </div>
        <input
          v-model="settings.api_key"
          type="password"
          :placeholder="hasApiKey ? 'Enter new key to replace...' : 'Paste your Billingo API key...'"
          class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm"
        />
        <p class="mt-2 text-xs text-surface-400">Find your API key at: Settings &gt; API in your Billingo account</p>

        <!-- Block Selection -->
        <div v-if="showBlockSelect" class="mt-4">
          <label class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-1 block">Invoice Block</label>
          <select
            v-model="settings.billingo_block_id"
            class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm"
          >
            <option :value="null">Auto-select first block</option>
            <option v-for="b in invoiceBlocks" :key="b.id" :value="b.id">{{ b.name }} ({{ b.prefix }})</option>
          </select>
        </div>

        <div class="mt-4 flex gap-2">
          <button
            @click="testConnection"
            :disabled="testing"
            class="px-4 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors disabled:opacity-50 flex items-center gap-1.5"
          >
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': testing }">{{ testing ? 'sync' : 'electrical_services' }}</span>
            {{ testing ? 'Testing...' : 'Test Connection' }}
          </button>
          <button
            v-if="hasApiKey"
            @click="fetchBlocks"
            :disabled="loadingBlocks"
            class="px-4 py-2 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-700 dark:text-surface-300 text-sm font-medium hover:bg-surface-300 dark:hover:bg-surface-600 transition-colors disabled:opacity-50 flex items-center gap-1.5"
          >
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': loadingBlocks }">{{ loadingBlocks ? 'sync' : 'refresh' }}</span>
            Refresh Blocks
          </button>
        </div>
      </div>

      <!-- API Key Section (Szamlazz.hu) -->
      <div v-if="showSzamlazzField" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-amber-500">key</span>
          Szamlazz.hu Agent Key
        </h3>
        <div v-if="hasSzamlazzKey" class="mb-3 flex items-center gap-2 text-sm">
          <span class="material-symbols-rounded text-green-500 text-lg">check_circle</span>
          <span class="text-surface-600 dark:text-surface-300">Current key: <code class="bg-surface-100 dark:bg-surface-700 px-2 py-0.5 rounded text-xs">{{ szamlazzKeyMasked }}</code></span>
        </div>
        <input
          v-model="settings.szamlazz_agent_key"
          type="password"
          :placeholder="hasSzamlazzKey ? 'Enter new key to replace...' : 'Paste your Szamlazz.hu agent key...'"
          class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm"
        />
        <p class="mt-2 text-xs text-surface-400">Find your agent key at: Settings &gt; API in your Szamlazz.hu account</p>

        <div class="mt-4">
          <button
            @click="testConnection"
            :disabled="testing"
            class="px-4 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors disabled:opacity-50 flex items-center gap-1.5"
          >
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': testing }">{{ testing ? 'sync' : 'electrical_services' }}</span>
            {{ testing ? 'Testing...' : 'Test Connection' }}
          </button>
        </div>
      </div>

      <!-- Company Details -->
      <div v-if="settings.provider !== 'none'" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-blue-500">domain</span>
          Company Details
        </h3>
        <p class="text-xs text-surface-400 mb-4">Used for invoice generation when pushing to the billing provider.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Company Name</label>
            <input v-model="settings.company_name" type="text" placeholder="Your Company Ltd."
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Tax Number</label>
            <input v-model="settings.company_tax_number" type="text" placeholder="12345678-2-42"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">EU VAT Number</label>
            <input v-model="settings.company_eu_tax_number" type="text" placeholder="HU12345678"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Email</label>
            <input v-model="settings.company_email" type="email" placeholder="billing@company.com"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="text-xs font-medium text-surface-500 mb-1 block">Address</label>
            <input v-model="settings.company_address" type="text" placeholder="1234 Budapest, Example Street 5."
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Phone</label>
            <input v-model="settings.company_phone" type="text" placeholder="+36 1 234 5678"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Bank Name</label>
            <input v-model="settings.company_bank_name" type="text" placeholder="OTP Bank"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="text-xs font-medium text-surface-500 mb-1 block">Bank Account Number</label>
            <input v-model="settings.company_bank_account" type="text" placeholder="12345678-12345678-12345678"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
        </div>
      </div>

      <!-- Invoice Defaults -->
      <div v-if="settings.provider !== 'none'" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-green-500">tune</span>
          Invoice Defaults
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Currency</label>
            <select v-model="settings.default_currency"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm">
              <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Tax Rate (%)</label>
            <input v-model.number="settings.default_tax_rate" type="number" min="0" max="100" step="0.5"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Payment Terms (days)</label>
            <input v-model.number="settings.default_payment_terms_days" type="number" min="0" max="365"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Payment Method</label>
            <select v-model="settings.default_payment_method"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm">
              <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 mb-1 block">Invoice Language</label>
            <select v-model="settings.default_language"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-white text-sm">
              <option v-for="l in languages" :key="l.value" :value="l.value">{{ l.label }}</option>
            </select>
          </div>
          <div class="flex items-center gap-3 pt-5">
            <button
              @click="settings.auto_save_to_drive = !settings.auto_save_to_drive"
              :class="[
                'relative w-11 h-6 rounded-full transition-colors',
                settings.auto_save_to_drive ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
              ]"
            >
              <span
                :class="[
                  'absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform',
                  settings.auto_save_to_drive ? 'translate-x-5' : ''
                ]"
              ></span>
            </button>
            <span class="text-sm text-surface-700 dark:text-surface-300">Save PDF to Drive</span>
          </div>
        </div>
      </div>

      <!-- Save Button -->
      <div class="flex justify-end">
        <button
          @click="saveSettings"
          :disabled="saving"
          class="px-6 py-2.5 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors disabled:opacity-50 flex items-center gap-2"
        >
          <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': saving }">{{ saving ? 'sync' : 'save' }}</span>
          {{ saving ? 'Saving...' : 'Save Settings' }}
        </button>
      </div>
    </template>
  </div>
</template>

