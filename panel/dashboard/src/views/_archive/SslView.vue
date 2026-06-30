<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'

const toast = useToastStore()

const loading = ref(true)
const certificates = ref([])
const issueModal = ref({ show: false, domain: '' })
const deleteModal = ref({ show: false, cert: null })
const preflightResult = ref(null)
const submitting = ref(false)

// Filters
const searchQuery = ref('')
const filterType = ref('all') // 'all', 'sites', 'mail', 'selfsigned'
const sortBy = ref('expiry') // 'expiry', 'name', 'days'

const filteredCertificates = computed(() => {
  let result = [...certificates.value]
  
  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(cert => 
      cert.domain.toLowerCase().includes(query) ||
      cert.issuer?.toLowerCase().includes(query)
    )
  }
  
  // Type filter
  if (filterType.value === 'mail') {
    result = result.filter(cert => cert.domain.startsWith('mail.'))
  } else if (filterType.value === 'sites') {
    result = result.filter(cert => !cert.domain.startsWith('mail.'))
  } else if (filterType.value === 'selfsigned') {
    result = result.filter(cert => cert.is_self_signed)
  }
  
  // Sort
  if (sortBy.value === 'expiry') {
    result.sort((a, b) => (a.days_remaining || 0) - (b.days_remaining || 0))
  } else if (sortBy.value === 'name') {
    result.sort((a, b) => a.domain.localeCompare(b.domain))
  } else if (sortBy.value === 'days') {
    result.sort((a, b) => (b.days_remaining || 0) - (a.days_remaining || 0))
  }
  
  return result
})

// Stats
const stats = computed(() => ({
  total: certificates.value.length,
  valid: certificates.value.filter(c => !c.is_expired && !c.is_self_signed).length,
  expiring: certificates.value.filter(c => c.days_remaining < 30 && !c.is_expired).length,
  expired: certificates.value.filter(c => c.is_expired).length,
  selfSigned: certificates.value.filter(c => c.is_self_signed).length
}))

const fetchCertificates = async () => {
  try {
    const response = await api.get('/ssl')
    if (response.data.success) {
      certificates.value = response.data.data.certificates || []
    }
  } catch (e) {
    toast.error('Failed to load certificates')
  } finally {
    loading.value = false
  }
}

