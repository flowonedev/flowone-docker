<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const route = useRoute()
const token = computed(() => route.params.token)
const { t, locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

const loading = ref(true)
const error = ref(null)
const rootFolder = ref(null) // The originally shared folder
const currentFolder = ref(null) // Current folder we're viewing
const files = ref([])
const subfolders = ref([])
const breadcrumb = ref([]) // Path from root to current folder
const allSubfolders = ref([]) // All subfolders in the shared tree

// Share info
const shareInfo = ref(null)
const requiresPassword = ref(false)
const limitReached = ref(false)
const passwordInput = ref('')
const passwordError = ref('')
const verifyingPassword = ref(false)

// Thumbnail cache
const thumbnailCache = ref({})

// File selection
const selectedFiles = ref(new Set())
const selectMode = ref(false)
const downloadingZip = ref(false)

// Sidebar state
const sidebarOpen = ref(false)
const treeExpanded = ref({})

// Toggle tree node expansion
function toggleTreeNode(folderId) {
  treeExpanded.value[folderId] = !treeExpanded.value[folderId]
}

// Toggle file selection
function toggleFileSelection(fileId) {
  if (selectedFiles.value.has(fileId)) {
    selectedFiles.value.delete(fileId)
  } else {
    selectedFiles.value.add(fileId)
  }
  // Force reactivity
  selectedFiles.value = new Set(selectedFiles.value)
}

// Check if file is selected
function isFileSelected(fileId) {
  return selectedFiles.value.has(fileId)
}

// Select all files
function selectAllFiles() {
  files.value.forEach(file => selectedFiles.value.add(file.id))
  selectedFiles.value = new Set(selectedFiles.value)
}

// Clear selection
function clearSelection() {
  selectedFiles.value = new Set()
  selectMode.value = false
}

// Get selected files count
const selectedCount = computed(() => selectedFiles.value.size)

// First, fetch share info to check if password is required
async function fetchShareInfo() {
  loading.value = true
  error.value = null
  
  try {
    // Try to access the folder - if password required, we'll get a 403
    const response = await fetch(`/api/drive/folder-share/${token.value}`)
    const data = await response.json()
    
    // Check for download limit reached FIRST
    if (response.status === 403 && (data.errors?.limit_reached)) {
      limitReached.value = true
      shareInfo.value = { 
        name: data.errors?.folder_name || t('sharedFolderView.sharedFolder'),
        max_downloads: data.errors?.max_downloads,
        download_count: data.errors?.download_count
      }
      loading.value = false
      return
    }
    
    // Check for password requirement - data is in errors object from Response::error()
    if (response.status === 403 && (data.requires_password || data.errors?.requires_password)) {
      requiresPassword.value = true
      shareInfo.value = { name: data.folder_name || data.errors?.folder_name || t('sharedFolderView.sharedFolder') }
      loading.value = false
      return
    }
    
    if (data.success) {
      requiresPassword.value = false
      limitReached.value = false
      currentFolder.value = data.data.folder
      rootFolder.value = data.data.folder
      files.value = data.data.files || []
      subfolders.value = data.data.subfolders || []
      allSubfolders.value = data.data.subfolders || []
      shareInfo.value = data.data.share_info || null
      breadcrumb.value = []
      clearSelection()
      // Expand root by default
      if (rootFolder.value) {
        treeExpanded.value[rootFolder.value.id] = true
      }
      // Load thumbnails for images
      loadThumbnails()
    } else {
      error.value = data.message || t('sharedFolderView.failedToLoadFolder')
    }
  } catch (e) {
    error.value = t('sharedFolderView.linkInvalidOrExpired')
  }
  
  loading.value = false
}

// Submit password
async function submitPassword() {
  if (!passwordInput.value.trim()) {
    passwordError.value = t('sharedFolderView.pleaseEnterPassword')
    return
  }
  
  verifyingPassword.value = true
  passwordError.value = ''
  
  try {
    const response = await fetch(`/api/drive/folder-share/${token.value}`, {
      method: 'GET',
      headers: {
        'X-Share-Password': passwordInput.value
      }
    })
    const data = await response.json()
    
    if (response.status === 403) {
      passwordError.value = t('sharedFolderView.incorrectPassword')
      verifyingPassword.value = false
      return
    }
    
    if (data.success) {
      requiresPassword.value = false
      currentFolder.value = data.data.folder
      rootFolder.value = data.data.folder
      files.value = data.data.files || []
      subfolders.value = data.data.subfolders || []
      allSubfolders.value = data.data.subfolders || []
      shareInfo.value = data.data.share_info || null
      breadcrumb.value = []
      clearSelection()
      // Expand root by default
      if (rootFolder.value) {
        treeExpanded.value[rootFolder.value.id] = true
      }
      // Load thumbnails for images
      loadThumbnails()
    } else {
      passwordError.value = data.message || t('sharedFolderView.failedToAccessFolder')
    }
  } catch (e) {
    passwordError.value = t('sharedFolderView.failedToVerifyPassword')
  }
  
  verifyingPassword.value = false
}

// Fetch shared folder data (root or subfolder)
async function fetchFolderData(subfolderId = null) {
  loading.value = true
  error.value = null
  
  try {
    let url = `/api/drive/folder-share/${token.value}`
    if (subfolderId) {
      url = `/api/drive/folder-share/${token.value}/subfolder/${subfolderId}`
    }
    
    const headers = {}
    if (passwordInput.value) {
      headers['X-Share-Password'] = passwordInput.value
    }
    
    const response = await fetch(url, { headers })
    const data = await response.json()
    
    if (data.success) {
      currentFolder.value = data.data.folder
      files.value = data.data.files || []
      subfolders.value = data.data.subfolders || []
      
      // For root folder, store it as rootFolder
      if (!subfolderId) {
        rootFolder.value = data.data.folder
        shareInfo.value = data.data.share_info || null
        breadcrumb.value = []
        allSubfolders.value = data.data.subfolders || []
      } else {
        // For subfolders, use the path from backend
        breadcrumb.value = data.data.path || []
      }
      clearSelection()
      // Load thumbnails for images
      loadThumbnails()
    } else {
      error.value = data.message || t('sharedFolderView.failedToLoadFolder')
    }
  } catch (e) {
    error.value = t('sharedFolderView.linkInvalidOrExpired')
  }
  
  loading.value = false
}

// Load thumbnails for image files
function loadThumbnails() {
  files.value.forEach(file => {
    if (file.mime_type?.startsWith('image/') && !thumbnailCache.value[file.id]) {
      thumbnailCache.value[file.id] = 'loading'
      // Build thumbnail URL
      let url = `/api/drive/folder-share/${token.value}/file/${file.id}`
      if (passwordInput.value) {
        url += `?p=${encodeURIComponent(passwordInput.value)}`
      }
      // Preload image
      const img = new Image()
      img.onload = () => {
        thumbnailCache.value[file.id] = url
      }
      img.onerror = () => {
        thumbnailCache.value[file.id] = 'error'
      }
      img.src = url
    }
  })
}

// Get thumbnail URL
function getThumbnailUrl(file) {
  const cached = thumbnailCache.value[file.id]
  if (cached && cached !== 'loading' && cached !== 'error') {
    return cached
  }
  return null
}

// Navigate into a subfolder
function navigateToSubfolder(subfolder) {
  fetchFolderData(subfolder.id)
  sidebarOpen.value = false
}

// Navigate back to root
function navigateToRoot() {
  fetchFolderData(null)
  sidebarOpen.value = false
}

// Navigate to a specific breadcrumb item
function navigateToBreadcrumb(item) {
  if (item.id === rootFolder.value?.id) {
    navigateToRoot()
  } else {
    fetchFolderData(item.id)
  }
}

// Select folder from sidebar
function selectFolder(folderId) {
  if (folderId === rootFolder.value?.id) {
    navigateToRoot()
  } else {
    fetchFolderData(folderId)
  }
  sidebarOpen.value = false
}

// Get file icon info with colors (matching DriveView)
function getFileIconInfo(mimeType) {
  // Images
  if (mimeType?.startsWith('image/')) {
    return { icon: 'image', color: 'text-pink-500', bgColor: 'bg-pink-100 dark:bg-pink-500/20' }
  }
  // Videos
  if (mimeType?.startsWith('video/')) {
    return { icon: 'movie', color: 'text-purple-500', bgColor: 'bg-purple-100 dark:bg-purple-500/20' }
  }
  // Audio
  if (mimeType?.startsWith('audio/')) {
    return { icon: 'audio_file', color: 'text-violet-500', bgColor: 'bg-violet-100 dark:bg-violet-500/20' }
  }
  // PDF
  if (mimeType?.includes('pdf')) {
    return { icon: 'picture_as_pdf', color: 'text-red-500', bgColor: 'bg-red-100 dark:bg-red-500/20' }
  }
  // Excel/Spreadsheets
  if (mimeType?.includes('spreadsheet') || mimeType?.includes('sheet') || mimeType?.includes('excel') || mimeType?.includes('csv')) {
    return { icon: 'table_chart', color: 'text-green-600', bgColor: 'bg-green-100 dark:bg-green-500/20' }
  }
  // PowerPoint/Presentations
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) {
    return { icon: 'slideshow', color: 'text-orange-500', bgColor: 'bg-orange-100 dark:bg-orange-500/20' }
  }
  // Word documents
  if (mimeType?.includes('word') || mimeType?.includes('msword') || mimeType?.includes('wordprocessing')) {
    return { icon: 'description', color: 'text-blue-600', bgColor: 'bg-blue-100 dark:bg-blue-500/20' }
  }
  // Archives
  if (mimeType?.includes('zip') || mimeType?.includes('compressed') || mimeType?.includes('archive') || mimeType?.includes('rar') || mimeType?.includes('7z')) {
    return { icon: 'folder_zip', color: 'text-amber-600', bgColor: 'bg-amber-100 dark:bg-amber-500/20' }
  }
  // Code files
  if (mimeType?.includes('javascript') || mimeType?.includes('json') || mimeType?.includes('xml') || mimeType?.includes('html') || mimeType?.includes('css') || mimeType?.includes('php')) {
    return { icon: 'code', color: 'text-cyan-500', bgColor: 'bg-cyan-100 dark:bg-cyan-500/20' }
  }
  // Text files
  if (mimeType?.includes('text/')) {
    return { icon: 'article', color: 'text-slate-500', bgColor: 'bg-slate-100 dark:bg-slate-500/20' }
  }
  // Default
  return { icon: 'draft', color: 'text-slate-500', bgColor: 'bg-slate-100 dark:bg-slate-500/20' }
}

