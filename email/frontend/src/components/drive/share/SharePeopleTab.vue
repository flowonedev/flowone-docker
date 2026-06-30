<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToastStore } from '@/stores/toast'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import {
  fetchCollaborators,
  addCollaborator as apiAddCollaborator,
  updateCollaboratorPermission as apiUpdatePermission,
  removeCollaborator as apiRemoveCollaborator,
} from '@/services/driveShareApi'

const props = defineProps({
  itemId: { type: Number, required: true },
  targetType: { type: String, default: 'file' }, // 'file' | 'folder'
})

const emit = defineEmits(['changed'])

const toast = useToastStore()
const colleaguesStore = useColleaguesStore()

const collaborators = ref([])
const loading = ref(false)
const adding = ref(false)
const collaboratorEmail = ref('')
const collaboratorPermission = ref('viewer')
const showColleaguePicker = ref(false)
const colleagueSearchQuery = ref('')

const isFolder = computed(() => props.targetType === 'folder')

const filteredColleagues = computed(() => {
  const search = colleagueSearchQuery.value.toLowerCase()
  const existingEmails = collaborators.value.map(c => c.email.toLowerCase())

  return colleaguesStore.sortedColleagues.filter(c => {
    if (existingEmails.includes(c.email.toLowerCase())) return false
    if (!search) return true
    return c.email.toLowerCase().includes(search) ||
           (c.display_name && c.display_name.toLowerCase().includes(search))
  }).slice(0, 10)
})

async function load() {
  loading.value = true
  try {
    collaborators.value = await fetchCollaborators(props.targetType, props.itemId)
  } finally {
    loading.value = false
  }
}

async function addCollaborator() {
  if (!collaboratorEmail.value) return

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!emailRegex.test(collaboratorEmail.value)) {
    toast.error('Please enter a valid email address')
    return
  }

  adding.value = true
  try {
    const result = await apiAddCollaborator(props.targetType, props.itemId, collaboratorEmail.value, collaboratorPermission.value)
    if (result.success) {
      toast.success('Collaborator added')
      collaboratorEmail.value = ''
      await load()
      emit('changed')
    } else {
      toast.error(result.error || 'Failed to add collaborator')
    }
  } finally {
    adding.value = false
  }
}

async function addColleague(colleague) {
  collaboratorEmail.value = colleague.email
  await addCollaborator()
  showColleaguePicker.value = false
  colleagueSearchQuery.value = ''
}

async function updatePermission(email, permission) {
  if (await apiUpdatePermission(props.targetType, props.itemId, email, permission)) {
    toast.success('Permission updated')
    await load()
    emit('changed')
  } else {
    toast.error('Failed to update permission')
  }
}

async function removeCollaborator(email) {
  if (await apiRemoveCollaborator(props.targetType, props.itemId, email)) {
    toast.success('Collaborator removed')
    await load()
    emit('changed')
  } else {
    toast.error('Failed to remove collaborator')
  }
}

onMounted(() => {
  load()
})
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-surface-600 dark:text-surface-400">
      Share this {{ isFolder ? 'folder' : 'file' }} with team members. They'll need to sign in to access it.
    </p>

    <!-- Team member picker -->
    <div class="relative">
      <button
        type="button"
        @click="showColleaguePicker = !showColleaguePicker"
        class="w-full px-3 py-2 text-left border border-surface-200 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 flex items-center justify-between hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
      >
        <span class="text-sm text-surface-500">Select team members...</span>
        <span class="material-symbols-rounded text-surface-400">{{ showColleaguePicker ? 'expand_less' : 'expand_more' }}</span>
      </button>

      <div
        v-if="showColleaguePicker"
        class="absolute z-20 w-full mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-xl overflow-hidden"
      >
        <div class="p-2 border-b border-surface-200 dark:border-surface-600">
          <input
            v-model="colleagueSearchQuery"
            type="text"
            placeholder="Search team members..."
            class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-600 border-0 rounded-lg text-sm placeholder:text-surface-400 outline-none"
          />
        </div>
        <div class="max-h-48 overflow-y-auto p-2">
          <button
            v-for="colleague in filteredColleagues"
            :key="colleague.id"
            @click="addColleague(colleague)"
            class="w-full px-3 py-2 text-left text-sm hover:bg-surface-50 dark:hover:bg-surface-600 rounded-lg flex items-center gap-3"
          >
            <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center text-primary-600 dark:text-primary-400 font-medium text-sm">
              {{ (colleague.display_name || colleague.email).charAt(0).toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-medium text-surface-900 dark:text-surface-100">{{ colleague.display_name || colleague.email.split('@')[0] }}</p>
              <p class="text-xs text-surface-500 truncate">{{ colleague.email }}</p>
            </div>
            <span class="material-symbols-rounded text-primary-500">add</span>
          </button>
          <div v-if="filteredColleagues.length === 0" class="py-4 text-center text-sm text-surface-500">
            No matching team members
          </div>
        </div>
      </div>
    </div>

    <!-- Default permission -->
    <div class="flex items-center gap-2 text-sm">
      <span class="text-surface-600 dark:text-surface-400">Default permission:</span>
      <select v-model="collaboratorPermission" class="input text-sm py-1 px-2 w-24">
        <option value="viewer">Viewer</option>
        <option value="editor">Editor</option>
      </select>
    </div>

    <!-- Manual email input -->
    <div class="flex gap-2">
      <input
        v-model="collaboratorEmail"
        type="email"
        class="input flex-1 text-sm"
        placeholder="Or enter external email..."
        @keydown.enter="addCollaborator"
      />
      <button
        @click="addCollaborator"
        :disabled="adding || !collaboratorEmail"
        class="btn-primary px-4"
      >
        <span v-if="adding" class="material-symbols-rounded animate-spin">progress_activity</span>
        <span v-else class="material-symbols-rounded">person_add</span>
      </button>
    </div>

    <div class="text-xs text-surface-500 flex items-center gap-1">
      <span class="material-symbols-rounded text-sm">info</span>
      <span v-if="isFolder"><strong>Viewer:</strong> Can view and download. <strong>Editor:</strong> Can also upload and delete.</span>
      <span v-else><strong>Viewer:</strong> Can open and download. <strong>Editor:</strong> Can also edit the document.</span>
    </div>

    <!-- Collaborators list -->
    <div v-if="loading" class="flex justify-center py-6">
      <span class="material-symbols-rounded animate-spin text-2xl text-primary-500">progress_activity</span>
    </div>

    <div v-else-if="collaborators.length === 0" class="text-center py-6 text-surface-500">
      <span class="material-symbols-rounded text-4xl mb-2">group_off</span>
      <p>No collaborators yet</p>
    </div>

    <div v-else class="space-y-2 max-h-60 overflow-y-auto">
      <div
        v-for="collab in collaborators"
        :key="collab.email"
        class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-900/50 rounded-lg"
      >
        <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
          <span class="text-sm font-medium text-primary-600 dark:text-primary-400">
            {{ collab.email.charAt(0).toUpperCase() }}
          </span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
            {{ collab.email }}
          </p>
          <p class="text-xs text-surface-500">
            Added by {{ collab.invited_by }}
          </p>
        </div>
        <select
          :value="collab.permission"
          @change="updatePermission(collab.email, $event.target.value)"
          class="input text-sm py-1 px-2 w-24"
        >
          <option value="viewer">Viewer</option>
          <option value="editor">Editor</option>
        </select>
        <button
          @click="removeCollaborator(collab.email)"
          class="p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg"
          title="Remove"
        >
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>
    </div>
  </div>
</template>
