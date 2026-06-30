<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'

const toast = useToastStore()

const loading = ref(true)
const status = ref(null)
const domains = ref([])
const accounts = ref([])
const forwards = ref([])
const createAccountModal = ref(false)
const deleteModal = ref({ show: false, type: null, item: null })
const resetPasswordModal = ref({ show: false, account: null })
const submitting = ref(false)
const searchQuery = ref('')
const selectedDomain = ref('all')
const showSettings = ref(false)

const newAccount = ref({ email: '', domain: '', password: '' })
const newPassword = ref('')

// DNS Records state
const dnsModal = ref({ show: false, domain: null })
const dnsRecords = ref(null)
const dnsLoading = ref(false)
const dkimGenerating = ref(false)
const setupRecordLoading = ref(null)

// Sorting
const sortColumn = ref('email')
const sortOrder = ref('asc')

const toggleSort = (column) => {
  if (sortColumn.value === column) {
    sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortColumn.value = column
    sortOrder.value = 'asc'
  }
}

// Create a map of forwards by source email
const forwardsBySource = computed(() => {
  const map = {}
  for (const fwd of forwards.value) {
    if (!map[fwd.source]) {
      map[fwd.source] = []
    }
    map[fwd.source].push(fwd.destination)
  }
  return map
})

// Filter accounts
const filteredAccounts = computed(() => {
  let result = [...accounts.value]
  
  // Filter by domain
  if (selectedDomain.value !== 'all') {
    result = result.filter(a => a.domain === selectedDomain.value)
  }
  
  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(a => 
      a.email.toLowerCase().includes(query) ||
      forwardsBySource.value[a.email]?.some(d => d.toLowerCase().includes(query))
    )
  }
  
  // Sort
  result.sort((a, b) => {
    let aVal, bVal
    
    switch (sortColumn.value) {
      case 'email':
        aVal = a.email || ''
        bVal = b.email || ''
        break
      case 'domain':
        aVal = a.domain || ''
        bVal = b.domain || ''
        break
      case 'size':
        aVal = a.size || 0
        bVal = b.size || 0
        break
      default:
        aVal = a.email || ''
        bVal = b.email || ''
    }
    
    if (typeof aVal === 'string') {
      return sortOrder.value === 'asc' 
        ? aVal.localeCompare(bVal) 
        : bVal.localeCompare(aVal)
    }
    return sortOrder.value === 'asc' ? aVal - bVal : bVal - aVal
  })
  
  return result
})

const domainList = computed(() => {
  const domains = [...new Set(accounts.value.map(a => a.domain))]
  return domains.sort()
})

const totalAccounts = computed(() => accounts.value.length)
const totalForwards = computed(() => forwards.value.length)

// Mail server settings
const mailSettings = computed(() => ({
  incoming: {
    imap: { host: 'mail.' + (domainList.value[0] || 'yourdomain.com'), port: 993, security: 'SSL/TLS' },
    pop3: { host: 'mail.' + (domainList.value[0] || 'yourdomain.com'), port: 995, security: 'SSL/TLS' },
  },
  outgoing: {
    smtp: { host: 'mail.' + (domainList.value[0] || 'yourdomain.com'), port: 465, security: 'SSL/TLS' },
    smtpAlt: { host: 'mail.' + (domainList.value[0] || 'yourdomain.com'), port: 587, security: 'STARTTLS' },
  }
}))

const fetchStatus = async () => {
  try {
    const response = await api.get('/mail/status')
    if (response.data.success) {
      status.value = response.data.data
    }
  } catch (e) {
    console.error(e)
  }
}

const fetchDomains = async () => {
  try {
    const response = await api.get('/mail/domains')
    if (response.data.success) {
      domains.value = response.data.data.domains || []
    }
  } catch (e) {
    console.error('Failed to load mail domains', e)
  }
}

const fetchAllAccounts = async () => {
  try {
    const response = await api.get('/mail/accounts')
    if (response.data.success) {
      accounts.value = response.data.data.accounts || []
    }
  } catch (e) {
    console.error('Failed to load accounts', e)
  }
}

const fetchAllForwards = async () => {
  try {
    const response = await api.get('/mail/forwards')
    if (response.data.success) {
      forwards.value = response.data.data.forwards || []
    }
  } catch (e) {
    console.error('Failed to load forwards', e)
  }
}

