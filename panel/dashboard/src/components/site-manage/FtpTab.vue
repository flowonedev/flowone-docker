<script setup>
// FtpTab
// ---------------------------------------------------------------
// FTP / SFTP / SSH-keys management for the V2 site management
// view. Replaces the "ftp" section of SiteDetailView.vue.
//
// All endpoints still live under the legacy domain-scoped routes
// (`/api/sites/{domain}/ftp-status`, `/api/sites/{domain}/ssh-keys/*`)
// which are themselves OLS / filesystem-coupled. They stay
// reusable as-is until the saga grows a dedicated CHANGE_SSH_KEY
// step in Phase 5.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import SftpUsersSection from '@/components/site-manage/SftpUsersSection.vue'

const props = defineProps({ domain: { type: String, required: true } })
const toast = useToastStore()

const status = ref(null)
const loading = ref(false)
const fixingPerms = ref(false)
const keyForm = ref({ key: '', label: '' })
const editing = ref({ index: null, key: '', label: '' })
// Confirm modal state for SSH key removal (replaces window.confirm()).
const removeConfirm = ref({ show: false, index: null, busy: false })

const sshKeys = computed(() => {
  const k = status.value?.ssh_keys
  return Array.isArray(k) ? k : []
})

// IMPORTANT: the agent's vhost.ftpStatus action returns:
//   {
//     ftp:       <bool>,    // is an FTP daemon active
//     sftp:      <bool>,    // is sshd reachable (sftp goes over ssh)
//     sshPort:   <int>,     // ACTUAL Port from /etc/ssh/sshd_config
//     siteUser:  <string>,  // unix owner of /home/{domain}/public_html
//     ssh_keys:  <array>,
//     ssh_permissions: <obj>,
//   }
//
// `ftp` and `sftp` are PRIMITIVES, not objects. Reading
// `status.sftp.port` silently returns undefined and the previous code
// then fell back to 22 (wrong if SSH was moved) and to an empty user
// string (wrong always). We now read the real top-level fields.
const sftpDetails = computed(() => ({
  host: props.domain,
  port: Number(status.value?.sshPort) || 22,
  user: status.value?.siteUser || '',
  path: `/home/${props.domain}/public_html`,
}))

const sftpActive = computed(() => !!status.value?.sftp)
const ftpActive = computed(() => !!status.value?.ftp)

const fetchStatus = async () => {
  loading.value = true
  try {
    const r = await api.get(`/sites/${encodeURIComponent(props.domain)}/ftp-status`)
    if (r.data?.success) status.value = r.data.data
  } catch {
    status.value = null
  } finally {
    loading.value = false
  }
}

