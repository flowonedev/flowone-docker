<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useDriveStore } from '@/stores/drive'
import { getRestrictions } from '@/services/driveShareApi'
import { getPublicOrigin } from '@/services/serverRegistry'
import FileAccessHistory from '@/components/drive/FileAccessHistory.vue'

const props = defineProps({
  show: Boolean,
  item: Object,
  type: { type: String, default: 'file' } // 'file' or 'folder'
})

const emit = defineEmits(['close'])

const drive = useDriveStore()

// State
const loading = ref(false)
const collaborators = ref([])
const shareInfo = ref(null)
const restrictions = ref({ no_download: false, no_print: false })
const chatSharedInChat = computed(() => {
  if (!props.item?.id) return false
  return drive.isSharedInChat(props.type, props.item.id)
})

// Computed
const isFolder = computed(() => props.type === 'folder')

const fileType = computed(() => {
  if (isFolder.value) return 'Folder'
  
  const mime = props.item?.mime_type || ''
  
  if (mime.startsWith('image/')) return 'Image'
  if (mime.startsWith('video/')) return 'Video'
  if (mime.startsWith('audio/')) return 'Audio'
  if (mime.includes('pdf')) return 'PDF Document'
  if (mime.includes('word') || mime.includes('document')) return 'Document'
  if (mime.includes('sheet') || mime.includes('excel')) return 'Spreadsheet'
  if (mime.includes('presentation') || mime.includes('powerpoint')) return 'Presentation'
  if (mime.includes('zip') || mime.includes('rar') || mime.includes('archive')) return 'Archive'
  if (mime.startsWith('text/')) return 'Text File'
  
  return 'File'
})

const hasPublicLink = computed(() => {
  return props.item?.share_token ? true : false
})

const shareExpiry = computed(() => {
  if (!props.item?.share_expires) return null
  return new Date(props.item.share_expires)
})

const isShareExpired = computed(() => {
  if (!shareExpiry.value) return false
  return shareExpiry.value < new Date()
})

// Generate the share link URL
const shareLink = computed(() => {
  if (!props.item?.share_token) return null
  const baseUrl = getPublicOrigin()
  if (isFolder.value) {
    return `${baseUrl}/drive/shared/${props.item.share_token}`
  } else {
    return `${baseUrl}/api/drive/download/${props.item.share_token}`
  }
})

// Copy link state
const linkCopied = ref(false)

const downloadLimitInfo = computed(() => {
  const max = props.item?.max_downloads
  const count = props.item?.download_count || 0
  
  if (!max) return null
  
  return {
    max,
    count,
    remaining: max - count,
    exhausted: count >= max
  }
})

const hasPassword = computed(() => {
  return props.item?.share_password ? true : false
})

const accessList = computed(() => {
  const list = []
  
  // Owner
  list.push({
    type: 'owner',
    email: props.item?.user_email || 'You',
    label: 'Owner',
    icon: 'shield_person',
    color: 'text-primary-500'
  })
  
  // Collaborators
  collaborators.value.forEach(c => {
    list.push({
      type: 'collaborator',
      email: c.email,
      label: c.permission === 'editor' ? 'Can edit' : 'Can view',
      icon: c.permission === 'editor' ? 'edit' : 'visibility',
      color: c.permission === 'editor' ? 'text-amber-500' : 'text-blue-500'
    })
  })
  
  // Public link
  if (hasPublicLink.value && !isShareExpired.value) {
    list.push({
      type: 'public',
      email: 'Anyone with link',
      label: 'Can download',
      icon: 'public',
      color: 'text-green-500'
    })
  }
  
  return list
})

