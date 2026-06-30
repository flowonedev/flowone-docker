<script setup>
/**
 * CrmPortalSection.vue - Manages portal access for a client from the CRM side
 * 
 * Features:
 * - Grant portal access to client contacts (auto-generates & sends magic link)
 * - View existing portal access entries (active/inactive)
 * - Send magic link emails (re-send)
 * - Copy magic link to clipboard (for sharing via WhatsApp, Slack, etc.)
 * - Revoke portal access
 */
import { ref, watch, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: Number, required: true },
  contacts: { type: Array, default: () => [] }
})

const toast = useToastStore()

// Portal access state
const accessList = ref([])
const loading = ref(false)
const granting = ref(false)
const showGrantModal = ref(false)
const showLinkModal = ref(false)
const sendingLink = ref(null) // ID of access being sent
const generatingLink = ref(null) // ID of access getting link generated
const revokingId = ref(null)

// Generated link display
const generatedLink = ref('')
const generatedLinkEmail = ref('')
const linkCopied = ref(false)

// Grant form
const grantForm = ref({
  email: '',
  name: '',
  contact_id: null
})

const activeAccess = computed(() => accessList.value.filter(a => a.is_active))
const revokedAccess = computed(() => accessList.value.filter(a => !a.is_active))

watch(() => props.clientId, (id) => {
  if (id) fetchAccess()
}, { immediate: true })

async function fetchAccess() {
  loading.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/portal/access`)
    if (res.data?.success) {
      accessList.value = res.data.data?.access || res.data.data || []
    }
  } catch (e) {
    accessList.value = []
  } finally {
    loading.value = false
  }
}

function prefillFromContact(contact) {
  grantForm.value.email = contact.email || ''
  grantForm.value.name = contact.name || ''
  grantForm.value.contact_id = contact.id || null
}

function openGrantModal() {
  grantForm.value = { email: '', name: '', contact_id: null }
  showGrantModal.value = true
}

async function grantAccess() {
  if (!grantForm.value.email) {
    toast.error('Email is required')
    return
  }
  granting.value = true
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/grant`, {
      email: grantForm.value.email,
      name: grantForm.value.name,
      contact_id: grantForm.value.contact_id
    })
    if (res.data?.success) {
      const data = res.data.data
      showGrantModal.value = false

      // If email was sent successfully
      if (data.email_sent) {
        toast.success(res.data.message || 'Portal access granted and magic link sent')
      } else if (data.portal_link) {
        // Email failed but we have a link — show it so admin can copy
        toast.info('Access granted. Email could not be sent — copy the link below to share manually.')
      } else {
        toast.success('Portal access granted')
      }

      // Show the generated link if available
      if (data.portal_link) {
        generatedLink.value = ensureFullUrl(data.portal_link)
        generatedLinkEmail.value = grantForm.value.email
        linkCopied.value = false
        showLinkModal.value = true
      }

      await fetchAccess()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to grant access')
  } finally {
    granting.value = false
  }
}

