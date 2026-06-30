<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/shared/AppHeader.vue'
import Modal from '@/components/shared/Modal.vue'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import api from '@/services/api'
import { useContactsStore } from '@/addons/contacts/stores/contacts'
import { useToastStore } from '@/stores/toast'

const { t } = useI18n()
const store = useContactsStore()
const toast = useToastStore()

const isMobile = ref(window.innerWidth < 640)
const onResize = () => { isMobile.value = window.innerWidth < 640 }

const fileInput = ref(null)
const editModal = ref(false)
const deleteTarget = ref(null)
const saving = ref(false)

const emptyForm = () => ({
  id: null,
  full_name: '',
  first_name: '',
  last_name: '',
  organization: '',
  job_title: '',
  notes: '',
  emails: [{ type: 'work', value: '' }],
  phones: [{ type: 'mobile', value: '' }],
})
const form = ref(emptyForm())

const searchInput = ref('')
let searchTimer = null
watch(searchInput, (v) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    store.search = v
    store.fetchContacts()
  }, 300)
})

const initials = (c) => {
  const n = (c.full_name || '').trim()
  if (n) return n.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase()
  const e = c.emails?.[0]?.value || '?'
  return e[0].toUpperCase()
}

const primaryEmail = (c) => c.emails?.[0]?.value || ''
const primaryPhone = (c) => c.phones?.[0]?.value || ''

const sortedContacts = computed(() => store.contacts)

// Sync boundary: synced books reach phones via CardDAV; the non-synced
// "Other contacts" pool holds auto-collected + client-derived people.
const isSyncedBook = (b) => Number(b.is_synced ?? 1) === 1
const syncedBooks = computed(() => store.addressBooks.filter(isSyncedBook))
const otherBooks = computed(() => store.addressBooks.filter((b) => !isSyncedBook(b)))
const otherBookIds = computed(() => new Set(otherBooks.value.map((b) => b.id)))
const otherCount = computed(() => otherBooks.value.reduce((s, b) => s + Number(b.contact_count || 0), 0))
const syncedCount = computed(() => syncedBooks.value.reduce((s, b) => s + Number(b.contact_count || 0), 0))

// A contact is "in the Other pool" when its book isn't synced.
const isOtherContact = (c) => otherBookIds.value.has(c.addressbook_id)

async function promote(c) {
  const res = await store.promoteContact(c)
  if (res.success) toast.success(t('contacts.savedToContacts', { name: c.full_name || primaryEmail(c) }))
  else toast.error(res.error || t('contacts.saveFailed'))
}

onMounted(async () => {
  window.addEventListener('resize', onResize)
  await store.fetchAddressBooks()
  await store.fetchContacts()
})

function openCreate() {
  form.value = emptyForm()
  editModal.value = true
}

function openEdit(c) {
  form.value = {
    id: c.id,
    full_name: c.full_name || '',
    first_name: c.first_name || '',
    last_name: c.last_name || '',
    organization: c.organization || '',
    job_title: c.job_title || '',
    notes: c.notes || '',
    emails: (c.emails && c.emails.length) ? JSON.parse(JSON.stringify(c.emails)) : [{ type: 'work', value: '' }],
    phones: (c.phones && c.phones.length) ? JSON.parse(JSON.stringify(c.phones)) : [{ type: 'mobile', value: '' }],
  }
  editModal.value = true
}

function addEmail() { form.value.emails.push({ type: 'other', value: '' }) }
function removeEmail(i) { form.value.emails.splice(i, 1) }
function addPhone() { form.value.phones.push({ type: 'other', value: '' }) }
function removePhone(i) { form.value.phones.splice(i, 1) }

