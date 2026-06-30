<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const router = useRouter()
const toast = useToastStore()
const auth = useAuthStore()

const loading = ref(true)
const users = ref([])
const sites = ref([])
const createModal = ref(false)
const editModal = ref({ show: false, user: null })
const deleteModal = ref({ show: false, user: null })
const sitesModal = ref({ show: false, user: null, sites: [] })
const submitting = ref(false)

// Filters
const searchQuery = ref('')
const filterRole = ref('all')
const filterStatus = ref('all')

const newUser = ref({
  username: '',
  password: '',
  email: '',
  role: 'user',
  status: 'active',
  sites: []
})

const filteredUsers = computed(() => {
  let result = [...users.value]
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(user => 
      user.username.toLowerCase().includes(query) ||
      (user.email && user.email.toLowerCase().includes(query))
    )
  }
  
  if (filterRole.value !== 'all') {
    result = result.filter(user => user.role === filterRole.value)
  }
  
  if (filterStatus.value !== 'all') {
    result = result.filter(user => user.status === filterStatus.value)
  }
  
  return result
})

const stats = computed(() => ({
  total: users.value.length,
  superAdmins: users.value.filter(u => u.role === 'super_admin').length,
  admins: users.value.filter(u => u.role === 'admin').length,
  regularUsers: users.value.filter(u => u.role === 'user').length,
  suspended: users.value.filter(u => u.status === 'suspended').length
}))

const availableRoles = computed(() => {
  if (auth.isSuperAdmin) return [
    { value: 'user', label: 'User' },
    { value: 'admin', label: 'Admin' },
    { value: 'super_admin', label: 'Super Admin' },
  ]
  return [
    { value: 'user', label: 'User' },
    { value: 'admin', label: 'Admin' },
  ]
})

function getRoleLabel(role) {
  switch (role) {
    case 'super_admin': return 'Super Admin'
    case 'admin': return 'Admin'
    default: return 'User'
  }
}

function getRoleBadgeClass(role) {
  switch (role) {
    case 'super_admin': return 'badge-primary'
    case 'admin': return 'badge-warning'
    default: return 'badge-info'
  }
}

const fetchUsers = async () => {
  try {
    const response = await api.get('/users')
    if (response.data.success) {
      users.value = response.data.data.users || []
    }
  } catch (e) {
    toast.error('Failed to load users')
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
    // Silently fail - sites are optional for user management
  }
}

