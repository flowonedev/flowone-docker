<script setup>
import { ref, computed } from 'vue'
import Modal from './shared/Modal.vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { getToken } from '@/services/tokenStorage'
import { folderCollectionUrl } from '@/services/mailRouteService'
import { useMailboxStore } from '@/stores/mailbox'

// Check if any actual verification results exist (not just "unknown" or "not_checked")
const hasVerificationResults = computed(() => {
  if (!rawData.value) return false
  const validStatuses = ['pass', 'fail', 'softfail', 'neutral', 'none', 'temperror', 'permerror', 'signed']
  const spf = (rawData.value.spf?.status || '').toLowerCase()
  const dkim = (rawData.value.dkim?.status || '').toLowerCase()
  const dmarc = (rawData.value.dmarc?.status || '').toLowerCase()
  
  return validStatuses.includes(spf) || validStatuses.includes(dkim) || validStatuses.includes(dmarc)
})

// Check which verifications are missing
const missingVerifications = computed(() => {
  if (!rawData.value) return []
  const missing = []
  const notVerifiedStatuses = ['unknown', 'not_checked']
  
  if (notVerifiedStatuses.includes((rawData.value.spf?.status || '').toLowerCase())) {
    missing.push('SPF')
  }
  if (notVerifiedStatuses.includes((rawData.value.dkim?.status || '').toLowerCase())) {
    missing.push('DKIM')
  }
  if (notVerifiedStatuses.includes((rawData.value.dmarc?.status || '').toLowerCase())) {
    missing.push('DMARC')
  }
  return missing
})

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  folder: {
    type: String,
    required: true
  },
  uid: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['close'])

const toast = useToastStore()
const mailbox = useMailboxStore()
const loading = ref(false)
const rawData = ref(null)
const showRawSource = ref(false)
const copied = ref(false)

// Fetch raw message data when modal opens
async function fetchRawMessage() {
  if (!props.folder || !props.uid) return
  
  loading.value = true
  rawData.value = null
  showRawSource.value = false
  
  try {
    const response = await api.get(folderCollectionUrl(mailbox.folders, props.folder, `messages/${props.uid}/raw`))
    if (response.data.success) {
      rawData.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch raw message:', e)
    toast.error('Failed to load original message')
    emit('close')
  } finally {
    loading.value = false
  }
}

// Format date nicely
function formatDate(timestamp) {
  if (!timestamp) return ''
  const date = new Date(timestamp * 1000)
  return date.toLocaleString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZoneName: 'short'
  })
}

// Format recipients
function formatRecipients(recipients) {
  if (!recipients || !Array.isArray(recipients)) return ''
  return recipients.map(r => {
    if (r.name && r.email) {
      return `${r.name} <${r.email}>`
    }
    return r.email || r.display || ''
  }).filter(Boolean).join(', ')
}

// Format file size
function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' bytes'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB'
}

// Get status badge class
function getStatusClass(status) {
  const s = (status || '').toUpperCase()
  if (s === 'PASS') return 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400'
  if (s === 'FAIL' || s === 'SOFTFAIL' || s === 'PERMERROR') return 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400'
  if (s === 'NEUTRAL' || s === 'NONE' || s === 'TEMPERROR') return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400'
  if (s === 'SIGNED') return 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400'
  if (s === 'NOT_CHECKED') return 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400'
  return 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-400'
}

// Format status for display
function formatStatus(status) {
  const s = (status || '').toUpperCase()
  if (s === 'NOT_CHECKED') return 'NOT VERIFIED'
  if (s === 'BESTGUESSPASS') return 'PASS*'
  if (s === 'UNKNOWN') return 'N/A'
  return s || 'N/A'
}

