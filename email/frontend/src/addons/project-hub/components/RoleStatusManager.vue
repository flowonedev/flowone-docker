<script setup>
import { ref, onMounted } from 'vue'
import * as roleApi from '@/addons/project-hub/services/projectHubRoleApi'

const roles = ref([])
const loading = ref(false)
const expandedRole = ref(null)
const roleStatuses = ref({})

const showAddRole = ref(false)
const newRoleName = ref('')
const newRoleColor = ref('#6366f1')
const newRoleIcon = ref('badge')

const showAddStatus = ref(null)
const newStatusName = ref('')
const newStatusColor = ref('#6b7280')
const newStatusIcon = ref('circle')
const newStatusTerminal = ref(false)

const editingRole = ref(null)
const editRoleName = ref('')
const editRoleColor = ref('')

const colorPresets = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#6b7280']
const iconPresets = ['badge', 'person', 'rate_review', 'visibility', 'engineering', 'design_services', 'code', 'support_agent', 'manage_accounts']

onMounted(loadRoles)

async function loadRoles() {
  loading.value = true
  try {
    roles.value = await roleApi.fetchRoles()
  } finally {
    loading.value = false
  }
}

async function addRole() {
  if (!newRoleName.value.trim()) return
  try {
    await roleApi.createRole({
      name: newRoleName.value.trim(),
      color: newRoleColor.value,
      icon: newRoleIcon.value,
    })
    newRoleName.value = ''
    showAddRole.value = false
    await loadRoles()
  } catch (err) {
    console.error('Failed to create role:', err)
  }
}

async function saveRoleEdit(id) {
  try {
    await roleApi.updateRole(id, {
      name: editRoleName.value.trim(),
      color: editRoleColor.value,
    })
    editingRole.value = null
    await loadRoles()
  } catch (err) {
    console.error('Failed to update role:', err)
  }
}

async function removeRole(id) {
  if (!confirm('Delete this role and all its statuses?')) return
  try {
    await roleApi.deleteRole(id)
    await loadRoles()
  } catch (err) {
    console.error('Failed to delete role:', err)
  }
}

async function toggleExpand(roleId) {
  if (expandedRole.value === roleId) {
    expandedRole.value = null
    return
  }
  expandedRole.value = roleId
  if (!roleStatuses.value[roleId]) {
    roleStatuses.value[roleId] = await roleApi.fetchRoleStatuses(roleId)
  }
}

async function addStatus(roleId) {
  if (!newStatusName.value.trim()) return
  try {
    await roleApi.createRoleStatus(roleId, {
      name: newStatusName.value.trim(),
      color: newStatusColor.value,
      icon: newStatusIcon.value,
      is_terminal: newStatusTerminal.value ? 1 : 0,
    })
    newStatusName.value = ''
    newStatusTerminal.value = false
    showAddStatus.value = null
    roleStatuses.value[roleId] = await roleApi.fetchRoleStatuses(roleId)
  } catch (err) {
    console.error('Failed to create status:', err)
  }
}

async function removeStatus(roleId, statusId) {
  try {
    await roleApi.deleteRoleStatus(statusId)
    roleStatuses.value[roleId] = await roleApi.fetchRoleStatuses(roleId)
  } catch (err) {
    console.error('Failed to delete status:', err)
  }
}

