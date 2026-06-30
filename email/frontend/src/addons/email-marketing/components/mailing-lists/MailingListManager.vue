<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useMailingListsStore } from '@/addons/email-marketing/stores/mailingLists'
import { useToastStore } from '@/stores/toast'
import { useAccountsStore } from '@/stores/accounts'
import AppHeader from '@/components/shared/AppHeader.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import StepGuide from '@/components/shared/StepGuide.vue'
import { featureGuides } from '@/data/featureGuides'
import { mailingListsGuide } from '@/data/stepGuides'

const mailingListsStore = useMailingListsStore()
const toast = useToastStore()
const accountsStore = useAccountsStore()

// Feature guide
const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.emailMarketing

// State
const loading = ref(true)
const search = ref('')
const selectedList = ref(null)
const showCreateListModal = ref(false)
const showEditListModal = ref(false)
const showAddContactModal = ref(false)
const showEditContactModal = ref(false)
const showImportModal = ref(false)
const editingContact = ref(null)

// Custom fields state
const customFields = ref([])
const showCustomFieldForm = ref(false)
const customFieldForm = ref({ field_label: '', field_type: 'text', options: [] })
const editingCustomFieldId = ref(null)

// Filter state
const positionFilter = ref('')
const companyFilter = ref('')
const showFiltersDropdown = ref(false)

// Get unique positions and companies for filter dropdowns
const uniquePositions = computed(() => {
  const positions = new Set()
  const contacts = mailingListsStore.currentList?.contacts || []
  contacts.forEach(c => {
    if (c.position) positions.add(c.position)
  })
  return Array.from(positions).sort()
})

const uniqueCompanies = computed(() => {
  const companies = new Set()
  const contacts = mailingListsStore.currentList?.contacts || []
  contacts.forEach(c => {
    if (c.company) companies.add(c.company)
  })
  return Array.from(companies).sort()
})

const activeFiltersCount = computed(() => {
  let count = 0
  if (positionFilter.value) count++
  if (companyFilter.value) count++
  return count
})

function clearFilters() {
  positionFilter.value = ''
  companyFilter.value = ''
}

// Form state
const listForm = ref({
  name: '',
  description: '',
  color: '#6366f1',
  icon: 'mail',
  is_shared: false
})

const contactForm = ref({
  email: '',
  name: '',
  phone: '',
  position: '',
  company: '',
  notes: '',
  custom_fields: {}
})

// Import state
const importFile = ref(null)
const importData = ref([])
const importParsing = ref(false)
const importPreview = ref([])
const importing = ref(false)

// Multi-select state
const multiSelectMode = ref(false)
const selectedContacts = ref(new Set())

// Colors for lists
const listColors = [
  '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f97316',
  '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6'
]

// Icons for lists
const listIcons = [
  'mail', 'contact_mail', 'group', 'campaign', 'newspaper',
  'business', 'diversity_3', 'handshake', 'person_add', 'contacts'
]

// Computed
const currentListContacts = computed(() => {
  if (!mailingListsStore.currentList) return []
  let contacts = mailingListsStore.currentList.contacts || []
  
  // Search filter
  if (search.value) {
    const q = search.value.toLowerCase()
    contacts = contacts.filter(c =>
      c.email.toLowerCase().includes(q) ||
      (c.name && c.name.toLowerCase().includes(q)) ||
      (c.position && c.position.toLowerCase().includes(q)) ||
      (c.company && c.company.toLowerCase().includes(q))
    )
  }
  
  // Position filter
  if (positionFilter.value) {
    contacts = contacts.filter(c => c.position === positionFilter.value)
  }
  
  // Company filter
  if (companyFilter.value) {
    contacts = contacts.filter(c => c.company === companyFilter.value)
  }
  
  return contacts
})

// Watch for list selection
watch(selectedList, async (id) => {
  if (id) {
    await mailingListsStore.fetchList(id)
    await loadCustomFields(id)
  } else {
    mailingListsStore.currentList = null
    customFields.value = []
  }
})

// Actions
async function init() {
  loading.value = true
  await mailingListsStore.fetchLists()
  loading.value = false
}

// List management
function openCreateList() {
  listForm.value = { name: '', description: '', color: '#6366f1', icon: 'mail', is_shared: false }
  showCreateListModal.value = true
}

function openEditList() {
  const list = mailingListsStore.currentList
  if (!list) return
  
  listForm.value = {
    name: list.name,
    description: list.description || '',
    color: list.color || '#6366f1',
    icon: list.icon || 'mail',
    is_shared: !!list.is_shared
  }
  showEditListModal.value = true
}

async function createList() {
  if (!listForm.value.name.trim()) {
    toast.error('List name is required')
    return
  }
  
  const result = await mailingListsStore.createList(listForm.value)
  if (result.success) {
    toast.success('List created')
    showCreateListModal.value = false
    selectedList.value = result.id
  } else {
    toast.error(result.error || 'Failed to create list')
  }
}