const createUser = async () => {
  submitting.value = true
  try {
    const response = await api.post('/users', newUser.value)
    if (response.data.success) {
      toast.success('User created successfully')
      createModal.value = false
      resetNewUser()
      await fetchUsers()
    } else {
      toast.error(response.data.error || 'Failed to create user')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create user')
  } finally {
    submitting.value = false
  }
}

const updateUser = async () => {
  if (!editModal.value.user) return
  
  submitting.value = true
  try {
    const payload = {
      email: editModal.value.user.email,
      role: editModal.value.user.role,
      status: editModal.value.user.status,
    }
    
    // Only include password if set
    if (editModal.value.user.newPassword) {
      payload.password = editModal.value.user.newPassword
    }
    
    const response = await api.put(`/users/${editModal.value.user.id}`, payload)
    if (response.data.success) {
      // Update sites if user is not super_admin
      if (editModal.value.user.role === 'user') {
        await api.put(`/users/${editModal.value.user.id}/sites`, {
          sites: editModal.value.user.assignedSites || []
        })
      }
      
      toast.success('User updated successfully')
      editModal.value = { show: false, user: null }
      await fetchUsers()
    } else {
      toast.error(response.data.error || 'Failed to update user')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update user')
  } finally {
    submitting.value = false
  }
}

const deleteUser = async () => {
  if (!deleteModal.value.user) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/users/${deleteModal.value.user.id}`)
    if (response.data.success) {
      toast.success('User deleted successfully')
      deleteModal.value = { show: false, user: null }
      await fetchUsers()
    } else {
      toast.error(response.data.error || 'Failed to delete user')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete user')
  } finally {
    submitting.value = false
  }
}

const openEditModal = async (user) => {
  editModal.value = {
    show: true,
    user: { ...user, newPassword: '', assignedSites: [] }
  }
  
  // Load user's assigned sites
  try {
    const response = await api.get(`/users/${user.id}/sites`)
    if (response.data.success) {
      editModal.value.user.assignedSites = response.data.data.sites.map(s => s.domain)
    }
  } catch (e) {
    // Silently fail
  }
}

const openSitesModal = async (user) => {
  sitesModal.value = {
    show: true,
    user: user,
    sites: []
  }
  
  try {
    const response = await api.get(`/users/${user.id}/sites`)
    if (response.data.success) {
      sitesModal.value.sites = response.data.data.sites.map(s => s.domain)
    }
  } catch (e) {
    toast.error('Failed to load user sites')
  }
}

const updateUserSites = async () => {
  if (!sitesModal.value.user) return
  
  submitting.value = true
  try {
    const response = await api.put(`/users/${sitesModal.value.user.id}/sites`, {
      sites: sitesModal.value.sites
    })
    if (response.data.success) {
      toast.success('Sites updated successfully')
      sitesModal.value = { show: false, user: null, sites: [] }
      await fetchUsers()
    } else {
      toast.error(response.data.error || 'Failed to update sites')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update sites')
  } finally {
    submitting.value = false
  }
}

const toggleSite = (domain) => {
  const index = sitesModal.value.sites.indexOf(domain)
  if (index > -1) {
    sitesModal.value.sites.splice(index, 1)
  } else {
    sitesModal.value.sites.push(domain)
  }
}

const resetNewUser = () => {
  newUser.value = {
    username: '',
    password: '',
    email: '',
    role: 'user',
    status: 'active',
    sites: []
  }
}

const formatDate = (date) => {
  if (!date) return 'Never'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

onMounted(() => {
  fetchUsers()
  fetchSites()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Users</h1>
        <p class="text-surface-500 text-sm mt-1">Manage panel users and their permissions</p>
      </div>
      <button @click="createModal = true" class="btn-primary">
        <span class="material-symbols-rounded">person_add</span>
        New User
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6">
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Total Users</p>
        <p class="stat-value">{{ stats.total }}</p>
      </div>
      <div v-if="auth.isSuperAdmin" class="stat-card">
        <p class="text-surface-500 text-sm">Super Admins</p>
        <p class="stat-value text-primary-600">{{ stats.superAdmins }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Admins</p>
        <p class="stat-value text-amber-600">{{ stats.admins }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Regular Users</p>
        <p class="stat-value">{{ stats.regularUsers }}</p>
      </div>
      <div class="stat-card">
        <p class="text-surface-500 text-sm">Suspended</p>
        <p class="stat-value text-red-600">{{ stats.suspended }}</p>
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
            placeholder="Search by username or email..."
          />
        </div>
        
        <select v-model="filterRole" class="input w-auto">
          <option value="all">All Roles</option>
          <option v-for="r in availableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        
        <select v-model="filterStatus" class="input w-auto">
          <option value="all">All Status</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Users table -->
    <div v-else class="card overflow-hidden">
      <div class="table-responsive">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th>User</th>
            <th>Role</th>
            <th>Sites</th>
            <th>Status</th>
            <th>Last Login</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in filteredUsers" :key="user.id">
            <td>
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                  <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">
                    {{ user.role === 'super_admin' ? 'admin_panel_settings' : 'person' }}
                  </span>
                </div>
                <div>
                  <p class="font-medium">{{ user.username }}</p>
                  <p class="text-sm text-surface-500">{{ user.email || 'No email' }}</p>
                </div>
              </div>
            </td>
            <td>
              <span 
                :class="['badge', getRoleBadgeClass(user.role)]"
              >
                {{ getRoleLabel(user.role) }}
              </span>
            </td>
            <td>
              <button 
                @click="openSitesModal(user)"
                class="flex items-center gap-1 text-sm text-primary-500 hover:text-primary-600"
              >
                <span class="material-symbols-rounded text-lg">language</span>
                {{ user.site_count || 0 }} sites
              </button>
            </td>
            <td>
              <StatusBadge :status="user.status" />
            </td>
            <td>
              <span class="text-sm text-surface-500">{{ formatDate(user.last_login) }}</span>
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <button 
                  @click="openEditModal(user)"
                  class="btn-ghost btn-sm text-primary-500"
                  title="Edit user"
                >
                  <span class="material-symbols-rounded">edit</span>
                </button>
                <button 
                  @click="deleteModal = { show: true, user }"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Delete user"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!filteredUsers.length">
            <td colspan="6" class="py-12 text-center text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">group</span>
              No users found
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <!-- Create modal -->
    <Modal :show="createModal" title="Create New User" @close="createModal = false">
      <form @submit.prevent="createUser" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Username</label>
          <input
            v-model="newUser.username"
            type="text"
            class="input"
            placeholder="username"
            required
            pattern="[a-zA-Z0-9_]{3,50}"
          />
          <p class="text-xs text-surface-500 mt-1">3-50 characters, alphanumeric and underscores only</p>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Password</label>
          <input
            v-model="newUser.password"
            type="password"
            class="input"
            placeholder="********"
            required
            minlength="8"
          />
          <p class="text-xs text-surface-500 mt-1">Minimum 8 characters</p>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Email (optional)</label>
          <input
            v-model="newUser.email"
            type="email"
            class="input"
            placeholder="user@example.com"
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Role</label>
          <select v-model="newUser.role" class="input">
            <option v-for="r in availableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </div>

        <!-- Site selection for regular users -->
        <div v-if="newUser.role === 'user'">
          <label class="block text-sm font-medium mb-2">Assigned Site</label>
          <p class="text-xs text-surface-500 mb-3">Select which site this user can access:</p>
          
          <div v-if="sites.length === 0" class="text-center py-4 text-surface-400 bg-surface-50 dark:bg-surface-800 rounded-xl">
            No sites available
          </div>
          
          <div v-else class="max-h-[200px] overflow-y-auto space-y-2 border border-surface-200 dark:border-surface-700 rounded-xl p-3">
            <label
              v-for="site in sites"
              :key="site.domain"
              class="flex items-center gap-3 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 cursor-pointer transition-colors"
              :class="newUser.sites.includes(site.domain) && 'bg-primary-50 dark:bg-primary-500/10'"
            >
              <input
                type="radio"
                :value="site.domain"
                :checked="newUser.sites.includes(site.domain)"
                @change="newUser.sites = [site.domain]"
                class="sr-only"
                name="newUserSite"
              />
              <div 
                class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors"
                :class="newUser.sites.includes(site.domain) 
                  ? 'bg-primary-500 border-primary-500' 
                  : 'border-surface-300 dark:border-surface-600'"
              >
                <span 
                  v-if="newUser.sites.includes(site.domain)" 
                  class="w-2 h-2 rounded-full bg-white"
                ></span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-sm truncate">{{ site.domain }}</p>
              </div>
            </label>
          </div>
          
          <p v-if="newUser.sites.length === 0" class="text-xs text-amber-600 dark:text-amber-400 mt-2">
            <span class="material-symbols-rounded text-sm align-middle">warning</span>
            User will not have access to any site if none selected
          </p>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="createModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Create User
          </button>
        </div>
      </form>
    </Modal>

    <!-- Edit modal -->
    <Modal :show="editModal.show" title="Edit User" @close="editModal = { show: false, user: null }">
      <form v-if="editModal.user" @submit.prevent="updateUser" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Username</label>
          <input
            :value="editModal.user.username"
            type="text"
            class="input bg-surface-100 dark:bg-surface-700"
            disabled
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">New Password (leave empty to keep current)</label>
          <input
            v-model="editModal.user.newPassword"
            type="password"
            class="input"
            placeholder="********"
            minlength="8"
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Email</label>
          <input
            v-model="editModal.user.email"
            type="email"
            class="input"
            placeholder="user@example.com"
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Role</label>
          <select v-model="editModal.user.role" class="input">
            <option v-for="r in availableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Status</label>
          <select v-model="editModal.user.status" class="input">
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>

        <!-- Site selection for regular users -->
        <div v-if="editModal.user.role === 'user'">
          <label class="block text-sm font-medium mb-2">Assigned Site</label>
          <p class="text-xs text-surface-500 mb-3">Select which site this user can access:</p>
          
          <div v-if="sites.length === 0" class="text-center py-4 text-surface-400 bg-surface-50 dark:bg-surface-800 rounded-xl">
            No sites available
          </div>
          
          <div v-else class="max-h-[200px] overflow-y-auto space-y-2 border border-surface-200 dark:border-surface-700 rounded-xl p-3">
            <label
              v-for="site in sites"
              :key="site.domain"
              class="flex items-center gap-3 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 cursor-pointer transition-colors"
              :class="editModal.user.assignedSites?.includes(site.domain) && 'bg-primary-50 dark:bg-primary-500/10'"
            >
              <input
                type="radio"
                :value="site.domain"
                :checked="editModal.user.assignedSites?.includes(site.domain)"
                @change="editModal.user.assignedSites = [site.domain]"
                class="sr-only"
                name="editUserSite"
              />
              <div 
                class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors"
                :class="editModal.user.assignedSites?.includes(site.domain) 
                  ? 'bg-primary-500 border-primary-500' 
                  : 'border-surface-300 dark:border-surface-600'"
              >
                <span 
                  v-if="editModal.user.assignedSites?.includes(site.domain)" 
                  class="w-2 h-2 rounded-full bg-white"
                ></span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-sm truncate">{{ site.domain }}</p>
              </div>
            </label>
          </div>
          
          <p v-if="!editModal.user.assignedSites?.length" class="text-xs text-amber-600 dark:text-amber-400 mt-2">
            <span class="material-symbols-rounded text-sm align-middle">warning</span>
            User will not have access to any site if none selected
          </p>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="editModal = { show: false, user: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </form>
    </Modal>

    <!-- Sites modal -->
    <Modal :show="sitesModal.show" title="Assign Sites" @close="sitesModal = { show: false, user: null, sites: [] }">
      <div v-if="sitesModal.user" class="space-y-4">
        <p class="text-sm text-surface-500">
          Select which sites <strong>{{ sitesModal.user.username }}</strong> can access:
        </p>
        
        <div v-if="sites.length === 0" class="text-center py-8 text-surface-400">
          No sites available
        </div>
        
        <div v-else class="max-h-[400px] overflow-y-auto space-y-2">
          <label
            v-for="site in sites"
            :key="site.domain"
            class="flex items-center gap-3 p-3 rounded-xl border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-800 cursor-pointer transition-colors"
            :class="sitesModal.sites.includes(site.domain) && 'bg-primary-50 dark:bg-primary-500/10 border-primary-300 dark:border-primary-500/30'"
          >
            <input
              type="checkbox"
              :checked="sitesModal.sites.includes(site.domain)"
              @change="toggleSite(site.domain)"
              class="sr-only"
            />
            <div 
              class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors"
              :class="sitesModal.sites.includes(site.domain) 
                ? 'bg-primary-500 border-primary-500' 
                : 'border-surface-300 dark:border-surface-600'"
            >
              <span 
                v-if="sitesModal.sites.includes(site.domain)" 
                class="material-symbols-rounded text-white text-sm"
              >check</span>
            </div>
            <div class="flex-1">
              <p class="font-medium">{{ site.domain }}</p>
              <p class="text-xs text-surface-500">{{ site.document_root }}</p>
            </div>
          </label>
        </div>

        <div class="flex justify-between items-center pt-4 border-t border-surface-200 dark:border-surface-700">
          <p class="text-sm text-surface-500">
            {{ sitesModal.sites.length }} site(s) selected
          </p>
          <div class="flex gap-3">
            <button @click="sitesModal = { show: false, user: null, sites: [] }" class="btn-secondary">
              Cancel
            </button>
            <button @click="updateUserSites" class="btn-primary" :disabled="submitting">
              <span v-if="submitting" class="spinner"></span>
              Save
            </button>
          </div>
        </div>
      </div>
    </Modal>

    <!-- Delete modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete User"
      :message="`Are you sure you want to delete user '${deleteModal.user?.username}'? This action cannot be undone.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deleteUser"
      @cancel="deleteModal = { show: false, user: null }"
    />
  </div>
</template>

