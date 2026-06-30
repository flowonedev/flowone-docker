<script setup>
/**
 * UserAvatar - Reusable avatar component
 * Shows profile image if available, falls back to colored initials.
 * 
 * Usage:
 *   <UserAvatar :colleague="colleague" size="sm" />
 *   <UserAvatar :email="userEmail" :name="userName" size="md" />
 *   <UserAvatar :avatarUrl="directUrl" :name="name" size="lg" />
 */
import { computed } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const props = defineProps({
  // Pass a full colleague object (preferred — auto-resolves avatar + initials + color)
  colleague: { type: Object, default: null },
  // OR pass email to look up colleague from store
  email: { type: String, default: '' },
  // Manual overrides
  name: { type: String, default: '' },
  avatarUrl: { type: String, default: '' },
  avatarPath: { type: String, default: '' },
  // Size presets: 'xs' (20px), 'sm' (28px), 'md' (32px), 'lg' (40px), 'xl' (48px), '2xl' (56px), '3xl' (80px)
  size: { type: String, default: 'md' },
  // Show online presence dot
  showPresence: { type: Boolean, default: false },
})

const colleaguesStore = useColleaguesStore()
const authStore = useAuthStore()

// Resolve the colleague either from prop or store lookup
const resolvedColleague = computed(() => {
  if (props.colleague) return props.colleague
  if (props.email) {
    return colleaguesStore.colleagueByEmail?.[props.email.toLowerCase()] || null
  }
  return null
})

// Prefix relative /api/ URLs with the correct base for Capacitor/Electron
function resolveApiUrl(url) {
  if (!url) return url
  if (url.startsWith('/api/')) {
    const base = (api.defaults.baseURL || '/api').replace(/\/api\/?$/, '')
    return base + url
  }
  return url
}

// Resolve avatar URL
const resolvedAvatarUrl = computed(() => {
  // Direct URL takes priority
  if (props.avatarUrl) return resolveApiUrl(props.avatarUrl)
  // Then avatar_path override
  if (props.avatarPath) {
    const filename = props.avatarPath.split('/').pop()
    const base = api.defaults.baseURL || '/api'
    return `${base}/colleagues/avatar/${filename}`
  }
  // Then colleague lookup
  if (resolvedColleague.value) {
    const url = colleaguesStore.getAvatarUrl(resolvedColleague.value)
    if (url) return url
  }
  // Fallback: auth user's own avatar_url (from bootstrap) when email matches current user
  if (props.email && authStore.userEmail) {
    if (props.email.toLowerCase() === authStore.userEmail.toLowerCase()) {
      return resolveApiUrl(authStore.user?.avatar_url) || null
    }
  }
  return null
})

// Resolve display name for initials
const displayName = computed(() => {
  if (props.name) return props.name
  if (resolvedColleague.value) {
    return resolvedColleague.value.display_name || resolvedColleague.value.email?.split('@')[0] || ''
  }
  if (props.email) return props.email.split('@')[0]
  return ''
})

// Get initials
const initials = computed(() => {
  if (resolvedColleague.value) {
    return colleaguesStore.getInitials(resolvedColleague.value)
  }
  const name = displayName.value
  if (!name) return '??'
  const parts = name.split(/[\s._-]+/)
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
})

// Generate consistent background color from email/name
const bgColor = computed(() => {
  const str = props.email || resolvedColleague.value?.email || displayName.value || ''
  const hash = str.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0)
  const hue = hash % 360
  return `hsl(${hue}, 55%, 50%)`
})

// Size classes
const sizeClasses = computed(() => {
  const sizes = {
    'xs': 'w-5 h-5 text-[9px]',
    'sm': 'w-7 h-7 text-xs',
    'md': 'w-8 h-8 text-xs',
    'lg': 'w-10 h-10 text-sm',
    'xl': 'w-12 h-12 text-base',
    '2xl': 'w-14 h-14 text-lg',
    '3xl': 'w-20 h-20 text-2xl',
  }
  return sizes[props.size] || sizes.md
})

// Presence dot size
const presenceDotClass = computed(() => {
  const dots = {
    'xs': 'w-1.5 h-1.5 border',
    'sm': 'w-2 h-2 border',
    'md': 'w-2.5 h-2.5 border-2',
    'lg': 'w-3 h-3 border-2',
    'xl': 'w-3.5 h-3.5 border-2',
    '2xl': 'w-3.5 h-3.5 border-2',
    '3xl': 'w-4 h-4 border-2',
  }
  return dots[props.size] || dots.md
})

// Presence status
const presenceStatus = computed(() => {
  if (!props.showPresence) return null
  const email = props.email || resolvedColleague.value?.email || ''
  if (!email) return null
  return colleaguesStore.getColleagueStatus(email)
})

const presenceColor = computed(() => {
  const status = presenceStatus.value
  if (!status || status === 'offline') return 'bg-surface-400'
  if (status === 'active') return 'bg-green-500'
  if (status === 'away') return 'bg-amber-500'
  if (status === 'do_not_disturb') return 'bg-red-500'
  return 'bg-surface-400'
})

// Handle image load error - fallback to initials
function onImageError(e) {
  e.target.style.display = 'none'
  e.target.nextElementSibling?.style && (e.target.nextElementSibling.style.display = 'flex')
}
</script>

<template>
  <div
    class="relative rounded-full flex-shrink-0"
    :class="sizeClasses"
  >
    <div
      class="w-full h-full rounded-full flex items-center justify-center font-semibold text-white overflow-hidden"
      :style="{ backgroundColor: resolvedAvatarUrl ? 'transparent' : bgColor }"
    >
      <!-- Avatar Image -->
      <img
        v-if="resolvedAvatarUrl"
        :src="resolvedAvatarUrl"
        :alt="displayName"
        class="w-full h-full rounded-full object-cover"
        loading="lazy"
        @error="onImageError"
      />
      <!-- Initials fallback (shown when no avatar or image fails to load) -->
      <span
        v-if="!resolvedAvatarUrl"
        class="select-none"
      >{{ initials }}</span>
      <!-- Hidden initials fallback for image error -->
      <span
        v-if="resolvedAvatarUrl"
        class="absolute inset-0 rounded-full items-center justify-center font-semibold text-white select-none"
        :style="{ backgroundColor: bgColor, display: 'none' }"
      >{{ initials }}</span>
    </div>
    
    <!-- Presence indicator dot -->
    <div
      v-if="showPresence && presenceStatus && presenceStatus !== 'offline'"
      class="absolute bottom-0 right-0 rounded-full border-white dark:border-surface-800"
      :class="[presenceDotClass, presenceColor]"
    ></div>
  </div>
</template>