function startEditRole(role) {
  editingRole.value = role.id
  editRoleName.value = role.name
  editRoleColor.value = role.color
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-surface-800 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg">shield_person</span>
        Roles & Statuses
      </h3>
      <button
        class="px-3 py-1.5 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center gap-1"
        @click="showAddRole = true"
      >
        <span class="material-symbols-rounded text-[14px]">add</span>
        Add Role
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-6">
      <span class="material-symbols-rounded animate-spin text-primary-500">progress_activity</span>
    </div>

    <!-- Add role form -->
    <div v-if="showAddRole" class="p-4 bg-surface-50 dark:bg-surface-700/50 rounded-xl border border-surface-200 dark:border-surface-600">
      <div class="flex items-center gap-3 mb-3">
        <input
          v-model="newRoleName"
          class="flex-1 px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 outline-none focus:ring-2 focus:ring-primary-500"
          placeholder="Role name..."
          @keydown.enter="addRole"
          autofocus
        />
      </div>
      <div class="flex items-center gap-2 mb-3">
        <span class="text-xs text-surface-500">Color:</span>
        <button
          v-for="c in colorPresets"
          :key="c"
          class="w-5 h-5 rounded-full border-2 transition-all"
          :class="newRoleColor === c ? 'border-surface-800 dark:border-white scale-110' : 'border-transparent'"
          :style="{ backgroundColor: c }"
          @click="newRoleColor = c"
        ></button>
      </div>
      <div class="flex items-center gap-2 mb-3">
        <span class="text-xs text-surface-500">Icon:</span>
        <button
          v-for="ic in iconPresets"
          :key="ic"
          class="w-7 h-7 rounded-lg flex items-center justify-center transition-all"
          :class="newRoleIcon === ic ? 'bg-primary-500 text-white' : 'bg-surface-200 dark:bg-surface-600 text-surface-500'"
          @click="newRoleIcon = ic"
        >
          <span class="material-symbols-rounded text-[16px]">{{ ic }}</span>
        </button>
      </div>
      <div class="flex items-center gap-2">
        <button class="px-4 py-1.5 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors" @click="addRole">Create</button>
        <button class="px-4 py-1.5 rounded-full text-xs text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors" @click="showAddRole = false">Cancel</button>
      </div>
    </div>

    <!-- Role list -->
    <div v-else class="space-y-2">
      <div
        v-for="role in roles"
        :key="role.id"
        class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden"
      >
        <!-- Role header -->
        <div class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors" @click="toggleExpand(role.id)">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center" :style="{ backgroundColor: role.color || '#6366f1' }">
            <span class="material-symbols-rounded text-white text-[18px]">{{ role.icon || 'badge' }}</span>
          </div>

          <template v-if="editingRole === role.id">
            <input
              v-model="editRoleName"
              class="flex-1 px-2 py-1 rounded-lg border border-primary-400 bg-white dark:bg-surface-700 text-sm outline-none text-surface-800 dark:text-surface-200"
              @click.stop
              @keydown.enter="saveRoleEdit(role.id)"
              @keydown.escape="editingRole = null"
            />
            <button class="text-xs text-primary-500 font-medium" @click.stop="saveRoleEdit(role.id)">Save</button>
          </template>
          <template v-else>
            <div class="flex-1 min-w-0">
              <span class="text-sm font-medium text-surface-800 dark:text-surface-200">{{ role.name }}</span>
              <span class="text-[10px] text-surface-400 ml-2">{{ role.status_count || 0 }} statuses</span>
            </div>
          </template>

          <div class="flex items-center gap-1">
            <button class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors" @click.stop="startEditRole(role)" title="Edit">
              <span class="material-symbols-rounded text-[16px] text-surface-400">edit</span>
            </button>
            <button class="p-1 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/20 transition-colors" @click.stop="removeRole(role.id)" title="Delete">
              <span class="material-symbols-rounded text-[16px] text-red-400">delete</span>
            </button>
            <span class="material-symbols-rounded text-[18px] text-surface-400 transition-transform" :class="{ 'rotate-180': expandedRole === role.id }">expand_more</span>
          </div>
        </div>

        <!-- Status list (expanded) -->
        <div v-if="expandedRole === role.id" class="border-t border-surface-200 dark:border-surface-700 px-4 py-3 bg-surface-50/50 dark:bg-surface-700/20">
          <div class="space-y-1.5 mb-3">
            <div
              v-for="status in (roleStatuses[role.id] || [])"
              :key="status.id"
              class="group flex items-center gap-2 px-3 py-2 rounded-lg bg-white dark:bg-surface-800 border border-surface-100 dark:border-surface-700"
            >
              <span class="w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: status.color }"></span>
              <span class="material-symbols-rounded text-[16px] text-surface-400">{{ status.icon || 'circle' }}</span>
              <span class="text-sm text-surface-700 dark:text-surface-300 flex-1">{{ status.name }}</span>
              <span v-if="status.is_terminal == 1" class="text-[10px] px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/20 text-green-600 dark:text-green-400">terminal</span>
              <button
                class="p-0.5 rounded opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-900/20 transition-all"
                @click="removeStatus(role.id, status.id)"
              >
                <span class="material-symbols-rounded text-[14px] text-red-400">close</span>
              </button>
            </div>

            <div v-if="(roleStatuses[role.id] || []).length === 0" class="text-xs text-surface-400 italic py-2 text-center">
              No statuses defined
            </div>
          </div>

          <!-- Add status form -->
          <div v-if="showAddStatus === role.id" class="p-3 rounded-lg bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
            <input
              v-model="newStatusName"
              class="w-full px-3 py-1.5 mb-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="Status name..."
              @keydown.enter="addStatus(role.id)"
            />
            <div class="flex items-center gap-2 mb-2">
              <span class="text-[10px] text-surface-500">Color:</span>
              <button
                v-for="c in colorPresets"
                :key="c"
                class="w-4 h-4 rounded-full border-2 transition-all"
                :class="newStatusColor === c ? 'border-surface-800 dark:border-white' : 'border-transparent'"
                :style="{ backgroundColor: c }"
                @click="newStatusColor = c"
              ></button>
            </div>
            <div class="flex items-center gap-3 mb-2">
              <label class="flex items-center gap-2 cursor-pointer">
                <div
                  class="w-9 h-5 rounded-full relative transition-colors cursor-pointer"
                  :class="newStatusTerminal ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600'"
                  @click="newStatusTerminal = !newStatusTerminal"
                >
                  <div
                    class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-all"
                    :class="newStatusTerminal ? 'left-[18px]' : 'left-0.5'"
                  ></div>
                </div>
                <span class="text-xs text-surface-500">Terminal (marks task as done)</span>
              </label>
            </div>
            <div class="flex items-center gap-2">
              <button class="px-3 py-1 rounded-full text-xs font-medium bg-primary-500 text-white" @click="addStatus(role.id)">Add</button>
              <button class="px-3 py-1 rounded-full text-xs text-surface-500" @click="showAddStatus = null">Cancel</button>
            </div>
          </div>
          <button
            v-else
            class="w-full flex items-center justify-center gap-1 py-2 rounded-lg border border-dashed border-surface-300 dark:border-surface-600 text-xs text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            @click="showAddStatus = role.id"
          >
            <span class="material-symbols-rounded text-[14px]">add</span>
            Add Status
          </button>
        </div>
      </div>

      <div v-if="!loading && roles.length === 0" class="text-center py-8 text-surface-400">
        <span class="material-symbols-rounded text-4xl block mb-2">shield_person</span>
        <p class="text-sm">No roles defined yet</p>
        <p class="text-xs mt-1">Create roles to define custom status workflows</p>
      </div>
    </div>
  </div>
</template>
