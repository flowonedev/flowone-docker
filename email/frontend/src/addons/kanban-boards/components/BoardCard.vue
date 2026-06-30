<script setup>
import { computed } from 'vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import { useThemeStore } from '@/stores/theme'

const props = defineProps({
  card: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['open-menu'])
const themeStore = useThemeStore()

// Computed
const hasLabels = computed(() => props.card.labels && props.card.labels.length > 0)
const hasStartDate = computed(() => !!props.card.start_date)
const hasDueDate = computed(() => !!props.card.due_date)
const hasChecklist = computed(() => props.card.checklist_total > 0)
const hasAttachments = computed(() => props.card.attachment_count > 0)
const hasComments = computed(() => props.card.comment_count > 0)
const hasDescription = computed(() => !!props.card.description)
const hasCardColor = computed(() => !!props.card.card_color)
const hasRevenue = computed(() => props.card.estimated_revenue > 0)
const hasTimeInfo = computed(() => (props.card.time_estimate_seconds > 0) || (props.card.time_spent_seconds > 0))

function fmtTimePill(seconds) {
  if (!seconds || seconds <= 0) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.round((seconds % 3600) / 60)
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

const timeLabel = computed(() => {
  const tracked = props.card.time_spent_seconds || 0
  const estimate = props.card.time_estimate_seconds || 0
  if (tracked && estimate) return `${fmtTimePill(tracked)} / ${fmtTimePill(estimate)}`
  if (tracked) return fmtTimePill(tracked)
  if (estimate) return `est ${fmtTimePill(estimate)}`
  return ''
})

const overBudgetExcessPct = computed(() => {
  const tracked = props.card.time_spent_seconds || 0
  const estimate = props.card.time_estimate_seconds || 0
  if (estimate > 0 && tracked > estimate) return Math.round(((tracked - estimate) / estimate) * 100)
  return 0
})

const timeOverBudget = computed(() => {
  const est = props.card.time_estimate_seconds || 0
  const spent = props.card.time_spent_seconds || 0
  return est > 0 && spent > est
})

const timeWarning = computed(() => {
  const est = props.card.time_estimate_seconds || 0
  const spent = props.card.time_spent_seconds || 0
  return est > 0 && spent >= est * 0.9 && spent <= est
})

function formatRevenue(amount, currency = 'HUF') {
  if (!amount) return ''
  const num = Number(amount)
  if (currency === 'HUF') {
    return num >= 1000000 
      ? `${(num / 1000000).toFixed(1).replace(/\.0$/, '')}M` 
      : num >= 1000 
        ? `${Math.round(num / 1000)}K` 
        : `${num}`
  }
  return num >= 1000000 
    ? `${(num / 1000000).toFixed(1).replace(/\.0$/, '')}M` 
    : num >= 1000 
      ? `${(num / 1000).toFixed(1).replace(/\.0$/, '')}K` 
      : `${num}`
}

function hexToLuminance(hex) {
  const c = hex.replace('#', '')
  const r = parseInt(c.substr(0, 2), 16) / 255
  const g = parseInt(c.substr(2, 2), 16) / 255
  const b = parseInt(c.substr(4, 2), 16) / 255
  const toLinear = (v) => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  return 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b)
}

const textColorClass = computed(() => {
  if (!hasCardColor.value) return ''
  if (themeStore.isDark) return 'card-text-light'
  return hexToLuminance(props.card.card_color) > 0.4 ? 'card-text-dark' : 'card-text-light'
})

function mixWithBase(hex, baseR, baseG, baseB, ratio) {
  const h = hex.replace('#', '')
  const r = parseInt(h.substr(0, 2), 16) || 0
  const g = parseInt(h.substr(2, 2), 16) || 0
  const b = parseInt(h.substr(4, 2), 16) || 0
  const mr = Math.round(r * (1 - ratio) + baseR * ratio)
  const mg = Math.round(g * (1 - ratio) + baseG * ratio)
  const mb = Math.round(b * (1 - ratio) + baseB * ratio)
  return `rgb(${mr}, ${mg}, ${mb})`
}

const cardStyle = computed(() => {
  if (!hasCardColor.value) return {}
  if (themeStore.isDark) {
    return { backgroundColor: mixWithBase(props.card.card_color, 30, 32, 38, 0.55) }
  }
  return { backgroundColor: props.card.card_color }
})

const dueStatus = computed(() => {
  if (!props.card.due_date) return null
  
  const now = new Date()
  const due = new Date(props.card.due_date)
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const tomorrow = new Date(today)
  tomorrow.setDate(tomorrow.getDate() + 1)
  
  if (props.card.completed) {
    return { label: 'Complete', class: hasCardColor.value ? 'text-current' : 'text-green-600 dark:text-green-400' }
  }
  if (due < today) {
    return { label: 'Overdue', class: hasCardColor.value ? 'text-current' : 'text-red-500 dark:text-red-400' }
  }
  if (due < tomorrow) {
    return { label: 'Due today', class: hasCardColor.value ? 'text-current' : 'text-amber-600 dark:text-amber-400' }
  }
  return { label: formatDate(props.card.due_date), class: hasCardColor.value ? 'text-current' : 'text-surface-500' }
})

const checklistProgress = computed(() => {
  if (props.card.checklist_total === 0) return 0
  return Math.round((props.card.checklist_done / props.card.checklist_total) * 100)
})

function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div 
    class="rounded-lg shadow-sm hover:shadow-md cursor-pointer transition-shadow group relative"
    :class="[
      hasCardColor ? 'border border-black/10' : 'bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600',
      textColorClass
    ]"
    :style="cardStyle"
  >
    <!-- Quick edit button on hover -->
    <button 
      class="absolute top-1 right-1 p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity z-10"
      :class="hasCardColor ? 'bg-black/20 hover:bg-black/30' : 'bg-surface-100 dark:bg-surface-600 hover:bg-surface-200 dark:hover:bg-surface-500'"
      @click.stop="emit('open-menu', $event)"
      title="Quick actions"
    >
      <span class="material-symbols-rounded text-sm" :class="hasCardColor ? 'text-current' : 'text-surface-500 dark:text-surface-400'">more_horiz</span>
    </button>
    
    <!-- Cover color (only when NO card_color is set) -->
    <div 
      v-if="card.cover_color && !hasCardColor"
      class="h-8 rounded-t-lg"
      :style="{ backgroundColor: card.cover_color }"
    ></div>
    
    <div class="p-3">
      <!-- Labels (hidden when card has background color since they clash) -->
      <div v-if="hasLabels && !hasCardColor" class="flex flex-wrap gap-1 mb-2">
        <span
          v-for="label in card.labels"
          :key="label.id"
          class="h-2 w-10 rounded-full"
          :style="{ backgroundColor: label.color }"
          :title="label.name"
        ></span>
      </div>
      
      <!-- Title -->
      <h4 
        class="text-sm"
        :class="[
          hasCardColor ? 'text-current' : 'text-surface-900 dark:text-surface-100',
          { 'line-through': card.completed }
        ]"
      >
        {{ card.title }}
      </h4>
      
      <!-- Key info row: revenue, checklist, due date, time -->
      <div v-if="hasRevenue || hasChecklist || hasDueDate || hasTimeInfo" class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2">
        <span 
          v-if="hasRevenue"
          class="inline-flex items-center gap-1 text-xs font-semibold"
          :class="hasCardColor ? 'text-current' : 'text-emerald-600 dark:text-emerald-400'"
        >
          <span class="material-symbols-rounded text-sm">payments</span>
          {{ formatRevenue(card.estimated_revenue, card.financial_currency) }} {{ card.financial_currency || 'HUF' }}
        </span>

        <span 
          v-if="hasChecklist"
          class="inline-flex items-center gap-1 text-xs font-medium"
          :class="hasCardColor ? 'text-current' : (checklistProgress === 100 ? 'text-green-600 dark:text-green-400' : 'text-surface-500')"
        >
          <span class="material-symbols-rounded text-sm">checklist</span>
          {{ card.checklist_done }}/{{ card.checklist_total }}
        </span>

        <span 
          v-if="hasDueDate && dueStatus"
          class="inline-flex items-center gap-1 text-xs font-medium"
          :class="hasCardColor ? 'text-current' : dueStatus.class"
        >
          <span class="material-symbols-rounded text-sm">event_upcoming</span>
          {{ dueStatus.label }}
        </span>

        <span
          v-if="hasTimeInfo"
          class="inline-flex items-center gap-1 text-xs font-medium"
          :class="hasCardColor ? 'text-current' : (timeOverBudget ? 'text-red-600 dark:text-red-400 font-bold' : timeWarning ? 'text-amber-500 dark:text-amber-400' : 'text-blue-500 dark:text-blue-400')"
        >
          <span class="material-symbols-rounded text-sm">{{ timeOverBudget ? 'warning' : 'timer' }}</span>
          {{ timeLabel }}
          <span v-if="overBudgetExcessPct > 0" class="text-[10px] opacity-80">(+{{ overBudgetExcessPct }}%)</span>
        </span>
      </div>

      <!-- Secondary badges: description, attachments, comments -->
      <div v-if="hasStartDate || hasDescription || hasAttachments || hasComments" class="flex flex-wrap items-center gap-2 mt-1.5">
        <span 
          v-if="hasStartDate"
          class="inline-flex items-center gap-1 text-xs"
          :class="hasCardColor ? 'text-current' : 'text-surface-400'"
          :title="'Started ' + formatDate(card.start_date)"
        >
          <span class="material-symbols-rounded text-sm">event</span>
          {{ formatDate(card.start_date) }}
        </span>

        <span 
          v-if="hasDescription"
          :class="hasCardColor ? 'text-current' : 'text-surface-400'"
          title="Has description"
        >
          <span class="material-symbols-rounded text-sm">subject</span>
        </span>
        
        <span 
          v-if="hasAttachments"
          class="inline-flex items-center gap-1 text-xs"
          :class="hasCardColor ? 'text-current' : 'text-surface-500'"
        >
          <span class="material-symbols-rounded text-sm">attach_file</span>
          {{ card.attachment_count }}
        </span>
        
        <span 
          v-if="hasComments"
          class="inline-flex items-center gap-1 text-xs"
          :class="hasCardColor ? 'text-current' : 'text-surface-500'"
        >
          <span class="material-symbols-rounded text-sm">chat_bubble</span>
          {{ card.comment_count }}
        </span>
      </div>
      
      <!-- Assigned user -->
      <div v-if="card.assigned_to" class="mt-2 flex justify-end">
        <UserAvatar :email="card.assigned_to" size="xs" :title="card.assigned_to" :show-presence="true" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.card-text-dark {
  color: #000;
}
.card-text-light {
  color: #fff;
}
</style>

