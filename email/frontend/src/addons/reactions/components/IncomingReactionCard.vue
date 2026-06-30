<script setup>
import { computed } from 'vue'

const props = defineProps({
  // The reaction emoji detected
  emoji: {
    type: String,
    required: true
  },
  // Who sent the reaction (from field)
  from: {
    type: Array,
    default: () => []
  },
  // Original subject (may contain "Re:" prefix)
  subject: {
    type: String,
    default: ''
  },
  // Confidence level: 'high' or 'medium'
  confidence: {
    type: String,
    default: 'high'
  },
  // Message date
  date: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['view-original', 'dismiss'])

// Get reactor name
const reactorName = computed(() => {
  if (props.from && props.from.length > 0) {
    return props.from[0].name || props.from[0].email || 'Someone'
  }
  return 'Someone'
})

// Get reactor email
const reactorEmail = computed(() => {
  if (props.from && props.from.length > 0) {
    return props.from[0].email || ''
  }
  return ''
})

// Clean subject (remove Re: prefix and the emoji if present)
const cleanSubject = computed(() => {
  let subject = props.subject || ''
  // Remove Re:/Fwd: prefixes
  subject = subject.replace(/^(Re:|Fwd?:|Fw:)\s*/gi, '')
  // Remove leading emoji if it matches the reaction emoji
  if (props.emoji && subject.startsWith(props.emoji)) {
    subject = subject.substring(props.emoji.length).trim()
  }
  // Remove common reaction text patterns (language agnostic - just trim excess)
  subject = subject.replace(/^\s*[-:]\s*/, '')
  return subject.trim() || 'your message'
})

// Format date nicely
const formattedDate = computed(() => {
  if (!props.date) return ''
  try {
    const date = new Date(props.date)
    const now = new Date()
    const diffMs = now - date
    const diffMins = Math.floor(diffMs / 60000)
    const diffHours = Math.floor(diffMs / 3600000)
    const diffDays = Math.floor(diffMs / 86400000)
    
    if (diffMins < 1) return 'just now'
    if (diffMins < 60) return `${diffMins}m ago`
    if (diffHours < 24) return `${diffHours}h ago`
    if (diffDays < 7) return `${diffDays}d ago`
    
    return date.toLocaleDateString()
  } catch {
    return props.date
  }
})

// Avatar initials
const initials = computed(() => {
  const name = reactorName.value
  if (!name) return '?'
  const parts = name.split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
})

// Avatar background color based on email
const avatarColor = computed(() => {
  const email = reactorEmail.value
  if (!email) return 'bg-primary-500'
  
  // Generate consistent color from email
  let hash = 0
  for (let i = 0; i < email.length; i++) {
    hash = email.charCodeAt(i) + ((hash << 5) - hash)
  }
  
  const colors = [
    'bg-blue-500', 'bg-green-500', 'bg-purple-500', 'bg-pink-500',
    'bg-indigo-500', 'bg-teal-500', 'bg-orange-500', 'bg-cyan-500'
  ]
  
  return colors[Math.abs(hash) % colors.length]
})
</script>

<template>
  <div class="incoming-reaction-card bg-gradient-to-br from-surface-50 to-surface-100 dark:from-surface-800 dark:to-surface-750 rounded-xl border border-surface-200 dark:border-surface-700 p-6 w-full">
    <!-- Main reaction display -->
    <div class="flex items-center gap-4">
      <!-- Large emoji -->
      <div class="reaction-emoji text-5xl flex-shrink-0 animate-bounce-subtle">
        {{ emoji }}
      </div>
      
      <!-- Reaction info -->
      <div class="flex-1 min-w-0">
        <!-- Reactor with avatar -->
        <div class="flex items-center gap-2 mb-1">
          <div :class="[avatarColor, 'w-6 h-6 rounded-full flex items-center justify-center text-white text-xs font-medium']">
            {{ initials }}
          </div>
          <span class="font-semibold text-surface-800 dark:text-surface-100 truncate">
            {{ reactorName }}
          </span>
        </div>
        
        <!-- Action text -->
        <p class="text-surface-600 dark:text-surface-400 text-sm">
          reacted to
          <span class="text-surface-700 dark:text-surface-300 font-medium truncate inline-block max-w-[200px] align-bottom" :title="cleanSubject">
            "{{ cleanSubject }}"
          </span>
        </p>
        
        <!-- Time -->
        <p class="text-surface-400 dark:text-surface-500 text-xs mt-1">
          {{ formattedDate }}
        </p>
      </div>
    </div>
    
    <!-- Confidence indicator for medium confidence -->
    <div v-if="confidence === 'medium'" class="mt-4 flex items-center gap-2 text-xs text-surface-500 dark:text-surface-400 bg-surface-100 dark:bg-surface-700/50 rounded-lg px-3 py-2">
      <span class="material-symbols-rounded text-sm">info</span>
      <span>This looks like a reaction email</span>
    </div>
  </div>
</template>

<style scoped>
@keyframes bounce-subtle {
  0%, 100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-4px);
  }
}

.animate-bounce-subtle {
  animation: bounce-subtle 2s ease-in-out infinite;
}

.incoming-reaction-card {
  box-shadow: 
    0 4px 6px -1px rgba(0, 0, 0, 0.05),
    0 2px 4px -1px rgba(0, 0, 0, 0.03);
}
</style>


