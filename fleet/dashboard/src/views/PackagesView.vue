<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const toast = useToastStore()
const loading = ref(true)
const packages = ref({})
const stats = ref({})
const sourceInfo = ref({})
const typeLabels = ref({})
const activeTab = ref('panel')

// Build state
const building = ref(false)
const buildResult = ref(null)

// Delete confirmation
const showDeleteModal = ref(false)
const packageToDelete = ref(null)
const deleting = ref(false)

const tabs = [
  { id: 'panel', label: 'VPS Admin Panel', icon: 'dashboard' },
  { id: 'email', label: 'Email App', icon: 'mail' },
  { id: 'agent', label: 'Fleet Agent', icon: 'smart_toy' },
]

const currentPackages = computed(() => packages.value[activeTab.value] || [])
const currentStats = computed(() => stats.value.by_type?.[activeTab.value] || {})
const currentSourceInfo = computed(() => sourceInfo.value[activeTab.value] || {})

const fetchPackages = async () => {
  loading.value = true
  try {
    const response = await api.get('/api/packages')
    packages.value = response.data.packages
    stats.value = response.data.stats
    sourceInfo.value = response.data.source_info || {}
    typeLabels.value = response.data.type_labels || {}
  } catch (error) {
    toast.error('Failed to load packages')
  } finally {
    loading.value = false
  }
}

const buildPackage = async () => {
  building.value = true
  buildResult.value = null

  try {
    const result = await api.post(`/api/packages/${activeTab.value}/build`)

    buildResult.value = result.data
    toast.success(`Package v${result.data.version} built successfully`)
    
    // Refresh the list
    await fetchPackages()
  } catch (error) {
    toast.error(error.message || 'Failed to build package')
  } finally {
    building.value = false
  }
}

const setLatest = async (pkg) => {
  try {
    await api.post(`/api/packages/${activeTab.value}/${pkg.version}/set-latest`)
    toast.success(`v${pkg.version} set as latest`)
    await fetchPackages()
  } catch (error) {
    toast.error(error.message || 'Failed to set latest version')
  }
}

const confirmDelete = (pkg) => {
  packageToDelete.value = { ...pkg, type: activeTab.value }
  showDeleteModal.value = true
}

const deletePackage = async () => {
  if (!packageToDelete.value) return
  
  deleting.value = true
  try {
    await api.delete(`/api/packages/${packageToDelete.value.type}/${packageToDelete.value.version}`)
    toast.success(`Package v${packageToDelete.value.version} deleted`)
    showDeleteModal.value = false
    packageToDelete.value = null
    await fetchPackages()
  } catch (error) {
    toast.error(error.message || 'Failed to delete package')
  } finally {
    deleting.value = false
  }
}

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const downloadPackage = (pkg) => {
  const token = localStorage.getItem('token')
  const url = `/api/packages/${activeTab.value}/${pkg.version}/download`
  
  fetch(url, {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  })
    .then(response => response.blob())
    .then(blob => {
      const downloadUrl = window.URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = downloadUrl
      a.download = pkg.filename
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      window.URL.revokeObjectURL(downloadUrl)
    })
    .catch(() => {
      toast.error('Failed to download package')
    })
}

const closeBuildResult = () => {
  buildResult.value = null
}

onMounted(fetchPackages)
</script>

