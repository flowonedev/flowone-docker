<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const toast = useToastStore()

// Active tab
const activeTab = ref('wordpress')

// Data
const templates = ref([])
const installedApps = ref([])
const sites = ref([])
const loading = ref(true)
const installing = ref(false)

// Docker data
const dockerStatus = ref(null)
const containers = ref([])
const dockerLoading = ref(false)
const dockerInstalling = ref(false)

// WordPress summary data per site
const wpSummaries = ref({})
const wpSummaryLoading = ref({})

// Modals
const showInstallModal = ref(false)
const showUninstallModal = ref(false)
const selectedTemplate = ref(null)
const selectedApp = ref(null)

// Install form
const installForm = ref({
  domain: '',
  app_slug: '',
  admin_email: '',
  admin_user: 'admin',
  admin_password: '',
  site_title: '',
  db_name: '',
})

// Site databases for install
const siteDatabases = ref([])
const siteDbLoading = ref(false)

// Filters
const filterSite = ref('')
const searchQuery = ref('')

// Computed
const filteredApps = computed(() => {
  let apps = installedApps.value
  
  if (filterSite.value) {
    apps = apps.filter(app => app.domain === filterSite.value)
  }
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    apps = apps.filter(app => 
      app.domain.toLowerCase().includes(query) ||
      app.app_name?.toLowerCase().includes(query) ||
      app.app_slug?.toLowerCase().includes(query)
    )
  }
  
  return apps
})

const uniqueSites = computed(() => {
  const domains = [...new Set(installedApps.value.map(app => app.domain))]
  return domains.sort()
})

// Methods
async function fetchData() {
  loading.value = true
  try {
    const [templatesRes, appsRes, sitesRes] = await Promise.all([
      api.get('/apps/templates'),
      api.get('/apps'),
      api.get('/sites'),
    ])
    
    templates.value = templatesRes.data?.data?.templates || []
    installedApps.value = appsRes.data?.data?.applications || []
    sites.value = sitesRes.data?.data?.vhosts || []
    
    // Fetch WP summaries for WordPress apps
    const wpApps = installedApps.value.filter(app => app.app_slug === 'wordpress')
    for (const app of wpApps.slice(0, 10)) { // Limit to first 10
      fetchWpSummary(app.domain)
    }
  } catch (error) {
    toast.error('Failed to load data: ' + (error.response?.data?.error || error.message))
  } finally {
    loading.value = false
  }
}

// Fetch WordPress summary for a domain
async function fetchWpSummary(domain) {
  if (wpSummaries.value[domain] || wpSummaryLoading.value[domain]) return
  
  wpSummaryLoading.value[domain] = true
  try {
    const response = await api.get(`/wordpress/${domain}`)
    if (response.data.success && response.data.data?.installed !== false) {
      wpSummaries.value[domain] = response.data.data
    } else {
      wpSummaries.value[domain] = null
    }
  } catch (e) {
    // WordPress not accessible or not installed
    wpSummaries.value[domain] = null
  } finally {
    wpSummaryLoading.value[domain] = false
  }
}

// Docker methods
async function fetchDockerStatus() {
  dockerLoading.value = true
  try {
    const response = await api.get('/docker/status')
    if (response.data.success) {
      dockerStatus.value = response.data.data
      
      // If Docker is running, fetch containers
      if (dockerStatus.value.running) {
        await fetchContainers()
      }
    }
  } catch (e) {
    dockerStatus.value = { installed: false, running: false }
  } finally {
    dockerLoading.value = false
  }
}

async function fetchContainers() {
  try {
    const response = await api.get('/docker/containers')
    if (response.data.success) {
      containers.value = response.data.data.containers || []
    }
  } catch (e) {
    console.error('Failed to fetch containers', e)
  }
}