// Copy to clipboard
async function copyToClipboard() {
  if (!rawData.value?.raw_source) return
  
  try {
    await navigator.clipboard.writeText(rawData.value.raw_source)
    copied.value = true
    toast.success('Copied to clipboard')
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (e) {
    toast.error('Failed to copy to clipboard')
  }
}

// Download as .eml file
function downloadOriginal() {
  if (!props.folder || !props.uid) return
  
  // Create download link
  const baseUrl = api.defaults.baseURL || '/api'
  const url = `${baseUrl}${folderCollectionUrl(mailbox.folders, props.folder, `messages/${props.uid}/download`)}`
  
  // Add auth token
  const token = getToken('webmail_token')
  
  // Open in new tab with auth header (fallback: direct download)
  const link = document.createElement('a')
  link.href = url + `?token=${encodeURIComponent(token)}`
  link.download = ''
  link.target = '_blank'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

// Watch for modal open
defineExpose({ fetchRawMessage })
</script>

<template>
  <Modal 
    :show="show" 
    title="Original Message"
    size="4xl"
    @close="emit('close')"
  >
    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <span class="spinner text-primary-500"></span>
    </div>
    
    <!-- Content -->
    <div v-else-if="rawData" class="space-y-6">
      <!-- Header info - responsive grid layout -->
      <div class="bg-surface-50 dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="divide-y divide-surface-200 dark:divide-surface-700">
          <!-- Message ID -->
          <div class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-1 md:gap-4 px-4 md:px-6 py-3">
            <div class="font-medium text-surface-500 dark:text-surface-400 text-sm">Message ID</div>
            <div class="text-primary-600 dark:text-primary-400 font-mono text-xs md:text-sm break-all">
              {{ rawData.message_id }}
            </div>
          </div>
          
          <!-- Created on -->
          <div class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-1 md:gap-4 px-4 md:px-6 py-3">
            <div class="font-medium text-surface-500 dark:text-surface-400 text-sm">Created on</div>
            <div class="text-surface-900 dark:text-surface-100">
              {{ formatDate(rawData.timestamp) }}
              <span v-if="rawData.delivery_time" class="text-surface-500 dark:text-surface-400 ml-2 text-sm">
                ({{ rawData.delivery_time.duration_text }})
              </span>
            </div>
          </div>
          
          <!-- From -->
          <div class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-1 md:gap-4 px-4 md:px-6 py-3">
            <div class="font-medium text-surface-500 dark:text-surface-400 text-sm">From</div>
            <div class="text-surface-900 dark:text-surface-100 break-all">
              {{ formatRecipients(rawData.from) }}
            </div>
          </div>
          
          <!-- To -->
          <div class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-1 md:gap-4 px-4 md:px-6 py-3">
            <div class="font-medium text-surface-500 dark:text-surface-400 text-sm">To</div>
            <div class="text-surface-900 dark:text-surface-100 break-all">
              {{ formatRecipients(rawData.to) }}
            </div>
          </div>
          
          <!-- CC (if present) -->
          <div v-if="rawData.cc?.length" class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-1 md:gap-4 px-4 md:px-6 py-3">
            <div class="font-medium text-surface-500 dark:text-surface-400 text-sm">Cc</div>
            <div class="text-surface-900 dark:text-surface-100 break-all">
              {{ formatRecipients(rawData.cc) }}
            </div>
          </div>
          
          <!-- Subject -->
          <div class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-1 md:gap-4 px-4 md:px-6 py-3">
            <div class="font-medium text-surface-500 dark:text-surface-400 text-sm">Subject</div>
            <div class="text-surface-900 dark:text-surface-100 font-medium break-all">
              {{ rawData.subject }}
            </div>
          </div>
          
          <!-- Size -->
          <div class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-1 md:gap-4 px-4 md:px-6 py-3">
            <div class="font-medium text-surface-500 dark:text-surface-400 text-sm">Size</div>
            <div class="text-surface-900 dark:text-surface-100">
              {{ formatSize(rawData.size) }}
            </div>
          </div>
          
          <!-- Authentication status row -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 px-4 md:px-6 py-4 bg-surface-100/50 dark:bg-surface-700/30">
            <!-- SPF -->
            <div class="flex items-center gap-3 flex-wrap">
              <span class="font-medium text-surface-500 dark:text-surface-400 text-sm w-14">SPF</span>
              <span 
                :class="['inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold', getStatusClass(rawData.spf?.status)]"
              >
                {{ formatStatus(rawData.spf?.status) }}
              </span>
              <span v-if="rawData.spf?.details" class="text-surface-600 dark:text-surface-400 text-sm">
                {{ rawData.spf.details }}
              </span>
            </div>
            
            <!-- DKIM -->
            <div class="flex items-center gap-3 flex-wrap">
              <span class="font-medium text-surface-500 dark:text-surface-400 text-sm w-14">DKIM</span>
              <span 
                :class="['inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold', getStatusClass(rawData.dkim?.status)]"
              >
                {{ formatStatus(rawData.dkim?.status) }}
              </span>
              <span v-if="rawData.dkim?.details" class="text-surface-600 dark:text-surface-400 text-sm">
                {{ rawData.dkim.details }}
              </span>
            </div>
            
            <!-- DMARC -->
            <div class="flex items-center gap-3 flex-wrap">
              <span class="font-medium text-surface-500 dark:text-surface-400 text-sm w-14">DMARC</span>
              <span 
                :class="['inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold', getStatusClass(rawData.dmarc?.status)]"
              >
                {{ formatStatus(rawData.dmarc?.status) }}
              </span>
              <span v-if="rawData.dmarc?.details" class="text-surface-600 dark:text-surface-400 text-sm">
                {{ rawData.dmarc.details }}
              </span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Warning if some verifications are missing -->
      <div 
        v-if="missingVerifications.length > 0"
        class="p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl"
      >
        <div class="flex items-start gap-3">
          <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-xl flex-shrink-0">info</span>
          <div class="text-sm">
            <p class="font-medium text-amber-800 dark:text-amber-300">{{ missingVerifications.join(', ') }} not verified by server</p>
            <p class="text-amber-700 dark:text-amber-400 mt-1">
              <template v-if="missingVerifications.includes('SPF')">Install <strong>postfix-policyd-spf-python</strong> for SPF. </template>
              <template v-if="missingVerifications.includes('DMARC')">Install <strong>OpenDMARC</strong> for DMARC. </template>
              <template v-if="missingVerifications.includes('DKIM')">Install <strong>OpenDKIM</strong> for DKIM. </template>
            </p>
          </div>
        </div>
      </div>
      
      <!-- Auth headers present (debug info) -->
      <div v-if="rawData.auth_headers && Object.keys(rawData.auth_headers).length > 0" class="space-y-2">
        <p class="text-xs font-medium text-surface-500 dark:text-surface-400 uppercase tracking-wide">Authentication Headers Found</p>
        <div class="flex flex-wrap gap-2">
          <span 
            v-for="(values, header) in rawData.auth_headers" 
            :key="header"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-surface-100 dark:bg-surface-700 rounded-full text-xs font-medium text-surface-700 dark:text-surface-300"
          >
            <span class="material-symbols-rounded text-sm text-green-500">check_circle</span>
            {{ header }}
          </span>
        </div>
      </div>
      
      <!-- Action buttons -->
      <div class="flex flex-wrap items-center gap-3">
        <button 
          @click="downloadOriginal"
          class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-full transition-colors border border-primary-200 dark:border-primary-500/30"
        >
          <span class="material-symbols-rounded text-lg">download</span>
          Download original
        </button>
        
        <button 
          @click="copyToClipboard"
          class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-200 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-full transition-colors"
        >
          <span class="material-symbols-rounded text-lg">{{ copied ? 'check' : 'content_copy' }}</span>
          {{ copied ? 'Copied!' : 'Copy to clipboard' }}
        </button>
      </div>
      
      <!-- Raw source toggle -->
      <div class="border-t border-surface-200 dark:border-surface-700 pt-4">
        <button 
          @click="showRawSource = !showRawSource"
          class="flex items-center gap-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200 transition-colors"
        >
          <span class="material-symbols-rounded text-lg transition-transform" :class="{ 'rotate-90': showRawSource }">
            chevron_right
          </span>
          {{ showRawSource ? 'Hide' : 'Show' }} raw source
        </button>
        
        <!-- Raw source content -->
        <Transition
          enter-active-class="transition-all duration-200 ease-out"
          leave-active-class="transition-all duration-150 ease-in"
          enter-from-class="opacity-0 max-h-0"
          enter-to-class="opacity-100 max-h-[600px]"
          leave-from-class="opacity-100 max-h-[600px]"
          leave-to-class="opacity-0 max-h-0"
        >
          <div v-if="showRawSource" class="mt-4 overflow-hidden">
            <pre class="p-4 md:p-6 bg-surface-900 dark:bg-black text-surface-100 text-xs font-mono rounded-xl overflow-x-auto max-h-[500px] overflow-y-auto whitespace-pre-wrap break-all leading-relaxed">{{ rawData.raw_source }}</pre>
          </div>
        </Transition>
      </div>
    </div>
    
    <!-- Error state -->
    <div v-else class="text-center py-16 text-surface-500">
      <span class="material-symbols-rounded text-5xl mb-3">error_outline</span>
      <p>Failed to load original message</p>
    </div>
    
    <template #footer>
      <div class="flex justify-end">
        <button @click="emit('close')" class="btn-secondary">
          Close
        </button>
      </div>
    </template>
  </Modal>
</template>