const addKey = async () => {
  if (!keyForm.value.key.trim()) {
    toast.error('Paste an SSH public key')
    return
  }
  try {
    const r = await api.post(
      `/sites/${encodeURIComponent(props.domain)}/ssh-keys`,
      { key: keyForm.value.key.trim(), label: keyForm.value.label.trim() },
    )
    if (r.data?.success) {
      toast.success('Key added')
      keyForm.value = { key: '', label: '' }
      await fetchStatus()
    } else {
      toast.error(r.data?.error || 'Add failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Add failed')
  }
}

const startEdit = (i, k) => {
  editing.value = { index: i, key: k.key ?? k, label: k.label ?? '' }
}

const saveEdit = async () => {
  try {
    const r = await api.put(
      `/sites/${encodeURIComponent(props.domain)}/ssh-keys/${editing.value.index}`,
      { key: editing.value.key, label: editing.value.label },
    )
    if (r.data?.success) {
      toast.success('Key updated')
      editing.value = { index: null, key: '', label: '' }
      await fetchStatus()
    } else {
      toast.error(r.data?.error || 'Update failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Update failed')
  }
}

const askRemoveKey = (i) => {
  removeConfirm.value = { show: true, index: i, busy: false }
}

const confirmRemoveKey = async () => {
  const i = removeConfirm.value.index
  if (i === null) return
  removeConfirm.value.busy = true
  try {
    const r = await api.delete(
      `/sites/${encodeURIComponent(props.domain)}/ssh-keys/${i}`,
    )
    if (r.data?.success) {
      toast.success('Key removed')
      await fetchStatus()
    } else {
      toast.error(r.data?.error || 'Delete failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Delete failed')
  } finally {
    removeConfirm.value = { show: false, index: null, busy: false }
  }
}

const fixPerms = async () => {
  fixingPerms.value = true
  try {
    const r = await api.post(
      `/sites/${encodeURIComponent(props.domain)}/fix-ssh-permissions`,
      {},
    )
    if (r.data?.success) toast.success('SSH permissions fixed')
    else toast.error(r.data?.error || 'Fix failed')
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Fix failed')
  } finally {
    fixingPerms.value = false
  }
}

const copy = (text) => {
  navigator.clipboard?.writeText(text)
  toast.info('Copied')
}

onMounted(fetchStatus)
</script>

<template>
  <div class="space-y-5">
    <!-- ─── Status summary ─── -->
    <div class="card">
      <div class="card-header flex items-center justify-between gap-2 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-orange-500">folder_shared</span>
          <h3 class="font-semibold">FTP / SFTP</h3>
        </div>
        <button class="btn-secondary btn-sm" :disabled="loading" @click="fetchStatus">
          <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': loading }">refresh</span>
        </button>
      </div>

      <div class="card-body">
        <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="skeleton h-24 w-full rounded-xl" />
          <div class="skeleton h-24 w-full rounded-xl" />
        </div>
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div
            class="p-3 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))]
                   bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]"
          >
            <div class="flex items-center justify-between">
              <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 font-semibold">
                FTP
              </p>
              <span
                class="badge"
                :class="ftpActive ? 'badge-success' : 'badge-neutral'"
              >
                <span class="status-dot" :class="ftpActive ? 'running' : 'stopped'" />
                {{ ftpActive ? 'Active' : 'Inactive' }}
              </span>
            </div>
            <p class="text-xs text-surface-500 dark:text-surface-400 mt-2">
              FTP is disabled on this stack. Use SFTP on port
              <span class="font-mono">{{ sftpDetails.port }}</span> below.
            </p>
          </div>
          <div
            class="p-3 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))]
                   bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]
                   space-y-1"
          >
            <div class="flex items-center justify-between">
              <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 font-semibold">
                SFTP - Primary Site User
              </p>
              <span
                class="badge"
                :class="sftpActive ? 'badge-success' : 'badge-neutral'"
              >
                <span class="status-dot" :class="sftpActive ? 'running' : 'stopped'" />
                {{ sftpActive ? 'Active' : 'Inactive' }}
              </span>
            </div>
            <p class="text-[11px] text-amber-600 dark:text-amber-400 font-medium">
              Full site access (not jailed). For restricted, folder-scoped
              logins use the section below.
            </p>
            <p class="text-xs"><span class="text-surface-500 dark:text-surface-400">Host:</span> <span class="font-mono">{{ sftpDetails.host }}</span></p>
            <p class="text-xs"><span class="text-surface-500 dark:text-surface-400">Port:</span> <span class="font-mono">{{ sftpDetails.port }}</span></p>
            <p class="text-xs">
              <span class="text-surface-500 dark:text-surface-400">User:</span>
              <span v-if="sftpDetails.user" class="font-mono">{{ sftpDetails.user }}</span>
              <span
                v-else
                class="font-mono text-amber-600 dark:text-amber-400 inline-flex items-center gap-1"
                title="Could not detect the unix owner of /home/{domain}/public_html. Run 'Fix Permissions' below."
              >
                <span class="material-symbols-rounded text-sm">warning</span>
                not detected
              </span>
            </p>
            <p class="text-xs"><span class="text-surface-500 dark:text-surface-400">Path:</span> <span class="font-mono break-all">{{ sftpDetails.path }}</span></p>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── SSH Keys ─── -->
    <div class="card">
      <div class="card-header flex items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-cyan-500">vpn_key</span>
          <h3 class="font-semibold">SSH Keys</h3>
        </div>
        <button
          class="btn-secondary btn-sm"
          :disabled="fixingPerms"
          @click="fixPerms"
        >
          <span v-if="fixingPerms" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">build</span>
          Fix Permissions
        </button>
      </div>

      <div class="card-body space-y-4">
        <div
          v-if="!sshKeys.length"
          class="text-sm text-surface-500 dark:text-surface-400 flex items-center gap-2 py-2"
        >
          <span class="material-symbols-rounded text-base">key_off</span>
          No SSH keys configured.
        </div>
        <ul v-else class="space-y-2">
          <li
            v-for="(k, i) in sshKeys"
            :key="i"
            class="p-3 rounded-xl text-xs
                   border border-surface-200 dark:border-[rgb(var(--color-border))]
                   bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]
                   flex items-start justify-between gap-2"
          >
            <div v-if="editing.index === i" class="flex-1 space-y-2">
              <input v-model="editing.label" type="text" class="input w-full text-xs" placeholder="Label" />
              <textarea v-model="editing.key" rows="2" class="input w-full text-xs font-mono"></textarea>
              <div class="flex gap-2 justify-end">
                <button class="btn-secondary btn-sm" @click="editing = { index: null, key: '', label: '' }">Cancel</button>
                <button class="btn-primary btn-sm" @click="saveEdit">Save</button>
              </div>
            </div>
            <template v-else>
              <div class="min-w-0 flex-1 flex items-start gap-2">
                <span
                  class="material-symbols-rounded text-cyan-500 text-base mt-0.5 shrink-0"
                >vpn_key</span>
                <div class="min-w-0">
                  <p class="font-semibold text-sm">{{ k.label || `Key ${i + 1}` }}</p>
                  <p class="font-mono text-[10px] truncate text-surface-500 dark:text-surface-400">
                    {{ k.key ?? k }}
                  </p>
                </div>
              </div>
              <div class="flex items-center gap-1 shrink-0">
                <button class="btn-ghost btn-sm" title="Copy" @click="copy(k.key ?? k)">
                  <span class="material-symbols-rounded text-sm">content_copy</span>
                </button>
                <button class="btn-ghost btn-sm" title="Edit" @click="startEdit(i, k)">
                  <span class="material-symbols-rounded text-sm">edit</span>
                </button>
                <button class="btn-ghost btn-sm text-red-500" title="Delete" @click="askRemoveKey(i)">
                  <span class="material-symbols-rounded text-sm">delete</span>
                </button>
              </div>
            </template>
          </li>
        </ul>

        <div class="border-t border-surface-200 dark:border-[rgb(var(--color-border))] pt-4 space-y-2">
          <h4 class="text-sm font-semibold flex items-center gap-1.5">
            <span class="material-symbols-rounded text-primary-500 text-base">add_circle</span>
            Add a key
          </h4>
          <input v-model="keyForm.label" type="text" class="input w-full text-sm" placeholder="Label (laptop / CI / etc.)" />
          <textarea
            v-model="keyForm.key"
            rows="3"
            class="input w-full text-xs font-mono"
            placeholder="ssh-ed25519 AAAA… or ssh-rsa AAAA…"
          ></textarea>
          <div class="flex justify-end">
            <button class="btn-primary btn-sm" @click="addKey">
              <span class="material-symbols-rounded text-sm">add</span>
              Add Key
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── Additional restricted (jailed) SFTP users ─── -->
    <SftpUsersSection :domain="domain" :port="sftpDetails.port" />

    <!-- ─── Remove SSH key confirm ─── -->
    <Modal
      :show="removeConfirm.show"
      title="Remove SSH key"
      size="md"
      @close="removeConfirm.show = false"
    >
      <p class="text-sm text-surface-600 dark:text-surface-300">
        This removes the key from
        <span class="font-mono">~/.ssh/authorized_keys</span>. Any sessions
        already using this key stay connected, but no new logins with it
        will succeed.
      </p>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="removeConfirm.busy"
          @click="removeConfirm.show = false"
        >
          Cancel
        </button>
        <button
          class="btn-danger"
          :disabled="removeConfirm.busy"
          @click="confirmRemoveKey"
        >
          <span v-if="removeConfirm.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">delete</span>
          Remove key
        </button>
      </template>
    </Modal>
  </div>
</template>
