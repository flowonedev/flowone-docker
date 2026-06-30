<script setup>
/**
 * PortalCallRoom - Video call room for portal clients.
 *
 * Thin wrapper: joins the call via portal session auth to get LiveKit credentials,
 * then hands off to the reusable VideoCallRoom component.
 */
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import portalApi from '@/services/portalApi'
import VideoCallRoom from '@/components/call/VideoCallRoom.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const callId = ref(route.params.callId)
const status = ref('loading') // loading | ready | error | ended
const error = ref('')

const livekitToken = ref('')
const wsUrl = ref('')
const participantName = ref('')

onMounted(async () => {
  await joinCall()
})

async function joinCall() {
  try {
    const res = await portalApi.post(`/portal/calls/${callId.value}/join`)
    if (res.data?.success) {
      const data = res.data.data
      livekitToken.value = data.token
      wsUrl.value = data.livekit_url
      participantName.value = data.participant_name || ''
      status.value = 'ready'
    } else {
      error.value = res.data?.message || t('portalCallRoom.failedToJoinCall')
      status.value = 'error'
    }
  } catch (e) {
    error.value = e.response?.data?.message || t('portalCallRoom.failedToJoinCall')
    status.value = 'error'
  }
}

function handleEnded() {
  status.value = 'ended'
}

function backToCalls() {
  router.push({ name: 'portal-calls' })
}
</script>

<template>
  <div class="min-h-[80vh] flex flex-col">
    <!-- Loading -->
    <div v-if="status === 'loading'" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin w-10 h-10 border-3 border-primary-500 border-t-transparent rounded-full mx-auto mb-4"></div>
        <p class="text-surface-500">{{ $t('portalCallRoom.joiningCall') }}</p>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="status === 'error'" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-5xl text-red-400 mb-2">call_end</span>
        <p class="text-surface-600 dark:text-surface-300 mb-4">
          {{ typeof error === 'string' && error.startsWith('portalCallRoom.') ? $t(error) : error }}
        </p>
        <button @click="backToCalls" 
                class="px-6 py-2.5 rounded-xl bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-200 text-sm font-medium hover:bg-surface-200 dark:hover:bg-surface-600">
          {{ $t('portalCallRoom.backToCalls') }}
        </button>
      </div>
    </div>

    <!-- Call Ended -->
    <div v-else-if="status === 'ended'" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <span class="material-symbols-rounded text-5xl text-surface-400 mb-2">call_end</span>
        <h2 class="text-xl font-semibold text-surface-800 dark:text-white mb-2">{{ $t('portalCallRoom.callEnded') }}</h2>
        <p class="text-surface-500 mb-4">{{ $t('portalCallRoom.thankYouForJoiningThe') }}</p>
        <button @click="backToCalls"
                class="px-6 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
          {{ $t('portalCallRoom.backToPortal') }}
        </button>
      </div>
    </div>

    <!-- Active Call via VideoCallRoom -->
    <VideoCallRoom
      v-else-if="status === 'ready'"
      :livekit-token="livekitToken"
      :ws-url="wsUrl"
      :participant-name="participantName"
      :is-admin="false"
      :show-pre-join="false"
      @ended="handleEnded"
      class="flex-1"
    />
  </div>
</template>
