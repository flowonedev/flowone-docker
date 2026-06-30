<script setup>
import { ref, computed, nextTick } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'

const props = defineProps({
  members: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  labels: { type: Array, default: () => [] },
})

const emit = defineEmits(['reload'])

const colleaguesStore = useColleaguesStore()

const groupBy = ref('member')
const datePreset = ref('week')
const today = new Date()
const startDate = ref(formatDate(getMonday(today)))
const endDate = ref(formatDate(getSunday(today)))

const selectedMember = ref(null)
const selectedGroupId = ref(null)
const selectedLabelId = ref(null)
const showFilterPanel = ref(false)
const filterBtnRef = ref(null)
const filterPanelStyle = ref({})

defineExpose({ startDate, endDate, selectedMember, selectedGroupId, selectedLabelId })

const statusBarColors = {
  assigned: 'bg-surface-400',
  working: 'bg-blue-500',
  review: 'bg-amber-500',
  done: 'bg-green-500',
  blocked: 'bg-red-500',
}

function getMonday(d) {
  const dt = new Date(d)
  const day = dt.getDay()
  const diff = dt.getDate() - day + (day === 0 ? -6 : 1)
  dt.setDate(diff)
  return dt
}

function getSunday(d) {
  const mon = getMonday(d)
  mon.setDate(mon.getDate() + 6)
  return mon
}

function formatDate(d) {
  return d.toISOString().split('T')[0]
}

function applyPreset(preset) {
  datePreset.value = preset
  const now = new Date()
  if (preset === 'today') {
    startDate.value = formatDate(now)
    endDate.value = formatDate(now)
  } else if (preset === 'week') {
    startDate.value = formatDate(getMonday(now))
    endDate.value = formatDate(getSunday(now))
  } else if (preset === 'next-week') {
    const next = new Date(now.getTime() + 7 * 86400000)
    startDate.value = formatDate(getMonday(next))
    endDate.value = formatDate(getSunday(next))
  } else if (preset === 'month') {
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1)
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0)
    startDate.value = formatDate(firstDay)
    endDate.value = formatDate(lastDay)
  }
  emit('reload')
}

function toggleFilterPanel() {
  showFilterPanel.value = !showFilterPanel.value
  if (showFilterPanel.value) nextTick(() => positionFilterPanel())
}

function positionFilterPanel() {
  const btn = filterBtnRef.value?.$el || filterBtnRef.value
  if (!btn) return
  const rect = btn.getBoundingClientRect()
  filterPanelStyle.value = {
    top: `${rect.bottom + 8}px`,
    right: `${window.innerWidth - rect.right}px`,
  }
}

const hasActiveFilters = computed(() => {
  return selectedMember.value || selectedGroupId.value || selectedLabelId.value
})

function clearFilters() {
  selectedMember.value = null
  selectedGroupId.value = null
  selectedLabelId.value = null
  emit('reload')
}

const dayColumns = computed(() => {
  const start = new Date(startDate.value)
  const end = new Date(endDate.value)
  const days = []
  const cur = new Date(start)
  while (cur <= end) {
    days.push({
      date: formatDate(cur),
      dayName: cur.toLocaleDateString(undefined, { weekday: 'short' }),
      dayNum: cur.getDate(),
      monthShort: cur.toLocaleDateString(undefined, { month: 'short' }),
      isWeekend: cur.getDay() === 0 || cur.getDay() === 6,
      isToday: formatDate(cur) === formatDate(new Date()),
    })
    cur.setDate(cur.getDate() + 1)
  }
  return days
})

const allCards = computed(() => {
  const cards = []
  for (const m of props.members) {
    for (const c of m.cards || []) {
      cards.push({ ...c, member_email: m.email })
    }
  }
  return cards
})

const timelineGroups = computed(() => {
  if (groupBy.value === 'member') return groupByMember()
  if (groupBy.value === 'group') return groupByTeamGroup()
  if (groupBy.value === 'type') return groupByType()
  if (groupBy.value === 'stage') return groupByStage()
  return groupByMember()
})

function enrichCard(card) {
  const cardStart = card.start_date || startDate.value
  const cardEnd = card.due_date || endDate.value
  const startIdx = dayColumns.value.findIndex(d => d.date >= cardStart)
  const endIdx = dayColumns.value.findIndex(d => d.date >= cardEnd)
  return {
    ...card,
    barStart: Math.max(0, startIdx >= 0 ? startIdx : 0),
    barEnd: Math.min(dayColumns.value.length - 1, endIdx >= 0 ? endIdx : dayColumns.value.length - 1),
  }
}

