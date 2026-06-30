<template>
  <div class="boardpro-card-emails">
    <div v-if="emails.length || awaitingCount > 0" class="flex items-center gap-2 mb-2">
      <span v-if="emails.length" class="text-xs text-surface-500 dark:text-surface-400">{{ emails.length }} linked</span>
      <span v-if="awaitingCount > 0" class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-0.5">
        <span class="material-symbols-rounded text-sm">schedule</span>
        {{ awaitingCount }} awaiting
      </span>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-4">
      <span class="material-symbols-rounded animate-spin text-gray-400">progress_activity</span>
    </div>

    <div v-else-if="emails.length === 0" class="text-xs text-gray-400 dark:text-gray-500 py-3 text-center">
      No emails linked to this card
    </div>

    <div v-else class="space-y-1.5 max-h-48 overflow-y-auto">
      <div
        v-for="email in emails"
        :key="email.id"
        class="flex items-start gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-700/50 transition-colors group"
      >
        <span
          class="material-symbols-rounded text-base mt-0.5 shrink-0"
          :class="replyStatusIcon(email.reply_status).color"
        >
          {{ replyStatusIcon(email.reply_status).icon }}
        </span>
        <div class="flex-1 min-w-0 cursor-pointer" @click="goToEmail(email)">
          <p class="text-xs font-medium text-gray-800 dark:text-gray-200 truncate hover:text-primary-500 transition-colors">
            {{ email.email_subject || 'No subject' }}
          </p>
          <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
            {{ email.email_from }} &middot; {{ formatDate(email.email_date) }}
          </p>
        </div>
        <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
          <select
            :value="email.reply_status"
            class="text-xs bg-transparent border border-gray-200 dark:border-gray-600 rounded px-1 py-0.5"
            @change="changeReplyStatus(email.id, $event.target.value)"
          >
            <option value="none">None</option>
            <option value="replied">Replied</option>
            <option value="awaiting">Awaiting</option>
            <option value="forwarded">Forwarded</option>
          </select>
          <button
            class="p-0.5 text-gray-400 hover:text-red-500 transition-colors"
            title="Unlink email"
            @click="handleUnlink(email.id)"
          >
            <span class="material-symbols-rounded text-sm">link_off</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useBoardProStore } from '../stores/boardPro'

const props = defineProps({
  cardId: { type: Number, required: true },
})

const router = useRouter()
const store = useBoardProStore()

const loading = computed(() => store.cardEmailsLoading)
const emails = computed(() => store.cardEmails[props.cardId] || [])
const awaitingCount = computed(() => emails.value.filter(e => e.reply_status === 'awaiting').length)

function replyStatusIcon(status) {
  switch (status) {
    case 'replied': return { icon: 'check_circle', color: 'text-green-500' }
    case 'awaiting': return { icon: 'schedule', color: 'text-amber-500' }
    case 'forwarded': return { icon: 'forward', color: 'text-blue-500' }
    default: return { icon: 'mail', color: 'text-gray-400' }
  }
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function goToEmail(email) {
  const folderPath = (email.email_folder || 'INBOX')
    .replace(/\./g, '/')
    .replace(/ /g, '_')
    .toLowerCase()
  router.push(`/email/${folderPath}/message/${email.email_uid}`)
}

async function changeReplyStatus(linkId, status) {
  try {
    await store.updateReplyStatus(linkId, status)
    await store.fetchCardEmails(props.cardId)
  } catch (e) { /* handled in store */ }
}

async function handleUnlink(linkId) {
  try {
    await store.unlinkEmail(props.cardId, linkId)
  } catch (e) { /* handled in store */ }
}

onMounted(() => {
  store.fetchCardEmails(props.cardId)
})
</script>

