<script setup>
import { computed } from 'vue'
import { useHuddleStore } from '@/stores/huddle'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const props = defineProps({
  conversationId: { type: Number, required: true },
})

const huddleStore = useHuddleStore()

const huddleInfo = computed(() => {
  return huddleStore.conversationActiveHuddles[props.conversationId]
})

const participants = computed(() => {
  return huddleInfo.value?.participants || []
})

const isCurrentUserInHuddle = computed(() => {
  return huddleStore.isInHuddle && huddleStore.conversationId === props.conversationId
})

function isSpeaking(email) {
  return huddleStore.speakingParticipants?.has?.(email?.toLowerCase())
}

function handleJoin() {
  huddleStore.joinHuddle(props.conversationId)
}
</script>

<template>
  <div v-if="huddleInfo" class="pl-10 pr-4 pb-1">
    <div class="bg-green-50/60 dark:bg-green-500/5 rounded-lg px-2.5 py-1.5 border border-green-200/50 dark:border-green-500/15">
      <!-- Participants -->
      <div class="space-y-1">
        <div
          v-for="p in participants.slice(0, 6)"
          :key="p.id || p.email"
          class="flex items-center gap-2"
        >
          <div
            :class="[
              'rounded-full transition-all flex-shrink-0',
              isSpeaking(p.email) ? 'ring-2 ring-green-500 ring-offset-1 dark:ring-offset-transparent' : ''
            ]"
          >
            <UserAvatar
              :colleague="p"
              :email="p.email"
              :name="p.display_name"
              :avatar-path="p.avatar_path || ''"
              size="xs"
            />
          </div>
          <span class="text-[11px] text-green-700 dark:text-green-400 truncate">
            {{ p.display_name || p.email?.split('@')[0] }}
          </span>
          <span
            v-if="isSpeaking(p.email)"
            class="material-symbols-rounded text-[10px] text-green-500 flex-shrink-0"
          >graphic_eq</span>
        </div>
        <div v-if="participants.length > 6" class="text-[10px] text-green-600 dark:text-green-400 pl-6">
          +{{ participants.length - 6 }} more
        </div>
      </div>

      <!-- Join button -->
      <button
        v-if="!isCurrentUserInHuddle"
        @click.stop="handleJoin"
        :disabled="huddleStore.loading"
        class="mt-1.5 w-full px-3 py-1 text-[11px] font-medium bg-green-500 text-white rounded-full hover:bg-green-600 transition-colors disabled:opacity-50 flex items-center justify-center gap-1"
      >
        <span class="material-symbols-rounded text-xs">headset_mic</span>
        {{ huddleStore.loading ? 'Joining...' : 'Join Huddle' }}
      </button>
    </div>
  </div>
</template>
