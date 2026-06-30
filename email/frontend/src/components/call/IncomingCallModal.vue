<script setup>
import { computed } from 'vue'
import { useCallStore } from '@/stores/call'
import { useCallLauncher } from '@/composables/useCallLauncher'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const callStore = useCallStore()
const callLauncher = useCallLauncher()

function acceptCall() {
  callLauncher.acceptIncomingCall()
}

const callerName = computed(() => {
  return callStore.callerInfo?.name || callStore.callerInfo?.email?.split('@')[0] || 'Unknown'
})

const callerEmail = computed(() => {
  return callStore.callerInfo?.email || ''
})

const callTypeLabel = computed(() => {
  return callStore.callType === 'video' ? 'Video Call' : 'Voice Call'
})

const callTypeIcon = computed(() => {
  return callStore.callType === 'video' ? 'videocam' : 'call'
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/60 backdrop-blur-sm">
      <div class="bg-surface-900 rounded-3xl p-8 max-w-sm w-full mx-4 text-center shadow-2xl border border-surface-700/50">
        <!-- Caller avatar with pulse ring -->
        <div class="relative inline-flex mb-5">
          <div class="absolute inset-0 rounded-full bg-primary-500/30 animate-ping"></div>
          <div class="relative">
            <UserAvatar
              :email="callerEmail"
              :name="callerName"
              size="3xl"
            />
          </div>
        </div>
        
        <!-- Caller info -->
        <h3 class="text-white text-lg font-semibold mb-1">{{ callerName }}</h3>
        <p class="text-white/50 text-sm mb-1">{{ callStore.callerInfo?.email }}</p>
        <div class="flex items-center justify-center gap-1.5 text-white/40 text-sm mb-8">
          <span class="material-symbols-rounded text-base">{{ callTypeIcon }}</span>
          <span>Incoming {{ callTypeLabel }}</span>
        </div>
        
        <!-- Action buttons -->
        <div class="flex items-center justify-center gap-6">
          <!-- Decline -->
          <div class="flex flex-col items-center gap-2">
            <button
              @click="callStore.rejectCall()"
              class="w-16 h-16 rounded-full bg-red-500 hover:bg-red-600 text-white flex items-center justify-center transition-colors shadow-lg shadow-red-500/20"
            >
              <span class="material-symbols-rounded text-3xl">call_end</span>
            </button>
            <span class="text-xs text-white/50">Decline</span>
          </div>
          
          <!-- Accept -->
          <div class="flex flex-col items-center gap-2">
            <button
              @click="acceptCall"
              class="w-16 h-16 rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center justify-center transition-colors shadow-lg shadow-green-500/20"
            >
              <span class="material-symbols-rounded text-3xl">{{ callTypeIcon }}</span>
            </button>
            <span class="text-xs text-white/50">Accept</span>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
@keyframes ping {
  0% {
    transform: scale(1);
    opacity: 0.5;
  }
  75%, 100% {
    transform: scale(1.6);
    opacity: 0;
  }
}
.animate-ping {
  animation: ping 1.5s cubic-bezier(0, 0, 0.2, 1) infinite;
}
</style>