<template>
  <div class="animate-fadeIn">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold">Deployment Packages</h1>
        <p class="text-surface-500 dark:text-surface-400 mt-1">Build and manage Panel, Email App, and Agent packages</p>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="card p-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-primary-500/20 rounded-lg flex items-center justify-center">
            <span class="material-symbols-rounded text-primary-500">package_2</span>
          </div>
          <div>
            <p class="text-2xl font-bold">{{ stats.total_packages || 0 }}</p>
            <p class="text-sm text-surface-500 dark:text-surface-400">Total Packages</p>
          </div>
        </div>
      </div>
      <div v-for="tab in tabs" :key="tab.id" class="card p-4 cursor-pointer hover:border-primary-500/50 transition-colors" @click="activeTab = tab.id">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center"
               :class="activeTab === tab.id ? 'bg-primary-500/20' : 'bg-surface-200 dark:bg-surface-700'">
            <span class="material-symbols-rounded" :class="activeTab === tab.id ? 'text-primary-500' : 'text-surface-500'">{{ tab.icon }}</span>
          </div>
          <div>
            <p class="text-lg font-semibold">{{ stats.by_type?.[tab.id]?.count || 0 }}</p>
            <p class="text-xs text-surface-500 dark:text-surface-400">{{ tab.label }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="card">
      <div class="border-b border-surface-200 dark:border-surface-700">
        <nav class="flex -mb-px">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="[
              'px-6 py-4 text-sm font-medium border-b-2 transition-colors flex items-center gap-2',
              activeTab === tab.id
                ? 'border-primary-500 text-primary-500'
                : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
            {{ tab.label }}
            <span v-if="packages[tab.id]?.length" class="ml-1 px-2 py-0.5 text-xs rounded-full"
                  :class="activeTab === tab.id ? 'bg-primary-500/20 text-primary-500' : 'bg-surface-200 dark:bg-surface-700'">
              {{ packages[tab.id].length }}
            </span>
          </button>
        </nav>
      </div>

      <div class="p-6">
        <!-- Build Section -->
        <div class="rounded-xl border-2 border-dashed border-surface-300 dark:border-surface-600 p-6 mb-6">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
              <div class="w-14 h-14 bg-primary-500/20 rounded-xl flex items-center justify-center">
                <span class="material-symbols-rounded text-primary-500 text-3xl">build</span>
              </div>
              <div>
                <h3 class="text-lg font-semibold">Build New Package</h3>
                <div class="text-sm text-surface-500 dark:text-surface-400 mt-1">
                  <template v-if="currentSourceInfo.exists">
                    <span class="inline-flex items-center gap-1 text-green-500">
                      <span class="material-symbols-rounded text-sm">check_circle</span>
                      Source available
                    </span>
                    <span class="mx-2">|</span>
                    <span>{{ currentSourceInfo.file_count?.toLocaleString() }} files</span>
                    <span class="mx-2">|</span>
                    <span>{{ currentSourceInfo.size_human }}</span>
                  </template>
                  <template v-else>
                    <span class="inline-flex items-center gap-1 text-red-500">
                      <span class="material-symbols-rounded text-sm">error</span>
                      Source not found
                    </span>
                    <span class="mx-2">|</span>
                    <span class="font-mono text-xs">{{ currentSourceInfo.path }}</span>
                  </template>
                </div>
              </div>
            </div>
            
            <button
              @click="buildPackage"
              :disabled="building || !currentSourceInfo.exists"
              class="btn btn-primary"
              :class="{ 'opacity-50 cursor-not-allowed': !currentSourceInfo.exists }"
            >
              <template v-if="building">
                <span class="spinner w-5 h-5"></span>
                Building...
              </template>
              <template v-else>
                <span class="material-symbols-rounded">construction</span>
                Build Package
              </template>
            </button>
          </div>
          
          <!-- Source Path Info -->
          <div class="mt-4 text-xs font-mono text-surface-500 dark:text-surface-400 bg-surface-100 dark:bg-surface-800/50 rounded-lg px-4 py-2">
            Source: {{ currentSourceInfo.path }}
          </div>
        </div>

        <!-- Build Result -->
        <div v-if="buildResult" class="mb-6 bg-green-500/10 border border-green-500/30 rounded-xl p-5">
          <div class="flex items-start justify-between">
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center">
                <span class="material-symbols-rounded text-green-500 text-2xl">check_circle</span>
              </div>
              <div>
                <h4 class="font-semibold text-green-400">Package Built Successfully</h4>
                <p class="text-sm text-green-400/80 mt-1">
                  <span class="font-semibold">{{ buildResult.type_label }}</span> v{{ buildResult.version }}
                </p>
              </div>
            </div>
            <button @click="closeBuildResult" class="text-surface-400 hover:text-surface-300">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-surface-800/50 rounded-lg p-3">
              <p class="text-xs text-surface-400">Version</p>
              <p class="font-semibold">v{{ buildResult.version }}</p>
            </div>
            <div class="bg-surface-800/50 rounded-lg p-3">
              <p class="text-xs text-surface-400">Package Size</p>
              <p class="font-semibold">{{ buildResult.size_human }}</p>
            </div>
            <div class="bg-surface-800/50 rounded-lg p-3">
              <p class="text-xs text-surface-400">Files Included</p>
              <p class="font-semibold">{{ buildResult.contents?.files?.toLocaleString() }}</p>
            </div>
            <div class="bg-surface-800/50 rounded-lg p-3">
              <p class="text-xs text-surface-400">Built At</p>
              <p class="font-semibold">{{ formatDate(buildResult.built_at) }}</p>
            </div>
          </div>
          
          <div class="mt-4">
            <p class="text-xs text-surface-400 mb-2">Directories Included:</p>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="dir in buildResult.contents?.directories"
                :key="dir"
                class="px-2 py-1 bg-surface-700 rounded text-xs font-mono"
              >
                {{ dir }}
              </span>
            </div>
          </div>
          
          <div class="mt-4 text-xs font-mono text-surface-500 bg-surface-800 rounded px-3 py-2">
            SHA-256: {{ buildResult.checksum }}
          </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-12">
          <div class="spinner w-10 h-10"></div>
        </div>

        <!-- Empty state -->
        <div v-else-if="currentPackages.length === 0" class="text-center py-12">
          <span class="material-symbols-rounded text-5xl text-surface-400 mb-4">inventory_2</span>
          <h3 class="text-lg font-semibold mb-2">No {{ tabs.find(t => t.id === activeTab)?.label }} packages</h3>
          <p class="text-surface-500 dark:text-surface-400">Click "Build Package" to create your first package</p>
        </div>

        <!-- Package List -->
        <div v-else>
          <h3 class="text-sm font-semibold text-surface-500 dark:text-surface-400 mb-4 uppercase tracking-wider">
            Available Versions
          </h3>
          <div class="space-y-3">
            <div
              v-for="pkg in currentPackages"
              :key="pkg.version"
              class="flex items-center justify-between p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            >
              <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-surface-200 dark:bg-surface-700 rounded-lg flex items-center justify-center">
                  <span class="material-symbols-rounded text-surface-500">archive</span>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="font-semibold">v{{ pkg.version }}</span>
                    <span v-if="pkg.is_latest" class="badge badge-success">Latest</span>
                  </div>
                  <p class="text-sm text-surface-500 dark:text-surface-400">
                    {{ pkg.size_human }} &bull; {{ formatDate(pkg.created_at) }}
                  </p>
                </div>
              </div>
              
              <div class="flex items-center gap-2">
                <button
                  v-if="!pkg.is_latest"
                  @click="setLatest(pkg)"
                  class="btn btn-ghost btn-sm"
                  title="Set as latest"
                >
                  <span class="material-symbols-rounded">star</span>
                  Set Latest
                </button>
                <button
                  @click="downloadPackage(pkg)"
                  class="btn btn-ghost btn-sm"
                  title="Download"
                >
                  <span class="material-symbols-rounded">download</span>
                </button>
                <button
                  @click="confirmDelete(pkg)"
                  class="btn btn-ghost btn-sm text-red-500 hover:bg-red-500/10"
                  title="Delete"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Checksum Info -->
        <div v-if="currentPackages.length > 0" class="mt-6 p-4 bg-surface-100 dark:bg-surface-800/50 rounded-xl">
          <h4 class="font-medium mb-2 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">verified</span>
            Package Checksums (SHA-256)
          </h4>
          <div class="space-y-2 font-mono text-xs">
            <div v-for="pkg in currentPackages" :key="pkg.version" class="flex items-start gap-2">
              <span class="text-surface-500 whitespace-nowrap">v{{ pkg.version }}:</span>
              <span class="text-surface-600 dark:text-surface-400 break-all">{{ pkg.checksum }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/70" @click="showDeleteModal = false"></div>
      <div class="relative bg-surface-800 rounded-xl shadow-2xl w-full max-w-md p-6">
        <div class="flex items-center gap-4 mb-4">
          <div class="w-12 h-12 bg-red-500/20 rounded-full flex items-center justify-center">
            <span class="material-symbols-rounded text-red-500 text-2xl">warning</span>
          </div>
          <div>
            <h3 class="text-lg font-semibold">Delete Package</h3>
            <p class="text-sm text-surface-400">This action cannot be undone</p>
          </div>
        </div>
        
        <p class="mb-6 text-surface-300">
          Are you sure you want to delete <strong class="text-white">{{ packageToDelete?.type }}-v{{ packageToDelete?.version }}</strong>?
        </p>

        <div v-if="packageToDelete?.is_latest" class="mb-6 p-3 bg-amber-500/10 border border-amber-500/30 rounded-lg">
          <p class="text-sm text-amber-400 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">info</span>
            This is the current "latest" version. The next highest version will become the new latest.
          </p>
        </div>
        
        <div class="flex justify-end gap-3">
          <button @click="showDeleteModal = false" class="btn btn-ghost">Cancel</button>
          <button 
            @click="deletePackage" 
            :disabled="deleting" 
            class="btn bg-red-600 hover:bg-red-700 text-white disabled:opacity-50"
          >
            <span v-if="deleting" class="spinner w-5 h-5"></span>
            <span v-else class="material-symbols-rounded">delete</span>
            Delete
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