const securityLimitations = computed(() => {
  const limitations = []
  
  if (shareExpiry.value) {
    limitations.push({
      icon: isShareExpired.value ? 'event_busy' : 'schedule',
      label: isShareExpired.value ? 'Link expired' : 'Expires',
      value: formatDate(shareExpiry.value),
      status: isShareExpired.value ? 'error' : 'warning'
    })
  }
  
  if (downloadLimitInfo.value) {
    const info = downloadLimitInfo.value
    limitations.push({
      icon: info.exhausted ? 'block' : 'download',
      label: 'Download limit',
      value: `${info.count} / ${info.max} used`,
      status: info.exhausted ? 'error' : (info.remaining <= 2 ? 'warning' : 'info')
    })
  }

  // View-only restrictions: apply to recipients with View access
  if (!isFolder.value && restrictions.value.no_download) {
    limitations.push({
      icon: 'file_download_off',
      label: 'Downloads disabled',
      value: 'Viewers cannot download this file',
      status: 'warning'
    })
  }

  if (!isFolder.value && restrictions.value.no_print) {
    limitations.push({
      icon: 'print_disabled',
      label: 'Printing disabled',
      value: 'Viewers cannot print this file',
      status: 'warning'
    })
  }
  
  if (hasPassword.value) {
    limitations.push({
      icon: 'lock',
      label: 'Password protected',
      value: 'Active',
      status: 'success'
    })
  }
  
  // Check if folder is protected (board-linked or system folder)
  if (isFolder.value && props.item) {
    // Board-linked folder
    if (props.item.board_id) {
      limitations.push({
        icon: 'shield',
        label: 'Protected folder',
        value: 'Linked to a board project - cannot be deleted',
        status: 'warning'
      })
    }
    // System "Boards" folder
    else if (props.item.name === 'Boards' && !props.item.parent_id) {
      limitations.push({
        icon: 'shield',
        label: 'System folder',
        value: 'Contains board project folders - cannot be deleted',
        status: 'warning'
      })
    }
    // System "Attachments" folder
    else if (props.item.name === 'Attachments' && !props.item.parent_id) {
      limitations.push({
        icon: 'shield',
        label: 'System folder',
        value: 'Contains saved email attachments - cannot be deleted',
        status: 'warning'
      })
    }
    // System "Chats" folder
    else if (props.item.name === 'Chats' && !props.item.parent_id) {
      limitations.push({
        icon: 'shield',
        label: 'System folder',
        value: 'Contains chat conversation files - cannot be deleted',
        status: 'warning'
      })
    }
    // System "Invoices" folder
    else if (props.item.name === 'Invoices' && !props.item.parent_id) {
      limitations.push({
        icon: 'shield',
        label: 'System folder',
        value: 'Contains invoice documents - cannot be deleted',
        status: 'warning'
      })
    }
    // System "Moodboards" folder
    else if (props.item.name === 'Moodboards' && !props.item.parent_id) {
      limitations.push({
        icon: 'shield',
        label: 'System folder',
        value: 'Contains moodboard assets - cannot be deleted',
        status: 'warning'
      })
    }
  }
  
  return limitations
})

// Load data when shown
watch(() => props.show, async (showing) => {
  if (showing && props.item) {
    await loadData()
  }
}, { immediate: true })

async function loadData() {
  loading.value = true
  
  try {
    // Load collaborators for folders
    if (isFolder.value && props.item?.id) {
      collaborators.value = await drive.fetchCollaborators(props.item.id)
    } else {
      collaborators.value = []
    }

    // Load view-only restrictions for files
    if (!isFolder.value && props.item?.id) {
      const data = await getRestrictions(props.item.id)
      restrictions.value = data
        ? { no_download: !!data.no_download, no_print: !!data.no_print }
        : { no_download: false, no_print: false }
    } else {
      restrictions.value = { no_download: false, no_print: false }
    }
  } catch (e) {
    console.error('Failed to load properties:', e)
  } finally {
    loading.value = false
  }
}

function close() {
  emit('close')
}

function formatSize(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let size = bytes
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024
    i++
  }
  return `${size.toFixed(size < 10 ? 1 : 0)} ${units[i]}`
}

function formatDate(date) {
  if (!date) return 'Unknown'
  const d = new Date(date)
  return d.toLocaleDateString('en-GB', { 
    day: 'numeric', 
    month: 'short', 
    year: 'numeric' 
  })
}