async function saveContact() {
  const payload = {
    ...form.value,
    book_id: store.selectedBookId || undefined,
    emails: form.value.emails.filter((e) => e.value.trim()),
    phones: form.value.phones.filter((p) => p.value.trim()),
  }
  if (!payload.full_name.trim() && !payload.first_name.trim() && payload.emails.length === 0) {
    toast.error(t('contacts.nameOrEmailRequired'))
    return
  }
  saving.value = true
  const res = form.value.id
    ? await store.updateContact(form.value.id, payload)
    : await store.createContact(payload)
  saving.value = false
  if (res.success) {
    toast.success(form.value.id ? t('contacts.saved') : t('contacts.created'))
    editModal.value = false
  } else {
    toast.error(res.error || t('contacts.saveFailed'))
  }
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  const res = await store.deleteContact(deleteTarget.value.id)
  if (res.success) toast.success(t('contacts.deleted'))
  else toast.error(res.error || t('contacts.deleteFailed'))
  deleteTarget.value = null
}

function triggerImport() {
  fileInput.value?.click()
}

async function onImportFile(e) {
  const file = e.target.files?.[0]
  if (!file) return
  const res = await store.importFile(file, store.selectedBookId)
  e.target.value = ''
  if (res.success) {
    toast.success(t('contacts.importResult', { imported: res.result.imported, updated: res.result.updated }))
  } else {
    toast.error(res.error || t('contacts.importFailed'))
  }
}

