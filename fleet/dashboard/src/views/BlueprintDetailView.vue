<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { useToastStore } from '../stores/toast'
import PackageEditor from '../components/PackageEditor.vue'
import ConfigEditor from '../components/ConfigEditor.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const loading = ref(true)
const blueprint = ref(null)
const activeTab = ref('templates')
const activeCategory = ref(null)
const packageCount = ref(0)

// Edit modals state
const showEditBlueprintModal = ref(false)
const showEditTemplateModal = ref(false)
const editingTemplate = ref(null)
const savingBlueprint = ref(false)
const savingTemplate = ref(false)
const configEditorRef = ref(null)

// Blueprint edit form
const blueprintForm = ref({
  name: '',
  description: '',
  version: '',
})

// Template edit form
const templateForm = ref({
  id: null,
  filename: '',
  target_path: '',
  content: '',
  permissions: '',
  owner: '',
  is_optional: false,
})

const fetchBlueprint = async () => {
  try {
    const response = await api.get(`/api/blueprints/${route.params.id}`)
    blueprint.value = response.data
    
    // Set first category as active
    const categories = Object.keys(blueprint.value.templates || {})
    if (categories.length > 0) {
      activeCategory.value = categories[0]
    }

    // Get package count
    try {
      const pkgResponse = await api.get(`/api/blueprints/${route.params.id}/packages`)
      packageCount.value = pkgResponse.data.total || 0
    } catch (e) {
      packageCount.value = 0
    }
  } catch (error) {
    toast.error('Failed to load blueprint')
    router.push('/blueprints')
  } finally {
    loading.value = false
  }
}

const templateCount = computed(() => {
  if (!blueprint.value?.templates) return 0
  return Object.values(blueprint.value.templates).reduce((sum, arr) => sum + arr.length, 0)
})

// Detect language from filename for syntax highlighting
const editorLanguage = computed(() => {
  const filename = templateForm.value.filename || ''
  const ext = filename.split('.').pop()?.toLowerCase()
  const langMap = {
    'php': 'php',
    'js': 'javascript',
    'json': 'json',
    'html': 'html',
    'htm': 'html',
    'css': 'css',
    'xml': 'xml',
    'sql': 'sql',
    'sh': 'shell',
    'bash': 'shell',
    'conf': 'conf',
    'cnf': 'conf',
    'ini': 'conf',
    'cfg': 'conf',
  }
  return langMap[ext] || 'conf'
})

// Get service type for AI context
const editorService = computed(() => {
  if (!activeCategory.value) return ''
  const serviceMap = {
    'openlitespeed': 'ols',
    'php': 'php',
    'mariadb': 'mysql',
    'postfix': 'postfix',
    'dovecot': 'dovecot',
    'fail2ban': 'fail2ban',
    'firewalld': 'firewall',
    'openvpn': 'openvpn',
  }
  return serviceMap[activeCategory.value] || activeCategory.value
})

const getCategoryIcon = (category) => {
  const icons = {
    'openlitespeed': 'dns',
    'php': 'code',
    'mariadb': 'database',
    'postfix': 'mail',
    'dovecot': 'inbox',
    'fail2ban': 'shield',
    'firewalld': 'local_fire_department',
    'openvpn': 'vpn_key',
    'systemd': 'settings',
    'panel': 'dashboard',
    'email_app': 'email',
    'other': 'description'
  }
  return icons[category] || 'description'
}

const onPackagesUpdated = async () => {
  // Refresh package count
  try {
    const pkgResponse = await api.get(`/api/blueprints/${route.params.id}/packages`)
    packageCount.value = pkgResponse.data.total || 0
  } catch (e) {
    // ignore
  }
}

// Open blueprint edit modal
const openEditBlueprint = () => {
  blueprintForm.value = {
    name: blueprint.value.name,
    description: blueprint.value.description || '',
    version: blueprint.value.version || '1.0.0',
  }
  showEditBlueprintModal.value = true
}

// Save blueprint changes
const saveBlueprint = async () => {
  savingBlueprint.value = true
  try {
    await api.put(`/api/blueprints/${blueprint.value.id}`, blueprintForm.value)
    blueprint.value.name = blueprintForm.value.name
    blueprint.value.description = blueprintForm.value.description
    blueprint.value.version = blueprintForm.value.version
    showEditBlueprintModal.value = false
    toast.success('Blueprint updated')
  } catch (error) {
    toast.error('Failed to update blueprint')
  } finally {
    savingBlueprint.value = false
  }
}

