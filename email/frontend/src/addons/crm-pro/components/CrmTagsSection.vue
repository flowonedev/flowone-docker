<script setup>
/**
 * CrmTagsSection - Tags and custom fields management for a client
 * Used in ClientSnapshot. Allows assigning/removing tags and editing custom field values.
 */
import { ref, watch, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: Number, required: true },
})

const toast = useToastStore()

// Tags
const allTags = ref([])
const clientTags = ref([])
const loadingTags = ref(false)
const showTagPicker = ref(false)
const newTagName = ref('')
const newTagColor = ref('#6366f1')
const newTagGroup = ref('')
const showNewTag = ref(false)

// Custom Fields
const customFields = ref([])
const loadingFields = ref(false)
const showFieldManager = ref(false)
const newFieldName = ref('')
const newFieldType = ref('text')

const tagColors = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16']

const availableTags = computed(() => {
  const assignedIds = new Set(clientTags.value.map(t => t.id))
  return allTags.value.filter(t => !assignedIds.has(t.id))
})

watch(() => props.clientId, () => {
  fetchClientTags()
  fetchAllTags()
  fetchCustomFields()
}, { immediate: true })

// =========================================================================
// Tags
// =========================================================================

async function fetchAllTags() {
  try {
    const res = await api.get('/crm/tags')
    if (res.data?.success) allTags.value = res.data.data?.tags || []
  } catch (e) { allTags.value = [] }
}

async function fetchClientTags() {
  loadingTags.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/tags`)
    if (res.data?.success) clientTags.value = res.data.data?.tags || []
  } catch (e) { clientTags.value = [] }
  loadingTags.value = false
}

async function assignTag(tag) {
  try {
    await api.post(`/clients/${props.clientId}/tags`, { tag_id: tag.id })
    clientTags.value.push(tag)
    showTagPicker.value = false
  } catch (e) {
    toast.error('Failed to assign tag')
  }
}

async function removeTag(tag) {
  try {
    await api.delete(`/clients/${props.clientId}/tags/${tag.id}`)
    clientTags.value = clientTags.value.filter(t => t.id !== tag.id)
  } catch (e) {
    toast.error('Failed to remove tag')
  }
}

async function createAndAssignTag() {
  if (!newTagName.value.trim()) return
  try {
    const res = await api.post('/crm/tags', {
      name: newTagName.value.trim(),
      color: newTagColor.value,
      tag_group: newTagGroup.value || null,
    })
    if (res.data?.success) {
      const tag = res.data.data
      allTags.value.push(tag)
      await assignTag(tag)
      newTagName.value = ''
      showNewTag.value = false
      toast.success('Tag created and assigned')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create tag')
  }
}

// =========================================================================
// Custom Fields
// =========================================================================

async function fetchCustomFields() {
  loadingFields.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/custom-fields`)
    if (res.data?.success) customFields.value = res.data.data?.fields || []
  } catch (e) { customFields.value = [] }
  loadingFields.value = false
}

async function saveFieldValue(field) {
  try {
    await api.put(`/clients/${props.clientId}/custom-fields`, {
      values: { [field.id]: field.field_value ?? '' }
    })
  } catch (e) {
    toast.error('Failed to save field')
  }
}

async function createField() {
  if (!newFieldName.value.trim()) return
  try {
    await api.post('/crm/custom-fields', {
      field_name: newFieldName.value.trim(),
      field_type: newFieldType.value,
    })
    newFieldName.value = ''
    newFieldType.value = 'text'
    showFieldManager.value = false
    fetchCustomFields()
    toast.success('Custom field created')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create field')
  }
}

