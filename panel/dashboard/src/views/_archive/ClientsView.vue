<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const router = useRouter()
const toast = useToastStore()

const loading = ref(true)
const clients = ref([])
const sites = ref([])
const createModal = ref(false)
const deleteModal = ref({ show: false, client: null })
const submitting = ref(false)

// Filters
const searchQuery = ref('')
const filterStatus = ref('all')

const newClient = ref({
  name: '',
  email: '',
  phone: '',
  company: '',
  address: '',
  notes: '',
  status: 'active',
  domains: []
})

const filteredClients = computed(() => {
  let result = [...clients.value]
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(client => 
      client.name.toLowerCase().includes(query) ||
      client.email.toLowerCase().includes(query) ||
      (client.company && client.company.toLowerCase().includes(query))
    )
  }
  
  if (filterStatus.value !== 'all') {
    result = result.filter(client => client.status === filterStatus.value)
  }
  
  return result
})

const stats = computed(() => ({
  total: clients.value.length,
  active: clients.value.filter(c => c.status === 'active').length,
  withSubscriptions: clients.value.filter(c => c.active_subscriptions > 0).length
}))

const fetchClients = async () => {
  try {
    const response = await api.get('/clients')
    if (response.data.success) {
      clients.value = response.data.data.clients || []
    }
  } catch (e) {
    toast.error('Failed to load clients')
  } finally {
    loading.value = false
  }
}

const fetchSites = async () => {
  try {
    const response = await api.get('/sites')
    if (response.data.success) {
      // Filter out mail.* domains
      sites.value = (response.data.data.vhosts || []).filter(
        site => !site.domain.startsWith('mail.')
      )
    }
  } catch (e) {
    // Silently fail
  }
}

const createClient = async () => {
  submitting.value = true
  try {
    const response = await api.post('/clients', newClient.value)
    if (response.data.success) {
      toast.success('Client created successfully')
      createModal.value = false
      resetNewClient()
      await fetchClients()
    } else {
      toast.error(response.data.error || 'Failed to create client')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create client')
  } finally {
    submitting.value = false
  }
}

const deleteClient = async () => {
  if (!deleteModal.value.client) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/clients/${deleteModal.value.client.id}`)
    if (response.data.success) {
      toast.success('Client deleted successfully')
      deleteModal.value = { show: false, client: null }
      await fetchClients()
    } else {
      toast.error(response.data.error || 'Failed to delete client')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete client')
  } finally {
    submitting.value = false
  }
}

const toggleDomain = (domain) => {
  const index = newClient.value.domains.indexOf(domain)
  if (index > -1) {
    newClient.value.domains.splice(index, 1)
  } else {
    newClient.value.domains.push(domain)
  }
}

const resetNewClient = () => {
  newClient.value = {
    name: '',
    email: '',
    phone: '',
    company: '',
    address: '',
    notes: '',
    status: 'active',
    domains: []
  }
}

onMounted(() => {
  fetchClients()
  fetchSites()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Clients</h1>
        <p class="text-surface-500 text-sm mt-1">Manage hosting clients</p>
      </div>
      <button @click="createModal = true" class="btn-primary">
        <span class="material-symbols-rounded">person_add</span>
        New Client
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-4 mb-6">
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Total Clients</p>
        <p class="stat-value">{{ stats.total }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Active</p>
        <p class="stat-value text-green-600">{{ stats.active }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">With Subscriptions</p>
        <p class="stat-value text-primary-600">{{ stats.withSubscriptions }}</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="card p-4 mb-6">
      <div class="flex flex-wrap gap-4 items-center">
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input
            v-model="searchQuery"
            type="text"
            class="input pl-10"
            placeholder="Search by name, email or company..."
          />
        </div>
        
        <select v-model="filterStatus" class="input w-auto">
          <option value="all">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Clients table -->
    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th>Client</th>
            <th>Contact</th>
            <th>Domains</th>
            <th>Subscriptions</th>
            <th>Status</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="client in filteredClients" :key="client.id">
            <td>
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                  <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">person</span>
                </div>
                <div>
                  <button 
                    @click="router.push(`/clients/${client.id}`)"
                    class="font-medium hover:text-primary-500 transition-colors text-left"
                  >
                    {{ client.name }}
                  </button>
                  <p v-if="client.company" class="text-sm text-surface-500">{{ client.company }}</p>
                </div>
              </div>
            </td>
            <td>
              <div class="text-sm">
                <p>{{ client.email }}</p>
                <p v-if="client.phone" class="text-surface-500">{{ client.phone }}</p>
              </div>
            </td>
            <td>
              <span class="text-sm">{{ client.domain_count || 0 }} domain(s)</span>
            </td>
            <td>
              <span 
                :class="[
                  'badge',
                  client.active_subscriptions > 0 ? 'badge-success' : 'badge-warning'
                ]"
              >
                {{ client.active_subscriptions || 0 }} active
              </span>
            </td>
            <td>
              <StatusBadge :status="client.status" />
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <button 
                  @click="router.push(`/clients/${client.id}`)"
                  class="btn-ghost btn-sm text-primary-500"
                  title="View details"
                >
                  <span class="material-symbols-rounded">visibility</span>
                </button>
                <button 
                  @click="deleteModal = { show: true, client }"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Delete client"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!filteredClients.length">
            <td colspan="6" class="py-12 text-center text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">group</span>
              No clients found
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <Modal :show="createModal" title="Add New Client" @close="createModal = false">
      <form @submit.prevent="createClient" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Name *</label>
            <input
              v-model="newClient.name"
              type="text"
              class="input"
              placeholder="John Doe"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Email *</label>
            <input
              v-model="newClient.email"
              type="email"
              class="input"
              placeholder="john@example.com"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Phone</label>
            <input
              v-model="newClient.phone"
              type="text"
              class="input"
              placeholder="+36 30 123 4567"
            />
          </div>

          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Company</label>
            <input
              v-model="newClient.company"
              type="text"
              class="input"
              placeholder="Company Ltd."
            />
          </div>

          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Address</label>
            <textarea
              v-model="newClient.address"
              class="input"
              rows="2"
              placeholder="Full address"
            />
          </div>

          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Notes</label>
            <textarea
              v-model="newClient.notes"
              class="input"
              rows="2"
              placeholder="Internal notes..."
            />
          </div>
        </div>

        <!-- Domain assignment -->
        <div v-if="sites.length > 0">
          <label class="block text-sm font-medium mb-2">Assign Domains</label>
          <div class="max-h-[200px] overflow-y-auto space-y-2 border border-surface-200 dark:border-surface-700 rounded-xl p-3">
            <label
              v-for="site in sites"
              :key="site.domain"
              class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 cursor-pointer"
            >
              <input
                type="checkbox"
                :checked="newClient.domains.includes(site.domain)"
                @change="toggleDomain(site.domain)"
                class="rounded border-surface-300"
              />
              <span>{{ site.domain }}</span>
            </label>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="createModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Add Client
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete Client"
      :message="`Are you sure you want to delete '${deleteModal.client?.name}'? This will also delete all their subscriptions and payment history.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deleteClient"
      @cancel="deleteModal = { show: false, client: null }"
    />
  </div>
</template>

