<script setup>
/**
 * BillingSettings - Configure external billing provider integration
 * 
 * Supports Billingo and Szamlazz.hu (Hungarian regulated invoicing platforms).
 * Allows user to:
 * - Select and configure a billing provider
 * - Enter API credentials (stored encrypted server-side)
 * - Set company details for invoice headers
 * - Configure default invoice settings (currency, tax, payment terms, etc.)
 * - Test connection to the selected provider
 * - Fetch available invoice blocks (Billingo)
 */
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'

const toast = useToastStore()
const addons = useAddons()

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const fetchingBlocks = ref(false)

// Settings form
const settings = ref({
  provider: 'none',
  // Billingo
  api_key: '',
  billingo_block_id: '',
  // Szamlazz.hu
  szamlazz_agent_key: '',
  // Company details
  company_name: '',
  company_address: '',
  company_tax_number: '',
  company_eu_tax_number: '',
  company_bank_account: '',
  company_bank_name: '',
  company_email: '',
  company_phone: '',
  // Defaults
  default_currency: 'HUF',
  default_tax_rate: 27.00,
  default_payment_terms_days: 8,
  default_payment_method: 'bank_transfer',
  default_language: 'hu',
  auto_save_to_drive: true,
})

const hasApiKey = ref(false)
const apiKeyMasked = ref('')
const hasSzamlazzAgentKey = ref(false)
const szamlazzAgentKeyMasked = ref('')
const invoiceBlocks = ref([])

const providers = [
  { value: 'none', label: 'No Provider (Manual)', icon: 'edit_note', description: 'Create invoices manually without external integration' },
  { value: 'billingo', label: 'Billingo', icon: 'receipt_long', description: 'Billingo.hu — Popular Hungarian invoicing platform' },
  { value: 'szamlazz', label: 'Számlázz.hu', icon: 'description', description: 'Számlázz.hu — Hungary\'s most used invoicing service' },
]

const currencies = [
  { value: 'HUF', label: 'HUF — Hungarian Forint' },
  { value: 'EUR', label: 'EUR — Euro' },
  { value: 'USD', label: 'USD — US Dollar' },
  { value: 'GBP', label: 'GBP — British Pound' },
]

const paymentMethods = [
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'paypal', label: 'PayPal' },
  { value: 'other', label: 'Other' },
]

const languages = [
  { value: 'hu', label: 'Magyar (Hungarian)' },
  { value: 'en', label: 'English' },
  { value: 'de', label: 'Deutsch (German)' },
]

const isBillingo = computed(() => settings.value.provider === 'billingo')
const isSzamlazz = computed(() => settings.value.provider === 'szamlazz')
const isNone = computed(() => settings.value.provider === 'none')

onMounted(async () => {
  await loadSettings()
})

