<template>
  <div class="space-y-1">
    <div class="flex items-center justify-between px-2 mb-1">
      <span class="text-[11px] font-semibold text-surface-400 dark:text-surface-500 uppercase tracking-wider">Team</span>
      <span class="text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">
        {{ onlineMembers.length }} online
      </span>
    </div>

    <!-- Search -->
    <div class="px-1 mb-1">
      <div class="relative">
        <span class="material-symbols-rounded text-[14px] text-surface-400 absolute left-2 top-1/2 -translate-y-1/2 pointer-events-none">search</span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Find team member..."
          class="w-full text-[11px] pl-7 pr-7 py-1.5 rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 text-surface-700 dark:text-surface-300 placeholder-surface-400 outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500 transition-colors"
        />
        <button
          v-if="searchQuery"
          class="absolute right-1.5 top-1/2 -translate-y-1/2 p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700"
          @click="searchQuery = ''"
        >
          <span class="material-symbols-rounded text-[12px] text-surface-400">close</span>
        </button>
      </div>
    </div>

    <!-- Grouped view (when not searching) -->
    <template v-if="!searchQuery">
      <template v-for="(group, gIdx) in displayGroups" :key="group.id">
        <div v-if="gIdx > 0 && group.members.length > 0" class="mx-2 my-1.5 border-t border-surface-200 dark:border-surface-700"></div>
        <div
          v-if="group.members.length > 0"
          class="mb-0.5"
        >
          <div
            class="flex items-center gap-1 px-2 py-1 cursor-pointer select-none rounded-md hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors"
            @click="toggleGroup(group.id)"
          >
            <span
              class="material-symbols-rounded text-[13px] text-surface-400 transition-transform"
              :class="expandedGroups[group.id] ? 'rotate-90' : ''"
            >chevron_right</span>
            <span
              v-if="group.color"
              class="w-1.5 h-1.5 rounded-full shrink-0"
              :style="{ backgroundColor: group.color }"
            ></span>
            <span class="text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide truncate">{{ group.name }}</span>
            <span class="text-[9px] text-surface-400 ml-auto tabular-nums">{{ group.onlineCount }}/{{ group.members.length }}</span>
          </div>

          <div v-if="expandedGroups[group.id]" class="space-y-px ml-1">
            <div
              v-for="member in group.sortedMembers"
              :key="member.email"
              class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              :class="colleaguesStore.isAdmin ? 'cursor-pointer' : ''"
              @click="colleaguesStore.isAdmin && openMemberPanel(member)"
            >
              <div class="relative shrink-0">
                <div
                  class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold"
                  :class="isOnline(member.email)
                    ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                    : 'bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400'"
                >{{ initials(member) }}</div>
                <span
                  class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-surface-50 dark:border-surface-800"
                  :class="statusDotClass(member.email)"
                ></span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate leading-tight">
                  {{ member.display_name || member.name || member.email }}
                </p>
                <p v-if="currentView(member.email)" class="text-[10px] text-surface-400 truncate leading-tight">
                  {{ currentView(member.email) }}
                </p>
              </div>
              <button
                v-if="chatEnabled"
                @click.stop="startChat(member)"
                class="shrink-0 p-1 rounded-md hover:bg-primary-100 dark:hover:bg-primary-900/30 transition-colors"
                title="Send message"
              >
                <span class="material-symbols-rounded text-[16px] text-surface-400 hover:text-primary-500 transition-colors">chat</span>
              </button>
            </div>
          </div>
        </div>
      </template>
    </template>

    <!-- Flat filtered list (when searching) -->
    <template v-else>
      <div
        v-for="member in filteredMembers"
        :key="member.email"
        class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        :class="colleaguesStore.isAdmin ? 'cursor-pointer' : ''"
        @click="colleaguesStore.isAdmin && openMemberPanel(member)"
      >
        <div class="relative shrink-0">
          <div
            class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold"
            :class="isOnline(member.email)
              ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
              : 'bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400'"
          >{{ initials(member) }}</div>
          <span
            class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-surface-50 dark:border-surface-800"
            :class="statusDotClass(member.email)"
          ></span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate leading-tight">
            {{ member.display_name || member.name || member.email }}
          </p>
          <p v-if="memberGroupLabel(member)" class="text-[10px] text-surface-400 truncate leading-tight">
            {{ memberGroupLabel(member) }}
          </p>
        </div>
        <button
          v-if="chatEnabled"
          @click.stop="startChat(member)"
          class="shrink-0 p-1 rounded-md hover:bg-primary-100 dark:hover:bg-primary-900/30 transition-colors"
          title="Send message"
        >
          <span class="material-symbols-rounded text-[16px] text-surface-400 hover:text-primary-500 transition-colors">chat</span>
        </button>
      </div>
      <div v-if="filteredMembers.length === 0" class="text-[11px] text-surface-400 text-center py-2">
        No matches
      </div>
    </template>

    <div v-if="!searchQuery && members.length === 0" class="text-xs text-surface-400 text-center py-2">
      No team members found
    </div>

    <MemberWorkPanel
      :member="selectedMember"
      @close="selectedMember = null"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, defineAsyncComponent } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useAddons } from '@/composables/useAddons'

