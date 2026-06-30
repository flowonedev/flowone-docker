<script setup>
import { ref, onMounted, computed } from 'vue'
import { useEmailTemplatesStore } from '@/stores/emailTemplates'
import { useToastStore } from '@/stores/toast'
import RichTextEditor from '@/components/RichTextEditor.vue'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'

const templatesStore = useEmailTemplatesStore()
const toast = useToastStore()

// View state
const showEditor = ref(false)
const editingTemplate = ref(null)
const showDeleteConfirm = ref(false)
const templateToDelete = ref(null)
const previewTemplate = ref(null)

// Form state
const form = ref({
  name: '',
  description: '',
  category: 'custom',
  icon: 'dashboard_customize',
  html_content: '<p></p>',
  is_shared: false,
})

// Category options
const categoryOptions = [
  { id: 'text', label: 'Text', icon: 'notes' },
  { id: 'media', label: 'Media', icon: 'image' },
  { id: 'layout', label: 'Layout', icon: 'view_column' },
  { id: 'cta', label: 'Call to Action', icon: 'ads_click' },
  { id: 'custom', label: 'Custom', icon: 'dashboard_customize' },
]

// Icon options
const iconOptions = [
  'dashboard_customize', 'notes', 'title', 'image', 'panorama',
  'view_column', 'view_week', 'grid_view', 'ads_click', 'format_quote',
  'horizontal_rule', 'table_chart', 'smart_button', 'star', 'favorite',
  'bolt', 'rocket_launch', 'celebration', 'campaign', 'mail',
]

// Filter state
const filterCategory = ref('all')
const viewMode = ref('all') // 'all', 'builtin', 'custom'

const filteredTemplates = computed(() => {
  let blocks = []
  if (viewMode.value === 'all') blocks = templatesStore.allBlocks
  else if (viewMode.value === 'builtin') blocks = templatesStore.builtinBlocks
  else blocks = templatesStore.templates
  
  if (filterCategory.value !== 'all') {
    blocks = blocks.filter(t => t.category === filterCategory.value)
  }
  return blocks
})

const builtinCount = computed(() => templatesStore.builtinBlocks.length)
const customCount = computed(() => templatesStore.templates.length)
const totalCount = computed(() => builtinCount.value + customCount.value)

onMounted(() => {
  templatesStore.fetchTemplates()
})

function openCreateForm() {
  editingTemplate.value = null
  form.value = {
    name: '',
    description: '',
    category: 'custom',
    icon: 'dashboard_customize',
    html_content: '<p></p>',
    is_shared: false,
  }
  showEditor.value = true
}

function openEditForm(template) {
  editingTemplate.value = template
  form.value = {
    name: template.name,
    description: template.description || '',
    category: template.category || 'custom',
    icon: template.icon || 'dashboard_customize',
    html_content: template.html_content || '<p></p>',
    is_shared: !!parseInt(template.is_shared),
  }
  showEditor.value = true
}

async function saveTemplate() {
  if (!form.value.name.trim()) {
    toast.warning('Please enter a template name')
    return
  }
  if (!form.value.html_content || form.value.html_content === '<p></p>' || form.value.html_content === '<p><br></p>') {
    toast.warning('Please add some content to the template')
    return
  }

  const data = {
    name: form.value.name.trim(),
    description: form.value.description.trim() || null,
    category: form.value.category,
    icon: form.value.icon,
    html_content: form.value.html_content,
    is_shared: form.value.is_shared ? 1 : 0,
  }

  let result
  if (editingTemplate.value) {
    result = await templatesStore.updateTemplate(editingTemplate.value.id, data)
    if (result.success) {
      toast.success('Template updated')
    } else {
      toast.error(result.error || 'Failed to update template')
      return
    }
  } else {
    result = await templatesStore.createTemplate(data)
    if (result.success) {
      toast.success('Template created')
    } else {
      toast.error(result.error || 'Failed to create template')
      return
    }
  }

  showEditor.value = false
  editingTemplate.value = null
}

function cancelEdit() {
  showEditor.value = false
  editingTemplate.value = null
}