// Open template edit modal
const openEditTemplate = async (template) => {
  editingTemplate.value = template
  
  // Fetch full template content
  try {
    const response = await api.get(`/api/blueprints/${blueprint.value.id}/templates/${template.id}`)
    const fullTemplate = response.data
    
    templateForm.value = {
      id: fullTemplate.id,
      filename: fullTemplate.filename,
      target_path: fullTemplate.target_path,
      content: fullTemplate.content || '',
      permissions: fullTemplate.permissions || '0644',
      owner: fullTemplate.owner || 'root',
      is_optional: fullTemplate.is_optional || false,
    }
    showEditTemplateModal.value = true
  } catch (error) {
    toast.error('Failed to load template')
  }
}

// Save template changes
const saveTemplate = async () => {
  savingTemplate.value = true
  try {
    await api.post(`/api/blueprints/${blueprint.value.id}/templates`, {
      template_id: templateForm.value.id,
      filename: templateForm.value.filename,
      target_path: templateForm.value.target_path,
      content: templateForm.value.content,
      permissions: templateForm.value.permissions,
      owner: templateForm.value.owner,
      is_optional: templateForm.value.is_optional,
      category: activeCategory.value,
    })
    
    // Update local data
    const templateList = blueprint.value.templates[activeCategory.value]
    const idx = templateList.findIndex(t => t.id === templateForm.value.id)
    if (idx !== -1) {
      templateList[idx] = { ...templateList[idx], ...templateForm.value }
    }
    
    showEditTemplateModal.value = false
    toast.success('Template updated')
  } catch (error) {
    toast.error('Failed to update template')
  } finally {
    savingTemplate.value = false
  }
}

// Check syntax - delegates to ConfigEditor component
const checkSyntax = () => {
  if (configEditorRef.value) {
    configEditorRef.value.checkSyntax()
  }
}

// Delete template
const deleteTemplate = async (template) => {
  if (!confirm(`Delete template "${template.filename}"?`)) return
  
  try {
    await api.delete(`/api/blueprints/${blueprint.value.id}/templates/${template.id}`)
    
    // Remove from local data
    const templateList = blueprint.value.templates[activeCategory.value]
    const idx = templateList.findIndex(t => t.id === template.id)
    if (idx !== -1) {
      templateList.splice(idx, 1)
    }
    
    toast.success('Template deleted')
  } catch (error) {
    toast.error('Failed to delete template')
  }
}

onMounted(fetchBlueprint)
</script>