async function installDocker() {
  dockerInstalling.value = true
  try {
    const response = await api.post('/docker/install', {
      include_compose: true
    })
    if (response.data.success) {
      toast.success('Docker installed successfully')
      await fetchDockerStatus()
    } else {
      toast.error(response.data.error || 'Failed to install Docker')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to install Docker')
  } finally {
    dockerInstalling.value = false
  }
}

async function restartContainer(id) {
  try {
    const response = await api.post(`/docker/containers/${id}/restart`)
    if (response.data.success) {
      toast.success('Container restarted')
      await fetchContainers()
    } else {
      toast.error(response.data.error || 'Failed to restart container')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart container')
  }
}

async function stopContainer(id) {
  try {
    const response = await api.post(`/docker/containers/${id}/stop`)
    if (response.data.success) {
      toast.success('Container stopped')
      await fetchContainers()
    } else {
      toast.error(response.data.error || 'Failed to stop container')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to stop container')
  }
}

async function startContainer(id) {
  try {
    const response = await api.post(`/docker/containers/${id}/start`)
    if (response.data.success) {
      toast.success('Container started')
      await fetchContainers()
    } else {
      toast.error(response.data.error || 'Failed to start container')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start container')
  }
}

function openInstallModal(template) {
  selectedTemplate.value = template
  installForm.value = {
    domain: '',
    app_slug: template.slug,
    admin_email: '',
    admin_user: 'admin',
    admin_password: generatePassword(),
    site_title: '',
    db_name: '',
  }
  showInstallModal.value = true
}

// Generate database name from domain
function generateDbName(domain) {
  if (!domain) return ''
  return domain.replace(/[^a-z0-9]/gi, '_').substring(0, 16) + '_wp'
}

// Watch domain changes to auto-generate db name and fetch existing databases
async function onDomainChange() {
  const domain = installForm.value.domain
  
  // Reset database state
  siteDatabases.value = []
  
  if (!domain) {
    installForm.value.db_name = ''
    return
  }
  
  // Fetch databases for this site
  siteDbLoading.value = true
  try {
    const response = await api.get(`/sites/${domain}/databases`)
    if (response.data.success && response.data.data?.databases?.length > 0) {
      siteDatabases.value = response.data.data.databases
      // Auto-select first database if available
      installForm.value.db_name = siteDatabases.value[0].name
    } else {
      // No databases found, generate new name
      installForm.value.db_name = generateDbName(domain)
    }
  } catch (e) {
    // Failed to fetch databases, generate new name
    console.error('Failed to fetch site databases', e)
    installForm.value.db_name = generateDbName(domain)
  } finally {
    siteDbLoading.value = false
  }
  
  installForm.value._lastDomain = domain
}

function closeInstallModal() {
  showInstallModal.value = false
  selectedTemplate.value = null
}

async function installApp() {
  if (!installForm.value.domain) {
    toast.error('Please select a site')
    return
  }
  
  if (!installForm.value.admin_email) {
    toast.error('Admin email is required')
    return
  }
  
  // Validate database name
  if (!installForm.value.db_name) {
    installForm.value.db_name = generateDbName(installForm.value.domain)
  }
  
  installing.value = true
  try {
    const payload = {
      domain: installForm.value.domain,
      app_slug: installForm.value.app_slug,
      admin_email: installForm.value.admin_email,
      admin_user: installForm.value.admin_user || 'admin',
      admin_password: installForm.value.admin_password,
      site_title: installForm.value.site_title || installForm.value.domain,
      db_name: installForm.value.db_name,
    }
    
    console.log('Installing app with payload:', payload)
    
    const response = await api.post('/apps/install', payload)
    
    if (response.data?.success) {
      toast.success(response.data.message || 'Application installed successfully')
      closeInstallModal()
      await fetchData()
      
      // Show credentials if returned
      if (response.data?.data?.admin_url) {
        toast.success(`Admin URL: ${response.data.data.admin_url}`, { duration: 10000 })
      }
    } else {
      toast.error(response.data?.error || 'Installation failed')
      console.error('Install failed:', response.data)
    }
  } catch (error) {
    const errorMsg = error.response?.data?.error || error.message || 'Installation failed'
    toast.error(errorMsg)
    console.error('Install error:', error.response?.data || error)
  } finally {
    installing.value = false
  }
}

function openUninstallModal(app) {
  selectedApp.value = app
  showUninstallModal.value = true
}

async function confirmUninstall() {
  if (!selectedApp.value) return
  
  try {
    await api.delete(`/apps/${selectedApp.value.id}`, {
      data: { keep_files: false, keep_database: false }
    })
    
    toast.success('Application uninstalled successfully')
    showUninstallModal.value = false
    selectedApp.value = null
    await fetchData()
  } catch (error) {
    toast.error('Failed to uninstall: ' + (error.response?.data?.error || error.message))
  }
}

function generatePassword() {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'
  let password = ''
  for (let i = 0; i < 16; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  return password
}

function regeneratePassword() {
  installForm.value.admin_password = generatePassword()
}

// Safe clipboard function with fallback for non-HTTPS contexts
async function copyToClipboard(text, successMessage = 'Copied to clipboard') {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
    } else {
      // Fallback for non-HTTPS or older browsers
      const textArea = document.createElement('textarea')
      textArea.value = text
      textArea.style.position = 'fixed'
      textArea.style.left = '-9999px'
      document.body.appendChild(textArea)
      textArea.select()
      document.execCommand('copy')
      document.body.removeChild(textArea)
    }
    toast.success(successMessage)
  } catch (e) {
    toast.error('Failed to copy to clipboard')
  }
}

function getAppIcon(template) {
  const iconMap = {
    wordpress: 'edit_note',
    laravel: 'code',
    joomla: 'article',
    drupal: 'hub',
    prestashop: 'shopping_cart',
  }
  return iconMap[template.slug] || template.icon || 'apps'
}

function formatDate(date) {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

onMounted(() => {
  fetchData()
  fetchDockerStatus()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Applications</h1>
        <p class="text-surface-500 dark:text-surface-400 mt-1">
          Install and manage web applications on your sites
        </p>
      </div>
      <button 
        @click="fetchData(); fetchDockerStatus()"
        class="btn btn-secondary"
        :disabled="loading"
      >
        <span class="material-symbols-rounded text-lg">refresh</span>
        <span class="hidden sm:inline">Refresh</span>
      </button>
    </div>

    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700">
      <nav class="tab-nav">
        <button
          @click="activeTab = 'wordpress'"
          :class="[
            'tab-btn',
            activeTab === 'wordpress' ? 'active' : ''
          ]"
        >
          <span class="material-symbols-rounded text-lg">edit_note</span>
          <span class="tab-label">WordPress</span>
        </button>
        <button
          @click="activeTab = 'docker'"
          :class="[
            'tab-btn',
            activeTab === 'docker' ? 'active' : ''
          ]"
        >
          <span class="material-symbols-rounded text-lg">deployed_code</span>
          <span class="tab-label">Docker</span>
          <span v-if="dockerStatus?.running" class="w-2 h-2 rounded-full bg-green-500"></span>
        </button>
      </nav>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <template v-else-if="activeTab === 'wordpress'">
      <!-- Application Templates -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-medium flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">add_circle</span>
            Install New Application
          </h2>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <div
              v-for="template in templates"
              :key="template.slug"
              class="group relative bg-surface-50 dark:bg-surface-800/50 rounded-xl p-5 border border-surface-200 dark:border-surface-700 hover:border-primary-500 dark:hover:border-primary-500 transition-all cursor-pointer"
              @click="openInstallModal(template)"
            >
              <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-2xl text-primary-600 dark:text-primary-400">
                    {{ getAppIcon(template) }}
                  </span>
                </div>
                <div class="flex-1 min-w-0">
                  <h3 class="font-semibold text-surface-900 dark:text-surface-100 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                    {{ template.name }}
                  </h3>
                  <p class="text-sm text-surface-500 dark:text-surface-400 mt-1 line-clamp-2">
                    {{ template.description }}
                  </p>
                  <div class="flex items-center gap-2 mt-3">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-300">
                      {{ template.category }}
                    </span>
                    <span 
                      v-if="template.requirements_met?.met"
                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400"
                    >
                      Ready
                    </span>
                    <span 
                      v-else
                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400"
                    >
                      Requirements needed
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <p v-if="templates.length === 0" class="text-center text-surface-500 py-8">
            No application templates available. Please run the database migration.
          </p>
        </div>
      </div>

      <!-- Installed Applications -->
      <div class="card">
        <div class="card-header-responsive">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <h2 class="text-lg font-medium flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">deployed_code</span>
              Installed Applications
              <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-surface-200 dark:bg-surface-700">
                {{ filteredApps.length }}
              </span>
            </h2>
            
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
              <div class="relative flex-1 min-w-[150px] sm:flex-none">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
                <input
                  v-model="searchQuery"
                  type="text"
                  placeholder="Search..."
                  class="input pl-10 w-full sm:w-40"
                >
              </div>
              <select v-model="filterSite" class="input w-full sm:w-40">
                <option value="">All Sites</option>
                <option v-for="domain in uniqueSites" :key="domain" :value="domain">
                  {{ domain }}
                </option>
              </select>
            </div>
          </div>
        </div>
        
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Application</th>
                <th>Site</th>
                <th class="hidden sm:table-cell">Version</th>
                <th>Status</th>
                <th class="hidden md:table-cell">Installed</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="app in filteredApps" :key="app.id">
                <td>
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">
                        {{ getAppIcon({ slug: app.app_slug, icon: app.app_icon }) }}
                      </span>
                    </div>
                    <div class="min-w-0">
                      <div class="font-medium truncate">{{ app.app_name || app.app_slug }}</div>
                      <div class="text-sm text-surface-500 truncate max-w-[150px]">{{ app.install_path }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <router-link 
                    :to="`/sites-v2/${app.domain}/manage?tab=wordpress`" 
                    class="text-primary-600 dark:text-primary-400 hover:underline flex items-center gap-1"
                  >
                    <span class="truncate max-w-[100px] sm:max-w-none">{{ app.domain }}</span>
                    <span class="material-symbols-rounded text-sm hidden sm:inline">open_in_new</span>
                  </router-link>
                </td>
                <td class="hidden sm:table-cell text-surface-600 dark:text-surface-300">
                  {{ wpSummaries[app.domain]?.version || app.app_version || 'latest' }}
                </td>
                <td>
                  <!-- WordPress Quick Stats -->
                  <div v-if="app.app_slug === 'wordpress' && wpSummaries[app.domain]" class="flex flex-wrap gap-1">
                    <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-600" title="Posts">
                      {{ wpSummaries[app.domain].posts?.post?.count || 0 }}
                    </span>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-500/20 text-purple-600" title="Pages">
                      {{ wpSummaries[app.domain].posts?.page?.count || 0 }}
                    </span>
                    <span v-if="wpSummaries[app.domain].plugins?.updates_available" class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600" title="Updates">
                      {{ wpSummaries[app.domain].plugins.updates_available }}
                    </span>
                  </div>
                  <div v-else-if="app.app_slug === 'wordpress' && wpSummaryLoading[app.domain]" class="flex items-center">
                    <span class="animate-spin rounded-full h-4 w-4 border-2 border-primary-500 border-t-transparent"></span>
                  </div>
                  <StatusBadge v-else :status="app.status" />
                </td>
                <td class="hidden md:table-cell text-sm text-surface-500">
                  {{ formatDate(app.installed_at) }}
                </td>
                <td>
                  <div class="flex items-center justify-end gap-1">
                    <router-link 
                      v-if="app.app_slug === 'wordpress'"
                      :to="`/sites-v2/${app.domain}/manage?tab=wordpress`"
                      class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                      title="Manage WordPress"
                    >
                      <span class="material-symbols-rounded text-lg">settings</span>
                    </router-link>
                    <a 
                      v-if="app.admin_url"
                      :href="app.admin_url" 
                      target="_blank"
                      class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                      title="Open Admin"
                    >
                      <span class="material-symbols-rounded text-lg">admin_panel_settings</span>
                    </a>
                    <button
                      @click="openUninstallModal(app)"
                      class="p-2 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/20 text-red-600 dark:text-red-400 transition-colors"
                      title="Uninstall"
                    >
                      <span class="material-symbols-rounded text-lg">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          
          <div v-if="filteredApps.length === 0" class="text-center py-12 text-surface-500">
            <span class="material-symbols-rounded text-4xl mb-2 block">apps</span>
            <p>No applications installed yet.</p>
            <p class="text-sm mt-1">Select an application template above to get started.</p>
          </div>
        </div>
      </div>
    </template>

    <!-- Docker Tab -->
    <template v-else-if="activeTab === 'docker'">
      <!-- Docker Loading -->
      <div v-if="dockerLoading" class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
      </div>

      <template v-else>
        <!-- Docker Not Installed -->
        <div v-if="!dockerStatus?.installed" class="card p-12 text-center">
          <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-3xl text-blue-600 dark:text-blue-400">deployed_code</span>
          </div>
          <h3 class="text-xl font-semibold mb-2">Docker Not Installed</h3>
          <p class="text-surface-500 mb-6 max-w-md mx-auto">
            Docker is not installed on this server. Install Docker to run containerized applications.
          </p>
          <button 
            @click="installDocker"
            class="btn btn-primary"
            :disabled="dockerInstalling"
          >
            <span v-if="dockerInstalling" class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent mr-2"></span>
            <span class="material-symbols-rounded">download</span>
            {{ dockerInstalling ? 'Installing...' : 'Install Docker' }}
          </button>
        </div>

        <!-- Docker Installed -->
        <template v-else>
          <!-- Docker Status -->
          <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="font-semibold flex items-center gap-2">
                <span class="material-symbols-rounded text-blue-500">deployed_code</span>
                Docker Status
              </h3>
              <button @click="fetchDockerStatus" class="btn btn-secondary btn-sm">
                <span class="material-symbols-rounded">refresh</span>
              </button>
            </div>
            
            <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Status</p>
                <div class="flex items-center gap-2">
                  <span :class="['w-2 h-2 rounded-full', dockerStatus.running ? 'bg-green-500' : 'bg-red-500']"></span>
                  <span class="font-semibold">{{ dockerStatus.running ? 'Running' : 'Stopped' }}</span>
                </div>
              </div>
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Version</p>
                <p class="font-semibold">{{ dockerStatus.version || '-' }}</p>
              </div>
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Compose</p>
                <p class="font-semibold">{{ dockerStatus.compose_installed ? dockerStatus.compose_version : 'Not Installed' }}</p>
              </div>
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Containers</p>
                <p class="font-semibold">{{ containers.length }}</p>
              </div>
            </div>
          </div>

          <!-- Containers -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h3 class="font-semibold flex items-center gap-2">
                <span class="material-symbols-rounded text-green-500">view_in_ar</span>
                Containers
                <span class="text-xs px-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700">
                  {{ containers.length }}
                </span>
              </h3>
            </div>

            <div v-if="containers.length === 0" class="p-12 text-center text-surface-500">
              <span class="material-symbols-rounded text-4xl mb-2 block">inbox</span>
              <p>No containers found</p>
              <p class="text-sm mt-1">Start a container using docker-compose or docker run</p>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="w-full">
                <thead>
                  <tr class="border-b border-surface-200 dark:border-surface-700">
                    <th class="text-left px-6 py-3 text-xs font-medium text-surface-500 uppercase">Container</th>
                    <th class="text-left px-6 py-3 text-xs font-medium text-surface-500 uppercase">Image</th>
                    <th class="text-left px-6 py-3 text-xs font-medium text-surface-500 uppercase">Status</th>
                    <th class="text-left px-6 py-3 text-xs font-medium text-surface-500 uppercase">Ports</th>
                    <th class="text-right px-6 py-3 text-xs font-medium text-surface-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-surface-200 dark:divide-surface-700">
                  <tr v-for="container in containers" :key="container.ID" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                    <td class="px-6 py-4">
                      <div class="flex items-center gap-3">
                        <div :class="['w-10 h-10 rounded-lg flex items-center justify-center', container.running ? 'bg-green-100 dark:bg-green-500/20' : 'bg-surface-100 dark:bg-surface-800']">
                          <span :class="['material-symbols-rounded', container.running ? 'text-green-600' : 'text-surface-400']">deployed_code</span>
                        </div>
                        <div>
                          <div class="font-medium">{{ container.Names }}</div>
                          <div class="text-xs text-surface-500 font-mono">{{ container.ID?.substring(0, 12) }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <span class="font-mono text-sm">{{ container.Image }}</span>
                    </td>
                    <td class="px-6 py-4">
                      <span :class="[
                        'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                        container.running 
                          ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400'
                          : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'
                      ]">
                        {{ container.State }}
                      </span>
                      <p class="text-xs text-surface-500 mt-1">{{ container.Status }}</p>
                    </td>
                    <td class="px-6 py-4">
                      <span class="text-sm font-mono">{{ container.Ports || '-' }}</span>
                    </td>
                    <td class="px-6 py-4">
                      <div class="flex items-center justify-end gap-1">
                        <button 
                          v-if="container.running"
                          @click="restartContainer(container.ID)"
                          class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                          title="Restart"
                        >
                          <span class="material-symbols-rounded text-lg">restart_alt</span>
                        </button>
                        <button 
                          v-if="container.running"
                          @click="stopContainer(container.ID)"
                          class="p-2 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-500/20 text-amber-600 transition-colors"
                          title="Stop"
                        >
                          <span class="material-symbols-rounded text-lg">stop_circle</span>
                        </button>
                        <button 
                          v-else
                          @click="startContainer(container.ID)"
                          class="p-2 rounded-lg hover:bg-green-100 dark:hover:bg-green-500/20 text-green-600 transition-colors"
                          title="Start"
                        >
                          <span class="material-symbols-rounded text-lg">play_circle</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>
      </template>
    </template>

    <!-- Install Modal -->
    <Modal 
      :show="showInstallModal" 
      @close="closeInstallModal"
      :title="`Install ${selectedTemplate?.name || 'Application'}`"
      size="lg"
    >
      <form @submit.prevent="installApp" class="space-y-6">
        <div v-if="selectedTemplate" class="flex items-center gap-4 p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl">
          <div class="w-14 h-14 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-3xl text-primary-600 dark:text-primary-400">
              {{ getAppIcon(selectedTemplate) }}
            </span>
          </div>
          <div>
            <h3 class="font-semibold text-lg">{{ selectedTemplate.name }}</h3>
            <p class="text-sm text-surface-500">{{ selectedTemplate.description }}</p>
          </div>
        </div>
        
        <!-- Site Selection -->
        <div>
          <label class="block text-sm font-medium mb-2">Target Site *</label>
          <select v-model="installForm.domain" @change="onDomainChange" class="input w-full" required>
            <option value="">Select a site...</option>
            <option v-for="site in sites" :key="site.domain" :value="site.domain">
              {{ site.domain }}
            </option>
          </select>
          <p class="text-xs text-surface-500 mt-1">
            The application will be installed in the site's document root.
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Site Title -->
          <div>
            <label class="block text-sm font-medium mb-2">Site Title</label>
            <input 
              v-model="installForm.site_title"
              type="text"
              class="input w-full"
              :placeholder="installForm.domain || 'My Website'"
            >
          </div>

          <!-- Database Name -->
          <div>
            <label class="block text-sm font-medium mb-2">Database</label>
            <!-- Loading state -->
            <div v-if="siteDbLoading" class="input w-full flex items-center gap-2 text-surface-500">
              <span class="animate-spin rounded-full h-4 w-4 border-2 border-primary-500 border-t-transparent"></span>
              Loading databases...
            </div>
            <!-- Databases found - show dropdown -->
            <template v-else-if="siteDatabases.length > 0">
              <select v-model="installForm.db_name" class="input w-full font-mono">
                <optgroup label="Existing Databases">
                  <option v-for="db in siteDatabases" :key="db.name" :value="db.name">
                    {{ db.name }} ({{ db.size_human || 'empty' }})
                  </option>
                </optgroup>
                <optgroup label="Create New">
                  <option :value="generateDbName(installForm.domain)">
                    {{ generateDbName(installForm.domain) }} (new)
                  </option>
                </optgroup>
              </select>
              <p class="text-xs text-surface-500 mt-1">
                <span class="material-symbols-rounded text-xs align-middle">info</span>
                Select existing database or create new
              </p>
            </template>
            <!-- No databases - show text input -->
            <template v-else>
              <input 
                v-model="installForm.db_name"
                type="text"
                class="input w-full font-mono"
                :placeholder="generateDbName(installForm.domain) || 'wordpress_db'"
              >
              <p v-if="installForm.domain" class="text-xs text-surface-500 mt-1">
                <span class="material-symbols-rounded text-xs align-middle">add_circle</span>
                New database will be created
              </p>
              <p v-else class="text-xs text-surface-500 mt-1">Auto-generated if left empty</p>
            </template>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Admin Email -->
          <div>
            <label class="block text-sm font-medium mb-2">Admin Email *</label>
            <input 
              v-model="installForm.admin_email"
              type="email"
              class="input w-full"
              placeholder="admin@example.com"
              required
            >
          </div>

          <!-- Admin Username -->
          <div>
            <label class="block text-sm font-medium mb-2">Admin Username</label>
            <input 
              v-model="installForm.admin_user"
              type="text"
              class="input w-full"
              placeholder="admin"
            >
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Admin Password -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-2">Admin Password</label>
            <div class="flex gap-2">
              <input 
                v-model="installForm.admin_password"
                type="text"
                class="input flex-1 font-mono text-sm"
                readonly
              >
              <button 
                type="button"
                @click="regeneratePassword"
                class="btn btn-secondary"
                title="Generate new password"
              >
                <span class="material-symbols-rounded">refresh</span>
              </button>
              <button 
                type="button"
                @click="copyToClipboard(installForm.admin_password)"
                class="btn btn-secondary"
                title="Copy password"
              >
                <span class="material-symbols-rounded">content_copy</span>
              </button>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-2 p-3 bg-amber-50 dark:bg-amber-500/10 rounded-lg text-amber-700 dark:text-amber-400 text-sm">
          <span class="material-symbols-rounded">warning</span>
          <span>Save the admin password - it will only be shown once.</span>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-surface-200 dark:border-surface-700">
          <button type="button" @click="closeInstallModal" class="btn btn-secondary">
            Cancel
          </button>
          <button 
            type="submit" 
            class="btn btn-primary"
            :disabled="installing"
          >
            <span v-if="installing" class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent mr-2"></span>
            {{ installing ? 'Installing...' : 'Install Application' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Uninstall Confirmation -->
    <ConfirmModal
      :show="showUninstallModal"
      title="Uninstall Application"
      :message="`Are you sure you want to uninstall ${selectedApp?.app_name || selectedApp?.app_slug} from ${selectedApp?.domain}? This will remove all files and the associated database.`"
      confirm-text="Uninstall"
      confirm-variant="danger"
      @confirm="confirmUninstall"
      @cancel="showUninstallModal = false"
    />
  </div>
</template>