const runPreflight = async () => {
  submitting.value = true
  preflightResult.value = null
  
  try {
    const response = await api.post(`/ssl/${issueModal.value.domain}/preflight`)
    if (response.data.success) {
      preflightResult.value = response.data.data
    } else {
      toast.error(response.data.error || 'Preflight check failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Preflight check failed')
  } finally {
    submitting.value = false
  }
}

const issueCertificate = async () => {
  submitting.value = true
  
  try {
    const response = await api.post(`/ssl/${issueModal.value.domain}/issue`)
    if (response.data.success) {
      toast.success('Certificate issued successfully')
      issueModal.value = { show: false, domain: '' }
      preflightResult.value = null
      await fetchCertificates()
    } else {
      toast.error(response.data.error || 'Failed to issue certificate')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to issue certificate')
  } finally {
    submitting.value = false
  }
}

const renewAll = async () => {
  submitting.value = true
  
  try {
    const response = await api.post('/ssl/renew')
    if (response.data.success) {
      toast.success('Certificates renewed')
      await fetchCertificates()
    } else {
      toast.error(response.data.error || 'Renewal failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Renewal failed')
  } finally {
    submitting.value = false
  }
}

const getCertStatus = (cert) => {
  if (cert.is_expired) return 'expired'
  if (cert.is_self_signed) return 'warning'
  if (cert.days_remaining < 30) return 'expiring'
  return 'valid'
}

const getStatusClass = (cert) => {
  const status = getCertStatus(cert)
  if (status === 'valid') return 'text-green-600 dark:text-green-400'
  if (status === 'expired') return 'text-red-600 dark:text-red-400'
  return 'text-amber-600 dark:text-amber-400'
}

const openIssueModal = (domain = '') => {
  issueModal.value = { show: true, domain }
  preflightResult.value = null
}

const deleteCertificate = async () => {
  if (!deleteModal.value.cert) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/ssl/${deleteModal.value.cert.domain}`)
    if (response.data.success) {
      toast.success('Certificate deleted')
      deleteModal.value = { show: false, cert: null }
      await fetchCertificates()
    } else {
      toast.error(response.data.error || 'Failed to delete certificate')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete certificate')
  } finally {
    submitting.value = false
  }
}

onMounted(fetchCertificates)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">SSL Certificates</h1>
        <p class="text-surface-500 text-sm mt-1">Manage SSL/TLS certificates</p>
      </div>
      <div class="flex gap-2">
        <button @click="renewAll" class="btn-secondary" :disabled="submitting">
          <span class="material-symbols-rounded">autorenew</span>
          Renew All
        </button>
        <button @click="openIssueModal()" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          Issue Certificate
        </button>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Total</p>
        <p class="stat-value">{{ stats.total }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Valid</p>
        <p class="stat-value text-green-600">{{ stats.valid }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Expiring Soon</p>
        <p class="stat-value text-amber-600">{{ stats.expiring }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Expired</p>
        <p class="stat-value text-red-600">{{ stats.expired }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Self-Signed</p>
        <p class="stat-value text-surface-500">{{ stats.selfSigned }}</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="card p-4 mb-6">
      <div class="flex flex-wrap gap-4 items-center">
        <!-- Search -->
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input
            v-model="searchQuery"
            type="text"
            class="input pl-10"
            placeholder="Search by domain or issuer..."
          />
        </div>
        
        <!-- Type filter -->
        <select v-model="filterType" class="input w-auto">
          <option value="all">All Certificates</option>
          <option value="sites">Sites Only</option>
          <option value="mail">Mail Only</option>
          <option value="selfsigned">Self-Signed</option>
        </select>
        
        <!-- Sort -->
        <select v-model="sortBy" class="input w-auto">
          <option value="expiry">Expiring Soon</option>
          <option value="days">Days Remaining</option>
          <option value="name">Name A-Z</option>
        </select>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Certificates table -->
    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th>Domain</th>
            <th>Issuer</th>
            <th>Valid Until</th>
            <th>Days Left</th>
            <th>Status</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="cert in filteredCertificates" :key="cert.domain">
            <td>
              <div class="flex items-center gap-3">
                <div :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center',
                  getCertStatus(cert) === 'valid' 
                    ? 'bg-green-100 dark:bg-green-500/20'
                    : getCertStatus(cert) === 'expired'
                      ? 'bg-red-100 dark:bg-red-500/20'
                      : 'bg-amber-100 dark:bg-amber-500/20'
                ]">
                  <span :class="['material-symbols-rounded', getStatusClass(cert)]">verified_user</span>
                </div>
                <div>
                  <p class="font-medium">{{ cert.domain }}</p>
                  <p v-if="cert.sans?.length" class="text-xs text-surface-500">
                    +{{ cert.sans.length }} SAN{{ cert.sans.length > 1 ? 's' : '' }}
                  </p>
                </div>
              </div>
            </td>
            <td>
              <span class="text-surface-600 dark:text-surface-400">{{ cert.issuer || '—' }}</span>
            </td>
            <td>
              <span class="text-sm">{{ cert.valid_to }}</span>
            </td>
            <td>
              <span :class="[
                'font-medium',
                cert.days_remaining < 30 ? 'text-amber-500' : 'text-surface-600 dark:text-surface-400'
              ]">
                {{ cert.days_remaining }}
              </span>
            </td>
            <td>
              <StatusBadge :status="getCertStatus(cert)" />
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <button 
                  @click="openIssueModal(cert.domain)" 
                  class="btn-ghost btn-sm"
                  title="Renew"
                >
                  <span class="material-symbols-rounded">autorenew</span>
                </button>
                <button 
                  @click="deleteModal = { show: true, cert }" 
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Delete"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!filteredCertificates.length">
            <td colspan="6" class="py-12 text-center text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">verified_user</span>
              No certificates found
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Issue modal -->
    <Modal :show="issueModal.show" title="Issue SSL Certificate" size="lg" @close="issueModal.show = false">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Domain</label>
          <input
            v-model="issueModal.domain"
            type="text"
            class="input"
            placeholder="example.com"
          />
        </div>

        <!-- Preflight results -->
        <div v-if="preflightResult" class="border rounded-xl p-4 dark:border-surface-700">
          <h4 class="font-medium mb-3 flex items-center gap-2">
            <span :class="[
              'material-symbols-rounded',
              preflightResult.ready ? 'text-green-500' : 'text-amber-500'
            ]">
              {{ preflightResult.ready ? 'check_circle' : 'warning' }}
            </span>
            Preflight Checks
          </h4>

          <div class="space-y-2">
            <div v-for="(value, key) in preflightResult.checks" :key="key" class="flex items-center gap-2 text-sm">
              <span :class="[
                'material-symbols-rounded text-lg',
                value ? 'text-green-500' : 'text-red-500'
              ]">
                {{ value ? 'check' : 'close' }}
              </span>
              <span>{{ key.replace(/_/g, ' ') }}</span>
            </div>
          </div>

          <div v-if="preflightResult.issues?.length" class="mt-4 p-3 bg-red-50 dark:bg-red-500/10 rounded-lg">
            <p class="text-sm text-red-600 dark:text-red-400 font-medium mb-2">Issues:</p>
            <ul class="text-sm text-red-600 dark:text-red-400 space-y-1">
              <li v-for="issue in preflightResult.issues" :key="issue">{{ issue }}</li>
            </ul>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="issueModal.show = false" class="btn-secondary">
            Cancel
          </button>
          <button 
            v-if="!preflightResult"
            @click="runPreflight" 
            class="btn-secondary"
            :disabled="!issueModal.domain || submitting"
          >
            <span v-if="submitting" class="spinner"></span>
            Run Preflight
          </button>
          <button 
            v-if="preflightResult?.ready"
            @click="issueCertificate" 
            class="btn-primary"
            :disabled="submitting"
          >
            <span v-if="submitting" class="spinner"></span>
            Issue Certificate
          </button>
        </div>
      </div>
    </Modal>

    <!-- Delete modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete Certificate"
      :message="`Are you sure you want to delete the certificate for '${deleteModal.cert?.domain}'? A backup will be created.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      require-confirmation="DELETE"
      @confirm="deleteCertificate"
      @cancel="deleteModal = { show: false, cert: null }"
    />
  </div>
</template>
