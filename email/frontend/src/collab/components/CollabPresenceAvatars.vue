<template>
  <div class="collab-presence-avatars flex items-center gap-1">
    <!-- Visible avatars -->
    <div class="flex -space-x-1.5">
      <div
        v-for="(user, index) in visibleUsers"
        :key="user.clientId || user.email"
        class="collab-avatar relative group"
        :style="{ zIndex: visibleUsers.length - index }"
      >
        <!-- Avatar circle -->
        <div 
          class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-semibold cursor-pointer transition-all duration-150 hover:scale-110"
          :style="{ backgroundColor: user.color }"
          :class="{ 'ring-2 ring-primary-400 ring-offset-1': user.isYou }"
        >
          {{ getInitials(user) }}
        </div>
        
        <!-- Online indicator -->
        <div 
          class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full"
          :class="user.isYou ? 'bg-primary-500 ring-2 ring-white' : 'bg-green-500 ring-2 ring-white'"
        ></div>
        
        <!-- Tooltip - positioned to the LEFT -->
        <div class="avatar-tooltip">
          {{ user.isYou ? 'You' : (user.name || user.email) }}
          <div class="tooltip-arrow"></div>
        </div>
      </div>
    </div>
    
    <!-- Overflow indicator -->
    <div
      v-if="overflowCount > 0"
      class="w-8 h-8 rounded-full bg-surface-100 flex items-center justify-center text-xs font-semibold text-surface-600 cursor-pointer relative group hover:bg-surface-200 transition-colors shadow-sm"
      @click="showOverflowList = !showOverflowList"
    >
      +{{ overflowCount }}
      
      <!-- Overflow dropdown -->
      <Teleport to="body">
        <div 
          v-if="showOverflowList"
          class="fixed bg-white rounded-xl shadow-xl border border-surface-200 py-2 z-[9999] min-w-[200px]"
          :style="overflowDropdownStyle"
          @click.stop
        >
          <div class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase tracking-wide border-b border-surface-100 mb-1">
            More Collaborators
          </div>
          <div
            v-for="user in overflowUsers"
            :key="user.clientId || user.email"
            class="flex items-center gap-3 px-3 py-2 hover:bg-surface-50 transition-colors"
          >
            <div 
              class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-semibold shadow-sm"
              :style="{ backgroundColor: user.color }"
            >
              {{ getInitials(user) }}
            </div>
            <span class="text-sm text-surface-700 truncate">
              {{ user.isYou ? 'You' : (user.name || user.email) }}
            </span>
            <span 
              v-if="user.isYou" 
              class="ml-auto text-xs text-primary-500 font-medium"
            >
              (you)
            </span>
          </div>
        </div>
      </Teleport>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  users: {
    type: Array,
    default: () => [],
  },
  maxVisible: {
    type: Number,
    default: 5,
  },
  currentUserEmail: {
    type: String,
    default: '',
  },
})

// State
const showOverflowList = ref(false)
const overflowButtonRef = ref(null)
const overflowDropdownStyle = ref({})

// Computed
const visibleUsers = computed(() => {
  return props.users.slice(0, props.maxVisible)
})

const overflowUsers = computed(() => {
  return props.users.slice(props.maxVisible)
})

const overflowCount = computed(() => {
  return Math.max(0, props.users.length - props.maxVisible)
})

// Get user initials
function getInitials(user) {
  const name = user.name || user.email || '?'
  const parts = name.split(/[\s@]/)
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
}

// Close overflow list on outside click
function handleClickOutside(e) {
  if (showOverflowList.value && !e.target.closest('.collab-presence-avatars')) {
    showOverflowList.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<style scoped>
.collab-avatar {
  position: relative;
}

/* Tooltip positioned to the LEFT of the avatar */
.avatar-tooltip {
  position: absolute;
  right: calc(100% + 8px);
  top: 50%;
  transform: translateY(-50%);
  padding: 6px 12px;
  background: #1f2937;
  color: white;
  font-size: 12px;
  font-weight: 500;
  border-radius: 8px;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease;
  z-index: 99999;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.collab-avatar:hover .avatar-tooltip {
  opacity: 1;
}

/* Arrow pointing right */
.tooltip-arrow {
  position: absolute;
  left: 100%;
  top: 50%;
  transform: translateY(-50%);
  border: 5px solid transparent;
  border-left-color: #1f2937;
}
</style>
