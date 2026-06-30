<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const emit = defineEmits(['close'])
const toast = useToastStore()

const loading = ref(false)
const unsubscribes = ref([])
const total = ref(0)
const page = ref(0)
const pageSize = 50
const resubscribing = ref(null)

async function fetchUnsubscribes() {
  loading.value = true
  try {
    const res = await api.get('/email-marketing/unsubscribes', {
      params: { limit: pageSize, offset: page.value * pageSize }
    })
    unsubscribes.value = res.data?.data?.unsubscribes || []
    total.value = res.data?.data?.total || 0
  } catch (e) {
    toast.error('Failed to load unsubscribe list')
  } finally {
    loading.value = false
  }
}

async function resubscribe(email) {
  resubscribing.value = email
  try {
    await api.delete(`/email-marketing/unsubscribes/${encodeURIComponent(email)}`)
    toast.success(`${email} has been resubscribed`)
    unsubscribes.value = unsubscribes.value.filter(u => u.unsubscribed_email !== email)
    total.value = Math.max(0, total.value - 1)
  } catch (e) {
    toast.error('Failed to resubscribe')
  } finally {
    resubscribing.value = null
  }
}

function nextPage() {
  if ((page.value + 1) * pageSize < total.value) {
    page.value++
    fetchUnsubscribes()
  }
}

function prevPage() {
  if (page.value > 0) {
    page.value--
    fetchUnsubscribes()
  }
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

function reasonLabel(reason) {
  const map = {
    too_frequent: 'Too many emails',
    not_relevant: 'Not relevant',
    never_subscribed: 'Never subscribed',
    other: 'Other'
  }
  return map[reason] || reason || '-'
}

onMounted(fetchUnsubscribes)
</script>

<template>
  <!-- Overlay -->
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl shadow-xl w-full max-w-2xl max-h-[80vh] flex flex-col overflow-hidden">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-xl text-red-500">unsubscribe</span>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Unsubscribed Recipients</h2>
          <span v-if="total" class="ml-2 px-2 py-0.5 text-xs rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400">
            {{ total }}
          </span>
        </div>
        <button @click="emit('close')" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
          <span class="material-symbols-rounded text-xl text-surface-500">close</span>
        </button>
      </div>

      <!-- Body -->
      <div class="flex-1 overflow-y-auto">
        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-16">
          <span class="material-symbols-rounded text-3xl text-primary-500 animate-spin">progress_activity</span>
        </div>

        <!-- Empty state -->
        <div v-else-if="unsubscribes.length === 0" class="flex flex-col items-center justify-center py-16 text-surface-400">
          <span class="material-symbols-rounded text-5xl mb-3">mark_email_read</span>
          <p class="text-sm">No one has unsubscribed yet.</p>
        </div>

        <!-- List -->
        <table v-else class="w-full text-sm">
          <thead class="sticky top-0 bg-surface-50 dark:bg-surface-800 text-left">
            <tr>
              <th class="px-6 py-3 font-medium text-surface-500">Email</th>
              <th class="px-6 py-3 font-medium text-surface-500">Reason</th>
              <th class="px-6 py-3 font-medium text-surface-500">Date</th>
              <th class="px-6 py-3 font-medium text-surface-500 text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in unsubscribes"
              :key="item.unsubscribed_email"
              class="border-t border-surface-100 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-800/50"
            >
              <td class="px-6 py-3 text-surface-900 dark:text-surface-100 font-medium">{{ item.unsubscribed_email }}</td>
              <td class="px-6 py-3 text-surface-500">{{ reasonLabel(item.reason) }}</td>
              <td class="px-6 py-3 text-surface-500">{{ formatDate(item.unsubscribed_at) }}</td>
              <td class="px-6 py-3 text-right">
                <button
                  @click="resubscribe(item.unsubscribed_email)"
                  :disabled="resubscribing === item.unsubscribed_email"
                  class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-medium bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors disabled:opacity-50"
                >
                  <span v-if="resubscribing === item.unsubscribed_email" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
                  <span v-else class="material-symbols-rounded text-sm">person_add</span>
                  Resubscribe
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Footer / Pagination -->
      <div v-if="total > pageSize" class="flex items-center justify-between px-6 py-3 border-t border-surface-200 dark:border-[rgb(var(--color-border))] text-xs text-surface-500">
        <span>Showing {{ page * pageSize + 1 }}–{{ Math.min((page + 1) * pageSize, total) }} of {{ total }}</span>
        <div class="flex gap-2">
          <button @click="prevPage" :disabled="page === 0" class="px-3 py-1 rounded-full bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-40 transition-colors">Prev</button>
          <button @click="nextPage" :disabled="(page + 1) * pageSize >= total" class="px-3 py-1 rounded-full bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-40 transition-colors">Next</button>
        </div>
      </div>
    </div>
  </div>
</template>
