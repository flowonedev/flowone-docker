<script setup>
// DnsTab
// ---------------------------------------------------------------
// DNS zone records management for the V2 site management view.
// Uses the canonical /api/dns/zones/{domain}/records endpoints
// (the broken `/dns/{domain}` paths fixed in the consolidation
// plan never reach this file - they were only in the SSL modal,
// see SslTab.vue).

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'

const props = defineProps({ domain: { type: String, required: true } })
const toast = useToastStore()

const records = ref([])
const loading = ref(false)
const showAddModal = ref(false)
const adding = ref(false)
const issues = ref(null)
const checking = ref(false)
const fixing = ref(false)
const deleteModal = ref({ open: false, rec: null, busy: false })
// Edit modal: gate destructive changes behind an explicit "Save" click
// instead of letting any keystroke in the table immediately PUT to the
// DNS API. SOA / NS records are not editable from this UI - hosters
// who need to touch them must use the DNS tooling directly.
const editModal = ref({ open: false, rec: null, draft: null, busy: false })

const newRec = ref({ name: '', type: 'A', content: '', ttl: 3600, prio: 0 })

const isProtectedRecord = (rec) => {
  const t = String(rec?.type ?? '').toUpperCase()
  return t === 'SOA' || t === 'NS'
}

const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA']

const sortedRecords = computed(() =>
  [...records.value].sort((a, b) => {
    if (a.type !== b.type) return a.type.localeCompare(b.type)
    return (a.name ?? '').localeCompare(b.name ?? '')
  }),
)

const fetchRecords = async () => {
  loading.value = true
  try {
    const r = await api.get(
      `/dns/zones/${encodeURIComponent(props.domain)}/records`,
    )
    if (r.data?.success) {
      records.value = r.data.data?.records ?? r.data.data ?? []
    }
  } catch {
    records.value = []
  } finally {
    loading.value = false
  }
}