// Format file size
function formatSize(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  while (bytes >= 1024 && i < units.length - 1) {
    bytes /= 1024
    i++
  }
  return `${bytes.toFixed(i > 0 ? 1 : 0)} ${units[i]}`
}

// Format date
function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString(localeTag.value, { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

// Format expiry date
function formatExpiry(dateStr) {
  if (!dateStr) return t('sharedFolderView.never')
  
  // Parse as UTC - MySQL datetime is stored in server time (UTC)
  // Convert "2026-01-14 15:30:00" to "2026-01-14T15:30:00Z" for proper UTC parsing
  const utcDateStr = dateStr.replace(' ', 'T') + 'Z'
  const date = new Date(utcDateStr)
  const now = new Date()
  const diff = date - now
  
  if (diff < 0) return t('sharedFolderView.expired')
  
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))
  const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))
  const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
  
  if (days > 0) {
    return t('sharedFolderView.daysLeft', days, { count: days })
  } else if (hours > 0) {
    return t('sharedFolderView.hoursLeft', hours, { count: hours })
  } else if (minutes > 0) {
    return t('sharedFolderView.minutesLeft', minutes, { count: minutes })
  } else {
    return t('sharedFolderView.lessThanOneMinute')
  }
}

function isExpired(dateStr) {
  if (!dateStr) return false
  const utcDateStr = dateStr.replace(' ', 'T') + 'Z'
  const date = new Date(utcDateStr)
  return date.getTime() - Date.now() < 0
}

