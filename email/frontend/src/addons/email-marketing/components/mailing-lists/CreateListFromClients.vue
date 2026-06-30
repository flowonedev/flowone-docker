<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useMailingListsStore } from '@/addons/email-marketing/stores/mailingLists'
import { useToastStore } from '@/stores/toast'

const emit = defineEmits(['close', 'created'])
const toast = useToastStore()
const mailingLists = useMailingListsStore()

const step = ref(1)
const loading = ref(false)
const contacts = ref([])
const selectedIds = ref(new Set())
const listName = ref('')
const listDescription = ref('')
const selectAll = ref(false)
const searchQuery = ref('')
const creating = ref(false)

const filteredContacts = computed(() => {
  if (!searchQuery.value) return contacts.value
  const q = searchQuery.value.toLowerCase()
  return contacts.value.filter(c =>
    (c.name || '').toLowerCase().includes(q) ||
    (c.email || '').toLowerCase().includes(q) ||
    (c.client_name || '').toLowerCase().includes(q) ||
    (c.position || '').toLowerCase().includes(q)
  )
})

const selectedCount = computed(() => selectedIds.value.size)

function toggleSelectAll() {
  if (selectAll.value) {
    filteredContacts.value.forEach(c => selectedIds.value.add(c.id))
  } else {
    filteredContacts.value.forEach(c => selectedIds.value.delete(c.id))
  }
}

function toggleContact(id) {
  if (selectedIds.value.has(id)) {
    selectedIds.value.delete(id)
  } else {
    selectedIds.value.add(id)
  }
  selectAll.value = filteredContacts.value.every(c => selectedIds.value.has(c.id))
}

async function fetchContacts() {
  loading.value = true
  try {
    const res = await api.get('/clients/all-contacts')
    contacts.value = res.data?.data?.contacts || []
  } catch (e) {
    toast.error('Failed to load client contacts')
  } finally {
    loading.value = false
  }
}

function goToStep2() {
  if (selectedCount.value === 0) {
    toast.warning('Select at least one contact')
    return
  }
  step.value = 2
}

async function createListFromSelected() {
  if (!listName.value.trim()) {
    toast.warning('Please enter a list name')
    return
  }

  creating.value = true
  try {
    const result = await mailingLists.createList({
      name: listName.value.trim(),
      description: listDescription.value.trim() || undefined,
    })

    if (!result.success) {
      toast.error(result.error || 'Failed to create list')
      creating.value = false
      return
    }

    const listId = result.id
    const selectedContacts = contacts.value
      .filter(c => selectedIds.value.has(c.id))
      .map(c => ({
        email: c.email,
        name: c.name || '',
        phone: c.phone || '',
        company: c.client_name || '',
        notes: c.position || '',
      }))

    const importResult = await mailingLists.importContacts(listId, selectedContacts, 'client-import')

    if (importResult.success) {
      toast.success(`List "${listName.value}" created with ${importResult.imported} contacts`)
      emit('created', listId)
      emit('close')
    } else {
      toast.error(importResult.error || 'Failed to import contacts')
    }
  } catch (e) {
    toast.error('An unexpected error occurred')
  } finally {
    creating.value = false
  }
}