function groupByMember() {
  return props.members.map(m => {
    const colleague = colleaguesStore.colleagueByEmail[m.email?.toLowerCase()]
    return {
      key: m.email,
      label: colleague?.display_name || m.email.split('@')[0],
      sublabel: m.email,
      icon: null,
      avatarEmail: m.email,
      color: null,
      cards: (m.cards || []).map(c => enrichCard(c)),
      taskCount: (m.cards || []).length,
    }
  })
}

function groupByTeamGroup() {
  const groups = colleaguesStore.groups || []
  const emailToGroups = {}
  for (const c of colleaguesStore.colleagues || []) {
    const cEmail = c.email?.toLowerCase()
    if (c.group_ids?.length) {
      for (const gid of c.group_ids) {
        if (!emailToGroups[cEmail]) emailToGroups[cEmail] = []
        emailToGroups[cEmail].push(gid)
      }
    }
  }

  const result = []
  for (const g of groups) {
    const groupCards = []
    for (const m of props.members) {
      const mEmail = m.email?.toLowerCase()
      if (emailToGroups[mEmail]?.includes(g.id)) {
        for (const c of m.cards || []) {
          groupCards.push({ ...enrichCard(c), member_email: m.email })
        }
      }
    }
    if (groupCards.length > 0) {
      result.push({
        key: `group-${g.id}`,
        label: g.name,
        sublabel: `${groupCards.length} task${groupCards.length !== 1 ? 's' : ''}`,
        icon: g.icon || 'group',
        avatarEmail: null,
        color: g.color || null,
        cards: groupCards,
        taskCount: groupCards.length,
      })
    }
  }

  const ungroupedCards = []
  for (const m of props.members) {
    const mEmail = m.email?.toLowerCase()
    if (!emailToGroups[mEmail]?.length) {
      for (const c of m.cards || []) {
        ungroupedCards.push({ ...enrichCard(c), member_email: m.email })
      }
    }
  }
  if (ungroupedCards.length > 0) {
    result.push({
      key: 'ungrouped',
      label: 'Ungrouped',
      sublabel: `${ungroupedCards.length} task${ungroupedCards.length !== 1 ? 's' : ''}`,
      icon: 'person',
      avatarEmail: null,
      color: null,
      cards: ungroupedCards,
      taskCount: ungroupedCards.length,
    })
  }
  return result
}

function groupByType() {
  const typeMap = {}
  for (const card of allCards.value) {
    const typeLabels = (card.labels || []).filter(l => l.is_type)
    if (typeLabels.length === 0) {
      if (!typeMap['_none']) typeMap['_none'] = { label: 'No Type', color: null, cards: [] }
      typeMap['_none'].cards.push(enrichCard(card))
    } else {
      for (const tl of typeLabels) {
        const key = `type-${tl.id}`
        if (!typeMap[key]) typeMap[key] = { label: tl.name, color: tl.color, cards: [] }
        typeMap[key].cards.push(enrichCard(card))
      }
    }
  }
  return Object.entries(typeMap).map(([key, group]) => ({
    key,
    label: group.label,
    sublabel: `${group.cards.length} task${group.cards.length !== 1 ? 's' : ''}`,
    icon: key === '_none' ? 'label_off' : 'label',
    avatarEmail: null,
    color: group.color,
    cards: group.cards,
    taskCount: group.cards.length,
  }))
}

function groupByStage() {
  const stageMap = {}
  for (const card of allCards.value) {
    const stage = card.list_name || 'Unknown'
    if (!stageMap[stage]) stageMap[stage] = { cards: [] }
    stageMap[stage].cards.push(enrichCard(card))
  }
  return Object.entries(stageMap).map(([stage, group]) => ({
    key: `stage-${stage}`,
    label: stage,
    sublabel: `${group.cards.length} task${group.cards.length !== 1 ? 's' : ''}`,
    icon: 'view_column',
    avatarEmail: null,
    color: null,
    cards: group.cards,
    taskCount: group.cards.length,
  }))
}

function getInitials(email) {
  const colleague = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (colleague) return colleaguesStore.getInitials(colleague)
  return (email || '?').charAt(0).toUpperCase()
}

function getAvatarColor(email) {
  const colleague = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (colleague) return colleaguesStore.getColleagueColor(colleague)
  return 'bg-primary-500'
}

