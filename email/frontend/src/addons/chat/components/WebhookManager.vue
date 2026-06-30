<script setup>
import { ref, computed, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import api from '@/services/api'

const props = defineProps({
  conversationId: {
    type: Number,
    default: null
  }
})

const emit = defineEmits(['close'])

const chatStore = useChatStore()
const webhooks = ref([])
const loading = ref(false)
const creating = ref(false)
const copiedId = ref(null)

// New webhook form
const showCreateForm = ref(false)
const newName = ref('')
const newAvatarUrl = ref('')

onMounted(async () => {
  await fetchWebhooks()
})

async function fetchWebhooks() {
  loading.value = true
  try {
    const response = await api.get('/chat/webhooks')
    if (response.data.success) {
      let all = response.data.data?.webhooks || []
      // Filter by conversation if specified
      if (props.conversationId) {
        all = all.filter(w => w.conversation_id === props.conversationId)
      }
      webhooks.value = all
    }
  } catch (e) {
    console.error('Failed to fetch webhooks:', e)
  } finally {
    loading.value = false
  }
}

async function createWebhook() {
  if (!newName.value.trim() || !props.conversationId) return
  creating.value = true
  try {
    const response = await api.post('/chat/webhooks', {
      conversation_id: props.conversationId,
      name: newName.value.trim(),
      avatar_url: newAvatarUrl.value.trim() || null
    })
    if (response.data.success) {
      webhooks.value.unshift(response.data.data.webhook)
      newName.value = ''
      newAvatarUrl.value = ''
      showCreateForm.value = false
    }
  } catch (e) {
    console.error('Failed to create webhook:', e)
  } finally {
    creating.value = false
  }
}

async function deleteWebhook(webhookId) {
  if (!confirm('Delete this webhook? The URL will stop working immediately.')) return
  try {
    const response = await api.delete(`/chat/webhooks/${webhookId}`)
    if (response.data.success) {
      webhooks.value = webhooks.value.filter(w => w.id !== webhookId)
    }
  } catch (e) {
    console.error('Failed to delete webhook:', e)
  }
}

function copyUrl(webhook) {
  navigator.clipboard.writeText(webhook.webhook_url)
  copiedId.value = webhook.id
  setTimeout(() => { copiedId.value = null }, 2000)
}

function formatDate(dateStr) {
  if (!dateStr) return 'Never'
  return new Date(dateStr).toLocaleDateString([], { 
    month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' 
  })
}
</script>

<template>
  <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg max-h-[80vh] flex flex-col">
    <!-- Header -->
    <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between flex-shrink-0">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-xl text-primary-500">webhook</span>
        <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Incoming Webhooks</h2>
      </div>
      <button @click="$emit('close')" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors">
        <span class="material-symbols-rounded text-xl text-surface-400">close</span>
      </button>
    </div>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 space-y-3">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-8">
        <span class="material-symbols-rounded text-3xl text-surface-300 animate-spin">progress_activity</span>
      </div>

      <!-- Webhook list -->
      <template v-else>
        <div
          v-for="webhook in webhooks"
          :key="webhook.id"
          class="p-4 bg-surface-50 dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-700"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-rounded text-xl text-indigo-500">webhook</span>
              </div>
              <div class="min-w-0">
                <p class="font-medium text-surface-900 dark:text-surface-100 truncate">{{ webhook.name }}</p>
                <p class="text-xs text-surface-500">
                  Created by {{ webhook.creator_name || webhook.creator_email }}
                </p>
              </div>
            </div>
            <button
              @click="deleteWebhook(webhook.id)"
              class="p-1.5 hover:bg-red-100 dark:hover:bg-red-500/20 rounded-lg transition-colors flex-shrink-0"
              title="Delete webhook"
            >
              <span class="material-symbols-rounded text-lg text-red-500">delete</span>
            </button>
          </div>

          <!-- URL -->
          <div class="mt-3 flex items-center gap-2">
            <input
              :value="webhook.webhook_url"
              readonly
              class="flex-1 px-3 py-1.5 text-xs font-mono bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-600 dark:text-surface-400 truncate"
            />
            <button
              @click="copyUrl(webhook)"
              class="px-3 py-1.5 text-xs font-medium rounded-full transition-colors flex items-center gap-1"
              :class="copiedId === webhook.id 
                ? 'bg-green-100 dark:bg-green-500/20 text-green-600' 
                : 'bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-600'"
            >
              <span class="material-symbols-rounded text-sm">
                {{ copiedId === webhook.id ? 'check' : 'content_copy' }}
              </span>
              {{ copiedId === webhook.id ? 'Copied' : 'Copy' }}
            </button>
          </div>

          <!-- Meta -->
          <div class="mt-2 flex items-center gap-4 text-xs text-surface-400">
            <span>Last used: {{ formatDate(webhook.last_used_at) }}</span>
            <span :class="webhook.is_active ? 'text-green-500' : 'text-red-500'">
              {{ webhook.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
        </div>

        <!-- Empty state -->
        <div v-if="!webhooks.length && !showCreateForm" class="text-center py-8 text-surface-400">
          <span class="material-symbols-rounded text-4xl mb-2 block">webhook</span>
          <p class="text-sm">No webhooks yet</p>
          <p class="text-xs mt-1">Create one to receive messages from external services</p>
        </div>
      </template>

      <!-- Create form -->
      <div v-if="showCreateForm" class="p-4 bg-primary-50 dark:bg-primary-500/10 rounded-xl border border-primary-200 dark:border-primary-500/30">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-3">New Webhook</h3>
        <div class="space-y-3">
          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400 mb-1 block">Name</label>
            <input
              v-model="newName"
              type="text"
              placeholder="e.g. GitHub, Jenkins, Sentry..."
              class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none text-surface-900 dark:text-surface-100"
              maxlength="100"
            />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400 mb-1 block">Avatar URL (optional)</label>
            <input
              v-model="newAvatarUrl"
              type="url"
              placeholder="https://example.com/icon.png"
              class="w-full px-3 py-2 text-sm bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none text-surface-900 dark:text-surface-100"
            />
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="createWebhook"
              :disabled="!newName.trim() || creating"
              class="px-4 py-2 text-sm font-medium bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors disabled:opacity-40"
            >
              {{ creating ? 'Creating...' : 'Create Webhook' }}
            </button>
            <button
              @click="showCreateForm = false"
              class="px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
            >
              Cancel
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="px-5 py-3 border-t border-surface-200 dark:border-surface-700 flex-shrink-0">
      <button
        v-if="!showCreateForm && conversationId"
        @click="showCreateForm = true"
        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors"
      >
        <span class="material-symbols-rounded text-lg">add</span>
        Add Webhook
      </button>
      <p v-if="!conversationId" class="text-xs text-surface-400 text-center">
        Select a conversation to manage its webhooks
      </p>
    </div>
  </div>
</template>

