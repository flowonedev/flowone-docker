<script setup>
import { ref, onMounted, computed } from 'vue'
import { useSpamStore } from '@/stores/spam'
import ConfirmModal from './shared/ConfirmModal.vue'

const spamStore = useSpamStore()

// Local state
const newBlockedEmail = ref('')
const newSafeEmail = ref('')
const blockDomainToo = ref(false)
const trustDomainToo = ref(false)
const activeTab = ref('spam-emails') // 'spam-emails' | 'blocked' | 'safe' | 'settings'
const searchQuery = ref('')
const spamEmailPage = ref(1)

// Confirm modals
const showUnblockConfirm = ref(false)
const showRemoveSafeConfirm = ref(false)
const showBlockSpamSenderConfirm = ref(false)
const showBlockSpamDomainConfirm = ref(false)
const pendingUnblockId = ref(null)
const pendingRemoveSafeId = ref(null)
const pendingBlockSpamEmail = ref(null)
const pendingBlockDomainEmail = ref(null)
const showUpgradeDomainConfirm = ref(false)
const pendingUpgradeSender = ref(null)

// Filter spam emails by search
const filteredSpamEmails = computed(() => {
  if (!searchQuery.value) return spamStore.spamEmails
  const q = searchQuery.value.toLowerCase()
  return spamStore.spamEmails.filter(e =>
    e.from?.toLowerCase().includes(q) ||
    e.subject?.toLowerCase().includes(q)
  )
})

// Filter lists by search
const filteredBlockedSenders = computed(() => {
  if (!searchQuery.value) return spamStore.blockedSenders
  const q = searchQuery.value.toLowerCase()
  return spamStore.blockedSenders.filter(s => 
    s.blocked_email?.toLowerCase().includes(q) ||
    s.blocked_domain?.toLowerCase().includes(q) ||
    s.reason?.toLowerCase().includes(q)
  )
})

const filteredSafeSenders = computed(() => {
  if (!searchQuery.value) return spamStore.safeSenders
  const q = searchQuery.value.toLowerCase()
  return spamStore.safeSenders.filter(s => 
    s.safe_email?.toLowerCase().includes(q) ||
    s.safe_domain?.toLowerCase().includes(q)
  )
})

// Actions
async function addBlockedSender() {
  const email = newBlockedEmail.value.trim()
  if (!email) return
  
  const success = await spamStore.blockSender(email, {
    blockDomain: blockDomainToo.value,
  })
  
  if (success) {
    newBlockedEmail.value = ''
    blockDomainToo.value = false
  }
}

async function addSafeSender() {
  const email = newSafeEmail.value.trim()
  if (!email) return
  
  const success = await spamStore.addSafeSender(email, trustDomainToo.value)
  
  if (success) {
    newSafeEmail.value = ''
    trustDomainToo.value = false
  }
}

function confirmUnblock(id) {
  pendingUnblockId.value = id
  showUnblockConfirm.value = true
}

async function executeUnblock() {
  if (pendingUnblockId.value) {
    await spamStore.unblockSender(pendingUnblockId.value)
  }
  showUnblockConfirm.value = false
  pendingUnblockId.value = null
}

function confirmUpgradeDomain(sender) {
  pendingUpgradeSender.value = sender
  showUpgradeDomainConfirm.value = true
}

async function executeUpgradeDomain() {
  if (pendingUpgradeSender.value) {
    const email = pendingUpgradeSender.value.blocked_email
    const domain = extractDomain(email)
    await spamStore.blockSender(email, {
      blockDomain: true,
      reason: `Blocked entire domain @${domain}`,
    })
  }
  showUpgradeDomainConfirm.value = false
  pendingUpgradeSender.value = null
}

function confirmRemoveSafe(id) {
  pendingRemoveSafeId.value = id
  showRemoveSafeConfirm.value = true
}

async function executeRemoveSafe() {
  if (pendingRemoveSafeId.value) {
    await spamStore.removeSafeSender(pendingRemoveSafeId.value)
  }
  showRemoveSafeConfirm.value = false
  pendingRemoveSafeId.value = null
}

// Block spam sender from email list
function confirmBlockSpamSender(email) {
  pendingBlockSpamEmail.value = email
  showBlockSpamSenderConfirm.value = true
}

async function executeBlockSpamSender() {
  if (pendingBlockSpamEmail.value) {
    const senderEmail = extractSenderEmail(pendingBlockSpamEmail.value.from)
    if (senderEmail) {
      await spamStore.blockSender(senderEmail, { reason: 'Blocked from spam folder' })
    }
  }
  showBlockSpamSenderConfirm.value = false
  pendingBlockSpamEmail.value = null
}

