<script setup>
/**
 * Docker details panel (Overview -> Docker tab).
 *
 * One place that shows everything the engine knows: engine facts, disk
 * usage, compose stacks, containers, images, volumes and networks. Data
 * comes from a single GET /docker/overview snapshot (agent-side
 * DockerInspector), so the page stays consistent even mid-deploy.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { cache, CACHE_KEYS, TTL } from '@/services/cache'

const toast = useToastStore()

const loading = ref(true)
const installing = ref(false)
const status = ref(null)      // /docker/status (installed? running? versions)
const overview = ref(null)    // /docker/overview (deep snapshot)
const actionBusy = ref(null)  // container id currently being started/stopped
const section = ref('containers') // containers | images | volumes | networks

const containers = computed(() => overview.value?.containers || [])
const images = computed(() => overview.value?.images || [])
const volumes = computed(() => overview.value?.volumes || [])
const networks = computed(() => overview.value?.networks || [])
const stacks = computed(() => overview.value?.stacks || [])
const diskUsage = computed(() => overview.value?.disk_usage || [])
const engine = computed(() => overview.value?.info || null)

const runningCount = computed(() => containers.value.filter(c => c.running).length)

const sections = computed(() => ([
  { id: 'containers', label: 'Containers', icon: 'view_in_ar', count: containers.value.length },
  { id: 'images', label: 'Images', icon: 'photo_library', count: images.value.length },
  { id: 'volumes', label: 'Volumes', icon: 'database', count: volumes.value.length },
  { id: 'networks', label: 'Networks', icon: 'lan', count: networks.value.length },
]))

const cacheAge = ref('not cached')
const refreshCacheAge = () => { cacheAge.value = cache.getAgeHuman(CACHE_KEYS.DOCKER_OVERVIEW) }

const fetchAll = async (forceRefresh = false) => {
  if (!forceRefresh) {
    const cachedStatus = cache.get(CACHE_KEYS.DOCKER_STATUS)
    const cachedOverview = cache.get(CACHE_KEYS.DOCKER_OVERVIEW)
    if (cachedStatus && cachedOverview) {
      status.value = cachedStatus
      overview.value = cachedOverview
      loading.value = false
      return
    }
  }

  loading.value = true
  try {
    const statusRes = await api.get('/docker/status')
    if (statusRes.data.success) {
      status.value = statusRes.data.data
      cache.set(CACHE_KEYS.DOCKER_STATUS, status.value, TTL.SHORT)
    }
    if (status.value?.running) {
      const res = await api.get('/docker/overview')
      if (res.data.success) {
        overview.value = res.data.data
        cache.set(CACHE_KEYS.DOCKER_OVERVIEW, overview.value, TTL.SHORT)
      }
    }
  } catch (e) {
    if (!status.value) status.value = { installed: false, running: false }
    toast.error(e.response?.data?.error || 'Failed to load Docker details')
  } finally {
    loading.value = false
    refreshCacheAge()
  }
}

const installDocker = async () => {
  installing.value = true
  try {
    const response = await api.post('/docker/install', { include_compose: true })
    if (response.data.success) {
      toast.success('Docker installed successfully')
      await fetchAll(true)
    } else {
      toast.error(response.data.error || 'Failed to install Docker')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to install Docker')
  } finally {
    installing.value = false
  }
}

const containerAction = async (id, verb) => {
  actionBusy.value = id
  try {
    const response = await api.post(`/docker/containers/${id}/${verb}`)
    if (response.data.success) {
      toast.success(`Container ${verb}ed`)
      await fetchAll(true)
    } else {
      toast.error(response.data.error || `Failed to ${verb} container`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to ${verb} container`)
  } finally {
    actionBusy.value = null
  }
}

const formatBytes = (bytes) => {
  if (bytes == null || isNaN(bytes)) return '-'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = Number(bytes)
  let i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v >= 100 || i === 0 ? 0 : 1)} ${units[i]}`
}

const healthBadge = (health) => ({
  healthy: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
  starting: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400',
  unhealthy: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400',
}[health] || 'bg-surface-100 dark:bg-surface-700 text-surface-600')

onMounted(() => fetchAll())
</script>

<template>
  <div class="space-y-6">
    <!-- Header row -->
    <div class="flex justify-between items-center">
      <span v-if="cacheAge !== 'not cached'" class="text-xs text-surface-400">
        <span class="material-symbols-rounded text-sm align-middle">schedule</span>
        Updated {{ cacheAge }}
      </span>
      <span v-else></span>
      <button @click="fetchAll(true)" class="btn-secondary" :disabled="loading">
        <span class="material-symbols-rounded" :class="loading && 'animate-spin'">refresh</span>
        Refresh
      </button>
    </div>

    <div v-if="loading && !overview" class="flex justify-center py-12">
      <span class="spinner"></span>
    </div>

    <template v-else>
      <!-- Docker not installed -->
      <div v-if="!status?.installed" class="card p-12 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-3xl text-blue-600 dark:text-blue-400">deployed_code</span>
        </div>
        <h3 class="text-xl font-semibold mb-2">Docker Not Installed</h3>
        <p class="text-surface-500 mb-6 max-w-md mx-auto">
          Docker is not installed on this server. Install Docker to run containerized applications.
        </p>
        <button @click="installDocker" class="btn-primary" :disabled="installing">
          <span v-if="installing" class="spinner-sm mr-2"></span>
          <span class="material-symbols-rounded">download</span>
          {{ installing ? 'Installing...' : 'Install Docker' }}
        </button>
      </div>

      <template v-else>
        <!-- Engine summary -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-blue-500">deployed_code</span>
              Docker Engine
            </h3>
            <span v-if="engine?.root_dir" class="text-xs text-surface-400 font-mono">{{ engine.root_dir }}</span>
          </div>

          <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
              <p class="text-xs text-surface-500 mb-1">Status</p>
              <div class="flex items-center gap-2">
                <span :class="['w-2 h-2 rounded-full', status?.running ? 'bg-green-500' : 'bg-red-500']"></span>
                <span class="font-semibold">{{ status?.running ? 'Running' : 'Stopped' }}</span>
              </div>
            </div>
            <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
              <p class="text-xs text-surface-500 mb-1">Engine</p>
              <p class="font-semibold">{{ engine?.server_version || status?.version || '-' }}</p>
            </div>
            <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
              <p class="text-xs text-surface-500 mb-1">Compose</p>
              <p class="font-semibold">{{ status?.compose_installed ? status?.compose_version : 'Not Installed' }}</p>
            </div>
            <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
              <p class="text-xs text-surface-500 mb-1">Containers</p>
              <p class="font-semibold">{{ runningCount }} / {{ containers.length }} running</p>
            </div>
            <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
              <p class="text-xs text-surface-500 mb-1">Storage Driver</p>
              <p class="font-semibold">{{ engine?.storage_driver || '-' }}</p>
            </div>
            <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
              <p class="text-xs text-surface-500 mb-1">Host RAM</p>
              <p class="font-semibold">{{ formatBytes(engine?.mem_total) }} · {{ engine?.ncpu || '-' }} CPU</p>
            </div>
          </div>
        </div>

        <!-- Disk usage -->
        <div v-if="diskUsage.length" class="card">
          <div class="card-header">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500">hard_drive</span>
              Disk Usage
            </h3>
          </div>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Type</th>
                  <th>Total</th>
                  <th>Active</th>
                  <th>Size</th>
                  <th>Reclaimable</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in diskUsage" :key="row.type">
                  <td class="font-medium">{{ row.type }}</td>
                  <td>{{ row.total }}</td>
                  <td>{{ row.active }}</td>
                  <td class="font-mono text-sm">{{ row.size }}</td>
                  <td class="font-mono text-sm">{{ row.reclaimable }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Compose stacks -->
        <div v-if="stacks.length" class="card">
          <div class="card-header">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-purple-500">stacks</span>
              Compose Stacks
            </h3>
          </div>
          <div class="p-4 space-y-3">
            <div v-for="stack in stacks" :key="stack.project" class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-3">
                  <span class="font-semibold">{{ stack.project }}</span>
                  <span v-if="stack.working_dir" class="text-xs text-surface-400 font-mono">{{ stack.working_dir }}</span>
                </div>
                <span :class="[
                  'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                  stack.running === stack.total
                    ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400'
                    : 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400'
                ]">{{ stack.running }} / {{ stack.total }} running</span>
              </div>
              <div class="flex flex-wrap gap-2">
                <span v-for="svc in stack.services" :key="svc.name"
                  class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-700">
                  <span :class="['w-1.5 h-1.5 rounded-full', svc.state === 'running' ? 'bg-green-500' : 'bg-red-500']"></span>
                  {{ svc.service || svc.name }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Section switcher -->
        <div class="flex flex-wrap gap-2">
          <button v-for="s in sections" :key="s.id" @click="section = s.id"
            :class="[
              'inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-colors',
              section === s.id
                ? 'bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-500/30'
                : 'bg-surface-50 dark:bg-surface-800 text-surface-600 dark:text-surface-400 border border-transparent hover:border-surface-300 dark:hover:border-surface-600'
            ]">
            <span class="material-symbols-rounded text-lg">{{ s.icon }}</span>
            {{ s.label }}
            <span class="text-xs px-1.5 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700">{{ s.count }}</span>
          </button>
        </div>

        <!-- Containers -->
        <div v-if="section === 'containers'" class="card">
          <div v-if="containers.length === 0" class="p-12 text-center text-surface-500">
            <span class="material-symbols-rounded text-4xl mb-2 block">inbox</span>
            <p>No containers found</p>
          </div>
          <div v-else class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Container</th>
                  <th>Image</th>
                  <th>Status</th>
                  <th>Ports</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in containers" :key="c.id">
                  <td>
                    <div class="flex items-center gap-3">
                      <div :class="['w-10 h-10 rounded-lg flex items-center justify-center shrink-0', c.running ? 'bg-green-100 dark:bg-green-500/20' : 'bg-surface-100 dark:bg-surface-800']">
                        <span :class="['material-symbols-rounded', c.running ? 'text-green-600' : 'text-surface-400']">deployed_code</span>
                      </div>
                      <div>
                        <div class="font-medium">{{ c.name }}</div>
                        <div class="text-xs text-surface-500 font-mono">
                          {{ c.id }}
                          <span v-if="c.compose_service" class="ml-1 text-purple-500">{{ c.compose_project }}/{{ c.compose_service }}</span>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td><span class="font-mono text-sm">{{ c.image }}</span></td>
                  <td>
                    <div class="flex items-center gap-2">
                      <span :class="[
                        'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                        c.running ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' : 'bg-surface-100 dark:bg-surface-700 text-surface-600'
                      ]">{{ c.state }}</span>
                      <span v-if="c.health" :class="['inline-flex items-center px-2 py-1 rounded-full text-xs font-medium', healthBadge(c.health)]">{{ c.health }}</span>
                    </div>
                    <p class="text-xs text-surface-500 mt-1">{{ c.status }}</p>
                  </td>
                  <td><span class="text-xs font-mono whitespace-pre-line">{{ (c.ports || '-').split(', ').join('\n') }}</span></td>
                  <td class="text-right">
                    <div class="flex items-center justify-end gap-1">
                      <span v-if="actionBusy === c.id" class="spinner-sm"></span>
                      <template v-else>
                        <button v-if="c.running" @click="containerAction(c.id, 'restart')" class="btn-ghost btn-sm" title="Restart">
                          <span class="material-symbols-rounded">restart_alt</span>
                        </button>
                        <button v-if="c.running" @click="containerAction(c.id, 'stop')" class="btn-ghost btn-sm text-amber-600" title="Stop">
                          <span class="material-symbols-rounded">stop_circle</span>
                        </button>
                        <button v-else @click="containerAction(c.id, 'start')" class="btn-ghost btn-sm text-green-600" title="Start">
                          <span class="material-symbols-rounded">play_circle</span>
                        </button>
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Images -->
        <div v-if="section === 'images'" class="card">
          <div v-if="images.length === 0" class="p-12 text-center text-surface-500">
            <span class="material-symbols-rounded text-4xl mb-2 block">inbox</span>
            <p>No images found</p>
          </div>
          <div v-else class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Repository</th>
                  <th>Tag</th>
                  <th>Image ID</th>
                  <th>Size</th>
                  <th>Created</th>
                  <th>In Use</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="img in images" :key="img.id + img.tag">
                  <td>
                    <span class="font-medium" :class="img.dangling && 'text-surface-400 italic'">{{ img.repository }}</span>
                  </td>
                  <td><span class="font-mono text-sm">{{ img.tag }}</span></td>
                  <td><span class="font-mono text-xs text-surface-500">{{ img.id }}</span></td>
                  <td><span class="font-mono text-sm">{{ img.size }}</span></td>
                  <td class="text-sm text-surface-500">{{ img.created }}</td>
                  <td>
                    <span :class="[
                      'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                      img.used_by > 0
                        ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400'
                        : 'bg-surface-100 dark:bg-surface-700 text-surface-500'
                    ]">{{ img.used_by > 0 ? `${img.used_by} container${img.used_by > 1 ? 's' : ''}` : 'unused' }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Volumes -->
        <div v-if="section === 'volumes'" class="card">
          <div v-if="volumes.length === 0" class="p-12 text-center text-surface-500">
            <span class="material-symbols-rounded text-4xl mb-2 block">inbox</span>
            <p>No volumes found</p>
          </div>
          <div v-else class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Volume</th>
                  <th>Driver</th>
                  <th>Size</th>
                  <th>Mountpoint</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="v in volumes" :key="v.name">
                  <td class="font-medium">{{ v.name }}</td>
                  <td class="text-sm">{{ v.driver }}</td>
                  <td><span class="font-mono text-sm">{{ v.size || '-' }}</span></td>
                  <td><span class="font-mono text-xs text-surface-500">{{ v.mountpoint }}</span></td>
                  <td>
                    <span :class="[
                      'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                      v.in_use
                        ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400'
                        : 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400'
                    ]">{{ v.in_use ? 'in use' : 'unused' }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Networks -->
        <div v-if="section === 'networks'" class="card">
          <div v-if="networks.length === 0" class="p-12 text-center text-surface-500">
            <span class="material-symbols-rounded text-4xl mb-2 block">inbox</span>
            <p>No networks found</p>
          </div>
          <div v-else class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Network</th>
                  <th>ID</th>
                  <th>Driver</th>
                  <th>Scope</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="n in networks" :key="n.id">
                  <td class="font-medium">{{ n.name }}</td>
                  <td><span class="font-mono text-xs text-surface-500">{{ n.id }}</span></td>
                  <td class="text-sm">{{ n.driver }}</td>
                  <td class="text-sm">{{ n.scope }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