async function updateList() {
  const list = mailingListsStore.currentList
  if (!list) return
  
  const result = await mailingListsStore.updateList(list.id, listForm.value)
  if (result.success) {
    toast.success('List updated')
    showEditListModal.value = false
    await mailingListsStore.fetchList(list.id)
  } else {
    toast.error(result.error || 'Failed to update list')
  }
}

async function deleteList() {
  const list = mailingListsStore.currentList
  if (!list) return
  
  if (!confirm(`Delete "${list.name}" and all its contacts?`)) return
  
  const result = await mailingListsStore.deleteList(list.id)
  if (result.success) {
    toast.success('List deleted')
    selectedList.value = null
  } else {
    toast.error(result.error || 'Failed to delete list')
  }
}

// Contact management
function openAddContact() {
  contactForm.value = { email: '', name: '', phone: '', position: '', company: '', notes: '', custom_fields: {} }
  showAddContactModal.value = true
}

function openEditContact(contact) {
  editingContact.value = contact
  let cf = contact.custom_fields || {}
  if (typeof cf === 'string') {
    try { cf = JSON.parse(cf) } catch { cf = {} }
  }
  contactForm.value = {
    email: contact.email,
    name: contact.name || '',
    phone: contact.phone || '',
    position: contact.position || '',
    company: contact.company || '',
    notes: contact.notes || '',
    custom_fields: { ...cf }
  }
  showEditContactModal.value = true
}

async function addContact() {
  if (!contactForm.value.email.trim()) {
    toast.error('Email is required')
    return
  }
  
  const list = mailingListsStore.currentList
  if (!list) return
  
  const result = await mailingListsStore.addContact(list.id, contactForm.value)
  if (result.success) {
    toast.success('Contact added')
    showAddContactModal.value = false
  } else {
    toast.error(result.error || 'Failed to add contact')
  }
}

async function updateContact() {
  if (!editingContact.value) return
  
  const result = await mailingListsStore.updateContact(editingContact.value.id, contactForm.value)
  if (result.success) {
    toast.success('Contact updated')
    showEditContactModal.value = false
    editingContact.value = null
  } else {
    toast.error(result.error || 'Failed to update contact')
  }
}

async function deleteContact(contact) {
  if (!confirm(`Delete contact "${contact.name || contact.email}"?`)) return
  
  const result = await mailingListsStore.deleteContact(contact.id)
  if (result.success) {
    toast.success('Contact deleted')
  } else {
    toast.error(result.error || 'Failed to delete contact')
  }
}

// Custom fields management
async function loadCustomFields(listId) {
  customFields.value = await mailingListsStore.fetchCustomFields(listId)
}

function editCustomField(field) {
  editingCustomFieldId.value = field.id
  customFieldForm.value = { field_label: field.field_label, field_type: field.field_type, options: field.options || [] }
  showCustomFieldForm.value = true
}

async function saveCustomField() {
  if (!customFieldForm.value.field_label) return
  const listId = selectedList.value
  if (!listId) return

  if (editingCustomFieldId.value) {
    const result = await mailingListsStore.updateCustomField(editingCustomFieldId.value, customFieldForm.value)
    if (!result.success) return
  } else {
    const result = await mailingListsStore.createCustomField(listId, customFieldForm.value)
    if (!result.success) return
  }
  showCustomFieldForm.value = false
  editingCustomFieldId.value = null
  await loadCustomFields(listId)
}

async function removeCustomField(fieldId) {
  if (!confirm('Delete this custom field?')) return
  await mailingListsStore.deleteCustomField(fieldId)
  const listId = selectedList.value
  if (listId) await loadCustomFields(listId)
}

// Multi-select
function toggleSelectMode() {
  multiSelectMode.value = !multiSelectMode.value
  if (!multiSelectMode.value) {
    selectedContacts.value.clear()
  }
}

function toggleContactSelection(id) {
  if (selectedContacts.value.has(id)) {
    selectedContacts.value.delete(id)
  } else {
    selectedContacts.value.add(id)
  }
}

function selectAllContacts() {
  currentListContacts.value.forEach(c => selectedContacts.value.add(c.id))
}

function clearSelection() {
  selectedContacts.value.clear()
}

async function deleteSelectedContacts() {
  if (selectedContacts.value.size === 0) return
  
  if (!confirm(`Delete ${selectedContacts.value.size} selected contact(s)?`)) return
  
  const result = await mailingListsStore.bulkDeleteContacts([...selectedContacts.value])
  if (result.success) {
    toast.success(`Deleted ${result.deleted} contact(s)`)
    selectedContacts.value.clear()
    multiSelectMode.value = false
  } else {
    toast.error(result.error || 'Failed to delete contacts')
  }
}

// Import
function openImport() {
  importFile.value = null
  importData.value = []
  importPreview.value = []
  showImportModal.value = true
}

function handleFileSelect(event) {
  const file = event.target.files?.[0]
  if (!file) return
  
  importFile.value = file
  parseImportFile(file)
}