async function exportContacts() {
  try {
    const res = await api.get(store.exportUrl(), { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/vcard' }))
    const a = document.createElement('a')
    a.href = url
    a.download = 'contacts.vcf'
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    toast.error(t('contacts.exportFailed'))
  }
}

async function createBook() {
  const name = window.prompt(t('contacts.newBookPrompt'))
  if (!name) return
  const res = await store.createAddressBook({ name })
  if (res.success) toast.success(t('contacts.bookCreated'))
  else toast.error(res.error || t('contacts.saveFailed'))
}
</script>

<template>
  <div class="h-[100dvh] bg-surface-50 dark:bg-surface-900 flex flex-col overflow-hidden">
    <AppHeader current-view="contacts" icon="contacts" :title="$t('contacts.title')" />

    <div class="flex-1 flex overflow-hidden">
      <!-- Sidebar: address books -->
      <aside v-if="!isMobile" class="w-64 flex-shrink-0 border-r border-surface-200 dark:border-surface-700 overflow-y-auto p-4 space-y-1">
        <button
          @click="store.selectBook(null)"
          :class="['w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium transition',
            store.selectedBookId === null ? 'bg-primary-50 dark:bg-primary-500/15 text-primary-600 dark:text-primary-400' : 'hover:bg-surface-100 dark:hover:bg-surface-800']"
        >
          <span class="flex items-center gap-2"><span class="material-symbols-rounded text-base">groups</span>{{ $t('contacts.allContacts') }}</span>
          <span class="text-xs text-surface-400">{{ store.totalContacts }}</span>
        </button>

        <!-- Synced books -->
        <button
          v-for="book in syncedBooks"
          :key="book.id"
          @click="store.selectBook(book.id)"
          :class="['w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium transition',
            store.selectedBookId === book.id ? 'bg-primary-50 dark:bg-primary-500/15 text-primary-600 dark:text-primary-400' : 'hover:bg-surface-100 dark:hover:bg-surface-800']"
        >
          <span class="flex items-center gap-2 min-w-0">
            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="{ backgroundColor: book.color || '#3b82f6' }"></span>
            <span class="truncate">{{ book.name }}</span>
          </span>
          <span class="text-xs text-surface-400">{{ book.contact_count }}</span>
        </button>

        <button @click="createBook" class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-800 transition mt-2">
          <span class="material-symbols-rounded text-base">add</span>{{ $t('contacts.newBook') }}
        </button>

        <!-- Other contacts (non-synced pool) -->
        <template v-if="otherBooks.length">
          <div class="pt-4 mt-3 border-t border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-1.5 px-3 mb-1">
              <span class="material-symbols-rounded text-sm text-surface-400">cloud_off</span>
              <span class="text-[11px] font-semibold uppercase tracking-wide text-surface-400">{{ $t('contacts.otherContacts') }}</span>
            </div>
            <p class="px-3 mb-1 text-[11px] text-surface-400 leading-snug">{{ $t('contacts.otherContactsHint') }}</p>
            <button
              v-for="book in otherBooks"
              :key="book.id"
              @click="store.selectBook(book.id)"
              :class="['w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium transition',
                store.selectedBookId === book.id ? 'bg-surface-200/70 dark:bg-surface-700/60 text-surface-700 dark:text-surface-200' : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-800']"
            >
              <span class="flex items-center gap-2 min-w-0">
                <span class="material-symbols-rounded text-base text-surface-400">person_off</span>
                <span class="truncate">{{ book.name }}</span>
              </span>
              <span class="text-xs text-surface-400">{{ book.contact_count }}</span>
            </button>
          </div>
        </template>
      </aside>

      <!-- Main -->
      <main class="flex-1 overflow-y-auto flex flex-col">
        <!-- Toolbar -->
        <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center gap-3 flex-wrap">
          <div class="relative flex-1 min-w-[180px]">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input v-model="searchInput" type="text" class="input pl-10" :placeholder="$t('contacts.searchPlaceholder')" />
          </div>
          <button @click="triggerImport" class="btn-secondary btn-sm" :disabled="store.importing">
            <span class="material-symbols-rounded" :class="store.importing && 'animate-spin'">{{ store.importing ? 'progress_activity' : 'upload' }}</span>
            {{ $t('contacts.import') }}
          </button>
          <button @click="exportContacts" class="btn-secondary btn-sm" :disabled="store.contacts.length === 0">
            <span class="material-symbols-rounded">download</span>
            {{ $t('contacts.export') }}
          </button>
          <button @click="openCreate" class="btn-primary btn-sm">
            <span class="material-symbols-rounded">person_add</span>
            {{ $t('contacts.newContact') }}
          </button>
          <input ref="fileInput" type="file" accept=".vcf,.vcard,.csv" class="hidden" @change="onImportFile" />
        </div>

        <!-- List -->
        <div class="flex-1 overflow-y-auto">
          <div v-if="store.loading" class="flex items-center justify-center py-16"><span class="spinner"></span></div>

          <div v-else-if="sortedContacts.length === 0" class="flex flex-col items-center justify-center py-20 text-center text-surface-400">
            <span class="material-symbols-rounded text-5xl mb-3">contacts</span>
            <p class="font-medium">{{ $t('contacts.empty') }}</p>
            <p class="text-sm mt-1">{{ $t('contacts.emptyHint') }}</p>
          </div>

          <ul v-else class="divide-y divide-surface-100 dark:divide-surface-800">
            <li
              v-for="c in sortedContacts"
              :key="c.id"
              @click="openEdit(c)"
              class="flex items-center gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/60 cursor-pointer group"
            >
              <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 flex items-center justify-center font-semibold text-sm flex-shrink-0">
                {{ initials(c) }}
              </div>
              <div class="min-w-0 flex-1">
                <p class="font-medium truncate flex items-center gap-1.5">
                  <span class="truncate">{{ c.full_name || primaryEmail(c) || $t('contacts.unnamed') }}</span>
                  <span v-if="c.origin === 'client'" class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded">{{ $t('contacts.fromClient') }}</span>
                  <span v-else-if="c.origin === 'auto'" class="text-[10px] font-semibold uppercase tracking-wide text-surface-500 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded">{{ $t('contacts.autoAdded') }}</span>
                </p>
                <p class="text-xs text-surface-500 truncate">
                  <span v-if="c.organization">{{ c.organization }}<span v-if="c.job_title"> · {{ c.job_title }}</span></span>
                  <span v-else-if="primaryEmail(c)">{{ primaryEmail(c) }}</span>
                </p>
              </div>
              <div class="hidden md:block text-right min-w-0">
                <p class="text-sm truncate">{{ primaryEmail(c) }}</p>
                <p class="text-xs text-surface-500 truncate">{{ primaryPhone(c) }}</p>
              </div>
              <button
                v-if="isOtherContact(c)"
                @click.stop="promote(c)"
                class="btn-icon opacity-0 group-hover:opacity-100 transition text-surface-400 hover:text-primary-500"
                :title="$t('contacts.saveToContacts')"
              >
                <span class="material-symbols-rounded">bookmark_add</span>
              </button>
              <button
                @click.stop="deleteTarget = c"
                class="btn-icon opacity-0 group-hover:opacity-100 transition text-surface-400 hover:text-red-500"
              >
                <span class="material-symbols-rounded">delete</span>
              </button>
            </li>
          </ul>
        </div>
      </main>
    </div>

    <!-- Create / edit modal -->
    <Modal :show="editModal" :title="form.id ? $t('contacts.editContact') : $t('contacts.newContact')" size="lg" @close="editModal = false">
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium mb-1">{{ $t('contacts.firstName') }}</label>
            <input v-model="form.first_name" type="text" class="input" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ $t('contacts.lastName') }}</label>
            <input v-model="form.last_name" type="text" class="input" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">{{ $t('contacts.displayName') }}</label>
          <input v-model="form.full_name" type="text" class="input" :placeholder="$t('contacts.displayNameHint')" />
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium mb-1">{{ $t('contacts.organization') }}</label>
            <input v-model="form.organization" type="text" class="input" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ $t('contacts.jobTitle') }}</label>
            <input v-model="form.job_title" type="text" class="input" />
          </div>
        </div>

        <!-- Emails -->
        <div>
          <label class="block text-sm font-medium mb-1">{{ $t('contacts.emails') }}</label>
          <div v-for="(em, i) in form.emails" :key="'em' + i" class="flex gap-2 mb-2">
            <select v-model="em.type" class="input w-28">
              <option value="work">{{ $t('contacts.typeWork') }}</option>
              <option value="home">{{ $t('contacts.typeHome') }}</option>
              <option value="other">{{ $t('contacts.typeOther') }}</option>
            </select>
            <input v-model="em.value" type="email" class="input flex-1" placeholder="name@example.com" />
            <button @click="removeEmail(i)" class="btn-icon text-surface-400 hover:text-red-500"><span class="material-symbols-rounded">close</span></button>
          </div>
          <button @click="addEmail" class="text-sm text-primary-600 dark:text-primary-400 flex items-center gap-1"><span class="material-symbols-rounded text-base">add</span>{{ $t('contacts.addEmail') }}</button>
        </div>

        <!-- Phones -->
        <div>
          <label class="block text-sm font-medium mb-1">{{ $t('contacts.phones') }}</label>
          <div v-for="(ph, i) in form.phones" :key="'ph' + i" class="flex gap-2 mb-2">
            <select v-model="ph.type" class="input w-28">
              <option value="mobile">{{ $t('contacts.typeMobile') }}</option>
              <option value="work">{{ $t('contacts.typeWork') }}</option>
              <option value="home">{{ $t('contacts.typeHome') }}</option>
              <option value="other">{{ $t('contacts.typeOther') }}</option>
            </select>
            <input v-model="ph.value" type="tel" class="input flex-1" placeholder="+1 555 0100" />
            <button @click="removePhone(i)" class="btn-icon text-surface-400 hover:text-red-500"><span class="material-symbols-rounded">close</span></button>
          </div>
          <button @click="addPhone" class="text-sm text-primary-600 dark:text-primary-400 flex items-center gap-1"><span class="material-symbols-rounded text-base">add</span>{{ $t('contacts.addPhone') }}</button>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ $t('contacts.notes') }}</label>
          <textarea v-model="form.notes" rows="2" class="input"></textarea>
        </div>
      </div>

      <template #footer>
        <button class="btn-secondary" @click="editModal = false">{{ $t('contacts.cancel') }}</button>
        <button class="btn-primary" @click="saveContact" :disabled="saving">
          <span v-if="saving" class="material-symbols-rounded animate-spin">progress_activity</span>
          {{ $t('contacts.save') }}
        </button>
      </template>
    </Modal>

    <ConfirmModal
      :show="!!deleteTarget"
      :title="$t('contacts.deleteContact')"
      :message="$t('contacts.deleteConfirm', { name: deleteTarget?.full_name || primaryEmail(deleteTarget || {}) || $t('contacts.unnamed') })"
      type="danger"
      @confirm="confirmDelete"
      @cancel="deleteTarget = null"
    />

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>
