<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const toast = useToastStore()

const loading = ref(true)
const status = ref(null)
const zones = ref([])
const selectedZone = ref(null)
const records = ref([])
const createRecordModal = ref(false)
const deleteModal = ref({ show: false, record: null })
const submitting = ref(false)

const newRecord = ref({
  name: '',
  type: 'A',
  content: '',
  ttl: 3600
})

const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA']

const fetchStatus = async () => {
  try {
    const response = await api.get('/dns/status')
    if (response.data.success) {
      status.value = response.data.data
    }
  } catch (e) {
    console.error(e)
  }
}

const fetchZones = async () => {
  try {
    const response = await api.get('/dns/zones')
    if (response.data.success) {
      zones.value = response.data.data.zones || []
    }
  } catch (e) {
    toast.error('Failed to load DNS zones')
  } finally {
    loading.value = false
  }
}

const selectZone = async (zone) => {
  selectedZone.value = zone
  
  try {
    const response = await api.get(`/dns/zones/${zone.name}/records`)
    if (response.data.success) {
      records.value = response.data.data.records || []
    }
  } catch (e) {
    toast.error('Failed to load DNS records')
  }
}

const addRecord = async () => {
  submitting.value = true
  try {
    const response = await api.post('/dns/records', {
      zone: selectedZone.value.name,
      ...newRecord.value
    })
    
    if (response.data.success) {
      toast.success('Record added')
      createRecordModal.value = false
      newRecord.value = { name: '', type: 'A', content: '', ttl: 3600 }
      await selectZone(selectedZone.value)
    } else {
      toast.error(response.data.error || 'Failed to add record')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add record')
  } finally {
    submitting.value = false
  }
}

const deleteRecord = async () => {
  if (!deleteModal.value.record) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/dns/records/${deleteModal.value.record.id}`)
    if (response.data.success) {
      toast.success('Record deleted')
      deleteModal.value = { show: false, record: null }
      await selectZone(selectedZone.value)
    } else {
      toast.error(response.data.error || 'Failed to delete record')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete record')
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await Promise.all([fetchStatus(), fetchZones()])
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">DNS</h1>
        <p class="text-surface-500 text-sm mt-1">Manage DNS zones and records</p>
      </div>
      <div class="flex items-center gap-3">
        <StatusBadge :status="status?.running ? 'running' : 'stopped'" />
        <span class="text-sm text-surface-500">{{ status?.zone_count || 0 }} zones</span>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Content -->
    <div v-else class="grid grid-cols-1 lg:grid-cols-4 gap-6">
      <!-- Zones list -->
      <div class="card">
        <div class="card-header">
          <h3 class="font-medium">Zones</h3>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-800">
          <button
            v-for="zone in zones"
            :key="zone.id"
            @click="selectZone(zone)"
            :class="[
              'w-full px-4 py-3 flex items-center justify-between text-left transition-colors',
              selectedZone?.id === zone.id 
                ? 'bg-primary-50 dark:bg-primary-500/10' 
                : 'hover:bg-surface-50 dark:hover:bg-surface-800'
            ]"
          >
            <div>
              <p class="font-medium">{{ zone.name }}</p>
              <p class="text-sm text-surface-500">{{ zone.record_count }} records</p>
            </div>
            <span class="material-symbols-rounded text-surface-400">chevron_right</span>
          </button>
          <div v-if="!zones.length" class="px-4 py-8 text-center text-surface-400">
            No zones
          </div>
        </div>
      </div>

      <!-- Records -->
      <div class="lg:col-span-3">
        <template v-if="selectedZone">
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h3 class="font-medium">{{ selectedZone.name }}</h3>
              <button @click="createRecordModal = true" class="btn-primary btn-sm">
                <span class="material-symbols-rounded">add</span>
                Add Record
              </button>
            </div>
            
            <div class="overflow-x-auto">
              <table class="table">
                <thead>
                  <tr class="bg-surface-50 dark:bg-surface-800/50">
                    <th>Name</th>
                    <th>Type</th>
                    <th>Content</th>
                    <th>TTL</th>
                    <th class="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="record in records" :key="record.id">
                    <td class="font-mono text-sm">{{ record.name }}</td>
                    <td><span class="badge badge-info">{{ record.type }}</span></td>
                    <td class="font-mono text-sm max-w-xs truncate" :title="record.content">
                      {{ record.content }}
                    </td>
                    <td class="text-surface-500">{{ record.ttl }}</td>
                    <td class="text-right">
                      <button
                        v-if="record.type !== 'SOA'"
                        @click="deleteModal = { show: true, record }"
                        class="btn-ghost btn-sm text-red-500"
                      >
                        <span class="material-symbols-rounded">delete</span>
                      </button>
                    </td>
                  </tr>
                  <tr v-if="!records.length">
                    <td colspan="5" class="py-8 text-center text-surface-400">
                      No records
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>

        <div v-else class="card p-12 text-center text-surface-400">
          <span class="material-symbols-rounded text-4xl mb-2 block">dns</span>
          Select a zone to view records
        </div>
      </div>
    </div>

    <!-- Add record modal -->
    <Modal :show="createRecordModal" title="Add DNS Record" @close="createRecordModal = false">
      <form @submit.prevent="addRecord" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Name</label>
          <input
            v-model="newRecord.name"
            type="text"
            class="input font-mono"
            :placeholder="selectedZone?.name"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Type</label>
          <select v-model="newRecord.type" class="input">
            <option v-for="type in recordTypes" :key="type" :value="type">{{ type }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Content</label>
          <input
            v-model="newRecord.content"
            type="text"
            class="input font-mono"
            placeholder="1.2.3.4"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">TTL</label>
          <input
            v-model.number="newRecord.ttl"
            type="number"
            class="input"
            min="60"
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="createRecordModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Add Record
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete record modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete DNS Record"
      :message="`Are you sure you want to delete the ${deleteModal.record?.type} record for '${deleteModal.record?.name}'?`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      require-confirmation="DELETE"
      @confirm="deleteRecord"
      @cancel="deleteModal = { show: false, record: null }"
    />
  </div>
</template>