const MemberWorkPanel = defineAsyncComponent(() => import('./MemberWorkPanel.vue'))

const colleaguesStore = useColleaguesStore()
const chatStore = useChatStore()
const { chatEnabled } = useAddons()

const selectedMember = ref(null)
const searchQuery = ref('')
const expandedGroups = ref({})

onMounted(async () => {
  await colleaguesStore.init()
  for (const g of colleaguesStore.sortedGroups) {
    expandedGroups.value[g.id] = true
  }
  expandedGroups.value['ungrouped'] = true
})

const members = computed(() => colleaguesStore.colleagues || [])

const onlineMembers = computed(() =>
  members.value.filter(m => isOnline(m.email))
)

const displayGroups = computed(() => {
  const groups = colleaguesStore.sortedGroups || []
  const byGroup = colleaguesStore.colleaguesByGroup || {}
  const result = []

  for (const group of groups) {
    const groupMembers = byGroup[group.id] || []
    if (groupMembers.length === 0) continue
    result.push({
      id: group.id,
      name: group.name,
      color: group.color || null,
      members: groupMembers,
      sortedMembers: sortMembersList(groupMembers),
      onlineCount: groupMembers.filter(m => isOnline(m.email)).length,
    })
  }

  const ungrouped = byGroup['ungrouped'] || []
  if (ungrouped.length > 0) {
    result.push({
      id: 'ungrouped',
      name: 'Ungrouped',
      color: null,
      members: ungrouped,
      sortedMembers: sortMembersList(ungrouped),
      onlineCount: ungrouped.filter(m => isOnline(m.email)).length,
    })
  }

  return result
})

const filteredMembers = computed(() => {
  const q = searchQuery.value.toLowerCase().trim()
  if (!q) return []
  return sortMembersList(
    members.value.filter(m => {
      const name = (m.display_name || m.name || '').toLowerCase()
      const email = (m.email || '').toLowerCase()
      return name.includes(q) || email.includes(q)
    })
  )
})

function sortMembersList(list) {
  return [...list].sort((a, b) => {
    const aOn = isOnline(a.email) ? 0 : 1
    const bOn = isOnline(b.email) ? 0 : 1
    if (aOn !== bOn) return aOn - bOn
    return (a.display_name || a.name || a.email).localeCompare(b.display_name || b.name || b.email)
  })
}

function toggleGroup(groupId) {
  expandedGroups.value[groupId] = !expandedGroups.value[groupId]
}

function isOnline(email) {
  const status = colleaguesStore.getColleagueStatus(email)
  return status === 'online' || status === 'active'
}

function currentView(email) {
  return colleaguesStore.getColleagueCurrentView(email)
}

function statusDotClass(email) {
  const status = colleaguesStore.getColleagueStatus(email)
  if (status === 'online' || status === 'active') return 'bg-green-500'
  if (status === 'away') return 'bg-amber-500'
  return 'bg-surface-400'
}

function initials(member) {
  const name = member.display_name || member.name
  if (name) {
    const parts = name.split(' ')
    return (parts[0]?.[0] || '') + (parts[1]?.[0] || '')
  }
  return (member.email?.[0] || '?').toUpperCase()
}

function memberGroupLabel(member) {
  if (!member.group_ids || member.group_ids.length === 0) return null
  const groupNames = member.group_ids
    .map(gid => colleaguesStore.groupById[gid]?.name)
    .filter(Boolean)
  return groupNames.join(', ')
}

function openMemberPanel(member) {
  selectedMember.value = member
}

async function startChat(member) {
  const id = member.id || member.email
  await chatStore.openDMAndExpand(id)
}
</script>
