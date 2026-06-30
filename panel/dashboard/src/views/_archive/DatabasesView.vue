<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'

const toast = useToastStore()

const loading = ref(true)
const databases = ref([])
const sites = ref([])
const createModal = ref(false)
const deleteModal = ref({ show: false, db: null })
const linkModal = ref({ show: false, db: null })
const orphansModal = ref(false)
const submitting = ref(false)
const showSystem = ref(false)
const searchQuery = ref('')
const orphanData = ref(null)
const autoLinking = ref(false)

const newDb = ref({
  name: '',
  user: '',
  password: '',
  domain: ''
})

const linkForm = ref({
  domain: '',
  db_user: '',
  notes: ''
})

// Filter databases
const filteredDatabases = computed(() => {
  let result = databases.value
  
  // Filter system databases
  if (!showSystem.value) {
    result = result.filter(db => !db.is_system)
  }
  
  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(db => 
      db.name.toLowerCase().includes(query) ||
      db.linked_site?.toLowerCase().includes(query) ||
      db.linked_sites?.some(s => s.domain.toLowerCase().includes(query)) ||
      db.users?.some(u => u.User.toLowerCase().includes(query))
    )
  }
  
  return result
})

// Stats
const totalSize = computed(() => {
  return databases.value
    .filter(db => !db.is_system)
    .reduce((sum, db) => sum + (db.size || 0), 0)
})

const linkedCount = computed(() => {
  return databases.value.filter(db => !db.is_system && db.linked_sites?.length > 0).length
})

const unlinkedCount = computed(() => {
  return databases.value.filter(db => !db.is_system && (!db.linked_sites || db.linked_sites.length === 0)).length
})