function formatDateTime(date) {
  if (!date) return 'Unknown'
  const d = new Date(date)
  return d.toLocaleDateString('en-GB', { 
    day: 'numeric', 
    month: 'short', 
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

function getInitials(email) {
  if (!email) return '?'
  return email.charAt(0).toUpperCase()
}

function getAvatarColor(email) {
  if (!email) return 'bg-surface-500'
  const colors = [
    'bg-red-500', 'bg-orange-500', 'bg-amber-500', 'bg-yellow-500',
    'bg-lime-500', 'bg-green-500', 'bg-emerald-500', 'bg-teal-500',
    'bg-cyan-500', 'bg-sky-500', 'bg-blue-500', 'bg-indigo-500',
    'bg-violet-500', 'bg-purple-500', 'bg-fuchsia-500', 'bg-pink-500'
  ]
  let hash = 0
  for (let i = 0; i < email.length; i++) {
    hash = email.charCodeAt(i) + ((hash << 5) - hash)
  }
  return colors[Math.abs(hash) % colors.length]
}

async function copyShareLink() {
  if (!shareLink.value) return
  
  try {
    await navigator.clipboard.writeText(shareLink.value)
    linkCopied.value = true
    setTimeout(() => {
      linkCopied.value = false
    }, 2000)
  } catch (e) {
    console.error('Failed to copy link:', e)
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="slide-right">
      <div 
        v-if="show" 
        class="fixed inset-y-0 right-0 z-50 flex"
        @click.self="close"
      >
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/30" @click="close"></div>
        
        <!-- Panel -->
        <div class="relative ml-auto w-full min-w-[340px] max-w-md bg-surface-900 shadow-xl flex flex-col h-full">
          <!-- Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-surface-700">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-2xl text-surface-400">
                {{ isFolder ? 'folder' : 'description' }}
              </span>
              <div>
                <h2 class="text-lg font-semibold text-surface-100">
                  {{ isFolder ? item?.name : item?.original_name }}
                </h2>
                <p class="text-sm text-surface-400">Properties</p>
              </div>
            </div>
            <button 
              @click="close"
              class="p-2 hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-surface-400">close</span>
            </button>
          </div>
          
          <!-- Content -->
          <div class="flex-1 overflow-y-auto">
            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center py-12">
              <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
            </div>
            
            <template v-else>
              <!-- Who has access -->
              <div class="px-6 py-4 border-b border-surface-700">
                <h3 class="text-sm font-semibold text-surface-100 mb-4 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">group</span>
                  Who has access
                </h3>
                
                <!-- Access avatars -->
                <div class="flex items-center gap-1 mb-3">
                  <div 
                    v-for="(access, idx) in accessList.slice(0, 5)" 
                    :key="idx"
                    :class="[
                      'w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-medium border-2 border-surface-900',
                      access.type === 'public' ? 'bg-green-600' : getAvatarColor(access.email)
                    ]"
                    :style="{ marginLeft: idx > 0 ? '-8px' : '0' }"
                    :title="access.email"
                  >
                    <span v-if="access.type === 'public'" class="material-symbols-rounded text-lg">public</span>
                    <template v-else>{{ getInitials(access.email) }}</template>
                  </div>
                  <div 
                    v-if="accessList.length > 5"
                    class="w-9 h-9 rounded-full bg-surface-600 flex items-center justify-center text-white text-xs font-medium border-2 border-surface-900"
                    style="margin-left: -8px"
                  >
                    +{{ accessList.length - 5 }}
                  </div>
                </div>
                
                <!-- Access description -->
                <p class="text-sm text-surface-400 mb-4">
                  <template v-if="accessList.length === 1 && !chatSharedInChat">
                    Private - only you have access
                  </template>
                  <template v-else-if="accessList.length === 1 && chatSharedInChat">
                    Private - referenced in chat (preview only)
                  </template>
                  <template v-else-if="hasPublicLink">
                    Shared publicly<template v-if="collaborators.length > 0"> and with {{ collaborators.length }} {{ collaborators.length === 1 ? 'person' : 'people' }}</template>
                  </template>
                  <template v-else-if="collaborators.length > 0">
                    Shared with {{ collaborators.length }} {{ collaborators.length === 1 ? 'person' : 'people' }}
                  </template>
                </p>
                
                <!-- Access list -->
                <div class="space-y-2">
                  <div 
                    v-for="(access, idx) in accessList" 
                    :key="idx"
                    class="flex items-center gap-3 p-2 rounded-lg bg-surface-800/50"
                  >
                    <div 
                      :class="[
                        'w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-medium',
                        access.type === 'public' ? 'bg-green-600' : getAvatarColor(access.email)
                      ]"
                    >
                      <span v-if="access.type === 'public'" class="material-symbols-rounded text-base">public</span>
                      <template v-else>{{ getInitials(access.email) }}</template>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-surface-200 truncate">
                        {{ access.type === 'owner' ? 'You' : access.email }}
                      </p>
                      <p class="text-xs text-surface-500">{{ access.label }}</p>
                    </div>
                    <span :class="['material-symbols-rounded text-lg', access.color]">
                      {{ access.icon }}
                    </span>
                  </div>
                </div>
              </div>
              
              <!-- Shared in Chat -->
              <div v-if="chatSharedInChat" class="px-6 py-4 border-b border-surface-700">
                <h3 class="text-sm font-semibold text-surface-100 mb-4 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">chat</span>
                  Shared in Chat
                </h3>
                
                <div class="p-3 rounded-lg bg-indigo-500/10 border border-indigo-500/30">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-500/20 text-indigo-400">
                      <span class="material-symbols-rounded text-sm">chat</span>
                      Referenced in Chat
                    </span>
                  </div>
                  <p class="text-xs text-surface-400 leading-relaxed">
                    This {{ isFolder ? 'folder' : 'file' }} was shared as an embed in a chat conversation. Chat participants can see the preview card, but Drive permissions still control who can download or edit.
                  </p>
                </div>
              </div>
              
              <!-- Security limitations -->
              <div class="px-6 py-4 border-b border-surface-700">
                <h3 class="text-sm font-semibold text-surface-100 mb-4 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">security</span>
                  Security limitations
                </h3>
                
                <div v-if="securityLimitations.length === 0" class="p-4 bg-surface-800 rounded-lg">
                  <p class="text-sm font-medium text-surface-300">No limitations applied</p>
                  <p class="text-xs text-surface-500 mt-1">If any are applied, they will appear here</p>
                </div>
                
                <div v-else class="space-y-3">
                  <div 
                    v-for="(limit, idx) in securityLimitations" 
                    :key="idx"
                    :class="[
                      'flex items-center gap-3 p-3 rounded-lg',
                      limit.status === 'error' ? 'bg-red-500/10 border border-red-500/30' :
                      limit.status === 'warning' ? 'bg-amber-500/10 border border-amber-500/30' :
                      limit.status === 'success' ? 'bg-green-500/10 border border-green-500/30' :
                      'bg-surface-800'
                    ]"
                  >
                    <span :class="[
                      'material-symbols-rounded text-xl',
                      limit.status === 'error' ? 'text-red-400' :
                      limit.status === 'warning' ? 'text-amber-400' :
                      limit.status === 'success' ? 'text-green-400' :
                      'text-surface-400'
                    ]">
                      {{ limit.icon }}
                    </span>
                    <div class="flex-1">
                      <p class="text-sm font-medium text-surface-200">{{ limit.label }}</p>
                      <p class="text-xs text-surface-400">{{ limit.value }}</p>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Open history (files only) -->
              <div v-if="!isFolder && item?.id" class="px-6 py-4 border-b border-surface-700">
                <h3 class="text-sm font-semibold text-surface-100 mb-4 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">history</span>
                  Open history
                </h3>
                <FileAccessHistory :file-id="item.id" />
              </div>

              <!-- Share Link (if shared) -->
              <div v-if="shareLink" class="px-6 py-4 border-b border-surface-700">
                <h3 class="text-sm font-semibold text-surface-100 mb-4 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">link</span>
                  Share Link
                </h3>
                
                <div :class="[
                  'rounded-lg p-3',
                  isShareExpired ? 'bg-red-500/10 border border-red-500/30' : 'bg-surface-800'
                ]">
                  <!-- Link status badge -->
                  <div class="flex items-center gap-2 mb-3">
                    <span :class="[
                      'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                      isShareExpired 
                        ? 'bg-red-500/20 text-red-400' 
                        : 'bg-green-500/20 text-green-400'
                    ]">
                      <span class="material-symbols-rounded text-sm">
                        {{ isShareExpired ? 'link_off' : 'check_circle' }}
                      </span>
                      {{ isShareExpired ? 'Expired' : 'Active' }}
                    </span>
                    <span v-if="shareExpiry" class="text-xs text-surface-500">
                      {{ isShareExpired ? 'Expired' : 'Expires' }} {{ formatDate(shareExpiry) }}
                    </span>
                  </div>
                  
                  <!-- Link URL -->
                  <div class="flex items-center gap-2">
                    <div class="flex-1 min-w-0">
                      <input 
                        type="text" 
                        :value="shareLink" 
                        readonly 
                        class="w-full px-3 py-2 bg-surface-900 border border-surface-600 rounded-lg text-sm text-surface-300 focus:outline-none select-all"
                        @focus="$event.target.select()"
                      />
                    </div>
                    <button 
                      @click="copyShareLink"
                      :class="[
                        'flex items-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-all',
                        linkCopied 
                          ? 'bg-green-500 text-white' 
                          : 'bg-primary-500 hover:bg-primary-600 text-white'
                      ]"
                    >
                      <span class="material-symbols-rounded text-lg">
                        {{ linkCopied ? 'check' : 'content_copy' }}
                      </span>
                      {{ linkCopied ? 'Copied' : 'Copy' }}
                    </button>
                  </div>
                  
                  <!-- Warning if expired -->
                  <p v-if="isShareExpired" class="text-xs text-red-400 mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">warning</span>
                    This link has expired and will not work. Create a new share link to share this {{ isFolder ? 'folder' : 'file' }}.
                  </p>
                </div>
              </div>
              
              <!-- File details -->
              <div class="px-6 py-4">
                <h3 class="text-sm font-semibold text-surface-100 mb-4 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">info</span>
                  {{ isFolder ? 'Folder' : 'File' }} details
                </h3>
                
                <div class="space-y-4">
                  <!-- Type -->
                  <div>
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Type</p>
                    <p class="text-sm text-surface-200">{{ fileType }}</p>
                  </div>
                  
                  <!-- Storage location -->
                  <div v-if="!isFolder && item?.storage_location">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Storage</p>
                    <div class="flex items-center gap-2">
                      <span 
                        :class="[
                          'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                          item.storage_location === 'nfs' 
                            ? 'bg-cyan-500/20 text-cyan-400' 
                            : 'bg-surface-600 text-surface-300'
                        ]"
                      >
                        <span class="material-symbols-rounded text-sm">
                          {{ item.storage_location === 'nfs' ? 'cloud_sync' : 'hard_drive' }}
                        </span>
                        {{ item.storage_location === 'nfs' ? 'NAS Storage' : 'Local Storage' }}
                      </span>
                    </div>
                  </div>
                  
                  <!-- Size (files only) -->
                  <div v-if="!isFolder && item?.size">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Size</p>
                    <p class="text-sm text-surface-200">{{ formatSize(item.size) }}</p>
                  </div>
                  
                  <!-- Item count (folders only) -->
                  <div v-if="isFolder">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Contents</p>
                    <p class="text-sm text-surface-200">
                      {{ item?.file_count || 0 }} files, {{ item?.subfolder_count || 0 }} folders
                    </p>
                  </div>
                  
                  <!-- Owner -->
                  <div>
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Owner</p>
                    <p class="text-sm text-surface-200">{{ item?.user_email || 'me' }}</p>
                  </div>
                  
                  <!-- Location -->
                  <div v-if="item?.folder_name || item?.parent_name">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Location</p>
                    <p class="text-sm text-surface-200">{{ item?.folder_name || item?.parent_name || 'My Drive' }}</p>
                  </div>
                  
                  <!-- Modified -->
                  <div v-if="item?.updated_at">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Modified</p>
                    <p class="text-sm text-surface-200">{{ formatDateTime(item.updated_at) }}</p>
                    <p v-if="item?.last_modified_by" class="text-xs text-surface-500 mt-0.5">by {{ item.last_modified_by }}</p>
                  </div>
                  
                  <!-- Opened -->
                  <div v-if="item?.last_opened_at">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Opened</p>
                    <p class="text-sm text-surface-200">{{ formatDateTime(item.last_opened_at) }}</p>
                  </div>
                  
                  <!-- Created -->
                  <div v-if="item?.created_at">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Created</p>
                    <p class="text-sm text-surface-200">{{ formatDateTime(item.created_at) }}</p>
                  </div>
                  
                  <!-- Version info (files only) -->
                  <div v-if="!isFolder && item?.current_version">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Version</p>
                    <p class="text-sm text-surface-200">Version {{ item.current_version }}</p>
                  </div>
                  
                  <!-- Share token (if shared) -->
                  <div v-if="hasPublicLink">
                    <p class="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Share Status</p>
                    <p :class="['text-sm', isShareExpired ? 'text-red-400' : 'text-green-400']">
                      {{ isShareExpired ? 'Link expired' : 'Active public link' }}
                    </p>
                  </div>
                </div>
              </div>
            </template>
          </div>
          
          <!-- Footer -->
          <div class="px-6 py-4 border-t border-surface-700 bg-surface-800/50">
            <button 
              @click="close"
              class="w-full px-4 py-2 bg-surface-700 hover:bg-surface-600 text-surface-200 rounded-lg font-medium transition-colors"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.slide-right-enter-active,
.slide-right-leave-active {
  transition: all 0.3s ease;
}

.slide-right-enter-active .relative,
.slide-right-leave-active .relative {
  transition: transform 0.3s ease;
}

.slide-right-enter-from,
.slide-right-leave-to {
  opacity: 0;
}

.slide-right-enter-from .relative,
.slide-right-leave-to .relative {
  transform: translateX(100%);
}
</style>

