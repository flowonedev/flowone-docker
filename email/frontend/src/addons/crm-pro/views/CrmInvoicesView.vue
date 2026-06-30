<script setup>
/**
 * CrmInvoicesView - Full invoice management view
 * Lists invoices, summary stats, filter by status, create/view/edit invoices.
 * Supports pushing invoices to external billing providers (Billingo / Szamlazz.hu).
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import AppHeader from '@/components/shared/AppHeader.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CrmSidebar from '../components/CrmSidebar.vue'
import CrmInvoiceEditor from '../components/CrmInvoiceEditor.vue'
import CrmInvoicePreview from '../components/CrmInvoicePreview.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { crmInvoicesGuide } from '@/data/stepGuides'

const router = useRouter()

const toast = useToastStore()

const invoices = ref([])
const summary = ref({})
const loading = ref(true)
const filter = ref('all')
const search = ref('')
const showEditor = ref(false)
const editingInvoice = ref(null)
const showPreview = ref(false)
const previewInvoice = ref(null)

// Billing provider integration state
const pushingInvoice = ref(null)    // ID of invoice being pushed
const sendingEmail = ref(null)      // ID of invoice being emailed
const syncingStatus = ref(null)     // ID of invoice being status-synced
const showSendEmailModal = ref(false)
const sendEmailTarget = ref(null)
const sendEmailForm = ref({ recipient_email: '', subject: '', body: '' })

const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.crmInvoices

const filteredInvoices = computed(() => {
  let list = invoices.value
  if (filter.value !== 'all') {
    list = list.filter(i => i.status === filter.value)
  }
  if (search.value.trim()) {
    const q = search.value.toLowerCase()
    list = list.filter(i =>
      i.invoice_number?.toLowerCase().includes(q) ||
      i.notes?.toLowerCase().includes(q)
    )
  }
  return list
})

const statusCounts = computed(() => {
  const counts = { all: invoices.value.length }
  for (const inv of invoices.value) {
    counts[inv.status] = (counts[inv.status] || 0) + 1
  }
  return counts
})

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  fetchInvoices()
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

async function fetchInvoices() {
  loading.value = true
  try {
    const res = await api.get('/crm/invoices')
    if (res.data?.success) {
      invoices.value = res.data.data?.invoices || []
      summary.value = res.data.data?.summary || {}
    }
  } catch (e) {
    toast.error('Failed to load invoices')
  } finally {
    loading.value = false
  }
}

function createNew() {
  editingInvoice.value = null
  showEditor.value = true
}

function editInvoice(inv) {
  editingInvoice.value = inv
  showEditor.value = true
}

function viewInvoice(inv) {
  previewInvoice.value = inv
  showPreview.value = true
}

async function deleteInvoice(inv) {
  if (!confirm(`Delete draft invoice ${inv.invoice_number}?`)) return
  try {
    await api.delete(`/crm/invoices/${inv.id}`)
    toast.success('Invoice deleted')
    fetchInvoices()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to delete')
  }
}

async function sendInvoice(inv) {
  try {
    await api.post(`/crm/invoices/${inv.id}/send`)
    toast.success('Invoice marked as sent')
    fetchInvoices()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to send')
  }
}

// Push invoice to external billing provider (Billingo / Szamlazz.hu)
async function pushToProvider(inv) {
  if (inv.external_invoice_id) {
    toast.info('Invoice already pushed to ' + (inv.billing_provider || 'provider'))
    return
  }
  pushingInvoice.value = inv.id
  try {
    const res = await api.post(`/crm/invoices/${inv.id}/push`, { electronic: true })
    if (res.data?.success) {
      toast.success(res.data.message || 'Invoice pushed to billing provider')
      if (res.data.data?.drive_file) {
        toast.info('PDF saved to Drive')
      }
      fetchInvoices()
    } else {
      toast.error(res.data?.message || 'Failed to push invoice')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to push to billing provider')
  } finally {
    pushingInvoice.value = null
  }
}

// Open send email modal
function openSendEmailModal(inv) {
  sendEmailTarget.value = inv
  sendEmailForm.value = {
    recipient_email: inv.client_email || '',
    subject: `Invoice ${inv.invoice_number}`,
    body: `Please find your invoice ${inv.invoice_number} attached.`,
  }
  showSendEmailModal.value = true
}

// Send invoice PDF via email
async function sendInvoiceEmail() {
  if (!sendEmailTarget.value) return
  sendingEmail.value = sendEmailTarget.value.id
  try {
    const res = await api.post(`/crm/invoices/${sendEmailTarget.value.id}/send-email`, sendEmailForm.value)
    if (res.data?.success) {
      toast.success(res.data.message || 'Invoice email sent')
      showSendEmailModal.value = false
      fetchInvoices()
    } else {
      toast.error(res.data?.message || 'Failed to send email')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to send invoice email')
  } finally {
    sendingEmail.value = null
  }
}

// Sync invoice status from external provider
async function syncStatus(inv) {
  syncingStatus.value = inv.id
  try {
    const res = await api.post(`/crm/invoices/${inv.id}/sync-status`)
    if (res.data?.success) {
      toast.success('Status synced')
      fetchInvoices()
    } else {
      toast.error(res.data?.message || 'Failed to sync status')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to sync status')
  } finally {
    syncingStatus.value = null
  }
}

// Download PDF from external provider
async function downloadPdf(inv) {
  try {
    const res = await api.get(`/crm/invoices/${inv.id}/download-pdf`)
    if (res.data?.success && res.data.data?.pdf_url) {
      window.open(res.data.data.pdf_url, '_blank')
    } else if (res.data?.success && res.data.data?.pdf_base64) {
      // Create a download link from base64
      const link = document.createElement('a')
      link.href = `data:application/pdf;base64,${res.data.data.pdf_base64}`
      link.download = `${inv.invoice_number || 'invoice'}.pdf`
      link.click()
    } else {
      toast.error(res.data?.message || 'No PDF available')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to download PDF')
  }
}

function onEditorSaved() {
  showEditor.value = false
  editingInvoice.value = null
  fetchInvoices()
}

function formatMoney(amount, currency = 'HUF') {
  return new Intl.NumberFormat('hu-HU', { style: 'currency', currency }).format(amount || 0)
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const statusColors = {
  draft: 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300',
  sent: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
  viewed: 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-300',
  partial: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300',
  paid: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300',
  overdue: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300',
  cancelled: 'bg-surface-100 dark:bg-surface-700 text-surface-400',
  refunded: 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300',
}

const statusIcons = {
  draft: 'edit_note', sent: 'send', viewed: 'visibility', partial: 'payments',
  paid: 'check_circle', overdue: 'warning', cancelled: 'cancel', refunded: 'undo',
}

const providerLabels = {
  billingo: 'Billingo',
  szamlazz: 'Számlázz.hu',
  manual: 'Manual',
}
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- App Header -->
    <AppHeader
      current-view="crm-invoices"
      icon="receipt_long"
      title="Invoices"
    >
      <template #title-badge>
        <ViewInfoButton view-key="crmInvoices" />
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>

    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <CrmSidebar />

      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 flex flex-col overflow-hidden'">
    <!-- Sub-header with action -->
    <div class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))]">
      <p class="text-sm text-surface-500 hidden sm:block">Manage invoices and track payments</p>
      <div class="flex items-center gap-2">
        <button @click="createNew"
                class="px-4 py-2 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium flex items-center gap-2 transition-colors">
          <span class="material-symbols-rounded text-lg">add</span>
          New Invoice
        </button>
      </div>
    </div>

    <!-- Feature Guide -->
    <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />

    <!-- Summary Cards -->
    <div v-if="!loading && Object.keys(summary).length" class="px-4 sm:px-6 py-3 grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="p-3 rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20">
        <p class="text-xs text-green-600 dark:text-green-400 font-medium">Revenue</p>
        <p class="text-lg font-bold text-green-700 dark:text-green-300">{{ formatMoney(summary.total_revenue) }}</p>
      </div>
      <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20">
        <p class="text-xs text-amber-600 dark:text-amber-400 font-medium">Outstanding</p>
        <p class="text-lg font-bold text-amber-700 dark:text-amber-300">{{ formatMoney(summary.outstanding) }}</p>
      </div>
      <div class="p-3 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20">
        <p class="text-xs text-red-600 dark:text-red-400 font-medium">Overdue ({{ summary.overdue_count || 0 }})</p>
        <p class="text-lg font-bold text-red-700 dark:text-red-300">{{ formatMoney(summary.overdue_amount) }}</p>
      </div>
      <div class="p-3 rounded-xl bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/20">
        <p class="text-xs text-blue-600 dark:text-blue-400 font-medium">Net Revenue</p>
        <p class="text-lg font-bold text-blue-700 dark:text-blue-300">{{ formatMoney(summary.net_revenue) }}</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 border-b border-surface-200 dark:border-surface-700">
      <div class="flex gap-1 overflow-x-auto -webkit-overflow-scrolling-touch">
        <button v-for="f in ['all', 'draft', 'sent', 'paid', 'overdue', 'partial']" :key="f"
                @click="filter = f"
                :class="['px-3 py-1.5 rounded-lg text-xs font-medium transition-colors whitespace-nowrap flex-shrink-0',
                  filter === f
                    ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
                    : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700']">
          {{ f.charAt(0).toUpperCase() + f.slice(1) }}
          <span v-if="statusCounts[f]" class="ml-1 opacity-60">{{ statusCounts[f] }}</span>
        </button>
      </div>
      <div class="hidden sm:block flex-1"></div>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-lg text-surface-400">search</span>
        <input v-model="search" placeholder="Search invoices..."
               class="pl-9 pr-4 py-2 rounded-xl bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 text-sm text-surface-700 dark:text-surface-200 w-full sm:w-56 focus:ring-2 focus:ring-primary-500 outline-none" />
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <!-- Invoice Table -->
    <div v-else-if="filteredInvoices.length > 0" class="flex-1 overflow-auto -webkit-overflow-scrolling-touch">
      <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-surface-50 dark:bg-surface-800 sticky top-0">
          <tr class="text-xs font-semibold text-surface-500 uppercase">
            <th class="px-3 md:px-6 py-3 text-left">Invoice</th>
            <th class="px-3 md:px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-left hide-on-mobile">Provider</th>
            <th class="px-4 py-3 text-left hide-on-mobile">Due Date</th>
            <th class="px-3 md:px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-right hide-on-mobile">Balance</th>
            <th class="px-6 py-3 text-right hide-on-mobile">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-100 dark:divide-surface-700/50">
          <tr v-for="inv in filteredInvoices" :key="inv.id"
              class="hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors cursor-pointer"
              @click="viewInvoice(inv)">
            <td class="px-3 md:px-6 py-3">
              <p class="font-semibold text-sm text-surface-900 dark:text-white">{{ inv.invoice_number }}</p>
              <p class="text-xs text-surface-400">{{ formatDate(inv.issue_date) }} · {{ inv.item_count || 0 }} items</p>
            </td>
            <td class="px-3 md:px-4 py-3">
              <span :class="['inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium', statusColors[inv.status] || '']">
                <span class="material-symbols-rounded text-xs">{{ statusIcons[inv.status] || 'circle' }}</span>
                {{ inv.status }}
              </span>
            </td>
            <td class="px-4 py-3 hide-on-mobile">
              <template v-if="inv.external_invoice_id">
                <a
                  v-if="inv.external_invoice_url"
                  :href="inv.external_invoice_url"
                  target="_blank"
                  @click.stop
                  class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium bg-green-100 dark:bg-green-500/10 text-green-700 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-500/20 transition-colors"
                  :title="`View on ${providerLabels[inv.billing_provider] || inv.billing_provider}`"
                >
                  <span class="material-symbols-rounded text-xs">open_in_new</span>
                  {{ providerLabels[inv.billing_provider] || inv.billing_provider }}
                </a>
                <span v-else class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium bg-green-100 dark:bg-green-500/10 text-green-700 dark:text-green-400">
                  <span class="material-symbols-rounded text-xs">check_circle</span>
                  {{ providerLabels[inv.billing_provider] || inv.billing_provider }}
                </span>
              </template>
              <span v-else class="text-xs text-surface-400">—</span>
            </td>
            <td class="px-4 py-3 text-sm text-surface-600 dark:text-surface-300 hide-on-mobile">{{ formatDate(inv.due_date) }}</td>
            <td class="px-3 md:px-4 py-3 text-sm text-right font-medium text-surface-900 dark:text-white">{{ formatMoney(inv.total, inv.currency) }}</td>
            <td class="px-4 py-3 text-sm text-right font-semibold hide-on-mobile" :class="(inv.total - inv.paid_amount) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400'">
              {{ formatMoney(inv.total - (inv.paid_amount || 0), inv.currency) }}
            </td>
            <td class="px-6 py-3 text-right hide-on-mobile" @click.stop>
              <div class="flex items-center justify-end gap-1">
                <!-- Push to billing provider (only if not yet pushed) -->
                <button
                  v-if="!inv.external_invoice_id"
                  @click="pushToProvider(inv)"
                  :disabled="pushingInvoice === inv.id"
                  title="Push to billing provider (Billingo / Számlázz.hu)"
                  class="p-1.5 rounded-lg text-purple-500 hover:bg-purple-50 dark:hover:bg-purple-500/10 disabled:opacity-50"
                >
                  <span class="material-symbols-rounded text-lg" :class="{ 'animate-spin': pushingInvoice === inv.id }">
                    {{ pushingInvoice === inv.id ? 'sync' : 'cloud_upload' }}
                  </span>
                </button>

                <!-- Send email with PDF (only if pushed to provider) -->
                <button
                  v-if="inv.external_invoice_id"
                  @click="openSendEmailModal(inv)"
                  title="Send invoice PDF via email"
                  class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10"
                >
                  <span class="material-symbols-rounded text-lg">forward_to_inbox</span>
                </button>

                <!-- Download PDF (only if pushed) -->
                <button
                  v-if="inv.external_invoice_id"
                  @click="downloadPdf(inv)"
                  title="Download invoice PDF"
                  class="p-1.5 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
                >
                  <span class="material-symbols-rounded text-lg">download</span>
                </button>

                <!-- Sync status from provider -->
                <button
                  v-if="inv.external_invoice_id"
                  @click="syncStatus(inv)"
                  :disabled="syncingStatus === inv.id"
                  title="Sync status from billing provider"
                  class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 disabled:opacity-50"
                >
                  <span class="material-symbols-rounded text-lg" :class="{ 'animate-spin': syncingStatus === inv.id }">sync</span>
                </button>

                <!-- Mark as sent (only for drafts not pushed) -->
                <button v-if="inv.status === 'draft' && !inv.external_invoice_id" @click="sendInvoice(inv)" title="Mark as sent"
                        class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10">
                  <span class="material-symbols-rounded text-lg">send</span>
                </button>

                <!-- Edit -->
                <button @click="editInvoice(inv)" title="Edit"
                        class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700">
                  <span class="material-symbols-rounded text-lg">edit</span>
                </button>

                <!-- Delete (only drafts not pushed) -->
                <button v-if="inv.status === 'draft' && !inv.external_invoice_id" @click="deleteInvoice(inv)" title="Delete"
                        class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                  <span class="material-symbols-rounded text-lg">delete</span>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">receipt_long</span>
        <h3 class="text-lg font-semibold text-surface-600 dark:text-surface-300 mt-3">No invoices yet</h3>
        <p class="text-sm text-surface-400 mt-1">Create your first invoice to get started</p>
        <button @click="createNew"
                class="mt-4 px-5 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
          Create Invoice
        </button>
      </div>
    </div>

      </div><!-- end flex-1 content -->
    </div><!-- end flex sidebar+content -->

    <!-- Invoice Editor Modal -->
    <CrmInvoiceEditor
      v-if="showEditor"
      :invoice="editingInvoice"
      @close="showEditor = false; editingInvoice = null"
      @saved="onEditorSaved"
    />

    <!-- Invoice Preview Modal -->
    <CrmInvoicePreview
      v-if="showPreview"
      :invoice="previewInvoice"
      @close="showPreview = false; previewInvoice = null"
      @edit="(inv) => { showPreview = false; editInvoice(inv) }"
      @refresh="fetchInvoices"
    />

    <!-- Send Invoice Email Modal -->
    <Teleport to="body">
      <div v-if="showSendEmailModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showSendEmailModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">forward_to_inbox</span>
            Send Invoice via Email
          </h3>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Recipient Email</label>
              <input
                v-model="sendEmailForm.recipient_email"
                type="email"
                placeholder="client@example.com"
                class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                       bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                       focus:ring-2 focus:ring-primary-500 outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Subject</label>
              <input
                v-model="sendEmailForm.subject"
                type="text"
                class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                       bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                       focus:ring-2 focus:ring-primary-500 outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Message</label>
              <textarea
                v-model="sendEmailForm.body"
                rows="3"
                class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600
                       bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                       focus:ring-2 focus:ring-primary-500 outline-none resize-none"
              ></textarea>
            </div>
          </div>
          <div class="flex justify-end gap-3 mt-6">
            <button @click="showSendEmailModal = false" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300">
              Cancel
            </button>
            <button
              @click="sendInvoiceEmail"
              :disabled="sendingEmail || !sendEmailForm.recipient_email"
              class="px-5 py-2 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium
                     disabled:opacity-50 flex items-center gap-2 transition-colors"
            >
              <span class="material-symbols-rounded text-lg" :class="{ 'animate-spin': sendingEmail }">
                {{ sendingEmail ? 'sync' : 'send' }}
              </span>
              {{ sendingEmail ? 'Sending...' : 'Send Email' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <StepGuide
      v-if="showStepGuide"
      :title-key="crmInvoicesGuide.titleKey"
      :subtitle-key="crmInvoicesGuide.subtitleKey"
      :header-icon="crmInvoicesGuide.headerIcon"
      :header-color="crmInvoicesGuide.headerColor"
      :storage-key="crmInvoicesGuide.storageKey"
      :steps="crmInvoicesGuide.steps"
      @close="showStepGuide = false"
    />

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>
