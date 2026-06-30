<script setup>
import { ref, computed } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useAuthStore } from '@/stores/auth'
import ProjectHubLayout from '@/addons/project-hub/components/ProjectHubLayout.vue'
import RoleStatusManager from '@/addons/project-hub/components/RoleStatusManager.vue'
import UserRoleAssigner from '@/addons/project-hub/components/UserRoleAssigner.vue'
import NotificationPreferences from '@/addons/project-hub/components/NotificationPreferences.vue'
import TestSimulationPanel from '@/components/settings/TestSimulationPanel.vue'

const colleaguesStore = useColleaguesStore()
const authStore = useAuthStore()

const isAdmin = computed(() => colleaguesStore.isAdmin)

// Test Simulation is gated server-side; the tab itself is also hidden for non-allowlisted domains.
const canSimulate = computed(() => {
  const e = (authStore.userEmail || '').toLowerCase()
  const d = e.split('@')[1] || ''
  return ['pixelranger.hu', 'whiterabbit.hu', 'greyskull.hu'].includes(d)
})

const activeSection = ref(isAdmin.value ? 'roles' : 'notifications')

const sections = computed(() => {
  const all = [
    { id: 'roles', label: 'Roles & Statuses', icon: 'badge', admin: true, allowed: true },
    { id: 'members', label: 'Member Roles', icon: 'group', admin: true, allowed: true },
    { id: 'notifications', label: 'Notifications', icon: 'notifications_active', admin: false, allowed: true },
    { id: 'test-simulation', label: 'Test Simulation', icon: 'science', admin: true, allowed: canSimulate.value },
  ]
  return all.filter(s => s.allowed && (!s.admin || isAdmin.value))
})
</script>

<template>
  <ProjectHubLayout title="Project Hub Settings" icon="settings">
    <div class="w-full p-6 space-y-6">
      <!-- Section tabs -->
      <div class="flex gap-1 p-1 bg-surface-100 dark:bg-surface-900 rounded-xl">
        <button
          v-for="section in sections"
          :key="section.id"
          @click="activeSection = section.id"
          :class="[
            'flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all flex-1 justify-center',
            activeSection === section.id
              ? 'bg-white dark:bg-surface-700 text-primary-600 dark:text-primary-400 shadow-sm'
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="material-symbols-rounded text-base">{{ section.icon }}</span>
          {{ section.label }}
        </button>
      </div>

      <div v-if="activeSection === 'roles' && isAdmin" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-6">
        <RoleStatusManager />
      </div>

      <div v-if="activeSection === 'members' && isAdmin" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-6">
        <UserRoleAssigner />
      </div>

      <div v-if="activeSection === 'notifications'" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-6">
        <NotificationPreferences />
      </div>

      <div v-if="activeSection === 'test-simulation' && isAdmin && canSimulate" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-6">
        <TestSimulationPanel />
      </div>
    </div>
  </ProjectHubLayout>
</template>
