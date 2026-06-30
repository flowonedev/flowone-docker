<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const router = useRouter()
const toast = useToastStore()

const step = ref(1)
const loading = ref(false)
const testingConnection = ref(false)
const connectionTested = ref(false)
const blueprints = ref([])

const form = ref({
  name: '',
  domain: '', // Base domain like "weddingcards.hu"
  is_local: false, // Always remote - Fleet Manager lives on the main server
  ip_address: '',
  ssh_port: 22,
  ssh_user: 'root',
  auth_method: 'password', // 'key' or 'password'
  ssh_password: '',
  key_path: '/root/.ssh/id_rsa',
  key_passphrase: '',
  panel_domain: '',
  email_domain: '',
  mail_domain: '',
  blueprint_id: null,
  admin_email: 'robert@pixelranger.hu',
  cpguard_license_key: '',
  notes: ''
})

const fetchBlueprints = async () => {
  try {
    const response = await api.get('/api/blueprints')
    blueprints.value = response.data
  } catch (error) {
    console.error('Failed to load blueprints', error)
  }
}

const testConnection = async () => {
  if (!form.value.ip_address) {
    toast.error('Please enter IP address')
    return
  }
  if (!form.value.ssh_user) {
    toast.error('Please enter SSH user')
    return
  }
  if (form.value.auth_method === 'password' && !form.value.ssh_password) {
    toast.error('Please enter SSH password')
    return
  }
  if (form.value.auth_method === 'key' && !form.value.key_path) {
    toast.error('Please enter SSH key path')
    return
  }

  testingConnection.value = true
  connectionTested.value = false

  try {
    const payload = {
      is_local: false,
      ip_address: form.value.ip_address,
      ssh_port: form.value.ssh_port,
      ssh_user: form.value.ssh_user,
      auth_method: form.value.auth_method,
    }

    if (form.value.auth_method === 'key') {
      payload.key_path = form.value.key_path
      payload.key_passphrase = form.value.key_passphrase
    } else {
      payload.ssh_password = form.value.ssh_password
    }

    const response = await api.post('/api/blueprints/test-connection', payload)
    connectionTested.value = true
    toast.success(response.message || 'Connection successful')
  } catch (error) {
    toast.error('Connection failed: ' + (error.message || 'Unknown error'))
  } finally {
    testingConnection.value = false
  }
}

const submitForm = async () => {
  loading.value = true

  try {
    // Set mail_domain to email_domain if not specified
    if (!form.value.mail_domain) {
      form.value.mail_domain = form.value.email_domain
    }

    // Build payload - only include key_path for key auth, only password for password auth
    const payload = { ...form.value }
    if (payload.auth_method === 'password') {
      delete payload.key_path
      delete payload.key_passphrase
    } else {
      delete payload.ssh_password
    }

    const response = await api.post('/api/servers', payload)
    toast.success('Server created successfully')
    router.push(`/servers/${response.data.id}`)
  } catch (error) {
    toast.error(error.message || 'Failed to create server')
  } finally {
    loading.value = false
  }
}

const nextStep = () => {
  if (step.value === 1) {
    if (!form.value.name || !form.value.domain) {
      toast.error('Please fill in server name and domain')
      return
    }
    // Auto-fill panel and email domains based on base domain
    form.value.panel_domain = `panel.${form.value.domain}`
    form.value.email_domain = `email.${form.value.domain}`
    // mail_domain is the base domain for email addresses (user@domain.com)
    form.value.mail_domain = form.value.domain
  } else if (step.value === 2) {
    if (!form.value.panel_domain || !form.value.email_domain) {
      toast.error('Please enter both panel and email domains')
      return
    }
  }
  step.value++
}

const prevStep = () => {
  step.value--
}

onMounted(fetchBlueprints)
</script>

