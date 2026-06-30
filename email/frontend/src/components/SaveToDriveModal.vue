<script setup>
import { ref, computed, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useDriveStore } from '@/stores/drive'

const toast = useToastStore()
const drive = useDriveStore()

const props = defineProps({
  show: Boolean,
  attachments: {
    type: Array,
    default: () => []
  },
  folder: String, // Email folder
  uid: Number,
  subject: String, // Email subject for auto-folder creation
  senderEmail: String // Sender email for client detection
})

const emit = defineEmits(['close', 'saved'])

// Auto-setup state
const autoSetupComplete = ref(false)
const autoSetupError = ref(null)

// Client detection state
const detectedClient = ref(null)

// State
const loading = ref(false)
const saving = ref(false)
const currentFolderId = ref(null)
const path = ref([{ id: null, name: 'My Drive' }])
const folders = ref([])
const showNewFolderInput = ref(false)
const newFolderName = ref('')
const creatingFolder = ref(false)

// Progress state
const progress = ref({
  current: 0,
  total: 0,
  currentFile: '',
  saved: [],
  failed: []
})

// Sanitize folder name (remove invalid characters)
function sanitizeFolderName(name) {
  if (!name) return 'Untitled'
  // Remove/replace characters that are problematic for folder names
  return name
    .replace(/[<>:"/\\|?*]/g, '-')
    .replace(/\s+/g, ' ')
    .trim()
    .substring(0, 100) || 'Untitled'
}

// Find or create a folder by name under a parent
async function findOrCreateFolder(parentId, folderName, detectClient = false) {
  // First check if folder exists
  const response = await api.get('/drive', { params: { folder_id: parentId || '' } })
  if (response.data.success) {
    const existingFolders = response.data.data.folders || []
    const existing = existingFolders.find(f => f.name.toLowerCase() === folderName.toLowerCase())
    if (existing) {
      return existing
    }
  }
  
  // Create the folder with client detection
  const createPayload = {
    name: folderName,
    parent_id: parentId
  }
  
  // Only pass sender_email for the subject folder (not Attachments root)
  if (detectClient && props.senderEmail) {
    createPayload.sender_email = props.senderEmail
  }
  
  const createResponse = await api.post('/drive/folders', createPayload)
  
  if (createResponse.data.success) {
    // Store detected client if returned
    if (createResponse.data.data.client) {
      detectedClient.value = createResponse.data.data.client
    }
    return createResponse.data.data.folder
  }
  
  throw new Error('Failed to create folder')
}

// Detect client from sender email
async function detectClientFromEmail() {
  if (!props.senderEmail) return null
  
  try {
    const response = await api.get('/drive/find-client', { 
      params: { email: props.senderEmail } 
    })
    if (response.data.success && response.data.data.client) {
      return response.data.data.client
    }
  } catch (e) {
    console.warn('Failed to detect client:', e)
  }
  return null
}

// Auto-setup the folder structure: Attachments / [Email Subject]
async function autoSetupFolderStructure() {
  if (!props.subject) {
    return // No subject, just open at root
  }
  
  autoSetupComplete.value = false
  autoSetupError.value = null
  detectedClient.value = null
  loading.value = true
  
  try {
    // Step 0: Detect client from sender email
    if (props.senderEmail) {
      detectedClient.value = await detectClientFromEmail()
    }
    
    // Step 1: Find or create "Attachments" folder at root
    const attachmentsFolder = await findOrCreateFolder(null, 'Attachments', false)
    
    // Step 2: Find or create subject folder inside Attachments (with client detection)
    const subjectName = sanitizeFolderName(props.subject)
    const subjectFolder = await findOrCreateFolder(attachmentsFolder.id, subjectName, true)
    
    // Step 3: Navigate to the subject folder
    path.value = [
      { id: null, name: 'My Drive' },
      { id: attachmentsFolder.id, name: attachmentsFolder.name },
      { id: subjectFolder.id, name: subjectFolder.name }
    ]
    currentFolderId.value = subjectFolder.id
    
    // Load the folder contents
    await loadFolders(subjectFolder.id)
    
    autoSetupComplete.value = true
  } catch (e) {
    console.error('Auto-setup failed:', e)
    autoSetupError.value = e.message
    // Fall back to root
    await loadFolders(null)
  }
  
  loading.value = false
}

// Load folder contents when modal opens
watch(() => props.show, async (show) => {
  if (show) {
    currentFolderId.value = null
    path.value = [{ id: null, name: 'My Drive' }]
    progress.value = { current: 0, total: 0, currentFile: '', saved: [], failed: [] }
    autoSetupComplete.value = false
    autoSetupError.value = null
    
    // Auto-setup folder structure based on email subject
    if (props.subject) {
      await autoSetupFolderStructure()
    } else {
      await loadFolders(null)
    }
  }
})

async function loadFolders(folderId) {
  loading.value = true
  try {
    const response = await api.get('/drive', { params: { folder_id: folderId || '' } })
    if (response.data.success) {
      folders.value = response.data.data.folders || []
      currentFolderId.value = folderId
    }
  } catch (e) {
    console.error('Failed to load folders:', e)
    toast.error('Failed to load Drive folders')
  }
  loading.value = false
}

async function navigateToFolder(folderId, folderName) {
  if (folderId === null) {
    path.value = [{ id: null, name: 'My Drive' }]
  } else {
    const existingIndex = path.value.findIndex(p => p.id === folderId)
    if (existingIndex !== -1) {
      path.value = path.value.slice(0, existingIndex + 1)
    } else {
      path.value.push({ id: folderId, name: folderName })
    }
  }
  await loadFolders(folderId)
}

async function createNewFolder() {
  if (!newFolderName.value.trim()) {
    toast.warning('Please enter a folder name')
    return
  }
  
  creatingFolder.value = true
  try {
    const response = await api.post('/drive/folders', {
      name: newFolderName.value.trim(),
      parent_id: currentFolderId.value
    })
    
    if (response.data.success) {
      const newFolder = response.data.data.folder
      folders.value.unshift(newFolder)
      newFolderName.value = ''
      showNewFolderInput.value = false
      toast.success('Folder created')
      
      // Navigate into the new folder
      await navigateToFolder(newFolder.id, newFolder.name)
    } else {
      toast.error(response.data.message || 'Failed to create folder')
    }
  } catch (e) {
    toast.error('Failed to create folder')
  }
  creatingFolder.value = false
}

async function saveAttachments() {
  if (props.attachments.length === 0) return
  
  saving.value = true
  progress.value = {
    current: 0,
    total: props.attachments.length,
    currentFile: '',
    saved: [],
    failed: []
  }
  
  try {
    // Get all part numbers
    const parts = props.attachments.map(a => a.part)
    
    // Save all attachments in one request
    const response = await api.post('/mailbox/save-attachments-to-drive', {
      folder: props.folder,
      uid: props.uid,
      parts: parts,
      drive_folder_id: currentFolderId.value
    })
    
    if (response.data.success) {
      const data = response.data.data
      progress.value.saved = data.saved || []
      progress.value.failed = data.failed || []
      progress.value.current = data.total
      
      if (data.success_count > 0) {
        toast.success(`${data.success_count} attachment(s) saved to Drive`)
        // Refresh drive contents if we're viewing that folder
        if (drive.currentFolderId === currentFolderId.value) {
          drive.fetchContents(currentFolderId.value)
        }
        emit('saved', data)
      }
      
      if (data.failed_count > 0) {
        toast.warning(`${data.failed_count} attachment(s) failed to save`)
      }
      
      // Close modal after a brief delay to show success
      setTimeout(() => {
        emit('close')
      }, 1000)
    } else {
      toast.error(response.data.message || 'Failed to save attachments')
    }
  } catch (e) {
    console.error('Failed to save attachments:', e)
    toast.error('Failed to save attachments to Drive')
  }
  
  saving.value = false
}

function close() {
  if (!saving.value) {
    emit('close')
  }
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

const progressPercent = computed(() => {
  if (progress.value.total === 0) return 0
  return Math.round((progress.value.current / progress.value.total) * 100)
})

const currentFolderName = computed(() => {
  return path.value[path.value.length - 1]?.name || 'My Drive'
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div 
        v-if="show" 
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click.self="close"
      >
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" @click="close"></div>
        
        <!-- Modal -->
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
          <!-- Header -->
          <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-primary-500">cloud_upload</span>
              </div>
              <div>
                <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Save to Drive</h2>
                <p class="text-sm text-surface-500">{{ attachments.length }} attachment(s)</p>
              </div>
            </div>
            <button 
              @click="close"
              :disabled="saving"
              class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors disabled:opacity-50"
            >
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <!-- Content -->
          <div class="flex-1 overflow-y-auto">
            <!-- Progress overlay when saving -->
            <div v-if="saving" class="p-6">
              <div class="text-center mb-4">
                <span class="material-symbols-rounded text-4xl text-primary-500 animate-pulse">cloud_sync</span>
                <p class="text-surface-700 dark:text-surface-300 mt-2">Saving attachments to Drive...</p>
              </div>
              
              <!-- Progress bar -->
              <div class="relative h-3 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                <div 
                  class="absolute inset-y-0 left-0 bg-primary-500 transition-all duration-300"
                  :style="{ width: progressPercent + '%' }"
                ></div>
              </div>
              <p class="text-center text-sm text-surface-500 mt-2">
                {{ progress.current }} / {{ progress.total }} files
              </p>
              
              <!-- Results summary -->
              <div v-if="progress.saved.length > 0 || progress.failed.length > 0" class="mt-4 space-y-2">
                <div v-for="item in progress.saved" :key="item.part" class="flex items-center gap-2 text-sm text-green-600 dark:text-green-400">
                  <span class="material-symbols-rounded text-base">check_circle</span>
                  <span class="truncate">{{ item.filename }}</span>
                </div>
                <div v-for="item in progress.failed" :key="item.part" class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400">
                  <span class="material-symbols-rounded text-base">error</span>
                  <span class="truncate">{{ item.filename || 'File' }} - {{ item.error }}</span>
                </div>
              </div>
            </div>
            
            <!-- Folder browser -->
            <div v-else class="p-4">
              <!-- Attachments preview -->
              <div class="mb-4 p-3 bg-surface-50 dark:bg-surface-900 rounded-xl">
                <p class="text-xs font-medium text-surface-500 uppercase tracking-wide mb-2">Files to save</p>
                <div class="space-y-1.5 max-h-24 overflow-y-auto">
                  <div 
                    v-for="att in attachments" 
                    :key="att.part"
                    class="flex items-center gap-2 text-sm"
                  >
                    <span class="material-symbols-rounded text-surface-400 text-base">attachment</span>
                    <span class="truncate text-surface-700 dark:text-surface-300">{{ att.filename }}</span>
                    <span class="text-xs text-surface-400 flex-shrink-0">{{ formatSize(att.size) }}</span>
                  </div>
                </div>
              </div>
              
              <!-- Detected client indicator -->
              <div v-if="detectedClient" class="mb-4 p-3 bg-blue-50 dark:bg-blue-500/10 rounded-xl border border-blue-200 dark:border-blue-500/30">
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-blue-500 text-lg">business</span>
                  <div class="flex-1 min-w-0">
                    <p class="text-xs text-blue-600 dark:text-blue-400 font-medium">Client Detected</p>
                    <p class="text-sm text-blue-700 dark:text-blue-300 font-medium truncate">{{ detectedClient.name }}</p>
                  </div>
                  <span class="material-symbols-rounded text-blue-500 text-base">check_circle</span>
                </div>
                <p class="text-xs text-blue-500 dark:text-blue-400 mt-1">Folder will be linked to this client</p>
              </div>
              
              <!-- Breadcrumb -->
              <div class="flex items-center gap-1 text-sm mb-3 overflow-x-auto">
                <button 
                  v-for="(item, i) in path" 
                  :key="item.id || 'root'"
                  @click="navigateToFolder(item.id, item.name)"
                  class="flex items-center gap-1 px-2 py-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors whitespace-nowrap"
                  :class="i === path.length - 1 ? 'text-primary-500 font-medium' : 'text-surface-600 dark:text-surface-400'"
                >
                  <span v-if="i === 0" class="material-symbols-rounded text-base">home</span>
                  <span>{{ item.name }}</span>
                </button>
              </div>
              
              <!-- New folder input -->
              <div v-if="showNewFolderInput" class="flex items-center gap-2 mb-3">
                <input 
                  v-model="newFolderName"
                  @keyup.enter="createNewFolder"
                  type="text"
                  placeholder="New folder name"
                  class="flex-1 px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 outline-none"
                  autofocus
                />
                <button 
                  @click="createNewFolder"
                  :disabled="creatingFolder"
                  class="px-3 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium disabled:opacity-50"
                >
                  <span v-if="creatingFolder" class="spinner w-4 h-4"></span>
                  <span v-else>Create</span>
                </button>
                <button 
                  @click="showNewFolderInput = false; newFolderName = ''"
                  class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg"
                >
                  <span class="material-symbols-rounded text-sm">close</span>
                </button>
              </div>
              
              <!-- Create folder button -->
              <button 
                v-else
                @click="showNewFolderInput = true"
                class="flex items-center gap-2 w-full px-3 py-2 mb-3 border border-dashed border-surface-300 dark:border-surface-600 rounded-lg text-surface-600 dark:text-surface-400 hover:border-primary-500 hover:text-primary-500 transition-colors"
              >
                <span class="material-symbols-rounded text-lg">create_new_folder</span>
                <span class="text-sm">Create new folder</span>
              </button>
              
              <!-- Folder list -->
              <div v-if="loading" class="flex items-center justify-center py-8">
                <span class="spinner text-primary-500"></span>
              </div>
              
              <div v-else-if="folders.length === 0" class="text-center py-8 text-surface-500">
                <span class="material-symbols-rounded text-4xl mb-2 text-primary-500">folder_open</span>
                <p class="text-sm text-surface-700 dark:text-surface-300">Ready to save</p>
                <p class="text-xs">Files will be saved to "{{ currentFolderName }}"</p>
              </div>
              
              <div v-else class="space-y-1 max-h-48 overflow-y-auto">
                <button
                  v-for="folder in folders"
                  :key="folder.id"
                  @click="navigateToFolder(folder.id, folder.name)"
                  class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors group"
                >
                  <span class="material-symbols-rounded text-xl text-amber-500">folder</span>
                  <span class="flex-1 text-left text-sm text-surface-700 dark:text-surface-300 truncate">{{ folder.name }}</span>
                  <span class="material-symbols-rounded text-surface-400 opacity-0 group-hover:opacity-100 transition-opacity">chevron_right</span>
                </button>
              </div>
              
              <!-- Selected folder indicator -->
              <div class="mt-4 p-3 bg-primary-50 dark:bg-primary-500/10 rounded-xl border border-primary-200 dark:border-primary-500/30">
                <p class="text-xs text-primary-600 dark:text-primary-400 font-medium">Save location:</p>
                <p class="text-sm text-primary-700 dark:text-primary-300 font-medium mt-0.5">
                  {{ path.map(p => p.name).join(' / ') }}
                </p>
              </div>
            </div>
          </div>
          
          <!-- Footer -->
          <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900">
            <button 
              @click="close"
              :disabled="saving"
              class="px-4 py-2 rounded-full text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors disabled:opacity-50"
            >
              Cancel
            </button>
            <button 
              @click="saveAttachments"
              :disabled="saving || attachments.length === 0"
              class="px-5 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-full font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              <span v-if="saving" class="spinner w-4 h-4"></span>
              <span class="material-symbols-rounded text-lg" v-else>cloud_upload</span>
              {{ saving ? 'Saving...' : 'Save to Drive' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-active .relative,
.modal-leave-active .relative {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .relative,
.modal-leave-to .relative {
  transform: scale(0.95);
  opacity: 0;
}

.spinner {
  @apply inline-block border-2 border-current border-t-transparent rounded-full animate-spin;
}
</style>