async function parseImportFile(file) {
  importParsing.value = true
  importData.value = []
  importPreview.value = []
  
  try {
    const text = await file.text()
    const lines = text.split('\n').filter(l => l.trim())
    
    if (lines.length === 0) {
      toast.error('File is empty')
      return
    }
    
    // Parse header
    const headerLine = lines[0]
    const header = headerLine.split(/[,;\t]/).map(h => {
      h = h.trim().toLowerCase().replace(/^["']|["']$/g, '')
      // Map common variations
      const mappings = {
        'e-mail': 'email',
        'email address': 'email',
        'e-mail address': 'email',
        'full name': 'name',
        'phone number': 'phone',
        'telephone': 'phone',
        'mobile': 'phone',
        'job title': 'position',
        'title': 'position',
        'role': 'position',
        'organization': 'company',
        'organisation': 'company',
        'firm': 'company',
      }
      return mappings[h] || h
    })
    
    // Parse data rows
    const contacts = []
    for (let i = 1; i < lines.length; i++) {
      const line = lines[i].trim()
      if (!line) continue
      
      // Simple CSV parsing (handles basic cases)
      const values = line.split(/[,;\t]/).map(v => v.trim().replace(/^["']|["']$/g, ''))
      
      const contact = {}
      header.forEach((col, idx) => {
        if (values[idx]) contact[col] = values[idx]
      })
      
      if (contact.email) {
        contacts.push(contact)
      }
    }
    
    importData.value = contacts
    importPreview.value = contacts.slice(0, 5)
    
  } catch (e) {
    toast.error('Failed to parse file: ' + e.message)
  } finally {
    importParsing.value = false
  }
}

async function doImport() {
  if (importData.value.length === 0) {
    toast.error('No contacts to import')
    return
  }
  
  const list = mailingListsStore.currentList
  if (!list) return
  
  importing.value = true
  
  const result = await mailingListsStore.importContacts(
    list.id, 
    importData.value,
    importFile.value?.name
  )
  
  importing.value = false
  
  if (result.success) {
    let msg = `Imported ${result.imported} contact(s)`
    if (result.skipped > 0) msg += `, ${result.skipped} skipped (duplicates)`
    if (result.errors?.length > 0) msg += `, ${result.errors.length} errors`
    toast.success(msg)
    showImportModal.value = false
  } else {
    toast.error(result.error || 'Failed to import contacts')
  }
}

// Download CSV template example
function downloadCsvExample() {
  const csvContent = `name,email,phone,position,company
John Doe,john.doe@example.com,+1 234 567 890,Marketing Manager,Acme Inc.
Jane Smith,jane.smith@company.com,+1 987 654 321,Sales Director,Tech Corp
Bob Johnson,bob.j@startup.io,,Developer,StartupXYZ
Alice Brown,alice@agency.com,+44 20 1234 5678,Project Manager,Creative Agency
Mike Wilson,mike.wilson@enterprise.com,+49 30 123456,CEO,Enterprise Solutions`

  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'mailing_list_template.csv'
  link.click()
  URL.revokeObjectURL(link.href)
}


// Lifecycle
onMounted(async () => {
  init()
  // Always fetch accounts for the dropdown
  await accountsStore.fetchAccounts()
})
</script>

<template>
  <div class="h-screen flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint">
    <!-- Top bar -->
    <AppHeader
      current-view="mailing-lists"
      icon="contact_mail"
      title="Emailing Lists"
    >
      <template #title-badge>
        <span 
          v-if="mailingListsStore.lists.length > 0"
          class="px-2 py-0.5 text-xs font-medium bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 rounded-full"
        >
          {{ mailingListsStore.lists.length }}
        </span>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>
    
    <!-- Main content -->
    <div class="flex-1 flex overflow-hidden">
      <!-- Sidebar - Drive style -->
      <aside class="w-64 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex flex-col overflow-hidden">
        <!-- New List button -->
        <div class="p-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
          <button
            @click="openCreateList"
            class="btn-secondary btn-sm w-full"
          >
            <span class="material-symbols-rounded">add</span>
            New List
          </button>
        </div>
        
        <!-- Lists -->
        <div class="flex-1 overflow-y-auto p-2">
        
          <!-- All lists button -->
          <button
            @click="selectedList = null"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors mb-1',
              selectedList === null 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-lg">folder_copy</span>
            <span class="flex-1 text-left">All Lists</span>
            <span class="text-xs text-surface-500">{{ mailingListsStore.lists.length }}</span>
          </button>
          
          <div class="border-t border-surface-200 dark:border-surface-700 my-2"></div>
          
          <!-- Individual lists -->
          <button
            v-for="list in mailingListsStore.sortedLists"
            :key="list.id"
            @click="selectedList = list.id"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
              selectedList === list.id 
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
          >
            <span 
              class="w-6 h-6 rounded-md flex items-center justify-center text-white text-xs"
              :style="{ backgroundColor: list.color || '#6366f1' }"
            >
              <span class="material-symbols-rounded text-sm">{{ list.icon || 'mail' }}</span>
            </span>
            <div class="flex-1 min-w-0 flex items-center gap-1.5">
              <span class="truncate">{{ list.name }}</span>
              <span 
                v-if="list.is_shared"
                class="material-symbols-rounded text-xs text-primary-500 shrink-0"
                title="Shared with team"
              >group</span>
            </div>
            <span class="text-xs text-surface-500 shrink-0">{{ list.contact_count || 0 }}</span>
          </button>
          
          <div v-if="mailingListsStore.lists.length === 0 && !loading" class="text-center py-8 text-surface-500">
            <span class="material-symbols-rounded text-4xl mb-2 block">folder_off</span>
            <p class="text-sm">No lists yet</p>
            <p class="text-xs mt-1">Click + to get started</p>
          </div>
        </div>
        
        <!-- Navigation -->
        <div class="px-2 py-2 border-t border-surface-200 dark:border-[rgb(var(--color-border))]">
          <button
            @click="$router.push('/campaigns')"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-primary-400">campaign</span>
            <span class="flex-1 text-left">Campaigns</span>
            <span class="material-symbols-rounded text-base text-surface-400">arrow_forward</span>
          </button>
        </div>
      </aside>
      
      <!-- Main area - Contacts -->
      <main class="flex-1 flex flex-col overflow-hidden bg-surface-50 dark:bg-surface-900">
        <!-- Toolbar -->
        <div class="flex-shrink-0 p-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))]">
          <div class="flex items-center gap-4">
            <!-- Search -->
            <div class="flex-1 max-w-md relative">
              <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
              <input
                v-model="search"
                type="text"
                placeholder="Search contacts..."
                class="w-full pl-10 pr-4 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none text-sm"
              />
            </div>
            
            <template v-if="mailingListsStore.currentList">
              <!-- Multi-select toggle -->
              <button
                @click="toggleSelectMode"
                :class="[
                  'flex items-center gap-2 px-3 py-2 rounded-full text-sm font-medium transition-colors',
                  multiSelectMode 
                    ? 'bg-red-500 text-white' 
                    : 'bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg">{{ multiSelectMode ? 'close' : 'checklist' }}</span>
                <span>{{ multiSelectMode ? 'Done' : 'Select' }}</span>
              </button>
              
              <!-- Filters dropdown -->
              <div class="relative">
                <button
                  @click="showFiltersDropdown = !showFiltersDropdown"
                  :class="[
                    'flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-full transition-colors',
                    activeFiltersCount > 0 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">filter_list</span>
                  <span>Filters</span>
                  <span v-if="activeFiltersCount > 0" class="w-5 h-5 rounded-full bg-white text-primary-500 text-xs font-bold flex items-center justify-center">
                    {{ activeFiltersCount }}
                  </span>
                </button>
                
                <!-- Filters Dropdown -->
                <div v-if="showFiltersDropdown" class="fixed inset-0 z-40" @click="showFiltersDropdown = false"></div>
                <div 
                  v-if="showFiltersDropdown"
                  class="absolute right-0 top-full mt-2 w-64 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 p-4 z-50"
                >
                  <div class="flex items-center justify-between mb-3">
                    <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Filters</h4>
                    <button 
                      v-if="activeFiltersCount > 0"
                      @click="clearFilters"
                      class="text-xs text-primary-500 hover:underline"
                    >
                      Clear all
                    </button>
                  </div>
                  
                  <!-- Position filter -->
                  <div class="mb-3">
                    <label class="block text-xs font-medium text-surface-500 mb-1">Position</label>
                    <select 
                      v-model="positionFilter"
                      class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500"
                    >
                      <option value="">All positions</option>
                      <option v-for="pos in uniquePositions" :key="pos" :value="pos">{{ pos }}</option>
                    </select>
                  </div>
                  
                  <!-- Company filter -->
                  <div>
                    <label class="block text-xs font-medium text-surface-500 mb-1">Company</label>
                    <select 
                      v-model="companyFilter"
                      class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500"
                    >
                      <option value="">All companies</option>
                      <option v-for="comp in uniqueCompanies" :key="comp" :value="comp">{{ comp }}</option>
                    </select>
                  </div>
                </div>
              </div>
              
              <!-- Import button -->
              <button
                @click="openImport"
                class="flex items-center gap-2 px-3 py-2 rounded-full text-sm font-medium bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 transition-colors"
              >
                <span class="material-symbols-rounded text-lg">upload_file</span>
                <span>Import</span>
              </button>
              
              <!-- Add contact -->
              <button
                @click="openAddContact"
                class="flex items-center gap-2 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors"
              >
                <span class="material-symbols-rounded text-lg">person_add</span>
                <span>Add Contact</span>
              </button>
              
              <!-- List actions -->
              <div class="flex items-center gap-1">
                <button
                  @click="openEditList"
                  class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
                  title="Edit list"
                >
                  <span class="material-symbols-rounded text-surface-600">edit</span>
                </button>
                <button
                  @click="deleteList"
                  class="p-2 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/20 text-red-500 transition-colors"
                  title="Delete list"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </div>
            </template>
          </div>
          
          <!-- Multi-select bar -->
          <div 
            v-if="multiSelectMode"
            class="mt-3 flex items-center gap-3 px-3 py-2 bg-primary-50 dark:bg-primary-900/20 rounded-lg"
          >
            <span class="text-sm text-primary-700 dark:text-primary-300">
              {{ selectedContacts.size }} selected
            </span>
            <button 
              @click="selectAllContacts"
              class="text-sm text-primary-600 hover:underline"
            >Select all visible</button>
            <button 
              v-if="selectedContacts.size > 0"
              @click="clearSelection"
              class="text-sm text-primary-600 hover:underline"
            >Clear</button>
            <div class="flex-1"></div>
            <button
              v-if="selectedContacts.size > 0"
              @click="deleteSelectedContacts"
              class="flex items-center gap-1 px-3 py-1 bg-red-500 hover:bg-red-600 text-white text-sm rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-sm">delete</span>
              Delete selected
            </button>
          </div>
        </div>
        
        <!-- Feature Guide -->
        <div v-if="showFeatureGuide" class="px-4 pt-4">
          <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-4">
          <!-- No list selected -->
          <div v-if="!mailingListsStore.currentList" class="flex flex-col items-center justify-center h-full text-surface-500">
            <span class="material-symbols-rounded text-6xl mb-3">contact_mail</span>
            <p class="text-lg">Select a list to view contacts</p>
            <p class="text-sm mt-1">Or create a new mailing list</p>
          </div>
          
          <!-- Loading -->
          <div v-else-if="loading" class="flex items-center justify-center py-12">
            <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">progress_activity</span>
          </div>
          
          <!-- Empty state -->
          <div v-else-if="currentListContacts.length === 0" class="flex flex-col items-center justify-center py-12 text-center">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">person_off</span>
            <p class="text-surface-600 dark:text-surface-400">
              {{ search ? 'No contacts found matching your search' : 'No contacts in this list yet' }}
            </p>
            <p v-if="!search" class="text-sm text-surface-500 mt-1">
              Click "Add Contact" or "Import" to get started
            </p>
          </div>
          
          <!-- Contacts table -->
          <div v-else class="bg-white dark:bg-surface-800 rounded-lg border border-surface-200 dark:border-surface-700 overflow-hidden">
            <table class="w-full">
              <thead class="bg-surface-50 dark:bg-surface-900 border-b border-surface-200 dark:border-surface-700">
                <tr>
                  <th v-if="multiSelectMode" class="w-10 px-3 py-3"></th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide">Name</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide">Email</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide">Phone</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide">Position</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide">Company</th>
                  <th class="w-20 px-4 py-3"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-surface-100 dark:divide-surface-700">
                <tr 
                  v-for="contact in currentListContacts" 
                  :key="contact.id"
                  :class="[
                    'transition-colors',
                    selectedContacts.has(contact.id) 
                      ? 'bg-primary-50 dark:bg-primary-900/20' 
                      : 'hover:bg-surface-50 dark:hover:bg-surface-700/50'
                  ]"
                >
                  <td v-if="multiSelectMode" class="px-3 py-3">
                    <button 
                      @click="toggleContactSelection(contact.id)"
                      :class="[
                        'w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all',
                        selectedContacts.has(contact.id)
                          ? 'bg-primary-500 border-primary-500 text-white'
                          : 'border-surface-300 dark:border-surface-600'
                      ]"
                    >
                      <span v-if="selectedContacts.has(contact.id)" class="material-symbols-rounded text-sm">check</span>
                    </button>
                  </td>
                  <td class="px-4 py-3">
                    <span class="text-surface-900 dark:text-surface-100 font-medium">
                      {{ contact.name || '-' }}
                    </span>
                  </td>
                  <td class="px-4 py-3">
                    <a :href="`mailto:${contact.email}`" class="text-primary-500 hover:underline">
                      {{ contact.email }}
                    </a>
                  </td>
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400">
                    {{ contact.phone || '-' }}
                  </td>
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400">
                    {{ contact.position || '-' }}
                  </td>
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400">
                    {{ contact.company || '-' }}
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-1">
                      <button
                        @click="openEditContact(contact)"
                        class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                        title="Edit"
                      >
                        <span class="material-symbols-rounded text-surface-500 text-lg">edit</span>
                      </button>
                      <button
                        @click="deleteContact(contact)"
                        class="p-1.5 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/20 text-red-500 transition-colors"
                        title="Delete"
                      >
                        <span class="material-symbols-rounded text-lg">delete</span>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <!-- Custom Fields Section -->
          <div v-if="mailingListsStore.currentList" class="mt-6 p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl border border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-lg text-primary-500">tune</span>
                Custom Fields
              </h3>
              <button
                @click="showCustomFieldForm = true; editingCustomFieldId = null; customFieldForm = { field_label: '', field_type: 'text', options: [] }"
                class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-xs font-medium transition-colors flex items-center gap-1"
              >
                <span class="material-symbols-rounded text-sm">add</span>
                Add Field
              </button>
            </div>
            
            <div v-if="customFields.length === 0" class="text-center py-6 text-sm text-surface-400">
              No custom fields defined yet. Add fields to store extra data on contacts.
            </div>
            
            <div v-else class="space-y-2">
              <div v-for="field in customFields" :key="field.id" class="flex items-center justify-between px-3 py-2 bg-white dark:bg-surface-700 rounded-lg">
                <div class="flex items-center gap-3">
                  <span class="text-xs font-mono bg-surface-100 dark:bg-surface-600 px-2 py-0.5 rounded text-surface-500">{{ '{' + field.field_key + '}' }}</span>
                  <span class="text-sm text-surface-900 dark:text-surface-100">{{ field.field_label }}</span>
                  <span class="text-xs text-surface-400 capitalize">{{ field.field_type }}</span>
                </div>
                <div class="flex items-center gap-1">
                  <button @click="editCustomField(field)" class="p-1.5 text-surface-400 hover:text-primary-500 transition-colors rounded-lg hover:bg-surface-100 dark:hover:bg-surface-600">
                    <span class="material-symbols-rounded text-sm">edit</span>
                  </button>
                  <button @click="removeCustomField(field.id)" class="p-1.5 text-surface-400 hover:text-red-500 transition-colors rounded-lg hover:bg-surface-100 dark:hover:bg-surface-600">
                    <span class="material-symbols-rounded text-sm">delete</span>
                  </button>
                </div>
              </div>
            </div>
            
            <!-- Add/Edit Custom Field Form -->
            <div v-if="showCustomFieldForm" class="mt-4 p-4 bg-white dark:bg-surface-700 rounded-xl border border-surface-200 dark:border-surface-600">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="text-xs font-medium text-surface-500 mb-1 block">Field Label</label>
                  <input v-model="customFieldForm.field_label" class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-600 border border-surface-200 dark:border-surface-500 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary-500/30" placeholder="e.g. Industry" />
                </div>
                <div>
                  <label class="text-xs font-medium text-surface-500 mb-1 block">Field Type</label>
                  <select v-model="customFieldForm.field_type" class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-600 border border-surface-200 dark:border-surface-500 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary-500/30">
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <option value="select">Select (Dropdown)</option>
                  </select>
                </div>
              </div>
              <div class="mt-3 flex justify-end gap-2">
                <button @click="showCustomFieldForm = false" class="px-3 py-1.5 bg-surface-100 dark:bg-surface-600 hover:bg-surface-200 dark:hover:bg-surface-500 text-surface-700 dark:text-surface-300 rounded-full text-xs font-medium transition-colors">Cancel</button>
                <button @click="saveCustomField" class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-xs font-medium transition-colors">
                  {{ editingCustomFieldId ? 'Update' : 'Add' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
    
    <!-- Create List Modal -->
    <div v-if="showCreateListModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-semibold">New Mailing List</h2>
          <button @click="showCreateListModal = false" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Name *</label>
            <input 
              v-model="listForm.name"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
              placeholder="e.g., Newsletter Subscribers"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Description</label>
            <textarea 
              v-model="listForm.description"
              rows="2"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none resize-none"
              placeholder="Optional description..."
            ></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Color</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="color in listColors"
                :key="color"
                @click="listForm.color = color"
                :class="[
                  'w-8 h-8 rounded-full transition-transform',
                  listForm.color === color ? 'ring-2 ring-offset-2 ring-primary-500 scale-110' : ''
                ]"
                :style="{ backgroundColor: color }"
              ></button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Icon</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="icon in listIcons"
                :key="icon"
                @click="listForm.icon = icon"
                :class="[
                  'w-10 h-10 rounded-lg flex items-center justify-center transition-colors',
                  listForm.icon === icon 
                    ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600' 
                    : 'bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded">{{ icon }}</span>
              </button>
            </div>
          </div>
          
          <!-- Visibility toggle -->
          <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between">
              <div>
                <label class="block text-sm font-medium">Share with team</label>
                <p class="text-xs text-surface-500">Make this list visible to all team members</p>
              </div>
              <button
                type="button"
                @click="listForm.is_shared = !listForm.is_shared"
                :class="[
                  'relative w-12 h-6 rounded-full transition-colors',
                  listForm.is_shared ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span 
                  :class="[
                    'absolute top-1 w-4 h-4 bg-white rounded-full transition-all shadow',
                    listForm.is_shared ? 'left-7' : 'left-1'
                  ]"
                ></span>
              </button>
            </div>
          </div>
        </div>
        <div class="flex justify-end gap-3 p-4 border-t border-surface-200 dark:border-surface-700">
          <button 
            @click="showCreateListModal = false"
            class="px-4 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >Cancel</button>
          <button 
            @click="createList"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
          >Create List</button>
        </div>
      </div>
    </div>
    
    <!-- Edit List Modal -->
    <div v-if="showEditListModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-semibold">Edit Mailing List</h2>
          <button @click="showEditListModal = false" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Name *</label>
            <input 
              v-model="listForm.name"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Description</label>
            <textarea 
              v-model="listForm.description"
              rows="2"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none resize-none"
            ></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Color</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="color in listColors"
                :key="color"
                @click="listForm.color = color"
                :class="[
                  'w-8 h-8 rounded-full transition-transform',
                  listForm.color === color ? 'ring-2 ring-offset-2 ring-primary-500 scale-110' : ''
                ]"
                :style="{ backgroundColor: color }"
              ></button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Icon</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="icon in listIcons"
                :key="icon"
                @click="listForm.icon = icon"
                :class="[
                  'w-10 h-10 rounded-lg flex items-center justify-center transition-colors',
                  listForm.icon === icon 
                    ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600' 
                    : 'bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded">{{ icon }}</span>
              </button>
            </div>
          </div>
          
          <!-- Visibility toggle -->
          <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between">
              <div>
                <label class="block text-sm font-medium">Share with team</label>
                <p class="text-xs text-surface-500">Make this list visible to all team members</p>
              </div>
              <button
                type="button"
                @click="listForm.is_shared = !listForm.is_shared"
                :class="[
                  'relative w-12 h-6 rounded-full transition-colors',
                  listForm.is_shared ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span 
                  :class="[
                    'absolute top-1 w-4 h-4 bg-white rounded-full transition-all shadow',
                    listForm.is_shared ? 'left-7' : 'left-1'
                  ]"
                ></span>
              </button>
            </div>
          </div>
        </div>
        <div class="flex justify-end gap-3 p-4 border-t border-surface-200 dark:border-surface-700">
          <button 
            @click="showEditListModal = false"
            class="px-4 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >Cancel</button>
          <button 
            @click="updateList"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
          >Save Changes</button>
        </div>
      </div>
    </div>
    
    <!-- Add Contact Modal -->
    <div v-if="showAddContactModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-semibold">Add Contact</h2>
          <button @click="showAddContactModal = false" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Email *</label>
            <input 
              v-model="contactForm.email"
              type="email"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
              placeholder="contact@example.com"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input 
              v-model="contactForm.name"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
              placeholder="John Doe"
            />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-1">Phone</label>
              <input 
                v-model="contactForm.phone"
                type="tel"
                class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
                placeholder="+1 234 567 890"
              />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Position</label>
              <input 
                v-model="contactForm.position"
                type="text"
                class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
                placeholder="Marketing Manager"
              />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Company</label>
            <input 
              v-model="contactForm.company"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
              placeholder="Acme Inc."
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Notes</label>
            <textarea 
              v-model="contactForm.notes"
              rows="2"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none resize-none"
              placeholder="Optional notes..."
            ></textarea>
          </div>
          
          <!-- Custom Fields -->
          <template v-if="customFields.length > 0">
            <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
              <p class="text-xs font-medium text-surface-500 mb-2 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">tune</span>
                Custom Fields
              </p>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div v-for="field in customFields" :key="field.field_key">
                <label class="block text-sm font-medium mb-1">{{ field.field_label }}</label>
                <input
                  v-if="field.field_type === 'text'"
                  v-model="contactForm.custom_fields[field.field_key]"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                  :placeholder="field.field_label"
                />
                <input
                  v-else-if="field.field_type === 'number'"
                  v-model.number="contactForm.custom_fields[field.field_key]"
                  type="number"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                />
                <input
                  v-else-if="field.field_type === 'date'"
                  v-model="contactForm.custom_fields[field.field_key]"
                  type="date"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                />
                <select
                  v-else-if="field.field_type === 'select'"
                  v-model="contactForm.custom_fields[field.field_key]"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                >
                  <option value="">-- Select --</option>
                  <option v-for="opt in (field.options || [])" :key="opt" :value="opt">{{ opt }}</option>
                </select>
              </div>
            </div>
          </template>
        </div>
        <div class="flex justify-end gap-3 p-4 border-t border-surface-200 dark:border-surface-700">
          <button 
            @click="showAddContactModal = false"
            class="px-4 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >Cancel</button>
          <button 
            @click="addContact"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
          >Add Contact</button>
        </div>
      </div>
    </div>
    
    <!-- Edit Contact Modal -->
    <div v-if="showEditContactModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-semibold">Edit Contact</h2>
          <button @click="showEditContactModal = false" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Email *</label>
            <input 
              v-model="contactForm.email"
              type="email"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input 
              v-model="contactForm.name"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
            />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-1">Phone</label>
              <input 
                v-model="contactForm.phone"
                type="tel"
                class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Position</label>
              <input 
                v-model="contactForm.position"
                type="text"
                class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
              />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Company</label>
            <input 
              v-model="contactForm.company"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Notes</label>
            <textarea 
              v-model="contactForm.notes"
              rows="2"
              class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none resize-none"
            ></textarea>
          </div>
          
          <!-- Custom Fields -->
          <template v-if="customFields.length > 0">
            <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
              <p class="text-xs font-medium text-surface-500 mb-2 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">tune</span>
                Custom Fields
              </p>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div v-for="field in customFields" :key="field.field_key">
                <label class="block text-sm font-medium mb-1">{{ field.field_label }}</label>
                <input
                  v-if="field.field_type === 'text'"
                  v-model="contactForm.custom_fields[field.field_key]"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                  :placeholder="field.field_label"
                />
                <input
                  v-else-if="field.field_type === 'number'"
                  v-model.number="contactForm.custom_fields[field.field_key]"
                  type="number"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                />
                <input
                  v-else-if="field.field_type === 'date'"
                  v-model="contactForm.custom_fields[field.field_key]"
                  type="date"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                />
                <select
                  v-else-if="field.field_type === 'select'"
                  v-model="contactForm.custom_fields[field.field_key]"
                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                >
                  <option value="">-- Select --</option>
                  <option v-for="opt in (field.options || [])" :key="opt" :value="opt">{{ opt }}</option>
                </select>
              </div>
            </div>
          </template>
        </div>
        <div class="flex justify-end gap-3 p-4 border-t border-surface-200 dark:border-surface-700">
          <button 
            @click="showEditContactModal = false"
            class="px-4 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >Cancel</button>
          <button 
            @click="updateContact"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
          >Save Changes</button>
        </div>
      </div>
    </div>
    
    <!-- Import Modal -->
    <div v-if="showImportModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-semibold">Import Contacts</h2>
          <button @click="showImportModal = false" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="p-4 space-y-4 flex-1 overflow-y-auto">
          <!-- File upload -->
          <div>
            <label class="block text-sm font-medium mb-2">Upload CSV or Excel file</label>
            <div 
              class="border-2 border-dashed border-surface-300 dark:border-surface-600 rounded-lg p-6 text-center cursor-pointer hover:border-primary-400 transition-colors"
              @click="$refs.fileInput.click()"
            >
              <input 
                ref="fileInput"
                type="file" 
                accept=".csv,.xlsx,.xls"
                class="hidden"
                @change="handleFileSelect"
              />
              <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">upload_file</span>
              <p class="text-surface-600 dark:text-surface-400">
                {{ importFile ? importFile.name : 'Click to select a file' }}
              </p>
              <p class="text-xs text-surface-500 mt-1">Supported formats: CSV, Excel (.xlsx, .xls)</p>
            </div>
          </div>
          
          <!-- Expected format -->
          <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
            <div class="flex items-start gap-2">
              <span class="material-symbols-rounded text-blue-500">info</span>
              <div class="flex-1">
                <p class="text-sm font-medium text-blue-700 dark:text-blue-300">Expected columns:</p>
                <p class="text-sm text-blue-600 dark:text-blue-400">
                  name, email, phone, position, company (email is required)
                </p>
                <button
                  @click="downloadCsvExample"
                  class="mt-2 inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                >
                  <span class="material-symbols-rounded text-sm">download</span>
                  Download CSV template
                </button>
              </div>
            </div>
          </div>
          
          <!-- Parsing -->
          <div v-if="importParsing" class="flex items-center justify-center py-8">
            <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
            <span class="ml-2 text-surface-600">Parsing file...</span>
          </div>
          
          <!-- Preview -->
          <div v-else-if="importPreview.length > 0">
            <p class="text-sm font-medium mb-2">
              Preview ({{ importData.length }} contacts found)
            </p>
            <div class="border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden">
              <table class="w-full text-sm">
                <thead class="bg-surface-50 dark:bg-surface-900">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Name</th>
                    <th class="px-3 py-2 text-left font-medium">Email</th>
                    <th class="px-3 py-2 text-left font-medium">Phone</th>
                    <th class="px-3 py-2 text-left font-medium">Position</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-surface-100 dark:divide-surface-700">
                  <tr v-for="(contact, i) in importPreview" :key="i">
                    <td class="px-3 py-2">{{ contact.name || '-' }}</td>
                    <td class="px-3 py-2">{{ contact.email }}</td>
                    <td class="px-3 py-2">{{ contact.phone || '-' }}</td>
                    <td class="px-3 py-2">{{ contact.position || '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-if="importData.length > 5" class="text-xs text-surface-500 mt-2">
              And {{ importData.length - 5 }} more...
            </p>
          </div>
        </div>
        <div class="flex justify-end gap-3 p-4 border-t border-surface-200 dark:border-surface-700">
          <button 
            @click="showImportModal = false"
            class="px-4 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >Cancel</button>
          <button 
            @click="doImport"
            :disabled="importData.length === 0 || importing"
            :class="[
              'px-4 py-2 rounded-lg transition-colors flex items-center gap-2',
              importData.length === 0 || importing
                ? 'bg-surface-300 dark:bg-surface-600 text-surface-500 cursor-not-allowed'
                : 'bg-primary-500 hover:bg-primary-600 text-white'
            ]"
          >
            <span v-if="importing" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
            {{ importing ? 'Importing...' : `Import ${importData.length} Contact${importData.length !== 1 ? 's' : ''}` }}
          </button>
        </div>
      </div>
    </div>

    <StepGuide
      v-if="showStepGuide"
      :title-key="mailingListsGuide.titleKey"
      :subtitle-key="mailingListsGuide.subtitleKey"
      :header-icon="mailingListsGuide.headerIcon"
      :header-color="mailingListsGuide.headerColor"
      :storage-key="mailingListsGuide.storageKey"
      :steps="mailingListsGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<style scoped>
/* Custom scrollbar */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: rgb(var(--color-surface-300));
  border-radius: 3px;
}
.dark ::-webkit-scrollbar-thumb {
  background: rgb(var(--color-surface-600));
}
</style>

