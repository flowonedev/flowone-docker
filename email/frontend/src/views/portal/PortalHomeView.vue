<script setup>
/**
 * PortalHomeView - Dashboard/overview for portal client
 * Shows unread updates, pending documents, active calls at a glance.
 */
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { usePortalStore } from '@/stores/portal'
import portalApi from '@/services/portalApi'

const router = useRouter()
const portal = usePortalStore()
const { locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))
const recentUpdates = ref([])
const pendingDocs = ref([])
const activeCalls = ref([])
const loading = ref(true)
const firstName = computed(() => {
  const name = portal.user?.name
  return name ? name.split(' ')[0] : ''
})

onMounted(async () => {
  try {
    const [updatesRes, docsRes, callsRes] = await Promise.allSettled([
      portalApi.get('/portal/updates', { params: { per_page: 5 } }),
      portalApi.get('/portal/documents'),
      portalApi.get('/portal/calls'),
    ])

    if (updatesRes.status === 'fulfilled') {
      recentUpdates.value = updatesRes.value.data?.data?.updates || []
    }
    if (docsRes.status === 'fulfilled') {
      pendingDocs.value = (docsRes.value.data?.data?.documents || []).filter(d => d.my_signer_status === 'pending')
    }
    if (callsRes.status === 'fulfilled') {
      activeCalls.value = callsRes.value.data?.data?.calls || []
    }
  } catch (e) {
    console.error('Portal home load error:', e)
  } finally {
    loading.value = false
  }
})

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString(localeTag.value, { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <div class="space-y-6">
    <!-- Welcome -->
    <div>
      <h2 class="text-2xl font-bold text-surface-900 dark:text-white">
        {{ firstName ? $t('portalHomeView.welcomeName', { name: firstName }) : $t('portalHomeView.welcome') }}
      </h2>
      <p class="text-surface-500 mt-1">{{ $t('portalHomeView.heresAnOverviewOfYour') }}</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">campaign</span>
          </div>
          <div>
            <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ portal.user?.unread_updates || 0 }}</p>
            <p class="text-xs text-surface-500">{{ $t('portalHomeView.unreadUpdates') }}</p>
          </div>
        </div>
      </div>
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">draw</span>
          </div>
          <div>
            <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ portal.user?.pending_documents || 0 }}</p>
            <p class="text-xs text-surface-500">{{ $t('portalHomeView.documentsToSign') }}</p>
          </div>
        </div>
      </div>
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-green-600 dark:text-green-400">videocam</span>
          </div>
          <div>
            <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ portal.user?.active_calls || 0 }}</p>
            <p class="text-xs text-surface-500">{{ $t('portalHomeView.activeCalls') }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Active Calls Banner -->
    <div v-if="activeCalls.length > 0" 
         class="bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30 rounded-xl p-4">
      <div v-for="call in activeCalls" :key="call.id" class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-green-600 dark:text-green-400 animate-pulse">call</span>
          <span class="text-sm font-medium text-green-800 dark:text-green-200">
            {{ call.status === 'active' ? $t('portalHomeView.callInProgress') : $t('portalHomeView.callWaitingForYou') }}
          </span>
        </div>
        <button 
          @click="router.push({ name: 'portal-call-room', params: { callId: call.id } })"
          class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors"
        >
          {{ $t('portalHomeView.joinCall') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Recent Updates -->
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="font-semibold text-surface-900 dark:text-white">{{ $t('portalHomeView.recentUpdates') }}</h3>
          <button @click="router.push({ name: 'portal-updates' })" class="text-sm text-primary-600 dark:text-primary-400 hover:underline">
            {{ $t('portalHomeView.viewAll') }}
          </button>
        </div>
        <div v-if="loading" class="p-8 text-center text-surface-400">
          <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
        </div>
        <div v-else-if="recentUpdates.length === 0" class="p-8 text-center text-surface-400 text-sm">
          {{ $t('portalUpdatesView.noUpdatesYet') }}
        </div>
        <div v-else class="divide-y divide-surface-100 dark:divide-surface-700">
          <button 
            v-for="update in recentUpdates" 
            :key="update.id"
            @click="router.push({ name: 'portal-update', params: { id: update.id } })"
            class="w-full text-left px-5 py-3.5 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors flex items-start gap-3"
          >
            <div class="w-2 h-2 rounded-full mt-2 shrink-0" 
                 :class="update.read_at ? 'bg-surface-300 dark:bg-surface-600' : 'bg-primary-500'"></div>
            <div class="min-w-0">
              <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ update.title }}</p>
              <p class="text-xs text-surface-500 mt-0.5">{{ formatDate(update.created_at) }}</p>
            </div>
          </button>
        </div>
      </div>

      <!-- Pending Documents -->
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="font-semibold text-surface-900 dark:text-white">{{ $t('portalHomeView.documentsAwaitingSignature') }}</h3>
          <button @click="router.push({ name: 'portal-documents' })" class="text-sm text-primary-600 dark:text-primary-400 hover:underline">
            {{ $t('portalHomeView.viewAll') }}
          </button>
        </div>
        <div v-if="loading" class="p-8 text-center text-surface-400">
          <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
        </div>
        <div v-else-if="pendingDocs.length === 0" class="p-8 text-center text-surface-400 text-sm">
          {{ $t('portalHomeView.noDocumentsAwaitingSignature') }}
        </div>
        <div v-else class="divide-y divide-surface-100 dark:divide-surface-700">
          <button 
            v-for="doc in pendingDocs" 
            :key="doc.id"
            @click="router.push({ name: 'portal-document', params: { docId: doc.id } })"
            class="w-full text-left px-5 py-3.5 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors"
          >
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-xl text-amber-500">draw</span>
              <div class="min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ doc.title }}</p>
                <p class="text-xs text-surface-500 mt-0.5">
                  {{ doc.document_type }} · {{ formatDate(doc.created_at) }}
                  <span v-if="doc.signing_deadline" class="text-amber-600 dark:text-amber-400"> · {{ $t('portalHomeView.dueDate', { date: formatDate(doc.signing_deadline) }) }}</span>
                </p>
              </div>
            </div>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