const generatePassword = () => {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'
  let password = ''
  for (let i = 0; i < 16; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  newDb.value.password = password
}

const fetchDatabases = async () => {
  try {
    const response = await api.get('/databases')
    if (response.data.success) {
      databases.value = response.data.data.databases || []
    }
  } catch (e) {
    toast.error('Failed to load databases')
  } finally {
    loading.value = false
  }
}

const fetchSites = async () => {
  try {
    const response = await api.get('/sites')
    if (response.data.success) {
      sites.value = response.data.data.vhosts || []
    }
  } catch (e) {
    // Silent fail - sites are optional for linking
  }
}

const createDatabase = async () => {
  submitting.value = true
  try {
    const response = await api.post('/databases', newDb.value)
    if (response.data.success) {
      toast.success('Database created')
      
      // Show credentials if user was created
      if (response.data.data.password) {
        toast.info(`Password: ${response.data.data.password}`, 0)
      }
      
      createModal.value = false
      newDb.value = { name: '', user: '', password: '', domain: '' }
      await fetchDatabases()
    } else {
      toast.error(response.data.error || 'Failed to create database')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create database')
  } finally {
    submitting.value = false
  }
}

const deleteDatabase = async () => {
  if (!deleteModal.value.db) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/databases/${deleteModal.value.db.name}`)
    if (response.data.success) {
      toast.success('Database deleted')
      deleteModal.value = { show: false, db: null }
      await fetchDatabases()
    } else {
      toast.error(response.data.error || 'Failed to delete database')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete database')
  } finally {
    submitting.value = false
  }
}

const openLinkModal = (db) => {
  linkModal.value = { show: true, db }
  linkForm.value = {
    domain: '',
    db_user: db.users?.[0]?.User || '',
    notes: ''
  }
}

const linkDatabase = async () => {
  if (!linkModal.value.db || !linkForm.value.domain) return
  
  submitting.value = true
  try {
    const response = await api.post(`/databases/${linkModal.value.db.name}/link`, linkForm.value)
    if (response.data.success) {
      toast.success('Database linked to site')
      linkModal.value = { show: false, db: null }
      await fetchDatabases()
    } else {
      toast.error(response.data.error || 'Failed to link database')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to link database')
  } finally {
    submitting.value = false
  }
}

const unlinkDatabase = async (dbName, domain) => {
  try {
    const response = await api.delete(`/databases/${dbName}/link/${domain}`)
    if (response.data.success) {
      toast.success('Database unlinked from site')
      await fetchDatabases()
    } else {
      toast.error(response.data.error || 'Failed to unlink database')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to unlink database')
  }
}

const showOrphans = async () => {
  orphansModal.value = true
  orphanData.value = null
  
  try {
    const response = await api.get('/databases/orphans')
    if (response.data.success) {
      orphanData.value = response.data.data
    } else {
      toast.error('Failed to detect orphan databases')
    }
  } catch (e) {
    toast.error('Failed to detect orphan databases')
  }
}

const autoLinkDatabases = async () => {
  autoLinking.value = true
  try {
    const response = await api.post('/databases/auto-link')
    if (response.data.success) {
      const data = response.data.data
      if (data.linked_count > 0) {
        toast.success(`${data.linked_count} databases linked automatically`)
      } else {
        toast.info('No databases could be auto-linked')
      }
      orphansModal.value = false
      await fetchDatabases()
    } else {
      toast.error(response.data.error || 'Auto-link failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Auto-link failed')
  } finally {
    autoLinking.value = false
  }
}

const formatSize = (bytes) => {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  while (bytes >= 1024 && i < units.length - 1) {
    bytes /= 1024
    i++
  }
  return `${bytes.toFixed(1)} ${units[i]}`
}

onMounted(() => {
  fetchDatabases()
  fetchSites()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Databases</h1>
        <p class="text-surface-500 text-sm mt-1">Manage MySQL databases and site links</p>
      </div>
      <div class="flex gap-3">
        <button @click="showOrphans" class="btn-secondary">
          <span class="material-symbols-rounded">link_off</span>
          Orphan Check
        </button>
        <button @click="createModal = true" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          New Database
        </button>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="card p-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-purple-600 dark:text-purple-400">database</span>
          </div>
          <div>
            <div class="text-2xl font-semibold">{{ databases.filter(d => !d.is_system).length }}</div>
            <div class="text-sm text-surface-500">Total Databases</div>
          </div>
        </div>
      </div>
      <div class="card p-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-green-600 dark:text-green-400">link</span>
          </div>
          <div>
            <div class="text-2xl font-semibold">{{ linkedCount }}</div>
            <div class="text-sm text-surface-500">Linked to Sites</div>
          </div>
        </div>
      </div>
      <div class="card p-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">link_off</span>
          </div>
          <div>
            <div class="text-2xl font-semibold">{{ unlinkedCount }}</div>
            <div class="text-sm text-surface-500">Unlinked (Orphans)</div>
          </div>
        </div>
      </div>
      <div class="card p-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">storage</span>
          </div>
          <div>
            <div class="text-2xl font-semibold">{{ formatSize(totalSize) }}</div>
            <div class="text-sm text-surface-500">Total Size</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="card p-4 mb-6">
      <div class="flex flex-wrap gap-4 items-center">
        <!-- Search -->
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input
            v-model="searchQuery"
            type="text"
            class="input pl-10"
            placeholder="Search databases, sites, users..."
          />
        </div>
        
        <!-- Show system toggle -->
        <label class="flex items-center gap-2 cursor-pointer">
          <div class="relative">
            <input type="checkbox" v-model="showSystem" class="sr-only peer" />
            <div class="w-11 h-6 bg-surface-200 dark:bg-surface-700 rounded-full peer peer-checked:bg-primary-500 transition-colors"></div>
            <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
          </div>
          <span class="text-sm">Show System DBs</span>
        </label>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Databases table -->
    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th>Name</th>
            <th>Size</th>
            <th>Tables</th>
            <th>Users</th>
            <th>Linked Sites</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="db in filteredDatabases" :key="db.name" :class="db.is_system ? 'opacity-60' : ''">
            <td>
              <div class="flex items-center gap-3">
                <div :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center',
                  db.is_system 
                    ? 'bg-surface-100 dark:bg-surface-800' 
                    : 'bg-purple-100 dark:bg-purple-500/20'
                ]">
                  <span :class="[
                    'material-symbols-rounded',
                    db.is_system 
                      ? 'text-surface-400' 
                      : 'text-purple-600 dark:text-purple-400'
                  ]">{{ db.is_system ? 'settings' : 'database' }}</span>
                </div>
                <div>
                  <span class="font-medium">{{ db.name }}</span>
                  <span v-if="db.is_system" class="ml-2 text-xs px-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500">
                    system
                  </span>
                </div>
              </div>
            </td>
            <td>
              <span class="text-surface-500">{{ db.size_human || formatSize(db.size) }}</span>
            </td>
            <td>
              <span class="text-surface-500">{{ db.tables_count || 0 }}</span>
            </td>
            <td>
              <div class="flex flex-wrap gap-1">
                <span 
                  v-for="user in (db.users || []).slice(0, 3)" 
                  :key="`${user.User}@${user.Host}`"
                  class="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400"
                >
                  {{ user.User }}
                </span>
                <span 
                  v-if="(db.users?.length || 0) > 3"
                  class="text-xs px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-800 text-surface-500"
                >
                  +{{ db.users.length - 3 }}
                </span>
                <span v-if="!db.users?.length" class="text-surface-400">-</span>
              </div>
            </td>
            <td>
              <div class="flex flex-wrap gap-1">
                <!-- Linked sites from our tracking -->
                <template v-if="db.linked_sites?.length">
                  <div 
                    v-for="link in db.linked_sites" 
                    :key="link.domain"
                    class="group flex items-center gap-1"
                  >
                    <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400">
                      {{ link.domain }}
                    </span>
                    <button 
                      @click="unlinkDatabase(db.name, link.domain)"
                      class="opacity-0 group-hover:opacity-100 text-surface-400 hover:text-red-500 transition-all"
                      title="Unlink"
                    >
                      <span class="material-symbols-rounded text-sm">close</span>
                    </button>
                  </div>
                </template>
                <!-- Guessed site (pattern-based) if no tracking links -->
                <template v-else-if="db.linked_site">
                  <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400" title="Guessed from naming pattern">
                    {{ db.linked_site }} ?
                  </span>
                </template>
                <span v-else class="text-surface-400">-</span>
              </div>
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <button 
                  v-if="!db.is_system"
                  @click="openLinkModal(db)"
                  class="btn-ghost btn-sm text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10"
                  title="Link to Site"
                >
                  <span class="material-symbols-rounded">link</span>
                </button>
                <button 
                  v-if="!db.is_system"
                  @click="deleteModal = { show: true, db }"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Delete"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!filteredDatabases.length">
            <td colspan="6" class="py-12 text-center text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">database</span>
              No databases found
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <Modal :show="createModal" title="Create Database" @close="createModal = false">
      <form @submit.prevent="createDatabase" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Database Name</label>
          <input
            v-model="newDb.name"
            type="text"
            class="input"
            placeholder="my_database"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Link to Site (optional)</label>
          <select v-model="newDb.domain" class="input">
            <option value="">-- No Site --</option>
            <option v-for="site in sites" :key="site.domain" :value="site.domain">
              {{ site.domain }}
            </option>
          </select>
          <p class="text-xs text-surface-500 mt-1">Link this database to a site for tracking</p>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Username (optional)</label>
          <input
            v-model="newDb.user"
            type="text"
            class="input"
            placeholder="db_user"
          />
          <p class="text-xs text-surface-500 mt-1">Leave empty to skip user creation</p>
        </div>

        <div v-if="newDb.user">
          <label class="block text-sm font-medium mb-2">Password</label>
          <div class="flex gap-2">
            <input
              v-model="newDb.password"
              type="text"
              class="input font-mono"
              placeholder="Enter or generate password"
            />
            <button type="button" @click="generatePassword" class="btn-secondary">
              <span class="material-symbols-rounded">casino</span>
            </button>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="createModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Create
          </button>
        </div>
      </form>
    </Modal>

    <!-- Link modal -->
    <Modal :show="linkModal.show" title="Link Database to Site" @close="linkModal = { show: false, db: null }">
      <form @submit.prevent="linkDatabase" class="space-y-4">
        <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-purple-600 dark:text-purple-400">database</span>
            </div>
            <div>
              <div class="font-medium">{{ linkModal.db?.name }}</div>
              <div class="text-sm text-surface-500">{{ linkModal.db?.size_human }}</div>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Link to Site</label>
          <select v-model="linkForm.domain" class="input" required>
            <option value="">-- Select Site --</option>
            <option v-for="site in sites" :key="site.domain" :value="site.domain">
              {{ site.domain }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Database User (for reference)</label>
          <input
            v-model="linkForm.db_user"
            type="text"
            class="input"
            placeholder="db_user"
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Notes (optional)</label>
          <input
            v-model="linkForm.notes"
            type="text"
            class="input"
            placeholder="e.g., WordPress main database"
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="linkModal = { show: false, db: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !linkForm.domain">
            <span v-if="submitting" class="spinner"></span>
            Link Database
          </button>
        </div>
      </form>
    </Modal>

    <!-- Orphans modal -->
    <Modal :show="orphansModal" title="Database Orphan Check" @close="orphansModal = false" size="lg">
      <div v-if="!orphanData" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>
      <div v-else class="space-y-6">
        <!-- Summary -->
        <div class="grid grid-cols-3 gap-4">
          <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-800 text-center">
            <div class="text-2xl font-semibold">{{ orphanData.total }}</div>
            <div class="text-sm text-surface-500">Total Databases</div>
          </div>
          <div class="p-4 rounded-xl bg-green-50 dark:bg-green-500/10 text-center">
            <div class="text-2xl font-semibold text-green-600 dark:text-green-400">{{ orphanData.linked_count }}</div>
            <div class="text-sm text-surface-500">Linked</div>
          </div>
          <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-500/10 text-center">
            <div class="text-2xl font-semibold text-amber-600 dark:text-amber-400">{{ orphanData.orphan_count }}</div>
            <div class="text-sm text-surface-500">Orphans</div>
          </div>
        </div>

        <!-- Auto-link button -->
        <div v-if="orphanData.orphan_count > 0" class="p-4 rounded-xl bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30">
          <div class="flex items-center justify-between">
            <div>
              <div class="font-medium text-blue-700 dark:text-blue-300">Auto-Link Databases</div>
              <div class="text-sm text-blue-600 dark:text-blue-400">
                Automatically link databases to sites based on naming patterns
              </div>
            </div>
            <button @click="autoLinkDatabases" class="btn-primary" :disabled="autoLinking">
              <span v-if="autoLinking" class="spinner"></span>
              <span class="material-symbols-rounded" v-else>auto_fix_high</span>
              Auto-Link
            </button>
          </div>
        </div>

        <!-- Orphan list -->
        <div v-if="orphanData.orphans?.length">
          <h3 class="font-medium mb-3">Orphan Databases ({{ orphanData.orphan_count }})</h3>
          <div class="space-y-2 max-h-64 overflow-y-auto">
            <div 
              v-for="db in orphanData.orphans" 
              :key="db.name"
              class="flex items-center justify-between p-3 rounded-lg bg-surface-50 dark:bg-surface-800"
            >
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-surface-400">database</span>
                <div>
                  <div class="font-medium">{{ db.name }}</div>
                  <div class="text-sm text-surface-500">
                    {{ db.size_human }} | {{ db.tables_count }} tables
                    <span v-if="db.guessed_site" class="ml-2 text-amber-600">
                      Might belong to: {{ db.guessed_site }}
                    </span>
                  </div>
                </div>
              </div>
              <button @click="openLinkModal(db); orphansModal = false" class="btn-secondary btn-sm">
                <span class="material-symbols-rounded">link</span>
                Link
              </button>
            </div>
          </div>
        </div>

        <!-- Linked list -->
        <div v-if="orphanData.linked?.length">
          <h3 class="font-medium mb-3 text-green-600 dark:text-green-400">Linked Databases ({{ orphanData.linked_count }})</h3>
          <div class="space-y-2 max-h-48 overflow-y-auto">
            <div 
              v-for="db in orphanData.linked" 
              :key="db.name"
              class="flex items-center justify-between p-3 rounded-lg bg-green-50 dark:bg-green-500/10"
            >
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-green-600 dark:text-green-400">check_circle</span>
                <div>
                  <div class="font-medium">{{ db.name }}</div>
                  <div class="text-sm text-surface-500">
                    {{ db.size_human }} | Linked to: {{ db.linked_to?.map(l => l.domain).join(', ') }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="flex justify-end pt-4">
          <button @click="orphansModal = false" class="btn-secondary">
            Close
          </button>
        </div>
      </div>
    </Modal>

    <!-- Delete modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete Database"
      :message="`Are you sure you want to delete '${deleteModal.db?.name}'? This action cannot be undone. A backup will be created.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      require-confirmation="DELETE"
      @confirm="deleteDatabase"
      @cancel="deleteModal = { show: false, db: null }"
    />
  </div>
</template>