function confirmDelete(template) {
  templateToDelete.value = template
  showDeleteConfirm.value = true
}

async function handleDelete() {
  if (!templateToDelete.value) return
  const result = await templatesStore.deleteTemplate(templateToDelete.value.id)
  if (result.success) {
    toast.success('Template deleted')
  } else {
    toast.error(result.error || 'Failed to delete template')
  }
  showDeleteConfirm.value = false
  templateToDelete.value = null
}

function togglePreview(template) {
  if (previewTemplate.value?.id === template.id) {
    previewTemplate.value = null
  } else {
    previewTemplate.value = template
  }
}

async function toggleShared(template) {
  const newShared = parseInt(template.is_shared) ? 0 : 1
  const result = await templatesStore.updateTemplate(template.id, { is_shared: newShared })
  if (result.success) {
    toast.success(newShared ? 'Template shared with team' : 'Template made private')
  } else {
    toast.error('Failed to update sharing')
  }
}

function duplicateTemplate(template) {
  editingTemplate.value = null
  form.value = {
    name: template.name + ' (Copy)',
    description: template.description || '',
    category: template.category || 'custom',
    icon: template.icon || 'dashboard_customize',
    html_content: template.html_content || '<p></p>',
    is_shared: false,
  }
  showEditor.value = true
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500">dashboard_customize</span>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Email Templates</h2>
        </div>
        <p class="text-sm text-surface-500 mt-1">
          Create reusable content blocks for your emails. Shared templates are available to your entire team.
        </p>
      </div>
      <button 
        v-if="!showEditor"
        @click="openCreateForm" 
        class="btn-primary"
      >
        <span class="material-symbols-rounded">add</span>
        New Template
      </button>
    </div>

    <!-- Editor Form -->
    <div v-if="showEditor" class="card p-6 space-y-5">
      <div class="flex items-center justify-between">
        <h3 class="font-medium text-surface-900 dark:text-surface-100">
          {{ editingTemplate ? 'Edit Template' : 'Create Template' }}
        </h3>
        <button @click="cancelEdit" class="btn-ghost btn-sm">
          <span class="material-symbols-rounded">close</span>
        </button>
      </div>

      <!-- Name -->
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
          Template Name <span class="text-red-500">*</span>
        </label>
        <input
          v-model="form.name"
          type="text"
          class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 outline-none focus:ring-2 focus:ring-primary-500/40 focus:border-primary-500"
          placeholder="e.g., Welcome Email Header"
        />
      </div>

      <!-- Description -->
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
          Description
        </label>
        <input
          v-model="form.description"
          type="text"
          class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 outline-none focus:ring-2 focus:ring-primary-500/40 focus:border-primary-500"
          placeholder="Optional short description"
        />
      </div>

      <!-- Category + Icon row -->
      <div class="grid grid-cols-2 gap-4">
        <!-- Category -->
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
            Category
          </label>
          <select
            v-model="form.category"
            class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 outline-none focus:ring-2 focus:ring-primary-500/40 focus:border-primary-500"
          >
            <option v-for="cat in categoryOptions" :key="cat.id" :value="cat.id">
              {{ cat.label }}
            </option>
          </select>
        </div>

        <!-- Icon -->
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
            Icon
          </label>
          <div class="flex flex-wrap gap-1.5 p-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 max-h-[80px] overflow-y-auto">
            <button
              v-for="icon in iconOptions"
              :key="icon"
              @click="form.icon = icon"
              :class="[
                'w-8 h-8 rounded-lg flex items-center justify-center transition-colors',
                form.icon === icon
                  ? 'bg-primary-500 text-white'
                  : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500'
              ]"
              :title="icon"
            >
              <span class="material-symbols-rounded text-lg">{{ icon }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Share with team toggle -->
      <div class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-900 rounded-lg">
        <button
          @click="form.is_shared = !form.is_shared"
          :class="[
            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0',
            form.is_shared ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
          ]"
        >
          <span
            :class="[
              'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow',
              form.is_shared ? 'translate-x-6' : 'translate-x-1'
            ]"
          />
        </button>
        <div>
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100">Share with team</p>
          <p class="text-xs text-surface-500">Everyone in your organization can use this template</p>
        </div>
      </div>

      <!-- HTML Content Editor -->
      <div>
        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
          Content <span class="text-red-500">*</span>
        </label>
        <RichTextEditor
          v-model="form.html_content"
          placeholder="Design your template content here..."
          :showAI="false"
        />
      </div>

      <!-- Actions -->
      <div class="flex items-center gap-3 pt-2">
        <button
          @click="saveTemplate"
          class="btn-primary"
          :disabled="templatesStore.saving"
        >
          <span v-if="templatesStore.saving" class="spinner w-4 h-4"></span>
          <span class="material-symbols-rounded">save</span>
          {{ editingTemplate ? 'Update Template' : 'Create Template' }}
        </button>
        <button @click="cancelEdit" class="btn-ghost">
          Cancel
        </button>
      </div>
    </div>

    <!-- Template List -->
    <div v-if="!showEditor">
      <!-- View mode + Filter bar -->
      <div class="space-y-3 mb-4">
        <!-- View mode tabs -->
        <div class="flex items-center gap-1 p-1 bg-surface-100 dark:bg-surface-800 rounded-lg w-fit">
          <button
            @click="viewMode = 'all'"
            :class="[
              'px-4 py-1.5 rounded-md text-sm font-medium transition-colors',
              viewMode === 'all'
                ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm'
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
          >
            All ({{ totalCount }})
          </button>
          <button
            @click="viewMode = 'builtin'"
            :class="[
              'px-4 py-1.5 rounded-md text-sm font-medium transition-colors',
              viewMode === 'builtin'
                ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm'
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
          >
            Built-in ({{ builtinCount }})
          </button>
          <button
            @click="viewMode = 'custom'"
            :class="[
              'px-4 py-1.5 rounded-md text-sm font-medium transition-colors',
              viewMode === 'custom'
                ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm'
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
          >
            Custom ({{ customCount }})
          </button>
        </div>
        
        <!-- Category filter pills -->
        <div class="flex items-center gap-2">
          <button
            @click="filterCategory = 'all'"
            :class="[
              'px-3 py-1.5 rounded-full text-xs font-medium transition-colors',
              filterCategory === 'all'
                ? 'bg-primary-500 text-white'
                : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
            ]"
          >
            All Categories
          </button>
          <button
            v-for="cat in categoryOptions"
            :key="cat.id"
            @click="filterCategory = cat.id"
            :class="[
              'px-3 py-1.5 rounded-full text-xs font-medium transition-colors flex items-center gap-1',
              filterCategory === cat.id
                ? 'bg-primary-500 text-white'
                : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-sm">{{ cat.icon }}</span>
            {{ cat.label }}
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="templatesStore.loading" class="flex items-center justify-center py-16">
        <span class="spinner text-primary-500"></span>
      </div>

      <!-- Templates Grid -->
      <div v-else-if="filteredTemplates.length > 0" class="space-y-3">
        <div
          v-for="template in filteredTemplates"
          :key="template.id"
          class="card p-4 hover:shadow-md transition-shadow"
        >
          <div class="flex items-start gap-4">
            <!-- Icon -->
            <div :class="[
              'w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0',
              template.is_builtin 
                ? 'bg-surface-100 dark:bg-surface-700' 
                : 'bg-primary-50 dark:bg-primary-500/10'
            ]">
              <span :class="[
                'material-symbols-rounded text-xl',
                template.is_builtin ? 'text-surface-500' : 'text-primary-500'
              ]">{{ template.icon || 'dashboard_customize' }}</span>
            </div>

            <!-- Info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-0.5">
                <h4 class="font-medium text-surface-900 dark:text-surface-100 truncate">{{ template.name }}</h4>
                <span 
                  v-if="template.is_builtin"
                  class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300 flex-shrink-0"
                >
                  Built-in
                </span>
                <span 
                  v-if="!template.is_builtin && parseInt(template.is_shared)"
                  class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 flex-shrink-0"
                >
                  Shared
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-surface-100 dark:bg-surface-700 text-surface-500 flex-shrink-0">
                  {{ template.category || 'custom' }}
                </span>
              </div>
              <p v-if="template.description" class="text-sm text-surface-500 truncate">{{ template.description }}</p>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-1 flex-shrink-0">
              <button
                @click="togglePreview(template)"
                :class="[
                  'p-2 rounded-lg transition-colors',
                  previewTemplate?.id === template.id
                    ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-500'
                    : 'text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-600 dark:hover:text-surface-300'
                ]"
                title="Preview"
              >
                <span class="material-symbols-rounded text-lg">{{ previewTemplate?.id === template.id ? 'visibility_off' : 'visibility' }}</span>
              </button>
              <!-- Custom template actions -->
              <template v-if="!template.is_builtin">
                <button
                  @click="toggleShared(template)"
                  :class="[
                    'p-2 rounded-lg transition-colors',
                    parseInt(template.is_shared)
                      ? 'text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10'
                      : 'text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-600 dark:hover:text-surface-300'
                  ]"
                  :title="parseInt(template.is_shared) ? 'Make private' : 'Share with team'"
                >
                  <span class="material-symbols-rounded text-lg">{{ parseInt(template.is_shared) ? 'group' : 'person' }}</span>
                </button>
                <button
                  @click="openEditForm(template)"
                  class="p-2 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                  title="Edit"
                >
                  <span class="material-symbols-rounded text-lg">edit</span>
                </button>
                <button
                  @click="confirmDelete(template)"
                  class="p-2 rounded-lg text-surface-400 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-500 transition-colors"
                  title="Delete"
                >
                  <span class="material-symbols-rounded text-lg">delete</span>
                </button>
              </template>
              <!-- Duplicate (available for both builtin and custom) -->
              <button
                @click="duplicateTemplate(template)"
                class="p-2 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                :title="template.is_builtin ? 'Duplicate as custom template' : 'Duplicate'"
              >
                <span class="material-symbols-rounded text-lg">content_copy</span>
              </button>
            </div>
          </div>

          <!-- Preview panel -->
          <div 
            v-if="previewTemplate?.id === template.id" 
            class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700"
          >
            <p class="text-xs font-medium text-surface-500 mb-2 uppercase">Preview</p>
            <div 
              class="prose dark:prose-invert max-w-none p-4 bg-white dark:bg-surface-900 rounded-lg border border-surface-200 dark:border-surface-700 text-sm"
              v-html="template.html_content"
            ></div>
          </div>
        </div>
      </div>

      <!-- Filtered empty -->
      <div v-else class="card p-8 text-center">
        <span class="material-symbols-rounded text-3xl text-surface-300 mb-2 block">filter_list_off</span>
        <p class="text-sm text-surface-500">No templates match the current filter</p>
        <button 
          v-if="viewMode === 'custom' && customCount === 0" 
          @click="openCreateForm" 
          class="btn-primary mt-4 mx-auto"
        >
          <span class="material-symbols-rounded">add</span>
          Create Your First Template
        </button>
      </div>
    </div>

    <!-- Info box -->
    <div v-if="!showEditor" class="p-4 bg-surface-100 dark:bg-surface-800 rounded-xl">
      <div class="flex items-start gap-3">
        <span class="material-symbols-rounded text-surface-400 mt-0.5">info</span>
        <div class="text-sm text-surface-500">
          <p class="font-medium text-surface-600 dark:text-surface-400 mb-1">How to use content blocks</p>
          <p>
            All blocks (built-in + custom) are available in the compose toolbar — click the
            <span class="inline-flex items-center"><span class="material-symbols-rounded text-sm align-middle">dashboard_customize</span></span> button.
            You can duplicate any built-in block to customize it. Shared templates are visible to everyone in your organization.
          </p>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <ConfirmModal
      :show="showDeleteConfirm"
      title="Delete Template"
      :message="`Are you sure you want to delete '${templateToDelete?.name}'? This action cannot be undone.`"
      confirm-text="Delete"
      type="danger"
      @confirm="handleDelete"
      @cancel="showDeleteConfirm = false; templateToDelete = null"
    />
  </div>
</template>

