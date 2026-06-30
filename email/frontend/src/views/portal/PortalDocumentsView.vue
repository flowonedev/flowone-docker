<script setup>
/**
 * PortalDocumentsView - Client portal document listing
 * Shows all documents shared with the client (contracts, invoices, proposals, etc.)
 * with signing status and actions.
 */
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import portalApi from '@/services/portalApi'

const router = useRouter()
const { t, locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

const documents = ref([])
const loading = ref(true)
const error = ref('')
const filter = ref('all') // all, pending, signed, draft

const filteredDocuments = computed(() => {
  if (filter.value === 'all') return documents.value
  if (filter.value === 'pending') return documents.value.filter(d => ['sent', 'viewed', 'signing'].includes(d.status) && d.my_signer_status === 'pending')
  if (filter.value === 'signed') return documents.value.filter(d => d.my_signer_status === 'signed' || d.status === 'signed')
  return documents.value
})

const pendingCount = computed(() => documents.value.filter(d => d.my_signer_status === 'pending').length)
const filterOptions = computed(() => ([
  { v: 'all', l: t('portalDocumentsView.filters.all') },
  { v: 'pending', l: t('portalDocumentsView.filters.needsSignature') },
  { v: 'signed', l: t('portalDocumentsView.filters.signed') },
]))

onMounted(() => fetchDocuments())

async function fetchDocuments() {
  loading.value = true
  try {
    const res = await portalApi.get('/portal/documents')
    if (res.data?.success) {
      documents.value = res.data.data?.documents || res.data.data || []
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'portalDocumentsView.failedToLoadDocuments'
  } finally {
    loading.value = false
  }
}

function openDocument(doc) {
  router.push({ name: 'portal-document', params: { docId: doc.id } })
}

function statusBadge(status) {
  const map = {
    draft: { label: t('portalDocumentsView.status.draft'), class: 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300' },
    sent: { label: t('portalDocumentsView.status.sent'), class: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' },
    viewed: { label: t('portalDocumentsView.status.viewed'), class: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300' },
    signing: { label: t('portalDocumentsView.status.signing'), class: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' },
    signed: { label: t('portalDocumentsView.status.signed'), class: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300' },
    rejected: { label: t('portalDocumentsView.status.rejected'), class: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' },
    expired: { label: t('portalDocumentsView.status.expired'), class: 'bg-surface-200 text-surface-500 dark:bg-surface-600 dark:text-surface-400' },
    archived: { label: t('portalDocumentsView.status.archived'), class: 'bg-surface-200 text-surface-500 dark:bg-surface-600 dark:text-surface-400' }
  }
  return map[status] || map.draft
}

function docTypeIcon(type) {
  const map = {
    contract: 'gavel', invoice: 'receipt', proposal: 'description',
    quote: 'request_quote', nda: 'security', agreement: 'handshake',
    receipt: 'receipt_long', other: 'draft'
  }
  return map[type] || 'draft'
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(localeTag.value, { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatCurrency(amount, currency) {
  if (!amount) return ''
  return new Intl.NumberFormat(localeTag.value, { style: 'currency', currency: currency || 'HUF' }).format(amount)
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h2 class="text-xl font-bold text-surface-900 dark:text-white">{{ $t('portalDocumentsView.documents') }}</h2>
        <p v-if="pendingCount > 0" class="text-sm text-amber-600 dark:text-amber-400 mt-0.5">
          {{ $t('portalDocumentsView.pendingSignatureCount', pendingCount, { count: pendingCount }) }}
        </p>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex gap-2 mb-4">
      <button v-for="f in filterOptions" :key="f.v"
              @click="filter = f.v"
              :class="['px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                filter === f.v 
                  ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300' 
                  : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700']">
        {{ f.l }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-16">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-16">
      <span class="material-symbols-rounded text-4xl text-red-400">error</span>
      <p class="mt-2 text-surface-500">{{ typeof error === 'string' && error.startsWith('portalDocumentsView.') ? $t(error) : error }}</p>
    </div>

    <!-- Empty -->
    <div v-else-if="filteredDocuments.length === 0" class="text-center py-16">
      <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">description</span>
      <h3 class="text-lg font-semibold text-surface-600 dark:text-surface-300 mt-3">{{ $t('portalDocumentsView.noDocuments') }}</h3>
      <p class="text-sm text-surface-400 mt-1">
        {{ filter === 'all' ? $t('portalDocumentsView.emptyAll') : $t('portalDocumentsView.emptyFiltered') }}
      </p>
    </div>

    <!-- Document List -->
    <div v-else class="space-y-3">
      <div v-for="doc in filteredDocuments" :key="doc.id"
           @click="openDocument(doc)"
           class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 cursor-pointer 
                  hover:shadow-md hover:border-primary-300 dark:hover:border-primary-500/40 transition-all group">
        <div class="flex items-center gap-4">
          <!-- Icon -->
          <div class="w-10 h-10 rounded-lg bg-surface-100 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-xl text-surface-500">{{ docTypeIcon(doc.document_type) }}</span>
          </div>

          <!-- Info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <h3 class="text-sm font-semibold text-surface-900 dark:text-white truncate group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                {{ doc.title }}
              </h3>
              <span :class="['text-xs font-medium px-2 py-0.5 rounded-full whitespace-nowrap', statusBadge(doc.status).class]">
                {{ statusBadge(doc.status).label }}
              </span>
            </div>
            <div class="flex items-center gap-3 text-xs text-surface-400 mt-1">
              <span>{{ doc.document_type }}</span>
              <span v-if="doc.amount">{{ formatCurrency(doc.amount, doc.currency) }}</span>
              <span v-if="doc.signing_deadline">{{ $t('portalDocumentsView.dueDate', { date: formatDate(doc.signing_deadline) }) }}</span>
              <span>{{ formatDate(doc.created_at) }}</span>
            </div>
          </div>

          <!-- Action indicator -->
          <div v-if="doc.my_signer_status === 'pending'" class="flex-shrink-0">
            <span class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300 text-xs font-medium">
              <span class="material-symbols-rounded text-sm">draw</span>
              {{ $t('portalDocumentsView.sign') }}
            </span>
          </div>
          <div v-else class="flex-shrink-0">
            <span class="material-symbols-rounded text-lg text-surface-300 group-hover:text-surface-500 transition-colors">chevron_right</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