<template>
  <div class="animate-fadeIn max-w-2xl mx-auto">
    <div class="flex items-center gap-4 mb-6">
      <button @click="router.push('/servers')" class="btn btn-ghost btn-sm">
        <span class="material-symbols-rounded">arrow_back</span>
      </button>
      <h1 class="text-2xl font-bold">Add New Server</h1>
    </div>

    <!-- Progress steps -->
    <div class="flex items-center justify-center gap-2 mb-8">
      <div v-for="s in 4" :key="s" class="flex items-center">
        <div :class="[
          'w-10 h-10 rounded-full flex items-center justify-center font-medium transition-colors',
          step >= s ? 'bg-primary-500 text-white' : 'bg-surface-300 dark:bg-surface-700 text-surface-500 dark:text-surface-400'
        ]">
          {{ s }}
        </div>
        <div v-if="s < 4" :class="[
          'w-12 h-1 mx-1',
          step > s ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-700'
        ]"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <!-- Step 1: Connection -->
        <div v-if="step === 1" class="space-y-4">
          <h2 class="text-lg font-semibold mb-4 text-surface-900 dark:text-surface-100">Server Connection</h2>
          
          <div>
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Server Name *</label>
            <input v-model="form.name" type="text" class="input w-full" placeholder="e.g., Client ABC Production" />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Base Domain *</label>
            <input v-model="form.domain" type="text" class="input w-full" placeholder="e.g., weddingcards.hu" />
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">The main domain for this server. Panel and email subdomains will be auto-generated.</p>
          </div>

          <!-- SSH Connection -->
          <div class="grid grid-cols-3 gap-4">
            <div class="col-span-2">
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">IP Address *</label>
              <input v-model="form.ip_address" type="text" class="input w-full" placeholder="e.g., 192.168.1.100" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">SSH Port</label>
              <input v-model="form.ssh_port" type="number" class="input w-full" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">SSH User *</label>
              <input v-model="form.ssh_user" type="text" class="input w-full" placeholder="e.g., root" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Auth Method</label>
              <div class="flex rounded-xl overflow-hidden border border-surface-300 dark:border-surface-600 h-[42px]">
                <button 
                  type="button"
                  @click="form.auth_method = 'key'"
                  :class="[
                    'flex-1 flex items-center justify-center gap-2 px-4 text-sm font-medium transition-all',
                    form.auth_method === 'key' 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">key</span>
                  SSH Key
                </button>
                <button 
                  type="button"
                  @click="form.auth_method = 'password'"
                  :class="[
                    'flex-1 flex items-center justify-center gap-2 px-4 text-sm font-medium transition-all',
                    form.auth_method === 'password' 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">password</span>
                  Password
                </button>
              </div>
            </div>
          </div>

          <!-- Key-based auth fields -->
          <div v-if="form.auth_method === 'key'" class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Key Path *</label>
              <input v-model="form.key_path" type="text" class="input w-full" placeholder="/root/.ssh/id_rsa" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Key Passphrase</label>
              <input v-model="form.key_passphrase" type="password" class="input w-full" placeholder="Leave empty if none" />
            </div>
          </div>

          <!-- Password auth fields -->
          <div v-if="form.auth_method === 'password'">
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">SSH Password *</label>
            <input v-model="form.ssh_password" type="password" class="input w-full" placeholder="Enter password" />
          </div>

          <button 
            @click="testConnection" 
            :disabled="testingConnection"
            class="btn btn-secondary w-full"
          >
            <span v-if="testingConnection" class="spinner w-5 h-5"></span>
            <span v-else class="material-symbols-rounded">wifi_tethering</span>
            {{ testingConnection ? 'Testing...' : 'Test Connection' }}
          </button>

          <div v-if="connectionTested" class="p-4 rounded-xl bg-green-500/10 border border-green-500/30">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-green-500 text-2xl">check_circle</span>
              <div>
                <p class="font-medium text-green-600 dark:text-green-400">Connection Successful</p>
                <p class="text-sm text-surface-600 dark:text-surface-400">Server is reachable via SSH.</p>
              </div>
            </div>
          </div>

          <div v-if="connectionTested" class="flex items-center gap-2 text-green-600 dark:text-green-400">
            <span class="material-symbols-rounded">check_circle</span>
            <span>Connection successful</span>
          </div>
        </div>

        <!-- Step 2: Domains -->
        <div v-if="step === 2" class="space-y-4">
          <h2 class="text-lg font-semibold mb-4 text-surface-900 dark:text-surface-100">Domain Configuration</h2>
          
          <div class="p-4 rounded-xl bg-primary-500/10 border border-primary-500/30 mb-4">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">info</span>
              <p class="text-sm text-surface-600 dark:text-surface-400">Auto-filled based on domain: <span class="font-medium text-primary-600 dark:text-primary-400">{{ form.domain }}</span></p>
            </div>
          </div>
          
          <div>
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Panel Domain *</label>
            <input v-model="form.panel_domain" type="text" class="input w-full" placeholder="e.g., panel.example.com" />
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">The domain where VPS Admin Panel will be accessible</p>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Email App Domain *</label>
            <input v-model="form.email_domain" type="text" class="input w-full" placeholder="e.g., email.example.com" />
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">The domain where MailFlow will be accessible</p>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Admin Email</label>
            <input v-model="form.admin_email" type="email" class="input w-full" placeholder="e.g., admin@example.com" />
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">Email address for the panel admin account</p>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">CPGuard License Key <span class="text-surface-400 font-normal">(optional)</span></label>
            <input v-model="form.cpguard_license_key" type="text" class="input w-full" placeholder="Leave empty to skip CPGuard installation" autocomplete="off" />
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">
              CPGuard licenses are bound to the server IP - buy one for this server's IP and paste it here.
              If provided, CPGuard is installed automatically during provisioning. You can also add it later from the server page.
            </p>
          </div>
        </div>

        <!-- Step 3: Blueprint -->
        <div v-if="step === 3" class="space-y-4">
          <h2 class="text-lg font-semibold mb-4 text-surface-900 dark:text-surface-100">Select Blueprint</h2>
          
          <div v-if="blueprints.length === 0" class="text-center py-8 text-surface-500 dark:text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2">inventory_2</span>
            <p>No blueprints available</p>
            <p class="text-sm mt-2">Create a blueprint first to enable automated deployment</p>
          </div>

          <div v-else class="space-y-3">
            <label
              v-for="bp in blueprints"
              :key="bp.id"
              :class="[
                'block p-4 border rounded-lg cursor-pointer transition-colors',
                form.blueprint_id === bp.id ? 'border-primary-500 bg-primary-500/10' : 'border-surface-200 dark:border-surface-700 hover:border-surface-400 dark:hover:border-surface-500'
              ]"
            >
              <input
                type="radio"
                :value="bp.id"
                v-model="form.blueprint_id"
                class="hidden"
              />
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">{{ bp.name }}</p>
                  <p class="text-sm text-surface-500 dark:text-surface-400">{{ bp.description || 'No description' }}</p>
                </div>
                <span v-if="bp.is_default" class="badge badge-info">Default</span>
              </div>
            </label>
          </div>
        </div>

        <!-- Step 4: Review -->
        <div v-if="step === 4" class="space-y-4">
          <h2 class="text-lg font-semibold mb-4 text-surface-900 dark:text-surface-100">Review & Create</h2>
          
          <div class="bg-surface-100 dark:bg-surface-700 rounded-lg p-4 space-y-3">
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">Server Name</span>
              <span class="text-surface-900 dark:text-surface-100">{{ form.name }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">Base Domain</span>
              <span class="text-surface-900 dark:text-surface-100">{{ form.domain }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">IP Address</span>
              <span class="text-surface-900 dark:text-surface-100">{{ form.ip_address }}:{{ form.ssh_port }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">Panel Domain</span>
              <span class="text-surface-900 dark:text-surface-100">{{ form.panel_domain }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">Email Domain</span>
              <span class="text-surface-900 dark:text-surface-100">{{ form.email_domain }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">Admin Email</span>
              <span class="text-surface-900 dark:text-surface-100">{{ form.admin_email }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">Blueprint</span>
              <span class="text-surface-900 dark:text-surface-100">{{ blueprints.find(b => b.id === form.blueprint_id)?.name || 'None' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500 dark:text-surface-400">CPGuard</span>
              <span class="text-surface-900 dark:text-surface-100">{{ form.cpguard_license_key ? 'License key provided - will install' : 'Skipped (no license key)' }}</span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Notes</label>
            <textarea v-model="form.notes" class="input w-full" rows="3" placeholder="Optional notes about this server..."></textarea>
          </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-6 pt-6 border-t border-surface-200 dark:border-surface-700">
          <button
            v-if="step > 1"
            @click="prevStep"
            class="btn btn-ghost"
          >
            <span class="material-symbols-rounded">arrow_back</span>
            Back
          </button>
          <div v-else></div>

          <button
            v-if="step < 4"
            @click="nextStep"
            class="btn btn-primary"
          >
            Next
            <span class="material-symbols-rounded">arrow_forward</span>
          </button>
          <button
            v-else
            @click="submitForm"
            :disabled="loading"
            class="btn btn-primary"
          >
            <span v-if="loading" class="spinner w-5 h-5"></span>
            <span v-else class="material-symbols-rounded">add</span>
            Create Server
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

