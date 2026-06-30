<script setup>
/**
 * CrmUpdateComposer - Compose and push updates to the client portal
 * Used in the ClientSnapshot view when CRM Pro is enabled.
 * Supports title, content, update type, mood board linking, file attachments.
 */
import { ref, computed, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: Number, required: true }
})

const emit = defineEmits(['update-created'])
const toast = useToastStore()

// Updates list
const updates = ref([])
const loading = ref(false)
const showComposer = ref(false)

// Composer form
const form = ref({
  title: '',
  content_text: '',
  update_type: 'general',
  mood_board_id: null,
  board_id: null,
  board_card_id: null,
  drive_file_ids: []
})
const submitting = ref(false)

// Upload
const pendingFiles = ref([])
const uploadingFiles = ref(false)

const updateTypes = [
  { value: 'general', label: 'General Update', icon: 'update' },
  { value: 'design', label: 'Design Review', icon: 'palette' },
  { value: 'milestone', label: 'Milestone', icon: 'flag' },
  { value: 'deliverable', label: 'Deliverable', icon: 'package' }
]

watch(() => props.clientId, () => fetchUpdates(), { immediate: true })

async function fetchUpdates() {
  loading.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/portal/updates`)
    if (res.data?.success) {
      updates.value = res.data.data?.updates || []
    }
  } catch (e) {
    updates.value = []
  } finally {
    loading.value = false
  }
}

function resetForm() {
  form.value = {
    title: '',
    content_text: '',
    update_type: 'general',
    mood_board_id: null,
    board_id: null,
    board_card_id: null,
    drive_file_ids: []
  }
  pendingFiles.value = []
}

async function submitUpdate() {
  if (!form.value.title.trim()) {
    toast.error('Title is required')
    return
  }
  submitting.value = true
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/updates`, {
      title: form.value.title,
      content_text: form.value.content_text,
      content_html: null,
      update_type: form.value.update_type,
      mood_board_id: form.value.mood_board_id,
      board_id: form.value.board_id,
      board_card_id: form.value.board_card_id,
      drive_file_ids: form.value.drive_file_ids
    })

    if (res.data?.success) {
      const newUpdate = res.data.data

      // Upload pending files
      if (pendingFiles.value.length > 0 && newUpdate?.id) {
        uploadingFiles.value = true
        for (const file of pendingFiles.value) {
          const formData = new FormData()
          formData.append('file', file)
          try {
            await api.post(`/clients/${props.clientId}/portal/updates/${newUpdate.id}/files`, formData)
          } catch (e) {
            toast.error(`Failed to upload ${file.name}`)
          }
        }
        uploadingFiles.value = false
      }

      toast.success('Update pushed to client portal')
      showComposer.value = false
      resetForm()
      await fetchUpdates()
      emit('update-created', newUpdate)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create update')
  } finally {
    submitting.value = false
  }
}

function addFiles(e) {
  const files = Array.from(e.target.files || [])
  pendingFiles.value.push(...files)
}

function removeFile(idx) {
  pendingFiles.value.splice(idx, 1)
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <!-- Section Header -->
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">campaign</span>
        Portal Updates
        <span v-if="updates.length" class="text-xs font-normal text-surface-400">({{ updates.length }})</span>
      </h3>
      <button
        @click="showComposer = !showComposer; if (!showComposer) resetForm()"
        class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium 
               bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 
               hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors"
      >
        <span class="material-symbols-rounded text-sm">{{ showComposer ? 'close' : 'add' }}</span>
        {{ showComposer ? 'Cancel' : 'Push Update' }}
      </button>
    </div>

    <!-- Composer -->
    <div v-if="showComposer" class="mb-4 p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50 border border-surface-200 dark:border-surface-700 space-y-3">
      <!-- Type selector -->
      <div class="flex gap-2">
        <button 
          v-for="t in updateTypes" :key="t.value"
          @click="form.update_type = t.value"
          :class="['flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
            form.update_type === t.value 
              ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300'
              : 'border-surface-200 dark:border-surface-600 text-surface-500 hover:border-surface-300']"
        >
          <span class="material-symbols-rounded text-sm">{{ t.icon }}</span>
          {{ t.label }}
        </button>
      </div>

      <!-- Title -->
      <input 
        v-model="form.title"
        type="text"
        placeholder="Update title..."
        class="w-full px-3 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 
               bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
               focus:ring-2 focus:ring-primary-500 outline-none"
      />

      <!-- Content -->
      <textarea 
        v-model="form.content_text"
        rows="4"
        placeholder="Write your update... (this will be visible to the client)"
        class="w-full px-3 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 
               bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
               focus:ring-2 focus:ring-primary-500 outline-none resize-none"
      ></textarea>

      <!-- File attachments -->
      <div>
        <div class="flex items-center gap-2 mb-2">
          <label class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium border border-surface-200 dark:border-surface-600 
                        text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer transition-colors">
            <span class="material-symbols-rounded text-sm">attach_file</span>
            Attach Files
            <input type="file" multiple @change="addFiles" class="hidden" />
          </label>
        </div>
        <div v-if="pendingFiles.length > 0" class="space-y-1">
          <div v-for="(file, idx) in pendingFiles" :key="idx" 
               class="flex items-center gap-2 text-xs text-surface-600 dark:text-surface-300 bg-surface-100 dark:bg-surface-700 px-3 py-1.5 rounded-lg">
            <span class="material-symbols-rounded text-sm">description</span>
            <span class="flex-1 truncate">{{ file.name }}</span>
            <button @click="removeFile(idx)" class="text-red-400 hover:text-red-600">
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Submit -->
      <div class="flex justify-end">
        <button @click="submitUpdate" :disabled="submitting || !form.title.trim()"
                class="px-5 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium 
                       disabled:opacity-50 transition-colors flex items-center gap-2">
          <span v-if="submitting" class="animate-spin material-symbols-rounded text-sm">sync</span>
          <span class="material-symbols-rounded text-sm" v-else>send</span>
          {{ submitting ? 'Pushing...' : 'Push to Portal' }}
        </button>
      </div>
    </div>

    <!-- Recent updates list -->
    <div v-if="loading" class="flex justify-center py-4">
      <span class="material-symbols-rounded animate-spin text-surface-400">sync</span>
    </div>

    <div v-else-if="updates.length > 0" class="space-y-2">
      <div v-for="update in updates.slice(0, 5)" :key="update.id"
           class="flex items-center gap-3 p-3 rounded-lg bg-surface-50 dark:bg-surface-800/50 border border-surface-100 dark:border-surface-700/50">
        <div :class="['w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0',
          update.update_type === 'design' ? 'bg-purple-100 dark:bg-purple-500/20 text-purple-600 dark:text-purple-400' :
          update.update_type === 'milestone' ? 'bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400' :
          update.update_type === 'deliverable' ? 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400' :
          'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400']">
          <span class="material-symbols-rounded text-lg">
            {{ update.update_type === 'design' ? 'palette' : update.update_type === 'milestone' ? 'flag' : update.update_type === 'deliverable' ? 'package' : 'update' }}
          </span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate">{{ update.title }}</p>
          <div class="flex items-center gap-2 text-xs text-surface-400">
            <span>{{ formatDate(update.created_at) }}</span>
            <span v-if="update.comment_count > 0">· {{ update.comment_count }} comments</span>
            <span v-if="update.read_count !== undefined">· {{ update.read_count }}/{{ update.total_recipients }} read</span>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-4">
      <p class="text-xs text-surface-400">No updates pushed yet</p>
    </div>
  </div>
</template>