const addRecord = async () => {
  adding.value = true
  try {
    const r = await api.post('/dns/records', {
      zone: props.domain,
      name: newRec.value.name || props.domain,
      type: newRec.value.type,
      content: newRec.value.content,
      ttl: Number(newRec.value.ttl) || 3600,
      prio: Number(newRec.value.prio) || 0,
    })
    if (r.data?.success) {
      toast.success('Record added')
      showAddModal.value = false
      newRec.value = { name: '', type: 'A', content: '', ttl: 3600, prio: 0 }
      await fetchRecords()
    } else {
      toast.error(r.data?.error || 'Failed to add record')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to add record')
  } finally {
    adding.value = false
  }
}

const openEdit = (rec) => {
  if (isProtectedRecord(rec)) return
  editModal.value = {
    open: true,
    rec,
    draft: {
      content: rec.content ?? '',
      ttl: rec.ttl ?? 3600,
      prio: rec.prio ?? 0,
    },
    busy: false,
  }
}

const saveEdit = async () => {
  const { rec, draft } = editModal.value
  if (!rec || !draft) return
  editModal.value.busy = true
  try {
    const r = await api.put(`/dns/records/${rec.id}`, {
      content: draft.content,
      ttl: Number(draft.ttl) || 3600,
      prio: Number(draft.prio) || 0,
    })
    if (r.data?.success) {
      toast.success('Record updated')
      editModal.value = { open: false, rec: null, draft: null, busy: false }
      await fetchRecords()
    } else {
      toast.error(r.data?.error || 'Update failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Update failed')
  } finally {
    editModal.value.busy = false
  }
}

const askDelete = (rec) => {
  if (isProtectedRecord(rec)) return
  deleteModal.value = { open: true, rec, busy: false }
}

const confirmDelete = async () => {
  const rec = deleteModal.value.rec
  if (!rec) return
  deleteModal.value.busy = true
  try {
    const r = await api.delete(`/dns/records/${rec.id}`)
    if (r.data?.success) {
      toast.success('Record deleted')
      await fetchRecords()
    } else {
      toast.error(r.data?.error || 'Delete failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Delete failed')
  } finally {
    deleteModal.value = { open: false, rec: null, busy: false }
  }
}

// Per-record-type color accent used by the records table.
const recordTypeBadgeClass = (type) => {
  const base = 'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold font-mono'
  switch (String(type ?? '').toUpperCase()) {
    case 'A':
    case 'AAAA':
      return `${base} bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300`
    case 'CNAME':
      return `${base} bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300`
    case 'MX':
      return `${base} bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300`
    case 'TXT':
      return `${base} bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300`
    case 'NS':
      return `${base} bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300`
    case 'SRV':
      return `${base} bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300`
    case 'CAA':
      return `${base} bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300`
    default:
      return `${base} bg-surface-100 text-surface-700 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-300`
  }
}

const checkIssues = async () => {
  checking.value = true
  try {
    const r = await api.post(
      `/dns/zones/${encodeURIComponent(props.domain)}/fix-issues`,
      { mode: 'check' },
    )
    if (r.data?.success) issues.value = r.data.data
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Check failed')
  } finally {
    checking.value = false
  }
}

const fixIssues = async () => {
  fixing.value = true
  try {
    const r = await api.post(
      `/dns/zones/${encodeURIComponent(props.domain)}/fix-issues`,
      { mode: 'fix' },
    )
    if (r.data?.success) {
      toast.success('DNS issues fixed')
      await checkIssues()
      await fetchRecords()
    } else {
      toast.error(r.data?.error || 'Fix failed')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Fix failed')
  } finally {
    fixing.value = false
  }
}

onMounted(fetchRecords)
</script>

<template>
  <div class="space-y-5">
    <!-- ─── Records list ─── -->
    <div class="card">
      <div class="card-header flex items-center justify-between gap-2 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-sky-500">dns</span>
          <h3 class="font-semibold">DNS Records</h3>
        </div>
        <div class="flex items-center gap-2">
          <button
            class="btn-secondary btn-sm"
            :disabled="checking"
            @click="checkIssues"
          >
            <span class="material-symbols-rounded text-sm">troubleshoot</span>
            Check Issues
          </button>
          <button
            class="btn-secondary btn-sm"
            :disabled="loading"
            @click="fetchRecords"
          >
            <span
              class="material-symbols-rounded text-sm"
              :class="{ 'animate-spin': loading }"
            >refresh</span>
          </button>
          <button class="btn-primary btn-sm" @click="showAddModal = true">
            <span class="material-symbols-rounded text-sm">add</span>
            Add Record
          </button>
        </div>
      </div>

      <div class="card-body">
        <div v-if="loading" class="space-y-2">
          <div class="skeleton h-10 w-full rounded-xl" />
          <div class="skeleton h-10 w-full rounded-xl" />
          <div class="skeleton h-10 w-full rounded-xl" />
          <div class="skeleton h-10 w-3/4 rounded-xl" />
        </div>
        <div
          v-else-if="!sortedRecords.length"
          class="py-8 text-center flex flex-col items-center gap-2 text-surface-500 dark:text-surface-400"
        >
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">
            dns
          </span>
          <p class="text-sm">No DNS records found for this zone.</p>
        </div>
        <div v-else class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th class="text-left">Type</th>
                <th class="text-left">Name</th>
                <th class="text-left">Content</th>
                <th class="text-left">TTL</th>
                <th class="text-left">Prio</th>
                <th class="text-right w-24">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rec in sortedRecords" :key="rec.id">
                <td>
                  <span :class="recordTypeBadgeClass(rec.type)">{{ rec.type }}</span>
                </td>
                <td class="font-mono text-xs">{{ rec.name }}</td>
                <td
                  class="font-mono text-xs max-w-xs truncate"
                  :title="rec.content"
                >
                  {{ rec.content }}
                </td>
                <td class="text-xs text-surface-500">{{ rec.ttl }}</td>
                <td class="text-xs text-surface-500 text-center">
                  <span v-if="rec.type === 'MX' || rec.type === 'SRV'">
                    {{ rec.prio ?? rec.priority ?? '-' }}
                  </span>
                  <span v-else class="text-surface-400">-</span>
                </td>
                <td class="text-right">
                  <div class="flex justify-end gap-1">
                    <button
                      v-if="!isProtectedRecord(rec)"
                      class="btn-ghost btn-sm"
                      title="Edit record"
                      @click="openEdit(rec)"
                    >
                      <span class="material-symbols-rounded text-sm">edit</span>
                    </button>
                    <button
                      v-if="!isProtectedRecord(rec)"
                      class="btn-ghost btn-sm text-red-500"
                      title="Delete record"
                      @click="askDelete(rec)"
                    >
                      <span class="material-symbols-rounded text-sm">delete</span>
                    </button>
                    <span
                      v-else
                      class="text-[10px] text-surface-400 italic"
                      title="SOA / NS records are managed by the system"
                    >
                      protected
                    </span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ─── Issues panel ─── -->
    <div v-if="issues" class="card">
      <div class="card-header flex items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <span
            class="material-symbols-rounded"
            :class="(issues.issues ?? []).length ? 'text-amber-500' : 'text-emerald-500'"
          >
            {{ (issues.issues ?? []).length ? 'warning' : 'check_circle' }}
          </span>
          <h3 class="font-semibold">DNS Health</h3>
        </div>
        <button
          v-if="(issues.issues ?? []).length"
          class="btn-primary btn-sm"
          :disabled="fixing"
          @click="fixIssues"
        >
          <span v-if="fixing" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">build</span>
          Auto-Fix
        </button>
      </div>
      <div class="card-body">
        <div
          v-if="!(issues.issues ?? []).length"
          class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400 text-sm"
        >
          <span class="material-symbols-rounded">check_circle</span>
          No DNS issues detected.
        </div>
        <ul v-else class="space-y-2">
          <li
            v-for="iss in issues.issues"
            :key="iss.type ?? iss.message"
            class="p-3 rounded-xl text-sm
                   border border-amber-200 dark:border-amber-500/30
                   bg-amber-50 dark:bg-amber-500/5
                   flex items-start gap-2"
          >
            <span class="material-symbols-rounded text-amber-500 text-base shrink-0 mt-0.5">
              warning
            </span>
            <div class="min-w-0">
              <p class="font-medium">{{ iss.title ?? iss.type }}</p>
              <p class="text-xs text-surface-500 dark:text-surface-400">{{ iss.message }}</p>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <!-- ─── Add modal ─── -->
    <Modal
      :show="showAddModal"
      title="Add DNS Record"
      size="md"
      @close="showAddModal = false"
    >
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-semibold mb-1">Type</label>
          <select v-model="newRec.type" class="input w-full">
            <option v-for="t in recordTypes" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">Name</label>
          <input
            v-model="newRec.name"
            type="text"
            class="input w-full"
            :placeholder="domain"
          />
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">Content</label>
          <input v-model="newRec.content" type="text" class="input w-full" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-semibold mb-1">TTL</label>
            <input v-model="newRec.ttl" type="number" class="input w-full" />
          </div>
          <div v-if="newRec.type === 'MX' || newRec.type === 'SRV'">
            <label class="block text-xs font-semibold mb-1">Priority</label>
            <input v-model="newRec.prio" type="number" class="input w-full" />
          </div>
        </div>
      </div>
      <template #footer>
        <button class="btn-secondary" @click="showAddModal = false">Cancel</button>
        <button class="btn-primary" :disabled="adding" @click="addRecord">
          <span v-if="adding" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">add</span>
          Add
        </button>
      </template>
    </Modal>

    <!-- ─── Edit record modal ─── -->
    <Modal
      :show="editModal.open"
      :title="`Edit ${editModal.rec?.type ?? ''} record${editModal.rec?.name ? ` for ${editModal.rec.name}` : ''}`"
      size="md"
      @close="editModal.open = false"
    >
      <div v-if="editModal.draft" class="space-y-3">
        <div class="grid grid-cols-2 gap-2 text-xs text-surface-500 dark:text-surface-400">
          <div>
            <p class="uppercase tracking-wide mb-1">Type</p>
            <p class="font-mono text-sm text-surface-900 dark:text-surface-100">{{ editModal.rec?.type }}</p>
          </div>
          <div>
            <p class="uppercase tracking-wide mb-1">Name</p>
            <p class="font-mono text-sm text-surface-900 dark:text-surface-100 truncate">{{ editModal.rec?.name }}</p>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">Content</label>
          <input
            v-model="editModal.draft.content"
            type="text"
            class="input w-full font-mono text-sm"
          />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-semibold mb-1">TTL</label>
            <input
              v-model="editModal.draft.ttl"
              type="number"
              class="input w-full"
            />
          </div>
          <div v-if="editModal.rec?.type === 'MX' || editModal.rec?.type === 'SRV'">
            <label class="block text-xs font-semibold mb-1">Priority</label>
            <input
              v-model="editModal.draft.prio"
              type="number"
              class="input w-full"
            />
          </div>
        </div>
      </div>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="editModal.busy"
          @click="editModal.open = false"
        >
          Cancel
        </button>
        <button
          class="btn-primary"
          :disabled="editModal.busy"
          @click="saveEdit"
        >
          <span v-if="editModal.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">save</span>
          Save changes
        </button>
      </template>
    </Modal>

    <!-- ─── Delete record confirm ─── -->
    <Modal
      :show="deleteModal.open"
      :title="`Delete ${deleteModal.rec?.type ?? ''} record`"
      size="md"
      @close="deleteModal.open = false"
    >
      <p class="text-sm text-surface-600 dark:text-surface-300">
        Delete the
        <span class="font-mono">{{ deleteModal.rec?.type }}</span> record for
        <span class="font-mono">{{ deleteModal.rec?.name }}</span>?
        Resolvers may cache it until the TTL expires.
      </p>
      <template #footer>
        <button
          class="btn-secondary"
          :disabled="deleteModal.busy"
          @click="deleteModal.open = false"
        >
          Cancel
        </button>
        <button
          class="btn-danger"
          :disabled="deleteModal.busy"
          @click="confirmDelete"
        >
          <span v-if="deleteModal.busy" class="spinner-sm" />
          <span v-else class="material-symbols-rounded text-sm">delete</span>
          Delete record
        </button>
      </template>
    </Modal>
  </div>
</template>
