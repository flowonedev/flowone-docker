import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

/**
 * Contacts store — full address book backed by the FlowOne /address-book*
 * endpoints. Mirrors the calendar store conventions (Composition API,
 * { success, data, message } envelope).
 */
export const useContactsStore = defineStore('contacts', () => {
  const addressBooks = ref([])
  const contacts = ref([])
  const selectedBookId = ref(null) // null = all books
  const search = ref('')
  const loading = ref(false)
  const importing = ref(false)

  const selectedBook = computed(() =>
    addressBooks.value.find((b) => b.id === selectedBookId.value) || null)

  const totalContacts = computed(() =>
    addressBooks.value.reduce((sum, b) => sum + Number(b.contact_count || 0), 0))

  async function fetchAddressBooks() {
    try {
      const res = await api.get('/address-books')
      if (res.data.success) {
        addressBooks.value = res.data.data.address_books || []
      }
    } catch (e) {
      console.error('fetchAddressBooks failed', e)
    }
  }

  async function fetchContacts() {
    loading.value = true
    try {
      const params = new URLSearchParams()
      if (selectedBookId.value) params.set('book_id', selectedBookId.value)
      if (search.value) params.set('search', search.value)
      const qs = params.toString()
      const res = await api.get('/address-book/contacts' + (qs ? `?${qs}` : ''))
      if (res.data.success) {
        contacts.value = res.data.data.contacts || []
      }
    } catch (e) {
      console.error('fetchContacts failed', e)
    } finally {
      loading.value = false
    }
  }

  async function createAddressBook(data) {
    const res = await api.post('/address-books', data)
    if (res.data.success) {
      await fetchAddressBooks()
      return { success: true, book: res.data.data.address_book }
    }
    return { success: false, error: res.data.message }
  }

  async function deleteAddressBook(id) {
    const res = await api.delete(`/address-books/${id}`)
    if (res.data.success) {
      if (selectedBookId.value === id) selectedBookId.value = null
      await fetchAddressBooks()
      await fetchContacts()
      return { success: true }
    }
    return { success: false, error: res.data.message }
  }

  async function createContact(data) {
    const res = await api.post('/address-book/contacts', data)
    if (res.data.success) {
      await Promise.all([fetchContacts(), fetchAddressBooks()])
      return { success: true, contact: res.data.data.contact }
    }
    return { success: false, error: res.data.message }
  }

  async function updateContact(id, data) {
    const res = await api.put(`/address-book/contacts/${id}`, data)
    if (res.data.success) {
      const idx = contacts.value.findIndex((c) => c.id === id)
      if (idx !== -1) contacts.value[idx] = res.data.data.contact
      return { success: true, contact: res.data.data.contact }
    }
    return { success: false, error: res.data.message }
  }

  async function deleteContact(id) {
    const res = await api.delete(`/address-book/contacts/${id}`)
    if (res.data.success) {
      contacts.value = contacts.value.filter((c) => c.id !== id)
      await fetchAddressBooks()
      return { success: true }
    }
    return { success: false, error: res.data.message }
  }

  /**
   * Promote a contact out of the non-synced "Other contacts" pool into the
   * default synced book (so it reaches phones via CardDAV). Identified by its
   * primary email, which the backend reconciles.
   */
  async function promoteContact(contact) {
    const email = contact?.emails?.[0]?.value
    if (!email) return { success: false, error: 'no_email' }
    const res = await api.post('/contacts/save', { email, name: contact.full_name || '' })
    if (res.data.success) {
      await Promise.all([fetchAddressBooks(), fetchContacts()])
      return { success: true, contact: res.data.data?.contact }
    }
    return { success: false, error: res.data.message }
  }

  /**
   * Import a vCard/CSV file. Accepts a File object; sends as multipart.
   */
  async function importFile(file, bookId = null) {
    importing.value = true
    try {
      const form = new FormData()
      form.append('file', file)
      if (bookId) form.append('book_id', bookId)
      const res = await api.post('/address-book/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      if (res.data.success) {
        await Promise.all([fetchContacts(), fetchAddressBooks()])
        return { success: true, result: res.data.data }
      }
      return { success: false, error: res.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Import failed' }
    } finally {
      importing.value = false
    }
  }

  function selectBook(id) {
    selectedBookId.value = id
    fetchContacts()
  }

  function exportUrl() {
    const params = new URLSearchParams()
    if (selectedBookId.value) params.set('book_id', selectedBookId.value)
    const qs = params.toString()
    return '/address-book/export' + (qs ? `?${qs}` : '')
  }

  return {
    addressBooks, contacts, selectedBookId, selectedBook, search, loading, importing,
    totalContacts,
    fetchAddressBooks, fetchContacts, createAddressBook, deleteAddressBook,
    createContact, updateContact, deleteContact, promoteContact, importFile, selectBook, exportUrl,
  }
})