<template>
  <div class="animate-fadeIn">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="spinner w-10 h-10"></div>
    </div>

    <template v-else-if="blueprint">
      <!-- Header -->
      <div class="flex items-center gap-4 mb-6">
        <button @click="router.push('/blueprints')" class="btn btn-ghost btn-sm">
          <span class="material-symbols-rounded">arrow_back</span>
        </button>
        <div class="flex-1">
          <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold">{{ blueprint.name }}</h1>
            <span v-if="blueprint.is_default" class="badge badge-info">Default</span>
          </div>
          <p class="text-surface-500 dark:text-surface-400">{{ blueprint.description || 'No description' }}</p>
        </div>
        <button @click="openEditBlueprint" class="btn btn-secondary">
          <span class="material-symbols-rounded">edit</span>
          Edit
        </button>
      </div>

      <!-- Info Cards -->
      <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="card p-4 text-center">
          <p class="text-surface-500 dark:text-surface-400 text-sm">Version</p>
          <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ blueprint.version || '1.0.0' }}</p>
        </div>
        <div class="card p-4 text-center">
          <p class="text-surface-500 dark:text-surface-400 text-sm">Templates</p>
          <p class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ templateCount }}</p>
        </div>
        <div class="card p-4 text-center">
          <p class="text-surface-500 dark:text-surface-400 text-sm">Packages</p>
          <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ packageCount }}</p>
        </div>
        <div class="card p-4 text-center">
          <p class="text-surface-500 dark:text-surface-400 text-sm">Servers Using</p>
          <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ blueprint.server_count || 0 }}</p>
        </div>
      </div>

      <!-- Tabs -->
      <div class="flex items-center gap-1 mb-6 border-b border-surface-200 dark:border-surface-700">
        <button 
          @click="activeTab = 'templates'"
          :class="[
            'flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors border-b-2 -mb-px',
            activeTab === 'templates' 
              ? 'border-primary-500 text-primary-600 dark:text-primary-400' 
              : 'border-transparent text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white'
          ]"
        >
          <span class="material-symbols-rounded">description</span>
          Templates ({{ templateCount }})
        </button>
        <button 
          @click="activeTab = 'packages'"
          :class="[
            'flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors border-b-2 -mb-px',
            activeTab === 'packages' 
              ? 'border-primary-500 text-primary-600 dark:text-primary-400' 
              : 'border-transparent text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white'
          ]"
        >
          <span class="material-symbols-rounded">package_2</span>
          Packages ({{ packageCount }})
        </button>
        <button 
          @click="activeTab = 'variables'"
          :class="[
            'flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors border-b-2 -mb-px',
            activeTab === 'variables' 
              ? 'border-primary-500 text-primary-600 dark:text-primary-400' 
              : 'border-transparent text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white'
          ]"
        >
          <span class="material-symbols-rounded">data_object</span>
          Variables
        </button>
      </div>

      <!-- Templates Tab -->
      <div v-if="activeTab === 'templates'" class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Categories sidebar -->
        <div class="space-y-2">
          <h3 class="text-sm font-medium text-surface-500 dark:text-surface-400 mb-3">Categories</h3>
          <button
            v-for="(templates, category) in blueprint.templates"
            :key="category"
            @click="activeCategory = category"
            :class="[
              'w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-colors',
              activeCategory === category 
                ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400' 
                : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300'
            ]"
          >
            <span class="material-symbols-rounded">{{ getCategoryIcon(category) }}</span>
            <span class="flex-1 capitalize">{{ category.replace('_', ' ') }}</span>
            <span class="text-xs bg-surface-200 dark:bg-surface-700 px-2 py-0.5 rounded-full">{{ templates.length }}</span>
          </button>
        </div>

        <!-- Templates list -->
        <div class="lg:col-span-3">
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold capitalize">{{ activeCategory?.replace('_', ' ') }} Templates</h2>
              <button class="btn btn-primary btn-sm">
                <span class="material-symbols-rounded">add</span>
                Add Template
              </button>
            </div>
            <div class="card-body p-0">
              <div 
                v-if="!activeCategory || !blueprint.templates[activeCategory]?.length" 
                class="p-6 text-center text-surface-500 dark:text-surface-400"
              >
                Select a category to view templates
              </div>
              <div v-else class="divide-y divide-surface-200 dark:divide-surface-700">
                <div 
                  v-for="template in blueprint.templates[activeCategory]" 
                  :key="template.id"
                  class="p-4 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors cursor-pointer"
                  @click="openEditTemplate(template)"
                >
                  <div class="flex items-start justify-between">
                    <div>
                      <p class="font-medium text-surface-900 dark:text-surface-100">{{ template.filename }}</p>
                      <p class="text-sm text-surface-500 dark:text-surface-400 font-mono">{{ template.target_path }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                      <span v-if="template.is_optional" class="badge badge-neutral">Optional</span>
                      <button @click.stop="openEditTemplate(template)" class="btn btn-ghost btn-sm">
                        <span class="material-symbols-rounded">edit</span>
                      </button>
                      <button @click.stop="deleteTemplate(template)" class="btn btn-ghost btn-sm text-red-500 hover:text-red-600">
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

      <!-- Packages Tab -->
      <div v-if="activeTab === 'packages'">
        <PackageEditor 
          :blueprint-id="blueprint.id" 
          @updated="onPackagesUpdated"
        />
      </div>

      <!-- Variables Tab -->
      <div v-if="activeTab === 'variables'" class="card">
        <div class="card-header">
          <h2 class="font-semibold">Template Variables</h2>
        </div>
        <div class="card-body">
          <div v-if="!blueprint.variables?.definitions?.length" class="text-center text-surface-500 dark:text-surface-400 py-8">
            No variables defined
          </div>
          <div v-else class="space-y-4">
            <div 
              v-for="variable in blueprint.variables.definitions" 
              :key="variable.name"
              class="flex items-center gap-4 p-4 bg-surface-100 dark:bg-surface-700/30 rounded-xl"
            >
              <div class="flex-1">
                <p class="font-mono text-primary-600 dark:text-primary-400" v-text="'{{' + variable.name + '}}'"></p>
                <p class="text-sm text-surface-500 dark:text-surface-400">{{ variable.label }}</p>
              </div>
              <span v-if="variable.required" class="badge badge-warning">Required</span>
              <span v-if="variable.generate" class="badge badge-info">Auto-generated</span>
              <span class="badge badge-neutral">{{ variable.type }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Edit Blueprint Modal -->
    <Teleport to="body">
      <div v-if="showEditBlueprintModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showEditBlueprintModal = false"></div>
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg mx-4 border border-surface-200 dark:border-surface-700">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
            <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">Edit Blueprint</h2>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Name</label>
              <input v-model="blueprintForm.name" type="text" class="input w-full" />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Description</label>
              <textarea v-model="blueprintForm.description" class="input w-full" rows="3"></textarea>
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Version</label>
              <input v-model="blueprintForm.version" type="text" class="input w-full" placeholder="1.0.0" />
            </div>
          </div>
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
            <button @click="showEditBlueprintModal = false" class="btn btn-ghost">Cancel</button>
            <button @click="saveBlueprint" :disabled="savingBlueprint" class="btn btn-primary">
              <span v-if="savingBlueprint" class="spinner w-4 h-4"></span>
              Save Changes
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Edit Template Modal -->
    <Teleport to="body">
      <div v-if="showEditTemplateModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showEditTemplateModal = false"></div>
        <div class="relative bg-surface-100 dark:bg-surface-900 rounded-2xl shadow-2xl w-full max-w-6xl mx-4 max-h-[90vh] overflow-hidden flex flex-col border border-surface-200 dark:border-surface-700">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700/50 flex items-center justify-between bg-surface-50 dark:bg-surface-800">
            <div>
              <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">Edit Template</h2>
              <p class="text-sm text-surface-500 dark:text-surface-400">{{ templateForm.target_path }}</p>
            </div>
            <button @click="showEditTemplateModal = false" class="btn btn-ghost btn-sm">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="flex-1 overflow-y-auto p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Filename</label>
                <input v-model="templateForm.filename" type="text" class="input w-full" />
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Target Path</label>
                <input v-model="templateForm.target_path" type="text" class="input w-full font-mono text-sm" />
              </div>
            </div>
            
            <div class="grid grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Permissions</label>
                <input v-model="templateForm.permissions" type="text" class="input w-full font-mono" placeholder="0644" />
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Owner</label>
                <input v-model="templateForm.owner" type="text" class="input w-full font-mono" placeholder="root:root" />
              </div>
              <div class="flex items-end pb-2">
                <label class="flex items-center gap-3 cursor-pointer">
                  <button 
                    type="button"
                    @click="templateForm.is_optional = !templateForm.is_optional"
                    :class="[
                      'relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary-500/50',
                      templateForm.is_optional ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span 
                      :class="[
                        'absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200',
                        templateForm.is_optional ? 'translate-x-5' : 'translate-x-0'
                      ]"
                    ></span>
                  </button>
                  <span class="text-sm text-surface-700 dark:text-surface-300">Optional template</span>
                </label>
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Content</label>
              <ConfigEditor
                ref="configEditorRef"
                v-model="templateForm.content"
                :language="editorLanguage"
                :service="editorService"
                :ai-enabled="true"
                :show-toolbar="true"
                :filename="templateForm.filename"
                height="400px"
                :zen-title="`${templateForm.filename} - Template Editor`"
                @save="saveTemplate"
              />
              <p class="text-xs text-surface-500 dark:text-surface-400 mt-2">
                Use <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">&#123;&#123;VARIABLE_NAME&#125;&#125;</code> for template variables that will be replaced during deployment.
              </p>
            </div>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700/50 flex items-center justify-between bg-surface-50 dark:bg-surface-800">
            <!-- Syntax Result -->
            <div v-if="configEditorRef?.syntaxResult" :class="['flex items-center gap-2 text-sm', configEditorRef.syntaxResult.valid ? 'text-green-500' : 'text-red-500']">
              <span class="material-symbols-rounded text-lg">{{ configEditorRef.syntaxResult.valid ? 'check_circle' : 'error' }}</span>
              {{ configEditorRef.syntaxResult.message }}
            </div>
            <div v-else></div>
            
            <!-- Buttons -->
            <div class="flex items-center gap-3">
              <button @click="showEditTemplateModal = false" class="btn btn-ghost">Cancel</button>
              <button @click="checkSyntax" :disabled="configEditorRef?.checkingSyntax" class="btn btn-secondary">
                <span v-if="configEditorRef?.checkingSyntax" class="spinner w-4 h-4"></span>
                <span v-else class="material-symbols-rounded">fact_check</span>
                Check Syntax
              </button>
              <button @click="saveTemplate" :disabled="savingTemplate" class="btn btn-primary">
                <span v-if="savingTemplate" class="spinner w-4 h-4"></span>
                <span v-else class="material-symbols-rounded">save</span>
                Save Template
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
