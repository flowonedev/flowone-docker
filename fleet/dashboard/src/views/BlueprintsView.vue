<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const toast = useToastStore()
const loading = ref(true)
const blueprints = ref([])

// Delete confirmation
const showDeleteModal = ref(false)
const blueprintToDelete = ref(null)
const deleting = ref(false)

const fetchBlueprints = async () => {
  loading.value = true
  try {
    const response = await api.get('/api/blueprints')
    blueprints.value = response.data
  } catch (error) {
    toast.error('Failed to load blueprints')
  } finally {
    loading.value = false
  }
}

const confirmDelete = (blueprint, event) => {
  event.preventDefault()
  event.stopPropagation()
  blueprintToDelete.value = blueprint
  showDeleteModal.value = true
}

const deleteBlueprint = async () => {
  if (!blueprintToDelete.value) return
  
  deleting.value = true
  try {
    await api.delete(`/api/blueprints/${blueprintToDelete.value.id}`)
    toast.success('Blueprint deleted successfully')
    blueprints.value = blueprints.value.filter(b => b.id !== blueprintToDelete.value.id)
    showDeleteModal.value = false
    blueprintToDelete.value = null
  } catch (error) {
    toast.error(error.message || 'Failed to delete blueprint')
  } finally {
    deleting.value = false
  }
}

onMounted(fetchBlueprints)
</script>

<template>
  <div class="animate-fadeIn">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Blueprints</h1>
      <RouterLink to="/blueprints/create" class="btn btn-primary">
        <span class="material-symbols-rounded">add</span>
        Create Snapshot
      </RouterLink>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="spinner w-10 h-10"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="blueprints.length === 0" class="card p-12 text-center">
      <span class="material-symbols-rounded text-6xl text-surface-400 mb-4">inventory_2</span>
      <h2 class="text-xl font-semibold mb-2">No blueprints yet</h2>
      <p class="text-surface-500 dark:text-surface-400 mb-6">Create a snapshot from your existing server configuration</p>
      <RouterLink to="/blueprints/create" class="btn btn-primary">
        <span class="material-symbols-rounded">camera</span>
        Create Server Snapshot
      </RouterLink>
    </div>

    <!-- Blueprints list -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <RouterLink
        v-for="blueprint in blueprints"
        :key="blueprint.id"
        :to="`/blueprints/${blueprint.id}`"
        class="card hover:border-primary-500/50 transition-colors group"
      >
        <div class="card-body">
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-primary-500/20 rounded-lg flex items-center justify-center">
                <span class="material-symbols-rounded text-primary-500">inventory_2</span>
              </div>
              <div>
                <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ blueprint.name }}</h3>
                <p class="text-sm text-surface-500 dark:text-surface-400">v{{ blueprint.version }}</p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="blueprint.is_default" class="badge badge-info">Default</span>
              <button 
                @click="confirmDelete(blueprint, $event)"
                class="p-1.5 rounded-lg opacity-0 group-hover:opacity-100 hover:bg-red-500/20 text-surface-400 hover:text-red-500 transition-all"
                title="Delete blueprint"
              >
                <span class="material-symbols-rounded text-lg">delete</span>
              </button>
            </div>
          </div>

          <p class="text-sm text-surface-500 dark:text-surface-400 mb-4 line-clamp-2">
            {{ blueprint.description || 'No description' }}
          </p>

          <div class="flex items-center justify-between text-xs text-surface-500 dark:text-surface-400">
            <span>{{ blueprint.template_count || 0 }} templates</span>
            <span>{{ blueprint.server_count || 0 }} servers</span>
          </div>
        </div>
      </RouterLink>
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
            <h3 class="text-lg font-semibold">Delete Blueprint</h3>
            <p class="text-sm text-surface-400">This action cannot be undone</p>
          </div>
        </div>
        
        <p class="mb-6 text-surface-300">
          Are you sure you want to delete <strong class="text-white">{{ blueprintToDelete?.name }}</strong>?
          This will remove all {{ blueprintToDelete?.template_count || 0 }} templates.
        </p>

        <div v-if="blueprintToDelete?.server_count > 0" class="mb-6 p-3 bg-amber-500/10 border border-amber-500/30 rounded-lg">
          <p class="text-sm text-amber-400 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">info</span>
            This blueprint is used by {{ blueprintToDelete.server_count }} server(s) and cannot be deleted.
          </p>
        </div>
        
        <div class="flex justify-end gap-3">
          <button @click="showDeleteModal = false" class="btn btn-ghost">Cancel</button>
          <button 
            @click="deleteBlueprint" 
            :disabled="deleting || blueprintToDelete?.server_count > 0" 
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
