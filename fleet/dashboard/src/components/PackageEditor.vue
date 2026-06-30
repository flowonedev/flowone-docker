<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const props = defineProps({
  blueprintId: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['updated'])

const toast = useToastStore()

// State
const loading = ref(true)
const saving = ref(false)
const packages = ref([])
const categories = ref([])
const activeCategory = ref('base')
const showAddModal = ref(false)
const editingPackage = ref(null)

// New package form
const newPackage = ref({
  category: 'base',
  package_name: '',
  version_constraint: '',
  is_required: true,
  pre_install_script: '',
  post_install_script: '',
})

// Computed
const packagesByCategory = computed(() => {
  const grouped = {}
  for (const cat of categories.value) {
    grouped[cat.key] = packages.value.filter(p => p.category === cat.key)
  }
  return grouped
})

const activePackages = computed(() => {
  return packagesByCategory.value[activeCategory.value] || []
})

const totalPackages = computed(() => packages.value.length)

// Methods
const loadData = async () => {
  loading.value = true
  try {
    const [packagesRes, categoriesRes] = await Promise.all([
      api.get(`/api/blueprints/${props.blueprintId}/packages`),
      api.get('/api/blueprints/package-categories'),
    ])
    
    packages.value = packagesRes.data.packages || []
    categories.value = categoriesRes.data || []
    
    // Set first category with packages as active
    if (categories.value.length > 0) {
      const firstWithPackages = categories.value.find(c => 
        packages.value.some(p => p.category === c.key)
      )
      if (firstWithPackages) {
        activeCategory.value = firstWithPackages.key
      }
    }
  } catch (error) {
    toast.error('Failed to load packages')
  } finally {
    loading.value = false
  }
}

const getCategoryIcon = (key) => {
  const icons = {
    'base': 'foundation',
    'web': 'dns',
    'php': 'code',
    'database': 'database',
    'mail': 'mail',
    'security': 'shield',
  }
  return icons[key] || 'package_2'
}

const getCategoryCount = (key) => {
  return (packagesByCategory.value[key] || []).length
}

const openAddModal = (category = null) => {
  newPackage.value = {
    category: category || activeCategory.value,
    package_name: '',
    version_constraint: '',
    is_required: true,
    pre_install_script: '',
    post_install_script: '',
  }
  editingPackage.value = null
  showAddModal.value = true
}

const openEditModal = (pkg) => {
  newPackage.value = { ...pkg }
  editingPackage.value = pkg
  showAddModal.value = true
}

const closeModal = () => {
  showAddModal.value = false
  editingPackage.value = null
}

const savePackage = async () => {
  if (!newPackage.value.package_name.trim()) {
    toast.error('Package name is required')
    return
  }

  saving.value = true
  try {
    if (editingPackage.value) {
      // Update existing
      await api.put(
        `/api/blueprints/${props.blueprintId}/packages/${editingPackage.value.id}`,
        newPackage.value
      )
      toast.success('Package updated')
    } else {
      // Add new
      await api.post(`/api/blueprints/${props.blueprintId}/packages/add`, newPackage.value)
      toast.success('Package added')
    }
    
    closeModal()
    await loadData()
    emit('updated')
  } catch (error) {
    toast.error(error.response?.data?.error || 'Failed to save package')
  } finally {
    saving.value = false
  }
}

const deletePackage = async (pkg) => {
  if (!confirm(`Delete package "${pkg.package_name}"?`)) return

  try {
    await api.delete(`/api/blueprints/${props.blueprintId}/packages/${pkg.id}`)
    toast.success('Package deleted')
    await loadData()
    emit('updated')
  } catch (error) {
    toast.error('Failed to delete package')
  }
}

const importDefaults = async (category = null) => {
  try {
    const response = await api.post(`/api/blueprints/${props.blueprintId}/packages/import-defaults`, {
      category
    })
    toast.success(`Imported ${response.data.imported} packages`)
    await loadData()
    emit('updated')
  } catch (error) {
    toast.error('Failed to import packages')
  }
}

watch(() => props.blueprintId, () => {
  loadData()
})

onMounted(loadData)
</script>

<template>
  <div class="space-y-6">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="spinner w-8 h-8"></div>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h3 class="text-lg font-semibold">Packages</h3>
          <p class="text-sm text-surface-500 dark:text-surface-400">{{ totalPackages }} packages defined</p>
        </div>
        <div class="flex items-center gap-2">
          <button @click="importDefaults()" class="btn btn-secondary btn-sm">
            <span class="material-symbols-rounded">download</span>
            Import Defaults
          </button>
          <button @click="openAddModal()" class="btn btn-primary btn-sm">
            <span class="material-symbols-rounded">add</span>
            Add Package
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Categories sidebar -->
        <div class="space-y-2">
          <button
            v-for="cat in categories"
            :key="cat.key"
            @click="activeCategory = cat.key"
            :class="[
              'w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-colors',
              activeCategory === cat.key 
                ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400' 
                : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300'
            ]"
          >
            <span class="material-symbols-rounded">{{ getCategoryIcon(cat.key) }}</span>
            <span class="flex-1">{{ cat.name }}</span>
            <span class="text-xs bg-surface-200 dark:bg-surface-700 px-2 py-0.5 rounded-full">
              {{ getCategoryCount(cat.key) }}
            </span>
          </button>
        </div>

        <!-- Packages list -->
        <div class="lg:col-span-3">
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold capitalize">
                {{ categories.find(c => c.key === activeCategory)?.name || activeCategory }} Packages
              </h2>
              <button @click="openAddModal(activeCategory)" class="btn btn-primary btn-sm">
                <span class="material-symbols-rounded">add</span>
                Add
              </button>
            </div>
            <div class="card-body p-0">
              <div 
                v-if="activePackages.length === 0" 
                class="p-8 text-center"
              >
                <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">package_2</span>
                <p class="text-surface-500 dark:text-surface-400">No packages in this category</p>
                <button 
                  @click="importDefaults(activeCategory)" 
                  class="btn btn-secondary btn-sm mt-4"
                >
                  Import Default {{ categories.find(c => c.key === activeCategory)?.name }} Packages
                </button>
              </div>
              <div v-else class="divide-y divide-surface-200 dark:divide-surface-700">
                <div 
                  v-for="pkg in activePackages" 
                  :key="pkg.id"
                  class="p-4 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
                >
                  <div class="flex items-start justify-between">
                    <div class="flex-1">
                      <div class="flex items-center gap-2">
                        <p class="font-medium font-mono text-surface-900 dark:text-surface-100">{{ pkg.package_name }}</p>
                        <span v-if="pkg.version_constraint" class="badge badge-neutral text-xs">
                          {{ pkg.version_constraint }}
                        </span>
                        <span v-if="!pkg.is_required" class="badge badge-warning text-xs">
                          Optional
                        </span>
                      </div>
                      <div class="flex items-center gap-4 mt-1 text-xs text-surface-500 dark:text-surface-400">
                        <span v-if="pkg.pre_install_script" class="flex items-center gap-1">
                          <span class="material-symbols-rounded text-sm">terminal</span>
                          Pre-install
                        </span>
                        <span v-if="pkg.post_install_script" class="flex items-center gap-1">
                          <span class="material-symbols-rounded text-sm">terminal</span>
                          Post-install
                        </span>
                        <span class="flex items-center gap-1">
                          <span class="material-symbols-rounded text-sm">reorder</span>
                          Order: {{ pkg.install_order }}
                        </span>
                      </div>
                    </div>
                    <div class="flex items-center gap-1">
                      <button @click="openEditModal(pkg)" class="btn btn-ghost btn-sm">
                        <span class="material-symbols-rounded">edit</span>
                      </button>
                      <button @click="deletePackage(pkg)" class="btn btn-ghost btn-sm text-red-600 dark:text-red-400">
                        <span class="material-symbols-rounded">delete</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Add/Edit Modal -->
    <Teleport to="body">
      <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-black/40 dark:bg-black/60 backdrop-blur-sm" @click="closeModal"></div>
        
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg mx-4 border border-surface-200 dark:border-surface-700">
          <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
            <h2 class="text-lg font-semibold">
              {{ editingPackage ? 'Edit Package' : 'Add Package' }}
            </h2>
            <button @click="closeModal" class="btn btn-ghost btn-sm">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>

          <div class="p-6 space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Category</label>
              <select v-model="newPackage.category" class="input w-full">
                <option v-for="cat in categories" :key="cat.key" :value="cat.key">
                  {{ cat.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Package Name</label>
              <input 
                v-model="newPackage.package_name" 
                type="text" 
                class="input w-full font-mono"
                placeholder="e.g. nginx, php8.3-fpm"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Version Constraint (optional)</label>
              <input 
                v-model="newPackage.version_constraint" 
                type="text" 
                class="input w-full"
                placeholder="e.g. 8.3, >=10.4"
              />
            </div>

            <div class="flex items-center gap-3">
              <input 
                type="checkbox" 
                v-model="newPackage.is_required" 
                id="is_required"
                class="toggle toggle-primary" 
              />
              <label for="is_required" class="text-sm">Required package</label>
            </div>

            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Pre-install Script (optional)</label>
              <textarea 
                v-model="newPackage.pre_install_script" 
                rows="3" 
                class="input w-full font-mono text-sm"
                placeholder="# Commands to run before installing"
              ></textarea>
            </div>

            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Post-install Script (optional)</label>
              <textarea 
                v-model="newPackage.post_install_script" 
                rows="3" 
                class="input w-full font-mono text-sm"
                placeholder="# Commands to run after installing"
              ></textarea>
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50 rounded-b-2xl">
            <button @click="closeModal" class="btn btn-ghost">Cancel</button>
            <button @click="savePackage" :disabled="saving" class="btn btn-primary">
              <span v-if="saving" class="spinner w-4 h-4"></span>
              {{ editingPackage ? 'Update' : 'Add' }} Package
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.toggle {
  @apply relative inline-flex h-6 w-11 items-center rounded-full bg-surface-300 dark:bg-surface-600 transition-colors cursor-pointer;
}

.toggle:checked {
  @apply bg-primary-500;
}

.toggle::after {
  content: '';
  @apply absolute left-1 h-4 w-4 rounded-full bg-white transition-transform shadow-sm;
}

.toggle:checked::after {
  @apply translate-x-5;
}
</style>