// Block entire domain from spam email list
function confirmBlockSpamDomain(email) {
  pendingBlockDomainEmail.value = email
  showBlockSpamDomainConfirm.value = true
}

async function executeBlockSpamDomain() {
  if (pendingBlockDomainEmail.value) {
    const senderEmail = extractSenderEmail(pendingBlockDomainEmail.value.from)
    if (senderEmail) {
      const domain = extractDomain(senderEmail)
      await spamStore.blockSender(senderEmail, {
        blockDomain: true,
        reason: `Blocked entire domain @${domain}`,
      })
    }
  }
  showBlockSpamDomainConfirm.value = false
  pendingBlockDomainEmail.value = null
}

// Spam emails actions
async function markNotSpam(email) {
  if (!spamStore.spamFolder || !email.uid) return
  const success = await spamStore.notSpam(spamStore.spamFolder, email.uid)
  if (success) {
    await spamStore.fetchSpamEmails(spamEmailPage.value)
    await spamStore.fetchStats()
  }
}

async function loadSpamPage(page) {
  spamEmailPage.value = page
  await spamStore.fetchSpamEmails(page)
}

// Extract sender display name from email from field
function extractSender(from) {
  if (!from) return 'Unknown'
  if (typeof from === 'string') return from
  if (Array.isArray(from) && from.length > 0) {
    const f = from[0]
    return f.name || f.email || f.address || 'Unknown'
  }
  return from.name || from.email || from.address || String(from)
}

function extractSenderEmail(from) {
  if (!from) return ''
  if (typeof from === 'string') {
    const match = from.match(/<([^>]+)>/)
    return match ? match[1] : from
  }
  if (Array.isArray(from) && from.length > 0) {
    return from[0].email || from[0].address || ''
  }
  return from.email || from.address || ''
}

function extractDomain(email) {
  if (!email) return ''
  const parts = email.split('@')
  return parts.length === 2 ? parts[1] : ''
}

// Settings
const localSettings = ref({
  auto_delete_days: 30,
  auto_training_enabled: true,
})

async function saveSettings() {
  await spamStore.updateSettings(localSettings.value)
}

// Format date
function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString([], { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric' 
  })
}

onMounted(async () => {
  await Promise.all([
    spamStore.fetchBlockedSenders(),
    spamStore.fetchSafeSenders(),
    spamStore.fetchSpamEmails(),
    spamStore.fetchSettings(),
    spamStore.fetchStats(),
  ])
  localSettings.value = { ...spamStore.settings }
})
</script>

