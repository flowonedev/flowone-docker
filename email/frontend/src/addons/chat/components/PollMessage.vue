<script setup>
import { ref, computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'

const props = defineProps({
  message: {
    type: Object,
    required: true
  },
  currentUserId: {
    type: Number,
    default: null
  }
})

const chatStore = useChatStore()

// Parse poll data from message content
// Format: [poll:"Question"|"Option 1"|"Option 2"|"Option 3"]
// Votes stored as JSON in message metadata or a separate field
const pollData = computed(() => {
  const content = props.message.content || ''
  const match = content.match(/^\[poll:(.+)\]$/)
  if (!match) return null

  const parts = match[1].split('|').map(p => p.replace(/^"|"$/g, '').trim())
  if (parts.length < 3) return null

  return {
    question: parts[0],
    options: parts.slice(1)
  }
})

// Parse votes from message metadata (stored as JSON)
const votes = computed(() => {
  try {
    return JSON.parse(props.message.poll_votes || '{}')
  } catch {
    return {}
  }
})

const totalVotes = computed(() => {
  return Object.values(votes.value).reduce((sum, voters) => sum + (voters?.length || 0), 0)
})

const hasVoted = computed(() => {
  if (!props.currentUserId) return false
  return Object.values(votes.value).some(voters => voters?.includes(props.currentUserId))
})

const myVote = computed(() => {
  if (!props.currentUserId) return null
  for (const [option, voters] of Object.entries(votes.value)) {
    if (voters?.includes(props.currentUserId)) return option
  }
  return null
})

function getVoteCount(optionIndex) {
  return votes.value[optionIndex]?.length || 0
}

function getPercentage(optionIndex) {
  if (totalVotes.value === 0) return 0
  return Math.round((getVoteCount(optionIndex) / totalVotes.value) * 100)
}

async function vote(optionIndex) {
  // Vote via the chat API by sending a special message
  // In a production app, this would be a dedicated endpoint
  try {
    await chatStore.sendMessage(
      props.message.conversation_id,
      `[poll_vote:${props.message.id}:${optionIndex}]`
    )
  } catch (e) {
    console.error('Failed to vote:', e)
  }
}
</script>

<template>
  <div v-if="pollData" class="mt-2 max-w-md">
    <div class="bg-surface-50 dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <!-- Poll header -->
      <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-2 mb-1">
          <span class="material-symbols-rounded text-lg text-primary-500">ballot</span>
          <span class="text-xs font-medium text-primary-500 uppercase tracking-wide">Poll</span>
        </div>
        <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">
          {{ pollData.question }}
        </h4>
      </div>

      <!-- Options -->
      <div class="p-3 space-y-2">
        <button
          v-for="(option, index) in pollData.options"
          :key="index"
          @click="vote(index)"
          :disabled="hasVoted"
          :class="[
            'w-full relative text-left rounded-lg overflow-hidden transition-colors',
            hasVoted ? 'cursor-default' : 'cursor-pointer hover:ring-2 hover:ring-primary-300 dark:hover:ring-primary-500/50'
          ]"
        >
          <!-- Background bar showing vote percentage -->
          <div class="absolute inset-0 rounded-lg">
            <div 
              :class="[
                'h-full rounded-lg transition-all duration-500',
                myVote == index ? 'bg-primary-100 dark:bg-primary-500/20' : 'bg-surface-100 dark:bg-surface-700'
              ]"
              :style="{ width: hasVoted ? getPercentage(index) + '%' : '0%' }"
            ></div>
          </div>

          <!-- Content -->
          <div class="relative px-4 py-2.5 flex items-center justify-between border border-surface-200 dark:border-surface-600 rounded-lg">
            <div class="flex items-center gap-2">
              <span v-if="myVote == index" class="material-symbols-rounded text-lg text-primary-500">check_circle</span>
              <span class="text-sm text-surface-900 dark:text-surface-100">{{ option }}</span>
            </div>
            <span v-if="hasVoted" class="text-xs font-medium text-surface-500">
              {{ getPercentage(index) }}%
            </span>
          </div>
        </button>
      </div>

      <!-- Footer -->
      <div class="px-4 py-2 border-t border-surface-200 dark:border-surface-700">
        <span class="text-xs text-surface-400">
          {{ totalVotes }} {{ totalVotes === 1 ? 'vote' : 'votes' }}
        </span>
      </div>
    </div>
  </div>
</template>

