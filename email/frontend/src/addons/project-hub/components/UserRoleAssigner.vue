<script setup>
import { ref, onMounted, computed } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import * as roleApi from '@/addons/project-hub/services/projectHubRoleApi'

const colleaguesStore = useColleaguesStore()

const roles = ref([])
const loading = ref(false)
const selectedEmail = ref(null)
const userRoles = ref([])
const userRolesLoading = ref(false)

const colleagues = computed(() => colleaguesStore.sortedColleagues || [])

onMounted(async () => {
  loading.value = true
  try {
    await colleaguesStore.init()
    roles.value = await roleApi.fetchRoles()
  } finally {
    loading.value = false
  }
})

async function selectUser(email) {
  selectedEmail.value = email
  userRolesLoading.value = true
  try {
    userRoles.value = await roleApi.fetchUserRoles(email)
  } finally {
    userRolesLoading.value = false
  }
}

function hasRole(roleId) {
  return userRoles.value.some(ur => ur.role_id == roleId)
}

async function toggleRole(roleId) {
  if (!selectedEmail.value) return
  try {
    if (hasRole(roleId)) {
      await roleApi.removeUserRole(selectedEmail.value, roleId)
    } else {
      await roleApi.assignUserRole(selectedEmail.value, roleId)
    }
    userRoles.value = await roleApi.fetchUserRoles(selectedEmail.value)
  } catch (err) {
    console.error('Failed to toggle user role:', err)
  }
}

function getInitials(colleague) {
  return colleaguesStore.getInitials(colleague)
}

function getColor(colleague) {
  return colleaguesStore.getColleagueColor(colleague)
}
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-sm font-semibold text-surface-800 dark:text-surface-200 flex items-center gap-2">
      <span class="material-symbols-rounded text-lg">group</span>
      Assign Roles to Members
    </h3>

    <div v-if="loading" class="flex justify-center py-6">
      <span class="material-symbols-rounded animate-spin text-primary-500">progress_activity</span>
    </div>

    <div v-else class="flex gap-4">
      <!-- Member list -->
      <div class="w-64 shrink-0 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700">
          <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Team Members</span>
        </div>
        <div class="max-h-96 overflow-y-auto">
          <button
            v-for="c in colleagues"
            :key="c.email"
            class="w-full flex items-center gap-2.5 px-3 py-2.5 text-left transition-colors"
            :class="selectedEmail === c.email
              ? 'bg-primary-50 dark:bg-primary-900/20 border-l-2 border-primary-500'
              : 'hover:bg-surface-50 dark:hover:bg-surface-700/50 border-l-2 border-transparent'"
            @click="selectUser(c.email)"
          >
            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0" :class="getColor(c)">
              {{ getInitials(c) }}
            </div>
            <div class="min-w-0">
              <div class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate">{{ c.display_name || c.email }}</div>
              <div class="text-[10px] text-surface-400 truncate">{{ c.email }}</div>
            </div>
          </button>
        </div>
      </div>

      <!-- Role assignment panel -->
      <div class="flex-1 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4">
        <div v-if="!selectedEmail" class="text-center py-12 text-surface-400">
          <span class="material-symbols-rounded text-4xl block mb-2">person_search</span>
          <p class="text-sm">Select a team member to manage their roles</p>
        </div>

        <div v-else>
          <div class="mb-4">
            <span class="text-sm font-medium text-surface-800 dark:text-surface-200">{{ selectedEmail }}</span>
          </div>

          <div v-if="userRolesLoading" class="flex justify-center py-6">
            <span class="material-symbols-rounded animate-spin text-primary-500 text-sm">progress_activity</span>
          </div>

          <div v-else class="space-y-2">
            <div
              v-for="role in roles"
              :key="role.id"
              class="flex items-center gap-3 px-3 py-2.5 rounded-xl border transition-colors cursor-pointer"
              :class="hasRole(role.id)
                ? 'border-primary-300 dark:border-primary-700 bg-primary-50 dark:bg-primary-900/20'
                : 'border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700/50'"
              @click="toggleRole(role.id)"
            >
              <div class="w-8 h-8 rounded-lg flex items-center justify-center" :style="{ backgroundColor: role.color || '#6366f1' }">
                <span class="material-symbols-rounded text-white text-[18px]">{{ role.icon || 'badge' }}</span>
              </div>
              <div class="flex-1">
                <div class="text-sm font-medium text-surface-800 dark:text-surface-200">{{ role.name }}</div>
                <div v-if="role.description" class="text-[10px] text-surface-400">{{ role.description }}</div>
              </div>
              <div
                class="w-9 h-5 rounded-full relative transition-colors"
                :class="hasRole(role.id) ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
              >
                <div
                  class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-all"
                  :class="hasRole(role.id) ? 'left-[18px]' : 'left-0.5'"
                ></div>
              </div>
            </div>

            <div v-if="roles.length === 0" class="text-center py-4 text-surface-400 text-xs">
              No roles defined yet. Create roles first.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