async function deleteField(field) {
  if (!confirm(`Delete custom field "${field.field_name}"? All values will be lost.`)) return
  try {
    await api.delete(`/crm/custom-fields/${field.id}`)
    fetchCustomFields()
    toast.success('Field deleted')
  } catch (e) {
    toast.error('Failed to delete field')
  }
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <!-- Tags Section -->
    <div class="mb-4">
      <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-500">label</span>
          Tags
        </h3>
        <button @click="showTagPicker = !showTagPicker"
                class="text-xs text-primary-600 hover:text-primary-700 font-medium flex items-center gap-0.5">
          <span class="material-symbols-rounded text-sm">add</span> Add
        </button>
      </div>

      <!-- Assigned Tags -->
      <div class="flex flex-wrap gap-1.5 mb-2">
        <span v-for="tag in clientTags" :key="tag.id"
              class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium text-white cursor-pointer hover:opacity-80"
              :style="{ backgroundColor: tag.color || '#6366f1' }"
              @click="removeTag(tag)" title="Click to remove">
          {{ tag.name }}
          <span class="material-symbols-rounded text-xs opacity-70">close</span>
        </span>
        <span v-if="!clientTags.length && !loadingTags" class="text-xs text-surface-400">No tags assigned</span>
      </div>

      <!-- Tag Picker -->
      <div v-if="showTagPicker" class="p-3 bg-surface-50 dark:bg-surface-800/50 rounded-lg space-y-2">
        <div v-if="availableTags.length" class="flex flex-wrap gap-1.5">
          <button v-for="tag in availableTags" :key="tag.id"
                  @click="assignTag(tag)"
                  class="px-2.5 py-1 rounded-full text-xs font-medium text-white hover:opacity-80 transition-opacity"
                  :style="{ backgroundColor: tag.color || '#6366f1' }">
            {{ tag.name }}
          </button>
        </div>
        <p v-else class="text-xs text-surface-400">No more tags available</p>

        <!-- Create new tag inline -->
        <div v-if="!showNewTag" class="flex justify-center">
          <button @click="showNewTag = true" class="text-xs text-primary-500 hover:text-primary-600 flex items-center gap-0.5">
            <span class="material-symbols-rounded text-sm">add</span> Create new tag
          </button>
        </div>
        <div v-else class="flex gap-2 items-end">
          <input v-model="newTagName" placeholder="Tag name" @keyup.enter="createAndAssignTag"
                 class="flex-1 px-3 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs focus:ring-2 focus:ring-primary-500 outline-none" />
          <div class="flex gap-1">
            <button v-for="c in tagColors" :key="c" @click="newTagColor = c"
                    :class="['w-5 h-5 rounded-full border-2 transition-transform', newTagColor === c ? 'scale-125 border-white ring-2 ring-primary-500' : 'border-transparent']"
                    :style="{ backgroundColor: c }"></button>
          </div>
          <button @click="createAndAssignTag"
                  class="px-3 py-1.5 rounded-lg bg-primary-600 text-white text-xs font-medium hover:bg-primary-700">
            Add
          </button>
        </div>
      </div>
    </div>

    <!-- Custom Fields Section -->
    <div>
      <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-500">tune</span>
          Custom Fields
        </h3>
        <button @click="showFieldManager = !showFieldManager"
                class="text-xs text-primary-600 hover:text-primary-700 font-medium flex items-center gap-0.5">
          <span class="material-symbols-rounded text-sm">settings</span>
        </button>
      </div>

      <!-- Field Values -->
      <div v-if="customFields.length" class="space-y-2">
        <div v-for="field in customFields" :key="field.id" class="flex items-center gap-2">
          <label class="text-xs text-surface-500 w-28 truncate" :title="field.field_name">{{ field.field_name }}</label>
          <input v-if="field.field_type === 'text' || field.field_type === 'url' || field.field_type === 'email' || field.field_type === 'phone'"
                 v-model="field.field_value"
                 :type="field.field_type === 'email' ? 'email' : field.field_type === 'url' ? 'url' : field.field_type === 'phone' ? 'tel' : 'text'"
                 @blur="saveFieldValue(field)"
                 class="flex-1 px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs focus:ring-2 focus:ring-primary-500 outline-none" />
          <input v-else-if="field.field_type === 'number'"
                 v-model="field.field_value" type="number"
                 @blur="saveFieldValue(field)"
                 class="flex-1 px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs focus:ring-2 focus:ring-primary-500 outline-none" />
          <input v-else-if="field.field_type === 'date'"
                 v-model="field.field_value" type="date"
                 @change="saveFieldValue(field)"
                 class="flex-1 px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs focus:ring-2 focus:ring-primary-500 outline-none" />
          <select v-else-if="field.field_type === 'select'"
                  v-model="field.field_value"
                  @change="saveFieldValue(field)"
                  class="flex-1 px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs focus:ring-2 focus:ring-primary-500 outline-none">
            <option value="">—</option>
            <option v-for="opt in (field.field_options || [])" :key="opt" :value="opt">{{ opt }}</option>
          </select>
          <button v-if="showFieldManager" @click="deleteField(field)"
                  class="p-1 rounded text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
            <span class="material-symbols-rounded text-sm">delete</span>
          </button>
        </div>
      </div>
      <p v-else class="text-xs text-surface-400">No custom fields defined</p>

      <!-- New Field Form -->
      <div v-if="showFieldManager" class="mt-3 p-3 bg-surface-50 dark:bg-surface-800/50 rounded-lg">
        <p class="text-xs font-medium text-surface-600 dark:text-surface-300 mb-2">Add Custom Field</p>
        <div class="flex gap-2">
          <input v-model="newFieldName" placeholder="Field name"
                 class="flex-1 px-3 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs focus:ring-2 focus:ring-primary-500 outline-none" />
          <select v-model="newFieldType"
                  class="px-3 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs focus:ring-2 focus:ring-primary-500 outline-none">
            <option value="text">Text</option>
            <option value="number">Number</option>
            <option value="date">Date</option>
            <option value="select">Select</option>
            <option value="url">URL</option>
            <option value="email">Email</option>
            <option value="phone">Phone</option>
          </select>
          <button @click="createField"
                  class="px-3 py-1.5 rounded-lg bg-primary-600 text-white text-xs font-medium hover:bg-primary-700">
            Add
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