function getGroupDayCount(group, date) {
  let count = 0
  for (const card of group.cards) {
    const cStart = card.start_date || startDate.value
    const cEnd = card.due_date || endDate.value
    if (date >= cStart && date <= cEnd) count++
  }
  return count
}

const typeLabels = computed(() => props.labels.filter(l => l.is_type))
const regularLabels = computed(() => props.labels.filter(l => !l.is_type))
const colleagues = computed(() => colleaguesStore.sortedColleagues || [])
const groups = computed(() => colleaguesStore.sortedGroups || [])
</script>

<template>
  <div>
    <!-- Controls bar -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <div class="flex bg-surface-200 dark:bg-surface-700 rounded-full p-0.5">
        <button
          v-for="p in [
            { key: 'today', label: 'Today' },
            { key: 'week', label: 'This Week' },
            { key: 'next-week', label: 'Next Week' },
            { key: 'month', label: 'This Month' },
            { key: 'custom', label: 'Custom' },
          ]"
          :key="p.key"
          class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
          :class="datePreset === p.key
            ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-700'"
          @click="applyPreset(p.key)"
        >
          {{ p.label }}
        </button>
      </div>

      <div v-if="datePreset === 'custom'" class="flex items-center gap-2">
        <input
          type="date"
          v-model="startDate"
          class="text-xs px-3 py-1.5 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200"
          @change="$emit('reload')"
        />
        <span class="text-surface-400 text-xs">to</span>
        <input
          type="date"
          v-model="endDate"
          class="text-xs px-3 py-1.5 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200"
          @change="$emit('reload')"
        />
      </div>

      <div class="w-px h-6 bg-surface-200 dark:bg-surface-700"></div>

      <div class="flex items-center gap-2">
        <span class="text-xs text-surface-500">Group by:</span>
        <div class="flex bg-surface-200 dark:bg-surface-700 rounded-full p-0.5">
          <button
            v-for="g in [
              { key: 'member', label: 'Member', icon: 'person' },
              { key: 'group', label: 'Team', icon: 'group' },
              { key: 'type', label: 'Type', icon: 'label' },
              { key: 'stage', label: 'Stage', icon: 'view_column' },
            ]"
            :key="g.key"
            class="px-3 py-1 rounded-full text-xs font-medium transition-colors flex items-center gap-1"
            :class="groupBy === g.key
              ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
              : 'text-surface-500 dark:text-surface-400 hover:text-surface-700'"
            @click="groupBy = g.key"
          >
            <span class="material-symbols-rounded text-[14px]">{{ g.icon }}</span>
            {{ g.label }}
          </button>
        </div>
      </div>

      <div class="flex-1"></div>

      <button
        ref="filterBtnRef"
        class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors flex items-center gap-1.5"
        :class="hasActiveFilters
          ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 border border-primary-300 dark:border-primary-700'
          : 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'"
        @click="toggleFilterPanel"
      >
        <span class="material-symbols-rounded text-[14px]">filter_list</span>
        Filters
        <span v-if="hasActiveFilters" class="w-1.5 h-1.5 rounded-full bg-primary-500"></span>
      </button>
    </div>

    <!-- Active filter chips -->
    <div v-if="hasActiveFilters" class="flex items-center gap-2 mb-4">
      <span class="text-xs text-surface-400">Active filters:</span>
      <button
        v-if="selectedMember"
        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300"
        @click="selectedMember = null"
      >
        <span class="material-symbols-rounded text-[12px]">person</span>
        {{ colleaguesStore.colleagueByEmail[selectedMember]?.display_name || selectedMember }}
        <span class="material-symbols-rounded text-[12px] ml-0.5">close</span>
      </button>
      <button
        v-if="selectedGroupId"
        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300"
        @click="selectedGroupId = null"
      >
        <span class="material-symbols-rounded text-[12px]">group</span>
        {{ colleaguesStore.groupById[selectedGroupId]?.name || 'Group' }}
        <span class="material-symbols-rounded text-[12px] ml-0.5">close</span>
      </button>
      <button
        v-if="selectedLabelId"
        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300"
        @click="selectedLabelId = null"
      >
        <span class="material-symbols-rounded text-[12px]">label</span>
        {{ labels.find(l => Number(l.id) === selectedLabelId)?.name || 'Label' }}
        <span class="material-symbols-rounded text-[12px] ml-0.5">close</span>
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="timelineGroups.length === 0" class="text-center py-16 text-surface-400 dark:text-surface-500">
      <span class="material-symbols-rounded text-5xl mb-3 block">group</span>
      <p class="mb-2">No assignments found for this period</p>
      <p v-if="hasActiveFilters" class="text-xs">Try adjusting your filters or date range</p>
    </div>

    <!-- Gantt chart -->
    <div v-else class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <div class="overflow-x-auto">
        <div class="min-w-max">
          <!-- Day headers -->
          <div class="flex border-b border-surface-200 dark:border-surface-700 sticky top-0 z-10 bg-white dark:bg-surface-800">
            <div class="w-52 shrink-0 px-4 py-2 text-xs font-semibold text-surface-500 uppercase tracking-wide border-r border-surface-200 dark:border-surface-700">
              {{ groupBy === 'member' ? 'Member' : groupBy === 'group' ? 'Team' : groupBy === 'type' ? 'Type' : 'Stage' }}
            </div>
            <div
              v-for="day in dayColumns"
              :key="day.date"
              class="w-12 shrink-0 text-center py-2 text-[10px] leading-tight border-r border-surface-100 dark:border-surface-700/50"
              :class="{
                'bg-surface-50 dark:bg-surface-700/30': day.isWeekend,
                'bg-primary-50 dark:bg-primary-900/20': day.isToday,
              }"
            >
              <div class="font-semibold" :class="day.isToday ? 'text-primary-600 dark:text-primary-400' : 'text-surface-500'">
                {{ day.dayName }}
              </div>
              <div :class="day.isToday ? 'text-primary-500' : 'text-surface-400'">
                {{ day.dayNum }}
              </div>
            </div>
          </div>

          <!-- Group rows -->
          <div v-for="group in timelineGroups" :key="group.key" class="border-b border-surface-100 dark:border-surface-700/50 last:border-0">
            <div class="flex items-center bg-surface-50/50 dark:bg-surface-700/20">
              <div class="w-52 shrink-0 px-4 py-3 flex items-center gap-2.5 border-r border-surface-200 dark:border-surface-700">
                <div v-if="group.avatarEmail" class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0" :class="getAvatarColor(group.avatarEmail)">
                  {{ getInitials(group.avatarEmail) }}
                </div>
                <div v-else class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" :class="group.color ? '' : 'bg-surface-200 dark:bg-surface-600'" :style="group.color ? { backgroundColor: group.color } : {}">
                  <span class="material-symbols-rounded text-[16px]" :class="group.color ? 'text-white' : 'text-surface-500'">{{ group.icon || 'folder' }}</span>
                </div>
                <div class="min-w-0">
                  <div class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate">{{ group.label }}</div>
                  <div class="text-[10px] text-surface-400 truncate">{{ group.sublabel }}</div>
                </div>
                <span class="ml-auto text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-surface-200 dark:bg-surface-600 text-surface-500 shrink-0">{{ group.taskCount }}</span>
              </div>
              <div class="flex flex-1">
                <div
                  v-for="day in dayColumns"
                  :key="day.date"
                  class="w-12 shrink-0 h-10 border-r border-surface-100 dark:border-surface-700/50 relative"
                  :class="{ 'bg-surface-50/50 dark:bg-surface-700/20': day.isWeekend }"
                >
                  <div
                    v-if="getGroupDayCount(group, day.date) > 0"
                    class="absolute inset-x-1 bottom-1 rounded-sm transition-all"
                    :class="getGroupDayCount(group, day.date) >= 3 ? 'bg-red-200 dark:bg-red-900/30' : getGroupDayCount(group, day.date) >= 2 ? 'bg-amber-200 dark:bg-amber-900/30' : 'bg-green-200 dark:bg-green-900/30'"
                    :style="{ height: Math.min(getGroupDayCount(group, day.date) * 6, 24) + 'px' }"
                    :title="`${getGroupDayCount(group, day.date)} task(s)`"
                  ></div>
                </div>
              </div>
            </div>

            <div v-for="card in group.cards" :key="card.card_id + '-' + group.key" class="flex items-center">
              <div class="w-52 shrink-0 px-4 py-1 border-r border-surface-200 dark:border-surface-700 flex items-center gap-2">
                <span
                  v-if="card.labels?.length"
                  class="w-2 h-2 rounded-full shrink-0"
                  :style="{ backgroundColor: card.labels[0]?.color || '#888' }"
                ></span>
                <span class="text-xs text-surface-500 truncate flex-1" :title="card.title">{{ card.title }}</span>
                <span v-if="card.member_email && groupBy !== 'member'" class="w-4 h-4 rounded-full text-[8px] font-bold text-white flex items-center justify-center shrink-0" :class="getAvatarColor(card.member_email)">
                  {{ getInitials(card.member_email) }}
                </span>
              </div>
              <div class="flex flex-1 relative h-6">
                <div
                  v-for="(day, idx) in dayColumns"
                  :key="day.date"
                  class="w-12 shrink-0 border-r border-surface-100 dark:border-surface-700/50"
                  :class="{ 'bg-surface-50/50 dark:bg-surface-700/20': day.isWeekend }"
                >
                  <div
                    v-if="idx >= card.barStart && idx <= card.barEnd"
                    class="h-4 mt-1 rounded-sm"
                    :class="[
                      statusBarColors[card.status] || 'bg-surface-400',
                      idx === card.barStart ? 'ml-1 rounded-l-full' : '',
                      idx === card.barEnd ? 'mr-1 rounded-r-full' : '',
                    ]"
                    :title="`${card.title} (${card.status})`"
                  ></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Legend -->
    <div class="flex items-center gap-4 mt-4 px-2">
      <span class="text-[10px] text-surface-400 uppercase font-semibold tracking-wide">Status:</span>
      <div v-for="(color, status) in statusBarColors" :key="status" class="flex items-center gap-1.5">
        <span class="w-3 h-2 rounded-sm" :class="color"></span>
        <span class="text-[10px] text-surface-500 capitalize">{{ status }}</span>
      </div>
      <div class="w-px h-3 bg-surface-200 dark:bg-surface-700 mx-1"></div>
      <span class="text-[10px] text-surface-400 uppercase font-semibold tracking-wide">Load:</span>
      <div class="flex items-center gap-1.5">
        <span class="w-3 h-2 rounded-sm bg-green-200 dark:bg-green-900/30"></span>
        <span class="text-[10px] text-surface-500">Low</span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-3 h-2 rounded-sm bg-amber-200 dark:bg-amber-900/30"></span>
        <span class="text-[10px] text-surface-500">Medium</span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-3 h-2 rounded-sm bg-red-200 dark:bg-red-900/30"></span>
        <span class="text-[10px] text-surface-500">High</span>
      </div>
    </div>

    <!-- Filter panel -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="showFilterPanel"
          class="fixed inset-0 z-[60]"
          @click.self="showFilterPanel = false"
        >
          <div
            class="absolute w-72 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 shadow-xl p-4 space-y-4"
            :style="filterPanelStyle"
          >
            <div>
              <label class="text-[10px] font-semibold text-surface-500 uppercase tracking-wide mb-1.5 block">Team Member</label>
              <select
                v-model="selectedMember"
                class="w-full text-xs px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-300"
              >
                <option :value="null">All Members</option>
                <option v-for="c in colleagues" :key="c.email" :value="c.email">
                  {{ c.display_name || c.email }}
                </option>
              </select>
            </div>

            <div>
              <label class="text-[10px] font-semibold text-surface-500 uppercase tracking-wide mb-1.5 block">Team Group</label>
              <select
                v-model="selectedGroupId"
                class="w-full text-xs px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-300"
              >
                <option :value="null">All Groups</option>
                <option v-for="g in groups" :key="g.id" :value="g.id">
                  {{ g.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="text-[10px] font-semibold text-surface-500 uppercase tracking-wide mb-1.5 block">Type / Label</label>
              <select
                v-model="selectedLabelId"
                class="w-full text-xs px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-300"
              >
                <option :value="null">All Types</option>
                <optgroup v-if="typeLabels.length" label="Types">
                  <option v-for="l in typeLabels" :key="l.id" :value="Number(l.id)">
                    {{ l.name }}
                  </option>
                </optgroup>
                <optgroup v-if="regularLabels.length" label="Labels">
                  <option v-for="l in regularLabels" :key="l.id" :value="Number(l.id)">
                    {{ l.name }}
                  </option>
                </optgroup>
              </select>
            </div>

            <div class="flex items-center gap-2 pt-2 border-t border-surface-200 dark:border-surface-700">
              <button
                class="flex-1 px-3 py-1.5 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                @click="clearFilters(); showFilterPanel = false"
              >
                Clear All
              </button>
              <button
                class="flex-1 px-3 py-1.5 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
                @click="showFilterPanel = false"
              >
                Done
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