// Download file with limit checking
async function downloadFile(file) {
  // Check if limit reached before attempting download (local check)
  if (shareInfo.value?.max_downloads && shareInfo.value.download_count >= shareInfo.value.max_downloads) {
    alert(t('sharedFolderView.downloadLimitReachedNoMoreDownloadsAllowed'))
    return
  }
  
  // Build download URL
  let url = `/api/drive/folder-share/${token.value}/file/${file.id}`
  if (passwordInput.value) {
    url += `?p=${encodeURIComponent(passwordInput.value)}`
  }
  
  // Use direct window.open for file download (simplest and most reliable)
  // The backend will handle limit checking and increment the count
  window.open(url, '_blank')
  
  // Update local download count after a short delay (give server time to process)
  setTimeout(() => {
    if (shareInfo.value && shareInfo.value.max_downloads !== null) {
      shareInfo.value.download_count = (shareInfo.value.download_count || 0) + 1
      shareInfo.value.downloads_remaining = Math.max(0, shareInfo.value.max_downloads - shareInfo.value.download_count)
      shareInfo.value.limit_reached = shareInfo.value.download_count >= shareInfo.value.max_downloads
    }
  }, 500)
}

// Download all files as zip
async function downloadAllAsZip() {
  if (downloadingZip.value) return
  
  // Check if limit reached
  if (shareInfo.value?.max_downloads && shareInfo.value.download_count >= shareInfo.value.max_downloads) {
    alert(t('sharedFolderView.downloadLimitReachedNoMoreDownloadsAllowed'))
    return
  }
  
  downloadingZip.value = true
  
  // Build zip download URL
  let url = `/api/drive/folder-share/${token.value}/zip`
  const params = []
  
  if (passwordInput.value) {
    params.push(`p=${encodeURIComponent(passwordInput.value)}`)
  }
  
  // If we're in a subfolder, include that
  if (currentFolder.value?.id && currentFolder.value.id !== rootFolder.value?.id) {
    params.push(`subfolder=${currentFolder.value.id}`)
  }
  
  if (params.length > 0) {
    url += '?' + params.join('&')
  }
  
  // Use window.open for download
  window.open(url, '_blank')
  
  // Update local download count after a short delay
  setTimeout(() => {
    downloadingZip.value = false
    if (shareInfo.value && shareInfo.value.max_downloads !== null) {
      shareInfo.value.download_count = (shareInfo.value.download_count || 0) + 1
      shareInfo.value.downloads_remaining = Math.max(0, shareInfo.value.max_downloads - shareInfo.value.download_count)
      shareInfo.value.limit_reached = shareInfo.value.download_count >= shareInfo.value.max_downloads
    }
  }, 1000)
}