const createAccount = async () => {
  submitting.value = true
  try {
    const response = await api.post('/mail/accounts', {
      email: `${newAccount.value.email}@${newAccount.value.domain}`,
      password: newAccount.value.password
    })
    
    if (response.data.success) {
      toast.success('Account created')
      createAccountModal.value = false
      newAccount.value = { email: '', domain: '', password: '' }
      await fetchAllAccounts()
    } else {
      toast.error(response.data.error || 'Failed to create account')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create account')
  } finally {
    submitting.value = false
  }
}

const deleteItem = async () => {
  if (!deleteModal.value.item) return
  
  submitting.value = true
  try {
    let response
    if (deleteModal.value.type === 'account') {
      response = await api.delete(`/mail/accounts/${encodeURIComponent(deleteModal.value.item.email)}`)
    } else {
      response = await api.delete(`/mail/forwards/${encodeURIComponent(deleteModal.value.item.source)}`)
    }
    
    if (response.data.success) {
      toast.success(`${deleteModal.value.type === 'account' ? 'Account' : 'Forward'} deleted`)
      deleteModal.value = { show: false, type: null, item: null }
      await Promise.all([fetchAllAccounts(), fetchAllForwards()])
    } else {
      toast.error(response.data.error || 'Delete failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Delete failed')
  } finally {
    submitting.value = false
  }
}

const resetPassword = async () => {
  if (!resetPasswordModal.value.account || !newPassword.value) return
  
  submitting.value = true
  try {
    const response = await api.post(`/mail/accounts/${encodeURIComponent(resetPasswordModal.value.account.email)}/password`, {
      password: newPassword.value
    })
    
    if (response.data.success) {
      toast.success('Password reset successfully')
      resetPasswordModal.value = { show: false, account: null }
      newPassword.value = ''
    } else {
      toast.error(response.data.error || 'Failed to reset password')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to reset password')
  } finally {
    submitting.value = false
  }
}

const openCreateModal = () => {
  newAccount.value.domain = domainList.value[0] || ''
  createAccountModal.value = true
}

// DNS Records functions
const openDnsModal = async (domain) => {
  dnsModal.value = { show: true, domain }
  dnsRecords.value = null
  dnsLoading.value = true
  
  try {
    const response = await api.get(`/mail/domains/${encodeURIComponent(domain)}/dns`)
    if (response.data.success) {
      dnsRecords.value = response.data.data
    }
  } catch (e) {
    toast.error('Failed to load DNS records')
  } finally {
    dnsLoading.value = false
  }
}

const generateDkim = async () => {
  if (!dnsModal.value.domain) return
  
  dkimGenerating.value = true
  try {
    const response = await api.post(`/mail/domains/${encodeURIComponent(dnsModal.value.domain)}/dkim`)
    if (response.data.success) {
      toast.success('DKIM keys generated')
      // Refresh DNS records
      await openDnsModal(dnsModal.value.domain)
    } else {
      toast.error(response.data.error || 'Failed to generate DKIM')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to generate DKIM')
  } finally {
    dkimGenerating.value = false
  }
}

const setupDnsRecord = async (recordType) => {
  if (!dnsModal.value.domain) return
  
  setupRecordLoading.value = recordType
  try {
    const response = await api.post(`/mail/domains/${encodeURIComponent(dnsModal.value.domain)}/dns`, {
      record_type: recordType
    })
    if (response.data.success) {
      toast.success(`${recordType.toUpperCase()} record ${response.data.data.action}`)
      // Refresh DNS records
      await openDnsModal(dnsModal.value.domain)
    } else {
      toast.error(response.data.error || `Failed to setup ${recordType}`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to setup ${recordType}`)
  } finally {
    setupRecordLoading.value = null
  }
}

const copyToClipboard = async (text) => {
  try {
    await navigator.clipboard.writeText(text)
    toast.success('Copied to clipboard')
  } catch (e) {
    toast.error('Failed to copy')
  }
}

const generatePassword = () => {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*'
  let password = ''
  for (let i = 0; i < 16; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  if (createAccountModal.value) {
    newAccount.value.password = password
  } else {
    newPassword.value = password
  }
}

onMounted(async () => {
  await Promise.all([
    fetchStatus(), 
    fetchDomains(),
    fetchAllAccounts(),
    fetchAllForwards()
  ])
  loading.value = false
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Mail</h1>
        <p class="text-surface-500 text-sm mt-1">Manage email accounts and forwards</p>
      </div>
      <div class="flex gap-2">
        <button @click="showSettings = !showSettings" class="btn-secondary">
          <span class="material-symbols-rounded">settings</span>
          Settings
        </button>
        <button @click="openCreateModal" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          New Account
        </button>
      </div>
    </div>

    <!-- Mail Server Settings -->
    <div v-if="showSettings" class="card p-6 mb-6">
      <h3 class="font-semibold mb-4 flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500">dns</span>
        Mail Server Settings
      </h3>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Incoming -->
        <div>
          <h4 class="font-medium text-sm text-surface-500 mb-3">INCOMING MAIL</h4>
          <div class="space-y-3">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
              <div class="flex justify-between items-center mb-2">
                <span class="font-medium">IMAP</span>
                <span class="text-xs px-2 py-1 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600">Recommended</span>
              </div>
              <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                <p><span class="text-surface-400">Server:</span> mail.<em>yourdomain.com</em></p>
                <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">993</code></p>
                <p><span class="text-surface-400">Security:</span> SSL/TLS</p>
              </div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
              <div class="font-medium mb-2">POP3</div>
              <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                <p><span class="text-surface-400">Server:</span> mail.<em>yourdomain.com</em></p>
                <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">995</code></p>
                <p><span class="text-surface-400">Security:</span> SSL/TLS</p>
              </div>
            </div>
          </div>
        </div>
        <!-- Outgoing -->
        <div>
          <h4 class="font-medium text-sm text-surface-500 mb-3">OUTGOING MAIL (SMTP)</h4>
          <div class="space-y-3">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
              <div class="flex justify-between items-center mb-2">
                <span class="font-medium">SMTP (SSL)</span>
                <span class="text-xs px-2 py-1 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600">Recommended</span>
              </div>
              <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                <p><span class="text-surface-400">Server:</span> mail.<em>yourdomain.com</em></p>
                <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">465</code></p>
                <p><span class="text-surface-400">Security:</span> SSL/TLS</p>
              </div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
              <div class="font-medium mb-2">SMTP (STARTTLS)</div>
              <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                <p><span class="text-surface-400">Server:</span> mail.<em>yourdomain.com</em></p>
                <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">587</code></p>
                <p><span class="text-surface-400">Security:</span> STARTTLS</p>
              </div>
            </div>
          </div>
        </div>
      </div>
      <p class="text-xs text-surface-400 mt-4">
        <span class="material-symbols-rounded text-sm align-middle">info</span>
        Username is the full email address. Replace <em>yourdomain.com</em> with your actual domain.
      </p>
      
      <!-- Email Limits -->
      <div class="border-t border-surface-200 dark:border-surface-700 pt-4 mt-4">
        <h4 class="font-medium text-sm text-surface-500 mb-3">EMAIL LIMITS</h4>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
            <p class="text-xs text-surface-500 mb-1">Per Hour</p>
            <p class="font-semibold">100 emails</p>
          </div>
          <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
            <p class="text-xs text-surface-500 mb-1">Per Day</p>
            <p class="font-semibold">500 emails</p>
          </div>
          <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
            <p class="text-xs text-surface-500 mb-1">Attachment Size</p>
            <p class="font-semibold">25 MB</p>
          </div>
          <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
            <p class="text-xs text-surface-500 mb-1">Message Size</p>
            <p class="font-semibold">50 MB</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Status cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span :class="['status-dot', status?.postfix?.running ? 'running' : 'stopped']"></span>
          <span class="font-medium">Postfix (SMTP)</span>
        </div>
        <div class="mt-2">
          <StatusBadge :status="status?.postfix?.running ? 'running' : 'stopped'" />
        </div>
      </div>
      
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span :class="['status-dot', status?.dovecot?.running ? 'running' : 'stopped']"></span>
          <span class="font-medium">Dovecot (IMAP)</span>
        </div>
        <div class="mt-2">
          <StatusBadge :status="status?.dovecot?.running ? 'running' : 'stopped'" />
        </div>
      </div>
      
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-surface-400">group</span>
          <span class="font-medium">Accounts</span>
        </div>
        <div class="stat-value mt-2">{{ totalAccounts }}</div>
      </div>
      
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-surface-400">forward_to_inbox</span>
          <span class="font-medium">Forwards</span>
        </div>
        <div class="stat-value mt-2">{{ totalForwards }}</div>
      </div>
    </div>

    <!-- Domains with DNS Status -->
    <div class="card p-6 mb-6">
      <h3 class="font-semibold mb-4 flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500">domain</span>
        Mail Domains
      </h3>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div 
          v-for="domain in domains" 
          :key="domain.domain"
          class="p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl hover:shadow-md transition"
        >
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">dns</span>
              </div>
              <div>
                <p class="font-medium">{{ domain.domain }}</p>
                <p class="text-xs text-surface-500">{{ domain.accounts }} accounts</p>
              </div>
            </div>
            <button 
              @click="openDnsModal(domain.domain)"
              class="btn-secondary btn-sm"
              title="DNS Records"
            >
              <span class="material-symbols-rounded">shield</span>
              DNS
            </button>
          </div>
        </div>
        <div v-if="!domains.length" class="col-span-full text-center py-8 text-surface-400">
          No mail domains configured
        </div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="card p-4 mb-6">
      <div class="flex flex-wrap gap-4 items-center">
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input
            v-model="searchQuery"
            type="text"
            class="input pl-10"
            placeholder="Search accounts..."
          />
        </div>
        
        <select v-model="selectedDomain" class="input w-auto">
          <option value="all">All Domains ({{ domainList.length }})</option>
          <option v-for="domain in domainList" :key="domain" :value="domain">
            {{ domain }}
          </option>
        </select>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Accounts Table -->
    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th class="cursor-pointer select-none" @click="toggleSort('email')">
              <div class="flex items-center gap-1">
                Email
                <span v-if="sortColumn === 'email'" class="material-symbols-rounded text-sm">
                  {{ sortOrder === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
            </th>
            <th class="cursor-pointer select-none" @click="toggleSort('size')">
              <div class="flex items-center gap-1">
                Size
                <span v-if="sortColumn === 'size'" class="material-symbols-rounded text-sm">
                  {{ sortOrder === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
            </th>
            <th>Forwards To</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="account in filteredAccounts" :key="account.email">
            <td>
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
                  <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">person</span>
                </div>
                <div>
                  <p class="font-medium">{{ account.email }}</p>
                  <p class="text-xs text-surface-500">{{ account.domain }}</p>
                </div>
              </div>
            </td>
            <td>
              <span class="text-surface-600 dark:text-surface-400">{{ account.size_human }}</span>
            </td>
            <td>
              <div v-if="forwardsBySource[account.email]?.length" class="flex flex-wrap gap-1">
                <span 
                  v-for="dest in forwardsBySource[account.email]" 
                  :key="dest"
                  class="text-xs px-2 py-1 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
                >
                  {{ dest }}
                </span>
              </div>
              <span v-else class="text-surface-400">—</span>
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <button 
                  @click="resetPasswordModal = { show: true, account }"
                  class="btn-ghost btn-sm"
                  title="Reset Password"
                >
                  <span class="material-symbols-rounded">key</span>
                </button>
                <button 
                  @click="deleteModal = { show: true, type: 'account', item: account }"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Delete Account"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!filteredAccounts.length">
            <td colspan="4" class="py-12 text-center text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">mail</span>
              No accounts found
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create account modal -->
    <Modal :show="createAccountModal" title="Create Email Account" @close="createAccountModal = false">
      <form @submit.prevent="createAccount" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Domain</label>
          <select v-model="newAccount.domain" class="input" required>
            <option v-for="domain in domainList" :key="domain" :value="domain">
              {{ domain }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Username</label>
          <div class="flex">
            <input
              v-model="newAccount.email"
              type="text"
              class="input rounded-r-none"
              placeholder="username"
              required
            />
            <span class="px-3 flex items-center bg-surface-100 dark:bg-surface-800 border border-l-0 border-surface-200 dark:border-surface-700 rounded-r-xl text-surface-500">
              @{{ newAccount.domain }}
            </span>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Password</label>
          <div class="flex gap-2">
            <input
              v-model="newAccount.password"
              type="text"
              class="input font-mono flex-1"
              placeholder="Password"
              required
            />
            <button type="button" @click="generatePassword" class="btn-secondary">
              <span class="material-symbols-rounded">casino</span>
            </button>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="createAccountModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Create Account
          </button>
        </div>
      </form>
    </Modal>

    <!-- Reset password modal -->
    <Modal :show="resetPasswordModal.show" title="Reset Password" @close="resetPasswordModal = { show: false, account: null }">
      <form @submit.prevent="resetPassword" class="space-y-4">
        <p class="text-surface-600 dark:text-surface-400">
          Reset password for <strong>{{ resetPasswordModal.account?.email }}</strong>
        </p>

        <div>
          <label class="block text-sm font-medium mb-2">New Password</label>
          <div class="flex gap-2">
            <input
              v-model="newPassword"
              type="text"
              class="input font-mono flex-1"
              placeholder="New password"
              required
            />
            <button type="button" @click="generatePassword" class="btn-secondary">
              <span class="material-symbols-rounded">casino</span>
            </button>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="resetPasswordModal = { show: false, account: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !newPassword">
            <span v-if="submitting" class="spinner"></span>
            Reset Password
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete confirmation modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete Email Account"
      :message="`Are you sure you want to delete '${deleteModal.item?.email || deleteModal.item?.source}'? This will permanently remove all emails.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      require-confirmation="DELETE"
      @confirm="deleteItem"
      @cancel="deleteModal = { show: false, type: null, item: null }"
    />

    <!-- DNS Records Modal -->
    <Modal 
      :show="dnsModal.show" 
      :title="`Mail DNS Records - ${dnsModal.domain}`" 
      size="lg"
      @close="dnsModal = { show: false, domain: null }"
    >
      <div v-if="dnsLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>
      
      <div v-else-if="dnsRecords" class="space-y-4">
        <div class="bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30 rounded-xl p-4">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">info</span>
            <div class="text-sm text-blue-800 dark:text-blue-300">
              <p class="font-medium mb-1">Email Deliverability Records</p>
              <p>SPF, DKIM, and DMARC records help ensure your emails are delivered and not marked as spam.</p>
            </div>
          </div>
        </div>

        <!-- Record Cards -->
        <div class="space-y-3">
          <!-- MX Record -->
          <div class="bg-surface-50 dark:bg-surface-800/50 rounded-xl p-4">
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">mail</span>
                <span class="font-medium">MX Record</span>
              </div>
              <div class="flex items-center gap-2">
                <span 
                  v-if="dnsRecords.records.mx?.status?.exists"
                  class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600"
                >
                  Configured
                </span>
                <span 
                  v-else
                  class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600"
                >
                  Missing
                </span>
                <button 
                  @click="setupDnsRecord('mx')" 
                  class="btn-secondary btn-sm"
                  :disabled="setupRecordLoading === 'mx'"
                >
                  <span v-if="setupRecordLoading === 'mx'" class="spinner"></span>
                  <span class="material-symbols-rounded" v-else>add</span>
                </button>
              </div>
            </div>
            <p class="text-sm text-surface-500 mb-2">{{ dnsRecords.records.mx?.description }}</p>
            <div class="flex items-center gap-2 bg-surface-100 dark:bg-surface-700 rounded-lg p-2">
              <code class="text-xs flex-1 break-all">{{ dnsRecords.records.mx?.example }}</code>
              <button @click="copyToClipboard(dnsRecords.records.mx?.example)" class="btn-ghost btn-sm">
                <span class="material-symbols-rounded text-sm">content_copy</span>
              </button>
            </div>
          </div>

          <!-- SPF Record -->
          <div class="bg-surface-50 dark:bg-surface-800/50 rounded-xl p-4">
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-green-500">verified_user</span>
                <span class="font-medium">SPF Record</span>
              </div>
              <div class="flex items-center gap-2">
                <span 
                  v-if="dnsRecords.records.spf?.status?.exists"
                  class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600"
                >
                  Configured
                </span>
                <span 
                  v-else
                  class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600"
                >
                  Missing
                </span>
                <button 
                  @click="setupDnsRecord('spf')" 
                  class="btn-secondary btn-sm"
                  :disabled="setupRecordLoading === 'spf'"
                >
                  <span v-if="setupRecordLoading === 'spf'" class="spinner"></span>
                  <span class="material-symbols-rounded" v-else>add</span>
                </button>
              </div>
            </div>
            <p class="text-sm text-surface-500 mb-2">{{ dnsRecords.records.spf?.description }}</p>
            <div class="flex items-center gap-2 bg-surface-100 dark:bg-surface-700 rounded-lg p-2">
              <code class="text-xs flex-1 break-all">{{ dnsRecords.records.spf?.content }}</code>
              <button @click="copyToClipboard(dnsRecords.records.spf?.content)" class="btn-ghost btn-sm">
                <span class="material-symbols-rounded text-sm">content_copy</span>
              </button>
            </div>
          </div>

          <!-- DKIM Record -->
          <div class="bg-surface-50 dark:bg-surface-800/50 rounded-xl p-4">
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-purple-500">key</span>
                <span class="font-medium">DKIM Record</span>
              </div>
              <div class="flex items-center gap-2">
                <span 
                  v-if="dnsRecords.dkim_configured"
                  class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600"
                >
                  Generated
                </span>
                <span 
                  v-else
                  class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600"
                >
                  Not Generated
                </span>
                <button 
                  v-if="!dnsRecords.dkim_configured"
                  @click="generateDkim" 
                  class="btn-primary btn-sm"
                  :disabled="dkimGenerating"
                >
                  <span v-if="dkimGenerating" class="spinner"></span>
                  Generate DKIM
                </button>
                <button 
                  v-else
                  @click="setupDnsRecord('dkim')" 
                  class="btn-secondary btn-sm"
                  :disabled="setupRecordLoading === 'dkim'"
                >
                  <span v-if="setupRecordLoading === 'dkim'" class="spinner"></span>
                  <span class="material-symbols-rounded" v-else>add</span>
                </button>
              </div>
            </div>
            <p class="text-sm text-surface-500 mb-2">{{ dnsRecords.records.dkim?.description }}</p>
            <div v-if="dnsRecords.dkim_configured">
              <p class="text-xs text-surface-400 mb-1">DNS Name: <code>{{ dnsRecords.records.dkim?.name }}</code></p>
              <div class="flex items-center gap-2 bg-surface-100 dark:bg-surface-700 rounded-lg p-2">
                <code class="text-xs flex-1 break-all">{{ dnsRecords.records.dkim?.content || dnsRecords.records.dkim?.example }}</code>
                <button @click="copyToClipboard(dnsRecords.records.dkim?.content || dnsRecords.records.dkim?.example)" class="btn-ghost btn-sm">
                  <span class="material-symbols-rounded text-sm">content_copy</span>
                </button>
              </div>
            </div>
            <div v-else class="text-sm text-surface-400 italic">
              Click "Generate DKIM" to create signing keys
            </div>
          </div>

          <!-- DMARC Record -->
          <div class="bg-surface-50 dark:bg-surface-800/50 rounded-xl p-4">
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-orange-500">policy</span>
                <span class="font-medium">DMARC Record</span>
              </div>
              <div class="flex items-center gap-2">
                <span 
                  v-if="dnsRecords.records.dmarc?.status?.exists"
                  class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600"
                >
                  Configured
                </span>
                <span 
                  v-else
                  class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600"
                >
                  Missing
                </span>
                <button 
                  @click="setupDnsRecord('dmarc')" 
                  class="btn-secondary btn-sm"
                  :disabled="setupRecordLoading === 'dmarc'"
                >
                  <span v-if="setupRecordLoading === 'dmarc'" class="spinner"></span>
                  <span class="material-symbols-rounded" v-else>add</span>
                </button>
              </div>
            </div>
            <p class="text-sm text-surface-500 mb-2">{{ dnsRecords.records.dmarc?.description }}</p>
            <p class="text-xs text-surface-400 mb-1">DNS Name: <code>{{ dnsRecords.records.dmarc?.name }}</code></p>
            <div class="flex items-center gap-2 bg-surface-100 dark:bg-surface-700 rounded-lg p-2">
              <code class="text-xs flex-1 break-all">{{ dnsRecords.records.dmarc?.content }}</code>
              <button @click="copyToClipboard(dnsRecords.records.dmarc?.content)" class="btn-ghost btn-sm">
                <span class="material-symbols-rounded text-sm">content_copy</span>
              </button>
            </div>
          </div>
        </div>

        <div class="border-t border-surface-200 dark:border-surface-700 pt-4">
          <p class="text-sm text-surface-500">
            <span class="material-symbols-rounded text-sm align-middle">help</span>
            Click the + button next to each record to automatically add it to your DNS zone.
            For external DNS providers, copy the values and add them manually.
          </p>
        </div>
      </div>

      <div v-else class="text-center py-8 text-surface-400">
        Failed to load DNS records
      </div>
    </Modal>
  </div>
</template>