async function loadSettings() {
  loading.value = true
  try {
    const res = await api.get('/billing/settings')
    if (res.data?.success && res.data?.data) {
      const data = res.data.data
      settings.value.provider = data.provider || 'none'
      settings.value.billingo_block_id = data.billingo_block_id || ''
      settings.value.company_name = data.company_name || ''
      settings.value.company_address = data.company_address || ''
      settings.value.company_tax_number = data.company_tax_number || ''
      settings.value.company_eu_tax_number = data.company_eu_tax_number || ''
      settings.value.company_bank_account = data.company_bank_account || ''
      settings.value.company_bank_name = data.company_bank_name || ''
      settings.value.company_email = data.company_email || ''
      settings.value.company_phone = data.company_phone || ''
      settings.value.default_currency = data.default_currency || 'HUF'
      settings.value.default_tax_rate = data.default_tax_rate ?? 27.00
      settings.value.default_payment_terms_days = data.default_payment_terms_days ?? 8
      settings.value.default_payment_method = data.default_payment_method || 'bank_transfer'
      settings.value.default_language = data.default_language || 'hu'
      settings.value.auto_save_to_drive = data.auto_save_to_drive == 1 || data.auto_save_to_drive === true

      // Masked keys
      hasApiKey.value = !!data.has_api_key
      apiKeyMasked.value = data.api_key_masked || ''
      hasSzamlazzAgentKey.value = !!data.has_szamlazz_agent_key
      szamlazzAgentKeyMasked.value = data.szamlazz_agent_key_masked || ''

      // Don't set api_key / szamlazz_agent_key — they come back blank/masked
      settings.value.api_key = ''
      settings.value.szamlazz_agent_key = ''
    }
  } catch (e) {
    // No settings yet — use defaults
    console.warn('[BillingSettings] Failed to load:', e)
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  saving.value = true
  try {
    const payload = { ...settings.value }
    // Don't send empty strings for API keys — server treats that as "keep existing"
    if (!payload.api_key) delete payload.api_key
    if (!payload.szamlazz_agent_key) delete payload.szamlazz_agent_key
    // Convert boolean to int for DB storage
    payload.auto_save_to_drive = payload.auto_save_to_drive ? 1 : 0

    const res = await api.put('/billing/settings', payload)
    if (res.data?.success) {
      toast.success('Billing settings saved')
      await loadSettings() // Refresh to get masked keys
    } else {
      toast.error(res.data?.message || 'Failed to save settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save billing settings')
  } finally {
    saving.value = false
  }
}

async function testConnection() {
  testing.value = true
  try {
    const res = await api.post('/billing/test-connection')
    if (res.data?.success) {
      toast.success(res.data?.message || 'Connection successful!')
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
  fetchingBlocks.value = true
  try {
    const res = await api.get('/billing/invoice-blocks')
    if (res.data?.success) {
      invoiceBlocks.value = res.data.data?.blocks || []
      if (invoiceBlocks.value.length === 0) {
        toast.info('No invoice blocks found. Create one in your Billingo account first.')
      } else {
        toast.success(`Found ${invoiceBlocks.value.length} invoice block(s)`)
      }
    } else {
      toast.error(res.data?.message || 'Failed to fetch blocks')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to fetch invoice blocks')
  } finally {
    fetchingBlocks.value = false
  }
}
</script>

<template>
  <div class="space-y-8">
    <!-- CRM Pro Required Notice -->
    <div v-if="!addons.crmProEnabled.value" class="p-6 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl">
      <div class="flex items-start gap-3">
        <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-xl mt-0.5">info</span>
        <div>
          <h3 class="font-semibold text-amber-800 dark:text-amber-300">CRM Pro Required</h3>
          <p class="text-sm text-amber-700 dark:text-amber-400 mt-1">
            Billing integration requires the CRM Pro addon. Enable it from the VPS Admin Panel to use this feature.
          </p>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-else-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <template v-else>
      <!-- Provider Selection -->
      <div>
        <h3 class="text-base font-semibold text-surface-900 dark:text-white mb-1">Billing Provider</h3>
        <p class="text-sm text-surface-500 mb-4">
          Connect to an external, regulated invoicing platform to generate legal invoices.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <button
            v-for="p in providers"
            :key="p.value"
            @click="settings.provider = p.value"
            :class="[
              'p-4 rounded-xl border-2 text-left transition-all',
              settings.provider === p.value
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'
            ]"
          >
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-lg" :class="settings.provider === p.value ? 'text-primary-600 dark:text-primary-400' : 'text-surface-500'">{{ p.icon }}</span>
              <span class="font-medium text-sm" :class="settings.provider === p.value ? 'text-primary-700 dark:text-primary-300' : 'text-surface-900 dark:text-white'">{{ p.label }}</span>
            </div>
            <p class="text-xs text-surface-500 dark:text-surface-400">{{ p.description }}</p>
          </button>
        </div>
      </div>

      <!-- API Credentials -->
      <div v-if="!isNone" class="border-t border-surface-200 dark:border-surface-700 pt-6">
        <h3 class="text-base font-semibold text-surface-900 dark:text-white mb-1">API Credentials</h3>
        <p class="text-sm text-surface-500 mb-4">
          Your API keys are encrypted and stored securely. They are never exposed in the browser.
        </p>

        <!-- Billingo -->
        <div v-if="isBillingo" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Billingo API Key
              <span v-if="hasApiKey" class="text-xs text-green-600 dark:text-green-400 ml-2">✓ Configured</span>
            </label>
            <input
              v-model="settings.api_key"
              type="password"
              :placeholder="hasApiKey ? apiKeyMasked : 'Enter your Billingo API v3 key'"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
            <p class="text-xs text-surface-400 mt-1">
              Found in Billingo → Settings → API → V3 API keys
            </p>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                Invoice Block ID
              </label>
              <button
                @click="fetchBlocks"
                :disabled="fetchingBlocks || !hasApiKey"
                class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 font-medium disabled:opacity-50"
              >
                <span v-if="fetchingBlocks" class="animate-spin inline-block">↻</span>
                {{ fetchingBlocks ? 'Loading...' : 'Fetch Blocks' }}
              </button>
            </div>
            <select
              v-if="invoiceBlocks.length > 0"
              v-model="settings.billingo_block_id"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            >
              <option value="">Select a block...</option>
              <option v-for="block in invoiceBlocks" :key="block.id" :value="block.id">
                {{ block.name || block.prefix || `Block #${block.id}` }}
              </option>
            </select>
            <input
              v-else
              v-model="settings.billingo_block_id"
              type="text"
              placeholder="e.g. 12345"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
            <p class="text-xs text-surface-400 mt-1">
              The invoice block determines the numbering sequence. Click "Fetch Blocks" to load from Billingo.
            </p>
          </div>
        </div>

        <!-- Szamlazz.hu -->
        <div v-if="isSzamlazz" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Számlázz.hu Agent Key
              <span v-if="hasSzamlazzAgentKey" class="text-xs text-green-600 dark:text-green-400 ml-2">✓ Configured</span>
            </label>
            <input
              v-model="settings.szamlazz_agent_key"
              type="password"
              :placeholder="hasSzamlazzAgentKey ? szamlazzAgentKeyMasked : 'Enter your Számlázz.hu agent key'"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
            <p class="text-xs text-surface-400 mt-1">
              Found in Számlázz.hu → Beállítások → Számlázz.hu Agent Key
            </p>
          </div>
        </div>

        <!-- Test Connection -->
        <div class="mt-4">
          <button
            @click="testConnection"
            :disabled="testing"
            class="px-4 py-2 rounded-xl bg-surface-100 hover:bg-surface-200 dark:bg-surface-700 dark:hover:bg-surface-600
                   text-sm font-medium text-surface-700 dark:text-surface-300 transition-colors disabled:opacity-50 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg" :class="{ 'animate-spin': testing }">
              {{ testing ? 'sync' : 'cloud_sync' }}
            </span>
            {{ testing ? 'Testing...' : 'Test Connection' }}
          </button>
        </div>
      </div>

      <!-- Company Details -->
      <div class="border-t border-surface-200 dark:border-surface-700 pt-6">
        <h3 class="text-base font-semibold text-surface-900 dark:text-white mb-1">Company Details</h3>
        <p class="text-sm text-surface-500 mb-4">
          These details are sent to the billing provider when creating invoices.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Company Name</label>
            <input
              v-model="settings.company_name"
              type="text"
              placeholder="Your Company Kft."
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Tax Number</label>
            <input
              v-model="settings.company_tax_number"
              type="text"
              placeholder="12345678-2-42"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Address</label>
            <input
              v-model="settings.company_address"
              type="text"
              placeholder="1234 Budapest, Example Street 1."
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">EU VAT Number</label>
            <input
              v-model="settings.company_eu_tax_number"
              type="text"
              placeholder="HU12345678"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Email</label>
            <input
              v-model="settings.company_email"
              type="email"
              placeholder="billing@yourcompany.hu"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Bank Name</label>
            <input
              v-model="settings.company_bank_name"
              type="text"
              placeholder="OTP Bank"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Bank Account</label>
            <input
              v-model="settings.company_bank_account"
              type="text"
              placeholder="12345678-12345678-12345678"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Phone</label>
            <input
              v-model="settings.company_phone"
              type="text"
              placeholder="+36 1 234 5678"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
        </div>
      </div>

      <!-- Default Invoice Settings -->
      <div class="border-t border-surface-200 dark:border-surface-700 pt-6">
        <h3 class="text-base font-semibold text-surface-900 dark:text-white mb-1">Invoice Defaults</h3>
        <p class="text-sm text-surface-500 mb-4">
          Default values used when creating new invoices. These can be overridden per invoice.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Currency</label>
            <select
              v-model="settings.default_currency"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            >
              <option v-for="c in currencies" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">VAT Rate (%)</label>
            <input
              v-model.number="settings.default_tax_rate"
              type="number"
              min="0"
              max="100"
              step="0.01"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Payment Terms (days)</label>
            <input
              v-model.number="settings.default_payment_terms_days"
              type="number"
              min="0"
              max="365"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Payment Method</label>
            <select
              v-model="settings.default_payment_method"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            >
              <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Invoice Language</label>
            <select
              v-model="settings.default_language"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                     bg-white dark:bg-surface-800 text-surface-900 dark:text-white text-sm
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            >
              <option v-for="l in languages" :key="l.value" :value="l.value">{{ l.label }}</option>
            </select>
          </div>
          <div class="flex items-center gap-3 self-end pb-1">
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" v-model="settings.auto_save_to_drive" class="sr-only peer">
              <div class="w-10 h-5 bg-surface-300 dark:bg-surface-600 peer-focus:ring-2 peer-focus:ring-primary-500 rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary-500"></div>
            </label>
            <span class="text-sm text-surface-700 dark:text-surface-300">Auto-save PDFs to Drive</span>
          </div>
        </div>
      </div>

      <!-- Save Button -->
      <div class="border-t border-surface-200 dark:border-surface-700 pt-6 flex items-center justify-between">
        <p class="text-xs text-surface-400">
          API keys are encrypted with AES-256-GCM before storage.
        </p>
        <button
          @click="saveSettings"
          :disabled="saving"
          class="px-6 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium
                 transition-colors disabled:opacity-50 flex items-center gap-2"
        >
          <span class="material-symbols-rounded text-lg" :class="{ 'animate-spin': saving }">
            {{ saving ? 'sync' : 'save' }}
          </span>
          {{ saving ? 'Saving...' : 'Save Settings' }}
        </button>
      </div>
    </template>
  </div>
</template>