// Download selected files as zip
async function downloadSelectedAsZip() {
  if (downloadingZip.value || selectedCount.value === 0) return
  
  // Check if limit reached
  if (shareInfo.value?.max_downloads && shareInfo.value.download_count >= shareInfo.value.max_downloads) {
    alert(t('sharedFolderView.downloadLimitReachedNoMoreDownloadsAllowed'))
    return
  }
  
  downloadingZip.value = true
  
  // Build zip download URL with selected file IDs
  let url = `/api/drive/folder-share/${token.value}/zip`
  const params = []
  
  if (passwordInput.value) {
    params.push(`p=${encodeURIComponent(passwordInput.value)}`)
  }
  
  // Add selected file IDs
  const fileIds = Array.from(selectedFiles.value).join(',')
  params.push(`files=${fileIds}`)
  
  if (params.length > 0) {
    url += '?' + params.join('&')
  }
  
  // Use window.open for download
  window.open(url, '_blank')
  
  // Update local download count after a short delay
  setTimeout(() => {
    downloadingZip.value = false
    clearSelection()
    if (shareInfo.value && shareInfo.value.max_downloads !== null) {
      shareInfo.value.download_count = (shareInfo.value.download_count || 0) + 1
      shareInfo.value.downloads_remaining = Math.max(0, shareInfo.value.max_downloads - shareInfo.value.download_count)
      shareInfo.value.limit_reached = shareInfo.value.download_count >= shareInfo.value.max_downloads
    }
  }, 1000)
}

// Handle file card click
function handleFileClick(event, file) {
  if (selectMode.value) {
    toggleFileSelection(file.id)
  }
}

// Is at root folder
const isAtRoot = computed(() => breadcrumb.value.length === 0)

// Total files size
const totalFilesSize = computed(() => {
  return files.value.reduce((sum, file) => sum + (file.size || 0), 0)
})

// Is mobile
const isMobile = ref(false)