async function sendMagicLink(access) {
  sendingLink.value = access.id
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/send-link`, {
      portal_access_id: access.id
    })
    if (res.data?.success) {
      toast.success(`Magic link sent to ${access.email}`)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to send magic link')
  } finally {
    sendingLink.value = null
  }
}

async function copyMagicLink(access) {
  generatingLink.value = access.id
  try {
    const res = await api.post(`/clients/${props.clientId}/portal/generate-link`, {
      portal_access_id: access.id
    })
    if (res.data?.success) {
      const link = res.data.data?.portal_link
      if (link) {
        generatedLink.value = ensureFullUrl(link)
        generatedLinkEmail.value = access.email
        linkCopied.value = false
        showLinkModal.value = true
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to generate link')
  } finally {
    generatingLink.value = null
  }
}

/** Ensure portal links are full URLs (prepend origin if the backend returns a relative path) */
function ensureFullUrl(link) {
  if (!link) return link
  if (link.startsWith('http://') || link.startsWith('https://')) return link
  return window.location.origin + (link.startsWith('/') ? '' : '/') + link
}

async function copyToClipboard() {
  try {
    await navigator.clipboard.writeText(generatedLink.value)
    linkCopied.value = true
    toast.success('Link copied to clipboard')
    setTimeout(() => { linkCopied.value = false }, 3000)
  } catch {
    // Fallback for older browsers
    const textarea = document.createElement('textarea')
    textarea.value = generatedLink.value
    document.body.appendChild(textarea)
    textarea.select()
    document.execCommand('copy')
    document.body.removeChild(textarea)
    linkCopied.value = true
    toast.success('Link copied to clipboard')
    setTimeout(() => { linkCopied.value = false }, 3000)
  }
}

async function revokeAccess(access) {
  revokingId.value = access.id
  try {
    const res = await api.delete(`/clients/${props.clientId}/portal/revoke/${access.id}`)
    if (res.data?.success) {
      toast.success('Portal access revoked')
      await fetchAccess()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to revoke access')
  } finally {
    revokingId.value = null
  }
}

function formatDate(d) {
  if (!d) return 'Never'
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <!-- Section Header -->
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">shield_person</span>
        Client Portal Access
      </h3>
      <button
        @click="openGrantModal"
        class="flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-medium 
               bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 
               hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors"
      >
        <span class="material-symbols-rounded text-sm">person_add</span>
        Grant Access
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-4">
      <span class="material-symbols-rounded animate-spin text-surface-400">sync</span>
    </div>

    <!-- Active Access List -->
    <div v-else-if="activeAccess.length > 0" class="space-y-2">
      <div
        v-for="access in activeAccess"
        :key="access.id"
        class="flex items-center gap-3 p-3 rounded-lg bg-surface-50 dark:bg-surface-800/50 border border-surface-100 dark:border-surface-700/50"
      >
        <!-- Avatar -->
        <div class="w-9 h-9 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-lg text-primary-600 dark:text-primary-400">person</span>
        </div>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate">
            {{ access.name || access.email }}
          </p>
          <p class="text-xs text-surface-500 truncate">
            {{ access.email }}
            <span v-if="access.last_login_at"> · Last login {{ formatDate(access.last_login_at) }}</span>
            <span v-else> · Never logged in</span>
          </p>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-1 flex-shrink-0">
          <!-- Copy Link (generate without emailing) -->
          <button
            @click="copyMagicLink(access)"
            :disabled="generatingLink === access.id"
            class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-500 dark:text-surface-400 transition-colors disabled:opacity-50"
            :title="generatingLink === access.id ? 'Generating...' : 'Copy portal link'"
          >
            <span class="material-symbols-rounded text-lg" :class="{ 'animate-spin': generatingLink === access.id }">
              {{ generatingLink === access.id ? 'sync' : 'link' }}
            </span>
          </button>
          <!-- Send via email -->
          <button
            @click="sendMagicLink(access)"
            :disabled="sendingLink === access.id"
            class="p-1.5 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-500/20 text-primary-600 dark:text-primary-400 transition-colors disabled:opacity-50"
            :title="sendingLink === access.id ? 'Sending...' : 'Send magic link via email'"
          >
            <span class="material-symbols-rounded text-lg" :class="{ 'animate-spin': sendingLink === access.id }">
              {{ sendingLink === access.id ? 'sync' : 'send' }}
            </span>
          </button>
          <!-- Revoke -->
          <button
            @click="revokeAccess(access)"
            :disabled="revokingId === access.id"
            class="p-1.5 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/20 text-red-500 dark:text-red-400 transition-colors disabled:opacity-50"
            title="Revoke access"
          >
            <span class="material-symbols-rounded text-lg">
              {{ revokingId === access.id ? 'sync' : 'person_remove' }}
            </span>
          </button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="text-center py-6">
      <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">no_accounts</span>
      <p class="text-sm text-surface-500 mt-2">No portal access granted yet</p>
      <p class="text-xs text-surface-400 mt-1">Grant access to allow clients to view updates, sign documents, and join calls</p>
    </div>

    <!-- Revoked list (collapsed) -->
    <details v-if="revokedAccess.length > 0" class="mt-3">
      <summary class="text-xs text-surface-400 cursor-pointer hover:text-surface-600 dark:hover:text-surface-300">
        {{ revokedAccess.length }} revoked access{{ revokedAccess.length !== 1 ? 'es' : '' }}
      </summary>
      <div class="mt-2 space-y-1">
        <div
          v-for="access in revokedAccess"
          :key="access.id"
          class="flex items-center gap-3 p-2 rounded-lg opacity-50"
        >
          <div class="w-7 h-7 rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-sm text-surface-400">person_off</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-xs text-surface-600 dark:text-surface-400 truncate">{{ access.name || access.email }}</p>
          </div>
        </div>
      </div>
    </details>

    <!-- Grant Access Modal -->
    <Teleport to="body">
      <div v-if="showGrantModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showGrantModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">person_add</span>
            Grant Portal Access
          </h3>

          <!-- Quick select from contacts -->
          <div v-if="contacts.length > 0" class="mb-4">
            <p class="text-xs font-medium text-surface-500 mb-2">Quick select from contacts:</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="contact in contacts.slice(0, 6)"
                :key="contact.id"
                @click="prefillFromContact(contact)"
                :class="['px-3 py-1.5 rounded-full text-xs font-medium border transition-colors',
                  grantForm.contact_id === contact.id
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300'
                    : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:border-surface-300']"
              >
                {{ contact.name || contact.email }}
              </button>
            </div>
          </div>

          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Email *</label>
              <input
                type="email"
                v-model="grantForm.email"
                placeholder="client@company.com"
                class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 
                       bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                       focus:ring-2 focus:ring-primary-500 outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Name</label>
              <input
                type="text"
                v-model="grantForm.name"
                placeholder="John Doe"
                class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 
                       bg-white dark:bg-surface-700 text-surface-900 dark:text-white text-sm
                       focus:ring-2 focus:ring-primary-500 outline-none"
              />
            </div>
          </div>

          <p class="text-xs text-surface-400 mt-3">
            A sign-in link will be generated and emailed to the client. If sending fails, you can copy the link manually.
          </p>

          <div class="flex justify-end gap-3 mt-5">
            <button @click="showGrantModal = false" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700">
              Cancel
            </button>
            <button
              @click="grantAccess"
              :disabled="!grantForm.email || granting"
              class="px-6 py-2 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50 transition-colors flex items-center gap-2"
            >
              <span v-if="granting" class="material-symbols-rounded text-sm animate-spin">sync</span>
              {{ granting ? 'Granting...' : 'Grant Access & Send Link' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Generated Link Modal (copy/share) -->
    <Teleport to="body">
      <div v-if="showLinkModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showLinkModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg p-6">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-2 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">link</span>
            Portal Access Link
          </h3>
          <p class="text-sm text-surface-500 mb-4">
            Share this link with <strong class="text-surface-700 dark:text-surface-200">{{ generatedLinkEmail }}</strong> so they can access the client portal. The link is single-use and expires in 24 hours.
          </p>

          <!-- Link display + copy -->
          <div class="flex items-center gap-2 p-3 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700">
            <input
              :value="generatedLink"
              readonly
              class="flex-1 bg-transparent text-sm text-surface-700 dark:text-surface-300 outline-none font-mono truncate"
              @click="$event.target.select()"
            />
            <button
              @click="copyToClipboard"
              :class="['flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium transition-colors flex-shrink-0',
                linkCopied
                  ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400'
                  : 'bg-primary-600 hover:bg-primary-700 text-white']"
            >
              <span class="material-symbols-rounded text-sm">{{ linkCopied ? 'check' : 'content_copy' }}</span>
              {{ linkCopied ? 'Copied' : 'Copy' }}
            </button>
          </div>

          <div class="flex justify-end mt-5">
            <button
              @click="showLinkModal = false"
              class="px-5 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