onMounted(fetchContacts)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-xl text-primary-500">group_add</span>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
            {{ step === 1 ? 'Select Client Contacts' : 'Name Your List' }}
          </h2>
        </div>
        <button @click="emit('close')" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
          <span class="material-symbols-rounded text-xl text-surface-500">close</span>
        </button>
      </div>

      <!-- Step 1: Select contacts -->
      <template v-if="step === 1">
        <!-- Search -->
        <div class="px-6 py-3 border-b border-surface-100 dark:border-surface-700">
          <div class="relative">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-lg text-surface-400">search</span>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Search by name, email, client..."
              class="w-full pl-10 pr-4 py-2 rounded-xl bg-surface-100 dark:bg-surface-800 border-none text-sm text-surface-900 dark:text-surface-100 placeholder-surface-400 focus:ring-2 focus:ring-primary-400 outline-none"
            />
          </div>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-y-auto">
          <div v-if="loading" class="flex items-center justify-center py-16">
            <span class="material-symbols-rounded text-3xl text-primary-500 animate-spin">progress_activity</span>
          </div>

          <div v-else-if="contacts.length === 0" class="flex flex-col items-center justify-center py-16 text-surface-400">
            <span class="material-symbols-rounded text-5xl mb-3">person_off</span>
            <p class="text-sm">No client contacts found.</p>
            <p class="text-xs mt-1">Add contacts to your clients first.</p>
          </div>

          <table v-else class="w-full text-sm">
            <thead class="sticky top-0 bg-surface-50 dark:bg-surface-800 text-left z-10">
              <tr>
                <th class="px-6 py-2.5 w-10">
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="sr-only peer" />
                    <div class="w-9 h-5 bg-surface-200 dark:bg-surface-600 peer-checked:bg-primary-500 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-full"></div>
                  </label>
                </th>
                <th class="px-3 py-2.5 font-medium text-surface-500">Name</th>
                <th class="px-3 py-2.5 font-medium text-surface-500">Email</th>
                <th class="px-3 py-2.5 font-medium text-surface-500">Client</th>
                <th class="px-3 py-2.5 font-medium text-surface-500">Position</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="contact in filteredContacts"
                :key="contact.id"
                @click="toggleContact(contact.id)"
                class="border-t border-surface-100 dark:border-surface-700 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
                :class="selectedIds.has(contact.id) ? 'bg-primary-50/50 dark:bg-primary-500/5' : ''"
              >
                <td class="px-6 py-2.5">
                  <label class="relative inline-flex items-center cursor-pointer" @click.stop>
                    <input
                      type="checkbox"
                      :checked="selectedIds.has(contact.id)"
                      @change="toggleContact(contact.id)"
                      class="sr-only peer"
                    />
                    <div class="w-9 h-5 bg-surface-200 dark:bg-surface-600 peer-checked:bg-primary-500 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-full"></div>
                  </label>
                </td>
                <td class="px-3 py-2.5 text-surface-900 dark:text-surface-100 font-medium">{{ contact.name || '-' }}</td>
                <td class="px-3 py-2.5 text-surface-600 dark:text-surface-400">{{ contact.email }}</td>
                <td class="px-3 py-2.5 text-surface-500">{{ contact.client_name || '-' }}</td>
                <td class="px-3 py-2.5 text-surface-500">{{ contact.position || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between px-6 py-3 border-t border-surface-200 dark:border-[rgb(var(--color-border))]">
          <span class="text-xs text-surface-500">{{ selectedCount }} contact{{ selectedCount !== 1 ? 's' : '' }} selected</span>
          <button
            @click="goToStep2"
            :disabled="selectedCount === 0"
            class="px-5 py-2 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-40 transition-colors"
          >
            Next
          </button>
        </div>
      </template>

      <!-- Step 2: Name & create -->
      <template v-if="step === 2">
        <div class="flex-1 overflow-y-auto px-6 py-6 space-y-5">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">List Name</label>
            <input
              v-model="listName"
              type="text"
              placeholder="e.g. Clients Newsletter"
              class="w-full px-4 py-2.5 rounded-xl bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-sm text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-400 outline-none"
              autofocus
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">Description (optional)</label>
            <textarea
              v-model="listDescription"
              rows="2"
              placeholder="Short description..."
              class="w-full px-4 py-2.5 rounded-xl bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-sm text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-400 outline-none resize-none"
            ></textarea>
          </div>
          <div class="p-3 rounded-xl bg-surface-50 dark:bg-surface-800 text-sm text-surface-600 dark:text-surface-400">
            <span class="material-symbols-rounded text-base align-middle mr-1">info</span>
            {{ selectedCount }} contact{{ selectedCount !== 1 ? 's' : '' }} will be imported into this new mailing list.
          </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between px-6 py-3 border-t border-surface-200 dark:border-[rgb(var(--color-border))]">
          <button @click="step = 1" class="px-4 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
            Back
          </button>
          <button
            @click="createListFromSelected"
            :disabled="creating || !listName.trim()"
            class="inline-flex items-center gap-2 px-5 py-2 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-40 transition-colors"
          >
            <span v-if="creating" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
            <span v-else class="material-symbols-rounded text-base">playlist_add</span>
            Create List
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