function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  fetchShareInfo()
  checkMobile()
  window.addEventListener('resize', checkMobile)
})
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900" :class="isMobile ? 'overflow-y-auto' : 'overflow-hidden'">
    <!-- Header -->
    <header class="flex-shrink-0 flex items-center justify-between px-4 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 safe-area-top min-h-safe-top">
      <div class="flex items-center gap-3">
        <!-- Mobile menu button -->
        <button 
          @click="sidebarOpen = !sidebarOpen"
          class="md:hidden p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
        >
          <span class="material-symbols-rounded">menu</span>
        </button>
        
        <div class="w-9 h-9 bg-primary-500 rounded-xl flex items-center justify-center">
          <span class="material-symbols-rounded text-white text-lg">folder_shared</span>
        </div>
        <div>
          <h1 class="text-sm font-semibold text-surface-900 dark:text-surface-100">{{ $t('sharedFolderView.sharedFolder') }}</h1>
          <p class="text-xs text-surface-500">{{ $t('sharedFolderView.viewAndDownloadFiles') }}</p>
        </div>
      </div>
      
      <!-- Share info badges -->
      <div v-if="shareInfo && !loading && !error && !requiresPassword && !limitReached" class="hidden sm:flex items-center gap-4 text-xs">
        <div class="flex items-center gap-1.5">
          <span class="material-symbols-rounded text-sm" :class="shareInfo.share_expires ? 'text-amber-500' : 'text-green-500'">
            {{ shareInfo.share_expires ? 'schedule' : 'all_inclusive' }}
          </span>
          <span :class="isExpired(shareInfo.share_expires) ? 'text-red-500' : 'text-surface-600 dark:text-surface-400'">
            {{ shareInfo.share_expires ? formatExpiry(shareInfo.share_expires) : $t('sharedFolderView.neverExpires') }}
          </span>
        </div>
        <div v-if="shareInfo.max_downloads" class="flex items-center gap-1.5">
          <span class="material-symbols-rounded text-sm text-blue-500">download</span>
          <span class="text-surface-600 dark:text-surface-400">{{ shareInfo.download_count || 0 }}/{{ shareInfo.max_downloads }}</span>
        </div>
        <div v-if="shareInfo.requires_password" class="flex items-center gap-1.5">
          <span class="material-symbols-rounded text-sm text-amber-500">lock</span>
          <span class="text-surface-600 dark:text-surface-400">{{ $t('sharedFolderView.protected') }}</span>
        </div>
      </div>
    </header>

    <!-- Main content area -->
    <div class="flex-1 flex overflow-hidden relative">
      <!-- Mobile sidebar overlay -->
      <div 
        v-if="isMobile && sidebarOpen"
        class="fixed inset-0 bg-black/50 z-40"
        @click="sidebarOpen = false"
      ></div>
      
      <!-- Sidebar -->
      <aside 
        :class="[
          'w-64 flex-shrink-0 border-r border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex flex-col overflow-hidden transition-transform duration-300 z-50',
          isMobile ? 'fixed inset-y-0 left-0' : '',
          isMobile && !sidebarOpen ? '-translate-x-full' : 'translate-x-0'
        ]"
      >
        <!-- Sidebar header -->
        <div class="p-4 border-b border-surface-200 dark:border-surface-700">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-amber-500 rounded-xl flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-white">folder</span>
            </div>
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-surface-900 dark:text-surface-100 truncate">{{ rootFolder?.name || $t('sharedFolderView.loading') }}</p>
              <p class="text-xs text-surface-500">{{ $t('sharedFolderView.sharedFolder') }}</p>
            </div>
          </div>
        </div>
        
        <!-- Folder tree -->
        <div class="flex-1 overflow-y-auto p-2">
          <!-- Loading state -->
          <div v-if="loading" class="flex items-center justify-center py-8">
            <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
          </div>
          
          <!-- Folder tree when loaded -->
          <template v-else-if="rootFolder && !error && !requiresPassword && !limitReached">
            <!-- Root folder -->
            <div 
              @click="selectFolder(rootFolder.id)"
              :class="[
                'flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer transition-colors text-sm',
                currentFolder?.id === rootFolder.id && isAtRoot
                  ? 'bg-primary-50 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400'
                  : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-lg text-amber-500">folder</span>
              <span class="truncate flex-1">{{ rootFolder.name }}</span>
            </div>
            
            <!-- Subfolders -->
            <div v-if="allSubfolders.length > 0" class="ml-4 mt-1 space-y-0.5">
              <div 
                v-for="subfolder in allSubfolders" 
                :key="subfolder.id"
                @click="selectFolder(subfolder.id)"
                :class="[
                  'flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer transition-colors text-sm',
                  currentFolder?.id === subfolder.id
                    ? 'bg-primary-50 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400'
                    : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg text-amber-500">folder</span>
                <span class="truncate flex-1">{{ subfolder.name }}</span>
              </div>
            </div>
          </template>
        </div>
        
        <!-- Sidebar footer with share info (mobile) -->
        <div v-if="shareInfo && !loading && !error && !requiresPassword && !limitReached" class="sm:hidden p-4 border-t border-surface-200 dark:border-surface-700 space-y-2 text-xs">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-sm" :class="shareInfo.share_expires ? 'text-amber-500' : 'text-green-500'">
              {{ shareInfo.share_expires ? 'schedule' : 'all_inclusive' }}
            </span>
            <span :class="isExpired(shareInfo.share_expires) ? 'text-red-500' : 'text-surface-600 dark:text-surface-400'">
              {{ shareInfo.share_expires ? formatExpiry(shareInfo.share_expires) : $t('sharedFolderView.neverExpires') }}
            </span>
          </div>
          <div v-if="shareInfo.max_downloads" class="flex items-center gap-2">
            <span class="material-symbols-rounded text-sm text-blue-500">download</span>
            <span class="text-surface-600 dark:text-surface-400">
              {{ $t('sharedFolderView.downloadsUsed', { used: (shareInfo.download_count || 0), max: shareInfo.max_downloads }) }}
            </span>
          </div>
        </div>
      </aside>
      
      <!-- Main content -->
      <main class="flex-1 flex flex-col overflow-hidden">
        <!-- Loading -->
        <div v-if="loading" class="flex-1 flex flex-col items-center justify-center">
          <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin mb-4">progress_activity</span>
          <p class="text-surface-500">{{ $t('sharedFolderView.loadingSharedFolder') }}</p>
        </div>

        <!-- Download Limit Reached -->
        <div v-else-if="limitReached" class="flex-1 flex flex-col items-center justify-center p-8">
          <div class="w-full max-w-md card p-8 text-center">
            <div class="w-16 h-16 bg-red-100 dark:bg-red-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
              <span class="material-symbols-rounded text-red-500 text-3xl">block</span>
            </div>
            <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">{{ $t('sharedFolderView.downloadLimitReached') }}</h2>
            <p class="text-surface-500 mb-4">{{ $t('sharedFolderView.downloadLimitReachedDescription') }}</p>
            <div class="bg-surface-100 dark:bg-surface-700 rounded-lg p-4">
              <p class="text-surface-700 dark:text-surface-300">
                <span class="text-surface-500">{{ $t('sharedFolderView.folderLabel') }}:</span> {{ shareInfo?.name }}
              </p>
              <p class="text-surface-700 dark:text-surface-300 mt-2">
                <span class="text-surface-500">{{ $t('sharedFolderView.downloadsUsedLabel') }}:</span> 
                <span class="text-red-500 font-semibold">{{ shareInfo?.download_count }} / {{ shareInfo?.max_downloads }}</span>
              </p>
            </div>
          </div>
        </div>

        <!-- Password Required -->
        <div v-else-if="requiresPassword" class="flex-1 flex flex-col items-center justify-center p-8">
          <div class="w-full max-w-md card p-8">
            <div class="text-center mb-6">
              <div class="w-16 h-16 bg-amber-100 dark:bg-amber-500/20 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-rounded text-amber-500 text-3xl">lock</span>
              </div>
              <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">{{ $t('sharedFolderView.passwordProtected') }}</h2>
              <p class="text-surface-500">{{ $t('sharedFolderView.folderRequiresPassword') }}</p>
              <p class="text-sm text-surface-400 mt-1">{{ shareInfo?.name }}</p>
            </div>
            
            <form @submit.prevent="submitPassword" class="space-y-4">
              <div>
                <input
                  v-model="passwordInput"
                  type="password"
                  :placeholder="$t('sharedFolderView.enterPassword')"
                  class="input"
                  :disabled="verifyingPassword"
                />
                <p v-if="passwordError" class="text-red-500 text-sm mt-2">{{ passwordError }}</p>
              </div>
              <button
                type="submit"
                :disabled="verifyingPassword"
                class="btn-primary w-full"
              >
                <span v-if="verifyingPassword" class="spinner w-4 h-4"></span>
                <span>{{ verifyingPassword ? $t('sharedFolderView.verifying') : $t('sharedFolderView.accessFolder') }}</span>
              </button>
            </form>
          </div>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="flex-1 flex flex-col items-center justify-center p-8">
          <div class="w-16 h-16 bg-red-100 dark:bg-red-500/20 rounded-full flex items-center justify-center mb-4">
            <span class="material-symbols-rounded text-red-500 text-3xl">link_off</span>
          </div>
          <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">{{ $t('sharedFolderView.linkUnavailable') }}</h2>
          <p class="text-surface-500 text-center max-w-md">{{ error }}</p>
        </div>

        <!-- Folder Content -->
        <template v-else>
          <!-- Toolbar -->
          <div class="flex items-center justify-between px-4 md:px-6 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
            <!-- Breadcrumbs -->
            <div class="flex items-center gap-2 text-sm min-w-0 flex-1">
              <button @click="navigateToRoot" class="text-primary-500 hover:underline flex items-center gap-1 flex-shrink-0">
                <span class="material-symbols-rounded text-lg">folder</span>
                <span class="hidden sm:inline">{{ rootFolder?.name }}</span>
              </button>
              <template v-for="(folder, i) in breadcrumb" :key="folder.id">
                <span class="text-surface-400 flex-shrink-0">/</span>
                <button 
                  @click="navigateToBreadcrumb(folder)"
                  :class="[
                    'truncate',
                    i === breadcrumb.length - 1 ? 'text-surface-700 dark:text-surface-300' : 'text-primary-500 hover:underline'
                  ]"
                >
                  {{ folder.name }}
                </button>
              </template>
            </div>
            
            <!-- File count -->
            <div class="text-xs text-surface-500 hidden md:block">
              {{ $t('sharedFolderView.filesCount', files.length, { count: files.length }) }}
              <span v-if="totalFilesSize > 0"> &middot; {{ formatSize(totalFilesSize) }}</span>
            </div>
          </div>
          
          <!-- Action Bar -->
          <div v-if="files.length > 0" class="flex flex-wrap items-center gap-2 px-4 md:px-6 py-3 bg-surface-50 dark:bg-surface-850 border-b border-surface-200 dark:border-surface-700">
            <!-- Download All Button -->
            <button 
              @click="downloadAllAsZip"
              :disabled="downloadingZip || files.length === 0"
              class="btn-primary btn-sm"
            >
              <span v-if="downloadingZip" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded text-lg">folder_zip</span>
              <span class="hidden sm:inline">{{ $t('sharedFolderView.downloadAll') }}</span>
            </button>
            
            <!-- Select Mode Toggle -->
            <button 
              @click="selectMode = !selectMode; if (!selectMode) clearSelection()"
              :class="[
                'btn-sm',
                selectMode ? 'btn-primary' : 'btn-secondary'
              ]"
            >
              <span class="material-symbols-rounded text-lg">{{ selectMode ? 'close' : 'checklist' }}</span>
              <span class="hidden sm:inline">{{ selectMode ? $t('sharedFolderView.cancel') : $t('sharedFolderView.select') }}</span>
            </button>
            
            <!-- Selection Actions (when files selected) -->
            <template v-if="selectMode && selectedCount > 0">
              <button 
                @click="selectAllFiles"
                class="btn-secondary btn-sm"
              >
                <span class="material-symbols-rounded text-lg">select_all</span>
                <span class="hidden sm:inline">{{ $t('sharedFolderView.all') }}</span>
              </button>
              
              <button 
                @click="downloadSelectedAsZip"
                :disabled="downloadingZip"
                class="btn-primary btn-sm"
              >
                <span v-if="downloadingZip" class="spinner w-4 h-4"></span>
                <span v-else class="material-symbols-rounded text-lg">download</span>
                <span>{{ selectedCount }}</span>
              </button>
            </template>
          </div>

          <!-- File Grid -->
          <div class="flex-1 overflow-y-auto p-4 md:p-6">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 md:gap-4">
              <!-- Subfolders in current view -->
              <div 
                v-for="subfolder in subfolders" 
                :key="'folder-' + subfolder.id"
                @click="navigateToSubfolder(subfolder)"
                class="group relative aspect-square card p-2 md:p-3 cursor-pointer hover:shadow-md transition-shadow flex flex-col items-center justify-center"
              >
                <div class="w-12 h-12 md:w-14 md:h-14 bg-amber-100 dark:bg-amber-500/20 rounded-xl flex items-center justify-center mb-2">
                  <span class="material-symbols-rounded text-amber-500 text-2xl md:text-3xl">folder</span>
                </div>
                <p class="text-xs font-medium text-surface-900 dark:text-surface-100 truncate w-full text-center px-1">{{ subfolder.name }}</p>
              </div>
              
              <!-- Files -->
              <div 
                v-for="file in files" 
                :key="'file-' + file.id"
                @click="handleFileClick($event, file)"
                :class="[
                  'group relative aspect-square card p-1.5 md:p-2 cursor-pointer transition-shadow flex flex-col items-center justify-center',
                  isFileSelected(file.id) 
                    ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/20' 
                    : 'hover:shadow-md'
                ]"
              >
                <!-- Selection checkbox (in select mode) -->
                <div 
                  v-if="selectMode"
                  @click.stop="toggleFileSelection(file.id)"
                  :class="[
                    'absolute top-2 left-2 w-5 h-5 rounded flex items-center justify-center transition-all z-10',
                    isFileSelected(file.id) 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-200 dark:bg-surface-600 text-surface-500 hover:bg-surface-300'
                  ]"
                >
                  <span class="material-symbols-rounded text-sm">
                    {{ isFileSelected(file.id) ? 'check' : '' }}
                  </span>
                </div>
                
                <!-- Image thumbnail -->
                <div v-if="file.mime_type?.startsWith('image/')" class="flex-1 w-full flex items-center justify-center overflow-hidden rounded bg-surface-100 dark:bg-surface-700">
                  <span 
                    v-if="thumbnailCache[file.id] === 'loading'"
                    class="material-symbols-rounded text-xl text-surface-400 animate-spin"
                  >progress_activity</span>
                  <img 
                    v-else-if="getThumbnailUrl(file)"
                    :src="getThumbnailUrl(file)" 
                    :alt="file.original_name"
                    class="w-full h-full object-cover"
                    loading="lazy"
                  />
                  <span 
                    v-else
                    class="material-symbols-rounded text-xl text-surface-400"
                  >image</span>
                </div>
                <!-- File icon for non-images -->
                <div v-else class="flex-1 flex items-center justify-center">
                  <div :class="['w-12 h-12 md:w-14 md:h-14 rounded-xl flex items-center justify-center', getFileIconInfo(file.mime_type).bgColor]">
                    <span :class="['material-symbols-rounded text-2xl md:text-3xl', getFileIconInfo(file.mime_type).color]">
                      {{ getFileIconInfo(file.mime_type).icon }}
                    </span>
                  </div>
                </div>
                
                <!-- File name and size -->
                <div class="w-full text-center mt-1 px-1">
                  <p class="text-xs font-medium text-surface-900 dark:text-surface-100 truncate" :title="file.original_name">
                    {{ file.original_name }}
                  </p>
                  <p class="text-[10px] text-surface-500">{{ formatSize(file.size) }}</p>
                </div>
                
                <!-- Download button (visible on hover, not in select mode) -->
                <div v-if="!selectMode" class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button 
                    @click.stop="downloadFile(file)"
                    class="p-1.5 rounded-lg bg-primary-500 hover:bg-primary-600 shadow-lg transition-colors"
                    :title="$t('sharedFolderView.download')"
                  >
                    <span class="material-symbols-rounded text-white text-sm">download</span>
                  </button>
                </div>
              </div>
            </div>

            <!-- Empty State -->
            <div v-if="files.length === 0 && subfolders.length === 0" class="flex flex-col items-center justify-center py-16">
              <div class="w-16 h-16 bg-surface-100 dark:bg-surface-700 rounded-full flex items-center justify-center mb-4">
                <span class="material-symbols-rounded text-surface-400 text-3xl">folder_off</span>
              </div>
              <p class="text-surface-500">{{ $t('sharedFolderView.folderIsEmpty') }}</p>
            </div>
          </div>
        </template>
      </main>
    </div>
  </div>
</template>

<style scoped>
.material-symbols-rounded {
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
</style>