<template>
  <div class="space-y-6">
    <!-- Stats Overview -->
    <div v-if="spamStore.stats" class="grid grid-cols-2 md:grid-cols-5 gap-4">
      <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4 text-center">
        <div class="text-2xl font-bold text-orange-500">{{ spamStore.stats.spam_folder_count ?? 0 }}</div>
        <div class="text-sm text-surface-500">Emails in Spam</div>
      </div>
      <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4 text-center">
        <div class="text-2xl font-bold text-red-500">{{ spamStore.stats.reported_spam }}</div>
        <div class="text-sm text-surface-500">Reported as Spam</div>
      </div>
      <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4 text-center">
        <div class="text-2xl font-bold text-green-500">{{ spamStore.stats.not_spam }}</div>
        <div class="text-sm text-surface-500">Marked Not Spam</div>
      </div>
      <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4 text-center">
        <div class="text-2xl font-bold text-surface-600 dark:text-surface-300">{{ spamStore.blockedCount }}</div>
        <div class="text-sm text-surface-500">Blocked Senders</div>
      </div>
      <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4 text-center">
        <div class="text-2xl font-bold text-surface-600 dark:text-surface-300">{{ spamStore.safeCount }}</div>
        <div class="text-sm text-surface-500">Trusted Senders</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 border-b border-surface-200 dark:border-surface-700 overflow-x-auto">
      <button
        @click="activeTab = 'spam-emails'"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
          activeTab === 'spam-emails'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-base align-middle mr-1">report</span>
        Spam Emails ({{ spamStore.spamEmailsCount }})
      </button>
      <button
        @click="activeTab = 'blocked'"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
          activeTab === 'blocked'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-base align-middle mr-1">block</span>
        Blocked Senders ({{ spamStore.blockedCount }})
      </button>
      <button
        @click="activeTab = 'safe'"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
          activeTab === 'safe'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-base align-middle mr-1">verified_user</span>
        Trusted Senders ({{ spamStore.safeCount }})
      </button>
      <button
        @click="activeTab = 'settings'"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
          activeTab === 'settings'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-base align-middle mr-1">settings</span>
        Settings
      </button>
    </div>

    <!-- Search (for spam-emails/blocked/safe tabs) -->
    <div v-if="activeTab !== 'settings'" class="relative">
      <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
      <input
        v-model="searchQuery"
        type="text"
        placeholder="Search..."
        class="w-full pl-10 pr-4 py-2 border border-surface-200 dark:border-surface-700 rounded-xl bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
      />
    </div>

    <!-- Spam Emails Tab -->
    <div v-if="activeTab === 'spam-emails'" class="space-y-4">
      <!-- Loading state -->
      <div v-if="spamStore.loading.spamEmails" class="text-center py-8">
        <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">progress_activity</span>
      </div>

      <!-- Empty state -->
      <div v-else-if="filteredSpamEmails.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-4xl mb-2 block">mark_email_read</span>
        <p>No spam emails found</p>
      </div>

      <!-- Spam email list -->
      <div v-else class="space-y-2">
        <div
          v-for="email in filteredSpamEmails"
          :key="email.uid"
          class="flex items-center justify-between p-3 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700"
        >
          <div class="flex-1 min-w-0">
            <div class="font-medium text-surface-900 dark:text-surface-100 truncate">
              {{ email.subject || '(No subject)' }}
            </div>
            <div class="text-sm text-surface-500 flex items-center gap-2 mt-0.5">
              <span class="truncate">{{ extractSender(email.from) }}</span>
              <span v-if="extractSenderEmail(email.from)" class="text-xs text-surface-400 truncate">
                &lt;{{ extractSenderEmail(email.from) }}&gt;
              </span>
              <span class="flex-shrink-0">{{ formatDate(email.date) }}</span>
            </div>
          </div>
          <div class="flex items-center gap-1 flex-shrink-0 ml-3">
            <button
              @click="markNotSpam(email)"
              :disabled="spamStore.loading.action"
              class="p-2 rounded-full hover:bg-green-50 dark:hover:bg-green-900/20 text-surface-500 hover:text-green-600 transition-colors"
              title="Not Spam - Move to Inbox"
            >
              <span class="material-symbols-rounded">move_to_inbox</span>
            </button>
            <button
              @click="confirmBlockSpamSender(email)"
              :disabled="spamStore.loading.action"
              class="p-2 rounded-full hover:bg-red-50 dark:hover:bg-red-900/20 text-surface-500 hover:text-red-600 transition-colors"
              title="Block Sender"
            >
              <span class="material-symbols-rounded">block</span>
            </button>
            <button
              @click="confirmBlockSpamDomain(email)"
              :disabled="spamStore.loading.action"
              class="p-2 rounded-full hover:bg-orange-50 dark:hover:bg-orange-900/20 text-surface-500 hover:text-orange-600 transition-colors"
              title="Block Entire Domain"
            >
              <span class="material-symbols-rounded">domain_disabled</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="spamStore.spamEmailsPages > 1" class="flex items-center justify-center gap-2 pt-2">
        <button
          :disabled="spamStore.spamEmailsPage <= 1"
          @click="loadSpamPage(spamStore.spamEmailsPage - 1)"
          class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300"
        >
          <span class="material-symbols-rounded text-base align-middle">chevron_left</span>
          Prev
        </button>
        <span class="text-sm text-surface-500">
          Page {{ spamStore.spamEmailsPage }} of {{ spamStore.spamEmailsPages }}
        </span>
        <button
          :disabled="spamStore.spamEmailsPage >= spamStore.spamEmailsPages"
          @click="loadSpamPage(spamStore.spamEmailsPage + 1)"
          class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300"
        >
          Next
          <span class="material-symbols-rounded text-base align-middle">chevron_right</span>
        </button>
      </div>
    </div>

    <!-- Blocked Senders Tab -->
    <div v-if="activeTab === 'blocked'" class="space-y-4">
      <!-- Add new blocked sender -->
      <div class="flex flex-col sm:flex-row gap-3 p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
        <div class="flex-1">
          <input
            v-model="newBlockedEmail"
            type="email"
            placeholder="Enter email address to block..."
            class="w-full px-4 py-2 border border-surface-200 dark:border-surface-700 rounded-xl bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
            @keyup.enter="addBlockedSender"
          />
        </div>
        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer">
          <div
            @click="blockDomainToo = !blockDomainToo"
            class="relative w-10 h-5 rounded-full transition-colors flex-shrink-0"
            :class="blockDomainToo ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
          >
            <div
              class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
              :class="blockDomainToo ? 'left-5' : 'left-0.5'"
            ></div>
          </div>
          Block entire domain
        </label>
        <button
          @click="addBlockedSender"
          :disabled="!newBlockedEmail || spamStore.loading.action"
          class="px-4 py-2 bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-full font-medium flex items-center gap-2 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">block</span>
          Block
        </button>
      </div>

      <!-- Blocked list -->
      <div v-if="spamStore.loading.blockedSenders" class="text-center py-8">
        <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">progress_activity</span>
      </div>
      <div v-else-if="filteredBlockedSenders.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-4xl mb-2 block">check_circle</span>
        <p>No blocked senders</p>
      </div>
      <div v-else class="space-y-2">
        <div 
          v-for="sender in filteredBlockedSenders" 
          :key="sender.id"
          class="flex items-center justify-between p-3 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700"
        >
          <div class="flex-1 min-w-0">
            <div class="font-medium text-surface-900 dark:text-surface-100 truncate">
              {{ sender.blocked_email }}
            </div>
            <div class="text-sm text-surface-500 flex items-center gap-2">
              <span v-if="sender.blocked_domain" class="px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded text-xs">
                Entire domain blocked
              </span>
              <span v-if="sender.reason" class="truncate">{{ sender.reason }}</span>
              <span>{{ formatDate(sender.created_at) }}</span>
            </div>
          </div>
          <div class="flex items-center gap-1 flex-shrink-0 ml-3">
            <button
              v-if="!sender.blocked_domain"
              @click="confirmUpgradeDomain(sender)"
              :disabled="spamStore.loading.action"
              class="p-2 rounded-full hover:bg-orange-50 dark:hover:bg-orange-900/20 text-surface-500 hover:text-orange-600 transition-colors"
              title="Block Entire Domain"
            >
              <span class="material-symbols-rounded">domain_disabled</span>
            </button>
            <button
              @click="confirmUnblock(sender.id)"
              class="p-2 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-green-600 transition-colors"
              title="Unblock sender"
            >
              <span class="material-symbols-rounded">remove_circle</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Safe Senders Tab -->
    <div v-if="activeTab === 'safe'" class="space-y-4">
      <!-- Add new safe sender -->
      <div class="flex flex-col sm:flex-row gap-3 p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
        <div class="flex-1">
          <input
            v-model="newSafeEmail"
            type="email"
            placeholder="Enter email address to trust..."
            class="w-full px-4 py-2 border border-surface-200 dark:border-surface-700 rounded-xl bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
            @keyup.enter="addSafeSender"
          />
        </div>
        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer">
          <div
            @click="trustDomainToo = !trustDomainToo"
            class="relative w-10 h-5 rounded-full transition-colors flex-shrink-0"
            :class="trustDomainToo ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
          >
            <div
              class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
              :class="trustDomainToo ? 'left-5' : 'left-0.5'"
            ></div>
          </div>
          Trust entire domain
        </label>
        <button
          @click="addSafeSender"
          :disabled="!newSafeEmail || spamStore.loading.action"
          class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-full font-medium flex items-center gap-2 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">verified_user</span>
          Add
        </button>
      </div>

      <!-- Safe list -->
      <div v-if="spamStore.loading.safeSenders" class="text-center py-8">
        <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">progress_activity</span>
      </div>
      <div v-else-if="filteredSafeSenders.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-4xl mb-2 block">mail</span>
        <p>No trusted senders added</p>
      </div>
      <div v-else class="space-y-2">
        <div 
          v-for="sender in filteredSafeSenders" 
          :key="sender.id"
          class="flex items-center justify-between p-3 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700"
        >
          <div class="flex-1 min-w-0">
            <div class="font-medium text-surface-900 dark:text-surface-100 truncate">
              {{ sender.safe_email }}
            </div>
            <div class="text-sm text-surface-500 flex items-center gap-2">
              <span v-if="sender.safe_domain" class="px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded text-xs">
                Entire domain trusted
              </span>
              <span>{{ formatDate(sender.created_at) }}</span>
            </div>
          </div>
          <button
            @click="confirmRemoveSafe(sender.id)"
            class="p-2 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-red-600 transition-colors"
            title="Remove from trusted senders"
          >
            <span class="material-symbols-rounded">remove_circle</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Settings Tab -->
    <div v-if="activeTab === 'settings'" class="space-y-6">
      <!-- Auto-delete old spam -->
      <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl space-y-4">
        <h3 class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded">auto_delete</span>
          Auto-delete Old Spam
        </h3>
        <p class="text-sm text-surface-500">
          Automatically delete emails from the Spam folder older than the specified number of days.
        </p>
        <div class="flex items-center gap-4">
          <label class="text-sm text-surface-600 dark:text-surface-400">Delete spam older than:</label>
          <select
            v-model="localSettings.auto_delete_days"
            class="px-3 py-2 border border-surface-200 dark:border-surface-700 rounded-xl bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
          >
            <option :value="7">7 days</option>
            <option :value="14">14 days</option>
            <option :value="30">30 days</option>
            <option :value="60">60 days</option>
            <option :value="90">90 days</option>
            <option :value="0">Never (disable)</option>
          </select>
        </div>
      </div>

      <!-- Auto training -->
      <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl space-y-4">
        <h3 class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded">model_training</span>
          Spam Filter Training
        </h3>
        <p class="text-sm text-surface-500">
          Help improve spam detection by automatically training the filter when you report spam or mark emails as not spam.
        </p>
        <label class="flex items-center gap-3 cursor-pointer">
          <div 
            class="relative w-12 h-6 rounded-full transition-colors"
            :class="localSettings.auto_training_enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
          >
            <div 
              class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-transform"
              :class="localSettings.auto_training_enabled ? 'left-7' : 'left-1'"
            ></div>
          </div>
          <span class="text-sm text-surface-700 dark:text-surface-300">
            Enable automatic spam filter training
          </span>
        </label>
      </div>

      <!-- Save button -->
      <div class="flex justify-end">
        <button
          @click="saveSettings"
          :disabled="spamStore.loading.settings"
          class="px-6 py-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white rounded-full font-medium flex items-center gap-2 transition-colors"
        >
          <span v-if="spamStore.loading.settings" class="material-symbols-rounded animate-spin">progress_activity</span>
          <span class="material-symbols-rounded" v-else>save</span>
          Save Settings
        </button>
      </div>
    </div>

    <!-- Unblock confirmation -->
    <ConfirmModal
      :show="showUnblockConfirm"
      title="Unblock Sender"
      message="Are you sure you want to unblock this sender? They will be able to send emails to your inbox again."
      confirm-text="Unblock"
      type="info"
      @confirm="executeUnblock"
      @cancel="showUnblockConfirm = false"
    />

    <!-- Remove safe sender confirmation -->
    <ConfirmModal
      :show="showRemoveSafeConfirm"
      title="Remove Trusted Sender"
      message="Are you sure you want to remove this sender from your trusted list?"
      confirm-text="Remove"
      type="warning"
      @confirm="executeRemoveSafe"
      @cancel="showRemoveSafeConfirm = false"
    />

    <!-- Block spam sender confirmation -->
    <ConfirmModal
      :show="showBlockSpamSenderConfirm"
      title="Block Sender"
      :message="`Are you sure you want to block ${pendingBlockSpamEmail ? extractSenderEmail(pendingBlockSpamEmail.from) : 'this sender'}? Future emails from them will be automatically moved to spam.`"
      confirm-text="Block"
      type="danger"
      @confirm="executeBlockSpamSender"
      @cancel="showBlockSpamSenderConfirm = false"
    />

    <!-- Block entire domain confirmation (from spam emails tab) -->
    <ConfirmModal
      :show="showBlockSpamDomainConfirm"
      title="Block Entire Domain"
      :message="`Are you sure you want to block all emails from @${pendingBlockDomainEmail ? extractDomain(extractSenderEmail(pendingBlockDomainEmail.from)) : ''}? All future emails from any address at this domain will be automatically blocked.`"
      confirm-text="Block Domain"
      type="danger"
      @confirm="executeBlockSpamDomain"
      @cancel="showBlockSpamDomainConfirm = false"
    />

    <!-- Upgrade to domain block confirmation (from blocked senders tab) -->
    <ConfirmModal
      :show="showUpgradeDomainConfirm"
      title="Block Entire Domain"
      :message="`Upgrade this block to cover the entire @${pendingUpgradeSender ? extractDomain(pendingUpgradeSender.blocked_email) : ''} domain? All future emails from any address at this domain will be automatically blocked.`"
      confirm-text="Block Domain"
      type="danger"
      @confirm="executeUpgradeDomain"
      @cancel="showUpgradeDomainConfirm = false"
    />
  </div>
</template>

